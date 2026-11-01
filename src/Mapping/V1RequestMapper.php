<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Mapping;

use Mfonte\ImageConversionApi\Http\InputReader;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidRequestException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Maps the 1.x request of POST /v1/{V1_SCRIPT_NAME} to the library, as the
 * 1.x process.php read it:
 *
 * - images: the multipart/form-data field "images[]" (a single "images"
 *   file is accepted too), with the extensions jpg, jpeg, png, gif and bmp;
 * - descriptors: an optional form field holding a JSON array of objects,
 *   each echoed in the entry of the file whose name is its "filename";
 * - query string: "m" (cwebp's method, 0 to 6, default 4) becomes the
 *   effort of the same method, "lossless" ("1" or "true") lossless
 *   encoding. The output is always WEBP at cwebp's default quality (75),
 *   metadata stripped, as cwebp produced it. The other 1.x options (pass,
 *   near_lossless, hint, jpeg_like) were cwebp switches with no
 *   counterpart in the library: they are accepted and ignored, as 1.x
 *   ignored an invalid one. Unlike /v2/convert, nothing is refused here.
 *
 * It also holds the 1.x wording that the response keeps: the sizes as
 * process.php formatted them and its upload error messages.
 */
final class V1RequestMapper
{
    /**
     * The multipart field of the images.
     */
    public const FIELD_IMAGES = 'images';

    /**
     * The form field of the descriptors.
     */
    public const FIELD_DESCRIPTORS = 'descriptors';

    /**
     * The extensions 1.x accepted, compared in lower case.
     */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'bmp'];

    /**
     * cwebp's quality when it is given none, which 1.x relied on.
     */
    public const QUALITY = 75;

    /**
     * cwebp's method when "m" is not given, as 1.x passed it.
     */
    public const DEFAULT_METHOD = 4;

    private const UPLOAD_ERRORS = [
        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive.',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive.',
        UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
    ];

    private const BYTE_UNITS = ['B', 'kB', 'MB', 'GB', 'TB'];

    /**
     * The files of the field "images", in order: a file input left empty is
     * null. Null when there is none, or when the field is not a list of
     * files.
     *
     * @return array<int, UploadedFile|null>|null
     */
    public function files(Request $request): ?array
    {
        if (!InputReader::isMultipart($request)) {
            return null;
        }
        $field = $request->files->all()[self::FIELD_IMAGES] ?? null;
        if ($field instanceof UploadedFile) {
            return [$field];
        }
        if (!is_array($field) || $field === []) {
            return null;
        }
        $files = [];
        foreach (array_values($field) as $index => $file) {
            if ($file !== null && !$file instanceof UploadedFile) {
                return null;
            }
            $files[$index] = $file;
        }

        return $files;
    }

    /**
     * The rate limit tokens a request costs: one per file it carries (empty
     * file inputs aside), and one for a request without any, which is
     * refused but still counted.
     */
    public function rateLimitCost(Request $request): int
    {
        $files = $this->files($request);

        return $files === null ? 1 : max(1, count(array_filter($files, static function (?UploadedFile $file): bool {
            return $file !== null;
        })));
    }

    /**
     * The conversion request of the query string.
     *
     * @throws InvalidRequestException never for a value computed here; declared for the library's parser
     */
    public function toConversionRequest(Request $request): ConversionRequest
    {
        $query = $request->query->all();
        $method = self::DEFAULT_METHOD;
        $m = $query['m'] ?? null;
        if (is_string($m)) {
            // 1.x cast the value with (int) and passed -m only for 0 to 6, so cwebp kept its default of 4.
            $cast = (int) $m;
            $method = $cast >= 0 && $cast <= 6 ? $cast : self::DEFAULT_METHOD;
        }
        $lossless = in_array($query['lossless'] ?? null, ['1', 'true'], true);

        return ConversionRequest::fromParameters([
            ConversionRequest::PARAM_FORMAT => ImageFormat::WEBP,
            ConversionRequest::PARAM_QUALITY => self::QUALITY,
            ConversionRequest::PARAM_EFFORT => self::effortOfMethod($method),
            ConversionRequest::PARAM_LOSSLESS => $lossless,
        ]);
    }

    /**
     * The effort whose cwebp method is $method: the library maps an effort e
     * to the method round(e * 6 / 9), and this is its inverse.
     *
     * @param int $method 0 to 6
     */
    public static function effortOfMethod(int $method): int
    {
        return (int) round($method * 9 / 6);
    }

    /**
     * The descriptors of the request: an empty list without the field (or
     * with an empty one, as 1.x read it), null when the field is not a JSON
     * array. Elements that are not objects are kept: they never match a
     * file.
     *
     * @return array<mixed>|null
     */
    public function descriptors(Request $request): ?array
    {
        if (!$request->request->has(self::FIELD_DESCRIPTORS)) {
            return [];
        }
        $field = $request->request->all()[self::FIELD_DESCRIPTORS];
        if (!is_string($field)) {
            return null;
        }
        if ($field === '' || $field === '0') {
            return [];
        }
        $descriptors = json_decode($field, true);

        return is_array($descriptors) && json_last_error() === JSON_ERROR_NONE ? $descriptors : null;
    }

    /**
     * The descriptor of a file: the first object whose "filename" is the
     * name of the file, or an empty array.
     *
     * @param array<mixed> $descriptors
     *
     * @return array<mixed>
     */
    public function descriptorOf(string $filename, array $descriptors): array
    {
        foreach ($descriptors as $descriptor) {
            if (is_array($descriptor) && isset($descriptor['filename']) && $descriptor['filename'] === $filename) {
                return $descriptor;
            }
        }

        return [];
    }

    /**
     * Whether 1.x accepted the extension of a file name.
     */
    public function hasAcceptedExtension(string $filename): bool
    {
        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /**
     * The 1.x message of an upload error.
     */
    public function uploadErrorMessage(int $error): string
    {
        return self::UPLOAD_ERRORS[$error] ?? 'Unknown upload error.';
    }

    /**
     * A size as 1.x formatted it ("1.23 MB": base 1024, two decimals at
     * most, units B, kB, MB, GB and TB).
     */
    public function formatBytes(int $bytes): string
    {
        $pow = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $pow = min($pow, count(self::BYTE_UNITS) - 1);

        return round($bytes / (1 << (10 * $pow)), 2) . ' ' . self::BYTE_UNITS[$pow];
    }

    /**
     * The compression ratio as 1.x computed it: the percentage saved,
     * rounded to two decimals.
     *
     * @param int $originalBytes above 0
     */
    public function compressionRatio(int $originalBytes, int $newBytes): float
    {
        return round((($originalBytes - $newBytes) / $originalBytes) * 100, 2);
    }
}
