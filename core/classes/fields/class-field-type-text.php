<?php
/**
 * 文本字段类型
 *
 * @package TodoCMS
 * @version 1.0.0
 */

 
class Field_Type_Text extends Field_Type_Base {
    /**
     * 渲染字段HTML
     *
     * @return string 字段HTML
     */
    protected function render_html() {
        $attributes = [
            'type' => 'text',
            'class' => 'form-control',
            'name' => $this->field['field_key'],
            'id' => 'field-' . $this->field['field_key'],
            'value' => todo_esc_attr($this->value ?? $this->field['field_default'] ?? ''),
        ];
        
        // 添加占位符
        if (!empty($this->field['placeholder'])) {
            $attributes['placeholder'] = todo_esc_attr($this->field['placeholder']);
        }
        
        // 添加最大长度
        if (!empty($this->field['max_length'])) {
            $attributes['maxlength'] = intval($this->field['max_length']);
        }
        
        if ($this->field['is_required'] ?? false) {
            $attributes['required'] = 'required';
        }
        
        // 允许通过钩子修改属性
        $attributes = todo_apply_filters('todo_field_type_text_attributes', $attributes, $this->field, $this->value);
        
        $html = sprintf('<input %s>', $this->build_attributes($attributes));
        
        return $this->wrap_field($html);
    }
    
    /**
     * 构建HTML属性字符串
     *
     * @param array $attributes 属性数组
     * @return string 属性字符串
     */
    protected function build_attributes($attributes) {
        $html = '';
        
        foreach ($attributes as $key => $value) {
            if ($value === true) {
                $html .= ' ' . $key;
            } elseif ($value !== false && $value !== null) {
                $html .= ' ' . $key . '="' . $value . '"';
            }
        }
        
        return $html;
    }
    
    /**
     * 包装字段HTML
     *
     * @param string $html 字段HTML
     * @return string 包装后的HTML
     */
    protected function wrap_field($html) {
        $output = '<div class="form-group">';
        
        // 添加标签
        if (!empty($this->field['field_name'])) {
            $output .= sprintf(
                '<label for="field-%s">%s%s</label>',
                todo_esc_attr($this->field['field_key']),
                todo_esc_html($this->field['field_name']),
                ($this->field['is_required'] ?? false) ? ' <span class="required">*</span>' : ''
            );
        }
        
        $output .= $html;
        
        // 添加描述
        if (!empty($this->field['field_description'])) {
            $output .= sprintf(
                '<p class="description">%s</p>',
                todo_esc_html($this->field['field_description'])
            );
        }
        
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * 自定义验证
     *
     * @param mixed $value 字段值
     * @return bool|string 验证通过返回true，失败返回错误信息
     */
    protected function custom_validate($value) {
        // 验证最大长度
        $max_length = $this->field['max_length'] ?? 0;
        if (!empty($max_length) && mb_strlen($value) > $max_length) {
            $error = sprintf(
                '字段 %s 的长度不能超过 %d 个字符',
                $this->field['field_name'],
                $max_length
            );
            
            // 允许通过钩子修改错误信息
            $error = todo_apply_filters('todo_field_type_text_max_length_error', $error, $this->field, $value, $max_length);
            
            return $error;
        }
        
        // 验证最小长度
        $min_length = $this->field['min_length'] ?? 0;
        if (!empty($min_length) && mb_strlen($value) < $min_length) {
            $error = sprintf(
                '字段 %s 的长度不能少于 %d 个字符',
                $this->field['field_name'],
                $min_length
            );
            
            // 允许通过钩子修改错误信息
            $error = todo_apply_filters('todo_field_type_text_min_length_error', $error, $this->field, $value, $min_length);
            
            return $error;
        }
        
        return true;
    }
    
    /**
     * 获取字段配置表单HTML
     *
     * @return string 配置表单HTML
     */
    public function get_config_html() {
        $html = parent::get_config_html();
        
        // 最大长度
        $html .= '<div class="form-group">';
        $html .= '<label>最大长度</label>';
        $html .= sprintf(
            '<input type="number" class="form-control" name="max_length" value="%d" min="0">',
            intval($this->field['max_length'] ?? 0)
        );
        $html .= '<small class="form-text text-muted">0 表示不限制</small>';
        $html .= '</div>';
        
        // 占位符
        $html .= '<div class="form-group">';
        $html .= '<label>占位符</label>';
        $html .= sprintf(
            '<input type="text" class="form-control" name="placeholder" value="%s">',
            todo_esc_attr($this->field['placeholder'] ?? '')
        );
        $html .= '</div>';
        
        // 字段描述
        $html .= '<div class="form-group">';
        $html .= '<label>字段描述</label>';
        $html .= sprintf(
            '<textarea class="form-control" name="field_description" rows="3">%s</textarea>',
            todo_esc_html($this->field['field_description'] ?? '')
        );
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * 验证字段配置
     *
     * @param array $config 字段配置
     * @return bool|string 验证通过返回true，失败返回错误信息
     */
    public function validate_config($config) {
        $result = parent::validate_config($config);
        if ($result !== true) {
            return $result;
        }
        
        // 验证最大长度
        if (isset($config['max_length']) && $config['max_length'] < 0) {
            return '最大长度不能小于0';
        }
        
        return true;
    }
    
    /**
     * 获取字段类型名称
     *
     * @return string 字段类型名称
     */
    public static function get_type_name() {
        return '单行文本';
    }
    
    /**
     * 获取字段类型描述
     *
     * @return string 字段类型描述
     */
    public static function get_type_description() {
        return '适用于输入简短的文本内容';
    }
    
    /**
     * 获取字段类型图标
     *
     * @return string 字段类型图标HTML
     */
    public static function get_type_icon() {
        return '<i class="custom-icon"></i>';
    }
}