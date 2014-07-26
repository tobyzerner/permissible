<?php namespace Tobscure\Permissible\Condition;

use Illuminate\Database\Query\Builder as IlluminateBuilder;

// extending purely for where functionality. import later
class Builder extends IlluminateBuilder
{
    public function __construct()
    {

    }
    
    public function newQuery()
    {
        return new Builder;
    }

    // $callback should accept the entity's ID expression as the argument and
    // return an Illuminate\Database\Query\Builder object which can then be
    // either executed directly, or inserted into a larger query.
    public function whereExists(Closure $callback, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotExists' : 'Exists';

        $this->wheres[] = compact('type', 'callback', 'boolean');

        return $this;
    }

    public function whereCan($permission, $relation = null, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotCan' : 'Can';

        $this->wheres[] = compact('type', 'permission', 'relation', 'boolean');

        return $this;
    }

    public function orWhereCan($permission, $relation = null)
    {
        return $this->whereCan($permission, $relation, 'or');
    }

    public function whereCannot($permission, $relation = null, $boolean = 'and')
    {
        return $this->whereCan($permission, $relation, $boolean, true);
    }

    public function orWhereCannot($permission, $relation = null)
    {
        return $this->whereCannot($permission, $relation, 'or');
    }
}
