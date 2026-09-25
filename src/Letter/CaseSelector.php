<?php

declare(strict_types=1);

namespace TgJobParser\Letter;

use TgJobParser\Profile\CandidateProfile;
use TgJobParser\Profile\Matcher;

/** Выбирает 2–3 самых релевантных кейса из опыта кандидата по тегам и тексту вакансии. */
final class CaseSelector
{
    /** @return list<array<string, mixed>> */
    public function select(CandidateProfile $profile, string $vacancyText, int $min = 2, int $max = 3): array
    {
        $normalized = Matcher::normalize($vacancyText);
        $scored = [];
        foreach ($profile->experience as $index => $case) {
            $hits = 0;
            foreach ((array) ($case['tags'] ?? []) as $tag) {
                if (Matcher::matchesPattern((string) $tag, $normalized)) {
                    $hits++;
                }
            }
            $scored[] = ['case' => $case, 'hits' => $hits, 'index' => $index];
        }
        usort($scored, static fn (array $a, array $b): int => [$b['hits'], $a['index']] <=> [$a['hits'], $b['index']]);

        $selected = [];
        foreach ($scored as $item) {
            if (count($selected) >= $max || ($item['hits'] === 0 && count($selected) >= $min)) {
                break;
            }
            $selected[] = $item['case'];
        }

        return $selected;
    }

    public function shouldMentionPetProject(CandidateProfile $profile, string $vacancyText): bool
    {
        $triggers = (array) ($profile->petProject['triggers'] ?? []);

        return $triggers !== [] && Matcher::matchesAny(array_map('strval', $triggers), Matcher::normalize($vacancyText)) !== null;
    }
}
