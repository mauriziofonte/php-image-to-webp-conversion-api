<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Config;

use Dotenv\Dotenv;
use Mfonte\ImageConversionApi\Exception\InvalidConfigurationException;

/**
 * Loads the configuration, environment first (plan section 4), as the
 * proxy's ConfigLoader does:
 *
 * 1. a variable of the environment that is set and not empty wins;
 * 2. otherwise the value of the optional .env file is used;
 * 3. the empty string and the literal "null" mean "not set", and the
 *    default of the key applies (Config::fromArray()).
 *
 * The .env file is parsed with phpdotenv into an array: the process
 * environment, $_ENV and $_SERVER are never modified, so a value of .env
 * never leaks into the environment of the conversion processes. Only the
 * keys of Config::KEYS are read; other variables of the file are ignored.
 * Comments start with "#"; lines that start with ";", the comments of the
 * 1.x .env file (read with parse_ini_file()), are skipped too. A parse
 * error is reported without the text phpdotenv quotes from the file,
 * which can be a secret.
 *
 * There is no compiled cache: reading and validating the configuration
 * costs well under a millisecond per request, next to conversions of tens
 * to thousands of milliseconds, and a compiled file would need a writable
 * code directory, which the read-only container root filesystem does not
 * have.
 */
final class ConfigLoader
{
    /**
     * @var string|null
     */
    private $envFile;

    /**
     * @var callable(string): ?string
     */
    private $environment;

    /**
     * @param string|null                      $envFile     path of the optional .env file; null to read the
     *                                                      environment only
     * @param (callable(string): ?string)|null $environment reads one environment variable and returns null when it
     *                                                      is not set; getenv() by default, which also sees the
     *                                                      variables PHP-FPM and Apache pass to the request
     */
    public function __construct(?string $envFile, ?callable $environment = null)
    {
        $this->envFile = $envFile;
        $this->environment = $environment ?? static function (string $name): ?string {
            $value = getenv($name);

            return is_string($value) ? $value : null;
        };
    }

    /**
     * Reads and validates the configuration.
     *
     * @param list<string> $webRoots directories the web server serves files from (see Config::fromArray())
     *
     * @throws InvalidConfigurationException when .env cannot be parsed or a value is invalid
     */
    public function load(array $webRoots = []): Config
    {
        return Config::fromArray($this->readValues(), $webRoots);
    }

    /**
     * The raw value of every key of Config::KEYS, environment first, then
     * .env; null for a key that is set in neither.
     *
     * @throws InvalidConfigurationException when .env exists but cannot be read or parsed
     *
     * @return array<string, string|null>
     */
    public function readValues(): array
    {
        $fromFile = $this->readEnvFile();
        $fromEnvironment = $this->readEnvironmentValues();

        $values = [];
        foreach (Config::KEYS as $key) {
            $values[$key] = $fromEnvironment[$key] ?? $fromFile[$key] ?? null;
        }

        return $values;
    }

    /**
     * The keys of Config::KEYS set to a value other than "" and "null" in
     * the environment.
     *
     * @return array<string, string>
     */
    public function readEnvironmentValues(): array
    {
        $values = [];
        foreach (Config::KEYS as $key) {
            $value = ($this->environment)($key);
            if ($value !== null && !self::isUnset($value)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * Whether a .env file is configured and exists.
     */
    public function hasEnvFile(): bool
    {
        return $this->envFile !== null && is_file($this->envFile);
    }

    /**
     * The path of the .env file, whether it exists or not; null when none
     * is configured.
     */
    public function getEnvFile(): ?string
    {
        return $this->envFile;
    }

    private static function isUnset(string $value): bool
    {
        $value = trim($value);

        return $value === '' || strtolower($value) === 'null';
    }

    /**
     * @throws InvalidConfigurationException
     *
     * @return array<string, string>
     */
    private function readEnvFile(): array
    {
        if ($this->envFile === null || !is_file($this->envFile)) {
            return [];
        }
        $contents = @file_get_contents($this->envFile);
        if ($contents === false) {
            throw new InvalidConfigurationException(['The .env file cannot be read']);
        }

        try {
            $parsed = Dotenv::parse((string) preg_replace('/^[ \t]*;[^\r\n]*/m', '', $contents));
        } catch (\Exception $exception) {
            // phpdotenv quotes the offending text ("... at [value]."), which can be a key.
            $reason = (string) preg_replace('/ at \[.*\z/s', '.', $exception->getMessage());

            throw new InvalidConfigurationException(['The .env file cannot be parsed: ' . $reason]);
        }

        $values = [];
        foreach (Config::KEYS as $key) {
            $value = $parsed[$key] ?? null;
            if (is_string($value) && !self::isUnset($value)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }
}
