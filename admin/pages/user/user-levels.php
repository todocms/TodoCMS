<?php
/**
 * 用户等级管理页面
 *
 * @package TodoCMS
 * @version 1.2.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

// 权限检查
if (!todo_current_user_can('manage_user_levels') && !todo_current_user_can('manage_users')) {
    todo_die('您没有权限访问此页面。');
}

$level_manager = todo_get_user_level_manager();

// 获取当前操作
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

// 处理批量操作
if (isset($_POST['bulk_action']) && !empty($_POST['level_ids'])) {
    // CSRF验证
    if (!Todo_Security_Manager::verify_request_csrf('user_levels_bulk', '_csrf_token')) {
        todo_die('安全验证失败，请重新提交。');
    }

    $level_ids = $_POST['level_ids'];
    $bulk_action = $_POST['bulk_action'];

    switch ($bulk_action) {
        case 'delete':
            $deleted_count = 0;
            foreach ($level_ids as $level_id) {
                if ($level_manager->delete_level($level_id)) {
                    $deleted_count++;
                }
            }
            if ($deleted_count > 0) {
                $message = "已删除 {$deleted_count} 个用户等级。";
            } else {
                $error = '删除失败，选定的等级下可能存在用户。';
            }
            break;

        case 'activate':
            $activated_count = 0;
            foreach ($level_ids as $level_id) {
                if ($level_manager->update_level($level_id, array('is_active' => 1))) {
                    $activated_count++;
                }
            }
            if ($activated_count > 0) {
                $message = "已启用 {$activated_count} 个用户等级。";
            }
            break;

        case 'deactivate':
            $deactivated_count = 0;
            foreach ($level_ids as $level_id) {
                if ($level_manager->update_level($level_id, array('is_active' => 0))) {
                    $deactivated_count++;
                }
            }
            if ($deactivated_count > 0) {
                $message = "已禁用 {$deactivated_count} 个用户等级。";
            }
            break;
    }
}

// 处理单个等级操作
if ($action && isset($_REQUEST['level'])) {
    $level_id = intval($_REQUEST['level']);

    switch ($action) {
        case 'delete':
            // CSRF验证
            if (!Todo_Security_Manager::verify_request_csrf('user_levels_manage', '_csrf_token')) {
                todo_die('安全验证失败，请重新提交。');
            }

            if ($level_manager->delete_level($level_id)) {
                $message = '用户等级已删除。';
            } else {
                $error = '删除失败，该等级下可能存在用户。';
            }
            break;
    }
}

// 处理表单提交（新增/编辑）
if ('POST' === $_SERVER['REQUEST_METHOD'] && in_array($action, ['create', 'update'])) {
    // CSRF验证
    if (!Todo_Security_Manager::verify_request_csrf('user_levels_manage', '_csrf_token')) {
        todo_die('安全验证失败，请重新提交。');
    }

    // 折扣率(0-100)，保留两位小数
    $discount_rate = isset($_POST['discount_rate']) ? floatval($_POST['discount_rate']) : 0;
    if ($discount_rate < 0) {
        $discount_rate = 0;
    }
    if ($discount_rate > 100) {
        $discount_rate = 100;
    }
    $discount_rate = number_format($discount_rate, 2, '.', '');

    $data = array(
        'level_name' => sanitize_text_field($_POST['level_name']),
        'level_slug' => sanitize_text_field($_POST['level_slug']),
        'min_points' => intval($_POST['min_points']),
        'level_color' => sanitize_text_field($_POST['level_color']),
        'level_icon' => sanitize_text_field($_POST['level_icon']),
        'description' => todo_kses_post($_POST['description']),
        'level_order' => intval($_POST['level_order']),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'discount_rate' => $discount_rate
    );

    if ($action === 'create') {
        // 1) 先生成一个预置唯一 slug（不依赖ID），避免 create 阶段因重复而失败
        $base = sanitize_title($data['level_name']);
        if (empty($base)) {
            $base = 'level';
        }
        $pre_slug = $base;
        if ($level_manager->level_slug_exists($pre_slug)) {
            $i = 2;
            $candidate = $base . '-' . $i;
            while ($level_manager->level_slug_exists($candidate)) {
                $i++;
                $candidate = $base . '-' . $i;
            }
            $pre_slug = $candidate;
        }
        $data['level_slug'] = $pre_slug;

        // 2) 创建
        $insert_id = $level_manager->create_user_level($data);
        if ($insert_id) {
            // 3) 使用 ID 规范化最终 slug：拼音-<id>
            $final_slug = $base . '-' . $insert_id;

            // 极小概率冲突（理论上不会，与自身排除），仍做兜底
            if ($level_manager->level_slug_exists($final_slug, $insert_id)) {
                $i = 2;
                $candidate = $final_slug . '-' . $i;
                while ($level_manager->level_slug_exists($candidate, $insert_id)) {
                    $i++;
                    $candidate = $final_slug . '-' . $i;
                }
                $final_slug = $candidate;
            }

            // 4) 回写规范化后的 slug
            $level_manager->update_level($insert_id, array('level_slug' => $final_slug));
            todo_add_admin_notice('用户等级创建成功！已规范化标识为：' . esc_html($final_slug), 'success');
        } else {
            $error = '用户等级创建失败。';
        }
    } else {
        $level_id = intval($_POST['level_id']);
        if ($level_manager->update_level($level_id, $data)) {
            todo_add_admin_notice('用户等级更新成功！', 'success');
        } else {
            todo_add_admin_notice('用户等级更新失败。', 'error');
        }
    }

    // 统一采用PRG跳转到列表页，避免重复提交，并在列表页展示通知
    todo_redirect(todo_admin_url('index.php?page=user-levels'));
    exit;
}

// 根据action决定显示内容
if (in_array($action, ['edit', 'new'])) {
    $edit_level = null;
    if ('edit' === $action) {
        $level_id = intval($_GET['level']);
        $edit_level = $level_manager->get_user_level($level_id);
    }
    $page_title = $edit_level ? '编辑用户等级' : '添加新用户等级';
    include __DIR__ . '/user-level-edit.php';
    return;
}

// 获取列表数据
$paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20;
$offset = ($paged - 1) * $per_page;

// 搜索条件
$search = isset($_GET['s']) ? trim($_GET['s']) : '';

// 状态筛选
$status = isset($_GET['status']) ? $_GET['status'] : '';

// 构建查询参数
$args = array(
    'number' => $per_page,
    'offset' => $offset,
    'search' => $search,
    'orderby' => 'level_order',
    'order' => 'ASC'
);

if ($status !== '') {
    $args['is_active'] = ($status === 'active') ? 1 : 0;
}

// 获取用户等级
$levels = $level_manager->get_user_levels($args);

// 获取总数（用于分页）
$total_levels = (int) $level_manager->get_user_levels(array_merge($args, array('count' => true)));
$total_pages = ceil($total_levels / $per_page);

// 获取状态统计
$all_count = $level_manager->get_user_levels(array('count' => true));
$active_count = $level_manager->get_user_levels(array('count' => true, 'is_active' => 1));
$inactive_count = $level_manager->get_user_levels(array('count' => true, 'is_active' => 0));
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title verse">“大鹏一日同风起，扶摇直上九万里。”<span>——李白《上李邕》</span></h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=user-levels&action=new'); ?>" class="button button-primary">添加新等级</a>
        </div>
    </div>

    <?php if (isset($message)): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo $message; ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo $error; ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <div class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-section">
                <h3 class="admin-card-title">用户等级列表</h3>
                <div class="admin-card-subtitle">
                    <?php echo $total_levels; ?> 个等级
                </div>
            </div>

            <div class="admin-card-actions">
                <form method="get" class="search-form" action="<?php echo todo_admin_url('index.php'); ?>">
                    <input type="hidden" name="page" value="user-levels">
                    <?php if (function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()): ?>
                        <input type="hidden" name="format" value="iframe">
                    <?php endif; ?>
                    <?php if ($status): ?>
                        <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
                    <?php endif; ?>
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="搜索等级...">
                    <button type="submit" class="button button-secondary">搜索</button>
                </form>
            </div>
        </div>

        <div class="admin-card-content">
            <div class="admin-filter-bar">
                <ul class="admin-filters">
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=user-levels'); ?>" class="<?php echo empty($status) ? 'current' : ''; ?>">
                            所有等级 <span class="count">(<?php echo $all_count; ?>)</span>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=user-levels&status=active'); ?>"
                            class="<?php echo $status === 'active' ? 'current' : ''; ?>">
                            已启用 <span class="count">(<?php echo $active_count; ?>)</span>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=user-levels&status=inactive'); ?>"
                            class="<?php echo $status === 'inactive' ? 'current' : ''; ?>">
                            已禁用 <span class="count">(<?php echo $inactive_count; ?>)</span>
                        </a>
                    </li>
                </ul>
            </div>

            <form method="post" class="admin-levels-form" action="<?php echo todo_admin_url('index.php?page=user-levels'); ?>">
                <?php echo Todo_Security_Manager::get_csrf_field('user_levels_bulk'); ?>

                <div class="bulk-actions-bar">
                    <div class="bulk-actions">
                        <select name="bulk_action">
                            <option value="">批量操作</option>
                            <option value="activate">启用</option>
                            <option value="deactivate">禁用</option>
                            <option value="delete">删除</option>
                        </select>
                        <button type="submit" class="button button-secondary">应用</button>
                    </div>
                </div>

                <table class="admin-table levels-table">
                    <thead>
                        <tr>
                            <th class="check-column">
                                <input type="checkbox" id="select-all-levels">
                            </th>
                            <th style="min-width: 120px;">等级名称</th>
                            <th>标识</th>
                            <th style="text-align: right;">最低积分</th>
                            <th style="text-align: right;">折扣率</th>
                            <th style="text-align: right;">颜色</th>
                            <th style="text-align: right;">用户数</th>
                            <th style="text-align: right; min-width: 100px;">状态</th>
                            <th style="text-align: right;">排序</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($levels)): ?>
                            <tr class="empty">
                                <td colspan="9">
                                    <div class="no-items">
                                        <p>没有找到用户等级。</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($levels as $level): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="level_ids[]" value="<?php echo $level->id; ?>">
                                    </td>
                                    <td>
                                        <strong>
                                            <a href="<?php echo todo_admin_url('index.php?page=user-levels&action=edit&level=' . $level->id); ?>">
                                                <?php if ($level->level_icon): ?>
                                                    <i class="<?php echo esc_attr($level->level_icon); ?>"></i>
                                                <?php endif; ?>
                                                <?php echo esc_html($level->level_name); ?>
                                            </a>
                                        </strong>
                                        <div class="row-actions">
                                            <span class="edit">
                                                <a
                                                    href="<?php echo todo_admin_url('index.php?page=user-levels&action=edit&level=' . $level->id); ?>">编辑</a>
                                                |
                                            </span>
                                            <span class="delete">
                                                <a href="javascript:void(0);" class="delete-level submitdelete"
                                                    data-level-id="<?php echo $level->id; ?>"
                                                    data-level-name="<?php echo esc_attr($level->level_name); ?>">删除</a>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <code><?php echo esc_html($level->level_slug); ?></code>
                                    </td>
                                    <td style="text-align: right;">
                                        <?php if($level->min_points > 0): ?>
                                        <span class="points-badge"><?php echo number_format($level->min_points); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <span
                                            class="discount-badge"><?php echo number_format((float) $level->discount_rate, 2); ?>%</span>
                                    </td>
                                    <td style="text-align: right;">
                                        <div class="color-preview"
                                            style="background-color: <?php echo esc_attr($level->level_color); ?>"
                                            title="<?php echo esc_attr($level->level_color); ?>"></div>
                                    </td>
                                    <td style="text-align: right;">
                                        <?php
                                        $user_count = $level_manager->get_level_user_count($level->id);
                                        if ($user_count > 0) {
                                            echo '<a href="' . todo_admin_url('index.php?page=users&level=' . $level->id) . '">' . number_format($user_count) . '</a>';
                                        } else {
                                            echo '0';
                                        }
                                        ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <span
                                            class="status-<?php echo $level->is_active ? 'active' : 'inactive'; ?>">
                                            <?php echo $level->is_active ? '启用' : '禁用'; ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <?php echo $level->level_order; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>

            <?php if ($total_pages > 1): ?>
                <?php
                $pagination_add_args = array();
                if (!empty($status)) {
                    $pagination_add_args['status'] = $status;
                }
                if (!empty($search)) {
                    $pagination_add_args['s'] = $search;
                }

                echo todo_render_pagination(array(
                    'total_items' => $total_levels,
                    'per_page' => $per_page,
                    'current_page' => $paged,
                    'base_url' => todo_admin_url('index.php?page=user-levels'),
                    'add_args' => $pagination_add_args,
                    'show_count' => true,
                ));
                ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 删除确认表单 -->
<form id="delete-form" method="post" style="display: none;" action="<?php echo todo_admin_url('index.php?page=user-levels'); ?>">
    <?php echo Todo_Security_Manager::get_csrf_field('user_levels_manage'); ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="level" id="delete-level-id">
</form>

<style>
    .search-form input[type="search"] {
        width: 200px;
    }

    .points-badge {
        background: #3bbcdc;
        color: white;
        padding: 0 10px;
        border-radius: 20px;
        font-size: 12px;
        display: inline-block;
    }

    .discount-badge {
        color: #ff6600;
        font-size: 12px;
        font-weight: 500;
        display: inline-block;
    }

    .color-preview {
        width: 20px;
        height: 20px;
        border: 1px solid #ddd;
        display: inline-block;
        vertical-align: middle;
    }

    @media (max-width: 768px) {
        .search-form {
            width: 100%;
        }

        .search-form input[type="search"] {
            flex: 1;
        }
    }
</style>

<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        // 全选/取消全选
        var selectAll = document.getElementById('select-all-levels');
        var checkboxes = document.querySelectorAll('input[name="level_ids[]"]');

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checkboxes.forEach(function (checkbox) {
                    checkbox.checked = selectAll.checked;
                });
            });
        }

        // 删除等级确认
        var deleteLinks = document.querySelectorAll('.delete-level');

        deleteLinks.forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var levelId = this.getAttribute('data-level-id');
                var levelName = this.getAttribute('data-level-name');

                if (typeof TodoModal !== 'undefined') {
                    TodoModal.deleteConfirm({
                        message: '您确定要删除等级 "' + levelName + '" 吗？',
                        onConfirm: function () {
                            document.getElementById('delete-level-id').value = levelId;
                            document.getElementById('delete-form').submit();
                        }
                    });
                } else if (confirm('您确定要删除等级 "' + levelName + '" 吗？')) {
                    document.getElementById('delete-level-id').value = levelId;
                    document.getElementById('delete-form').submit();
                }
            });
        });

        // 批量操作确认
        var bulkForm = document.querySelector('.admin-levels-form');
        if (bulkForm) {
            bulkForm.addEventListener('submit', function (e) {
                var bulkAction = document.querySelector('select[name="bulk_action"]').value;
                var checkedBoxes = document.querySelectorAll('input[name="level_ids[]"]:checked');

                if (!bulkAction) {
                    e.preventDefault();
                    TodoCMS.Utils.showMessage('请选择一个批量操作。', 'warning');
                    return;
                }

                if (checkedBoxes.length === 0) {
                    e.preventDefault();
                    TodoCMS.Utils.showMessage('请至少选择一个等级。', 'warning');
                    return;
                }

                if (bulkAction === 'delete') {
                    e.preventDefault();
                    if (typeof TodoModal !== 'undefined') {
                        TodoModal.deleteConfirm({
                            message: '您确定要删除选定的 ' + checkedBoxes.length + ' 个等级吗？',
                            onConfirm: function () {
                                bulkForm.submit();
                            }
                        });
                    } else if (confirm('您确定要删除选定的 ' + checkedBoxes.length + ' 个等级吗？')) {
                        bulkForm.submit();
                    }
                }
            });
        }
    });
</script>
