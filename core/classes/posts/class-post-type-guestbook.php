<?php
/**
 * 留言模型类
 *
 * 处理留言类型的数据模型定义和字段管理
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class CMS_Post_Type_Guestbook {
    /**
     * 模型标识
     *
     * @var string
     */
    public static $model_key = 'guestbook';
    
    /**
     * 模型名称
     *
     * @var string
     */
    public static $model_name = '留言模型';
    
    /**
     * 模型描述
     *
     * @var string
     */
    public static $model_description = '用于发布留言和反馈的数据模型';
    
    /**
     * 列表模板
     *
     * @var string
     */
    public static $list_template = 'lists_guestbook.html';
    
    /**
     * 内容模板
     *
     * @var string
     */
    public static $view_template = 'view_guestbook.html';
    
    /**
     * 构造函数
     */
    public function __construct() {
        // 构造函数保留为空，所有功能通过静态方法实现
    }    
    /**
     * 获取模型固定字段配置
     *
     * @return array 模型特有的固定字段配置数组
     */
    public static function get_model_fixed_fields() {
        return [
            [
                'field_name' => '联系人姓名',
                'field_key' => 'contact_name',
                'field_type' => 'text',
                'field_label' => '联系人姓名',
                'field_description' => '留言者的姓名',
                'field_default' => '',
                'field_options' => json_encode([
                    'placeholder' => '请输入姓名...'
                ]),
                'is_required' => 1,
                'field_order' => 1,
                'is_fixed' => 1
            ],
            [
                'field_name' => '联系邮箱',
                'field_key' => 'contact_email',
                'field_type' => 'email',
                'field_label' => '联系邮箱',
                'field_description' => '留言者的邮箱地址',
                'field_default' => '',
                'field_options' => json_encode([
                    'placeholder' => '请输入邮箱地址...'
                ]),
                'is_required' => 1,
                'field_order' => 2,
                'is_fixed' => 1
            ],
            [
                'field_name' => '联系电话',
                'field_key' => 'contact_phone',
                'field_type' => 'text',
                'field_label' => '联系电话',
                'field_description' => '留言者的联系电话',
                'field_default' => '',
                'field_options' => json_encode([
                    'placeholder' => '请输入联系电话...'
                ]),
                'is_required' => 0,
                'field_order' => 3,
                'is_fixed' => 1
            ],
            [
                'field_name' => '留言状态',
                'field_key' => 'message_status',
                'field_type' => 'select',
                'field_label' => '留言状态',
                'field_description' => '留言的处理状态',
                'field_default' => 'pending',
                'field_options' => json_encode([
                    'options' => [
                        'pending' => '待处理',
                        'replied' => '已回复',
                        'closed' => '已关闭'
                    ]
                ]),
                'is_required' => 0,
                'field_order' => 4,
                'is_fixed' => 1
            ]
        ];
    }    

    /**
     * 获取所有固定字段配置（模型固定字段 + 基础固定字段）
     *
     * @return array
     */
    public static function get_fixed_fields() {
        return array_merge(self::get_model_fixed_fields(), self::get_base_fixed_fields());
    }
    /**
     * 获取基础固定字段配置
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
                'field_description' => '留空则使用留言标题',
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
    /**
     * 获取用于渲染的字段配置
     *
     * @return array 字段配置数组
     */
    public static function get_fields_for_render() {
        return self::get_fixed_fields();
    }
 
    /**
     * 获取固定字段数据
     *
     * @param array $field_values 字段值数组
     * @param int $post_id 留言ID
     * @param bool $include_wrapper 是否包含包装器
     * @return array 结构化的字段数据
     */
    public static function get_fixed_fields_data($field_values = [], $post_id = 0, $include_wrapper = true) {
        $fields = self::get_fixed_fields();
        $fields_data = [];
        
        foreach ($fields as $field) {
            $field_key = $field['field_key'];
            $value = '';
            if (isset($field_values[$field_key]) && $field_values[$field_key] !== '') {
                $value = $field_values[$field_key];
            } elseif ($post_id > 0) {
                $fixed_values = self::get_fixed_field_values($post_id);
                $value = isset($fixed_values[$field_key]) ? $fixed_values[$field_key] : $field['field_default'];
            } else {
                $value = $field['field_default'];
            }
            
            $options = json_decode($field['field_options'], true) ?: [];
            
            $field_data = [
                'key' => $field_key,
                'name' => $field['field_name'],
                'type' => $field['field_type'],
                'value' => $value,
                'required' => $field['is_required'],
                'description' => $field['field_description'],
                'options' => $options,
                'attributes' => []
            ];
            
            if ($field['field_type'] === 'textarea') {
                $field_data['attributes']['rows'] = isset($options['rows']) ? $options['rows'] : 3;
            }
            
            if (isset($options['placeholder'])) {
                $field_data['attributes']['placeholder'] = $options['placeholder'];
            }            
            if (isset($options['max_length'])) {
                $field_data['attributes']['maxlength'] = $options['max_length'];
            }
            
            $fields_data[] = $field_data;
        }
        
        return [
            'fields' => $fields_data,
            'include_wrapper' => $include_wrapper,
            'section_title' => '留言设置',
            'section_class' => 'fixed-fields-section'
        ];
    }
    
    /**
     * 获取固定字段HTML
     *
     * @param array $field_values 字段值数组
     * @param int $post_id 留言ID
     * @param bool $include_wrapper 是否包含包装器
     * @return string HTML字符串
     */
    public static function get_fixed_fields_html($field_values = [], $post_id = 0, $include_wrapper = true) {
        $data = self::get_fixed_fields_data($field_values, $post_id, $include_wrapper);
        return self::render_fields_html($data);
    }
    
    /**
     * 渲染字段HTML
     *
     * @param array $data 字段数据
     * @return string HTML字符串
     */
    private static function render_fields_html($data) {
        return Todo_Field_Renderer::render_sections_html($data);
    }
    
    /**
     * 获取固定字段值
     *
     * @param int $post_id 留言ID
     * @return array 固定字段值数组
     */
    public static function get_fixed_field_values($post_id) {
        global $tododb;
        
        if (!$post_id || !$tododb) {
            return [];
        }        
        $field_values = [];
        
        try {
            $post_sql = "SELECT seo_title, seo_keywords, seo_description FROM " . TABLE_PREFIX . "posts WHERE ID = ?";
            $post_data = $tododb->get_row($post_sql, [$post_id]);
            
            if ($post_data) {
                $post_array = is_array($post_data) ? $post_data : (array)$post_data;
                foreach ($post_array as $key => $value) {
                    $field_values[$key] = $value;
                }
            }

            $cf_rows = $tododb->get_results(
                "SELECT field_key, field_value FROM " . TABLE_PREFIX . "post_custom_fields WHERE post_id = ? AND field_key IN ('contact_name','contact_email','contact_phone','message_status')",
                [$post_id]
            );
            if (is_array($cf_rows)) {
                foreach ($cf_rows as $r) {
                    if (!is_object($r)) continue;
                    $k = (string) ($r->field_key ?? '');
                    if ($k === '') continue;
                    $v = $r->field_value ?? '';
                    if (!isset($field_values[$k]) || $field_values[$k] === '' || $field_values[$k] === null) {
                        $field_values[$k] = $v;
                    }
                }
            }

            $model_sql = "SELECT * FROM " . TABLE_PREFIX . "model_fields_guestbook WHERE post_id = ?";
            $model_data = $tododb->get_row($model_sql, [$post_id]);
            if ($model_data) {
                $model_array = is_array($model_data) ? $model_data : (array)$model_data;
                foreach ($model_array as $key => $value) {
                    if (in_array($key, ['field_id', 'post_id', 'created_at', 'updated_at'], true)) {
                        continue;
                    }
                    $field_values[$key] = $value;
                }
            }
        } catch (Exception $e) {
            error_log('获取留言固定字段值失败: ' . $e->getMessage());
        }
        
        return $field_values;
    }
}
