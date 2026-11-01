<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Config;

/**
 * Compares filesystem paths as the filesystem resolves them: the longest
 * existing ancestor of a path is resolved with realpath() (symbolic links
 * included), and the part that does not exist yet is normalised lexically
 * ("." and ".." segments, repeated separators).
 *
 * @internal used by Config to keep TEMP_DIR outside the web root and
 *           LOCK_DIR out of the reach of the stale-directory sweep, and by
 *           RequestDirectories to keep that sweep away from LOCK_DIR
 */
final class PathResolver
{
    private function __construct()
    {
    }

    /**
     * Whether $path is $directory or lies below it.
     *
     * @param string $path      absolute path, existing or not
     * @param string $directory absolute path, existing or not
     */
    public static function isInside(string $path, string $directory): bool
    {
        $path = self::resolve($path);
        $directory = self::resolve($directory);

        return self::isBelowOrSame($path, $directory);
    }

    /**
     * Whether $path is $directory or lies below it as written: compared
     * lexically ("." and ".." segments, repeated separators), with no
     * symbolic link resolved. A path reached through a symbolic link may
     * be inside a directory as written and not as resolved: a check meant
     * to keep something out of a directory needs both this and isInside().
     *
     * @param string $path      absolute path, existing or not
     * @param string $directory absolute path, existing or not
     */
    public static function isInsideAsWritten(string $path, string $directory): bool
    {
        return self::isBelowOrSame(self::lexical($path), self::lexical($directory));
    }

    /**
     * The absolute form of $path with forward slashes and no ".", ".." or
     * empty segment, with no symbolic link resolved.
     *
     * @param string $path absolute path, existing or not
     */
    public static function lexical(string $path): string
    {
        return self::normalise(str_replace('\\', '/', $path));
    }

    /**
     * The absolute form of $path with forward slashes, the symbolic links of
     * its existing part resolved, and no ".", ".." or empty segment.
     *
     * @param string $path absolute path, existing or not
     */
    public static function resolve(string $path): string
    {
        $existing = str_replace('\\', '/', $path);
        $missing = [];
        while (realpath($existing) === false) {
            $parent = str_replace('\\', '/', dirname($existing));
            if ($parent === $existing) {
                break;
            }
            array_unshift($missing, basename($existing));
            $existing = $parent;
        }
        $real = realpath($existing);
        $base = $real === false ? $existing : str_replace('\\', '/', $real);

        return self::normalise($base . '/' . implode('/', $missing));
    }

    private static function isBelowOrSame(string $path, string $directory): bool
    {
        return $path === $directory || strpos($path, rtrim($directory, '/') . '/') === 0;
    }

    private static function normalise(string $path): string
    {
        $root = '';
        if (preg_match('#\A(?:[A-Za-z]:)?/#', $path, $match) === 1) {
            $root = $match[0];
            $path = (string) substr($path, strlen($root));
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return $root . implode('/', $segments);
    }
}
