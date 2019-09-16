<?php

namespace Friendica\Test\src\Core\Cache;

use Friendica\Core\Cache\MemcacheCache;
use Friendica\Core\Config\Configuration;

/**
 * @requires extension memcache
 */
class MemcacheCacheTest extends MemoryCacheTest
{
	protected function getInstance()
	{
		$configMock = \Mockery::mock(Configuration::class);

		$host = $_SERVER['MEMCACHE_HOST'] ?? 'localhost';

		$configMock
			->shouldReceive('get')
			->with('system', 'memcache_host')
			->andReturn($host);
		$configMock
			->shouldReceive('get')
			->with('system', 'memcache_port')
			->andReturn(11211);

		try {
			$this->cache = new MemcacheCache($host, $configMock);
		} catch (\Exception $e) {
			$this->markTestSkipped('Memcache is not available');
		}
		return $this->cache;
	}

	public function tearDown()
	{
		$this->cache->clear(false);
		parent::tearDown();
	}
}
