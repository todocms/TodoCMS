<?php
/**
 * 前端语言切换器
 * 
 * @package TodoCMS
 * @subpackage Language
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access denied.');
}

// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../');
}

class TodoLanguageSwitcher
{

    private $registry;
    private $current_locale;
    private $supported_languages;

    public function __construct()
    {
        // 新方案：使用 LanguageRegistry + core/functions/language.php
        require_once ABSPATH . 'core/functions/language.php';
        $this->registry = LanguageRegistry::get_instance();
        $this->current_locale = get_current_language();
        // 使用层级排序的语言列表
        $this->supported_languages = $this->registry->get_languages_hierarchical(true);
    }

    /**
     * 渲染语言切换器
     */
    public function render($type = 'dropdown', $options = [])
    {
        if (count($this->supported_languages) <= 1) {
            return '';
        }

        $defaults = [
            'show_flags' => true,
            'show_names' => true,
            'show_native_names' => false,
            'class' => 'todo-language-switcher',
            'current_url' => $this->get_current_url()
        ];

        $options = array_merge($defaults, $options);

        switch ($type) {
            case 'dropdown':
                return $this->render_dropdown($options);
            case 'list':
                return $this->render_list($options);
            case 'flags':
                return $this->render_flags($options);
            default:
                return $this->render_dropdown($options);
        }
    }

    /**
     * 渲染下拉菜单样式
     */
    private function render_dropdown($options)
    {
        $current_lang = $this->get_language_by_code($this->current_locale);

        if (!$current_lang) {
            return '';
        }

        $html = '<div class="' . esc_attr($options['class']) . ' dropdown-style">';

        // 当前语言按钮
        $html .= '<button type="button" class="current-language">';

        if ($options['show_flags'] && !empty($current_lang['flag'])) {
            $html .= $this->render_flag_element($current_lang['flag'], $current_lang['title'] ?? '');
        }

        if ($options['show_names']) {
            $display_name = ($options['show_native_names'] && !empty($current_lang['native_name']))
                ? $current_lang['native_name']
                : (isset($current_lang['title']) ? $current_lang['title'] : $this->current_locale);
            $html .= '<span class="name">' . esc_html($display_name) . '</span>';
        }

        $html .= '<i class="icon todofont todo-Right ro90"></i>';
        $html .= '</button>';

        // 语言列表
        $html .= '<div class="language-dropdown" style="display: none;">';

        foreach ($this->supported_languages as $loc => $lang) {
            if ($loc === $this->current_locale) {
                continue;
            }

            $switch_url = $this->get_switch_url($loc, $options['current_url']);

            $html .= '<a href="' . esc_url($switch_url) . '" class="language-option" data-lang="' . esc_attr($loc) . '">';

            if ($options['show_flags'] && !empty($lang['flag'])) {
                $html .= $this->render_flag_element($lang['flag'], $lang['title'] ?? '');
            }

            if ($options['show_names']) {
                $display_name = ($options['show_native_names'] && !empty($lang['native_name']))
                    ? $lang['native_name']
                    : (isset($lang['title']) ? $lang['title'] : $loc);

                $level = isset($lang['_level']) ? (int) $lang['_level'] : 0;
                if ($level > 0) {
                    $display_name = str_repeat('-', $level) . ' ' . $display_name;
                }

                $html .= '<span class="name">' . esc_html($display_name) . '</span>';
            }

            $html .= '</a>';
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * 渲染列表样式
     */
    private function render_list($options)
    {
        $html = '<ul class="' . esc_attr($options['class']) . ' list-style">';

        foreach ($this->supported_languages as $loc => $lang) {
            $is_current = ($loc === $this->current_locale);
            $switch_url = $is_current ? '#' : $this->get_switch_url($loc, $options['current_url']);

            $class = $is_current ? 'current' : '';

            $html .= '<li class="' . $class . '">';
            $html .= '<a href="' . esc_url($switch_url) . '" class="language-option" data-lang="' . esc_attr($loc) . '">';

            if ($options['show_flags'] && !empty($lang['flag'])) {
                $html .= $this->render_flag_element($lang['flag'], $lang['title'] ?? '');
            }

            if ($options['show_names']) {
                $display_name = ($options['show_native_names'] && !empty($lang['native_name']))
                    ? $lang['native_name']
                    : (isset($lang['title']) ? $lang['title'] : $loc);

                $level = isset($lang['_level']) ? (int) $lang['_level'] : 0;
                if ($level > 0) {
                    $display_name = str_repeat('-', $level) . ' ' . $display_name;
                }

                $html .= '<span class="name">' . esc_html($display_name) . '</span>';
            }

            $html .= '</a>';
            $html .= '</li>';
        }

        $html .= '</ul>';

        return $html;
    }

    /**
     * 渲染旗帜样式
     */
    private function render_flags($options)
    {
        $html = '<div class="' . esc_attr($options['class']) . ' flags-style">';

        foreach ($this->supported_languages as $loc => $lang) {
            if (empty($lang['flag'])) {
                continue;
            }

            $is_current = ($loc === $this->current_locale);
            $switch_url = $is_current ? '#' : $this->get_switch_url($loc, $options['current_url']);

            $class = $is_current ? 'current' : '';
            $title = ($options['show_native_names'] && !empty($lang['native_name']))
                ? $lang['native_name']
                : (isset($lang['title']) ? $lang['title'] : $loc);

            $html .= '<a href="' . esc_url($switch_url) . '" class="flag-link language-option ' . $class . '" data-lang="' . esc_attr($loc) . '" title="' . esc_attr($title) . '">';
            $html .= $this->render_flag_element($lang['flag'], $title, true);
            $html .= '</a>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * 渲染国旗元素
     */
    private function render_flag_element($flag, $title = '', $is_large = false)
    {
        $flag = trim($flag);
        if (empty($flag))
            return '';

        // 检查是否是图片代码（如 cn, us, jp, kr）
        if (preg_match('/^[a-z]{2}$/i', $flag)) {
            $flag_code = strtolower($flag);
            $flag_rel = 'public/static/common/images/flags/' . $flag_code . '.png';
            $flag_path = ABSPATH . ltrim($flag_rel, '/');
            $flag_url = function_exists('todo_get_site_url') ? todo_get_site_url($flag_rel) : ('/' . ltrim($flag_rel, '/'));
            if (function_exists('todo_media_url_to_runtime_absolute')) {
                $flag_url = todo_media_url_to_runtime_absolute($flag_url);
            }

            if (file_exists($flag_path)) {
                return '<img src="' . esc_url($flag_url) . '" alt="' . esc_attr($title) . '" class="flag-img' . ($is_large ? ' large' : '') . '">';
            }
        }

        // 回退到文字/Emoji
        return '<span class="flag">' . esc_html($flag) . '</span>';
    }

    /**
     * 获取语言切换URL
     */
    private function get_switch_url($target_locale, $current_url)
    {
        // 使用统一的新方案函数，避免旧模式逻辑
        if (function_exists('get_language_url')) {
            return get_language_url($target_locale, $current_url);
        }
        // 后备：直接返回当前URL
        return $current_url;
    }

    private function strip_any_language_prefix($path)
    {
        $prefixMap = $this->registry->path_prefix_map();
        $knownPrefixes = array_keys($prefixMap);
        $segments = array_values(array_filter(explode('/', $path), function ($seg) {
            return $seg !== ''; }));
        if (!empty($segments) && in_array($segments[0], $knownPrefixes, true)) {
            array_shift($segments);
        }
        $newPath = '/' . implode('/', $segments);
        if ($newPath === '/') {
            return '/';
        }
        // 保留尾部斜杠
        if (substr($path, -1) === '/' && substr($newPath, -1) !== '/') {
            $newPath .= '/';
        }
        return $newPath;
    }

    /**
     * 获取当前URL
     */
    private function get_current_url()
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        return $protocol . '://' . $host . $uri;
    }

    /**
     * 根据locale获取语言信息（新方案）
     */
    private function get_language_by_code($locale)
    {
        if (isset($this->supported_languages[$locale])) {
            return $this->supported_languages[$locale];
        }
        // 后备：从注册中心获取
        $info = $this->registry->language_by_locale($locale);
        return is_array($info) ? $info : null;
    }
}

/**
 * 全局函数：渲染语言切换器
 */
function todo_language_switcher($type = 'dropdown', $options = [])
{
    $switcher = new TodoLanguageSwitcher();
    return $switcher->render($type, $options);
}

/**
 * 全局函数：输出语言切换器
 */
function todo_the_language_switcher($type = 'dropdown', $options = [])
{
    echo todo_language_switcher($type, $options);
}
?>
