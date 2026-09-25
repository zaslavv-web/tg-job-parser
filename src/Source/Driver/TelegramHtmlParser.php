<?php

declare(strict_types=1);

namespace TgJobParser\Source\Driver;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use TgJobParser\Model\RawPost;

/**
 * Разбор веб-превью публичного канала https://t.me/s/{username}.
 * Устойчив к вариациям разметки: берёт текст, ссылки, дату, инлайн-кнопки, подписи к медиа.
 */
final class TelegramHtmlParser
{
    /** @return array{posts: list<RawPost>, title: ?string, oldest_id: ?int} */
    public function parse(string $html, string $username): array
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($doc);

        $title = null;
        $titleNode = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' tgme_channel_info_header_title ')]")->item(0);
        if ($titleNode !== null) {
            $title = trim($titleNode->textContent);
        }

        $posts = [];
        $oldest = null;
        foreach ($xpath->query('//*[@data-post]') as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $dataPost = $node->getAttribute('data-post');
            if (!preg_match('~/(\d+)$~', $dataPost, $m)) {
                continue;
            }
            $id = (int) $m[1];
            $oldest = $oldest === null ? $id : min($oldest, $id);

            $textParts = [];
            $links = [];
            foreach ($xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' tgme_widget_message_text ') or contains(concat(' ', normalize-space(@class), ' '), ' tgme_widget_message_caption ')]", $node) as $textNode) {
                $textParts[] = $this->textWithBreaks($textNode);
                foreach ($xpath->query('.//a[@href]', $textNode) as $a) {
                    $links[] = $this->absolute(($a instanceof DOMElement) ? $a->getAttribute('href') : '');
                }
            }
            // Карточка-превью ссылки тоже содержит полезный URL
            foreach ($xpath->query(".//a[contains(concat(' ', normalize-space(@class), ' '), ' tgme_widget_message_link_preview ')]", $node) as $a) {
                $links[] = $this->absolute(($a instanceof DOMElement) ? $a->getAttribute('href') : '');
            }
            $buttons = [];
            foreach ($xpath->query(".//a[contains(concat(' ', normalize-space(@class), ' '), ' tgme_widget_message_inline_button ')]", $node) as $a) {
                if ($a instanceof DOMElement && $a->getAttribute('href') !== '') {
                    $buttons[] = ['label' => trim($a->textContent), 'url' => $this->absolute($a->getAttribute('href'))];
                }
            }
            $publishedAt = null;
            $time = $xpath->query('.//time[@datetime]', $node)->item(0);
            if ($time instanceof DOMElement) {
                try {
                    $publishedAt = new DateTimeImmutable($time->getAttribute('datetime'));
                } catch (\Exception) {
                    $publishedAt = null;
                }
            }
            $text = trim(implode("\n\n", array_filter($textParts)));
            if ($text === '') {
                continue; // медиа без подписи, сервисные сообщения
            }
            $posts[] = new RawPost(
                externalId: (string) $id,
                text: $text,
                url: 'https://t.me/' . $username . '/' . $id,
                publishedAt: $publishedAt,
                links: array_values(array_unique(array_filter($links))),
                buttons: $buttons,
            );
        }
        usort($posts, static fn (RawPost $a, RawPost $b): int => (int) $a->externalId <=> (int) $b->externalId);

        return ['posts' => $posts, 'title' => $title, 'oldest_id' => $oldest];
    }

    private function textWithBreaks(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === 'br') {
                $out .= "\n";
            } elseif ($child instanceof DOMElement) {
                $out .= $this->textWithBreaks($child);
            } else {
                $out .= $child->textContent;
            }
        }

        return (string) preg_replace("/\n{3,}/", "\n\n", $out);
    }

    private function absolute(string $href): string
    {
        $href = trim($href);
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }
        if (str_starts_with($href, '/')) {
            return 'https://t.me' . $href;
        }

        return $href;
    }
}
