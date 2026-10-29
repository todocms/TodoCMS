<?php
/**
 * TodoCMS 更新服务与授权校验辅助
 *
 * 提供：
 * - 客户端指纹生成 todo_get_client_fingerprint()
 * - HTTP JSON 请求封装 todo_http_post_json(), todo_http_get_json()
 * - 响应签名验签 todo_openssl_verify_signature()
 * - 通过授权服务器检查核心更新 todo_check_core_updates_via_license()
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问（但允许AJAX请求）
if (!defined('ABSPATH') && !defined('DOING_AJAX')) {
    exit('Direct access denied');
}

if (!function_exists('todo_base64url_encode')) {
    function todo_base64url_encode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('todo_base64url_decode')) {
    function todo_base64url_decode($data)
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $data .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
if (!function_exists('todo_get_client_fingerprint')) {
    function todo_get_client_fingerprint()
    {
        if (class_exists('Todo_Core_Kernel') && is_callable(['Todo_Core_Kernel', 'get_client_fingerprint'])) {
            return Todo_Core_Kernel::get_client_fingerprint();
        }
        $utils_path = defined('ABSPATH') ? ABSPATH . 'core/classes/class-system-utils.php' : dirname(__DIR__) . '/classes/class-system-utils.php';
        if (is_file($utils_path) && !class_exists('Todo_System_Utils')) require_once $utils_path;
        if (class_exists('Todo_System_Utils')) return Todo_System_Utils::get_client_fingerprint();
        return '';
    }
}

if (!function_exists('todo_http_post_json')) {
    function todo_http_post_json($url, $payload, $headers = array(), $timeout = 12)
    {
        $utils_path = defined('ABSPATH') ? ABSPATH . 'core/classes/class-system-utils.php' : dirname(__DIR__) . '/classes/class-system-utils.php';
        if (is_file($utils_path) && !class_exists('Todo_System_Utils')) require_once $utils_path;
        if (class_exists('Todo_System_Utils')) return Todo_System_Utils::http_post_json($url, $payload, $headers, $timeout);
        return class_exists('Todo_Core_Kernel') ? Todo_Core_Kernel::http_post_json($url, $payload, $headers, $timeout) : false;
    }
}

if (!function_exists('todo_http_get_json')) {
    function todo_http_get_json($url, $headers = array(), $timeout = 12)
    {
        $headers = array_merge(array('Accept: application/json'), $headers);
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
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
        if ($body === false && function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
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
}

if (!function_exists('todo_openssl_verify_signature')) {
    function todo_openssl_verify_signature($data_array, $signature_b64, $public_key)
    {
        $utils_path = defined('ABSPATH') ? ABSPATH . 'core/classes/class-system-utils.php' : dirname(__DIR__) . '/classes/class-system-utils.php';
        if (is_file($utils_path) && !class_exists('Todo_System_Utils')) require_once $utils_path;
        if (class_exists('Todo_System_Utils')) return Todo_System_Utils::openssl_verify_signature($data_array, $signature_b64, $public_key);
        return class_exists('Todo_Core_Kernel') ? Todo_Core_Kernel::openssl_verify_signature($data_array, $signature_b64, $public_key) : false;
    }
}

if (!function_exists('todo_check_core_updates_via_license')) {
    /**
     * 通过授权服务器检查核心更新（双验证协议）
     *
     * 流程：
     * 1) 组装客户端指纹与请求负载，POST 授权服务器 verify 接口
     * 2) 校验授权服务器响应签名（非对称验签）
     * 3) 返回标准化的更新信息（包含 download_url 与 token_endpoint 供二次验证）
     *
     * @param string $endpoint 授权服务器验证接口地址
     * @param string $public_key 授权服务器公钥（PEM）
     * @param string $current_version 当前CMS版本
     * @return array|false 成功返回更新信息数组，失败返回 false
     */
    function todo_check_core_updates_via_license($endpoint, $public_key, $current_version)
    {
        if (empty($endpoint)) {
            return false;
        }
        // 构建请求负载
        $domain = function_exists('todo_get_http_host') ? todo_get_http_host() : (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost');
        $payload = array(
            'domain' => strtolower($domain),
            'cms_version' => $current_version,
            'ts' => time(),
            'nonce' => function_exists('random_bytes') ? bin2hex(random_bytes(8)) : substr(sha1(uniqid('', true)), 0, 16),
            'fingerprint' => todo_get_client_fingerprint(),
        );
        // 发送请求
        $resp = todo_http_post_json($endpoint, $payload, array(), 12);
        if (!is_array($resp['json']) || empty($resp['json']['success'])) {
            return false;
        }
        $j = $resp['json'];
        // 验签（非对称RSA）
        if (!empty($public_key) && !empty($j['signature']) && !empty($j['ts']) && !empty($j['data'])) {
            $data = $j['data'];
            $ok = todo_openssl_verify_signature(array(
                'latest' => isset($data['latest']) ? $data['latest'] : '',
                'download_url' => isset($data['download_url']) ? $data['download_url'] : '',
                'ts' => $j['ts']
            ), $j['signature'], $public_key);
            if (!$ok) {
                return false;
            }
        }
        // 组装返回
        $data = isset($j['data']) && is_array($j['data']) ? $j['data'] : array();
        return array(
            'current' => $current_version,
            'latest' => isset($data['latest']) ? $data['latest'] : $current_version,
            'download_url' => isset($data['download_url']) ? $data['download_url'] : '',
            'token_endpoint' => isset($data['token_endpoint']) ? $data['token_endpoint'] : '',
            'changelog' => isset($data['changelog']) ? $data['changelog'] : '',
            'requires_php' => isset($data['requires_php']) ? $data['requires_php'] : '7.4',
            'requires_mysql' => isset($data['requires_mysql']) ? $data['requires_mysql'] : '5.6',
            'checksum_md5' => isset($data['checksum_md5']) ? $data['checksum_md5'] : '',
            'signature' => isset($j['signature']) ? $j['signature'] : '',
            'ts' => isset($j['ts']) ? $j['ts'] : 0
        );
    }

    /**
     * 简化的授权更新检查（新版本）
     * 
     * @param string $server_url 授权服务器URL
     * @param string $current_version 当前版本
     * @return array|false 更新信息或false
     */
    function todo_check_updates_simple($server_url = '', $current_version = '')
    {
        if (empty($server_url)) {
            $server_url = defined('TODO_LICENSE_SERVER_URL') ? TODO_LICENSE_SERVER_URL : '';
        }

        if (empty($server_url)) {
            return false;
        }

        if (empty($current_version)) {
            $current_version = function_exists('todo_get_cms_version') ? todo_get_cms_version() : (defined('TODO_VERSION') ? TODO_VERSION : '1.0.0');
        }

        $domain = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $domain = preg_replace('/:\d+$/', '', $domain); // 移除端口号
        $license_key = get_option('todo_license_key'); // 假设授权码存储在选项中

        // 1. 验证授权
        $validation_payload = array(
            'domain' => $domain,
            'license_key' => $license_key
        );

        $validation_response = todo_http_post_json($server_url . '?action=validate_license', $validation_payload);

        if (!$validation_response || $validation_response['status'] !== 200 || empty($validation_response['json']['status']) || $validation_response['json']['status'] !== 'success') {
            update_option('todo_license_status', 'invalid');
            return false; // 授权失败
        }

        update_option('todo_license_status', 'valid');
        $update_token = $validation_response['json']['update_token'];

        // 2. 获取更新包信息
        $update_payload = array(
            'domain' => $domain,
            'token' => $update_token
        );

        $update_response = todo_http_post_json($server_url . '?action=get_update_package', $update_payload);

        if (!$update_response || $update_response['status'] !== 200 || empty($update_response['json']['status']) || $update_response['json']['status'] !== 'success') {
            return false; // 获取更新包信息失败
        }

        return $update_response['json'];
    }

    /**
     * 获取下载URL
     * 
     * @param string $server_url 授权服务器URL
     * @param string $version 要下载的版本
     * @return array|false 下载信息或false
     */
    function todo_get_download_url($server_url = '', $version = '')
    {
        if (empty($server_url)) {
            $server_url = defined('TODO_LICENSE_SERVER_URL') ? TODO_LICENSE_SERVER_URL : 'http://license.todocms.com';
        }

        if (empty($version)) {
            $version = defined('TODO_DEFAULT_LATEST_VERSION') ? TODO_DEFAULT_LATEST_VERSION : '1.5.0';
        }

        // 获取当前域名
        $domain = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $domain = preg_replace('/:\d+$/', '', $domain); // 移除端口号

        // 组装请求数据
        $payload = array(
            'action' => 'get_download_url',
            'domain' => $domain,
            'version' => $version,
            'fingerprint' => todo_get_client_fingerprint(),
            'timestamp' => time()
        );

        // 发送请求
        $response = todo_http_post_json($server_url . '/api.php', $payload);

        if (!$response || !is_array($response)) {
            return false;
        }

        // 检查错误
        if (isset($response['error'])) {
            error_log('TodoCMS Download URL Error: ' . $response['error']);
            return false;
        }

        return $response;
    }

    /**
     * Verify license status
     * 
     * @param string $server_url License server URL
     * @return array|false License information or false
     */
    function todo_verify_license($server_url = '')
    {
        return Todo_Core_Kernel::verify_license($server_url);
    }
}
