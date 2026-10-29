<?php
/**
 * TodoCMS PAYJS支付接口
 * 
 * 支持微信支付和支付宝支付
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    exit('Direct access not permitted');
}

class Todo_Payment_PAYJS {
    
    /**
     * PAYJS API配置
     */
    private $mchid;
    private $key;
    private $api_url = 'https://payjs.cn/api/';
    
    /**
     * 支付方式
     */
    const METHOD_WECHAT = 'wechat';
    const METHOD_ALIPAY = 'alipay';
    
    /**
     * 构造函数
     */
    public function __construct() {
        $this->mchid = todo_get_option('payjs_mchid', '');
        $this->key = todo_get_option('payjs_key', '');
    }
    
    /**
     * 创建支付
     * 
     * @param int $order_id 订单ID
     * @param string $method 支付方式 (wechat/alipay)
     * @return array
     */
    public function create_payment($order_id, $method = self::METHOD_WECHAT) {
        // 获取订单信息
        $order = Todo_Order_Manager::get_order($order_id);
        if (!$order) {
            return array(
                'success' => false,
                'message' => '订单不存在'
            );
        }
        
        // 检查配置
        if (empty($this->mchid) || empty($this->key)) {
            return array(
                'success' => false,
                'message' => 'PAYJS配置不完整'
            );
        }
        
        // 准备支付参数
        $params = array(
            'mchid' => $this->mchid,
            'total_fee' => intval($order['total_amount'] * 100), // 转换为分
            'out_trade_no' => $order['order_no'],
            'body' => $this->get_order_description($order),
            'notify_url' => $this->get_notify_url(),
            'callback_url' => $this->get_return_url($order_id)
        );
        
        // 根据支付方式选择API端点
        $endpoint = ($method === self::METHOD_ALIPAY) ? 'cashier' : 'native';
        
        // 生成签名
        $params['sign'] = $this->generate_sign($params);
        
        // 发送请求
        $response = $this->send_request($endpoint, $params);
        
        if (!$response) {
            return array(
                'success' => false,
                'message' => '支付接口请求失败'
            );
        }
        
        // 解析响应
        $result = json_decode($response, true);
        
        if (!$result) {
            return array(
                'success' => false,
                'message' => '支付接口响应格式错误'
            );
        }
        
        if ($result['return_code'] != 1) {
            return array(
                'success' => false,
                'message' => $result['return_msg'] ?? '支付创建失败'
            );
        }
        
        // 返回成功结果
        $payment_data = array(
            'gateway_order_id' => $result['payjs_order_id'],
            'qr_code' => $result['qrcode'] ?? '',
            'code_url' => $result['code_url'] ?? '',
            'cashier_url' => $result['cashier'] ?? '',
            'method' => $method
        );
        
        return array(
            'success' => true,
            'gateway_order_id' => $result['payjs_order_id'],
            'data' => $payment_data,
            'message' => '支付创建成功'
        );
    }
    
    /**
     * 处理支付回调
     * 
     * @param array $callback_data 回调数据
     * @return array
     */
    public function handle_callback($callback_data = null) {
        // 如果没有传入数据，从POST获取
        if ($callback_data === null) {
            $callback_data = $_POST;
        }
        
        // 验证必要参数
        $required_fields = array('payjs_order_id', 'out_trade_no', 'total_fee', 'sign');
        foreach ($required_fields as $field) {
            if (!isset($callback_data[$field])) {
                return array(
                    'success' => false,
                    'message' => "缺少必要参数: {$field}"
                );
            }
        }
        
        // 验证签名
        if (!$this->verify_sign($callback_data)) {
            return array(
                'success' => false,
                'message' => '签名验证失败'
            );
        }
        
        // 获取订单
        $order = Todo_Order_Manager::get_order_by_no($callback_data['out_trade_no']);
        if (!$order) {
            return array(
                'success' => false,
                'message' => '订单不存在'
            );
        }
        
        // 检查订单状态
        if ($order['payment_status'] === Todo_Order_Manager::PAYMENT_PAID) {
            return array(
                'success' => true,
                'message' => '订单已支付'
            );
        }
        
        // 验证金额
        $order_amount = intval($order['total_amount'] * 100);
        if ($callback_data['total_fee'] != $order_amount) {
            return array(
                'success' => false,
                'message' => '支付金额不匹配'
            );
        }
        
        // 处理支付成功
        $payment_data = array(
            'payment_id' => 0, // 这里需要从支付记录中获取
            'amount' => $order['total_amount'],
            'method' => 'payjs_' . ($callback_data['type'] ?? 'wechat'),
            'gateway_order_id' => $callback_data['payjs_order_id'],
            'transaction_id' => $callback_data['transaction_id'] ?? '',
            'callback_data' => $callback_data
        );
        
        if (Todo_Payment_Manager::handle_payment_success($order['id'], $payment_data)) {
            return array(
                'success' => true,
                'message' => '支付处理成功'
            );
        } else {
            return array(
                'success' => false,
                'message' => '支付处理失败'
            );
        }
    }
    
    /**
     * 查询支付状态
     * 
     * @param string $gateway_order_id 支付网关订单号
     * @return array
     */
    public function query_payment($gateway_order_id) {
        $params = array(
            'payjs_order_id' => $gateway_order_id
        );
        
        // 生成签名
        $params['sign'] = $this->generate_sign($params);
        
        // 发送请求
        $response = $this->send_request('check', $params);
        
        if (!$response) {
            return array(
                'success' => false,
                'message' => '查询请求失败'
            );
        }
        
        $result = json_decode($response, true);
        
        if (!$result) {
            return array(
                'success' => false,
                'message' => '查询响应格式错误'
            );
        }
        
        if ($result['return_code'] != 1) {
            return array(
                'success' => false,
                'message' => $result['return_msg'] ?? '查询失败'
            );
        }
        
        // 解析支付状态
        $status = 'pending';
        if (isset($result['status']) && $result['status'] == 1) {
            $status = 'success';
        }
        
        return array(
            'success' => true,
            'status' => $status,
            'data' => $result
        );
    }
    
    /**
     * 申请退款
     * 
     * @param string $gateway_order_id 支付网关订单号
     * @param float $refund_amount 退款金额
     * @param string $refund_reason 退款原因
     * @return array
     */
    public function create_refund($gateway_order_id, $refund_amount, $refund_reason = '') {
        $params = array(
            'payjs_order_id' => $gateway_order_id,
            'refund_fee' => intval($refund_amount * 100) // 转换为分
        );
        
        // 生成签名
        $params['sign'] = $this->generate_sign($params);
        
        // 发送请求
        $response = $this->send_request('refund', $params);
        
        if (!$response) {
            return array(
                'success' => false,
                'message' => '退款请求失败'
            );
        }
        
        $result = json_decode($response, true);
        
        if (!$result) {
            return array(
                'success' => false,
                'message' => '退款响应格式错误'
            );
        }
        
        if ($result['return_code'] != 1) {
            return array(
                'success' => false,
                'message' => $result['return_msg'] ?? '退款失败'
            );
        }
        
        return array(
            'success' => true,
            'refund_id' => $result['refund_id'] ?? '',
            'data' => $result,
            'message' => '退款申请成功'
        );
    }
    
    /**
     * 生成签名
     * 
     * @param array $params 参数数组
     * @return string
     */
    private function generate_sign($params) {
        // 移除sign参数
        unset($params['sign']);
        
        // 按键名排序
        ksort($params);
        
        // 构建签名字符串
        $sign_string = '';
        foreach ($params as $key => $value) {
            if ($value !== '' && $value !== null) {
                $sign_string .= $key . '=' . $value . '&';
            }
        }
        
        // 添加key
        $sign_string .= 'key=' . $this->key;
        
        // 生成MD5签名并转大写
        return strtoupper(md5($sign_string));
    }
    
    /**
     * 验证签名
     * 
     * @param array $data 数据数组
     * @return bool
     */
    private function verify_sign($data) {
        if (!isset($data['sign'])) {
            return false;
        }
        
        $received_sign = $data['sign'];
        $calculated_sign = $this->generate_sign($data);
        
        return $received_sign === $calculated_sign;
    }
    
    /**
     * 发送HTTP请求
     * 
     * @param string $endpoint API端点
     * @param array $params 请求参数
     * @return string|false
     */
    private function send_request($endpoint, $params) {
        $url = $this->api_url . $endpoint;
        
        // 使用cURL发送POST请求
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: TodoCMS-PAYJS/1.0'
        ));
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            todo_log_error("PAYJS API request error: {$error}");
            return false;
        }
        
        if ($http_code !== 200) {
            todo_log_error("PAYJS API HTTP error: {$http_code}");
            return false;
        }
        
        return $response;
    }
    
    /**
     * 获取订单描述
     * 
     * @param array $order 订单信息
     * @return string
     */
    private function get_order_description($order) {
        $site_name = todo_get_option('site_name', 'TodoCMS');
        
        if ($order['is_download_order']) {
            return $site_name . ' - 数字商品订单';
        }
        
        return $site_name . ' - 订单号: ' . $order['order_no'];
    }
    
    /**
     * 获取异步通知URL
     * 
     * @return string
     */
    private function get_notify_url() {
        $base_url = todo_get_option('site_url', '');
        if (empty($base_url)) {
            $base_url = 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['HTTP_HOST'];
        }
        
        return rtrim($base_url, '/') . '/includes/payment/payjs-notify.php';
    }
    
    /**
     * 获取同步返回URL
     * 
     * @param int $order_id 订单ID
     * @return string
     */
    private function get_return_url($order_id) {
        $base_url = todo_get_option('site_url', '');
        if (empty($base_url)) {
            $base_url = 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['HTTP_HOST'];
        }
        
        return rtrim($base_url, '/') . '/order-success.php?order_id=' . $order_id;
    }
    
    /**
     * 获取支付二维码HTML
     * 
     * @param string $qr_code 二维码内容
     * @param string $method 支付方式
     * @return string
     */
    public function get_qr_code_html($qr_code, $method = self::METHOD_WECHAT) {
        $method_name = ($method === self::METHOD_ALIPAY) ? '支付宝' : '微信';
        
        return '<div class="payjs-qr-code">
                    <div class="qr-title">请使用' . $method_name . '扫码支付</div>
                    <div class="qr-image">
                        <img src="' . htmlspecialchars($qr_code) . '" alt="' . $method_name . '支付二维码" />
                    </div>
                    <div class="qr-tips">
                        <p>请在手机上打开' . $method_name . 'APP</p>
                        <p>扫描上方二维码完成支付</p>
                    </div>
                </div>';
    }
    
    /**
     * 获取支付页面JavaScript
     * 
     * @param int $order_id 订单ID
     * @param string $gateway_order_id 支付网关订单号
     * @return string
     */
    public function get_payment_script($order_id, $gateway_order_id) {
        return '<script type="text/javascript">
                var paymentChecker = {
                    orderId: ' . intval($order_id) . ',
                    gatewayOrderId: "' . htmlspecialchars($gateway_order_id) . '",
                    checkInterval: null,
                    
                    init: function() {
                        this.startChecking();
                    },
                    
                    startChecking: function() {
                        var self = this;
                        this.checkInterval = setInterval(function() {
                            self.checkPaymentStatus();
                        }, 3000); // 每3秒检查一次
                        
                        // 5分钟后停止检查
                        setTimeout(function() {
                            self.stopChecking();
                        }, 300000);
                    },
                    
                    stopChecking: function() {
                        if (this.checkInterval) {
                            clearInterval(this.checkInterval);
                            this.checkInterval = null;
                        }
                    },
                    
                    checkPaymentStatus: function() {
                        var self = this;
                        fetch("/includes/ajax/payment-status.php", {
                            method: "POST",
                            headers: {
                                "Content-Type": "application/x-www-form-urlencoded",
                                "X-Requested-With": "XMLHttpRequest"
                            },
                            body: "action=check_status&order_id=" + this.orderId + "&gateway_order_id=" + this.gatewayOrderId
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && data.paid) {
                                self.stopChecking();
                                self.handlePaymentSuccess();
                            }
                        })
                        .catch(error => {
                            console.error("Payment status check error:", error);
                        });
                    },
                    
                    handlePaymentSuccess: function() {
                        // 显示支付成功提示
                        alert("支付成功！页面即将跳转...");
                        
                        // 跳转到订单成功页面
                        window.location.href = "/order-success.php?order_id=" + this.orderId;
                    }
                };
                
                // 页面加载完成后开始检查
                document.addEventListener("DOMContentLoaded", function() {
                    paymentChecker.init();
                });
                </script>';
    }
}