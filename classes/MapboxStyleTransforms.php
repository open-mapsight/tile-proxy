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
                'setLayerProperties' => static::setLayerProperties($style, $transform),
                'mapFonts' => static::mapFonts($style, $transform['fonts'] ?? []),
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

    /** Map font names in text-font stacks, literal expressions, and formatted text overrides. */
    public static function mapFonts(array $style, array $fonts): array
    {
        foreach ($fonts as $from => $to) {
            if (!is_string($from) || !is_string($to) || $to === '') {
                throw new RuntimeException('mapFonts requires a map of font names');
            }
        }
        $mapNames = static function ($value) use (&$mapNames, $fonts) {
            if (!is_array($value)) {
                return $value;
            }
            // Property/variable names and match labels are not font names.
            $op = $value[0] ?? null;
            if (in_array($op, ['get', 'has', 'var'], true)) {
                return $value;
            }
            if ($op === 'match' || $op === 'case') {
                for ($i = $op === 'match' ? 3 : 2, $count = count($value); $i < $count - 1; $i += 2) {
                    $value[$i] = $mapNames($value[$i]);
                }
                $value[count($value) - 1] = $mapNames($value[count($value) - 1]);
                return $value;
            }
            if (array_is_list($value) && array_filter($value, 'is_string') === $value) {
                return array_map(static fn (string $name): string => $fonts[$name] ?? $name, $value);
            }
            return array_map($mapNames, $value);
        };
        $visit = static function (array $value) use (&$visit, $mapNames): array {
            foreach ($value as $key => $child) {
                if ($key === 'text-font') {
                    $value[$key] = $mapNames($child);
                } elseif (is_array($child)) {
                    $value[$key] = $visit($child);
                }
            }
            return $value;
        };
        $style['layers'] = $visit($style['layers'] ?? []);

        return $style;
    }

    /**
     * Match selector fields with AND; values within a field with OR. Empty selector matches all layers.
     * Property expressions are copied verbatim, never recursively merged or evaluated by PHP.
     */
    public static function setLayerProperties(array $style, array $transform): array
    {
        $selector = $transform['selector'] ?? [];
        $properties = $transform['properties'] ?? [];
        if (!is_array($selector) || !is_array($properties)) {
            throw new RuntimeException('setLayerProperties requires selector and properties objects');
        }
        foreach ($selector as $field => $values) {
            if (!in_array($field, ['ids', 'prefixes', 'sourceLayers', 'types'], true)
                || !is_array($values) || array_filter($values, 'is_string') !== $values) {
                throw new RuntimeException('Invalid setLayerProperties selector');
            }
        }
        foreach ($properties as $field => $value) {
            if (!in_array($field, ['paint', 'layout', 'filter', 'minzoom', 'maxzoom'], true)
                || (in_array($field, ['paint', 'layout'], true) && !is_array($value))) {
                throw new RuntimeException('Invalid setLayerProperties property');
            }
        }
        foreach (($style['layers'] ?? []) as $index => $layer) {
            if (!is_array($layer)) {
                continue;
            }
            foreach ($selector as $field => $values) {
                $matches = match ($field) {
                    'ids' => in_array($layer['id'] ?? null, $values, true),
                    'types' => in_array($layer['type'] ?? null, $values, true),
                    'sourceLayers' => in_array($layer['source-layer'] ?? null, $values, true),
                    'prefixes' => count(array_filter($values, static fn (string $prefix): bool => str_starts_with((string)($layer['id'] ?? ''), $prefix))) > 0,
                };
                if (!$matches) {
                    continue 2;
                }
            }
            foreach ($properties as $field => $value) {
                if ($field === 'paint' || $field === 'layout') {
                    foreach ($value as $key => $property) {
                        if ($property === null) {
                            unset($layer[$field][$key]);
                        } else {
                            $layer[$field][$key] = $property;
                        }
                    }
                    // An empty PHP array would serialise as [], which is not a valid paint/layout object.
                    if (($layer[$field] ?? null) === []) {
                        unset($layer[$field]);
                    }
                } elseif ($value === null) {
                    unset($layer[$field]);
                } else {
                    $layer[$field] = $value;
                }
            }
            $style['layers'][$index] = $layer;
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
