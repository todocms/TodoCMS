<?php
/**
 * 图集模型类
 *
 * 处理图集类型的数据模型定义和字段管理
 *
 * @package TodoCMS
 * @version 1.0.0
 */
 
class CMS_Post_Type_Images {
    /**
     * 模型标识
     *
     * @var string
     */
    public static $model_key = 'images';
    
    /**
     * 模型名称
     *
     * @var string
     */
    public static $model_name = '图集模型';
    
    /**
     * 模型描述
     *
     * @var string
     */
    public static $model_description = '用于发布图片集合、相册等图片展示的数据模型';
    
    /**
     * 列表模板
     *
     * @var string
     */
    public static $list_template = 'lists_images.html';
    
    /**
     * 内容模板
     *
     * @var string
     */
    public static $view_template = 'view_images.html';
    
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
                'field_name' => '图片列表',
                'field_key' => 'post_images',
                'field_type' => 'multi_file',
                'field_label' => '图片列表',
                'field_description' => '选择图片列队',
                'field_default' => '',
                'field_options' => json_encode([
                    'media_type' => 'image',
                    'file_type' => 'image',
                    'multiple' => true,
                    'max_files' => 100,
                    'accept' => 'image/*',
                    'placeholder' => '点击选择图片',
                    'layout' => ['full_width' => true]
                ]),
                'is_required' => 0,
                'field_order' => 1,
                'is_fixed' => 1,
                'field_section' => '图集设置'
            ],
            [
                'field_name' => '封面图',
                'field_key' => 'cover_image',
                'field_type' => 'text',
                'field_label' => '封面图',
                'field_description' => '留空则默认使用第一张图片',
                'field_default' => '',
                'field_options' => json_encode([
                    'placeholder' => '请输入封面图片URL...'
                ]),
                'is_required' => 0,
                'field_order' => 2,
                'is_fixed' => 1,
                'field_section' => '图集设置'
            ],
            [
                'field_name' => '摄影师',
                'field_key' => 'photographer',
                'field_type' => 'text',
                'field_label' => '摄影师',
                'field_description' => '',
                'field_default' => '',
                'field_options' => json_encode([
                    'placeholder' => '如：张三'
                ]),
                'is_required' => 0,
                'field_order' => 3,
                'is_fixed' => 1,
                'field_section' => '图集设置'
            ],
            [
                'field_name' => '拍摄时间',
                'field_key' => 'shoot_date',
                'field_type' => 'date',
                'field_label' => '拍摄时间',
                'field_description' => '',
                'field_default' => '',
                'field_options' => json_encode([]),
                'is_required' => 0,
                'field_order' => 4,
                'is_fixed' => 1,
                'field_section' => '图集设置'
            ],
            [
                'field_name' => '拍摄地点',
                'field_key' => 'location',
                'field_type' => 'text',
                'field_label' => '拍摄地点',
                'field_description' => '',
                'field_default' => '',
                'field_options' => json_encode([
                    'placeholder' => '如：上海'
                ]),
                'is_required' => 0,
                'field_order' => 5,
                'is_fixed' => 1,
                'field_section' => '图集设置'
            ],
            [
                'field_name' => '推荐',
                'field_key' => 'is_featured',
                'field_type' => 'switch',
                'field_label' => '推荐',
                'field_description' => '',
                'field_default' => '0',
                'field_options' => json_encode([
                    'on_text' => '是',
                    'off_text' => '否'
                ]),
                'is_required' => 0,
                'field_order' => 6,
                'is_fixed' => 1,
                'field_section' => '运营设置'
            ],
        ];
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
                'field_description' => '留空则使用图集标题',
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
     * 获取固定字段配置（合并模型固定字段和基础固定字段）
     *
     * @return array 完整的固定字段配置数组
     */
    public static function get_fixed_fields() {
        return array_merge(
            self::get_model_fixed_fields(),
            self::get_base_fixed_fields()
        );
    }
    
    /**
     * 获取用于渲染的字段配置
     *
     * @return array 字段配置数组
     */
    public static function get_fields_for_render($post_id = 0, $field_values = [], $include_wrapper = true) {
        $fields = self::get_fixed_fields();
        $normalized_fields = [];
        $values = [];

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

            $values[$field_key] = $value;
            $options = json_decode($field['field_options'], true) ?: [];
            $normalized_fields[$field_key] = [
                'type' => $field['field_type'],
                'label' => $field['field_label'],
                'required' => $field['is_required'],
                'description' => $field['field_description'],
                'options' => $options,
                'default' => $field['field_default'],
                'field_section' => $field['field_section'] ?? '基础设置'
            ];

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

        return Todo_Field_Renderer::group_fields_by_section(
            $normalized_fields,
            $values,
            ['include_wrapper' => $include_wrapper]
        );
    }    
    /**
     * 获取固定字段数据
     *
     * @param array $field_values 字段值数组
     * @param int $post_id 图集ID
     * @param bool $include_wrapper 是否包含包装器
     * @return array 结构化的字段数据
     */
    public static function get_fixed_fields_data($field_values = [], $post_id = 0, $include_wrapper = true) {
        return self::get_fields_for_render($post_id, $field_values, $include_wrapper);
    }
    
    /**
     * 获取固定字段HTML
     *
     * @param array $field_values 字段值数组
     * @param int $post_id 图集ID
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
     * @param int $post_id 图集ID
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

            $table = $tododb->prefix . 'model_fields_images';
            $exists = false;
            if (function_exists('table_exists')) {
                $exists = table_exists($table);
            } elseif (preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                $exists = (bool) $tododb->get_var("SHOW TABLES LIKE '{$table}'");
            }
            if ($exists) {
                $row = $tododb->get_row("SELECT * FROM `{$table}` WHERE post_id = ? LIMIT 1", [$post_id], 'ARRAY_A');
                if (is_object($row)) {
                    $row = (array) $row;
                }
                if (is_array($row)) {
                    foreach ($row as $k => $v) {
                        if (in_array($k, ['field_id', 'post_id', 'created_at', 'updated_at'], true)) {
                            continue;
                        }
                        $vv = maybe_unserialize($v);
                        if (is_string($vv)) {
                            $t = trim($vv);
                            if (($t !== '') && (($t[0] === '[' && substr($t, -1) === ']') || ($t[0] === '{' && substr($t, -1) === '}'))) {
                                $decoded = json_decode($t, true);
                                if ($decoded !== null) {
                                    $vv = $decoded;
                                }
                            }
                        }
                        $field_values[$k] = $vv;
                    }
                }
            }
        } catch (Exception $e) {
            error_log('获取图集固定字段值失败: ' . $e->getMessage());
        }
        
        return $field_values;
    }
}
