<?php
/**
 * 评论系统概览页面
 * 
 * @package TodoCMS
 * @subpackage Admin/Comment
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

// 检查用户权限
if (!current_user_can('manage_comments')) {
    http_response_code(403);
    todo_die('You do not have sufficient permissions to access this page.', '系统错误', array('response' => 403));
}

// 获取评论统计数据
function get_comment_stats() {
    global $tododb;
    
    $stats = array(
        'article_comments' => 0,
        'product_inquiries' => 0,
        'website_messages' => 0,
        'email_subscriptions' => 0,
        'pending_comments' => 0
    );
    
    try {
        // 文章评论统计 - 通过JOIN posts表获取文章类型的评论（排除评价类型的评论 comment_type = 'review'）
        $article_comments = $tododb->get_var("
            SELECT COUNT(*) FROM {$tododb->prefix}comments c
            LEFT JOIN {$tododb->prefix}posts p ON c.comment_post_ID = p.ID
            WHERE p.post_type = 'article' AND c.comment_approved != 'trash' AND (c.comment_type IS NULL OR c.comment_type != 'review')
        ");
        $stats['article_comments'] = $article_comments ? intval($article_comments) : 0;
        
        // 产品咨询统计 - 通过JOIN posts表获取产品类型的评论（排除评价类型的评论 comment_type = 'review'）
        $product_inquiries = $tododb->get_var("
            SELECT COUNT(*) FROM {$tododb->prefix}comments c
            LEFT JOIN {$tododb->prefix}posts p ON c.comment_post_ID = p.ID
            WHERE p.post_type = 'product' AND c.comment_approved != 'trash' AND (c.comment_type IS NULL OR c.comment_type != 'review')
        ");
        $stats['product_inquiries'] = $product_inquiries ? intval($product_inquiries) : 0;
        
        // 网站留言统计 - 直接从 posts 表查询 guestbook 类型的文章
        $website_messages = $tododb->get_var("
            SELECT COUNT(*) FROM {$tododb->prefix}posts 
            WHERE post_type = 'guestbook' AND post_status != 'trash'
        ");
        $stats['website_messages'] = $website_messages ? intval($website_messages) : 0;
        
        // 邮件订阅统计
        $email_subscriptions = $tododb->get_var("
            SELECT COUNT(*) FROM {$tododb->prefix}email_subscribers 
            WHERE status = 'active'
        ");
        $stats['email_subscriptions'] = $email_subscriptions ? intval($email_subscriptions) : 0;
        
        // 待审核评论（排除评价类型的评论）
        $pending_comments = $tododb->get_var("
            SELECT COUNT(*) FROM {$tododb->prefix}comments 
            WHERE comment_approved = '0' AND (comment_type IS NULL OR comment_type != 'review')
        ");
        $stats['pending_comments'] = $pending_comments ? intval($pending_comments) : 0;
    } catch (Exception $e) {
        error_log('Error getting comment stats: ' . $e->getMessage());
    }
    
    return $stats;
}

// 获取最近活动（包括评论和网站留言）
function get_recent_activities() {
    global $tododb;
    
    try {
        $prefix = $tododb->prefix;
        
        $activities = $tododb->get_results("
            SELECT c.comment_ID, c.comment_post_ID, c.comment_author, c.comment_author_email,
                   c.comment_date, c.comment_content, c.comment_approved, c.comment_type,
                   c.comment_parent, p.post_title, p.post_type, 'comment' AS source_type
            FROM {$prefix}comments c
            LEFT JOIN {$prefix}posts p ON c.comment_post_ID = p.ID
            WHERE c.comment_approved != 'trash' AND (c.comment_type IS NULL OR c.comment_type != 'review')
            
            UNION ALL
            
            SELECT p2.ID AS comment_ID, p2.ID AS comment_post_ID,
                   COALESCE(cf_name.field_value, u.display_name, '匿名') AS comment_author,
                   COALESCE(cf_email.field_value, u.user_email, '') AS comment_author_email,
                   p2.post_date AS comment_date, p2.post_content AS comment_content,
                   CASE WHEN p2.post_status = 'publish' THEN '1'
                        WHEN p2.post_status IN ('draft','pending') THEN '0'
                        ELSE p2.post_status END AS comment_approved,
                   'guestbook' AS comment_type, 0 AS comment_parent,
                   p2.post_title, p2.post_type, 'post' AS source_type
            FROM {$prefix}posts p2
            LEFT JOIN {$prefix}post_custom_fields cf_name ON cf_name.post_id = p2.ID AND cf_name.field_key = 'contact_name'
            LEFT JOIN {$prefix}post_custom_fields cf_email ON cf_email.post_id = p2.ID AND cf_email.field_key = 'contact_email'
            LEFT JOIN {$prefix}users u ON u.ID = p2.post_author
            WHERE p2.post_type = 'guestbook' AND p2.post_status != 'trash'
            
            ORDER BY comment_date DESC
            LIMIT 10
        ");
        
        // 补充查询 model_fields_guestbook 表中的联系人姓名和邮箱（部分留言的姓名/邮箱仅存于此表）
        if (!empty($activities)) {
            $guestbook_ids = [];
            foreach ($activities as $act) {
                if (
                    ($act->source_type ?? '') === 'post'
                    && ($act->post_type ?? '') === 'guestbook'
                    && !empty($act->comment_post_ID)
                ) {
                    $guestbook_ids[] = (int) $act->comment_post_ID;
                }
            }
            if (!empty($guestbook_ids)) {
                try {
                    $ph = implode(',', array_fill(0, count($guestbook_ids), '?'));
                    $mf_rows = $tododb->get_results(
                        "SELECT post_id, contact_name, contact_email FROM {$prefix}model_fields_guestbook WHERE post_id IN ({$ph})",
                        $guestbook_ids
                    );
                    if (is_array($mf_rows)) {
                        $mf_map = [];
                        foreach ($mf_rows as $r) {
                            if (is_object($r) && !empty($r->post_id)) {
                                $pid = (int) $r->post_id;
                                $mf_map[$pid] = [
                                    'name'  => !empty($r->contact_name) ? (string) $r->contact_name : '',
                                    'email' => !empty($r->contact_email) ? (string) $r->contact_email : '',
                                ];
                            }
                        }
                        foreach ($activities as $act) {
                            $pid = (int) ($act->comment_post_ID ?? 0);
                            if (isset($mf_map[$pid])) {
                                if (($act->comment_author === '匿名' || $act->comment_author === '') && $mf_map[$pid]['name'] !== '') {
                                    $act->comment_author = $mf_map[$pid]['name'];
                                }
                                if (($act->comment_author_email === '' || $act->comment_author_email === null) && $mf_map[$pid]['email'] !== '') {
                                    $act->comment_author_email = $mf_map[$pid]['email'];
                                }
                            }
                        }
                    }
                } catch (\Exception $ignored) {
                    // model_fields_guestbook 表可能不存在，静默回退
                }
            }
        }
        
        return $activities ?: array();
    } catch (Exception $e) {
        error_log('Error getting recent activities: ' . $e->getMessage());
        return array();
    }
}

// 计算人性化时间差
if (!function_exists('human_time_diff')) {
    function human_time_diff($from, $to = '') {
        if (empty($to)) {
            $to = current_time('timestamp');
        }
        
        if (!is_numeric($from)) {
            $from = strtotime($from);
        }
        if (!is_numeric($to)) {
            $to = strtotime($to);
        }
        
        $diff = abs($to - $from);
        
        if ($diff < HOUR_IN_SECONDS) {
            $mins = round($diff / MINUTE_IN_SECONDS);
            if ($mins <= 1) {
                return '刚刚';
            }
            return $mins . '分钟前';
        } elseif ($diff < DAY_IN_SECONDS) {
            $hours = round($diff / HOUR_IN_SECONDS);
            return $hours . '小时前';
        } elseif ($diff < WEEK_IN_SECONDS) {
            $days = round($diff / DAY_IN_SECONDS);
            return $days . '天前';
        } elseif ($diff < MONTH_IN_SECONDS) {
            $weeks = round($diff / WEEK_IN_SECONDS);
            return $weeks . '周前';
        } elseif ($diff < YEAR_IN_SECONDS) {
            $months = round($diff / MONTH_IN_SECONDS);
            return $months . '个月前';
        } else {
            $years = round($diff / YEAR_IN_SECONDS);
            return $years . '年前';
        }
    }
}

// 定义时间常量（如果未定义）
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('WEEK_IN_SECONDS')) {
    define('WEEK_IN_SECONDS', 604800);
}
if (!defined('MONTH_IN_SECONDS')) {
    define('MONTH_IN_SECONDS', 2592000);
}
if (!defined('YEAR_IN_SECONDS')) {
    define('YEAR_IN_SECONDS', 31536000);
}

$stats = get_comment_stats();
$recent_activities = get_recent_activities();
?>

<div class="admin-page">
    
    <div class="admin-card">
        <div class="admin-card-content">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                <div style="background: linear-gradient(180deg, #effbf9, transparent);border-radius: 4px;padding: 20px;display: flex;align-items: start;flex-direction: column;">
                    <h3 style="font-size: 28px;line-height: normal;"><?php echo number_format($stats['article_comments']); ?></h3>
                    <p class="description">文章评论</p>
                    <a href="<?php echo todo_admin_url('index.php?page=comments'); ?>" style="font-size: 12px;">查看全部</a>
                </div>
                
                <div style="background: linear-gradient(180deg, #f3fcf1, transparent);border-radius: 4px;padding: 20px;display: flex;align-items: start;flex-direction: column;">
                    <h3 style="font-size: 28px;line-height: normal;"><?php echo number_format($stats['product_inquiries']); ?></h3>
                    <p class="description">产品询盘</p>
                    <a href="<?php echo todo_admin_url('index.php?page=product-inquiries'); ?>" style="font-size: 12px;">查看全部</a>
                </div>
                
                <div style="background: linear-gradient(180deg, #fbfaf2, transparent);border-radius: 4px;padding: 20px;display: flex;align-items: start;flex-direction: column;">
                    <h3 style="font-size: 28px;line-height: normal;"><?php echo number_format($stats['website_messages']); ?></h3>
                    <p class="description">网站留言</p>
                    <a href="<?php echo todo_admin_url('index.php?page=website-messages'); ?>" style="font-size: 12px;">查看全部</a>
                </div>
                
                <div style="background: linear-gradient(180deg, #fef7f7, transparent);border-radius: 4px;padding: 20px;display: flex;align-items: start;flex-direction: column;">
                    <h3 style="font-size: 28px;line-height: normal;"><?php echo number_format($stats['email_subscriptions']); ?></h3>
                    <p class="description">邮件订阅</p>
                    <a href="<?php echo todo_admin_url('index.php?page=email-subscriptions'); ?>" style="font-size: 12px;">查看全部</a>
                </div>
                
                <div style="background: linear-gradient(180deg, #f2f1fc, transparent);border-radius: 4px;padding: 20px;display: flex;align-items: start;flex-direction: column;">
                    <h3 style="font-size: 28px;line-height: normal;"><?php echo number_format($stats['pending_comments']); ?></h3>
                    <p class="description">待审核</p>
                    <a href="<?php echo todo_admin_url('index.php?page=comment-pending'); ?>" style="font-size: 12px;">立即处理</a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 最近活动 -->
    <div class="admin-card" style="margin-top: 20px;">
        <div class="admin-card-header">
            <h3 class="admin-card-title">最近活动</h3>
        </div>
        <div class="admin-card-content">
            <div class="activity-list">
            <?php if (!empty($recent_activities)): ?>
                <?php foreach ($recent_activities as $activity): ?>
                    <div class="activity-item">
                        <div class="activity-avatar">
                            <?php echo get_avatar($activity->comment_author_email, 32); ?>
                        </div>
                        <div class="activity-content">
                            <div class="activity-header">
                                <strong><?php echo esc_html($activity->comment_author); ?></strong>
                                <span class="description"><?php 
                                    $post_type = (string) ($activity->post_type ?? '');
                                    $comment_type = (string) ($activity->comment_type ?? '');
                                    $is_reply = (!empty($activity->comment_parent) && intval($activity->comment_parent) > 0) || ((in_array($post_type, ['guestbook', 'message'], true) || in_array($comment_type, ['guestbook', 'message'], true)) && ($activity->source_type ?? 'comment') !== 'post');
                                    if (in_array($post_type, ['guestbook', 'message'], true) || in_array($comment_type, ['guestbook', 'message'], true) || $post_type === '') {
                                        echo $is_reply ? '↳回复 留言' : ($post_type === 'message' ? '发送了 留言' : '发表了 留言');
                                    } elseif ($comment_type === 'inquiry') {
                                        echo $is_reply ? '↳回复 咨询' : '咨询了 产品';
                                    } elseif ($comment_type === 'review') {
                                        echo $is_reply ? '↳回复 评价' : '评价了 商品';
                                    } elseif ($comment_type === 'comment') {
                                        if ($post_type === 'product') {
                                            echo $is_reply ? '↳回复 评价' : '评价了 商品';
                                        } else {
                                            echo $is_reply ? '↳回复 评论' : '评论了 文章';
                                        }
                                    } else {
                                        switch($post_type) {
                                            case 'article': echo $is_reply ? '↳回复 评论' : '评论了 文章'; break;
                                            case 'product': echo $is_reply ? '↳回复 评价' : '评价了 商品'; break;
                                            case 'guestbook': echo $is_reply ? '↳回复 留言' : '发表了 留言'; break;
                                            case 'message': echo $is_reply ? '↳回复 留言' : '发送了 留言'; break;
                                            default: echo $is_reply ? '↳回复 评论' : '发表了 评论'; break;
                                        }
                                    }
                                ?></span>
                                <?php if ($activity->post_title): ?>
                                    <span class="activity-target">"<?php echo esc_html($activity->post_title); ?>"</span>
                                <?php endif; ?>
                            </div>
                            <div class="activity-excerpt">
                                <?php echo esc_html(todo_trim_words(strip_tags(htmlspecialchars_decode($activity->comment_content, ENT_QUOTES)), 20)); ?>
                            </div>
                            <div class="activity-meta">
                                <span class="activity-time"><?php 
                                    $comment_timestamp = strtotime($activity->comment_date);
                                    $current_timestamp = current_time('timestamp');
                                    echo human_time_diff($comment_timestamp, $current_timestamp);
                                ?></span>
                                <span class="status-<?php echo $activity->comment_approved; ?>">
                                    <?php 
                                        switch($activity->comment_approved) {
                                            case '1': echo '已通过'; break;
                                            case '0': echo '待审核'; break;
                                            case 'spam': echo '垃圾评论'; break;
                                            case 'trash': echo '已删除'; break;
                                            default: echo $activity->comment_approved; break;
                                        }
                                    ?>
                                </span>
                            </div>
                        </div>
                        <div class="activity-actions">
                            <?php
                            $is_new_guestbook = (in_array($post_type, ['guestbook', 'message'], true) || in_array($comment_type, ['guestbook', 'message'], true)) && !$is_reply;
                            if ($is_new_guestbook):
                            ?>
                            <a href="<?php echo todo_admin_url('index.php?page=post-edit&from=website-messages&post=' . intval($activity->comment_ID)); ?>" class="btn button-secondary">编辑</a>
                            <?php else: ?>
                            <a href="<?php echo todo_admin_url('index.php?page=comment-edit&from=comment-dashboard&comment_id=' . intval($activity->comment_ID)); ?>" class="btn button-secondary">编辑</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-items">
                    <p>暂无最近活动。</p>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div>
    
</div>

<style>
.comment-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.stat-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 20px;
    display: flex;
    align-items: center;
    transition: box-shadow 0.3s ease;
}

.stat-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.stat-card.pending {
    border-left: 4px solid #d63638;
}

.stat-icon {
    font-size: 24px;
    margin-right: 15px;
    color: #666;
}

.stat-content h3 {
    margin: 0;
    font-size: 28px;
    font-weight: 500;
    color: #23282d;
}

.stat-content p {
    margin: 5px 0;
    color: #666;
    font-size: 14px;
}

.stat-link {
    font-size: 12px;
    text-decoration: none;
}

.recent-activities {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin: 20px 0;
    padding: 20px;
}

.recent-activities h2 {
    margin-top: 0;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
}

.activity-item {
    display: flex;
    align-items: flex-start;
    padding: 15px 0;
    border-bottom: 1px solid #eceef1;
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-avatar {
    margin: 3px 10px 0 0;
}

.activity-content {
    flex: 1;
}

.activity-header {
    font-size: 14px;
}

.activity-target {
    color: #0073aa;
}

.activity-excerpt {
    color: #666;
    line-height: 1.4;
    font-size: 14px;
    text-indent: 10px;
}

.activity-meta {
    font-size: 12px;
    color: #999;
}

.activity-time {
    margin-right: 10px;
}

.activity-actions {
    margin-left: 0;
}

.quick-actions {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 20px;
    margin: 20px 0;
}

.quick-actions h2 {
    margin-top: 0;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
}

.action-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.action-buttons .button {
    display: flex;
    align-items: center;
    gap: 5px;
}
</style>
