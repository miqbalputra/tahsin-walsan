<?php
use PHPUnit\Framework\TestCase;

final class DataIndukHelperTest extends TestCase
{
    public function testNormalizeMakesSafeMatchKeys(): void
    {
        self::assertSame('ahmadfauzi', dataIndukNormalize(' Ahmad Fauzi '));
        self::assertSame('08123456789', dataIndukNormalize('0812-345 6789'));
    }

    public function testConfiguredRequiresEveryServerSecret(): void
    {
        putenv('DATA_INDUK_ENABLED=true');
        putenv('DATA_INDUK_BASE_URL=https://data.example.test');
        putenv('DATA_INDUK_API_KEY=');
        putenv('DATA_INDUK_UNIT_ID=unit-1');
        self::assertFalse(dataIndukIsConfigured());
    }
}
