<?php
/**
 * 数字字段类型
 *
 * @package TodoCMS
 * @version 1.0.0
 */

 
class Field_Type_Number extends Field_Type_Text {
    /**
     * 获取字段HTML
     *
     * @return string 字段HTML
     */
    protected function render_html() {
        $is_decimal = $this->field['decimal'] ?? false;
        $attributes = [
            'type' => 'number',
            'class' => 'form-control',
            'name' => $this->field['field_key'],
            'id' => 'field-' . $this->field['field_key'],
            'value' => todo_esc_attr($this->value ?? $this->field['field_default'] ?? ''),
            'step' => $is_decimal ? 'any' : '1'
        ];
        
        if ($this->field['is_required'] ?? false) {
            $attributes['required'] = 'required';
        }
        
        if (isset($this->field['min'])) {
            $attributes['min'] = $this->field['min'];
        }
        
        if (isset($this->field['max'])) {
            $attributes['max'] = $this->field['max'];
        }
        
        $html = sprintf('<input %s>', $this->build_attributes($attributes));
        
        // 添加单位
        if (!empty($this->field['unit'])) {
            $html = sprintf(
                '<div class="input-group">%s<div class="input-group-append"><span class="input-group-text">%s</span></div></div>',
                $html,
                todo_esc_html($this->field['unit'])
            );
        }
        
        return $this->wrap_field($html);
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
        
        if ($value === '') {
            return true;
        }
        
        // 验证是否为数字
        if (!is_numeric($value)) {
            return sprintf('字段 %s 必须是数字', $this->field['field_name']);
        }
        
        // 验证整数
        $is_decimal = $this->field['decimal'] ?? false;
        if (!$is_decimal && !ctype_digit((string)$value)) {
            return sprintf('字段 %s 必须是整数', $this->field['field_name']);
        }
        
        // 验证最小值
        if (isset($this->field['min']) && $value < $this->field['min']) {
            return sprintf(
                '字段 %s 不能小于 %s',
                $this->field['field_name'],
                $this->field['min']
            );
        }
        
        // 验证最大值
        if (isset($this->field['max']) && $value > $this->field['max']) {
            return sprintf(
                '字段 %s 不能大于 %s',
                $this->field['field_name'],
                $this->field['max']
            );
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
        if ($value === '') {
            return '';
        }
        
        $is_decimal = $this->field['decimal'] ?? false;
        return $is_decimal ? (float)$value : (int)$value;
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
        
        // 是否允许小数
        $html .= '<div class="form-group">';
        $html .= '<div class="form-check">';
        $html .= sprintf(
            '<input type="checkbox" class="form-check-input" name="decimal" value="1" %s>',
            todo_checked($this->field['decimal'] ?? false, true, false)
        );
        $html .= '<label class="form-check-label">允许小数</label>';
        $html .= '</div>';
        $html .= '</div>';
        
        // 最小值
        $html .= '<div class="form-group">';
        $html .= '<label>最小值</label>';
        $html .= sprintf(
            '<input type="number" class="form-control" name="min" value="%s" step="any">',
            todo_esc_attr($this->field['min'] ?? '')
        );
        $html .= '</div>';
        
        // 最大值
        $html .= '<div class="form-group">';
        $html .= '<label>最大值</label>';
        $html .= sprintf(
            '<input type="number" class="form-control" name="max" value="%s" step="any">',
            todo_esc_attr($this->field['max'] ?? '')
        );
        $html .= '</div>';
        
        // 单位
        $html .= '<div class="form-group">';
        $html .= '<label>单位</label>';
        $html .= sprintf(
            '<input type="text" class="form-control" name="unit" value="%s">',
            todo_esc_attr($this->field['unit'] ?? '')
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
        
        // 验证最小值和最大值
        if (isset($config['min']) && isset($config['max']) && $config['min'] > $config['max']) {
            return '最小值不能大于最大值';
        }
        
        return true;
    }
    
    /**
     * 获取字段类型名称
     *
     * @return string 字段类型名称
     */
    public static function get_type_name() {
        return '数字';
    }
    
    /**
     * 获取字段类型描述
     *
     * @return string 字段类型描述
     */
    public static function get_type_description() {
        return '适用于输入数字，支持整数和小数';
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
        
        if (!is_numeric($value)) {
            return false;
        }
        
        return [
            "meta_key = ? AND meta_value = ?",
            [$this->field['field_key'], $value]
        ];
    }
    
    /**
     * 获取字段排序SQL
     *
     * @param string $order 排序方向(ASC|DESC)
     * @return array|false 成功返回[SQL语句, 参数数组]，失败返回false
     */
    public function get_order_sql($order) {
        return [
            "meta_key = ? ORDER BY CAST(meta_value AS " . (($this->field['decimal'] ?? false) ? 'DECIMAL' : 'SIGNED') . ") " . $order,
            [$this->field['field_key']]
        ];
    }
}