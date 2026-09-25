<?php

declare(strict_types=1);

namespace TgJobParser\Tests\Unit;

use TgJobParser\Kernel\App;
use TgJobParser\Letter\Generator\LetterGeneratorInterface;
use TgJobParser\Letter\Generator\TemplateLetterGenerator;
use TgJobParser\Letter\LetterGenerationException;
use TgJobParser\Letter\LetterGuard;
use TgJobParser\Letter\LetterRequest;
use TgJobParser\Letter\LetterService;
use TgJobParser\Letter\CaseSelector;
use TgJobParser\Letter\PromptBuilder;
use TgJobParser\Profile\ProfileProvider;
use TgJobParser\Tests\AppFactory;
use TgJobParser\Tests\TestCase;

final class LetterTest extends TestCase
{
    private App $app;

    public function setUp(): void
    {
        $this->app = AppFactory::create();
    }

    private function request(string $vacancy, string $lang = 'ru', ?string $role = 'Head of Product', ?string $company = 'Acme'): LetterRequest
    {
        $profile = $this->app->get(ProfileProvider::class)->profile();
        $selector = new CaseSelector();

        return new LetterRequest($vacancy, $lang, $role, $company, $selector->select($profile, $vacancy),
            $selector->shouldMentionPetProject($profile, $vacancy), ['HR-tech'], [], $profile);
    }

    public function testTemplateLetterFollowsSpec(): void
    {
        $request = $this->request("Head of Product, HR-tech платформа (LMS, ATS), AI-агенты. Enterprise-клиенты.");
        $text = $this->app->get(LetterGuard::class)->check($this->app->get(TemplateLetterGenerator::class)->generate($request), 'template', $request);

        $this->assertContains('Здравствуйте!', $text);
        $this->assertContains('Откликаюсь на позицию Head of Product в Acme.', $text);
        $this->assertContains('HR-tech — мой домен', $text);
        $this->assertContains('РЖД', $text, 'релевантный HR-tech кейс');
        $this->assertContains('growth-peak.pro', $text, 'вакансия про AI → пет-проект');
        $this->assertContains('Владимир Заслав', $text);
        $this->assertContains('+7(926) 9882199, @VladimirZaslav', $text);
        $this->assertNotContains('Уважаемые', $text);
        $this->assertLessOrEqual(130, LetterGuard::wordCount($text));
        $cases = substr_count($text, "\n— ");
        $this->assertTrue($cases >= 2 && $cases <= 3, "2–3 кейса, получено {$cases}");
    }

    public function testEnglishTemplate(): void
    {
        $text = $this->app->get(TemplateLetterGenerator::class)->generate($this->request('Senior PM for fintech payments platform, B2B', 'en', 'Senior Product Manager', null));
        $this->assertContains("I'm applying for the Senior Product Manager role.", $text);
        $this->assertContains('СЭП', $text, 'финтех-кейс');
        $this->assertNotContains('growth-peak.pro', $text, 'не про AI — без пет-проекта');
    }

    public function testCaseSelectorUsesOnlyProfileFacts(): void
    {
        $profile = $this->app->get(ProfileProvider::class)->profile();
        $known = array_column($profile->experience, 'project');
        foreach ((new CaseSelector())->select($profile, 'маркетплейс, retention, LTV, конверсия') as $case) {
            $this->assertTrue(in_array($case['project'], $known, true));
        }
        $this->assertSame(2, count((new CaseSelector())->select($profile, 'совсем нерелевантный текст')), 'минимум 2 кейса всегда');
    }

    public function testGuardRejectsClichesInventedNumbersAndLongLetters(): void
    {
        $guard = $this->app->get(LetterGuard::class);
        $request = $this->request('Head of Product, B2B SaaS');
        $this->assertThrows(LetterGenerationException::class, fn () => $guard->check('Здравствуйте! Я идеальный кандидат.', 'ai', $request));
        $this->assertThrows(LetterGenerationException::class, fn () => $guard->check('У меня 18+ лет опыта в SaaS.', 'ai', $request));
        $this->assertThrows(LetterGenerationException::class, fn () => $guard->check('Вырастил выручку на 340% в Яндексе.', 'ai', $request));
        $this->assertThrows(LetterGenerationException::class, fn () => $guard->check(str_repeat('слово ', 210), 'ai', $request));

        $ok = $guard->check('Привет! В РЖД сделал 4 проекта на 230 млн ₽ и 30 000 MAU. Созвонимся?', 'ai', $request);
        $this->assertContains('@VladimirZaslav', $ok, 'контакты дописываются, если AI их забыл');
    }

    public function testFallsBackToTemplateWhenAiFails(): void
    {
        $failing = new class () implements LetterGeneratorInterface {
            public function unavailableReason(): ?string { return null; }
            public function kind(): string { return 'ai'; }
            public function generate(LetterRequest $request): string { throw new \RuntimeException('API недоступен'); }
        };
        $hallucinating = new class () implements LetterGeneratorInterface {
            public function unavailableReason(): ?string { return null; }
            public function kind(): string { return 'ai'; }
            public function generate(LetterRequest $request): string { return 'Я идеальный кандидат с опытом 25 лет.'; }
        };
        $service = $this->app->container->make(LetterService::class, ['generators' => [
            'template' => $this->app->get(TemplateLetterGenerator::class),
            'claude' => $failing,
            'openai' => $hallucinating,
        ]]);
        $request = $this->request('Head of Product, B2B SaaS');

        $letter = $service->generate($request, 'claude');
        $this->assertSame('template', $letter->mode);
        $this->assertContains('API недоступен', (string) $letter->note);

        $letter = $service->generate($request, 'openai');
        $this->assertSame('template', $letter->mode);
        $this->assertContains('Запрещённая формулировка', (string) $letter->note);
    }

    public function testUnavailableAiModeDegradesSilently(): void
    {
        $letter = $this->app->get(LetterService::class)->generate($this->request('Head of Product'), 'claude');
        $this->assertSame('template', $letter->mode);
        $this->assertContains('claude:', (string) $letter->note);
    }

    public function testPromptContainsResumeVacancyAndUserRequirements(): void
    {
        $profiles = $this->app->get(ProfileProvider::class);
        $profiles->addToList('letter_requirements', 'Упомянуть готовность выйти через 2 недели');
        $request = $this->app->get(LetterService::class)->requestFor(new \TgJobParser\Model\Post([
            'id' => 1, 'text' => 'Head of Product, AI-платформа', 'language' => 'ru', 'title' => 'Head of Product', 'company' => null,
        ]));
        $prompt = $this->app->get(PromptBuilder::class)->build($request);
        $this->assertContains('Используй ТОЛЬКО факты из резюме', $prompt['system']);
        $this->assertContains('Упомянуть готовность выйти через 2 недели', $prompt['system']);
        $this->assertContains('Антиплагиат: запустил MVP AI-детекции', $prompt['user']);
        $this->assertContains('ВАКАНСИЯ:', $prompt['user']);
    }

    public function testPlusRequirementGoesIntoTemplateVerbatim(): void
    {
        $this->app->get(ProfileProvider::class)->addToList('letter_requirements', '+ Готов выйти через 2 недели.');
        $request = $this->app->get(LetterService::class)->requestFor(new \TgJobParser\Model\Post(['id' => 1, 'text' => 'Head of Product', 'language' => 'ru']));
        $this->assertContains('Готов выйти через 2 недели.', $this->app->get(TemplateLetterGenerator::class)->generate($request));
    }

    public function testRoleDoesNotDuplicateCompanyAndSpecificDomainWins(): void
    {
        $request = $this->app->get(LetterService::class)->requestFor(new \TgJobParser\Model\Post([
            'id' => 1, 'text' => "Senior Product Manager в Kaspi\nFintech, платежи, B2B", 'language' => 'ru',
            'title' => 'Senior Product Manager в Kaspi', 'company' => 'Kaspi',
        ]));
        $this->assertSame('Senior Product Manager', $request->role);
        $this->assertSame('Fintech', $request->domains[0]);
        $text = $this->app->get(TemplateLetterGenerator::class)->generate($request);
        $this->assertContains('Откликаюсь на позицию Senior Product Manager в Kaspi.', $text);
        $this->assertContains('Финтех мне близок', $text);
    }
}
