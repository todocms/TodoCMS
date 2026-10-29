<?php
/**
 * 多语言支持函数文件
 *
 * 包含多语言支持和本地化的函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 加载 LocaleContext
$locale_class = ABSPATH . 'core/classes/class-locale-context.php';
if (file_exists($locale_class)) {
    require_once $locale_class;
}

/**
 * 获取当前语言
 */
if (!function_exists('get_current_language')) {
    function get_current_language()
    {
        // 统一返回连字符格式（如 zh-CN），优先使用请求级 LocaleContext
        return apply_filters('current_language', LocaleContext::get_instance()->get_locale());
    }
}

/**
 * 设置当前语言
 *
 * @param string $locale 语言代码
 * @return bool 是否成功设置
 */
if (!function_exists('set_current_language')) {
    function set_current_language($locale)
    {
        return update_option('locale', $locale);
    }
}

/**
 * 获取可用语言列表
 */
if (!function_exists('get_available_languages')) {
    function get_available_languages()
    {
        // 使用层级排序的语言列表
        $langs = LanguageRegistry::get_instance()->get_languages_hierarchical(true);
        $out = array();
        foreach ($langs as $loc => $info) {
            $level = isset($info['_level']) ? intval($info['_level']) : 0;
            $display_title = $info['title'];
            // 如果有层级，添加层级前缀
            if ($level > 0) {
                $display_title = str_repeat('-', $level) . ' ' . $display_title;
            }
            $out[$loc] = array(
                'name' => $display_title,
                'native_name' => $display_title,
                'lang_code' => strtolower($loc),
                'flag' => isset($info['flag']) ? $info['flag'] : '',
                'default' => !empty($info['is_default']),
                '_level' => $level
            );
        }
        return apply_filters('available_languages', $out);
    }
}

/**
 * 获取语言数据
 */
if (!function_exists('get_language_data')) {
    function get_language_data($locale)
    {
        if (!is_string($locale)) {
            return false;
        }
        $locale = str_replace('_', '-', $locale);
        $info = LanguageRegistry::get_instance()->language_by_locale($locale);
        if ($info) {
            return array(
                'name' => $info['title'],
                'native_name' => $info['title'],
                'lang_code' => strtolower($info['locale']),
                'flag' => isset($info['flag']) ? $info['flag'] : '',
                'default' => !empty($info['is_default'])
            );
        }
        $languages = get_available_languages();
        return isset($languages[$locale]) ? $languages[$locale] : false;
    }
}

/**
 * 获取默认语言
 */
if (!function_exists('get_default_language')) {
    function get_default_language()
    {
        return LanguageRegistry::get_instance()->get_default_locale();
    }
}

/**
 * 翻译文本
 */
if (!function_exists('__')) {
    function __($text, $domain = 'default')
    {
        // 优先使用数据库翻译
        if (function_exists('todo_language_manager')) {
            $mgr = todo_language_manager();
            if ($mgr) {
                $translated = $mgr->translate($text, null);
                if (is_string($translated) && $translated !== $text) {
                    return $translated;
                }
            }
        }
        // 回退：主题语言包
        return CMS_Translation::translate($text, $domain);
    }
}

/**
 * 翻译文本并输出
 *
 * @param string $text 要翻译的文本
 * @param string $domain 文本域
 */
if (!function_exists('_e')) {
    function _e($text, $domain = 'default')
    {
        echo __($text, $domain);
    }
}

/**
 * 翻译文本并进行esc_html处理
 *
 * @param string $text 要翻译的文本
 * @param string $domain 文本域
 * @return string 翻译并HTML转义后的文本
 */
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default')
    {
        return esc_html(__($text, $domain));
    }
}

/**
 * 翻译文本，进行esc_html处理并输出
 *
 * @param string $text 要翻译的文本
 * @param string $domain 文本域
 */
if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = 'default')
    {
        echo esc_html__($text, $domain);
    }
}

/**
 * 翻译文本并进行esc_attr处理
 *
 * @param string $text 要翻译的文本
 * @param string $domain 文本域
 * @return string 翻译并属性转义后的文本
 */
if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = 'default')
    {
        return esc_attr(__($text, $domain));
    }
}

/**
 * 翻译文本，进行esc_attr处理并输出
 *
 * @param string $text 要翻译的文本
 * @param string $domain 文本域
 */
if (!function_exists('esc_attr_e')) {
    function esc_attr_e($text, $domain = 'default')
    {
        echo esc_attr__($text, $domain);
    }
}

/**
 * 翻译复数形式文本
 *
 * @param string $single 单数形式文本
 * @param string $plural 复数形式文本
 * @param int $number 数量
 * @param string $domain 文本域
 * @return string 翻译后的文本
 */
if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = 'default')
    {
        return CMS_Translation::translate_plural($single, $plural, $number, $domain);
    }
}

/**
 * 带上下文的翻译文本
 *
 * @param string $text 要翻译的文本
 * @param string $context 上下文
 * @param string $domain 文本域
 * @return string 翻译后的文本
 */
if (!function_exists('_x')) {
    function _x($text, $context, $domain = 'default')
    {
        // 优先使用数据库翻译，支持上下文
        if (function_exists('todo_language_manager')) {
            $mgr = todo_language_manager();
            if ($mgr) {
                $translated = $mgr->translate($text, $context);
                if (is_string($translated) && $translated !== $text) {
                    return $translated;
                }
            }
        }
        // 回退到文件型语言包的上下文翻译
        return CMS_Translation::translate_with_context($text, $context, $domain);
    }
}

// 兼容新方案的上下文翻译包装：HTML 与属性转义
if (!function_exists('esc_html_x')) {
    function esc_html_x($text, $context, $domain = 'default')
    {
        return esc_html(_x($text, $context, $domain));
    }
}

// 属性上下文翻译并转义（避免模板中缺少该函数导致报错）
if (!function_exists('esc_attr_x')) {
    function esc_attr_x($text, $context, $domain = 'default')
    {
        return esc_attr(_x($text, $context, $domain));
    }
}

/**
 * 根据上下文翻译文本并输出
 *
 * @param string $context 上下文
 * @param string $text 要翻译的文本
 * @param string $domain 文本域
 */
if (!function_exists('_ex')) {
    function _ex($text, $context, $domain = 'default')
    {
        echo _x($text, $context, $domain);
    }
}

/**
 * 加载文本域
 *
 * @param string $domain 文本域
 * @param string $path 语言文件路径
 * @return bool 是否成功加载
 */
if (!function_exists('load_textdomain')) {
    function load_textdomain($domain, $path)
    {
        return CMS_Translation::load_textdomain($domain, $path);
    }
}

/**
 * 加载默认文本域
 *
 * @param string $path 语言文件路径
 * @return bool 是否成功加载
 */
if (!function_exists('load_default_textdomain')) {
    function load_default_textdomain($path)
    {
        return load_textdomain('default', $path);
    }
}

/**
 * 加载主题文本域
 *
 * @param string $domain 文本域
 * @param string $theme_dir 主题目录
 * @return bool 是否成功加载
 */
if (!function_exists('load_theme_textdomain')) {
    function load_theme_textdomain($domain, $theme_dir)
    {
        $locale = current_locale();
        $path = $theme_dir . '/languages/' . $domain . '-' . $locale . '.mo';
        return load_textdomain($domain, $path);
    }
}

/**
 * 获取语言HTML属性
 *
 * @return string HTML语言属性
 */
if (!function_exists('language_attributes')) {
    function language_attributes()
    {
        $locale = current_locale();
        // 统一语言码：连字符+区域大写（如 en-US、zh-CN）
        if (function_exists('canonical_locale')) {
            $locale = canonical_locale($locale);
        } else {
            // 基础兜底：下划线改连字符并区域大写
            $locale = str_replace('_', '-', $locale);
            if (strpos($locale, '-') !== false) {
                list($l, $r) = explode('-', $locale, 2);
                $locale = strtolower($l) . '-' . strtoupper($r);
            } else {
                $locale = strtolower($locale);
            }
        }

        // 简易方向检测（常见 RTL 语言）
        $rtl_langs = array('ar', 'he', 'fa', 'ur');
        $dir = in_array(substr($locale, 0, 2), $rtl_langs, true) ? 'rtl' : 'ltr';

        $attributes = 'lang="' . esc_attr($locale) . '" dir="' . $dir . '"';
        echo apply_filters('language_attributes', $attributes);
    }
}

/**
 * 根据本地化设置格式化日期
 *
 * @param string $format 日期格式
 * @param int|string $timestamp 时间戳
 * @param bool $gmt 是否使用GMT时间
 * @return string 格式化后的日期
 */
if (!function_exists('date_i18n')) {
    function date_i18n($format, $timestamp = null, $gmt = false)
    {
        if (null === $timestamp) {
            $timestamp = time();
        } elseif (!is_numeric($timestamp)) {
            $timestamp = strtotime($timestamp);
        }
        if ($gmt) {
            $timestamp += get_option('gmt_offset', 0) * HOUR_IN_SECONDS;
        }
        // 简单实现，实际应根据语言进行更复杂的处理
        $locale = current_locale();
        $date = date($format, $timestamp);
        // 可按 $locale 定义不同格式化规则
        return apply_filters('date_i18n', $date, $format, $timestamp, $gmt);
    }
}

/**
 * 根据本地化设置格式化数字
 *
 * @param float $number 要格式化的数字
 * @param int $decimals 小数位数
 * @return string 格式化后的数字
 */
if (!function_exists('number_format_i18n')) {
    function number_format_i18n($number, $decimals = 0)
    {
        $locale = current_locale();

        // 根据不同语言设置不同的千位分隔符和小数点（新方案：连字符格式）
        $thousands_sep = ',';
        $decimal_point = '.';

        if (strpos($locale, 'fr') === 0 || strpos($locale, 'ru') === 0) {
            $thousands_sep = ' ';
            $decimal_point = ',';
        } elseif (strpos($locale, 'de') === 0 || strpos($locale, 'es') === 0) {
            $thousands_sep = '.';
            $decimal_point = ',';
        }

        $formatted = number_format($number, (int) $decimals, $decimal_point, $thousands_sep);
        return apply_filters('number_format_i18n', $formatted, $number, (int) $decimals, $decimal_point, $thousands_sep);
    }
}
if (!function_exists('current_locale')) {
    function current_locale()
    {
        // 检查是否有手动设置的全局语言变量（用于缓存生成等场景）
        if (isset($GLOBALS['_todo_current_locale']) && !empty($GLOBALS['_todo_current_locale'])) {
            return $GLOBALS['_todo_current_locale'];
        }

        if (class_exists('LocaleContext')) {
            return LocaleContext::get_instance()->get_locale();
        }
        $legacy = get_option('locale', 'zh-CN');
        return str_replace('_', '-', $legacy);
    }
}

// 统一语言代码格式：使用连字符并将区域大写，如 zh-CN、en-US
if (!function_exists('canonical_locale')) {
    function canonical_locale($locale)
    {
        static $recursing = false;
        static $cache = [];

        // 基础处理
        $locale = is_string($locale) ? trim($locale) : '';

        // 缓存命中
        if (isset($cache[$locale])) {
            return $cache[$locale];
        }

        // 规范化辅助逻辑（无需外部依赖）
        $basic_normalize = function($loc) {
            if ($loc === '') return 'zh-CN';
            $l = str_replace('_', '-', $loc);
            $parts = explode('-', strtolower($l));
            if (count($parts) >= 2) {
                $parts[1] = strtoupper($parts[1]);
                return $parts[0] . '-' . $parts[1];
            }
            // 特殊处理常见缩写
            if ($parts[0] === 'en') return 'en-US';
            if ($parts[0] === 'zh') return 'zh-CN';
            return $parts[0];
        };

        // 递归保护
        if ($recursing) {
            return $basic_normalize($locale);
        }

        $recursing = true;

        try {
            if ($locale === '') {
                $res = function_exists('get_default_language') ? get_default_language() : 'zh-CN';
                $cache[''] = $res;
                return $res;
            }

            // 转换下划线为连字符
            $l = str_replace('_', '-', $locale);
            $parts = explode('-', strtolower($l));

            if (count($parts) === 1) {
                // 仅语言代码（如 en、zh）→ 优先映射到已注册语言中第一个匹配项
                if (class_exists('LanguageRegistry')) {
                    $registry = LanguageRegistry::get_instance();
                    $langs = $registry->get_languages(true);
                    foreach ($langs as $loc => $info) {
                        if (strpos($loc, $parts[0] . '-') === 0) {
                            $cache[$locale] = $loc;
                            return $loc;
                        }
                    }
                }
                // 特殊处理常见缩写（当注册中心未加载或无匹配时）
                if ($parts[0] === 'en') return 'en-US';
                if ($parts[0] === 'zh') return 'zh-CN';

                // 回退：返回默认语言
                $def = function_exists('get_default_language') ? get_default_language() : 'zh-CN';
                $cache[$locale] = $def;
                return $def;
            }

            // 规范区域段为大写
            $parts[1] = strtoupper($parts[1]);
            $canon = $parts[0] . '-' . $parts[1];

            $cache[$locale] = $canon;
            return $canon;
        } catch (Exception $e) {
            // 出错时返回基本规范化结果
            return $basic_normalize($locale);
        } finally {
            $recursing = false;
        }
    }
}

// 新增：校验语言是否合法（注册中心 + 正则 + 白名单）
if (!function_exists('is_valid_language')) {
    function is_valid_language($locale)
    {
        if (class_exists('LanguageRegistry')) {
            $reg = LanguageRegistry::get_instance();
            if ($reg->is_supported_locale($locale, true)) {
                return true;
            }
        }
        if (class_exists('TodoLanguageManager')) {
            $supported = TodoLanguageManager::get_instance()->get_supported_languages();
            return isset($supported[$locale]);
        }
        return false;
    }
}

// 新增：生成指定语言的 URL（支持路径前缀或域名映射模式）
if (!function_exists('get_language_url')) {
    function get_language_url($target_locale, $current_url = null)
    {
        // 归一化与校验
        $target = canonical_locale($target_locale);
        if (!is_valid_language($target)) {
            // 非法语言统一回退到默认语言 URL
            $target = get_default_language();
        }
        $reg = class_exists('LanguageRegistry') ? LanguageRegistry::get_instance() : null;
        $mode = $reg ? $reg->get_routing_mode() : 'path_prefix';

        // 当前 URL
        if (!is_string($current_url) || $current_url === '') {
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            $current_url = $scheme . '://' . $host . $uri;
        }

        $parts = parse_url($current_url);
        // Fix for non-ASCII URLs: PHP 7.x parse_url() corrupts bytes > 0x7F
        // Re-parse with percent-encoded path if corruption is detected
        if (isset($parts['path']) && !mb_check_encoding($parts['path'], 'UTF-8')) {
            $safe_url = preg_replace_callback('#[^\x20-\x7E]#u', function ($m) {
                return rawurlencode($m[0]);
            }, $current_url);
            $parts = parse_url($safe_url);
            if (isset($parts['path'])) {
                $parts['path'] = rawurldecode($parts['path']);
            }
            if (isset($parts['query'])) {
                $parts['query'] = rawurldecode($parts['query']);
            }
        }
        $scheme = isset($parts['scheme']) ? $parts['scheme'] : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http');
        $host = isset($parts['host']) ? $parts['host'] : ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $path = isset($parts['path']) ? $parts['path'] : '/';
        $query = isset($parts['query']) ? $parts['query'] : '';
        $port = isset($parts['port']) ? $parts['port'] : null;
        $fragRaw = isset($parts['fragment']) ? $parts['fragment'] : '';

        // 计算安装子目录段（例如 /todocms → ['todocms']）
        $install_segments = (function () {
            $segments = array();
            if (function_exists('todo_get_site_url')) {
                $site_path = parse_url(todo_get_site_url(), PHP_URL_PATH);
                if ($site_path) {
                    $site_segs = array_values(array_filter(explode('/', trim($site_path, '/')), function ($s) {
                        return $s !== '';
                    }));
                    if (!empty($site_segs)) {
                        return $site_segs;
                    }
                }
            }
            $doc = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/')) : '';
            $abs = defined('ABSPATH') ? str_replace('\\', '/', rtrim(ABSPATH, '/')) : '';
            if ($doc && $abs && strpos($abs, $doc) === 0) {
                $rel = trim(substr($abs, strlen($doc)), '/');
                if ($rel !== '') {
                    $segments = array_values(array_filter(explode('/', $rel), function ($s) {
                        return $s !== ''; }));
                }
            } else {
                $dir = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])) : '';
                $dir = trim($dir, '/');
                if ($dir !== '') {
                    $segments = array_values(array_filter(explode('/', $dir), function ($s) {
                        return $s !== '' && strtolower($s) !== 'admin'; }));
                }
            }
            return $segments;
        })();

        // 工具：移除任何已知的语言前缀（考虑安装子目录偏移）
        $strip_prefix = function ($p) use ($reg, $install_segments) {
            $segments = array_values(array_filter(explode('/', $p), function ($seg) {
                return $seg !== '';
            }));
            $offset = 0;
            if (!empty($install_segments)) {
                $len = count($install_segments);
                if (count($segments) >= $len && array_slice($segments, 0, $len) === $install_segments) {
                    $offset = $len;
                }
            }
            if ($reg) {
                $known = array_keys($reg->path_prefix_map());
                if (isset($segments[$offset]) && in_array($segments[$offset], $known, true)) {
                    array_splice($segments, $offset, 1);
                }
            }
            $np = '/' . implode('/', $segments);
            if ($np === '/') {
                return '/';
            }
            // 保留尾部斜杠
            if (substr($p, -1) === '/' && substr($np, -1) !== '/') {
                $np .= '/';
            }
            return $np;
        };

        if ($mode === 'path_prefix') {
            // 目标前缀
            $info = $reg ? $reg->language_by_locale($target) : null;
            $prefix = ($info && isset($info['path_prefix'])) ? trim($info['path_prefix']) : '';
            $basePath = $strip_prefix($path);

            // 过滤掉不应该带有语言前缀的系统文件/静态资源
            // 如果生成的 URL 指向这类资源，说明当前正处于该资源的 404 处理上下文中。
            // 此时语言切换应回退到首页，而非产生错误链接。
            if (preg_match('/\.(ico|png|jpg|jpeg|gif|css|js|xml|txt)$/i', $basePath) || strpos($basePath, '/public/') !== false) {
                $basePath = '/';
                if (!empty($install_segments)) {
                    $basePath = '/' . implode('/', $install_segments) . '/';
                }
            }
            // 组装路径：默认语言不加前缀，其它语言插入在安装子目录之后
            if ($prefix === '' || $prefix === null) {
                $newPath = $basePath;
            } else {
                $baseSegments = array_values(array_filter(explode('/', trim($basePath, '/')), function ($s) {
                    return $s !== '';
                }));
                $installLen = count($install_segments);
                $installPart = $installLen > 0 ? array_slice($baseSegments, 0, $installLen) : array();
                $restPart = array_slice($baseSegments, $installLen);
                $indexPos = array_search('index.php', $restPart, true);
                if ($indexPos !== false) {
                    $beforeIndex = array_slice($restPart, 0, $indexPos + 1); // 包含 index.php
                    $afterIndex = array_slice($restPart, $indexPos + 1);
                    $newSegments = array_merge($installPart, $beforeIndex, array($prefix), $afterIndex);
                } else {
                    $newSegments = array_merge($installPart, array($prefix), $restPart);
                }
                $newPath = '/' . implode('/', $newSegments);
                // 去重斜杠
                $newPath = preg_replace('#//+#', '/', $newPath);
                // 保留尾部斜杠（当仅有语言前缀/子目录首页时，不追加多余的尾部斜杠，保持如 /en 或 /todocms/en 标准格式）
                if (empty($restPart)) {
                    $newPath = rtrim($newPath, '/');
                } else if (substr($basePath, -1) === '/' && substr($newPath, -1) !== '/') {
                    $newPath .= '/';
                }
                // 若包含 index.php 且无尾部斜杠，追加以支持 PATH_INFO
                if (strpos($newPath, '/index.php') !== false && substr($newPath, -1) !== '/') {
                    $newPath .= '/';
                }
            }
            $newParts = array('scheme' => $scheme, 'host' => $host, 'path' => $newPath);
            if (!is_null($port)) {
                $newParts['port'] = $port;
            }
            if ($query !== '') {
                $newParts['query'] = $query;
            }
            if ($fragRaw !== '') {
                $newParts['fragment'] = $fragRaw;
            }
            return build_url($newParts);
        } else {
            // 域名模式：切换 host，路径不使用任何语言前缀
            $info = $reg ? $reg->language_by_locale($target) : null;
            $targetHost = ($info && !empty($info['domain'])) ? $info['domain'] : $host;
            $cleanPath = $strip_prefix($path);
            $newParts = array('scheme' => $scheme, 'host' => $targetHost, 'path' => $cleanPath);
            if ($targetHost === $host && !is_null($port)) {
                $newParts['port'] = $port;
            }
            if ($query !== '') {
                $newParts['query'] = $query;
            }
            if ($fragRaw !== '') {
                $newParts['fragment'] = $fragRaw;
            }
            return build_url($newParts);
        }
    }
}

// 辅助：URL 构建、添加/移除查询参数
if (!function_exists('build_url')) {
    function build_url($parts)
    {
        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = isset($parts['host']) ? $parts['host'] : '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $user = isset($parts['user']) ? $parts['user'] : '';
        $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $pass = ($user || $pass) ? "$pass@" : '';
        $path = isset($parts['path']) ? $parts['path'] : '';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        return "$scheme$user$pass$host$port$path$query$fragment";
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg($key, $value = null, $url = null)
    {
        // 支持批量添加参数：当$key为数组时
        if (is_array($key)) {
            $url = $value;
            $params_to_add = $key;
        } else {
            $params_to_add = array($key => $value);
        }

        if (is_null($url)) {
            $url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        }

        $url_parts = parse_url($url);
        $params = array();

        if (isset($url_parts['query'])) {
            parse_str($url_parts['query'], $params);
        }

        foreach ($params_to_add as $param_key => $param_value) {
            if (is_string($param_key) || is_int($param_key)) {
                $params[$param_key] = $param_value;
            }
        }

        $url_parts['query'] = http_build_query($params);
        return build_url($url_parts);
    }
}

if (!function_exists('remove_query_arg')) {
    function remove_query_arg($key, $url = null)
    {
        if (is_null($url)) {
            $url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        }

        $url_parts = parse_url($url);
        $params = array();

        if (isset($url_parts['query'])) {
            parse_str($url_parts['query'], $params);
            unset($params[$key]);
            $url_parts['query'] = http_build_query($params);
        }
        return build_url($url_parts);
    }
}

// 生成 hreflang 链接集合（前端 SEO 使用）
function todo_hreflang_links($current_url = null, $return_array = false)
{
    $registry = class_exists('LanguageRegistry') ? LanguageRegistry::get_instance() : null;
    $langs = $registry ? $registry->get_languages(true) : get_available_languages();
    $links = array();
    $current_url = $current_url ?: (function_exists('get_current_url') ? get_current_url() : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/')));
    foreach ($langs as $loc => $info) {
        $href = get_language_url($loc, $current_url);
        $links[] = sprintf('<link rel="alternate" hreflang="%s" href="%s">', $loc, htmlspecialchars($href, ENT_QUOTES, 'UTF-8'));
    }
    $default = get_default_language();
    $hrefDefault = get_language_url($default, $current_url);
    $links[] = sprintf('<link rel="alternate" hreflang="x-default" href="%s">', htmlspecialchars($hrefDefault, ENT_QUOTES, 'UTF-8'));
    if ($return_array) {
        return $links;
    }
    echo implode("\n", $links);
}

// 生成 canonical 链接（按当前语言）
function todo_canonical_link($current_url = null)
{
    $current_url = $current_url ?: (function_exists('get_current_url') ? get_current_url() : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/')));
    $canon = get_language_url(current_locale(), $current_url);
    echo sprintf('<link rel="canonical" href="%s">', htmlspecialchars($canon, ENT_QUOTES, 'UTF-8'));
}

// 主题模板调用别名
function language_hreflang_links($current_url = null, $return_array = false)
{
    return todo_hreflang_links($current_url, $return_array);
}
function language_canonical_link($current_url = null)
{
    return todo_canonical_link($current_url);
}

/**
 * =============================================================================
 * 语言管理功能（同步配置文件与数据库）
 * =============================================================================
 */

/**
 * 读取语言配置文件
 */
if (!function_exists('todo_read_languages_config')) {
    function todo_read_languages_config()
    {
        $config_path = ABSPATH . 'core/config/languages.php';
        $cfg = [];
        if (file_exists($config_path)) {
            $cfg = include $config_path;
        }
        if (!is_array($cfg)) {
            $cfg = [];
        }
        return [$cfg, $config_path];
    }
}

/**
 * 写入语言配置文件
 */
if (!function_exists('todo_write_languages_config')) {
    function todo_write_languages_config($cfg, $config_path)
    {
        if (!is_array($cfg) || empty($config_path)) {
            return false;
        }
        $export = var_export($cfg, true);
        $php = "<?php\n/**\n * TodoCMS 语言注册中心\n * 自动生成于 " . date('c') . "\n */\nreturn " . $export . ";";
        $ok = (bool) file_put_contents($config_path, $php);
        if ($ok) {
            clearstatcache(true, $config_path);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($config_path, true);
            }
        }
        return $ok;
    }
}

/**
 * 更新数据库中的语言信息
 */
if (!function_exists('todo_update_language_in_db')) {
    function todo_update_language_in_db($locale, $data)
    {
        global $tododb;
        if (!$tododb)
            return false;

        $table = $tododb->prefix . 'languages';
        $loc_raw = is_string($locale) ? trim($locale) : '';
        $loc_hyphen = str_replace('_', '-', $loc_raw);
        if (function_exists('canonical_locale')) {
            $loc_hyphen = canonical_locale($loc_hyphen);
        }
        $loc_underscore = str_replace('-', '_', $loc_hyphen);
        $codes = array_values(array_unique(array_filter([$loc_raw, $loc_hyphen, $loc_underscore])));
        if (empty($codes)) {
            return false;
        }
        $in_placeholders = implode(',', array_fill(0, count($codes), '?'));
        $existing_code = $tododb->get_var("SELECT code FROM {$table} WHERE code IN ({$in_placeholders}) LIMIT 1", $codes);
        $exists = !empty($existing_code);

        $db_data = [];
        if (isset($data['enabled'])) {
            $db_data['status'] = $data['enabled'] ? 'active' : 'inactive';
        }
        if (isset($data['title'])) {
            $db_data['name'] = $data['title'];
            $db_data['native_name'] = $data['title'];
        }
        if (isset($data['is_default'])) {
            $db_data['is_default'] = $data['is_default'] ? 1 : 0;
            // 如果设置为默认，其它语言取消默认
            if ($db_data['is_default']) {
                $tododb->update($table, ['is_default' => 0], ['is_default' => 1]);
            }
        }
        if (isset($data['sort_order'])) {
            $db_data['sort_order'] = $data['sort_order'];
        }
        if (isset($data['flag'])) {
            $db_data['flag'] = $data['flag'];
        }
        if (isset($data['currency_code'])) {
            $db_data['currency_code'] = $data['currency_code'];
        }
        if (isset($data['currency_exchange_rate'])) {
            $db_data['currency_exchange_rate'] = floatval($data['currency_exchange_rate']);
        }
        if (isset($data['currency_status'])) {
            $db_data['currency_status'] = intval($data['currency_status']);
        }
        if (isset($data['is_default_currency'])) {
            $db_data['is_default_currency'] = $data['is_default_currency'] ? 1 : 0;
            if ($db_data['is_default_currency']) {
                $tododb->update($table, ['is_default_currency' => 0], ['is_default_currency' => 1]);
            }
        }

        if (empty($db_data))
            return true;

        if ($exists) {
            return $tododb->update($table, $db_data, ['code' => $existing_code]);
        } else {
            // 组装完整插入数据
            $display_title = '';
            $native_title = '';
            if (function_exists('todo_read_languages_config')) {
                list($cfg, $path) = todo_read_languages_config();
                if (isset($cfg['languages']) && is_array($cfg['languages'])) {
                    foreach ($cfg['languages'] as $lang) {
                        if (!is_array($lang)) continue;
                        $cfg_loc = isset($lang['locale']) ? str_replace('_', '-', (string) $lang['locale']) : '';
                        if (function_exists('canonical_locale')) {
                            $cfg_loc = canonical_locale($cfg_loc);
                        }
                        if ($cfg_loc !== '' && strtolower($cfg_loc) === strtolower($loc_hyphen)) {
                            $display_title = isset($lang['title']) ? (string) $lang['title'] : '';
                            $native_title = isset($lang['native_name']) ? (string) $lang['native_name'] : '';
                            break;
                        }
                    }
                }
            }
            if (!isset($db_data['name']) || (string) $db_data['name'] === '') {
                $db_data['name'] = $display_title !== '' ? $display_title : ($loc_hyphen !== '' ? $loc_hyphen : $loc_raw);
            }
            if (!isset($db_data['native_name']) || (string) $db_data['native_name'] === '') {
                $db_data['native_name'] = $native_title !== '' ? $native_title : (string) $db_data['name'];
            }
            if (!isset($db_data['status'])) {
                $db_data['status'] = 'active';
            }
            if (!isset($db_data['is_default'])) {
                $db_data['is_default'] = 0;
            }
            if (!isset($db_data['sort_order'])) {
                $db_data['sort_order'] = 0;
            }
            if (!isset($db_data['direction'])) {
                $db_data['direction'] = 'ltr';
            }
            $insert_code = $loc_hyphen !== '' ? $loc_hyphen : $loc_raw;
            $db_data['code'] = $insert_code;

            $quoted_table = $table;
            if (is_string($quoted_table) && strpos($quoted_table, '`') === false) {
                $t = trim($quoted_table);
                if ($t !== '' && preg_match('/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)?$/', $t)) {
                    $parts = explode('.', $t);
                    $parts = array_map(function ($p) {
                        $p = str_replace('`', '', (string) $p);
                        return '`' . $p . '`';
                    }, $parts);
                    $quoted_table = implode('.', $parts);
                }
            }

            $fields = array_keys($db_data);
            $placeholders = array_fill(0, count($fields), '?');
            $quoted_fields = array_map(function ($f) {
                $f = str_replace('`', '', (string) $f);
                return '`' . $f . '`';
            }, $fields);

            $update_clauses = [];
            foreach ($fields as $f) {
                if ($f === 'code') {
                    continue;
                }
                $clean = str_replace('`', '', (string) $f);
                $qf = '`' . $clean . '`';
                $update_clauses[] = "{$qf} = VALUES({$qf})";
            }

            $sql = "INSERT INTO {$quoted_table} (" . implode(', ', $quoted_fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            if (!empty($update_clauses)) {
                $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $update_clauses);
            }

            $values = array_values($db_data);
            $res = $tododb->query($sql, $values);
            return $res !== false ? $res : false;
        }

    }
}

/**
 * 更新语言启用状态
 */
if (!function_exists('todo_update_language_enabled')) {
    function todo_update_language_enabled($locale, $enabled)
    {
        list($cfg, $path) = todo_read_languages_config();
        if (!isset($cfg['languages']) || !is_array($cfg['languages'])) {
            return false;
        }
        $locLower = strtolower($locale);
        $found = false;
        foreach ($cfg['languages'] as &$lang) {
            if (isset($lang['locale']) && strtolower($lang['locale']) === $locLower) {
                if (!empty($lang['is_default']) && !$enabled) {
                    $enabled = 1;
                }
                $lang['enabled'] = (bool) $enabled;
                $found = true;
                break;
            }
        }
        unset($lang);
        if (!$found)
            return false;

        $ok = todo_write_languages_config($cfg, $path);
        if ($ok) {
            todo_update_language_in_db($locale, ['enabled' => $enabled]);
        }
        return $ok;
    }
}

/**
 * 更新货币设置
 */
if (!function_exists('todo_update_language_currency')) {
    function todo_update_language_currency($locale, $data)
    {
        list($cfg, $path) = todo_read_languages_config();
        if (!isset($cfg['languages']) || !is_array($cfg['languages'])) {
            return false;
        }
        $locLower = strtolower($locale);
        $found = false;
        foreach ($cfg['languages'] as &$lang) {
            if (isset($lang['locale']) && strtolower($lang['locale']) === $locLower) {
                $lang['currency_code'] = $data['currency_code'] ?? '';
                $lang['currency_exchange_rate'] = floatval($data['currency_exchange_rate'] ?? 1);
                $lang['currency_status'] = intval($data['currency_status'] ?? 1);
                $lang['is_default_currency'] = !empty($data['is_default_currency']) ? 1 : 0;
                $found = true;
            } elseif (!empty($data['is_default_currency'])) {
                // 如果当前设置了默认货币，则取消其他语言的默认货币
                $lang['is_default_currency'] = 0;
            }
        }
        unset($lang);
        if (!$found)
            return false;

        $ok = todo_write_languages_config($cfg, $path);
        if ($ok) {
            todo_update_language_in_db($locale, $data);
        }
        return $ok;
    }
}

/**
 * 更新语言-站点关联设置
 *
 * @param string $locale  语言代码
 * @param array  $data    包含 site_ids (int[]) 和 default_site_id (int|null)
 * @return bool
 */
if (!function_exists('todo_update_language_site_association')) {
    function todo_update_language_site_association($locale, $data)
    {
        list($cfg, $path) = todo_read_languages_config();
        if (!isset($cfg['languages']) || !is_array($cfg['languages'])) {
            return false;
        }
        $locLower = strtolower($locale);
        $found = false;
        // 站点ID列表，确保是整数数组
        $site_ids = array();
        if (!empty($data['site_ids']) && is_array($data['site_ids'])) {
            foreach ($data['site_ids'] as $sid) {
                $sid = intval($sid);
                if ($sid > 0) {
                    $site_ids[] = $sid;
                }
            }
        }
        $site_ids = array_values(array_unique($site_ids));
        $default_site_id = isset($data['default_site_id']) ? intval($data['default_site_id']) : 0;

        // 验证 default_site_id 必须在 site_ids 中
        if ($default_site_id > 0 && !in_array($default_site_id, $site_ids, true)) {
            $default_site_id = !empty($site_ids) ? $site_ids[0] : 0;
        }

        foreach ($cfg['languages'] as &$lang) {
            if (isset($lang['locale']) && strtolower($lang['locale']) === $locLower) {
                $lang['site_ids'] = $site_ids;
                $lang['default_site_id'] = $default_site_id;
                $found = true;
            }
        }
        unset($lang);
        if (!$found) {
            return false;
        }

        $ok = todo_write_languages_config($cfg, $path);
        return $ok;
    }
}

/**
 * 获取指定语言关联的站点列表
 *
 * @param string $locale 语言代码
 * @return array 关联的站点信息数组 [['id'=>1,'name'=>'站点名','slug'=>'main'], ...]
 */
if (!function_exists('todo_get_language_sites')) {
    function todo_get_language_sites($locale)
    {
        $registry = LanguageRegistry::get_instance();
        $registry->init();
        $languages = $registry->get_languages(false);
        $locLower = strtolower($locale);
        $site_ids = array();

        foreach ($languages as $code => $info) {
            if (strtolower($code) === $locLower || strtolower($info['locale'] ?? '') === $locLower) {
                $site_ids = isset($info['site_ids']) && is_array($info['site_ids']) ? $info['site_ids'] : array();
                break;
            }
        }

        if (empty($site_ids)) {
            // 业务需求：不再回退返回所有站点，直接返回空数组
            return array();
        }

        global $tododb;
        if (!$tododb || !$tododb->table_exists('sites')) {
            return array();
        }

        $ids_escaped = array_map('intval', $site_ids);
        $placeholders = implode(',', $ids_escaped);
        $table = $tododb->prefix . 'sites';
        $rows = $tododb->get_results(
            "SELECT id, name, slug, is_default FROM {$table} WHERE id IN ({$placeholders}) AND status = 'active' ORDER BY id ASC"
        );

        $result = array();
        foreach ((array) $rows as $s) {
            $result[] = array(
                'id' => (int) $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'is_default' => (bool) $s->is_default,
            );
        }
        return $result;
    }
}

/**
 * 获取当前语言下的默认站点ID
 *
 * @param string $locale 语言代码（可选，默认当前语言）
 * @return int 默认站点ID，若无则返回0（全局）
 */
if (!function_exists('todo_get_language_default_site_id')) {
    function todo_get_language_default_site_id($locale = null)
    {
        if ($locale === null) {
            $locale = function_exists('current_locale') ? current_locale() : 'zh-CN';
        }
        $registry = LanguageRegistry::get_instance();
        $registry->init();
        $languages = $registry->get_languages(false);
        $normalized = method_exists($registry, 'normalize_locale') ? $registry->normalize_locale($locale) : str_replace('_', '-', (string) $locale);
        if ($normalized === '' && method_exists($registry, 'locale_for_mark')) {
            $normalized = $registry->locale_for_mark($locale) ?: $normalized;
        }
        $locLower = strtolower($normalized !== '' ? $normalized : (string) $locale);
        $default_site_id = 0;

        foreach ($languages as $code => $info) {
            if (strtolower($code) === $locLower || strtolower($info['locale'] ?? '') === $locLower) {
                $default_site_id = isset($info['default_site_id']) ? intval($info['default_site_id']) : 0;
                if ($default_site_id <= 0) {
                    $site_ids = isset($info['site_ids']) && is_array($info['site_ids']) ? array_values(array_unique(array_filter(array_map('intval', $info['site_ids']), function ($v) { return $v > 0; }))) : [];
                    if (count($site_ids) === 1) {
                        $default_site_id = (int) $site_ids[0];
                    }
                }
                break;
            }
        }

        return $default_site_id;
    }
}

/**
 * 设置默认语言
 */
if (!function_exists('todo_set_default_locale')) {
    function todo_set_default_locale($locale)
    {
        list($cfg, $path) = todo_read_languages_config();
        if (!isset($cfg['languages']) || !is_array($cfg['languages'])) {
            return false;
        }
        $locLower = strtolower($locale);
        // 收集已占用的前缀，用于为原默认语言分配前缀时避免冲突
        $usedPrefixes = [];
        foreach ($cfg['languages'] as $lang) {
            $pp = isset($lang['path_prefix']) ? trim((string) $lang['path_prefix']) : '';
            if ($pp !== '' && !(isset($lang['locale']) && strtolower($lang['locale']) === $locLower)) {
                $usedPrefixes[strtolower($pp)] = true;
            }
        }
        foreach ($cfg['languages'] as &$lang) {
            $isDefault = (isset($lang['locale']) && strtolower($lang['locale']) === $locLower);
            $lang['is_default'] = $isDefault ? true : false;
            if ($isDefault) {
                $lang['enabled'] = true;
                // 约定：默认语言无路径前缀（根路径即默认语言）
                $lang['path_prefix'] = '';
            } else {
                // 非默认语言必须有前缀，否则会与根路径（默认语言）冲突
                $pp = isset($lang['path_prefix']) ? trim((string) $lang['path_prefix']) : '';
                if ($pp === '') {
                    $candidate = isset($lang['mark']) ? trim((string) $lang['mark']) : '';
                    if ($candidate === '' || isset($usedPrefixes[strtolower($candidate)])) {
                        $candidate = isset($lang['locale']) ? trim((string) $lang['locale']) : '';
                    }
                    if ($candidate !== '' && !isset($usedPrefixes[strtolower($candidate)])) {
                        $lang['path_prefix'] = $candidate;
                        $usedPrefixes[strtolower($candidate)] = true;
                    }
                }
            }
        }
        unset($lang);
        $ok = todo_write_languages_config($cfg, $path);
        if ($ok) {
            todo_update_language_in_db($locale, ['is_default' => true, 'enabled' => true]);
            // 默认语言变更影响全站 URL 生成，需同步清除页面缓存
            if (function_exists('todo_clear_language_url_caches')) {
                todo_clear_language_url_caches();
            }
        }
        return $ok;
    }
}

/**
 * 清除受语言 URL 规则影响的缓存（页面 HTML 缓存）
 * 在默认语言、路径前缀、域名映射等变更后调用，避免前台输出过期链接
 */
if (!function_exists('todo_clear_language_url_caches')) {
    function todo_clear_language_url_caches()
    {
        if (class_exists('Todo_Unified_Cache_Manager')) {
            Todo_Unified_Cache_Manager::clear_cache('template_html');
            return;
        }
        if (function_exists('clear_template_html_cache')) {
            clear_template_html_cache();
        }
    }
}

/**
 * 设置路由模式
 */
if (!function_exists('todo_set_routing_mode')) {
    function todo_set_routing_mode($mode)
    {
        if (!in_array($mode, ['path_prefix', 'domain'], true)) {
            return false;
        }
        list($cfg, $path) = todo_read_languages_config();
        if (!isset($cfg['routing']) || !is_array($cfg['routing'])) {
            $cfg['routing'] = [];
        }
        $cfg['routing']['mode'] = $mode;
        return todo_write_languages_config($cfg, $path);
    }
}

/**
 * 设置回退语言
 */
if (!function_exists('todo_set_fallback_locale')) {
    function todo_set_fallback_locale($locale)
    {
        list($cfg, $path) = todo_read_languages_config();
        if (!isset($cfg['languages']) || !is_array($cfg['languages'])) {
            return false;
        }
        $locNorm = str_replace('_', '-', trim($locale));
        $exists = false;
        foreach ($cfg['languages'] as $lang) {
            if (isset($lang['locale']) && strtolower($lang['locale']) === strtolower($locNorm)) {
                $exists = true;
                break;
            }
        }
        if (!$exists)
            return false;
        $cfg['fallback_locale'] = $locNorm;
        return todo_write_languages_config($cfg, $path);
    }
}

/**
 * 新增语言
 */
if (!function_exists('todo_add_language')) {
    function todo_add_language($data)
    {
        list($cfg, $path) = todo_read_languages_config();
        if (!isset($cfg['languages']) || !is_array($cfg['languages'])) {
            $cfg['languages'] = [];
        }

        $mark = isset($data['mark']) ? strtolower(trim($data['mark'])) : '';
        $raw_locale = isset($data['locale']) ? trim($data['locale']) : '';

        if ($mark === '') {
            return '语言标识 (mark) 不能为空';
        }
        if ($raw_locale === '') {
            return '语言代码 (locale) 不能为空';
        }

        if (function_exists('canonical_locale')) {
            $locale = canonical_locale($raw_locale);
        } else {
            $locale = str_replace('_', '-', $raw_locale);
            $parts = explode('-', $locale);
            if (count($parts) === 2) {
                $locale = strtolower($parts[0]) . '-' . strtoupper($parts[1]);
            }
        }

        $title = isset($data['title']) ? trim($data['title']) : $locale;
        $prefix = isset($data['path_prefix']) ? trim($data['path_prefix']) : '';
        $domain = isset($data['domain']) ? trim($data['domain']) : '';
        $enabled = isset($data['enabled']) ? (bool) $data['enabled'] : true;
        $parentId = isset($data['parent_id']) && $data['parent_id'] !== '' ? str_replace('_', '-', trim($data['parent_id'])) : null;
        $sortOrder = isset($data['sort_order']) ? intval($data['sort_order']) : 0;
        $flag = isset($data['flag']) ? trim($data['flag']) : null;

        $regex = isset($cfg['regex']) ? $cfg['regex'] : '/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,4})?$/i';
        if (!preg_match($regex, $locale)) {
            return '语言代码 (locale) 格式不符合规范，例如 ja-JP、en-US 或 es';
        }

        // 重复校验
        foreach ($cfg['languages'] as $lang) {
            $existing_loc = isset($lang['locale']) ? strtolower(str_replace('_', '-', trim($lang['locale']))) : '';
            if ($existing_loc === strtolower($locale)) {
                return '语言代码 (' . $locale . ') 已存在';
            }
            if (isset($lang['mark']) && strtolower(trim($lang['mark'])) === $mark) {
                return '语言标识 (' . $mark . ') 已存在';
            }
            if ($prefix !== '' && isset($lang['path_prefix']) && strtolower(trim($lang['path_prefix'])) === strtolower($prefix)) {
                return '路径前缀 (' . $prefix . ') 已被其它语言使用';
            }
            if ($domain !== '' && isset($lang['domain']) && strtolower(trim($lang['domain'])) === strtolower($domain)) {
                return '域名 (' . $domain . ') 已被其它语言使用';
            }
        }

        $new_lang = [
            'mark' => $mark,
            'locale' => $locale,
            'title' => $title !== '' ? $title : $locale,
            'is_default' => false,
            'domain' => $domain !== '' ? $domain : null,
            'path_prefix' => $prefix,
            'enabled' => $enabled,
            'sort_order' => $sortOrder,
            'flag' => $flag,
            'site_ids' => [1],
            'default_site_id' => 1,
        ];

        if ($parentId) {
            $new_lang['parent_id'] = $parentId;
        }

        $cfg['languages'][] = $new_lang;
        if (!isset($cfg['whitelist']) || !is_array($cfg['whitelist'])) {
            $cfg['whitelist'] = [];
        }
        if (!in_array($locale, $cfg['whitelist'], true)) {
            $cfg['whitelist'][] = $locale;
        }

        $ok = todo_write_languages_config($cfg, $path);
        if (!$ok) {
            return '保存语言配置文件失败，请检查 core/config/languages.php 写入权限775/www，所有者/用户组 给“写入”。';
        }

        todo_update_language_in_db($locale, [
            'enabled' => $enabled,
            'title' => $title,
            'is_default' => false,
            'sort_order' => $sortOrder,
            'flag' => $flag
        ]);

        return true;
    }
}

/**
 * 删除语言
 */
if (!function_exists('todo_delete_language')) {
    function todo_delete_language($locale)
    {
        list($cfg, $path) = todo_read_languages_config();
        if (!isset($cfg['languages']) || !is_array($cfg['languages'])) {
            return false;
        }
        $locLower = strtolower(str_replace('_', '-', trim($locale)));
        $new = [];
        $deletedDefault = false;
        $deleted = false;
        foreach ($cfg['languages'] as $lang) {
            $isTarget = (isset($lang['locale']) && strtolower($lang['locale']) === $locLower);
            if ($isTarget) {
                if (!empty($lang['is_default'])) {
                    $deletedDefault = true;
                }
                $deleted = true;
                continue;
            }
            $new[] = $lang;
        }
        if (!$deleted || $deletedDefault)
            return false;

        $cfg['languages'] = $new;
        if (isset($cfg['whitelist']) && is_array($cfg['whitelist'])) {
            $cfg['whitelist'] = array_values(array_filter($cfg['whitelist'], function ($loc) use ($locLower) {
                return strtolower($loc) !== $locLower;
            }));
        }

        $ok = todo_write_languages_config($cfg, $path);
        if ($ok) {
            global $tododb;
            $tododb->delete($tododb->prefix . 'languages', ['code' => $locale]);

            // 重新初始化注册中心，清除内存单例中的旧语言数据
            if (class_exists('LanguageRegistry')) {
                LanguageRegistry::get_instance()->init();
            }

            // 清除页面 HTML 缓存，防止前台模板/语言切换器组件输出被删除语言的旧缓存
            if (function_exists('todo_clear_language_url_caches')) {
                todo_clear_language_url_caches();
            }

            // 删除被删除语言的模板缓存目录
            $registry = class_exists('LanguageRegistry') ? LanguageRegistry::get_instance() : null;
            $normalized_locale = $registry ? $registry->normalize_locale($locale) : str_replace('_', '-', $locale);
            $cache_dir = ABSPATH . 'content/cache/templates/' . $normalized_locale;
            if (is_dir($cache_dir)) {
                if (function_exists('delete_directory')) {
                    delete_directory($cache_dir);
                } elseif (function_exists('todo_delete_directory')) {
                    todo_delete_directory($cache_dir);
                }
            }
        }
        return $ok;
    }
}

/**
 * 更新语言映射
 */
if (!function_exists('todo_update_language_mapping')) {
    function todo_update_language_mapping($locale, $updates)
    {
        list($cfg, $path) = todo_read_languages_config();
        if (!isset($cfg['languages']) || !is_array($cfg['languages'])) {
            return false;
        }
        $site_id = isset($updates['site_id']) ? intval($updates['site_id']) : 0;
        $locLower = strtolower(str_replace('_', '-', trim($locale)));
        $found = false;
        foreach ($cfg['languages'] as &$lang) {
            if (isset($lang['locale']) && strtolower($lang['locale']) === $locLower) {
                $found = true;
                if (isset($updates['path_prefix'])) {
                    // 默认语言强制无前缀（根路径即默认语言）
                    $lang['path_prefix'] = !empty($lang['is_default']) ? '' : trim($updates['path_prefix']);
                }
                if (isset($updates['domain'])) {
                    $lang['domain'] = $updates['domain'] !== '' ? $updates['domain'] : null;
                }
                break;
            }
        }
        unset($lang);
        if (!$found) {
            return false;
        }

        $ok = true;
        $did_write_cfg = false;
        if (isset($updates['path_prefix']) || isset($updates['domain'])) {
            $did_write_cfg = true;
            $ok = todo_write_languages_config($cfg, $path);
            // 前缀/域名映射影响全站 URL 生成，需同步清除页面缓存
            if ($ok && function_exists('todo_clear_language_url_caches')) {
                todo_clear_language_url_caches();
            }
        }

        if (!$did_write_cfg) {
            return false;
        }
        return $ok;
    }
}

/**
 * 更新语言排序
 */
if (!function_exists('todo_update_language_sort_order')) {
    function todo_update_language_sort_order($locale, $sort_order)
    {
        list($cfg, $path) = todo_read_languages_config();
        if (!isset($cfg['languages']) || !is_array($cfg['languages'])) {
            return false;
        }
        $locLower = strtolower(str_replace('_', '-', trim($locale)));
        $found = false;
        foreach ($cfg['languages'] as &$lang) {
            if (isset($lang['locale']) && strtolower($lang['locale']) === $locLower) {
                $lang['sort_order'] = intval($sort_order);
                $found = true;
                break;
            }
        }
        unset($lang);
        if (!$found)
            return false;

        $ok = todo_write_languages_config($cfg, $path);
        if ($ok) {
            todo_update_language_in_db($locale, ['sort_order' => $sort_order]);
        }
        return $ok;
    }
}

if (!function_exists('todo_get_currencies_list')) {
    /**
     * 获取支持的货币完整列表
     * 涵盖 ISO 4217 标准主要跨境国际贸易货币及东盟/东亚等地区货币
     */
    function todo_get_currencies_list() {
        static $currencies = null;
        if ($currencies !== null) {
            return $currencies;
        }

        $currencies = array(
            'CNY' => array('name' => '人民币', 'symbol' => '¥'),
            'USD' => array('name' => '美元', 'symbol' => '$'),
            'EUR' => array('name' => '欧元', 'symbol' => '€'),
            'GBP' => array('name' => '英镑', 'symbol' => '£'),
            'JPY' => array('name' => '日元', 'symbol' => 'JP¥'),
            'HKD' => array('name' => '港币', 'symbol' => 'HK$'),
            'TWD' => array('name' => '新台币', 'symbol' => 'NT$'),
            'MOP' => array('name' => '澳门币', 'symbol' => 'MOP$'),
            'KRW' => array('name' => '韩元', 'symbol' => '₩'),
            'SGD' => array('name' => '新加坡元', 'symbol' => 'S$'),
            'MYR' => array('name' => '马来西亚令吉', 'symbol' => 'RM'),
            'THB' => array('name' => '泰铢', 'symbol' => '฿'),
            'VND' => array('name' => '越南盾', 'symbol' => '₫'),
            'IDR' => array('name' => '印尼卢比', 'symbol' => 'Rp'),
            'PHP' => array('name' => '菲律宾比索', 'symbol' => '₱'),
            'LAK' => array('name' => '老挝基普', 'symbol' => '₭'),
            'KHR' => array('name' => '柬埔寨瑞尔', 'symbol' => '៛'),
            'MMK' => array('name' => '缅甸元', 'symbol' => 'Ks'),
            'BND' => array('name' => '文莱元', 'symbol' => 'B$'),
            'INR' => array('name' => '印度卢比', 'symbol' => '₹'),
            'AUD' => array('name' => '澳大利亚元', 'symbol' => 'A$'),
            'CAD' => array('name' => '加拿大元', 'symbol' => 'C$'),
            'NZD' => array('name' => '新西兰元', 'symbol' => 'NZ$'),
            'RUB' => array('name' => '俄罗斯卢布', 'symbol' => '₽'),
            'BRL' => array('name' => '巴西雷亚尔', 'symbol' => 'R$'),
            'MXN' => array('name' => '墨西哥比索', 'symbol' => 'Mex$'),
            'AED' => array('name' => '阿联酋迪拉姆', 'symbol' => 'AED'),
            'SAR' => array('name' => '沙特里亚尔', 'symbol' => 'SAR'),
            'TRY' => array('name' => '土耳其里拉', 'symbol' => '₺'),
            'CHF' => array('name' => '瑞士法郎', 'symbol' => 'CHF'),
            'SEK' => array('name' => '瑞典克朗', 'symbol' => 'SEK'),
            'NOK' => array('name' => '挪威克朗', 'symbol' => 'NOK'),
            'DKK' => array('name' => '丹麦克朗', 'symbol' => 'DKK'),
            'PLN' => array('name' => '波兰兹罗提', 'symbol' => 'zł'),
            'ZAR' => array('name' => '南非兰特', 'symbol' => 'ZAR'),
        );
        return $currencies;
    }
}

if (!function_exists('todo_get_currency_symbol')) {
    function todo_get_currency_symbol($code) {
        $code = strtoupper((string)$code);
        $list = todo_get_currencies_list();
        return isset($list[$code]['symbol']) ? $list[$code]['symbol'] : $code;
    }
}

if (!function_exists('todo_get_currency_name')) {
    function todo_get_currency_name($code) {
        $code = strtoupper((string)$code);
        $list = todo_get_currencies_list();
        return isset($list[$code]['name']) ? $list[$code]['name'] : $code;
    }
}

if (!function_exists('todo_get_currency_display_label')) {
    /**
     * 获取完整货币显示标签"
     */
    function todo_get_currency_display_label($code = '') {
        if ($code === '') {
            $config = function_exists('todo_get_currency_config') ? todo_get_currency_config() : [];
            $code = !empty($config['code']) ? $config['code'] : '';
        }
        $name = todo_get_currency_name($code);
        $symbol = todo_get_currency_symbol($code);
        return $symbol !== '' ? $name . ' (' . strtoupper($code) . '/' . $symbol . ')' : $name;
    }
}

if (!function_exists('todo_get_currency_config')) {
    function todo_get_currency_config() {
        static $config = null;
        if ($config !== null) {
            return $config;
        }

        $symbol_mode = function_exists('get_option') ? get_option('currency_symbol_mode', 'b2c') : 'b2c';

        $config = array(
            'status' => 0,
            'code' => '',
            'symbol' => '',
            'display_symbol' => '',
            'symbol_mode' => $symbol_mode,
            'rate' => 1.0,
        );
        
        try {
            $locale = function_exists('current_locale') ? current_locale() : '';
            if ($locale !== '' && class_exists('LanguageRegistry')) {
                $registry = LanguageRegistry::get_instance();
                $lang_info = $registry->language_by_locale($locale);
                
                if ($lang_info && isset($lang_info['currency_status']) && $lang_info['currency_status'] == 1) {
                    $config['status'] = 1;
                    if (!empty($lang_info['currency_code'])) {
                        $config['code'] = strtoupper($lang_info['currency_code']);
                        $config['symbol'] = todo_get_currency_symbol($lang_info['currency_code']);
                    }
                    if (!empty($lang_info['currency_exchange_rate']) && floatval($lang_info['currency_exchange_rate']) > 0) {
                        $config['rate'] = floatval($lang_info['currency_exchange_rate']);
                    }
                } else {
                    $default_info = $registry->get_default_currency_info();
                    if ($default_info && !empty($default_info['currency_code'])) {
                        $config['code'] = strtoupper($default_info['currency_code']);
                        $config['symbol'] = todo_get_currency_symbol($default_info['currency_code']);
                    }
                }
            }
        } catch (Exception $e) {
        }
        
        // 符号特征：B2C 统一使用货币符号，B2B 统一使用 ISO 代码规范
        if ($symbol_mode === 'b2b') {
            $config['display_symbol'] = !empty($config['code']) ? $config['code'] : $config['symbol'];
        } else {
            $config['display_symbol'] = !empty($config['symbol']) ? $config['symbol'] : $config['code'];
        }

        return $config;
    }
}

if (!function_exists('todo_get_default_currency_config')) {
    /**
     * 获取系统默认货币（is_default_currency设为1的语言，回退为默认语言）的配置
     * 专门用于后台（如订单列表、后台统计等）展示一致的货币符号与计算
     *
     * @return array ['code' => '...', 'symbol' => '...', 'rate' => ...]
     */
    function todo_get_default_currency_config() {
        static $default_config = null;
        if ($default_config !== null) {
            return $default_config;
        }

        $symbol_mode = function_exists('get_option') ? get_option('currency_symbol_mode', 'b2c') : 'b2c';

        $default_config = array(
            'code' => '',
            'symbol' => '',
            'display_symbol' => '',
            'symbol_mode' => $symbol_mode,
            'rate' => 1.0,
        );

        try {
            if (class_exists('LanguageRegistry')) {
                $registry = LanguageRegistry::get_instance();
                $def_info = $registry->get_default_currency_info();
                if ($def_info) {
                    if (!empty($def_info['currency_code'])) {
                        $default_config['code'] = strtoupper($def_info['currency_code']);
                        $default_config['symbol'] = todo_get_currency_symbol($def_info['currency_code']);
                    }
                    if (!empty($def_info['currency_exchange_rate']) && floatval($def_info['currency_exchange_rate']) > 0) {
                        $default_config['rate'] = floatval($def_info['currency_exchange_rate']);
                    }
                }
            }
        } catch (Exception $e) {
        }

        if ($symbol_mode === 'b2b') {
            $default_config['display_symbol'] = !empty($default_config['code']) ? $default_config['code'] : $default_config['symbol'];
        } else {
            $default_config['display_symbol'] = !empty($default_config['symbol']) ? $default_config['symbol'] : $default_config['code'];
        }

        return $default_config;
    }
}

if (!function_exists('todo_apply_currency_exchange')) {
    function todo_apply_currency_exchange($price) {
        $price = (float)$price;
        if ($price <= 0) return 0.0;
        
        $config = todo_get_currency_config();
        $default_config = todo_get_default_currency_config();
        
        $current_rate = ($config['status'] == 1 && $config['rate'] > 0) ? $config['rate'] : 1.0;
        $base_rate = ($default_config['rate'] > 0) ? $default_config['rate'] : 1.0;
        
        // 价格录入时是以系统基准/默认货币记录的；从基准货币换算为当前货币汇率比值为 (current_rate / base_rate)
        $ratio = $current_rate / $base_rate;
        return round($price * $ratio, 2);
    }
}

if (!function_exists('todo_format_price')) {
    /**
     * 根据当前语言环境的汇率和货币符号格式化价格
     *
     * @param float|int|string $price 基础价格（基准货币）
     * @param string $format 格式: 'currency'(符号+价格, 默认), 'number'(纯按汇率转换格式化后的数字), 'raw'(浮点数)
     * @return string|float
     */
    function todo_format_price($price, $format = 'currency') {
        $price = (float)$price;
        $exchanged_price = todo_apply_currency_exchange($price);
        
        if ($format === 'raw') {
            return $exchanged_price;
        }

        if ($format === 'number' || $format === 'clean_number') {
            return number_format($exchanged_price, 2, '.', '');
        }

        $config = todo_get_currency_config();
        $symbol = isset($config['display_symbol']) && $config['display_symbol'] !== '' ? $config['display_symbol'] : (isset($config['symbol']) ? $config['symbol'] : '');
        $code = !empty($config['code']) ? strtoupper($config['code']) : '';
        $formatted_number = number_format($exchanged_price, 2);

        if ($format === 'iso') {
            return ($code !== '' ? $code . ' ' : '') . $formatted_number;
        }

        if ($format === 'full') {
            return $symbol . $formatted_number . ($code !== '' ? ' ' . $code : '');
        }

        return $symbol . $formatted_number;
    }
}

if (!function_exists('todo_format_admin_price')) {
    /**
     * 后台统一价格格式化函数
     * 无论后台操作员选择什么语言环境，均使用系统默认货币(is_default_currency)符号和价格格式
     *
     * @param float|int|string $price 基础价格（基准货币）
     * @param string $format 格式: 'currency'(符号+价格, 默认), 'number'(纯格式化数字), 'raw'(浮点数)
     * @return string|float
     */
    function todo_format_admin_price($price, $format = 'currency') {
        $price = (float)$price;
        if ($format === 'raw') {
            return $price;
        }

        $cfg = todo_get_default_currency_config();
        $symbol = isset($cfg['display_symbol']) && $cfg['display_symbol'] !== '' ? $cfg['display_symbol'] : (isset($cfg['symbol']) ? $cfg['symbol'] : '');
        $formatted_number = number_format($price, 2);

        if ($format === 'number') {
            return $formatted_number;
        }

        return $symbol . $formatted_number;
    }
}

if (!function_exists('todo_convert_base_to_currency')) {
    /**
     * 将系统默认基准主货币（Base Currency）下的金额转换为目标币种金额
     *
     * @param float|int|string $price 基准主货币金额
     * @param string $target_code 目标币种代码
     * @return float
     */
    function todo_convert_base_to_currency($price, $target_code = 'CNY') {
        $price = floatval($price);
        if ($price <= 0) {
            return 0.0;
        }

        $target_code = strtoupper(trim((string)$target_code));
        $default_config = todo_get_default_currency_config();
        $default_code = strtoupper($default_config['code'] ?? 'CNY');
        $base_rate = floatval($default_config['rate'] ?? 0) > 0 ? floatval($default_config['rate']) : 1.0;

        if ($target_code === '' || $target_code === $default_code) {
            return round($price, 2);
        }

        $target_rate = 0.0;

        // 优先从 LanguageRegistry 匹配目标币种汇率
        if (class_exists('LanguageRegistry')) {
            $registry = LanguageRegistry::get_instance();
            $all_langs = method_exists($registry, 'get_languages') ? $registry->get_languages(false) : [];
            if (is_array($all_langs)) {
                foreach ($all_langs as $info) {
                    if (is_array($info) && !empty($info['currency_status']) && strtoupper($info['currency_code'] ?? '') === $target_code) {
                        if (!empty($info['currency_exchange_rate']) && floatval($info['currency_exchange_rate']) > 0) {
                            $target_rate = floatval($info['currency_exchange_rate']);
                            break;
                        }
                    }
                }
            }
        }

        // 如果未查到汇率但当前活动币种正是目标币种
        if ($target_rate <= 0 && function_exists('todo_get_currency_config')) {
            $curr_cfg = todo_get_currency_config();
            if (strtoupper($curr_cfg['code'] ?? '') === $target_code && !empty($curr_cfg['rate']) && floatval($curr_cfg['rate']) > 0) {
                $target_rate = floatval($curr_cfg['rate']);
            }
        }

        if ($target_rate > 0 && $base_rate > 0) {
            $ratio = $target_rate / $base_rate;
            return round($price * $ratio, 2);
        }

        return round($price, 2);
    }
}

if (!function_exists('todo_convert_base_to_cny')) {
    /**
     * 快捷将系统基准货币金额转换为CNY金额
     *
     * @param float|int|string $price 基准主货币金额
     * @return float CNY金额
     */
    function todo_convert_base_to_cny($price) {
        return todo_convert_base_to_currency($price, 'CNY');
    }
}



