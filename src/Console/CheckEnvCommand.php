<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Console;

use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Config\ConfigLoader;
use Mfonte\ImageConversionApi\Exception\InvalidConfigurationException;
use Mfonte\ImageConversionApi\Http\KernelFactory;
use Mfonte\ImageConversionApi\Limits\RequestLimits;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface as LibraryException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * "check-env": validates the configuration (environment first, then .env)
 * with the rules the service applies at boot (Config), then the host:
 *
 * - errors: no API key; TEMP_DIR not a writable directory; LOCK_DIR,
 *   CONVERTER_CACHE_DIR or the directory of LOG_FILE neither writable nor
 *   creatable; a converter that cannot be built (a strict isolation policy
 *   the host cannot meet) or that writes no WEBP;
 * - warnings: ext-posix missing (the library then keeps no capability or
 *   isolation cache, and every process probes again, for seconds);
 *   LOCK_DIR under /tmp or /var/tmp, which systemd-tmpfiles ages out;
 *   REQUIRE_HTTPS off; no isolation; the php.ini limits of this SAPI
 *   (post_max_size, upload_max_filesize, max_file_uploads) below the
 *   service's.
 *
 * It creates nothing: the directories are checked, not created, because
 * the command often runs as another user (root during "composer install")
 * than PHP-FPM, which must own LOCK_DIR and CONVERTER_CACHE_DIR. For the
 * same reason the converter is probed without the capability cache. The
 * php.ini read is the one of the PHP that runs the command (the CLI's),
 * which may differ from PHP-FPM's.
 *
 * Exits with 1 on any error. With --informational, used by the Composer
 * install hooks, it always exits with 0, so that installing the
 * dependencies never fails because the service is not configured yet.
 */
final class CheckEnvCommand extends Command
{
    public const NAME = 'check-env';

    /**
     * Directories that the system cleans up by age (systemd-tmpfiles,
     * tmpreaper): a lock file removed while the service runs lets two
     * processes take the same slot.
     */
    private const AGED_DIRECTORIES = ['/tmp', '/var/tmp'];

    /**
     * @var ConfigLoader
     */
    private $loader;

    /**
     * @var list<string>
     */
    private $webRoots;

    /**
     * @var callable(Config): Capabilities
     */
    private $capabilities;

    /**
     * @var callable(string): bool
     */
    private $extensionLoaded;

    /**
     * @var callable(string): string
     */
    private $iniGet;

    /**
     * @param ConfigLoader                          $loader          reads the environment and the .env file of the
     *                                                               installation
     * @param list<string>                          $webRoots        directories the web server serves files from
     * @param (callable(Config): Capabilities)|null $capabilities    the capabilities of the converter the service
     *                                                               builds; may throw the library's exceptions
     * @param (callable(string): bool)|null         $extensionLoaded extension_loaded() by default
     * @param (callable(string): string)|null       $iniGet          ini_get() by default
     */
    public function __construct(
        ConfigLoader $loader,
        array $webRoots,
        ?callable $capabilities = null,
        ?callable $extensionLoaded = null,
        ?callable $iniGet = null
    ) {
        $this->loader = $loader;
        $this->webRoots = $webRoots;
        $this->capabilities = $capabilities ?? static function (Config $config): Capabilities {
            return KernelFactory::converterBuilder($config, new NullLogger(), false)->build()->getCapabilities();
        };
        $this->extensionLoaded = $extensionLoaded ?? static function (string $extension): bool {
            return extension_loaded($extension);
        };
        $this->iniGet = $iniGet ?? static function (string $name): string {
            return (string) ini_get($name);
        };
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Validate the configuration (environment and .env) and the host requirements')
            ->addOption(
                'informational',
                null,
                InputOption::VALUE_NONE,
                'Report the problems but always exit with 0 (used by the Composer install hooks)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $informational = $input->getOption('informational') === true;

        if (!$this->loader->hasEnvFile() && $this->loader->readEnvironmentValues() === []) {
            if ($informational) {
                $output->writeln('<fg=yellow>!</> NOTE: no configuration yet. Set the variables of .env.example in the');
                $output->writeln('    environment, or copy .env.example to .env, then run "composer check-env".');

                return 0;
            }
            $output->writeln('<fg=red>✗ Environment check failed: no configuration. Set the variables of .env.example in the environment, or copy .env.example to .env.</>');

            return 1;
        }

        $errors = [];
        $warnings = [];
        $notes = [];

        try {
            $config = $this->loader->load($this->webRoots);
        } catch (InvalidConfigurationException $exception) {
            $config = null;
            $errors = $exception->getErrors();
        }
        if ($config !== null) {
            $this->checkHost($config, $errors, $warnings, $notes);
        }

        foreach ($notes as $note) {
            $output->writeln('  ' . OutputFormatter::escape($note));
        }
        foreach ($warnings as $warning) {
            $output->writeln('<fg=yellow>!</> ' . OutputFormatter::escape($warning));
        }
        if ($errors !== []) {
            $output->writeln('<fg=red>✗ Environment check failed:</>');
            foreach ($errors as $error) {
                $output->writeln('    <fg=red>•</> ' . OutputFormatter::escape($error));
            }
            if ($informational) {
                $output->writeln('    Fix the errors above, then run "composer check-env" to validate the configuration.');

                return 0;
            }

            return 1;
        }

        $output->writeln('<fg=green>✓ Environment check passed.</>');

        return 0;
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     * @param list<string> $notes
     */
    private function checkHost(Config $config, array &$errors, array &$warnings, array &$notes): void
    {
        if ($config->getApiKeys() === []) {
            $errors[] = 'No API key is configured (API_KEY, API_KEYS): every protected request would be refused';
        }
        if (!$config->isHttpsRequired()) {
            $warnings[] = 'REQUIRE_HTTPS is off: API keys may travel in clear text. Keep it off only for local development.';
        }

        $temp = $config->getTemporaryDirectory();
        if (!is_dir($temp) || !is_writable($temp)) {
            $errors[] = sprintf('TEMP_DIR %s is not a writable directory', $temp);
        }
        self::checkCreatable('LOCK_DIR', $config->getLockDirectory(), $errors);
        foreach (self::AGED_DIRECTORIES as $aged) {
            if (strpos($config->getLockDirectory() . '/', $aged . '/') === 0) {
                $warnings[] = sprintf(
                    'LOCK_DIR %s is under %s, which systemd-tmpfiles cleans up by age: use a directory that is not, such as /run/image-conversion-api.',
                    $config->getLockDirectory(),
                    $aged
                );
            }
        }
        $cache = $config->getConverterCacheDirectory();
        if ($cache !== null) {
            self::checkCreatable('CONVERTER_CACHE_DIR', $cache, $errors);
        }
        $logFile = $config->getLogFile();
        if ($logFile !== null && $logFile !== Config::LOG_FILE_STDERR) {
            if (file_exists($logFile) ? !is_writable($logFile) : !is_writable(dirname($logFile))) {
                $errors[] = sprintf('LOG_FILE %s cannot be written', $logFile);
            }
        }

        if (!($this->extensionLoaded)('posix')) {
            $warnings[] = 'ext-posix is missing: the conversion library keeps no cache of the backends and of the isolation it probes, so every request probes them again (seconds each).';
        }
        $this->checkIni($config, $warnings);
        $this->checkConverter($config, $errors, $warnings, $notes);
    }

    /**
     * An error unless $path is a writable directory, or can be created
     * under its nearest existing ancestor.
     *
     * @param list<string> $errors
     */
    private static function checkCreatable(string $key, string $path, array &$errors): void
    {
        if (is_dir($path)) {
            if (!is_writable($path)) {
                $errors[] = sprintf('%s %s is not writable', $key, $path);
            }

            return;
        }
        if (file_exists($path)) {
            $errors[] = sprintf('%s %s is not a directory', $key, $path);

            return;
        }
        $parent = dirname($path);
        while (!file_exists($parent) && $parent !== dirname($parent)) {
            $parent = dirname($parent);
        }
        if (!is_dir($parent) || !is_writable($parent)) {
            $errors[] = sprintf('%s %s does not exist and cannot be created in %s', $key, $path, $parent);
        }
    }

    /**
     * @param list<string> $warnings
     */
    private function checkIni(Config $config, array &$warnings): void
    {
        $limits = [
            ['post_max_size', $config->getMaxBodyBytes(), 'MAX_BODY_BYTES'],
            ['upload_max_filesize', $config->getMaxImageBytes(), 'MAX_IMAGE_BYTES'],
        ];
        foreach ($limits as list($directive, $bytes, $key)) {
            $value = ($this->iniGet)($directive);
            if (RequestLimits::parseIniBytes($value) < $bytes) {
                $warnings[] = sprintf(
                    '%s is %s in the php.ini of %s, below %s (%d bytes): set it to at least that in PHP-FPM too.',
                    $directive,
                    $value,
                    PHP_SAPI,
                    $key,
                    $bytes
                );
            }
        }
        if ($config->getV1ScriptName() !== null) {
            $value = ($this->iniGet)('max_file_uploads');
            if ((int) $value < $config->getV1MaxFiles()) {
                $warnings[] = sprintf(
                    'max_file_uploads is %s in the php.ini of %s, below V1_MAX_FILES (%d): PHP drops the files above it.',
                    $value,
                    PHP_SAPI,
                    $config->getV1MaxFiles()
                );
            }
        }
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     * @param list<string> $notes
     */
    private function checkConverter(Config $config, array &$errors, array &$warnings, array &$notes): void
    {
        try {
            $capabilities = ($this->capabilities)($config);
        } catch (LibraryException $exception) {
            $errors[] = sprintf('The converter cannot be built (%s): %s', $exception->getErrorCode(), $exception->getMessage());

            return;
        }

        $notes[] = sprintf(
            'Converter: isolation %s; backends %s; WEBP output %s.',
            $capabilities->getIsolationLevel(),
            $capabilities->getBackends() === [] ? 'none' : implode(', ', $capabilities->getBackends()),
            $capabilities->supportsOutput(ImageFormat::WEBP) ? 'yes' : 'no'
        );
        if (!$capabilities->supportsOutput(ImageFormat::WEBP)) {
            $errors[] = 'No backend of this host writes WEBP: install libvips (vips), cwebp, Imagick or GD with WEBP support';
        }
        if ($capabilities->getIsolationLevel() === IsolationLevel::NONE) {
            $warnings[] = 'The conversions run inside the PHP process, without isolation (proc_open is disabled): a decoder crash takes the worker down.';
        }
    }
}
