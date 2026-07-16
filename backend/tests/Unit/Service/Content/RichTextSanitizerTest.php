<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Content;

use app\service\content\RichTextSanitizer;
use PHPUnit\Framework\TestCase;

final class RichTextSanitizerTest extends TestCase
{
    private RichTextSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new RichTextSanitizer();
    }

    public function testKeepsCommonFormattingAndAssetReferences(): void
    {
        $html = '<p style="text-align: center; position: fixed">Hello <strong>World</strong>'
            . '<img src="/uploads/a.jpg" data-asset-id="12" width="320"></p>';

        $sanitized = $this->sanitizer->sanitize($html);

        self::assertStringContainsString('<p style="text-align: center">', $sanitized);
        self::assertStringContainsString('<strong>World</strong>', $sanitized);
        self::assertStringContainsString('src="/uploads/a.jpg"', $sanitized);
        self::assertStringContainsString('data-asset-id="12"', $sanitized);
    }

    public function testRemovesExecutableTagsHandlersAndDangerousProtocols(): void
    {
        $html = '<script>alert(1)</script><svg><script>alert(2)</script></svg>'
            . '<img src=javascript:alert(3) onerror=alert(4)>'
            . '<a href="java&#x0A;script:alert(5)" onclick="alert(6)">link</a>';

        $sanitized = $this->sanitizer->sanitize($html);

        self::assertStringNotContainsStringIgnoringCase('script', $sanitized);
        self::assertStringNotContainsStringIgnoringCase('javascript:', $sanitized);
        self::assertStringNotContainsStringIgnoringCase('onerror', $sanitized);
        self::assertStringNotContainsStringIgnoringCase('onclick', $sanitized);
        self::assertSame('<img><a>link</a>', $sanitized);
    }

    public function testAddsReverseTabnabbingProtectionToBlankLinks(): void
    {
        $sanitized = $this->sanitizer->sanitize(
            '<a href="https://example.com" target="_blank" rel="nofollow">link</a>'
        );

        self::assertStringContainsString('rel="nofollow noopener noreferrer"', $sanitized);
    }
}
