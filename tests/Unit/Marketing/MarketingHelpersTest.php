<?php

declare(strict_types=1);

namespace Nebrix\Tests\Unit\Marketing;

use Nebrix\Core\Config;
use Nebrix\Http\HttpException;
use Nebrix\Http\Router;
use Nebrix\Marketing\IpAddress;
use Nebrix\Marketing\JsonLd;
use Nebrix\Marketing\Markdown;
use Nebrix\Marketing\MediaStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Safe Markdown, JSON-LD encoding, IP shortening, image uploads and
 * fallback routes.
 */
final class MarketingHelpersTest extends TestCase
{
    public function testMarkdownEscapesAllHtmlAndOnlyAllowsSafeLinks(): void
    {
        $html = Markdown::toHtml(implode("\n", [
            '<script>alert(1)</script> <img src=x onerror=alert(1)> <a href="javascript:alert(1)">x</a>',
            '[js](javascript:alert(1)) [data](data:text/html;base64,PHNjcmlwdD4=) [proto](//evil.example) [slash](/\\evil.example)',
            '[quote](https://example.com/"onmouseover="alert(1)) [vb](vbscript:msgbox) [space](java script:x)',
            '**<b>bold</b>** `<i>code</i>`',
        ]), 'https://nebrix.test');

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('<i>', $html);
        self::assertStringNotContainsString('href="javascript', $html);
        self::assertStringNotContainsString('href="data:', $html);
        self::assertStringNotContainsString('href="//', $html);
        self::assertStringNotContainsString('href="/\\', $html);
        self::assertStringNotContainsString('onmouseover="', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('<strong>&lt;b&gt;bold&lt;/b&gt;</strong>', $html);
        self::assertStringContainsString('<code>&lt;i&gt;code&lt;/i&gt;</code>', $html);
        // Only the tags of the renderer remain.
        preg_match_all('/<\/?([a-z0-9]+)/', $html, $tags);
        self::assertSame([], array_diff(array_unique($tags[1]), ['p', 'br', 'strong', 'em', 'code', 'a', 'pre', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote', 'hr']));
    }

    public function testMarkdownFeaturesAndLinkTypes(): void
    {
        $html = Markdown::toHtml("# Title\n### Sub\nText with *em* and _em_ and **strong**.\nNext line\n\n- one\n- two\n\n1. first\n\n> quote\n\n```\n<code>\n```\n---\n[ext](https://example.com/a?b=1&c=2) [own](https://nebrix.test/x) [rel](/products/kvm) [mail](mailto:info@example.com) [anchor](#faq)", 'https://nebrix.test');

        self::assertStringContainsString('<h2>Title</h2>', $html);
        self::assertStringContainsString('<h3>Sub</h3>', $html);
        self::assertStringContainsString('<p>Text with <em>em</em> and <em>em</em> and <strong>strong</strong>.<br>Next line</p>', $html);
        self::assertStringContainsString('<ul><li>one</li><li>two</li></ul>', $html);
        self::assertStringContainsString('<ol><li>first</li></ol>', $html);
        self::assertStringContainsString('<blockquote><p>quote</p></blockquote>', $html);
        self::assertStringContainsString('<pre><code>&lt;code&gt;</code></pre>', $html);
        self::assertStringContainsString('<hr>', $html);
        self::assertStringContainsString('<a href="https://example.com/a?b=1&amp;c=2" rel="nofollow noopener">ext</a>', $html, 'External links: nofollow noopener.');
        self::assertStringContainsString('<a href="https://nebrix.test/x">own</a>', $html);
        self::assertStringContainsString('<a href="/products/kvm">rel</a>', $html);
        self::assertStringContainsString('<a href="mailto:info@example.com">mail</a>', $html);
        self::assertStringContainsString('<a href="#faq">anchor</a>', $html);
        // E-mails and feeds get absolute links.
        self::assertStringContainsString('<a href="https://nebrix.test/products/kvm">rel</a>', Markdown::toHtml('[rel](/products/kvm)', 'https://nebrix.test', true));
        self::assertSame('Title Sub Text with em…', Markdown::toPlainText("# Title\n## Sub\nText with *em* and more", 25));
    }

    public function testJsonLdCannotCloseTheScriptElement(): void
    {
        $script = (string) JsonLd::script([['@type' => 'Product', 'name' => '</script><script>alert(1)</script><!--', 'description' => "Tom's & \"Jerry\"", 'empty' => '', 'none' => null]]);

        self::assertStringStartsWith('<script type="application/ld+json">{"@context":"https://schema.org","@graph":[', $script);
        self::assertSame(1, substr_count($script, '</script>'), 'Only the closing tag of the element itself.');
        self::assertStringNotContainsString('<!--', $script);
        $escaped = static fn (string $text): string => trim(json_encode($text, JsonLd::FLAGS), '"');
        self::assertStringContainsString($escaped('</script><script>'), $script);
        self::assertStringStartsWith('\\', $escaped('<'), 'The tag characters are written as JSON escapes.');
        self::assertStringContainsString($escaped("Tom's & \"Jerry\""), $script);
        self::assertStringNotContainsString("Tom's", $script);
        self::assertStringNotContainsString('"empty"', $script, 'Empty values are left out.');
        $json = substr($script, strlen('<script type="application/ld+json">'), -strlen('</script>'));
        self::assertSame('</script><script>alert(1)</script><!--', json_decode($json, true)['@graph'][0]['name']);
        self::assertNull(JsonLd::script([[]]));
    }

    public function testIpAddressesAreShortened(): void
    {
        self::assertSame('203.0.113.0', IpAddress::truncate('203.0.113.77'));
        self::assertSame('2001:db8:abcd::', IpAddress::truncate('2001:db8:abcd:12:34::1'));
        self::assertSame('', IpAddress::truncate('not-an-ip'));
    }

    public function testMediaStoreAcceptsOnlyRealPngAndJpegImages(): void
    {
        $dir = sys_get_temp_dir() . '/nebrix-media-' . bin2hex(random_bytes(4));
        $store = new MediaStore(new Config([]), $dir, false);
        $file = static function (string $content): array {
            $path = (string) tempnam(sys_get_temp_dir(), 'upl');
            file_put_contents($path, $content);

            return ['tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'name' => 'image.png', 'size' => strlen($content)];
        };
        try {
            $name = $store->store($file((string) base64_decode(self::PNG, true)));
            self::assertMatchesRegularExpression(MediaStore::NAME_PATTERN, $name);
            self::assertStringEndsWith('.png', $name);
            self::assertNotNull($store->path($name));
            self::assertNull($store->path('../../etc/passwd'));

            foreach (['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', '<html><script>alert(1)</script></html>', "GIF89a\x01\x00\x01\x00"] as $content) {
                try {
                    $store->store($file($content));
                    self::fail('Accepted a file that is no PNG/JPEG.');
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString('PNG or JPEG', $exception->getMessage());
                }
            }
            try {
                $store->store($file("\x89PNG\r\n\x1a\nnot really"));
                self::fail('Accepted a broken PNG.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
            $store->delete($name);
            self::assertNull($store->path($name));
        } finally {
            foreach (glob($dir . '/*') ?: [] as $leftover) {
                unlink($leftover);
            }
            @rmdir($dir);
        }
    }

    public function testFallbackRoutesAreMatchedAfterAllOthers(): void
    {
        $router = new Router();
        $router->get('/{slug:(?!api$)[a-z0-9]+(?:-[a-z0-9]+)*}', [self::class, 'landing'], ['fallback' => true]);
        $router->get('/about', [self::class, 'about']);
        $router->post('/contact', [self::class, 'contact']);

        self::assertSame('about', $router->match('GET', '/about')[0]->handler[1], 'A later static route wins.');
        [$route, $params] = $router->match('GET', '/minecraft-server-mieten');
        self::assertTrue($router->isFallback($route));
        self::assertSame(['slug' => 'minecraft-server-mieten'], $params);
        try {
            $router->match('GET', '/contact');
            self::fail('A GET to a POST-only address must stay 405.');
        } catch (HttpException $exception) {
            self::assertSame(405, $exception->statusCode());
        }
        try {
            $router->match('POST', '/unknown');
            self::fail('Unknown addresses stay 404 for other methods.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->statusCode());
        }
        try {
            $router->match('GET', '/api');
            self::fail('/api is never a landing page.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->statusCode());
        }
    }

    /** 1×1 pixel PNG. */
    public const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
}
