<?php
// 开启输出缓冲
ob_start();
$__todo_post_edit_t0 = microtime(true);

/**
 * 管理后台编辑文章页面
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

// 定义管理员常量
if (!defined('IS_ADMIN')) {
    define('IS_ADMIN', true);
}

// 定义管理页面常量，用于跳过工具文件的权限检查
if (!defined('TODO_ADMIN_PAGE')) {
    define('TODO_ADMIN_PAGE', true);
}

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 定义基本路径常量（如果未定义）
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');

    // 加载Composer autoloader
    if (!class_exists('CMS_DB')) {
    }

}
if (!defined('TODO_ADMIN_PATH')) {
    define('TODO_ADMIN_PATH', ADMIN_DIR);
}

// 加载配置文件
require_once ABSPATH . 'config.php';

// 加载核心引导文件
require_once ABSPATH . 'core/bootstrap.php';

// 确保兼容性函数可用
if (!function_exists('str_starts_with')) {
    require_once ABSPATH . 'core/functions/missing-core-functions.php';
}

// 加载扩展的文章函数
require_once ABSPATH . 'core/functions/post-functions-extended.php';

// 启动会话
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 检查是否是AJAX请求，如果是则立即清理输出缓冲和设置JSON头部
if (isset($_POST['action']) && in_array($_POST['action'], ['auto_save_draft', 'clear_auto_draft', 'save_enabled_languages'])) {
    // 清理任何之前的输出缓冲区
    while (ob_get_level()) {
        ob_end_clean();
    }
    // 重新开始一个干净的输出缓冲
    ob_start();
    header('Content-Type: application/json');
}

require_once CORE_DIR . '/functions/auth.php';
require_once CORE_DIR . '/functions/core.php';
require_once CORE_DIR . '/functions/core-system-functions.php';
require_once CORE_DIR . '/functions/content.php';
require_once CORE_DIR . '/functions/security.php';
require_once CORE_DIR . '/functions/formatting.php';
require_once CORE_DIR . '/functions/media.php';

require_once ADMIN_DIR . '/includes/functions.php';
require_once ADMIN_DIR . '/includes/todo-functions.php';

if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::add_metric('post_edit_bootstrap', 'post-edit bootstrap + requires', (microtime(true) - $__todo_post_edit_t0) * 1000);
}

// 获取文章ID（兼容历史参数：?post=123）
$post_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['post']) ? intval($_GET['post']) : (isset($_POST['post_id']) ? intval($_POST['post_id']) : 0));
if (!$post_id) {
    todo_die('无效的文章ID');
}

// 检查权限（并尽早处理页面内AJAX动作，避免加载完整编辑页面逻辑）
$__todo_post_edit_action = isset($_POST['action']) ? (string) $_POST['action'] : '';
$__todo_is_post_edit_inline_ajax = in_array($__todo_post_edit_action, ['auto_save_draft', 'clear_auto_draft', 'save_enabled_languages'], true);

if (!is_user_logged_in() || !current_user_can('edit_post', $post_id)) {
    if ($__todo_is_post_edit_inline_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '用户未登录或权限不足']);
        exit;
    }
    $login_target = function_exists('todo_admin_login_proxy_url') ? todo_admin_login_proxy_url() : todo_admin_url('login.php');
    todo_redirect($login_target);
    exit;
}

if ($__todo_post_edit_action === 'auto_save_draft') {
    $title = sanitize_text_field($_POST['post_title']);
    $content = isset($_POST['post_content']) ? todo_esc_html($_POST['post_content']) : '';

    $user_id = get_current_user_id();
    $auto_draft_key = 'auto_draft_' . $user_id . '_' . $post_id;

    if (!isset($_SESSION)) {
        session_start();
    }

    $_SESSION[$auto_draft_key] = [
        'title' => $title,
        'content' => $content,
        'saved_at' => time()
    ];

    if (ob_get_level()) {
        ob_end_clean();
    }

    $timezone = get_option('timezone_string', 'Asia/Shanghai');
    if (!empty($timezone)) {
        @date_default_timezone_set($timezone);
    }

    echo json_encode([
        'success' => true,
        'message' => '自动保存成功',
        'saved_at' => date('H:i')
    ]);

    exit;
}

if ($__todo_post_edit_action === 'clear_auto_draft') {
    if (!class_exists('Todo_Security_Manager')) {
        $security_manager_path = ABSPATH . 'core/classes/class-security-manager.php';
        if (file_exists($security_manager_path)) {
            require_once $security_manager_path;
            Todo_Security_Manager::init();
        }
    }

    if (!Todo_Security_Manager::verify_request_csrf('edit_post_nonce', '_todononce')) {
        if (ob_get_level()) {
            ob_end_clean();
        }

        echo json_encode(['success' => false, 'message' => '安全验证失败']);
        exit;
    }

    $user_id = get_current_user_id();
    $auto_draft_key = 'auto_draft_' . $user_id . '_' . $post_id;

    if (!isset($_SESSION)) {
        session_start();
    }

    unset($_SESSION[$auto_draft_key]);

    if (ob_get_level()) {
        ob_end_clean();
    }

    echo json_encode([
        'success' => true,
        'message' => '草稿已清除'
    ]);

    exit;
}

if ($__todo_post_edit_action === 'save_enabled_languages') {
    if (!class_exists('Todo_Security_Manager')) {
        $security_manager_path = ABSPATH . 'core/classes/class-security-manager.php';
        if (file_exists($security_manager_path)) {
            require_once $security_manager_path;
            Todo_Security_Manager::init();
        }
    }

    if (!Todo_Security_Manager::verify_request_csrf('edit_post_nonce', '_todononce')) {
        if (ob_get_level()) {
            ob_end_clean();
        }
        echo json_encode(['success' => false, 'message' => '安全验证失败']);
        exit;
    }

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id) {
        echo json_encode(['success' => false, 'message' => '无效的文章ID']);
        exit;
    }

    if (!current_user_can('edit_post', $post_id)) {
        echo json_encode(['success' => false, 'message' => '无权编辑此文章']);
        exit;
    }

    $enabled_langs = [];
    if (isset($_POST['enabled_languages']) && is_array($_POST['enabled_languages'])) {
        $enabled_langs = array_map('sanitize_text_field', $_POST['enabled_languages']);
    }

    $json_val = json_encode($enabled_langs);

    update_post_meta($post_id, '_enabled_languages', $json_val);

    if (class_exists('Todo_Unified_Cache_Manager')) {
        Todo_Unified_Cache_Manager::clean_post_cache($post_id);
    }

    if (ob_get_level()) {
        ob_end_clean();
    }

    echo json_encode([
        'success' => true,
        'message' => '语言设置已保存',
        'enabled' => $enabled_langs
    ]);
    exit;
}

require_once ADMIN_DIR . '/includes/file.php';
require_once ADMIN_DIR . '/components/featured-images-component.php';
require_once ADMIN_DIR . '/components/model-fields-component.php';
require_once ADMIN_DIR . '/components/hierarchical-categories-selector.php';
require_once ADMIN_DIR . '/components/suggested-tags-component.php';

$__todo_post_edit_t_models = microtime(true);
Todo_Model_Manager::init();
$model_fields_manager = new Todo_Model_Fields_Manager();
if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::add_metric('post_edit_models', 'init model managers', (microtime(true) - $__todo_post_edit_t_models) * 1000);
}

// 获取文章数据
$__todo_post_edit_t_post = microtime(true);
$post = get_post($post_id);
if (!$post) {
    todo_die('文章不存在');
}
if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::add_metric('post_edit_post', 'load post', (microtime(true) - $__todo_post_edit_t_post) * 1000);
}

$views_boost = isset($_POST['views_boost'])
    ? max(0, intval($_POST['views_boost']))
    : (int) get_post_meta($post_id, '_views_boost', true);

// 获取文章分类
$__todo_post_edit_t_terms = microtime(true);
$post_categories = todo_get_post_categories($post_id);
$selected_categories = array_map(function ($cat) {
    return $cat->term_id;
}, $post_categories);
if (empty($selected_categories) && isset($post->post_type) && $post->post_type === 'guestbook') {
    $cid = intval($post->category_id ?? 0);
    if ($cid > 0) {
        $selected_categories = [$cid];
    }
}

// 获取文章标签
$post_tags = todo_get_post_tags($post_id);
$post_tags = implode(', ', array_filter(array_map(function ($tag) {
    $raw_name = is_object($tag) ? ($tag->name ?? ($tag->tag_title ?? '')) : (is_array($tag) ? ($tag['name'] ?? ($tag['tag_title'] ?? '')) : '');
    if (!is_string($raw_name) || $raw_name === '') {
        return '';
    }
    $clean_name = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($raw_name) : $raw_name;
    $clean_name = trim(preg_replace('/\[[a-z]{2}(?:-[a-z]{2})?\|.*?\]/is', '', $clean_name));
    $clean_name = trim(preg_replace('/\[:[a-z]{2}(?:-[a-z]{2})?\].*?\[:\]/is', '', $clean_name));
    return $clean_name ?: $raw_name;
}, (array)$post_tags)));
if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::add_metric('post_edit_terms', 'load categories + tags', (microtime(true) - $__todo_post_edit_t_terms) * 1000);
}

// 获取文章字段值 - 使用统一的post-fields-manager接口
$field_values = array();

// 使用统一的字段管理接口获取所有字段值
$__todo_post_edit_t_fields = microtime(true);
$post_fields_manager = Todo_Post_Fields_Manager::get_instance();
$field_values = $post_fields_manager->get_custom_fields($post_id, null, 'post');
if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::add_metric('post_edit_fields', 'load custom fields', (microtime(true) - $__todo_post_edit_t_fields) * 1000);
}

if (isset($post->post_type) && $post->post_type !== '' && class_exists('Todo_Model_Manager')) {
    $pt = (string) $post->post_type;
    if ($model_fields_manager->is_supported_model($pt)) {
        $model_class_name = 'CMS_Post_Type_' . ucfirst($pt);
        if (class_exists($model_class_name) && method_exists($model_class_name, 'get_fixed_field_values')) {
            $fixed_values = $model_class_name::get_fixed_field_values($post_id);
            if (is_array($fixed_values) && !empty($fixed_values)) {
                $field_values = array_merge($field_values, $fixed_values);
            }
        }
    }
}

if (!empty($selected_categories)) {
    foreach ($selected_categories as $category_id) {
        $category_model = Todo_Model_Manager::get_category_model($category_id);
        if ($category_model === 'product' && class_exists('CMS_Post_Type_Product') && method_exists('CMS_Post_Type_Product', 'get_fixed_field_values')) {
            $product_fixed_values = CMS_Post_Type_Product::get_fixed_field_values($post_id);
            if (is_array($product_fixed_values) && !empty($product_fixed_values)) {
                $field_values = array_merge($field_values, $product_fixed_values);
            }
            break;
        }
    }
}

// 如果统一接口没有返回数据，回退到传统方法
if (empty($field_values)) {
    // 首先尝试加载article模型的字段（默认模型）
    if ($model_fields_manager->is_supported_model('article')) {
        $article_field_values = $model_fields_manager->get_model_fields('article', $post_id);
        $field_values = array_merge($field_values, $article_field_values);
    }

    // 然后根据分类加载其他模型字段
    if (!empty($selected_categories)) {
        foreach ($selected_categories as $category_id) {
            $category_model = Todo_Model_Manager::get_category_model($category_id);
            if ($category_model && $category_model !== 'article' && $model_fields_manager->is_supported_model($category_model)) {
                $model_field_values = $model_fields_manager->get_model_fields($category_model, $post_id);
                $field_values = array_merge($field_values, $model_field_values);
            }
        }
    }
}

// 将模型字段值映射为前端字段名格式（添加 model_field_ 前缀）
// 这样前端 restoreFieldValues 函数可以正确恢复字段值
$mapped_field_values = array();
foreach ($field_values as $key => $value) {
    // 如果键名已经是 model_field_ 开头，直接使用
    if (str_starts_with($key, 'model_field_')) {
        $mapped_field_values[$key] = $value;
    } else {
        // 否则添加 model_field_ 前缀
        $mapped_field_values['model_field_' . $key] = $value;
        // 同时保留原键名，以兼容某些特殊情况
        $mapped_field_values[$key] = $value;
    }
}
$field_values = $mapped_field_values;

// 获取传统字段值作为补充
if (function_exists('get_post_model_fields_values')) {
    $legacy_field_values = get_post_model_fields_values($post_id, $selected_categories);
    $field_values = array_merge($legacy_field_values, $field_values);
}

function todo_parse_enabled_languages_value($raw)
{
    if ($raw === '' || $raw === false || $raw === null) {
        return null;
    }
    if (is_array($raw)) {
        return $raw;
    }
    if (!is_string($raw)) {
        $raw = (string) $raw;
    }
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    if (is_string($decoded)) {
        $decoded2 = json_decode($decoded, true);
        if (is_array($decoded2)) {
            return $decoded2;
        }
    }
    return null;
}

$enabled_languages_meta_val = get_post_meta($post_id, '_enabled_languages', true);
$enabled_languages = todo_parse_enabled_languages_value($enabled_languages_meta_val);

if ($enabled_languages === null && isset($post_fields_manager)) {
    $field_data = $post_fields_manager->get_custom_fields($post_id, '_enabled_languages');
    if (!empty($field_data) && isset($field_data['_enabled_languages']) && $field_data['_enabled_languages'] !== '') {
        $enabled_languages_meta_val = $field_data['_enabled_languages'];
        $enabled_languages = todo_parse_enabled_languages_value($enabled_languages_meta_val);
    }
}

if ($enabled_languages === null) {
    // 修复：默认不再开启所有语言，避免在未显式翻译时自动在所有语言中可见
    $default_lang = function_exists('get_default_language') ? get_default_language() : 'zh-CN';
    $enabled_languages = array($default_lang);
}

$enabled_languages = array_values(array_unique(array_filter(array_map(function ($loc) {
    if (!is_string($loc)) {
        $loc = (string) $loc;
    }
    $loc = str_replace('_', '-', $loc);
    if (function_exists('canonical_locale')) {
        $loc = canonical_locale($loc);
    } else {
        $parts = explode('-', $loc, 2);
        if (count($parts) === 1) {
            $loc = strtolower($parts[0]);
        } else {
            $loc = strtolower($parts[0]) . '-' . strtoupper($parts[1]);
        }
    }
    return $loc;
}, $enabled_languages))));

// 获取层级排列的语言列表
$hierarchical_languages = array();
if (class_exists('LanguageRegistry')) {
    $hierarchical_languages = LanguageRegistry::get_instance()->get_languages_hierarchical(true);
} else {
    $hierarchical_languages = get_available_languages();
}

/**
 * 格式化上传路径（UEditor风格）
 * @param string $format
 * @param string $filename
 * @return string
 */
function format_upload_path($format, $filename = '')
{
    $t = time();
    $d = explode('-', date("Y-y-m-d-H-i-s") ?? '');
    $format = str_replace("{yyyy}", $d[0], $format);
    $format = str_replace("{yy}", $d[1], $format);
    $format = str_replace("{mm}", $d[2], $format);
    $format = str_replace("{dd}", $d[3], $format);
    $format = str_replace("{hh}", $d[4], $format);
    $format = str_replace("{ii}", $d[5], $format);
    $format = str_replace("{ss}", $d[6], $format);
    $format = str_replace("{time}", $t, $format);

    if (preg_match("/\{rand:(\d+)\}/", $format, $matches)) {
        $format = preg_replace("/\{rand:\d+\}/", generate_random_string($matches[1]), $format);
    }

    if ($filename) {
        $ext = strtolower(strrchr($filename, '.'));
        $format = str_replace("{filename}", preg_replace("/\.[^.]+$/", "", $filename), $format);
        $format .= $ext;
    }

    return $format;
}

// 获取自动保存的草稿
$auto_draft_data = null;
if (!isset($_SESSION)) {
    session_start();
}
$user_id = get_current_user_id();
$auto_draft_key = 'auto_draft_' . $user_id . '_' . $post_id;
if (isset($_SESSION[$auto_draft_key])) {
    $auto_draft_data = $_SESSION[$auto_draft_key];
}
if ($auto_draft_data && trim((string) ($auto_draft_data['title'] ?? '')) === '' && trim((string) ($auto_draft_data['content'] ?? '')) === '') {
    unset($_SESSION[$auto_draft_key]);
    $auto_draft_data = null;
}

// 处理选择的分类参数，用于动态加载模型字段
// 只有在没有从数据库获取到分类时才使用GET参数
if (empty($selected_categories) && isset($_GET['selected_categories']) && !empty($_GET['selected_categories'])) {
    $selected_categories = explode(',', $_GET['selected_categories'] ?? '');
    $selected_categories = array_map('intval', $selected_categories);
    $selected_categories = array_filter($selected_categories);
}

$message = '';
$error = '';

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_post') {
    // 获取表单数据
    $post_title = isset($_POST['post_title']) ? trim($_POST['post_title']) : '';
    // 将HTML标签转义后保存，确保数据库中为安全的实体表示（保持数据库存实体，前端渲染时再解码）
    $post_content = isset($_POST['post_content']) ? $_POST['post_content'] : '';
    // 强制转换为相对路径
    if (function_exists('todo_convert_content_urls_to_relative')) {
        $post_content = todo_convert_content_urls_to_relative($post_content);
    }
    // 将HTML标签转义后保存，确保数据库中为安全的实体表示
    $post_content = todo_esc_html($post_content);
    $post_excerpt = isset($_POST['post_excerpt']) ? $_POST['post_excerpt'] : '';
    $post_status = isset($_POST['post_status']) ? $_POST['post_status'] : 'draft';
    $post_categories = isset($_POST['post_category']) ? (array) $_POST['post_category'] : array();
    $post_tags = isset($_POST['post_tags']) ? $_POST['post_tags'] : '';
    $post_author = isset($_POST['post_author']) ? intval($_POST['post_author']) : get_current_user_id();

    $seo_title = isset($_POST['seo_title']) ? trim($_POST['seo_title']) : (isset($_POST['model_field_seo_title']) ? trim($_POST['model_field_seo_title']) : '');
    $seo_keywords = isset($_POST['seo_keywords']) ? trim($_POST['seo_keywords']) : (isset($_POST['model_field_seo_keywords']) ? trim($_POST['model_field_seo_keywords']) : '');
    $seo_description = isset($_POST['seo_description']) ? trim($_POST['seo_description']) : (isset($_POST['model_field_seo_description']) ? trim($_POST['model_field_seo_description']) : '');
    $seo_auto_update = isset($_POST['seo_auto_update']) ? 1 : 0;

    // 获取高级设置字段数据
    $comment_status = isset($_POST['comment_status']) ? trim($_POST['comment_status']) : 'open';
    $custom_url = isset($_POST['custom_url']) ? trim($_POST['custom_url']) : '';

    // 获取基础字段数据
    $view_count = isset($post->view_count) ? (int) $post->view_count : 0;
    $views_boost = isset($_POST['views_boost']) ? max(0, intval($_POST['views_boost'])) : (int) get_post_meta($post_id, '_views_boost', true);
    $read_permission = isset($_POST['read_permission']) ? trim($_POST['read_permission']) : 'public';
    $view_template = isset($_POST['view_template']) ? trim($_POST['view_template']) : '';

    // 验证数据
    $errors = array();

    if (empty($post_title)) {
        $errors[] = '请输入文章标题。';
    }

    if (empty($post_content)) {
        $errors[] = '请输入文章内容。';
    }

    // 可见性与密码校验（前端选择的post_visibility与post_password）
    $post_visibility = isset($_POST['post_visibility']) ? trim($_POST['post_visibility']) : 'public';
    if (!in_array($post_visibility, ['public', 'readable', 'password', 'private'], true)) {
        $post_visibility = 'public';
    }
    $post_password = isset($_POST['post_password']) ? trim($_POST['post_password']) : '';
    if ($post_visibility === 'password' && $post_password === '') {
        $errors[] = '选择“密码保护”可见性时必须设置密码。';
    }

    // 如果没有错误，更新文章
    if (empty($errors)) {
        // 获取主分类的模型ID和模型键（第一层：基础数据）
        $main_model_id = null;
        $main_model_key = 'article'; // 默认为文章模型

        // 优先使用POST中的分类数据，如果没有则使用现有文章的分类
        $categories_for_model = $post_categories;
        if (empty($categories_for_model)) {
            // 如果POST中没有分类数据，使用现有文章的分类
            $categories_for_model = array_map(function ($cat) {
                return $cat->term_id;
            }, $selected_categories);
        }

        if (!empty($categories_for_model)) {
            $main_category_id = $categories_for_model[0];
            $main_category_model = Todo_Model_Manager::get_category_model($main_category_id);
            if ($main_category_model) {
                $main_model_id = Todo_Model_Manager::get_model_id_by_key($main_category_model);
                $main_model_key = $main_category_model;
            }
        }
        if (($main_model_key === 'article' || $main_model_key === '') && isset($post->post_type) && $post->post_type !== '' && $post->post_type !== 'article') {
            $pt = (string) $post->post_type;
            if ($model_fields_manager->is_supported_model($pt)) {
                $main_model_key = $pt;
                $main_model_id = intval($post->model_id ?? 0);
                if ($main_model_id <= 0) {
                    $main_model_id = Todo_Model_Manager::get_model_id_by_key($pt);
                }
            }
        }

        // 定时发布处理：兼容之前保存的定时信息，确保在草稿/待审也能保存定时，在发布时应用
        $scheduled_post_date = null;
        $scheduled_post_date_gmt = null;
        $prev_schedule_enabled = function_exists('get_post_meta') ? get_post_meta($post_id, '_scheduled_enabled', true) : 0;
        $prev_schedule_time = function_exists('get_post_meta') ? get_post_meta($post_id, '_scheduled_time', true) : '';
        $prev_schedule_time_gmt = function_exists('get_post_meta') ? get_post_meta($post_id, '_scheduled_time_gmt', true) : '';
        $schedule_enabled_current = null; // null 表示沿用之前的设置

        if (!empty($_POST['enable_schedule'])) {
            if (!empty($_POST['schedule_time'])) {
                $raw_input = trim($_POST['schedule_time']);
                // 将 HTML5 datetime-local 值转换为标准格式 Y-m-d H:i:s
                $local_dt = str_replace('T', ' ', $raw_input);
                if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $local_dt)) {
                    $local_dt .= ':00';
                }
                $ts = strtotime($local_dt);
                if ($ts) {
                    $scheduled_post_date = date('Y-m-d H:i:s', $ts);
                    $scheduled_post_date_gmt = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                    $schedule_enabled_current = true;
                } else {
                    $schedule_enabled_current = false;
                }
            } else {
                // 明确提交但未提供时间，视为取消定时
                $schedule_enabled_current = false;
            }
        } else {
            // 如果提供了文章标题，说明是主表单提交，但未勾选定时，则明确清理定时设置
            if (isset($_POST['post_title'])) {
                $schedule_enabled_current = false;
            } else {
                // 否则（如来自其他动作），沿用之前的定时设置
                if (!empty($prev_schedule_enabled) && !empty($prev_schedule_time)) {
                    $scheduled_post_date = $prev_schedule_time;
                    $scheduled_post_date_gmt = $prev_schedule_time_gmt;
                    $schedule_enabled_current = true;
                } else {
                    $schedule_enabled_current = false;
                }
            }
        }

        // 初始尊重下拉框中选择的状态（如果存在）
        if (isset($_POST['post_status'])) {
            $post_status = $_POST['post_status'];
        }

        // 根据提交按钮微调状态（显式按钮动作优先级高）
        if (isset($_POST['save'])) {
            if ($_POST['save'] === '保存草稿' || $_POST['save'] === 'draft') {
                $post_status = 'draft';
            } elseif ($_POST['save'] === '提交审核' || $_POST['save'] === 'pending') {
                $post_status = 'pending';
            } elseif ($_POST['save'] === 'publish' && !isset($_POST['post_status'])) {
                // 如果没有显式传 post_status 下拉框且点击了发布性质的按钮，设为发布
                $post_status = 'publish';
            }
            // 注意：点击“更新”时 save 的值为 publish，但我们由于已经先取了 $_POST['post_status']，所以这里不再强制改变。这样“草稿 -> 更新”就能保持草稿状态。
        }

        // Prepare featured image data for posts table (JSON format)
        $featured_image_content = '';
        if (isset($_POST['featured_images'])) {
            $fi_data = $_POST['featured_images'];
            if (is_string($fi_data)) {
                $fi_decoded = json_decode($fi_data, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($fi_decoded)) {
                    $featured_image_content = json_encode($fi_decoded, JSON_UNESCAPED_UNICODE);
                }
            } elseif (is_array($fi_data)) {
                $featured_image_content = json_encode($fi_data, JSON_UNESCAPED_UNICODE);
            }
        }

        $post_data = array(
            'ID' => $post_id,
            'post_title' => $post_title,
            'post_content' => $post_content,
            'post_excerpt' => $post_excerpt,
            'post_status' => $post_status,
            'post_author' => $post_author,
            'post_type' => $main_model_key,  // 根据模型键设置post_type
            'model_id' => $main_model_id,
            'seo_title' => $seo_title,
            'seo_keywords' => $seo_keywords,
            'seo_description' => $seo_description,
            'seo_auto_update' => $seo_auto_update,
            // 高级设置字段
            'comment_status' => $comment_status,
            'custom_url' => $custom_url,
            // 基础字段
            'view_count' => $view_count,
            'read_permission' => $read_permission,
            'view_template' => $view_template,
            'visibility' => $post_visibility,
            'post_password' => $post_password,
            'featured_image' => $featured_image_content,
            'doc_properties' => isset($_POST['doc_properties']) ? implode(',', array_map('intval', $_POST['doc_properties'])) : ''
        );

        // 根据当前提交的状态与定时设置，应用发布时间
        if ($post_status === 'publish' && $schedule_enabled_current && !empty($scheduled_post_date)) {
            // 发布时应用之前或当前的定时
            $post_data['post_date'] = $scheduled_post_date;
            $post_data['post_date_gmt'] = $scheduled_post_date_gmt;
            $ts_check = strtotime($scheduled_post_date);
            $now_ts = time();
            if ($ts_check && $ts_check > $now_ts) {
                $post_status = 'future';
                $post_data['post_status'] = 'future';
            } else {
                $post_status = 'publish';
                $post_data['post_status'] = 'publish';
            }
        } elseif ($post_status === 'publish') {
            // 立即发布（未启用定时或没有定时时间）
            // 重要：已发布文章“更新”时不应覆盖 post_date（否则列表与前台会按更新时间重排）
            $post_data['post_status'] = 'publish';

            $keep_original_publish_date = false;
            if ($post && isset($post->post_status) && $post->post_status === 'publish' && !empty($post->post_date)) {
                $keep_original_publish_date = true;
            }

            if (!$keep_original_publish_date) {
                $now_mysql = current_time('mysql');
                $now_gmt = current_time('mysql', true);
                $post_data['post_date'] = $now_mysql;
                $post_data['post_date_gmt'] = $now_gmt;
            }

            // 若原文章为 future，确保改为 publish
            if ($post && isset($post->post_status) && $post->post_status === 'future') {
                $post_status = 'publish';
                $post_data['post_status'] = 'publish';
            }
        } else {
            // 草稿/待审：保存定时但不改变状态
            if ($schedule_enabled_current && !empty($scheduled_post_date)) {
                $post_data['post_date'] = $scheduled_post_date;
                $post_data['post_date_gmt'] = $scheduled_post_date_gmt;
            }
            // 不清理定时设置，除非用户明确取消
        }

        // 移除原有的强制 publish 逻辑，以尊重 calculated $post_status

        // 更新文章
        $result = todo_update_post($post_data);

        // 立即验证数据库状态并尝试修复（针对部分环境出现的事务提交异常）
        if (!is_todo_error($result) && isset($post_data['post_status'])) {
            global $tododb;
            // 绕过缓存直接查询数据库
            $db_status = $tododb->get_var($tododb->prepare("SELECT post_status FROM {$tododb->prefix}posts WHERE ID = %d", $post_id));

            if ($db_status !== $post_data['post_status']) {
                error_log("CRITICAL ERROR: Post status update mismatch! Expected: " . $post_data['post_status'] . ", Got: " . $db_status . ". Attempting forced correction.");

                // 强制直接更新数据库
                $force_update = $tododb->update(
                    "{$tododb->prefix}posts",
                    array('post_status' => $post_data['post_status']),
                    array('ID' => $post_id)
                );

                if ($force_update !== false) {
                    error_log("Forced correction successful.");
                } else {
                    error_log("Forced correction failed.");
                }
            }

            // 无论状态是否匹配，都确保清理缓存（确保缓存与数据库一致）
            // 注意：即使 todo_update_post 已经清理过缓存，这里再次清理可以确保：
            // 1. 如果 todo_update_post 因为某些原因没有清理缓存，这里会补充清理
            // 2. 如果状态不匹配并进行了强制修复，这里会清理修复后的缓存
            if (class_exists('Todo_Unified_Cache_Manager')) {
                Todo_Unified_Cache_Manager::clean_post_cache($post_id);
            }
        }

        // 保存可见性与密码元字段（第二层元数据）
        if (!is_todo_error($result)) {
            // 保存可见性设置
            update_post_meta($post_id, '_visibility', $post_visibility);
            // 确保密码也作为元数据保存（供 UI 使用）
            if ($post_visibility === 'password') {
                update_post_meta($post_id, 'post_password', $post_password);
            } else {
                update_post_meta($post_id, 'post_password', '');
            }
            update_post_meta($post_id, '_views_boost', $views_boost);
            // 保存定时设置（元数据）。保持草稿/待审下的定时持久化；仅在明确取消时清理
            if ($schedule_enabled_current && !empty($scheduled_post_date)) {
                update_post_meta($post_id, '_scheduled_enabled', 1);
                update_post_meta($post_id, '_scheduled_time', $scheduled_post_date);
                update_post_meta($post_id, '_scheduled_time_gmt', $scheduled_post_date_gmt);
            } else {
                // 如果明确提交了表单（由主表单标识 post_title 或 enable_schedule 判断），但未启用或时间为空，则清理元数据
                if (isset($_POST['post_title']) || isset($_POST['enable_schedule'])) {
                    update_post_meta($post_id, '_scheduled_enabled', 0);
                    update_post_meta($post_id, '_scheduled_time', '');
                    update_post_meta($post_id, '_scheduled_time_gmt', '');
                }
            }

            // 保存启用的多语言版本
            if (isset($_POST['enabled_languages']) && is_array($_POST['enabled_languages'])) {
                $enabled_langs = array_map('sanitize_text_field', $_POST['enabled_languages']);
                update_post_meta($post_id, '_enabled_languages', json_encode($enabled_langs));
            } else if (isset($_POST['action']) && $_POST['action'] === 'update_post') {
                // 如果提交了表单但没有勾选任何语言（且不是自动保存），则保存为空数组
                update_post_meta($post_id, '_enabled_languages', json_encode(array()));
            }

            // 检查是否有模型变更
            $model_changed = isset($_POST['model_changed']) && $_POST['model_changed'] === '1';
            $original_models = array();
            $new_models = array();

            if ($model_changed) {
                // 获取原始模型和新模型
                if (isset($_POST['original_models']) && !empty($_POST['original_models'])) {
                    $original_models = explode(',', $_POST['original_models'] ?? '');
                    $original_models = array_filter(array_map('trim', $original_models));
                }

                if (isset($_POST['new_models']) && !empty($_POST['new_models'])) {
                    $new_models = explode(',', $_POST['new_models'] ?? '');
                    $new_models = array_filter(array_map('trim', $new_models));
                }

                // 执行模型字段迁移
                if (!empty($original_models) && !empty($new_models)) {
                    $migration_details = array();
                    $migration_success = true;

                    // 为每个原始模型到新模型的组合执行迁移
                    foreach ($original_models as $from_model) {
                        foreach ($new_models as $to_model) {
                            if ($from_model !== $to_model) {
                                $migration_result = array(
                                    'success' => false,
                                    'errors' => array(),
                                    'migration_details' => array(
                                        'from_model' => $from_model,
                                        'to_model' => $to_model,
                                        'post_id' => $post_id,
                                    ),
                                );

                                if (class_exists('Todo_Model_Fields_Manager')) {
                                    $model_fields_manager = new Todo_Model_Fields_Manager();
                                    $ok = (bool) $model_fields_manager->migrate_model_fields($from_model, $to_model, $post_id, array());
                                    $migration_result['success'] = $ok;
                                    if (!$ok) {
                                        $migration_result['errors'][] = 'migrate_model_fields_failed';
                                    }
                                } else {
                                    $migration_result['errors'][] = 'Todo_Model_Fields_Manager_missing';
                                }

                                if (!$migration_result['success']) {
                                    $migration_success = false;
                                    // 收集迁移错误详情
                                    $error_detail = array(
                                        'from_model' => $from_model,
                                        'to_model' => $to_model,
                                        'success' => false,
                                        'errors' => $migration_result['errors'],
                                        'post_id' => $post_id
                                    );
                                    $migration_details[] = $error_detail;
                                    error_log('Model migration failed for post ' . $post_id . ' from ' . $from_model . ' to ' . $to_model . ': ' . implode(', ', $migration_result['errors']));
                                } else {
                                    // 收集迁移详情
                                    if (isset($migration_result['migration_details'])) {
                                        $migration_result['migration_details']['success'] = true;
                                        $migration_details[] = $migration_result['migration_details'];
                                    }
                                    error_log('Model migration successful for post ' . $post_id . ' from ' . $from_model . ' to ' . $to_model);
                                }
                            }
                        }
                    }

                    // 将迁移详情存储到会话中，用于页面显示
                    if (!empty($migration_details)) {
                        $_SESSION['migration_details'] = $migration_details;
                    }
                }
            }

            // 设置分类
            if (!empty($post_categories)) {
                todo_set_post_categories($post_id, $post_categories);
            }

            // 设置标签
            if (!empty($post_tags)) {
                todo_set_post_tags($post_id, $post_tags);
            }

            // 新的三层数据架构字段保存逻辑
            $model_fields_manager = new Todo_Model_Fields_Manager();
            $post_fields_manager = Todo_Post_Fields_Manager::get_instance();

            // 分离字段数据
            $model_preset_fields = array();  // 第二层：模型预设字段
            $custom_fields = array();        // 第三层：用户自定义字段

            // 获取模型的固定字段列表
            $fixed_field_keys = array();

            // 尝试从模型类动态获取固定字段键
            $model_class_name = 'CMS_Post_Type_' . ucfirst($main_model_key);
            if (class_exists($model_class_name) && method_exists($model_class_name, 'get_model_fixed_fields')) {
                $model_instance = new $model_class_name();
                $fixed_fields_config = $model_instance->get_model_fixed_fields();
                if (!empty($fixed_fields_config) && is_array($fixed_fields_config)) {
                    foreach ($fixed_fields_config as $cfg) {
                        if (is_array($cfg) && isset($cfg['field_key'])) {
                            $fixed_field_keys[] = $cfg['field_key'];
                        }
                    }
                }
            }

            // 如果动态获取失败，回退到硬编码列表
            if (empty($fixed_field_keys)) {
                $fixed_fields = $model_fields_manager->get_model_fixed_fields($main_model_key);
                if (!empty($fixed_fields)) {
                    $fixed_field_keys = $fixed_fields;
                }
            }

            foreach ($_POST as $key => $value) {
                // 预处理：从动态Radio name中捕获值（如 product_type_67xxx -> product_type）
                // 仅当标准字段名不存在或为空时生效
                if ($main_model_key === 'product') {
                    $dynamic_prefixes = ['product_type_', 'spec_type_', 'sale_status_', 'delivery_type_'];
                    foreach ($dynamic_prefixes as $prefix) {
                        if (str_starts_with($key, $prefix)) {
                            $base_key = substr($prefix, 0, -1); // remove trailing underscore
                            // 总是优先使用动态Key的值，因为这代表了用户真实的Radio选择
                            // 即使前端JS失效导致hidden input未更新，这里也能捕获到最新值
                            if ($value !== '') {
                                $_POST[$base_key] = $value;
                                $model_preset_fields[$base_key] = $value;
                            }
                        }
                    }
                }
                // 处理model_field_前缀的字段（自定义字段）
                if (str_starts_with($key, 'model_field_')) {
                    $clean_key = str_replace('model_field_', '', $key);

                    // 检查是否为模型预设字段
                    if ($main_model_key && $model_fields_manager->is_model_preset_field($main_model_key, $clean_key)) {
                        $model_preset_fields[$clean_key] = $value;
                        if ($main_model_key === 'product') {
                            $product_note_map = [
                                'before_payment_note' => 'memo_before_pay',
                                'after_payment_note' => 'memo_after_pay',
                                'confirm_receipt_note' => 'memo_after_confirm',
                            ];
                            if (isset($product_note_map[$clean_key])) {
                                $model_preset_fields[$product_note_map[$clean_key]] = $value;
                            }
                        }
                        // 添加调试日志
                        if ($clean_key === 'product_video') {
                            error_log("收集到产品视频字段 - clean_key: $clean_key, value: " . (is_array($value) ? json_encode($value) : $value));
                        }
                    } else {
                        // 兼容产品模型的友好字段名（before/after/confirm_*）映射为专用表字段
                        if ($main_model_key === 'product') {
                            $product_note_map = [
                                'before_payment_note' => 'memo_before_pay',
                                'after_payment_note' => 'memo_after_pay',
                                'confirm_receipt_note' => 'memo_after_confirm',
                            ];
                            if (isset($product_note_map[$clean_key])) {
                                $model_preset_fields[$product_note_map[$clean_key]] = $value;
                                continue;
                            }
                        }
                        // 用户自定义字段
                        $custom_fields[$key] = $value;
                    }
                } elseif (str_starts_with($key, 'custom_field_')) {
                    // 该值可能为JSON字符串（如 multi_file），保存器会根据字段类型自行处理
                    $custom_fields[$key] = $value;
                }
                // 产品模型的友好字段名映射到专用表字段名
                elseif ($main_model_key === 'product' && in_array($key, ['before_payment_note', 'after_payment_note', 'confirm_receipt_note'], true)) {
                    $product_note_map = [
                        'before_payment_note' => 'memo_before_pay',
                        'after_payment_note' => 'memo_after_pay',
                        'confirm_receipt_note' => 'memo_after_confirm',
                    ];
                    $mapped_key = $product_note_map[$key] ?? $key;
                    $model_preset_fields[$mapped_key] = $value;
                }
                elseif ($main_model_key === 'product' && in_array($key, ['product_services', 'product_type', 'spec_type', 'sale_status', 'product_video', 'discount_type', 'discount_config', 'spec_config', 'spec_details'], true)) {
                    $model_preset_fields[$key] = $value;
                }
                // 处理固定字段（直接使用字段键作为name）
                elseif (in_array($key, $fixed_field_keys, true)) {
                    $model_preset_fields[$key] = $value;
                    // 避免数组转字符串的警告，统一做安全日志输出
                    if (is_array($value) || is_object($value)) {
                        $val_str = json_encode($value, JSON_UNESCAPED_UNICODE);
                    } else {
                        $val_str = (string) $value;
                    }
                    error_log("Found fixed field: {$key} = {$val_str}");
                }
            }

            // 特殊处理：产品模型自定义参数 custom_params 组装与保存
            if ($main_model_key === 'product') {
                $params = [];
                // 支持两种命名方式：
                // 1) 关联数组：param_id[ID] / sort_order[ID] / param_name[ID] / param_value[ID]
                // 2) 顺序数组：param_sort[] / param_name[] / param_value[]
                $hasAssoc = isset($_POST['param_id']) || (isset($_POST['param_name']) && is_array($_POST['param_name']) && array_keys($_POST['param_name']) !== range(0, count($_POST['param_name']) - 1));
                if ($hasAssoc) {
                    $param_ids = [];
                    if (isset($_POST['param_id']) && is_array($_POST['param_id'])) {
                        $param_ids = array_keys($_POST['param_id']);
                    } elseif (isset($_POST['param_name']) && is_array($_POST['param_name'])) {
                        $param_ids = array_keys($_POST['param_name']);
                    }
                    foreach ($param_ids as $pid) {
                        $pid = trim((string) $pid);
                        $name = isset($_POST['param_name'][$pid]) ? trim((string) $_POST['param_name'][$pid]) : '';
                        $val = isset($_POST['param_value'][$pid]) ? trim((string) $_POST['param_value'][$pid]) : '';
                        $sort = isset($_POST['sort_order'][$pid]) ? (int) $_POST['sort_order'][$pid] : (isset($_POST['param_sort'][$pid]) ? (int) $_POST['param_sort'][$pid] : 100);
                        if ($name === '' && $val === '') {
                            continue;
                        }
                        $params[$pid] = [
                            'sort_order' => $sort,
                            'param_name' => $name,
                            'param_value' => $val,
                        ];
                    }
                } else {
                    $names = isset($_POST['param_name']) && is_array($_POST['param_name']) ? $_POST['param_name'] : [];
                    $vals = isset($_POST['param_value']) && is_array($_POST['param_value']) ? $_POST['param_value'] : [];
                    $sorts = isset($_POST['param_sort']) && is_array($_POST['param_sort']) ? $_POST['param_sort'] : [];
                    $count = max(count($names), count($vals));
                    for ($i = 0; $i < $count; $i++) {
                        $name = isset($names[$i]) ? trim((string) $names[$i]) : '';
                        $val = isset($vals[$i]) ? trim((string) $vals[$i]) : '';
                        $sort = isset($sorts[$i]) ? (int) $sorts[$i] : 100;
                        if ($name === '' && $val === '') {
                            continue;
                        }
                        $pid = (string) ($i + 1);
                        $params[$pid] = [
                            'sort_order' => $sort,
                            'param_name' => $name,
                            'param_value' => $val,
                        ];
                    }
                }
                // 处理删除的参数ID（如果存在）
                if (!empty($_POST['del_param_id'])) {
                    $del_ids = array_filter(array_map('trim', explode(',', (string) $_POST['del_param_id'])), 'strlen');
                    foreach ($del_ids as $did) {
                        unset($params[$did]);
                    }
                }
                // 根据排序值进行排序，保持键不变
                if (!empty($params)) {
                    uasort($params, function ($a, $b) {
                        return ($a['sort_order'] ?? 100) <=> ($b['sort_order'] ?? 100);
                    });
                }
                // 写入模型预设字段（空数组则写空字符串以清理数据）
                $model_preset_fields['custom_params'] = !empty($params) ? json_encode($params, JSON_UNESCAPED_UNICODE) : '';
            }

            // 获取字段配置进行验证 - 使用统一接口
            $field_configs = array();

            // 优先使用统一的字段管理接口获取字段配置
            $unified_field_configs = $post_fields_manager->get_custom_fields($post_id, null, 'post');
            if (!empty($unified_field_configs)) {
                $field_configs = $unified_field_configs;
            } else {
                // 回退到传统方法
                foreach ($post_categories as $category_id) {
                    $category_model = Todo_Model_Manager::get_category_model($category_id);
                    if ($category_model && $model_fields_manager->is_supported_model($category_model)) {
                        $model_fields = $model_fields_manager->get_model_fields($category_model, $post_id);
                        if (is_array($model_fields)) {
                            $field_configs = array_merge($field_configs, $model_fields);
                        }
                    }
                }
            }

            // 使用统一的字段管理接口保存所有字段
            // 第二层：保存模型预设字段到对应的模型表
            if (!empty($model_preset_fields) && $main_model_key) {
                // 添加调试日志
                error_log("准备保存模型预设字段 - Post ID: $post_id, Model: $main_model_key, Fields: " . json_encode(array_keys($model_preset_fields), JSON_UNESCAPED_UNICODE));

                $model_save_result = $model_fields_manager->save_model_preset_fields(
                    $main_model_key,
                    $post_id,
                    $model_preset_fields
                );

                if (!$model_save_result) {
                    error_log("ERROR: 模型预设字段保存失败 - Post ID: $post_id, Model: $main_model_key");
                    // 不要继续执行，因为保存失败
                } else {
                    error_log("成功保存模型预设字段 - Post ID: $post_id, Model: $main_model_key");
                }
            } else {
                error_log("跳过保存模型预设字段 - model_preset_fields为空: " . (empty($model_preset_fields) ? 'true' : 'false') . ", main_model_key: " . ($main_model_key ?? 'null'));
            }

            // 第三层：保存用户自定义字段 - 使用统一的post-fields-manager接口
            if (!empty($custom_fields)) {
                $custom_save_result = Todo_Post_Fields_Manager::get_instance()->save_custom_fields(
                    $post_id,
                    $main_model_id,
                    $custom_fields,
                    'post'
                );

                if (!$custom_save_result) {
                    error_log("Failed to save custom fields for post ID: $post_id");
                }
            }

            // 清除自动保存的草稿
            if (isset($_SESSION[$auto_draft_key])) {
                unset($_SESSION[$auto_draft_key]);
            }

            $redirect_from_query = '';
            if (isset($_GET['from']) && $_GET['from'] !== '') {
                $from_value = sanitize_text_field($_GET['from']);
                if ($from_value !== '') {
                    $redirect_from_query = '&from=' . rawurlencode($from_value);
                }
            }

            // 重定向到编辑页面或文章列表
            $redirect_message = 7; // 默认：草稿已更新

            if ($model_changed && !empty($original_models) && !empty($new_models)) {
                $redirect_message = 8; // 模型变更成功
            } elseif (isset($_POST['save']) && ($_POST['save'] === '更新' || $_POST['save'] === 'publish')) {
                $redirect_message = 2; // 文章已更新
            }

            if (isset($_POST['save']) && ($_POST['save'] === '更新' || $_POST['save'] === 'publish')) {
                if (function_exists('todo_add_admin_notice')) {
                    $messages = array(
                        2 => '文章已更新。',
                        7 => '草稿已更新。',
                        8 => '数据模型变更成功，字段数据已迁移。',
                    );
                    todo_add_admin_notice($messages[intval($redirect_message)] ?? '操作成功。', 'success');
                }
                todo_redirect(todo_admin_url('index.php?page=post-edit&id=' . $post_id . $redirect_from_query));
                exit;
            } elseif (isset($_POST['save']) && ($_POST['save'] === '保存草稿' || $_POST['save'] === 'draft')) {
                if (function_exists('todo_add_admin_notice')) {
                    todo_add_admin_notice('草稿已保存。', 'success');
                }
                todo_redirect(todo_admin_url('index.php?page=post-edit&id=' . $post_id . $redirect_from_query));
                exit;
            } elseif (isset($_POST['save']) && $_POST['save'] === '提交审核') {
                if (function_exists('todo_add_admin_notice')) {
                    todo_add_admin_notice('文章已提交审核。', 'success');
                }
                todo_redirect(todo_admin_url('index.php?page=posts'));
                exit;
            } else {
                // 根据文章状态决定重定向
                if ($post_status === 'publish') {
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice('文章已更新。', 'success');
                    }
                    todo_redirect(todo_admin_url('index.php?page=post-edit&id=' . $post_id . $redirect_from_query));
                } elseif ($post_status === 'pending') {
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice('文章已提交审核。', 'success');
                    }
                    todo_redirect(todo_admin_url('index.php?page=posts'));
                } else {
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice('草稿已保存。', 'success');
                    }
                    todo_redirect(todo_admin_url('index.php?page=post-edit&id=' . $post_id . $redirect_from_query));
                }
                exit;
            }
        } else {
            $errors[] = $result->get_error_message();
            // 重定向回编辑页面显示错误
            $redirect_from_query = '';
            if (isset($_GET['from']) && $_GET['from'] !== '') {
                $from_value = sanitize_text_field($_GET['from']);
                if ($from_value !== '') {
                    $redirect_from_query = '&from=' . rawurlencode($from_value);
                }
            }
            todo_redirect(todo_admin_url('index.php?page=post-edit&id=' . $post_id . $redirect_from_query . '&error=1'));
            exit;
        }
    }
}

// 获取所有分类
$categories = get_categories(array(
    'hide_empty' => false
));

// 获取所有用户
$users = get_users(array(
    'role__in' => array('administrator', 'editor', 'author'),
    'orderby' => 'display_name'
));

// 获取当前用户（todocms风格函数）
$current_user = todo_get_current_user();
?>

<!-- MathJax 支持 -->
<script id="MathJax-script" async src="<?php echo todo_admin_url('assets/js/mathjax.js'); ?>" type="text/javascript"></script>
<script type="text/javascript">
    window.MathJax = {
        tex: {
            inlineMath: [['$', '$'], ['\\(', '\\)']],
            displayMath: [['$$', '$$'], ['\\[', '\\]']],
            processEscapes: true,
            processEnvironments: true
        },
        options: {
            skipHtmlTags: ['script', 'noscript', 'style', 'textarea', 'pre']
        }
    };
</script>

<?php
// 输出层级分类选择器的样式和脚本
enqueue_hierarchical_categories_selector_styles();
?>

<style>
    .updated {
        background: #fff;
        border-left: 4px solid #46b450;
    }

    /* 语言启用选择下拉菜单样式 */
    .lang-selector-dropdown {
        position: relative;
        display: inline-block;
    }

    .lang-selector-menu {
        display: none;
        position: absolute;
        right: 0;
        bottom: 110%;
        background: #fff;
        border: 1px solid #eceef1;
        border-radius: 4px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        z-index: 1000;
        min-width: 235px;
        padding: 10px;
    }

    .lang-selector-dropdown.active .lang-selector-menu {
        display: block;
    }

    .lang-selector-item {
        display: flex !important;
        align-items: center;
        padding: 2px 8px;
        cursor: pointer;
        border-radius: 3px;
        transition: background 0.2s;
        font-size: 13px;
        margin: 0 !important;
    }

    .lang-selector-item:hover {
        background: #f0f0f1;
    }

    .lang-selector-divider {
        height: 1px;
        background: #eee;
        margin: 8px 0;
    }

    .lang-selector-action {
        color: #007cba;
        text-decoration: none;
        font-size: 13px;
        padding: 2px 8px;
        display: block;
        cursor: pointer;
    }

    .lang-selector-action:hover {
        background: #f0f0f1;
        color: #005687;
    }

    .lang-selector-level-1 {
        padding-left: 24px;
    }

    .lang-selector-level-2 {
        padding-left: 40px;
    }

    .lang-selector-level-3 {
        padding-left: 56px;
    }

    .lang-selector-level-4 {
        padding-left: 72px;
    }
</style>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">内容更新</h2>
        <button type="button" class="button button-link" id="todo-super-copy" title="复制全量数据" style="margin-right: auto;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" style="vertical-align: middle;"><g fill="currentColor"><path d="M11 0H0v11h3V9H2V2h7v1h2z"/><path d="M16 5H5v11h11zm-9 9V7h7v7z"/></g></svg>
        </button>
        <div class="admin-page-actions">
            <?php
            $return_url = todo_admin_url('index.php?page=posts');
            $from = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
            if ($from !== '') {
                $return_url = todo_admin_url('index.php?page=' . urlencode($from));
            }
            ?>
            <a href="<?php echo $return_url; ?>" class="button button-secondary">返回列表</a>
        </div>
    </div>

    <?php
    if (isset($_SESSION['migration_details']) && !empty($_SESSION['migration_details'])) {
        $has_success = false;
        $has_error = false;

        // 检查是否有成功和失败的迁移
        foreach ($_SESSION['migration_details'] as $detail) {
            if (isset($detail['success']) && $detail['success']) {
                $has_success = true;
            } elseif (isset($detail['success']) && !$detail['success']) {
                $has_error = true;
            } elseif (!isset($detail['success']) && (isset($detail['migrated_fields']) || isset($detail['deleted_fields']))) {
                $has_success = true;
            }
        }

        // 根据结果选择通知样式
        $notice_class = 'notice-info';
        if ($has_error && !$has_success) {
            $notice_class = 'notice-error';
        } elseif ($has_success && !$has_error) {
            $notice_class = 'notice-success';
        } elseif ($has_success && $has_error) {
            $notice_class = 'notice-warning';
        }

        echo '<div class="notice ' . $notice_class . ' is-dismissible">';
        echo '<h4 style="margin-top: 10px;">模型字段迁移详情：</h4>';

        foreach ($_SESSION['migration_details'] as $detail) {
            $is_success = isset($detail['success']) ? $detail['success'] : (isset($detail['migrated_fields']) || isset($detail['deleted_fields']));

            echo '<p><strong>从模型 "' . esc_html($detail['from_model']) . '" 迁移到 "' . esc_html($detail['to_model']) . '"</strong></p>';

            if (!$is_success && isset($detail['errors'])) {
                echo '<p><span style="color: #dc3232;">✗ 迁移失败：</span></p>';
                echo '<ul style="margin-left: 20px; color: #dc3232;">';
                foreach ($detail['errors'] as $error) {
                    echo '<li>' . esc_html($error) . '</li>';
                }
                echo '</ul>';
            } else {
                if (!empty($detail['deleted_fields'])) {
                    echo '<p><span style="color: #dc3232;">✗ 已删除字段：</span> ' . esc_html(implode(', ', $detail['deleted_fields'])) . '</p>';
                }

                if (!empty($detail['migrated_fields'])) {
                    echo '<p><span style="color: #46b450;">✓ 已迁移字段：</span> ' . esc_html(implode(', ', $detail['migrated_fields'])) . '</p>';
                }

                if (empty($detail['deleted_fields']) && empty($detail['migrated_fields'])) {
                    echo '<p style="color: #666;">无字段需要迁移</p>';
                }
            }
        }

        echo '<button type="button" class="notice-dismiss">✕</button>';
        echo '</div>';

        // 清除会话中的迁移详情，避免重复显示
        unset($_SESSION['migration_details']);
    }
    ?>

    <?php if (!empty($errors)): ?>
        <div class="notice notice-error is-dismissible">
            <?php foreach ($errors as $error): ?>
                <p><?php echo esc_html($error); ?></p>
            <?php endforeach; ?>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <?php
    $from_query = '';
    if (isset($_GET['from']) && $_GET['from'] !== '') {
        $from_value = sanitize_text_field($_GET['from']);
        if ($from_value !== '') {
            $from_query = '&from=' . rawurlencode($from_value);
        }
    }
    ?>
    <form method="post" enctype="multipart/form-data" id="post-form" class="todo-request" action="<?php echo todo_admin_url('index.php?page=post-edit&id=' . $post_id . $from_query); ?>">
        <input type="hidden" name="action" value="update_post">
        <input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>">
        <?php echo Todo_Security_Manager::csrf_field('edit_post_nonce', '_todononce'); ?>

        <div class="admin-post-form-wrap">
            <div class="admin-post-main">
                <div class="admin-card">
                    <div class="admin-card-content">
                        <div class="form-group">
                            <label for="post-title">标题/名称</label>
                            <input type="text" name="post_title" id="post-title"
                                value="<?php echo $auto_draft_data ? esc_attr($auto_draft_data['title']) : esc_attr($post->post_title); ?>"
                                placeholder="在此输入标题 或 名称" class="admin-post-title-input">
                            <?php if ($auto_draft_data): ?>
                                <div class="auto-draft-notice">
                                    <p class="text-info">
                                        检测到自动保存的草稿（<?php echo format_date($auto_draft_data['saved_at'], 'Y-m-d H:i'); ?>），已自动恢复内容。
                                        <a href="javascript:void(0);" id="clear-auto-draft"
                                            class="text-danger ml-2">清除草稿</a>
                                    </p>
                                </div>
                            <?php endif; ?>

                            <!-- 永久链接编辑器 -->
                            <div class="permalink-editor" id="permalink-editor">
                                <div class="permalink-display">
                                    固定链接
                                    <span class="permalink-url"><?php echo esc_html(get_permalink($post)); ?></span>
                                    <button type="button" class="btn-link permalink-edit-btn"
                                        id="permalink-edit-btn">编辑</button>
                                </div>
                                <div class="permalink-edit-form" id="permalink-edit-form" style="display: none;">
                                    <div class="permalink-input-group">
                                        <span class="permalink-prefix">别名/地址</span>
                                        <input type="text" name="custom_url" id="custom-url"
                                            value="<?php echo esc_attr($post->custom_url); ?>"
                                            placeholder="<?php echo esc_attr($post->post_name); ?>"
                                            class="permalink-input">
                                    </div>
                                    <div class="permalink-actions">
                                        <button type="button" class="btn button-secondary"
                                            id="permalink-cancel-btn">取消</button>
                                        <button type="button" class="btn button-primary"
                                            id="permalink-save-btn">确定</button>
                                    </div>
                                    <p class="description">地址解析以“设置 〉网站信息
                                        〉固定连接”的结构为准，留空则默认使用文章别名，支持http/https连接（自定义外链地址）。设置并更新文章才会生效。</p>
                                </div>
                            </div>
                        </div>

                        <!-- 文档属性关联 -->
                        <?php
                        // 获取前台显示的文档属性
                        require_once ABSPATH . 'admin/includes/doc-properties-functions.php';
                        $display_properties = get_display_doc_properties();

                        // 获取当前文章已关联的属性
                        $post_properties = get_post_doc_properties($post_id);
                        ?>
                        <?php if (!empty($display_properties)): ?>
                            <div class="form-group">
                                <h4>文档属性</h4>
                                <div class="doc-properties-wrapper">
                                    <?php foreach ($display_properties as $property): ?>
                                        <?php
                                        // 确保 $property 是数组（兼容对象返回）
                                        if (is_object($property)) {
                                            $property = (array) $property;
                                        }
                                        if (!is_array($property) || !isset($property['id'])) {
                                            continue;
                                        }
                                        ?>
                                        <label class="doc-property-item">
                                            <input type="checkbox" name="doc_properties[]"
                                                value="<?php echo esc_attr($property['id']); ?>" <?php checked(in_array($property['id'], $post_properties)); ?>>
                                            <span
                                                class="property-name"><?php echo esc_html(isset($property['name']) ? $property['name'] : ''); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <div class="editor-toolbar-wrapper">
                                <h4>内容</h4>
                            </div>
                            <div class="editor-container" id="editor-container">
                                <?php
                                // 使用文本编辑器
                                $content = $auto_draft_data ? $auto_draft_data['content'] : $post->post_content;
                                if (function_exists('todo_convert_content_urls_to_absolute')) {
                                    $content = todo_convert_content_urls_to_absolute($content);
                                }
                                ?>
                                <textarea id="post-content" name="post_content" rows="20"
                                    style="width: 100%; height: 400px;"><?php echo htmlspecialchars($content); ?></textarea>
                            </div>
                            <div class="editor-status items-center justify-between">
                                <div class="flex flex-col">
                                    <p>
                                        <span id="auto-save-status"></span>
                                        <span id="last-saved"></span>
                                    </p>
                                    <a href="javascript:void(0);" id="btn-clean-format" style="margin-right: auto;opacity: 0.7;" title="清理无用样式及多余属性，保留纯净排版"><i class="icon todofont todo-Clear" style="opacity: 0.5; font-size: 14px; margin-right: 1px;"></i>清理多余属性</a>
                                </div>
                                <div class="editor-quick-actions flex items-center gap-3">
                                    <?php 
                                    $is_cloud_storage = false;
                                    if (class_exists('Todo_Storage_Manager')) {
                                        $storage_mgr = Todo_Storage_Manager::get_instance();
                                        if (!$storage_mgr->is_local()) {
                                            $is_cloud_storage = true;
                                        }
                                    }
                                    ?>
                                    <label class="flex items-center gap-1 m-0" style="opacity: 0.5; filter: grayscale(1);"><input type="checkbox" id="chk-save-remote-images" name="chk_save_remote_images" <?php echo $is_cloud_storage ? 'disabled' : 'checked'; ?> title="<?php echo $is_cloud_storage ? '已开启云存储，禁止二次抓取保存' : ''; ?>">保存远程图片</label>
                                    <label class="flex items-center gap-1 m-0" style="opacity: 0.5; filter: grayscale(1);"><input type="checkbox" id="chk-clear-external-links" name="chk_clear_external_links" checked>清除外链地址</label>
                                    <button type="button" class="btn button-link" id="btn-ai-expand-export" title="保留结构进行内容扩展">导出AI扩展</button>
                                    <button type="button" class="btn button-link" id="btn-ai-create-export" title="基于媒体资源进行原创创作">导出AI创作</button>
                                    <button type="button" class="btn button-link" id="btn-ai-import-creation" title="将AI创作导入到编辑器">导入JSON创作</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="admin-card">
                    <div class="admin-card-content">
                        <div class="admin-post-status">
                            <label for="post-excerpt">摘要</label>
                            <textarea name="post_excerpt" id="post-excerpt" rows="3"
                                class="admin-post-excerpt-editor"><?php echo esc_textarea($post->post_excerpt); ?></textarea>
                        </div>
                    </div>
                </div>

                <?php
                // 引入数据模型字段组件
                require_once ABSPATH . 'admin/components/model-fields-component.php';

                // 引入弹窗组件
                require_once TODO_ADMIN_PATH . '/components/modal.php';
                todo_enqueue_script('todo-modal');

                // 渲染数据模型字段组件
                $__todo_post_edit_t_model_fields = microtime(true);
                global $tododb;
                $__todo_post_edit_q0 = (isset($tododb) && method_exists($tododb, 'query_count')) ? $tododb->query_count() : null;
                render_model_fields_component(array(
                    'post_id' => $post_id,
                    'selected_categories' => $selected_categories,
                    'field_values' => $field_values,
                    'container_id' => 'editbox',
                    'show_title' => true, // 显示区块的标题“XX模型 字段”
                    'defer_attrs' => false
                ));
                $__todo_post_edit_q1 = (isset($tododb) && method_exists($tododb, 'query_count')) ? $tododb->query_count() : null;
                if (class_exists('Todo_Performance_Monitor')) {
                    $qdesc = ($__todo_post_edit_q0 !== null && $__todo_post_edit_q1 !== null) ? (' (+' . max(0, $__todo_post_edit_q1 - $__todo_post_edit_q0) . ' queries)') : '';
                    Todo_Performance_Monitor::add_metric('post_edit_model_fields', 'render model fields' . $qdesc, (microtime(true) - $__todo_post_edit_t_model_fields) * 1000);
                }

                // 输出组件样式
                enqueue_model_fields_component_styles(); ?>

                <?php // 动态触发定时发布检查：访问此页面时也执行一次，确保到期文章立即发布
                if (function_exists('todo_run_scheduled_posts')) {
                    todo_run_scheduled_posts();
                }
                ?>

                <!-- 多语言：语言版本面板 -->
                <div class="admin-card" id="language-versions-card">
                    <div class="admin-card-header no-border">
                        <h3 class="admin-card-title">语言版本</h3>
                        <div class="lang-selector-dropdown" id="lang-selector-dropdown">
                            <button type="button" class="button button-link" id="lang-selector-btn"><i
                                    class="toicon">↵</i> 语言开关</button>
                            <div class="lang-selector-menu">
                                <div class="lang-selector-action" id="lang-select-all">✓ 启用所有语言（全选）</div>
                                <div class="lang-selector-action" id="lang-deselect-all">✘ 禁用所有语言（取消全选）</div>
                                <div class="lang-selector-divider"></div>
                                <div class="lang-selector-list" style="max-height: 400px; overflow: auto;">
                                    <?php
                                    $default_lang_canonical = str_replace('_', '-', get_default_language());
                                    if (strpos($default_lang_canonical, '-') !== false) {
                                        list($l, $r) = explode('-', $default_lang_canonical, 2);
                                        $default_lang_canonical = strtolower($l) . '-' . strtoupper($r);
                                    }
                                    foreach ($hierarchical_languages as $loc => $info):
                                        $is_default = ($loc === $default_lang_canonical);
                                        ?>
                                        <label
                                            class="lang-selector-item lang-selector-level-<?php echo isset($info['_level']) ? $info['_level'] : 0; ?>">
                                            <?php if ($is_default): ?>
                                                <input type="checkbox" name="enabled_languages[]"
                                                    value="<?php echo esc_attr($loc); ?>" checked style="display:none;">
                                                <b><?php echo esc_html($info['title']); ?></b>
                                                <span style="color: #999;">[主稿]</span>
                                            <?php else: ?>
                                                <input type="checkbox" name="enabled_languages[]"
                                                    value="<?php echo esc_attr($loc); ?>" <?php checked(in_array($loc, $enabled_languages)); ?>>
                                                <span><?php echo esc_html($info['title']); ?></span>
                                            <?php endif; ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="admin-card-content">
                        <?php
                        $available_languages = function_exists('get_available_languages') ? get_available_languages() : array();
                        // 统一默认语言代码：旧式下划线 → 连字符，并区域段大写（如 zh-CN）
                        $default_language = function_exists('get_default_language') ? get_default_language() : 'zh_CN';
                        $post_id_safe = intval($post->ID);
                        // 为模型字段组件提供规范化语言码（与后端校验一致）
                        $component_lang_code = str_replace('_', '-', $default_language);
                        if (strpos($component_lang_code, '-') !== false) {
                            list($l, $r) = explode('-', $component_lang_code, 2);
                            $component_lang_code = strtolower($l) . '-' . strtoupper($r);
                        } else {
                            $component_lang_code = strtolower($component_lang_code);
                        }
                        if (function_exists('canonical_locale')) {
                            $component_lang_code = canonical_locale($component_lang_code);
                        }
                        ?>
                        <div id="lang-versions-panel" data-post-id="<?php echo esc_attr($post_id_safe); ?>"
                            data-default-lang="<?php echo esc_attr($default_language); ?>">
                            <div class="lang-versions-list"></div>
                            <div class="lang-versions-editor" style="display:none;">
                                <div class="form-group">
                                    <label>语言</label>
                                    <select id="tr-language"></select>
                                </div>
                                <div class="form-group">
                                    <label>标题</label>
                                    <input type="text" id="tr-title" class="w-100" placeholder="翻译标题">
                                </div>
                                <div class="form-group">
                                    <label>内容</label>
                                    <textarea id="post-translation-content" style="width:100%;height:240px;"></textarea>
                                </div>
                                <div class="form-group">
                                    <label>摘要</label>
                                    <textarea id="tr-excerpt" rows="3" class="w-100" placeholder="翻译摘要"></textarea>
                                </div>

                                <div style="margin: -15px -15px 0;">
                                    <?php
                                    // 复用模型字段组件：用于多语言版本的字段编辑
                                    // 使用不同的容器ID与字段名前缀，避免与主语言冲突
                                    $__todo_post_edit_t_tr_model_fields = microtime(true);
                                    global $tododb;
                                    $__todo_post_edit_tr_q0 = (isset($tododb) && method_exists($tododb, 'query_count')) ? $tododb->query_count() : null;
                                    render_model_fields_component(array(
                                        'post_id' => $post_id,
                                        'selected_categories' => $selected_categories,
                                        'field_values' => array(), // 初始为空，打开编辑器时通过AJAX恢复
                                        'container_id' => 'trabox',
                                        'instance_uid' => 'trpanel',
                                        'show_title' => true,
                                        'include_featured' => true,
                                        // 语言感知的字段名前缀：避免与主内容表单冲突
                                        'lang_code' => $component_lang_code,
                                        'defer_attrs' => true,
                                    ));
                                    $__todo_post_edit_tr_q1 = (isset($tododb) && method_exists($tododb, 'query_count')) ? $tododb->query_count() : null;
                                    if (class_exists('Todo_Performance_Monitor')) {
                                        $qdesc = ($__todo_post_edit_tr_q0 !== null && $__todo_post_edit_tr_q1 !== null) ? (' (+' . max(0, $__todo_post_edit_tr_q1 - $__todo_post_edit_tr_q0) . ' queries)') : '';
                                        Todo_Performance_Monitor::add_metric('post_edit_tr_model_fields', 'render tr model fields' . $qdesc, (microtime(true) - $__todo_post_edit_t_tr_model_fields) * 1000);
                                    }
                                    ?>
                                </div>

                                <div class="fixed-fields-section">
                                    <div class="form-group">
                                        <h4>翻译状态</h4>
                                        <select id="tr-status">
                                            <option value="pending">待审核</option>
                                            <option value="approved">已发布</option>
                                            <option value="rejected">被拒绝</option>
                                            <option value="draft">草稿</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="flex gap-3 flex-wrap form-submit">
                                    <button type="button" class="button button-link"
                                        id="btn-copy-default">复制默认语言内容</button>
                                    <button type="button" class="button button-link" id="btn-cancel-translation"
                                        style="margin-left: 33px;">取消</button>
                                    <button type="button" class="button button-primary"
                                        id="btn-save-translation">保存翻译</button>
                                    <div class="flex gap-3" style="margin-left: auto;">
                                        <button type="button" class="button button-secondary" id="btn-copy-all">批量复制并发布</button>
                                        <button type="button" class="button button-secondary" id="btn-export-json">导出JSON翻译</button>
                                        <button type="button" class="button button-secondary" id="btn-import-json">导入JSON翻译</button>
                                    </div>
                                </div>
                            </div>
                            <style>
                                .lang-versions-list {
                                    display: flex;
                                    flex-wrap: wrap;
                                    gap: 8px;
                                }

                                .lang-chip {
                                    display: inline-flex;
                                    align-items: center;
                                    gap: 6px;
                                    border: 1px solid #eceef1;
                                    border-radius: 3px;
                                    padding: 2px 4px 2px 10px;
                                    cursor: pointer;
                                    background: #fafafa;
                                }

                                .lang-chip .code {
                                    font-weight: 500;
                                }

                                .lang-chip .name {
                                    font-size: 13px;
                                }

                                .lang-chip .status {
                                    font-size: 12px;
                                    padding: 2px 6px;
                                    border-radius: 3px;
                                    background: #eee;
                                }

                                .lang-chip.approved .status {
                                    background: #d4edda;
                                    color: #155724;
                                }

                                .lang-chip.pending .status {
                                    background: #fff3cd;
                                    color: #856404;
                                }

                                .lang-chip.rejected .status {
                                    background: #f8d7da;
                                    color: #721c24;
                                }

                                /* 默认语言状态样式 */
                                .lang-chip.default .status {
                                    background: #e0e7ff;
                                    color: #1e40af;
                                }

                                .lang-versions-editor {
                                    border-top: 1px dashed #eceef1;
                                    margin-top: 12px;
                                    padding-top: 12px;
                                }
                            </style>
                            <script type="text/javascript">
                                // 组件隔离说明：
                                // 1) 主内容组件与翻译内容组件在DOM与数据上完全独立
                                // 2) 翻译内容组件按语言生成唯一命名空间：name 前缀为 tr_{lang}_model_field_
                                // 3) 翻译字段的元素ID按语言唯一：id=trpanel-{langKey}-{fieldKey}-{counter}
                                // 4) 提交主表单时自动禁用翻译组件，避免数据污染（严格隔离）
                                // 5) 性能策略：仅在打开语言面板时重写前缀与ID，避免不必要的DOM操作
                                (function () {
                                    var adminBase = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
                                    var ajaxUrl = adminBase + 'ajax/post-ajax.php';
                                    var postId = <?php echo intval($post_id_safe); ?>;
                                    var defaultLang = '<?php echo esc_attr(function_exists('canonical_locale') ? canonical_locale(str_replace('_', '-', $default_language)) : str_replace('_', '-', $default_language)); ?>';
                                    var nonceInput = document.querySelector('input[name="_todononce"]');
                                    var _todononce = nonceInput ? nonceInput.value : '';
                                    var LANGS = <?php echo json_encode($available_languages, JSON_UNESCAPED_UNICODE); ?> || {};

                                    var panel = document.getElementById('lang-versions-panel');
                                    var listEl = panel.querySelector('.lang-versions-list');
                                    var editorEl = panel.querySelector('.lang-versions-editor');
                                    var langSelect = document.getElementById('tr-language');
                                    var titleInput = document.getElementById('tr-title');
                                    var excerptInput = document.getElementById('tr-excerpt');
                                    var statusSelect = document.getElementById('tr-status');
                                    var btnCopy = document.getElementById('btn-copy-default');
                                    var btnSave = document.getElementById('btn-save-translation');
                                    var btnCancel = document.getElementById('btn-cancel-translation');
                                    var btnCopyAll = document.getElementById('btn-copy-all');

                                    // 语言启用选择下拉逻辑
                                    (function () {
                                        var dropdown = document.getElementById('lang-selector-dropdown');
                                        var btn = document.getElementById('lang-selector-btn');
                                        var selectAll = document.getElementById('lang-select-all');
                                        var deselectAll = document.getElementById('lang-deselect-all');
                                        var checkboxes = dropdown.querySelectorAll('.lang-selector-list input[type="checkbox"]');

                                        function getCheckboxState() {
                                            var state = {};
                                            checkboxes.forEach(function (cb) {
                                                state[cb.value] = cb.checked;
                                            });
                                            return JSON.stringify(state);
                                        }
                                        var initialState = getCheckboxState();

                                        function saveEnabledLanguages() {
                                            var currentState = getCheckboxState();
                                            if (currentState === initialState) return;

                                            if (typeof TodoModal !== 'undefined' && typeof TodoModal.confirm === 'function') {
                                                TodoModal.confirm({
                                                    title: '保存设置',
                                                    message: '是否保存当前语言版本的启用设置？',
                                                    onConfirm: function () {
                                                        var enabled = [];
                                                        checkboxes.forEach(function (cb) {
                                                            if (cb.checked) {
                                                                enabled.push(cb.value);
                                                            }
                                                        });

                                                        var fd = new FormData();
                                                        fd.append('action', 'save_enabled_languages');
                                                        fd.append('post_id', postId);
                                                        enabled.forEach(function (lang) {
                                                            fd.append('enabled_languages[]', lang);
                                                        });
                                                        fd.append('_todononce', _todononce);

                                                        fetch(ajaxUrl, {
                                                            method: 'POST',
                                                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                                                            body: fd
                                                        })
                                                            .then(function (r) { return r.json(); })
                                                            .then(function (json) {
                                                                if (json.success) {
                                                                    console.log('Language settings saved');
                                                                    initialState = currentState; // 更新初始状态
                                                                } else {
                                                                    console.error('Failed to save language settings:', json.message);
                                                                    if (typeof TodoModal.error === 'function') {
                                                                        TodoModal.error('保存失败：' + json.message);
                                                                    }
                                                                }
                                                            })
                                                            .catch(function (err) {
                                                                console.error('Error saving language settings:', err);
                                                            });
                                                    },
                                                    onCancel: function () {
                                                        // 还原状态
                                                        var stateObj = JSON.parse(initialState);
                                                        checkboxes.forEach(function (cb) {
                                                            if (stateObj.hasOwnProperty(cb.value)) {
                                                                cb.checked = stateObj[cb.value];
                                                            }
                                                        });
                                                        if (typeof renderLangChips === 'function') renderLangChips();
                                                    }
                                                });
                                            } else {
                                                // Fallback to browser confirm
                                                if (confirm('是否保存当前语言版本的启用设置？')) {
                                                    var enabled = [];
                                                    checkboxes.forEach(function (cb) {
                                                        if (cb.checked) {
                                                            enabled.push(cb.value);
                                                        }
                                                    });

                                                    var fd = new FormData();
                                                    fd.append('action', 'save_enabled_languages');
                                                    fd.append('post_id', postId);
                                                    enabled.forEach(function (lang) {
                                                        fd.append('enabled_languages[]', lang);
                                                    });
                                                    fd.append('_todononce', _todononce);

                                                    fetch(ajaxUrl, {
                                                        method: 'POST',
                                                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                                                        body: fd
                                                    })
                                                        .then(function (r) { return r.json(); })
                                                        .then(function (json) {
                                                            if (json.success) {
                                                                console.log('Language settings saved');
                                                                initialState = currentState;
                                                            }
                                                        });
                                                } else {
                                                    var stateObj = JSON.parse(initialState);
                                                    checkboxes.forEach(function (cb) {
                                                        if (stateObj.hasOwnProperty(cb.value)) {
                                                            cb.checked = stateObj[cb.value];
                                                        }
                                                    });
                                                    if (typeof renderLangChips === 'function') renderLangChips();
                                                }
                                            }
                                        }

                                        btn.addEventListener('click', function (e) {
                                            e.stopPropagation();
                                            if (dropdown.classList.contains('active')) {
                                                saveEnabledLanguages();
                                            } else {
                                                initialState = getCheckboxState(); // 记录打开时的状态
                                            }
                                            dropdown.classList.toggle('active');
                                        });

                                        document.addEventListener('click', function (e) {
                                            if (!dropdown.contains(e.target)) {
                                                if (dropdown.classList.contains('active')) {
                                                    saveEnabledLanguages();
                                                }
                                                dropdown.classList.remove('active');
                                            }
                                        });

                                        selectAll.addEventListener('click', function () {
                                            checkboxes.forEach(function (cb) {
                                                cb.checked = true;
                                            });
                                            if (typeof renderLangChips === 'function') renderLangChips();
                                        });

                                        deselectAll.addEventListener('click', function () {
                                            checkboxes.forEach(function (cb) {
                                                if (cb.style.display !== 'none') {
                                                    cb.checked = false;
                                                }
                                            });
                                            if (typeof renderLangChips === 'function') renderLangChips();
                                        });

                                        checkboxes.forEach(function (cb) {
                                            cb.addEventListener('change', function () {
                                                if (typeof renderLangChips === 'function') renderLangChips();
                                            });
                                        });
                                    })();

                                    var trModelContentId = 'trabox-content-trpanel';

                                    var TrEditor = (function () {
                                        var currentLang = '';
                                        function canonicalizeLocale(loc) {
                                            if (!loc || typeof loc !== 'string') return '';
                                            var s = loc.replace('_', '-');
                                            var parts = s.split('-');
                                            if (parts.length === 1) return parts[0].toLowerCase();
                                            return parts[0].toLowerCase() + '-' + parts[1].toUpperCase();
                                        }
                                        function namePrefix(loc) {
                                            var effective = canonicalizeLocale(loc || currentLang || (langSelect && langSelect.value) || '');
                                            return 'tr_' + effective + '_model_field_';
                                        }
                                        function disableInputs(disabled) {
                                            var c = document.getElementById(trModelContentId);
                                            if (!c) return;
                                            c.querySelectorAll('input, textarea, select').forEach(function (el) {
                                                el.disabled = !!disabled;
                                                var hasName = el.hasAttribute('name');
                                                if (disabled) {
                                                    if (hasName) {
                                                        var n = el.getAttribute('name');
                                                        if (n) el.setAttribute('data-name-backup', n);
                                                        el.removeAttribute('name');
                                                    }
                                                } else {
                                                    if (!hasName) {
                                                        var backup = el.getAttribute('data-name-backup');
                                                        if (backup) {
                                                            el.setAttribute('name', backup);
                                                        } else {
                                                            var base = el.getAttribute('data-field-key');
                                                            if (base) {
                                                                var isArray = el.getAttribute('data-array') === '1' || /\[\]$/.test(base);
                                                                base = base.replace(/\[\]$/, '');
                                                                var newName = namePrefix(currentLang) + base + (isArray ? '[]' : '');
                                                                el.setAttribute('name', newName);
                                                            }
                                                        }
                                                    }
                                                }
                                            });
                                        }
                                        function assignNamespace(loc) {
                                            var c = document.getElementById(trModelContentId);
                                            if (!c) return;
                                            var prefix = namePrefix(loc);
                                            c.setAttribute('data-name-prefix', prefix);
                                            c.querySelectorAll('input[name], textarea[name], select[name]').forEach(function (el) {
                                                var name = el.getAttribute('name');
                                                if (!name) return;
                                                var base = name
                                                    .replace(/^tr_[a-z]{2}(?:-[A-Z]{2})?_model_field_/, '')
                                                    .replace(/^tr_model_field_/, '')
                                                    .replace(/^model_field_/, '');
                                                if (base === name) return;
                                                var isArray = /\[\]$/.test(base) || /\[\]$/.test(name);
                                                base = base.replace(/\[\]$/, '');
                                                var newName = prefix + base + (isArray ? '[]' : '');
                                                el.setAttribute('name', newName);
                                                el.classList.add('tr-field');
                                                el.classList.add('tr-lang-' + prefix.replace(/^tr_/, '').replace(/_model_field_$/, ''));
                                                el.setAttribute('data-field-key', base);
                                            });
                                            c.querySelectorAll('input:not([name]), textarea:not([name]), select:not([name])').forEach(function (el) {
                                                var wrap = el.closest('[data-field-key]');
                                                var base = wrap ? wrap.getAttribute('data-field-key') : '';
                                                if (!base) return;
                                                var isArray = el.getAttribute('data-array') === '1';
                                                var newName = prefix + base + (isArray ? '[]' : '');
                                                el.setAttribute('name', newName);
                                                el.classList.add('tr-field');
                                                el.setAttribute('data-field-key', base);
                                            });
                                        }
                                        function assignUniqueIds(loc) {
                                            var c = document.getElementById(trModelContentId);
                                            if (!c) return;
                                            var prefix = namePrefix(loc);
                                            var langKey = prefix.replace(/^tr_/, '').replace(/_model_field_$/, '');
                                            var seen = {};
                                            var counter = 0;
                                            c.querySelectorAll('input, textarea, select').forEach(function (el) {
                                                var base = el.getAttribute('data-field-key');
                                                if (!base) return;
                                                var newId = 'trpanel-' + langKey + '-' + base + '-' + (++counter);
                                                if (!seen[newId]) { seen[newId] = true; el.id = newId; }
                                                var wrap = el.closest('[data-field-key="' + base + '"]') || el.parentNode;
                                                if (wrap) { wrap.querySelectorAll('label[for]').forEach(function (lb) { lb.setAttribute('for', newId); }); }
                                            });
                                        }
                                        function restoreValues(valuesMap, loc) {
                                            var c = document.getElementById(trModelContentId);
                                            if (!c || !valuesMap) return;
                                            var prefix = namePrefix(loc);
                                            Object.keys(valuesMap).forEach(function (k) {
                                                var name = prefix + k;
                                                var els = c.querySelectorAll('[name="' + name + '"], [name="' + name + '[]"]');
                                                if (!els.length) return;
                                                var v = valuesMap[k];
                                                els.forEach(function (el) {
                                                    var type = (el.getAttribute('type') || '').toLowerCase();
                                                    if (type === 'checkbox') {
                                                        if (Array.isArray(v)) { el.checked = v.indexOf(el.value) !== -1; }
                                                        else { el.checked = !!v && (v === 1 || v === '1' || v === true || v === 'true'); }
                                                    } else if (type === 'radio') {
                                                        el.checked = (el.value == v);
                                                    } else if ((el.tagName || '').toLowerCase() === 'select') {
                                                        if (el.multiple && Array.isArray(v)) { Array.from(el.options).forEach(function (opt) { opt.selected = v.indexOf(opt.value) !== -1; }); }
                                                        else { el.value = v; }
                                                    } else {
                                                        el.value = (v == null ? '' : v);
                                                    }
                                                });
                                            });
                                            Object.keys(valuesMap).forEach(function (k) {
                                                var v = valuesMap[k];
                                                var fixedWraps = c.querySelectorAll('.fixed-field[data-field-key="' + k + '"]');
                                                fixedWraps.forEach(function (wrap) {
                                                    var el = wrap.querySelector('input, textarea, select');
                                                    if (!el) return;
                                                    var type = (el.getAttribute('type') || '').toLowerCase();
                                                    if (type === 'checkbox') { el.checked = !!v && (v === 1 || v === '1' || v === true || v === 'true'); }
                                                    else if (type === 'radio') { var checked = wrap.querySelector('input[type="radio"][value="' + v + '"]'); if (checked) checked.checked = true; }
                                                    else if ((el.tagName || '').toLowerCase() === 'select') { el.value = v; }
                                                    else { el.value = (v == null ? '' : v); }
                                                });
                                            });
                                            c.querySelectorAll('input[type="hidden"]').forEach(function (hid) {
                                                var toggle = document.getElementById(hid.id + '-toggle');
                                                if (toggle) toggle.checked = !!hid.value && (hid.value === '1' || hid.value === 1 || hid.value === true || hid.value === 'true');
                                            });
                                        }
                                        function collectFields(loc) {
                                            var c = document.getElementById(trModelContentId);
                                            var fields = {};
                                            if (!c) return fields;
                                            var prefix = namePrefix(loc);
                                            var fixedKeysAttr = c.getAttribute('data-fixed-keys') || '';
                                            var fixedKeys = fixedKeysAttr ? fixedKeysAttr.split(',').filter(function (s) { return !!s; }) : [];
                                            var isFixedKey = function (k) {
                                                return (fixedKeys.indexOf(k) !== -1) || (k === 'seo_title' || k === 'seo_keywords' || k === 'seo_description');
                                            };
                                            c.querySelectorAll('input, textarea, select').forEach(function (el) {
                                                var name = el.getAttribute('name');
                                                if (!name || name.indexOf(prefix) !== 0) return;
                                                var type = (el.getAttribute('type') || '').toLowerCase();
                                                var key = name.substring(prefix.length).replace(/\[\]$/, '');
                                                if (isFixedKey(key)) return; // 跳过固定字段，避免写入自定义字段翻译表
                                                if (type === 'checkbox') {
                                                    if (name.endsWith('[]')) {
                                                        if (!fields[key]) fields[key] = [];
                                                        if (el.checked) fields[key].push(el.value);
                                                    } else {
                                                        fields[key] = el.checked ? 1 : 0;
                                                    }
                                                } else if (type === 'radio') {
                                                    var checked = c.querySelector('input[type="radio"][name="' + name + '"]:checked');
                                                    fields[key] = checked ? checked.value : '';
                                                } else if ((el.tagName || '').toLowerCase() === 'select') {
                                                    fields[key] = el.multiple ? Array.from(el.selectedOptions).map(function (o) { return o.value; }) : el.value;
                                                } else {
                                                    fields[key] = el.value;
                                                }
                                            });
                                            // 不再将固定字段回填到自定义字段集合
                                            return fields;
                                        }
                                        return {
                                            open: function (loc) {
                                                currentLang = canonicalizeLocale(loc);
                                                editorEl.style.display = 'block';
                                                langSelect.value = currentLang;
                                                disableInputs(false);
                                                assignNamespace(currentLang);
                                                assignUniqueIds(currentLang);
                                            },
                                            restore: function (valuesMap, loc) { restoreValues(valuesMap, loc); },
                                            collect: function (loc) { return collectFields(loc); },
                                            disable: function (flag) { disableInputs(flag); },
                                            prefix: function (loc) { return namePrefix(loc); }
                                        };
                                    })();

                                    function initLangSelect() {
                                        langSelect.innerHTML = '';
                                        Object.keys(LANGS).forEach(function (loc) {
                                            var opt = document.createElement('option');
                                            opt.value = loc;
                                            opt.textContent = (LANGS[loc].name || LANGS[loc].native_name || loc) + ' (' + loc + ')';
                                            langSelect.appendChild(opt);
                                        });
                                    }
                                    var lastStatusMap = {};
                                    function renderLangChips(statusMap) {
                                        if (statusMap) lastStatusMap = statusMap;
                                        else statusMap = lastStatusMap;

                                        listEl.innerHTML = '';

                                        // 获取当前勾选的语言
                                        var enabledLangs = Array.from(document.querySelectorAll('input[name="enabled_languages[]"]:checked')).map(function (cb) { return cb.value; });

                                        var locs = Object.keys(LANGS);
                                        locs.sort(function(a, b) {
                                            var aIsDefault = (a === defaultLang) || !!(LANGS[a] && LANGS[a].default);
                                            var bIsDefault = (b === defaultLang) || !!(LANGS[b] && LANGS[b].default);
                                            if (aIsDefault) return -1;
                                            if (bIsDefault) return 1;
                                            return 0;
                                        });

                                        locs.forEach(function (loc) {
                                            var info = LANGS[loc];
                                            var isDefault = (loc === defaultLang) || !!info.default;

                                            // 如果不是默认语言且未勾选，则不显示
                                            if (!isDefault && enabledLangs.indexOf(loc) === -1) {
                                                return;
                                            }

                                            var status = statusMap[loc] || 'none';
                                            var displayStatus = isDefault ? '主稿' : (status === 'none' ? '↓' : status);
                                            var chip = document.createElement('div');
                                            chip.className = 'lang-chip ' + (isDefault ? 'default' : status);
                                            chip.dataset.lang = loc;
                                            chip.innerHTML = '<span class="code">' + loc + '</span>' +
                                                '<span class="name">' + (info.name || info.native_name || loc) + '</span>' +
                                                '<span class="status">' + displayStatus + '</span>';
                                            chip.addEventListener('click', function () {
                                                if (loc === defaultLang) { TodoModal.toast('默认语言请使用上方编辑区进行编辑。', 'warning'); return; }
                                                TrEditor.open(loc);
												statusSelect.value = (lastStatusMap && lastStatusMap[loc]) ? lastStatusMap[loc] : 'pending';
                                                var fd = new FormData();
                                                fd.append('action', 'get_post_translation');
                                                fd.append('post_id', postId);
                                                fd.append('language_code', loc);
                                                fd.append('_todononce', _todononce);
                                                fetch(ajaxUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', body: fd })
                                                    .then(function (r) { return r.text(); })
                                                    .then(function (txt) {
                                                        var json; try { json = JSON.parse(txt); } catch (e) { console.error('get_post_translation error', txt); return; }
                                                        var tr = (json && json.success) ? json.data : null;
                                                        // 注入临时翻译数据（来自 JSON 导入）
                                                        if (window._temp_translations && window._temp_translations[loc]) {
                                                            tr = tr || {};
                                                            Object.assign(tr, window._temp_translations[loc]);
                                                        }
                                                        titleInput.value = tr && tr.title ? tr.title : '';
                                                        excerptInput.value = tr && tr.excerpt ? tr.excerpt : '';
														statusSelect.value = (tr && tr.status) ? tr.status : ((lastStatusMap && lastStatusMap[loc]) ? lastStatusMap[loc] : 'pending');
                                                        var defaultContent = '';
                                                        try { if (window.UE && UE.getEditor) { var defaultEditor = UE.getEditor('post-content'); if (defaultEditor) defaultContent = defaultEditor.getContent(); } } catch (e) { }
                                                        setTimeout(function () {
                                                            if (window.UE) {
                                                                var trEditor = UE.getEditor('post-translation-content');
                                                                if (trEditor) { trEditor.ready(function () { trEditor.setContent(tr && tr.content ? tr.content : ''); }); }
                                                            } else {
                                                                var ta = document.getElementById('post-translation-content');
                                                                ta.value = tr && tr.content ? tr.content : '';
                                                            }
                                                        }, 50);
                                                        TrEditor.restore({
                                                            'seo_title': (tr && tr.seo_title) ? tr.seo_title : '',
                                                            'seo_keywords': (tr && tr.seo_keywords) ? tr.seo_keywords : '',
                                                            'seo_description': (tr && tr.seo_description) ? tr.seo_description : ''
                                                        }, loc);
                                                    }).catch(function (err) { console.error('get_post_translation error', err); });
                                                var fd2 = new FormData();
                                                fd2.append('action', 'list_post_custom_field_translations');
                                                fd2.append('post_id', postId);
                                                fd2.append('language_code', loc);
                                                fd2.append('_todononce', _todononce);
                                                fetch(ajaxUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', body: fd2 })
                                                    .then(function (r) { return r.text(); })
                                                    .then(function (txt) {
                                                        var json; try { json = JSON.parse(txt); } catch (e) { console.error('list_post_custom_field_translations error', txt); return; }
                                                        var map = (json && json.success) ? (json.data || {}) : {};
                                                        TrEditor.restore(map, loc);
                                                    }).catch(function (err) { console.error('list_post_custom_field_translations error', err); });
                                            });
                                            listEl.appendChild(chip);
                                        });
                                    }
                                    function fetchStatuses() {
                                        var fd = new FormData();
                                        fd.append('action', 'list_post_translations');
                                        fd.append('post_id', postId);
                                        fd.append('_todononce', _todononce);
                                        fetch(ajaxUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', body: fd })
                                            .then(function (r) { return r.text(); })
                                            .then(function (txt) {
                                                var json; try { json = JSON.parse(txt); } catch (e) { console.error('list_post_translations error', txt); return; }
                                                var map = {};
                                                if (json && json.success && Array.isArray(json.data)) {
                                                    json.data.forEach(function (row) { map[row.language_code] = row.status || 'none'; });
                                                }
                                                renderLangChips(map);
                                            }).catch(function (err) { console.error('list_post_translations error', err); });
                                    }

                                    btnCopy.addEventListener('click', function () {
                                        var defaultTitle = '<?php echo esc_js($post->post_title); ?>';
                                        var defaultExcerpt = '<?php echo esc_js($post->post_excerpt); ?>';
                                        var defaultContent = '';
                                        try { if (window.UE && UE.getEditor) { var ed = UE.getEditor('post-content'); if (ed) defaultContent = ed.getContent(); } } catch (e) { }
                                        titleInput.value = defaultTitle || '';
                                        excerptInput.value = defaultExcerpt || '';
                                        if (window.UE && UE.getEditor) { var trEditor = UE.getEditor('post-translation-content'); if (trEditor) trEditor.setContent(defaultContent || ''); }
                                        else { var ta = document.getElementById('post-translation-content'); ta.value = defaultContent || ''; }
                                    });

                                    btnSave.addEventListener('click', function () {
                                        var loc = langSelect.value;
                                        if (!loc || loc === defaultLang) { TodoModal.toast('请选择非默认语言进行保存', 'warning'); return; }
                                        var contentVal = '';
                                        if (window.UE && UE.getEditor) { var ed = UE.getEditor('post-translation-content'); if (ed) contentVal = ed.getContent(); }
                                        else { contentVal = document.getElementById('post-translation-content').value; }
                                        var fields = TrEditor.collect(loc) || {};
                                        var prefix = TrEditor.prefix(loc);
                                        var c = document.getElementById(trModelContentId);
                                        var sys = {};
                                        if (titleInput) { sys['title'] = titleInput.value || ''; }
                                        if (excerptInput) { sys['excerpt'] = excerptInput.value || ''; }
                                        sys['content'] = contentVal || '';
                                        sys['status'] = statusSelect.value || 'pending';
                                        var fixed = {};
                                        var fixedKeysAttr = c ? c.getAttribute('data-fixed-keys') : '';
                                        var fixedKeys = fixedKeysAttr ? fixedKeysAttr.split(',').filter(function (s) { return !!s; }) : [];

                                        fixedKeys.forEach(function (k) {
                                            var el = c ? c.querySelector('[name="' + prefix + k + '"]') : null;
                                            if (!el) return;
                                            var type = (el.getAttribute('type') || '').toLowerCase();
                                            if (type === 'checkbox') { fixed[k] = el.checked ? 1 : 0; }
                                            else if (type === 'radio') {
                                                var checked = c.querySelector('input[type="radio"][name="' + (el.getAttribute('name') || '') + '"]:checked');
                                                fixed[k] = checked ? checked.value : '';
                                            } else if ((el.tagName || '').toLowerCase() === 'select') {
                                                fixed[k] = el.multiple ? Array.from(el.selectedOptions).map(function (o) { return o.value; }) : el.value;
                                            } else { fixed[k] = el.value || ''; }
                                        });
                                        var fd = new FormData();
                                        fd.append('action', 'upsert_post_translation');
                                        fd.append('post_id', postId);
                                        fd.append('language_code', loc);
                                        try { fd.append('system_fields', JSON.stringify(sys)); } catch (e) { fd.append('system_fields', '{}'); }
                                        try { fd.append('fixed_fields', JSON.stringify(fixed)); } catch (e) { fd.append('fixed_fields', '{}'); }
                                        if (sys.hasOwnProperty('title')) { fd.append('title', sys['title']); }
                                        if (sys.hasOwnProperty('excerpt')) { fd.append('excerpt', sys['excerpt']); }
                                        if (sys.hasOwnProperty('content')) { fd.append('content', sys['content']); }
                                        if (sys.hasOwnProperty('status')) { fd.append('status', sys['status']); }
                                        if (fixed.hasOwnProperty('seo_title')) { fd.append('seo_title', fixed['seo_title']); }
                                        if (fixed.hasOwnProperty('seo_keywords')) { fd.append('seo_keywords', fixed['seo_keywords']); }
                                        if (fixed.hasOwnProperty('seo_description')) { fd.append('seo_description', fixed['seo_description']); }
                                        fd.append('_todononce', _todononce);
                                        fetch(ajaxUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', body: fd })
                                            .then(function (r) { return r.text(); })
                                            .then(function (txt) {
                                                var json; try { json = JSON.parse(txt); } catch (e) { TodoModal.toast('网络错误', 'error'); console.error('upsert_post_translation error', txt); return; }
                                                if (json && json.success) {
                                                    var fd2 = new FormData();
                                                    fd2.append('action', 'upsert_post_custom_field_translations');
                                                    fd2.append('post_id', postId);
                                                    fd2.append('language_code', loc);
                                                    fd2.append('status', statusSelect.value || 'pending');
                                                    fd2.append('_todononce', _todononce);
                                                    try { fd2.append('fields', JSON.stringify(fields || {})); } catch (e) { fd2.append('fields', '{}'); }
                                                    fetch(ajaxUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', body: fd2 })
                                                        .then(function (r) { return r.text(); })
                                                        .then(function (txt2) {
                                                            var json2; try { json2 = JSON.parse(txt2); } catch (e) { console.error('upsert_post_custom_field_translations error', txt2); TodoModal.toast('翻译保存成功，但自定义字段保存失败', 'warning'); return; }
                                                            if (json2 && json2.success) { TodoModal.toast('翻译保存成功', 'success'); }
                                                            else { TodoModal.toast('翻译保存成功，但自定义字段保存失败：' + (json2 && json2.message ? json2.message : '未知错误'), 'warning'); }
                                                            editorEl.style.display = 'none';
                                                            TrEditor.disable(true);
                                                            fetchStatuses();
                                                        }).catch(function (err) {
                                                            console.error('upsert_post_custom_field_translations error', err);
                                                            TodoModal.toast('翻译保存成功，但自定义字段保存失败', 'warning');
                                                            editorEl.style.display = 'none';
                                                            TrEditor.disable(true);
                                                            fetchStatuses();
                                                        });
                                                } else {
                                                    TodoModal.toast('保存失败：' + (json && json.message ? json.message : '未知错误'), 'error');
                                                }
                                            }).catch(function (err) { TodoModal.toast('网络错误', 'error'); console.error('upsert_post_translation error', err); });
                                    });

                                    if (btnCopyAll) {
                                        btnCopyAll.addEventListener('click', function () {
                                            var enabledLangs = Array.from(document.querySelectorAll('input[name="enabled_languages[]"]:checked'))
                                                .map(function (cb) { return cb.value; })
                                                .filter(function (loc) { return !!loc && loc !== defaultLang && !!LANGS[loc]; });

                                            if (!enabledLangs.length) { TodoModal.toast('请先在语言开关中勾选需要复制的语言', 'warning'); return; }

                                            var mainTitleEl = document.getElementById('post-title');
                                            var mainExcerptEl = document.getElementById('post-excerpt');
                                            var mainTitle = mainTitleEl ? (mainTitleEl.value || '') : '';
                                            var mainExcerpt = mainExcerptEl ? (mainExcerptEl.value || '') : '';
                                            if (!mainTitle) mainTitle = '<?php echo esc_js($post->post_title); ?>';
                                            if (!mainExcerpt) mainExcerpt = '<?php echo esc_js($post->post_excerpt); ?>';

                                            var mainContent = '';
                                            try {
                                                if (window.UE && UE.getEditor) {
                                                    var mainEd = UE.getEditor('post-content');
                                                    if (mainEd) mainContent = mainEd.getContent();
                                                }
                                            } catch (e) { }
                                            if (!mainContent) {
                                                var mainTa = document.getElementById('post-content');
                                                if (mainTa) mainContent = mainTa.value || '';
                                            }

                                            function collectMainModelFields() {
                                                var map = {};
                                                var form = document.getElementById('post-form');
                                                if (!form) return map;
                                                var els = form.querySelectorAll('input[name^="model_field_"], textarea[name^="model_field_"], select[name^="model_field_"]');
                                                els.forEach(function (el) {
                                                    var name = el.getAttribute('name') || '';
                                                    if (!name) return;
                                                    var isArray = /\[\]$/.test(name) || el.getAttribute('data-array') === '1';
                                                    var baseName = name.replace(/\[\]$/, '');
                                                    var key = baseName.replace(/^model_field_/, '');
                                                    if (!key) return;

                                                    var tag = (el.tagName || '').toLowerCase();
                                                    var type = (el.getAttribute('type') || '').toLowerCase();
                                                    if (type === 'file') return;

                                                    if (type === 'radio') {
                                                        if (map.hasOwnProperty(key)) return;
                                                        var checked = form.querySelector('input[type="radio"][name="' + name.replace(/"/g, '\\"') + '"]:checked');
                                                        map[key] = checked ? checked.value : '';
                                                        return;
                                                    }

                                                    if (type === 'checkbox') {
                                                        if (isArray) {
                                                            if (!Array.isArray(map[key])) map[key] = [];
                                                            if (el.checked) map[key].push(el.value);
                                                        } else {
                                                            map[key] = el.checked ? 1 : 0;
                                                        }
                                                        return;
                                                    }

                                                    if (tag === 'select') {
                                                        if (el.multiple) {
                                                            map[key] = Array.from(el.selectedOptions).map(function (o) { return o.value; });
                                                        } else {
                                                            map[key] = el.value;
                                                        }
                                                        return;
                                                    }

                                                    map[key] = el.value;
                                                });
                                                return map;
                                            }

                                            var mainModelFieldMap = collectMainModelFields();
                                            var fixed = {};
                                            ['seo_title', 'seo_keywords', 'seo_description'].forEach(function (k) {
                                                if (mainModelFieldMap.hasOwnProperty(k)) fixed[k] = mainModelFieldMap[k];
                                            });
                                            var fields = {};
                                            Object.keys(mainModelFieldMap).forEach(function (k) {
                                                if (k === 'seo_title' || k === 'seo_keywords' || k === 'seo_description') return;
                                                fields[k] = mainModelFieldMap[k];
                                            });

                                            var oldText = btnCopyAll.textContent;
                                            btnCopyAll.disabled = true;
                                            btnCopyAll.textContent = '处理中...';

                                            function parseJsonText(txt) {
                                                try { return JSON.parse(txt); } catch (e) { return null; }
                                            }

                                            function upsertOne(loc) {
                                                var sys = { title: mainTitle || '', excerpt: mainExcerpt || '', content: mainContent || '', status: 'approved' };
                                                var fd = new FormData();
                                                fd.append('action', 'upsert_post_translation');
                                                fd.append('post_id', postId);
                                                fd.append('language_code', loc);
                                                try { fd.append('system_fields', JSON.stringify(sys)); } catch (e) { fd.append('system_fields', '{}'); }
                                                try { fd.append('fixed_fields', JSON.stringify(fixed)); } catch (e) { fd.append('fixed_fields', '{}'); }
                                                fd.append('title', sys.title);
                                                fd.append('excerpt', sys.excerpt);
                                                fd.append('content', sys.content);
                                                fd.append('status', sys.status);
                                                if (fixed.hasOwnProperty('seo_title')) fd.append('seo_title', fixed['seo_title']);
                                                if (fixed.hasOwnProperty('seo_keywords')) fd.append('seo_keywords', fixed['seo_keywords']);
                                                if (fixed.hasOwnProperty('seo_description')) fd.append('seo_description', fixed['seo_description']);
                                                fd.append('_todononce', _todononce);
                                                return fetch(ajaxUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', body: fd })
                                                    .then(function (r) { return r.text(); })
                                                    .then(function (txt) {
                                                        var json = parseJsonText(txt);
                                                        if (!json) throw new Error('网络错误');
                                                        if (!json.success) throw new Error(json.message || '保存失败');
                                                        var fd2 = new FormData();
                                                        fd2.append('action', 'upsert_post_custom_field_translations');
                                                        fd2.append('post_id', postId);
                                                        fd2.append('language_code', loc);
                                                        fd2.append('status', 'approved');
                                                        fd2.append('_todononce', _todononce);
                                                        try { fd2.append('fields', JSON.stringify(fields || {})); } catch (e) { fd2.append('fields', '{}'); }
                                                        return fetch(ajaxUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', body: fd2 });
                                                    })
                                                    .then(function (r2) { return r2.text(); })
                                                    .then(function (txt2) {
                                                        var json2 = parseJsonText(txt2);
                                                        if (!json2) throw new Error('网络错误');
                                                        if (!json2.success) throw new Error(json2.message || '自定义字段保存失败');
                                                        return true;
                                                    });
                                            }

                                            var ok = [];
                                            var fail = [];
                                            enabledLangs.reduce(function (p, loc) {
                                                return p.then(function () {
                                                    return upsertOne(loc)
                                                        .then(function () { ok.push(loc); })
                                                        .catch(function (e) { fail.push({ loc: loc, message: (e && e.message) ? e.message : String(e) }); });
                                                });
                                            }, Promise.resolve())
                                                .then(function () {
                                                    btnCopyAll.disabled = false;
                                                    btnCopyAll.textContent = oldText;
                                                    fetchStatuses();
                                                    if (!fail.length) {
                                                        TodoModal.toast('已复制并发布：' + ok.length + ' 个语言', 'success');
                                                        return;
                                                    }
                                                    var msg = '已完成：' + ok.length + ' 个；失败：' + fail.length + ' 个\n' + fail.map(function (x) { return x.loc + '：' + x.message; }).join('\n');
                                                    TodoModal.toast(msg, 'warning');
                                                })
                                                .catch(function () {
                                                    btnCopyAll.disabled = false;
                                                    btnCopyAll.textContent = oldText;
                                                    TodoModal.toast('操作失败', 'error');
                                                });
                                        });
                                    }

                                    btnCancel.addEventListener('click', function () { editorEl.style.display = 'none'; TrEditor.disable(true); });

                                    initLangSelect();
                                    TrEditor.disable(true);
                                    var postForm = document.getElementById('post-form');
                                    if (postForm) { postForm.addEventListener('submit', function () { TrEditor.disable(true); }); }
                                    try {
                                        if (window.UE && UE.getEditor) {
                                            var trEditor = UE.getEditor('post-translation-content', {
                                                serverUrl: ((window.TODO && window.TODO.join) ? window.TODO.join(window.TODO.ADMIN_BASE, 'assets/js/ueditor/php/controller.php') : '/admin/assets/js/ueditor/php/controller.php'),
                                                initialFrameHeight: 240,
                                                autoHeightEnabled: false,
                                                relativeUrl: true
                                            });
                                            window.trUE = trEditor;
                                            trEditor.ready(function () {
                                                window.currentUE = trEditor;
                                                trEditor.addListener('focus', function () { window.currentUE = trEditor; });
                                                trEditor.addListener('click', function () { window.currentUE = trEditor; });
                                                trEditor.addListener('selectionchange', function () { window.currentUE = trEditor; });
                                            });
                                        }
                                    } catch (e) { }

                                    // 初始化全局内存缓存，用于存储当前编辑会话的媒体占位符
                                    window._todo_placeholders = window._todo_placeholders || {};
                                    
                                    // JSON 导出翻译
                                    if (document.getElementById('btn-export-json')) {
                                        document.getElementById('btn-export-json').addEventListener('click', function () {
                                            var fd = new FormData();
                                            fd.append('action', 'export_translation_json');
                                            fd.append('post_id', postId);
                                            fd.append('_todononce', _todononce);

                                            if (window.TodoModal && typeof TodoModal.showLoading === 'function') {
                                                TodoModal.showLoading('正在生成导出文件...');
                                            }

                                            fetch(ajaxUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                                                .then(function (r) { return r.json(); })
                                                .then(function (json) {
                                                    if (window.TodoModal && typeof TodoModal.hideLoading === 'function') {
                                                        TodoModal.hideLoading();
                                                    }
                                                    if (json.success && json.data) {
                                                        // 新增：保存占位符映射到内存缓存和本地存储
                                                        if (json.data.placeholders) {
                                                            window._todo_placeholders[postId] = json.data.placeholders;
                                                            localStorage.setItem('todocms_placeholders_' + postId, JSON.stringify(json.data.placeholders));
                                                        }
                                                        var blob = new Blob([JSON.stringify(json.data, null, 2)], { type: 'application/json' });
                                                        var link = document.createElement('a');
                                                        link.href = URL.createObjectURL(blob);
                                                        link.download = json.filename || ('post-' + postId + '-translation-export.json');
                                                        link.click();
                                                        setTimeout(function() { URL.revokeObjectURL(link.href); }, 100);
                                                    } else {
                                                        TodoModal.toast('导出失败: ' + (json.message || '未知错误'), 'error');
                                                    }
                                                })
                                                .catch(function (err) {
                                                    if (window.TodoModal && typeof TodoModal.hideLoading === 'function') {
                                                        TodoModal.hideLoading();
                                                    }
                                                    console.error(err);
                                                    TodoModal.toast('导出发生错误', 'error');
                                                });
                                        });
                                    }

                                    // JSON 导入翻译
                                    if (document.getElementById('btn-import-json')) {
                                        document.getElementById('btn-import-json').addEventListener('click', function () {
                                            
                                            TodoModal.show({
                                                title: '导入 JSON 翻译',
                                                content: document.getElementById('todo-tpl-import-post-translation-modal').innerHTML,
                                                onConfirm: function (modal) {
                                                    var fileInput = modal.querySelector('input[name="translation_file"]');
                                                    if (!fileInput || !fileInput.files.length) {
                                                        TodoModal.toast('请选择 JSON 文件', 'warning');
                                                        return false;
                                                    }

                                                    var file = fileInput.files[0];
                                                    var reader = new FileReader();
                                                    reader.onload = function (e) {
                                                        var content = e.target.result;
                                                        var fd = new FormData();
                                                        fd.append('action', 'import_translation_json');
                                                        fd.append('post_id', postId);
                                                        fd.append('json_content', content);
                                                        fd.append('_todononce', _todononce);
                                                        
                                                        // 新增：从内存或本地存储尝试读取占位符备份
                                                        var localPlaceholders = (window._todo_placeholders && window._todo_placeholders[postId]) ? 
                                                            JSON.stringify(window._todo_placeholders[postId]) : 
                                                            localStorage.getItem('todocms_placeholders_' + postId);
                                                        
                                                        if (localPlaceholders) {
                                                            fd.append('local_placeholders', localPlaceholders);
                                                        }

                                                        TodoModal.showLoading('正在解析翻译文件...');
                                                        fetch(ajaxUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                                                            .then(function (r) { return r.json(); })
                                                            .then(function (json) {
                                                                TodoModal.hideLoading();
                                                                if (json.success && json.data) {
                                                                    // 注入前清理：将绝对路径还原为相对路径
                                                                    var siteOrigin = window.location.origin;
                                                                    for (var lang in json.data) {
                                                                        var item = json.data[lang];
                                                                        if (item.content) {
                                                                            item.content = item.content.split(siteOrigin + '/').join('/');
                                                                            item.content = item.content.split(siteOrigin).join('');
                                                                        }
                                                                    }

                                                                    window._temp_translations = window._temp_translations || {};
                                                                    Object.assign(window._temp_translations, json.data);
                                                                    
                                                                    TodoModal.toast('解析成功！已注入到本地缓存。点击下方对应的语言标签即可查看注入的翻译内容。请检查无误后点击“保存翻译”入库。', 'success');
                                                                    editorEl.style.display = 'none';
                                                                    TrEditor.disable(true);
                                                                    TodoModal.closeAll();
                                                                    // 刷新列表以显示有变动（可选）
                                                                } else {
                                                                    TodoModal.toast('导入失败: ' + (json.message || '未知错误'), 'error');
                                                                }
                                                            })
                                                            .catch(function (err) {
                                                                TodoModal.hideLoading();
                                                                console.error(err);
                                                                TodoModal.toast('导入请求发生错误', 'error');
                                                            });
                                                    };
                                                    reader.readAsText(file);
                                                    return false; // 异步处理
                                                }
                                            });
                                        });
                                    }

                                    // AI 扩展导出
                                    if (document.getElementById('btn-ai-expand-export')) {
                                        document.getElementById('btn-ai-expand-export').addEventListener('click', function() {
                                            var fd = new FormData();
                                            fd.append('action', 'export_post_ai_expand');
                                            fd.append('post_id', postId);
                                            fd.append('_todononce', _todononce);
                                            TodoModal.showLoading('正在生成 AI 扩展任务文件...');
                                            fetch(ajaxUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                                                .then(function(r) { return r.json(); })
                                                .then(function(json) {
                                                    TodoModal.hideLoading();
                                                    if (json.success && json.data) {
                                                        // 新增：保存占位符映射到内存缓存和本地存储
                                                        if (json.data.placeholders) {
                                                            window._todo_placeholders[postId] = json.data.placeholders;
                                                            localStorage.setItem('todocms_placeholders_' + postId, JSON.stringify(json.data.placeholders));
                                                        }
                                                        var blob = new Blob([JSON.stringify(json.data, null, 2)], { type: 'application/json' });
                                                        var link = document.createElement('a');
                                                        link.href = URL.createObjectURL(blob);
                                                        link.download = json.filename || 'ai-expand.json';
                                                        link.click();
                                                    } else { TodoModal.toast('导出失败: ' + (json.message || '未知错误'), 'error'); }
                                                }).catch(function(err) { TodoModal.hideLoading(); console.error(err); TodoModal.toast('导出错误', 'error'); });
                                        });
                                    }

                                    // AI 创作导出
                                    if (document.getElementById('btn-ai-create-export')) {
                                        document.getElementById('btn-ai-create-export').addEventListener('click', function() {
                                            var fd = new FormData();
                                            fd.append('action', 'export_post_ai_create');
                                            fd.append('post_id', postId);
                                            fd.append('_todononce', _todononce);
                                            TodoModal.showLoading('正在生成 AI 创作任务文件...');
                                            fetch(ajaxUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                                                .then(function(r) { return r.json(); })
                                                .then(function(json) {
                                                    TodoModal.hideLoading();
                                                    if (json.success && json.data) {
                                                        // 新增：保存占位符映射到内存缓存和本地存储
                                                        if (json.data.placeholders) {
                                                            window._todo_placeholders[postId] = json.data.placeholders;
                                                            localStorage.setItem('todocms_placeholders_' + postId, JSON.stringify(json.data.placeholders));
                                                        }
                                                        var blob = new Blob([JSON.stringify(json.data, null, 2)], { type: 'application/json' });
                                                        var link = document.createElement('a');
                                                        link.href = URL.createObjectURL(blob);
                                                        link.download = json.filename || 'ai-create.json';
                                                        link.click();
                                                    } else { TodoModal.toast('导出失败: ' + (json.message || '未知错误'), 'error'); }
                                                }).catch(function(err) { TodoModal.hideLoading(); console.error(err); TodoModal.toast('导出错误', 'error'); });
                                        });
                                    }

                                    // 导入 JSON 创作
                                    if (document.getElementById('btn-ai-import-creation')) {
                                        document.getElementById('btn-ai-import-creation').addEventListener('click', function() {
                                            TodoModal.show({
                                                title: '导入 AI 创作内容',
                                                content: document.getElementById('todo-tpl-import-post-creation-modal').innerHTML,
                                                onConfirm: function(modal) {
                                                    var fileInput = modal.querySelector('input[name="creation_file"]');
                                                    if (!fileInput || !fileInput.files.length) { TodoModal.toast('请选择 JSON 文件', 'warning'); return false; }
                                                    var file = fileInput.files[0];
                                                    var reader = new FileReader();
                                                    reader.onload = function(e) {
                                                        var txt = e.target.result;
                                                        // 简单脱壳
                                                        if (txt.trim().indexOf('```') === 0) {
                                                            var matches = txt.trim().match(/^```(?:json)?\s*(\{.*?\})\s*```$/is);
                                                            if (matches) txt = matches[1];
                                                        }
                                                        var json; try { json = JSON.parse(txt); } catch(err) { TodoModal.toast('JSON 格式错误', 'error'); return; }
                                                        
                                                        // 寻找翻译内容或创作内容
                                                        var data = json.translations ? (json.translations[Object.keys(json.translations)[0]]) : json;
                                                        if (json.items) data = json.items; // 兼容导出结构
                                                        if (data.content || data.title) {
                                                            if (data.title) {
                                                                var titleEl = document.getElementById('post-title');
                                                                if (titleEl) titleEl.value = data.title;
                                                            }
                                                            if (data.excerpt) {
                                                                var excerptEl = document.getElementById('post-excerpt');
                                                                if (excerptEl) excerptEl.value = data.excerpt;
                                                            }
                                                            
                                                            // 回填主内容 SEO 字段
                                                            ['seo_title', 'seo_keywords', 'seo_description'].forEach(function(k) {
                                                                if (data[k]) {
                                                                    // 优先寻找带有 model_field_ 前缀的输入框（文章模型字段组中的 SEO 设置）
                                                                    var els = document.querySelectorAll('[name$="' + k + '"]');
                                                                    els.forEach(function(el) {
                                                                        if (el.name.indexOf('tr_') === -1) { // 排除多语言面板的字段，仅针对主编辑区
                                                                            el.value = data[k];
                                                                        }
                                                                    });
                                                                }
                                                            });
                                                            if (data.content) {
                                                                var content = data.content;
                                                                // 还原占位符
                                                                var placeholders = json.placeholders || {};
                                                                
                                                                // 增强：优先从内存读取，其次从本地存储读取备份
                                                                if (Object.keys(placeholders).length === 0) {
                                                                    if (window._todo_placeholders && window._todo_placeholders[postId]) {
                                                                        placeholders = window._todo_placeholders[postId];
                                                                    } else {
                                                                        var localStored = localStorage.getItem('todocms_placeholders_' + postId);
                                                                        if (localStored) {
                                                                            try {
                                                                                placeholders = JSON.parse(localStored);
                                                                            } catch(e) { console.error('解析本地占位符失败', e); }
                                                                        }
                                                                    }
                                                                }

                                                                if (placeholders && Object.keys(placeholders).length > 0) {
                                                                    // 支持 [[MEDIA_N(alt: ..., title: ...)]] 和 [[MEDIA_N]] 两种格式
                                                                    // 与 PHP 后端逻辑保持一致，不能用简单的字符串替换
                                                                    content = content.replace(/\[\[MEDIA_(\d+)(?:\(alt:\s*([\s\S]*?)\s*(?:,\s*)?title:\s*([\s\S]*?)\))?\]\]/g, function(match, idx, alt, title) {
                                                                        var key = '[[MEDIA_' + idx + ']]';
                                                                        if (!placeholders[key]) return match;
                                                                        var tag = placeholders[key];
                                                                        var altVal   = alt   ? alt.trim().replace(/^['"]|['"]$/g, '')   : '';
                                                                        var titleVal = title ? title.trim().replace(/^['"]|['"]$/g, '') : '';
                                                                        if (altVal) {
                                                                            if (tag.indexOf('alt=') !== -1) tag = tag.replace(/alt=["'][^"']*["']/gi, 'alt="' + altVal.replace(/"/g, '&quot;') + '"');
                                                                            else tag = tag.replace('<img', '<img alt="' + altVal.replace(/"/g, '&quot;') + '"');
                                                                        }
                                                                        if (titleVal) {
                                                                            if (tag.indexOf('title=') !== -1) tag = tag.replace(/title=["'][^"']*["']/gi, 'title="' + titleVal.replace(/"/g, '&quot;') + '"');
                                                                            else tag = tag.replace('<img', '<img title="' + titleVal.replace(/"/g, '&quot;') + '"');
                                                                        }
                                                                        return tag;
                                                                    });
                                                                }
                                                                
                                                                // 清理绝对路径为相对路径
                                                                var siteOrigin = window.location.origin;
                                                                content = content.split(siteOrigin + '/').join('/');
                                                                content = content.split(siteOrigin).join('');

                                                                if (window.ue && typeof window.ue.setContent === 'function') { window.ue.setContent(content); }
                                                                else {
                                                                    var ta = document.getElementById('post-content');
                                                                    if (ta) ta.value = content;
                                                                }
                                                            }
                                                            TodoModal.toast('已成功将 AI 创作内容注入到编辑器，请检查后手动保存/发布文章。', 'success');
                                                            TodoModal.closeAll();
                                                        } else { TodoModal.toast('未在 JSON 中找到有效的内容字段 (title/content)', 'warning'); }
                                                    };
                                                    reader.readAsText(file);
                                                    return false;
                                                }
                                            });
                                        });
                                    }

                                    fetchStatuses();
                                })();
                            </script>
                        </div>
                    </div>
                </div>
            </div>

            <div class="admin-post-sidebar">
                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">发布</h3>
                    </div>
                    <div class="admin-card-content">
                        <?php if (current_user_can('publish_posts')): ?>
                            <div class="admin-post-status">
                                <label for="post-status">状态</label>
                                <?php $disp_status = isset($_POST['post_status']) ? $_POST['post_status'] : $post->post_status; ?>
                                <select name="post_status" id="post-status">
                                    <option value="publish" <?php selected($disp_status, 'publish'); ?>>已发布</option>
                                    <option value="draft" <?php selected($disp_status, 'draft'); ?>>草稿</option>
                                    <option value="pending" <?php selected($disp_status, 'pending'); ?>>等待审核</option>
                                </select>
                            </div>

                            <div class="admin-post-visibility">
                                <label for="post-visibility">可见性</label>
                                <?php
                                $disp_visibility = isset($_POST['post_visibility']) ? $_POST['post_visibility'] : (get_post_meta($post_id, '_visibility', true) ?: 'public');
                                ?>
                                <select name="post_visibility" id="post-visibility">
                                    <option value="public" <?php selected($disp_visibility, 'public'); ?>>公开</option>
                                    <option value="readable" <?php selected($disp_visibility, 'readable'); ?>>可读 (不收录)</option>
                                    <option value="password" <?php selected($disp_visibility, 'password'); ?>>密码保护</option>
                                    <option value="private" <?php selected($disp_visibility, 'private'); ?>>私密</option>
                                </select>

                                <div id="password-visibility-fields"
                                    style="margin-top: 10px; display: <?php echo $disp_visibility === 'password' ? 'block' : 'none'; ?>;">
                                    <label for="post-password">密码</label>
                                    <?php $disp_password = isset($_POST['post_password']) ? $_POST['post_password'] : get_post_meta($post_id, 'post_password', true); ?>
                                    <input type="text" name="post_password" id="post-password"
                                        value="<?php echo esc_attr($disp_password); ?>">
                                </div>
                            </div>
                            <script type="text/javascript">
                                (function () {
                                    var visibility = document.getElementById('post-visibility');
                                    var pwWrap = document.getElementById('password-visibility-fields');
                                    var pwInput = document.getElementById('post-password');
                                    if (!visibility || !pwWrap) return;
                                    function updateVisibilityFields() {
                                        var val = visibility.value;
                                        if (val === 'password') {
                                            pwWrap.style.display = 'block';
                                        } else {
                                            pwWrap.style.display = 'none';
                                            // 可选：切换到非密码时清空输入，避免误提交旧密码
                                            // if (pwInput) { pwInput.value = ''; }
                                        }
                                    }
                                    visibility.addEventListener('change', updateVisibilityFields);
                                    // 初始化一次，确保进入页面时状态正确
                                    updateVisibilityFields();
                                })();
                            </script>

                            <div class="admin-post-schedule">
                                <?php
                                // 计算datetime-local默认值（优先使用元数据）
                                $schedule_default = '';
                                $schedule_checked = false;
                                $meta_enabled = function_exists('get_post_meta') ? get_post_meta($post->ID, '_scheduled_enabled', true) : 0;
                                $meta_time = function_exists('get_post_meta') ? get_post_meta($post->ID, '_scheduled_time', true) : '';

                                if (!empty($meta_enabled) && !empty($meta_time)) {
                                    $schedule_checked = true;
                                    $schedule_default = date('Y-m-d\TH:i', strtotime($meta_time));
                                } elseif ($post->post_status === 'future' && !empty($post->post_date)) {
                                    // 兼容旧逻辑：如果是 future 且 post_date 存在，按 post_date 展示
                                    $schedule_checked = true;
                                    $schedule_default = date('Y-m-d\TH:i', strtotime($post->post_date));
                                }

                                // 若本次提交包含定时控件，优先使用本次提交的值覆盖 UI 状态
                                if (isset($_POST['post_title'])) {
                                    $schedule_checked = !empty($_POST['enable_schedule']);
                                    if (!empty($_POST['schedule_time'])) {
                                        $schedule_default = esc_attr($_POST['schedule_time']);
                                    }
                                }
                                ?>
                                <label>
                                    <input type="checkbox" name="enable_schedule" id="enable-schedule" <?php echo $schedule_checked ? 'checked' : ''; ?>> 定时发布
                                </label>
                                <div id="schedule-time-wrap"
                                    style="margin-top:8px; display: <?php echo esc_attr($schedule_checked ? 'block' : 'none'); ?>;">
                                    <input type="datetime-local" name="schedule_time" id="schedule-time"
                                        value="<?php echo esc_attr($schedule_default); ?>">
                                    <p class="description">设置将来的发布时间（若早于当前时间，保存时将按该时间直接发布）。</p>
                                </div>
                            </div>

                            <script type="text/javascript">
                                (function () {
                                    var enable = document.getElementById('enable-schedule');
                                    var wrap = document.getElementById('schedule-time-wrap');
                                    if (enable && wrap) {
                                        enable.addEventListener('change', function () {
                                            wrap.style.display = this.checked ? 'block' : 'none';
                                        });
                                    }
                                    // 根据文章状态显示/隐藏“定时发布”区块（仅不可见，不清除数据）
                                    var statusSelect = document.getElementById('post-status');
                                    var scheduleContainer = document.querySelector('.admin-post-schedule');
                                    function updateScheduleBlockVisibility() {
                                        if (!scheduleContainer || !statusSelect) return;
                                        if (statusSelect.value === 'publish') {
                                            // 显示整个定时发布区块
                                            scheduleContainer.style.display = '';
                                            // 保持内部显示与复选框一致
                                            if (enable && wrap) {
                                                wrap.style.display = enable.checked ? 'block' : 'none';
                                            }
                                        } else {
                                            // 隐藏区块（不清除输入值）
                                            scheduleContainer.style.display = 'none';
                                        }
                                    }
                                    if (statusSelect) {
                                        statusSelect.addEventListener('change', updateScheduleBlockVisibility);
                                        // 初始化一次
                                        updateScheduleBlockVisibility();
                                    }
                                })();
                            </script>

                            <div class="admin-post-publish-actions">
                                <button type="submit" name="save" value="publish" class="button button-primary">更新</button>
                                <button type="submit" name="save" value="draft" class="button button-secondary"
                                    id="save-draft-button">保存草稿</button>
                            </div>
                        <?php else: ?>
                            <div class="admin-post-status">
                                <label for="post-status">状态</label>
                                <select name="post_status" id="post-status">
                                    <option value="pending" <?php selected($post->post_status, 'pending'); ?>>等待审核</option>
                                    <option value="draft" <?php selected($post->post_status, 'draft'); ?>>草稿</option>
                                </select>
                            </div>

                            <div class="admin-post-publish-actions">
                                <button type="submit" name="save" value="pending"
                                    class="button button-primary">提交审核</button>
                                <button type="submit" name="save" value="draft" class="button button-secondary"
                                    id="save-draft-button">保存草稿</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="admin-card categoriesbox">
                    <div class="admin-card-header no-border">
                        <h3 class="admin-card-title">分类目录</h3>
                    </div>
                    <div class="admin-card-content category-box">
                        <?php
                        // 渲染层级分类选择器
                        $__todo_post_edit_t_cat_selector = microtime(true);
                        render_hierarchical_categories_selector(array(
                            'name' => 'post_category[]',
                            'id' => 'post-categories',
                            'selected' => $selected_categories,
                            'multiple' => true,
                            'show_checkbox' => true,
                            'class' => 'hierarchical-categories-selector',
                            'description' => '选择文章所属的分类目录。',
                            'restrict_to_same_model' => true  // 启用同模型限制
                        ));
                        if (class_exists('Todo_Performance_Monitor')) {
                            Todo_Performance_Monitor::add_metric('post_edit_category_selector', 'render category selector', (microtime(true) - $__todo_post_edit_t_cat_selector) * 1000);
                        }
                        ?>
                        <p style="min-height: 22px;">
                        <?php if (current_user_can('manage_categories')): ?>
                            <a href="<?php echo todo_admin_url('index.php?page=categories&action=add'); ?>">+ 添加新分类目录</a>
                        <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="admin-card">
                    <div class="admin-card-header no-border">
                        <h3 class="admin-card-title">标签</h3>
                    </div>
                    <div class="admin-card-content">
                        <textarea name="post_tags" id="post-tags" rows="3" class="regular-text" style="width: 100%;" placeholder="多个标签请用中/英逗号分隔"><?php echo esc_textarea($post_tags); ?></textarea>
                        <p class="description">每篇控制在 3~5 个 精准标签即可。</p>

                        <?php
                        $__todo_post_edit_t_tags_component = microtime(true);
                        render_suggested_tags_component(10, 'post-tags');
                        if (class_exists('Todo_Performance_Monitor')) {
                            Todo_Performance_Monitor::add_metric('post_edit_suggested_tags', 'render suggested tags', (microtime(true) - $__todo_post_edit_t_tags_component) * 1000);
                        }
                        ?>
                    </div>
                </div>

                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">特色图片</h3>
                    </div>
                    <div class="admin-card-content">
                        <div class="admin-post-status">
                            <?php
                            // 加载特色图像组件
                            if (function_exists('todo_render_featured_images_component')) {
                                // 从 posts.featured_image 字段读取特色图片数据（JSON格式）
                                $current_featured_images = array();
                                if (!empty($post->featured_image)) {
                                    $decoded = json_decode($post->featured_image, true);
                                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                        $current_featured_images = $decoded;
                                    }
                                }

                                // 配置特色图片组件
                                $featured_images_config = array(
                                    'field_id' => 'post_featured_images',
                                    'field_name' => 'featured_images',
                                    'current_images' => $current_featured_images,
                                    'multiple' => true,
                                    'max_images' => 6,
                                    'enable_debug' => false
                                );

                                $__todo_post_edit_t_featured = microtime(true);
                                echo todo_render_featured_images_component($featured_images_config);
                                if (class_exists('Todo_Performance_Monitor')) {
                                    Todo_Performance_Monitor::add_metric('post_edit_featured_images', 'render featured images', (microtime(true) - $__todo_post_edit_t_featured) * 1000);
                                }
                                echo '<p class="description">最多可为文章设置6张特色图片。</p>';
                            } else {
                                echo '<p class="description">特色图片组件加载失败。</p>';
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <div class="admin-card">
                    <div class="admin-card-content">
                        <?php if (current_user_can('edit_others_posts')): ?>
                            <div class="admin-post-status">
                                <label for="post-author">作者</label>
                                <select name="post_author" id="post-author">
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?php echo esc_attr($user->ID); ?>" <?php selected($post->post_author, $user->ID); ?>>
                                            <?php echo esc_html($user->display_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="admin-post-status">
                            <label for="comment-status">评论</label>
                            <select name="comment_status" id="comment-status">
                                <option value="open" <?php selected($post->comment_status, 'open'); ?>>开启评论</option>
                                <option value="closed" <?php selected($post->comment_status, 'closed'); ?>>关闭评论</option>
                            </select>
                        </div>
                        <div class="admin-post-status">
                            <label for="read_permission">阅读权限</label>
                            <?php
                            $level_manager = function_exists('todo_get_user_level_manager') ? todo_get_user_level_manager() : null;
                            $user_levels = $level_manager ? $level_manager->get_user_levels(array('is_active' => 1)) : array();
                            ?>
                            <select name="read_permission" id="read_permission">
                                <option value="public" <?php selected($post->read_permission, 'public'); ?>>公开</option>
                                <?php if (!empty($user_levels)): ?>
                                    <?php foreach ($user_levels as $lvl): ?>
                                        <option value="<?php echo htmlspecialchars($lvl->level_slug); ?>" <?php selected($post->read_permission, $lvl->level_slug); ?>>
                                            <?php echo htmlspecialchars($lvl->level_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="member" <?php selected($post->read_permission, 'member'); ?>>会员</option>
                                    <option value="vip" <?php selected($post->read_permission, 'vip'); ?>>高级会员</option>
                                <?php endif; ?>
                                <option value="admin" <?php selected($post->read_permission, 'admin'); ?>>管理员</option>
                            </select>
                        </div>
                        <div class="admin-post-status">
                            <label for="views_boost" class="flex items-center justify-between">浏览统计<span style="font-size: 12px; color: #a0a0aa;">真实访问：<b><?php echo esc_attr(isset($post->view_count) ? (int) $post->view_count : 0); ?></b></span></label>
                            <input type="number" name="views_boost" id="views_boost" min="0" step="1" value="<?php echo esc_attr($views_boost); ?>">
                        </div>
                        <div class="admin-post-status">
                            <label class="nofor">
                                <span style="display: block;">视图模版</span>
                                <select name="view_template" id="view-template"
                                    data-current-template="<?php echo esc_attr(isset($post->view_template) ? basename($post->view_template) : ''); ?>">
                                    <?php
                                    // 同步 post-new 的"视图模版"逻辑：按分类所属模型列出 view_模型标识*.html
                                    $default_template_shown = false;

                                    // 计算当前选中的视图模版：优先文章自身，其次继承主分类设置的 view_template（使用带扩展名的完整文件名）
                                    $selected_view_template = '';
                                    if (isset($post->view_template) && !empty($post->view_template)) {
                                        $selected_view_template = basename($post->view_template);
                                    }

                                    // 从文章分类推断分类默认模板
                                    $post_categories = get_post_categories($post->ID);
                                    if (empty($selected_view_template) && !empty($post_categories)) {
                                        $primary = $post_categories[0];
                                        $primary_id = is_array($primary) ? ($primary['id'] ?? null) : (isset($primary->id) ? $primary->id : $primary);
                                        if ($primary_id) {
                                            $category_default_view = get_term_meta(intval($primary_id), 'cat_view_template', true);
                                            if (!empty($category_default_view)) {
                                                $selected_view_template = basename($category_default_view);
                                            }
                                        }
                                    }

                                    // 根据主分类的模型，列出模板文件
                                    if (!empty($post_categories)) {
                                        $primary = $post_categories[0];
                                        $primary_id = is_array($primary) ? ($primary['id'] ?? null) : (isset($primary->id) ? $primary->id : $primary);
                                        $category_model = $primary_id ? Todo_Model_Manager::get_category_model(intval($primary_id)) : null;

                                        if ($category_model) {
                                            $theme_dir = get_template_directory();
                                            if (is_dir($theme_dir)) {
                                                $__todo_post_edit_t_templates = microtime(true);
                                                $template_files = glob($theme_dir . '/view_' . $category_model . '*.html') ?: [];
                                                if (class_exists('Todo_Performance_Monitor')) {
                                                    Todo_Performance_Monitor::add_metric('post_edit_view_templates', 'glob view templates', (microtime(true) - $__todo_post_edit_t_templates) * 1000);
                                                }

                                                // 计算“默认模版”对应的值：优先取分类设置的内容模板（不带扩展名），否则为模型默认模板名
                                                $cat_default_view = get_term_meta(intval($primary_id), 'cat_view_template', true);
                                                $default_value_name = '';
                                                if (!empty($cat_default_view)) {
                                                    $default_value_name = pathinfo($cat_default_view, PATHINFO_FILENAME);
                                                } else {
                                                    $default_value_name = 'view_' . $category_model;
                                                }

                                                // 输出“使用默认模板”选项（值为模型默认模板名，不带扩展名，如 view_article），并按需选中
                                                $default_model_value = 'view_' . $category_model;
                                                if ($selected_view_template === '') {
                                                    $selected_view_template = $default_model_value;
                                                }
                                                $default_selected_attr = ($selected_view_template === $default_model_value) ? ' selected="selected"' : '';
                                                echo '<option value="' . esc_attr($default_model_value) . '"' . $default_selected_attr . '>默认模版</option>';

                                                // 添加其它模板选项，跳过与模型默认模板同名的文件，选项值使用完整文件名（含扩展名），显示完整文件名
                                                foreach ($template_files as $template_file) {
                                                    $template_name = pathinfo($template_file, PATHINFO_FILENAME);
                                                    $template_display = basename($template_file); // 显示完整文件名
                                    
                                                    if ($template_name === 'view_' . $category_model) {
                                                        // 避免与“使用默认模板”重复（模型默认模板文件）
                                                        continue;
                                                    }

                                                    $selected_attr = ($selected_view_template === $template_display) ? ' selected="selected"' : '';
                                                    echo '<option value="' . esc_attr($template_display) . '"' . $selected_attr . '>' . esc_html($template_display) . '</option>';
                                                }
                                                $default_template_shown = true;
                                            }
                                        }
                                    }

                                    // 如果没有选择分类或没有找到模版，显示通用选项
                                    if (empty($post_categories) || !$default_template_shown) {
                                        $default_selected_attr = ($selected_view_template === '') ? ' selected="selected"' : '';
                                        echo '<option value=""' . $default_selected_attr . '>默认模版</option>';

                                        // 如果没有选择分类，显示所有可用的视图模版（值使用完整文件名用于选中对比）
                                        if (empty($post_categories)) {
                                            $theme_dir = get_template_directory();
                                            if (is_dir($theme_dir)) {
                                                $__todo_post_edit_t_templates_all = microtime(true);
                                                $all_view_files = glob($theme_dir . '/view_*.html') ?: [];
                                                if (class_exists('Todo_Performance_Monitor')) {
                                                    Todo_Performance_Monitor::add_metric('post_edit_view_templates_all', 'glob all view templates', (microtime(true) - $__todo_post_edit_t_templates_all) * 1000);
                                                }
                                                foreach ($all_view_files as $template_file) {
                                                    $template_display = basename($template_file);
                                                    $selected_attr = ($selected_view_template === $template_display) ? ' selected="selected"' : '';
                                                    echo '<option value="' . esc_attr($template_display) . '"' . $selected_attr . '>' . esc_html($template_display) . '</option>';
                                                }
                                            }
                                        }
                                    }
                                    ?>
                                </select>
                            </label>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </form>
</div>

<style>
    /* 编辑文章页面特定样式 */
    .admin-post-form-wrap {
        display: flex;
        flex-wrap: wrap;
        margin: 0 -10px;
    }

    .mbody .admin-post-form-wrap {
        margin: 340px -30px 0;
    }

    .mbody .admin-post-form-wrap .admin-card {
        border-radius: 0;
    }

    .admin-post-main {
        flex: 1 500px;
        padding: 0 10px;
        min-width: 0;
        /* 防止flex子项溢出 */
    }

    .admin-post-sidebar {
        width: 280px;
        padding: 0 10px;
    }

    .mbody .admin-post-sidebar {
        flex: 1;
        display: flex;
        flex-direction: column-reverse;
    }

    .mbody .admin-post-sidebar .categoriesbox {
        position: absolute;
        top: 75px;
        left: 0;
        right: 0;
    }

    .admin-post-title-input {
        width: 100%;
        padding: 12px;
        color: #282832 !important;
        font-weight: 500;
        font-size: 1.7em;
        line-height: 1.3;
        border: 1px dashed #eceef1;
        box-shadow: none;
        background-color: transparent;
        margin: 0 0 15px;
        outline: none;
    }

    .admin-post-title-input:focus {
        border-color: #0073aa;
    }

    /* 永久链接编辑器样式 */
    .permalink-display {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        font-size: 14px;
    }

    .permalink-url {
        color: #0073aa;
        font-family: monospace;
        word-break: break-all;
    }

    .permalink-edit-btn {
        color: #0073aa;
        text-decoration: none;
        border: none;
        background: none;
        cursor: pointer;
        padding: 0;
        font-size: 13px;
    }

    .permalink-edit-btn:hover {
        text-decoration: underline;
    }

    .permalink-input-group {
        display: flex;
        align-items: center;
        margin-bottom: 10px;
        border: 1px solid #eceef1;
        border-radius: 4px;
        background: white;
        overflow: hidden;
    }

    .permalink-prefix,
    .permalink-suffix {
        padding: 8px 10px;
        color: #6c6c75;
        font-family: monospace;
        font-size: 13px;
        white-space: nowrap;
    }

    .permalink-input {
        flex: 1;
        border: none !important;
        outline: none;
        font-family: monospace;
    }

    .permalink-actions {
        display: flex;
        gap: 8px;
        margin-bottom: 5px;
    }

    .admin-post-content-editor,
    .admin-post-excerpt-editor {
        width: 100%;
        min-height: 300px;
        border: 1px solid #eceef1;
        padding: 10px;
    }

    .admin-post-excerpt-editor {
        min-height: 60px;
    }

    .admin-post-status,
    .admin-post-visibility {
        margin-bottom: 15px;
    }

    .admin-post-status:last-child,
    .admin-post-visibility:last-child {
        margin-bottom: 0;
    }

    .admin-post-publish-actions {
        display: flex;
        justify-content: space-between;
        margin-top: 20px;
    }

    /* 分类选择器样式由通用组件提供 */
    .post-categories-selector {
        margin: 10px 0;
    }

    #featured-image-preview {
        margin-bottom: 10px;
        min-height: 100px;
        border: 1px dashed #eceef1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        color: #999;
    }

    #featured-image-preview:empty:before {
        content: "无预览图片";
    }

    #featured-image-preview img {
        max-width: 100%;
        max-height: 200px;
    }

    /* 编辑器增强样式 */
    .editor-toolbar-wrapper {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }

    .editor-meta-info {
        display: flex;
        gap: 15px;
        font-size: 12px;
        color: #666;
        align-items: center;
    }

    .editor-status {
        display: flex;
        gap: 15px;
        font-size: 12px;
        padding-top: 10px;
    }

    /* 全屏编辑样式 */
    .editor-container.fullscreen {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 9999;
        background: white;
        padding: 20px;
        overflow: auto;
    }

    .editor-container.fullscreen #post-content {
        height: calc(100vh - 200px);
        min-height: calc(100vh - 200px);
    }

    body.editor-fullscreen {
        overflow: hidden;
    }

    /* 自动草稿提示样式 */
    .auto-draft-notice {
        margin: 0 0 8px;
        padding: 8px 12px;
        background-color: #e3f2fd;
        border: 1px solid #bbdefb;
        border-radius: 4px;
        font-size: 12px;
    }

    .auto-draft-notice .text-info {
        color: #1976d2;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 5px;
    }

    .auto-draft-notice .text-danger {
        text-decoration: none;
        margin-left: auto;
    }

    .auto-draft-notice .text-danger:hover {
        text-decoration: underline;
    }

    /* 响应式调整 */
    @media (max-width: 768px) {
        .editor-toolbar-wrapper {
            flex-direction: column;
            gap: 10px;
            align-items: flex-start;
        }

        .editor-meta-info {
            order: 1;
        }

        .editor-status {
            flex-direction: column;
            gap: 5px;
            align-items: baseline;
        }

        .auto-draft-notice {
            font-size: 11px;
            padding: 6px 10px;
        }
    }

    /* 表格样式 */
    .table {
        width: 100%;
        border-collapse: collapse;
        margin: 15px 0;
        font-size: 14px;
    }

    .table th,
    .table td {
        padding: 8px 12px;
        text-align: left;
        border-bottom: 1px solid #eceef1;
    }

    .seo-settings {
        background: #fff;
        border: 1px solid #eceef1;
        border-radius: 4px;
        margin-bottom: 20px;
    }

    .seo-settings .card-header {
        background: #f9f9f9;
        border-bottom: 1px solid #eceef1;
        padding: 12px 15px;
        font-weight: 500;
        color: #333;
    }

    .seo-settings .card-body {
        padding: 15px;
    }

    .character-counter {
        font-weight: 500;
    }

    .seo-auto-update {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 10px;
    }

    .seo-auto-update input[type="checkbox"] {
        margin: 0;
    }

    .seo-auto-update label {
        margin: 0;
        font-weight: normal;
        cursor: pointer;
    }

    .table th {
        background-color: #f8f9fa;
        font-weight: 500;
        border-bottom: 2px solid #dee2e6;
    }

    .table-striped tbody tr:nth-child(odd) {
        background-color: #f9f9f9;
    }

    .table-bordered {
        border: 1px solid #dee2e6;
    }

    .mbody .mobile-table-bordered {
        overflow: auto;
        margin: 0px -15px 0px -30px;
    }

    .mbody .mobile-table-bordered table {
        margin-left: 30px;
        margin-right: 20px;
    }

    .table-bordered th,
    .table-bordered td {
        border: 1px solid #dee2e6;
    }

    .table-hover tbody tr:hover {
        background-color: #f5f5f5;
    }

    .todo-product-type-wrap > div div:first-child label {
        border: 1px solid #7eadd8;
        color: #006ebf;
        padding: 6px 20px 4px;
        border-radius: 3px;
    }
    .todo-product-type-wrap > div div:last-child label {
        border: 1px solid #d9c099;
        color: #c19100;
        padding: 6px 20px 4px;
        border-radius: 3px;
    }
</style>

<?php
// 注册页面特定脚本
todo_enqueue_script('ueditor-config', todo_admin_url('assets/js/ueditor/ueditor.config.js'), array(), '1.0');
todo_enqueue_script('ueditor-all', todo_admin_url('assets/js/ueditor/ueditor.all.js'), array('ueditor-config'), '1.0');
todo_enqueue_script('ueditor-lang', todo_admin_url('assets/js/ueditor/lang/zh-cn/zh-cn.js'), array('ueditor-all'), '1.0');
?>

<!-- 输出页面特定脚本 -->
<?php todo_print_scripts(false); ?>

<script type="text/javascript">
    // 文章编辑页面脚本
    // 全局变量
    var autoSaveTimer;
    var hasChanges = false;
    var autoSaveStatus;
    var lastSaved;
    var ajaxUrl = '<?php echo todo_admin_url('ajax/post-ajax.php'); ?>';

    // 全局函数：打开图片列表对话框
    window.OpenImagesList = function (event, element, multiple = true) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }

        // 根据来源元素或当前活动编辑器，解析目标UE实例
        function resolveEditorFromEvent(el) {
            if (!el || !window.UE || !UE.getEditor) return null;
            try {
                // 1. 优先尝试从 UEditor 实例池中匹配包含该元素的实例（最可靠）
                if (UE.instants) {
                    for (var id in UE.instants) {
                        var inst = UE.instants[id];
                        if (inst && inst.container && (inst.container == el || inst.container.contains(el))) {
                            return inst;
                        }
                    }
                }
                // 2. 兼容支持closest的浏览器，优先查找UE容器
                if (el.closest) {
                    var container = el.closest('#post-content, #post-translation-content, .todo-editor, .form-control.todo-editor');
                    if (container && container.id) {
                        return UE.getEditor(container.id);
                    }
                }
                // 3. 回退：逐层向上查找包含的容器id
                var node = el;
                var maxDepth = 50;
                while (node && node !== document && maxDepth-- > 0) {
                    if (node.id && (node.id === 'post-content' || node.id === 'post-translation-content' || node.id.indexOf('field_') === 0 || node.id.indexOf('editbox_') === 0)) {
                        return UE.getEditor(node.id);
                    }
                    node = node.parentNode;
                }
            } catch (e) { }
            return null;
        }

        var editorInstance = resolveEditorFromEvent(element) || window.currentUE || window.ue || window.editor || null;
        
        if (!editorInstance) {
            console.error('OpenImagesList: 找不到编辑器实例');
            TodoModal.toast('编辑器未就绪，请稍后再试', 'warning');
            return false;
        }

        // 检查编辑器是否已准备就绪
        if (!editorInstance.isReady) {
            // 等待编辑器就绪后再执行
            setTimeout(function () {
                window.OpenImagesList(event, element, multiple);
            }, 500);
            return false;
        }

        // 使用TodoCMS媒体模态框
        if (typeof window.selectMediaForField === 'function') {
            window.selectMediaForField({
                title: multiple ? '选择多张图片' : '选择图片',
                button_text: '插入图片',
                multiple: multiple,
                type: 'image',
                callback: function (selectedMedia) {
                    var mediaArray = Array.isArray(selectedMedia) ? selectedMedia : (selectedMedia ? [selectedMedia] : []);
                    if (mediaArray.length === 0) return;

                    var html = '';
                    mediaArray.forEach(function (media) {
                        if (media && media.url) {
                            if (typeof window.formatMediaHtmlForEditor === 'function') {
                                html += window.formatMediaHtmlForEditor(media);
                            } else {
                                var title = media.title || media.alt || media.filename || media.name || '';
                                var isImage = (media.type && media.type.indexOf('image/') === 0) || /\.(jpg|jpeg|png|gif|webp|bmp|svg)$/i.test(media.url || '');
                                if (isImage) {
                                    html += '<img src="' + media.url + '" alt="' + title + '" title="' + title + '" />';
                                } else {
                                    html += '<a href="' + media.url + '" rel="nofollow" target="_blank" title="' + title + '">' + (title || media.url) + '</a>';
                                }
                            }
                        }
                    });

                    if (html) {
                        try {
                            // 确保编辑器焦点
                            editorInstance.focus();
                            // 插入HTML内容
                            editorInstance.execCommand('inserthtml', html);
                        } catch (err) {
                            console.error('插入图片时出错:', err);
                            TodoModal.toast('插入图片失败，请重试', 'error');
                        }
                    }
                }
            });
        } else {
            // 降级到UEditor原生对话框
            if (editorInstance && editorInstance.execCommand) {
                editorInstance.execCommand('insertimage');
            } else {
                console.error('UEditor实例未找到，无法打开图片对话框');
                TodoModal.toast('编辑器未就绪，请稍后再试', 'warning');
            }
        }

        return false;
    };

    // 初始化通知关闭功能
    function initNotices() {
        // 自动隐藏成功通知
        var successNotices = document.querySelectorAll('.notice-success');
        successNotices.forEach(function (notice) {
            setTimeout(function () {
                if (notice && notice.style.display !== 'none') {
                    notice.style.opacity = '0';
                    notice.style.transition = 'opacity 0.5s ease';
                    setTimeout(function () {
                        notice.style.display = 'none';
                    }, 500);
                }
            }, 10000); // 10秒后自动隐藏
        });
    }

    // 初始化模型变更检测
    function initModelChangeDetection() {
        // 获取分类选择器和表单元素
        const categoryInputs = document.querySelectorAll('#post-categories input[type="checkbox"], #post-categories_wrapper input[type="checkbox"]');
        const postForm = document.getElementById('post-form');

        if (!categoryInputs.length || !postForm) {
            return;
        }

        // 获取原始选中的分类及其模型
        const originalSelectedCategories = [];
        const originalModels = new Set();

        categoryInputs.forEach(input => {
            if (input.checked) {
                const categoryId = input.value;
                const listItem = input.closest('li');
                const modelKey = listItem ? listItem.getAttribute('data-model-key') : null;

                originalSelectedCategories.push({
                    id: categoryId,
                    modelKey: modelKey
                });

                if (modelKey) {
                    originalModels.add(modelKey);
                }
            }
        });

        // 监听表单提交
        postForm.addEventListener('submit', function (e) {
            // 表单提交前同步编辑器内容
            if (window.UE && (window.UE.instants || window.UE.instances)) {
                var instants = window.UE.instants || window.UE.instances;
                for (var key in instants) {
                    if (Object.prototype.hasOwnProperty.call(instants, key)) {
                        try {
                            instants[key].sync();
                        } catch (err) {
                            console.warn('UEditor sync failed for ' + key, err);
                        }
                    }
                }
            } else if (window.ue && typeof window.ue.hasContents === 'function' && window.ue.hasContents()) {
                var pcEl = document.getElementById('post-content');
                if (pcEl) {
                    pcEl.value = window.ue.getContent();
                }
            }

            // 获取当前选中的分类及其模型
            const currentSelectedCategories = [];
            const currentModels = new Set();

            categoryInputs.forEach(input => {
                if (input.checked) {
                    const categoryId = input.value;
                    const listItem = input.closest('li');
                    const modelKey = listItem ? listItem.getAttribute('data-model-key') : null;

                    currentSelectedCategories.push({
                        id: categoryId,
                        modelKey: modelKey
                    });

                    if (modelKey) {
                        currentModels.add(modelKey);
                    }
                }
            });

            // 检查是否有模型变更
            const hasModelChange = !areSetsEqual(originalModels, currentModels);

	            if (hasModelChange && currentSelectedCategories.length > 0 && !postForm.__todo_model_change_confirmed) {
                e.preventDefault();
                e.stopImmediatePropagation();
                e.stopPropagation();

                var restoreSubmit = function () {
                    try {
                        if (postForm.__todo_original_submit) {
                            postForm.submit = postForm.__todo_original_submit;
	                            delete postForm.__todo_original_submit;
                        }
                    } catch (e) {
                    }
                };

	                var submitForm = function () {
	                    try {
	                        if (typeof postForm.requestSubmit === 'function') {
	                            postForm.requestSubmit();
	                            return;
	                        }
	                    } catch (e) {
	                    }
	                    var ev = null;
	                    try {
	                        ev = new Event('submit', { bubbles: true, cancelable: true });
	                    } catch (e) {
	                        try {
	                            ev = document.createEvent('Event');
	                            ev.initEvent('submit', true, true);
	                        } catch (e2) {
	                            ev = null;
	                        }
	                    }
	                    try {
	                        if (ev && postForm.dispatchEvent(ev) === false) {
	                            return;
	                        }
	                    } catch (e) {
	                    }
	                    try {
	                        postForm.submit();
	                    } catch (e) {
	                    }
	                };

                try {
                    if (!postForm.__todo_original_submit) {
                        postForm.__todo_original_submit = postForm.submit;
                        postForm.submit = function () {
                        };
                    }
                } catch (e) {
                }

                // 构建确认消息
                let confirmMessage = '检测到数据模型变更！\n\n';

                if (originalModels.size > 0) {
                    confirmMessage += '原模型: ' + Array.from(originalModels).join(', ') + '\n';
                }

                if (currentModels.size > 0) {
                    confirmMessage += '新模型: ' + Array.from(currentModels).join(', ') + '\n';
                }

                confirmMessage += '\n变更数据模型将会迁移原模型字段数据至新模型中。\n';
                confirmMessage += '确定要继续保存吗？';

                // 显示确认对话框
                var _confirmModal = TodoModal.confirm({
                    message: confirmMessage,
                    onConfirm: function () {
                        // 用户确认，添加标记字段并重新提交
                        let modelChangeField = postForm.querySelector('input[name="model_changed"]');
                        if (!modelChangeField) {
                            modelChangeField = document.createElement('input');
                            modelChangeField.type = 'hidden';
                            modelChangeField.name = 'model_changed';
                            postForm.appendChild(modelChangeField);
                        }
                        modelChangeField.value = '1';

                        // 添加原始模型和新模型信息
                        let originalModelsField = postForm.querySelector('input[name="original_models"]');
                        if (!originalModelsField) {
                            originalModelsField = document.createElement('input');
                            originalModelsField.type = 'hidden';
                            originalModelsField.name = 'original_models';
                            postForm.appendChild(originalModelsField);
                        }
                        originalModelsField.value = Array.from(originalModels).join(',');

                        let newModelsField = postForm.querySelector('input[name="new_models"]');
                        if (!newModelsField) {
                            newModelsField = document.createElement('input');
                            newModelsField.type = 'hidden';
                            newModelsField.name = 'new_models';
                            postForm.appendChild(newModelsField);
                        }
                        newModelsField.value = Array.from(currentModels).join(',');

	                        postForm.__todo_model_change_confirmed = true;
	                        restoreSubmit();
                        try {
                            if (typeof TodoModal !== 'undefined' && TodoModal.restoreScroll) {
                                TodoModal.restoreScroll();
                            }
                        } catch (e) {
                        }

                        setTimeout(function () {
	                            submitForm();
                        }, 0);
                    },
                    onCancel: function () {
                        restoreSubmit();
                        try {
                            if (typeof TodoModal !== 'undefined' && TodoModal.restoreScroll) {
                                TodoModal.restoreScroll();
                            }
                        } catch (e) {
                        }
                    }
                });

                try {
                    if (_confirmModal) {
	                        var onCloseHandler = function () {
                            restoreSubmit();
                            try {
                                if (typeof TodoModal !== 'undefined' && TodoModal.restoreScroll) {
                                    TodoModal.restoreScroll();
                                }
                            } catch (e) {
                            }
	                        };
	                        if (typeof _confirmModal.onCloseCallback === 'function') {
	                            _confirmModal.onCloseCallback(onCloseHandler);
	                        } else {
	                            _confirmModal.onClose = onCloseHandler;
	                        }
                    }
                } catch (e) {
                }
            }
        });

        // 辅助函数：比较两个Set是否相等
        function areSetsEqual(set1, set2) {
            if (set1.size !== set2.size) {
                return false;
            }

            for (let item of set1) {
                if (!set2.has(item)) {
                    return false;
                }
            }

            return true;
        }
    }

    // 页面加载完成后初始化
    document.addEventListener('DOMContentLoaded', function () {
        initUEditor();
        initAutoSave();
        initNotices();
        initModelChangeDetection();
    });

    // 初始化UEditor编辑器
    function initUEditor() {
        // 检查容器是否存在
        var container = document.getElementById('post-content');
        if (!container) {
            console.error('找不到post-content元素');
            return;
        }

        // 修复iframe跨框架调用时UEditor的Mask组件访问错误
        // 在UEditor初始化之前就保护UI组件
        if (window.parent && window.parent !== window) {
            // 等待UEditor加载后再保护
            var protectUEditorUI = function() {
                try {
                    // 保护UEditor的Mask组件
                    if (window.UE && window.UE.ui && window.UE.ui.Mask) {
                        var originalIsHidden = window.UE.ui.Mask.prototype.isHidden;
                        if (!originalIsHidden._protected) {
                            window.UE.ui.Mask.prototype.isHidden = function() {
                                try {
                                    // 确保DOM元素存在再访问style
                                    if (this.getDom && typeof this.getDom === 'function') {
                                        var dom = this.getDom();
                                        if (!dom) {
                                            return true; // 如果DOM不存在，认为是隐藏的
                                        }
                                    }
                                    return originalIsHidden.call(this);
                                } catch (e) {
                                    console.warn('UEditor Mask.isHidden error (suppressed):', e.message);
                                    return true; // 出错时默认返回隐藏状态
                                }
                            };
                            window.UE.ui.Mask.prototype.isHidden._protected = true;
                        }
                    }

                    // 保护baidu.editor命名空间的Mask组件
                    if (window.baidu && window.baidu.editor && window.baidu.editor.ui && window.baidu.editor.ui.Mask) {
                        var baiduOriginalIsHidden = window.baidu.editor.ui.Mask.prototype.isHidden;
                        if (!baiduOriginalIsHidden._protected) {
                            window.baidu.editor.ui.Mask.prototype.isHidden = function() {
                                try {
                                    if (this.getDom && typeof this.getDom === 'function') {
                                        var dom = this.getDom();
                                        if (!dom) {
                                            return true;
                                        }
                                    }
                                    return baiduOriginalIsHidden.call(this);
                                } catch (e) {
                                    console.warn('Baidu Editor Mask.isHidden error (suppressed):', e.message);
                                    return true;
                                }
                            };
                            window.baidu.editor.ui.Mask.prototype.isHidden._protected = true;
                        }
                    }
                } catch (protectError) {
                    console.warn('Failed to protect UEditor UI components:', protectError);
                }
            };

            // 尝试立即保护，如果UEditor已加载
            protectUEditorUI();
            
            // 如果还没加载，在初始化后再次尝试
            setTimeout(protectUEditorUI, 100);
        }

        // 将HTML实体解码为可编辑的HTML，确保UEditor能解析转义后的内容
        function decodeHtmlEntities(str) {
            if (!str) return '';
            var textarea = document.createElement('textarea');
            textarea.innerHTML = str;
            return textarea.value;
        }

        // 获取post-content元素的宽度，如果获取失败则默认为500
        var postContentElement = document.getElementById('post-content');
        // 在初始化UEditor之前，将textarea的值从实体形式解码为真实HTML
        try {
            var originalVal = postContentElement && typeof postContentElement.value === 'string' ? postContentElement.value : '';
            var decodedVal = decodeHtmlEntities(originalVal);
            // 仅在确实发生变化时再写回，避免触发不必要的事件
            if (decodedVal !== originalVal) {
                postContentElement.value = decodedVal;
            }
        } catch (e) {
            console.warn('解码HTML实体时出错:', e);
        }

        var initialWidth = postContentElement ? postContentElement.offsetWidth : 500;

        // 初始化UEditor
        window.ue = UE.getEditor('post-content', {
            initialFrameWidth: initialWidth,
            initialFrameHeight: 400,
            autoHeightEnabled: false,
            toolbars: [[
                'fullscreen', 'source', '|', 'undo', 'redo', '|',
                'bold', 'italic', 'underline', 'fontborder', 'strikethrough', 'superscript', 'subscript', 'removeformat', 'formatmatch', 'autotypeset', 'blockquote', 'pasteplain', '|', 'forecolor', 'backcolor', 'insertorderedlist', 'insertunorderedlist', '|',
                'rowspacingtop', 'rowspacingbottom', 'lineheight',
                'customstyle', 'paragraph', 'fontfamily', 'fontsize',
                'directionalityltr', 'directionalityrtl', 'indent', '|',
                'justifyleft', 'justifycenter', 'justifyright', 'justifyjustify', '|',
                'link', 'unlink', 'anchor', '|', 'imagenone', 'imageleft', 'imageright', 'imagecenter', '|',
                'simpleupload', 'insertimage', 'emotion', 'insertvideo', 'map', 'gmap', 'insertframe', 'insertcode', 'pagebreak', 'template', '|',
                'horizontal', 'date', 'time', 'spechars', '|',
                'inserttable', 'deletetable', 'insertparagraphbeforetable', 'insertrow', 'deleterow', 'insertcol', 'deletecol', 'mergecells', 'mergeright', 'mergedown', 'splittocells', 'splittorows', 'splittocols', '|',
                'print', 'preview', 'searchreplace', 'drafts', 'previewmobile'
            ]],
            elementPathEnabled: false,
            wordCount: true,
            maximumWords: 50000,
            // 图片上传配置
            serverUrl: ((window.TODO && window.TODO.join) ? window.TODO.join(window.TODO.ADMIN_BASE, 'assets/js/ueditor/php/controller.php') : '/admin/assets/js/ueditor/php/controller.php'),
            relativeUrl: true,
            // 添加分类ID参数
            serverParam: {
                'category_id': function () {
                    // 获取当前文章的分类ID
                    var categorySelect = document.querySelector('select[name="post_category"]');
                    return categorySelect ? categorySelect.value : null;
                }
            },
            // 允许的文件类型
            allowDivTransToP: false,
            // 粘贴配置
            retainOnlyLabelPasted: false,
            pasteplain: false,
            // 自动保存 - 已禁用，使用TodoCMS自己的自动保存机制
            enableAutoSave: false,
            saveInterval: 30000, // 30秒自动保存一次（已禁用）
            // 多图上传
            insertImageUrlPrefix: '',
            // 工具栏浮动
            autoFloatEnabled: false
        });

        // 编辑器准备就绪后的回调
        window.ue.ready(function () {
            // 强制 UEditor 在获取内容时使用相对路径的输出规则
            window.ue.addOutputRule(function (root) {
                var siteUrl = (window.TODO && window.TODO.SITE_URL) ? window.TODO.SITE_URL.replace(/\/+$/, '') : window.location.origin;
                var siteHost = '';
                try { siteHost = new URL(siteUrl).host; } catch(e) {}

                root.traversal(function (node) {
                    if (node.type == 'element' && (node.tagName == 'img' || node.tagName == 'a')) {
                        var attr = node.tagName == 'img' ? 'src' : 'href';
                        var url = node.getAttr(attr);
                        if (url && url.indexOf('http') === 0) {
                            try {
                                var u = new URL(url);
                                if (u.host === siteHost) {
                                    var rel = u.pathname + u.search + u.hash;
                                    // 如果有站点前缀逻辑，可以在这里进一步处理
                                    node.setAttr(attr, rel);
                                }
                            } catch (e) {}
                        }
                    }
                });
            });

            // 兼容旧逻辑，同时记录当前活动编辑器为主编辑器
            window.editor = window.ue;
            window.currentUE = window.ue;

            // 在不同的交互下，始终将当前编辑器指向主编辑器
            window.ue.addListener('focus', function () { window.currentUE = window.ue; });
            window.ue.addListener('click', function () { window.currentUE = window.ue; });
            window.ue.addListener('selectionchange', function () { window.currentUE = window.ue; });

            // 模式切换时忽略内容变化，防止点击“源代码”触发自动保存
            window.ue.addListener('beforeexeccommand', function (type, cmd) {
                if (cmd === 'source') {
                    window.ue.__todo_is_source_toggling = true;
                }
            });
            window.ue.addListener('afterexeccommand', function (type, cmd) {
                if (cmd === 'source') {
                    // 稍微延迟恢复，确保由切换模式引起的同步 contentChange 已被忽略
                    setTimeout(function() {
                        window.ue.__todo_is_source_toggling = false;
                    }, 200);
                }
            });

            // 监听内容变化，用于自动保存
            window.ue.addListener('contentChange', function (e) {
                // 如果正在切换源码模式，或者当前处于源码模式，跳过自动保存
                if (window.ue.__todo_is_source_toggling || window.ue.queryCommandState('source') === 1) {
                    return;
                }
                // 表单提交过程中不触发自动保存，避免与提交过程竞争
                if (window.__todo_is_submitting) {
                    return;
                }

                markAsChanged();
                // 延迟执行自动保存，避免频繁保存
                clearTimeout(window.autoSaveTimer);
                window.autoSaveTimer = setTimeout(function () {
                    autoSaveDraft();
                }, 5000); // 5秒后自动保存
            });

            // 页面卸载前保存
            window.addEventListener('beforeunload', function (e) {
                if (hasChanges && !window.__todo_is_submitting) {
                    autoSaveDraft();
                    e.preventDefault();
                    e.returnValue = '您有未保存的更改，确定要离开吗？';
                    return e.returnValue;
                }
            });
        });
    }

    // 自动保存功能
    function initAutoSave() {
        var titleInput = document.getElementById('post-title');

        // 清除自动保存草稿
        function clearAutoDraft() {
            var formData = new FormData();
            formData.append('action', 'clear_auto_draft');
            formData.append('post_id', document.querySelector('input[name="post_id"]') ? document.querySelector('input[name="post_id"]').value : '');
            formData.append('_todononce', document.querySelector('input[name="_todononce"]') ? document.querySelector('input[name="_todononce"]').value : '');

            fetch(ajaxUrl, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: formData
            })
                .then(function (resp) { return resp.text(); })
                .then(function (txt) {
                    var data; try { data = JSON.parse(txt); } catch (e) { console.error('清除草稿失败:', txt); return; }
                    if (data.success) {
                        // 隐藏自动草稿提示
                        var notice = document.querySelector('.auto-draft-notice');
                        if (notice) {
                            notice.style.display = 'none';
                        }
                        
                        // 显示成功提示
                        if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils && TodoCMS.Utils.showMessage) {
                            TodoCMS.Utils.showMessage('草稿已清除', 'success');
                        }
                        setTimeout(function () {
                            window.location.reload();
                        }, 1000);
                    } else {
                        // 显示错误提示
                        if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils && TodoCMS.Utils.showMessage) {
                            TodoCMS.Utils.showMessage(data.message || '清除草稿失败', 'error');
                        }
                    }
                })
                .catch(error => {
                    console.error('清除草稿失败:', error);
                    if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils && TodoCMS.Utils.showMessage) {
                        TodoCMS.Utils.showMessage('清除草稿失败', 'error');
                    }
                });
        }

        // 绑定清除草稿按钮事件
        var clearDraftBtn = document.getElementById('clear-auto-draft');
        if (clearDraftBtn) {
            clearDraftBtn.addEventListener('click', function (e) {
                e.preventDefault();
                TodoModal.confirm({
                    title: '清除草稿',
                    message: '确定要清除自动保存的草稿吗？',
                    confirmText: '确定',
                    cancelText: '取消',
                    onConfirm: function () {
                        clearAutoDraft();
                        return true; // 允许关闭弹窗
                    }
                });
            });
        }
    }

    // 标记内容已更改
    function markAsChanged() {
        hasChanges = true;
        updateAutoSaveStatus('有未保存的更改');
    }

    // 更新自动保存状态
    function updateAutoSaveStatus(message) {
        var statusElement = document.getElementById('auto-save-status');
        if (statusElement) {
            statusElement.textContent = '自动保存: ' + message;
        }
    }

    // 自动保存草稿
    function autoSaveDraft() {
        if (!hasChanges) return;
        // 提交中不触发自动保存，避免与表单提交竞争 session 写入
        if (window.__todo_is_submitting) return;

        var title = document.getElementById('post-title').value;
        var content = '';

        // 获取编辑器内容
        if (window.ue && window.ue.hasContents()) {
            content = window.ue.getContent();
        } else {
            content = document.getElementById('post-content').value;
        }

        var formData = new FormData();
        formData.append('action', 'auto_save');
        formData.append('title', title);
        formData.append('content', content);
        formData.append('post_id', document.querySelector('input[name="post_id"]').value);
        formData.append('_todononce', document.querySelector('input[name="_todononce"]').value);

        updateAutoSaveStatus('正在保存...');

        fetch(ajaxUrl, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: formData
        })
            .then(function (resp) { return resp.text(); })
            .then(function (txt) {
                var data; try { data = JSON.parse(txt); } catch (e) { console.error('自动保存失败:', txt); updateAutoSaveStatus('保存失败'); return; }
                if (data.success) {
                    hasChanges = false;
                    var now = new Date();
                    var timeString = now.getHours().toString().padStart(2, '0') + ':' +
                        now.getMinutes().toString().padStart(2, '0') + ':' +
                        now.getSeconds().toString().padStart(2, '0');
                    updateAutoSaveStatus('已保存');
                    var lastSavedElement = document.getElementById('last-saved');
                    if (lastSavedElement) {
                        lastSavedElement.textContent = '最后保存: ' + (data.saved_at_full || data.time || data.saved_at || timeString);
                    }
                } else {
                    updateAutoSaveStatus('保存失败');
                }
            })
            .catch(error => {
                console.error('自动保存失败:', error);
                updateAutoSaveStatus('保存失败');
            });
    }

    // 上传图片到服务器的函数
    function uploadImageToServer(file, callback) {
        var formData = new FormData();
        formData.append('upfile', file); // 使用UEditor配置的字段名

        // 使用fetch API上传文件
        fetch(((window.TODO && window.TODO.join) ? window.TODO.join(window.TODO.ADMIN_BASE, 'assets/js/ueditor/php/controller.php') : '/admin/assets/js/ueditor/php/controller.php') + '?action=uploadimage', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (result) {
                if (result.state === 'SUCCESS') {
                    // 上传成功，调用回调函数
                    callback({
                        success: true,
                        url: result.url,
                        title: result.title || file.name
                    });
                } else {
                    // 上传失败
                    callback({
                        success: false,
                        message: result.state || '上传失败'
                    });
                }
            })
            .catch(function (error) {
                console.error('上传错误:', error);
                callback({
                    success: false,
                    message: '网络错误'
                });
            });
    }

    // 永久链接编辑器功能
    function initPermalinkEditor() {
        var originalCustomUrl = '<?php echo esc_js($post->custom_url); ?>';
        var editBtn = document.getElementById('permalink-edit-btn');
        var saveBtn = document.getElementById('permalink-save-btn');
        var cancelBtn = document.getElementById('permalink-cancel-btn');
        var displayDiv = document.querySelector('.permalink-display');
        var editForm = document.getElementById('permalink-edit-form');
        var urlSpan = document.querySelector('.permalink-url');
        var customUrlInput = document.getElementById('custom-url');

        if (!editBtn || !saveBtn || !cancelBtn) return;

        if (!customUrlInput) {
            console.warn('initPermalinkEditor: #custom-url not found');
        }

        // 编辑按钮点击事件
        editBtn.addEventListener('click', function () {
            if (displayDiv) displayDiv.style.display = 'none';
            if (editForm) editForm.style.display = 'block';
            if (customUrlInput) customUrlInput.focus();
        });

        // 保存按钮点击事件
        saveBtn.addEventListener('click', function () {
            var customUrl = customUrlInput ? customUrlInput.value.trim() : '';
            var siteUrl = '<?php echo get_option('site_url', todo_get_site_url()); ?>';
            var postName = '<?php echo esc_js($post->post_name); ?>';

            // 更新隐藏字段的值
            var hiddenField = document.getElementById('custom-url-hidden');
            if (hiddenField) {
                hiddenField.value = customUrl;
            }

            // 更新显示的URL（按固定链接结构预览）
            var permalinkStructure = '<?php echo esc_js(get_option('permalink_structure', '')); ?>';
            var postId = <?php echo (int) $post->ID; ?>;
            var postDateStr = '<?php echo esc_js($post->post_date ?: date('Y-m-d H:i:s')); ?>';
            var authorSlug = '<?php echo esc_js((function_exists("get_user_by") && ($a = get_user_by('id', $post->post_author))) ? ($a->user_login ?? "unknown") : "unknown"); ?>';
            var categorySlug = '<?php echo esc_js((function_exists("get_the_terms") && ($terms = get_the_terms($post->ID, "category")) && is_array($terms) && ($first = reset($terms)) && is_object($first) && !empty($first->slug)) ? $first->slug : "uncategorized"); ?>';
            function pad2(n) { return n < 10 ? ('0' + n) : ('' + n); }
            function buildDisplayUrl(customUrl) {
                customUrl = customUrl || '';
                // 外链直接显示
                if (/^(https?:)?\/\//i.test(customUrl)) {
                    return customUrl;
                }
                if (!permalinkStructure) {
                    return siteUrl + '/?p=' + postId;
                }
                var d = new Date(postDateStr.replace(/-/g, '/'));
                var year = d.getFullYear();
                var month = pad2(d.getMonth() + 1);
                var day = pad2(d.getDate());
                var hour = pad2(d.getHours());
                var minute = pad2(d.getMinutes());
                var second = pad2(d.getSeconds());
                var slug = customUrl || postName;
                var p = permalinkStructure;
                p = p.replace('%year%', year).replace('%monthnum%', month).replace('%day%', day)
                    .replace('%hour%', hour).replace('%minute%', minute).replace('%second%', second)
                    .replace('%post_id%', postId).replace('%postname%', slug)
                    .replace('%category%', categorySlug).replace('%author%', authorSlug);
                if (p.charAt(0) !== '/') { p = '/' + p; }
                p = p.replace(/\/+$/, '');
                if (!/\.html$/i.test(p)) { p = p + '.html'; }
                return siteUrl + p;
            }
            var displayUrl = buildDisplayUrl(customUrl);
            if (urlSpan) urlSpan.textContent = displayUrl;

            // 隐藏编辑表单，显示URL显示
            if (editForm) editForm.style.display = 'none';
            if (displayDiv) displayDiv.style.display = 'block';

            // 自动保存文章（触发表单提交）
            if (typeof autoSaveDraft === 'function') {
                autoSaveDraft();
            } else {
                // 如果没有自动保存函数，显示提示
                var notice = document.createElement('div');
                notice.className = 'notice notice-info is-dismissible';
                notice.innerHTML = '<p>永久链接已更新，请保存文章以使更改生效。</p>';
                var form = document.querySelector('.admin-post-form-wrap');
                if (form) {
                    form.insertBefore(notice, form.firstChild);
                    // 5秒后自动移除提示
                    setTimeout(function () {
                        if (notice.parentNode) {
                            notice.parentNode.removeChild(notice);
                        }
                    }, 5000);
                }
            }
        });

        // 取消按钮点击事件
        cancelBtn.addEventListener('click', function () {
            // 恢复原始值
            if (customUrlInput) customUrlInput.value = originalCustomUrl;

            // 隐藏编辑表单，显示URL显示
            if (editForm) editForm.style.display = 'none';
            if (displayDiv) displayDiv.style.display = 'block';
        });

        // 输入框回车事件
        if (customUrlInput) {
            customUrlInput.addEventListener('keypress', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    saveBtn.click();
                }
            });

            // 输入框ESC事件
            customUrlInput.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    cancelBtn.click();
                }
            });
        }
    }

    // 更新视图模版选项（可选传入已选栏目ID数组，以便响应 jQuery 组件的 model-fields-updated 事件）
    function updateViewTemplates(selectedCategoriesProvided) {
        const categoryInputs = document.querySelectorAll('.hierarchical-categories-selector input.category-input:checked');
        const selectedCategories = Array.isArray(selectedCategoriesProvided) ? selectedCategoriesProvided : Array.from(categoryInputs).map(input => input.value);
        const viewTemplateSelect = document.getElementById('view-template');

        if (!viewTemplateSelect) {
            return;
        }

        // 如果没有选择分类，清空模版选项
        if (selectedCategories.length === 0) {
            viewTemplateSelect.innerHTML = '<option value="" selected>默认模版</option>';
            return;
        }

        // 发送AJAX请求获取视图模版
        const formData = new FormData();
        formData.append('action', 'get_view_templates');
        // 以逗号分隔字符串传递，确保后端一致按字符串解析
        formData.append('category_ids', selectedCategories.join(','));
        formData.append('_todononce', document.querySelector('input[name="_todononce"]') ? document.querySelector('input[name="_todononce"]').value : '');

        fetch(todoAjax.ajaxurl, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: formData
        })
            .then(function (resp) { return resp.text(); })
            .then(function (text) {
                var data;
                try { data = JSON.parse(text); } catch (e) {
                    console.error('获取视图模版失败:', text);
                    return;
                }
                if (data.success) {
                    // 保存当前选中的值与服务端保存值（data-current-template 来源于 PHP 渲染）
                    const currentValue = viewTemplateSelect.value;
                    const savedTemplate = (viewTemplateSelect.dataset.currentTemplate || '').trim();

                    // 清空现有选项并添加“默认模版”（值优先取分类设置的内容模板，不带扩展名；否则回退为模型默认模板名，如 view_article）
                    const defaultValue = data.default ? data.default : (data.model ? ('view_' + data.model) : '');
                    viewTemplateSelect.innerHTML = '';
                    const defaultOption = document.createElement('option');
                    defaultOption.value = defaultValue;
                    defaultOption.textContent = '默认模版';
                    viewTemplateSelect.appendChild(defaultOption);

                    // 状态：文章保存值是否为默认值（如 view_article）
                    const savedIsDefault = !!savedTemplate && savedTemplate === defaultValue;

                    // 添加新的模版选项（值使用完整文件名，含扩展名）
                    let selectionTarget = null;
                    if (savedIsDefault) {
                        // 数据库中就是模型默认值 → 选中“默认模版”
                        selectionTarget = defaultValue;
                    } else if (savedTemplate) {
                        // 优先使用文章已保存的模板值（完整文件名或不带扩展名）
                        selectionTarget = savedTemplate;
                    } else if (currentValue && /\.html?$/i.test(currentValue)) {
                        // 次选：用户之前在下拉框中选择了具体文件
                        selectionTarget = currentValue;
                    } else if (data.category_default_file) {
                        // 再次：栏目默认内容模板（完整文件名）
                        selectionTarget = data.category_default_file;
                    } else {
                        // 回退：模型默认模板（不带扩展名）
                        selectionTarget = defaultValue;
                    }

                    let matchedFile = false;
                    const options = [];
                    data.templates.forEach(template => {
                        const option = document.createElement('option');
                        option.value = template.file;
                        option.textContent = template.display;
                        if (selectionTarget && selectionTarget === template.file) {
                            option.selected = true;
                            matchedFile = true;
                        }
                        viewTemplateSelect.appendChild(option);
                        options.push(option);
                    });

                    // 若按完整文件名未命中，且文章保存值不是默认值，再尝试按不带扩展名的“基名”匹配（.htm/.html差异容错）
                    if (!matchedFile && !savedIsDefault) {
                        const tryBases = [];
                        if (savedTemplate) {
                            tryBases.push(savedTemplate.replace(/\.(html?|htm)$/i, ''));
                        }
                        if (data.category_default_file) {
                            tryBases.push(data.category_default_file.replace(/\.(html?|htm)$/i, ''));
                        }
                        // 去重
                        const bases = Array.from(new Set(tryBases.filter(Boolean)));
                        for (const base of bases) {
                            for (const opt of options) {
                                const optBase = opt.value.replace(/\.(html?|htm)$/i, '');
                                if (optBase === base) {
                                    opt.selected = true;
                                    matchedFile = true;
                                    selectionTarget = opt.value; // 采用列表中实际存在的扩展名
                                    break;
                                }
                            }
                            if (matchedFile) break;
                        }
                    }

                    // 如果目标是默认值或未找到匹配文件，则选中“默认模版”
                    if (!selectionTarget || selectionTarget === defaultValue || !matchedFile || savedIsDefault) {
                        defaultOption.selected = true;
                        // 明确设置下拉框的值为默认项，保证 selected 状态在所有浏览器一致
                        viewTemplateSelect.value = defaultValue;
                    } else {
                        // 明确设置下拉框当前值为匹配的文件，确保 selected 生效
                        viewTemplateSelect.value = selectionTarget;
                    }
                }
            })
            .catch(error => {
                console.error('获取视图模版失败:', error);
            });
    }

    // 监听分类选择变化
    function initCategoryChangeHandler() {
        const categoryInputs = document.querySelectorAll('.hierarchical-categories-selector input.category-input');
        categoryInputs.forEach(input => {
            input.addEventListener('change', function () {
                // 延迟执行，确保其他逻辑（比如模型字段刷新）先执行
                setTimeout(() => updateViewTemplates(), 100);
            });
        });
    }

    function initSuperCopyButton() {
        var btn = document.getElementById('todo-super-copy');
        if (!btn) return;

        var storageKey = 'todocms_supercopy_post_form_v1';

        function show(msg, type) {
            TodoCMS.Utils.showMessage(msg, type || 'info');
        }

        function serializeForm(form) {
            try {
                var contentEl = document.getElementById('post-content');
                if (contentEl && window.ue && typeof window.ue.getContent === 'function') {
                    contentEl.value = window.ue.getContent();
                }

                // 同步所有动态 UEditor 实例以确保内容能被 FormData 抓取
                if (window.UE && (window.UE.instants || window.UE.instances)) {
                    var instants = window.UE.instants || window.UE.instances;
                    for (var key in instants) {
                        if (Object.prototype.hasOwnProperty.call(instants, key)) {
                            var inst = instants[key];
                            if (inst && typeof inst.sync === 'function') {
                                try {
                                    inst.sync();
                                } catch (e) {}
                            }
                        }
                    }
                }
            } catch (e) {
            }

            var fd = new FormData(form);
            var obj = {};
            fd.forEach(function (value, key) {
                if (key === '_todononce') return;
                if (value instanceof File) return;
                if (Object.prototype.hasOwnProperty.call(obj, key)) {
                    if (!Array.isArray(obj[key])) obj[key] = [obj[key]];
                    obj[key].push(value);
                } else {
                    obj[key] = key.slice(-2) === '[]' ? [value] : value;
                }
            });
            return obj;
        }

        btn.addEventListener('click', function () {
            var form = document.getElementById('post-form') || document.querySelector('form');
            if (!form) {
                show('未找到表单，无法复制', 'error');
                return;
            }

            var postId = '';
            try {
                var pidEl = form.querySelector('input[name="post_id"]');
                postId = pidEl ? (pidEl.value || '') : '';
            } catch (e) {
            }

            var payload = {
                v: 1,
                savedAt: Date.now(),
                source: {
                    postId: postId,
                    url: String(window.location.href || '')
                },
                data: serializeForm(form)
            };

            try {
                sessionStorage.setItem(storageKey, JSON.stringify(payload));
                show('已保存到本地浏览器，关闭浏览器自动销毁', 'success');
            } catch (e) {
                show('保存失败：浏览器存储空间不足或被限制', 'error');
            }
        });
    }

    function initEditorQuickActions() {
        const chkSaveRemote = document.getElementById('chk-save-remote-images');
        const chkClearLinks = document.getElementById('chk-clear-external-links');
        const form = document.getElementById('post-form');

        if (!form || !chkSaveRemote || !chkClearLinks) return;

        let isProcessing = false;
        let submitType = null; // 记录点击了哪个提交按钮

        // 清理多余属性功能绑定
        const btnCleanFormat = document.getElementById('btn-clean-format');
        if (btnCleanFormat) {
            btnCleanFormat.addEventListener('click', function() {
                let content = '';
                if (window.ue && typeof window.ue.getContent === 'function') {
                    content = window.ue.getContent();
                } else {
                    const editorEl = document.getElementById('post-content');
                    content = editorEl ? editorEl.value : '';
                }
                if (!content) return;

                const parser = new DOMParser();
                const doc = parser.parseFromString(content, 'text/html');
                const elements = doc.body.getElementsByTagName('*');
                
                const allowedAttrs = {
                    'DIV': ['class'],
                    'IMG': ['src', 'alt', 'title', 'width', 'height'],
                    'A': ['href', 'title', 'target', 'rel'],
                    'VIDEO': ['src', 'controls', 'width', 'height', 'poster', 'autoplay', 'loop', 'muted'],
                    'AUDIO': ['src', 'controls', 'autoplay', 'loop'],
                    'SOURCE': ['src', 'type'],
                    'IFRAME': ['src', 'width', 'height', 'frameborder', 'allowfullscreen'],
                    'TD': ['colspan', 'rowspan'],
                    'TH': ['colspan', 'rowspan'],
                    'TABLE': ['border']
                };

                for (let i = 0; i < elements.length; i++) {
                    const el = elements[i];
                    const tagName = el.tagName;
                    const allowed = allowedAttrs[tagName] || [];
                    
                    // 倒序遍历并移除不在白名单中的属性
                    for (let j = el.attributes.length - 1; j >= 0; j--) {
                        const attrName = el.attributes[j].name.toLowerCase();
                        if (attrName === 'class' && tagName === 'DIV') {
                            const classList = Array.from(el.classList);
                            const preservedClasses = classList.filter(c => c === 'image-wrapper' || c === 'image-caption');
                            if (preservedClasses.length > 0) {
                                el.className = preservedClasses.join(' ');
                                continue;
                            }
                        }
                        if (!allowed.includes(attrName)) {
                            el.removeAttribute(attrName);
                        }
                    }
                }

                const finalContent = doc.body.innerHTML;
                if (window.ue && typeof window.ue.setContent === 'function') {
                    window.ue.setContent(finalContent);
                } else {
                    const editorEl = document.getElementById('post-content');
                    if (editorEl) editorEl.value = finalContent;
                }

                TodoModal.toast('清理格式成功，已去除多余样式及属性', 'success');
            });
        }

        // 监听所有提交按钮的点击，记录点击的是哪个
        form.querySelectorAll('input[type="submit"], button[type="submit"]').forEach(btn => {
            btn.addEventListener('click', function() {
                submitType = { name: btn.name, value: btn.value };
            });
        });

        form.addEventListener('submit', function(e) {
            // 如果已经在处理中，允许第二次提交直接通过
            if (isProcessing) return;

            // 设置提交中标识，防止由于编辑器同步内容导致 hasChanges 变回 true 从而触发浏览器弹窗
            window.__todo_is_submitting = true;
            hasChanges = false;
            
            // 清除任何待处理的自动保存定时器，防止提交过程中意外触发自动保存草稿
            if (window.autoSaveTimer) {
                clearTimeout(window.autoSaveTimer);
                window.autoSaveTimer = null;
            }
            
            // 如果两个都没勾选，直接通过
            if (!chkSaveRemote.checked && !chkClearLinks.checked) return;

            let content = '';
            if (window.ue && typeof window.ue.getContent === 'function') {
                content = window.ue.getContent();
            } else {
                const editorEl = document.getElementById('post-content');
                content = editorEl ? editorEl.value : '';
            }

            // 内容为空则不处理
            if (!content || content.trim() === '') return;

            // 开始拦截并处理
            e.preventDefault();
            isProcessing = true;

            if (window.TodoModal && typeof TodoModal.showLoading === 'function') {
                TodoModal.showLoading('正在处理文章内容，请稍候...');
            }

            // 使用 DOMParser 解析 HTML，确保处理健壮，避免正则匹配标签的局限性
            const parser = new DOMParser();
            const doc = parser.parseFromString(content, 'text/html');
            const homeUrl = window.location.origin;

            // 1. 清除外链 (同步处理)
            if (chkClearLinks.checked) {
                const links = Array.from(doc.querySelectorAll('a'));
                links.forEach(a => {
                    const href = a.getAttribute('href');
                    if (href && href.startsWith('http')) {
                        if (!href.startsWith(homeUrl)) {
                            // 是外链，用文本节点替换 a 标签
                            const fragment = document.createDocumentFragment();
                            while (a.firstChild) {
                                fragment.appendChild(a.firstChild);
                            }
                            a.parentNode.replaceChild(fragment, a);
                        }
                    }
                });
            }

            // 2. 保存远程媒体 (异步请求)
            if (chkSaveRemote.checked) {
                const mediaEls = Array.from(doc.querySelectorAll('img, video, audio, source, iframe, embed, object'));
                const remoteUrls = [];
                const elUrlMap = new Map();

                mediaEls.forEach(el => {
                    const url = el.getAttribute('src');
                    if (url && url.startsWith('http')) {
                        if (!url.startsWith(homeUrl)) {
                            if (!remoteUrls.includes(url)) {
                                remoteUrls.push(url);
                            }
                            // 记录这个元素依赖哪个 url，方便等会替换
                            elUrlMap.set(el, url);
                        }
                    }
                });

                if (remoteUrls.length > 0) {
                    const fd = new FormData();
                    fd.append('action', 'save_remote_images');
                    fd.append('urls', JSON.stringify(remoteUrls));
                    fd.append('post_id', document.querySelector('input[name="post_id"]')?.value || 0);
                    
                    const nonceInput = document.querySelector('input[name="_todononce"]');
                    if (nonceInput) fd.append('_todononce', nonceInput.value);

                    fetch((typeof ajaxUrl !== 'undefined' ? ajaxUrl : 'ajax/post-ajax.php'), {
                        method: 'POST',
                        body: fd,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                    .then(r => r.json())
                    .then(json => {
                        if (json.success && json.data) {
                            // json.data 格式为 { "原始URL": "新URL" }
                            elUrlMap.forEach((originalUrl, el) => {
                                if (json.data[originalUrl]) {
                                    el.setAttribute('src', json.data[originalUrl]);
                                }
                            });
                            // 特殊处理 href 指向原图的 a 标签
                            const links = Array.from(doc.querySelectorAll('a'));
                            links.forEach(a => {
                                const href = a.getAttribute('href');
                                if (href && json.data[href]) {
                                    a.setAttribute('href', json.data[href]);
                                }
                            });
                        }
                        finish(doc.body.innerHTML);
                    })
                    .catch(err => {
                        console.error('Remote images save failed:', err);
                        finish(doc.body.innerHTML);
                    });
                } else {
                    finish(doc.body.innerHTML);
                }
            } else {
                finish(doc.body.innerHTML);
            }

            function finish(finalContent) {
                // 更新编辑器内容（可能会触发 contentChange，导致 hasChanges 被设为 true）
                if (window.ue && typeof window.ue.setContent === 'function') {
                    window.ue.setContent(finalContent);
                }
                
                // 关键：手动同步内容到 textarea，防止 form.submit() 跳过 UEditor 的自动同步
                const editorEl = document.getElementById('post-content');
                if (editorEl) {
                    editorEl.value = finalContent;
                }
                
                // 在 setContent 之后强制重置变更标志和自动保存定时器，
                // 防止 setContent 触发的 contentChange 再触发自动保存草稿
                hasChanges = false;
                if (window.autoSaveTimer) {
                    clearTimeout(window.autoSaveTimer);
                    window.autoSaveTimer = null;
                }
                
                if (window.TodoModal && typeof TodoModal.hideLoading === 'function') {
                    TodoModal.hideLoading();
                }
                
                // 模拟原本被点击的按钮提交
                if (submitType && submitType.name) {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = submitType.name;
                    hidden.value = submitType.value;
                    form.appendChild(hidden);
                }
                
                // 正式提交表单
                form.submit();
            }
        });
    }

    // 页面加载完成后初始化
    document.addEventListener('DOMContentLoaded', function () {
        initUEditor();
        initNotices();
        initCategoryChangeHandler();

        // 初始化永久链接编辑器
        initPermalinkEditor();

        // 初次进入编辑页即根据当前选中栏目刷新视图模版（与 post-new 保持一致体验）
        updateViewTemplates();

        // 监听 jQuery 组件刷新事件：模型字段更新后，重新刷新视图模版下拉框
        if (typeof jQuery !== 'undefined') {
            jQuery(document).on('model-fields-updated', function (e, payload) {
                try {
                    const cats = (payload && Array.isArray(payload.selectedCategories))
                        ? payload.selectedCategories
                        : Array.from(document.querySelectorAll('.hierarchical-categories-selector input.category-input:checked')).map(i => i.value);
                    updateViewTemplates(cats);
                } catch (err) {
                    console.error('model-fields-updated handler error:', err);
                    updateViewTemplates();
                }
            });
        }

        initSuperCopyButton();

        // 初始化编辑器快捷操作
        initEditorQuickActions();
    });

</script>

<?php
 $__todo_post_edit_t_media_modal = microtime(true);
require_once TODO_ADMIN_PATH . '/components/media-modal.php';
if (class_exists('Todo_Performance_Monitor')) {
    Todo_Performance_Monitor::add_metric('post_edit_media_modal', 'render media modal', (microtime(true) - $__todo_post_edit_t_media_modal) * 1000);
    Todo_Performance_Monitor::add_metric('post_edit_total', 'post-edit total', (microtime(true) - $__todo_post_edit_t0) * 1000);
}
?>

<!-- 文章翻译导入模态框模板 -->
<template id="todo-tpl-import-post-translation-modal">
    <form method="post">
        <div class="todo-modal-body-content">
            <p>JSON文件导入</p>
            <p class="description m-b-20">请选择由“JSON导出翻译”生成并经 AI 翻译后的 JSON 文件进行导入。</p>
            <input type="file" name="translation_file" accept="application/json,.json">
            <p class="description m-t-20">
                注意：导入将根据 JSON 中的 <code>translations</code> 字段内容自动更新对应语言的文章标题、内容和摘要。
                占位符 <code>[[MEDIA_N]]</code> 将会自动还原为原文中的多媒体标签。
            </p>
        </div>
    </form>
</template>
<!-- AI 创作导入模态框模板 -->
<template id="todo-tpl-import-post-creation-modal">
    <form method="post">
        <div class="todo-modal-body-content">
            <p>JSON文件导入</p>
            <p class="description m-b-20">请选择 AI 创作或扩展后的 JSON 文件。内容将直接注入到当前编辑器的标题、摘要和主内容中。</p>
            <input type="file" name="creation_file" accept="application/json,.json">
            <p class="description m-t-20">
                注意：此操作会<b>覆盖</b>当前编辑器中的内容。入库以您最终点击页面的“发布”或“更新”按钮为准。
            </p>
        </div>
    </form>
</template>
