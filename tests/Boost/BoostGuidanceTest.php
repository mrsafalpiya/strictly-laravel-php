<?php

namespace StrictlyLaravel\Tests\Boost;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StrictlyLaravel\Boost\BoostGuidance;

/**
 * Before boost:install and boost:update, the app's boost.json lists the package, so Boost installs its guidance
 * (Problem P-146). The harness test "Boost installs the package's core guideline and every skill…" runs Boost itself.
 */
final class BoostGuidanceTest extends TestCase
{
    private string $boostJson;

    protected function setUp(): void
    {
        $this->boostJson = sys_get_temp_dir().'/strictly-boost-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->boostJson);
    }

    #[Test]
    public function it_creates_boost_json_for_boost_install(): void
    {
        self::assertTrue((new BoostGuidance($this->boostJson))->select(createConfig: true));
        self::assertSame("{\n    \"packages\": [\n        \"mrsafalpiya/strictly-laravel\"\n    ]\n}\n", file_get_contents($this->boostJson));
    }

    #[Test]
    public function it_leaves_a_missing_boost_json_to_boost_update(): void
    {
        self::assertFalse((new BoostGuidance($this->boostJson))->select(createConfig: false));
        self::assertFileDoesNotExist($this->boostJson);
    }

    #[Test]
    public function it_adds_the_package_to_the_developers_choices_once(): void
    {
        file_put_contents($this->boostJson, '{"agents": ["claude_code"], "packages": ["vendor/other"], "guidelines": true}');
        $guidance = new BoostGuidance($this->boostJson);

        self::assertTrue($guidance->select(createConfig: false));
        self::assertFalse($guidance->select(createConfig: false));
        self::assertSame(
            ['agents' => ['claude_code'], 'guidelines' => true, 'packages' => ['vendor/other', 'mrsafalpiya/strictly-laravel']],
            json_decode((string) file_get_contents($this->boostJson), true),
        );
    }

    #[Test]
    public function it_leaves_a_boost_json_boost_cannot_read_alone(): void
    {
        file_put_contents($this->boostJson, '{"agents": [');

        self::assertFalse((new BoostGuidance($this->boostJson))->select(createConfig: true));
        self::assertSame('{"agents": [', file_get_contents($this->boostJson));
    }
}
