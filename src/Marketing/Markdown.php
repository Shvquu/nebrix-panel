<?php

declare(strict_types=1);

namespace Nebrix\Marketing;

/**
 * Safe Markdown subset for landing pages, blog posts and newsletters.
 *
 * Raw HTML is never passed through: every character of the input is
 * escaped, and only the tags this renderer produces itself appear in the
 * output. Links are restricted to http(s), mailto, site-relative paths
 * ("/products") and anchors ("#faq"); everything else (javascript:, data:,
 * protocol-relative "//host", backslashes) is shown as plain text. Links to
 * other hosts get rel="nofollow noopener".
 *
 * Supported syntax: "#"/"##", "###" and "####" headings (h2, h3, h4 – the page has its
 * own h1), paragraphs (a single line break becomes <br>), "-"/"*"/"+" and
 * "1." lists, "> " quotes, fenced code blocks (```), `code`, **bold**,
 * *italic* / _italic_, [text](url) and "---" rules. Images are not
 * supported (cover images are uploaded separately).
 */
final class Markdown
{
    private const MAX_LENGTH = 200_000;

    /**
     * @param string $siteUrl  the site's own address (APP_URL): links to its host are internal
     * @param bool   $absolute make site-relative links absolute (e-mails, feeds)
     */
    public static function toHtml(string $markdown, string $siteUrl = '', bool $absolute = false): string
    {
        $text = mb_substr(str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $markdown), 0, self::MAX_LENGTH);
        // Control characters are removed; \x02/\x03 are used as placeholders below.
        $text = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $text);
        $renderer = new self(rtrim($siteUrl, '/'), $absolute);

        return $renderer->blocks(explode("\n", $text));
    }

    /** Plain text of the Markdown (for meta descriptions and feeds). */
    public static function toPlainText(string $markdown, int $limit = 300): string
    {
        $html = self::toHtml($markdown);
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>', '</h2>', '</h3>', '</h4>'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        // Cut at the last word boundary.
        $cut = mb_substr($text, 0, $limit - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $limit / 2 ? mb_substr($cut, 0, $space) : $cut) . '…';
    }

    /** @var list<string> rendered blocks */
    private array $out = [];

    /** @var list<string> lines of the open paragraph */
    private array $paragraph = [];

    /** ul or ol while a list is open */
    private ?string $listType = null;

    /** @var list<list<string>> lines per item of the open list */
    private array $listItems = [];

    /** @var list<string> lines of the open quote */
    private array $quote = [];

    private function __construct(
        private readonly string $siteUrl,
        private readonly bool $absolute,
    ) {
    }

    /**
     * @param list<string> $lines
     */
    private function blocks(array $lines): string
    {
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = rtrim($lines[$i]);

            if (preg_match('/^\s{0,3}(```|~~~)/', $line) === 1) {
                $this->flush();
                $code = [];
                for ($i++; $i < $count && preg_match('/^\s{0,3}(```|~~~)\s*$/', $lines[$i]) !== 1; $i++) {
                    $code[] = $lines[$i];
                }
                $this->out[] = '<pre><code>' . self::escape(implode("\n", $code)) . '</code></pre>';
                continue;
            }

            if (trim($line) === '') {
                $this->flush();
                continue;
            }

            if (preg_match('/^\s{0,3}>\s?(.*)$/', $line, $match) === 1) {
                if ($this->quote === []) {
                    $this->flush();
                }
                $this->quote[] = $match[1];
                continue;
            }
            if ($this->quote !== []) {
                $this->flush();
            }

            if (preg_match('/^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $match) === 1) {
                $this->flush();
                $level = max(2, min(4, strlen($match[1])));
                $this->out[] = sprintf('<h%d>%s</h%1$d>', $level, $this->inline($match[2]));
                continue;
            }

            if (preg_match('/^\s{0,3}([-*_])(\s*\1){2,}\s*$/', $line) === 1) {
                $this->flush();
                $this->out[] = '<hr>';
                continue;
            }

            $item = null;
            if (preg_match('/^\s{0,3}[-*+]\s+(.*)$/', $line, $match) === 1) {
                $item = ['ul', $match[1]];
            } elseif (preg_match('/^\s{0,3}\d{1,9}[.)]\s+(.*)$/', $line, $match) === 1) {
                $item = ['ol', $match[1]];
            }
            if ($item !== null) {
                if ($this->paragraph !== [] || ($this->listType !== null && $this->listType !== $item[0])) {
                    $this->flush();
                }
                $this->listType ??= $item[0];
                $this->listItems[] = [$item[1]];
                continue;
            }

            // Indented lines continue the current list item.
            if ($this->listType !== null && $this->listItems !== [] && preg_match('/^\s{2,}\S/', $line) === 1) {
                $this->listItems[count($this->listItems) - 1][] = trim($line);
                continue;
            }
            if ($this->listType !== null) {
                $this->flush();
            }

            $this->paragraph[] = trim($line);
        }
        $this->flush();

        return implode("\n", $this->out);
    }

    /** Closes the open paragraph, list or quote. */
    private function flush(): void
    {
        if ($this->paragraph !== []) {
            $this->out[] = '<p>' . implode('<br>', array_map($this->inline(...), $this->paragraph)) . '</p>';
            $this->paragraph = [];
        }
        if ($this->listType !== null) {
            $items = array_map(fn (array $item): string => '<li>' . implode('<br>', array_map($this->inline(...), $item)) . '</li>', $this->listItems);
            $this->out[] = '<' . $this->listType . '>' . implode('', $items) . '</' . $this->listType . '>';
            $this->listType = null;
            $this->listItems = [];
        }
        if ($this->quote !== []) {
            $parts = [];
            foreach (preg_split('/\n{2,}/', trim(implode("\n", $this->quote))) ?: [] as $part) {
                if (trim($part) !== '') {
                    $parts[] = '<p>' . implode('<br>', array_map($this->inline(...), explode("\n", trim($part)))) . '</p>';
                }
            }
            if ($parts !== []) {
                $this->out[] = '<blockquote>' . implode('', $parts) . '</blockquote>';
            }
            $this->quote = [];
        }
    }

    private function inline(string $text, bool $links = true): string
    {
        /** @var list<string> $tokens HTML fragments that are already safe */
        $tokens = [];
        $hold = static function (string $html) use (&$tokens): string {
            $tokens[] = $html;

            return "\x02" . (count($tokens) - 1) . "\x03";
        };

        // Code spans first: their content is shown literally.
        $text = (string) preg_replace_callback('/`([^`]+)`/', static fn (array $m): string => $hold('<code>' . self::escape($m[1]) . '</code>'), $text);

        if ($links) {
            $text = (string) preg_replace_callback(
                '/\[([^\[\]]+)\]\(\s*([^()\s]+)\s*\)/',
                function (array $m) use ($hold): string {
                    $label = $this->inline($m[1], false);
                    $link = $this->link($m[2]);
                    if ($link === null) {
                        return $hold($label);
                    }

                    return $hold(sprintf('<a href="%s"%s>%s</a>', self::escape($link['href']), $link['external'] ? ' rel="nofollow noopener"' : '', $label));
                },
                $text,
            );
        }

        $html = self::escape($text);
        // Emphasis never spans a tag, so the produced tags always nest properly.
        $html = (string) preg_replace('/\*\*(?=[^\s<])([^<]+?)(?<=[^\s<])\*\*/u', '<strong>$1</strong>', $html);
        $html = (string) preg_replace('/(?<![\p{L}\p{N}_])__(?=[^\s<])([^<]+?)(?<=[^\s<])__(?![\p{L}\p{N}_])/u', '<strong>$1</strong>', $html);
        $html = (string) preg_replace('/(?<!\*)\*(?=[^\s*<])([^<]+?)(?<=[^\s*<])\*(?!\*)/u', '<em>$1</em>', $html);
        $html = (string) preg_replace('/(?<![\p{L}\p{N}_])_(?=[^\s_<])([^<]+?)(?<=[^\s_<])_(?![\p{L}\p{N}_])/u', '<em>$1</em>', $html);

        // Restore the held fragments (nested tokens of link labels resolve in turn).
        for ($pass = 0; $pass < 3 && str_contains($html, "\x02"); $pass++) {
            $html = (string) preg_replace_callback('/\x02(\d+)\x03/', static fn (array $m): string => $tokens[(int) $m[1]] ?? '', $html);
        }

        return $html;
    }

    /**
     * @return array{href: string, external: bool}|null
     */
    private function link(string $url): ?array
    {
        $url = trim($url);
        if (preg_match('/[\s\\\\<>"\'`]/', $url) === 1) {
            return null;
        }
        if (preg_match('#^\#[A-Za-z0-9_-]{1,100}$#', $url) === 1) {
            return ['href' => $url, 'external' => false];
        }
        // Site-relative path; never "//host" (protocol-relative).
        if (preg_match('#^/(?!/)[A-Za-z0-9._~!$&()*+,;=:@%/?\#-]*$#', $url) === 1) {
            return ['href' => ($this->absolute && $this->siteUrl !== '' ? $this->siteUrl : '') . $url, 'external' => false];
        }
        if (preg_match('/^mailto:[^@\s]+@[A-Za-z0-9.-]+(\?[A-Za-z0-9=&%._-]*)?$/i', $url) === 1) {
            return ['href' => $url, 'external' => false];
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_USER) !== null) {
            return null;
        }
        $ownHost = strtolower((string) parse_url($this->siteUrl, PHP_URL_HOST));

        return ['href' => $url, 'external' => $ownHost === '' || $host !== $ownHost];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
