<?php
/**
 * TodoCMS 通用导入导出管理器
 *
 * 用途：
 * - 提供词汇、订单、留言、用户、文章等列表的通用 JSON 导出能力
 * - 提供语言翻译 JSON 的通用导入能力
 *
 * @package TodoCMS

 * @version 1.0.0
 */

class TodoExportManager {
    /** @var TodoExportManager|null */
    private static $instance = null;

    /** @var mixed */
    private $db;

    /** @var array */
    private $lang_config = [];

    private function __construct() {
        global $tododb;
        $this->db = $tododb;
        $cfg_path = ABSPATH . 'core/config/languages.php';
        if (file_exists($cfg_path)) {
            $loaded = include $cfg_path;
            if (is_array($loaded)) {
                $this->lang_config = $loaded;
            }
        }
    }

    public static function get_instance() {
        if (!self::$instance) {
            self::$instance = new TodoExportManager();
        }
        return self::$instance;
    }

    // 统一的 locale 规范化
    public function canonical_locale($locale) {
        if (function_exists('canonical_locale')) {
            return canonical_locale($locale);
        }
        $locale = str_replace('_','-', trim($locale));
        // 简单规范：区域大写
        if (strpos($locale, '-') !== false) {
            [$l, $r] = explode('-', $locale, 2);
            return strtolower($l) . '-' . strtoupper($r);
        }
        return strtolower($locale);
    }

    // 校验 locale 是否在白名单且符合正则
    public function validate_locale($locale) {
        $locale = $this->canonical_locale($locale);
        $regex = isset($this->lang_config['regex']) ? $this->lang_config['regex'] : '/^[a-z]{2}(?:-[A-Z]{2})?$/';
        $okRegex = (bool)preg_match($regex, $locale);
        $whitelist = isset($this->lang_config['whitelist']) && is_array($this->lang_config['whitelist']) ? $this->lang_config['whitelist'] : [];
        return $okRegex && (empty($whitelist) || in_array($locale, $whitelist, true));
    }

    // 获取默认语言
    public function get_default_locale() {
        if (!empty($this->lang_config['fallback_locale'])) {
            return $this->lang_config['fallback_locale'];
        }
        return 'zh-CN';
    }

    // 语言存在性检查与注册（确保满足外键约束）
    private function language_exists($locale) {
        $locale = $this->canonical_locale($locale);
        $count = $this->db->get_var("SELECT COUNT(*) FROM {$this->db->prefix}languages WHERE code = ?", [$locale]);
        return intval($count) > 0;
    }

    private function try_register_language($locale) {
        $locale = $this->canonical_locale($locale);
        // 仅当在白名单内时允许注册
        $whitelist = isset($this->lang_config['whitelist']) && is_array($this->lang_config['whitelist']) ? $this->lang_config['whitelist'] : [];
        if (!empty($whitelist) && !in_array($locale, $whitelist, true)) {
            return false;
        }
        if ($this->language_exists($locale)) {
            return true;
        }
        // 从语言注册中心获取标题
        $title = $locale; $mark = substr($locale, 0, 2);
        if (isset($this->lang_config['languages']) && is_array($this->lang_config['languages'])) {
            foreach ($this->lang_config['languages'] as $lang) {
                if (isset($lang['locale']) && $this->canonical_locale($lang['locale']) === $locale) {
                    $title = isset($lang['title']) ? (string)$lang['title'] : $title;
                    $mark = isset($lang['mark']) ? (string)$lang['mark'] : $mark;
                    break;
                }
            }
        }
        // 简单推断国旗标识
        $flag = '';
        if (strpos($locale, '-') !== false) {
            [$l, $r] = explode('-', $locale, 2);
            $flag = strtolower($r);
        } else {
            $flag = strtolower($mark);
        }
        // 获取排序
        $sort = intval($this->db->get_var("SELECT COALESCE(MAX(sort_order),0) FROM {$this->db->prefix}languages")) + 1;
        $row = [
            'code' => $locale,
            'name' => $title,
            'native_name' => $title,
            'flag' => $flag,
            'direction' => 'ltr',
            'status' => 'active',
            'is_default' => 0,
            'sort_order' => $sort,
        ];
        $ok = $this->db->insert($this->db->prefix . 'languages', $row);
        return (bool)$ok;
    }

    // 通用导出包装
    public function build_export_json($type, array $meta, array $items) {
        $payload = [
            'version' => '1.0',
            'type' => $type,
            'meta' => $meta,
            'items' => $items,
            'exported_at' => date('c'),
        ];
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // 语言词汇导出（可选过滤：term_ids、context、theme）
    public function export_language_terms(array $options = []) {
        $term_ids = isset($options['term_ids']) && is_array($options['term_ids']) ? array_map('intval', $options['term_ids']) : [];
        $context = isset($options['context']) ? sanitize_text_field($options['context']) : '';
        $theme   = isset($options['theme']) ? sanitize_text_field($options['theme']) : '';

        $where = ['1=1'];
        $params = [];
        if (!empty($term_ids)) {
            $placeholders = implode(',', array_fill(0, count($term_ids), '?'));
            $where[] = "id IN ($placeholders)";
            $params = array_merge($params, $term_ids);
        }
        if (!empty($context)) {
            $where[] = 'context = ?';
            $params[] = $context;
        }
        if (!empty($theme)) {
            $where[] = 'theme = ?';
            $params[] = $theme;
        }
        $sql = "SELECT id, term_key, original_text, context, description, theme, auto_detected FROM {$this->db->prefix}language_terms WHERE " . implode(' AND ', $where) . " ORDER BY id";
        $rows = $this->db->get_results($sql, $params);
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int)$row->id,
                'term_key' => (string)$row->term_key,
                'original_text' => (string)$row->original_text,
                'context' => (string)$row->context,
                'description' => isset($row->description) ? (string)$row->description : '',
                'theme' => (string)$row->theme,
                'source' => $row->auto_detected ? 'auto' : 'manual',
            ];
        }
        $meta = [
            'theme' => $theme,
            'context' => $context,
            'default_locale' => $this->get_default_locale(),
        ];
        return $this->build_export_json('language_terms', $meta, $items);
    }

    // 语言翻译导入：接收 JSON 文本，写入/更新 language_translations
    public function import_language_translations(string $json, array $options = []) {
        $result = [
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $result['errors'][] = 'JSON解析失败';
            return $result;
        }
        if (!isset($data['type']) || !in_array($data['type'], ['language_translations','todo_translations','translations'], true)) {
            $result['errors'][] = '无效的导入类型';
            return $result;
        }
        $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : (isset($data['translations']) && is_array($data['translations']) ? $data['translations'] : []);
        if (empty($items)) {
            $result['errors'][] = '导入数据为空';
            return $result;
        }

        // 允许通过 theme 限制
        $themeLimit = isset($data['meta']['theme']) ? sanitize_text_field($data['meta']['theme']) : '';

        foreach ($items as $it) {
            $termKey = isset($it['term_key']) ? sanitize_text_field($it['term_key']) : '';
            $locale  = isset($it['locale']) ? sanitize_text_field($it['locale']) : '';
            $text    = isset($it['translated_text']) ? (string)$it['translated_text'] : '';
            $notes   = isset($it['translator_notes']) ? (string)$it['translator_notes'] : '';
            $status  = isset($it['status']) ? sanitize_text_field($it['status']) : 'pending';

            if (!$termKey || !$locale) {
                $result['skipped']++;
                $result['errors'][] = '缺少 term_key 或 locale';
                continue;
            }
            $locale = $this->canonical_locale($locale);
            if (!$this->validate_locale($locale)) {
                $result['skipped']++;
                $result['errors'][] = '不在白名单或非法的语言：' . $locale;
                continue;
            }
            // 确保语言已在 todo_languages 中注册，避免外键约束错误
            if (!$this->language_exists($locale)) {
                $try = $this->try_register_language($locale);
                if (!$try) {
                    $result['skipped']++;
                    $result['errors'][] = '目标语言未注册或不允许：' . $locale;
                    continue;
                }
            }

            // 依据 term_key 查找 term_id，且可选 theme 限制
            $where = 'term_key = ?';
            $params = [$termKey];
            if (!empty($themeLimit)) {
                $where .= ' AND theme = ?';
                $params[] = $themeLimit;
            }
            $termId = $this->db->get_var("SELECT id FROM {$this->db->prefix}language_terms WHERE $where", $params);
            if (!$termId) {
                $result['skipped']++;
                $result['errors'][] = '找不到术语：' . $termKey;
                continue;
            }

            // 检查是否已有翻译
            $exists = $this->db->get_var(
                "SELECT id FROM {$this->db->prefix}language_translations WHERE term_id = ? AND language_code = ?",
                [$termId, $locale]
            );

            $row = [
                'translated_text' => $text,
                'translator_notes' => $notes,
                'status' => in_array($status, ['pending','approved','rejected'], true) ? $status : 'pending',
                'updated_at' => current_time('mysql'),
            ];

            $overwrite = !empty($options['overwrite']);
            if ($exists) {
                if ($overwrite) {
                    $ok = $this->db->update("{$this->db->prefix}language_translations", $row, ['id' => intval($exists)]);
                    if ($ok !== false) { $result['updated']++; } else { $result['errors'][] = '更新失败：' . $termKey . ' [' . $locale . ']'; }
                } else {
                    // 不覆盖已存在的翻译，跳过
                    $result['skipped']++;
                }
            } else {
                $row['term_id'] = intval($termId);
                $row['language_code'] = $locale;
                $row['created_at'] = current_time('mysql');
                $ok = $this->db->insert("{$this->db->prefix}language_translations", $row);
                if ($ok) { $result['inserted']++; } else { $result['errors'][] = '插入失败：' . $termKey . ' [' . $locale . ']'; }
            }
        }

        return $result;
    }

    // 精简词汇导出：符合用户指定的 JSON 格式
    public function export_language_terms_simple(array $options = []) {
        $termIds = isset($options['term_ids']) && is_array($options['term_ids']) ? array_map('intval', $options['term_ids']) : [];
        $context = isset($options['context']) ? sanitize_text_field($options['context']) : '';
        $theme   = isset($options['theme']) ? sanitize_text_field($options['theme']) : '';

        $where = [];
        $params = [];
        if (!empty($termIds)) {
            $placeholders = implode(',', array_fill(0, count($termIds), '?'));
            $where[] = "id IN ($placeholders)";
            $params = array_merge($params, $termIds);
        }
        if (!empty($context)) { $where[] = 'context = ?'; $params[] = $context; }
        if (!empty($theme))   { $where[] = 'theme = ?';   $params[] = $theme; }
        $sql = "SELECT id, term_key, original_text FROM {$this->db->prefix}language_terms";
        if (!empty($where)) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $sql .= ' ORDER BY id ASC';
        $rows = $this->db->get_results($sql, $params);

        // 计算当前语言：优先使用传入参数，其次当前语言管理器，最后默认语言
        $locale = isset($options['language']) ? sanitize_text_field($options['language']) : (isset($options['locale']) ? sanitize_text_field($options['locale']) : '');
        if (!$locale) {
            $locale = $this->get_default_locale();
            if (function_exists('todo_language_manager')) {
                $mgr = todo_language_manager();
                if ($mgr && method_exists($mgr, 'get_current_language')) {
                    $locale = $mgr->get_current_language();
                }
            }
        }
        $locale = $this->canonical_locale($locale);

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => intval($row->id),
                'term_key' => (string)$row->term_key,
                'original_text' => (string)$row->original_text,
                'context' => ''
            ];
        }
        $payload = [
            'prompt' => '对items进行翻译，目标语言：current_language，原文：original_text，译文：context。语义精简，严格保留格式，确保JSON合法，最好提供JSON代码块或可直接下载的.json文件。',
            'meta' => [ 'current_language' => $locale ],
            'items' => $items,
        ];
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // 精简词汇导入：读取 meta.current_language 并将 items[*].context 写入翻译
    public function import_language_terms_simple(string $json, array $options = []) {
        $result = [ 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [] ];
        $data = json_decode($json, true);
        if (!is_array($data)) { $result['errors'][] = 'JSON解析失败'; return $result; }
        if (!isset($data['meta']['current_language'])) { $result['errors'][] = '缺少目标语言'; return $result; }
        $locale = $this->canonical_locale(sanitize_text_field($data['meta']['current_language']));
        if (!$this->validate_locale($locale)) { $result['errors'][] = '不在白名单或非法的语言：' . $locale; return $result; }
        // 确保语言已在 todo_languages 中注册，避免外键约束错误
        if (!$this->language_exists($locale)) {
            $try = $this->try_register_language($locale);
            if (!$try) {
                $result['errors'][] = '目标语言未注册或不允许：' . $locale;
                return $result;
            }
        }
        $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
        if (empty($items)) { $result['errors'][] = '导入数据为空'; return $result; }

        foreach ($items as $it) {
            $termKey = isset($it['term_key']) ? sanitize_text_field($it['term_key']) : '';
            $id      = isset($it['id']) ? intval($it['id']) : 0;
            $text    = isset($it['context']) ? (string)$it['context'] : '';
            if (!$termKey && !$id) { $result['skipped']++; $result['errors'][] = '缺少 term_key 或 id'; continue; }
            if ($text === '') { $result['skipped']++; continue; }

            // 查找 term
            $termId = 0;
            if ($termKey) {
                $termId = $this->db->get_var("SELECT id FROM {$this->db->prefix}language_terms WHERE term_key = ?", [$termKey]);
            }
            if (!$termId && $id) {
                $termId = $this->db->get_var("SELECT id FROM {$this->db->prefix}language_terms WHERE id = ?", [$id]);
            }
            if (!$termId) { $result['skipped']++; $result['errors'][] = '找不到术语：' . ($termKey ?: ('#' . $id)); continue; }

            // 是否已有翻译
            $exists = $this->db->get_var(
                "SELECT id FROM {$this->db->prefix}language_translations WHERE term_id = ? AND language_code = ?",
                [intval($termId), $locale]
            );
            $row = [
                'translated_text' => $text,
                'status' => 'approved',
                'updated_at' => current_time('mysql'),
            ];
            $overwrite = !empty($options['overwrite']);
            if ($exists) {
                if ($overwrite) {
                    $ok = $this->db->update("{$this->db->prefix}language_translations", $row, ['id' => intval($exists)]);
                    if ($ok !== false) { $result['updated']++; } else { $result['errors'][] = '更新失败：' . ($termKey ?: ('#' . $id)); }
                } else {
                    // 不覆盖已存在的翻译，跳过
                    $result['skipped']++;
                }
            } else {
                $row['term_id'] = intval($termId);
                $row['language_code'] = $locale;
                $row['created_at'] = current_time('mysql');
                $ok = $this->db->insert("{$this->db->prefix}language_translations", $row);
                if ($ok) { $result['inserted']++; } else { $result['errors'][] = '插入失败：' . ($termKey ?: ('#' . $id)); }
            }
        }
        return $result;
    }
}

// 便捷函数（todocms风格）
if (!function_exists('todo_export_manager')) {
    function todo_export_manager() { return TodoExportManager::get_instance(); }
}

if (!function_exists('todo_export_language_terms_json')) {
    function todo_export_language_terms_json($options = []) { return todo_export_manager()->export_language_terms($options); }
}

if (!function_exists('todo_import_language_translations_json')) {
    function todo_import_language_translations_json($json, $options = []) { return todo_export_manager()->import_language_translations($json, $options); }
}

if (!function_exists('todo_export_language_terms_simple_json')) {
    function todo_export_language_terms_simple_json($options = []) { return todo_export_manager()->export_language_terms_simple($options); }
}

if (!function_exists('todo_import_language_terms_simple_json')) {
    function todo_import_language_terms_simple_json($json, $options = []) { return todo_export_manager()->import_language_terms_simple($json, $options); }
}
