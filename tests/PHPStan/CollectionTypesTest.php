<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The collection types the Composer package declares more precisely than Laravel does (P-206, P-207), through PHPStan's
 * type inference test kit: each `assertType()` in the data file is one case.
 */
final class CollectionTypesTest extends TypeInferenceTestCase
{
    /**
     * @return iterable<mixed>
     */
    public static function dataFileAsserts(): iterable
    {
        yield from self::gatherAssertTypes(__DIR__.'/data/collection-types.php');
    }

    #[DataProvider('dataFileAsserts')]
    public function test_collection_types(string $assertType, string $file, mixed ...$args): void
    {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__.'/collection-types.neon'];
    }
}
