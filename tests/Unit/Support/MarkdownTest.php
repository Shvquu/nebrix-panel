<?php

declare(strict_types=1);

namespace Nebrix\Tests\Unit\Support;

use Nebrix\Support\HelpCenter;
use Nebrix\Support\Markdown;
use PHPUnit\Framework\TestCase;

final class MarkdownTest extends TestCase
{
    public function testRendersTheSupportedSubset(): void
    {
        $html = Markdown::render("# Title\n\nSome **bold** and *italic* text with `code <b>`.\nSecond line.\n\n- one\n- two\n\n1. first\n2. second\n\n> quoted\n\n```\n<?php echo 1; ?>\n```\n\n---");
        self::assertSame(
            "<h2>Title</h2>\n<p>Some <strong>bold</strong> and <em>italic</em> text with <code>code &lt;b&gt;</code>.<br>\nSecond line.</p>\n<ul><li>one</li><li>two</li></ul>\n<ol><li>first</li><li>second</li></ol>\n<blockquote><p>quoted</p></blockquote>\n<pre><code>&lt;?php echo 1; ?&gt;</code></pre>\n<hr>",
            $html,
        );
    }

    public function testEscapesHtmlAndOnlyAllowsSafeLinks(): void
    {
        $html = Markdown::render('<img src=x onerror=alert(1)> [a](javascript:alert(1)) [b](data:text/html,x) [c](//evil.test) [d](https://ok.test/?a=1&b=2) [q](https://x.test/"onmouseover=alert) [e](/tickets/create) [f](mailto:support@example.test) [**g**](vbscript:x)');
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        foreach (['javascript:', 'data:', '//evil.test', 'vbscript:'] as $bad) {
            self::assertStringNotContainsString('href="' . $bad, $html);
        }
        self::assertStringNotContainsString('onmouseover="', $html);
        self::assertStringNotContainsString('href="https://x.test', $html, 'Quotes are not allowed in URLs.');
        self::assertStringContainsString('<a href="https://ok.test/?a=1&amp;b=2" rel="nofollow noopener">d</a>', $html);
        self::assertStringContainsString('<a href="/tickets/create">e</a>', $html, 'Same-site paths, no rel needed.');
        self::assertStringContainsString('<a href="mailto:support@example.test">f</a>', $html);
        self::assertStringContainsString('<strong>g</strong>', $html, 'An unsafe link keeps its text.');
        self::assertSame('Title Some bold text link', Markdown::toText("# Title\n\nSome **bold** text [link](https://x.test)"));
    }

    public function testSlugsAndSearchTerms(): void
    {
        self::assertSame('ueber-unsere-server-faq', HelpCenter::slugify('Über unsere Server – FAQ!'));
        self::assertSame('article', HelpCenter::slugify('!!!'));
        self::assertSame(['reset', 'password', 'vps'], HelpCenter::terms('How can I reset the password of my VPS?'));
        self::assertSame(['passwort', 'zurücksetzen'], HelpCenter::terms('Wie kann ich das Passwort zurücksetzen'));
        self::assertSame([], HelpCenter::terms('% _ ?'));
    }
}
