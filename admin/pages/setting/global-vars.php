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
    if (!defined('IS_ADMIN')) {
        define('IS_ADMIN', true);
        require_once dirname(dirname(dirname(__DIR__))) . '/config.php';

        // 检查session是否已启动
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // 定义常量
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(dirname(dirname(__DIR__))) . '/');
        }

        // 加载Composer autoloader
        if (!class_exists('CMS_DB')) {
        }

        // 加载必要的函数和类
        require_once CORE_DIR . '/functions/security-extend.php';
        require_once CORE_DIR . '/functions/formatting.php';
    }

    // 设置正确的Content-Type头
    header('Content-Type: text/html; charset=utf-8');

    if (isset($_GET['field_type'])) {
        $field_type = sanitize_text_field($_GET['field_type']);
        $mode = isset($_GET['mode']) ? sanitize_text_field($_GET['mode']) : 'add';

        // 获取现有配置数据（编辑模式）
        $field_options = isset($_GET['field_options']) ? sanitize_textarea_field($_GET['field_options']) : '';
        $field_values = isset($_GET['field_values']) ? sanitize_textarea_field($_GET['field_values']) : '';
        $min_value = isset($_GET['min_value']) ? sanitize_text_field($_GET['min_value']) : '';
        $max_value = isset($_GET['max_value']) ? sanitize_text_field($_GET['max_value']) : '';
        $max_length = isset($_GET['max_length']) ? sanitize_text_field($_GET['max_length']) : '';
        $max_images = isset($_GET['max_images']) ? sanitize_text_field($_GET['max_images']) : '20';
        $max_files = isset($_GET['max_files']) ? sanitize_text_field($_GET['max_files']) : '';

        $config_html = '';

        // 兼容旧数据：优先使用 field_values，其次回退到 field_options
        $option_text = ($field_values !== '') ? $field_values : $field_options;

        // 根据字段类型生成配置选项
        switch ($field_type) {
            case 'select':
            case 'radio':
            case 'checkbox':
                $config_html = '
                <div class="form-group">
                    <label for="field_options">选项设置</label>
                    <textarea id="field_options" name="field_values" rows="5" placeholder="每行一个选项，格式：值|显示文本（如：1|选项一）">' . esc_textarea($option_text) . '</textarea>
                    <p class="description">每行一个选项，格式：值|显示文本（如：1|选项一）</p>
                </div>';
                break;

            case 'number':
                $config_html = '
                <div class="form-group">
                    <label for="min_value">最小值</label>
                    <input type="number" id="min_value" name="min_value" value="' . esc_attr($min_value) . '" placeholder="允许输入的最小数值">
                    <p class="description">允许输入的最小数值</p>
                </div>
                <div class="form-group">
                    <label for="max_value">最大值</label>
                    <input type="number" id="max_value" name="max_value" value="' . esc_attr($max_value) . '" placeholder="允许输入的最大数值">
                    <p class="description">允许输入的最大数值</p>
                </div>';
                break;

            case 'text':
                $config_html = '
                <div class="form-group">
                    <label for="max_length">最大长度</label>
                    <input type="number" id="max_length" name="max_length" value="' . esc_attr($max_length) . '" placeholder="允许输入的最大字符数">
                    <p class="description">允许输入的最大字符数</p>
                </div>';
                break;

            case 'multi_file':
                $config_html = '
                <div class="form-group">
                    <label for="max_files">最大文件数量</label>
                    <input type="number" id="max_files" name="max_files" value="' . esc_attr($max_files) . '" placeholder="允许选择的最大文件数量">
                    <p class="description">允许选择的最大文件数量，留空或0表示不限制</p>
                </div>';
                break;

            case 'region':
                $config_html = '
                <div class="form-group">
                    <label>区域级别</label>
                    <select class="form-control" name="region_level">
                        <option value="1">省级</option>
                        <option value="2">省市</option>
                        <option value="3">省市区</option>
                        <option value="4">省市区乡镇</option>
                        <option value="5" selected>省市区乡镇+详细地址</option>
                    </select>
                    <p class="description">选择区域地址的详细程度（默认包含详细地址输入框）</p>
                </div>';
                break;

            default:
                $config_html = '<p class="description">此字段类型无需额外配置。</p>';
                break;
        }

        echo $config_html;
    } else {
        echo '<p class="description error">缺少字段类型参数。</p>';
    }

    exit;
}

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 检查用户权限
if (!current_user_can('manage_global_vars') && !current_user_can('manage_options')) {
    todo_die('您没有足够的权限访问此页面。');
}

// 处理POST请求 - 必须在任何输出之前
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // 验证安全性
        if (!Todo_Security_Manager::verify_request_csrf('global_vars_action', '_todononce')) {
            todo_die('安全验证失败');
        }

        if ($_POST['action'] === 'add_field') {
            // 添加新字段
            $field_name = isset($_POST['field_name']) ? sanitize_text_field($_POST['field_name']) : '';
            $field_key = isset($_POST['field_key']) ? sanitize_key($_POST['field_key']) : '';
            $field_type = isset($_POST['field_type']) ? sanitize_text_field($_POST['field_type']) : '';
            $field_description = isset($_POST['field_description']) ? sanitize_textarea_field($_POST['field_description']) : '';
            $field_default = isset($_POST['field_default']) ? $_POST['field_default'] : '';
            $field_required = isset($_POST['field_required']) ? (bool) $_POST['field_required'] : false;

            // 验证字段
            if (empty($field_name) || empty($field_key) || empty($field_type)) {
                $error_message = '字段名称、标识和类型不能为空';
            } else {
                // 检查字段标识是否已存在
                $existing_fields = get_option('global_vars_fields', array());

                // 确保$existing_fields是数组
                if (!is_array($existing_fields)) {
                    $existing_fields = array();
                }

                $field_exists = false;
                foreach ($existing_fields as $existing_field) {
                    if ($existing_field['field_key'] === $field_key) {
                        $error_message = '字段标识已存在，请使用其他标识';
                        $field_exists = true;
                        break;
                    }
                }

                if (!$field_exists) {
                    // 创建新字段
                    $new_field = array(
                        'field_name' => $field_name,
                        'field_key' => $field_key,
                        'field_type' => $field_type,
                        'field_description' => $field_description,
                        'field_default' => $field_default,
                        'field_required' => $field_required,
                        'field_status' => 'active',
                        'field_order' => count($existing_fields) + 1
                    );

                    // 添加字段特定的配置
                    if ($field_type === 'select' || $field_type === 'radio' || $field_type === 'checkbox') {
                        $field_values = isset($_POST['field_values']) ? sanitize_textarea_field($_POST['field_values']) : '';
                        $field_options = isset($_POST['field_options']) ? sanitize_textarea_field($_POST['field_options']) : '';
                        $new_field['field_values'] = $field_values;
                        $new_field['field_options'] = $field_options;
                        $new_field['multiple'] = isset($_POST['multiple']) ? (bool) $_POST['multiple'] : false;
                    } elseif ($field_type === 'number') {
                        $new_field['min_value'] = isset($_POST['min_value']) ? intval($_POST['min_value']) : null;
                        $new_field['max_value'] = isset($_POST['max_value']) ? intval($_POST['max_value']) : null;
                    } elseif ($field_type === 'text') {
                        $new_field['max_length'] = isset($_POST['max_length']) ? intval($_POST['max_length']) : null;
                    } elseif ($field_type === 'multi_file') {
                        $new_field['max_files'] = isset($_POST['max_files']) ? intval($_POST['max_files']) : 20;
                    } elseif ($field_type === 'region') {
                        $new_field['region_level'] = isset($_POST['region_level']) ? intval($_POST['region_level']) : 5;
                    }

                    // 保存字段
                    $existing_fields[] = $new_field;
                    update_option('global_vars_fields', $existing_fields);

                    // 重定向到字段列表页面
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice('字段添加成功', 'success');
                    }
                    todo_redirect(todo_admin_url('index.php?page=global-vars'));
                }
            }
        } elseif ($_POST['action'] === 'edit_field') {
            // 编辑字段逻辑
            $field_index = isset($_POST['field_index']) ? intval($_POST['field_index']) : -1;
            $existing_fields = get_option('global_vars_fields', array());

            if ($field_index >= 0 && isset($existing_fields[$field_index])) {
                $field_name = isset($_POST['field_name']) ? sanitize_text_field($_POST['field_name']) : '';
                $field_key = isset($_POST['field_key']) ? sanitize_key($_POST['field_key']) : '';
                $field_type = isset($_POST['field_type']) ? sanitize_text_field($_POST['field_type']) : '';
                $field_description = isset($_POST['field_description']) ? sanitize_textarea_field($_POST['field_description']) : '';
                $field_default = isset($_POST['field_default']) ? $_POST['field_default'] : '';
                $field_required = isset($_POST['field_required']) ? (bool) $_POST['field_required'] : false;

                if (!empty($field_name) && !empty($field_key) && !empty($field_type)) {
                    $existing_fields[$field_index] = array(
                        'field_name' => $field_name,
                        'field_key' => $field_key,
                        'field_type' => $field_type,
                        'field_description' => $field_description,
                        'field_default' => $field_default,
                        'field_required' => $field_required,
                        'field_status' => isset($existing_fields[$field_index]['field_status']) ? $existing_fields[$field_index]['field_status'] : 'active',
                        'field_order' => $existing_fields[$field_index]['field_order']
                    );

                    // 添加字段特定的配置
                    if ($field_type === 'select' || $field_type === 'radio' || $field_type === 'checkbox') {
                        $field_values = isset($_POST['field_values']) ? sanitize_textarea_field($_POST['field_values']) : '';
                        $field_options = isset($_POST['field_options']) ? sanitize_textarea_field($_POST['field_options']) : '';
                        $existing_fields[$field_index]['field_values'] = $field_values;
                        $existing_fields[$field_index]['field_options'] = $field_options;
                        $existing_fields[$field_index]['multiple'] = isset($_POST['multiple']) ? (bool) $_POST['multiple'] : false;
                    } elseif ($field_type === 'number') {
                        $existing_fields[$field_index]['min_value'] = isset($_POST['min_value']) ? intval($_POST['min_value']) : null;
                        $existing_fields[$field_index]['max_value'] = isset($_POST['max_value']) ? intval($_POST['max_value']) : null;
                    } elseif ($field_type === 'text') {
                        $existing_fields[$field_index]['max_length'] = isset($_POST['max_length']) ? intval($_POST['max_length']) : null;
                    } elseif ($field_type === 'multi_file') {
                        $existing_fields[$field_index]['max_files'] = isset($_POST['max_files']) ? intval($_POST['max_files']) : 20;
                    } elseif ($field_type === 'region') {
                        $existing_fields[$field_index]['region_level'] = isset($_POST['region_level']) ? intval($_POST['region_level']) : 5;
                    }

                    update_option('global_vars_fields', $existing_fields);
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice('字段更新成功', 'success');
                    }
                    todo_redirect(todo_admin_url('index.php?page=global-vars'));
                }
            }
        } elseif ($_POST['action'] === 'trash_field' || $_POST['action'] === 'delete_field_confirm') {
            // 移至回收站逻辑
            $field_index = isset($_POST['field_index']) ? intval($_POST['field_index']) : (!empty($_POST['field_id']) ? intval($_POST['field_id']) : -1);
            $existing_fields = get_option('global_vars_fields', array());

            if ($field_index >= 0 && isset($existing_fields[$field_index])) {
                $existing_fields[$field_index]['field_status'] = 'trash';
                $existing_fields[$field_index]['trashed_at'] = date('Y-m-d H:i:s');
                update_option('global_vars_fields', $existing_fields);
            }
        } elseif ($_POST['action'] === 'update_values') {
            // 更新字段值
            $fields = get_option('global_vars_fields', array());
            $global_vars = get_option('global_vars', array());

            // 创建字段生成器实例
            $field_generator = new CMS_Field_Generator($fields, $global_vars);

            // 验证和处理表单数据
            $validation = $field_generator->validate_form_data($_POST);

            if ($validation['success']) {
                // 格式化字段值
                $formatted_values = $field_generator->format_values($_POST);

                // 统一应用规则2：入库数据清洗，剥离子目录前缀与全路径，保持 /content/uploads/... 入库
                if (is_array($formatted_values) && function_exists('todo_media_url_to_relative')) {
                    array_walk_recursive($formatted_values, function (&$v) {
                        if (is_string($v) && $v !== '') {
                            if (strpos($v, '<') !== false && function_exists('todo_convert_content_urls_to_relative')) {
                                $v = todo_convert_content_urls_to_relative($v);
                            } elseif ($v[0] === '{' || $v[0] === '[') {
                                $decoded = json_decode($v, true);
                                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                    array_walk_recursive($decoded, function (&$jv) {
                                        if (is_string($jv) && (strpos($jv, '/content/') !== false || strpos($jv, 'content/uploads/') !== false || preg_match('#^https?://#i', $jv))) {
                                            $jv = todo_media_url_to_relative($jv);
                                        }
                                    });
                                    $v = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                                }
                            } elseif (strpos($v, '/content/') !== false || strpos($v, 'content/uploads/') !== false || preg_match('#^https?://#i', $v)) {
                                $v = todo_media_url_to_relative($v);
                            }
                        }
                    });
                }

                // 保存字段值
                update_option('global_vars', $formatted_values);

                if (function_exists('todo_add_admin_notice')) {
                    todo_add_admin_notice('字段值已更新', 'success');
                }
                todo_redirect(todo_admin_url('index.php?page=global-vars'));
            } else {
                $error_message = '表单验证失败：' . implode(', ', $validation['errors']);
            }
        }
    }
}

/**
 * 全局变量管理页面
 *
 * 提供查看和管理全局变量的功能
 *
 * @package TodoCMS
 * @version 1.0.0
 */

$page_title = '全局变量管理';

if (!defined('ADMIN_URL')) {
    define('ADMIN_URL', (defined('TODO_URL') ? TODO_URL : (function_exists('todo_get_site_url') ? todo_get_site_url() : '')) . '/admin');
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
        'radio' => ['key' => 'radio', 'name' => '单选项', 'description' => '单选按钮组'],
        'checkbox' => ['key' => 'checkbox', 'name' => '多选项', 'description' => '复选框组'],
        'multi_file' => ['key' => 'multi_file', 'name' => '图片/文件', 'description' => '上传和选择多个文件'],
        'region' => ['key' => 'region', 'name' => '地区', 'description' => '省市区三级联动选择'],
        'switch' => ['key' => 'switch', 'name' => '开关', 'description' => '开关切换按钮'],
    ];
}

// 获取操作类型
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// 获取字段索引（用于编辑）
$field_index = isset($_GET['field_index']) ? intval($_GET['field_index']) : -1;

// 获取字段列表
$all_fields = get_option('global_vars_fields', array());

// 确保$all_fields是数组
if (!is_array($all_fields)) {
    $all_fields = array();
}

// 过滤掉已移至回收站的字段 (渲染和管理时默认仅显示活动字段)
$fields = array_filter($all_fields, function($field) {
    return !isset($field['field_status']) || $field['field_status'] === 'active';
});

// 获取全局变量值
$global_vars = get_option('global_vars', array());

if (isset($error_message)) {
    echo '<div class="notice notice-error is-dismissible"><p>' . $error_message . '</p><button type="button" class="notice-dismiss">✕</button></div>';
}

$todo_global_vars_has_rich_editor = false;

// 显示页面内容
switch ($action) {
    case 'add_field':
        // 添加字段表单
        ?>
        <form method="post" action="<?php echo todo_admin_url('index.php?page=global-vars'); ?>" class="todo-request">
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">添加全局变量字段</h3>
                </div>
                <div class="admin-card-content">
                    <?php echo Todo_Security_Manager::csrf_field('global_vars_action', '_todononce'); ?>
                    <input type="hidden" name="action" value="add_field">

                    <div class="form-group">
                        <label for="field_name">字段名称 <span class="required">*</span></label>
                        <input type="text" id="field_name" name="field_name" value="" required>
                        <p class="description">字段的显示名称，如"标题"、"内容"等</p>
                    </div>

                    <div class="form-group">
                        <label for="field_key">字段标识 <span class="required">*</span></label>
                        <input type="text" id="field_key" name="field_key" value="" required>
                        <p class="description">字段的唯一标识，仅允许小写字母、数字和下划线，如"title"、"content"等</p>
                    </div>

                    <div class="form-group">
                        <label>字段类型 <span class="required">*</span></label>
                        <p class="description" style="margin-bottom: 15px;">选择字段的数据类型</p>
                        <div class="field-type-options">
                            <div class="field-type-grid">
                                <?php foreach ($field_types as $type_key => $type_info): ?>
                                    <div class="field-type-option">
                                        <label>
                                            <input type="radio" name="field_type" value="<?php echo esc_attr($type_key); ?>"
                                                required>
                                            <div class="field-type-info">
                                                <strong><?php echo esc_html(is_array($type_info) ? $type_info['name'] : $type_info); ?></strong>
                                                <small><?php echo esc_html(is_array($type_info) ? ($type_info['description'] ?? '') : ''); ?></small>
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
                    <h3>字段配置</h3>
                </div>
                <div class="admin-card-content" id="add-field-type-config">
                    <!-- 字段类型特定的配置选项将在这里动态加载 -->
                    <div class="loading">正在加载配置选项...</div>
                </div>
            </div>

            <div class="admin-card">
                <div class="admin-card-header">
                    <h3>默认设置</h3>
                </div>
                <div class="admin-card-content">
                    <div class="form-group">
                        <label for="field_description">字段描述</label>
                        <textarea id="field_description" name="field_description" rows="3"></textarea>
                        <p class="description">字段下方的简要描述，用于帮助用户理解字段用途</p>
                    </div>

                    <div class="form-group">
                        <label for="field_default">默认值</label>
                        <input type="text" id="field_default" name="field_default" value="">
                        <p class="description">字段的默认值，新建内容时将自动填入此值</p>
                    </div>

                    <div class="form-group">
                        <label for="field_required">
                            <input type="checkbox" id="field_required" name="field_required" value="1">
                            必填字段
                        </label>
                        <p class="description">勾选此项表示此字段为必填项，用户必须填写才能保存</p>
                    </div>
                </div>
            </div>

            <div class="admin-form-submit">
                <a href="<?php echo todo_admin_url('index.php?page=global-vars'); ?>" class="button button-secondary">取消</a>
                <button type="submit" class="button button-primary">添加字段</button>
            </div>
        </form>

        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function () {
                if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils && TodoCMS.Utils.initSlugGenerator) {
                    TodoCMS.Utils.initSlugGenerator({
                        nameInputId: 'field_name',
                        slugInputId: 'field_key',
                        ajaxAction: 'slug_generator',
                        id: -1,
                        nameParam: 'name',
                        idParam: 'id',
                        extraParams: {
                            type: 'global_var_field'
                        }
                    });
                }

                var fieldTypeRadios = document.querySelectorAll('input[name="field_type"]');
                var fieldOptionsDiv = document.getElementById('field_options_container');
                var fieldMultipleDiv = document.getElementById('field_multiple_container');

                function toggleOptionBlocks() {
                    var fieldType = '';
                    for (var i = 0; i < fieldTypeRadios.length; i++) {
                        if (fieldTypeRadios[i].checked) {
                            fieldType = fieldTypeRadios[i].value;
                            break;
                        }
                    }

                    if (fieldType === 'select' || fieldType === 'radio' || fieldType === 'checkbox') {
                        if (fieldOptionsDiv) fieldOptionsDiv.style.display = 'block';
                        if (fieldMultipleDiv) {
                            if (fieldType === 'select' || fieldType === 'checkbox') {
                                fieldMultipleDiv.style.display = 'block';
                            } else {
                                fieldMultipleDiv.style.display = 'none';
                            }
                        }
                    } else {
                        if (fieldOptionsDiv) fieldOptionsDiv.style.display = 'none';
                        if (fieldMultipleDiv) fieldMultipleDiv.style.display = 'none';
                    }

                    // 更新字段配置
                    updateAddFieldConfig(fieldType);
                }

                // 动态加载字段配置选项
                function updateAddFieldConfig(fieldType) {
                    var configContainer = document.getElementById('add-field-type-config');
                    if (!configContainer) return;

                    configContainer.innerHTML = '<div class="loading">正在加载配置选项...</div>';

                    // 构建请求URL，保持当前页面的format参数
                    var baseUrl = 'index.php?page=global-vars&action=get_field_config&field_type=' + encodeURIComponent(fieldType);
                    var currentUrl = window.location.search;
                    if (currentUrl.indexOf('format=iframe') !== -1 && baseUrl.indexOf('format=iframe') === -1) {
                        baseUrl += '&format=iframe';
                    }

                    var xhr = new XMLHttpRequest();
                    xhr.open('GET', baseUrl, true);
                    xhr.onreadystatechange = function () {
                        if (xhr.readyState === 4) {
                            if (xhr.status === 200) {
                                configContainer.innerHTML = xhr.responseText;
                            } else {
                                configContainer.innerHTML = '<p class="description error">加载配置选项失败。</p>';
                            }
                        }
                    };
                    xhr.send();
                }

                if (fieldTypeRadios.length > 0) {
                    for (var i = 0; i < fieldTypeRadios.length; i++) {
                        fieldTypeRadios[i].addEventListener('change', toggleOptionBlocks);
                    }
                    var hasChecked = false;
                    for (var j = 0; j < fieldTypeRadios.length; j++) {
                        if (fieldTypeRadios[j].checked) { hasChecked = true; break; }
                    }
                    if (!hasChecked) { fieldTypeRadios[0].checked = true; }
                    toggleOptionBlocks();
                }

                // 快速添加选项 - 新增模式
                var addBtn = document.getElementById('add-option-btn');
                var input = document.getElementById('new-option-input');
                var textarea = document.getElementById('field_values');
                if (addBtn && input && textarea) {
                    addBtn.addEventListener('click', function () {
                        var val = (input.value || '').trim();
                        if (!val) return;
                        var prefix = textarea.value && textarea.value.trim().length > 0 ? '\n' : '';
                        textarea.value = textarea.value + prefix + val;
                        input.value = '';
                        input.focus();
                    });
                }
            });
        </script>
        <?php
        break;

    case 'edit_field':
        // 编辑字段表单
        if ($field_index >= 0 && isset($fields[$field_index])) {
            $field = $fields[$field_index];
            ?>
            <form method="post" action="<?php echo todo_admin_url('index.php?page=global-vars'); ?>" class="todo-request">
                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">编辑全局变量字段</h3>
                    </div>
                    <div class="admin-card-content">
                        <?php echo Todo_Security_Manager::csrf_field('global_vars_action', '_todononce'); ?>
                        <input type="hidden" name="action" value="edit_field">
                        <input type="hidden" name="field_index" value="<?php echo $field_index; ?>">

                        <div class="form-group">
                            <label for="field_name">字段名称 <span class="required">*</span></label>
                            <input type="text" id="field_name" name="field_name"
                                value="<?php echo todo_esc_attr($field['field_name']); ?>" required>
                            <p class="description">字段的显示名称，如"标题"、"内容"等</p>
                        </div>

                        <div class="form-group">
                            <label for="field_key">字段标识 <span class="required">*</span></label>
                            <input type="text" id="field_key" name="field_key"
                                value="<?php echo todo_esc_attr($field['field_key']); ?>" required>
                            <p class="description">字段的唯一标识，仅允许小写字母、数字和下划线，如"title"、"content"等</p>
                        </div>

                        <div class="form-group">
                            <label>字段类型 <span class="required">*</span></label>
                            <p class="description" style="margin-bottom: 15px;">选择字段的数据类型</p>
                            <div class="field-type-options">
                                <div class="field-type-grid">
                                    <?php foreach ($field_types as $type_key => $type_info): ?>
                                        <div class="field-type-option">
                                            <label>
                                                <input type="radio" name="field_type" value="<?php echo esc_attr($type_key); ?>" <?php checked($field['field_type'], $type_key); ?> required>
                                                <div class="field-type-info">
                                                    <strong><?php echo esc_html(is_array($type_info) ? $type_info['name'] : $type_info); ?></strong>
                                                    <small><?php echo esc_html(is_array($type_info) ? ($type_info['description'] ?? '') : ''); ?></small>
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
                        <h3 class="admin-card-title">字段配置</h3>
                    </div>
                    <div class="admin-card-content" id="edit-field-type-config">
                        <!-- 字段类型特定的配置选项将在这里动态加载 -->
                        <div class="loading">正在加载配置选项...</div>
                    </div>
                </div>

                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">默认设置</h3>
                    </div>
                    <div class="admin-card-content">
                        <div class="form-group">
                            <label for="field_description">字段描述</label>
                            <textarea id="field_description" name="field_description"
                                rows="3"><?php echo esc_textarea($field['field_description']); ?></textarea>
                            <p class="description">字段下方的简要描述，用于帮助用户理解字段用途</p>
                        </div>

                        <div class="form-group">
                            <label for="field_default">默认值</label>
                            <input type="text" id="field_default" name="field_default"
                                value="<?php echo esc_attr($field['field_default'] ?? ''); ?>">
                            <p class="description">字段的默认值，新建内容时将自动填入此值</p>
                        </div>

                        <div class="form-group">
                            <label for="field_required">
                                <input type="checkbox" id="field_required" name="field_required" value="1" <?php checked(isset($field['field_required']) && $field['field_required']); ?>>
                                必填字段
                            </label>
                            <p class="description">勾选此项表示此字段为必填项，用户必须填写才能保</p>
                        </div>
                    </div>
                </div>

                <div class="admin-form-submit">
                    <a href="<?php echo todo_admin_url('index.php?page=global-vars'); ?>" class="button button-secondary">取消</a>
                    <button type="submit" class="button button-primary">更新字段</button>
                </div>
            </form>

            <script type="text/javascript">
                document.addEventListener('DOMContentLoaded', function () {
                    if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils && TodoCMS.Utils.initSlugGenerator) {
                        TodoCMS.Utils.initSlugGenerator({
                            nameInputId: 'field_name',
                            slugInputId: 'field_key',
                            ajaxAction: 'slug_generator',
                            id: <?php echo intval($field_index); ?>,
                            nameParam: 'name',
                            idParam: 'id',
                            extraParams: {
                                type: 'global_var_field'
                            }
                        });
                    }

                    var fieldTypeRadios = document.querySelectorAll('input[name="field_type"]');
                    var fieldOptionsDiv = document.getElementById('edit_field_options_container');
                    var fieldMultipleDiv = document.getElementById('edit_field_multiple_container');

                    function toggleOptionBlocks() {
                        var fieldType = '';
                        for (var i = 0; i < fieldTypeRadios.length; i++) {
                            if (fieldTypeRadios[i].checked) {
                                fieldType = fieldTypeRadios[i].value;
                                break;
                            }
                        }

                        if (fieldType === 'select' || fieldType === 'radio' || fieldType === 'checkbox') {
                            if (fieldOptionsDiv) fieldOptionsDiv.style.display = 'block';
                            if (fieldMultipleDiv) {
                                if (fieldType === 'select' || fieldType === 'checkbox') {
                                    fieldMultipleDiv.style.display = 'block';
                                } else {
                                    fieldMultipleDiv.style.display = 'none';
                                }
                            }
                        } else {
                            if (fieldOptionsDiv) fieldOptionsDiv.style.display = 'none';
                            if (fieldMultipleDiv) fieldMultipleDiv.style.display = 'none';
                        }

                        // 更新字段配置
                        updateEditFieldConfig(fieldType);
                    }

                    // 动态加载字段配置选项
                    function updateEditFieldConfig(fieldType) {
                        var configContainer = document.getElementById('edit-field-type-config');
                        if (!configContainer) return;

                        configContainer.innerHTML = '<div class="loading">正在加载配置选项...</div>';

                        // 获取当前字段的配置数据
                        var fieldData = <?php echo json_encode($field); ?>;
                        var params = 'field_type=' + encodeURIComponent(fieldType) + '&mode=edit';

                        // 添加现有配置数据
                        if (fieldData.field_options) params += '&field_options=' + encodeURIComponent(fieldData.field_options);
                        if (fieldData.field_values) params += '&field_values=' + encodeURIComponent(fieldData.field_values);
                        if (fieldData.min_value !== undefined) params += '&min_value=' + encodeURIComponent(fieldData.min_value);
                        if (fieldData.max_value !== undefined) params += '&max_value=' + encodeURIComponent(fieldData.max_value);
                        if (fieldData.max_length !== undefined) params += '&max_length=' + encodeURIComponent(fieldData.max_length);
                        if (fieldData.max_files !== undefined) params += '&max_files=' + encodeURIComponent(fieldData.max_files);

                        // 保持当前页面的format参数
                        var currentUrl = window.location.search;
                        if (currentUrl.indexOf('format=iframe') !== -1) {
                            params += '&format=iframe';
                        }

                        var xhr = new XMLHttpRequest();
                        xhr.open('GET', 'index.php?page=global-vars&action=get_field_config&' + params, true);
                        xhr.onreadystatechange = function () {
                            if (xhr.readyState === 4) {
                                if (xhr.status === 200) {
                                    configContainer.innerHTML = xhr.responseText;
                                } else {
                                    configContainer.innerHTML = '<p class="description error">加载配置选项失败。</p>';
                                }
                            }
                        };
                        xhr.send();
                    }

                    if (fieldTypeRadios.length > 0) {
                        for (var i = 0; i < fieldTypeRadios.length; i++) {
                            fieldTypeRadios[i].addEventListener('change', toggleOptionBlocks);
                        }
                        var hasChecked = false;
                        for (var j = 0; j < fieldTypeRadios.length; j++) {
                            if (fieldTypeRadios[j].checked) { hasChecked = true; break; }
                        }
                        if (!hasChecked && fieldTypeRadios[0]) { fieldTypeRadios[0].checked = true; }
                        toggleOptionBlocks();
                    }

                    // 快速添加选项 - 编辑模式
                    var editAddBtn = document.getElementById('edit-add-option-btn');
                    var editInput = document.getElementById('edit-new-option-input');
                    var editTextarea = document.getElementById('field_values');
                    if (editAddBtn && editInput && editTextarea) {
                        editAddBtn.addEventListener('click', function () {
                            var val = (editInput.value || '').trim();
                            if (!val) return;
                            var prefix = editTextarea.value && editTextarea.value.trim().length > 0 ? '\n' : '';
                            editTextarea.value = editTextarea.value + prefix + val;
                            editInput.value = '';
                            editInput.focus();
                        });
                    }
                });
            </script>
            <?php
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>字段不存在</p><button type="button" class="notice-dismiss">✕</button></div>';
            echo '<div class="admin-form-submit"><a href="' . todo_admin_url('index.php?page=global-vars') . '" class="button">返回字段列表</a></div>';
        }
        break;

    default:
        // 字段列表和值编辑
        ?>
        <div class="admin-card">
            <div class="admin-card-header no-border">
                <h3 class="admin-card-title">自定义全局变量管理</h3>
                <div class="admin-card-actions">
                    <a href="<?php echo todo_admin_url('index.php?page=global-vars&action=add_field'); ?>" class="button button-primary">添加字段</a>
                </div>
            </div>
            <div class="admin-card-content">
                <?php if (empty($fields)): ?>
                    <div class="no-items">
                        <p>暂无全局变量字段，请点击"添加字段"按钮创建。</p>
                    </div>
                <?php else: ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th style="min-width: 120px;">字段名称</th>
                                <th>字段标识</th>
                                <th>字段类型</th>
                                <th style="min-width: 180px;">描述</th>
                                <th style="width: 130px; min-width: 130px;">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fields as $idx => $field): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($field['field_name']); ?></strong></td>
                                    <td><?php echo esc_html($field['field_key']); ?></td>
                                    <td>
                                        <div class="tb-description">
                                            <?php echo esc_html(isset($field_types[$field['field_type']]) ? $field_types[$field['field_type']]['name'] : $field['field_type']); ?>
                                        </div>
                                    </td>
                                    <td><?php echo esc_html($field['field_description']); ?></td>
                                    <td>
                                        <div class="row-actions">
                                            <span class="edit"><a href="<?php echo todo_admin_url('index.php?page=global-vars&action=edit_field&field_index=' . $idx); ?>">编辑</a> | </span>
                                            <span class="trash">
                                                <a href="javascript:void(0);" class="submitdelete js-trash-global-var" data-field-index="<?php echo esc_attr($idx); ?>" data-field-name="<?php echo esc_attr($field['field_name']); ?>">移至回收站</a>
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($fields)): ?>
            <?php
            foreach ($fields as $field) {
                $ft = $field['field_type'] ?? '';
                if ($ft === 'editor' || $ft === 'html') {
                    $todo_global_vars_has_rich_editor = true;
                    break;
                }
            }
            ?>
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">变量设置</h3>
                </div>
                <div class="admin-card-content">
                    <form method="post" action="<?php echo todo_admin_url('index.php?page=global-vars'); ?>" class="todo-request">
                        <?php echo Todo_Security_Manager::csrf_field('global_vars_action', '_todononce'); ?>
                        <input type="hidden" name="action" value="update_values">

                        <?php
                        // 确保字段和值都是数组
                        if (!function_exists('todo_clean_multi_file_value')) {
                            function todo_clean_multi_file_value($value, $max_files = null)
                            {
                                // 将各种可能的输入规范化为逗号分隔的文件ID字符串
                                $ids = [];
                                if (is_string($value)) {
                                    $value = trim($value);
                                    // 尝试JSON
                                    if ($value !== '' && ($value[0] === '[' || $value[0] === '{')) {
                                        $decoded = json_decode($value, true);
                                        if (json_last_error() === JSON_ERROR_NONE) {
                                            if (is_array($decoded)) {
                                                foreach ($decoded as $v) {
                                                    if (is_numeric($v)) {
                                                        $ids[] = (int) $v;
                                                    } elseif (is_array($v) && isset($v['id']) && is_numeric($v['id'])) {
                                                        // 处理包含文件对象的JSON数组
                                                        $ids[] = (int) $v['id'];
                                                    }
                                                }
                                            }
                                        }
                                    } elseif (preg_match('/^\s*\d+(?:\s*,\s*\d+)*\s*$/', $value)) {
                                        foreach (explode(',', $value) as $v) {
                                            $v = trim($v);
                                            if ($v !== '' && ctype_digit($v)) {
                                                $ids[] = (int) $v;
                                            }
                                        }
                                    } elseif (ctype_digit($value)) {
                                        $ids[] = (int) $value;
                                    }
                                } elseif (is_array($value)) {
                                    foreach ($value as $v) {
                                        if (is_numeric($v)) {
                                            $ids[] = (int) $v;
                                        }
                                    }
                                } elseif (is_numeric($value)) {
                                    $ids[] = (int) $value;
                                }
                                // 去重、去0、限制数量
                                $ids = array_values(array_filter(array_unique($ids), function ($n) {
                                    return $n > 0;
                                }));
                                if (is_int($max_files) && $max_files > 0) {
                                    $ids = array_slice($ids, 0, $max_files);
                                }
                                return empty($ids) ? '' : implode(',', $ids);
                            }
                        }
                        if (is_array($fields) && is_array($global_vars)) {
                            // 数据修复：确保全局变量键名与字段配置一致
                            $corrected_global_vars = [];
                            $field_keys_set = [];

                            foreach ($fields as $field) {
                                if (isset($field['field_key'])) {
                                    $field_key = $field['field_key'];
                                    $field_type = $field['field_type'] ?? '';
                                    $field_keys_set[$field_key] = true;

                                    $field_value = isset($global_vars[$field_key]) ? $global_vars[$field_key] : null;

                                    // 对 multi_file 字段进行特殊的值格式验证和清理
                                    if ($field_type === 'multi_file' && $field_value !== null) {
                                        $max_files = isset($field['max_files']) && ctype_digit((string) $field['max_files']) ? (int) $field['max_files'] : null;
                                        $field_value = todo_clean_multi_file_value($field_value, $max_files);
                                    }

                                    $corrected_global_vars[$field_key] = $field_value;
                                }
                            }

                            // 保留不在字段配置中的其他全局变量
                            foreach ($global_vars as $key => $value) {
                                if (!isset($field_keys_set[$key])) {
                                    $corrected_global_vars[$key] = $value;
                                }
                            }

                            // 创建字段生成器实例
                            // 统一应用规则3：后端显示解析包装，解析拼接子目录前缀 /todocms/content/... 供后端回填显示使用
                            if (is_array($corrected_global_vars) && function_exists('todo_media_url_to_absolute')) {
                                array_walk_recursive($corrected_global_vars, function (&$v) {
                                    if (is_string($v) && $v !== '') {
                                        if (strpos($v, '<') !== false && function_exists('todo_convert_content_urls_to_absolute')) {
                                            $v = todo_convert_content_urls_to_absolute($v);
                                        } elseif ($v[0] === '{' || $v[0] === '[') {
                                            $decoded = json_decode($v, true);
                                            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                                array_walk_recursive($decoded, function (&$jv) {
                                                    if (is_string($jv) && (strpos($jv, '/content/') !== false || strpos($jv, 'content/uploads/') !== false)) {
                                                        $jv = todo_media_url_to_absolute($jv);
                                                    }
                                                });
                                                $v = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                                            }
                                        } elseif (strpos($v, '/content/') !== false || strpos($v, 'content/uploads/') !== false) {
                                            $v = todo_media_url_to_absolute($v);
                                        }
                                    }
                                });
                            }

                            $field_generator = new CMS_Field_Generator($fields, $corrected_global_vars);

                            // 生成字段HTML
                            echo $field_generator->generate();
                        } else {
                            echo '<div class="notice notice-error is-dismissible"><p>字段数据格式错误，无法生成表单。</p><button type="button" class="notice-dismiss">✕</button></div>';
                            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                                echo '<pre>字段数据: ' . print_r($fields, true) . '</pre>';
                                echo '<pre>值数据: ' . print_r($global_vars, true) . '</pre>';
                            }
                        }
                        ?>

                        <div class="form-submit">
                            <button type="submit" class="button button-primary">保存</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">说明</h3>
                </div>
                <div class="admin-card-content">
                    <div class="form-group">
                        <p>全局变量可以在模板中使用以下函数获取：</p>
                        <pre><code>前端模版任意位置使用 {{todo:global_var name='字段标识'}}</code></pre>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <?php
        break;
}
?>

<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        var trashNonce = '<?php echo esc_js(todo_create_nonce('global_vars_action')); ?>';

        document.addEventListener('click', function (e) {
            var link = e.target && e.target.closest ? e.target.closest('a.js-trash-global-var') : null;
            if (!link) return;

            e.preventDefault();
            e.stopPropagation();

            var fieldIndex = link.getAttribute('data-field-index') || '';
            var fieldName = link.getAttribute('data-field-name') || '';
            if (fieldIndex === '') return;

            var submitForm = function () {
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = '<?php echo todo_admin_url('index.php?page=global-vars'); ?>';
                form.className = 'todo-request';

                var actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'trash_field';
                form.appendChild(actionInput);

                var idxInput = document.createElement('input');
                idxInput.type = 'hidden';
                idxInput.name = 'field_index';
                idxInput.value = fieldIndex;
                form.appendChild(idxInput);

                var nonceInput = document.createElement('input');
                nonceInput.type = 'hidden';
                nonceInput.name = '_todononce';
                nonceInput.value = trashNonce;
                form.appendChild(nonceInput);

                document.body.appendChild(form);
                form.submit();
            };

            if (window.TodoModal && typeof window.TodoModal.deleteConfirm === 'function') {
                TodoModal.deleteConfirm({
                    title: '移至回收站',
                    message: fieldName ? ('确定将 \"' + fieldName + '\" 移至回收站吗？') : '确定将该字段移至回收站吗？',
                    itemInfo: fieldName ? { '变量名称': fieldName } : null,
                    confirmText: '移至回收站',
                    onConfirm: submitForm
                });
                return;
            }

            if (window.confirm(fieldName ? ('确定将 \"' + fieldName + '\" 移至回收站吗？') : '确定将该字段移至回收站吗？')) {
                submitForm();
            }
        }, true);
    });
</script>

<?php if ($todo_global_vars_has_rich_editor): ?>
    <?php
    // 引入并渲染媒体模态框组件，用于 UEditor 的多图上传功能
    require_once ADMIN_DIR . '/components/media-modal.php';
    echo TodoMediaModal::render('global-vars-media-modal');

    todo_enqueue_script('ueditor-config', todo_admin_url('assets/js/ueditor/ueditor.config.js'), array(), '1.0');
    todo_enqueue_script('ueditor-all', todo_admin_url('assets/js/ueditor/ueditor.all.js'), array('ueditor-config'), '1.0');
    todo_enqueue_script('ueditor-lang', todo_admin_url('assets/js/ueditor/lang/zh-cn/zh-cn.js'), array('ueditor-all'), '1.0');
    todo_print_scripts(false);
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            if (!window.UE || !UE.getEditor) {
                return;
            }

            if (typeof window.OpenImagesList !== 'function') {
                window.OpenImagesList = function (event, element, multiple = true) {
                    if (event) {
                        event.preventDefault();
                        event.stopPropagation();
                    }

                    var isMultiple = !!multiple;

                    var editorInstance = window.currentUE || null;
                    if (!editorInstance && window.UE && UE.instances) {
                        for (var k in UE.instances) {
                            if (Object.prototype.hasOwnProperty.call(UE.instances, k)) {
                                editorInstance = UE.getEditor(k);
                                break;
                            }
                        }
                    }

                    if (!editorInstance || !editorInstance.execCommand) {
                        return false;
                    }

                    var selectMediaForField = window.selectMediaForField;
                    if (typeof selectMediaForField !== 'function' && window.parent && window.parent !== window) {
                        try {
                            if (typeof window.parent.selectMediaForField === 'function') {
                                selectMediaForField = window.parent.selectMediaForField;
                            }
                        } catch (e) {
                        }
                    }

                    if (typeof selectMediaForField !== 'function') {
                        try {
                            editorInstance.execCommand('insertimage');
                        } catch (e) {
                        }
                        return false;
                    }

                    selectMediaForField({
                        title: isMultiple ? '选择多张图片' : '选择图片',
                        button_text: isMultiple ? '插入图片' : '插入图片',
                        multiple: isMultiple,
                        type: 'image',
                        callback: function (selectedMedia) {
                            if (!editorInstance || !editorInstance.execCommand) {
                                return;
                            }

                            var html = '';
                            var mediaList = Array.isArray(selectedMedia) ? selectedMedia : (selectedMedia ? [selectedMedia] : []);
                            mediaList.forEach(function (media) {
                                if (media && media.url) {
                                    if (typeof window.formatMediaHtmlForEditor === 'function') {
                                        html += window.formatMediaHtmlForEditor(media);
                                    } else {
                                        var title = media.title || media.alt || media.filename || media.name || '';
                                        var isImage = (media.type && media.type.indexOf('image/') === 0) || /\.(jpg|jpeg|png|gif|webp|bmp|svg)$/i.test(media.url || '');
                                        if (isImage) {
                                            html += '<img src="' + media.url + '" alt="' + title + '" title="' + title + '" />';
                                        } else {
                                            html += '<a href="' + media.url + '" rel="nofollow" target="_blank" title="' + title + '">' + (title || media.url) + '</a>';
                                        }
                                    }
                                }
                            });
                            if (html) {
                                editorInstance.execCommand('inserthtml', html);
                            }
                        }
                    });

                    return false;
                };
            }

            var textareas = document.querySelectorAll('textarea.todo-editor, textarea.html-editor');
            if (!textareas || textareas.length === 0) {
                return;
            }

            for (var i = 0; i < textareas.length; i++) {
                var textarea = textareas[i];
                var id = textarea.getAttribute('id');
                if (!id) {
                    continue;
                }

                if (UE.instances && UE.instances[id]) {
                    continue;
                }

                var editor = UE.getEditor(id, {
                    initialFrameHeight: 300,
                    autoHeightEnabled: true,
                    elementPathEnabled: false,
                    wordCount: true,
                    serverUrl: ((window.TODO && window.TODO.join) ? window.TODO.join(window.TODO.ADMIN_BASE, 'assets/js/ueditor/php/controller.php') : '/admin/assets/js/ueditor/php/controller.php')
                });

                if (editor && typeof editor.ready === 'function') {
                    (function (ed) {
                        ed.ready(function () {
                            window.currentUE = ed;
                            try {
                                ed.addListener('focus', function () { window.currentUE = ed; });
                                ed.addListener('click', function () { window.currentUE = ed; });
                                ed.addListener('selectionchange', function () { window.currentUE = ed; });
                            } catch (e) {
                            }
                        });
                    })(editor);
                }
            }
        });
    </script>
<?php endif; ?>

<!-- 特色图像组件初始化脚本 -->
<script type="text/javascript">
    jQuery(document).ready(function ($) {
        // 特色图像组件实例初始化
        if (typeof window.FeaturedImagesComponent !== "undefined") {
            // 查找所有需要初始化的特色图像组件
            $('.featured-images-component').each(function () {
                var $container = $(this);
                var fieldId = $container.data('field-id');
                var fieldName = $container.data('field-name');
                var multiple = $container.data('multiple') || true;
                var maxImages = $container.data('max-images') || 20;
                var fileType = $container.data('file-type') || 'any';
                var variant = $container.data('variant') || 'universal';

                // 初始化特色图片组件
                var featuredImagesConfig = {
                    fieldId: fieldId,
                    fieldName: fieldName,
                    multiple: multiple,
                    maxImages: maxImages,
                    enableDebug: false,
                    fileType: fileType,
                    variant: variant
                };

                var featuredImagesComponent = new window.FeaturedImagesComponent(featuredImagesConfig);
                // 组件构造函数中已自动调用init()，无需重复调用
            });
        }
    });
</script>
