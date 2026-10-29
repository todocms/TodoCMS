<?php
/**
 * TodoCMS 模板语言标签解析器
 * 
 * @package TodoCMS
 * @subpackage Language
 * @version 1.0.0
 */

// 防止直接访问（但允许AJAX请求）
if (!defined('ABSPATH') && !defined('DOING_AJAX')) {
    exit('Direct access denied');
}

// 兼容性保障：如果语言管理器函数未定义，则加载对应类文件
if (!function_exists('todo_language_manager')) {
    $lm_path = ABSPATH . 'includes/language/class-language-manager.php';
    if (file_exists($lm_path)) {
        require_once $lm_path;
    }
}

/**
 * 模板语言标签解析器类
 */
class TodoLanguageTemplateParser
{

    /**
     * 语言管理器实例
     */
    private $language_manager;

    /**
     * 标签模式
     */
    private $tag_pattern = '/\{([^}|]+)\|todo_lang(?:\|([^}]*))?\}/';

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->language_manager = todo_language_manager();
    }

    /**
     * 解析模板内容
     * 
     * @param string $content 模板内容
     * @param string $context 上下文（可选）
     * @return string 解析后的内容
     */
    public function parse($content, $context = null)
    {
        if (empty($content)) {
            return $content;
        }

        // 使用编译型模板引擎进行解析（性能更高，支持缓存）
        if (class_exists('CMS_Template_Compiler_V2')) {
            $data = [];
            if ($context) {
                $data['__todo_lang_context'] = $context;
            }
            // 使用 render_string 编译并执行包含语言标签的内容
            return CMS_Template_Compiler_V2::render_string($content, $data);
        }

        // 编译引擎不可用时，抛出异常，不进行解释型解析，直接暴露错误
        throw new Exception('TodoCMS Error: Compiled template engine not found for language parsing.');
    }

    public function extract_language_tags($content)
    {
        $tags = [];

        if (preg_match_all($this->tag_pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $original_text = trim($match[1]);
                $context = isset($match[2]) ? trim($match[2]) : null;

                if (!empty($original_text)) {
                    $tags[] = [
                        'full_tag' => $match[0],
                        'original_text' => $original_text,
                        'context' => $context
                    ];
                }
            }
        }

        return $tags;
    }

    /**
     * 验证模板语法
     * 
     * @param string $content 模板内容
     * @return array 验证结果
     */
    public function validate_template_syntax($content)
    {
        $errors = [];
        $warnings = [];

        // 检查未闭合的标签
        $open_braces = substr_count($content, '{');
        $close_braces = substr_count($content, '}');

        if ($open_braces !== $close_braces) {
            $errors[] = '模板中存在未闭合的大括号';
        }

        // 检查语言标签格式
        if (preg_match_all('/\{[^}]*\|todo_lang[^}]*\}/', $content, $matches)) {
            foreach ($matches[0] as $tag) {
                if (!preg_match($this->tag_pattern, $tag)) {
                    $warnings[] = "语言标签格式可能有误: {$tag}";
                }
            }
        }

        // 检查空的语言标签
        if (preg_match_all('/\{\s*\|todo_lang/', $content, $matches)) {
            $errors[] = '发现空的语言标签';
        }

        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * 生成语言标签
     * 
     * @param string $text 原始文本
     * @param string $context 上下文（可选）
     * @return string 生成的语言标签
     */
    public function generate_language_tag($text, $context = null)
    {
        if (empty($text)) {
            return '';
        }

        $tag = '{' . $text . '|todo_lang';

        if (!empty($context)) {
            $tag .= '|' . $context;
        }

        $tag .= '}';

        return $tag;
    }

    /**
     * 批量替换文本为语言标签
     * 
     * @param string $content 模板内容
     * @param array $replacements 替换规则 ['原文' => '上下文']
     * @return string 替换后的内容
     */
    public function batch_replace_with_tags($content, $replacements)
    {
        foreach ($replacements as $text => $context) {
            $tag = $this->generate_language_tag($text, $context);
            $content = str_replace($text, $tag, $content);
        }

        return $content;
    }

    /**
     * 扫描模板文件中的语言标签
     * 
     * @param string $template_path 模板文件路径
     * @return array 扫描结果
     */
    public function scan_template_file($template_path)
    {
        if (!file_exists($template_path)) {
            return [
                'success' => false,
                'error' => '模板文件不存在: ' . $template_path
            ];
        }

        $content = file_get_contents($template_path);
        if ($content === false) {
            return [
                'success' => false,
                'error' => '无法读取模板文件: ' . $template_path
            ];
        }

        $tags = $this->extract_language_tags($content);
        $validation = $this->validate_template_syntax($content);

        return [
            'success' => true,
            'file_path' => $template_path,
            'tags' => $tags,
            'tag_count' => count($tags),
            'validation' => $validation
        ];
    }

    /**
     * 扫描主题目录中的所有模板文件
     * 
     * @param string $theme_path 主题目录路径
     * @return array 扫描结果
     */
    public function scan_theme_templates($theme_path)
    {
        $results = [];

        if (!is_dir($theme_path)) {
            return [
                'success' => false,
                'error' => '主题目录不存在: ' . $theme_path
            ];
        }

        // 扫描 .htm 和 .html 文件
        $template_files = glob($theme_path . '/*.{htm,html}', GLOB_BRACE);

        foreach ($template_files as $file) {
            $scan_result = $this->scan_template_file($file);
            $results[] = $scan_result;
        }

        // 统计信息
        $total_files = count($results);
        $total_tags = array_sum(array_column($results, 'tag_count'));
        $files_with_errors = count(array_filter($results, function ($r) {
            return !empty($r['validation']['errors']);
        }));

        return [
            'success' => true,
            'theme_path' => $theme_path,
            'files' => $results,
            'statistics' => [
                'total_files' => $total_files,
                'total_tags' => $total_tags,
                'files_with_errors' => $files_with_errors
            ]
        ];
    }
}

/**
 * 获取模板解析器实例
 */
function todo_language_template_parser()
{
    static $parser = null;
    if ($parser === null) {
        $parser = new TodoLanguageTemplateParser();
    }
    return $parser;
}

/**
 * 解析模板内容 - 全局快捷函数
 */
function todo_parse_language_template($content, $context = null)
{
    return todo_language_template_parser()->parse($content, $context);
}

/**
 * 提取语言标签 - 全局快捷函数
 */
function todo_extract_language_tags($content)
{
    return todo_language_template_parser()->extract_language_tags($content);
}