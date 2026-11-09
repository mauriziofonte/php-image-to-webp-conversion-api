<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Exception;

/**
 * The configuration cannot be loaded: a value is missing or invalid.
 * getErrors() lists every problem found, one sentence each.
 */
final class InvalidConfigurationException extends \UnexpectedValueException implements ExceptionInterface
{
    /**
     * @var list<string>
     */
    private $errors;

    /**
     * @param list<string> $errors one sentence per problem, at least one
     */
    public function __construct(array $errors)
    {
        $this->errors = $errors === [] ? ['The configuration is invalid'] : $errors;
        parent::__construct('Invalid configuration: ' . implode('; ', $this->errors));
    }

    /**
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
