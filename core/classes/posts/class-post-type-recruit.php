<?php
/**
 * 招聘模型类
 *
 * 处理招聘类型的数据模型定义和字段管理
 *
 * @package TodoCMS
 * @version 1.0.0
 */
 
class CMS_Post_Type_Recruit {
    /**
     * 模型标识
     *
     * @var string
     */
    public static $model_key = 'recruit';
    
    /**
     * 模型名称
     *
     * @var string
     */
    public static $model_name = '招聘模型';
    
    /**
     * 模型描述
     *
     * @var string
     */
    public static $model_description = '用于发布招聘信息的数据模型';
    
    /**
     * 列表模板
     *
     * @var string
     */
    public static $list_template = 'lists_recruit.html';
    
    /**
     * 内容模板
     *
     * @var string
     */
    public static $view_template = 'view_recruit.html';
    
    /**
     * 获取模型特定的固定字段
     *
     * @return array 模型固定字段数组
     */
    public static function get_model_fixed_fields() {
        return [
            [
                'field_key' => 'company_name',
                'field_name' => '公司名称',
                'field_type' => 'text',
                'field_default' => '',
                'field_description' => '',
                'field_options' => json_encode(['placeholder' => '请输入公司名称']),
                'is_required' => 0,
                'field_order' => 1,
                'is_fixed' => 1,
                'field_section' => '公司信息'
            ],
            [
                'field_key' => 'company_logo',
                'field_name' => '公司Logo',
                'field_type' => 'multi_file',
                'field_default' => '',
                'field_description' => '选择Logo图片',
                'field_options' => json_encode([
                    'media_type' => 'image',
                    'multiple' => true,
                    'accept' => 'image/*',
                    'placeholder' => '点击选择Logo图片'
                ]),
                'is_required' => 0,
                'field_order' => 2,
                'is_fixed' => 1,
                'field_section' => '公司信息'
            ],
            [
                'field_key' => 'company_desc',
                'field_name' => '公司简介',
                'field_type' => 'textarea',
                'field_default' => '',
                'field_description' => '',
                'field_options' => json_encode(['rows' => 4, 'placeholder' => '请输入公司简介...', 'layout' => ['full_width' => true]]),
                'is_required' => 0,
                'field_order' => 3,
                'is_fixed' => 1,
                'field_section' => '公司信息'
            ],
            [
                'field_key' => 'work_location',
                'field_name' => '工作地点',
                'field_type' => 'text',
                'field_default' => '',
                'field_description' => '',
                'field_options' => json_encode(['placeholder' => '如：上海·浦东新区']),
                'is_required' => 0,
                'field_order' => 4,
                'is_fixed' => 1,
                'field_section' => '职位信息'
            ],
            [
                'field_key' => 'salary',
                'field_name' => '薪资待遇',
                'field_type' => 'text',
                'field_default' => '',
                'field_description' => '',
                'field_options' => json_encode(['placeholder' => '如：8K-12K / 20-30K / 面议']),
                'is_required' => 0,
                'field_order' => 5,
                'is_fixed' => 1,
                'field_section' => '职位信息'
            ],
            [
                'field_key' => 'experience',
                'field_name' => '经验要求',
                'field_type' => 'select',
                'field_default' => '不限',
                'field_description' => '',
                'field_options' => json_encode([
                    'options' => [
                        '不限' => '不限',
                        '1年以下' => '1年以下',
                        '1-3年' => '1-3年',
                        '3-5年' => '3-5年',
                        '5-10年' => '5-10年',
                        '10年以上' => '10年以上'
                    ]
                ]),
                'is_required' => 0,
                'field_order' => 6,
                'is_fixed' => 1,
                'field_section' => '职位信息'
            ],
            [
                'field_key' => 'education',
                'field_name' => '学历要求',
                'field_type' => 'select',
                'field_default' => '不限',
                'field_description' => '',
                'field_options' => json_encode([
                    'options' => [
                        '不限' => '不限',
                        '高中及以上' => '高中及以上',
                        '大专及以上' => '大专及以上',
                        '本科及以上' => '本科及以上',
                        '硕士及以上' => '硕士及以上'
                    ]
                ]),
                'is_required' => 0,
                'field_order' => 7,
                'is_fixed' => 1,
                'field_section' => '职位信息'
            ],
            [
                'field_key' => 'recruit_num',
                'field_name' => '招聘人数',
                'field_type' => 'number',
                'field_default' => '0',
                'field_description' => '',
                'field_options' => json_encode(['placeholder' => '如：3']),
                'is_required' => 0,
                'field_order' => 8,
                'is_fixed' => 1,
                'field_section' => '职位信息'
            ],
            [
                'field_key' => 'job_type',
                'field_name' => '岗位类型',
                'field_type' => 'select',
                'field_default' => 'full_time',
                'field_description' => '',
                'field_options' => json_encode([
                    'options' => [
                        'full_time' => '全职',
                        'part_time' => '兼职',
                        'intern' => '实习'
                    ]
                ]),
                'is_required' => 0,
                'field_order' => 9,
                'is_fixed' => 1,
                'field_section' => '职位信息'
            ],
            [
                'field_key' => 'deadline',
                'field_name' => '截止日期',
                'field_type' => 'date',
                'field_default' => '',
                'field_description' => '',
                'field_options' => json_encode([]),
                'is_required' => 0,
                'field_order' => 10,
                'is_fixed' => 1,
                'field_section' => '职位信息'
            ],
            [
                'field_key' => 'requirements',
                'field_name' => '任职要求',
                'field_type' => 'textarea',
                'field_default' => '',
                'field_description' => '每行一条；也支持JSON数组',
                'field_options' => json_encode(['rows' => 6, 'placeholder' => "1. ...\n2. ...", 'layout' => ['full_width' => true]]),
                'is_required' => 0,
                'field_order' => 11,
                'is_fixed' => 1,
                'field_section' => '职位详情'
            ],
            [
                'field_key' => 'benefits',
                'field_name' => '福利待遇',
                'field_type' => 'textarea',
                'field_default' => '',
                'field_description' => '每行一条：icon|名称；例如 fa-gift|年终奖；也支持JSON数组',
                'field_options' => json_encode(['rows' => 6, 'placeholder' => "fa-gift|年终奖\nfa-plane|带薪年假", 'layout' => ['full_width' => true]]),
                'is_required' => 0,
                'field_order' => 12,
                'is_fixed' => 1,
                'field_section' => '职位详情'
            ],
            [
                'field_key' => 'work_address',
                'field_name' => '工作地址',
                'field_type' => 'textarea',
                'field_default' => '',
                'field_description' => '',
                'field_options' => json_encode(['rows' => 3, 'placeholder' => '请输入详细工作地址...', 'layout' => ['full_width' => true]]),
                'is_required' => 0,
                'field_order' => 13,
                'is_fixed' => 1,
                'field_section' => '职位详情'
            ],
            [
                'field_key' => 'hr_email',
                'field_name' => 'HR邮箱',
                'field_type' => 'email',
                'field_default' => '',
                'field_description' => '',
                'field_options' => json_encode(['placeholder' => '如：hr@example.com']),
                'is_required' => 0,
                'field_order' => 14,
                'is_fixed' => 1,
                'field_section' => '投递设置'
            ],
            [
                'field_key' => 'hr_phone',
                'field_name' => 'HR电话',
                'field_type' => 'text',
                'field_default' => '',
                'field_description' => '',
                'field_options' => json_encode(['placeholder' => '如：400-000-0000']),
                'is_required' => 0,
                'field_order' => 15,
                'is_fixed' => 1,
                'field_section' => '投递设置'
            ],
            [
                'field_key' => 'is_urgent',
                'field_name' => '急聘',
                'field_type' => 'switch',
                'field_default' => '0',
                'field_description' => '',
                'field_options' => json_encode(['on_text' => '是', 'off_text' => '否']),
                'is_required' => 0,
                'field_order' => 16,
                'is_fixed' => 1,
                'field_section' => '运营设置'
            ],
            [
                'field_key' => 'is_hot',
                'field_name' => '热门',
                'field_type' => 'switch',
                'field_default' => '0',
                'field_description' => '',
                'field_options' => json_encode(['on_text' => '是', 'off_text' => '否']),
                'is_required' => 0,
                'field_order' => 17,
                'is_fixed' => 1,
                'field_section' => '运营设置'
            ],
            [
                'field_key' => 'is_new',
                'field_name' => '最新',
                'field_type' => 'switch',
                'field_default' => '0',
                'field_description' => '',
                'field_options' => json_encode(['on_text' => '是', 'off_text' => '否']),
                'is_required' => 0,
                'field_order' => 18,
                'is_fixed' => 1,
                'field_section' => '运营设置'
            ]
        ];
    }
    
    /**
     * 获取基础固定字段
     *
     * @return array 基础固定字段数组
     */
    public static function get_base_fixed_fields() {
        return [
            'seo_title' => [
                'field_key' => 'seo_title',
                'field_name' => 'SEO标题',
                'field_type' => 'text',
                'field_default' => '',
                'field_description' => '留空则使用页面标题',
                'field_options' => json_encode([
                    'placeholder' => '请输入SEO标题...',
                    'max_length' => 60
                ]),
                'is_required' => 0,
                'field_order' => 1,
                'is_fixed' => 1,
                'field_section' => 'SEO设置'
            ],
            'seo_keywords' => [
                'field_key' => 'seo_keywords',
                'field_name' => 'SEO关键词',
                'field_type' => 'text',
                'field_default' => '',
                'field_description' => '多个关键字用英文逗号,分隔',
                'field_options' => json_encode([
                    'placeholder' => '英文逗号,分隔...',
                    'max_length' => 200
                ]),
                'is_required' => 0,
                'field_order' => 2,
                'is_fixed' => 1,
                'field_section' => 'SEO设置'
            ],
            'seo_description' => [
                'field_key' => 'seo_description',
                'field_name' => 'SEO描述',
                'field_type' => 'textarea',
                'field_default' => '',
                'field_description' => '',
                'field_options' => json_encode([
                    'placeholder' => '请输入SEO描述...',
                    'rows' => 3,
                    'max_length' => 160
                ]),
                'is_required' => 0,
                'field_order' => 3,
                'is_fixed' => 1,
                'field_section' => 'SEO设置'
            ]
        ];
    }
    
    /**
     * 获取所有固定字段（模型字段 + 基础字段）
     *
     * @return array 所有固定字段数组
     */
    public static function get_fixed_fields() {
        return array_merge(self::get_model_fixed_fields(), self::get_base_fixed_fields());
    }
    
    /**
     * 获取用于渲染的字段数据
     *
     * @param array $field_values 字段值数组
     * @param int $post_id 招聘ID
     * @param bool $include_wrapper 是否包含包装器
     * @return array 用于渲染的字段数据
     */
    public static function get_fields_for_render($field_values = [], $post_id = 0, $include_wrapper = true) {
        return self::get_fixed_fields_data($field_values, $post_id, $include_wrapper);
    }    
    /**
     * 获取固定字段数据
     *
     * @param array $field_values 字段值数组
     * @param int $post_id 招聘ID
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
            'section_title' => '招聘设置',
            'section_class' => 'fixed-fields-section'
        ];
    }
    
    /**
     * 获取固定字段HTML
     *
     * @param array $field_values 字段值数组
     * @param int $post_id 招聘ID
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
     * @param int $post_id 招聘ID
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

            $table = $tododb->prefix . 'model_fields_recruit';
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

            if ((!isset($field_values['salary']) || $field_values['salary'] === '') && isset($field_values['job_salary'])) {
                $field_values['salary'] = $field_values['job_salary'];
            }
            if ((!isset($field_values['work_location']) || $field_values['work_location'] === '') && isset($field_values['job_location'])) {
                $field_values['work_location'] = $field_values['job_location'];
            }
            if ((!isset($field_values['experience']) || $field_values['experience'] === '') && isset($field_values['job_experience'])) {
                $field_values['experience'] = $field_values['job_experience'];
            }
        } catch (Exception $e) {
            error_log('获取招聘固定字段值失败: ' . $e->getMessage());
        }
        
        return $field_values;
    }
}
