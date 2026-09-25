<?php

declare(strict_types=1);

namespace TgJobParser\Kernel;

use TgJobParser\Application\FileLock;
use TgJobParser\Database\Database;
use TgJobParser\Database\Migrator;
use TgJobParser\Enricher\EnricherInterface;
use TgJobParser\Filter\FilterStepInterface;
use TgJobParser\Filter\Pipeline;
use TgJobParser\Http\CurlHttpClient;
use TgJobParser\Http\HttpClientInterface;
use TgJobParser\Letter\Generator\LetterGeneratorInterface;
use TgJobParser\Letter\LetterGuard;
use TgJobParser\Letter\LetterService;
use TgJobParser\Letter\PromptBuilder;
use TgJobParser\Links\LinkExtractor;
use TgJobParser\Scoring\RuleFactory;
use TgJobParser\Scoring\Scorer;
use TgJobParser\Source\SourceDriverInterface;
use TgJobParser\Source\SourceRegistry;
use TgJobParser\Web\Session;
use TgJobParser\Web\View;
use UnexpectedValueException;

/**
 * Корень композиции: читает config/*.php и собирает контейнер.
 * Единственное место, где модули связываются между собой.
 */
final class App
{
    private function __construct(public readonly Container $container, public readonly Config $config)
    {
    }

    /**
     * @param array<string, mixed> $overrides точечные переопределения конфига (тесты, CLI-флаги)
     * @param array<string, \Closure(Container): mixed> $services подмена сервисов (тесты)
     */
    public static function boot(string $root, array $overrides = [], array $services = []): self
    {
        $config = self::loadConfig($root);
        foreach ($overrides as $key => $value) {
            $config = $config->with($key, $value);
        }
        $container = new Container();
        $app = new self($container, $config);
        $app->register();
        foreach ($services as $id => $factory) {
            $container->set($id, $factory);
        }
        $app->registerListeners();

        return $app;
    }

    public static function loadConfig(string $root): Config
    {
        $envReader = new Env($root . '/.env');
        $env = static fn (string $key, ?string $default = null): ?string => $envReader->get($key, $default);
        $load = static function (string $file) use ($root, $env): array {
            $value = (static fn (string $__file, string $root, \Closure $env): mixed => require $__file)($file, $root, $env);
            if (!is_array($value)) {
                throw new UnexpectedValueException("Конфиг {$file} должен возвращать массив");
            }

            return $value;
        };
        $items = $load($root . '/config/app.php');
        $items['profile'] = $load($root . '/config/profile.php');
        $items['pipeline'] = $load($root . '/config/pipeline.php');
        $items['modules'] = $load($root . '/config/modules.php');
        $items['routes'] = $load($root . '/config/routes.php');

        return new Config($items);
    }

    public function get(string $id): mixed
    {
        return $this->container->get($id);
    }

    public function migrate(): array
    {
        return $this->container->get(Migrator::class)->migrate();
    }

    private function register(): void
    {
        $c = $this->container;
        $config = $this->config;

        $c->instance(Config::class, $config);
        $c->instance(Container::class, $c);
        $c->instance(self::class, $this);
        $c->set(LoggerInterface::class, fn () => new FileLogger((string) $config->get('paths.log')));
        $c->set(Clock::class, fn () => new Clock());
        $c->set(EventDispatcher::class, fn (Container $c) => new EventDispatcher($c->get(LoggerInterface::class)));

        $c->set(Database::class, fn () => Database::connect(
            (string) $config->get('db.dsn'),
            $config->get('db.user'),
            $config->get('db.password'),
        ));
        $c->set(Migrator::class, fn (Container $c) => new Migrator($c->get(Database::class), (string) $config->get('paths.migrations')));

        $c->set(HttpClientInterface::class, fn () => new CurlHttpClient(
            timeout: (int) $config->get('http.timeout', 20),
            connectTimeout: (int) $config->get('http.connect_timeout', 8),
            retries: (int) $config->get('http.retries', 2),
            userAgent: (string) $config->get('http.user_agent', 'tg-job-parser/1.0'),
        ));
        $c->set(FileLock::class, fn () => new FileLock((string) $config->get('paths.lock')));

        // --- Источники ---
        $c->set(SourceRegistry::class, fn (Container $c) => new SourceRegistry(
            $this->instantiateAll('modules.source_drivers', SourceDriverInterface::class),
        ));

        // --- Конвейер ---
        $c->set(LinkExtractor::class, fn () => new LinkExtractor($config->array('pipeline.link_strategies')));
        $c->set(Scorer::class, fn (Container $c) => new Scorer(
            (new RuleFactory($config->array('modules.scoring_rule_types')))->createAll($config->array('pipeline.scoring_rules')),
            $c->get(LoggerInterface::class),
        ));
        $c->set(Pipeline::class, function (Container $c) use ($config): Pipeline {
            $enrichers = $this->instantiateAll('modules.enrichers', EnricherInterface::class);
            $ordered = [];
            foreach ($config->array('pipeline.enrichers') as $id) {
                if (isset($enrichers[$id])) {
                    $ordered[$id] = $enrichers[$id];
                }
            }
            $available = $this->instantiateAll('modules.filter_steps', FilterStepInterface::class);
            $steps = [];
            foreach ($config->array('pipeline.steps') as $definition) {
                $id = is_array($definition) ? (string) $definition['id'] : (string) $definition;
                if (!isset($available[$id])) {
                    throw new UnexpectedValueException("Шаг «{$id}» из pipeline.php не зарегистрирован в modules.php");
                }
                $steps[] = ['id' => $id, 'step' => $available[$id], 'on_error' => is_array($definition) ? (string) ($definition['on_error'] ?? 'continue') : 'continue'];
            }

            return new Pipeline($ordered, $steps, $c->get(LoggerInterface::class));
        });

        // --- Письма ---
        $c->set(PromptBuilder::class, fn () => new PromptBuilder($config->get('paths.templates') . '/prompts/cover_letter.txt'));
        $c->set(LetterGuard::class, fn () => new LetterGuard(
            (array) $config->get('letters.max_words', []),
            array_values((array) $config->get('letters.forbidden_phrases', [])),
        ));
        // --- Веб ---
        $c->set(Session::class, static fn () => new Session());
        $c->set(View::class, fn () => new View((string) $config->get('paths.templates')));

        $c->set(LetterService::class, fn (Container $c) => $c->make(LetterService::class, [
            'generators' => $this->instantiateAll('modules.letter_generators', LetterGeneratorInterface::class),
        ]));
    }

    private function registerListeners(): void
    {
        $events = $this->container->get(EventDispatcher::class);
        foreach ($this->config->array('modules.listeners') as $event => $classes) {
            foreach ((array) $classes as $class) {
                // Ленивое создание: подписчик собирается только когда событие реально произошло
                $events->listen((string) $event, function (array $payload) use ($class): void {
                    $listener = $this->container->get($class);
                    if (!$listener instanceof ListenerInterface) {
                        throw new UnexpectedValueException("{$class} должен реализовывать ListenerInterface");
                    }
                    $listener->handle($payload);
                });
            }
        }
    }

    /**
     * Создаёт модули из реестра config/modules.php, проверяя контракт.
     *
     * @template T of object
     * @param class-string<T> $contract
     * @return array<string, T>
     */
    private function instantiateAll(string $key, string $contract): array
    {
        $result = [];
        foreach ($this->config->array($key) as $id => $class) {
            $instance = $this->container->get((string) $class);
            if (!$instance instanceof $contract) {
                throw new UnexpectedValueException("{$class} ({$key}.{$id}) должен реализовывать {$contract}");
            }
            $result[(string) $id] = $instance;
        }

        return $result;
    }
}
