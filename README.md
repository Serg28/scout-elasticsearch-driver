# Scout Elasticsearch Driver

Этот пакет представляет собой адаптацию [babenkoivan/scout-elasticsearch-driver](https://github.com/babenkoivan/scout-elasticsearch-driver) для работы с Elasticsearch >= 7.0.0.

## Особенности

* Тип модели теперь сохраняется в поле `type` в соответствии с [рекомендациями Elasticsearch](https://www.elastic.co/guide/en/elasticsearch/reference/7.x/removal-of-types.html#_custom_type_field)
* После поиска модели атрибут `_score` будет доступен в результирующей модели
* Логирование запросов к Elasticsearch через стандартные каналы Laravel

## Доступные консольные команды

| Команда | Описание | Пример использования | Параметры |
|---------|----------|----------------------|-----------|
| `elastic:reindex` | Создание нового индекса и наполнение его данными с переключением алиаса (zero downtime reindex) | `php artisan elastic:reindex "App\MyIndexConfigurator"` | Название класса конфигуратора индекса |
| `elastic:drop-index` | Удаление индекса и его алиасов | `php artisan elastic:drop-index "App\MyIndexConfigurator"` | Название класса конфигуратора индекса |
| `make:index-configurator` | Создание класса конфигуратора индекса | `php artisan make:index-configurator MyIndexConfigurator` | Название создаваемого класса |
| `make:search-rule` | Создание класса правила поиска | `php artisan make:search-rule MySearchRule` | Название создаваемого класса |

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

    // Маппинг названий индексов Elasticsearch к моделям (для MixedSearch)
    // ОБЯЗАТЕЛЬНЫЙ параметр для работы MixedSearch
    'type_mapping' => [
         'siteprefix_products' => \App\Models\Product::class,
         'siteprefix_model_relate' => \App\Models\ModelRelate::class,
    ],
];
```

## Конфигуратор индекса

Для настройки индекса Elasticsearch используется класс-конфигуратор. Вы можете создать свой конфигуратор с помощью команды:

```bash
php artisan make:index-configurator MyIndexConfigurator
```
Подробнее о настройках индекса можно узнать в [разделе управления индексами](https://www.elastic.co/guide/en/elasticsearch/guide/current/index-management.html) документации Elasticsearch.

Обратите внимание, что для каждой модели поиска требуется собственный конфигуратор индекса.

> Индексы, созданные в Elasticsearch 6.0.0 или более поздней версии, могут содержать только один тип сопоставления. Индексы, созданные в 5.x с несколькими типами сопоставления, продолжат функционировать в Elasticsearch 6.x, как и прежде. Типы сопоставления будут полностью удалены в Elasticsearch 7.0.0.

Более подробную информацию можно найти [здесь](https://www.elastic.co/guide/en/elasticsearch/reference/6.x/removal-of-types.html).

После выполнения команды вы найдете файл `MyIndexConfigurator.php` в папке `app`. Пример конфигуратора:

```php
<?php

namespace App;

use Novius\ScoutElastic\IndexConfigurator;

class MyIndexConfigurator extends IndexConfigurator
{
    protected $name = 'my_index';

    public function mapping()
    {
        return [
            'properties' => [
                'title' => [
                    'type' => 'text',
                    'analyzer' => 'standard',
                ],
                'content' => [
                    'type' => 'text',
                    'analyzer' => 'standard',
                ],
                'created_at' => [
                    'type' => 'date',
                ],
            ],
        ];
    }

    public function settings()
    {
        return [
            'number_of_shards' => 1,
            'number_of_replicas' => 0,
        ];
    }
}
```

Для создания индекса и его переиндексации используйте команду:

```bash
php artisan elastic:reindex "App\MyIndexConfigurator"
```

Эта команда создаст новый индекс на основе конфигуратора и выполнит переиндексацию данных без простоя.

Для удаления индекса с указанием конфигуратора:

```bash
php artisan elastic:drop-index "App\MyIndexConfigurator"
```

Вы можете указать поля, которые будут индексироваться драйвером, с помощью метода `toSearchableArray`.
Подробнее об этих параметрах можно узнать в [официальной документации Scout](https://laravel.com/docs/5.5/scout#configuration).

## Правила поиска

Для создания кастомных правил поиска используйте команду:

```bash
php artisan make:search-rule MySearchRule
```

В файле app/MySearchRule.php вы найдете определение класса:

```php
<?php

namespace App;

use Novius\ScoutElastic\SearchRule;

class MySearchRule extends SearchRule
{
    // This method returns an array, describes how to highlight the results.
    // If null is returned, no highlighting will be used. 
    public function buildHighlightPayload()
    {
        return [
            'fields' => [
                'name' => [
                    'type' => 'plain'
                ]
            ]
        ];
    }
    
    // This method returns an array, that represents bool query.
    public function buildQueryPayload()
    {
        return [
            'must' => [
                'match' => [
                    'name' => $this->builder->query
                ]
            ]
        ];
    }
}
```

Подробнее о запросах типа bool можно прочитать [здесь](https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-bool-query.html)
и о подсветке [здесь](https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-body.html#request-body-search-highlighting).

Правило поиска по умолчанию возвращает следующую полезную нагрузку:

```php 
return [ 
   'must' => [ 
       'query_string' => [ 
           'query' => $this->builder->query 
       ] 
   ] 
]; 
``` 

Это означает, что по умолчанию при вызове метода `search` для модели он пытается найти строку запроса в любом поле.

Чтобы подключить правило поиска к модели, добавьте его в свойство `$searchRules`:

```php
class Product extends Model
{
    use Novius\ScoutElastic\Searchable;

    protected $searchRules = [
        \App\ProductSearchRule::class,
    ];

    // Конфигуратор индекса
    public function getScoutIndexConfigurator()
    {
        return \App\ProductsIndexConfigurator::class;
    }
}
```

Вы также можете задать правило поиска в конструкторе запросов:

```php
// Вы можете задать либо класс SearchRule 
App\MyModel::search('Brazil')
    ->rule(App\MySearchRule::class)
    ->get();
    
// или в вызове
App\MyModel::search('Brazil')
    ->rule(function($builder) {
        return [
            'must' => [
                'match' => [
                    'Country' => $builder->query
                ]
            ]
        ];
    })
    ->get();
```

Чтобы получить подсветку, используйте атрибут модели `highlight`:

```php
// Допустим, мы подсвечиваем поле `name` из `MyModel`.
$model = App\MyModel::search('Brazil')
    ->rule(App\MySearchRule::class)
    ->first();

// Теперь вы можете получить необработанное подсвеченное значение:
$model->highlight->name;

// или строковое значение: 
 $model->highlight->nameAsString;
```

## Использование

### Доступные фильтры

| Метод | Описание | Пример для обычного поиска | Пример для MixedSearch |
|-------|----------|----------------------------|-----------------------|
| where | Простое условие | `->where('field', 'value')` | `->where('field', 'value', 'index_name')` |
| orWhere | Альтернативное условие | `->orWhere('field', 'value')` | `->orWhere('field', 'value', 'index_name')` |
| whereIn | Проверка на вхождение в массив | `->whereIn('field', [1, 2, 3])` | `->whereIn('field', [1, 2, 3], 'index_name')` |
| orWhereIn | Альтернативное условие на вхождение | `->orWhereIn('field', [1, 2, 3])` | `->orWhereIn('field', [1, 2, 3], 'index_name')` |
| whereNotIn | Исключение значений | `->whereNotIn('field', [1, 2, 3])` | `->whereNotIn('field', [1, 2, 3], 'index_name')` |
| orWhereNotIn | Альтернативное условие исключения | `->orWhereNotIn('field', [1, 2, 3])` | `->orWhereNotIn('field', [1, 2, 3], 'index_name')` |
| whereBetween | Диапазон значений | `->whereBetween('field', [1, 10])` | `->whereBetween('field', [1, 10], 'index_name')` |
| orWhereBetween | Альтернативное условие диапазона | `->orWhereBetween('field', [1, 10])` | `->orWhereBetween('field', [1, 10], 'index_name')` |
| whereNotBetween | Исключение диапазона | `->whereNotBetween('field', [1, 10])` | `->whereNotBetween('field', [1, 10], 'index_name')` |
| orWhereNotBetween | Альтернативное условие исключения диапазона | `->orWhereNotBetween('field', [1, 10])` | `->orWhereNotBetween('field', [1, 10], 'index_name')` |
| whereMatch | Полнотекстовый поиск | `->whereMatch('field', 'text')` | `->whereMatch('field', 'text', 'index_name')` |
| orWhereMatch | Альтернативное полнотекстовое условие | `->orWhereMatch('field', 'text')` | `->orWhereMatch('field', 'text', 'index_name')` |
| whereRegexp | Поиск по регулярному выражению | `->whereRegexp('field', 'pattern')` | `->whereRegexp('field', 'pattern', 'index_name')` |
| orWhereRegexp | Альтернативное условие регулярного выражения | `->orWhereRegexp('field', 'pattern')` | `->orWhereRegexp('field', 'pattern', 'index_name')` |
| whereGeoDistance | Географическая близость | `->whereGeoDistance('location', [lat, lon], '10km')` | `->whereGeoDistance('location', [lat, lon], '10km', 'index_name')` |
| whereGeoBoundingBox | Географический прямоугольник | `->whereGeoBoundingBox('location', [[lat1, lon1], [lat2, lon2]])` | `->whereGeoBoundingBox('location', [[lat1, lon1], [lat2, lon2]], 'index_name')` |
| whereGeoPolygon | Географический многоугольник | `->whereGeoPolygon('location', [[lat1, lon1], [lat2, lon2], ...])` | `->whereGeoPolygon('location', [[lat1, lon1], [lat2, lon2], ...], 'index_name')` |
| whereGeoShape | Географическая форма | `->whereGeoShape('location', $shape, 'INTERSECTS')` | `->whereGeoShape('location', $shape, 'INTERSECTS', 'index_name')` |
| whereExists | Проверка существования поля | `->whereExists('field')` | `->whereExists('field', 'index_name')` |
| orWhereExists | Альтернативное условие существования | `->orWhereExists('field')` | `->orWhereExists('field', 'index_name')` |
| whereNotExists | Проверка отсутствия поля | `->whereNotExists('field')` | `->whereNotExists('field', 'index_name')` |
| orWhereNotExists | Альтернативное условие отсутствия | `->orWhereNotExists('field')` | `->orWhereNotExists('field', 'index_name')` |
| collapse | Группировка результатов | `->collapse('field')` | `->collapse('field', 'index_name')` |

## Требования

* PHP >= 8.1
* Laravel Framework >= 10
* Elasticsearch >= 7.0.0

## Установка

```bash
composer require novius/laravel-scout-elasticsearch-driver:dev-laravel10-12
```

## Конфигурация

Для настройки пакета сначала опубликуйте настройки:

```bash
php artisan vendor:publish --provider="Laravel\Scout\ScoutServiceProvider"
php artisan vendor:publish --provider="Novius\ScoutElastic\ScoutElasticServiceProvider"
```

Затем установите драйвер `elastic` в файле `config/scout.php` и настройте драйвер в файле `config/scout_elastic.php`.

## Добавленные возможности

### Отключение гидратации модели из базы данных

У модели можно установить свойство `$databaseHydrate`, которое при значении false делает то же самое, что и метод `toBase()`:

```php
public bool $databaseHydrate = false;
```

При `false` будут возвращаться также и "виртуальные" атрибуты, как они определены в индексе и перечислены в `$fillable`.
При `true` из индекса возвращается реальная модель, как при обычном Eloquent-запросе, без "виртуальных" атрибутов.

### Возможность добавления данных в тело каждого запроса

Можно добавлять к запросу параметры на уровне query в body:

```php
protected $searchSettings = [ 'track_total_hits' => true, ];
```

## Использование

После создания конфигуратора индекса, самого индекса Elasticsearch и модели для поиска вы готовы к работе.
Теперь вы можете [индексировать](https://laravel.com/docs/5.5/scout#indexing) и [искать](https://laravel.com/docs/5.5/scout#searching) данные в соответствии с документацией.

Пример использования простого поиска:

```php 
// задать строку запроса 
App\MyModel::search('phone') 
    // указать столбцы для выборки 
    ->select(['title', 'price']) 
    // фильтр 
    ->where('color', 'red') 
    // сортировка 
    ->orderBy('price', 'asc') 
    // свернуть по полю 
    ->collapse('brand') 
    // установить смещение 
    ->from(0) 
    // установить лимит 
    ->take(10) 
    // получить результаты 
    ->get(); 
``` 

Если вам нужно только количество совпадений для запроса, используйте метод `count`:

```php 
App\MyModel::search('phone') 
    ->count(); 
``` 

Если вам нужно загрузить связи, используйте метод `with`:

```php 
App\MyModel::search('phone') 
    ->with('makers') 
    ->get(); 
``` 

В дополнение к стандартной функциональности пакет предлагает вам возможность фильтровать данные в Elasticsearch без указания строки запроса:

```php 
App\MyModel::search('*') 
    ->where('id', 1) 
    ->get(); 
``` 

Также вы можете переопределить [правила поиска](#search-rules) модели:

```php 
App\MyModel::search('Brazil') 
    ->rule(App\MySearchRule::class) 
    ->get(); 
``` 

И использовать [разнообразие](#available-filters) условий `where`:

```php 
App\MyModel::search('*') 
    ->whereRegexp('name.raw', 'A.+') 
    ->where('age', '>=',30) 
    ->whereExists('безработный') 
    ->get(); 
```

И отфильтровать результаты с оценкой меньше [min_score](https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-body.html#request-body-search-min-score):

```php 
App\MyModel::search('sales') 
    ->minScore(1.0) 
    ->get(); 
``` 

И добавить более сложную сортировку (например, geo_distance)

```php 
$model = App\MyModel::search('sales') 
    ->orderRaw([ 
       '_geo_distance' => [ 
           'coordinates' => [ 
               'lat' => 51.507351, 
               'lon' => -0.127758 
           ], 
           'order' => 'asc', 
           'unit' => 'm' 
       ] 
    ]) 
    ->get(); 

// Чтобы получить результат сортировки, используйте атрибут модели `sortPayload`: 
$model->sortPayload; 
``` 



Наконец, если вы хотите отправить пользовательский запрос, вы можете использовать метод `searchRaw`:

```php 
App\MyModel::searchRaw([ 
    'query' => [ 
        'bool' => [ 
            'must' => [ 
                'match' => [ 
                    '_all' => 'Brazil' 
                ] 
            ] 
        ] 
    ] 
]); 
``` 

Этот запрос вернет необработанный ответ.


---

# MixedSearch - Многоиндексный поиск

MixedSearch позволяет выполнять поиск по нескольким индексам Elasticsearch одновременно, с возможностью фильтрации, агрегации и других операций.

## Настройка MixedSearch

Для работы MixedSearch обязательно нужно настроить параметр `type_mapping` в конфигурационном файле `config/scout_elastic.php`. Этот параметр определяет соответствие между названиями индексов Elasticsearch и классами моделей Laravel.

Пример настройки:

```php
'type_mapping' => [
    'siteprefix_products' => \App\Models\Product::class,
    'siteprefix_model_relate' => \App\Models\ModelRelate::class,
],
```

## Основные возможности

### 1. Базовый поиск по нескольким индексам

```php
use Novius\ScoutElastic\Builders\MixedSearch;

$results = MixedSearch::create()
    ->within(\App\Models\Product::class)
    ->within(\App\Models\ModelRelate::class)
    ->query('поисковый запрос')
    ->get();
```

### 2. Выборка полей по индексам

Можно указать, какие поля нужно вернуть для каждого индекса:

```php
$results = MixedSearch::create()
    ->within(\App\Models\Product::class)
    ->within(\App\Models\ModelRelate::class)
    ->select([
        'siteprefix_products' => [
            'external_id' => 'product_external_id',
            'code'
        ],
        'siteprefix_model_relate' => [
            'product_external_id'
        ]
    ])
    ->query('поисковый запрос')
    ->get();
```

Где:
- Ключ - полное название индекса
- Значение - массив полей выборки у этой модели
- Поля могут быть либо простым значением (например, 'code'), либо ключ-значение, где ключ - оригинальное название поля, а значение - алиас

### 3. Условия фильтрации

Можно применять фильтры ко всем индексам или к конкретному индексу. Для всех методов фильтрации можно указать название индекса в качестве последнего параметра. Полный список поддерживаемых методов смотрите в таблице [Доступные фильтры](#доступные-фильтры).

Пример использования:

```php
$results = MixedSearch::create()
    ->within(\App\Models\Product::class)
    ->within(\App\Models\ModelRelate::class)
    ->where('status', 'active') // для всех индексов
    ->where('price', '>', 100, 'siteprefix_products') // только для products
    ->where('parent_id', '!=', null, 'siteprefix_model_relate') // только для model_relate
    ->get();
```

### 4. Агрегации

Можно выполнять агрегации с указанием полей для каждого индекса:

```php
$results = MixedSearch::create()
    ->within(\App\Models\Product::class)
    ->within(\App\Models\ModelRelate::class)
    ->aggregate([
        'by_docs' => [
            'terms' => [
                'field' => 'external_id',
                'size' => 1000,
            ],
        ],
    ], [
        'by_docs' => [
            'siteprefix_products' => 'external_id',
            'siteprefix_model_relate' => 'product_external_id'
        ]
    ])
    ->get();

$aggregations = $results->aggregations();
```

Где второй массив - это название индекса и поле, которое будет использоваться для field.

### 5. Коллапс результатов

Можно группировать результаты по полю с указанием индекса:

```php
$results = MixedSearch::create()
    ->within(\App\Models\Product::class)
    ->within(\App\Models\ModelRelate::class)
    ->collapse('external_id', 'siteprefix_products')
    ->collapse('product_external_id', 'siteprefix_model_relate')
    ->get();
```

Где второй параметр - полное название индекса.

### 6. Пагинация

Обычная пагинация:

```php
$paginator = MixedSearch::create()
    ->within(\App\Models\Product::class)
    ->query('поисковый запрос')
    ->paginate(20);
```

Search_after пагинация для больших наборов данных:

```php
$result = MixedSearch::create()
    ->within(\App\Models\Product::class)
    ->query('поисковый запрос')
    ->paginateWithSearchAfter(20, $searchAfterValues);

echo "Total: " . $result['total'];
$nextSearchAfter = $result['search_after']; // для следующей страницы
```

### 7. Возврат базовой коллекции

Метод `toBase()` позволяет вернуть базовую коллекцию вместо моделей:

```php
$results = MixedSearch::create()
    ->within(\App\Models\Product::class)
    ->query('поисковый запрос')
    ->toBase()
    ->get();
```

### 8. Асинхронный поиск

Для выполнения нескольких запросов одновременно:

```php
$results = MixedSearch::create()
    ->within(\App\Models\Product::class)
    ->within(\App\Models\ModelRelate::class)
    ->query('поисковый запрос')
    ->getAsync(); // использует msearch API
```

### 9. Профилирование и мониторинг
```php
$stats = MixedSearch::create()
    ->within(\App\Models\Product::class)
    ->query('search term')
    ->getQueryStats();

// Результат:
// [
//     'execution_time_ms' => 25.4,
//     'total_hits' => 1500,
//     'results_count' => 20,
//     'took_elasticsearch' => 8,
//     'shards' => ['total' => 5, 'successful' => 5, 'failed' => 0]
// ]
```

### 10. Дополнительные методы
```php
// Получить только количество результатов
$count = MixedSearch::create()
    ->within(\App\Models\Product::class)
    ->where('status', 'active')
    ->count();

// Получить уникальные значения поля
$categories = MixedSearch::create()
    ->within(\App\Models\Product::class)
    ->distinct('category_id');

// Предложения (suggestions)
$suggestions = MixedSearch::create()
    ->within(\App\Models\Product::class)
    ->suggest([
        'name_suggest' => [
            'text' => 'prodcut', // опечатка
            'term' => ['field' => 'name']
        ]
    ]);

// Объяснение релевантности
$explain = MixedSearch::create()
    ->within(\App\Models\Product::class)
    ->query('search term')
    ->explainDocument('product_id_123', 'products');
```

### 11. Управление кэшами

#### Мониторинг использования памяти
```php
$stats = MixedSearch::getCacheStats();
/*
[
    'model_instances_count' => 25,
    'model_meta_count' => 150,
    'class_exists_count' => 50,
    'memory_usage' => 8388608,    // 8MB
    'peak_memory' => 12582912     // 12MB
]
*/
```
#### Очистка кэшей
```php
// Очистить все кэши для освобождения памяти
MixedSearch::clearAllCaches();

// Установить лимиты для предотвращения утечки памяти
MixedSearch::setCacheLimits(100, 500);
```


## Примеры использования

### Пример 1: Поиск по двум индексам с фильтрацией

```php
$results = MixedSearch::create()
    ->within(\App\Models\Product::class)
    ->within(\App\Models\ModelRelate::class)
    ->select([
        'siteprefix_products' => ['external_id', 'code', 'name'],
        'siteprefix_model_relate' => ['product_external_id', 'title']
    ])
    ->where('status', 'active')
    ->where('price', '>', 100, 'siteprefix_products')
    ->orderBy('created_at', 'desc')
    ->paginate(20);
```

### Пример 2: Агрегации с группировкой по полям

```php
$results = MixedSearch::create()
    ->within(\App\Models\Product::class)
    ->within(\App\Models\ModelRelate::class)
    ->aggregate([
        'by_category' => [
            'terms' => [
                'field' => 'category_id',
                'size' => 10,
            ],
        ],
        'price_stats' => [
            'stats' => ['field' => 'price']
        ],
    ], [
        'by_category' => [
            'siteprefix_products' => 'category_id',
            'siteprefix_model_relate' => 'category_id'
        ],
        'price_stats' => [
            'siteprefix_products' => 'price',
            'siteprefix_model_relate' => 'price'
        ]
    ])
    ->get();

$aggregations = $results->aggregations();
```

### Пример 3: Коллапс результатов с пагинацией

```php
$result = MixedSearch::create()
    ->within(\App\Models\Product::class)
    ->within(\App\Models\ModelRelate::class)
    ->collapse('external_id', 'siteprefix_products')
    ->collapse('product_external_id', 'siteprefix_model_relate')
    ->paginateWithSearchAfter(20, $searchAfterValues);

$products = $result['results'];
$nextSearchAfter = $result['search_after'];
$total = $result['total'];
```

### Настройки для максимальной производительности

#### В config/scout_elastic.php:
```php
return [
    // Отключить логирование в продакшене
    'log_enabled' => env('SCOUT_ELASTIC_LOG_ENABLED', false),
    
    // Другие настройки...
];
```

### В .env:
```bash
# Отключить логирование Elasticsearch в продакшене
SCOUT_ELASTIC_LOG_ENABLED=false

# Включить только для разработки/отладки
SCOUT_ELASTIC_LOG_ENABLED=true
```

### Рекомендации по использованию

1. **Для больших данных (>100k документов)**: используйте `paginateWithSearchAfter()` вместо обычной пагинации
2. **При множественных запросах**: используйте `getAsync()` для параллельного выполнения
3. **В продакшене**: отключайте логирование (`scout_elastic.log_enabled = false`)
4. **Для длительных процессов**: периодически очищайте кэши с помощью `clearAllCaches()`
5. **Мониторинг**: используйте `getQueryStats()` и `getCacheStats()` для контроля производительности


## Лицензия

Этот пакет распространяется по лицензии MIT.
