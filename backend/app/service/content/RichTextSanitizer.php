<?php

declare(strict_types=1);

namespace app\service\content;

final class RichTextSanitizer
{
    private const ALLOWED_TAGS = [
        'a', 'blockquote', 'br', 'code', 'div', 'em',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'img',
        'li', 'ol', 'p', 'pre', 'span', 'strong',
        'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr',
        'u', 'ul',
    ];

    private const VOID_TAGS = ['br', 'img'];

    private const GLOBAL_ATTRIBUTES = ['class', 'style', 'title'];

    private const TAG_ATTRIBUTES = [
        'a' => ['href', 'rel', 'target'],
        'img' => ['alt', 'data-asset-id', 'height', 'src', 'width'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan'],
    ];

    private const ALLOWED_STYLE_PROPERTIES = [
        'background-color', 'border', 'border-color', 'border-style', 'border-width',
        'color', 'font-family', 'font-size', 'font-style', 'font-weight',
        'height', 'letter-spacing', 'line-height', 'margin', 'margin-bottom',
        'margin-left', 'margin-right', 'margin-top', 'max-width', 'min-width',
        'padding', 'padding-bottom', 'padding-left', 'padding-right', 'padding-top',
        'text-align', 'text-decoration', 'vertical-align', 'white-space', 'width',
        'word-break',
    ];

    public function sanitize(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $html = str_replace("\0", '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

        do {
            $previous = $html;
            $html = preg_replace(
                '#<(script|style|iframe|object|embed|svg|math)\b[^>]*>.*?</\1\s*>#is',
                '',
                $html
            ) ?? $html;
        } while ($html !== $previous);

        $html = strip_tags($html, self::ALLOWED_TAGS);
        $html = preg_replace_callback(
            '#<\s*(/?)\s*([a-z][a-z0-9]*)\b([^>]*)>#i',
            fn (array $matches): string => $this->sanitizeTag($matches),
            $html
        ) ?? '';

        return trim($html);
    }

    /**
     * @param array<int, string> $matches
     */
    private function sanitizeTag(array $matches): string
    {
        $tag = strtolower((string) ($matches[2] ?? ''));
        if (!in_array($tag, self::ALLOWED_TAGS, true)) {
            return '';
        }

        if (($matches[1] ?? '') === '/') {
            return in_array($tag, self::VOID_TAGS, true) ? '' : "</{$tag}>";
        }

        return '<' . $tag . $this->sanitizeAttributes($tag, (string) ($matches[3] ?? '')) . '>';
    }

    private function sanitizeAttributes(string $tag, string $source): string
    {
        preg_match_all(
            '/([a-zA-Z][a-zA-Z0-9:-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/',
            $source,
            $matches,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL
        );

        $allowed = array_merge(self::GLOBAL_ATTRIBUTES, self::TAG_ATTRIBUTES[$tag] ?? []);
        $attributes = [];
        foreach ($matches as $match) {
            if (!str_contains((string) ($match[0] ?? ''), '=')) {
                continue;
            }

            $name = strtolower((string) ($match[1] ?? ''));
            if (!in_array($name, $allowed, true) || array_key_exists($name, $attributes)) {
                continue;
            }

            $value = (string) ($match[2] ?? $match[3] ?? $match[4] ?? '');
            $value = $this->sanitizeAttributeValue($tag, $name, $value);
            if ($value !== null) {
                $attributes[$name] = $value;
            }
        }

        if (($attributes['target'] ?? '') === '_blank') {
            $rel = preg_split('/\s+/', (string) ($attributes['rel'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $attributes['rel'] = implode(' ', array_values(array_unique([...$rel, 'noopener', 'noreferrer'])));
        }

        $result = '';
        foreach ($attributes as $name => $value) {
            $result .= sprintf(
                ' %s="%s"',
                $name,
                htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')
            );
        }

        return $result;
    }

    private function sanitizeAttributeValue(string $tag, string $name, string $value): ?string
    {
        $value = trim($value);

        if ($name === 'href' || $name === 'src') {
            return $this->sanitizeUrl($tag, $name, $value);
        }
        if ($name === 'style') {
            return $this->sanitizeStyle($value) ?: null;
        }
        if ($name === 'class') {
            return preg_match('/^[a-zA-Z0-9 _-]{1,300}$/', $value) ? $value : null;
        }
        if ($name === 'data-asset-id') {
            return ctype_digit($value) && (int) $value > 0 ? $value : null;
        }
        if (in_array($name, ['colspan', 'height', 'rowspan', 'width'], true)) {
            return preg_match('/^\d{1,4}(?:%|px)?$/', $value) ? $value : null;
        }
        if ($name === 'target') {
            return in_array($value, ['_blank', '_self'], true) ? $value : null;
        }
        if ($name === 'rel') {
            $tokens = preg_split('/\s+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $tokens = array_values(array_intersect($tokens, ['nofollow', 'noopener', 'noreferrer', 'sponsored', 'ugc']));
            return $tokens === [] ? null : implode(' ', array_unique($tokens));
        }

        return mb_substr($value, 0, $name === 'title' ? 500 : 1000);
    }

    private function sanitizeUrl(string $tag, string $name, string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace('/[\x00-\x20\x7f]+/', '', $decoded) ?? '';
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $normalized, $matches)) {
            $scheme = strtolower((string) $matches[1]);
            $allowedSchemes = $tag === 'a' && $name === 'href'
                ? ['http', 'https', 'mailto', 'tel']
                : ['http', 'https'];
            if (!in_array($scheme, $allowedSchemes, true)) {
                return null;
            }
        }

        return mb_substr($value, 0, 4000);
    }

    private function sanitizeStyle(string $style): string
    {
        $safe = [];
        foreach (explode(';', $style) as $declaration) {
            [$property, $value] = array_pad(explode(':', $declaration, 2), 2, '');
            $property = strtolower(trim($property));
            $value = trim($value);
            if (!in_array($property, self::ALLOWED_STYLE_PROPERTIES, true) || $value === '') {
                continue;
            }
            if (preg_match('/[\\<>\x00-\x1f{}]|url\s*\(|expression\s*\(|javascript:|@import|behavior\s*:|binding\s*:/i', $value)) {
                continue;
            }

            $safe[] = $property . ': ' . mb_substr($value, 0, 300);
        }

        return implode('; ', $safe);
    }
}
