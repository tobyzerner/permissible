<?php namespace Tobscure\Permissible;

trait Permissible
{
    protected static $callbacks = [
        'grant' => [],
        'check' => []
    ];

    public static function grant($permission, $callback = null)
    {
        return static::addCallback('grant', $permission, $callback);
    }

    public static function check($permission, $callback = null)
    {
        return static::addCallback('check', $permission, $callback);
    }

    protected static function addCallback($type, $permission, $callback = null)
    {
        if ($callback === null) {
            $callback = $permission;
            $permission = '*';
        }
        static::$callbacks[$type][] = [(array) $permission, $callback];
    }

    protected static function getCallbacks($type, $permission)
    {
        $callbacks = [];

        foreach (static::$callbacks[$type] as $callback) {
            list($permissions, $closure) = $callback;
            if (array_intersect([$permission, '*'], $permissions)) {
                $callbacks[] = $closure;
            }
        }

        return $callbacks;
    }

    public function can($user, $permission)
    {
        $conditions = $this->getConditions($user, $permission);

        $evaluator = new Condition\Evaluator($this, $user);

        return $evaluator->evaluate($conditions);
    }

    public function scopeWhereCan($query, $user, $permission)
    {
        $permissionQuery = $this->getPermissionWheres($user, $permission);

        $query->addNestedWhereQuery($permissionQuery);

        return $query;
    }

    protected function getConditions($user, $permission)
    {
        $conditions = new Condition\Builder;

        $grant = $this->constructConditions($this->getCallbacks('grant', $permission), $user, $permission, 'or');
        if ($grant === false) {
            return false;
        } elseif ($grant !== true) {
            $conditions->addNestedWhereQuery($grant);
        }

        $check = $this->constructConditions($this->getCallbacks('check', $permission), $user, $permission, 'and');
        if ($check === false) {
            return false;
        } elseif ($check !== true) {
            $conditions->addNestedWhereQuery($check);
        }

        if ($grant === true and $check === true) {
            return true;
        }

        return count($conditions->wheres) ? $conditions : false;
    }

    protected function constructConditions($callbacks, $user, $permission, $boolean)
    {
        if (! $callbacks) {
            return $boolean == 'and';
        }

        $conditions = new Condition\Builder;

        foreach ($callbacks as $callback) {
            $callbackConditions = new Condition\Builder;
            $result = $callback($callbackConditions, $user, $permission);

            // If boolean == or, then only one callback must be true for the conditions to be satisfied.
            // If boolean == and, then only one callback must be false for the conditions to not be satisfied.
            if ($result === ($boolean == 'or')) {
                return $result;
            }

            $conditions->addNestedWhereQuery($callbackConditions, $boolean);
        }

        return $conditions;
    }

    public function getPermissionWheres($user, $permission)
    {
        $conditions = $this->getConditions($user, $permission);

        $compiler = new Condition\Compiler($this, $user);

        return $compiler->compile($conditions);
    }
}
