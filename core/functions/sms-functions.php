<?php
/**
 * TodoCMS 短信服务核心函数库
 * 提供统一短信发送接口，支持阿里云短信与腾讯云短信
 *
 * @package TodoCMS
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('todo_send_sms')) {
    /**
     * 发送短信
     *
     * @param string $mobile 接收手机号码
     * @param string $code 验证码或主要参数
     * @param string $type 业务类型（如 login, register, forgot_password）
     * @param string &$error_msg 错误信息输出
     * @return bool
     */
    function todo_send_sms($mobile, $code, $type, &$error_msg = '')
    {
        $mobile = trim((string)$mobile);
        if (empty($mobile)) {
            $error_msg = '手机号不能为空';
            return false;
        }

        $provider = function_exists('get_option') ? get_option('sms_provider', 'aliyun') : 'aliyun';

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
     *
     * @param string $mobile 手机号
     * @param string $code 验证码
     * @param string $type 类型
     * @param string &$error_msg 错误信息输出
     * @return bool
     */
    function todo_send_sms_aliyun($mobile, $code, $type, &$error_msg = '')
    {
        $accessKeyId = function_exists('get_option') ? get_option('sms_aliyun_access_key_id', '') : '';
        $accessKeySecret = function_exists('get_option') ? get_option('sms_aliyun_access_key_secret', '') : '';
        $signName = function_exists('get_option') ? get_option('sms_aliyun_sign_name', '') : '';
        $templateCode = function_exists('get_option') ? get_option('sms_aliyun_template_code', '') : '';
        $endpoint = function_exists('get_option') ? trim((string) get_option('sms_aliyun_endpoint', '')) : '';
        $region = function_exists('get_option') ? trim((string) get_option('sms_aliyun_region', '')) : '';

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
            'TemplateParam'    => json_encode(['code' => (string)$code, 'min' => '5']),
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
     *
     * @param string $mobile 手机号
     * @param string $code 验证码
     * @param string $type 类型
     * @param string &$error_msg 错误信息输出
     * @return bool
     */
    function todo_send_sms_tencent($mobile, $code, $type, &$error_msg = '')
    {
        $secretId = function_exists('get_option') ? get_option('sms_tencent_secret_id', '') : '';
        $secretKey = function_exists('get_option') ? get_option('sms_tencent_secret_key', '') : '';
        $sdkAppId = function_exists('get_option') ? get_option('sms_tencent_app_id', '') : '';
        $signName = function_exists('get_option') ? get_option('sms_tencent_sign_name', '') : '';
        $templateId = function_exists('get_option') ? get_option('sms_tencent_template_id', '') : '';
        $endpoint = function_exists('get_option') ? trim((string) get_option('sms_tencent_endpoint', '')) : '';
        $region = function_exists('get_option') ? trim((string) get_option('sms_tencent_region', '')) : '';

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
