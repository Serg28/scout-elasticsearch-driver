<?php

return [
    // Настройки подключения к Elasticsearch
    'client' => [
        'hosts' => [
            env('SCOUT_ELASTIC_HOST', 'localhost:9200'),
        ],
    ],

    // Автоматическое обновление документа после изменений
    'document_refresh' => env('SCOUT_ELASTIC_DOCUMENT_REFRESH'),

    // Список моделей, которые индексируются через Scout Elastic
    'searchable_models' => [],

    // Включить логирование запросов к Elasticsearch
    'log_enabled' => env('SCOUT_ELASTIC_LOG_ENABLED', false),

    // Каналы логирования для Elasticsearch
    'log_channels' => ['es'],

    // Название подключения к очереди для индексации (например, redis)
    'queue_connection' => env('SCOUT_ELASTIC_QUEUE_CONNECTION', 'redis'),

    // Имя очереди по умолчанию для переиндексации
    'queue_name' => env('SCOUT_ELASTIC_QUEUE_NAME', 'shop-reindexModels'),

    // Индивидуальные очереди для конкретных моделей (опционально)
    // Ключ — FQCN модели, значение — имя очереди для этой модели
    'model_queues' => [
        // Пример:
        App\Models\Product::class => 'shop-reindexProducts', // Очередь только для Product
        App\Models\ModelRelate::class => 'shop-reindexModels', // Очередь только для ModelRelate
    ],
];
