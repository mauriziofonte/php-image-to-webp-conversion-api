<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 'On');

define('VERSION', '1.0.0');
define('TEMP_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'temp' . DIRECTORY_SEPARATOR);

if (php_sapi_name() === 'cli') {
    exit('This service can only be accessed via HTTP.');
}

if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    http_response_code(400);
    outputError('HTTPS is required.');
}

$config = parse_ini_file(__DIR__ . DIRECTORY_SEPARATOR . '.env');
if ($config === false) {
    outputError('Configuration file not found or invalid.');
}

$headers = getallheaders();
$apiKey = $headers['X-API-Key'] ?? null;
if ($apiKey !== $config['API_KEY']) {
    http_response_code(401);
    outputError('Invalid API key provided.');
}

$requestUri = $_SERVER['REQUEST_URI'];
$scriptName = basename(parse_url($requestUri, PHP_URL_PATH));
if ($scriptName !== $config['SCRIPT_NAME']) {
    http_response_code(400);
    outputError('Invalid endpoint.');
}

$cwebpBinary = trim(shell_exec('which cwebp'));
if (empty($cwebpBinary)) {
    outputError('cwebp binary not found. Install it using "sudo apt install webp" on Ubuntu/Debian.');
}

if (!is_dir(TEMP_DIR) && !mkdir(TEMP_DIR, 0755, true)) {
    outputError('Failed to create temporary directory.');
}

if (empty($_FILES['images']['name'])) {
    outputError('No images uploaded.');
}

$descriptors = [];
if (!empty($_POST['descriptors'])) {
    $descriptors = json_decode($_POST['descriptors'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        outputError('Invalid descriptors JSON.');
    }
}

$response = [];
$startTime = microtime(true);

foreach ($_FILES['images']['name'] as $key => $filename) {
    $error = $_FILES['images']['error'][$key];
    $tmpName = $_FILES['images']['tmp_name'][$key];
    $descriptor = getDescriptor($filename, $descriptors);

    if ($error !== UPLOAD_ERR_OK) {
        $response[] = array_merge($descriptor, [
            'filename' => $filename,
            'status' => false,
            'error' => fileUploadError($error),
        ]);
        continue;
    }

    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp'], true)) {
        $response[] = array_merge($descriptor, [
            'filename' => $filename,
            'status' => false,
            'error' => 'Unsupported file extension.',
        ]);
        continue;
    }

    $tempFile = TEMP_DIR . uniqid('', true) . '.' . $extension;
    if (!move_uploaded_file($tmpName, $tempFile)) {
        $response[] = array_merge($descriptor, [
            'filename' => $filename,
            'status' => false,
            'error' => 'Failed to move uploaded file.',
        ]);
        continue;
    }

    $outputFile = TEMP_DIR . uniqid('', true) . '.webp';
    $command = escapeshellcmd($cwebpBinary) . ' -quiet';

    $command .= buildConversionOptions($_GET);

    $command .= ' ' . escapeshellarg($tempFile) . ' -o ' . escapeshellarg($outputFile);

    exec($command, $cmdOutput, $returnVar);

    if ($returnVar !== 0 || !file_exists($outputFile)) {
        $response[] = array_merge($descriptor, [
            'filename' => $filename,
            'status' => false,
            'error' => 'Conversion failed.',
        ]);
        @unlink($tempFile);
        continue;
    }

    $webpData = base64_encode(file_get_contents($outputFile));
    $origSize = filesize($tempFile);
    $newSize = filesize($outputFile);
    $compressionRatio = round((($origSize - $newSize) / $origSize) * 100, 2);

    $response[] = array_merge($descriptor, [
        'filename' => $filename,
        'status' => true,
        'orig_filesize' => formatBytes($origSize),
        'new_filesize' => formatBytes($newSize),
        'compression_ratio' => $compressionRatio,
        'webp_image_base64' => $webpData,
    ]);

    @unlink($tempFile);
    @unlink($outputFile);
}

$elapsedTime = round(microtime(true) - $startTime, 2) . 's';
outputJson([
    'status' => true,
    'version' => VERSION,
    'elapsed_time' => $elapsedTime,
    'response' => $response,
]);

/**
 * Outputs an error message in JSON format and terminates the script.
 *
 * @param string $message The error message to output.
 * @return void
 */
function outputError(string $message): void
{
    outputJson(['status' => false, 'version' => VERSION, 'message' => $message]);
}

/**
 * Outputs a JSON-encoded response and terminates the script.
 *
 * @param array $data The data to encode and output as JSON.
 * @return void
 */
function outputJson(array $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Retrieves the descriptor associated with a given filename.
 *
 * @param string $filename    The name of the file to find the descriptor for.
 * @param array  $descriptors An array of descriptors to search.
 * @return array The descriptor associated with the filename, or an empty array if not found.
 */
function getDescriptor(string $filename, array $descriptors): array
{
    foreach ($descriptors as $descriptor) {
        if (isset($descriptor['filename']) && $descriptor['filename'] === $filename) {
            return $descriptor;
        }
    }
    return [];
}

/**
 * Builds the command-line options string for the cwebp conversion command based on provided parameters.
 *
 * @param array $params The array of parameters (e.g., $_GET) containing conversion options.
 * @return string The command-line options string for cwebp.
 */
function buildConversionOptions(array $params): string
{
    $options = '';

    if (isset($params['pass']) && filter_var($params['pass'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]])) {
        $options .= ' -pass ' . (int)$params['pass'];
    }

    $method = isset($params['m']) ? (int)$params['m'] : 4;
    if ($method >= 0 && $method <= 6) {
        $options .= ' -m ' . $method;
    }

    if (isset($params['lossless']) && in_array($params['lossless'], ['1', 'true'], true)) {
        $options .= ' -lossless';
    }

    if (isset($params['near_lossless']) && filter_var($params['near_lossless'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]])) {
        $options .= ' -near_lossless ' . (int)$params['near_lossless'];
    }

    if (isset($params['hint']) && in_array($params['hint'], ['photo', 'picture', 'graph'], true)) {
        $options .= ' -hint ' . escapeshellarg($params['hint']);
    }

    if (isset($params['jpeg_like']) && filter_var($params['jpeg_like'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]])) {
        $options .= ' -jpeg_like ' . (int)$params['jpeg_like'];
    }

    return $options;
}

/**
 * Formats a size in bytes into a human-readable string with appropriate units.
 *
 * @param int $bytes     The size in bytes.
 * @param int $precision The number of decimal places to include.
 * @return string The formatted size string (e.g., "1.23 MB").
 */
function formatBytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'kB', 'MB', 'GB', 'TB'];
    $pow = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Returns a human-readable error message corresponding to a file upload error code.
 *
 * @param int $errorCode The error code from a file upload.
 * @return string The associated error message.
 */
function fileUploadError(int $errorCode): string
{
    $errors = [
        UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive.',
        UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive.',
        UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
    ];
    return $errors[$errorCode] ?? 'Unknown upload error.';
}
