<?php

declare(strict_types=1);

namespace Nebrix\Support;

/**
 * Small, safe Markdown renderer for help center articles.
 *
 * All input is HTML-escaped; only the elements generated here end up as
 * markup (headings, paragraphs, lists, block quotes, code, emphasis, rules
 * and links). Links are only created for http(s), mailto and same-site
 * paths ("/…"); external links get rel="nofollow noopener". Raw HTML in the
 * source is shown as text, never interpreted.
 */
final class Markdown
{
    private const MAX_INPUT = 200_000;

    public static function render(string $markdown): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", mb_substr($markdown, 0, self::MAX_INPUT)));
        $html = [];
        $paragraph = [];

        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = rtrim($lines[$i]);

            if (str_starts_with(ltrim($line), '```')) {
                self::flush($paragraph, $html);
                $code = [];
                for ($i++; $i < $count && !str_starts_with(ltrim($lines[$i]), '```'); $i++) {
                    $code[] = rtrim($lines[$i]);
                }
                $html[] = '<pre><code>' . self::escape(implode("\n", $code)) . '</code></pre>';
                continue;
            }
            if (trim($line) === '') {
                self::flush($paragraph, $html);
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*$/', $line, $m) === 1) {
                self::flush($paragraph, $html);
                $level = min(4, strlen($m[1]) + 1);
                $html[] = sprintf('<h%d>%s</h%d>', $level, self::inline($m[2]), $level);
                continue;
            }
            if (preg_match('/^\s{0,3}(?:-{3,}|\*{3,}|_{3,})\s*$/', $line) === 1) {
                self::flush($paragraph, $html);
                $html[] = '<hr>';
                continue;
            }
            if (preg_match('/^\s{0,3}>\s?(.*)$/', $line) === 1) {
                self::flush($paragraph, $html);
                $quote = [];
                while ($i < $count && preg_match('/^\s{0,3}>\s?(.*)$/', rtrim($lines[$i]), $q) === 1) {
                    $quote[] = $q[1];
                    $i++;
                }
                $i--;
                $html[] = '<blockquote><p>' . implode("<br>\n", array_map(self::inline(...), array_filter($quote, static fn (string $l): bool => trim($l) !== ''))) . '</p></blockquote>';
                continue;
            }
            foreach (['ul' => '/^\s{0,3}[-*+]\s+(.+)$/', 'ol' => '/^\s{0,3}\d{1,3}[.)]\s+(.+)$/'] as $tag => $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    self::flush($paragraph, $html);
                    $items = [];
                    while ($i < $count && preg_match($pattern, rtrim($lines[$i]), $item) === 1) {
                        $items[] = '<li>' . self::inline($item[1]) . '</li>';
                        $i++;
                    }
                    $i--;
                    $html[] = '<' . $tag . '>' . implode('', $items) . '</' . $tag . '>';
                    continue 2;
                }
            }
            $paragraph[] = trim($line);
        }
        self::flush($paragraph, $html);

        return implode("\n", $html);
    }

    /**
     * Closes the current paragraph.
     *
     * @param list<string> $paragraph lines of the paragraph (emptied)
     * @param list<string> $html
     */
    private static function flush(array &$paragraph, array &$html): void
    {
        if ($paragraph !== []) {
            $html[] = '<p>' . implode("<br>\n", array_map(self::inline(...), $paragraph)) . '</p>';
            $paragraph = [];
        }
    }

    /** Plain text for previews and search snippets. */
    public static function toText(string $markdown): string
    {
        $text = (string) preg_replace(['/```[a-z0-9]*\n?/i', '/\[([^\]\n]+)\]\([^)\s]+\)/', '/^\s{0,3}(#{1,6}|>|[-*+]|\d{1,3}[.)])\s+/m', '/[*`]+/'], ['', '$1', '', ''], $markdown);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function isSafeUrl(string $url): bool
    {
        return preg_match('#^(https?://[^\s<>"\'`]+|mailto:[^\s<>"\'`/]+@[^\s<>"\'`]+|/(?![/\\\\])[^\s<>"\'`]*)$#i', $url) === 1;
    }

    private static function inline(string $text): string
    {
        $out = '';
        foreach (preg_split('/(`[^`\n]+`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (strlen($part) > 1 && $part[0] === '`' && $part[strlen($part) - 1] === '`') {
                $out .= '<code>' . self::escape(substr($part, 1, -1)) . '</code>';
            } else {
                $out .= self::links($part);
            }
        }

        return $out;
    }

    private static function links(string $text): string
    {
        $out = '';
        $offset = 0;
        if (preg_match_all('/\[([^\]\n]{1,300})\]\(([^)\s]{1,2000})\)/', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches as $match) {
                [$whole, $position] = $match[0];
                $out .= self::emphasis(self::escape(substr($text, $offset, $position - $offset)));
                $label = self::emphasis(self::escape($match[1][0]));
                $url = $match[2][0];
                if (self::isSafeUrl($url)) {
                    $external = preg_match('#^https?://#i', $url) === 1;
                    $out .= sprintf('<a href="%s"%s>%s</a>', self::escape($url), $external ? ' rel="nofollow noopener"' : '', $label);
                } else {
                    // Unsafe schemes (javascript:, data:, …) are shown as plain text.
                    $out .= $label;
                }
                $offset = $position + strlen($whole);
            }
        }

        return $out . self::emphasis(self::escape(substr($text, $offset)));
    }

    /** Applied to already escaped text: only wraps it in <strong>/<em>. */
    private static function emphasis(string $escaped): string
    {
        $escaped = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $escaped);

        return (string) preg_replace('/(?<![\w*])\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?![\w*])/u', '<em>$1</em>', $escaped);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
