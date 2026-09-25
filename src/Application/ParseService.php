<?php

declare(strict_types=1);

namespace TgJobParser\Application;

use TgJobParser\Kernel\Clock;
use TgJobParser\Kernel\Config;
use TgJobParser\Kernel\EventDispatcher;
use TgJobParser\Kernel\LoggerInterface;
use TgJobParser\Model\Source;
use TgJobParser\Repository\ParseRunRepository;
use TgJobParser\Repository\PostRepository;
use TgJobParser\Repository\SourceRepository;
use TgJobParser\Source\FetchOptions;
use TgJobParser\Source\SourceRegistry;
use Throwable;

/**
 * Проход по источникам. Гарантии стабильности:
 *  - сбой одного источника не мешает остальным (статус «ошибка» + текст в UI);
 *  - сбой одного поста не мешает остальным постам источника;
 *  - курсор источника двигается только после успешного сохранения постов;
 *  - параллельный запуск (cron + кнопка) блокируется lock-файлом.
 */
final class ParseService
{
    public function __construct(
        private readonly SourceRepository $sources,
        private readonly PostRepository $posts,
        private readonly ParseRunRepository $runs,
        private readonly SourceRegistry $registry,
        private readonly ClassificationService $classification,
        private readonly EventDispatcher $events,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly FileLock $lock,
        private readonly Clock $clock,
    ) {
    }

    public function run(string $trigger = 'manual', ?int $onlySourceId = null): ParseReport
    {
        $report = new ParseReport();
        if (!$this->lock->acquire()) {
            $report->skipped = true;
            $report->skipReason = 'парсинг уже выполняется';

            return $report;
        }
        $runId = $this->runs->start($trigger);
        try {
            $sources = $onlySourceId !== null
                ? array_filter([$this->sources->find($onlySourceId)])
                : $this->sources->active();
            foreach ($sources as $source) {
                $this->parseSource($source, $report);
            }
        } finally {
            $this->runs->finish($runId, $report);
            $this->lock->release();
        }
        $this->events->dispatch('parse.finished', ['report' => $report, 'trigger' => $trigger]);
        $this->logger->info('Парсинг завершён', ['trigger' => $trigger, 'summary' => $report->summary()]);

        return $report;
    }

    private function parseSource(Source $source, ParseReport $report): void
    {
        try {
            $driver = $this->registry->driver($source->kind);
            $maxAge = (int) $this->config->get('parsing.max_post_age_days', 30);
            $result = $driver->fetch($source, new FetchOptions(
                maxPages: (int) $this->config->get('parsing.max_pages', 5),
                maxPagesFirstRun: (int) $this->config->get('parsing.max_pages_first_run', 3),
                notBefore: $maxAge > 0 ? $this->clock->now()->modify("-{$maxAge} days") : null,
                isKnown: fn (string $externalId): bool => $this->posts->exists((int) $source->id, $externalId),
            ));
        } catch (Throwable $e) {
            $report->sourcesFailed++;
            $report->errors[$source->displayName()] = $e->getMessage();
            $this->sources->markFailed((int) $source->id, $e->getMessage());
            $this->events->dispatch('source.failed', ['source' => $source, 'error' => $e->getMessage()]);

            return;
        }

        foreach ($result->posts as $raw) {
            try {
                $post = $this->posts->insertIfNew((int) $source->id, $raw);
                if ($post === null) {
                    continue;
                }
                $report->postsNew++;
                $classification = $this->classification->classify($post, $source, true);
                if ($classification->isShortlisted()) {
                    $report->vacanciesFound++;
                    $report->newShortlisted[] = $post->id();
                }
            } catch (Throwable $e) {
                $this->logger->error('Не удалось обработать пост', [
                    'source' => $source->handle,
                    'external_id' => $raw->externalId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $this->sources->markParsed((int) $source->id, $result->cursor, $result->title);
        $report->sourcesOk++;
    }
}
