<?php
/**
 * 字段类型基类
 *
 * 为所有字段类型提供基础功能和统一接口
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 确保钩子函数已加载
if (!function_exists('todo_do_action')) {
    require_once ABSPATH . 'core/functions/todo-hooks.php';
}
 
abstract class Field_Type_Base {
    /**
     * 字段配置
     *
     * @var array
     */
    protected $field = [];
    
    /**
     * 字段值
     *
     * @var mixed
     */
    protected $value = null;
    
    /**
     * 构造函数
     *
     * @param array $field 字段配置
     * @param mixed $value 字段值
     */
    public function __construct($field, $value = null) {
        $this->field = $field;
        $this->value = $value;
    }
    
    /**
     * 获取字段HTML
     *
     * @return string 字段HTML
     */
    public function get_html() {
        // 执行获取HTML前的钩子
        todo_do_action('todo_field_type_before_get_html', $this->field, $this->value);
        
        // 调用子类实现
        $html = $this->render_html();
        
        // 允许通过钩子修改HTML
        $html = todo_apply_filters('todo_field_type_html', $html, $this->field, $this->value);
        
        // 执行获取HTML后的钩子
        todo_do_action('todo_field_type_after_get_html', $html, $this->field, $this->value);
        
        return $html;
    }
    
    /**
     * 渲染字段HTML（子类需要实现）
     *
     * @return string 字段HTML
     */
    abstract protected function render_html();
    
    /**
     * 渲染字段（get_html的别名）
     *
     * @return string 字段HTML
     */
    public function render() {
        return $this->get_html();
    }
    
    /**
     * 验证字段值
     *
     * @param mixed $value 字段值
     * @return bool|string 验证通过返回true，失败返回错误信息
     */
    public function validate($value) {
        // 执行验证前的钩子
        todo_do_action('todo_field_type_before_validate', $this->field, $value);
        
        // 允许通过钩子修改验证值
        $value = todo_apply_filters('todo_field_type_validate_value', $value, $this->field);
        
        // 检查必填字段
        if (($this->field['is_required'] ?? false) && empty($value)) {
            $error = sprintf('字段 %s 不能为空', $this->field['field_name']);
            
            // 允许通过钩子修改错误信息
            $error = todo_apply_filters('todo_field_type_required_error', $error, $this->field, $value);
            
            return $error;
        }
        
        // 执行自定义验证
        $result = $this->custom_validate($value);
        
        // 允许通过钩子修改验证结果
        $result = todo_apply_filters('todo_field_type_validate_result', $result, $this->field, $value);
        
        // 执行验证后的钩子
        todo_do_action('todo_field_type_after_validate', $result, $this->field, $value);
        
        return $result;
    }
    
    /**
     * 自定义验证（子类可以重写）
     *
     * @param mixed $value 字段值
     * @return bool|string 验证通过返回true，失败返回错误信息
     */
    protected function custom_validate($value) {
        return true;
    }
    
    /**
     * 格式化字段值用于保存
     *
     * @param mixed $value 字段值
     * @return mixed 格式化后的值
     */
    public function format_value($value) {
        // 执行格式化前的钩子
        todo_do_action('todo_field_type_before_format', $this->field, $value);
        
        // 允许通过钩子修改格式化值
        $value = todo_apply_filters('todo_field_type_format_value', $value, $this->field);
        
        // 执行自定义格式化
        $formatted = $this->custom_format($value);
        
        // 允许通过钩子修改格式化结果
        $formatted = todo_apply_filters('todo_field_type_format_result', $formatted, $this->field, $value);
        
        // 执行格式化后的钩子
        todo_do_action('todo_field_type_after_format', $formatted, $this->field, $value);
        
        return $formatted;
    }
    
    /**
     * 自定义格式化（子类可以重写）
     *
     * @param mixed $value 字段值
     * @return mixed 格式化后的值
     */
    protected function custom_format($value) {
        return $value;
    }
    
    /**
     * 格式化字段值用于显示
     *
     * @param mixed $value 字段值
     * @return mixed 格式化后的值
     */
    public function format_value_for_display($value) {
        return $value;
    }
    
    /**
     * 获取字段配置表单HTML
     *
     * @return string 配置表单HTML
     */
    public function get_config_html() {
        $html = '';
        
        // 字段名称
        $html .= '<div class="form-group">';
        $html .= '<label>字段名称</label>';
        $html .= sprintf(
            '<input type="text" class="form-control" name="field_name" value="%s" required>',
            todo_esc_attr($this->field['field_name'] ?? '')
        );
        $html .= '</div>';
        
        // 字段标识
        $html .= '<div class="form-group">';
        $html .= '<label>字段标识</label>';
        $html .= sprintf(
            '<input type="text" class="form-control" name="field_key" value="%s" required pattern="[a-zA-Z_][a-zA-Z0-9_]*">',
            todo_esc_attr($this->field['field_key'] ?? '')
        );
        $html .= '<small class="form-text text-muted">只能包含字母、数字和下划线，必须以字母或下划线开头</small>';
        $html .= '</div>';
        
        // 默认值
        $html .= '<div class="form-group">';
        $html .= '<label>默认值</label>';
        $html .= sprintf(
            '<input type="text" class="form-control" name="field_default" value="%s">',
            todo_esc_attr($this->field['field_default'] ?? '')
        );
        $html .= '</div>';
        
        // 是否必填
        $html .= '<div class="form-group">';
        $html .= '<div class="form-check">';
        $html .= sprintf(
            '<input type="checkbox" class="form-check-input" name="is_required" value="1" %s>',
            todo_checked($this->field['is_required'] ?? false, true, false)
        );
        $html .= '<label class="form-check-label">必填</label>';
        $html .= '</div>';
        $html .= '</div>';
        
        // 是否显示
        $html .= '<div class="form-group">';
        $html .= '<div class="form-check">';
        $html .= sprintf(
            '<input type="checkbox" class="form-check-input" name="is_visible" value="1" %s>',
            todo_checked($this->field['is_visible'] ?? true, true, false)
        );
        $html .= '<label class="form-check-label">显示</label>';
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
        // 验证字段名称
        if (empty($config['field_name'])) {
            return '字段名称不能为空';
        }
        
        // 验证字段标识
        if (empty($config['field_key'])) {
            return '字段标识不能为空';
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $config['field_key'])) {
            return '字段标识只能包含字母、数字和下划线，必须以字母或下划线开头';
        }
        
        return true;
    }
    
    /**
     * 获取字段类型名称
     *
     * @return string 字段类型名称
     */
    abstract public static function get_type_name();
    
    /**
     * 获取字段类型描述
     *
     * @return string 字段类型描述
     */
    abstract public static function get_type_description();
    
    /**
     * 获取字段类型图标
     *
     * @return string 字段类型图标HTML
     */
    public static function get_type_icon() {
        return '<i class="custom-icon"></i>';
    }
    
    /**
     * 获取字段搜索条件HTML
     *
     * @return string 搜索条件HTML
     */
    public function get_search_html() {
        return sprintf(
            '<input type="text" class="form-control" name="search[%s]" value="%s">',
            todo_esc_attr($this->field['field_key']),
            todo_esc_attr(isset($_GET['search'][$this->field['field_key']]) ? $_GET['search'][$this->field['field_key']] : '')
        );
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
        
        return [
            "meta_key = ? AND meta_value LIKE ?",
            [$this->field['field_key'], '%' . $value . '%']
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
            "meta_key = ? ORDER BY meta_value " . $order,
            [$this->field['field_key']]
        ];
    }
    
    /**
     * 包装字段HTML
     *
     * @param string $html 字段HTML
     * @return string 包装后的HTML
     */
    protected function wrap_field($html) {
        $output = '';
        
        // 添加标签
        if (!empty($this->field['field_name'])) {
            $output .= sprintf(
                '<label>%s</label>',
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
        
        return $output;
    }
}