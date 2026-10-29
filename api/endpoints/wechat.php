<?php
/**
 * 微信 API 端点
 *
 * 提供微信小程序登录相关的 API 端点
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

/**
 * 微信小程序登录
 */
function api_wechat_login($params) {
    global $tododb;

    if (!isset($params['code'])) {
        send_api_error(400, '缺少 code 参数');
    }

    $code = sanitize_text_field($params['code']);

    $nickname = '';
    if (isset($params['nickname'])) {
        $nickname = trim((string) $params['nickname']);
        if ($nickname !== '') {
            $nickname = sanitize_text_field($nickname);
        }
    }
    $avatar_url = '';
    if (isset($params['avatar'])) {
        $avatar_url = trim((string) $params['avatar']);
        if ($avatar_url !== '') {
            $avatar_url = esc_url_raw($avatar_url);
        }
    }
    
    // 获取小程序配置
    $appid = '';
    $secret = '';
    if (function_exists('get_option')) {
        $appid = (string) get_option('wechat_mini_appid', '');
        $secret = (string) get_option('wechat_mini_secret', '');
        if (($appid === '' || $secret === '') && function_exists('todo_get_option')) {
            $appid = $appid !== '' ? $appid : (string) todo_get_option('wechat_mini_appid', '');
            $secret = $secret !== '' ? $secret : (string) todo_get_option('wechat_mini_secret', '');
        }
        if ($appid === '' || $secret === '') {
            $ps = get_option('todo_payment_settings', []);
            if (is_array($ps) && isset($ps['wechat']) && is_array($ps['wechat'])) {
                if ($appid === '' && isset($ps['wechat']['mini_appid'])) {
                    $appid = (string) $ps['wechat']['mini_appid'];
                }
                if ($secret === '' && isset($ps['wechat']['mini_secret'])) {
                    $secret = (string) $ps['wechat']['mini_secret'];
                }
            }
        }
    } elseif (function_exists('todo_get_option')) {
        $appid = (string) todo_get_option('wechat_mini_appid', '');
        $secret = (string) todo_get_option('wechat_mini_secret', '');
    }
    $appid = trim($appid);
    $secret = trim($secret);

    if (!$appid || !$secret) {
        send_api_error(500, '小程序配置缺失');
    }

    // 调用微信接口获取 openid
    $url = "https://api.weixin.qq.com/sns/jscode2session?appid=$appid&secret=$secret&js_code=$code&grant_type=authorization_code";
    $response = @file_get_contents($url);
    if ($response === false) {
        send_api_error(500, '微信接口请求失败');
    }
    $data = json_decode($response, true);

    if (isset($data['errcode'])) {
        send_api_error(400, '微信登录失败: ' . $data['errmsg']);
    }

    $openid = $data['openid'];

    // 检查用户是否存在
    $user_table = TABLE_PREFIX . 'users';
    $user_meta_table = TABLE_PREFIX . 'user_meta';

    $user_id = $tododb->get_var("SELECT user_id FROM $user_meta_table WHERE meta_key = 'wechat_openid' AND meta_value = ?", [$openid]);

    if (!$user_id) {
        // 创建新用户
        $username = 'wx_' . substr($openid, -8);
        $display_name = $nickname !== '' ? $nickname : $username;
        $user_nicename = function_exists('sanitize_title') ? sanitize_title($display_name) : $display_name;
        if (!is_string($user_nicename) || trim($user_nicename) === '') {
            $user_nicename = $username;
        }
        $user_data = [
            'user_login' => $username,
            'user_nicename' => $user_nicename,
            'display_name' => $display_name,
            'user_registered' => current_time('mysql'),
            'user_status' => 0
        ];
        
        $tododb->insert($user_table, $user_data);
        $user_id = $tododb->insert_id();

        // 保存 openid
        update_user_meta($user_id, 'wechat_openid', $openid);
        // 默认设置为普通零售用户
        update_user_meta($user_id, 'user_level', 'retail');
    } else {
        $row = $tododb->query("SELECT user_login, display_name FROM {$user_table} WHERE ID = ? LIMIT 1", [$user_id]);
        $row = (is_array($row) && !empty($row)) ? $row[0] : null;
        $current_login = (is_array($row) && isset($row['user_login'])) ? (string) $row['user_login'] : '';
        $current_display_name = (is_array($row) && isset($row['display_name'])) ? (string) $row['display_name'] : '';
        $current_display_name = trim($current_display_name);

        if ($nickname !== '') {
            $should_update_display_name = ($current_display_name === '' || $current_display_name === $current_login || strpos($current_display_name, 'wx_') === 0);
            if ($should_update_display_name && $current_display_name !== $nickname) {
                $tododb->query("UPDATE {$user_table} SET display_name = ? WHERE ID = ?", [$nickname, $user_id]);
            }
        }
    }

    $user_level = get_user_meta($user_id, 'user_level', true);
    $user_level = $user_level ?: 'retail';

    if ($nickname !== '') {
        $existing_nickname = get_user_meta($user_id, 'nickname', true);
        if (empty($existing_nickname)) {
            update_user_meta($user_id, 'nickname', $nickname);
        }
        $existing_first_name = get_user_meta($user_id, 'first_name', true);
        if (empty($existing_first_name)) {
            update_user_meta($user_id, 'first_name', $nickname);
        }
    }
    if ($avatar_url !== '') {
        if (function_exists('todo_process_social_user_avatar')) {
            todo_process_social_user_avatar($user_id, $avatar_url, 'wechat');
        } else {
            update_user_meta($user_id, 'avatar', $avatar_url);
        }
    }

    $username = $tododb->get_var("SELECT user_login FROM {$user_table} WHERE ID = ? LIMIT 1", [$user_id]);
    $username = $username ?: ('wx_' . substr($openid, -8));

    $expiration = 7 * 24 * 60 * 60;
    $token = generate_api_jwt((int) $user_id, (string) $username, [
        'user_level' => (string) $user_level,
        'openid' => (string) $openid
    ], $expiration);

    return [
        'token' => $token,
        'token_type' => 'Bearer',
        'expires_in' => $expiration,
        'user_id' => $user_id,
        'user_level' => $user_level,
        'openid' => $openid
    ];
}

/**
 * 获取用户信息
 */
function api_get_user_info($params) {
    $user_id = get_current_user_id();
    if (!$user_id) {
        send_api_error(401, '未登录');
    }

    $user_level = get_user_meta($user_id, 'user_level', true);
    
    return [
        'user_id' => $user_id,
        'user_level' => $user_level,
        'nickname' => get_user_meta($user_id, 'nickname', true),
        'avatar' => get_user_meta($user_id, 'avatar', true)
    ];
}

// 注册路由
register_api_route('wechat/login', 'POST', 'api_wechat_login');
register_api_route('user/info', 'GET', 'api_get_user_info');
register_api_route('me', 'GET', 'api_get_user_info');
