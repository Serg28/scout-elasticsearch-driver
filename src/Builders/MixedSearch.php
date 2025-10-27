<?php

namespace Novius\ScoutElastic\Builders;

use Laravel\Scout\Builder;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Laravel\Scout\EngineManager;
use Novius\ScoutElastic\Facades\ElasticClient;
use Illuminate\Support\Arr;
use Illuminate\Pagination\LengthAwarePaginator;
use Novius\ScoutElastic\SearchRule;

/**
 * Оптимизированный класс для многоиндексного поиска в Elasticsearch
 *
 * Улучшения производительности:
 * - Кэширование экземпляров моделей и метаданных
 * - Оптимизированные операции с массивами
 * - Условное логирование только при необходимости
 * - Упрощенная структура условий поиска
 * - Объединенные запросы для пагинации
 */
class MixedSearch extends Builder
{
    use MixedSearchExtensions;

    /** @var array Список индексов для поиска */
    public $indices = [];

    /** @var array Маппинг индексов к классам моделей */
    protected $models = [];

    /** @var array Поля для выборки по индексам */
    public $select = [];

    /** @var int|null Смещение для пагинации */
    public $offset;

    /** @var int|null Лимит результатов */
    public $limit;

    /** @var array Условия фильтрации (упрощенная структура) */
    public $wheres = [];

    /** @var array Параметры сортировки */
    public $orders = [];

    /** @var array Конфигурация агрегаций */
    protected $aggregations = [];

    /** @var float|null Минимальный score для результатов */
    protected $minScore;

    /** @var int|null Минимальное количество совпадений для should запросов */
    protected $minimumShouldMatch;

    /** @var array Настройки коллапса результатов */
    protected $collapse = [];

    /** @var array|null Значения для search_after пагинации */
    protected $searchAfter;

    /** @var mixed Отношения для загрузки */
    protected $with;

    /** @var bool Возвращать базовую коллекцию */
    protected $toBase = false;

    /** @var array Дополнительные опции запроса */
    public $options = [];

    // Кэши для оптимизации производительности
    /** @var array Кэш экземпляров моделей */
    private static $modelInstancesCache = [];

    /** @var array Кэш метаданных моделей */
    private static $modelMetaCache = [];

    /** @var array Кэш проверок существования классов */
    private static $classExistsCache = [];

    /** @var bool Включено ли логирование */
    private static $loggingEnabled;

    public function __construct()
    {
        parent::__construct(null, null);
        $this->initializeDefaults();

        // Инициализируем настройку логирования один раз
        if (self::$loggingEnabled === null) {
            self::$loggingEnabled = config('scout_elastic.log_enabled', false);
        }
    }

    /**
     * Инициализирует значения по умолчанию
     */
    private function initializeDefaults(): void
    {
        $this->wheres = [
            '_all' => ['must' => [], 'must_not' => [], 'should' => []]
        ];
        $this->collapse = ['_all' => null];
    }

    public static function create(): self
    {
        return new static();
    }

    /**
     * Добавляет индекс или модель для поиска
     *
     * @param string|Model $index Класс модели, экземпляр модели или название индекса
     * @return $this
     */
    public function within($index)
    {
        $indexName = $this->resolveIndexName($index);

        if (!in_array($indexName, $this->indices)) {
            $this->indices[] = $indexName;
            $this->wheres[$indexName] = ['must' => [], 'must_not' => [], 'should' => []];
            $this->collapse[$indexName] = null;
        }

        return $this;
    }

    /**
     * Определяет имя индекса из различных входных данных
     */
    private function resolveIndexName($index): string
    {
        if (is_string($index) && $this->isModelClass($index)) {
            $indexName = $this->getModelInstance($index)->searchableAs();
            $this->models[$indexName] = $index;
            return $indexName;
        }

        if ($index instanceof Model) {
            $indexName = $index->searchableAs();
            $this->models[$indexName] = get_class($index);
            return $indexName;
        }

        // Обычная строка с названием индекса
        $this->models[$index] = null;
        return $index;
    }

    /**
     * Проверяет, является ли строка классом модели (с кэшированием)
     */
    private function isModelClass(string $class): bool
    {
        if (!isset(self::$classExistsCache[$class])) {
            self::$classExistsCache[$class] = class_exists($class) && is_subclass_of($class, Model::class);
        }

        return self::$classExistsCache[$class];
    }

    /**
     * Получает экземпляр модели (с кэшированием)
     */
    private function getModelInstance(string $modelClass): Model
    {
        if (!isset(self::$modelInstancesCache[$modelClass])) {
            self::$modelInstancesCache[$modelClass] = new $modelClass();
        }

        return self::$modelInstancesCache[$modelClass];
    }

    /**
     * Получает метаданные модели (с кэшированием)
     */
    private function getModelMeta(string $modelClass, string $property)
    {
        $cacheKey = $modelClass . '.' . $property;

        if (!isset(self::$modelMetaCache[$cacheKey])) {
            $instance = $this->getModelInstance($modelClass);

            switch ($property) {
                case 'scoutKeyName':
                    self::$modelMetaCache[$cacheKey] = $instance->getScoutKeyName();
                    break;
                case 'searchSettings':
                    self::$modelMetaCache[$cacheKey] = method_exists($instance, 'getSearchSettings')
                        ? ($instance->getSearchSettings() ?? []) : [];
                    break;
                case 'searchRules':
                    self::$modelMetaCache[$cacheKey] = method_exists($instance, 'getSearchRules')
                        ? ($instance->getSearchRules() ?? []) : [];
                    break;
                default:
                    self::$modelMetaCache[$cacheKey] = null;
            }
        }

        return self::$modelMetaCache[$cacheKey];
    }

    public function query($query)
    {
        $this->query = $query;
        return $this;
    }

    /**
     * Настраивает поля для выборки
     *
     * @param array|string $fields Поля для выборки
     * @return $this
     */
    public function select($fields)
    {
        // Инициализируем select для всех индексов
        foreach ($this->indices as $index) {
            $this->select[$index] = ['*' => null];
        }

        if (is_array($fields) && !Arr::isAssoc($fields)) {
            // Простой массив полей для всех индексов
            $fieldsArray = array_fill_keys($fields, null);
            foreach ($this->indices as $index) {
                $this->select[$index] = $fieldsArray;
            }
        } elseif (is_array($fields)) {
            // Ассоциативный массив: индекс => поля
            foreach ($fields as $index => $indexFields) {
                $this->validateIndex($index);

                $this->select[$index] = [];
                foreach ($indexFields as $field => $alias) {
                    if (is_numeric($field)) {
                        $this->select[$index][$alias] = null;
                    } else {
                        $this->select[$index][$field] = $alias;
                    }
                }
            }
        } else {
            // Одно поле для всех индексов
            $fieldsArray = array_fill_keys(Arr::wrap($fields), null);
            foreach ($this->indices as $index) {
                $this->select[$index] = $fieldsArray;
            }
        }

        return $this;
    }

    private function validateIndex(string $index): void
    {
        if (!in_array($index, $this->indices)) {
            throw new \InvalidArgumentException("Index {$index} not specified in within()");
        }
    }

    public function toBase()
    {
        $this->toBase = true;
        return $this;
    }

    public function isToBase(): bool
    {
        return $this->toBase;
    }

    public function from($offset)
    {
        $this->offset = $offset;
        return $this;
    }

    public function take($limit)
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Добавляет сортировку к запросу
     *
     * @param string $field Поле для сортировки
     * @param string $direction Направление сортировки (asc/desc)
     * @return $this
     */
    public function orderBy($field, $direction = 'asc')
    {
        $this->orders[] = [
            $field => strtolower($direction) === 'asc' ? 'asc' : 'desc'
        ];
        return $this;
    }

    /**
     * Оптимизированная пагинация - один запрос вместо двух
     *
     * @param int|null $perPage Количество элементов на странице
     * @param string $pageName Имя параметра страницы
     * @param int|null $page Номер страницы
     * @return LengthAwarePaginator
     */
    public function paginate($perPage = null, $pageName = 'page', $page = null)
    {
        $page = $page ?: \Illuminate\Pagination\Paginator::resolveCurrentPage($pageName);
        $perPage = $perPage ?: 15;

        $this->from(($page - 1) * $perPage)->take($perPage);

        // Одновременно получаем результаты и общее количество
        $rawResults = $this->engine()->rawSearch($this);
        $results = $this->engine()->map($this, $rawResults, null);
        $total = $this->engine()->getTotalCount($rawResults);

        if (self::$loggingEnabled) {
            \Log::channel(config('scout_elastic.log_channels')[0])
                ->debug('Paginate results', [
                    'perPage' => $perPage,
                    'page' => $page,
                    'total' => $total,
                    'results_count' => $results->count(),
                ]);
        }

        $paginator = new LengthAwarePaginator(
            $results,
            $total,
            $perPage,
            $page,
            ['path' => \Illuminate\Pagination\Paginator::resolveCurrentPath(), 'pageName' => $pageName]
        );

        if (isset($this->with) && $paginator->total() > 0) {
            $paginator->getCollection()->load($this->with);
        }

        return $paginator;
    }

    /**
     * Добавляет условие WHERE
     *
     * @param string|\Closure $field Поле или замыкание
     * @param mixed $operator Оператор сравнения
     * @param mixed $value Значение
     * @param string|null $index Конкретный индекс
     * @param string $boolean Тип условия (must, must_not, should)
     * @return $this
     */
    public function where($field, $operator = null, $value = null, $index = null, $boolean = 'must')
    {
        if ($field instanceof \Closure) {
            return $this->whereNested($field, $index, $boolean);
        }

        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        $condition = $this->buildCondition($field, $operator, $value);

        if ($operator === '!=' || $operator === '<>') {
            $this->addNegativeCondition($condition, $boolean, $index);
        } else {
            $this->addCondition($condition, $boolean, $index);
        }

        return $this;
    }

    /**
     * Создает условие для Elasticsearch
     */
    private function buildCondition(string $field, string $operator, $value): array
    {
        switch ($operator) {
            case '=':
                return ['term' => [$field => $value]];
            case '>':
                return ['range' => [$field => ['gt' => $value]]];
            case '<':
                return ['range' => [$field => ['lt' => $value]]];
            case '>=':
                return ['range' => [$field => ['gte' => $value]]];
            case '<=':
                return ['range' => [$field => ['lte' => $value]]];
            default:
                return ['term' => [$field => $value]];
        }
    }

    /**
     * Добавляет условие в соответствующий раздел
     */
    private function addCondition(array $condition, string $boolean, ?string $index): void
    {
        $targetIndex = $index ?? '_all';
        $this->wheres[$targetIndex][$boolean][] = $condition;
    }

    /**
     * Добавляет негативное условие
     */
    private function addNegativeCondition(array $condition, string $boolean, ?string $index): void
    {
        if ($boolean === 'should') {
            $condition = ['bool' => ['must_not' => [$condition]]];
        }

        $targetIndex = $index ?? '_all';

        if ($index && !in_array($index, $this->indices)) {
            throw new \InvalidArgumentException("Index {$index} not specified in within()");
        }

        if ($boolean === 'must') {
            $this->wheres[$targetIndex]['must_not'][] = $condition;
        } else {
            $this->wheres[$targetIndex][$boolean][] = $condition;
        }
    }

    public function orWhere($field, $operator = null, $value = null, $index = null)
    {
        return $this->where($field, $operator, $value, $index, 'should');
    }

    public function whereNested(\Closure $callback, $boolean = 'must')
    {
        $filter = new self();
        call_user_func($callback, $filter);

        $payload = $filter->buildPayload();

        if (self::$loggingEnabled) {
            \Log::channel(config('scout_elastic.log_channels')[0])
                ->debug('FilterBuilder nested payload', ['payload' => $payload]);
        }

        $this->wheres['_all'][$boolean][] = $payload['query']['bool'] ?? [];
        return $this;
    }

    protected function prepareValueAndOperator($value, $operator, $useDefault = false)
    {
        if ($useDefault) {
            return [$operator, '='];
        }
        return [$value, $operator];
    }

    public function whereIn($field, $value, $index = null, $boolean = 'must')
    {
        $condition = ['terms' => [$field => $value]];
        $this->addCondition($condition, $boolean, $index);
        return $this;
    }

    public function orWhereIn($field, array $value, $index = null)
    {
        return $this->whereIn($field, $value, $index, 'should');
    }

    public function whereNotIn($field, $value, $index = null, $boolean = 'must')
    {
        $condition = ['terms' => [$field => $value]];
        $this->addNegativeCondition($condition, $boolean, $index);
        return $this;
    }

    public function orWhereNotIn($field, array $value, $index = null)
    {
        return $this->whereNotIn($field, $value, $index, 'should');
    }

    public function whereBetween($field, array $value, $index = null, $boolean = 'must')
    {
        $condition = ['range' => [$field => ['gte' => $value[0], 'lte' => $value[1]]]];
        $this->addCondition($condition, $boolean, $index);
        return $this;
    }

    public function orWhereBetween($field, array $value, $index = null)
    {
        return $this->whereBetween($field, $value, $index, 'should');
    }

    public function whereNotBetween($field, array $value, $index = null, $boolean = 'must')
    {
        $condition = ['range' => [$field => ['gte' => $value[0], 'lte' => $value[1]]]];
        $this->addNegativeCondition($condition, $boolean, $index);
        return $this;
    }

    public function orWhereNotBetween($field, array $value, $index = null)
    {
        return $this->whereNotBetween($field, $value, $index, 'should');
    }

    public function whereExists($field, $index = null, $boolean = 'must')
    {
        $condition = ['exists' => ['field' => $field]];
        $this->addCondition($condition, $boolean, $index);
        return $this;
    }

    public function orWhereExists($field, $index = null)
    {
        return $this->whereExists($field, $index, 'should');
    }

    public function whereNotExists($field, $index = null, $boolean = 'must')
    {
        $condition = ['exists' => ['field' => $field]];
        $this->addNegativeCondition($condition, $boolean, $index);
        return $this;
    }

    public function orWhereNotExists($field, $index = null)
    {
        return $this->whereNotExists($field, $index, 'should');
    }

    public function whereMatch($field, $value, $index = null, $boolean = 'must')
    {
        $condition = ['match' => [$field => $value]];
        $this->addCondition($condition, $boolean, $index);
        return $this;
    }

    public function orWhereMatch($field, $value, $index = null)
    {
        return $this->whereMatch($field, $value, $index, 'should');
    }

    public function whereNotMatch($field, $value, $index = null, $boolean = 'must')
    {
        $condition = ['match' => [$field => $value]];
        $this->addNegativeCondition($condition, $boolean, $index);
        return $this;
    }

    public function orWhereNotMatch($field, $value, $index = null)
    {
        return $this->whereNotMatch($field, $value, $index, 'should');
    }

    public function whereRegexp($field, $value, $flags = 'ALL', $index = null, $boolean = 'must')
    {
        $condition = ['regexp' => [$field => ['value' => $value, 'flags' => $flags]]];
        $this->addCondition($condition, $boolean, $index);
        return $this;
    }

    public function orWhereRegexp($field, $value, $flags = 'ALL', $index = null)
    {
        return $this->whereRegexp($field, $value, $flags, $index, 'should');
    }

    public function whereGeoDistance($field, $value, $distance, $index = null, $boolean = 'must')
    {
        $condition = ['geo_distance' => ['distance' => $distance, $field => $value]];
        $this->addCondition($condition, $boolean, $index);
        return $this;
    }

    public function orWhereGeoDistance($field, $value, $distance, $index = null)
    {
        return $this->whereGeoDistance($field, $value, $distance, $index, 'should');
    }

    public function whereGeoBoundingBox($field, array $value, $index = null, $boolean = 'must')
    {
        $condition = ['geo_bounding_box' => [$field => $value]];
        $this->addCondition($condition, $boolean, $index);
        return $this;
    }

    public function orWhereGeoBoundingBox($field, array $value, $index = null)
    {
        return $this->whereGeoBoundingBox($field, $value, $index, 'should');
    }

    public function whereGeoPolygon($field, array $points, $index = null, $boolean = 'must')
    {
        $condition = ['geo_polygon' => [$field => ['points' => $points]]];
        $this->addCondition($condition, $boolean, $index);
        return $this;
    }

    public function orWhereGeoPolygon($field, array $points, $index = null)
    {
        return $this->whereGeoPolygon($field, $points, $index, 'should');
    }

    public function whereGeoShape($field, array $shape, $relation = 'INTERSECTS', $index = null, $boolean = 'must')
    {
        $condition = ['geo_shape' => [$field => ['shape' => $shape, 'relation' => $relation]]];
        $this->addCondition($condition, $boolean, $index);
        return $this;
    }

    public function orWhereGeoShape($field, array $shape, $relation = 'INTERSECTS', $index = null)
    {
        return $this->whereGeoShape($field, $shape, $relation, $index, 'should');
    }

    /**
     * Настраивает коллапс результатов по полю
     *
     * @param string $field Поле для коллапса
     * @param string|null $index Конкретный индекс
     * @return $this
     */
    public function collapse(string $field, $index = null)
    {
        $targetIndex = $index ?? '_all';

        if ($index && !in_array($index, $this->indices)) {
            throw new \InvalidArgumentException("Index {$index} not specified in within()");
        }

        $this->collapse[$targetIndex] = $field;
        return $this;
    }

    public function minScore($score)
    {
        $this->minScore = $score;
        return $this;
    }

    public function minimumShouldMatch($value = 0)
    {
        $this->minimumShouldMatch = $value;
        return $this;
    }

    public function with($relations)
    {
        $this->with = $relations;
        return $this;
    }

    /**
     * Включает удаленные записи в результаты
     *
     * @return $this
     */
    public function withTrashed()
    {
        foreach ($this->wheres as &$whereGroup) {
            $whereGroup['must'] = array_filter($whereGroup['must'], function ($condition) {
                return !isset($condition['term']['__soft_deleted']) || $condition['term']['__soft_deleted'] !== 0;
            });
        }
        return $this;
    }

    /**
     * Возвращает только удаленные записи
     *
     * @return $this
     */
    public function onlyTrashed()
    {
        return tap($this->withTrashed(), function () {
            $this->where('__soft_deleted', '=', 1);
        });
    }

    public function engine()
    {
        return app(EngineManager::class)->engine();
    }

    /**
     * Выполняет поиск и возвращает результаты
     *
     * @return Collection
     * @throws \InvalidArgumentException
     */
    public function get(): Collection
    {
        if (empty($this->indices)) {
            throw new \InvalidArgumentException('Необходимо указать хотя бы один индекс или модель.');
        }

        $engine = $this->engine();
        $payloads = $this->buildSearchQueryPayloadCollection();
        $results = new Collection();

        foreach ($payloads as $payload) {
            if (self::$loggingEnabled) {
                \Log::channel(config('scout_elastic.log_channels')[0])
                    ->debug('Elasticsearch multi-index query', $payload);
            }

            /** @var Elasticsearch $response */
            $response = ElasticClient::search($payload);
            $response['_payload'] = $payload;
            $results = $results->merge($engine->map($this, $response, null));
        }

        if (isset($this->with) && $results->count() > 0) {
            $results->load($this->with);
        }

        return $results;
    }

    /**
     * Оптимизированная обработка агрегаций с минимизацией сложных фильтров
     *
     * @param array $aggregations Конфигурация агрегаций
     * @param array $fieldMap Маппинг полей по индексам
     * @return $this
     */
    public function aggregate($aggregations, $fieldMap = [])
    {
        $processedAggregations = [];

        foreach ($aggregations as $name => $agg) {
            if (!isset($fieldMap[$name])) {
                $processedAggregations[$name] = $agg;
                $this->warnAboutFieldTypeMismatch($name, $agg);
                continue;
            }

            // Оптимизированная обработка агрегаций с fieldMap
            $processedAgg = $this->buildOptimizedAggregation($name, $agg, $fieldMap[$name]);
            $processedAggregations[$name] = $processedAgg;
        }

        // Используем прямое присваивание вместо array_merge для лучшей производительности
        foreach ($processedAggregations as $name => $agg) {
            $this->aggregations[$name] = $agg;
        }

        return $this;
    }

    /**
     * Создает оптимизированную агрегацию с минимальными фильтрами
     */
    private function buildOptimizedAggregation(string $name, array $agg, array $fieldsByIndex): array
    {
        $modifiedAgg = [
            'filter' => ['bool' => ['should' => []]],
            'aggs' => [],
        ];

        foreach ($this->indices as $index) {
            $field = $fieldsByIndex[$index] ?? null;
            if (!$field) continue;

            // Клонируем базовую агрегацию и заменяем поле
            $subAgg = $this->replaceAggregationField($agg, $field);

            // Минимальные фильтры только для существующих записей
            $modifiedAgg['filter']['bool']['should'][] = [
                'bool' => [
                    'filter' => [
                        ['term' => ['_index' => $index]],
                        ['exists' => ['field' => $field]],
                    ],
                ],
            ];

            $modifiedAgg['aggs'][$index . '_' . $name] = $subAgg;
        }

        return $modifiedAgg;
    }

    /**
     * Заменяет поле в агрегации (оптимизированная версия)
     */
    private function replaceAggregationField(array $agg, string $field): array
    {
        static $aggTypes = ['terms', 'avg', 'sum', 'min', 'max', 'value_count'];

        foreach ($aggTypes as $aggType) {
            if (isset($agg[$aggType]['field'])) {
                $agg[$aggType]['field'] = $field;
                break;
            }
        }

        return $agg;
    }

    /**
     * Выводит предупреждение о возможном несоответствии типов полей
     */
    private function warnAboutFieldTypeMismatch(string $name, array $agg): void
    {
        static $numericAggTypes = ['avg', 'sum', 'min', 'max'];

        foreach ($numericAggTypes as $aggType) {
            if (isset($agg[$aggType]) && self::$loggingEnabled) {
                \Log::channel(config('scout_elastic.log_channels')[0])
                    ->warning(
                        "Aggregation '$name' of type '$aggType' may fail if field types differ across indices",
                        [
                            'indices' => $this->indices,
                            'field' => $agg[$aggType]['field'] ?? 'unknown',
                        ]
                    );
                break;
            }
        }
    }

    /**
     * Оптимизированная обработка результатов агрегаций
     *
     * @return array|null
     */
    public function aggregations()
    {
        $rawAggregations = $this->engine()->profile($this)['aggregations'] ?? null;
        if (!$rawAggregations) {
            return null;
        }

        $result = [];

        foreach ($rawAggregations as $name => $aggData) {
            $processedAgg = $this->processAggregationResult($name, $aggData);
            if ($processedAgg !== null) {
                $result[$name] = $processedAgg;
            }
        }

        return $result;
    }

    /**
     * Обрабатывает результат одной агрегации
     */
    private function processAggregationResult(string $name, array $aggData): ?array
    {
        $buckets = [];
        $docCount = $aggData['doc_count'] ?? 0;
        $meta = $aggData['meta'] ?? [];
        $hasSubAggregations = false;
        $aggregatedValue = 0;

        // Ищем под-агрегации для каждого индекса
        foreach ($this->indices as $index) {
            $subAggName = $index . '_' . $name;

            if (!isset($aggData[$subAggName])) continue;

            $hasSubAggregations = true;
            $subAggData = $aggData[$subAggName];

            // Обработка terms агрегаций
            if (isset($subAggData['buckets'])) {
                foreach ($subAggData['buckets'] as $bucket) {
                    $key = $bucket['key'];
                    $buckets[$key] = ($buckets[$key] ?? 0) + $bucket['doc_count'];
                }
            }

            // Обработка числовых агрегаций
            elseif (isset($subAggData['value'])) {
                $aggregatedValue += $subAggData['value'];
            }
        }

        // Формируем финальный результат
        if ($hasSubAggregations) {
            if (!empty($buckets)) {
                return [
                    'doc_count' => $docCount,
                    'meta' => $meta,
                    'buckets' => array_map(function ($key, $docCount) {
                        return ['key' => $key, 'doc_count' => $docCount];
                    }, array_keys($buckets), $buckets),
                ];
            } elseif ($aggregatedValue > 0) {
                return [
                    'value' => $aggregatedValue,
                    'doc_count' => $docCount,
                    'meta' => $meta,
                ];
            }
        }

        // Возвращаем исходные данные, если нет под-агрегаций
        return $hasSubAggregations ? null : $aggData;
    }

    public function withOptions(array $options)
    {
        $this->options = $options;
        return $this;
    }

    /**
     * Оптимизированная сборка payload'ов запросов с кэшированием
     *
     * @return Collection
     */
    public function buildSearchQueryPayloadCollection(): Collection
    {
        $payloadCollection = collect();

        // Группируем модели для уменьшения повторных вычислений
        $validModels = $this->getValidModelsWithRules();

        if (empty($validModels)) {
            return $this->buildDefaultPayload();
        }

        foreach ($validModels as $index => $modelData) {
            $payloads = $this->buildModelPayloads($index, $modelData);
            $payloadCollection = $payloadCollection->merge($payloads);
        }

        if ($this->callback) {
            return collect([call_user_func($this->callback, ElasticClient::getFacadeRoot(), $payloadCollection->first())]);
        }

        return $payloadCollection;
    }

    /**
     * Получает модели с валидными searchRules (с кэшированием)
     */
    private function getValidModelsWithRules(): array
    {
        $validModels = [];

        foreach ($this->models as $index => $modelClass) {

            if (!$modelClass || !$this->isModelClass($modelClass)) {
                continue;
            }

            $searchRules = $this->getModelMeta($modelClass, 'searchRules');
            if (empty($searchRules)) continue;

            $validModels[$index] = [
                'class' => $modelClass,
                'instance' => $this->getModelInstance($modelClass),
                'rules' => $searchRules,
                'settings' => $this->getModelMeta($modelClass, 'searchSettings'),
            ];
        }

        return $validModels;
    }

    /**
     * Создает payload'ы для конкретной модели
     */
    private function buildModelPayloads(string $index, array $modelData): Collection
    {
        $payloads = collect();

        foreach ($modelData['rules'] as $rule) {
            // Проверяем применимость правила до создания экземпляра (оптимизация)
            if (is_callable($rule)) {
                $queryPayload = call_user_func($rule, $this);
                if (empty($queryPayload)) continue;
            } else {
                /** @var SearchRule $ruleEntity */
                $ruleEntity = new $rule($this);
                if (!$ruleEntity->isApplicable()) continue;

                $queryPayload = $ruleEntity->buildQueryPayload();
                if (empty($queryPayload)) continue;
            }

            $payload = $this->buildBasePayload($index, $modelData);

            // Добавляем query payload
            $payload['body']['query']['bool'] = $queryPayload;

            // Добавляем highlight если нужно
            if (($this->options['highlight'] ?? true) && !is_callable($rule)) {
                $highlightPayload = $ruleEntity->buildHighlightPayload();
                if (!empty($highlightPayload)) {
                    $payload['body']['highlight'] = $highlightPayload;
                }
            }

            $this->applyCommonSettings($payload, $modelData);
            $payloads->push($payload);
        }

        return $payloads;
    }

    /**
     * Создает базовый payload для индекса
     */
    private function buildBasePayload(string $index, array $modelData): array
    {
        $payload = [
            'index' => $index,
            'body' => ['track_total_hits' => true],
        ];

        // Настройка _source с кэшированием scoutKeyName
        if (!empty($this->select[$index])) {
            $source = $this->buildSourceFields($index, $modelData['instance']);
            $payload['body']['_source'] = !empty($source) ? array_unique($source) : true;
        } else {
            $payload['body']['_source'] = true;
        }

        return $payload;
    }

    /**
     * Создает список полей для _source
     */
    private function buildSourceFields(string $index, Model $modelInstance): array
    {
        $source = [];
        $scoutKeyName = $this->getModelMeta(get_class($modelInstance), 'scoutKeyName');

        // Добавляем обязательные поля
        $fields = $this->select[$index];
        if (!isset($fields[$scoutKeyName])) {
            $fields[$scoutKeyName] = null;
        }
        if (!isset($fields['type'])) {
            $fields['type'] = null;
        }

        return array_keys($fields);
    }

    /**
     * Применяет общие настройки к payload
     */
    private function applyCommonSettings(array &$payload, array $modelData): void
    {
        // Базовые настройки
        if (isset($this->minScore)) {
            $payload['body']['min_score'] = $this->minScore;
        }

        if (isset($this->offset)) {
            $payload['body']['from'] = $this->offset;
        }

        if (isset($this->limit)) {
            $payload['body']['size'] = $this->limit;
        }

        if (!empty($this->orders)) {
            $payload['body']['sort'] = $this->orders;
        }

        if (!empty($this->aggregations)) {
            $payload['body']['aggs'] = $this->aggregations;
        }

        // Search_after пагинация
        if (!empty($this->searchAfter)) {
            $payload['body']['search_after'] = $this->searchAfter;
        }

        // Всегда включаем track_scores для получения _score
        $payload['body']['track_scores'] = true;

        // Опции профилирования
        if (isset($this->options['explain'])) {
            $payload['body']['explain'] = $this->options['explain'];
        }

        if (isset($this->options['profile'])) {
            $payload['body']['profile'] = $this->options['profile'];
        }

        // Применяем фильтры
        $this->applyWhereConditions($payload, $modelData['instance']->searchableAs());

        // Применяем коллапс
        $this->applyCollapseSettings($payload, $modelData['instance']->searchableAs());

        // Применяем настройки модели
        $this->applyModelSettings($payload, $modelData['settings']);
    }

    /**
     * Оптимизированное применение WHERE условий
     */
    private function applyWhereConditions(array &$payload, string $index): void
    {
        $boolQuery = $payload['body']['query']['bool'] ?? [];

        // Глобальные условия
        $globalWheres = $this->wheres['_all'];
        if (!empty($globalWheres['must'])) {
            $boolQuery['filter'] = array_merge($boolQuery['filter'] ?? [], $globalWheres['must']);
        }
        if (!empty($globalWheres['must_not'])) {
            $boolQuery['must_not'] = array_merge($boolQuery['must_not'] ?? [], $globalWheres['must_not']);
        }
        if (!empty($globalWheres['should'])) {
            $boolQuery['should'] = array_merge($boolQuery['should'] ?? [], $globalWheres['should']);
        }

        // Условия для конкретного индекса
        $indexWheres = $this->wheres[$index] ?? ['must' => [], 'must_not' => [], 'should' => []];
        if (!empty($indexWheres['must']) || !empty($indexWheres['must_not']) || !empty($indexWheres['should'])) {
            $indexBool = ['bool' => ['filter' => [['term' => ['_index' => $index]]]]];

            if (!empty($indexWheres['must'])) {
                $indexBool['bool']['filter'] = array_merge($indexBool['bool']['filter'], $indexWheres['must']);
            }
            if (!empty($indexWheres['must_not'])) {
                $indexBool['bool']['must_not'] = $indexWheres['must_not'];
            }
            if (!empty($indexWheres['should'])) {
                $indexBool['bool']['should'] = $indexWheres['should'];
                $indexBool['bool']['minimum_should_match'] = 1;
            }

            $boolQuery['filter'] = array_merge($boolQuery['filter'] ?? [], [$indexBool]);
        }

        // Применяем minimum_should_match
        if (isset($this->minimumShouldMatch) && !empty($boolQuery['should'])) {
            $boolQuery['minimum_should_match'] = $this->minimumShouldMatch;
        }

        if (!empty($boolQuery)) {
            $payload['body']['query']['bool'] = $boolQuery;
        }
    }

    /**
     * Упрощенное применение настроек коллапса
     */
    private function applyCollapseSettings(array &$payload, string $index): void
    {
        $collapseFields = array_filter($this->collapse);

        if (empty($collapseFields)) return;

        // Простой случай - один коллапс для всех
        if (count($collapseFields) === 1 && isset($collapseFields['_all'])) {
            $payload['body']['collapse'] = ['field' => $collapseFields['_all']];
            return;
        }

        // Коллапс для конкретного индекса
        if (isset($collapseFields[$index])) {
            $payload['body']['collapse'] = ['field' => $collapseFields[$index]];
            return;
        }

        // Сложный случай - создаем post_filter
        $collapseQueries = [];
        foreach ($collapseFields as $collapseIndex => $field) {
            if ($collapseIndex === '_all' || $collapseIndex === $index) {
                $collapseQueries[] = [
                    'bool' => [
                        'filter' => [
                            ['exists' => ['field' => $field]],
                        ],
                    ],
                ];
            }
        }

        if (!empty($collapseQueries)) {
            $payload['body']['post_filter'] = [
                'bool' => [
                    'should' => $collapseQueries,
                    'minimum_should_match' => 1,
                ],
            ];
        }
    }

    /**
     * Применяет настройки модели к payload
     */
    private function applyModelSettings(array &$payload, array $settings): void
    {
        if (!is_array($settings)) {
            return;
        }

        foreach ($settings as $setting => $value) {
            if (is_array($value) && isset($payload['body'][$setting]) && is_array($payload['body'][$setting])) {
                $payload['body'][$setting] = array_merge($payload['body'][$setting], $value);
            } else {
                $payload['body'][$setting] = $value;
            }
        }
    }

    /**
     * Создает дефолтный payload когда нет searchRules
     */
    private function buildDefaultPayload(): Collection
    {
        $payload = [
            'index' => implode(',', array_unique($this->indices)),
            'body' => [
                'track_total_hits' => true,
                'query' => ['bool' => []],
            ],
        ];

        // Базовый запрос
        if (!empty($this->query)) {
            $payload['body']['query']['bool']['must'] = [
                ['query_string' => ['query' => $this->query ?: '*']],
            ];
        }

        // Настройка _source
        $payload['body']['_source'] = true;

        if (!empty($this->select)) {
            $source = [];

            foreach ($this->select as $index => $fields) {
                $modelClass = $this->models[$index] ?? null;

                if ($modelClass && $this->isModelClass($modelClass)) {
                    $scoutKeyName = $this->getModelMeta($modelClass, 'scoutKeyName');
                    $fields[$scoutKeyName] = $fields[$scoutKeyName] ?? null;
                    $fields['type'] = $fields['type'] ?? null;
                }

                foreach ($fields as $key => $_) {
                    $source[$key] = true; // ключи автоматически уникальны
                }
            }

            if (!empty($source)) {
                $payload['body']['_source'] = array_keys($source);
            }
        }

        // Применяем стандартные настройки
        $this->applyStandardSettings($payload);
        $this->applyDefaultWhereConditions($payload);
        $this->applyDefaultModelSettings($payload);

        return collect([$payload]);
    }

    /**
     * Применяет стандартные настройки к дефолтному payload
     */
    private function applyStandardSettings(array &$payload): void
    {
        if (isset($this->minScore)) {
            $payload['body']['min_score'] = $this->minScore;
        }

        if (isset($this->offset)) {
            $payload['body']['from'] = $this->offset;
        }

        if (isset($this->limit)) {
            $payload['body']['size'] = $this->limit;
        }

        if (!empty($this->orders)) {
            $payload['body']['sort'] = $this->orders;
        }

        if (!empty($this->aggregations)) {
            $payload['body']['aggs'] = $this->aggregations;
        }

        // Всегда включаем track_scores для получения _score
        $payload['body']['track_scores'] = true;

        if (isset($this->options['explain'])) {
            $payload['body']['explain'] = $this->options['explain'];
        }

        if (isset($this->options['profile'])) {
            $payload['body']['profile'] = $this->options['profile'];
        }
    }

    /**
     * Применяет WHERE условия к дефолтному payload
     */
    private function applyDefaultWhereConditions(array &$payload): void
    {
        $boolQuery = $payload['body']['query']['bool'];

        // Глобальные условия
        $globalWheres = $this->wheres['_all'];
        if (!empty($globalWheres['must'])) {
            $boolQuery['filter'] = array_merge($boolQuery['filter'] ?? [], $globalWheres['must']);
        }
        if (!empty($globalWheres['must_not'])) {
            $boolQuery['must_not'] = array_merge($boolQuery['must_not'] ?? [], $globalWheres['must_not']);
        }
        if (!empty($globalWheres['should'])) {
            $boolQuery['should'] = array_merge($boolQuery['should'] ?? [], $globalWheres['should']);
        }

        // Индекс-специфичные фильтры
        $indexSpecificFilters = [];
        foreach ($this->indices as $index) {
            $indexWheres = $this->wheres[$index] ?? ['must' => [], 'must_not' => [], 'should' => []];
            if (empty($indexWheres['must']) && empty($indexWheres['must_not']) && empty($indexWheres['should'])) {
                continue;
            }

            $indexBool = ['bool' => ['filter' => [['term' => ['_index' => $index]]]]];
            if (!empty($indexWheres['must'])) {
                $indexBool['bool']['filter'] = array_merge($indexBool['bool']['filter'], $indexWheres['must']);
            }
            if (!empty($indexWheres['must_not'])) {
                $indexBool['bool']['must_not'] = $indexWheres['must_not'];
            }
            if (!empty($indexWheres['should'])) {
                $indexBool['bool']['should'] = $indexWheres['should'];
                $indexBool['bool']['minimum_should_match'] = 1;
            }
            $indexSpecificFilters[] = $indexBool;
        }

        if (!empty($indexSpecificFilters)) {
            $boolQuery['should'] = array_merge($boolQuery['should'] ?? [], $indexSpecificFilters);
            $boolQuery['minimum_should_match'] = 1;
        }

        // Коллапс для дефолтного payload
        $this->applyDefaultCollapse($payload);

        if (!empty($boolQuery)) {
            if (isset($this->minimumShouldMatch) && !empty($boolQuery['should'])) {
                $boolQuery['minimum_should_match'] = $this->minimumShouldMatch;
            }
            $payload['body']['query']['bool'] = $boolQuery;
        }
    }

    /**
     * Применяет коллапс к дефолтному payload
     */
    private function applyDefaultCollapse(array &$payload): void
    {
        $collapseFields = array_filter($this->collapse);

        if (empty($collapseFields)) return;

        if (count($collapseFields) === 1 && isset($collapseFields['_all'])) {
            $payload['body']['collapse'] = ['field' => $collapseFields['_all']];
        } elseif (!empty($collapseFields)) {
            $collapseQueries = [];
            foreach ($collapseFields as $index => $field) {
                if ($index === '_all') {
                    $collapseQueries[] = [
                        'bool' => [
                            'filter' => [
                                ['exists' => ['field' => $field]],
                            ],
                        ],
                    ];
                } else {
                    $collapseQueries[] = [
                        'bool' => [
                            'filter' => [
                                ['term' => ['_index' => $index]],
                                ['exists' => ['field' => $field]],
                            ],
                        ],
                    ];
                }
            }
            if (!empty($collapseQueries)) {
                $payload['body']['post_filter'] = [
                    'bool' => [
                        'should' => $collapseQueries,
                        'minimum_should_match' => 1,
                    ],
                ];
            }
        }
    }

    /**
     * Применяет настройки моделей к дефолтному payload
     */
    private function applyDefaultModelSettings(array &$payload): void
    {
        foreach ($this->models as $index => $modelClass) {
            if (!$modelClass || !$this->isModelClass($modelClass)) {
                continue;
            }

            $settings = $this->getModelMeta($modelClass, 'searchSettings');
            if (!is_array($settings)) {
                continue;
            }

            foreach ($settings as $setting => $value) {
                if (is_array($value) && isset($payload['body'][$setting]) && is_array($payload['body'][$setting])) {
                    $payload['body'][$setting] = array_merge($payload['body'][$setting], $value);
                } else {
                    $payload['body'][$setting] = $value;
                }
            }
        }
    }

    /**
     * Создает payload для обратной совместимости
     *
     * @return array
     */
    public function buildPayload()
    {
        $payload = $this->buildSearchQueryPayloadCollection()->first() ?? [
            'body' => [
                'query' => ['bool' => []],
                'track_total_hits' => true,
            ],
        ];

        return $payload['body'] ?? $payload;
    }
}
