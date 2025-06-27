<?php

namespace Novius\ScoutElastic\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Events\ModelsImported;
use Symfony\Component\Console\Attribute\AsCommand; // Добавляем EngineManager

#[AsCommand(name: 'scout:import-with-index')] // Изменяем имя команды, чтобы не конфликтовать с оригинальной
class ScoutImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:import-with-index
        {model : Class name of model to bulk import}
        {--c|chunk= : The number of records to import at a time (Defaults to configuration value: `scout.chunk.searchable`)}
        {--index= : The name of the index to import into (Overrides default index name/alias)}'; // <--- НОВАЯ ОПЦИЯ

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import the given model into the search index with an optional specific index name.';

    /**
     * Execute the console command.
     *
     * @param  \Laravel\Scout\EngineManager  $engineManager  // Инжектируем EngineManager
     * @return void
     */
    public function handle(Dispatcher $events, EngineManager $engineManager)
    {
        $class = $this->argument('model');
        $model = new $class;

        // Получаем желаемое имя индекса из опций
        $targetIndex = $this->option('index');

        $events->listen(ModelsImported::class, function ($event) use ($class, $targetIndex) {
            $key = $targetIndex?:$event->models->last()->getScoutKey();
            $this->line('<comment>Imported ['.$class.'] models up to ID:</comment> '.$key);
        });

        $model::makeAllSearchable($this->option('chunk'));

        $events->forget(ModelsImported::class);

        $this->info('All ['.$class.'] records have been imported.');
    }
}
