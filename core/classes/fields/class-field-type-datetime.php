<?php
/**
 * 日期时间字段类型
 *
 * @package TodoCMS
 * @version 1.0.0
 */

 
class Field_Type_Datetime extends Field_Type_Text {
    /**
     * 获取字段HTML
     *
     * @return string 字段HTML
     */
    protected function render_html() {
        $show_time = $this->field['show_time'] ?? false;
        $type = $show_time ? 'datetime-local' : 'date';
        $format = $show_time ? 'Y-m-d\TH:i' : 'Y-m-d';
        
        $attributes = [
            'type' => $type,
            'class' => 'form-control',
            'name' => $this->field['field_key'],
            'id' => 'field-' . $this->field['field_key'],
            'value' => $this->value ? date($format, strtotime($this->value)) : ($this->field['field_default'] ?? '')
        ];
        
        if ($this->field['is_required'] ?? false) {
            $attributes['required'] = 'required';
        }
        
        if (isset($this->field['min_date'])) {
            $attributes['min'] = date($format, strtotime($this->field['min_date']));
        }
        
        if (isset($this->field['max_date'])) {
            $attributes['max'] = date($format, strtotime($this->field['max_date']));
        }
        
        $html = sprintf('<input %s>', $this->build_attributes($attributes));
        
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
        
        // 获取是否显示时间的配置
        $show_time = $this->field['show_time'] ?? false;
        
        // 验证日期格式
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return sprintf('字段 %s 的日期格式无效', $this->field['field_name']);
        }
        
        // 验证最小日期
        if (isset($this->field['min_date'])) {
            $min_timestamp = strtotime($this->field['min_date']);
            if ($timestamp < $min_timestamp) {
                return sprintf(
                    '字段 %s 不能早于 %s',
                    $this->field['field_name'],
                    date($show_time ? 'Y-m-d H:i' : 'Y-m-d', $min_timestamp)
                );
            }
        }
        
        // 验证最大日期
        if (isset($this->field['max_date'])) {
            $max_timestamp = strtotime($this->field['max_date']);
            if ($timestamp > $max_timestamp) {
                return sprintf(
                    '字段 %s 不能晚于 %s',
                    $this->field['field_name'],
                    date($show_time ? 'Y-m-d H:i' : 'Y-m-d', $max_timestamp)
                );
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
        if ($value === '') {
            return '';
        }
        
        $timestamp = strtotime($value);
        return date('Y-m-d H:i:s', $timestamp);
    }
    
    /**
     * 格式化字段值用于显示
     *
     * @param mixed $value 字段值
     * @return mixed 格式化后的值
     */
    public function format_value_for_display($value) {
        if ($value === '') {
            return '';
        }
        
        $timestamp = strtotime($value);
        $show_time = $this->field['show_time'] ?? false;
        return date($show_time ? 'Y-m-d H:i' : 'Y-m-d', $timestamp);
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
        
        // 是否显示时间
        $html .= '<div class="form-group">';
        $html .= '<div class="form-check">';
        $html .= sprintf(
            '<input type="checkbox" class="form-check-input" name="show_time" value="1" %s>',
            todo_checked($this->field['show_time'] ?? false, true, false)
        );
        $html .= '<label class="form-check-label">显示时间</label>';
        $html .= '</div>';
        $html .= '</div>';
        
        // 最小日期
        $html .= '<div class="form-group">';
        $html .= '<label>最小日期</label>';
        $html .= sprintf(
            '<input type="date" class="form-control" name="min_date" value="%s">',
            todo_esc_attr($this->field['min_date'] ?? '')
        );
        $html .= '</div>';
        
        // 最大日期
        $html .= '<div class="form-group">';
        $html .= '<label>最大日期</label>';
        $html .= sprintf(
            '<input type="date" class="form-control" name="max_date" value="%s">',
            todo_esc_attr($this->field['max_date'] ?? '')
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
        
        // 验证最小日期和最大日期
        if (!empty($config['min_date']) && !empty($config['max_date'])) {
            $min_timestamp = strtotime($config['min_date']);
            $max_timestamp = strtotime($config['max_date']);
            if ($min_timestamp && $max_timestamp && $min_timestamp > $max_timestamp) {
                return '最小日期不能大于最大日期';
            }
        }
        
        return true;
    }
    
    /**
     * 获取字段类型名称
     *
     * @return string 字段类型名称
     */
    public static function get_type_name() {
        return '日期时间';
    }
    
    /**
     * 获取字段类型描述
     *
     * @return string 字段类型描述
     */
    public static function get_type_description() {
        return '适用于选择日期和时间';
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
        
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return false;
        }
        
        $date = date('Y-m-d', $timestamp);
        return [
            "meta_key = ? AND DATE(meta_value) = ?",
            [$this->field['field_key'], $date]
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
            "meta_key = ? ORDER BY CAST(meta_value AS DATETIME) " . $order,
            [$this->field['field_key']]
        ];
    }
}