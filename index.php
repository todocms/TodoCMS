<?php
/**
 * 主入口文件
 *
 * 加载核心文件并初始化系统
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 记录开始时间
define('CMS_START_TIME', microtime(true));

// 开启输出缓冲
ob_start(function ($buffer) {
    if (http_response_code() === 200 && class_exists('Todo_Firewall_Manager')) {
        $buffer = Todo_Firewall_Manager::inject_honeypot_link($buffer);
    }
    if (function_exists('todo_seo_output_filter')) {
        return todo_seo_output_filter($buffer);
    }
    return $buffer;
});

// 检查配置文件是否存在
if (!file_exists(__DIR__ . '/config.php')) {
    // 如果配置文件不存在，重定向到安装页面（支持二级目录）
    $__script_dir = isset($_SERVER['SCRIPT_NAME']) ? rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') : '';
    $__prefix = $__script_dir ? $__script_dir : '';
    header('Location: ' . $__prefix . '/install.php');
    exit;
}

// 加载配置文件
require_once dirname(__FILE__) . '/config.php';

// Load Composer Autoloader
require_once dirname(__FILE__) . '/vendor/autoload.php';

// 加载并启动 TodoCMS 防火墙防采集拦截模块
if (file_exists(dirname(__FILE__) . '/core/classes/class-firewall-manager.php')) {
    require_once dirname(__FILE__) . '/core/classes/class-firewall-manager.php';
    Todo_Firewall_Manager::handle_request();
}

// 启动会话（前台使用独立会话名，避免与后台 PHPSESSID 冲突）
if (class_exists('Todo_Session_Manager')) {
    Todo_Session_Manager::init();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 定义URL常量
if (!defined('TODO_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $path = $script !== '' ? dirname($script) : '';
    $path = ($path === '/' || $path === '\\' || $path === '.') ? '' : $path;
    define('TODO_URL', $protocol . $host . $path);
}

// 加载核心引导文件
$bootstrap_start = microtime(true);
require_once CORE_DIR . '/bootstrap.php';
if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::add_metric('bootstrap', 'Core Bootstrap', (microtime(true) - $bootstrap_start) * 1000);
}

// 加载函数加载器
require_once CORE_DIR . '/functions/function-loader.php';

// 使用函数加载器加载核心函数
Todo_Function_Loader::load_core_functions();

// 确保关键函数文件被加载
if (!function_exists('maybe_unserialize')) {
    require_once CORE_DIR . '/functions/formatting.php';
}

// 加载数据库架构
require_once CORE_DIR . '/db/schema.php';

/**
 * 检查CMS是否已安装
 *
 * @return bool 是否已安装
 */
if (!function_exists('is_cms_installed')) {
    function is_cms_installed()
    {
        // 优先使用统一的安装状态检测逻辑（包含自动重连和更稳健的表检测）
        if (function_exists('todo_is_installed')) {
            return todo_is_installed();
        }

        // 检查配置文件是否存在
        if (!file_exists(ABSPATH . 'config.php')) {
            return false;
        }

        // 尝试连接数据库并检查system_config表是否存在
        try {
            global $tododb;

            // 如果数据库连接不存在，尝试初始化
            if (!$tododb) {
                require_once ABSPATH . 'core/bootstrap.php';
            }

            // 使用 information_schema 更稳健地检测表是否存在
            $dbName = defined('DB_NAME') ? DB_NAME : (defined('DATABASE_NAME') ? constant('DATABASE_NAME') : null);
            $tableExists = $tododb->get_var(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1",
                [$dbName, TABLE_PREFIX . 'system_config']
            );
            if (!$tableExists) {
                return false;
            }

            // 检查 installed 选项
            $installed = $tododb->get_var(
                "SELECT config_value FROM " . TABLE_PREFIX . "system_config WHERE config_key = 'installed' AND group_name = 'general' LIMIT 1"
            );
            return $installed === '1';
        } catch (Exception $e) {
            error_log('检查安装状态时出错: ' . $e->getMessage());
            return false;
        }
    }
}

// 检查CMS是否已安装
if (!is_cms_installed()) {
    // 支持二级目录：使用当前脚本目录作为前缀
    $__script_dir = isset($_SERVER['SCRIPT_NAME']) ? rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') : '';
    $__prefix = $__script_dir ? $__script_dir : '';
    header('Location: ' . $__prefix . '/install.php');
    exit;
}

// 处理请求
$request_start = microtime(true);
cms_handle_request();
if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::add_metric('request', 'Request Handling', (microtime(true) - $request_start) * 1000);
}

// 只有在非管理后台时才加载主题
if (!defined('IS_ADMIN') || !IS_ADMIN) {
    error_log('准备加载主题');
    // 加载主题
    $theme_start = microtime(true);
    cms_load_theme();
    if (class_exists('Todo_Performance_Monitor')) {
        Todo_Performance_Monitor::add_metric('theme', 'Theme Loading', (microtime(true) - $theme_start) * 1000);
    }
} else {
    error_log('跳过主题加载 - IS_ADMIN=' . (defined('IS_ADMIN') ? (IS_ADMIN ? 'true' : 'false') : 'undefined'));
}

// 记录总页面加载时间
if (class_exists('Todo_Performance_Monitor') && defined('CMS_START_TIME')) {
    Todo_Performance_Monitor::add_metric('total', 'Total Page Load', (microtime(true) - CMS_START_TIME) * 1000);
}
