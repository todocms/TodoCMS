<?php
ob_start();
/**
 * TodoCMS 后台入口伪装页（隐藏真实入口 + 智能重定向）
 * @package TodoCMS
 * @version 1.0.0
 * 访问 /admin 或 /admin/index.php：
 *  - 未安装：跳转到安装页面
 *  - 已安装：
 *      · 未登录：返回 404，避免暴露任何信息
 *      · 已登录：内部转发到真实入口（不在 URL 中暴露文件名）
 */

// Load Composer Autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(__FILE__)) . '/');
}
require_once ABSPATH . 'config.php';
require_once ABSPATH . 'vendor/autoload.php';

// 定义后台常量
if (!defined('IS_ADMIN')) {
    define('IS_ADMIN', true);
}
if (!defined('TODO_ADMIN')) {
    define('TODO_ADMIN', true);
}

// 确保会话已启动（用于读取登录态）
session_status() !== PHP_SESSION_ACTIVE && session_start();

// 定义 is_user_logged_in 函数（如果不存在）
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in()
    {
        // 使用 Todo_Session_Manager 检查登录状态
        if (class_exists('Todo_Session_Manager')) {
            Todo_Session_Manager::init();
            if (defined('IS_ADMIN') && IS_ADMIN) {
                $logged_in = Todo_Session_Manager::is_admin_logged_in();
                if (!$logged_in && defined('TODO_DEMO_ADMIN_ENABLED') && TODO_DEMO_ADMIN_ENABLED) {
                    if (isset($_SESSION['todo_demo_admin']) && (int)$_SESSION['todo_demo_admin'] === 1) {
                        $logged_in = true;
                    }
                }
                return $logged_in;
            } else {
                return Todo_Session_Manager::is_frontend_logged_in();
            }
        }
        return false;
    }
}

// 如果未安装（无 config.php），跳转到安装页面（项目根目录安装器）
$config_file = ABSPATH . 'config.php';
if (!file_exists($config_file)) {
    header('Location: ' . rtrim(dirname(dirname($_SERVER['REQUEST_URI'])), '/') . '/install.php');
    exit;
}

// 检查数据库是否已安装（防止清空数据库后的重定向循环）
try {
    require_once $config_file;

    // 使用全局数据库连接
    global $tododb;
    if (!$tododb) {
        // 注意：这里不应再次包含 bootstrap.php，因为它会包含 autoload
        if (!function_exists('get_database_connection')) {
            // 通常 config.php 应该已经建立了连接或提供了凭据
        }
    }
} catch (Exception $e) {
    // 数据库连接失败，跳转到安装页面
    header('Location: ' . rtrim(dirname(dirname($_SERVER['REQUEST_URI'])), '/') . '/install.php');
    exit;
}

// Ensure core is initialized to satisfy admin direct access guards
require_once ABSPATH . 'core/bootstrap.php';

if ((isset($_SESSION['admin_user_id']) || isset($_SESSION['todo_admin_uid'])) && class_exists('Todo_Core_Kernel') && is_callable(['Todo_Core_Kernel', 'check_runtime_license']) && !call_user_func(['Todo_Core_Kernel', 'check_runtime_license'])) {
    $page = (string) ($_GET['page'] ?? '');
    $action = (string) ($_GET['action'] ?? '');
    if ($action !== 'logout' && $page !== 'updates' && $page !== 'setting/updates') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow', true);
        echo '403 Forbidden';
        exit;
    }
}

// 再次检查安装状态 (using autoloaded functions/classes)
try {
    // 更稳健的安装状态检测：优先使用统一函数，其次使用 information_schema
    $installed = false;
    if (function_exists('todo_is_installed')) {
        $installed = todo_is_installed();
    } elseif (function_exists('is_cms_installed')) {
        $installed = is_cms_installed();
    } elseif (class_exists('CMS_Option')) {
        $installed = (CMS_Option::get('installed') == '1');
    }

    if (!$installed) {
        // 系统未安装或关键表不存在，跳转到安装页面
        header('Location: ' . rtrim(dirname(dirname($_SERVER['REQUEST_URI'])), '/') . '/install.php');
        exit;
    }
} catch (Exception $e) {
    // Ignore
}

// 处理登出请求（支持 ?action=logout&redirect_to=...）
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    if (function_exists('todo_logout')) {
        todo_logout();
    }
    $redirect_to = isset($_GET['redirect_to']) ? (string) $_GET['redirect_to'] : '';
    if ($redirect_to === '') {
        if (!function_exists('todo_home_url')) {
            @require_once ABSPATH . 'core/functions/todo-core-functions.php';
        }
        $redirect_to = function_exists('todo_home_url') ? todo_home_url('/') : '/';
    }
    redirect($redirect_to);
}

/**
 * 智能后台重定向函数
 *
 * 设计原则（安全隐蔽）：
 *  - 未登录：一律返回 404，不暴露任何后台信息
 *  - 已登录：内部转发到真实入口（URL 中不暴露真实文件名）
 *
 * 注意：任何对 /admin 或 /admin/index.php 的探测都应得到 404。
 */
if (!function_exists('perform_admin_redirect')) {
    function perform_admin_redirect()
    {
        if (!class_exists('Todo_Session_Manager')) {
            if (!defined('ABSPATH')) {
                define('ABSPATH', dirname(dirname(__FILE__)) . '/');
            }
            if (!class_exists('Todo_Session_Manager')) {
                // Session 管理器不可用，直接 404
                return_404();
            }
        }
        Todo_Session_Manager::init();

        // 未登录用户：始终返回 404，保持入口隐蔽性
        $is_admin_logged_in = Todo_Session_Manager::is_admin_logged_in();

        if (!$is_admin_logged_in && defined('TODO_DEMO_ADMIN_ENABLED') && TODO_DEMO_ADMIN_ENABLED) {
            if (isset($_SESSION['todo_demo_admin']) && (int)$_SESSION['todo_demo_admin'] === 1) {
                $is_admin_logged_in = true;
            }
        }

        if (!$is_admin_logged_in) {
            return_404();
        }

        if (function_exists('todo_cleanup_default_admin_login_file')) {
            todo_cleanup_default_admin_login_file();
        } else {
            $root_dir = defined('ABSPATH') ? ABSPATH : dirname(__DIR__) . '/';
            $install_file = rtrim($root_dir, '/\\') . '/install.php';
            if (file_exists($install_file)) {
                $random_suffix = substr(md5(time() . mt_rand(1000, 9999)), 0, 17);
                @rename($install_file, rtrim($root_dir, '/\\') . '/install_' . $random_suffix . '.php');
            }

            $db_admin_base = class_exists('CMS_Option') ? CMS_Option::get('site_adminbasefile') : '';
            if (!empty($db_admin_base) && $db_admin_base !== 'todo-login') {
                $admin_dir = defined('ADMIN_DIR') ? ADMIN_DIR : ABSPATH . 'admin';
                $admin_dir = rtrim($admin_dir, '/\\');
                $new_entry_file = $admin_dir . '/' . $db_admin_base . '.php';
                $default_file = $admin_dir . '/todo-login.php';
                if (file_exists($new_entry_file) && is_readable($new_entry_file) && file_exists($default_file)) {
                    @unlink($default_file);
                }
            }
        }

        // 已登录：获取真实后台入口文件名
        $admin_entry = function_exists('todo_get_admin_entry_filename')
            ? todo_get_admin_entry_filename()
            : get_admin_entry_filename();

        if (!$admin_entry) {
            return_404();
        }

        // 安全检查：如果解析结果指向本文件（index），立即 404
        // 防止数据库被错误写入 'index' 后的递归/自引用
        $self_basename = basename(__FILE__, '.php');
        if ($admin_entry === $self_basename) {
            return_404();
        }

        // 构建真实后台入口路径
        $real_admin_file = ADMIN_DIR . '/' . $admin_entry . '.php';
        if (!file_exists($real_admin_file)) {
            // 真实入口文件不存在，返回 404（不尝试任何二次回退）
            return_404();
        }

        // 内部转发到真实后台入口
        include $real_admin_file;
        exit;
    }
}

/**
 * 获取后台入口文件名（代理层备用实现）
 *
 * 安全原则：
 *  1. 优先从数据库读取，并验证对应文件存在。
 *  2. 如数据库值无效，使用文件系统扫描查找符合命名规则的重命名入口。
 *  3. 扫描时严格排除所有系统保留文件名，避免将 'index' 等误识别为真实入口。
 *  4. 任何情况下都不主动将系统保留名写回数据库。
 *  5. 找不到真实入口时返回 null，由调用方返回 404。
 */
if (!function_exists('get_admin_entry_filename')) {
    function get_admin_entry_filename()
    {
        // 系统保留文件名黑名单（禁止作为真实入口）
        $reserved = array('index', 'admin', 'login', 'main', 'admin-ajax', 'admin-footer', 'admin-header');
        $self_basename = basename(__FILE__, '.php'); // 'index'

        // 1. 优先从数据库获取配置
        try {
            $option_class = ABSPATH . 'core/classes/class-option.php';
            if (file_exists($option_class)) {
                require_once $option_class;
            }
            if (class_exists('CMS_Option')) {
                $db_value = CMS_Option::get('site_adminbasefile');
                if (!empty($db_value)
                    && !in_array($db_value, $reserved, true)
                    && $db_value !== $self_basename
                ) {
                    $file_path = ADMIN_DIR . '/' . $db_value . '.php';
                    if (file_exists($file_path)) {
                        return $db_value;
                    }
                }
            }
        } catch (Exception $e) {
            // 数据库不可用，继续尝试文件系统扫描
        }

        // 2. 文件系统扫描：查找符合重命名规则的入口文件
        // 约定：真实入口文件名由大写拼音前缀 + 下划线 + 十六进制随机串组成
        $candidates = glob(ADMIN_DIR . '/*.php');
        if ($candidates) {
            foreach ($candidates as $candidate_path) {
                $candidate = basename($candidate_path, '.php');
                // 排除所有保留文件名与本文件
                if ($candidate === $self_basename || in_array($candidate, $reserved, true)) {
                    continue;
                }
                // 必须符合重命名格式：前缀_十六进制串（至少 10 位）
                if ($candidate !== 'todo-login' && !preg_match('/^[A-Za-z]+_[0-9a-f]{10,}$/', $candidate)) {
                    continue;
                }
                // 找到候选文件，尝试同步到数据库（修复失效的 DB 记录）
                try {
                    if (class_exists('CMS_Option')) {
                        CMS_Option::update('site_adminbasefile', $candidate);
                    }
                } catch (Exception $e) {
                    // 忽略写入错误
                }
                return $candidate;
            }
        }

        // 3. 找不到任何真实入口，返回 null，由调用方返回 404
        return null;
    }
}

/**
 * 返回404页面
 */
if (!function_exists('return_404')) {
    function return_404()
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow', true);
        echo '404 Not Found';
        exit;
    }
}

// 执行智能重定向逻辑
perform_admin_redirect();

// 如果执行到这里，说明重定向函数没有正常工作，返回404
return_404();
