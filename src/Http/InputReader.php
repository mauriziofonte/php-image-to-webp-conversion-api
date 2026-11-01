<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http;

use Mfonte\ImageConversionApi\Exception\RequestException;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Finds the image of a conversion request in one of the two shapes the
 * endpoint accepts, and gives its path on disk without loading it in
 * memory:
 *
 * - multipart/form-data with the image as the one file of the field
 *   "image" (FIELD): PHP has already stored the upload in its own
 *   temporary file (upload_tmp_dir), which is used as it is and which PHP
 *   removes at the end of the request;
 * - any other body (Content-Type image/png, application/octet-stream or
 *   none): the raw bytes are the image. They are copied from php://input
 *   into the request directory in chunks, and the copy stops one byte
 *   past the size limit, so a body without Content-Length (chunked
 *   transfer encoding) is refused as soon as it is too large, without
 *   being read to its end.
 *
 * An application/x-www-form-urlencoded body is refused (415): PHP has
 * parsed it as a form, and it cannot carry a binary image anyway. An empty
 * body is a missing image (400) whatever its Content-Type, so that a bare
 * POST, which some clients label as a form, gets the more useful answer.
 */
final class InputReader
{
    /**
     * The multipart field that carries the image.
     */
    public const FIELD = 'image';

    /**
     * Name of the copy of a raw body in the request directory.
     */
    public const RAW_INPUT_NAME = 'input';

    /**
     * Whether the request carries a multipart/form-data body.
     */
    public static function isMultipart(Request $request): bool
    {
        return self::mediaType($request) === 'multipart/form-data';
    }

    /**
     * The path of the image of the request.
     *
     * @param int $maxBytes the largest image accepted, in bytes
     *
     * @throws RequestException 400 missing_image, 400 invalid_request (a multipart body with other files or several
     *                          images), 413 file_too_large, 415 unsupported_media_type, or 500 internal_error when the
     *                          upload or the copy fails on the server's side
     */
    public function read(Request $request, RequestDirectory $directory, int $maxBytes): string
    {
        $mediaType = self::mediaType($request);
        if ($mediaType === 'multipart/form-data') {
            return $this->readUpload($request, $maxBytes);
        }
        $path = $this->readBody($request, $directory, $maxBytes);
        if ($mediaType === 'application/x-www-form-urlencoded') {
            throw new RequestException(415, ResponseFactory::ERROR_UNSUPPORTED_MEDIA_TYPE, 'Send the image as the raw body, or as the multipart/form-data field "image".');
        }

        return $path;
    }

    private function readUpload(Request $request, int $maxBytes): string
    {
        $files = $request->files->all();
        $file = $files[self::FIELD] ?? null;
        unset($files[self::FIELD]);
        if (array_filter($files, static function ($other): bool {
            return $other !== null && $other !== [];
        }) !== [] || is_array($file)) {
            throw new RequestException(400, ErrorCode::INVALID_REQUEST, 'Send exactly one file, in the multipart/form-data field "image".');
        }
        if (!$file instanceof UploadedFile) {
            throw self::missingImage();
        }

        switch ($file->getError()) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw self::tooLarge();
            case UPLOAD_ERR_PARTIAL:
            case UPLOAD_ERR_NO_FILE:
                throw self::missingImage();
            default:
                throw self::serverError();
        }
        if (!$file->isValid()) {
            throw self::serverError();
        }
        $path = $file->getPathname();
        clearstatcache(true, $path);
        $size = @filesize($path);
        if ($size === false) {
            throw self::serverError();
        }
        if ($size === 0) {
            throw self::missingImage();
        }
        if ($size > $maxBytes) {
            throw self::tooLarge();
        }

        return $path;
    }

    private function readBody(Request $request, RequestDirectory $directory, int $maxBytes): string
    {
        $body = $request->getContent(true);
        if (!is_resource($body)) {
            throw self::serverError();
        }
        $path = $directory->getPath() . DIRECTORY_SEPARATOR . self::RAW_INPUT_NAME;
        $target = @fopen($path, 'xb');
        if ($target === false) {
            throw self::serverError();
        }

        try {
            $copied = @stream_copy_to_stream($body, $target, $maxBytes + 1);
            $flushed = @fflush($target);
        } finally {
            @fclose($target);
        }
        if ($copied === false || !$flushed) {
            throw self::serverError();
        }
        if ($copied > $maxBytes) {
            throw self::tooLarge();
        }
        if ($copied === 0) {
            throw self::missingImage();
        }

        return $path;
    }

    /**
     * The media type of the body, lower case and without parameters; '' when
     * the request has no Content-Type.
     */
    private static function mediaType(Request $request): string
    {
        $contentType = (string) $request->headers->get('Content-Type', '');
        $semicolon = strpos($contentType, ';');

        return strtolower(trim($semicolon === false ? $contentType : substr($contentType, 0, $semicolon)));
    }

    private static function missingImage(): RequestException
    {
        return new RequestException(400, ResponseFactory::ERROR_MISSING_IMAGE, 'Send an image as the raw body, or as the multipart/form-data field "image".');
    }

    private static function tooLarge(): RequestException
    {
        return new RequestException(413, ErrorCode::FILE_TOO_LARGE, 'The image is larger than the maximum file size.');
    }

    private static function serverError(): RequestException
    {
        return new RequestException(500, ResponseFactory::ERROR_INTERNAL, 'The request could not be processed.');
    }
}
