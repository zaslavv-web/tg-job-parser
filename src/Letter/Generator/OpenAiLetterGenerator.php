<?php

declare(strict_types=1);

namespace TgJobParser\Letter\Generator;

use TgJobParser\Http\HttpClientInterface;
use TgJobParser\Kernel\Config;
use TgJobParser\Letter\LetterGenerationException;
use TgJobParser\Letter\LetterRequest;
use TgJobParser\Letter\PromptBuilder;

/** AI-режим через OpenAI Chat Completions (или совместимый endpoint: OPENAI_BASE_URL). */
final class OpenAiLetterGenerator implements LetterGeneratorInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly PromptBuilder $prompts,
        private readonly HttpClientInterface $http,
    ) {
    }

    public function kind(): string
    {
        return 'ai';
    }

    public function unavailableReason(): ?string
    {
        return $this->config->get('ai.openai.api_key') ? null : 'Не задан OPENAI_API_KEY';
    }

    public function generate(LetterRequest $request): string
    {
        $prompt = $this->prompts->build($request);
        $response = $this->http->postJson(
            rtrim((string) $this->config->get('ai.openai.base_url'), '/') . '/chat/completions',
            [
                'model' => (string) $this->config->get('ai.openai.model'),
                'messages' => [
                    ['role' => 'system', 'content' => $prompt['system']],
                    ['role' => 'user', 'content' => $prompt['user']],
                ],
            ],
            ['Authorization' => 'Bearer ' . $this->config->get('ai.openai.api_key')],
        );
        if (!$response->ok()) {
            throw new LetterGenerationException('OpenAI: HTTP ' . $response->status . ' — ' . mb_substr($response->body, 0, 200));
        }
        $text = $response->json()['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new LetterGenerationException('OpenAI: пустой ответ');
        }

        return trim($text);
    }
}
