<?php

declare(strict_types=1);

namespace TgJobParser\Application;

use InvalidArgumentException;
use TgJobParser\Model\Source;
use TgJobParser\Repository\SourceRepository;
use TgJobParser\Source\SourceRegistry;

/** Управление источниками (ТЗ 3.1 + раздел 6). */
final class SourceService
{
    public function __construct(
        private readonly SourceRepository $sources,
        private readonly SourceRegistry $registry,
    ) {
    }

    /** @param array<string, mixed> $options */
    public function add(string $input, ?string $kind = null, array $options = []): Source
    {
        $described = $this->registry->describe($input, $kind, $options);
        if ($this->sources->findByHandle($described->kind, $described->handle) !== null) {
            throw new InvalidArgumentException("Источник уже подключён: {$described->handle}");
        }

        return $this->sources->create($described);
    }

    public function remove(int $id): void
    {
        $this->sources->delete($id);
    }

    public function toggle(int $id): void
    {
        $source = $this->sources->find($id) ?? throw new InvalidArgumentException('Источник не найден');
        $this->sources->setActive($id, !$source->isActive);
    }
}
