<?php
/**
 * TodoCMS QQ登录回调处理接口
 * 获取 OpenID / UserInfo, 自动建账号入库并建立Session登录状态
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}

require_once(ABSPATH . 'config.php');
require_once(ABSPATH . 'core/bootstrap.php');
require_once(ABSPATH . 'core/functions/function-loader.php');

Todo_Function_Loader::load_core_functions();

if (class_exists('Todo_Session_Manager')) {
    Todo_Session_Manager::init();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$redirect_to = isset($_SESSION['qq_redirect_to']) && !empty($_SESSION['qq_redirect_to']) ? $_SESSION['qq_redirect_to'] : todo_get_user_center_url();
unset($_SESSION['qq_redirect_to']);

// 彻底解码 URL（防二次/多次 URL 编码导致 Location 跳转非法 URL）
while (is_string($redirect_to) && (strpos($redirect_to, '%3A') !== false || strpos($redirect_to, '%2F') !== false || strpos($redirect_to, '%3a') !== false || strpos($redirect_to, '%2f') !== false)) {
    $redirect_to = rawurldecode($redirect_to);
}

// 安全二次检查：如果记录的目标地址依然是登录页面，强制改为会员中心
if (strpos($redirect_to, 'user=login') !== false || strpos($redirect_to, '/login') !== false || strpos($redirect_to, 'user=register') !== false || strpos($redirect_to, '/register') !== false) {
    $redirect_to = todo_get_user_center_url();
}

// 校验 state 防止 CSRF
$state = $_GET['state'] ?? '';
$session_state = $_SESSION['qq_oauth_state'] ?? '';
unset($_SESSION['qq_oauth_state']);

// 记录回调进入日志
if (class_exists('Todo_Error_Handler')) {
    Todo_Error_Handler::log(
        '[QQ Login Debug] Received Callback Request',
        Todo_Error_Handler::LEVEL_INFO,
        [
            'get_state' => $state,
            'session_state' => $session_state,
            'redirect_to' => $redirect_to,
            'session_name' => session_name(),
            'session_id' => session_id(),
            'code_present' => !empty($_GET['code'])
        ]
    );
}

// 校验 state 防止 CSRF（若 Session 丢失 state，允许容错继续执行 OAuth 登录）
if (!empty($state) && !empty($session_state) && !hash_equals((string)$session_state, (string)$state)) {
    if (class_exists('Todo_Error_Handler')) {
        Todo_Error_Handler::log(
            '[QQ Login Debug] CSRF State Verification Failed',
            Todo_Error_Handler::LEVEL_WARNING,
            [
                'get_state' => $state,
                'session_state' => $session_state
            ]
        );
    }
    header('Content-Type: text/html; charset=utf-8');
    $login_url = esc_url(todo_get_login_url());
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>登录中转</title></head><body>';
    echo '<script>';
    echo 'alert("QQ登录安全校验失败，请重新尝试");';
    echo 'window.location.href = "' . $login_url . '";';
    echo '</script>';
    echo '<p>正在跳转，请稍候... 如未跳转，<a href="' . $login_url . '">点击此处</a></p>';
    echo '</body></html>';
    exit;
}

$code = $_GET['code'] ?? '';
if (empty($code)) {
    header('Content-Type: text/html; charset=utf-8');
    $login_url = esc_url(todo_get_login_url());
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>登录中转</title></head><body>';
    echo '<script>';
    echo 'alert("QQ登录授权失败或取消了授权");';
    echo 'window.location.href = "' . $login_url . '";';
    echo '</script>';
    echo '<p>正在跳转，请稍候... 如未跳转，<a href="' . $login_url . '">点击此处</a></p>';
    echo '</body></html>';
    exit;
}

$qq_login_enabled = get_option('qq_login_enabled', '0');
$qq_app_id = trim((string) get_option('qq_app_id', ''));
$qq_app_key = trim((string) get_option('qq_app_key', ''));
$qq_redirect_uri = trim((string) get_option('qq_redirect_uri', ''));

if ($qq_redirect_uri === '') {
    $qq_redirect_uri = todo_get_site_url('/api/user/qq-callback.php');
}

if (!$qq_login_enabled || empty($qq_app_id) || empty($qq_app_key)) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<script>alert("系统未开启或未完成QQ登录配置"); window.location.href="' . esc_url(todo_get_login_url()) . '";</script>';
    exit;
}

/**
 * 辅助 HTTP GET 请求函数
 */
function todo_qq_http_get($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
    return @file_get_contents($url);
}

// 第一步：用 code 换取 access_token
$token_url = 'https://graph.qq.com/oauth2.0/token?' . http_build_query([
    'grant_type'    => 'authorization_code',
    'client_id'     => $qq_app_id,
    'client_secret' => $qq_app_key,
    'code'          => $code,
    'redirect_uri'  => $qq_redirect_uri,
    'fmt'           => 'json'
]);

$token_res = todo_qq_http_get($token_url);
$token_data = json_decode($token_res, true);

// 兼容旧版 URL-encoded 返回格式
if (empty($token_data) || !isset($token_data['access_token'])) {
    parse_str((string)$token_res, $token_params);
    if (!empty($token_params['access_token'])) {
        $token_data = $token_params;
    }
}

if (empty($token_data['access_token'])) {
    header('Content-Type: text/html; charset=utf-8');
    $msg = isset($token_data['error_description']) ? $token_data['error_description'] : '获取Access Token失败';
    echo '<script>alert("QQ登录失败: ' . addslashes($msg) . '"); window.location.href="' . esc_url(todo_get_login_url()) . '";</script>';
    exit;
}

$access_token = $token_data['access_token'];

// 第二步：获取用户的 openid
$graph_url = 'https://graph.qq.com/oauth2.0/me?' . http_build_query([
    'access_token' => $access_token,
    'fmt'          => 'json'
]);

$graph_res = todo_qq_http_get($graph_url);
$graph_data = json_decode($graph_res, true);

// 兼容 callback( {"client_id":"...","openid":"..."} );
if (empty($graph_data['openid']) && is_string($graph_res)) {
    if (preg_match('/callback\s*\(\s*(\{.*?\})\s*\)/i', $graph_res, $matches)) {
        $graph_data = json_decode($matches[1], true);
    }
}

if (empty($graph_data['openid'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<script>alert("获取QQ OpenID失败"); window.location.href="' . esc_url(todo_get_login_url()) . '";</script>';
    exit;
}

$openid = $graph_data['openid'];

// 第三步：获取 QQ 用户详细信息（昵称、头像）
$user_info_url = 'https://graph.qq.com/user/get_user_info?' . http_build_query([
    'access_token'       => $access_token,
    'oauth_consumer_key' => $qq_app_id,
    'openid'             => $openid
]);

$user_info_res = todo_qq_http_get($user_info_url);
$user_info = json_decode($user_info_res, true);

// 严格模式提取并解析数据：
// 1. 头像：提取 QQ 头像地址 (优先使用高清 100x100 头像)
$remote_avatar_url = !empty($user_info['figureurl_qq_2']) ? trim($user_info['figureurl_qq_2']) :
                    (!empty($user_info['figureurl_2']) ? trim($user_info['figureurl_2']) :
                    (!empty($user_info['figureurl_1']) ? trim($user_info['figureurl_1']) : ''));

// 2. 生日：year 包装成 YYYY-01-01 格式
$user_birthday = '';
if (!empty($user_info['year'])) {
    $yr = intval($user_info['year']);
    if ($yr > 1900 && $yr < 2100) {
        $user_birthday = sprintf('%04d-01-01', $yr);
    }
}

// 3. 性别：gender 转换成 male / female
$user_gender = '';
if (isset($user_info['gender'])) {
    $raw_gender = (string)$user_info['gender'];
    if ($raw_gender === '男' || $raw_gender === '1' || ($user_info['gender_type'] ?? null) == 2) {
        $user_gender = 'male';
    } elseif ($raw_gender === '女' || $raw_gender === '2' || ($user_info['gender_type'] ?? null) == 1) {
        $user_gender = 'female';
    }
}

// 4. 昵称
$qq_nickname = !empty($user_info['nickname']) ? trim($user_info['nickname']) : '';

// 第四步：检查数据库中是否已存在该 QQ openid 绑定的用户
global $tododb;
$user_id = 0;

$meta_row = $tododb->get_row(
    "SELECT user_id FROM " . $tododb->prefix . "user_meta WHERE meta_key = 'qq_openid' AND meta_value = ? LIMIT 1",
    [$openid]
);

if ($meta_row && !empty($meta_row->user_id)) {
    $user_id = intval($meta_row->user_id);
}

// 如果尚未存在绑定的用户，则新建用户并自动入库
if (!$user_id) {
    if (function_exists('get_option') && !get_option('users_can_register', false)) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<script>alert("网站目前已暂停新用户注册"); window.location.href="' . esc_url(todo_get_login_url()) . '";</script>';
        exit;
    }

    $default_role = get_option('default_role', 'subscriber');
    $rand_suffix = substr(md5($openid), 0, 8);
    $username = 'qq_' . $rand_suffix;

    // 确保用户名不重复
    $count = 1;
    while (username_exists($username)) {
        $username = 'qq_' . $rand_suffix . '_' . $count;
        $count++;
    }

    $email = $username . '@qq.social';
    $display_name = !empty($qq_nickname) ? $qq_nickname : 'QQUser_' . substr($rand_suffix, 0, 4);

    // 随机强密码
    $password = function_exists('todo_generate_password') ? todo_generate_password(16, true) : substr(md5(uniqid(mt_rand(), true)), 0, 16);

    // 提前处理三方登录头像，确保入库时即绑定正确头像，防止触发默认 SVG 及 100.jpg 路径重名覆盖
    $initial_avatar = '';
    if (!empty($remote_avatar_url) && function_exists('todo_download_remote_image')) {
        $avatar_filename = 'avatar_qq_' . $rand_suffix . '_' . substr(md5($remote_avatar_url), 0, 6) . '.jpg';
        $download_res = todo_download_remote_image($remote_avatar_url, 0, $avatar_filename);
        if (!is_todo_error($download_res) && !empty($download_res)) {
            $initial_avatar = $download_res;
        }
    }

    $user_data = [
        'user_login'      => $username,
        'user_email'      => $email,
        'user_pass'       => $password,
        'user_nicename'   => $username,
        'display_name'    => $display_name,
        'user_registered' => function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s'),
        'user_status'     => 0,
        'avatar'          => $initial_avatar
    ];

    $new_id = todo_insert_user($user_data);

    if (is_todo_error($new_id) || !$new_id) {
        header('Content-Type: text/html; charset=utf-8');
        $error_msg = is_todo_error($new_id) ? $new_id->get_error_message() : '数据库写入失败';
        echo '<script>alert("自动创建用户失败：' . addslashes($error_msg) . '"); window.location.href="' . esc_url(todo_get_login_url()) . '";</script>';
        exit;
    }

    $user_id = (int)$new_id;

    // 设置角色
    if (class_exists('CMS_User')) {
        $user_obj = new CMS_User($user_id);
        $user_obj->set_role($default_role);
    } elseif (class_exists('Todo_User')) {
        $user_obj = new Todo_User($user_id);
        $user_obj->set_role($default_role);
    }

    // 保存 openid & 社交元数据
    update_user_meta($user_id, 'qq_openid', $openid);
    if (!empty($remote_avatar_url)) {
        update_user_meta($user_id, '_qq_remote_avatar', $remote_avatar_url);
    }
}

// 仅对未设置该属性的用户补充社交资料，绝不覆盖用户已在个人中心修改过的数据
if (!empty($qq_nickname)) {
    $existing_first_name = get_user_meta($user_id, 'first_name', true);
    if (empty($existing_first_name)) {
        update_user_meta($user_id, 'first_name', $qq_nickname);
    }
}
if (!empty($user_birthday)) {
    $existing_birthday = get_user_meta($user_id, 'birthday', true);
    if (empty($existing_birthday)) {
        update_user_meta($user_id, 'birthday', $user_birthday);
    }
}
if (!empty($user_gender)) {
    $existing_gender = get_user_meta($user_id, 'gender', true);
    if (empty($existing_gender)) {
        update_user_meta($user_id, 'gender', $user_gender);
    }
}

// 归一化处理三方用户头像绑定与按需下载（避免重复生成默认SVG及重复下载图片）
if (function_exists('todo_process_social_user_avatar')) {
    todo_process_social_user_avatar($user_id, $remote_avatar_url, 'qq');
}

// 获取用户对象
$user = get_user_by_id($user_id);
if (!$user) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<script>alert("读取用户信息失败"); window.location.href="' . esc_url(todo_get_login_url()) . '";</script>';
    exit;
}

// 第五步：登录会话建立
if (!class_exists('Todo_Session_Manager')) {
    if (!defined('TODO_LOADED')) {
        define('TODO_LOADED', true);
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

// 显式清理后端模拟登录标记，确保第三方登录拥有绝对最高优先级覆盖
foreach (['remember_token', 'todo_remember_token', 'todo_user_id', 'todo_impersonate'] as $cname) {
    setcookie($cname, '', time() - 3600, '/');
    unset($_COOKIE[$cname]);
}

// 建立前台 Session
Todo_Session_Manager::set_frontend_session($user_id, $user_role, true);

if (class_exists('Todo_Error_Handler')) {
    Todo_Error_Handler::log(
        '[QQ Login Debug] Frontend Session Created Successfully',
        Todo_Error_Handler::LEVEL_INFO,
        [
            'user_id' => $user_id,
            'user_role' => $user_role,
            'session_name' => session_name(),
            'session_id' => session_id(),
            'current_frontend_user_id' => Todo_Session_Manager::get_frontend_user_id(),
            'cookie_token' => $_COOKIE[Todo_Session_Manager::COOKIE_FRONTEND_TOKEN] ?? 'none'
        ]
    );
}

if (class_exists('Todo_Cart_Manager') && method_exists('Todo_Cart_Manager', 'sync_after_login')) {
    Todo_Cart_Manager::sync_after_login($user_id);
}

// 更新最后登录时间与日志
if (function_exists('todo_update_user_last_login')) {
    todo_update_user_last_login($user_id);
} elseif (class_exists('Todo_User_Manager') && method_exists('Todo_User_Manager', 'update_login_info')) {
    Todo_User_Manager::update_login_info($user_id);
} elseif (is_callable([$user, 'update_last_login'])) {
    $user->update_last_login();
} else {
    $now = date('Y-m-d H:i:s');
    update_user_meta($user_id, 'last_login', $now);
    update_user_meta($user_id, '_last_login', $now);
}

if (function_exists('todo_log_user_action')) {
    todo_log_user_action($user_id, 'login', [
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'login_type' => 'qq'
    ]);
}

// 跳转到登录前页面或用户中心
$safe_redirect_to = esc_url($redirect_to);

if (class_exists('Todo_Error_Handler')) {
    Todo_Error_Handler::log(
        '[QQ Login Debug] Redirecting User After OAuth Login',
        Todo_Error_Handler::LEVEL_INFO,
        [
            'user_id' => $user_id,
            'redirect_to' => $safe_redirect_to
        ]
    );
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Location: ' . $safe_redirect_to);
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>登录成功，正在跳转</title></head><body>';
echo '<script>window.location.href = "' . addslashes($safe_redirect_to) . '";</script>';
echo '<p>登录成功，正在跳转... 如未自动跳转，<a href="' . $safe_redirect_to . '">点击此处</a></p>';
echo '</body></html>';
exit;
