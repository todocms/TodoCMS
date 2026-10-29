<?php
/**
 * 多行文本字段类型
 *
 * @package TodoCMS
 * @version 1.0.0
 */

 
class Field_Type_Textarea extends Field_Type_Text {
    /**
     * 获取字段HTML
     *
     * @return string 字段HTML
     */
    protected function render_html() {
        $attributes = [
            'class' => 'form-control',
            'name' => $this->field['field_key'],
            'id' => 'field-' . $this->field['field_key'],
            'rows' => $this->field['rows'] ?? 5
        ];
        
        if ($this->field['is_required'] ?? false) {
            $attributes['required'] = 'required';
        }
        
        if (!empty($this->field['placeholder'])) {
            $attributes['placeholder'] = $this->field['placeholder'];
        }
        
        $val = $this->value ?? $this->field['field_default'] ?? '';
        if (!empty($this->field['use_editor'])) {
            if (function_exists('todo_convert_content_urls_to_relative')) {
                $val = todo_convert_content_urls_to_relative($val);
            }
            if (function_exists('todo_normalize_content_root_relative_urls')) {
                $val = todo_normalize_content_root_relative_urls($val);
            }
        }

        $html = sprintf(
            '<textarea %s>%s</textarea>',
            $this->build_attributes($attributes),
            todo_esc_html($val)
        );
        
        return $this->wrap_field($html);
    }
    
    /**
     * 获取字段配置表单HTML
     *
     * @return string 配置表单HTML
     */
    public function get_config_html() {
        $html = parent::get_config_html();
        
        // 移除最大长度字段
        $html = preg_replace('/<div class="form-group">.*?最大长度.*?<\/div>/s', '', $html);
        
        // 行数
        $html .= '<div class="form-group">';
        $html .= '<label>行数</label>';
        $html .= sprintf(
            '<input type="number" class="form-control" name="rows" value="%d" min="2">',
            intval($this->field['rows'] ?? 5)
        );
        $html .= '</div>';
        
        // 是否使用富文本编辑器
        $html .= '<div class="form-group">';
        $html .= '<div class="form-check">';
        $html .= sprintf(
            '<input type="checkbox" class="form-check-input" name="use_editor" value="1" %s>',
            todo_checked($this->field['use_editor'] ?? false, true, false)
        );
        $html .= '<label class="form-check-label">使用富文本编辑器</label>';
        $html .= '</div>';
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
        
        // 验证行数
        if (isset($config['rows']) && $config['rows'] < 2) {
            return '行数不能小于2';
        }
        
        return true;
    }
    
    /**
     * 获取字段类型名称
     *
     * @return string 字段类型名称
     */
    public static function get_type_name() {
        return '多行文本';
    }
    
    /**
     * 获取字段类型描述
     *
     * @return string 字段类型描述
     */
    public static function get_type_description() {
        return '适用于输入较长的文本内容，支持富文本编辑器';
    }
    
    /**
     * 获取字段类型图标
     *
     * @return string 字段类型图标HTML
     */
    public static function get_type_icon() {
        return '<i class="custom-icon"></i>';
    }
    
    /**
     * 获取字段搜索SQL
     *
     * @param string $value 搜索值
     * @return array|false 成功返回[SQL语句, 参数数组]，失败返回false
     */
    public function get_search_sql($value) {
        if ($value === '') {
            return false;
        }
        
        // 对富文本内容进行HTML标签过滤
        $use_editor = $this->field['use_editor'] ?? false;
        if ($use_editor) {
            return [
                "meta_key = ? AND LOWER(REPLACE(meta_value, '<[^>]+>', '')) LIKE LOWER(?)",
                [$this->field['field_key'], '%' . $value . '%']
            ];
        }
        
        return parent::get_search_sql($value);
    }
    
    /**
     * 格式化字段值用于显示
     *
     * @param mixed $value 字段值
     * @return mixed 格式化后的值
     */
    public function format_value_for_display($value) {
        $use_editor = $this->field['use_editor'] ?? false;
        if ($use_editor) {
            // 富文本内容不需要转义
            return $value;
        }
        
        // 普通文本需要转义并保留换行
        return nl2br(todo_esc_html($value));
    }
}