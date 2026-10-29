<?php
/**
 * 专题模型类
 *
 * 处理专题类型的数据模型定义和字段管理
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class CMS_Post_Type_Special {
    /**
     * 模型标识
     *
     * @var string
     */
    public static $model_key = 'special';
    
    /**
     * 模型名称
     *
     * @var string
     */
    public static $model_name = '专题模型';
    
    /**
     * 模型描述
     *
     * @var string
     */
    public static $model_description = '用于发布专题活动、专题报道等专题内容的数据模型';
    
    /**
     * 列表模板
     *
     * @var string
     */
    public static $list_template = 'lists_special.html';
    
    /**
     * 内容模板
     *
     * @var string
     */
    public static $view_template = 'view_special.html';
    
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
                'field_name' => '专题封面',
                'field_key' => 'thumb',
                'field_type' => 'multi_file',
                'field_label' => '专题封面',
                'field_description' => '选择封面图片',
                'field_default' => '',
                'field_options' => json_encode([
                    'media_type' => 'image',
                    'multiple' => true,
                    'accept' => 'image/*',
                    'placeholder' => '点击选择封面'
                ]),
                'is_required' => 0,
                'field_order' => 1,
                'is_fixed' => 1,
                'field_section' => '专题设置'
            ],
            [
                'field_name' => '副标题',
                'field_key' => 'subtitle',
                'field_type' => 'text',
                'field_label' => '副标题',
                'field_description' => '',
                'field_default' => '',
                'field_options' => json_encode([
                    'placeholder' => '请输入副标题...',
                    'layout' => ['full_width' => true, 'sub_width' => true]
                ]),
                'is_required' => 0,
                'field_order' => 2,
                'is_fixed' => 1,
                'field_section' => '专题设置'
            ],
            [
                'field_name' => '精选内容ID',
                'field_key' => 'related_posts',
                'field_type' => 'textarea',
                'field_label' => '精选内容ID',
                'field_description' => '填写 post_id 列表：逗号/换行分隔；也支持JSON数组',
                'field_default' => '',
                'field_options' => json_encode([
                    'rows' => 4,
                    'placeholder' => "123\n456\n789",
                    'layout' => ['full_width' => true]
                ]),
                'is_required' => 0,
                'field_order' => 5,
                'is_fixed' => 1,
                'field_section' => '聚合设置'
            ],
            [
                'field_name' => '聚合分类ID',
                'field_key' => 'related_categories',
                'field_type' => 'text',
                'field_label' => '聚合分类ID',
                'field_description' => '子栏目分类ID，多个用逗号分隔（如：12,15,18）',
                'field_default' => '',
                'field_options' => json_encode([
                    'placeholder' => '如：12,15,18'
                ]),
                'is_required' => 0,
                'field_order' => 6,
                'is_fixed' => 1,
                'field_section' => '聚合设置'
            ],
            [
                'field_name' => '聚合标签',
                'field_key' => 'related_tags',
                'field_type' => 'text',
                'field_label' => '聚合标签',
                'field_description' => '多个用逗号分隔（slug或名称）',
                'field_default' => '',
                'field_options' => json_encode([
                    'placeholder' => '如：news,activity'
                ]),
                'is_required' => 0,
                'field_order' => 7,
                'is_fixed' => 1,
                'field_section' => '聚合设置'
            ],
            [
                'field_name' => '时间线(JSON)',
                'field_key' => 'timeline_events',
                'field_type' => 'textarea',
                'field_label' => '时间线(JSON)',
                'field_description' => '示例：[{"date":"202601","title":"节点标题","excerpt":"节点摘要","content":["id1,id2,id3",{"title": "外链标题","image": "/*.jpg", "url": "http://*.com", "time": "2026-07-31 15:56:40"}]}]',
                'field_default' => '',
                'field_options' => json_encode([
                    'rows' => 6,
                    'placeholder' => '[{"date":"202601","title":"节点标题","excerpt":"节点摘要","content":["id1,id2,id3"]}]',
                    'layout' => ['full_width' => true, 'sub_width' => true]
                ]),
                'is_required' => 0,
                'field_order' => 9,
                'is_fixed' => 1,
                'field_section' => '内容区块'
            ],
            [
                'field_name' => '专题图集',
                'field_key' => 'gallery_images',
                'field_type' => 'multi_file',
                'field_label' => '专题图集',
                'field_description' => '选择专题图集图片',
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
                'field_order' => 10,
                'is_fixed' => 1,
                'field_section' => '内容区块'
            ],
            [
                'field_name' => '热门',
                'field_key' => 'is_hot',
                'field_type' => 'switch',
                'field_label' => '热门',
                'field_description' => '',
                'field_default' => '0',
                'field_options' => json_encode([
                    'on_text' => '是',
                    'off_text' => '否'
                ]),
                'is_required' => 0,
                'field_order' => 11,
                'is_fixed' => 1,
                'field_section' => '运营设置'
            ],
            [
                'field_name' => '推荐',
                'field_key' => 'is_recommend',
                'field_type' => 'switch',
                'field_label' => '推荐',
                'field_description' => '',
                'field_default' => '0',
                'field_options' => json_encode([
                    'on_text' => '是',
                    'off_text' => '否'
                ]),
                'is_required' => 0,
                'field_order' => 12,
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
                'field_description' => '留空则使用专题标题',
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
    public static function get_fields_for_render() {
        return self::get_fixed_fields();
    }    
    /**
     * 获取固定字段数据
     *
     * @param array $field_values 字段值数组
     * @param int $post_id 专题ID
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
            'section_title' => '专题设置',
            'section_class' => 'fixed-fields-section'
        ];
    }
    
    /**
     * 获取固定字段HTML
     *
     * @param array $field_values 字段值数组
     * @param int $post_id 专题ID
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
     * @param int $post_id 专题ID
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

            $table = $tododb->prefix . 'model_fields_special';
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
            error_log('获取专题固定字段值失败: ' . $e->getMessage());
        }
        
        return $field_values;
    }
}
