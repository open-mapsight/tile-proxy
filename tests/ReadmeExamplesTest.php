<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy\Tests;

use OpenMapsight\TileProxy\Base;
use OpenMapsight\TileProxy\MetadataScope;
use OpenMapsight\TileProxy\Processor;
use OpenMapsight\TileProxy\Result;
use OpenMapsight\TileProxy\Utils;
use PHPUnit\Framework\TestCase;

class ReadmeExamplesTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        ob_start();
        $this->tempDir = sys_get_temp_dir() . '/tile_proxy_readme_test_' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        ob_end_clean();
        $files = glob($this->tempDir . '/*');
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    public function testParseJsoncExample(): void
    {
        $jsonc = <<<'JSONC'
{
    "ops": [
        {
            "cacheServerName": "source-1",
            "urls": ["https://example.com/tiles/{z}/{x}/{y}.png"],
            "mimeType": "image/png",
            "cacheBrowserTtl": 3600,
            "cacheServerTtl": 86400
        },
        {
            "op": "colorFilter",
            "filter": "reducedSaturation",
            "cacheServerName": "filter-1"
        },
        {
            "op": "imgOpt",
            "cacheServerName": "opt-1"
        }
    ]
}
JSONC;

        $config = Utils::parseJsoncString($jsonc);
        $this->assertIsArray($config);
        $this->assertArrayHasKey('ops', $config);
        $this->assertCount(3, $config['ops']);
    }

    public function testBaseRunFromJsonConfigFileUsesProcessor(): void
    {
        // Setup a dummy config file
        $jsonc = '{"ops": [{"cacheServerName": "test", "urls": ["http://test.com"], "mimeType": "image/png"}], "cacheServerPath": "/tmp/cache"}';
        $cfgPath = $this->tempDir . '/config.jsonc';
        file_put_contents($cfgPath, $jsonc);

        // Define a mock processor class
        // Needs to be in the namespace to be accessible easily or fully qualified
        $mockProcessorClass = MockProcessor::class;

        // Mock $_GET to avoid warnings in getReqArgs
        $_GET = ['x' => '0', 'y' => '0', 'z' => '0'];

        // Run
        Base::runFromJsonConfigFile($cfgPath, $mockProcessorClass);

        $this->assertTrue(MockProcessor::$called);
    }
}

class MockProcessor extends Processor
{
    public static bool $called = false;

    public static function run(array $ops, array $reqArgs, string $cachePath, MetadataScope $meta, array $upstreamHttp = []): Result
    {
        self::$called = true;
        // Return a dummy result that prevents further side effects in Base::run
        $res = new Result($reqArgs, $cachePath, $ops[0]['cacheServerName'], $meta);
        $res->mimeType = 'image/png';
        $res->setData('dummy'); // This prevents getData() returning null
        return $res;
    }
}
