<?php
/**
 * Todo小部件基类
 * 
 * 提供小部件系统的基础功能
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

abstract class TODO_Widget {
    
    /**
     * 小部件ID
     * 
     * @var string
     */
    protected $id_base;
    
    /**
     * 小部件名称
     * 
     * @var string
     */
    protected $name;
    
    /**
     * 小部件选项
     * 
     * @var array
     */
    protected $widget_options;
    
    /**
     * 控制选项
     * 
     * @var array
     */
    protected $control_options;
    
    /**
     * 注册的小部件
     * 
     * @var array
     */
    private static $widgets = [];
    
    /**
     * 构造函数
     * 
     * @param string $id_base 小部件ID基础
     * @param string $name 小部件名称
     * @param array $widget_options 小部件选项
     * @param array $control_options 控制选项
     */
    public function __construct($id_base, $name, $widget_options = array(), $control_options = array()) {
        $this->id_base = $id_base;
        $this->name = $name;
        $this->widget_options = todo_parse_args($widget_options, array(
            'classname' => '',
            'description' => '',
            'customize_selective_refresh' => false
        ));
        $this->control_options = todo_parse_args($control_options, array(
            'width' => 250,
            'height' => 200
        ));
        
        // 注册小部件
        self::$widgets[$id_base] = $this;
    }
    
    /**
     * 输出小部件内容
     * 
     * @param array $args 显示参数
     * @param array $instance 小部件实例数据
     */
    abstract public function widget($args, $instance);
    
    /**
     * 输出小部件设置表单
     * 
     * @param array $instance 小部件实例数据
     * @return string|void
     */
    public function form($instance) {
        echo '<p>' . __('此小部件没有设置选项', 'todocms') . '</p>';
    }
    
    /**
     * 更新小部件设置
     * 
     * @param array $new_instance 新的实例数据
     * @param array $old_instance 旧的实例数据
     * @return array 更新后的实例数据
     */
    public function update($new_instance, $old_instance) {
        return $new_instance;
    }
    
    /**
     * 获取小部件ID
     * 
     * @return string
     */
    public function get_id() {
        return $this->id_base;
    }
    
    /**
     * 获取小部件名称
     * 
     * @return string
     */
    public function get_name() {
        return $this->name;
    }
    
    /**
     * 获取小部件选项
     * 
     * @return array
     */
    public function get_widget_options() {
        return $this->widget_options;
    }
    
    /**
     * 获取控制选项
     * 
     * @return array
     */
    public function get_control_options() {
        return $this->control_options;
    }
    
    /**
     * 获取所有注册的小部件
     * 
     * @return array
     */
    public static function get_widgets() {
        return self::$widgets;
    }
    
    /**
     * 根据ID获取小部件
     * 
     * @param string $id 小部件ID
     * @return TODO_Widget|null
     */
    public static function get_widget($id) {
        return isset(self::$widgets[$id]) ? self::$widgets[$id] : null;
    }
    
    /**
     * 注册小部件
     * 
     * @param string $widget_class 小部件类名
     */
    public static function register_widget($widget_class) {
        if (class_exists($widget_class)) {
            new $widget_class();
        }
    }
    
    /**
     * 注销小部件
     * 
     * @param string $widget_id 小部件ID
     */
    public static function unregister_widget($widget_id) {
        if (isset(self::$widgets[$widget_id])) {
            unset(self::$widgets[$widget_id]);
        }
    }
    
    /**
     * 渲染小部件
     * 
     * @param string $widget_id 小部件ID
     * @param array $args 显示参数
     * @param array $instance 实例数据
     */
    public static function render_widget($widget_id, $args = array(), $instance = array()) {
        $widget = self::get_widget($widget_id);
        if ($widget) {
            $default_args = array(
                'before_widget' => '<div class="widget">',
                'after_widget' => '</div>',
                'before_title' => '<h3 class="widget-title">',
                'after_title' => '</h3>'
            );
            $args = todo_parse_args($args, $default_args);
            $widget->widget($args, $instance);
        }
    }
    
    /**
     * 获取字段名称
     * 
     * @param string $field_name 字段名
     * @return string
     */
    protected function get_field_name($field_name) {
        return 'widget-' . $this->id_base . '[' . $field_name . ']';
    }
    
    /**
     * 获取字段ID
     * 
     * @param string $field_name 字段名
     * @return string
     */
    protected function get_field_id($field_name) {
        return 'widget-' . $this->id_base . '-' . $field_name;
    }
    
    /**
     * 输出文本输入框
     * 
     * @param string $field_name 字段名
     * @param string $value 字段值
     * @param string $label 标签
     */
    protected function text_field($field_name, $value = '', $label = '') {
        $field_id = $this->get_field_id($field_name);
        $field_name = $this->get_field_name($field_name);
        
        echo '<p>';
        if ($label) {
            echo '<label for="' . esc_attr($field_id) . '">' . esc_html($label) . ':</label>';
        }
        echo '<input class="widefat" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" type="text" value="' . esc_attr($value) . '" />';
        echo '</p>';
    }
    
    /**
     * 输出文本域
     * 
     * @param string $field_name 字段名
     * @param string $value 字段值
     * @param string $label 标签
     */
    protected function textarea_field($field_name, $value = '', $label = '') {
        $field_id = $this->get_field_id($field_name);
        $field_name = $this->get_field_name($field_name);
        
        echo '<p>';
        if ($label) {
            echo '<label for="' . esc_attr($field_id) . '">' . esc_html($label) . ':</label>';
        }
        echo '<textarea class="widefat" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" rows="3">' . esc_textarea($value) . '</textarea>';
        echo '</p>';
    }
    
    /**
     * 输出选择框
     * 
     * @param string $field_name 字段名
     * @param string $value 字段值
     * @param array $options 选项
     * @param string $label 标签
     */
    protected function select_field($field_name, $value = '', $options = array(), $label = '') {
        $field_id = $this->get_field_id($field_name);
        $field_name = $this->get_field_name($field_name);
        
        echo '<p>';
        if ($label) {
            echo '<label for="' . esc_attr($field_id) . '">' . esc_html($label) . ':</label>';
        }
        echo '<select class="widefat" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '">';
        foreach ($options as $option_value => $option_label) {
            echo '<option value="' . esc_attr($option_value) . '"' . selected($value, $option_value, false) . '>' . esc_html($option_label) . '</option>';
        }
        echo '</select>';
        echo '</p>';
    }
    
    /**
     * 输出复选框
     * 
     * @param string $field_name 字段名
     * @param bool $checked 是否选中
     * @param string $label 标签
     */
    protected function checkbox_field($field_name, $checked = false, $label = '') {
        $field_id = $this->get_field_id($field_name);
        $field_name = $this->get_field_name($field_name);
        
        echo '<p>';
        echo '<input class="checkbox" type="checkbox" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" value="1"' . checked($checked, true, false) . ' />';
        if ($label) {
            echo '<label for="' . esc_attr($field_id) . '">' . esc_html($label) . '</label>';
        }
        echo '</p>';
    }
}

/**
 * Todo_Widget 别名，保持向后兼容
 */
if (!class_exists('Todo_Widget')) {
    class_alias('TODO_Widget', 'Todo_Widget');
}
