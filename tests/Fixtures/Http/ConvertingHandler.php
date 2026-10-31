<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Fixtures\Http;

use Mfonte\ImageConversionApi\Http\RequestDirectory;
use Mfonte\ImageConversionApi\Http\RequestHandlerInterface;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ConverterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Behaves as the conversion endpoints will: stores the request body in the
 * request directory, converts it with a ConverterInterface into the same
 * directory, then ends as configured. It records the files that exist in
 * the directory just before it ends, so that tests can check that the files
 * existed and are gone afterwards.
 */
final class ConvertingHandler implements RequestHandlerInterface
{
    /** Answers 200 with the converted bytes. */
    public const SUCCEED = 'succeed';

    /** Answers a 422 error response. */
    public const ANSWER_ERROR = 'answer_error';

    /** Throws a RuntimeException. */
    public const THROW = 'throw';

    /** Ends the process with a fatal error: a class declared twice. */
    public const FATAL = 'fatal';

    /**
     * @var ConverterInterface
     */
    private $converter;

    /**
     * @var string
     */
    private $outcome;

    /**
     * @var list<string> files of the request directory just before the handler ended, relative to it
     */
    private $files = [];

    public function __construct(ConverterInterface $converter, string $outcome = self::SUCCEED)
    {
        $this->converter = $converter;
        $this->outcome = $outcome;
    }

    public function handle(Request $request, RequestDirectory $directory): Response
    {
        $path = $directory->getPath();
        $input = $path . DIRECTORY_SEPARATOR . 'input.png';
        $output = $path . DIRECTORY_SEPARATOR . 'output.webp';
        file_put_contents($input, $request->getContent());
        mkdir($path . DIRECTORY_SEPARATOR . 'nested');
        file_put_contents($path . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'chunk', 'chunk');

        try {
            $this->converter->convert($input, $output, new ConversionRequest('webp'));
        } finally {
            $this->files = self::listFiles($path);
        }

        switch ($this->outcome) {
            case self::ANSWER_ERROR:
                return (new ResponseFactory())->error(422, 'invalid_image', 'The image is malformed.');
            case self::THROW:
                throw new \RuntimeException('The handler failed.');
            case self::FATAL:
                eval('final class ConvertingHandlerFatalProbe {}');
                eval('final class ConvertingHandlerFatalProbe {}');

                return new Response('unreachable');
            default:
                return new Response((string) file_get_contents($output), 200, ['Content-Type' => 'image/webp']);
        }
    }

    /**
     * @return list<string>
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * @return list<string> the files below $directory, as paths relative to it with "/" separators, sorted
     */
    public static function listFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($directory) + 1));
            }
        }
        sort($files);

        return $files;
    }
}
