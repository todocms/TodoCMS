<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

// 在任何输出之前设置header
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}

// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
/**
 * 模型字段添加页面
 *
 * 提供添加新字段到模型的功能
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 必须在任何HTML输出之前处理AJAX请求
if (isset($_GET['action']) && $_GET['action'] === 'get_field_config') {
    // 清除任何之前的输出缓冲
    while (ob_get_level()) {
        ob_end_clean();
    }

    // 检查用户权限
    if (!current_user_can('edit_models') && !current_user_can('manage_models')) {
        echo '<div class="notice notice-error"><p>您没有足够的权限访问此页面。</p></div>';
        exit;
    }

    if (!isset($_GET['field_type'])) {
        echo '<div class="notice notice-error"><p>缺少字段类型参数。</p></div>';
        exit;
    }

    // 确保字段类型已初始化
    if (empty(CMS_Field_Types::get_types())) {
        CMS_Field_Types::init();
    }

    $field_type = sanitize_text_field($_GET['field_type']);

    // 创建字段配置数据
    $config_fields = [];

    // 根据字段类型添加相应的配置字段
    switch ($field_type) {
        case 'select':
        case 'radio':
        case 'checkbox':
            $config_fields[] = [
                'field_name' => '选项设置',
                'field_key' => 'field_options',
                'field_type' => 'textarea',
                'field_description' => '每行一个选项，格式：值|显示文本',
                'field_default' => '',
                'is_required' => 0
            ];
            break;

        case 'number':
            $config_fields[] = [
                'field_name' => '最小值',
                'field_key' => 'min_value',
                'field_type' => 'number',
                'field_description' => '数字的最小值',
                'field_default' => '',
                'is_required' => 0
            ];
            $config_fields[] = [
                'field_name' => '最大值',
                'field_key' => 'max_value',
                'field_type' => 'number',
                'field_description' => '数字的最大值',
                'field_default' => '',
                'is_required' => 0
            ];
            break;

        case 'text':
            $config_fields[] = [
                'field_name' => '最大长度',
                'field_key' => 'max_length',
                'field_type' => 'number',
                'field_description' => '文本的最大长度',
                'field_default' => '255',
                'is_required' => 0
            ];
            break;

        case 'multi_file':
            $config_fields[] = [
                'field_name' => '最大文件数量',
                'field_key' => 'max_files',
                'field_type' => 'number',
                'field_description' => '允许选择的最大文件数量，留空或0表示不限制',
                'field_default' => '20',
                'is_required' => 0
            ];
            break;

        case 'region':
            $config_fields[] = [
                'field_name' => '区域级别',
                'field_key' => 'region_level',
                'field_type' => 'select',
                'field_description' => '选择区域地址的详细程度（默认包含详细地址输入框）',
                'field_options' => "1|省级\n2|省市\n3|省市区\n4|省市区乡镇\n5|省市区乡镇+详细地址",
                'field_default' => '5',
                'is_required' => 0
            ];
            break;
    }

    if (!empty($config_fields)) {
        // 使用字段生成器生成配置表单
        $field_generator = new CMS_Field_Generator($config_fields, []);
        echo $field_generator->generate();
    } else {
        echo '<p class="description">此字段类型无需额外配置。</p>';
    }

    // 发送输出并清理缓冲区
    $output = ob_get_clean();
    echo $output;
    exit;
}

// 检查用户权限
if (!current_user_can('edit_models') && !current_user_can('manage_models')) {
    todo_die('您没有足够的权限访问此页面。');
}

$page_title = '添加字段';

// 获取模型ID，优先使用model_id参数
$model_id = isset($_GET['model_id']) ? intval($_GET['model_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

if ($model_id <= 0) {
    echo '<div class="wrap"><div class="notice notice-error"><p>无效的模型ID</p></div></div>';
    return;
}

// 获取模型信息
$model = new CMS_Model($model_id);
$model_data = $model->get_data();

if (empty($model_data)) {
    echo '<div class="wrap"><div class="notice notice-error"><p>模型不存在</p></div></div>';
    return;
}

// 初始化字段类型
CMS_Field_Types::init();

// 获取可用的字段类型
$field_types = CMS_Field_Types::get_types();

// 如果字段类型为空，添加默认字段类型
if (empty($field_types)) {
    $field_types = [
        'text' => ['key' => 'text', 'name' => '单行文本', 'description' => '单行文本输入框'],
        'textarea' => ['key' => 'textarea', 'name' => '多行文本', 'description' => '多行文本输入框'],
        'html' => ['key' => 'html', 'name' => 'HTML文本', 'description' => '富文本编辑器'],
        'number' => ['key' => 'number', 'name' => '数字', 'description' => '数字输入框'],
        'datetime' => ['key' => 'datetime', 'name' => '日期时间', 'description' => '日期和时间选择器'],
        'select' => ['key' => 'select', 'name' => '下拉选择', 'description' => '下拉选择框'],
        'radio' => ['key' => 'radio', 'name' => '单选按钮', 'description' => '单选按钮组'],
        'checkbox' => ['key' => 'checkbox', 'name' => '复选框', 'description' => '多选复选框组'],
        'multi-file' => ['key' => 'file', 'name' => '图片/文件', 'description' => '上传和选择'],
        'region' => ['key' => 'region', 'name' => '地区', 'description' => '省市区三级联动选择'],
        'switch' => ['key' => 'switch', 'name' => '开关', 'description' => '开关切换按钮'],
    ];
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_field') {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('add_field', '_todononce')) {
        todo_admin_notice('安全验证失败，请重试。', 'error');
    } else {
        $field_name = isset($_POST['field_name']) ? sanitize_text_field($_POST['field_name']) : '';
        $field_key = isset($_POST['field_key']) ? sanitize_key($_POST['field_key']) : '';
        $field_type = isset($_POST['field_type']) ? sanitize_text_field($_POST['field_type']) : 'text';
        $field_description = isset($_POST['field_description']) ? sanitize_textarea_field($_POST['field_description']) : '';
        $field_default = isset($_POST['field_default']) ? sanitize_text_field($_POST['field_default']) : '';
        $is_required = isset($_POST['is_required']) ? 1 : 0;
        $category_only = isset($_POST['category_only']) ? 1 : 0;
        $field_categories = isset($_POST['field_categories']) ? array_map('intval', $_POST['field_categories']) : array();

        // 收集字段配置数据
        $field_options = array();

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

        $layout = isset($_POST['layout']) && is_array($_POST['layout']) ? $_POST['layout'] : [];
        $sub_width = !empty($layout['sub_width']);
        if ($sub_width && in_array($field_type, ['text', 'textarea', 'html'], true)) {
            if (!isset($field_options['layout']) || !is_array($field_options['layout'])) {
                $field_options['layout'] = [];
            }
            $field_options['layout']['sub_width'] = 1;
        }

        // 验证字段信息
        if (empty($field_name) || empty($field_key)) {
            todo_admin_notice('字段名称和标识不能为空', 'error');
        } else {
            // 检查字段标识是否已存在
            $existing_fields = $model->get_fields();
            $field_exists = false;

            if (!empty($existing_fields)) {
                foreach ($existing_fields as $existing_field) {
                    // 确保 $existing_field 是对象，如果是数组则转换
                    if (is_array($existing_field)) {
                        $existing_field = (object) $existing_field;
                    }
                    if ($existing_field->field_key === $field_key) {
                        $field_exists = true;
                        break;
                    }
                }
            }

            if ($field_exists) {
                todo_admin_notice('字段标识已存在，请使用其他标识', 'error');
            } else {
                // 添加新字段
                $field_data = [
                    'field_name' => $field_name,
                    'field_key' => $field_key,
                    'field_type' => $field_type,
                    'field_description' => $field_description,
                    'field_default' => $field_default,
                    'field_options' => !empty($field_options) ? maybe_serialize($field_options) : '',
                    'is_required' => $is_required,
                    'category_only' => $category_only,
                    'is_system' => 0,
                    'is_visible' => 1,
                    'field_order' => 0,
                    'field_categories' => $field_categories
                ];

                $result = $model->add_field($field_data);

                if ($result) {
                    // 添加字段与分类的关联
                    if (!empty($field_categories)) {
                        global $tododb;
                        foreach ($field_categories as $term_id) {
                            tododb_insert(
                                TABLE_PREFIX . 'term_field_visibility',
                                array(
                                    'term_id' => intval($term_id),
                                    'field_id' => intval($result),
                                    'is_visible' => 1
                                )
                            );
                        }
                    }

                    todo_admin_notice('字段添加成功', 'success');
                    todo_redirect(todo_admin_url('index.php?page=model-fields&model_id=' . $model_id));
                    exit;
                } else {
                    todo_admin_notice('添加字段失败', 'error');
                }
            }
        }
    }
}
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">添加字段</h2>
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

    <form method="post" action="<?php echo todo_admin_url('index.php?page=models-add-field&model_id=' . $model_id); ?>" class="admin-form todo-request">
        <?php echo Todo_Security_Manager::csrf_field('add_field'); ?>
        <input type="hidden" name="action" value="add_field">

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
                    <input name="field_name" type="text" id="field_name" value="" required>
                    <p class="description">字段的显示名称，如"标题"、"内容"等</p>
                </div>

                <div class="form-group">
                    <label for="field_key">字段标识 <span class="required">*</span></label>
                    <input name="field_key" type="text" id="field_key" value="" required>
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
                                            value="<?php echo todo_esc_attr($type_key); ?>" <?php echo ($type_key === array_key_first($field_types)) ? 'checked' : ''; ?>
                                            onchange="updateFieldConfig(this.value)">
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
                var baseUrl = '<?php echo todo_admin_url('index.php?page=models-add-field&model_id=' . $model_id . '&action=get_field_config'); ?>&field_type=' + fieldType;

                // 使用AJAX加载字段类型特定的配置选项
                var xhr = new XMLHttpRequest();
                xhr.open('GET', baseUrl, true);
                xhr.onreadystatechange = function () {
                    if (xhr.readyState === 4) {
                        if (xhr.status === 200) {
                            configContainer.innerHTML = xhr.responseText;
                        } else {
                            configContainer.innerHTML = '<div class="notice notice-error"><p>加载配置选项失败，请重试。</p></div>';
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
                        id: 0,
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
                        placeholder="请输入字段的详细描述..."></textarea>
                    <p class="description">字段下方的简要描述，用于帮助用户理解字段用途</p>
                </div>

                <div class="form-group">
                    <label for="field_default">默认值</label>
                    <input name="field_default" type="text" id="field_default" value="" placeholder="请输入字段的默认值...">
                    <p class="description">字段的默认值，新建内容时将自动填入此值</p>
                </div>

                <div class="form-group" id="sub-width-field" style="display:none;">
                    <label class="checkbox-label">
                        <input name="layout[sub_width]" type="checkbox" id="layout_sub_width" value="1">
                        <span class="checkmark"></span>
                        宽度100%
                    </label>
                    <p class="description">仅在字段布局设置为全宽时生效</p>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input name="is_required" type="checkbox" id="is_required" value="1">
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
                    <label for="field_categories">关联分类</label>
                    <?php
                    // 引入层级分类选择器组件
                    require_once ABSPATH . 'admin/components/hierarchical-categories-selector.php';

                    // 渲染层级分类选择器
                    render_hierarchical_categories_selector(array(
                        'name' => 'field_categories[]',
                        'id' => 'field_categories',
                        'selected' => array(),
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
                        <input name="category_only" type="checkbox" id="category_only" value="1">
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
                添加字段
            </button>
        </div>
    </form>
</div>

<?php
// 输出层级分类选择器的CSS样式
if (function_exists('enqueue_hierarchical_categories_selector_styles')) {
    enqueue_hierarchical_categories_selector_styles();
}
?>
