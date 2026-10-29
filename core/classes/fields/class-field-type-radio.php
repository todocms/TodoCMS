<?php
/**
 * 单选按钮字段类型
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class Field_Type_Radio extends Field_Type_Base {
    /**
     * 渲染字段HTML
     *
     * @return string 字段HTML
     */
    protected function render_html() {
        $options = $this->get_options();
        $value = $this->value ?? $this->field['field_default'] ?? '';
        
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
                '<label class="form-check-label" for="%s"><input type="radio" class="form-check-input" name="%s" id="%s" value="%s" %s %s><span>%s</span></label>',
                todo_esc_attr($id),
                todo_esc_attr($this->field['field_key']),
                todo_esc_attr($id),
                todo_esc_attr($option_value),
                todo_checked($value, $option_value, false),
                ($this->field['is_required'] ?? false) ? 'required' : '',
                todo_esc_html($option_label)
            );
        }

        $html .= '</div>';
        $html .= '<p class="description">' . todo_esc_html($this->field['field_description'] ?? '') . '</p>';
        $html .= '</div>';
        
        return $html;
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
        
        // 获取字段标题用于错误消息
        $field_title = $this->field['field_title'] ?? $this->field['field_name'] ?? '字段';
        
        // 必填验证
        if (($this->field['is_required'] ?? false) && empty($value)) {
            $errors[] = sprintf('%s 是必填字段', $field_title);
        }
        
        // 选项验证
        if (!empty($value)) {
            $options = $this->get_options();
            if (!empty($options) && !array_key_exists($value, $options) && !in_array($value, $options)) {
                $errors[] = sprintf('%s 的值不在允许的选项中', $field_title);
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'value' => $value
        ];
    }
    
    /**
     * 格式化保存值
     *
     * @param mixed $value 原始值
     * @return mixed 格式化后的值
     */
    public function format_save_value($value) {
        return trim($value);
    }
    
    /**
     * 格式化显示值
     *
     * @param mixed $value 原始值
     * @return string 格式化后的显示值
     */
    public function format_display_value($value) {
        if (empty($value)) {
            return '';
        }
        
        $options = $this->get_options();
        
        // 返回对应的标签，如果没有找到则返回原值
        return $options[$value] ?? $value;
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
        return '单选按钮';
    }
    
    /**
     * 获取字段类型描述
     *
     * @return string 字段类型描述
     */
    public static function get_type_description() {
        return '单选按钮组，用户只能选择一个选项';
    }
}
