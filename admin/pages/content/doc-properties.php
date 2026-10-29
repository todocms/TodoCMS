<?php
// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
/**
 * 文档属性管理页面
 *
 * @package TodoCMS
 * @subpackage Administration
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 定义数据库查询常量
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// 检查用户权限
if (!current_user_can('manage_categories') && !current_user_can('manage_options')) {
    todo_die('您没有权限访问此页面。');
}

// 定义管理路径常量
if (!defined('TODO_ADMIN_PATH')) {
    define('TODO_ADMIN_PATH', dirname(dirname(__DIR__)));
}

// 包含文档属性函数文件
require_once TODO_ADMIN_PATH . '/includes/doc-properties-functions.php';

// 记录访问日志
if (function_exists('todo_log_info')) {
    todo_log_info('文档属性页面访问', array(
        'user_id' => get_current_user_id(),
        'ip' => Todo_Security_Manager::get_client_ip(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'edit_id' => isset($_GET['edit']) ? intval($_GET['edit']) : null
    ));
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 验证CSRF token
    if (!Todo_Security_Manager::verify_request_csrf('doc_properties_action')) {
        todo_die('安全验证失败，请重新提交。');
    }
    
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_property':
                // 验证输入数据
                $name = sanitize_text_field($_POST['property_name'] ?? '');
                $value = sanitize_text_field($_POST['property_value'] ?? '');
                $display = isset($_POST['display']) ? 1 : 0;
                $sort_order = intval($_POST['sort_order'] ?? 0);
                
                // 验证必填字段和格式
                if (empty($name)) {
                    $error_message = '属性名称不能为空！';
                } elseif (strlen($name) > 50) {
                    $error_message = '属性名称不能超过50个字符！';
                } elseif (empty($value)) {
                    $error_message = '属性值不能为空！';
                } elseif (strlen($value) > 50) {
                    $error_message = '属性值不能超过50个字符！';
                } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $value)) {
                    $error_message = '属性值只能包含字母、数字、下划线和连字符！';
                } elseif (preg_match('/^[0-9]/', $value)) {
                    $error_message = '属性值不能以数字开头！';
                } elseif ($sort_order < 0 || $sort_order > 9999) {
                    $error_message = '排序值必须在0-9999之间！';
                } else {
                    $result = add_doc_property($name, $value, $display, $sort_order);
                    if ($result) {
                        $success_message = '文档属性添加成功！';
                        // 记录操作日志
                        if (function_exists('todo_log_info')) {
                            todo_log_info('文档属性添加', array(
                                'user_id' => get_current_user_id(),
                                'property_name' => $name,
                                'property_value' => $value,
                                'ip' => Todo_Security_Manager::get_client_ip()
                            ));
                        }
                    } else {
                        $error_message = '文档属性添加失败！可能属性值已存在。';
                        // 记录失败日志
                        if (function_exists('todo_log_warning')) {
                            todo_log_warning('文档属性添加失败', array(
                                'user_id' => get_current_user_id(),
                                'property_name' => $name,
                                'property_value' => $value,
                                'ip' => Todo_Security_Manager::get_client_ip()
                            ));
                        }
                    }
                }
                break;
                
            case 'update_property':
                // 验证输入数据
                $id = intval($_POST['property_id'] ?? 0);
                $name = sanitize_text_field($_POST['property_name'] ?? '');
                $value = sanitize_text_field($_POST['property_value'] ?? '');
                $display = isset($_POST['display']) ? 1 : 0;
                $sort_order = intval($_POST['sort_order'] ?? 0);
                
                // 验证必填字段和格式
                if ($id <= 0) {
                    $error_message = '无效的属性ID！';
                } elseif (empty($name)) {
                    $error_message = '属性名称不能为空！';
                } elseif (strlen($name) > 50) {
                    $error_message = '属性名称不能超过50个字符！';
                } elseif (empty($value)) {
                    $error_message = '属性值不能为空！';
                } elseif (strlen($value) > 50) {
                    $error_message = '属性值不能超过50个字符！';
                } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $value)) {
                    $error_message = '属性值只能包含字母、数字、下划线和连字符！';
                } elseif (preg_match('/^[0-9]/', $value)) {
                    $error_message = '属性值不能以数字开头！';
                } elseif ($sort_order < 0 || $sort_order > 9999) {
                    $error_message = '排序值必须在0-9999之间！';
                } else {
                    $result = update_doc_property($id, $name, $value, $display, $sort_order);
                    if ($result) {
                        $success_message = '文档属性更新成功！';
                        // 记录操作日志
                        if (function_exists('todo_log_info')) {
                            todo_log_info('文档属性更新', array(
                                'user_id' => get_current_user_id(),
                                'property_id' => $id,
                                'property_name' => $name,
                                'property_value' => $value,
                                'ip' => Todo_Security_Manager::get_client_ip()
                            ));
                        }
                    } else {
                        $error_message = '文档属性更新失败！可能属性值已被其他记录使用。';
                        // 记录失败日志
                        if (function_exists('todo_log_warning')) {
                            todo_log_warning('文档属性更新失败', array(
                                'user_id' => get_current_user_id(),
                                'property_id' => $id,
                                'property_name' => $name,
                                'property_value' => $value,
                                'ip' => Todo_Security_Manager::get_client_ip()
                            ));
                        }
                    }
                }
                break;
                
            case 'delete_property':
                $id = intval($_POST['property_id'] ?? 0);
                if ($id <= 0) {
                    $error_message = '无效的属性ID！';
                } elseif ($id <= 9) {
                    $error_message = '预设属性不能删除！';
                } else {
                    // 获取要删除的属性信息用于日志
                    $property_to_delete = get_doc_property($id);
                    $result = delete_doc_property($id);
                    if ($result) {
                        $success_message = '文档属性删除成功！';
                        // 记录操作日志
                        if (function_exists('todo_log_info') && $property_to_delete) {
                            todo_log_info('文档属性删除', array(
                                'user_id' => get_current_user_id(),
                                'property_id' => $id,
                                'property_name' => $property_to_delete['name'],
                                'property_value' => $property_to_delete['value'],
                                'ip' => Todo_Security_Manager::get_client_ip()
                            ));
                        }
                    } else {
                        $error_message = '文档属性删除失败！';
                        // 记录失败日志
                        if (function_exists('todo_log_warning')) {
                            todo_log_warning('文档属性删除失败', array(
                                'user_id' => get_current_user_id(),
                                'property_id' => $id,
                                'ip' => Todo_Security_Manager::get_client_ip()
                            ));
                        }
                    }
                }
                break;
                
            default:
                $error_message = '无效的操作！';
                break;
        }
    } else {
        $error_message = '缺少操作参数！';
    }
}

// 获取所有文档属性及关联文章数
$properties = get_doc_properties();
$doc_prop_counts = function_exists('get_doc_properties_post_counts') ? get_doc_properties_post_counts() : array();

// 获取编辑的属性
$edit_property = null;
if (isset($_GET['edit']) && intval($_GET['edit']) > 0) {
    $edit_id = intval($_GET['edit']);
    $edit_property = get_doc_property($edit_id);
}

?>

<div class="wrap">
    <?php if (isset($success_message)): ?>
        <div class="success-message">
            <strong>成功！</strong> <?php echo esc_html($success_message); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
        <div class="error-message">
            <strong>错误！</strong> <?php echo esc_html($error_message); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['message'])): ?>
        <div class="success-message">
            <strong>成功！</strong> <?php echo esc_html($_GET['message']); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['error'])): ?>
        <div class="error-message">
            <strong>错误！</strong> <?php echo esc_html($_GET['error']); ?>
        </div>
    <?php endif; ?>
    
    <div class="admin-row">
        <!-- 添加/编辑表单 -->
        <div class="admin-col-l" style="width: 260px;">
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">
                        <?php 
                        if ($edit_property) {
                            echo ($edit_property['id'] <= 9) ? '设置预设属性' : '编辑文档属性';
                        } else {
                            echo '添加文档属性';
                        }
                        ?>
                    </h3>
                </div>
                <div class="admin-card-content">
                    <form method="post" action="<?php echo todo_admin_url('index.php?page=doc-properties'); ?>" class="todo-request">
                        <?php 
                        // 添加CSRF保护
                        echo Todo_Security_Manager::csrf_field('doc_properties_action');
                        ?>
                        <?php if ($edit_property): ?>
                            <input type="hidden" name="action" value="update_property">
                            <input type="hidden" name="property_id" value="<?php echo $edit_property['id']; ?>">
                            <?php if ($edit_property['id'] <= 9): // 预设属性，隐藏名称和值字段 ?>
                                <input type="hidden" name="property_name" value="<?php echo esc_attr($edit_property['name']); ?>">
                                <input type="hidden" name="property_value" value="<?php echo esc_attr($edit_property['value']); ?>">
                                
                                <div class="alert alert-info">
                                    <strong><?php echo esc_html($edit_property['name']); ?></strong> - 预设属性<br>(<?php echo esc_html($edit_property['value']); ?>)<br>
                                    <small>预设属性的名称和值不可修改，只能调整排序和显示状态。</small>
                                </div>
                            <?php else: // 自定义属性，显示所有字段 ?>
                                <div class="form-group">
                                    <label for="property_name">属性名称 <span class="required">*</span></label>
                                    <input type="text" id="property_name" name="property_name" 
                                            value="<?php echo esc_attr($edit_property['name']); ?>" 
                                            placeholder="如：头条、推荐等" 
                                            maxlength="50" 
                                            required>
                                    <p class="description">最多50个字符</p>
                                </div>
                                
                                <div class="form-group">
                                    <label for="property_value">属性值 <span class="required">*</span></label>
                                    <input type="text" id="property_value" name="property_value" 
                                            value="<?php echo esc_attr($edit_property['value']); ?>" 
                                            placeholder="如：headline、recommend等" 
                                            pattern="^[a-zA-Z][a-zA-Z0-9_\-]*$" 
                                            maxlength="50" 
                                            required>
                                    <p class="description">用于程序识别的唯一标识，只能包含字母、数字、下划线和连字符，不能以数字开头，最多50个字符</p>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <input type="hidden" name="action" value="add_property">
                            
                            <div class="form-group">
                                <label for="property_name">属性名称 <span class="required">*</span></label>
                                <input type="text" id="property_name" name="property_name" 
                                        value="" placeholder="如：头条、推荐等" 
                                        maxlength="50" 
                                        required>
                                <p class="description">最多50个字符</p>
                            </div>
                            
                            <div class="form-group">
                                <label for="property_value">属性值 <span class="required">*</span></label>
                                <input type="text" id="property_value" name="property_value" 
                                        value="" placeholder="如：headline、recommend等" 
                                        pattern="^[a-zA-Z][a-zA-Z0-9_\-]*$" 
                                        maxlength="50" 
                                        required>
                                <p class="description">用于程序识别的唯一标识，只能包含字母、数字、下划线和连字符，不能以数字开头，最多50个字符</p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label for="sort_order">排序</label>
                            <input type="number" id="sort_order" name="sort_order" 
                                    value="<?php echo $edit_property ? $edit_property['sort_order'] : 0; ?>" 
                                    min="0" max="9999" step="1">
                            <p class="description">数字越小排序越靠前，0-9999</p>
                        </div>
                        
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="display" value="1" 
                                        <?php echo (!$edit_property || $edit_property['is_active']) ? 'checked' : ''; ?>>
                                前台显示
                            </label>
                        </div>
                        
                        <div class="form-submit">
                            <button type="submit" class="button button-primary">
                                <?php echo $edit_property ? '更新属性' : '添加属性'; ?>
                            </button>
                            <?php if ($edit_property): ?>
                                <a href="<?php echo todo_admin_url('index.php?page=doc-properties'); ?>" class="button button-secondary">取消</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- 属性列表 -->
        <div class="admin-col-r">
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">文档属性列表</h3>
                </div>
                
                <div class="admin-card-content">
                    <?php if (empty($properties)): ?>
                        <div class="empty-state">
                            <p>暂无文档属性，请添加第一个属性。</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th style="min-width: 90px;">属性名称</th>
                                        <th style="min-width: 90px;">属性值</th>
                                        <th style="min-width: 60px;">文章数</th>
                                        <th style="min-width: 80px;width: 80px;">显示</th>
                                        <th style="min-width: 80px;width: 80px;">排序</th>
                                        <th style="min-width: 110px;width: 110px;">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($properties as $property): 
                                        // 确保 $property 是数组格式
                                        if (is_object($property)) {
                                            $property = (array) $property;
                                        }
                                        $pid = intval($property['id']);
                                        $pcount = intval($doc_prop_counts[$pid] ?? 0);
                                    ?>
                                        <tr>
                                            <td><?php echo esc_html($property['name']); ?></td>
                                            <td><code><?php echo esc_html($property['value']); ?></code></td>
                                            <td class="posts column-posts">
                                                <a href="<?php echo todo_admin_url('index.php?page=posts&doc_properties=' . $pid); ?>"><?php echo $pcount; ?></a>
                                            </td>
                                            <td>
                                                <?php if ($property['is_active']): ?>
                                                    <span class="status-active">显示</span>
                                                <?php else: ?>
                                                    <span class="status-inactive">隐藏</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $property['sort_order']; ?></td>
                                            <td>
                                                <div class="row-actions">
                                                <?php if ($property['id'] <= 9): // 预设属性只能修改排序和显示 ?>
                                                    <a href="<?php echo todo_admin_url('index.php?page=doc-properties&edit=' . $property['id']); ?>">设置</a>
                                                    <em class="tb-meta">预设属性</em>
                                                <?php else: // 自定义属性可以完全编辑和删除 ?>
                                                    <a href="<?php echo todo_admin_url('index.php?page=doc-properties&edit=' . $property['id']); ?>">编辑</a> |
                                                    <form method="post" style="display: inline-block;" 
                                                            onsubmit="return TodoCMS.Utils.confirmAction(this, '确定要删除这个文档属性吗？')">
                                                        <?php echo Todo_Security_Manager::csrf_field('doc_properties_action'); ?>
                                                        <input type="hidden" name="action" value="delete_property">
                                                        <input type="hidden" name="property_id" value="<?php echo $property['id']; ?>">
                                                        <button type="submit" class="btn-normal btn-error">删除</button>
                                                    </form>
                                                <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- 预设属性说明 -->
            <div class="admin-card" style="margin-top: 20px;">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">预设属性说明</h3>
                </div>
                
                <div class="admin-card-content">
                    <p>系统预设了以下常用文档属性，您可以根据需要添加：</p>
                    <ul class="property-list">
                        <li><strong>头条</strong> (headline) - 标记为头条文章</li>
                        <li><strong>推荐</strong> (recommend) - 推荐文章</li>
                        <li><strong>加推</strong> (featured) - 特别推荐</li>
                        <li><strong>标粗</strong> (bold) - 标题加粗显示</li>
                        <li><strong>有图</strong> (with_image) - 包含图片的文章</li>
                        <li><strong>外链</strong> (external_link) - 外部链接文章</li>
                        <li><strong>轮播</strong> (carousel) - 轮播展示</li>
                        <li><strong>滚动</strong> (scroll) - 滚动展示</li>
                        <li><strong>AI内容</strong> (ai_content) - AI 生成的内容</li>
                    </ul>
                    <p class="description">您也可以根据网站需要自定义其他属性。</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

.checkbox-label input[type="checkbox"] {
    margin: 0;
}

/* 错误提示样式 */
.error-input {
    border-color: #dc3545 !important;
    background-color: #fff5f5 !important;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
}

.success-input {
    border-color: #28a745 !important;
    background-color: #f8fff9 !important;
    box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25) !important;
}

/* 提交按钮禁用状态 */
.btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* 加载动画 */
.loading-spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid #f3f3f3;
    border-top: 2px solid #007cba;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-right: 5px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* 成功提示 */
.success-message {
    background-color: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
    padding: 0.75rem 1.25rem;
    margin-bottom: 1rem;
    border-radius: 0.375rem;
    position: relative;
}

.error-message {
    background-color: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
    padding: 0.75rem 1.25rem;
    margin-bottom: 1rem;
    border-radius: 0.375rem;
    position: relative;
}

.table-responsive {
    overflow-x: auto;
}

.actions {
    white-space: nowrap;
}

.actions .btn {
    margin-right: 5px;
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #666;
}

.property-list {
    margin: 15px 0;
    padding-left: 20px;
}

.property-list li {
    margin-bottom: 8px;
}

.alert {
    padding: 12px 16px;
    margin-bottom: 20px;
    border: 1px solid transparent;
    border-radius: 4px;
}

.alert-info {
    color: #0c5460;
    background-color: #d1ecf1;
    border-color: #bee5eb;
}

.alert strong {
    font-weight: 500;
}

.alert small {
    display: block;
    margin-top: 5px;
    opacity: 0.8;
}
</style>

<script type="text/javascript">
// 表单验证和防重复提交
document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('form');
    let isSubmitting = false;

    if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils && TodoCMS.Utils.initSlugGenerator) {
        TodoCMS.Utils.initSlugGenerator({
            nameInputId: 'property_name',
            slugInputId: 'property_value',
            ajaxAction: 'slug_generator',
            id: <?php echo $edit_property ? intval($edit_property['id']) : 0; ?>,
            nameParam: 'name',
            idParam: 'id',
            extraParams: {
                type: 'doc_property'
            }
        });
    }
    
    forms.forEach(function(form) {
        form.addEventListener('submit', function(e) {
            // 防重复提交
            if (isSubmitting) {
                e.preventDefault();
                return;
            }
            
            const propertyName = form.querySelector('input[name="property_name"]');
            const propertyValue = form.querySelector('input[name="property_value"]');
            const sortOrder = form.querySelector('input[name="sort_order"]');
            const submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
            
            if (propertyName) {
                // 验证属性名称长度
                if (propertyName.value.trim().length === 0) {
                    TodoCMS.Utils.showMessage('属性名称不能为空', 'warning');
                    propertyName.focus();
                    e.preventDefault();
                    return;
                }
                
                if (propertyName.value.length > 50) {
                    TodoCMS.Utils.showMessage('属性名称不能超过50个字符', 'warning');
                    propertyName.focus();
                    e.preventDefault();
                    return;
                }
            }
            
            if (propertyValue) {
                // 验证属性值格式和长度
                if (propertyValue.value.trim().length === 0) {
                    TodoCMS.Utils.showMessage('属性值不能为空', 'warning');
                    propertyValue.focus();
                    e.preventDefault();
                    return;
                }
                
                if (propertyValue.value.length > 50) {
                    TodoCMS.Utils.showMessage('属性值不能超过50个字符', 'warning');
                    propertyValue.focus();
                    e.preventDefault();
                    return;
                }
                
                // 验证属性值格式：不能以数字开头，只能包含字母、数字、下划线和连字符
                const pattern = /^[a-zA-Z][a-zA-Z0-9_-]*$/;
                if (!pattern.test(propertyValue.value)) {
                    TodoCMS.Utils.showMessage('属性值格式不正确！要求：只能包含字母、数字、下划线和连字符，不能以数字开头。', 'warning');
                    propertyValue.focus();
                    e.preventDefault();
                    return;
                }
            }
            
            if (sortOrder) {
                // 验证排序值范围
                const sortValue = parseInt(sortOrder.value);
                if (isNaN(sortValue) || sortValue < 0 || sortValue > 9999) {
                    TodoCMS.Utils.showMessage('排序值必须在0-9999之间', 'warning');
                    sortOrder.focus();
                    e.preventDefault();
                    return;
                }
            }
            
            // 验证通过，设置提交状态
             isSubmitting = true;
             if (submitBtn) {
                 submitBtn.disabled = true;
                 const originalText = submitBtn.textContent || submitBtn.value;
                 const spinner = '<span class="loading-spinner"></span>';
                 
                 if (submitBtn.tagName === 'BUTTON') {
                     submitBtn.innerHTML = spinner + '提交中...';
                 } else {
                     submitBtn.value = '提交中...';
                 }
                 
                 // 5秒后重置按钮状态（防止网络问题导致按钮永久禁用）
                 setTimeout(function() {
                     isSubmitting = false;
                     submitBtn.disabled = false;
                     if (submitBtn.tagName === 'BUTTON') {
                         submitBtn.innerHTML = originalText;
                     } else {
                         submitBtn.value = originalText;
                     }
                 }, 5000);
             }
        });
    });
    
    // 实时验证属性值格式
    const propertyValueInputs = document.querySelectorAll('input[name="property_value"]');
    propertyValueInputs.forEach(function(input) {
        input.addEventListener('input', function() {
            const pattern = /^[a-zA-Z][a-zA-Z0-9_-]*$/;
            if (this.value && !pattern.test(this.value)) {
                this.classList.add('error-input');
                this.classList.remove('success-input');
            } else if (this.value) {
                this.classList.add('success-input');
                this.classList.remove('error-input');
            } else {
                this.classList.remove('error-input', 'success-input');
            }
        });
    });
    
    // 实时验证属性名称长度
    const propertyNameInputs = document.querySelectorAll('input[name="property_name"]');
    propertyNameInputs.forEach(function(input) {
        input.addEventListener('input', function() {
            if (this.value.length > 50) {
                this.classList.add('error-input');
                this.classList.remove('success-input');
            } else if (this.value.trim().length > 0) {
                this.classList.add('success-input');
                this.classList.remove('error-input');
            } else {
                this.classList.remove('error-input', 'success-input');
            }
        });
    });
    
    // 实时验证排序值
    const sortOrderInputs = document.querySelectorAll('input[name="sort_order"]');
    sortOrderInputs.forEach(function(input) {
        input.addEventListener('input', function() {
            const sortValue = parseInt(this.value);
            if (isNaN(sortValue) || sortValue < 0 || sortValue > 9999) {
                this.classList.add('error-input');
                this.classList.remove('success-input');
            } else {
                this.classList.add('success-input');
                this.classList.remove('error-input');
            }
        });     
     });
     
     // 自动隐藏成功和错误消息
     const messages = document.querySelectorAll('.success-message, .error-message');
     messages.forEach(function(message) {
         setTimeout(function() {
             message.style.opacity = '0';
             message.style.transition = 'opacity 0.5s ease-out';
             setTimeout(function() {
                 if (message.parentNode) {
                     message.parentNode.removeChild(message);
                 }
             }, 500);
         }, 5000); // 5秒后开始淡出
     });
     
     // 为消息添加关闭按钮
     messages.forEach(function(message) {
         const closeBtn = document.createElement('button');
         closeBtn.innerHTML = '×';
         closeBtn.style.cssText = 'position: absolute; top: 15px; right: 15px; background: none; border: none; font-size: 18px; cursor: pointer; color: inherit; opacity: 0.7;';
         closeBtn.addEventListener('click', function() {
             message.style.opacity = '0';
             message.style.transition = 'opacity 0.3s ease-out';
             setTimeout(function() {
                 if (message.parentNode) {
                     message.parentNode.removeChild(message);
                 }
             }, 300);
         });
         closeBtn.addEventListener('mouseenter', function() {
             this.style.opacity = '1';
         });
         closeBtn.addEventListener('mouseleave', function() {
             this.style.opacity = '0.7';
         });
         message.appendChild(closeBtn);
     });
 });
 </script>
