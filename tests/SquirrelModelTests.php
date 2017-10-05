<?php

use Eloquent\Cache\SquirrelCache;

class SquirrelModelTests extends PHPUnit_Framework_TestCase
{
    const CACHE_PREFIX = "Squirrel";

    /**
     *
     */
    public function testSettings()
    {
        SquirrelCache::setGlobalCacheActive(false);
        $this->assertFalse( SquirrelCache::isGlobalCacheActive(), "Set Cache to inactive, but did not come back false." );

        SquirrelCache::setGlobalCacheActive(true);
        $this->assertTrue( SquirrelCache::isGlobalCacheActive(), "Set Cache to active, but did not come back true." );

        $prefix = self::CACHE_PREFIX;
        $class  = "App\\User";
        SquirrelCache::setCacheKeyPrefix($prefix);
        $plainPrefix = SquirrelCache::getCacheKeyPrefix();
        $expectedPrefix = $prefix . "::";
        $this->assertEquals( $plainPrefix, $expectedPrefix, "Set prefix to `$plainPrefix`, but didn't get expected result `$expectedPrefix`" );

        $classPrefix = SquirrelCache::getCacheKeyPrefix($class);
        $expectedPrefix = $prefix . "::" . $class . "::";
        $this->assertEquals( $classPrefix, $expectedPrefix, "Set prefix to `$plainPrefix` with Class `$class`, but didn't get expected result `$expectedPrefix`" );
    }

    /**
     * @expectedException Eloquent\Cache\Exceptions\InvalidSquirrelModelException
     */
    public function testInvalidExtendingModel()
    {
        $user = new InvalidSquirrelModelExtend();
    }

    /**
     * 
     */
    public function testSquirrelModelDefaults()
    {
        $user = new DefaultsTestUser();
        $uniqueKeys = $user->getUniqueKeys();
        $primaryKey = $user->getKeyName();

        $this->assertContains( $primaryKey, $uniqueKeys, "Default return for models unique keys should return the primary key, but it did not." );
        $this->assertCount( 1, $uniqueKeys, "Expected default unique keys to come back with a single element, but it did not." );

        SquirrelCache::setGlobalCacheActive(true); // Set global cache to true
        $this->assertTrue( $user->isCacheing(), "The global cache is true, and the model cache should return true for cacheing, but it is not." );

        $this->assertEquals( (24*60), $user->cacheExpirationMinutes(), "Default expiration date expects 24 hours, but it came back different." );

        $keys = $user->cacheKeys();
        
        $this->assertCount( 1, $keys, "Expected 1 cache key returned, but received different amount." );

        $prefix = SquirrelCache::getCacheKeyPrefix( get_class($user) );
        $primary = [
            $primaryKey => strval($user->id)
        ];
        $expectedCacheKey = $prefix . serialize($primary);

        $firstKeyValue = array_shift($keys);
        $this->assertEquals( $expectedCacheKey, $firstKeyValue );

        $primaryCacheKey = $user->primaryCacheKey();
        $this->assertEquals( $expectedCacheKey, $primaryCacheKey );
    }

    /**
     * 
     */
    public function testModifiedSquirrelModel()
    {
        $user = new ModifiedTestUser();

        $uniqueKeys = $user->getUniqueKeys();
        $primaryKey = $user->getKeyName();

        $this->assertContains( $primaryKey, $uniqueKeys );
        $this->assertcount( 3, $uniqueKeys );

        SquirrelCache::setGlobalCacheActive(true); // Set global cache to true
        $this->assertFalse( $user->isCacheing(), "Modified User Model has cache turned off, but it's still returning true." );

        $this->assertEquals( ((24*60) *7), $user->cacheExpirationMinutes() );

        $keys = $user->cacheKeys();
        $this->assertCount( 3, $keys, "Expected 3 cache keys returned, but received different amount." );

        $prefix = SquirrelCache::getCacheKeyPrefix( get_class($user) );
        $primary = [
            $primaryKey => strval($user->id)
        ];
        $expectedCacheKey = $prefix . serialize($primary);
        $this->assertContains( $expectedCacheKey, $keys );

        $primaryCacheKey = $user->primaryCacheKey();
        $this->assertEquals( $expectedCacheKey, $primaryCacheKey );
    }
}
