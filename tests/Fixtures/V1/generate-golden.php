<?php

declare(strict_types=1);

/*
 * Writes the /v1 golden files from the 1.x behaviour: it takes process.php
 * as the last 1.x commit left it (V1_COMMIT), runs it under PHP's built-in
 * web server with a .env, HTTPS set by a router and a fake cwebp first in
 * PATH, sends it every request of cases.php as multipart/form-data, and
 * stores each response body in golden/<case>.json, the 1.x statuses and
 * Content-Types in golden/index.json, and the cwebp switches 1.x passed
 * in golden/cwebp-arguments.json.
 *
 * Run from the repository root, with vendor/ installed:
 *
 *     php tests/Fixtures/V1/generate-golden.php
 *
 * It needs git, a POSIX shell and "which" (process.php looks cwebp up
 * with it). The files only change when cases.php changes.
 */

use Mfonte\ImageConversionApi\Tests\Fixtures\Http\BuiltinServer;

const V1_COMMIT = '7564a80';
const SCRIPT_NAME = 'convert-now';
const API_KEY = 'golden-files-key-0123456789';
const UPLOAD_MAX_BYTES = 2097152;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

/** @var array{output: string, needle: string, cases: array<string, array{query: string, fields: array<string, string>, files: list<array{0: string, 1: string|int}>, status: int}>} $fixture */
$fixture = require __DIR__ . '/cases.php';

$work = sys_get_temp_dir() . '/image-conversion-api-golden-' . bin2hex(random_bytes(4));
mkdir($work . '/bin', 0700, true);
mkdir($work . '/ini', 0700, true);

exec('git -C ' . escapeshellarg($root) . ' show ' . V1_COMMIT . ':process.php', $lines, $status);
if ($status !== 0) {
    fwrite(STDERR, 'Cannot read process.php at ' . V1_COMMIT . "\n");
    exit(1);
}
file_put_contents($work . '/process.php', implode("\n", $lines) . "\n");
file_put_contents($work . '/.env', 'SCRIPT_NAME = "' . SCRIPT_NAME . "\"\nAPI_KEY = \"" . API_KEY . "\"\n");
file_put_contents($work . '/router.php', "<?php\n\$_SERVER['HTTPS'] = 'on';\nrequire __DIR__ . '/process.php';\n");
file_put_contents($work . '/output.webp', $fixture['output']);
file_put_contents($work . '/ini/golden.ini', 'upload_max_filesize = ' . UPLOAD_MAX_BYTES . "\npost_max_size = 32M\n");
// The fake cwebp: "cwebp -quiet [switches] <input> -o <output>". It logs its
// switches, fails on an input that holds the needle, and writes OUTPUT.
file_put_contents(
    $work . '/bin/cwebp',
    <<<'SH'
#!/bin/sh
for last; do :; done
switches=""
input=""
while [ "$#" -gt 0 ]; do
    case "$1" in
        -o) break ;;
        *) switches="$switches $input"; input="$1" ;;
    esac
    shift
done
printf '%s\n' "$switches" | sed 's/^ *//' >> "$CWEBP_LOG"
grep -q "$CWEBP_NEEDLE" "$input" && exit 1
cp "$CWEBP_OUTPUT" "$last"
SH
);
chmod($work . '/bin/cwebp', 0700);

$server = BuiltinServer::start($work, $work . '/router.php', [
    'PATH' => $work . '/bin:' . (getenv('PATH') ?: '/usr/bin:/bin'),
    'PHP_INI_SCAN_DIR' => $work . '/ini',
    'CWEBP_LOG' => $work . '/cwebp.log',
    'CWEBP_NEEDLE' => $fixture['needle'],
    'CWEBP_OUTPUT' => $work . '/output.webp',
], $work . '/server.log');

$index = [];
$arguments = [];
try {
    foreach ($fixture['cases'] as $name => $case) {
        $boundary = '----golden' . bin2hex(random_bytes(8));
        $body = '';
        foreach ($case['fields'] as $field => $value) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$field\"\r\n\r\n$value\r\n";
        }
        foreach ($case['files'] as $file) {
            $content = is_int($file[1]) ? str_repeat('x', UPLOAD_MAX_BYTES + 1) : $file[1];
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"images[]\"; filename=\"{$file[0]}\"\r\n"
                . "Content-Type: application/octet-stream\r\n\r\n$content\r\n";
        }
        $body .= "--$boundary--\r\n";
        @unlink($work . '/cwebp.log');
        $response = $server->request('POST', '/v1/' . SCRIPT_NAME . $case['query'], [
            'X-API-Key' => API_KEY,
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
        ], $body);
        file_put_contents(__DIR__ . '/golden/' . $name . '.json', $response['body']);
        $index[$name] = ['status' => $response['status'], 'content_type' => $response['headers']['content-type'] ?? null];
        $log = is_file($work . '/cwebp.log') ? (string) file_get_contents($work . '/cwebp.log') : '';
        $arguments[$name] = $log === '' ? [] : explode("\n", rtrim($log, "\n"));
    }
} finally {
    $server->stop();
    $output = $server->getOutput();
    exec('rm -rf ' . escapeshellarg($work));
}

$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
file_put_contents(__DIR__ . '/golden/index.json', json_encode($index, $flags) . "\n");
file_put_contents(__DIR__ . '/golden/cwebp-arguments.json', json_encode($arguments, $flags) . "\n");
if (preg_match('/PHP (?:Fatal|Parse|Warning|Notice|Deprecated)/', $output) === 1) {
    fwrite(STDERR, $output);
    exit(1);
}
echo 'Wrote ' . count($index) . " golden files.\n";
