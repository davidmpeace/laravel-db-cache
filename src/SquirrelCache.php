<?php
namespace Eloquent\Cache;

use Illuminate\Database\Eloquent\Model;
use Eloquent\Cache\Query\SquirrelQueryBuilder;
use Illuminate\Support\Collection;
use Eloquent\Cache\Timer\Timer;
use Cache;
use Exception;

class SquirrelCache
{
    // Global config option that will set the cache to ON or OFF
    private static $globalCacheActive = true;

    // Simple way to namespace cache tags with a unique ID
    private static $cacheKeyPrefix = "Squirrel";

    // Determines whether the squirrel cache logging is active. When active, it will log each query that hits the database, along with it's execution time, and any cache-misses.
    private static $loggingActive = false;

    // Array of SquirrelCacheLog objects.  Will only be filled when the squirrel cache log is activated.
    private static $logs = [];

    /**
     * Set's the global cache active setting to true or false.  This is the master override switch to turn 
     * cacheing on or off.
     * 
     * @param boolean $active
     */
    public static function setGlobalCacheActive($active = true)
    {
        static::$globalCacheActive = (bool)$active;
    }

    /**
     * Returns true if the global cache is currently active.
     * 
     * @return boolean
     */
    public static function isGlobalCacheActive()
    {
        return (bool)static::$globalCacheActive;
    }

    /**
     * Set's the global logging active setting to true or false.
     * 
     * @param boolean $active
     */
    public static function setLoggingActive($active = true)
    {
        static::$loggingActive = $active;
    }

    /**
     * Returns true if the global logging setting is currently active.
     * 
     * @return boolean
     */
    public static function isLoggingActive()
    {
        return static::$loggingActive;
    }

    /**
     * Returns the array of logs.
     * 
     * @return [SquirrelCacheLog]
     */
    public static function getLogs()
    {
        return static::$logs;
    }

    /**
     * Returns the summary of the logs data.
     * 
     * @return stdClass
     */
    public static function getLogSummary()
    {
        return SquirrelCacheLog::summary();
    }

    /** 
     * If logging is enabled, this will log a Cached hit.
     *
     */
    public static function logCacheHit( SquirrelQueryBuilder $builder, Timer $timer, Collection $models )
    {
        return static::log( $cacheHit = true, $builder, $timer, $models );
    }

    /** 
     * If logging is enabled, this will log a cache miss, meaning a query was run against the database.
     *
     */
    public static function logCacheMiss( SquirrelQueryBuilder $builder, Timer $timer, Collection $models )
    {
        return static::log( $cacheHit = false, $builder, $timer, $models );
    }

    /**
     * Private helper method, that adds a log record if logging is enabled.
     * 
     * @param  boolean              $cacheHit True if the cache was hit, and the DB was NOT queried
     * @param  SquirrelQueryBuilder $builder  The SquirrelQueryBuilder instance
     * @param  Timer                $timer    The squirrel Timer instance
     * @param  Collection           $models   The collection of models being returned
     */
    private static function log( $cacheHit = false, SquirrelQueryBuilder $builder, Timer $timer, Collection $models )
    {
        if( !static::isLoggingActive() ) {
            return;
        }

        $elapsed = $timer->elapsed();

        $log           = new SquirrelCacheLog();
        $log->cacheHit = $cacheHit;
        $log->time     = $elapsed;

        $log->setQueryFromBuilder($builder);
        $log->setModels($builder, $models);

        static::$logs[] = $log;
    }

    /**
     * Set the prefix to be used when storing and retrieving cache records.
     * 
     * @param string
     */
    public static function setCacheKeyPrefix($cacheKeyPrefix)
    {
        static::$cacheKeyPrefix = $cacheKeyPrefix;
    }

    /**
     * Returns the cache key prefix, optionally with a class name as well.
     * 
     * @param  string $className
     * @return string
     */
    public static function getCacheKeyPrefix($className = null)
    {
        $keyPrefix = (!empty($className)) ? static::$cacheKeyPrefix . "::" . $className : static::$cacheKeyPrefix;
        return $keyPrefix . "::";
    }

    /**
     * This method will return an array of cache keys that may be used to store this model in cache.  The $modelAttributes
     * array should contain all the fields required to populate the unique keys returned from getUniqueKeys()
     *
     * @access public
     * @static
     * @param  array $modelAttributes
     * @return array Returns an array of cache keys, keyed off the column names.
     */
    public static function uniqueKeys(Model $sourceObject)
    {
        $objectKeys      = $sourceObject->getUniqueKeys();
        $primaryKey      = $sourceObject->getKeyName();

        if (!in_array($primaryKey, $objectKeys)) {
            $objectKeys[] = $primaryKey;
        }

        $uniqueKeys = [];
        foreach ($objectKeys as $value) {
            $key = $value;
            if (is_array($key)) {
                sort($key);
                sort($value);
                $key = implode(",", $key);
            }
            $uniqueKeys[$key] = $value;
        }
        ksort($uniqueKeys);
        return $uniqueKeys;
    }

    /**
     * Returns all the cache keys for an object.
     * 
     * @param  Model      $sourceObject
     * @param  array|null $modelAttributes
     * @return array
     */
    public static function cacheKeys(Model $sourceObject, array $modelAttributes = null)
    {
        $modelAttributes = (!empty($modelAttributes)) ? $modelAttributes : $sourceObject->getAttributes();
        $uniqueKeys      = static::uniqueKeys($sourceObject);
        $prefix          = static::getCacheKeyPrefix(get_class($sourceObject));

        $cacheKeys = [];
        foreach ($uniqueKeys as $key => $columns) {
            $columns = (!is_array($columns)) ? [$columns] : $columns;

            $keyedByColumn = [];
            foreach ($columns as $column) {
                // If the column doesn't exist in the model attributes, we don't return the cache key at all
                if (!array_key_exists($column, $modelAttributes)) {
                    continue 2;
                }

                $keyedByColumn[$column] = strval($modelAttributes[$column]);
            }

            ksort($keyedByColumn);

            $cacheKeys[$key] = $prefix . serialize($keyedByColumn);
        }

        return $cacheKeys;
    }

    /**
     * Returns the primary cache key for the model, which holds the full data for the object, rather than just a reference.
     * 
     * @param  Model      $sourceObject
     * @param  array|null $modelAttributes
     * @return string
     */
    public static function primaryCacheKey(Model $sourceObject, array $modelAttributes = null)
    {
        $keys = static::cacheKeys($sourceObject, $modelAttributes);
        return array_get($keys, $sourceObject->getKeyName());
    }
    
    private static function cachedClassesKey()
    {
        return static::getCacheKeyPrefix() . "CachedClasses";
    }

    /**
     * This method will store data for a model via all it's various keys.  Only the primary cache key will actually contain the model data,
     * while the other cache keys will contain pointers to where the primary data resides in cache.
     *
     * @access public
     * @static
     * @param  array $modelAttributes
     * @return null
     */
    public static function remember(Model $sourceObject, array $modelAttributes = null)
    {
        $cacheKeys       = static::cacheKeys($sourceObject, $modelAttributes);
        $primaryCacheKey = static::primaryCacheKey($sourceObject, $modelAttributes);
        $expiration      = $sourceObject->cacheExpirationMinutes();
        
        $modelAttributes = (!empty($modelAttributes)) ? $modelAttributes : $sourceObject->getAttributes();

        Cache::put($primaryCacheKey, $modelAttributes, $expiration);

        foreach ($cacheKeys as $cacheKey) {
            if ($cacheKey != $primaryCacheKey) {
                Cache::put($cacheKey, $primaryCacheKey, $expiration);
            }
        }

        // Store the source object class, in our list of stored classes
        $cachedClassesKey = static::cachedClassesKey();
        $storedValue = Cache::get($cachedClassesKey);

        $classes = [];
        if( !empty($storedValue) ) {
            $classes = unserialize($storedValue);
        }

        if( !in_array(get_class($sourceObject), $classes) ) {
            $classes[] = get_class($sourceObject);
            $classes   = serialize($classes);
            Cache::put($cachedClassesKey, $classes, (60*24*365));
        }
    }

    public static function cachedClasses(): array 
    {
        $cachedClassesKey = static::cachedClassesKey();
        $storedValue = Cache::get($cachedClassesKey);

        $classes = [];
        if( !empty($storedValue) ) {
            $classes = unserialize($storedValue);
        }

        return $classes;
    }

    public static function cachedClassesWithCacheCount(): array 
    {
        $classes = static::cachedClasses();

        $classNamesAndCounts = [];
        foreach( $classes as $className ) {
            $classNamesAndCounts[ $className ] = static::countCachedWithSameClass( new $className() );
        }

        return $classNamesAndCounts;
    }

    /**
     * This method allows for easy disposing of a model from cache.
     *
     * @access public
     * @static
     * @param  array $modelAttributes
     * @return null
     */
    public static function forget(Model $sourceObject, array $modelAttributes = null)
    {
        $cacheKeys = static::cacheKeys($sourceObject, $modelAttributes);

        foreach ($cacheKeys as $cacheKey) {
            Cache::forget($cacheKey);
        }
    }

    /**
     * This method will remove all the cached objects that share the same class as the supplied model.
     * NOTE: This method will only work if the default cache driver is redis.
     * 
     * @param  Model $sourceObject The model with the class to remove from cache.
     * @return null
     */
    public static function forgetAllWithSameClass(Model $sourceObject)
    {
        $defaultCacheStoreType = static::defaultCacheStoreType();

        if( $defaultCacheStoreType == 'redis' ) {
            $searchKeys = addslashes(Cache::getPrefix() . static::getCacheKeyPrefix(get_class($sourceObject)) . "*");
            
            $keys = [];
            try {
                $redis = Cache::getRedis();
                $keys = $redis->keys( $searchKeys );
            }
            catch( Exception $e ) {}

            foreach( $keys as $key ) {
                try {
                    $redis->del( $key );
                }
                catch( Exception $e ) {}            
            }
        }
    }
    
    /**
     * This method will count the number of cached objects that share the same class as the supplied model.
     * NOTE: This method will only work if the default cache driver is redis.
     * 
     * @param  Model $sourceObject The model with the class to count cached records.
     * @return null
     */
    public static function countCachedWithSameClass(Model $sourceObject)
    {
        return count(static::allCachedPrimaryKeysWithSameClass($sourceObject));
    }

    /**
     * Returns an array of all the primary cache keys for all cached objects that share the same class as the supplied model 
     * NOTE: This method will only work if the default cache driver is redis.
     * 
     * @param  Model $sourceObject The model with the class to return cached keys for.
     * @return null
     */
    public static function allCachedPrimaryKeysWithSameClass(Model $sourceObject): array
    {
        $defaultCacheStoreType = static::defaultCacheStoreType();

        if( $defaultCacheStoreType == 'redis' ) {
            $prefix     = static::getCacheKeyPrefix(get_class($sourceObject));
            $primaryKey = $sourceObject->getKeyName();
            
            if( !empty($primaryKey) ) {

                $searchString = 'a:1:{s:'.strlen($primaryKey).':"'.$primaryKey.'";s:*:"*";}';
                $searchKeys   = addslashes(Cache::getPrefix() . $prefix . $searchString);
            
                try {
                    $redis = Cache::getRedis();
                    return $redis->keys( $searchKeys );
                }
                catch( Exception $e ) {}
            }
        }

        return [];
    }

    /**
     * Flushes the entire SquirrelCache cache for all stored models, of every class type.
     * NOTE: This method will only work if the default cache driver is redis.
     * 
     * @return null
     */
    public static function flushAll()
    {
        $defaultCacheStoreType = static::defaultCacheStoreType();

        if( $defaultCacheStoreType == 'redis' ) {
            $searchKeys = addslashes(Cache::getPrefix() . static::getCacheKeyPrefix() . "*");

            $keys = [];
            try {
                $redis = Cache::getRedis();
                $keys = $redis->keys( $searchKeys );
            }
            catch( Exception $e ) {}

            foreach( $keys as $key ) {
                try {
                    $redis->del( $key );
                }
                catch( Exception $e ) {}            
            }
        }
    }

    /**
     * Helper method to return the default store type.
     * 
     * @return null
     */
    private static function defaultCacheStoreType()
    {
        $defaultCacheStore     = config('cache.default');
        $defaultCacheStoreType = config('cache.stores.' . $defaultCacheStore . ".driver");
        return $defaultCacheStoreType;
    }

    /**
     * This method will retrieve data for a specific cache key.  If the data returned from cache, is a pointer to another cache record, 
     * it will fetch the pointed data instead, and return the data from the other end point.
     *
     * @access public
     * @static
     * @param  string $cacheKey
     * @param  bool $checkEvenIfGlobalCacheIsInactive Will find the object, even if the global cache is off.
     * @return array|null
     */
    public static function get($cacheKey, $checkEvenIfGlobalCacheIsInactive = false)
    {
        if (!$checkEvenIfGlobalCacheIsInactive && !static::isGlobalCacheActive()) {
            return false;
        }

        $cacheTagPrefix = static::getCacheKeyPrefix();

        if ($data = Cache::get($cacheKey)) {
            if (is_string($data) && (substr($data, 0, strlen($cacheTagPrefix)) == $cacheTagPrefix)) {
                // If the data returned from cache, is a reference to another cache key, we return that one instead.
                $data = Cache::get($data);
            }
        }

        return $data;
    }
}
