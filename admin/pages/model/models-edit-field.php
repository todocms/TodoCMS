<?php
// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
// 处理AJAX请求 - 需要在加载其他文件之前处理
if (isset($_GET['action']) && $_GET['action'] === 'get_field_config') {
    // 清除任何之前的输出缓冲
    while (ob_get_level()) {
        ob_end_clean();
    }

    // 加载最小必要文件
    require_once dirname(dirname(dirname(__DIR__))) . '/config.php';

    // 检查session是否已启动
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // 定义常量（检查是否已定义）
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(dirname(dirname(__DIR__))) . '/');
    }

    // 加载Composer autoloader
    if (!class_exists('CMS_DB')) {
    }

    // 加载必要的函数和类
    require_once CORE_DIR . '/functions/security-extend.php';
    require_once CORE_DIR . '/functions/formatting.php';

    // 设置正确的Content-Type头
    header('Content-Type: text/html; charset=utf-8');

    if (isset($_GET['field_type'])) {
        $field_type = sanitize_text_field($_GET['field_type']);

        // 确保字段类型已初始化
        if (empty(CMS_Field_Types::get_types())) {
            CMS_Field_Types::init();
        }

        $config_fields = [];

        // 根据字段类型定义配置字段
        switch ($field_type) {
            case 'select':
            case 'radio':
            case 'checkbox':
                $config_fields[] = [
                    'field_key' => 'field_options',
                    'field_name' => '选项设置',
                    'field_type' => 'textarea',
                    'field_description' => '每行一个选项，格式：值|显示文本（如：1|选项一）',
                    'is_required' => 0
                ];
                break;

            case 'number':
                $config_fields[] = [
                    'field_key' => 'min_value',
                    'field_name' => '最小值',
                    'field_type' => 'number',
                    'field_description' => '允许输入的最小数值'
                ];
                $config_fields[] = [
                    'field_key' => 'max_value',
                    'field_name' => '最大值',
                    'field_type' => 'number',
                    'field_description' => '允许输入的最大数值'
                ];
                break;

            case 'text':
                $config_fields[] = [
                    'field_key' => 'max_length',
                    'field_name' => '最大长度',
                    'field_type' => 'number',
                    'field_description' => '允许输入的最大字符数'
                ];
                break;

            case 'multi_file':
                $config_fields[] = [
                    'field_key' => 'max_images',
                    'field_name' => '最大文件数量',
                    'field_type' => 'number',
                    'field_description' => '允许选择的最大文件数量，留空或0表示不限制',
                    'field_default' => '20'
                ];
                break;

            case 'region':
                $config_fields[] = [
                    'field_key' => 'region_level',
                    'field_name' => '区域级别',
                    'field_type' => 'select',
                    'field_description' => '选择区域地址的详细程度（默认包含详细地址输入框）',
                    'field_options' => "1|省级\n2|省市\n3|省市区\n4|省市区乡镇\n5|省市区乡镇+详细地址",
                    'field_default' => '5',
                    'is_required' => 0
                ];
                break;
        }

        if (!empty($config_fields)) {
            // 预填充已保存的字段配置值
            $values = [];

            // 需要模型和字段信息以读取已保存的配置
            if (isset($_GET['model_id']) && isset($_GET['field_id'])) {
                // 加载必要的类与函数
                require_once CORE_DIR . '/functions/formatting.php';

                $model_id = intval($_GET['model_id']);
                $field_id = intval($_GET['field_id']);

                if ($model_id > 0 && $field_id > 0) {
                    $model = new CMS_Model($model_id);
                    $existing_field = $model->get_field($field_id);
                    if ($existing_field && !empty($existing_field['field_options'])) {
                        $saved = maybe_unserialize($existing_field['field_options']);
                        if (is_array($saved)) {
                            switch ($field_type) {
                                case 'select':
                                case 'radio':
                                case 'checkbox':
                                    $values['field_options'] = $saved['options'] ?? '';
                                    break;
                                case 'number':
                                    $values['min_value'] = $saved['min_value'] ?? '';
                                    $values['max_value'] = $saved['max_value'] ?? '';
                                    break;
                                case 'text':
                                    $values['max_length'] = $saved['max_length'] ?? '';
                                    break;
                                case 'multi_file':
                                    // 兼容旧键名 max_files
                                    $values['max_images'] = $saved['max_images'] ?? ($saved['max_files'] ?? '20');
                                    break;
				                case 'region':
				                    $values['region_level'] = $saved['region_level'] ?? '5';
				                    break;
                            }
                        }
                    }
                }
            }

            // 使用字段生成器生成配置表单
            $field_generator = new CMS_Field_Generator($config_fields, $values);
            echo $field_generator->generate();
        } else {
            echo '<p class="description">此字段类型无需额外配置。</p>';
        }
    } else {
        echo '<p class="description error">缺少字段类型参数。</p>';
    }

    // 发送输出并清理缓冲区
    $output = ob_get_clean();
    echo $output;
    exit;
}

// 防止直接访问
if (!defined('IS_ADMIN')) {
    // 定义基础常量
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(dirname(dirname(__DIR__))) . '/');
    }
    define('TODO_LOADED', true);
    define('IS_ADMIN', true);

    // 加载配置文件
    require_once(dirname(dirname(dirname(__DIR__))) . '/config.php');

    // 定义数据库前缀常量
    if (!defined('DB_PREFIX')) {
        define('DB_PREFIX', TABLE_PREFIX);
    }

    // 启动会话
    session_start();

    // 加载核心引导文件
    require_once(CORE_DIR . '/bootstrap.php');

    // 加载函数加载器
    require_once(CORE_DIR . '/functions/function-loader.php');

    // 使用函数加载器加载核心函数
    Todo_Function_Loader::load_core_functions();

    // 加载管理后台所需的函数组
    Todo_Function_Loader::load_function_group('admin');
    Todo_Function_Loader::load_function_group('security');
    Todo_Function_Loader::load_function_group('content');
}

// 确保安全扩展函数可用
require_once dirname(dirname(dirname(__DIR__))) . '/core/functions/security-extend.php';

require_once dirname(dirname(dirname(__DIR__))) . '/core/functions/formatting.php';

// 检查用户权限
if (!function_exists('current_user_can') || (!current_user_can('edit_models') && !current_user_can('manage_models'))) {
    todo_die('您没有权限访问此页面');
}

// 获取模型ID和字段ID
$model_id = isset($_GET['model_id']) ? intval($_GET['model_id']) : 0;
$field_id = isset($_GET['field_id']) ? intval($_GET['field_id']) : 0;

if (!$model_id || !$field_id) {
    todo_die('无效的模型ID或字段ID');
}

// 获取模型信息
$model = new CMS_Model($model_id);
$model_data = $model->get_data();

if (empty($model_data)) {
    echo '<div class="notice notice-error is-dismissible"><p>模型不存在</p><button type="button" class="notice-dismiss">✕</button></div>';
    return;
}

// 获取字段信息
$field_data = $model->get_field($field_id);
if (empty($field_data)) {
    echo '<div class="notice notice-error is-dismissible"><p>字段不存在</p><button type="button" class="notice-dismiss">✕</button></div>';
    return;
}

// 初始化字段类型
CMS_Field_Types::init();

// 获取所有可用的字段类型
$field_types = CMS_Field_Types::get_types();

// 处理AJAX更新请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_field') {
    // 从POST参数获取model_id和field_id
    $post_model_id = isset($_POST['model_id']) ? intval($_POST['model_id']) : 0;
    $post_field_id = isset($_POST['field_id']) ? intval($_POST['field_id']) : 0;

    if (!$post_model_id || !$post_field_id) {
        echo json_encode(['success' => false, 'message' => '无效的模型ID或字段ID']);
        exit;
    }

    // 创建模型实例
    $post_model = new CMS_Model($post_model_id);
    $post_model_data = $post_model->get_data();

    if (empty($post_model_data)) {
        echo json_encode(['success' => false, 'message' => '模型不存在']);
        exit;
    }

    // 获取字段信息
    $post_field_data = $post_model->get_field($post_field_id);
    if (empty($post_field_data)) {
        echo json_encode(['success' => false, 'message' => '字段不存在']);
        exit;
    }

    $field_name = isset($_POST['field_title']) ? sanitize_text_field($_POST['field_title']) : '';
    $field_key = isset($_POST['field_name']) ? sanitize_key($_POST['field_name']) : '';
    $field_type = isset($_POST['field_type']) ? sanitize_text_field($_POST['field_type']) : 'text';
    $field_categories = isset($_POST['field_categories']) ? array_map('intval', $_POST['field_categories']) : array();

    // 验证字段信息
    if (empty($field_name) || empty($field_key)) {
        echo json_encode(['success' => false, 'message' => '字段名称和标识不能为空']);
        exit;
    }

    // 更新字段
    $update_data = [
        'field_name' => $field_name,
        'field_key' => $field_key,
        'field_type' => $field_type,
        'field_categories' => $field_categories
    ];

    try {
        $result = $post_model->update_field($post_field_id, $update_data);

        if ($result) {
            echo json_encode(['success' => true, 'message' => '字段更新成功']);
        } else {
            echo json_encode(['success' => false, 'message' => '更新字段失败']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '更新失败: ' . $e->getMessage()]);
    }

    exit;
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_field') {
    if (!Todo_Security_Manager::verify_request_csrf('model_field_edit', '_todononce')) {
        todo_admin_notice('安全验证失败，请刷新页面后重试', 'error');
    } else {
        // 获取提交的字段值
        $submitted_field_name = isset($_POST['field_name']) ? sanitize_text_field($_POST['field_name']) : '';
        $submitted_field_key = isset($_POST['field_key']) ? sanitize_key($_POST['field_key']) : '';
        $submitted_field_type = isset($_POST['field_type']) ? sanitize_text_field($_POST['field_type']) : 'text';
        $submitted_field_description = isset($_POST['field_description']) ? sanitize_textarea_field($_POST['field_description']) : '';
        $submitted_field_default = isset($_POST['field_default']) ? sanitize_text_field($_POST['field_default']) : '';
        $submitted_is_required = isset($_POST['is_required']) ? 1 : 0;
        $submitted_category_only = isset($_POST['category_only']) ? 1 : 0;
        $submitted_field_categories = isset($_POST['field_categories']) ? array_unique(array_map('intval', $_POST['field_categories'])) : array();
        $submitted_layout = isset($_POST['layout']) && is_array($_POST['layout']) ? $_POST['layout'] : [];
        $submitted_sub_width = !empty($submitted_layout['sub_width']) ? 1 : 0;

        $current_field_options = maybe_unserialize($field_data['field_options'] ?? '');
        if (is_string($current_field_options)) {
            $decoded = json_decode($current_field_options, true);
            if (is_array($decoded)) {
                $current_field_options = $decoded;
            }
        }
        if (!is_array($current_field_options)) {
            $current_field_options = [];
        }
        $current_sub_width = !empty($current_field_options['layout']['sub_width']) ? 1 : 0;

        // 获取当前字段关联的分类
        $current_field_categories = [];
        if ($field_data) {
            global $tododb;
            $category_results = $tododb->get_results(
                "SELECT term_id FROM " . TABLE_PREFIX . "term_field_visibility WHERE field_id = ?",
                [$field_id]
            );
            if ($category_results) {
                $current_field_categories = array_column($category_results, 'term_id');
            }
        }

        // 初始化$field_categories变量，用于页面显示
        $field_categories = $current_field_categories;

        // 检查是否只更新分类关联
        $only_update_categories = false;
        $categories_changed = isset($_POST['categories_changed']) && $_POST['categories_changed'] === '1';

        if ((isset($_POST['only_update_categories']) && $_POST['only_update_categories'] === '1') || $categories_changed) {
            $only_update_categories = true;
        } else {
            // 通过比较值是否发生变化来判断
            $only_update_categories = (
                $submitted_field_name === $field_data['field_name'] &&
                $submitted_field_key === $field_data['field_key'] &&
                $submitted_field_type === $field_data['field_type'] &&
                $submitted_field_description === $field_data['field_description'] &&
                $submitted_field_default === $field_data['field_default'] &&
                $submitted_is_required == $field_data['is_required'] &&
                $submitted_category_only == $field_data['category_only'] &&
                $submitted_sub_width == $current_sub_width &&
                $submitted_field_categories !== $current_field_categories
            );
        }

        if ($only_update_categories) {
            // 只更新分类关联，保留原有字段信息
            $field_name = $field_data['field_name'];
            $field_key = $field_data['field_key'];
            $field_type = $field_data['field_type'];
            $field_description = $field_data['field_description'];
            $field_default = $field_data['field_default'];
            $is_required = $field_data['is_required'];
            $category_only = $field_data['category_only'];
            $field_categories = $submitted_field_categories;
            $field_options = $current_field_options;
        } else {
            // 正常更新所有字段信息
            $field_name = $submitted_field_name;
            $field_key = $submitted_field_key;
            $field_type = $submitted_field_type;
            $field_description = $submitted_field_description;
            $field_default = $submitted_field_default;
            $is_required = $submitted_is_required;
            $category_only = $submitted_category_only;
            $field_categories = $submitted_field_categories;
        }

        // 收集字段配置数据（只在非仅更新分类时执行）
        if (!$only_update_categories) {
            $field_options = $current_field_options;
            unset(
                $field_options['options'],
                $field_options['min_value'],
                $field_options['max_value'],
                $field_options['max_length'],
                $field_options['max_images'],
                $field_options['max_files']
            );

            // 根据字段类型收集相应的配置
            switch ($field_type) {
                case 'select':
                case 'radio':
                case 'checkbox':
                    if (isset($_POST['field_options'])) {
                        $field_options['options'] = sanitize_textarea_field($_POST['field_options']);
                    }
                    break;
                case 'number':
                    if (isset($_POST['min_value'])) {
                        $field_options['min_value'] = sanitize_text_field($_POST['min_value']);
                    }
                    if (isset($_POST['max_value'])) {
                        $field_options['max_value'] = sanitize_text_field($_POST['max_value']);
                    }
                    break;
                case 'text':
                    if (isset($_POST['max_length'])) {
                        $field_options['max_length'] = sanitize_text_field($_POST['max_length']);
                    }
                    break;
                case 'multi_file':
                    if (isset($_POST['max_images'])) {
                        $field_options['max_images'] = intval($_POST['max_images']);
                    }
                    break;
                case 'region':
                    if (isset($_POST['region_level'])) {
                        $field_options['region_level'] = intval($_POST['region_level']);
                    }
                    break;
            }

            $should_set_sub_width = $submitted_sub_width && in_array($field_type, ['text', 'textarea', 'html'], true);
            if ($should_set_sub_width) {
                if (!isset($field_options['layout']) || !is_array($field_options['layout'])) {
                    $field_options['layout'] = [];
                }
                $field_options['layout']['sub_width'] = 1;
            } else {
                if (isset($field_options['layout']) && is_array($field_options['layout']) && array_key_exists('sub_width', $field_options['layout'])) {
                    unset($field_options['layout']['sub_width']);
                }
            }
        }
        if (!is_array($field_options)) {
            $field_options = array();
        }

        // 验证字段信息（只在非仅更新分类时验证）
        if (!$only_update_categories && (empty($field_name) || empty($field_key))) {
            todo_admin_notice('字段名称和标识不能为空', 'error');
        } else {
            // 检查字段标识是否已存在（只在非仅更新分类时检查）
            $field_exists = false;
            if (!$only_update_categories) {
                $existing_fields = $model->get_fields();

                if (!empty($existing_fields)) {
                    foreach ($existing_fields as $existing_field) {
                        // 确保 $existing_field 是对象，如果是数组则转换
                        if (is_array($existing_field)) {
                            $existing_field = (object) $existing_field;
                        }
                        if ($existing_field->field_key === $field_key && $existing_field->field_id != $field_id) {
                            $field_exists = true;
                            break;
                        }
                    }
                }
            }

            if ($field_exists) {
                todo_admin_notice('字段标识已存在，请使用其他标识', 'error');
            } else {
                $update_data = [
                    'field_name' => $field_name,
                    'field_key' => $field_key,
                    'field_type' => $field_type,
                    'field_description' => $field_description,
                    'field_default' => $field_default,
                    'field_options' => !empty($field_options) ? maybe_serialize($field_options) : '',
                    'is_required' => $is_required,
                    'category_only' => $category_only,
                    'field_categories' => $field_categories
                ];

                try {
                    $result = $model->update_field($field_id, $update_data);
                } catch (Throwable $e) {
                    $result = false;
                }

                if ($result) {
                    todo_admin_notice('字段更新成功', 'success');
                    todo_redirect(todo_admin_url('index.php?page=model-fields&model_id=' . $model_id));
                    exit;
                } else {
                    $no_changes = (
                        $submitted_field_name === $field_data['field_name'] &&
                        $submitted_field_key === $field_data['field_key'] &&
                        $submitted_field_type === $field_data['field_type'] &&
                        $submitted_field_description === $field_data['field_description'] &&
                        $submitted_field_default === $field_data['field_default'] &&
                        $submitted_is_required == $field_data['is_required'] &&
                        $submitted_category_only == $field_data['category_only'] &&
                        $submitted_sub_width == $current_sub_width &&
                        $submitted_field_categories === $current_field_categories
                    );
                    if ($no_changes) {
                        todo_admin_notice('字段更新成功', 'success');
                        todo_redirect(todo_admin_url('index.php?page=model-fields&model_id=' . $model_id));
                        exit;
                    }
                    todo_admin_notice('更新字段失败', 'error');
                    todo_redirect(todo_admin_url('index.php?page=models-edit-field&model_id=' . $model_id . '&field_id=' . $field_id));
                    exit;
                }
            }
        }
    }
}
// 初始化 $field_categories 变量用于页面显示（非POST请求时）
if (!isset($field_categories)) {
    $field_categories = [];
    if ($field_data) {
        global $tododb;
        $category_results = $tododb->get_results(
            "SELECT term_id FROM " . TABLE_PREFIX . "term_field_visibility WHERE field_id = ?",
            [$field_id]
        );
        if ($category_results) {
            $field_categories = array_column($category_results, 'term_id');
        }
    }
}

$field_options_for_display = maybe_unserialize($field_data['field_options'] ?? '');
if (is_string($field_options_for_display)) {
    $decoded = json_decode($field_options_for_display, true);
    if (is_array($decoded)) {
        $field_options_for_display = $decoded;
    }
}
if (!is_array($field_options_for_display)) {
    $field_options_for_display = [];
}
$layout_sub_width_checked = !empty($field_options_for_display['layout']['sub_width']);
$should_show_sub_width = in_array($field_data['field_type'] ?? '', ['text', 'textarea', 'html'], true);

?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">编辑字段</h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=model-fields&model_id=' . $model_id); ?>"
                class="button button-secondary">返回字段列表</a>
        </div>
    </div>
    <?php if (isset($error_message)): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo todo_esc_html($error_message); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <form method="post"
        action="<?php echo todo_admin_url('index.php?page=models-edit-field&model_id=' . $model_id . '&field_id=' . $field_id); ?>"
        class="admin-form todo-request">
        <input type="hidden" name="action" value="edit_field">
        <?php echo todo_csrf_field('model_field_edit', '_todononce'); ?>

        <div class="admin-card">
            <div class="admin-card-header">
                <h3>基础配置</h3>
            </div>
            <div class="admin-card-content">
                <div class="form-group">
                    <label for="model_name">所属模型</label>
                    <input type="text" value="<?php echo todo_esc_attr($model_data['model_name'] ?? ''); ?>" readonly>
                </div>

                <div class="form-group">
                    <label for="field_name">字段名称 <span class="required">*</span></label>
                    <input name="field_name" type="text" id="field_name"
                        value="<?php echo todo_esc_attr($field_data['field_name'] ?? ''); ?>" required>
                    <p class="description">字段的显示名称，如"标题"、"内容"等</p>
                </div>

                <div class="form-group">
                    <label for="field_key">字段标识 <span class="required">*</span></label>
                    <input name="field_key" type="text" id="field_key"
                        value="<?php echo todo_esc_attr($field_data['field_key'] ?? ''); ?>" required>
                    <p class="description">字段的唯一标识，仅允许小写字母、数字和下划线，如"title"、"content"等</p>
                </div>

                <div class="form-group">
                    <label>数据类型 <span class="required">*</span></label>
                    <p class="description" style="margin-bottom: 15px;">选择字段的数据类型</p>
                    <div class="field-type-options">
                        <div class="field-type-grid">
                            <?php foreach ($field_types as $type_key => $type_info): ?>
                                <div class="field-type-option">
                                    <label>
                                        <input type="radio" name="field_type"
                                            value="<?php echo todo_esc_attr($type_key); ?>" <?php echo ($type_key === ($field_data['field_type'] ?? array_key_first($field_types))) ? 'checked' : ''; ?> onchange="updateFieldConfig(this.value)">
                                        <div class="field-type-info">
                                            <strong><?php echo todo_esc_html($type_info['name']); ?></strong>
                                            <small><?php echo todo_esc_html($type_info['description'] ?? ''); ?></small>
                                        </div>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-header">
                <h3>字段类型</h3>
            </div>
            <div class="admin-card-content" id="field-type-config">
                <!-- 字段类型特定的配置选项将在这里动态加载 -->
            </div>
        </div>

        <script type="text/javascript">
            function toggleSubWidthFieldOption(fieldType) {
                var subWidthGroup = document.getElementById('sub-width-field');
                var subWidthCheckbox = document.getElementById('layout_sub_width');
                if (!subWidthGroup || !subWidthCheckbox) return;

                var shouldShow = fieldType === 'text' || fieldType === 'textarea' || fieldType === 'html';
                if (shouldShow) {
                    subWidthGroup.style.display = 'block';
                } else {
                    subWidthGroup.style.display = 'none';
                    subWidthCheckbox.checked = false;
                }
            }

            function updateFieldConfig(fieldType) {
                // 显示加载状态
                var configContainer = document.getElementById('field-type-config');
                configContainer.innerHTML = '<div class="loading">正在加载配置选项...</div>';
                toggleSubWidthFieldOption(fieldType);

                // 构建请求URL
                var baseUrl = '<?php echo todo_admin_url('index.php?page=models-edit-field&model_id=' . $model_id . '&field_id=' . $field_id . '&action=get_field_config'); ?>&field_type=' + fieldType;

                // 使用AJAX加载字段类型特定的配置选项
                var xhr = new XMLHttpRequest();
                xhr.open('GET', baseUrl, true);
                xhr.onreadystatechange = function () {
                    if (xhr.readyState === 4) {
                        if (xhr.status === 200) {
                            configContainer.innerHTML = xhr.responseText;
                        } else {
                            configContainer.innerHTML = '<div class="notice notice-error is-dismissible"><p>加载配置选项失败，请重试。</p><button type="button" class="notice-dismiss">✕</button></div>';
                        }
                    }
                };
                xhr.send();
            }

            // 监听分类选择变化，控制"仅关联至分类信息"选项的显示
            function toggleCategoryOnlyField() {
                var categoryCheckboxes = document.querySelectorAll('input[name="field_categories[]"]');
                var categoryOnlyField = document.getElementById('category-only-field');
                var hasSelectedCategory = false;

                // 检查是否有选中的分类
                for (var i = 0; i < categoryCheckboxes.length; i++) {
                    if (categoryCheckboxes[i].checked) {
                        hasSelectedCategory = true;
                        break;
                    }
                }

                // 根据是否有选中的分类来显示或隐藏"仅关联至分类信息"选项
                if (hasSelectedCategory) {
                    categoryOnlyField.style.display = 'block';
                } else {
                    categoryOnlyField.style.display = 'none';
                    // 如果隐藏了选项，同时取消勾选
                    document.getElementById('category_only').checked = false;
                }
            }

            // 初始加载默认字段类型的配置
            document.addEventListener('DOMContentLoaded', function () {
                if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils && TodoCMS.Utils.initSlugGenerator) {
                    TodoCMS.Utils.initSlugGenerator({
                        nameInputId: 'field_name',
                        slugInputId: 'field_key',
                        ajaxAction: 'slug_generator',
                        id: <?php echo intval($field_id); ?>,
                        nameParam: 'name',
                        idParam: 'id',
                        extraParams: {
                            type: 'model_field',
                            model_id: <?php echo intval($model_id); ?>
                        }
                    });
                }

                // 获取第一个选中的单选按钮的值
                var checkedRadio = document.querySelector('input[name="field_type"]:checked');
                if (checkedRadio) {
                    updateFieldConfig(checkedRadio.value);
                } else {
                    // 如果没有选中的单选按钮，则选中第一个并加载其配置
                    var firstRadio = document.querySelector('input[name="field_type"]');
                    if (firstRadio) {
                        firstRadio.checked = true;
                        updateFieldConfig(firstRadio.value);
                    }
                }

                var fieldTypeRadios = document.querySelectorAll('input[name="field_type"]');
                for (var i = 0; i < fieldTypeRadios.length; i++) {
                    fieldTypeRadios[i].addEventListener('change', function () {
                        toggleSubWidthFieldOption(this.value);
                    });
                }

                // 监听分类选择变化
                setTimeout(function () {
                    var categoryCheckboxes = document.querySelectorAll('input[name="field_categories[]"]');
                    for (var i = 0; i < categoryCheckboxes.length; i++) {
                        categoryCheckboxes[i].addEventListener('change', toggleCategoryOnlyField);
                    }
                    // 初始检查
                    toggleCategoryOnlyField();
                }, 500); // 延迟执行，确保分类选择器已经渲染完成
            });
        </script>

        <div class="admin-card">
            <div class="admin-card-header">
                <h3>默认设置</h3>
            </div>
            <div class="admin-card-content">
                <div class="form-group">
                    <label for="field_description">字段描述</label>
                    <textarea name="field_description" id="field_description" rows="3"
                        placeholder="请输入字段的详细描述..."><?php echo esc_textarea($field_data['field_description'] ?? ''); ?></textarea>
                    <p class="description">字段下方的简要描述，用于帮助用户理解字段用途</p>
                </div>

                <div class="form-group">
                    <label for="field_default">默认值</label>
                    <input name="field_default" type="text" id="field_default"
                        value="<?php echo todo_esc_attr($field_data['field_default'] ?? ''); ?>"
                        placeholder="请输入字段的默认值...">
                    <p class="description">字段的默认值，新建内容时将自动填入此值</p>
                </div>

                <div class="form-group" id="sub-width-field" style="<?php echo $should_show_sub_width ? '' : 'display:none;'; ?>">
                    <label class="checkbox-label">
                        <input name="layout[sub_width]" type="checkbox" id="layout_sub_width" value="1" <?php echo $layout_sub_width_checked ? 'checked' : ''; ?>>
                        <span class="checkmark"></span>
                        宽度100%
                    </label>
                    <p class="description">仅在字段布局设置为全宽时生效</p>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input name="is_required" type="checkbox" id="is_required" value="1" <?php echo (!empty($field_data['is_required'])) ? 'checked' : ''; ?>>
                        <span class="checkmark"></span>
                        必填字段
                    </label>
                    <p class="description">勾选此项表示此字段为必填项，用户必须填写才能保存</p>
                </div>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-header">
                <h3>可见性设置</h3>
            </div>
            <div class="admin-card-content">
                <div class="form-group">
                    <label for="field_categories">关联分类<?php echo empty($field_categories) ? ' <span class="tb-meta">无</span>' : ''; ?></label>
                    <?php
                    // 引入层级分类选择器组件
                    require_once ABSPATH . 'admin/components/hierarchical-categories-selector.php';

                    // 渲染层级分类选择器
                    render_hierarchical_categories_selector(array(
                        'name' => 'field_categories[]',
                        'id' => 'field_categories',
                        'selected' => $field_categories,
                        'multiple' => true,
                        'show_checkbox' => true,
                        'class' => 'field-categories-selector',
                        'description' => '选择与此字段关联的分类（可多选），只有在这些分类下的内容才会显示此字段。灰色分类不可用于当前模型。',
                        'model_id' => $model_id,
                        'filter_by_model' => true
                    ));
                    ?>
                </div>

                <div class="form-group" id="category-only-field" style="display: none;">
                    <label class="checkbox-label">
                        <input name="category_only" type="checkbox" id="category_only" value="1" <?php echo (!empty($field_data['category_only'])) ? 'checked' : ''; ?>>
                        <span class="checkmark"></span>
                        仅关联至分类信息
                    </label>
                    <p class="description">勾选此项表示此字段仅用于扩展分类属性，不会在模型下的文章中显示</p>
                </div>
            </div>
        </div>

        <div class="admin-form-submit">
            <a href="<?php echo todo_admin_url('index.php?page=model-fields&model_id=' . $model_id); ?>" class="button button-secondary">
                取消
            </a>
            <button type="submit" name="submit" class="button button-primary">
                更新字段
            </button>
        </div>
    </form>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            var form = document.querySelector('.admin-form');
            if (!form) return;
            form.addEventListener('submit', function (e) {
                if (form.dataset.submitted === '1') {
                    e.preventDefault();
                    return false;
                }
                form.dataset.submitted = '1';
                var btn = form.querySelector('button[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                    btn.classList.add('loading');
                }
            });
        });
    </script>
</div>

<?php
// 输出层级分类选择器的CSS样式
if (function_exists('enqueue_hierarchical_categories_selector_styles')) {
    enqueue_hierarchical_categories_selector_styles();
}
?>
