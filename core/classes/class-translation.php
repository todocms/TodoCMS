<?php
/**
 * 翻译类
 *
 * 处理多语言翻译和本地化功能
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class CMS_Translation
{
    /**
     * 已加载的文本域
     *
     * @var array
     */
    private static $domains = array();

    /**
     * 翻译缓存
     *
     * @var array
     */
    private static $cache = array();

    /**
     * 回退语言
     * @var string
     */
    private static $fallback_locale = 'zh-CN';

    /**
     * 当前语言（连字符格式）
     * @var string|null
     */
    private static $current_locale = null;
    /**
     * 当通过 init_for_locale() 指定语言初始化时，锁定当前语言，避免 translate() 按请求上下文覆盖
     */
    private static $locale_locked = false;

    /**
     * 初始化翻译系统
     */
    public static function init()
    {
        // 解除语言锁：以请求级 LocaleContext 为准
        self::$locale_locked = false;
        // 获取当前语言（连字符）与回退语言
        if (function_exists('current_locale')) {
            self::$current_locale = current_locale();
        } else {
            self::$current_locale = str_replace('_', '-', get_current_language());
        }
        // 从注册中心读取回退语言
        if (class_exists('LanguageRegistry')) {
            self::$fallback_locale = LanguageRegistry::get_instance()->get_fallback_locale();
        }
        $locale = self::$current_locale ?: 'zh-CN';

        // 当前主题目录
        $activeTheme = (function_exists('get_theme_name') ? get_theme_name() : 'default');
        $themesDir = defined('THEMES_DIR') ? THEMES_DIR : 'content/themes';
        if (strpos($themesDir, '/') === 0 || strpos($themesDir, ABSPATH) === 0) {
            $themesBase = rtrim($themesDir, '/');
        } else {
            $themesBase = rtrim(ABSPATH, '/') . '/' . trim($themesDir, '/');
        }
        $themeDirAbs = $themesBase . '/' . $activeTheme;

        // 获取当前设备类型（pc / mobile）
        $device = 'pc';
        if (class_exists('CMS_Theme')) {
            $theme_obj = CMS_Theme::get_active_theme();
            if ($theme_obj && method_exists($theme_obj, 'get_device_style')) {
                $device = $theme_obj->get_device_style();
            }
        } elseif (function_exists('todo_is_mobile') && todo_is_mobile()) {
            $device = 'mobile';
        }

        $primaryDevDir = ($device === 'mobile') ? 'mobile' : 'pc';
        $secondaryDevDir = ($device === 'mobile') ? 'pc' : 'mobile';

        // 每次初始化时，先清空默认文本域与缓存，避免跨请求残留（尤其在内建服务器或长驻进程场景）
        if (isset(self::$domains['default'])) {
            self::unload_textdomain('default');
        }
        self::clear_cache();

        // 1) 主题 languages 目录下的 MO 文件（次要设备在先，主要设备在后）
        self::load_textdomain('default', $themeDirAbs . '/' . $secondaryDevDir . '/languages', $locale);
        self::load_textdomain('default', $themeDirAbs . '/' . $primaryDevDir . '/languages', $locale);

        // 2) 主题 assets/lang 下的 PHP 扁平包（以当前设备为主）
        if (!isset(self::$domains['default'])) {
            self::load_php_textdomain('default', $locale, $device);
        }

        // 3) 回退至 fallback_locale 按同序加载
        if (!isset(self::$domains['default']) && self::$fallback_locale && self::$fallback_locale !== $locale) {
            $fb = self::$fallback_locale;
            self::load_textdomain('default', $themeDirAbs . '/' . $secondaryDevDir . '/languages', $fb);
            self::load_textdomain('default', $themeDirAbs . '/' . $primaryDevDir . '/languages', $fb);
            if (!isset(self::$domains['default'])) {
                self::load_php_textdomain('default', $fb, $device);
            }
        }

        // 执行初始化钩子
        do_action('cms_translation_init');
    }

    /**
     * 按指定语言初始化翻译系统（不依赖请求上下文）
     */
    public static function init_for_locale($locale, $device = null)
    {
        // 锁定指定语言，防止 translate() 按 current_locale() 触发重载导致跨语言污染
        self::$locale_locked = true;
        // 规范化目标语言（连字符并区域大写）
        if (function_exists('canonical_locale')) {
            $locale = canonical_locale($locale);
        } else {
            $locale = str_replace('_', '-', (string) $locale);
        }
        self::$current_locale = $locale ?: 'zh-CN';
        // 从注册中心读取回退语言
        if (class_exists('LanguageRegistry')) {
            self::$fallback_locale = LanguageRegistry::get_instance()->get_fallback_locale();
        }

        if ($device === null) {
            $device = 'pc';
            if (class_exists('CMS_Theme')) {
                $theme_obj = CMS_Theme::get_active_theme();
                if ($theme_obj && method_exists($theme_obj, 'get_device_style')) {
                    $device = $theme_obj->get_device_style();
                }
            } elseif (function_exists('todo_is_mobile') && todo_is_mobile()) {
                $device = 'mobile';
            }
        }

        $primaryDevDir = ($device === 'mobile') ? 'mobile' : 'pc';
        $secondaryDevDir = ($device === 'mobile') ? 'pc' : 'mobile';

        // 当前主题目录
        $activeTheme = (function_exists('get_theme_name') ? get_theme_name() : 'default');
        $themesDir = defined('THEMES_DIR') ? THEMES_DIR : 'content/themes';
        if (strpos($themesDir, '/') === 0 || strpos($themesDir, ABSPATH) === 0) {
            $themesBase = rtrim($themesDir, '/');
        } else {
            $themesBase = rtrim(ABSPATH, '/') . '/' . trim($themesDir, '/');
        }
        $themeDirAbs = $themesBase . '/' . $activeTheme;

        // 清空默认文本域与缓存，避免跨语言残留
        if (isset(self::$domains['default'])) {
            self::unload_textdomain('default');
        }
        self::clear_cache();

        // 1) 主题 languages 目录下的 MO 文件
        self::load_textdomain('default', $themeDirAbs . '/' . $secondaryDevDir . '/languages', $locale);
        self::load_textdomain('default', $themeDirAbs . '/' . $primaryDevDir . '/languages', $locale);

        // 2) 主题 assets/lang 下的 PHP 扁平包
        if (!isset(self::$domains['default'])) {
            self::load_php_textdomain('default', $locale, $device);
        }

        // 3) 回退至 fallback_locale 按同序加载
        if (!isset(self::$domains['default']) && self::$fallback_locale && self::$fallback_locale !== $locale) {
            $fb = self::$fallback_locale;
            self::load_textdomain('default', $themeDirAbs . '/' . $secondaryDevDir . '/languages', $fb);
            self::load_textdomain('default', $themeDirAbs . '/' . $primaryDevDir . '/languages', $fb);
            if (!isset(self::$domains['default'])) {
                self::load_php_textdomain('default', $fb, $device);
            }
        }

        // 初始化钩子（保持与 init 一致）
        do_action('cms_translation_init');
    }

    /**
     * 加载文本域
     *
     * @param string $domain 文本域
     * @param string $path MO文件路径
     * @return bool 是否成功加载
     */
    public static function load_textdomain($domain, $path, $locale = null)
    {
        // 目录模式：根据 locale 搜索候选 MO 文件
        if ($locale !== null || (is_string($path) && is_dir($path))) {
            $locHyphen = str_replace('_', '-', (string) $locale);
            $locUnder = str_replace('-', '_', (string) $locale);
            $base = rtrim($path, '/');
            $candidates = array(
                $base . '/' . $domain . '-' . $locHyphen . '.mo',
                $base . '/' . $domain . '-' . $locUnder . '.mo',
                $base . '/' . $locUnder . '.mo',
                $base . '/' . $locHyphen . '.mo',
            );
            foreach ($candidates as $moFile) {
                if (file_exists($moFile) && is_file($moFile)) {
                    $path = $moFile;
                    break;
                }
            }
        }
        // 必须是具体的文件路径；若仅为目录且未找到候选 .mo 文件，则不加载，返回 false 以便后续 PHP 扁平包回退
        if (!is_string($path) || !file_exists($path) || !is_file($path)) {
            return false;
        }

        // 加载并合并MO文件（若文本域已存在则合并）
        $mo = self::parse_mo_file($path);
        if (!$mo) {
            return false;
        }

        // 标注当前加载的语言与主题，便于后续一致性检查
        $activeTheme = (function_exists('get_theme_name') ? get_theme_name() : 'default');
        $locHyphen = str_replace('_', '-', (string) $locale);

        if (isset(self::$domains[$domain])) {
            // 合并 translations
            if (isset($mo['translations']) && is_array($mo['translations'])) {
                $existing = self::$domains[$domain]['translations'] ?? array();
                self::$domains[$domain]['translations'] = array_merge($existing, $mo['translations']);
            }
            // 记录来源与上下文
            if (!isset(self::$domains[$domain]['headers'])) {
                self::$domains[$domain]['headers'] = array();
            }
            self::$domains[$domain]['headers']['mo_source'] = $path;
            self::$domains[$domain]['headers']['locale'] = $locHyphen;
            self::$domains[$domain]['headers']['theme'] = $activeTheme;
            return true;
        }

        self::$domains[$domain] = $mo;
        if (!isset(self::$domains[$domain]['headers'])) {
            self::$domains[$domain]['headers'] = array();
        }
        self::$domains[$domain]['headers']['mo_source'] = $path;
        self::$domains[$domain]['headers']['locale'] = $locHyphen;
        self::$domains[$domain]['headers']['theme'] = $activeTheme;
        return true;
    }

    /**
     * 加载基于PHP数组的文本域文件
     * 支持主题根路径的扁平语言包：content/themes/<active_theme>/assets/lang/<locale>.php
     */
    private static function load_php_textdomain($domain, $locale, $device = null)
    {
        if ($device === null) {
            $device = 'pc';
            if (class_exists('CMS_Theme')) {
                $theme_obj = CMS_Theme::get_active_theme();
                if ($theme_obj && method_exists($theme_obj, 'get_device_style')) {
                    $device = $theme_obj->get_device_style();
                }
            } elseif (function_exists('todo_is_mobile') && todo_is_mobile()) {
                $device = 'mobile';
            }
        }

        // 将连字符格式标准化为下划线，适配 zh_CN、en_US、ja_JP 等文件命名
        $normLocale = str_replace('-', '_', $locale);
        $candidates = array();

        // 1) 核心语言包目录（最低优先级）
        $candidates[] = rtrim(ABSPATH, '/') . '/assets/languages/' . $normLocale . '.php';

        // 2) 主题语言包：按设备优先级确定（次要设备在先，主要设备在后，后合并者覆盖前者）
        $activeTheme = (function_exists('get_theme_name') ? get_theme_name() : 'default');
        if (defined('THEMES_DIR')) {
            $themesDir = THEMES_DIR;
            if (strpos($themesDir, '/') === 0 || strpos($themesDir, ABSPATH) === 0) {
                $themesBase = rtrim($themesDir, '/');
            } else {
                $themesBase = rtrim(ABSPATH, '/') . '/' . trim($themesDir, '/');
            }
        } else {
            $themesBase = rtrim(ABSPATH, '/') . '/content/themes';
        }
        $themeDir = $themesBase . '/' . $activeTheme;

        $primaryDevDir = ($device === 'mobile') ? 'mobile' : 'pc';
        $secondaryDevDir = ($device === 'mobile') ? 'pc' : 'mobile';

        // 次要设备主题语言包（回退）
        $candidates[] = $themeDir . '/' . $secondaryDevDir . '/assets/lang/' . $normLocale . '.php';
        // 主要设备主题语言包（最高优先级，放在数组最后，array_merge 会覆盖前面的冲突键）
        $candidates[] = $themeDir . '/' . $primaryDevDir . '/assets/lang/' . $normLocale . '.php';

        // 依次加载所有存在的文件（次要设备/核心在先，主要设备在后），逐个合并
        $loaded = false;
        foreach ($candidates as $file) {
            if (!file_exists($file)) {
                continue;
            }

            try {
                $map = include $file;
            } catch (\Throwable $e) {
                if (class_exists('CMS_Logger')) {
                    CMS_Logger::warning("主题语言包加载失败 [{$file}]: " . $e->getMessage());
                } else {
                    error_log("[CMS_Translation] Failed to include {$file}: " . $e->getMessage());
                }
                continue;
            }
            if (!is_array($map)) {
                continue;
            }

            if (!isset(self::$domains[$domain])) {
                self::$domains[$domain] = [
                    'translations' => array(),
                    'headers' => []
                ];
            }
            self::$domains[$domain]['translations'] = array_merge(self::$domains[$domain]['translations'], $map);

            if (!isset(self::$domains[$domain]['headers']['sources'])) {
                self::$domains[$domain]['headers']['sources'] = array();
            }
            self::$domains[$domain]['headers']['sources'][] = $file;
            // 标注当前加载的语言、主题与设备，便于后续一致性检查
            self::$domains[$domain]['headers']['locale'] = str_replace('_', '-', $locale);
            self::$domains[$domain]['headers']['theme'] = (function_exists('get_theme_name') ? get_theme_name() : 'default');
            self::$domains[$domain]['headers']['device'] = $device;

            $loaded = true;
        }
        return $loaded;
    }

    /**
     * 解析MO文件
     *
     * @param string $path MO文件路径
     * @return array|false 解析结果或false
     */
    private static function parse_mo_file($path)
    {
        // 简化版MO文件解析
        // 实际实现应该更复杂，这里只是示例
        $mo = array(
            'translations' => array(),
            'headers' => array()
        );

        // 在实际实现中，这里应该解析二进制MO文件
        // 为了简化，我们假设已经解析完成

        return $mo;
    }

    /**
     * 翻译文本
     *
     * @param string $text 要翻译的文本
     * @param string $domain 文本域
     * @return string 翻译后的文本
     */
    public static function translate($text, $domain = 'default')
    {
        // 惰性初始化与一致性校验：确保每次请求都在正确语言、主题与设备模式下加载翻译
        $activeTheme = (function_exists('get_theme_name') ? get_theme_name() : 'default');
        $currentLocale = function_exists('current_locale') ? current_locale() : (self::$current_locale ?: null);
        if ($currentLocale) {
            $currentLocale = str_replace('_', '-', $currentLocale);
        }

        $device = 'pc';
        if (class_exists('CMS_Theme')) {
            $theme_obj = CMS_Theme::get_active_theme();
            if ($theme_obj && method_exists($theme_obj, 'get_device_style')) {
                $device = $theme_obj->get_device_style();
            }
        } elseif (function_exists('todo_is_mobile') && todo_is_mobile()) {
            $device = 'mobile';
        }

        // 如果尚未初始化、或默认文本域未加载，或已加载的语言/主题/设备与当前不一致，则重新初始化
        $needInit = false;
        if (self::$current_locale === null || !isset(self::$domains['default'])) {
            $needInit = true;
        } else {
            $hdr = self::$domains['default']['headers'] ?? [];
            $loadedLocale = isset($hdr['locale']) ? $hdr['locale'] : null;
            $loadedTheme = isset($hdr['theme']) ? $hdr['theme'] : null;
            $loadedDevice = isset($hdr['device']) ? $hdr['device'] : null;
            if (self::$locale_locked) {
                // 当已锁定语言时，以锁定语言、当前主题与设备模式进行一致性校验
                if (($loadedLocale !== (self::$current_locale ?: $loadedLocale)) || ($loadedTheme !== $activeTheme) || ($loadedDevice !== $device)) {
                    $needInit = true;
                }
            } else {
                // 正常模式：以请求上下文语言、主题与设备模式进行一致性校验
                if (($currentLocale && $loadedLocale !== $currentLocale) || ($loadedTheme !== $activeTheme) || ($loadedDevice !== $device)) {
                    $needInit = true;
                }
            }
        }
        if ($needInit) {
            if (self::$locale_locked) {
                self::init_for_locale(self::$current_locale ?: ($currentLocale ?: 'zh-CN'), $device);
            } else {
                self::init();
            }
        }

        // 统一走主题与设备维度缓存，避免不同设备与主题翻译冲突
        $cache_key = md5($text . '|' . (self::$current_locale ?: '') . '|' . $activeTheme . '|' . $device);
        if (isset(self::$cache[$cache_key])) {
            return self::$cache[$cache_key];
        }

        // 始终使用默认文本域（新方案不区分 domain）
        $domainUsed = 'default';
        if (!isset(self::$domains[$domainUsed])) {
            return $text; // 如果文本域未加载，返回原文
        }

        // 查找翻译
        $translations = self::$domains[$domainUsed]['translations'];
        if (isset($translations[$text])) {
            $translated = $translations[$text];
        } else {
            $translated = $text; // 如果没有找到翻译，返回原文
            // 记录缺失的翻译键，便于后续补齐
            try {
                $loc = self::$current_locale ?: 'unknown';
                $fb = self::$fallback_locale ?: 'zh-CN';
                // 仅在当前语言非回退语言时记录缺失，避免默认语言重复噪音
                // 增加 TODO_DEBUG 判断，只有在开启调试模式时才记录日志
                if ($loc !== $fb && (defined('TODO_DEBUG') && TODO_DEBUG)) {
                    $activeTheme = (function_exists('get_theme_name') ? get_theme_name() : 'default');
                    $line = date('Y-m-d H:i:s') . "\t" . $loc . "\t" . $activeTheme . "\t" . str_replace(["\r", "\n"], ' ', (string) $text) . "\n";
                    $logFile = rtrim(ABSPATH, '/') . '/logs/translation_missing.log';
                    // 创建日志文件（若不存在）并追加写入
                    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
                }
            } catch (\Throwable $e) {
                // 安全吞掉日志写入异常，不影响正常渲染
            }
        }

        // 缓存结果
        self::$cache[$cache_key] = $translated;

        return $translated;
    }

    /**
     * 根据数量翻译单复数形式
     *
     * @param string $single 单数形式
     * @param string $plural 复数形式
     * @param int $number 数量
     * @param string $domain 文本域
     * @return string 翻译后的文本
     */
    public static function translate_plural($single, $plural, $number, $domain = 'default')
    {
        // 简化版单复数翻译
        // 实际实现应该考虑不同语言的复数规则
        $text = $number == 1 ? $single : $plural;
        return self::translate($text, $domain);
    }

    /**
     * 根据上下文翻译文本
     */
    public static function translate_with_context($text, $context, $domain = 'default')
    {
        // 带上下文的翻译
        $context_text = $context . '\4' . $text; // 使用特殊分隔符
        $translation = self::translate($context_text, $domain);

        // 如果找不到带上下文的翻译，尝试不带上下文的翻译
        if ($translation === $context_text) {
            return self::translate($text, $domain);
        }

        return $translation;
    }

    /**
     * 根据上下文翻译单复数形式
     */
    public static function translate_plural_with_context($text, $plural, $number, $context, $domain = 'default')
    {
        $text = $number == 1 ? $text : $plural;
        return self::translate_with_context($text, $context, $domain);
    }

    /**
     * 获取已加载的文本域
     *
     * @return array 已加载的文本域列表
     */
    public static function get_loaded_domains()
    {
        return array_keys(self::$domains);
    }

    /**
     * 清除翻译缓存
     */
    public static function clear_cache()
    {
        self::$cache = array();
    }

    /**
     * 获取指定文本域的全量字典映射数组
     *
     * @param string $domain 文本域
     * @return array 翻译字典
     */
    public static function get_all_translations($domain = 'default')
    {
        if (!isset(self::$domains[$domain])) {
            self::init();
        }
        return isset(self::$domains[$domain]['translations']) && is_array(self::$domains[$domain]['translations'])
            ? self::$domains[$domain]['translations']
            : array();
    }

    /**
     * 卸载文本域
     *
     * @param string $domain 文本域
     * @return bool 是否成功卸载
     */
    public static function unload_textdomain($domain)
    {
        if (isset(self::$domains[$domain])) {
            unset(self::$domains[$domain]);
            return true;
        }
        return false;
    }
}
?>