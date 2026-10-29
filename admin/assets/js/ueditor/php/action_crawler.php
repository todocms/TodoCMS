<?php
/**
 * UEditor 远程图片抓取
 */

/* 上传配置 */
$config = array(
    "pathFormat" => $CONFIG['catcherPathFormat'],
    "maxSize" => $CONFIG['catcherMaxSize'],
    "allowFiles" => $CONFIG['catcherAllowFiles'],
    "oriName" => "remote.png"
);
$fieldName = $CONFIG['catcherFieldName'];

// 全局上传开关检查：当“文件上传功能”关闭时，阻止UEditor的远程抓图
if (!defined('ABSPATH')) {
    // 计算项目根目录，尽力加载配置
    $root_dir = dirname(dirname(dirname(dirname(dirname(dirname(__FILE__))))));
    if (file_exists($root_dir . '/config.php')) {
        define('ABSPATH', $root_dir . '/');
        require_once($root_dir . '/config.php');
    }
}
if (defined('ABSPATH')) {
    @require_once(ABSPATH . 'core/functions/load_functions.php');
    // 运行核心初始化，确保 get_option 等函数与核心类可用
    if (function_exists('cms_init')) {
        cms_init();
    }
}
$enable_upload = null;
if (function_exists('get_option')) {
    $enable_upload = get_option('enable_upload', '1');
} elseif (class_exists('CMS_Option')) {
    $enable_upload = CMS_Option::get('enable_upload', '1');
} else {
    $enable_upload = '1';
}
if (!$enable_upload) {
    result(array('state' => '文件上传功能已禁用', 'list' => array()));
}

// 新增：锚定保存至 uploads 目录并准备根路径
$__base_root = defined('ABSPATH') ? rtrim(ABSPATH, '/\\') : rtrim(realpath(dirname(__FILE__, 5)), '/\\');
$__uploads_root = $__base_root . '/content/uploads';
if (!is_dir($__uploads_root)) { @mkdir($__uploads_root, 0755, true); }

/* 抓取远程图片 */
$list = array();
if (isset($_POST[$fieldName])) {
    $source = $_POST[$fieldName];
} else {
    $source = $_GET[$fieldName];
}

foreach ($source as $imgUrl) {
    $item = array(
        "state" => "",
        "url" => "",
        "size" => "",
        "title" => htmlspecialchars($imgUrl),
        "original" => htmlspecialchars($imgUrl),
        "source" => htmlspecialchars($imgUrl)
    );
    
    // 校验 URL scheme 与 SSRF 防护（仅允许 http/https，拒绝内网/本地地址）
    if (!todo_is_public_url($imgUrl)) {
        $item['state'] = '受限的请求地址：禁止访问私有网络或本地资源。';
        array_push($list, $item);
        continue;
    }
    // 检查远程资源是否可访问
    $heads = @get_headers($imgUrl, 1);
    if (!$heads || !(stristr($heads[0], '200') && stristr($heads[0], 'OK'))) {
        $item['state'] = '链接不可用';
        array_push($list, $item);
        continue;
    }
    // 新增：可选 Content-Type 检查
    $ct = '';
    if (isset($heads['Content-Type'])) {
        $ct = is_array($heads['Content-Type']) ? end($heads['Content-Type']) : $heads['Content-Type'];
    }
    
    // 获取请求头
    $fileInfo = pathinfo($imgUrl);
    $ext = isset($fileInfo['extension']) ? $fileInfo['extension'] : 'jpg';
    if (!in_array('.' . $ext, $config['allowFiles'])) {
        $item['state'] = '不允许的文件格式';
        array_push($list, $item);
        continue;
    }
    // 新增：当响应类型不为 image/* 时阻止（若提供）
    if ($ct && stripos($ct, 'image/') !== 0) {
        $item['state'] = '不允许的文件格式';
        array_push($list, $item);
        continue;
    }
    
    // 抓取图片
    $context = stream_context_create(
        array('http' => array(
            'follow_location' => false // don't follow redirects
        ))
    );
    // 修复：确保开启缓冲获取内容
    ob_start();
    readfile($imgUrl, false, $context);
    $img = ob_get_contents();
    ob_end_clean();
    preg_match("/[^\/]+\.(\w+)$/", $imgUrl, $m);
    
    if (strlen($img) > $config['maxSize']) {
        $item['state'] = '图片大小超出限制';
        array_push($list, $item);
        continue;
    }
    
    // 生成保存文件名（锚定至 uploads/ueditor）
    $rawPath = formatPath($config['pathFormat'], $config['oriName']);
    // 修正：UE配置中的 pathFormat 已包含 '/content/uploads'，这里仅取其后的相对部分，避免重复 content/uploads 片段
    $rawPathRel = ltrim(preg_replace('#^/content/uploads/#', '', str_replace('\\', '/', $rawPath)), '/');
    $relPath = 'ueditor/' . $rawPathRel;
    // 拒绝路径穿越与绝对路径
    if (preg_match('#(^|/)\.\.(/|$)#', $relPath) || preg_match('#^([A-Za-z]:|/)#', $relPath)) {
        $item['state'] = '非法保存路径';
        array_push($list, $item);
        continue;
    }
    $savePath = rtrim($__uploads_root, '/') . '/' . $relPath;
    $dirname = dirname($savePath);
    
    // 创建目录并进行 realpath 前缀校验
    if (!is_dir($dirname) && !mkdir($dirname, 0755, true)) {
        $item['state'] = '目录创建失败';
        array_push($list, $item);
        continue;
    }
    $uploadsReal = realpath($__uploads_root);
    $dirReal = realpath($dirname);
    if ($uploadsReal === false || $dirReal === false || strpos($dirReal, $uploadsReal) !== 0) {
        $item['state'] = '非法保存路径';
        array_push($list, $item);
        continue;
    } else if (!is_writeable($dirname)) {
        $item['state'] = '目录没有写权限';
        array_push($list, $item);
        continue;
    }
    
    // 保存图片
    if (!(file_put_contents($savePath, $img) && file_exists($savePath))) { //移动失败
        $item['state'] = '写入文件失败';
        array_push($list, $item);
        continue;
    } else {
        // 二次校验 MIME 类型（使用本地文件检测）
        $mime = '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) { $mime = finfo_file($fi, $savePath); finfo_close($fi); }
        } elseif (function_exists('mime_content_type')) {
            $mime = mime_content_type($savePath);
        }
        if ($mime && stripos($mime, 'image/') !== 0) {
            @unlink($savePath);
            $item['state'] = '不允许的文件格式';
            array_push($list, $item);
            continue;
        }
        @chmod($savePath, 0644);
        $item['state'] = 'SUCCESS';
        // 返回相对 URL，以 uploads 为根
        $full_url = rtrim(todo_get_site_url(), '/') . '/content/uploads/' . $relPath;
        $item['url'] = (function_exists('todo_media_url_to_relative') ? todo_media_url_to_relative($full_url) : '/content/uploads/' . $relPath);
        $item['size'] = strlen($img);
        array_push($list, $item);
    }
}

/* 返回抓取数据 */
result(array(
    'state'=> count($list) ? 'SUCCESS':'ERROR',
    'list'=> $list
));
?>