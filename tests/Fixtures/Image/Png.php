<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Fixtures\Image;

/**
 * Writes small truecolor PNG files in plain PHP (zlib only), for tests that
 * run where GD is missing, such as the official PHP images.
 */
final class Png
{
    private function __construct()
    {
    }

    /**
     * A horizontal gradient of $width x $height pixels, RGB, 8 bits.
     */
    public static function gradient(int $width, int $height): string
    {
        $raw = '';
        for ($y = 0; $y < $height; ++$y) {
            $raw .= "\0";
            for ($x = 0; $x < $width; ++$x) {
                $raw .= pack('CCC', intdiv(255 * $x, max(1, $width - 1)), intdiv(255 * $y, max(1, $height - 1)), 128);
            }
        }
        $compressed = gzcompress($raw, 9);

        return "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . self::chunk('IDAT', is_string($compressed) ? $compressed : '')
            . self::chunk('IEND', '');
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
