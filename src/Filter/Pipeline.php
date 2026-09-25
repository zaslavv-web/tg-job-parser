<?php

declare(strict_types=1);

namespace TgJobParser\Filter;

use TgJobParser\Enricher\EnricherInterface;
use TgJobParser\Kernel\LoggerInterface;
use TgJobParser\Model\Classification;
use TgJobParser\Model\Post;
use Throwable;

/**
 * Прогоняет контекст через обогатители и шаги. Каждый шаг изолирован:
 * исключение в шаге не валит обработку, а трактуется по его политике on_error.
 */
final class Pipeline
{
    /**
     * @param array<string, EnricherInterface> $enrichers
     * @param list<array{id: string, step: FilterStepInterface, on_error: string}> $steps
     */
    public function __construct(
        private readonly array $enrichers,
        private readonly array $steps,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(VacancyContext $context): Classification
    {
        foreach ($this->enrichers as $id => $enricher) {
            try {
                $enricher->enrich($context);
            } catch (Throwable $e) {
                $context->trace(['enricher' => $id, 'error' => $e->getMessage()]);
                $this->logger->warning('Обогатитель упал', ['enricher' => $id, 'error' => $e->getMessage()]);
            }
        }

        $status = Post::STATUS_NEW;
        $score = null;
        $rejectReason = null;

        foreach ($this->steps as ['id' => $id, 'step' => $step, 'on_error' => $onError]) {
            try {
                $result = $step->process($context);
            } catch (Throwable $e) {
                $this->logger->warning('Шаг фильтра упал', ['step' => $id, 'error' => $e->getMessage()]);
                $context->trace(['step' => $id, 'decision' => 'error', 'reason' => $e->getMessage()]);
                if ($onError === 'reject') {
                    $status = Post::STATUS_REJECTED;
                    $rejectReason = "Ошибка шага «{$id}»";
                    break;
                }
                continue;
            }
            $context->trace(array_filter(['step' => $id, 'decision' => $result->decision, 'reason' => $result->reason], static fn ($v): bool => $v !== null));

            if ($result->score !== null) {
                $score = $result->score;
            }
            if (!$result->isTerminal()) {
                continue;
            }
            [$status, $rejectReason] = match ($result->decision) {
                StepResult::NOT_VACANCY => [Post::STATUS_NOT_VACANCY, $result->reason],
                StepResult::REJECT => [Post::STATUS_REJECTED, $result->reason],
                default => [(string) $result->status, null],
            };
            break;
        }

        if ($status === Post::STATUS_NEW) {
            // Конвейер без терминального шага (скоринг выключен) — вакансия прошла все фильтры
            $status = Post::STATUS_MAYBE;
        }

        return new Classification(
            status: $status,
            score: $score,
            rejectReason: $rejectReason,
            reasons: $context->reasons(),
            trace: $context->traceLog(),
            attributes: $context->attributes(),
        );
    }
}
