<?php
/**
 * 多语言模块（新方案简化版）
 *
 * 说明：
 * - 移除旧模式（数据库驱动的语言/术语/翻译表）相关逻辑与钩子
 * - 统一依赖新语言体系：LanguageRegistry + LocaleContext + core/functions/language.php
 * - 仅提供页面语言 meta 与 hreflang 输出、前端资源加载等基础功能
 */

// 防止直接访问（但允许AJAX请求）
if (!defined('ABSPATH') && !defined('DOING_AJAX')) {
    exit('Direct access denied');
}

// 确保加载语言管理器类
if (file_exists(__DIR__ . '/class-language-manager.php')) {
    require_once __DIR__ . '/class-language-manager.php';
}

class TodoLanguageModule
{
    private static $instance = null;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // 仅注册钩子即可：避免重复 add_action 导致多次入队
        $this->register_hooks();
    }

    private function register_hooks()
    {
        // 与系统初始化点对齐（新方案）
        add_action('todo_init', [$this, 'on_init']);

        // 页面 <head> 输出语言相关的 meta 与 hreflang
        add_action('todo_head', [$this, 'add_language_meta']);

        // 前端语言切换器资源（如存在）
        add_action('todo_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);

        // 管理端菜单（后续会迁移至新管理页面方案，此处仅保留占位）
        if (is_admin()) {
            add_action('todo_admin_menu', [$this, 'add_admin_menu']);
        }
    }

    public function on_init()
    {
        // 使用新 LocaleContext 进行请求级语言环境引导
        if (class_exists('LocaleContext')) {
            LocaleContext::get_instance()->bootstrap();
        }
        // 初始化翻译系统（加载主题语言包与回退链）
        if (class_exists('CMS_Translation')) {
            CMS_Translation::init();
        }
    }

    /**
     * 输出语言相关的 meta 与 hreflang
     */
    public function add_language_meta()
    {
        if (!function_exists('current_locale')) {
            return;
        }
        $current = current_locale();

        echo '<meta name="language" content="' . esc_attr($current) . '">' . "\n";
    }

    private function load_frontend_assets()
    {
        // 保留占位，无需重复注册资源入队钩子
    }

    public function enqueue_frontend_scripts()
    {
        $public_css = ABSPATH . 'public/static/common/css/language-switcher.css';
        $public_js = ABSPATH . 'public/static/common/js/language-switcher.js';
        // 仅使用统一的资源排队机制（最新方案），资源迁移至 public/static/common
        if (file_exists($public_css) && function_exists('todo_enqueue_style')) {
            todo_enqueue_style('todo-language-switcher', todo_get_site_url('public/static/common/css/language-switcher.css'), [], '1.0.2', 'all');
        }
        if (file_exists($public_js) && function_exists('todo_enqueue_script')) {
            // 新方案：统一在页脚输出，依赖模板调用 {{todo:todo_footer}} 触发钩子
            todo_enqueue_script('todo-language-switcher', todo_get_site_url('public/static/common/js/language-switcher.js'), [], '1.0.2', true);
        }

        // 站点切换器样式与脚本
        $site_css = ABSPATH . 'public/static/common/css/site-switcher.css';
        $site_js = ABSPATH . 'public/static/common/js/site-switcher.js';
        if (file_exists($site_css) && function_exists('todo_enqueue_style')) {
            todo_enqueue_style('todo-site-switcher', todo_get_site_url('public/static/common/css/site-switcher.css'), [], '1.0.0', 'all');
        }
        if (file_exists($site_js) && function_exists('todo_enqueue_script')) {
            todo_enqueue_script('todo-site-switcher', todo_get_site_url('public/static/common/js/site-switcher.js'), [], '1.0.0', true);
        }
    }

    public function add_admin_menu()
    {
        // TODO: 迁移至新管理页面与配置方案（基于 LanguageRegistry 配置文件）
    }

    // 为避免旧代码直接调用这些方法导致致命错误，保留占位返回值
    public function get_language_manager()
    {
        return null;
    }
    public function get_template_parser()
    {
        return null;
    }
    public function get_language_switcher()
    {
        return null;
    }
}

function todo_init_language_module()
{
    return TodoLanguageModule::get_instance();
}

function todo_language_module()
{
    return TodoLanguageModule::get_instance();
}

// 初始化模块
TodoLanguageModule::get_instance();

// 与系统初始化钩子对齐（保持对 on_init 的支持）
add_action('todo_init', 'todo_init_language_module');

// ========== 新增：按语言映射主题（active_theme 过滤器） ==========
if (!function_exists('todo_sanitize_theme_name')) {
    function todo_sanitize_theme_name($name)
    {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '') {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            return '';
        }
        return $name;
    }
}

if (!function_exists('todo_get_mapped_theme_for_locale')) {
    function todo_get_mapped_theme_for_locale($locale)
    {
        $locale = str_replace('_', '-', (string) $locale);
        if (function_exists('canonical_locale')) {
            $locale = canonical_locale($locale);
        }
        
        $registry = class_exists('LanguageRegistry') ? LanguageRegistry::get_instance() : null;
        $fallback = $registry ? $registry->get_fallback_locale() : 'zh-CN';

        // 语言→主题映射的唯一数据源
        $map = get_option('language_theme_map', array());
        if (is_string($map)) {
            $decoded = json_decode($map, true);
            if (is_array($decoded)) {
                $map = $decoded;
            } else {
                $map = array();
            }
        }
        if (!is_array($map)) {
            $map = array();
        }
        // 归一化键
        $normMap = array();
        foreach ($map as $loc => $themeName) {
            $locNorm = str_replace('_', '-', (string) $loc);
            if (function_exists('canonical_locale')) {
                $locNorm = canonical_locale($locNorm);
            }
            $normMap[$locNorm] = todo_sanitize_theme_name($themeName);
        }
        // options 映射命中 — language_theme_map 是语言→主题映射的唯一数据源
        if (isset($normMap[$locale]) && theme_exists($normMap[$locale])) {
            return $normMap[$locale];
        }

        return '';
    }
}

if (!function_exists('todo_active_theme_language_mapper')) {
    function todo_active_theme_language_mapper($current)
    {
        global $preview_theme_name;
        // 在预览模式和后台环境中不做语言主题映射，但允许缓存预热过程（通过 action 或 flag 判断）获取正确的映射
        $is_cache_warmup = (isset($_POST['action']) && $_POST['action'] === 'process_cache_url') || !empty($GLOBALS['todo_doing_cache_warmup']);
        if (!empty($preview_theme_name) || (function_exists('is_admin') && is_admin() && !$is_cache_warmup)) {
            return $current;
        }

        $origLocale = function_exists('current_locale') ? current_locale() : str_replace('_', '-', get_option('locale', 'zh_CN'));
        $locale = $origLocale;
        $registry = class_exists('LanguageRegistry') ? LanguageRegistry::get_instance() : null;
        $usedFallback = false;
        if ($registry && !$registry->is_supported_locale($locale, true)) {
            $locale = $registry->get_fallback_locale();
            $usedFallback = true;
        }

        // 新增：默认语言始终使用系统默认主题，不做映射
        if ($registry) {
            $defaultLocale = $registry->get_default_locale();
            if (!empty($defaultLocale) && $locale === $defaultLocale) {
                return $current;
            }
        }

        $mapped = todo_get_mapped_theme_for_locale($locale);
        if (!$mapped || !theme_exists($mapped)) {
            return $current;
        }

        if (function_exists('todo_log_event')) {
            todo_log_event('language_theme_map_hit', array(
                'locale' => $locale,
                'mapped_theme' => $mapped,
                'used_fallback' => $usedFallback ? 1 : 0,
            ));
        }
        return $mapped;
    }
}

// 注册过滤器（优先级略早于默认）
add_filter('active_theme', 'todo_active_theme_language_mapper', 10);

// 当语言主题映射更新时，清理翻译缓存
if (!function_exists('todo_on_language_theme_map_updated')) {
    function todo_on_language_theme_map_updated($newMap, $oldMap)
    {
        if (class_exists('CMS_Translation')) {
            CMS_Translation::clear_cache();
        }
    }
}
add_action('language_theme_map_updated', 'todo_on_language_theme_map_updated', 10, 2);
