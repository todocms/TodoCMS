<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

/**
 * TodoCMS 语言词汇管理页面
 * 
 * @package TodoCMS
 * @subpackage Language
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access denied');
}

// 检查用户权限
if (!current_user_can('manage_languages')) {
    todo_die('您没有权限访问此页面');
}

// 加载扩展工具函数（提供 todo_safe_redirect 等）
require_once ABSPATH . 'core/functions/utility-functions-extended.php';

// 确保 todo_add_admin_notice 函数可用
if (!function_exists('todo_add_admin_notice')) {
    require_once ABSPATH . 'core/functions/missing-core-functions.php';
}

// 确保在函数作用域包含时能够访问全局数据库连接
global $tododb;

// 加载语言管理器类文件（确保 todo_language_manager() 函数可用）
if (!function_exists('todo_language_manager')) {
    require_once ABSPATH . 'includes/language/class-language-manager.php';
}

$language_manager = todo_language_manager();

// 获取参数
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20;
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$context_filter = isset($_GET['context']) ? sanitize_text_field($_GET['context']) : '';
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$requested_domain = isset($_GET['domain']) ? sanitize_text_field($_GET['domain']) : '';
$lang = isset($_GET['lang']) ? sanitize_text_field($_GET['lang']) : (isset($_GET['locale']) ? sanitize_text_field($_GET['locale']) : '');
if (function_exists('canonical_locale') && $lang) {
    $lang = canonical_locale($lang);
} else {
    $lang = str_replace('_', '-', $lang);
}

// 计算有效语言（若请求语言未注册，则按 mark 回退，如 ja-JP -> ja）
$effective_locale = $lang ?: $language_manager->get_current_language();

// 检测是否为 iframe 模式
$is_iframe = function_exists('todo_is_iframe_mode') ? todo_is_iframe_mode() : (isset($_GET['format']) && $_GET['format'] === 'iframe');
if (class_exists('LanguageRegistry')) {
    $registry = LanguageRegistry::get_instance();
    if (!$registry->is_supported_locale($effective_locale, true)) {
        $parts = explode('-', $effective_locale);
        $baseMark = strtolower($parts[0] ?? '');
        $byMark = $baseMark ? $registry->locale_for_mark($baseMark) : null;
        $effective_locale = $byMark ?: $registry->get_default_locale();
    }
}

// 依据语言映射限制词汇管理域：仅管理当前语言映射的主题（无映射则使用当前激活主题）
$default_theme_slug = class_exists('CMS_Theme') ? CMS_Theme::get_active_theme()->get_stylesheet() : ($active_theme ?: 'default');
$map = get_option('language_theme_map', array());
if (is_string($map)) {
    $decoded = json_decode($map, true);
    $map = is_array($decoded) ? $decoded : array();
}
$mapped_theme = ($effective_locale && isset($map[$effective_locale]) && $map[$effective_locale]) ? $map[$effective_locale] : $default_theme_slug;
// 强制以映射主题作为过滤条件，避免跨语言或无关主题的词汇被展示/生成
$theme_filter = $mapped_theme;

// 主题/域列表
$available_themes = function_exists('todo_get_themes') ? todo_get_themes() : [];
$active_theme = function_exists('get_theme_name') ? get_theme_name() : '';

// 处理表单提交
if ($_POST && Todo_Security_Manager::verify_request_csrf('language_terms_management')) {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'add_term':
            handle_add_term();
            break;
        case 'edit_term':
            handle_edit_term();
            break;
        case 'delete_term':
            handle_delete_term();
            break;
        case 'bulk_action':
            handle_bulk_action();
            break;
        case 'clear_all_terms':
            handle_clear_all_terms();
            break;
    }

    // 检测是否为 AJAX 请求
    $wants_json = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || 
                  (isset($_POST['format']) && $_POST['format'] === 'json') || 
                  (isset($_GET['format']) && $_GET['format'] === 'json');

    // PRG 模式防重复提交：提交完成后重定向到词汇管理页（保留当前语言）
    $redirect_lang = $lang ?: $language_manager->get_current_language();
    if (function_exists('canonical_locale') && $redirect_lang) {
        $redirect_lang = canonical_locale($redirect_lang);
    }
    
    $redirect_query = [
        'page' => 'language-terms',
        'lang' => $redirect_lang
    ];
    
    // 显式保留 format 参数，确保 iframe 模式下重定向后依然保持布局隔离
    if (isset($_GET['format'])) {
        $redirect_query['format'] = sanitize_text_field($_GET['format']);
    } elseif (isset($_POST['format'])) {
        $redirect_query['format'] = sanitize_text_field($_POST['format']);
    } elseif (function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()) {
        $redirect_query['format'] = 'iframe';
    }
    
    // 使用后台伪装入口 /admin，实现PRG重定向
    $redirect_url = todo_admin_url('index.php?' . http_build_query($redirect_query));
    
    if ($wants_json) {
        if (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => '操作成功',
            'redirect' => $redirect_url
        ]);
        exit;
    }

    if (function_exists('todo_redirect')) {
        todo_redirect($redirect_url);
    } else {
        todo_safe_redirect($redirect_url);
    }
    exit;
}

/**
 * 处理添加词汇
 */
function handle_add_term()
{
    global $tododb;

    $original_text = sanitize_text_field($_POST['original_text'] ?? '');
    $context = sanitize_text_field($_POST['context'] ?? '');
    $description = sanitize_textarea_field($_POST['description'] ?? '');
    $domain = sanitize_text_field($_POST['domain'] ?? '');

    if (empty($original_text)) {
        todo_add_admin_notice('原始文本不能为空', 'error');
        return;
    }

    // 统一使用当前语言映射的主题域，避免跨域生成
    global $theme_filter;
    // 将保留的 domain 字段与主题保持一致，避免历史查询错位
    $fallback_theme = '';
    if (class_exists('CMS_Theme')) {
        $fallback_theme = CMS_Theme::get_active_theme()->get_stylesheet();
    }
    $theme_filter_value = is_string($theme_filter) ? $theme_filter : '';
    $domain = $theme_filter_value !== '' ? $theme_filter_value : $fallback_theme;
    $theme = $theme_filter_value !== '' ? $theme_filter_value : $fallback_theme;

    // 生成唯一的 term_key（统一使用 todo_generate_term_key，按主题维度）
    $term_key = todo_generate_term_key($original_text, $context, $theme);

    // 检查是否存在 domain/theme 列
    $table_name = $tododb->prefix . 'language_terms';
    $has_domain = (bool) $tododb->get_var("SHOW COLUMNS FROM `{$table_name}` LIKE 'domain'");
    $has_theme = (bool) $tododb->get_var("SHOW COLUMNS FROM `{$table_name}` LIKE 'theme'");

    $data = [
        'term_key' => $term_key,
        'original_text' => $original_text,
        'context' => $context,
        'description' => $description,
        'auto_detected' => 0,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql')
    ];

    if ($has_domain) {
        $data['domain'] = $domain;
    }
    if ($has_theme) {
        $data['theme'] = $theme;
    }

    $result = $tododb->insert(
        "{$tododb->prefix}language_terms",
        $data
    );

    if ($result) {
        todo_add_admin_notice('词汇添加成功', 'success');
    } else {
        todo_add_admin_notice('词汇添加失败', 'error');
    }
}

/**
 * 处理编辑词汇
 */
function handle_edit_term()
{
    global $tododb;

    $term_id = intval($_POST['term_id'] ?? 0);
    $original_text = sanitize_text_field($_POST['original_text'] ?? '');
    $context = sanitize_text_field($_POST['context'] ?? '');
    $description = sanitize_textarea_field($_POST['description'] ?? '');

    if (!$term_id || empty($original_text)) {
        todo_add_admin_notice('参数错误', 'error');
        return;
    }

    // 检查是否存在 domain/theme 列
    $table_name = $tododb->prefix . 'language_terms';
    $has_domain = (bool) $tododb->get_var("SHOW COLUMNS FROM `{$table_name}` LIKE 'domain'");
    $has_theme = (bool) $tododb->get_var("SHOW COLUMNS FROM `{$table_name}` LIKE 'theme'");

    $data = [
        'original_text' => $original_text,
        'context' => $context,
        'description' => $description,
        'updated_at' => current_time('mysql')
    ];
    if ($has_domain) {
        // 统一使用当前语言映射的主题域，避免跨域修改
        global $theme_filter;
        $fallback_theme = '';
        if (class_exists('CMS_Theme')) {
            $fallback_theme = CMS_Theme::get_active_theme()->get_stylesheet();
        }
        $theme_filter_value = is_string($theme_filter) ? $theme_filter : '';
        $data['domain'] = $theme_filter_value !== '' ? $theme_filter_value : $fallback_theme;
    }
    if ($has_theme) {
        // 同步主题字段，保证与统计口径一致
        global $theme_filter;
        $fallback_theme = '';
        if (class_exists('CMS_Theme')) {
            $fallback_theme = CMS_Theme::get_active_theme()->get_stylesheet();
        }
        $theme_filter_value = is_string($theme_filter) ? $theme_filter : '';
        $data['theme'] = $theme_filter_value !== '' ? $theme_filter_value : $fallback_theme;
    }

    $result = $tododb->update(
        "{$tododb->prefix}language_terms",
        $data,
        ['id' => $term_id]
    );

    if ($result !== false) {
        // 编辑可能改变上下文或域，为避免缓存错位，清理所有语言的相关缓存
        if (isset($language_manager) && is_object($language_manager) && method_exists($language_manager, 'clear_translation_cache')) {
            $langs = $language_manager->get_supported_languages();
            foreach ($langs as $code => $info) {
                $language_manager->clear_translation_cache($term_id, $code);
            }
        }
        todo_add_admin_notice('词汇更新成功', 'success');
    } else {
        todo_add_admin_notice('词汇更新失败', 'error');
    }
}

/**
 * 处理删除词汇
 */
function handle_delete_term()
{
    global $tododb;

    $term_id = intval($_POST['term_id'] ?? 0);

    if (!$term_id) {
        todo_add_admin_notice('参数错误', 'error');
        return;
    }

    // 删除词汇及其所有翻译
    $result = $tododb->delete("{$tododb->prefix}language_terms", ['id' => $term_id]);

    if ($result) {
        todo_add_admin_notice('词汇删除成功', 'success');
    } else {
        todo_add_admin_notice('词汇删除失败', 'error');
    }
}

/**
 * 处理批量操作
 */
function handle_bulk_action()
{
    $bulk_action = $_POST['bulk_action'] ?? '';
    $term_ids = $_POST['term_ids'] ?? [];

    if (empty($bulk_action) || empty($term_ids)) {
        todo_add_admin_notice('请选择操作和词汇', 'error');
        return;
    }

    switch ($bulk_action) {
        case 'delete':
            handle_bulk_delete($term_ids);
            break;
        case 'set_context':
            handle_bulk_set_context($term_ids);
            break;
    }
}

/**
 * 批量删除
 */
function handle_bulk_delete($term_ids)
{
    global $tododb;

    // 使用 PDO 预处理，避免将参数作为 fetch 模式传入导致错误
    $pdo = todo_get_db_connection();

    $term_ids = array_map('intval', $term_ids);
    $placeholders = implode(',', array_fill(0, count($term_ids), '?'));

    $sql = "DELETE FROM {$tododb->prefix}language_terms WHERE id IN ($placeholders)";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($term_ids);
        $affected = (int) $stmt->rowCount();

        if ($affected > 0) {
            todo_add_admin_notice("成功删除 {$affected} 个词汇", 'success');
        } else {
            todo_add_admin_notice('批量删除失败', 'error');
        }
    } catch (PDOException $e) {
        todo_add_admin_notice('批量删除异常：' . $e->getMessage(), 'error');
    }
}

/**
 * 批量设置上下文
 */
function handle_bulk_set_context($term_ids)
{
    global $tododb;

    // 使用 PDO 预处理，避免将参数作为 fetch 模式传入导致错误
    $pdo = todo_get_db_connection();

    $new_context = sanitize_text_field($_POST['new_context'] ?? '');
    $term_ids = array_map('intval', $term_ids);
    $placeholders = implode(',', array_fill(0, count($term_ids), '?'));

    $sql = "UPDATE {$tododb->prefix}language_terms SET context = ?, updated_at = ? WHERE id IN ($placeholders)";
    $params = array_merge([$new_context, current_time('mysql')], $term_ids);

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $affected = (int) $stmt->rowCount();

        if ($affected > 0) {
            todo_add_admin_notice("成功更新 {$affected} 个词汇的上下文", 'success');
        } else {
            todo_add_admin_notice('批量更新失败', 'error');
        }
    } catch (PDOException $e) {
        todo_add_admin_notice('批量更新异常：' . $e->getMessage(), 'error');
    }
}

/**
 * 清空当前筛选范围内的所有词汇及其翻译
 */
function handle_clear_all_terms()
{
    global $tododb, $language_manager;

    $search = sanitize_text_field($_POST['search'] ?? '');
    $context = sanitize_text_field($_POST['context'] ?? '');
    $theme = sanitize_text_field($_POST['theme'] ?? '');
    $status = sanitize_text_field($_POST['status'] ?? '');
    $lang = sanitize_text_field($_POST['lang'] ?? '');
    if (function_exists('canonical_locale') && $lang) {
        $lang = canonical_locale($lang);
    } else {
        $lang = str_replace('_', '-', $lang);
    }
    $effective_locale = $lang ?: ($language_manager ? $language_manager->get_current_language() : '');

    // 动态检测列是否存在，避免旧库缺列导致 SQL 错误
    // 优化：合并为一次查询获取所有列名
    $columns = $tododb->get_col(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$tododb->prefix . 'language_terms']
    );

    $has_term_key = in_array('term_key', $columns);
    $has_desc = in_array('description', $columns);
    $has_auto = in_array('auto_detected', $columns);
    $has_theme = intval($tododb->get_var(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'theme'",
        [$tododb->prefix . 'language_terms']
    )) > 0;

    $where_conditions = ['1=1'];
    $query_params = [];

    if ($search !== '') {
        $like = '%' . $search . '%';
        // 支持译文搜索（按当前有效语言）
        $search_clauses = [];
        $search_clauses[] = 'original_text LIKE ?';
        $query_params[] = $like;
        if ($has_term_key) {
            $search_clauses[] = 'term_key LIKE ?';
            $query_params[] = $like;
        }
        if ($has_desc) {
            $search_clauses[] = 'description LIKE ?';
            $query_params[] = $like;
        }
        $search_clauses[] = "EXISTS (SELECT 1 FROM {$tododb->prefix}language_translations lt WHERE lt.term_id = {$tododb->prefix}language_terms.id AND lt.language_code = ? AND lt.translated_text LIKE ?)";
        $query_params[] = $effective_locale ?: ($language_manager ? $language_manager->get_current_language() : '');
        $query_params[] = $like;
        $where_conditions[] = '(' . implode(' OR ', $search_clauses) . ')';
    }
    if ($context !== '') {
        $where_conditions[] = 'context = ?';
        $query_params[] = $context;
    }
    if ($theme !== '' && $has_theme) {
        $where_conditions[] = 'theme = ?';
        $query_params[] = $theme;
    }
    if ($status !== '') {
        if ($has_auto && $status === 'auto_detected') {
            $where_conditions[] = 'auto_detected = 1';
        } elseif ($has_auto && $status === 'manual') {
            $where_conditions[] = 'auto_detected = 0';
        } elseif ($status === 'missing') {
            // 按当前有效语言筛选无翻译的术语
            $where_conditions[] = "NOT EXISTS (SELECT 1 FROM {$tododb->prefix}language_translations lt WHERE lt.term_id = {$tododb->prefix}language_terms.id AND lt.language_code = ? AND lt.translated_text IS NOT NULL AND lt.translated_text != '')";
            $query_params[] = $effective_locale ?: ($language_manager ? $language_manager->get_current_language() : '');
        } elseif ($status === 'translated') {
            // 按当前有效语言筛选已翻译的术语
            $where_conditions[] = "EXISTS (SELECT 1 FROM {$tododb->prefix}language_translations lt WHERE lt.term_id = {$tododb->prefix}language_terms.id AND lt.language_code = ? AND lt.translated_text IS NOT NULL AND lt.translated_text != '')";
            $query_params[] = $effective_locale ?: ($language_manager ? $language_manager->get_current_language() : '');
        }
    }

    $where_clause = implode(' AND ', $where_conditions);

    // 查出所有待删除的 term_id
    $term_ids = $tododb->get_col(
        "SELECT id FROM {$tododb->prefix}language_terms WHERE $where_clause",
        $query_params
    );

    if (empty($term_ids)) {
        todo_add_admin_notice('未找到需要清空的词汇（请检查筛选条件）', 'warning');
        return;
    }

    // 使用 PDO 进行批量删除
    $pdo = todo_get_db_connection();

    $placeholders = implode(',', array_fill(0, count($term_ids), '?'));
    try {
        // 删除相关翻译
        $stmt1 = $pdo->prepare("DELETE FROM {$tododb->prefix}language_translations WHERE term_id IN ($placeholders)");
        $stmt1->execute($term_ids);
        // 删除术语
        $stmt2 = $pdo->prepare("DELETE FROM {$tododb->prefix}language_terms WHERE id IN ($placeholders)");
        $stmt2->execute($term_ids);
        $deleted = (int) $stmt2->rowCount();

        // 清理缓存：避免未及时同步
        if (isset($language_manager) && is_object($language_manager) && method_exists($language_manager, 'get_supported_languages') && method_exists($language_manager, 'clear_translation_cache')) {
            $langs = $language_manager->get_supported_languages();
            foreach ($langs as $code => $info) {
                foreach ($term_ids as $tid) {
                    $language_manager->clear_translation_cache($tid, $code);
                }
            }
        }

        todo_add_admin_notice("已清空 {$deleted} 个词汇及其翻译", 'success');
    } catch (PDOException $e) {
        todo_add_admin_notice('清空词汇异常：' . $e->getMessage(), 'error');
    }
}

/**
 * 生成唯一的term_key
 */
function generate_unique_term_key($text, $context = null)
{
    global $tododb;

    $base_key = sanitize_title($text);
    if ($context) {
        // 使用安全的标题生成规则处理上下文，避免非法字符
        $base_key = sanitize_title($context) . '_' . $base_key;
    }

    $counter = 0;
    $term_key = $base_key;

    // 如果当前数据库中不存在 term_key 列，则直接返回基础键，避免 SQL 错误
    $column_exists = $tododb->get_var(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'term_key'",
        [$tododb->prefix . 'language_terms']
    );
    if (!intval($column_exists)) {
        return $term_key;
    }

    while (true) {
        $existing = $tododb->get_var(
            "SELECT COUNT(*) FROM {$tododb->prefix}language_terms WHERE term_key = ?",
            [$term_key]
        );

        if (!$existing) {
            break;
        }

        $counter++;
        $term_key = $base_key . '_' . $counter;
    }

    return $term_key;
}

// 构建查询条件
$where_conditions = ['1=1'];
$query_params = [];

// 动态检测列是否存在，避免旧数据库因缺列导致的 SQL 错误
$has_term_key = intval($tododb->get_var(
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'term_key'",
    [$tododb->prefix . 'language_terms']
)) > 0;
$has_desc = intval($tododb->get_var(
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'description'",
    [$tododb->prefix . 'language_terms']
)) > 0;
$has_auto = intval($tododb->get_var(
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'auto_detected'",
    [$tododb->prefix . 'language_terms']
)) > 0;
$has_domain = intval($tododb->get_var(
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'domain'",
    [$tododb->prefix . 'language_terms']
)) > 0;
$has_theme = intval($tododb->get_var(
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'theme'",
    [$tododb->prefix . 'language_terms']
)) > 0;

if (!empty($search)) {
    $search_term = '%' . $search . '%';

    // 构造可搜索字段（含译文）
    $search_clauses = [];
    // 原文
    $search_clauses[] = "original_text LIKE ?";
    $query_params[] = $search_term;
    // 键
    if ($has_term_key) {
        $search_clauses[] = "term_key LIKE ?";
        $query_params[] = $search_term;
    }
    // 描述
    if ($has_desc) {
        $search_clauses[] = "description LIKE ?";
        $query_params[] = $search_term;
    }
    // 译文（当前有效语言）
    $search_clauses[] = "EXISTS (SELECT 1 FROM {$tododb->prefix}language_translations lt WHERE lt.term_id = {$tododb->prefix}language_terms.id AND lt.language_code = ? AND lt.translated_text LIKE ?)";
    $query_params[] = $effective_locale ?: $language_manager->get_current_language();
    $query_params[] = $search_term;

    $where_conditions[] = '(' . implode(' OR ', $search_clauses) . ')';
}

if (!empty($context_filter)) {
    $where_conditions[] = "context = ?";
    $query_params[] = $context_filter;
}

if (!empty($theme_filter) && $has_theme) {
    $where_conditions[] = "theme = ?";
    $query_params[] = $theme_filter;
}

if (!empty($status_filter)) {
    if ($has_auto && $status_filter === 'auto_detected') {
        $where_conditions[] = "auto_detected = 1";
    } elseif ($has_auto && $status_filter === 'manual') {
        $where_conditions[] = "auto_detected = 0";
    } elseif ($status_filter === 'missing') {
        // 未翻译：筛选当前有效语言下没有非空翻译文本的术语
        $where_conditions[] = "NOT EXISTS (SELECT 1 FROM {$tododb->prefix}language_translations lt WHERE lt.term_id = {$tododb->prefix}language_terms.id AND lt.language_code = ? AND lt.translated_text IS NOT NULL AND lt.translated_text != '')";
        $query_params[] = $effective_locale ?: $language_manager->get_current_language();
    } elseif ($status_filter === 'translated') {
        // 已翻译：筛选当前有效语言下存在非空翻译文本的术语
        $where_conditions[] = "EXISTS (SELECT 1 FROM {$tododb->prefix}language_translations lt WHERE lt.term_id = {$tododb->prefix}language_terms.id AND lt.language_code = ? AND lt.translated_text IS NOT NULL AND lt.translated_text != '')";
        $query_params[] = $effective_locale ?: $language_manager->get_current_language();
    }
}

$where_clause = implode(' AND ', $where_conditions);

// 获取总数
$total_items = $tododb->get_var(
    "SELECT COUNT(*) FROM {$tododb->prefix}language_terms WHERE $where_clause",
    $query_params
);

// 计算分页
$total_pages = ceil($total_items / $per_page);
$offset = ($current_page - 1) * $per_page;

// 获取词汇列表
$per_page = (int) $per_page;
$offset = (int) $offset;
$terms_query = "SELECT * FROM {$tododb->prefix}language_terms WHERE $where_clause ORDER BY created_at DESC LIMIT {$per_page} OFFSET {$offset}";

$terms = $tododb->get_results(
    $terms_query,
    $query_params
);

// 获取所有上下文
if (!empty($theme_filter) && $has_theme) {
    $contexts = $tododb->get_col(
        "SELECT DISTINCT context FROM {$tododb->prefix}language_terms WHERE theme = ? AND context IS NOT NULL AND context != '' ORDER BY context",
        [$theme_filter]
    );
} else {
    $contexts = $tododb->get_col(
        "SELECT DISTINCT context FROM {$tododb->prefix}language_terms WHERE context IS NOT NULL AND context != '' ORDER BY context"
    );
}

// 页面标题
$page_title = '词汇管理';
$page_subtitle = '管理需要翻译的词汇和短语';

// 统计当前筛选范围的翻译/未翻译数量（按当前有效语言）
$translated_count = 0;
$untranslated_count = 0;
// where 子句在统计查询中使用了 t 别名，需要替换原有的表前缀为 t.
$where_clause_stats = str_replace($tododb->prefix . 'language_terms.', 't.', $where_clause);
try {
    $translated_count = intval($tododb->get_var(
        "SELECT COUNT(DISTINCT t.id)
         FROM {$tododb->prefix}language_terms t
         INNER JOIN {$tododb->prefix}language_translations lt
           ON lt.term_id = t.id
          AND lt.language_code = ?
          AND lt.translated_text IS NOT NULL
          AND lt.translated_text != ''
         WHERE $where_clause_stats",
        array_merge([$effective_locale ?: $language_manager->get_current_language()], $query_params)
    ));
} catch (Exception $e) {
    $translated_count = 0;
}
$untranslated_count = max(0, intval($total_items) - $translated_count);
?>
<script type="text/javascript">
    // 基础变量定义（提前定义以供后续函数使用）
    var todoTermsContext = '<?php echo esc_js($context_filter); ?>';
    var todoTermsTheme = '<?php echo esc_js($theme_filter); ?>';
    var todoTermsLang = '<?php echo esc_js($lang ?: $language_manager->get_current_language()); ?>';
    var todoTermsNonce = '<?php echo (class_exists('Todo_Security_Manager') ? Todo_Security_Manager::generate_csrf_token('manage_terms') : todo_create_nonce("manage_terms")); ?>';
    var todoTermsMode = '<?php echo $is_iframe ? 'iframe' : 'normal'; ?>';

    // 模态框辅助函数 - 使用 TodoModal 重构
    window.showAddTermModal = function () {
        TodoModal.form({
            templateId: 'todo-tpl-add-term-modal',
            title: '添加新词汇',
            onOpen: function(modal) {
                var form = modal.element.querySelector('form');
                if (form) {
                    var actionUrl = window.location.href;
                    // 确保如果当前是在 iframe/弹窗中，action 带上 format=iframe 以保证服务端正确识别
                    if (window.TodoRequest && window.TodoRequest.getMode() === 'iframe' && actionUrl.indexOf('format=iframe') === -1) {
                        actionUrl += (actionUrl.indexOf('?') === -1 ? '?' : '&') + 'format=iframe';
                    }
                    form.setAttribute('action', actionUrl);
                    
                    var input = form.querySelector('input[name="original_text"]');
                    if (input) setTimeout(function() { input.focus(); }, 100);
                }
            },
            onSubmit: function(formData, form) {
                if (form && typeof form.checkValidity === 'function' && !form.checkValidity()) {
                    if (typeof form.reportValidity === 'function') {
                        form.reportValidity();
                    }
                    return false;
                }

                if (window.TodoRequest && typeof window.TodoRequest.submitForm === 'function') {
                    window.TodoRequest.submitForm(form, {
                        onSuccess: function() {
                            TodoModal.toast('词汇添加成功', 'success');
                            // 关闭所有弹窗以恢复滚动
                            if (typeof TodoModal !== 'undefined' && TodoModal.closeAll) {
                                TodoModal.closeAll();
                            }
                            // 延时刷新页面以确保用户看到提示且状态已同步
                            setTimeout(function() {
                                if (window.TodoRequest && typeof window.TodoRequest.redirect === 'function') {
                                    window.TodoRequest.redirect(window.location.href);
                                } else {
                                    window.location.reload();
                                }
                            }, 500);
                        }
                    });
                } else if (window.HTMLFormElement && window.HTMLFormElement.prototype && typeof window.HTMLFormElement.prototype.submit === 'function') {
                    window.HTMLFormElement.prototype.submit.call(form);
                } else {
                    form.submit();
                }
                return false;
            }
        });
    };

    window.hideAddTermModal = function () {
        TodoModal.closeAll();
    };

    window.hideEditTermModal = function () {
        TodoModal.closeAll();
    };

    window.showImportTranslationsModal = function () {
        TodoModal.form({
            templateId: 'todo-tpl-import-translations-modal',
            title: '批量导入翻译',
            submitText: '上传并导入',
            onSubmit: function(formData, form) {
                var fileInput = form.querySelector('input[name="translations_file"]');
                if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                    TodoModal.toast('请先选择要导入的JSON文件', 'warning');
                    return false;
                }
                var file = fileInput.files[0];
                var overwrite = form.querySelector('input[name="overwrite_import"]').checked ? 1 : 0;
                
                var reader = new FileReader();
                reader.onload = function(e) {
                    var content = e.target.result;
                    var payload = {
                        action: 'language_terms',
                        sub_action: 'import_translations',
                        json: content,
                        overwrite: overwrite,
                        _todononce: todoTermsNonce
                    };
                    
                    var loading = TodoModal.showLoading('正在导入...');
                    
                    jQuery.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: payload,
                        dataType: 'json'
                    }).done(function(resp) {
                        if (loading && loading.hide) loading.hide();
                        else TodoModal.hideLoading();
                        
                        if (resp && resp.success && resp.data) {
                            var msg = '导入成功：新增 ' + (resp.data.inserted || 0) + ' 条，更新 ' + (resp.data.updated || 0) + ' 条。';
                            TodoModal.success(msg, {
                                onConfirm: function() {
                                    window.location.href = window.location.href.replace(/#.*$/, '');
                                }
                            });
                        } else {
                            TodoModal.error((resp && resp.error) ? resp.error : '导入失败');
                        }
                    }).fail(function(xhr) {
                        if (loading && loading.hide) loading.hide();
                        else TodoModal.hideLoading();
                        TodoModal.error('导入请求失败：' + (xhr.responseText || '网络错误'));
                    });
                };
                reader.onerror = function() {
                    TodoModal.error('读取文件失败');
                };
                reader.readAsText(file, 'utf-8');
                return false;
            }
        });
    };

    window.hideImportTranslationsModal = function () {
        TodoModal.closeAll();
    };

    window.editTerm = function (termId) {
        var loading = TodoModal.showLoading('正在加载...');
        jQuery.post(ajaxurl, {
            action: 'language_terms',
            sub_action: 'get_term',
            term_id: termId,
            _todononce: todoTermsNonce
        }, function (response) {
            TodoModal.hideLoading();
            try {
                if (typeof response === 'string') {
                    response = JSON.parse(response);
                }
            } catch (e) { }
            
            if (response && response.success) {
                var term = response.data;
                TodoModal.form({
                    templateId: 'todo-tpl-edit-term-modal',
                    title: '编辑词汇',
                    submitText: '更新词汇',
                    onOpen: function(modal) {
                        var form = modal.element.querySelector('form');
                        if (form) {
                            var actionUrl = window.location.href;
                            if (window.TodoRequest && window.TodoRequest.getMode() === 'iframe' && actionUrl.indexOf('format=iframe') === -1) {
                                actionUrl += (actionUrl.indexOf('?') === -1 ? '?' : '&') + 'format=iframe';
                            }
                            form.setAttribute('action', actionUrl);

                            var idInput = form.querySelector('input[name="term_id"]');
                            if (idInput) idInput.value = term.id;
                            
                            var originalInput = form.querySelector('input[name="original_text"]');
                            if (originalInput) originalInput.value = term.original_text;
                            
                            var contextInput = form.querySelector('input[name="context"]');
                            if (contextInput) contextInput.value = term.context || '';
                            
                            var descInput = form.querySelector('textarea[name="description"]');
                            if (descInput) descInput.value = term.description || '';
                            
                            var domainInput = form.querySelector('input[name="domain"]');
                            if (domainInput) domainInput.value = term.domain || '';
                        }
                    },
                    onSubmit: function(formData, form) {
                        if (form && typeof form.checkValidity === 'function' && !form.checkValidity()) {
                            if (typeof form.reportValidity === 'function') {
                                form.reportValidity();
                            }
                            return false;
                        }

                        if (window.TodoRequest && typeof window.TodoRequest.submitForm === 'function') {
                            window.TodoRequest.submitForm(form, {
                                onSuccess: function() {
                                    TodoModal.toast('词汇更新成功', 'success');
                                    // 关闭所有弹窗以恢复滚动
                                    if (typeof TodoModal !== 'undefined' && TodoModal.closeAll) {
                                        TodoModal.closeAll();
                                    }
                                    // 延时刷新页面
                                    setTimeout(function() {
                                        if (window.TodoRequest && typeof window.TodoRequest.redirect === 'function') {
                                            window.TodoRequest.redirect(window.location.href);
                                        } else {
                                            window.location.reload();
                                        }
                                    }, 500);
                                }
                            });
                        } else if (window.HTMLFormElement && window.HTMLFormElement.prototype && typeof window.HTMLFormElement.prototype.submit === 'function') {
                            window.HTMLFormElement.prototype.submit.call(form);
                        } else {
                            form.submit();
                        }
                        return false;
                    }
                });
            } else {
                var msg = (response && response.data) ? response.data : '未知错误';
                TodoModal.error('获取词汇信息失败：' + msg);
            }
        }, 'json').fail(function() {
            TodoModal.hideLoading();
            TodoModal.error('网络错误');
        });
    };

    window.deleteTerm = function (termId) {
        TodoModal.confirm({
            title: '确认删除',
            message: '确定要删除这个词汇吗？删除后将无法恢复，所有相关翻译也会被删除。',
            onConfirm: function () {
                var form = document.createElement('form');
                form.method = 'post';
                form.innerHTML = `
            <input type="hidden" name="action" value="delete_term">
            <input type="hidden" name="term_id" value="${termId}">
            <input type="hidden" name="_todononce" value="${todoTermsNonce}">
            ${todoTermsMode === 'iframe' ? '<input type="hidden" name="format" value="iframe">' : ''}
        `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    };

    window.confirmBulkAction = function (btn) {
        var action = document.getElementById('bulk-action-selector-top').value;
        var checkedBoxes = document.querySelectorAll('input[name="term_ids[]"]:checked');

        if (!action) {
            TodoModal.toast('请选择一个操作', 'warning');
            return false;
        }

        if (checkedBoxes.length === 0) {
            TodoModal.toast('请至少选择一个词汇', 'warning');
            return false;
        }

        if (action === 'delete') {
            TodoModal.confirm({
                title: '确认删除',
                message: `确定要删除选中的 ${checkedBoxes.length} 个词汇吗？删除后将无法恢复。`,
                onConfirm: function () {
                    if (btn && btn.form) btn.form.submit();
                }
            });
            return false;
        }

        if (action === 'set_context') {
            var newContext = document.querySelector('input[name="new_context"]').value;
            if (!newContext.trim()) {
                TodoModal.toast('请输入新的上下文', 'warning');
                return false;
            }
            TodoModal.confirm({
                title: '确认设置',
                message: `确定要为选中的 ${checkedBoxes.length} 个词汇设置上下文为 "${newContext}" 吗？`,
                onConfirm: function () {
                    if (btn && btn.form) btn.form.submit();
                }
            });
            return false;
        }

        return true;
    };

    window.todoClearAllTerms = function () {
        TodoModal.confirm({
            title: '清空确认',
            message: '确定要清空当前筛选范围内的所有词汇吗？同时会删除相关翻译。',
            type: 'danger',
            confirmText: '确定清空',
            onConfirm: function () {
                var form = document.createElement('form');
                form.method = 'post';
                form.innerHTML = `
        <input type="hidden" name="action" value="clear_all_terms">
        <input type="hidden" name="_todononce" value="<?php echo (class_exists('Todo_Security_Manager') ? Todo_Security_Manager::generate_csrf_token('language_terms_management') : todo_create_nonce('language_terms_management')); ?>">
        <input type="hidden" name="lang" value="<?php echo esc_attr($lang ?: $language_manager->get_current_language()); ?>">
        <input type="hidden" name="search" value="<?php echo esc_attr($search); ?>">
        <input type="hidden" name="context" value="<?php echo esc_attr($context_filter); ?>">
        <input type="hidden" name="theme" value="<?php echo esc_attr($theme_filter); ?>">
        <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
        ${todoTermsMode === 'iframe' ? '<input type="hidden" name="format" value="iframe">' : ''}
      `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    };

    window.todoExportSelectedTermsSimple = function () {
        try {
            var ids = [];
            var checkboxes = document.querySelectorAll('input[name="term_ids[]"]:checked');
            checkboxes.forEach(function (cb) { ids.push(cb.value); });

            // 显示加载状态
            var loading = TodoModal.showLoading('正在导出词汇，请稍候...');

            var payload = {
                action: 'language_terms',
                sub_action: 'export_terms_simple',
                _todononce: todoTermsNonce
            };
            if (ids.length > 0) payload.term_ids = ids;
            if (todoTermsContext) payload.context = todoTermsContext;
            if (todoTermsTheme) payload.theme = todoTermsTheme;
            if (todoTermsLang) payload.language = todoTermsLang;

            var headers = { 'X-Requested-With': 'XMLHttpRequest', 'X-TODO-Nonce': todoTermsNonce };

            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: payload,
                headers: headers,
                dataType: 'json'
            }).done(function (resp) {
                // 隐藏加载状态
                if (loading && loading.hide) {
                    loading.hide();
                } else {
                    TodoModal.hideLoading();
                }

                if (resp && resp.success && resp.data && resp.data.json) {
                    var filename = resp.data.filename || ('todo-terms-export-simple-' + (resp.data.locale || 'unknown') + '-' + (resp.data.count || 0) + '.json');
                    var count = resp.data.count || 0;

                    // 下载文件
                    downloadJSON(resp.data.json, filename);

                    // 显示成功提示
                    TodoModal.success('导出成功！共导出 ' + count + ' 条词汇。', {
                        title: '导出完成',
                        onConfirm: function () {
                            // 关闭弹窗
                        }
                    });
                } else {
                    var msg = (resp && resp.error) ? resp.error : '导出失败，请稍后重试';
                    TodoModal.error(msg, {
                        title: '导出失败'
                    });
                }
            }).fail(function (xhr) {
                // 隐藏加载状态
                if (loading && loading.hide) {
                    loading.hide();
                } else {
                    TodoModal.hideLoading();
                }

                var errorMsg = '导出请求失败';
                try {
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        errorMsg = xhr.responseJSON.error;
                    } else if (xhr.responseText) {
                        var parsed = JSON.parse(xhr.responseText);
                        if (parsed && parsed.error) {
                            errorMsg = parsed.error;
                        }
                    }
                } catch (e) {
                    errorMsg = '网络错误，请检查网络连接后重试';
                }

                TodoModal.error(errorMsg, {
                    title: '导出失败'
                });
            });
        } catch (e) {
            // 隐藏加载状态
            TodoModal.hideLoading();

            TodoModal.error('导出发生异常：' + e.message, {
                title: '导出异常'
            });
        }
    };

    window.downloadJSON = function (jsonString, filename) {
        try {
            var blob = new Blob([jsonString], { type: 'application/json;charset=utf-8' });
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = filename || 'todo-terms-export.json';
            document.body.appendChild(link);
            link.click();
            setTimeout(function () {
                URL.revokeObjectURL(link.href);
                document.body.removeChild(link);
            }, 0);
        } catch (e) {
            TodoModal.error('下载导出文件失败：' + e.message, {
                title: '下载失败'
            });
        }
    };

    window.todoImportTranslationsFromFile = function () {
        var input = document.getElementById('translations_file');
        if (!input || !input.files || input.files.length === 0) {
            showMessage('请先选择要导入的JSON文件', 'warning');
            return;
        }
        var file = input.files[0];
        var reader = new FileReader();
        reader.onload = function (e) {
            var content = e.target.result;
            // 发送到后端进行导入
            var payload = {
                action: 'language_terms',
                sub_action: 'import_translations',
                json: content,
                overwrite: document.getElementById('overwrite_import') && document.getElementById('overwrite_import').checked ? 1 : 0,
                _todononce: todoTermsNonce
            };
            var headers = { 'X-Requested-With': 'XMLHttpRequest' };
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: payload,
                headers: headers,
                dataType: 'json'
            }).done(function (resp) {
                if (resp && resp.success && resp.data) {
                    var msg = '导入成功：新增 ' + (resp.data.inserted || 0) + ' 条，更新 ' + (resp.data.updated || 0) + ' 条。';
                    showMessage(msg, 'success');
                    hideImportTranslationsModal();
                    // PRG 模式：导入后重定向以避免刷新重复提交
                    window.location.href = window.location.href.replace(/#.*$/, '');
                } else {
                    var err = (resp && resp.error) ? resp.error : '导入失败，请检查JSON格式或稍后重试';
                    showMessage(err, 'error');
                }
            }).fail(function (xhr) {
                var msg = '导入请求失败：' + (xhr && xhr.responseText ? xhr.responseText : '网络错误');
                showMessage(msg, 'error');
            });
        };
        reader.onerror = function () {
            showMessage('读取文件失败，请重试', 'error');
        };
        reader.readAsText(file, 'utf-8');
    };

    // 确保父级页面也能访问这些函数（应对 moveGlobalComponents 移动元素的情况）
    if (window.parent && window.parent !== window) {
        var functionsToExport = [
            'showAddTermModal', 'hideAddTermModal', 'hideEditTermModal',
            'showImportTranslationsModal', 'hideImportTranslationsModal',
            'editTerm', 'deleteTerm', 'confirmBulkAction',
            'todoClearAllTerms', 'todoExportSelectedTermsSimple',
            'downloadJSON', 'todoImportTranslationsFromFile'
        ];
        functionsToExport.forEach(function (fnName) {
            window.parent[fnName] = window.parent[fnName] || window[fnName];
        });
    }
</script>
<div class="admin-page">
    <div class="admin-page-header">
        <a href="<?php echo todo_admin_url('index.php?page=language'); ?>" class="button button-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="vertical-align: middle; margin: -10px -5px -7px -5px;"><path fill="currentColor" d="M6 19h3.692v-5.884h4.616V19H18v-9l-6-4.538L6 10zm-1 1V9.5l7-5.288L19 9.5V20h-5.692v-5.884h-2.616V20zm7-7.77"></path></svg>
            语言首页
        </a>
        <div class="admin-page-actions">
            <a href="javascript:void(0);" class="button button-primary" onclick="showAddTermModal()">添加词汇</a>
        </div>
    </div>
    <div class="admin-card">
        <div class="admin-card-header">
            <h3 class="admin-card-title">词汇列表</h3>
            <div class="admin-card-actions">
                <span class="description m-0">
                    语言：<b style="color: #333;"><?php echo esc_html($lang ?: $language_manager->get_current_language()); ?></b>，
                    映射主题：<code><?php echo esc_html($theme_filter); ?></code>，
                    已翻译 <code><?php echo intval($translated_count); ?></code>，未翻译 <code><?php echo intval($untranslated_count); ?></code>
                </span>
            </div>
        </div>
        <div class="admin-card-content">
            <!-- 搜索和筛选 -->
            <div class="bulk-actions-bar">
                <div class="bulk-actions">
                    <form method="get" action="<?php echo todo_admin_url('index.php'); ?>" class="flex flex-wrap gap-3 todo-request">
                        <input type="hidden" name="page" value="language-terms">
                        <input type="hidden" name="lang"
                            value="<?php echo esc_attr($lang ?: $language_manager->get_current_language()); ?>">
                        <?php if ($is_iframe): ?>
                            <input type="hidden" name="format" value="iframe">
                        <?php endif; ?>

                        <input type="search" name="search" value="<?php echo esc_attr($search); ?>" placeholder="搜索词汇..." style="min-width: unset;width: 150px;color: #006dbf;">

                        <select name="context" style="min-width: unset;width: 120px;">
                            <option value="">所有上下文</option>
                            <?php foreach ($contexts as $context): ?>
                                <option value="<?php echo esc_attr($context); ?>" <?php selected($context_filter, $context); ?>>
                                    <?php echo esc_html($context ?: '(无上下文)'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select name="status" style="min-width: unset;width: 100px;">
                            <option value="">所有状态</option>
                            <option value="auto_detected" <?php selected($status_filter, 'auto_detected'); ?>>自动检测</option>
                            <option value="manual" <?php selected($status_filter, 'manual'); ?>>手动添加</option>
                            <option value="missing" <?php selected($status_filter, 'missing'); ?>>未翻译</option>
                            <option value="translated" <?php selected($status_filter, 'translated'); ?>>已翻译</option>
                        </select>

                        <input type="submit" class="button button-secondary" value="筛选">

                        <?php if ($search || $context_filter || $status_filter): ?>
                            <a href="<?php echo todo_admin_url('index.php?page=language-terms&lang=' . urlencode($lang ?: $language_manager->get_current_language())); ?>"
                                class="btn-normal m-l-05" style="line-height: 35px;">重置</a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if ($total_pages > 1): ?>
                    <?php
                    $pagination_add_args = array();
                    $pagination_add_args['lang'] = $lang ?: $language_manager->get_current_language();
                    if (!empty($search)) {
                        $pagination_add_args['search'] = $search;
                    }
                    if (!empty($context_filter)) {
                        $pagination_add_args['context'] = $context_filter;
                    }
                    if (!empty($status_filter)) {
                        $pagination_add_args['status'] = $status_filter;
                    }

                    echo todo_render_pagination(array(
                        'total_items' => $total_items,
                        'per_page' => $per_page,
                        'current_page' => $current_page,
                        'base_url' => todo_admin_url('index.php?page=language-terms'),
                        'add_args' => $pagination_add_args,
                        'show_count' => false,
                    ));
                    ?>
                <?php endif; ?>
            </div>

            <!-- 批量操作表单 -->
            <form method="post" id="terms-form" class="todo-request">
                <?php echo Todo_Security_Manager::csrf_field('language_terms_management'); ?>
                <input type="hidden" name="action" value="bulk_action">
                <?php if ($is_iframe): ?>
                    <input type="hidden" name="format" value="iframe">
                <?php endif; ?>

                <div class="bulk-actions-bar">
                    <div>
                        <select name="bulk_action" id="bulk-action-selector-top" style="min-width: unset;width: 150px;">
                            <option value="">批量操作</option>
                            <option value="delete">删除</option>
                            <option value="set_context">设置上下文</option>
                        </select>

                        <div id="bulk-context-input" style="display: none; margin-left: 10px;">
                            <input type="text" name="new_context" placeholder="新上下文" style="min-width: unset;width: 215px;">
                        </div>

                        <input type="submit" class="button button-secondary action" value="应用" onclick="return confirmBulkAction(this)">
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <button type="button" class="button button-secondary" onclick="todoExportSelectedTermsSimple()">导出JSON词汇</button>
                        <button type="button" class="button button-secondary" onclick="showImportTranslationsModal()">导入JSON翻译</button>
                        <button type="button" class="button button-secondary" onclick="todoClearAllTerms()">清空所有词汇</button>
                    </div>
                </div>

                <!-- 词汇列表表格 -->
                <table class="admin-table">
                    <thead>
                        <tr>
                            <td class="check-column">
                                <input type="checkbox" id="cb-select-all">
                            </td>
                            <th>词汇/译文</th>
                            <th>词汇键</th>
                            <th>上下文</th>
                            <th>描述</th>
                            <th width="70">来源</th>
                            <th>更新时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($terms)): ?>
                            <tr class="empty">
                                <td colspan="7">
                                    <div class="no-items">
                                        <p>没有找到词汇。</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($terms as $term): ?>

                                <tr>
                                    <th>
                                        <input type="checkbox" name="term_ids[]" value="<?php echo $term->id; ?>">
                                    </th>
                                    <td>
                                        <?php
                                        // 根据当前语言显示翻译文本，若无则回退到原文
                                        $current_locale = $effective_locale;
                                        if (function_exists('canonical_locale') && $current_locale) {
                                            $current_locale = canonical_locale($current_locale);
                                        }
                                        $display_text = $tododb->get_var(
                                            "SELECT translated_text FROM {$tododb->prefix}language_translations WHERE term_id = ? AND language_code = ? AND translated_text IS NOT NULL AND translated_text != '' ORDER BY is_selected DESC, (status='approved') DESC, updated_at DESC, id DESC LIMIT 1",
                                            [intval($term->id), $current_locale]
                                        );
                                        ?>
                                        <strong style="word-break: break-all;"><?php echo esc_html($term->original_text); ?></strong>
                                        <?php if (!empty($display_text) && $display_text !== $term->original_text): ?>
                                            <p class="description m-0">
                                                译文：<?php echo esc_html($display_text); ?> (<?php echo esc_html($current_locale); ?>)
                                            </p>
                                        <?php endif; ?>
                                        <div class="row-actions">
                                            <?php if (empty($term->auto_detected)): ?>
                                                <span class="edit">
                                                    <a href="javascript:void(0);" onclick="editTerm(<?php echo $term->id; ?>)">编辑</a> |
                                                </span>
                                            <?php endif; ?>
                                            <span class="quick-translate">
                                                <a href="javascript:void(0);" onclick="editLanguageTranslation(<?php echo $term->id; ?>, '<?php echo esc_js($effective_locale); ?>')">快速翻译</a>
                                                |
                                            </span>
                                            <span class="translate">
                                                <a
                                                    href="<?php echo todo_admin_url('index.php?page=language-translations&term_id=' . $term->id . '&lang=' . urlencode($effective_locale)); ?>">查看</a>
                                                |
                                            </span>
                                            <span class="delete">
                                                <a href="javascript:void(0);" onclick="deleteTerm(<?php echo $term->id; ?>)"
                                                    class="submitdelete">删除</a>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <code><?php echo esc_html($term->term_key); ?></code>
                                    </td>
                                    <td>
                                        <?php if (!empty($term->context)): ?>
                                            <code style="opacity: 0.5;"><?php echo esc_html($term->context); ?></code>
                                        <?php else: ?>
                                            <span class="text-muted" style="color: #999;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $term->description ? esc_html($term->description) : ''; ?>
                                    </td>
                                    <td>
                                        <span class="tb-meta">
                                        <?php if ($term->auto_detected): ?>
                                            自动检测
                                        <?php else: ?>
                                            手动添加
                                        <?php endif; ?>
                                        </span>
                                    </td>
                                    <td style="width: 150px;">
                                        <span class="tb-meta">
                                            <?php echo date('Y-m-d H:i', strtotime(isset($term->updated_at) ? $term->updated_at : $term->created_at)); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>

            <!-- 底部分页 -->
            <?php if ($total_pages > 1): ?>
                <?php
                $pagination_add_args = array();
                $pagination_add_args['lang'] = $lang ?: $language_manager->get_current_language();
                if (!empty($search)) {
                    $pagination_add_args['search'] = $search;
                }
                if (!empty($context_filter)) {
                    $pagination_add_args['context'] = $context_filter;
                }
                if (!empty($status_filter)) {
                    $pagination_add_args['status'] = $status_filter;
                }

                echo todo_render_pagination(array(
                    'total_items' => $total_items,
                    'per_page' => $per_page,
                    'current_page' => $current_page,
                    'base_url' => todo_admin_url('index.php?page=language-terms'),
                    'add_args' => $pagination_add_args,
                    'show_count' => true,
                ));
                ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 添加词汇模态框模板 -->
<template id="todo-tpl-add-term-modal">
    <form method="post" id="add-term-form">
        <?php echo Todo_Security_Manager::csrf_field('language_terms_management'); ?>
        <input type="hidden" name="action" value="add_term">
        <?php if ($is_iframe): ?>
            <input type="hidden" name="format" value="iframe">
        <?php endif; ?>
        <table class="form-table">
            <tr>
                <th style="width: 100px;">原始文本 <span class="required">*</span></th>
                <td>
                    <input type="text" name="original_text" class="regular-text" required>
                    <p class="description">需要翻译的原始文本</p>
                </td>
            </tr>
            <tr>
                <th style="width: 100px;">上下文</th>
                <td>
                    <input type="text" name="context" class="regular-text" list="context-list">
                    <datalist id="context-list">
                        <?php foreach ($contexts as $context): ?>
                            <option value="<?php echo esc_attr($context); ?>">
                        <?php endforeach; ?>
                    </datalist>
                    <p class="description">词汇的使用上下文，有助于准确翻译</p>
                </td>
            </tr>
            <tr>
                <th style="width: 100px;">主题域</th>
                <td>
                    <input type="hidden" name="domain" value="<?php echo esc_attr($theme_filter); ?>">
                    <span class="description"><code><?php echo esc_html($theme_filter); ?></code>（仅允许在该映射主题下管理词汇）</span>
                </td>
            </tr>
            <tr>
                <th style="width: 100px;">描述</th>
                <td style="width: 400px;">
                    <textarea name="description" rows="3" class="large-text" style="width: 100%;min-width: unset;"></textarea>
                    <p class="description">词汇的详细说明</p>
                </td>
            </tr>
        </table>
    </form>
</template>

<!-- 编辑词汇模态框模板 -->
<template id="todo-tpl-edit-term-modal">
    <form method="post" id="edit-term-form">
        <?php echo Todo_Security_Manager::csrf_field('language_terms_management'); ?>
        <input type="hidden" name="action" value="edit_term">
        <?php if ($is_iframe): ?>
            <input type="hidden" name="format" value="iframe">
        <?php endif; ?>
        <input type="hidden" name="term_id">
        <table class="form-table">
            <tr>
                <th style="width: 100px;">原始文本 <span class="required">*</span></th>
                <td>
                    <input type="text" name="original_text" class="regular-text" required>
                </td>
            </tr>
            <tr>
                <th style="width: 100px;">上下文</th>
                <td>
                    <input type="text" name="context" class="regular-text" list="context-list">
                </td>
            </tr>
            <tr>
                <th style="width: 100px;">主题域</th>
                <td>
                    <input type="hidden" name="domain" value="<?php echo esc_attr($theme_filter); ?>">
                    <span class="description"><code><?php echo esc_html($theme_filter); ?></code>（仅允许在该映射主题下管理词汇）</span>
                </td>
            </tr>
            <tr>
                <th style="width: 100px;">描述</th>
                <td style="width: 400px;">
                    <textarea name="description" rows="3" class="large-text" style="width: 100%;min-width: unset;"></textarea>
                </td>
            </tr>
        </table>
    </form>
</template>

<!-- 批量导入翻译模态框模板 -->
<template id="todo-tpl-import-translations-modal">
    <form method="post" id="import-translations-form">
        <div class="todo-modal-body-content">
            <p>JSON文件导入</p>
            <p class="description m-b-20">请选择由“导出精简JSON”生成并经第三方工具翻译后的JSON文件进行导入。</p>
            <input type="file" name="translations_file" accept="application/json,.json">
            <p class="m-t-20">
                <label><input type="checkbox" name="overwrite_import" value="1">
                    覆盖导入</label>
            </p>
            <p class="description">勾选将覆盖已存在的翻译；不勾选默认只新增，不覆盖已有翻译</p>
        </div>
    </form>
</template>

<!-- 编辑/新增翻译模态框 -->
<template id="todo-tpl-edit-translation-modal">
    <div class="todo-modal-body-content">
        <form method="post">
            <input type="hidden" name="page" value="language-translations">
            <?php echo todo_csrf_field('language_translations_management'); ?>
            <input type="hidden" name="action" value="save_translation">
            <?php if ($is_iframe): ?>
                <input type="hidden" name="format" value="iframe">
            <?php endif; ?>
            <input type="hidden" name="term_id" id="edit_term_id">
            <input type="hidden" name="language_code" id="edit_language_code">
            <input type="hidden" name="translation_id" id="edit_translation_id">
            <input type="hidden" name="redirect_to" id="edit_redirect_to">
            <table class="form-table">
                <tr>
                    <th style="width: 100px;">原始文本</th>
                    <td>
                        <b id="edit_original_text" style="color: #333;"></b>
                    </td>
                </tr>
                <tr>
                    <th style="width: 100px;">目标语言</th>
                    <td>
                        <div id="edit_language_name" class="language-name-display"></div>
                        <select id="edit_language_select" class="regular-text" style="display:none; margin-top:8px;">
                            <option value="">请选择语言...</option>
                            <?php 
                            $languages_list = class_exists('LanguageRegistry') ? LanguageRegistry::get_instance()->get_languages(true) : array();
                            foreach ($languages_list as $l_code => $lang): 
                                $l_name = is_array($lang) ? ($lang['name'] ?? $l_code) : ($lang->name ?? $l_code);
                                $l_native = is_array($lang) ? ($lang['native_name'] ?? '') : ($lang->native_name ?? '');
                            ?>
                                <option value="<?php echo esc_attr($l_code); ?>">
                                    <?php echo esc_html($l_name); ?> <?php if ($l_native && $l_native !== $l_name): ?>(<?php echo esc_html($l_native); ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th style="width: 100px;">翻译文本</th>
                    <td style="width: 400px;">
                        <textarea name="translated_text" id="edit_translated_text" rows="3" class="large-text"
                            placeholder="请输入翻译文本..." style="width: 100%;min-width: unset;"></textarea>
                    </td>
                </tr>
                <tr>
                    <th style="width: 100px;">译者备注</th>
                    <td style="width: 400px;">
                        <textarea name="translator_notes" id="edit_translator_notes" rows="3" class="large-text"
                            placeholder="可选：添加翻译说明或备注..." style="width: 100%;min-width: unset;"></textarea>
                    </td>
                </tr>
            </table>
            <p style="display: flex; gap: 10px 20px; padding: 0 0 0 25px; flex-wrap: wrap;">
                <label style="display:inline-block;">
                    <input type="checkbox" name="is_new_version" id="edit_is_new_version" value="1"> 作为新版本保存
                </label>
                <label style="display:inline-block;">
                    <input type="checkbox" name="is_selected" id="edit_is_selected" value="1"> 设为当前选用
                </label>
            </p>
        </form>
    </div>
</template>

<?php
/**
 * 获取词汇翻译统计
 */
function get_term_translation_stats($term_id)
{
    global $tododb;

    // 使用 LanguageRegistry 获取启用语言，并在存在当前语言的主题映射时按主题进行统计
    $registry = class_exists('LanguageRegistry') ? LanguageRegistry::get_instance() : null;
    $language_theme_map = get_option('language_theme_map', array());
    if (is_string($language_theme_map)) {
        $decoded = json_decode($language_theme_map, true);
        $language_theme_map = is_array($decoded) ? $decoded : array();
    }
    if (!is_array($language_theme_map)) {
        $language_theme_map = array();
    }
    $default_theme = class_exists('CMS_Theme') ? CMS_Theme::get_active_theme()->get_stylesheet() : '';

    // 从页面上下文读取当前统计主题（若存在）
    $current_theme = '';
    if (isset($GLOBALS['current_language_theme']) && is_string($GLOBALS['current_language_theme'])) {
        $current_theme = $GLOBALS['current_language_theme'];
    } elseif (isset($current_language_theme) && is_string($current_language_theme)) {
        $current_theme = $current_language_theme; // 兜底（同文件内变量）
    }

    $enabled_locales = array();
    if ($registry) {
        foreach ($registry->get_languages(true) as $loc => $info) {
            $locale = isset($info['locale']) ? $info['locale'] : $loc;
            $locNorm = str_replace('_', '-', $locale);
            if (function_exists('canonical_locale')) {
                $locNorm = canonical_locale($locNorm);
            }
            $mapped_theme = '';
            if (isset($language_theme_map[$locNorm]) && $language_theme_map[$locNorm] !== '') {
                $mapped_theme = preg_replace('/[^A-Za-z0-9_-]/', '', $language_theme_map[$locNorm]);
            } elseif (!empty($info['theme'])) {
                $mapped_theme = preg_replace('/[^A-Za-z0-9_-]/', '', $info['theme']);
            }
            if ($mapped_theme === '') {
                $mapped_theme = $default_theme;
            }
            // 若存在当前统计主题，则仅统计映射到该主题的语言
            if ($current_theme === '' || $mapped_theme === $current_theme) {
                $enabled_locales[] = $locNorm;
            }
        }
    } else {
        // 回退到数据库 languages（旧架构）
        $enabled_locales = (array) $tododb->get_col("SELECT code FROM {$tododb->prefix}languages WHERE status = 'active'");
    }

    $total_languages = count($enabled_locales);
    if ($total_languages === 0) {
        return ['total' => 0, 'completed' => 0, 'percentage' => 0];
    }

    // 计算已完成：统计该 term 在启用语言集合中存在非空翻译的语言数量（按语言去重）
    $placeholders = implode(',', array_fill(0, count($enabled_locales), '?'));
    $params = array_merge([intval($term_id)], $enabled_locales);
    $sql = "SELECT COUNT(DISTINCT language_code) FROM {$tododb->prefix}language_translations 
            WHERE term_id = ? AND translated_text IS NOT NULL AND translated_text != '' 
              AND language_code IN ($placeholders)";
    $completed_translations = (int) $tododb->get_var($sql, $params);

    $percentage = $total_languages > 0 ? ($completed_translations / $total_languages) * 100 : 0;

    return [
        'total' => $total_languages,
        'completed' => $completed_translations,
        'percentage' => $percentage
    ];
}
?>

<style>
    .translation-progress {
        font-size: 12px;
        color: #666;
    }

    .progress-bar {
        margin-top: 2px;
    }

    #bulk-context-input {
        display: inline-block;
    }
</style>

<script type="text/javascript">
    if (typeof window.ajaxurl === 'undefined' || !window.ajaxurl) {
        window.ajaxurl = '<?php echo todo_admin_url('admin-ajax.php'); ?>';
    }
    if (typeof window.location !== 'undefined' && window.location.origin) {
        try {
            var _ajaxURL = new URL(window.ajaxurl, window.location.origin);
            if (_ajaxURL.origin !== window.location.origin) {
                window.ajaxurl = (window.TODO && window.TODO.join ? window.TODO.join((window.TODO.ADMIN_BASE || (window.location.origin + '/admin')), 'admin-ajax.php') : (window.location.origin + '/admin/admin-ajax.php'));
            }
        } catch (e) {
            window.ajaxurl = (window.TODO && window.TODO.join ? window.TODO.join((window.TODO.ADMIN_BASE || (window.location.origin + '/admin')), 'admin-ajax.php') : (window.location.origin + '/admin/admin-ajax.php'));
        }
    }

    jQuery(document).ready(function ($) {
        // 全选/取消全选
        $('#cb-select-all').on('change', function () {
            $('input[name="term_ids[]"]').prop('checked', this.checked);
        });

        // 批量操作选择器
        $('#bulk-action-selector-top').on('change', function () {
            if ($(this).val() === 'set_context') {
                $('#bulk-context-input').show();
            } else {
                $('#bulk-context-input').hide();
            }
        });
    });

    // 辅助函数：显示消息
    function showMessage(msg, type) {
        TodoCMS.Utils.showMessage(msg, type);
    }

    window.initSelectionCheckbox = function (versionsCount, isSelectedInitial, chkSelected, chkNewVer) {
        if (!chkSelected || !chkNewVer) return;

        chkSelected.checked = !!isSelectedInitial;
        chkNewVer.onchange = null;

        versionsCount = parseInt(versionsCount, 10);
        if (isNaN(versionsCount)) { versionsCount = 0; }

        if (versionsCount === 1) {
            chkSelected.disabled = true;
            chkNewVer.checked = false;
            chkNewVer.onchange = function () {
                if (this.checked) {
                    chkSelected.disabled = false;
                } else {
                    chkSelected.disabled = true;
                    chkSelected.checked = true;
                }
            };
            chkSelected.checked = true;
        } else {
            chkSelected.disabled = false;
            if (versionsCount === 0) {
                chkSelected.checked = true;
            }
            chkNewVer.onchange = function () {};
        }
    };

    window.editLanguageTranslation = function (termId, languageCode) {
        jQuery.post(ajaxurl, {
            action: 'language_translations',
            sub_action: 'get_term_language_info',
            term_id: termId,
            language_code: languageCode,
            _todononce: '<?php echo (class_exists('Todo_Security_Manager') ? Todo_Security_Manager::generate_csrf_token('manage_translations') : todo_create_nonce("manage_translations")); ?>'
        }, function (response) {
            try {
                if (typeof response === 'string') {
                    response = JSON.parse(response);
                }
            } catch (e) { }
            if (response && response.success) {
                var data = response.data;
                
                TodoModal.form({
                    templateId: 'todo-tpl-edit-translation-modal',
                    title: (data.translated_text ? '编辑翻译' : '新增翻译'),
                    submitText: '保存翻译',
                    onOpen: function(modal) {
                        var form = modal.element.querySelector('form');
                        if (form) {
                            var actionUrl = '<?php echo todo_admin_url('index.php?page=language-translations'); ?>';
                            if (window.TodoRequest && window.TodoRequest.getMode() === 'iframe' && actionUrl.indexOf('format=iframe') === -1) {
                                actionUrl += (actionUrl.indexOf('?') === -1 ? '?' : '&') + 'format=iframe';
                            }
                            form.setAttribute('action', actionUrl);
                        }
                        var setVal = function(sel, val) { var el = form.querySelector(sel); if(el) el.value = val; };
                        var setText = function(sel, val) { var el = form.querySelector(sel); if(el) el.textContent = val; };
                        
                        var langCode = data.language_code || languageCode || '';
                        setVal('#edit_term_id', termId);
                        setVal('#edit_language_code', langCode);
                        setVal('#edit_redirect_to', window.location.href);
                        setText('#edit_original_text', data.original_text);
                        setText('#edit_language_name', data.language_name || '');
                        
                        var langNameEl = form.querySelector('#edit_language_name');
                        var sel = form.querySelector('#edit_language_select');
                        if (sel) {
                            sel.onchange = null;
                            sel.value = langCode;
                            sel.style.display = langCode ? 'none' : '';
                            sel.onchange = function() {
                                var v = this.value || '';
                                setVal('#edit_language_code', v);
                                if (langNameEl) {
                                    var txt = '';
                                    if (this.selectedIndex >= 0) {
                                        txt = (this.options[this.selectedIndex].textContent || '').trim();
                                    }
                                    langNameEl.textContent = txt;
                                }
                            };
                        }
                        
                        setVal('#edit_translated_text', data.translated_text || '');
                        setVal('#edit_translator_notes', data.translator_notes || '');
                        setVal('#edit_translation_id', data.id || '');
                        
                        var chkSelected = form.querySelector('#edit_is_selected');
                        var chkNewVer = form.querySelector('#edit_is_new_version');
                        
                        var vc = (typeof data.versions_count !== 'undefined') ? parseInt(data.versions_count, 10) : 0;
                        initSelectionCheckbox(isNaN(vc) ? 0 : vc, true, chkSelected, chkNewVer);
                    },
                    onSubmit: function(formData, form) {
                        if (window.TodoRequest) {
                            window.TodoRequest.submitForm(form, {
                                onSuccess: function(res) {
                                    TodoModal.toast('翻译保存成功', 'success');
                                    if (typeof TodoModal !== 'undefined' && TodoModal.closeAll) {
                                        TodoModal.closeAll();
                                    }
                                    setTimeout(function() {
                                        var rawUrl = (res && res.redirect) ? res.redirect : window.location.href;
                                        var targetUrl = rawUrl.replace(/&amp;/g, '&');
                                        if (window.TodoRequest && typeof window.TodoRequest.redirect === 'function') {
                                            window.TodoRequest.redirect(targetUrl);
                                        } else {
                                            window.location.href = targetUrl;
                                        }
                                    }, 500);
                                },
                                onError: function() {
                                    TodoModal.toast('翻译保存失败，请重试', 'error');
                                }
                            });
                        } else {
                            form.submit();
                        }
                        return true;
                    }
                });
            } else {
                var msg = (response && response.data) ? response.data : '未知错误';
                TodoModal.error('获取信息失败：' + msg);
            }
        }, 'json');
    };

    // 点击模态框外部关闭
    window.onclick = function (event) {
        var addModal = document.getElementById('add-term-modal') || (window.parent !== window ? window.parent.document.getElementById('add-term-modal') : null);
        var editModal = document.getElementById('edit-term-modal') || (window.parent !== window ? window.parent.document.getElementById('edit-term-modal') : null);
        var importModal = document.getElementById('import-translations-modal') || (window.parent !== window ? window.parent.document.getElementById('import-translations-modal') : null);

        if (event.target === addModal) {
            if (typeof hideAddTermModal === 'function') hideAddTermModal();
        }
        if (event.target === editModal) {
            if (typeof hideEditTermModal === 'function') hideEditTermModal();
        }
        if (event.target === importModal) {
            if (typeof hideImportTranslationsModal === 'function') hideImportTranslationsModal();
        }
    }
</script>
