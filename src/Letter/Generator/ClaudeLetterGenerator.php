<?php

declare(strict_types=1);

namespace TgJobParser\Letter\Generator;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use TgJobParser\Kernel\Config;
use TgJobParser\Letter\LetterGenerationException;
use TgJobParser\Letter\LetterRequest;
use TgJobParser\Letter\PromptBuilder;

/**
 * AI-режим через Claude API (официальный PHP SDK anthropic-ai/sdk, ставится composer-ом).
 * Нет SDK или ключа → генератор «недоступен», сервис молча работает в шаблонном режиме.
 */
final class ClaudeLetterGenerator implements LetterGeneratorInterface
{
    private ?Client $client = null;

    public function __construct(private readonly Config $config, private readonly PromptBuilder $prompts)
    {
    }

    public function kind(): string
    {
        return 'ai';
    }

    public function unavailableReason(): ?string
    {
        if (!class_exists(Client::class)) {
            return 'Не установлен SDK: composer require anthropic-ai/sdk guzzlehttp/guzzle';
        }
        if (!$this->config->get('ai.claude.api_key')) {
            return 'Не задан CLAUDE_API_KEY';
        }

        return null;
    }

    public function generate(LetterRequest $request): string
    {
        $prompt = $this->prompts->build($request);
        $this->client ??= new Client(apiKey: (string) $this->config->get('ai.claude.api_key'));

        try {
            // Серверный фолбэк на другую модель, если основная откажет по политике безопасности
            $message = $this->client->beta->messages->create(
                maxTokens: (int) $this->config->get('ai.claude.max_tokens', 4000),
                messages: [['role' => 'user', 'content' => $prompt['user']]],
                model: (string) $this->config->get('ai.claude.model', 'claude-opus-5'),
                system: $prompt['system'],
                outputConfig: ['effort' => (string) $this->config->get('ai.claude.effort', 'low')],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (AuthenticationException $e) {
            throw new LetterGenerationException('Claude: неверный API-ключ', 0, $e);
        } catch (RateLimitException $e) {
            throw new LetterGenerationException('Claude: превышен лимит запросов, попробуйте позже', 0, $e);
        } catch (APIStatusException $e) {
            throw new LetterGenerationException('Claude: ошибка API — ' . $e->getMessage(), 0, $e);
        } catch (APIConnectionException $e) {
            throw new LetterGenerationException('Claude: нет соединения с API', 0, $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new LetterGenerationException('Claude отказался генерировать письмо');
        }
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return trim($text);
    }
}
