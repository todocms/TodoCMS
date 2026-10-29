<?php
/**
 * PayJS支付插件核心类
 *
 * 实现PayJS支付接口调用和订单管理
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * PayJS支付管理类
 */
class Todo_PayJS_Payment {
    
    /**
     * PayJS API配置
     */
    private $mchid;           // 商户号
    private $key;             // 通信密钥
    private $api_url;         // API地址
    private $notify_url;      // 回调地址
    
    /**
     * 构造函数
     */
    public function __construct() {
        $this->mchid = todo_get_option('payjs_mchid', '');
        $this->key = todo_get_option('payjs_key', '');
        $this->api_url = 'https://payjs.cn/api/native';
        $this->notify_url = todo_admin_url('ajax/payjs-notify.php');
    }
    
    /**
     * 验证配置是否完整
     * 
     * @return bool
     */
    public function is_configured() {
        return !empty($this->mchid) && !empty($this->key);
    }
    
    /**
     * 创建支付订单
     * 
     * @param array $order_data 订单数据
     * @return array 支付结果
     */
    public function create_payment($order_data) {
        try {
            // 验证配置
            if (!$this->is_configured()) {
                return [
                    'success' => false,
                    'message' => 'PayJS支付未配置或配置不完整'
                ];
            }
            
            // 验证订单数据
            $validation = $this->validate_order_data($order_data);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => $validation['message']
                ];
            }
            
            // 构建支付参数
            $pay_data = [
                'mchid' => $this->mchid,
                'out_trade_no' => $order_data['order_no'],
                'total_fee' => intval($order_data['amount'] * 100), // 转换为分
                'body' => $order_data['subject'],
                'notify_url' => $this->notify_url,
                'attach' => json_encode([
                    'user_id' => $order_data['user_id'],
                    'package_id' => $order_data['package_id'],
                    'order_type' => 'upgrade_package'
                ])
            ];
            
            // 生成签名
            $pay_data['sign'] = $this->generate_sign($pay_data);
            
            // 发送请求
            $response = $this->send_request($this->api_url, $pay_data);
            
            if ($response['success']) {
                $result = json_decode($response['data'], true);
                
                if ($result && $result['return_code'] == 1) {
                    return [
                        'success' => true,
                        'data' => [
                            'payjs_order_id' => $result['payjs_order_id'],
                            'qrcode' => $result['qrcode'],
                            'code_url' => $result['code_url'],
                            'order_no' => $order_data['order_no']
                        ]
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => $result['return_msg'] ?? '创建支付订单失败'
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'message' => '网络错误: ' . $response['message']
                ];
            }
            
        } catch (Exception $e) {
            todo_log_error('PayJS创建支付订单异常', [
                'error' => $e->getMessage(),
                'order_data' => $order_data
            ]);
            
            return [
                'success' => false,
                'message' => '系统异常，请稍后重试'
            ];
        }
    }
    
    /**
     * 查询订单状态
     * 
     * @param string $payjs_order_id PayJS订单ID
     * @return array 查询结果
     */
    public function query_order($payjs_order_id) {
        try {
            $query_data = [
                'payjs_order_id' => $payjs_order_id
            ];
            
            $query_data['sign'] = $this->generate_sign($query_data);
            
            $response = $this->send_request('https://payjs.cn/api/check', $query_data);
            
            if ($response['success']) {
                $result = json_decode($response['data'], true);
                
                if ($result && $result['return_code'] == 1) {
                    return [
                        'success' => true,
                        'data' => $result
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => $result['return_msg'] ?? '查询订单失败'
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'message' => '网络错误: ' . $response['message']
                ];
            }
            
        } catch (Exception $e) {
            todo_log_error('PayJS查询订单异常', [
                'error' => $e->getMessage(),
                'payjs_order_id' => $payjs_order_id
            ]);
            
            return [
                'success' => false,
                'message' => '系统异常，请稍后重试'
            ];
        }
    }
    
    /**
     * 关闭订单
     * 
     * @param string $payjs_order_id PayJS订单ID
     * @return array 关闭结果
     */
    public function close_order($payjs_order_id) {
        try {
            $close_data = [
                'payjs_order_id' => $payjs_order_id
            ];
            
            $close_data['sign'] = $this->generate_sign($close_data);
            
            $response = $this->send_request('https://payjs.cn/api/close', $close_data);
            
            if ($response['success']) {
                $result = json_decode($response['data'], true);
                
                return [
                    'success' => $result && $result['return_code'] == 1,
                    'message' => $result['return_msg'] ?? '关闭订单操作完成'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '网络错误: ' . $response['message']
                ];
            }
            
        } catch (Exception $e) {
            todo_log_error('PayJS关闭订单异常', [
                'error' => $e->getMessage(),
                'payjs_order_id' => $payjs_order_id
            ]);
            
            return [
                'success' => false,
                'message' => '系统异常，请稍后重试'
            ];
        }
    }
    
    /**
     * 验证订单数据
     * 
     * @param array $order_data 订单数据
     * @return array 验证结果
     */
    private function validate_order_data($order_data) {
        $required_fields = ['order_no', 'amount', 'subject', 'user_id', 'package_id'];
        
        foreach ($required_fields as $field) {
            if (empty($order_data[$field])) {
                return [
                    'valid' => false,
                    'message' => "缺少必需字段: {$field}"
                ];
            }
        }
        
        // 验证金额
        if (!is_numeric($order_data['amount']) || $order_data['amount'] <= 0) {
            return [
                'valid' => false,
                'message' => '订单金额无效'
            ];
        }
        
        // 验证订单号格式
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $order_data['order_no'])) {
            return [
                'valid' => false,
                'message' => '订单号格式无效'
            ];
        }
        
        return [
            'valid' => true,
            'message' => '验证通过'
        ];
    }
    
    /**
     * 生成签名
     * 
     * @param array $data 待签名数据
     * @return string 签名
     */
    private function generate_sign($data) {
        // 移除sign字段
        unset($data['sign']);
        
        // 按键名排序
        ksort($data);
        
        // 构建签名字符串
        $sign_string = '';
        foreach ($data as $key => $value) {
            if ($value !== '' && $key !== 'sign') {
                $sign_string .= $key . '=' . $value . '&';
            }
        }
        $sign_string .= 'key=' . $this->key;
        
        // 生成MD5签名并转大写
        return strtoupper(md5($sign_string));
    }
    
    /**
     * 发送HTTP请求
     * 
     * @param string $url 请求URL
     * @param array $data 请求数据
     * @return array 响应结果
     */
    private function send_request($url, $data) {
        try {
            $ch = curl_init();
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'User-Agent: TodoCMS-PayJS/1.0'
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            curl_close($ch);
            
            if ($error) {
                return [
                    'success' => false,
                    'message' => 'CURL错误: ' . $error
                ];
            }
            
            if ($http_code !== 200) {
                return [
                    'success' => false,
                    'message' => 'HTTP错误: ' . $http_code
                ];
            }
            
            return [
                'success' => true,
                'data' => $response
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '请求异常: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 验证回调签名
     * 
     * @param array $data 回调数据
     * @return bool 验证结果
     */
    public function verify_notify_sign($data) {
        if (empty($data['sign'])) {
            return false;
        }
        
        $received_sign = $data['sign'];
        $calculated_sign = $this->generate_sign($data);
        
        return strtoupper($received_sign) === strtoupper($calculated_sign);
    }
    
    /**
     * 获取支付方式信息
     * 
     * @return array 支付方式信息
     */
    public function get_payment_info() {
        return [
            'id' => 'payjs',
            'name' => 'PayJS微信支付',
            'description' => '通过PayJS接口实现微信扫码支付',
            'icon' => 'fa-wechat',
            'enabled' => $this->is_configured(),
            'config_url' => todo_admin_url('settings.php?tab=payment#payjs')
        ];
    }
    
    /**
     * 获取配置表单字段
     * 
     * @return array 配置字段
     */
    public static function get_config_fields() {
        return [
            'payjs_mchid' => [
                'title' => 'PayJS商户号',
                'type' => 'text',
                'description' => '在PayJS后台获取的商户号',
                'required' => true
            ],
            'payjs_key' => [
                'title' => 'PayJS通信密钥',
                'type' => 'password',
                'description' => '在PayJS后台获取的通信密钥',
                'required' => true
            ],
            'payjs_enabled' => [
                'title' => '启用PayJS支付',
                'type' => 'switch',
                'description' => '是否启用PayJS微信支付功能',
                'default' => false
            ]
        ];
    }
}

/**
 * 获取PayJS支付管理器实例
 * 
 * @return Todo_PayJS_Payment
 */
function todo_get_payjs_payment() {
    static $instance = null;
    
    if ($instance === null) {
        $instance = new Todo_PayJS_Payment();
    }
    
    return $instance;
}
