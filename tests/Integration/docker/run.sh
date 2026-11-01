#!/usr/bin/env bash
#
# Integration harness of the conversion API. Two modes:
#
# - fpm: the API behind Apache + PHP-FPM and behind nginx + PHP-FPM, on the
#   official php:<version>-fpm, httpd and nginx images (compose.yaml), with
#   the shipped .htaccess files and deploy/nginx unchanged, and a php.ini
#   that prints every error. Apache serves public/ on one port and the
#   installation directory as /webp-converter/ (the root .htaccess) on
#   another;
# - image: docker/compose.yaml, the hardened image of docker/Dockerfile
#   behind nginx, with real conversions; the hardening of the running
#   container is checked too.
#
# Then tests/Integration (PHPUnit) runs against them in a php:<version>-cli
# container on the host network; in image mode it posts the library's
# security corpus too, when vendor/ holds the library's tests (installed
# with "composer reinstall mfonte/secure-image-converter
# --prefer-install=source").
#
# Usage: tests/Integration/docker/run.sh [--php <version>] [--mode fpm|image|all] [--keep]
#
#   --php     PHP version of the fpm mode and of the PHPUnit run (default 8.5)
#   --mode    which mode to run (default fpm)
#   --keep    leave the containers and HARNESS_WORKDIR in place afterwards
#
# vendor/ is mounted from this checkout, so install it beforehand, with the
# dev dependencies, for a version that runs on --php (for example
# "composer update" with that PHP version).
#
# Environment:
#   HARNESS_IMAGE_MIRROR    registry the official images are pulled from when
#                           their Docker Hub name is not present locally
#                           (default mirror.gcr.io/library; empty for Docker
#                           Hub). They run by the mirror's name: the harness
#                           tags no image it did not build.
#   HARNESS_WORKDIR         empty directory for the application copies, the
#                           certificate and the logs (default: a temporary
#                           directory, removed at the end unless --keep)
#   HARNESS_IMAGE           image mode: an image of docker/Dockerfile to test
#                           instead of building one (it is not removed)
#   HARNESS_VENDOR_CONTEXT  image mode: build with this vendor/ (PHP 8.3, no
#                           dev) as the "app-vendor" context instead of
#                           running Composer in the build
#   COMPOSER_AUTH           image mode: handed to Composer in the build as the
#                           "composer_auth" secret (a GitHub token)
#   HARNESS_SANDBOX         image mode: 1 adds docker/compose.sandbox.yaml and
#                           expects the "sandbox" isolation (the host must
#                           meet its prerequisites; not on GitHub's runners)

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "$here/../../.." && pwd)"

php_version=8.5
mode=fpm
keep=0

log() { printf '==> %s\n' "$*"; }
pass() { printf '    ok: %s\n' "$*"; }
die() {
    printf 'FAILED: %s\n' "$*" >&2
    exit 1
}
usage() { sed -n '18,22p' "$0" | sed 's/^# \{0,1\}//'; }

while [ $# -gt 0 ]; do
    case "$1" in
        --php) php_version="${2:-}"; shift 2 || die "--php needs a version" ;;
        --php=*) php_version="${1#*=}"; shift ;;
        --mode) mode="${2:-}"; shift 2 || die "--mode needs a value" ;;
        --mode=*) mode="${1#*=}"; shift ;;
        --keep) keep=1; shift ;;
        -h | --help) usage; exit 0 ;;
        *) usage >&2; die "unknown argument: $1" ;;
    esac
done

[[ "$php_version" =~ ^[0-9]+\.[0-9]+$ ]] || die "--php expects a version such as 8.5, got '$php_version'"
case "$mode" in
    all) modes=(fpm image) ;;
    fpm | image) modes=("$mode") ;;
    *) die "--mode expects fpm, image or all, got '$mode'" ;;
esac

for tool in docker curl git openssl; do
    command -v "$tool" >/dev/null || die "$tool is required"
done
docker compose version >/dev/null 2>&1 || die "the docker compose plugin is required"
[ -f "$root/vendor/autoload.php" ] || die "vendor/ is missing: install the dependencies first (see the header of this script)"
[ -f "$root/vendor/bin/phpunit" ] || die "vendor/bin/phpunit is missing: install the dev dependencies too"

# --- working directory ------------------------------------------------------

own_workdir=0
if [ -n "${HARNESS_WORKDIR:-}" ]; then
    mkdir -p "$HARNESS_WORKDIR"
    [ -z "$(ls -A "$HARNESS_WORKDIR")" ] || die "HARNESS_WORKDIR must be empty: $HARNESS_WORKDIR"
    workdir="$(cd "$HARNESS_WORKDIR" && pwd)"
else
    workdir="$(mktemp -d "${TMPDIR:-/tmp}/ica-harness.XXXXXX")"
    own_workdir=1
fi
chmod 755 "$workdir"

# PHP-FPM (fpm mode) and PHPUnit run as the caller, so that the caller can
# read the logs and remove what they write; as www-data when it is root.
if [ "$(id -u)" -eq 0 ]; then
    run_uid=33
    run_gid=33
else
    run_uid="$(id -u)"
    run_gid="$(id -g)"
fi

suffix="php${php_version//./}-$$"
fpm_project="ica-harness-fpm-$suffix"
image_project="ica-harness-image-$suffix"
api_key="integration-api-key-$(openssl rand -hex 12)"
metrics_key="integration-metrics-key-$(openssl rand -hex 12)"
server_name=images-api.example.com
built_image=""

# Each compose file is used with an override that runs every image by the
# local name resolve() found (see write_image_override).
fpm_compose() { docker compose --project-name "$fpm_project" --file "$here/compose.yaml" --file "$workdir/fpm-images.yaml" "$@"; }
image_files=(--file "$root/docker/compose.yaml")
if [ "${HARNESS_SANDBOX:-0}" = 1 ]; then
    image_files+=(--file "$root/docker/compose.sandbox.yaml")
fi
image_compose() { docker compose --project-name "$image_project" "${image_files[@]}" --file "$workdir/image-images.yaml" "$@"; }

fpm_started=0
image_started=0
diagnostics() {
    local name="$1"
    shift
    log "Diagnostics ($name)"
    "$@" ps --all
    "$@" logs --no-color --timestamps >"$workdir/$name-compose.log" 2>&1
    tail -n 80 "$workdir/$name-compose.log"
    for file in "$workdir"/*/logs/*; do
        if [ -s "$file" ]; then
            printf -- '--- %s\n' "$file"
            tail -n 40 "$file"
        fi
    done
}
cleanup() {
    local status=$?
    set +e
    if [ "$fpm_started" -eq 1 ]; then
        [ "$status" -eq 0 ] || diagnostics fpm fpm_compose
        [ "$keep" -eq 1 ] || fpm_compose down --volumes --remove-orphans --timeout 5 >/dev/null 2>&1
    fi
    if [ "$image_started" -eq 1 ]; then
        [ "$status" -eq 0 ] || diagnostics image image_compose
        [ "$keep" -eq 1 ] || image_compose down --volumes --remove-orphans --timeout 5 >/dev/null 2>&1
    fi
    if [ -n "$built_image" ] && [ "$keep" -eq 0 ]; then
        docker image rm "$built_image" >/dev/null 2>&1
    fi
    if [ "$keep" -eq 1 ]; then
        log "Kept $workdir and the projects $fpm_project, $image_project. Remove them with:"
        printf '    docker compose --project-name <project> down --volumes && rm -rf %s\n' "$workdir"
    elif [ "$own_workdir" -eq 1 ]; then
        rm -rf "$workdir"
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

# --- helpers ----------------------------------------------------------------

mirror="${HARNESS_IMAGE_MIRROR-mirror.gcr.io/library}"
# resolve <image>: prints the local name to run <image> by: <image> itself
# when it is present, otherwise its name on the mirror, pulled when missing.
# Nothing is tagged, so images other users share are left as they are.
resolve() {
    local image="$1" source_image
    if docker image inspect "$image" >/dev/null 2>&1; then
        printf '%s\n' "$image"
        return
    fi
    source_image="${mirror:+$mirror/}$image"
    if ! docker image inspect "$source_image" >/dev/null 2>&1; then
        docker pull --quiet "$source_image" >/dev/null || die "cannot pull $source_image"
    fi
    printf '%s\n' "$source_image"
}

# write_image_override <compose file> <override>: a compose override that
# runs the image of every service of <compose file> by its resolved name.
write_image_override() {
    local compose_file="$1" file="$2" service image resolved
    printf 'services:\n' >"$file.tmp"
    while read -r service image; do
        resolved="$(resolve "$image")" || exit 1
        printf '  %s:\n    image: %s\n' "$service" "$resolved" >>"$file.tmp"
        pass "$service: $resolved"
    done < <(docker compose --file "$compose_file" config | awk '
        /^[^ ]/ { in_services = ($0 == "services:") }
        in_services && /^  [^ ].*:$/ { service = substr($1, 1, length($1) - 1) }
        in_services && /^    image: / { print service, $2 }')
    mv "$file.tmp" "$file"
}

wait_for() {
    local what="$1" timeout="$2" deadline
    shift 2
    deadline=$((SECONDS + timeout))
    until "$@" >/dev/null 2>&1; do
        [ "$SECONDS" -lt "$deadline" ] || die "timed out after ${timeout}s waiting for $what"
        sleep 0.5
    done
    pass "$what"
}

expect() {
    if [ "$2" = "$3" ]; then
        pass "$1"
    else
        die "$1: expected '$3', got '$2'"
    fi
}

# port_of <compose function> <service> <container port>
port_of() { "$1" port "$2" "$3" | sed -n 's/.*:\([0-9][0-9]*\)$/\1/p' | head -n 1; }

# The harness talks to 127.0.0.1 only: no proxy of the environment applies.
healthz_ok() { curl --noproxy '*' -fsS "$@" | grep -q '"status":"ok"'; }

# The certificate of nginx, for $server_name, its own CA.
mkdir -p "$workdir/tls"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=$server_name" \
    -addext "subjectAltName=DNS:$server_name" -addext basicConstraints=critical,CA:TRUE \
    -keyout "$workdir/tls/$server_name.key" -out "$workdir/tls/$server_name.pem" >/dev/null 2>&1 ||
    die "openssl cannot generate the certificate"
chmod 644 "$workdir/tls/$server_name.key" "$workdir/tls/$server_name.pem"

log "Images (${mirror:-Docker Hub})"
php_cli="$(resolve "php:$php_version-cli")" || exit 1
pass "$php_cli"

log "vendor/ on PHP $php_version"
docker run --rm --network none --volume "$root:/app:ro" "$php_cli" \
    php -r 'require "/app/vendor/autoload.php";' ||
    die "vendor/ does not load on PHP $php_version: install it for that version first"
pass "vendor/autoload.php loads"

phpunit_env=(
    -e "ICA_INTEGRATION_API_KEY=$api_key"
    -e "ICA_INTEGRATION_METRICS_KEY=$metrics_key"
    -e "ICA_INTEGRATION_V1_SCRIPT_NAME=convert-now"
    -e "ICA_INTEGRATION_CA_FILE=$workdir/tls/$server_name.pem"
    -e "ICA_INTEGRATION_SERVER_NAME=$server_name"
)

# run_phpunit <label> <env args...>: tests/Integration on the host network.
run_phpunit() {
    local label="$1"
    shift
    log "PHPUnit: $label (PHP $php_version)"
    docker run --rm --network host --user "$run_uid:$run_gid" -e HOME=/tmp \
        --volume "$root:$root:ro" --volume "$workdir:$workdir:ro" --workdir "$root" \
        "${phpunit_env[@]}" "$@" "$php_cli" \
        php vendor/bin/phpunit --testsuite integration --do-not-cache-result ||
        die "$label: the integration tests failed"
}

# --- fpm mode ---------------------------------------------------------------

# Tracked and untracked files that git does not ignore, so that local
# changes are tested; vendor/ is mounted from the checkout.
copy_application() {
    local target="$1"
    mkdir -p "$target/vendor"
    (cd "$root" && git ls-files -z --cached --others --exclude-standard -- . \
        ':(exclude)tests' ':(exclude)tools' ':(exclude).github' ':(exclude)docker' ':(exclude)deploy') |
        while IFS= read -r -d '' file; do
            if [ -e "$root/$file" ]; then
                printf '%s\0' "$file"
            fi
        done |
        tar -C "$root" --null --files-from=- -cf - | tar -C "$target" -xf -
    cp "$here/app.env" "$target/.env"
    sed -i "s/^API_KEY=.*/API_KEY=\"$api_key\"/; s/^METRICS_KEYS=.*/METRICS_KEYS=\"$metrics_key\"/" "$target/.env"
}

run_fpm() {
    local s apache_port apache_subdir_port nginx_port nginx_http_port
    export HARNESS_PHP_VERSION="$php_version" HARNESS_UID="$run_uid" HARNESS_GID="$run_gid" HARNESS_WORKDIR="$workdir"

    log "Images for the fpm mode"
    write_image_override "$here/compose.yaml" "$workdir/fpm-images.yaml"

    for s in apache nginx; do
        copy_application "$workdir/$s/app"
        mkdir -p "$workdir/$s/logs"
        chmod -R u+rwX,go+rX,go-w "$workdir/$s"
        if [ "$(id -u)" -eq 0 ]; then
            chown -R "$run_uid:$run_gid" "$workdir/$s"
        fi
    done

    log "Starting the fpm mode (PHP $php_version)"
    fpm_started=1
    fpm_compose up --detach --pull never || die "docker compose up failed"

    apache_port="$(port_of fpm_compose apache 80)"
    apache_subdir_port="$(port_of fpm_compose apache 8080)"
    nginx_port="$(port_of fpm_compose nginx 443)"
    nginx_http_port="$(port_of fpm_compose nginx 80)"
    wait_for "apache answers /healthz" 60 healthz_ok "http://127.0.0.1:$apache_port/healthz"
    wait_for "apache answers /webp-converter/healthz" 30 healthz_ok "http://127.0.0.1:$apache_subdir_port/webp-converter/healthz"
    wait_for "nginx answers /healthz over TLS" 60 healthz_ok --cacert "$workdir/tls/$server_name.pem" \
        --resolve "$server_name:$nginx_port:127.0.0.1" "https://$server_name:$nginx_port/healthz"

    run_phpunit "fpm mode, Apache and nginx" \
        -e "ICA_INTEGRATION_APACHE_URL=http://127.0.0.1:$apache_port" \
        -e "ICA_INTEGRATION_APACHE_LOG=$workdir/apache/logs/api.log" \
        -e "ICA_INTEGRATION_APACHE_SUBDIR_URL=http://127.0.0.1:$apache_subdir_port/webp-converter" \
        -e "ICA_INTEGRATION_APACHE_SUBDIR_LOG=$workdir/apache/logs/api.log" \
        -e "ICA_INTEGRATION_NGINX_URL=https://127.0.0.1:$nginx_port" \
        -e "ICA_INTEGRATION_NGINX_HTTP_URL=http://127.0.0.1:$nginx_http_port" \
        -e "ICA_INTEGRATION_NGINX_LOG=$workdir/nginx/logs/api.log"

    for s in apache nginx; do
        if [ -s "$workdir/$s/logs/php-error.log" ]; then
            log "PHP errors logged by fpm-$s (logged, never displayed)"
            tail -n 20 "$workdir/$s/logs/php-error.log"
        fi
    done
    pass "fpm mode passed"
}

# --- image mode -------------------------------------------------------------

run_image() {
    local image https_port http_port app status id environment
    # The keys as their digests, as recommended: the container's
    # environment holds no key. No rate limit: the security corpus alone
    # sends some 450 requests.
    export API_KEYS="sha256:$(printf '%s' "$api_key" | sha256sum | cut -d ' ' -f 1)" \
        METRICS_KEYS="sha256:$(printf '%s' "$metrics_key" | sha256sum | cut -d ' ' -f 1)" \
        V1_SCRIPT_NAME=convert-now RATE_LIMIT=0 TLS_DIR="$workdir/tls" \
        HTTPS_PUBLISH="127.0.0.1:" HTTP_PUBLISH="127.0.0.1:"

    log "Images for the image mode"
    if [ -n "${HARNESS_IMAGE:-}" ]; then
        image="$HARNESS_IMAGE"
        docker image inspect "$image" >/dev/null 2>&1 || die "HARNESS_IMAGE $image does not exist"
        pass "$image (given)"
    else
        image="ica-harness/image-conversion-api:$suffix"
        local ubuntu
        ubuntu="$(resolve ubuntu:24.04)" || exit 1
        log "Building $image (on $ubuntu)"
        local build=(docker build --network host --file "$root/docker/Dockerfile" --tag "$image" --build-arg "UBUNTU_IMAGE=$ubuntu")
        if [ -n "${HARNESS_VENDOR_CONTEXT:-}" ]; then
            build+=(--build-arg VENDOR_SOURCE=context --build-context "app-vendor=$HARNESS_VENDOR_CONTEXT")
        elif [ -n "${COMPOSER_AUTH:-}" ]; then
            build+=(--secret id=composer_auth,env=COMPOSER_AUTH)
        fi
        built_image="$image"
        "${build[@]}" "$root" || die "docker build failed"
        pass "built $image"
    fi
    export IMAGE_CONVERSION_API_IMAGE="$image"
    write_image_override "$root/docker/compose.yaml" "$workdir/image-images.yaml"

    log "Starting docker/compose.yaml"
    image_started=1
    image_compose up --detach --no-build --pull never || die "docker compose up failed"
    https_port="$(port_of image_compose web 443)"
    http_port="$(port_of image_compose web 80)"
    curl_tls=(--cacert "$workdir/tls/$server_name.pem" --resolve "$server_name:$https_port:127.0.0.1")
    wait_for "nginx answers /healthz over TLS" 60 healthz_ok "${curl_tls[@]}" "https://$server_name:$https_port/healthz"

    log "Hardening of the app container"
    app="$(image_compose ps --quiet app)"
    expect "runs as uid 10001" "$(image_compose exec -T app id -u)" 10001
    expect "read-only root filesystem" "$(docker inspect --format '{{.HostConfig.ReadonlyRootfs}}' "$app")" true
    expect "every capability dropped" "$(docker inspect --format '{{json .HostConfig.CapDrop}}' "$app")" '["ALL"]'
    docker inspect --format '{{json .HostConfig.SecurityOpt}}' "$app" | grep -q '"no-new-privileges:true"' ||
        die "no-new-privileges is not set"
    pass "no-new-privileges"
    expect "no effective capability" "$(image_compose exec -T app awk '/^CapEff:/ { print $2 }' /proc/1/status)" 0000000000000000
    expect "NoNewPrivs set" "$(image_compose exec -T app awk '/^NoNewPrivs:/ { print $2 }' /proc/1/status)" 1
    if image_compose exec -T app sh -c 'touch /srv/image-conversion-api/probe' >/dev/null 2>&1; then
        die "the application directory is writable"
    fi
    pass "the application directory is read-only"
    image_compose exec -T app php bin/image-conversion-api check-env >"$workdir/check-env.log" 2>&1 ||
        { cat "$workdir/check-env.log"; die "check-env fails in the container"; }
    pass "check-env passes in the container"
    environment="$(docker inspect --format '{{json .Config.Env}}' "$app")"
    case "$environment" in
        *'"API_KEYS=sha256:'*) ;;
        *) die "API_KEYS is not a digest in the environment of the container" ;;
    esac
    case "$environment" in
        *"$api_key"* | *"$metrics_key"*) die "a key is in the environment of the container" ;;
    esac
    pass "no key in the environment of the container (digests only)"
    if [ "${HARNESS_SANDBOX:-0}" = 1 ]; then
        expect "isolation with docker/compose.sandbox.yaml" "$(sed -n 's/.*Converter: isolation \([a-z]*\);.*/\1/p' "$workdir/check-env.log")" sandbox
    else
        expect "isolation of docker/compose.yaml" "$(sed -n 's/.*Converter: isolation \([a-z]*\);.*/\1/p' "$workdir/check-env.log")" rlimit
    fi

    run_phpunit "image mode, docker/compose.yaml" \
        -e "ICA_INTEGRATION_IMAGE_URL=https://127.0.0.1:$https_port" \
        -e "ICA_INTEGRATION_IMAGE_HTTP_URL=http://127.0.0.1:$http_port"

    log "Logs and caches of the app container"
    id="proxy-image-check-$(openssl rand -hex 6)"
    status="$(curl --noproxy '*' -sS -o /dev/null -w '%{http_code}' "${curl_tls[@]}" -H "X-Request-Id: $id" -H "Authorization: Bearer $api_key" \
        "https://$server_name:$https_port/metrics")"
    expect "/metrics with the request ID" "$status" 200
    sleep 1
    image_compose logs --no-color app >"$workdir/image-app.log" 2>&1
    grep -q "\"request_id\":\"$id\"" "$workdir/image-app.log" || die "no JSON log line of the container carries $id"
    pass "the container log carries the request ID of the client"
    if grep -q 'said into std' "$workdir/image-app.log"; then
        die "PHP-FPM decorates the log lines of the workers"
    fi
    pass "the JSON lines reach the container log undecorated"
    [ -n "$(image_compose exec -T app ls -A /var/cache/image-conversion-api)" ] || die "CONVERTER_CACHE_DIR stayed empty"
    pass "the library keeps its probes in CONVERTER_CACHE_DIR"
    wait_for "Docker reports the container healthy" 90 \
        sh -c "[ \"\$(docker inspect --format '{{.State.Health.Status}}' $app)\" = healthy ]"
    pass "image mode passed"
}

for m in "${modes[@]}"; do
    "run_$m"
done

log "Integration tests passed (${modes[*]}, PHP $php_version)"
