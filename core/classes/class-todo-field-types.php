<?php
/**
 * TodoCMS 字段类型定义
 * 
 * 定义所有支持的字段类型及其配置
 *
 * @package TodoCMS
 * @version 1.0.0
 */

 
class Todo_Field_Types {
    
    /**
     * 获取所有支持的字段类型
     *
     * @return array 字段类型配置数组
     */
    public static function get_field_types() {
        return [
            'text' => [
                'name' => '单行文本',
                'description' => '单行文本输入框',
                'category' => 'basic',
                'settings' => [
                    'placeholder' => '占位符文本',
                    'maxlength' => '最大长度',
                    'pattern' => '验证正则表达式'
                ]
            ],
            'textarea' => [
                'name' => '多行文本',
                'description' => '多行文本输入框',
                'category' => 'basic',
                'settings' => [
                    'rows' => '行数',
                    'placeholder' => '占位符文本',
                    'maxlength' => '最大长度'
                ]
            ],
            'html' => [
                'name' => 'HTML文本',
                'description' => '富文本编辑器',
                'category' => 'advanced',
                'settings' => [
                    'height' => '编辑器高度',
                    'toolbar' => '工具栏配置',
                    'upload_enabled' => '允许上传'
                ]
            ],
            'select' => [
                'name' => '下拉框',
                'description' => '单选下拉列表',
                'category' => 'choice',
                'settings' => [
                    'options' => '选项列表',
                    'multiple' => '允许多选',
                    'searchable' => '可搜索'
                ]
            ],
            'radio' => [
                'name' => '单选项',
                'description' => '单选按钮组',
                'category' => 'choice',
                'settings' => [
                    'options' => '选项列表',
                    'layout' => '布局方式'
                ]
            ],
            'checkbox' => [
                'name' => '多选项',
                'description' => '复选框组',
                'category' => 'choice',
                'settings' => [
                    'options' => '选项列表',
                    'layout' => '布局方式',
                    'max_selections' => '最大选择数'
                ]
            ],            'region' => [
                'name' => '区域类型',
                'description' => '省市区选择器',
                'category' => 'location',
                'settings' => [
                    'level' => '选择级别',
                    'default_province' => '默认省份',
                    'default_city' => '默认城市'
                ]
            ],
            'number' => [
                'name' => '整数类型',
                'description' => '整数输入框',
                'category' => 'numeric',
                'settings' => [
                    'min' => '最小值',
                    'max' => '最大值',
                    'step' => '步长'
                ]
            ],
            'decimal' => [
                'name' => '小数类型',
                'description' => '小数输入框',
                'category' => 'numeric',
                'settings' => [
                    'min' => '最小值',
                    'max' => '最大值',
                    'precision' => '小数位数'
                ]
            ],
            'price' => [
                'name' => '金额类型',
                'description' => '货币金额输入',
                'category' => 'numeric',
                'settings' => [
                    'currency' => '货币符号',
                    'min' => '最小金额',
                    'max' => '最大金额'
                ]
            ],
            'image' => [
                'name' => '单图',
                'description' => '单张图片上传',
                'category' => 'media',
                'settings' => [
                    'max_size' => '最大文件大小',
                    'allowed_types' => '允许的文件类型',
                    'thumbnail_size' => '缩略图尺寸'
                ]
            ],
            'gallery' => [
                'name' => '多图',
                'description' => '多张图片上传',
                'category' => 'media',
                'settings' => [
                    'max_files' => '最大文件数',
                    'max_size' => '最大文件大小',
                    'sortable' => '可排序'
                ]
            ],
            'file' => [
                'name' => '附件类型',
                'description' => '文件上传',
                'category' => 'media',
                'settings' => [
                    'max_size' => '最大文件大小',
                    'allowed_types' => '允许的文件类型',
                    'multiple' => '允许多文件'
                ]
            ],
            'video' => [
                'name' => '多媒体类型',
                'description' => '视频/音频上传',
                'category' => 'media',
                'settings' => [
                    'max_size' => '最大文件大小',
                    'allowed_types' => '允许的媒体类型',
                    'poster' => '视频封面'
                ]
            ],
            'download_builder' => [
                'name' => '下载配置',
                'description' => '多下载地址配置器',
                'category' => 'media',
                'settings' => [
                    'max_downloads' => '最大下载数量',
                    'allow_file_select' => '允许文件选择',
                    'placeholder' => '占位符文本'
                ]
            ],
            'datetime' => [
                'name' => '日期和时间',
                'description' => '日期时间选择器',
                'category' => 'datetime',
                'settings' => [
                    'format' => '日期格式',
                    'min_date' => '最小日期',
                    'max_date' => '最大日期',
                    'time_enabled' => '启用时间选择'
                ]
            ],
            'switch' => [
                'name' => '开关',
                'description' => '开关按钮',
                'category' => 'basic',
                'settings' => [
                    'on_text' => '开启文本',
                    'off_text' => '关闭文本',
                    'default_value' => '默认值'
                ]
            ]
        ];
    }
    
    /**
     * 获取字段类型分类
     *
     * @return array 分类配置
     */
    public static function get_field_categories() {
        return [
            'basic' => [
                'name' => '基础字段',
                'order' => 1
            ],
            'choice' => [
                'name' => '选择字段',
                'order' => 2
            ],
            'numeric' => [
                'name' => '数值字段',
                'order' => 3
            ],
            'media' => [
                'name' => '媒体字段',
                'order' => 4
            ],
            'datetime' => [
                'name' => '日期时间',
                'order' => 5
            ],
            'location' => [
                'name' => '位置字段',
                'order' => 6
            ],
            'advanced' => [
                'name' => '高级字段',
                'order' => 7
            ]
        ];
    }
    
    /**
     * 获取指定字段类型的配置
     *
     * @param string $type 字段类型
     * @return array|null 字段配置
     */
    public static function get_field_type($type) {
        $types = self::get_field_types();
        return isset($types[$type]) ? $types[$type] : null;
    }
    
    /**
     * 验证字段类型是否存在
     *
     * @param string $type 字段类型
     * @return bool
     */
    public static function is_valid_type($type) {
        $types = self::get_field_types();
        return isset($types[$type]);
    }
    
    /**
     * 获取字段类型的默认设置
     *
     * @param string $type 字段类型
     * @return array 默认设置
     */
    public static function get_default_settings($type) {
        $field_type = self::get_field_type($type);
        if (!$field_type) {
            return [];
        }
        
        $defaults = [];
        foreach ($field_type['settings'] as $key => $label) {
            $defaults[$key] = '';
        }
        
        return $defaults;
    }
}