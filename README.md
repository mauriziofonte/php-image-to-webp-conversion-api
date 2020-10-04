# PHP "Image to WEPB" JSON API Microservice

A simple JSON **PHP microservice** that can be used to convert input images like jpeg, png, (or any other image format) to **webp** via a simple API, with custom **conversion options**

Why a "stupid" microservice like this? Because **in conversion to webp format, you need exec() to be called** in order to use the **cwebp** conversion binary in a linux machine. The `exec()` function is not always allowed on all hosting services (think about shared hostings), and my personal choice is to **disable it** on production websites.

Hence, an external microservice come helpful. **You can "outsource" the conversion stuff to another machine**, and keep the main repository/project that actually uses WEBP images (and its lamp environment) clean and secured.

## Requirements

1.  Lamp environment, with mod_rewrite (or similar) enabled
2.  **HTTPS** enabled on your virtualhost
3.  PHP >= **5.6**
4.  **cwebp** binary installed on your machine
5.  **exec()** function enabled on your php.ini

## Installation

1.  Clone this repository inside an already-existing virtualhost in a folder of your choice
2.  Install **the cwebp binary** on your environment. On ubuntu/debian: `sudo apt install webp`
3.  Clone the `.env.example` file in `.env`
4.  No composer install, this script doesn't need anything else :)
5.  (optional) If installed inside another git project, modify your parent `.gitignore` to avoid conflicts on your parent repository

## How to configure the env file

1.  **SCRIPT_NAME** : this will be the script name that will be whitelisted to allow replies from the microservice
2.  **API_KEY** : this will be the api key that needs to be sent out via the `-x-api-key` header

Example:

Imagine this microservice is installed inside an already-existing project, inside a folder of his own, called **webp-converter**:

https://www.example.com/webp-converter/

```
SCRIPT_NAME = "convert-now"
```

The application will reply **only if** called from `https://www.example.com/webp-converter/convert-now`

## How to pass converter options

Currently available options (1:1 with the cwebp binary)

1.  **pass** : **analysis pass number**, integer, range : 1-10
2.  **m** : **compression method**, integer, range: 1-6
3.  **lossless** : **encode image losslessly**, to set it **ON** you can pass `1` or `true`
4.  **near_lossless** : **use near-lossless image preprocessing**, integer, range: 0-100. 100 = OFF
5.  **hint** : **specify image characteristics hint**, available values: one of: `photo`, `picture` or `graph`
6.  **jpeg_like** : **roughly match expected JPEG size**, integer, range: 1-100

## What the converter expects

It expects:

1.  A valid api key sent via the **-x-api-key** header
2.  An `images[]` array of multipart form-data images (that can be read and parsed with PHP's native `$_FILES`)
3.  (optionally) a `descriptors` JSON that will be used to map the image filenames to custom data you choose.

## Map the images to custom data you choose

If you want to map the images you send with **machine data**, say, you want to map the image filename `test-image.jpg` to a `file_id`, you can send a special `descriptors` key, **in json format**, that will be "mixed" with the convertion output.

More complicated to explain in words than to see it in action: refer to the **example curl request** and **example curl response**. Pay attention to the **descriptors** key, and pay attention to `extra_data`, `arbitrary_data` and `file_id`

## Example curl request

The microservice accepts only requests made via **https** protocol, to the whitelisted **SCRIPT_NAME** .env configuration, with a valid **API_KEY** provided in the `-x-api-key` header.

```
curl --location --request POST 'https://www.example.com/webp-converter/convert-now' \
--header '-x-api-key: averysecretapikey' \
--form 'images[]=@/C:/Users/User/Pictures/Wallpaper/wallpaper_1.jpg' \
--form 'images[]=@/C:/Users/User/Pictures/Wallpaper/wallpaper_2.jpg' \
--form 'images[]=@/C:/Users/User/Pictures/Wallpaper/wallpaper_3.jpg' \
--form 'images[]=@/C:/Users/User/not_an_image.xls' \
--form 'descriptors=[{"filename":"wallpaper_1.jpg","file_id":"1","extra_data":"this is the first image in the array"},{"filename":"wallpaper_2.jpg","file_id":"arbitrary_data","description":"Another wonderful image"},{"filename":"wallpaper_3.jpg","file_id":"27726629911"}]'
```

## Example response

The application will always reply with a JSON object.

Valid images that have been converted will have a `status` = **true** in the response payload. Also, the `webp_image_base64` will be **null** for unprocessable entities.

```
{
    "status": true,
    "version": "1.0.0",
    "elapsed_time": "0.81s",
    "response": [
        {
            "filename": "wallpaper_1.jpg",
            "file_id": "1",
            "extra_data": "this is the first image in the array",
            "status": true,
            "orig_filesize": "375.5kB",
            "new_filesize": "146.8kB",
            "compression_ratio": 60.9,
            "webp_image_base64" : " ... base64 encoded wallpaper_1.jpg in webp format ..."
        },
        {
            "filename": "wallpaper_2.jpg",
            "file_id": "arbitrary_data",
            "description": "Another wonderful image",
            "status": true,
            "orig_filesize": "323.39kB",
            "new_filesize": "271.41kB",
            "compression_ratio": 16.07,
            "webp_image_base64" : " ... base64 encoded wallpaper_2.jpg in webp format ..."
        },
        {
            "filename": "wallpaper_3.jpg",
            "file_id": "27726629911",
            "status": true,
            "orig_filesize": "695.49kB",
            "new_filesize": "52.1kB",
            "compression_ratio": 92.51,
            "webp_image_base64" : " ... base64 encoded wallpaper_3.jpg in webp format ..."
        },
        {
            "filename": "not_an_image.xls",
            "error": "This file extension is not allowed",
            "status": false
        }
    ]
}
```

## Application errors

If, for some reason, the application cannot serve your request, the response will be something like this:

```
{
    "status": false,
    "version": "1.0.0",
    "message": "Invalid api key provided."
}
```

So, always check for the **status** key on the root object in the response payload.

For any application-level error, like **Invalid api key provided**, an http status code error, like **401 unauthorized** will be sent out with the response.

## Full PHP implementation example

A full PHP implementation example of how to use the API via PHP is provided:

```
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

    // call the imageToWebpApi() function
    $webp_images = imageToWebpApi('https://my-api-server.example.com/converter', 'VERY_STRONG_API_KEY', $images);
    if ($webp_images['status'] === true) {
        foreach ($webp_images['response'] as $i => $conversion_data) {
            // IMPORTANT: keep in mind that every "$conversion_data" item contains the custom keys
            // provided in this example as "file_id" and "description", transparently
            // passed back out from the API
            if ($conversion_data['status'] === true) {
                // ok, the image has been converted correctly.
                // do whatever you need with the image (save to file perhaps? :))

                echo 'Conversion OK for image ' . $conversion_data['filename'] . chr(10);
                echo chr(9) . 'Original image size: ' . $conversion_data['orig_filesize'] . chr(10);
                echo chr(9) . 'Converted image size: ' . $conversion_data['new_filesize'] . chr(10);
                echo chr(9) . 'Compression ratio: ' . $conversion_data['compression_ratio'] . chr(10);
                echo chr(9) . 'Base64 webp image length: ' . strlen($conversion_data['webp_image_base64']) . chr(10);
            } else {
                // this image has not been converted due to an error.
                // the error reason is inside ['error']
                echo 'Conversion ERROR for image ' . $conversion_data['filename'] . chr(10);
                echo chr(9) . 'Error reason: ' . $conversion_data['error'] . chr(10);
            }
        }
    } else {
        // an application error occurred while calling the conversion API: report it.
        trigger_error('Cannot run imageToWebpApi: ' . $webp_images['error'], E_USER_NOTICE);
    }

    function imageToWebpApi(string $api_url, string $api_key, array $images) : array
    {
        // Create an array of files to post via cUrl and the file descriptors
        $postData = $descriptors = [];
        foreach ($images as $index => $file_data) {
            if (is_file($file_data['path'])) {
                $realpath = realpath($file_data['path']);
                $mime = mime_content_type($file_data['path']);
                $basename = basename($file_data['path']);

                $postData['images[' . $index . ']'] = curl_file_create(
                    $realpath,
                    $mime,
                    $basename
                );

                unset($file_data['path']);
                $file_data['filename'] = $basename;
                $descriptors[] = $file_data;
            }
        }

        // append the descriptors json object to POST data
        $postData['descriptors'] = json_encode($descriptors);

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            '-x-api-key: ' , $api_key,
            'User-Agent: PHP cUrl connector for MWEBP'
        ]);
        $ret = curl_exec($ch);
        $status_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (
            ($decoded = @json_decode($ret, true)) !== null &&
            is_array($decoded) &&
            $status_code === 200 &&
            array_key_exists('response', $decoded)
        ) {
            return [
                'status' => true,
                'response' => $decoded['response']
            ];
        } else {
            if (is_array($decoded) && array_key_exists('message', $decoded)) {
                $error_message = $decoded['message'];
            } else {
                $error_message = 'Unknown error with HTTP_RESPONSE_CODE="' . $status_code . '"';
            }

            return [
                'status' => false,
                'error' => $error_message
            ];
        }
    }
```

The above example will print something like this:

```
Conversion OK for image image1.jpg
	Original image size: 362.08kB
	Converted image size: 58.94kB
	Compression ratio: 83.72
	Base64 webp image length: 80476
Conversion OK for image image2.jpg
	Original image size: 1.41MB
	Converted image size: 119.54kB
	Compression ratio: 91.69
	Base64 webp image length: 163220
Conversion ERROR for image not_an_image.iso
	Error reason: This file extension is not allowed
```
