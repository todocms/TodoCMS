<?php
/**
 * TodoCMS 自定义字段渲染器
 *
 * 用于统一渲染模型自定义字段（admin使用）
 * - 覆盖 admin/components/model-fields-component.php 中的 render_field_html 逻辑
 * - 覆盖 admin/pages/content/categories.php 中的 render_field_input 逻辑
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class Todo_Custom_Field_Renderer {
    /**
     * 渲染字段HTML（统一入口）
     *
     * @param array $field 字段配置（模型字段结构）
     * @param mixed $value 字段值
     * @param array $options 渲染选项：name、id、post_id、context 等
     * @return string 生成的HTML字符串
     */
    public static function render_field($field, $value = '', $options = []) {
        $field_key = isset($field['field_key']) ? $field['field_key'] : '';
        $field_type = isset($field['field_type']) ? $field['field_type'] : 'text';
        $field_label = isset($field['field_label']) ? $field['field_label'] : (isset($field['field_name']) ? $field['field_name'] : '');
        $field_description = isset($field['field_description']) ? $field['field_description'] : '';
        $is_required = !empty($field['is_required']);

        // 统一 name / id
        $field_id = isset($options['id']) && $options['id'] !== '' ? $options['id'] : ('field_' . $field_key);
        $field_name = isset($options['name']) && $options['name'] !== '' ? $options['name'] : ('model_field_' . $field_key);

        // 标准化选项配置
        $field_options = self::normalize_field_options(isset($field['field_options']) ? $field['field_options'] : [], isset($field['field_values']) ? $field['field_values'] : []);

        $layout = [];
        if (isset($field['layout']) && is_array($field['layout'])) {
            $layout = $field['layout'];
        } elseif (isset($field['field_layout']) && is_array($field['field_layout'])) {
            $layout = $field['field_layout'];
        } elseif (isset($field_options['layout']) && is_array($field_options['layout'])) {
            $layout = $field_options['layout'];
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
        $html = '<div class="form-group fixed-field' . $w100 . '" data-field-type="' . self::esc_attr($field_type) . '" data-field-key="' . self::esc_attr($field_key) . '">';

        if ($field_label !== '') {
            $html .= '<h4>' . self::esc_html($field_label);
            if ($is_required) {
                $html .= ' <span class="required">*</span>';
            }
            $html .= '</h4>';
        }

        // 输入控件
        $html .= self::render_field_input($field_type, $field_key, $field_name, $field_id, $value, $field_options, $is_required);

        // 字段描述
        if (!empty($field_description)) {
            $html .= '<p class="description">' . self::esc_html($field_description) . '</p>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * 渲染字段输入区域
     */
    private static function render_field_input($type, $field_key, $name, $id, $value, $field_options, $is_required) {
        switch ($type) {
            case 'text':
            case 'email':
            case 'url':
                return '<input type="' . self::esc_attr($type) . '" id="' . self::esc_attr($id) . '" name="' . self::esc_attr($name) . '" value="' . self::esc_attr($value) . '" class="form-control"' . ($is_required ? ' required' : '') . ' />';

            case 'textarea':
                $ta_val = $value;
                if (!empty($field_options['use_editor'])) {
                    if (function_exists('todo_convert_content_urls_to_relative')) {
                        $ta_val = todo_convert_content_urls_to_relative($ta_val);
                    }
                    if (function_exists('todo_normalize_content_root_relative_urls')) {
                        $ta_val = todo_normalize_content_root_relative_urls($ta_val);
                    }
                }
                return '<textarea id="' . self::esc_attr($id) . '" name="' . self::esc_attr($name) . '" class="form-control"' . ($is_required ? ' required' : '') . '>' . self::esc_html($ta_val) . '</textarea>';

            case 'select':
                $options = self::normalize_options_data($field_options, isset($field_options['options']) ? $field_options['options'] : (isset($field_options['values']) ? $field_options['values'] : []));
                $html = '<select id="' . self::esc_attr($id) . '" name="' . self::esc_attr($name) . '" class="form-control"' . ($is_required ? ' required' : '') . '>';
                $html .= '<option value="">请选择...</option>';
                foreach ($options as $option_value => $option_label) {
                    $selected = ($value == $option_value) ? ' selected' : '';
                    $html .= '<option value="' . self::esc_attr($option_value) . '"' . $selected . '>' . self::esc_html($option_label) . '</option>';
                }
                $html .= '</select>';
                return $html;

            case 'radio':
                $options = self::normalize_options_data($field_options, isset($field_options['options']) ? $field_options['options'] : (isset($field_options['values']) ? $field_options['values'] : []));
                $html = '<div class="flex gap-5 items-center">';
                $idx = 0;
                foreach ($options as $option_value => $option_label) {
                    $idx++;
                    $radio_id = 'field_' . self::esc_attr($field_key) . '_' . $idx;
                    $checked = ($value == $option_value) ? ' checked' : '';
                    $html .= '<div class="flex gap-1 items-center">';
                    $html .= '<input type="radio" id="' . $radio_id . '" name="' . self::esc_attr($name) . '" value="' . self::esc_attr($option_value) . '" class="form-check-input"' . ($is_required ? ' required' : '') . $checked . ' />';
                    $html .= '<label class="nofor" for="' . $radio_id . '">' . self::esc_html($option_label) . '</label>';
                    $html .= '</div>';
                }
                $html .= '</div>';
                return $html;

            case 'checkbox':
                $options = self::normalize_options_data($field_options, isset($field_options['options']) ? $field_options['options'] : (isset($field_options['values']) ? $field_options['values'] : []));
                $selected_values = self::normalize_multi_values($value);
                $scope_id = 'checkbox_group_' . self::esc_attr($id);
                $html = '<div class="flex gap-5 items-center" id="' . $scope_id . '">';
                $idx = 0;
                foreach ($options as $option_value => $option_label) {
                    $idx++;
                    $checkbox_id = 'field_' . self::esc_attr($field_key) . '_' . $idx;
                    $checked = in_array((string)$option_value, $selected_values, true) ? ' checked' : '';
                    $html .= '<div class="flex gap-1 items-center">';
                    $html .= '<input type="checkbox" id="' . $checkbox_id . '" name="' . self::esc_attr($name) . '[]" value="' . self::esc_attr($option_value) . '" class="form-check-input"' . $checked . ' />';
                    $html .= '<label class="nofor" for="' . $checkbox_id . '">' . self::esc_html($option_label) . '</label>';
                    $html .= '</div>';
                }
                $html .= '</div>';
                if ($is_required) {
                    $html .= '<script type="text/javascript">' . "\n"
                        . '(function(){' . "\n"
                        . '  var root = document.getElementById("' . $scope_id . '");' . "\n"
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

            case 'switch':
                $switch_field_config = array(
                    'field_key' => $name,
                    'on_text' => isset($field_options['on_text']) ? $field_options['on_text'] : (isset($field_options['on']) ? $field_options['on'] : '开启'),
                    'off_text' => isset($field_options['off_text']) ? $field_options['off_text'] : (isset($field_options['off']) ? $field_options['off'] : '关闭'),
                    'field_default' => isset($field_options['field_default']) ? $field_options['field_default'] : '',
                    'is_required' => $is_required
                );
                $switch_instance = new Field_Type_Switch($switch_field_config, $value);
                return $switch_instance->render();

            case 'region':
                $region_field_config = array(
                    'field_key' => $name,
                    'field_default' => isset($field_options['field_default']) ? $field_options['field_default'] : '',
                    'region_level' => isset($field_options['region_level']) ? intval($field_options['region_level']) : 5,
                    'is_required' => $is_required
                );
                $region_instance = new Field_Type_Region($region_field_config, $value);
                return $region_instance->render();

            case 'number':
                return '<input type="number" id="' . self::esc_attr($id) . '" name="' . self::esc_attr($name) . '" value="' . self::esc_attr($value) . '" class="form-control"' . ($is_required ? ' required' : '') . ' />';

            case 'date':
                return '<input type="date" id="' . self::esc_attr($id) . '" name="' . self::esc_attr($name) . '" value="' . self::esc_attr($value) . '" class="form-control"' . ($is_required ? ' required' : '') . ' />';

            case 'datetime':
                return '<input type="datetime-local" id="' . self::esc_attr($id) . '" name="' . self::esc_attr($name) . '" value="' . self::esc_attr($value) . '" class="form-control"' . ($is_required ? ' required' : '') . ' />';

            case 'multi_file':
                $field_config = array(
                    'key' => $field_key,
                    'name' => $name, // 使用传入的表单名称（如 custom_ 前缀）
                    'id' => $id,     // 传入渲染ID，便于组件生成隐藏域ID
                    'options' => $field_options,
                    'required' => $is_required
                );
                $field_instance = new Field_Type_Multi_File($field_config, $value);
                return $field_instance->render();

            case 'editor':
                $editor_val = $value;
                if (function_exists('todo_convert_content_urls_to_relative')) {
                    $editor_val = todo_convert_content_urls_to_relative($editor_val);
                }
                if (function_exists('todo_normalize_content_root_relative_urls')) {
                    $editor_val = todo_normalize_content_root_relative_urls($editor_val);
                }
                return '<textarea id="' . self::esc_attr($id) . '" name="' . self::esc_attr($name) . '" class="form-control todo-editor" rows="15"' . ($is_required ? ' required' : '') . ' style="width: 100%;">' . self::esc_html($editor_val) . '</textarea>';

            case 'html':
                $html_val = $value;
                if (function_exists('todo_convert_content_urls_to_relative')) {
                    $html_val = todo_convert_content_urls_to_relative($html_val);
                }
                if (function_exists('todo_normalize_content_root_relative_urls')) {
                    $html_val = todo_normalize_content_root_relative_urls($html_val);
                }
                return '<textarea id="' . self::esc_attr($id) . '" name="' . self::esc_attr($name) . '" class="form-control html-editor" rows="5"' . ($is_required ? ' required' : '') . '>' . self::esc_html($html_val) . '</textarea>';

            default:
                return '<input type="text" id="' . self::esc_attr($id) . '" name="' . self::esc_attr($name) . '" value="' . self::esc_attr($value) . '" class="form-control"' . ($is_required ? ' required' : '') . ' />';
        }
    }

    /**
     * 规范化字段选项
     * - 接受序列化字符串/JSON/纯文本/数组
     */
    private static function normalize_field_options($field_options_raw, $field_values_raw) {
        $field_options = array();
        // 优先解析 $field_options
        if (is_string($field_options_raw)) {
            $trim = trim($field_options_raw);
            $decoded_json = json_decode($trim, true);
            if (is_array($decoded_json)) {
                $field_options = $decoded_json;
            } else {
                $maybe_unser = @unserialize($trim);
                if ($maybe_unser !== false && is_array($maybe_unser)) {
                    $field_options = $maybe_unser;
                } else {
                    $field_options = array('options' => $trim);
                }
            }
        } elseif (is_array($field_options_raw)) {
            $field_options = $field_options_raw;
        }
        // 回退使用 $field_values
        if (empty($field_options) && !empty($field_values_raw)) {
            $field_options = array('options' => $field_values_raw);
        }
        return $field_options;
    }

    /**
     * 规范化选项数据到 key=>label
     */
    private static function normalize_options_data($field_options, $options_data) {
        $normalized = array();
        // 如果是字符串：逐行 value|label
        if (is_string($options_data)) {
            $lines = preg_split("/\r\n|\n|\r/", $options_data);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $parts = explode('|', $line, 2);
                $option_value = trim($parts[0]);
                $option_label = isset($parts[1]) ? trim($parts[1]) : $option_value;
                $normalized[$option_value] = $option_label;
            }
        } elseif (is_array($options_data)) {
            foreach ($options_data as $k => $v) {
                if (is_string($v)) {
                    if (strpos($v, '|') !== false) {
                        list($val, $lab) = array_map('trim', explode('|', $v, 2));
                        $normalized[$val] = $lab;
                    } else {
                        $val = (is_string($k) && $k !== '') ? $k : $v;
                        $normalized[$val] = $v;
                    }
                } elseif (is_array($v)) {
                    $val = isset($v['value']) ? $v['value'] : ((is_string($k) && $k !== '') ? $k : '');
                    $lab = isset($v['label']) ? $v['label'] : (isset($v['text']) ? $v['text'] : $val);
                    if ($val !== '') {
                        $normalized[$val] = $lab;
                    }
                }
            }
        }
        return $normalized;
    }

    /**
     * 规范化多选值为字符串数组
     * - 支持数组、JSON字符串、序列化字符串、逗号分隔字符串、单值
     */
    private static function normalize_multi_values($val) {
        $selected = array();
        if (is_array($val)) {
            $selected = $val;
        } elseif (is_string($val) && $val !== '') {
            $fv = trim($val);
            // JSON 数组
            if ((substr($fv, 0, 1) === '[') && (substr($fv, -1) === ']')) {
                $decoded = json_decode($fv, true);
                if (is_array($decoded)) {
                    $selected = $decoded;
                }
            }
            // PHP 序列化数组
            if (empty($selected)) {
                $unser = @unserialize($fv);
                if ($unser !== false && is_array($unser)) {
                    $selected = $unser;
                }
            }
            // 逗号分隔或单值
            if (empty($selected)) {
                if (strpos($fv, ',') !== false) {
                    $selected = explode(',', $fv);
                } else {
                    $selected[] = $fv;
                }
            }
        }
        // 统一修剪并转字符串
        return array_map(function($v){ return trim(strval($v)); }, $selected);
    }

    /**
     * 安全转义HTML属性
     */
    private static function esc_attr($text) {
        if (is_array($text)) {
            $text = json_encode($text, JSON_UNESCAPED_UNICODE);
        }
        $text = is_scalar($text) ? (string) $text : '';
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 安全转义HTML内容
     */
    private static function esc_html($text) {
        if (is_array($text)) {
            $text = json_encode($text, JSON_UNESCAPED_UNICODE);
        }
        $text = is_scalar($text) ? (string) $text : '';
        return htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
    }
}
