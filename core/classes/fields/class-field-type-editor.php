<?php
/**
 * 编辑器字段类型 (UEditor)
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class Field_Type_Editor extends Field_Type_Textarea {
    /**
     * 获取字段HTML
     *
     * @return string 字段HTML
     */
    protected function render_html() {
        $attributes = [
            'class' => 'form-control todo-editor',
            'name' => $this->field['field_key'],
            'id' => 'field-' . $this->field['field_key'],
            'rows' => $this->field['rows'] ?? 15
        ];
        
        if ($this->field['is_required'] ?? false) {
            $attributes['required'] = 'required';
        }
        
        $placeholder = $this->field['placeholder'] ?? '';
        if (!empty($placeholder)) {
            $attributes['placeholder'] = $placeholder;
        }
        
        // 允许通过钩子修改属性
        $attributes = todo_apply_filters('todo_field_type_editor_attributes', $attributes, $this->field, $this->value);
        
        $val = $this->value ?? $this->field['field_default'] ?? '';
        if (function_exists('todo_convert_content_urls_to_absolute')) {
            $val = todo_convert_content_urls_to_absolute($val);
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
        // 使用父类的配置HTML，但移除“使用富文本编辑器”选项，因为此字段类型本身就是编辑器
        $html = parent::get_config_html();
        $html = preg_replace('/<div class="form-group">.*?使用富文本编辑器.*?<\/div>/s', '', $html);
        
        return $html;
    }
    
    /**
     * 自定义格式化（保存入库前规范化相对路径）
     *
     * @param mixed $value 字段值
     * @return mixed 格式化后的值
     */
    protected function custom_format($value) {
        if (is_string($value) && $value !== '') {
            if (function_exists('todo_convert_content_urls_to_relative')) {
                $value = todo_convert_content_urls_to_relative($value);
            }
            if (function_exists('todo_media_url_to_relative')) {
                $value = todo_media_url_to_relative($value);
            }
        }
        return $value;
    }

    /**
     * 格式化字段值用于显示
     *
     * @param mixed $value 字段值
     * @return mixed 格式化后的值
     */
    public function format_value_for_display($value) {
        // 编辑器内容不需要转义
        return $value;
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
        
        // 对HTML内容进行标签过滤后搜索
        return [
            "meta_key = ? AND LOWER(REPLACE(meta_value, '<[^>]+>', '')) LIKE LOWER(?)",
            [$this->field['field_key'], '%' . $value . '%']
        ];
    }
    
    /**
     * 获取字段类型名称
     *
     * @return string 字段类型名称
     */
    public static function get_type_name() {
        return '编辑器';
    }
    
    /**
     * 获取字段类型描述
     *
     * @return string 字段类型描述
     */
    public static function get_type_description() {
        return '使用系统内置的 UEditor 富文本编辑器，支持复杂格式和媒体插入';
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
