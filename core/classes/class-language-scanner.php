<?php
/**
 * TodoCMS 语言包扫描与自动维护核心引擎
 *
 * 负责扫描全站模板（HTM/HTML/JS）、核心后端接口以及数据库中的标签/属性/等级，
 * 提取所有待翻译词条，比对差量并支持一键将翻译合并写回语言包。
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

class Todo_Language_Scanner {
    /**
     * 提取内容中所有的多语言标签原文
     *
     * @param string $content
     * @return array
     */
    public static function extract_all_language_tags($content) {
        $tags = [];
        if (empty($content) || !is_string($content)) {
            return $tags;
        }

        // 1. 模板标签：{{todo:t text="原文"}} / {{todo:t|text='原文'}} / {{todo:t|text=原文}} 等
        $pattern_tpl = '/\{\{todo:t\s*(?:\|\s*)?text\s*[=:]\s*(?:(["\'])(.*?)\1|([^\s|}]+))(?:\|\s*([^}]*))?\s*\}\}/i';
        if (preg_match_all($pattern_tpl, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $raw_text = !empty($m[2]) ? stripslashes($m[2]) : (isset($m[3]) ? $m[3] : '');
                $text = trim($raw_text);
                if ($text !== '') {
                    $tags[$text] = true;
                }
            }
        }

        // 2. JS / PHP 翻译函数：__('原文') / __t("原文") / _e("原文") / esc_html__("原文") 等
        $pattern_func = '/(?:^|[^\w$])(?:__|__t|_e|_x|_ex|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\s*\(\s*(["\'])(.*?)\1/u';
        if (preg_match_all($pattern_func, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $text = trim(stripslashes($m[2]));
                if ($text !== '') {
                    $tags[$text] = true;
                }
            }
        }

        return array_keys($tags);
    }

    /**
     * 递归全量扫描指定主题及核心后端文件中的已标记词汇
     *
     * @param string $theme 主题名称，默认 'default'
     * @param string|null $device_filter 设备类型 (pc / mobile)，默认全扫
     * @return array
     */
    public static function scan_all_tagged_strings($theme = 'default', $device_filter = null) {
        global $tododb;

        $theme = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$theme);
        if (empty($theme)) $theme = 'default';

        $theme_base = ABSPATH . 'content/themes/' . $theme;
        $real_base = realpath($theme_base);
        if (!$real_base || !is_dir($real_base)) {
            $theme_base = ABSPATH . 'content/themes/default';
            $real_base = realpath($theme_base);
        }

        $tagged_dict = [];

        // 1. 扫描主题目录
        if ($real_base && is_dir($real_base)) {
            $scan_dirs = [$real_base];
            if ($device_filter && in_array($device_filter, ['pc', 'mobile'], true)) {
                $dev_dir = $real_base . '/' . $device_filter;
                if (is_dir($dev_dir)) {
                    $scan_dirs = [$dev_dir];
                    if (is_dir($real_base . '/assets')) {
                        $scan_dirs[] = $real_base . '/assets';
                    }
                }
            }

            $files = [];
            foreach ($scan_dirs as $s_dir) {
                $real_s_dir = realpath($s_dir);
                if (!$real_s_dir || !is_dir($real_s_dir)) continue;

                $rii = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($real_s_dir, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($rii as $file) {
                    if ($file->isFile()) {
                        $ext = strtolower($file->getExtension());
                        if (in_array($ext, ['htm', 'html', 'js', 'php'], true)) {
                            // 排除自身语言包目录，防止死循环
                            $path = $file->getPathname();
                            if (strpos($path, '/lang/') !== false || strpos($path, '/languages/') !== false) {
                                continue;
                            }
                            $files[] = $path;
                        }
                    }
                }
            }

            // 补充核心后端接口与模板标签文件
            $core_files = [
                ABSPATH . 'core/classes/class-html-template-tags.php',
                ABSPATH . 'core/classes/class-user-center-template-tags.php',
                ABSPATH . 'core/classes/class-order-manager.php',
                ABSPATH . 'core/classes/class-cart-manager.php',
                ABSPATH . 'core/classes/class-firewall-manager.php',
                ABSPATH . 'core/functions/user-center-template.php',
                ABSPATH . 'core/functions/url-functions.php',
                ABSPATH . 'core/functions/security-extend.php',
                ABSPATH . 'core/functions/media.php',
                ABSPATH . 'api/captcha/slider.php',
                ABSPATH . 'api/user/register-handler.php',
                ABSPATH . 'api/user/forgot-password-handler.php',
                ABSPATH . 'api/user/login-handler.php',
                ABSPATH . 'api/user/mobile-login-handler.php',
                ABSPATH . 'api/user/send-email-code.php',
                ABSPATH . 'api/user/recharge-handler.php',
                ABSPATH . 'api/guestbook/submit.php',
                ABSPATH . 'api/sms/send.php',
                ABSPATH . 'admin/ajax/comment-handler.php',
                ABSPATH . 'admin/ajax/email-subscription-handler.php',
                ABSPATH . 'admin/ajax/cart-handler.php',
                ABSPATH . 'includes/ajax/payment-process.php',
                ABSPATH . 'includes/ajax/checkout-process.php',
                ABSPATH . 'includes/ajax/cart-handler.php',
                ABSPATH . 'includes/ajax/product-info.php',
                ABSPATH . 'includes/ajax/coupon-handler.php',
                ABSPATH . 'includes/ajax/address-handler.php',
                ABSPATH . 'includes/ajax/order-handler.php',
                ABSPATH . 'includes/ajax/cancel-order.php',
                ABSPATH . 'includes/ajax/confirm-receive.php',
                ABSPATH . 'includes/ajax/create-payment.php',
                ABSPATH . 'includes/ajax/payment-status.php',
                ABSPATH . 'includes/ajax/review-handler.php',
                ABSPATH . 'includes/ajax/favorites-handler.php',
                ABSPATH . 'includes/ajax/history-handler.php'
            ];

            foreach ($core_files as $cf) {
                if (file_exists($cf)) {
                    $files[] = $cf;
                }
            }

            $files = array_unique($files);
            foreach ($files as $fp) {
                $c = @file_get_contents($fp);
                if ($c === false) continue;
                $t_list = self::extract_all_language_tags($c);
                foreach ($t_list as $txt) {
                    $tagged_dict[$txt] = true;
                }
            }
        }

        // 2. 扫描数据库标签表 (tags.tag_title)
        $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : (defined('DB_PREFIX') ? DB_PREFIX : 'todo_');
        if ($tododb && method_exists($tododb, 'get_col')) {
            try {
                $db_tags = $tododb->get_col("SELECT DISTINCT tag_title FROM {$prefix}tags WHERE tag_title IS NOT NULL AND tag_title != ''");
                if (is_array($db_tags)) {
                    foreach ($db_tags as $t_title) {
                        $t_title = trim($t_title);
                        if (function_exists('todo_parse_inline_translations')) {
                            $t_title = todo_parse_inline_translations($t_title);
                        }
                        $t_title = trim(preg_replace('/\[[a-z]{2}(?:-[a-z]{2})?\|.*?\]/is', '', $t_title));
                        if ($t_title !== '') {
                            $tagged_dict[$t_title] = true;
                        }
                    }
                }
            } catch (Exception $e) {}

            // 3. 扫描文档属性表 (doc_properties)
            try {
                $db_props = $tododb->get_col("SELECT DISTINCT name FROM {$prefix}doc_properties WHERE name IS NOT NULL AND name != '' UNION SELECT DISTINCT display_name FROM {$prefix}doc_properties WHERE display_name IS NOT NULL AND display_name != ''");
                if (is_array($db_props)) {
                    foreach ($db_props as $p_name) {
                        $p_name = trim($p_name);
                        if (function_exists('todo_parse_inline_translations')) {
                            $p_name = todo_parse_inline_translations($p_name);
                        }
                        $p_name = trim(preg_replace('/\[[a-z]{2}(?:-[a-z]{2})?\|.*?\]/is', '', $p_name));
                        if ($p_name !== '') {
                            $tagged_dict[$p_name] = true;
                        }
                    }
                }
            } catch (Exception $e) {}

            // 4. 扫描会员等级表 (user_levels)
            try {
                $db_levels = $tododb->get_col("SELECT DISTINCT level_name FROM {$prefix}user_levels WHERE level_name IS NOT NULL AND level_name != ''");
                if (is_array($db_levels)) {
                    foreach ($db_levels as $l_name) {
                        $l_name = trim($l_name);
                        if ($l_name !== '') {
                            $tagged_dict[$l_name] = true;
                        }
                    }
                }
            } catch (Exception $e) {}
        }

        // 5. 补充全站高频通用词
        $common_terms = ['批发价', '高级批发价', '视频', '专题', '留言'];
        foreach ($common_terms as $ct) {
            $tagged_dict[$ct] = true;
        }

        return array_values(array_unique(array_keys($tagged_dict)));
    }

    /**
     * 获取指定语言包的文件存储物理路径
     *
     * @param string $lang 语言代码（如 en_US, ja_JP）
     * @param string $theme 主题名称
     * @return string
     */
    public static function get_language_file_path($lang = 'en_US', $theme = 'default') {
        $theme = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$theme) ?: 'default';
        $lang = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$lang) ?: 'en_US';

        $lang_dir = ABSPATH . "content/themes/{$theme}/pc/assets/lang";
        if (!is_dir($lang_dir)) {
            @mkdir($lang_dir, 0755, true);
        }

        return $lang_dir . "/{$lang}.php";
    }

    /**
     * 读取现有的语言包词条字典
     *
     * @param string $lang
     * @param string $theme
     * @return array
     */
    public static function load_existing_language_pack($lang = 'en_US', $theme = 'default') {
        $file = self::get_language_file_path($lang, $theme);
        if (is_file($file)) {
            try {
                $data = include $file;
                if (is_array($data)) {
                    return $data;
                }
            } catch (Throwable $e) {}
        }
        return [];
    }

    /**
     * 比对并提取出缺失翻译的词条列表
     *
     * @param string $lang
     * @param string $theme
     * @param int $limit 限制返回的未翻译条数（0 表示不限）
     * @return array
     */
    public static function get_missing_translations($lang = 'en_US', $theme = 'default', $limit = 100) {
        $all_tagged = self::scan_all_tagged_strings($theme);
        $existing_pack = self::load_existing_language_pack($lang, $theme);

        $missing = [];
        foreach ($all_tagged as $term) {
            $term_clean = trim($term);
            if ($term_clean === '') continue;

            // 如果语言包中不存在此键，或者翻译值为空字符串，则视为缺失
            if (!isset($existing_pack[$term_clean]) || trim((string)$existing_pack[$term_clean]) === '') {
                $missing[] = $term_clean;
            }
        }

        $total_missing = count($missing);
        $paged_missing = ($limit > 0 && $limit < $total_missing) ? array_slice($missing, 0, $limit) : $missing;

        return [
            'theme' => $theme,
            'lang' => $lang,
            'total_tagged_count' => count($all_tagged),
            'existing_translated_count' => count(array_filter($existing_pack, function($v) { return trim((string)$v) !== ''; })),
            'missing_count' => $total_missing,
            'returned_count' => count($paged_missing),
            'missing_terms' => $paged_missing
        ];
    }

    /**
     * 将新的翻译键值对增量合并并安全写回目标语言包文件
     *
     * @param string $lang
     * @param array $translations 增量词条键值对 ['原文' => '译文']
     * @param string $theme
     * @return array
     */
    public static function update_language_pack($lang, $translations, $theme = 'default') {
        if (!is_array($translations) || empty($translations)) {
            return [
                'success' => false,
                'message' => '未传入有效的翻译键值对数据'
            ];
        }

        $existing_pack = self::load_existing_language_pack($lang, $theme);
        $updated_items = 0;

        foreach ($translations as $original => $translated) {
            $k = trim((string)$original);
            $v = trim((string)$translated);
            if ($k !== '') {
                $existing_pack[$k] = $v;
                $updated_items++;
            }
        }

        // 格式化输出为美观安全的 PHP 数组代码
        $php_content = "<?php\n";
        $php_content .= "/**\n";
        $php_content .= " * TodoCMS Language pack (theme-level, flat file)\n";
        $php_content .= " * Target Language: {$lang} | Theme: {$theme}\n";
        $php_content .= " * Generated/Updated by TodoCMS AI Agent at " . date('Y-m-d H:i:s') . "\n";
        $php_content .= " */\n";
        $php_content .= "if (!defined('ABSPATH')) { exit('Direct access denied'); }\n\n";
        $php_content .= "return array(\n";

        foreach ($existing_pack as $key => $val) {
            $escaped_key = addcslashes($key, "'\\");
            $escaped_val = addcslashes($val, "'\\");
            $php_content .= "    '{$escaped_key}' => '{$escaped_val}',\n";
        }
        $php_content .= ");\n";

        $file_path = self::get_language_file_path($lang, $theme);

        // 原子写入文件
        $temp_file = $file_path . '.tmp.' . bin2hex(random_bytes(4));
        $write_res = @file_put_contents($temp_file, $php_content);
        if ($write_res === false) {
            return [
                'success' => false,
                'message' => "写入临时语言包文件失败，请检查目录权限: {$file_path}"
            ];
        }

        if (!@rename($temp_file, $file_path)) {
            @unlink($temp_file);
            return [
                'success' => false,
                'message' => "替换目标语言包文件失败: {$file_path}"
            ];
        }

        // 刷新语言包翻译缓存
        if (class_exists('CMS_Translation') && is_callable(['CMS_Translation', 'clear_cache'])) {
            CMS_Translation::clear_cache();
        }

        $rel_path = str_replace(ABSPATH, '', $file_path);

        return [
            'success' => true,
            'updated_count' => $updated_items,
            'total_pack_count' => count($existing_pack),
            'file' => $rel_path,
            'message' => "成功写入 {$updated_items} 条翻译至 {$rel_path}，语言包总词条数现为 " . count($existing_pack)
        ];
    }
}
