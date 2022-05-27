# craft3-illuminate-redis-adapter
Redis Cache based on Illuminate redis for CraftCMS 3

# Installation
You can install this package using composer;
```
composer require digitalnatives/craft3-illuminate-redis-adapter
```

## Choose an adapter
### PHPredis (recommended)
For best performance we recommend using ext-phpredis

#### Config
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

### predis
When installing php extensions is not an option, predis is a very good option.

If you haven't installed predis, install it using composer;
```
composer require predis/predis
```

#### Config
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
