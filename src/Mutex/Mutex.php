<?php

/**
 * @author    Ezra Pool <ezra@digitalnatives.nl>
 * @copyright Digital Natives (c) 2024
 */

declare(strict_types=1);

namespace DigitalNatives\Mutex;

use DigitalNatives\Cache\Redis;
use Yii;
use yii\base\InvalidConfigException;
use yii\di\Instance;

/**
 * \DigitalNatives\Mutex\Mutex implements a mutex component using cache as the storage medium.
 * \DigitalNatives\Mutex\Mutex requires redis version 2.6.12 or higher to work properly.
 *
 * It needs to be configured with a `\DigitalNatives\Cache\Redis` that is also configured as an application component.
 * By default, it will use the `cache` application component.
 *
 * To use Mutex as the application component, configure the application as follows:
 *
 * ```php
 * [
 *     'components' => [
 *         'mutex' => static function () {
 *             $config = [
 *                 'class' => \DigitalNatives\Mutex\Mutex::class,
 *                 'expire' => Craft::$app->request->isConsoleRequest ? 900 : 30,
 *             ];
 *
 *             return Craft::createObject($config);
 *         }
 *     ]
 * ]
 * ```
 *
 * Or if you don't have the redis-cache from this package configured as the cache component.
 *
 * ```php
 * [
 *     'components' => [
 *         'mutex' => static function () {
 *             $config = [
 *                 'class' => \DigitalNatives\Mutex\Mutex::class,
 *                 'expire' => Craft::$app->request->isConsoleRequest ? 900 : 30,
 *             ];
 *
 *             return Craft::createObject($config);
 *         }
 *     ],
 * ]
 * ```
 *
 * Or statically:
 *
 * ```php
 * [
 *     'components' => [
 *         'mutex' => [
 *             'class' => \DigitalNatives\Mutex\Mutex::class,
 *             'expire' => 30,
 *         ],
 *     ],
 * ]
 * ```
 *
 * @see \yii\mutex\Mutex
 * @see https://redis.io/topics/distlock
 *
 * @author Ezra Pool <ezra@digitalnatives.nl>
 * @since 1.0.0
 */
class Mutex extends \yii\mutex\Mutex
{
    /**
     * @var int the number of seconds in which the lock will be auto released.
     */
    public int $expire = 30;

    /**
     * @var ?string a string prefixed to every cache key so that it is unique. If not set,
     * it will use a prefix generated from [[Application::id]]. You may set this property to be an empty string
     * if you don't want to use key prefix. It is recommended that you explicitly set this property to some
     * static value if the cached data needs to be shared among multiple applications.
     */
    public ?string $keyPrefix = null;

    /**
     * @var Redis|string|array the Redis [[Connection]] object or the application component ID of the Redis [[Connection]].
     * This can also be an array that is used to create a redis [[Connection]] instance in case you do not want to configure
     * redis connection as an application component.
     * After the Mutex object is created, if you want to change this property, you should only assign it
     * with a Redis [[Connection]] object.
     */
    public Redis|string|array $cache = 'cache';

    /** @var \Illuminate\Contracts\Cache\Lock[] */
    private array $locks = [];

    /**
     * Initializes the redis Mutex component.
     * This method will initialize the [[cache]] property to make sure it refers to a valid cache connection.
     * @throws InvalidConfigException if [[cache]] is invalid.
     */
    public function init(): void
    {
        parent::init();

        $this->cache = Instance::ensure($this->cache, Redis::class);

        if ($this->keyPrefix === null) {
            $this->keyPrefix = Yii::$app->id . ':lock';
        }
    }

    /**
     * Acquires a lock by name.
     *
     * @param string $name of the lock to be acquired. Must be unique.
     * @param int $timeout time (in seconds) to wait for lock to be released. Defaults to `0` meaning that method
     *                     will return false immediately in case lock was already acquired.
     *
     * @return bool lock acquiring result.
     */
    protected function acquireLock($name, $timeout = 0): bool
    {
        $lockName = $this->lockName($name);

        $this->locks[$lockName] = $this->cache->lock($lockName, $this->expire);

        try {
            return $this->locks[$lockName]->block($timeout);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Releases acquired lock. This method will return `false` in case the lock was not found or Redis command failed.
     *
     * @param string $name of the lock to be released. This lock must already exist.
     * @return bool lock release result: `false` in case named lock was not found or Redis command failed.
     */
    protected function releaseLock($name): bool
    {
        $lockName = $this->lockName($name);

        $lock = $this->locks[$lockName]?->release() ?? false;
        unset($this->locks[$lockName]);

        return $lock;
    }

    /**
     * Helper function to generate a lock name.
     */
    protected function lockName(string $name): string
    {
        return "{$this->keyPrefix}:{$name}";
    }
}
