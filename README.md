# Scout Elasticsearch Driver
[![Travis](https://img.shields.io/travis/novius/laravel-scout-elasticsearch-driver.svg?maxAge=1800&style=flat-square)](https://travis-ci.org/novius/laravel-scout-elasticsearch-driver)
[![Packagist Release](https://img.shields.io/packagist/v/novius/laravel-scout-elasticsearch-driver.svg?maxAge=1800&style=flat-square)](https://packagist.org/packages/novius/laravel-scout-elasticsearch-driver)
[![Licence](https://img.shields.io/packagist/l/novius/laravel-scout-elasticsearch-driver.svg?maxAge=1800&style=flat-square)](https://github.com/novius/laravel-scout-elasticsearch-driver#licence)

This package is an adaptation of [babenkoivan/scout-elasticsearch-driver ](https://github.com/babenkoivan/scout-elasticsearch-driver) to get working with Elasticsearch >= 7.0.0

This package version was created to be compatible with [Elasticsearch "Removal of mapping types"](https://www.elastic.co/guide/en/elasticsearch/reference/7.x/removal-of-types.html#removal-of-types) introduced in Elasticsearch >= 7.0.0

## Features added

* Model's type is now saved in `type` field by default according to [Elasticsearch recommendations](https://www.elastic.co/guide/en/elasticsearch/reference/7.x/removal-of-types.html#_custom_type_field)

* After a model search, an attribute `_score` will be hydrated on your result Model.

Example : 

```php
$results = MyModel::search('keywords')->get();
foreach ($results as $result) {
    // Score is now available in : $result->_score
}
```

* Logs : you can now use Laravel loggers to log ElasticSearch's requests

To enable logs you have to set `SCOUT_ELASTIC_LOG_ENABLED` to `true` and specify which log's channel(s) to use.

Example :

***config/logging.php***
```php
<?php

return [

    ...
    
    'channels' => [

        ...

        'es' => [
            'driver' => 'daily',
            'path' => storage_path('logs/es.log'),
            'level' => 'debug',
            'days' => 5,
        ],
    ],
];
```

## Доступные artisan-команды

- `elastic:reindex` — создание нового индекса и наполнение его данными с переключением алиаса (zero downtime reindex). Если индекс уже существует, будет создан новый с новым именем и переключён алиас.
- `elastic:drop-index` — удаление индекса и его алиасов.

## Конфигурация scout_elastic.php

Файл `config/scout_elastic.php` содержит расширенные настройки для работы с Elasticsearch и очередями:

```php
return [
    // Настройки подключения к Elasticsearch
    'client' => [
        'hosts' => [env('SCOUT_ELASTIC_HOST', 'localhost:9200')],
    ],
    // Автоматическое обновление документа после изменений
    'document_refresh' => env('SCOUT_ELASTIC_DOCUMENT_REFRESH'),
    // Список моделей, которые индексируются через Scout Elastic
    'searchable_models' => [
        'App\\Models\\Product',
        'App\\Models\\ModelRelate',
        // ...
    ],
    // Включить логирование запросов к Elasticsearch
    'log_enabled' => env('SCOUT_ELASTIC_LOG_ENABLED', false),
    // Каналы логирования для Elasticsearch
    'log_channels' => ['es'],

    // Название подключения к очереди (например, redis)
    'queue_connection' => env('SCOUT_ELASTIC_QUEUE_CONNECTION', 'redis'),
    // Имя очереди по-умолчанию для переиндексации
    'queue_name' => env('SCOUT_ELASTIC_QUEUE_NAME', 'shop-reindexModels'),

    // Индивидуальные очереди для конкретных моделей (опционально)
    // Ключ — FQCN модели, значение — имя очереди для этой модели
    'model_queues' => [
        // Пример:
        App\\Models\\Product::class => 'shop-reindexProducts',
        App\\Models\\ModelRelate::class => 'shop-reindexModels',
    ],
];
```

- `queue_connection` — название подключения к очереди (например, redis, database и т.д.)
- `queue_name` — имя очереди по умолчанию для всех моделей
- `model_queues` — массив индивидуальных очередей для конкретных моделей (ключ — FQCN модели, значение — имя очереди)

Это позволяет гибко управлять процессом индексации и отслеживать прогресс для каждой модели отдельно.

## Requirements

* PHP >= 7.4
* Laravel Framework >= 7.25
* Elasticsearch >= 7.0.0

*For Laravel > 6 and < 7.25 you can install 2.x version.*

## Installation

```sh
composer require novius/laravel-scout-elasticsearch-driver:dev-master
```

## Configuration

To configure the package you need to publish settings first:

```
php artisan vendor:publish --provider="Laravel\Scout\ScoutServiceProvider"
php artisan vendor:publish --provider="Novius\ScoutElastic\ScoutElasticServiceProvider"
```

Then, set the driver setting to `elastic` in the `config/scout.php` file and configure the driver itself in the `config/scout_elastic.php` file.
The available options are:

Option | Description
--- | ---
client | A setting hash to build Elasticsearch client. More information you can find [here](https://www.elastic.co/guide/en/elasticsearch/client/php-api/current/configuration.html#_building_the_client_from_a_configuration_hash). By default the host is set to `localhost:9200`.
document_refresh | This option controls when updated documents appear in the search results. Can be set to `'true'`, `'false'`, `'wait_for'` or `null`. More details about this option you can find [here](https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-refresh.html). By default set to `null`.

Note, that if you use the bulk document indexing you'll probably want to change the chunk size, you can do that in the `config/scout.php` file.


## Added

### Disable db hydration per model
https://github.com/babenkoivan/scout-elasticsearch-driver/pull/218

Handles model property (boolean) $databaseHydrate to add option to disable database data hydration

У модели устанавливаем свойство:
```php
    public bool $databaseHydrate = false;
```
При false будут возвращаться также и "виртуальные" атрибуты, как они определены в индексе и перечислены в $fillable
При true из индекса возвращается реальная модель, как при обычном eloquent-запросе, без "виртуальных" атрибутов

### Adds the ability to add data into the body of each request set in the Seachable model.
https://github.com/babenkoivan/scout-elasticsearch-driver/pull/363

Возможность добавлять к запросу параметры на уровне query в body
```php
protected $searchSettings = [ 'track_total_hits' => true, ];
```

## Usage

Please read the [original package documentation](https://github.com/babenkoivan/scout-elasticsearch-driver). 

## Lint

Run php-cs with:

```sh
composer run-script lint
```

## Contributing

Contributions are welcome!
Leave an issue on Github, or create a Pull Request.


## Licence

This package is under MIT Licence.
