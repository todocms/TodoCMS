<?php
/**
 * 自定义模型基类
 *
 * 为用户创建的自定义模型提供统一的数据处理能力
 * 继承基础模型功能，支持动态字段处理和SEO基础字段
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class CMS_Post_Type_Custom {
    /**
     * 模型ID
     *
     * @var int
     */
    protected $model_id;
    
    /**
     * 模型配置
     *
     * @var array
     */
    protected $model_config;
    
    /**
     * 构造函数
     *
     * @param int|string $model_identifier 模型ID或模型键
     */
    public function __construct($model_identifier = 0) {
        if (is_string($model_identifier)) {
            // 如果传入的是字符串，认为是model_key，需要获取model_id
            $this->model_id = $this->get_model_id_by_key($model_identifier);
        } else {
            // 如果传入的是数字，认为是model_id
            $this->model_id = intval($model_identifier);
        }
        $this->load_model_config();
    }
    
    /**
     * 根据模型键获取模型ID
     *
     * @param string $model_key 模型键
     * @return int 模型ID
     */
    private function get_model_id_by_key($model_key) {
        global $tododb;
        
        if (empty($model_key) || !$tododb) {
            return 0;
        }
        
        try {
            $sql = "SELECT model_id FROM " . TABLE_PREFIX . "models WHERE model_key = ? AND is_system = 0";
            $result = $tododb->get_var($sql, [$model_key]);
            return $result ? intval($result) : 0;
        } catch (Exception $e) {
            error_log('获取模型ID失败: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * 加载模型配置
     */
    private function load_model_config() {
        global $tododb;
        
        if (!$this->model_id || !$tododb) {
            return;
        }
        
        try {
            $sql = "SELECT * FROM " . TABLE_PREFIX . "models WHERE model_id = ?";
            $result = $tododb->get_row($sql, [$this->model_id]);
            
            if ($result) {
                $this->model_config = is_array($result) ? $result : (array)$result;
            }
        } catch (Exception $e) {
            error_log('加载自定义模型配置失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取模型ID
     *
     * @return int
     */
    public function get_model_id() {
        return $this->model_id;
    }
    
    /**
     * 获取模型标识
     *
     * @return string
     */
    public function get_model_key() {
        return isset($this->model_config['model_key']) ? $this->model_config['model_key'] : '';
    }
    
    /**
     * 获取模型名称
     *
     * @return string
     */
    public function get_model_name() {
        return isset($this->model_config['model_name']) ? $this->model_config['model_name'] : '';
    }
    
    /**
     * 获取基础固定字段配置（SEO字段等）
     * 所有自定义模型都应该具备这些基础字段
     *
     * @return array 基础固定字段配置数组
     */
    public static function get_base_fixed_fields() {
        return [
            [
                'field_name' => 'SEO标题',
                'field_key' => 'seo_title',
                'field_type' => 'text',
                'field_label' => 'SEO标题',
                'field_description' => '留空则使用文章标题',
                'field_default' => '',
                'field_options' => json_encode([
                    'max_length' => 60,
                    'placeholder' => '请输入SEO标题...'
                ]),
                'is_required' => 0,
                'field_order' => 1,
                'is_fixed' => 1,
                'field_section' => 'SEO设置'
            ],
            [
                'field_name' => 'SEO关键字',
                'field_key' => 'seo_keywords',
                'field_type' => 'text',
                'field_label' => 'SEO关键字',
                'field_description' => '多个关键字用英文逗号,分隔',
                'field_default' => '',
                'field_options' => json_encode([
                    'max_length' => 200,
                    'placeholder' => '英文逗号,分隔...'
                ]),
                'is_required' => 0,
                'field_order' => 2,
                'is_fixed' => 1,
                'field_section' => 'SEO设置'
            ],
            [
                'field_name' => 'SEO描述',
                'field_key' => 'seo_description',
                'field_type' => 'textarea',
                'field_label' => 'SEO描述',
                'field_description' => '',
                'field_default' => '',
                'field_options' => json_encode([
                    'max_length' => 160,
                    'rows' => 3,
                    'placeholder' => '请输入SEO描述...'
                ]),
                'is_required' => 0,
                'field_order' => 3,
                'is_fixed' => 1,
                'field_section' => 'SEO设置'
            ]
        ];
    }

    public static function get_model_fixed_fields() {
        return [];
    }

    public static function get_fixed_fields() {
        return array_merge(self::get_model_fixed_fields(), self::get_base_fixed_fields());
    }
    
    /**
     * 获取模型自定义字段配置
     *
     * @return array 自定义字段配置数组
     */
    public function get_model_custom_fields() {
        global $tododb;
        
        if (!$this->model_id || !$tododb) {
            return [];
        }
        
        try {
            $sql = "SELECT * FROM " . TABLE_PREFIX . "model_fields WHERE model_id = ? AND field_status = 'active' ORDER BY field_order ASC";
            $results = $tododb->get_results($sql, [$this->model_id]);
            
            $fields = [];
            if ($results) {
                foreach ($results as $field) {
                    $field_array = is_array($field) ? $field : (array)$field;
                    $fields[] = [
                        'field_name' => $field_array['field_name'],
                        'field_key' => $field_array['field_key'],
                        'field_type' => $field_array['field_type'],
                        'field_label' => $field_array['field_label'],
                        'field_description' => $field_array['field_description'],
                        'field_default' => $field_array['field_default'],
                        'field_options' => $field_array['field_options'],
                        'is_required' => $field_array['is_required'],
                        'field_order' => $field_array['field_order'],
                        'is_fixed' => 0,
                        'field_section' => '自定义字段'
                    ];
                }
            }
            
            return $fields;
        } catch (Exception $e) {
            error_log('获取自定义模型字段失败: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取所有字段配置（基础字段 + 自定义字段）
     *
     * @return array 所有字段配置数组
     */
    public function get_all_fields() {
        return array_merge(self::get_base_fixed_fields(), $this->get_model_custom_fields());
    }
    
    /**
     * 获取用于渲染的字段数据（按section分组）
     *
     * @param int $post_id 文章ID
     * @param array $field_values 字段值数组
     * @param bool $include_wrapper 是否包含包装器
     * @return array 分组后的字段数据
     */
    public function get_fields_for_render($post_id = 0, $field_values = [], $include_wrapper = true) {
        // 获取字段配置
        $fields = $this->get_all_fields();
        $normalized_fields = [];
        $values = [];
        
        // 转换字段格式为渲染器期望的格式
        foreach ($fields as $field) {
            $field_key = $field['field_key'];
            
            // 获取字段值
            $value = '';
            if (isset($field_values[$field_key]) && $field_values[$field_key] !== '') {
                $value = $field_values[$field_key];
            } elseif ($post_id > 0) {
                // 从数据库获取字段值
                if (in_array($field_key, ['seo_title', 'seo_keywords', 'seo_description'])) {
                    // SEO字段从posts表获取
                    $fixed_values = $this->get_fixed_field_values($post_id);
                    $value = isset($fixed_values[$field_key]) ? $fixed_values[$field_key] : $field['field_default'];
                } else {
                    // 自定义字段从post_custom_fields表获取
                    $custom_values = $this->get_custom_field_values($post_id);
                    $value = isset($custom_values[$field_key]) ? $custom_values[$field_key] : $field['field_default'];
                }
            } else {
                $value = $field['field_default'];
            }
            
            $values[$field_key] = $value;
            
            // 转换字段配置格式
            $options = json_decode($field['field_options'], true) ?: [];
            $normalized_fields[$field_key] = [
                'type' => $field['field_type'],
                'label' => $field['field_label'],
                'required' => $field['is_required'],
                'description' => $field['field_description'],
                'options' => $options,
                'default' => $field['field_default'],
                'field_section' => $field['field_section'] ?? '自定义字段'
            ];
            
            // 处理特定字段类型的属性
            if ($field['field_type'] === 'textarea') {
                $normalized_fields[$field_key]['rows'] = $options['rows'] ?? 3;
            }
            
            if (isset($options['placeholder'])) {
                $normalized_fields[$field_key]['placeholder'] = $options['placeholder'];
            }
            
            if (isset($options['max_length'])) {
                $normalized_fields[$field_key]['max_length'] = $options['max_length'];
            }
        }
        
        // 使用渲染器的通用分组方法
        return Todo_Field_Renderer::group_fields_by_section(
            $normalized_fields,
            $values,
            ['include_wrapper' => $include_wrapper]
        );
    }
    
    /**
     * 获取固定字段值（SEO字段等）
     *
     * @param int $post_id 文章ID
     * @return array 固定字段值数组
     */
    public function get_fixed_field_values($post_id) {
        global $tododb;
        
        if (!$post_id || !$tododb) {
            return [];
        }
        
        $field_values = [];
        
        try {
            // 获取固定字段（存储在posts表中）
            $post_sql = "SELECT seo_title, seo_keywords, seo_description FROM " . TABLE_PREFIX . "posts WHERE ID = ?";
            $post_data = $tododb->get_row($post_sql, [$post_id]);
            
            if ($post_data) {
                $post_array = is_array($post_data) ? $post_data : (array)$post_data;
                foreach ($post_array as $key => $value) {
                    $field_values[$key] = $value;
                }
            }
        } catch (Exception $e) {
            error_log('获取自定义模型固定字段值失败: ' . $e->getMessage());
        }
        
        return $field_values;
    }
    
    /**
     * 获取自定义字段值
     *
     * @param int $post_id 文章ID
     * @return array 自定义字段值数组
     */
    public function get_custom_field_values($post_id) {
        global $tododb;
        
        if (!$post_id || !$tododb) {
            return [];
        }
        
        $field_values = [];
        
        try {
            // 获取自定义字段（存储在post_custom_fields表中）
            $custom_sql = "SELECT field_key, field_value FROM " . TABLE_PREFIX . "post_custom_fields WHERE post_id = ?";
            $custom_data = $tododb->get_results($custom_sql, [$post_id]);
            
            if ($custom_data) {
                foreach ($custom_data as $field) {
                    $field_array = is_array($field) ? $field : (array)$field;
                    $field_values[$field_array['field_key']] = maybe_unserialize($field_array['field_value']);
                }
            }
        } catch (Exception $e) {
            error_log('获取自定义模型自定义字段值失败: ' . $e->getMessage());
        }
        
        return $field_values;
    }
    
    /**
     * 保存字段数据
     *
     * @param int $post_id 文章ID
     * @param array $field_data 字段数据
     * @return bool 保存是否成功
     */
    public function save_fields($post_id, $field_data) {
        if (!$post_id || empty($field_data)) {
            return false;
        }
        
        global $tododb;
        
        try {
            // 分离SEO字段和自定义字段
            $seo_fields = [];
            $custom_fields = [];
            
            foreach ($field_data as $field_key => $field_value) {
                if (in_array($field_key, ['seo_title', 'seo_keywords', 'seo_description'])) {
                    $seo_fields[$field_key] = $field_value;
                } else {
                    $custom_fields[$field_key] = $field_value;
                }
            }
            
            // 保存SEO字段到posts表
            if (!empty($seo_fields)) {
                $update_parts = [];
                $params = [];
                
                foreach ($seo_fields as $key => $value) {
                    $update_parts[] = "`{$key}` = ?";
                    $params[] = $value;
                }
                
                if (!empty($update_parts)) {
                    $params[] = $post_id;
                    $sql = "UPDATE " . TABLE_PREFIX . "posts SET " . implode(', ', $update_parts) . " WHERE ID = ?";
                    $tododb->query($sql, $params);
                }
            }
            
            // 保存自定义字段到post_custom_fields表
            if (!empty($custom_fields)) {
                foreach ($custom_fields as $field_key => $field_value) {
                    $sql = "INSERT INTO " . TABLE_PREFIX . "post_custom_fields (post_id, field_key, field_value) 
                            VALUES (?, ?, ?) 
                            ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)";
                    $tododb->query($sql, [$post_id, $field_key, maybe_serialize($field_value)]);
                }
            }
            
            return true;
        } catch (Exception $e) {
            error_log('保存自定义模型字段失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 验证字段数据
     *
     * @param array $field_data 字段数据
     * @return array 验证结果
     */
    public function validate_fields($field_data) {
        $errors = [];
        $fields = $this->get_all_fields();
        
        foreach ($fields as $field) {
            $field_key = $field['field_key'];
            $field_value = isset($field_data[$field_key]) ? $field_data[$field_key] : '';
            
            // 检查必填字段
            if ($field['is_required'] && empty($field_value)) {
                $errors[] = $field['field_label'] . '是必填字段';
            }
            
            // 检查字段长度限制
            $options = json_decode($field['field_options'], true) ?: [];
            if (isset($options['max_length']) && strlen($field_value) > $options['max_length']) {
                $errors[] = $field['field_label'] . '长度不能超过' . $options['max_length'] . '个字符';
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * 获取固定字段HTML
     *
     * @param array $field_values 字段值数组
     * @param int $post_id 文章ID
     * @param bool $show_title 是否显示标题
     * @return string HTML字符串
     */
    public function get_fixed_fields_html($field_values = [], $post_id = 0, $show_title = true) {
        $html = '';
        $fixed_fields = $this->get_base_fixed_fields();
        
        if (empty($fixed_fields)) {
            return $html;
        }
        
        if ($show_title) {
            $html .= '<div class="fixed-fields-section section-custom-model">';
            $html .= '<h4 class="section-title">SEO设置</h4>';
        }
        
        $html .= '<div class="flex justify-between flex-wrap">';
        foreach ($fixed_fields as $field) {
            $field_key = $field['field_key'];
            $field_value = isset($field_values[$field_key]) ? $field_values[$field_key] : (isset($field['field_default']) ? $field['field_default'] : '');
            
            $html .= '<div class="form-group fixed-field">';
            $html .= '<h4>' . esc_html($field['field_label']) . '</h4>';
            
            switch ($field['field_type']) {
                case 'text':
                    $html .= '<input type="text" id="' . esc_attr($field_key) . '" name="' . esc_attr($field_key) . '" value="' . esc_attr($field_value) . '" class="form-control" />';
                    break;
                    
                case 'textarea':
                    $html .= '<textarea id="' . esc_attr($field_key) . '" name="' . esc_attr($field_key) . '" class="form-control">' . esc_textarea($field_value) . '</textarea>';
                    break;
                    
                case 'number':
                    $html .= '<input type="number" id="' . esc_attr($field_key) . '" name="' . esc_attr($field_key) . '" value="' . esc_attr($field_value) . '" class="form-control" />';
                    break;
                    
                default:
                    $html .= '<input type="text" id="' . esc_attr($field_key) . '" name="' . esc_attr($field_key) . '" value="' . esc_attr($field_value) . '" class="form-control" />';
                    break;
            }
            
            if (!empty($field['field_description'])) {
                $html .= '<p class="description">' . esc_html($field['field_description']) . '</p>';
            }
            
            $html .= '</div>';
        }
        $html .= '</div>';
        
        if ($show_title) {
            $html .= '</div>';
        }
        
        return $html;
    }
}
