<?php
/**
 * 语言注册中心 LanguageRegistry
 *
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access denied.');
}

class LanguageRegistry
{
    private static $instance = null;

    private $languages = array();        // keyed by hyphen-locale, e.g. en-US
    private $byMark = array();            // mark -> locale
    private $defaultLocale = 'zh-CN';
    private $fallbackLocale = 'zh-CN';
    private $domainMap = array();         // host -> locale
    private $pathPrefixMap = array();     // path prefix -> locale
    private $enabledLocales = array();
    private $whitelist = array();
    private $regex = '/^[a-z]{2}(-[A-Z]{2})?$/';
    private $routingMode = 'path_prefix';

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    public function init()
    {
        $configPath = ABSPATH . 'core/config/languages.php';
        $cfg = array();
        if (file_exists($configPath)) {
            // 确保读取到最新文件状态（避免第一次刷新未更新）
            clearstatcache(true, $configPath);
            $cfg = include $configPath;
        }
        if (!is_array($cfg)) {
            $cfg = array();
        }

        // 重新初始化内部状态，防止重复 init 时残留旧数据导致首次刷新不更新
        $this->languages = array();
        $this->byMark = array();
        $this->domainMap = array();
        $this->pathPrefixMap = array();
        $this->enabledLocales = array();
        $this->whitelist = array();
        $this->defaultLocale = 'zh-CN';

        $this->routingMode = isset($cfg['routing']['mode']) ? (string) $cfg['routing']['mode'] : 'path_prefix';
        $this->regex = isset($cfg['regex']) && is_string($cfg['regex']) ? $cfg['regex'] : '/^[a-z]{2}(-[A-Z]{2})?$/';
        $this->fallbackLocale = isset($cfg['fallback_locale']) ? $this->normalize_locale($cfg['fallback_locale']) : 'zh-CN';
        $wl = isset($cfg['whitelist']) && is_array($cfg['whitelist']) ? $cfg['whitelist'] : array();
        foreach ($wl as $loc) {
            $this->whitelist[] = $this->normalize_locale($loc);
        }
        $langs = isset($cfg['languages']) && is_array($cfg['languages']) ? $cfg['languages'] : array();
        $seenDefault = false;
        $originalIndex = 0;
        foreach ($langs as $lang) {
            $mark = isset($lang['mark']) ? strtolower(trim($lang['mark'])) : '';
            $locale = isset($lang['locale']) ? $this->normalize_locale($lang['locale']) : '';
            $title = isset($lang['title']) ? trim($lang['title']) : $locale;
            $nativeName = isset($lang['native_name']) ? trim($lang['native_name']) : $title;
            $isDefault = !empty($lang['is_default']);
            $domain = isset($lang['domain']) && $lang['domain'] ? strtolower(trim($lang['domain'])) : null;
            $pathPrefix = isset($lang['path_prefix']) ? trim($lang['path_prefix']) : '';
            $enabled = isset($lang['enabled']) ? (bool) $lang['enabled'] : true;
            // 新增：可选的主题字段（严格清理）
            $themeName = isset($lang['theme']) ? trim($lang['theme']) : null;
            if (is_string($themeName) && $themeName !== '') {
                // 只允许字母、数字、下划线与短横线，避免路径穿越
                if (!preg_match('/^[A-Za-z0-9_-]+$/', $themeName)) {
                    $themeName = null;
                }
            } else {
                $themeName = null;
            }
            // 排序字段：默认为0
            $sortOrder = isset($lang['sort_order']) ? intval($lang['sort_order']) : 0;

            if (!$locale || !$mark) {
                continue;
            }
            // 严格校验 locale，并按白名单过滤；mark 允许字母数字与短横线
            if (!preg_match('/^[a-z][a-z0-9-]*$/', $mark)) {
                continue;
            }
            if (!preg_match($this->regex, $locale)) {
                continue;
            }
            if (!empty($this->whitelist) && !in_array($locale, $this->whitelist, true)) {
                continue;
            }
            // 处理父级关系
            $parentId = isset($lang['parent_id']) && !empty($lang['parent_id']) ? $this->normalize_locale($lang['parent_id']) : null;

            $this->languages[$locale] = array(
                'mark' => $mark,
                'locale' => $locale,
                'title' => $title,
                'native_name' => $nativeName, // 保持向后兼容，若未提供则使用 title
                'flag' => isset($lang['flag']) ? $lang['flag'] : null, // 新增：国旗图标
                'domain' => $domain,
                'path_prefix' => $pathPrefix,
                'enabled' => $enabled,
                'is_default' => $isDefault,
                'theme' => $themeName,
                'parent_id' => $parentId,
                'sort_order' => $sortOrder,
                'currency_code' => isset($lang['currency_code']) ? $lang['currency_code'] : '',
                'currency_exchange_rate' => isset($lang['currency_exchange_rate']) ? floatval($lang['currency_exchange_rate']) : 1,
                'currency_status' => isset($lang['currency_status']) ? intval($lang['currency_status']) : 1,
                'is_default_currency' => !empty($lang['is_default_currency']) ? 1 : 0,
                'site_ids' => isset($lang['site_ids']) && is_array($lang['site_ids']) ? $lang['site_ids'] : array(),
                'default_site_id' => isset($lang['default_site_id']) ? intval($lang['default_site_id']) : 0,
                '_original_index' => $originalIndex // 记录在配置文件中的原始索引
            );
            $this->byMark[$mark] = $locale;
            if ($enabled) {
                $this->enabledLocales[] = $locale;
            }
            if ($domain) {
                $this->domainMap[$domain] = $locale;
            }
            if ($enabled && $pathPrefix !== '') {
                $this->pathPrefixMap[$pathPrefix] = $locale;
            }
            if ($isDefault && !$seenDefault) {
                $this->defaultLocale = $locale;
                $seenDefault = true;
            }
            $originalIndex++;
        }
        // whitelist 已在前面处理，避免重复加入
        if (isset($cfg['domain_locale_map']) && is_array($cfg['domain_locale_map'])) {
            foreach ($cfg['domain_locale_map'] as $host => $loc) {
                $hostNorm = strtolower(trim($host));
                $locNorm = $this->normalize_locale($loc);
                $this->domainMap[$hostNorm] = $locNorm;
            }
        }
        if (!$seenDefault) {
            $this->defaultLocale = !empty($this->enabledLocales) ? $this->enabledLocales[0] : $this->fallbackLocale;
        }
    }

    public function get_routing_mode()
    {
        return $this->routingMode;
    }
    public function get_languages($onlyEnabled = true)
    {
        if ($onlyEnabled) {
            $result = array();
            foreach ($this->enabledLocales as $loc) {
                $result[$loc] = $this->languages[$loc];
            }
            return $result;
        }
        return $this->languages;
    }
    public function get_default_locale()
    {
        return $this->defaultLocale;
    }
    public function get_default_currency_info()
    {
        foreach ($this->languages as $info) {
            if (!empty($info['is_default_currency'])) {
                return $info;
            }
        }
        $def_locale = $this->get_default_locale();
        return isset($this->languages[$def_locale]) ? $this->languages[$def_locale] : null;
    }
    public function get_fallback_locale()
    {
        return $this->fallbackLocale;
    }

    /**
     * 获取指定语言的回退链
     * 顺序：祖先语言（从直接父级到根） -> 默认语言
     * 
     * @param string $locale 语言代码
     * @return array 回退语言代码数组
     */
    public function get_fallback_locales($locale)
    {
        $locale = $this->normalize_locale($locale);
        $fallbacks = array();

        // 1) 获取所有祖先（从直接父级到根）
        $ancestors = $this->get_ancestors($locale);
        if (!empty($ancestors)) {
            // get_ancestors 返回顺序通常是 [根, ..., 直接父级]
            // 我们需要 [直接父级, ..., 根]
            $fallbacks = array_reverse(array_keys($ancestors));
        }

        // 2) 追加默认语言
        $default = $this->get_default_locale();
        if ($default && $default !== $locale && !in_array($default, $fallbacks, true)) {
            $fallbacks[] = $default;
        }

        return array_values(array_unique($fallbacks));
    }

    public function is_supported_locale($locale, $onlyEnabled = true)
    {
        $loc = $this->normalize_locale($locale);
        if ($loc === '') {
            return false;
        }
        if (!preg_match($this->regex, $loc)) {
            return false;
        }
        if (!empty($this->whitelist) && !in_array($loc, $this->whitelist, true)) {
            return false;
        }
        if (!isset($this->languages[$loc])) {
            return false;
        }
        if ($onlyEnabled && !$this->languages[$loc]['enabled']) {
            return false;
        }
        return true;
    }
    public function normalize_locale($locale)
    {
        if (!is_string($locale) || $locale === '') {
            return '';
        }
        $l = str_replace('_', '-', trim($locale));
        $parts = explode('-', $l);
        if (count($parts) >= 2) {
            $parts[0] = strtolower($parts[0]);
            $parts[1] = strtoupper($parts[1]);
            $l = $parts[0] . '-' . $parts[1] . (count($parts) > 2 ? '-' . implode('-', array_slice($parts, 2)) : '');
        } else {
            $l = strtolower($l);
        }
        return $l;
    }

    public function locale_for_mark($mark)
    {
        $m = strtolower(trim($mark));
        return isset($this->byMark[$m]) ? $this->byMark[$m] : null;
    }
    public function language_by_locale($locale)
    {
        $loc = $this->normalize_locale($locale);
        return isset($this->languages[$loc]) ? $this->languages[$loc] : null;
    }

    public function domain_map()
    {
        return $this->domainMap;
    }
    public function path_prefix_map()
    {
        return $this->pathPrefixMap;
    }

    public function match_by_host($host)
    {
        if (!$host)
            return null;
        $h = strtolower(trim($host));
        $h = preg_replace('/:\\d+$/', '', $h); // strip port
        return isset($this->domainMap[$h]) ? $this->domainMap[$h] : null;
    }
    /**
     * 计算安装子目录的路径段（例如 /todocms → ['todocms']）
     */
    private function install_base_segments()
    {
        $segments = array();
        $doc = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') : '';
        $abs = defined('ABSPATH') ? rtrim(ABSPATH, '/') : '';
        if ($doc && $abs && strpos($abs, $doc) === 0) {
            $rel = trim(substr($abs, strlen($doc)), '/');
            if ($rel !== '') {
                $segments = array_values(array_filter(explode('/', $rel), function ($s) {
                    return $s !== '';
                }));
            }
        } else {
            // 兜底：从脚本目录推断
            $dir = isset($_SERVER['SCRIPT_NAME']) ? dirname($_SERVER['SCRIPT_NAME']) : '';
            $dir = trim($dir, '/');
            if ($dir !== '') {
                $segments = array_values(array_filter(explode('/', $dir), function ($s) {
                    return $s !== '' && strtolower($s) !== 'admin';
                }));
            }
        }
        return $segments;
    }

    public function match_by_path_segment($uri)
    {
        if (!is_string($uri) || $uri === '') {
            return null;
        }
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }
        $all = array_values(array_filter(explode('/', ltrim($path, '/')), function ($s) {
            return $s !== '';
        }));
        if (empty($all)) {
            return null;
        }
        
        // 优先按 offset（扣除安装子目录段）匹配
        $install = $this->install_base_segments();
        $offset = 0;
        if (!empty($install)) {
            $len = count($install);
            if (count($all) >= $len && array_slice($all, 0, $len) === $install) {
                $offset = $len;
            }
        }

        // 先尝试按期望的 offset 段匹配
        if (isset($all[$offset])) {
            $seg = strtolower($all[$offset]);
            foreach ($this->pathPrefixMap as $prefix => $loc) {
                if ($prefix !== '' && $seg === strtolower($prefix)) {
                    return $loc;
                }
            }
            if (isset($this->byMark[$seg])) {
                return $this->byMark[$seg];
            }
        }

        // 遍历所有路径段匹配（兜底兼容如 CLI 环境或复杂 Referer 路径）
        foreach ($all as $seg) {
            $segLower = strtolower($seg);
            foreach ($this->pathPrefixMap as $prefix => $loc) {
                if ($prefix !== '' && $segLower === strtolower($prefix)) {
                    return $loc;
                }
            }
            if (isset($this->byMark[$segLower])) {
                return $this->byMark[$segLower];
            }
        }

        return null;
    }

    public function choose_from_accept_language($header)
    {
        if (!is_string($header) || $header === '')
            return null;
        $langs = $this->get_languages(true);
        $candidates = array();
        $parts = preg_split('/,\s*/', $header);
        foreach ($parts as $p) {
            $pair = explode(';', $p);
            $tag = strtolower(trim($pair[0]));
            $q = 1.0;
            if (isset($pair[1]) && preg_match('/q=([0-9\.]+)/', $pair[1], $m)) {
                $q = (float) $m[1];
            }
            $candidates[$tag] = $q;
        }
        $best = null;
        $bestQ = -1;
        foreach ($candidates as $tag => $q) {
            foreach ($langs as $loc => $info) {
                $primary = strtolower($info['mark']);
                $locLower = strtolower($loc);
                if ($tag === $locLower || $tag === $primary) {
                    if ($q > $bestQ) {
                        $best = $loc;
                        $bestQ = $q;
                    }
                }
            }
        }
        return $best;
    }

    /**
     * 获取语言的层级深度（从根开始计算，根为1）
     * @param string $locale 语言代码
     * @return int 层级深度，最大为5
     */
    public function get_language_depth($locale)
    {
        $loc = $this->normalize_locale($locale);
        if (!isset($this->languages[$loc])) {
            return 0;
        }

        $depth = 1;
        $maxDepth = 5;
        $current = $loc;
        $visited = array(); // 防止循环引用

        while ($depth < $maxDepth && isset($this->languages[$current]['parent_id']) && $this->languages[$current]['parent_id']) {
            $parentId = $this->languages[$current]['parent_id'];

            // 防止循环引用
            if (in_array($parentId, $visited)) {
                break;
            }
            $visited[] = $parentId;

            if (isset($this->languages[$parentId])) {
                $current = $parentId;
                $depth++;
            } else {
                break;
            }
        }

        return $depth;
    }

    /**
     * 获取语言的所有子语言（递归）
     * @param string $locale 父语言代码
     * @return array 子语言数组，键为locale，值为语言信息
     */
    public function get_children($locale)
    {
        $loc = $this->normalize_locale($locale);
        $children = array();

        foreach ($this->languages as $langLocale => $langInfo) {
            if (isset($langInfo['parent_id']) && $langInfo['parent_id'] === $loc) {
                $children[$langLocale] = $langInfo;
            }
        }

        return $children;
    }

    /**
     * 获取语言的所有祖先（从根到直接父级）
     * @param string $locale 语言代码
     * @return array 祖先数组，从根到直接父级
     */
    public function get_ancestors($locale)
    {
        $loc = $this->normalize_locale($locale);
        $ancestors = array();
        $maxDepth = 5;
        $current = $loc;
        $visited = array(); // 防止循环引用

        while ($maxDepth > 0 && isset($this->languages[$current]['parent_id']) && $this->languages[$current]['parent_id']) {
            $parentId = $this->languages[$current]['parent_id'];

            // 防止循环引用
            if (in_array($parentId, $visited)) {
                break;
            }
            $visited[] = $parentId;

            if (isset($this->languages[$parentId])) {
                array_unshift($ancestors, $this->languages[$parentId]);
                $current = $parentId;
                $maxDepth--;
            } else {
                break;
            }
        }

        return $ancestors;
    }

    /**
     * 获取按层级排序的语言列表
     * @param bool $onlyEnabled 是否只返回启用的语言
     * @return array 按层级排序的语言数组
     */
    public function get_languages_hierarchical($onlyEnabled = true)
    {
        $allLanguages = $this->get_languages($onlyEnabled);
        $sorted = array();
        $processed = array();

        // 计算每个语言的层级深度
        $calculate_level = function ($locale, $langInfo, &$levelCache) use (&$calculate_level, $allLanguages) {
            if (isset($levelCache[$locale])) {
                return $levelCache[$locale];
            }

            // 如果没有父级，层级为0
            if (!isset($langInfo['parent_id']) || !$langInfo['parent_id']) {
                $levelCache[$locale] = 0;
                return 0;
            }

            $parentId = $langInfo['parent_id'];

            // 如果父级不存在，层级为0
            if (!isset($allLanguages[$parentId])) {
                $levelCache[$locale] = 0;
                return 0;
            }

            // 递归计算父级层级
            $parentLevel = $calculate_level($parentId, $allLanguages[$parentId], $levelCache);

            // 当前层级 = 父级层级 + 1，但不能超过4（因为层级从0开始，所以最大层级是4）
            $currentLevel = min($parentLevel + 1, 4);
            $levelCache[$locale] = $currentLevel;

            return $currentLevel;
        };

        // 预先计算所有语言的层级
        $levelCache = array();
        foreach ($allLanguages as $locale => $langInfo) {
            $calculate_level($locale, $langInfo, $levelCache);
        }

        // 为每个语言添加层级信息和原始索引（用于保持配置文件中的顺序）
        $index = 0;
        foreach ($allLanguages as $locale => $langInfo) {
            $langInfo['_level'] = isset($levelCache[$locale]) ? $levelCache[$locale] : 0;
            $langInfo['_original_index'] = $index++;
            $allLanguages[$locale] = $langInfo;
        }

        // 辅助函数：递归处理语言树，每个父级下的子级独立排序
        // 这样每个层级的 sort_order 都是独立的，不会影响其他层级的排序
        $process_tree = function ($parentId = null) use (&$process_tree, &$sorted, $allLanguages) {
            // 获取当前父级下的所有直接子语言
            $children = array();
            foreach ($allLanguages as $locale => $langInfo) {
                $langParentId = isset($langInfo['parent_id']) && $langInfo['parent_id'] ? $langInfo['parent_id'] : null;
                // 如果 parentId 为 null，获取根语言；否则获取指定父级下的子语言
                if (
                    ($parentId === null && $langParentId === null) ||
                    ($parentId !== null && $langParentId === $parentId)
                ) {
                    $children[$locale] = $langInfo;
                }
            }

            // 对当前层级的语言按 sort_order 排序，相同 sort_order 时按原始索引（配置文件顺序）排序
            // 这样每个父级下的子级排序是独立的，且保持配置文件中的顺序
            uasort($children, function ($a, $b) {
                $sortA = isset($a['sort_order']) ? intval($a['sort_order']) : 0;
                $sortB = isset($b['sort_order']) ? intval($b['sort_order']) : 0;
                if ($sortA !== $sortB) {
                    return $sortA - $sortB;
                }
                // sort_order 相同时按原始索引（配置文件中的顺序）排序
                $indexA = isset($a['_original_index']) ? intval($a['_original_index']) : 999999;
                $indexB = isset($b['_original_index']) ? intval($b['_original_index']) : 999999;
                if ($indexA !== $indexB) {
                    return $indexA - $indexB;
                }
                // 最后按 locale 排序
                $localeA = isset($a['locale']) ? $a['locale'] : '';
                $localeB = isset($b['locale']) ? $b['locale'] : '';
                return strcmp($localeA, $localeB);
            });

            // 依次处理每个语言及其子级（深度优先）
            foreach ($children as $locale => $langInfo) {
                // 添加当前语言到结果
                $sorted[$locale] = $langInfo;

                // 递归处理该语言的所有子级（子级会在当前语言后面立即显示）
                $process_tree($locale);
            }
        };

        // 从根语言开始处理（parent_id 为 null）
        $process_tree(null);

        return $sorted;
    }

    /**
     * 获取语言的显示名称（带层级前缀）
     * @param string $locale 语言代码
     * @param string $prefix 前缀字符，默认为 "-"
     * @return string 带层级前缀的显示名称
     */
    public function get_language_display_name($locale, $prefix = '-')
    {
        $loc = $this->normalize_locale($locale);
        if (!isset($this->languages[$loc])) {
            return $locale;
        }

        $langInfo = $this->languages[$loc];
        $depth = $this->get_language_depth($loc);

        if ($depth <= 1) {
            return $langInfo['title'];
        }

        $indent = str_repeat($prefix, $depth - 1);
        return $indent . $langInfo['title'];
    }
}
