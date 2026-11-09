<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Exception;

/**
 * An operation failed because of the host, for example a directory that
 * cannot be created. The message names the operation and the path, never
 * request data.
 */
final class RuntimeException extends \RuntimeException implements ExceptionInterface
{
}
