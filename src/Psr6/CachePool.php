<?php

namespace ByJG\Cache\Psr6;

use ByJG\Cache\Exception\InvalidArgumentException;
use ByJG\Cache\Psr16\BaseCacheEngine;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class CachePool implements CacheItemPoolInterface
{
    /**
     * @var \Psr\SimpleCache\CacheInterface
     */
    protected $_cacheEngine;

    /**
     * @var CacheItem
     */
    protected $_lastCacheItem;

    /**
     * @var int
     */
    protected $bufferSize = 10;

    /**
     * @var CacheItem[]
     */
    protected $buffer = [];

    /**
     * @var array
     */
    protected $bufferKeys = [];

    /**
     * CachePool constructor.
     * 
     * @param BaseCacheEngine $_cacheEngine
     * @param int $bufferSize
     */
    public function __construct(BaseCacheEngine $_cacheEngine, $bufferSize = 10)
    {
        $this->_cacheEngine = $_cacheEngine;
        $this->bufferSize = intval($bufferSize);
    }

    /**
     * @return int
     */
    public function getBufferSize()
    {
        return $this->bufferSize;
    }

    /**
     * @param int $bufferSize
     */
    public function setBufferSize($bufferSize)
    {
        $this->bufferSize = $bufferSize;
    }


    /**
     * Add an element to buffer. If the buffer is full, the first element added will be removed 
     * 
     * @param CacheItem $cacheItem
     */
    protected function addElementToBuffer(CacheItem $cacheItem)
    {
        if ($this->bufferSize < 1) {
            return;
        }

        $key = $cacheItem->getKey();
        $this->buffer[$key] = $cacheItem;

        if (in_array($key, $this->bufferKeys)) {
            return;
        }

        array_push($this->bufferKeys, $key);

        if (count($this->bufferKeys) > $this->bufferSize) {
            $element = array_shift($this->bufferKeys);
            unset($this->buffer[$element]);
        }
    }

    /**
     * Remove a specific key from buffer
     * 
     * @param $key
     */
    protected function removeElementFromBuffer($key)
    {
        $result = array_search($key, $this->bufferKeys);
        if ($result === false) {
            return;
        }

        unset($this->buffer[$key]);
        unset($this->bufferKeys[$result]);
    }

    /**
     * Psr implementation of getItem()
     *
     * @param string $key
     * @return CacheItem
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function getItem($key)
    {
        // Get the element from the buffer if still remains valid!
        if (in_array($key, $this->bufferKeys)) {
            $cacheItem = $this->buffer[$key];
            if ($cacheItem->getExpiresInSecs() > 1) {
                return $cacheItem;
            }
        }
        
        // Get the element from the cache!
        $result = $this->_cacheEngine->get($key);
        $cache = new CacheItem($key, $result, $result !== null);

        $this->addElementToBuffer($cache);

        return $cache;
    }

    /**
     * Psr implementation of getItems()
     *
     * @param array $keys
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function getItems(array $keys = array())
    {
        $result = [];
        foreach ($keys as $key) {
            $result[] = $this->getItem($key);
        }

        return $result;
    }

    /**
     * Psr implementation of hasItems()
     *
     * @param string $key
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function hasItem($key)
    {
        return $this->getItem($key)->isHit();
    }

    /**
     * Psr implementation of clear()
     */
    public function clear()
    {
        $this->_cacheEngine->clear();
        $this->bufferKeys = [];
        $this->buffer = [];
    }

    /**
     * Psr implementation of deleteItem()
     *
     * @param string $key
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function deleteItem($key)
    {
        return $this->deleteItems([$key]);
    }

    /**
     * Psr Implementation of deleteItems()
     *
     * @param array $keys
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function deleteItems(array $keys)
    {
        foreach ($keys as $key) {
            $this->_cacheEngine->delete($key);
            $this->removeElementFromBuffer($key);
        }
        
        return true;
    }

    /**
     * @param CacheItemInterface $item
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function save(CacheItemInterface $item)
    {
        if (!($item instanceof CacheItem)) {
            throw new InvalidArgumentException('The cache item must be an implementation of \ByJG\Cache\Psr\CacheItem');
        }
        
        if ($item->getExpiresInSecs() < 1) {
            throw new InvalidArgumentException('Object has expired!');
        }
        
        $this->_cacheEngine->set($item->getKey(), $item->get(), $item->getExpiresInSecs());
        $this->addElementToBuffer($item);
        
        return true;
    }

    /**
     * @var CacheItem[]
     */
    protected $deferredItem = [];

    /**
     * Psr Implementation of saveDeferred()
     * 
     * @param CacheItemInterface $item
     * @return bool
     */
    public function saveDeferred(CacheItemInterface $item)
    {
        $this->deferredItem[] = $item;
        return true;
    }

    /**
     * Psr implementation of commit()
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function commit()
    {
        foreach ($this->deferredItem as $item) {
            $this->save($item);
        }
        
        $this->deferredItem = [];
    }

    public function isAvailable()
    {
        return $this->_cacheEngine->isAvailable();
    }
}
