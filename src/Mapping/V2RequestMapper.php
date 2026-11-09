<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Mapping;

use Mfonte\ImageConversionApi\Exception\RequestException;
use Mfonte\ImageConversionApi\Http\InputReader;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ConversionResult;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Maps POST /v2/convert to the library and back.
 *
 * Parameters: the canonical parameters of ConversionRequest (format,
 * quality, effort, lossless, width, height, fit, enlarge, strip, keep_icc,
 * animation), in the query string or, for a multipart/form-data body, as
 * form fields too. They are parsed strictly by
 * ConversionRequest::fromParameters(): an unknown name or a value outside
 * the canonical spellings is a 400 invalid_request, never ignored. A
 * parameter given both in the query string and as a form field is refused
 * too, rather than one of them silently winning.
 *
 * Response of a conversion: 200 with the image as the body and
 *
 * | Header                         | Value                                                         |
 * | ------------------------------ | ------------------------------------------------------------- |
 * | Content-Type                   | MIME type of the output, sniffed from its content             |
 * | Content-Length                 | size of the output in bytes                                   |
 * | Cache-Control                  | no-store (the caller caches, not intermediaries)              |
 * | X-Content-Type-Options         | nosniff                                                       |
 * | X-Conversion-Format            | output format: webp, avif, jpeg, png, gif, bmp, tiff          |
 * | X-Conversion-Requested-Format  | the format parameter: webp, avif or original                  |
 * | X-Conversion-Fallback          | 1 when webp or avif was requested and another format was      |
 * |                                | produced because the host cannot produce it, otherwise 0      |
 * | X-Conversion-Fallback-Reason   | the library's warning about that fallback (only when 1)       |
 * | X-Conversion-Original-Kept     | 1 when the body is the input unchanged (the converted file    |
 * |                                | was not smaller and the input satisfied the request), else 0  |
 * | X-Conversion-Width             | output width in pixels                                        |
 * | X-Conversion-Height            | output height in pixels                                       |
 * | X-Conversion-Frames            | number of frames, 1 for a still image                         |
 * | X-Conversion-Input-Bytes       | size of the input in bytes                                    |
 * | X-Conversion-Backend           | backend that produced the output (none when no backend ran)   |
 * | X-Conversion-Isolation         | isolation level the conversion ran under                      |
 * | X-Conversion-Duration-Ms       | duration of the conversion in milliseconds                    |
 * | X-Conversion-Warnings          | number of warnings                                            |
 * | X-Conversion-Warning           | one header line per warning, in order (none when 0)           |
 *
 * Every value is printable ASCII: warnings are 1 to 256 printable ASCII
 * characters by the library's contract, without paths or input data.
 */
final class V2RequestMapper
{
    /**
     * Prefix of the library's warning about a format fallback, followed by
     * the requested format.
     */
    private const FALLBACK_WARNING = 'The %s output is not available';

    /**
     * The conversion request of an HTTP request.
     *
     * @throws InvalidRequestException a parameter is unknown or invalid (invalid_request, 400)
     * @throws RequestException        a parameter is given twice (invalid_request, 400)
     */
    public function toConversionRequest(Request $request): ConversionRequest
    {
        $parameters = $request->query->all();
        if (InputReader::isMultipart($request)) {
            $fields = $request->request->all();
            if (array_intersect_key($parameters, $fields) !== []) {
                throw new RequestException(400, ErrorCode::INVALID_REQUEST, 'A parameter is given both in the query string and as a form field.');
            }
            $parameters += $fields;
        }

        return ConversionRequest::fromParameters($parameters);
    }

    /**
     * The response of a successful conversion.
     *
     * @param resource $output     the output file, open for reading at its start; the response reads it to its end and
     *                             closes it when it is sent
     * @param int      $inputBytes size of the input, in bytes
     */
    public function toResponse(ConversionResult $result, ConversionRequest $request, $output, int $inputBytes): Response
    {
        $requested = $request->getFormat();
        $fallback = !$result->isPassthrough()
            && $requested !== ConversionRequest::FORMAT_ORIGINAL
            && $result->getOutputFormat() !== $requested;
        $warnings = $result->getWarnings();

        $headers = [
            'Content-Type' => $result->getMimeType(),
            'Content-Length' => (string) $result->getByteSize(),
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Conversion-Format' => $result->getOutputFormat(),
            'X-Conversion-Requested-Format' => $requested,
            'X-Conversion-Fallback' => $fallback ? '1' : '0',
            'X-Conversion-Original-Kept' => $result->isPassthrough() ? '1' : '0',
            'X-Conversion-Width' => (string) $result->getWidth(),
            'X-Conversion-Height' => (string) $result->getHeight(),
            'X-Conversion-Frames' => (string) $result->getFrameCount(),
            'X-Conversion-Input-Bytes' => (string) $inputBytes,
            'X-Conversion-Backend' => $result->getBackend(),
            'X-Conversion-Isolation' => $result->getIsolationLevel(),
            'X-Conversion-Duration-Ms' => (string) $result->getDurationMs(),
            'X-Conversion-Warnings' => (string) count($warnings),
        ];
        if ($fallback) {
            $reason = self::fallbackReason($warnings, $requested);
            if ($reason !== null) {
                $headers['X-Conversion-Fallback-Reason'] = $reason;
            }
        }

        $response = new StreamedResponse(static function () use ($output): void {
            fpassthru($output);
            fclose($output);
        }, 200, $headers);
        if ($warnings !== []) {
            $response->headers->set('X-Conversion-Warning', $warnings);
        }

        return $response;
    }

    /**
     * The warning that explains a fallback from $requested.
     *
     * @param list<string> $warnings
     */
    private static function fallbackReason(array $warnings, string $requested): ?string
    {
        $prefix = sprintf(self::FALLBACK_WARNING, $requested);
        foreach ($warnings as $warning) {
            if (strpos($warning, $prefix) === 0) {
                return $warning;
            }
        }

        return null;
    }
}
