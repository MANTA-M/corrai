<?php

namespace Corrai;

use \Exception;
use \Memcached;

/**
 *
 */
class MemSingleton
{
    private Memcached $memcache;

    public function __construct() {
        $this->memcache = new Memcached;
        $this->memcache->addServer("127.0.0.1", 11211);
    }

    public function set(string $key, mixed $value): bool {
        return $this->memcache->set($key, $value);
    }

    public function get(string $key): mixed {
        return $this->memcache->get($key);
    }

    public function fetchAll(): array | false {
        return $this->memcache->fetchAll();
    }
}
?>