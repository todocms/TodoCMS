<?php
/**
 * TodoCMS 后台评价处理AJAX接口
 * 
 * 处理评价管理相关的AJAX请求
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('DOING_AJAX') || !DOING_AJAX) {
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        http_response_code(403);
        echo json_encode(array('error' => 'Direct access denied'));
        exit;
    }
}

// 定义AJAX环境
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

// 加载核心文件
require_once dirname(dirname(dirname(__FILE__))) . '/core/bootstrap.php';

// 检查用户权限
if (!is_user_logged_in() || !todo_current_user_can('manage_reviews')) {
    http_response_code(403);
    echo json_encode(array(
        'success' => false,
        'message' => '权限不足'
    ));
    exit;
}

// 获取操作类型
$action = $_POST['action'] ?? '';

// 验证CSRF令牌
if (!Todo_Security_Manager::verify_request_csrf()) {
    http_response_code(403);
    echo json_encode(array(
        'success' => false,
        'message' => 'CSRF验证失败'
    ));
    exit;
}

try {
    switch ($action) {
        case 'get_review_details':
            handle_get_review_details();
            break;
            
        case 'update_review_status':
            handle_update_review_status();
            break;
            
        case 'reply_review':
            handle_reply_review();
            break;
            
        case 'delete_review':
            handle_delete_review();
            break;
            
        default:
            throw new Exception('无效的操作类型');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(array(
        'success' => false,
        'message' => $e->getMessage()
    ));
    exit;
}

/**
 * 获取评价详情
 */
function handle_get_review_details() {
    $review_id = intval($_POST['review_id'] ?? 0);
    
    if ($review_id <= 0) {
        throw new Exception('无效的评价ID');
    }
    
    // 获取评价详情
    global $tododb;
    $reviews_table = $tododb->prefix . 'order_reviews';
    $users_table = $tododb->prefix . 'users';
    $posts_table = $tododb->prefix . 'posts';
    $orders_table = $tododb->prefix . 'orders';
    
    $sql = "SELECT r.*, u.username, u.email, p.title as product_title, 
                   o.order_no as order_number, o.created_at as order_date
            FROM {$reviews_table} r
            LEFT JOIN {$users_table} u ON r.user_id = u.id
            LEFT JOIN {$posts_table} p ON r.product_id = p.id
            LEFT JOIN {$orders_table} o ON r.order_id = o.id
            WHERE r.id = ?";
    
    $review = $tododb->get_row($sql, [$review_id], 'ARRAY_A');
    
    if (!$review) {
        throw new Exception('评价不存在');
    }
    
    // 构建详情HTML
    $html = build_review_details_html($review);
    
    echo json_encode(array(
        'success' => true,
        'html' => $html
    ));
    exit;
}

/**
 * 更新评价状态
 */
function handle_update_review_status() {
    $review_id = intval($_POST['review_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    
    if ($review_id <= 0) {
        throw new Exception('无效的评价ID');
    }
    
    if (!in_array($status, array('pending', 'approved', 'rejected'))) {
        throw new Exception('无效的状态');
    }
    
    $result = Todo_Order_Review::update_review_status($review_id, $status);
    
    if (!$result) {
        throw new Exception('更新状态失败');
    }
    
    echo json_encode(array(
        'success' => true,
        'message' => '状态更新成功'
    ));
    exit;
}

/**
 * 回复评价
 */
function handle_reply_review() {
    $review_id = intval($_POST['review_id'] ?? 0);
    $reply_content = trim($_POST['reply_content'] ?? '');
    
    if ($review_id <= 0) {
        throw new Exception('无效的评价ID');
    }
    
    if (empty($reply_content)) {
        throw new Exception('回复内容不能为空');
    }
    
    if (mb_strlen($reply_content, 'UTF-8') > 500) {
        throw new Exception('回复内容不能超过500字符');
    }
    
    // 检查评价是否存在
    $review = Todo_Order_Review::get_review($review_id);
    if (!$review) {
        throw new Exception('评价不存在');
    }
    
    // 更新回复内容
    global $tododb;
    $reviews_table = $tododb->prefix . 'order_reviews';
    
    $result = $tododb->update(
        $reviews_table,
        array(
            'reply_content' => $reply_content,
            'reply_time' => current_time('mysql'),
            'reply_user_id' => todo_get_current_user_id()
        ),
        array('id' => $review_id),
        array('%s', '%s', '%d'),
        array('%d')
    );
    
    if ($result === false) {
        throw new Exception('回复失败');
    }
    
    echo json_encode(array(
        'success' => true,
        'message' => '回复成功'
    ));
    exit;
}

/**
 * 删除评价
 */
function handle_delete_review() {
    $review_id = intval($_POST['review_id'] ?? 0);
    
    if ($review_id <= 0) {
        throw new Exception('无效的评价ID');
    }
    
    $result = Todo_Order_Review::delete_review($review_id);
    
    if (!$result) {
        throw new Exception('删除失败');
    }
    
    echo json_encode(array(
        'success' => true,
        'message' => '删除成功'
    ));
    exit;
}

/**
 * 构建评价详情HTML
 */
function build_review_details_html($review) {
    $images = !empty($review['images']) ? json_decode($review['images'], true) : array();
    
    $status_text = array(
        'pending' => '待审核',
        'approved' => '已通过',
        'rejected' => '已拒绝'
    );
    
    $status_class = array(
        'pending' => 'warning',
        'approved' => 'success',
        'rejected' => 'danger'
    );
    ?>
    <div class="review-details">
        <!-- 基本信息 -->
        <div class="row mb-3">
            <div class="col-md-6">
                <h6>商品信息</h6>
                <p class="mb-1"><strong>商品名称：</strong><?php echo esc_html($review['product_title']); ?></p>
                <?php if (!empty($review['spec_name'])): ?>
                    <p class="mb-1"><strong>商品规格：</strong><?php echo esc_html($review['spec_name']); ?></p>
                <?php endif; ?>
                <p class="mb-1"><strong>订单号：</strong><?php echo esc_html($review['order_number']); ?></p>
                <p class="mb-1"><strong>下单时间：</strong><?php echo date('Y-m-d H:i', strtotime($review['order_date'])); ?></p>
            </div>
            <div class="col-md-6">
                <h6>用户信息</h6>
                <p class="mb-1"><strong>用户名：</strong><?php echo esc_html($review['username']); ?></p>
                <p class="mb-1"><strong>邮箱：</strong><?php echo esc_html($review['email']); ?></p>
                <p class="mb-1"><strong>评价时间：</strong><?php echo date('Y-m-d H:i', strtotime($review['created_at'])); ?></p>
                <p class="mb-1">
                    <strong>状态：</strong>
                    <span class="badge badge-<?php echo $status_class[$review['status']]; ?>">
                        <?php echo $status_text[$review['status']]; ?>
                    </span>
                </p>
            </div>
        </div>
        
        <!-- 评分 -->
        <div class="mb-3">
            <h6>评分</h6>
            <div class="rating-display">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <i class="custom-star <?php echo $i <= $review['rating'] ? 'text-warning' : 'text-muted'; ?>" style="font-size: 18px;"></i>
                <?php endfor; ?>
                <span class="ml-2"><?php echo $review['rating']; ?>/5</span>
            </div>
        </div>
        
        <!-- 评价内容 -->
        <div class="mb-3">
            <h6>评价内容</h6>
            <div class="border p-3 bg-light rounded">
                <?php echo nl2br(esc_html($review['content'])); ?>
            </div>
        </div>
        
        <!-- 评价图片 -->
        <?php if (!empty($images) && is_array($images)): ?>
            <div class="mb-3">
                <h6>评价图片</h6>
                <div class="row">
                    <?php foreach ($images as $image): ?>
                        <div class="col-md-3 mb-2">
                            <img src="<?php echo esc_attr($image); ?>" 
                                 alt="评价图片" 
                                 class="img-fluid rounded border"
                                 onclick="showImageModal('<?php echo esc_attr($image); ?>')"
                                 style="cursor: pointer;">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- 商家回复 -->
        <div class="mb-3">
            <h6>商家回复</h6>
            <?php if (!empty($review['reply_content'])): ?>
                <div class="border p-3 bg-info text-white rounded">
                    <?php echo nl2br(esc_html($review['reply_content'])); ?>
                    <div class="mt-2 small">
                        回复时间：<?php echo date('Y-m-d H:i', strtotime($review['reply_time'])); ?>
                    </div>
                </div>
            <?php else: ?>
                <p class="text-muted">暂无回复</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
?>