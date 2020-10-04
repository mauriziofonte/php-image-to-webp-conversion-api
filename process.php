<?php
    error_reporting(0);
    ini_set('display_errors', 'Off');
    $init_time = microtime(true);

    // define some useful stuff
    define('DS', DIRECTORY_SEPARATOR);
    define('RD', rtrim(dirname(__FILE__), DS) . DS);
    define('CONFIG_FILE', RD . '.env');
    define('VERSION', '1.0.0');
    define('TEMP_DIR', RD . 'temp' . DS);
    define(
        'IS_HTTPS',
        (
            (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
            (isset($_SERVER['SERVER_PORT']) && intval($_SERVER['SERVER_PORT']) === 443) ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
            (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https')
        ) ? true : false
    );
    define('FROM_CLI', (php_sapi_name() === 'cli' or defined('STDIN')) ? true : false);

    // 1: preliminary checks, config load, API_KEY check, CWEBP binary locator
    preFlightChecksAndInit();

    // 2: then, we parse the compression options passed via $_GET
    $cwebp_analysis_pass = (
        array_key_exists('pass', $_GET) &&
        is_numeric($_GET['pass']) &&
        intval($_GET['pass']) >= 1 &&
        intval($_GET['pass']) <= 10
    ) ? intval($_GET['pass']) : null;
    $cwebp_compression = (
        array_key_exists('m', $_GET) &&
        is_numeric($_GET['m']) &&
        intval($_GET['m']) >= 0 &&
        intval($_GET['m']) <= 6
    ) ? intval($_GET['m']) : 4;
    $cwebp_lossless = (
        array_key_exists('lossless', $_GET) &&
        in_array($_GET['lossless'], [1, '1', 'true'])
    ) ? true : false;
    $cwebp_near_lossless = (
        array_key_exists('near_lossless', $_GET) &&
        is_numeric($_GET['near_lossless']) &&
        intval($_GET['near_lossless']) >= 0 &&
        intval($_GET['near_lossless']) <= 100
    ) ? intval($_GET['near_lossless']) : null;
    $cwebp_hint = (
        array_key_exists('hint', $_GET) &&
        in_array($_GET['hint'], ['photo', 'picture', 'graph'])
    ) ? $_GET['hint'] : null;
    $cwebp_jpeg_like = (
        array_key_exists('jpeg_like', $_GET) &&
        is_numeric($_GET['jpeg_like']) &&
        intval($_GET['jpeg_like']) >= 0 &&
        intval($_GET['jpeg_like']) <= 100
    ) ? intval($_GET['jpeg_like']) : null;
    
    // 3: see if we have the "descriptors" key in the request payload:
    // if so, parse it, so we can mix&match it with the response afterwards
    $request_descriptors = [];
    if (
        array_key_exists('descriptors', $_POST) &&
        ($raw_descriptors = @json_decode($_POST['descriptors'], true)) !== null &&
        is_array($raw_descriptors)
    ) {
        foreach ($raw_descriptors as $i => $rd) {
            if (array_key_exists('filename', $rd)) {
                $filename = trim($rd['filename']);
                unset($rd['filename']);

                // remove keys that are used by us in the conversion part of the script
                if (array_key_exists('processable', $rd)) {
                    unset($rd['processable']);
                }
                if (array_key_exists('error', $rd)) {
                    unset($rd['error']);
                }
                if (array_key_exists('tempfile', $rd)) {
                    unset($rd['tempfile']);
                }

                // the md5() on the filename is to avoid malicious things done with array keys, on
                // maliciously-crafted filenames
                $request_descriptors[md5($filename)] = $rd;
            }
        }
    }

    // 4: parse the images in $_FILES and create a map of processable entities
    $request_files = [];
    foreach ($_FILES['images']['name'] as $file_index => $upload_name) {
        $upload_error = $_FILES['images']['error'][$file_index];
        $upload_size = $_FILES['images']['size'][$file_index];
        $upload_tmp_name = $_FILES['images']['tmp_name'][$file_index];
        $upload_type = $_FILES['images']['type'][$file_index];
        $upload_descriptor = md5(trim($upload_name));
        $upload_extension = strtolower(pathinfo($upload_name, PATHINFO_EXTENSION));
        $descriptor = (array_key_exists($upload_descriptor, $request_descriptors)) ? $request_descriptors[$upload_descriptor] : [];

        // Returns TRUE if the file named by filename was uploaded via HTTP POST.
        // This is useful to help ensure that a malicious user hasn't tried to trick
        // the script into working onfiles upon which it should not be working--for instance, /etc/passwd.
        if (is_uploaded_file($upload_tmp_name) === false) {
            $temp = [
                'processable' => false,
                'filename' => $upload_name,
                'error' => 'Cannot process a fake uploaded file'
            ];
            $temp = array_merge($temp, $descriptor);
            $request_files[] = $temp;
            continue;
        }

        // filter out all input files that are not an image (stupid, but serves to remove bad stuff)
        if (!in_array($upload_extension, ['jpg', 'jpeg', 'bmp', 'png', 'gif'])) {
            $temp = [
                'processable' => false,
                'filename' => $upload_name,
                'error' => 'This file extension is not allowed'
            ];
            $temp = array_merge($temp, $descriptor);
            $request_files[] = $temp;
            continue;
        }
        
        if ($upload_error === UPLOAD_ERR_OK) {
            $tempfile = TEMP_DIR . md5(microtime().rand(111111, 999999)) . '.' . $upload_extension;
            if (move_uploaded_file($upload_tmp_name, $tempfile)) {
                $temp = [
                    'processable' => true,
                    'filename' => $upload_name,
                    'tempfile' => $tempfile
                ];
                $temp = array_merge($temp, $descriptor);
                $request_files[] = $temp;
                continue;
            } else {
                $temp = [
                    'processable' => false,
                    'filename' => $upload_name,
                    'error' => 'Cannot move the upload file to the temporary process directory'
                ];
                $temp = array_merge($temp, $descriptor);
                $request_files[] = $temp;
                continue;
            }
        } else {
            $errmessage = 'Unknown error';
            switch ($upload_error) {
                case UPLOAD_ERR_INI_SIZE:
                    $errmessage = 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
                break;
                case UPLOAD_ERR_FORM_SIZE:
                    $errmessage = 'The uploaded file exceeds the MAX_FILE_SIZE directiveof the origin form';
                break;
                case UPLOAD_ERR_PARTIAL:
                    $errmessage = 'The uploaded file was only partially uploaded';
                break;
                case UPLOAD_ERR_NO_FILE:
                    $errmessage = 'No file was uploaded';
                break;
                case UPLOAD_ERR_NO_TMP_DIR:
                    $errmessage = 'Missing a temporary folder';
                break;
                case UPLOAD_ERR_CANT_WRITE:
                    $errmessage = 'Failed to write file to disk';
                break;
                case UPLOAD_ERR_EXTENSION:
                    $errmessage = 'A PHP extension stopped the file upload';
                break;
            }
            $temp = [
                'processable' => false,
                'filename' => $upload_name,
                'error' => $errmessage
            ];
            $temp = array_merge($temp, $descriptor);
            $request_files[] = $temp;
            continue;
        }
    }

    // 5: process the "processable" entities on $request_files
    $process_output = [];
    foreach ($request_files as $i => $rf) {
        if ($rf['processable']) {
            // create the command line for this conversion
            $outfile = TEMP_DIR . md5(microtime().rand(111111, 999999)) . '.webp';
            $command = CWEBP_BINARY . ' -m ' . $cwebp_compression;
            if ($cwebp_analysis_pass) {
                $command .= $command . ' -pass ' . $cwebp_analysis_pass;
            }
            if ($cwebp_lossless) {
                $command .= $command . ' -lossless';
            }
            if ($cwebp_near_lossless) {
                $command .= $command . ' -near_lossless ' . $cwebp_near_lossless;
            }
            if ($cwebp_hint) {
                $command .= $command . ' -hint ' . $cwebp_hint;
            }
            if ($cwebp_jpeg_like) {
                $command .= $command . ' -jpeg_like ' . $cwebp_jpeg_like;
            }
            $command .= ' -mt -quiet "' . $rf['tempfile'] . '" -o "' . $outfile . '"';

            // exec the command
            $bash_output = [];
            $retvar = exec($command, $bash_output);
            if (is_file($outfile)) {
                // ok, we're done!
                $orig_filesize = filesize($rf['tempfile']);
                $new_filesize = filesize($outfile);
                $compression_ratio = round((($orig_filesize-$new_filesize)/$orig_filesize)*100, 2);
                $rf['status'] = true;
                $rf['orig_filesize'] = human_filesize($orig_filesize);
                $rf['new_filesize'] = human_filesize($new_filesize);
                $rf['compression_ratio'] = $compression_ratio;
                $rf['webp_image_base64'] = @base64_encode(file_get_contents($outfile));
            } else {
                // an error occurred during conversion :(
                $rf['status'] = false;
                $rf['error'] = 'Cannot compress the image with cwebp';
            }

            if (is_file($rf['tempfile'])) {
                @unlink($rf['tempfile']);
            }
            if (is_file($outfile)) {
                @unlink($outfile);
            }
            
            // cleanup the item array
            unset($rf['tempfile']);
            unset($rf['processable']);

            // append the item status data to the response
            $process_output[] = $rf;
        } else {
            // append the "unprocessable" item status data to the response
            unset($rf['processable']);
            $rf['status'] = false;
            $rf['webp_image_base64'] = null;
            $process_output[] = $rf;
        }
    }

    // 6: finally, print out the response json
    output(true, ['response' => $process_output]);
    
    function output(bool $status, ?array $data = null, ?int $status_code = null)
    {
        global $init_time;

        $return_array = ['status' => false, 'version' => VERSION];
        if ($status) {
            $end_time = microtime(true);
            $return_array['status'] = true;
            $return_array['elapsed_time'] = round($end_time - $init_time, 2) . 's';
        }
        if (!empty($data)) {
            $return_array = array_merge($return_array, $data);
        }
        
        header_remove();
        if ($status_code) {
            http_response_code($status_code);
        }
        header('Content-Type: application/json');
        header('Content-Encoding: UTF-8');
        echo json_encode($return_array, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        exit();
    }

    function human_filesize($size, $precision = 2)
    {
        for ($i = 0; ($size / 1024) > 0.9; $i++, $size /= 1024) {
        }
        return round($size, $precision).['B','kB','MB','GB','TB','PB','EB','ZB','YB'][$i];
    }

    function preFlightChecksAndInit() : void
    {
        if (FROM_CLI) {
            die('This service can only be served from a LAMP environment (Apache/Nginx/etc). Nothing to do via CLI.');
        }
        if (!IS_HTTPS) {
            output(false, [
                'message' => 'Cannot proceed without HTTPS enabled. Please, enable HTTPS support on this virtual host.'
            ], 400);
        }
        if (!is_file(CONFIG_FILE)) {
            output(false, [
                'message' => 'Cannot file config file. Please, make sure to follow the installation instructions.'
            ], 503);
        }
        if (($config = parse_ini_file(CONFIG_FILE)) === false) {
            output(false, [
                'message' => 'The config file could not be parsed. Check the syntax of the config file.'
            ], 503);
        } else {
            foreach ($config as $key => $val) {
                // make the .env config key available
                // skip if the $val is an array: it is useless.
                if (!is_array($val)) {
                    define(strtoupper($key), $val);
                }
            }
        }
        if (!defined('API_KEY') || empty(API_KEY)) {
            output(false, [
                'message' => 'Cannot find a valid API_KEY defined in the config file. Please, make sure to follow the installation instructions.'
            ], 503);
        }
        if (!defined('SCRIPT_NAME') || empty(SCRIPT_NAME)) {
            output(false, [
                'message' => 'Cannot find a valid SCRIPT_NAME defined in the config file. Please, make sure to follow the installation instructions.'
            ], 503);
        }
        // check the SCRIPT_NAME against the request
        if (
            !array_key_exists('REQUEST_URI', $_SERVER) ||
            SCRIPT_NAME !== pathinfo(explode('?', $_SERVER['REQUEST_URI'])[0], PATHINFO_BASENAME)
        ) {
            output(false, [
                'message' => 'Cannot reply to the provided request uri.'
            ], 400);
        }
        if (($req_headers = getallheaders()) === false) {
            output(false, [
                'message' => 'Cannot reliably understand the request headers.'
            ], 400);
        } else {
            foreach ($req_headers as $key => $val) {
                if (in_array(strtolower($key), ['-x-api-key', '_x-api-key'])) {
                    define('REQUEST_API_KEY', $val);
                }
            }
        }
        if (!defined('REQUEST_API_KEY') || REQUEST_API_KEY !== API_KEY) {
            output(false, [
                'message' => 'Invalid api key provided.'
            ], 401);
        }
        if (!function_exists('exec') || !is_callable('exec')) {
            output(false, [
                'message' => 'The "exec" function cannot be called. This microservice relies upon calling exec().'
            ], 503);
        }
        $cwepb_binary = [];
        $cwepb_binary_check_retvar = exec('which cwebp', $cwepb_binary);
        $cwepb_binary = implode('', $cwepb_binary);
        if (empty($cwepb_binary)) {
            output(false, [
                'message' => 'The "cwebp" binary cannot be found on your environment. On Ubuntu/Debian, run "sudo apt install webp"'
            ], 500);
        } else {
            define('CWEBP_BINARY', trim($cwepb_binary));
        }
        if (!is_dir(TEMP_DIR)) {
            @mkdir(TEMP_DIR, 0755);
        }
        if (!is_dir(TEMP_DIR)) {
            output(false, [
                'message' => 'Cannot create the required temporary directory. Please check your environment.'
            ], 500);
        }
    }