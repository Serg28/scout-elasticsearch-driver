<?php

namespace Novius\ScoutElastic\Builders;

use Laravel\Scout\Builder;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Laravel\Scout\EngineManager;
use Novius\ScoutElastic\ElasticEngine;
use Novius\ScoutElastic\Facades\ElasticClient;
use Illuminate\Support\Arr;
use Illuminate\Pagination\LengthAwarePaginator;

class MixedSearch extends Builder
{
    public $indices = [];
    protected $models = [];
    public $select = []; // Хранит поля для каждого индекса, например ['products' => ['title' => 'product_title', 'price']]
    public $offset;
    public $limit;
    public $wheres = [
        'must' => [],
        'must_not' => [],
        'should' => [],
    ];
    public $orders = [];
    protected $aggregations = [];
    protected $minScore;
    protected $minimumShouldMatch;
    protected $collapse;
    protected $with;

    public function __construct()
    {
        parent::__construct(null, null);
    }

    public static function create(): self
    {
        return new static();
    }

    public function within($index)
    {
        if (is_string($index) && class_exists($index) && is_subclass_of($index, Model::class)) {
            $indexName = (new $index)->searchableAs();
            $this->models[$indexName] = $index;
        } elseif ($index instanceof Model) {
            $indexName = $index->searchableAs();
            $this->models[$indexName] = get_class($index);
        } else {
            $indexName = $index;
            $this->models[$indexName] = null;
        }

        $this->indices[] = $indexName;

        return $this;
    }

    public function query($query)
    {
        $this->query = $query;
        return $this;
    }

    public function select($fields)
    {
        // Инициализируем select для всех индексов по умолчанию как ['*']
        foreach ($this->indices as $index) {
            $this->select[$index] = [];
        }

        if (is_array($fields) && !Arr::isAssoc($fields)) {
            // Если передан плоский массив, применяем его ко всем индексам
            foreach ($this->indices as $index) {
                $this->select[$index] = array_fill_keys($fields, null);
            }
        } elseif (is_array($fields)) {
            // Если передан массив с индексами, сохраняем как есть
            foreach ($fields as $index => $indexFields) {
                if (!in_array($index, $this->indices)) {
                    throw new \InvalidArgumentException("Index {$index} not specified in within()");
                }
                $this->select[$index] = [];
                foreach ($indexFields as $field => $alias) {
                    if (is_numeric($field)) {
                        $this->select[$index][$alias] = null; // Поле без алиаса
                    } else {
                        $this->select[$index][$field] = $alias; // Поле с алиасом
                    }
                }
            }
        } else {
            // Если строка, преобразуем в массив для всех индексов
            foreach ($this->indices as $index) {
                $this->select[$index] = array_fill_keys(Arr::wrap($fields), null);
            }
        }

        return $this;
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

    public function paginate($perPage = null, $pageName = 'page', $page = null)
    {
        $page = $page ?: \Illuminate\Pagination\Paginator::resolveCurrentPage($pageName);
        $perPage = $perPage ?: 15;

        $this->from(($page - 1) * $perPage)->take($perPage);

        $results = $this->get();
        $total = $this->engine()->getTotalCount($results);

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

    public function where($field, $operator = null, $value = null, $boolean = 'must')
    {
        if ($field instanceof \Closure) {
            return $this->whereNested($field, $boolean);
        }

        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        switch ($operator) {
            case '=':
                $this->wheres[$boolean][] = ['term' => [$field => $value]];
                break;
            case '>':
                $this->wheres[$boolean][] = ['range' => [$field => ['gt' => $value]]];
                break;
            case '<':
                $this->wheres[$boolean][] = ['range' => [$field => ['lt' => $value]]];
                break;
            case '>=':
                $this->wheres[$boolean][] = ['range' => [$field => ['gte' => $value]]];
                break;
            case '<=':
                $this->wheres[$boolean][] = ['range' => [$field => ['lte' => $value]]];
                break;
            case '!=':
            case '<>':
                $term = ['term' => [$field => $value]];
                $this->setNegativeCondition($term, $boolean);
                break;
        }

        return $this;
    }

    public function orWhere($field, $operator = null, $value = null)
    {
        return $this->where($field, $operator, $value, 'should');
    }

    public function whereNested(\Closure $callback, $boolean = 'must')
    {
        $modelClass = $this->models[array_key_first($this->models)] ?? \App\Models\Product::class;
        $filter = new FilterBuilder(new $modelClass, null, false);
        call_user_func($callback, $filter);
        $payload = $filter->buildPayload();
        $this->wheres[$boolean][] = $payload[0]['body']['query']['bool']['filter'] ?? [];

        return $this;
    }

    protected function prepareValueAndOperator($value, $operator, $useDefault = false)
    {
        if ($useDefault) {
            return [$operator, '='];
        }
        return [$value, $operator];
    }

    protected function setNegativeCondition($condition, $boolean = 'must')
    {
        if ($boolean == 'should') {
            $this->wheres[$boolean][] = ['bool' => ['must_not' => [$condition]]];
        } else {
            $this->wheres['must_not'][] = $condition;
        }
    }

    public function whereIn($field, $value, $boolean = 'must')
    {
        $this->wheres[$boolean][] = ['terms' => [$field => $value]];
        return $this;
    }

    public function orWhereIn($field, array $value)
    {
        return $this->whereIn($field, $value, 'should');
    }

    public function whereNotIn($field, $value, $boolean = 'must')
    {
        $term = ['terms' => [$field => $value]];
        $this->setNegativeCondition($term, $boolean);
        return $this;
    }

    public function orWhereNotIn($field, array $value)
    {
        return $this->whereNotIn($field, $value, 'should');
    }

    public function whereBetween($field, array $value, $boolean = 'must')
    {
        $this->wheres[$boolean][] = ['range' => [$field => ['gte' => $value[0], 'lte' => $value[1]]]];
        return $this;
    }

    public function orWhereBetween($field, array $value)
    {
        return $this->whereBetween($field, $value, 'should');
    }

    public function whereNotBetween($field, array $value, $boolean = 'must')
    {
        $term = ['range' => [$field => ['gte' => $value[0], 'lte' => $value[1]]]];
        $this->setNegativeCondition($term, $boolean);
        return $this;
    }

    public function orWhereNotBetween($field, array $value)
    {
        return $this->whereNotBetween($field, $value, 'should');
    }

    public function whereExists($field, $boolean = 'must')
    {
        $this->wheres[$boolean][] = ['exists' => ['field' => $field]];
        return $this;
    }

    public function orWhereExists($field)
    {
        return $this->whereExists($field, 'should');
    }

    public function whereNotExists($field, $boolean = 'must')
    {
        $term = ['exists' => ['field' => $field]];
        $this->setNegativeCondition($term, $boolean);
        return $this;
    }

    public function orWhereNotExists($field)
    {
        return $this->whereNotExists($field, 'should');
    }

    public function whereMatch($field, $value, $boolean = 'must')
    {
        $this->wheres[$boolean][] = ['match' => [$field => $value]];
        return $this;
    }

    public function orWhereMatch($field, $value)
    {
        return $this->whereMatch($field, $value, 'should');
    }

    public function whereNotMatch($field, $value, $boolean = 'must')
    {
        $term = ['match' => [$field => $value]];
        $this->setNegativeCondition($term, $boolean);
        return $this;
    }

    public function orWhereNotMatch($field, $value)
    {
        return $this->whereNotMatch($field, $value, 'should');
    }

    public function whereRegexp($field, $value, $flags = 'ALL', $boolean = 'must')
    {
        $this->wheres[$boolean][] = ['regexp' => [$field => ['value' => $value, 'flags' => $flags]]];
        return $this;
    }

    public function orWhereRegexp($field, $value, $flags = 'ALL')
    {
        return $this->whereRegexp($field, $value, $flags, 'should');
    }

    public function whereGeoDistance($field, $value, $distance, $boolean = 'must')
    {
        $this->wheres[$boolean][] = ['geo_distance' => ['distance' => $distance, $field => $value]];
        return $this;
    }

    public function orWhereGeoDistance($field, $value, $distance)
    {
        return $this->whereGeoDistance($field, $value, $distance, 'should');
    }

    public function whereGeoBoundingBox($field, array $value, $boolean = 'must')
    {
        $this->wheres[$boolean][] = ['geo_bounding_box' => [$field => $value]];
        return $this;
    }

    public function orWhereGeoBoundingBox($field, $value)
    {
        return $this->whereGeoBoundingBox($field, $value, 'should');
    }

    public function whereGeoPolygon($field, array $points, $boolean = 'must')
    {
        $this->wheres[$boolean][] = ['geo_polygon' => [$field => ['points' => $points]]];
        return $this;
    }

    public function orWhereGeoPolygon($field, array $points)
    {
        return $this->whereGeoPolygon($field, $points, 'should');
    }

    public function whereGeoShape($field, array $shape, $relation = 'INTERSECTS', $boolean = 'must')
    {
        $this->wheres[$boolean][] = ['geo_shape' => [$field => ['shape' => $shape, 'relation' => $relation]]];
        return $this;
    }

    public function orWhereGeoShape($field, array $shape, $relation = 'INTERSECTS')
    {
        return $this->whereGeoShape($field, $shape, $relation, 'should');
    }

    public function orderBy($field, $direction = 'asc')
    {
        $this->orders[] = [$field => strtolower($direction) == 'asc' ? 'asc' : 'desc'];
        return $this;
    }

    public function orderRaw(array $payload)
    {
        $this->orders[] = $payload;
        return $this;
    }

    public function aggregate($aggregations)
    {
        $this->aggregations = array_merge($this->aggregations, Arr::wrap($aggregations));
        return $this;
    }

    public function collapse(string $field)
    {
        $this->collapse = $field;
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

    public function withTrashed()
    {
        $this->wheres['must'] = collect($this->wheres['must'])
            ->filter(function ($item) {
                return Arr::get($item, 'term.__soft_deleted') !== 0;
            })
            ->values()
            ->all();
        return $this;
    }

    public function onlyTrashed()
    {
        return tap($this->withTrashed(), function () {
            $this->wheres['must'][] = ['term' => ['__soft_deleted' => 1]];
        });
    }

    public function engine()
    {
        return app(EngineManager::class)->engine();
    }

    public function get(): Collection
    {
        if (empty($this->indices)) {
            throw new \InvalidArgumentException('Необходимо указать хотя бы один индекс или модель.');
        }

        /** @var ElasticEngine $engine */
        $engine = $this->engine();

        $payload = $this->buildPayload();
        $params = [
            'index' => implode(',', array_unique($this->indices)),
            'body' => $payload,
        ];

        if (config('scout_elastic.log_enabled', false)) {
            \Illuminate\Support\Facades\Log::channel(config('scout_elastic.log_channels')[0])
                ->debug('Elasticsearch multi-index query', $params);
        }

        /** @var Elasticsearch $response */
        $response = ElasticClient::search($params);
        $response['_payload'] = $params; // Сохраняем payload для использования в hydrateMixedModels

        $collection = $engine->map($this, $response, null);

        if (isset($this->with) && $collection->count() > 0) {
            $collection->load($this->with);
        }

        return $collection;
    }

    public function buildPayload(): array
    {
        $payload = [
            'track_total_hits' => true,
        ];

        // Формируем _source с учетом алиасов
        if (!empty($this->select)) {
            $source = [];
            foreach ($this->select as $index => $fields) {
                $modelClass = $this->models[$index] ?? null;
                if ($modelClass && class_exists($modelClass)) {
                    $instance = new $modelClass();
                    $scoutKeyName = $instance->getScoutKeyName();
                    // Добавляем scoutKeyName и type, если их нет
                    if (!isset($fields[$scoutKeyName])) {
                        $fields[$scoutKeyName] = null;
                    }
                    if (!isset($fields['type'])) {
                        $fields['type'] = null;
                    }
                }
                foreach ($fields as $field => $alias) {
                    $source[] = $field; // Добавляем только имена полей в _source
                }
            }
            $payload['_source'] = !empty($source) ? array_unique($source) : true;
        } else {
            $payload['_source'] = true;
        }

        if (isset($this->minScore)) {
            $payload['min_score'] = $this->minScore;
        }

        if (isset($this->offset)) {
            $payload['from'] = $this->offset;
        }

        if (isset($this->limit)) {
            $payload['size'] = $this->limit;
        }

        if (!empty($this->orders)) {
            $payload['sort'] = $this->orders;
        }

        if (!empty($this->aggregations)) {
            $payload['aggs'] = $this->aggregations;
        }

        if (isset($this->collapse)) {
            $payload['collapse'] = ['field' => $this->collapse];
        }

        $boolQuery = ['bool' => []];

        if (!empty($this->query)) {
            $boolQuery['bool']['must'] = array_merge(
                $boolQuery['bool']['must'] ?? [],
                [['query_string' => ['query' => $this->query ?: '*']]]
            );
        }

        if (!empty($this->wheres['must'])) {
            $boolQuery['bool']['filter'] = $this->wheres['must'];
        }

        if (!empty($this->wheres['must_not'])) {
            $boolQuery['bool']['must_not'] = $this->wheres['must_not'];
        }

        if (!empty($this->wheres['should'])) {
            $boolQuery['bool']['should'] = $this->wheres['should'];
            if (isset($this->minimumShouldMatch)) {
                $boolQuery['bool']['minimum_should_match'] = $this->minimumShouldMatch;
            }
        }

        if (!empty($boolQuery['bool'])) {
            $payload['query'] = $boolQuery;
        }

        /*foreach ($this->models as $indexName => $modelClass) {
            if ($modelClass && class_exists($modelClass) && property_exists($modelClass, 'searchSettings')) {
                $model = new $modelClass;
                $payload = array_merge_recursive($payload, $model->searchSettings ?? []);
            }
        }*/
        // Объединяем searchSettings
        foreach ($this->models as $modelClass) {
            if (!$modelClass || !class_exists($modelClass) || !property_exists($modelClass, 'searchSettings')) {
                continue;
            }

            $modelInstance = new $modelClass();
            foreach ($modelInstance->searchSettings ?? [] as $key => $value) {
                if (is_array($value) && isset($payload[$key]) && is_array($payload[$key])) {
                    $payload[$key] = array_merge($payload[$key], $value);
                } else {
                    $payload[$key] = $value;
                }
            }
        }

        if ($this->callback) {
            return call_user_func($this->callback, ElasticClient::getFacadeRoot(), $payload);
        }

        return $payload;
    }
}
