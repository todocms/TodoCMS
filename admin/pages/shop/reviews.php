<?php
/**
 * TodoCMS 商品评价管理页面
 * 
 * 管理商品评价和咨询
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ADMIN_DIR') && !defined('TODO_LOADED')) {
    http_response_code(403);
    exit('Direct access denied');
}

// 检查是否在管理后台环境
if (!defined('TODO_ADMIN') || !TODO_ADMIN) {
    http_response_code(403);
    exit('Access denied: Admin area required');
}

// 确保数据库连接已初始化（在会话检查之前）
if (!isset($tododb)) {
    global $tododb;
    if (!isset($tododb)) {
        $tododb = new CMS_DB();
    }
}

// 检查用户登录状态
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 使用 Todo_Session_Manager 检查后台登录状态
if (!class_exists('Todo_Session_Manager')) {
    if (!defined('TODO_LOADED')) {
        define('TODO_LOADED', true);
    }
}
if (class_exists('Todo_Session_Manager')) {
    Todo_Session_Manager::init();
}

if (!class_exists('Todo_Session_Manager') || !Todo_Session_Manager::is_admin_logged_in()) {
    http_response_code(403);
    todo_die('You must be logged in to access this page.', '系统错误', array('response' => 403));
}

// 权限检查
if (!current_user_can('manage_options')) {
    http_response_code(403);
    todo_die('You do not have sufficient permissions to access this page.', '系统错误', array('response' => 403));
}

$message = $message ?? null;
$message_type = $message_type ?? 'success';

// 处理表单与操作请求 (GET/POST)
$req_action = sanitize_text_field($_REQUEST['action'] ?? '');
if (!empty($req_action) && in_array($req_action, ['approve', 'reject', 'delete', 'update_review_status', 'add_review', 'bulk_action', 'reply_review'], true)) {
    $nonce = $_REQUEST['_todononce'] ?? $_POST['_todononce'] ?? '';
    // CSRF验证
    if (!todo_verify_nonce($nonce, 'todo_reviews_action')) {
        todo_die('Security check failed');
    }

    $action = $req_action;
    $message = '';
    $message_type = 'success';

    switch ($action) {
        case 'approve':
            $review_id = intval($_REQUEST['review_id'] ?? 0);
            todo_update_review_status($review_id, 'approved');
            if (function_exists('todo_add_admin_notice')) {
                todo_add_admin_notice('评价已批准通过', 'success');
            }
            todo_redirect(todo_admin_url('index.php?page=todo-reviews'));
            exit;
        case 'reject':
            $review_id = intval($_REQUEST['review_id'] ?? 0);
            todo_update_review_status($review_id, 'reject');
            if (function_exists('todo_add_admin_notice')) {
                todo_add_admin_notice('评价已拒绝', 'success');
            }
            todo_redirect(todo_admin_url('index.php?page=todo-reviews'));
            exit;
        case 'delete':
            $review_id = intval($_REQUEST['review_id'] ?? 0);
            todo_delete_review($review_id);
            if (function_exists('todo_add_admin_notice')) {
                todo_add_admin_notice('评价已永久删除', 'success');
            }
            todo_redirect(todo_admin_url('index.php?page=todo-reviews'));
            exit;
        case 'update_review_status':
            $review_id = intval($_POST['review_id'] ?? 0);
            $status = sanitize_text_field($_POST['status'] ?? '');
            $result = todo_update_review_status($review_id, $status);
            if ($result) {
                $message = '评价状态更新成功';
            } else {
                $message = '评价状态更新失败';
                $message_type = 'error';
            }
            break;

        case 'add_review':
            $product_id = intval($_POST['product_id'] ?? 0);
            $user_id = intval($_POST['user_id'] ?? 0);
            $order_id = intval($_POST['order_id'] ?? 0);
            $rating = intval($_POST['rating'] ?? 0);
            $content = sanitize_textarea_field($_POST['content'] ?? '');
            $status = sanitize_text_field($_POST['status'] ?? 'pending');
            if ($status === 'approved') {
                $status = 'approve';
            }
            $result = todo_add_review($product_id, $user_id, $order_id, $rating, $content, $status);
            if ($result) {
                $message = '评价添加成功';
            } else {
                $message = '评价添加失败';
                $message_type = 'error';
            }
            break;

        case 'bulk_action':
            $review_ids = array_map('intval', $_POST['review_ids'] ?? []);
            $bulk_action = sanitize_text_field($_POST['bulk_action'] ?? '');
            if (!empty($review_ids) && !empty($bulk_action)) {
                $success_count = 0;
                foreach ($review_ids as $review_id) {
                    if ($bulk_action === 'delete') {
                        $result = todo_delete_review($review_id);
                    } else {
                        $result = todo_update_review_status($review_id, $bulk_action);
                    }
                    if ($result) $success_count++;
                }
                $message = "成功处理 {$success_count} 个评价";
            }
            break;

        case 'reply_review':
            $review_id = intval($_POST['review_id'] ?? 0);
            $reply_content = sanitize_textarea_field($_POST['reply_content'] ?? '');
            $notify_user = isset($_POST['send_email']) && $_POST['send_email'] == '1';
            $template_slug = $_POST['template_slug'] ?? '';
            $reply_content_for_email = $reply_content;
            $reply_content_for_store = $reply_content;
            if ($reply_content_for_store === '' && $notify_user && $template_slug !== '' && function_exists('todo_get_email_template')) {
                $tpl = todo_get_email_template($template_slug);
                if ($tpl) {
                    $reply_content_for_store = '（邮件模板回复）';
                }
            }
            $token_key = 'reply_review|' . intval($review_id) . '|' . (string) $reply_content_for_store . '|' . intval($notify_user ? 1 : 0) . '|' . (string) $template_slug;
            if (function_exists('todo_claim_submit_token') && !todo_claim_submit_token($token_key, 120)) {
                $message = '正在发送中，请勿重复提交';
                $message_type = 'error';
                break;
            }
            
            $admin_name = '管理员';
            $admin_email = '';
            try {
                if (class_exists('Todo_Session_Manager')) {
                    Todo_Session_Manager::init();
                    $admin_user_id = intval(Todo_Session_Manager::get_admin_user_id());
                    if ($admin_user_id > 0) {
                        if (function_exists('get_user_by')) {
                            $admin_user = get_user_by('id', $admin_user_id);
                            if ($admin_user) {
                                $admin_name = (string) ($admin_user->display_name ?? $admin_user->user_login ?? $admin_name);
                                $admin_email = (string) ($admin_user->user_email ?? '');
                            }
                        } elseif (function_exists('todo_get_user')) {
                            $admin_user = todo_get_user($admin_user_id);
                            if (is_object($admin_user)) {
                                $admin_name = (string) ($admin_user->display_name ?? $admin_user->user_login ?? $admin_name);
                                $admin_email = (string) ($admin_user->user_email ?? '');
                            } elseif (is_array($admin_user)) {
                                $admin_name = (string) ($admin_user['display_name'] ?? $admin_user['user_login'] ?? $admin_name);
                                $admin_email = (string) ($admin_user['user_email'] ?? '');
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
            }
            
            global $tododb;
            $pr_table = $tododb->prefix . 'product_reviews';

            // 更新 todo_product_reviews 表上的商家回复文本
            $tododb->update(
                $pr_table,
                [
                    'reply_content' => $reply_content_for_store,
                    'reply_time' => current_time('mysql')
                ],
                ['id' => $review_id]
            );

            // 同时将回复插入到 todo_comments 表（comment_parent = review_id, comment_type = 'review'）
            $product_id = intval($tododb->get_var($tododb->prepare("SELECT product_id FROM {$pr_table} WHERE id = %d", $review_id)));

            $reply_id = todo_create_comment([
                'post_id' => $product_id,
                'comment_post_ID' => $product_id,
                'parent_id' => $review_id,
                'comment_parent' => $review_id,
                'author_name' => $admin_name,
                'comment_author' => $admin_name,
                'author_email' => $admin_email,
                'comment_author_email' => $admin_email,
                'content' => $reply_content_for_store,
                'comment_content' => $reply_content_for_store,
                'comment_type' => 'review',
                'comment_approved' => 1,
            ]);
            
            if ($reply_id || $review_id > 0) {
                $message = '回复已提交';
                if ($notify_user && function_exists('todo_send_review_reply_notification')) {
                    todo_send_review_reply_notification($review_id, $reply_content_for_email, $template_slug);
                }
            } else {
                $message = '回复评价失败';
                $message_type = 'error';
            }
            break;
    }
}

// 获取筛选参数
$status_filter = sanitize_text_field($_GET['status'] ?? '');
$product_filter = intval($_GET['product'] ?? 0);
$rating_filter = intval($_GET['rating'] ?? 0);
$search = sanitize_text_field($_GET['search'] ?? '');
$per_page = 20;
$current_page = max(1, intval($_GET['paged'] ?? 1));
$offset = ($current_page - 1) * $per_page;

// 获取评价列表
$reviews = [];
$total_reviews = 0;
$total_pages = 0;
$review_stats = [
    'total' => 0,
    'approve' => 0,
    'approved' => 0,
    'pending' => 0,
    'reject' => 0,
    'avg_rating' => 0,
];
try {
    $reviews = todo_get_reviews($status_filter, $product_filter, $rating_filter, $search, $per_page, $offset);
    $total_reviews = todo_count_reviews($status_filter, $product_filter, $rating_filter, $search);
    $total_pages = ceil($total_reviews / $per_page);
    $review_stats = todo_get_review_stats();
} catch (Throwable $e) {
    $message = '评价数据加载失败，请稍后重试。';
    $message_type = 'error';
}

/**
 * 获取评价列表
 */
function todo_get_reviews($status = '', $product_id = 0, $rating = 0, $search = '', $limit = 20, $offset = 0)
{
    global $tododb;
    $pr_table = $tododb->prefix . 'product_reviews';
    $comments_table = $tododb->prefix . 'comments';
    $orders_table = $tododb->prefix . 'orders';
    $users_table = $tododb->prefix . 'users';
    $posts_table = $tododb->prefix . 'posts';

    $where = ["1=1"];
    $params = [];

    if ($status !== '') {
        if ($status === 'approve' || $status === 'approved') {
            $where[] = "pr.status = 1";
        } elseif ($status === 'pending') {
            $where[] = "pr.status = 0";
        } elseif ($status === 'reject' || $status === 'trash') {
            $where[] = "pr.status = 2";
        }
    }

    if ($product_id > 0) {
        $where[] = "pr.product_id = ?";
        $params[] = $product_id;
    }

    if ($rating > 0) {
        $where[] = "pr.rating = ?";
        $params[] = $rating;
    }

    if ($search !== '') {
        $where[] = "(pr.content LIKE ? OR p.post_title LIKE ? OR u.display_name LIKE ? OR u.user_login LIKE ?)";
        $s = '%' . $search . '%';
        $params[] = $s;
        $params[] = $s;
        $params[] = $s;
        $params[] = $s;
    }

    $where_sql = implode(' AND ', $where);

    $sql = "SELECT pr.*, 
                   p.post_title as product_title, 
                   o.order_no as order_number,
                   COALESCE(u.display_name, u.user_login, '游客') as user_name
            FROM {$pr_table} pr
            LEFT JOIN {$posts_table} p ON pr.product_id = p.ID
            LEFT JOIN {$orders_table} o ON pr.order_id = o.id
            LEFT JOIN {$users_table} u ON pr.user_id = u.id
            WHERE {$where_sql}
            ORDER BY pr.created_at DESC, pr.id DESC
            LIMIT {$offset}, {$limit}";

    $rows = $tododb->get_results($sql, $params, OBJECT);
    $rows = is_array($rows) ? $rows : [];

    $results = [];
    foreach ($rows as $pr) {
        $row = new stdClass();
        $row->id = (int) $pr->id;
        $row->product_id = (int) $pr->product_id;
        $row->order_id = (int) $pr->order_id;
        $row->content = (string) ($pr->content ?? '');
        $row->spec_name = (string) ($pr->spec_name ?? '');
        $row->images = (string) ($pr->images ?? '');
        $row->status_raw = (int) ($pr->status ?? 0);
        $row->rating = (int) ($pr->rating ?? 5);
        $row->created_at = (string) ($pr->created_at ?? '');
        $row->user_name = (string) ($pr->user_name ?? '游客');
        $row->user_id = (int) ($pr->user_id ?? 0);
        $row->comment_parent = 0;
        $row->todo_depth = 0;
        $row->product_title = (string) ($pr->product_title ?? '');
        $row->order_number = (string) ($pr->order_number ?? '');

        $status_code = (int) $pr->status;
        $row->status = ($status_code === 1) ? 'approve' : (($status_code === 2) ? 'reject' : 'pending');

        $row->admin_reply = (string) ($pr->reply_content ?? '');
        $row->replied_at = (string) ($pr->reply_time ?? '');

        $results[] = $row;

        // 递归从 todo_comments 获取该评价主记录下所有的子回复（comment_type = 'review'）
        $visited = [(int) $pr->id => true];
        $fetch_replies = function($parent_id, $depth) use (&$fetch_replies, &$visited, $tododb, $comments_table, $users_table, $pr, $row) {
            if ($depth > 10 || $parent_id <= 0) {
                return [];
            }
            $rep_list = [];
            $replies = $tododb->get_results($tododb->prepare(
                "SELECT c.*, COALESCE(u.display_name, u.user_login, c.comment_author) as reply_author_name 
                 FROM {$comments_table} c 
                 LEFT JOIN {$users_table} u ON c.user_id = u.id 
                 WHERE c.comment_parent = %d AND c.comment_type = 'review'
                 ORDER BY c.comment_ID ASC",
                $parent_id
            ), [], 'OBJECT');

            if (!empty($replies) && is_array($replies)) {
                foreach ($replies as $rep) {
                    $cid = (int) $rep->comment_ID;
                    if ($cid <= 0 || isset($visited[$cid])) {
                        continue;
                    }
                    $visited[$cid] = true;

                    $r_row = new stdClass();
                    $r_row->id = $cid;
                    $r_row->product_id = (int) $rep->comment_post_ID;
                    $r_row->order_id = (int) $pr->order_id;
                    $r_row->content = (string) ($rep->comment_content ?? '');
                    $r_row->spec_name = '';
                    $r_row->images = '';
                    $r_row->status_raw = (string) ($rep->comment_approved ?? '1');
                    $r_row->rating = 0;
                    $r_row->created_at = (string) ($rep->comment_date ?? '');
                    $r_row->user_name = (string) ($rep->reply_author_name ?: ($rep->comment_author ?: '游客'));
                    $r_row->user_id = (int) ($rep->user_id ?? 0);
                    $r_row->comment_parent = (int) $parent_id;
                    $r_row->todo_depth = $depth;
                    $r_row->product_title = $row->product_title;
                    $r_row->order_number = $row->order_number;
                    $r_row->status = ($rep->comment_approved == '1') ? 'approve' : (($rep->comment_approved == 'trash') ? 'reject' : 'pending');
                    $r_row->admin_reply = '';
                    $r_row->replied_at = '';

                    $rep_list[] = $r_row;

                    // 递归查找深层子回复
                    $children = $fetch_replies($cid, $depth + 1);
                    if (!empty($children)) {
                        $rep_list = array_merge($rep_list, $children);
                    }
                }
            }
            return $rep_list;
        };

        $child_replies = $fetch_replies((int) $pr->id, 1);
        if (!empty($child_replies)) {
            $results = array_merge($results, $child_replies);
        }
    }

    return $results;
}

/**
 * 统计评价数量
 */
function todo_count_reviews($status = '', $product_id = 0, $rating = 0, $search = '')
{
    global $tododb;
    $pr_table = $tododb->prefix . 'product_reviews';
    $users_table = $tododb->prefix . 'users';
    $posts_table = $tododb->prefix . 'posts';

    $where = ["1=1"];
    $params = [];

    if ($status !== '') {
        if ($status === 'approve' || $status === 'approved') {
            $where[] = "pr.status = 1";
        } elseif ($status === 'pending') {
            $where[] = "pr.status = 0";
        } elseif ($status === 'reject' || $status === 'trash') {
            $where[] = "pr.status = 2";
        }
    }

    if ($product_id > 0) {
        $where[] = "pr.product_id = ?";
        $params[] = $product_id;
    }

    if ($rating > 0) {
        $where[] = "pr.rating = ?";
        $params[] = $rating;
    }

    if ($search !== '') {
        $where[] = "(pr.content LIKE ? OR p.post_title LIKE ? OR u.display_name LIKE ? OR u.user_login LIKE ?)";
        $s = '%' . $search . '%';
        $params[] = $s;
        $params[] = $s;
        $params[] = $s;
        $params[] = $s;
    }

    $where_sql = implode(' AND ', $where);
    $sql = "SELECT COUNT(*) FROM {$pr_table} pr
            LEFT JOIN {$posts_table} p ON pr.product_id = p.ID
            LEFT JOIN {$users_table} u ON pr.user_id = u.id
            WHERE {$where_sql}";

    return (int) $tododb->get_var($sql, $params);
}

/**
 * 获取评价统计
 */
function todo_get_review_stats()
{
    global $tododb;
    $pr_table = $tododb->prefix . 'product_reviews';

    $stats = $tododb->get_results("
        SELECT 
            status, 
            COUNT(*) as count
        FROM {$pr_table}
        GROUP BY status
    ", ARRAY_A);

    $result = [
        'total' => 0,
        'approve' => 0,
        'approved' => 0,
        'pending' => 0,
        'reject' => 0
    ];

    if (is_array($stats)) {
        foreach ($stats as $stat) {
            $count = intval($stat['count']);
            $st = intval($stat['status']);
            if ($st === 1) $key = 'approve';
            elseif ($st === 0) $key = 'pending';
            elseif ($st === 2) $key = 'reject';
            else $key = 'pending';

            if (isset($result[$key])) $result[$key] += $count;
            $result['total'] += $count;
        }
    }

    // 获取平均评分
    $avg_rating = $tododb->get_var("
        SELECT AVG(rating)
        FROM {$pr_table}
        WHERE status = 1
    ");
    $result['avg_rating'] = round(floatval($avg_rating), 1);
    $result['approved'] = $result['approve'];

    return $result;
}

/**
 * 更新评价状态
 */
function todo_update_review_status($review_id, $status)
{
    global $tododb;
    $review_id = intval($review_id);
    if ($review_id <= 0) return false;

    $st_val = 0;
    if ($status === 'approve' || $status === 'approved') {
        $st_val = 1;
    } elseif ($status === 'reject' || $status === 'trash') {
        $st_val = 2;
    }

    // 优先尝试更新 todo_product_reviews 表（评价主记录）
    $pr_table = $tododb->prefix . 'product_reviews';
    $updated = $tododb->update($pr_table, ['status' => $st_val], ['id' => $review_id]);

    // 同时兼顾更新可能存储在 todo_comments 中的二级/多级回复状态
    $comment_approved_val = ($st_val === 1) ? '1' : (($st_val === 2) ? 'trash' : '0');
    todo_set_comment_status($review_id, $comment_approved_val);

    return true;
}

/**
 * 添加评价
 */
function todo_add_review($product_id, $user_id, $order_id, $rating, $content, $status = 'pending')
{
    global $tododb;
    $st_val = ($status === 'approve' || $status === 'approved') ? 1 : (($status === 'reject' || $status === 'trash') ? 2 : 0);

    $pr_table = $tododb->prefix . 'product_reviews';
    $data = [
        'product_id' => intval($product_id),
        'order_id' => intval($order_id),
        'user_id' => intval($user_id),
        'rating' => intval($rating),
        'content' => sanitize_textarea_field($content),
        'status' => $st_val
    ];

    $res = $tododb->insert($pr_table, $data);
    return $res ? $tododb->insert_id : false;
}

/**
 * 删除评价
 */
function todo_delete_review($review_id)
{
    global $tododb;
    $review_id = intval($review_id);
    if ($review_id <= 0) return false;

    $pr_table = $tododb->prefix . 'product_reviews';
    $comments_table = $tododb->prefix . 'comments';
    $orders_table = $tododb->prefix . 'orders';

    // 查出所关联的 order_id 备用
    $order_id = intval($tododb->get_var($tododb->prepare("SELECT order_id FROM {$pr_table} WHERE id = %d", $review_id)));

    // 1. 删除 product_reviews 表主记录
    $tododb->query($tododb->prepare("DELETE FROM {$pr_table} WHERE id = %d", $review_id));

    // 2. 删除属于该评价的二级/多级回复 (todo_comments 中 comment_parent = review_id 且 comment_type = 'review')
    $tododb->query($tododb->prepare("DELETE FROM {$comments_table} WHERE comment_parent = %d AND comment_type = 'review'", $review_id));

    // 3. 删除可能通过 todo_comments ID 传进来的回复记录本身
    todo_delete_comment($review_id, true);

    // 4. 重置订单可评价标记 (reviewed = 0)
    if ($order_id > 0) {
        $tododb->query($tododb->prepare("DELETE FROM " . DB_PREFIX . "order_reviews WHERE order_id = %d", $order_id));
        $tododb->query($tododb->prepare("UPDATE {$orders_table} SET reviewed = 0 WHERE id = %d", $order_id));
    }

    return true;
}

/**
 * 格式化评价状态
 */
function todo_format_review_status($status)
{
    $statuses = [
        'approve' => ['label' => '已通过', 'class' => 'status-approved'],
        'pending' => ['label' => '待审核', 'class' => 'status-pending'],
        'reject' => ['label' => '已拒绝', 'class' => 'status-reject']
    ];

    return $statuses[$status] ?? ['label' => $status, 'class' => 'status-inactive'];
}

/**
 * 显示星级评分
 */
function todo_display_rating($rating)
{
    $stars = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $stars .= '<span class="star filled">★</span>';
        } else {
            $stars .= '<span class="star">☆</span>';
        }
    }
    return $stars;
}

/**
 * 获取产品列表（用于筛选）
 */
function todo_get_products_for_filter()
{
    global $tododb;

    if (!isset($tododb) || !is_object($tododb)) {
        return [];
    }
    if (method_exists($tododb, 'table_exists')) {
        if (!$tododb->table_exists('posts')) {
            return [];
        }
    }

    $posts_table = $tododb->prefix . 'posts';

    try {
        return $tododb->get_results("
            SELECT p.ID, p.post_title
            FROM $posts_table p
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            ORDER BY p.post_title ASC
            LIMIT 100
        ");
    } catch (Throwable $e) {
        return [];
    }
}

$products_for_filter = todo_get_products_for_filter();
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">评价管理</h2>
        <div class="admin-page-actions">
            <a href="javascript:void(0);" class="button button-primary" id="add-review-btn">添加评价</a>
        </div>
    </div>

    <?php if (isset($message)): ?>
        <div class="notice notice-<?php echo $message_type; ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <div class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-section">
                <h3 class="admin-card-title">评价列表</h3>
            </div>
            <div class="admin-card-actions">
                <form method="get" class="search-form">
                    <input type="hidden" name="page" value="todo-reviews">
                    <input type="text" name="search" value="<?php echo esc_attr($search); ?>"
                        placeholder="搜索评价内容、商品名称、用户名...">
                    <button type="submit" class="button button-secondary">搜索</button>
                </form>
            </div>
        </div>

        <div class="admin-card-content">
            <div class="admin-filter-bar">
                <ul class="admin-filters">
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=todo-reviews'); ?>"
                            class="<?php echo empty($status_filter) ? 'current' : ''; ?>">
                            全部 <span class="count">(<?php echo number_format($review_stats['total']); ?>)</span>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=todo-reviews&status=approve'); ?>"
                            class="<?php echo $status_filter === 'approve' ? 'current' : ''; ?>">
                            已通过 <span class="count">(<?php echo number_format($review_stats['approve']); ?>)</span>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=todo-reviews&status=pending'); ?>"
                            class="<?php echo $status_filter === 'pending' ? 'current' : ''; ?>">
                            待审核 <span class="count">(<?php echo number_format($review_stats['pending']); ?>)</span>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=todo-reviews&status=reject'); ?>"
                            class="<?php echo $status_filter === 'reject' ? 'current' : ''; ?>">
                            已拒绝 <span class="count">(<?php echo number_format($review_stats['reject']); ?>)</span>
                        </a>
                    </li>
                </ul>
            </div>

            <form class="products-sec" method="get">
                <input type="hidden" name="page" value="todo-reviews">
                <?php if ($status_filter): ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
                <?php endif; ?>
                <select name="product" style="max-width: 200px;">
                    <option value="">全部商品</option>
                    <?php foreach ($products_for_filter as $product): ?>
                        <option value="<?php echo $product->ID; ?>" <?php selected($product_filter, $product->ID); ?>>
                            <?php echo esc_html($product->post_title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="rating" style="min-width: 100px;">
                    <option value="">全部评分</option>
                    <option value="5" <?php selected($rating_filter, 5); ?>>5星</option>
                    <option value="4" <?php selected($rating_filter, 4); ?>>4星</option>
                    <option value="3" <?php selected($rating_filter, 3); ?>>3星</option>
                    <option value="2" <?php selected($rating_filter, 2); ?>>2星</option>
                    <option value="1" <?php selected($rating_filter, 1); ?>>1星</option>
                </select>
                <button type="submit" class="button button-secondary">筛选</button>
                <?php if (!empty($product_filter) || !empty($rating_filter) || $search !== ''): ?>
                    <a href="<?php echo todo_admin_url('index.php?page=todo-reviews' . ($status_filter ? '&status=' . $status_filter : '')); ?>"
                        class="btn-normal m-l-05">重置</a>
                <?php endif; ?>
            </form>

            <form method="post" id="reviews-form">
                <?php todo_nonce_field('todo_reviews_action'); ?>
                <input type="hidden" name="action" value="bulk_action">

                <div class="bulk-actions-bar">
                    <div class="bulk-actions">
                        <select name="bulk_action">
                            <option value="">批量操作</option>
                            <option value="approve">通过</option>
                            <option value="pending">待审核</option>
                            <option value="reject">拒绝</option>
                            <option value="delete">删除</option>
                        </select>
                        <button type="submit" class="button button-secondary">应用</button>
                    </div>
                </div>

                <!-- 评价列表 -->
                <table class="admin-table">
                    <thead>
                        <tr>
                            <td class="check-column">
                                <input type="checkbox" id="cb-select-all">
                            </td>
                            <th style="min-width: 240px;">商品信息</th>
                            <th style="min-width: 100px;">用户</th>
                            <th style="min-width: 120px; text-align: center;">评分</th>
                            <th style="min-width: 160px;">评价内容</th>
                            <th style="min-width: 120px;">订单号</th>
                            <th style="min-width: 80px;">状态</th>
                            <th style="width: 132px; min-width: 132px;">评价时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($reviews)): ?>
                            <tr class="empty">
                                <td colspan="8">
                                    <div class="no-items">
                                        <p>没有找到评价。</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($reviews as $review): ?>
                                <?php $is_reply = !empty($review->comment_parent); ?>
                                <tr>
                                    <th>
                                        <?php if (!$is_reply): ?>
                                            <input type="checkbox" name="review_ids[]" value="<?php echo $review->id; ?>">
                                        <?php endif; ?>
                                    </th>
                                    <td>
                                        <?php
                                        $product_id = isset($review->product_id) ? intval($review->product_id) : 0;
                                        $thumb_url = ($product_id > 0 && function_exists('get_the_post_thumbnail_url')) ? get_the_post_thumbnail_url($product_id, 'full') : '';
                                        if (!empty($thumb_url) && function_exists('todo_media_url_to_absolute')) {
                                            $thumb_url = todo_media_url_to_absolute($thumb_url);
                                        }

                                        // 规范化评价图片（后端环境严格使用 todo_media_url_to_absolute 转换成包含安装子目录的相对路径）
                                        $parsed_images = [];
                                        if (!empty($review->images)) {
                                            $raw_imgs = is_array($review->images) ? $review->images : json_decode($review->images, true);
                                            if (is_array($raw_imgs)) {
                                                foreach ($raw_imgs as $img_path) {
                                                    if (!empty($img_path)) {
                                                        $parsed_images[] = function_exists('todo_media_url_to_absolute') ? todo_media_url_to_absolute($img_path) : $img_path;
                                                    }
                                                }
                                            }
                                        }
                                        $review->images = $parsed_images;
                                        ?>
                                        <div class="product-info">
                                            <?php if (!empty($thumb_url)): ?>
                                                <div class="product-thumbnail">
                                                    <img src="<?php echo esc_url($thumb_url); ?>" alt="<?php echo esc_attr($review->product_title ?: ''); ?>" style="width:40px;height:40px;object-fit:cover;border-radius:4px;">
                                                </div>
                                            <?php endif; ?>
                                            <div class="product-details">
                                                <strong><?php echo esc_html($review->product_title ?: '商品已删除'); ?></strong>
                                                <?php if ($product_id > 0 && !$is_reply): ?>
                                                    <div class="row-actions">ID: <?php echo esc_html($product_id); ?></div>
                                                <?php endif; ?>
                                            
                                                <div class="row-actions">
                                                <?php if (!$is_reply): ?>
                                                    <?php if ($review->status !== 'approve'): ?>
                                                        <a href="<?php echo todo_admin_url('index.php?page=todo-reviews&action=approve&review_id=' . $review->id . '&_todononce=' . todo_create_nonce('todo_reviews_action')); ?>" class="approve js-todo-post-action" style="color:#0073aa;">批准</a> |
                                                    <?php endif; ?>
                                                    <a href="javascript:void(0);" class="reply-review"
                                                        data-review='<?php echo json_encode($review); ?>'>回复</a> |
                                                    <a href="javascript:void(0);" class="quick-status"
                                                        data-review-id="<?php echo $review->id; ?>"
                                                        data-current-status="<?php echo $review->status; ?>">状态</a> |
                                                    <a href="javascript:void(0);" class="view-review"
                                                        data-review='<?php echo json_encode($review); ?>'>查看</a> |
                                                    <a href="<?php echo todo_admin_url('index.php?page=comment-edit&from=todo-reviews&comment_id=' . $review->id); ?>" class="edit-review">编辑</a> |
                                                    <a href="<?php echo todo_admin_url('index.php?page=todo-reviews&action=delete&review_id=' . $review->id . '&_todononce=' . todo_create_nonce('todo_reviews_action')); ?>" class="delete-review js-todo-post-action" style="color:#a00;" data-confirm="确定删除该评价吗？">删除</a>
                                                <?php else: ?>
                                                    <?php if ($review->status !== 'approve'): ?>
                                                        <a href="<?php echo todo_admin_url('index.php?page=todo-reviews&action=approve&review_id=' . $review->id . '&_todononce=' . todo_create_nonce('todo_reviews_action')); ?>" class="approve js-todo-post-action" style="color:#0073aa;">批准</a> |
                                                    <?php endif; ?>
                                                    <a href="javascript:void(0);" class="view-review"
                                                        data-review='<?php echo json_encode($review); ?>'>查看</a> |
                                                    <a href="javascript:void(0);" class="reply-review"
                                                        data-review='<?php echo json_encode($review); ?>'>回复</a> |
                                                    <a href="<?php echo todo_admin_url('index.php?page=comment-edit&from=todo-reviews&comment_id=' . $review->id); ?>" class="edit-review">编辑</a> |
                                                    <a href="<?php echo todo_admin_url('index.php?page=todo-reviews&action=delete&review_id=' . $review->id . '&_todononce=' . todo_create_nonce('todo_reviews_action')); ?>" class="delete-review js-todo-post-action" style="color:#a00;" data-confirm="确定删除该评价吗？">删除</a>
                                                <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo esc_html($review->user_name ?: '游客'); ?>
                                        <p class="description m-0">ID: <?php echo $review->user_id; ?></p>
                                    </td>
                                    <?php if (!$is_reply): ?>
                                    <td>
                                        <div class="rating-display">
                                            <?php echo todo_display_rating($review->rating); ?>
                                            <p class="description m-0"><?php echo $review->rating; ?>/5</p>
                                        </div>
                                    </td>
                                    <td style="padding-left: <?php echo intval($review->todo_depth ?? 0) * 10; ?>px;">
                                    <?php else: ?>
                                    <td colspan="2" style="padding-left: <?php echo intval($review->todo_depth ?? 0) * 10; ?>px;">
                                    <?php endif; ?>
                                        <div class="review-content flex flex-col items-start gap-1">
                                            <?php if ($review->comment_parent > 0): ?>
                                                <?php
                                                global $tododb;
                                                $r_parent_author = '';
                                                if (class_exists('Todo_Comment_Manager')) {
                                                    $r_parent_author = (string) Todo_Comment_Manager::get_comment_meta($review->id, 'reply_to_name', true);
                                                }
                                                if ($r_parent_author === '') {
                                                    $p_row = $tododb->get_row($tododb->prepare("SELECT comment_author FROM {$tododb->prefix}comments WHERE comment_ID = %d", $review->comment_parent));
                                                    if ($p_row && !empty($p_row->comment_author)) {
                                                        $r_parent_author = (string) $p_row->comment_author;
                                                    } else {
                                                        $pr_row = $tododb->get_row($tododb->prepare("SELECT pr.user_id, COALESCE(u.display_name, u.user_login, '游客') as user_name FROM {$tododb->prefix}product_reviews pr LEFT JOIN {$tododb->prefix}users u ON pr.user_id = u.id WHERE pr.id = %d", $review->comment_parent));
                                                        if ($pr_row) {
                                                            $r_parent_author = (string) ($pr_row->user_name ?? '');
                                                        }
                                                    }
                                                }
                                                ?>
                                                <span class="reply-indicator" style="font-size: 12px; opacity: 0.5; flex-shrink: 0;">↳ 回复 @<?php echo esc_html($r_parent_author); ?>:</span>
                                            <?php endif; ?>
                                            <div style="overflow: hidden; max-height: 40px; line-height: 20px; font-size: 14px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">
                                                <?php echo todo_trim_words($review->content, 20); ?>
                                            </div>
                                            <?php if (!empty($review->images)): ?>
                                                <span style="font-size: 12px; opacity: 0.5;">[有图]</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($review->order_number)): ?>
                                            <a class="btn-normal"
                                                href="<?php echo todo_admin_url('index.php?page=todo-orders&search=' . $review->order_number); ?>">
                                                <?php echo esc_html($review->order_number); ?>
                                            </a>
                                        <?php elseif (!empty($review->order_id)): ?>
                                            <a class="btn-normal"
                                                href="<?php echo todo_admin_url('index.php?page=todo-orders&search=' . $review->order_id); ?>">
                                                <?php echo esc_html($review->order_id); ?>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $status_info = todo_format_review_status($review->status); ?>
                                        <span class="<?php echo $status_info['class']; ?>">
                                            <?php echo $status_info['label']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="tb-meta"><?php echo date('Y-m-d H:i', strtotime($review->created_at)); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>

            <!-- 分页 -->
            <?php if ($total_pages > 1): ?>
                <?php
                $pagination_add_args = array();
                if (!empty($status_filter)) {
                    $pagination_add_args['status'] = $status_filter;
                }
                if (!empty($product_filter)) {
                    $pagination_add_args['product'] = $product_filter;
                }
                if (!empty($rating_filter)) {
                    $pagination_add_args['rating'] = $rating_filter;
                }
                if (!empty($search)) {
                    $pagination_add_args['search'] = $search;
                }

                echo todo_render_pagination(array(
                    'total_items' => $total_reviews,
                    'per_page' => $per_page,
                    'current_page' => $current_page,
                    'base_url' => todo_admin_url('index.php?page=todo-reviews'),
                    'add_args' => $pagination_add_args,
                    'show_count' => true,
                ));
                ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<template id="add-review-modal-template" data-size="lg">
    <div data-slot="title">添加评价</div>
    <div data-slot="body">
        <form id="add-review-form" method="post">
            <?php todo_nonce_field('todo_reviews_action'); ?>
            <input type="hidden" name="action" value="add_review">

            <table class="form-table">
                <tr>
                    <th>商品 <span class="required">*</span></th>
                    <td>
                        <select name="product_id" id="add-product-id" style="max-width: 395px;" required>
                            <option value="">选择商品</option>
                            <?php foreach ($products_for_filter as $product): ?>
                                <option value="<?php echo $product->ID; ?>">
                                    <?php echo esc_html($product->post_title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="add-user-id">用户ID</label></th>
                    <td>
                        <input type="number" name="user_id" id="add-user-id" min="0" value="0">
                        <p class="description">0表示游客评价</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="add-order-id">订单ID</label></th>
                    <td>
                        <input type="number" name="order_id" id="add-order-id" min="0" value="0">
                        <p class="description">0表示非订单评价</p>
                    </td>
                </tr>
                <tr>
                    <th>评分 <span class="required">*</span></th>
                    <td>
                        <select name="rating" id="add-rating" required>
                            <option value="">选择评分</option>
                            <option value="5">5星 - 非常满意</option>
                            <option value="4">4星 - 满意</option>
                            <option value="3">3星 - 一般</option>
                            <option value="2">2星 - 不满意</option>
                            <option value="1">1星 - 非常不满意</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>评价内容 <span class="required">*</span></th>
                    <td>
                        <textarea name="content" id="add-content" rows="3" required></textarea>
                    </td>
                </tr>
                <tr>
                    <th><label for="add-status">状态</label></th>
                    <td>
                        <select name="status" id="add-status">
                            <option value="pending">待审核</option>
                            <option value="approve">已通过</option>
                            <option value="reject">已拒绝</option>
                        </select>
                    </td>
                </tr>
            </table>
        </form>
    </div>
    <div data-slot="footer" class="todo-modal-footer">
        <button type="button" class="button button-secondary todo-modal-cancel">取消</button>
        <button type="submit" form="add-review-form" class="button button-primary">添加评价</button>
    </div>
</template>

<template id="quick-review-status-modal-template" data-size="sm">
    <div data-slot="title">更新评价状态</div>
    <div data-slot="body">
        <form id="quick-status-form" method="post">
            <?php todo_nonce_field('todo_reviews_action'); ?>
            <input type="hidden" name="action" value="update_review_status">
            <input type="hidden" name="review_id" id="quick-review-id">

            <table class="form-table">
                <tr>
                    <th><label for="quick-status">评价状态</label></th>
                    <td>
                        <select name="status" id="quick-status" required>
                            <option value="approve">已通过</option>
                            <option value="pending">待审核</option>
                            <option value="reject">已拒绝</option>
                        </select>
                    </td>
                </tr>
            </table>
        </form>
    </div>
    <div data-slot="footer" class="todo-modal-footer">
        <button type="button" class="button button-secondary todo-modal-cancel">取消</button>
        <button type="submit" form="quick-status-form" class="button button-primary">更新状态</button>
    </div>
</template>

<template id="reply-review-modal-template">
    <div data-slot="title">回复评价</div>
    <div data-slot="body">
        <form id="reply-review-form" method="post">
            <?php todo_nonce_field('todo_reviews_action'); ?>
            <input type="hidden" name="action" value="reply_review">
            <input type="hidden" name="review_id" id="reply-review-id">

            <div class="form-group">
                <label for="reply-content">回复内容：</label>
                <textarea name="reply_content" id="reply-content" rows="3" style="width: 100%;" required></textarea>
            </div>

            <div class="form-group">
                <label>
                    <input type="checkbox" name="send_email" value="1">
                    发送邮件通知用户
                </label>
            </div>

            <div class="form-group">
                <label for="reply-template">邮件通知模板：</label>
                <select id="reply-template" name="template_slug">
                    <option value="">-- 邮件模板(可选) --</option>
                </select>
                <p class="description">您可以在"工具 > 邮件设置"中 <a href="<?php echo todo_admin_url('index.php?page=mail&tab=templates'); ?>">管理模版</a></p>
            </div>
        </form>
    </div>
    <div data-slot="footer" class="todo-modal-footer">
        <button type="button" class="button button-secondary todo-modal-cancel">取消</button>
        <button type="submit" form="reply-review-form" class="button button-primary">提交回复</button>
    </div>
</template>

<template id="view-review-modal-template" data-size="lg">
    <div data-slot="title">查看评价详情</div>
    <div data-slot="body">
        <div id="review-details"></div>
    </div>
</template>

<style>
    .products-sec {
        float: right;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
    }

    .mbody .products-sec {
        float: unset !important;
        margin-bottom: 10px;
    }
    .rating-display {
        text-align: center;
    }

    .star {
        color: #ffc107;
        font-size: 16px;
    }

    .star.filled {
        color: #ff9800;
    }

    .review-content {
        max-width: 200px;
        word-wrap: break-word;
        font-size: 14px;
    }

    .product-info {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .product-thumbnail {
        flex-shrink: 0;
    }

    .product-thumbnail img {
        vertical-align: middle;
        font-size: 12px;
        overflow: hidden;
        display: block;
    }

    .product-details {
        flex: 1;
    }
</style>

<script type="text/javascript">
    jQuery(document).ready(function ($) {
        // 全选/取消全选
        $('#cb-select-all').on('change', function () {
            $('input[name="review_ids[]"]').prop('checked', this.checked);
        });

        // 添加评价
        $('#add-review-btn').on('click', function (e) {
            e.preventDefault();
            new TodoModal({
                templateId: 'add-review-modal-template',
                destroyOnClose: true,
                onOpen: function(modal) {
                    var $modal = $(modal.element);
                    var $form = $modal.find('form');
                    
                    // AJAX 提交，确保提交到当前 iframe 地址
                    $form.on('submit', function(e) {
                        e.preventDefault();
                        var formData = new FormData(this);
                        
                        $.ajax({
                            url: window.location.href,
                            type: 'POST',
                            data: formData,
                            processData: false,
                            contentType: false,
                            success: function(response) {
                                var text = (typeof response === 'string') ? response : '';
                                if (text && (text.indexOf('Security check failed') !== -1 || text.indexOf('评价添加失败') !== -1)) {
                                    TodoModal.error('添加评价失败，请检查权限与必填项');
                                    return;
                                }
                                modal.close();
                                window.location.reload();
                            },
                            error: function(xhr) {
                                var detail = '';
                                try {
                                    detail = (xhr && xhr.responseText) ? String(xhr.responseText).slice(0, 200) : '';
                                } catch (e) {}
                                TodoModal.error('添加评价失败，请重试' + (detail ? ': ' + detail : ''));
                            }
                        });
                    });
                }
            }).open();
        });

        // 快速状态更新
        $('.quick-status').on('click', function (e) {
            e.preventDefault();
            var reviewId = $(this).data('review-id');
            var currentStatus = $(this).data('current-status');
            const modal = new TodoModal({
                templateId: 'quick-review-status-modal-template',
                destroyOnClose: true,
                onOpen: function(modal) {
                    var $modal = $(modal.element);
                    $modal.find('#quick-review-id').val(reviewId);
                    $modal.find('#quick-status').val(currentStatus);
                    
                    // AJAX 提交，确保提交到当前 iframe 地址
                    var $form = $modal.find('form');
                    $form.on('submit', function(e) {
                        e.preventDefault();
                        var formData = new FormData(this);
                        
                        $.ajax({
                            url: window.location.href,
                            type: 'POST',
                            data: formData,
                            processData: false,
                            contentType: false,
                            success: function(response) {
                                modal.close();
                                window.location.reload();
                            },
                            error: function(xhr) {
                                var detail = '';
                                try {
                                    detail = (xhr && xhr.responseText) ? String(xhr.responseText).slice(0, 200) : '';
                                } catch (e) {}
                                TodoModal.error('更新失败，请重试' + (detail ? ': ' + detail : ''));
                            }
                        });
                    });
                }
            });
            modal.open();
        });

        // 回复评价
        $('.reply-review').on('click', function (e) {
            e.preventDefault();
            var review = $(this).data('review');
            const modal = new TodoModal({
                templateId: 'reply-review-modal-template',
                destroyOnClose: true,
                onOpen: function(modal) {
                    var $modal = $(modal.element);
                    $modal.find('#reply-review-id').val(review.id);
                    if (review.admin_reply) {
                        $modal.find('#reply-content').val(review.admin_reply);
                    }
                    
                    var templateSelect = $modal.find('#reply-template');
                    var $replyInput = $modal.find('#reply-content');
                    var $sendEmail = $modal.find('input[name="send_email"]');
                    function syncReplyRequired() {
                        var shouldSkip = $sendEmail.is(':checked') && String(templateSelect.val() || '').trim() !== '';
                        $replyInput.prop('required', !shouldSkip);
                    }
                    if (templateSelect.length) {
                        fetch('admin-ajax.php?action=get_templates_by_category&category=review_reply')
                            .then(response => response.json())
                            .then(data => {
                                if (data.success && data.data) {
                                    data.data.forEach(tpl => {
                                        templateSelect.append('<option value="' + tpl.slug + '">' + tpl.name + '</option>');
                                    });
                                }
                                syncReplyRequired();
                            });

                        templateSelect.on('change', function() {
                            var slug = $(this).val();
                            syncReplyRequired();
                            if (slug) {
                                fetch('admin-ajax.php?action=get_template_content&id=0&slug=' + slug)
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success && data.data) {
                                            //$modal.find('#reply-content').val(data.data.content.replace(/<[^>]*>?/gm, ''));
                                        }
                                    });
                            }
                        });
                    }
                    if ($sendEmail.length) {
                        $sendEmail.on('change', syncReplyRequired);
                    }
                    syncReplyRequired();

                    // AJAX 提交
                    var $form = $modal.find('form');
                    $form.on('submit', function(e) {
                        e.preventDefault();
                        if ($form.attr('data-submitting') === '1') {
                            return;
                        }
                        $form.attr('data-submitting', '1');
                        var $btn = $modal.find('.todo-modal-footer .button-primary');
                        var btnText = $btn.length ? $btn.text() : '';
                        if ($btn.length) {
                            $btn.prop('disabled', true);
                            $btn.text('发送中...');
                        }
                        var formData = new FormData(this);
                        
                        $.ajax({
                            url: window.location.href,
                            type: 'POST',
                            data: formData,
                            processData: false,
                            contentType: false,
                            success: function(response) {
                                modal.close();
                                window.location.reload();
                            },
                            error: function(xhr) {
                                var detail = '';
                                try {
                                    detail = (xhr && xhr.responseText) ? String(xhr.responseText).slice(0, 200) : '';
                                } catch (e) {}
                                TodoModal.error('回复失败，请重试' + (detail ? ': ' + detail : ''));
                                $form.attr('data-submitting', '0');
                                if ($btn.length) {
                                    $btn.prop('disabled', false);
                                    $btn.text(btnText);
                                }
                            }
                        });
                    });
                }
            });
            modal.open();
        });

        // 查看评价详情
        $('.view-review').on('click', function (e) {
            e.preventDefault();
            var review = $(this).data('review');
            var imagesHtml = '';
            var rawImages = review.images;
            var imgList = [];
            if (typeof rawImages === 'string' && rawImages) {
                try { imgList = JSON.parse(rawImages); } catch(e) {}
            } else if (Array.isArray(rawImages)) {
                imgList = rawImages;
            }
            if (Array.isArray(imgList) && imgList.length > 0) {
                imagesHtml = '<div class="comment-images-grid" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;margin-bottom:8px;">' +
                    imgList.map(function(imgUrl) {
                        return '<a href="' + imgUrl + '" target="_blank"><img src="' + imgUrl + '" style="width:72px;height:72px;object-fit:cover;border-radius:4px;border:1px solid #eee;"></a>';
                    }).join('') +
                    '</div>';
            }

            var detailsHtml = '<ul class="detail">' +
                '<li class="detail-label">商品:</li>' +
                '<li>' + (review.product_title || '商品已删除') + ' (ID: ' + review.product_id + ')</li>' +
                '<li class="detail-label">用户:</li>' +
                '<li>' + (review.user_name || '游客') + ' (ID: ' + review.user_id + ')</li>' +
                '<li class="detail-label">评分:</li>' +
                '<li>' + review.rating + '/5 星</li>' +
                '<li class="detail-label">评价内容:</li>' +
                '<li>' + review.content + imagesHtml +'</li>' +
                (review.admin_reply ? '<li class="detail-label" style="color: #d63638;">商家回复:</li><li style="color: #d63638;">' + review.admin_reply + (review.replied_at ? ' <br><small>' + review.replied_at + '</small>' : '') + '</li>' : '') +
                '<li class="detail-label">订单号:</li>' +
                '<li>' + (review.order_number || review.order_id || '无') + '</li>' +
                '<li class="detail-label">状态:</li>' +
                '<li>' + review.status + '</li>' +
                '<li class="detail-label">评价时间:</li>' +
                '<li>' + review.created_at + '</li>' +
                '</ul>';

            const modal = new TodoModal({
                templateId: 'view-review-modal-template',
                destroyOnClose: true
            });
            const $ctx = $(modal.element);
            $ctx.find('#review-details').html(detailsHtml);
            modal.open();
        });

        // 删除（单条：包含评价与回复）
        $('.delete-review').on('click', function (e) {
            e.preventDefault();
            var href = $(this).attr('href');
            if (!href) {
                return;
            }
            if (typeof TodoModal === 'undefined') {
                window.location.href = href;
                return;
            }
            TodoModal.deleteConfirm({
                title: '确认删除',
                message: '确定要永久删除这条内容吗？',
                onConfirm: function () {
                    window.location.href = href;
                }
            });
        });

        // 批量操作确认
        $('#reviews-form').on('submit', function (e) {
            var action = $('select[name="bulk_action"]').val();
            var checkedItems = $('input[name="review_ids[]"]:checked').length;

            if (!action) {
                e.preventDefault();
                TodoModal.warning('请选择批量操作');
                return false;
            }

            if (checkedItems === 0) {
                e.preventDefault();
                TodoModal.warning('请选择要操作的评价');
                return false;
            }

            var actionText = $('select[name="bulk_action"] option:selected').text();
            var form = this;

            if (action === 'delete') {
                e.preventDefault();
                TodoModal.deleteConfirm({
                    title: '确认删除评价',
                    message: '确定要删除 ' + checkedItems + ' 个评价吗？',
                    onConfirm: function () {
                        form.submit();
                    }
                });
            } else {
                e.preventDefault();
                TodoModal.confirm({
                    title: '确认操作',
                    message: '确定要对 ' + checkedItems + ' 个评价执行"' + actionText + '"操作吗？',
                    onConfirm: function () {
                        form.submit();
                    }
                });
            }
        });
    });
</script>
