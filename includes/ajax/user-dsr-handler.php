<?php
/**
 * GDPR 用户数据主体权利 (DSR) 处理接口
 * Article 15 (Right to Access / Data Portability) & Article 17 (Right to Erasure / Anonymization)
 */
if (!defined('ABSPATH')) {
    define('ABSPATH', rtrim(dirname(dirname(dirname(__FILE__))), '/') . '/');
}

require_once ABSPATH . 'vendor/autoload.php';
require_once ABSPATH . 'config.php';

if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

require_once ABSPATH . 'core/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    if (class_exists('Todo_Session_Manager')) {
        Todo_Session_Manager::configure_php_session('frontend');
    }
    @session_start();
}

$user_id = 0;
if (function_exists('get_current_user_id')) {
    $user_id = intval(get_current_user_id());
}

if ($user_id <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Unauthorized user']);
    exit;
}

$cookie_consent_enabled = function_exists('get_option') ? get_option('cookie_consent_enabled', '0') : '0';
if ($cookie_consent_enabled !== '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'GDPR feature is disabled']);
    exit;
}

global $tododb;
$action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : '';

// 1. 导出个人所有数据 (GDPR Data Portability - Article 20)
if ($action === 'export_data') {
    $user_data = [];

    // 基本资料
    $user = $tododb->get_row($tododb->prepare("SELECT ID, user_login, display_name, user_email, balance, user_registered FROM " . TABLE_PREFIX . "users WHERE ID = %d", $user_id), ARRAY_A);
    if ($user) {
        unset($user['user_pass']);
        // 补充用户元数据（如手机号、性别、生日、昵称等）
        $user_metas = $tododb->get_results($tododb->prepare("SELECT meta_key, meta_value FROM " . TABLE_PREFIX . "user_meta WHERE user_id = %d", $user_id), ARRAY_A);
        if ($user_metas) {
            foreach ($user_metas as $m) {
                $key = $m['meta_key'];
                if (in_array($key, ['capabilities', 'session_tokens'], true)) {
                    continue;
                }
                $user[$key] = function_exists('maybe_unserialize') ? maybe_unserialize($m['meta_value']) : $m['meta_value'];
            }
        }
        $user_data['profile'] = $user;
    }

    // 收货地址簿
    $addr_table = $tododb->table_exists('member_addresses') ? TABLE_PREFIX . 'member_addresses' : ($tododb->table_exists('user_addresses') ? TABLE_PREFIX . 'user_addresses' : '');
    $addresses = [];
    if ($addr_table) {
        $addresses = $tododb->get_results($tododb->prepare("SELECT * FROM {$addr_table} WHERE user_id = %d", $user_id), ARRAY_A);
    }
    $user_data['addresses'] = $addresses ?: [];

    // 历史订单列表
    $orders = [];
    if ($tododb->table_exists('orders')) {
        $orders = $tododb->get_results($tododb->prepare("SELECT order_no, total_amount, payment_amount, status, payment_status, shipping_status, created_at FROM " . TABLE_PREFIX . "orders WHERE user_id = %d ORDER BY id DESC", $user_id), ARRAY_A);
    }
    $user_data['orders'] = $orders ?: [];

    // 评价与收藏
    $reviews = [];
    if ($tododb->table_exists('product_reviews')) {
        $reviews = $tododb->get_results($tododb->prepare("SELECT product_id, rating, content, created_at FROM " . TABLE_PREFIX . "product_reviews WHERE user_id = %d", $user_id), ARRAY_A);
    }
    $user_data['reviews'] = $reviews ?: [];

    $filename = 'gdpr-data-export-user-' . $user_id . '-' . date('Ymd') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $site_name = '';
    if (function_exists('todo_get_option')) {
        $site_name = (string) todo_get_option('site_title', '');
    } elseif (function_exists('get_option')) {
        $site_name = (string) get_option('site_title', '');
    }
    if ($site_name === '') {
        $site_name = (string) get_option('site_name', '');
    }
    if ($site_name === '') {
        $site_name = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    }

    echo json_encode([
        'gdpr_export_notice' => 'This export contains all personal data associated with your account' . ($site_name !== '' ? (' on ' . $site_name) : '') . '.',
        'export_date' => date('c'),
        'user_data' => $user_data
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. 申请注销账号与敏感 PII 脱敏 (GDPR Right to Erasure - Article 17)
if ($action === 'delete_account') {
    header('Content-Type: application/json; charset=utf-8');

    // 检查是否有未完成的订单（未发货/进行中/退款中）
    if ($tododb->table_exists('orders')) {
        $active_orders = $tododb->get_var($tododb->prepare(
            "SELECT COUNT(*) FROM " . TABLE_PREFIX . "orders WHERE user_id = %d AND status IN ('pending', 'processing', 'paid', 'shipped')",
            $user_id
        ));

        if (intval($active_orders) > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete account while you have active or pending orders. Please wait for orders to complete.']);
            exit;
        }

        // 对历史订单中的个人敏感 PII 数据做脱敏处理（以平衡财税 VAT 审计保留要求与 GDPR 被遗忘权）
        $anonymized_email = 'anonymized-' . substr(md5($user_id . time()), 0, 8) . '@gdpr-deleted.local';
        $tododb->query($tododb->prepare(
            "UPDATE " . TABLE_PREFIX . "orders SET shipping_address = 'GDPR Anonymized Address', shipping_info = '{\"name\":\"GDPR Anonymized User\",\"phone\":\"***\",\"email\":\"***\"}' WHERE user_id = %d",
            $user_id
        ));
    } else {
        $anonymized_email = 'anonymized-' . substr(md5($user_id . time()), 0, 8) . '@gdpr-deleted.local';
    }

    // 清理收货地址
    $addr_table = $tododb->table_exists('member_addresses') ? TABLE_PREFIX . 'member_addresses' : ($tododb->table_exists('user_addresses') ? TABLE_PREFIX . 'user_addresses' : '');
    if ($addr_table) {
        $tododb->query($tododb->prepare("DELETE FROM {$addr_table} WHERE user_id = %d", $user_id));
    }

    // 清理用户元数据中的个人敏感 PII
    $tododb->query($tododb->prepare(
        "DELETE FROM " . TABLE_PREFIX . "user_meta WHERE user_id = %d AND meta_key IN ('phone', 'mobile', 'nickname', 'first_name', 'last_name', 'gender', 'birthday', 'city', 'company', 'bio', 'avatar', 'last_login_ip')",
        $user_id
    ));

    // 脱敏并挂起用户账号（同时脱敏 user_login，防止其中包含手机号导致手机号泄漏或占用手机号再次注册）
    $anonymized_login = 'deleted_' . $user_id . '_' . substr(md5(uniqid('', true)), 0, 6);
    $tododb->update(
        TABLE_PREFIX . 'users',
        [
            'user_login' => $anonymized_login,
            'display_name' => 'Deleted User (GDPR)',
            'user_nicename' => 'deleted_' . $user_id,
            'user_email' => $anonymized_email,
            'user_pass' => '*',
            'user_status' => -1
        ],
        ['ID' => $user_id]
    );

    // 清理 Session 退出登录
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    // 计算注销后的首页重定向地址（兼顾子目录部署与当前多语言前缀）
    $redirect_url = '';
    if (!empty($_REQUEST['redirect_to'])) {
        $candidate = trim((string) $_REQUEST['redirect_to']);
        if (filter_var($candidate, FILTER_VALIDATE_URL) || strpos($candidate, '/') === 0) {
            $redirect_url = $candidate;
        }
    }
    if (empty($redirect_url)) {
        $raw_base = function_exists('todo_get_site_url') ? rtrim(todo_get_site_url(), '/') : (function_exists('todo_home_url') ? rtrim(todo_home_url(), '/') : (function_exists('site_url') ? rtrim(site_url(), '/') : ''));
        if (function_exists('current_locale') && function_exists('get_language_url')) {
            $redirect_url = rtrim(get_language_url(current_locale(), $raw_base), '/');
        } else {
            $redirect_url = $raw_base;
        }
    }
    if (empty($redirect_url)) {
        $redirect_url = '/';
    } else {
        $redirect_url = rtrim($redirect_url, '/') . '/';
    }

    $success_msg = 'Your account has been anonymized and deleted according to GDPR Article 17.';
    if (function_exists('current_locale') && strpos(current_locale(), 'zh') !== false) {
        $success_msg = '您的账号已成功注销并完成个人敏感数据脱敏，历史交易凭据已依法合规封存。';
    }

    echo json_encode([
        'success' => true,
        'message' => $success_msg,
        'redirect' => $redirect_url
    ]);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => false, 'message' => 'Invalid action']);
exit;
