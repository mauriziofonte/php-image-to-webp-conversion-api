<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Exception;

/**
 * A request that the service refuses before or instead of a conversion: no
 * image, a body in an unsupported shape, a limit of the API exceeded. It
 * carries the HTTP status, the machine-readable error code and the fixed
 * sentence of the error body, so that a handler can answer it as it is.
 *
 * The message is a fixed sentence for the client: it never contains
 * request data.
 */
final class RequestException extends \RuntimeException implements ExceptionInterface
{
    /**
     * @var int
     */
    private $statusCode;

    /**
     * @var string
     */
    private $errorCode;

    /**
     * @var array<string, string>
     */
    private $headers;

    /**
     * @param int                   $statusCode HTTP status, 400 to 599
     * @param string                $errorCode  stable machine-readable code (snake_case)
     * @param string                $message    fixed sentence for the client, never request data
     * @param array<string, string> $headers    response headers that go with the error, such as Retry-After
     */
    public function __construct(int $statusCode, string $errorCode, string $message, array $headers = [])
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->headers = $headers;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
