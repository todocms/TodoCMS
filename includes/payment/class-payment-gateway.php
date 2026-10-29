<?php
/**
 * TodoCMS 支付网关基类
 * 
 * 为所有支付网关提供统一的接口和基础功能
 * 
 * @package TodoCMS
 * @subpackage Payment
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    exit('Direct access not permitted');
}

/**
 * 支付网关抽象基类
 */
abstract class Todo_Payment_Gateway {
    
    /**
     * 网关ID
     * @var string
     */
    protected $id;
    
    /**
     * 网关名称
     * @var string
     */
    protected $title;
    
    /**
     * 网关描述
     * @var string
     */
    protected $description;
    
    /**
     * 是否启用
     * @var bool
     */
    protected $enabled = false;
    
    /**
     * 网关设置
     * @var array
     */
    protected $settings = array();
    
    /**
     * 支持的功能
     * @var array
     */
    protected $supports = array();
    
    /**
     * 构造函数
     */
    public function __construct() {
        $this->init();
        $this->load_settings();
    }
    
    /**
     * 初始化网关
     */
    protected function init() {
        // 子类实现
    }
    
    /**
     * 加载设置
     */
    protected function load_settings() {
        $this->settings = get_option($this->get_option_key(), array());
        $this->enabled = isset($this->settings['enabled']) ? $this->settings['enabled'] : false;
    }
    
    /**
     * 获取设置选项键名
     * @return string
     */
    protected function get_option_key() {
        return 'todo_payment_' . $this->id . '_settings';
    }
    
    /**
     * 获取网关ID
     * @return string
     */
    public function get_id() {
        return $this->id;
    }
    
    /**
     * 获取网关标题
     * @return string
     */
    public function get_title() {
        return $this->title;
    }
    
    /**
     * 获取网关描述
     * @return string
     */
    public function get_description() {
        return $this->description;
    }
    
    /**
     * 是否启用
     * @return bool
     */
    public function is_enabled() {
        return $this->enabled;
    }
    
    /**
     * 获取设置值
     * @param string $key 设置键名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get_setting($key, $default = null) {
        return isset($this->settings[$key]) ? $this->settings[$key] : $default;
    }
    
    /**
     * 设置配置值
     * @param string $key 设置键名
     * @param mixed $value 设置值
     */
    public function set_setting($key, $value) {
        $this->settings[$key] = $value;
    }
    
    /**
     * 保存设置
     * @return bool
     */
    public function save_settings() {
        return update_option($this->get_option_key(), $this->settings);
    }
    
    /**
     * 检查是否支持某个功能
     * @param string $feature 功能名称
     * @return bool
     */
    public function supports($feature) {
        return in_array($feature, $this->supports);
    }
    
    /**
     * 验证配置
     * @return bool
     */
    abstract public function validate_config();
    
    /**
     * 处理支付请求
     * @param array $order_data 订单数据
     * @return array 支付响应
     */
    abstract public function process_payment($order_data);
    
    /**
     * 处理支付回调
     * @param array $callback_data 回调数据
     * @return array 处理结果
     */
    abstract public function process_callback($callback_data);
    
    /**
     * 查询支付状态
     * @param string $transaction_id 交易ID
     * @return array 查询结果
     */
    public function query_payment($transaction_id) {
        return array(
            'success' => false,
            'message' => '该支付网关不支持状态查询'
        );
    }
    
    /**
     * 关闭订单
     * @param string $transaction_id 交易ID
     * @return array 关闭结果
     */
    public function close_order($transaction_id) {
        return array(
            'success' => false,
            'message' => '该支付网关不支持关闭订单'
        );
    }
    
    /**
     * 获取支付表单HTML
     * @param array $order_data 订单数据
     * @return string HTML内容
     */
    public function get_payment_form($order_data) {
        return '';
    }
    
    /**
     * 获取管理设置表单字段
     * @return array 表单字段配置
     */
    public function get_form_fields() {
        return array();
    }
    
    /**
     * 记录日志
     * @param string $message 日志消息
     * @param string $level 日志级别
     */
    protected function log($message, $level = 'info') {
        if (function_exists('todo_log')) {
            todo_log($message, $level, 'payment-' . $this->id);
        }
    }
    
    /**
     * 生成随机字符串
     * @param int $length 长度
     * @return string
     */
    protected function generate_random_string($length = 32) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $randomString;
    }
    
    /**
     * 验证回调签名
     * @param array $data 回调数据
     * @param string $signature 签名
     * @return bool
     */
    protected function verify_signature($data, $signature) {
        // 子类实现具体的签名验证逻辑
        return false;
    }
    
    /**
     * 格式化金额
     * @param float $amount 金额
     * @return string 格式化后的金额
     */
    protected function format_amount($amount) {
        return number_format($amount, 2, '.', '');
    }
    
    /**
     * 获取回调URL
     * @return string
     */
    protected function get_callback_url() {
        return todo_get_site_url() . '/includes/payment/' . $this->id . '-notify.php';
    }
    
    /**
     * 获取返回URL
     * @param int $order_id 订单ID
     * @return string
     */
    protected function get_return_url($order_id = 0) {
        $url = todo_get_site_url() . '/order-success';
        if ($order_id) {
            $url .= '?order_id=' . $order_id;
        }
        return $url;
    }
    
    /**
     * 获取取消URL
     * @param int $order_id 订单ID
     * @return string
     */
    protected function get_cancel_url($order_id = 0) {
        $url = todo_get_site_url() . '/order-cancel';
        if ($order_id) {
            $url .= '?order_id=' . $order_id;
        }
        return $url;
    }
}

/**
 * 支付网关管理器
 */
class Todo_Payment_Gateway_Manager {
    
    /**
     * 已注册的支付网关
     * @var array
     */
    private static $gateways = array();
    
    /**
     * 注册支付网关
     * @param string $id 网关ID
     * @param string $class_name 网关类名
     */
    public static function register_gateway($id, $class_name) {
        self::$gateways[$id] = $class_name;
    }
    
    /**
     * 获取支付网关实例
     * @param string $id 网关ID
     * @return Todo_Payment_Gateway|null
     */
    public static function get_gateway($id) {
        if (isset(self::$gateways[$id])) {
            $class_name = self::$gateways[$id];
            if (class_exists($class_name)) {
                return new $class_name();
            }
        }
        return null;
    }
    
    /**
     * 获取所有已注册的网关
     * @return array
     */
    public static function get_all_gateways() {
        $gateways = array();
        foreach (self::$gateways as $id => $class_name) {
            if (class_exists($class_name)) {
                $gateways[$id] = new $class_name();
            }
        }
        return $gateways;
    }
    
    /**
     * 获取启用的网关
     * @return array
     */
    public static function get_enabled_gateways() {
        $enabled_gateways = array();
        $all_gateways = self::get_all_gateways();
        
        foreach ($all_gateways as $id => $gateway) {
            if ($gateway->is_enabled()) {
                $enabled_gateways[$id] = $gateway;
            }
        }
        
        return $enabled_gateways;
    }
}
