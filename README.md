# Permissible

Powerful, flexible, relational permissions using Eloquent.

Permissible allows you to define permissions as independent logic clauses which can be directly evaluated against a model's data, or compiled into a Fluent query's WHERE clause.

## Rationale

Sometimes, permissions can be complex. Imagine a scenario with **discussions** which each have many **posts**. Here is our permission logic:

* A user has permission to view a post only if they have permission to view the discussion which it's in.
* A user can only view a discussion if they started the discussion. 

The cascade starts to build: **a user can view a post ONLY IF the discussion it's in was started by them.**

So how do we tell if a post can be viewed or not? The obvious solution might be to do something like this:

```php
class Post extends Eloquent
{
    public function discussion()
    {
        return $this->belongsTo('Discussion');
    }

    public function canView()
    {
        return $this->discussion->canView();
    }
}

class Discussion extends Eloquent
{
    public function canView()
    {
        return $this->start_user_id == Auth::user()->id;
    }
}
```

Great! Now we can call `$post->canView()` to determine whether or not a post can be viewed. But this won't work in all cases. Imagine we're doing a search for posts:

```php
$results = Post::with('discussion')
    ->where('content', 'like', '%hello%')
    ->take(20)
    ->get();
```

Let's say we got the 20 results we requested, and there are more in the database that we didn't get. But now we have to filter them down to only the ones we can view:

```php
$results = $results->filter(function ($post) {
    return $post->canView();
});
```

Now we might only have 10, or 5, or even 0! We can't present this to the user — they want a full page of results, and there's no good reason why they shouldn't get what they want. **Clearly, we need to filter out the posts that the user can't view in the search query.**

OK, so how about something like this:

```php
$viewableDiscussions = function ($query) {
    $query->select('id')
          ->from('discussions')
          ->where('start_user_id', Auth::user()->id);
};

$results = Post::where('content', 'LIKE', '%hello%')
    ->whereIn('discussion_id', $viewableDiscussions)
    ->take(20)
    ->get();
```

Great, problem solved, right? Well, yes, but now we've **duplicated our permission logic in the Discussion model's `canView` method and our sub-select query.**

We could of course move the sub-select logic into a `scopeCanView` method, but the logic is still duplicated — the `canView` copy of the logic evaluates the model's data, while the `scopeCanView` copy of the logic adds a WHERE clause to a query to filter its results. **It's the exact same logic, just in a different form! When permissions get really complex, this duplication is painful.**

Permissible makes it really easy to deal with scenarios like these. Permissions are defined by agnostic condition clauses which can be directly evaluated against a model's data, or compiled into a query's WHERE clause to filter results.

## Install

via Composer:

    "tobscure/permissible": "*"

## Usage

### Checking Permissions

On any models which you want to have permission checking available for, simply include the `Tobscure\Permissible\Permissible` trait. Easy!

```php
use Tobscure\Permissible\Permissible;

class Discussion extends Eloquent
{
    use Permissible;
}
```

This trait provides `can` and `scopeWhereCan` methods so you can do things like this:

```php
// Check if a user has permission to view a certain discussion
$discussion = Discussion::find(1);
if (! $discussion->can($user, 'view')) {
    echo 'permission denied';
}

// List all of the discussions that a user has permission to view
$discussions = Discussion::whereCan($user, 'view')->get();
```

However, these won't be much good without having defined any conditions upon which to grant the permission, because **initially all permissions are denied**.

### Granting Permissions

To define a condition on which to grant a permission, the trait provides us with a static `grant` method. We can call this when we boot up the model. The first argument is the name of the permission; the second is a Closure which accepts a `Tobscure\Permissible\Condition\Builder` object, and a $user object.

The `Condition\Builder` class is very similar to Laravel's query builder; you will be familiar with the `where`, `whereNull`, `whereNotNull`, `whereIn`, and `whereNotIn` methods, as well as the `or` variants of them all.

```php
public static function boot()
{
    parent::boot();

    static::grant('view', function ($grant, $user) {
        $grant->where('start_user_id', $user->id);
    });
}
```

#### Permission Conditions

In addition to these static `where` conditions, you can define conditions which depend upon another permission, or a permission on a relationship. This is done using the `whereCan` and `whereCannot` methods. (These also have `or` variants.)

```php
$grant->whereCan('edit')
      ->orWhereCan('view', 'user');
```

#### Exists Conditions

You may also define conditions which check for the presence or absence of a database record by passing a Closure to `whereExists` or `whereNotExists`. As well as a query builder, this Closure accepts a value which represents the ID of the model; it will be a binding placeholder (`?`) if the condition is being evaluated against model data, and it will be the name of a column if the condition is being added to a query.

```php
$grant->whereExists(function ($query, $id) use ($user) {
    return $query->select(DB::raw(1))
        ->from('discussions_private')
        ->where('user_id', $user->id)
        ->whereRaw("discussion_id = $id");
});
```

#### Granting Multiple Permissions

You may grant multiple permissions using the same logic by passing in an array of permission names, or by ommitting the permission argument altogether. The third argument passed into the closure is the name of the permission being evaluated.

To always grant a permission, regardless of the specific entity in question, you may return true from the callback.

```php
static::grant(['view', 'edit'], function ($grant, $user) {
    return $user->isAdmin();
});
```

### Requiring Conditions

If *any one* of the sets of conditions defined for a certain permission is satisfied, then the permission is granted. So we can dyanmically add new grant conditions at runtime:

```php
// Users can view discussions if they are started by themselves
static::grant('view', function ($grant, $user) {
    $grant->where('start_user_id', $user->id);
});

// Users can also view discussions if they are started by their mothers
static::grant('view', function ($grant, $user) {
    $grant->where('start_user_id', $user->mother->id);
});
```

But what if, at runtime, we needed to make it so that a user couldn't view any discussions that are more than a year old, regardless of who they were started by? To achieve this, we have to add *required* conditions using the `check` method:

```php
// Users can view discussions ONLY if they are less than a year old
static::check('view', function ($check, $user) {
    $oneYearAgo = strtotime('-1 year', time());
    $check->where('start_time', '<', $oneYearAgo);
});
```

For a permission to be granted, *all* of the conditions defined using the `check` method must be satisfied, if there are any.

To summarise, in order for a permission to be granted, two sets of conditions must be satisfied:

1. At least one **grant** condition must be satisfied.
2. All of the **check** conditions must be satisfied.
