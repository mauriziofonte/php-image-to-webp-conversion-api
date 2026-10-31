<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers the requests of one route. The kernel calls it only after the
 * request passed the checks of the route (HTTPS, method), and turns any
 * exception it throws into a 500 response.
 */
interface RequestHandlerInterface
{
    /**
     * @param RequestDirectory $directory where the handler keeps the temporary files of this request (uploads,
     *                                    conversion outputs). The kernel removes it, with everything inside, before
     *                                    handle() returns to the caller, whatever happened: the response must
     *                                    therefore carry its body (a string, or a stream the handler opened
     *                                    before returning), never a path into the directory
     */
    public function handle(Request $request, RequestDirectory $directory): Response;
}
