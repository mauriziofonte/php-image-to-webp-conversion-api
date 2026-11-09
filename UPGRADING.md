# Upgrading

## From 1.x to 2.0

2.0 replaces the 1.x script, `process.php`, with a Composer project. The 1.x request and response are still served, at a new URL, so existing clients keep working once pointed at it; moving them to `POST /v2/convert` is optional.

### Contents

- [1. Install 2.0](#1-install-20)
- [2. Configure the web server](#2-configure-the-web-server)
- [3. Update the configuration](#3-update-the-configuration)
- [4. Point 1.x clients at the new URL](#4-point-1x-clients-at-the-new-url)
- [5. What changes for 1.x clients](#5-what-changes-for-1x-clients)
- [6. Move clients to POST /v2/convert](#6-move-clients-to-post-v2convert)

### 1. Install 2.0

| | 1.x | 2.0 |
| --- | --- | --- |
| PHP | any version that ran `process.php` | 7.2.5 to 8.5, 64-bit |
| Installation | copy the files | `git clone`, then `composer install --no-dev` |
| Converter | the `cwebp` binary, through `exec()` | the mfonte/secure-image-converter library, with any of libvips, cwebp, gif2webp, avifenc, Imagick and GD, in isolated processes |

`exec()` is no longer needed; the library runs its backends with `proc_open()`. Follow [Installation](README.md#installation) in the README, then run `composer check-env`, which reports missing backends, extensions and `php.ini` limits.

### 2. Configure the web server

`process.php` is gone. The front controller is `public/index.php`, and the web server must route every request to it.

- **Apache, document root at `public/`** (recommended): `public/.htaccess` does the routing and passes the `Authorization` header to PHP-FPM.
- **Apache, installation directory served as is**, as 1.x was installed (for example `https://example.com/webp-converter/`): the new root `.htaccess` replaces the 1.x one. It hands every request to `public/index.php`, serves no file of the installation, and keeps the routes below the directory's URL.
- **nginx**: use `deploy/nginx/`.

Both Apache layouts need `mod_rewrite` and `AllowOverride FileInfo Indexes Options=Indexes,MultiViews` (or `AllowOverride All`): the shipped `.htaccess` files set `Options` and `DirectoryIndex`. See [docs/deployment.md](docs/deployment.md).

### 3. Update the configuration

The `.env` file of 1.x mostly keeps working; change these:

- **Rename `SCRIPT_NAME` to `V1_SCRIPT_NAME`.** 2.0 does not read `SCRIPT_NAME`, which the web server sets for every request as the path of the front controller. Without `V1_SCRIPT_NAME` there is no `/v1` endpoint, and 1.x clients get `404 not_found`.
- **Check the length of `API_KEY`.** Every key must be at least 16 bytes long; a shorter key makes the configuration invalid, and every request is answered `503 invalid_configuration`. Generate a new key (`openssl rand -hex 32`) and give it to the clients if needed.
- **Comments and quoting.** Comments now start with `#`; the `;` comments of a 1.x file are still skipped. Quote any value that contains spaces.
- **The environment wins.** Each key is read from the environment first (`env[KEY]` in a PHP-FPM pool, `SetEnv` in Apache, `environment:` in Docker), then from `.env`.

Every other key is new and optional; the README lists them with their defaults. Review at least these:

- `REQUIRE_HTTPS` (default `true`), as 1.x required HTTPS too. Behind a reverse proxy or load balancer that terminates TLS, list it in `TRUSTED_PROXIES`, or every protected request is refused with `400 https_required`.
- `TEMP_DIR` and `LOCK_DIR`. 1.x wrote its temporary files to `temp/` inside the installation. 2.0 uses the system temporary directory by default, which must be outside the web root; set `LOCK_DIR` to `/run/image-conversion-api`.
- `RATE_LIMIT` (default 300 requests per minute per key, burst 50) and `V1_MAX_FILES` (default 10), which did not exist in 1.x.

Then run `composer check-env`.

### 4. Point 1.x clients at the new URL

The 1.x endpoint moved below `/v1/`:

| Layout | 1.x URL | 2.0 URL |
| --- | --- | --- |
| Installed in a served subdirectory (root `.htaccess`) | `https://example.com/webp-converter/<SCRIPT_NAME>` | `https://example.com/webp-converter/v1/<V1_SCRIPT_NAME>` |
| Installed at the document root (2.0: document root at `public/`) | `https://example.com/<SCRIPT_NAME>` | `https://example.com/v1/<V1_SCRIPT_NAME>` |

1.x answered any path whose last segment was `SCRIPT_NAME`. 2.0 matches the path exactly; any other path is `404 not_found` for an authenticated client.

**The key header.** 1.x read the key from `X-API-Key`, while its `.env.example` told clients to send `-x-api-key`, which is what the 2.x releases of the proxy (mfonte/proxy-cdn) send. 2.0 accepts the key from the first of these that a request carries, and checks only that one:

1. `Authorization: Bearer <key>`;
2. `X-API-Key: <key>`;
3. `-x-api-key: <key>`.

Existing clients therefore keep working with either header. A request that carries both a wrong `X-API-Key` and the right `-x-api-key` is refused, because only `X-API-Key` is checked. New clients should send `Authorization: Bearer`. With Apache, the shipped `.htaccess` files pass that header to PHP-FPM; see [The Authorization header](docs/deployment.md#the-authorization-header).

### 5. What changes for 1.x clients

The request format and the success document are the same: `images[]`, `descriptors`, the `m` and `lossless` options, and `{"status":true,"version":"1.0.0","elapsed_time","response":[...]}` with the images in base64. These answers differ:

| Situation | 1.x | 2.0 at `/v1/` |
| --- | --- | --- |
| Missing or wrong key | `401` `{"status":false,"version":"1.0.0","message":"Invalid API key provided."}` | `401` `{"error":{"code":"missing_api_key" or "invalid_api_key","message"}}` |
| Plain HTTP | `400` `{"status":false,...,"message":"HTTPS is required."}` | `400` `{"error":{"code":"https_required","message"}}` |
| No images | `200`, `"status": false`, `No images uploaded.` | `400`, the same 1.x body |
| Invalid `descriptors` | `200`, `"status": false`, `Invalid descriptors JSON.` | `400`, the same 1.x body |
| More than `V1_MAX_FILES` images | converted | `413`, 1.x body, `Too many images: send at most N per request.` |
| No conversion slot, or isolation unavailable | (no such limit) | `503` with `Retry-After` where available, 1.x body |
| Rate limit or body size exceeded | (no such limit) | `429 rate_limited` or `413 body_too_large`, 2.x body |
| WEBP not smaller than the original | the larger WEBP | the file fails: `The WEBP image would not be smaller than the original.` |
| Image larger than `MAX_IMAGE_BYTES` | converted, within PHP's upload limits | the file fails: `The uploaded file exceeds the maximum file size.` |
| `pass`, `near_lossless`, `hint`, `jpeg_like` | passed to `cwebp` | accepted and ignored |

What stays the same: the output is WEBP at quality 75, cwebp's default; `m` (0 to 6) sets the compression effort and `lossless` (`1` or `true`) lossless encoding; the per-file error messages for upload errors and unsupported extensions (`jpg`, `jpeg`, `png`, `gif`, `bmp` are accepted); the size format of `orig_filesize` and `new_filesize`; `compression_ratio`.

A client that only checks the top-level `status` key must also treat a non-200 status, or a body with an `error` object, as a failure. See [The 1.x endpoint](README.md#the-1x-endpoint).

### 6. Move clients to POST /v2/convert

`/v1` is not deprecated and stays available; move a client when it needs what only `/v2/convert` offers. `POST /v2/convert` converts one image per request and answers the image itself:

| 1.x | `/v2/convert` |
| --- | --- |
| One multipart request with `images[]` | One request per image: the raw body, or the multipart field `image` |
| `descriptors` echoed in the response | Correlate on the client side; an `X-Request-Id` the client sends is echoed back |
| Base64 image in a JSON document | The image is the response body; `Content-Type` and `X-Conversion-Format` name its format |
| `m=0` to `m=6` | `effort=0` to `effort=9` (default 4); the 1.x method `m` corresponds to `effort` = round(`m` x 9 / 6) |
| `lossless=1` | `lossless=1` |
| Quality 75 | `quality` 1 to 100, default 80 for WEBP; pass `quality=75` to keep the 1.x quality |
| `orig_filesize`, `new_filesize`, `compression_ratio` | `X-Conversion-Input-Bytes` and `Content-Length` |
| `"status": false` with an `error` per file | An HTTP error status with `{"error":{"code","message"}}`; see [Errors](README.md#errors) |
| WEBP always returned | `X-Conversion-Original-Kept: 1` with the input as the body when the conversion was not smaller; `X-Conversion-Fallback: 1` when the host produced another format |
| Unknown or invalid options ignored | `400 invalid_request` |

`/v2/convert` also offers AVIF output (`format=avif`), resizing (`width`, `height`, `fit`, `enlarge`), metadata and ICC profile control (`strip`, `keep_icc`) and an animation policy (`animation`). Example:

```bash
curl --fail -H "Authorization: Bearer $API_KEY" -H 'Content-Type: image/jpeg' \
  --data-binary @wallpaper_1.jpg -o wallpaper_1.webp -D headers.txt \
  'https://images-api.example.com/v2/convert?quality=75'
```

Read the format of the saved file from `X-Conversion-Format`, not from the request: it differs when the original is kept or the host falls back to another format.
