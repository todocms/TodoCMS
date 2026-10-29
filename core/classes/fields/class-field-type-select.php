<?php
/**
 * 选择字段类型
 *
 * @package TodoCMS
 * @version 1.0.0
 */

 
class Field_Type_Select extends Field_Type_Base {
    /**
     * 渲染字段HTML
     *
     * @return string 字段HTML
     */
    protected function render_html() {
        $options = $this->get_options();
        $value = $this->value ?? $this->field['field_default'] ?? '';
        
        $multiple = $this->field['multiple'] ?? false;
        if ($multiple) {
            // 多选
            $attributes = [
                'class' => 'form-control',
                'name' => $this->field['field_key'] . '[]',
                'id' => 'field-' . $this->field['field_key'],
                'multiple' => 'multiple',
                'size' => min(count($options), 5)
            ];
            
            if ($this->field['is_required'] ?? false) {
                $attributes['required'] = 'required';
            }
            
            $html = '<select ' . $this->build_attributes($attributes) . '>';
            
            foreach ($options as $opt) {
                $opt_value = is_array($opt) && isset($opt['value']) ? $opt['value'] : (string)$opt;
                $opt_label = is_array($opt) && isset($opt['label']) ? $opt['label'] : $opt_value;
                $selected = is_array($value) && in_array($opt_value, $value, true);
                $html .= sprintf(
                    '<option value="%s" %s>%s</option>',
                    todo_esc_attr($opt_value),
                    todo_selected($selected, true, false),
                    todo_esc_html($opt_label)
                );
            }
            
            $html .= '</select>';
        } else {
            // 单选
            $display_type = $this->field['display_type'] ?? 'select';
        if ($display_type === 'radio') {
                // 单选按钮
                $html = '';
                foreach ($options as $opt) {
                    $opt_value = is_array($opt) && isset($opt['value']) ? $opt['value'] : (string)$opt;
                    $opt_label = is_array($opt) && isset($opt['label']) ? $opt['label'] : $opt_value;
                    $id = 'field-' . $this->field['field_key'] . '-' . todo_sanitize_key($opt_value);
                    $html .= '<div class="form-check">';
                    $html .= sprintf(
                        '<input type="radio" class="form-check-input" name="%s" id="%s" value="%s" %s %s>',
                        todo_esc_attr($this->field['field_key']),
                        todo_esc_attr($id),
                        todo_esc_attr($opt_value),
                        todo_checked($value, $opt_value, false),
                        ($this->field['is_required'] ?? false) ? 'required' : ''
                    );
                    $html .= sprintf(
                        '<label class="form-check-label" for="%s">%s</label>',
                        todo_esc_attr($id),
                        todo_esc_html($opt_label)
                    );
                    $html .= '</div>';
                }
            } else {
                // 下拉菜单
                $attributes = [
                    'class' => 'form-control',
                    'name' => $this->field['field_key'],
                    'id' => 'field-' . $this->field['field_key']
                ];
                
                if ($this->field['is_required'] ?? false) {
                    $attributes['required'] = 'required';
                }
                
                $html = '<select ' . $this->build_attributes($attributes) . '>';
                
                if (!($this->field['is_required'] ?? false)) {
                    $html .= '<option value="">请选择</option>';
                }
                
                foreach ($options as $opt) {
                    $opt_value = is_array($opt) && isset($opt['value']) ? $opt['value'] : (string)$opt;
                    $opt_label = is_array($opt) && isset($opt['label']) ? $opt['label'] : $opt_value;
                    $html .= sprintf(
                        '<option value="%s" %s>%s</option>',
                        todo_esc_attr($opt_value),
                        todo_selected($value, $opt_value, false),
                        todo_esc_html($opt_label)
                    );
                }
                
                $html .= '</select>';
            }
        }
        
        return $this->wrap_field($html);
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
        
        if (!empty($raw_options)) {
            $lines = preg_split('/\r\n|\r|\n/', $raw_options);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') { continue; }
                // 支持 值|标签 格式；若无分隔符则值=标签
                if (strpos($line, '|') !== false) {
                    list($v, $l) = array_map('trim', explode('|', $line, 2));
                    $options[] = ['value' => $v, 'label' => $l !== '' ? $l : $v];
                } else {
                    $options[] = ['value' => $line, 'label' => $line];
                }
            }
        }
        
        return $options;
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
     * 验证字段值
     *
     * @param mixed $value 字段值
     * @return bool|string 验证通过返回true，失败返回错误信息
     */
    public function validate($value) {
        $result = parent::validate($value);
        if ($result !== true) {
            return $result;
        }
        
        if ($value === '' || $value === null || $value === []) {
            return true;
        }
        
        $options = $this->get_options();
        
        $multiple = $this->field['multiple'] ?? false;
        if ($multiple) {
            // 多选验证
            if (!is_array($value)) {
                return sprintf('字段 %s 的值无效', $this->field['field_name']);
            }
            
            foreach ($value as $v) {
                if (!in_array($v, $options)) {
                    return sprintf('字段 %s 的值无效', $this->field['field_name']);
                }
            }
        } else {
            // 单选验证
            if (!in_array($value, $options)) {
                return sprintf('字段 %s 的值无效', $this->field['field_name']);
            }
        }
        
        return true;
    }
    
    /**
     * 格式化字段值用于保存
     *
     * @param mixed $value 字段值
     * @return mixed 格式化后的值
     */
    public function format_value($value) {
        $multiple = $this->field['multiple'] ?? false;
        if ($multiple) {
            return is_array($value) ? implode("\n", $value) : '';
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
        $multiple = $this->field['multiple'] ?? false;
        if ($multiple) {
            return $value ? explode("\n", $value) : [];
        }
        
        return $value;
    }
    
    /**
     * 获取字段配置表单HTML
     *
     * @return string 配置表单HTML
     */
    public function get_config_html() {
        $html = parent::get_config_html();
        
        // 选项值
        $html .= '<div class="form-group">';
        $html .= '<label>选项值</label>';
        $html .= sprintf(
            '<textarea class="form-control" name="field_values" rows="5" required>%s</textarea>',
            todo_esc_textarea($this->field['field_values'] ?? '')
        );
        $html .= '<small class="form-text text-muted">每行一个选项</small>';
        $html .= '</div>';
        
        // 是否多选
        $html .= '<div class="form-group">';
        $html .= '<div class="form-check">';
        $html .= sprintf(
            '<input type="checkbox" class="form-check-input" name="multiple" value="1" %s>',
            todo_checked($this->field['multiple'] ?? false, true, false)
        );
        $html .= '<label class="form-check-label">允许多选</label>';
        $html .= '</div>';
        $html .= '</div>';
        
        // 显示类型
        $html .= '<div class="form-group">';
        $html .= '<label>显示类型</label>';
        $html .= '<select class="form-control" name="display_type">';
        $html .= sprintf(
            '<option value="select" %s>下拉菜单</option>',
            todo_selected($this->field['display_type'] ?? 'select', 'select', false)
        );
        $html .= sprintf(
            '<option value="radio" %s>单选按钮</option>',
            todo_selected($this->field['display_type'] ?? 'select', 'radio', false)
        );
        $html .= '</select>';
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
        
        // 验证选项值
        if (empty($config['field_values'])) {
            return '选项值不能为空';
        }
        
        return true;
    }
    
    /**
     * 获取字段类型名称
     *
     * @return string 字段类型名称
     */
    public static function get_type_name() {
        return '选择';
    }
    
    /**
     * 获取字段类型描述
     *
     * @return string 字段类型描述
     */
    public static function get_type_description() {
        return '适用于从预设选项中选择，支持单选和多选';
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
        
        $multiple = $this->field['multiple'] ?? false;
        if ($multiple) {
            return [
                "meta_key = ? AND (meta_value = ? OR meta_value LIKE ? OR meta_value LIKE ? OR meta_value LIKE ?)",
                [
                    $this->field['field_key'],
                    $value,
                    $value . "\n%",
                    "%\n" . $value . "\n%",
                    "%\n" . $value
                ]
            ];
        }
        
        return parent::get_search_sql($value);
    }
}