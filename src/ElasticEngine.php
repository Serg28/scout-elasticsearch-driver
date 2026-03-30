<?php
// упрощенный
namespace Novius\ScoutElastic;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Novius\ScoutElastic\Builders\SearchBuilder;
use Novius\ScoutElastic\Builders\MixedSearch;
use Novius\ScoutElastic\Facades\ElasticClient;
use Novius\ScoutElastic\Indexers\IndexerInterface;
use Novius\ScoutElastic\Payloads\TypePayload;
use stdClass;
use Illuminate\Support\Facades\Log;

/**
 * Класс-движок для интеграции Laravel Scout с Elasticsearch.
 */
class ElasticEngine extends Engine
{
    /**
     * @var IndexerInterface
     */
    protected $indexer;

    /**
     * ElasticEngine constructor.
     *
     * @param IndexerInterface $indexer Индексер (BulkIndexer или любой, реализующий интерфейс)
     */
    public function __construct(IndexerInterface $indexer)
    {
        $this->indexer = $indexer;
    }

    /**
     * Обновление/индексация моделей.
     *
     * @param mixed $models
     * @return void
     */
    public function update($models)
    {
        $this->indexer->update($models);
    }

    /**
     * Удаление моделей из индекса.
     *
     * @param mixed $models
     * @return void
     */
    public function delete($models)
    {
        $this->indexer->delete($models);
    }

    /**
     * Построение коллекции payload'ов для поиска.
     *
     * Возвращает коллекцию массивов payload или payload'ов как есть (для MixedSearch).
     *
     * @param Builder $builder
     * @param array $options
     * @return \Illuminate\Support\Collection
     */
    public function buildSearchQueryPayloadCollection(Builder $builder, array $options = [])
    {
        $payloads = collect();

        if ($builder instanceof MixedSearch) {
            $payloads->push([
                'index' => implode(',', $builder->indices),
                'body'  => $builder->buildPayload(),
            ]);

            return $payloads;
        }

        if ($builder instanceof SearchBuilder) {
            $searchRules = $builder->rules ?: $builder->model->getSearchRules();
            foreach ($searchRules as $rule) {
                $payload = new TypePayload($builder->model);

                if (is_callable($rule)) {
                    $payload->setIfNotEmpty('body.query.bool', call_user_func($rule, $builder));
                } else {
                    $ruleEntity = new $rule($builder);
                    if (! $ruleEntity->isApplicable()) {
                        continue;
                    }
                    $payload->setIfNotEmpty('body.query.bool', $ruleEntity->buildQueryPayload());
                    if ($options['highlight'] ?? true) {
                        $payload->setIfNotEmpty('body.highlight', $ruleEntity->buildHighlightPayload());
                    }
                }

                $payloads->push($payload);
            }
        } else {
            // fallback — match_all
            $payloads->push(
                (new TypePayload($builder->model))
                    ->setIfNotEmpty('body.query.bool.must.match_all', new stdClass())
            );
        }

        return $payloads->map(function ($payload) use ($builder, $options) {
            if ($payload instanceof TypePayload) {
                $payload
                    ->setIfNotEmpty('body._source', $builder->select)
                    ->setIfNotEmpty('body.collapse.field', $builder->collapse)
                    ->setIfNotEmpty('body.sort', $builder->orders)
                    ->setIfNotEmpty('body.aggregations', $builder->aggregations)
                    ->setIfNotEmpty('body.explain', $options['explain'] ?? null)
                    ->setIfNotEmpty('body.profile', $options['profile'] ?? null)
                    ->setIfNotNull('body.from', $builder->offset)
                    ->setIfNotNull('body.size', $builder->limit)
                    ->setIfNotEmpty('body.query.bool.filter.bool.minimum_should_match', $builder->minimumShouldMatch);

                // where-clauses
                foreach ($builder->wheres as $clause => $filters) {
                    $clauseKey = 'body.query.bool.filter.bool.' . $clause;
                    $clauseValue = array_merge($payload->get($clauseKey, []), $filters);
                    $payload->setIfNotEmpty($clauseKey, $clauseValue);
                }

                // model search settings
                $settings = $builder->model->getSearchSettings();
                foreach ($settings as $setting => $value) {
                    $payload->setIfNotEmpty("body.{$setting}", $value);
                }

                return $payload->get();
            }

            return $payload;
        });
    }

    /**
     * Выполнение поиска — объединяет результаты нескольких payload'ов.
     *
     * @param Builder $builder
     * @param array $options
     * @return array
     * @throws \Exception
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        if ($builder->callback) {
            return call_user_func($builder->callback, ElasticClient::getFacadeRoot(), $builder->query, $options);
        }

        $results = [
            'hits' => ['hits' => [], 'total' => ['value' => 0]],
            'aggregations' => [],
        ];

        $logEnabled = config('scout_elastic.log_enabled', false);
        $logChannel = $logEnabled ? config('scout_elastic.log_channels')[0] : null;

        $this->buildSearchQueryPayloadCollection($builder, $options)
            ->each(function ($payload) use (&$results, $logEnabled, $logChannel) {
                $index = $payload['index'] ?? null;
                $body = $payload['body'] ?? [];

                if ($logEnabled) {
                    Log::channel($logChannel)->debug('Elasticsearch query', ['index' => $index, 'body' => $body]);
                }

                try {
                    $searchResult = ElasticClient::search([
                        'index' => $index,
                        'body'  => $body,
                    ]);

                    // объединяем hits
                    $results['hits']['hits'] = array_merge($results['hits']['hits'], $searchResult['hits']['hits'] ?? []);

                    // суммируем total
                    //$results['hits']['total']['value'] += $searchResult['hits']['total']['value'] ?? 0;
                    $results['hits']['total']['value'] +=  $this->getTotalCount($searchResult);

                    // объединяем агрегации (рекурсивно)
                    if (isset($searchResult['aggregations'])) {
                        foreach ($searchResult['aggregations'] as $key => $value) {
                            if (!isset($results['aggregations'][$key])) {
                                $results['aggregations'][$key] = $value;
                            } else {
                                $results['aggregations'][$key] = $this->mergeAggregations($results['aggregations'][$key], $value);
                            }
                        }
                    }

                    $results['_payload'] = $payload;
                } catch (\Exception $e) {
                    if ($logEnabled) {
                        Log::channel($logChannel)->error('Elasticsearch search error', [
                            'index' => $index,
                            'body'  => $body,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    throw $e;
                }
            });

        return $results;
    }

    /**
     * Рекурсивное корректное объединение агрегаций.
     *
     * @param mixed $existing
     * @param mixed $new
     * @return mixed
     */
    protected function mergeAggregations($existing, $new)
    {
        if (is_numeric($existing) && is_numeric($new)) {
            return $existing + $new;
        }

        if (is_array($existing) && is_array($new)) {
            foreach ($new as $key => $value) {
                if (isset($existing[$key])) {
                    $existing[$key] = $this->mergeAggregations($existing[$key], $value);
                } else {
                    $existing[$key] = $value;
                }
            }
            return $existing;
        }

        return $new;
    }

    /**
     * Выполняет сырой поиск и возвращает результат.
     *
     * @param Builder $builder
     * @param array $options
     * @return array
     */
    public function rawSearch(Builder $builder, array $options = [])
    {
        return $this->performSearch($builder, $options);
    }

    /**
     * Search wrapper — возвращает результаты, готовые к map'пингу.
     *
     * @param Builder $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        if ($builder instanceof MixedSearch) {
            return $this->map($builder, $this->performSearch($builder), null);
        }

        return $this->performSearch($builder);
    }

    /**
     * Пагинация — устанавливает from/size и выполняет поиск.
     *
     * @param Builder $builder
     * @param int $perPage
     * @param int $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $builder->from(($page - 1) * $perPage)->take($perPage);

        if ($builder instanceof MixedSearch) {
            return $this->map($builder, $this->performSearch($builder), null);
        }

        return $this->performSearch($builder);
    }

    /**
     * Explain запрос.
     *
     * @param Builder $builder
     * @return array
     */
    public function explain(Builder $builder)
    {
        return $this->performSearch($builder, ['explain' => true]);
    }

    /**
     * Profile запрос.
     *
     * @param Builder $builder
     * @return array
     */
    public function profile(Builder $builder)
    {
        return $this->performSearch($builder, ['profile' => true]);
    }

    /**
     * Выполняет count для набора payload'ов.
     *
     * @param Builder $builder
     * @return int
     */
    public function count(Builder $builder)
    {
        $count = 0;

        $this->buildSearchQueryPayloadCollection($builder, ['highlight' => false])
            ->each(function ($payload) use (&$count) {
                $result = ElasticClient::count($payload);
                $count += $result['count'] ?? 0;
            });

        return $count;
    }

    /**
     * Поиск по произвольному телу для модели.
     *
     * @param Model $model
     * @param array $query
     * @return array
     */
    public function searchRaw(Model $model, $query)
    {
        $payload = (new TypePayload($model))->setIfNotEmpty('body', $query)->get();

        return ElasticClient::search($payload);
    }

    /**
     * Возвращает коллекцию id из результатов.
     *
     * @param array $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results['hits']['hits'])
            ->map(function ($result) {
                $result['_id'] = $this->getModelIDFromHit($result);
                return $result;
            })
            ->pluck('_id');
    }

    /**
     * Маппинг результатов в коллекцию моделей.
     *
     * @param Builder $builder
     * @param array $results
     * @param Model|null $model
     * @return Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if ($this->getTotalCount($results) == 0) {
            return Collection::make();
        }

        $models = $builder instanceof MixedSearch
            ? $this->hydrateMixedModels($builder, $results)
            : $this->hydrateModels($builder, $model, $results);

        $mappedModels = Collection::make($results['hits']['hits'])
            ->map(function ($hit) use ($models, $builder) {
                $id = $builder instanceof MixedSearch ? $hit['_id'] : $this->getModelIDFromHit($hit);

                if (isset($models[$id])) {
                    $model = $models[$id];
                    $model->_score = $hit['_score'] ?? null;

                    if (isset($hit['highlight'])) {
                        $model->highlight = new Highlight($hit['highlight']);
                    }

                    return $model;
                }
            })
            ->filter()
            ->values();

        return new Collection($mappedModels->all());
    }

    public function lazyMap(Builder $builder, $results, $model)
    {
        if ($this->getTotalCount($results) == 0) {
            return LazyCollection::make();
        }

        if ($builder instanceof MixedSearch) {
            $models = $this->hydrateMixedModels($builder, $results);
        } else {
            $models = $this->hydrateModels($builder, $model, $results);
        }

        return LazyCollection::make($results['hits']['hits'])
            ->map(function ($hit) use ($models, $builder) {
                $id = $builder instanceof MixedSearch
                    ? $hit['_id']
                    : $this->getModelIDFromHit($hit);

                if (isset($models[$id])) {
                    $model = $models[$id];
                    $model->_score = $hit['_score'];

                    if (isset($hit['highlight'])) {
                        $model->highlight = new Highlight($hit['highlight']);
                    }
                    return $model;
                }
            })
            ->filter()
            ->values();
    }

    /**
     * Общее число хитов в результатах.
     *
     * @param array $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['hits']['total']['value'] ?? $results['_shards']['total'] ?? 0;
    }

    /**
     * Удаление индекса.
     *
     * @param string $name
     * @return void
     */
    public function deleteIndex($name)
    {
        $this->indexer->delete($name);
    }

    /**
     * Создание индекса — пока заглушка (необходимо реализовать по требованию).
     *
     * @param string $name
     * @param array $options
     * @return void
     */
    public function createIndex($name, array $options = [])
    {
        // TODO: Implement createIndex() method.
    }

    /**
     * Flush — сброс из индекса (unsearchable).
     *
     * @param Model $model
     * @return void
     */
    public function flush($model)
    {
        $query = $model::usesSoftDelete() ? $model->withTrashed() : $model->newQuery();
        $query->orderBy($model->getScoutKeyName())->unsearchable();
    }

    /**
     * Получить id модели из _id хита (формат: {type}_{id}).
     *
     * @param array $hit
     * @return string|null
     */
    protected function getModelIDFromHit($hit)
    {
        if (empty($hit['_id'])) {
            return null;
        }
        $parts = explode('_', (string) $hit['_id']);
        return (string) end($parts);
    }

    /**
     * Получить имя типа из id вида {type}_{id}.
     *
     * @param string $id
     * @return string
     */
    protected function getTypeNameFromId($id)
    {
        return Str::beforeLast($id, '_');
    }

    /**
     * Гидратация моделей при обычном (не-mixed) поиске.
     *
     * Поддерживаются варианты:
     * - databaseHydrate = false или toBase() — создаём модели из _source;
     * - иначе — загружаем из БД одним запросом по scoutKeyName.
     *
     * @param Builder $builder
     * @param Model|null $model
     * @param array $results
     * @return Collection
     */
    public function hydrateModels(Builder $builder, $model, $results)
    {
        // если model не задан (возможен при расширениях) — берём из builder
        if ($model === null) {
            $model = $builder->model;
            if (!$model) {
                return new Collection();
            }
        }

        // when using source-only hydration
        if ((property_exists($model, 'databaseHydrate') && $model->databaseHydrate === false) || $builder->isToBase()) {
            $hits = collect($results['hits']['hits']);
            $className = get_class($model);
            $models = new Collection();
            $indexAttributesPrefix = $model->indexAttributesPrefix ?? null;

            $hits->each(function ($item) use ($className, $indexAttributesPrefix, $models) {
                $attributes = Arr::get($item['_source'], $indexAttributesPrefix, $item['_source'] ?? []);
                $item['_id'] = $this->getModelIDFromHit($item);
                $models->put($item['_id'], new $className($attributes));
            });

            return $models;
        }

        // database hydrate: получаем колонки и загружаем из БД одним запросом
        $scoutKeyName = $model->getScoutKeyName();
        $columns = Arr::get($results, '_payload.body._source', ['*']);

        if ($columns !== true && ! in_array($scoutKeyName, (array) $columns, true)) {
            $columns[] = $scoutKeyName;
        }

        $ids = $this->mapIds($results)->all();
        $query = $model::usesSoftDelete() ? $model->withTrashed() : $model->newQuery();

        $models = $query->whereIn($scoutKeyName, $ids)->get($columns)->keyBy($scoutKeyName);

        return $models;
    }

    /**
     * Гидратация при MixedSearch — поддерживает:
     * - source-based (toBase/databaseHydrate=false);
     * - database-based (загрузка по типу модели, один запрос на тип).
     *
     * @param MixedSearch $builder
     * @param array $results
     * @return Collection
     */
    protected function hydrateMixedModels(Builder $builder, $results)
    {
        $hits = collect($results['hits']['hits']);
        $models = new Collection();

        $logEnabled = config('scout_elastic.log_enabled', false);
        $logChannel = $logEnabled ? config('scout_elastic.log_channels')[0] : null;

        // Группируем по типу: либо из _id (type_id), либо по _index
        $hitsByType = $hits->groupBy(function ($item) {
            return $this->getTypeNameFromId($item['_id']) ?? $item['_index'];
        });

        foreach ($hitsByType as $type => $typeHits) {
            $modelClass = config("scout_elastic.type_mapping.{$type}");
            if ($modelClass === null || ! class_exists($modelClass)) {
                if ($logEnabled) {
                    Log::channel($logChannel)->warning("Model class not found for type: {$type}");
                }
                continue;
            }

            /** @var Model $instance */
            $instance = new $modelClass();
            $scoutKeyName = $instance->getScoutKeyName();
            $indexAttributesPrefix = $instance->indexAttributesPrefix ?? null;

            $selectedFields = $builder instanceof MixedSearch && isset($builder->select[$type])
                ? $builder->select[$type]
                : ['*' => null];

            $isSelectAll = empty($selectedFields) || (is_array($selectedFields) && array_keys($selectedFields) === ['*']);

            $useSource = ($builder instanceof MixedSearch && $builder->isToBase()) ||
                (property_exists($instance, 'databaseHydrate') && $instance->databaseHydrate === false);

            if ($useSource) {
                // строим модели из _source
                $typeHits->each(function ($item) use ($models, $modelClass, $selectedFields, $isSelectAll, $scoutKeyName, $indexAttributesPrefix) {
                    $source = $item['_source'] ?? [];
                    $attributes = Arr::get($source, $indexAttributesPrefix, $source);
                    $mapped = [];

                    if ($isSelectAll) {
                        $mapped = $attributes;
                    } elseif ($selectedFields) {
                        foreach ($selectedFields as $field => $alias) {
                            $targetAlias = $alias ?: (is_numeric($field) ? $alias : $field);
                            $targetField = is_numeric($field) ? $alias : $field;
                            if (isset($attributes[$targetField])) {
                                $mapped[$targetAlias] = $attributes[$targetField];
                            }
                        }
                    }

                    if (! isset($mapped[$scoutKeyName]) && isset($source[$scoutKeyName])) {
                        $mapped[$scoutKeyName] = $source[$scoutKeyName];
                    }

                    $models->put($item['_id'], new $modelClass($mapped));
                });

                continue;
            }

            // databaseHydrate = true: собираем scoutKeyValues и делаем один запрос
            $scoutKeyValues = $typeHits->pluck('_source.' . $scoutKeyName)->filter()->values()->all();

            if (empty($scoutKeyValues)) {
                if ($logEnabled) {
                    Log::channel($logChannel)->warning("No valid scoutKey values for model", [
                        'model' => $modelClass,
                        'scoutKeyName' => $scoutKeyName,
                    ]);
                }
                continue;
            }

            // Определяем колонки для запроса
            $isSelectStar = $isSelectAll;
            $modelColumns = $isSelectStar ? ['*'] : [];

            if (! $isSelectStar && $selectedFields) {
                // selectedFields может быть в формате [field => alias] или [0 => field]
                $tableColumns = $instance->getConnection()->getSchemaBuilder()->getColumnListing($instance->getTable());
                foreach ($selectedFields as $field => $alias) {
                    $targetField = is_numeric($field) ? $alias : $field;
                    if (in_array($targetField, $tableColumns, true)) {
                        $modelColumns[$targetField] = is_numeric($field) ? $targetField : $alias;
                    }
                }
            }

            // добавляем scoutKeyName если нужно
            if (! $isSelectStar && ! in_array($scoutKeyName, array_keys((array) $modelColumns), true)) {
                $modelColumns[$scoutKeyName] = $scoutKeyName;
            }

            $queryColumns = $modelColumns === ['*'] ? ['*'] : array_keys($modelColumns);
            $query = $instance->newQuery();
            if ($modelClass::usesSoftDelete()) {
                $query = $query->withTrashed();
            }

            $foundModels = $query->select($queryColumns)
                ->whereIn($scoutKeyName, $scoutKeyValues)
                ->get()
                ->keyBy($scoutKeyName);

            if ($logEnabled) {
                Log::channel($logChannel)->debug('Hydrating models', [
                    'model' => $modelClass,
                    'scoutKeyName' => $scoutKeyName,
                    'scoutKeyValues' => $scoutKeyValues,
                    'queryColumns' => $queryColumns,
                    'foundModels' => $foundModels->pluck($scoutKeyName)->all(),
                ]);
            }

            // Для каждого хита либо используем найденную модель, либо создаём "пустую" модель и логируем
            $typeHits->each(function ($item) use ($models, $modelClass, $foundModels, $scoutKeyName, $selectedFields, $isSelectAll, $logEnabled) {
                $source = $item['_source'] ?? [];
                $scoutKeyValue = $source[$scoutKeyName] ?? null;

                $model = $scoutKeyValue && $foundModels->has($scoutKeyValue)
                    ? $foundModels[$scoutKeyValue]
                    : new $modelClass();

                if (! $model->exists && $logEnabled) {
                    Log::channel(config('scout_elastic.log_channels')[0] ?? null)
                        ->warning("Model not found in database", [
                            'model' => $modelClass,
                            'scoutKeyName' => $scoutKeyName,
                            'scoutKeyValue' => $scoutKeyValue,
                        ]);
                }

                $attributes = $model->getAttributes();

                if ($selectedFields && !$isSelectAll) {
                    $mappedAttributes = [];
                    foreach ($attributes as $key => $value) {
                        $alias = null;
                        foreach ($selectedFields as $field => $fieldAlias) {
                            $targetField = is_numeric($field) ? $fieldAlias : $field;
                            if ($targetField === $key) {
                                $alias = is_numeric($field) ? $targetField : $fieldAlias;
                                break;
                            }
                        }
                        $mappedAttributes[$alias ?? $key] = $value;
                    }
                    $model->setRawAttributes($mappedAttributes);
                } else {
                    $model->setRawAttributes($attributes);
                }

                $models->put($item['_id'], $model);
            });
        }

        return $models;
    }

    /**
     * Получение информации об индексе Elasticsearch: настройки, маппинги, алиасы.
     *
     * @param string $indexName
     * @return array
     * @throws \Exception
     */
    public function getIndexInfo(string $indexName): array
    {
        $logEnabled = config('scout_elastic.log_enabled', false);
        $logChannel = $logEnabled ? config('scout_elastic.log_channels')[0] : null;

        try {
            $client = ElasticClient::getFacadeRoot();

            // Получаем индекс или алиас
            $response = $client->indices()->get(['index' => $indexName]);

            // Если это алиас, берём реальное имя индекса
            if (!isset($response[$indexName])) {
                $aliasInfo = $client->indices()->getAlias(['name' => $indexName]);
                $indexName = array_key_first($aliasInfo);
                $response = $client->indices()->get(['index' => $indexName]);
            }

            $indexData = $response[$indexName] ?? [];
            $settings = $indexData['settings']['index'] ?? [];
            $mappings = $indexData['mappings']['properties'] ?? [];
            $aliases  = $indexData['aliases'] ?? [];

            // Получаем статистику по документам
            $stats = $client->indices()->stats(['index' => $indexName]);
            $primaries = $stats['indices'][$indexName]['primaries'] ?? [];
            $docs = $primaries['docs'] ?? [];

            if ($logEnabled) {
                Log::channel($logChannel)->debug('Elasticsearch index info', [
                    'index' => $indexName,
                    'settings' => $settings,
                ]);
            }

            return [
                'index_name'   => $indexName,
                'settings'     => $settings,
                'mappings'     => $mappings,
                'aliases'      => $aliases,
                'docs_count'   => $docs['count'] ?? 0,
                'docs_deleted' => $docs['deleted'] ?? 0,
            ];
        } catch (\Exception $e) {
            if ($logEnabled) {
                Log::channel($logChannel)->error('Elasticsearch index info error', [
                    'index' => $indexName,
                    'error' => $e->getMessage(),
                ]);
            }
            throw $e;
        }
    }
}
