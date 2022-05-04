# craft3-illuminate-redis-adapter
Redis Cache based on Illuminate redis for CraftCMS 3

# Choose an adapter
## PHPredis (recommended)
For best performance we recommend using ext-phpredis

## Config
~~~php
return [
    'components' => [
        'cache' => [
            'class' => DigitalNatives\Cache\Redis::class,
            'defaultDuration' => 86400,
            'connection' => 'phpredis',
            'config' => [
                'host' => getenv('REDIS_HOST'),
                'port' => (int)getenv('REDIS_PORT'),
                'database' => getenv('REDIS_DB'),
                'connectTimeout' => 60,
                'readTimeout' => 60,
                'serializer' => \Redis::SERIALIZER_NONE
            ],
        ],
    ],
];
~~~

## predis
When installing php extensions is not an option, predis is a very good option.

## Config
~~~php
return [
    'components' => [
        'cache' => [
            'class' => DigitalNatives\Cache\Redis::class,
            'defaultDuration' => 86400,
            'connection' => 'predis',
            'params' => [
                'host' => getenv('REDIS_HOST'),
                'port' => (int)getenv('REDIS_PORT'),
            ],
            'options' => [
                ['profile' => '5.0']
            ]           
        ],
    ],
];
~~~
