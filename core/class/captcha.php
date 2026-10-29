<?php
/**
 * 验证码生成器
 * 
 * 为TodoCMS提供验证码图片生成功能
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    define('ABSPATH', rtrim(dirname(dirname(__FILE__)), '/') . '/');
}

// 启动会话
session_start();

// 获取验证码类型
$type = isset($_GET['type']) ? $_GET['type'] : 'default';

// 验证码配置
$config = [
    'width' => 120,
    'height' => 40,
    'length' => 4,
    'font_size' => 16,
    'bg_color' => [255, 255, 255],
    'text_color' => [0, 0, 0],
    'noise_color' => [200, 200, 200],
    'chars' => '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'
];

// 根据类型调整配置
switch ($type) {
    case 'users_login':
        $config['length'] = 4;
        break;
    case 'users_mobile_login':
        $config['length'] = 4;
        break;
    case 'users_register':
        $config['length'] = 5;
        break;
    default:
        $config['length'] = 4;
        break;
}

// 生成验证码字符串
$captcha_string = '';
for ($i = 0; $i < $config['length']; $i++) {
    $captcha_string .= $config['chars'][rand(0, strlen($config['chars']) - 1)];
}

// 将验证码保存到会话中
$_SESSION['captcha_' . $type] = strtolower($captcha_string);

// 创建图像
$image = imagecreate($config['width'], $config['height']);

// 分配颜色
$bg_color = imagecolorallocate($image, $config['bg_color'][0], $config['bg_color'][1], $config['bg_color'][2]);
$text_color = imagecolorallocate($image, $config['text_color'][0], $config['text_color'][1], $config['text_color'][2]);
$noise_color = imagecolorallocate($image, $config['noise_color'][0], $config['noise_color'][1], $config['noise_color'][2]);

// 填充背景
imagefill($image, 0, 0, $bg_color);

// 添加噪点
for ($i = 0; $i < 50; $i++) {
    imagesetpixel($image, rand(0, $config['width']), rand(0, $config['height']), $noise_color);
}

// 添加干扰线
for ($i = 0; $i < 3; $i++) {
    imageline($image, rand(0, $config['width']), rand(0, $config['height']), 
              rand(0, $config['width']), rand(0, $config['height']), $noise_color);
}

// 绘制验证码文字
$char_width = $config['width'] / $config['length'];
for ($i = 0; $i < $config['length']; $i++) {
    $char = $captcha_string[$i];
    $x = $char_width * $i + rand(5, 10);
    $y = rand($config['font_size'], $config['height'] - 5);
    $angle = rand(-15, 15);
    
    // 使用内置字体
    imagestring($image, 5, $x, $y - 20, $char, $text_color);
}

// 设置HTTP头
header('Content-Type: image/png');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// 输出图像
imagepng($image);

// 清理内存
imagedestroy($image);
