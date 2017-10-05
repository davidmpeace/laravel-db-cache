# Squirrel

Squirrel is an Eloquent cacheing solution that handles the complexities of 'remembers' and 'forgets' for you.  This package is intended to be used with Laravel.  Squirrel automatically caches and retrieves models when querying records using [Eloquent ORM](http://laravel.com/docs/eloquent).  When Squirrel is used, you can expect to see orders of magnitude fewer queries to your database, with the confidence you will never be retrieving stale data from Cache.

## License

Squirrel is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)

## Installation

To get started with Squirrel, add to your `composer.json` file as a dependency:

    composer require davidmpeace/squirrel

### Basic Usage

To use the Squirrel library, you simply need to use the Squirrel trait for any model you want to implement cacheing for.  Typically, you would want to implement the trait in your super-class so that all your sub-classes will automatically inherit the functionality.

```php
<?php
namespace App;

use Illuminate\Database\Eloquent\Model;
use Eloquent\Cache\Squirrel;

class MyAppSuperModel extends Model
{
    use Squirrel;
}
```

That's it!  You will now automatically inherit all the magic of Squirrel.

### Default Behavior

Without any customization, Squirrel behaves in the following default manner:

1) Each model will be cached using the key returned from the $model->getKeyName() method, as defined in the base Eloquent Model class.
2) The Cache is "active" for all models that use the Squirrel trait.
3) Each model is cached for 24 hours before expiring.

### Configuration

Sometimes you'll need custom configuration on a per-model basis.  Here are some examples of methods you can implement to override default behavior.

```php
<?php
namespace App;

use Illuminate\Database\Eloquent\Model;
use Eloquent\Cache\Squirrel;

class User extends Model
{
    use Squirrel;
    
    /**
     * Implement this method, to establish additional unique keys on your table.  Doing this gives Squirrel more power
     * in establishing more cacheable queries.  Return an array of string column names, or nested arrays for 
     * compound unique keys.
     */
    public function getUniqueKeys()
    {
        $primaryKey = $this->getKeyName();
        return [$primaryKey, 'uuid', ['account_id', 'email']];
    }
    
    /**
     * Implement this method to cacheing on or off for this model specifically.  Returning false on this method
     * does not affect other models also using Squirrel.
     */
    public function isCacheActive()
    {
        return true; 
    }
    
    /**
     * Implement this method to change the expiration minutes timeout when cacheing this model.
     */
    public function cacheExpirationMinutes()
    {
        return (60 * 24); 
    }
}
```

### Global Configuration & Methods

Use the following global configuration methods to update settings for all models that implement Squirrel cache.

```php
use Eloquent\Cache\SquirrelCache;

SquirrelCache::setGlobalCacheActive(false);      // Turn Squirrel ON or OFF globally
SquirrelCache::isGlobalCacheActive();            // Returns the config value if Squirrel is active or not globally.
SquirrelCache::setCacheKeyPrefix("Squirrel::");  // Prefix used for all stored Cache Keys
SquirrelCache::getCacheKeyPrefix();              // Returns the cache key prefix
SquirrelCache::setLoggingActive(true);           // Turns on internally logging for cache hits\misses, and DB queries.
SquirrelCache::isLoggingActive();                // Returns true if logging is enabled.
SquirrelCache::getLogs();                        // Returns the array of logs that were generated so far from Squirrel.
SquirrelCache::getLogSummary();                  // Returns a simple log summary of hits, misses, and DB queries.
SquirrelCache::flushAll();                       // Flushes all cached models from Squirrel
```

### Squirrel Model Instance Methods

These methods are available to any Object using the Squirrel Trait

```php
$model->remember();                  // Will store the object in Cache
$model->forget();                    // Will remove the object from Cache
$model->isCached();                  // Returns true if the current object is stored in cache.
$model->isCacheing();                // Returns true if Cacheing is on for this model
$model->cachedData();                // Returns the data that is stored in cache for this object.
$model->cacheKeys();                 // Will return an array of all the Cache keys used to store the object
$model->primaryCacheKey();           // Will return the primary cache key for the object.
$model->cacheExpirationMinutes();    // Returns the number of minutes cache records stay available.
$model->countCachedWithSameClass();  // Returns the number of cached models with the same class.
$model->forgetAllWithSameClass();    // Forgets all cached models with the same class.
```

### Queries Supported

Squirrel is meant to support multiple unique keys, as well as compound unique keys, so any query that is attempting to bring back a single row based on a unique key will work.  However, you may also perform an "In" query, as long as that's the only part of the query.  See below:

```php
// Simple ID Queries
Model::find(1);
Model::whereId(1)->get();

// This works because we return a compound unique key on the model
Model::whereAccountId(12)->whereEmail('foo@bar.com')->get();  

// Also works, because it will try to find all the individual records
Model::whereIn('id', [1,2,3,4,5])->get(); 

// Works if 'uuid' is returned as a unique key on the model
Model::whereUuid('12345-12346-123456-12356')->first(); 

// THESE QUERIES DO NOT WORK WITH CACHEING, AND WILL QUERY THE DB

// WON'T CACHE because the "=" equals sign, and "in", are the only supported operators.
Model::where('id', '>', 50)->get();

// WON'T CACHE because the field is not defined as a unique key on the model
Model::wherePlanId(23)->first();
```

### Under the Hood

The way Squirrel works is by extending the default `\Illuminate\Database\Query\Builder` Class, which is responsible for executing queries for your models.

By default, Models inherit a method called `newBaseQueryBuilder()` which is responsible for returning the Builder object.  We overload this method so we can return the `SquirrelQueryBuilder` object instead.

The `SquirrelQueryBuilder->get()` method does the actual querying.  However, before we query the data, we first check to see if our model is cached via any unique keys, if so, we return it, otherwise, we do the query.  Finally, after the query is executed, we save the retrieved data in cache so it doesn't get hit again until the data expires.

Cache keys are stored in the following format:

`SquirrelCache::$cacheKeyPrefix . "::" . get_class($model) . "::" . serialize(uniquekey);`

Example for User model where id=1:

`Squirrel::App\\User::a:1:{s:2:"id";s:1:"1";}`

Example for User model where account_id=27 and email=foo@bar.com:

`Squirrel::App\\User::a:2:{s:10:"account_id";s:2:"27";s:5:"email";s:11:"foo@bar.com";}`
