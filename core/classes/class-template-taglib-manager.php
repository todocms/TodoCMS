<?php
/**
 * 模板标签库管理器
 * 
 * 管理标签库的注册、加载和解析
 * 参考ZanCms的标签库机制实现
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 标签库管理器类
 */
class Todo_Template_Taglib_Manager {
    
    /**
     * 单例实例
     * @var Todo_Template_Taglib_Manager
     */
    private static $instance = null;
    
    /**
     * 已注册的标签库
     * @var array
     */
    private $taglibs = [];
    
    /**
     * 预加载的标签库列表
     * @var array
     */
    private $preload_taglibs = [];
    
    /**
     * 内置标签库列表
     * @var array
     */
    private $builtin_taglibs = [];
    
    /**
     * 标签库目录
     * @var string
     */
    private $taglib_dir = '';
    
    /**
     * 标签处理器缓存（标签名 => 处理器）
     * @var array
     */
    private $handler_cache = [];
    
    /**
     * 构造函数
     */
    private function __construct() {
        $this->taglib_dir = ABSPATH . 'core/classes/taglibs/';
        $this->init_default_taglibs();
    }
    
    /**
     * 获取单例实例
     * 
     * @return Todo_Template_Taglib_Manager
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 初始化默认标签库
     */
    private function init_default_taglibs() {
        // 注册内置标签库（默认使用todo前缀）
        $this->builtin_taglibs = ['todo'];
        
        // 从配置中读取预加载的标签库
        $preload = get_option('template_taglib_pre_load', '');
        if (!empty($preload)) {
            $this->preload_taglibs = array_map('trim', explode(',', $preload));
        }
        
        // 自动加载预加载的标签库
        foreach ($this->preload_taglibs as $taglib_name) {
            $this->load_taglib($taglib_name);
        }
        
        // 自动加载内置标签库
        foreach ($this->builtin_taglibs as $taglib_name) {
            $this->load_taglib($taglib_name);
        }
    }
    
    /**
     * 注册标签库
     * 
     * @param string $name 标签库名称
     * @param object|string $handler 标签库处理器对象或类名
     * @param array $options 选项（prefix, namespace等）
     * @return bool 是否注册成功
     */
    public function register_taglib($name, $handler, $options = []) {
        // 如果是类名，实例化它
        if (is_string($handler)) {
            if (!class_exists($handler)) {
                error_log("TodoCMS Taglib Manager: Class '{$handler}' not found for taglib '{$name}'");
                return false;
            }
            
            if (method_exists($handler, 'get_instance')) {
                $handler = call_user_func([$handler, 'get_instance']);
            } else {
                $handler = new $handler();
            }
        }
        
        // 检查是否实现了标签库接口
        if (!($handler instanceof Todo_Template_Taglib_Base)) {
            // 兼容旧的方式：将CMS_HTML_Template_Tags转换为标签库
            if (method_exists($handler, 'get_instance') || is_object($handler)) {
                // 创建包装器
                $wrapper = new Todo_Template_Taglib_Wrapper($handler, $name);
                $handler = $wrapper;
            } else {
                error_log("TodoCMS Taglib Manager: Handler for taglib '{$name}' must be an instance of Todo_Template_Taglib_Base");
                return false;
            }
        }
        
        $this->taglibs[$name] = [
            'handler' => $handler,
            'prefix' => isset($options['prefix']) ? $options['prefix'] : $name,
            'options' => $options,
            'type' => ($handler instanceof Todo_Template_Taglib_Base) ? 'taglib_base' : 'legacy',
            'registered' => true
        ];
        
        // 清除处理器缓存
        $this->handler_cache = [];
        
        return true;
    }
    
    /**
     * 加载标签库（从文件系统自动加载）
     * 
     * @param string $name 标签库名称
     * @return bool 是否加载成功
     */
    public function load_taglib($name) {
        // 如果已经注册，直接返回
        if (isset($this->taglibs[$name]['registered'])) {
            return true;
        }
        
        // 尝试从文件系统加载
        $taglib_file = $this->taglib_dir . $name . '/Tag' . ucfirst($name) . '.php';
        
        // 如果不存在，尝试直接的文件名
        if (!file_exists($taglib_file)) {
            $taglib_file = $this->taglib_dir . 'Tag' . ucfirst($name) . '.php';
        }
        
        if (file_exists($taglib_file)) {
            require_once $taglib_file;
            
            $class_name = 'Todo_Template_Taglib_' . ucfirst($name);
            if (class_exists($class_name)) {
                return $this->register_taglib($name, $class_name);
            }
        }
        
        // 尝试从现有类加载
        $existing_class = 'CMS_HTML_Template_Tags';
        if ($name === 'todo' && class_exists($existing_class)) {
            // 注册默认标签库
            return $this->register_taglib($name, $existing_class);
        }
        
        return false;
    }
    
    /**
     * 获取标签库处理器
     * 
     * @param string $name 标签库名称
     * @return Todo_Template_Taglib_Base|null
     */
    public function get_taglib($name) {
        if (isset($this->taglibs[$name])) {
            return $this->taglibs[$name]['handler'];
        }
        
        // 尝试加载
        if ($this->load_taglib($name)) {
            return $this->taglibs[$name]['handler'];
        }
        
        return null;
    }
    
    /**
     * 获取标签处理器（通过标签名查找）
     * 
     * @param string $tag_name 标签名（如 'post_title'）
     * @return callable|null 标签处理器
     */
    public function get_tag_handler($tag_name) {
        // 检查缓存
        if (isset($this->handler_cache[$tag_name])) {
            return $this->handler_cache[$tag_name];
        }
        
        // 遍历所有标签库查找
        foreach ($this->taglibs as $lib_name => $lib_config) {
            $handler = $lib_config['handler'];
            
            if ($handler instanceof Todo_Template_Taglib_Base) {
                $tag_handler = $handler->get_tag_handler($tag_name);
                if ($tag_handler !== null) {
                    $this->handler_cache[$tag_name] = $tag_handler;
                    return $tag_handler;
                }
            } elseif (method_exists($handler, 'parse_' . $tag_name)) {
                // 兼容旧的方式
                $tag_handler = [$handler, 'parse_' . $tag_name];
                $this->handler_cache[$tag_name] = $tag_handler;
                return $tag_handler;
            }
        }
        
        return null;
    }
    
    /**
     * 解析标签库标签（在模板解析时调用）
     * 
     * @param string $taglib_name 标签库名称
     * @param string $content 模板内容
     * @param array $data 模板数据
     * @return string 解析后的内容
     */
    public function parse_taglib($taglib_name, &$content, $data = []) {
        // Deprecated: Interpretive parsing is disabled. Use CMS_Template_Compiler_V2.
        if (defined('TODO_DEBUG') && TODO_DEBUG) {
            error_log("TodoCMS Warning: parse_taglib called (interpretive mode). This is deprecated. Use compiled engine.");
        }
        return $content;
    }
    
    /**
     * 解析标签参数
     * 
     * @param string $params_str 参数字符串
     * @return array 参数数组
     */
    private function parse_tag_params($params_str) {
        $attrs = [];
        if (empty($params_str)) {
            return $attrs;
        }
        
        // 解析 key="value" 或 key='value' 或 key=value 格式
        if (preg_match_all('/(\w+)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s]+))/', $params_str, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[1];
                // 优先使用引号内的值
                $value = !empty($match[3]) ? $match[3] : (!empty($match[4]) ? $match[4] : $match[5]);
                $attrs[$key] = $value;
            }
        }
        
        return $attrs;
    }
    
    /**
     * 获取所有已注册的标签库
     * 
     * @return array
     */
    public function get_all_taglibs() {
        return $this->taglibs;
    }
    
    /**
     * 检查标签库是否存在
     * 
     * @param string $name 标签库名称
     * @return bool
     */
    public function has_taglib($name) {
        return isset($this->taglibs[$name]);
    }
    
    /**
     * 清除标签库缓存
     */
    public function clear_cache() {
        $this->handler_cache = [];
    }
}

/**
 * 标签库包装器（用于兼容旧的标签处理器）
 */
class Todo_Template_Taglib_Wrapper extends Todo_Template_Taglib_Base {
    
    private $wrapped_handler;
    private $wrapper_name;
    
    public function __construct($handler, $name) {
        $this->wrapped_handler = $handler;
        $this->wrapper_name = $name;
        $this->taglib_name = $name;
        parent::__construct();
    }
    
    protected function register_tags() {
        // 通过反射获取所有parse_*方法
        $methods = get_class_methods($this->wrapped_handler);
        foreach ($methods as $method) {
            if (strpos($method, 'parse_') === 0) {
                $tag_name = substr($method, 6); // 移除 'parse_' 前缀
                $this->register_tag_handler($tag_name, [$this->wrapped_handler, $method]);
            }
        }
    }
}
