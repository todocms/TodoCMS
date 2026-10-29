<?php
/**
 * TodoCMS 字段渲染器
 * 
 * 负责渲染各种类型的字段HTML
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class Todo_Field_Renderer
{

    /**
     * 渲染字段HTML
     *
     * @param array $field 字段配置
     * @param mixed $value 字段值
     * @param array $options 渲染选项
     * @return string 字段HTML
     */
    public static function render_field($field, $value = '', $options = [])
    {
        // 确保字段类型定义已加载
        if (!class_exists('Todo_Field_Types')) {
            require_once dirname(__FILE__) . '/class-todo-field-types.php';
        }

        $field_type = $field['type'] ?? 'text';
        $field_name = $field['name'] ?? '';
        $field_label = $field['label'] ?? '';
        $field_id = $options['id'] ?? 'field_' . $field_name;
        $required = $field['required'] ?? false;
        $description = $field['description'] ?? '';
        $settings = $field['settings'] ?? [];

        // 验证字段类型
        if (!Todo_Field_Types::is_valid_type($field_type)) {
            return '<div class="field-error">未知字段类型: ' . esc_html($field_type) . '</div>';
        }

        $html = '<div class="field-group field-type-' . esc_attr($field_type) . '">';

        // 字段标签
        if ($field_label) {
            $html .= '<label for="' . esc_attr($field_id) . '" class="field-label">';
            $html .= esc_html($field_label);
            if ($required) {
                $html .= ' <span class="required">*</span>';
            }
            $html .= '</label>';
        }

        // 字段输入
        $html .= '<div class="field-input">';
        $html .= self::render_field_input($field_type, $field_name, $value, $field_id, $settings, $required);
        $html .= '</div>';

        // 字段描述
        if ($description) {
            $html .= '<div class="field-description">' . esc_html($description) . '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * 渲染字段输入HTML
     *
     * @param string $type 字段类型
     * @param string $name 字段名称
     * @param mixed $value 字段值
     * @param string $id 字段ID
     * @param array $settings 字段设置
     * @param bool $required 是否必填
     * @return string 输入HTML
     */
    private static function render_field_input($type, $name, $value, $id, $settings, $required)
    {
        $method = 'render_' . $type . '_field';

        if (method_exists(__CLASS__, $method)) {
            return self::$method($name, $value, $id, $settings, $required);
        }

        // 默认渲染为文本输入
        return self::render_text_field($name, $value, $id, $settings, $required);
    }
    /**
     * 渲染文本字段
     */
    private static function render_text_field($name, $value, $id, $settings, $required)
    {
        $placeholder = $settings['placeholder'] ?? '';
        $maxlength = $settings['maxlength'] ?? '';
        $pattern = $settings['pattern'] ?? '';

        $attrs = [
            'type="text"',
            'name="' . esc_attr($name) . '"',
            'id="' . esc_attr($id) . '"',
            'value="' . esc_attr($value) . '"',
            'class="form-control"'
        ];

        if ($placeholder)
            $attrs[] = 'placeholder="' . esc_attr($placeholder) . '"';
        if ($maxlength)
            $attrs[] = 'maxlength="' . esc_attr($maxlength) . '"';
        if ($pattern)
            $attrs[] = 'pattern="' . esc_attr($pattern) . '"';
        if ($required)
            $attrs[] = 'required';

        return '<input ' . implode(' ', $attrs) . ' />';
    }

    /**
     * 渲染多行文本字段
     */
    private static function render_textarea_field($name, $value, $id, $settings, $required)
    {
        $placeholder = $settings['placeholder'] ?? '';
        $rows = $settings['rows'] ?? '4';
        $maxlength = $settings['maxlength'] ?? '';

        $attrs = [
            'name="' . esc_attr($name) . '"',
            'id="' . esc_attr($id) . '"',
            'class="form-control"',
            'rows="' . esc_attr($rows) . '"'
        ];

        if ($placeholder)
            $attrs[] = 'placeholder="' . esc_attr($placeholder) . '"';
        if ($maxlength)
            $attrs[] = 'maxlength="' . esc_attr($maxlength) . '"';
        if ($required)
            $attrs[] = 'required';

        return '<textarea ' . implode(' ', $attrs) . '>' . esc_html($value) . '</textarea>';
    }

    /**
     * 渲染HTML编辑器字段
     */
    private static function render_html_field($name, $value, $id, $settings, $required)
    {
        $height = $settings['height'] ?? '300px';
        $toolbar = $settings['toolbar'] ?? 'full';

        $html = '<div class="html-editor-wrapper">';
        $html .= '<textarea name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" class="html-editor" ';
        $html .= 'data-height="' . esc_attr($height) . '" data-toolbar="' . esc_attr($toolbar) . '"';
        if ($required)
            $html .= ' required';
        $html .= '>' . esc_html($value) . '</textarea>';
        $html .= '</div>';

        return $html;
    }

    /**
     * 渲染下拉选择字段
     */
    private static function render_select_field($name, $value, $id, $settings, $required)
    {
        $options = $settings['options'] ?? [];
        $multiple = $settings['multiple'] ?? false;
        $searchable = $settings['searchable'] ?? false;

        $attrs = [
            'name="' . esc_attr($name) . ($multiple ? '[]' : '') . '"',
            'id="' . esc_attr($id) . '"',
            'class="form-control' . ($searchable ? ' select2' : '') . '"'
        ];

        if ($multiple)
            $attrs[] = 'multiple';
        if ($required)
            $attrs[] = 'required';

        $html = '<select ' . implode(' ', $attrs) . '>';

        if (!$multiple && !$required) {
            $html .= '<option value="">请选择...</option>';
        }

        foreach ($options as $option_value => $option_label) {
            $selected = '';
            if ($multiple) {
                $selected = is_array($value) && in_array($option_value, $value) ? ' selected' : '';
            } else {
                $selected = $value == $option_value ? ' selected' : '';
            }

            $html .= '<option value="' . esc_attr($option_value) . '"' . $selected . '>';
            $html .= esc_html($option_label);
            $html .= '</option>';
        }

        $html .= '</select>';

        return $html;
    }
    /**
     * 渲染单选按钮字段
     */
    private static function render_radio_field($name, $value, $id, $settings, $required)
    {
        $options = $settings['options'] ?? [];
        $layout = $settings['layout'] ?? 'vertical';

        $html = '<div class="radio-group layout-' . esc_attr($layout) . '">';

        foreach ($options as $option_value => $option_label) {
            $checked = $value == $option_value ? ' checked' : '';
            $radio_id = $id . '_' . $option_value;

            $html .= '<div class="radio-item">';
            $html .= '<input type="radio" name="' . esc_attr($name) . '" id="' . esc_attr($radio_id) . '" ';
            $html .= 'value="' . esc_attr($option_value) . '"' . $checked;
            if ($required)
                $html .= ' required';
            $html .= ' />';
            $html .= '<label for="' . esc_attr($radio_id) . '">' . esc_html($option_label) . '</label>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * 渲染复选框字段
     */
    private static function render_checkbox_field($name, $value, $id, $settings, $required)
    {
        $options = $settings['options'] ?? [];
        $layout = $settings['layout'] ?? 'vertical';
        $max_selections = $settings['max_selections'] ?? 0;

        $value_array = is_array($value) ? $value : [];

        $scope_id = $id . '_checkbox_group';
        $html = '<div class="checkbox-group layout-' . esc_attr($layout) . '" id="' . esc_attr($scope_id) . '"';
        if ($max_selections > 0) {
            $html .= ' data-max-selections="' . esc_attr($max_selections) . '"';
        }
        $html .= '>';

        foreach ($options as $option_value => $option_label) {
            $checked = in_array($option_value, $value_array) ? ' checked' : '';
            $checkbox_id = $id . '_' . $option_value;

            $html .= '<div class="checkbox-item">';
            $html .= '<input type="checkbox" name="' . esc_attr($name) . '[]" id="' . esc_attr($checkbox_id) . '" ';
            $html .= 'value="' . esc_attr($option_value) . '"' . $checked;
            $html .= ' />';
            $html .= '<label for="' . esc_attr($checkbox_id) . '">' . esc_html($option_label) . '</label>';
            $html .= '</div>';
        }

        $html .= '</div>';
        if ($required) {
            $html .= '<script type="text/javascript">' . "\n"
                . '(function(){' . "\n"
                . '  var root = document.getElementById("' . self::esc_attr($scope_id) . '");' . "\n"
                . '  if(!root) return;' . "\n"
                . '  var boxes = root.querySelectorAll("input[type=checkbox]");' . "\n"
                . '  if(!boxes || boxes.length === 0) return;' . "\n"
                . '  var first = boxes[0];' . "\n"
                . '  function update(){' . "\n"
                . '    var any = false;' . "\n"
                . '    boxes.forEach(function(b){ if(b.checked) any = true; });' . "\n"
                . '    first.setCustomValidity(any ? "" : "请至少选择一项");' . "\n"
                . '  }' . "\n"
                . '  boxes.forEach(function(b){ b.addEventListener("change", update); });' . "\n"
                . '  update();' . "\n"
                . '})();' . "\n"
                . '</script>';
        }

        return $html;
    }

    /**
     * 渲染数字字段
     */
    private static function render_number_field($name, $value, $id, $settings, $required)
    {
        $min = $settings['min'] ?? '';
        $max = $settings['max'] ?? '';
        $step = $settings['step'] ?? '1';

        $attrs = [
            'type="number"',
            'name="' . esc_attr($name) . '"',
            'id="' . esc_attr($id) . '"',
            'value="' . esc_attr($value) . '"',
            'class="form-control"',
            'step="' . esc_attr($step) . '"'
        ];

        if ($min !== '')
            $attrs[] = 'min="' . esc_attr($min) . '"';
        if ($max !== '')
            $attrs[] = 'max="' . esc_attr($max) . '"';
        if ($required)
            $attrs[] = 'required';

        return '<input ' . implode(' ', $attrs) . ' />';
    }

    /**
     * 渲染小数字段
     */
    private static function render_decimal_field($name, $value, $id, $settings, $required)
    {
        $min = $settings['min'] ?? '';
        $max = $settings['max'] ?? '';
        $precision = $settings['precision'] ?? '2';
        $step = '0.' . str_repeat('0', $precision - 1) . '1';

        $attrs = [
            'type="number"',
            'name="' . esc_attr($name) . '"',
            'id="' . esc_attr($id) . '"',
            'value="' . esc_attr($value) . '"',
            'class="form-control"',
            'step="' . esc_attr($step) . '"'
        ];

        if ($min !== '')
            $attrs[] = 'min="' . esc_attr($min) . '"';
        if ($max !== '')
            $attrs[] = 'max="' . esc_attr($max) . '"';
        if ($required)
            $attrs[] = 'required';

        return '<input ' . implode(' ', $attrs) . ' />';
    }

    /**
     * 渲染金额字段
     */
    private static function render_price_field($name, $value, $id, $settings, $required)
    {
        $currency = $settings['currency'];
        $min = $settings['min'] ?? '0';
        $max = $settings['max'] ?? '';

        $html = '<div class="price-field-wrapper">';
        $html .= '<span class="currency-symbol">' . esc_html($currency) . '</span>';

        $attrs = [
            'type="number"',
            'name="' . esc_attr($name) . '"',
            'id="' . esc_attr($id) . '"',
            'value="' . esc_attr($value) . '"',
            'class="form-control price-input"',
            'step="0.01"'
        ];

        if ($min !== '')
            $attrs[] = 'min="' . esc_attr($min) . '"';
        if ($max !== '')
            $attrs[] = 'max="' . esc_attr($max) . '"';
        if ($required)
            $attrs[] = 'required';

        $html .= '<input ' . implode(' ', $attrs) . ' />';
        $html .= '</div>';

        return $html;
    }

    /**
     * 渲染图片上传字段
     */
    private static function render_image_field($name, $value, $id, $settings, $required)
    {
        $max_size = $settings['max_size'] ?? '2MB';
        $allowed_types = $settings['allowed_types'] ?? 'jpg,jpeg,png,gif,webp';

        $html = '<div class="image-upload-wrapper" data-field-name="' . esc_attr($name) . '">';

        // 隐藏的输入字段存储图片URL
        $html .= '<input type="hidden" name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" value="' . esc_attr($value) . '"';
        if ($required)
            $html .= ' required';
        $html .= ' />';

        // 图片预览区域
        $html .= '<div class="image-preview">';
        if ($value) {
            $html .= '<img src="' . esc_attr($value) . '" alt="预览图片" />';
            $html .= '<button type="button" class="btn-remove-image" title="删除图片">×</button>';
        } else {
            $html .= '<div class="no-image">点击上传图片</div>';
        }
        $html .= '</div>';

        // 上传按钮
        $html .= '<div class="upload-controls">';
        $html .= '<button type="button" class="btn btn-primary btn-upload-image">选择图片</button>';
        $html .= '<small class="upload-info">支持格式: ' . esc_html($allowed_types) . ', 最大: ' . esc_html($max_size) . '</small>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * 渲染多图上传字段
     */
    private static function render_gallery_field($name, $value, $id, $settings, $required)
    {
        $max_files = $settings['max_files'] ?? '10';
        $max_size = $settings['max_size'] ?? '2MB';
        $sortable = $settings['sortable'] ?? true;

        $images = is_array($value) ? $value : ($value ? explode(',', $value) : []);

        $html = '<div class="gallery-upload-wrapper" data-field-name="' . esc_attr($name) . '" data-max-files="' . esc_attr($max_files) . '">';

        // 隐藏的输入字段存储图片URLs（逗号分隔）
        $html .= '<input type="hidden" name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" value="' . esc_attr(implode(',', $images)) . '"';
        if ($required)
            $html .= ' required';
        $html .= ' />';

        // 图片列表
        $html .= '<div class="gallery-list' . ($sortable ? ' sortable' : '') . '">';
        foreach ($images as $index => $image_url) {
            if ($image_url) {
                $html .= '<div class="gallery-item" data-url="' . esc_attr($image_url) . '">';
                $html .= '<img src="' . esc_attr($image_url) . '" alt="图片 ' . ($index + 1) . '" />';
                $html .= '<div class="gallery-item-controls">';
                if ($sortable) {
                    $html .= '<button type="button" class="btn-move" title="拖拽排序">⋮⋮</button>';
                }
                $html .= '<button type="button" class="btn-remove" title="删除">×</button>';
                $html .= '</div>';
                $html .= '</div>';
            }
        }
        $html .= '</div>';

        // 上传控制
        $html .= '<div class="upload-controls">';
        $html .= '<button type="button" class="btn btn-primary btn-upload-gallery">添加图片</button>';
        $html .= '<small class="upload-info">最多 ' . esc_html($max_files) . ' 张图片, 每张最大: ' . esc_html($max_size) . '</small>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }
    /**
     * 渲染日期时间字段
     */
    private static function render_datetime_field($name, $value, $id, $settings, $required)
    {
        $format = $settings['format'] ?? 'Y-m-d H:i:s';
        $min_date = $settings['min_date'] ?? '';
        $max_date = $settings['max_date'] ?? '';
        $time_enabled = $settings['time_enabled'] ?? true;

        $input_type = $time_enabled ? 'datetime-local' : 'date';
        $input_format = $time_enabled ? 'Y-m-d\TH:i' : 'Y-m-d';

        // 转换值格式
        $input_value = '';
        if ($value) {
            $timestamp = is_numeric($value) ? $value : strtotime($value);
            if ($timestamp) {
                $input_value = date($input_format, $timestamp);
            }
        }

        $attrs = [
            'type="' . $input_type . '"',
            'name="' . esc_attr($name) . '"',
            'id="' . esc_attr($id) . '"',
            'value="' . esc_attr($input_value) . '"',
            'class="form-control datetime-picker"'
        ];

        if ($min_date) {
            $min_timestamp = strtotime($min_date);
            if ($min_timestamp) {
                $attrs[] = 'min="' . date($input_format, $min_timestamp) . '"';
            }
        }

        if ($max_date) {
            $max_timestamp = strtotime($max_date);
            if ($max_timestamp) {
                $attrs[] = 'max="' . date($input_format, $max_timestamp) . '"';
            }
        }

        if ($required)
            $attrs[] = 'required';

        return '<input ' . implode(' ', $attrs) . ' />';
    }

    /**
     * 安全转义HTML属性
     */
    private static function esc_attr($text)
    {
        // 处理数组类型，转换为字符串
        if (is_array($text)) {
            $text = implode(', ', $text);
        }
        // 确保是字符串类型
        $text = (string) $text;
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 安全转义HTML内容
     */
    private static function esc_html($text)
    {
        // 处理数组类型，转换为字符串
        if (is_array($text)) {
            $text = implode(', ', $text);
        }
        // 确保是字符串类型
        $text = (string) $text;
        return htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
    }

    /**
     * 数据结构适配器 - 将旧版fields结构转换为统一的sections结构
     * 
     * @param array $data 原始数据（可能是sections结构或fields结构）
     * @return array 统一的sections结构数据
     */
    public static function normalize_fields_data($data)
    {
        // 如果已经是sections结构，直接返回
        if (isset($data['sections'])) {
            return $data;
        }

        // 转换旧版fields结构为sections结构
        $normalized = [
            'include_wrapper' => $data['include_wrapper'] ?? true,
            'sections' => []
        ];

        // 如果有fields数据，包装成一个section
        if (isset($data['fields']) && is_array($data['fields'])) {
            $section = [
                'section_title' => $data['section_title'] ?? '基础设置',
                'section_class' => $data['section_class'] ?? 'fixed-fields-section',
                'fields' => $data['fields']
            ];
            $normalized['sections'][] = $section;
        }

        return $normalized;
    }

    /**
     * 统一的sections结构渲染器
     * 
     * @param array $data sections结构的数据
     * @return string 渲染后的HTML
     */
    public static function render_sections_html($data)
    {
        // 先通过适配器统一数据结构
        $normalized_data = self::normalize_fields_data($data);

        $html = '';

        // 遍历所有sections
        foreach ($normalized_data['sections'] as $section) {
            if ($normalized_data['include_wrapper']) {
                $html .= '<div class="' . self::esc_attr($section['section_class']) . '">';
                $html .= '<h4 class="section-title"><span>' . self::esc_html($section['section_title']) . '</span></h4>';
                $html .= '<div class="flex justify-between flex-wrap">';
            }

            // 渲染section中的所有字段
            foreach ($section['fields'] as $field) {
                $html .= self::render_model_field($field);
            }

            if ($normalized_data['include_wrapper']) {
                $html .= '</div></div>';
            }
        }

        return $html;
    }

    /**
     * 渲染模型字段HTML（兼容各种模型类的字段格式）
     * 
     * @param array $field 字段数据
     * @return string 字段HTML
     */
    public static function render_model_field($field)
    {
        $html = '';

        // 获取字段基本信息
        $field_type = $field['type'] ?? 'text';
        $field_key = $field['key'] ?? '';
        $field_name = $field['name'] ?? '';
        $field_value = $field['value'] ?? '';
        $required = $field['required'] ?? false;
        $description = $field['description'] ?? '';
        $attributes = $field['attributes'] ?? [];

        if ($field_key === 'unit_qty') {
            return '';
        }

        // 优先使用专用渲染器
        if ($field_type === 'switch') {
            return self::render_switch_field($field_key, $field_value, $field_name, $description);
        }

        $layout = [];
        if (isset($field['layout']) && is_array($field['layout'])) {
            $layout = $field['layout'];
        } elseif (isset($field['options']) && is_array($field['options']) && isset($field['options']['layout']) && is_array($field['options']['layout'])) {
            $layout = $field['options']['layout'];
        } elseif (isset($attributes['layout']) && is_array($attributes['layout'])) {
            $layout = $attributes['layout'];
        }

        $full_width = null;
        if (is_array($layout) && array_key_exists('full_width', $layout)) {
            $full_width = (bool) $layout['full_width'];
        }
        if ($full_width === null) {
            $full_width = $field_type === 'editor';
        }
        $sub_width = false;
        if (is_array($layout) && array_key_exists('sub_width', $layout)) {
            $sub_width = (bool) $layout['sub_width'];
        }

        $w100 = $full_width ? ' w-100' : '';
        if ($full_width) {
            $w100 .= $sub_width ? ' sub-100' : '';
        }

        // 默认隐藏字段
        $fieldHide = in_array($field_key, [
            'delivery_express_note', // 快递发货说明
            'delivery_download_url', // 网盘URL
            'delivery_download_code', // 提取码
            'delivery_download_password', // 解压密码
            'delivery_download_note', // 网盘下载说明
            'delivery_content_note', // 文本内容说明
            'auto_gen_license_key', // 自动生成授权码
            'spec_config', // 规格配置
            'spec_details', // 规格明细
            'discount_config' // 折扣配置
        ]) ? ' style="display:none;"' : '';

        // 单规格的字段标记
        $fielSpec = in_array($field_key, [
            'price', // 现售价
            'wholesale_price', // 批发价
            'wholesale_vip_price', // 高级批发价
            'original_price', // 原价
            'discount_type', // 折扣类型
            'discount_config', // 折扣配置
            'product_code', // 商品编码
            'stock', // 商品库存
            'show_stock', // 前端显示库存
            'virtual_sales' // 虚拟销量
        ]) ? ' data-spec-visibility="single"' : '';

        // 字段容器
        $html .= '<div class="form-group fixed-field' . $w100 . '" data-field-type="' . self::esc_attr($field_type) . '" data-field-key="' . self::esc_attr($field_key) . '"' . $fielSpec . $fieldHide . '>';

        $is_auto_gen_key = ($field_key === 'auto_gen_license_key');
        if (!$is_auto_gen_key) {
            $html .= '<h4>' . self::esc_html($field_name);
            if ($required) {
                $html .= ' <span class="required">*</span>';
            }
            $html .= '</h4>';
        }

        // 产品模型特殊字段的个性化渲染
        if ($field_key === 'product_video') {
            // 产品视频使用特色图像组件（支持视频）
            $html .= self::render_product_video_field($field_key, $field_value, $required);
        } elseif ($field_key === 'product_type') {
            // 产品类型使用radio渲染
            $html .= self::render_product_type_field($field_key, $field_value, $required);
        } elseif ($field_key === 'product_services') {
            // 产品服务使用checkbox渲染
            $html .= self::render_product_services_field($field_key, $field_value, $required);
        } elseif ($field_key === 'unit') {
            $field_id = $field_key . '_' . uniqid();
            $field_settings = $field['options'] ?? [];
            $html .= self::render_unit_field($field_key, $field_value, $field_id, $field_settings, $required);
        } elseif ($field_key === 'spec_type') {
            // 规格类型使用radio渲染
            $html .= self::render_spec_type_field($field_key, $field_value, $required);
        } elseif ($field_key === 'discount_type') {
            // 折扣类型使用radio渲染
            $html .= self::render_discount_type_field($field_key, $field_value, $required);
        } elseif ($field_key === 'discount_config') {
            // 折扣配置：当选择“指定等级”时，按会员等级设置价格
            $html .= self::render_discount_config_field($field_key, $field_value, $required);
        } elseif ($field_key === 'show_stock') {
            // 前端显示库存使用checkbox渲染
            $html .= self::render_show_stock_field($field_key, $field_value, $required);
        } elseif ($field_key === 'sale_status') {
            // 发售状态使用radio渲染
            $html .= self::render_sale_status_field($field_key, $field_value, $required);
        } elseif ($field_key === 'delivery_type') {
            // 交付物类型使用radio渲染（带条件显示）
            $html .= self::render_delivery_type_field($field_key, $field_value, $required);
        } elseif ($field_key === 'custom_params') {
            $html .= self::render_custom_params_field_scoped($field_key, $field_value, $required);
        } elseif ($field_key === 'spec_config') {
            // 规格配置作为隐藏字段，由规格构建器同步写入 JSON（后端渲染显示时拼接上子目录前缀）
            $value = is_string($field_value) ? $field_value : json_encode($field_value, JSON_UNESCAPED_UNICODE);
            if (!empty($value) && function_exists('todo_media_url_to_absolute')) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    array_walk_recursive($decoded, function (&$v) {
                        if (is_string($v) && $v !== '' && (strpos($v, '/content/') !== false || strpos($v, 'content/uploads/') !== false)) {
                            $v = todo_media_url_to_absolute($v);
                        }
                    });
                    $value = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                }
            }
            $html .= '<textarea name="spec_config" id="spec_config" style="display:none;">' . self::esc_html($value) . '</textarea>';
        } elseif ($field_key === 'spec_details') {
            // 规格明细作为隐藏字段，由规格构建器同步写入 JSON
            $value = is_string($field_value) ? $field_value : json_encode($field_value, JSON_UNESCAPED_UNICODE);
            $html .= '<textarea name="spec_details" id="spec_details" style="display:none;">' . self::esc_html($value) . '</textarea>';
        } elseif ($field_key === 'download_config') {
            // 下载模型的列队
            $field_id = $field_key . '_' . uniqid();
            $field_settings = $field['options'] ?? [];
            $html .= self::render_download_builder_field($field_key, $field_value, $field_id, $field_settings, $required);
        } else {
            // 根据字段类型渲染输入控件
            switch ($field_type) {
                case 'textarea':
                    $html .= '<textarea class="form-control" name="' . self::esc_attr($field_key) . '" id="' . self::esc_attr($field_key) . '"';
                    foreach ($attributes as $attr => $value) {
                        $html .= ' ' . $attr . '="' . self::esc_attr($value) . '"';
                    }
                    if ($required) {
                        $html .= ' required';
                    }
                    $html .= '>' . self::esc_html($field_value) . '</textarea>';
                    break;

                case 'select':
                    $options = $field['options'] ?? [];
                    $html .= '<select class="form-control" name="' . self::esc_attr($field_key) . '" id="' . self::esc_attr($field_key) . '"';
                    foreach ($attributes as $attr => $value) {
                        $html .= ' ' . $attr . '="' . self::esc_attr($value) . '"';
                    }
                    if ($required) {
                        $html .= ' required';
                    }
                    $html .= '>';

                    foreach ($options as $option_value => $option_label) {
                        $selected = ($field_value == $option_value) ? ' selected' : '';
                        $html .= '<option value="' . self::esc_attr($option_value) . '"' . $selected . '>';
                        $html .= self::esc_html($option_label);
                        $html .= '</option>';
                    }
                    $html .= '</select>';
                    break;

                case 'checkbox':
                    $checked = $field_value ? ' checked' : '';
                    $html .= '<input type="checkbox" class="form-check-input" name="' . self::esc_attr($field_key) . '" style="vertical-align: middle;" id="' . self::esc_attr($field_key) . '" value="1"' . $checked;
                    foreach ($attributes as $attr => $value) {
                        $html .= ' ' . $attr . '="' . self::esc_attr($value) . '"';
                    }
                    if ($required) {
                        $html .= ' required';
                    }
                    $html .= '>';
                    break;

                default: // text 和其他类型
                    $html .= '<input type="text" class="form-control" name="' . self::esc_attr($field_key) . '" id="' . self::esc_attr($field_key) . '" value="' . self::esc_attr($field_value) . '"';
                    foreach ($attributes as $attr => $value) {
                        $html .= ' ' . $attr . '="' . self::esc_attr($value) . '"';
                    }
                    if ($required) {
                        $html .= ' required';
                    }
                    $html .= '>';
                    break;
            }
        }

        if ($is_auto_gen_key) {
            $html .= '<h4 style="display: inline; margin-left: 5px; vertical-align: middle;">' . self::esc_html($field_name);
            if ($required) {
                $html .= ' <span class="required">*</span>';
            }
            $html .= '</h4>';
        }

        // 字段描述
        if (!empty($description)) {
            $html .= '<p class="description">' . self::esc_html($description) . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    private static function render_unit_field($field_key, $field_value, $field_id, $settings, $required)
    {
        $options = [];
        if (is_array($settings) && isset($settings['options']) && is_array($settings['options'])) {
            $options = $settings['options'];
        } elseif (is_array($settings)) {
            $options = $settings;
        }

        $quantity_units = ['g', 'kg', 'jin', 'liang', 'ml', 'l'];
        if (is_array($settings) && isset($settings['quantity_units']) && is_array($settings['quantity_units'])) {
            $quantity_units = $settings['quantity_units'];
        }

        $qty_value = '1';
        if (is_array($settings) && array_key_exists('unit_qty_value', $settings)) {
            $qty_value = (string) $settings['unit_qty_value'];
        }

        $qty_id = $field_id . '_qty';
        $unit_id = $field_id . '_unit';

        $html = '<div class="todo-unit-field" id="' . self::esc_attr($field_id) . '" data-quantity-units="' . self::esc_attr(json_encode(array_values($quantity_units), JSON_UNESCAPED_UNICODE)) . '">';
        $html .= '<div class="flex items-center" style="gap:8px;">';

        $html .= '<input type="text" class="form-control" name="unit_qty" id="' . self::esc_attr($qty_id) . '" value="' . self::esc_attr($qty_value) . '" placeholder="如1或均码" style="max-width:140px; display:none;" />';

        $html .= '<select class="form-control" name="' . self::esc_attr($field_key) . '" id="' . self::esc_attr($unit_id) . '"';
        if ($required) {
            $html .= ' required';
        }
        $html .= '>';
        $html .= '<option value="">请选择...</option>';
        foreach ($options as $option_value => $option_label) {
            $selected = ((string) $field_value === (string) $option_value) ? ' selected' : '';
            $html .= '<option value="' . self::esc_attr($option_value) . '"' . $selected . '>' . self::esc_html($option_label) . '</option>';
        }
        $html .= '</select>';

        $html .= '</div>';

        $html .= '<script>(function(){'
            . 'var root=document.getElementById(' . json_encode($field_id) . ');'
            . 'if(!root){return;}'
            . 'var qty=document.getElementById(' . json_encode($qty_id) . ');'
            . 'var sel=document.getElementById(' . json_encode($unit_id) . ');'
            . 'if(!qty||!sel){return;}'
            . 'var units=[];'
            . 'try{units=JSON.parse(root.getAttribute("data-quantity-units")||"[]")||[];}catch(e){units=[];}'
            . 'function update(){'
            . 'var v=sel.value||"";'
            . 'var need=units.indexOf(v)!==-1;'
            . 'qty.style.display=need?"":"none";'
            . '}'
            . 'sel.addEventListener("change",update);'
            . 'update();'
            . '})();</script>';

        $html .= '</div>';

        return $html;
    }

    /**
     * 通用字段分组方法
     * 将字段数组按照 field_section 属性进行分组
     * 
     * @param array $fields 字段配置数组
     * @param array $values 字段值数组（可选）
     * @param array $options 渲染选项
     * @return array 分组后的sections结构数据
     */
    public static function group_fields_by_section($fields, $values = [], $options = [])
    {
        $sections_data = [];
        $default_section = $options['default_section'] ?? '基础设置';
        $include_wrapper = $options['include_wrapper'] ?? true;

        foreach ($fields as $field_name => $field_config) {
            // 获取字段分组，默认为基础设置
            $section = $field_config['field_section'] ?? $default_section;

            // 获取字段值
            $field_value = '';
            if (isset($values[$field_name])) {
                $field_value = $values[$field_name];
            } elseif (isset($field_config['default'])) {
                $field_value = $field_config['default'];
            }

            // 构建字段数据
            $field_data = [
                'key' => $field_name,  // 字段键名，用于表单提交
                'name' => $field_config['label'] ?? $field_name,  // 字段显示名称
                'type' => $field_config['type'] ?? 'text',
                'label' => $field_config['label'] ?? $field_name,
                'value' => $field_value,
                'required' => $field_config['required'] ?? false,
                'description' => $field_config['description'] ?? '',
                'options' => $field_config['options'] ?? [],
                'layout' => $field_config['layout'] ?? (isset($field_config['options']['layout']) ? $field_config['options']['layout'] : [])
            ];

            // 处理特定字段类型的属性
            if ($field_config['type'] === 'textarea') {
                $field_data['rows'] = $field_config['rows'] ?? 4;
            }

            if ($field_config['type'] === 'media') {
                $field_data['media_type'] = $field_config['media_type'] ?? 'image';
                $field_data['multiple'] = $field_config['multiple'] ?? false;
            }

            // 处理可选属性
            if (isset($field_config['placeholder'])) {
                $field_data['placeholder'] = $field_config['placeholder'];
            }

            if (isset($field_config['max_length'])) {
                $field_data['max_length'] = $field_config['max_length'];
            }

            // 如果分组不存在，创建新分组
            if (!isset($sections_data[$section])) {
                $sections_data[$section] = [
                    'section_title' => $section,
                    'section_class' => 'fixed-fields-section section-' . sanitize_title($section),
                    'fields' => []
                ];
            }

            // 将字段添加到对应分组
            $sections_data[$section]['fields'][] = $field_data;
        }

        return [
            'include_wrapper' => $include_wrapper,
            'sections' => array_values($sections_data)
        ];
    }

    /**
     * 简化标题为CSS类名
     * 
     * @param string $title 标题
     * @return string CSS类名
     */
    private static function sanitize_title($title)
    {
        // 移除特殊字符，转换为小写，用连字符连接
        $title = strtolower($title);
        $title = preg_replace('/[^a-z0-9\x{4e00}-\x{9fa5}]/u', '-', $title);
        $title = preg_replace('/-+/', '-', $title);
        return trim($title, '-');
    }

    /**
     * 渲染产品视频字段（使用特色图像组件支持视频）
     * 
     * @param string $field_key 字段键名
     * @param mixed $field_value 字段值
     * @param bool $required 是否必填
     * @return string HTML字符串
     */
    private static function render_product_video_field($field_key, $field_value = '', $required = false)
    {
        // 引入特色图像组件
        if (!function_exists('todo_render_featured_images_component')) {
            require_once ABSPATH . 'admin/components/featured-images-component.php';
        }

        // 规范化已有值为组件所需的 current_images 结构
        $current_images = [];
        $append_item = function ($id_or_item) use (&$current_images) {
            $item = [];
            if (is_array($id_or_item)) {
                $item = $id_or_item;
            } else {
                $item_id = is_numeric($id_or_item) ? intval($id_or_item) : 0;
                if ($item_id > 0) {
                    $url = function_exists('todo_get_attachment_url') ? todo_get_attachment_url($item_id) : '';
                    // 对于视频，预览图以占位符为主，避免<img>加载视频URL导致破图
                    $title = function_exists('todo_get_the_title') ? todo_get_the_title($item_id) : '';
                    $item = [
                        'id' => $item_id,
                        'url' => '',
                        'title' => $title,
                        'type' => 'video'
                    ];
                    // 如果后续扩展了视频封面图，可在此填充 $item['url'] = $cover_url;
                }
            }
            if (!empty($item)) {
                // 最少确保类型为视频
                if (!isset($item['type'])) {
                    $item['type'] = 'video';
                }
                // 如果提供了URL且是视频文件URL，依然保守置空，避免<img>破图
                if (!empty($item['url'])) {
                    $ext = strtolower(pathinfo(parse_url($item['url'], PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                    $video_exts = ['mp4', 'mov', 'm4v', 'webm', 'ogg', 'ogv'];
                    if (in_array($ext, $video_exts, true)) {
                        $item['url'] = '';
                    }
                }
                $current_images[] = $item;
            }
        };

        if (is_array($field_value)) {
            // 可能是单项或多项结构
            $is_assoc = array_keys($field_value) !== range(0, count($field_value) - 1);
            if ($is_assoc) {
                $append_item($field_value);
            } else {
                foreach ($field_value as $v) {
                    $append_item($v);
                }
            }
        } elseif (is_string($field_value)) {
            $trim = trim($field_value);
            if ($trim !== '') {
                if ($trim[0] === '[' || $trim[0] === '{') {
                    $decoded = json_decode($trim, true);
                    if (is_array($decoded)) {
                        $is_assoc = array_keys($decoded) !== range(0, count($decoded) - 1);
                        if ($is_assoc) {
                            $append_item($decoded);
                        } else {
                            foreach ($decoded as $v) {
                                $append_item($v);
                            }
                        }
                    } elseif (is_numeric($trim)) {
                        $append_item(intval($trim));
                    }
                } elseif (is_numeric($trim)) {
                    $append_item(intval($trim));
                }
            }
        } elseif (is_numeric($field_value)) {
            $append_item(intval($field_value));
        }

        // 生成稳定的 field_id，避免与其他组件冲突
        $field_id = self::sanitize_title($field_key) . '_group';

        $config = [
            'field_id' => $field_id,
            'field_name' => $field_key,
            'current_images' => $current_images,
            'file_type' => 'video',
            'multiple' => false,
            'max_images' => 1,
            'sortable' => false,
            'variant' => 'featured',
            'enable_debug' => defined('TODO_DEBUG') && TODO_DEBUG,
        ];

        return todo_render_featured_images_component($config);
    }

    /**
     * 渲染产品类型字段（radio选择）
     * 
     * @param string $field_key 字段键名
     * @param string $field_value 字段值
     * @param bool $required 是否必填
     * @return string HTML字符串
     */
    private static function render_product_type_field($field_key, $field_value, $required = false)
    {
        $options = [
            'physical' => '实物产品',
            'virtual' => '虚拟产品'
        ];

        // 如果字段值为空，默认选中"实物产品"
        if (empty($field_value)) {
            $field_value = 'physical';
        }

        $uid = uniqid('pt_');
        $scopeId = 'todo-product-type-' . $uid;
        $groupName = $field_key . '_' . $uid;
        $html = '<div id="' . self::esc_attr($scopeId) . '" class="todo-product-type-wrap">';
        $html .= '<input type="hidden" name="' . self::esc_attr($field_key) . '" value="' . self::esc_attr($field_value) . '">';
        $html .= '<div class="flex gap-x-4 gap-y-2 flex-wrap">';
        foreach ($options as $value => $label) {
            $checked = ($field_value === $value) ? ' checked="checked"' : '';
            $required_attr = $required ? ' required="required"' : '';
            $radio_id = $groupName . '_' . $value;

            $html .= '<div class="flex gap-1 items-center">';
            $html .= '<label class="m-0">';
            $html .= '<input type="radio" id="' . self::esc_attr($radio_id) . '" name="' . self::esc_attr($groupName) . '" value="' . self::esc_attr($value) . '"' . $checked . $required_attr . '> ';
            $html .= self::esc_html($label);
            $html .= '</label>';
            $html .= '</div>';
        }
        $html .= '</div></div>';
        $html .= '<script type="text/javascript">' . "\n"
            . '(function(){' . "\n"
            . '  var root = document.getElementById("' . self::esc_attr($scopeId) . '");' . "\n"
            . '  if(!root) return;' . "\n"
            . '  var hidden = root.querySelector("input[type=hidden][name=\'' . self::esc_attr($field_key) . '\']");' . "\n"
            . '  var scope = root.closest("form") || document;' . "\n"
            . '  function update(){' . "\n"
            . '    var checked = root.querySelector("input[type=radio][name=\'' . self::esc_attr($groupName) . '\']:checked");' . "\n"
            . '    var val = checked ? checked.value : "";' . "\n"
            . '    // 如果没有任何选项被选中，默认选中"实物产品"' . "\n"
            . '    if(!val){' . "\n"
            . '      var physicalRadio = root.querySelector("input[type=radio][name=\'' . self::esc_attr($groupName) . '\'][value=\'physical\']");' . "\n"
            . '      if(physicalRadio){' . "\n"
            . '        physicalRadio.checked = true;' . "\n"
            . '        val = "physical";' . "\n"
            . '      }' . "\n"
            . '    }' . "\n"
            . '    if(hidden){ hidden.value = val; ' . "" . '}' . "\n"
            . '    if(hidden){ try{ hidden.dispatchEvent(new Event("change", {bubbles:true})); hidden.dispatchEvent(new CustomEvent("todo:product_type_change", {detail:{value: val}, bubbles:true})); }catch(e){ /* no-op */ } }' . "\n"
            . '    // 基于产品类型的字段显隐：data-product-type-visibility="physical|virtual"' . "\n"
            . '    var blocks = scope.querySelectorAll("[data-product-type-visibility]");' . "\n"
            . '    blocks.forEach(function(el){' . "\n"
            . '      var need = el.getAttribute("data-product-type-visibility");' . "\n"
            . '      var show = (need === val);' . "\n"
            . '      el.style.display = show ? "" : "none";' . "\n"
            . '      if(!show){ el.querySelectorAll("[required]").forEach(function(inp){ inp.setAttribute("data-was-required","1"); inp.removeAttribute("required"); }); }' . "\n"
            . '      else { el.querySelectorAll("[data-was-required]").forEach(function(inp){ inp.setAttribute("required","required"); inp.removeAttribute("data-was-required"); }); }' . "\n"
            . '    });' . "\n"
            . '  }' . "\n"
            . '  root.querySelectorAll("input[type=radio][name=\'' . self::esc_attr($groupName) . '\']").forEach(function(r){ r.addEventListener("change", update); });' . "\n"
            . '  update();' . "\n"
            . '})();' . "\n"
            . '</script>';

        return $html;
    }

    /**
     * 渲染产品服务字段（checkbox多选）
     * 
     * @param string $field_key 字段键名
     * @param string $field_value 字段值（JSON字符串或数组）
     * @param bool $required 是否必填
     * @return string HTML字符串
     */
    private static function render_product_services_field($field_key, $field_value, $required = false)
    {
        // 动态读取服务设置
        $settings = todo_get_option('todo_service_settings', null);
        $options = [];
        if (is_array($settings) && array_key_exists('product_service_items', $settings)) {
            $list = is_array($settings['product_service_items']) ? $settings['product_service_items'] : [];
            $seen = [];
            foreach ($list as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $code = isset($it['code']) ? todo_sanitize_key($it['code']) : '';
                $label = isset($it['label']) ? sanitize_text_field($it['label']) : '';
                if (function_exists('todo_parse_inline_translations')) {
                    $label = todo_parse_inline_translations($label);
                }
                if (!$code || !$label || isset($seen[$code])) {
                    continue;
                }
                $seen[$code] = true;
                $options[$code] = $label;
            }
        } else {
            $options = [];
        }

        // 处理字段值（可能是JSON字符串或数组）
        $selected_values = [];
        if (is_string($field_value)) {
            $decoded = json_decode($field_value, true);
            $selected_values = is_array($decoded) ? $decoded : [];
        } elseif (is_array($field_value)) {
            $selected_values = $field_value;
        }

        $html = '<div class="flex gap-x-4 flex-wrap">';
        $html .= '<input type="hidden" name="' . self::esc_attr($field_key) . '" value="">';

        foreach ($options as $value => $label) {
            $checked = in_array($value, $selected_values) ? ' checked' : '';
            $html .= '<label>';
            $html .= '<input type="checkbox" name="' . self::esc_attr($field_key) . '[]" value="' . self::esc_attr($value) . '"' . $checked . '>';
            $html .= ' ' . self::esc_html($label);
            $html .= '</label>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * 渲染规格类型字段（radio选择）
     * 
     * @param string $field_key 字段键名
     * @param string $field_value 字段值
     * @param bool $required 是否必填
     * @return string HTML字符串
     */
    private static function render_spec_type_field($field_key, $field_value, $required = false)
    {
        // 默认回退：若未设置或非法，强制为单规格
        if ($field_value !== 'single' && $field_value !== 'multiple') {
            $field_value = 'single';
        }
        $options = [
            'single' => '单规格',
            'multiple' => '多规格'
        ];

        $uid = uniqid('st_');
        $scopeId = 'todo-spec-type-' . $uid;
        $groupName = $field_key . '_' . $uid;
        $html = '<div id="' . self::esc_attr($scopeId) . '" class="todo-spec-type-wrap">';
        $html .= '<input type="hidden" name="' . self::esc_attr($field_key) . '" value="' . self::esc_attr($field_value) . '">';
        $html .= '<div class="flex gap-4">';
        foreach ($options as $value => $label) {
            $checked = ($field_value === $value) ? ' checked' : '';
            $html .= '<label>';
            $html .= '<input type="radio" name="' . self::esc_attr($groupName) . '" value="' . self::esc_attr($value) . '"' . $checked;
            if ($required) {
                $html .= ' required';
            }
            $html .= '> ' . self::esc_html($label);
            $html .= '</label>';
        }
        $html .= '</div></div>';

        // 多规格时显示“产品规格”构建器
        $containerStyle = ($field_value === 'multiple') ? '' : ' style="display:none;"';
        $html .= '<div id="' . self::esc_attr($scopeId . '-builder') . '" data-spec-visibility="multiple"' . $containerStyle . '>';
        $html .= self::render_product_specs_builder();
        $html .= '</div>';

        // 切换时联动显示/隐藏（含单/多规格字段显隐与表单校验状态）
        $html .= '<script type="text/javascript">' . "\n"
            . '(function(){' . "\n"
            . '  var root = document.getElementById("' . self::esc_attr($scopeId) . '");' . "\n"
            . '  if(!root) return;' . "\n"
            . '  var hidden = root.querySelector("input[type=hidden][name=\'' . self::esc_attr($field_key) . '\']");' . "\n"
            . '  var builder = document.getElementById("' . self::esc_attr($scopeId . '-builder') . '");' . "\n"
            . '  var scope = root.closest("form") || document;' . "\n"
            . '  function update(){' . "\n"
            . '    var checked = root.querySelector("input[type=radio][name=\'' . self::esc_attr($groupName) . '\']:checked");' . "\n"
            . '    var val = checked ? checked.value : "";' . "\n"
            . '    if(hidden){ hidden.value = val; }' . "\n"
            . '    if(builder){ builder.style.display = (val === "multiple") ? "" : "none"; }' . "\n"
            . '    // 同步基于规格类型的字段显隐：data-spec-visibility="single|multiple"' . "\n"
            . '    // 先在scope中查找，如果找不到则在document中查找（兼容多语言面板等场景）' . "\n"
            . '    var blocks = scope.querySelectorAll("[data-spec-visibility]");' . "\n"
            . '    if(blocks.length === 0 && scope !== document){' . "\n"
            . '      blocks = document.querySelectorAll("[data-spec-visibility]");' . "\n"
            . '    }' . "\n"
            . '    // 确保遍历所有匹配的元素（使用Array.from确保forEach正常工作）' . "\n"
            . '    Array.from(blocks).forEach(function(el){' . "\n"
            . '      if(!el || !el.getAttribute) return;' . "\n"
            . '      var need = el.getAttribute("data-spec-visibility");' . "\n"
            . '      if(!need) return;' . "\n"
            . '      var show = (need === val);' . "\n"
            . '      // 特例：当为单规格(single)时，discount_config 仅在折扣类型为 level 时可见' . "\n"
            . '      // 注意：discount_config 的显示/隐藏主要由 discount_type 字段的 update() 函数控制' . "\n"
            . '      // 这里只负责根据规格类型显示/隐藏，不覆盖 discount_type 的控制逻辑' . "\n"
            . '      if (show) {' . "\n"
            . '        var fk = el.getAttribute("data-field-key") || "";' . "\n"
            . '        if (fk === "discount_config") {' . "\n"
            . '          // 查找折扣类型隐藏字段和radio选中值' . "\n"
            . '          var dtHidden = scope.querySelector("input[type=hidden][name=\'discount_type\']");' . "\n"
            . '          if(!dtHidden && scope !== document){' . "\n"
            . '            dtHidden = document.querySelector("input[type=hidden][name=\'discount_type\']");' . "\n"
            . '          }' . "\n"
            . '          var dtVal = dtHidden ? dtHidden.value : "";' . "\n"
            . '          // 如果隐藏字段没有值，尝试从radio获取' . "\n"
            . '          if(!dtVal){' . "\n"
            . '            var dtRoot = scope.querySelector(".todo-discount-type-wrap");' . "\n"
            . '            if(!dtRoot && scope !== document){' . "\n"
            . '              dtRoot = document.querySelector(".todo-discount-type-wrap");' . "\n"
            . '            }' . "\n"
            . '            if(dtRoot){' . "\n"
            . '              var dtChecked = dtRoot.querySelector("input[type=radio]:checked");' . "\n"
            . '              dtVal = dtChecked ? dtChecked.value : "";' . "\n"
            . '            }' . "\n"
            . '          }' . "\n"
            . '          if (dtVal !== "level") { show = false; }' . "\n"
            . '        }' . "\n"
            . '      }' . "\n"
            . '      el.style.display = show ? "" : "none";' . "\n"
            . '      if(!show){ el.querySelectorAll("[required]").forEach(function(inp){ inp.setAttribute("data-was-required","1"); inp.removeAttribute("required"); }); }' . "\n"
            . '      else { el.querySelectorAll("[data-was-required]").forEach(function(inp){ inp.setAttribute("required","required"); inp.removeAttribute("data-was-required"); }); }' . "\n"
            . '    });' . "\n"
            . '  }' . "\n"
            . '  root.querySelectorAll("input[type=radio][name=\'' . self::esc_attr($groupName) . '\']").forEach(function(r){ r.addEventListener("change", update); });' . "\n"
            . '  update();' . "\n"
            . '})();' . "\n"
            . '</script>';

        return $html;
    }

    /**
     * 渲染产品规格构建器（仅在多规格下显示）
     * 生成规格组（如颜色、尺码）和规格值，并根据笛卡尔积生成规格明细表
     * 同步维护隐藏字段 spec_config/spec_details（若存在于DOM中）
     */
    private static function render_product_specs_builder()
    {
        $html = '';
        $groupUid = uniqid('sg_');
        $html .= '<div class="todo-specs-builder" id="todo-specs-builder">';
        $html .= '  <div class="spec-groups">';
        // 规格组（如颜色、尺码）
        $html .= '    <div class="spec-group" data-group-index="0" data-group-uid="' . self::esc_attr($groupUid) . '">';
        $html .= '      <div class="spec-group-item">';
        $html .= '         <div class="spec-group-header flex gap-2 items-center flex-wrap">';
        $html .= '              <input type="text" class="spec-title" id="spec-title-' . self::esc_attr($groupUid) . '" value="" placeholder="如：规格名称">';
        $html .= '              <span class="rm del-spec-group" title="删除规格组">x</span>';
        $html .= '              <label class="flex items-center gap-1 m-0"><input type="checkbox" name="add-spec-image" class="btn" title="为该规格的规格值添加图片">图片</label>';
        $html .= '              <label class="flex items-center gap-1 m-0"><input type="checkbox" name="add-spec-color" class="btn" title="为该规格的规格值添加颜色">颜色</label>';
        $html .= '         </div>';
        $html .= '         <div class="spec-values" style="margin: 10px 0 0 40px;">';
        $html .= '              <div class="value-list flex gap-x-8 gap-y-2 items-center flex-wrap"></div>';
        $html .= '              <div class="add-value flex gap-2 items-center flex-wrap">';
        $html .= '                  <input type="text" class="new-value-input" id="new-value-input-' . self::esc_attr($groupUid) . '" placeholder="如：规格值">';
        $html .= '                  <button type="button" class="btn add-value-btn">✚ 增加规格值</button>';
        $html .= '              </div>';
        $html .= '         </div>';
        $html .= '      </div>';
        $html .= '    </div>';
        $html .= '    <button type="button" class="btn button-primary add-spec-group-btn" style="margin-top: 10px;">新增规格</button>';
        $html .= '  </div>';
        // 价格/库存
        $curr_label = function_exists('todo_get_currency_display_label')
            ? todo_get_currency_display_label()
            : (function_exists('todo_get_default_currency_config')
                ? (function() { $c = todo_get_default_currency_config(); return ($c['code'] ?? '') . ($c['symbol'] ? ' (' . $c['symbol'] . ')' : ''); })()
                : '');

        $html .= '  <div class="spec-details" style="margin-top:16px;">';
        $html .= '    <h4 class="items-center gap-2 flex-wrap" style="display:flex;"><span style="font-size: 12px; padding: 2px 5px; border-radius: 3px; background-color: #f8f9fa;">' . self::esc_html($curr_label) . '</span>价格/库存<span class="description m-0" style="font-weight: normal;color: #d43838;">(必须完成价格/库存参数才能更新多规格数据)</span></h4>';
        $html .= '    <div class="mobile-table-bordered"><table class="table table-bordered multiple-table" id="spec_input_tab" border="1" cellpadding="10" cellspacing="10">';
        $html .= '      <thead>';
        $html .= '        <tr id="todo-spec-head-row" style="font-size: 12px;"></tr>';
        $html .= '      </thead>';
        $html .= '      <tbody id="todo-spec-rows"></tbody>';
        $html .= '    </table></div>';
        $html .= '  </div>';
        $html .= '</div>';

        // 引入规格构建器脚本
        $html .= '<script src="' . todo_admin_url('assets/js/spec-builder.js') . '?v=' . TODO_VERSION . '" type="text/javascript"></script>';

        return $html;
    }

    /**
     * 渲染折扣类型字段（radio选择）
     * 
     * @param string $field_key 字段键名
     * @param string $field_value 字段值
     * @param bool $required 是否必填
     * @return string HTML字符串
     */
    private static function render_discount_type_field($field_key, $field_value, $required = false)
    {
        $options = [
            'none' => '无折扣',
            'auto' => '自动折扣',
            'level' => '指定等级'
        ];

        $uid = uniqid('dt_');
        $scopeId = 'todo-discount-type-' . $uid;
        $groupName = $field_key . '_' . $uid;
        $html = '<div id="' . self::esc_attr($scopeId) . '" class="todo-discount-type-wrap">';
        $html .= '<input type="hidden" name="' . self::esc_attr($field_key) . '" value="' . self::esc_attr($field_value) . '">';
        $html .= '<div class="flex gap-x-4 flex-wrap">';
        foreach ($options as $value => $label) {
            $checked = ($field_value === $value) ? ' checked' : '';
            $html .= '<label>';
            $html .= '<input type="radio" name="' . self::esc_attr($groupName) . '" value="' . self::esc_attr($value) . '"' . $checked;
            if ($required) {
                $html .= ' required';
            }
            $html .= '> ' . self::esc_html($label);
            $html .= $value === 'auto' ? ' <span style="font-size:13px; font-style:italic; margin-left:-5px;">（根据用户等级设置自动计算价格）</span>' : '';
            $html .= $value === 'level' ? ' <span style="font-size:13px; font-style:italic; margin-left:-5px;">（指定等级进行计算）</span>' : '';
            $html .= '</label>';
        }
        $html .= '</div></div>';
        $html .= '<script type="text/javascript">' . "\n"
            . '(function(){' . "\n"
            . '  var root = document.getElementById("' . self::esc_attr($scopeId) . '");' . "\n"
            . '  if(!root) return;' . "\n"
            . '  var hidden = root.querySelector("input[type=hidden][name=\'' . self::esc_attr($field_key) . '\']");' . "\n"
            . '  var scope = root.closest("form") || document;' . "\n"
            . '  function update(){' . "\n"
            . '    var checked = root.querySelector("input[type=radio][name=\'' . self::esc_attr($groupName) . '\']:checked");' . "\n"
            . '    var val = checked ? checked.value : "";' . "\n"
            . '    if(hidden){ hidden.value = val; }' . "\n"
            . '    // 查找折扣配置字段（兼容多语言面板等场景）' . "\n"
            . '    var cfgFields = scope.querySelectorAll("[data-field-key=\'discount_config\']");' . "\n"
            . '    if(cfgFields.length === 0 && scope !== document){' . "\n"
            . '      cfgFields = document.querySelectorAll("[data-field-key=\'discount_config\']");' . "\n"
            . '    }' . "\n"
            . '    // 查找规格类型隐藏字段' . "\n"
            . '    var specHidden = scope.querySelector("input[type=hidden][name=\'spec_type\']");' . "\n"
            . '    if(!specHidden && scope !== document){' . "\n"
            . '      specHidden = document.querySelector("input[type=hidden][name=\'spec_type\']");' . "\n"
            . '    }' . "\n"
            . '    var specVal = specHidden ? specHidden.value : "";' . "\n"
            . '    // 如果隐藏字段没有值，尝试从radio获取（兼容初始化时的情况）' . "\n"
            . '    if(!specVal){' . "\n"
            . '      var specRoot = scope.querySelector(".todo-spec-type-wrap");' . "\n"
            . '      if(!specRoot && scope !== document){' . "\n"
            . '        specRoot = document.querySelector(".todo-spec-type-wrap");' . "\n"
            . '      }' . "\n"
            . '      if(specRoot){' . "\n"
            . '        var specChecked = specRoot.querySelector("input[type=radio]:checked");' . "\n"
            . '        specVal = specChecked ? specChecked.value : "single";' . "\n"
            . '      } else {' . "\n"
            . '        specVal = "single";' . "\n"
            . '      }' . "\n"
            . '    }' . "\n"
            . '    // 只有当规格类型为"single"且折扣类型为"level"时才显示折扣配置' . "\n"
            . '    var showCfg = (specVal === "single" && val === "level");' . "\n"
            . '    Array.from(cfgFields).forEach(function(el){' . "\n"
            . '      el.style.display = showCfg ? "" : "none";' . "\n"
            . '      if(!showCfg){ el.querySelectorAll("[required]").forEach(function(inp){ inp.setAttribute("data-was-required","1"); inp.removeAttribute("required"); }); }' . "\n"
            . '      else { el.querySelectorAll("[data-was-required]").forEach(function(inp){ inp.setAttribute("required","required"); inp.removeAttribute("data-was-required"); }); }' . "\n"
            . '    });' . "\n"
            . '  }' . "\n"
            . '  root.querySelectorAll("input[type=radio][name=\'' . self::esc_attr($groupName) . '\']").forEach(function(r){ r.addEventListener("change", update); });' . "\n"
            . '  update();' . "\n"
            . '})();' . "\n"
            . '</script>';

        return $html;
    }

    /**
     * 渲染折扣配置字段（根据折扣类型显示不同配置，指定等级时为每个会员等级设置价格）
     * @param string $field_key 字段键名（discount_config）
     * @param string|array $field_value 字段值（JSON或数组）
     * @param bool $required 是否必填
     * @return string HTML字符串
     */
    private static function render_discount_config_field($field_key, $field_value, $required = false)
    {
        // 确保用户等级管理器可用
        if (!function_exists('todo_get_user_level_manager')) {
            $lvlMgrFile = __DIR__ . '/class-user-level-manager.php';
            if (file_exists($lvlMgrFile)) {
                require_once $lvlMgrFile;
            }
        }
        $levels = [];
        if (function_exists('todo_get_user_level_manager')) {
            $levels = todo_get_user_level_manager()->get_user_levels(['is_active' => 1, 'order' => 'ASC']);
        }
        // 解析已保存配置
        if (is_string($field_value) && $field_value !== '') {
            $cfg = json_decode($field_value, true);
            if (!is_array($cfg)) {
                $cfg = [];
            }
        } elseif (is_array($field_value)) {
            $cfg = $field_value;
        } else {
            $cfg = [];
        }
        $level_prices = isset($cfg['level_prices']) && is_array($cfg['level_prices']) ? $cfg['level_prices'] : [];
        $uid = uniqid('dc_');
        $scopeId = 'todo-discount-config-' . $uid;

        $hiddenJson = $cfg ? json_encode($cfg, JSON_UNESCAPED_UNICODE) : '{}';

        $curr_label = function_exists('todo_get_currency_display_label')
            ? todo_get_currency_display_label()
            : (function_exists('todo_get_default_currency_config')
                ? (function() { $c = todo_get_default_currency_config(); return ($c['code'] ?? '') . ($c['symbol'] ? ' (' . $c['symbol'] . ')' : ''); })()
                : '');

        $html = '<div id="' . self::esc_attr($scopeId) . '" class="todo-discount-config-wrap">';
        // 自动折扣提示区
        $html .= '<div class="discount-auto-tip" style="color:#666; font-size:13px;">';
        $html .= '自动折扣将根据用户等级的“折扣率”自动计算最终售价，无需手动配置。可前往“用户等级”设置折扣率。';
        $html .= '</div>';
        // 指定等级编辑区
        $html .= '<div class="mobile-table-bordered">';
        if (!empty($levels)) {
            $html .= '<table style="max-width:680px;">';
            $html .= '<thead style="background-color: #f2f2f2;"><tr>'
                . '<th style="width:30%;min-width: 105px;padding: 2px 10px;font-size: 14px;text-align: left;">会员等级</th>'
                . '<th style="width:40%;min-width: 105px;padding: 2px 10px;font-size: 14px;text-align: left;">标识</th>'
                . '<th style="width:15%;padding: 2px 10px;font-size: 14px;text-align: left;word-break: keep-all;">现折扣</th>'
                . '<th style="width:15%;padding: 2px 10px;font-size: 14px;text-align: left;">一口价 <em style="font-size: 10px;">' . self::esc_html($curr_label) . '</em></th>'
                . '</tr></thead>';
            $html .= '<tbody>';
            foreach ($levels as $lv) {
                $lid = isset($lv->id) ? intval($lv->id) : 0;
                $lname = isset($lv->level_name) ? $lv->level_name : ('等级#' . $lid);
                $lslug = isset($lv->level_slug) ? $lv->level_slug : '';
                $ldiscount = isset($lv->discount_rate) ? floatval($lv->discount_rate) : 0.0;
                $val = isset($level_prices[$lid]) ? $level_prices[$lid] : '';
                $html .= '<tr>'
                    . '<td style="padding: 2px 10px; line-height: 18px;">' . self::esc_html($lname) . '</td>'
                    . '<td style="padding: 2px 10px;"><code>' . self::esc_html($lslug) . '</code></td>'
                    . '<td style="padding: 2px 10px;">' . self::esc_html(rtrim(rtrim(number_format($ldiscount, 2, '.', ''), '0'), '.')) . '%</td>'
                    . '<td style="padding: 2px 10px;">'
                    . '<input type="text" class="level-price" id="level-price-' . self::esc_attr((string) $lid) . '" data-level-id="' . self::esc_attr((string) $lid) . '" data-discount-rate="' . self::esc_attr((string) $ldiscount) . '" value="' . self::esc_attr((string) $val) . '"'
                    . ' placeholder="如 9.9" style="width:160px; min-width: auto;" oninput="this.value=this.value.replace(/[^0-9.]/g,\'\');">'
                    . '</td>'
                    . '</tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<div class="notice notice-warning" style="padding:8px 10px;">未找到可用的用户等级，请先在“用户等级”中创建。</div>';
        }
        $html .= '</div>';
        // 隐藏字段（JSON）
        $html .= '<textarea name="' . self::esc_attr($field_key) . '" style="display:none;">' . self::esc_html($hiddenJson) . '</textarea>';

        // JS 同步行为
        $html .= '<script type="text/javascript">'
            . '(function(){'
            . 'var root=document.getElementById("' . self::esc_attr($scopeId) . '"); if(!root) return;'
            . 'var hidden=root.querySelector("textarea[name=\'' . self::esc_attr($field_key) . '\']");'
            . 'function readType(){ var form=root.closest("form")||document; var t=form.querySelector("[name=\'discount_type\']"); return t? t.value: ""; }'
            . 'function readConfigMode(){ try{ var c=JSON.parse(hidden.value||"{}"); return (c && c.mode) ? c.mode : ""; }catch(e){ return ""; } }'
            . 'function toggleSections(){ var mode=readType(); var cmode=readConfigMode(); if(!mode && cmode){ mode=cmode; } }'
            . 'function buildConfig(){ var mode=readType(); if(!mode){ mode=readConfigMode(); } var obj={}; if(mode==="level"){ var prices={}; root.querySelectorAll("input.level-price").forEach(function(inp){ var id=inp.getAttribute("data-level-id"); var v=inp.value.trim(); if(v!==""){ var f=parseFloat(v); if(!isNaN(f)) prices[id]=f; }}); obj.mode="level"; obj.level_prices=prices; } else if(mode==="auto"){ obj.mode="auto"; } hidden.value=JSON.stringify(obj); }'
            . 'function prefillIfEmpty(){ var mode=readType(); if(!mode){ mode=readConfigMode(); } if(mode!=="level") return; if(root.dataset.prefilled==="1") return; var hasVal=false; root.querySelectorAll("input.level-price").forEach(function(inp){ if((inp.value||"").trim()!==""){ hasVal=true; } }); if(!hasVal){ var base = parseFloat((document.getElementById(\'price\')||{value:\'0\'}).value||\'0\'); root.querySelectorAll("input.level-price").forEach(function(inp){ var disc = parseFloat(inp.getAttribute(\'data-discount-rate\')||\'0\'); if(!isNaN(disc) && !isNaN(base) && disc>0 && disc<=100){ var autoPrice = (base * (disc/100)); if(!isNaN(autoPrice)){ inp.value = autoPrice.toFixed(2); inp.placeholder = \'建议: \' + autoPrice.toFixed(2); } } }); root.dataset.prefilled="1"; buildConfig(); } }'
            . 'function initFromConfig(){ try{ var c=JSON.parse(hidden.value||"{}"); if(c&&c.level_prices){ Object.keys(c.level_prices).forEach(function(id){ var inp=root.querySelector("input.level-price[data-level-id=\'+id+\']"); if(inp){ inp.value=c.level_prices[id]; } }); } }catch(e){} toggleSections(); }'
            . 'root.querySelectorAll("input.level-price").forEach(function(inp){ inp.addEventListener("input", function(){ this.value=this.value.replace(/[^0-9.]/g,""); buildConfig(); }); inp.addEventListener("change", function(){ buildConfig(); var disc = parseFloat(inp.getAttribute(\'data-discount-rate\')||\'0\'); var base = parseFloat((document.getElementById(\'price\')||{value:\'0\'}).value||\'0\'); if(!isNaN(disc) && !isNaN(base) && disc>0 && disc<=100){ var autoPrice = (base * (disc/100)); if(!isNaN(autoPrice)){ inp.placeholder = \'建议: \' + autoPrice.toFixed(2); } } else { inp.placeholder=\'如 9.9\'; } }); });'
            . 'var form=root.closest("form")||document; form.addEventListener("change", function(e){ buildConfig(); toggleSections(); prefillIfEmpty(); if(e && e.target && e.target.id===\'price\'){ root.querySelectorAll(\'input.level-price\').forEach(function(inp){ var disc = parseFloat(inp.getAttribute(\'data-discount-rate\')||\'0\'); var base = parseFloat(e.target.value||\'0\'); if(!isNaN(disc) && !isNaN(base) && disc>0 && disc<=100){ var autoPrice = (base * (disc/100)); if(!isNaN(autoPrice)){ inp.placeholder = \'建议: \' + autoPrice.toFixed(2); } } else { inp.placeholder=\'如 9.9\'; } }); } }, true);'
            . 'initFromConfig(); buildConfig(); prefillIfEmpty();'
            . '})();'
            . '</script>';

        $html .= '</div>';
        return $html;
    }

    /**
     * 渲染开关字段
     */
    private static function render_switch_field($field_key, $field_value, $label = '', $description = '')
    {
        $checked = ($field_value === '1' || $field_value === 1 || $field_value === true || $field_value === 'true' || $field_value === 'on') ? ' checked' : '';
        // 确保ID唯一，避免多语言面板冲突
        $uid = uniqid();
        $toggleId = 'toggle-' . self::sanitize_title($field_key) . '-' . $uid;
        
        // 容器
        $html = '<div class="form-group fixed-field" data-field-type="switch" data-field-key="' . self::esc_attr($field_key) . '">';
        
        // 标题
        if ($label) {
            $html .= '<h4>' . self::esc_html($label) . '</h4>';
        }
        
        $html .= '<div class="switch-field">';
        // 隐藏字段用于存储实际值 (0 或 1)
        $html .= '<input type="hidden" name="' . self::esc_attr($field_key) . '" id="field-' . self::esc_attr($field_key) . '" value="' . ($checked ? '1' : '0') . '">';
        
        $html .= '<div class="switch-toggle">';
        // 复选框仅用于UI交互
        $html .= '<input type="checkbox" id="' . self::esc_attr($toggleId) . '" class="switch-checkbox" ' . $checked . '>';
        $html .= '<label class="switch-label" for="' . self::esc_attr($toggleId) . '">';
        $html .= '<span class="switch-inner" data-on="是" data-off="否"></span>';
        $html .= '<span class="switch-switch"></span>';
        $html .= '</label>';
        $html .= '</div>';
        
        // 内联JS处理点击事件
        $html .= '<script type="text/javascript">'
            . '(function(){'
            . '  var toggle = document.getElementById("' . self::esc_attr($toggleId) . '");'
            . '  if(!toggle) return;'
            . '  var wrapper = toggle.closest(".switch-field");'
            . '  var hidden = wrapper ? wrapper.querySelector("input[type=hidden]") : null;'
            . '  if(!hidden && wrapper) hidden = document.getElementById("field-' . self::esc_attr($field_key) . '");'
            . '  toggle.addEventListener("change", function(){'
            . '    if(hidden) { hidden.value = this.checked ? "1" : "0"; }'
            . '  });'
            . '})();'
            . '</script>';
            
        // 内联样式
        $html .= '<style>'
            . '.switch-toggle { position: relative; display: inline-block; margin-right: 10px; }'
            . '.switch-checkbox { display: none; }'
            . '.switch-label { display: block; width: 60px; height: 30px; margin-bottom: 0; text-indent: -150%; clip: rect(0 0 0 0); color: transparent; user-select: none; cursor: pointer; }'
            . '.switch-label::before, .switch-label::after { content: ""; display: block; position: absolute; cursor: pointer; }'
            . '.switch-label::before { width: 100%; height: 100%; background-color: #dedede; border-radius: 9999em; transition: background-color 0.25s ease; }'
            . '.switch-label::after { top: 2px; left: 2px; width: 26px; height: 26px; border-radius: 50%; background-color: white; box-shadow: 0 0 2px rgba(0, 0, 0, 0.45); transition: left 0.25s ease; }'
            . '.switch-checkbox:checked + .switch-label::before { background-color: transparent; border: 1px solid #4caf50; width: 58px; height: 28px; }'
            . '.switch-checkbox:checked + .switch-label::after { left: 32px; }'
            . '.switch-inner { display: block; position: absolute; top: 0; left: 0; width: 100%; height: 100%; overflow: hidden; text-indent: 0; font-size: 12px; line-height: 30px; color: white; text-align: center; z-index: 1; }'
            . '.switch-inner::before, .switch-inner::after { content: attr(data-off); position: absolute; top: 0; left: 0; text-indent: 24px; width: 100%; text-align: center; transition: left 0.25s ease; }'
            . '.switch-inner::after { content: attr(data-on); left: -100%; }'
            . '.switch-checkbox:checked + .switch-label .switch-inner { color: #4caf50; }'
            . '.switch-checkbox:checked + .switch-label .switch-inner::before { left: 100%; }'
            . '.switch-checkbox:checked + .switch-label .switch-inner::after { left: 0; text-indent: -24px; }'
            . '</style>';
            
        $html .= '</div>';
        
        if ($description) {
            $html .= '<p class="description">' . self::esc_html($description) . '</p>';
        }
        
        $html .= '</div>';
        return $html;
    }

    /**
     * 渲染前端显示库存字段（checkbox开关）
     * 
     * @param string $field_key 字段键名
     * @param string $field_value 字段值
     * @param bool $required 是否必填
     * @return string HTML字符串
     */
    private static function render_show_stock_field($field_key, $field_value, $required = false)
    {
        $checked = ($field_value === '1' || $field_value === 'on' || $field_value === true) ? ' checked' : '';

        $html = '<div class="switch-group show-stock-group">';
        $html .= '<label class="switch-label">';
        $html .= '<input type="hidden" name="' . self::esc_attr($field_key) . '" value="0">';
        $html .= '<input type="checkbox" name="' . self::esc_attr($field_key) . '" value="1"' . $checked;
        if ($required) {
            $html .= ' required';
        }
        $html .= '>';
        $html .= '<span class="switch-slider"></span>';
        $html .= '<span class="switch-text">';
        $html .= $checked ? '显示库存' : '不显示库存';
        $html .= '</span>';
        $html .= '</label>';
        $html .= '</div>';

        return $html;
    }

    /**
     * 渲染发售状态字段（radio选择）
     * 
     * @param string $field_key 字段键名
     * @param string $field_value 字段值
     * @param bool $required 是否必填
     * @return string HTML字符串
     */
    private static function render_sale_status_field($field_key, $field_value, $required = false)
    {
        $options = [
            'online' => '立即上架',
            'warehouse' => '放入仓库'
        ];

        $uid = uniqid('ss_');
        $scopeId = 'todo-sale-status-' . $uid;
        $groupName = $field_key . '_' . $uid;
        $html = '<div id="' . self::esc_attr($scopeId) . '" class="todo-sale-status-wrap">';
        $html .= '<input type="hidden" name="' . self::esc_attr($field_key) . '" value="' . self::esc_attr($field_value) . '">';
        $html .= '<div class="flex gap-4">';
        foreach ($options as $value => $label) {
            $checked = ($field_value === $value) ? ' checked' : '';
            $html .= '<label>';
            $html .= '<input type="radio" name="' . self::esc_attr($groupName) . '" value="' . self::esc_attr($value) . '"' . $checked;
            if ($required) {
                $html .= ' required';
            }
            $html .= '> ' . self::esc_html($label);
            $html .= '</label>';
        }
        $html .= '</div></div>';
        $html .= '<script type="text/javascript">' . "\n"
            . '(function(){' . "\n"
            . '  var root = document.getElementById("' . self::esc_attr($scopeId) . '");' . "\n"
            . '  if(!root) return;' . "\n"
            . '  var hidden = root.querySelector("input[type=hidden][name=\'' . self::esc_attr($field_key) . '\']");' . "\n"
            . '  var scope = root.closest("form") || document;' . "\n"
            . '  function update(){' . "\n"
            . '    var checked = root.querySelector("input[type=radio][name=\'' . self::esc_attr($groupName) . '\']:checked");' . "\n"
            . '    var val = checked ? checked.value : "";' . "\n"
            . '    if(hidden){ hidden.value = val; }' . "\n"
            . '    // 基于发售状态的字段显隐：data-sale-status-visibility="online|warehouse"' . "\n"
            . '    var blocks = scope.querySelectorAll("[data-sale-status-visibility]");' . "\n"
            . '    blocks.forEach(function(el){' . "\n"
            . '      var need = el.getAttribute("data-sale-status-visibility");' . "\n"
            . '      var show = (need === val);' . "\n"
            . '      el.style.display = show ? "" : "none";' . "\n"
            . '      if(!show){ el.querySelectorAll("[required]").forEach(function(inp){ inp.setAttribute("data-was-required","1"); inp.removeAttribute("required"); }); }' . "\n"
            . '      else { el.querySelectorAll("[data-was-required]").forEach(function(inp){ inp.setAttribute("required","required"); inp.removeAttribute("data-was-required"); }); }' . "\n"
            . '    });' . "\n"
            . '  }' . "\n"
            . '  root.querySelectorAll("input[type=radio][name=\'' . self::esc_attr($groupName) . '\']").forEach(function(r){ r.addEventListener("change", update); });' . "\n"
            . '  update();' . "\n"
            . '})();' . "\n"
            . '</script>';

        return $html;
    }

    /**
     * 渲染交付物类型字段（radio选择，带条件显示）
     * 
     * @param string $field_key 字段键名
     * @param string $field_value 字段值
     * @param bool $required 是否必填
     * @return string HTML字符串
     */
    private static function render_delivery_type_field($field_key, $field_value, $required = false)
    {
        $options = [
            'expresstrue' => '快递发货',
            'express' => '手动发货',
            'download' => '网盘下载',
            'content' => '文本内容'
        ];

        $uid = uniqid('dy_');
        $scopeId = 'todo-delivery-type-' . $uid;
        $groupName = $field_key . '_' . $uid;
        $html = '<div id="' . self::esc_attr($scopeId) . '" class="todo-delivery-type-wrap">';
        $html .= '<input type="hidden" name="' . self::esc_attr($field_key) . '" value="' . self::esc_attr($field_value) . '">';
        $html .= '<div class="flex gap-x-4 flex-wrap">';
        foreach ($options as $value => $label) {
            $checked = ($field_value === $value) ? ' checked' : '';
            $html .= '<label>';
            $html .= '<input type="radio" name="' . self::esc_attr($groupName) . '" value="' . self::esc_attr($value) . '"' . $checked;
            if ($required) {
                $html .= ' required';
            }
            $html .= ' data-delivery-type="' . self::esc_attr($value) . '"';
            $html .= '> ' . self::esc_html($label);
            $html .= '</label>';
        }
        $html .= '</div></div>';

        // 添加JavaScript来处理条件显示（作用域隔离 + 同步隐藏字段值）
        $html .= '<script type="text/javascript">' . "\n"
            . '(function(){' . "\n"
            . '  var root = document.getElementById("' . self::esc_attr($scopeId) . '");' . "\n"
            . '  if(!root) return;' . "\n"
            . '  var hidden = root.querySelector("input[type=hidden][name=\'' . self::esc_attr($field_key) . '\']");' . "\n"
            . '  var scope = root.closest("form") || document;' . "\n"
            . '  var radios = root.querySelectorAll("input[type=radio][name=\'' . self::esc_attr($groupName) . '\']");' . "\n"
            . '  var ptMap = {expresstrue:"physical", express:"virtual", download:"virtual", content:"virtual"};' . "\n"
            . '  radios.forEach(function(r){ var lab = r.closest("label"); if(lab && ptMap[r.value]){ lab.setAttribute("data-product-type-visibility", ptMap[r.value]); }});' . "\n"
            . '  var productTypeHidden = scope.querySelector("input[type=hidden][name=\'product_type\']");' . "\n"
            . '  function enforceByProductType(pt){' . "\n"
            . '    var allowed = (pt === "physical") ? ["expresstrue"] : (pt === "virtual" ? ["express","download","content"] : ["expresstrue","express","download","content"]);' . "\n"
            . '    var hasCheckedAllowed = false;' . "\n"
            . '    radios.forEach(function(r){' . "\n"
            . '      var allow = allowed.indexOf(r.value) !== -1;' . "\n"
            . '      r.disabled = !allow;' . "\n"
            . '      var lab = r.closest("label"); if(lab){ lab.style.display = allow ? "" : "none"; }' . "\n"
            . '      if(r.checked && allow){ hasCheckedAllowed = true; }' . "\n"
            . '    });' . "\n"
            . '    if(!hasCheckedAllowed){' . "\n"
            . '      var firstAllowed = null;' . "\n"
            . '      radios.forEach(function(r){ if(!firstAllowed && !r.disabled){ firstAllowed = r; } });' . "\n"
            . '      if(firstAllowed){ firstAllowed.checked = true; }' . "\n"
            . '    }' . "\n"
            . '    update();' . "\n"
            . '  }' . "\n"
            . '  if(productTypeHidden){' . "\n"
            . '    enforceByProductType(productTypeHidden.value || "");' . "\n"
            . '    try{ productTypeHidden.dispatchEvent(new CustomEvent("todo:product_type_change", {detail:{value: productTypeHidden.value}, bubbles:true})); }catch(e){}' . "\n"
            . '    productTypeHidden.addEventListener("change", function(){ enforceByProductType(productTypeHidden.value || ""); });' . "\n"
            . '  }' . "\n"
            . '  scope.addEventListener("todo:product_type_change", function(e){ var v=(e && e.detail && e.detail.value) ? e.detail.value : (productTypeHidden?productTypeHidden.value:""); enforceByProductType(v); });' . "\n"
            . '  var expressFields = ["[data-field-key=\'delivery_express_note\']"];' . "\n"
            . '  var downloadFields = ["[data-field-key=\'delivery_download_url\']", "[data-field-key=\'delivery_download_code\']", "[data-field-key=\'delivery_download_password\']", "[data-field-key=\'delivery_download_note\']"];' . "\n"
            . '  var contentFields = ["[data-field-key=\'delivery_content_note\']", "[data-field-key=\'auto_gen_license_key\']"];' . "\n"
            . '  function toggle(list, show){' . "\n"
            . '    if(!list || typeof list.forEach !== "function"){ list = []; }' . "\n"
            . '    list.forEach(function(sel){' . "\n"
            . '      // 先在scope中查找，如果找不到则在document中查找（兼容多语言面板等场景）' . "\n"
            . '      var elements = scope.querySelectorAll(sel);' . "\n"
            . '      if(elements.length === 0 && scope !== document){' . "\n"
            . '        elements = document.querySelectorAll(sel);' . "\n"
            . '      }' . "\n"
            . '      elements.forEach(function(el){' . "\n"
            . '        el.style.display = show ? "" : "none";' . "\n"
            . '        if(!show){' . "\n"
            . '          el.querySelectorAll("[required]").forEach(function(inp){ inp.setAttribute("data-was-required","1"); inp.removeAttribute("required"); });' . "\n"
            . '        } else {' . "\n"
            . '          el.querySelectorAll("[data-was-required]").forEach(function(inp){ inp.setAttribute("required","required"); inp.removeAttribute("data-was-required"); });' . "\n"
            . '        }' . "\n"
            . '      });' . "\n"
            . '    });' . "\n"
            . '  }' . "\n"
            . '  function getSelected(){ var c = root.querySelector("input[type=radio][name=\'' . self::esc_attr($groupName) . '\']:checked"); return c ? c.value : ""; }' . "\n"
            . '  function update(){' . "\n"
            . '    var val = getSelected();' . "\n"
            . '    if(hidden){ hidden.value = val; }' . "\n"
            . '    toggle(expressFields, false); toggle(downloadFields, false); toggle(contentFields, false);' . "\n"
            . '    if(val === "express"){ toggle(expressFields, true); }' . "\n"
            . '    else if(val === "download"){ toggle(downloadFields, true); }' . "\n"
            . '    else if(val === "content"){ toggle(contentFields, true); }' . "\n"
            . '    // 同步基于交付类型的字段显隐：data-delivery-type-visibility="express|download|content"' . "\n"
            . "    var blocks = scope.querySelectorAll('[data-delivery-type-visibility]');" . "\n"
            . '    if(blocks.length === 0 && scope !== document){' . "\n"
            . '      blocks = document.querySelectorAll("[data-delivery-type-visibility]");' . "\n"
            . '    }' . "\n"
            . '    blocks.forEach(function(el){' . "\n"
            . "      var need = el.getAttribute('data-delivery-type-visibility');" . "\n"
            . '      var show = (need === val);' . "\n"
            . '      el.style.display = show ? "" : "none";' . "\n"
            . "      if(!show){ el.querySelectorAll('[required]').forEach(function(inp){ inp.setAttribute('data-was-required','1'); inp.removeAttribute('required'); }); }" . "\n"
            . "      else { el.querySelectorAll('[data-was-required]').forEach(function(inp){ inp.setAttribute('required','required'); inp.removeAttribute('data-was-required'); }); }" . "\n"
            . '    });' . "\n"
            . '  }' . "\n"
            . '  radios.forEach(function(r){ r.addEventListener("change", update); });' . "\n"
            . '  update();' . "\n"
            . '})();' . "\n"
            . '</script>';

        return $html;
    }

    /**
     * 渲染产品自定义参数字段
     */
    private static function render_custom_params_field($field_key, $field_value, $required = false)
    {
        $html = '';

        // 解析现有参数数据
        $params = [];
        if (!empty($field_value)) {
            $decoded = json_decode($field_value, true);
            if (is_array($decoded)) {
                $params = $decoded;
            }
        }

        // 生成下一个参数ID
        $next_param_id = 1;
        if (!empty($params)) {
            $max_id = max(array_keys($params));
            $next_param_id = $max_id + 1;
        }

        // 表头
        $html .= '<div style="overflow: auto;"><div id="customParamTitle" style="margin: 0 0 5px; ' . (empty($params) ? 'display: none;' : 'display: block;') . '">';
        $html .= '<dl class="flex gap-3">';
        $html .= '<dt style="min-width: 60px; width: 60px;">排序</dt>';
        $html .= '<dt style="min-width: 220px;">参数名称</dt>';
        $html .= '<dd style="min-width: 220px;">参数值</dd>';
        $html .= '</dl>';
        $html .= '</div>';

        // 参数列表容器
        $html .= '<div id="customParam" style="margin: 0 0 10px;">';
        $html .= '<input type="hidden" name="del_param_id" id="delParamID">';

        // 渲染现有参数
        foreach ($params as $param_id => $param_data) {
            $sort_order = isset($param_data['sort_order']) ? $param_data['sort_order'] : 100;
            $param_name = isset($param_data['param_name']) ? esc_attr($param_data['param_name']) : '';
            $param_value = isset($param_data['param_value']) ? esc_attr($param_data['param_value']) : '';

            $html .= '<dl class="flex gap-3" style="margin: 0 0 5px;">';
            $html .= '<input type="hidden" name="param_id[' . $param_id . ']" value="' . $param_id . '">';
            $html .= '<dt>';
            $html .= '<input type="text" name="sort_order[' . $param_id . ']" value="' . $sort_order . '" style="min-width: 60px; width: 60px;">';
            $html .= '</dt>';
            $html .= '<dt>';
            $html .= '<input type="text" name="param_name[' . $param_id . ']" value="' . $param_name . '">';
            $html .= '</dt>';
            $html .= '<dd style="display: flex; align-items: center; gap: 5px;">';
            $html .= '<input type="text" name="param_value[' . $param_id . ']" value="' . $param_value . '">';
            $html .= '<a class="btn btn-normal" href="javascript:void(0);" onclick="this.closest(\'dl\').remove();var delInput=document.getElementById(\'delParamID\');var currentDel=delInput.value;delInput.value=currentDel ? currentDel + \' ,\'  + ' . $param_id . ' : ' . $param_id . ';document.getElementById(\'customParamTitle\').style.display = document.getElementById(\'customParam\').children.length < 2 ? \'none\' : \'\';" style="margin-left: 10px;">×</a>';
            $html .= '</dd>';
            $html .= '</dl>';
        }
        $html .= '</div></div>';
        // 添加按钮
        $html .= '<a href="JavaScript:void(0);" id="addCustomParamBtn" data-param_id="' . $next_param_id . '" onclick="addCustomParam(this);" class="btn button-link"><i class="toicon">✚</i></a>';

        // 添加JavaScript功能
        $html .= '<script type="text/javascript">';
        $html .= 'window.addCustomParam = function(btn) {';
        $html .= '    var paramId = parseInt(btn.getAttribute("data-param_id"));';
        $html .= '    var container = document.getElementById("customParam");';
        $html .= '    var dl = document.createElement("dl");';
        $html .= '    dl.className = "flex gap-3";';
        $html .= '    dl.style.margin = "0 0 5px";';
        $html .= '    dl.innerHTML = \'<input type="hidden" name="param_id[\' + paramId + \']" value="\' + paramId + \'">\'+';
        $html .= '        \'<dt><input type="text" name="sort_order[\' + paramId + \']" value="100"></dt>\'+';
        $html .= '        \'<dt><input type="text" name="param_name[\' + paramId + \']" value=""></dt>\'+';
        $html .= '        \'<dd><input type="text" name="param_value[\' + paramId + \']" value="">\'+';
        $html .= '        \'<a class="btn btn-normal" href="javascript:void(0);" onclick="this.closest(\\\'dl\\\').remove();var delInput=document.getElementById(\\\'delParamID\\\');var currentDel=delInput.value;delInput.value=currentDel ? currentDel + \\\',\\\' + \' + paramId + \' : \' + paramId + \';document.getElementById(\\\'customParamTitle\\\').style.display = document.getElementById(\\\'customParam\\\').children.length < 2 ? \\\'none\\\' : \\\'\\\';" style="margin-left: 10px;">×</a></dd>\';';
        $html .= '    container.appendChild(dl);';
        $html .= '    btn.setAttribute("data-param_id", paramId + 1);';
        $html .= '    var title = document.getElementById("customParamTitle");';
        $html .= '    if (title && title.style.display === "none") { title.style.display = "block"; }';
        $html .= '}';
        $html .= '</script>';

        return $html;
    }

    private static function render_custom_params_field_scoped($field_key, $field_value, $required = false)
    {
        $html = '';
        $uid = substr(md5(uniqid('', true)), 0, 8);
        $titleId = 'customParamTitle-' . $uid;
        $containerId = 'customParam-' . $uid;
        $delId = 'delParamID-' . $uid;
        $btnId = 'addCustomParamBtn-' . $uid;

        $params = [];
        if (!empty($field_value)) {
            $decoded = json_decode($field_value, true);
            if (is_array($decoded)) {
                $params = $decoded;
            }
        }
        $next_param_id = 1;
        if (!empty($params)) {
            $max_id = max(array_keys($params));
            $next_param_id = $max_id + 1;
        }

        $html .= '<div style="overflow: auto;"><div id="' . self::esc_attr($titleId) . '" style="margin: 0 0 5px; ' . (empty($params) ? 'display: none;' : 'display: block;') . '">';
        $html .= '<dl class="flex gap-3">';
        $html .= '<dt style="min-width: 60px; width: 60px;">排序</dt>';
        $html .= '<dt style="min-width: 220px;">参数名称</dt>';
        $html .= '<dd style="min-width: 220px;">参数值</dd>';
        $html .= '</dl>';
        $html .= '</div>';

        $html .= '<div id="' . self::esc_attr($containerId) . '" style="margin: 0 0 10px;">';
        $html .= '<input type="hidden" name="del_param_id" id="' . self::esc_attr($delId) . '">';
        foreach ($params as $param_id => $param_data) {
            $sort_order = isset($param_data['sort_order']) ? $param_data['sort_order'] : 100;
            $param_name = isset($param_data['param_name']) ? esc_attr($param_data['param_name']) : '';
            $param_value = isset($param_data['param_value']) ? esc_attr($param_data['param_value']) : '';
            $html .= '<dl class="flex gap-3" style="margin: 0 0 5px;">';
            $html .= '<input type="hidden" name="param_id[' . $param_id . ']" value="' . $param_id . '">';
            $html .= '<dt><input type="text" name="sort_order[' . $param_id . ']" value="' . $sort_order . '" style="min-width: 60px; width: 60px;"></dt>';
            $html .= '<dt><input type="text" name="param_name[' . $param_id . ']" value="' . $param_name . '"></dt>';
            $html .= '<dd style="display: flex; align-items: center; gap: 5px;"><input type="text" name="param_value[' . $param_id . ']" value="' . $param_value . '">';
            $html .= '<a class="btn btn-normal" href="javascript:void(0);" onclick="this.closest(\'dl\').remove();var delInput=document.getElementById(\'' . self::esc_attr($delId) . '\');var currentDel=delInput.value;delInput.value=currentDel ? currentDel + \' ,\'  + ' . $param_id . ' : ' . $param_id . ';document.getElementById(\'' . self::esc_attr($titleId) . '\').style.display = document.getElementById(\'' . self::esc_attr($containerId) . '\').children.length < 2 ? \'none\' : \'\';" style="margin-left: 10px;">×</a>';
            $html .= '</dd>';
            $html .= '</dl>';
        }
        $html .= '</div></div>';

        $html .= '<a href="JavaScript:void(0);" id="' . self::esc_attr($btnId) . '" data-uid="' . self::esc_attr($uid) . '" data-param_id="' . $next_param_id . '" onclick="todoAddCustomParam(this);" class="btn button-link" style="display: inline-block;"><i class="toicon">✚</i></a>';

        // 添加JavaScript功能（作用域隔离版本）
        $html .= '<script type="text/javascript">' . "\n"
            . '(function(){' . "\n"
            . '  var uid = "' . self::esc_attr($uid) . '";' . "\n"
            . '  var titleId = "' . self::esc_attr($titleId) . '";' . "\n"
            . '  var containerId = "' . self::esc_attr($containerId) . '";' . "\n"
            . '  var delId = "' . self::esc_attr($delId) . '";' . "\n"
            . '  if(typeof window.todoAddCustomParam === "undefined"){' . "\n"
            . '    window.todoAddCustomParam = function(btn){' . "\n"
            . '      var uid = btn.getAttribute("data-uid");' . "\n"
            . '      if(!uid) return;' . "\n"
            . '      var titleId = "customParamTitle-" + uid;' . "\n"
            . '      var containerId = "customParam-" + uid;' . "\n"
            . '      var delId = "delParamID-" + uid;' . "\n"
            . '      var paramId = parseInt(btn.getAttribute("data-param_id")) || 1;' . "\n"
            . '      var container = document.getElementById(containerId);' . "\n"
            . '      if(!container) return;' . "\n"
            . '      var dl = document.createElement("dl");' . "\n"
            . '      dl.className = "flex gap-3";' . "\n"
            . '      dl.style.margin = "0 0 5px";' . "\n"
            . '      dl.innerHTML = \'<input type="hidden" name="param_id[\' + paramId + \']" value="\' + paramId + \'">\'+' . "\n"
            . '        \'<dt><input type="text" name="sort_order[\' + paramId + \']" value="100" style="min-width: 60px; width: 60px;"></dt>\'+' . "\n"
            . '        \'<dt><input type="text" name="param_name[\' + paramId + \']" value=""></dt>\'+' . "\n"
            . '        \'<dd style="display: flex; align-items: center; gap: 5px;"><input type="text" name="param_value[\' + paramId + \']" value="">\'+' . "\n"
            . '        \'<a class="btn btn-normal" href="javascript:void(0);" onclick="this.closest(\\\'dl\\\').remove();var delInput=document.getElementById(\\\'\' + delId + \'\\\');var currentDel=delInput.value;delInput.value=currentDel ? currentDel + \\\',\\\' + \' + paramId + \' : \' + paramId + \';var titleEl=document.getElementById(\\\'\' + titleId + \'\\\');var containerEl=document.getElementById(\\\'\' + containerId + \'\\\');if(titleEl && containerEl){titleEl.style.display = containerEl.children.length < 2 ? \\\'none\\\' : \\\'\\\';}" style="margin-left: 10px;">×</a></dd>\';' . "\n"
            . '      container.appendChild(dl);' . "\n"
            . '      btn.setAttribute("data-param_id", paramId + 1);' . "\n"
            . '      var title = document.getElementById(titleId);' . "\n"
            . '      if (title && title.style.display === "none") { title.style.display = "block"; }' . "\n"
            . '    };' . "\n"
            . '  }' . "\n"
            . '})();' . "\n"
            . '</script>';

        return $html;
    }

    /**
     * 渲染下载配置字段
     */
    private static function render_download_builder_field($name, $value, $id, $settings, $required)
    {
        $max_downloads = $settings['max_downloads'] ?? 10;
        $allow_file_select = $settings['allow_file_select'] ?? true;
        $placeholder = $settings['placeholder'] ?? '配置下载地址...';

        // 解析现有值
        $downloads = [];
        if ($value) {
            $downloads = is_string($value) ? json_decode($value, true) : $value;
            if (!is_array($downloads)) {
                $downloads = [];
            }
        }

        $html = '<div class="download-builder-wrapper" data-max-downloads="' . self::esc_attr($max_downloads) . '" data-allow-file-select="' . ($allow_file_select ? '1' : '0') . '" data-placeholder="' . self::esc_attr($placeholder) . '">';
        $html .= '<input type="hidden" name="' . self::esc_attr($name) . '" id="' . self::esc_attr($id) . '" value="' . self::esc_attr(json_encode($downloads)) . '">';

        // 下载列表容器
        $html .= '<div class="download-list" id="download-list-' . self::esc_attr($id) . '">';

        // 渲染现有下载项
        foreach ($downloads as $index => $download) {
            $html .= self::render_download_item($index, $download, $allow_file_select, $placeholder);
        }

        $html .= '</div>';

        // 添加按钮
        $html .= '<div class="download-actions">';
        $html .= '<button type="button" class="btn button-primary add-download-btn" onclick="addDownloadItem(\'' . self::esc_attr($id) . '\')">';
        $html .= '添加下载地址</button>';
        $html .= '</div>';

        // 引入JavaScript文件
        $html .= '<script type="text/javascript">';
        $html .= 'if (typeof window.downloadBuilderLoaded === "undefined") {';
        $html .= '    window.downloadBuilderLoaded = true;';
        $html .= '    var script = document.createElement("script");';
        $html .= '    script.src = "' . todo_admin_url() . 'assets/js/download-builder.js";';
        $html .= '    document.head.appendChild(script);';
        $html .= '}';
        $html .= '</script>';

        $html .= '</div>';

        return $html;
    }

    /**
     * 渲染单个下载项
     */
    private static function render_download_item($index, $download, $allow_file_select = true, $placeholder = '输入地址或选择文件')
    {
        $url = $download['url'] ?? '';
        $alias = $download['alias'] ?? '';
        $extract_code = $download['extract_code'] ?? '';
        $password = $download['password'] ?? '';
        $note = $download['note'] ?? '';

        $html = '<div class="download-item" data-index="' . self::esc_attr($index) . '">';
        $html .= '<div class="download-item-header flex gap-5 items-center">';
        $html .= '<span class="download-item-title" style="font-size: 20px; color: #ccc; font-style: italic;">#' . ($index + 1) . '</span>';
        $html .= '<button type="button" class="btn button-link remove-download-btn" onclick="removeDownloadItem(this)">✕</button>';
        $html .= '</div>';

        $html .= '<div class="download-item-content flex gap-x-10 items-top flex-wrap" style="margin: 10px 0 0 40px;">';

        // 下载地址
        $html .= '<div class="form-group">';
        $html .= '<h4>下载地址</h4>';
        $html .= '<div class="input-group flex flex-wrap gap-2">';
        $html .= '<input type="text" class="form-control download-url" value="' . self::esc_attr($url) . '" placeholder="' . self::esc_attr($placeholder) . '">';
        if ($allow_file_select) {
            $html .= '<button type="button" class="btn button-link select-file-btn" onclick="selectMediaFile(this)">✚ 选择文件</button>';
        }
        $html .= '</div>';
        $html .= '</div>';

        // 别名
        $html .= '<div class="form-group">';
        $html .= '<h4>别名</h4>';
        $html .= '<input type="text" class="form-control download-alias" value="' . self::esc_attr($alias) . '" placeholder="下载链接显示名称">';
        $html .= '</div>';

        // 提取码
        $html .= '<div class="form-group">';
        $html .= '<h4>提取码:</h4>';
        $html .= '<input type="text" class="form-control download-extract-code" value="' . self::esc_attr($extract_code) . '">';
        $html .= '</div>';

        // 解压密码
        $html .= '<div class="form-group">';
        $html .= '<h4>解压密码</h4>';
        $html .= '<input type="text" class="form-control download-password" value="' . self::esc_attr($password) . '">';
        $html .= '</div>';

        // 说明
        $html .= '<div class="form-group">';
        $html .= '<h4>备注</h4>';
        $html .= '<textarea class="form-control download-note" rows="2">' . self::esc_html($note) . '</textarea>';
        $html .= '</div>';

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }
}
