<?php
/**
 * TodoCMS 前端用户找回密码处理器
 * 支持邮箱和手机号找回密码
 *
 * @package TodoCMS
 */

// 设置错误报告与 JSON 基础响应头
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

// 定义程序根目录路径
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(__DIR__)) . '/');
}

try {
    // 引入核心框架入口文件
    require_once ABSPATH . 'config.php';
    require_once ABSPATH . 'core/bootstrap.php';
    require_once ABSPATH . 'core/functions/function-loader.php';

    // 载入系统核心函数库
    if (class_exists('Todo_Function_Loader')) {
        Todo_Function_Loader::load_core_functions();
    }

    if (class_exists('Todo_Session_Manager')) {
        Todo_Session_Manager::init();
    } elseif (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Core loading error: ' . $e->getMessage()
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

// 检查AJAX请求
if (!todo_is_ajax_request()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => __('非法访问')
    ]);
    exit;
}

// PRG 防重复提交守卫（仅对携带 _submit_key 的 POST 生效）
Todo_PRG_Guard::guard_endpoint('user_forgot_password', 30);

try {
    // 验证CSRF令牌
    $csrf_token = todo_post_string('_csrf_token');
    if ($csrf_token === '') {
        $csrf_token = todo_post_string('_todononce');
    }
    if (function_exists('todo_verify_csrf_token')) {
        if (!empty($csrf_token) && !todo_verify_csrf_token($csrf_token, 'forgot_password')) {
            throw new Exception(__('安全验证失败，请刷新页面重试'));
        }
    }

    // 获取操作类型
    $action = trim(todo_post_string('action', 'send_code')); // send_code 或 reset_password

    if (!in_array($action, ['send_code', 'reset_password'])) {
        throw new Exception(__('无效的操作类型'));
    }

    // 根据操作类型处理
    if ($action === 'send_code') {
        handle_send_reset_code();
    } else {
        handle_reset_password();
    }

} catch (Exception $e) {
    // 记录错误日志
    if (function_exists('todo_log_error')) {
        todo_log_error('Forgot Password Error: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'action' => $action ?? '',
            'ip' => todo_server_string('REMOTE_ADDR')
        ]);
    }

    // 返回错误响应（使用 200 HTTP 响应码，由 success 字段标记业务状态，防止前端底层 AJAX 报 500/400 错误）
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * 发送重置密码验证码
 */
function handle_send_reset_code()
{
    // 获取数据
    $account = trim(todo_post_string('account')); // 邮箱或手机号
    $captcha = trim(todo_post_string('captcha'));
    $type = trim(todo_post_string('type', 'auto')); // auto, email, mobile

    // 基础验证
    if (empty($account)) {
        throw new Exception(__('请输入邮箱或手机号'));
    }

    if (empty($captcha)) {
        throw new Exception(__('请输入验证码'));
    }

    // 验证图形验证码 (支持前端滑块验证成功的 session 标记以及传统验证码)
    if (!todo_verify_captcha($captcha)) {
        throw new Exception(__('验证码校验失败，请刷新滑块重试'));
    }

    // 自动判断账号类型
    if ($type === 'auto') {
        if (filter_var($account, FILTER_VALIDATE_EMAIL)) {
            $type = 'email';
        } elseif (preg_match('/^1[3-9]\d{9}$/', $account)) {
            $type = 'mobile';
        } else {
            throw new Exception(__('请输入正确的邮箱或手机号'));
        }
    }

    // 查找用户
    $user = null;
    if ($type === 'email') {
        $user = get_user_by('email', $account);
        if (!$user) {
            throw new Exception(__('该邮箱未注册'));
        }
    } else {
        $user = get_user_by_mobile($account);
        if (!$user) {
            throw new Exception(__('该手机号未注册'));
        }
    }

    // 检查发送频率限制
    if (!todo_check_reset_code_rate_limit($account, $type)) {
        throw new Exception(__('发送过于频繁，请稍后再试'));
    }

    // 生成重置码
    $reset_code = sprintf('%06d', mt_rand(100000, 999999));

    // 保存重置码
    if (!todo_save_reset_code($user->ID, $account, $reset_code, $type)) {
        throw new Exception(__('重置码保存失败'));
    }

    // 发送重置码
    $sent = false;
    $send_error = '';
    if ($type === 'email') {
        $sent = todo_send_reset_email($user, $reset_code);
    } else {
        $sent = todo_send_reset_sms($account, $reset_code, $send_error);
    }

    if (!$sent) {
        throw new Exception(!empty($send_error) ? $send_error : __('发送失败，请重试'));
    }

    // 记录发送日志
    if (function_exists('todo_log_user_action')) {
        todo_log_user_action($user->ID, 'forgot_password_send', [
            'type' => $type,
            'account' => $account,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    }

    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => $type === 'email' ? __('重置邮件发送成功，请查收邮箱') : __('验证码发送成功，请注意查收'),
        'data' => [
            'type' => $type,
            'account' => $account,
            'expires_in' => 300 // 5分钟有效期
        ]
    ]);
}

/**
 * 重置密码
 */
function handle_reset_password()
{
    // 获取数据
    $account = trim(todo_post_string('account'));
    $reset_code = trim(todo_post_string('reset_code'));
    $new_password = trim(todo_post_string('new_password'));
    $confirm_password = trim(todo_post_string('confirm_password'));
    $type = trim(todo_post_string('type'));

    // 基础验证
    if (empty($account)) {
        throw new Exception(__('账号信息丢失'));
    }

    if (empty($reset_code)) {
        throw new Exception(__('请输入重置码'));
    }

    if (empty($new_password)) {
        throw new Exception(__('请输入新密码'));
    }

    if (empty($confirm_password)) {
        throw new Exception(__('请确认新密码'));
    }

    if (empty($type)) {
        throw new Exception(__('重置类型丢失'));
    }

    // 详细验证
    if (strlen($new_password) < 6) {
        throw new Exception(__('密码长度不能少于6位'));
    }

    if ($new_password !== $confirm_password) {
        throw new Exception(__('两次输入的密码不一致'));
    }

    // 验证重置码
    $user_id = todo_verify_reset_code($account, $reset_code, $type);
    if (!$user_id) {
        throw new Exception(__('重置码错误或已过期'));
    }

    // 更新密码
    $result = todo_update_user([
        'ID' => $user_id,
        'user_pass' => $new_password
    ]);
    if (is_todo_error($result)) {
        throw new Exception(__('密码重置失败'));
    }

    // 清除所有重置码
    todo_clear_reset_codes($user_id);

    // 记录重置日志
    if (function_exists('todo_log_user_action')) {
        todo_log_user_action($user_id, 'password_reset', [
            'type' => $type,
            'account' => $account,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    }

    // 发送密码重置通知
    $user = get_user_by('ID', $user_id);
    if ($user && $user->user_email) {
        todo_send_password_reset_notification($user);
    }

    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => __('密码重置成功'),
        'data' => [
            'redirect_url' => todo_get_login_url()
        ]
    ]);
}

/**
 * 检查重置码发送频率限制
 */
function todo_check_reset_code_rate_limit($account, $type)
{
    try {
        global $tododb;
        $field = ($type === 'email') ? 'email' : 'mobile';

        // 检查1分钟内是否已发送
        $count = $tododb->get_var(
            $tododb->prepare(
                "SELECT COUNT(*) FROM {$tododb->prefix}password_reset_codes 
                 WHERE {$field} = %s AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)",
                $account
            )
        );

        if ($count > 0) {
            return false;
        }

        // 检查1小时内发送次数（最多3次）
        $count = $tododb->get_var(
            $tododb->prepare(
                "SELECT COUNT(*) FROM {$tododb->prefix}password_reset_codes 
                 WHERE {$field} = %s AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                $account
            )
        );

        return $count < 3;
    } catch (Exception $e) {
        error_log('Reset code rate limit check failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * 保存重置码
 */
function todo_save_reset_code($user_id, $account, $code, $type)
{
    try {
        global $tododb;
        $field = ($type === 'email') ? 'email' : 'mobile';

        // 插入新重置码（适应系统 Schema: email, mobile, reset_code, expires_at, used, ip_address, created_at）
        $data = [
            'user_id'    => $user_id,
            $field       => $account,
            'reset_code' => $code,
            'expires_at' => date('Y-m-d H:i:s', time() + 300),
            'used'       => 0,
            'ip_address' => todo_server_string('REMOTE_ADDR'),
            'created_at' => date('Y-m-d H:i:s')
        ];

        $result = $tododb->insert($tododb->prefix . 'password_reset_codes', $data);
        return $result !== false;
    } catch (Exception $e) {
        error_log('Save reset code error: ' . $e->getMessage());
        return false;
    }
}

/**
 * 验证重置码
 */
function todo_verify_reset_code($account, $code, $type)
{
    try {
        global $tododb;
        $field = ($type === 'email') ? 'email' : 'mobile';

        $result = $tododb->get_row(
            $tododb->prepare(
                "SELECT * FROM {$tododb->prefix}password_reset_codes 
                 WHERE {$field} = %s AND reset_code = %s AND used = 0 
                 AND expires_at > NOW() 
                 ORDER BY created_at DESC LIMIT 1",
                $account,
                $code
            )
        );

        if ($result) {
            // 标记重置码为已使用
            $tododb->update(
                $tododb->prefix . 'password_reset_codes',
                ['used' => 1, 'used_at' => date('Y-m-d H:i:s')],
                ['id' => $result->id]
            );
            return $result->user_id;
        }
    } catch (Exception $e) {
        error_log('Reset code verification failed: ' . $e->getMessage());
        return false;
    }

    return false;
}

/**
 * 清除用户的所有重置码
 */
function todo_clear_reset_codes($user_id)
{
    global $tododb;

    $tododb->update(
        $tododb->prefix . 'password_reset_codes',
        ['used' => 1, 'used_at' => date('Y-m-d H:i:s')],
        ['user_id' => $user_id, 'used' => 0]
    );
}

/**
 * 发送重置邮件
 * 优先使用邮件模板，如果模板不存在则使用默认格式
 */
function todo_send_reset_email($user, $reset_code)
{
    // 尝试使用邮件模板
    if (function_exists('todo_send_verification_code_email')) {
        return todo_send_verification_code_email($user->user_email, $reset_code, 'forgot_password');
    }
    
    $site_name = function_exists('todo_get_option') ? todo_get_option('site_title', 'TodoCMS') : (function_exists('get_option') ? get_option('site_title', 'TodoCMS') : 'TodoCMS');
    $current_lang = '';
    if (function_exists('get_current_language')) {
        $current_lang = (string) get_current_language();
    } elseif (class_exists('LocaleContext')) {
        $current_lang = (string) LocaleContext::get_instance()->get_locale();
    }
    if (empty($current_lang)) {
        $current_lang = trim(todo_post_string('lang', todo_get_string('lang')));
    }
    if (empty($current_lang) && !empty($_COOKIE['todo_locale'])) {
        $current_lang = trim((string) $_COOKIE['todo_locale']);
    }
    if (empty($current_lang) && function_exists('get_default_language')) {
        $current_lang = (string) get_default_language();
    }

    $resolved_site_name = $site_name;
    if (!empty($current_lang) && function_exists('todo_resolve_multilang_text')) {
        $resolved_site_name = todo_resolve_multilang_text($site_name, $current_lang);
    }

    $subject = __('密码重置验证码') . ' - ' . $resolved_site_name;
    $message = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">';
    $message .= '<h2 style="color: #333;">' . esc_html(__('密码重置验证码')) . '</h2>';
    $message .= '<p>' . sprintf(esc_html(__('您好 %s')), '，<strong>' . esc_html($user->display_name) . '</strong>') . '</p>';
    $message .= '<p>' . esc_html(__('您的密码重置验证码是')) . '：<strong style="font-size: 24px; color: #007bff;">' . esc_html($reset_code) . '</strong></p>';
    $message .= '<p style="color: #666;">' . esc_html(__('验证码有效期为5分钟，请及时使用')) . '</p>';
    $message .= '<p style="color: #999; font-size: 12px;">' . esc_html(__('如果这不是您的操作，请忽略此邮件')) . '</p>';
    $message .= '<hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">';
    $message .= '<p style="color: #999; font-size: 12px;">' . esc_html($resolved_site_name) . '</p>';
    $message .= '</div>';

    $headers = ['Content-Type: text/html; charset=UTF-8'];
    return todo_mail($user->user_email, $subject, $message, $headers);
}

/**
 * 发送重置短信
 */
function todo_send_reset_sms($mobile, $reset_code, &$error_msg = '')
{
    // 如果函数尚未加载，尝试动态引入短信核心函数
    if (!function_exists('todo_send_sms')) {
        $sms_file = ABSPATH . 'core/functions/sms-functions.php';
        if (file_exists($sms_file)) {
            require_once $sms_file;
        }
    }

    if (!function_exists('todo_send_sms')) {
        $error_msg = __('短信发送系统未配置或未加载');
        return false;
    }

    // 调用短信发送函数
    return todo_send_sms($mobile, $reset_code, 'forgot_password', $error_msg);
}

/**
 * 发送密码重置成功通知
 * 使用HTML格式提升用户体验
 */
function todo_send_password_reset_notification($user)
{
    $site_name = function_exists('todo_get_option') ? todo_get_option('site_title', 'TodoCMS') : (function_exists('get_option') ? get_option('site_title', 'TodoCMS') : 'TodoCMS');
    $email = $user->user_email;
    $username = $user->user_login ?? '';
    $display_name = $user->display_name ?: $username;
    $reset_time = function_exists('current_time') ? current_time('Y-m-d H:i:s') : date('Y-m-d H:i:s');

    // 获取当前用户操作的多语言上下文
    $current_lang = '';
    if (function_exists('get_current_language')) {
        $current_lang = (string) get_current_language();
    } elseif (class_exists('LocaleContext')) {
        $current_lang = (string) LocaleContext::get_instance()->get_locale();
    }
    if (empty($current_lang)) {
        $current_lang = trim(todo_post_string('lang', todo_get_string('lang')));
    }
    if (empty($current_lang) && !empty($_COOKIE['todo_locale'])) {
        $current_lang = trim((string) $_COOKIE['todo_locale']);
    }
    if (empty($current_lang) && function_exists('get_default_language')) {
        $current_lang = (string) get_default_language();
    }

    // 优先支持邮件模板通知（fixed_slug: password_reset_notify 或 category: password_reset）
    $template_slug = 'password_reset_notify';
    $tpl = null;
    if (function_exists('todo_get_email_template')) {
        $tpl = todo_get_email_template($template_slug);
        if (!$tpl) {
            $tpl = todo_get_email_template('password_reset');
            if ($tpl) {
                $template_slug = 'password_reset';
            }
        }
    }

    if ($tpl && function_exists('todo_send_template_mail')) {
        $mail_data = [
            'username'     => $username,
            'email'        => $email,
            'display_name' => $display_name,
            'reset_time'   => $reset_time,
            'site_name'    => $site_name,
            'lang'         => $current_lang,
            'language'     => $current_lang,
            'order_lang'   => $current_lang,
        ];
        $meta = [
            'lang'       => $current_lang,
            'order_lang' => $current_lang,
        ];
        return todo_send_template_mail($email, $template_slug, $mail_data, false, $meta);
    }

    // 若未配置邮件模板，对回退文本支持多语言站点名解析
    $resolved_site_name = $site_name;
    if (!empty($current_lang) && function_exists('todo_resolve_multilang_text')) {
        $resolved_site_name = todo_resolve_multilang_text($site_name, $current_lang);
    }

    $subject = __('密码重置成功通知') . ' - ' . $resolved_site_name;
    
    // 模板未配置时，回退到默认HTML格式
    $message = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">';
    $message .= '<h2 style="color: #28a745;">' . esc_html(__('密码重置成功')) . '</h2>';
    $message .= '<p>' . sprintf(esc_html(__('您好 %s')), '，<strong>' . esc_html($display_name) . '</strong>') . '</p>';
    $message .= '<p>' . esc_html(__('您的账号密码已成功重置')) . '</p>';
    $message .= '<p><strong>' . esc_html(__('重置时间')) . ': </strong>' . esc_html($reset_time) . '</p>';
    $message .= '<div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px; margin: 20px 0;">';
    $message .= '<p style="margin: 0; color: #856404;">⚠️ ' . esc_html(__('如果这不是您的操作，请立即联系我们')) . '</p>';
    $message .= '</div>';
    $message .= '<hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">';
    $message .= '<p style="color: #999; font-size: 12px;">' . esc_html($resolved_site_name) . '</p>';
    $message .= '</div>';

    $headers = ['Content-Type: text/html; charset=UTF-8'];
    return todo_mail($email, $subject, $message, $headers);
}

/**
 * 根据手机号获取用户
 */
if (!function_exists('get_user_by_mobile')) {
    function get_user_by_mobile($mobile)
    {
        $users = get_users([
            'meta_key' => 'mobile',
            'meta_value' => $mobile,
            'number' => 1
        ]);

        if (!empty($users)) {
            $u = $users[0];
            $raw_status = isset($u->user_status) ? (int)$u->user_status : 0;
            if ($raw_status !== -1) {
                return $u;
            }
        }

        return false;
    }
}

/**
 * 获取登录页面URL
 * 标准化使用 ?user=login 格式
 */
if (!function_exists('todo_get_login_url')) {
    function todo_get_login_url()
    {
        if (function_exists('get_login_url')) {
            return get_login_url();
        }
        // 回退方案：使用标准化格式
        return site_url('?user=login');
    }
}
