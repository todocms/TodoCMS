<?php
/**
 * 获取视图模版AJAX处理器
 * 
 * 根据选择的分类获取对应数据模型的视图模版文件
 * 
 * @package TodoCMS
 * @subpackage Admin\Ajax
 */

// 防止直接访问（返回统一JSON而不是纯文本，避免前端解析错误）

// 定义AJAX常量（如果尚未定义）
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

// 检查AJAX请求（宽松检查：允许DOING_AJAX常量或X-Requested-With头）
$is_ajax_request = (defined('DOING_AJAX') && DOING_AJAX) ||
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if (!$is_ajax_request) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

// 启动会话，确保 admin_logged_in 与权限检查可用
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 确保环境加载
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}
if (file_exists(ABSPATH . 'core/bootstrap.php')) {
    require_once ABSPATH . 'core/bootstrap.php';
}

// CSRF 校验：与编辑页保持一致使用 edit_post_nonce
if (!defined('IS_ADMIN')) {
    define('IS_ADMIN', true);
}

// 需要使用 admin_logged_in、current_user_can 等后台函数
if (!function_exists('admin_logged_in') || !function_exists('current_user_can')) {
    if (file_exists(ABSPATH . 'admin/includes/todo-functions.php')) {
        require_once ABSPATH . 'admin/includes/todo-functions.php';
    }
    if (!function_exists('current_user_can') && file_exists(ABSPATH . 'core/functions/auth.php')) {
        require_once ABSPATH . 'core/functions/auth.php';
    }
}
// 收集调试信息
$debug_info = array();
$debug_info['post_params'] = array_keys($_POST);
$debug_info['get_params'] = array_keys($_GET);
$debug_info['has_todononce_post'] = isset($_POST['_todononce']) ? 'yes' : 'no';
$debug_info['has_todononce_get'] = isset($_GET['_todononce']) ? 'yes' : 'no';
$debug_info['todononce_post_preview'] = isset($_POST['_todononce']) ? substr($_POST['_todononce'], 0, 10) . '...' : 'none';
$debug_info['has_x_csrf_token'] = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? 'yes' : 'no';
$debug_info['has_x_todo_nonce'] = isset($_SERVER['HTTP_X_TODO_NONCE']) ? 'yes' : 'no';
$debug_info['session_id'] = session_id() ? substr(session_id(), 0, 8) . '...' : 'none';
$debug_info['session_started'] = session_status() === PHP_SESSION_ACTIVE ? 'yes' : 'no';
$debug_info['has_csrf_tokens_session'] = isset($_SESSION['todo_csrf_tokens']) ? 'yes' : 'no';
if (isset($_SESSION['todo_csrf_tokens']) && is_array($_SESSION['todo_csrf_tokens'])) {
    $debug_info['csrf_tokens_actions'] = array_keys($_SESSION['todo_csrf_tokens']);
}

// 分别测试每个CSRF验证（根据referer决定优先级）
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
$is_post_new = strpos($referer, '/admin/index.php?page=post-new') !== false;
$is_post_edit = strpos($referer, '/admin/index.php?page=post-edit') !== false;

// 根据页面类型调整验证优先级
if ($is_post_new) {
    // post-new 页面优先验证 create_post_nonce
    $csrf_create_post = Todo_Security_Manager::verify_request_csrf('create_post_nonce', '_todononce');
    $csrf_edit_post = Todo_Security_Manager::verify_request_csrf('edit_post_nonce', '_todononce');
    $csrf_category_action = Todo_Security_Manager::verify_request_csrf('category_action', '_todononce');
} else {
    // 其他页面保持原顺序
    $csrf_edit_post = Todo_Security_Manager::verify_request_csrf('edit_post_nonce', '_todononce');
    $csrf_create_post = Todo_Security_Manager::verify_request_csrf('create_post_nonce', '_todononce');
    $csrf_category_action = Todo_Security_Manager::verify_request_csrf('category_action', '_todononce');
}

$debug_info['csrf_edit_post_result'] = $csrf_edit_post ? 'passed' : 'failed';
$debug_info['csrf_create_post_result'] = $csrf_create_post ? 'passed' : 'failed';
$debug_info['csrf_category_action_result'] = $csrf_category_action ? 'passed' : 'failed';

$csrf_ok = $csrf_edit_post || $csrf_create_post || $csrf_category_action;

if (!$csrf_ok) {
    $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
    $from_post_pages = (strpos($referer, '/admin/index.php?page=post-new') !== false)
        || (strpos($referer, '/admin/index.php?page=post-edit') !== false)
        || (strpos($referer, '/admin/index.php?page=categories') !== false);
    
    // 详细的CSRF失败调试信息
    $debug_log = "get-view-templates.php: CSRF failed. ";
    $debug_log .= "Referer: $referer, ";
    $debug_log .= "From post pages: " . ($from_post_pages ? 'yes' : 'no') . ", ";
    $debug_log .= "Logged in: " . (admin_logged_in() ? 'yes' : 'no') . ", ";
    $debug_log .= "POST params: " . implode(', ', $debug_info['post_params']) . ", ";
    $debug_log .= "GET params: " . implode(', ', $debug_info['get_params']) . ", ";
    $debug_log .= "Has _todononce POST: " . $debug_info['has_todononce_post'] . ", ";
    $debug_log .= "Has _todononce GET: " . $debug_info['has_todononce_get'] . ", ";
    $debug_log .= "_todononce preview: " . $debug_info['todononce_post_preview'] . ", ";
    $debug_log .= "Has X-CSRF-Token header: " . $debug_info['has_x_csrf_token'] . ", ";
    $debug_log .= "Has X-Todo-Nonce header: " . $debug_info['has_x_todo_nonce'] . ", ";
    $debug_log .= "Session ID: " . $debug_info['session_id'] . ", ";
    $debug_log .= "Session started: " . $debug_info['session_started'] . ", ";
    $debug_log .= "Has CSRF tokens in session: " . $debug_info['has_csrf_tokens_session'];
    if (!empty($debug_info['csrf_tokens_actions'])) {
        $debug_log .= ", CSRF tokens actions: " . implode(', ', $debug_info['csrf_tokens_actions']);
    }
    $debug_log .= ", Edit post nonce: " . $debug_info['csrf_edit_post_result'];
    $debug_log .= ", Create post nonce: " . $debug_info['csrf_create_post_result'];
    $debug_log .= ", Category action: " . $debug_info['csrf_category_action_result'];
    
    error_log($debug_log);
    
    if (!admin_logged_in() || !$from_post_pages) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'CSRF验证失败']);
        exit;
    }
}

// 检查管理员登录与权限
if (!admin_logged_in() || (!current_user_can('edit_posts') && !current_user_can('publish_posts'))) {
    // Debug Permission failure
    error_log("get-view-templates.php: Permission denied. Logged in: " . (admin_logged_in() ? 'yes' : 'no') . ", Can edit: " . (current_user_can('edit_posts') ? 'yes' : 'no'));
    
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

// 加载必要的类与模板函数（兼容 CORE_DIR 未定义的情况）
$CORE_PATH = defined('CORE_DIR') ? CORE_DIR : (defined('ABSPATH') ? ABSPATH . 'core' : __DIR__ . '/../../core');
// 确保模板函数可用（提供 get_template_directory 等）
if (file_exists($CORE_PATH . '/functions/template.php')) {
    require_once $CORE_PATH . '/functions/template.php';
}

try {
    // 获取分类ID或模型ID
    $category_ids_raw = isset($_POST['category_ids']) ? $_POST['category_ids'] : '';
    $model_id = isset($_POST['model_id']) ? intval($_POST['model_id']) : 0;

    // 处理前端发送的数组数据（FormData会将数组转为逗号分隔的字符串）
    if (is_string($category_ids_raw)) {
        $category_ids = !empty($category_ids_raw) ? array_map('intval', explode(',', $category_ids_raw)) : [];
    } else if (is_array($category_ids_raw)) {
        $category_ids = array_map('intval', $category_ids_raw);
    } else {
        $category_ids = [];
    }

    // 解析模型标识：优先根据分类获取，其次根据模型ID获取
    $category_model = '';
    if (!empty($category_ids)) {
        $primary_category = $category_ids[0];
        $category_model = Todo_Model_Manager::get_category_model($primary_category);
    } elseif ($model_id > 0) {
        // 通过模型ID安全获取模型标识（统一走模型管理器，避免直接操作DB）
        $category_model = Todo_Model_Manager::get_model_key_by_id($model_id) ?: '';
    }

    if (empty($category_model)) {
        echo json_encode([
            'success' => true,
            'templates' => []
        ]);
        exit;
    }

    if (!$category_model) {
        echo json_encode([
            'success' => true,
            'templates' => []
        ]);
        exit;
    }

    // 在所有选中分类中查找已设置的默认内容模板（完整文件名），优先匹配当前模型
    $category_default_file = '';
    foreach ($category_ids as $cid) {
        // 兼容两个元字段键：优先 view_template（当前实现），其次 cat_view_template（历史/兼容）
        $category_default_view = get_term_meta($cid, 'view_template', true);
        if (empty($category_default_view)) {
            $category_default_view = get_term_meta($cid, 'cat_view_template', true);
        }
        if (!empty($category_default_view)) {
            $candidate = basename($category_default_view);
            // 仅当文件前缀与当前模型一致时才采用，避免跨模型误选
            if (strpos($candidate, 'view_' . $category_model) === 0) {
                $category_default_file = $candidate;
                break;
            }
        }
    }

    $theme_dir = get_template_directory();
    $templates = [];

    if (is_dir($theme_dir)) {
        // 查询模型的 view_template 配置
        global $tododb;
        $model_view_template = '';
        if ($model_id > 0) {
            $model_view_template = $tododb->get_var(
                "SELECT view_template FROM " . TABLE_PREFIX . "models WHERE model_id = ?",
                [$model_id]
            ) ?: '';
        } elseif (!empty($category_ids)) {
            // 通过分类的 model_key 反查
            $prime_cat_model = Todo_Model_Manager::get_category_model($category_ids[0]);
            if ($prime_cat_model) {
                $model_view_template = $tododb->get_var(
                    "SELECT view_template FROM " . TABLE_PREFIX . "models WHERE model_key = ?",
                    [$prime_cat_model]
                ) ?: '';
            }
        }
        
        $template_files = glob($theme_dir . '/view_' . $category_model . '*.html') ?: [];
        // 如果模型配置了自定义 view_template，按其词干也做 glob 匹配同类模板
        if (!empty($model_view_template)) {
            $stem = pathinfo($model_view_template, PATHINFO_FILENAME); // e.g. view_custom-cat
            $siblings = glob($theme_dir . '/' . $stem . '*.html') ?: [];
            $template_files = array_merge($template_files, $siblings);
        }
        $template_files = array_unique($template_files);

        $default_display_name = '';
        if (!empty($model_view_template) && file_exists($theme_dir . '/' . $model_view_template)) {
            $default_display_name = $model_view_template;
        } else {
            $default_file = $theme_dir . '/view_' . $category_model . '.html';
            $default_display_name = file_exists($default_file) ? basename($default_file) : '';
        }

        foreach ($template_files as $template_file) {
            $display_name = basename($template_file);
            if ($default_display_name && $display_name === $default_display_name) {
                continue;
            }

            $templates[] = [
                'file' => $display_name,
                'display' => $display_name
            ];
        }
    }

    $default_model_value = $default_display_name ? pathinfo($default_display_name, PATHINFO_FILENAME) : 'view_' . $category_model;

    echo json_encode([
        'success' => true,
        'model' => $category_model,
        'default' => $default_model_value,
        'category_default_file' => $category_default_file,
        'templates' => $templates
    ]);

} catch (Exception $e) {
    error_log('获取视图模版失败: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => '获取视图模版失败'
    ]);
}
