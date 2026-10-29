<?php

$uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
$path = (string) parse_url($uri, PHP_URL_PATH);
if ($path === '') {
    $path = '/';
}
// 规范化双斜杠，避免 //includes/... 等路径导致 404
$path = preg_replace('#/{2,}#', '/', $path);

$full_path = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . $path;
if ($path !== '/' && (is_file($full_path) || is_dir($full_path))) {
    return false;
}

if ($path === '/api' || strpos($path, '/api/') === 0) {
    require __DIR__ . '/index.php';
    return true;
}

$index = dirname(__DIR__) . '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $index;

// 计算安装子目录前缀，确保子目录部署时 SCRIPT_NAME 正确
$base_prefix = '';
$doc_root = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/')) : '';
$abs_path = defined('ABSPATH') ? str_replace('\\', '/', rtrim(ABSPATH, '/')) : (dirname(__DIR__));
if ($doc_root && $abs_path && strpos($abs_path, $doc_root) === 0) {
    $rel = trim(substr($abs_path, strlen($doc_root)), '/');
    if ($rel !== '') {
        $base_prefix = '/' . $rel;
    }
}
$_SERVER['SCRIPT_NAME'] = ($base_prefix !== '') ? ($base_prefix . '/index.php') : '/index.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['DOCUMENT_URI'] = $_SERVER['SCRIPT_NAME'];

require $index;
return true;
