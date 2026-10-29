<?php
/**
 * TodoCMS PAYJS支付回调处理
 * 
 * 处理PAYJS支付成功的异步通知
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    exit('Direct access denied.');
}

// 加载核心文件
require_once dirname(dirname(__DIR__)) . '/core/bootstrap.php';

// 设置响应头
header('Content-Type: text/plain; charset=utf-8');

// 记录回调日志
todo_log_info('PAYJS callback received: ' . json_encode($_POST));

try {
    // 检查请求方法
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    // 检查必要参数
    $required_fields = array('payjs_order_id', 'out_trade_no', 'total_fee', 'sign');
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || $_POST[$field] === '') {
            throw new Exception("Missing required field: {$field}");
        }
    }
    
    // 初始化支付管理器
    Todo_Payment_Manager::init();
    
    // 处理回调
    $result = Todo_Payment_Manager::handle_payment_callback('payjs_wechat', $_POST);
    
    if ($result['success']) {
        // 记录成功日志
        todo_log_info('PAYJS callback processed successfully: ' . $_POST['out_trade_no']);
        
        // 返回成功响应
        echo 'success';
    } else {
        // 记录失败日志
        todo_log_error('PAYJS callback processing failed: ' . $result['message']);
        
        // 返回失败响应
        echo 'fail';
    }
    
} catch (Exception $e) {
    // 记录异常日志
    todo_log_error('PAYJS callback exception: ' . $e->getMessage());
    
    // 返回失败响应
    echo 'fail';
}

// 确保脚本结束
exit;