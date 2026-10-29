<?php
/**
 * Points System Admin Page
 *
 * @package TodoCMS
 */

if (!defined('IS_ADMIN')) {
    exit('Direct access not permitted');
}

if (!current_user_can('manage_points')) {
    todo_die('您没有足够的权限访问此页面。');
}

$points_mgr = Points_Manager::get_instance();

$message = '';
$error = '';

// Handle Settings Update
if (isset($_POST['save_points_settings'])) {
    if (class_exists('Todo_Security_Manager') && Todo_Security_Manager::verify_request_csrf('points_settings', '_pointsnonce')) {
        update_option('points_checkin_reward', intval($_POST['checkin_reward']));
        update_option('points_to_currency_ratio', intval($_POST['ratio']));
        update_option('points_usable_categories', sanitize_text_field($_POST['usable_categories']));
        $message = '设置已保存';
    } else {
        $error = '安全验证失败';
    }
}

// Handle Manual Adjustment
if (isset($_POST['adjust_points'])) {
    if (class_exists('Todo_Security_Manager') && Todo_Security_Manager::verify_request_csrf('points_adjust', '_adjustnonce')) {
        $user_id = intval($_POST['adj_user_id']);
        $amount = floatval($_POST['adj_amount']);
        $adj_type = $_POST['adj_type']; // add or deduct
        $desc = sanitize_text_field($_POST['adj_desc']);
        
        if ($user_id > 0 && $amount != 0) {
            if ($adj_type === 'add') {
                $points_mgr->add_points($user_id, $amount, 'admin_adjust', $desc ?: '管理员手动增加');
                $message = '积分已成功增加';
            } else {
                if ($points_mgr->deduct_points($user_id, $amount, 'admin_adjust', $desc ?: '管理员手动扣除')) {
                    $message = '积分已成功扣除';
                } else {
                    $error = '余额不足，扣除失败';
                }
            }
        } else {
            $error = '参数错误';
        }
    } else {
        $error = '安全验证失败';
    }
}

// Get current settings
$checkin_reward = get_option('points_checkin_reward', 10);
$ratio = get_option('points_to_currency_ratio', 100);
$usable_categories = get_option('points_usable_categories', '');

// Get Statistics
global $tododb;
$total_points = $tododb->get_var("SELECT SUM(CAST(meta_value AS DECIMAL(10,2))) FROM " . TABLE_PREFIX . "user_meta WHERE meta_key = '_user_points'");

// Get History
$paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20;
$offset = ($paged - 1) * $per_page;
$history = $tododb->get_results(
    "SELECT l.*, u.user_login, u.display_name 
     FROM " . TABLE_PREFIX . "user_points_log l 
     LEFT JOIN " . TABLE_PREFIX . "users u ON l.user_id = u.ID 
     ORDER BY l.created_at DESC LIMIT $per_page OFFSET $offset",
    [],
    'ARRAY_A'
);
$total_logs = $tododb->get_var("SELECT COUNT(*) FROM " . TABLE_PREFIX . "user_points_log");
$total_pages = ceil($total_logs / $per_page);

?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title verse">“不积跬步，无以至千里；不积小流，无以成江海。”<span>——荀子《劝学》</span></h2>
    </div>

    <?php if ($message): ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div>
    <?php endif; ?>

    <div class="admin-card" style="display:flex; gap: 40px; padding: 20px;">
        <div>
            <label>系统总积分</label>
            <strong style="font-size: 24px; color:#333;"><?php echo number_format($total_points ?: 0); ?></strong>
        </div>
        <div>
            <label>积分价值比</label>
            <span style="padding-top: 5px; display: inline-block;"><?php echo $ratio; ?> 积分 = 1 <?php echo todo_get_default_currency_config()['code']; ?></span>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <h3 class="admin-card-title">基础设置</h3>
        </div>
        <div class="admin-card-content">
            <form method="post">
                <?php if (class_exists('Todo_Security_Manager')): ?>
                    <?php echo Todo_Security_Manager::csrf_field('points_settings', '_pointsnonce'); ?>
                <?php endif; ?>
                <div class="form-group">
                    <label>每日签到奖励</label>
                    <input type="number" name="checkin_reward" value="<?php echo $checkin_reward; ?>">
                    <p class="description">每位用户每日签到获取的积分数</p>
                </div>
                <div class="form-group">
                    <label>积分抵扣比例 (1<?php echo todo_get_default_currency_config()['code']; ?>=?积分)</label>
                    <input type="number" name="ratio" value="<?php echo $ratio; ?>">
                    <p class="description">例如设置100，则100积分抵扣1<?php echo todo_get_default_currency_config()['code']; ?></p>
                </div>
                <div class="form-group">
                    <label>积分使用对象 (分类ID)</label>
                    <input type="text" name="usable_categories" value="<?php echo esc_attr($usable_categories); ?>" placeholder="例如: 10,12,56">
                    <p class="description">输入可以使用积分抵扣的分类ID，多个用英文逗号隔开。留空则默认全站可使用积分抵扣。注意：积分不存在转赠规则。</p>
                </div>
                <button type="submit" name="save_points_settings" class="button button-primary">保存设置</button>
            </form>
        </div>
    </div>

    <!-- Manual Adjustment -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h3 class="admin-card-title">手动调增/调减</h3>
        </div>
        <div class="admin-card-content">
            <form method="post">
                <?php if (class_exists('Todo_Security_Manager')): ?>
                    <?php echo Todo_Security_Manager::csrf_field('points_adjust', '_adjustnonce'); ?>
                <?php endif; ?>
                <div class="form-group">
                    <label>用户ID</label>
                    <input type="number" name="adj_user_id" placeholder="用户ID" required>
                </div>
                <div class="form-group">
                    <label>变动数额</label>
                    <input type="number" step="0.01" name="adj_amount" placeholder="积分数" required>
                </div>
                <div class="form-group">
                    <label>操作类型</label>
                    <select name="adj_type">
                        <option value="add">增加 (+)</option>
                        <option value="deduct">扣除 (-)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>备注描述</label>
                    <input type="text" name="adj_desc" placeholder="例如：活动奖励">
                </div>
                <button type="submit" name="adjust_points" class="button button-primary">确认调整</button>
            </form>
        </div>
    </div>
    
    <div class="admin-card">
        <div class="admin-card-header">
            <h3 class="admin-card-title">积分变动明细</h3>
        </div>
        <div class="admin-card-content">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th style="min-width: 170px;">时间</th>
                        <th>用户</th>
                        <th style="min-width: 170px;">描述</th>
                        <th style="text-align: center;">行为</th>
                        <th style="text-align: right;">数额</th>
                        <th style="text-align: right;">变动后</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($history)): ?>
                        <tr class="empty">
                            <td colspan="6">
                                <div class="no-items">
                                    <p>暂无变动记录。</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($history as $log): ?>
                            <tr>
                                <td><small><?php echo $log['created_at']; ?></small></td>
                                <td>
                                    <strong><?php echo esc_html($log['display_name'] ?: $log['user_login']); ?></strong>
                                    <p class="description m-0">ID: <?php echo $log['user_id']; ?></p>
                                </td>
                                <td style="font-size: 12px;"><?php echo esc_html($log['description']); ?></td>
                                <td style="text-align: center;"><span class="badge"><?php echo $log['action']; ?></span></td>
                                <td style="text-align: right;">
                                    <span style="color: <?php echo $log['amount'] > 0 ? '#1D8E52' : '#E53E3E'; ?>; font-weight:700;">
                                        <?php echo $log['amount'] > 0 ? '+' : ''; ?><?php echo $log['amount']; ?>
                                    </span>
                                </td>
                                <td style="text-align: right;"><?php echo $log['balance_after']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
                <?php echo todo_render_pagination([
                    'total_items' => $total_logs,
                    'per_page' => $per_page,
                    'current_page' => $paged,
                    'base_url' => todo_admin_url('index.php?page=points-system')
                ]); ?>
            <?php endif; ?>
        </div>
    </div>
</div>
