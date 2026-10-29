<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
/**
 * 模型添加页面
 *
 * 提供添加新模型的功能
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 检查用户权限
if (!current_user_can('manage_models') && !current_user_can('edit_models')) {
    todo_die('您没有足够的权限访问此页面。');
}

$page_title = '添加新模型';

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_model') {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('add_model', '_todononce')) {
        todo_admin_notice('安全验证失败，请重试。', 'error');
    } else {
        // 添加新模型
        $model_name = isset($_POST['model_name']) ? sanitize_text_field($_POST['model_name']) : '';
        $model_key = isset($_POST['model_key']) ? sanitize_key($_POST['model_key']) : '';
        $model_description = isset($_POST['model_description']) ? sanitize_textarea_field($_POST['model_description']) : '';
        $model_status = isset($_POST['model_status']) ? sanitize_text_field($_POST['model_status']) : 'active';
        $list_template = isset($_POST['list_template']) ? sanitize_text_field($_POST['list_template']) : '';
        $view_template = isset($_POST['view_template']) ? sanitize_text_field($_POST['view_template']) : '';

        if (empty($model_name) || empty($model_key)) {
            todo_admin_notice('模型名称和标识不能为空', 'error');
        } else {
            // 检查模型标识是否已存在
            $existing_model = CMS_Model::get_by_key($model_key);

            $model_exists = false;
            if ($existing_model !== null) {
                $model_data = $existing_model->get_data();
                if (!empty($model_data) && isset($model_data['model_id']) && $model_data['model_id'] > 0) {
                    $model_exists = true;
                }
            }

            if ($model_exists) {
                todo_admin_notice('模型标识已存在，请使用其他标识', 'error');
            } else {
                // 模型不存在，创建新模型
                $model = new CMS_Model();
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
                    // 自动创建模型字段表
                    try {
                        $model_fields_manager = new Todo_Model_Fields_Manager();
                        $table_created = $model_fields_manager->create_model_table($model_key);

                        if ($table_created) {
                            todo_add_admin_notice('模型添加成功，并已自动创建模型字段表', 'success');
                        } else {
                            todo_add_admin_notice('模型添加成功，但模型字段表创建失败，请手动创建', 'warning');
                        }
                    } catch (Exception $e) {
                        error_log('创建模型字段表失败: ' . $e->getMessage());
                        todo_add_admin_notice('模型添加成功，但模型字段表创建失败: ' . $e->getMessage(), 'warning');
                    }

                    // 清除模型缓存
                    CMS_Model::clear_cache();

                    // 重定向到模型列表页面
                    todo_redirect(todo_admin_url('index.php?page=models'));
                    exit;
                } else {
                    todo_admin_notice('模型添加失败', 'error');
                }
            }
        }
    }
}

?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">添加新模型</h2>
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
            <form method="post" action="<?php echo todo_admin_url('index.php?page=models&action=add'); ?>" class="admin-form todo-request">
                <?php echo Todo_Security_Manager::csrf_field('add_model'); ?>
                <input type="hidden" name="action" value="add_model">

                <div class="form-group">
                    <label for="model_name">模型名称</label>
                    <input name="model_name" type="text" id="model_name" class="regular-text"
                        value="<?php echo isset($_POST['model_name']) ? esc_attr($_POST['model_name']) : ''; ?>"
                        required>
                    <p class="description">模型的显示名称，如"文章"、"产品"等</p>
                </div>

                <div class="form-group">
                    <label for="model_key">模型标识</label>
                    <input name="model_key" type="text" id="model_key" class="regular-text"
                        value="<?php echo isset($_POST['model_key']) ? esc_attr($_POST['model_key']) : ''; ?>" required>
                    <p class="description">模型的唯一标识，仅允许小写字母、数字和下划线，如"post"、"product"等</p>
                </div>

                <div class="form-group">
                    <label for="model_description">模型描述</label>
                    <textarea name="model_description" id="model_description" class="large-text"
                        rows="3"><?php echo isset($_POST['model_description']) ? esc_textarea($_POST['model_description']) : ''; ?></textarea>
                    <p class="description">模型的简要描述</p>
                </div>

                <div class="form-group">
                    <label for="model_status">状态</label>
                    <select name="model_status" id="model_status" class="regular-text">
                        <option value="active" <?php selected(isset($_POST['model_status']) ? $_POST['model_status'] : 'active', 'active'); ?>>启用</option>
                        <option value="inactive" <?php selected(isset($_POST['model_status']) ? $_POST['model_status'] : '', 'inactive'); ?>>禁用</option>
                    </select>
                    <p class="description">模型的状态</p>
                </div>

                <div class="form-group">
                    <label for="list_template">列表页模板</label>
                    <input name="list_template" type="text" id="list_template" class="regular-text"
                        value="<?php echo isset($_POST['list_template']) ? esc_attr($_POST['list_template']) : ''; ?>">
                    <p class="description">此模型的列表页模板文件名，如 lists_custom.html。留空则自动匹配 lists_{模型标识}.html</p>
                </div>

                <div class="form-group">
                    <label for="view_template">详情页模板</label>
                    <input name="view_template" type="text" id="view_template" class="regular-text"
                        value="<?php echo isset($_POST['view_template']) ? esc_attr($_POST['view_template']) : ''; ?>">
                    <p class="description">此模型的详情页模板文件名，如 view_custom.html。留空则自动匹配 view_{模型标识}.html</p>
                </div>

                <div class="form-submit">
                    <button type="submit" name="submit" id="submit" class="button button-primary">添加模型</button>
                </div>
            </form>
        </div>
    </div>
</div>