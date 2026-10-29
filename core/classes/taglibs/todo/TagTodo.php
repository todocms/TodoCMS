<?php
/**
 * Todo标签库
 * 
 * 默认标签库，包含所有基础标签
 * 基于CMS_HTML_Template_Tags实现
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

require_once ABSPATH . 'core/classes/class-template-taglib-base.php';

/**
 * Todo标签库类
 */
class Todo_Template_Taglib_Todo extends Todo_Template_Taglib_Base {
    
    protected $taglib_name = 'todo';
    protected $tag_prefix = 'todo';
    
    /**
     * HTML标签处理器实例
     * @var CMS_HTML_Template_Tags
     */
    private $html_tags_handler = null;

    /**
     * 模板解析器实例
     * @var CMS_Template_Compiler_V2
     */
    private $parser_handler = null;
    
    /**
     * 注册标签
     */
    protected function register_tags() {
        // 1. 注册 CMS_HTML_Template_Tags 的标签 (作为主实现)
        if (class_exists('CMS_HTML_Template_Tags')) {
            $this->html_tags_handler = CMS_HTML_Template_Tags::get_instance();
            $this->register_html_tags();
        }

        // 2. 注册 CMS_Template_Compiler_V2 的标签 (仅补齐缺失标签，避免同名双注册/覆盖)
        if (class_exists('CMS_Template_Compiler_V2')) {
            $this->parser_handler = CMS_Template_Compiler_V2::class;
            $this->register_parser_tags();
        }

        $this->register_provider_tags();
    }

    /**
     * 注册模板解析器的所有标签
     */
    private function register_parser_tags() {
        if (!$this->parser_handler) {
            return;
        }

        $methods = get_class_methods($this->parser_handler);

        foreach ($methods as $method) {
            if (strpos($method, 'parse_') === 0) {
                $tag_name = substr($method, 6);
                if ($this->get_tag_handler($tag_name)) {
                    continue;
                }
                $this->register_tag_handler($tag_name, [$this->parser_handler, $method]);
            }
        }
    }
    
    /**
     * 注册HTML标签处理器的所有标签
     */
    private function register_html_tags() {
        if (!$this->html_tags_handler) {
            return;
        }
        
        // 动态获取所有 parse_* 方法并注册
        // 由于这是编译型引擎，反射带来的性能开销只发生在编译期，运行时无影响
        // 这样可以确保 CMS_HTML_Template_Tags 中的所有新标签都能自动被合并使用
        $methods = get_class_methods($this->html_tags_handler);
        
        foreach ($methods as $method) {
            if (strpos($method, 'parse_') === 0) {
                $tag_name = substr($method, 6); // 移除 parse_ 前缀
                $this->register_tag_handler($tag_name, [$this->html_tags_handler, $method]);
            }
        }
    }

    private function register_provider_tags() {
        $dir = ABSPATH . 'core/classes/taglibs/todo/providers';
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*.php');
        if (!$files) {
            return;
        }
        foreach ($files as $file) {
            $map = include $file;
            if (!is_array($map)) {
                continue;
            }
            foreach ($map as $tag => $handler) {
                if (is_string($tag) && $tag !== '' && is_callable($handler)) {
                    $this->register_tag_handler($tag, $handler);
                }
            }
        }
    }
    
    /**
     * 调用标签处理器（重写以支持特殊处理）
     * 
     * @param string $tag_name 标签名
     * @param array $attrs 属性
     * @param string $content 标签内容
     * @param array $data 模板数据
     * @return mixed
     */
    public function call_tag($tag_name, $attrs, $content, $data) {
        $handler = $this->get_tag_handler($tag_name);
        if ($handler && is_callable($handler)) {
            return call_user_func($handler, $attrs, $content, $data);
        }
        return '';
    }
}
