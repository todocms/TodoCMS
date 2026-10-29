<?php
/**
 * 浏览历史处理AJAX接口
 * 
 * @package TodoCMS
 * @subpackage Includes/Ajax
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access denied.');
}

$security = Todo_Security_Manager::getInstance();
if (!$security->verify_request_csrf()) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    header('X-Robots-Tag: noindex, nofollow', true);
    echo json_encode(['error' => 'CSRF verification failed']);
    exit;
}

if (!defined('DOING_AJAX') || !DOING_AJAX) {
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        http_response_code(403);
        echo json_encode(['error' => 'Direct access denied']);
        exit;
    }
}

// 加载TodoCMS环境
require_once dirname(dirname(__DIR__)) . '/core/bootstrap.php';

// 检查用户登录状态
if (!is_user_logged_in()) {
    todo_send_json_error(__('请先登录'));
}

$current_user = todo_get_current_user();
$user_id = todo_get_current_user_id();

// 获取操作类型
$action = sanitize_text_field($_POST['action'] ?? $_GET['action'] ?? '');

// 验证nonce
if (!todo_verify_nonce($_POST['_todononce'] ?? $_GET['_todononce'] ?? '', 'todocms_nonce')) {
    todo_send_json_error(__('安全验证失败'));
}

// 根据操作类型处理请求
switch ($action) {
    case 'add':
        handle_add_history();
        break;
        
    case 'remove':
        handle_remove_history();
        break;
        
    case 'clear_all':
        handle_clear_all_history();
        break;
        
    case 'clear_selected':
        handle_clear_selected_history();
        break;
        
    case 'get_stats':
        handle_get_history_stats();
        break;
        
    case 'get_recent':
        handle_get_recent_history();
        break;
        
    default:
        todo_send_json_error(__('无效的操作'));
}

/**
 * 添加浏览历史
 */
function handle_add_history() {
    global $tododb, $user_id;
    
    $product_id = intval($_POST['product_id'] ?? 0);
    
    if (!$product_id) {
        todo_send_json_error(__('商品ID不能为空'));
    }
    
    // 验证商品是否存在且已发布
    $product = get_post($product_id);
    if (!$product || $product->post_status !== 'publish') {
        todo_send_json_error(__('商品不存在或已下架'));
    }
    
    try {
        // 检查是否已存在记录
        $existing = $tododb->get_row(
            "SELECT id, view_count 
            FROM {$tododb->prefix}user_browse_history 
            WHERE user_id = ? AND product_id = ?",
            [$user_id, $product_id]
        );
        
        if ($existing) {
            // 更新现有记录
            $result = $tododb->update(
                $tododb->prefix . 'user_browse_history',
                [
                    'viewed_at' => current_time('mysql'),
                    'view_count' => $existing->view_count + 1
                ],
                [
                    'id' => $existing->id
                ],
                ['%s', '%d'],
                ['%d']
            );
            
            if ($result === false) {
                throw new Exception(__('更新浏览记录失败'));
            }
            
            todo_send_json_success([
                'message' => __('浏览记录已更新'),
                'view_count' => $existing->view_count + 1
            ]);
        } else {
            // 插入新记录
            $result = $tododb->insert(
                $tododb->prefix . 'user_browse_history',
                [
                    'user_id' => $user_id,
                    'product_id' => $product_id,
                    'viewed_at' => current_time('mysql'),
                    'view_count' => 1
                ],
                ['%d', '%d', '%s', '%d']
            );
            
            if ($result === false) {
                throw new Exception(__('添加浏览记录失败'));
            }
            
            // 清理旧记录（保留最近100条）
            cleanup_old_history($user_id);
            
            todo_send_json_success([
                'message' => __('浏览记录已添加'),
                'view_count' => 1
            ]);
        }
        
    } catch (Exception $e) {
        error_log('Add history error: ' . $e->getMessage());
        todo_send_json_error(__('操作失败') . ': ' . $e->getMessage());
    }
}

/**
 * 删除单条浏览历史
 */
function handle_remove_history() {
    global $tododb, $user_id;
    
    $history_id = intval($_POST['history_id'] ?? 0);
    
    if (!$history_id) {
        todo_send_json_error(__('历史记录ID不能为空'));
    }
    
    try {
        // 验证记录是否属于当前用户
        $history = $tododb->get_row(
            "SELECT id 
            FROM {$tododb->prefix}user_browse_history 
            WHERE id = ? AND user_id = ?",
            [$history_id, $user_id]
        );
        
        if (!$history) {
            todo_send_json_error(__('记录不存在或无权限删除'));
        }
        
        // 删除记录
        $result = $tododb->delete(
            $tododb->prefix . 'user_browse_history',
            [
                'id' => $history_id,
                'user_id' => $user_id
            ],
            ['%d', '%d']
        );
        
        if ($result === false) {
            throw new Exception(__('删除失败'));
        }
        
        todo_send_json_success(__('浏览记录已删除'));
        
    } catch (Exception $e) {
        error_log('Remove history error: ' . $e->getMessage());
        todo_send_json_error(__('删除失败') . ': ' . $e->getMessage());
    }
}

/**
 * 清空所有浏览历史
 */
function handle_clear_all_history() {
    global $tododb, $user_id;
    
    try {
        $result = $tododb->delete(
            $tododb->prefix . 'user_browse_history',
            ['user_id' => $user_id],
            ['%d']
        );
        
        if ($result === false) {
            throw new Exception(__('清空失败'));
        }
        
        todo_send_json_success([
            'message' => sprintf(__('成功清空 %d 条浏览记录'), $result),
            'count' => $result
        ]);
        
    } catch (Exception $e) {
        error_log('Clear all history error: ' . $e->getMessage());
        todo_send_json_error(__('清空失败') . ': ' . $e->getMessage());
    }
}

/**
 * 批量删除浏览历史
 */
function handle_clear_selected_history() {
    global $tododb, $user_id;
    
    $history_ids = $_POST['history_ids'] ?? '';
    
    if (empty($history_ids)) {
        todo_send_json_error(__('请选择要删除的记录'));
    }
    
    // 处理ID列表
    if (is_string($history_ids)) {
        $history_ids = explode(',', $history_ids);
    }
    
    $history_ids = array_map('intval', $history_ids);
    $history_ids = array_filter($history_ids);
    
    if (empty($history_ids)) {
        todo_send_json_error(__('无效的记录ID'));
    }
    
    try {
        // 验证所有记录都属于当前用户
        $placeholders = implode(',', array_fill(0, count($history_ids), '?'));
        $check_params = array_merge([$user_id], $history_ids);
        
        $valid_count = $tododb->get_var("
            SELECT COUNT(*) 
            FROM {$tododb->prefix}user_browse_history 
            WHERE user_id = ? AND id IN ($placeholders)
        ", $check_params);
        
        if ($valid_count != count($history_ids)) {
            todo_send_json_error(__('包含无效的记录'));
        }
        
        // 执行删除
        $delete_params = array_merge([$user_id], $history_ids);
        $result = $tododb->query("
            DELETE FROM {$tododb->prefix}user_browse_history 
            WHERE user_id = ? AND id IN ($placeholders)
        ", $delete_params);
        
        if ($result === false) {
            throw new Exception(__('删除失败'));
        }
        
        todo_send_json_success([
            'message' => sprintf(__('成功删除 %d 条浏览记录'), $result),
            'count' => $result
        ]);
        
    } catch (Exception $e) {
        error_log('Clear selected history error: ' . $e->getMessage());
        todo_send_json_error(__('删除失败') . ': ' . $e->getMessage());
    }
}

/**
 * 获取浏览历史统计
 */
function handle_get_history_stats() {
    global $tododb, $user_id;
    
    try {
        // 总浏览记录数
        $total_count = $tododb->get_var("
            SELECT COUNT(*) 
            FROM {$tododb->prefix}user_browse_history 
            WHERE user_id = ?
        ", [$user_id]);
        
        // 今日浏览数
        $today_count = $tododb->get_var("
            SELECT COUNT(*) 
            FROM {$tododb->prefix}user_browse_history 
            WHERE user_id = ? AND DATE(viewed_at) = CURDATE()
        ", [$user_id]);
        
        // 本周浏览数
        $week_count = $tododb->get_var("
            SELECT COUNT(*) 
            FROM {$tododb->prefix}user_browse_history 
            WHERE user_id = ? AND viewed_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)
        ", [$user_id]);
        
        // 本月浏览数
        $month_count = $tododb->get_var("
            SELECT COUNT(*) 
            FROM {$tododb->prefix}user_browse_history 
            WHERE user_id = ? AND viewed_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)
        ", [$user_id]);
        
        // 最常浏览的分类
        $top_categories = $tododb->get_results("
            SELECT c.name as category_name, COUNT(*) as view_count
            FROM {$tododb->prefix}user_browse_history h
            INNER JOIN {$tododb->posts} p ON h.product_id = p.ID
            INNER JOIN {$tododb->term_relationships} tr ON p.ID = tr.object_id
            INNER JOIN {$tododb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_cat'
            INNER JOIN {$tododb->terms} c ON tt.term_id = c.term_id
            WHERE h.user_id = ? AND p.post_status = 'publish'
            GROUP BY c.term_id, c.name
            ORDER BY view_count DESC
            LIMIT 5
        ", [$user_id], 'ARRAY_A');
        
        todo_send_json_success([
            'total_count' => intval($total_count),
            'today_count' => intval($today_count),
            'week_count' => intval($week_count),
            'month_count' => intval($month_count),
            'top_categories' => $top_categories ?: []
        ]);
        
    } catch (Exception $e) {
        error_log('Get history stats error: ' . $e->getMessage());
        todo_send_json_error(__('获取统计失败') . ': ' . $e->getMessage());
    }
}

/**
 * 获取最近浏览记录
 */
function handle_get_recent_history() {
    global $tododb, $user_id;
    
    $limit = intval($_GET['limit'] ?? 10);
    $limit = min(max($limit, 1), 50); // 限制在1-50之间
    
    try {
        $items = $tododb->get_results("
            SELECT h.id, h.product_id, h.viewed_at, h.view_count,
                   p.post_title as product_name,
                   pm1.meta_value as regular_price,
                   pm2.field_value as sale_price
            FROM {$tododb->prefix}user_browse_history h
            INNER JOIN {$tododb->posts} p ON h.product_id = p.ID
            LEFT JOIN {$tododb->prefix}post_custom_fields pm1 ON p.ID = pm1.post_id AND pm1.field_key = '_regular_price'
            LEFT JOIN {$tododb->prefix}post_custom_fields pm2 ON p.ID = pm2.post_id AND pm2.field_key = '_sale_price'
            WHERE h.user_id = ? AND p.post_status = 'publish'
            ORDER BY h.viewed_at DESC
            LIMIT ?
        ", [$user_id, $limit], 'ARRAY_A');
        
        // 处理数据
        foreach ($items as &$item) {
            // 处理图片
            $item['featured_image'] = function_exists('get_the_post_thumbnail_url')
                ? (string) get_the_post_thumbnail_url((int) $item['product_id'], 'full')
                : '';
            
            // 处理价格
            $item['regular_price'] = floatval($item['regular_price'] ?: 0);
            $item['sale_price'] = floatval($item['sale_price'] ?: 0);
            
            // 处理其他字段
            $item['view_count'] = intval($item['view_count'] ?: 1);
            $item['product_url'] = get_permalink($item['product_id']);
            
            // 格式化时间
            $item['viewed_at_formatted'] = date('m-d H:i', strtotime($item['viewed_at']));
        }
        
        todo_send_json_success([
            'items' => $items ?: [],
            'count' => count($items)
        ]);
        
    } catch (Exception $e) {
        error_log('Get recent history error: ' . $e->getMessage());
        todo_send_json_error(__('获取记录失败') . ': ' . $e->getMessage());
    }
}

/**
 * 清理旧的浏览历史记录
 */
function cleanup_old_history($user_id, $keep_count = 100) {
    global $tododb;
    
    try {
        // 获取当前记录总数
        $total_count = $tododb->get_var("
            SELECT COUNT(*) 
            FROM {$tododb->prefix}user_browse_history 
            WHERE user_id = ?
        ", [$user_id]);
        
        if ($total_count <= $keep_count) {
            return; // 不需要清理
        }
        
        // 获取需要保留的最新记录的最小ID
        $min_id_to_keep = $tododb->get_var("
            SELECT id 
            FROM {$tododb->prefix}user_browse_history 
            WHERE user_id = ? 
            ORDER BY viewed_at DESC 
            LIMIT 1 OFFSET ?
        ", [$user_id, $keep_count - 1]);
        
        if ($min_id_to_keep) {
            // 删除旧记录
            $tododb->query("
                DELETE FROM {$tododb->prefix}user_browse_history 
                WHERE user_id = ? AND id < ?
            ", [$user_id, $min_id_to_keep]);
        }
        
    } catch (Exception $e) {
        error_log('Cleanup old history error: ' . $e->getMessage());
    }
}

/**
 * 记录浏览历史（供其他地方调用）
 */
function todo_add_browse_history($user_id, $product_id) {
    global $tododb;
    
    if (!$user_id || !$product_id) {
        return false;
    }
    
    // 验证商品是否存在
    $product = get_post($product_id);
    if (!$product || $product->post_status !== 'publish') {
        return false;
    }
    
    try {
        // 检查是否已存在记录
        $existing = $tododb->get_row(
            "SELECT id, view_count 
            FROM {$tododb->prefix}user_browse_history 
            WHERE user_id = ? AND product_id = ?",
            [$user_id, $product_id]
        );
        
        if ($existing) {
            // 更新现有记录
            $result = $tododb->update(
                $tododb->prefix . 'user_browse_history',
                [
                    'viewed_at' => current_time('mysql'),
                    'view_count' => $existing->view_count + 1
                ],
                [
                    'id' => $existing->id
                ],
                ['%s', '%d'],
                ['%d']
            );
        } else {
            // 插入新记录
            $result = $tododb->insert(
                $tododb->prefix . 'user_browse_history',
                [
                    'user_id' => $user_id,
                    'product_id' => $product_id,
                    'viewed_at' => current_time('mysql'),
                    'view_count' => 1
                ],
                ['%d', '%d', '%s', '%d']
            );
            
            // 清理旧记录
            cleanup_old_history($user_id);
        }
        
        return $result !== false;
        
    } catch (Exception $e) {
        error_log('Add browse history error: ' . $e->getMessage());
        return false;
    }
}

/**
 * 获取用户浏览历史数量
 */
function todo_get_user_browse_count($user_id) {
    global $tododb;
    
    if (!$user_id) {
        return 0;
    }
    
    $count = $tododb->get_var("
        SELECT COUNT(*) 
        FROM {$tododb->prefix}user_browse_history 
        WHERE user_id = ?
    ", $user_id);
    
    return intval($count ?: 0);
}

/**
 * 检查商品是否被用户浏览过
 */
function todo_is_product_browsed($user_id, $product_id) {
    global $tododb;
    
    if (!$user_id || !$product_id) {
        return false;
    }
    
    $exists = $tododb->get_var("
        SELECT id 
        FROM {$tododb->prefix}user_browse_history 
        WHERE user_id = ? AND product_id = ?
    ", [$user_id, $product_id]);
    
    return !empty($exists);
}
?>
