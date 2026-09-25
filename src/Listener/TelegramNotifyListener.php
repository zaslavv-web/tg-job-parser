<?php

declare(strict_types=1);

namespace TgJobParser\Listener;

use TgJobParser\Application\ParseReport;
use TgJobParser\Http\HttpClientInterface;
use TgJobParser\Kernel\Config;
use TgJobParser\Kernel\ListenerInterface;
use TgJobParser\Model\Post;
use TgJobParser\Repository\PostRepository;

/** Этап 8: после парсинга шлёт в Telegram-бот новые рекомендуемые вакансии (если заданы токен и chat_id). */
final class TelegramNotifyListener implements ListenerInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly HttpClientInterface $http,
        private readonly PostRepository $posts,
    ) {
    }

    public function handle(array $payload): void
    {
        $token = (string) $this->config->get('notify.telegram_bot_token');
        $chatId = (string) $this->config->get('notify.telegram_chat_id');
        $report = $payload['report'] ?? null;
        if ($token === '' || $chatId === '' || !$report instanceof ParseReport || $report->newShortlisted === []) {
            return;
        }
        $lines = [];
        foreach ($report->newShortlisted as $id) {
            $post = $this->posts->find($id);
            if ($post === null || $post->status() !== Post::STATUS_RECOMMENDED) {
                continue;
            }
            $lines[] = sprintf('✅ %d/100 — %s%s', (int) $post->get('score'), $post->get('title') ?: 'Вакансия', $post->get('vacancy_url') ? "\n" . $post->get('vacancy_url') : '');
        }
        if ($lines === []) {
            return;
        }
        $text = "Новые рекомендуемые вакансии:\n\n" . implode("\n\n", array_slice($lines, 0, 20));
        $this->http->postJson("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => mb_substr($text, 0, 4000),
            'disable_web_page_preview' => true,
        ]);
    }
}
