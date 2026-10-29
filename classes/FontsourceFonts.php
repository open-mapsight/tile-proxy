<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

use RuntimeException;

/** Allow-listed, version-pinned Fontsource faces for ol-mapbox-style's CSS loader. */
final class FontsourceFonts
{
    public static function configuration(array $styleCfg, string $family, string $variant): ?array
    {
        $font = $styleCfg['webfonts'][$family] ?? null;
        if (!is_array($font)) {
            return null;
        }
        $faces = $font['faces'] ?? [];
        if (!is_array($faces)) {
            throw new RuntimeException('Invalid Fontsource faces');
        }
        $upstreamVariant = array_is_list($faces)
            ? (in_array($variant, $faces, true) ? $variant : null)
            : ($faces[$variant] ?? null);
        if ($upstreamVariant === null) {
            return null;
        }
        if (!is_string($upstreamVariant) || preg_match('/^[1-9]00(?:-italic)?$/D', $upstreamVariant) !== 1) {
            throw new RuntimeException('Invalid Fontsource face');
        }
        $font['variant'] = $upstreamVariant;
        if (preg_match('/^\d+\.\d+\.\d+$/D', $font['version'] ?? '') !== 1) {
            throw new RuntimeException('Fontsource requires a fixed version');
        }
        $font['subsets'] ??= ['latin', 'latin-ext'];
        if (!is_array($font['subsets']) || $font['subsets'] === []) {
            throw new RuntimeException('Fontsource requires non-empty subsets');
        }
        foreach ($font['subsets'] as $subset) {
            if (!is_string($subset) || preg_match('/^[a-z]+(?:-[a-z]+)*$/D', $subset) !== 1) {
                throw new RuntimeException('Invalid Fontsource subset');
            }
        }

        return $font;
    }

    public static function baseUrl(string $family, array $font): string
    {
        return 'https://cdn.jsdelivr.net/npm/@fontsource/' . $family . '@' . $font['version'];
    }

    /** Keep only selected @font-face blocks, preserving their unicode ranges and using local WOFF2 URLs. */
    public static function rewriteCss(string $css, string $family, string $variant, array $font, string $publicPath): string
    {
        $weight = substr($variant, 0, 3);
        $style = str_ends_with($variant, '-italic') ? 'italic' : 'normal';
        $upstreamWeight = substr($font['variant'], 0, 3);
        $upstreamStyle = str_ends_with($font['variant'], '-italic') ? 'italic' : 'normal';
        preg_match_all('/@font-face\s*\{([^{}]*)\}/', $css, $blocks);
        $result = '';
        foreach ($font['subsets'] as $subset) {
            $filename = $family . '-' . $subset . '-' . $upstreamWeight . '-' . $upstreamStyle . '.woff2';
            $found = false;
            foreach ($blocks[1] as $block) {
                if (preg_match('~url\(\s*([\'"]?)\./files/' . preg_quote($filename, '~') . '\1\s*\)~', $block) !== 1) {
                    continue;
                }
                $src = "src: url('" . $publicPath . '/' . $subset . '-' . $weight . '-' . $style . ".woff2') format('woff2');";
                $block = preg_replace('/\bsrc\s*:[^;]*;/', $src, $block);
                $block = preg_replace('/font-weight\s*:[^;]*;/', 'font-weight: ' . $weight . ';', $block);
                $block = preg_replace('/font-style\s*:[^;]*;/', 'font-style: ' . $style . ';', $block);
                $result .= "@font-face {" . $block . "}\n";
                $found = true;
                break;
            }
            if (!$found) {
                throw new RuntimeException('Configured Fontsource subset is missing: ' . $subset);
            }
        }

        return $result;
    }
}
