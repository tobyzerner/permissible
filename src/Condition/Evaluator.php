<?php namespace Tobscure\Permissible\Condition;

class Evaluator
{
    protected $model;

    protected $user;

    public function __construct($model, $user)
    {
        $this->model = $model;
        $this->user = $user;
    }

    public function evaluate($conditions)
    {
        $prevSatisfied = null;

        if ($conditions === true or $conditions === false) {
            return $conditions;
        }

        if (! $conditions->wheres) {
            return true;
        }

        foreach ($conditions->wheres as $where) {
            if ($where['boolean'] == 'or' and $prevSatisfied) {
                continue;
            }

            $method = "where{$where['type']}";

            $satisfied = $this->$method($where);

            if ($where['boolean'] == 'and') {
                $prevSatisfied = ($prevSatisfied !== false) && $satisfied;
            } else {
                $prevSatisfied = $prevSatisfied || $satisfied;
            }
        }

        return $prevSatisfied;
    }

    protected function whereNested($where)
    {
        return $this->evaluate($where['query']);
    }

    protected function entityValue($column)
    {
        $value = $this->model;

        $parts = explode('.', $column);
        foreach ($parts as $part) {
            $value = $value->$part;
        }

        return $value;
    }

    protected function whereBasic($where)
    {
        $column = $where['column'];
        $value = $where['value'];
        $operator = str_pad($where['operator'], 2, '=');
        $entityValue = $this->entityValue($column);

        return eval('return $entityValue '.$operator.' $value;');
    }

    protected function whereIn($where)
    {
        $column = $where['column'];
        $value = $where['value'];
        $entityValue = $this->entityValue($column);

        return in_array($value, $entityValue);
    }

    protected function whereNotIn($where)
    {
        return ! $this->whereIn($where);
    }

    protected function whereNull($where)
    {
        $column = $where['column'];
        $entityValue = $this->entityValue($column);

        return $this->model ? is_null($entityValue) : true;
    }

    protected function whereNotNull($where)
    {
        return ! $this->whereNull($where);
    }

    protected function whereExists($where)
    {
        $query = $where['callback']($this->model->newQuery(), '?');
        $query->addBinding($this->model->id, 'where');
        
        return $query->first();
    }

    protected function whereNotExists($where)
    {
        return ! $this->whereExists($where);
    }

    protected function whereCan($where)
    {
        $relation = $where['relation'];

        $model = $this->model;
        
        if ($relation) {
            $model = $model->$relation;
        }

        return $model->can($this->user, $where['permission']);
    }

    protected function whereNotCan($where)
    {
        return ! $this->whereCan($where);
    }
}
