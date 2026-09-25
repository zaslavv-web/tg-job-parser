<?php

declare(strict_types=1);

namespace TgJobParser\Tests\Unit;

use TgJobParser\Filter\Classifier;
use TgJobParser\Kernel\App;
use TgJobParser\Model\Classification;
use TgJobParser\Model\RawPost;
use TgJobParser\Model\Source;
use TgJobParser\Profile\ProfileProvider;
use TgJobParser\Tests\AppFactory;
use TgJobParser\Tests\TestCase;

/** Сценарии из ТЗ 3.3–3.4: каждый шаг цепочки и ключевые баллы скоринга. */
final class ClassificationScenariosTest extends TestCase
{
    private App $app;

    public function setUp(): void
    {
        $this->app = AppFactory::create();
    }

    private function classify(string $text, ?Source $source = null): Classification
    {
        $source ??= new Source(1, 'telegram', 'product_jobs');

        return $this->app->get(Classifier::class)->classify(new RawPost('1', $text, 'https://t.me/product_jobs/1'), $source);
    }

    private function labels(Classification $c): string
    {
        return implode(' | ', array_map(static fn (array $r): string => $r['label'] . ' ' . ($r['points'] ?? ''), $c->reasons));
    }

    public function testIdealVacancyIsRecommended(): void
    {
        $c = $this->classify("#вакансия #remote\nHead of Product в Acme\nB2B SaaS, AI-агенты, отвечаете за P&L.\nУдалёнка, $6000–8000. Запуск с нуля.");
        $this->assertSame('recommended', $c->status, $this->labels($c));
        $this->assertSame(95, $c->score, '20+10+15+10+10+10+8+7+5 по таблице 3.4');
        $this->assertSame('Head of Product в Acme', $c->attributes['title']);
        $this->assertContains('Должность: Head of Product (целевая)', $this->labels($c));
        $this->assertContains('Удалёнка', $this->labels($c));
    }

    public function testScoreBreakdownMatchesSpec(): void
    {
        // Должность +20, домен +15 (Fintech), удалёнка +10 = 45 → «возможно интересно»
        $c = $this->classify("#вакансия\nProduct Manager\nФинтех-стартап, удалённо.");
        $this->assertSame(45, $c->score, $this->labels($c));
        $this->assertSame('maybe', $c->status);
    }

    public function testPostWithoutMarkersIsNotVacancy(): void
    {
        $c = $this->classify('Завтра вебинар про карьеру продакта, приходите!');
        $this->assertSame('not_vacancy', $c->status);
        $this->assertFalse($c->isVacancy());
    }

    public function testAntiRoleByTitleIsRejected(): void
    {
        $c = $this->classify("#вакансия\nJunior Product Manager\nУдалённо, B2B SaaS");
        $this->assertSame('rejected', $c->status);
        $this->assertContains('Анти-должность: Junior / Middle', (string) $c->rejectReason);
    }

    public function testDeveloperVacancyIsRejectedAtStep2(): void
    {
        $c = $this->classify("Ищем Backend-разработчика (Go) в продуктовую команду. Удалёнка.");
        $this->assertSame('rejected', $c->status);
        $this->assertContains('Анти-должность', (string) $c->rejectReason);
    }

    public function testTeamDescriptionDoesNotTriggerAntiRole(): void
    {
        $c = $this->classify("#вакансия\nSenior Product Manager\nВ команде 5 backend-разработчиков и QA. Удалёнка, B2B SaaS.");
        $this->assertSame('maybe', $c->status, (string) $c->rejectReason);
    }

    public function testProjectManagerWithProductContextPasses(): void
    {
        $c = $this->classify("#вакансия\nProduct / Project Manager\nУдалёнка, SaaS");
        $this->assertTrue($c->status !== 'rejected' || !str_contains((string) $c->rejectReason, 'Анти'), (string) $c->rejectReason);
    }

    public function testBlockedGeographyIsRejected(): void
    {
        $c = $this->classify("#вакансия\nProduct Owner\nОфис в Лондоне, UK");
        $this->assertSame('rejected', $c->status);
        $this->assertSame('География: Великобритания', $c->rejectReason);
    }

    public function testSaintPetersburgOfficeAllowedOnlyWithRemote(): void
    {
        $this->assertSame('rejected', $this->classify("#вакансия\nProduct Manager\nОфис Санкт-Петербург")->status);
        $c = $this->classify("#вакансия\nProduct Manager\nСанкт-Петербург или удалёнка, B2B");
        $this->assertTrue($c->status !== 'rejected' || !str_contains((string) $c->rejectReason, 'География'), (string) $c->rejectReason);
    }

    public function testHybridOutsideAllowedCitiesIsRejected(): void
    {
        $this->assertSame('География: гибрид вне допустимых городов', $this->classify("#вакансия\nProduct Manager\nГибрид, Берлин")->rejectReason);
        $this->assertTrue($this->classify("#вакансия\nHead of Product\nГибрид, Алматы. B2B SaaS, AI")->isShortlisted());
    }

    public function testMoscowOfficePenalty(): void
    {
        $c = $this->classify("#вакансия\nHead of Product\nОфис в Москве, B2B SaaS, AI");
        $this->assertContains('Офис в Москве без удалёнки -25', $this->labels($c));
    }

    public function testShortExperiencePenalty(): void
    {
        $c = $this->classify("#вакансия\nProduct Manager\nУдалёнка. Опыт от 1 года.");
        $this->assertContains('Требуемый опыт < 2 лет -20', $this->labels($c));
    }

    public function testGamblingRejectedUntilUserAllows(): void
    {
        $text = "#вакансия #remote\nHead of Product — iGaming, казино-платформа\nУдалёнка, B2B, AI";
        $c = $this->classify($text);
        $this->assertSame('Домен-исключение: iGaming', $c->rejectReason);

        $this->app->get(ProfileProvider::class)->setFlags(['allow_gambling' => true]);
        $c = $this->classify($text);
        $this->assertTrue($c->status !== 'rejected' || !str_contains((string) $c->rejectReason, 'iGaming'));
        $this->assertContains('Домен из исключений: iGaming -30', $this->labels($c));
    }

    public function testLowScoreIsRejected(): void
    {
        $c = $this->classify("#вакансия\nProduct Manager\nСтартап в сфере доставки");
        $this->assertSame('rejected', $c->status);
        $this->assertContains('Низкий скоринг', (string) $c->rejectReason);
    }

    public function testJobSiteSourceSkipsMarkerStep(): void
    {
        $source = new Source(2, 'web', 'https://orbit.example/careers', options: ['assume_vacancy' => true]);
        $c = $this->classify("Head of Product\nRemote. B2B SaaS, AI.", $source);
        $this->assertTrue($c->isShortlisted(), (string) $c->rejectReason);
    }

    public function testEnglishVacancyDetected(): void
    {
        $c = $this->classify("#hiring Senior Product Manager at Payflow\nFully remote, fintech payments, B2B. Salary $7k");
        $this->assertSame('en', $c->attributes['language']);
        $this->assertSame('Payflow', $c->attributes['company']);
        $this->assertTrue($c->isShortlisted(), (string) $c->rejectReason);
    }

    public function testUserAddedListItemChangesDecision(): void
    {
        $profiles = $this->app->get(ProfileProvider::class);
        $text = "#вакансия\nHead of Product\nГибрид, Белград. B2B SaaS, AI";
        $this->assertSame('rejected', $this->classify($text)->status);
        $profiles->addToList('allowed_hybrid_cities', 'Белград');
        $this->assertTrue($this->classify($text)->isShortlisted());
        $profiles->removeFromList('allowed_hybrid_cities', 'Белград');
        $this->assertSame('rejected', $this->classify($text)->status);
    }

    public function testTraceExplainsEveryStep(): void
    {
        $c = $this->classify("#вакансия\nHead of Product\nУдалёнка, B2B SaaS");
        $steps = array_column($c->trace, 'step');
        $this->assertSame(['vacancy_marker', 'target_role', 'anti_role', 'geography', 'excluded_domain', 'scoring'], $steps);
    }
}
