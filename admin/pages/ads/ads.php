<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

/**
 * 广告管理页面 - 优化版
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    todo_die('Direct access not permitted');
}

// 初始化广告管理器
$ads_manager = Todo_Ads_Manager::init();

// 检查权限
if (!current_user_can('manage_ads')) {
    todo_die('您没有管理广告的权限。');
}

// 处理操作
$action = isset($_POST['action']) ? (string) $_POST['action'] : (isset($_GET['action']) ? (string) $_GET['action'] : '');
$message = '';
$error = '';

// PRG 提示处理
if (isset($_GET['notice'])) {
    if ($_GET['notice'] === 'success') {
        $message = isset($_GET['msg']) ? urldecode((string) $_GET['msg']) : '';
    } else {
        $error = isset($_GET['msg']) ? urldecode((string) $_GET['msg']) : '';
    }
} elseif (isset($_GET['message']) && $_GET['message'] === 'added') {
    $message = '广告添加成功。';
}

// 处理删除广告
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action === 'delete') {
    $ad_id = intval($_POST['id'] ?? 0);
    if ($ad_id > 0) {
        $nonce = (string) ($_POST['_todononce'] ?? '');
        $valid = class_exists('Todo_Security_Manager') ? Todo_Security_Manager::verify_csrf_token($nonce, 'delete_ad_' . $ad_id) : false;
        if (!$valid) {
            todo_redirect(todo_admin_url('index.php?page=ads&notice=error&msg=' . urlencode('安全验证失败')));
            exit;
        }
        $result = Todo_Ads_Manager::delete_ad($ad_id);
        $redir = $result !== false ? 'success' : 'error';
        $msg = $result !== false ? '广告删除成功。' : '删除广告失败。';
        todo_redirect(todo_admin_url('index.php?page=ads&notice=' . $redir . '&msg=' . urlencode($msg)));
        exit;
    }
}

// 处理状态切换
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action === 'toggle_status') {
    $ad_id = intval($_POST['id'] ?? 0);
    if ($ad_id > 0) {
        $nonce = (string) ($_POST['_todononce'] ?? '');
        $valid = class_exists('Todo_Security_Manager') ? Todo_Security_Manager::verify_csrf_token($nonce, 'toggle_ad_' . $ad_id) : false;
        if (!$valid) {
            todo_redirect(todo_admin_url('index.php?page=ads&notice=error&msg=' . urlencode('安全验证失败')));
            exit;
        }
        $ad = Todo_Ads_Manager::get_ad($ad_id);
        if ($ad) {
            $is_active_now = false;
            if (isset($ad['status'])) {
                if (is_numeric($ad['status'])) {
                    $is_active_now = intval($ad['status']) === 1;
                } else {
                    $is_active_now = (strtolower(trim((string) $ad['status'])) === 'active');
                }
            }
            $new_status = $is_active_now ? 0 : 1;
            $result = Todo_Ads_Manager::update_ad($ad_id, array('status' => $new_status));
            $redir = $result !== false ? 'success' : 'error';
            $msg = $result !== false ? '广告状态更新成功。' : '更新广告状态失败。';
            todo_redirect(todo_admin_url('index.php?page=ads&notice=' . $redir . '&msg=' . urlencode($msg)));
            exit;
        }
    }
}

// 处理批量操作（AJAX）
if ($action === 'bulk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 仅允许AJAX请求
    if (!(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Direct access denied']);
        exit;
    }

    // 可选：CSRF验证（使用专用动作 ads_bulk）
    $nonce = isset($_POST['_todononce']) ? $_POST['_todononce'] : '';
    if (class_exists('Todo_Security_Manager')) {
        if (empty($nonce) || !Todo_Security_Manager::verify_csrf_token($nonce, 'ads_bulk')) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
            exit;
        }
    }

    $bulk_action = isset($_POST['action']) ? sanitize_text_field($_POST['action']) : '';
    $ids_json = isset($_POST['ads']) ? $_POST['ads'] : '[]';
    $ids = json_decode($ids_json, true);
    if (!is_array($ids)) {
        $ids = array();
    }

    // 过滤ID列表
    $ad_ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($v) {
        return $v > 0; })));

    if (empty($bulk_action) || empty($ad_ids)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '无效的参数']);
        exit;
    }

    $success = 0;
    foreach ($ad_ids as $ad_id) {
        if ($bulk_action === 'activate') {
            $res = Todo_Ads_Manager::update_ad($ad_id, ['status' => 1]);
            if ($res !== false)
                $success++;
        } elseif ($bulk_action === 'deactivate') {
            $res = Todo_Ads_Manager::update_ad($ad_id, ['status' => 0]);
            if ($res !== false)
                $success++;
        } elseif ($bulk_action === 'delete') {
            $res = Todo_Ads_Manager::delete_ad($ad_id);
            if ($res !== false)
                $success++;
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'processed' => count($ad_ids),
        'succeed' => $success
    ]);
    exit;
}

// 获取广告列表
$ads_data = Todo_Ads_Manager::get_ads();

// 预取分组映射（id => name），用于列表显示分组名称
$ad_group_map = array();
$__all_groups = Todo_Ads_Manager::get_ad_groups();
if (is_array($__all_groups)) {
    foreach ($__all_groups as $__g) {
        if (isset($__g['id']) && isset($__g['name'])) {
            $ad_group_map[intval($__g['id'])] = $__g['name'];
        }
    }
}

// 生成批量操作CSRF令牌
$ads_bulk_nonce = class_exists('Todo_Security_Manager') ? Todo_Security_Manager::generate_csrf_token('ads_bulk') : '';
?>
<script type="text/javascript">window.TODO = window.TODO || {}; window.TODO.adsBulkNonce = '<?php echo esc_js($ads_bulk_nonce); ?>';</script>

<div class="admin-ads-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title verse">“此曲只应天上有，人间能得几回闻。”<span>——杜甫</span></h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=ad-new'); ?>" class="button">新建广告</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html($message); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html($error); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <div class="admin-card">
        <div class="admin-card-header no-border">
            <h3 class="admin-card-title">Banner列表</h3>
            <div class="admin-card-actions">
                <select id="bulk-actions" class="bulk-actions">
                    <option value="">批量操作</option>
                    <option value="activate">激活</option>
                    <option value="deactivate">停用</option>
                    <option value="delete">删除</option>
                </select>
                <button id="apply-bulk-action" class="button button-secondary">应用</button>
            </div>
        </div>

        <div class="admin-card-content">
            <?php if (empty($ads_data)): ?>
                <div class="no-items">
                    <p>未找到广告。</p>
                </div>
            <?php else: ?>
                <div class="ads-grid">
                    <?php foreach ($ads_data as $ad): ?>
                        <?php $is_active = (isset($ad['status']) && ($ad['status'] === 'active' || intval($ad['status']) === 1)); ?>
                        <div class="ad-card<?php echo $is_active ? ' active' : ''; ?>">
                            <div class="ad-card-header" style="background: none;">
                                <label class="ad-select-label"><input type="checkbox" class="ad-select"
                                        value="<?php echo $ad['id']; ?>"></label>
                                <h4 class="ad-title">
                                    <?php echo esc_html($ad['title']); ?>
                                </h4>
                                <div class="ad-status <?php echo $is_active ? 'active' : 'inactive'; ?>">
                                    <?php echo $is_active ? '已激活' : '已停用'; ?>
                                </div>
                            </div>

                            <div class="ad-card-content">
                                <div class="ad-meta">
                                    <div class="ad-meta-item">
                                        <span class="meta-label">类 型：</span>
                                        <span class="meta-value">
                                            <?php
                                            $types = array(
                                                'image' => '图片广告',
                                                'code' => '代码广告',
                                                'media' => '多媒体广告'
                                            );
                                            echo isset($types[$ad['type']]) ? $types[$ad['type']] : $ad['type'];
                                            ?>
                                        </span>
                                    </div>

                                    <div class="ad-meta-item">
                                        <span class="meta-label">分 组：</span>
                                        <span
                                            class="meta-value"><?php echo isset($ad_group_map[intval($ad['group_id'])]) ? esc_html($ad_group_map[intval($ad['group_id'])]) : '未分组'; ?></span>
                                    </div>

                                    <div class="ad-meta-item">
                                        <span class="meta-label">创 建：</span>
                                        <span class="meta-value"><?php echo date('Y-m-d H:i', strtotime($ad['created_at'])); ?></span>
                                    </div>

                                    <?php if (!empty($ad['start_date']) || !empty($ad['end_date'])): ?>
                                    <div class="ad-meta-item">
                                        <span class="meta-label shrink-0">区 间：</span>
                                        <div class="flex-col">
                                            <span class="meta-value" style="color: #282832;">
                                            <?php echo (!empty($ad['start_date']) ? date('Y-m-d H:i', strtotime($ad['start_date'])) : '...'); ?>
                                            </span>
                                            <span class="meta-value" style="color: #282832;">
                                                <?php echo (!empty($ad['end_date']) ? date('Y-m-d H:i', strtotime($ad['end_date'])) : '...'); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (!empty($ad['start_time']) || !empty($ad['end_time'])): ?>
                                    <div class="ad-meta-item">
                                        <span class="meta-label">时 段：</span>
                                        <span class="meta-value" style="color: #282832;">
                                            <?php echo (!empty($ad['start_time']) ? date('H:i', strtotime($ad['start_time'])) : '...'); ?>
                                            ~
                                            <?php echo (!empty($ad['end_time']) ? date('H:i', strtotime($ad['end_time'])) : '...'); ?>
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="ad-card-actions">
                                <a href="<?php echo todo_admin_url('index.php?page=ad-edit&id=' . $ad['id']); ?>"
                                    class="button button-link">编辑</a>
                                <a href="javascript:void(0);" onclick="previewAd(<?php echo $ad['id']; ?>)"
                                    class="btn-normal">预览</a>
                                <a href="<?php echo todo_admin_url('index.php?page=ads&action=delete&id=' . $ad['id'] . '&_todononce=' . urlencode(Todo_Security_Manager::generate_csrf_token('delete_ad_' . $ad['id']))); ?>"
                                    class="btn-normal delete-ad-btn js-todo-post-action" data-confirm="确定删除该广告吗？">删除</a>

                                <a href="<?php echo todo_admin_url('index.php?page=ads&action=toggle_status&id=' . $ad['id'] . '&_todononce=' . urlencode(Todo_Security_Manager::generate_csrf_token('toggle_ad_' . $ad['id']))); ?>"
                                    class="btn-normal js-todo-post-action" style="margin-left: auto;">
                                    <?php echo $is_active ? '停用' : '激活'; ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    .bulk-actions {
        min-width: 150px;
    }

    .ads-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
        gap: 20px;
    }

    .ad-card {
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        border-radius: 4px;
        background: linear-gradient(0deg, #f9f9f9, transparent);
        overflow: hidden;
    }

    .ad-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 15px;
        background: #f6f7f7;
    }

    .ad-card.active {
        background: linear-gradient(0deg, #f9f9f9, transparent);
    }

    .ad-select-label {
        margin-bottom: 0;
        opacity: 0.2;
    }

    .ad-card:hover .ad-select-label { opacity: 1; }

    .ad-title {
        margin: 0 auto 0 10px;
        font-size: 16px;
    }

    .ad-status {
        font-size: 12px;
        padding: 3px 8px;
        border-radius: 3px;
    }

    .ad-status.active {
        background: #f5fcf5;
        color: #46b450;
        box-shadow: inset 0 0 1px #148514;
    }

    .ad-status.inactive {
        background: #fff8f8;
        color: #dc3232;
        box-shadow: inset 0 0 1px #830f0f;
    }

    .ad-card-content {
        padding: 15px;
    }

    .ad-thumbnail-container {
        margin-bottom: 15px;
        text-align: center;
    }

    .ad-thumbnail {
        max-width: 100%;
        height: auto;
        max-height: 150px;
        border: 1px solid #ddd;
        border-radius: 3px;
        cursor: pointer;
    }

    .ad-thumbnail:hover {
        border-color: #0073aa;
    }

    .ad-meta {
        margin-top: -10px;
    }

    .ad-meta-item {
        display: flex;
        margin-left: 22px;
        margin-bottom: 5px;
        font-size: 13px;
    }

    .meta-label {
        margin-right: 5px;
        color: #a0a0aa;
    }

    .meta-value {
        color: #a0a0aa;
    }

    .ad-card-actions {
        display: flex;
        align-items: center;
        gap: 20px;
        padding: 0 15px 10px;
    }
</style>

<script type="text/javascript">
    function previewAd(adId) {
        window.open(<?php echo json_encode(todo_admin_url()); ?> + 'index.php?page=ad-preview&id=' + adId + '&format=iframe', '_blank', 'width=800,height=600');
    }

    document.addEventListener('DOMContentLoaded', function () {
        // 批量操作
        const bulkActions = document.getElementById('bulk-actions');
        const applyBulkAction = document.getElementById('apply-bulk-action');

        if (bulkActions && applyBulkAction) {
            applyBulkAction.addEventListener('click', function () {
                const action = bulkActions.value;
                if (!action) {
                    TodoCMS.Utils.showMessage('请选择批量操作', 'warning');
                    return;
                }

                const selectedAds = document.querySelectorAll('.ad-card input[type="checkbox"]:checked');
                if (selectedAds.length === 0) {
                    TodoCMS.Utils.showMessage('请至少选择一个广告', 'warning');
                    return;
                }

                const adIds = Array.from(selectedAds).map(checkbox => checkbox.value);

                // 封装批量操作逻辑
                const performBulkAction = function () {
                    // 执行批量操作
                    const formData = new FormData();
                    formData.append('action', action);
                    formData.append('ads', JSON.stringify(adIds));
                    // 附带CSRF令牌
                    if (window.TODO && window.TODO.adsBulkNonce) {
                        formData.append('_todononce', window.TODO.adsBulkNonce);
                    }

                    fetch(<?php echo json_encode(todo_admin_url('index.php?page=ads&action=bulk')); ?>, {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData,
                        credentials: 'same-origin'
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data && data.success) {
                                location.reload();
                            } else {
                                const msg = (data && (data.message || data.error)) ? (data.message || data.error) : '操作失败';
                                TodoCMS.Utils.showMessage(msg, 'error');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            TodoCMS.Utils.showMessage('操作失败', 'error');
                        });
                };

                // 确认删除操作
                if (action === 'delete') {
                    TodoModal.deleteConfirm({
                        title: '确认删除广告',
                        message: `确定要永久删除选中的 ${adIds.length} 个广告吗？`,
                        onConfirm: function () {
                            performBulkAction();
                        }
                    });
                    return;
                }

                performBulkAction();
            });
        }

        // 单个删除确认
        const deleteButtons = document.querySelectorAll('.delete-ad-btn');
        deleteButtons.forEach(button => {
            button.addEventListener('click', function (e) {
                e.preventDefault();
                const url = this.getAttribute('href');
                TodoModal.deleteConfirm({
                    title: '确认删除广告',
                    message: '确定要删除这个广告吗？删除后不可恢复。',
                    onConfirm: function () {
                        window.location.href = url;
                    }
                });
            });
        });
    });
</script>
