<?php

namespace Novius\ScoutElastic\Console;

use Illuminate\Console\Command;
use Novius\ScoutElastic\Console\Features\HasConfigurator;
use Novius\ScoutElastic\Console\Features\RequiresIndexConfiguratorArgument;
use Novius\ScoutElastic\Payloads\IndexPayload;
use Symfony\Component\Console\Command\Command as CommandAlias;

/**
 * Команда для пересоздания индекса модели в Elasticsearch для scout
 */
class ElasticIndexReindexCommand extends Command
{
    use HasConfigurator;
    use RequiresIndexConfiguratorArgument;

    protected $signature = 'elastic:reindex
                            {index-configurator : The index configurator class}';

    protected $description = 'Reindex an Elasticsearch index with 0 downtime';

    /**
     * Handle the command.
     *
     * @return int
     */
    public function handle(): int
    {
        try {
            $this->configurator = $this->getIndexConfigurator();
            $alias = $this->configurator->getName();

            $this->info(sprintf('Searching for existing alias : %s.', $alias));

            if (! $this->aliasAlreadyExists()) {
                $this->info(sprintf('No index found for alias : %s.', $alias));
            } else {
                $currentIndex = $this->findIndexNameByAlias($alias);
                $this->info(sprintf('An index already exists : %s. Create another.', $currentIndex));
            }

            $indexCreationPayload = new IndexPayload($this->configurator, true);
            $newIndexName = $indexCreationPayload->get('index');

            if ($newIndexName) {
                collect(config('scout_elastic.searchable_models', []))
                    ->filter(function ($indexableClass) {
                        $model = new $indexableClass;

                        return method_exists($model,
                            'getIndexConfigurator') && get_class($model->getIndexConfigurator()) === get_class($this->configurator);
                    })->each(function ($class) use ($newIndexName) {
                        $this->call('elastic:migrate-model', [
                            'model' => $class,
                            'target-index' => $newIndexName,
                        ]);
                    });
            }

            return CommandAlias::SUCCESS;
        } catch (\Exception $e) {
            $this->error($e);

            return CommandAlias::FAILURE;
        }
    }
}
