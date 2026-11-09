# Deployment

This guide covers running the Image Conversion API behind Apache, behind nginx, and in Docker. The [README](../README.md) describes the HTTP interface and the configuration keys.

## Contents

- [Principles](#principles)
- [PHP and PHP-FPM settings](#php-and-php-fpm-settings)
- [Apache](#apache)
- [nginx](#nginx)
- [Docker](#docker)
- [Writable directories](#writable-directories)
- [Sizing](#sizing)
- [Behind a reverse proxy or load balancer](#behind-a-reverse-proxy-or-load-balancer)

## Principles

- `public/index.php` is the front controller. The web server routes every request to it and serves no other file of the installation: not `.env`, `vendor/`, `src/` nor `README.md`.
- The service matches its routes (`/v2/convert`, `/v1/{V1_SCRIPT_NAME}`, `/healthz`, `/metrics`) on the request path. Give it a host of its own, or, with Apache, the installation directory as described below.
- The configuration comes from environment variables first, then from the optional `.env` file at the root of the installation.
- Run `composer check-env` as the PHP-FPM user after every configuration change.

## PHP and PHP-FPM settings

| Setting | Value |
| --- | --- |
| `post_max_size` | at least `MAX_BODY_BYTES` (default 21 MiB) |
| `upload_max_filesize` | at least `MAX_IMAGE_BYTES` (default 20 MiB) |
| `max_file_uploads` | at least `V1_MAX_FILES` (default 10) when `/v1` is enabled |
| `display_errors`, `display_startup_errors` | `Off` |
| PHP-FPM `request_terminate_timeout` | above `CONVERSION_TIME_LIMIT` (default 50 s) |

The service turns `display_errors` off as soon as it runs and logs PHP errors instead of printing them. PHP can raise an error before the service runs, for example for a body above `post_max_size`, and that error follows the PHP configuration. The shipped Apache and nginx configurations therefore turn it off for PHP too (`PHP_ADMIN_VALUE`, or `php_flag` for `mod_php`), and so does the Docker image's pool (`php_admin_flag`). Do the same in any other configuration, or set `display_errors = Off` in `php.ini`.

PHP-FPM clears the environment of its workers by default (`clear_env = yes`). To configure the service through environment variables, pass each key in the pool, or keep the environment:

```ini
; /etc/php/8.3/fpm/pool.d/image-conversion-api.conf (excerpt)
env[API_KEYS] = "sha256:<64 hex digits>"
env[LOCK_DIR] = /run/image-conversion-api
; or: clear_env = no
```

Otherwise use the `.env` file. Each conversion runs in a separate process with its own limits, so the PHP worker's `memory_limit` does not need to cover the image: the Docker image uses `256M`.

## Apache

Apache 2.4 with `mod_rewrite`, and PHP-FPM through `mod_proxy_fcgi` (or `mod_php`). Two layouts are supported, each with a shipped `.htaccess`.

### Document root at `public/` (recommended)

`public/.htaccess` hands every request to `index.php`. It needs `AllowOverride FileInfo Indexes Options=Indexes,MultiViews` (or `AllowOverride All`): the file sets `Options -Indexes -MultiViews` and `DirectoryIndex disabled`, which Apache refuses with a `500` under `AllowOverride FileInfo` alone. The same line also covers the `php_flag` lines read by `mod_php`. Without `mod_rewrite`, every request is refused.

```apache
<VirtualHost *:443>
    ServerName images-api.example.com
    DocumentRoot /srv/image-conversion-api/public

    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/images-api.example.com.pem
    SSLCertificateKeyFile /etc/ssl/private/images-api.example.com.key

    <Directory /srv/image-conversion-api/public>
        AllowOverride FileInfo Indexes Options=Indexes,MultiViews
        Require all granted
    </Directory>

    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php-fpm-image-conversion-api.sock|fcgi://localhost"
    </FilesMatch>
</VirtualHost>
```

`mod_ssl` sets the `HTTPS` variable that `REQUIRE_HTTPS` checks. Redirect plain HTTP to HTTPS, or do not serve it: API keys must not travel in clear text. Redirect to a fixed name, such as `Redirect permanent / https://images-api.example.com/` in the `*:80` virtual host of that name, never to a URL built from the request's `Host`.

### Installation directory served as is

Where the installation directory itself is served, as 1.x was installed (`https://example.com/webp-converter/`), the root `.htaccess` hands every request to `public/index.php` and serves no file of the installation. It passes the URL path of the directory to the service in the `IMAGE_CONVERSION_API_BASE` variable, so the routes answer below that prefix: `https://example.com/webp-converter/v2/convert`, `https://example.com/webp-converter/v1/<V1_SCRIPT_NAME>`. It has the same requirements: `mod_rewrite` and `AllowOverride FileInfo Indexes Options=Indexes,MultiViews` (or `AllowOverride All`). Prefer the `public/` document root where the hosting allows it; `TEMP_DIR` must still be outside the served directory.

### The Authorization header

Apache does not pass the `Authorization` header to CGI or PHP-FPM by default. Both shipped `.htaccess` files pass it with a rewrite rule:

```apache
RewriteCond %{HTTP:Authorization} .+
RewriteRule .* - [E=HTTP_AUTHORIZATION:%0]
```

After the internal redirect to the front controller, the variable may reach PHP as `REDIRECT_HTTP_AUTHORIZATION`; the service reads both. Alternatively, on Apache 2.4.13 or later, enable `CGIPassAuth On` in the `<Directory>` block, and PHP receives `HTTP_AUTHORIZATION` directly. Clients that send the key in `X-API-Key` need neither: every web server passes that header.

### Timeouts

Apache's `Timeout` and `ProxyTimeout` default to 60 seconds. Keep them, and PHP-FPM's `request_terminate_timeout`, above `CONVERSION_TIME_LIMIT`, so that the service answers its own `422 conversion_timeout` before the web server gives up on the request.

## nginx

`deploy/nginx/image-conversion-api.conf` holds a TLS server and a plain HTTP server that only redirects; `deploy/nginx/upstream.conf` names the PHP-FPM pool. Include both in the `http` block, then adapt `server_name`, the certificate paths, `root` and the upstream:

```bash
cp deploy/nginx/image-conversion-api.conf deploy/nginx/upstream.conf /etc/nginx/conf.d/
nginx -t && systemctl reload nginx
```

What the configuration does:

- every request goes to `public/index.php` (`SCRIPT_FILENAME`), so no file of the installation is ever served;
- `client_max_body_size 22m`: `MAX_BODY_BYTES` plus room for the multipart framing; raise both together;
- `fastcgi_param HTTPS $https if_not_empty`: tells the service that the request arrived over TLS;
- `fastcgi_param HTTP_PROXY ""`: a `Proxy` request header does not become `HTTP_PROXY`;
- `fastcgi_param PHP_ADMIN_VALUE`: turns `display_errors` and `display_startup_errors` off for PHP;
- `fastcgi_read_timeout 60s`: above `CONVERSION_TIME_LIMIT`;
- the plain HTTP server redirects with `301` to `https://` and its own `server_name` (the first name, so keep one per server block), with `Cache-Control: no-store`; a request for any other `Host`, which reaches it because it is the only server on port 80, gets `421` and no redirect.

nginx passes the `Authorization` header to PHP-FPM as `HTTP_AUTHORIZATION` without further configuration. The service must be the whole host, or a location of its own that passes the original `REQUEST_URI`.

## Docker

`docker/Dockerfile` builds a PHP-FPM image (FastCGI on port 9000) on Ubuntu 24.04 with PHP 8.3 and every backend of the library: libvips, cwebp and gif2webp, avifenc, Imagick and GD, plus `prlimit` and bubblewrap. It runs as uid 10001, needs no capability and no privilege escalation, and writes only to `TEMP_DIR` (`/tmp`), `LOCK_DIR` (`/run/image-conversion-api`) and `CONVERTER_CACHE_DIR` (`/var/cache/image-conversion-api`), so its root filesystem can be read-only. `ext-apcu` and `ext-posix` are included. A `HEALTHCHECK` asks `/healthz` through FastCGI.

### Build

From the root of the repository:

```bash
docker build -f docker/Dockerfile -t image-conversion-api .
```

Composer installs the dependencies in a build stage. An optional `composer_auth` build secret, holding the JSON of `COMPOSER_AUTH`, gives it a GitHub token without leaving it in a layer:

```bash
COMPOSER_AUTH='{"github-oauth": {"github.com": "<token>"}}' \
  docker build -f docker/Dockerfile --secret id=composer_auth,env=COMPOSER_AUTH -t image-conversion-api .
```

To reuse a `vendor/` installed beforehand for PHP 8.3 without development dependencies (`composer install --no-dev`), pass it as a named build context:

```bash
docker build -f docker/Dockerfile --build-arg VENDOR_SOURCE=context \
  --build-context app-vendor=./vendor -t image-conversion-api .
```

### Run with Compose

`docker/compose.yaml` runs the image (`app`) behind nginx (`web`), with the configuration of `deploy/nginx`:

```bash
KEY="$(openssl rand -hex 32)"   # give it to the clients
export API_KEYS="sha256:$(printf '%s' "$KEY" | sha256sum | cut -d ' ' -f 1)"
mkdir -p docker/tls   # images-api.example.com.pem and .key
docker compose -f docker/compose.yaml up --build -d
```

- `REQUIRE_HTTPS` stays on, its default: nginx terminates TLS and passes `HTTPS` to PHP-FPM. Behind another TLS-terminating proxy, see [Behind a reverse proxy or load balancer](#behind-a-reverse-proxy-or-load-balancer); turn it off only for local tests over plain HTTP.
- `API_KEYS` is required, and `METRICS_KEYS`, `V1_SCRIPT_NAME`, `RATE_LIMIT` and `RATE_LIMIT_BURST` are read from the shell. Any other key of `.env.example` can be added under `environment`.
- `TEMP_DIR` and `LOCK_DIR` are tmpfs mounts; `LOCK_DIR` is private to uid 10001 (mode 0700).
- `CONVERTER_CACHE_DIR` is the `converter-cache` volume, so the probed backends and isolation level survive restarts.
- `TLS_DIR`, `HTTP_PUBLISH` and `HTTPS_PUBLISH` override the certificate directory and the published ports.
- The PHP-FPM pool (`docker/php-fpm.conf`) runs `pm = static` with 8 workers, `request_terminate_timeout = 60s`, `clear_env = no`, and passes the JSON log lines of `LOG_FILE=stderr` to the container log unchanged.
- `docker/php.ini` sets `post_max_size = 22M`, `upload_max_filesize = 21M`, `max_file_uploads = 20` and `memory_limit = 256M`. Raise them with `MAX_BODY_BYTES`, `MAX_IMAGE_BYTES` and `V1_MAX_FILES`.

Adapt `server_name` and the certificate paths in `deploy/nginx/image-conversion-api.conf`.

### Isolation in Docker

Under Docker's default seccomp profile, bubblewrap cannot create its namespaces, so the conversions run at the `rlimit` isolation level. `/healthz` with a key and `check-env` in the container show the level reached. The [README](../README.md#security-model) describes what `rlimit` leaves exposed and how to narrow it.

### Sandbox isolation in Docker

`docker/compose.sandbox.yaml` is an opt-in override that lets the conversions run at the `sandbox` level:

```bash
docker compose -f docker/compose.yaml down --volumes
docker compose -f docker/compose.yaml -f docker/compose.sandbox.yaml up -d
```

It applies `docker/seccomp-bwrap.json`, which is Docker's default profile plus a rule that allows `clone`, `unshare`, `mount`, `umount2` and `pivot_root` without `CAP_SYS_ADMIN`, and `systempaths=unconfined`, which leaves `/proc` and `/sys` unmasked so that bubblewrap can mount its own `/proc`. The container still holds no capability and runs as uid 10001.

Host prerequisites:

- unprivileged user namespaces: `user.max_user_namespaces` above 0, and `kernel.unprivileged_userns_clone=1` where the kernel has it (Debian);
- with AppArmor (Ubuntu, Debian): `apparmor=unconfined` in the container's `security_opt`, or a profile that allows bubblewrap's mounts and user namespaces; on Ubuntu 23.10 and later also `kernel.apparmor_restrict_unprivileged_userns=0`.

Without them the library falls back to `rlimit`, and `check-env` says so.

The library caches the isolation level it probed in `CONVERTER_CACHE_DIR` for an hour, whatever the seccomp profile is now. `down --volumes` empties it (it removes the `converter-cache` volume only). Do the same when removing the override or changing the host prerequisites; otherwise conversions fail with `500 conversion_failed`, or keep the old level, until the entry expires or a failed conversion makes the library probe again (at most once a minute). `check-env` warns about a cached level that differs from the one it probes.

## Writable directories

| Key | Holds | Recommendation |
| --- | --- | --- |
| `TEMP_DIR` | one directory per request, named `image-conversion-api-*` | Outside the web root. A local disk or a tmpfs sized as below. |
| `LOCK_DIR` | the conversion slot lock files; the rate limit and metrics files with the `file` stores | `/run/image-conversion-api`, owned by the PHP user, mode 0700 |
| `CONVERTER_CACHE_DIR` | the backends and isolation level the library probed | A persistent directory private to the PHP user, such as `/var/cache/image-conversion-api` |

`LOCK_DIR` must be absolute, on a local filesystem, private to the PHP user (it is created with mode 0700 and refused when other users can write to it or to a parent directory without the sticky bit) and never cleaned while the service runs. It must not be `TEMP_DIR`, nor lie inside a directory of `TEMP_DIR` whose name starts with `image-conversion-api-`. Its default, `TEMP_DIR/image-conversion-api.locks`, is under the system temporary directory, which systemd-tmpfiles cleans up by age: `check-env` warns about a `LOCK_DIR` under `/tmp` or `/var/tmp`. With systemd, create `/run/image-conversion-api` at boot with a `tmpfiles.d` entry, here for PHP-FPM workers running as `www-data`:

```text
# /etc/tmpfiles.d/image-conversion-api.conf
d /run/image-conversion-api 0700 www-data www-data -
```

```bash
systemd-tmpfiles --create /etc/tmpfiles.d/image-conversion-api.conf
```

Without a writable `CONVERTER_CACHE_DIR`, or without `ext-posix`, the library keeps no cache, and every PHP process probes the backends again, which takes seconds. Point it at a persistent volume when the root filesystem is read-only.

## Sizing

For `CONCURRENCY_SLOTS` conversions at once (default 4):

- **Memory**: at least `CONCURRENCY_SLOTS` x 2 GiB for the conversions (the library's per-process memory ceiling), plus PHP-FPM's `pm.max_children` x `memory_limit`, plus the size of `TEMP_DIR` when it is a tmpfs. `docker/compose.yaml` shows the sum: 4 x 2048 MiB + 8 x 256 MiB + 1200 MiB = 11440 MiB, so `mem_limit: 12g`.
- **`TEMP_DIR`**: at least `CONCURRENCY_SLOTS` x (`MAX_BODY_BYTES` + 256 MiB), for the uploads, the decoded frames and the outputs.
- **PHP-FPM workers**: one per slot, plus workers for `/healthz`, `/metrics` and the requests that wait for a slot. The Docker pool runs 8.
- **Timeouts**: the web server's, PHP-FPM's and the client's above `CONVERSION_TIME_LIMIT`.

## Behind a reverse proxy or load balancer

When TLS ends at a load balancer or reverse proxy, list its addresses in `TRUSTED_PROXIES`. The service then believes its `X-Forwarded-Proto`, which must say `https` for every hop, and takes the client address from `X-Forwarded-For`, read from right to left up to the first address that is not a trusted proxy. From any other peer both headers are ignored. The same key has the same meaning in the proxy, mfonte/proxy-cdn.

When the load balancer forwards plain HTTP to nginx's port 80, the shipped configuration does not fit: its port 80 server answers every request with a redirect to HTTPS, which the load balancer forwards to port 80 again, in a loop. On that port, serve the API instead of redirecting, and let the load balancer redirect the clients that use plain HTTP:

```nginx
server {
    listen 80;
    server_name images-api.example.com;
    server_tokens off;
    root /srv/image-conversion-api/public;
    client_max_body_size 22m;

    # Only the load balancer may reach this port (firewall or security group).
    location / {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param HTTP_PROXY "";
        fastcgi_param PHP_ADMIN_VALUE "display_errors=Off\ndisplay_startup_errors=Off";
        fastcgi_read_timeout 60s;
        fastcgi_pass image_conversion_api_fpm;
    }
}
```

with the load balancer's addresses in `TRUSTED_PROXIES` (for example `TRUSTED_PROXIES=10.0.0.0/8`) and `REQUIRE_HTTPS` left on: the service reads HTTPS from the load balancer's `X-Forwarded-Proto`, and still refuses a request that reached the load balancer over plain HTTP.

Health checks of a load balancer should call `GET /healthz` without a key; an orchestrator's readiness check can send a key to make the service build its converter.
