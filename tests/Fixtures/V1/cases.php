<?php

declare(strict_types=1);

/*
 * The requests of the /v1 golden files, shared by generate-golden.php,
 * which sends them to the 1.x process.php, and V1GoldenTest, which sends
 * them to 2.x. A file is [name, content] or [name, UPLOAD_ERR_*]; the
 * fake cwebp of the generator and the scripted converter of the test both
 * write OUTPUT, and both fail on an input that contains FAIL.
 *
 * "status" is the HTTP status 2.x answers; 1.x answered 200 to all of them.
 */

$input = static function (int $bytes, string $tag): string {
    return substr($tag . str_repeat('.', $bytes), 0, $bytes);
};

return [
    'output' => 'RIFF' . str_repeat("\0", 4) . 'WEBPVP8 ' . str_repeat('w', 84),
    'needle' => 'FAIL',
    'cases' => [
        'batch' => [
            'query' => '?m=6&lossless=true&pass=3&hint=photo',
            'fields' => [
                'descriptors' => '[{"filename":"a.png","id":7,"path":"/img/a.png","label":"caffè"},'
                    . '{"0":"first","filename":"c.gif","status":"overridden"},{"filename":"zzz.jpg","id":9},"not an object"]',
            ],
            'files' => [
                ['a.png', $input(2048, 'PNG-A')],
                ['b.JPG', $input(1500, 'JPEG-B')],
                ['c.gif', $input(200, 'GIF-C')],
            ],
            'status' => 200,
        ],
        'per-file-failures' => [
            'query' => '?m=9',
            'fields' => [
                'descriptors' => '[{"filename":"broken.png","id":"x-1"},{"filename":"notes.txt","id":"x-2"}]',
            ],
            'files' => [
                ['notes.txt', $input(300, 'TEXT')],
                ['broken.png', $input(900, 'PNG-FAIL')],
                ['huge.png', UPLOAD_ERR_INI_SIZE],
                ['photo.bmp', $input(700, 'BMP')],
            ],
            'status' => 200,
        ],
        'single-file-defaults' => [
            'query' => '',
            'fields' => [],
            'files' => [
                ['photo.jpeg', $input(1048576 + 4096, 'JPEG')],
            ],
            'status' => 200,
        ],
        'no-images' => [
            'query' => '',
            'fields' => ['descriptors' => '[]'],
            'files' => [],
            'status' => 400,
        ],
        'invalid-descriptors' => [
            'query' => '',
            'fields' => ['descriptors' => '{not json'],
            'files' => [
                ['a.png', $input(2048, 'PNG-A')],
            ],
            'status' => 400,
        ],
    ],
];
