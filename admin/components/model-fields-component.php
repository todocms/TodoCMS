<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../');
}

/**
 * 根据模型键获取字段HTML
 * @param string $model_key 模型键
 * @param array $field_values 字段值数组
 * @param int $post_id 文章ID
 * @return string HTML字符串
 */
function get_model_fields_html_by_model($model_key, $field_values = array(), $post_id = 0, $show_title = true, $category_ids = array())
{
    if (empty($model_key)) {
        return '';
    }

    $html = '<div class="model-fields-group" data-model="' . esc_attr($model_key) . '">';
    if ($show_title) {
        $html .= '<h4 class="model-title">' . get_model_display_name($model_key) . ' 字段</h4>';
    }

    // 先渲染特色字段（固定字段）
    $featured_fields_html = get_model_featured_fields_html($model_key, $field_values, $post_id);
    if (!empty($featured_fields_html)) {
        $html .= $featured_fields_html;
    }

    // 再渲染自定义字段
    // 注意：根据可见性要求，只有与当前栏目关联并可见的字段才显示
    $model_fields = get_component_model_fields($model_key, (array) $category_ids);

    if (!empty($model_fields)) {
        $html .= '<div class="custom-fields-section">';
        $html .= '<h5 class="section-subtitle">自定义字段</h5>';
        $html .= '<div class="grid gap-4 grid-cols-3">';

        foreach ($model_fields as $field) {
            $field_name = $field['field_key'] ?? $field['field_name'];

            // 尝试多种字段名格式来获取字段值
            $field_value = '';
            if (isset($field_values['model_field_' . $field_name])) {
                $field_value = $field_values['model_field_' . $field_name];
            } elseif (isset($field_values[$field_name])) {
                $field_value = $field_values[$field_name];
            } else {
                $field_value = $field['default_value'] ?? '';
            }

            $html .= render_field_html($field, $field_value, $post_id);
        }

        $html .= '</div>';
        $html .= '</div>';
    }

    $html .= '</div>';

    return $html;
}

/**
 * 获取模型显示名称
 * @param string $model_key 模型键
 * @return string 模型显示名称
 */
function get_model_display_name($model_key)
{
    if (empty($model_key)) {
        return '未知模型';
    }

    global $tododb;

    try {
        $sql = "SELECT model_name FROM " . TABLE_PREFIX . "models WHERE model_key = ? LIMIT 1";
        $result = $tododb->get_var($sql, [$model_key]);

        return $result ? $result : $model_key;
    } catch (Exception $e) {
        error_log('获取模型显示名称失败: ' . $e->getMessage());
        return $model_key;
    }
}

/**
 * 渲染字段HTML
 * @param array $field 字段配置
 * @param mixed $field_value 字段值
 * @param int $post_id 文章ID
 * @return string 字段HTML
 */
function render_field_html($field, $field_value, $post_id = 0, $name_prefix = 'model_field_', $id_prefix = '', $suppress_attrs = false)
{
    $field_key = isset($field['field_key']) ? $field['field_key'] : '';
    $options = [
        'name' => $suppress_attrs ? '' : ($name_prefix . $field_key),
        'id' => $suppress_attrs ? '' : (($id_prefix ? $id_prefix : '') . 'field_' . $field_key),
        'post_id' => intval($post_id)
    ];
    return Todo_Custom_Field_Renderer::render_field($field, $field_value, $options);
}

/**
 * 获取所有分类（兼容函数）
 * @return array 分类列表
 */
if (!function_exists('get_all_categories')) {
    function get_all_categories()
    {
        global $tododb;
        if (!isset($tododb)) {
            return [];
        }

        return $tododb->get_results("SELECT t.term_id, t.name, t.slug, tt.description, tt.parent, tt.count 
             FROM {$tododb->prefix}terms t 
             INNER JOIN {$tododb->prefix}term_taxonomy tt ON t.term_id = tt.term_id 
             WHERE tt.taxonomy = 'category' 
             ORDER BY t.name ASC");
    }
}

/**
 * 生成所有模型字段数据
 * @param int $post_id 文章ID
 * @return array 模型字段数据数组
 */
function generate_all_model_fields_data($post_id = 0, $field_values = array(), $show_title = true, $include_featured = true, $name_prefix = 'model_field_', $id_prefix = '', $suppress_attrs = false, $limit_models = null)
{
    global $tododb;
    
    // 确保数据库连接已初始化
    if (!isset($tododb) || $tododb === null) {
        if (class_exists('CMS_DB')) {
            $tododb = CMS_DB::get_instance();
        } else {
            // 如果 CMS_DB 也不存在，返回空数据
            return array(
                'models' => array(),
                'category_to_model' => array()
            );
        }
    }
    
    $all_categories = get_all_categories();

    $category_to_model = array(); // 栏目ID => 模型键
    $models_index = array();      // 模型键 => 索引数据

    // 优化1：批量获取栏目模型，避免N+1查询
    $cat_ids = [];
    foreach ($all_categories as $category) {
        $cat_ids[] = is_object($category) ? $category->term_id : $category['term_id'];
    }

    if (!empty($cat_ids)) {
        $category_to_model = Todo_Model_Manager::get_category_models_batch($cat_ids);
    }

    // 针对每个模型构建字段索引与可见性映射
    $unique_models = array_unique(array_values($category_to_model));

    // 如果指定了限制模型，需要特殊处理
    // 如果限制的模型不在 $unique_models 中（即没有关联分类），也要包含进来
    if ($limit_models !== null && is_array($limit_models)) {
        // 合并：已有的模型 + 限制的模型（即使没有关联分类）
        $unique_models = array_unique(array_merge($unique_models, $limit_models));
        // 只保留在限制列表中的模型
        $unique_models = array_intersect($unique_models, $limit_models);
    }

    // 优化2：批量获取模型名称
    $model_names = [];
    if (!empty($unique_models) && isset($tododb) && $tododb !== null) {
        $unique_models_values = array_values($unique_models);
        $placeholders = implode(',', array_fill(0, count($unique_models_values), '?'));
        $table_prefix = isset($tododb->prefix) ? $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_');
        $sql = "SELECT model_key, model_name FROM " . $table_prefix . "models WHERE model_key IN ($placeholders)";
        $results = $tododb->get_results($sql, $unique_models_values);
        if ($results) {
            foreach ($results as $r) {
                $model_names[$r->model_key] = $r->model_name;
            }
        }
    }

    foreach ($unique_models as $model_key) {
        // 该模型下的所有栏目
        $model_categories = array_keys(array_filter($category_to_model, fn ($mk) => $mk === $model_key));
        
        // 如果模型没有关联分类，使用空数组（允许渲染模型字段，即使没有分类关联）
        // 这对于直接请求特定模型字段的场景很重要

        // 固定字段HTML（只渲染一次）
        $current_field_values = !empty($field_values) ? $field_values : ($post_id ? get_post_model_fields_values($post_id, $model_categories) : array());
        $model_id_prefix = $id_prefix ? ($id_prefix . $model_key . '_') : '';
        // 使用“模型维度”的ID前缀，避免同页多个模型/语言面板导致的ID冲突
    $featured_html = $include_featured ? get_model_featured_fields_html($model_key, $current_field_values, $post_id, $name_prefix, $model_id_prefix, $suppress_attrs, $model_categories) : '';

    // 固定字段键集合
    $fixed_keys = array('seo_title', 'seo_keywords', 'seo_description');
        $model_class = get_model_class_by_key($model_key);
        try {
            if ($model_class === 'CMS_Post_Type_Custom') {
                $instance = new $model_class($model_key);
                if (method_exists($instance, 'get_fixed_fields')) {
                    $fixed_fields = $instance->get_fixed_fields();
                    foreach ($fixed_fields as $ff) {
                        $arr = is_array($ff) ? $ff : (array) $ff;
                        $k = isset($arr['field_key']) ? $arr['field_key'] : '';
                        if ($k) {
                            $fixed_keys[] = $k;
                        }
                    }
                }
            } elseif ($model_class && method_exists($model_class, 'get_fixed_fields')) {
                $fixed_fields = $model_class::get_fixed_fields();
                foreach ($fixed_fields as $ff) {
                    $arr = is_array($ff) ? $ff : (array) $ff;
                    $k = isset($arr['field_key']) ? $arr['field_key'] : '';
                    if ($k) {
                        $fixed_keys[] = $k;
                    }
                }
            }
        } catch (Exception $e) { /* 忽略 */
        }
        $fixed_keys = array_values(array_unique($fixed_keys));

        // 获取模型的“完整字段集合”（不按栏目过滤，用于渲染去重后的字段HTML）
        // 传入模型关联的所有栏目ID，以避免触发“未指定栏目ID”的错误日志，并确保只加载有效的字段
        $all_fields = get_component_model_fields($model_key, $model_categories);
        $fields_html_by_key = array();
        $field_order = array();
        if (!empty($all_fields)) {
            foreach ($all_fields as $field_cfg) {
                // 跳过固定字段，固定字段已在 featured_html 中渲染
                if (!empty($field_cfg['is_fixed'])) {
                    continue;
                }
                $key = $field_cfg['field_key'];
                $val = isset($current_field_values['model_field_' . $key]) ? $current_field_values['model_field_' . $key] : ($field_cfg['field_default'] ?? '');
                $piece = render_field_html($field_cfg, $val, $post_id, $name_prefix, $model_id_prefix, $suppress_attrs);
                $fields_html_by_key[$key] = $piece;
                $field_order[$key] = isset($field_cfg['field_order']) ? (int) $field_cfg['field_order'] : 0;
            }
        }

        // 优化3：批量获取字段可见性，避免循环查询
        $category_visibility = array();
        foreach ($model_categories as $cid) {
            $category_visibility[$cid] = array();
        }

        if (!empty($model_categories) && isset($tododb) && $tododb !== null) {
            $cat_ids_str = implode(',', array_map('intval', $model_categories));
            $table_prefix = isset($tododb->prefix) ? $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_');
            // 查询所有这些栏目的可见字段
            $sql = "SELECT tfv.term_id, mf.field_key 
                    FROM " . $table_prefix . "term_field_visibility tfv
                    JOIN " . $table_prefix . "model_fields mf ON tfv.field_id = mf.field_id
                    JOIN " . $table_prefix . "models m ON mf.model_id = m.model_id
                    WHERE m.model_key = ? 
                    AND tfv.term_id IN ($cat_ids_str) 
                    AND tfv.is_visible = 1";

            $vis_results = $tododb->get_results($sql, [$model_key]);

            if ($vis_results) {
                foreach ($vis_results as $row) {
                    if (isset($category_visibility[$row->term_id])) {
                        $category_visibility[$row->term_id][] = $row->field_key;
                    }
                }
            }
        }

        $display_name = isset($model_names[$model_key]) ? $model_names[$model_key] : $model_key;

        $models_index[$model_key] = array(
            'display_name' => $display_name,
            'featured_html' => $featured_html,
            'fields_html_by_key' => $fields_html_by_key,
            'field_order' => $field_order,
            'category_visibility' => $category_visibility,
            'fixed_keys' => $fixed_keys
        );
    }

    return array(
        'models' => $models_index,
        'category_to_model' => $category_to_model
    );
}

/**
 * 渲染模型字段组件
 * @param array $args 参数数组
 */
function render_model_fields_component($args = array())
{
    $post_id = isset($args['post_id']) ? intval($args['post_id']) : 0;
    $field_values = isset($args['field_values']) ? $args['field_values'] : array();
    $show_title = isset($args['show_title']) ? $args['show_title'] : true;
    $include_featured = isset($args['include_featured']) ? (bool) $args['include_featured'] : true;
    $name_prefix = isset($args['name_prefix']) ? $args['name_prefix'] : 'model_field_';
    $lang_code = isset($args['lang_code']) ? trim($args['lang_code']) : '';

    // 语言码规范化与校验：统一使用 xx-XX 格式
    if (!empty($lang_code)) {
        $lang_code = str_replace('_', '-', $lang_code);
        $parts = explode('-', $lang_code);
        if (count($parts) === 1) {
            $lang_code = strtolower($parts[0]);
        } else {
            $lang_code = strtolower($parts[0]) . '-' . strtoupper($parts[1]);
        }
        if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $lang_code)) {
            $lang_code = '';
        }
    }

    // 容器ID与内容ID
    $container_id = isset($args['container_id']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '', $args['container_id']) : 'model-fields-container';
    // 增加组件实例唯一标识，防止同页多次渲染导致的ID重复
    $instance_uid = isset($args['instance_uid']) && preg_match('/^[a-zA-Z0-9_\-]+$/', $args['instance_uid']) ? $args['instance_uid'] : substr(md5(uniqid('', true)), 0, 6);
    $content_id = $container_id . '-content-' . $instance_uid;
    $is_lang_panel = ($instance_uid === 'trpanel');

    // 生成ID前缀：容器维度 + 语言维度 + 实例维度，保证全局唯一
    $id_prefix_base = $container_id . '_';
    $id_prefix = $id_prefix_base . (!empty($lang_code) ? 'tr_' . $lang_code . '_' : '') . 'inst_' . $instance_uid . '_';
    // 生成name前缀：语言维度用于区分翻译与主稿
    $name_prefix = !empty($lang_code) ? 'tr_' . $lang_code . '_model_field_' : $name_prefix;

    // 生成所有模型字段数据
    $defer_attrs = isset($args['defer_attrs']) ? (bool) $args['defer_attrs'] : false;
    if (empty($lang_code)) {
        $defer_attrs = false;
    }

    // 确定初始需要渲染的模型
    $limit_models = null;
    if (!isset($args['render_all_models'])) {
        $selected_cats = isset($args['selected_categories']) ? $args['selected_categories'] : array();
        if (!empty($selected_cats)) {
            $limit_models = array();
            $cat_models = Todo_Model_Manager::get_category_models_batch($selected_cats);
            if (!empty($cat_models)) {
                $limit_models = array_unique(array_values($cat_models));
            }
        } else {
            // 如果没有选中的分类，可能不需要渲染任何模型，或者只渲染默认的
            // 这里我们传入空数组，表示暂不渲染任何模型HTML
            $limit_models = array();
        }
    }

    $all_data = $is_lang_panel ? array('models' => array(), 'category_to_model' => array()) : generate_all_model_fields_data($post_id, $field_values, $show_title, $include_featured, $name_prefix, $id_prefix, $defer_attrs, $limit_models);

    $models_index = $is_lang_panel ? array() : $all_data['models'];
    $category_to_model = $is_lang_panel ? array() : $all_data['category_to_model'];

    ?>
    <!-- 模型字段容器 -->
    <div id="<?php echo esc_attr($container_id); ?>" class="model-fields-container"
        data-name-prefix="<?php echo esc_attr($name_prefix); ?>" <?php if (!empty($lang_code)): ?>
            data-lang-code="<?php echo esc_attr($lang_code); ?>" <?php endif; ?>
        data-id-prefix="<?php echo esc_attr($id_prefix); ?>" <?php if ($is_lang_panel): ?> data-lang-panel="1" <?php endif; ?>>
        <?php
        $fixed_keys_union = array();
        if ($is_lang_panel) {
            // 语言面板至少需要采集SEO固定字段
            $fixed_keys_union = array('seo_title', 'seo_keywords', 'seo_description');
        } else {
            foreach ($models_index as $mkey => $mdata) {
                if (!empty($mdata['fixed_keys']) && is_array($mdata['fixed_keys'])) {
                    $fixed_keys_union = array_merge($fixed_keys_union, $mdata['fixed_keys']);
                }
            }
            $fixed_keys_union = array_values(array_unique($fixed_keys_union));
        }
        $fixed_keys_attr = implode(',', $fixed_keys_union);
        ?>
        <div id="<?php echo esc_attr($content_id); ?>" data-fixed-keys="<?php echo esc_attr($fixed_keys_attr); ?>">
            <?php if (!$is_lang_panel): ?>
                <div class="no-model-fields">
                    <p class="text-muted">请先选择栏目分类，系统将根据栏目绑定的数据模型显示相应字段。</p>
                </div>
            <?php else: ?>
                <?php
                $sections = array();
                $base_fields_map = array();
                if (!empty($post_id)) {
                    try {
                        global $tododb;
                        $category_sql = "SELECT tm.term_id, m.model_key FROM " . TABLE_PREFIX . "term_relationships tr JOIN " . TABLE_PREFIX . "term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id JOIN " . TABLE_PREFIX . "term_models tm ON tt.term_id = tm.term_id JOIN " . TABLE_PREFIX . "models m ON tm.model_id = m.model_id WHERE tr.object_id = ? AND tt.taxonomy = 'category'";
                        $post_categories = $tododb->get_results($category_sql, [$post_id]);
                        $post_models = array();
                        if (!empty($post_categories)) {
                            foreach ($post_categories as $category) {
                                $mk = is_object($category) ? $category->model_key : $category['model_key'];
                                if (!empty($mk)) {
                                    $post_models[] = $mk;
                                }
                            }
                        }
                        $post_models = array_values(array_unique($post_models));
                        foreach ($post_models as $model_key) {
                            $model_class = get_model_class_by_key($model_key);
                            if ($model_class) {
                                $fields = array();
                                if ($model_class === 'CMS_Post_Type_Custom') {
                                    $instance = new $model_class($model_key);
                                    if (method_exists($instance, 'get_base_fixed_fields')) {
                                        $fields = $instance->get_base_fixed_fields();
                                    }
                                } elseif (method_exists($model_class, 'get_base_fixed_fields')) {
                                    $fields = $model_class::get_base_fixed_fields();
                                }
                                if (!empty($fields)) {
                                    foreach ($fields as $f) {
                                        $k = isset($f['field_key']) ? $f['field_key'] : '';
                                        if (!$k) {
                                            continue;
                                        }
                                        if (!isset($base_fields_map[$k])) {
                                            $base_fields_map[$k] = $f;
                                            $sec = isset($f['field_section']) && $f['field_section'] !== '' ? $f['field_section'] : '基础固定字段';
                                            if (!isset($sections[$sec])) {
                                                $sections[$sec] = array();
                                            }
                                            $sections[$sec][] = $k;
                                        }
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {
                    }
                }
                ?>
                <?php if (!empty($sections)): ?>
                    <?php foreach ($sections as $sec => $keys): ?>
                        <div class="fixed-fields-section">
                            <div class="section-title"><span><?php echo esc_html($sec); ?></span></div>
                            <div class="flex justify-between flex-wrap">
                                <?php foreach ($keys as $k): ?>
                                    <?php
                                    $field_cfg = $base_fields_map[$k];
                                    $field_value = '';
                                    if (isset($field_values['model_field_' . $k])) {
                                        $field_value = $field_values['model_field_' . $k];
                                    } elseif (isset($field_values[$k])) {
                                        $field_value = $field_values[$k];
                                    } else {
                                        $field_value = isset($field_cfg['field_default']) ? $field_cfg['field_default'] : '';
                                    }
                                    echo render_field_html($field_cfg, $field_value, $post_id, $name_prefix, $id_prefix);
                                    ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- 模型字段组件样式 -->
    <style>
        .model-title {
            margin: 0 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
            color: #333;
            font-size: 16px;
            font-weight: 500;
        }

        .section-subtitle {
            margin: 0 0 10px -15px;
            color: #999;
            font-size: 14px;
            font-weight: normal;
            border-bottom: 1px solid #eceef1;
            padding-bottom: 5px;
        }

        .fixed-fields-section {
            padding-left: 15px;
        }

        .fixed-fields-section .section-title {
            margin: 0 0 10px -15px;
            color: #999;
            font-size: 14px;
            font-weight: normal;
            position: relative;
        }

        .fixed-fields-section .section-title span {
            background-color: #fff;
            padding-right: 10px;
            position: relative;
        }

        .fixed-fields-section .section-title::before {
            content: '';
            width: 100%;
            height: 1px;
            border-bottom: 1px dashed #eceef1;
            position: absolute;
            top: 10px;
        }

        .model-title+.fixed-fields-section .section-title::before {
            display: none;
        }

        .custom-fields-section {
            padding-left: 15px;
        }

        .mbody .custom-fields-section .grid > div {
            grid-column: 1 / -1;
        }

        .model-title+.custom-fields-section .section-subtitle {
            padding-bottom: 0;
            border: none;
        }

        .model-field {
            margin-bottom: 15px;
        }

        .model-field .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .model-field .form-control:focus {
            border-color: #007cba;
            outline: none;
            box-shadow: 0 0 0 1px #007cba;
        }

        .model-field .form-text {
            margin-top: 5px;
            font-size: 13px;
            color: #aaa;
            font-style: italic;
        }

        .no-model-fields {
            text-align: center;
            padding: 30px;
            color: #bbbbc4;
        }

        .loading-fields {
            text-align: center;
            padding: 30px;
            color: #6c6c75;
        }

        .loading-fields i {
            margin-right: 8px;
        }
    </style>

    <!-- 模型字段组件JavaScript -->
    <script type="text/javascript">
        (function ($) {
            'use strict';

            // 预生成的模型字段数据（按容器隔离，避免多实例覆盖）
            window['modelModelsIndex_' + '<?php echo esc_js($content_id); ?>'] = <?php echo json_encode($models_index, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            window['categoryToModel_' + '<?php echo esc_js($content_id); ?>'] = <?php echo json_encode($category_to_model, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            // 正在加载的模型列表（防止重复请求）
            window['loadingModels_' + '<?php echo esc_js($content_id); ?>'] = window['loadingModels_' + '<?php echo esc_js($content_id); ?>'] || {};
            // 加载失败的模型列表（避免无限重试）
            window['failedModels_' + '<?php echo esc_js($content_id); ?>'] = window['failedModels_' + '<?php echo esc_js($content_id); ?>'] || {};
            var CONTAINER_ID = '<?php echo esc_attr($container_id); ?>';
            var CONTENT_ID = '<?php echo esc_attr($content_id); ?>';
            var ID_PREFIX = '<?php echo esc_js($id_prefix); ?>';
            var IS_LANG_PANEL = <?php echo $is_lang_panel ? 'true' : 'false'; ?>;

            /**
             * 更新模型字段
             * @param {Array} categoryIds 选中的栏目ID数组
             */
            // 保存当前字段值（修复radio组与多选checkbox的值采集）
            function saveCurrentFieldValues() {
                var values = {};
                $('#' + CONTENT_ID).find('input, textarea, select').each(function () {
                    var $el = $(this);
                    var name = $el.attr('name');
                    if (!name) { return; }
                    var type = ($el.attr('type') || '').toLowerCase();

                    if (type === 'checkbox') {
                        // 支持boolean开关与多选checkbox（name以[]结尾）
                        if (name.endsWith('[]')) {
                            // 确保数组只初始化一次，避免覆盖已有值
                            if (!values.hasOwnProperty(name)) { values[name] = []; }
                            if ($el.is(':checked')) { values[name].push($el.val()); }
                        } else {
                            values[name] = $el.is(':checked') ? 1 : 0;
                        }
                    } else if (type === 'radio') {
                        // 仅保存该radio组已选中的值
                        values[name] = $('#' + CONTENT_ID).find('input[type="radio"][name="' + name + '"]:checked').val() || '';
                    } else {
                        values[name] = $el.val();
                    }
                });
                return values;
            }

            // 恢复字段值（修复radio组与多选checkbox的恢复方式）
            function restoreFieldValues(values) {
                for (var name in values) {
                    var val = values[name];
                    var $elements = $('#' + CONTENT_ID).find('[name="' + name + '"]');
                    if (!$elements.length) { continue; }

                    // 检测类型（以第一个元素为准）
                    var type = ($elements.attr('type') || '').toLowerCase();

                    if (type === 'checkbox' || $elements.is(':checkbox')) {
                        // 支持布尔开关与多选checkbox（name以[]结尾）
                        if (name.endsWith('[]') && Array.isArray(val)) {
                            $elements.each(function () {
                                var $chk = $(this);
                                $chk.prop('checked', val.indexOf($chk.val()) !== -1);
                            });
                        } else {
                            $elements.prop('checked', !!val && (val === 1 || val === '1' || val === true));
                        }
                    } else if (type === 'radio' || ($elements.length && $elements[0].type === 'radio')) {
                        // 恢复radio组选中项，不修改各选项的value属性
                        $elements.prop('checked', false);
                        $elements.filter('[value="' + val + '"]').prop('checked', true);
                    } else {
                        // 普通输入/选择控件
                        // 如果 val 是对象或数组，且目标是 hidden input，必须先 JSON.stringify
                        // 否则 jQuery .val(array) 会调用 Array.toString() 输出 "[object Object],[object Object]"
                        var safeVal = val;
                        if (val !== null && val !== undefined && typeof val === 'object') {
                            safeVal = JSON.stringify(val);
                        }
                        $elements.val(safeVal);

                        // 额外处理：如果该元素是 textarea 且绑定了 UEditor，同步更新编辑器内容
                        if ($elements.is('textarea') && window.UE) {
                            $elements.each(function() {
                                var textareaEl = this;
                                var instants = window.UE.instants || window.UE.instances || {};
                                var editor = null;

                                // 尝试 1: 通过 textarea name 匹配
                                for (var k in instants) {
                                    if (Object.prototype.hasOwnProperty.call(instants, k)) {
                                        var inst = instants[k];
                                        if (inst && ((inst.textarea && inst.textarea.name === textareaEl.name) ||
                                            (inst.options && inst.options.textarea === textareaEl.name))) {
                                            editor = inst;
                                            break;
                                        }
                                    }
                                }

                                // 尝试 2: 通过相邻的外围容器匹配
                                if (!editor) {
                                    var $group = $(this).closest('.form-group, [data-field-type="editor"], .fixed-field');
                                    var eduiId = $group.find('.edui-default[id]').attr('id');
                                    if (eduiId && instants[eduiId]) {
                                        editor = instants[eduiId];
                                    }
                                }

                                if (editor) {
                                    try {
                                        if (editor.isReady) {
                                            editor.setContent(val);
                                        } else {
                                            (function(content) {
                                                editor.ready(function() {
                                                    editor.setContent(content);
                                                });
                                            })(val);
                                        }
                                    } catch (e) {}
                                }
                            });
                        }

                        // 额外处理：如果该元素是关联了 featured-images-component 的隐藏域，同步更新图片组件
                        var $imgComp = $('#' + CONTENT_ID).find('.featured-images-component[data-field-name="' + name + '"]');
                        if ($imgComp.length > 0) {
                            try {
                                var parsed = JSON.parse(val || '[]');
                                $imgComp.data('current-images', parsed);
                                $imgComp.attr('data-current-images', JSON.stringify(parsed));
                                var inst = $imgComp.data('featuredImagesInstance');
                                if (inst) {
                                    var $preview = $imgComp.find('.featured-images-preview, .universal-images-preview');
                                    $preview.empty();
                                    if (typeof inst.loadCurrentImages === 'function') {
                                        inst.loadCurrentImages();
                                    }
                                }
                            } catch (e) {}
                        }

                        // 同步开关字段的可视化状态（Field_Type_Switch：隐藏input + 可视checkbox）
                        var firstEl = $elements.first();
                        var firstType = (firstEl.attr('type') || '').toLowerCase();
                        if (firstType === 'hidden') {
                            var $switchWrapper = firstEl.closest('.switch-field');
                            if ($switchWrapper.length > 0) {
                                var $toggle = $switchWrapper.find('input[type="checkbox"].switch-checkbox');
                                if ($toggle.length > 0) {
                                    var checked = !!val && (val === 1 || val === '1' || val === true);
                                    $toggle.prop('checked', checked);
                                }
                            } else {
                                // 兼容旧版基于 ID 命名的 toggle
                                var hidId = firstEl.attr('id');
                                if (hidId) {
                                    var $toggle2 = $('#' + hidId + '-toggle');
                                    if ($toggle2.length) {
                                        var checked2 = !!val && (val === 1 || val === '1' || val === true);
                                        $toggle2.prop('checked', checked2);
                                    }
                                }
                            }
                        }
                    }
                }
            }

            function updateModelFields(categoryIds) {
                if (IS_LANG_PANEL) { return; }
                var $container = $('#' + CONTENT_ID);

                if (!categoryIds || categoryIds.length === 0) {
                    $container.html('<div class="no-model-fields"><p class="text-muted">请先选择栏目分类，系统将根据栏目绑定的数据模型显示相应字段。</p></div>');
                    return;
                }

                // 获取当前显示的模型
                var currentModel = $container.find('.model-fields-group').data('model');

                // 构建本次选择涉及的模型集合
                var categoryToModel = window['categoryToModel_' + CONTENT_ID] || {};
                var modelsSet = new Set();
                categoryIds.forEach(function (cid) {
                    var mk = categoryToModel[cid];
                    if (mk) modelsSet.add(mk);
                });

                // 保存当前字段值
                var currentValues = saveCurrentFieldValues();

                var fieldsHtml = '';
                var modelsIndex = window['modelModelsIndex_' + CONTENT_ID] || {};

                // 针对每一个涉及的模型，计算该模型在本次选择下的"联合可见性字段集合"（并集）并渲染
                var loadingModels = window['loadingModels_' + CONTENT_ID] || {};
                var failedModels = window['failedModels_' + CONTENT_ID] || {};
                var missingModels = [];
                modelsSet.forEach(function (modelKey) {
                    // 只处理：1) 不在索引中 2) 不在加载中 3) 未标记为失败
                    if (!modelsIndex[modelKey] && !loadingModels[modelKey] && !failedModels[modelKey]) {
                        missingModels.push(modelKey);
                    }
                });

                if (missingModels.length > 0) {
                    // 标记这些模型为正在加载
                    missingModels.forEach(function (mk) {
                        loadingModels[mk] = true;
                    });
                    window['loadingModels_' + CONTENT_ID] = loadingModels;
                    
                    // 显示加载状态
                    if ($container.find('.loading-fields').length === 0) {
                        $container.append('<div class="loading-fields">正在加载字段...</div>');
                    }

                    // AJAX请求获取缺失的模型字段
                    // 获取CSRF token（多种方式尝试，确保能找到）
                    var nonceValue = '';
                    var nonceInput = document.querySelector('input[name="_todononce"]');
                    if (nonceInput) {
                        nonceValue = nonceInput.value;
                    } else if (typeof $ !== 'undefined') {
                        nonceValue = $('#_todononce').val() || $('input[name="_todononce"]').val() || '';
                    }
                    
                    $.ajax({
                        url: 'ajax/post-ajax.php',
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'get_model_fields',
                            model_keys: missingModels,
                            post_id: <?php echo intval($post_id); ?>,
                            name_prefix: '<?php echo esc_js($name_prefix); ?>',
                            id_prefix: ID_PREFIX,
                            _todononce: nonceValue
                        },
                        success: function (response) {
                            // 清除加载标记
                            missingModels.forEach(function (mk) {
                                delete loadingModels[mk];
                            });
                            window['loadingModels_' + CONTENT_ID] = loadingModels;
                            
                            $container.find('.loading-fields').remove();
                            if (response.success && response.data && Object.keys(response.data).length > 0) {
                                // 合并新获取的模型数据
                                $.extend(modelsIndex, response.data);
                                // 更新全局索引
                                window['modelModelsIndex_' + CONTENT_ID] = modelsIndex;
                                // 重新执行更新逻辑
                                updateModelFields(categoryIds);
                            } else {
                                // 标记为失败，避免无限重试
                                missingModels.forEach(function (mk) {
                                    failedModels[mk] = true;
                                });
                                window['failedModels_' + CONTENT_ID] = failedModels;
                                
                                console.error('Failed to load model fields:', response.message || '返回数据为空');
                                var errorMsg = response.message || '未知错误';
                                if ($container.find('.error-message').length === 0) {
                                    $container.append('<div class="error-message">加载字段失败: ' + errorMsg + '</div>');
                                }
                            }
                        },
                        error: function (xhr, status, error) {
                            // 清除加载标记
                            missingModels.forEach(function (mk) {
                                delete loadingModels[mk];
                            });
                            window['loadingModels_' + CONTENT_ID] = loadingModels;
                            
                            // 标记为失败，避免无限重试
                            missingModels.forEach(function (mk) {
                                failedModels[mk] = true;
                            });
                            window['failedModels_' + CONTENT_ID] = failedModels;
                            
                            $container.find('.loading-fields').remove();
                            console.error('AJAX error loading model fields:', error, xhr.responseText);
                            var errorMsg = '网络错误或服务器错误';
                            try {
                                var errorResponse = JSON.parse(xhr.responseText);
                                if (errorResponse && errorResponse.message) {
                                    errorMsg = errorResponse.message;
                                }
                            } catch (e) {}
                            
                            if ($container.find('.error-message').length === 0) {
                                $container.append('<div class="error-message">加载字段失败: ' + errorMsg + '</div>');
                            }
                        }
                    });
                    return; // 中断本次更新，等待AJAX返回
                }

                modelsSet.forEach(function (modelKey) {
                    var modelData = modelsIndex[modelKey];
                    if (!modelData) return;

                    // 1) 该模型下，被选中的栏目列表
                    var pickedCategoryIds = categoryIds.filter(function (cid) {
                        return (categoryToModel[cid] === modelKey);
                    }).map(function (v) { return parseInt(v); });

                    // 2) 计算并集的字段键集合
                    var unionKeys = new Set();
                    pickedCategoryIds.forEach(function (cid) {
                        var vis = modelData.category_visibility[cid] || [];
                        vis.forEach(function (k) { unionKeys.add(k); });
                    });

                    // 如果有可见字段，渲染：模型标题 + 固定字段 + 自定义字段（按order排序）
                    var partHtml = '<div class="model-fields-group" data-model="' + modelKey + '">';
                    partHtml += '<h4 class="model-title">' + modelData.display_name + ' 字段</h4>';

                    // 固定字段
                    if (modelData.featured_html) {
                        partHtml += modelData.featured_html;
                    }

                    // 自定义字段
                    var keys = Array.from(unionKeys).filter(function(k) {
                        return modelData.fields_html_by_key && modelData.fields_html_by_key[k];
                    });

                    if (keys.length > 0) {
                        // 按字段顺序排序
                        keys.sort(function (a, b) {
                            var oa = modelData.field_order[a] || 0;
                            var ob = modelData.field_order[b] || 0;
                            return oa - ob;
                        });

                        partHtml += '<div class="custom-fields-section">';
                        partHtml += '<h5 class="section-subtitle">自定义字段</h5>';
                        partHtml += '<div class="grid gap-4 grid-cols-3">';
                        keys.forEach(function (k) {
                            partHtml += modelData.fields_html_by_key[k];
                        });
                        partHtml += '</div>';
                        partHtml += '</div>';
                    }

                    partHtml += '</div>';
                    fieldsHtml += partHtml;
                });

                // 更新内容前进行ID唯一化处理 - 已在服务端通过uniqid保证，前端不再重写以免破坏脚本内ID引用
                function ensureUniqueIds(html, prefix) {
                    return html;
                }

                // 更新内容后恢复字段值（安全插入，避免内联script导致解析异常）
                if (fieldsHtml) {
                    // 先尝试直接插入
                    try {
                        var safeHtml = ensureUniqueIds(fieldsHtml, ID_PREFIX);
                        $container.html(safeHtml);
                        try {
                            var secs = $('#' + CONTENT_ID).find('.fixed-fields-section');
                            secs.each(function () {
                                var $s = $(this);
                                if ($s.find('.fixed-field').length === 0) { $s.remove(); }
                            });
                        } catch (e3) { }
                    } catch (err) {
                        console.error('Model fields HTML insert error:', err);
                        // 回退：移除内联脚本后再插入，避免 appendChild Invalid token
                        var safeHtml = ensureUniqueIds(fieldsHtml.replace(/<script[\s\S]*?<\/script>/gi, ''), ID_PREFIX);
                        try {
                            var temp2 = document.createElement('div');
                            temp2.innerHTML = safeHtml;
                            var seen2 = {};
                            var nodes2 = temp2.querySelectorAll('[id^="' + ID_PREFIX + '"]');
                            for (var j = 0; j < nodes2.length; j++) {
                                var idv2 = nodes2[j].id;
                                if (!idv2) continue;
                                if (seen2[idv2]) {
                                    nodes2[j].parentNode && nodes2[j].parentNode.removeChild(nodes2[j]);
                                } else {
                                    seen2[idv2] = true;
                                }
                            }
                            safeHtml = temp2.innerHTML;
                        } catch (e2) { /* 忽略预清理错误 */ }
                        $container.html(safeHtml);
                    }

                    // 提取并执行被移除或未执行的脚本
                    try {
                        var scripts = [];
                        var re = /<script[^>]*>([\s\S]*?)<\/script>/gi, m;
                        while ((m = re.exec(fieldsHtml)) !== null) {
                            if (m[1]) scripts.push(m[1]);
                        }
                        scripts.forEach(function (code) {
                            try { jQuery.globalEval(code); } catch (e) { console.error('Model fields script eval error:', e); }
                        });
                    } catch (e) {
                        console.error('Model fields script extraction failed:', e);
                    }

                    dedupeIds($container);
                    dedupeGlobalByPrefix(ID_PREFIX);
                    initializeFieldComponents($container);
                    restoreFieldValues(currentValues);
                } else {
                    $container.html('');
                }

                // 触发字段更新事件
                $(document).trigger('model-fields-updated', [categoryIds]);
            }

            // 去重：移除容器内重复ID的元素，保留首个
            function dedupeIds($container) {
                var seen = {};
                $container.find('[id]').each(function () {
                    var id = this.id;
                    if (!id) return;
                    if ($(this).closest('.media-modal-overlay').length) {
                        return;
                    }
                    if (seen[id]) {
                        $(this).remove();
                    } else {
                        seen[id] = true;
                    }
                });
            }

            // 全局去重：移除整个页面内以指定前缀开头的重复ID元素，保留首个
            function dedupeGlobalByPrefix(prefix) {
                if (!prefix) return;
                var seen = {};
                // 仅在当前容器内进行去重，避免跨面板/跨语言误删输入控件
                $('#' + CONTENT_ID).find('[id^="' + prefix + '"]').each(function () {
                    var id = this.id;
                    if (!id) return;
                    if (seen[id]) {
                        $(this).remove();
                    } else {
                        seen[id] = true;
                    }
                });
            }

            /**
             * 初始化字段组件
             * @param {jQuery} $container 容器元素
             */
            function initializeFieldComponents($container) {
                // 初始化日期选择器
                $container.find('input[type="date"], input[type="datetime-local"]').each(function () {
                    // 可以在这里添加日期选择器的初始化代码
                });

                // 初始化富文本编辑器
                $container.find('textarea.rich-editor, textarea.todo-editor').each(function () {
                    var id = $(this).attr('id');
                    if (id && window.UE && UE.getEditor) {
                        // 如果已经存在实例，先销毁（防止AJAX重新渲染导致的冲突）
                        try {
                            var oldInstance = UE.getEditor(id);
                            if (oldInstance) {
                                oldInstance.destroy();
                            }
                        } catch (e) {}
                        
                        var editor = UE.getEditor(id, {
                            initialFrameHeight: 300,
                            autoHeightEnabled: true,
                            elementPathEnabled: false,
                            wordCount: true,
                            enableAutoSave: false,
                            serverUrl: ((window.TODO && window.TODO.join) ? window.TODO.join(window.TODO.ADMIN_BASE, 'assets/js/ueditor/php/controller.php') : '/admin/assets/js/ueditor/php/controller.php')
                        });

                        try {
                            if (editor && typeof editor.ready === 'function') {
                                editor.ready(function () {
                                    try {
                                        var $root = $('#' + id).closest('[data-field-type="editor"], .form-group, .fixed-field');
                                        if ($root && $root.length) {
                                            $root.find('textarea.todo-editor, textarea.rich-editor').each(function () {
                                                var tid = $(this).attr('id');
                                                if (!tid || tid !== id) {
                                                    $(this).css('display', 'none');
                                                }
                                            });
                                        }

                                        // 添加内容变化监听器，实时同步到 textarea
                                        editor.addListener('contentChange', function () {
                                            var content = editor.getContent();
                                            $('#' + id).val(content);
                                            // 触发 change 事件以便其他脚本感知
                                            $('#' + id).trigger('change');
                                        });
                                        
                                        // 兼容处理：点击即同步，防止某些情况下 contentChange 不触发
                                        editor.addListener('blur', function () {
                                            $('#' + id).val(editor.getContent());
                                        });

                                    } catch (e2) {}
                                });
                            }
                        } catch (e1) {}
                    }
                });

                // 初始化文件上传组件
                $container.find('.file-upload-field').each(function () {
                    // 可以在这里添加文件上传组件的初始化代码
                });
            }

            // 监听栏目选择变化
            $(document).on('change', 'input[name="post_category[]"], select[name="post_category"]', function (e) {
                e.preventDefault(); // 阻止默认表单提交行为
                if (IS_LANG_PANEL) { return; }

                var selectedCategories = [];

                // 获取选中的栏目ID
                $('input[name="post_category[]"]:checked, select[name="post_category"] option:selected').each(function () {
                    var categoryId = $(this).val();
                    if (categoryId && categoryId !== '') {
                        selectedCategories.push(categoryId);
                    }
                });

                // 检查当前页面类型，只在post-new页面更新URL
                var currentPage = new URLSearchParams(window.location.search).get('page');
                if (currentPage === 'post-new') {
                    // 使用history.replaceState更新URL但不刷新页面
                    var newUrl = window.location.pathname + '?page=post-new';
                    if (selectedCategories.length > 0) {
                        newUrl += '&selected_categories=' + selectedCategories.join(',');
                    }
                    history.replaceState(null, '', newUrl);
                }

                updateModelFields(selectedCategories);
            });

            // 表单提交处理
            $('form').on('submit', function (e) {
                // 同步所有 UEditor 实例内容到其对应的 textarea
                if (window.UE && (UE.instants || UE.instances)) {
                    var instants = UE.instants || UE.instances;
                    for (var key in instants) {
                        if (instants.hasOwnProperty(key)) {
                            try {
                                instants[key].sync();
                            } catch (err) {
                                console.warn('UEditor sync failed for ' + key, err);
                            }
                        }
                    }
                }

                // 其他逻辑...

                // 只在post-new页面移除URL中的selected_categories参数
                var currentPage = new URLSearchParams(window.location.search).get('page');
                if (currentPage === 'post-new') {
                    var cleanUrl = window.location.pathname + '?page=post-new';
                    history.replaceState(null, '', cleanUrl);
                }

                // 允许表单正常提交
                return true;
            });

            // 页面加载时初始化
            $(document).ready(function () {
                // 检查是否有预选的栏目
                var initialCategories = [];
                $('input[name="post_category[]"]:checked, select[name="post_category"] option:selected').each(function () {
                    var categoryId = $(this).val();
                    if (categoryId && categoryId !== '') {
                        initialCategories.push(categoryId);
                    }
                });

                if (!IS_LANG_PANEL && initialCategories.length > 0) {
                    updateModelFields(initialCategories);

                    // 页面加载后，如果有现有的字段值，需要恢复它们
                    setTimeout(function () {
                        // 从PHP传递的field_values中恢复值
                        var phpFieldValues = <?php echo json_encode($field_values ?? array()); ?>;
                        if (phpFieldValues && Object.keys(phpFieldValues).length > 0) {
                            restoreFieldValues(phpFieldValues);
                        }
                    }, 100);
                }
            });

        })(jQuery);
    </script>
    <?php
}

/**
 * 获取模型字段配置
 * @param string $model_key 模型键
 * @param array $category_ids 栏目ID数组
 * @param bool $for_category_page 是否为分类页面调用（categories.php）
 * @return array 字段配置数组
 */
function get_component_model_fields($model_key, $category_ids = array(), $for_category_page = false)
{
    static $runtime_cache = array();
    $cache_key = $model_key . '|' . ($for_category_page ? '1' : '0') . '|' . implode(',', array_map('intval', $category_ids));
    if (isset($runtime_cache[$cache_key])) {
        return $runtime_cache[$cache_key];
    }

    $all_fields = array();
    // 初始化用于后续判断的变量，避免未定义导致的误判
    $custom_fields = array();
    $custom_fields_configs = array();
    $has_custom_fields = false;

    // 首先获取模型类的固定字段
    $model_class = Todo_Model_Manager::get_model_class($model_key);
    if ($model_class && method_exists($model_class, 'get_fixed_fields')) {
        try {
            $fixed_fields = call_user_func(array($model_class, 'get_fixed_fields'));
            if (!empty($fixed_fields)) {
                // 标记固定字段，避免与自定义字段重复渲染
                foreach ($fixed_fields as &$ff) {
                    if (is_array($ff)) {
                        $ff['is_fixed'] = 1;
                    } elseif (is_object($ff)) {
                        $ff->is_fixed = 1;
                    }
                }
                unset($ff);
                $all_fields = array_merge($all_fields, $fixed_fields);
            }
        } catch (Exception $e) {
            error_log('获取模型固定字段失败: ' . $e->getMessage());
        }
    }

    // 然后获取自定义字段，考虑字段与分类的绑定关系
    global $tododb;
    if (isset($tododb)) {
        try {
            // 联合可见性：当提供栏目ID时，返回满足以下条件的字段：
            // 对于分类页面(categories.php)：返回所有在 tfv 中对所选栏目可见的字段（忽略category_only限制）
            // 对于文章页面(post-new/post-edit)：只返回 category_only = 0 且在 tfv 中对所选栏目可见的字段
            if (!empty($category_ids)) {
                $category_ids_str = implode(',', array_map('intval', $category_ids));

                if ($for_category_page) {
                    // 分类页面：返回所有可见字段，不过滤category_only
                    $sql = "SELECT mf.* FROM " . $tododb->prefix . "model_fields mf
                            LEFT JOIN " . $tododb->prefix . "models m ON mf.model_id = m.model_id
                            INNER JOIN " . $tododb->prefix . "term_field_visibility tfv ON mf.field_id = tfv.field_id
                            WHERE m.model_key = ? AND tfv.term_id IN (" . $category_ids_str . ") AND tfv.is_visible = 1 AND mf.field_status = 'active'
                            ORDER BY mf.field_order ASC";
                    $custom_fields_configs = $tododb->get_results($sql, [$model_key]);
                } else {
                    // 文章页面：只返回category_only=0的字段
                    $sql = "SELECT mf.* FROM " . $tododb->prefix . "model_fields mf
                            LEFT JOIN " . $tododb->prefix . "models m ON mf.model_id = m.model_id
                            INNER JOIN " . $tododb->prefix . "term_field_visibility tfv ON mf.field_id = tfv.field_id
                            WHERE m.model_key = ? AND tfv.term_id IN (" . $category_ids_str . ") AND tfv.is_visible = 1 AND mf.category_only = 0 AND mf.field_status = 'active'
                            ORDER BY mf.field_order ASC";
                    $custom_fields_configs = $tododb->get_results($sql, [$model_key]);
                }
            } else {
                // 没有栏目ID时，不返回任何自定义字段（保持与“未关联/不存在即不显示”的规则一致）
                $custom_fields_configs = array();
            }

            if (!empty($custom_fields_configs)) {
                // 转换字段配置格式以兼容现有代码
                foreach ($custom_fields_configs as $config) {
                    $field_array = is_object($config) ? (array) $config : $config;

                    $field_options_raw = !empty($field_array['field_options']) ? $field_array['field_options'] : '';
                    $field_options = array();
                    if (!empty($field_options_raw)) {
                        if (!function_exists('maybe_unserialize')) {
                            require_once ABSPATH . 'core/functions/formatting.php';
                        }
                        $decoded = maybe_unserialize($field_options_raw);
                        if (is_array($decoded)) {
                            $field_options = $decoded;
                        } else {
                            $json_decoded = json_decode($field_options_raw, true);
                            if (is_array($json_decoded)) {
                                $field_options = $json_decoded;
                            } else {
                                // 作为纯文本选项处理（例如 select 的 options）
                                $field_options = array('options' => $field_options_raw);
                            }
                        }
                    }

                    $all_fields[] = array(
                        'field_id' => isset($field_array['field_id']) ? $field_array['field_id'] : 0,
                        'field_key' => $field_array['field_key'],
                        'field_name' => $field_array['field_name'],
                        'field_label' => $field_array['field_label'] ?? $field_array['field_name'],
                        'field_type' => $field_array['field_type'],
                        'field_description' => isset($field_array['field_description']) ? $field_array['field_description'] : '',
                        'field_default' => isset($field_array['field_default']) ? $field_array['field_default'] : '',
                        'is_required' => isset($field_array['is_required']) ? $field_array['is_required'] : 0,
                        'field_options' => $field_options,
                        'field_order' => isset($field_array['field_order']) ? $field_array['field_order'] : 0,
                        'is_fixed' => 0
                    );
                }
                // 标记已获取到自定义字段，避免后续回退重复合并
                $has_custom_fields = true;
            }
        } catch (Exception $e) {
            error_log('获取自定义字段配置失败: ' . $e->getMessage());
        }
    }

    // 如果Todo_Model_Fields_Manager没有获取到字段，回退到Todo_Model_Manager
    // 注释掉回退逻辑，确保字段可见性严格依赖todo_term_field_visibility表
    if (!$has_custom_fields && empty($category_ids) && class_exists('Todo_Model_Manager')) {
        try {
            $custom_fields = Todo_Model_Manager::get_model_fields($model_key);

            if (!empty($custom_fields)) {
                $all_fields = array_merge($all_fields, $custom_fields);
                $has_custom_fields = true;
            }
        } catch (Exception $e) {
            error_log('模型管理器获取字段失败: ' . $e->getMessage());
        }
    }

    // 如果模型管理器没有获取到自定义字段，且未指定栏目ID时，回退到直接数据库查询
    // 注意：当提供了栏目ID但无可见字段时，不应回退为全部字段，确保“未绑定即不显示”
    if (!$has_custom_fields && empty($category_ids)) {
        error_log('获取模型字段失败（未指定栏目ID），回退到数据库查询');
        global $tododb;

        try {
            $sql = "SELECT f.* FROM " . TABLE_PREFIX . "model_fields f 
                    INNER JOIN " . TABLE_PREFIX . "models m ON f.model_id = m.model_id 
                    WHERE m.model_key = ? AND f.field_status = 'active'
                    ORDER BY f.field_order ASC, f.field_id ASC";

            $fields = $tododb->get_results($sql, [$model_key]);

            if ($fields) {
                // 转换为数组格式
                foreach ($fields as $field) {
                    // 处理对象或数组格式
                    $field_array = is_object($field) ? (array) $field : $field;

                    $field_options_raw = !empty($field_array['field_options']) ? $field_array['field_options'] : '';
                    $field_options = array();
                    if (!empty($field_options_raw)) {
                        if (!function_exists('maybe_unserialize')) {
                            require_once ABSPATH . 'core/functions/formatting.php';
                        }
                        $decoded = maybe_unserialize($field_options_raw);
                        if (is_array($decoded)) {
                            $field_options = $decoded;
                        } else {
                            $json_decoded = json_decode($field_options_raw, true);
                            if (is_array($json_decoded)) {
                                $field_options = $json_decoded;
                            } else {
                                // 作为纯文本选项处理（例如 select 的 options）
                                $field_options = array('options' => $field_options_raw);
                            }
                        }
                    }

                    $all_fields[] = array(
                        'field_id' => $field_array['field_id'],
                        'field_key' => $field_array['field_key'],
                        'field_name' => isset($field_array['field_label']) ? $field_array['field_label'] : $field_array['field_name'],
                        'field_label' => isset($field_array['field_label']) ? $field_array['field_label'] : $field_array['field_name'],
                        'field_type' => $field_array['field_type'],
                        'field_description' => isset($field_array['field_description']) ? $field_array['field_description'] : '',
                        'field_default' => isset($field_array['field_default']) ? $field_array['field_default'] : '',
                        'is_required' => isset($field_array['is_required']) ? $field_array['is_required'] : 0,
                        'field_options' => $field_options,
                        'field_order' => isset($field_array['field_order']) ? $field_array['field_order'] : 0,
                        'is_fixed' => 0
                    );
                }
            }
        } catch (Exception $e) {
            error_log('获取模型字段失败: ' . $e->getMessage());
        }
    }

    // 按“固定字段 -> SEO -> 自定义字段”的期望顺序排序
    if (!empty($all_fields)) {
        usort($all_fields, function ($a, $b) {
            $is_fixed_a = !empty($a['is_fixed']) ? 1 : 0;
            $is_fixed_b = !empty($b['is_fixed']) ? 1 : 0;
            if ($is_fixed_a !== $is_fixed_b) {
                return $is_fixed_b - $is_fixed_a;
            }

            $key_a = isset($a['field_key']) ? (string) $a['field_key'] : '';
            $key_b = isset($b['field_key']) ? (string) $b['field_key'] : '';
            $sec_a = isset($a['field_section']) ? (string) $a['field_section'] : '';
            $sec_b = isset($b['field_section']) ? (string) $b['field_section'] : '';

            $is_seo_a = ($key_a === 'seo_title' || $key_a === 'seo_keywords' || $key_a === 'seo_description' || stripos($sec_a, 'seo') !== false);
            $is_seo_b = ($key_b === 'seo_title' || $key_b === 'seo_keywords' || $key_b === 'seo_description' || stripos($sec_b, 'seo') !== false);

            if ($is_fixed_a === 1 && $is_seo_a !== $is_seo_b) {
                return $is_seo_a ? 1 : -1;
            }

            $order_a = isset($a['field_order']) ? (int) $a['field_order'] : 0;
            $order_b = isset($b['field_order']) ? (int) $b['field_order'] : 0;
            if ($order_a !== $order_b) {
                return $order_a - $order_b;
            }

            return strcmp($key_a, $key_b);
        });
    }

    $result = filter_fields_by_visibility($all_fields, $category_ids);
    $runtime_cache[$cache_key] = $result;
    return $result;
}

/**
 * 根据栏目可见性过滤字段
 * @param array $fields 字段数组
 * @param array $category_ids 栏目ID数组
 * @return array 过滤后的字段数组
 */
function filter_fields_by_visibility($fields, $category_ids = array())
{
    if (empty($fields) || empty($category_ids)) {
        return $fields;
    }

    $filtered_fields = array();

    foreach ($fields as $field) {
        // 检查字段是否对指定栏目可见
        $field_visibility = isset($field['field_visibility']) ? $field['field_visibility'] : array();

        // 如果没有设置可见性限制，或者当前栏目在可见列表中
        if (empty($field_visibility) || array_intersect($category_ids, $field_visibility)) {
            $filtered_fields[] = $field;
        }
    }

    return $filtered_fields;
}

/**
 * 获取文章的模型字段值
 * @param int $post_id 文章ID
 * @param array $category_ids 栏目ID数组
 * @return array 字段值数组
 */
function get_post_model_fields_values($post_id, $category_ids = array())
{
    if (empty($post_id)) {
        return array();
    }

    global $tododb;
    $all_field_values = array();

    // 使用新的模型字段管理器获取字段值
    if (class_exists('Todo_Model_Fields_Manager')) {
        try {
            // 使用新的模型字段管理器
            $model_fields_manager = new Todo_Model_Fields_Manager();

            // 获取文章所属的数据模型
            $post_models = array();
            if (!empty($category_ids)) {
                foreach ($category_ids as $category_id) {
                    $category_model = Todo_Model_Manager::get_category_model($category_id);
                    if ($category_model && $model_fields_manager->is_supported_model($category_model)) {
                        $post_models[] = $category_model;
                    }
                }
            }

            // 如果没有指定栏目，尝试从文章的分类中获取模型
            if (empty($post_models)) {
                // 直接查询数据库获取文章分类
                $category_sql = "SELECT tm.term_id, m.model_key 
                                  FROM " . TABLE_PREFIX . "term_relationships tr 
                                  JOIN " . TABLE_PREFIX . "term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id 
                                  JOIN " . TABLE_PREFIX . "term_models tm ON tt.term_id = tm.term_id 
                                  JOIN " . TABLE_PREFIX . "models m ON tm.model_id = m.model_id 
                                  WHERE tr.object_id = ? AND tt.taxonomy = 'category'";
                $post_categories = $tododb->get_results($category_sql, [$post_id]);

                if (!empty($post_categories)) {
                    foreach ($post_categories as $category) {
                        $category_model = is_object($category) ? $category->model_key : $category['model_key'];
                        if ($category_model && $model_fields_manager->is_supported_model($category_model)) {
                            $post_models[] = $category_model;
                        }
                    }
                }
            }

            // 如果仍然没有找到模型，尝试检查所有支持的模型表
            if (empty($post_models)) {
                $supported_models = ['article', 'product', 'images', 'download', 'media', 'single', 'special', 'guestbook', 'recruit'];
                foreach ($supported_models as $model_key) {
                    if ($model_fields_manager->is_supported_model($model_key)) {
                        // 检查该模型表中是否有该文章的数据
                        $table_name = TABLE_PREFIX . 'model_fields_' . $model_key;
                        $count_sql = "SELECT COUNT(*) FROM {$table_name} WHERE post_id = ?";
                        $count = $tododb->get_var($count_sql, [$post_id]);
                        if ($count > 0) {
                            $post_models[] = $model_key;
                        }
                    }
                }
            }

            // 从每个模型的独立表中获取字段值
            foreach (array_unique($post_models) as $model_key) {
                $model_fields = $model_fields_manager->get_model_fields($model_key, $post_id);
                // 确保是数组且不为null
                if ($model_fields && is_array($model_fields)) {
                    // 将字段值映射为前端字段名格式（添加 model_field_ 前缀）
                    foreach ($model_fields as $key => $value) {
                        // 如果键名已经是 model_field_ 开头，直接使用
                        if (str_starts_with($key, 'model_field_')) {
                            $all_field_values[$key] = $value;
                        } else {
                            // 否则添加 model_field_ 前缀
                            $all_field_values['model_field_' . $key] = $value;
                            // 同时保留原键名，以兼容某些特殊情况
                            $all_field_values[$key] = $value;
                        }
                    }
                }
            }

            // 如果从模型表中获取到了数据，直接返回
            if (!empty($all_field_values)) {
                return $all_field_values;
            }

        } catch (Exception $e) {
            error_log('新模型字段管理器获取字段值失败: ' . $e->getMessage());
        }
    }

    try {
        // 获取自定义字段
        $sql = "SELECT field_key, field_value FROM " . TABLE_PREFIX . "post_custom_fields WHERE post_id = ? AND field_key LIKE 'model_field_%'";
        $meta_data = $tododb->get_results($sql, [$post_id]);

        $field_values = array();
        if ($meta_data) {
            foreach ($meta_data as $meta) {
                $key = is_array($meta) ? $meta['field_key'] : $meta->field_key;
                $value = is_array($meta) ? $meta['field_value'] : $meta->field_value;
                $field_values[$key] = maybe_unserialize($value);
            }
        }

        // 获取固定字段值（通过模型类）
        if (!empty($category_ids)) {
            foreach ($category_ids as $category_id) {
                $model_key = Todo_Model_Manager::get_category_model($category_id);
                if ($model_key) {
                    $model_class = Todo_Model_Manager::get_model_class($model_key);
                    if ($model_class && method_exists($model_class, 'get_fixed_field_values')) {
                        $fixed_values = call_user_func([$model_class, 'get_fixed_field_values'], $post_id);
                        if (is_array($fixed_values)) {
                            $field_values = array_merge($field_values, $fixed_values);
                        }
                        break; // 只需要获取一次固定字段值
                    }
                }
            }
        }

        return $field_values;
    } catch (Exception $e) {
        error_log('获取文章模型字段值失败: ' . $e->getMessage());
        return array();
    }
}

/**
 * 输出模型字段组件样式
 * 用于在需要的页面加载组件相关的CSS样式
 */
function enqueue_model_fields_component_styles()
{
    // 输出模型字段组件的CSS样式
    echo '<style type="text/css">';
    echo '
/* 模型字段组件样式 */';
    echo '
.model-fields-container {';
    echo '
    margin: 20px 0;';
    echo '
    padding: 15px;';
    echo '
    box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);';
    echo '
    border-radius: 3px;';
    echo '
    background-color: #fff;';
    echo '
    position: relative;';
    echo '
}';
    echo '
.model-fields-group .model-title {';
    echo '
    margin: 0 0 15px 0;';
    echo '
    padding: 0 0 10px 0;';
    echo '
    border-bottom: 1px solid #eceef1;';
    echo '
    font-size: 16px;';
    echo '
    font-weight: 500;';
    echo '
    color: #333;';
    echo '
}';
    echo '
.model-field {';
    echo '
    margin-bottom: 15px;';
    echo '
}';
    echo '
.model-field .form-control {';
    echo '
    width: 100%;';
    echo '
    padding: 8px 12px;';
    echo '
    border: 1px solid #ddd;';
    echo '
    border-radius: 4px;';
    echo '
    font-size: 14px;';
    echo '
    line-height: 1.5;';
    echo '
}';
    echo '
.model-field .form-control:focus {';
    echo '
    border-color: #0073aa;';
    echo '
    outline: none;';
    echo '
    box-shadow: 0 0 0 2px rgba(0, 115, 170, 0.2);';
    echo '
}';
    echo '
.model-field .form-check-input {';
    echo '
    margin-right: 8px;';
    echo '
}';
    echo '
.model-field .form-text {';
    echo '
    margin-top: 5px;';
    echo '
    font-size: 13px;';
    echo '
    color: #aaa;';
    echo '
    font-style: italic;';
    echo '
}';
    echo '
.model-field textarea.form-control {';
    echo '
    resize: vertical;';
    echo '
    min-height: 80px;';
    echo '
}';
    echo '
.model-field select.form-control {';
    echo '
    height: auto;';
    echo '
    padding: 8px 12px;';
    echo '
}';
    echo '
.model-fields-loading {';
    echo '
    text-align: center;';
    echo '
    padding: 20px;';
    echo '
    color: #666;';
    echo '
}';
    echo '
.model-fields-error {';
    echo '
    padding: 10px;';
    echo '
    background-color: #f8d7da;';
    echo '
    border: 1px solid #f5c6cb;';
    echo '
    border-radius: 4px;';
    echo '
    color: #721c24;';
    echo '
}';
    echo '
</style>';
}

/**
 * 根据模型键获取模型类名 (Compatibility Wrapper)
 */
if (!function_exists('get_model_class_by_key')) {
    function get_model_class_by_key($model_key)
    {
        return Todo_Model_Manager::get_model_class($model_key);
    }
}

/**
 * 获取所有栏目 (Compatibility Wrapper)
 */
if (!function_exists('get_all_categories')) {
    function get_all_categories()
    {
        if (class_exists('Todo_Category_Manager')) {
            $cats = Todo_Category_Manager::get_categories();
            // Map id to term_id for compatibility
            foreach ($cats as &$cat) {
                $cat['term_id'] = $cat['id'];
            }
            return $cats;
        }
        return [];
    }
}

/**
 * 获取模型特色字段HTML (Compatibility Wrapper)
 */
if (!function_exists('get_model_featured_fields_html')) {
    function get_model_featured_fields_html($model_key, $field_values, $post_id, $name_prefix = 'model_field_', $id_prefix = '', $suppress_attrs = false, $category_ids = array())
    {
        $all_fields = get_component_model_fields($model_key, $category_ids);
        $html = '';

        if (empty($all_fields)) {
            return $html;
        }

        // Group fields by section
        $sections = [];
        $default_section = '基础设置';

        foreach ($all_fields as $field) {
            if (!empty($field['is_fixed'])) {
                $section = !empty($field['field_section']) ? $field['field_section'] : $default_section;
                if (!isset($sections[$section])) {
                    $sections[$section] = [];
                }
                $sections[$section][] = $field;
            }
        }

        // Render sections
        foreach ($sections as $section_name => $fields) {
            // Section wrapper
            // Sanitize title logic: lowercase, replace special chars with hyphen
            $slug = strtolower($section_name);
            $slug = preg_replace('/[^a-z0-9\x{4e00}-\x{9fa5}]/u', '-', $slug);
            $slug = preg_replace('/-+/', '-', $slug);
            $slug = trim($slug, '-');

            $html .= '<div class="fixed-fields-section section-' . esc_attr($slug) . '">';
            $html .= '<h4 class="section-title"><span>' . esc_html($section_name) . '</span></h4>';
            $html .= '<div class="flex justify-between flex-wrap">';

            foreach ($fields as $field) {
                $key = $field['field_key'];
                // Try different key formats for value
                $val = '';
                if (isset($field_values[$name_prefix . $key])) {
                    $val = $field_values[$name_prefix . $key];
                } elseif (isset($field_values[$key])) {
                    $val = $field_values[$key];
                } else {
                    $val = isset($field['field_default']) ? $field['field_default'] : '';
                }

                // Check for special fields handled by Todo_Field_Renderer
                $special_keys = [
                    'product_video', 'product_type', 'product_services', 'spec_type', 
                    'price', 'wholesale_price', 'wholesale_vip_price', 'original_price', 'product_code', 'stock', 'virtual_sales', 
                    'discount_type', 'discount_config', 'show_stock', 'sale_status', 
                    'delivery_type', 'delivery_express_note', 'delivery_download_url', 
                    'delivery_download_code', 'delivery_download_password', 
                    'delivery_download_note', 'delivery_content_note', 'auto_gen_license_key',
                    'custom_params', 'spec_config', 'spec_details', 
                    'download_config'
                ];

                if (in_array($key, $special_keys) || (isset($field['field_type']) && $field['field_type'] === 'switch')) {
                    // Adapt field for Todo_Field_Renderer
                    $adapter_field = [
                        'key' => $field['field_key'],
                        'type' => $field['field_type'],
                        'name' => $field['field_name'],
                        'value' => $val,
                        'required' => !empty($field['is_required']),
                        'description' => isset($field['field_description']) ? $field['field_description'] : '',
                        'options' => isset($field['field_options']) ? json_decode($field['field_options'], true) : [],
                        'attributes' => []
                    ];
                    // Ensure class exists
                    if (class_exists('Todo_Field_Renderer')) {
                        $html .= Todo_Field_Renderer::render_model_field($adapter_field);
                    } else {
                         $html .= render_field_html($field, $val, $post_id, $name_prefix, $id_prefix, $suppress_attrs);
                    }
                } else {
                    $html .= render_field_html($field, $val, $post_id, $name_prefix, $id_prefix, $suppress_attrs);
                }
            }

            $html .= '</div>'; // Close flex
            $html .= '</div>'; // Close section
        }

        return $html;
    }
}

// 检查是否为自定义模型
if (isset($model_key) && Todo_Model_Manager::is_custom_model($model_key)) {
    // 加载自定义模型基类
    $custom_class_file = ABSPATH . 'core/classes/posts/class-model-custom.php';
    if (file_exists($custom_class_file)) {
        require_once $custom_class_file;
        return 'CMS_Post_Type_Custom';
    }
}

return null;
