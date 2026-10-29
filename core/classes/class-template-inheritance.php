<?php
/**
 * 模板继承处理器
 * 
 * 实现模板继承机制，支持子模板重写父模板的block区域
 * 参考ZanCms的实现，支持extend和block标签
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 模板继承处理器类
 */
class Todo_Template_Inheritance
{

    /**
     * 继承深度限制（防止无限递归）
     */
    const MAX_INHERITANCE_DEPTH = 10;

    /**
     * 当前继承深度
     * @var int
     */
    private static $inheritance_depth = 0;

    /**
     * 已处理的模板路径（用于检测循环继承）
     * @var array
     */
    private static $processed_templates = [];

    /**
     * 处理模板继承
     * 
     * @param string $template_content 模板内容
     * @param string $template_path 模板文件路径
     * @return string 处理后的模板内容
     */
    public static function process($template_content, $template_path)
    {
        // 检查是否包含extend标签（支持 {{extend ...}} 和 {{todo:extend ...}}）
        if (!preg_match('/\{\{(?:todo:)?extend\s+name=["\']([^"\']+)["\']\}\}/i', $template_content, $matches)) {
            // 没有extend标签，直接返回
            return $template_content;
        }

        // 检查继承深度
        if (self::$inheritance_depth >= self::MAX_INHERITANCE_DEPTH) {
            error_log("TodoCMS Template Inheritance: Maximum inheritance depth reached for template: {$template_path}");
            return $template_content;
        }

        // 检查循环继承
        $template_key = md5($template_path);
        if (in_array($template_key, self::$processed_templates)) {
            error_log("TodoCMS Template Inheritance: Circular inheritance detected for template: {$template_path}");
            return $template_content;
        }

        // 记录当前模板
        self::$processed_templates[] = $template_key;
        self::$inheritance_depth++;

        try {
            // 获取父模板名称
            $parent_template = trim($matches[1]);

            // 解析父模板路径
            $parent_path = self::resolve_template_path($parent_template, $template_path);

            if (!$parent_path || !file_exists($parent_path)) {
                error_log("TodoCMS Template Inheritance: Parent template not found: {$parent_template} (resolved: {$parent_path})");
                return $template_content;
            }

            // 读取父模板内容
            $parent_content = file_get_contents($parent_path);
            if ($parent_content === false) {
                error_log("TodoCMS Template Inheritance: Failed to read parent template: {$parent_path}");
                return $template_content;
            }

            // 递归处理父模板的继承（如果父模板也继承了其他模板）
            $parent_content = self::process($parent_content, $parent_path);

            // 提取子模板的blocks
            $child_blocks = self::extract_blocks($template_content);

            // 提取父模板的blocks
            $parent_blocks = self::extract_blocks($parent_content);

            // 合并blocks（子模板的blocks覆盖父模板的同名blocks）
            $merged_blocks = array_merge($parent_blocks, $child_blocks);

            // 替换父模板中的block标签
            $result = self::replace_blocks($parent_content, $merged_blocks);

            // 移除extend标签
            $result = preg_replace('/\{(?:todo:)?extend\s+name=["\'][^"\']+["\']\}/i', '', $result);

            return $result;

        } finally {
            // 恢复继承深度和已处理列表
            self::$inheritance_depth--;
            array_pop(self::$processed_templates);
        }
    }

    /**
     * 解析模板路径
     * 
     * @param string $template_name 模板名称
     * @param string $current_path 当前模板路径（用于相对路径解析）
     * @return string|false 模板完整路径，失败返回false
     */
    private static function resolve_template_path($template_name, $current_path)
    {
        // 规范化模板名称
        $template_name = trim($template_name);
        if (empty($template_name)) {
            return false;
        }

        // 如果模板名称没有扩展名，默认添加.html
        if (!preg_match('/\.(html|htm|php)$/i', $template_name)) {
            $template_name .= '.html';
        }

        // 安全检查：防止路径穿越
        $template_name = str_replace('\\', '/', $template_name);
        $template_name = preg_replace('/^(\.*)\//', '/', $template_name);
        $template_name = preg_replace('/\.(\.*)\//', '', $template_name);

        // 如果以/开头，从主题根目录查找
        if (strpos($template_name, '/') === 0) {
            $template_dir = function_exists('get_template_directory') ? get_template_directory() : '';
            if (empty($template_dir)) {
                return false;
            }
            return $template_dir . $template_name;
        }

        // 相对路径：相对于当前模板所在目录
        $current_dir = dirname($current_path);
        $resolved_path = $current_dir . '/' . $template_name;

        if (file_exists($resolved_path)) {
            return $resolved_path;
        }

        // 如果相对路径不存在，尝试从主题根目录查找
        $template_dir = function_exists('get_template_directory') ? get_template_directory() : '';
        if (!empty($template_dir)) {
            $absolute_path = $template_dir . '/' . $template_name;
            if (file_exists($absolute_path)) {
                return $absolute_path;
            }
        }

        return false;
    }

    /**
     * 提取模板中的blocks
     * 
     * @param string $content 模板内容
     * @return array blocks数组，键为block名称，值为block内容
     */
    private static function extract_blocks($content)
    {
        $blocks = [];

        // 匹配 {{block name="block_name"}}... {{todo:block name="block_name"}}...{{todo:block}}
        // 支持最内层优先匹配（避免嵌套block被截断）
        $pattern = '/\{\{(?:todo:)?block\s+name=["\']([^"\']+)["\']\}\}((?:(?!\{\{(?:todo:)?block\s+name=).)*?)\{\{\/(?:todo:)?block\}\}/s';

        while (preg_match($pattern, $content, $matches)) {
            $block_name = trim($matches[1]);
            $block_content = $matches[2];

            // 存储block（如果有同名block，后出现的覆盖先出现的）
            $blocks[$block_name] = $block_content;

            // 移除已匹配的block，继续匹配剩余内容
            $content = preg_replace($pattern, '', $content, 1);
        }

        return $blocks;
    }

    /**
     * 替换模板中的block标签为block内容
     * 
     * @param string $content 模板内容
     * @param array $blocks blocks数组
     * @return string 替换后的内容
     */
    private static function replace_blocks($content, $blocks)
    {
        // 替换 {{block name="block_name"}}... {{todo:block name="block_name"}}...{{todo:block}}
        $content = preg_replace_callback(
            '/\{\{(?:todo:)?block\s+name=["\']([^"\']+)["\']\}\}.*?\{\{\/(?:todo:)?block\}\}/s',
            function ($matches) use ($blocks) {
                $block_name = trim($matches[1]);

                // 如果blocks中存在该block，使用blocks中的内容
                if (isset($blocks[$block_name])) {
                    return $blocks[$block_name];
                }

                // 如果不存在，保留原始block内容（父模板的默认内容）
                return $matches[0];
            },
            $content
        );

        return $content;
    }

    /**
     * 检查模板是否使用了继承
     * 
     * @param string $template_content 模板内容
     * @return bool
     */
    public static function has_extend($template_content)
    {
        return preg_match('/\{(?:todo:)?extend\s+name=["\']([^"\']+)["\']\}/i', $template_content) === 1;
    }

    /**
     * 获取模板的所有blocks（用于调试）
     * 
     * @param string $template_content 模板内容
     * @return array
     */
    public static function get_blocks($template_content)
    {
        return self::extract_blocks($template_content);
    }

    /**
     * 重置继承状态（用于测试）
     */
    public static function reset()
    {
        self::$inheritance_depth = 0;
        self::$processed_templates = [];
    }
}

