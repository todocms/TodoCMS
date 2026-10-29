<?php
/**
 * TodoCMS 更新服务配置
 * - 定义更新服务器地址与授权域名
 * - 提供统一读取接口
 */

// 直接访问保护
if (!defined('ABSPATH')) {
    exit('Direct access denied.');
}

// 是否启用在线更新
if (!defined('TODO_UPDATE_ENABLED')) {
    define('TODO_UPDATE_ENABLED', true);
}

// 更新服务器 API 端点
if (!defined('TODO_UPDATE_SERVER_URL')) {
    $override_api = (function_exists('todo_get_option') ? (string) todo_get_option('todo_update_server_url') : '');
    if (!empty($override_api)) {
        define('TODO_UPDATE_SERVER_URL', $override_api);
    } else {
        define('TODO_UPDATE_SERVER_URL', '');
    }
}

// 下载服务端点
if (!defined('TODO_DOWNLOAD_SERVER_URL')) {
    $override_dl = (function_exists('todo_get_option') ? (string) todo_get_option('todo_download_server_url') : '');
    if (!empty($override_dl)) {
        define('TODO_DOWNLOAD_SERVER_URL', $override_dl);
    } else {
        define('TODO_DOWNLOAD_SERVER_URL', '');
    }
}

// 更新服务器公钥路径（用于验签），支持设置项覆盖
if (!defined('TODO_UPDATE_SERVER_PUBLIC_KEY')) {
    $override_key = (function_exists('todo_get_option') ? (string) todo_get_option('todo_update_public_key_path') : '');
    if (!empty($override_key)) {
        define('TODO_UPDATE_SERVER_PUBLIC_KEY', $override_key);
    } else {
        define('TODO_UPDATE_SERVER_PUBLIC_KEY', ABSPATH . '/core/config/update_public_key.pem');
    }
}

// 授权域名（提交给更新服务器做授权校验），支持设置项覆盖
if (!defined('TODO_UPDATE_LICENSE_DOMAIN')) {
    $override_domain = (function_exists('todo_get_option') ? (string) todo_get_option('todo_update_license_domain') : '');
    if (!empty($override_domain)) {
        // 去除协议和端口，统一小写
        $override_domain = preg_replace('#^https?://#', '', $override_domain);
        $override_domain = preg_replace('/:\\d+$/', '', $override_domain);
        define('TODO_UPDATE_LICENSE_DOMAIN', strtolower($override_domain));
    } else {
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // 去除端口
        $domain = preg_replace('/:\\d+$/', '', $domain);
        define('TODO_UPDATE_LICENSE_DOMAIN', strtolower($domain));
    }
}

// 请求超时与重试策略
if (!defined('TODO_UPDATE_HTTP_TIMEOUT')) {
    define('TODO_UPDATE_HTTP_TIMEOUT', 20); // 秒（适当增加以兼容网络波动）
}
if (!defined('TODO_UPDATE_HTTP_RETRY')) {
    define('TODO_UPDATE_HTTP_RETRY', 2); // 失败重试次数（增加一次重试）
}

// 日志文件
if (!defined('TODO_UPDATE_LOG_FILE')) {
    define('TODO_UPDATE_LOG_FILE', ABSPATH . '/logs/update.log');
}

// 统一读取更新配置
function todo_get_update_config()
{
    return array(
        'enabled' => (bool) TODO_UPDATE_ENABLED,
        'api' => (string) TODO_UPDATE_SERVER_URL,
        'download' => (string) TODO_DOWNLOAD_SERVER_URL,
        'domain' => (string) TODO_UPDATE_LICENSE_DOMAIN,
        'timeout' => (int) TODO_UPDATE_HTTP_TIMEOUT,
        'retry' => (int) TODO_UPDATE_HTTP_RETRY,
        'logfile' => (string) TODO_UPDATE_LOG_FILE,
    );
}
