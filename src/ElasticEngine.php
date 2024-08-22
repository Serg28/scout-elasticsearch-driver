<?php

namespace Novius\ScoutElastic;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Novius\ScoutElastic\Builders\SearchBuilder;
use Novius\ScoutElastic\Facades\ElasticClient;
use Novius\ScoutElastic\Indexers\IndexerInterface;
use Novius\ScoutElastic\Payloads\TypePayload;
use stdClass;

class ElasticEngine extends Engine
{
    /**
     * The indexer interface.
     *
     * @var \ScoutElastic\Indexers\IndexerInterface
     */
    protected $indexer;

    /**
     * ElasticEngine constructor.
     *
     * @param \ScoutElastic\Indexers\IndexerInterface $indexer
     * @return void
     */
    public function __construct(IndexerInterface $indexer)
    {
        $this->indexer = $indexer;
    }

    /**
     * {@inheritdoc}
     */
    public function update($models)
    {
        $this
            ->indexer
            ->update($models);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($models)
    {
        $this->indexer->delete($models);
    }

    /**
     * Build the payload collection.
     *
     * @param \Laravel\Scout\Builder $builder
     * @param array $options
     * @return \Illuminate\Support\Collection
     */
    public function buildSearchQueryPayloadCollection(Builder $builder, array $options = [])
    {
        $payloadCollection = collect();

        if ($builder instanceof SearchBuilder) {
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

        return $payloadCollection->map(function (TypePayload $payload) use ($builder, $options) {
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
                $clauseKey = 'body.query.bool.filter.bool.'.$clause;
                //$clauseKey = 'body.query.bool.filter';  // Это последний рабочий вариант, но не работает с orWhere

                $clauseValue = array_merge(
                    $payload->get($clauseKey, []),
                    $filters
                );

                $payload->setIfNotEmpty($clauseKey, $clauseValue);

            }

            return $payload->get();
        });
    }

    /**
     * Perform the search.
     *
     * @param \Laravel\Scout\Builder $builder
     * @param array $options
     * @return array|mixed
     */
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
                $results = ElasticClient::search($payload);

                $results['_payload'] = $payload;

                if ($this->getTotalCount($results) > 0) {
                    return false;
                }
            });

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder);
    }

    /**
     * {@inheritdoc}
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $builder
            ->from(($page - 1) * $perPage)
            ->take($perPage);

        return $this->performSearch($builder);
    }

    /**
     * Explain the search.
     *
     * @param \Laravel\Scout\Builder $builder
     * @return array|mixed
     */
    public function explain(Builder $builder)
    {
        return $this->performSearch($builder, [
            'explain' => true,
        ]);
    }

    /**
     * Profile the search.
     *
     * @param \Laravel\Scout\Builder $builder
     * @return array|mixed
     */
    public function profile(Builder $builder)
    {
        return $this->performSearch($builder, [
            'profile' => true,
        ]);
    }

    /**
     * Aggregations for the search.
     *
     * @param \Laravel\Scout\Builder $builder
     * @return array|mixed
     */
    public function aggregations(Builder $builder, $aggregations)
    {
        return $this->performSearch($builder, [
            $builder->aggregations = $aggregations
        ]);
    }

    /**
     * Return the number of documents found.
     *
     * @param \Laravel\Scout\Builder $builder
     * @return int
     */
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

    /**
     * Make a raw search.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param array $query
     * @return mixed
     */
    public function searchRaw(Model $model, $query)
    {
        $payload = (new TypePayload($model))
            ->setIfNotEmpty('body', $query)
            ->get();

        return ElasticClient::search($payload);
    }

    /**
     * {@inheritdoc}
     */
    public function mapIds($results)
    {
        return collect($results['hits']['hits'])->map(function ($result) {
            $result['_id'] = $this->getModelIDFromHit($result);

            return $result;
        })->pluck('_id');
    }

    /**
     * {@inheritdoc}
     */
    public function map(Builder $builder, $results, $model)
    {
        if ($this->getTotalCount($results) == 0) {
            return Collection::make();
        }

        $scoutKeyName = $model->getScoutKeyName();

        $columns = Arr::get($results, '_payload.body._source');

        if (is_null($columns)) {
            $columns = ['*'];
        } else {
            $columns[] = $scoutKeyName;
        }

        $ids = $this->mapIds($results)->all();

        $query = $model::usesSoftDelete() ? $model->withTrashed() : $model->newQuery();

        $models = $query
            ->whereIn($scoutKeyName, $ids)
            ->get($columns)
            ->keyBy($scoutKeyName);

        return Collection::make($results['hits']['hits'])
            ->map(function ($hit) use ($models) {
                $id = $this->getModelIDFromHit($hit);

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
     * {@inheritdoc}
     */
    public function getTotalCount($results)
    {
        return $results['hits']['total']['value'] ?? 0;
    }

    /**
     * {@inheritdoc}
     */
    public function flush($model)
    {
        $query = $model::usesSoftDelete() ? $model->withTrashed() : $model->newQuery();

        $query
            ->orderBy($model->getScoutKeyName())
            ->unsearchable();
    }

    /**
     * Extract model ID from hit (by removing prefix type)
     *
     * @param $hit
     * @return mixed
     */
    protected function getModelIDFromHit($hit)
    {
        //return str_replace($hit['_source']['type'].'_', '', $hit['_id']);
        return last(explode('_',$hit['_id']));
    }

    public function lazyMap(Builder $builder, $results, $model)
    {
        if ($this->getTotalCount($results) == 0) {
            return LazyCollection::make();
        }

        $scoutKeyName = $model->getScoutKeyName();

        $columns = Arr::get($results, '_payload.body._source');

        if (is_null($columns)) {
            $columns = ['*'];
        } else {
            $columns[] = $scoutKeyName;
        }

        $ids = $this->mapIds($results)->all();

        $query = $model::usesSoftDelete() ? $model->withTrashed() : $model->newQuery();

        // Получение моделей частями с использованием LazyCollection
        $models = $query->whereIn($scoutKeyName, $ids)->cursor()->keyBy($scoutKeyName);

        return LazyCollection::make($results['hits']['hits'])
            ->map(function ($hit) use ($models, $scoutKeyName) {
                $id = $this->getModelIDFromHit($hit);

                $model = $models->firstWhere($scoutKeyName, $id);
                if ($model) {
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
        // TODO: Implement deleteIndex() method.
        $this->indexer->delete($name);
    }
}
