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

    public function __construct(BulkIndexer $indexer)
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

        $results = [];

        $this
            ->buildSearchQueryPayloadCollection($builder, $options)
            ->each(function ($payload) use (&$results) {
                if (config('scout_elastic.log_enabled', false)) {
                    \Illuminate\Support\Facades\Log::channel(config('scout_elastic.log_channels')[0])
                        ->debug('Elasticsearch query', $payload);
                }

                $results = ElasticClient::search($payload);
                $results['_payload'] = $payload;

                if ($this->getTotalCount($results) > 0) {
                    return false;
                }
            });

        return $results;
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
                $count = $result['count'] ?? 0;

                if ($count > 0) {
                    return false;
                }
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
            $models = $this->hydrateModels($model, $results);
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
            $models = $this->hydrateModels($model, $results);
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

    public function hydrateModels($model, $results)
    {
        if ($model->databaseHydrate === false) {
            $hits = collect($results['hits']['hits']);
            $className = get_class($model);
            $models = new Collection();

            $hits->each(function ($item, $key) use ($className, $model, $models) {
                $attributes = Arr::get($item['_source'], $model->indexAttributesPrefix);
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

        $hits->each(function ($item) use ($models, $builder) {
            $source = $item['_source'] ?? [];
            // $type = $source['type'] ?? $item['_type'] ?? $item['_index'];
            $type = $this->getTypeNameFromId($item['_id']) ?? $item['_index'];

            $modelClass = config("scout_elastic.type_mapping.{$type}");
            if ($modelClass === null || !class_exists($modelClass)) {
                \Illuminate\Support\Facades\Log::warning("Model class not found for type: {$type}");
                return;
            }

            /** @var Model $instance */
            $instance = new $modelClass();
            $scoutKeyName = $instance->getScoutKeyName();

            // Получаем выбранные поля для этого индекса из builder->select
            $selectedFields = $builder instanceof MixedSearch && isset($builder->select[$type])
                ? $builder->select[$type]
                : [];

            // Если select пуст или ['*'], включаем все поля
            $isSelectAll = empty($selectedFields) || (is_array($selectedFields) && array_keys($selectedFields) === ['*']);

            // Разделяем поля на те, что есть в модели (для databaseHydrate = true)
            $modelColumns = [];
            $tableColumns = $instance->getConnection()->getSchemaBuilder()->getColumnListing($instance->getTable());

            if ($isSelectAll) {
                // Для select(['*']) или пустого select включаем все столбцы модели
                $modelColumns = ['*'];
            } elseif ($selectedFields) {
                // Выбираем только поля, существующие в таблице модели
                foreach ($selectedFields as $field => $alias) {
                    $targetField = is_numeric($field) ? $alias : $field;
                    $targetAlias = is_numeric($field) ? $targetField : $alias;
                    if (in_array($targetField, $tableColumns)) {
                        $modelColumns[$targetField] = $targetAlias;
                    }
                    // Поля, которых нет в модели, игнорируются для databaseHydrate = true
                }
            }

            // Добавляем scoutKeyName, если его нет
            if (!in_array($scoutKeyName, array_keys($modelColumns)) && $modelColumns !== ['*']) {
                $modelColumns[$scoutKeyName] = $scoutKeyName;
            }

            if (property_exists($instance, 'databaseHydrate') && !$instance->databaseHydrate) {
                // Для databaseHydrate = false используем _source напрямую
                $attributes = Arr::get($item['_source'], $instance->indexAttributesPrefix, $item['_source']);
                $mappedAttributes = [];

                if ($isSelectAll) {
                    // Если select пуст или ['*'], возвращаем все поля из _source
                    foreach ($attributes as $key => $value) {
                        $mappedAttributes[$key] = $value;
                    }
                } elseif ($selectedFields) {
                    // Применяем только выбранные поля с алиасами
                    foreach ($selectedFields as $field => $alias) {
                        $targetField = is_numeric($field) ? $alias : $field;
                        $targetAlias = is_numeric($field) ? $targetField : $alias;
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
            } else {
                // Для databaseHydrate = true выбираем только поля, существующие в модели
                $queryColumns = $modelColumns === ['*'] ? ['*'] : array_keys($modelColumns);
                $query = $instance->newQuery();
                if ($modelClass::usesSoftDelete()) {
                    $query = $query->withTrashed();
                }

                //$model = $query->select($queryColumns)
                //    ->find($source[$scoutKeyName] ?? null) ?? new $modelClass();
                $model = $query->select($queryColumns)
                    ->where($scoutKeyName, '=', $source[$scoutKeyName] ?? null)->first() ?? new $modelClass();

                // Получаем атрибуты модели
                $attributes = $model->getAttributes();

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
                    // Если select пуст или ['*'], возвращаем все атрибуты модели
                    $model->setRawAttributes($attributes);
                }

                $models->put($item['_id'], $model);
            }
        });

        return $models;
    }
}
