<?php

/**
 * @author Ezra Pool <ezra@digitalnatives.nl>
 */

declare(strict_types=1);

namespace DigitalNatives\Cache;

use Illuminate\Cache\PhpRedisLock;
use Illuminate\Cache\RedisLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Redis\Connection;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisClusterConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Predis\Client;
use Predis\Response\Status;
use yii\caching\Cache;

class Redis extends Cache
{
    private const COMMAND_EXISTS = 'EXISTS';
    private const COMMAND_GET = 'GET';
    private const COMMAND_MGET = 'MGET';
    private const COMMAND_MSET = 'MSET';
    private const COMMAND_MSETNX = 'MSETNX';
    private const COMMAND_DEL = 'DEL';

    private const FLAG_EX = 'EX';
    private const FLAG_NX = 'NX';

    private const STATUS_OK = 'OK';

    public string $connection = 'phpredis';

    /** @var array{host?: string, port?: int, timeout?: int, retryInterval?: int, readTimeout?: float, password?: string, database?: int, serializer?: int} */
    public array $config = [];

    /** @var array{scheme?: string, host?: string, port?: int, ssl?: array{cafile: string, verify_peer: bool}} */
    public array $params = [];

    /** @var array{prefix?: string, exceptions?: bool, connections?: array, cluster?: string|callable, replication?: string|callable, aggregate?: callable, parameters?: array, commands?: string} */
    public array $options = [];

    /**
     * @phpstan-var PredisConnection|PredisClusterConnection|PhpRedisClusterConnection|PhpRedisConnection|null
     */
    private ?Connection $redis = null;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->redis = $this->connection(); /* @phpstan-ignore-line */
    }

    /**
     * Get a phpredis connection object.
     *
     * @return \Redis
     * @throws \RedisException
     */
    private function getPhpRedis(): \Redis
    {
        $redis = new \Redis();
        $redis->connect(
            (string)($this->config['host'] ?? '127.0.0.1'),
            (int)($this->config['port'] ?? 6379),
            (int)($this->config['timeout'] ?? 0),
            null,
            (int)($this->config['retryInterval'] ?? 0),
            (float)($this->config['readTimeout'] ?? 0.0)
        );

        if (isset($this->config['password'])) {
            $redis->auth($this->config['password']);
        }

        $redis->select((int)($this->config['database'] ?? 0));
        $redis->setOption(\Redis::OPT_SERIALIZER, $this->config['serializer'] ?? \Redis::SERIALIZER_NONE);

        return $redis;
    }

    /**
     * Get a redis connection object depending on the config.
     *
     * @return Connection
     * @throws \RedisException
     */
    private function connection(): Connection
    {
        return match ($this->connection) {
            'predis' => new PredisConnection(
                new Client($this->params, $this->options)
            ),
            'predisCluster' => new PredisClusterConnection(
                new Client($this->params, $this->options)
            ),
            'phpredisCluster' => new PhpRedisClusterConnection(
                $this->getPhpRedis(),
                null,
                $this->config
            ),
            default => new PhpRedisConnection(
                $this->getPhpRedis(),
                null,
                $this->config
            ),
        };
    }

    /**
     * @inheritdoc
     */
    public function exists($key): bool
    {
        return $this->returnBoolean($this->redis->command(self::COMMAND_EXISTS, [$this->buildKey($key)]));
    }

    /**
     * @inheritdoc
     */
    protected function getValue($key)
    {
        return $this->redis->command(self::COMMAND_GET, [$key]);
    }

    /**
     * @inheritdoc
     *
     * @param string[] $keys a list of keys identifying the cached values
     * @throws \RedisException
     */
    protected function getValues($keys): array
    {
        $result = $this->redis->command(self::COMMAND_MGET, [$keys]);

        // return data in [$key => $value] structure.
        return array_combine($keys, $result);
    }

    /**
     * @inheritdoc
     */
    protected function setValue($key, $value, $duration): bool
    {
        if ($duration === 0) {
            return $this->returnBoolean($this->redis->set($key, $value));
        }

        return $this->returnBoolean($this->redis->set($key, $value, self::FLAG_EX, $duration));
    }

    /**
     * @inheritdoc
     *
     * @param array<string, mixed> $data array where key corresponds to cache key while value is the value stored
     * @return string[] array of failed keys
     * @throws \RedisException
     */
    protected function setValues($data, $duration)
    {
        if ($duration === 0) {
            return $this->redis->command(self::COMMAND_MSET, [$data]);
        }

        return $this->redis->pipeline(static function ($pipe) use ($duration, $data) {
            foreach ($data as $key => $value) {
                $pipe->set($key, $value, [self::FLAG_EX => $duration]);
            }
        });
    }

    /**
     * @inheritdoc
     */
    protected function addValue($key, $value, $duration): bool
    {
        if ($duration === 0) {
            return $this->returnBoolean($this->redis->setnx($key, $value));
        }

        return $this->returnBoolean($this->redis->set($key, $value, self::FLAG_EX, $duration, self::FLAG_NX));
    }

    /**
     * @inheritdoc
     *
     * @param array<string, mixed> $data array where key corresponds to cache key while value is the value stored.
     * @return string[] array of failed keys
     * @throws \RedisException
     */
    protected function addValues($data, $duration): array
    {
        if ($duration === 0) {
            return $this->redis->command(self::COMMAND_MSETNX, [$data]);
        }

        return $this->redis->pipeline(static function ($pipe) use ($duration, $data) {
            foreach ($data as $key => $value) {
                $pipe->set($key, $value, [self::FLAG_EX => $duration, self::FLAG_NX]);
            }
        });
    }

    /**
     * @inheritdoc
     */
    protected function deleteValue($key): bool
    {
        return $this->returnBoolean($this->redis->command(self::COMMAND_DEL, [$key]));
    }

    /**
     * @inheritdoc
     */
    protected function flushValues(): bool
    {
        return $this->returnBoolean($this->redis->flushdb());
    }

    /**
     * @param int|bool|Status $value
     * @return bool
     */
    private function returnBoolean($value): bool
    {
        if ($value instanceof Status) {
            return Status::get((string)$value) === self::STATUS_OK;
        }

        return (bool)$value;
    }

    /**
     * Get a lock instance.
     *
     * @param string $name
     * @param int $seconds
     * @param string|null $owner
     * @return Lock
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): Lock
    {
        return match (true) {
            $this->redis instanceof PhpRedisConnection =>
                new PhpRedisLock($this->redis, $name, $seconds, $owner),
            default =>
                new RedisLock($this->redis, $name, $seconds, $owner),
        };
    }
}
