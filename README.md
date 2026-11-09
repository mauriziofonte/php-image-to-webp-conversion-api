# Image Conversion API

[![CI](https://github.com/mauriziofonte/php-image-to-webp-conversion-api/actions/workflows/ci.yml/badge.svg)](https://github.com/mauriziofonte/php-image-to-webp-conversion-api/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-7.2.5%20to%208.5-777bb4.svg)](composer.json)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE)

`mfonte/image-conversion-api` is a small HTTP service, written in PHP, that converts untrusted images to web-optimized WEBP or AVIF files. A client sends one image and receives the converted image as the response body, with headers that describe what was produced. The conversion runs in the [mfonte/secure-image-converter](https://github.com/mauriziofonte/php-secure-image-converter) library, which validates the input, picks a backend available on the host (libvips, cwebp, gif2webp, avifenc, Imagick or GD) and runs it in an isolated process with resource limits. The service adds what an HTTP deployment needs around it: API keys, HTTPS enforcement, rate limiting, a per-host concurrency limit, JSON logs, Prometheus metrics and a health endpoint. It also serves the request format of the 1.x releases as a compatibility endpoint.

It is the remote converter of the transparent WEBP proxy [mfonte/proxy-cdn](https://github.com/mauriziofonte/php-webp-rewriter-trasparent-proxy), and can be used by any HTTP client.

## Table of contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Usage](#usage)
  - [Authentication](#authentication)
  - [POST /v2/convert](#post-v2convert)
  - [GET /healthz](#get-healthz)
  - [GET /metrics](#get-metrics)
  - [Errors](#errors)
- [The 1.x endpoint](#the-1x-endpoint)
- [Deployment](#deployment)
- [Operations](#operations)
- [Security model](#security-model)
- [Performance](#performance)
- [Testing and development](#testing-and-development)
- [Versioning and compatibility](#versioning-and-compatibility)
- [Contributing](#contributing)
- [Reporting security issues](#reporting-security-issues)
- [License](#license)

## Features

- **One image per request, binary response.** `POST /v2/convert` takes the image as the raw body or as one multipart field and answers the converted image itself, streamed from disk, with its exact `Content-Type` and `Content-Length`.
- **WEBP and AVIF output**, resizing (`contain`, `cover`, `fill`), metadata stripping with an optional ICC profile, and an explicit policy for animated images. When the host cannot produce the requested format, or the converted file would not be smaller, the response says so in its headers.
- **Strict parameters.** An unknown parameter or a malformed value is a `400`, never silently ignored.
- **Fail-closed authentication.** API keys as `Authorization: Bearer`, `X-API-Key` or the legacy proxy header; several keys for rotation; keys may be configured as SHA-256 digests; constant-time comparison. With no key configured every protected request is refused.
- **Transport and network controls.** HTTPS is required by default, forwarding headers are believed only from `TRUSTED_PROXIES`, and an optional IP allowlist applies on top of the key.
- **Limits.** A per-key token bucket rate limit, a host-wide limit on concurrent conversions, a request time limit that the library enforces before the web server gives up, and body and image size limits checked before the body is read.
- **Stable error contract.** Every error is `{"error":{"code","message"}}` with a machine-readable code and a fixed message that never echoes request data.
- **Operations.** JSON log lines with a request ID that is echoed in `X-Request-Id`, a Prometheus exposition at `/metrics`, a public liveness check and an authenticated readiness check with the converter's capabilities at `/healthz`, and a `check-env` command that validates the configuration and the host.
- **Deployment material.** Apache `.htaccess` files, an nginx configuration, and a hardened Docker image (non-root, read-only root filesystem, no capabilities) with a Compose file.
- **1.x compatibility.** The 1.x batch request and JSON response are served at `/v1/{V1_SCRIPT_NAME}`.

## Requirements

- PHP 7.2.5 or later, up to PHP 8.5, 64-bit, with the `filter`, `hash` and `json` extensions. The test suite runs on PHP 7.2 to 8.5 in CI.
- A web server: Apache 2.4 with PHP-FPM or `mod_php`, or nginx with PHP-FPM. The Docker image uses Ubuntu 24.04 with PHP 8.3.
- Composer 2, with access to GitHub: the conversion library is installed from its Git repository (see [Installation](#installation)).
- At least one conversion backend that writes WEBP: libvips (`vips`), `cwebp`, Imagick or GD with WEBP support. `gif2webp` adds animated WEBP output and `avifenc` AVIF output.
- For process isolation: `proc_open()` enabled and `ext-posix`, which the library also needs to keep its capability cache. The library uses `prlimit` (util-linux) for resource limits and bubblewrap (`bwrap`) for its sandbox, when they are available.
- Recommended: `ext-apcu`, which keeps the rate limit and metrics counters in shared memory instead of files.

`composer check-env` reports what is missing on a given host.

## Installation

The service is a Composer project. It is not published on Packagist: install it from Git, at a release tag.

```bash
git clone https://github.com/mauriziofonte/php-image-to-webp-conversion-api.git /srv/image-conversion-api
cd /srv/image-conversion-api
git checkout <release-tag>
composer install --no-dev --optimize-autoloader
```

`composer.json` declares the conversion library's Git repository as a Composer [VCS repository](https://getcomposer.org/doc/05-repositories.md#vcs), so Composer downloads `mfonte/secure-image-converter` from GitHub. No `composer.lock` is committed: the dependencies are resolved for the PHP version that runs Composer, so run it with the same PHP version as the service. To avoid GitHub's anonymous API rate limit, give Composer a token, for example through the `COMPOSER_AUTH` environment variable:

```bash
export COMPOSER_AUTH='{"github-oauth": {"github.com": "<token>"}}'
```

After the install, Composer runs `bin/image-conversion-api check-env --informational`, which reports configuration problems without failing the install.

To build the Docker image instead, see [docs/deployment.md](docs/deployment.md#docker).

## Quick start

1. Create a configuration. Copy `.env.example` to `.env` at the root of the installation, or set the same keys as environment variables, and set an API key of at least 16 bytes:

   ```bash
   cp .env.example .env
   openssl rand -hex 32   # a new key; put it in API_KEY in .env
   ```

   Also set `V1_SCRIPT_NAME` to the 1.x endpoint name you need, or remove it to disable `/v1`.

2. Point the web server at `public/` (see [Deployment](#deployment)) and validate the setup:

   ```bash
   composer check-env
   ```

3. Convert an image:

   ```bash
   curl --fail -H "Authorization: Bearer $API_KEY" -H 'Content-Type: image/png' \
     --data-binary @photo.png -o photo.webp -D - \
     'https://images-api.example.com/v2/convert?format=webp&width=1200'
   ```

4. Check that the service is up, and with the key, what it can do:

   ```bash
   curl https://images-api.example.com/healthz
   curl -H "Authorization: Bearer $API_KEY" https://images-api.example.com/healthz
   ```

For local development over plain HTTP, set `REQUIRE_HTTPS=false`.

## Configuration

Every key is read from the environment first, then from the optional `.env` file at the root of the installation. In a PHP-FPM pool, pass variables with `env[KEY] = value` or `clear_env = no`; in Apache use `SetEnv`; in Docker use `environment:`. For every key, an unset variable, an empty value and the literal `null` mean "not set", and the default applies. Only the keys below are read; other variables are ignored, and values from `.env` are never copied into the process environment.

In `.env`, comments start with `#`; lines starting with `;`, the comments of the 1.x file, are skipped too. Quote values that contain spaces.

An invalid value makes every request, `/healthz` included, answer `503 invalid_configuration`; the reason is logged at critical level. `composer check-env` prints the same reasons.

| Key | Default | Meaning |
| --- | --- | --- |
| `API_KEY` | not set | One accepted API key, at least 16 bytes long (a multibyte character counts for each of its bytes), taken whole, commas included. May be given as `sha256:<64 hex digits>`, the SHA-256 digest of the key. |
| `API_KEYS` | not set | More accepted keys, separated by commas, each at least 16 bytes long or a `sha256:` digest. Use it for rotation: add the new key, move the clients, remove the old key. With neither `API_KEY` nor `API_KEYS`, every protected endpoint answers `503 authentication_not_configured`. |
| `METRICS_KEYS` | not set | Keys accepted on `/metrics` only, separated by commas, with the same rules. The API keys are accepted on `/metrics` too. |
| `REQUIRE_HTTPS` | `true` | Whether the protected endpoints refuse requests that did not arrive over HTTPS (`400 https_required`). Accepts `true`, `false`, `1`, `0`, `yes`, `no`, `on`, `off`. |
| `TRUSTED_PROXIES` | none | IPs and CIDR blocks (IPv4 and IPv6), separated by commas, of the reverse proxies whose `X-Forwarded-Proto` and `X-Forwarded-For` headers are believed. From any other peer the headers are ignored. |
| `IP_ALLOWLIST` | every client | IPs and CIDR blocks, separated by commas, of the clients allowed on the protected endpoints, in addition to the key (`403 ip_not_allowed` otherwise). |
| `V1_SCRIPT_NAME` | not set | Last path segment of the 1.x endpoint, served at `/v1/{V1_SCRIPT_NAME}`: letters, digits, `.`, `_`, `~` and `-`, not `.` or `..`. Not set: there is no `/v1` endpoint. |
| `V1_MAX_FILES` | `10` | Most images in one `/v1` request, 1 to 100. PHP's `max_file_uploads` must not be lower. |
| `MAX_BODY_BYTES` | `22020096` (21 MiB) | Largest request body of the conversion endpoints, in bytes. A larger `Content-Length`, or a multipart body above PHP's `post_max_size`, is refused with `413 body_too_large` before the body is read. |
| `MAX_IMAGE_BYTES` | `20971520` (20 MiB) | Largest image, in bytes, 1 to 1073741824 (`413 file_too_large`). |
| `CONVERSION_TIME_LIMIT` | `50` | Longest time, in whole seconds from 1 to 3600, that a conversion request may spend after PHP received it, waiting for a slot and converting. The library stops a conversion that would exceed it (`422 conversion_timeout`). Keep it below the timeouts of the web server, PHP-FPM and the client. |
| `CONCURRENCY_SLOTS` | `4` | Most conversions running at the same time on the host, 1 to 256, shared by every PHP process through lock files in `LOCK_DIR`. |
| `CONCURRENCY_WAIT` | `10` | Longest wait for a free slot, in whole seconds, from 0 (one attempt) to below `CONVERSION_TIME_LIMIT`; then `503 concurrency_limit` with `Retry-After`. When not set and `CONVERSION_TIME_LIMIT` is 10 or less, the default is `CONVERSION_TIME_LIMIT` minus 1. |
| `RATE_LIMIT` | `300` | Requests per minute per API key on the conversion endpoints, 0 to 1000000; `0` turns rate limiting off. Above it, `429 rate_limited` with `Retry-After`. |
| `RATE_LIMIT_BURST` | `50` | Requests a key may send at once after a pause, 1 to 1000000. |
| `RATE_LIMIT_STORE` | `auto` | Where the rate limit counters are kept: `apcu` (needs APCu enabled), `file` (in `LOCK_DIR`) or `auto` (APCu when it is enabled, otherwise files). |
| `METRICS_STORE` | `auto` | Where the `/metrics` counters are kept: `apcu`, `file` (in `LOCK_DIR`), `auto` or `off` (nothing is counted). |
| `TEMP_DIR` | system temporary directory | Absolute path of the directory that receives the per-request temporary directories. It must be outside the web root. |
| `LOCK_DIR` | `TEMP_DIR/image-conversion-api.locks` | Absolute path of the directory of the conversion slot lock files and of the file stores. It must be private to the PHP user, on a local filesystem and never cleaned while the service runs; it must not be `TEMP_DIR` or lie inside a directory of `TEMP_DIR` whose name starts with `image-conversion-api-`. Recommended: `/run/image-conversion-api`. |
| `CONVERTER_CACHE_DIR` | `<system temporary directory>/secure-image-converter-<uid>` | Absolute path of the directory where the library caches the backends and the isolation level it probed. Set it to a persistent writable directory when the root filesystem is read-only. |
| `LOG_FILE` | PHP's `error_log()` | Where the JSON log lines go: `stderr`, or the absolute path of a file whose directory exists. |
| `LOG_LEVEL` | `info` | Least severe level logged: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert` or `emergency`. |

`.env.example` documents every key with an example value.

## Usage

The service has four routes. Paths are matched exactly; an unknown path answers `404 not_found`, and a known path with another method answers `405 method_not_allowed` with an `Allow` header. A `HEAD` request gets the headers of the `GET` response without the body. Method overrides (`X-HTTP-Method-Override`, `_method`) are ignored.

| Path | Methods | Access |
| --- | --- | --- |
| `/v2/convert` | `POST` | API key |
| `/v1/{V1_SCRIPT_NAME}` | `POST` | API key; only when `V1_SCRIPT_NAME` is set |
| `/healthz` | `GET`, `HEAD` | public; more detail with an API key |
| `/metrics` | `GET`, `HEAD` | API key or one of `METRICS_KEYS` |

Every response carries `X-Request-Id`. The JSON responses also carry `Cache-Control: no-store` and `X-Content-Type-Options: nosniff`.

### Authentication

Every path except `/healthz`, unknown paths included, is protected. A protected request goes through these checks, in this order:

1. **HTTPS.** Unless `REQUIRE_HTTPS` is off, the request must have reached the service over HTTPS: the web server's `HTTPS` variable, or, from a peer listed in `TRUSTED_PROXIES`, an `X-Forwarded-Proto` whose every value means HTTPS. Otherwise: `400 https_required`.
2. **A key is configured.** Otherwise: `503 authentication_not_configured`.
3. **IP allowlist.** When `IP_ALLOWLIST` is set, the client address must be in it: `REMOTE_ADDR`, or behind `TRUSTED_PROXIES` the nearest `X-Forwarded-For` address that is not a trusted proxy. Otherwise: `403 ip_not_allowed`, before the key is looked at.
4. **The key.** The service reads the first of these that the request carries:
   1. `Authorization: Bearer <key>` (the scheme is case-insensitive; other schemes, such as `Basic`, do not count as a key);
   2. `X-API-Key: <key>`, the 1.x header;
   3. `-x-api-key: <key>`, the header that the 1.x `.env.example` documented and the 2.x releases of the proxy send.

   Only that first credential is checked. No key: `401 missing_api_key`. A key that is not configured: `401 invalid_api_key`. Both carry a `WWW-Authenticate: Bearer realm="image-conversion-api"` challenge, with `error="invalid_token"` for a key that is not accepted.

An unauthenticated client therefore cannot tell which paths exist. The presented key is compared with every configured key in constant time, over HMAC-SHA256 digests with a per-process random key, so the time taken does not reveal whether a key matched, which one, or its length.

**Keys as digests.** Any entry of `API_KEY`, `API_KEYS` and `METRICS_KEYS` may be the key's SHA-256 digest instead of the key, so that the configuration does not hold the key itself:

```bash
printf '%s' "$KEY" | sha256sum      # API_KEYS="sha256:<the 64 hex digits>"
```

An entry that starts with `sha256:` (in any case) but is not followed by exactly 64 hexadecimal digits makes the configuration invalid; it is never taken as a key. The 16-byte minimum cannot be checked for a key given as a digest.

### POST /v2/convert

Converts one image and answers the converted image as the body.

**Body.** Either the raw image (any `Content-Type` except a form, for example `image/png` or `application/octet-stream`), or `multipart/form-data` with the image as the only file, in the field `image`. An empty body is `400 missing_image`. An `application/x-www-form-urlencoded` body is `415 unsupported_media_type`. A multipart body with other files, or several files in `image`, is `400 invalid_request`. The raw body is copied to disk in chunks and never loaded in memory; a body without `Content-Length` is refused as soon as it exceeds `MAX_IMAGE_BYTES`.

**Parameters**, in the query string, or for a multipart body as form fields too (a parameter given in both places is `400 invalid_request`). They are parsed strictly: an unknown name or a value outside these spellings is `400 invalid_request`; for a known parameter, the message names it.

| Parameter | Values | Default |
| --- | --- | --- |
| `format` | `webp`, `avif`, `original` (keep the input format; resize or strip only) | `webp` |
| `quality` | integer 1 to 100 | per output format: 80 for WEBP, 60 for AVIF |
| `effort` | integer 0 (fastest) to 9 (smallest output) | `4` |
| `lossless` | boolean | `0` |
| `width`, `height` | integer 1 to 16383 | no constraint |
| `fit` | `contain`, `cover`, `fill` | `contain` |
| `enlarge` | boolean: allow upscaling | `0` |
| `strip` | boolean: strip metadata | `1` |
| `keep_icc` | boolean: keep the ICC color profile | `1` |
| `animation` | `preserve`, `first_frame`, `reject` | `preserve` |

Booleans are `1`, `0`, `true` or `false`. A valid request can still exceed the host's dimension, pixel or frame limits once the output size is resolved: that is `422 image_too_large`.

**Success.** `200` with the image as the body and these headers:

| Header | Value |
| --- | --- |
| `Content-Type` | MIME type of the output, from its content |
| `Content-Length` | size of the output in bytes |
| `Cache-Control` | `no-store` |
| `X-Content-Type-Options` | `nosniff` |
| `X-Conversion-Format` | output format: `webp`, `avif`, `jpeg`, `png`, `gif`, `bmp` or `tiff` |
| `X-Conversion-Requested-Format` | the `format` parameter: `webp`, `avif` or `original` |
| `X-Conversion-Fallback` | `1` when WEBP or AVIF was requested and the host produced another format, otherwise `0` |
| `X-Conversion-Fallback-Reason` | the library's warning about that fallback, only when `X-Conversion-Fallback` is `1` |
| `X-Conversion-Original-Kept` | `1` when the body is the input unchanged, because the converted file was not smaller and the input satisfied the request, otherwise `0` |
| `X-Conversion-Width`, `X-Conversion-Height` | output dimensions in pixels |
| `X-Conversion-Frames` | number of frames, `1` for a still image |
| `X-Conversion-Input-Bytes` | size of the input in bytes |
| `X-Conversion-Backend` | backend that produced the output (`none` when no backend ran) |
| `X-Conversion-Isolation` | isolation level the conversion ran under: `none`, `process`, `rlimit` or `sandbox` |
| `X-Conversion-Duration-Ms` | duration of the conversion in milliseconds |
| `X-Conversion-Warnings` | number of warnings |
| `X-Conversion-Warning` | one header line per warning, in order; absent when there are none |

Examples:

```bash
# Raw body, WEBP at most 1200 pixels wide
curl --fail -H "Authorization: Bearer $API_KEY" -H 'Content-Type: image/jpeg' \
  --data-binary @photo.jpg -o photo.webp \
  'https://images-api.example.com/v2/convert?width=1200'

# Multipart body, AVIF, parameters as form fields
curl --fail -H "Authorization: Bearer $API_KEY" \
  -F image=@photo.png -F format=avif -F quality=55 -o photo.avif \
  https://images-api.example.com/v2/convert
```

A client should store the body under the format named by `X-Conversion-Format`, not the one it requested: with a fallback, or when the original is kept, they differ.

### GET /healthz

`/healthz` is public and needs neither a key nor HTTPS.

- **Without a key** it answers `200 {"status":"ok"}`, without touching the converter or the disk: a liveness check for load balancers and orchestrators. It answers `503 invalid_configuration` when the configuration is invalid.
- **With an accepted key** (subject to `REQUIRE_HTTPS` and `IP_ALLOWLIST`, like a protected route) it builds the converter and adds its capabilities, in the library's `Capabilities::toArray()` schema, version 1: a readiness check, and what the proxy's remote mode reads.

  ```json
  {"status":"ok","capabilities":{"schema":1,"input_formats":["..."],"output_formats":["..."],
   "animated_output_formats":["..."],"backends":["..."],"isolation_level":"rlimit",
   "limits":{"max_bytes":20971520,"max_width":8192,"max_height":8192,"max_megapixels":40,
             "max_frames":300,"max_total_megapixels":400}}}
  ```

  The values depend on the host; the limits above are the library's defaults, with `max_bytes` set from `MAX_IMAGE_BYTES`. When the converter cannot be built (an unusable `LOCK_DIR`, for example), it answers `503 {"status":"unavailable","error":{"code","message"}}` with the code `/v2/convert` would answer, and logs the reason.

- **With a wrong key** it answers `401 invalid_api_key`.

The capabilities are kept out of the public form because they describe the host's backends, isolation level and limits.

### GET /metrics

The Prometheus text exposition (format 0.0.4) of the counters of every PHP process of the host. It needs an API key or one of `METRICS_KEYS`; give the scraper a metrics key, which cannot convert images.

| Metric | Type | Labels |
| --- | --- | --- |
| `image_conversion_api_up` | gauge | |
| `image_conversion_api_metrics_available` | gauge | |
| `image_conversion_api_requests_total` | counter | `route`, `status` |
| `image_conversion_api_request_duration_seconds` | histogram | `route` |
| `image_conversion_api_conversions_total` | counter | `route`, `outcome` |
| `image_conversion_api_conversion_duration_seconds` | histogram | `route` |

`route` is `v2_convert`, `v1`, `healthz`, `metrics`, or `none` when no route matched. `outcome` is `converted`, the library's error code, or `not_webp` for a `/v1` file whose output was not WEBP. The histogram buckets are 0.005, 0.025, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30 and 60 seconds. `metrics_available` is `0` when the store cannot be read; the counters are then missing from the scrape, not reset. With `METRICS_STORE=off` only the two gauges are rendered.

### Errors

Every error of the service has this body:

```json
{"error": {"code": "invalid_api_key", "message": "The API key is not valid."}}
```

The `code` is stable and machine-readable; the `message` is a fixed sentence for humans that may change and never contains request data. The status follows the code:

| Status | Code | Meaning |
| --- | --- | --- |
| 400 | `https_required` | A protected path was called over plain HTTP. |
| 400 | `invalid_request` | A parameter is unknown, malformed, or given twice; or a multipart body carries other files. |
| 400 | `missing_image` | The request carries no image, or its upload did not complete. |
| 401 | `missing_api_key` | No key was sent. `WWW-Authenticate` is set. |
| 401 | `invalid_api_key` | The key is not accepted. `WWW-Authenticate` is set. |
| 403 | `ip_not_allowed` | `IP_ALLOWLIST` is set and the client address is not in it. |
| 404 | `not_found` | No endpoint has this path. |
| 405 | `method_not_allowed` | The endpoint does not answer this method. `Allow` lists the methods. |
| 413 | `body_too_large` | The declared body exceeds `MAX_BODY_BYTES`, or a multipart body exceeds PHP's `post_max_size`. |
| 413 | `file_too_large` | The image exceeds `MAX_IMAGE_BYTES`. |
| 415 | `unsupported_format` | The image format is not supported. |
| 415 | `unsupported_media_type` | The body is a URL-encoded form. |
| 422 | `invalid_image` | The image is malformed, truncated, or not of the format its content claims. |
| 422 | `animation_rejected` | The image is animated and the request has `animation=reject`. |
| 422 | `image_too_large` | The image or the requested output exceeds a dimension, pixel or frame limit. |
| 422 | `conversion_timeout` | The conversion would exceed the time left within `CONVERSION_TIME_LIMIT`. |
| 422 | `resource_limit` | The conversion exceeded its memory, CPU time or file size limit. |
| 429 | `rate_limited` | The key exceeded `RATE_LIMIT`. `Retry-After` is set. |
| 500 | `conversion_failed` | The image could not be converted. |
| 500 | `output_verification_failed` | The conversion output failed its verification. |
| 500 | `internal_error` | An unexpected error; the details are in the log. |
| 503 | `invalid_configuration` | The configuration is invalid; the reason is in the log. |
| 503 | `authentication_not_configured` | No API key is configured. |
| 503 | `isolation_unavailable` | The isolation that conversions require is not available on this host. |
| 503 | `concurrency_limit` | No conversion slot became free within `CONCURRENCY_WAIT`. `Retry-After` is set. |
| 503 | `concurrency_unavailable` | The conversion slots in `LOCK_DIR` cannot be taken. `Retry-After: 1`. |
| 503 | `rate_limit_unavailable` | The rate limit store cannot be updated, so the request is refused rather than let through uncounted. `Retry-After: 1`. |

The library's codes (`invalid_request`, `unsupported_format`, `invalid_image`, `animation_rejected`, `file_too_large`, `image_too_large`, `conversion_timeout`, `resource_limit`, `isolation_unavailable`, `concurrency_limit`, `conversion_failed`, `output_verification_failed`, `internal_error`) keep the meaning they have in the library; a code that this version does not know is answered as `500 internal_error`. The `/v1` endpoint answers some errors in its 1.x body instead; see below.

## The 1.x endpoint

`POST /v1/{V1_SCRIPT_NAME}` serves the 1.x request (`images[]`, `descriptors`, the `m` and `lossless` options) and the 1.x JSON document, base64 images included, for existing 1.x clients. It exists only when `V1_SCRIPT_NAME` is set.

New clients should use [`POST /v2/convert`](#post-v2convert), which converts one image per request, answers the image as the response body and offers AVIF, resizing and more options; [UPGRADING.md](UPGRADING.md) describes the move.

**Request.** `multipart/form-data` with the images in `images[]` (a single `images` file is accepted too), with the extensions `jpg`, `jpeg`, `png`, `gif` and `bmp`, and an optional `descriptors` field holding a JSON array of objects. Each object whose `filename` matches an uploaded file is echoed in that file's entry. Query string options:

- `m` (0 to 6, default 4) is cwebp's method, mapped to the library's `effort`; `lossless` (`1` or `true`) enables lossless encoding;
- `pass`, `near_lossless`, `hint` and `jpeg_like` are accepted and ignored; nothing is refused here, unlike on `/v2/convert`;
- the output is always WEBP at quality 75, cwebp's default, with metadata stripped.

```bash
curl -H "Authorization: Bearer $API_KEY" \
  -F 'images[]=@wallpaper_1.jpg' -F 'images[]=@wallpaper_2.png' \
  -F 'descriptors=[{"filename":"wallpaper_1.jpg","file_id":"1"}]' \
  'https://images-api.example.com/v1/convert-now?m=6'
```

**Response.** The 1.x document, with `application/json; charset=utf-8`:

```json
{"status":true,"version":"1.0.0","elapsed_time":"0.81s","response":[
  {"filename":"wallpaper_1.jpg","file_id":"1","status":true,"orig_filesize":"375.5 kB",
   "new_filesize":"146.8 kB","compression_ratio":60.9,"webp_image_base64":"UklGR..."},
  {"filename":"wallpaper_2.png","status":false,"error":"The WEBP image would not be smaller than the original."}]}
```

A file that fails has `"status": false` and an `error`: the 1.x messages for upload errors and for an unsupported extension (`Unsupported file extension.`), `The uploaded file exceeds the maximum file size.` above `MAX_IMAGE_BYTES`, `Conversion failed.` for a server-side failure, and the fixed sentence of the error code for a rejected image (see [Errors](#errors)). A file whose WEBP would not be smaller than the original fails with `The WEBP image would not be smaller than the original.`, so that the client keeps its original; 1.x returned the larger WEBP.

**Limits.** At most `V1_MAX_FILES` images, each at most `MAX_IMAGE_BYTES`, the whole body at most `MAX_BODY_BYTES`, all converted within one `CONVERSION_TIME_LIMIT`, with the conversion slots shared with `/v2/convert`. Each image costs one rate limit token, as one `/v2/convert` request does; a request without images costs one, and a batch larger than `RATE_LIMIT_BURST` costs the burst. PHP's `max_file_uploads` must be at least `V1_MAX_FILES`: PHP drops the files above it without an error.

**Refusals.** A request refused as a whole keeps the 1.x body, `{"status":false,"version":"1.0.0","message":"..."}`, with a status other than 200:

| Status | Message | When |
| --- | --- | --- |
| 400 | `No images uploaded.` | The request has no `images` files. |
| 400 | `Invalid descriptors JSON.` | `descriptors` is not a JSON array. |
| 413 | `Too many images: send at most N per request.` | More than `V1_MAX_FILES` images. |
| 503 | the fixed sentence of the error | No conversion slot became free, the slots cannot be taken, or the isolation is unavailable. `Retry-After` is set when the error carries it. |

The checks that run before the endpoint (HTTPS, authentication, the IP allowlist, the rate limit and the body size) answer in the 2.x body, `{"error":{"code","message"}}`, as described in [Errors](#errors).

## Deployment

The front controller is `public/index.php`. Route every request to it and serve no other file of the installation.

- **Apache** (PHP-FPM through `mod_proxy_fcgi`, or `mod_php`): make `public/` the document root; its `.htaccess` hands every request to `index.php` and passes the `Authorization` header to PHP. When the installation directory itself is served, as 1.x was installed (`https://example.com/webp-converter/`), the root `.htaccess` hands every request to `public/index.php` and keeps the routes below that prefix (`/webp-converter/v2/convert`). Both need `mod_rewrite` and `AllowOverride FileInfo Indexes Options=Indexes,MultiViews` (or `AllowOverride All`).
- **nginx**: `deploy/nginx/image-conversion-api.conf` and `deploy/nginx/upstream.conf` (TLS, `client_max_body_size`, every request to `public/index.php`).
- **Docker**: `docker/Dockerfile` builds a PHP-FPM image with every backend, and `docker/compose.yaml` runs it non-root, with a read-only root filesystem and no capabilities, behind nginx.

[docs/deployment.md](docs/deployment.md) covers each of them, the `Authorization` header with Apache (`CGIPassAuth` or `HTTP_AUTHORIZATION`), PHP settings, writable directories, sizing and the isolation levels in Docker.

Whatever the web server, keep its timeouts and PHP-FPM's `request_terminate_timeout` above `CONVERSION_TIME_LIMIT`, set PHP's `post_max_size` and `upload_max_filesize` to at least `MAX_BODY_BYTES` and `MAX_IMAGE_BYTES`, and turn `display_errors` off in the PHP configuration: the service turns it off itself as soon as it runs, but PHP can raise an error before that, such as for a body above `post_max_size`.

## Operations

**check-env.** `composer check-env` (or `php bin/image-conversion-api check-env`) validates the configuration with the rules the service applies at boot, then the host, and exits with 1 on any error.

- Errors: no API key; `TEMP_DIR` not a writable directory; `LOCK_DIR`, `CONVERTER_CACHE_DIR` or the directory of `LOG_FILE` neither writable nor creatable; a converter that cannot be built, or no backend that writes WEBP.
- Warnings: `ext-posix` missing; `LOCK_DIR` under `/tmp` or `/var/tmp`; `REQUIRE_HTTPS` off; the isolation level `none` (child processes without process group or resource limits); keys configured as themselves rather than as digests while conversions run without the sandbox; `post_max_size`, `upload_max_filesize` or `max_file_uploads` below the service's limits; an isolation level cached in `CONVERTER_CACHE_DIR` that differs from the one probed now.

Run it as the PHP-FPM user: it checks the directories and the converter as the user that runs it, and the library skips the cache files of other users. It creates nothing, because it often runs as another user than PHP-FPM (root during `composer install`), and it reads the php.ini of the PHP that runs it (the CLI's), which may differ from PHP-FPM's. With `--informational`, as the Composer install hooks run it, it always exits with 0.

**Logs.** One JSON object per line, with the fields always in this order:

```json
{"timestamp":"2026-10-30T10:00:00.123456+00:00","level":"info","channel":"image-conversion-api","request_id":"4bf92f3577b34da6","message":"Request handled","context":{"method":"POST","route":"v2_convert","status":200,"duration_ms":412}}
```

- Every request ends with one `info` entry, `Request handled`, with the method, the route name, the status and the duration. The path itself is never logged, since the `/v1` path holds `V1_SCRIPT_NAME`.
- Refusals are logged at `info`, server-side failures at `error` with the exception. An API error code is logged under `context.error_code`, a library error code under `context.conversion_error_code`, with the library's detailed message under `context.reason`.
- PHP warnings and notices raised while a request runs are logged, never printed; stray output is discarded and its size logged.
- `LOG_FILE=stderr` writes to the standard error stream, which is the container log behind PHP-FPM with `catch_workers_output = yes` and `decorate_workers_output = no`. The default, PHP's `error_log()`, may prefix the lines.

**Request IDs.** The service adopts the `X-Request-Id` the client sends when it is 1 to 128 characters among letters, digits and `._:/+=@-`; otherwise it generates 32 hexadecimal digits. The ID is logged as `request_id` and sent back in `X-Request-Id`, so the proxy's log entries and the service's correlate.

**Temporary files.** Each request gets its own directory under `TEMP_DIR`, named `image-conversion-api-*`, removed when the request ends, after a fatal error too. Directories left by a killed process are removed once nobody has changed them for an hour (or twice `CONVERSION_TIME_LIMIT`, whichever is longer).

**Lock directory.** `LOCK_DIR` holds the conversion slots and, with the `file` stores, the rate limit and metrics counters. It is created with mode 0700 and refused when other users can write to it. Use `/run/image-conversion-api`, owned by the PHP user, rather than a directory under `/tmp`, which systemd-tmpfiles cleans up by age; see [docs/deployment.md](docs/deployment.md#writable-directories).

**Rate limiting.** A token bucket per API key: `RATE_LIMIT` requests per minute on average, up to `RATE_LIMIT_BURST` at once. A refused request does not consume a token, and `Retry-After` is the time until the next token, in whole seconds. Only the conversion endpoints are rate-limited. APCu counts per PHP-FPM master, so several pools or hosts each count on their own; the `file` store is shared by every PHP process of the host. The limiter trusts the host clock: if the clock steps back, a key that spent its burst waits for the length of the step, and a `LOCK_DIR` shared by several hosts needs their clocks synchronized.

**Concurrency.** At most `CONCURRENCY_SLOTS` conversions run at once on the host; a request waits up to `CONCURRENCY_WAIT` seconds for a slot, then gets `503 concurrency_limit`. When the slots cannot be taken at all (an unusable `LOCK_DIR`), requests are refused with `503 concurrency_unavailable`, never converted beyond the limit; fixing `LOCK_DIR` needs no restart.

## Security model

The service accepts untrusted images from authenticated clients. Its defenses, from the outside in:

- **Transport.** Protected routes require HTTPS by default. `X-Forwarded-Proto` and `X-Forwarded-For` are believed only from `TRUSTED_PROXIES`, so a client cannot claim HTTPS or an allowed address.
- **Authentication** fails closed, compares keys in constant time, checks only the first credential a request carries, and accepts keys only from headers, never from the query string. Keys may be configured as SHA-256 digests.
- **Request handling.** Parameters are parsed strictly. Sizes are checked before the body is read. Error bodies never echo request data, and the library's detailed messages go to the log only. Unknown paths need a key, so they reveal nothing. `/healthz` publishes the host's capabilities only to an authenticated client.
- **Files.** Uploads and outputs live in a per-request directory under `TEMP_DIR`, which must be outside the web root, and are removed when the request ends. The shipped web server configurations serve no file of the installation.
- **Conversion.** The library validates the input, enforces dimension, pixel, frame and size limits, and runs decoders and encoders in separate processes with resource limits (`rlimit`), or in a bubblewrap sandbox without network access (`sandbox`) where the host allows it. The level reached is reported in `X-Conversion-Isolation`, in `/healthz` with a key and by `check-env`.

**Isolation levels.** Without the `sandbox` level, a decoder that a hostile image takes over runs as the PHP-FPM user, in the same PID namespace and with the same network. It can then read what that user can: `.env`, the PHP-FPM environment and, unless the host's Yama `ptrace_scope` is 1 or more, PHP-FPM's memory, which holds the keys of the requests being served; the files of the requests in progress, `LOCK_DIR` and `CONVERTER_CACHE_DIR`. It can also signal the workers and reach whatever the host network reaches. Under Docker's default seccomp profile bubblewrap cannot create its namespaces, so the shipped Compose file runs at `rlimit`. To narrow the exposure:

- configure the keys as `sha256:` digests, so that the configuration holds no key;
- give the service a network of its own;
- use the `sandbox` level where possible; for Docker, `docker/compose.sandbox.yaml` enables it (see [docs/deployment.md](docs/deployment.md#sandbox-isolation-in-docker)).

The test suite includes the library's corpus of hostile images, posted to `/v2/convert` in process and, in CI, over HTTP to the Docker image. This is a description of what the code does and what is tested, not a security certification.

## Performance

No benchmark figures are published for the service. Conversion time depends on the backend, the image and the host, and is reported per request in `X-Conversion-Duration-Ms` and in the `image_conversion_api_conversion_duration_seconds` histogram.

Design properties:

- request bodies are streamed to disk and responses streamed from disk, so the memory of a PHP worker does not grow with the size of the image; `/v1` encodes its base64 output in chunks while it is sent. `tests/Functional/Load/LoadTest.php` checks this;
- the backends and isolation level probed by the library are cached in `CONVERTER_CACHE_DIR`; without a writable cache, or without `ext-posix`, every PHP process probes them again, which takes seconds;
- reading and validating the configuration is done on every request, without a compiled cache.

## Testing and development

```bash
composer install                # with the development dependencies
composer test                   # PHPUnit: unit, functional and integration suites
```

The integration suite is skipped unless the Docker harness starts the web servers:

```bash
tests/Integration/docker/run.sh --php 8.5 --mode fpm     # Apache + PHP-FPM and nginx + PHP-FPM
tests/Integration/docker/run.sh --php 8.5 --mode image   # the Docker image behind nginx
```

Static analysis and coding style use tools installed in `tools/` (PHP 7.4 or later; Psalm needs PHP 8.1 or later):

```bash
for tool in tools/*/; do composer install --working-dir="$tool"; done
composer cs:check   # php-cs-fixer, PSR-12 plus the project rules
composer analyse    # PHPStan at level max, as PHP 7.2.5 and as PHP 8.5
composer taint      # Psalm taint analysis of src/
composer ci         # cs:check, analyse and test
```

CI runs the test suite on PHP 7.2 to 8.5 with the lowest and the highest dependencies, the static analysis, the web server integration on PHP 7.2, 8.1 and 8.5, and the Docker image build and tests. [CONTRIBUTING.md](CONTRIBUTING.md) describes the workflow.

## Versioning and compatibility

The project follows [Semantic Versioning](https://semver.org/). The public interface is the HTTP contract: the routes, the parameters, the response headers, the error codes and their statuses, the `/healthz` capabilities schema, the metric names, the log fields and the configuration keys. [CHANGELOG.md](CHANGELOG.md) lists the changes, and [UPGRADING.md](UPGRADING.md) explains how to move from 1.x to 2.0.

## Contributing

Contributions are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) for the development setup, the checks a pull request must pass and the commit message convention.

## Reporting security issues

Do not open a public issue for a vulnerability. Report it privately through GitHub's private vulnerability reporting, as described in [SECURITY.md](SECURITY.md).

## License

The BSD 3-Clause License. See [LICENSE](LICENSE). Copyright (c) 2020, Maurizio Fonte.
