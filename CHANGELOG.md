# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-11-09

This release is a rewrite of the 1.x script as a Composer project; [UPGRADING.md](UPGRADING.md) explains how to move an existing installation.

### Added

- `POST /v2/convert`: converts one image, sent as the raw body or as the multipart field `image`, and answers the converted image as the response body, with `X-Conversion-*` headers that describe the output, any format fallback and whether the original was kept.
- WEBP and AVIF output, resizing, metadata stripping, ICC profile handling and an animation policy, through the [mfonte/secure-image-converter](https://github.com/mauriziofonte/php-secure-image-converter) library and its backends (libvips, cwebp, gif2webp, avifenc, Imagick, GD) with process isolation and resource limits.
- Strict parameter parsing: an unknown parameter or a malformed value is a `400 invalid_request`.
- A uniform error body, `{"error":{"code","message"}}`, with stable codes and fixed messages.
- Authentication with `Authorization: Bearer`, besides `X-API-Key` and the legacy `-x-api-key` header; several keys (`API_KEYS`) for rotation; keys configured as SHA-256 digests (`sha256:<hex>`); constant-time comparison.
- `REQUIRE_HTTPS`, `TRUSTED_PROXIES` and `IP_ALLOWLIST`.
- A per-key token bucket rate limit (`RATE_LIMIT`, `RATE_LIMIT_BURST`, `RATE_LIMIT_STORE`), a host-wide limit on concurrent conversions (`CONCURRENCY_SLOTS`, `CONCURRENCY_WAIT`, `LOCK_DIR`), a request time limit (`CONVERSION_TIME_LIMIT`) and body and image size limits (`MAX_BODY_BYTES`, `MAX_IMAGE_BYTES`).
- `GET /healthz`: a public liveness answer, and with an API key the converter's capabilities (schema 1).
- `GET /metrics`: Prometheus counters and histograms of requests and conversions, with scraper-only keys (`METRICS_KEYS`) and a configurable store (`METRICS_STORE`).
- JSON log lines (`LOG_FILE`, `LOG_LEVEL`) with a request ID adopted from `X-Request-Id` or generated, and echoed in every response.
- Per-request temporary directories under `TEMP_DIR`, removed at the end of the request and, when a process died, by a later request.
- `bin/image-conversion-api check-env` (`composer check-env`), which validates the configuration and the host.
- Configuration from environment variables, then from the optional `.env` file.
- Apache `.htaccess` files for a `public/` document root and for an installation directory served as is, an nginx configuration in `deploy/nginx/`, and a hardened Docker image with Compose files in `docker/`, including an opt-in override for the `sandbox` isolation.
- A test suite (unit, functional, integration behind Apache, nginx and Docker, the library's hostile image corpus) and static analysis (PHPStan, Psalm taint analysis, php-cs-fixer), run in CI on PHP 7.2 to 8.5.

### Changed

- The service requires PHP 7.2.5 or later and is installed with Composer.
- The front controller is `public/index.php`; every request is routed to it.
- The 1.x endpoint moved from `/{SCRIPT_NAME}` to `/v1/{V1_SCRIPT_NAME}`, and the configuration key `SCRIPT_NAME` is now `V1_SCRIPT_NAME`. It is supported, not deprecated, and has no removal date.
- The 1.x endpoint converts with the library and within the 2.x limits: at most `V1_MAX_FILES` images per request, one rate limit token per image. A file whose WEBP would not be smaller than the original now fails instead of returning the larger file. Requests refused as a whole answer `400`, `413` or `503` instead of `200`, with the 1.x body.
- The `.env` file takes `#` comments (the 1.x `;` comments are still skipped), and values with spaces must be quoted.
- API keys must be at least 16 bytes long.

### Removed

- `process.php`, the 1.x script, and its direct calls to the `cwebp` binary through `exec()`.

### Security

- With no API key configured, every protected request is refused (`503 authentication_not_configured`). 1.x let a request without a key header through when `API_KEY` was missing.
- PHP errors and stray output never reach a response body; they are logged. 1.x turned `display_errors` on.
- Uploaded files and outputs are kept in per-request directories outside the web root and removed after the request. 1.x used a `temp/` directory inside the installation.
- Error bodies never echo request data, and every path except `/healthz` requires a key, so an unauthenticated client cannot tell which paths exist.
- The plain HTTP server of the shipped nginx configuration redirects only requests for its own `server_name`, to that name rather than to the client's `Host`, with `Cache-Control: no-store`; requests for other hosts get `421`.

[2.0.0]: https://github.com/mauriziofonte/php-image-to-webp-conversion-api/releases/tag/v2.0.0
