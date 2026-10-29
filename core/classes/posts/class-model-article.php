<?php
/**
 * 文章模型类
 *
 * 处理文章类型的数据模型定义和字段管理
 *
 * @package TodoCMS
 * @version 1.0.0
 */
 
class CMS_Post_Type_Article {
    /**
     * 模型标识
     *
     * @var string
     */
    public static $model_key = 'article';
    
    /**
     * 模型名称
     *
     * @var string
     */
    public static $model_name = '文章模型';
    
    /**
     * 模型描述
     *
     * @var string
     */
    public static $model_description = '用于发布新闻、资讯等文章内容的数据模型';
    
    /**
     * 列表模板
     *
     * @var string
     */
    public static $list_template = 'lists_article.html';
    
    /**
     * 内容模板
     *
     * @var string
     */
    public static $view_template = 'view_article.html';
    
    /**
     * 构造函数
     */
    public function __construct() {
        // 构造函数保留为空，所有功能通过静态方法实现
    }
    
    /**
     * 获取模型固定字段配置（存储在 todo_model_fields_* 表）
     *
     * @return array 模型固定字段配置数组
     */
    public static function get_model_fixed_fields() {
        return [
            [
                'field_name' => '文章来源',
                'field_key' => 'article_source',
                'field_type' => 'text',
                'field_label' => '文章来源',
                'field_description' => '文章来源，转载地址',
                'field_default' => '',
                'field_options' => json_encode([
                    'max_length' => 60,
                    'placeholder' => '请输入文章来源...'
                ]),
                'is_required' => 0,
                'field_order' => 1,
                'is_fixed' => 1,
                'field_section' => '基础设置'
            ],
            [
                'field_name' => '是否原创',
                'field_key' => 'is_original',
                'field_type' => 'switch',
                'field_label' => '是否原创',
                'field_description' => '是否原创：是/否',
                'field_default' => '1',
                'field_options' => json_encode([
                    'on_text' => '是',
                    'off_text' => '否'
                ]),
                'is_required' => 0,
                'field_order' => 2,
                'is_fixed' => 1,
                'field_section' => '基础设置'
            ]
        ];
    }

    /**
     * 获取基础固定字段配置（存储在 posts 表）
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

    /**
     * 获取所有固定字段配置（多重拼接）
     *
     * @return array 固定字段配置数组
     */
    public static function get_fixed_fields() {
        return array_merge(self::get_model_fixed_fields(), self::get_base_fixed_fields());
    }

    /**
     * 获取用于渲染的字段数据（按section分组）
     * 使用字段渲染器的通用分组方法
     *
     * @param int $post_id 文章ID
     * @param array $field_values 字段值数组
     * @param bool $include_wrapper 是否包含包装器
     * @return array 分组后的字段数据
     */
    public static function get_fields_for_render($post_id = 0, $field_values = [], $include_wrapper = true) {
        // 获取字段配置
        $fields = self::get_fixed_fields();
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
                $fixed_values = self::get_fixed_field_values($post_id);
                $value = isset($fixed_values[$field_key]) ? $fixed_values[$field_key] : $field['field_default'];
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
                'field_section' => $field['field_section'] ?? '基础设置'
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
     * 获取固定字段的结构化数据（用于模板渲染）
     * 使用字段渲染器的通用分组方法
     *
     * @param array $field_values 字段值数组
     * @param int $post_id 文章ID
     * @param bool $include_wrapper 是否包含包装器
     * @return array 结构化的字段数据
     */
    public static function get_fixed_fields_data($field_values = [], $post_id = 0, $include_wrapper = true) {
        // 直接调用get_fields_for_render方法，它已经使用了正确的分组逻辑
        return self::get_fields_for_render($post_id, $field_values, $include_wrapper);
    }
    
    /**
     * 获取固定字段HTML
     *
     * @param array $field_values 字段值数组
     * @param int $post_id 文章ID
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
     * @param int $post_id 文章ID
     * @return array 固定字段值数组
     */
    public static function get_fixed_field_values($post_id) {
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
                    // 即使值为空也要添加到数组中，这样字段就能正确显示
                    $field_values[$key] = $value;
                }
            }
        } catch (Exception $e) {
            error_log('获取文章固定字段值失败: ' . $e->getMessage());
        }
        
        return $field_values;
    }
  
}