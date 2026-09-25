<?php

declare(strict_types=1);

namespace TgJobParser\Tests\Integration;

use TgJobParser\Cli\CommandInterface;
use TgJobParser\Enricher\EnricherInterface;
use TgJobParser\Filter\FilterStepInterface;
use TgJobParser\Kernel\App;
use TgJobParser\Kernel\ListenerInterface;
use TgJobParser\Letter\Generator\LetterGeneratorInterface;
use TgJobParser\Profile\Matcher;
use TgJobParser\Profile\Term;
use TgJobParser\Scoring\ScoringRuleInterface;
use TgJobParser\Source\SourceDriverInterface;
use TgJobParser\Tests\TestCase;

/**
 * Страховка от «сломал конфиг — упал прод»: любая правка config/*.php,
 * нарушающая контракты модулей, ловится здесь, до деплоя.
 */
final class ModuleContractsTest extends TestCase
{
    private function config(): \TgJobParser\Kernel\Config
    {
        return App::loadConfig(dirname(__DIR__, 2));
    }

    public function testEveryRegisteredModuleImplementsItsContract(): void
    {
        $contracts = [
            'modules.source_drivers' => SourceDriverInterface::class,
            'modules.enrichers' => EnricherInterface::class,
            'modules.filter_steps' => FilterStepInterface::class,
            'modules.scoring_rule_types' => ScoringRuleInterface::class,
            'modules.letter_generators' => LetterGeneratorInterface::class,
            'modules.commands' => CommandInterface::class,
        ];
        foreach ($contracts as $key => $interface) {
            foreach ($this->config()->array($key) as $id => $class) {
                $this->assertTrue(class_exists($class), "{$key}.{$id}: класс {$class} не найден");
                $this->assertTrue(is_subclass_of($class, $interface), "{$key}.{$id}: {$class} не реализует {$interface}");
            }
        }
        foreach ($this->config()->array('modules.listeners') as $event => $classes) {
            foreach ($classes as $class) {
                $this->assertTrue(is_subclass_of($class, ListenerInterface::class), "{$event}: {$class}");
            }
        }
    }

    public function testPipelineReferencesOnlyRegisteredModules(): void
    {
        $config = $this->config();
        $steps = $config->array('modules.filter_steps');
        foreach ($config->array('pipeline.steps') as $step) {
            $this->assertTrue(isset($steps[$step['id']]), "Шаг {$step['id']} не зарегистрирован");
            $this->assertTrue(in_array($step['on_error'] ?? 'continue', ['continue', 'reject'], true));
        }
        $enrichers = $config->array('modules.enrichers');
        foreach ($config->array('pipeline.enrichers') as $id) {
            $this->assertTrue(isset($enrichers[$id]), "Обогатитель {$id} не зарегистрирован");
        }
        $types = $config->array('modules.scoring_rule_types');
        $lists = $config->array('profile.lists');
        foreach ($config->array('pipeline.scoring_rules') as $rule) {
            $this->assertTrue(isset($types[$rule['type']]), "Тип правила {$rule['type']} не зарегистрирован");
            $this->assertTrue(is_int($rule['points']), "Правило {$rule['id']}: points должно быть int");
            if (isset($rule['list'])) {
                $this->assertTrue(isset($lists[$rule['list']]), "Правило {$rule['id']} ссылается на несуществующий список {$rule['list']}");
            }
            foreach ($rule['type'] === 'regex' ? $rule['patterns'] : [] as $regex) {
                $this->assertTrue(@preg_match($regex, '') !== false, "Некорректный regex в правиле {$rule['id']}: {$regex}");
            }
        }
    }

    public function testEveryProfilePatternCompiles(): void
    {
        foreach ($this->config()->array('profile.lists') as $name => $items) {
            foreach ($items as $definition) {
                foreach (Term::from($definition)->patterns as $pattern) {
                    $this->assertTrue(Matcher::isValidPattern($pattern), "Список {$name}: шаблон «{$pattern}» не компилируется");
                }
            }
        }
        foreach ($this->config()->array('profile.editable_lists') as $name => $_) {
            $this->assertTrue(array_key_exists($name, $this->config()->array('profile.lists')), "editable_lists: нет списка {$name}");
        }
    }

    public function testRoutesPointToExistingActions(): void
    {
        foreach ($this->config()->array('routes') as [$method, $path, [$class, $action]]) {
            $this->assertTrue(in_array($method, ['GET', 'POST'], true));
            $this->assertTrue(method_exists($class, $action), "{$method} {$path}: нет {$class}::{$action}");
        }
    }

    public function testLinkStrategyRegexesCompile(): void
    {
        foreach ($this->config()->array('pipeline.link_strategies') as $strategy) {
            foreach (array_merge($strategy['hosts'], $strategy['paths']) as $regex) {
                $this->assertTrue(@preg_match($regex, '') !== false, "Некорректный regex ссылок: {$regex}");
            }
        }
    }
}
