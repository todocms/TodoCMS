<?php
/**
 * 复选框字段类型
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class Field_Type_Checkbox extends Field_Type_Base {
    /**
     * 渲染字段HTML
     *
     * @return string 字段HTML
     */
    protected function render_html() {
        $options = $this->get_options();
        $values = $this->get_selected_values();
        
        $html = '<div class="form-group">';
        $html .= '<label>' . todo_esc_html($this->field['field_name'] ?? $this->field['field_key']) . '</label>';
        $html .= '<div class="flex gap-5 items-center">';
        
        foreach ($options as $option_value => $option_label) {
            // 如果选项是数组格式，使用键值对；否则使用值作为标签
            if (is_numeric($option_value)) {
                $option_value = $option_label;
            }
            
            $id = 'field-' . $this->field['field_key'] . '-' . todo_sanitize_key($option_value);
            
            // 标签包含输入框和文本
            $html .= sprintf(
                '<label class="form-check-label" for="%s"><input type="checkbox" class="form-check-input" name="%s" id="%s" value="%s" %s %s><span>%s</span></label>',
                todo_esc_attr($id),
                todo_esc_attr($this->field['field_key']),
                todo_esc_attr($id),
                todo_esc_attr($option_value),
                in_array($option_value, $values) ? 'checked' : '',
                ($this->field['is_required'] ?? false) ? 'required' : '',
                todo_esc_html($option_label)
            );
        }

        $html .= '</div>';
        $html .= '<p class="description">' . todo_esc_html($this->field['field_description'] ?? '') . '</p>';
        $html .= '</div>';
        
        // 添加隐藏字段以确保在没有选择时也能提交空值
        $html .= sprintf(
            '<input type="hidden" name="%s_submitted" value="1">',
            todo_esc_attr($this->field['field_key'])
        );
        
        return $html;
    }
    
    /**
     * 获取已选择的值
     *
     * @return array 已选择的值数组
     */
    protected function get_selected_values() {
        $value = $this->value ?? $this->field['field_default'] ?? [];
        
        // 确保返回数组
        if (is_string($value)) {
            // 如果是字符串，尝试解析为数组
            if (strpos($value, ',') !== false) {
                $value = array_map('trim', explode(',', $value));
            } elseif (!empty($value)) {
                $value = [$value];
            } else {
                $value = [];
            }
        } elseif (!is_array($value)) {
            $value = empty($value) ? [] : [$value];
        }
        
        return array_filter($value); // 过滤空值
    }
    
    /**
     * 获取选项列表
     *
     * @return array 选项列表
     */
    protected function get_options() {
        $options = [];
        
        // 优先从field_values获取选项，如果为空则从field_options获取
        $raw_options = '';
        if (!empty($this->field['field_values'])) {
            $raw_options = $this->field['field_values'];
        } elseif (!empty($this->field['field_options'])) {
            $raw_options = $this->field['field_options'];
        }
        
        // 从字段配置中获取选项
        if (!empty($raw_options)) {
            // 如果是字符串，按行分割
            if (is_string($raw_options)) {
                $lines = explode("\n", $raw_options);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    
                    // 支持 "值|标签" 格式
                    if (strpos($line, '|') !== false) {
                        list($value, $label) = explode('|', $line, 2);
                        $options[trim($value)] = trim($label);
                    } else {
                        $options[$line] = $line;
                    }
                }
            } elseif (is_array($raw_options)) {
                $options = $raw_options;
            }
        }
        
        return $options;
    }
    
    /**
     * 验证字段值
     *
     * @param mixed $value 字段值
     * @return array 验证结果
     */
    public function validate($value) {
        $errors = [];
        $values = is_array($value) ? $value : [];
        
        // 必填验证
        if (($this->field['is_required'] ?? false) && empty($values)) {
            $errors[] = sprintf('%s 是必填字段', $this->field['field_title'] ?? '字段');
        }
        
        // 选项验证
        if (!empty($values)) {
            $options = $this->get_options();
            if (!empty($options)) {
                $valid_values = array_merge(array_keys($options), array_values($options));
                foreach ($values as $val) {
                    if (!in_array($val, $valid_values)) {
                        $errors[] = sprintf('%s 包含无效的选项值: %s', $this->field['field_title'] ?? '字段', $val);
                        break;
                    }
                }
            }
        }
        
        // 最大选择数量验证
        $max_selections = $this->field['max_selections'] ?? 0;
        if ($max_selections > 0 && count($values) > $max_selections) {
            $errors[] = sprintf('%s 最多只能选择 %d 个选项', $this->field['field_title'] ?? '字段', $max_selections);
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'value' => $values
        ];
    }
    
    /**
     * 格式化保存值
     *
     * @param mixed $value 原始值
     * @return string 格式化后的值（逗号分隔的字符串）
     */
    public function format_save_value($value) {
        if (is_array($value)) {
            return implode(',', array_filter($value));
        }
        return (string)$value;
    }
    
    /**
     * 格式化显示值
     *
     * @param mixed $value 原始值
     * @return string 格式化后的显示值
     */
    public function format_display_value($value) {
        $values = $this->get_selected_values();
        
        if (empty($values)) {
            return '';
        }
        
        $options = $this->get_options();
        $labels = [];
        
        foreach ($values as $val) {
            // 返回对应的标签，如果没有找到则返回原值
            $labels[] = $options[$val] ?? $val;
        }
        
        return implode(', ', $labels);
    }
    
    /**
     * 构建HTML属性字符串
     *
     * @param array $attributes 属性数组
     * @return string 属性字符串
     */
    protected function build_attributes($attributes) {
        $attr_strings = [];
        
        foreach ($attributes as $key => $value) {
            if (is_bool($value)) {
                if ($value) {
                    $attr_strings[] = todo_esc_attr($key);
                }
            } elseif ($value !== null && $value !== '') {
                $attr_strings[] = sprintf('%s="%s"', todo_esc_attr($key), todo_esc_attr($value));
            }
        }
        
        return implode(' ', $attr_strings);
    }
    
    /**
     * 获取字段类型名称
     *
     * @return string 字段类型名称
     */
    public static function get_type_name() {
        return '复选框';
    }
    
    /**
     * 获取字段类型描述
     *
     * @return string 字段类型描述
     */
    public static function get_type_description() {
        return '多选复选框组，用户可以选择多个选项';
    }
}
