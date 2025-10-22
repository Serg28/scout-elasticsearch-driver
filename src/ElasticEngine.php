<?php

namespace Novius\ScoutElastic;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Novius\ScoutElastic\Builders\SearchBuilder;
use Novius\ScoutElastic\Builders\MixedSearch;
use Novius\ScoutElastic\Facades\ElasticClient;
use Novius\ScoutElastic\Indexers\BulkIndexer;
use Novius\ScoutElastic\Indexers\IndexerInterface;
use Novius\ScoutElastic\Payloads\TypePayload;
use stdClass;

class ElasticEngine extends Engine
{
    protected $indexer;

    public function __construct(/*BulkIndexer*/IndexerInterface $indexer)
    {
        $this->indexer = $indexer;
    }

    public function update($models)
    {
        $this->indexer->update($models);
    }

    public function delete($models)
    {
        $this->indexer->delete($models);
    }

    public function buildSearchQueryPayloadCollection(Builder $builder, array $options = [])
    {
        $payloadCollection = collect();

        if ($builder instanceof MixedSearch) {
            $payload = [
                'index' => implode(',', $builder->indices),
                'body' => $builder->buildPayload(),
            ];
            $payloadCollection->push($payload);
        } elseif ($builder instanceof SearchBuilder) {
            $searchRules = $builder->rules ?: $builder->model->getSearchRules();

            foreach ($searchRules as $rule) {
                $payload = new TypePayload($builder->model);

                if (is_callable($rule)) {
                    $payload->setIfNotEmpty('body.query.bool', call_user_func($rule, $builder));
                } else {
                    /** @var SearchRule $ruleEntity */
                    $ruleEntity = new $rule($builder);

                    if ($ruleEntity->isApplicable()) {
                        $payload->setIfNotEmpty('body.query.bool', $ruleEntity->buildQueryPayload());
                        if ($options['highlight'] ?? true) {
                            $payload->setIfNotEmpty('body.highlight', $ruleEntity->buildHighlightPayload());
                        }
                    } else {
                        continue;
                    }
                }

                $payloadCollection->push($payload);
            }
        } else {
            $payload = (new TypePayload($builder->model))
                ->setIfNotEmpty('body.query.bool.must.match_all', new stdClass());

            $payloadCollection->push($payload);
        }

        return $payloadCollection->map(function ($payload) use ($builder, $options) {
            if ($builder instanceof MixedSearch) {
                return $payload;
            }

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

                foreach ($builder->wheres as $clause => $filters) {
                    $clauseKey = 'body.query.bool.filter.bool.' . $clause;
                    $clauseValue = array_merge(
                        $payload->get($clauseKey, []),
                        $filters
                    );
                    $payload->setIfNotEmpty($clauseKey, $clauseValue);
                }

                $settings = $builder->model->getSearchSettings();
                foreach ($settings as $setting => $value) {
                    $payload->setIfNotEmpty('body.' . $setting, $value);
                }

                return $payload->get();
            }

            return $payload;
        });
    }

    protected function performSearch(Builder $builder, array $options = [])
    {
        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                ElasticClient::getFacadeRoot(),
                $builder->query,
                $options
            );
        }

        $results = [
            'hits' => ['hits' => [], 'total' => ['value' => 0]],
            'aggregations' => [],
        ];

        // Кэшируем проверку логирования
        $logEnabled = config('scout_elastic.log_enabled', false);
        $logChannel = $logEnabled ? config('scout_elastic.log_channels')[0] : null;

        $this->buildSearchQueryPayloadCollection($builder, $options)
            ->each(function ($payload) use (&$results, $logEnabled, $logChannel) {
                $index = $payload['index'] ?? null;
                $body = $payload['body'] ?? [];

                if ($logEnabled) {
                    \Illuminate\Support\Facades\Log::channel($logChannel)
                        ->debug('Elasticsearch query', ['index' => $index, 'body' => $body]);
                }

                try {
                    $searchResult = ElasticClient::search([
                        'index' => $index,
                        'body' => $body,
                    ]);

                    // Объединяем хиты
                    $results['hits']['hits'] = array_merge(
                        $results['hits']['hits'],
                        $searchResult['hits']['hits'] ?? []
                    );

                    // Суммируем total
                    $results['hits']['total']['value'] += $searchResult['hits']['total']['value'] ?? 0;

                    // Объединяем агрегации (корректно обрабатываем числовые значения)
                    if (isset($searchResult['aggregations'])) {
                        foreach ($searchResult['aggregations'] as $key => $value) {
                            if (!isset($results['aggregations'][$key])) {
                                $results['aggregations'][$key] = $value;
                            } else {
                                // Рекурсивное объединение для вложенных структур
                                $results['aggregations'][$key] = $this->mergeAggregations(
                                    $results['aggregations'][$key],
                                    $value
                                );
                            }
                        }
                    }

                    $results['_payload'] = $payload;
                } catch (\Exception $e) {
                    if ($logEnabled) {
                        \Illuminate\Support\Facades\Log::channel($logChannel)->error('Elasticsearch search error', [
                            'index' => $index,
                            'body' => $body,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    throw $e;
                }
            });

        return $results;
    }

    /**
     * Корректное объединение агрегаций
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

    public function rawSearch(Builder $builder, array $options = [])
    {
        return $this->performSearch($builder, $options);
    }

    public function search(Builder $builder)
    {
        if ($builder instanceof MixedSearch) {
            return $this->map($builder, $this->performSearch($builder), null);
        }
        return $this->performSearch($builder);
    }

    public function paginate(Builder $builder, $perPage, $page)
    {
        $builder
            ->from(($page - 1) * $perPage)
            ->take($perPage);

        if ($builder instanceof MixedSearch) {
            return $this->map($builder, $this->performSearch($builder), null);
        }
        return $this->performSearch($builder);
    }

    public function explain(Builder $builder)
    {
        return $this->performSearch($builder, ['explain' => true]);
    }

    public function profile(Builder $builder)
    {
        return $this->performSearch($builder, ['profile' => true]);
    }

    public function aggregations(Builder $builder, $aggregations)
    {
        return $this->performSearch($builder, ['aggregations' => $aggregations]);
    }

    public function count(Builder $builder)
    {
        $count = 0;

        $this
            ->buildSearchQueryPayloadCollection($builder, ['highlight' => false])
            ->each(function ($payload) use (&$count) {
                $result = ElasticClient::count($payload);
                $count += $result['count'] ?? 0;
            });

        return $count;
    }

    public function searchRaw(Model $model, $query)
    {
        $payload = (new TypePayload($model))
            ->setIfNotEmpty('body', $query)
            ->get();

        return ElasticClient::search($payload);
    }

    public function mapIds($results)
    {
        return collect($results['hits']['hits'])->map(function ($result) {
            $result['_id'] = $this->getModelIDFromHit($result);
            return $result;
        })->pluck('_id');
    }

    public function map(Builder $builder, $results, $model)
    {
        if ($this->getTotalCount($results) == 0) {
            return Collection::make();
        }

        if ($builder instanceof MixedSearch) {
            $models = $this->hydrateMixedModels($builder, $results);
        } else {
            $models = $this->hydrateModels($builder, $model, $results);
        }

        return Collection::make($results['hits']['hits'])
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

    public function getTotalCount($results)
    {
        return $results['hits']['total']['value'] ?? 0;
    }

    public function flush($model)
    {
        $query = $model::usesSoftDelete() ? $model->withTrashed() : $model->newQuery();
        $query->orderBy($model->getScoutKeyName())->unsearchable();
    }

    protected function getModelIDFromHit($hit)
    {
        return last(explode('_', $hit['_id']));
    }

    protected function getTypeNameFromId($id)
    {
        return \Str::beforeLast($id, '_');
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

    public function createIndex($name, array $options = [])
    {
        // TODO: Implement createIndex() method.
    }

    public function deleteIndex($name)
    {
        $this->indexer->delete($name);
    }

    public function hydrateModels(Builder $builder, $model, $results)
    {
        // Проверяем, что модель не null (может быть при использовании MixedSearch расширений)
        if ($model === null) {
            $model = $builder->model;
            if (!$model) {
                return new Collection();
            }
        }

        if ($model->databaseHydrate === false || $builder->isToBase()) {
            $hits = collect($results['hits']['hits']);
            $className = get_class($model);
            $models = new Collection();
            $indexAttributesPrefix = $model->indexAttributesPrefix;

            $hits->each(function ($item) use ($className, $indexAttributesPrefix, $models) {
                $attributes = Arr::get($item['_source'], $indexAttributesPrefix);
                $item['_id'] = $this->getModelIDFromHit($item);
                $models->put($item['_id'], new $className($attributes));
            });
        } else {
            $scoutKeyName = $model->getScoutKeyName();
            $columns = Arr::get($results, '_payload.body._source', ['*']);
            if ($columns !== true && !in_array($scoutKeyName, $columns)) {
                $columns[] = $scoutKeyName;
            }

            $ids = $this->mapIds($results)->all();
            $query = $model::usesSoftDelete() ? $model->withTrashed() : $model->newQuery();

            $models = $query
                ->whereIn($scoutKeyName, $ids)
                ->get($columns)
                ->keyBy($scoutKeyName);
        }

        return $models;
    }

    protected function hydrateMixedModels(Builder $builder, $results)
    {
        $hits = collect($results['hits']['hits']);
        $models = new Collection();

        // Кэшируем проверку логирования
        $logEnabled = config('scout_elastic.log_enabled', false);
        $logChannel = $logEnabled ? config('scout_elastic.log_channels')[0] : null;

        // Группируем хиты по типу модели
        $hitsByType = $hits->groupBy(function ($item) {
            return $this->getTypeNameFromId($item['_id']) ?? $item['_index'];
        });

        // Обрабатываем каждый тип модели отдельно
        foreach ($hitsByType as $type => $typeHits) {
            $modelClass = config("scout_elastic.type_mapping.{$type}");
            if ($modelClass === null || !class_exists($modelClass)) {
                if ($logEnabled) {
                    \Illuminate\Support\Facades\Log::channel($logChannel)
                        ->warning("Model class not found for type: {$type}");
                }
                continue;
            }

            /** @var Model $instance */
            $instance = new $modelClass();
            $scoutKeyName = $instance->getScoutKeyName();
            $indexAttributesPrefix = $instance->indexAttributesPrefix;

            // Получаем выбранные поля для этого индекса из builder->select
            $selectedFields = $builder instanceof MixedSearch && isset($builder->select[$type])
                ? $builder->select[$type]
                : ['*' => null];

            // Если select пуст или ['*'], включаем все поля
            $isSelectAll = empty($selectedFields) || (is_array($selectedFields) && array_keys($selectedFields) === ['*']);

            // Проверяем, включен ли toBase или databaseHydrate = false
            $useSource = ($builder instanceof MixedSearch && $builder->isToBase()) ||
                (property_exists($instance, 'databaseHydrate') && !$instance->databaseHydrate);

            if ($useSource) {
                // Используем _source напрямую
                $typeHits->each(function ($item) use ($models, $modelClass, $selectedFields, $isSelectAll, $scoutKeyName, $indexAttributesPrefix) {
                    $source = $item['_source'] ?? [];
                    $attributes = Arr::get($source, $indexAttributesPrefix, $source);
                    $mappedAttributes = [];

                    if ($isSelectAll) {
                        // Если select пуст или ['*'], возвращаем все поля из _source
                        $mappedAttributes = $attributes;
                    } elseif ($selectedFields) {
                        // Применяем только выбранные поля с алиасами
                        foreach ($selectedFields as $field => $alias) {
                            $targetAlias = $alias ?: $field;
                            $targetField = $field ?: $alias;

                            if (isset($attributes[$targetField])) {
                                $mappedAttributes[$targetAlias] = $attributes[$targetField];
                            }
                        }
                    }

                    // Убедимся, что scoutKeyName включён
                    if (!isset($mappedAttributes[$scoutKeyName]) && isset($source[$scoutKeyName])) {
                        $mappedAttributes[$scoutKeyName] = $source[$scoutKeyName];
                    }

                    $models->put($item['_id'], new $modelClass($mappedAttributes));
                });
            } else {
                // Разделяем поля на те, что есть в модели (для databaseHydrate = true)
                $modelColumns = [];
                $tableColumns = $instance->getConnection()->getSchemaBuilder()->getColumnListing($instance->getTable());

                if ($isSelectAll) {
                    $modelColumns = ['*'];
                } elseif ($selectedFields) {
                    foreach ($selectedFields as $field => $alias) {
                        $targetField = is_numeric($field) ? $alias : $field;
                        $targetAlias = is_numeric($field) ? $targetField : $alias;
                        if (in_array($targetField, $tableColumns)) {
                            $modelColumns[$targetField] = $targetAlias;
                        }
                    }
                }

                // Добавляем scoutKeyName, если его нет
                if (!in_array($scoutKeyName, array_keys($modelColumns)) && $modelColumns !== ['*']) {
                    $modelColumns[$scoutKeyName] = $scoutKeyName;
                }

                // Собираем все значения scoutKeyName
                $scoutKeyValues = $typeHits->pluck('_source.' . $scoutKeyName)->filter()->values()->all();

                if (empty($scoutKeyValues)) {
                    if ($logEnabled) {
                        \Illuminate\Support\Facades\Log::channel($logChannel)
                            ->warning("No valid scoutKey values for model", [
                                'model' => $modelClass,
                                'scoutKeyName' => $scoutKeyName,
                            ]);
                    }
                    continue;
                }

                // Для databaseHydrate = true загружаем все записи одним запросом
                $queryColumns = $modelColumns === ['*'] ? ['*'] : array_keys($modelColumns);
                $query = $instance->newQuery();
                if ($modelClass::usesSoftDelete()) {
                    $query = $query->withTrashed();
                }

                // Выполняем один запрос с WHERE IN
                $foundModels = $query->select($queryColumns)
                    ->whereIn($scoutKeyName, $scoutKeyValues)
                    ->get()
                    ->keyBy($scoutKeyName);

                // Отладка: логируем запрос и найденные модели
                if ($logEnabled) {
                    \Illuminate\Support\Facades\Log::channel($logChannel)
                        ->debug('Hydrating models', [
                            'model' => $modelClass,
                            'scoutKeyName' => $scoutKeyName,
                            'scoutKeyValues' => $scoutKeyValues,
                            'queryColumns' => $queryColumns,
                            'foundModels' => $foundModels->pluck($scoutKeyName)->all(),
                        ]);
                }

                // Обрабатываем каждый хит
                $typeHits->each(function ($item) use ($models, $modelClass, $foundModels, $scoutKeyName, $selectedFields, $isSelectAll, $logEnabled, $logChannel) {
                    $source = $item['_source'] ?? [];
                    $scoutKeyValue = $source[$scoutKeyName] ?? null;

                    $model = $scoutKeyValue && $foundModels->has($scoutKeyValue)
                        ? $foundModels[$scoutKeyValue]
                        : new $modelClass();

                    if (!$model->exists && $logEnabled) {
                        \Illuminate\Support\Facades\Log::channel($logChannel)
                            ->warning("Model not found in database", [
                                'model' => $modelClass,
                                'scoutKeyName' => $scoutKeyName,
                                'scoutKeyValue' => $scoutKeyValue,
                            ]);
                    }

                    $attributes = $model->getAttributes();

                    // Отладка: логируем атрибуты
                    if ($logEnabled) {
                        \Illuminate\Support\Facades\Log::channel($logChannel)
                            ->debug('Model attributes', [
                                'model' => $modelClass,
                                'attributes' => $attributes,
                            ]);
                    }

                    // Применяем алиасы к атрибутам модели
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
        }

        return $models;
    }
}
