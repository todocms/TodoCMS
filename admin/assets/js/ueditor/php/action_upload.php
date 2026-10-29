<?php
/**
 * UEditor 文件上传处理
 */

// 定义一个常量表明这是通过UEditor访问的
if (!defined('UEDITOR_ACCESS')) {
    define('UEDITOR_ACCESS', true);
}

// 引入CMS核心文件
if (!defined('ABSPATH')) {
    // 获取项目根目录 - 从 /admin/assets/js/ueditor/php/ 回到根目录
    // __FILE__ -> php -> ueditor -> js -> assets -> admin -> 根目录 (需要6层dirname)
    $root_dir = dirname(dirname(dirname(dirname(dirname(dirname(__FILE__))))));
    
    // 检查config.php是否存在
    if (!file_exists($root_dir . '/config.php')) {
        die(json_encode(array('state' => '配置文件不存在: ' . $root_dir . '/config.php')));
    }
    
    // 先定义ABSPATH常量
    define('ABSPATH', $root_dir . '/');
}

if (file_exists(ABSPATH . 'core/bootstrap.php')) {
    require_once ABSPATH . 'core/bootstrap.php';
}
if (function_exists('cms_init')) {
    cms_init();
}

// 全局上传开关检查：当“文件上传功能”关闭时，直接拒绝UEditor的上传
$enable_upload = null;
if (function_exists('get_option')) {
    $enable_upload = get_option('enable_upload', '1');
} elseif (class_exists('CMS_Option')) {
    $enable_upload = CMS_Option::get('enable_upload', '1');
} else {
    $enable_upload = '1';
}
if (!$enable_upload) {
    result(array('state' => '文件上传功能已禁用'));
}

$app_max_size = null;
if (class_exists('TodoUploadConfig')) {
    $app_max_size = (int) TodoUploadConfig::get_max_upload_size();
} elseif (function_exists('get_option')) {
    $mb = get_option('max_upload_size', null);
    if ($mb !== null && is_numeric($mb) && (int) $mb > 0) {
        $app_max_size = (int) $mb * 1024 * 1024;
    }
}
if ($app_max_size !== null && $app_max_size <= 0) {
    result(array('state' => '文件上传功能已禁用'));
}

// 获取上传配置
switch ($action) {
    case 'uploadimage':
    case 'simpleupload':
        $config = array(
            "pathFormat" => $CONFIG['imagePathFormat'],
            "maxSize" => $CONFIG['imageMaxSize'],
            "allowFiles" => $CONFIG['imageAllowFiles']
        );
        $fieldName = $CONFIG['imageFieldName'];
        break;
    case 'uploadscrawl':
        $config = array(
            "pathFormat" => $CONFIG['scrawlPathFormat'],
            "maxSize" => $CONFIG['scrawlMaxSize'],
            "allowFiles" => array('.png')
        );
        $fieldName = $CONFIG['scrawlFieldName'];
        $base64 = "base64";
        break;
    case 'uploadvideo':
        $config = array(
            "pathFormat" => $CONFIG['videoPathFormat'],
            "maxSize" => $CONFIG['videoMaxSize'],
            "allowFiles" => $CONFIG['videoAllowFiles']
        );
        $fieldName = $CONFIG['videoFieldName'];
        break;
    case 'uploadfile':
    default:
        $config = array(
            "pathFormat" => $CONFIG['filePathFormat'],
            "maxSize" => $CONFIG['fileMaxSize'],
            "allowFiles" => $CONFIG['fileAllowFiles']
        );
        $fieldName = $CONFIG['fileFieldName'];
        break;
}

if ($app_max_size !== null && isset($config['maxSize'])) {
    $config['maxSize'] = min((int) $config['maxSize'], (int) $app_max_size);
}

/* 生成上传文件的保存路径 */
$path = formatPath($config["pathFormat"]);
// 修正：UE配置中的 pathFormat 已包含 '/content/uploads'，这里仅取其后的相对部分，避免重复生成 content/uploads/content/uploads/... 目录
$pathRel = ltrim(preg_replace('#^/content/uploads/#', '', $path), '/');

/* 上传文件保存目录（强制锚定到 ABSPATH/content/uploads，防止路径穿越与越权写入） */
$uploadsRoot = ABSPATH . 'content/uploads';
$savePath = rtrim($uploadsRoot, '/') . '/' . ltrim(dirname($pathRel), '/');

/* 创建目录失败 */
if (!createDir($savePath)) {
    result(array(
        'state' => '目录创建失败'
    ));
}

/* 遇到涂鸦上传 */
if (isset($base64)) {
    $img = base64_decode($_POST[$fieldName]);
    // 使用修正后的相对路径生成文件名，避免重复 content/uploads 片段
    $filename = rtrim($savePath, '/') . '/' . basename($pathRel);
    // realpath 前缀校验，确保写入到 uploads 目录
    $uploads_real = realpath($uploadsRoot);
    $dest_parent_real = realpath(dirname($filename));
    if ($uploads_real === false || $dest_parent_real === false || strpos($dest_parent_real, $uploads_real) !== 0) {
        result(array('state' => '非法上传路径'));
    }
    // 大小校验
    if (strlen($img) > $config['maxSize']) {
        result(array('state' => '文件大小超出限制'));
    }
    // 写入并二次 MIME 校验
    if (file_put_contents($filename, $img, LOCK_EX)) {
        // 检测 MIME 类型
        $mime = '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) { $mime = finfo_file($fi, $filename); finfo_close($fi); }
        } elseif (function_exists('mime_content_type')) {
            $mime = mime_content_type($filename);
        }
        if (!$mime || stripos($mime, 'image/png') !== 0) {
            @unlink($filename);
            result(array('state' => '不允许的文件格式'));
        }
        @chmod($filename, 0644);
        result(array(
            'state' => 'SUCCESS',
            'url' => $path,
            'title' => basename($path),
            'original' => 'scrawl.png',
            'type' => '.png',
            'size' => strlen($img)
        ));
    } else {
        result(array(
            'state' => '涂鸦上传出错'
        ));
    }
}

/* 上传文件 */
if (!isset($_FILES[$fieldName])) {
    result(array(
        'state' => '上传文件未找到'
    ));
}

$file = $_FILES[$fieldName];

/* 上传错误 */
if ($file['error'] !== UPLOAD_ERR_OK) {
    $errors = array(
        UPLOAD_ERR_INI_SIZE => '文件大小超出 php.ini 限制',
        UPLOAD_ERR_FORM_SIZE => '文件大小超出表单限制',
        UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
        UPLOAD_ERR_NO_FILE => '没有文件被上传',
        UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
        UPLOAD_ERR_CANT_WRITE => '文件写入失败',
        UPLOAD_ERR_EXTENSION => '文件上传被扩展阻止'
    );
    
    result(array(
        'state' => isset($errors[$file['error']]) ? $errors[$file['error']] : '未知上传错误'
    ));
}

/* 无效文件 */
if (!$file['tmp_name'] || !is_uploaded_file($file['tmp_name'])) {
    result(array(
        'state' => '无效的上传文件'
    ));
}

/* 文件大小检查 */
if ($file['size'] > $config['maxSize']) {
    result(array(
        'state' => '文件大小超出限制'
    ));
}

/* 获取文件扩展名 */
$ext = getFileExt($file['name']);

/* 文件类型检查 */
if (!in_array($ext, $config['allowFiles'])) {
    result(array(
        'state' => '不允许的文件类型'
    ));
}

/* 生成文件名 */
$filename = formatPath($config["pathFormat"], $file['name']);
$fullPath = $_SERVER['DOCUMENT_ROOT'] . $filename;

/* 获取分类ID与站点ID（如果提供） */
$category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : (isset($_GET['category_id']) ? intval($_GET['category_id']) : null);
$site_id = isset($_REQUEST['site']) ? intval($_REQUEST['site']) : (isset($_REQUEST['site_id']) ? intval($_REQUEST['site_id']) : null);

$upload_args = array(
    'generate_thumbnails' => false  // 默认只上传原图，不生成缩略图
);
if ($category_id) {
    $upload_args['category_id'] = $category_id;
}
if ($site_id && $site_id > 0) {
    $upload_args['site_id'] = $site_id;
    if (function_exists('todo_set_current_site_id')) {
        todo_set_current_site_id($site_id, false);
    }
}

/* 使用CMS媒体管理系统上传文件 */
$upload_result = CMS_Media::upload($file, $upload_args);

if ($upload_result && is_numeric($upload_result) && $upload_result > 0) {
    // 获取上传文件的信息
    $media_info = CMS_Media::get_attachment($upload_result);
    
    if ($media_info) {
        result(array(
            'state' => 'SUCCESS',
            'url' => $media_info['url'],
            'title' => $media_info['title'],
            'original' => $media_info['filename'],
            'type' => $ext,
            'size' => $file['size'],
            'attachment_id' => $upload_result
        ));
    } else {
        result(array(
            'state' => '获取文件信息失败，附件ID: ' . $upload_result
        ));
    }
} else {
    $error_msg = '文件保存失败';
    if (is_array($upload_result) && isset($upload_result['error'])) {
        $error_msg = $upload_result['error'];
    }
    result(array(
        'state' => $error_msg
    ));
}
?>
