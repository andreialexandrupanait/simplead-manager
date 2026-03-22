<?php

namespace Tests\Unit\Helpers;

use App\Helpers\FormatHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FormatHelperTest extends TestCase
{
    #[Test]
    #[DataProvider('bytesProvider')]
    public function it_formats_bytes_correctly(int $bytes, string $expected): void
    {
        $this->assertSame($expected, FormatHelper::bytes($bytes));
    }

    public static function bytesProvider(): array
    {
        return [
            'zero bytes' => [0, '0 B'],
            'bytes' => [500, '500 B'],
            'kilobytes' => [1024, '1 KB'],
            'megabytes' => [1048576, '1 MB'],
            'gigabytes' => [1073741824, '1 GB'],
            'partial megabytes' => [1572864, '1.5 MB'],
        ];
    }

    #[Test]
    public function it_respects_precision_parameter(): void
    {
        // round() doesn't add trailing zeros, so precision=1 and precision=2 both give '1.5 MB'
        $this->assertSame('1.5 MB', FormatHelper::bytes(1572864, 1));
        $this->assertSame('1.5 MB', FormatHelper::bytes(1572864, 2));
        // Test with a value that actually shows different precision
        $this->assertSame('1.33 MB', FormatHelper::bytes(1398101, 2));
        $this->assertSame('1.3 MB', FormatHelper::bytes(1398101, 1));
    }
}
