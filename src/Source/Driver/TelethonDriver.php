<?php

declare(strict_types=1);

namespace TgJobParser\Source\Driver;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use TgJobParser\Kernel\Config;
use TgJobParser\Model\FetchResult;
use TgJobParser\Model\RawPost;
use TgJobParser\Model\Source;
use TgJobParser\Source\FetchOptions;
use TgJobParser\Source\SourceDriverInterface;

/**
 * Вариант B из ТЗ: приватные каналы через Telethon (scripts/telethon_parser.py).
 * Скрипт общается с PHP только через JSON в stdout — его можно заменить любой реализацией.
 */
final class TelethonDriver implements SourceDriverInterface
{
    public function __construct(private readonly Config $config, private readonly ProcessRunner $runner)
    {
    }

    public function label(): string
    {
        return 'Telegram через Telethon (приватные каналы)';
    }

    public function inputHint(): string
    {
        return 'https://t.me/+invite, @username или id канала';
    }

    /** Автоопределение только для инвайт-ссылок — публичные каналы идут через t.me/s. */
    public function supports(string $input): bool
    {
        return (bool) preg_match('~^(https?://)?(t|telegram)\.me/(\+|joinchat/)[\w-]+~i', trim($input));
    }

    public function describe(string $input, array $options = []): Source
    {
        $input = trim($input);
        if ($this->supports($input)) {
            $handle = preg_replace('~^(https?://)?~i', 'https://', $input);
        } elseif (($username = TelegramPublicDriver::extractUsername($input)) !== null) {
            $handle = $username;
        } elseif (preg_match('~^-?\d{5,}$~', $input)) {
            $handle = $input;
        } else {
            throw new InvalidArgumentException('Ожидается инвайт-ссылка, @username или числовой id канала');
        }

        return new Source(null, 'telethon', (string) $handle, str_starts_with((string) $handle, 'http') ? (string) $handle : null);
    }

    public function fetch(Source $source, FetchOptions $options): FetchResult
    {
        if (($reason = $this->unavailableReason()) !== null) {
            throw new RuntimeException($reason);
        }
        $command = [
            (string) $this->config->get('telethon.python', 'python3'),
            (string) $this->config->get('telethon.script'),
            '--channel', $source->handle,
            '--limit', (string) ($options->maxPages * 20),
        ];
        if ($source->lastPostId !== null) {
            array_push($command, '--min-id', $source->lastPostId);
        }
        $result = $this->runner->run($command, (int) $this->config->get('parsing.source_timeout_seconds', 90), [
            'TG_API_ID' => (string) $this->config->get('telethon.api_id'),
            'TG_API_HASH' => (string) $this->config->get('telethon.api_hash'),
            'TG_SESSION' => (string) $this->config->get('telethon.session'),
        ]);
        $data = json_decode(trim($result['stdout']), true);
        if ($result['exit'] !== 0 || !is_array($data)) {
            $message = is_array($data) && isset($data['error']) ? (string) $data['error'] : (trim($result['stderr']) ?: 'пустой ответ');
            throw new RuntimeException('Telethon: ' . mb_substr($message, 0, 500));
        }

        return self::parseOutput($data, $source);
    }

    /** @param array<string, mixed> $data */
    public static function parseOutput(array $data, Source $source): FetchResult
    {
        $posts = [];
        foreach ((array) ($data['posts'] ?? []) as $item) {
            if (!is_array($item) || trim((string) ($item['text'] ?? '')) === '') {
                continue;
            }
            try {
                $date = isset($item['date']) ? new DateTimeImmutable((string) $item['date']) : null;
            } catch (\Exception) {
                $date = null;
            }
            $buttons = [];
            foreach ((array) ($item['buttons'] ?? []) as $button) {
                if (is_array($button) && !empty($button['url'])) {
                    $buttons[] = ['label' => (string) ($button['label'] ?? ''), 'url' => (string) $button['url']];
                }
            }
            $posts[] = new RawPost(
                externalId: (string) $item['id'],
                text: (string) $item['text'],
                url: isset($item['url']) ? (string) $item['url'] : null,
                publishedAt: $date,
                links: array_values(array_map('strval', (array) ($item['links'] ?? []))),
                buttons: $buttons,
            );
        }
        usort($posts, static fn (RawPost $a, RawPost $b): int => (int) $a->externalId <=> (int) $b->externalId);
        $cursor = $posts ? end($posts)->externalId : $source->lastPostId;

        return new FetchResult($posts, $cursor, isset($data['title']) ? (string) $data['title'] : null);
    }

    public function unavailableReason(): ?string
    {
        if (!function_exists('proc_open')) {
            return 'proc_open отключён в php.ini';
        }
        if (!$this->config->get('telethon.api_id') || !$this->config->get('telethon.api_hash')) {
            return 'Задайте TG_API_ID и TG_API_HASH (my.telegram.org) и авторизуйте сессию: python3 scripts/telethon_parser.py --login';
        }

        return null;
    }
}
