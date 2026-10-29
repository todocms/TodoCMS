<?php
/**
 * 升级套餐管理页面
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 权限检查
if (!todo_current_user_can('manage_users')) {
    todo_die('您没有权限访问此页面。');
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF验证
    if (!Todo_Security_Manager::verify_request_csrf('upgrade_package_action', '_csrf_token')) {
        todo_die('安全验证失败，请重新提交。');
    }
    
    $package_manager = todo_get_upgrade_package_manager();
    
    // 创建操作的结果占位，便于后续重定向到编辑页展示规范化后的slug
    $created_id = 0;
    $final_slug = '';
    
    // 处理批量操作（借鉴 users.php 风格）
    if (isset($_POST['bulk_action']) && !empty($_POST['package_ids']) && is_array($_POST['package_ids'])) {
        $ids = array_map('intval', $_POST['package_ids']);
        $bulk_action = sanitize_text_field($_POST['bulk_action']);
        $success = 0; $failed = 0;
        foreach ($ids as $pid) {
            switch ($bulk_action) {
                case 'activate':
                    $res = $package_manager->update_package($pid, array('is_active' => 1));
                    $success += $res ? 1 : 0; $failed += $res ? 0 : 1;
                    break;
                case 'deactivate':
                    $res = $package_manager->update_package($pid, array('is_active' => 0));
                    $success += $res ? 1 : 0; $failed += $res ? 0 : 1;
                    break;
                case 'delete':
                    $res = $package_manager->delete_package($pid);
                    $success += $res ? 1 : 0; $failed += $res ? 0 : 1;
                    break;
            }
        }
        if ($success > 0) {
            todo_add_admin_notice('批量操作完成：成功 ' . $success . ' 个' . ($failed>0 ? ('，失败 ' . $failed . ' 个') : '' ) . '。', $failed>0?'warning':'success');
        } else {
            todo_add_admin_notice('批量操作未成功，请检查权限或数据状态。', 'error');
        }
        // 重定向避免重复提交
        todo_redirect(todo_admin_url('index.php?page=upgrade-packages'));
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
            $data = array(
                'package_name' => sanitize_text_field($_POST['package_name']),
                'package_slug' => sanitize_text_field($_POST['package_slug']),
                'target_level_id' => intval($_POST['target_level_id']),
                'package_type' => sanitize_text_field($_POST['package_type']),
                'points_amount' => intval($_POST['points_amount']),
                'price' => floatval($_POST['price']),
                'original_price' => $_POST['original_price'] === '' ? '' : floatval($_POST['original_price']),
                'validity_days' => intval($_POST['validity_days']),
                'package_features' => $_POST['package_features'] ?? array(),
                'package_icon' => sanitize_text_field($_POST['package_icon']),
                'package_color' => sanitize_text_field($_POST['package_color']),
                'description' => todo_kses_post($_POST['description']),
                'sort_order' => intval($_POST['sort_order']),
                'is_recommended' => isset($_POST['is_recommended']) ? 1 : 0,
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            );

            // 1) 预置唯一slug（不依赖ID），避免create阶段因重复而失败
            $base = sanitize_title($data['package_name']);
            if (empty($base)) { $base = 'package'; }
            $pre_slug = $base;
            if ($package_manager->package_slug_exists($pre_slug)) {
                $i = 2;
                $candidate = $base . '-' . $i;
                while ($package_manager->package_slug_exists($candidate)) {
                    $i++;
                    $candidate = $base . '-' . $i;
                }
                $pre_slug = $candidate;
            }
            $data['package_slug'] = $pre_slug;
            
            // 2) 创建
            $insert_id = $package_manager->create_upgrade_package($data);
            if ($insert_id) {
                // 3) 使用ID规范化最终slug：拼音-<id>
                $final_slug = $base . '-' . $insert_id;
                // 极小概率冲突兜底（排除自身）
                if ($package_manager->package_slug_exists($final_slug, $insert_id)) {
                    $i = 2;
                    $candidate = $final_slug . '-' . $i;
                    while ($package_manager->package_slug_exists($candidate, $insert_id)) {
                        $i++;
                        $candidate = $final_slug . '-' . $i;
                    }
                    $final_slug = $candidate;
                }
                // 4) 回写最终slug
                $package_manager->update_package($insert_id, array('package_slug' => $final_slug));
                
                todo_add_admin_notice('升级套餐创建成功！', 'success');
                // 创建成功后跳转到编辑页面
                todo_redirect(todo_admin_url('index.php?page=upgrade-packages&edit=' . $insert_id));
            } else {
                todo_add_admin_notice('升级套餐创建失败，请检查数据是否正确。', 'error');
                // 失败时返回列表页
                todo_redirect(todo_admin_url('index.php?page=upgrade-packages'));
            }
            break;
            
        case 'update':
            $package_id = intval($_POST['package_id']);
            $data = array(
                'package_name' => sanitize_text_field($_POST['package_name']),
                'package_slug' => sanitize_text_field($_POST['package_slug']),
                'target_level_id' => intval($_POST['target_level_id']),
                'package_type' => sanitize_text_field($_POST['package_type']),
                'points_amount' => intval($_POST['points_amount']),
                'price' => floatval($_POST['price']),
                'original_price' => $_POST['original_price'] === '' ? '' : floatval($_POST['original_price']),
                'validity_days' => intval($_POST['validity_days']),
                'package_features' => $_POST['package_features'] ?? array(),
                'package_icon' => sanitize_text_field($_POST['package_icon']),
                'package_color' => sanitize_text_field($_POST['package_color']),
                'description' => todo_kses_post($_POST['description']),
                'sort_order' => intval($_POST['sort_order']),
                'is_recommended' => isset($_POST['is_recommended']) ? 1 : 0,
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            );
            
            if ($package_manager->update_package($package_id, $data)) {
                todo_add_admin_notice('升级套餐更新成功！', 'success');
            } else {
                todo_add_admin_notice('升级套餐更新失败，请检查数据是否正确。', 'error');
            }
            // 更新后跳转回编辑页面
            todo_redirect(todo_admin_url('index.php?page=upgrade-packages&edit=' . $package_id));
            break;
            
        case 'delete':
            $package_id = intval($_POST['package_id']);
            if ($package_manager->delete_package($package_id)) {
                todo_add_admin_notice('升级套餐删除成功！', 'success');
            } else {
                todo_add_admin_notice('升级套餐删除失败，可能有订单正在使用此套餐。', 'error');
            }
            // 删除后返回列表页
            todo_redirect(todo_admin_url('index.php?page=upgrade-packages'));
            break;
    }
    
    // 默认重定向（兜底）
    todo_redirect(todo_admin_url('index.php?page=upgrade-packages'));
    exit;
}

// 获取升级套餐列表
$package_manager = todo_get_upgrade_package_manager();
$orderby = trim((string) ($_GET['orderby'] ?? 'sort_order'));
$order = strtolower(trim((string) ($_GET['order'] ?? 'asc'))) === 'desc' ? 'DESC' : 'ASC';
$allowed_orderby = array('package_name', 'package_type', 'target_level_name', 'price', 'sort_order', 'is_active');
if (!in_array($orderby, $allowed_orderby, true)) {
    $orderby = 'sort_order';
}
$packages = $package_manager->get_upgrade_packages(array(
    'orderby' => $orderby,
    'order' => $order,
    'is_active' => null
));

// 获取用户等级列表
$level_manager = todo_get_user_level_manager();
$levels = $level_manager->get_user_levels(array('is_active' => 1));

// 获取当前编辑的套餐
$edit_package = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_package = $package_manager->get_upgrade_package($_GET['edit']);
    // 统一从数据表字段 features 读取，并解码到 package_features 供表单使用
    if ($edit_package) {
        $raw_features = isset($edit_package->features) ? $edit_package->features : '';
        if (!empty($raw_features)) {
            $decoded = json_decode($raw_features, true);
            if (is_array($decoded)) {
                $edit_package->package_features = isset($decoded['list']) && is_array($decoded['list']) ? $decoded['list'] : array();
                $edit_package->package_icon = isset($decoded['icon']) ? $decoded['icon'] : '';
                $edit_package->package_color = isset($decoded['color']) ? $decoded['color'] : '';
            } else {
                $edit_package->package_features = array();
                $edit_package->package_icon = '';
                $edit_package->package_color = '';
            }
        } else {
            $edit_package->package_features = array();
            $edit_package->package_icon = '';
            $edit_package->package_color = '';
        }
    }
}

// 页面标题
$page_title = $edit_package ? '编辑升级套餐' : '升级套餐管理';

// 套餐类型选项
$package_types = array(
    'points' => '积分套餐',
    'direct' => '直升套餐',
    'time' => '时长套餐'
);

// 如果是编辑模式，显示编辑页面
if ($edit_package || isset($_GET['new'])) {
    include 'upgrade-packages-edit.php';
    return;
}
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title verse">“会当凌绝顶，一览众山小。”<span>——杜甫《望岳》</span></h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=upgrade-packages&new=1'); ?>" class="button button-primary">
                添加新套餐
            </a>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <h3 class="admin-card-title">搜索和筛选</h3>
        </div>
        <div class="admin-card-content">
            <div class="search-filters">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="search-name">套餐名称</label>
                        <input type="text" id="search-name" class="form-control" placeholder="搜索套餐名称...">
                    </div>
                    <div class="filter-group">
                        <label for="filter-type">套餐类型</label>
                        <select id="filter-type" class="form-control">
                            <option value="">全部类型</option>
                            <?php foreach ($package_types as $type => $label): ?>
                            <option value="<?php echo esc_attr($type); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="filter-level">目标等级</label>
                        <select id="filter-level" class="form-control">
                            <option value="">全部等级</option>
                            <?php foreach ($levels as $level): ?>
                            <option value="<?php echo $level->id; ?>"><?php echo esc_html($level->level_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="filter-status">状态</label>
                        <select id="filter-status" class="form-control">
                            <option value="">全部状态</option>
                            <option value="1">启用</option>
                            <option value="0">禁用</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <button type="button" class="button button-secondary" onclick="resetFilters()">重置筛选</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 套餐列表 -->
    <div class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-section">
                <h3 class="admin-card-title">升级套餐列表</h3>
                <div class="admin-card-subtitle">
                    <span id="total-count"><?php echo is_array($packages) ? count($packages) : 0; ?></span> 个套餐
                    <span id="filtered-count" style="display: none;"></span>
                </div>
            </div>
            <div class="admin-card-actions">
                <form method="get" class="search-form" action="<?php echo todo_admin_url('index.php'); ?>" onsubmit="event.preventDefault(); document.getElementById('search-name').value = this.querySelector('input[name=q]').value; applyFilters();">
                    <input type="hidden" name="page" value="upgrade-packages">
                    <?php if (function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()): ?>
                        <input type="hidden" name="format" value="iframe">
                    <?php endif; ?>
                    <input type="search" name="q" placeholder="搜索套餐...">
                    <button type="submit" class="button button-secondary">搜索</button>
                </form>
            </div>
        </div>
        <div class="admin-card-content">
            <?php if (empty($packages)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="toicon">📦</i>
                </div>
                <h3>暂无升级套餐</h3>
                <p>开始创建您的第一个升级套餐吧！</p>
                <a href="<?php echo todo_admin_url('index.php?page=upgrade-packages&new=1'); ?>" class="button button-primary">
                    <i class="toicon">✚</i> 添加新套餐
                </a>
            </div>
            <?php else: ?>
            <!-- 表格视图 -->
            <div id="table-view" class="view-mode">
                <table class="admin-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th class="check-column">
                                <input type="checkbox" id="select-all">
                            </th>
                            <?php echo todo_admin_render_sort_th('套餐信息', 'package_name', 'column-primary', array('query' => $_GET, 'base_url' => todo_admin_url('index.php'), 'current_orderby' => $orderby, 'current_order' => strtolower($order), 'style' => 'min-width: 200px;')); ?>
                            <?php echo todo_admin_render_sort_th('类型', 'package_type', '', array('query' => $_GET, 'base_url' => todo_admin_url('index.php'), 'current_orderby' => $orderby, 'current_order' => strtolower($order), 'style' => 'min-width: 100px;')); ?>
                            <?php echo todo_admin_render_sort_th('目标等级', 'target_level_name', '', array('query' => $_GET, 'base_url' => todo_admin_url('index.php'), 'current_orderby' => $orderby, 'current_order' => strtolower($order))); ?>
                            <?php echo todo_admin_render_sort_th('价格', 'price', '', array('query' => $_GET, 'base_url' => todo_admin_url('index.php'), 'current_orderby' => $orderby, 'current_order' => strtolower($order), 'style' => 'min-width: 100px;')); ?>
                            <?php echo todo_admin_render_sort_th('排序', 'sort_order', '', array('query' => $_GET, 'base_url' => todo_admin_url('index.php'), 'current_orderby' => $orderby, 'current_order' => strtolower($order))); ?>
                            <?php echo todo_admin_render_sort_th('状态', 'is_active', '', array('query' => $_GET, 'base_url' => todo_admin_url('index.php'), 'current_orderby' => $orderby, 'current_order' => strtolower($order))); ?>
                        </tr>
                    </thead>
                    <tbody id="packages-tbody">
                        <?php foreach ($packages as $package): ?>
                        <?php 
                        $discount = $package_manager->calculate_package_discount($package);
                        ?>
                        <tr class="package-row" 
                            data-name="<?php echo esc_attr(strtolower($package->package_name)); ?>"
                            data-type="<?php echo esc_attr($package->package_type); ?>"
                            data-level="<?php echo esc_attr($package->target_level_id); ?>"
                            data-status="<?php echo esc_attr($package->is_active); ?>">
                            <td>
                                <input type="checkbox" name="package_ids[]" value="<?php echo $package->id; ?>">
                            </td>
                            <td>
                                <div class="package-info">
                                    <div class="package-header">
                                        <?php if ($package->package_icon): ?>
                                        <i class="<?php echo esc_attr($package->package_icon); ?>" style="color: <?php echo esc_attr($package->package_color); ?>"></i>
                                        <?php endif; ?>
                                        <strong><?php echo esc_html($package->package_name); ?></strong>
                                        <?php if ($package->is_recommended): ?>
                                        <span class="recommended-badge">推荐</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="description m-0"><?php echo esc_html($package->package_slug); ?></div>
                                    <?php if ($package->description): ?>
                                    <div class="description m-0"><?php echo todo_kses_post($package->description); ?></div>
                                    <?php endif; ?>
                                    <div class="row-actions">
                                        <span class="edit">
                                            <a href="<?php echo todo_admin_url('index.php?page=upgrade-packages&edit=' . $package->id); ?>">编辑</a> |
                                        </span>
                                        <span class="delete">
                                            <a href="javascript:void(0);" onclick="deletePackage(<?php echo $package->id; ?>, '<?php echo esc_js($package->package_name); ?>')" class="submitdelete">删除</a>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="type-badge type-<?php echo esc_attr($package->package_type); ?>">
                                    <?php echo esc_html($package_types[$package->package_type] ?? $package->package_type); ?>
                                </span>
                                <?php if ($package->package_type === 'points'): ?>
                                <div class="type-detail"><?php echo number_format($package->points_amount); ?> 积分</div>
                                <?php elseif ($package->validity_days > 0): ?>
                                <div class="type-detail"><?php echo $package->validity_days; ?> 天</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($package->target_level_name): ?>
                                <div class="level-badge"<?php echo !empty($package->target_level_color) ? ' style="background-color: ' . esc_attr($package->target_level_color) . '"' : ''; ?>>
                                    <?php echo esc_html($package->target_level_name); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="price-info">
                                    <span class="current-price"><?php echo todo_format_admin_price($package->price); ?></span>
                                    <?php if ($discount['has_discount']): ?>
                                    <span class="original-price"><?php echo todo_format_admin_price($package->original_price); ?></span>
                                    <span class="discount-rate"><?php echo $discount['discount_rate']; ?>% OFF</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo esc_html($package->sort_order); ?></td>
                            <td>
                                <span class="status-<?php echo $package->is_active ? 'active' : 'inactive'; ?>">
                                    <?php echo $package->is_active ? '启用' : '禁用'; ?>
                                </span>
                            </td>
                        </tr>

                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <!-- 批量操作栏 -->
            <div id="bulk-actions-card" class="admin-pagination" style="display:none;">
                <div class="info">已选择 <b id="selected-count">0</b> 个套餐</div>
                <div class="pagination" style="gap: 10px;">
                    <select id="bulk-action" class="form-control">
                        <option value="">批量操作</option>
                        <option value="activate">启用</option>
                        <option value="deactivate">禁用</option>
                        <option value="delete">删除</option>
                    </select>
                    <button type="button" class="button button-secondary" onclick="applyBulkAction()">应用</button>
                </div>
            </div>
        </div>
    </div>
</div>

<template id="upgrade-package-delete-modal-template" data-size="sm" data-type="danger">
    <div data-slot="title">确认删除</div>
    <div data-slot="body">
        <p>您确定要删除套餐 "<span id="delete-package-name"></span>" 吗？</p>
        <p class="text-warning">如果有订单正在使用此套餐，删除可能会失败。</p>
    </div>
    <div data-slot="footer">
        <button type="button" class="button button-secondary todo-modal-cancel">取消</button>
        <button type="button" class="button button-primary" id="confirm-delete-btn">确认删除</button>
    </div>
</template>

<style>
/* 搜索筛选样式 */
.search-filters {
    margin-bottom: 0;
}

.filter-row {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
}

.filter-group {
    flex: 1;
    min-width: 150px;
}

/* 套餐信息样式 */
.package-info {
    max-width: 300px;
}

.package-header {
    display: flex;
    align-items: center;
    gap: 8px;
}

.package-header i {
    font-size: 16px;
}

.recommended-badge {
    background: #ff6b35;
    color: white;
    flex-shrink: 0;
    padding: 0 6px;
    border-radius: 3px;
    font-size: 12px;
}

/* 类型标识样式 */
.type-badge {
    border: 1px solid #7c7c8f;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    color: #333;
}

.type-badge.type-points {
    border-color: #3ba7dc;
    color: #3ba7dc;
}

.type-badge.type-direct {
    border-color: #46b450;
    color: #46b450;
}

.type-badge.type-time {
    border-color: #ff8c00;
    color: #ff8c00;
}

.type-detail {
    font-size: 11px;
    color: #666;
    margin-top: 2px;
    text-indent: 5px;
}

/* 等级标识样式 */
.level-badge {
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    background-color: #828282;
    color: white;
    display: inline-block;
}

/* 价格信息样式 */
.price-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.current-price {
    font-weight: 500;
    color: #d63638;
    font-size: 14px;
}

.original-price {
    font-size: 12px;
    margin: -5px 0 0;
    color: #666;
    text-decoration: line-through;
}

.discount-rate {
    font-size: 10px;
    background: linear-gradient(90deg, #7a8293, transparent);
    color: white;
    padding: 1px 4px;
    border-radius: 2px;
}

/* 卡片视图样式 */
.packages-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
}

.package-card {
    border: 1px solid #ddd;
    border-radius: 8px;
    background: #fff;
    overflow: hidden;
    transition: box-shadow 0.2s;
}

.package-card:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.package-card-header {
    padding: 15px;
    background: #f9f9f9;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.package-icon i {
    font-size: 24px;
}

.package-status {
    display: flex;
    gap: 5px;
    align-items: center;
}

.package-card-body {
    padding: 15px;
}

.package-title {
    margin: 0 0 8px 0;
    font-size: 16px;
    font-weight: 500;
}

.package-card .package-slug {
    margin-bottom: 8px;
}

.package-type {
    margin-bottom: 8px;
}

.package-price {
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.package-level {
    margin-bottom: 8px;
}

.package-description {
    font-size: 13px;
    color: #666;
    line-height: 1.4;
    max-height: 60px;
    overflow: hidden;
}

.package-card-footer {
    padding: 15px;
    background: #f9f9f9;
    border-top: 1px solid #eee;
}

.package-actions {
    display: flex;
    gap: 8px;
}

/* 批量操作样式 */
.bulk-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.selected-count {
    font-size: 13px;
    color: #666;
}

/* 空状态样式 */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #666;
}

.empty-state-icon i {
    font-size: 48px;
    color: #ccc;
}

.empty-state h3 {
    margin: 0 0 10px 0;
    font-size: 18px;
    color: #333;
}

.empty-state p {
    margin: 0 0 20px 0;
    font-size: 14px;
}

.text-warning {
    color: #ff8c00;
}

/* 响应式设计 */
@media (max-width: 768px) {
    .filter-row {
        flex-direction: column;
    }
    
    .filter-group {
        min-width: auto;
    }
    
    .packages-grid {
        grid-template-columns: 1fr;
    }
    
    .package-info {
        max-width: none;
    }
}
</style>

<script type="text/javascript">
// 全局变量
let currentSort = { field: '', direction: '' };
let selectedPackages = [];
let bulkEditMode = false;

// 页面加载完成后初始化
document.addEventListener('DOMContentLoaded', function() {
    initializeFilters();
    initializeSorting();
    initializeBulkActions();
    initializeSearch();
});

// 初始化筛选功能
function initializeFilters() {
    const filters = ['search-name', 'filter-type', 'filter-level', 'filter-status'];
    
    filters.forEach(filterId => {
        const element = document.getElementById(filterId);
        if (element) {
            element.addEventListener('input', applyFilters);
            element.addEventListener('change', applyFilters);
        }
    });
}

// 初始化搜索功能
function initializeSearch() {
    const searchInput = document.getElementById('search-name');
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(applyFilters, 300);
        });
    }
}

// 应用筛选
function applyFilters() {
    const searchName = document.getElementById('search-name').value.toLowerCase();
    const filterType = document.getElementById('filter-type').value;
    const filterLevel = document.getElementById('filter-level').value;
    const filterStatus = document.getElementById('filter-status').value;
    
    const rows = document.querySelectorAll('.package-row');
    let visibleCount = 0;
    
    // 筛选表格行
    rows.forEach(row => {
        const name = row.dataset.name || '';
        const type = row.dataset.type || '';
        const level = row.dataset.level || '';
        const status = row.dataset.status || '';
        
        let visible = true;
        
        if (searchName && !name.includes(searchName)) visible = false;
        if (filterType && type !== filterType) visible = false;
        if (filterLevel && level !== filterLevel) visible = false;
        if (filterStatus && status !== filterStatus) visible = false;
        
        row.style.display = visible ? '' : 'none';
        if (visible) visibleCount++;
    });
    
    // 更新计数
    updateFilteredCount(visibleCount);
}

// 重置筛选
function resetFilters() {
    document.getElementById('search-name').value = '';
    document.getElementById('filter-type').value = '';
    document.getElementById('filter-level').value = '';
    document.getElementById('filter-status').value = '';
    
    applyFilters();
}

// 更新筛选计数
function updateFilteredCount(count) {
    const totalCount = document.getElementById('total-count');
    const filteredCount = document.getElementById('filtered-count');
    const total = parseInt(totalCount.textContent);
    
    if (count < total) {
        filteredCount.textContent = `，显示 ${count} 个`;
        filteredCount.style.display = 'inline';
    } else {
        filteredCount.style.display = 'none';
    }
}

// 切换批量编辑模式
function toggleBulkEdit() {
    const bulkEditMode = document.body.classList.contains('bulk-edit-mode');
    const toggleButton = document.getElementById('bulk-edit-toggle');
    
    if (bulkEditMode) {
        document.body.classList.remove('bulk-edit-mode');
        toggleButton.innerHTML = '<i class="toicon">✏️</i> 批量编辑';

    } else {
        document.body.classList.add('bulk-edit-mode');
        toggleButton.innerHTML = '<i class="toicon">✖️</i> 退出编辑';
    }
}

// 初始化排序功能
function initializeSorting() {
    const sortableHeaders = document.querySelectorAll('.sortable');
    
    sortableHeaders.forEach(header => {
        header.addEventListener('click', function(e) {
            e.preventDefault();
            const field = this.dataset.sort;
            sortTable(field);
        });
    });
}

// 表格排序
function sortTable(field) {
    const tbody = document.getElementById('packages-tbody');
    const rows = Array.from(tbody.querySelectorAll('.package-row'));
    
    // 确定排序方向
    let direction = 'asc';
    if (currentSort.field === field && currentSort.direction === 'asc') {
        direction = 'desc';
    }
    
    // 排序
    rows.sort((a, b) => {
        let aVal, bVal;
        
        switch (field) {
            case 'package_name':
                aVal = a.querySelector('.package-header strong').textContent;
                bVal = b.querySelector('.package-header strong').textContent;
                break;
            case 'package_type':
                aVal = a.dataset.type;
                bVal = b.dataset.type;
                break;
            case 'target_level_name':
                aVal = a.querySelector('.level-badge')?.textContent || '';
                bVal = b.querySelector('.level-badge')?.textContent || '';
                break;
            case 'price':
                aVal = parseFloat(a.querySelector('.current-price').textContent.replace(/[^\d.]/g, ''));
                bVal = parseFloat(b.querySelector('.current-price').textContent.replace(/[^\d.]/g, ''));
                break;
            case 'sort_order':
                aVal = parseInt(a.cells[5].textContent);
                bVal = parseInt(b.cells[5].textContent);
                break;
            case 'is_active':
                aVal = a.dataset.status;
                bVal = b.dataset.status;
                break;
            default:
                return 0;
        }
        
        if (typeof aVal === 'string') {
            aVal = aVal.toLowerCase();
            bVal = bVal.toLowerCase();
        }
        
        if (direction === 'asc') {
            return aVal > bVal ? 1 : -1;
        } else {
            return aVal < bVal ? 1 : -1;
        }
    });
    
    // 重新插入排序后的行
    rows.forEach(row => tbody.appendChild(row));
    
    // 更新排序状态
    currentSort = { field, direction };
}

// 初始化批量操作
function initializeBulkActions() {
    const selectAll = document.getElementById('select-all');
    const checkboxes = document.querySelectorAll('input[name="package_ids[]"]');
    const bulkEditToggle = document.getElementById('bulk-edit-toggle');
    
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkActions();
        });
    }
    
    if (bulkEditToggle) {
        bulkEditToggle.addEventListener('click', function(e) {
            e.preventDefault();
            toggleBulkEdit();
        });
    }
    
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateBulkActions);
    });
}

// 更新批量操作
function updateBulkActions() {
    const checkboxes = document.querySelectorAll('input[name="package_ids[]"]:checked');
    const bulkCard = document.getElementById('bulk-actions-card');
    const selectedCount = document.getElementById('selected-count');
    
    selectedPackages = Array.from(checkboxes).map(cb => cb.value);
    
    if (selectedPackages.length > 0) {
        bulkCard.style.display = 'flex';
        selectedCount.textContent = selectedPackages.length;
    } else {
        bulkCard.style.display = 'none';
    }
}

// 应用批量操作
function applyBulkAction() {
    const action = document.getElementById('bulk-action').value;
    
    if (!action || selectedPackages.length === 0) {
        TodoCMS.Utils.showMessage('请选择操作和套餐', 'warning');
        return;
    }
    
    let message = '';
    switch (action) {
        case 'activate':
            message = `确定要启用选中的 ${selectedPackages.length} 个套餐吗？`;
            break;
        case 'deactivate':
            message = `确定要禁用选中的 ${selectedPackages.length} 个套餐吗？`;
            break;
        case 'delete':
            message = `确定要删除选中的 ${selectedPackages.length} 个套餐吗？`;
            break;
    }
    
    TodoModal.confirm({
        message: message,
        onConfirm: function() {
            // 创建表单提交批量操作
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            // 添加CSRF令牌
            const csrfField = document.querySelector('input[name="_csrf_token"]');
            if (csrfField) {
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '_csrf_token';
                csrfInput.value = csrfField.value;
                form.appendChild(csrfInput);
            }
            
            // 添加批量操作类型
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'bulk_action';
            actionInput.value = action;
            form.appendChild(actionInput);
            
            // 添加选中的套餐ID
            selectedPackages.forEach(id => {
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'package_ids[]';
                idInput.value = id;
                form.appendChild(idInput);
            });
            
            document.body.appendChild(form);
            form.submit();
        }
    });
}

// 删除套餐
function deletePackage(id, name) {
    // 存储要删除的套餐ID
    window.deletePackageId = id;
    const modal = new TodoModal({
        templateId: 'upgrade-package-delete-modal-template',
        destroyOnClose: true
    });
    window.deleteModalInstance = modal;
    modal.onCloseCallback(function () {
        window.deleteModalInstance = null;
        window.deletePackageId = null;
    });
    const root = modal.element;
    if (root) {
        const titleEl = root.querySelector('#delete-package-name');
        if (titleEl) titleEl.textContent = name;
        const btn = root.querySelector('#confirm-delete-btn');
        if (btn) btn.addEventListener('click', confirmDelete);
    }
    modal.open();
}

// 确认删除
function confirmDelete() {
    if (window.deletePackageId) {
        // 创建表单提交删除请求
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        // 添加CSRF令牌
        const csrfField = document.querySelector('input[name="_csrf_token"]');
        if (csrfField) {
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = '_csrf_token';
            csrfInput.value = csrfField.value;
            form.appendChild(csrfInput);
        }
        
        // 添加操作类型
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'delete';
        form.appendChild(actionInput);
        
        // 添加套餐ID
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'package_id';
        idInput.value = window.deletePackageId;
        form.appendChild(idInput);
        
        document.body.appendChild(form);
        form.submit();
    }
    
    closeDeleteModal();
}

// 关闭删除模态框
function closeDeleteModal() {
    if (window.deleteModalInstance) {
        window.deleteModalInstance.close();
        window.deleteModalInstance = null;
    }
    window.deletePackageId = null;
}
</script>

<!-- 引入CSRF管理器 -->
<script src="<?php echo todo_admin_url('assets/js/csrf-manager.js'); ?>" type="text/javascript"></script>

<?php
// 隐藏的CSRF令牌，供JavaScript使用
echo '<div style="display: none;">';
echo Todo_Security_Manager::get_csrf_field('upgrade_package_action', '_csrf_token');
echo '</div>';
?>
