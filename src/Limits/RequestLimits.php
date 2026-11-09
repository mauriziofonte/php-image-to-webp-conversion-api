<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Limits;

use Mfonte\ImageConversionApi\Exception\RequestException;
use Mfonte\ImageConversionApi\Http\InputReader;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Symfony\Component\HttpFoundation\Request;

/**
 * The size limit of the body of a conversion request (MAX_BODY_BYTES),
 * checked on the declared Content-Length before anything reads the body.
 *
 * A multipart/form-data body larger than PHP's post_max_size is refused
 * as well: PHP drops such a body before the script runs and leaves $_FILES
 * and $_POST empty, which would otherwise look like a request without an
 * image. A body without Content-Length (chunked) is bounded while it is
 * read: by the image size limit for a raw body (InputReader), by
 * post_max_size for a multipart body, which PHP reads itself.
 */
final class RequestLimits
{
    /**
     * @var int
     */
    private $maxBodyBytes;

    /**
     * @var int
     */
    private $postMaxBytes;

    /**
     * @param int         $maxBodyBytes MAX_BODY_BYTES, at least 1
     * @param string|null $postMaxSize  PHP's post_max_size in php.ini notation ("8M"); ini_get('post_max_size')
     *                                  when null
     */
    public function __construct(int $maxBodyBytes, ?string $postMaxSize = null)
    {
        $this->maxBodyBytes = max(1, $maxBodyBytes);
        $this->postMaxBytes = self::parseIniBytes($postMaxSize ?? (string) ini_get('post_max_size'));
    }

    public function getMaxBodyBytes(): int
    {
        return $this->maxBodyBytes;
    }

    /**
     * @throws RequestException 413 body_too_large when the declared body is larger than MAX_BODY_BYTES, or is a
     *                          multipart body larger than post_max_size
     */
    public function assertBodyAllowed(Request $request): void
    {
        $length = self::declaredLength($request);
        if ($length === null) {
            return;
        }
        if ($length > $this->maxBodyBytes || (InputReader::isMultipart($request) && $length > $this->postMaxBytes)) {
            throw new RequestException(413, ResponseFactory::ERROR_BODY_TOO_LARGE, 'The request body is larger than the service accepts.');
        }
    }

    /**
     * A size in php.ini notation, in bytes: digits with an optional K, M or
     * G suffix (case-insensitive, powers of 1024); 0 or a value PHP would
     * not understand means no limit.
     */
    public static function parseIniBytes(string $value): int
    {
        if (preg_match('/\A\s*([0-9]{1,15})\s*([kmg]?)\s*\z/i', $value, $match) !== 1 || (int) $match[1] === 0) {
            return PHP_INT_MAX;
        }
        $shift = ['' => 0, 'k' => 10, 'm' => 20, 'g' => 30][strtolower($match[2])];
        $bytes = (int) $match[1];

        return $bytes > (PHP_INT_MAX >> $shift) ? PHP_INT_MAX : $bytes << $shift;
    }

    /**
     * The Content-Length of the request; null when it has none or one that
     * is not a number (a chunked body).
     */
    private static function declaredLength(Request $request): ?int
    {
        $header = $request->headers->get('Content-Length');
        if ($header === null || preg_match('/\A\s*([0-9]{1,18})\s*\z/', $header, $match) !== 1) {
            return null;
        }

        return (int) $match[1];
    }
}
