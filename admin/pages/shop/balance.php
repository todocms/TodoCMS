<?php
/**
 * TodoCMS 用户余额管理页面
 * 
 * 管理用户余额充值、消费记录等功能
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('TODO_ADMIN')) {
    exit('Direct access denied');
}

// 检查用户权限
if (!current_user_can('manage_options')) {
    todo_die(__('You do not have sufficient permissions to access this page.'));
}

global $tododb;
$balance_table = $tododb->prefix . 'member_balance';

// 处理表单提交
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF验证
    if (!todo_verify_nonce($_POST['_todononce'], 'todo_balance_action')) {
        $error = '安全验证失败，请重试。';
    } else {
        $action = sanitize_text_field($_POST['action']);

        switch ($action) {
            case 'add_balance':
                $result = todo_add_user_balance($_POST);
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;

            case 'adjust_balance':
                $result = todo_adjust_user_balance($_POST);
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;
        }

        // 处理完成后重定向，防止重复提交
        if (!empty($message) || !empty($error)) {
            if (!empty($message) && function_exists('todo_add_admin_notice')) {
                todo_add_admin_notice($message, 'success');
            }
            if (!empty($error) && function_exists('todo_add_admin_notice')) {
                todo_add_admin_notice($error, 'error');
            }
            header('Location: ' . todo_admin_url('index.php?page=todo-balance'));
            exit;
        }
    }
}

// 获取筛选参数
$user_filter = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$type_filter = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';

// 分页参数
$per_page = 20;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$offset = ($current_page - 1) * $per_page;

// 构建查询条件
$where_clauses = array('1=1');
$params = array();

if ($user_filter > 0) {
    $where_clauses[] = 'b.user_id = ?';
    $params[] = intval($user_filter);
}

if ($type_filter !== '') {
    $where_clauses[] = 'b.type = ?';
    $params[] = $type_filter;
}

if ($search) {
    $like = '%' . $search . '%';
    $where_clauses[] = '(b.description LIKE ? OR b.order_id LIKE ? OR o.order_no LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$where_clause = implode(' AND ', $where_clauses);

// 获取总数
$total_query = "SELECT COUNT(*) FROM $balance_table b LEFT JOIN {$tododb->prefix}orders o ON b.order_id = o.id WHERE $where_clause";
$total_records = intval($tododb->get_var($total_query, $params));

// 获取余额记录列表
$records_query = "SELECT b.*, o.order_no FROM $balance_table b LEFT JOIN {$tododb->prefix}orders o ON b.order_id = o.id WHERE $where_clause ORDER BY b.created_at DESC LIMIT " . intval($per_page) . " OFFSET " . intval($offset);
$balance_records = $tododb->get_results($records_query, $params);

// 计算分页
$total_pages = ceil($total_records / $per_page);

// 获取用户余额统计
$balance_stats = todo_get_balance_stats();

/**
 * 添加用户余额
 */
function todo_add_user_balance($data)
{
    global $tododb;

    $raw_user_id = isset($data['user_id']) ? $data['user_id'] : '';
    $user_ids = array_filter(array_unique(array_map('intval', explode(',', (string)$raw_user_id))));
    $amount = floatval($data['amount']);
    $type = sanitize_text_field($data['type']);
    $description = sanitize_text_field($data['description']);
    $raw_order_id = isset($data['order_id']) ? sanitize_text_field($data['order_id']) : '';
    $order_id = 0;

    if (!empty($raw_order_id)) {
        if (is_numeric($raw_order_id) && strlen($raw_order_id) < 10) {
            $order_id = intval($raw_order_id);
        } else {
            // 尝试通过订单号查询订单ID
            $order_query = "SELECT id FROM {$tododb->prefix}orders WHERE order_no = ?";
            $found_id = $tododb->get_var($order_query, [$raw_order_id]);
            if ($found_id) {
                $order_id = intval($found_id);
            } elseif (is_numeric($raw_order_id) && floatval($raw_order_id) <= 2147483647) {
                $order_id = intval($raw_order_id);
            }
        }
    }

    // 验证必填字段
    if (empty($user_ids)) {
        return ['success' => false, 'message' => '请选择至少一个用户！'];
    }
    if ($amount < 1) {
        return ['success' => false, 'message' => '充值金额必须至少为 1！'];
    }

    $success_count = 0;
    $error_count = 0;
    $table_name = $tododb->prefix . 'member_balance';

    foreach ($user_ids as $user_id) {
        // 检查用户是否存在
        $user = get_user_by('id', $user_id);
        if (!$user) {
            $error_count++;
            continue;
        }

        // 隐身保护：普通管理员无法给开发者充值
        if (get_user_meta($user_id, 'is_developer', true) === '1' && !todo_is_super_admin()) {
            $error_count++;
            continue;
        }

        // 计算变动后余额
        $current_balance = todo_get_user_balance($user_id);
        $balance_after = $current_balance + $amount;

        // 插入余额记录
        $result = $tododb->insert($table_name, [
            'user_id' => $user_id,
            'amount' => $amount,
            'balance_after' => $balance_after,
            'type' => $type,
            'description' => $description,
            'order_id' => $order_id > 0 ? $order_id : 0,
            'created_at' => current_time('mysql')
        ]);

        if ($result) {
            $success_count++;
            todo_update_user_total_balance($user_id);
        } else {
            $error_count++;
        }
    }

    if ($success_count > 0) {
        $msg = "成功为 {$success_count} 名用户充值余额。";
        if ($error_count > 0) {
            $msg .= " 其中 {$error_count} 名用户操作失败。";
        }
        return ['success' => true, 'message' => $msg];
    } else {
        return ['success' => false, 'message' => '所有选中的用户充值均失败！'];
    }
}

/**
 * 调整用户余额
 */
function todo_adjust_user_balance($data)
{
    global $tododb;

    $raw_user_id = isset($data['user_id']) ? $data['user_id'] : '';
    $user_ids = array_filter(array_unique(array_map('intval', explode(',', (string)$raw_user_id))));
    $amount = floatval($data['amount']);
    $description = sanitize_text_field($data['description']);

    // 验证必填字段
    if (empty($user_ids)) {
        return ['success' => false, 'message' => '请选择至少一个用户！'];
    }
    if ($amount == 0 || abs($amount) < 1) {
        return ['success' => false, 'message' => '调整金额绝对值必须至少为 1！'];
    }

    $success_count = 0;
    $error_count = 0;
    $insufficient_balance_count = 0;
    $table_name = $tododb->prefix . 'member_balance';
    $type = $amount > 0 ? 'admin_add' : 'admin_deduct';

    foreach ($user_ids as $user_id) {
        // 检查用户是否存在
        $user = get_user_by('id', $user_id);
        if (!$user) {
            $error_count++;
            continue;
        }

        // 隐身保护：普通管理员无法给开发者调整余额
        if (get_user_meta($user_id, 'is_developer', true) === '1' && !todo_is_super_admin()) {
            $error_count++;
            continue;
        }

        // 计算变动后余额
        $current_balance = todo_get_user_balance($user_id);
        if ($amount < 0 && $current_balance + $amount < 0) {
            $insufficient_balance_count++;
            continue;
        }
        $balance_after = $current_balance + $amount;

        $result = $tododb->insert($table_name, [
            'user_id' => $user_id,
            'amount' => $amount,
            'balance_after' => $balance_after,
            'type' => $type,
            'description' => $description ? $description : ($amount > 0 ? '管理员充值' : '管理员扣减'),
            'created_at' => current_time('mysql')
        ]);

        if ($result) {
            $success_count++;
            todo_update_user_total_balance($user_id);
        } else {
            $error_count++;
        }
    }

    if ($success_count > 0) {
        $msg = "成功为 {$success_count} 名用户调整余额。";
        if ($insufficient_balance_count > 0 || $error_count > 0) {
            $msg .= " 其中";
            if ($insufficient_balance_count > 0) $msg .= " {$insufficient_balance_count} 名用户余额不足建议充值，";
            if ($error_count > 0) $msg .= " {$error_count} 名用户操作失败。";
            $msg = rtrim($msg, '，') . "。";
        }
        return ['success' => true, 'message' => $msg];
    } else {
        $msg = "余额调整失败！";
        if ($insufficient_balance_count > 0) $msg .= " {$insufficient_balance_count} 名用户余额不足。";
        return ['success' => false, 'message' => $msg];
    }
}

/**
 * 获取余额统计
 */
function todo_get_balance_stats()
{
    global $tododb;

    $table_name = $tododb->prefix . 'member_balance';

    // 总充值金额
    $total_recharge = $tododb->get_var(
        "SELECT SUM(amount) FROM $table_name WHERE type IN ('recharge', 'admin_add') AND amount > 0"
    );

    // 总消费金额
    $total_consume = $tododb->get_var(
        "SELECT SUM(ABS(amount)) FROM $table_name WHERE type IN ('order_pay', 'admin_deduct') AND amount < 0"
    );

    // 当前总余额
    $total_balance = $tododb->get_var(
        "SELECT SUM(amount) FROM $table_name"
    );

    // 有余额的用户数
    $users_with_balance = $tododb->get_var(
        "SELECT COUNT(DISTINCT user_id) FROM $table_name 
         GROUP BY user_id 
         HAVING SUM(amount) > 0"
    );

    return [
        'total_recharge' => $total_recharge ? floatval($total_recharge) : 0,
        'total_consume' => $total_consume ? floatval($total_consume) : 0,
        'total_balance' => $total_balance ? floatval($total_balance) : 0,
        'users_with_balance' => $users_with_balance ? intval($users_with_balance) : 0
    ];
}

/**
 * 获取余额类型文本
 */
function todo_get_balance_type_text($type)
{
    $types = [
        'recharge' => '充值',
        'order_pay' => '订单支付',
        'order_refund' => '订单退款',
        'admin_add' => '管理员充值',
        'admin_deduct' => '管理员扣减',
        'reward' => '奖励',
        'other' => '其他'
    ];

    return isset($types[$type]) ? $types[$type] : $type;
}

/**
 * 格式化金额显示
 */
function todo_format_balance_amount($amount)
{
    if ($amount > 0) {
        return '<span class="amount-positive">+' . todo_format_admin_price($amount) . '</span>';
    } else {
        return '<span class="amount-negative">-' . todo_format_admin_price(abs($amount)) . '</span>';
    }
}
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">用户余额管理</h2>
        <div class="admin-page-actions">
            <button type="button" class="button button-secondary" onclick="window.location.href='?page=todo-gift-cards'">礼品卡</button>
            <button type="button" class="button button-secondary" onclick="if(window.showAdjustBalanceModal){window.showAdjustBalanceModal();}else if(window.parent&&window.parent.showAdjustBalanceModal){window.parent.showAdjustBalanceModal();}">调整余额</button>
            <button type="button" class="button button-primary" onclick="if(window.showAddBalanceModal){window.showAddBalanceModal();}else if(window.parent&&window.parent.showAddBalanceModal){window.parent.showAddBalanceModal();}">充值余额</button>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <h3 class="admin-card-title">余额记录</h3>
            <div class="admin-card-actions">
                <span class="description m-0">
                    总充值: <?php echo todo_format_admin_price($balance_stats['total_recharge']); ?> ,
                    总消费: <?php echo todo_format_admin_price($balance_stats['total_consume']); ?> ,
                    总余额: <?php echo todo_format_admin_price($balance_stats['total_balance']); ?> ,
                    有余额用户: <?php echo number_format($balance_stats['users_with_balance']); ?>人
                </span>
            </div>
        </div>

        <div class="admin-card-content">
            <div class="admin-filter-bar">
                <form method="get" class="flex flex-wrap gap-y-3">
                    <input type="hidden" name="page" value="todo-balance">

                    <select name="type" style="margin-right: 10px;">
                        <option value="">所有类型</option>
                        <option value="recharge" <?php selected($type_filter, 'recharge'); ?>>充值</option>
                        <option value="order_pay" <?php selected($type_filter, 'order_pay'); ?>>订单支付</option>
                        <option value="order_refund" <?php selected($type_filter, 'order_refund'); ?>>订单退款</option>
                        <option value="admin_add" <?php selected($type_filter, 'admin_add'); ?>>管理员充值</option>
                        <option value="admin_deduct" <?php selected($type_filter, 'admin_deduct'); ?>>管理员扣减</option>
                        <option value="reward" <?php selected($type_filter, 'reward'); ?>>奖励</option>
                    </select>

                    <input type="number" name="user_id" value="<?php echo $user_filter; ?>" placeholder="用户ID"
                        style="width: 80px; min-width: auto; margin-right: 10px;">

                    <input type="text" name="search" value="<?php echo esc_attr($search); ?>" placeholder="搜索描述或订单号..."
                        style="margin-right: 10px;">

                    <button type="submit" class="button button-secondary">筛选</button>

                    <?php if ($type_filter || $user_filter || $search): ?>
                        <a href="?page=todo-balance" class="btn-normal m-l-05" style="line-height: 35px;">重置</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- 余额记录列表 -->
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>用户</th>
                        <th>订单号</th>
                        <th style="width: 240px; min-width: 240px;">描述</th>
                        <th style="width: 150px; min-width: 150px;">时间</th>
                        <th style="min-width: 50px;">类型</th>
                        <th style="min-width: 100px; text-align: right;">金额</th>
                        <th style="min-width: 100px; text-align: right;">当前余额</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($balance_records): ?>
                        <?php foreach ($balance_records as $record): ?>
                            <?php
                            $user = get_user_by('id', $record->user_id);
                            ?>
                            <tr>
                                <td>
                                    <?php if ($user): ?>
                                        <strong><?php echo esc_html($user->get_display_name()); ?></strong>
                                        <p class="description m-0">ID: <?php echo $record->user_id; ?></p>
                                        <small><?php echo esc_html($user->get_email()); ?></small>
                                    <?php else: ?>
                                        <span class="user-deleted">用户不存在 ID: <?php echo $record->user_id; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($record->order_id): ?>
                                        <a
                                            href="<?php echo todo_admin_url('index.php?page=todo-orders&order_id=' . $record->order_id); ?>">
                                            <?php echo esc_html($record->order_no ? $record->order_no : $record->order_id); ?>
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 14px;"><?php echo esc_html($record->description); ?></td>
                                <td>
                                    <span class="tb-meta"><?php echo date('Y-m-d H:i', strtotime($record->created_at)); ?></span>
                                </td>
                                <td>
                                    <span class="balance-type balance-type-<?php echo $record->type; ?>">
                                        <?php echo todo_get_balance_type_text($record->type); ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <?php echo todo_format_balance_amount($record->amount); ?>
                                </td>
                                <td style="text-align: right;">
                                    <?php $current_user_balance = todo_get_user_balance($record->user_id); ?>
                                    <?php echo todo_format_admin_price($current_user_balance); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="empty">
                            <td colspan="7">
                                <div class="no-items">
                                    <p>没有找到余额记录。</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- 底部分页 -->
            <?php if ($total_pages > 1): ?>
                <?php
                $pagination_add_args = array();
                if (!empty($user_filter)) {
                    $pagination_add_args['user_id'] = $user_filter;
                }
                if (!empty($type_filter)) {
                    $pagination_add_args['type'] = $type_filter;
                }
                if (!empty($search)) {
                    $pagination_add_args['search'] = $search;
                }

                echo todo_render_pagination(array(
                    'total_items' => $total_records,
                    'per_page' => $per_page,
                    'current_page' => $current_page,
                    'base_url' => todo_admin_url('index.php?page=todo-balance'),
                    'add_args' => $pagination_add_args,
                    'show_count' => true,
                ));
                ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 充值余额模态框 -->
<?php
$todo_balance_form_action = todo_admin_url('index.php?page=todo-balance');
$todo_balance_iframe_mode = function_exists('todo_is_iframe_mode') && todo_is_iframe_mode();
if ($todo_balance_iframe_mode) {
    $todo_balance_form_action = add_query_arg('format', 'iframe', $todo_balance_form_action);
}
$todo_balance_form_target = $todo_balance_iframe_mode ? 'todo-admin-iframe' : '';
?>
<template id="todo-tpl-add-balance-modal">
    <form method="post" id="add-balance-form" action="<?php echo esc_url($todo_balance_form_action); ?>"<?php echo $todo_balance_form_target ? ' target="' . esc_attr($todo_balance_form_target) . '"' : ''; ?>>
        <div class="todo-modal-body-content">
            <?php todo_nonce_field('todo_balance_action'); ?>
            <input type="hidden" name="action" value="add_balance">

            <table class="form-table w-120">
                <tr>
                    <th>用户 <span class="required">*</span></th>
                    <td>
                        <div class="flex flex-wrap gap-2">
                            <input type="text" name="user_id" id="add-user-id" required>
                            <button type="button" class="button button-secondary todo-user-select-btn" data-target-input="#add-user-id"
                                data-target-info="#add-user-info" data-multiple="true" data-title="选择用户">选择用户</button>
                        </div>
                        <p class="description">输入用户ID（多个用逗号分隔）或点击选择用户</p>
                        <div id="add-user-info" style="display: none; margin-top: 10px; display: flex; flex-wrap: wrap; gap: 8px;"></div>
                    </td>
                </tr>
                <tr>
                    <th>充值金额 <span class="required">*</span></th>
                    <td>
                        <input type="number" name="amount" id="add-amount" step="1" min="1" required>
                        <?php echo todo_get_default_currency_config()['code']; ?>
                        <p class="description">充值金额，至少为 1</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="add-type">充值类型</label></th>
                    <td>
                        <select name="type" id="add-type">
                            <option value="recharge">用户充值</option>
                            <option value="admin_add">管理员充值</option>
                            <option value="reward">奖励</option>
                            <option value="other">其他</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="add-description">充值说明</label></th>
                    <td>
                        <textarea name="description" id="add-description" rows="3" class="large-text"></textarea>
                        <p class="description">充值说明，可选</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="add-order-id">关联订单</label></th>
                    <td>
                        <input type="text" name="order_id" id="add-order-id" class="regular-text">
                        <p class="description">关联的订单号，可选</p>
                    </td>
                </tr>
            </table>
        </div>
    </form>
</template>

<!-- 调整余额模态框 -->
<template id="todo-tpl-adjust-balance-modal">
    <form method="post" id="adjust-balance-form" action="<?php echo esc_url($todo_balance_form_action); ?>"<?php echo $todo_balance_form_target ? ' target="' . esc_attr($todo_balance_form_target) . '"' : ''; ?>>
        <div class="todo-modal-body-content">
            <?php todo_nonce_field('todo_balance_action'); ?>
            <input type="hidden" name="action" value="adjust_balance">

            <table class="form-table w-120">
                <tr>
                    <th>用户 <span class="required">*</span></th>
                    <td>
                        <div class="flex flex-wrap gap-2">
                            <input type="text" name="user_id" id="adjust-user-id" required>
                            <button type="button" class="button button-secondary todo-user-select-btn"
                                data-target-input="#adjust-user-id" data-target-info="#adjust-user-info"
                                data-multiple="true" data-title="选择用户">选择用户</button>
                        </div>
                        <p class="description">输入用户ID（多个用逗号分隔）或点击选择用户</p>
                        <div id="adjust-user-info" style="display: none; margin-top: 10px; display: flex; flex-wrap: wrap; gap: 8px;"></div>
                    </td>
                </tr>
                <tr>
                    <th>调整金额 <span class="required">*</span></th>
                    <td>
                        <input type="number" name="amount" id="adjust-amount" step="1" required>
                        <?php echo todo_get_default_currency_config()['code']; ?>
                        <p class="description">正数为加，负数为扣减余额，至少为 1</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="adjust-description">调整说明</label></th>
                    <td>
                        <textarea name="description" id="adjust-description" rows="3" class="large-text"></textarea>
                        <p class="description">调整说明，可选</p>
                    </td>
                </tr>
            </table>
        </div>
    </form>
</template>
<style>
    .balance-stats {
        margin: 20px 0;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .stat-card {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 20px;
        text-align: center;
    }

    .stat-card h3 {
        margin: 0 0 10px 0;
        font-size: 14px;
        color: #666;
    }

    .stat-value {
        font-size: 24px;
        font-weight: 500;
        color: #333;
    }

    .amount-positive {
        color: #46b450;
    }

    .amount-negative {
        color: #dc3232;
    }

    .balance-type {
        padding: 2px 8px;
        border-radius: 3px;
        font-size: 12px;
        font-weight: normal;
        box-shadow: inset 0 0 1px rgba(0, 0, 0, 0.4);
    }

    .balance-type-recharge {
        background: #e7f3ff;
        color: #0073aa;
    }

    .balance-type-order_pay {
        background: #fff2e7;
        color: #d63638;
    }

    .balance-type-order_refund {
        background: #e7f7e7;
        color: #46b450;
    }

    .balance-type-admin_add {
        background: #f0e7ff;
        color: #7c3aed;
    }

    .balance-type-admin_deduct {
        background: #ffe7e7;
        color: #dc3232;
    }

    .balance-type-reward {
        background: #fff7e7;
        color: #f59e0b;
    }

    .user-deleted {
        color: #999;
        font-size: 14px;
        font-style: italic;
    }

    .user-search {
        margin-bottom: 20px;
        display: flex;
        gap: 10px;
    }

    .user-search input {
        flex: 1;
    }

    .user-list {
        max-height: 300px;
        overflow-y: auto;
        border: 1px solid #ddd;
        border-radius: 4px;
    }

    .user-item {
        padding: 10px;
        border-bottom: 1px solid #eee;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .user-item:hover {
        background-color: #f5f5f5;
    }

    .user-item:last-child {
        border-bottom: none;
    }

    .user-info h4 {
        margin: 0;
        font-size: 14px;
    }

    .user-info p {
        margin: 0;
        font-size: 12px;
        color: #666;
    }

    .user-balance {
        font-weight: 500;
        color: #46b450;
    }

    /* 用户搜索结果样式 */
    .user-search-results {
        max-height: 400px;
        overflow-y: auto;
        border: 1px solid #ddd;
        border-radius: 4px;
        background: #fff;
    }

    .user-item {
        padding: 12px 15px;
        border-bottom: 1px solid #eee;
        cursor: pointer;
        transition: background-color 0.2s;
    }

    .user-item:last-child {
        border-bottom: none;
    }

    .user-item:hover {
        background-color: #f8f9fa;
    }

    .user-info .user-name {
        font-size: 14px;
        margin-bottom: 4px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .user-info .user-email {
        font-size: 12px;
        color: #666;
        margin-bottom: 4px;
    }

    .user-info .user-balance {
        font-size: 11px;
        color: #888;
    }

    .pagination-info {
        font-size: 12px;
        color: #666;
    }
</style>

<script src="<?php echo todo_admin_url('assets/js/csrf-manager.js'); ?>" type="text/javascript"></script>
<script type="text/javascript">
    (function (init) {
        if (window.jQuery && typeof window.jQuery === 'function') {
            window.jQuery(init);
            return;
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { init(window.jQuery); });
        } else {
            init(window.jQuery);
        }
    })(function ($) {
        if (typeof todoCSRF !== 'undefined') {
            if (!todoCSRF.tokens) {
                todoCSRF.tokens = {};
            }
            todoCSRF.tokens['user_search'] = {
                value: '<?php echo todo_create_nonce("user_search"); ?>',
                lastRefresh: Date.now()
            };
        }

        // Legacy modal handlers removed
    });

    let currentSelectTarget = '';

    function showAddBalanceModal() {
        currentSelectTarget = 'add';
        TodoModal.form({
            templateId: 'todo-tpl-add-balance-modal',
            title: '用户充值',
            submitText: '确认充值',
            cancelText: '取消',
            onSubmit: function(formData, form) {
                form.submit();
            }
        });
    }

    function closeAddBalanceModal() {
        TodoModal.closeAll();
    }

    function showAdjustBalanceModal() {
        currentSelectTarget = 'adjust';
        TodoModal.form({
            templateId: 'todo-tpl-adjust-balance-modal',
            title: '调整余额',
            submitText: '确认调整',
            cancelText: '取消',
            onSubmit: function(formData, form) {
                form.submit();
            }
        });
    }

    function closeAdjustBalanceModal() {
        TodoModal.closeAll();
    }

    function searchUsers() { /* 已由全局 TodoUserSelectorModal 组件接管 */ }

    function displayUserList(users, pagination) { /* 已由全局 TodoUserSelectorModal 组件接管 */
        const userList = document.getElementById('user-list');

        if (!users || users.length === 0) {
            userList.innerHTML = '<div style="text-align: center; padding: 20px; color: #6c757d;">未找到匹配的用户</div>';
            return;
        }

        let html = '<div class="user-search-results">';

        users.forEach(user => {
            const statusBadge = user.status === 'active' ?
                '<span class="status-active">正常</span>' :
                '<span class="status-inactive">禁用</span>';

            html += `
            <div class="user-item" onclick="selectUserFromList(${user.id}, '${user.display_name || user.username}', '${user.email}', ${user.balance})">
                <div class="user-info">
                    <div class="user-name">
                        <strong>${user.display_name || user.username}</strong>
                        ${statusBadge}
                    </div>
                    <div class="user-email">${user.email}</div>
                    <div class="user-balance">
                        余额：<?php echo todo_get_default_currency_config()['symbol']; ?>${user.balance.toFixed(2)} | 
                        充值：<?php echo todo_get_default_currency_config()['symbol']; ?>${user.total_recharge.toFixed(2)} | 
                        消费：<?php echo todo_get_default_currency_config()['symbol']; ?>${user.total_consumption.toFixed(2)}
                    </div>
                </div>
            </div>
        `;
        });

        // 添加分页信息
        if (pagination.total_pages > 1) {
            html += '<div class="pagination-info" style="text-align: center; padding: 10px; border-top: 1px solid #dee2e6; margin-top: 10px;">';
            html += `第 ${pagination.current_page} 页，共 ${pagination.total_pages} 页 (${pagination.total_users} 个用户)`;
            html += '</div>';
        }

        html += '</div>';

        userList.innerHTML = html;
    }

    function selectUserFromList(userId, userName, userEmail, balance) {
        const targetInput = document.getElementById(currentSelectTarget + '-user-id');
        targetInput.value = userId;

        const userInfo = document.getElementById(currentSelectTarget + '-user-info');
        userInfo.innerHTML = `
        <div style="font-size: 14px;background-color: #efeff5;padding: 5px 10px;border-radius: 3px;">
            <p>${userName}<em class="description m-0"> #${userId}</em></p>
            <p class="description m-0">${userEmail}</p>
            当前余额：<?php echo todo_get_default_currency_config()['symbol']; ?>${balance}
        </div>
    `;
        userInfo.style.display = 'flex';

        closeUserSelectModal();
    }

    window.showAddBalanceModal = showAddBalanceModal;
    window.closeAddBalanceModal = closeAddBalanceModal;
    window.showAdjustBalanceModal = showAdjustBalanceModal;
    window.closeAdjustBalanceModal = closeAdjustBalanceModal;
    window.selectUserFromList = selectUserFromList;

    try {
        if (window.parent && window.parent !== window) {
            window.parent.showAddBalanceModal = showAddBalanceModal;
            window.parent.closeAddBalanceModal = closeAddBalanceModal;
            window.parent.showAdjustBalanceModal = showAdjustBalanceModal;
            window.parent.closeAdjustBalanceModal = closeAdjustBalanceModal;
            window.parent.selectUserFromList = selectUserFromList;
        }
    } catch (e) {}
</script>
<?php
// 引入全局用户选择器模态框组件
require_once TODO_ADMIN_PATH . '/components/user-selector.php';
todo_user_selector_modal([
    // 可按需传入选项，例如：'multiple' => false（默认单选）
]);
?>
