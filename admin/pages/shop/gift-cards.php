<?php
/**
 * TodoCMS 礼品卡管理页面
 * 
 * 管理礼品卡生成、列表和状态
 * 
 * @package TodoCMS
 */

if (!defined('TODO_ADMIN')) {
    exit('Direct access denied');
}

if (!current_user_can('manage_options')) {
    todo_die(__('You do not have sufficient permissions to access this page.'));
}

global $tododb;
$table_name = $tododb->prefix . 'gift_cards';

// 1. 处理导出 CSV (必须在任何输出之前，或者清除缓冲区)
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    if (!current_user_can('manage_options')) {
        todo_die('Permission denied');
    }

    $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    
    $where_clauses = ['1=1'];
    $params = [];
    if ($status_filter !== '') {
        $where_clauses[] = 'status = ?';
        $params[] = intval($status_filter);
    }
    if ($search) {
        $where_clauses[] = 'code LIKE ?';
        $params[] = '%' . $tododb->esc_like($search) . '%';
    }
    
    $where_sql = implode(' AND ', $where_clauses);
    $export_records = $tododb->get_results("SELECT * FROM $table_name WHERE $where_sql ORDER BY id DESC", $params);
    
    if ($export_records) {
        // 清除之前的输出缓冲区内容（如 admin-header.php 输出的 HTML）
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        $filename = 'gift_cards_export_' . date('YmdHis') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Cache-Control: max-age=0');
        
        $output = fopen('php://output', 'w');
        // 添加 UTF-8 BOM 以支持 Excel 中文显示 (必不可少)
        fwrite($output, "\xEF\xBB\xBF");
        
        // 表头
        fputcsv($output, ['ID', '批次号', '卡号', '激活码(PIN)', '面值', '卡片类型', '状态', '有效期至', '创建时间']);
        
        foreach ($export_records as $row) {
            $status_text = '未知';
            if ($row->status == 0) $status_text = '未使用';
            if ($row->status == 1) $status_text = '已使用';
            if ($row->status == 2) $status_text = '已作废';
            
            // 处理日期：如果是 0000-00-00 则显示为“无”，并缩短格式以减少列宽占用
            $expiry_at = ($row->expiry_at && $row->expiry_at !== '0000-00-00 00:00:00') 
                ? date('Y-m-d H:i', strtotime($row->expiry_at)) 
                : '无';
            $created_at = ($row->created_at && $row->created_at !== '0000-00-00 00:00:00') 
                ? date('Y-m-d H:i', strtotime($row->created_at)) 
                : '-';

            fputcsv($output, [
                $row->id,
                $row->batch_no,
                $row->code,
                "\t" . $row->pin, // 添加制表符防止 Excel 把 PIN 当成数字处理（如果是纯数字）
                $row->amount,
                $row->card_type == 1 ? '实体卡' : '电子卡',
                $status_text,
                $expiry_at,
                $created_at
            ]);
        }
        fclose($output);
        exit;
    } else {
        todo_add_admin_notice('没有可导出的数据', 'error');
    }
}

// 处理表单提交
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!todo_verify_nonce($_POST['_todononce'], 'todo_gift_card_action')) {
        $error = '安全验证失败，请重试。';
    } else {
        $action = sanitize_text_field($_POST['action']);
        
        switch ($action) {
            case 'generate_cards':
                $amount = floatval($_POST['amount']);
                $quantity = intval($_POST['quantity']);
                $batch_no = sanitize_text_field($_POST['batch_no'] ?? '');
                $card_type = intval($_POST['card_type'] ?? 0);
                $expiry_date = sanitize_text_field($_POST['expiry_at'] ?? '');
                
                if ($amount < 1 || $quantity <= 0 || $quantity > 500) {
                    $error = '面值必须至少为 1，且每次最多生成 500 张。';
                } else {
                    $success_count = 0;
                    for ($i = 0; $i < $quantity; $i++) {
                        $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 16));
                        $pin = ($card_type == 1) ? strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6)) : null;
                        
                        $data = [
                            'batch_no' => $batch_no,
                            'code' => $code,
                            'pin' => $pin,
                            'amount' => $amount,
                            'card_type' => $card_type,
                            'status' => 0,
                            'created_by' => get_current_user_id(),
                            'created_at' => current_time('mysql')
                        ];
                        
                        if (!empty($expiry_date)) {
                            $data['expiry_at'] = $expiry_date . ' 23:59:59';
                        }

                        $result = $tododb->insert($table_name, $data);
                        if ($result) {
                            $success_count++;
                        }
                    }
                    $curr_sym = todo_get_default_currency_config()['symbol'];
                    $message = "成功生成 {$success_count} 张面值为 {$curr_sym}{$amount} 的礼品卡。";
                }
                break;
                
            case 'void_card':
                $id = intval($_POST['id']);
                $result = $tododb->update($table_name, ['status' => 2], ['id' => $id, 'status' => 0]);
                if ($result) {
                    $message = '礼品卡已作废。';
                } else {
                    $error = '操作失败，礼品卡可能已被使用或作废。';
                }
                break;

            case 'batch_action':
                $ids = isset($_POST['ids']) ? array_map('intval', (array)$_POST['ids']) : [];
                $batch_action = sanitize_text_field($_POST['batch_action_type'] ?? '');
                
                if (empty($ids)) {
                    $error = '请选择要操作的项目。';
                    break;
                }

                if ($batch_action === 'void') {
                    $success_count = $tododb->query("UPDATE $table_name SET status = 2 WHERE id IN (" . implode(',', $ids) . ") AND status = 0");
                    $message = "成功作废 {$success_count} 张礼品卡。";
                } elseif ($batch_action === 'delete') {
                    // 1. 先把选中的且未使用的设为作废
                    $tododb->query("UPDATE $table_name SET status = 2 WHERE id IN (" . implode(',', $ids) . ") AND status = 0");
                    // 2. 执行物理删除（通常建议只删除未激活或已作废的，避免删除财务记录）
                    $success_count = $tododb->query("DELETE FROM $table_name WHERE id IN (" . implode(',', $ids) . ") AND (status = 2 OR status = 0)");
                    $message = "成功删除 {$success_count} 条礼品卡记录（已激活的记录将被保留以维护账目完整性）。";
                }
                break;
        }

        if (!empty($message) && function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice($message, 'success');
        }
        if (!empty($error) && function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice($error, 'error');
        }
        header('Location: ' . todo_admin_url('index.php?page=todo-gift-cards'));
        exit;
    }
}

// 分页和筛选
$per_page = 20;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$offset = ($current_page - 1) * $per_page;
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';

$where_clauses = ['1=1'];
$params = [];

if ($status_filter !== '') {
    $where_clauses[] = 'status = ?';
    $params[] = intval($status_filter);
}

if ($search) {
    $where_clauses[] = 'code LIKE ?';
    $params[] = '%' . $tododb->esc_like($search) . '%';
}

$where_sql = implode(' AND ', $where_clauses);
$total_records = $tododb->get_var("SELECT COUNT(*) FROM $table_name WHERE $where_sql", $params);
$records = $tododb->get_results("SELECT * FROM $table_name WHERE $where_sql ORDER BY id DESC LIMIT $per_page OFFSET $offset", $params);
$total_pages = ceil($total_records / $per_page);
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">礼品卡管理</h2>
        <div class="admin-page-actions">
            <a href="?page=todo-balance" class="button button-secondary">返回余额管理</a>
            <button type="button" class="button button-primary" onclick="if(window.showGenerateModal){window.showGenerateModal();}else if(window.parent&&window.parent.showGenerateModal){window.parent.showGenerateModal();}">生成礼品卡</button>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <h3 class="admin-card-title">礼品卡列表</h3>
        </div>
        <div class="admin-card-content">
            <form method="get" id="filter-form">
                <div class="admin-filter-bar">
                    <div class="flex flex-wrap gap-y-3">
                        <input type="hidden" name="page" value="todo-gift-cards">
                        <select name="status" style="margin-right: 10px;">
                            <option value="">所有状态</option>
                            <option value="0" <?php selected($status_filter, '0'); ?>>未使用</option>
                            <option value="1" <?php selected($status_filter, '1'); ?>>已使用</option>
                            <option value="2" <?php selected($status_filter, '2'); ?>>已作废</option>
                        </select>
                        <input type="text" name="search" value="<?php echo esc_attr($search); ?>" placeholder="搜索卡号..." style="margin-right: 10px;">
                        <button type="submit" class="button button-secondary">筛选</button>
                        <?php if ($status_filter !== '' || $search): ?>
                            <a href="?page=todo-gift-cards" class="btn-normal m-l-05" style="line-height: 35px;">重置</a>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="button button-secondary float-right" onclick="exportCsv()">导出当前列表 CSV</button>
                </div>
            </form>

            <form method="post" id="batch-form">
                <?php todo_nonce_field('todo_gift_card_action'); ?>
                <input type="hidden" name="action" value="batch_action">
                <input type="hidden" name="batch_action_type" id="batch-action-type-input" value="">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" id="select-all"></th>
                            <th>ID</th>
                            <th>批次/类型</th>
                            <th>卡号 / PIN</th>
                            <th style="text-align: right;">面值</th>
                            <th style="min-width: 80px; text-align: center;">状态</th>
                            <th>激活用户</th>
                            <th>激活时间/有效期</th>
                            <th style="width: 160px; min-width: 160px;">创建时间</th>
                            <th style="min-width: 60px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($records): ?>
                            <?php foreach ($records as $record): ?>
                                <tr>
                                    <td><input type="checkbox" name="ids[]" value="<?php echo $record->id; ?>" class="row-checkbox"></td>
                                    <td><?php echo $record->id; ?></td>
                                    <td>
                                        <div class="tb-meta"><?php echo $record->batch_no ?: '-'; ?></div>
                                        <div style="font-size:12px; color:<?php echo $record->card_type == 1 ? '#0073aa' : '#666'; ?>">
                                            <?php echo $record->card_type == 1 ? '实体卡' : '电子卡'; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div><strong style="font-family:monospace; font-size:14px; letter-spacing:1px;"><?php echo esc_html($record->code); ?></strong></div>
                                        <?php if ($record->pin): ?>
                                            <div style="font-size:12px; color:#999;">PIN: <span style="font-family:monospace;"><?php echo esc_html($record->pin); ?></span></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right;"><span class="amount-positive"><?php echo todo_format_admin_price($record->amount); ?></span></td>
                                    <td style="text-align: center;">
                                        <?php if ($record->status == 0): ?>
                                            <?php 
                                            $is_expired = $record->expiry_at && strtotime($record->expiry_at) < time();
                                            if ($is_expired): ?>
                                                <span class="badge" style="background:#f5f5f5; color:#999; padding:2px 6px; border-radius:3px; font-size:12px;">已过期</span>
                                            <?php else: ?>
                                                <span class="badge" style="background:#e7f3ff; color:#0073aa; padding:2px 6px; border-radius:3px; font-size:12px;">未使用</span>
                                            <?php endif; ?>
                                        <?php elseif ($record->status == 1): ?>
                                            <span class="badge" style="background:#e7f7e7; color:#46b450; padding:2px 6px; border-radius:3px; font-size:12px;">已使用</span>
                                        <?php elseif ($record->status == 2): ?>
                                            <span class="badge" style="background:#ffe7e7; color:#dc3232; padding:2px 6px; border-radius:3px; font-size:12px;">已作废</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($record->activated_by): ?>
                                            <?php 
                                            $user = get_user_by('id', $record->activated_by);
                                            echo $user ? esc_html($user->get_display_name()) . " (#{$record->activated_by})" : "未知用户 (#{$record->activated_by})";
                                            ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($record->activated_at): ?>
                                            <div class="tb-meta"><?php echo date('Y-m-d H:i', strtotime($record->activated_at)); ?></div>
                                        <?php endif; ?>
                                        <?php if ($record->expiry_at): ?>
                                            <div style="font-size:11px; color:#999;">有效期至: <?php echo date('Y-m-d', strtotime($record->expiry_at)); ?></div>
                                        <?php endif; ?>
                                        <?php if (!$record->activated_at && !$record->expiry_at): ?>-<?php endif; ?>
                                    </td>
                                    <td><span class="tb-meta"><?php echo date('Y-m-d H:i', strtotime($record->created_at)); ?></span></td>
                                    <td>
                                        <?php if ($record->status == 0): ?>
                                            <button type="button" class="status-reject" style="cursor: pointer;" onclick="voidCard(<?php echo $record->id; ?>)">作废</button>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr class="empty"><td colspan="10"><div class="no-items"><p>没有找到礼品卡记录。</p></div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>

            <div id="batch-controls" class="admin-pagination" style="display:none;">
                <div class="info"><b id="batch-count-display">0</b> 项已选中</div>
                <div class="pagination" style="gap: 10px;">
                    <button type="button" class="button button-secondary" id="batch-cancel-btn">取消选择</button>
                    <button type="button" class="button button-secondary" onclick="submitBatchAction('void')">批量作废</button>
                    <button type="button" class="button" style="background:#dc3232;color:#fff;border-color:#dc3232;" onclick="submitBatchAction('delete')">批量删除</button>
                </div>
            </div>

            <form id="single-action-form" method="post" style="display:none;">
                <?php todo_nonce_field('todo_gift_card_action'); ?>
                <input type="hidden" name="action" id="single-action-name" value="">
                <input type="hidden" name="id" id="single-action-id" value="">
            </form>

            <?php if ($total_pages > 1): ?>
                <?php
                $pagination_add_args = [];
                if ($status_filter !== '') $pagination_add_args['status'] = $status_filter;
                if ($search) $pagination_add_args['search'] = $search;

                echo todo_render_pagination(array(
                    'total_items' => $total_records,
                    'per_page' => $per_page,
                    'current_page' => $current_page,
                    'base_url' => todo_admin_url('index.php?page=todo-gift-cards'),
                    'add_args' => $pagination_add_args,
                    'show_count' => true,
                ));
                ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$todo_gc_form_action = todo_admin_url('index.php?page=todo-gift-cards');
$todo_gc_iframe_mode = function_exists('todo_is_iframe_mode') && todo_is_iframe_mode();
if ($todo_gc_iframe_mode) {
    $todo_gc_form_action = add_query_arg('format', 'iframe', $todo_gc_form_action);
}
$todo_gc_form_target = $todo_gc_iframe_mode ? 'todo-admin-iframe' : '';
?>
<template id="todo-tpl-generate-card-modal">
    <form method="post" id="generate-card-form" action="<?php echo esc_url($todo_gc_form_action); ?>"<?php echo $todo_gc_form_target ? ' target="' . esc_attr($todo_gc_form_target) . '"' : ''; ?>>
        <div class="todo-modal-body-content">
            <?php todo_nonce_field('todo_gift_card_action'); ?>
            <input type="hidden" name="action" value="generate_cards">
            <table class="form-table w-120">
                <tr>
                    <th>礼品卡面值 <span class="required">*</span></th>
                    <td>
                        <input type="number" name="amount" id="gc-amount" step="1" min="1" required>
                        <?php echo todo_get_default_currency_config()['code']; ?>
                        <p class="description">充值到余额的具体金额，例如 100.00</p>
                    </td>
                </tr>
                <tr>
                    <th>生成数量 <span class="required">*</span></th>
                    <td>
                        <input type="number" name="quantity" id="gc-quantity" step="1" min="1" max="500" value="10" required>
                        <p class="description">每次最多生成500张</p>
                    </td>
                </tr>
                <tr>
                    <th>卡片类型</th>
                    <td>
                        <label style="margin-right: 20px;"><input type="radio" name="card_type" value="0" checked> 电子卡</label>
                        <label><input type="radio" name="card_type" value="1"> 实体卡 (生成PIN码)</label>
                    </td>
                </tr>
                <tr>
                    <th>批次名称</th>
                    <td>
                        <input type="text" name="batch_no" placeholder="如：2024夏季推广">
                        <p class="description">选填，便于后续分类管理和导出</p>
                    </td>
                </tr>
                <tr>
                    <th>有效期至</th>
                    <td>
                        <input type="date" name="expiry_at">
                        <p class="description">选填，过期后礼品卡将无法激活</p>
                    </td>
                </tr>
            </table>
        </div>
    </form>
</template>

<style>
.amount-positive { color: #46b450; font-weight: 500; }
</style>

<script src="<?php echo todo_admin_url('assets/js/csrf-manager.js'); ?>" type="text/javascript"></script>
<script type="text/javascript">
    function showGenerateModal() {
        if (typeof TodoModal === 'undefined') return;
        TodoModal.form({
            templateId: 'todo-tpl-generate-card-modal',
            title: '生成礼品卡',
            submitText: '确定生成',
            cancelText: '取消',
            width: 500,
            onSubmit: function(formData, form) {
                form.submit();
            }
        });
    }

    window.showGenerateModal = showGenerateModal;

    // 选择管理
    const selectAll = document.getElementById('select-all');
    const rowCheckboxes = document.querySelectorAll('.row-checkbox');
    const batchControls = document.getElementById('batch-controls');
    const batchCountDisplay = document.getElementById('batch-count-display');
    const batchCancelBtn = document.getElementById('batch-cancel-btn');

    function updateBatchControls() {
        const checkedBoxes = document.querySelectorAll('.row-checkbox:checked');
        const checkedCount = checkedBoxes.length;
        batchControls.style.display = checkedCount > 0 ? 'flex' : 'none';
        if (batchCountDisplay) {
            batchCountDisplay.innerText = checkedCount;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            rowCheckboxes.forEach(cb => cb.checked = this.checked);
            updateBatchControls();
            updateSelectAllState();
        });
    }

    rowCheckboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            updateBatchControls();
            updateSelectAllState();
        });
    });

    if (batchCancelBtn) {
        batchCancelBtn.addEventListener('click', function() {
            rowCheckboxes.forEach(cb => cb.checked = false);
            if (selectAll) {
                selectAll.checked = false;
                selectAll.indeterminate = false;
            }
            updateBatchControls();
        });
    }

    function updateSelectAllState() {
        if (!selectAll) return;
        const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
        const totalCount = rowCheckboxes.length;
        if (checkedCount === 0) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        } else if (checkedCount === totalCount) {
            selectAll.checked = true;
            selectAll.indeterminate = false;
        } else {
            selectAll.checked = false;
            selectAll.indeterminate = true;
        }
    }

    // Initial state check
    updateBatchControls();
    updateSelectAllState();

    function submitBatchAction(action) {
        if (!action) {
            TodoCMS.Utils.showMessage('请选择操作类型', 'warning');
            return;
        }
        
        const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
        if (checkedCount === 0) {
            TodoCMS.Utils.showMessage('请选择要操作的项目', 'warning');
            return;
        }

        // 设置隐藏域的值
        const actionInput = document.getElementById('batch-action-type-input');
        if (actionInput) actionInput.value = action;
        
        const confirmMsg = action === 'delete' ? '确定要删除选中的 ' + checkedCount + ' 张礼品卡吗？删除前系统将自动进行作废处理。已激活的卡片将保留记录。' : '确定要作废选中的 ' + checkedCount + ' 张礼品卡吗？';
        
        const doSubmit = function() {
            document.getElementById('batch-form').submit();
        };

        if (window.TodoCMS && TodoCMS.Utils && typeof TodoCMS.Utils.confirm === 'function') {
            TodoCMS.Utils.confirm(confirmMsg, doSubmit);
        } else if (confirm(confirmMsg)) {
            doSubmit();
        }
    }

    function voidCard(id) {
        if (confirm('确定要作废这张礼品卡吗？')) {
            const form = document.getElementById('single-action-form');
            document.getElementById('single-action-name').value = 'void_card';
            document.getElementById('single-action-id').value = id;
            form.submit();
        }
    }

    function exportCsv() {
        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('action', 'export_csv');
        window.location.href = currentUrl.toString();
    }

    try {
        if (window.parent && window.parent !== window) {
            window.parent.showGenerateModal = showGenerateModal;
        }
    } catch (e) {}
</script>
