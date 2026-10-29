<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

/**
 * 广告分组管理页面
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

if (!current_user_can('manage_ads')) {
    todo_die('您没有管理广告的权限。');
}

// 确保数据库连接已初始化
global $tododb;
if (!$tododb) {
    todo_die('数据库连接失败，请检查配置。');
}

// 初始化广告管理器
$ads_manager = Todo_Ads_Manager::init();

// 处理表单提交
if (isset($_POST['submit'])) {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('add_ad_group', '_todononce')) {
        todo_die('安全验证失败，请重新提交。');
    }

    $name = sanitize_text_field($_POST['name']);
    $code = sanitize_text_field($_POST['code']);
    $description = sanitize_textarea_field($_POST['description']);
    $sort_order = intval($_POST['sort_order']);
    // 统一状态为整型 1/0，兼容旧表单字符串 'active'/'inactive'
    $status_raw = $_POST['status'] ?? 1;
    if (is_numeric($status_raw)) {
        $status = intval($status_raw) ? 1 : 0;
    } else {
        $status_str = strtolower(trim((string) $status_raw));
        $status = ($status_str === 'active') ? 1 : 0;
    }

    $result = $ads_manager->create_ad_group(array(
        'name' => $name,
        'code' => $code,
        'description' => $description,
        'sort_order' => $sort_order,
        'status' => $status
    ));

    if ($result) {
        todo_redirect(todo_admin_url('index.php?page=ad-groups&notice=success&msg=' . urlencode('广告分组创建成功！')));
        exit;
    } else {
        $error_message = '广告分组创建失败！';
    }
}

// 处理删除操作
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete' && isset($_POST['id'])) {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('delete_ad_group_' . $_POST['id'], '_todononce')) {
        todo_die('安全验证失败，请重新操作。');
    }

    $id = intval($_POST['id']);
    if ($ads_manager->delete_ad_group($id)) {
        todo_redirect(todo_admin_url('index.php?page=ad-groups&notice=success&msg=' . urlencode('广告分组删除成功！')));
        exit;
    } else {
        $ads_count = $tododb->get_var("SELECT COUNT(*) FROM " . TABLE_PREFIX . "ads WHERE group_id = ?", [$id]);
        if (intval($ads_count) > 0) {
            $error_message = '该分组下还有 ' . intval($ads_count) . ' 个广告，无法删除。请先移除或转移这些广告。';
        } else {
            $error_message = '广告分组删除失败！';
        }
    }
}

// PRG 提示处理
if (isset($_GET['notice'])) {
    if ($_GET['notice'] === 'success') {
        $success_message = isset($_GET['msg']) ? urldecode((string) $_GET['msg']) : '';
    } else {
        $error_message = isset($_GET['msg']) ? urldecode((string) $_GET['msg']) : '';
    }
}

// 获取所有广告分组
$ad_groups = $ads_manager->get_ad_groups();
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title verse">“淡妆浓抹总相宜。”<span>——苏轼《饮湖上初晴后雨》</span></h2>
    </div>

    <?php if (isset($success_message)): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html($success_message); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html($error_message); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <div class="admin-row">
        <!-- 左侧：添加表单 -->
        <div class="admin-col-l" style="width: 260px;">
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">新增广告分组</h3>
                </div>
                <div class="admin-card-content">
                    <form method="post" action="" class="todo-request">
                        <?php echo Todo_Security_Manager::csrf_field('add_ad_group', '_todononce'); ?>

                        <div class="form-group">
                            <label for="name">分组名称 <span class="required">*</span></label>
                            <input name="name" type="text" id="name"
                                value="<?php echo isset($_POST['name']) ? esc_attr($_POST['name']) : ''; ?>"
                                class="regular-text" required>
                            <p class="description">广告分组的显示名称。</p>
                        </div>

                        <div class="form-group">
                            <label for="code">分组标识 <span class="required">*</span></label>
                            <input name="code" type="text" id="code"
                                value="<?php echo isset($_POST['code']) ? esc_attr($_POST['code']) : ''; ?>"
                                class="regular-text" required>
                            <p class="description">只能包含字母、数字和下划线。</p>
                        </div>

                        <div class="form-group">
                            <label for="description">分组描述</label>
                            <textarea name="description" id="description" rows="3"
                                class="large-text"><?php echo isset($_POST['description']) ? esc_textarea($_POST['description']) : ''; ?></textarea>
                            <p class="description">广告分组的详细描述。</p>
                        </div>

                        <div class="form-group">
                            <label for="sort_order">排序</label>
                            <input name="sort_order" type="number" id="sort_order"
                                value="<?php echo isset($_POST['sort_order']) ? intval($_POST['sort_order']) : 0; ?>"
                                class="small-text" min="0">
                            <p class="description">数字越小排序越靠前。</p>
                        </div>

                        <div class="form-group">
                            <label for="status">状态</label>
                            <select name="status" id="status" class="regular-select">
                                <option value="1" <?php selected(isset($_POST['status']) ? intval($_POST['status']) : 1, 1); ?>>激活</option>
                                <option value="0" <?php selected(isset($_POST['status']) ? intval($_POST['status']) : 1, 0); ?>>停用</option>
                            </select>
                            <p class="description">广告分组的状态。</p>
                        </div>

                        <div class="form-submit">
                            <button type="submit" name="submit" id="submit" class="button button-primary">添加分组</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- 右侧：分组列表 -->
        <div class="admin-col-r">
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">现有广告分组</h3>
                    <div class="admin-card-actions">
                        <span class="description m-0"><?php echo count($ad_groups); ?> 个分组</span>
                    </div>
                </div>
                <div class="admin-card-content">
                    <?php if (empty($ad_groups)): ?>
                        <div class="no-items">
                            <p>暂无广告分组。</p>
                        </div>
                    <?php else: ?>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th style="min-width: 200px;">分组名称</th>
                                    <th style="width: 60px; min-width: 60px;">广告</th>
                                    <th>代码</th>
                                    <th style="width: 80px; min-width: 80px;">状态</th>
                                    <th style="width: 60px;">排序</th>
                                    <th style="width: 100px; min-width: 100px;">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ad_groups as $group): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html($group['name']); ?></strong>
                                            <?php if (!empty($group['description'])): ?>
                                                <br><p class="description"><?php echo esc_html($group['description']); ?></p>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $ad_count = $tododb->get_var("SELECT COUNT(*) FROM " . TABLE_PREFIX . "ads WHERE group_id = ?", [$group['id']]);
                                            echo intval($ad_count);
                                            ?>
                                        </td>
                                        <td>
                                            <code><?php echo esc_html($group['code']); ?></code>
                                        </td>
                                        <td>
                                            <?php $gstatus = intval($group['status']); ?>
                                            <span class="status-<?php echo $gstatus === 1 ? 'active' : 'inactive'; ?>">
                                                <?php echo $gstatus === 1 ? '激活' : '停用'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo intval($group['sort_order'] ?? 0); ?>
                                        </td>
                                        <td>
                                            <div class="row-actions">
                                                <a href="<?php echo todo_admin_url('index.php?page=ad-group-edit&id=' . $group['id']); ?>">编辑</a> |
                                                <a href="<?php echo todo_admin_url('index.php?page=ad-groups&action=delete&id=' . $group['id'] . '&_todononce=' . Todo_Security_Manager::generate_csrf_token('delete_ad_group_' . $group['id'])); ?>"
                                                    class="submitdelete delete-ad-group-btn"
                                                    data-group-id="<?php echo $group['id']; ?>">删除</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .column-code {
        width: 20%;
    }

    .column-sort {
        width: 10%;
        text-align: center;
    }

    .column-status {
        width: 10%;
        text-align: center;
    }

    .column-name {
        width: auto;
    }

    code {
        background: #f1f1f1;
        padding: 2px 6px;
        border-radius: 3px;
        font-family: Consolas, Monaco, monospace;
        font-size: 12px;
    }
</style>
<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        // 处理删除广告分组按钮
        document.querySelectorAll('.delete-ad-group-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var href = this.getAttribute('href');
                if (!href) return;
                var doPost = function () {
                    if (window.TodoRequest && typeof window.TodoRequest.ajax === 'function') {
                        var fd = new FormData();
                        try {
                            var u = new URL(href, window.location.href);
                            u.searchParams.forEach(function (v, k) {
                                fd.append(k, v);
                            });
                        } catch (err) {}
                        window.TodoRequest.ajax({ url: href, method: 'POST', data: fd });
                        return;
                    }
                    window.location.href = href;
                };

                TodoModal.deleteConfirm({
                    message: '确定要删除这个广告分组吗？',
                    onConfirm: function () {
                        doPost();
                    }
                });
            });
        });

        if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils && TodoCMS.Utils.initSlugGenerator) {
            TodoCMS.Utils.initSlugGenerator({
                nameInputId: 'name',
                slugInputId: 'code',
                ajaxAction: 'slug_generator',
                id: 0,
                nameParam: 'name',
                idParam: 'id',
                extraParams: {
                    type: 'ad_group'
                }
            });
        }
    });
</script>
