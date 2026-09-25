<?php

declare(strict_types=1);

namespace TgJobParser\Letter;

use TgJobParser\Kernel\Config;
use TgJobParser\Kernel\LoggerInterface;
use TgJobParser\Letter\Generator\LetterGeneratorInterface;
use TgJobParser\Model\Post;
use TgJobParser\Profile\Matcher;
use TgJobParser\Profile\ProfileProvider;
use TgJobParser\Repository\LetterRepository;
use Throwable;

/**
 * Генерация письма с деградацией: выбранный режим → … → template.
 * Любая ошибка AI (ключ, сеть, лимит, «галлюцинация») даёт шаблонное письмо с пометкой причины.
 */
final class LetterService
{
    /** @param array<string, LetterGeneratorInterface> $generators id → генератор */
    public function __construct(
        private readonly array $generators,
        private readonly LetterGuard $guard,
        private readonly CaseSelector $cases,
        private readonly ProfileProvider $profiles,
        private readonly LetterRepository $letters,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param string|null $mode template | claude | openai | null (режим по умолчанию) */
    public function generateFor(Post $post, ?string $mode = null, ?string $language = null): Letter
    {
        $letter = $this->generate($this->requestFor($post, $language), $mode);
        $this->letters->save($post->id(), $letter);

        return $letter;
    }

    public function generate(LetterRequest $request, ?string $mode = null): Letter
    {
        $notes = [];
        foreach ($this->chain($mode) as $id) {
            $generator = $this->generators[$id] ?? null;
            if ($generator === null) {
                continue;
            }
            $reason = $generator->unavailableReason();
            if ($reason !== null) {
                $notes[] = "{$id}: {$reason}";
                continue;
            }
            try {
                $text = $this->guard->check($generator->generate($request), $generator->kind(), $request);

                return new Letter($text, $request->language, $id, $notes ? implode('; ', $notes) : null);
            } catch (Throwable $e) {
                $notes[] = "{$id}: {$e->getMessage()}";
                $this->logger->warning('Генератор письма не справился', ['generator' => $id, 'error' => $e->getMessage()]);
            }
        }
        throw new LetterGenerationException('Ни один генератор не сработал: ' . implode('; ', $notes));
    }

    /** @return array<string, ?string> режим → причина недоступности (null = готов) */
    public function availableModes(): array
    {
        $modes = [];
        foreach ($this->generators as $id => $generator) {
            $modes[$id] = $generator->unavailableReason();
        }

        return $modes;
    }

    public function requestFor(Post $post, ?string $language = null): LetterRequest
    {
        $profile = $this->profiles->profile();
        $text = $post->text();
        // Специфичный домен (Fintech, HR-tech) важнее общего (B2B SaaS) для фразы «почему интересно»
        $matchedDomains = Matcher::matchTerms($profile->list('priority_domains'), Matcher::normalize($text));
        usort($matchedDomains, static fn ($a, $b): int => (int) $a->meta('generic', false) <=> (int) $b->meta('generic', false));
        $domains = array_map(static fn ($t): string => $t->label, $matchedDomains);
        $requirements = array_map(
            static fn ($t): string => $t->label,
            $profile->list('letter_requirements')->terms,
        );
        $role = $post->get('title');
        $company = $post->get('company') ?: null;
        if (is_string($role) && is_string($company) && $company !== '') {
            // «Head of Product в Acme» + компания Acme → не дублируем компанию в письме
            $role = trim((string) preg_replace('/\s*(?:\bв\b|\bat\b|@|—|-|,)\s*' . preg_quote($company, '/') . '\s*$/iu', '', $role));
        }
        if (is_string($role) && mb_strlen($role) > 70) {
            $role = null;
        }

        return new LetterRequest(
            vacancyText: $text,
            language: $language ?? ((string) $post->get('language') ?: 'ru'),
            role: $role ?: null,
            company: $company,
            cases: $this->cases->select($profile, $text),
            mentionPetProject: $this->cases->shouldMentionPetProject($profile, $text),
            domains: array_values($domains),
            requirements: $requirements,
            profile: $profile,
        );
    }

    /** @return list<string> */
    private function chain(?string $mode): array
    {
        $mode = $mode ?: (string) $this->config->get('letters.mode', 'template');
        $chain = [$mode];
        if ($mode !== 'template') {
            $chain[] = 'template';
        }

        return array_values(array_unique($chain));
    }
}
