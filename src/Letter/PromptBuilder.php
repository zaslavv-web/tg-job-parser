<?php

declare(strict_types=1);

namespace TgJobParser\Letter;

/** Промпт для AI (ТЗ, раздел 9) из шаблона templates/prompts/cover_letter.txt — правится без кода. */
final class PromptBuilder
{
    public function __construct(private readonly string $templatePath)
    {
    }

    /** @return array{system: string, user: string} */
    public function build(LetterRequest $request): array
    {
        $template = is_readable($this->templatePath) ? (string) file_get_contents($this->templatePath) : '';
        [$system, $user] = array_pad(explode('---USER---', $template, 2), 2, '');
        $profile = $request->profile;
        $language = $request->language === 'en' ? 'английский' : 'русский';
        $requirements = $request->requirements
            ? implode("\n", array_map(static fn (string $r): string => '- ' . ltrim($r, '+ '), $request->requirements))
            : '- нет';
        $vars = [
            '{language}' => $language,
            '{name}' => $profile->contact('name'),
            '{phone}' => $profile->contact('phone'),
            '{telegram}' => $profile->contact('telegram'),
            '{pet_project}' => $profile->contact('pet_project'),
            '{resume}' => $this->resume($request),
            '{vacancy}' => mb_substr($request->vacancyText, 0, 6000),
            '{requirements}' => $requirements,
            '{focus_cases}' => implode(', ', array_map(static fn (array $c): string => (string) $c['project'], $request->cases)),
        ];

        return ['system' => trim(strtr($system, $vars)), 'user' => trim(strtr($user, $vars))];
    }

    private function resume(LetterRequest $request): string
    {
        $key = $request->language === 'en' ? 'result_en' : 'result_ru';
        $lines = [];
        foreach ($request->profile->experience as $case) {
            $lines[] = sprintf('- %s: %s', $case['project'], $case[$key] ?? $case['result_ru']);
        }
        $pet = $request->profile->petProject[$request->language === 'en' ? 'text_en' : 'text_ru'] ?? '';
        if ($pet !== '') {
            $lines[] = '- Пет-проект: ' . $pet;
        }

        return implode("\n", $lines);
    }
}
