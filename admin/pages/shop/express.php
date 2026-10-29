<?php
/**
 * TodoCMS 快递公司管理页面
 * 
 * 管理系统支持的快递公司
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('TODO_ADMIN')) {
    exit('Direct access denied');
}

// 检查权限
if (!todo_current_user_can('manage_options')) {
    todo_redirect(todo_admin_url('index.php?page=dashboard'));
    exit;
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 验证CSRF令牌
    if (!todo_verify_csrf_token($_POST['_csrf_token'] ?? '', 'express_companies')) {
        todo_admin_notice('CSRF验证失败', 'error');
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add_company':
                $name = sanitize_text_field($_POST['company_name'] ?? '');
                $code = sanitize_text_field($_POST['company_code'] ?? '');
                $website = esc_url_raw($_POST['company_website'] ?? '');
                $phone = sanitize_text_field($_POST['company_phone'] ?? '');
                $status = intval($_POST['company_status'] ?? 1);
                
                if (empty($name) || empty($code)) {
                    todo_admin_notice('公司名称和代码不能为空', 'error');
                } else {
                    $result = todo_add_express_company($name, $code, $website, $phone, $status);
                    if (is_todo_error($result)) {
                        todo_admin_notice($result->get_error_message(), 'error');
                    } elseif ($result) {
                        todo_admin_notice('快递公司添加成功', 'success');
                    } else {
                        todo_admin_notice('快递公司添加失败', 'error');
                    }
                }
                break;
                
            case 'update_company':
                $id = intval($_POST['company_id'] ?? 0);
                $name = sanitize_text_field($_POST['company_name'] ?? '');
                $code = sanitize_text_field($_POST['company_code'] ?? '');
                $website = esc_url_raw($_POST['company_website'] ?? '');
                $phone = sanitize_text_field($_POST['company_phone'] ?? '');
                $status = intval($_POST['company_status'] ?? 1);
                
                if ($id && !empty($name) && !empty($code)) {
                    $result = todo_update_express_company($id, $name, $code, $website, $phone, $status);
                    if (is_todo_error($result)) {
                        todo_admin_notice($result->get_error_message(), 'error');
                    } elseif ($result) {
                        todo_admin_notice('快递公司更新成功', 'success');
                    } else {
                        todo_admin_notice('快递公司更新失败', 'error');
                    }
                } else {
                    todo_admin_notice('参数错误', 'error');
                }
                break;
                
            case 'delete_company':
                $id = intval($_POST['company_id'] ?? 0);
                if ($id) {
                    $result = todo_delete_express_company($id);
                    if ($result) {
                        todo_admin_notice('快递公司删除成功', 'success');
                    } else {
                        todo_admin_notice('快递公司删除失败', 'error');
                    }
                }
                break;
                
            case 'batch_action':
                $batch_action = $_POST['batch_action'] ?? '';
                $company_ids = $_POST['company_ids'] ?? [];
                
                if (!empty($company_ids) && !empty($batch_action)) {
                    $success_count = 0;
                    foreach ($company_ids as $id) {
                        $id = intval($id);
                        if ($id) {
                            switch ($batch_action) {
                                case 'enable':
                                    if (todo_update_express_company_status($id, 1)) {
                                        $success_count++;
                                    }
                                    break;
                                case 'disable':
                                    if (todo_update_express_company_status($id, 0)) {
                                        $success_count++;
                                    }
                                    break;
                                case 'delete':
                                    if (todo_delete_express_company($id)) {
                                        $success_count++;
                                    }
                                    break;
                            }
                        }
                    }
                    todo_admin_notice("批量操作完成，成功处理 {$success_count} 个快递公司", 'success');
                }
                break;
        }
    }
}

// 获取快递公司列表
$companies = todo_get_express_companies();

// 页面标题
$page_title = '快递公司管理';
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title"><?php echo $page_title; ?></h2>
        <div class="admin-page-actions">
            <a href="javascript:void(0);" class="button button-primary" onclick="showAddModal(); return false;">添加快递公司</a>
        </div>
    </div>
    
    <div class="admin-card">
        <div class="admin-card-header">
            <h3 class="admin-card-title">快递公司列表</h3>
        </div>
        <div class="admin-card-content">
            <form method="POST" id="companiesForm" action="<?php echo todo_admin_url('index.php?page=todo-express'); ?>">
                <?php echo todo_csrf_token_field('express_companies'); ?>
                <input type="hidden" name="action" value="batch_action">
                
                <div class="bulk-actions-bar">
                    <div class="bulk-actions">
                        <select name="batch_action" id="bulk-action-selector-top">
                            <option value="">批量操作</option>
                            <option value="enable">启用</option>
                            <option value="disable">禁用</option>
                            <option value="delete">删除</option>
                        </select>
                        <button type="submit" class="button button-secondary" onclick="return confirmBatchAction()">应用</button>
                    </div>
                </div>
                
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="check-column">
                                <input type="checkbox" id="cb-select-all">
                            </th>
                            <th style="min-width: 200px;">公司名称</th>
                            <th>公司代码</th>
                            <th>官网</th>
                            <th style="width: 160px; min-width: 160px;">客服电话</th>
                            <th style="min-width: 60px;">状态</th>
                            <th style="width: 160px; min-width: 160px;">创建时间</th>
                        </tr>
                    </thead>
            <tbody>
                <?php if (!empty($companies)): ?>
                    <?php foreach ($companies as $company): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="company_ids[]" value="<?php echo $company['id']; ?>">
                            </td>
                            <td>
                                <strong><?php echo esc_html($company['name']); ?></strong>
                                <div class="row-actions">
                                    <a href="javascript:void(0);" onclick="editCompany(<?php echo htmlspecialchars(json_encode($company)); ?>)">
                                        编辑
                                    </a>|
                                    <a href="javascript:void(0);" class="submitdelete" onclick="deleteCompany(<?php echo $company['id']; ?>, '<?php echo esc_js($company['name']); ?>')">
                                        删除
                                    </a>
                                </div>
                            </td>
                            <td><?php echo esc_html($company['code']); ?></td>
                            <td>
                                <?php if (!empty($company['website'])): ?>
                                    <a href="<?php echo esc_url($company['website']); ?>" target="_blank">
                                        <?php echo esc_html($company['website']); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">未设置</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($company['phone'] ?: '未设置'); ?></td>
                            <td>
                                <?php if ($company['status']): ?>
                                    <span class="status-active">启用</span>
                                <?php else: ?>
                                    <span class="status-inactive">禁用</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="tb-meta"><?php echo esc_html($company['created_at']); ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="empty">
                        <td colspan="7">
                            <div class="no-items">
                                <p>暂无快递公司。</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
    </div>
</div>

<!-- 添加/编辑快递公司模态框 -->
<template id="todo-tpl-company-modal">
    <div class="todo-modal-body-content">
        <form method="POST" id="companyForm" action="<?php echo todo_admin_url('index.php?page=todo-express'); ?>">
            <?php echo todo_csrf_token_field('express_companies'); ?>
            <input type="hidden" name="action" id="companyAction" value="add_company">
            <input type="hidden" name="company_id" id="companyId" value="">
            
            <div class="form-group">
                <label for="companyName">公司名称 <span class="required">*</span></label>
                <input type="text" class="form-control" id="companyName" name="company_name" required>
            </div>
            
            <div class="form-group">
                <label for="companyCode">公司代码 <span class="required">*</span></label>
                <input type="text" class="form-control" id="companyCode" name="company_code" required>
                <p class="description">用于快递查询接口的公司标识</p>
            </div>
            
            <div class="form-group">
                <label for="companyWebsite">官网地址</label>
                <input type="url" class="form-control" id="companyWebsite" name="company_website" placeholder="https://">
            </div>
            
            <div class="form-group">
                <label for="companyPhone">客服电话</label>
                <input type="text" class="form-control" id="companyPhone" name="company_phone">
            </div>
            
            <div class="form-group">
                <label for="companyStatus">状态</label>
                <select class="form-control" id="companyStatus" name="company_status">
                    <option value="1">启用</option>
                    <option value="0">禁用</option>
                </select>
            </div>
        </form>
    </div>
</template>

<script type="text/javascript">
/**
 * 显示添加模态框
 */
function showAddModal() {
    TodoModal.form({
        templateId: 'todo-tpl-company-modal',
        title: '添加快递公司',
        submitText: '保存',
        onOpen: function(modal) {
            var form = modal.element.querySelector('form');
            form.reset();
            modal.element.querySelector('#companyAction').value = 'add_company';
            modal.element.querySelector('#companyId').value = '';
        },
        onSubmit: function(formData, form) {
            if (form && typeof form.checkValidity === 'function' && !form.checkValidity()) {
                if (typeof form.reportValidity === 'function') {
                    form.reportValidity();
                }
                return false;
            }
            return submitCompanyForm(form);
        }
    });
}

/**
 * 编辑快递公司
 */
function editCompany(company) {
    TodoModal.form({
        templateId: 'todo-tpl-company-modal',
        title: '编辑快递公司',
        submitText: '保存',
        onOpen: function(modal) {
            var form = modal.element.querySelector('form');
            modal.element.querySelector('#companyAction').value = 'update_company';
            modal.element.querySelector('#companyId').value = company.id;
            modal.element.querySelector('#companyName').value = company.name;
            modal.element.querySelector('#companyCode').value = company.code;
            modal.element.querySelector('#companyWebsite').value = company.website || '';
            modal.element.querySelector('#companyPhone').value = company.phone || '';
            modal.element.querySelector('#companyStatus').value = company.status;
        },
        onSubmit: function(formData, form) {
            if (form && typeof form.checkValidity === 'function' && !form.checkValidity()) {
                if (typeof form.reportValidity === 'function') {
                    form.reportValidity();
                }
                return false;
            }
            return submitCompanyForm(form);
        }
    });
}

function submitCompanyForm(form) {
    if (!form) {
        return false;
    }
    var targetIframe = null;
    if (window.parent && window.parent.document) {
        targetIframe = window.parent.document.getElementById('todo-admin-iframe');
    }
    if (!targetIframe) {
        targetIframe = document.getElementById('todo-admin-iframe');
    }
    if (targetIframe) {
        var iframeName = targetIframe.getAttribute('name') || 'todo-admin-iframe';
        form.setAttribute('target', iframeName);
        var action = form.getAttribute('action') || window.location.href;
        var iframeSrc = targetIframe.getAttribute('src') || '';
        if (iframeSrc) {
            action = iframeSrc;
        }
        if (action.indexOf('format=iframe') === -1) {
            action += (action.indexOf('?') === -1 ? '?' : '&') + 'format=iframe';
        }
        form.setAttribute('action', action);
    }
    if (window.HTMLFormElement && window.HTMLFormElement.prototype && typeof window.HTMLFormElement.prototype.submit === 'function') {
        window.HTMLFormElement.prototype.submit.call(form);
    } else {
        form.submit();
    }
    return true;
}

/**
 * 删除快递公司
 */
function deleteCompany(id, name) {
    TodoModal.confirm({
        title: '确认删除',
        message: '确定要删除快递公司 "' + name + '" 吗？',
        onConfirm: function() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '<?php echo todo_admin_url('index.php?page=todo-express'); ?>';
            form.innerHTML = `
                <?php echo todo_csrf_token_field('express_companies'); ?>
                <input type="hidden" name="action" value="delete_company">
                <input type="hidden" name="company_id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    });
}

/**
 * 确认批量操作
 */
function confirmBatchAction() {
    const action = document.getElementById('bulk-action-selector-top').value;
    const checkedBoxes = document.querySelectorAll('input[name="company_ids[]"]:checked');
    
    if (!action) {
        TodoCMS.Utils.showMessage('请选择要执行的操作', 'warning');
        return false;
    }
    
    if (checkedBoxes.length === 0) {
        TodoCMS.Utils.showMessage('请选择要操作的快递公司', 'warning');
        return false;
    }
    
    let message = '';
    switch (action) {
        case 'enable':
            message = `确定要启用选中的 ${checkedBoxes.length} 个快递公司吗？`;
            break;
        case 'disable':
            message = `确定要禁用选中的 ${checkedBoxes.length} 个快递公司吗？`;
            break;
        case 'delete':
            message = `确定要删除选中的 ${checkedBoxes.length} 个快递公司吗？`;
            break;
    }
    
    TodoModal.confirm({
        message: message,
        onConfirm: function() {
            document.getElementById('companiesForm').submit();
        }
    });
    return false;
}

// 全选/取消全选
document.getElementById('cb-select-all').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('input[name="company_ids[]"]');
    checkboxes.forEach(checkbox => {
        checkbox.checked = this.checked;
    });
});
</script>

<style>
.text-danger {
    color: #dc3545;
}
</style>

<?php
/**
 * 快递公司相关函数
 */

/**
 * 获取快递公司列表
 */
function todo_get_express_companies($status = null) {
    global $tododb;
    
    $table_name = $tododb->prefix . 'shipping_companies';
    
    $sql = "SELECT * FROM {$table_name}";
    
    if ($status !== null) {
        $sql .= " WHERE status = ?";
        return $tododb->get_results($sql, [$status], 'ARRAY_A');
    }
    
    $sql .= " ORDER BY created_at DESC";
    
    return $tododb->get_results($sql, ARRAY_A);
}

/**
 * 添加快递公司
 */
function todo_add_express_company($name, $code, $website = '', $phone = '', $status = 1) {
    global $tododb;
    
    $table_name = $tododb->prefix . 'shipping_companies';
    
    // 检查快递公司代码是否已存在
    $existing = $tododb->get_var(
        "SELECT COUNT(*) FROM {$table_name} WHERE code = ?",
        [$code]
    );
    
    if ($existing > 0) {
        return new TODO_Error('duplicate_code', '快递公司代码已存在');
    }
    
    return $tododb->insert(
        $table_name,
        [
            'name' => $name,
            'code' => $code,
            'website' => $website,
            'phone' => $phone,
            'status' => $status,
            'created_at' => current_time('mysql')
        ]
    );
}

/**
 * 更新快递公司
 */
function todo_update_express_company($id, $name, $code, $website = '', $phone = '', $status = 1) {
    global $tododb;
    
    $table_name = $tododb->prefix . 'shipping_companies';
    
    // 检查快递公司代码是否已被其他记录使用
    $existing = $tododb->get_var(
        "SELECT COUNT(*) FROM {$table_name} WHERE code = ? AND id != ?",
        [$code, $id]
    );
    
    if ($existing > 0) {
        return new TODO_Error('duplicate_code', '快递公司代码已存在');
    }
    
    return $tododb->update(
        $table_name,
        [
            'name' => $name,
            'code' => $code,
            'website' => $website,
            'phone' => $phone,
            'status' => $status,
            'updated_at' => current_time('mysql')
        ],
        ['id' => $id]
    );
}

/**
 * 更新快递公司状态
 */
function todo_update_express_company_status($id, $status) {
    global $tododb;
    
    $table_name = $tododb->prefix . 'shipping_companies';
    
    return $tododb->update(
        $table_name,
        [
            'status' => $status,
            'updated_at' => current_time('mysql')
        ],
        ['id' => $id]
    );
}

/**
 * 删除快递公司
 */
function todo_delete_express_company($id) {
    global $tododb;
    
    $table_name = $tododb->prefix . 'shipping_companies';
    
    return $tododb->delete(
        $table_name,
        ['id' => $id]
    );
}
?>
