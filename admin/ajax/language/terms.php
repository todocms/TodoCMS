<?php
/**
 * 语言词汇 AJAX 处理器
 *
 * 提供词汇的查询、添加、编辑、删除与批量操作，供管理端异步交互使用。
 *
 * @package TodoCMS
 * @subpackage Language
 */

// 防止直接访问（AJAX环境）
if (!defined('DOING_AJAX') || !DOING_AJAX) {
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Direct access denied']);
        exit;
    }
}

// 权限检查
if (!function_exists('todo_current_user_can') || !todo_current_user_can('manage_languages')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

// CSRF校验（统一令牌）
if (!class_exists('Todo_Security_Manager') || !Todo_Security_Manager::verify_request_csrf('manage_terms')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid nonce']);
    exit;
}

header('Content-Type: application/json');

$registry = class_exists('LanguageRegistry') ? LanguageRegistry::get_instance() : null;
$manager = function_exists('todo_language_manager') ? todo_language_manager() : null;

// Ensure Export Manager is loaded
if (!class_exists('TodoExportManager')) {
    $export_manager_path = ABSPATH . 'core/classes/class-export-manager.php';
    if (file_exists($export_manager_path)) {
        require_once $export_manager_path;
    }
}

global $tododb;
$action = isset($_POST['sub_action']) ? sanitize_text_field($_POST['sub_action']) : '';

// 生成唯一 term_key（复用页面逻辑但以AJAX形式）
function todo_generate_term_key_ajax($original_text, $context, $theme = null)
{
    $base_key = sanitize_title($original_text);
    if (!empty($context)) {
        $base_key = sanitize_title($context) . '_' . $base_key;
    }
    if (!empty($theme)) {
        $base_key = sanitize_title($theme) . '_' . $base_key;
    }
    return $base_key; // 唯一性由数据库冲突检查后增量编号
}

try {
    switch ($action) {
        case 'list': {
            $search = sanitize_text_field($_POST['s'] ?? '');
            $context = sanitize_text_field($_POST['context'] ?? '');
            $theme = sanitize_text_field($_POST['theme'] ?? '');
            $status = sanitize_text_field($_POST['status'] ?? '');
            $limit = max(1, min(100, intval($_POST['limit'] ?? 20)));
            $offset = max(0, intval($_POST['offset'] ?? 0));

            $where = 'WHERE 1=1';
            $params = [];
            // 动态列存在检查
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
            $has_theme = intval($tododb->get_var(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'theme'",
                [$tododb->prefix . 'language_terms']
            )) > 0;

            if ($search !== '') {
                $like = '%' . $search . '%';
                if ($has_term_key && $has_desc) {
                    $where .= " AND (original_text LIKE ? OR term_key LIKE ? OR description LIKE ?)";
                    array_push($params, $like, $like, $like);
                } elseif ($has_term_key) {
                    $where .= " AND (original_text LIKE ? OR term_key LIKE ?)";
                    array_push($params, $like, $like);
                } elseif ($has_desc) {
                    $where .= " AND (original_text LIKE ? OR description LIKE ?)";
                    array_push($params, $like, $like);
                } else {
                    $where .= " AND original_text LIKE ?";
                    $params[] = $like;
                }
            }
            if ($context !== '') {
                $where .= ' AND context = ?';
                $params[] = $context;
            }
            if ($theme !== '' && $has_theme) {
                $where .= ' AND theme = ?';
                $params[] = $theme;
            }
            if ($status !== '') {
                if ($has_auto && $status === 'auto_detected') {
                    $where .= ' AND auto_detected = 1';
                } elseif ($has_auto && $status === 'manual') {
                    $where .= ' AND auto_detected = 0';
                }
            }

            $total = intval($tododb->get_var(
                "SELECT COUNT(*) FROM {$tododb->prefix}language_terms {$where}",
                $params
            ));
            $rows = $tododb->get_results(
                "SELECT * FROM {$tododb->prefix}language_terms {$where} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}",
                $params
            );
            echo json_encode(['success' => true, 'data' => ['total' => $total, 'items' => $rows]]);
            break;
        }

        case 'add': {
            $original_text = sanitize_text_field($_POST['original_text'] ?? '');
            $context = sanitize_text_field($_POST['context'] ?? '');
            $description = sanitize_textarea_field($_POST['description'] ?? '');
            $theme = sanitize_text_field($_POST['theme'] ?? '');
            if ($original_text === '') {
                echo json_encode(['success' => false, 'data' => '原始文本不能为空']);
                break;
            }
            // 检查列存在
            $has_theme = intval($tododb->get_var(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'theme'",
                [$tododb->prefix . 'language_terms']
            )) > 0;
            $term_key = todo_generate_term_key_ajax($original_text, $context, $has_theme ? $theme : null);
            // 保证唯一：若冲突则加后缀编号
            $suffix = 0;
            $final_key = $term_key;
            while ($tododb->get_var("SELECT COUNT(*) FROM {$tododb->prefix}language_terms WHERE term_key = ?", [$final_key])) {
                $suffix++;
                $final_key = $term_key . '_' . $suffix;
            }
            $data = [
                'term_key' => $final_key,
                'original_text' => $original_text,
                'context' => $context,
                'description' => $description,
                'auto_detected' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($has_theme) {
                $data['theme'] = $theme;
            }
            $tododb->insert($tododb->prefix . 'language_terms', $data);
            echo json_encode(['success' => true, 'data' => '添加成功', 'term_key' => $final_key]);
            break;
        }

        case 'get_term': {
            $term_id = intval($_POST['term_id'] ?? 0);
            if ($term_id <= 0) {
                echo json_encode(['success' => false, 'data' => '参数错误']);
                break;
            }
            // 查询词汇详情
            $row = $tododb->get_row("SELECT * FROM {$tododb->prefix}language_terms WHERE id = ?", [$term_id]);
            if (!$row) {
                echo json_encode(['success' => false, 'data' => '词汇不存在']);
                break;
            }
            // 兼容可选列：theme
            $has_theme = intval($tododb->get_var(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'theme'",
                [$tododb->prefix . 'language_terms']
            )) > 0;
            if (!$has_theme) {
                $row->theme = '';
            }
            echo json_encode(['success' => true, 'data' => $row]);
            break;
        }

        case 'edit': {
            $term_id = intval($_POST['term_id'] ?? 0);
            $original_text = sanitize_text_field($_POST['original_text'] ?? '');
            $context = sanitize_text_field($_POST['context'] ?? '');
            $description = sanitize_textarea_field($_POST['description'] ?? '');
            $theme = sanitize_text_field($_POST['theme'] ?? '');
            if ($term_id <= 0 || $original_text === '') {
                echo json_encode(['success' => false, 'data' => '参数错误']);
                break;
            }
            $has_theme = intval($tododb->get_var(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'theme'",
                [$tododb->prefix . 'language_terms']
            )) > 0;
            $data = [
                'original_text' => $original_text,
                'context' => $context,
                'description' => $description,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            if ($has_theme) {
                $data['theme'] = $theme;
            }
            $tododb->update($tododb->prefix . 'language_terms', $data, ['id' => $term_id]);
            // 编辑后清理各语言缓存（避免错位）
            if ($manager && method_exists($manager, 'get_supported_languages') && method_exists($manager, 'clear_translation_cache')) {
                $langs = $manager->get_supported_languages();
                foreach ($langs as $code => $info) {
                    $manager->clear_translation_cache($term_id, $code);
                }
            }
            echo json_encode(['success' => true, 'data' => '更新成功']);
            break;
        }

        case 'delete': {
            $term_id = intval($_POST['term_id'] ?? 0);
            if ($term_id <= 0) {
                echo json_encode(['success' => false, 'data' => '参数错误']);
                break;
            }
            // 删除词汇及其翻译
            $tododb->delete($tododb->prefix . 'language_translations', ['term_id' => $term_id]);
            $tododb->delete($tododb->prefix . 'language_terms', ['id' => $term_id]);
            echo json_encode(['success' => true, 'data' => '删除成功']);
            break;
        }

        case 'bulk_delete': {
            $ids = isset($_POST['term_ids']) && is_array($_POST['term_ids']) ? array_map('intval', $_POST['term_ids']) : [];
            $deleted = 0;
            foreach ($ids as $tid) {
                if ($tid <= 0) {
                    continue;
                }
                $tododb->delete($tododb->prefix . 'language_translations', ['term_id' => $tid]);
                $tododb->delete($tododb->prefix . 'language_terms', ['id' => $tid]);
                $deleted++;
            }
            echo json_encode(['success' => true, 'data' => ['deleted' => $deleted]]);
            break;
        }

        case 'bulk_set_context': {
            $ids = isset($_POST['term_ids']) && is_array($_POST['term_ids']) ? array_map('intval', $_POST['term_ids']) : [];
            $new_context = sanitize_text_field($_POST['new_context'] ?? '');
            $updated = 0;
            foreach ($ids as $tid) {
                if ($tid <= 0) {
                    continue;
                }
                $tododb->update($tododb->prefix . 'language_terms', [
                    'context' => $new_context,
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id' => $tid]);
                $updated++;
            }
            echo json_encode(['success' => true, 'data' => ['updated' => $updated]]);
            break;
        }

        case 'export_terms_simple': {
            // 参数解析
            $term_ids = isset($_POST['term_ids']) && is_array($_POST['term_ids']) ? array_map('intval', $_POST['term_ids']) : [];
            $context = sanitize_text_field($_POST['context'] ?? '');
            $theme = sanitize_text_field($_POST['theme'] ?? '');
            $language = sanitize_text_field($_POST['language'] ?? '');
            $options = ['term_ids' => $term_ids, 'context' => $context, 'theme' => $theme, 'language' => $language];

            // 检查导出函数是否存在
            if (!function_exists('todo_export_language_terms_simple_json')) {
                echo json_encode(['success' => false, 'error' => '导出功能未启用，请检查系统配置']);
                break;
            }

            // 生成精简JSON
            try {
                $json = todo_export_language_terms_simple_json($options);
                if (!$json || $json === '') {
                    echo json_encode(['success' => false, 'error' => '导出数据为空，请检查筛选条件或词汇数据']);
                    break;
                }

                $data = json_decode($json, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    echo json_encode(['success' => false, 'error' => 'JSON编码失败：' . json_last_error_msg()]);
                    break;
                }

                $count = is_array($data) && isset($data['items']) && is_array($data['items']) ? count($data['items']) : 0;
                if ($count === 0) {
                    echo json_encode(['success' => false, 'error' => '没有找到可导出的词汇，请检查筛选条件']);
                    break;
                }

                $locale = is_array($data) && isset($data['meta']['current_language']) ? $data['meta']['current_language'] : ($language ?: 'unknown');
                $filename = 'todo-terms-export-simple-' . $locale . '-' . $count . '.json';
                echo json_encode(['success' => true, 'data' => ['json' => $json, 'count' => $count, 'locale' => $locale, 'filename' => $filename]]);
                exit;
            } catch (Exception $e) {
                error_log('Export terms simple error: ' . $e->getMessage());
                echo json_encode(['success' => false, 'error' => '导出过程中发生错误：' . $e->getMessage()]);
            }
            break;
        }

        case 'import_translations': {
            if (todo_is_demo_admin_session()) {
                echo json_encode(['success' => false, 'error' => '演示模式！']);
                break;
            }
            $json = isset($_POST['json']) ? (string) $_POST['json'] : '';
            $overwrite = !empty($_POST['overwrite']); // 默认不覆盖
            if ($json === '') {
                echo json_encode(['success' => false, 'error' => '缺少导入JSON']);
                break;
            }
            $parsed = json_decode($json, true);
            if (!is_array($parsed)) {
                echo json_encode(['success' => false, 'error' => 'JSON解析失败']);
                break;
            }
            // 根据结构选择处理器：存在 meta.current_language 则走 simple；否则走完整翻译导入
            $result = null;
            if (isset($parsed['meta']) && isset($parsed['meta']['current_language'])) {
                $result = function_exists('todo_import_language_terms_simple_json') ? todo_import_language_terms_simple_json($json, ['overwrite' => $overwrite]) : null;
            } else {
                $result = function_exists('todo_import_language_translations_json') ? todo_import_language_translations_json($json, ['overwrite' => $overwrite]) : null;
            }
            if (!$result || !is_array($result)) {
                echo json_encode(['success' => false, 'error' => '导入失败，请检查JSON格式或稍后重试']);
                break;
            }
            echo json_encode(['success' => true, 'data' => $result]);
            break;
        }

        default:
            echo json_encode(['success' => false, 'data' => '无效的操作']);
    }
} catch (Exception $e) {
    error_log('Terms AJAX error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'data' => '操作失败']);
}
