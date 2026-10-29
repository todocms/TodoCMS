<?php
/**
 * TodoCMS 前端用户登录处理器
 * 处理用户登录请求，支持用户名/邮箱+密码登录
 */

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 0); // 不直接显示错误，通过JSON返回

// 设置JSON响应头
header('Content-Type: application/json; charset=utf-8');

// 定义 ABSPATH 常量（如果尚未定义）
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}

try {
    // 加载核心文件
    require_once(ABSPATH . 'config.php');
    require_once(ABSPATH . 'core/bootstrap.php');
    require_once(ABSPATH . 'core/functions/function-loader.php');

    // 使用函数加载器加载核心函数
    Todo_Function_Loader::load_core_functions();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Core loading error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit;
}

// 检查是否为POST请求
if (todo_server_string('REQUEST_METHOD') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => __('只允许POST请求')
    ]);
    exit;
}

// 检查AJAX请求（放宽检查，允许通过查询参数或请求头）
$is_ajax = false;
if (todo_is_ajax_request()) {
    $is_ajax = true;
} elseif (todo_get_string('ajax') === '1') {
    // 允许通过查询参数标识AJAX请求（兼容性）
    $is_ajax = true;
}

if (!$is_ajax) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => '非法访问：需要AJAX请求',
        'debug' => [
            'x_requested_with' => todo_server_string('HTTP_X_REQUESTED_WITH', 'not_set'),
            'request_method' => todo_server_string('REQUEST_METHOD', 'not_set'),
            'user_agent' => todo_server_string('HTTP_USER_AGENT', 'not_set')
        ]
    ]);
    exit;
}

// PRG 防重复提交守卫（仅对携带 _submit_key 的 POST 生效）
Todo_PRG_Guard::guard_endpoint('user_login', 30);

try {
    // 验证CSRF令牌
    $csrf_valid = false;
    $nonce_field = '_todononce';
    $token = todo_post_string($nonce_field);

    if (function_exists('todo_verify_csrf_token')) {
        $csrf_valid = !empty($token) && todo_verify_csrf_token($token, 'login');
    } elseif (class_exists('Todo_Security_Manager')) {
        // 回退到统一的请求验证方法
        $csrf_valid = Todo_Security_Manager::verify_request_csrf('login', $nonce_field);
    }

    if (!$csrf_valid) {
        throw new Exception(__('安全验证失败，请刷新页面重试'));
    }

    // 获取登录数据
    $username = trim(todo_post_string('username'));
    $password = todo_post_string('password');
    $captcha = trim(todo_post_string('captcha'));
    $remember = todo_post_string('remember') === '1';

    // 基础验证
    if (empty($username)) {
        throw new Exception(__('请输入用户名或邮箱'));
    }

    if (empty($password)) {
        throw new Exception(__('请输入密码'));
    }

    if (strlen($password) < 6) {
        throw new Exception(__('密码长度不能少于6位'));
    }

    if (empty($captcha)) {
        throw new Exception(__('请输入验证码'));
    }

    // 验证图形验证码
    if (!function_exists('todo_verify_captcha')) {
        throw new Exception(__('验证码验证函数未加载'));
    }

    if (!todo_verify_captcha($captcha, 'users_login')) {
        throw new Exception(__('验证码错误'));
    }

    // 用户认证
    if (!function_exists('authenticate_user')) {
        throw new Exception(__('用户名或密码错误'));
    }

    $user_id = authenticate_user($username, $password);

    if (!$user_id) {
        if (function_exists('todo_log_action')) {
            todo_log_action('login_failed', '前台登录失败: ' . $username, 0);
        }
        throw new Exception(__('用户名或密码错误'));
    }

    // 获取用户信息
    if (!function_exists('get_user_by_id')) {
        throw new Exception(__('用户名或密码错误'));
    }

    $user = get_user_by_id($user_id);

    if (!$user) {
        throw new Exception(__('用户名或密码错误'));
    }

    if ($user->get_status() !== 'active') {
        
        if (function_exists('todo_log_action')) {
            todo_log_action('login_blocked', '用户状态异常，登录被阻止: ' . $username, $user_id);
        }
        
        throw new Exception(__('用户名或密码错误'));
    }

    // 初始化会话管理器（使用 Composer 自动加载）
    if (!class_exists('Todo_Session_Manager')) {
        // 如果类不存在，可能是常量未定义，尝试定义后再检查
        if (!defined('TODO_LOADED')) {
            define('TODO_LOADED', true);
        }
        // 再次检查类是否存在（Composer 会自动加载）
        if (!class_exists('Todo_Session_Manager')) {
            throw new Exception('Todo_Session_Manager 类无法加载，请检查 Composer 自动加载配置');
        }
    }
    Todo_Session_Manager::init();

    $user_role = 'subscriber';
    if (is_callable([$user, 'get_roles'])) {
        $roles = $user->get_roles();
        if (is_array($roles) && !empty($roles)) {
            $user_role = reset($roles);
        }
    } elseif (is_callable([$user, 'get_meta'])) {
        $roles_meta = $user->get_meta('roles', []);
        if (is_array($roles_meta) && !empty($roles_meta)) {
            $user_role = reset($roles_meta);
        }
    }

    Todo_Session_Manager::set_frontend_session($user_id, $user_role, $remember);

    if (class_exists('Todo_Cart_Manager') && method_exists('Todo_Cart_Manager', 'sync_after_login')) {
        Todo_Cart_Manager::sync_after_login($user_id);
    }

    // 更新最后登录时间
    if (is_callable([$user, 'update_last_login'])) {
        $user->update_last_login();
    } elseif (function_exists('update_user_meta')) {
        $now = date('Y-m-d H:i:s');
        update_user_meta($user_id, 'last_login', $now);
        update_user_meta($user_id, '_last_login', $now);
    }

    // 记录登录日志
    if (function_exists('todo_log_user_action')) {
        todo_log_user_action($user_id, 'login', [
            'ip' => todo_server_string('REMOTE_ADDR'),
            'user_agent' => todo_server_string('HTTP_USER_AGENT'),
            'login_type' => 'account'
        ]);
    }

    // 获取重定向URL
    $redirect_url = todo_get_user_center_url();
    if (todo_post_string('redirect') !== '') {
        $redirect_url = sanitize_url(todo_post_string('redirect'));
    }

    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => __('登录成功'),
        'data' => [
            'user_id' => $user_id,
            'username' => $user->get_username(),
            'display_name' => $user->get_display_name(),
            'redirect_url' => $redirect_url
        ]
    ]);

} catch (Exception $e) {
    // 记录错误日志
    if (function_exists('todo_log_error')) {
        todo_log_error('Login Error: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'username' => $username ?? '',
            'ip' => todo_server_string('REMOTE_ADDR')
        ]);
    }

    // 返回错误响应（统一使用200，方便前端按业务逻辑处理）
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * 验证密码
 * 支持password_verify和MD5两种方式
 */
function todo_verify_password($password, $hash)
{
    // 优先使用password_verify
    if (password_verify($password, $hash)) {
        return true;
    }

    // 兼容MD5（旧系统）
    if (md5($password) === $hash) {
        return true;
    }

    return false;
}

/**
 * 设置用户会话
 */
function todo_set_user_session($user, $remember = false)
{
    // 注意：此函数已废弃，会话管理现在由 Todo_Session_Manager 处理
    // 保留此函数仅用于兼容性，实际使用 Todo_Session_Manager::set_frontend_session()
    
    // 获取用户ID和角色
    $user_id = $user->get_id();
    $sess_role = 'subscriber';
    
    // 设置角色（兼容不同用户对象）
    if (is_callable([$user, 'get_data'])) {
        $user_data = $user->get_data();
        if (isset($user_data->role) && !empty($user_data->role)) {
            $sess_role = $user_data->role;
        }
    }
    if (empty($sess_role) && is_callable([$user, 'get_roles'])) {
        $roles = $user->get_roles();
        if (is_array($roles) && !empty($roles)) {
            $sess_role = reset($roles);
        }
    }
    if (empty($sess_role) && is_callable([$user, 'get_meta'])) {
        $roles_meta = $user->get_meta('roles', []);
        if (is_string($roles_meta) && strpos($roles_meta, 'a:') === 0) {
            $tmp = @unserialize($roles_meta);
            if ($tmp !== false) {
                $roles_meta = $tmp;
            }
        }
        if (is_array($roles_meta) && !empty($roles_meta)) {
            $sess_role = reset($roles_meta);
        }
    }
    $sess_role = strtolower($sess_role ?: 'subscriber');
    
    // 使用 Todo_Session_Manager 设置前台会话
    if (class_exists('Todo_Session_Manager')) {
        Todo_Session_Manager::init();
        Todo_Session_Manager::set_frontend_session($user_id, $sess_role, $remember);
    }

    // 注意：会话记录和记住登录状态现在由 Todo_Session_Manager::set_frontend_session() 自动处理
    // 此函数保留仅用于兼容性，实际功能已由 Todo_Session_Manager 处理
}

/**
 * 清理URL
 */
function sanitize_url($url)
{
    // 基础清理
    $url = trim($url);

    // 防止XSS
    $url = filter_var($url, FILTER_SANITIZE_URL);

    // 验证URL格式
    if (!filter_var($url, FILTER_VALIDATE_URL) && !preg_match('/^\//', $url)) {
        return todo_get_user_center_url();
    }

    return $url;
}
