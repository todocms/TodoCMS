<?php
/**
 * 延迟加载机制
 * 
 * 实现类文件的按需加载和延迟初始化
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

class Todo_Lazy_Loader {
    
    /**
     * 加载器实例
     */
    private static $instance = null;
    
    /**
     * 类映射表
     */
    private $class_map = [];
    
    /**
     * 已加载的类
     */
    private $loaded_classes = [];
    
    /**
     * 延迟加载的对象
     */
    private $lazy_objects = [];

    /**
     * 加载路径
     */
    private $load_paths = [];
    
    /**
     * 命名空间映射
     */
    private $namespace_map = [];
    
    /**
     * 是否已注册自动加载器
     */
    private $autoloader_registered = false;
    
    /**
     * 获取加载器实例
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 私有构造函数
     */
    private function __construct() {
        $this->initializeClassMap();
        $this->registerAutoloader();
    }
    
    /**
     * 初始化类映射表
     */
    private function initializeClassMap() {
        // 核心类映射
        $this->class_map = [
            // 核心管理器
            'Todo_Model_Manager' => CORE_DIR . '/classes/class-model-manager.php',
            'Todo_Field_Manager' => CORE_DIR . '/classes/class-field-manager.php',
            'Todo_Theme_Manager' => CORE_DIR . '/classes/class-theme-manager.php',
            'Todo_Plugin_Manager' => CORE_DIR . '/classes/class-plugin-manager.php',
            'Todo_Cache_Manager' => CORE_DIR . '/classes/class-cache-manager.php',
            'Todo_Security_Manager' => CORE_DIR . '/classes/class-security-manager.php',
            'Todo_User_Manager' => CORE_DIR . '/classes/class-user-manager.php',
            'Todo_Menu_Manager' => CORE_DIR . '/classes/class-menu-manager.php',
            'Todo_Media_Manager' => CORE_DIR . '/classes/class-media-manager.php',
            'Todo_Order_Manager' => CORE_DIR . '/classes/class-order-manager.php',
            'Todo_Comment_Manager' => CORE_DIR . '/classes/class-comment-manager.php',
            'Todo_Performance_Monitor' => CORE_DIR . '/classes/class-performance-monitor.php',
            'Todo_Storage_Manager' => CORE_DIR . '/classes/class-storage-manager.php',
            'Todo_Tag_Manager' => CORE_DIR . '/classes/class-tag-manager.php',
            'Todo_Ads_Manager' => CORE_DIR . '/classes/class-ads-manager.php',
            'Todo_Product_Inquiry_Manager' => CORE_DIR . '/classes/class-product-inquiry-manager.php',
            'Todo_Website_Message_Manager' => CORE_DIR . '/classes/class-website-message-manager.php',
            'Todo_Email_Subscription_Manager' => CORE_DIR . '/classes/class-email-subscription-manager.php',
            'Todo_PRG_Guard' => CORE_DIR . '/classes/class-prg-guard.php',
            'Todo_Cart_Manager' => CORE_DIR . '/classes/class-cart-manager.php',
            'Todo_Payment_Manager' => CORE_DIR . '/classes/class-payment-manager.php',
            
            // 字段类型
            'Todo_Field_Type_Text' => CORE_DIR . '/classes/fields/class-field-type-text.php',
            'Todo_Field_Type_Textarea' => CORE_DIR . '/classes/fields/class-field-type-textarea.php',
            'Todo_Field_Type_Html' => CORE_DIR . '/classes/fields/class-field-type-html.php',
            'Todo_Field_Type_Select' => CORE_DIR . '/classes/fields/class-field-type-select.php',
            'Todo_Field_Type_Radio' => CORE_DIR . '/classes/fields/class-field-type-radio.php',
            'Todo_Field_Type_Checkbox' => CORE_DIR . '/classes/fields/class-field-type-checkbox.php',
            'Todo_Field_Type_Number' => CORE_DIR . '/classes/fields/class-field-type-number.php',
            'Todo_Field_Type_Date' => CORE_DIR . '/classes/fields/class-field-type-date.php',
            'Todo_Field_Type_Multi_File' => CORE_DIR . '/classes/fields/class-field-type-multi-file.php',
			'Todo_Field_Type_Region' => CORE_DIR . '/classes/fields/class-field-type-region.php',
            'Todo_Field_Type_Switch' => CORE_DIR . '/classes/fields/class-field-type-switch.php',
            
            // 数据模型
            'Todo_Model_Article' => CORE_DIR . '/models/class-model-article.php',
            'Todo_Model_Product' => CORE_DIR . '/models/class-model-product.php',
            'Todo_Model_Images' => CORE_DIR . '/models/class-model-images.php',
            'Todo_Model_Download' => CORE_DIR . '/models/class-model-download.php',
            'Todo_Model_Media' => CORE_DIR . '/models/class-model-media.php',
            'Todo_Model_Single' => CORE_DIR . '/models/class-model-single.php',
            'Todo_Model_Special' => CORE_DIR . '/models/class-model-special.php',
            'Todo_Model_Guestbook' => CORE_DIR . '/models/class-model-guestbook.php',
            'Todo_Model_Recruit' => CORE_DIR . '/models/class-model-recruit.php',
            
            // 工具类
            'Todo_Logger' => CORE_DIR . '/classes/class-logger.php',
            'Todo_Exception_Handler' => CORE_DIR . '/classes/class-exception-handler.php',
            'Todo_DI_Container' => CORE_DIR . '/classes/class-di-container.php',
            'Todo_Error_Handler' => CORE_DIR . '/classes/class-error-handler.php',
            'Todo_Database' => CORE_DIR . '/classes/class-database.php',
            'Todo_Template' => CORE_DIR . '/classes/class-template.php',
            'Todo_Hooks' => CORE_DIR . '/classes/class-hooks.php',

            // 特殊/历史命名类映射
            'TodoUpgradePackageManager' => CORE_DIR . '/classes/class-upgrade-package-manager.php',
            'TodoUserLevelManager' => CORE_DIR . '/classes/class-user-level-manager.php',
            'Points_Manager' => CORE_DIR . '/classes/class-points-manager.php',
            'CMS_Media' => CORE_DIR . '/classes/class-media.php',
            'Todo_Media' => CORE_DIR . '/classes/class-media.php',
        ];
        
        // 添加加载路径
        $this->load_paths = [
            CORE_DIR . '/classes/',
            CORE_DIR . '/classes/fields/',
            CORE_DIR . '/models/',
            PLUGIN_DIR . '/',
            THEMES_DIR . '/'
        ];
        
        // 命名空间映射
        $this->namespace_map = [
            'Todo\\Core\\' => CORE_DIR . '/classes/',
            'Todo\\Fields\\' => CORE_DIR . '/classes/fields/',
            'Todo\\Models\\' => CORE_DIR . '/models/',
            'Todo\\Plugins\\' => PLUGIN_DIR . '/',
            'Todo\\Themes\\' => THEMES_DIR . '/'
        ];
    }
    
    /**
     * 注册自动加载器
     */
    public function registerAutoloader() {
        if (!$this->autoloader_registered) {
            spl_autoload_register([$this, 'autoload']);
            $this->autoloader_registered = true;
        }
    }
    
    /**
     * 自动加载类
     * 
     * @param string $class_name 类名
     * @return bool
     */
    public function autoload($class_name) {
        // 检查是否已加载
        if (isset($this->loaded_classes[$class_name])) {
            return true;
        }
        
        // 尝试从类映射表加载
        if (isset($this->class_map[$class_name])) {
            return $this->loadClassFile($class_name, $this->class_map[$class_name]);
        }
        
        // 尝试命名空间映射
        foreach ($this->namespace_map as $namespace => $path) {
            if (strpos($class_name, $namespace) === 0) {
                $relative_class = substr($class_name, strlen($namespace));
                $file_path = $path . str_replace('\\', '/', $relative_class) . '.php';
                
                if (file_exists($file_path)) {
                    return $this->loadClassFile($class_name, $file_path);
                }
            }
        }
        
        // 尝试按约定查找文件
        return $this->findAndLoadClass($class_name);
    }
    
    /**
     * 查找并加载类文件
     * 
     * @param string $class_name 类名
     * @return bool
     */
    private function findAndLoadClass($class_name) {
        // 转换类名为文件名
        $file_patterns = $this->getFilePatterns($class_name);
        
        foreach ($this->load_paths as $path) {
            foreach ($file_patterns as $pattern) {
                $file_path = $path . $pattern;
                if (file_exists($file_path)) {
                    return $this->loadClassFile($class_name, $file_path);
                }
            }
        }
        
        return false;
    }
    
    /**
     * 获取文件名模式
     * 
     * @param string $class_name 类名
     * @return array
     */
    private function getFilePatterns($class_name) {
        $patterns = [];
        
        // 移除常见前缀
        $clean_name = str_replace(['Todo_', 'CMS_'], '', $class_name);
        
        // 1. 标准 kebab-case 转换（兼容 PRG / API / ORM 等连续缩写：Todo_PRG_Guard -> prg-guard）
        $norm = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1-$2', $clean_name);
        $norm = preg_replace('/([a-z\d])([A-Z])/', '$1-$2', $norm);
        $kebab = strtolower(str_replace('_', '-', $norm));
        $kebab = trim($kebab, '-');
        
        $patterns[] = 'class-' . $kebab . '.php';
        $patterns[] = $kebab . '.php';

        // 2. 原始单一字母切割 snake_case（向后兼容旧规则）
        $snake_case = strtolower(preg_replace('/([A-Z])/', '-$1', $clean_name));
        $snake_case = ltrim($snake_case, '-');
        $patterns[] = 'class-' . $snake_case . '.php';
        $patterns[] = $snake_case . '.php';
        
        // 3. 全小写与原名
        $patterns[] = strtolower($clean_name) . '.php';
        $patterns[] = $class_name . '.php';
        
        return array_unique($patterns);
    }
    
    /**
     * 加载类文件
     * 
     * @param string $class_name 类名
     * @param string $file_path 文件路径
     * @return bool
     */
    private function loadClassFile($class_name, $file_path) {
        if (!file_exists($file_path)) {
            return false;
        }
        
        try {
            require_once $file_path;
            $this->loaded_classes[$class_name] = $file_path;
            
            // 记录加载日志
            if (class_exists('Todo_Logger')) {
                Todo_Logger::debug("延迟加载类: {$class_name} from {$file_path}");
            }
            
            return true;
        } catch (Exception $e) {
            if (class_exists('Todo_Logger')) {
                Todo_Logger::error("加载类文件失败: {$class_name}", [
                    'file' => $file_path,
                    'error' => $e->getMessage()
                ]);
            }
            return false;
        }
    }
    
    /**
     * 创建延迟加载对象
     * 
     * @param string $class_name 类名
     * @param array $args 构造参数
     * @return Todo_Lazy_Object
     */
    public function createLazyObject($class_name, $args = []) {
        return new Todo_Lazy_Object($class_name, $args, $this);
    }
    
    /**
     * 预加载类
     * 
     * @param array $classes 类名数组
     */
    public function preloadClasses($classes) {
        foreach ($classes as $class_name) {
            if (!isset($this->loaded_classes[$class_name])) {
                $this->autoload($class_name);
            }
        }
    }
    
    /**
     * 添加类映射
     * 
     * @param string $class_name 类名
     * @param string $file_path 文件路径
     */
    public function addClassMap($class_name, $file_path) {
        $this->class_map[$class_name] = $file_path;
    }
    
    /**
     * 添加加载路径
     * 
     * @param string $path 路径
     */
    public function addLoadPath($path) {
        if (!in_array($path, $this->load_paths)) {
            $this->load_paths[] = rtrim($path, '/') . '/';
        }
    }
    
    /**
     * 添加命名空间映射
     * 
     * @param string $namespace 命名空间
     * @param string $path 路径
     */
    public function addNamespaceMap($namespace, $path) {
        $this->namespace_map[$namespace] = rtrim($path, '/') . '/';
    }
    
    /**
     * 获取已加载的类
     * 
     * @return array
     */
    public function getLoadedClasses() {
        return $this->loaded_classes;
    }
    
    /**
     * 获取类映射表
     * 
     * @return array
     */
    public function getClassMap() {
        return $this->class_map;
    }
    
    /**
     * 检查类是否已加载
     * 
     * @param string $class_name 类名
     * @return bool
     */
    public function isLoaded($class_name) {
        return isset($this->loaded_classes[$class_name]);
    }
    
    /**
     * 重置加载器
     */
    public function reset() {
        $this->loaded_classes = [];
        $this->lazy_objects = [];
    }
    
    /**
     * 获取加载统计信息
     * 
     * @return array
     */
    public function getStats() {
        return [
            'loaded_classes' => count($this->loaded_classes),
            'class_map_size' => count($this->class_map),
            'load_paths' => count($this->load_paths),
            'namespace_maps' => count($this->namespace_map),
            'lazy_objects' => count($this->lazy_objects)
        ];
    }
}

/**
 * 延迟加载对象代理
 */
class Todo_Lazy_Object {
    
    /**
     * 目标类名
     */
    private $class_name;
    
    /**
     * 构造参数
     */
    private $args;
    
    /**
     * 加载器实例
     */
    private $loader;
    
    /**
     * 实际对象实例
     */
    private $instance = null;

    /**
     * 代理层虚拟属性，避免向目标实例写入未声明属性。
     */
    private $virtual_properties = [];
    
    /**
     * 构造函数
     * 
     * @param string $class_name 类名
     * @param array $args 构造参数
     * @param Todo_Lazy_Loader $loader 加载器
     */
    public function __construct($class_name, $args = [], $loader = null) {
        $this->class_name = $class_name;
        $this->args = $args;
        $this->loader = $loader ?: Todo_Lazy_Loader::getInstance();
    }
    
    /**
     * 获取实际对象实例
     * 
     * @return object
     */
    private function getInstance() {
        if ($this->instance === null) {
            // 确保类已加载
            if (!class_exists($this->class_name)) {
                $this->loader->autoload($this->class_name);
            }
            
            // 创建实例
            if (empty($this->args)) {
                $this->instance = new $this->class_name();
            } else {
                $reflection = new ReflectionClass($this->class_name);
                $this->instance = $reflection->newInstanceArgs($this->args);
            }
        }
        
        return $this->instance;
    }
    
    /**
     * 魔术方法：调用方法
     */
    public function __call($method, $args) {
        return call_user_func_array([$this->getInstance(), $method], $args);
    }
    
    /**
     * 魔术方法：获取属性
     */
    public function __get($property) {
        $instance = $this->getInstance();
        if (is_object($instance) && isset($instance->$property)) {
            return $instance->$property;
        }
        return $this->virtual_properties[$property] ?? null;
    }
    
    /**
     * 魔术方法：设置属性
     */
    public function __set($property, $value) {
        $instance = $this->getInstance();
        if ($instance instanceof stdClass || property_exists($instance, $property)) {
            $instance->$property = $value;
            return;
        }
        $this->virtual_properties[$property] = $value;
    }
    
    /**
     * 魔术方法：检查属性是否存在
     */
    public function __isset($property) {
        $instance = $this->getInstance();
        return (is_object($instance) && isset($instance->$property)) || isset($this->virtual_properties[$property]);
    }
    
    /**
     * 魔术方法：删除属性
     */
    public function __unset($property) {
        $instance = $this->getInstance();
        if ($instance instanceof stdClass || property_exists($instance, $property)) {
            unset($instance->$property);
        }
        unset($this->virtual_properties[$property]);
    }
}

// 便捷函数
if (!function_exists('todo_lazy_load')) {
    /**
     * 创建延迟加载对象
     * 
     * @param string $class_name 类名
     * @param array $args 构造参数
     * @return Todo_Lazy_Object
     */
    function todo_lazy_load($class_name, $args = []) {
        return Todo_Lazy_Loader::getInstance()->createLazyObject($class_name, $args);
    }
}

if (!function_exists('todo_preload')) {
    /**
     * 预加载类
     * 
     * @param array $classes 类名数组
     */
    function todo_preload($classes) {
        Todo_Lazy_Loader::getInstance()->preloadClasses($classes);
    }
}
