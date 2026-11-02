<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Lets the service answer below a URL prefix when Apache serves the
 * installation directory itself (the .htaccess at its root), as 1.x was
 * installed: https://example.com/webp-converter/v2/convert.
 *
 * That .htaccess rewrites every request to public/index.php and passes the
 * URL path of the installation directory ("/webp-converter/") in the
 * environment variable ENV, which Apache renames REDIRECT_ENV after the
 * internal redirect. The routes are then matched on the path below that
 * prefix, as they are when public/ is the document root. The variable comes
 * from the server configuration only: a client cannot set it (a request
 * header becomes HTTP_*).
 */
final class InstallationBase
{
    /**
     * The environment variable the root .htaccess sets.
     */
    public const ENV = 'IMAGE_CONVERSION_API_BASE';

    private function __construct()
    {
    }

    /**
     * Makes $request resolve its path below the installation prefix, when
     * the root .htaccess passed one. Call it before the path is read.
     */
    public static function apply(Request $request): void
    {
        $base = self::of($request);
        if ($base === null || $base === '/') {
            return;
        }
        // Request::getBaseUrl() takes SCRIPT_NAME as the prefix when its file
        // name is the front controller's.
        $scriptName = $base . 'index.php';
        $request->server->set('SCRIPT_NAME', $scriptName);
        $request->server->set('PHP_SELF', $scriptName);
        $request->server->remove('ORIG_SCRIPT_NAME');
    }

    /**
     * The URL path of the installation directory, starting and ending with
     * "/"; null when none was passed or it is malformed.
     */
    public static function of(Request $request): ?string
    {
        foreach (['', 'REDIRECT_', 'REDIRECT_REDIRECT_'] as $prefix) {
            $value = $request->server->get($prefix . self::ENV);
            if (is_string($value) && $value !== '') {
                return preg_match('#\A/(?:[A-Za-z0-9._~!$&\'()*+,;=:@%-]+/)*\z#', $value) === 1 && strpos($value, '/../') === false
                    ? $value
                    : null;
            }
        }

        return null;
    }
}
