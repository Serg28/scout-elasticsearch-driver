<?php

namespace Novius\ScoutElastic\Console;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Novius\ScoutElastic\Console\Features\RequiresModelArgument;
use Novius\ScoutElastic\Facades\ElasticClient;
use Novius\ScoutElastic\Payloads\IndexPayload;
use Novius\ScoutElastic\Payloads\RawPayload;
// use ScoutElastic\Migratable;
// use ScoutElastic\Migratable;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Class ElasticMigrateModelCommand
 *
 * Консольная команда для миграции модели Elasticsearch на другой индекс.
 * Эта команда позволяет создать новый индекс, скопировать в него данные,
 * обновить маппинги и настройки, а затем переключить псевдоним на новый индекс.
 *
 * Класс для миграции индекса Elasticsearch для указанной модели.
 *
 * Позволяет:
 *  - создать новый индекс с нужными настройками и маппингом;
 *  - скопировать в него все данные через очередь;
 *  - переключить псевдоним на новый индекс и удалить старый;
 *  - поддерживает индивидуальные очереди для разных моделей (см. config/scout_elastic.php);
 *  - отображает прогресс ожидания очереди с помощью прогрессбара.
 *
 * Использование:
 *   php artisan elastic:migrate-model 'App\\Models\\Product' new_index_name
 *
 * @author ...
 */
class ElasticMigrateModelCommand extends Command
{
    use RequiresModelArgument {
        RequiresModelArgument::getArguments as private modelArgument;
    }

    /**
     * Имя консольной команды.
     *
     * @var string
     */
    protected $name = 'elastic:migrate-model';

    /**
     * Описание консольной команды.
     *
     * @var string
     */
    protected $description = 'Migrate model to another index';

    /**
     * Имя очереди для операций переиндексации (определяется динамически).
     *
     * @var string
     */
    protected string $queueName;

    /**
     * Название подключения к очереди (redis, database и т.д.).
     *
     * @var string
     */
    protected string $queueConnection;

    /**
     * Получить имя очереди для текущей модели.
     * Если для модели не задана отдельная очередь, возвращает общую.
     *
     * @return string
     */
    protected function resolveQueueName(): string
    {
        $model = $this->getModel();
        $modelClass = is_object($model) ? get_class($model) : $model;
        $modelQueues = config('scout_elastic.model_queues', []);

        return $modelQueues[$modelClass] ?? config('scout_elastic.queue_name', 'shop-reindexModels');
    }

    /**
     * Конструктор команды. Устанавливает подключение к очереди.
     */
    public function __construct()
    {
        parent::__construct();
        $this->queueConnection = config('scout_elastic.queue_connection', 'redis');
        // queueName теперь определяется динамически
    }

    /**
     * Получить аргументы команды (модель и имя целевого индекса).
     *
     * @return array
     */
    protected function getArguments()
    {
        $arguments = $this->modelArgument();

        $arguments[] = ['target-index', InputArgument::REQUIRED, 'The index name to migrate'];

        return $arguments;
    }

    /**
     * Получить опции команды.
     *
     * @return array
     */
    // https://github.com/babenkoivan/scout-elasticsearch-driver/pull/139/files
    protected function getOptions()
    {
        $options = parent::getOptions();

        $options[] = ['no-queue', null, InputOption::VALUE_NONE, 'Turn off queue while importing'];

        return $options;
    }

    /**
     * Переключает псевдоним на целевой индекс.
     *
     * Если псевдоним с указанным именем существует, он атомарно переключается
     * со старого индекса на новый (целевой). Старый индекс затем удаляется.
     * Если псевдоним не существует (т.е. имя модели указывает на реальный индекс),
     * то исходный индекс удаляется, и для целевого индекса создается новый псевдоним.
     *
     * @param  string  $name  Имя псевдонима (обычно совпадает с именем индекса модели).
     * @return void
     */
    // https://github.com/babenkoivan/scout-elasticsearch-driver/pull/139/files
    protected function switchAliasForTargetIndex($name)
    {
        $targetIndex = $this->argument('target-index');

        $sourceIndexConfigurator = $this
            ->getModel()
            ->getIndexConfigurator();
        $payload = (new IndexPayload($sourceIndexConfigurator))
            ->get();

        // Имя индекса модели - это псевдоним, переключаем псевдоним с текущего индекса на целевой
        // в противном случае удаляем индекс и создаем псевдоним для целевого индекса
        if ($this->isAliasExists($sourceIndexConfigurator->getName())) {
            $aliases = $this->getAlias($sourceIndexConfigurator->getName());

            foreach ($aliases as $index => $alias) {

                // переключаем псевдоним на новый индекс за один атомарный шаг
                $payload = (new RawPayload)
                    ->set('body.actions.0.remove.alias', $name)
                    ->set('body.actions.0.remove.index', $index)
                    ->set('body.actions.1.add.alias', $name)
                    ->set('body.actions.1.add.index', $targetIndex)
                    ->get();

                ElasticClient::indices()
                    ->updateAliases($payload);

                $this->info(sprintf(
                    'Псевдоним %s был перемещен с %s на %s.',
                    $name,
                    $index,
                    $targetIndex
                ));

                // удаляем старый индекс
                $payload = (new RawPayload)
                    ->set('index', $index)
                    ->get();

                ElasticClient::indices()
                    ->delete($payload);

                $this->info(sprintf(
                    'Индекс %s был удален.',
                    $index
                ));
            }
        } else {
            // имя индекса модели - это фактический индекс
            $this->deleteSourceIndex();
            $this->createAliasForTargetIndex($name);
        }
    }

    /**
     * Проверяет, существует ли целевой индекс.
     *
     * @return bool True, если целевой индекс существует, иначе false.
     */
    protected function isTargetIndexExists()
    {
        $targetIndex = $this->argument('target-index');

        $payload = (new RawPayload)
            ->set('index', $targetIndex)
            ->get();

        return ElasticClient::indices()
            ->exists($payload);
    }

    /**
     * Создает целевой индекс.
     * Использует настройки из конфигуратора индекса исходной модели.
     *
     * @return void
     */
    protected function createTargetIndex()
    {
        $targetIndex = $this->argument('target-index');

        $sourceIndexConfigurator = $this->getModel()
            ->getIndexConfigurator();

        $payload = (new RawPayload)
            ->set('index', $targetIndex)
            ->setIfNotEmpty('body.settings', $sourceIndexConfigurator->getSettings())
            ->get();

        ElasticClient::indices()
            ->create($payload);

        $this->info(sprintf(
            'Индекс %s был создан.',
            $targetIndex
        ));
    }

    /**
     * Обновляет настройки целевого индекса.
     * Индекс временно закрывается для применения настроек, а затем открывается.
     *
     * @return void
     *
     * @throws \Exception Если при обновлении настроек возникает ошибка.
     */
    protected function updateTargetIndex()
    {
        $targetIndex = $this->argument('target-index');

        $sourceIndexConfigurator = $this->getModel()
            ->getIndexConfigurator();

        $targetIndexPayload = (new RawPayload)
            ->set('index', $targetIndex)
            ->get();

        $indices = ElasticClient::indices();

        try {
            $indices->close($targetIndexPayload);

            if ($settings = $sourceIndexConfigurator->getSettings()) {
                $targetIndexSettingsPayload = (new RawPayload)
                    ->set('index', $targetIndex)
                    ->set('body.settings', $settings)
                    ->get();

                $indices->putSettings($targetIndexSettingsPayload);
            }

            $indices->open($targetIndexPayload);
        } catch (Exception $exception) {
            // Убедимся, что индекс открыт, даже если произошла ошибка
            $indices->open($targetIndexPayload);

            throw $exception;
        }

        $this->info(sprintf(
            'Индекс %s был обновлен.',
            $targetIndex
        ));
    }

    /**
     * Обновляет маппинг целевого индекса.
     * Использует маппинг из исходной модели и конфигуратора индекса.
     *
     * @return void
     */
    protected function updateTargetIndexMapping()
    {
        $sourceModel = $this->getModel();
        $sourceIndexConfigurator = $sourceModel->getIndexConfigurator();

        $targetIndex = $this->argument('target-index');
        $targetType = $sourceModel->searchableAs();

        $mapping = $sourceIndexConfigurator->getDefaultMapping();

        if (empty($mapping)) {
            $this->warn(sprintf(
                'Маппинг для %s пуст.',
                get_class($sourceModel)
            ));

            return;
        }

        $payload = (new RawPayload)
            ->set('index', $targetIndex)
            ->set('type', $targetType)
            ->set('include_type_name', 'true')
            ->set('body.'.$targetType, $mapping)
            ->get();

        ElasticClient::indices()
            ->putMapping($payload);

        $this->info(sprintf(
            'Маппинг для %s был обновлен.',
            $targetIndex
        ));
    }

    /**
     * Проверяет, существует ли псевдоним с указанным именем.
     *
     * @param  string  $name  Имя псевдонима.
     * @return bool True, если псевдоним существует, иначе false.
     */
    protected function isAliasExists($name)
    {
        $payload = (new RawPayload)
            ->set('name', $name)
            ->get();

        return ElasticClient::indices()
            ->existsAlias($payload);
    }

    /**
     * Получает информацию о псевдониме.
     *
     * @param  string  $name  Имя псевдонима.
     * @return array<string, mixed> Массив, где ключи - имена индексов, на которые указывает псевдоним.
     */
    protected function getAlias($name)
    {
        $getPayload = (new RawPayload)
            ->set('name', $name)
            ->get();

        return ElasticClient::indices()
            ->getAlias($getPayload);
    }

    /**
     * Удаляет псевдоним.
     * Если псевдоним указывает на несколько индексов, он будет удален для каждого из них.
     *
     * @param  string  $name  Имя псевдонима.
     * @return void
     */
    protected function deleteAlias($name)
    {
        $aliases = $this->getAlias($name);

        if (empty($aliases)) {
            return;
        }

        foreach ($aliases as $index => $alias) {
            $deletePayload = (new RawPayload)
                ->set('index', $index)
                ->set('name', $name)
                ->get();

            ElasticClient::indices()
                ->deleteAlias($deletePayload);

            $this->info(sprintf(
                'Псевдоним %s для индекса %s был удален.',
                $name,
                $index
            ));
        }
    }

    /**
     * Создает псевдоним для целевого индекса.
     * Если псевдоним с таким именем уже существует, он сначала удаляется.
     *
     * @param  string  $name  Имя создаваемого псевдонима.
     * @return void
     */
    protected function createAliasForTargetIndex($name)
    {
        $targetIndex = $this->argument('target-index');

        if ($this->isAliasExists($name)) {
            $this->deleteAlias($name);
        }

        $payload = (new RawPayload)
            ->set('index', $targetIndex)
            ->set('name', $name)
            ->get();

        ElasticClient::indices()
            ->putAlias($payload);

        $this->info(sprintf(
            'Псевдоним %s для индекса %s был создан.',
            $name,
            $targetIndex
        ));
    }

    /**
     * Импортирует документы в целевой индекс через scout:import-with-index.
     * Перед импортом подменяет параметры очереди в конфиге scout для текущей модели.
     *
     * @return void
     */
    // https://github.com/babenkoivan/scout-elasticsearch-driver/pull/139/files
    protected function importDocumentsToTargetIndex()
    {
        $sourceModel = $this->getModel();
        $targetIndex = $this->argument('target-index');

        if ($this->option('no-queue')) {
            config(['scout.queue' => false]);
        } else {
            config([
                'scout.queue.queue' => $this->resolveQueueName(),
                'scout.queue.connection' => $this->queueConnection,
            ]);
        }

        $this->call(
            'scout:import-with-index',
            ['model' => get_class($sourceModel), '--index' => $targetIndex]
        );
    }

    /**
     * Удаляет исходный индекс.
     * Удаление происходит только если имя индекса модели не является псевдонимом.
     *
     * @return void
     */
    // https://github.com/babenkoivan/scout-elasticsearch-driver/pull/139/files
    protected function deleteSourceIndex()
    {
        $sourceIndexConfigurator = $this
            ->getModel()
            ->getIndexConfigurator();

        // Удаляем индекс, только если имя индекса - это фактический индекс
        if (! $this->isAliasExists($sourceIndexConfigurator->getName())) {
            $payload = (new IndexPayload($sourceIndexConfigurator))
                ->get();

            // Проверяем, существует ли индекс
            if (ElasticClient::indices()->exists($payload)) {
                ElasticClient::indices()->delete($payload);

                $this->info(sprintf(
                    'Индекс %s был удален.',
                    $sourceIndexConfigurator->getName()
                ));
            } else {
                $this->warn(sprintf(
                    'Индекс %s не существует, удаление не требуется.',
                    $sourceIndexConfigurator->getName()
                ));
            }
        }
    }

    /**
     * Удаляет неиспользуемые индексы, связанные с моделью.
     * Проверяет все индексы, начинающиеся с префикса модели,
     * и удаляет те, которые не имеют псевдонимов.
     */
    protected function deleteOrphanedModelIndices(): void
    {
        $model = $this->getModel();
        $indexPrefix = $model->getIndexConfigurator()->getName(); // например, 'products'
        $allIndices = ElasticClient::cat()->indices(['format' => 'json']);

        $aliases = ElasticClient::indices()->getAlias([]); // все алиасы

        foreach ($allIndices as $indexInfo) {
            $indexName = $indexInfo['index'];
            // Проверяем, что индекс относится к модели (например, products_*)
            if (strpos($indexName, $indexPrefix) === 0) {
                // Если у индекса нет алиасов — удаляем
                if (! isset($aliases[$indexName]) || empty($aliases[$indexName]['aliases'])) {
                    ElasticClient::indices()->delete(['index' => $indexName]);
                    $this->info("Удалён неиспользуемый индекс: $indexName");
                }
            }
        }
    }

    /**
     * Выполняет команду миграции индекса.
     *
     * Последовательность действий:
     * 1. Создает или обновляет целевой индекс.
     * 2. Обновляет маппинг целевого индекса.
     * 3. Создает псевдоним для записи (write alias) на целевой индекс.
     * 4. Импортирует документы в целевой индекс (возможно, через очередь).
     * 5. Ожидает завершения всех задач в очереди импорта.
     * 6. Переключает основной псевдоним модели на новый (целевой) индекс.
     *    При этом старый индекс, на который указывал псевдоним, удаляется.
     *    Если исходное имя индекса модели не было псевдонимом, то исходный индекс удаляется
     *    и создается новый псевдоним.
     * 7. Удаляет неиспользуемые индексы, связанные с моделью.
     *
     * @throws Exception
     */
    public function handle(): void
    {
        $sourceModel = $this->getModel();
        $sourceIndexConfigurator = $sourceModel->getIndexConfigurator();

        $this->newLine();
        $this->info('==============================');
        $this->info('  Миграция индекса для модели:');
        $this->line('  <info>' . get_class($sourceModel) . '</info>');
        $this->info('==============================');
        $this->newLine();

        $this->section('1. Проверка и создание/обновление целевого индекса');
        $this->isTargetIndexExists() ? $this->updateTargetIndex() : $this->createTargetIndex();

        $this->section('2. Обновление маппинга целевого индекса');
        $this->updateTargetIndexMapping();

        $this->section('3. Создание write-алиаса для целевого индекса');
        $this->createAliasForTargetIndex($sourceIndexConfigurator->getWriteAlias());

        $this->section('4. Импорт документов в новый индекс');
        $this->importDocumentsToTargetIndex();

        $this->section('5. Ожидание завершения очереди индексации');
        $this->waitForQueueToEmpty();
        $this->info('✅ Индексация завершена.');
        $this->newLine();

        $this->section('6. Переключение основного алиаса на новый индекс');
        $this->switchAliasForTargetIndex($sourceIndexConfigurator->getName());

        $this->section('7. Удаление неиспользуемых индексов');
        $this->deleteOrphanedModelIndices();

        $this->newLine();
        $this->info(str_repeat('=', 40));
        $this->info(sprintf(
            'Модель <info>%s</info> успешно мигрирована на индекс <info>%s</info>.',
            get_class($sourceModel),
            $this->argument('target-index')
        ));
        $this->info(str_repeat('=', 40));
        $this->newLine();
    }

    /**
     * Визуально выделяет этап процесса.
     *
     * @param string $title
     * @return void
     */
    protected function section(string $title): void
    {
        $this->newLine();
        $this->info(str_repeat('-', 40));
        $this->info($title);
        $this->info(str_repeat('-', 40));
        $this->newLine();
    }

    /**
     * Ожидает, пока очередь для текущей модели не опустеет.
     * Показывает прогрессбар.
     *
     * @return void
     */
    protected function waitForQueueToEmpty(): void
    {
        $queueName = $this->resolveQueueName();
        $connection = $this->queueConnection;
        $this->info("Ожидание завершения задач в очереди: $queueName");
        $count = Queue::connection($connection)->size($queueName);
        if ($count === 0) {
            $this->info('Очередь пуста.');

            return;
        }
        $bar = $this->output->createProgressBar($count);
        $bar->setFormat('progress: [%bar%] %current%/%max% (%percent:3s%%)');
        $bar->start();
        $lastCount = $count;
        do {
            $count = Queue::connection($connection)->size($queueName);
            if ($count > $lastCount) {
                $bar->setMaxSteps($count);
            }
            if ($count < $lastCount) {
                $bar->advance($lastCount - $count);
                $lastCount = $count;
            }
            if ($count > 0) {
                sleep(3);
            }
        } while ($count > 0);
        $bar->finish();
        $this->line('');
        $this->info('Очередь пуста.');
    }
}
