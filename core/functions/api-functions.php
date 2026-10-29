<?php
/**
 * API函数文件
 *
 * 提供API相关的辅助函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 确保加载 API 核心类（包含 Todo_API 类及 cms_api() 函数）
if (!function_exists('cms_api')) {
    if (defined('ABSPATH') && file_exists(ABSPATH . 'core/classes/class-api.php')) {
        require_once ABSPATH . 'core/classes/class-api.php';
    }
}

/**
 * 注册API路由
 *
 * @param string $endpoint 端点名称
 * @param string $method 请求方法 (GET, POST, PUT, DELETE, PATCH)
 * @param callable $handler 处理函数
 */
function register_api_route($endpoint, $method, $handler) {
    cms_api()->register_route($endpoint, $method, $handler);
}

/**
 * 设置API认证方法
 *
 * @param string $method 认证方法 (jwt, api_key, none)
 */
function set_api_auth_method($method) {
    cms_api()->set_auth_method($method);
}

/**
 * 生成JWT令牌
 *
 * @param int $user_id 用户ID
 * @param string $username 用户名
 * @param array $extra_data 额外数据
 * @param int $expiration 过期时间（秒）
 * @return string JWT令牌
 */
function generate_api_jwt($user_id, $username, $extra_data = [], $expiration = 3600) {
    // 获取密钥：仅从数据库读取，不回退到任何静态配置常量（防密钥泄露伪造攻击）
    $secret_key = trim((string) get_option('jwt_secret_key', ''));
    if (empty($secret_key)) {
        // 自动生成安全随机密钥并持久化，杜绝使用可预测的静态字符串
        $secret_key = bin2hex(random_bytes(32));
        update_option('jwt_secret_key', $secret_key);
    }
    
    // 头部
    $header = [
        'typ' => 'JWT',
        'alg' => 'HS256'
    ];
    $header_base64 = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($header)));
    
    // 载荷
    $payload = array_merge([
        'sub' => $user_id,
        'name' => $username,
        'iat' => time(),
        'exp' => time() + $expiration
    ], $extra_data);
    $payload_base64 = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload)));
    
    // 签名
    $signature = hash_hmac('sha256', $header_base64 . '.' . $payload_base64, $secret_key, true);
    $signature_base64 = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    // 组合JWT
    return $header_base64 . '.' . $payload_base64 . '.' . $signature_base64;
}

/**
 * 生成API密钥
 *
 * @param int $user_id 用户ID
 * @return string API密钥
 */
function generate_api_key($user_id = 0) {
    // 生成随机密钥
    $api_key = bin2hex(random_bytes(16));
    
    // 存储密钥
    $api_keys = get_option('api_keys', []);
    $api_keys[$user_id] = $api_key;
    update_option('api_keys', $api_keys);
    
    return $api_key;
}

/**
 * 验证API密钥
 *
 * @param string $api_key API密钥
 * @return bool 是否有效
 */
function validate_api_key($api_key) {
    $api_keys = get_option('api_keys', []);
    return in_array($api_key, $api_keys);
}

/**
 * 获取API用户ID
 *
 * @param string $api_key API密钥
 * @return int 用户ID
 */
function get_api_user_id($api_key) {
    $api_keys = get_option('api_keys', []);
    return array_search($api_key, $api_keys) ?: 0;
}

/**
 * 撤销API密钥
 *
 * @param string $api_key API密钥
 * @return bool 是否成功
 */
function revoke_api_key($api_key) {
    $api_keys = get_option('api_keys', []);
    $user_id = array_search($api_key, $api_keys);
    
    if ($user_id !== false) {
        unset($api_keys[$user_id]);
        update_option('api_keys', $api_keys);
        return true;
    }
    
    return false;
}

/**
 * 获取用户的API密钥
 *
 * @param int $user_id 用户ID
 * @return string|false API密钥或false
 */
function get_user_api_key($user_id) {
    $api_keys = get_option('api_keys', []);
    return isset($api_keys[$user_id]) ? $api_keys[$user_id] : false;
}

/**
 * 发送API响应
 *
 * @param mixed $data 响应数据
 */
function send_api_response($data) {
    cms_api()->send_response($data);
}

/**
 * 发送API错误
 *
 * @param int $code HTTP状态码
 * @param string $message 错误消息
 */
function send_api_error($code, $message, $data = null) {
    cms_api()->send_error($code, $message, $data);
}

/**
 * 生成JWT令牌（基于载荷）
 *
 * @param array $payload JWT载荷数据
 * @return string JWT令牌
 */
function generate_jwt($payload) {
    // 获取密钥：仅从数据库读取，不回退到任何静态配置常量（防密钥泄露伪造攻击）
    $secret_key = trim((string) get_option('jwt_secret_key', ''));
    if (empty($secret_key)) {
        // 自动生成安全随机密钥并持久化
        $secret_key = bin2hex(random_bytes(32));
        update_option('jwt_secret_key', $secret_key);
    }
    
    // 头部
    $header = [
        'typ' => 'JWT',
        'alg' => 'HS256'
    ];
    $header_base64 = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($header)));
    
    // 载荷已由调用者提供
    $payload_base64 = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload)));
    
    // 签名
    $signature = hash_hmac('sha256', $header_base64 . '.' . $payload_base64, $secret_key, true);
    $signature_base64 = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    // 组合JWT
    return $header_base64 . '.' . $payload_base64 . '.' . $signature_base64;
}
