<?php
namespace Eloquent\Cache;

use Eloquent\Cache\Query\SquirrelQueryBuilder;
use Eloquent\Cache\Exceptions\InvalidSquirrelModelException;

/**
 * Trait for the Squirrel package.  A Laravel package that automatically caches and retrieves models 
 * when querying records using Eloquent ORM.
 * 
 */
trait Squirrel
{
    /**
     * Constructor to validate the Model Extending Squirrel is actually a descendant of an Eloquen Model.
     * 
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        if (!is_subclass_of($this, "Illuminate\Database\Eloquent\Model")) {
            throw new InvalidSquirrelModelException("Models using the Squirrel trait must extend from the base Eloquent Model.");
        }

        return parent::__construct($attributes);
    }

    /**
     * For cacheing to behave the way we expect, we need to remove the model from cache every time it is
     * saved or deleted.  That way, the next time that data is read, we are reading fresh data from our 
     * data source.
     *
     * @access protected
     * @static
     * @return null
     */
    final protected static function bootSquirrel()
    {
        static::saved(function ($model) {
            $model->forget();
        });

        static::deleted(function ($model) {
            $model->forget();
        });
    }


    /**
     * Returns an array of column names that are unique identifiers for this model in the DB.  By default, it will return
     * only ['id'].  Classes using this trait overwrite this method, and return their own array.  The returned array may contain elements
     * that are string column names, or arrays of string column names for composite unique keys.
     *
     * @access public
     * @static
     * @return  array  Returns an array of unique keys.  Elements may be strings, or arrays of strings for a composite index.
     * 
     */
    public function getUniqueKeys()
    {
        $primaryKey = $this->getKeyName();
        return [$primaryKey];
    }

    /**
     * Classes using this trait may use their own logic to determine if classes should use cacheing
     * or not.  
     * 
     * If this method returns FALSE, then no cacheing logic will be used for Models of this Class.
     * If this method returns TRUE, then cacheing will be used for Models of this Class.
     *
     * @access protected
     * @static
     * @return bool Return true to use this trait for this Class, or false to disable.
     */
    protected function isCacheActive()
    {
        // Defaulted the trait to Active
        return true;
    }


    /**
     * Helper method to quickly determine if cacheing should be used for this class.  It will verify the model
     * cache is active, and the global cache option is active.
     *
     * @access public
     * @final
     * @static
     * @return boolean
     */
    final public function isCacheing()
    {
        return ($this->isCacheActive() && SquirrelCache::isCacheActive());
    }

    /**
     * Models using this trait may extend this method to return a different expiration
     * timeout for each Class.  This value can change depending on the frequency, or volatility of the 
     * data in your Model.  By default, this method expires the data after 24 hours.
     *
     * @access protected
     * @static
     * @return  integer Number of minutes before the cache expires automatically.
     */
    public function cacheExpirationMinutes()
    {
        return (60 * 24);
    }

    /**
     * Store the object attributes in cache by it's cache keys.
     *
     * @access public 
     * @return null
     */
    final public function remember()
    {
        SquirrelCache::remember($this, $this->getAttributes());
    }

    /**
     * Remove this object from cache.
     *
     * @access public
     * @return null
     */
    final public function forget()
    {
        SquirrelCache::forget($this, $this->getAttributes());
    }

    /**
     * Returns all cache keys for this model instance.
     *
     * @access public
     * @return array  Returns an array of cache keys
     */
    final public function cacheKeys()
    {
        return SquirrelCache::cacheKeys($this, $this->getAttributes());
    }

    /**
     * Returns just the primary cache key.
     * 
     * @return [type] [description]
     */
    final public function primaryCacheKey()
    {
        return SquirrelCache::primaryCacheKey($this, $this->getAttributes());
    }

    /*****************************************************************************************************************
     *
     *  !IMPORANT!
     *  Method below is required to proxy requests through Eloquent apropriately.
     *  Do not change or override.
     * 
     *****************************************************************************************************************/

    /**
     * Overwrite default functionality, and return our custom SquirrelQueryBuilder.
     *
     * @access protected
     * @return \Illuminate\Database\Query\Builder
     */
    protected function newBaseQueryBuilder()
    {
        $connection = $this->getConnection();

        $queryBuilder = new SquirrelQueryBuilder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor());
        $queryBuilder->setSourceModel($this);

        return $queryBuilder;
    }
}
