<?php

declare(strict_types=1);

namespace TgJobParser\Source;

use InvalidArgumentException;
use TgJobParser\Model\Source;

/** Реестр драйверов из config/modules.php → source_drivers. */
final class SourceRegistry
{
    /** @param array<string, SourceDriverInterface> $drivers kind → драйвер */
    public function __construct(private readonly array $drivers)
    {
    }

    public function driver(string $kind): SourceDriverInterface
    {
        return $this->drivers[$kind] ?? throw new InvalidArgumentException("Нет драйвера для источника типа «{$kind}»");
    }

    public function has(string $kind): bool
    {
        return isset($this->drivers[$kind]);
    }

    /** @return array<string, SourceDriverInterface> */
    public function all(): array
    {
        return $this->drivers;
    }

    /** Создаёт описание источника: по явному типу или по первому драйверу, узнавшему ввод. */
    public function describe(string $input, ?string $kind = null, array $options = []): Source
    {
        $input = trim($input);
        if ($input === '') {
            throw new InvalidArgumentException('Укажите @username, ссылку на канал, RSS или сайт');
        }
        if ($kind !== null && $kind !== '' && $kind !== 'auto') {
            return $this->driver($kind)->describe($input, $options);
        }
        foreach ($this->drivers as $driver) {
            if ($driver->supports($input)) {
                return $driver->describe($input, $options);
            }
        }
        throw new InvalidArgumentException('Не удалось определить тип источника: ' . $input);
    }
}
