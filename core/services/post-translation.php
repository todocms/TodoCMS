<?php
/**
 * 文章翻译服务
 *
 * 提供文章翻译的读取、写入与聚合输出能力
 */

if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

/**
 * 翻译记录静态缓存
 * @var array
 */
$GLOBALS['todo_post_translations_cache'] = [];

function todo_get_post_translation_exact($post_id, $language_code)
{
    global $tododb;
    $post_id = (int) $post_id;
    $language_code = trim((string) $language_code);
    if ($post_id <= 0 || $language_code === '') {
        return null;
    }
    $canonical = canonical_locale($language_code);
    $table = $tododb->prefix . 'post_translations';
    $row = $tododb->get_row("SELECT * FROM {$table} WHERE post_id = ? AND language_code = ? LIMIT 1", [$post_id, $canonical], 'ARRAY_A');
    return $row ?: null;
}

/**
 * 批量预加载文章翻译记录
 *
 * @param array $post_ids
 * @param string $language_code
 * @return void
 */
function todo_prime_post_translations($post_ids, $language_code)
{
    global $tododb;

    if (empty($post_ids)) {
        return;
    }

    $post_ids = array_unique(array_filter(array_map('intval', $post_ids)));
    if (empty($post_ids)) {
        return;
    }

    static $preloaded_langs = [];
    $canonical = canonical_locale($language_code);
    if (!isset($preloaded_langs[$canonical]) && $tododb) {
        $preloaded_langs[$canonical] = true;
        $table_trans = $tododb->prefix . 'post_translations';
        if (!method_exists($tododb, 'table_exists') || $tododb->table_exists('post_translations')) {
            $alt_lang = str_replace('-', '_', $canonical);
            $all_rows = $tododb->get_results("SELECT * FROM {$table_trans} WHERE language_code = ? OR language_code = ? ORDER BY id DESC LIMIT 1000", [$canonical, $alt_lang], 'ARRAY_A');
            if ($all_rows && is_array($all_rows)) {
                foreach ($all_rows as $row) {
                    $pid = intval($row['post_id']);
                    $lcode = $row['language_code'];
                    $GLOBALS['todo_post_translations_cache'][$pid . '|' . $lcode] = $row;
                    $GLOBALS['todo_post_translations_cache'][$pid . '|' . str_replace('_', '-', $lcode)] = $row;
                    $GLOBALS['todo_post_translations_cache'][$pid . '|' . str_replace('-', '_', $lcode)] = $row;
                    $GLOBALS['todo_post_translations_cache'][$pid . '|' . strtolower($lcode)] = $row;
                }
            }
        }
    }

    $canonical = canonical_locale($language_code);

    // 找出未缓存的
    $to_query = [];
    foreach ($post_ids as $id) {
        $k = $id . '|' . $canonical;
        if (!array_key_exists($k, $GLOBALS['todo_post_translations_cache'])) {
            $to_query[] = $id;
        }
    }

    if (empty($to_query)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($to_query), '?'));
    $table = $tododb->prefix . 'post_translations';
    $sql = "SELECT * FROM {$table} WHERE post_id IN ($placeholders)";
    $results = $tododb->get_results($sql, $to_query, 'ARRAY_A');

    // 初始化缓存项为 null
    foreach ($to_query as $id) {
        $GLOBALS['todo_post_translations_cache'][$id . '|' . $canonical] = null;
        $GLOBALS['todo_post_translations_cache'][$id . '|' . str_replace('-', '_', $canonical)] = null;
        $GLOBALS['todo_post_translations_cache'][$id . '|' . str_replace('_', '-', $canonical)] = null;
    }

    if ($results) {
        foreach ($results as $row) {
            $pid = intval($row['post_id']);
            $lcode = $row['language_code'];
            $GLOBALS['todo_post_translations_cache'][$pid . '|' . $lcode] = $row;
            $GLOBALS['todo_post_translations_cache'][$pid . '|' . str_replace('_', '-', $lcode)] = $row;
            $GLOBALS['todo_post_translations_cache'][$pid . '|' . str_replace('-', '_', $lcode)] = $row;
            $GLOBALS['todo_post_translations_cache'][$pid . '|' . strtolower($lcode)] = $row;
            
            if (class_exists('Todo_Cache_Manager')) {
                $cache_key = "post_translation_{$pid}_{$lcode}";
                Todo_Cache_Manager::set($cache_key, $row, 3600, 'posts', $lcode);
            }
        }
    }
}

/**
 * 获取单个文章的指定语言翻译记录
 *
 * @param int $post_id
 * @param string $language_code 例如 zh-CN、en-US
 * @return array|null 翻译记录（未命中返回 null）
 */
function todo_get_post_translation($post_id, $language_code)
{
    global $tododb;

    $post_id = intval($post_id);
    $language_code = trim($language_code);
    if ($post_id <= 0 || empty($language_code)) {
        return null;
    }

    // 归一化语言代码
    $canonical = canonical_locale($language_code);
    $cache_key = $post_id . '|' . $canonical;

    // 自动触发批量预加载全量热点文章多语言数据
    if (function_exists('todo_preload_post_translations')) {
        todo_preload_post_translations([$post_id], $language_code);
    }

    // 1. 检查静态缓存
    if (array_key_exists($cache_key, $GLOBALS['todo_post_translations_cache'])) {
        return $GLOBALS['todo_post_translations_cache'][$cache_key];
    }

    // 2. 检查持久化缓存（Redis）
    if (class_exists('Todo_Cache_Manager')) {
        $pkey = "post_translation_{$post_id}_{$canonical}";
        $cached = Todo_Cache_Manager::get($pkey, 'posts', $canonical);
        if ($cached !== false) {
            $GLOBALS['todo_post_translations_cache'][$cache_key] = $cached;
            return $cached;
        }
    }

    // 3. 查询数据库 - 精确匹配
    $table_translations = $tododb->prefix . 'post_translations';
    $sql = "SELECT * FROM {$table_translations} WHERE post_id = ? AND language_code = ? LIMIT 1";
    $row = $tododb->get_row($sql, [$post_id, $canonical], 'ARRAY_A');

    // 兼容历史：若未命中，尝试常见等价写法
    if (empty($row)) {
        $cands = array();
        $cands[] = strtolower($canonical);
        $cands[] = str_replace('-', '_', $canonical);
        $cands[] = strtoupper(str_replace('-', '_', $canonical));

        $parts = explode('-', $canonical);
        if (count($parts) > 1) {
            $cands[] = $parts[0];
        }

        if (strlen($language_code) === 2) {
            $cands[] = strtolower($language_code);
            if (class_exists('LanguageRegistry')) {
                $reg = LanguageRegistry::get_instance();
                $loc = $reg->locale_for_mark($language_code);
                if ($loc) {
                    $cands[] = $loc;
                }
            }
        }
        $cands = array_values(array_unique(array_filter($cands)));
        if (!empty($cands)) {
            $inPlaceholders = implode(',', array_fill(0, count($cands), '?'));
            $sql2 = "SELECT * FROM {$table_translations} WHERE post_id = ? AND language_code IN ($inPlaceholders) ORDER BY FIELD(language_code, " . implode(',', array_map(function ($v) {
                return "'" . addslashes($v) . "'";
            }, $cands)) . ") LIMIT 1";
            $params = array_merge([$post_id], $cands);
            $row = $tododb->get_row($sql2, $params, 'ARRAY_A');
        }
    }

    // 设置缓存
    $GLOBALS['todo_post_translations_cache'][$cache_key] = $row ?: null;
    
    if ($row && class_exists('Todo_Cache_Manager')) {
        $pkey = "post_translation_{$post_id}_{$canonical}";
        Todo_Cache_Manager::set($pkey, $row, 3600, 'posts', $canonical);
    }

    return $row ?: null;
}

/**
 * 创建或更新文章翻译（Upsert）
 *
 * @param int $post_id
 * @param string $language_code
 * @param array $data 可包含：title, content, excerpt, slug, custom_url, seo_title, seo_keywords, seo_description, status
 * @return array 保存后的翻译记录
 */
function todo_upsert_post_translation($post_id, $language_code, $data = [])
{
    global $tododb;

    $post_id = intval($post_id);
    $language_code = trim($language_code);
    if ($post_id <= 0 || empty($language_code)) {
        throw new Exception('参数不合法');
    }

    // 允许字段白名单
    $allowed = ['title', 'content', 'excerpt', 'seo_title', 'seo_keywords', 'seo_description', 'status'];
    $payload = [];
    foreach ($allowed as $key) {
        if (isset($data[$key])) {
            $payload[$key] = $data[$key];
        }
    }

    // 规范化语言码
    $canonical = canonical_locale($language_code);

    // 写入前校验：必须是已注册语言
    if (!is_valid_language($canonical)) {
        throw new Exception('非法或未启用语言：' . $canonical);
    }

    // 检查数据库表中是否存在该语言，如果不存在但 LanguageRegistry 中有，则自动创建
    $table_languages = $tododb->prefix . 'languages';
    // 兼容性：检查连字符与下划线两种格式 (如 zh-CN 与 zh_CN)
    $sqlCheck = "SELECT COUNT(*) FROM {$table_languages} WHERE code = ? OR code = ?";
    $underscore_loc = str_replace('-', '_', $canonical);
    $lang_count = (int) $tododb->get_var($sqlCheck, [$canonical, $underscore_loc]);

    if ($lang_count === 0) {
        // 如果数据库中不存在，但 LanguageRegistry 中有注册，则自动创建数据库记录
        require_once ABSPATH . 'core/classes/class-language-registry.php';
        $registry = LanguageRegistry::get_instance();
        $registry->init();
        $langInfo = $registry->language_by_locale($canonical);

        if ($langInfo && is_array($langInfo)) {
            // 自动创建数据库记录
            $langData = array(
                'code' => $canonical,
                'name' => isset($langInfo['title']) ? $langInfo['title'] : $canonical,
                'native_name' => isset($langInfo['native_name']) ? $langInfo['native_name'] : (isset($langInfo['title']) ? $langInfo['title'] : $canonical),
                'flag' => isset($langInfo['flag']) ? $langInfo['flag'] : null,
                'direction' => 'ltr',
                'status' => !empty($langInfo['enabled']) ? 'active' : 'inactive',
                'is_default' => !empty($langInfo['is_default']) ? 1 : 0,
                'sort_order' => isset($langInfo['sort_order']) ? intval($langInfo['sort_order']) : 0
            );
            $tododb->insert($table_languages, $langData);
            // 重新检查
            $lang_count = (int) $tododb->get_var($sqlCheck, [$canonical, $underscore_loc]);
        }

        // 如果仍然不存在，抛出异常
        if ($lang_count === 0) {
            error_log("todo_upsert_post_translation: Language not found in DB and auto-creation failed for locale: {$canonical}");
            throw new Exception('未注册语言，无法写入翻译：' . $canonical);
        }
    }

    // 查询是否存在
    $existing = todo_get_post_translation_exact($post_id, $canonical);
    $now = date('Y-m-d H:i:s');

    if ($existing) {
        // 更新
        $updateData = $payload;
        $updateData['updated_at'] = $now;
        $where = [
            'post_id' => $post_id,
            'language_code' => $canonical,
        ];
        $tododb->update($tododb->prefix . 'post_translations', $updateData, $where);
    } else {
        // 插入
        $insertData = [
            'post_id' => $post_id,
            'language_code' => $canonical,
            'status' => isset($payload['status']) ? $payload['status'] : 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        foreach ($payload as $k => $v) {
            $insertData[$k] = $v;
        }
        $tododb->insert($tododb->prefix . 'post_translations', $insertData);
    }

    // 缓存清理：删除该文章在对应语言的聚合缓存（精确删除所有常见变体）
    // 允许状态集合：前台通常会允许 approved 或 approved+pending 两种形态
    $allowed_sets = array(
        array('approved'),
        array('approved', 'pending'),
    );
    // 标志位组合：覆盖常见选项，确保不同形态的缓存都被清理
    $flag_sets = array(
        array('include_seo' => true, 'prefer_custom_url' => true, 'include_raw_translation' => false, 'fallback' => true, 'allow_default_translation' => false),
        array('include_seo' => true, 'prefer_custom_url' => true, 'include_raw_translation' => false, 'fallback' => false, 'allow_default_translation' => false),
        array('include_seo' => false, 'prefer_custom_url' => true, 'include_raw_translation' => false, 'fallback' => true, 'allow_default_translation' => false),
        array('include_seo' => false, 'prefer_custom_url' => true, 'include_raw_translation' => false, 'fallback' => false, 'allow_default_translation' => false),
        array('include_seo' => true, 'prefer_custom_url' => true, 'include_raw_translation' => true, 'fallback' => true, 'allow_default_translation' => false),
        array('include_seo' => true, 'prefer_custom_url' => true, 'include_raw_translation' => true, 'fallback' => false, 'allow_default_translation' => false),
        array('include_seo' => true, 'prefer_custom_url' => true, 'include_raw_translation' => false, 'fallback' => true, 'allow_default_translation' => true),
        array('include_seo' => true, 'prefer_custom_url' => true, 'include_raw_translation' => false, 'fallback' => false, 'allow_default_translation' => true),
    );
    foreach ($allowed_sets as $allowed) {
        $allowed_key = md5(implode(',', $allowed));
        foreach ($flag_sets as $fs) {
            $raw_key = 'post_multilang_' . $post_id . '_' . $canonical
                . '_' . ($fs['include_seo'] ? 'S1' : 'S0')
                . '_' . ($fs['prefer_custom_url'] ? 'U1' : 'U0')
                . '_' . ($fs['include_raw_translation'] ? 'R1' : 'R0')
                . '_' . ($fs['fallback'] ? 'F1' : 'F0')
                . '_' . ($fs['allow_default_translation'] ? 'D1' : 'D0')
                . '_' . $allowed_key;
            Todo_Cache_Manager::delete($raw_key, 'posts', $canonical);

            $raw_key_v2 = 'post_multilang_v2_' . $post_id . '_' . $canonical
                . '_' . ($fs['include_seo'] ? 'S1' : 'S0')
                . '_' . ($fs['prefer_custom_url'] ? 'U1' : 'U0')
                . '_' . ($fs['include_raw_translation'] ? 'R1' : 'R0')
                . '_' . ($fs['fallback'] ? 'F1' : 'F0')
                . '_' . ($fs['allow_default_translation'] ? 'D1' : 'D0')
                . '_' . $allowed_key;
            Todo_Cache_Manager::delete($raw_key_v2, 'posts', $canonical);
        }
    }

    // 清理原始翻译记录缓存
    Todo_Cache_Manager::delete("post_translation_{$post_id}_{$canonical}", 'posts', $canonical);

    if (method_exists('Todo_Cache_Manager', 'deleteByPattern')) {
        Todo_Cache_Manager::deleteByPattern('post_multilang_v2_' . $post_id . '_' . $canonical . '*');
        Todo_Cache_Manager::deleteByPattern('post_multilang_' . $post_id . '_' . $canonical . '*');
        Todo_Cache_Manager::deleteByPattern('post_translation_' . $post_id . '_' . $canonical);
    }

    // 清理内存中的静态缓存
    foreach (array_keys($GLOBALS['todo_post_translations_cache']) as $k) {
        if (!is_string($k)) {
            continue;
        }
        $prefix = $post_id . '|';
        if (strpos($k, $prefix) !== 0) {
            continue;
        }
        if (substr($k, -strlen('|' . $canonical)) === '|' . $canonical) {
            unset($GLOBALS['todo_post_translations_cache'][$k]);
        }
    }

    // 触发钩子：通知统一缓存管理器做页面缓存清理/预热等处理
    todo_do_action('todo_post_translation_updated', $post_id, $canonical);
    // 复用现有内容更新钩子，确保与文章发布保持一致的清理行为
    todo_do_action('todo_post_updated', $post_id);

    // 清理对象缓存（post_*等），避免后台/前台继续命中旧对象数据
    Todo_Unified_Cache_Manager::clean_post_cache($post_id);

    // 返回最新记录
    return todo_get_post_translation_exact($post_id, $canonical);
}

/**
 * 聚合输出：获取文章的多语言版本（带缓存与回退）
 *
 * 说明：
 * - 当指定语言有有效翻译（status=approved），会覆盖标题/内容/摘要/SEO等字段；
 * - 当翻译缺失或不满足状态要求，若 fallback=true 则回退到默认语言（原文）；
 * - URL 默认根据站点的语言前缀策略生成（依赖 get_language_url）。
 *
 * @param int $post_id 文章ID
 * @param string|null $language_code 语言代码；为空时自动取 get_current_language()
 * @param array $options 可选参数：
 *   - fallback(bool): 默认 true，翻译缺失时回退到默认语言
 *   - status_allowed(array|string): 默认 ['approved']，允许应用的翻译状态
 *   - include_seo(bool): 默认 true，返回 seo_title/seo_keywords/seo_description
 *   - include_raw_translation(bool): 默认 false，是否附带 raw_translation 字段
 *   - prefer_custom_url(bool): 默认 true，如翻译存在 custom_url 则优先使用
 * @return array|null 返回聚合后的文章数据（不存在或未发布返回 null）
 */
function todo_get_post_multilang($post_id, $language_code = null, $options = [])
{
    global $tododb;

    $post_id = intval($post_id);
    if ($post_id <= 0) {
        return null;
    }

    // 1. 优先计算语言
    $lang = $language_code ?: get_current_language();
    if (empty($lang)) {
        $lang = get_default_language();
    }
    if (empty($lang)) {
        $lang = 'en-US';
    }
    // 归一化
    $lang = canonical_locale($lang);

    // 2. 递归防护
    static $loading = [];
    $guard_key = $post_id . '_' . $lang;

    if (isset($loading[$guard_key])) {
        // 检测到递归调用，只在调试模式下记录（避免日志污染）
        if (defined('TODO_DEBUG') && TODO_DEBUG) {
            error_log("Recursion detected in todo_get_post_multilang for post_id: $post_id, lang: $lang");
        }
        // 返回null而不是继续递归
        return null;
    }

    // 标记为加载中
    $loading[$guard_key] = true;

    try {
        // 解析选项
        $opts = array_merge([
            'fallback' => true,
            'status_allowed' => ['approved'],
            'include_seo' => true,
            'include_raw_translation' => false,
            'prefer_custom_url' => true,
            'allow_default_translation' => false,
        ], is_array($options) ? $options : []);

        // 状态集合（参与缓存键）
        $allowed = is_array($opts['status_allowed']) ? array_values($opts['status_allowed']) : [$opts['status_allowed']];
        $allowed_key = md5(implode(',', $allowed));

        $cache_key = 'post_multilang_v2_' . $post_id . '_' . $lang
            . '_' . ($opts['include_seo'] ? 'S1' : 'S0')
            . '_' . ($opts['prefer_custom_url'] ? 'U1' : 'U0')
            . '_' . ($opts['include_raw_translation'] ? 'R1' : 'R0')
            . '_' . ($opts['fallback'] ? 'F1' : 'F0')
            . '_' . ($opts['allow_default_translation'] ? 'D1' : 'D0')
            . '_' . $allowed_key;

        $cached = todo_cache_get($cache_key, 'posts', $lang);
        if ($cached !== false && $cached !== null) {
            return $cached;
        }

        // 查询基础文章（仅发布）
        $base = null;
        if (isset($opts['post_object'])) {
            $obj = $opts['post_object'];
            if (is_object($obj)) {
                $base = (array) $obj;
            } elseif (is_array($obj)) {
                $base = $obj;
            }
        }

        if (empty($base)) {
            $table_posts = $tododb->prefix . 'posts';
            $sql = "SELECT ID, post_title, post_content, post_excerpt, post_date, post_modified, post_author, post_name, post_status, custom_url FROM {$table_posts} WHERE ID = ? LIMIT 1";
            $base = $tododb->get_row($sql, [$post_id], 'ARRAY_A');
        }

        if (empty($base)) {
            return null;
        }

        // 确保 $base 是数组（兼容对象返回）
        if (is_object($base)) {
            $base = (array) $base;
        }

        if (!isset($base['post_status']) || $base['post_status'] !== 'publish') {
            if ($base['post_status'] !== 'publish') {
                return null;
            }
        }

        // 基础结构
        $result = [
            'id' => intval($base['ID']),
            'title' => $base['post_title'],
            'content' => $base['post_content'],
            'excerpt' => $base['post_excerpt'],
            'date' => $base['post_date'],
            'modified' => $base['post_modified'],
            'slug' => $base['post_name'],
            'author' => intval($base['post_author']),
            'featured_image' => null,
            'categories' => [],
            'tags' => [],
            'language' => $lang,
            'source_language' => get_default_language(),
            'translated' => false,
            'translation_status' => null,
            'fields_overridden' => [],
        ];

        // 读取翻译
        $t_global = todo_get_post_translation_exact($post_id, $lang);
        $best_for_status = $t_global;
        $default_lang = get_default_language();
        $default_lang = $default_lang ? canonical_locale($default_lang) : $default_lang;
        $is_default_lang = ($default_lang && $default_lang === $lang);
        $selected_for_apply = null;
        foreach ([$t_global] as $cand) {
            if (!$cand || !is_array($cand)) {
                continue;
            }
            if (!isset($cand['status']) || !in_array($cand['status'], $allowed, true)) {
                continue;
            }
            if ($is_default_lang && empty($opts['allow_default_translation'])) {
                continue;
            }
            $selected_for_apply = $cand;
            break;
        }
        $translation = $selected_for_apply ?: $best_for_status;
        $apply_translation = (bool) $selected_for_apply;
        if ($apply_translation) {
            $map = [
                'title' => 'title',
                'content' => 'content',
                'excerpt' => 'excerpt',
            ];
            foreach ($map as $field => $tr_field) {
                if (!empty($translation[$tr_field])) {
                    $result[$field] = $translation[$tr_field];
                    $result['fields_overridden'][] = $field;
                }
            }
            if ($opts['include_seo']) {
                $seo_map = ['seo_title', 'seo_keywords', 'seo_description'];
                foreach ($seo_map as $k) {
                    if (!empty($translation[$k])) {
                        $result[$k] = $translation[$k];
                        $result['fields_overridden'][] = $k;
                    }
                }
                $pobj = get_post($post_id);
                $base_seo_title = '';
                $base_seo_keywords = '';
                $base_seo_description = '';
                if ($pobj && isset($pobj->seo_title) && $pobj->seo_title !== '') {
                    $base_seo_title = (string) $pobj->seo_title;
                }
                if ($pobj && isset($pobj->seo_keywords) && $pobj->seo_keywords !== '') {
                    $base_seo_keywords = (string) $pobj->seo_keywords;
                }
                if ($pobj && isset($pobj->seo_description) && $pobj->seo_description !== '') {
                    $base_seo_description = (string) $pobj->seo_description;
                }
                if ($base_seo_title === '') {
                    $base_seo_title = (string) get_post_meta($post_id, 'seo_title', true);
                }
                if ($base_seo_keywords === '') {
                    $base_seo_keywords = (string) get_post_meta($post_id, 'seo_keywords', true);
                }
                if ($base_seo_keywords === '') {
                    $base_seo_keywords = (string) get_post_meta($post_id, 'meta_keywords', true);
                }
                if ($base_seo_keywords === '') {
                    $base_seo_keywords = (string) get_post_meta($post_id, 'keywords', true);
                }
                if ($base_seo_description === '') {
                    $base_seo_description = (string) get_post_meta($post_id, 'seo_description', true);
                }
                if (empty($result['seo_title']) && $base_seo_title !== '') {
                    $result['seo_title'] = $base_seo_title;
                }
                if (empty($result['seo_keywords']) && $base_seo_keywords !== '') {
                    $result['seo_keywords'] = $base_seo_keywords;
                }
                if (empty($result['seo_description']) && $base_seo_description !== '') {
                    $result['seo_description'] = $base_seo_description;
                }
            }
            $base_url = get_permalink($post_id);
            if ($base_url) {
                $result['url'] = rtrim(get_language_url($lang, $base_url), '/');
            }
            $result['translated'] = true;
            $result['translation_status'] = $translation['status'] ?? null;
            if ($opts['include_raw_translation'] && $translation) {
                $result['raw_translation'] = $translation;
            }
        } else {
            $base_url = get_permalink($post_id);
            if ($base_url) {
                $result['url'] = rtrim(get_language_url($lang, $base_url), '/');
            }
            $result['translated'] = false;
            $result['translation_status'] = $translation['status'] ?? null;
            if ($opts['include_seo']) {
                $pobj = get_post($post_id);
                $base_seo_title = '';
                $base_seo_keywords = '';
                $base_seo_description = '';
                if ($pobj && isset($pobj->seo_title) && $pobj->seo_title !== '') {
                    $base_seo_title = (string) $pobj->seo_title;
                }
                if ($pobj && isset($pobj->seo_keywords) && $pobj->seo_keywords !== '') {
                    $base_seo_keywords = (string) $pobj->seo_keywords;
                }
                if ($pobj && isset($pobj->seo_description) && $pobj->seo_description !== '') {
                    $base_seo_description = (string) $pobj->seo_description;
                }
                if ($base_seo_title === '') {
                    $base_seo_title = (string) get_post_meta($post_id, 'seo_title', true);
                }
                if ($base_seo_keywords === '') {
                    $base_seo_keywords = (string) get_post_meta($post_id, 'seo_keywords', true);
                }
                if ($base_seo_keywords === '') {
                    $base_seo_keywords = (string) get_post_meta($post_id, 'meta_keywords', true);
                }
                if ($base_seo_keywords === '') {
                    $base_seo_keywords = (string) get_post_meta($post_id, 'keywords', true);
                }
                if ($base_seo_description === '') {
                    $base_seo_description = (string) get_post_meta($post_id, 'seo_description', true);
                }
                if ($base_seo_title !== '') {
                    $result['seo_title'] = $base_seo_title;
                }
                if ($base_seo_keywords !== '') {
                    $result['seo_keywords'] = $base_seo_keywords;
                }
                if ($base_seo_description !== '') {
                    $result['seo_description'] = $base_seo_description;
                }
            }
        }

        // 写入缓存
        todo_cache_set($cache_key, $result, null, 'posts', $lang);

        return $result;
    } catch (Exception $e) {
        // 发生错误时，记录但不输出（避免日志污染）
        if (defined('TODO_DEBUG') && TODO_DEBUG) {
            error_log('todo_get_post_multilang error: ' . $e->getMessage());
        }
        return null;
    } finally {
        // 确保无论如何都清理标记（防止递归）
        unset($loading[$guard_key]);
    }
}

/**
 * 列出文章自定义字段的指定语言翻译集合
 * 返回形如 [field_key => field_value]
 */
function todo_list_post_custom_field_translations($post_id, $language_code)
{
    global $tododb;
    $post_id = intval($post_id);
    $language_code = trim($language_code);
    if ($post_id <= 0 || $language_code === '') {
        return array();
    }

    $canonical = canonical_locale($language_code);
    // 校验语言合法性与注册状态
    $table_languages = $tododb->prefix . 'languages';
    $sqlCheck = "SELECT COUNT(*) FROM {$table_languages} WHERE code = ?";
    $lang_count = (int) $tododb->get_var($sqlCheck, [$canonical]);
    if ($lang_count === 0) {
        return array();
    }

    $table = $tododb->prefix . 'post_custom_field_translations';
    $rows = $tododb->get_results(
        "SELECT field_key, field_value FROM {$table} WHERE post_id = ? AND language_code = ?",
        [$post_id, $canonical],
        'ARRAY_A'
    );
    $map = array();
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $map[$r['field_key']] = maybe_unserialize($r['field_value']);
        }
    }
    return $map;
}

/**
 * 批量写入/更新文章自定义字段翻译
 * @param int $post_id
 * @param string $language_code
 * @param array $fields 形如 [field_key => field_value]
 * @param string|null $status 状态（pending/approved/rejected/draft），默认沿用传入或 pending
 * @return array 保存结果：['saved' => [keys...], 'updated' => n, 'inserted' => n]
 */
function todo_upsert_post_custom_field_translations($post_id, $language_code, $fields = array(), $status = null)
{
    global $tododb;
    $post_id = intval($post_id);
    $language_code = trim($language_code);
    if ($post_id <= 0 || $language_code === '' || !is_array($fields)) {
        throw new Exception('参数不合法');
    }
    $canonical = function_exists('canonical_locale') ? canonical_locale($language_code) : $language_code;
    // 校验语言
    if (!is_valid_language($canonical)) {
        throw new Exception('非法或未启用语言：' . $canonical);
    }

    // 检查数据库表中是否存在该语言，如果不存在但 LanguageRegistry 中有，则自动创建
    $table_languages = $tododb->prefix . 'languages';
    $sqlCheck = "SELECT COUNT(*) FROM {$table_languages} WHERE code = ?";
    $lang_count = (int) $tododb->get_var($sqlCheck, [$canonical]);

    if ($lang_count === 0) {
        // 如果数据库中不存在，但 LanguageRegistry 中有注册，则自动创建数据库记录
        require_once ABSPATH . 'core/classes/class-language-registry.php';
        $registry = LanguageRegistry::get_instance();
        $registry->init();
        $langInfo = $registry->language_by_locale($canonical);

        if ($langInfo && is_array($langInfo)) {
            // 自动创建数据库记录
            $langData = array(
                'code' => $canonical,
                'name' => isset($langInfo['title']) ? $langInfo['title'] : $canonical,
                'native_name' => isset($langInfo['native_name']) ? $langInfo['native_name'] : (isset($langInfo['title']) ? $langInfo['title'] : $canonical),
                'flag' => isset($langInfo['flag']) ? $langInfo['flag'] : null,
                'direction' => 'ltr',
                'status' => !empty($langInfo['enabled']) ? 'active' : 'inactive',
                'is_default' => !empty($langInfo['is_default']) ? 1 : 0,
                'sort_order' => isset($langInfo['sort_order']) ? intval($langInfo['sort_order']) : 0
            );
            $tododb->insert(TABLE_PREFIX . 'languages', $langData);
            // 重新检查
            $lang_count = (int) $tododb->get_var($sqlCheck, [$canonical]);
        }

        // 如果仍然不存在，抛出异常
        if ($lang_count === 0) {
            throw new Exception('未注册语言，无法写入翻译：' . $canonical);
        }
    }

    $table = $tododb->prefix . 'post_custom_field_translations';
    $now = date('Y-m-d H:i:s');
    $ins = 0;
    $upd = 0;
    $keys = array();

    foreach ($fields as $k => $v) {
        // 字段键安全白名单：字母/数字/下划线/中划线
        $field_key = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $k);
        if ($field_key === '') {
            continue;
        }
        $keys[] = $field_key;
        // 序列化复杂值
        $val = is_array($v) || is_object($v) ? maybe_serialize($v) : (string) $v;
        // 先查是否存在
        $exists = $tododb->get_var(
            "SELECT id FROM {$table} WHERE post_id = ? AND field_key = ? AND language_code = ? LIMIT 1",
            [$post_id, $field_key, $canonical]
        );
        if ($exists) {
            $tododb->update($table, [
                'field_value' => $val,
                'status' => $status ? sanitize_text_field($status) : 'pending',
                'updated_at' => $now,
            ], [
                'id' => intval($exists)
            ]);
            $upd++;
        } else {
            $insertData = [
                'post_id' => $post_id,
                'field_key' => $field_key,
                'language_code' => $canonical,
                'field_value' => $val,
                'status' => $status ? sanitize_text_field($status) : 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $tododb->insert($table, $insertData);
            $ins++;
        }
    }

    // 钩子与缓存清理
    todo_do_action('todo_post_custom_field_translation_updated', $post_id, $canonical, $keys);
    todo_do_action('todo_post_updated', $post_id);

    // 清理特定的 multilang 缓存，因为自定义字段变更可能影响 multilang 输出（如果有集成）
    // 虽然默认实现不直接包含自定义字段，但为保险起见，清理该语言下的相关缓存
    if (class_exists('Todo_Cache_Manager')) {
        Todo_Cache_Manager::deleteByPattern('post_multilang_' . $post_id . '_' . $canonical . '*');
        Todo_Cache_Manager::deleteByPattern('post_multilang_v2_' . $post_id . '_' . $canonical . '*');
        // 同时清理内存中的静态缓存（如果有）
        foreach (array_keys($GLOBALS['todo_post_translations_cache']) as $k) {
            if (!is_string($k)) {
                continue;
            }
            $prefix = $post_id . '|';
            if (strpos($k, $prefix) !== 0) {
                continue;
            }
            if (substr($k, -strlen('|' . $canonical)) === '|' . $canonical) {
                unset($GLOBALS['todo_post_translations_cache'][$k]);
            }
        }
    }

    Todo_Unified_Cache_Manager::clean_post_cache($post_id);

    return array('saved' => $keys, 'updated' => $upd, 'inserted' => $ins);
}

/**
 * 批量获取多个文章的多语言数据（批量预加载模式）
 * 
 * 此函数一次性查询所有文章的翻译数据，避免N+1查询问题
 * 不包含 featured_image/categories/tags，由调用方负责获取
 *
 * @param array $post_ids 文章ID数组
 * @param string|null $language_code 语言代码，为空时自动取当前语言
 * @param array $options 可选参数：
 *   - fallback(bool): 默认 true
 *   - status_allowed(array|string): 默认 ['approved']
 *   - include_seo(bool): 默认 true
 * @return array 返回 [post_id => translation_data] 映射
 */
function todo_get_posts_multilang_batch($post_ids, $language_code = null, $options = [])
{
    global $tododb;

    // 参数验证
    if (empty($post_ids) || !is_array($post_ids)) {
        return [];
    }

    // 清理和验证post_ids
    $post_ids = array_map('intval', $post_ids);
    $post_ids = array_filter($post_ids, function ($id) {
        return $id > 0;
    });
    $post_ids = array_unique($post_ids);

    if (empty($post_ids)) {
        return [];
    }

    // 解析选项
    $opts = array_merge([
        'fallback' => true,
        'status_allowed' => ['approved'],
        'include_seo' => true,
    ], is_array($options) ? $options : []);

    // 计算语言
    $lang = $language_code ?: get_current_language();
    if (empty($lang)) {
        $lang = get_default_language();
    }
    if (empty($lang)) {
        $lang = 'en-US';
    }

    // 归一化语言代码
    $lang = canonical_locale($lang);

    // 状态集合
    $allowed = is_array($opts['status_allowed']) ? array_values($opts['status_allowed']) : [$opts['status_allowed']];

    // 性能优化：优先从内部对象缓存获取基础文章数据
    $base_posts = [];
    $ids_to_query = [];
    if (!isset($GLOBALS['todo_internal_post_cache'])) {
        $GLOBALS['todo_internal_post_cache'] = [];
    }

    foreach ($post_ids as $id) {
        // 增加字段校验：确保缓存的文章对象包含必要的字段，如 post_title
        if (isset($GLOBALS['todo_internal_post_cache'][$id]) && is_object($GLOBALS['todo_internal_post_cache'][$id]) && isset($GLOBALS['todo_internal_post_cache'][$id]->post_title)) {
            $obj = $GLOBALS['todo_internal_post_cache'][$id];
            // 确保正确转换为数组，处理可能存在的 ORM 对象提取属性
            $base_posts[] = method_exists($obj, 'to_array') ? $obj->to_array() : (array)$obj;
        } else {
            $ids_to_query[] = $id;
        }
    }

    if (!empty($ids_to_query)) {
        $placeholders = implode(',', array_fill(0, count($ids_to_query), '?'));
        $table_posts = $tododb->prefix . 'posts';
        $base_sql = "SELECT ID, post_title, post_content, post_excerpt, post_date, post_modified, post_author, post_name, post_status, custom_url 
                     FROM {$table_posts} 
                     WHERE ID IN ($placeholders) AND post_status = 'publish'";
        $queried_posts = $tododb->get_results($base_sql, $ids_to_query, 'ARRAY_A');
        if ($queried_posts) {
            foreach ($queried_posts as $p) {
                // 加倍保险：确保查询到的数据也是完整的（虽然 SELECT 语句已经指定了字段）
                if (isset($p['ID'])) {
                    $base_posts[] = $p;
                    // 同时同步更新内部缓存，确保后续请求能直接从缓存获取完整对象
                    $GLOBALS['todo_internal_post_cache'][intval($p['ID'])] = (object) $p;
                }
            }
        }
    }

    if (empty($base_posts)) {
        return [];
    }

    // 建立基础数据映射
    $result = [];
    foreach ($base_posts as $base) {
        $post_id = intval($base['ID']);
        $result[$post_id] = [
            'id' => $post_id,
            'title' => $base['post_title'] ?? '',
            'content' => $base['post_content'] ?? '',
            'excerpt' => $base['post_excerpt'] ?? '',
            'date' => $base['post_date'] ?? '',
            'modified' => $base['post_modified'] ?? '',
            'slug' => $base['post_name'] ?? '',
            'author' => intval($base['post_author'] ?? 0),
            'status' => $base['post_status'] ?? '', // Added post_status
            // 这些字段由调用方负责获取，避免递归调用
            'featured_image' => null,
            'categories' => [],
            'tags' => [],
            // 语言信息
            'language' => $lang,
            'source_language' => get_default_language(),
            'translated' => false,
            'translation_status' => null,
            'fields_overridden' => [],
        ];
    }

    // 批量查询翻译数据（加入内存缓存检查）
    if (!isset($GLOBALS['todo_post_translations_cache'])) {
        $GLOBALS['todo_post_translations_cache'] = [];
    }

    $ids_to_query = [];
    foreach ($post_ids as $id) {
        $cache_key = $id . '|' . $lang;
        if (!array_key_exists($cache_key, $GLOBALS['todo_post_translations_cache'])) {
            $ids_to_query[] = $id;
        }
    }

    if (!empty($ids_to_query)) {
        $placeholders = implode(',', array_fill(0, count($ids_to_query), '?'));
        $table_translations = $tododb->prefix . 'post_translations';
        $trans_sql = "SELECT * 
                      FROM {$table_translations} 
                      WHERE post_id IN ($placeholders) AND language_code = ?";
        $trans_params = array_merge($ids_to_query, [$lang]);
        
        $fetched_trans = $tododb->query($trans_sql, $trans_params);
        
        $trans_map = [];
        if (!empty($fetched_trans)) {
            foreach ($fetched_trans as $t) {
                $trans_map[intval($t['post_id'])] = $t;
            }
        }
        
        // 标记所有已查询的ID，包括没有翻译的，防止重复查询
        foreach ($ids_to_query as $id) {
            $GLOBALS['todo_post_translations_cache'][$id . '|' . $lang] = isset($trans_map[$id]) ? $trans_map[$id] : null;
        }
    }

    // 应用翻译数据
    foreach ($post_ids as $post_id) {
        if (!isset($result[$post_id])) {
            continue;
        }

        $trans = $GLOBALS['todo_post_translations_cache'][$post_id . '|' . $lang];
        if (!$trans) {
            continue;
        }


            // 检查状态是否允许
            if (!in_array($trans['status'], $allowed, true)) {
                continue;
            }

            // 如果当前语言是系统主语言，且未强制允许，则跳过应用翻译（防止切换主语言后被旧译文覆盖）
            $default_lang = function_exists('get_default_language') ? get_default_language() : 'zh-CN';
            $default_lang = $default_lang ? canonical_locale($default_lang) : null;
            if ($default_lang === $lang && empty($opts['allow_default_translation'])) {
                continue;
            }

            // 覆盖字段
            $map = [
                'title' => 'title',
                'content' => 'content',
                'excerpt' => 'excerpt',
            ];
            foreach ($map as $field => $tr_field) {
                if (!empty($trans[$tr_field])) {
                    $result[$post_id][$field] = $trans[$tr_field];
                    $result[$post_id]['fields_overridden'][] = $field;
                }
            }

            // SEO字段
            if ($opts['include_seo']) {
                $seo_map = ['seo_title', 'seo_keywords', 'seo_description'];
                foreach ($seo_map as $k) {
                    if (!empty($trans[$k])) {
                        $result[$post_id][$k] = $trans[$k];
                        $result[$post_id]['fields_overridden'][] = $k;
                    }
                }
            }

            // URL构建
            $base_url = get_permalink($post_id);
            if ($base_url) {
                $result[$post_id]['url'] = rtrim(get_language_url($lang, $base_url), '/');
            }

            $result[$post_id]['translated'] = true;
            $result[$post_id]['translation_status'] = $trans['status'] ?? null;
        }

    // 为未翻译的文章生成URL（如果启用fallback）
    if ($opts['fallback']) {
        foreach ($result as $post_id => &$data) {
            if (!$data['translated']) {
                $base_url = get_permalink($post_id);
                if ($base_url) {
                    $data['url'] = rtrim(get_language_url($lang, $base_url), '/');
                }
            }
        }
    }

    return $result;
}

/**
 * 删除指定文章的所有翻译记录（post_translations）
 *
 * @param int $post_id
 * @return array 统计信息：['deleted_rows'=>int,'languages'=>array]
 */
function todo_delete_post_translations($post_id)
{
    global $tododb;

    $post_id = (int) $post_id;
    if ($post_id <= 0) {
        return ['deleted_rows' => 0, 'languages' => []];
    }

    $table = $tododb->prefix . 'post_translations';
    $langs = $tododb->get_col("SELECT DISTINCT language_code FROM {$table} WHERE post_id = ?", [$post_id]);
    $langs = is_array($langs) ? array_values(array_unique(array_filter(array_map('strval', $langs)))) : [];

    $deleted_rows = 0;
    $res = $tododb->query("DELETE FROM {$table} WHERE post_id = ?", [$post_id]);
    if ($res !== false) {
        $deleted_rows = is_numeric($res) ? (int) $res : 0;
    }

    if (class_exists('Todo_Cache_Manager')) {
        foreach ($langs as $lang) {
            $canonical = function_exists('canonical_locale') ? canonical_locale($lang) : str_replace('_', '-', $lang);
            Todo_Cache_Manager::deleteByPattern('post_translation_' . $post_id . '_' . $canonical);
            Todo_Cache_Manager::deleteByPattern('post_multilang_' . $post_id . '_' . $canonical . '*');
            Todo_Cache_Manager::deleteByPattern('post_multilang_v2_' . $post_id . '_' . $canonical . '*');
        }
    }
    foreach (array_keys($GLOBALS['todo_post_translations_cache']) as $k) {
        if (!is_string($k)) {
            continue;
        }
        $prefix = $post_id . '|';
        if (strpos($k, $prefix) === 0) {
            unset($GLOBALS['todo_post_translations_cache'][$k]);
        }
    }
    if (class_exists('Todo_Unified_Cache_Manager')) {
        Todo_Unified_Cache_Manager::clean_post_cache($post_id);
    }

    return ['deleted_rows' => $deleted_rows, 'languages' => $langs];
}

/**
 * 清理 post_translations：孤儿记录、默认语言记录等
 *
 * @param array $options
 * @return array
 */
function todo_cleanup_post_translations($options = [])
{
    global $tododb;

    $opts = array_merge([
        'delete_orphans' => false,
        'delete_default_language' => false,
        'default_language' => function_exists('get_default_language') ? (string) get_default_language() : 'zh-CN',
        'limit' => 20000,
    ], is_array($options) ? $options : []);

    $stats = [
        'deleted_orphans' => 0,
        'deleted_default_language' => 0,
        'default_language' => $opts['default_language'],
    ];

    $table_trans = $tododb->prefix . 'post_translations';
    $table_posts = $tododb->prefix . 'posts';

    if (!empty($opts['delete_orphans'])) {
        $limit = max(1, (int) $opts['limit']);
        // MySQL 规范：多表 JOIN DELETE 语句不能直接使用 LIMIT 子句，改为使用 IN 子查询
        $sql = "DELETE FROM {$table_trans} WHERE post_id IN (
            SELECT tmp.post_id FROM (
                SELECT pt.post_id FROM {$table_trans} pt LEFT JOIN {$table_posts} p ON pt.post_id = p.ID WHERE p.ID IS NULL LIMIT {$limit}
            ) tmp
        )";
        $res = $tododb->query($sql);
        if ($res !== false && is_numeric($res)) {
            $stats['deleted_orphans'] = (int) $res;
        }
    }

    if (!empty($opts['delete_default_language'])) {
        $lang = function_exists('canonical_locale') ? canonical_locale((string) $opts['default_language']) : str_replace('_', '-', (string) $opts['default_language']);
        $limit = max(1, (int) $opts['limit']);

        $rows = $tododb->get_results(
            "SELECT DISTINCT post_id, language_code FROM {$table_trans} WHERE language_code = ? LIMIT {$limit}",
            [$lang],
            'ARRAY_A'
        );
        $res = $tododb->query("DELETE FROM {$table_trans} WHERE language_code = ? LIMIT {$limit}", [$lang]);
        if ($res !== false && is_numeric($res)) {
            $stats['deleted_default_language'] = (int) $res;
        }

        if (class_exists('Todo_Cache_Manager') && is_array($rows)) {
            foreach ($rows as $r) {
                $pid = isset($r['post_id']) ? (int) $r['post_id'] : 0;
                if ($pid <= 0) {
                    continue;
                }
                Todo_Cache_Manager::deleteByPattern('post_translation_' . $pid . '_' . $lang);
                Todo_Cache_Manager::deleteByPattern('post_multilang_' . $pid . '_' . $lang . '*');
                Todo_Cache_Manager::deleteByPattern('post_multilang_v2_' . $pid . '_' . $lang . '*');
                foreach (array_keys($GLOBALS['todo_post_translations_cache']) as $k) {
                    if (!is_string($k)) {
                        continue;
                    }
                    $prefix = $pid . '|';
                    if (strpos($k, $prefix) === 0) {
                        unset($GLOBALS['todo_post_translations_cache'][$k]);
                    }
                }
                if (class_exists('Todo_Unified_Cache_Manager')) {
                    Todo_Unified_Cache_Manager::clean_post_cache($pid);
                }
            }
        }
    }

    return $stats;
}

function todo_delete_post_translations_bulk($post_ids)
{
    global $tododb;

    $post_ids = array_values(array_unique(array_filter(array_map('intval', (array) $post_ids), function ($v) {
        return $v > 0;
    })));
    if (empty($post_ids)) {
        return ['deleted_rows' => 0, 'posts' => 0, 'languages' => []];
    }

    $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
    $table = $tododb->prefix . 'post_translations';

    $pairs = $tododb->get_results(
        "SELECT DISTINCT post_id, language_code FROM {$table} WHERE post_id IN ({$placeholders})",
        $post_ids,
        'ARRAY_A'
    );

    $deleted_rows = 0;
    $res = $tododb->query("DELETE FROM {$table} WHERE post_id IN ({$placeholders})", $post_ids);
    if ($res !== false && is_numeric($res)) {
        $deleted_rows = (int) $res;
    }

    $langs_by_post = [];
    if (is_array($pairs)) {
        foreach ($pairs as $r) {
            $pid = isset($r['post_id']) ? (int) $r['post_id'] : 0;
            $lang = isset($r['language_code']) ? (string) $r['language_code'] : '';
            if ($pid <= 0 || $lang === '') {
                continue;
            }
            if (!isset($langs_by_post[$pid])) {
                $langs_by_post[$pid] = [];
            }
            $langs_by_post[$pid][] = $lang;
        }
    }

    if (class_exists('Todo_Cache_Manager')) {
        foreach ($langs_by_post as $pid => $langs) {
            foreach (array_values(array_unique($langs)) as $lang) {
                $canonical = function_exists('canonical_locale') ? canonical_locale($lang) : str_replace('_', '-', $lang);
                Todo_Cache_Manager::deleteByPattern('post_translation_' . $pid . '_' . $canonical);
                Todo_Cache_Manager::deleteByPattern('post_multilang_' . $pid . '_' . $canonical . '*');
                Todo_Cache_Manager::deleteByPattern('post_multilang_v2_' . $pid . '_' . $canonical . '*');
            }
            foreach (array_keys($GLOBALS['todo_post_translations_cache']) as $k) {
                if (!is_string($k)) {
                    continue;
                }
                $prefix = ((int) $pid) . '|';
                if (strpos($k, $prefix) === 0) {
                    unset($GLOBALS['todo_post_translations_cache'][$k]);
                }
            }
            if (class_exists('Todo_Unified_Cache_Manager')) {
                Todo_Unified_Cache_Manager::clean_post_cache((int) $pid);
            }
        }
    }

    return ['deleted_rows' => $deleted_rows, 'posts' => count($post_ids), 'languages' => $langs_by_post];
}

function todo_delete_post_translations_range($start_id, $end_id, $limit = 20000)
{
    global $tododb;

    $start = (int) $start_id;
    $end = (int) $end_id;
    if ($start <= 0 || $end <= 0) {
        return ['deleted_rows' => 0, 'posts' => 0, 'start' => $start, 'end' => $end, 'truncated' => false];
    }
    if ($start > $end) {
        $tmp = $start;
        $start = $end;
        $end = $tmp;
    }
    $limit = max(1, (int) $limit);

    $table = $tododb->prefix . 'post_translations';
    $post_ids = $tododb->get_col(
        "SELECT DISTINCT post_id FROM {$table} WHERE post_id >= ? AND post_id <= ? ORDER BY post_id ASC LIMIT {$limit}",
        [$start, $end]
    );
    $post_ids = is_array($post_ids) ? array_values(array_filter(array_map('intval', $post_ids))) : [];
    $truncated = (count($post_ids) >= $limit);
    $bulk = todo_delete_post_translations_bulk($post_ids);
    return [
        'deleted_rows' => isset($bulk['deleted_rows']) ? (int) $bulk['deleted_rows'] : 0,
        'posts' => isset($bulk['posts']) ? (int) $bulk['posts'] : count($post_ids),
        'start' => $start,
        'end' => $end,
        'truncated' => $truncated,
    ];
}

if (function_exists('todo_add_action')) {
    todo_add_action('todo_post_deleted', 'todo_delete_post_translations', 20, 1);
}
