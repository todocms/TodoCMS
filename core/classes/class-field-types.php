<?php
/**
 * 字段类型管理器
 *
 * @package TodoCMS
 * @version 1.0.0
 */

 
class CMS_Field_Types {
    /**
     * 已注册的字段类型
     *
     * @var array
     */
    private static $types = [];
    
    /**
     * 注册字段类型
     *
     * @param string $type_class 字段类型类名
     * @return bool 是否成功注册
     */
    public static function register($type_class) {
        // 执行注册前的钩子
        do_action('todo_field_types_before_register', $type_class);
        
        // 允许通过钩子修改类名
        $type_class = apply_filters('todo_field_types_register_class_name', $type_class);
        
        if (!class_exists($type_class)) {
            do_action('todo_field_types_register_failed', $type_class, 'class_not_exists');
            return false;
        }
        
        $type_key = self::get_type_key($type_class);
        if (!$type_key) {
            do_action('todo_field_types_register_failed', $type_class, 'invalid_type_key');
            return false;
        }
        
        // 允许通过钩子修改类型键名
        $type_key = apply_filters('todo_field_types_register_type_key', $type_key, $type_class);
        
        self::$types[$type_key] = $type_class;
        
        // 执行注册后的钩子
        do_action('todo_field_types_after_register', $type_key, $type_class);
        
        return true;
    }
    
    /**
     * 获取字段类型键名
     *
     * @param string $type_class 字段类型类名
     * @return string|false 成功返回键名，失败返回false
     */
    private static function get_type_key($type_class) {
        if (!class_exists($type_class)) {
            return false;
        }
        
        $reflection = new ReflectionClass($type_class);
        if (!$reflection->isSubclassOf('Field_Type_Base')) {
            return false;
        }
        
        $key = strtolower(str_replace('Field_Type_', '', $type_class));
        return $key;
    }
    
    /**
     * 获取字段类型实例
     *
     * @param string $type 字段类型
     * @param array $field 字段配置
     * @param mixed $value 字段值
     * @return Field_Type_Base|null 字段类型实例
     */
    public static function get_type($type, $field = [], $value = null) {
        // 执行获取前的钩子
        do_action('todo_field_types_before_get_type', $type, $field, $value);
        
        // 允许通过钩子修改字段类型
        $type = apply_filters('todo_field_types_get_type_name', $type, $field, $value);
        
        if (!isset(self::$types[$type])) {
            // 执行类型不存在的钩子
            do_action('todo_field_types_type_not_found', $type, $field, $value);
            return null;
        }
        
        $class_name = self::$types[$type];
        
        // 允许通过钩子修改类名
        $class_name = apply_filters('todo_field_types_get_class_name', $class_name, $type, $field, $value);
        
        if (!class_exists($class_name)) {
            // 执行类不存在的钩子
            do_action('todo_field_types_class_not_found', $class_name, $type, $field, $value);
            return null;
        }
        
        // 允许通过钩子修改字段配置和值
        $field = apply_filters('todo_field_types_instance_field_config', $field, $type, $value);
        $value = apply_filters('todo_field_types_instance_value', $value, $type, $field);
        
        $instance = new $class_name($field, $value);
        
        // 允许通过钩子修改实例
        $instance = apply_filters('todo_field_types_instance', $instance, $type, $field, $value);
        
        // 执行获取后的钩子
        do_action('todo_field_types_after_get_type', $instance, $type, $field, $value);
        
        return $instance;
    }
    
    /**
     * 获取所有字段类型
     *
     * @return array 字段类型列表
     */
    public static function get_types() {
        $types = [];
        
        foreach (self::$types as $type_key => $type_class) {
            $types[$type_key] = [
                'key' => $type_key,
                'name' => $type_class::get_type_name(),
                'description' => $type_class::get_type_description(),
                'icon' => $type_class::get_type_icon(),
                'class' => $type_class
            ];
        }
        
        return $types;
    }
    
    /**
     * 初始化字段类型
     */
    public static function init() {
        // 执行初始化前的钩子
        do_action('todo_field_types_before_init');
        
        // 注册内置字段类型
        self::register_builtin_types();
        
        // 允许插件注册自定义字段类型
        do_action('todo_register_field_types');
        
        // 执行初始化后的钩子
        do_action('todo_field_types_after_init', self::$types);
    }
    
    /**
     * 注册内置字段类型
     */
    private static function register_builtin_types() {
        // 加载字段类型基类
        $fields_dir = dirname(__DIR__) . '/classes/fields';
        require_once $fields_dir . '/class-field-type-base.php';
        
        // 加载并注册字段类型
        $field_types = [
            'Field_Type_Text' => 'class-field-type-text.php',
            'Field_Type_Textarea' => 'class-field-type-textarea.php',
            'Field_Type_Html' => 'class-field-type-html.php',
            'Field_Type_Editor' => 'class-field-type-editor.php',
            'Field_Type_Number' => 'class-field-type-number.php',
            'Field_Type_Datetime' => 'class-field-type-datetime.php',
            'Field_Type_Select' => 'class-field-type-select.php',
            'Field_Type_Radio' => 'class-field-type-radio.php',
            'Field_Type_Checkbox' => 'class-field-type-checkbox.php',
            'Field_Type_Multi_File' => 'class-field-type-multi-file.php',
            'Field_Type_Region' => 'class-field-type-region.php',
            'Field_Type_Switch' => 'class-field-type-switch.php'
        ];
        
        foreach ($field_types as $class_name => $file_name) {
            $file_path = $fields_dir . '/' . $file_name;
            if (file_exists($file_path)) {
                require_once $file_path;
                if (class_exists($class_name)) {
                    self::register($class_name);
                }
            }
        }
    }
}

// 初始化字段类型
add_action('init', ['CMS_Field_Types', 'init']);