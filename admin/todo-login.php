<?php
// 开启输出缓冲 8.21
ob_start();
$__todo_admin_t0 = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
$__todo_admin_marks = [
    't0' => $__todo_admin_t0,
];

/**
 * 管理后台入口文件
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 加载 Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';
$__todo_admin_marks['autoload'] = microtime(true);

// 加载配置文件
require_once ABSPATH . 'config.php';
$__todo_admin_marks['config'] = microtime(true);

// 定义管理后台常量
if (!defined('IS_ADMIN')) {
    define('IS_ADMIN', true);
}
if (!defined('ADMIN_ACCESS')) {
    define('ADMIN_ACCESS', true);
}
if (!defined('TODO_ADMIN')) {
    define('TODO_ADMIN', true);
}
if (!defined('ADMIN_INCLUDED')) {
    define('ADMIN_INCLUDED', true);
}

// 定义数据库前缀常量（兼容性）
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', TABLE_PREFIX);
}

// 确保会话已启动
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$__todo_admin_marks['session'] = microtime(true);

// 定义其他常量
if (!defined('TODO_ADMIN_PATH')) {
    define('TODO_ADMIN_PATH', ADMIN_DIR);
}

// 加载核心引导文件（如果尚未加载）
if (!defined('TODO_BOOTSTRAP_LOADED')) {
    require_once(CORE_DIR . '/bootstrap.php');
}
$__todo_admin_marks['bootstrap'] = microtime(true);
if (class_exists('Todo_Performance_Monitor')) {
    $t0 = $__todo_admin_marks['t0'];
    $t_autoload = $__todo_admin_marks['autoload'] ?? $t0;
    $t_config = $__todo_admin_marks['config'] ?? $t_autoload;
    $t_session = $__todo_admin_marks['session'] ?? $t_config;
    $t_boot = $__todo_admin_marks['bootstrap'] ?? $t_session;
    Todo_Performance_Monitor::add_metric('admin_autoload', 'admin vendor/autoload.php', ($t_autoload - $t0) * 1000);
    Todo_Performance_Monitor::add_metric('admin_config', 'admin config.php', ($t_config - $t_autoload) * 1000);
    Todo_Performance_Monitor::add_metric('admin_session', 'admin session_start', ($t_session - $t_config) * 1000);
    Todo_Performance_Monitor::add_metric('admin_bootstrap', 'admin require core/bootstrap.php', ($t_boot - $t_session) * 1000);
}

// 加载函数加载器
require_once(CORE_DIR . '/functions/function-loader.php');

// 加载管理后台专用函数
require_once(ADMIN_DIR . '/includes/todo-functions.php');

// 使用函数加载器加载核心函数
$__todo_perf_started = false;
if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::start('admin_core_functions');
    $__todo_perf_started = true;
}
try {
    Todo_Function_Loader::load_core_functions();
} finally {
    if ($__todo_perf_started && class_exists('Todo_Performance_Monitor')) {
        Todo_Performance_Monitor::end('admin_core_functions', false, 'Todo_Function_Loader::load_core_functions');
    }
}

// 加载系统扩展函数（包含 sanitize_email 等函数）
if (file_exists(CORE_DIR . '/functions/system-functions-extended.php')) {
    require_once(CORE_DIR . '/functions/system-functions-extended.php');
}

// 加载邮件扩展函数（用于验证码功能）
if (file_exists(CORE_DIR . '/functions/mail-extend.php')) {
    require_once(CORE_DIR . '/functions/mail-extend.php');
}
// Ensure screen API is available when admin-header.php is included directly from pages
if (!class_exists('TODO_Screen')) {
    require_once ADMIN_DIR . '/includes/class-todo-screen.php';
}

// 安全增强：检查并自动轮换后台入口文件（每周一次，增加爆破难度）
if (function_exists('todo_maybe_auto_rotate_admin_entry')) {
    todo_maybe_auto_rotate_admin_entry();
}

// 加载数据库架构
$__todo_schema_started = false;
if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::start('admin_schema');
    $__todo_schema_started = true;
}
try {
    require_once(CORE_DIR . '/db/schema.php');
} finally {
    if ($__todo_schema_started && class_exists('Todo_Performance_Monitor')) {
        Todo_Performance_Monitor::end('admin_schema', false, 'db/schema.php');
    }
}

// 检查CMS是否已安装的函数
if (!function_exists('is_cms_installed')) {
    function is_cms_installed()
    {
        // 优先使用统一的安装状态检测逻辑
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

// 加载管理后台所需的函数组
Todo_Function_Loader::load_function_group('admin');
Todo_Function_Loader::load_function_group('security');
Todo_Function_Loader::load_function_group('content');

// 加载分类选择器组件
require_once ADMIN_DIR . '/components/hierarchical-categories-selector.php';

// 防止重复执行
if (defined('ADMIN_LOADED')) {
    return;
}
define('ADMIN_LOADED', true);

// 检查是否已安装
$__todo_installed_perf_started = false;
if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::start('admin_install_check');
    $__todo_installed_perf_started = true;
}
if (!is_cms_installed()) {
    if ($__todo_installed_perf_started && class_exists('Todo_Performance_Monitor')) {
        Todo_Performance_Monitor::end('admin_install_check', false, 'is_cms_installed');
    }
    header('Location: ../install.php');
    exit;
}
if ($__todo_installed_perf_started && class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::end('admin_install_check', false, 'is_cms_installed');
}

// 处理退出登录请求
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    todo_logout();
    header('Location: ' . todo_get_site_url());
    exit;
}

// 检查用户是否已登录
$__todo_login_state_perf_started = false;
if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::start('admin_login_state');
    $__todo_login_state_perf_started = true;
}
if (!is_user_logged_in()) {
    if ($__todo_login_state_perf_started && class_exists('Todo_Performance_Monitor')) {
        Todo_Performance_Monitor::end('admin_login_state', false, 'is_user_logged_in false');
    }
    // 确保会话已启动
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    // 初始化变量
    $username = '';
    $login_error = '';
    $login_message = '';
    $redirect_to = 'index.php';

    // 检查是否有登录消息
    if (isset($_GET['login_message'])) {
        $login_message = $_GET['login_message'];
    }

    // 处理AJAX请求：获取验证码
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_captcha') {
        header('Content-Type: application/json');
        $client_ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
        
        // 1. 检查是否因验证码尝试失败被锁定
        if (function_exists('todo_check_captcha_lockout')) {
            $lockout = todo_check_captcha_lockout($client_ip);
            if ($lockout) {
                echo json_encode(['success' => false, 'message' => "操作过于频繁，请在 {$lockout['wait_minutes']} 分钟后再试"]);
                exit;
            }
        }

        // 2. 检查是否刷新过于频繁（独立限制，不计入验证失败次数）
        if (function_exists('todo_check_captcha_refresh_lockout')) {
            $refresh_lockout = todo_check_captcha_refresh_lockout($client_ip);
            if ($refresh_lockout) {
                echo json_encode(['success' => false, 'message' => $refresh_lockout['message']]);
                exit;
            }
        }

        // 注册刷新记录
        if (function_exists('todo_register_captcha_attempt')) {
            todo_register_captcha_attempt($client_ip, true, 'refresh');
        }

        require_once CORE_DIR . '/classes/class-sliding-captcha.php';
        $captcha = new Todo_Sliding_Captcha();
        echo json_encode($captcha->create());
        exit;
    }

    // 处理AJAX请求：验证验证码
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_captcha') {
        header('Content-Type: application/json');
        $client_ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');

        // 检查是否被锁定
        if (function_exists('todo_check_captcha_lockout')) {
            $lockout = todo_check_captcha_lockout($client_ip);
            if ($lockout) {
                echo json_encode(['success' => false, 'message' => "操作过于频繁，请在 {$lockout['wait_minutes']} 分钟后再试"]);
                exit;
            }
        }

        require_once CORE_DIR . '/classes/class-sliding-captcha.php';
        $captcha = new Todo_Sliding_Captcha();
        $x = isset($_POST['x']) ? floatval($_POST['x']) : 0;

        if ($captcha->verify($x)) {
            $_SESSION['todo_captcha_verified'] = true;
            // 验证成功，重置尝试次数
            if (function_exists('todo_register_captcha_attempt')) {
                todo_register_captcha_attempt($client_ip, true, 'verify');
            }
            echo json_encode(['success' => true]);
        } else {
            $_SESSION['todo_captcha_verified'] = false;
            // 验证失败，记录尝试
            if (function_exists('todo_register_captcha_attempt')) {
                todo_register_captcha_attempt($client_ip, false, 'verify');
            }

        }
        exit;
    }

    // 处理AJAX请求：检查凭证
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'check_credentials') {
        header('Content-Type: application/json');

        // 检查是否启用邮箱验证
        $email_verify_enabled = defined('TODO_ADMIN_LOGIN_EMAIL_VERIFY') ? TODO_ADMIN_LOGIN_EMAIL_VERIFY : true;
        $todo_demo_user = defined('TODO_DEMO_ADMIN_USERNAME') ? (string) constant('TODO_DEMO_ADMIN_USERNAME') : '';
        $post_username = isset($_POST['username']) ? trim($_POST['username']) : '';
        if (defined('TODO_DEMO_ADMIN_ENABLED') && constant('TODO_DEMO_ADMIN_ENABLED') && $todo_demo_user && $post_username === $todo_demo_user) {
            $email_verify_enabled = false;
        }

        // 检查人机验证
        if ($email_verify_enabled && (!isset($_SESSION['todo_captcha_verified']) || !$_SESSION['todo_captcha_verified'])) {
            exit;
        }

        if (!Todo_Security_Manager::verify_request_csrf('login_action', '_todononce')) {
            exit;
        }

        $username = isset($_POST['username']) ? trim($_POST['username']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';

        // 暴力破解防护
        $client_ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
        $lockout = function_exists('todo_check_login_lockout') ? todo_check_login_lockout($username, $client_ip) : false;
        if ($lockout) {
            echo json_encode(['success' => false, 'message' => "尝试次数过多，请在 {$lockout['wait_minutes']} 分钟后再试"]);
            exit;
        }

        $user_id = authenticate_user($username, $password);
        
        // 记录登录尝试
        if (function_exists('todo_register_login_attempt')) {
            todo_register_login_attempt($username, $client_ip, (bool)$user_id);
        }

        if ($user_id) {
            $user = get_user_by_id($user_id);
            if ($user && is_callable([$user, 'get_data'])) {
                $user_data = $user->get_data();
                $email = isset($user_data->user_email) ? $user_data->user_email : '';
                echo json_encode(['success' => true, 'email' => $email]);
            } else {
                echo json_encode(['success' => false, 'message' => '无法获取用户信息']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => '用户名或密码不正确']);
        }
        exit;
    }

    // 处理AJAX请求：发送登录验证码
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_login_code') {
        header('Content-Type: application/json');

        // 检查是否启用邮箱验证
        $email_verify_enabled = defined('TODO_ADMIN_LOGIN_EMAIL_VERIFY') ? TODO_ADMIN_LOGIN_EMAIL_VERIFY : true;
        $todo_demo_user = defined('TODO_DEMO_ADMIN_USERNAME') ? (string) constant('TODO_DEMO_ADMIN_USERNAME') : '';
        $post_email = isset($_POST['email']) ? trim($_POST['email']) : '';
        if (defined('TODO_DEMO_ADMIN_ENABLED') && constant('TODO_DEMO_ADMIN_ENABLED') && $todo_demo_user) {
            $demo_user_obj = get_user_by('login', $todo_demo_user);
            if ($demo_user_obj && isset($demo_user_obj->user_email) && $post_email === $demo_user_obj->user_email) {
                $email_verify_enabled = false;
            }
        }

        // 检查人机验证
        if ($email_verify_enabled && (!isset($_SESSION['todo_captcha_verified']) || !$_SESSION['todo_captcha_verified'])) {
            exit;
        }

        if (!Todo_Security_Manager::verify_request_csrf('login_action', '_todononce')) {
            echo json_encode(['success' => false, 'message' => '安全验证失败']);
            exit;
        }

        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => '邮箱地址无效']);
            exit;
        }

        // 检查邮箱是否属于已注册用户
        $user = get_user_by('email', $email);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => '该邮箱未注册']);
            exit;
        }

        // 生成并发送验证码
        if (function_exists('todo_generate_and_send_login_code')) {
            $result = todo_generate_and_send_login_code($email);
            if ($result !== false) {
                $_SESSION['login_verification_sent'] = time();
                $_SESSION['login_verification_email'] = $email;
                echo json_encode(['success' => true, 'message' => '验证码已发送到您的邮箱']);
            } else {
                echo json_encode(['success' => false, 'message' => '发送验证码失败，请稍后重试']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => '验证码发送功能未启用']);
        }
        exit;
    }

    // 如果是登录请求
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
        // 检查是否启用邮箱验证
        $email_verify_enabled = defined('TODO_ADMIN_LOGIN_EMAIL_VERIFY') ? TODO_ADMIN_LOGIN_EMAIL_VERIFY : true;
        $todo_demo_user = defined('TODO_DEMO_ADMIN_USERNAME') ? (string) constant('TODO_DEMO_ADMIN_USERNAME') : '';
        $post_username = isset($_POST['username']) ? trim($_POST['username']) : '';
        if (defined('TODO_DEMO_ADMIN_ENABLED') && constant('TODO_DEMO_ADMIN_ENABLED') && $todo_demo_user && $post_username === $todo_demo_user) {
            $email_verify_enabled = false;
        }

        // 验证nonce
        if (!Todo_Security_Manager::verify_request_csrf('login_action', '_todononce')) {
            $login_error = '安全验证失败，请刷新页面后重试。';
            $user_id = false;
        } elseif ($email_verify_enabled && (!isset($_SESSION['todo_captcha_verified']) || !$_SESSION['todo_captcha_verified'])) {
            $login_error = '请先完成滑动验证码验证。';
            $user_id = false;
        } else {
            $username = isset($_POST['username']) ? trim($_POST['username']) : '';
            $password = isset($_POST['password']) ? $_POST['password'] : '';

            // 暴力破解防护
            $client_ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
            $lockout = function_exists('todo_check_login_lockout') ? todo_check_login_lockout($username, $client_ip) : false;
            
            if ($lockout) {
                $login_error = "尝试次数过多，请在 {$lockout['wait_minutes']} 分钟后再试";
                $user_id = false;
            } else {
                // 先验证用户名和密码
                $user_id = authenticate_user($username, $password);
                
                // 记录登录尝试
                if (function_exists('todo_register_login_attempt')) {
                    todo_register_login_attempt($username, $client_ip, (bool)$user_id);
                }
                
                if (!$user_id) {
                    $login_error = '用户名或密码不正确';
                }
            }

            if ($user_id) {
                // 获取用户信息
                $user = get_user_by_id($user_id);

                // 如果启用邮箱验证，需要验证验证码
                if ($email_verify_enabled) {
                    $verification_code = isset($_POST['verification_code']) ? trim($_POST['verification_code']) : '';

                    if (empty($verification_code)) {
                        // 检查是否已经发送过验证码
                        if (isset($_SESSION['login_verification_sent']) && $_SESSION['login_verification_sent']) {
                            $login_error = '请输入邮箱验证码。';
                        } else {
                            $login_error = '请先获取邮箱验证码。';
                        }
                    } else {
                        // 获取用户邮箱
                        $user_email = '';
                        if ($user && is_callable([$user, 'get_data'])) {
                            $user_data = $user->get_data();
                            $user_email = isset($user_data->user_email) ? $user_data->user_email : '';
                        }

                        if (empty($user_email)) {
                            $login_error = '无法获取用户邮箱信息。';
                        } else {
                            // 验证验证码
                            if (function_exists('todo_verify_email_code')) {
                                if (todo_verify_email_code($user_email, $verification_code, 'admin_login_verify')) {
                                    // 验证码正确，清除会话中的验证信息
                                    unset($_SESSION['login_verification_sent']);
                                    unset($_SESSION['login_verification_email']);

                                    // 登录成功，设置会话（后台登录不使用记住我功能）
                                    if (class_exists('Todo_Session_Manager')) {
                                        Todo_Session_Manager::init();
                                        $role = '';
                                        // 尝试获取用户角色
                                        if (isset($user) && is_object($user)) {
                                            if (isset($user->role)) {
                                                $role = $user->role;
                                            } elseif (is_callable([$user, 'get_roles'])) {
                                                $roles = $user->get_roles();
                                                $role = !empty($roles) ? (is_array($roles) ? reset($roles) : $roles) : '';
                                            }
                                        }
                                        Todo_Session_Manager::set_admin_session($user_id, $role, false);
                                    } else {
                                        set_auth_cookie($user_id, false);
                                    }

                                    // 发送登录提醒
                                    if (function_exists('todo_notify_admin_login')) {
                                        todo_notify_admin_login($user_id);
                                    }

                                    // 重定向到管理后台
                                    $redirect_to = isset($_POST['redirect_to']) ? (string) $_POST['redirect_to'] : 'index.php?page=dashboard';
                                    $redirect_to = str_replace(["\r", "\n"], '', trim($redirect_to));
                                    if ($redirect_to === '' || strpos($redirect_to, '//') === 0) {
                                        $redirect_to = 'index.php?page=dashboard';
                                    }
                                    if (preg_match('/^https?:\/\//i', $redirect_to)) {
                                        $target = (function_exists('todo_validate_redirect') && todo_validate_redirect($redirect_to))
                                            ? $redirect_to
                                            : todo_admin_url('index.php?page=dashboard');
                                    } else {
                                        $target = todo_admin_url($redirect_to);
                                    }
                                    header('Location: ' . $target);
                                    exit;
                                } else {
                                    $login_error = '验证码错误或已过期，请重新获取。';
                                    // 保持验证码输入框显示，但清除已发送状态，允许重新发送
                                    unset($_SESSION['login_verification_sent']);
                                    unset($_SESSION['login_verification_email']);
                                }
                            } else {
                                $login_error = '验证码验证功能未启用。';
                            }
                        }
                    }
                } else {
                    // 未启用邮箱验证，直接登录（后台登录不使用记住我功能）
                    if (class_exists('Todo_Session_Manager')) {
                        Todo_Session_Manager::init();
                        $role = '';
                        // 尝试获取用户角色
                        if (isset($user) && is_object($user)) {
                            if (isset($user->role)) {
                                $role = $user->role;
                            } elseif (is_callable([$user, 'get_roles'])) {
                                $roles = $user->get_roles();
                                $role = !empty($roles) ? (is_array($roles) ? reset($roles) : $roles) : '';
                            }
                        }
                        Todo_Session_Manager::set_admin_session($user_id, $role, false);
                    } else {
                        set_auth_cookie($user_id, false);
                    }

                    // 发送登录提醒
                    if (function_exists('todo_notify_admin_login')) {
                        todo_notify_admin_login($user_id);
                    }

                    // 确保会话写入
                    session_write_close();

                    // 重定向到管理后台
                    $redirect_to = isset($_POST['redirect_to']) ? (string) $_POST['redirect_to'] : 'index.php?page=dashboard';
                    $redirect_to = str_replace(["\r", "\n"], '', trim($redirect_to));
                    if ($redirect_to === '' || strpos($redirect_to, '//') === 0) {
                        $redirect_to = 'index.php?page=dashboard';
                    }
                    if (preg_match('/^https?:\/\//i', $redirect_to)) {
                        $target = (function_exists('todo_validate_redirect') && todo_validate_redirect($redirect_to))
                            ? $redirect_to
                            : todo_admin_url('index.php?page=dashboard');
                    } else {
                        $target = todo_admin_url($redirect_to);
                    }
                    header('Location: ' . $target);
                    exit;
                }
            }
        }
    }

    // 显示登录表单
    include_once ADMIN_DIR . '/templates/login.php';
    ob_end_flush(); // Flush output buffer before exit
    exit;
}
if ($__todo_login_state_perf_started && class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::end('admin_login_state', false, 'is_user_logged_in true');
}

if (function_exists('todo_cleanup_default_admin_login_file')) {
    todo_cleanup_default_admin_login_file();
}

if (class_exists('Todo_Core_Kernel') && is_callable(['Todo_Core_Kernel', 'check_runtime_license']) && !call_user_func(['Todo_Core_Kernel', 'check_runtime_license'])) {
    $page = (string) ($_GET['page'] ?? '');
    if ($page !== 'updates' && $page !== 'setting/updates') {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow', true);
        echo '403 Forbidden';
        exit;
    }
}

// 检查用户权限
$__todo_cap_perf_started = false;
if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::start('admin_capability');
    $__todo_cap_perf_started = true;
}
if (!current_user_can('access_admin')) {
    if ($__todo_cap_perf_started && class_exists('Todo_Performance_Monitor')) {
        Todo_Performance_Monitor::end('admin_capability', false, 'current_user_can(access_admin) false');
    }
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow', true);
    echo '403 Forbidden';
    exit;
}
if ($__todo_cap_perf_started && class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::end('admin_capability', false, 'current_user_can(access_admin) true');
}

// 加载管理后台函数文件
$__todo_admin_functions_perf_started = false;
if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::start('admin_functions_php');
    $__todo_admin_functions_perf_started = true;
}
require_once ADMIN_DIR . '/includes/functions.php';
if ($__todo_admin_functions_perf_started && class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::end('admin_functions_php', false, 'admin/includes/functions.php');
}

$is_iframe_mode = isset($_GET['format']) && $_GET['format'] === 'iframe';
// 加载管理后台菜单（包括商城菜单和用户菜单）
// 注意：iframe 模式也必须注册菜单/页面钩子，否则像 todo-orders 这类动态页面无法匹配到回调，会回退到 dashboard。
$__todo_menu_require_perf_started = false;
if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::start('admin_menu_requires');
    $__todo_menu_require_perf_started = true;
}
require_once ADMIN_DIR . '/includes/shop-menu.php';
require_once ADMIN_DIR . '/includes/user-menu.php';
if ($__todo_menu_require_perf_started && class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::end('admin_menu_requires', false, 'admin menu require_once');
}

// 触发admin_menu钩子以注册所有菜单
$__todo_admin_menu_hook_perf_started = false;
if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::start('admin_menu_hook');
    $__todo_admin_menu_hook_perf_started = true;
}
do_action('admin_menu');
if ($__todo_admin_menu_hook_perf_started && class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::end('admin_menu_hook', false, 'do_action(admin_menu)');
}

// 处理管理后台操作
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

$__todo_request_method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$__todo_is_safe_redirect_method = in_array($__todo_request_method, ['GET', 'HEAD'], true);
$__todo_is_ajax = function_exists('todo_is_ajax_request') ? (bool) todo_is_ajax_request() : false;

if ($__todo_is_safe_redirect_method && !$__todo_is_ajax && !headers_sent() && function_exists('todo_current_site_id')) {
    $uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    $is_super = function_exists('todo_is_super_admin') ? (bool) todo_is_super_admin($uid ?: null) : false;
    $is_global = $is_super || (function_exists('todo_is_demo_admin_session') && todo_is_demo_admin_session());

    if (!$is_global && $uid > 0 && isset($GLOBALS['tododb']) && is_object($GLOBALS['tododb'])) {
        $db = $GLOBALS['tododb'];
        $prefix = property_exists($db, 'prefix') ? $db->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : '');
        try {
            $role_meta = $db->get_var("SELECT meta_value FROM {$prefix}user_meta WHERE user_id = ? AND meta_key = 'roles' LIMIT 1", [$uid]);
            if ($role_meta) {
                $roles = function_exists('maybe_unserialize') ? maybe_unserialize($role_meta) : $role_meta;
                if (is_array($roles) && (in_array('administrator', $roles, true) || in_array('admin', $roles, true))) {
                    $is_global = true;
                } elseif (is_string($roles)) {
                    $r = strtolower(trim($roles));
                    if ($r === 'administrator' || $r === 'admin') {
                        $is_global = true;
                    }
                }
            }
        } catch (Throwable $e) {
        }
    }

    if (!$is_global) {
        $resolved_site_id = (int) todo_current_site_id();
        if ($resolved_site_id > 0 && function_exists('todo_set_current_site_id')) {
            todo_set_current_site_id($resolved_site_id, true);
        }

        $requested_site_id = (isset($_GET['site']) && is_numeric($_GET['site'])) ? (int) $_GET['site'] : null;
        if ($requested_site_id === null || $requested_site_id !== $resolved_site_id) {
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
            $parts = parse_url($uri);
            $path = isset($parts['path']) ? $parts['path'] : '';
            $query = isset($parts['query']) ? $parts['query'] : '';
            $params = [];
            if ($query !== '') {
                parse_str($query, $params);
            }
            $params['site'] = $resolved_site_id;
            $new_query = http_build_query($params);
            $new_uri = $path . ($new_query !== '' ? ('?' . $new_query) : '');
            if ($new_uri !== '' && $new_uri !== $uri) {
                header('Location: ' . $new_uri, true, 302);
                exit;
            }
        }
    }
}

if (function_exists('todo_is_demo_admin_session') && todo_is_demo_admin_session() && function_exists('todo_is_demo_write_request') && todo_is_demo_write_request()) {
    todo_guard_demo_mode_write();
}

// 针对无路由名页面，给页面预设标题（必须在header.php之前设置）
switch (true) {
    case $page === 'dashboard':
        $GLOBALS['admin_title'] = '欢迎页';
        break;
    case $page === 'theme-customize' && !isset($_GET['file']):
        $GLOBALS['admin_title'] = '主题自定义';
        break;
    case $page === 'theme-customize' && isset($_GET['file']):
        $GLOBALS['admin_title'] = '<small>安全会话将在 30 分钟后过期!</small>';
        break;
    case $page === 'user-edit':
        $GLOBALS['admin_title'] = '个人资料';
        break;
    case $page === 'user-batch':
        $GLOBALS['admin_title'] = '批量添加';
        break;
    case $page === 'post-edit':
        $GLOBALS['admin_title'] = '编辑文章';
        break;
    case $page === 'cache-management':
        $GLOBALS['admin_title'] = '缓存管理';
        break;
    case $page === 'plugin-settings':
        $GLOBALS['admin_title'] = '插件设置';
        break;
    case $page === 'plugin-manage':
        $GLOBALS['admin_title'] = '插件管理';
        break;
    case $page === 'todo-service-settings':
        $GLOBALS['admin_title'] = '服务设置';
        break;
    case $page === 'themes':
        $GLOBALS['admin_title'] = '主题管理';
        break;
    case $page === 'theme-studio':
        $GLOBALS['admin_title'] = '主题工作室';
        break;
    case $page === 'template-tags':
        $GLOBALS['admin_title'] = '模板标签手册';
        break;
    case $page === 'models-edit':
        $GLOBALS['admin_title'] = '模型编辑';
        break;
    case $page === 'models-fields':
        $GLOBALS['admin_title'] = '模型字段';
        break;
    case $page === 'models-edit-field':
        $GLOBALS['admin_title'] = '字段编辑';
        break;
    case $page === 'language-terms':
        $GLOBALS['admin_title'] = '词汇';
        break;
    case $page === 'language-translations':
        $GLOBALS['admin_title'] = '翻译';
        break;
    case $page === 'access-settings':
        $GLOBALS['admin_title'] = '访问设置';
        break;
    
    default:
        $GLOBALS['admin_title'] = '···';
        break;
}

if (function_exists('todo_is_ajax_request') && todo_is_ajax_request()) {
    if (!function_exists('todo_admin_ajax_bridge_shutdown')) {
        function todo_admin_ajax_bridge_shutdown() {
            if (headers_sent()) {
                return;
            }

            if (ob_get_level() <= 0) {
                return;
            }

            $content = '';
            while (ob_get_level() > 0) {
                $content = ob_get_clean() . $content;
            }

            $headers = headers_list();
            $location = null;
            foreach ($headers as $header_line) {
                if (stripos($header_line, 'Location:') === 0) {
                    $location = trim(substr($header_line, strlen('Location:')));
                    break;
                }
            }

            $trim = ltrim($content);
            if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
                json_decode($trim, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    header('Content-Type: application/json; charset=UTF-8');
                    echo $trim;
                    return;
                }
            }

            if ($location !== null) {
                header_remove('Location');
            }

            $status_code = http_response_code();
            $success = ($status_code < 400);

            $payload = array(
                'success' => $success,
                'message' => $success ? '' : '请求失败',
                'data' => null,
                'redirect' => $location,
                'errors' => array(),
            );

            if ($location === null && $content !== '') {
                $payload['data'] = array('html' => $content);
            }

            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
    }

    register_shutdown_function('todo_admin_ajax_bridge_shutdown');
}

// 在输出头部前拦截页面内AJAX（如广告批量操作），保证返回纯JSON而非HTML
if ($page === 'ads' && isset($_GET['action']) && $_GET['action'] === 'bulk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    include_once ADMIN_DIR . '/pages/ads/ads.php';
    exit;
}

// 拦截主题编辑的人机验证AJAX请求
if ($page === 'theme-customize' && isset($_POST['action']) && in_array($_POST['action'], ['get_captcha', 'verify_captcha'])) {
    include_once ADMIN_DIR . '/pages/theme/theme-customize.php';
    exit;
}

// 加载管理后台头部
$__todo_header_perf_started = false;
if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::start('admin_header');
    $__todo_header_perf_started = true;
}
require_once ADMIN_DIR . '/templates/header.php';
if ($__todo_header_perf_started && class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::end('admin_header', false, 'admin/templates/header.php');
}

// 加载页面内容
$__todo_page_perf_label = 'admin_page_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $page);
$__todo_page_perf_started = false;
if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::start($__todo_page_perf_label);
    $__todo_page_perf_started = true;
}
switch ($page) {
    case 'dashboard':
        include(ADMIN_DIR . '/pages/dashboard/dashboard.php');
        break;
    case 'posts':
        include_once ADMIN_DIR . '/pages/content/posts.php';
        break;
    case 'post-new':
        include_once ADMIN_DIR . '/pages/content/post-new.php';
        break;
    case 'post-edit':
        include_once ADMIN_DIR . '/pages/content/post-edit.php';
        break;
    case 'categories':
        include_once ADMIN_DIR . '/pages/content/categories.php';
        break;
    case 'tags':
        include_once ADMIN_DIR . '/pages/content/tags.php';
        break;
    case 'search-logs':
        include_once ADMIN_DIR . '/pages/content/search-logs.php';
        break;
    case 'comments':
        include_once ADMIN_DIR . '/pages/comment/comments.php';
        break;
    case 'comment-edit':
        include_once ADMIN_DIR . '/pages/comment/comment-edit.php';
        break;
    case 'comment-pending':
        include_once ADMIN_DIR . '/pages/comment/pending.php';
        break;
    case 'comment-dashboard':
        include_once ADMIN_DIR . '/pages/comment/index.php';
        break;
    case 'product-inquiries':
        include_once ADMIN_DIR . '/pages/shop/product-inquiries.php';
        break;
    case 'website-messages':
        include_once ADMIN_DIR . '/pages/comment/website-messages.php';
        break;
    case 'email-subscriptions':
        include_once ADMIN_DIR . '/pages/comment/email-subscriptions.php';
        break;
    case 'users':
        include(ADMIN_DIR . '/pages/user/users.php');
        break;
    case 'access-settings':
        include_once ADMIN_DIR . '/pages/user/access-settings.php';
        break;
    case 'user-new':
        include_once ADMIN_DIR . '/pages/user/user-new.php';
        break;
    case 'user-edit':
        include_once ADMIN_DIR . '/pages/user/user-edit.php';
        break;
    case 'user-batch':
        include_once ADMIN_DIR . '/pages/user/user-batch.php';
        break;
    case 'user-levels':
        include_once ADMIN_DIR . '/pages/user/user-levels.php';
        break;
    case 'upgrade-plans':
        // 兼容旧链接，重定向到新路由
        $qs = $_GET;
        $qs['page'] = 'upgrade-packages';
        $target = 'index.php?' . http_build_query($qs);
        header('Location: ' . $target, true, 302);
        exit;
    case 'upgrade-packages':
        include_once ADMIN_DIR . '/pages/user/upgrade-packages.php';
        break;
    case 'themes':
        include_once ADMIN_DIR . '/pages/theme/themes.php';
        break;
    case 'theme-studio':
        include_once ADMIN_DIR . '/pages/theme/theme-studio.php';
        break;
    case 'template-tags':
        include_once ADMIN_DIR . '/pages/theme/template-tags.php';
        break;
    case 'theme-customize':
        include_once ADMIN_DIR . '/pages/theme/theme-customize.php';
        break;
    case 'plugins':
        include_once ADMIN_DIR . '/pages/plugin/plugins.php';
        break;
    case 'plugin-install':
        include_once ADMIN_DIR . '/pages/plugin/plugin-install.php';
        break;
    case 'plugin-settings':
        include_once ADMIN_DIR . '/pages/plugin/plugin-settings.php';
        break;
    case 'plugin-manage':
        include_once ADMIN_DIR . '/pages/plugin/plugin-manage.php';
        break;
    case 'settings':
        include(ADMIN_DIR . '/pages/setting/settings.php');
        break;
    case 'security-demo':
        include_once ADMIN_DIR . '/pages/security/security-demo.php';
        break;
    case 'security-check':
        include(ADMIN_DIR . '/pages/security/security-check.php');
        break;
    case 'password-security':
        include_once ADMIN_DIR . '/pages/security/password-security.php';
        break;
    case 'trash':
        include_once ADMIN_DIR . '/pages/tool/trash.php';
        break;
    case 'menus':
        include_once ADMIN_DIR . '/pages/tool/menus.php';
        break;
    case 'language':
        include_once ADMIN_DIR . '/pages/language/index.php';
        break;
    case 'language-terms':
        include_once ADMIN_DIR . '/pages/language/terms.php';
        break;
    case 'language-translations':
        include_once ADMIN_DIR . '/pages/language/translations.php';
        break;
    case 'language-template-detail':
        include_once ADMIN_DIR . '/pages/language/template-detail.php';
        break;
    case 'cache-management':
        include_once ADMIN_DIR . '/pages/tool/cache-management.php';
        break;
    case 'security-logs':
        include(ADMIN_DIR . '/pages/security/security-logs.php');
        break;
    case 'password-policy':
        include(ADMIN_DIR . '/pages/security/password-policy.php');
        break;
    case 'two-factor-auth':
        include(ADMIN_DIR . '/pages/security/two-factor-auth.php');
        break;
    case 'api-security':
        include(ADMIN_DIR . '/pages/security/api-security.php');
        break;
    case 'database-security':
        include(ADMIN_DIR . '/pages/security/database-security.php');
        break;
    case 'backup-security':
        include(ADMIN_DIR . '/pages/security/backup-security.php');
        break;
    case 'security-audit':
        include_once(ADMIN_DIR . '/pages/security/security-audit.php');
        break;
    case 'security-compliance':
        include_once(ADMIN_DIR . '/pages/security/security-compliance.php');
        break;
    case 'product-categories':
        include_once(ADMIN_DIR . '/pages/content/product-categories.php');
        break;
    case 'models':
        include_once(ADMIN_DIR . '/pages/model/models.php');
        break;
    case 'models-edit':
        include_once(ADMIN_DIR . '/pages/model/models-edit.php');
        break;
    case 'model-fields':
        include_once(ADMIN_DIR . '/pages/model/model-fields.php');
        break;
    case 'models-add-field':
        include_once(ADMIN_DIR . '/pages/model/models-add-field.php');
        break;
    case 'models-edit-field':
        include_once(ADMIN_DIR . '/pages/model/models-edit-field.php');
        break;
    case 'global-vars':
        include_once(ADMIN_DIR . '/pages/setting/global-vars.php');
        break;
    case 'site-info':
        header('Location: index.php?page=settings&tab=site-info');
        exit;
    case 'seo-settings':
        include_once(ADMIN_DIR . '/pages/setting/seo-settings.php');
        break;
    case 'media':
        include_once(ADMIN_DIR . '/pages/content/media.php');
        break;
    case 'doc-properties':
        include_once(ADMIN_DIR . '/pages/content/doc-properties.php');
        break;
    case 'ads':
        include_once(ADMIN_DIR . '/pages/ads/ads.php');
        break;
    case 'ad-new':
        include_once(ADMIN_DIR . '/pages/ads/ad-new.php');
        break;
    case 'ad-edit':
        include_once(ADMIN_DIR . '/pages/ads/ad-edit.php');
        break;
    case 'ad-preview':
        include_once(ADMIN_DIR . '/pages/ads/ad-preview.php');
        break;
    case 'ad-groups':
        include_once(ADMIN_DIR . '/pages/ads/ad-groups.php');
        break;
    case 'ad-group-edit':
        include_once(ADMIN_DIR . '/pages/ads/ad-group-edit.php');
        break;
    case 'links':
        include_once(ADMIN_DIR . '/pages/links/links.php');
        break;
    case 'link-new':
        include_once(ADMIN_DIR . '/pages/links/link-new.php');
        break;
    case 'link-edit':
        include_once(ADMIN_DIR . '/pages/links/link-edit.php');
        break;
    case 'todo-service-settings':
        include_once(ADMIN_DIR . '/pages/shop/service-settings.php');
        break;
    case 'updates':
        include_once(ADMIN_DIR . '/pages/setting/updates.php');
        break;
    case 'api-manager':
        include_once(ADMIN_DIR . '/pages/setting/api-manager.php');
        break;
    case 'mail':
        include_once(ADMIN_DIR . '/pages/mail/settings.php');
        break;
    case 'sites':
        include_once(ADMIN_DIR . '/pages/setting/sites.php');
        break;
    case 'user-license':
        include_once(ADMIN_DIR . '/pages/setting/user-license.php');
        break;
    default:
        // 检查是否为动态注册的页面（如商城页面）
        global $admin_page_hooks, $submenu;

        $page_hook = null;
        $callback_found = false;

        // 查找页面钩子
        if (isset($admin_page_hooks)) {
            foreach ($admin_page_hooks as $slug => $hook) {
                if ($page === $slug) {
                    $page_hook = $hook;
                    break;
                }
            }
        }

        // 查找子菜单页面
        if (!$page_hook && isset($submenu)) {
            foreach ($submenu as $parent_slug => $items) {
                foreach ($items as $item) {
                    if (isset($item[2]) && $item[2] === $page) {
                        // 找到匹配的子菜单项，生成页面钩子
                        if (isset($admin_page_hooks[$parent_slug])) {
                            $parent_hook = $admin_page_hooks[$parent_slug];
                        } else {
                            $parent_hook = $parent_slug;
                        }
                        $plugin_name = preg_replace('|\.php$|', '', $page);
                        $page_hook = $parent_hook . '_page_' . $plugin_name;
                        break 2;
                    }
                }
            }
        }

        // 执行页面钩子
        if ($page_hook && has_action($page_hook)) {
            do_action($page_hook);
            $callback_found = true;
        }

        // 如果没有找到动态页面，显示默认仪表板
        if (!$callback_found) {
            include(ADMIN_DIR . '/pages/dashboard/dashboard.php');
        }
        break;
}
if ($__todo_page_perf_started && class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::end($__todo_page_perf_label, false, 'admin page include');
}

// 加载管理后台底部
$__todo_footer_perf_started = false;
if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::start('admin_footer');
    $__todo_footer_perf_started = true;
}
require_once ADMIN_DIR . '/templates/footer.php';
if ($__todo_footer_perf_started && class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::end('admin_footer', false, 'admin/templates/footer.php');
}
