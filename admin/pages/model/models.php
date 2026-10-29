<?php
// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
/**
 * 模型管理页面
 */

// 检查权限
if (!current_user_can('manage_models') && !current_user_can('edit_models')) {
    todo_die('您没有足够的权限访问此页面。');
}

// 引入弹窗组件
require_once dirname(__DIR__, 2) . '/components/modal.php';

$page_title = '模型管理';

// 处理状态更新消息
$message = isset($_GET['message']) ? sanitize_text_field($_GET['message']) : '';

// 获取操作类型
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// 处理删除操作 (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_model') {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('delete_model', '_todononce')) {
        todo_admin_notice('安全验证失败，请重试。', 'error');
    } else {
        $model_id = isset($_POST['model_id']) ? intval($_POST['model_id']) : 0;

        if ($model_id > 0) {
            $model = new CMS_Model($model_id);
            $model_data = $model->get_data();

            // 检查是否为系统默认模型
            if (!empty($model_data) && isset($model_data['is_system']) && $model_data['is_system'] == 1) {
                todo_admin_notice('不能删除系统默认模型', 'error');
            } else {
                $result = $model->delete();

                if ($result) {
                    todo_add_admin_notice('模型删除成功', 'success');
                    todo_redirect(todo_admin_url('index.php?page=models'));
                } else {
                    todo_admin_notice('模型删除失败', 'error');
                }
            }
        } else {
            todo_admin_notice('无效的模型ID', 'error');
        }
    }
}

// 处理消息提示
if (isset($_GET['message'])) {
    switch ($_GET['message']) {
        case 'added':
            $success_message = '模型添加成功';
            break;
        case 'updated':
            $success_message = '模型更新成功';
            break;
        case 'deleted':
            $success_message = '模型删除成功';
            break;
        case 'status_updated':
            $success_message = '模型状态更新成功';
            break;
    }
}

// 处理字段相关的消息提示
if (isset($_GET['message'])) {
    switch ($_GET['message']) {
        case 'field_added':
            $success_message = '字段添加成功';
            break;
        case 'field_updated':
            $success_message = '字段更新成功';
            break;
        case 'field_deleted':
            $success_message = '字段删除成功';
            break;
    }
}

// 根据操作类型显示不同的界面
switch ($action) {
    case 'delete_field':
        // 删除字段 - 包含外部文件
        include_once ABSPATH . 'admin/pages/model/models-delete-field.php';
        break;

    case 'edit_field':
        // 编辑字段
        include_once ABSPATH . 'admin/pages/model/models-edit-field.php';
        break;

    case 'add_field':
        // 包含添加字段页面
        include_once ABSPATH . 'admin/pages/model/models-add-field.php';
        break;
    case 'add':
        // 添加新模型界面 - 包含外部文件
        include_once ABSPATH . 'admin/pages/model/models-add.php';
        break;

    case 'edit':
        // 编辑模型界面 - 包含外部文件
        include_once ABSPATH . 'admin/pages/model/models-edit.php';
        break;

    case 'fields':
        // 模型字段管理界面 - 包含外部文件
        $model_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['model_id']) ? intval($_GET['model_id']) : 0);

        if ($model_id <= 0) {
            todo_admin_notice('无效的模型ID', 'error');
            todo_redirect(todo_admin_url('index.php?page=models'));
            break;
        }

        // 重定向到独立的模型字段管理页面
        todo_redirect(todo_admin_url('index.php?page=model-fields&model_id=' . $model_id));
        exit;
        break;

    case 'delete':
        $model_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['model_id']) ? intval($_GET['model_id']) : 0);

        if ($model_id <= 0) {
            todo_redirect(todo_admin_url('index.php?page=models&error=invalid_id'));
            exit;
        }

        todo_redirect(todo_admin_url('index.php?page=models'));
        exit;
        break;

    case 'list':
    default:
        // 模型列表界面
        // 获取所有模型
        $args = array(
            'orderby' => 'model_id',
            'order' => 'ASC'
        );

        $models = CMS_Model::get_models($args);

        // 性能优化：批量获取所有模型的字段数量（避免N+1查询）
        $model_ids = array();
        foreach ($models as $model) {
            $model_ids[] = is_object($model) ? $model->model_id : $model['model_id'];
        }
        $fields_counts = CMS_Model::get_fields_counts($model_ids);

        // 处理状态切换
        if (isset($_GET['toggle_status']) && isset($_GET['model_id'])) {
            $toggle_model_id = intval($_GET['model_id']);
            $model = new CMS_Model($toggle_model_id);
            $model_data = $model->get_data();

            if (!empty($model_data)) {
                // 检查model_status是否存在，如果不存在则默认为inactive
                $current_status = isset($model_data['model_status']) ? $model_data['model_status'] : 'inactive';
                $new_status = ($current_status === 'active') ? 'inactive' : 'active';

                // 使用update方法而不是不存在的set_data方法
                if (
                    $model->update([
                        'model_name' => $model_data['model_name'],
                        'model_key' => $model_data['model_key'],
                        'model_type' => $model_data['model_type'] ?? 'A',
                        'list_template' => $model_data['list_template'] ?? null,
                        'view_template' => $model_data['view_template'] ?? null,
                        'model_status' => $new_status
                    ])
                ) {
                    todo_add_admin_notice('模型状态更新成功', 'success');
                    // 重定向以避免刷新时重复切换
                    todo_redirect(todo_admin_url('index.php?page=models'));
                    exit;
                }
            }
        }

        // 显示消息已由全局 admin_notices 钩子处理
        ?>

        <div class="admin-page">
            <div class="admin-page-header">
                <h2 class="admin-page-title">模型管理</h2>
                <div class="admin-page-actions">
                    <a href="<?php echo todo_admin_url('index.php?page=models&action=add'); ?>" class="button button-primary">添加新模型</a>
                </div>
            </div>

            <div class="admin-card">
                <div class="admin-card-header no-border">
                    <h3 class="admin-card-title">模型列表</h3>
                </div>
                <div class="admin-card-content">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th style="min-width: 130px;">模型名称</th>
                                <th>模型标识</th>
                                <th style="min-width: 130px;">描述</th>
                                <th>字段数</th>
                                <th style="min-width: 80px; width: 80px;">状态</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($models)): ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="no-items">
                                            <p>没有找到模型。</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($models as $model):
                                    // 使用预先批量获取的字段数量（性能优化）
                                    $model_id = is_object($model) ? $model->model_id : $model['model_id'];
                                    $fields_count = isset($fields_counts[$model_id]) ? $fields_counts[$model_id] : 0;
                                    ?>
                                    <tr>
                                        <td><?php echo $model->model_id; ?></td>
                                        <td>
                                            <?php
                                            // 检查是否为系统默认模型
                                            $is_default_model = isset($model->is_system) && $model->is_system == 1;
                                            ?>
                                            <strong>
                                                <?php if (current_user_can('edit_models') && !$is_default_model): ?>
                                                    <a href="<?php echo todo_admin_url('index.php?page=models-edit&model_id=' . $model->model_id); ?>">
                                                        <?php echo esc_html($model->model_name); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <?php echo esc_html($model->model_name); ?>
                                                <?php endif; ?>
                                            </strong>
                                            <?php if ($is_default_model): ?>
                                                <em class="tb-meta" style="display: inline-block;">默认</em>
                                            <?php endif; ?>
                                            <?php if (current_user_can('edit_models')): ?>
                                                <div class="row-actions">
                                                    <?php
                                                    // 检查是否为系统默认模型
                                                    $is_default_model = isset($model->is_system) && $model->is_system == 1;
                                                    ?>
                                                    <span class="edit">
                                                        <a href="<?php echo todo_admin_url('index.php?page=models-edit&model_id=' . $model->model_id); ?>"
                                                            aria-label="编辑 <?php echo esc_attr($model->model_name); ?>">
                                                            编辑
                                                        </a>
                                                        |
                                                    </span>
                                                    <span class="fields">
                                                        <a href="<?php echo todo_admin_url('index.php?page=model-fields&model_id=' . $model->model_id); ?>"
                                                            aria-label="管理 <?php echo esc_attr($model->model_name); ?> 字段">
                                                            字段
                                                        </a>
                                                    <?php if (!$is_default_model): ?>
                                                        |
                                                    </span>
                                                    <span class="delete submitdelete">
                                                        <a href="javascript:void(0);" data-model-id="<?php echo esc_attr($model->model_id); ?>" data-model-name="<?php echo esc_attr($model->model_name); ?>" data-model-key="<?php echo esc_attr($model->model_key); ?>" data-model-description="<?php echo esc_attr($model->model_description ?? ''); ?>"
                                                            aria-label="删除 <?php echo esc_attr($model->model_name); ?>" class="submitdelete">
                                                            删除
                                                        </a>
                                                    <?php endif; ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html($model->model_key); ?></td>
                                        <td><?php echo esc_html($model->model_description ?? ''); ?></td>
                                        <td>
                                            <?php if (current_user_can('edit_models')): ?>
                                                <a href="<?php echo todo_admin_url('index.php?page=model-fields&model_id=' . $model->model_id); ?>">
                                                    <?php echo $fields_count; ?>
                                                </a>
                                            <?php else: ?>
                                                <?php echo $fields_count; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (current_user_can('edit_models')): ?>
                                                <?php $status = isset($model->model_status) ? $model->model_status : 'inactive'; ?>
                                                <a href="<?php echo todo_admin_url('index.php?page=models&toggle_status=1&model_id=' . $model->model_id); ?>"
                                                    class="status-toggle status-<?php echo $status; ?>">
                                                    <?php echo $status === 'active' ? '启用' : '禁用'; ?>
                                                </a>
                                            <?php else: ?>
                                                <?php $status = isset($model->model_status) ? $model->model_status : 'inactive'; ?>
                                                <span class="status-<?php echo $status; ?>">
                                                    <?php echo $status === 'active' ? '启用' : '禁用'; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>

                        <tfoot>
                            <tr>
                                <th>ID</th>
                                <th>模型名称</th>
                                <th>模型标识</th>
                                <th>描述</th>
                                <th>字段数</th>
                                <th>状态</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <style>
                .status-toggle {
                    display: inline-block;
                    text-decoration: none;
                    cursor: pointer;
                    transition: all 0.3s ease;
                }

                .status-toggle:hover {
                    opacity: 0.8;
                }
            </style>
        </div>
        <?php
        break;

    // 删除了重复的default子句
}

?>
<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        var deleteModelNonce = '<?php echo esc_js(todo_create_nonce('delete_model')); ?>';

        document.addEventListener('click', function (e) {
            var link = e.target && e.target.closest ? e.target.closest('a.submitdelete[data-model-id]') : null;
            if (!link) return;

            e.preventDefault();
            e.stopPropagation();

            var modelId = link.getAttribute('data-model-id') || '';
            if (!modelId) return;

            var modelName = link.getAttribute('data-model-name') || '';
            var modelKey = link.getAttribute('data-model-key') || '';
            var modelDesc = link.getAttribute('data-model-description') || '';

            var submitForm = function () {
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = '<?php echo todo_admin_url('index.php?page=models'); ?>';
                form.className = 'todo-request';

                var actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_model';
                form.appendChild(actionInput);

                var idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'model_id';
                idInput.value = modelId;
                form.appendChild(idInput);

                var nonceInput = document.createElement('input');
                nonceInput.type = 'hidden';
                nonceInput.name = '_todononce';
                nonceInput.value = deleteModelNonce;
                form.appendChild(nonceInput);

                document.body.appendChild(form);
                form.submit();
            };

            var itemInfo = {};
            if (modelName) itemInfo['模型名称'] = modelName;
            if (modelKey) itemInfo['模型标识'] = modelKey;
            if (modelDesc) itemInfo['模型描述'] = modelDesc;

            if (window.TodoModal && typeof window.TodoModal.deleteConfirm === 'function') {
                TodoModal.deleteConfirm({
                    title: '确认删除模型',
                    message: '将同时删除该模型下的所有字段和数据',
                    itemInfo: itemInfo,
                    confirmText: '确认删除',
                    onConfirm: submitForm
                });
                return;
            }

            if (window.confirm('确认删除该模型？将同时删除该模型下的所有字段和数据')) {
                submitForm();
            }
        }, true);
    });
</script>
<?php

// 处理错误消息
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'invalid_id':
            echo '<div class="notice notice-error"><p>无效的模型ID</p></div>';
            break;
    }
}
?>

<style>
    /* 模型列表页面特定样式 */
    .admin-subsubsub {
        margin-bottom: 15px;
    }

    .admin-subsubsub ul {
        margin: 0;
        padding: 0;
        list-style: none;
        font-size: 13px;
    }

    .admin-subsubsub li {
        display: inline-block;
        margin: 0;
        padding: 0;
    }

    .admin-subsubsub a {
        padding: 0 5px;
        line-height: 2;
        text-decoration: none;
    }

    .admin-subsubsub a.current {
        font-weight: 500;
        color: #000;
    }

    .admin-subsubsub .count {
        color: #999;
    }

    .admin-tablenav {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin: 15px 0;
    }

    .admin-tablenav.top {
        margin-top: 0;
    }
</style>
