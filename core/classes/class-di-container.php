<?php
/**
 * 依赖注入容器
 * 
 * 轻量级DI容器，支持单例模式、工厂模式和自动装配
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

 
class Todo_DI_Container {
    
    /**
     * 容器实例
     */
    private static $instance = null;
    
    /**
     * 服务绑定
     */
    private $bindings = [];
    
    /**
     * 单例实例
     */
    private $instances = [];
    
    /**
     * 别名映射
     */
    private $aliases = [];
    
    /**
     * 构造函数参数缓存
     */
    private $constructor_cache = [];
    
    /**
     * 获取容器实例
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
        // 注册核心服务
        $this->registerCoreServices();
    }
    
    /**
     * 注册核心服务
     */
    private function registerCoreServices() {
        // 注册日志服务
        $this->singleton('logger', function() {
            return new Todo_Logger();
        });
        
        // 注册缓存服务
        $this->singleton('cache', function() {
            return Todo_Cache_Manager::getInstance();
        });
        
        // 注册数据库服务
        $this->singleton('db', function() {
            global $tododb;
            return $tododb;
        });
        
        // 注册安全管理器
        $this->singleton('security', function() {
            return Todo_Security_Manager::getInstance();
        });
        
        // 注册模型管理器
        $this->singleton('model_manager', function() {
            return Todo_Model_Manager::getInstance();
        });
        
        // 注册字段管理器
        $this->singleton('field_manager', function() {
            return Todo_Field_Manager::getInstance();
        });
        
        // 注册主题管理器
        $this->singleton('theme_manager', function() {
            return Todo_Theme_Manager::getInstance();
        });
        
        // 注册插件管理器
        $this->singleton('plugin_manager', function() {
            return Todo_Plugin_Manager::getInstance();
        });
    }
    
    /**
     * 绑定服务
     * 
     * @param string $abstract 抽象名称
     * @param mixed $concrete 具体实现
     * @param bool $shared 是否共享实例
     */
    public function bind($abstract, $concrete = null, $shared = false) {
        if ($concrete === null) {
            $concrete = $abstract;
        }
        
        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'shared' => $shared
        ];
        
        // 如果已存在实例且不是共享的，清除实例
        if (!$shared && isset($this->instances[$abstract])) {
            unset($this->instances[$abstract]);
        }
    }
    
    /**
     * 绑定单例服务
     * 
     * @param string $abstract 抽象名称
     * @param mixed $concrete 具体实现
     */
    public function singleton($abstract, $concrete = null) {
        $this->bind($abstract, $concrete, true);
    }
    
    /**
     * 绑定实例
     * 
     * @param string $abstract 抽象名称
     * @param mixed $instance 实例
     */
    public function instance($abstract, $instance) {
        $this->instances[$abstract] = $instance;
        $this->bindings[$abstract] = [
            'concrete' => $instance,
            'shared' => true
        ];
    }
    
    /**
     * 设置别名
     * 
     * @param string $alias 别名
     * @param string $abstract 抽象名称
     */
    public function alias($alias, $abstract) {
        $this->aliases[$alias] = $abstract;
    }
    
    /**
     * 解析服务
     * 
     * @param string $abstract 抽象名称
     * @param array $parameters 参数
     * @return mixed
     */
    public function make($abstract, $parameters = []) {
        // 解析别名
        $abstract = $this->getAlias($abstract);
        
        // 如果是共享实例且已存在，直接返回
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }
        
        // 构建实例
        $instance = $this->build($abstract, $parameters);
        
        // 如果是共享的，缓存实例
        if (isset($this->bindings[$abstract]) && $this->bindings[$abstract]['shared']) {
            $this->instances[$abstract] = $instance;
        }
        
        return $instance;
    }
    
    /**
     * 构建实例
     * 
     * @param string $abstract 抽象名称
     * @param array $parameters 参数
     * @return mixed
     */
    private function build($abstract, $parameters = []) {
        // 如果有绑定，使用绑定的具体实现
        if (isset($this->bindings[$abstract])) {
            $concrete = $this->bindings[$abstract]['concrete'];
        } else {
            $concrete = $abstract;
        }
        
        // 如果是闭包，执行闭包
        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }
        
        // 如果是字符串，尝试实例化类
        if (is_string($concrete)) {
            return $this->buildClass($concrete, $parameters);
        }
        
        // 直接返回实例
        return $concrete;
    }
    
    /**
     * 构建类实例
     * 
     * @param string $class 类名
     * @param array $parameters 参数
     * @return object
     */
    private function buildClass($class, $parameters = []) {
        // 检查类是否存在
        if (!class_exists($class)) {
            throw new Exception("类 {$class} 不存在");
        }
        
        // 获取反射类
        $reflector = new ReflectionClass($class);
        
        // 检查是否可实例化
        if (!$reflector->isInstantiable()) {
            throw new Exception("类 {$class} 不可实例化");
        }
        
        // 获取构造函数
        $constructor = $reflector->getConstructor();
        
        // 如果没有构造函数，直接实例化
        if ($constructor === null) {
            return new $class;
        }
        
        // 解析构造函数依赖
        $dependencies = $this->resolveDependencies($constructor, $parameters);
        
        // 创建实例
        return $reflector->newInstanceArgs($dependencies);
    }
    
    /**
     * 解析依赖
     * 
     * @param ReflectionMethod $constructor 构造函数反射
     * @param array $parameters 参数
     * @return array
     */
    private function resolveDependencies($constructor, $parameters = []) {
        $dependencies = [];
        $params = $constructor->getParameters();
        
        foreach ($params as $param) {
            $name = $param->getName();
            
            // 如果提供了参数，使用提供的参数
            if (array_key_exists($name, $parameters)) {
                $dependencies[] = $parameters[$name];
                continue;
            }
            
            // 尝试类型提示
            $type = $param->getType();
            if ($type && !$type->isBuiltin()) {
                $className = $type->getName();
                $dependencies[] = $this->make($className);
                continue;
            }
            
            // 检查默认值
            if ($param->isDefaultValueAvailable()) {
                $dependencies[] = $param->getDefaultValue();
                continue;
            }
            
            // 如果参数可为空
            if ($param->allowsNull()) {
                $dependencies[] = null;
                continue;
            }
            
            throw new Exception("无法解析参数 {$name}");
        }
        
        return $dependencies;
    }
    
    /**
     * 获取别名对应的抽象名称
     * 
     * @param string $abstract 抽象名称或别名
     * @return string
     */
    private function getAlias($abstract) {
        return isset($this->aliases[$abstract]) ? $this->aliases[$abstract] : $abstract;
    }
    
    /**
     * 检查服务是否已绑定
     * 
     * @param string $abstract 抽象名称
     * @return bool
     */
    public function bound($abstract) {
        $abstract = $this->getAlias($abstract);
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }
    
    /**
     * 检查服务是否为单例
     * 
     * @param string $abstract 抽象名称
     * @return bool
     */
    public function isShared($abstract) {
        $abstract = $this->getAlias($abstract);
        return isset($this->instances[$abstract]) || 
               (isset($this->bindings[$abstract]) && $this->bindings[$abstract]['shared']);
    }
    
    /**
     * 移除绑定
     * 
     * @param string $abstract 抽象名称
     */
    public function forget($abstract) {
        $abstract = $this->getAlias($abstract);
        unset($this->bindings[$abstract], $this->instances[$abstract]);
    }
    
    /**
     * 清空容器
     */
    public function flush() {
        $this->bindings = [];
        $this->instances = [];
        $this->aliases = [];
        $this->constructor_cache = [];
    }
    
    /**
     * 获取所有绑定
     * 
     * @return array
     */
    public function getBindings() {
        return $this->bindings;
    }
    
    /**
     * 获取所有实例
     * 
     * @return array
     */
    public function getInstances() {
        return $this->instances;
    }
    
    /**
     * 魔术方法：获取服务
     * 
     * @param string $name 服务名称
     * @return mixed
     */
    public function __get($name) {
        return $this->make($name);
    }
    
    /**
     * 魔术方法：检查服务是否存在
     * 
     * @param string $name 服务名称
     * @return bool
     */
    public function __isset($name) {
        return $this->bound($name);
    }
}

// 便捷函数
if (!function_exists('todo_container')) {
    /**
     * 获取DI容器实例
     * 
     * @return Todo_DI_Container
     */
    function todo_container() {
        return Todo_DI_Container::getInstance();
    }
}

if (!function_exists('todo_make')) {
    /**
     * 从容器中解析服务
     * 
     * @param string $abstract 抽象名称
     * @param array $parameters 参数
     * @return mixed
     */
    function todo_make($abstract, $parameters = []) {
        return todo_container()->make($abstract, $parameters);
    }
}

if (!function_exists('todo_singleton')) {
    /**
     * 绑定单例服务
     * 
     * @param string $abstract 抽象名称
     * @param mixed $concrete 具体实现
     */
    function todo_singleton($abstract, $concrete = null) {
        todo_container()->singleton($abstract, $concrete);
    }
}

if (!function_exists('todo_bind')) {
    /**
     * 绑定服务
     * 
     * @param string $abstract 抽象名称
     * @param mixed $concrete 具体实现
     * @param bool $shared 是否共享
     */
    function todo_bind($abstract, $concrete = null, $shared = false) {
        todo_container()->bind($abstract, $concrete, $shared);
    }
}