<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Exception;

/**
 * Implemented by every exception thrown by this package, so that a caller
 * can catch all of them at once. Exceptions of the conversion library keep
 * their own marker interface (Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface).
 */
interface ExceptionInterface extends \Throwable
{
}
