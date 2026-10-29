<?php
if (!defined('TODO_LOADED')) {
    define('TODO_LOADED', true);
}
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}
// 在管理员AJAX接口中显式标识为后台，以避免admin/includes中的直接访问保护触发
if (!defined('IS_ADMIN')) {
    define('IS_ADMIN', true);
}
if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}
require_once dirname(__DIR__, 2) . '/core/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? '';
$user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
$csrf_token = $_POST['_csrf_token'] ?? '';

// 权限校验准备：当前操作者
$current_user = todo_get_current_user();
$current_user_id = todo_get_current_user_id();
if (!$current_user_id) {
    echo json_encode(['error' => '无权限操作']);
    exit;
}

// CSRF校验（针对不同动作使用不同令牌名）
$csrf_action = ($action === 'logout_other_sessions') ? 'logout_other_sessions' : (($action === 'impersonate_user') ? 'impersonate_user' : '');
if (!$csrf_action || !Todo_Security_Manager::verify_request_csrf($csrf_action, '_csrf_token')) {
    echo json_encode(['error' => 'CSRF令牌校验失败']);
    exit;
}

// 注销其他会话：管理员或本人可操作
if ($action === 'logout_other_sessions' && $user_id > 0) {
    if (!todo_user_can('manage_users', $current_user_id) && $current_user_id != $user_id) {
        echo json_encode(['error' => '无权限操作']);
        exit;
    }
    // 隐身保护：普通管理员无法注销开发者的会话
    if (get_user_meta($user_id, 'is_developer', true) === '1' && !todo_is_super_admin($current_user_id)) {
        echo json_encode(['error' => '无权限操作该账号会话']);
        exit;
    }
    global $tododb;
    $current_session_id = session_id();

    $sessions_table = DB_PREFIX . 'user_sessions';
    if (!function_exists('table_exists') || table_exists($sessions_table)) {
        $sql = "DELETE FROM {$sessions_table} WHERE user_id = ? AND session_id <> ?";
        $tododb->query($sql, [$user_id, $current_session_id]);
    }

    $tokens_table = DB_PREFIX . 'remember_tokens';
    if (function_exists('table_exists') && table_exists($tokens_table)) {
        $sql2 = "DELETE FROM {$tokens_table} WHERE user_id = ?";
        $tododb->query($sql2, [$user_id]);
    }

    echo json_encode(['success' => true]);
    exit;
}

// 以目标用户身份登录并跳转到用户中心：仅管理员可操作（仅影响前台，不修改后台会话）
if ($action === 'impersonate_user' && $user_id > 0) {
    if (!todo_user_can('manage_users', $current_user_id)) {
        echo json_encode(['error' => '无权限操作']);
        exit;
    }
    // 隐身保护：普通管理员无法模拟开发者账号
    if (get_user_meta($user_id, 'is_developer', true) === '1' && !todo_is_super_admin($current_user_id)) {
        echo json_encode(['error' => '无权限模拟此用户']);
        exit;
    }

    // 注意：不修改当前服务器端后台会话，避免后台刷新后变更为该用户
    // 建立临时前台会话，做到不留痕迹（使用会话级Cookie，不修改用户最后登录时间/IP，不持久留存）
    try {
        global $tododb;
        $tokens_table = DB_PREFIX . 'remember_tokens';
        // 模拟登录仅保留1小时临时中转，过期自动失效，不使用30天持久Token
        $token_expire_ts = time() + 3600;
        $token = function_exists('todo_generate_password') ? todo_generate_password(32, false) : md5(uniqid(mt_rand(), true));
        $expires_at = date('Y-m-d H:i:s', $token_expire_ts);
        if (!function_exists('table_exists') || table_exists($tokens_table)) {
            // 新增用途标记 purpose = impersonate，若旧库无purpose列则回退为不含purpose插入
            try {
                tododb_insert($tokens_table, [
                    'user_id'    => $user_id,
                    'token'      => $token,
                    'purpose'    => 'impersonate',
                    'expires_at' => $expires_at,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            } catch (Exception $e) {
                try {
                    tododb_insert($tokens_table, [
                        'user_id'    => $user_id,
                        'token'      => $token,
                        'expires_at' => $expires_at,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                } catch (Exception $e2) {
                    // 不中断流程
                }
            }
        }
        // 设置会话级Cookie（expire = 0，浏览器关闭即失效，不在客户端持久保存模拟状态）
        $site_url = todo_get_site_url();
        $cookie_host = parse_url($site_url, PHP_URL_HOST);
        $cookie_scheme = parse_url($site_url, PHP_URL_SCHEME);
        $secure = ($cookie_scheme === 'https');
        $domain = $cookie_host;
        if ($domain === 'localhost' || (function_exists('filter_var') && filter_var($domain, FILTER_VALIDATE_IP))) {
            $domain = '';
        }
        setcookie('remember_token', $token, 0, '/', $domain, $secure, true);
        setcookie('todo_remember_token', $token, 0, '/', $domain, $secure, true);
        setcookie('todo_user_id', (string) $user_id, 0, '/', $domain, $secure, true);
        setcookie('todo_impersonate', '1', 0, '/', $domain, $secure, false);

        // 独立建立前台会话
        if (class_exists('Todo_Session_Manager')) {
            try {
                $role = function_exists('get_user_role') ? get_user_role($user_id) : (function_exists('todo_get_user_role') ? todo_get_user_role($user_id) : '');
                if (method_exists('Todo_Session_Manager', 'set_frontend_session')) {
                    // 保存当前后台 session 状态
                    $orig_name = session_name();
                    $orig_status = session_status();
                    if ($orig_status === PHP_SESSION_ACTIVE) {
                        session_write_close();
                    }
                    session_name(Todo_Session_Manager::FRONTEND_SESSION_NAME);
                    if (isset($_COOKIE[Todo_Session_Manager::FRONTEND_SESSION_NAME])) {
                        session_id($_COOKIE[Todo_Session_Manager::FRONTEND_SESSION_NAME]);
                    } else {
                        session_id(session_create_id());
                    }
                    @session_start();
                    $_SESSION[Todo_Session_Manager::KEY_FRONTEND_USER_ID] = (int) $user_id;
                    $_SESSION[Todo_Session_Manager::KEY_FRONTEND_ROLE] = $role;
                    $_SESSION['is_impersonating'] = true;
                    $_SESSION['impersonator_admin_id'] = $current_user_id;

                    // 保持用户真实的最后登录时间，不写入当前时间以避免在前台留下模拟登录痕迹
                    $orig_last_login = function_exists('get_user_meta') ? (get_user_meta($user_id, 'last_login', true) ?: get_user_meta($user_id, '_last_login', true)) : '';
                    if (empty($orig_last_login) && class_exists('Todo_User_Manager')) {
                        $u_obj = Todo_User_Manager::get_user($user_id);
                        if (!empty($u_obj['last_login_at'])) {
                            $orig_last_login = $u_obj['last_login_at'];
                        }
                    }
                    if ($orig_last_login) {
                        $_SESSION[Todo_Session_Manager::KEY_FRONTEND_LOGIN_TIME] = is_numeric($orig_last_login) ? (int)$orig_last_login : strtotime($orig_last_login);
                    } else {
                        unset($_SESSION[Todo_Session_Manager::KEY_FRONTEND_LOGIN_TIME]);
                    }
                    
                    $sess_token = function_exists('todo_generate_password') ? todo_generate_password(32, false) : bin2hex(random_bytes(16));
                    $_SESSION[Todo_Session_Manager::KEY_FRONTEND_TOKEN] = $sess_token;
                    
                    // 设置会话级前台 session cookie (expire = 0)
                    setcookie(Todo_Session_Manager::FRONTEND_SESSION_NAME, session_id(), 0, '/', $domain, $secure, true);
                    setcookie(Todo_Session_Manager::COOKIE_FRONTEND_TOKEN, $sess_token, 0, '/', $domain, $secure, true);
                    
                    session_write_close();
                    
                    // 恢复原本的后台 session
                    if ($orig_status === PHP_SESSION_ACTIVE && $orig_name) {
                        session_name($orig_name);
                        if (isset($_COOKIE[$orig_name])) {
                            session_id($_COOKIE[$orig_name]);
                        }
                        @session_start();
                    }
                }
            } catch (Exception $e) {
                // 忽略会话写入失败
            }
        }
    } catch (Exception $e) {
        // 不中断流程
    }

    $redirect_url = todo_get_user_center_url();
    echo json_encode(['success' => true, 'redirect_url' => $redirect_url]);
    exit;
}

echo json_encode(['error' => '参数错误']);
exit;
