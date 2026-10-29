<?php
/**
 * TodoCMS 短信验证码发送API
 * 支持登录、注册、找回密码等场景
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
Todo_PRG_Guard::guard_endpoint('sms_send', 30);

try {
    // 验证CSRF令牌
    if (!function_exists('todo_verify_csrf_token')) {
        throw new Exception('CSRF验证函数未加载');
    }

    if (!todo_verify_csrf_token()) {
        throw new Exception(__('安全验证失败，请刷新页面重试'));
    }

    // 获取请求数据
    $mobile = trim(todo_post_string('mobile'));
    $captcha = trim(todo_post_string('captcha'));
    $type = trim(todo_post_string('type', 'login')); // login, register, forgot_password

    // 基础验证
    if (empty($mobile)) {
        throw new Exception(__('请输入手机号码'));
    }

    if (!preg_match('/^1[3-9]\d{9}$/', $mobile)) {
        throw new Exception(__('请输入正确的手机号码'));
    }

    if (empty($captcha)) {
        throw new Exception(__('请输入图形验证码'));
    }

    if (!in_array($type, ['login', 'register', 'forgot_password'])) {
        throw new Exception(__('无效的验证码类型'));
    }

    // 验证图形验证码
    if (!function_exists('todo_verify_captcha')) {
        throw new Exception(__('验证码验证函数未加载'));
    }

    if (!todo_verify_captcha($captcha)) {
        throw new Exception(__('图形验证码错误'));
    }

    // 检查发送频率限制
    if (!todo_check_sms_rate_limit($mobile)) {
        throw new Exception(__('发送过于频繁，请稍后再试'));
    }

    // 根据类型进行业务验证
    switch ($type) {
        case 'login':
            // 登录：检查手机号是否已注册
            if (!get_user_by_mobile($mobile)) {
                throw new Exception(__('该手机号未注册'));
            }
            break;

        case 'register':
            // 注册：检查系统是否开启允许任何人注册
            if (function_exists('get_option') && !get_option('users_can_register', false)) {
                throw new Exception(__('网站目前已暂停新用户注册'));
            }
            // 检查手机号是否已被使用
            if (get_user_by_mobile($mobile)) {
                throw new Exception(__('该手机号已被注册'));
            }
            break;

        case 'forgot_password':
            // 找回密码：检查手机号是否已注册
            if (!get_user_by_mobile($mobile)) {
                throw new Exception(__('该手机号未注册'));
            }
            break;
    }

    // 生成验证码
    $code = sprintf('%06d', mt_rand(100000, 999999));

    // 保存验证码到数据库
    if (!todo_save_sms_code($mobile, $code, $type)) {
        throw new Exception(__('验证码保存失败'));
    }

    // 发送短信
    $sms_error = '';
    if (!todo_send_sms($mobile, $code, $type, $sms_error)) {
        throw new Exception(!empty($sms_error) ? $sms_error : __('短信发送失'));
    }

    // 记录发送日志
    if (function_exists('todo_log_sms_send')) {
        todo_log_sms_send($mobile, $type, [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    }

    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => __('验证码发送成功，请注意查收'),
        'data' => [
            'mobile' => $mobile,
            'expires_in' => 300 // 5分钟有效期
        ]
    ]);

} catch (Exception $e) {
    // 记录错误日志
    if (function_exists('todo_log_error')) {
        todo_log_error('SMS Send Error', $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'mobile' => $mobile ?? '',
            'type' => $type ?? '',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    }

    // 返回错误响应
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * 检查短信发送频率限制
 */
function todo_check_sms_rate_limit($mobile)
{
    try {
        $pdo = get_database_connection();

        // 检查1分钟内是否已发送
        $count = $pdo->get_var(
            "SELECT COUNT(*) FROM {$pdo->prefix}sms_codes 
             WHERE mobile = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)",
            [$mobile]
        );

        if ($count > 0) {
            return false;
        }

        // 检查1小时内发送次数（最多5次）
        $count = $pdo->get_var(
            "SELECT COUNT(*) FROM {$pdo->prefix}sms_codes 
             WHERE mobile = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            [$mobile]
        );

        return $count < 5;
    } catch (Exception $e) {
        error_log('SMS rate limit check failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * 保存短信验证码
 */
function todo_save_sms_code($mobile, $code, $type)
{
    try {
        $pdo = get_database_connection();

        // 先清理该手机号未使用的旧验证码
        $pdo->query(
            "UPDATE {$pdo->prefix}sms_codes SET used = 1, used_at = ? 
             WHERE mobile = ? AND type = ? AND used = 0",
            [date('Y-m-d H:i:s'), $mobile, $type]
        );

        // 插入新验证码
        $result = $pdo->query(
            "INSERT INTO {$pdo->prefix}sms_codes 
             (mobile, code, type, used, expires_at, created_at, ip_address) 
             VALUES (?, ?, ?, 0, ?, ?, ?)",
            [
                $mobile,
                $code,
                $type,
                date('Y-m-d H:i:s', time() + 300),
                date('Y-m-d H:i:s'),
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]
        );

        return $result !== false;
    } catch (Exception $e) {
        error_log('SMS code save failed: ' . $e->getMessage());
        return false;
    }
}

if (!function_exists('todo_send_sms')) {
    /**
     * 发送短信
     */
    function todo_send_sms($mobile, $code, $type, &$error_msg = '')
    {
        $provider = get_option('sms_provider', 'aliyun');

        switch ($provider) {
            case 'tencent':
                return todo_send_sms_tencent($mobile, $code, $type, $error_msg);

            case 'aliyun':
            default:
                return todo_send_sms_aliyun($mobile, $code, $type, $error_msg);
        }
    }
}

if (!function_exists('todo_send_sms_aliyun')) {
    /**
     * 阿里云短信发送（通用 HTTP/POP API 原生签名算法，零第三方 SDK 依赖）
     */
    function todo_send_sms_aliyun($mobile, $code, $type, &$error_msg = '')
    {
        $accessKeyId = get_option('sms_aliyun_access_key_id', '');
        $accessKeySecret = get_option('sms_aliyun_access_key_secret', '');
        $signName = get_option('sms_aliyun_sign_name', '');
        $templateCode = get_option('sms_aliyun_template_code', '');
        $endpoint = trim((string) get_option('sms_aliyun_endpoint', ''));
        $region = trim((string) get_option('sms_aliyun_region', ''));

        if (empty($accessKeyId) || empty($accessKeySecret) || empty($signName) || empty($templateCode)) {
            $error_msg = '阿里云短信配置不完整，请在后台设置中填写';
            error_log('Aliyun SMS error: Missing configuration.');
            return false;
        }

        // 清理 endpoint（去除可能包含的 https:// 或末尾斜杠）
        $endpoint = preg_replace('#^https?://#i', '', rtrim($endpoint, '/'));
        if (empty($endpoint)) {
            $endpoint = 'dysmsapi.aliyuncs.com';
        }
        if (empty($region)) {
            $region = 'cn-hangzhou';
        }

        // 根据接口域名或配置判断是测试接口(dypnsapi)还是正式商用接口(dysmsapi)
        $is_dypns = (stripos($endpoint, 'dypnsapi') !== false);
        $action = $is_dypns ? 'SendSmsVerifyCode' : 'SendSms';
        $phone_key = $is_dypns ? 'PhoneNumber' : 'PhoneNumbers';

        $params = [
            'AccessKeyId'      => $accessKeyId,
            'Action'           => $action,
            'Format'           => 'JSON',
            $phone_key         => $mobile,
            'RegionId'         => $region,
            'SignName'         => $signName,
            'SignatureMethod'  => 'HMAC-SHA1',
            'SignatureNonce'   => uniqid(mt_rand(), true),
            'SignatureVersion' => '1.0',
            'TemplateCode'     => $templateCode,
            'TemplateParam'    => json_encode(['code' => (string)$code, 'min'  => '5']),
            'Timestamp'        => gmdate('Y-m-d\TH:i:s\Z'),
            'Version'          => '2017-05-25',
        ];

        ksort($params);

        $canonicalizedQueryString = '';
        foreach ($params as $key => $value) {
            $canonicalizedQueryString .= '&' . rawurlencode($key) . '=' . rawurlencode($value);
        }
        $stringToSign = 'GET&%2F&' . rawurlencode(substr($canonicalizedQueryString, 1));
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));

        $url = 'https://' . $endpoint . '/?Signature=' . rawurlencode($signature) . $canonicalizedQueryString;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if (!empty($curl_error)) {
            $error_msg = '阿里云请求网络错误: ' . $curl_error;
            error_log('Aliyun SMS Curl Error: ' . $curl_error);
            return false;
        }

        $resData = json_decode($response, true);
        if (isset($resData['Code']) && $resData['Code'] === 'OK') {
            return true;
        }

        $errMsg = $resData['Message'] ?? ($resData['Code'] ?? $response);
        $error_msg = '阿里云接口返回: ' . $errMsg;
        error_log('Aliyun SMS Send Failed: ' . $errMsg);
        return false;
    }
}

if (!function_exists('todo_send_sms_tencent')) {
    /**
     * 腾讯云短信发送（TC3-HMAC-SHA256 签名算法）
     */
    function todo_send_sms_tencent($mobile, $code, $type, &$error_msg = '')
    {
        $secretId = get_option('sms_tencent_secret_id', '');
        $secretKey = get_option('sms_tencent_secret_key', '');
        $sdkAppId = get_option('sms_tencent_app_id', '');
        $signName = get_option('sms_tencent_sign_name', '');
        $templateId = get_option('sms_tencent_template_id', '');
        $endpoint = trim((string) get_option('sms_tencent_endpoint', ''));
        $region = trim((string) get_option('sms_tencent_region', ''));

        if (empty($secretId) || empty($secretKey) || empty($sdkAppId) || empty($signName) || empty($templateId)) {
            $error_msg = '腾讯云短信配置不完整，请在后台设置中填写';
            error_log('Tencent SMS error: Missing configuration.');
            return false;
        }

        // 清理 endpoint 与 region
        $endpoint = preg_replace('#^https?://#i', '', rtrim($endpoint, '/'));
        $host = !empty($endpoint) ? $endpoint : 'sms.tencentcloudapi.com';
        $region = !empty($region) ? $region : 'ap-guangzhou';

        $formattedMobile = '+86' . ltrim($mobile, '+86');

        $payloadArr = [
            'SmsSdkAppId'      => $sdkAppId,
            'SignName'         => $signName,
            'TemplateId'       => $templateId,
            'TemplateParamSet' => [(string)$code],
            'PhoneNumberSet'   => [$formattedMobile]
        ];
        $payload = json_encode($payloadArr);

        $service = 'sms';
        $version = '2021-01-11';
        $action = 'SendSms';
        $timestamp = time();
        $date = gmdate('Y-m-d', $timestamp);

        // 1. 规范请求串
        $httpRequestMethod = 'POST';
        $canonicalUri = '/';
        $canonicalQueryString = '';
        $canonicalHeaders = "content-type:application/json; charset=utf-8\nhost:" . $host . "\nx-tc-action:" . strtolower($action) . "\n";
        $signedHeaders = 'content-type;host;x-tc-action';
        $hashedRequestPayload = hash('sha256', $payload);
        $canonicalRequest = $httpRequestMethod . "\n" . $canonicalUri . "\n" . $canonicalQueryString . "\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $hashedRequestPayload;

        // 2. 待签名字符串
        $algorithm = 'TC3-HMAC-SHA256';
        $credentialScope = $date . '/' . $service . '/tc3_request';
        $hashedCanonicalRequest = hash('sha256', $canonicalRequest);
        $stringToSign = $algorithm . "\n" . $timestamp . "\n" . $credentialScope . "\n" . $hashedCanonicalRequest;

        // 3. 计算签名
        $secretDate = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
        $secretService = hash_hmac('sha256', $service, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature = hash_hmac('sha256', $stringToSign, $secretSigning);

        // 4. 构造 Authorization
        $authorization = $algorithm . ' ' . 'Credential=' . $secretId . '/' . $credentialScope . ', ' . 'SignedHeaders=' . $signedHeaders . ', ' . 'Signature=' . $signature;

        $headers = [
            'Authorization: ' . $authorization,
            'Content-Type: application/json; charset=utf-8',
            'Host: ' . $host,
            'X-TC-Action: ' . $action,
            'X-TC-Timestamp: ' . $timestamp,
            'X-TC-Version: ' . $version,
            'X-TC-Region: ' . $region
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://' . $host);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if (!empty($curl_error)) {
            $error_msg = '腾讯云请求网络错误: ' . $curl_error;
            error_log('Tencent SMS Curl Error: ' . $curl_error);
            return false;
        }

        $resData = json_decode($response, true);
        if (isset($resData['Response']['SendStatusSet'][0]['Code']) && $resData['Response']['SendStatusSet'][0]['Code'] === 'Ok') {
            return true;
        }

        $errMsg = $resData['Response']['Error']['Message'] ?? ($resData['Response']['SendStatusSet'][0]['Message'] ?? $response);
        $error_msg = '腾讯云接口返回: ' . $errMsg;
        error_log('Tencent SMS Send Failed: ' . $errMsg);
        return false;
    }
}

/**
 * 根据手机号获取用户
 */
if (!function_exists('get_user_by_mobile')) {
    function get_user_by_mobile($mobile)
    {
        $mobile = trim((string) $mobile);
        if ($mobile === '') {
            return false;
        }

        try {
            $pdo = get_database_connection();
            $user_id = $pdo->get_var(
                "SELECT m.user_id FROM {$pdo->prefix}user_meta m 
                 INNER JOIN {$pdo->prefix}users u ON m.user_id = u.ID
                 WHERE m.meta_key = 'mobile' AND m.meta_value = ? AND u.user_status != -1 LIMIT 1",
                [$mobile]
            );

            if ($user_id && function_exists('get_user_by_id')) {
                return get_user_by_id($user_id);
            }

            if ($user_id && function_exists('get_user_by')) {
                return get_user_by('id', $user_id);
            }

            if (function_exists('get_user_by')) {
                $user = get_user_by('login', $mobile);
                if ($user && (!isset($user->user_status) || (int)$user->user_status !== -1)) {
                    return $user;
                }
            }

            return false;
        } catch (Exception $e) {
            error_log('Get user by mobile failed: ' . $e->getMessage());
            return false;
        }
    }
}
