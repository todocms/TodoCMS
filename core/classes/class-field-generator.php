<?php
/**
 * 通用字段生成器组件
 *
 * 提供统一的接口来生成各种类型的字段
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 确保钩子函数已加载
if (!function_exists('todo_do_action')) {
    require_once ABSPATH . 'core/functions/todo-hooks.php';
}
 
class CMS_Field_Generator {
    /**
     * 字段配置
     *
     * @var array
     */
    private $fields = [];
    
    /**
     * 字段值
     *
     * @var array
     */
    private $values = [];
    
    /**
     * 表单ID
     *
     * @var string
     */
    private $form_id = '';
    
    /**
     * 构造函数
     *
     * @param array $fields 字段配置数组
     * @param array $values 字段值数组
     * @param string $form_id 表单ID
     */
    public function __construct($fields = [], $values = [], $form_id = 'form-fields') {
        $this->fields = $fields;
        $this->values = $values;
        $this->form_id = $form_id;
    }
    
    /**
     * 添加字段
     *
     * @param array $field 字段配置
     * @return void
     */
    public function add_field($field) {
        $this->fields[] = $field;
    }
    
    /**
     * 设置字段值
     *
     * @param array $values 字段值数组
     * @return void
     */
    public function set_values($values) {
        $this->values = $values;
    }
    
    /**
     * 设置单个字段值
     *
     * @param string $field_key 字段键名
     * @param mixed $value 字段值
     * @return void
     */
    public function set_value($field_key, $value) {
        $this->values[$field_key] = $value;
    }
    
    /**
     * 获取字段值
     *
     * @param string $field_key 字段键名
     * @param mixed $default 默认值
     * @return mixed 字段值
     */
    public function get_value($field_key, $default = null) {
        return isset($this->values[$field_key]) ? $this->values[$field_key] : $default;
    }
    
    /**
     * 获取所有字段值
     *
     * @return array 字段值数组
     */
    public function get_values() {
        return $this->values;
    }
    
    /**
     * 验证字段值
     *
     * @param array $data 要验证的数据
     * @return array 验证结果，包含 'valid' 和 'errors' 键
     */
    public function validate($data = null) {
        // 如果没有提供数据，使用当前值
        if ($data === null) {
            $data = $this->values;
        }
        
        // 允许通过钩子修改验证数据
        $data = todo_apply_filters('todo_field_generator_validate_data', $data, $this->fields, $this->form_id);
        
        // 执行验证前的钩子
        todo_do_action('todo_field_generator_before_validate', $data, $this->fields, $this->form_id);
        
        $errors = [];
        
        foreach ($this->fields as $field) {
            if (!is_array($field) || !isset($field['field_key'])) {
                continue;
            }
            
            $field_key = $field['field_key'];
            $value = $data[$field_key] ?? null;
            
            // 允许通过钩子修改字段验证配置
            $field = todo_apply_filters('todo_field_generator_validate_field_config', $field, $value, $data, $this->form_id);
            
            // 执行字段验证前的钩子
            todo_do_action('todo_field_generator_before_validate_field', $field, $value, $data, $this->form_id);
            
            // 获取字段类型实例
            $field_type = CMS_Field_Types::get_type($field['field_type'], $field, $value);
            
            if ($field_type) {
                $result = $field_type->validate($value);
                
                // 允许通过钩子修改验证结果
                $result = todo_apply_filters('todo_field_generator_validate_result', $result, $field, $value, $data, $this->form_id);
                
                if ($result !== true) {
                    $errors[$field_key] = $result;
                }
                
                // 执行字段验证后的钩子
                todo_do_action('todo_field_generator_after_validate_field', $field, $value, $result, $data, $this->form_id);
            }
        }
        
        // 允许通过钩子修改最终验证结果
        $validation_result = [
            'valid' => empty($errors),
            'errors' => $errors
        ];
        
        $validation_result = todo_apply_filters('todo_field_generator_validation_result', $validation_result, $data, $this->fields, $this->form_id);
        
        // 执行验证后的钩子
        todo_do_action('todo_field_generator_after_validate', $validation_result, $data, $this->fields, $this->form_id);
        
        return $validation_result;
    }
    
    /**
     * 格式化字段值用于保存
     *
     * @param array $form_data 表单数据（可选）
     * @return array 格式化后的字段值
     */
    public function format_values($form_data = null) {
        // 获取现有的全局变量值，保留未在字段配置中的值
        $formatted_values = $this->values;
        
        // 如果提供了表单数据，先更新值
        if ($form_data !== null) {
            $this->update_values_from_form($form_data);
        }
        
        foreach ($this->fields as $field) {
            $field_key = $field['field_key'];
            $value = $this->get_value($field_key);
            
            // 获取字段类型实例
            $field_type = CMS_Field_Types::get_type($field['field_type'], $field, $value);
            
            if ($field_type) {
                $formatted_values[$field_key] = $field_type->format_value($value);
            } else {
                $formatted_values[$field_key] = $value;
            }
        }
        
        return $formatted_values;
    }
    
    /**
     * 格式化字段值
     *
     * @param array $data 要格式化的数据
     * @return array 格式化后的数据
     */
    public function format($data) {
        // 允许通过钩子修改格式化数据
        $data = todo_apply_filters('todo_field_generator_format_data', $data, $this->fields, $this->form_id);
        
        // 执行格式化前的钩子
        todo_do_action('todo_field_generator_before_format', $data, $this->fields, $this->form_id);
        
        $formatted = [];
        
        foreach ($this->fields as $field) {
            if (!is_array($field) || !isset($field['field_key'])) {
                continue;
            }
            
            $field_key = $field['field_key'];
            $value = $data[$field_key] ?? null;
            
            // 允许通过钩子修改字段格式化配置
            $field = todo_apply_filters('todo_field_generator_format_field_config', $field, $value, $data, $this->form_id);
            
            // 执行字段格式化前的钩子
            todo_do_action('todo_field_generator_before_format_field', $field, $value, $data, $this->form_id);
            
            // 获取字段类型实例
            $field_type = CMS_Field_Types::get_type($field['field_type'], $field, $value);
            
            if ($field_type) {
                $formatted_value = $field_type->format($value);
                
                // 允许通过钩子修改格式化结果
                $formatted_value = todo_apply_filters('todo_field_generator_format_result', $formatted_value, $field, $value, $data, $this->form_id);
                
                $formatted[$field_key] = $formatted_value;
                
                // 执行字段格式化后的钩子
                todo_do_action('todo_field_generator_after_format_field', $field, $value, $formatted_value, $data, $this->form_id);
            } else {
                $formatted[$field_key] = $value;
            }
        }
        
        // 允许通过钩子修改最终格式化结果
        $formatted = todo_apply_filters('todo_field_generator_formatted_data', $formatted, $data, $this->fields, $this->form_id);
        
        // 执行格式化后的钩子
        todo_do_action('todo_field_generator_after_format', $formatted, $data, $this->fields, $this->form_id);
        
        return $formatted;
    }
    
    /**
     * 验证表单数据
     *
     * @param array $form_data 表单数据
     * @return array 验证结果
     */
    public function validate_form_data($form_data) {
        $result = [
            'success' => true,
            'errors' => []
        ];
        
        // 更新值
        $this->update_values_from_form($form_data);
        
        // 验证每个字段
        foreach ($this->fields as $field) {
            $field_key = $field['field_key'];
            $value = $this->get_value($field_key);
            
            // 检查必填字段
            if (isset($field['field_required']) && $field['field_required']) {
                if (empty($value) && $value !== '0' && $value !== 0) {
                    $result['success'] = false;
                    $result['errors'][] = $field['field_name'] . ' 是必填字段';
                    continue;
                }
            }
            
            // 获取字段类型实例进行验证
            $field_type = CMS_Field_Types::get_type($field['field_type'], $field, $value);
            
            if ($field_type) {
                $validation = $field_type->validate($value);
                
                if ($validation !== true) {
                    $result['success'] = false;
                    $result['errors'][] = $field['field_name'] . ': ' . $validation;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * 从表单数据更新字段值
     *
     * @param array $form_data 表单数据
     * @return void
     */
    private function update_values_from_form($form_data) {
        foreach ($this->fields as $field) {
            // 保护：确保字段配置有效
            if (!is_array($field) || !isset($field['field_key'])) {
                continue;
            }

            $field_key = $field['field_key'];

            // 兼容 TodoCMS 统一命名规则：model_field_<field_key>
            // 同时兼容历史前缀：custom_field_<field_key>
            $possible_names = [
                $field_key,
                'model_field_' . $field_key,
                'custom_field_' . $field_key
            ];

            $found_value = null;
            foreach ($possible_names as $name) {
                if (isset($form_data[$name])) {
                    $found_value = $form_data[$name];
                    break;
                }
            }

            if ($found_value !== null) {
                $this->set_value($field_key, $found_value);
            } else {
                // 对于复选框/开关一类，如果没有提交值，则设为false
                if (($field['field_type'] ?? '') === 'switch' || ($field['field_type'] ?? '') === 'checkbox') {
                    $this->set_value($field_key, false);
                }
            }
        }
    }
    
    /**
     * 生成字段HTML
     *
     * @return string 字段HTML
     */
    public function generate() {
        // 允许通过钩子修改字段配置
        $this->fields = todo_apply_filters('todo_field_generator_fields', $this->fields, $this->form_id);
        
        // 执行生成前的钩子
        todo_do_action('todo_field_generator_before_generate', $this->fields, $this->values, $this->form_id);
        
        $html = '';
        
        // 确保字段是有效的数组
        if (!is_array($this->fields)) {
            $html .= '<p>字段配置不是有效的数组格式</p>';
            return $html;
        }
        
        foreach ($this->fields as $field) {
            // 确保字段是有效的数组且包含必要的键
            if (!is_array($field) || !isset($field['field_key']) || !isset($field['field_type'])) {
                $html .= '<p>字段配置格式错误: ' . print_r($field, true) . '</p>';
                continue;
            }
            
            $field_key = $field['field_key'];
            $value = $this->get_value($field_key, $field['field_default'] ?? null);
            
            // 允许通过钩子修改单个字段配置
            $field = todo_apply_filters('todo_field_generator_field_config', $field, $value, $this->form_id);
            
            // 执行字段生成前的钩子
            todo_do_action('todo_field_generator_before_field', $field, $value, $this->form_id);
            
            // 获取字段类型实例
            $field_type = CMS_Field_Types::get_type($field['field_type'], $field, $value);
            
            if ($field_type) {
                $field_html = $field_type->get_html();
                
                // 允许通过钩子修改字段HTML
                $field_html = todo_apply_filters('todo_field_generator_field_html', $field_html, $field, $value, $this->form_id);

                if ($field['field_type'] == 'multi_file') {
                    $html .= '<div class="form-group" style="max-width: 235px;">' . $field_html . '</div>';
                } elseif ($field['field_type'] == 'region' || $field['field_type'] == 'switch') {
                    $html .= '<div class="form-group">' . $field_html . '</div>';
                } else {
                    $html .= $field_html;
                }

                // 执行字段生成后的钩子
                todo_do_action('todo_field_generator_after_field', $field, $value, $field_html, $this->form_id);
            } else {
                $html .= '<p>不支持的字段类型: ' . todo_esc_html($field['field_type']) . '</p>';
            }
        }
        
        // 允许通过钩子修改最终HTML
        $html = todo_apply_filters('todo_field_generator_html', $html, $this->fields, $this->values, $this->form_id);
        
        // 执行生成后的钩子
        todo_do_action('todo_field_generator_after_generate', $html, $this->fields, $this->values, $this->form_id);
        
        return $html;
    }
    
    /**
     * 生成字段配置HTML
     *
     * @param string $field_type 字段类型
     * @param array $field_data 字段数据
     * @return string 字段配置HTML
     */
    public static function generate_config($field_type, $field_data = []) {
        // 获取字段类型实例
        $field_type_obj = CMS_Field_Types::get_type($field_type, $field_data);
        
        if ($field_type_obj) {
            return $field_type_obj->get_config_html();
        }
        
        return '';
    }
    
    /**
     * 生成字段搜索HTML
     *
     * @param array $fields 字段配置数组
     * @param array $search_values 搜索值数组
     * @return string 搜索HTML
     */
    public static function generate_search($fields, $search_values = []) {
        $html = '<div class="search-fields">';
        
        foreach ($fields as $field) {
            $field_key = $field['field_key'];
            $search_value = isset($search_values[$field_key]) ? $search_values[$field_key] : null;
            
            // 获取字段类型实例
            $field_type = CMS_Field_Types::get_type($field['field_type'], $field, $search_value);
            
            if ($field_type) {
                $html .= '<div class="search-field search-field-' . todo_esc_attr($field['field_type']) . '">';
                $html .= $field_type->get_search_html();
                $html .= '</div>';
            }
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * 生成字段SQL查询条件
     *
     * @param array $fields 字段配置数组
     * @param array $search_values 搜索值数组
     * @return array 包含SQL条件和参数的数组
     */
    public static function generate_search_sql($fields, $search_values = []) {
        $where = [];
        $where_values = [];
        
        foreach ($fields as $field) {
            $field_key = $field['field_key'];
            
            if (isset($search_values[$field_key]) && $search_values[$field_key] !== '') {
                $search_value = $search_values[$field_key];
                
                // 获取字段类型实例
                $field_type = CMS_Field_Types::get_type($field['field_type'], $field, $search_value);
                
                if ($field_type) {
                    $sql = $field_type->get_search_sql();
                    
                    if ($sql) {
                        $where[] = $sql['where'];
                        $where_values = array_merge($where_values, $sql['values']);
                    }
                }
            }
        }
        
        return [
            'where' => $where,
            'values' => $where_values
        ];
    }
    
    /**
     * 生成字段排序SQL
     *
     * @param array $field 字段配置
     * @param string $order 排序方向（ASC或DESC）
     * @return string 排序SQL
     */
    public static function generate_order_sql($field, $order = 'ASC') {
        // 获取字段类型实例
        $field_type = CMS_Field_Types::get_type($field['field_type'], $field);
        
        if ($field_type) {
            return $field_type->get_order_sql($order);
        }
        
        return '';
    }
}