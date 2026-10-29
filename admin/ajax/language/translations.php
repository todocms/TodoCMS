<?php
/**
 * 语言翻译 AJAX 处理器
 *
 * 提供翻译的增删改查与批量操作，供管理端异步交互使用。
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
if (!class_exists('Todo_Security_Manager') || !Todo_Security_Manager::verify_request_csrf('manage_translations')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid nonce']);
    exit;
}

if (!headers_sent()) {
    header('Content-Type: application/json');
}

$registry = class_exists('LanguageRegistry') ? LanguageRegistry::get_instance() : null;
$manager  = function_exists('todo_language_manager') ? todo_language_manager() : null;

global $tododb;
$action = isset($_POST['sub_action']) ? sanitize_text_field($_POST['sub_action']) : '';

// 规范化语言代码
function todo_normalize_locale_for_ajax($code, $registry) {
    $code = sanitize_text_field($code);
    if ($registry && method_exists($registry, 'canonicalize_locale')) {
        return $registry->canonicalize_locale($code);
    }
    $code = str_replace('_','-', $code);
    if (strpos($code, '-') !== false) {
        list($l,$r) = explode('-', $code, 2);
        $code = strtolower($l) . '-' . strtoupper($r);
    }
    return $code;
}

try {
    switch ($action) {
        case 'list': {
            $term_id = intval($_POST['term_id'] ?? 0);
            $lang    = isset($_POST['language_code']) ? todo_normalize_locale_for_ajax($_POST['language_code'], $registry) : '';
            $status  = sanitize_text_field($_POST['status'] ?? '');
            $search  = sanitize_text_field($_POST['s'] ?? '');
            $limit   = max(1, min(100, intval($_POST['limit'] ?? 20)));
            $offset  = max(0, intval($_POST['offset'] ?? 0));

            $where   = 'WHERE 1=1';
            $params  = [];
            if ($term_id > 0) { $where .= ' AND t.term_id = ?'; $params[] = $term_id; }
            if ($lang !== '') { $where .= ' AND t.language_code = ?'; $params[] = $lang; }
            if ($status !== '') { $where .= ' AND t.status = ?'; $params[] = $status; }
            if ($search !== '') {
                $where .= ' AND (lt.original_text LIKE ? OR t.translated_text LIKE ? OR t.translator_notes LIKE ?)';
                $like = '%' . $search . '%';
                array_push($params, $like, $like, $like);
            }

            $total = intval($tododb->get_var(
                "SELECT COUNT(*) FROM {$tododb->prefix}language_translations t
                 LEFT JOIN {$tododb->prefix}language_terms lt ON t.term_id = lt.id
                 {$where}",
                $params
            ));

            $rows = $tododb->get_results(
                "SELECT t.id, t.term_id, t.language_code, t.status, t.translated_text, t.translator_notes,
                        lt.original_text, lt.term_key, lt.context
                 FROM {$tododb->prefix}language_translations t
                 LEFT JOIN {$tododb->prefix}language_terms lt ON t.term_id = lt.id
                 {$where}
                 ORDER BY t.updated_at DESC
                 LIMIT {$limit} OFFSET {$offset}",
                $params
            );

            echo json_encode(['success' => true, 'data' => ['total' => $total, 'items' => $rows]]);
            break;
        }

        case 'save': {
            $term_id = intval($_POST['term_id'] ?? 0);
            $lang = todo_normalize_locale_for_ajax($_POST['language_code'] ?? '', $registry);
            $text = (string)($_POST['translated_text'] ?? '');
            $notes = (string)($_POST['translator_notes'] ?? '');
            if ($term_id <= 0 || $lang === '') {
                echo json_encode(['success' => false, 'data' => '参数错误']);
                break;
            }
            $existing = $tododb->get_row(
                "SELECT id FROM {$tododb->prefix}language_translations WHERE term_id = ? AND language_code = ?",
                [$term_id, $lang]
            );
            $data = [
                'translated_text' => $text,
                'translator_notes' => $notes,
                'status' => ($text === '' ? 'pending' : 'approved'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            if ($existing) {
                $tododb->update($tododb->prefix . 'language_translations', $data, ['id' => intval($existing->id)]);
            } else {
                $data['term_id'] = $term_id;
                $data['language_code'] = $lang;
                $data['created_at'] = date('Y-m-d H:i:s');
                $tododb->insert($tododb->prefix . 'language_translations', $data);
            }
            if ($manager && method_exists($manager, 'clear_translation_cache')) {
                $manager->clear_translation_cache($term_id, $lang);
            }
            echo json_encode(['success' => true, 'data' => '保存成功']);
            break;
        }

        case 'save_batch': {
            $term_id = intval($_POST['term_id'] ?? 0);
            $items = isset($_POST['translations']) && is_array($_POST['translations']) ? $_POST['translations'] : [];
            if ($term_id <= 0) { echo json_encode(['success' => false, 'data' => '参数错误']); break; }
            $updated = 0;
            foreach ($items as $lang => $payload) {
                $langCode = todo_normalize_locale_for_ajax($lang, $registry);
                $text   = isset($payload['translated_text']) ? (string)$payload['translated_text'] : '';
                $notes  = isset($payload['translator_notes']) ? (string)$payload['translator_notes'] : '';
                $status = isset($payload['status']) ? sanitize_text_field($payload['status']) : ($text === '' ? 'pending' : 'approved');
                if ($text === '' && $status === 'approved') { $status = 'pending'; }
                $existing = $tododb->get_row(
                    "SELECT id FROM {$tododb->prefix}language_translations WHERE term_id = ? AND language_code = ?",
                    [$term_id, $langCode]
                );
                $data = [
                    'translated_text' => $text,
                    'translator_notes' => $notes,
                    'status' => $status,
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                if ($existing) {
                    $tododb->update($tododb->prefix . 'language_translations', $data, ['id' => intval($existing->id)]);
                } else {
                    $data['term_id'] = $term_id;
                    $data['language_code'] = $langCode;
                    $data['created_at'] = date('Y-m-d H:i:s');
                    $tododb->insert($tododb->prefix . 'language_translations', $data);
                }
                if ($manager && method_exists($manager, 'clear_translation_cache')) {
                    $manager->clear_translation_cache($term_id, $langCode);
                }
                $updated++;
            }
            echo json_encode(['success' => true, 'data' => ['updated' => $updated]]);
            break;
        }

        case 'delete': {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['success' => false, 'data' => '参数错误']); break; }
            $row = $tododb->get_row("SELECT term_id, language_code FROM {$tododb->prefix}language_translations WHERE id = ?", [$id]);
            $tododb->delete($tododb->prefix . 'language_translations', ['id' => $id]);
            $tododb->delete($tododb->prefix . 'language_cache', ['translation_id' => $id]);
            if ($row && $manager && method_exists($manager, 'clear_translation_cache')) {
                $manager->clear_translation_cache(intval($row->term_id), sanitize_text_field($row->language_code));
            }
            echo json_encode(['success' => true, 'data' => '删除成功']);
            break;
        }

        case 'bulk_status': {
            $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
            $status = sanitize_text_field($_POST['status'] ?? 'pending');
            $updated = 0;
            foreach ($ids as $id) {
                $row = $tododb->get_row("SELECT term_id, language_code FROM {$tododb->prefix}language_translations WHERE id = ?", [$id]);
                if (!$row) { continue; }
                $tododb->update($tododb->prefix . 'language_translations', [
                    'status' => $status,
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id' => $id]);
                if ($manager && method_exists($manager, 'clear_translation_cache')) {
                    $manager->clear_translation_cache(intval($row->term_id), sanitize_text_field($row->language_code));
                }
                $updated++;
            }
            echo json_encode(['success' => true, 'data' => ['updated' => $updated]]);
            break;
        }

        case 'bulk_delete': {
            $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
            $deleted = 0;
            foreach ($ids as $id) {
                $row = $tododb->get_row("SELECT term_id, language_code FROM {$tododb->prefix}language_translations WHERE id = ?", [$id]);
                if (!$row) { continue; }
                $tododb->delete($tododb->prefix . 'language_translations', ['id' => $id]);
                $tododb->delete($tododb->prefix . 'language_cache', ['translation_id' => $id]);
                if ($manager && method_exists($manager, 'clear_translation_cache')) {
                    $manager->clear_translation_cache(intval($row->term_id), sanitize_text_field($row->language_code));
                }
                $deleted++;
            }
            echo json_encode(['success' => true, 'data' => ['deleted' => $deleted]]);
            break;
        }

        case 'get_translation': {
            $id = intval($_POST['translation_id'] ?? 0);
            if ($id <= 0) { echo json_encode(['success' => false, 'data' => '参数错误']); break; }
            $row = $tododb->get_row(
                "SELECT t.id, t.term_id, t.language_code, t.translated_text, t.translator_notes, t.status, t.is_selected,
                        lt.original_text, lt.term_key, lt.context,
                        l.name AS language_name, l.native_name AS native_name
                 FROM {$tododb->prefix}language_translations t
                 LEFT JOIN {$tododb->prefix}language_terms lt ON t.term_id = lt.id
                 LEFT JOIN {$tododb->prefix}languages l ON t.language_code = l.code
                 WHERE t.id = ?",
                [$id]
            );
            if (!$row) { echo json_encode(['success' => false, 'data' => '未找到翻译']); break; }
            // 统计该词汇在该语言下的版本数量
            $versions_count = (int)$tododb->get_var(
                "SELECT COUNT(*) FROM {$tododb->prefix}language_translations WHERE term_id = ? AND language_code = ?",
                [intval($row->term_id), sanitize_text_field($row->language_code)]
            );
            $row->versions_count = $versions_count;
            echo json_encode(['success' => true, 'data' => $row]);
            break;
        }

        case 'get_term_language_info': {
            $term_id = intval($_POST['term_id'] ?? 0);
            $req_lang = isset($_POST['language_code']) ? $_POST['language_code'] : '';
            $lang = todo_normalize_locale_for_ajax($req_lang, $registry);
            if ($term_id <= 0) { echo json_encode(['success' => false, 'data' => '参数错误']); break; }

            // 先读取词汇以便根据词汇所属主题推断语言
            $term = $tododb->get_row(
                "SELECT id, term_key, original_text, context, theme FROM {$tododb->prefix}language_terms WHERE id = ?",
                [$term_id]
            );
            if (!$term) { echo json_encode(['success' => false, 'data' => '词汇不存在']); break; }

            // 当未提供目标语言时，自动推断：优先使用“词汇所在的语言”（根据词汇主题映射），其次使用该词汇的选用版本语言，再次使用系统默认语言
            if ($lang === '') {
                // 1) 按词汇主题反查语言（options.language_theme_map: locale => theme）
                $themeLocale = '';
                $map = get_option('language_theme_map', array());
                if (is_string($map)) {
                    $decoded = json_decode($map, true);
                    $map = is_array($decoded) ? $decoded : array();
                }
                if (is_array($map) && !empty($term->theme)) {
                    foreach ($map as $loc => $th) {
                        if ($th === $term->theme) { $themeLocale = $loc; break; }
                    }
                }
                // 2) 若未命中 options 映射，尝试从注册中心语言配置中通过 info['theme'] 反查
                if ($themeLocale === '' && $registry && method_exists($registry, 'get_languages')) {
                    $langs = $registry->get_languages(true); // 仅启用的语言
                    foreach ($langs as $loc => $info) {
                        if (!empty($info['theme']) && $info['theme'] === $term->theme) { $themeLocale = $loc; break; }
                    }
                }
                if ($themeLocale !== '') {
                    $lang = todo_normalize_locale_for_ajax($themeLocale, $registry);
                }

                // 3) 若仍为空，使用该词汇的“选用版本”的语言
                if ($lang === '') {
                    $selected = $tododb->get_row(
                        "SELECT language_code FROM {$tododb->prefix}language_translations WHERE term_id = ? AND is_selected = 1 ORDER BY updated_at DESC LIMIT 1",
                        [$term_id]
                    );
                    if ($selected && !empty($selected->language_code)) {
                        $lang = todo_normalize_locale_for_ajax($selected->language_code, $registry);
                    }
                }
                // 4) 再次回退：注册中心默认语言
                if ($lang === '' && $registry && method_exists($registry, 'get_default_locale')) {
                    $lang = $registry->get_default_locale();
                }
                // 5) 最末回退：从 languages 表中取启用且默认的语言或第一条
                if ($lang === '') {
                    $langRow = $tododb->get_row("SELECT code FROM {$tododb->prefix}languages WHERE enabled = 1 ORDER BY is_default DESC, id ASC LIMIT 1");
                    $lang = $langRow ? sanitize_text_field($langRow->code) : 'zh-CN';
                }
            }

            // 取该语言下的翻译（若存在）
            $translation = $tododb->get_row(
                "SELECT id, translated_text, translator_notes, status FROM {$tododb->prefix}language_translations WHERE term_id = ? AND language_code = ?",
                [$term_id, $lang]
            );
            // 统计版本数
            $versions_count = (int)$tododb->get_var(
                "SELECT COUNT(*) FROM {$tododb->prefix}language_translations WHERE term_id = ? AND language_code = ?",
                [$term_id, $lang]
            );
            $langInfo = $registry && method_exists($registry, 'language_by_locale') ? $registry->language_by_locale($lang) : null;
            if (!$langInfo) {
                $langRow = $tododb->get_row("SELECT code, name, native_name FROM {$tododb->prefix}languages WHERE code = ?", [$lang]);
                $langInfo = $langRow ? ['locale' => $langRow->code, 'title' => $langRow->name, 'native_name' => $langRow->native_name] : ['locale' => $lang, 'title' => $lang, 'native_name' => $lang];
            }
            $data = [
                'term_id' => intval($term->id),
                'original_text' => (string)$term->original_text,
                'context' => (string)$term->context,
                'language_code' => $lang,
                'language_name' => isset($langInfo['title']) ? $langInfo['title'] : $lang,
                'native_name' => isset($langInfo['native_name']) ? $langInfo['native_name'] : $lang,
                'translated_text' => $translation ? (string)$translation->translated_text : '',
                'translator_notes' => $translation ? (string)$translation->translator_notes : '',
                'status' => $translation ? (string)$translation->status : 'pending',
                'versions_count' => $versions_count,
                'translation_id' => $translation ? intval($translation->id) : 0
            ];
            echo json_encode(['success' => true, 'data' => $data]);
            break;
        }

        case 'get_terms_for_quick_translate': {
            $lang = todo_normalize_locale_for_ajax($_POST['language_code'] ?? '', $registry);
            if ($lang === '') { echo json_encode(['success' => false, 'data' => '参数错误']); break; }
            // 尝试基于语言映射到主题进行过滤
            $theme = null;
            if ($registry && method_exists($registry, 'language_by_locale')) {
                $info = $registry->language_by_locale($lang);
                if (is_array($info) && !empty($info['theme'])) { $theme = $info['theme']; }
            }
            $params = [];
            $where = 'WHERE 1=1';
            if ($theme) { $where .= ' AND theme = ?'; $params[] = $theme; }
            $rows = $tododb->get_results(
                "SELECT id, original_text, context FROM {$tododb->prefix}language_terms {$where} ORDER BY updated_at DESC LIMIT 100",
                $params
            );
            echo json_encode(['success' => true, 'data' => $rows]);
            break;
        }

        default:
            echo json_encode(['success' => false, 'data' => '无效的操作']);
    }
    exit;
} catch (Exception $e) {
    error_log('Translations AJAX error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'data' => '操作失败']);
    exit;
}
