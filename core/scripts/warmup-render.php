<?php
/**
 * TodoCMS 自定义URL缓存预热渲染脚本（CLI 子进程）
 *
 * 在独立进程内模拟一次前台 GET 请求（游客身份 + X-TODO-WARMUP 头），
 * 走真实前台路由渲染页面并写入缓存。不发起任何 HTTP 连接，
 * 因此不依赖 Web 服务器空闲 worker，速度即页面渲染耗时。
 *
 * 用法: php warmup-render.php <base64(json{"url":"http://..."})>
 * 结果通过 STDERR 标记返回：
 *   __WARMUP_RENDER_COMPLETE__ len=<输出字节数>
 *   __WARMUP_RENDER_FATAL__ <错误信息>
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

$payload = isset($argv[1]) ? json_decode(base64_decode($argv[1]), true) : null;
$url = (is_array($payload) && !empty($payload['url'])) ? (string) $payload['url'] : '';
if ($url === '') {
    fwrite(STDERR, "__WARMUP_RENDER_FATAL__ missing url\n");
    exit(2);
}

$root = dirname(dirname(__DIR__));
$parts = parse_url($url);
if (!$parts || empty($parts['host'])) {
    fwrite(STDERR, "__WARMUP_RENDER_FATAL__ invalid url: {$url}\n");
    exit(2);
}

$scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'http';
$host = $parts['host'];
$default_port = ($scheme === 'https') ? 443 : 80;
$port = isset($parts['port']) ? (int) $parts['port'] : $default_port;
// 与浏览器行为一致：默认端口不出现在 Host 头中（影响页面缓存键）
$host_header = $host . (($port && $port !== $default_port) ? ':' . $port : '');
$path = (isset($parts['path']) && $parts['path'] !== '') ? $parts['path'] : '/';
$query = isset($parts['query']) ? $parts['query'] : '';

$device = (is_array($payload) && !empty($payload['device'])) ? (string) $payload['device'] : 'pc';

// 构造与浏览器访问一致的请求环境（游客：无 Cookie、无会话）
$_GET = array();
$_POST = array();
$_REQUEST = array();
$_COOKIE = array();
if ($query !== '') {
    parse_str($query, $_GET);
    $_REQUEST = $_GET;
}

if ($device === 'mobile') {
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1 TodoCMS Cache Warmup Bot/1.0';
    $_GET['device'] = 'mobile';
    $_REQUEST['device'] = 'mobile';
} else {
    $_SERVER['HTTP_USER_AGENT'] = 'TodoCMS Cache Warmup Bot/1.0';
}

// 计算真实 DOCUMENT_ROOT 与子目录下的 SCRIPT_NAME
// 优先采用 Web 传入的 DOCUMENT_ROOT；若在 CLI 独立子进程中，根据预热的目标 URL 路径智能判断是主目录还是子目录部署
$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '' ? str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/')) : '';
if ($doc_root && strpos(str_replace('\\', '/', $root), $doc_root) === 0) {
    $sub_dir = rtrim(substr(str_replace('\\', '/', $root), strlen($doc_root)), '/');
} else {
    // 智能推导：检查目标 URL 路径是否以当前项目所在目录名 (如 /todocms) 开头
    $folder_name = basename($root);
    $normalized_path = '/' . ltrim($path, '/');
    if ($folder_name !== '' && (strpos($normalized_path, '/' . $folder_name . '/') === 0 || $normalized_path === '/' . $folder_name)) {
        // 子目录部署
        $doc_root = str_replace('\\', '/', dirname($root));
        $sub_dir = '/' . $folder_name;
    } else {
        // 主目录/根站点部署
        $doc_root = str_replace('\\', '/', $root);
        $sub_dir = '';
    }
}
$script_name = ($sub_dir !== '' ? $sub_dir : '') . '/index.php';

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = $path . ($query !== '' ? '?' . $query : '');
$_SERVER['QUERY_STRING'] = $query;
$_SERVER['HTTP_HOST'] = $host_header;
$_SERVER['SERVER_NAME'] = $host;
$_SERVER['SERVER_PORT'] = (string) $port;
$_SERVER['DOCUMENT_ROOT'] = $doc_root;
$_SERVER['SCRIPT_NAME'] = $script_name;
$_SERVER['PHP_SELF'] = $script_name;
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['HTTP_X_TODO_WARMUP'] = '1';
$_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml';
if ($scheme === 'https') {
    $_SERVER['HTTPS'] = 'on';
} else {
    unset($_SERVER['HTTPS']);
}
unset($_SERVER['HTTP_COOKIE']);

register_shutdown_function(function () {
    $err = error_get_last();
    $is_fatal = $err && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true);
    // 统计并丢弃全部输出缓冲（HTML 不需要回传，缓存已在渲染过程中写入磁盘）
    $len = 0;
    while (ob_get_level() > 0) {
        $len += (int) ob_get_length();
        @ob_end_clean();
    }
    if ($is_fatal) {
        fwrite(STDERR, '__WARMUP_RENDER_FATAL__ ' . $err['message'] . ' @' . $err['file'] . ':' . $err['line'] . "\n");
    } else {
        fwrite(STDERR, '__WARMUP_RENDER_COMPLETE__ len=' . $len . "\n");
    }
});

ob_start();
chdir($root);
require $root . '/index.php';
