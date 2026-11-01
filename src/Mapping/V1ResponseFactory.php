<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Mapping;

use Mfonte\ImageConversionApi\Exception\RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builds the 1.x JSON documents of POST /v1/{V1_SCRIPT_NAME}, byte for
 * byte as process.php encoded them (JSON_UNESCAPED_UNICODE and
 * JSON_UNESCAPED_SLASHES, "application/json; charset=utf-8"):
 *
 *     {"status":true,"version":"1.0.0","elapsed_time":"0.42s","response":[
 *       {...descriptor, "filename":"a.png","status":true,"orig_filesize":"1.2 MB",
 *        "new_filesize":"301.5 kB","compression_ratio":75.5,"webp_image_base64":"UklGR..."},
 *       {...descriptor, "filename":"b.txt","status":false,"error":"Unsupported file extension."}]}
 *
 *     {"status":false,"version":"1.0.0","message":"No images uploaded."}
 *
 * The images are not loaded in memory: the response holds an open handle
 * on each output and encodes it to base64 in chunks while it is sent, so
 * that the peak memory of a batch is a chunk, not the sum of its images.
 * Invalid UTF-8 in a file name or descriptor is replaced (U+FFFD), where
 * 1.x answered an empty body.
 */
final class V1ResponseFactory
{
    /**
     * The version 1.x reported, kept so that clients see the same document.
     */
    public const VERSION = '1.0.0';

    public const CONTENT_TYPE = 'application/json; charset=utf-8';

    /**
     * The key of an entry that holds the output, as an open handle.
     */
    public const IMAGE_KEY = 'webp_image_base64';

    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * Bytes read per chunk: a multiple of 3, so that the base64 of the
     * chunks is the base64 of the file.
     */
    private const CHUNK_BYTES = 196608;

    private const COMMON_HEADERS = [
        'Content-Type' => self::CONTENT_TYPE,
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
    ];

    /**
     * The document of a processed batch. $entries holds one entry per file,
     * in order: its data and, for a converted file, an open handle on the
     * output, at its start, and the size of the output. The base64 of the
     * output takes the place of the value of IMAGE_KEY, which the data
     * holds where 1.x placed it.
     *
     * @param list<array{data: array<mixed>, image: resource|null, imageBytes: int}> $entries
     * @param float                                                                  $elapsedSeconds the time spent on
     *                                                                                               the files
     * @param array<string, string>                                                  $headers        added to the
     *                                                                                               common headers
     *
     * @throws RuntimeException when the document cannot be encoded
     */
    public function batch(array $entries, float $elapsedSeconds, array $headers = []): Response
    {
        $marker = self::marker();
        $document = self::encode([
            'status' => true,
            'version' => self::VERSION,
            'elapsed_time' => round($elapsedSeconds, 2) . 's',
            'response' => $marker,
        ]);
        // Each piece is a string of JSON, or the handle of an output whose
        // base64 is written in its place.
        $outer = self::split($document, '"' . $marker . '"');
        $pieces = [$outer[0] . '['];
        $length = strlen($outer[0]) + 1;
        foreach ($entries as $index => $entry) {
            $data = $entry['data'];
            $image = $entry['image'];
            if ($image !== null) {
                $data[self::IMAGE_KEY] = $marker;
            }
            $json = ($index > 0 ? ',' : '') . self::encode($data);
            if ($image === null) {
                $pieces[] = $json;
                $length += strlen($json);
                continue;
            }
            $parts = self::split($json, $marker);
            array_push($pieces, $parts[0], $image, $parts[1]);
            $length += strlen($json) - strlen($marker) + 4 * intdiv($entry['imageBytes'] + 2, 3);
        }
        $pieces[] = ']' . $outer[1];
        $length += 1 + strlen($outer[1]);

        return new StreamedResponse(static function () use ($pieces): void {
            foreach ($pieces as $piece) {
                if (is_string($piece)) {
                    echo $piece;
                    continue;
                }
                while (!feof($piece)) {
                    $chunk = (string) fread($piece, self::CHUNK_BYTES);
                    if ($chunk === '') {
                        break;
                    }
                    echo base64_encode($chunk);
                }
                fclose($piece);
            }
        }, 200, array_merge(self::COMMON_HEADERS, $headers, ['Content-Length' => (string) $length]));
    }

    /**
     * The document of a request refused as a whole.
     *
     * @param string                $message a fixed sentence, never request data
     * @param array<string, string> $headers added to the common headers
     */
    public function error(int $status, string $message, array $headers = []): Response
    {
        $body = self::encode(['status' => false, 'version' => self::VERSION, 'message' => $message]);

        return new Response($body, $status, array_merge(self::COMMON_HEADERS, $headers));
    }

    /**
     * @param array<mixed> $data
     *
     * @throws RuntimeException when the data cannot be encoded
     */
    private static function encode(array $data): string
    {
        $json = json_encode($data, self::FLAGS);
        if (!is_string($json)) {
            throw new RuntimeException('The 1.x response cannot be encoded: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * What comes before and after the first $marker in $json.
     *
     * @throws RuntimeException when $json does not hold $marker
     *
     * @return array{string, string}
     */
    private static function split(string $json, string $marker): array
    {
        $at = strpos($json, $marker);
        if ($at === false) {
            throw new RuntimeException('The 1.x response lost its placeholder while it was encoded.');
        }

        return [substr($json, 0, $at), substr($json, $at + strlen($marker))];
    }

    /**
     * A string that stands in for a value while the document is encoded:
     * 32 hex digits, which no JSON escape produces and which a client's
     * descriptor would have to guess.
     */
    private static function marker(): string
    {
        return 'v1-image-' . bin2hex(random_bytes(16));
    }
}
