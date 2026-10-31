<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Exception;

/**
 * A value given to a constructor or a factory method is invalid: a
 * programming or integration error, never a runtime condition of a request.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements ExceptionInterface
{
}
