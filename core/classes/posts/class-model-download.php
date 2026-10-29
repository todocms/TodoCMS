<?php
/**
 * 下载模型类
 *
 * 处理下载类型的数据模型定义和字段管理
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class CMS_Post_Type_Download {
    /**
     * 模型标识
     *
     * @var string
     */
    public static $model_key = 'download';
    
    /**
     * 模型名称
     *
     * @var string
     */
    public static $model_name = '下载模型';
    
    /**
     * 模型描述
     *
     * @var string
     */
    public static $model_description = '用于发布软件、文档等下载资源的数据模型';
    
    /**
     * 列表模板
     *
     * @var string
     */
    public static $list_template = 'lists_download.html';
    
    /**
     * 内容模板
     *
     * @var string
     */
    public static $view_template = 'view_download.html';
    
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
            // 基础设置字段
            [
                'field_name' => '下载配置',
                'field_key' => 'download_config',
                'field_type' => 'download_builder',
                'field_label' => '下载列队',
                'field_description' => '配置多个下载地址，支持网盘链接、直链下载等',
                'field_default' => '',
                'field_options' => json_encode([
                    'max_downloads' => 10,
                    'allow_file_select' => true,
                    'placeholder' => '配置下载地址...'
                ]),
                'is_required' => 0,
                'field_order' => 1,
                'is_fixed' => 1,
                'field_section' => '基础设置'
            ],
            [
                'field_name' => '下载说明',
                'field_key' => 'download_note',
                'field_type' => 'textarea',
                'field_label' => '下载说明',
                'field_description' => '下载相关的补充说明',
                'field_default' => '',
                'field_options' => json_encode([
                    'rows' => 3,
                    'placeholder' => '请输入下载说明...',
                    'layout' => ['full_width' => true]
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
                'field_description' => '留空则使用下载标题',
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
            
            // 获取模型字段（存储在模型专用表中）
            $model_sql = "SELECT download_config, download_note FROM " . TABLE_PREFIX . "model_fields_download WHERE post_id = ?";
            $model_data = $tododb->get_row($model_sql, [$post_id]);
            
            if ($model_data) {
                $model_array = is_array($model_data) ? $model_data : (array)$model_data;
                foreach ($model_array as $key => $value) {
                    $field_values[$key] = $value;
                }
            }
        } catch (Exception $e) {
            error_log('获取下载固定字段值失败: ' . $e->getMessage());
        }
        
        return $field_values;
    }
    
    /**
     * 保存模型字段数据
     *
     * @param int $post_id 文章ID
     * @param array $model_fields 模型字段数据
     * @return bool
     */
    public static function save_fields($post_id, $model_fields) {
        global $tododb;
        
        if (!$post_id || !$tododb || empty($model_fields)) {
            return false;
        }
        
        try {
            $table_name = TABLE_PREFIX . 'model_fields_download';
            
            // 检查记录是否存在
            $check_sql = "SELECT COUNT(*) as count FROM `{$table_name}` WHERE post_id = ?";
            $result = $tododb->get_row($check_sql, [$post_id], 'ARRAY_A');
            // 兼容对象和数组返回格式
            if (is_object($result)) {
                $result = (array)$result;
            }
            $record_exists = ($result && isset($result['count'])) ? (int)$result['count'] > 0 : false;
            
            // 准备字段数据
            $download_config = isset($model_fields['download_config']) ? $model_fields['download_config'] : '';
            $download_note = isset($model_fields['download_note']) ? $model_fields['download_note'] : '';
            
            // 验证下载配置格式
            if ($download_config && !self::validate_download_config($download_config)) {
                error_log('下载配置格式无效: ' . $download_config);
                return false;
            }
            
            if ($record_exists) {
                // 更新记录（同时更新更新时间）
                $update_sql = "UPDATE `{$table_name}` SET download_config = ?, download_note = ?, updated_at = NOW() WHERE post_id = ?";
                $result = $tododb->query($update_sql, [$download_config, $download_note, $post_id]);
            } else {
                // 插入新记录（同时写入创建时间和更新时间）——统一使用 tododb_insert
                $result = tododb_insert($table_name, [
                    'post_id'         => $post_id,
                    'download_config' => $download_config,
                    'download_note'   => $download_note,
                    'created_at'      => date('Y-m-d H:i:s'),
                    'updated_at'      => date('Y-m-d H:i:s')
                ]) !== false;
            }
            
            if ($result === false) {
                error_log('保存下载模型字段失败: post_id=' . $post_id);
                return false;
            }
            
            return true;
        } catch (Exception $e) {
            error_log('保存下载模型字段异常: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 验证下载配置格式
     *
     * @param string $config_json JSON格式的下载配置
     * @return bool
     */
    private static function validate_download_config($config_json) {
        if (empty($config_json)) {
            return true; // 空值是有效的
        }
        
        $config = json_decode($config_json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false; // JSON格式错误
        }
        
        if (!is_array($config)) {
            return false; // 必须是数组
        }
        
        // 验证每个下载项的格式
        foreach ($config as $item) {
            if (!is_array($item)) {
                return false;
            }
            
            // 必须有url字段
            if (!isset($item['url']) || empty($item['url'])) {
                return false;
            }
            
            // 验证可选字段
            $allowed_fields = ['url', 'alias', 'extract_code', 'password', 'note'];
            foreach ($item as $key => $value) {
                if (!in_array($key, $allowed_fields)) {
                    return false;
                }
            }
        }
        
        return true;
    }
  
}