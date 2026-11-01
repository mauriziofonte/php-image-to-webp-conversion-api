# PHP "Image to WEBP" JSON API Microservice

A simple PHP microservice that converts input images (JPEG, PNG, or any other image format) to **WEBP** using a JSON API with custom conversion options.

## 2.0 endpoint: `POST /v2/convert`

> 2.0 is in development; the rest of this README still describes 1.x and is rewritten before the release.

`POST /v2/convert` converts one image and answers the image itself, not JSON.

- **Authentication**: `Authorization: Bearer <key>` (or the 1.x `X-API-Key` header), over HTTPS.
- **Body**: the image as the raw body (any `Content-Type` but a form, e.g. `image/png` or `application/octet-stream`), or `multipart/form-data` with the image as the one file of the field `image`.
- **Parameters**, in the query string (or as form fields of a multipart body), parsed strictly: an unknown name or a malformed value is a `400`. `format` (`webp`, `avif`, `original`; default `webp`), `quality` (1-100), `effort` (0-9, default 4), `lossless`, `width` and `height` (1-16383), `fit` (`contain`, `cover`, `fill`), `enlarge`, `strip`, `keep_icc` and `animation` (`preserve`, `first_frame`, `reject`). Booleans are `1`/`0` or `true`/`false`.
- **Success**: `200`, the image as the body, its exact `Content-Type` (`image/webp`, `image/avif`, or the input's type when the original is kept) and `Content-Length`, and these headers: `X-Conversion-Format`, `X-Conversion-Requested-Format`, `X-Conversion-Fallback` (`1` when the requested format could not be produced) with `X-Conversion-Fallback-Reason`, `X-Conversion-Original-Kept` (`1` when the converted file was not smaller and the input is returned unchanged), `X-Conversion-Width`, `X-Conversion-Height`, `X-Conversion-Frames`, `X-Conversion-Input-Bytes`, `X-Conversion-Backend`, `X-Conversion-Isolation`, `X-Conversion-Duration-Ms`, `X-Conversion-Warnings` (a count) and one `X-Conversion-Warning` line per warning.
- **Errors**: `{"error": {"code": "...", "message": "..."}}` with a status that follows the code: `400` (`invalid_request`, `missing_image`), `401`, `413` (`file_too_large`, `body_too_large`), `415` (`unsupported_format`, `unsupported_media_type`), `422` (`invalid_image`, `animation_rejected`, `image_too_large`, `conversion_timeout`, `resource_limit`), `429` (`rate_limited`, with `Retry-After`), `500` (`conversion_failed`, `output_verification_failed`, `internal_error`), `503` (`isolation_unavailable`, `concurrency_limit` with `Retry-After`).
- **Limits** (see `.env.example`): `MAX_BODY_BYTES` and `MAX_IMAGE_BYTES`, `CONCURRENCY_SLOTS` conversions at once per host with a `CONCURRENCY_WAIT`, a per-key token bucket of `RATE_LIMIT` requests per minute with a `RATE_LIMIT_BURST` (in APCu when it is enabled, otherwise in files of `LOCK_DIR`), and `CONVERSION_TIME_LIMIT`, which must stay below the timeouts of the web server and PHP-FPM.

```bash
curl --fail -H "Authorization: Bearer $API_KEY" -H 'Content-Type: image/png' \
  --data-binary @photo.png -o photo.webp -D - \
  'https://example.com/v2/convert?format=webp&width=1200'
```

## Why Use This Microservice?

Converting images to WEBP format often requires the `exec()` function to call the `cwebp` binary on a Linux machine. However, `exec()` is not always permitted on all hosting services (e.g., shared hosting), and it's a common practice to disable it on production websites for security reasons.

By using this external microservice, you can **offload the conversion process to another machine**, keeping your main application and environment clean and secure.

## Requirements

1. LAMP environment with `mod_rewrite` (or similar) enabled.
2. **HTTPS** enabled on your virtual host.
3. PHP version **5.6** or higher.
4. **cwebp** binary installed on your machine.
5. **exec()** function enabled in your `php.ini`.

## Installation

1. Clone this repository into a directory within your existing virtual host.
2. Install the **cwebp** binary on your environment. On Ubuntu/Debian: `sudo apt install webp`.
3. Copy `.env.example` to `.env` and configure it as needed.
4. No Composer installation is required for this script.
5. _(Optional)_ If installed inside another Git project, modify your parent `.gitignore` to avoid conflicts.

## Configuring the `.env` File

* **SCRIPT\_NAME**: The endpoint name that will be whitelisted to allow requests to the microservice.
* **API\_KEY**: The API key that must be provided via the `X-API-Key` header. Use at least 16 characters: the 2.x service refuses shorter keys.

**Example:**

If the microservice is installed at `https://www.example.com/webp-converter/` and your `.env` contains:

makefile

Copia codice

`SCRIPT_NAME="convert-now"`

The application will respond **only** to requests made to `https://www.example.com/webp-converter/convert-now`.

## Passing Conversion Options

Available options (mapped directly to `cwebp` binary options):

1. **pass**: Analysis pass number (integer, range: 1-10).
2. **m**: Compression method (integer, range: 0-6).
3. **lossless**: Encode image losslessly (`1` or `true` to enable).
4. **near\_lossless**: Use near-lossless image preprocessing (integer, range: 0-100; 100 = OFF).
5. **hint**: Specify image characteristics hint (`photo`, `picture`, or `graph`).
6. **jpeg\_like**: Roughly match expected JPEG size (integer, range: 1-100).

## What the Converter Expects

* A valid API key sent via the `X-API-Key` header.
* An `images[]` array of multipart form-data images (accessible via PHP's `$_FILES`).
* _(Optional)_ A `descriptors` JSON object to map image filenames to custom data.

## Mapping Images to Custom Data

You can associate each image with custom data using the `descriptors` key, which should be a JSON array. This data will be included in the response, allowing you to correlate images with your application's data.

Refer to the **example curl request** and **example response** below.

## Example Curl Request

```bash
curl --location --request POST 'https://www.example.com/webp-converter/convert-now' \
--header 'X-API-Key: averysecretapikey' \
--form 'images[]=@/path/to/wallpaper_1.jpg' \
--form 'images[]=@/path/to/wallpaper_2.jpg' \
--form 'images[]=@/path/to/wallpaper_3.jpg' \
--form 'images[]=@/path/to/not_an_image.xls' \
--form 'descriptors=[{"filename":"wallpaper_1.jpg","file_id":"1","extra_data":"First image"},{"filename":"wallpaper_2.jpg","file_id":"2","description":"Second image"},{"filename":"wallpaper_3.jpg","file_id":"3"}]'
```

## Example Response

```json
{
    "status": true,
    "version": "1.0.0",
    "elapsed_time": "0.81s",
    "response": [
        {
            "filename": "wallpaper_1.jpg",
            "file_id": "1",
            "extra_data": "First image",
            "status": true,
            "orig_filesize": "375.5kB",
            "new_filesize": "146.8kB",
            "compression_ratio": 60.9,
            "webp_image_base64": " ... base64 encoded image ..."
        },
        {
            "filename": "wallpaper_2.jpg",
            "file_id": "2",
            "description": "Second image",
            "status": true,
            "orig_filesize": "323.39kB",
            "new_filesize": "271.41kB",
            "compression_ratio": 16.07,
            "webp_image_base64": " ... base64 encoded image ..."
        },
        {
            "filename": "wallpaper_3.jpg",
            "file_id": "3",
            "status": true,
            "orig_filesize": "695.49kB",
            "new_filesize": "52.1kB",
            "compression_ratio": 92.51,
            "webp_image_base64": " ... base64 encoded image ..."
        },
        {
            "filename": "not_an_image.xls",
            "error": "Unsupported file extension.",
            "status": false
        }
    ]
}
```

## Application Errors

If the application cannot process your request, the response will include a `status` of `false` and an error `message`. An appropriate HTTP status code (e.g., **401 Unauthorized**) will also be returned.

```json
{
    "status": false,
    "version": "1.0.0",
    "message": "Invalid API key provided."
}
```

Always check the **status** key in the root object of the response payload.

## Full PHP Implementation Example

```php
<?php
$images = [
    [
        'path' => '/home/user/files/image1.jpg',
        'file_id' => 1,
        'description' => 'First Image'
    ],
    [
        'path' => '/home/user/files/image2.jpg',
        'file_id' => 2,
        'description' => 'Second Image'
    ],
    [
        'path' => '/home/user/files/not_an_image.iso',
        'file_id' => 5,
        'description' => 'Invalid Image'
    ]
];

$webp_images = imageToWebpApi('https://my-api-server.example.com/converter', 'VERY_STRONG_API_KEY', $images);

if ($webp_images['status'] === true) {
    foreach ($webp_images['response'] as $conversion_data) {
        if ($conversion_data['status'] === true) {
            echo 'Conversion OK for image ' . $conversion_data['filename'] . PHP_EOL;
            echo 'Original image size: ' . $conversion_data['orig_filesize'] . PHP_EOL;
            echo 'Converted image size: ' . $conversion_data['new_filesize'] . PHP_EOL;
            echo 'Compression ratio: ' . $conversion_data['compression_ratio'] . '%' . PHP_EOL;
            echo 'Base64 image length: ' . strlen($conversion_data['webp_image_base64']) . PHP_EOL;
        } else {
            echo 'Conversion ERROR for image ' . $conversion_data['filename'] . PHP_EOL;
            echo 'Error reason: ' . $conversion_data['error'] . PHP_EOL;
        }
    }
} else {
    trigger_error('Cannot run imageToWebpApi: ' . $webp_images['error'], E_USER_NOTICE);
}

function imageToWebpApi(string $api_url, string $api_key, array $images): array
{
    $postData = [];
    $descriptors = [];

    foreach ($images as $index => $file_data) {
        if (is_file($file_data['path'])) {
            $realpath = realpath($file_data['path']);
            $mime = mime_content_type($file_data['path']);
            $basename = basename($file_data['path']);

            $postData['images[' . $index . ']'] = new CURLFile($realpath, $mime, $basename);

            unset($file_data['path']);
            $file_data['filename'] = $basename;
            $descriptors[] = $file_data;
        }
    }

    $postData['descriptors'] = json_encode($descriptors);

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-API-Key: ' . $api_key,
        'User-Agent: PHP cURL connector for Image to WEBP API'
    ]);

    $response = curl_exec($ch);
    $status_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);

    if ($decoded !== null && isset($decoded['response']) && $status_code === 200) {
        return [
            'status' => true,
            'response' => $decoded['response']
        ];
    } else {
        $error_message = $decoded['message'] ?? 'Unknown error with HTTP status code ' . $status_code;
        return [
            'status' => false,
            'error' => $error_message
        ];
    }
}
```

**Sample Output:**

```txt
Conversion OK for image image1.jpg
Original image size: 362.08kB
Converted image size: 58.94kB
Compression ratio: 83.72%
Base64 image length: 80476
Conversion OK for image image2.jpg
Original image size: 1.41MB
Converted image size: 119.54kB
Compression ratio: 91.69%
Base64 image length: 163220
Conversion ERROR for image not_an_image.iso
Error reason: Unsupported file extension.
```
