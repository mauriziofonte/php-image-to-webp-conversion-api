<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Fixtures\Http;

/**
 * A minimal HTTP/1.0 client on PHP streams, for the integration tests: no
 * extension needed, no redirect followed, every status returned. HTTP/1.0
 * keeps the servers from chunking the bodies, which the stream wrapper
 * would not decode.
 */
final class HttpClient
{
    /**
     * @var string|null
     */
    private $caFile;

    /**
     * @var string|null
     */
    private $peerName;

    /**
     * @param string|null $caFile   the certificate that signs the server's, for HTTPS
     * @param string|null $peerName the name the server certificate is checked against, for HTTPS to an address
     */
    public function __construct(?string $caFile = null, ?string $peerName = null)
    {
        $this->caFile = $caFile;
        $this->peerName = $peerName;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, list<string>>, body: string}
     */
    public function request(string $method, string $url, array $headers = [], string $body = ''): array
    {
        $lines = ['Connection: close'];
        if ($this->peerName !== null) {
            $lines[] = 'Host: ' . $this->peerName;
        }
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
        if ($this->caFile !== null) {
            $ssl['cafile'] = $this->caFile;
        }
        if ($this->peerName !== null) {
            $ssl['peer_name'] = $this->peerName;
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $lines),
                'content' => $body,
                'ignore_errors' => true,
                'follow_location' => 0,
                'protocol_version' => 1.0,
                'timeout' => 120,
            ],
            'ssl' => $ssl,
        ]);

        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            $error = error_get_last();

            throw new \RuntimeException(sprintf('%s %s failed: %s', $method, $url, $error !== null ? $error['message'] : 'unknown error'));
        }
        $meta = stream_get_meta_data($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return self::parse(isset($meta['wrapper_data']) && is_array($meta['wrapper_data']) ? $meta['wrapper_data'] : [], is_string($content) ? $content : '');
    }

    /**
     * A multipart/form-data body and its Content-Type.
     *
     * @param array<string, string>                                                   $fields
     * @param list<array{field: string, name: string, type: string, content: string}> $files
     *
     * @return array{string, string}
     */
    public static function multipart(array $fields, array $files): array
    {
        $boundary = 'ica-' . bin2hex(random_bytes(8));
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $name . "\"\r\n\r\n" . $value . "\r\n";
        }
        foreach ($files as $file) {
            $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $file['field'] . '"; filename="' . $file['name'] . "\"\r\n"
                . 'Content-Type: ' . $file['type'] . "\r\n\r\n" . $file['content'] . "\r\n";
        }

        return [$body . '--' . $boundary . "--\r\n", 'multipart/form-data; boundary=' . $boundary];
    }

    /**
     * @param array<mixed> $lines
     *
     * @return array{status: int, headers: array<string, list<string>>, body: string}
     */
    private static function parse(array $lines, string $body): array
    {
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            if (!is_string($line)) {
                continue;
            }
            if (preg_match('#\AHTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $match) === 1) {
                // A new status line starts the headers of the response that counts.
                $status = (int) $match[1];
                $headers = [];
            } elseif (strpos($line, ':') !== false) {
                list($name, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($name))][] = trim($value);
            }
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
