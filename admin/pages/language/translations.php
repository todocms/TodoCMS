<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

/**
 * TodoCMS 语言翻译管理页面
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

// 初始化数据库连接，避免 $tododb 未定义
global $tododb;
if (!isset($tododb) || !is_object($tododb) || !method_exists($tododb, 'get_results')) {
    if (!function_exists('get_database_connection')) {
        require_once ABSPATH . 'core/functions/core-system-functions.php';
    }
    $tododb = get_database_connection();
    // 设置表前缀以兼容 {$tododb->prefix}
    if ($tododb && property_exists($tododb, 'prefix') && empty($tododb->prefix) && defined('TODO_TABLE_PREFIX')) {
        $tododb->prefix = constant('TODO_TABLE_PREFIX');
    }
}

// 加载语言管理器类文件（确保 todo_language_manager() 函数可用）
if (!function_exists('todo_language_manager')) {
    require_once ABSPATH . 'includes/language/class-language-manager.php';
}

// 现在再获取语言管理器实例，确保其持有有效的数据库连接
$language_manager = todo_language_manager();

// 获取参数
$term_id = isset($_GET['term_id']) ? intval($_GET['term_id']) : 0;
// 语言参数优先读取 lang，其次读取 language；统一规范化
$language_code = isset($_GET['lang']) ? sanitize_text_field($_GET['lang']) : (isset($_GET['language']) ? sanitize_text_field($_GET['language']) : '');

// 检测是否为 iframe 模式
$is_iframe = function_exists('todo_is_iframe_mode') ? todo_is_iframe_mode() : (isset($_GET['format']) && $_GET['format'] === 'iframe' || isset($_POST['format']) && $_POST['format'] === 'iframe');

if (!empty($language_code)) {
    if (function_exists('canonical_locale')) {
        $language_code = canonical_locale($language_code);
    } else {
        $language_code = str_replace('_', '-', $language_code);
        if (strpos($language_code, '-') !== false) {
            list($l, $r) = explode('-', $language_code, 2);
            $language_code = strtolower($l) . '-' . strtoupper($r);
        } else {
            $language_code = strtolower($language_code);
        }
    }
}
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20;
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
// 已移除状态筛选维度，避免不必要的参数污染
// 若未指定语言，则显示所有语言的翻译列表（不强制默认当前请求语言）
// 当 term_id 存在但 language 未指定时，不设置 $language_code，让后续查询展示该词汇的所有语言翻译。

// 处理表单提交
if ($_POST && Todo_Security_Manager::verify_request_csrf('language_translations_management')) {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'save_translation':
            handle_save_translation();
            break;
        case 'delete_translation':
            handle_delete_translation();
            break;
        case 'bulk_action':
            handle_bulk_translation_action();
            break;
        case 'auto_translate':
            handle_auto_translate();
            break;
        case 'set_selected_version':
            handle_set_selected_version();
            break;
        case 'clear_all_term_translations':
            handle_clear_all_term_translations();
            break;
    }
}

function handle_clear_all_term_translations()
{
    global $tododb, $language_manager;

    $deleted_translations = 0;
    $deleted_terms = 0;

    $total_terms = (int) $tododb->get_var("SELECT COUNT(*) FROM {$tododb->prefix}language_terms");
    $total_translations = (int) $tododb->get_var("SELECT COUNT(*) FROM {$tododb->prefix}language_translations");

    $res1 = $tododb->query("DELETE FROM {$tododb->prefix}language_translations");
    if ($res1 !== false && is_numeric($res1)) {
        $deleted_translations = (int) $res1;
    } else {
        $deleted_translations = $total_translations;
    }

    $res2 = $tododb->query("DELETE FROM {$tododb->prefix}language_terms");
    if ($res2 !== false && is_numeric($res2)) {
        $deleted_terms = (int) $res2;
    } else {
        $deleted_terms = $total_terms;
    }

    if (isset($language_manager) && is_object($language_manager) && method_exists($language_manager, 'clear_translation_cache')) {
        $language_manager->clear_translation_cache();
    } elseif (function_exists('todo_language_manager')) {
        $mgr = todo_language_manager();
        if ($mgr && method_exists($mgr, 'clear_translation_cache')) {
            $mgr->clear_translation_cache();
        }
    }
    if (class_exists('CMS_Translation') && method_exists('CMS_Translation', 'clear_cache')) {
        CMS_Translation::clear_cache();
    }
    if (function_exists('clear_translation_caches')) {
        clear_translation_caches();
    }

    todo_add_admin_notice('已清空所有词汇与翻译：词汇 ' . $deleted_terms . ' 条，翻译 ' . $deleted_translations . ' 条', 'success');

    $redirect_query = ['page' => 'language-translations'];
    $keep_params = ['lang', 'language', 'search', 'paged', 'format'];
    foreach ($keep_params as $p) {
        if (isset($_GET[$p]) && $_GET[$p] !== '') {
            $redirect_query[$p] = sanitize_text_field($_GET[$p]);
        }
    }
    $redirect_url = function_exists('admin_url') ? add_query_arg($redirect_query, admin_url('index.php')) : ('index.php?' . http_build_query($redirect_query));
    if (function_exists('todo_redirect')) {
        todo_redirect($redirect_url);
    } else {
        header('Location: ' . $redirect_url, true, 303);
        exit;
    }
    exit;
}

/**
 * 处理保存翻译
 */
function handle_save_translation()
{
    global $tododb, $language_manager, $is_iframe;

    error_log('handle_save_translation called. POST: ' . print_r($_POST, true));

    $term_id = intval($_POST['term_id'] ?? 0);
    $language_code = sanitize_text_field($_POST['language_code'] ?? '');
    $is_ajax = function_exists('todo_is_ajax_request') ? todo_is_ajax_request() : (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
    $wants_json = $is_ajax;
    // 归一化语言代码（连字符并区域大写），保证与系统一致
    if (function_exists('canonical_locale')) {
        $language_code = canonical_locale($language_code);
    } else {
        $language_code = str_replace('_', '-', $language_code);
        // 简单将区域段大写
        if (strpos($language_code, '-') !== false) {
            list($l, $r) = explode('-', $language_code, 2);
            $language_code = strtolower($l) . '-' . strtoupper($r);
        }
    }
    $translated_text = sanitize_textarea_field($_POST['translated_text'] ?? '');
    $translator_notes = sanitize_textarea_field($_POST['translator_notes'] ?? '');

    if (!$term_id || !$language_code) {
        if ($wants_json) {
            while (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => '参数错误']);
            exit;
        }
        todo_add_admin_notice('参数错误', 'error');
        return;
    }

    $lang_exists = (int) $tododb->get_var(
        "SELECT COUNT(*) FROM {$tododb->prefix}languages WHERE code = ?",
        [$language_code]
    );
    if ($lang_exists === 0 && class_exists('LanguageRegistry')) {
        $registry = LanguageRegistry::get_instance();
        if ($registry && method_exists($registry, 'init')) {
            $registry->init();
        }
        $langInfo = $registry && method_exists($registry, 'language_by_locale') ? $registry->language_by_locale($language_code) : null;
        if (is_array($langInfo)) {
            $tododb->insert("{$tododb->prefix}languages", [
                'code' => $language_code,
                'name' => isset($langInfo['title']) ? (string) $langInfo['title'] : $language_code,
                'native_name' => isset($langInfo['native_name']) ? (string) $langInfo['native_name'] : (isset($langInfo['title']) ? (string) $langInfo['title'] : $language_code),
                'flag' => isset($langInfo['flag']) ? (string) $langInfo['flag'] : null,
                'direction' => (isset($langInfo['direction']) && $langInfo['direction'] === 'rtl') ? 'rtl' : 'ltr',
                'status' => !empty($langInfo['enabled']) ? 'active' : 'inactive',
                'is_default' => !empty($langInfo['is_default']) ? 1 : 0,
                'sort_order' => isset($langInfo['sort_order']) ? intval($langInfo['sort_order']) : 0,
            ]);
        }
    }

    // 版本化保存逻辑：支持“作为新版本保存”和“设为当前选用”
    $translation_id = intval($_POST['translation_id'] ?? 0);
    $is_new_version = intval($_POST['is_new_version'] ?? 0) === 1 ? 1 : 0;
    $is_selected = intval($_POST['is_selected'] ?? 0) === 1 ? 1 : 0;
    // 统计该词汇在该语言下的版本总数
    $versions_count = (int) $tododb->get_var(
        "SELECT COUNT(*) FROM {$tododb->prefix}language_translations WHERE term_id = ? AND language_code = ?",
        [$term_id, $language_code]
    );
    // 若该词汇在该语言下尚无“选用”版本，则默认设为选用
    $has_selected = (int) $tododb->get_var(
        "SELECT COUNT(*) FROM {$tododb->prefix}language_translations WHERE term_id = ? AND language_code = ? AND is_selected = 1",
        [$term_id, $language_code]
    );
    if ($has_selected === 0) {
        $is_selected = 1;
    }
    // 业务约束：当仅存在一个翻译版本且未选择“作为新版本保存”时，强制保持“设为当前选用”为勾选状态
    if ($versions_count === 1 && $is_new_version !== 1) {
        $is_selected = 1;
    }

    $data = [
        'translated_text' => $translated_text,
        'translator_notes' => $translator_notes,
        'status' => empty($translated_text) ? 'pending' : 'approved',
        'updated_at' => current_time('mysql')
    ];

    $result = false;
    $updated_id = 0;

    if ($translation_id && !$is_new_version) {
        // 更新指定翻译记录
        $result = $tododb->update(
            "{$tododb->prefix}language_translations",
            array_merge($data, ['is_selected' => $is_selected]),
            ['id' => $translation_id]
        );
        $updated_id = $translation_id;
    } else {
        // 未指定ID时，根据是否新版本选择更新或插入
        $existing = null;
        if (!$is_new_version) {
            // 获取现有最新/选用记录
            $existing = $tododb->get_row(
                "SELECT * FROM {$tododb->prefix}language_translations WHERE term_id = ? AND language_code = ? ORDER BY is_selected DESC, updated_at DESC LIMIT 1",
                [$term_id, $language_code]
            );
        }

        if ($existing && !$is_new_version) {
            // 更新现有最新记录
            $result = $tododb->update(
                "{$tododb->prefix}language_translations",
                array_merge($data, ['is_selected' => $is_selected]),
                ['id' => $existing->id]
            );
            $updated_id = intval($existing->id);
        } else {
            // 创建新翻译版本
            $data['term_id'] = $term_id;
            $data['language_code'] = $language_code;
            $data['created_at'] = current_time('mysql');
            $data['is_selected'] = $is_selected;

            $result = $tododb->insert(
                "{$tododb->prefix}language_translations",
                $data
            );
            if ($result !== false && method_exists($tododb, 'insert_id')) {
                $updated_id = intval($tododb->insert_id());
            } else {
                // 回退：尝试读取最新ID
                $last = $tododb->get_row(
                    "SELECT id FROM {$tododb->prefix}language_translations WHERE term_id = ? AND language_code = ? ORDER BY id DESC LIMIT 1",
                    [$term_id, $language_code]
                );
                if ($last) {
                    $updated_id = intval($last->id);
                }
            }
        }
    }

    // 若成功，清除相关缓存（容错处理）
    if ($result !== false) {
        todo_add_admin_notice('翻译保存成功', 'success');

        // 使用单例获取语言管理器并清理缓存
        $manager = function_exists('todo_language_manager') ? todo_language_manager() : null;
        if ($manager && method_exists($manager, 'clear_translation_cache')) {
            $manager->clear_translation_cache($term_id, $language_code);
        }
        
        // 同时同步清空文件型翻译缓存
        if (class_exists('CMS_Translation') && method_exists('CMS_Translation', 'clear_cache')) {
            CMS_Translation::clear_cache();
        }

        // 处理选用逻辑：取消同 term+lang 的其他选用，并确保当前记录为唯一选用项
        if ($is_selected && $updated_id) {
            // 使用 $tododb 进行更新，替代直接 PDO 操作，确保前缀处理一致
            $tododb->query($tododb->prepare(
                "UPDATE {$tododb->prefix}language_translations SET is_selected = 0 WHERE term_id = %d AND language_code = %s AND id <> %d",
                $term_id, $language_code, $updated_id
            ));
            
            $tododb->update(
                "{$tododb->prefix}language_translations", 
                ['is_selected' => 1], 
                ['id' => $updated_id]
            );
        }
    } else {
        if ($wants_json) {
            while (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => '翻译保存失败']);
            exit;
        }
        todo_add_admin_notice('翻译保存失败', 'error');
    }

    $redirect_url = '';
    if (!empty($_POST['redirect_to'])) {
        $redirect_url = html_entity_decode(sanitize_text_field($_POST['redirect_to']));
    } elseif (!empty($_GET['redirect_to'])) {
        $redirect_url = html_entity_decode(sanitize_text_field($_GET['redirect_to']));
    }

    if (empty($redirect_url)) {
        // PRG 防重复提交：保存后重定向
        $redirect_query = [
            'page' => 'language-translations',
            'term_id' => $term_id,
        ];

        // 如果原请求中带有语言、状态、搜索、分页等参数，则在重定向中保留
        $keep_params = ['lang', 'language', 'status', 'search', 'paged'];
        foreach ($keep_params as $p) {
            if (isset($_GET[$p]) && $_GET[$p] !== '') {
                $redirect_query[$p] = sanitize_text_field($_GET[$p]);
            }
        }

        // 显式保留 format 参数，确保 iframe 模式下重定向后依然保持布局隔离
        if (isset($_GET['format'])) {
            $redirect_query['format'] = sanitize_text_field($_GET['format']);
        } elseif (isset($_POST['format'])) {
            $redirect_query['format'] = sanitize_text_field($_POST['format']);
        } elseif (function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()) {
            $redirect_query['format'] = 'iframe';
        }

        $redirect_url = function_exists('todo_admin_url') ? todo_admin_url('index.php?' . http_build_query($redirect_query)) : ('index.php?' . http_build_query($redirect_query));
    }
    
    if ($wants_json) {
        if (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => '翻译保存成功',
            'redirect' => $redirect_url
        ]);
        exit;
    }
    
    if ($is_iframe) {
        $safe_url = htmlspecialchars((string) $redirect_url, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . $safe_url . '"></head><body><script type="text/javascript">window.location.replace(' . json_encode((string) $redirect_url) . ');</script></body></html>';
        exit;
    }

    header('Location: ' . $redirect_url, true, 303);
    exit;
}

/**
 * 处理删除翻译
 */
function handle_delete_translation()
{
    global $tododb;
    global $language_manager;

    $translation_id = intval($_POST['translation_id'] ?? 0);

    if (!$translation_id) {
        todo_add_admin_notice('参数错误', 'error');
        return;
    }

    // 获取 term_id, language_code, is_selected 以便清理缓存与保护当前选用
    $translation = $tododb->get_row(
        "SELECT term_id, language_code, is_selected FROM {$tododb->prefix}language_translations WHERE id = ?",
        [$translation_id]
    );

    // 若删除的是当前选用版本，将在删除后自动回退到同语言的最新剩余版本（若存在）

    $result = $tododb->delete(
        "{$tododb->prefix}language_translations",
        ['id' => $translation_id]
    );

    if ($result) {
        // 若删除的是当前选用版本，尝试回退到同语言的最新剩余版本
        if ($translation && intval($translation->is_selected) === 1) {
            $alt = $tododb->get_row(
                "SELECT id FROM {$tododb->prefix}language_translations WHERE term_id = ? AND language_code = ? ORDER BY updated_at DESC, id DESC LIMIT 1",
                [intval($translation->term_id), sanitize_text_field($translation->language_code)]
            );
            if ($alt && isset($alt->id) && intval($alt->id) > 0) {
                $pdo = todo_get_db_connection();
                $sql = "UPDATE {$tododb->prefix}language_translations SET is_selected = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE term_id = ? AND language_code = ?";
                try {
                    $stmt = $pdo->prepare($sql);
                    if ($stmt) {
                        $stmt->execute([intval($alt->id), intval($translation->term_id), sanitize_text_field($translation->language_code)]);
                    }
                } catch (PDOException $e) {
                    error_log('Failed to update translation selection: ' . $e->getMessage());
                }
            }
        }

        // 清理相关缓存
        if ($translation && isset($language_manager) && is_object($language_manager) && method_exists($language_manager, 'clear_translation_cache')) {
            $language_manager->clear_translation_cache(intval($translation->term_id), sanitize_text_field($translation->language_code));
        }
        if (class_exists('CMS_Translation') && method_exists('CMS_Translation', 'clear_cache')) {
            CMS_Translation::clear_cache();
        }

        todo_add_admin_notice('翻译删除成功', 'success');
    } else {
        todo_add_admin_notice('翻译删除失败', 'error');
    }

    // PRG 防重复提交：删除后重定向到 GET
    $redirect_query = ['page' => 'language-translations'];
    if (!empty($translation->term_id)) {
        $redirect_query['term_id'] = intval($translation->term_id);
    }
    
    // 保持当前的语言视图及其他过滤/分页参数
    $keep_params = ['lang', 'language', 'status', 'search', 'paged', 'format'];
    foreach ($keep_params as $p) {
        if (isset($_GET[$p]) && $_GET[$p] !== '') {
            $redirect_query[$p] = sanitize_text_field($_GET[$p]);
        } elseif ($p === 'format' && isset($_POST['format'])) {
             $redirect_query[$p] = sanitize_text_field($_POST['format']);
        }
    }
    $redirect_url = function_exists('admin_url') ? add_query_arg($redirect_query, admin_url('index.php')) : ('index.php?' . http_build_query($redirect_query));
    if (function_exists('todo_redirect')) {
        todo_redirect($redirect_url);
    } else {
        header('Location: ' . $redirect_url, true, 303);
        exit;
    }
    exit;
}

/**
 * 处理批量翻译操作
 */
function handle_bulk_translation_action()
{
    $bulk_action = $_POST['bulk_action'] ?? '';
    $translation_ids = $_POST['translation_ids'] ?? [];
    $term_ids = $_POST['term_ids'] ?? [];

    if (empty($bulk_action)) {
        todo_add_admin_notice('请选择一个批量操作', 'error');
        return;
    }

    switch ($bulk_action) {
        case 'delete':
            // 若包含无翻译项（基于 term_id 的行），优先按“删除词汇”处理，
            // 将删除所选词汇及其所有语言的翻译；同时也会覆盖删除所选翻译ID。
            if (!empty($term_ids)) {
                handle_bulk_delete_terms($term_ids);
            } else {
                if (empty($translation_ids)) {
                    todo_add_admin_notice('请至少选择一个可删除项', 'error');
                    return;
                }
                handle_bulk_delete_translations($translation_ids);
            }
            break;
        case 'mark_completed':
            if (empty($translation_ids)) {
                todo_add_admin_notice('请至少选择一个翻译以标记完成', 'error');
                return;
            }
            handle_bulk_mark_status($translation_ids, 'approved');
            break;
        case 'mark_pending':
            if (empty($translation_ids)) {
                todo_add_admin_notice('请至少选择一个翻译以标记待处理', 'error');
                return;
            }
            handle_bulk_mark_status($translation_ids, 'pending');
            break;
    }
}

/**
 * 批量删除翻译
 */
function handle_bulk_delete_translations($translation_ids)
{
    global $tododb;
    global $language_manager;

    $translation_ids = array_map('intval', (array) $translation_ids);
    if (empty($translation_ids)) {
        todo_add_admin_notice('未选择任何翻译', 'error');
        return;
    }

    $placeholders = implode(',', array_fill(0, count($translation_ids), '?'));

    // 预取 term_id, language_code, is_selected 以便删除后清理缓存，并保护当前选用
    $details = $tododb->get_results(
        "SELECT id, term_id, language_code, is_selected FROM {$tododb->prefix}language_translations WHERE id IN ($placeholders)",
        $translation_ids
    );

    // 执行删除（包含当前选用版本），并在每个 term+lang 维度上进行选用回退
    $pdo = todo_get_db_connection();
    $delete_placeholders = implode(',', array_fill(0, count($translation_ids), '?'));
    $sql = "DELETE FROM {$tododb->prefix}language_translations WHERE id IN ($delete_placeholders)";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($translation_ids);
        $result = $stmt->rowCount();
    } catch (PDOException $e) {
        error_log('Failed to delete translations: ' . $e->getMessage());
        $result = 0;
    }

    if ($result) {
        // 对被删除集合中含有“选用版本”的 term+lang，进行自动回退
        $groups_needing_fallback = [];
        foreach ((array) $details as $row) {
            if (intval($row->is_selected) === 1 && in_array((int) $row->id, $translation_ids, true)) {
                $key = intval($row->term_id) . ':' . sanitize_text_field($row->language_code);
                $groups_needing_fallback[$key] = [$row->term_id, $row->language_code];
            }
        }

        foreach ($groups_needing_fallback as $k => $pair) {
            list($g_term_id, $g_lang_code) = $pair;
            // 查找删除后的剩余最新版本
            $alt = $tododb->get_row(
                "SELECT id FROM {$tododb->prefix}language_translations WHERE term_id = ? AND language_code = ? ORDER BY updated_at DESC, id DESC LIMIT 1",
                [intval($g_term_id), sanitize_text_field($g_lang_code)]
            );
            if ($alt && isset($alt->id) && intval($alt->id) > 0) {
                $pdo = todo_get_db_connection();
                $sql2 = "UPDATE {$tododb->prefix}language_translations SET is_selected = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE term_id = ? AND language_code = ?";
                try {
                    $stmt2 = $pdo->prepare($sql2);
                    if ($stmt2) {
                        $stmt2->execute([intval($alt->id), intval($g_term_id), sanitize_text_field($g_lang_code)]);
                    }
                } catch (PDOException $e) {
                    error_log('Failed to update translation selection: ' . $e->getMessage());
                }
            } else {
                // 无剩余版本时，清空选用标记（实际上删除后已无记录，无需清空）
            }

            // 清理缓存
            if (isset($language_manager) && is_object($language_manager) && method_exists($language_manager, 'clear_translation_cache')) {
                $language_manager->clear_translation_cache((int) $g_term_id, sanitize_text_field($g_lang_code));
            }
        }

        if (class_exists('CMS_Translation') && method_exists('CMS_Translation', 'clear_cache')) {
            CMS_Translation::clear_cache();
        }

        todo_add_admin_notice("成功删除 {$result} 个翻译", 'success');
    } else {
        todo_add_admin_notice('批量删除失败', 'error');
    }

    // PRG 防重复提交：批量删除后重定向到当前筛选视图
    $redirect_query = ['page' => 'language-translations'];
    if (!empty($_GET['term_id'])) {
        $redirect_query['term_id'] = intval($_GET['term_id']);
    }
    
    // 保持当前的语言视图及其他过滤/分页参数
    $keep_params = ['lang', 'language', 'status', 'search', 'paged', 'format'];
    foreach ($keep_params as $p) {
        if (isset($_GET[$p]) && $_GET[$p] !== '') {
            $redirect_query[$p] = sanitize_text_field($_GET[$p]);
        }
    }
    $redirect_url = function_exists('admin_url') ? add_query_arg($redirect_query, admin_url('index.php')) : ('index.php?' . http_build_query($redirect_query));
    if (function_exists('todo_redirect')) {
        todo_redirect($redirect_url);
    } else {
        header('Location: ' . $redirect_url, true, 303);
        exit;
    }
    exit;
}

/**
 * 批量删除词汇（及其所有语言翻译）
 */
function handle_bulk_delete_terms($term_ids)
{
    global $tododb;
    global $language_manager;

    $term_ids = array_map('intval', (array) $term_ids);
    $term_ids = array_values(array_filter($term_ids, function ($v) {
        return $v > 0; }));
    if (empty($term_ids)) {
        todo_add_admin_notice('未选择任何词汇', 'error');
        return;
    }

    // 先删除这些词汇下的所有翻译版本
    $pdo = todo_get_db_connection();
    $placeholders = implode(',', array_fill(0, count($term_ids), '?'));
    $deleted_translations = 0;
    try {
        $stmt1 = $pdo->prepare("DELETE FROM {$tododb->prefix}language_translations WHERE term_id IN ($placeholders)");
        if ($stmt1) {
            $stmt1->execute($term_ids);
            $deleted_translations = $stmt1->rowCount();
        }
    } catch (PDOException $e) {
        error_log('Failed to delete translations: ' . $e->getMessage());
    }

    // 再删除词汇本身
    try {
        $stmt2 = $pdo->prepare("DELETE FROM {$tododb->prefix}language_terms WHERE id IN ($placeholders)");
        if ($stmt2) {
            $stmt2->execute($term_ids);
        }
    } catch (PDOException $e) {
        error_log('Failed to delete terms: ' . $e->getMessage());
    }
    $deleted_terms = $stmt2 ? $stmt2->rowCount() : 0;

    // 清理缓存（粗粒度）
    if (isset($language_manager) && is_object($language_manager) && method_exists($language_manager, 'clear_translation_cache')) {
        foreach ($term_ids as $tid) {
            // 无语言代码维度，这里仅做粗粒度清理；实际渲染会读取最新数据
            $language_manager->clear_translation_cache((int) $tid, '');
        }
    }
    if (class_exists('CMS_Translation') && method_exists('CMS_Translation', 'clear_cache')) {
        CMS_Translation::clear_cache();
    }

    if ($deleted_terms > 0) {
        todo_add_admin_notice("成功删除 {$deleted_terms} 个词汇，已清理其关联的 {$deleted_translations} 个翻译版本", 'success');
    } else {
        todo_add_admin_notice('未删除任何词汇', 'warning');
    }

    // PRG 防重复提交：批量删除后重定向
    $redirect_query = ['page' => 'language-translations'];
    if (!empty($_GET['term_id'])) {
        $redirect_query['term_id'] = intval($_GET['term_id']);
    }
    
    // 保持当前的语言视图
    if (isset($_GET['lang'])) {
        $redirect_query['lang'] = sanitize_text_field($_GET['lang']);
    } elseif (isset($_GET['language'])) {
        $redirect_query['language'] = sanitize_text_field($_GET['language']);
    }

    if (!empty($_GET['status']))
        $redirect_query['status'] = sanitize_text_field($_GET['status']);
    if (!empty($_GET['search']))
        $redirect_query['search'] = sanitize_text_field($_GET['search']);
    $redirect_url = function_exists('admin_url') ? add_query_arg($redirect_query, admin_url('index.php')) : ('index.php?' . http_build_query($redirect_query));
    if (function_exists('todo_redirect')) {
        todo_redirect($redirect_url);
    } else {
        header('Location: ' . $redirect_url, true, 303);
        exit;
    }
    exit;
}

/**
 * 批量标记状态
 */
function handle_bulk_mark_status($translation_ids, $status)
{
    global $tododb;

    $translation_ids = array_map('intval', (array) $translation_ids);
    if (empty($translation_ids)) {
        todo_add_admin_notice('未选择任何翻译', 'error');
        return;
    }
    $placeholders = implode(',', array_fill(0, count($translation_ids), '?'));

    $params = array_merge([$status, current_time('mysql')], $translation_ids);

    $pdo = todo_get_db_connection();
    $sql = "UPDATE {$tododb->prefix}language_translations 
             SET status = ?, updated_at = ? 
             WHERE id IN ($placeholders)";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->rowCount();
    } catch (PDOException $e) {
        error_log('Failed to update translation status: ' . $e->getMessage());
        $result = 0;
    }

    if ($result) {
        $status_text = '已更新';
        if ($status === 'approved') {
            $status_text = '已完成';
        } elseif ($status === 'pending') {
            $status_text = '待处理';
        } elseif ($status === 'rejected') {
            $status_text = '已驳回';
        }
        todo_add_admin_notice("成功将 {$result} 个翻译标记为{$status_text}", 'success');
    } else {
        todo_add_admin_notice('批量更新失败', 'error');
    }

    // PRG 防重复提交：批量标记状态后重定向到当前筛选视图
    $redirect_query = ['page' => 'language-translations'];
    if (!empty($_GET['term_id'])) {
        $redirect_query['term_id'] = intval($_GET['term_id']);
    }
    
    // 保持当前的语言视图及其他过滤/分页参数
    $keep_params = ['lang', 'language', 'status', 'search', 'paged', 'format'];
    foreach ($keep_params as $p) {
        if (isset($_GET[$p]) && $_GET[$p] !== '') {
            $redirect_query[$p] = sanitize_text_field($_GET[$p]);
        }
    }
    $redirect_url = function_exists('admin_url') ? add_query_arg($redirect_query, admin_url('index.php')) : ('index.php?' . http_build_query($redirect_query));
    if (function_exists('todo_redirect')) {
        todo_redirect($redirect_url);
    } else {
        header('Location: ' . $redirect_url, true, 303);
        exit;
    }
    exit;
}

/**
 * 处理自动翻译
 */
function handle_auto_translate()
{
    // 这里可以集成第三方翻译API，如Google Translate、百度翻译等
    todo_add_admin_notice('自动翻译功能正在开发中', 'info');
}

/**
 * 设定某个翻译版本为当前选用
 */
function handle_set_selected_version()
{
    global $tododb;
    global $language_manager;

    $translation_id = intval($_POST['translation_id'] ?? 0);
    $term_id = intval($_POST['term_id'] ?? 0);
    $language_code = sanitize_text_field($_POST['language_code'] ?? '');

    if (!$translation_id || !$term_id || !$language_code) {
        todo_add_admin_notice('参数错误', 'error');
        return;
    }

    // 归一化语言代码
    if (function_exists('canonical_locale')) {
        $language_code = canonical_locale($language_code);
    } else {
        $language_code = str_replace('_', '-', $language_code);
        if (strpos($language_code, '-') !== false) {
            list($l, $r) = explode('-', $language_code, 2);
            $language_code = strtolower($l) . '-' . strtoupper($r);
        }
    }

    // 取消同 term+lang 其他记录的选用
    $pdo = todo_get_db_connection();
    $sql = "UPDATE {$tododb->prefix}language_translations SET is_selected = 0 WHERE term_id = ? AND language_code = ? AND id <> ?";
    try {
        $stmt = $pdo->prepare($sql);
        if ($stmt) {
            $stmt->execute([$term_id, $language_code, $translation_id]);
        }
    } catch (PDOException $e) {
        error_log('Failed to update translation selection: ' . $e->getMessage());
    }

    // 标记当前记录为选用
    $tododb->update("{$tododb->prefix}language_translations", ['is_selected' => 1], ['id' => $translation_id]);

    // 清理缓存
    if (isset($language_manager) && is_object($language_manager) && method_exists($language_manager, 'clear_translation_cache')) {
        $language_manager->clear_translation_cache($term_id, $language_code);
    }
    if (class_exists('CMS_Translation') && method_exists('CMS_Translation', 'clear_cache')) {
        CMS_Translation::clear_cache();
    }

    todo_add_admin_notice('已设为当前选用版本', 'success');

    // PRG 重定向
    $redirect_query = [
        'page' => 'language-translations',
        'term_id' => $term_id,
    ];
    
    // 保持当前的语言视图及其他过滤/分页参数
    $keep_params = ['lang', 'language', 'status', 'search', 'paged', 'format'];
    foreach ($keep_params as $p) {
        if (isset($_GET[$p]) && $_GET[$p] !== '') {
            $redirect_query[$p] = sanitize_text_field($_GET[$p]);
        }
    }
    $redirect_url = function_exists('admin_url') ? add_query_arg($redirect_query, admin_url('index.php')) : ('index.php?' . http_build_query($redirect_query));
    if (function_exists('todo_redirect')) {
        todo_redirect($redirect_url);
    } else {
        header('Location: ' . $redirect_url, true, 303);
        exit;
    }
    exit;
}

// 获取支持的语言（统一走 LanguageRegistry，显示已启用语言；若不可用则回退数据库）
$languages = [];
if (class_exists('LanguageRegistry')) {
    $registry = LanguageRegistry::get_instance();
    $lang_infos = $registry->get_languages(false); // 显示所有已注册语言（含未启用），用于独立词汇管理
    foreach ($lang_infos as $loc => $info) {
        $languages[] = (object) [
            'code' => $loc,
            'name' => isset($info['title']) ? $info['title'] : $loc,
            'native_name' => isset($info['native_name']) ? $info['native_name'] : (isset($info['title']) ? $info['title'] : $loc),
            'theme' => isset($info['theme']) ? $info['theme'] : null,
        ];
    }
} else {
    // 回退到旧语言表
    $languages = $tododb->get_results(
        "SELECT * FROM {$tododb->prefix}languages ORDER BY sort_order, name"
    );
}

// 如果指定了词汇ID，获取词汇信息
$current_term = null;
if ($term_id) {
    $current_term = $tododb->get_row(
        "SELECT * FROM {$tododb->prefix}language_terms WHERE id = ?",
        [$term_id]
    );

    if (!$current_term) {
        todo_add_admin_notice('指定的词汇不存在', 'error');
        $term_id = 0;
    }
}

// 构建查询条件
$where_conditions = ['1=1'];
$query_params = [];
$current_language_theme = '';
$join_lang_filter = '';

if ($term_id) {
    $where_conditions[] = "t.term_id = ?";
    $query_params[] = $term_id;
}

if (!empty($language_code)) {
    // 有 term_id 的详情视图，使用 WHERE 过滤；无 term_id 的聚合视图，使用 JOIN 过滤以保留无翻译的词汇
    if ($term_id) {
        $where_conditions[] = "t.language_code = ?";
        $query_params[] = $language_code;
    } else {
        $join_lang_filter = $language_code;
        // 依据语言选择，按映射主题隔离词汇（语言-主题互不冲突）
        $mapped_theme = function_exists('todo_get_mapped_theme_for_locale') ? todo_get_mapped_theme_for_locale($language_code) : '';
        $registry = class_exists('LanguageRegistry') ? LanguageRegistry::get_instance() : null;
        $lang_info = $registry ? $registry->language_by_locale($language_code) : null;
        $candidate_theme = '';
        if (is_string($mapped_theme) && $mapped_theme !== '') {
            $candidate_theme = $mapped_theme;
        } elseif (is_array($lang_info) && !empty($lang_info['theme'])) {
            $candidate_theme = preg_replace('/[^A-Za-z0-9_-]/', '', $lang_info['theme']);
        }
        // 仅匹配该主题下扫描出的词汇（按 language_terms.theme 严格隔离）
        if ($candidate_theme !== '') {
            // 兼容历史数据：theme 为空时按 file_path 推断主题
            $where_conditions[] = "(lt.theme = ? OR (lt.theme IS NULL AND lt.file_path LIKE ?))";
            $query_params[] = $candidate_theme;
            $query_params[] = '%/content/themes/' . $candidate_theme . '/%';
            $current_language_theme = $candidate_theme;
        }
    }
}

if (!empty($search)) {
    $where_conditions[] = "(lt.original_text LIKE ? OR t.translated_text LIKE ? OR t.translator_notes LIKE ?)";
    $search_term = '%' . $search . '%';
    $query_params[] = $search_term;
    $query_params[] = $search_term;
    $query_params[] = $search_term;
}

$where_clause = implode(' AND ', $where_conditions);

// 获取翻译总数（聚合视图：以词汇为主，左联选用版本；详情视图：以翻译为主）
if (!$term_id) {
    $count_query = "
        SELECT COUNT(*)
        FROM {$tododb->prefix}language_terms lt
        LEFT JOIN {$tododb->prefix}language_translations t
          ON t.term_id = lt.id AND t.is_selected = 1" . (!empty($join_lang_filter) ? " AND t.language_code = ?" : "") . "
        LEFT JOIN {$tododb->prefix}languages l ON t.language_code = l.code
        WHERE $where_clause
    ";
    $params_for_count = $query_params;
    if (!empty($join_lang_filter)) {
        array_unshift($params_for_count, $join_lang_filter);
    }
    $total_items = $tododb->get_var($count_query, $params_for_count);
} else {
    $count_query = "
        SELECT COUNT(*) 
        FROM {$tododb->prefix}language_translations t
        LEFT JOIN {$tododb->prefix}language_terms lt ON t.term_id = lt.id
        LEFT JOIN {$tododb->prefix}languages l ON t.language_code = l.code
        WHERE $where_clause
    ";
    $total_items = $tododb->get_var($count_query, $query_params);
}

// 计算分页
$total_pages = ceil($total_items / $per_page);
$offset = ($current_page - 1) * $per_page;

// 获取翻译列表
if (!$term_id) {
    $translations_query = "
        SELECT t.*, lt.id AS lt_id, lt.original_text, lt.term_key, lt.context, lt.created_at AS term_created_at, lt.updated_at AS term_updated_at, l.name as language_name, l.native_name
        FROM {$tododb->prefix}language_terms lt
        LEFT JOIN {$tododb->prefix}language_translations t
          ON t.term_id = lt.id AND t.is_selected = 1" . (!empty($join_lang_filter) ? " AND t.language_code = ?" : "") . "
        LEFT JOIN {$tododb->prefix}languages l ON t.language_code = l.code
        WHERE $where_clause
        ORDER BY lt.created_at DESC, lt.id DESC
        LIMIT " . intval($per_page) . " OFFSET " . intval($offset) . "
    ";
    $params_for_list = $query_params;
    if (!empty($join_lang_filter)) {
        array_unshift($params_for_list, $join_lang_filter);
    }
    $translations = $tododb->get_results($translations_query, $params_for_list);
} else {
    $translations_query = "
        SELECT t.*, lt.original_text, lt.term_key, lt.context, l.name as language_name, l.native_name
        FROM {$tododb->prefix}language_translations t
        LEFT JOIN {$tododb->prefix}language_terms lt ON t.term_id = lt.id
        LEFT JOIN {$tododb->prefix}languages l ON t.language_code = l.code
        WHERE $where_clause
        ORDER BY t.is_selected DESC, t.updated_at DESC
        LIMIT " . intval($per_page) . " OFFSET " . intval($offset) . "
    ";
    $translations = $tododb->get_results($translations_query, $query_params);
}

// 使用 LanguageRegistry 补全/覆盖语言显示名称，避免旧 languages 表未包含注册语言时名称缺失
if (!empty($translations) && class_exists('LanguageRegistry')) {
    $registry = LanguageRegistry::get_instance();
    foreach ($translations as $idx => $row) {
        $info = $registry->language_by_locale($row->language_code ?? '');
        if (is_array($info)) {
            $row->language_name = isset($info['title']) ? $info['title'] : ($row->language_code ?? '');
            $row->native_name = isset($info['native_name']) ? $info['native_name'] : ($row->language_name ?? $row->language_code);
            $translations[$idx] = $row;
        }
    }
}

// 页面标题
$page_title = '翻译管理';
if ($current_term) {
    $page_title .= ' - ' . esc_html($current_term->original_text);
}
$page_subtitle = '管理词汇的多语言翻译';
?>
<script type="text/javascript">
    // 模态框辅助函数 - 提前定义并挂载到 window/parent 以避免在页面较大加载较慢或元素移动时触发 onclick 产生 ReferenceError
    // 兼容性保留，实际逻辑已迁移至 TodoModal
    window.hideQuickTranslateModal = function () {
        TodoModal.closeAll();
    };

    window.showQuickTranslateModal = function () {
        TodoModal.form({
            templateId: 'todo-tpl-quick-translate-modal',
            title: '快速翻译',
            submitText: '开始翻译',
            onOpen: function(modal) {
                var form = modal.element.querySelector('form');
                var langSelect = form.querySelector('[name="language"]');
                var termSelect = form.querySelector('[name="term_id"]');
                
                // 绑定语言变化事件
                if (langSelect && termSelect) {
                    langSelect.addEventListener('change', function() {
                        var lang = this.value;
                        if (!lang) {
                            termSelect.innerHTML = '<option value="">请选择词汇（按目标语言映射主题过滤）...</option>';
                            return;
                        }
                        loadQuickTerms(lang, termSelect);
                    });
                }
            },
            onSubmit: function(formData, form) {
                // 构造GET请求跳转
                var params = new URLSearchParams();
                for(var pair of formData.entries()) {
                    params.append(pair[0], pair[1]);
                }
                
                // 立即关闭并销毁弹窗，防止页面跳转后弹窗残留或滚动锁定
                TodoModal.closeAll({ destroy: true });
                
                window.location.href = '?' + params.toString();
                return false;
            }
        });
    };

    window.showClearAllTermTranslationsModal = function () {
        TodoModal.confirm({
            title: '清空所有词汇翻译',
            message: '将删除所有词汇及其所有语言翻译记录（不可恢复）。确定继续吗？',
            type: 'warning',
            onConfirm: function () {
                var form = document.getElementById('todo-clear-all-term-translations-form');
                if (!form) return;
                if (window.TodoRequest && typeof window.TodoRequest.submitForm === 'function') {
                    window.TodoRequest.submitForm(form, { async: false });
                } else {
                    form.submit();
                }
            }
        });
    };

    window.hideEditTranslationModal = function () {
        TodoModal.closeAll();
    };

    window.editTranslation = function (translationId) {
        // 通过AJAX获取翻译信息
        jQuery.post(ajaxurl, {
            action: 'language_translations',
            sub_action: 'get_translation',
            translation_id: translationId,
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
                    title: '编辑翻译',
                    submitText: '保存翻译',
                    onOpen: function(modal) {
                        var form = modal.element.querySelector('form');
                        if (form) {
                            var actionUrl = window.location.href;
                            // 确保如果当前是在 iframe/弹窗中，action 带上 format=iframe 以保证服务端正确识别
                            if (window.TodoRequest && window.TodoRequest.getMode() === 'iframe' && actionUrl.indexOf('format=iframe') === -1) {
                                actionUrl += (actionUrl.indexOf('?') === -1 ? '?' : '&') + 'format=iframe';
                            }
                            form.setAttribute('action', actionUrl);
                        }
                        var setVal = function(sel, val) { var el = form.querySelector(sel); if(el) el.value = val; };
                        var setText = function(sel, val) { var el = form.querySelector(sel); if(el) el.textContent = val; };
                        
                        setVal('#edit_term_id', data.term_id);
                        setVal('#edit_language_code', data.language_code);
                        setText('#edit_original_text', data.original_text);
                        setText('#edit_language_name', data.language_name);
                        
                        var sel = form.querySelector('#edit_language_select');
                        if (sel) {
                            sel.value = data.language_code || '';
                            sel.style.display = 'none';
                        }
                        
                        setVal('#edit_translated_text', data.translated_text || '');
                        setVal('#edit_translator_notes', data.translator_notes || '');
                        setVal('#edit_translation_id', data.id || '');
                        
                        // 初始化“设为当前选用”的勾选与禁用逻辑
                        var chkSelected = form.querySelector('#edit_is_selected');
                        var chkNewVer = form.querySelector('#edit_is_new_version');
                        
                        var vc = (typeof data.versions_count !== 'undefined') ? parseInt(data.versions_count, 10) : 0;
                        var isSel = (typeof data.is_selected !== 'undefined') ? (data.is_selected == 1) : true;
                        
                        initSelectionCheckbox(vc, isSel, chkSelected, chkNewVer);
                    },
                    onSubmit: function(formData, form) {
                        if (window.TodoRequest) {
                            window.TodoRequest.submitForm(form, {
                                onSuccess: function() {
                                    TodoModal.toast('翻译保存成功', 'success');
                                    if (typeof TodoModal !== 'undefined' && TodoModal.closeAll) {
                                        TodoModal.closeAll();
                                    }
                                    setTimeout(function() {
                                        if (window.TodoRequest && typeof window.TodoRequest.redirect === 'function') {
                                            window.TodoRequest.redirect(window.location.href);
                                        } else {
                                            window.location.reload();
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
                TodoModal.error('获取翻译信息失败：' + msg);
            }
        }, 'json');
    };

    window.editLanguageTranslation = function (termId, languageCode) {
        // 通过AJAX获取词汇和语言信息
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
                    title: '新增翻译',
                    submitText: '保存翻译',
                    onOpen: function(modal) {
                        var form = modal.element.querySelector('form');
                        if (form) {
                            var actionUrl = window.location.href;
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
                        setVal('#edit_translation_id', '');
                        
                        // 初始化“设为当前选用”的勾选与禁用逻辑
                        var chkSelected = form.querySelector('#edit_is_selected');
                        var chkNewVer = form.querySelector('#edit_is_new_version');
                        
                        var vc = (typeof data.versions_count !== 'undefined') ? parseInt(data.versions_count, 10) : 0;
                        initSelectionCheckbox(isNaN(vc) ? 0 : vc, true, chkSelected, chkNewVer);
                    },
                    onSubmit: function(formData, form) {
                        if (window.TodoRequest) {
                            window.TodoRequest.submitForm(form, {
                                onSuccess: function() {
                                    TodoModal.toast('翻译保存成功', 'success');
                                    if (typeof TodoModal !== 'undefined' && TodoModal.closeAll) {
                                        TodoModal.closeAll();
                                    }
                                    setTimeout(function() {
                                        if (window.TodoRequest && typeof window.TodoRequest.redirect === 'function') {
                                            window.TodoRequest.redirect(window.location.href);
                                        } else {
                                            window.location.reload();
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

    window.initSelectionCheckbox = function (versionsCount, isSelectedInitial, chkSelected, chkNewVer) {
        if (!chkSelected || !chkNewVer) return;

        // 设置初始勾选
        chkSelected.checked = !!isSelectedInitial;
        // 清除旧的事件绑定
        chkNewVer.onchange = null;

        versionsCount = parseInt(versionsCount, 10);
        if (isNaN(versionsCount)) { versionsCount = 0; }

        if (versionsCount === 1) {
            // 单版本：强制选用并禁用，除非用户勾选“作为新版本保存”
            chkSelected.disabled = true;
            chkNewVer.checked = false; // 默认不作为新版本保存
            chkNewVer.onchange = function () {
                if (this.checked) {
                    chkSelected.disabled = false; // 新版本时，允许用户自由设定是否选用
                } else {
                    chkSelected.disabled = true;
                    chkSelected.checked = true; // 恢复为强制选用
                }
            };
            // 打开时强制选用
            chkSelected.checked = true;
        } else {
            // 多版本或无版本：允许自由编辑
            chkSelected.disabled = false;
            // 对新增场景，若没有任何版本（0），默认选用
            if (versionsCount === 0) {
                chkSelected.checked = true;
            }
            // 新版本勾选不影响“设为当前选用”的控件可编辑性
            chkNewVer.onchange = function () { /* no-op for multi-version */ };
        }
    };

    window.deleteTranslation = function (translationId) {
        TodoModal.confirm({
            title: '确认删除',
            message: '确定要删除这个翻译吗？删除后将无法恢复。',
            onConfirm: function() {
                var form = document.createElement('form');
                form.method = 'post';
                form.innerHTML = `
                <input type="hidden" name="action" value="delete_translation">
                <input type="hidden" name="translation_id" value="${translationId}">
                <input type="hidden" name="_todononce" value="<?php echo (class_exists('Todo_Security_Manager') ? Todo_Security_Manager::generate_csrf_token('language_translations_management') : todo_create_nonce('language_translations_management')); ?>">
                `;
                document.body.appendChild(form);
                if (window.TodoRequest) {
                    window.TodoRequest.submitForm(form);
                } else {
                    form.submit();
                }
            }
        });
    };

    window.confirmBulkAction = function (e) {
        var action = document.getElementById('bulk-action-selector-top').value;
        var checkedBoxes = document.querySelectorAll('input[name="translation_ids[]"]:checked, input[name="term_ids[]"]:checked');

        if (!action) {
            TodoModal.warning('请选择一个操作');
            return false;
        }

        if (checkedBoxes.length === 0) {
            TodoModal.warning('请至少选择一个项');
            return false;
        }

        if (action === 'delete') {
            // 阻止默认提交
            if (e && e.preventDefault) e.preventDefault();
            
            TodoModal.confirm({
                message: '确定要删除选中的 ' + checkedBoxes.length + ' 个项吗？\n提示：勾选"无翻译"的行将删除对应词汇及其所有语言的翻译。',
                onConfirm: function() {
                    var form = document.getElementById('bulk-action-selector-top').form;
                    if (form) {
                        form.submit();
                    }
                }
            });
            return false;
        }
        return true;
    };

    window.loadQuickTerms = function (languageCode, targetElement) {
        var termSelect = targetElement;
        if (!termSelect) {
            termSelect = document.getElementById('quick_term_id');
        }
        if (!termSelect) return;
        termSelect.innerHTML = '<option value="">加载中...</option>';

        jQuery.post(ajaxurl, {
            action: 'language_translations',
            sub_action: 'get_terms_for_quick_translate',
            language_code: languageCode,
            _todononce: '<?php echo (class_exists('Todo_Security_Manager') ? Todo_Security_Manager::generate_csrf_token('manage_translations') : todo_create_nonce("manage_translations")); ?>'
        }, function (response) {
            try {
                if (typeof response === 'string') {
                    response = JSON.parse(response);
                }
            } catch (e) { }
            var optionsHtml = '';
            if (response && response.success && Array.isArray(response.data)) {
                if (response.data.length === 0) {
                    optionsHtml = '<option value="">该语言映射主题下暂无词汇</option>';
                } else {
                    optionsHtml = '<option value="">请选择词汇...</option>';
                    response.data.forEach(function (term) {
                        var text = (term.original_text || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        var ctx = term.context ? ' (' + term.context + ')' : '';
                        optionsHtml += '<option value="' + term.id + '">' + text + ctx + '</option>';
                    });
                }
            } else {
                optionsHtml = '<option value="">加载失败，请重试</option>';
            }
            termSelect.innerHTML = optionsHtml;
        }, 'json');
    };

    // 确保父级页面也能访问这些函数（应对 moveGlobalComponents 移动元素的情况）
    if (window.parent && window.parent !== window) {
        var functionsToExport = [
            'hideQuickTranslateModal', 'showQuickTranslateModal', 'hideEditTranslationModal',
            'editTranslation', 'editLanguageTranslation', 'initSelectionCheckbox',
            'deleteTranslation', 'confirmBulkAction', 'loadQuickTerms'
        ];
        functionsToExport.forEach(function (fnName) {
            window.parent[fnName] = window.parent[fnName] || window[fnName];
        });
    }

    // 确保 ajaxurl 存在
    if (typeof window.ajaxurl === 'undefined' || !window.ajaxurl) {
        window.ajaxurl = '<?php echo todo_admin_url('admin-ajax.php'); ?>';
    }
    var ajaxurl = window.ajaxurl;

    jQuery(document).ready(function ($) {
        // 全选/取消全选
        var $targets = $('input[name="translation_ids[]"], input[name="term_ids[]"]');
        if ($targets.length === 0) {
            $('#cb-select-all').prop('checked', false).prop('disabled', true).attr('title', '当前视图无可批量选择项');
            $('#bulk-action-selector-top').prop('disabled', true);
        } else {
            $('#cb-select-all').prop('disabled', false).attr('title', '全选/取消全选');
        }

        $('#cb-select-all').on('change', function () {
            $targets.prop('checked', this.checked);
        });

        // 语言变化时加载对应主题的词汇
        $('#quick_language').on('change', function () {
            var lang = $(this).val();
            if (!lang) {
                $('#quick_term_id').html('<option value="">请选择词汇（按目标语言映射主题过滤）...</option>');
                return;
            }
            loadQuickTerms(lang);
        });

        // 点击模态框外部关闭
        window.onclick = function (event) {
            var getEl = function(id) { return document.getElementById(id) || (window.parent !== window ? window.parent.document.getElementById(id) : null); };
            var editModal = getEl('edit-translation-modal');
            var quickModal = getEl('quick-translate-modal');

            if (event.target === editModal) {
                if (typeof hideEditTranslationModal === 'function') hideEditTranslationModal();
            }
            if (event.target === quickModal) {
                if (typeof hideQuickTranslateModal === 'function') hideQuickTranslateModal();
            }
        };
    });
</script>

<div class="admin-page">
    <div class="admin-page-header">
        <a href="<?php echo todo_admin_url('index.php?page=language'); ?>" class="button button-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="vertical-align: middle; margin: -10px -5px -7px -5px;"><path fill="currentColor" d="M6 19h3.692v-5.884h4.616V19H18v-9l-6-4.538L6 10zm-1 1V9.5l7-5.288L19 9.5V20h-5.692v-5.884h-2.616V20zm7-7.77"></path></svg>
            语言首页
        </a>
        <div class="admin-page-actions">
            <?php
            $back_url = todo_admin_url('index.php?page=language-translations');
            if (isset($language_code) && $language_code !== '') {
                $back_url = todo_admin_url('index.php?page=language-terms');
                if (function_exists('add_query_arg')) {
                    $back_url = add_query_arg('lang', $language_code, $back_url);
                } else {
                    $back_url .= (strpos($back_url, '?') === false ? '?' : '&') . 'lang=' . urlencode($language_code);
                }
            }
            ?>
            
            <?php if (!$term_id): ?>
                <a href="javascript:void(0);" class="button button-primary" onclick="showQuickTranslateModal()">快速翻译</a>
            <?php else: ?>
                <a href="<?php echo esc_url($back_url); ?>" class="button button-secondary">返回列表</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- 当前词汇信息 -->
    <?php if ($current_term): ?>
        <div class="notice">
            <p>
                当前词汇：<strong>"<?php echo esc_html($current_term->original_text); ?>"</strong>
                <span class="description">
                <?php if ($current_term->context): ?>
                    （上下文：<?php echo esc_html($current_term->context); ?>）
                <?php endif; ?>
                <?php if (!empty($language_code)): ?>
                    <?php
                    // 根据当前语言显示翻译文本；如无翻译则显示"未翻译"
                    $selected_row = null;
                    if (!empty($translations)) {
                        foreach ($translations as $row) {
                            if (intval($row->is_selected) === 1) {
                                $selected_row = $row;
                                break;
                            }
                        }
                        if (!$selected_row) {
                            $selected_row = $translations[0];
                        }
                    }
                    if ($selected_row && !empty($selected_row->translated_text)) {
                        echo '→ 翻译文本：' . esc_html($selected_row->translated_text);
                    } else {
                        echo '→ 未翻译';
                    }
                    ?>
                <?php endif; ?>
                </span>
            </p>
        </div>
    <?php endif; ?>

    <div class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-section">
                <h3 class="admin-card-title">翻译列表</h3>
                <?php if ($term_id): ?>
                    <div class="admin-card-subtitle">
                        <?php if (!empty($language_code)): ?>
                            <?php echo esc_html($language_code); ?> 语言下该词汇的翻译版本
                            <?php if (!empty($current_language_theme)): ?>（语言主题：<?php echo esc_html($current_language_theme); ?>）<?php endif; ?>
                        <?php else: ?>
                            该词汇在“所有语言”下的所有翻译版本
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!$term_id): ?>
                <div class="admin-card-actions">
                    <form method="get" action="" class="search-form todo-request">
                        <input type="hidden" name="page" value="language-translations">
                        <input type="search" name="search" value="<?php echo esc_attr($search); ?>" placeholder="搜索翻译...">
                        <select name="language">
                            <option value="">所有语言</option>
                            <?php foreach ($languages as $lang): ?>
                                <option value="<?php echo esc_attr($lang->code); ?>" <?php selected($language_code, $lang->code); ?>>
                                    <?php echo esc_html($lang->name); ?>
                                    <?php if ($lang->native_name && $lang->native_name !== $lang->name): ?>
                                        (<?php echo esc_html($lang->native_name); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button button-secondary">筛选</button>
                        <?php if ($search || $language_code): ?>
                            <a href="?page=language-translations" class="btn-normal m-l-05">重置</a>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <div class="admin-card-content">
            <?php if (!$term_id && !empty($language_code)): ?>
                <div class="admin-filter-bar">
                    <div class="description">
                        <?php if (!empty($current_language_theme)): ?>
                            当前语言主题：<strong><?php echo esc_html($current_language_theme); ?></strong>
                        <?php else: ?>
                            当前语言未映射主题，使用系统默认主题词汇
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" id="todo-clear-all-term-translations-form" class="todo-request" style="display:none;">
                <?php echo todo_csrf_field('language_translations_management'); ?>
                <input type="hidden" name="action" value="clear_all_term_translations">
            </form>

            <!-- 批量操作表单 -->
            <form method="post" id="translations-form" class="todo-request">
                <?php echo todo_csrf_field('language_translations_management'); ?>
                <input type="hidden" name="action" value="bulk_action">

                <div class="bulk-actions-bar">
                    <div class="bulk-actions">
                        <select name="bulk_action" id="bulk-action-selector-top" style="min-width: unset; width: 150px;">
                            <option value="">批量操作</option>
                            <option value="delete">删除</option>
                        </select>
                        <button type="submit" class="button button-secondary" onclick="return confirmBulkAction()">应用</button>
                        <?php if ($term_id): ?>
                            <?php $target_lang = !empty($language_code) ? $language_code : ''; ?>
                            <a href="javascript:void(0);" class="button button-primary" style="margin-left: 8px;"
                                onclick="editLanguageTranslation(<?php echo intval($term_id); ?>, '<?php echo esc_js($target_lang); ?>'); return false;">新翻译</a>
                        <?php endif; ?>
                        <?php if (!$term_id): ?>
                            <a href="javascript:void(0);" class="button button-secondary" onclick="showClearAllTermTranslationsModal()">清空所有词汇和翻译</a>
                        <?php endif; ?>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <?php
                        $pagination_add_args = array();
                        if (!empty($term_id)) {
                            $pagination_add_args['term_id'] = (int) $term_id;
                        }
                        if (!empty($language_code)) {
                            $pagination_add_args['language'] = $language_code;
                        }
                        if (!empty($search)) {
                            $pagination_add_args['search'] = $search;
                        }

                        echo todo_render_pagination(array(
                            'total_items' => $total_items,
                            'per_page' => $per_page,
                            'current_page' => $current_page,
                            'base_url' => todo_admin_url('index.php?page=language-translations'),
                            'add_args' => $pagination_add_args,
                            'show_count' => false,
                        ));
                        ?>
                    <?php endif; ?>
                </div>

                <!-- 翻译列表表格 -->
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="check-column">
                                <input type="checkbox" id="cb-select-all">
                            </th>
                            <?php if (!$term_id): ?>
                                <th style="min-width: 160px;">词汇文本</th>
                                <th style="min-width: 100px;">词汇键</th>
                                <th>上下文</th>
                            <?php endif; ?>
                            <th style="min-width: 120px;">语言</th>
                            <th style="min-width: 160px;">翻译文本</th>
                            <th style="min-width: 100px;">译者备注</th>
                            <th style="min-width: 140px;">更新时间</th>
                            <?php if ($term_id): ?>
                            <th style="min-width: 150px;">操作</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($translations) && is_array($translations)): $translations = array_reverse($translations); endif; ?>
                        <?php if (empty($translations)): ?>
                        <tr class="empty">
                            <td colspan="<?php echo $term_id ? '6' : '8'; ?>">
                                <div class="no-items">
                            <?php
                            // 细分：判断文件语言包是否已存在该词汇翻译
                            // 目标语言：优先取筛选语言，其次当前请求语言
                            $target_lang = !empty($language_code) ? $language_code : '';
                            if ($target_lang === '' && !empty($term_id) && isset($current_term) && is_object($current_term)) {
                                $registry = class_exists('LanguageRegistry') ? LanguageRegistry::get_instance() : null;
                                $themeLocale = '';
                                $map = get_option('language_theme_map', array());
                                if (is_string($map)) {
                                    $decoded = json_decode($map, true);
                                    $map = is_array($decoded) ? $decoded : array();
                                }
                                if (is_array($map) && !empty($current_term->theme)) {
                                    foreach ($map as $loc => $th) {
                                        if ($th === $current_term->theme) {
                                            $themeLocale = $loc;
                                            break;
                                        }
                                    }
                                }
                                if ($themeLocale === '' && $registry && method_exists($registry, 'get_languages')) {
                                    $langs = $registry->get_languages(true);
                                    foreach ($langs as $loc => $info) {
                                        if (!empty($info['theme']) && $info['theme'] === $current_term->theme) {
                                            $themeLocale = $loc;
                                            break;
                                        }
                                    }
                                }
                                if ($themeLocale !== '') {
                                    if (function_exists('canonical_locale')) {
                                        $target_lang = canonical_locale($themeLocale);
                                    } else {
                                        $target_lang = preg_replace('/^([a-z]{2})(-[A-Z]{2})?$/', '$1$2', $themeLocale);
                                    }
                                }
                                if ($target_lang === '') {
                                    $selected = $tododb->get_row("SELECT language_code FROM {$tododb->prefix}language_translations WHERE term_id = ? AND is_selected = 1 ORDER BY updated_at DESC LIMIT 1", [$term_id]);
                                    if ($selected && !empty($selected->language_code)) {
                                        if (function_exists('canonical_locale')) {
                                            $target_lang = canonical_locale($selected->language_code);
                                        } else {
                                            $target_lang = $selected->language_code;
                                        }
                                    }
                                }
                                if ($target_lang === '' && $registry && method_exists($registry, 'get_default_locale')) {
                                    $target_lang = $registry->get_default_locale();
                                }
                                if ($target_lang === '') {
                                    $langRow = $tododb->get_row("SELECT code FROM {$tododb->prefix}languages WHERE enabled = 1 ORDER BY is_default DESC, id ASC LIMIT 1");
                                    $target_lang = $langRow ? sanitize_text_field($langRow->code) : 'zh-CN';
                                }
                            }
                            if ($target_lang === '') {
                                $target_lang = function_exists('current_locale') ? current_locale() : 'zh-CN';
                            }
                            if (empty($current_language_theme) && !empty($target_lang)) {
                                $mapped_theme2 = function_exists('todo_get_mapped_theme_for_locale') ? todo_get_mapped_theme_for_locale($target_lang) : '';
                                $registry2 = isset($registry) && $registry ? $registry : (class_exists('LanguageRegistry') ? LanguageRegistry::get_instance() : null);
                                $lang_info2 = $registry2 ? $registry2->language_by_locale($target_lang) : null;
                                if (is_string($mapped_theme2) && $mapped_theme2 !== '') {
                                    $current_language_theme = $mapped_theme2;
                                } elseif (is_array($lang_info2) && !empty($lang_info2['theme'])) {
                                    $current_language_theme = preg_replace('/[^A-Za-z0-9_-]/', '', $lang_info2['theme']);
                                }
                            }
                            $normLocale = str_replace('-', '_', $target_lang);
                            // 主题候选：优先使用语言映射主题，其次当前激活主题
                            $themeCandidate = !empty($current_language_theme) ? $current_language_theme : (function_exists('get_theme_name') ? get_theme_name() : 'default');
                            // 计算主题绝对路径（兼容 THEMES_DIR 为绝对或相对路径）
                            $themesDir = defined('THEMES_DIR') ? THEMES_DIR : 'content/themes';
                            if (strpos($themesDir, '/') === 0 || (defined('ABSPATH') && strpos($themesDir, ABSPATH) === 0)) {
                                $themesBase = rtrim($themesDir, '/');
                            } else {
                                $themesBase = rtrim(ABSPATH, '/') . '/' . trim($themesDir, '/');
                            }
                            $themeDir = $themesBase . '/' . $themeCandidate;
                            // 候选文件：主题语言包与核心语言包
                            $candidate_files = array(
                                $themeDir . '/assets/lang/' . $normLocale . '.php',
                                rtrim(ABSPATH, '/') . '/assets/languages/' . $normLocale . '.php',
                            );
                            $has_file_translation = false;
                            $file_hit = '';
                            if (!empty($term_id) && isset($current_term) && is_object($current_term)) {
                                foreach ($candidate_files as $fp) {
                                    if (!file_exists($fp)) {
                                        continue;
                                    }
                                    static $langFileCache = [];
                                    if (!isset($langFileCache[$fp])) {
                                        $langFileCache[$fp] = include $fp;
                                    }
                                    $map = $langFileCache[$fp];
                                    if (is_array($map)) {
                                        $orig = (string) $current_term->original_text;
                                        if (isset($map[$orig]) && $map[$orig] !== '') {
                                            $has_file_translation = true;
                                            $file_hit = $fp;
                                            break;
                                        }
                                    }
                                }
                            }
                            $file_rel = $file_hit ? str_replace(rtrim(ABSPATH, '/') . '/', '', $file_hit) : '';
                            ?>
                            <?php if ($has_file_translation): ?>
                                <p><i class="toicon">✔</i>
                                    语言包中已存在该词汇翻译：<code><?php echo esc_html($file_rel); ?></code></p>
                            <?php else: ?>
                                <p>没有找到翻译。</p>
                            <?php endif; ?>
                            <?php if ($term_id): ?>
                                <?php /* 保持前面已计算的 $target_lang（含溯源回退），不再覆盖 */ ?>
                                <p class="m-t-20">
                                    <a href="javascript:void(0);" class="button button-primary"
                                        onclick="editLanguageTranslation(<?php echo intval($term_id); ?>, '<?php echo esc_js($target_lang); ?>'); return false;">新增翻译</a>
                                </p>
                                <p>
                                    <?php if (!empty($target_lang)): ?>
                                        <span class="description">为该词汇在
                                            <code><?php echo esc_html($target_lang); ?></code> 语言下创建翻译。</span>
                                    <?php else: ?>
                                        <span class="description">将在弹窗中选择目标语言后创建翻译。</span>
                                    <?php endif; ?>
                                </p>
                            <?php else: ?>
                                <p class="description">请选择词汇后进行新增。</p>
                            <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php
                        // 统计每个 (term_id, language_code) 的翻译版本数量，用于控制“设为选用”按钮显示
                        $lang_version_counts = [];
                        foreach ($translations as $tt) {
                            $k = $tt->term_id . ':' . $tt->language_code;
                            $lang_version_counts[$k] = ($lang_version_counts[$k] ?? 0) + 1;
                        }
                        $row_index = 0;
                        foreach ($translations as $translation): ?>
                            <tr>
                                <th>
                                    <?php if (!isset($translation->id) || intval($translation->id) <= 0): ?>
                                        <?php
                                        $tid = 0;
                                        if (isset($translation->term_id) && intval($translation->term_id) > 0) {
                                            $tid = intval($translation->term_id);
                                        } elseif (isset($translation->lt_id) && intval($translation->lt_id) > 0) {
                                            $tid = intval($translation->lt_id);
                                        }
                                        ?>
                                        <?php if ($tid > 0): ?>
                                            <input type="checkbox" name="term_ids[]" value="<?php echo $tid; ?>" title="删除该词汇（及其所有语言翻译）">
                                        <?php else: ?>
                                            <input type="checkbox" disabled title="缺少词汇ID，无法批量操作">
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <input type="checkbox" name="translation_ids[]" value="<?php echo intval($translation->id); ?>">
                                    <?php endif; ?>
                                </th>

                                <?php if (!$term_id): ?>
                                    <td>
                                        <strong><?php echo esc_html($translation->original_text); ?></strong>
                                        <div class="row-actions">
                                            <span class="view">
                                                <?php $term_id_for_view = (isset($translation->term_id) && intval($translation->term_id) > 0) ? intval($translation->term_id) : ((isset($translation->lt_id) && intval($translation->lt_id) > 0) ? intval($translation->lt_id) : 0); ?>
                                                <?php if ($term_id_for_view > 0): ?>
                                                    <a
                                                        href="<?php echo todo_admin_url('index.php?page=language-translations&term_id=' . $term_id_for_view); ?>">所有翻译版本</a>
                                                <?php else: ?>
                                                    <span class="description">无法定位词汇ID</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <code><?php echo esc_html($translation->term_key); ?></code>
                                    </td>
                                    <td>
                                        <?php echo $translation->context ? esc_html($translation->context) : ''; ?>
                                    </td>
                                <?php endif; ?>

                                <td>
                                    <?php if (!$term_id): ?>
                                        <?php echo esc_html($translation->language_name); ?>
                                    <?php else: ?>
                                        <strong><?php echo esc_html($translation->language_name); ?></strong>
                                    <?php endif; ?>
                                    
                                    <div class="row-actions">
                                        <?php if (!$term_id): ?>
                                            <?php $term_id_for_view = (isset($translation->term_id) && intval($translation->term_id) > 0) ? intval($translation->term_id) : ((isset($translation->lt_id) && intval($translation->lt_id) > 0) ? intval($translation->lt_id) : 0); ?>
                                            <?php $target_lang = !empty($language_code) ? $language_code : ''; ?>
                                            <span class="edit">
                                                <?php if (isset($translation->id) && intval($translation->id) > 0): ?>
                                                    <a href="javascript:void(0);"
                                                        onclick="editTranslation(<?php echo intval($translation->id); ?>)">编辑</a> |
                                                <?php elseif ($term_id_for_view > 0): ?>
                                                    <a href="javascript:void(0);"
                                                        onclick="editLanguageTranslation(<?php echo $term_id_for_view; ?>, '<?php echo esc_js($target_lang); ?>')">新增翻译</a>
                                                <?php else: ?>
                                                    <span class="description">不可编辑（缺少词汇ID）</span>
                                                <?php endif; ?>
                                            </span>
                                            <?php if (isset($translation->id) && intval($translation->id) > 0): ?>
                                                <span class="delete">
                                                    <a href="javascript:void(0);"
                                                        onclick="deleteTranslation(<?php echo intval($translation->id); ?>)"
                                                        class="submitdelete">删除</a>
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="edit">
                                                <a href="javascript:void(0);"
                                                    onclick="editTranslation(<?php echo $translation->id; ?>)">编辑</a> |
                                            </span>
                                            <span class="delete">
                                                <a href="javascript:void(0);"
                                                    onclick="deleteTranslation(<?php echo $translation->id; ?>)"
                                                    class="submitdelete">删除</a>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td>
                                    <?php if ($translation->translated_text): ?>
                                        <div class="translation-text">
                                            <?php echo esc_html($translation->translated_text); ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="description">未翻译</span>
                                    <?php endif; ?>
                                    <?php
                                    // 在非空列表视图中，也提示是否存在“文件语言包”命中，用于辅助判断是否需要新增数据库翻译
                                    // 仅在存在 term_id（查看某词汇的所有翻译）或能获取 original_text 时执行
                                    $origTextRow = isset($translation->original_text) ? (string) $translation->original_text : (isset($current_term->original_text) ? (string) $current_term->original_text : '');
                                    if ($origTextRow !== '') {
                                        $targetLangRow = $translation->language_code ?? ($language_code ?? current_locale());
                                        $normLocaleRow = str_replace('-', '_', $targetLangRow);
                                        $themeCandidateRow = !empty($current_language_theme) ? $current_language_theme : (function_exists('get_theme_name') ? get_theme_name() : 'default');
                                        $themesDirRow = defined('THEMES_DIR') ? THEMES_DIR : 'content/themes';
                                        if (strpos($themesDirRow, '/') === 0 || (defined('ABSPATH') && strpos($themesDirRow, ABSPATH) === 0)) {
                                            $themesBaseRow = rtrim($themesDirRow, '/');
                                        } else {
                                            $themesBaseRow = rtrim(ABSPATH, '/') . '/' . trim($themesDirRow, '/');
                                        }
                                        $themeDirRow = $themesBaseRow . '/' . $themeCandidateRow;
                                        $candidateFilesRow = array(
                                            $themeDirRow . '/assets/lang/' . $normLocaleRow . '.php',
                                            rtrim(ABSPATH, '/') . '/assets/languages/' . $normLocaleRow . '.php',
                                        );
                                        $hasFileTransRow = false;
                                        $fileHitRow = '';
                                        foreach ($candidateFilesRow as $fpRow) {
                                            if (!file_exists($fpRow)) {
                                                continue;
                                            }
                                            static $langFileCacheRow = [];
                                            if (!isset($langFileCacheRow[$fpRow])) {
                                                $langFileCacheRow[$fpRow] = include $fpRow;
                                            }
                                            $mapRow = $langFileCacheRow[$fpRow];
                                            if (is_array($mapRow) && isset($mapRow[$origTextRow]) && $mapRow[$origTextRow] !== '') {
                                                $hasFileTransRow = true;
                                                $fileHitRow = $fpRow;
                                                break;
                                            }
                                        }
                                        if ($hasFileTransRow) {
                                            $fileRelRow = str_replace(rtrim(ABSPATH, '/') . '/', '', $fileHitRow);
                                            echo '<div class="description" style="margin-top:4px;">语言包命中：<code>' . esc_html($fileRelRow) . '</code></div>';
                                        }
                                    }
                                    ?>
                                </td>

                                <td>
                                    <?php if ($translation->translator_notes): ?>
                                        <div class="translator-notes" title="<?php echo esc_attr($translation->translator_notes); ?>">
                                            <?php echo esc_html(mb_substr($translation->translator_notes, 0, 50)); ?>
                                            <?php if (mb_strlen($translation->translator_notes) > 50): ?>...<?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td style="width: 150px;">
                                    <span class="tb-meta">
                                    <?php
                                    $dt = '';
                                    if (isset($translation->updated_at) && $translation->updated_at) {
                                        $dt = (string) $translation->updated_at;
                                    } elseif (isset($translation->created_at) && $translation->created_at) {
                                        $dt = (string) $translation->created_at;
                                    } elseif (isset($translation->term_updated_at) && $translation->term_updated_at) {
                                        $dt = (string) $translation->term_updated_at;
                                    } elseif (isset($translation->term_created_at) && $translation->term_created_at) {
                                        $dt = (string) $translation->term_created_at;
                                    }

                                    $ts = $dt !== '' ? strtotime($dt) : false;
                                    echo $ts ? date('Y-m-d H:i', $ts) : '—';
                                    ?>
                                    </span>
                                </td>
                                <?php if ($term_id): ?>
                                <td style="width: 150px;">
                                    <?php
                                    // 只有存在“同一语言的多个版本”时才提供“设为选用”按钮
                                    $key = $translation->term_id . ':' . $translation->language_code;
                                    $has_multiple_versions = isset($lang_version_counts[$key]) && $lang_version_counts[$key] > 1;
                                    if ($term_id && $has_multiple_versions && intval($translation->is_selected) !== 1): ?>
                                        <form method="post" style="display:inline;">
                                            <?php echo todo_csrf_field('language_translations_management'); ?>
                                            <input type="hidden" name="action" value="set_selected_version">
                                            <input type="hidden" name="translation_id" value="<?php echo $translation->id; ?>">
                                            <input type="hidden" name="term_id" value="<?php echo $translation->term_id; ?>">
                                            <input type="hidden" name="language_code"
                                                value="<?php echo esc_attr($translation->language_code); ?>">
                                            <button type="submit" class="btn button-primary todo-request">设为选用</button>
                                        </form>
                                    <?php else: ?>
                                        <?php if (intval($translation->is_selected) === 1): ?>
                                            <span class="description">当前选用</span>
                                        <?php else: ?>
                                            <span class="description">—</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <?php $row_index++; ?>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                    </tbody>
                </table>

                        <?php if ($total_pages > 1): ?>
                            <?php
                            $pagination_add_args = array();
                            if (!empty($term_id)) {
                                $pagination_add_args['term_id'] = (int) $term_id;
                            }
                            if (!empty($language_code)) {
                                $pagination_add_args['language'] = $language_code;
                            }
                            if (!empty($search)) {
                                $pagination_add_args['search'] = $search;
                            }

                            echo todo_render_pagination(array(
                                'total_items' => $total_items,
                                'per_page' => $per_page,
                                'current_page' => $current_page,
                                'base_url' => todo_admin_url('index.php?page=language-translations'),
                                'add_args' => $pagination_add_args,
                                'show_count' => true,
                            ));
                            ?>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 编辑翻译模态框 -->
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
                            <?php foreach ($languages as $lang): ?>
                                <option value="<?php echo esc_attr($lang->code); ?>">
                                    <?php echo esc_html($lang->name); ?>    <?php if ($lang->native_name && $lang->native_name !== $lang->name): ?>
                                        (<?php echo esc_html($lang->native_name); ?>)<?php endif; ?>
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

<!-- 快速翻译模态框 -->
<template id="todo-tpl-quick-translate-modal">
    <div class="todo-modal-body-content">
        <form method="get">
            <input type="hidden" name="page" value="language-translations">
            <?php if ($is_iframe): ?>
                <input type="hidden" name="format" value="iframe">
            <?php endif; ?>
            <table class="form-table">
                <tr>
                    <th style="width: 100px;">目标语言</th>
                    <td>
                        <select name="language" id="quick_language" class="regular-text">
                            <option value="">请选择语言...</option>
                            <?php foreach ($languages as $lang): ?>
                                <option value="<?php echo esc_attr($lang->code); ?>">
                                    <?php echo esc_html($lang->name); ?>
                                    <?php if ($lang->native_name && $lang->native_name !== $lang->name): ?>
                                        (<?php echo esc_html($lang->native_name); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th style="width: 100px;">选择词汇</th>
                    <td>
                        <select name="term_id" id="quick_term_id" class="regular-text" style="width: 220px; padding-right: 20px;">
                            <option value="">请选择词汇（按目标语言映射主题过滤）...</option>
                        </select>
                        <p class="description">提示：请选择目标语言后，将自动加载该语言映射主题下的词汇列表。</p>
                    </td>
                </tr>
            </table>
        </form>
    </div>
</template>

<style>
    .translation-text {
        max-width: 200px;
        word-wrap: break-word;
        line-height: normal;
    }

    .translator-notes {
        max-width: 150px;
        word-wrap: break-word;
        cursor: help;
    }

    .translation-status {
        padding: 2px 8px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: 500;
        text-transform: uppercase;
    }

    .status-completed {
        background: #d4edda;
        color: #155724;
    }

    .status-review {
        background: #d1ecf1;
        color: #0c5460;
    }

    .status-unknown {
        background: #f8d7da;
        color: #721c24;
    }

    /* 新增：明确“已驳回”状态样式 */
    .status-rejected {
        background: #f8d7da;
        color: #721c24;
    }
</style>
