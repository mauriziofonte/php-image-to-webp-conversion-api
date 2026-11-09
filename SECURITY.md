# Security policy

## Supported versions

| Version | Supported |
| --- | --- |
| 2.x | Yes |
| 1.x | No |

Security fixes are made on the latest 2.x release. The 1.x releases (`process.php`) are not maintained; the 1.x request format remains available in 2.x through the `/v1` endpoint, which receives the same fixes as the rest of 2.x.

## Reporting a vulnerability

Report vulnerabilities privately, through GitHub's private vulnerability reporting:

1. Open the [Security tab](https://github.com/mauriziofonte/php-image-to-webp-conversion-api/security) of the repository.
2. Choose **Report a vulnerability** and fill in the advisory form.

Please do not open a public issue, pull request or discussion for a vulnerability. Include:

- the affected version or commit;
- the deployment it applies to (web server, PHP version, Docker or not, isolation level shown by `composer check-env`);
- the steps to reproduce, with a sample request or image where possible;
- the impact you observed or expect.

The report stays private while it is assessed. A fix is prepared in the private advisory and published with a release, together with the advisory.

## Scope

In scope: the code of this repository, meaning the HTTP service in `src/`, `public/` and `bin/`, and the shipped deployment material (`.htaccess`, `deploy/nginx/`, `docker/`). Examples: an authentication bypass, a way to read or write files outside the request's temporary directory, request data or secrets disclosed in a response or a log, a limit that can be bypassed, a crash of the service triggered by a request.

Out of scope here:

- vulnerabilities in the conversion library, [mfonte/secure-image-converter](https://github.com/mauriziofonte/php-secure-image-converter), such as a decoder that a crafted image takes over or an escape from its isolation: report them in that repository;
- vulnerabilities in third-party software (PHP, the web server, libvips, libwebp, libavif, ImageMagick, GD, bubblewrap): report them upstream;
- configurations that the documentation advises against, such as `REQUIRE_HTTPS=false` in production, keys shorter than the minimum, or a `TEMP_DIR` inside the web root;
- the properties of the `rlimit` isolation level that the README describes in its security model.
