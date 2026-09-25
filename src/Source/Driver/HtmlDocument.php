<?php

declare(strict_types=1);

namespace TgJobParser\Source\Driver;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/** Утилиты разбора произвольного HTML: загрузка, текст с переносами, абсолютные ссылки, JSON-LD. */
final class HtmlDocument
{
    public readonly DOMXPath $xpath;

    public function __construct(string $html, public readonly string $baseUrl)
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->xpath = new DOMXPath($doc);
    }

    public function text(?DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'noscript', 'svg'], true)) {
                    continue;
                }
                $block = in_array($tag, ['p', 'div', 'li', 'br', 'h1', 'h2', 'h3', 'h4', 'tr', 'section', 'article', 'ul', 'ol'], true);
                // Соседние инлайн-элементы (<a>…</a><span>…</span>) разделяем пробелом
                $out .= ($block ? "\n" : '') . $this->text($child) . ($block ? "\n" : ' ');
            } else {
                $out .= $child->textContent;
            }
        }
        $out = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $out);

        return trim((string) preg_replace("/\s*\n\s*(\n\s*)+/", "\n\n", $out));
    }

    public function absolute(string $href): string
    {
        $href = trim($href);
        if ($href === '' || preg_match('~^[a-z][a-z0-9+.-]*:~i', $href)) {
            return $href;
        }
        $parts = parse_url($this->baseUrl);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($href, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        $dir = preg_replace('~/[^/]*$~', '/', $parts['path'] ?? '/');

        return $origin . $dir . $href;
    }

    /** @return list<string> href ссылок внутри узла */
    public function links(DOMNode $node): array
    {
        $links = [];
        foreach ($this->xpath->query('.//a[@href]', $node) as $a) {
            if ($a instanceof DOMElement) {
                $url = $this->absolute($a->getAttribute('href'));
                if (preg_match('~^https?://~i', $url)) {
                    $links[] = $url;
                }
            }
        }

        return array_values(array_unique($links));
    }

    /**
     * Объекты schema.org JobPosting из JSON-LD — самый надёжный источник описания вакансии на сайтах.
     *
     * @return list<array<string, mixed>>
     */
    public function jobPostings(): array
    {
        $found = [];
        foreach ($this->xpath->query('//script[@type="application/ld+json"]') as $script) {
            $data = json_decode(trim($script->textContent), true);
            if (!is_array($data)) {
                continue;
            }
            $stack = [$data];
            while ($stack) {
                $item = array_pop($stack);
                if (!is_array($item)) {
                    continue;
                }
                $type = $item['@type'] ?? null;
                if ($type === 'JobPosting' || (is_array($type) && in_array('JobPosting', $type, true))) {
                    $found[] = $item;
                    continue;
                }
                foreach ($item as $value) {
                    if (is_array($value)) {
                        $stack[] = $value;
                    }
                }
            }
        }

        return $found;
    }

    /** Текст JobPosting в формате, понятном фильтрам: заголовок, компания, локация, формат, описание. */
    public static function jobPostingText(array $job): string
    {
        $lines = [];
        $lines[] = (string) ($job['title'] ?? '');
        $org = $job['hiringOrganization']['name'] ?? null;
        if (is_string($org)) {
            $lines[] = 'Компания: ' . $org;
        }
        if (($job['jobLocationType'] ?? '') === 'TELECOMMUTE') {
            $lines[] = 'Remote';
        }
        $locations = $job['jobLocation'] ?? [];
        $locations = isset($locations['address']) ? [$locations] : (array) $locations;
        foreach ($locations as $location) {
            $address = is_array($location) ? ($location['address'] ?? []) : [];
            if (is_array($address)) {
                $lines[] = implode(', ', array_filter([
                    $address['addressLocality'] ?? null,
                    is_array($address['addressCountry'] ?? null) ? ($address['addressCountry']['name'] ?? null) : ($address['addressCountry'] ?? null),
                ], 'is_string'));
            }
        }
        $salary = $job['baseSalary']['value'] ?? null;
        if (is_array($salary)) {
            $currency = (string) ($job['baseSalary']['currency'] ?? '');
            $lines[] = 'Зарплата: ' . trim(($salary['minValue'] ?? '') . ' - ' . ($salary['maxValue'] ?? $salary['value'] ?? '') . ' ' . $currency);
        }
        $description = (string) ($job['description'] ?? '');
        $description = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $description)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines[] = trim($description);

        return trim(implode("\n", array_filter($lines, static fn ($l): bool => trim((string) $l) !== '')));
    }
}
