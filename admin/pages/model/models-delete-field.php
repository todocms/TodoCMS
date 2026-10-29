<?php
// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
/**
 * 模型字段删除页面
 *
 * 提供删除模型字段的功能
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 检查用户权限
if (!current_user_can('edit_models') && !current_user_can('manage_models')) {
    todo_die('您没有足够的权限访问此页面。');
}

$page_title = '删除字段';

// 获取模型ID和字段ID
$model_id = isset($_GET['model_id']) ? intval($_GET['model_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
$field_id = isset($_GET['field_id']) ? intval($_GET['field_id']) : 0;

if ($model_id <= 0 || $field_id <= 0) {
    echo '<div class="notice notice-error"><p>无效的模型ID或字段ID</p></div>';
    return;
}

// 获取模型信息
$model = new CMS_Model($model_id);
$model_data = $model->get_data();

if (empty($model_data)) {
    echo '<div class="notice notice-error"><p>模型不存在</p></div>';
    return;
}

// 获取字段信息
$field_data = $model->get_field($field_id);

if (empty($field_data)) {
    echo '<div class="notice notice-error"><p>字段不存在</p></div>';
    return;
}

// 如果是系统字段，不允许删除
if (isset($field_data['is_system']) && $field_data['is_system']) {
    echo '<div class="notice notice-error"><p>系统字段不能删除</p></div>';
    return;
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_field_confirm') {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('delete_field', '_todononce')) {
        todo_admin_notice('安全验证失败，请重试。', 'error');
    } else {
        $result = $model->delete_field($field_id);
        
        if ($result) {
            todo_admin_notice('字段删除成功', 'success');
            todo_redirect(todo_admin_url('index.php?page=model-fields&model_id=' . $model_id));
            exit;
        } else {
            todo_admin_notice('删除字段失败', 'error');
        }
    }
}

?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">删除字段</h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=model-fields&model_id=' . $model_id); ?>" class="button button-secondary">返回字段列表</a>
        </div>
    </div>
    

    
    <div class="notice notice-warning">
        <p><strong>警告：</strong> 您即将删除字段 "<?php echo esc_html($field_data['field_name']); ?>"。将会删除所有与此字段相关的数据。</p>
    </div>
    
    <form method="post" action="<?php echo todo_admin_url('index.php?page=models&action=delete_field&model_id=' . $model_id . '&field_id=' . $field_id); ?>" class="admin-form todo-request">
        <?php echo Todo_Security_Manager::csrf_field('delete_field'); ?>
        <input type="hidden" name="action" value="delete_field_confirm">
        
        <div class="form-field">
            <label>模型名称</label>
            <input type="text" value="<?php echo esc_attr($model_data['model_name'] ?? ''); ?>" readonly>
        </div>
        
        <div class="form-field">
            <label>字段名称</label>
            <input type="text" value="<?php echo esc_attr($field_data['field_name'] ?? ''); ?>" readonly>
        </div>
        
        <div class="form-field">
            <label>字段标识</label>
            <input type="text" value="<?php echo esc_attr($field_data['field_key'] ?? ''); ?>" readonly>
        </div>
        
        <div class="form-field">
            <label>字段类型</label>
            <input type="text" value="<?php echo esc_attr($field_data['field_type'] ?? ''); ?>" readonly>
        </div>
        
        <p class="submit">
            <a href="<?php echo todo_admin_url('index.php?page=model-fields&model_id=' . $model_id); ?>" class="button">取消</a>
            <input type="submit" name="submit" id="submit" class="button button-primary" value="确认删除">
        </p>
    </form>
</div>