<?php
/**
 * TodoCMS 多语言字段与变量支持
 * - 文章自定义字段多语言
 * - 分类/标签（术语）SEO与自定义属性多语言
 * - 后台自定义变量（options）多语言
 *
 * @package TodoCMS
 */

// 直接访问保护

/**
 * 获取规范化的当前语言（hyphen 风格，如 zh-CN, en-US）
 */
function todo_current_locale($fallback = true)
{
    $locale = LocaleContext::get_instance()->get_locale();
    if (!$locale && $fallback) {
        $locale = LanguageRegistry::get_instance()->get_default_locale();
        if (!$locale) {
            $locale = 'zh-CN';
        }
    }
    if ($locale) {
        $locale = canonical_locale($locale);
    }
    return $locale;
}

/**
 * 获取文章自定义字段的语言覆盖（单个字段）
 */
function todo_get_post_custom_field_translation($post_id, $field_key, $locale = null, $options = array())
{
    global $tododb;
    if (!$post_id || !$field_key) {
        return null;
    }
    $locale = $locale ?: todo_current_locale(true);
    $allowed = isset($options['status_allowed']) ? (array) $options['status_allowed'] : array('approved', 'pending');
    $table = $tododb->prefix . 'post_custom_field_translations';

    // 查询函数（按指定语言尝试读取一条最新且优先已审核的记录）
    $query_fn = function ($loc) use ($tododb, $table, $post_id, $field_key, $allowed) {
        if (!$loc) {
            return null;
        }
        $sql = "SELECT field_value FROM {$table} WHERE post_id = ? AND field_key = ? AND language_code = ? AND status IN (" . implode(",", array_fill(0, count($allowed), '?')) . ") ORDER BY (status='approved') DESC, updated_at DESC, id DESC LIMIT 1";
        $params = array_merge(array($post_id, $field_key, $loc), $allowed);
        $val = $tododb->get_var($sql, $params);
        return ($val !== null && $val !== false) ? maybe_unserialize($val) : null;
    };

    // 1) 先按当前语言精确查询
    $val = $query_fn($locale);
    if ($val !== null && $val !== '') {
        return $val;
    }

    // 2) 构建回退链：LanguageRegistry -> 简写（en）
    $fallbacks = array();
    $reg = LanguageRegistry::get_instance();
    $fbList = (array) $reg->get_fallback_locales($locale);
    foreach ($fbList as $l) {
        if ($l && $l !== $locale) {
            $fallbacks[] = canonical_locale($l);
        }
    }
    // 默认语言追加到末尾
    $def = $reg->get_default_locale();
    if ($def && $def !== $locale) {
        $fallbacks[] = canonical_locale($def);
    }
    // 简写（如 en-US -> en）
    if (strpos($locale, '-') !== false) {
        $short = strtolower(strtok($locale, '-'));
        if ($short) {
            $fallbacks[] = $short;
        }
    }
    // 去重保持顺序
    $seen = array();
    $fallbacks = array_values(array_filter($fallbacks, function ($l) use (&$seen) {
        if (!$l)
            return false;
        $k = strtolower($l);
        if (isset($seen[$k]))
            return false;
        $seen[$k] = true;
        return true;
    }));

    foreach ($fallbacks as $loc) {
        $v = $query_fn($loc);
        if ($v !== null && $v !== '') {
            return $v;
        }
    }

    // 3) 未命中则返回 null，由上层负责继续回退到非多语言源
    return null;
}

/**
 * 解析内联占位符翻译文本
 * 示例：
 *  - "关键字[en|Keywords][ja|キーワード]" 在当前语言为 en-US 时输出 "Keywords"；在其他语言回退为 "关键字"
 * 规则：
 *  - 匹配形如 [lang|text] 的片段，其中 lang 支持 zh-CN / en-US / 简写 zh / en
 *  - 优先精确匹配规范化语言码，其次匹配简写（如 en-US → en）
 *  - 未命中时返回去除占位片段后的基础文本
 */
function todo_parse_inline_translations($text, $locale = null)
{
    if (!is_string($text) || $text === '' || strpos($text, '[') === false) {
        return $text;
    }
    $locale = $locale ?: todo_current_locale(true);
    if ($locale) {
        $locale = function_exists('canonical_locale') ? canonical_locale($locale) : str_replace('_', '-', $locale);
    }
    $target = strtolower((string) $locale);
    $target_short = strtok($target, '-');

    // 模式1：纯多语言定义块，如 [zh|接受全部][en|Accept All] 或 [en|Search]（除标签外无其他实质文本）
    $pattern = '/\[([a-z]{2}(?:-[a-z]{2})?)\|(.*?)\]/is';
    $stripped = trim(preg_replace($pattern, '', $text));
    if ($stripped === '') {
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            $map = array();
            foreach ($matches as $m) {
                $lang_raw = trim((string) $m[1]);
                $lang = function_exists('canonical_locale') ? canonical_locale($lang_raw) : str_replace('_', '-', $lang_raw);
                $lang_norm = strtolower($lang);
                $val = $m[2];
                $map[$lang_norm] = $val;
                $short = strtok($lang_norm, '-');
                if ($short !== '' && !isset($map[$short])) {
                    $map[$short] = $val;
                }
            }
            if (isset($map[$target])) {
                return $map[$target];
            }
            if ($target_short !== '' && isset($map[$target_short])) {
                return $map[$target_short];
            }
            return reset($map);
        }
    }

    // 模式2：内联混合文本，如 "搜索[en|Search]: %search_term%" 或 "页面未找到[en|Page Not Found] %separator%"
    return preg_replace_callback('/((?:[^\s\[\]]+?))?((?:\[[a-z]{2}(?:-[a-z]{2})?\|.*?\])+)/is', function($m) use ($target, $target_short) {
        $prefix = $m[1];
        $cluster = $m[2];
        preg_match_all('/\[([a-z]{2}(?:-[a-z]{2})?)\|(.*?)\]/is', $cluster, $matches, PREG_SET_ORDER);
        $map = array();
        foreach ($matches as $match) {
            $lang_raw = trim((string) $match[1]);
            $lang = function_exists('canonical_locale') ? canonical_locale($lang_raw) : str_replace('_', '-', $lang_raw);
            $lang_norm = strtolower($lang);
            $val = $match[2];
            $map[$lang_norm] = $val;
            $short = strtok($lang_norm, '-');
            if ($short !== '' && !isset($map[$short])) {
                $map[$short] = $val;
            }
        }
        if (isset($map[$target])) {
            return $map[$target];
        }
        if ($target_short !== '' && isset($map[$target_short])) {
            return $map[$target_short];
        }
        // 未命中目标语言时：若无前缀（独立嵌入标签），回退至首个翻译；若有前缀默认词，保留前缀并丢弃其他语言标签
        if ($prefix === '' || $prefix === null) {
            return reset($map);
        }
        return $prefix;
    }, $text);
}

function todo_map_inline_translations_deep($value, $locale = null)
{
    if (is_string($value)) {
        return todo_parse_inline_translations($value, $locale);
    }
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = todo_map_inline_translations_deep($v, $locale);
        }
        return $out;
    }
    if (is_object($value)) {
        foreach ($value as $k => $v) {
            $value->$k = todo_map_inline_translations_deep($v, $locale);
        }
        return $value;
    }
    return $value;
}

/**
 * 获取文章自定义字段（多语言覆盖集合）
 */
function todo_get_post_custom_fields_multilang($post_id, $locale = null, $options = array())
{
    global $tododb;
    $locale = $locale ?: todo_current_locale(true);
    $base = get_post_custom_fields($post_id);
    if (empty($post_id)) {
        return $base;
    }
    $allowed = isset($options['status_allowed']) ? (array) $options['status_allowed'] : array('approved', 'pending');
    $table = $tododb->prefix . 'post_custom_field_translations';
    $sql = "SELECT field_key, field_value FROM {$table} WHERE post_id = ? AND language_code = ? AND status IN (" . implode(",", array_fill(0, count($allowed), '?')) . ")";
    $params = array_merge(array($post_id, $locale), $allowed);
    $rows = $tododb->get_results($sql, $params);
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $base[$r->field_key] = maybe_unserialize($r->field_value);
        }
    }
    // 可选：附加原始翻译数据
    if (!empty($options['include_raw'])) {
        $base['_translation'] = array('locale' => $locale);
    }
    return $base;
}

/**
 * 批量获取文章自定义字段（多语言覆盖集合）
 * 
 * @param array $post_ids 文章ID数组
 * @param string|null $locale 语言代码
 * @param array $options 可选参数
 * @return array [post_id => fields]
 */
function todo_get_posts_custom_fields_multilang_batch($post_ids, $locale = null, $options = array())
{
    global $tododb;
    $locale = $locale ?: todo_current_locale(true);

    if (empty($post_ids)) {
        return [];
    }

    // 1. 确保元数据缓存已更新（利用TodoCMS核心机制）
    if (function_exists('update_post_meta_cache')) {
        update_post_meta_cache($post_ids);
    }

    $results = [];
    foreach ($post_ids as $pid) {
        $results[$pid] = get_post_custom_fields($pid);
        if (empty($results[$pid])) {
            // 兼容旧环境
            $custom_fields = get_post_meta($pid);
            if ($custom_fields) {
                foreach ($custom_fields as $key => $value) {
                    if (strpos($key, '_') === 0) {
                        continue;
                    }
                    $results[$pid][$key] = is_array($value) ? $value[0] : $value;
                }
            }
        }
    }

    $allowed = isset($options['status_allowed']) ? (array) $options['status_allowed'] : array('approved', 'pending');
    $table = $tododb->prefix . 'post_custom_field_translations';

    $ids_str = implode(',', array_map('intval', $post_ids));
    $placeholders = implode(",", array_fill(0, count($allowed), '?'));

    $sql = "SELECT post_id, field_key, field_value FROM {$table} WHERE post_id IN ($ids_str) AND language_code = ? AND status IN ($placeholders)";
    $params = array_merge(array($locale), $allowed);

    $rows = $tododb->get_results($sql, $params);

    if (is_array($rows)) {
        foreach ($rows as $r) {
            $pid = (int) $r->post_id;
            if (isset($results[$pid])) {
                $results[$pid][$r->field_key] = maybe_unserialize($r->field_value);
            }
        }
    }

    return $results;
}

/**
 * 分类/标签 SEO 多语言
 */
function todo_get_term_seo_multilang($term_id, $taxonomy = 'category', $locale = null, $options = array())
{
    global $tododb;
    $locale = $locale ?: todo_current_locale(true);
    $result = array('seo_title' => '', 'seo_keywords' => '', 'seo_description' => '');
    // 基础：主表 term_attributes（当前表结构无 taxonomy 列）
    $base = $tododb->get_row(
        "SELECT seo_title, seo_keywords, seo_description, attributes FROM {$tododb->prefix}term_attributes WHERE term_id = ? LIMIT 1",
        array($term_id)
    );
    if ($base) {
        $st = isset($base->seo_title) ? (string) $base->seo_title : '';
        $sk = isset($base->seo_keywords) ? (string) $base->seo_keywords : '';
        $sd = isset($base->seo_description) ? (string) $base->seo_description : '';
        if ($st !== '' || $sk !== '' || $sd !== '') {
            if ($st !== '') {
                $st = todo_parse_inline_translations($st, $locale);
            }
            if ($sk !== '') {
                $sk = todo_parse_inline_translations($sk, $locale);
            }
            if ($sd !== '') {
                $sd = todo_parse_inline_translations($sd, $locale);
            }
            $result['seo_title'] = $st;
            $result['seo_keywords'] = $sk;
            $result['seo_description'] = $sd;
        } else {
            $attrs = isset($base->attributes) ? json_decode($base->attributes, true) : null;
            if (is_array($attrs)) {
                if (!empty($attrs['seo_title'])) {
                    $result['seo_title'] = todo_parse_inline_translations((string) $attrs['seo_title'], $locale);
                }
                if (!empty($attrs['seo_keywords'])) {
                    $result['seo_keywords'] = todo_parse_inline_translations((string) $attrs['seo_keywords'], $locale);
                }
                if (!empty($attrs['seo_description'])) {
                    $result['seo_description'] = todo_parse_inline_translations((string) $attrs['seo_description'], $locale);
                }
            }
        }
    }
    // 翻译覆盖：term_translations
    $allowed = isset($options['status_allowed']) ? (array) $options['status_allowed'] : array('approved', 'pending');
    $table = $tododb->prefix . 'term_translations';
    $sql = "SELECT seo_title, seo_keywords, seo_description FROM {$table} WHERE term_id = ? AND taxonomy = ? AND language_code = ? AND status IN (" . implode(",", array_fill(0, count($allowed), '?')) . ") ORDER BY (status='approved') DESC, updated_at DESC, id DESC LIMIT 1";
    $params = array_merge(array($term_id, $taxonomy, $locale), $allowed);
    $t = $tododb->get_row($sql, $params);
    if ($t) {
        if (!empty($t->seo_title)) {
            $result['seo_title'] = (string) $t->seo_title;
        }
        if (!empty($t->seo_keywords)) {
            $result['seo_keywords'] = (string) $t->seo_keywords;
        }
        if (!empty($t->seo_description)) {
            $result['seo_description'] = (string) $t->seo_description;
        }
    }
    return $result;
}

/**
 * 分类/标签自定义属性（JSON）多语言
 */
function todo_get_term_attributes_multilang($term_id, $taxonomy = 'category', $locale = null, $options = array())
{
    global $tododb;
    $locale = $locale ?: todo_current_locale(true);
    $attrs = array();
    // 主表 JSON（当前表结构无 taxonomy 列）
    if (function_exists('get_term_attributes')) {
        $attrs = get_term_attributes($term_id);
    } else {
        $row = $tododb->get_row(
            "SELECT attributes FROM {$tododb->prefix}term_attributes WHERE term_id = ? LIMIT 1",
            array($term_id)
        );
        if ($row && !empty($row->attributes)) {
            $base = json_decode($row->attributes, true);
            if (is_array($base)) {
                $attrs = $base;
            }
        }
    }
    // 翻译覆盖：JSON 合并（浅覆盖）
    $allowed = isset($options['status_allowed']) ? (array) $options['status_allowed'] : array('approved', 'pending');
    $table = $tododb->prefix . 'term_translations';
    $sql = "SELECT attributes FROM {$table} WHERE term_id = ? AND taxonomy = ? AND language_code = ? AND status IN (" . implode(",", array_fill(0, count($allowed), '?')) . ") ORDER BY (status='approved') DESC, updated_at DESC, id DESC LIMIT 1";
    $params = array_merge(array($term_id, $taxonomy, $locale), $allowed);
    $t = $tododb->get_row($sql, $params);
    if ($t && !empty($t->attributes)) {
        $over = json_decode($t->attributes, true);
        if (is_array($over)) {
            foreach ($over as $k => $v) {
                $attrs[$k] = $v;
            }
        }
    }
    // 解析内联占位符翻译（深度处理）：如 banner_title "主标题[en|Main Title]"
    $attrs = todo_map_inline_translations_deep($attrs, $locale);
    return $attrs;
}

/**
 * Options 多语言读取（后台自定义变量）
 */
function todo_get_option_ml($key, $default = '', $locale = null, $options = array())
{
    global $tododb;
    $locale = $locale ?: todo_current_locale(true);

    // 静态缓存与一键预加载
    static $cache = [];
    static $preloaded = false;
    if (!$preloaded && $tododb) {
        $preloaded = true;
        if (method_exists($tododb, 'table_exists') && $tododb->table_exists('option_translations')) {
            $table_opt = $tododb->prefix . 'option_translations';
            $rows = $tododb->get_results("SELECT option_key, language_code, option_value FROM {$table_opt} WHERE status IN ('approved', 'pending') ORDER BY (status='approved') DESC, updated_at DESC, id DESC");
            if ($rows && is_array($rows)) {
                foreach ($rows as $r) {
                    $k = $r->option_key;
                    $l = $r->language_code;
                    $v = maybe_unserialize($r->option_value);
                    $v_mapped = todo_map_inline_translations_deep($v, $l);
                    $ck = $k . '|' . $l . '|' . md5(serialize([]));
                    if (!isset($cache[$ck])) {
                        $cache[$ck] = $v_mapped;
                    }
                }
            }
        }
    }

    $cache_key = $key . '|' . $locale . '|' . md5(serialize($options));

    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $allowed = isset($options['status_allowed']) ? (array) $options['status_allowed'] : array('approved', 'pending');
    $table = $tododb->prefix . 'option_translations';
    $sql = "SELECT option_value FROM {$table} WHERE option_key = ? AND language_code = ? AND status IN (" . implode(",", array_fill(0, count($allowed), '?')) . ") ORDER BY (status='approved') DESC, updated_at DESC, id DESC LIMIT 1";
    $params = array_merge(array($key, $locale), $allowed);
    $val = $tododb->get_var($sql, $params);
    if ($val !== null && $val !== '' && $val !== false) {
        $result = maybe_unserialize($val);
        // 解析行内多语言
        $result = todo_map_inline_translations_deep($result, $locale);

        $cache[$cache_key] = $result;
        return $result;
    }
    // 回退：默认语言
    if (!empty($options['fallback'])) {
        $fb = LanguageRegistry::get_instance()->get_default_locale();
        if ($fb && $fb !== $locale) {
            $params = array_merge(array($key, $fb), $allowed);
            $val2 = $tododb->get_var($sql, $params);
            if ($val2 !== null && $val2 !== '' && $val2 !== false) {
                $result = maybe_unserialize($val2);
                // 解析行内多语言
                $result = todo_map_inline_translations_deep($result, $locale);

                $cache[$cache_key] = $result;
                return $result;
            }
        }
    }
    // 最终回退：系统 option
    $result = todo_get_option($key, $default);

    // 解析行内多语言
    $result = todo_map_inline_translations_deep($result, $locale);

    $cache[$cache_key] = $result;
    return $result;
}

/**
 * 分类/标签基础文本（名称、描述）多语言
 */
function todo_get_term_text_multilang($term_id, $taxonomy = 'category', $locale = null, $options = array())
{
    global $tododb;
    $locale = $locale ?: todo_current_locale(true);
    $result = array('name' => '', 'description' => '');
    // 基础：主表（通过 get_term 或直接读取 terms 表）
    $term = get_term($term_id, $taxonomy);
    if (is_array($term)) {
        $result['name'] = isset($term['name']) ? todo_parse_inline_translations((string) $term['name'], $locale) : '';
        $result['description'] = isset($term['description']) ? todo_parse_inline_translations((string) $term['description'], $locale) : '';
    } elseif (is_object($term)) {
        $result['name'] = isset($term->name) ? todo_parse_inline_translations((string) $term->name, $locale) : '';
        $result['description'] = isset($term->description) ? todo_parse_inline_translations((string) $term->description, $locale) : '';
    }
    // 翻译覆盖：term_translations
    $allowed = isset($options['status_allowed']) ? (array) $options['status_allowed'] : array('approved', 'pending');
    $table = $tododb->prefix . 'term_translations';
    $sql = "SELECT name, description FROM {$table} WHERE term_id = ? AND taxonomy = ? AND language_code = ? AND status IN (" . implode(",", array_fill(0, count($allowed), '?')) . ") ORDER BY (status='approved') DESC, updated_at DESC, id DESC LIMIT 1";
    $params = array_merge(array($term_id, $taxonomy, $locale), $allowed);
    $t = $tododb->get_row($sql, $params);
    if ($t) {
        if (!empty($t->name)) {
            $result['name'] = (string) $t->name;
        }
        if (!empty($t->description)) {
            $result['description'] = (string) $t->description;
        }
    }
    return $result;
}

/**
 * 获取字符串的所有多语言候选值（用于规格匹配等过滤语言比较）
 * 包含：原始字符串、去除[lang|text]后的基础文本、各语言的解析值
 *
 * @param string|mixed $str
 * @return array 字符串候选数组
 */
function todo_get_string_multilang_candidates($str)
{
    if (!is_scalar($str)) {
        return array();
    }
    $raw = trim((string) $str);
    if ($raw === '') {
        return array();
    }
    $candidates = array($raw);

    if (strpos($raw, '[') !== false) {
        $pattern = '/\[([a-z]{2}(?:-[a-z]{2})?)\|(.*?)\]/is';
        if (preg_match_all($pattern, $raw, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $val = trim((string) $match[2]);
                if ($val !== '') {
                    $candidates[] = $val;
                }
            }
            $base = trim(preg_replace($pattern, '', $raw));
            if ($base !== '') {
                $candidates[] = $base;
            }
        }
    }

    return array_values(array_unique($candidates));
}

/**
 * 判断两个字符串在多语言/内联翻译过滤下是否匹配
 * 只要二者的候选集合存在交集，即视为匹配
 *
 * @param string|mixed $str1
 * @param string|mixed $str2
 * @return bool
 */
function todo_multilang_strings_match($str1, $str2)
{
    $s1 = trim((string) $str1);
    $s2 = trim((string) $str2);
    if ($s1 === $s2) {
        return true;
    }
    if ($s1 === '' || $s2 === '') {
        return false;
    }

    $cand1 = todo_get_string_multilang_candidates($s1);
    $cand2 = todo_get_string_multilang_candidates($s2);

    return !empty(array_intersect($cand1, $cand2));
}

?>
