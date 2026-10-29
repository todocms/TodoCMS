<?php
/**
 * UEditor PHP 后端控制器
 * 处理 UEditor 的各种后端请求
 */

// 设置错误报告
error_reporting(E_ERROR | E_WARNING);

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 定义一个常量表明这是通过UEditor访问的
define('UEDITOR_ACCESS', true);
// 定义TODO_LOADED常量以允许访问类文件
define('TODO_LOADED', true);
if (!defined('TODO_VERSION')) {
    define('TODO_VERSION', '1.0.0');
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
    
    // 然后加载配置文件
    require_once($root_dir . '/config.php');
}

// 获取配置
$config_path = dirname(__FILE__) . '/config.json';
$CONFIG = json_decode(preg_replace("/\/\*[\s\S]+?\*\//", "", file_get_contents($config_path)), true);

// 获取请求参数
$action = $_REQUEST['action'];

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$is_admin = isset($_SESSION['admin_user_id']) || isset($_SESSION['todo_admin_uid']);
$is_demo = (defined('TODO_DEMO_ADMIN_ENABLED') && TODO_DEMO_ADMIN_ENABLED) && isset($_SESSION['todo_demo_admin']) && (int) $_SESSION['todo_demo_admin'] === 1;

if (!$is_admin && !$is_demo) {
    header("Content-type: application/json; charset=UTF-8");
    echo json_encode(['state' => '请先登录管理员账号'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($is_demo) {
    header("Content-type: application/json; charset=UTF-8");
    echo json_encode(['state' => '演示模式！'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 标记管理后台上下文
if (!defined('IS_ADMIN')) {
    define('IS_ADMIN', true);
}

// 加载核心函数以访问站点配置
if (defined('ABSPATH')) {
    @require_once(ABSPATH . 'core/functions/load_functions.php');
    // 运行核心初始化，以确保 get_option/update_option 等函数与类被定义
    if (function_exists('cms_init')) {
        cms_init();
    }
}

// 初始化站点上下文（确保上传归属正确的站点）
if (function_exists('todo_current_site_id')) {
    todo_current_site_id();
}
// 全局上传开关检查：当“文件上传功能”关闭时，阻止UEditor的上传/抓取动作
$uploadActions = array('uploadimage','simpleupload','uploadscrawl','uploadvideo','uploadfile','catchimage');
// 更稳健的开关获取：优先使用 get_option；若未定义则回退到 CMS_Option::get；最终回退到启用
$enable_upload = null;
if (function_exists('get_option')) {
    $enable_upload = get_option('enable_upload', '1');
} elseif (class_exists('CMS_Option')) {
    $enable_upload = CMS_Option::get('enable_upload', '1');
} else {
    $enable_upload = '1';
}
if (!$enable_upload && in_array($action, $uploadActions)) {
    result(array('state' => '文件上传功能已禁用'));
}

switch ($action) {
    case 'config':
        result($CONFIG);
        break;
        
    /* 上传图片 */
    case 'uploadimage':
        include("action_upload.php");
        break;
        
    /* 上传涂鸦 */
    case 'uploadscrawl':
        include("action_upload.php");
        break;
        
    /* 上传视频 */
    case 'uploadvideo':
        include("action_upload.php");
        break;
        
    /* 上传文件 */
    case 'uploadfile':
        include("action_upload.php");
        break;
        
    /* 列出图片 */
    case 'listimage':
        include("action_list.php");
        break;
        
    /* 列出文件 */
    case 'listfile':
        include("action_list.php");
        break;
        
    /* 抓取远程文件 */
    case 'catchimage':
        include("action_crawler.php");
        break;
        
    default:
        result(array(
            'state'=> '请求地址出错'
        ));
        break;
}

/**
 * 输出json字符串
 * @param array $array
 */
function result($array) {
    header("Content-type: application/json; charset=UTF-8");
    echo json_encode($array);
    exit;
}

/**
 * 创建目录
 * @param string $path
 * @return bool
 */
function createDir($path) {
    if (!file_exists($path)) {
        return mkdir($path, 0755, true);
    }
    return true;
}

/**
 * 获取文件扩展名
 * @param string $filename
 * @return string
 */
function getFileExt($filename) {
    return strtolower(strrchr($filename, '.'));
}

/**
 * 格式化路径
 * @param string $format
 * @param string $filename
 * @return string
 */
function formatPath($format, $filename = '') {
    $t = time();
    $d = explode('-', date("Y-y-m-d-H-i-s"));
    $format = str_replace("{yyyy}", $d[0], $format);
    $format = str_replace("{yy}", $d[1], $format);
    $format = str_replace("{mm}", $d[2], $format);
    $format = str_replace("{dd}", $d[3], $format);
    $format = str_replace("{hh}", $d[4], $format);
    $format = str_replace("{ii}", $d[5], $format);
    $format = str_replace("{ss}", $d[6], $format);
    $format = str_replace("{time}", $t, $format);
    
    if (preg_match("/\{rand:(\d+)\}/", $format, $matches)) {
        $format = preg_replace("/\{rand:\d+\}/", rand_string($matches[1]), $format);
    }
    
    if ($filename) {
        $ext = getFileExt($filename);
        $format = str_replace("{filename}", preg_replace("/\.[^.]+$/", "", $filename), $format);
        $format .= $ext;
    }
    
    return $format;
}

/**
 * 生成随机字符串
 * @param int $length
 * @return string
 */
function rand_string($length) {
    $chars = "abcdefghijklmnopqrstuvwxyz0123456789";
    $str = "";
    for ($i = 0; $i < $length; $i++) {
        $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
    }
    return $str;
}
?>