<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\FontsourceFonts;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FontsourceFontsTest extends TestCase
{
    public function testRejectsUnpinnedVersionsBeforeFetchingFonts(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Fontsource requires a fixed version');
        FontsourceFonts::configuration([
            'webfonts' => ['ubuntu' => ['version' => 'latest', 'faces' => ['400']]],
        ], 'ubuntu', '400');
    }

    public function testMissingConfiguredSubsetDoesNotReturnPartialCss(): void
    {
        $font = FontsourceFonts::configuration([
            'webfonts' => ['ubuntu' => [
                'version' => '5.2.8', 'faces' => ['400'], 'subsets' => ['latin', 'latin-ext'],
            ]],
        ], 'ubuntu', '400');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configured Fontsource subset is missing: latin-ext');
        FontsourceFonts::rewriteCss(
            "@font-face {font-family: 'Ubuntu'; src: url(./files/ubuntu-latin-400-normal.woff2) format('woff2');}",
            'ubuntu',
            '400',
            $font,
            '/map-assets/fonts/city/ubuntu'
        );
    }

    public function testItalicAliasPreservesUnicodeRangesAndUsesLocalRequestedWeight(): void
    {
        $font = FontsourceFonts::configuration([
            'webfonts' => ['ubuntu' => [
                'version' => '5.2.8', 'faces' => ['600-italic' => '500-italic'], 'subsets' => ['latin'],
            ]],
        ], 'ubuntu', '600-italic');
        $css = FontsourceFonts::rewriteCss(
            "@font-face {font-family: 'Ubuntu'; font-weight: 500; font-style: italic; src: url('./files/ubuntu-latin-500-italic.woff2') format('woff2'); unicode-range: U+0000-00FF;}",
            'ubuntu',
            '600-italic',
            $font,
            '/map-assets/fonts/city/ubuntu'
        );
        $this->assertStringContainsString('font-weight: 600;', $css);
        $this->assertStringContainsString('font-style: italic;', $css);
        $this->assertStringContainsString('/map-assets/fonts/city/ubuntu/latin-600-italic.woff2', $css);
        $this->assertStringContainsString('unicode-range: U+0000-00FF;', $css);
    }
}
