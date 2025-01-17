<?php

namespace ConfigCat\Cache;

use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

class Psr16Cache extends ConfigCache
{
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    /**
     * Reads the value identified by the given $key from the underlying cache.
     *
     * @param string $key identifier for the cached value
     *
     * @return string|null cached value for the given key, or null if it's missing
     *
     * @throws InvalidArgumentException if the $key is not a legal value
     */
    protected function get(string $key): ?string
    {
        return $this->cache->get($key);
    }

    /**
     * Writes the value identified by the given $key into the underlying cache.
     *
     * @param string $key   identifier for the cached value
     * @param string $value the value to cache
     *
     * @throws InvalidArgumentException if the $key is not a legal value
     */
    protected function set(string $key, string $value): void
    {
        $this->cache->set($key, $value);
    }
}
