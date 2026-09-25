<?php

declare(strict_types=1);

namespace TgJobParser\Tests\Integration;

use TgJobParser\Database\Database;
use TgJobParser\Database\Migrator;
use TgJobParser\Filter\Classifier;
use TgJobParser\Filter\FilterStepInterface;
use TgJobParser\Filter\StepResult;
use TgJobParser\Filter\VacancyContext;
use TgJobParser\Kernel\EventDispatcher;
use TgJobParser\Kernel\MemoryLogger;
use TgJobParser\Model\RawPost;
use TgJobParser\Model\Source;
use TgJobParser\Tests\AppFactory;
use TgJobParser\Tests\TestCase;

final class ResilienceTest extends TestCase
{
    public function testMigrationsAreIdempotent(): void
    {
        $app = AppFactory::create();
        $this->assertSame([], $app->get(Migrator::class)->migrate());
        $this->assertSame([], $app->get(Migrator::class)->status());
    }

    public function testBrokenFilterStepDoesNotStopPipeline(): void
    {
        $app = AppFactory::create(null, [
            'modules.filter_steps.exploding' => ExplodingStep::class,
            'pipeline.steps' => [
                ['id' => 'vacancy_marker'],
                ['id' => 'exploding', 'on_error' => 'continue'],
                ['id' => 'target_role'],
                ['id' => 'scoring', 'on_error' => 'reject'],
            ],
        ]);
        $c = $app->get(Classifier::class)->classify(new RawPost('1', "#вакансия\nHead of Product\nУдалёнка, B2B SaaS, AI"), new Source(1, 'telegram', 'x'));
        $this->assertTrue($c->isShortlisted(), 'шаг с on_error=continue пропущен');
        $this->assertSame('error', $c->trace[1]['decision']);
    }

    public function testBrokenStepWithRejectPolicyRejectsSafely(): void
    {
        $app = AppFactory::create(null, [
            'modules.filter_steps.exploding' => ExplodingStep::class,
            'pipeline.steps' => [['id' => 'exploding', 'on_error' => 'reject']],
        ]);
        $c = $app->get(Classifier::class)->classify(new RawPost('1', 'x'), new Source(1, 'telegram', 'x'));
        $this->assertSame('rejected', $c->status);
    }

    public function testFailingListenerIsIsolated(): void
    {
        $logger = new MemoryLogger();
        $events = new EventDispatcher($logger);
        $called = false;
        $events->listen('e', static function (): void {
            throw new \RuntimeException('boom');
        });
        $events->listen('e', static function () use (&$called): void {
            $called = true;
        });
        $events->dispatch('e');
        $this->assertTrue($called);
        $this->assertSame('error', $logger->records[0]['level']);
    }

    public function testNewPipelineStepCanBePluggedWithoutCoreChanges(): void
    {
        $app = AppFactory::create(null, [
            'modules.filter_steps.no_crypto' => NoCryptoStep::class,
            'pipeline.steps' => [['id' => 'vacancy_marker'], ['id' => 'no_crypto'], ['id' => 'scoring']],
        ]);
        $c = $app->get(Classifier::class)->classify(new RawPost('1', "#вакансия Head of Product в крипто-бирже, удалёнка"), new Source(1, 'telegram', 'x'));
        $this->assertSame('Крипта', $c->rejectReason);
    }

    public function testNewScoringRuleIsPureConfig(): void
    {
        $app = AppFactory::create(null, [
            'pipeline.scoring_rules' => [['id' => 'equity', 'type' => 'keywords', 'points' => 50, 'label' => 'Опцион', 'patterns' => ['опцион*', 'equity']]],
            'pipeline.steps' => [['id' => 'scoring']],
        ]);
        $c = $app->get(Classifier::class)->classify(new RawPost('1', 'Дадим опцион'), new Source(1, 'telegram', 'x'));
        $this->assertSame(50, $c->score);
    }

    public function testSettingsTableStoresJson(): void
    {
        $app = AppFactory::create();
        $repo = $app->get(\TgJobParser\Repository\SettingsRepository::class);
        $repo->set('a', ['x' => 1]);
        $repo->set('a', ['x' => 2]);
        $this->assertSame(['x' => 2], $repo->get('a'));
        $this->assertSame(1, (int) $app->get(Database::class)->scalar('SELECT COUNT(*) FROM settings'));
    }
}

final class ExplodingStep implements FilterStepInterface
{
    public function process(VacancyContext $context): StepResult
    {
        throw new \LogicException('Шаг сломан');
    }
}

final class NoCryptoStep implements FilterStepInterface
{
    public function process(VacancyContext $context): StepResult
    {
        return $context->matchesPatterns(['крипт*', 'crypto']) ? StepResult::reject('Крипта') : StepResult::pass();
    }
}
