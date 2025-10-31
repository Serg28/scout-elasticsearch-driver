<?php

namespace Novius\ScoutElastic;

use Exception;
use Novius\ScoutElastic\Builders\FilterBuilder;
use Novius\ScoutElastic\Builders\SearchBuilder;
use Laravel\Scout\Searchable as SourceSearchable;

trait Searchable
{
    use SourceSearchable {
        SourceSearchable::bootSearchable as sourceBootSearchable;
        SourceSearchable::getScoutKeyName as sourceGetScoutKeyName;
    }

    /**
     * The highligths.
     *
     * @var \ScoutElastic\Highlight|null
     */
    private $highlight = null;

    /**
     * Defines if the model is searchable.
     *
     * @var bool
     */
    protected static $isSearchableTraitBooted = false;

    /**
     * Boot the trait.
     *
     * @return void
     */
    public static function bootSearchable()
    {
        if (static::$isSearchableTraitBooted) {
            return;
        }

        self::sourceBootSearchable();

        static::$isSearchableTraitBooted = true;
    }

    /**
     * Get the index configurator.
     *
     * @return Novius\ScoutElastic\IndexConfigurator
     * @throws \Exception
     */
    public function getIndexConfigurator()
    {
        static $indexConfigurator;

        if (! $indexConfigurator) {
            if (! isset($this->indexConfigurator) || empty($this->indexConfigurator)) {
                throw new Exception(sprintf(
                    'An index configurator for the %s model is not specified.',
                    __CLASS__
                ));
            }

            $indexConfiguratorClass = $this->indexConfigurator;
            $indexConfigurator = new $indexConfiguratorClass();
        }

        return $indexConfigurator;
    }

    /**
     * Get the search rules.
     *
     * @return array
     */
    public function getSearchRules()
    {
        return isset($this->searchRules) && count($this->searchRules) > 0 ?
            $this->searchRules : [SearchRule::class];
    }

    /**
     * Get the search rules.
     *
     * @return array
     */
    public function getSearchSettings()
    {
        return isset($this->searchSettings) && count($this->searchSettings) > 0 ?
            $this->searchSettings : [];
    }

    /**
     * Execute the search.
     *
     * @param string $query
     * @param callable|null $callback
     * @return \ScoutElastic\Builders\FilterBuilder|\ScoutElastic\Builders\SearchBuilder
     */
    public static function search($query, $callback = null)
    {
        $softDelete = static::usesSoftDelete() && config('scout.soft_delete', false);

        if ($query == '*') {
            return new FilterBuilder(new static(), $callback, $softDelete);
        } else {
            return new SearchBuilder(new static(), $query, $callback, $softDelete);
        }
    }

    /**
     * Execute a raw search.
     *
     * @param array $query
     * @return array
     */
    public static function searchRaw(array $query)
    {
        $model = new static();

        return $model->searchableUsing()
            ->searchRaw($model, $query);
    }

    /**
     * Set the highlight attribute.
     *
     * @param \ScoutElastic\Highlight $value
     * @return void
     */
    public function setHighlightAttribute(Highlight $value)
    {
        $this->highlight = $value;
    }

    /**
     * Get the highlight attribute.
     *
     * @return \ScoutElastic\Highlight|null
     */
    public function getHighlightAttribute()
    {
        return $this->highlight;
    }

    /**
     * Get the key name used to index the model.
     *
     * @return mixed
     */
    public function getScoutKeyName()
    {
        return $this->getKeyName();
    }

    public function getScoutKeyValue()
    {
        return $this->{$this->getScoutKeyName()} ?? $this->getKey();
    }

    public function getScoutKey()
    {
        // return $this->searchableAs().'_'.$this->getKey();
        return $this->searchableAs().'_'.$this->getScoutKeyValue();
    }

    /**
     * Get the name of the index associated with the model.
     *
     * @throws \Exception
     */
    public function searchableAs(): string
    {
        if (app()->bound('elasticIndexCreated')) {
            return app('elasticIndexCreated');  // Используем новый индекс
        }

        return $this->getIndexConfigurator()->getName();  // Возвращаем текущее имя алиаса
    }

    /*
     * Get the type of the index associated with the model.
     *
     * @return string
     */
    public function getSearchType(): string
    {
        return $this->getIndexConfigurator()->getType() ?: $this->searchableAs();
    }

    /**
     * Get information about the index, including creation date and update time.
     *
     * @return array
     * @throws Exception
     */
    public function getIndexInfo(): array
    {
        $indexName = $this->searchableAs();
        return $this->searchableUsing()->getIndexInfo($indexName);
    }

    /**
     * Статический метод получения информации об индексе Elasticsearch.
     *
     * @param string|null $indexName Если не передан — берём searchableAs() модели
     * @return array
     * @throws \Exception
     */
    public static function getIndexInfoStatic(?string $indexName = null): array
    {
        // Получаем экземпляр модели
        $model = new static;

        // Если имя индекса не передано, берём из searchableAs()
        $indexName = $indexName ?? $model->searchableAs();

        // Получаем информацию об индексе через сервис (searchableUsing)
        return $model->searchableUsing()->getIndexInfo($indexName);
    }
}
