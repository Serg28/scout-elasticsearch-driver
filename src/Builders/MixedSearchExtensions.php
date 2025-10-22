<?php

namespace Novius\ScoutElastic\Builders;

use Illuminate\Support\Collection;
use Novius\ScoutElastic\Facades\ElasticClient;

/**
 * Расширения для MixedSearch - дополнительные методы оптимизации
 * 
 * Этот трейт содержит дополнительные методы для:
 * - Search_after пагинация для больших наборов данных
 * - Асинхронные запросы через msearch
 * - Профилирование и мониторинг
 * - Методы очистки кэша
 */
trait MixedSearchExtensions
{
    /** @var array|null Значения для search_after пагинации */
    protected $searchAfter;
    
    /** @var bool Включить профилирование запросов */
    protected $enableProfiling = false;
    
    /** @var array Буфер для отложенного логирования */
    private static $logBuffer = [];
    
    // Кэши для совместимости с MixedSearch (если не определены в классе)
    /** @var array Кэш экземпляров моделей */
    private static $modelInstancesCache = [];
    
    /** @var array Кэш метаданных моделей */
    private static $modelMetaCache = [];
    
    /** @var array Кэш проверок существования классов */
    private static $classExistsCache = [];
    
    /** @var bool Включено ли логирование */
    private static $loggingEnabled;

    /**
     * Устанавливает search_after для пагинации больших наборов данных
     * 
     * @param array $values Массив значений для продолжения поиска
     * @return $this
     */
    public function searchAfter(array $values)
    {
        $this->searchAfter = $values;
        return $this;
    }

    /**
     * Включает профилирование запросов
     * 
     * @param bool $enable Включить или отключить
     * @return $this
     */
    public function enableProfiling(bool $enable = true)
    {
        $this->enableProfiling = $enable;
        return $this;
    }

    /**
     * Выполняет асинхронный поиск с использованием msearch
     * Эффективен когда нужно выполнить несколько запросов одновременно
     * 
     * @return Collection
     */
    public function getAsync(): Collection
    {
        if (empty($this->indices)) {
            throw new \InvalidArgumentException('Необходимо указать хотя бы один индекс или модель.');
        }

        $payloads = $this->buildSearchQueryPayloadCollection();
        
        if ($payloads->count() <= 1) {
            // Для одного запроса используем обычный метод
            return $this->get();
        }

        return $this->executeMultiSearch($payloads);
    }

    /**
     * Выполняет множественный поиск через msearch API
     */
    private function executeMultiSearch(Collection $payloads): Collection
    {
        $msearchBody = [];
        
        // Формируем тело запроса для msearch
        foreach ($payloads as $payload) {
            $msearchBody[] = [
                'index' => $payload['index'],
                'type' => '_doc'
            ];
            $msearchBody[] = $payload['body'];
        }

        if (self::$loggingEnabled) {
            $this->bufferLog('Elasticsearch msearch query', ['body_count' => count($msearchBody) / 2]);
        }

        // Выполняем msearch запрос
        $response = ElasticClient::msearch(['body' => $msearchBody]);
        
        return $this->processMsearchResponse($response, $payloads);
    }

    /**
     * Обрабатывает ответ от msearch API
     */
    private function processMsearchResponse(array $response, Collection $payloads): Collection
    {
        $engine = $this->engine();
        $results = new Collection();

        foreach ($response['responses'] as $index => $singleResponse) {
            if (isset($singleResponse['error'])) {
                if (self::$loggingEnabled) {
                    \Log::channel(config('scout_elastic.log_channels')[0])
                        ->error('Elasticsearch msearch error', $singleResponse['error']);
                }
                continue;
            }

            // Добавляем payload для совместимости
            $singleResponse['_payload'] = $payloads->get($index);
            $mappedResults = $engine->map($this, $singleResponse, null);
            $results = $results->merge($mappedResults);
        }

        if (isset($this->with) && $results->count() > 0) {
            $results->load($this->with);
        }

        return $results;
    }

    /**
     * Пагинация с использованием search_after для больших наборов данных
     * Более эффективна чем offset-based пагинация для deep pagination
     * 
     * @param int $perPage Количество элементов на странице
     * @param array|null $searchAfter Значения для продолжения поиска
     * @return array ['results' => Collection, 'search_after' => array|null]
     */
    public function paginateWithSearchAfter(int $perPage = 15, ?array $searchAfter = null): array
    {
        if ($searchAfter) {
            $this->searchAfter($searchAfter);
        }
        
        $this->take($perPage);
        
        // Для search_after нужна сортировка
        if (empty($this->orders)) {
            $this->orders = [['_id' => 'asc']];
        }

        // Включаем профилирование для получения _score
        $this->enableProfiling(true);

        $rawResults = $this->engine()->rawSearch($this);
        $results = $this->engine()->map($this, $rawResults, null);

        // Извлекаем search_after из последнего результата
        $nextSearchAfter = null;
        if (!empty($rawResults['hits']['hits'])) {
            $lastHit = end($rawResults['hits']['hits']);
            $nextSearchAfter = $lastHit['sort'] ?? null;
            
            // Логируем для отладки
            if (self::$loggingEnabled) {
                \Log::channel(config('scout_elastic.log_channels')[0])
                    ->debug('Search after values', [
                    'last_hit_sort' => $lastHit['sort'] ?? null,
                    'last_hit_id' => $lastHit['_id'] ?? null,
                    'last_hit_score' => $lastHit['_score'] ?? null,
                ]);
            }
        }

        if (isset($this->with) && $results->count() > 0) {
            $results->load($this->with);
        }

        return [
            'results' => $results,
            'search_after' => $nextSearchAfter,
            'total' => $this->engine()->getTotalCount($rawResults)
        ];
    }

    /**
     * Получает статистику выполнения запроса
     * Полезно для оптимизации и мониторинга
     * 
     * @return array
     */
    public function getQueryStats(): array
    {
        $this->enableProfiling(true);
        
        $start = microtime(true);
        $results = $this->get();
        $executionTime = (microtime(true) - $start) * 1000; // в миллисекундах

        $rawResults = $this->engine()->rawSearch($this);
        
        return [
            'execution_time_ms' => round($executionTime, 2),
            'total_hits' => $this->engine()->getTotalCount($rawResults),
            'results_count' => $results->count(),
            'took_elasticsearch' => $rawResults['took'] ?? null,
            'shards' => $rawResults['_shards'] ?? null,
            'profile' => $rawResults['profile'] ?? null,
        ];
    }

    /**
     * Применяет search_after к payload
     */
    private function applySearchAfter(array &$payload): void
    {
        if ($this->searchAfter) {
            $payload['body']['search_after'] = $this->searchAfter;
            // Убираем from при использовании search_after
            unset($payload['body']['from']);
        }
    }

    /**
     * Применяет профилирование к payload
     */
    private function applyProfiling(array &$payload): void
    {
        if ($this->enableProfiling) {
            $payload['body']['profile'] = true;
        }
    }

    /**
     * Буферизированное логирование для лучшей производительности
     */
    private function bufferLog(string $message, array $context = []): void
    {
        if (!self::$loggingEnabled) return;
        
        self::$logBuffer[] = [
            'message' => $message,
            'context' => $context,
            'timestamp' => microtime(true)
        ];

        // Сбрасываем буфер при достижении лимита
        if (count(self::$logBuffer) >= 10) {
            self::flushLogBuffer();
        }
    }

    /**
     * Сбрасывает буфер логирования
     */
    private static function flushLogBuffer(): void
    {
        if (empty(self::$logBuffer)) return;

        $channel = \Illuminate\Support\Facades\Log::channel(config('scout_elastic.log_channels')[0]);
        
        foreach (self::$logBuffer as $logEntry) {
            $channel->debug($logEntry['message'], $logEntry['context']);
        }

        self::$logBuffer = [];
    }

    /**
     * Очищает все кэши класса
     * Полезно для освобождения памяти при длительных процессах
     */
    public static function clearAllCaches(): void
    {
        self::$modelInstancesCache = [];
        self::$modelMetaCache = [];
        self::$classExistsCache = [];
        self::flushLogBuffer();
    }

    /**
     * Получает информацию о размере кэшей
     * Полезно для мониторинга потребления памяти
     * 
     * @return array
     */
    public static function getCacheStats(): array
    {
        return [
            'model_instances_count' => count(self::$modelInstancesCache),
            'model_meta_count' => count(self::$modelMetaCache),
            'class_exists_count' => count(self::$classExistsCache),
            'log_buffer_count' => count(self::$logBuffer),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];
    }

    /**
     * Устанавливает лимит для кэшей для предотвращения утечки памяти
     */
    public static function setCacheLimits(int $instanceLimit = 100, int $metaLimit = 500): void
    {
        // Очищаем старые записи если превышен лимит
        if (count(self::$modelInstancesCache) > $instanceLimit) {
            self::$modelInstancesCache = array_slice(self::$modelInstancesCache, -$instanceLimit, null, true);
        }
        
        if (count(self::$modelMetaCache) > $metaLimit) {
            self::$modelMetaCache = array_slice(self::$modelMetaCache, -$metaLimit, null, true);
        }
    }

    /**
     * Создает explain запрос для отладки релевантности
     * 
     * @param string $documentId ID документа для объяснения
     * @param string $index Индекс документа
     * @return array
     */
    public function explainDocument(string $documentId, string $index): array
    {
        $payload = $this->buildSearchQueryPayloadCollection()->first();
        
        if (!$payload) {
            throw new \InvalidArgumentException('Cannot build query payload for explain');
        }

        return ElasticClient::explain([
            'index' => $index,
            'id' => $documentId,
            'body' => [
                'query' => $payload['body']['query'] ?? ['match_all' => []],
            ],
        ]);
    }

    /**
     * Получает предложения (suggestions) от Elasticsearch
     * 
     * @param array $suggestionConfig Конфигурация предложений
     * @return array
     */
    public function suggest(array $suggestionConfig): array
    {
        $payload = [
            'index' => implode(',', array_unique($this->indices)),
            'body' => [
                'suggest' => $suggestionConfig,
            ],
        ];

        $response = ElasticClient::search($payload);
        
        return $response['suggest'] ?? [];
    }

    /**
     * Выполняет count запрос для получения только количества документов
     * Более эффективен чем получение всех результатов
     * 
     * @return int
     */
    public function count(): int
    {
        $payload = $this->buildSearchQueryPayloadCollection()->first();
        
        if (!$payload) {
            return 0;
        }

        // Создаем count запрос на основе существующего payload
        $countPayload = [
            'index' => $payload['index'],
            'body' => [
                'query' => $payload['body']['query'] ?? ['match_all' => []],
            ],
        ];

        $response = ElasticClient::count($countPayload);
        
        return $response['count'] ?? 0;
    }

    /**
     * Возвращает только уникальные значения определенного поля
     * 
     * @param string $field Поле для получения уникальных значений
     * @param int $size Максимальное количество уникальных значений
     * @return array
     */
    public function distinct(string $field, int $size = 1000): array
    {
        $this->aggregate([
            'distinct_values' => [
                'terms' => [
                    'field' => $field,
                    'size' => $size,
                ],
            ],
        ]);

        $aggregations = $this->aggregations();
        
        if (!isset($aggregations['distinct_values']['buckets'])) {
            return [];
        }

        return array_column($aggregations['distinct_values']['buckets'], 'key');
    }
}
