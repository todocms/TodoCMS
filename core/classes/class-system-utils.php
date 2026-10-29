<?php
/**
 * Todo System Utils
 *
 * Provides unencrypted system utility functions necessary for standalone operation
 * during bootstrapping or when the core kernel cannot be decrypted.
 */

class Todo_System_Utils
{
    /**
     * Get client fingerprint for license verification
     * 
     * @return string SHA256 hash of system fingerprint
     */
    public static function get_client_fingerprint()
    {
        $domain = function_exists('todo_get_http_host')
            ? todo_get_http_host()
            : (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost');

        $parts = array(
            'd' => strtolower($domain),
            'p' => defined('ABSPATH') ? substr(sha1(ABSPATH), 0, 16) : '',
            'v' => PHP_VERSION,
            'os' => PHP_OS,
            'sapi' => PHP_SAPI,
            'db' => (defined('DB_HOST') ? DB_HOST : '') . '|' . (defined('DB_NAME') ? substr(md5(DB_NAME), 0, 8) : ''),
            'key' => defined('AUTH_KEY') ? substr(sha1(AUTH_KEY . (defined('AUTH_SALT') ? AUTH_SALT : '')), 0, 16) : ''
        );

        return hash('sha256', implode('|', $parts));
    }

    /**
     * HTTP POST JSON request
     * 
     * @param string $url Target URL
     * @param array|string $payload Request payload
     * @param array $headers Additional headers
     * @param int $timeout Timeout in seconds
     * @return array Response data
     */
    public static function http_post_json($url, $payload, $headers = array(), $timeout = 12)
    {
        $headers = array_merge(array(
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Requested-With: XMLHttpRequest'
        ), $headers);

        $context = stream_context_create(array(
            'http' => array(
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'timeout' => $timeout,
                'ignore_errors' => true,
            )
        ));

        $body = @file_get_contents($url, false, $context);
        $status = 0;

        $hdr_var = 'http_response_header';
        $resp_headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : (isset($$hdr_var) && is_array($$hdr_var) ? $$hdr_var : null);
        if (is_array($resp_headers)) {
            foreach ($resp_headers as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int) $m[1];
                    break;
                }
            }
        }

        $timeout_ms = $timeout;
        if ($body === false && function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout_ms);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }

        return array(
            'status' => $status,
            'body' => $body,
            'json' => $body ? json_decode($body, true) : null
        );
    }

    /**
     * Verify OpenSSL signature
     * 
     * @param array $data_array Data to verify
     * @param string $signature_b64 Base64 encoded signature
     * @param string $public_key Public key
     * @return bool Verification result
     */
    public static function openssl_verify_signature($data_array, $signature_b64, $public_key)
    {
        if (!function_exists('openssl_verify')) {
            return false;
        }

        if (empty($public_key) || empty($signature_b64)) {
            return false;
        }

        $payload = json_encode($data_array, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = base64_decode($signature_b64);
        $algo = defined('OPENSSL_ALGO_SHA256') ? OPENSSL_ALGO_SHA256 : 1;

        $res = openssl_pkey_get_public($public_key);
        if (!$res) {
            return false;
        }

        $ok = openssl_verify($payload, $signature, $res, $algo);
        openssl_free_key($res);

        return $ok === 1;
    }
}
