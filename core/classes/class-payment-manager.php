<?php
/**
 * TodoCMS 支付管理器
 * 
 * 管理可扩展的支付接口系统，优先对接PAYJS
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    exit('Direct access not permitted');
}

class Todo_Payment_Manager {
    
    /**
     * 数据库连接
     * @var CMS_DB
     */
    private static $tododb;
    
    /**
     * 支付方式注册表
     * @var array
     */
    private static $payment_methods = array();
    
    /**
     * 支付状态常量
     */
    const STATUS_PENDING = 'pending';       // 待支付
    const STATUS_PROCESSING = 'processing'; // 处理中
    const STATUS_SUCCESS = 'success';       // 支付成功
    const STATUS_FAILED = 'failed';         // 支付失败
    const STATUS_CANCELLED = 'cancelled';   // 已取消
    const STATUS_REFUNDED = 'refunded';     // 已退款
    const STATUS_PARTIAL_REFUNDED = 'partial_refunded'; // 部分退款
    
    /**
     * 支付方式常量
     */
    const METHOD_PAYJS_WECHAT = 'payjs_wechat';
    const METHOD_PAYJS_ALIPAY = 'payjs_alipay';
    const METHOD_OFFLINE = 'offline';
    const METHOD_BALANCE = 'balance';
    
    /**
     * 初始化支付管理器
     */
    public static function init() {
        global $tododb;
        self::$tododb = $tododb;
        
        // 注册默认支付方式
        self::register_default_methods();
        
        // 加载支付接口
        self::load_payment_gateways();
    }
    
    /**
     * 注册支付方式
     * 
     * @param string $method_id 支付方式ID
     * @param array $method_config 支付方式配置
     */
    public static function register_method($method_id, $method_config) {
        $defaults = array(
            'name' => '',
            'description' => '',
            'icon' => '',
            'class' => '',
            'enabled' => true,
            'sort_order' => 0,
            'supports' => array('payment', 'refund'),
            'settings' => array()
        );
        
        self::$payment_methods[$method_id] = array_merge($defaults, $method_config);
    }
    
    /**
     * 注册默认支付方式
     */
    private static function register_default_methods() {
        // PAYJS微信支付
        self::register_method(self::METHOD_PAYJS_WECHAT, array(
            'name' => '微信支付',
            'description' => '使用PAYJS微信支付',
            'icon' => 'wechat',
            'class' => 'Todo_Payment_PAYJS',
            'method' => 'wechat',
            'supports' => array('payment', 'refund', 'query'),
            'sort_order' => 1
        ));
        
        // PAYJS支付宝
        self::register_method(self::METHOD_PAYJS_ALIPAY, array(
            'name' => '支付宝',
            'description' => '使用PAYJS支付宝',
            'icon' => 'alipay',
            'class' => 'Todo_Payment_PAYJS',
            'method' => 'alipay',
            'supports' => array('payment', 'refund', 'query'),
            'sort_order' => 2
        ));
        
        // 线下支付
        self::register_method(self::METHOD_OFFLINE, array(
            'name' => '线下支付',
            'description' => '银行转账、现金等线下支付方式',
            'icon' => 'bank',
            'class' => 'Todo_Payment_Offline',
            'supports' => array('payment'),
            'sort_order' => 10
        ));
        
        // 余额支付
        self::register_method(self::METHOD_BALANCE, array(
            'name' => '余额支付',
            'description' => '使用账户余额支付',
            'icon' => 'wallet',
            'class' => 'Todo_Payment_Balance',
            'supports' => array('payment'),
            'sort_order' => 5
        ));
    }
    
    /**
     * 加载支付网关
     */
    private static function load_payment_gateways() {
        $gateway_files = array(
            TODO_PATH . '/includes/payment/class-payjs.php',
            TODO_PATH . '/includes/payment/class-offline.php',
            TODO_PATH . '/includes/payment/class-balance.php'
        );
        
        foreach ($gateway_files as $file) {
            if (file_exists($file)) {
                require_once $file;
            }
        }
    }
    
    /**
     * 获取可用的支付方式
     * 
     * @param array $args 查询参数
     * @return array
     */
    public static function get_available_methods($args = array()) {
        $defaults = array(
            'enabled_only' => true,
            'order_amount' => 0,
            'user_id' => 0
        );
        
        $args = array_merge($defaults, $args);
        $methods = array();
        
        foreach (self::$payment_methods as $method_id => $method) {
            // 检查是否启用
            if ($args['enabled_only'] && !$method['enabled']) {
                continue;
            }
            
            // 检查是否支持当前订单金额
            if ($args['order_amount'] > 0) {
                $min_amount = self::get_method_setting($method_id, 'min_amount', 0);
                $max_amount = self::get_method_setting($method_id, 'max_amount', 0);
                
                if ($min_amount > 0 && $args['order_amount'] < $min_amount) {
                    continue;
                }
                
                if ($max_amount > 0 && $args['order_amount'] > $max_amount) {
                    continue;
                }
            }
            
            // 余额支付需要检查用户余额
            if ($method_id === self::METHOD_BALANCE && $args['user_id'] > 0) {
                $user_balance = self::get_user_balance($args['user_id']);
                if ($user_balance < $args['order_amount']) {
                    continue;
                }
            }
            
            $methods[$method_id] = $method;
        }
        
        // 按排序字段排序
        uasort($methods, function($a, $b) {
            return $a['sort_order'] - $b['sort_order'];
        });
        
        return $methods;
    }
    
    /**
     * 获取支付方式配置
     * 
     * @param string $method_id 支付方式ID
     * @return array|null
     */
    public static function get_method($method_id) {
        return isset(self::$payment_methods[$method_id]) ? self::$payment_methods[$method_id] : null;
    }
    
    /**
     * 获取支付方式名称
     * 
     * @param string $method_id 支付方式ID
     * @return string
     */
    public static function get_method_name($method_id) {
        $method = self::get_method($method_id);
        return $method ? $method['name'] : '未知支付方式';
    }
    
    /**
     * 获取支付方式设置
     * 
     * @param string $method_id 支付方式ID
     * @param string $setting_key 设置键
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get_method_setting($method_id, $setting_key, $default = null) {
        $method = self::get_method($method_id);
        if (!$method || !isset($method['settings'][$setting_key])) {
            return $default;
        }
        
        return $method['settings'][$setting_key];
    }
    
    /**
     * 获取支付方式所有设置
     * 
     * @param string $method_id 支付方式ID
     * @return array
     */
    public static function get_method_settings($method_id) {
        $method = self::get_method($method_id);
        if (!$method || !isset($method['settings'])) {
            return array();
        }
        
        return $method['settings'];
    }
    
    /**
     * 创建支付
     * 
     * @param int $order_id 订单ID
     * @param string $method_id 支付方式ID
     * @param array $extra_data 额外数据
     * @return array
     */
    public static function create_payment($order_id, $method_id, $extra_data = array()) {
        // 获取订单信息
        $order = Todo_Order_Manager::get_order($order_id);
        if (!$order) {
            return array(
                'success' => false,
                'message' => '订单不存在'
            );
        }
        
        // 检查订单状态
        if ($order['payment_status'] === Todo_Order_Manager::PAYMENT_PAID) {
            return array(
                'success' => false,
                'message' => '订单已支付'
            );
        }
        
        if ($order['status'] === Todo_Order_Manager::STATUS_CANCELLED) {
            return array(
                'success' => false,
                'message' => '订单已取消'
            );
        }
        
        // 获取支付方式
        $method = self::get_method($method_id);
        if (!$method || !$method['enabled']) {
            return array(
                'success' => false,
                'message' => '支付方式不可用'
            );
        }
        
        // 创建支付记录
        $payment_id = self::create_payment_record($order_id, $method_id, $order['total_amount']);
        if (!$payment_id) {
            return array(
                'success' => false,
                'message' => '创建支付记录失败'
            );
        }
        
        // 调用支付网关
        if (!empty($method['class']) && class_exists($method['class'])) {
            $gateway = new $method['class']();
            
            if (method_exists($gateway, 'create_payment')) {
                $payment_method = isset($method['method']) ? $method['method'] : '';
                $result = $gateway->create_payment($order_id, $payment_method);
                
                if ($result['success']) {
                    // 更新支付记录
                    self::update_payment_record($payment_id, array(
                        'gateway_order_id' => $result['gateway_order_id'] ?? '',
                        'gateway_data' => json_encode($result['data'] ?? array()),
                        'status' => self::STATUS_PROCESSING
                    ));
                    
                    $result['payment_id'] = $payment_id;
                    return $result;
                } else {
                    // 更新支付记录为失败
                    self::update_payment_record($payment_id, array(
                        'status' => self::STATUS_FAILED,
                        'failure_reason' => $result['message'] ?? '支付创建失败'
                    ));
                    
                    return $result;
                }
            }
        }
        
        return array(
            'success' => false,
            'message' => '支付网关不可用'
        );
    }
    
    /**
     * 处理支付回调
     * 
     * @param string $method_id 支付方式ID
     * @param array $callback_data 回调数据
     * @return array
     */
    public static function handle_payment_callback($method_id, $callback_data) {
        $method = self::get_method($method_id);
        if (!$method || empty($method['class'])) {
            return array(
                'success' => false,
                'message' => '支付方式不存在'
            );
        }
        
        if (!class_exists($method['class'])) {
            return array(
                'success' => false,
                'message' => '支付网关类不存在'
            );
        }
        
        $gateway = new $method['class']();
        if (!method_exists($gateway, 'handle_callback')) {
            return array(
                'success' => false,
                'message' => '支付网关不支持回调处理'
            );
        }
        
        return $gateway->handle_callback($callback_data);
    }
    
    /**
     * 查询支付状态
     * 
     * @param int $payment_id 支付记录ID
     * @return array
     */
    public static function query_payment_status($payment_id) {
        $payment = self::get_payment_record($payment_id);
        if (!$payment) {
            return array(
                'success' => false,
                'message' => '支付记录不存在'
            );
        }
        
        $method = self::get_method($payment['method_id']);
        if (!$method || empty($method['class'])) {
            return array(
                'success' => false,
                'message' => '支付方式不存在'
            );
        }
        
        if (!class_exists($method['class'])) {
            return array(
                'success' => false,
                'message' => '支付网关类不存在'
            );
        }
        
        $gateway = new $method['class']();
        if (!method_exists($gateway, 'query_payment')) {
            return array(
                'success' => false,
                'message' => '支付网关不支持状态查询'
            );
        }
        
        return $gateway->query_payment($payment['gateway_order_id']);
    }
    
    /**
     * 处理退款
     * 
     * @param int $order_id 订单ID
     * @param float $refund_amount 退款金额
     * @param string $refund_reason 退款原因
     * @return array
     */
    public static function process_refund($order_id, $refund_amount, $refund_reason = '') {
        // 获取订单信息
        $order = Todo_Order_Manager::get_order($order_id);
        if (!$order) {
            return array(
                'success' => false,
                'message' => '订单不存在'
            );
        }
        
        // 检查订单是否已支付
        if ($order['payment_status'] !== Todo_Order_Manager::PAYMENT_PAID) {
            return array(
                'success' => false,
                'message' => '订单未支付，无法退款'
            );
        }
        
        // 检查退款金额
        if ($refund_amount <= 0) {
            return array(
                'success' => false,
                'message' => '退款金额必须大于0'
            );
        }
        
        if ($refund_amount > $order['total_amount']) {
            return array(
                'success' => false,
                'message' => '退款金额不能超过订单金额'
            );
        }
        
        // 获取支付方式
        $method = self::get_method($order['payment_method']);
        if (!$method) {
            return array(
                'success' => false,
                'message' => '支付方式不存在'
            );
        }
        
        // 检查是否支持退款
        if (!in_array('refund', $method['supports'])) {
            return array(
                'success' => false,
                'message' => '该支付方式不支持退款'
            );
        }
        
        // 开始事务
        self::$tododb->begin_transaction();
        
        try {
            // 创建退款记录
            $refund_id = self::create_refund_record($order_id, $refund_amount, $refund_reason);
            if (!$refund_id) {
                throw new Exception('创建退款记录失败');
            }
            
            // 调用支付网关退款
            if (!empty($method['class']) && class_exists($method['class'])) {
                $gateway = new $method['class']();
                
                if (method_exists($gateway, 'process_refund')) {
                    $result = $gateway->process_refund($order_id, $refund_amount, $refund_reason);
                    
                    if ($result['success']) {
                        // 更新退款记录
                        self::update_refund_record($refund_id, array(
                            'status' => self::STATUS_SUCCESS,
                            'gateway_refund_id' => $result['gateway_refund_id'] ?? '',
                            'gateway_data' => json_encode($result['data'] ?? array()),
                            'processed_at' => date('Y-m-d H:i:s')
                        ));
                        
                        // 更新订单状态
                        $payment_status = ($refund_amount >= $order['total_amount']) ? 
                            Todo_Order_Manager::PAYMENT_REFUNDED : 
                            Todo_Order_Manager::PAYMENT_PARTIAL_REFUNDED;
                        
                        Todo_Order_Manager::update_order($order_id, array(
                            'payment_status' => $payment_status
                        ));
                        
                        // 恢复商品库存
                        if ($refund_amount >= $order['total_amount']) {
                            self::restore_product_stock($order_id);
                        }
                        
                        // 记录订单日志
                        $refund_fmt = function_exists('todo_format_admin_price') ? todo_format_admin_price((float)$refund_amount) : number_format((float)$refund_amount, 2);
                        $log_message = "退款成功，退款金额：{$refund_fmt}";
                        if (!empty($refund_reason)) {
                            $log_message .= "，退款原因：{$refund_reason}";
                        }
                        Todo_Order_Manager::add_order_log($order_id, 'refund_success', $log_message, todo_get_current_user_id());
                        
                        // 提交事务
                        self::$tododb->commit();
                        
                        return array(
                            'success' => true,
                            'message' => '退款处理成功',
                            'refund_id' => $refund_id,
                            'gateway_refund_id' => $result['gateway_refund_id'] ?? ''
                        );
                    } else {
                        // 更新退款记录为失败
                        self::update_refund_record($refund_id, array(
                            'status' => self::STATUS_FAILED,
                            'failure_reason' => $result['message'] ?? '退款处理失败'
                        ));
                        
                        throw new Exception($result['message'] ?? '退款处理失败');
                    }
                } else {
                    // 对于不支持自动退款的支付方式，标记为人工处理
                    self::update_refund_record($refund_id, array(
                        'status' => self::STATUS_PROCESSING,
                        'failure_reason' => '需要人工处理退款'
                    ));
                    
                    // 记录订单日志
                    $refund_fmt = function_exists('todo_format_admin_price') ? todo_format_admin_price((float)$refund_amount) : number_format((float)$refund_amount, 2);
                    $log_message = "退款申请已提交，需要人工处理，退款金额：{$refund_fmt}";
                    if (!empty($refund_reason)) {
                        $log_message .= "，退款原因：{$refund_reason}";
                    }
                    Todo_Order_Manager::add_order_log($order_id, 'refund_pending', $log_message, todo_get_current_user_id());
                    
                    // 提交事务
                    self::$tododb->commit();
                    
                    return array(
                        'success' => true,
                        'message' => '退款申请已提交，需要人工处理',
                        'refund_id' => $refund_id,
                        'manual_process' => true
                    );
                }
            } else {
                throw new Exception('支付网关不可用');
            }
            
        } catch (Exception $e) {
            // 回滚事务
            self::$tododb->rollback();
            todo_log_error('Refund processing failed: ' . $e->getMessage());
            
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }

    /**
     * 处理支付成功
     * 
     * @param int $order_id 订单ID
     * @param array $payment_data 支付数据
     * @return bool
     */
    public static function handle_payment_success($order_id, $payment_data = array()) {
        // 开始事务
        self::$tododb->begin_transaction();
        
        try {
            // 更新订单支付状态
            $update_data = array(
                'payment_status' => Todo_Order_Manager::PAYMENT_PAID,
                'paid_amount' => $payment_data['amount'] ?? 0,
                'payment_method' => $payment_data['method'] ?? '',
                'paid_at' => date('Y-m-d H:i:s')
            );
            
            // 如果是待付款状态，更新为已付款
            $order = Todo_Order_Manager::get_order($order_id);
            if ($order && $order['status'] === Todo_Order_Manager::STATUS_PENDING) {
                $update_data['status'] = Todo_Order_Manager::STATUS_PAID;
            }
            
            if (!Todo_Order_Manager::update_order($order_id, $update_data)) {
                throw new Exception('更新订单状态失败');
            }
            
            // 更新支付记录
            if (!empty($payment_data['payment_id'])) {
                self::update_payment_record($payment_data['payment_id'], array(
                    'status' => self::STATUS_SUCCESS,
                    'paid_at' => date('Y-m-d H:i:s'),
                    'gateway_data' => json_encode($payment_data)
                ));
            }
            
            // 减少商品库存
            self::reduce_product_stock($order_id);
            
            // 记录订单日志
            Todo_Order_Manager::add_order_log($order_id, 'payment_success', '支付成功', 0, $payment_data);
            
            // 发送支付成功通知
            self::send_payment_success_notification($order_id);
            
            // 提交事务
            self::$tododb->commit();
            
            return true;
            
        } catch (Exception $e) {
            // 回滚事务
            self::$tododb->rollback();
            todo_log_error('Payment success handling failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 创建支付记录
     * 
     * @param int $order_id 订单ID
     * @param string $method_id 支付方式ID
     * @param float $amount 支付金额
     * @return int|false
     */
    private static function create_payment_record($order_id, $method_id, $amount) {
        $query = "INSERT INTO " . DB_PREFIX . "payments 
                  (order_id, method_id, amount, status, created_at) 
                  VALUES (?, ?, ?, ?, NOW())";
        
        $result = self::$tododb->query($query, array($order_id, $method_id, $amount, self::STATUS_PENDING));
        
        if ($result) {
            return self::$tododb->insert_id();
        }
        
        return false;
    }
    
    /**
     * 更新支付记录
     * 
     * @param int $payment_id 支付记录ID
     * @param array $data 更新数据
     * @return bool
     */
    private static function update_payment_record($payment_id, $data) {
        $set_clauses = array();
        $params = array();
        $types = '';
        
        $updatable_fields = array(
            'status' => 's',
            'gateway_order_id' => 's',
            'gateway_data' => 's',
            'failure_reason' => 's',
            'paid_at' => 's'
        );
        
        foreach ($updatable_fields as $field => $type) {
            if (isset($data[$field])) {
                $set_clauses[] = "{$field} = ?";
                $params[] = $data[$field];
                $types .= $type;
            }
        }
        
        if (empty($set_clauses)) {
            return false;
        }
        
        $set_clauses[] = 'updated_at = NOW()';
        $params[] = $payment_id;
        $types .= 'i';
        
        $query = "UPDATE " . DB_PREFIX . "payments SET " . implode(', ', $set_clauses) . " WHERE id = ?";
        return self::$tododb->query($query, $params);
    }
    
    /**
     * 获取支付记录
     * 
     * @param int $payment_id 支付记录ID
     * @return array|null
     */
    private static function get_payment_record($payment_id) {
        $query = "SELECT * FROM " . DB_PREFIX . "payments WHERE id = ?";
        
        return self::$tododb->get_row($query, array($payment_id), ARRAY_A);
    }
    
    /**
     * 减少商品库存
     * 
     * @param int $order_id 订单ID
     * @return bool
     */
    private static function reduce_product_stock($order_id) {
        $order_items = Todo_Order_Manager::get_order_items($order_id);
        
        foreach ($order_items as $item) {
            // 减少商品库存
            if ($item['spec_id'] > 0) {
                // 有规格的商品
                $query = "UPDATE " . DB_PREFIX . "product_specs 
                          SET stock = stock - ? 
                          WHERE id = ? AND stock >= ?";
                $result = self::$tododb->query($query, $item['quantity'], $item['spec_id'], $item['quantity']);
            } else {
                // 无规格的商品
                $query = "UPDATE " . DB_PREFIX . "posts 
                          SET stock = stock - ? 
                          WHERE ID = ? AND stock >= ?";
                $result = self::$tododb->query($query, $item['quantity'], $item['product_id'], $item['quantity']);
            }
            
            if (!$result) {
                todo_log_error("Failed to reduce stock for product {$item['product_id']}");
            }
        }
        
        return true;
    }
    
    /**
     * 发送支付成功通知
     * 
     * @param int $order_id 订单ID
     */
    private static function send_payment_success_notification($order_id) {
        // 这里可以实现邮件通知、短信通知等
        // 暂时记录日志
        todo_log_info("Payment success notification sent for order {$order_id}");
    }
    
    /**
     * 获取用户余额
     * 
     * @param int $user_id 用户ID
     * @return float
     */
    public static function get_user_balance($user_id) {
        $query = "SELECT balance FROM " . DB_PREFIX . "users WHERE id = ?";
        
        return floatval(self::$tododb->get_var($query, array($user_id)));
        
        if ($row = $result->fetch_assoc()) {
            return floatval($row['balance']);
        }
        
        return 0.0;
    }
    
    /**
     * 创建退款记录
     * 
     * @param int $order_id 订单ID
     * @param float $refund_amount 退款金额
     * @param string $refund_reason 退款原因
     * @return int|false 退款记录ID
     */
    private static function create_refund_record($order_id, $refund_amount, $refund_reason = '') {
        $data = array(
            'order_id' => $order_id,
            'refund_amount' => $refund_amount,
            'refund_reason' => $refund_reason,
            'status' => self::STATUS_PROCESSING,
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => todo_get_current_user_id()
        );
        
        return self::$tododb->insert('refunds', $data);
    }
    
    /**
     * 更新退款记录
     * 
     * @param int $refund_id 退款记录ID
     * @param array $data 更新数据
     * @return bool
     */
    private static function update_refund_record($refund_id, $data) {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return self::$tododb->update('refunds', $data, array('id' => $refund_id));
    }
    
    /**
     * 恢复商品库存
     * 
     * @param int $order_id 订单ID
     * @return bool
     */
    private static function restore_product_stock($order_id) {
        // 获取订单商品
        $order_items = self::$tododb->get_results(
            "SELECT product_id, quantity FROM order_items WHERE order_id = ?",
            [$order_id],
            'ARRAY_A'
        );
        
        if (!$order_items) {
            return false;
        }
        
        foreach ($order_items as $item) {
            // 更新库存
            self::$tododb->query(
                "UPDATE posts SET stock = stock + ? WHERE id = ?",
                [$item['quantity'], $item['product_id']]
            );
        }
        
        return true;
    }

    /**
     * 获取状态名称
     * 
     * @param string $status 状态代码
     * @return string
     */
    public static function get_status_name($status) {
        $status_names = array(
            self::STATUS_PENDING => '待支付',
            self::STATUS_PROCESSING => '处理中',
            self::STATUS_SUCCESS => '支付成功',
            self::STATUS_FAILED => '支付失败',
            self::STATUS_CANCELLED => '已取消',
            self::STATUS_REFUNDED => '已退款',
            self::STATUS_PARTIAL_REFUNDED => '部分退款'
        );
        
        return isset($status_names[$status]) ? $status_names[$status] : '未知状态';
    }
}