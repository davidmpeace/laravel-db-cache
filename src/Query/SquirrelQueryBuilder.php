<?php
namespace Eloquent\Cache\Query;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Eloquent\Cache\SquirrelCache;
use Eloquent\Cache\Timer\Timer;

/**
 * This class is used as a proxy to the back-end Eloquent ORM.  It allows
 * us to first check if we have cache moddels, before we actually do any queries 
 * to the database.
 * 
 */
class SquirrelQueryBuilder extends Builder
{
    private $sourceModel;

    /**
     * Models with relationships may ultimately use this method to spawn child queries; as such, we need to ensure we 
     * don't return a SquirrelQueryBuilder object, as it will not work.
     * 
     * @return \Illuminate\Database\Query\Builder
     */
    public function newQuery()
    {
        $builder = new SquirrelQueryBuilder($this->connection, $this->grammar, $this->processor);

        if( $this->sourceModel ) {
            $builder->setSourceModel = $this->sourceModel;
        }

        return $builder;
    }

    /**
     * Setter method for setting the source model.
     * 
     * @param Model $sourceModel
     */
    public function setSourceModel(Model $sourceModel)
    {
        $this->sourceModel = $sourceModel;
    }

    /**
     * Getter method for getting the source model.
     * 
     * @return Model
     */
    public function sourceModel()
    {
        return $this->sourceModel;
    }

    /**
     * Helper method to get the source object's deleted_at column name.
     * 
     * @return string
     */
    private function sourceObjectDeletedAtColumnName()
    {
        if ($this->sourceModel && method_exists($this->sourceModel, 'getDeletedAtColumn')) {
            return $this->sourceModel->getDeletedAtColumn();
        }
    }

    /**
     * This method is overloaded from the base Elquent builder, so we can first see if the cached models already exist, and we can then bypass checking the database
     * entirely.
     *
     * @access public
     * @param  array  $columns
     * @return array
     */
    public function get($columns = array('*'))
    {
        $timer = new Timer();

        $cachedModels = $this->findCachedModels();

        if (!empty($cachedModels)) {
            $cachedModels = collect($cachedModels);
            SquirrelCache::logCacheHit($this, $timer, $cachedModels);
            return $cachedModels;
        }

        $results = parent::get($columns);

        foreach ($results as $result) {
            SquirrelCache::remember($this->sourceModel, (array)$result);
        }
        
        SquirrelCache::logCacheMiss($this, $timer, $results);

        return $results;
    }

    /**
     * Attempts to find cached models based on the current query.
     * 
     * @return array
     */
    private function findCachedModels()
    {   
        if (!$this->sourceModel || !$this->sourceModel->isCacheing()) {
            return false;
        }
        
        $uniqueKeys   = $this->sourceModel->getUniqueKeys();

        // Early validation allows us to fail immediately on obvious unsupported query types
        if (empty($uniqueKeys) || $this->distinct || $this->limit > 1 ||
            !empty($this->groups) || !empty($this->havings) || !empty($this->orders)  || !empty($this->offset)  ||
            !empty($this->unions) || !empty($this->joins)   || !empty($this->columns) ||  empty($this->wheres)) {
            return false;
        }

        $query = new SquirrelQuery($this->wheres, $this->sourceObjectDeletedAtColumnName());

        $searchingKey   = $query->uniqueKeyString();
        $modelKeys      = SquirrelCache::uniqueKeys($this->sourceModel);
        $cacheKeyPrefix = SquirrelCache::getCacheKeyPrefix(get_class($this->sourceModel));

        if (array_key_exists($searchingKey, $modelKeys)) {
            $cacheKeys = $query->cacheKeys($cacheKeyPrefix);
            
            if (empty($cacheKeys)) {
                return;
            }

            $models = [];
            foreach ($cacheKeys as $key) {
                $model = SquirrelCache::get($key);

                if ($model) {
                    if ($deletedAt = $query->deletedAtObject()) {
                        if (array_key_exists($deletedAt->column, $model)) {
                            if ($deletedAt->value == SquirrelQueryWhere::WHERE_CLAUSE_TYPE_NULL && !empty($model[$deletedAt->column])) {
                                // The query requires the deleted at column be empty
                                return;
                            }
                            if ($deletedAt->value == SquirrelQueryWhere::WHERE_CLAUSE_TYPE_NOT_NULL && empty($model[$deletedAt->column])) {
                                // The query requires the deleted at column have a value
                                return;
                            }
                        } else {
                            // The deleted at column could not be found, so we are not going to return anything
                            return;
                        }
                    }

                    $models[] = (object)$model;
                    continue;
                }
                
                return;
            }

            return $models;
        }
    }
}
