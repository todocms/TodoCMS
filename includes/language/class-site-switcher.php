<?php
/**
 * 前端站点切换器
 *
 * 根据当前语言环境下已关联的站点信息，输出下拉切换组件。
 * 模板调用：{{todo:site_switcher type="dropdown"}}
 *
 * @package TodoCMS
 * @subpackage Language
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access denied.');
}

class TodoSiteSwitcher
{
    /**
     * 当前站点ID
     * @var int
     */
    private $current_site_id = 0;

    /**
     * 当前语言代码
     * @var string
     */
    private $current_locale = 'zh-CN';

    /**
     * 该语言关联的站点列表
     * @var array
     */
    private $associated_sites = array();

    /**
     * 默认站点ID
     * @var int
     */
    private $default_site_id = 0;

    /**
     * 构造函数
     */
    public function __construct()
    {
        // 获取当前语言
        $this->current_locale = function_exists('current_locale')
            ? current_locale()
            : (function_exists('todo_get_current_language') ? todo_get_current_language() : 'zh-CN');
        $this->current_locale = str_replace('_', '-', $this->current_locale);

        // 获取当前站点ID
        if (function_exists('todo_current_site_id')) {
            $this->current_site_id = (int) todo_current_site_id();
        } elseif (isset($_GET['site']) && is_numeric($_GET['site'])) {
            $this->current_site_id = (int) $_GET['site'];
        } elseif (defined('TODO_SITE_ID')) {
            $this->current_site_id = (int) TODO_SITE_ID;
        }

        // 加载该语言关联的站点列表
        if (function_exists('todo_get_language_sites')) {
            $this->associated_sites = todo_get_language_sites($this->current_locale);
        }

        // 加载默认站点ID
        if (function_exists('todo_get_language_default_site_id')) {
            $this->default_site_id = (int) todo_get_language_default_site_id($this->current_locale);
        }

        // 检查当前站点是否在关联列表中
        $is_in_associated = false;
        if (!empty($this->associated_sites)) {
            foreach ($this->associated_sites as $site) {
                if ((int) $site['id'] === $this->current_site_id) {
                    $is_in_associated = true;
                    break;
                }
            }
        } else {
            $is_in_associated = true; // 无关联限制时放行
        }

        // 如果当前站点未设置或不在关联列表中，优先使用语言默认站点
        if ($this->current_site_id <= 0 || !$is_in_associated) {
            if ($this->default_site_id > 0) {
                $this->current_site_id = $this->default_site_id;
            }
        }

        // 业务需求：如果没有配置关联站点，则不显示站点切换器，不再回退显示所有站点
        // （当 associated_sites 为空时，后续 render 方法会直接返回空字符串）
    }

    /**
     * 渲染站点切换器
     *
     * @param string $type    输出样式：dropdown / list
     * @param array  $options 附加选项
     * @return string HTML
     */
    public function render($type = 'dropdown', $options = array())
    {
        if (empty($this->associated_sites) || count($this->associated_sites) <= 1) {
            return '';
        }

        $defaults = array(
            'show_icons' => false,
            'class' => 'todo-site-switcher',
            'current_url' => $this->get_current_url()
        );
        $options = array_merge($defaults, $options);

        switch ($type) {
            case 'list':
                return $this->render_list($options);
            case 'dropdown':
            default:
                return $this->render_dropdown($options);
        }
    }

    /**
     * 渲染下拉菜单样式
     */
    private function render_dropdown($options)
    {
        $current_site = $this->get_site_by_id($this->current_site_id);
        if (!$current_site) {
            $current_site = reset($this->associated_sites);
        }

        $html = '<div class="' . esc_attr($options['class']) . ' site-dropdown-style">';

        // 当前站点按钮
        $html .= '<button type="button" class="current-site">';
        $html .= '<span class="site-name">' . esc_html($current_site['name']) . '</span>';
        $html .= '<i class="icon todofont todo-Right ro90"></i>';
        $html .= '</button>';

        // 站点列表
        $html .= '<div class="site-dropdown-list" style="display: none;">';

        foreach ($this->associated_sites as $site) {
            if ((int) $site['id'] === (int) $this->current_site_id) {
                continue;
            }

            $onclick = "document.cookie='todo_site_id=" . esc_js($site['id']) . "; path=/; max-age=15552000'; window.location.reload();";
            $html .= '<a href="javascript:void(0);" onclick="' . $onclick . '" class="site-option" data-site-id="' . esc_attr($site['id']) . '">';
            $html .= '<span class="site-name">' . esc_html($site['name']) . '</span>';
            if (!empty($site['is_default']) && (int) $site['id'] === $this->default_site_id) {
                $html .= ' <span class="site-default-badge">默认</span>';
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
        $html = '<ul class="' . esc_attr($options['class']) . ' site-list-style">';

        foreach ($this->associated_sites as $site) {
            $is_current = ((int) $site['id'] === (int) $this->current_site_id);
            $class = $is_current ? 'current' : '';

            $onclick = "document.cookie='todo_site_id=" . esc_js($site['id']) . "; path=/; max-age=15552000'; window.location.reload();";
            $html .= '<li class="' . $class . '">';
            $html .= '<a href="javascript:void(0);" onclick="' . $onclick . '" class="site-option" data-site-id="' . esc_attr($site['id']) . '">';
            $html .= '<span class="site-name">' . esc_html($site['name']) . '</span>';
            if (!empty($site['is_default']) && (int) $site['id'] === $this->default_site_id) {
                $html .= ' <span class="site-default-badge">默认</span>';
            }
            $html .= '</a>';
            $html .= '</li>';
        }

        $html .= '</ul>';
        return $html;
    }

    /**
     * 根据ID获取站点信息
     */
    private function get_site_by_id($site_id)
    {
        foreach ($this->associated_sites as $site) {
            if ((int) $site['id'] === (int) $site_id) {
                return $site;
            }
        }
        return null;
    }

    /**
     * 生成切换站点的URL
     */
    private function get_switch_url($site_id, $current_url)
    {
        $parsed = parse_url($current_url);
        $query = array();
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }
        $query['site'] = $site_id;

        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
        $host = isset($parsed['host']) ? $parsed['host'] : '';
        $path = isset($parsed['path']) ? $parsed['path'] : '/';

        return $scheme . $host . $path . '?' . http_build_query($query);
    }

    /**
     * 获取当前完整URL
     */
    private function get_current_url()
    {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field($_SERVER['HTTP_HOST']) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field($_SERVER['REQUEST_URI']) : '/';
        return $scheme . $host . $uri;
    }
}

/**
 * 全局函数：渲染站点切换器
 */
function todo_site_switcher($type = 'dropdown', $options = array())
{
    $switcher = new TodoSiteSwitcher();
    return $switcher->render($type, $options);
}

/**
 * 全局函数：输出站点切换器
 */
function todo_the_site_switcher($type = 'dropdown', $options = array())
{
    echo todo_site_switcher($type, $options);
}
