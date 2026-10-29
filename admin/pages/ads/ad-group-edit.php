<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

/**
 * 广告分组编辑页面
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

// 初始化广告管理器
$ads_manager = Todo_Ads_Manager::get_instance();

// 获取分组ID
$group_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$group_id) {
    todo_redirect(todo_admin_url('index.php?page=ad-groups'));
    exit;
}

// 获取分组信息
$group = $ads_manager->get_ad_group($group_id);
if (!$group) {
    todo_redirect(todo_admin_url('index.php?page=ad-groups'));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete_ad' && isset($_POST['ad_id'])) {
    if (!class_exists('Todo_Security_Manager') || !Todo_Security_Manager::verify_request_csrf('edit_ad_group', '_todononce')) {
        todo_die('安全验证失败，请重新操作。');
    }
    $ad_id = intval($_POST['ad_id']);
    $ad = class_exists('Todo_Ads_Manager') ? Todo_Ads_Manager::get_ad($ad_id) : null;
    if (!is_array($ad) || intval($ad['group_id'] ?? 0) !== $group_id) {
        todo_redirect(todo_admin_url('index.php?page=ad-group-edit&id=' . $group_id . '&notice=error&msg=' . urlencode('无效的广告。')));
        exit;
    }
    $ok = class_exists('Todo_Ads_Manager') ? Todo_Ads_Manager::delete_ad($ad_id) : false;
    todo_redirect(todo_admin_url('index.php?page=ad-group-edit&id=' . $group_id . '&notice=' . ($ok ? 'success' : 'error') . '&msg=' . urlencode($ok ? '广告删除成功。' : '删除广告失败。')));
    exit;
}

// 处理表单提交
if (isset($_POST['submit'])) {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('edit_ad_group', '_todononce')) {
        todo_die('安全验证失败，请重新操作。');
    }

    $name = sanitize_text_field($_POST['name']);
    $code = sanitize_text_field($_POST['code']);
    $description = sanitize_textarea_field($_POST['description']);
    $sort_order = intval($_POST['sort_order']);
    // 状态值统一为整型 1/0（兼容旧表单传入字符串 active/inactive）
    $status_raw = isset($_POST['status']) ? $_POST['status'] : 1;
    if (is_numeric($status_raw)) {
        $status = intval($status_raw);
    } else {
        $status_str = strtolower(trim((string) $status_raw));
        $status = ($status_str === 'active') ? 1 : 0;
    }

    $result = $ads_manager->update_ad_group($group_id, array(
        'name' => $name,
        'code' => $code,
        'description' => $description,
        'sort_order' => $sort_order,
        'status' => $status
    ));

    if ($result) {
        todo_redirect(todo_admin_url('index.php?page=ad-group-edit&id=' . $group_id . '&notice=success&msg=' . urlencode('广告分组更新成功！')));
        exit;
    } else {
        $error_message = '广告分组更新失败！';
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

// 获取该分组下的广告
$group_ads = $ads_manager->get_ads_by_group($group_id);
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">编辑广告分组</h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=ad-groups'); ?>" class="button button-secondary">返回分组列表</a>
        </div>
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
        <!-- 左侧：编辑表单 -->
        <div class="admin-col-l" style="width: 260px;">
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">分组信息</h3>
                </div>
                <div class="admin-card-content">
                    <form method="post" action="" class="todo-request">
                        <?php echo Todo_Security_Manager::csrf_field('edit_ad_group', '_todononce'); ?>

                        <div class="form-group">
                            <label for="name">分组名称 <span class="required">*</span></label>
                            <input name="name" type="text" id="name" value="<?php echo esc_attr($group['name']); ?>"
                                class="regular-text" required>
                            <p class="description">广告分组的显示名称。</p>
                        </div>

                        <div class="form-group">
                            <label for="code">分组标识 <span class="required">*</span></label>
                            <input name="code" type="text" id="code" value="<?php echo esc_attr($group['code']); ?>"
                                class="regular-text" required>
                            <p class="description">只能包含字母、数字和下划线。</p>
                        </div>

                        <div class="form-group">
                            <label for="description">分组描述</label>
                            <textarea name="description" id="description" rows="3"
                                class="large-text"><?php echo esc_textarea($group['description']); ?></textarea>
                            <p class="description">广告分组的详细描述。</p>
                        </div>

                        <div class="form-group">
                            <label for="sort_order">排序</label>
                            <input name="sort_order" type="number" id="sort_order"
                                value="<?php echo intval($group['sort_order']); ?>" class="small-text" min="0">
                            <p class="description">数字越小排序越靠前。</p>
                        </div>

                        <div class="form-group">
                            <label for="status">状态</label>
                            <select name="status" id="status" class="regular-select">
                                <option value="1" <?php selected(intval($group['status']), 1); ?>>激活</option>
                                <option value="0" <?php selected(intval($group['status']), 0); ?>>停用</option>
                            </select>
                            <p class="description">广告分组的状态。</p>
                        </div>

                        <div class="form-submit">
                            <a href="<?php echo todo_admin_url('index.php?page=ad-groups'); ?>"
                                class="button button-secondary">取消</a>
                            <button type="submit" name="submit" id="submit" class="button button-primary">更新分组</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- 右侧：分组下的广告列表 -->
        <div class="admin-col-r">
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-title-section">
                        <h3 class="admin-card-title">分组下的广告</h3>
                        <div class="admin-card-subtitle">
                            <?php echo count($group_ads); ?> 个广告
                        </div>
                    </div>
                    <div class="admin-card-actions">
                        <a href="<?php echo todo_admin_url('index.php?page=ad-new&group_id=' . $group_id); ?>"
                            class="button button-primary">添加广告</a>
                    </div>
                </div>
                <div class="admin-card-content">
                    <?php if (empty($group_ads)): ?>
                        <div class="no-items">
                            <p>该分组下暂无广告。</p>
                        </div>
                    <?php else: ?>
                        <table class="admin-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>标题</th>
                                    <th>类型</th>
                                    <th style="width: 80px;">状态</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($group_ads as $ad): ?>
                                    <tr>
                                        <td>
                                            <strong>
                                                <a
                                                    href="<?php echo todo_admin_url('index.php?page=ad-edit&id=' . $ad['id']); ?>">
                                                    <?php echo esc_html($ad['title']); ?>
                                                </a>
                                            </strong>
                                            <div class="row-actions">
                                                <span class="edit">
                                                    <a
                                                        href="<?php echo todo_admin_url('index.php?page=ad-edit&id=' . $ad['id']); ?>">编辑</a>
                                                    |
                                                </span>
                                                <span class="view">
                                                    <a href="javascript:void(0);" onclick="previewAd(<?php echo $ad['id']; ?>)">预览</a> |
                                                </span>
                                                <span class="trash">
                                                    <a href="<?php echo todo_admin_url('index.php?page=ad-group-edit&id=' . $group_id . '&action=delete_ad&ad_id=' . $ad['id']); ?>"
                                                        class="submitdelete js-todo-post-action" data-confirm="广告将会被彻底删除，如果仅是“移出组”请编辑广告更换分组。">删除</a>
                                                </span>
                                            </div>
                                        </td>
                                        <td class="type column-type">
                                            <?php
                                            $type_labels = array(
                                                'image' => '图片',
                                                'text' => '文字',
                                                'code' => '代码',
                                                'video' => '视频',
                                                'gallery' => '图片轮播'
                                            );
                                            echo isset($type_labels[$ad['type']]) ? esc_html($type_labels[$ad['type']]) : esc_html($ad['type']);
                                            ?>
                                        </td>
                                        <td>
                                            <?php $adActive = (intval($ad['status']) === 1); ?>
                                            <span class="status-<?php echo $adActive ? 'active' : 'inactive'; ?>">
                                                <?php echo $adActive ? '激活' : '停用'; ?>
                                            </span>
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
    .column-actions {
        width: 150px;
        white-space: nowrap;
    }

    .column-actions .button {
        margin-right: 5px;
    }
</style>

<script type="text/javascript">
    function previewAd(adId) {
        window.open(<?php echo json_encode(todo_admin_url()); ?> + 'index.php?page=ad-preview&id=' + adId + '&format=iframe', '_blank', 'width=800,height=600');
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils && TodoCMS.Utils.initSlugGenerator) {
            TodoCMS.Utils.initSlugGenerator({
                nameInputId: 'name',
                slugInputId: 'code',
                ajaxAction: 'slug_generator',
                id: <?php echo intval($group_id); ?>,
                nameParam: 'name',
                idParam: 'id',
                extraParams: {
                    type: 'ad_group'
                }
            });
        }
    });
</script>
