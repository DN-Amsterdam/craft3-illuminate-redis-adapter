<?php

declare(strict_types=1);

namespace DigitalNatives\Cache;

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
    private const COMMAND_DEL = 'DEL';

    private const FLAG_EX = 'EX';
    private const FLAG_NX = 'NX';

    private const STATUS_OK = 'OK';

    public string $connection = 'phpredis';
    public array $config = [];
    public array $params = [];
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
     */
    private function getPhpRedis(): \Redis
    {
        $redis = new \Redis();
        $redis->connect(
            (string)($this->config['host'] ?? '127.0.0.1'),
            (int)($this->config['port'] ?? 6379),
            (int)($this->config['timeout'] ?? 0),
            $this->config['timeout'] ?? null,
            (int)($this->config['retryInterval'] ?? 0),
            (float)($this->config['readTimeout'] ?? 0.0)
        );
        $redis->select((int)($this->config['database'] ?? 0));
        $redis->setOption(\Redis::OPT_SERIALIZER, $this->config['serializer'] ?? \Redis::SERIALIZER_NONE);

        return $redis;
    }

    /**
     * Get a redis connection object depending on the config.
     *
     * @return Connection
     */
    private function connection(): Connection
    {
        switch ($this->connection) {
            case 'predis':
                return new PredisConnection(
                    new Client($this->params, $this->options)
                );
            case 'predisCluster':
                return new PredisClusterConnection(
                    new Client($this->params, $this->options)
                );
            case 'phpredisCluster':
                return new PhpRedisClusterConnection(
                    $this->getPhpRedis(),
                    null,
                    $this->config
                );
            case 'phpredis':
            default:
                return new PhpRedisConnection(
                    $this->getPhpRedis(),
                    null,
                    $this->config
                );
        }
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

    protected function setValues($data, $duration)
    {
        if ($duration === 0) {
            return $this->redis->command(self::COMMAND_MSET, [$data]);
        }

        return $this->redis->pipeline(static function ($pipe) use ($duration, $data) {
            foreach ($data as $key => $value) {
                $pipe->setEx($key, $duration, $value);
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
}
