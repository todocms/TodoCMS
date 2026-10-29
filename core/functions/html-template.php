<?php
/**
 * HTML模板函数文件
 *
 * 扩展现有模板系统以支持HTML静态模板
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 加载HTML模板文件
 *
 * @param string $template 模板文件名（不含扩展名）
 * @param array $data 传递给模板的数据
 * @param bool $echo 是否直接输出，默认true
 * @return string|void 如果$echo为false，返回解析后的内容
 */
function load_html_template($template, $data = [], $echo = true)
{
    $template_path = get_html_template_path($template);

    if (!$template_path) {
        // 如果HTML模板不存在，尝试加载PHP模板
        return load_template($template . '.php', $data);
    }

    $content = parse_html_template($template_path, $data);

    if ($echo) {
        echo $content;
    } else {
        return $content;
    }
}

/**
 * 获取HTML模板文件路径
 *
 * @param string $template 模板文件名（不含扩展名）
 * @return string|false 模板文件路径，如果不存在返回false
 */
function get_html_template_path($template)
{
    $theme_dir = get_template_directory();

    // 优先查找HTML文件
    $html_template = $theme_dir . '/' . $template . '.html';
    if (file_exists($html_template)) {
        return $html_template;
    }

    // 查找模板部分目录
    $template_parts_dir = $theme_dir . '/template-parts';
    $html_template_part = $template_parts_dir . '/' . $template . '.html';
    if (file_exists($html_template_part)) {
        return $html_template_part;
    }

    return false;
}

/**
 * 获取模板文件路径（支持HTML和PHP）
 *
 * @param string $template 模板文件名（不含扩展名）
 * @return string|false 模板文件路径，如果不存在返回false
 */
function get_template_file_path($template)
{
    // 优先查找HTML模板
    $html_path = get_html_template_path($template);
    if ($html_path) {
        return $html_path;
    }

    // 查找PHP模板
    $php_path = get_template_path($template . '.php');
    if ($php_path && file_exists($php_path)) {
        return $php_path;
    }

    return false;
}

/**
 * 包含HTML模板部分
 *
 * @param string $template_part 模板部分名称
 * @param array $data 传递给模板的数据
 */
function get_html_template_part($template_part, $data = [])
{
    $template_path = get_html_template_path('template-parts/' . $template_part);

    if (!$template_path) {
        $template_path = get_html_template_path($template_part);
    }

    if ($template_path) {
        echo parse_html_template_part($template_path, $data);
    } else {
        // 回退到PHP模板
        get_template_part($template_part);
    }
}

/**
 * 解析HTML模板文件
 * 
 * 强制使用编译引擎进行解析，不进行回退。
 * 
 * @param string $template_path 模板文件绝对路径
 * @param array $data 传递给模板的数据
 * @return string 解析后的内容
 * @throws Exception 如果编译引擎未找到
 */
if (!function_exists('parse_html_template')) {
    function parse_html_template($template_path, $data = [])
    {
        if (!class_exists('CMS_Template_Compiler_V2')) {
            require_once __DIR__ . '/../classes/class-template-compiler-v2.php';
        }
        $out = CMS_Template_Compiler_V2::parse_template_with_cache($template_path, $data);
        if (function_exists('todo_normalize_content_root_relative_urls') && is_string($out) && $out !== '') {
            $out = todo_normalize_content_root_relative_urls($out);
        }
        return $out;
    }
}

/**
 * 解析HTML模板片段（跳过静态缓存生成，仅动态编译渲染）
 * 适用于 header, footer, sidebar 等已在主模板中被合并缓存的组件
 */
if (!function_exists('parse_html_template_part')) {
    function parse_html_template_part($template_path, $data = [])
    {
        if (!class_exists('CMS_Template_Compiler_V2')) {
            require_once __DIR__ . '/../classes/class-template-compiler-v2.php';
        }
        $out = CMS_Template_Compiler_V2::render_template($template_path, $data);
        if (function_exists('todo_normalize_content_root_relative_urls') && is_string($out) && $out !== '') {
            $out = todo_normalize_content_root_relative_urls($out);
        }
        return $out;
    }
} // end if (!function_exists('parse_html_template_part'))

if (!function_exists('get_template_parser')) {
    function get_template_parser()
    {
        static $parser = null;
        if ($parser !== null) {
            return $parser;
        }

        if (!class_exists('CMS_Template_Compiler_V2')) {
            $parser = null;
            return $parser;
        }

        $parser = new class {
            public function parse_template($template_path, $data = [])
            {
                return CMS_Template_Compiler_V2::render_template($template_path, $data);
            }

            public function parse_content($content, $data = [])
            {
                return CMS_Template_Compiler_V2::render_string($content, $data);
            }

            public function register_tag($tag_name, $callback)
            {
                return CMS_Template_Compiler_V2::register_tag($tag_name, $callback);
            }

            public function clear_cache($template_name = null)
            {
                return CMS_Template_Compiler_V2::clear_cache($template_name);
            }
        };

        return $parser;
    }
}
