<?php

declare(strict_types=1);

namespace Nebrix\Marketing;

use Twig\Markup;

/**
 * Structured data (schema.org JSON-LD) for search engines.
 *
 * The data block is printed as <script type="application/ld+json">. Browsers
 * never execute data blocks, so the strict CSP (script-src 'self') does not
 * apply to it and needs no nonce or hash. The JSON is encoded with
 * JSON_HEX_TAG/AMP/APOS/QUOT, so no text from settings or content (product
 * names, FAQ answers …) can close the element ("</script>") or start a
 * comment ("<!--"): "<" and ">" are written as JSON unicode escapes.
 */
final class JsonLd
{
    public const FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * @param array<mixed> $data
     */
    public static function encode(array $data): string
    {
        return json_encode(self::clean($data), self::FLAGS);
    }

    /**
     * The script element with all nodes in one graph, or null without nodes.
     *
     * @param list<array<string, mixed>> $nodes
     */
    public static function script(array $nodes): ?Markup
    {
        $nodes = array_values(array_filter($nodes, static fn (array $node): bool => $node !== []));
        if ($nodes === []) {
            return null;
        }

        return new Markup('<script type="application/ld+json">' . self::encode(['@context' => 'https://schema.org', '@graph' => $nodes]) . '</script>', 'UTF-8');
    }

    /**
     * Removes empty values (null, '', []) so optional properties are left out.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function clean(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = self::clean($value);
            }
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $result[$key] = $value;
        }

        return array_is_list($data) ? array_values($result) : $result;
    }
}
