<?php
/**
 * 管理后台用户管理页面
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 检查用户权限
if (!current_user_can('list_users')) {
    todo_die('您没有足够的权限访问此页面。');
}

// 获取当前操作
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

// 处理批量操作
if (isset($_POST['bulk_action']) && !empty($_POST['user_ids'])) {
    if (!class_exists('Todo_Security_Manager') || !Todo_Security_Manager::verify_request_csrf('users_action', '_todononce')) {
        todo_die('安全验证失败');
    }
    $user_ids = $_POST['user_ids'];
    $bulk_action = $_POST['bulk_action'];

    switch ($bulk_action) {
        case 'delete':
            if (current_user_can('delete_users')) {
                foreach ($user_ids as $user_id) {
                    if ($user_id != get_current_user_id()) { // 不允许删除自己
                        delete_user($user_id);
                    }
                }
                $message = '已删除选定的用户。';
            } else {
                $error = '您没有权限删除用户。';
            }
            break;

        case 'change_role':
            if (current_user_can('edit_users') && isset($_POST['new_role'])) {
                $new_role = $_POST['new_role'];
                foreach ($user_ids as $user_id) {
                    $user = new CMS_User($user_id);
                    $user->set_role($new_role);
                }
                $message = '已更改选定用户的角色。';
            } else {
                $error = '您没有权限更改用户角色。';
            }
            break;
    }
}

// 处理单个用户操作
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'], $_POST['user_id'])) {
    if (!class_exists('Todo_Security_Manager') || !Todo_Security_Manager::verify_request_csrf('users_action', '_todononce')) {
        todo_die('安全验证失败');
    }
    $action = (string) $_POST['action'];
    $user_id = intval($_POST['user_id']);

    switch ($action) {
        case 'delete':
            if (current_user_can('delete_users')) {
                if ($user_id != get_current_user_id()) { // 不允许删除自己
                    delete_user($user_id);
                    $message = '用户已删除。';
                } else {
                    $error = '您不能删除自己的账户。';
                }
            } else {
                $error = '您没有权限删除用户。';
            }
            break;
    }
}

// 获取用户列表
$paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20;
$offset = ($paged - 1) * $per_page;

// 搜索条件
$search = isset($_GET['s']) ? trim($_GET['s']) : '';

// 角色筛选
$role = isset($_GET['role']) ? $_GET['role'] : '';

// 构建查询参数
$args = array(
    'number' => $per_page,
    'offset' => $offset,
    'search' => $search,
    'role' => $role,
    'orderby' => 'registered',
    'order' => 'DESC'
);

// 获取用户
$users = get_users($args);

// 获取用户总数（用于分页）
$total_users = count_users($args);
$total_pages = ceil($total_users / $per_page);

// 获取所有可用角色
$roles = array(
    'administrator' => '管理员',
    'editor' => '编辑',
    'author' => '作者',
    'subscriber' => '订阅者'
);
?>

<div class="admin-users-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">用户</h2>
        <div class="admin-page-actions">
            <?php if (current_user_can('manage_options')): ?>
                <a href="<?php echo todo_admin_url('index.php?page=access-settings' . (todo_is_iframe_mode() ? '&format=iframe' : '')); ?>"
                    class="button button-secondary">访问设置</a>
            <?php endif; ?>
            <a href="<?php echo todo_admin_url('index.php?page=user-new'); ?>" class="button button-primary">添加新用户</a>
        </div>
    </div>

    <?php if (isset($message)): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html($message); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html($error); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <div class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-section">
                <h3 class="admin-card-title">用户列表</h3>
                <div class="admin-card-subtitle">
                    <?php echo esc_html($total_users); ?> 个用户
                </div>
            </div>

            <div class="admin-card-actions">
                <form method="get" class="search-form" action="<?php echo todo_admin_url('index.php'); ?>">
                    <input type="hidden" name="page" value="users">
                    <?php if (todo_is_iframe_mode()): ?>
                        <input type="hidden" name="format" value="iframe">
                    <?php endif; ?>
                    <?php if ($role): ?>
                        <input type="hidden" name="role" value="<?php echo esc_attr($role); ?>">
                    <?php endif; ?>
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="搜索用户...">
                    <button type="submit" class="button button-secondary">搜索</button>
                </form>
            </div>
        </div>

        <div class="admin-card-content">
            <div class="admin-filter-bar">
                <ul class="admin-filters">
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=users'); ?>" class="<?php echo empty($role) ? 'current' : ''; ?>">
                            所有用户 <span class="count">(<?php echo count_users(); ?>)</span>
                        </a>
                    </li>
                    <?php foreach ($roles as $role_id => $role_name): ?>
                        <li>
                            <a href="<?php echo todo_admin_url('index.php?page=users&role=' . esc_attr($role_id)); ?>"
                                class="<?php echo esc_attr($role === $role_id ? 'current' : ''); ?>">
                                <?php echo esc_html($role_name); ?> <span
                                    class="count">(<?php echo esc_html(count_users(array('role' => $role_id))); ?>)</span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <form method="post" class="admin-users-form">
                <?php if (class_exists('Todo_Security_Manager')): ?>
                    <?php echo Todo_Security_Manager::csrf_field('users_action', '_todononce'); ?>
                <?php endif; ?>
                <div class="bulk-actions-bar">
                    <div class="bulk-actions">
                        <select name="bulk_action">
                            <option value="">批量操作</option>
                            <?php if (current_user_can('delete_users')): ?>
                                <option value="delete">删除</option>
                            <?php endif; ?>
                            <?php if (current_user_can('edit_users')): ?>
                                <option value="change_role">更改角色</option>
                            <?php endif; ?>
                        </select>

                        <select name="new_role" class="hidden role-select">
                            <?php foreach ($roles as $role_id => $role_name): ?>
                                <option value="<?php echo esc_attr($role_id); ?>"><?php echo esc_html($role_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" class="button button-secondary">应用</button>
                    </div>
                    <?php if ($total_pages > 1): ?>
                    <?php
                        $pagination_add_args = array();
                        if (!empty($role)) {
                            $pagination_add_args['role'] = $role;
                        }
                        if (!empty($search)) {
                            $pagination_add_args['s'] = $search;
                        }

                        echo todo_render_pagination(array(
                            'total_items' => $total_users,
                            'per_page' => $per_page,
                            'current_page' => $paged,
                            'base_url' => todo_admin_url('index.php?page=users'),
                            'add_args' => $pagination_add_args,
                            'show_count' => false,
                        ));
                    ?>
                    <?php endif; ?>
                </div>

                <table class="admin-table users-table">
                    <thead>
                        <tr>
                            <th class="check-column">
                                <input type="checkbox" id="select-all-users">
                            </th>
                            <th style="min-width: 120px;">用户名</th>
                            <th>姓名</th>
                            <th>邮箱</th>
                            <th style="min-width: 100px;">角色</th>
                            <th>文章数</th>
                            <th style="width: 150px; min-width: 150px;">注册时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr class="empty">
                                <td colspan="7">
                                    <div class="no-items">
                                        <p>没有找到用户。</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <?php if ($user->ID != get_current_user_id()): ?>
                                            <input type="checkbox" name="user_ids[]" value="<?php echo esc_attr($user->ID); ?>">
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong>
                                            <a href="<?php echo todo_admin_url('index.php?page=user-edit&user_id=' . esc_attr($user->ID)); ?>">
                                                <?php echo esc_html($user->user_login); ?>
                                            </a>
                                        </strong>
                                        <div class="row-actions">
                                            <a href="<?php echo todo_admin_url('index.php?page=user-edit&user_id=' . esc_attr($user->ID)); ?>">编辑</a>
                                            <?php if ($user->ID != get_current_user_id() && current_user_can('delete_users')): ?> |
                                            <a href="<?php echo todo_admin_url('index.php?page=users&action=delete&user_id=' . esc_attr($user->ID)); ?>"
                                                class="delete-user submitdelete js-todo-post-action" data-confirm="您确定要删除用户 &quot;<?php echo esc_attr($user->user_login); ?>&quot; 吗？">删除</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo esc_html($user->display_name); ?>
                                    </td>
                                    <td>
                                        <?php echo esc_html($user->user_email); ?>
                                    </td>
                                    <td>
                                        <?php
                                        $user_roles = $user->roles;
                                        if (!empty($user_roles)) {
                                            $role_names = array();
                                            foreach ($user_roles as $role_id) {
                                                if (isset($roles[$role_id])) {
                                                    $role_names[] = $roles[$role_id];
                                                }
                                            }
                                            echo implode(', ', $role_names);
                                        } else {
                                            echo '无角色';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $post_count = count_user_posts($user->ID);
                                        if ($post_count > 0) {
                                            echo '<a href="' . todo_admin_url('index.php?page=posts&author=' . $user->ID) . '">' . $post_count . '</a>';
                                        } else {
                                            echo '0';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="tb-meta">
                                            <?php echo date('Y-m-d H:i', strtotime($user->user_registered)); ?>
                                        </span>
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
                if (!empty($role)) {
                    $pagination_add_args['role'] = $role;
                }
                if (!empty($search)) {
                    $pagination_add_args['s'] = $search;
                }

                echo todo_render_pagination(array(
                    'total_items' => $total_users,
                    'per_page' => $per_page,
                    'current_page' => $paged,
                    'base_url' => todo_admin_url('index.php?page=users'),
                    'add_args' => $pagination_add_args,
                    'show_count' => true,
                ));
                ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        // 全选/取消全选
        var selectAll = document.getElementById('select-all-users');
        var checkboxes = document.querySelectorAll('input[name="user_ids[]"]');

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checkboxes.forEach(function (checkbox) {
                    checkbox.checked = selectAll.checked;
                });
            });
        }

        // 批量操作下拉菜单联动
        var bulkActionSelect = document.querySelector('select[name="bulk_action"]');
        var roleSelect = document.querySelector('select[name="new_role"]');

        if (bulkActionSelect && roleSelect) {
            bulkActionSelect.addEventListener('change', function () {
                if (this.value === 'change_role') {
                    roleSelect.classList.remove('hidden');
                } else {
                    roleSelect.classList.add('hidden');
                }
            });
        }

    });
</script>
