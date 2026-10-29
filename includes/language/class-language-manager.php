<?php
/**
 * TodoCMS 多语言管理器核心类
 * 
 * @package TodoCMS
 * @subpackage Language
 * @version 1.0.0
 */

// 防止直接访问（但允许AJAX请求）
if (!defined('ABSPATH') && !defined('DOING_AJAX')) {
    exit('Direct access denied');
}

/**
 * 多语言管理器类
 */
class TodoLanguageManager
{

    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * 当前语言代码
     */
    private $current_language = 'zh-CN';

    /**
     * 默认语言代码
     */
    private $default_language = 'zh-CN';

    /**
     * 支持的语言列表
     */
    private $supported_languages = [];

    /**
     * 翻译缓存
     */
    private $translation_cache = [];

    /**
     * 词汇条目缓存（避免重复查询 get_or_create_term）- 静态缓存，请求级别共享
     */
    private static $term_cache = [];

    /**
     * 按原文与主题快速检索词汇条目的索引（消除因为文件 context 行号变动导致的 3800+ 次逐条 SQL 查询）
     */
    private static $terms_by_text = [];
    private static $terms_by_text_theme = [];

    /**
     * 缺失翻译记录缓存（避免重复查询和插入）- 静态缓存，请求级别共享
     */
    private static $missing_translation_cache = [];

    /**
     * 词汇键值存在性缓存（避免重复查询 term_key_exists）
     */
    private static $term_key_exists_cache = [];

    /**
     * 自动检测到的词汇
     */
    private $auto_detected_terms = [];

    /**
     * 待批量查询的翻译请求（延迟批量加载）
     * 格式: [cache_key => ['term_id' => id, 'language' => lang, 'text' => text, 'context' => ctx, 'theme' => theme]]
     */
    private $pending_translations = [];

    /**
     * 是否已执行批量查询
     */
    private $bulk_queries_executed = false;

    /**
     * 待批量查询的词汇条目（延迟批量加载）
     * 格式: [cache_key => ['text' => text, 'context' => ctx, 'theme' => theme]]
     */
    private $pending_terms = [];

    /**
     * 是否已执行批量词汇查询
     */
    private $bulk_terms_queries_executed = false;

    /**
     * 待批量记录的缺失翻译（延迟批量插入）
     * 格式: [cache_key => ['term_id' => id, 'language' => lang]]
     */
    private $pending_missing_translations = [];

    /**
     * 数据库连接
     */
    private $db;

    /**
     * 构造函数
     */
    private function __construct()
    {
        global $tododb;
        $this->db = $tododb;
        $this->init();
    }

    /**
     * 获取单例实例
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 初始化
     */
    private function init()
    {
        // 加载支持的语言
        $this->load_supported_languages();

        // 设置当前语言
        $this->set_current_language();

        // 注册钩子
        $this->register_hooks();
    }

    /**
     * 加载支持的语言（合并数据库 Active 与注册中心 Enabled）
     */
    private function load_supported_languages()
    {
        $this->supported_languages = [];
        $dbLangs = [];
        $useDb = false;

        // 读取数据库语言（允许 inactive 存在，但后续按注册中心启用集做合并）
        try {
            if ($this->db && method_exists($this->db, 'table_exists') && $this->db->table_exists('languages')) {
                $useDb = true;
                $dbLangs = $this->db->get_results(
                    "SELECT * FROM {$this->db->prefix}languages ORDER BY sort_order ASC"
                );
            }
        } catch (\Throwable $e) {
            $useDb = false;
        }

        // 引入语言注册中心（作为全局启用白名单与默认语言来源）
        if (!class_exists('LanguageRegistry')) {
            $registryPath = ABSPATH . 'core/classes/class-language-registry.php';
            if (file_exists($registryPath)) {
                require_once $registryPath;
            }
        }
        $registry = class_exists('LanguageRegistry') ? LanguageRegistry::get_instance() : null;
        $defaultFromRegistry = null;

        // 1) 先以注册中心“启用语言”为基准集合，确保 /ja/ /ko/ 等按配置可用
        if ($registry) {
            $configLangs = $registry->get_languages(true); // 仅启用的语言
            foreach ($configLangs as $locale => $info) {
                $localeNorm = function_exists('canonical_locale') ? canonical_locale($locale) : str_replace('_', '-', $locale);
                $this->supported_languages[$localeNorm] = [
                    'id' => null,
                    'code' => $localeNorm,
                    'name' => isset($info['title']) ? $info['title'] : $localeNorm,
                    'native_name' => isset($info['native_name']) ? $info['native_name'] : (isset($info['title']) ? $info['title'] : $localeNorm),
                    'flag_icon' => null,
                    'is_default' => !empty($info['is_default']),
                    'direction' => isset($info['direction']) ? $info['direction'] : 'ltr',
                    'date_format' => isset($info['date_format']) ? $info['date_format'] : 'Y-m-d',
                    'time_format' => isset($info['time_format']) ? $info['time_format'] : 'H:i:s'
                ];
                if (!empty($info['is_default'])) {
                    $defaultFromRegistry = $localeNorm;
                }
            }
        }

        // 2) 再合并数据库中 Active 的语言（站点可额外开启不在注册中心白名单的语言）
        if ($useDb && !empty($dbLangs)) {
            foreach ($dbLangs as $lang) {
                // 统一代码格式
                $codeNorm = function_exists('canonical_locale') ? canonical_locale($lang->code) : str_replace('_', '-', $lang->code);
                if (isset($lang->status) && $lang->status === 'active') {
                    $this->supported_languages[$codeNorm] = [
                        'id' => $lang->id,
                        'code' => $codeNorm,
                        'name' => $lang->name,
                        'native_name' => isset($lang->native_name) ? $lang->native_name : $lang->name,
                        'flag_icon' => isset($lang->flag) ? $lang->flag : null,
                        'is_default' => isset($lang->is_default) ? ((int) $lang->is_default === 1) : (isset($this->supported_languages[$codeNorm]['is_default']) ? $this->supported_languages[$codeNorm]['is_default'] : false),
                        'direction' => isset($lang->direction) ? $lang->direction : ($this->supported_languages[$codeNorm]['direction'] ?? 'ltr'),
                        'date_format' => isset($lang->date_format) ? $lang->date_format : ($this->supported_languages[$codeNorm]['date_format'] ?? 'Y-m-d'),
                        'time_format' => isset($lang->time_format) ? $lang->time_format : ($this->supported_languages[$codeNorm]['time_format'] ?? 'H:i:s')
                    ];
                    if (isset($lang->is_default) && (int) $lang->is_default === 1) {
                        $this->default_language = $codeNorm;
                    }
                }
            }
        }

        // 3) 解析默认语言优先级：注册中心标记 > 数据库标记 > 注册中心默认/后备 > zh-CN
        if (empty($this->default_language)) {
            if ($defaultFromRegistry) {
                $this->default_language = $defaultFromRegistry;
            } elseif ($registry) {
                $this->default_language = $registry->get_default_locale() ?: $registry->get_fallback_locale();
            } else {
                $this->default_language = 'zh-CN';
            }
        }

        // 4) 兜底：若合并后仍为空，至少提供一个后备语言
        if (empty($this->supported_languages)) {
            $fallback = $registry ? ($registry->get_fallback_locale() ?: 'en-US') : 'en-US';
            $fallbackNorm = function_exists('canonical_locale') ? canonical_locale($fallback) : str_replace('_', '-', $fallback);
            $this->supported_languages[$fallbackNorm] = [
                'id' => null,
                'code' => $fallbackNorm,
                'name' => $fallbackNorm,
                'native_name' => $fallbackNorm,
                'flag_icon' => null,
                'is_default' => true,
                'direction' => 'ltr',
                'date_format' => 'Y-m-d',
                'time_format' => 'H:i:s'
            ];
            $this->default_language = $fallbackNorm;
        }
    }

    /**
     * 设置当前语言
     */
    private function set_current_language()
    {
        // 优先使用请求级 LocaleContext，使路径段/域名路由在当前请求立即生效
        if (class_exists('LocaleContext')) {
            try {
                $lc = LocaleContext::get_instance()->get_locale();
                if ($lc && $this->is_supported_language($lc)) {
                    $this->current_language = $lc;
                   $source = method_exists(LocaleContext::get_instance(), 'get_source') ? LocaleContext::get_instance()->get_source() : '';
                    if ($source === 'cookie' || $source === 'default') {
                        $this->set_language_cookie($lc);
                    }
                    return;
                }
            } catch (\Throwable $e) {
                // 忽略 LocaleContext 异常，继续后续判定
            }
        }

        // 其次：Cookie(todo_locale) > 浏览器语言 > 默认语言
        if (isset($_COOKIE['todo_locale']) && $this->is_supported_language($_COOKIE['todo_locale'])) {
            $this->current_language = $_COOKIE['todo_locale'];
            return;
        }

        $browser_lang = $this->detect_browser_language();
        if ($browser_lang && $this->is_supported_language($browser_lang)) {
            $this->current_language = $browser_lang;
            $this->set_language_cookie($browser_lang);
            return;
        }

        $this->current_language = $this->default_language;
    }

    /**
     * 检测浏览器语言
     */
    private function detect_browser_language()
    {
        $accept_language = todo_server_string('HTTP_ACCEPT_LANGUAGE');
        if ($accept_language === '') {
            return null;
        }

        $languages = explode(',', $accept_language);
        foreach ($languages as $lang) {
            $lang_parts = explode(';', (string) $lang);
            $lang = trim((string) ($lang_parts[0] ?? ''));
            if ($lang === '') {
                continue;
            }

            // 尝试完整匹配
            if ($this->is_supported_language($lang)) {
                return $lang;
            }

            // 尝试语言代码匹配（如 zh 匹配 zh-CN）
            $lang_code = explode('-', $lang)[0];
            foreach ($this->supported_languages as $code => $info) {
                if (strpos($code, $lang_code . '-') === 0) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * 设置语言Cookie
     */
    private function set_language_cookie($language)
    {
        if (headers_sent()) return;

        $path = '';
        if (isset($_SERVER['REQUEST_URI'])) {
            $path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $path = is_string($path) ? $path : '';
        }

        $is_xhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        $is_ajax = (defined('DOING_AJAX') && DOING_AJAX) || $is_xhr;
        $is_api = ($path !== '' && (strpos($path, '/api/') !== false || strpos($path, '/ajax/') !== false));
        if ($is_ajax || $is_api) {
            return;
        }

        if ($path !== '') {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($ext && in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'css', 'js', 'woff', 'woff2', 'ttf', 'eot', 'map', 'txt', 'xml', 'json'])) {
                return;
            }
        }

        if (!empty($_SERVER['HTTP_ACCEPT'])) {
            $accept = strtolower($_SERVER['HTTP_ACCEPT']);
            if (strpos($accept, 'application/json') !== false && strpos($accept, 'text/html') === false) {
                return;
            }
        }

        setcookie('todo_locale', $language, time() + (86400 * 30), '/');
    }

    /**
     * 检测是否为后台管理上下文
     */
    private function is_admin_context()
    {
        try {
            // 优先使用显式的后台常量
            if ((defined('TODO_ADMIN') && TODO_ADMIN) || (defined('ADMIN_ACCESS') && ADMIN_ACCESS)) {
                return true;
            }
            // 其次：请求路径中包含 /admin/ 或 /admin-ajax/
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            if ($uri && (strpos($uri, '/admin/') !== false || strpos($uri, '/admin-ajax') !== false)) {
                return true;
            }
        } catch (\Throwable $e) {
            // 忽略判定异常，默认非后台
        }
        return false;
    }

    /**
     * 检查是否为支持的语言
     */
    public function is_supported_language($language)
    {
        return isset($this->supported_languages[$language]);
    }

    /**
     * 获取当前语言
     */
    public function get_current_language()
    {
        return $this->current_language;
    }

    /**
     * 获取默认语言
     */
    public function get_default_language()
    {
        return $this->default_language;
    }

    /**
     * 获取支持的语言列表
     */
    public function get_supported_languages()
    {
        return $this->supported_languages;
    }

    /**
     * 切换语言
     */
    public function switch_language($language)
    {
        if ($this->is_supported_language($language)) {
            $this->current_language = $language;
            $this->set_language_cookie($language);
            return true;
        }
        return false;
    }
    /**
     * 翻译文本
     * 
     * @param string $text 原始文本
     * @param string $context 上下文
     * @param string $language 目标语言（可选）
     * @return string 翻译后的文本
     */
    public function translate($text, $context = null, $language = null)
    {
        if (empty($text) || !is_string($text)) {
            return $text;
        }

        // 过滤非可翻译词汇
        $trimmed_text = trim($text);
        if (
            strpos($text, '{{') !== false ||
            (mb_strlen($trimmed_text) <= 1 && !preg_match('/\p{L}/u', $trimmed_text)) || // 单字/符号标点（如 +, -, =，保留全球所有语言单字如中文 "和"、阿拉伯语 "و" 等）
            preg_match('/^[+\-*\/=><&|!#%^~@$:;,._\s]+$/u', $trimmed_text) ||
            preg_match('/^(?:https?:\/\/|\/|\.\/|content\/|assets\/)[^\s]+$/i', $text) ||
            preg_match('/\.(?:png|jpg|jpeg|gif|webp|svg|ico|css|js|woff2?|ttf|eot)(?:\?.*)?$/i', $text) ||
            preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $text) ||
            preg_match('/^(?:\$|¥|￥|€|£)?\s*-?\d+(?:,\d{3})*(?:\.\d+)?\s*(?:[A-Z]{3})?$/u', $trimmed_text) || // 数字、金额（如 118.56）
            preg_match('/^\d+\s*(?:pcs|pc|piece|pieces|kg|g|mg|l|ml|m|cm|mm|px|em|rem|%|usd|eur|cny|rmb|gbp|cad|aud|set|sets|box|boxes|item|items)(?:\/[a-z]+)?$/i', $trimmed_text) || // 数量+单位（如 1pcs）
            preg_match('/^(?:pcs|piece|pieces|set|sets|box|boxes|item|items)$/i', $trimmed_text) || // 纯单位英文/Key（如 pcs, piece, items）
            preg_match('/^\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}(?:\s+\d{1,2}:\d{1,2}(?::\d{1,2})?)?$/', $trimmed_text) || // 日期时间
            preg_match('/^\d{1,2}:\d{1,2}(?::\d{1,2})?$/', $trimmed_text) || // 时间（如 14:06:00）
            preg_match('/^(?:\d{1,3}\.){3}\d{1,3}$/', $trimmed_text) || // IP 地址
            preg_match('/^[a-f0-9]{32}$|^[a-f0-9]{40}$/i', $trimmed_text) || // MD5 / SHA1
            preg_match('/^[A-Z0-9]{10,}$/i', $trimmed_text) || // 订单/交易流水号
            preg_match('/(?:订单支付|交易流水|单号|订单号|流水号|Ref|SN|ID|Transaction)\s*[:：#]?\s*[A-Z0-9]{8,}/iu', $trimmed_text) || // 带流水号摘要
            preg_match('/^(?:[a-z]{2}(?:-[a-z0-9_-]+)+)$/i', $trimmed_text) || // 语言前缀/Slug（如 en-youtube, zh-cn-demo）
            preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}\s-]+[:：]\s*.+$/u', $trimmed_text) || // 规格组合拼接 Key: Value
            // ---- 全球收货地址识别（结构特征，不依赖任何特定语言）----
            // 信号1：街道门牌号 + 国际通用街道类型词（英/法/德/西/葡/荷/意等）
            preg_match('/\b\d+(?:[\-\/]\d+)?[A-Za-z]?\s+\w[\w\s\']{1,40}\s+(?:Street|St|Avenue|Ave|Road|Rd|Boulevard|Blvd|Drive|Dr|Lane|Ln|Court|Ct|Place|Pl|Way|Terrace|Ter|Circle|Cir|Highway|Hwy|Freeway|Fwy|Parkway|Pkwy|Rue|Voie|Chemin|Allée|Strasse|Stra[ß]e|Weg|Platz|Calle|Carrera|Avenida|Rua|Estrada|Travessa|Straat|Laan|Via|Corso|Viale|Piazza|Strada|Ulitsa|Prospekt|Улица|Проспект|路|街|道|巷|弄|大道|大街)(?:[.,\s]|$)/iu', $trimmed_text) ||
            // 信号2：包含各国邮政编码格式（US 5位/UK A1A/CA A1A1A1/DE 5位/JP 3-4位等）
            preg_match('/\b(?:[A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2}|\d{5}(?:-\d{4})?|[A-Z]\d[A-Z]\s*\d[A-Z]\d|\d{4,6}(?:\s*-\s*\d{3,4})?|\d{3}-\d{4})\b/i', $trimmed_text) && strlen($trimmed_text) > 10 && strpos($trimmed_text, ',') !== false ||
            // 信号3：以 ISO-3166 两字母国家代码结尾（如 ", US" ", CN" ", DE"）
            preg_match('/,\s*(?:US|GB|CN|DE|FR|JP|CA|AU|IN|BR|MX|IT|ES|NL|RU|KR|SA|AE|SG|MY|TH|VN|PH|ID|PK|BD|NG|ZA|EG|TR|PL|SE|NO|DK|FI|CH|AT|BE|PT|GR|CZ|HU|RO|SK|HK|TW|NZ|AR|CL|CO|PE|VE|UA|IL|IR|IQ|ZA)\s*$/i', $trimmed_text) ||
            // 信号4：「数字 文字 文字, 文字, 文字」多逗号分隔的地理层级结构（城市, 州, 国家 / 门牌, 街道, 城市）
            preg_match('/^\d+[^,\n]{3,60},\s*[^,\n]{2,60},\s*[^,\n]{2,60}/u', $trimmed_text) ||
            // 信号5：中文地址（保留原有中文特征兼容）
            preg_match('/(?:省|市|区|县|镇|街道|路|号|楼|室|单元|村|弄).{0,30}(?:省|市|区|县|镇|街道|路|号|楼|室|单元)/u', $trimmed_text) ||
            // 信号6：「P.O. Box / PO Box / Postfach / Apartado / 私書箱」邮政信箱
            preg_match('/\bP\.?O\.?\s*Box\b|\bPostfach\b|\bApartado\b|\b私書箱\b|\bB\.?P\.?\s*\d+/i', $trimmed_text)
        ) {
            return $text;
        }

        $target_language = $language ?: $this->current_language;
        // 统一语言代码格式（连字符并区域大写）
        if (function_exists('canonical_locale')) {
            $target_language = canonical_locale($target_language);
        } else {
            $target_language = str_replace('_', '-', $target_language);
        }

        // 解析 [zh|...][en|...] 多语言内联标记语法
        if (is_string($text) && strpos($text, '[') !== false && preg_match('/\[([a-z]{2}(?:-[A-Z]{2})?)\|([^\]]*)\]/i', $text)) {
            $lang_short = strtolower(substr($target_language, 0, 2));
            if (preg_match('/\[(?:' . preg_quote($target_language, '/') . '|' . preg_quote($lang_short, '/') . ')\|([^\]]*)\]/i', $text, $m)) {
                return $m[1];
            }
            // 回退取第一组 [lang|text]
            if (preg_match('/\[[a-z]{2}(?:-[A-Z]{2})?\|([^\]]*)\]/i', $text, $m)) {
                return $m[1];
            }
        }

        // 引入主题与设备维度，支持多主题/多设备/多翻译域
        $device = 'pc';
        if (class_exists('CMS_Theme')) {
            $theme_obj = CMS_Theme::get_active_theme();
            if ($theme_obj && method_exists($theme_obj, 'get_device_style')) {
                $device = $theme_obj->get_device_style();
            }
        } elseif (function_exists('todo_is_mobile') && todo_is_mobile()) {
            $device = 'mobile';
        }
        $theme = (function_exists('get_theme_name') ? get_theme_name() : 'default') . ':' . $device;

        // 数据表存在性检查
        $hasTerms = $this->db && method_exists($this->db, 'table_exists') && $this->db->table_exists('language_terms');
        $hasTranslations = $hasTerms && $this->db->table_exists('language_translations');
        $hasCache = $hasTerms && $this->db->table_exists('language_cache');

        // 即便缺少数据库表，也允许文件型语言包回退
        if (!$hasTerms || !$hasTranslations) {
            // 尝试资源文件回退（主题 assets/lang 或 resources/lang）
            if (class_exists('CMS_Translation')) {
                // 确保文件型翻译按目标语言与设备模式初始化，避免跨语言/跨终端污染
                if (method_exists('CMS_Translation', 'init_for_locale')) {
                    CMS_Translation::init_for_locale($target_language, $device);
                }
                $fallback = null;
                if (!empty($context)) {
                    // 优先尝试带上下文的资源翻译
                    $fallback = CMS_Translation::translate_with_context($text, $context, 'default');
                    if ($fallback === $context . '\\4' . $text) { // translate_with_context 未命中时会返回拼接原文
                        $fallback = null;
                    }
                }
                if ($fallback === null) {
                    $fallback = CMS_Translation::translate($text, 'default');
                }
                if (is_string($fallback) && $fallback !== $text) {
                    return $fallback;
                }
            }
            // 无法从文件型语言包获取翻译，返回原文
            return $text;
        }

        // 性能优化：首次调用时一次性批量预加载当前语言与主题的整表词汇字典到内存，彻底消灭 N+1 查询
        $this->preload_request_dictionary($target_language, $theme);

        // 获取或创建词汇条目（自动检测并生成新词条）
        $term = $this->get_or_create_term($text, $context);
        
        if ($term && !empty($term['id'])) {
            $ctx_for_key = isset($term['context']) && $term['context'] ? $term['context'] : '';
            $cache_key = md5($text . '|' . $ctx_for_key . '|' . $target_language . '|' . $theme);

            // 内存缓存命中 DB 翻译
            if (isset($this->translation_cache[$cache_key])) {
                return $this->translation_cache[$cache_key];
            }
        } else {
            $cache_key = md5($text . '|' . ($context ?: '') . '|' . $target_language . '|' . $theme);
        }

        // 数据库预加载未命中翻译时，尝试资源文件 (CMS_Translation, 主题/系统的 PHP 语言包如 en_US.php) 回退
        if (class_exists('CMS_Translation')) {
            if (method_exists('CMS_Translation', 'init_for_locale')) {
                CMS_Translation::init_for_locale($target_language, $device);
            }
            $fallback = null;
            if (!empty($context)) {
                $fallback = CMS_Translation::translate_with_context($text, $context, 'default');
                if ($fallback === $context . '\4' . $text) {
                    $fallback = null;
                }
            }
            if ($fallback === null) {
                $fallback = CMS_Translation::translate($text, 'default');
            }
            if (is_string($fallback) && $fallback !== $text) {
                $this->translation_cache[$cache_key] = $fallback;
                return $fallback;
            }
        }

        // 记录缺失词条待插队列
        if ($term && !empty($term['id'])) {
            $missing_key = $term['id'] . '|' . $target_language;
            if (!isset($this->pending_missing_translations[$missing_key])) {
                $this->pending_missing_translations[$missing_key] = [
                    'term_id' => $term['id'],
                    'language' => $target_language
                ];
            }
        }

        // 无任何有效翻译时，记录 negative cache 并返回原文
        $this->translation_cache[$cache_key] = $text;
        return $text;
    }

    /**
     * 请求级字典一次性批量预加载（1条 SQL 预加载当前语言全量词汇与翻译）
     */
    private function preload_request_dictionary($target_language, $theme)
    {
        static $preloaded_keys = [];
        $preload_key = $target_language . '|' . $theme;
        if (isset($preloaded_keys[$preload_key])) {
            return;
        }
        $preloaded_keys[$preload_key] = true;

        if (!$this->db || !method_exists($this->db, 'table_exists') || !$this->db->table_exists('language_terms') || !$this->db->table_exists('language_translations')) {
            return;
        }

        $terms_table = $this->db->prefix . 'language_terms';
        $trans_table = $this->db->prefix . 'language_translations';

        // 一次性查出当前语言下所有关联的主题和全局词条翻译
        // 注意：数据库存储的 theme 为纯主题名（如 "default"），而传入的 $theme 可能包含设备后缀（如 "default:pc"）
        $theme_base = !empty($theme) ? explode(':', $theme)[0] : 'default';
        $sql = "SELECT lt.id, lt.term_key, lt.original_text, lt.context, lt.theme, tr.translated_text
                FROM {$terms_table} lt
                LEFT JOIN {$trans_table} tr ON (lt.id = tr.term_id AND (tr.language_code = ? OR tr.language_code = ?))
                WHERE (lt.theme IN ('default', 'global', ?, ?) OR lt.theme IS NULL OR lt.theme = '')";
        
        $alt_lang = str_replace('-', '_', $target_language);
        $rows = $this->db->get_results($sql, [$target_language, $alt_lang, $theme_base, $theme]);
        if ($rows && is_array($rows)) {
            foreach ($rows as $row) {
                $text = (string)$row->original_text;
                $ctx = (string)($row->context ?: '');
                $term_theme = (string)($row->theme ?: '');

                $term_obj = [
                    'id' => (int)$row->id,
                    'term_key' => $row->term_key,
                    'original_text' => $text,
                    'context' => $row->context,
                ];

                if (!empty($row->term_key)) {
                    self::$term_key_exists_cache[$row->term_key] = true;
                }
                
                $cache_key_term = md5($text . '|' . $ctx . '|' . $term_theme);
                self::$term_cache[$cache_key_term] = $term_obj;
                $cache_key_term_current = md5($text . '|' . $ctx . '|' . $theme);
                self::$term_cache[$cache_key_term_current] = $term_obj;
                $cache_key_term_empty = md5($text . '||' . $theme);
                self::$term_cache[$cache_key_term_empty] = $term_obj;

                self::$terms_by_text[$text] = $term_obj;
                if ($term_theme !== '') {
                    self::$terms_by_text_theme[$text . '|' . $term_theme] = $term_obj;
                }
                self::$terms_by_text_theme[$text . '|' . $theme] = $term_obj;

                if (!empty($row->translated_text) && $row->translated_text !== $text) {
                    $trans_text = $row->translated_text;
                    $cache_key_trans = md5($text . '|' . $ctx . '|' . $target_language . '|' . $theme);
                    $this->translation_cache[$cache_key_trans] = $trans_text;
                    $cache_key_trans_empty = md5($text . '||' . $target_language . '|' . $theme);
                    $this->translation_cache[$cache_key_trans_empty] = $trans_text;
                    $cache_key_trans_by_text = md5($text . '|' . $target_language);
                    $this->translation_cache[$cache_key_trans_by_text] = $trans_text;
                }
            }
        }
        $this->bulk_queries_executed = true;
        $this->bulk_terms_queries_executed = true;
    }

    /**
     * 获取或创建词汇条目（支持批量查询优化）
     */
    private function get_or_create_term($text, $context = null)
    {
        // 缺少词汇表时不进行数据库操作
        if (!$this->db || !method_exists($this->db, 'table_exists') || !$this->db->table_exists('language_terms')) {
            return null;
        }

        // 当前主题（与 translate() 保持一致，需包含设备后缀，如 "default:pc"）
        $theme_name = function_exists('get_theme_name') ? get_theme_name() : 'default';
        $device = 'pc';
        if (class_exists('CMS_Theme')) {
            $theme_obj = CMS_Theme::get_active_theme();
            if ($theme_obj && method_exists($theme_obj, 'get_device_style')) {
                $device = $theme_obj->get_device_style();
            }
        } elseif (function_exists('todo_is_mobile') && todo_is_mobile()) {
            $device = 'mobile';
        }
        $theme = $theme_name . ':' . $device;

        // 构建缓存键
        $cache_key = md5($text . '|' . ($context ?: '') . '|' . $theme);
        
        // 检查静态内存缓存（请求级别共享，包含 negative cache）
        if (array_key_exists($cache_key, self::$term_cache)) {
            return self::$term_cache[$cache_key] ?: null;
        }

        // 尝试从预加载字典中的原文索引精准查找
        if (isset(self::$terms_by_text_theme[$text . '|' . $theme])) {
            $term = self::$terms_by_text_theme[$text . '|' . $theme];
            self::$term_cache[$cache_key] = $term;
            return $term;
        }

        if (isset(self::$terms_by_text[$text])) {
            $term = self::$terms_by_text[$text];
            self::$term_cache[$cache_key] = $term;
            return $term;
        }

        // 如果预加载字典已执行，可以确认词条不在 DB，跳过 SELECT；但仍需允许自动创建新词条
        $existing_term = $this->query_single_term($text, $context, $theme, $cache_key);

        if ($existing_term) {
            self::$term_cache[$cache_key] = $existing_term;
            return $existing_term;
        }

        // 未命中则设置 negative cache 标记，防止同一请求中对缺失词条反复触发 SQL
        self::$term_cache[$cache_key] = false;
        return null;
    }

    /**
     * 查询单个词汇条目（后备方法）
     */
    private function query_single_term($text, $context, $theme, $cache_key)
    {
        $existing_term = null;

        // 若尚未全量预加载，尝试从 DB 查询；若已全量预加载过整表，未命中的词条必定不在 DB 中，直接跳过 SELECT
        if (!$this->bulk_terms_queries_executed) {
            // 优先查同主题同原文的现有词条（杜绝因为 context 行号变化导致重复插库）
            $existing_term = $this->db->get_row(
                "SELECT * FROM {$this->db->prefix}language_terms WHERE original_text = ? AND theme = ? ORDER BY id DESC LIMIT 1",
                [$text, $theme]
            );

            if (!$existing_term) {
                // 回退查找跨主题同原文词条
                $existing_term = $this->db->get_row(
                    "SELECT * FROM {$this->db->prefix}language_terms WHERE original_text = ? ORDER BY id DESC LIMIT 1",
                    [$text]
                );
            }

            if ($existing_term) {
                $result = [
                    'id' => $existing_term->id,
                    'term_key' => $existing_term->term_key,
                    'original_text' => $existing_term->original_text,
                    'context' => $existing_term->context,
                ];
                self::$term_cache[$cache_key] = $result;
                return $result;
            }
        }

        // 在后台管理上下文中，不自动创建新词条，避免后台UI文本被反复写入词汇表
        if ($this->is_admin_context()) {
            return null;
        }

        // 运行时自动收集词条筛选：防止符号、流水号、纯英文状态Code、账号用户名、日志描述写入词库
        $has_native_letter = preg_match('/[^\x00-\x7F]/u', $text) && preg_match('/\p{L}/u', $text);
        $trimmed_text = trim($text);

        // 包含动态交易单号/流水号或动态规格组合(Key: Value)的不允许自动生成词条
        if (
            preg_match('/[A-Z0-9]{12,}/i', $trimmed_text) || 
            preg_match('/(?:订单支付|交易|流水|单号|Ref|SN|Transaction)\s*[:：#]?\s*[A-Z0-9]{8,}/iu', $trimmed_text) ||
            preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}\s-]+[:：]\s*.+$/u', $trimmed_text)
        ) {
            return null;
        }

        if (!$has_native_letter && empty($context)) {
            if (
                mb_strlen($trimmed_text) <= 2 || // 简短符号或单字（如 +, -）
                preg_match('/^[+\-*\/=><&|!#%^~@$:;,._\s]+$/u', $trimmed_text) ||
                preg_match('/^(?:processing|pending|paid|unpaid|completed|success|failed|cancelled|refunded|shipped|delivered|active|inactive|disabled|enabled|pcs|piece|pieces|set|sets|box|boxes|item|items|balance)$/i', $trimmed_text) || // 系统状态Slug/纯属性Key
                (preg_match('/^[a-z0-9_-]+$/i', $trimmed_text) && preg_match('/[0-9]/', $trimmed_text)) || // 混合数字的账号/Id（如 user882, T20261005165712000017）
                preg_match('/(?:from|changed|created|status|order|payment|updated|deleted|inserted|top-up)/i', $trimmed_text) || // 状态变迁与日志词汇（如 Order created）
                preg_match('/^[A-Z][a-z]+\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?$/', $trimmed_text) || // 人名（如 Oliver Martin）
                preg_match('/^[a-z]{2,3}-[a-z0-9_-]+$/i', $trimmed_text) || // 语言Slug（如 en-youtube）
                preg_match('/^\d+\s*[a-zA-Z%]+$/i', $trimmed_text) // 数量单位（如 1pcs）
            ) {
                return null;
            }
        }

        // 如果没有传入显式 $context，通过调用栈自动补全出处位置
        // 注意：预加载完成后跳过 debug_backtrace()，避免每个新词条都发生一次昂贵的调用栈捕获
        if (empty($context) && !$this->bulk_terms_queries_executed) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
            foreach ($trace as $frame) {
                if (isset($frame['file']) && isset($frame['line'])) {
                    $file = $frame['file'];
                    // 跳过多语言系统内部的核心处理文件
                    if (
                        strpos($file, 'includes/language/') !== false ||
                        strpos($file, 'class-language-manager.php') !== false ||
                        strpos($file, 'class-template-compiler') !== false
                    ) {
                        continue;
                    }
                    $abs_path = str_replace('\\', '/', $file);
                    $site_path = defined('ABSPATH') ? str_replace('\\', '/', ABSPATH) : '';
                    $rel_file = $site_path ? str_replace($site_path, '', $abs_path) : $abs_path;
                    $rel_file = ltrim($rel_file, '/');
                    $context = $rel_file . '#' . $frame['line'];
                    break;
                }
            }
        }

        // 在真正创建新词条前，再次进行全库唯一性确认（确保 original_text 不重复插库）
        if (!$this->bulk_terms_queries_executed) {
            $check_existing = $this->db->get_row(
                "SELECT * FROM {$this->db->prefix}language_terms WHERE original_text = ? ORDER BY id DESC LIMIT 1",
                [$text]
            );
            if ($check_existing) {
                $result = [
                    'id' => $check_existing->id,
                    'term_key' => $check_existing->term_key,
                    'original_text' => $check_existing->original_text,
                    'context' => $check_existing->context,
                ];
                self::$term_cache[$cache_key] = $result;
                return $result;
            }
        }

        // 生成干净简洁的词汇键 term_key（不再拼接冗长的 context 文件路径）
        $slug = sanitize_title($text);
        if (empty($slug) || strlen(trim($slug, '-_')) === 0) {
            $slug = 'term_' . substr(md5($text), 0, 10);
        }
        $base_key = $slug;
        if (!empty($theme) && $theme !== 'default') {
            $theme_slug = sanitize_title($theme) ?: 'theme';
            $base_key = $theme_slug . '_' . $base_key;
        }
        // 保证唯一：若冲突则加后缀编号（使用缓存优化）
        $suffix = 0;
        $term_key = $base_key;
        while ($this->term_key_exists($term_key)) {
            $suffix++;
            $term_key = $base_key . '_' . $suffix;
            // 防止无限循环
            if ($suffix > 100) {
                $term_key = $base_key . '_' . time() . '_' . mt_rand(1000, 9999);
                break;
            }
        }

        // 创建新词汇条目（存入数据库的 theme 必须是标准主题名，如 "default"，不能带 ":pc" 等设备后缀，以便后台词汇管理列表正确筛选）
        $theme_for_db = !empty($theme) ? explode(':', $theme)[0] : 'default';
        $term_data = [
            'term_key' => $term_key,
            'original_text' => $text,
            'context' => $context,
            'theme' => $theme_for_db,
            'auto_detected' => 1,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];

        $result = $this->db->insert("{$this->db->prefix}language_terms", $term_data);

        if ($result) {
            $term_id = intval($result);
            $this->auto_detected_terms[] = $term_key;

            $result_data = [
                'id' => $term_id,
                'term_key' => $term_key,
                'original_text' => $text,
                'context' => $context,
            ];
            // 缓存新创建的词条（静态缓存）
            self::$term_cache[$cache_key] = $result_data;
            // 同时缓存 term_key 存在性
            self::$term_key_exists_cache[$term_key] = true;
            return $result_data;
        }

        return null;
    }

    /**
     * 执行批量词汇查询（优化N+1查询问题）
     */
    private function execute_bulk_term_queries()
    {
        if (empty($this->pending_terms)) {
            return;
        }

        // 允许多次执行，每次处理当前的待查询列表
        // 保存当前待查询的请求，然后清空列表（允许后续继续收集）
        $current_pending = $this->pending_terms;
        $this->pending_terms = [];
        
        // 标记为已执行（但允许后续再次执行）
        if (!$this->bulk_terms_queries_executed) {
            $this->bulk_terms_queries_executed = true;
        }

        // 如果没有数据库表，跳过批量查询
        if (!$this->db || !method_exists($this->db, 'table_exists') || !$this->db->table_exists('language_terms')) {
            return;
        }

        // 按主题分组
        $by_theme = [];
        foreach ($current_pending as $cache_key => $pending) {
            $theme = $pending['theme'];
            if (!isset($by_theme[$theme])) {
                $by_theme[$theme] = [];
            }
            $by_theme[$theme][$cache_key] = $pending;
        }

        // 批量查询每个主题的词汇
        foreach ($by_theme as $theme => $requests) {
            // 收集所有文本和上下文
            $texts = [];
            $text_context_pairs = [];
            foreach ($requests as $cache_key => $pending) {
                $text = $pending['text'];
                $context = $pending['context'] ?: '';
                $texts[] = $text;
                $text_context_pairs[] = ['text' => $text, 'context' => $context, 'cache_key' => $cache_key];
            }

            if (empty($texts)) {
                continue;
            }

            // 批量查询：先查询精确匹配（原文+上下文+主题）
            $placeholders = implode(',', array_fill(0, count($texts), '?'));
            $context_placeholders = implode(',', array_fill(0, count($texts), '?'));
            
            // 构建查询：查找所有匹配的词汇（包括有上下文和无上下文的）
            $sql = "SELECT * FROM {$this->db->prefix}language_terms 
                    WHERE theme = ? AND original_text IN ($placeholders)
                    ORDER BY 
                        CASE WHEN context IS NULL OR context = '' THEN 1 ELSE 0 END,
                        id DESC";
            
            $params = array_merge([$theme], $texts);
            $existing_terms = $this->db->get_results($sql, $params);

            // 构建结果映射：优先精确匹配（原文+上下文+主题），其次模糊匹配（原文+主题）
            $term_map = [];
            foreach ($existing_terms as $term) {
                $text = $term->original_text;
                $ctx = $term->context ?: '';
                $key = md5($text . '|' . $ctx . '|' . $theme);
                
                // 如果该文本+上下文组合还没有结果，或者当前结果更精确（有上下文），则使用它
                if (!isset($term_map[$key]) || (!empty($ctx) && empty($term_map[$key]['context']))) {
                    $term_map[$key] = [
                        'id' => $term->id,
                        'term_key' => $term->term_key,
                        'original_text' => $term->original_text,
                        'context' => $term->context,
                    ];
                }
            }

            // 处理每个请求
            foreach ($text_context_pairs as $pair) {
                $cache_key = $pair['cache_key'];
                $text = $pair['text'];
                $context = $pair['context'];
                
                // 先尝试精确匹配
                $exact_key = md5($text . '|' . $context . '|' . $theme);
                if (isset($term_map[$exact_key])) {
                    self::$term_cache[$cache_key] = $term_map[$exact_key];
                    continue;
                }
                
                // 再尝试模糊匹配（无上下文）
                $fuzzy_key = md5($text . '||' . $theme);
                if (isset($term_map[$fuzzy_key])) {
                    self::$term_cache[$cache_key] = $term_map[$fuzzy_key];
                    continue;
                }

                // 如果未找到，且非后台环境，则立即创建
                if (!$this->is_admin_context()) {
                    // 生成唯一键并创建新词条
                    $slug = sanitize_title($text);
                    if (empty($slug) || strlen(trim($slug, '-_')) === 0) {
                        $slug = 'term_' . substr(md5($text), 0, 10);
                    }
                    $base_key = $slug;
                    if (!empty($context)) {
                        $ctx_slug = sanitize_title($context) ?: substr(md5($context), 0, 6);
                        $base_key = $ctx_slug . '_' . $base_key;
                    }
                    if (!empty($theme)) {
                        $theme_slug = sanitize_title($theme) ?: 'theme';
                        $base_key = $theme_slug . '_' . $base_key;
                    }

                    // 保证唯一：若冲突则加后缀编号
                    $suffix = 0;
                    $term_key = $base_key;
                    while ($this->term_key_exists($term_key)) {
                        $suffix++;
                        $term_key = $base_key . '_' . $suffix;
                        if ($suffix > 100) {
                            $term_key = $base_key . '_' . time() . '_' . mt_rand(1000, 9999);
                            break;
                        }
                    }

                    $theme_for_db = !empty($theme) ? explode(':', $theme)[0] : 'default';
                    $term_data = [
                        'term_key' => $term_key,
                        'original_text' => $text,
                        'context' => $context,
                        'theme' => $theme_for_db,
                        'auto_detected' => 1,
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ];

                    $result = $this->db->insert("{$this->db->prefix}language_terms", $term_data);

                    if ($result) {
                        $term_id = intval($result);
                        $this->auto_detected_terms[] = $term_key;

                        $result_data = [
                            'id' => $term_id,
                            'term_key' => $term_key,
                            'original_text' => $text,
                            'context' => $context,
                        ];
                        self::$term_cache[$cache_key] = $result_data;
                        self::$term_key_exists_cache[$term_key] = true;
                    }
                }
            }
        }
    }

    /**
     * 检查词汇键值是否存在（带缓存优化）
     */
    private function term_key_exists($term_key)
    {
        // 缺少词汇表时认为不存在，避免数据库异常
        if (!$this->db || !method_exists($this->db, 'table_exists') || !$this->db->table_exists('language_terms')) {
            return false;
        }

        // 检查静态缓存
        if (isset(self::$term_key_exists_cache[$term_key])) {
            return self::$term_key_exists_cache[$term_key];
        }

        $result = $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->db->prefix}language_terms WHERE term_key = ?",
            [$term_key]
        );

        $exists = $result > 0;
        // 缓存结果
        self::$term_key_exists_cache[$term_key] = $exists;
        return $exists;
    }

    /**
     * 获取翻译
     */
    private function get_translation($term_id, $language)
    {
        // 缺少翻译表时返回空
        if (!$this->db || !method_exists($this->db, 'table_exists') || !$this->db->table_exists('language_translations')) {
            return null;
        }
        // 统一语言代码格式
        if (function_exists('canonical_locale')) {
            $language = canonical_locale($language);
        } else {
            $language = str_replace('_', '-', $language);
        }

        $translation = $this->db->get_var(
            "SELECT translated_text FROM {$this->db->prefix}language_translations 
             WHERE term_id = ? AND language_code = ? AND translated_text IS NOT NULL AND translated_text != ''
                 ORDER BY is_selected DESC, (status='approved') DESC, updated_at DESC, id DESC LIMIT 1",
            [$term_id, $language]
        );

        return $translation;
    }

    /**
     * 获取缓存的翻译
     */
    private function get_cached_translation($cache_key, $language)
    {
        // 缺少缓存表时返回空
        if (!$this->db || !method_exists($this->db, 'table_exists') || !$this->db->table_exists('language_cache')) {
            return null;
        }

        $cached = $this->db->get_var(
            "SELECT cache_data FROM {$this->db->prefix}language_cache 
             WHERE language_code = ? AND cache_key = ? AND expires_at > NOW()",
            [$language, $cache_key]
        );

        if ($cached) {
            $data = json_decode($cached, true);
            return $data['translation'] ?? null;
        }

        return null;
    }

    /**
     * 缓存翻译结果
     */
    private function cache_translation($cache_key, $language, $translation)
    {
        // 缺少缓存表时跳过缓存
        if (!$this->db || !method_exists($this->db, 'table_exists') || !$this->db->table_exists('language_cache')) {
            return;
        }

        $cache_data = json_encode(['translation' => $translation]);
        $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1小时后过期

        // 使用 upsert 逻辑替代不存在的 replace(): 先查是否存在，再按存在更新，不存在插入
        $existing_id = $this->db->get_var(
            "SELECT id FROM {$this->db->prefix}language_cache WHERE language_code = ? AND cache_key = ?",
            [$language, $cache_key]
        );

        if ($existing_id) {
            // 更新已有缓存
            $this->db->update(
                "{$this->db->prefix}language_cache",
                [
                    'cache_data' => $cache_data,
                    'expires_at' => $expires_at,
                    'updated_at' => current_time('mysql')
                ],
                [
                    'language_code' => $language,
                    'cache_key' => $cache_key
                ]
            );
        } else {
            // 插入新的缓存记录
            $this->db->insert(
                "{$this->db->prefix}language_cache",
                [
                    'language_code' => $language,
                    'cache_key' => $cache_key,
                    'cache_data' => $cache_data,
                    'expires_at' => $expires_at,
                    'created_at' => current_time('mysql')
                ]
            );
        }
    }

    /**
     * 记录缺失的翻译（批量处理优化）
     */
    private function log_missing_translation($term_id, $language)
    {
        // 统一语言代码格式
        if (function_exists('canonical_locale')) {
            $language = canonical_locale($language);
        } else {
            $language = str_replace('_', '-', $language);
        }

        // 构建缓存键
        $cache_key = $term_id . '|' . $language;
        
        // 检查静态内存缓存，避免重复查询和插入
        if (isset(self::$missing_translation_cache[$cache_key])) {
            return;
        }

        // 标记为已处理（先标记，避免并发重复插入）
        self::$missing_translation_cache[$cache_key] = true;

        // 添加到批量插入队列
        if (!isset($this->pending_missing_translations[$cache_key])) {
            $this->pending_missing_translations[$cache_key] = [
                'term_id' => $term_id,
                'language' => $language
            ];
        }
    }

    /**
     * 批量插入缺失翻译记录（优化N+1查询问题）
     */
    private function execute_bulk_missing_translation_inserts()
    {
        if (empty($this->pending_missing_translations)) {
            return;
        }

        // 缺少翻译表时跳过记录
        if (!$this->db || !method_exists($this->db, 'table_exists') || !$this->db->table_exists('language_translations')) {
            $this->pending_missing_translations = [];
            return;
        }

        // 批量检查哪些记录已存在
        $term_ids = array_unique(array_column($this->pending_missing_translations, 'term_id'));
        $languages = array_unique(array_column($this->pending_missing_translations, 'language'));
        
        if (empty($term_ids) || empty($languages)) {
            $this->pending_missing_translations = [];
            return;
        }

        // 查询 todo_languages 表，获取实际存在的语言代码白名单（防止外键约束错误）
        $valid_language_codes = [];
        try {
            if ($this->db->table_exists('languages')) {
                $placeholders_all_langs = implode(',', array_fill(0, count($languages), '?'));
                $valid_rows = $this->db->get_results(
                    "SELECT code FROM {$this->db->prefix}languages WHERE code IN ($placeholders_all_langs)",
                    $languages
                );
                foreach ($valid_rows as $row) {
                    $valid_language_codes[] = $row->code;
                }
            }
        } catch (\Throwable $e) {
            // 查询失败时降级：跳过整批插入，避免 FK 异常
            $this->pending_missing_translations = [];
            return;
        }

        // 没有任何合法语言码，不插入
        if (empty($valid_language_codes)) {
            $this->pending_missing_translations = [];
            return;
        }

        // 只保留白名单内的语言
        $languages = array_values(array_intersect($languages, $valid_language_codes));
        if (empty($languages)) {
            $this->pending_missing_translations = [];
            return;
        }

        // 批量查询已存在的记录
        $placeholders_ids = implode(',', array_fill(0, count($term_ids), '?'));
        $placeholders_langs = implode(',', array_fill(0, count($languages), '?'));
        $existing = $this->db->get_results(
            "SELECT term_id, language_code FROM {$this->db->prefix}language_translations 
             WHERE term_id IN ($placeholders_ids) AND language_code IN ($placeholders_langs)",
            array_merge($term_ids, $languages)
        );

        // 构建已存在记录的映射
        $existing_map = [];
        foreach ($existing as $row) {
            $key = $row->term_id . '|' . $row->language_code;
            $existing_map[$key] = true;
        }

        // 批量插入不存在的记录
        $to_insert = [];
        $log_entries = [];
        $theme = function_exists('get_theme_name') ? get_theme_name() : 'default';
        $now = current_time('mysql');

        foreach ($this->pending_missing_translations as $cache_key => $pending) {
            // 跳过不在白名单内的语言（二次保障）
            if (!in_array($pending['language'], $valid_language_codes, true)) {
                continue;
            }
            if (!isset($existing_map[$cache_key])) {
                $to_insert[] = [
                    'term_id' => $pending['term_id'],
                    'language_code' => $pending['language'],
                    'translated_text' => '',
                    'status' => 'pending',
                    'translator_notes' => '',
                    'created_at' => $now
                ];
                $log_entries[] = sprintf("[%s] term_id=%d lang=%s theme=%s\n", date('Y-m-d H:i:s'), $pending['term_id'], $pending['language'], $theme);
            }
        }

        // 批量插入
        if (!empty($to_insert)) {
            // 使用批量插入（如果数据库支持）
            foreach ($to_insert as $data) {
                $this->db->insert("{$this->db->prefix}language_translations", $data);
            }

            // 批量写入日志文件（受 TODO_DEBUG 控制）
            if (defined('TODO_DEBUG') && TODO_DEBUG && !empty($log_entries)) {
                $logDir = ABSPATH . 'logs';
                $logFile = $logDir . '/language-missing.log';
                if (!is_dir($logDir)) {
                    @mkdir($logDir, 0755, true);
                }
                @file_put_contents($logFile, implode('', $log_entries), FILE_APPEND);
            }
        }

        // 清空待插入列表
        $this->pending_missing_translations = [];
    }

    /**
     * 执行批量翻译查询（优化N+1查询问题）
     */
    private function execute_bulk_translation_queries()
    {
        if (empty($this->pending_translations)) {
            return;
        }

        // 保存当前待查询的请求，然后清空列表（允许后续继续收集）
        $current_pending = $this->pending_translations;
        $this->pending_translations = [];
        
        // 如果这是第一次执行，标记为已执行（但允许后续再次执行）
        if (!$this->bulk_queries_executed) {
            $this->bulk_queries_executed = true;
        }

        // 如果没有数据库表，跳过批量查询
        $hasTerms = $this->db && method_exists($this->db, 'table_exists') && $this->db->table_exists('language_terms');
        $hasTranslations = $hasTerms && $this->db->table_exists('language_translations');
        $hasCache = $hasTerms && $this->db->table_exists('language_cache');

        if (!$hasTerms || !$hasTranslations) {
            // 如果没有数据库表，尝试文件型回退
            foreach ($current_pending as $cache_key => $pending) {
                if (class_exists('CMS_Translation')) {
                    $target_language = $pending['language'];
                    if (method_exists('CMS_Translation', 'init_for_locale')) {
                        CMS_Translation::init_for_locale($target_language);
                    }
                    $fallback = null;
                    if (!empty($pending['context'])) {
                        $fallback = CMS_Translation::translate_with_context($pending['text'], $pending['context'], 'default');
                        if ($fallback === $pending['context'] . '\\4' . $pending['text']) {
                            $fallback = null;
                        }
                    }
                    if ($fallback === null) {
                        $fallback = CMS_Translation::translate($pending['text'], 'default');
                    }
                    if (is_string($fallback) && $fallback !== $pending['text']) {
                        $this->translation_cache[$cache_key] = $fallback;
                    } else {
                        $this->translation_cache[$cache_key] = $pending['text'];
                    }
                } else {
                    $this->translation_cache[$cache_key] = $pending['text'];
                }
            }
            return;
        }

        // 按语言分组
        $by_language = [];
        foreach ($current_pending as $cache_key => $pending) {
            $lang = $pending['language'];
            if (!isset($by_language[$lang])) {
                $by_language[$lang] = [];
            }
            $by_language[$lang][$cache_key] = $pending;
        }

        // 批量查询每个语言的翻译
        foreach ($by_language as $language => $requests) {
            $term_ids = array_unique(array_column($requests, 'term_id'));
            if (empty($term_ids)) {
                continue;
            }

            // 统一语言代码格式
            if (function_exists('canonical_locale')) {
                $language = canonical_locale($language);
            } else {
                $language = str_replace('_', '-', $language);
            }

            // 批量查询翻译（优先查询已批准的翻译）
            $placeholders = implode(',', array_fill(0, count($term_ids), '?'));
            $translations = $this->db->get_results(
                "SELECT term_id, translated_text FROM {$this->db->prefix}language_translations 
                 WHERE term_id IN ($placeholders) AND language_code = ? 
                   AND translated_text IS NOT NULL AND translated_text != ''
                 ORDER BY is_selected DESC, (status='approved') DESC, updated_at DESC, id DESC",
                array_merge($term_ids, [$language])
            );

            // 构建 term_id => translation 映射
            $translation_map = [];
            foreach ($translations as $trans) {
                $tid = intval($trans->term_id);
                // 只保留第一个（优先级最高的）翻译
                if (!isset($translation_map[$tid])) {
                    $translation_map[$tid] = $trans->translated_text;
                }
            }

            // 批量查询缓存（如果缓存表存在）
            $cache_keys_to_query = array_keys($requests);
            $cache_results = [];
            if ($hasCache && !empty($cache_keys_to_query)) {
                $cache_placeholders = implode(',', array_fill(0, count($cache_keys_to_query), '?'));
                $cache_data = $this->db->get_results(
                    "SELECT cache_key, cache_data FROM {$this->db->prefix}language_cache 
                     WHERE language_code = ? AND cache_key IN ($cache_placeholders) AND expires_at > NOW()",
                    array_merge([$language], $cache_keys_to_query)
                );

                foreach ($cache_data as $cached) {
                    $data = json_decode($cached->cache_data, true);
                    if (isset($data['translation'])) {
                        $cache_results[$cached->cache_key] = $data['translation'];
                    }
                }
            }

            // 处理每个请求
            foreach ($requests as $cache_key => $pending) {
                $term_id = $pending['term_id'];
                $text = $pending['text'];

                // 优先使用缓存结果
                if (isset($cache_results[$cache_key])) {
                    $translation = $cache_results[$cache_key];
                    // 如果缓存内容与原文一致，尝试从翻译表获取
                    if ($translation === $text && isset($translation_map[$term_id])) {
                        $translation = $translation_map[$term_id];
                    }
                } elseif (isset($translation_map[$term_id])) {
                    $translation = $translation_map[$term_id];
                } else {
                    // 未找到翻译，尝试文件型回退
                    $translation = null;
                    if (class_exists('CMS_Translation')) {
                        if (method_exists('CMS_Translation', 'init_for_locale')) {
                            CMS_Translation::init_for_locale($language);
                        }
                        $fallback = null;
                        if (!empty($pending['context'])) {
                            $fallback = CMS_Translation::translate_with_context($text, $pending['context'], 'default');
                            if ($fallback === $pending['context'] . '\\4' . $text) {
                                $fallback = null;
                            }
                        }
                        if ($fallback === null) {
                            $fallback = CMS_Translation::translate($text, 'default');
                        }
                        if (is_string($fallback) && $fallback !== $text) {
                            $translation = $fallback;
                        }
                    }
                    
                    if ($translation === null) {
                        $translation = $text;
                        // 记录缺失翻译
                        $this->log_missing_translation($term_id, $language);
                    }
                }

                // 存储到内存缓存
                $this->translation_cache[$cache_key] = $translation;

                // 如果从翻译表获取到结果，更新缓存表
                if ($hasCache && isset($translation_map[$term_id]) && $translation === $translation_map[$term_id]) {
                    $this->cache_translation($cache_key, $language, $translation);
                }
            }
        }

        // 注意：$this->pending_translations 已在函数开始时清空，允许后续继续收集新请求
    }

    /**
     * 注册钩子
     */
    private function register_hooks()
    {
        // 在页面结束时保存自动检测到的词汇
        add_action('shutdown', [$this, 'save_auto_detected_terms']);
        
        // 在页面结束时执行批量查询（确保所有翻译请求都被处理）
        add_action('shutdown', [$this, 'flush_bulk_translation_queries'], 999);
        
        // 在页面结束时执行批量词汇查询（确保所有词汇请求都被处理）
        add_action('shutdown', [$this, 'flush_bulk_term_queries'], 998);
        
        // 在页面结束时执行批量缺失翻译插入（确保所有缺失翻译都被记录）
        add_action('shutdown', [$this, 'flush_bulk_missing_translations'], 997);
    }

    /**
     * 保存自动检测到的词汇
     */
    public function save_auto_detected_terms()
    {
        if (!empty($this->auto_detected_terms)) {
            // 这里可以记录日志或执行其他操作
            error_log('TodoCMS Language: Auto-detected ' . count($this->auto_detected_terms) . ' new terms');
        }
    }

    /**
     * 公开方法：执行批量翻译查询（供外部调用）
     */
    public function flush_bulk_translation_queries()
    {
        $this->execute_bulk_translation_queries();
    }

    /**
     * 公开方法：执行批量词汇查询（供外部调用）
     */
    public function flush_bulk_term_queries()
    {
        $this->execute_bulk_term_queries();
    }

    /**
     * 公开方法：执行批量缺失翻译插入（供外部调用）
     */
    public function flush_bulk_missing_translations()
    {
        $this->execute_bulk_missing_translation_inserts();
    }

    /**
     * 清理过期缓存
     */
    public function cleanup_expired_cache()
    {
        $this->db->query(
            "DELETE FROM {$this->db->prefix}language_cache WHERE expires_at < NOW()"
        );
    }

    /**
     * 获取语言统计信息
     */
    public function get_language_stats()
    {
        $stats = [];

        foreach ($this->supported_languages as $code => $info) {
            $total_terms = $this->db->get_var(
                "SELECT COUNT(*) FROM {$this->db->prefix}language_terms"
            );

            $translated_terms = $this->db->get_var(
                "SELECT COUNT(*) FROM {$this->db->prefix}language_translations 
                 WHERE language_code = ? AND translated_text != '' AND status = 'approved'",
                [$code]
            );

            $pending_terms = $this->db->get_var(
                "SELECT COUNT(*) FROM {$this->db->prefix}language_translations 
                 WHERE language_code = ? AND (translated_text = '' OR status != 'approved')",
                [$code]
            );

            $stats[$code] = [
                'language_info' => $info,
                'total_terms' => (int) $total_terms,
                'translated_terms' => (int) $translated_terms,
                'pending_terms' => (int) $pending_terms,
                'completion_rate' => $total_terms > 0 ? round(($translated_terms / $total_terms) * 100, 2) : 0
            ];
        }

        return $stats;
    }

    /**
     * 清除翻译缓存
     */
    public function clear_translation_cache($term_id = null, $language_code = null)
    {
        // 清空内存缓存
        $this->translation_cache = [];
        self::$term_cache = [];
        self::$missing_translation_cache = [];
        self::$term_key_exists_cache = [];

        // 如果无数据库或缓存表不存在，直接返回
        if (!$this->db || !method_exists($this->db, 'table_exists') || !$this->db->table_exists('language_cache')) {
            return;
        }

        // 统一语言代码
        if ($language_code) {
            if (function_exists('canonical_locale')) {
                $language_code = canonical_locale($language_code);
            } else {
                $language_code = str_replace('_', '-', $language_code);
            }
        }

        try {
            if ($term_id && $language_code) {
                // 根据 term_id 获取原文、上下文和主题以构建缓存键
                $term = $this->db->get_row(
                    "SELECT original_text, context, theme FROM {$this->db->prefix}language_terms WHERE id = ?",
                    [$term_id]
                );
                if ($term) {
                    $ctx = $term->context ? $term->context : '';
                    $theme = $term->theme ? $term->theme : (function_exists('get_theme_name') ? get_theme_name() : 'default');
                    $cache_key_ctx = md5($term->original_text . '|' . $ctx . '|' . $language_code . '|' . $theme);
                    $cache_key_empty = md5($term->original_text . '|' . '' . '|' . $language_code . '|' . $theme);
                    // 清理两种可能的缓存键（历史不一致导致的缓存错位）
                    $this->db->delete("{$this->db->prefix}language_cache", [
                        'language_code' => $language_code,
                        'cache_key' => $cache_key_ctx
                    ]);
                    $this->db->delete("{$this->db->prefix}language_cache", [
                        'language_code' => $language_code,
                        'cache_key' => $cache_key_empty
                    ]);
                } else {
                    // 若未找到 term，降级按语言清理
                    $this->db->delete("{$this->db->prefix}language_cache", [
                        'language_code' => $language_code
                    ]);
                }
            } elseif ($language_code) {
                // 仅按语言清理
                $this->db->delete("{$this->db->prefix}language_cache", [
                    'language_code' => $language_code
                ]);
            } else {
                // 未指定参数则清空整个缓存表
                $this->db->query("DELETE FROM {$this->db->prefix}language_cache");
            }
        } catch (\Throwable $e) {
            // 记录错误但不阻断流程
            if (function_exists('error_log')) {
                error_log('TodoCMS Language: 清理翻译缓存失败 - ' . $e->getMessage());
            }
        }
    }
}

/**
 * 获取语言管理器实例
 */
if (!function_exists('todo_language_manager')) {
    /**
     * 获取语言管理器实例
     */
    function todo_language_manager()
    {
        return TodoLanguageManager::get_instance();
    }
}

/**
 * 翻译函数 - 全局快捷方式
 */
if (!function_exists('todo_translate')) {
    /**
     * 翻译函数 - 全局快捷方式
     */
    function todo_translate($text, $context = null, $language = null)
    {
        return todo_language_manager()->translate($text, $context, $language);
    }
}

/**
 * 翻译函数简写
 */
if (!function_exists('todo_t')) {
    /**
     * 翻译函数简写
     */
    function todo_t($text, $context = null, $domain = 'default')
    {
        return todo_translate($text, $context);
    }
}

/**
 * 获取当前语言
 */
if (!function_exists('todo_get_current_language')) {
    /**
     * 获取当前语言
     */
    function todo_get_current_language()
    {
        return todo_language_manager()->get_current_language();
    }
}

/**
 * 获取支持的语言列表
 */
if (!function_exists('todo_get_supported_languages')) {
    /**
     * 获取支持的语言列表
     */
    function todo_get_supported_languages()
    {
        return todo_language_manager()->get_supported_languages();
    }
}

/**
 * 生成词汇键值（包含可选主题/上下文前缀；唯一性由 (file_path, term_key, theme) 组合约束保证）
 */
if (!function_exists('todo_generate_term_key')) {
    /**
     * 生成词汇键值（包含可选主题/上下文前缀；唯一性由 (file_path, term_key, theme) 组合约束保证）
     */
    function todo_generate_term_key($text, $context = null, $theme = null)
    {
        global $tododb;

        $slug = sanitize_title($text);
        if (empty($slug) || strlen(trim($slug, '-_')) === 0) {
            $slug = 'term_' . substr(md5($text), 0, 10);
        }
        $base_key = $slug;
        if ($context) {
            $ctx_slug = sanitize_title($context) ?: substr(md5($context), 0, 6);
            $base_key = $ctx_slug . '_' . $base_key;
        }
        if ($theme) {
            $theme_slug = sanitize_title($theme) ?: 'theme';
            $base_key = $theme_slug . '_' . $base_key;
        }

        $counter = 0;
        $term_key = $base_key;

        // 如果数据库或表不可用，直接返回基础键
        if (!$tododb || !method_exists($tododb, 'table_exists') || !$tododb->table_exists('language_terms')) {
            return $term_key;
        }

        // 按 term_key 检测基础重复（允许跨文件、跨主题重复由组合唯一约束控制）
        while (
            intval($tododb->get_var(
                "SELECT COUNT(*) FROM {$tododb->prefix}language_terms WHERE term_key = ?",
                [$term_key]
            ))
        ) {
            $counter++;
            $term_key = $base_key . '_' . $counter;
        }

        return $term_key;
    }
}
