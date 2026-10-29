<?php
/**
 * TodoCMS 订单评价AJAX接口
 * 
 * 处理订单评价相关的AJAX请求
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('DOING_AJAX')) {
    if (!defined('DOING_AJAX')) {
        define('DOING_AJAX', true);
    }
}

// 检查AJAX请求
if (!defined('DOING_AJAX') || !DOING_AJAX || !todo_is_ajax_request()) {
    http_response_code(403);
    echo json_encode(array('error' => 'Direct access denied'));
    exit;
}

// 加载核心文件
require_once dirname(dirname(__DIR__)) . '/core/bootstrap.php';

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

// PRG 防重复提交守卫（仅对携带 _submit_key 的 POST 生效）
Todo_PRG_Guard::guard_endpoint('review_handler', 30);

try {
    // 检查用户登录状态
    if (!is_user_logged_in()) {
        throw new Exception(__('用户未登录'));
    }
    
    // 获取当前用户ID
    $current_user_id = todo_get_current_user_id();
    
    // 获取请求参数
    $action = todo_post_string('action');
    
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf($action)) {
        throw new Exception(__('安全验证失败'));
    }
    
    // 验证参数
    if (empty($action)) {
        throw new Exception(__('缺少操作参数'));
    }
    
    $response = array('success' => false);
    
    switch ($action) {
        case 'create_review':
            $response = handle_create_review($current_user_id);
            break;
            
        case 'get_order_reviews':
            $response = handle_get_order_reviews($current_user_id);
            break;
            
        case 'get_product_reviews':
            $response = handle_get_product_reviews();
            break;
            
        case 'upload_review_image':
            $response = handle_upload_review_image($current_user_id);
            break;
            
        default:
            throw new Exception(__('未知的操作类型'));
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode(array(
        'success' => false,
        'message' => $e->getMessage()
    ));
}

/**
 * 处理创建评价
 * 
 * @param int $user_id 用户ID
 * @return array
 */
function handle_create_review($user_id) {
    // 获取表单数据
    $order_id = todo_post_int('order_id');
    $product_id = todo_post_int('product_id');
    $rating = todo_post_int('rating');
    $content = trim(todo_post_string('content'));
    $images = isset($_POST['images']) && is_array($_POST['images']) ? $_POST['images'] : array();
    
    // 验证必填字段
    if ($order_id <= 0) {
        throw new Exception(__('无效的订单ID'));
    }
    
    if ($product_id <= 0) {
        throw new Exception(__('无效的商品ID'));
    }
    
    if ($rating < 1 || $rating > 5) {
        throw new Exception(__('评分必须在1-5之间'));
    }
    
    if (empty($content)) {
        throw new Exception(__('评价内容不能为空'));
    }
    
    if (mb_strlen($content, 'UTF-8') < 10) {
        throw new Exception(__('评价内容至少需要10个字符'));
    }
    
    if (mb_strlen($content, 'UTF-8') > 1000) {
        throw new Exception(__('评价内容不能超过1000个字符'));
    }
    
    // 验证图片数组
    if (!is_array($images)) {
        $images = array();
    }
    
    // 限制图片数量
    if (count($images) > 9) {
        throw new Exception(__('最多只能上传9张图片'));
    }
    
    // 验证图片URL
    $valid_images = array();
    foreach ($images as $image) {
        if (is_string($image) && !empty($image)) {
            // 检查是否是有效的图片URL
            if (filter_var($image, FILTER_VALIDATE_URL) || strpos($image, '/content/uploads/') === 0) {
                $valid_images[] = $image;
            }
        }
    }
    
    // 准备评价数据
    $review_data = array(
        'order_id' => $order_id,
        'user_id' => $user_id,
        'product_id' => $product_id,
        'rating' => $rating,
        'content' => $content,
        'images' => $valid_images
    );
    
    // 创建评价
    $review_id = Todo_Order_Review::create_review($review_data);
    
    if ($review_id) {
        return array(
            'success' => true,
            'message' => __('评价提交成功'),
            'review_id' => $review_id
        );
    } else {
        throw new Exception(__('评价提交失败'));
    }
}

/**
 * 处理获取订单评价
 * 
 * @param int $user_id 用户ID
 * @return array
 */
function handle_get_order_reviews($user_id) {
    $order_id = todo_post_int('order_id');
    
    if ($order_id <= 0) {
        throw new Exception(__('无效的订单ID'));
    }
    
    // 获取订单信息，验证权限
    $order = Todo_Order_Manager::get_order($order_id);
    if (!$order) {
        throw new Exception(__('订单不存在'));
    }
    
    if ($order['user_id'] != $user_id) {
        throw new Exception(__('无权限查看此订单的评价'));
    }
    
    // 获取评价列表
    $reviews = Todo_Order_Review::get_order_reviews($order_id, $user_id);
    
    return array(
        'success' => true,
        'reviews' => $reviews
    );
}

/**
 * 处理获取商品评价
 * 
 * @return array
 */
function handle_get_product_reviews() {
    $product_id = todo_post_int('product_id');
    $page = todo_post_int('page', 1);
    $limit = todo_post_int('limit', 10);
    
    if ($product_id <= 0) {
        throw new Exception(__('无效的商品ID'));
    }
    
    // 限制每页数量
    if ($limit > 50) {
        $limit = 50;
    }
    
    $offset = ($page - 1) * $limit;
    
    // 获取评价列表
    $reviews = Todo_Order_Review::get_product_reviews($product_id, array(
        'limit' => $limit,
        'offset' => $offset
    ));
    
    // 获取评价统计
    $stats = Todo_Order_Review::get_product_rating_stats($product_id);
    
    return array(
        'success' => true,
        'reviews' => $reviews,
        'stats' => $stats,
        'page' => $page,
        'limit' => $limit
    );
}

/**
 * 处理评价图片上传
 * 
 * @param int $user_id 用户ID
 * @return array
 */
function handle_upload_review_image($user_id) {
    if (function_exists('get_option') && !get_option('enable_upload', true)) {
        throw new Exception(__('文件上传功能已被管理员禁用'));
    }

    // 检查是否有文件上传
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception(__('请选择要上传的图片'));
    }
    
    $file = $_FILES['image'];
    
    // 验证文件类型
    $allowed_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
    if (!in_array($file['type'], $allowed_types)) {
        throw new Exception(__('只支持JPG、PNG、GIF、WebP格式的图片'));
    }
    
    // 验证文件大小（最大5MB）
    $max_size = 5 * 1024 * 1024;
    if (class_exists('TodoUploadConfig')) {
        $app_max = (int) TodoUploadConfig::get_max_upload_size();
        if ((int) $app_max <= 0) {
            throw new Exception(__('文件上传功能已被管理员禁用'));
        }
        $max_size = min($max_size, $app_max);
    }
    if ($file['size'] > $max_size) {
        $max_mb = round($max_size / (1024 * 1024), 2);
        throw new Exception(sprintf(__('图片大小不能超过 %sMB'), $max_mb));
    }
    
    // 验证图片尺寸
    $image_info = getimagesize($file['tmp_name']);
    if (!$image_info) {
        throw new Exception(__('无效的图片文件'));
    }
    
    $width = $image_info[0];
    $height = $image_info[1];
    
    // 限制图片尺寸
    if ($width > 2000 || $height > 2000) {
        throw new Exception(__('图片尺寸不能超过2000x2000像素'));
    }
    
    if ($width < 100 || $height < 100) {
        throw new Exception(__('图片尺寸不能小于100x100像素'));
    }
    
    // 生成文件名
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'review_' . $user_id . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $extension;
    
    // 创建上传目录
    $upload_dir = TODO_ROOT . '/content/uploads/reviews/' . date('Y/m');
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            throw new Exception(__('创建上传目录失败'));
        }
    }
    
    $upload_path = $upload_dir . '/' . $filename;
    $relative_path = '/content/uploads/reviews/' . date('Y/m') . '/' . $filename;
    
    // realpath 前缀校验，确保上传到 /content/uploads 下
    $uploads_base = (defined('ABSPATH') ? rtrim(ABSPATH, '/') : rtrim(TODO_ROOT, '/')) . '/content/uploads';
    $base_real = realpath($uploads_base);
    $dir_real = realpath($upload_dir);
    if ($base_real === false || $dir_real === false || strpos($dir_real, $base_real) !== 0) {
        throw new Exception(__('非法上传目录'));
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new Exception(__('非法上传源'));
    }
    
    // 移动上传文件
    if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
        throw new Exception(__('文件上传失败'));
    }
    @chmod($upload_path, 0644);
    
    return array(
        'success' => true,
        'message' => __('图片上传成功'),
        'image_url' => $relative_path
    );
}
