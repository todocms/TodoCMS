<?php
/**
 * 登录二维码生成器
 * 
 * 生成用于扫码登录的二维码
 */

// 定义 ABSPATH 常量
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}

// 加载核心文件（如果需要获取站点信息）
if (file_exists(ABSPATH . 'config.php')) {
    require_once ABSPATH . 'config.php';
}

// 获取待生成的内容
// 实际应用中，这里应该生成一个带 token 的 URL，例如 https://your-site.com/login?token=xxxx
$site_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
$data = $site_url . '/api/user/mobile-login-handler.php?action=scan&sid=' . session_id();

// 使用公共 API 生成二维码并跳转（作为快速实现）
// 也可以使用本地库生成，但为了不增加依赖，使用 API 跳转
$qr_api = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($data);

header("Location: $qr_api");
exit;
