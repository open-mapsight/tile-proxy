<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\MapboxStyleTransforms;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MapboxStyleTransformsTest extends TestCase
{
    public function testSelectiveRecolourKeepsFiltersAndExpressionsIntact(): void
    {
        $style = ['layers' => [
            ['id' => 'Building_fill', 'type' => 'fill', 'source-layer' => 'Buildings', 'filter' => ['==', ['get', 'public'], true], 'paint' => ['fill-color' => '#eee', 'fill-opacity' => 0.8, 'fill-pattern' => 'hatch']],
            ['id' => 'Building_outline', 'type' => 'line', 'source-layer' => 'Buildings', 'paint' => ['line-color' => '#111']],
            ['id' => 'Park_fill', 'type' => 'fill', 'source-layer' => 'Parks', 'paint' => ['fill-color' => '#afa']],
        ]];
        $expression = ['interpolate', ['linear'], ['get', 'shade'], 0, '#00415b', 1, '#0083b4'];
        $result = MapboxStyleTransforms::apply($style, ['transforms' => [[
            'op' => 'setLayerProperties',
            'selector' => ['prefixes' => ['Building_'], 'sourceLayers' => ['Buildings'], 'types' => ['fill']],
            'properties' => ['paint' => ['fill-color' => $expression, 'fill-pattern' => null], 'minzoom' => 14],
        ]]]);
        $this->assertSame($expression, $result['layers'][0]['paint']['fill-color']);
        $this->assertSame(0.8, $result['layers'][0]['paint']['fill-opacity']);
        $this->assertArrayNotHasKey('fill-pattern', $result['layers'][0]['paint']);
        $this->assertSame($style['layers'][0]['filter'], $result['layers'][0]['filter']);
        $this->assertSame(14, $result['layers'][0]['minzoom']);
        $this->assertSame($style['layers'][1], $result['layers'][1]);
        $this->assertSame($style['layers'][2], $result['layers'][2]);
        $this->assertSame('#eee', $style['layers'][0]['paint']['fill-color']);
    }

    public function testTransformsComposeAndNullRemovesTopLevelProperty(): void
    {
        $result = MapboxStyleTransforms::apply(['layers' => [['id' => 'label', 'type' => 'symbol', 'filter' => ['has', 'name']]]], ['transforms' => [
            ['op' => 'setLayerProperties', 'properties' => ['layout' => ['visibility' => 'none']]],
            ['op' => 'setLayerProperties', 'selector' => ['ids' => ['label']], 'properties' => ['filter' => null, 'layout' => ['text-font' => ['Noto Sans Regular']]]],
        ]]);
        $this->assertArrayNotHasKey('filter', $result['layers'][0]);
        $this->assertSame(['visibility' => 'none', 'text-font' => ['Noto Sans Regular']], $result['layers'][0]['layout']);
    }

    public function testRemovingTheLastPaintPropertyDropsTheEmptyObject(): void
    {
        $result = MapboxStyleTransforms::apply(['layers' => [['id' => 'pattern', 'type' => 'fill', 'paint' => ['fill-pattern' => 'hatch']]]], ['transforms' => [
            ['op' => 'setLayerProperties', 'selector' => ['ids' => ['pattern']], 'properties' => ['paint' => ['fill-pattern' => null]]],
        ]]);
        $this->assertArrayNotHasKey('paint', $result['layers'][0]);
    }

    public function testMisspelledSelectorCannotAccidentallyRecolourEveryLayer(): void
    {
        $this->expectException(RuntimeException::class);
        MapboxStyleTransforms::apply(['layers' => []], ['transforms' => [[
            'op' => 'setLayerProperties', 'selector' => ['sourceLayer' => ['Buildings']], 'properties' => ['paint' => ['fill-color' => '#000']],
        ]]]);
    }
}
