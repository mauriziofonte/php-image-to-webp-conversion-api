# PHP "Image to WEPB" JSON API Microservice

A simple JSON **PHP microservice** that can be used to convert images in jpeg, png, (or any other image format) to **webp** via a simple API, with **conversion options**

Why a "stupid" microservice like this? Because **in conversion to webp format, you need exec() to be called** in order to use the **cwebp** conversion binary in a linux machine. The `exec()` function is not always allowed on all hosting services (think about shared hostings), and my personal choice is to **disable it** on production websites.

Hence, an external microservice come helpful. **You can "outsource" the conversion stuff to another machine**, and keep the main repository (and lamp environment) clean.

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
