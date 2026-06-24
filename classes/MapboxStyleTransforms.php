<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

use RuntimeException;

class MapboxStyleTransforms
{
    /**
     * @param array<string, mixed> $styleCfg
     */
    public static function apply(array $style, array $styleCfg): array
    {
        foreach (($styleCfg['transforms'] ?? []) as $transform) {
            if (!is_array($transform)) {
                continue;
            }

            $style = match ($transform['op'] ?? null) {
                'removeLayersById' => static::removeLayersById($style, $transform['ids'] ?? []),
                'removeLayersByIdContains' => static::removeLayersByIdContains($style, (string)($transform['contains'] ?? '')),
                'removeLayersByIdPrefixExcept' => static::removeLayersByIdPrefixExcept(
                    $style,
                    $transform['prefixes'] ?? [],
                    $transform['except'] ?? []
                ),
                default => throw new RuntimeException('Unsupported style transform: "' . (string)($transform['op'] ?? '') . '"'),
            };
        }

        return $style;
    }

    public static function removeLayersById(array $style, array $ids): array
    {
        if (empty($style['layers']) || !is_array($style['layers'])) {
            return $style;
        }

        $ids = array_flip($ids);
        $style['layers'] = array_values(array_filter(
            $style['layers'],
            static fn ($layer) => !is_array($layer) || !isset($ids[$layer['id'] ?? null])
        ));

        return $style;
    }

    public static function removeLayersByIdContains(array $style, string $contains): array
    {
        if ($contains === '' || empty($style['layers']) || !is_array($style['layers'])) {
            return $style;
        }

        $style['layers'] = array_values(array_filter(
            $style['layers'],
            static fn ($layer) => !is_array($layer)
                || !is_string($layer['id'] ?? null)
                || !str_contains($layer['id'], $contains)
        ));

        return $style;
    }

    public static function removeLayersByIdPrefixExcept(array $style, array $prefixes, array $except): array
    {
        if (empty($prefixes) || empty($style['layers']) || !is_array($style['layers'])) {
            return $style;
        }

        $except = array_flip(array_filter($except, 'is_string'));
        $prefixes = array_values(array_filter($prefixes, 'is_string'));
        $style['layers'] = array_values(array_filter(
            $style['layers'],
            static function ($layer) use ($prefixes, $except): bool {
                if (!is_array($layer) || !is_string($layer['id'] ?? null)) {
                    return true;
                }

                foreach ($prefixes as $prefix) {
                    if (str_starts_with($layer['id'], $prefix)) {
                        return isset($except[$layer['id']]);
                    }
                }

                return true;
            }
        ));

        return $style;
    }
}
