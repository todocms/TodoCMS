<?php
/**
 * TodoCMS 订单报表导出AJAX接口
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

$security = Todo_Security_Manager::getInstance();
if (!$security->verify_request_csrf()) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    header('X-Robots-Tag: noindex, nofollow', true);
    echo json_encode(['error' => 'CSRF verification failed']);
    exit;
}

if (!defined('DOING_AJAX')) {
    if (!defined('DOING_AJAX')) {
        define('DOING_AJAX', true);
    }
}

// 检查AJAX请求
if (!defined('DOING_AJAX') || !DOING_AJAX || 
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    echo json_encode(array('error' => 'Direct access denied'));
    exit;
}

// 加载核心文件
require_once dirname(dirname(dirname(__DIR__))) . '/core/bootstrap.php';

try {
    // 检查用户权限
    if (!todo_current_user_can('manage_orders')) {
        throw new Exception('权限不足');
    }
    
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('export_order_report', $_POST['_todononce'] ?? '')) {
        throw new Exception('安全验证失败');
    }
    
    // 获取请求参数
    $report_type = $_POST['report_type'] ?? '';
    $date_from = $_POST['date_from'] ?? '';
    $date_to = $_POST['date_to'] ?? '';
    
    // 验证参数
    if (empty($report_type)) {
        throw new Exception('缺少报表类型参数');
    }
    
    // 构建过滤条件
    $filters = array();
    if (!empty($date_from)) {
        $filters['date_from'] = $date_from;
    }
    if (!empty($date_to)) {
        $filters['date_to'] = $date_to;
    }
    
    // 根据报表类型设置限制
    switch ($report_type) {
        case 'sales_trend':
            $filters['period'] = $_POST['period'] ?? 'day';
            $filters['limit'] = intval($_POST['limit'] ?? 30);
            break;
        case 'top_products':
        case 'top_customers':
            $filters['limit'] = intval($_POST['limit'] ?? 100);
            break;
    }
    
    // 导出报表
    $result = Todo_Order_Statistics::export_report($report_type, $filters);
    
    if ($result['success']) {
        // 设置下载响应头
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        
        // 读取并输出文件内容
        $file_path = str_replace(todo_get_upload_url(), todo_get_upload_dir(), $result['download_url']);
        if (file_exists($file_path)) {
            readfile($file_path);
            // 删除临时文件
            unlink($file_path);
        } else {
            throw new Exception('导出文件不存在');
        }
    } else {
        throw new Exception('报表导出失败');
    }
    
} catch (Exception $e) {
    // 如果是AJAX请求，返回JSON错误
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array(
            'success' => false,
            'message' => $e->getMessage()
        ));
    } else {
        // 否则显示错误页面
        todo_die('导出失败：' . $e->getMessage());
    }
}