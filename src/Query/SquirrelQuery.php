<?php
namespace Eloquent\Cache\Query;

/**
 * Class to help manage, sort, and process the components of a query builder.
 * 
 */
class SquirrelQuery
{
    public $deletedAtColumnName;
    public $wheres = [];
    private $isCacheable;

    public function __construct(array $whereData, $deletedAtColumnName = null)
    {
        $this->deletedAtColumnName = $deletedAtColumnName;
        $this->loadData($whereData);
    }

    /**
     * Loads all the where clauses from the query builder object, into a more workable format.
     * 
     * @return null
     */
    private function loadData(array $whereData)
    {
        foreach ($whereData as $where) {
            $this->wheres[] = new SquirrelQueryWhere($where);
        }

        $this->sortAlphabetical();
    }

    /**
     * Sort the wheres by the column name alphabetically.  This ensures consistency across the package, despite the order
     * in which the where clause was specified.
     *
     * @return null
     */
    private function sortAlphabetical()
    {
        usort($this->wheres, function ($a, $b) {
            return strcmp($a->column, $b->column);
        });
    }

    /**
     * Generates a string, we can use to easily match against the unique keys on the model, to see
     * if the statement qualifies for cache loopup.
     * 
     * @return string
     */
    public function uniqueKeyString()
    {
        $wheres = $this->allExcludingDeletedAt();

        $columnNames = [];
        foreach ($wheres as $where) {
            $columnNames[] = $where->column;
        }
        return implode(",", $columnNames);
    }

    /**
     * This method returns an array of cache keys we can use to attempt a cache lookup.  It will only
     * return an array of keys if the statement is valid and cacheable.
     * 
     * @return array  Array of cache key strings
     */
    public function cacheKeys($cacheKeyPrefix = '')
    {
        $keys = [];
        if ($this->isCacheable()) {
            if ($where = $this->inStatement()) {
                foreach ($where->value as $value) {
                    $keys[] = $cacheKeyPrefix . serialize([ $where->column => strval($value) ]);
                }
            } else {
                $keys[] = $cacheKeyPrefix . serialize($this->keysWithValues());
            }
        }
        return $keys;
    }

    /**
     * Helper method to return a simple key=>value array with all the wheres in the statement.  Will only work
     * if the statement is valid and cacheable.
     * 
     * @return array Array keyed by the column name
     */
    public function keysWithValues()
    {
        $values = [];
        if ($this->isCacheable()) {
            $wheres = $this->allExcludingDeletedAt();
            foreach ($wheres as $where) {
                $values[$where->column] = (is_array($where->value)) ? $where->value : strval($where->value);
            }
        }
        return $values;
    }

    /**
     * Helper method to return the deleted at part of the wheres (if present).  Note: if multiple columns in the where statement
     * have the same column name, this only returns the first.
     * 
     * @return SquirrelQueryWhere
     */
    public function deletedAtObject()
    {
        return $this->firstWithColumnName($this->deletedAtColumnName);
    }

    /**
     * Returns all our where statements, excluding the deleted at statement.
     * 
     * @return array
     */
    public function allExcludingDeletedAt()
    {
        $wheres = [];
        foreach ($this->wheres as $where) {
            if ($where->column != $this->deletedAtColumnName) {
                $wheres[] = $where;
            }
        }
        return $wheres;
    }

    /**
     * Returns the first where statement that matches a specific column name.
     * 
     * @param  string $columnName
     * @return SquirrelQueryWhere
     */
    public function firstWithColumnName($columnName)
    {
        foreach ($this->wheres as $where) {
            if ($where->column == $columnName) {
                return $where;
            }
        }
    }

    /**
     * If the statement is a single in statement, return that WHERE clause element.
     * 
     * @return SquirrelQueryWhere
     */
    public function inStatement()
    {
        $wheres = $this->allExcludingDeletedAt();
        if (count($wheres) == 1 && is_array($wheres[0]->value)) {
            return $wheres[0];
        }
    }

    /**
     * Method to evaluate if the set of WHERES are cacheable based on a number of different criteria.  Value is evaluated once,
     * then returned from the object scope after that to prevent more checks than are necessary.
     * 
     * @return boolean
     */
    public function isCacheable()
    {
        if (is_null($this->isCacheable)) {
            $this->isCacheable = (bool)($this->containsNoDuplicates() && $this->validWheres() && $this->validArrays());
        }
        return $this->isCacheable;
    }

    /**
     * There are occasions when the application erronoeously will attempt a DB search on a null primary key.  In this case, we 
     * need to handle a special case and not query the database at all.  The return will always be empty.
     * 
     * @return boolean
     */
    public function isQueryingOnNullValue()
    {
        $wheres = $this->allExcludingDeletedAt();
        foreach( $wheres as $where ) {
            if( $where->operator == SquirrelQueryWhere::WHERE_CLAUSE_OPERATOR_IS && $where->type == SquirrelQueryWhere::WHERE_CLAUSE_TYPE_NULL ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validation which returns true if the set of WHERE objects have no duplicate column names.
     * 
     * @return bool
     */
    private function containsNoDuplicates()
    {
        $columnNames = [];
        foreach ($this->wheres as $where) {
            if (in_array($where->column, $columnNames)) {
                return false;
            }
            $columnNames[] = $where->column;
        }

        return true;
    }

    /**
     * Method to validate a number of different data criteria, to ensure the wheres have the data we need to properly search the cache.
     * 
     * @return bool
     */
    private function validWheres()
    {
        foreach ($this->wheres as $where) {
            // We only support 'AND' where statements, fail for anything else
            if ($where->boolean != SquirrelQueryWhere::WHERE_CLAUSE_BOOL_AND) {
                return false;
            }

            // If the where statement is "BASIC", we only support the "EQUALS" operator
            if ($where->type == SquirrelQueryWhere::WHERE_CLAUSE_TYPE_BASIC &&
                $where->operator != SquirrelQueryWhere::WHERE_CLAUSE_OPERATOR_EQUALS) {
                return false;
            }

            // Only deleted at columns support nullable values, so if it's nullable, and not the deleted at column, we return false.
            if (in_array($where->type, [SquirrelQueryWhere::WHERE_CLAUSE_TYPE_NULL, SquirrelQueryWhere::WHERE_CLAUSE_TYPE_NOT_NULL])) {
                if ($where->column != $this->deletedAtColumnName) {
                    return false;
                }
            } elseif (!in_array($where->type, [SquirrelQueryWhere::WHERE_CLAUSE_TYPE_BASIC, SquirrelQueryWhere::WHERE_CLAUSE_TYPE_IN])) {
                // We don't support other types of where clauses than BASIC and IN
                return false;
            }
        }

        return true;
    }

    /**
     * Validates that if we have an IN statement, of an array value, that we only have 1 of them.
     * 
     * @return bool
     */
    private function validArrays()
    {
        $wheres = $this->allExcludingDeletedAt();
        foreach ($wheres as $where) {
            if ($where->operator == SquirrelQueryWhere::WHERE_CLAUSE_TYPE_IN || is_array($where->value)) {
                // We allow a single IN statement
                return (count($wheres) == 1);
            }
        }

        return true;
    }
}
