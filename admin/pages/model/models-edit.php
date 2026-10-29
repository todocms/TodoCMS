<?php
// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
/**
 * 模型编辑页面
 *
 * 提供编辑现有模型的功能
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

$page_title = '编辑模型';

// 获取模型ID
$model_id = isset($_GET['model_id']) ? intval($_GET['model_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

if ($model_id <= 0) {
    echo '<div class="notice notice-error"><p>无效的模型ID</p></div>';
    return;
}

$model = new CMS_Model($model_id);
$model_data = $model->get_data();

if (empty($model_data)) {
    echo '<div class="notice notice-error"><p>模型不存在</p></div>';
    return;
}

// 检查是否为系统默认模型
$is_default_model = isset($model_data['is_system']) && $model_data['is_system'] == 1;

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_model') {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('edit_model', '_todononce')) {
        todo_admin_notice('安全验证失败，请重试。', 'error');
    } else {
        $model_description = isset($_POST['model_description']) ? sanitize_textarea_field($_POST['model_description']) : '';
        $model_status = isset($_POST['model_status']) ? sanitize_text_field($_POST['model_status']) : 'active';
        $list_template = isset($_POST['list_template']) ? sanitize_text_field($_POST['list_template']) : '';
        $view_template = isset($_POST['view_template']) ? sanitize_text_field($_POST['view_template']) : '';

        if ($is_default_model) {
            // 系统默认模型只能修改描述、状态和模板
            $model->set_data([
                'model_description' => $model_description,
                'model_status' => $model_status,
                'list_template' => $list_template,
                'view_template' => $view_template
            ]);

            $result = $model->save();

            if ($result) {
                todo_add_admin_notice('模型更新成功', 'success');
                todo_redirect(todo_admin_url('index.php?page=models'));
                exit;
            } else {
                todo_admin_notice('模型更新失败', 'error');
            }
        } else {
            // 非系统模型可以修改所有字段
            $model_name = isset($_POST['model_name']) ? sanitize_text_field($_POST['model_name']) : '';
            $model_key = isset($_POST['model_key']) ? sanitize_key($_POST['model_key']) : '';

            if (empty($model_name) || empty($model_key)) {
                todo_admin_notice('模型名称和标识不能为空', 'error');
            } else {
                // 检查模型标识是否已存在（排除当前模型）
                $has_error = false;
                if ($model_key !== $model_data['model_key']) {
                    $temp_model = new CMS_Model();
                    $existing_model = $temp_model->get_by_key($model_key);

                    if (!empty($existing_model)) {
                        todo_admin_notice('模型标识已存在，请使用其他标识', 'error');
                        $has_error = true;
                    }
                }

                if (!$has_error) {
                    // 更新模型
                    $model->set_data([
                        'model_name' => $model_name,
                        'model_key' => $model_key,
                        'model_description' => $model_description,
                        'model_status' => $model_status,
                        'list_template' => $list_template,
                        'view_template' => $view_template
                    ]);

                    $result = $model->save();

                    if ($result) {
                        todo_add_admin_notice('模型更新成功', 'success');
                        todo_redirect(todo_admin_url('index.php?page=models'));
                        exit;
                    } else {
                        todo_admin_notice('模型更新失败', 'error');
                    }
                }
            }
        }
    }
}

?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">编辑模型</h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=models'); ?>" class="button button-secondary">返回模型列表</a>
        </div>
    </div>

    <?php if (isset($error_message)): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html($error_message); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <div class="admin-card">
        <div class="admin-card-header">
            <h3 class="admin-card-title">模型信息</h3>
        </div>
        <div class="admin-card-content">
            <form method="post" action="<?php echo todo_admin_url('index.php?page=models-edit&model_id=' . $model_id); ?>"
                class="admin-form todo-request">
                <?php echo Todo_Security_Manager::csrf_field('edit_model'); ?>
                <input type="hidden" name="action" value="edit_model">
                <input type="hidden" name="model_id" value="<?php echo $model_id; ?>">

                <?php if ($is_default_model): ?>
                    <div class="notice notice-info">
                        <p><strong>注意：</strong>这是系统默认模型，模型名称和标识不可修改，但可以修改描述和状态。</p>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="model_name">模型名称</label>
                    <input name="model_name" type="text" id="model_name" class="regular-text"
                        value="<?php echo esc_attr($model_data['model_name'] ?? ''); ?>" <?php echo $is_default_model ? 'disabled readonly' : 'required'; ?>>
                    <p class="description">模型的显示名称，如"文章"、"产品"等<?php echo $is_default_model ? '（系统默认模型不可修改）' : ''; ?></p>
                </div>

                <div class="form-group">
                    <label for="model_key">模型标识</label>
                    <input name="model_key" type="text" id="model_key" class="regular-text"
                        value="<?php echo esc_attr($model_data['model_key'] ?? ''); ?>" <?php echo $is_default_model ? 'disabled readonly' : 'required'; ?>>
                    <p class="description">
                        模型的唯一标识，仅允许小写字母、数字和下划线，如"post"、"product"等<?php echo $is_default_model ? '（系统默认模型不可修改）' : ''; ?>
                    </p>
                </div>

                <div class="form-group">
                    <label for="model_description">模型描述</label>
                    <textarea name="model_description" id="model_description" class="large-text"
                        rows="3"><?php echo esc_textarea($model_data['model_description'] ?? ''); ?></textarea>
                    <p class="description">模型的简要描述</p>
                </div>

                <div class="form-group">
                    <label for="model_status">状态</label>
                    <select name="model_status" id="model_status" class="regular-text">
                        <option value="active" <?php selected($model_data['model_status'] ?? '', 'active'); ?>>启用
                        </option>
                        <option value="inactive" <?php selected($model_data['model_status'] ?? '', 'inactive'); ?>>禁用
                        </option>
                    </select>
                    <p class="description">模型的状态</p>
                </div>

                <div class="form-group">
                    <label for="list_template">列表页模板</label>
                    <input name="list_template" type="text" id="list_template" class="regular-text"
                        value="<?php echo esc_attr($model_data['list_template'] ?? ''); ?>">
                    <p class="description">此模型的列表页模板文件名，如 lists_custom.html。留空则自动匹配 lists_{模型标识}.html</p>
                </div>

                <div class="form-group">
                    <label for="view_template">详情页模板</label>
                    <input name="view_template" type="text" id="view_template" class="regular-text"
                        value="<?php echo esc_attr($model_data['view_template'] ?? ''); ?>">
                    <p class="description">此模型的详情页模板文件名，如 view_custom.html。留空则自动匹配 view_{模型标识}.html</p>
                </div>

                <div class="form-submit">
                    <button type="submit" name="submit" id="submit" class="button button-primary">更新模型</button>
                </div>
            </form>
        </div>
    </div>
</div>