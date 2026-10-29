<?php
/**
 * 模板标签库基类
 * 
 * 提供标签库扩展的基础接口，方便开发者创建自定义标签库
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 标签库基类
 */
abstract class Todo_Template_Taglib_Base {
    
    /**
     * 标签处理器映射表
     * @var array
     */
    protected $tag_handlers = [];
    
    /**
     * 标签库名称
     * @var string
     */
    protected $taglib_name = '';
    
    /**
     * 标签前缀
     * @var string
     */
    protected $tag_prefix = 'todo';
    
    /**
     * 构造函数
     */
    public function __construct() {
        $this->register_tags();
    }
    
    /**
     * 注册标签（子类实现）
     * 
     * 在此方法中使用 $this->register_tag_handler() 注册标签
     */
    abstract protected function register_tags();
    
    /**
     * 注册标签处理器
     * 
     * @param string $tag_name 标签名（不含前缀）
     * @param callable|string $handler 处理函数或方法名
     */
    protected function register_tag_handler($tag_name, $handler) {
        if (is_string($handler) && method_exists($this, $handler)) {
            $this->tag_handlers[$tag_name] = [$this, $handler];
        } elseif (is_callable($handler)) {
            $this->tag_handlers[$tag_name] = $handler;
        } else {
            error_log("TodoCMS Taglib: Invalid handler for tag '{$tag_name}' in taglib '{$this->taglib_name}'");
        }
    }
    
    /**
     * 获取标签处理器
     * 
     * @param string $tag_name 标签名
     * @return callable|null 标签处理器，未找到返回null
     */
    public function get_tag_handler($tag_name) {
        return isset($this->tag_handlers[$tag_name]) ? $this->tag_handlers[$tag_name] : null;
    }
    
    /**
     * 获取所有已注册的标签名
     * 
     * @return array
     */
    public function get_registered_tags() {
        return array_keys($this->tag_handlers);
    }
    
    /**
     * 获取标签库名称
     * 
     * @return string
     */
    public function get_taglib_name() {
        return $this->taglib_name;
    }
    
    /**
     * 获取标签前缀
     * 
     * @return string
     */
    public function get_tag_prefix() {
        return $this->tag_prefix;
    }
    
    /**
     * 解析标签参数（辅助方法）
     * 
     * @param string|array $params 参数字符串或数组
     * @return array 解析后的参数数组
     */
    protected function parse_params($params) {
        if (is_array($params)) {
            return $params;
        }
        
        $attrs = [];
        if (empty($params)) {
            return $attrs;
        }
        
        // 解析 key=value 格式的参数
        if (preg_match_all('/(\w+)\s*=\s*([^\s,]+)/', $params, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[1];
                $value = trim($match[2], '"\'');
                $attrs[$key] = $value;
            }
        }
        
        return $attrs;
    }
    
    /**
     * 获取模板数据（辅助方法）
     * 
     * @param array $data 模板数据
     * @param string $key 键名（支持点号分隔，如 'post.title'）
     * @param mixed $default 默认值
     * @return mixed
     */
    protected function get_data($data, $key, $default = null) {
        if (!is_array($data)) {
            return $default;
        }
        
        if (strpos($key, '.') === false) {
            return isset($data[$key]) ? $data[$key] : $default;
        }
        
        $parts = explode('.', $key);
        $value = $data;
        
        foreach ($parts as $part) {
            if (is_array($value) && isset($value[$part])) {
                $value = $value[$part];
            } elseif (is_object($value) && isset($value->$part)) {
                $value = $value->$part;
            } else {
                return $default;
            }
        }
        
        return $value;
    }
}
