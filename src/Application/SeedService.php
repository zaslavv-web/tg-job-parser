<?php

declare(strict_types=1);

namespace TgJobParser\Application;

use InvalidArgumentException;
use TgJobParser\Kernel\Config;
use TgJobParser\Source\Driver\LinkedInDriver;

/**
 * Подключение рекомендуемых источников: каналы из ТЗ (app.php → seed_sources)
 * и поиски LinkedIn из профиля (profile.php → linkedin_searches). Повторный вызов безопасен.
 */
final class SeedService
{
    private const WORKPLACE = ['onsite' => '1', 'remote' => '2', 'hybrid' => '3'];
    private const EXPERIENCE = ['internship' => '1', 'entry' => '2', 'associate' => '3', 'mid-senior' => '4', 'director' => '5', 'executive' => '6'];
    private const POSTED = ['day' => 'r86400', 'week' => 'r604800', 'month' => 'r2592000'];

    public function __construct(private readonly SourceService $sources, private readonly Config $config)
    {
    }

    /** @return array{added: list<string>, skipped: list<string>} */
    public function seed(): array
    {
        $result = ['added' => [], 'skipped' => []];
        foreach ($this->recommended() as $input) {
            try {
                $source = $this->sources->add($input);
                $result['added'][] = $source->displayName();
            } catch (InvalidArgumentException $e) {
                $result['skipped'][] = $input . ' — ' . $e->getMessage();
            }
        }

        return $result;
    }

    /** @return list<string> */
    public function recommended(): array
    {
        $inputs = array_values(array_map('strval', $this->config->array('seed_sources')));
        foreach ($this->config->array('profile.linkedin_searches') as $search) {
            $inputs[] = self::linkedInUrl((array) $search);
        }

        return $inputs;
    }

    /** Описание поиска из профиля → ссылка на поиск LinkedIn. */
    public static function linkedInUrl(array $search): string
    {
        $codes = static function (string $value, array $map, string $what): string {
            $result = [];
            foreach (array_filter(array_map('trim', explode(',', $value))) as $item) {
                $result[] = $map[$item] ?? throw new InvalidArgumentException("linkedin_searches: неизвестное значение {$what} «{$item}»");
            }

            return implode(',', $result);
        };
        $params = array_filter([
            'keywords' => (string) ($search['keywords'] ?? ''),
            'location' => (string) ($search['location'] ?? ''),
            'f_WT' => $codes((string) ($search['workplace'] ?? ''), self::WORKPLACE, 'workplace'),
            'f_E' => $codes((string) ($search['experience'] ?? ''), self::EXPERIENCE, 'experience'),
            'f_TPR' => self::POSTED[(string) ($search['posted'] ?? 'week')] ?? 'r604800',
        ], static fn (string $v): bool => $v !== '');

        return LinkedInDriver::searchUrl(LinkedInDriver::searchParams(LinkedInDriver::searchUrl($params)));
    }
}
