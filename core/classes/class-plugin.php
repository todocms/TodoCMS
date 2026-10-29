<?php
/**
 * TodoCMS 插件基类
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 插件基类
 * 
 * 所有插件都应该继承此类
 */
abstract class Todo_Plugin {
    
    /**
     * 插件名称
     * @var string
     */
    protected $name = '';
    
    /**
     * 插件版本
     * @var string
     */
    protected $version = '1.0.0';
    
    /**
     * 插件作者
     * @var string
     */
    protected $author = '';
    
    /**
     * 插件描述
     * @var string
     */
    protected $description = '';
    
    /**
     * 插件目录
     * @var string
     */
    protected $plugin_dir = '';
    
    /**
     * 插件URL
     * @var string
     */
    protected $plugin_url = '';
    
    /**
     * 插件文本域
     * @var string
     */
    protected $text_domain = '';
    
    /**
     * 插件选项
     * @var array
     */
    protected $options = array();
    
    /**
     * 构造函数
     *
     * @return void
     */
    public function __construct() {
        // 子类可以重写此方法
    }
    
    /**
     * 初始化插件
     * 
     * 子类必须实现此方法
     *
     * @return void
     */
    abstract public function init();
    
    /**
     * 激活插件时的操作
     *
     * @return void
     */
    public function activate() {
        // 默认为空，子类可以重写
    }
    
    /**
     * 停用插件时的操作
     *
     * @return void
     */
    public function deactivate() {
        // 默认为空，子类可以重写
    }
    
    /**
     * 卸载插件时的操作
     *
     * @return void
     */
    public function uninstall() {
        // 默认为空，子类可以重写
    }
    
    /**
     * 获取插件名称
     * 
     * @return string
     */
    public function get_name() {
        return $this->name;
    }
    
    /**
     * 获取插件版本
     * 
     * @return string
     */
    public function get_version() {
        return $this->version;
    }
    
    /**
     * 获取插件作者
     * 
     * @return string
     */
    public function get_author() {
        return $this->author;
    }
    
    /**
     * 获取插件描述
     * 
     * @return string
     */
    public function get_description() {
        return $this->description;
    }
    
    /**
     * 获取插件目录
     * 
     * @return string
     */
    public function get_plugin_dir() {
        return $this->plugin_dir;
    }
    
    /**
     * 获取插件URL
     * 
     * @return string
     */
    public function get_plugin_url() {
        return $this->plugin_url;
    }
    
    /**
     * 获取插件选项
     * 
     * @param string $key 选项键名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get_option($key = '', $default = null) {
        if (empty($key)) {
            return $this->options;
        }
        
        return isset($this->options[$key]) ? $this->options[$key] : $default;
    }
    
    /**
     * 设置插件选项
     * 
     * @param string $key 选项键名
     * @param mixed $value 选项值
     */
    public function set_option($key, $value) {
        $this->options[$key] = $value;
    }
    
    /**
     * 更新插件选项到数据库
     * 
     * @return bool
     */
    public function update_options() {
        return update_option($this->text_domain . '_options', $this->options);
    }
    
    /**
     * 加载插件选项
     */
    protected function load_options() {
        $this->options = get_option($this->text_domain . '_options', array());
    }
    
    /**
     * 添加动作钩子
     * 
     * @param string $hook_name 钩子名称
     * @param string $method_name 方法名称
     * @param int $priority 优先级
     * @param int $accepted_args 接受的参数数量
     */
    protected function add_action($hook_name, $method_name, $priority = 10, $accepted_args = 1) {
        todo_add_action($hook_name, array($this, $method_name), $priority, $accepted_args);
    }
    
    /**
     * 添加过滤器钩子
     * 
     * @param string $hook_name 钩子名称
     * @param string $method_name 方法名称
     * @param int $priority 优先级
     * @param int $accepted_args 接受的参数数量
     */
    protected function add_filter($hook_name, $method_name, $priority = 10, $accepted_args = 1) {
        todo_add_filter($hook_name, array($this, $method_name), $priority, $accepted_args);
    }
    
    /**
     * 记录日志
     * 
     * @param string $message 日志消息
     * @param string $level 日志级别
     */
    protected function log($message, $level = 'info') {
        if (defined('TODO_DEBUG') && TODO_DEBUG) {
            error_log(sprintf('[%s] %s: %s', strtoupper($level), $this->name, $message));
        }
    }
    
    /**
     * 获取插件信息
     * 
     * @return array
     */
    public function get_info() {
        return array(
            'name' => $this->name,
            'version' => $this->version,
            'author' => $this->author,
            'description' => $this->description,
            'plugin_dir' => $this->plugin_dir,
            'plugin_url' => $this->plugin_url,
            'text_domain' => $this->text_domain
        );
    }
}