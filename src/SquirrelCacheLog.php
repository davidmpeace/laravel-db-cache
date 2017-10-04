<?php
namespace Eloquent\Cache;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Eloquent\Cache\Query\SquirrelQueryBuilder;

/**
 * This class is used to hold all relevant data for a SquirrelCache hit or miss.  When a query is executed against a model
 * this will either show the QUERY that was executed (and the time it took to query), or will show the returned set of models
 * that were brought back from cache.
 * 
 */
class SquirrelCacheLog
{
    /** @var boolean True if the cache was hit, and the DB was not queried */
    public $cacheHit = false;

    /** @var float The amount of time, in seconds, that the cache process took. */
    public $time = null;

    /** @var string The SquirrelQueryBuilder sql string (with ? for bindings) that was generated for the query */
    public $builderSql = null;

    /** @var array The array of binding values (used to replace the ? in the SQL string) */
    public $bindings = [];

    /** @var string The attempted translation (and replacement of ?) into a fully qualified SQL string. */
    public $sql = null;

    /** @var string The attempted translation from the table name, to a class name, of what type of model is being queried. */
    public $entity = null;

    /** @var array An array of models, and their IDs, that were returned by the query */
    public $models = [];

    /**
     * This method is used internally by Squirrel to set the SQL that would be executed by the supplied builder object.
     *  
     * @param SquirrelQueryBuilder $builder
     */
    public function setQueryFromBuilder( SquirrelQueryBuilder $builder )
    {
        $this->builderSql = $builder->toSql();
        $this->bindings   = $builder->getBindings();

        $this->sql = "";
        $lastPos = -1;
        foreach( $this->bindings as $binding ) {
            $pos     = strpos($this->builderSql, "?", $lastPos + 1);
            
            if( $pos >= 0 ) {
                $this->sql .= substr($this->builderSql, $lastPos + 1, $pos - $lastPos - 1) . "'" . addslashes($binding) . "'";
            }

            $lastPos = $pos;
        }

        $this->sql .= substr($this->builderSql, $lastPos + 1);
    }

    /**
     * This method is used internally by Squirrel to set the collection of models strings that are being returned by a SQL query.
     *  
     * @param SquirrelQueryBuilder $builder
     * @param Illuminate\Support\Collection $models
     */
    public function setModels( SquirrelQueryBuilder $builder, Collection $models )
    {
        $this->models = [];

        $sourceModel = $builder->sourceModel();
        $primaryKey  = ($sourceModel) ? $sourceModel->getKeyName() : 'id';

        $this->entity = studly_case(str_singular($builder->from));

        foreach( $models as $model ) {

            $primaryKeyValue = "?";
            if( property_exists($model, $primaryKey) ) {
                $primaryKeyValue = $model->$primaryKey;
            }

            $this->models[] = $this->entity . " [{$primaryKey}={$primaryKeyValue}]";
        }
    }

    /**
     * Returns the total aggregate query time that has occurred so far in Squirrel (if logging is enabled).
     * 
     * @return float The total execution time, so far, for ALL queries to the DB.
     */
    public static function totalQueryExecutionTime()
    {
        return static::totalExecutionTime($queriesOnly = true);
    }

    /**
     * Returns the total aggregate execution time that has occurred so far in Squirrel (if logging is enabled).
     * 
     * @return float The total execution time, so far, for ALL queries that have run through Squirrel.
     */
    public static function totalExecutionTime( $queriesOnly = false )
    {
        $logs = SquirrelCache::getLogs();

        $time = 0;
        foreach( $logs as $log ) {
            if( !$queriesOnly || ($queriesOnly && !$log->cacheHit) ) {
                $time += (float)$log->time;
            }
        }

        return $time;
    }
}