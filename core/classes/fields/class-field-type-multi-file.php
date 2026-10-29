<?php
/**
 * 多文件字段类型 - 基于特色图像组件
 *
 * 支持单文件和多文件模式，完全基于TodoFeaturedImagesComponent组件
 * 统一处理图片、视频、音频、文档等各种文件类型的选择和管理
 * 组件自动处理JS/CSS资源加载，无需手动管理
 *
 * @package TodoCMS
 * @version 3.1.0
 */

// 加载特色图像组件
if (!class_exists('TodoFeaturedImagesComponent')) {
    require_once ABSPATH . 'admin/components/featured-images-component.php';
}

class Field_Type_Multi_File extends Field_Type_Base {
    
    /**
     * 获取字段类型名称
     *
     * @return string
     */
    public static function get_type_name() {
        return '图片/文件';
    }
    
    /**
     * 获取字段类型描述
     *
     * @return string
     */
    public static function get_type_description() {
        return '支持图片和文件上传、选择和管理';
    }
    
    /**
     * 获取字段类型图标
     *
     * @return string
     */
    public static function get_type_icon() {
        return 'fa-images';
    }
    
    /**
     * 获取字段类型键名
     *
     * @return string
     */
    public static function get_type_key() {
        return 'multi_file';
    }
    
    /**
     * 获取字段HTML
     * 
     * 使用TodoFeaturedImagesComponent组件渲染多文件字段
     * 组件会自动加载所需的JS/CSS资源
     *
     * @return string 字段HTML
     */
    protected function render_html() {
        // 检查特色图像组件是否可用
        if (!$this->is_component_available()) {
            return '<div class="error">错误：特色图像组件未加载，无法渲染多文件字段</div>';
        }
        
        // 获取字段配置和当前值
        $field_options = isset($this->field['options']) ? $this->field['options'] : array();
        $field_value = $this->value;
        $field_key = isset($this->field['field_key']) ? $this->field['field_key'] : (isset($this->field['key']) ? $this->field['key'] : 'multi_file');
        
        // 解析字段选项
        
        $options = is_array($field_options) ? $field_options : (json_decode($field_options, true) ?: array());
        $max_files = isset($options['max_files']) ? intval($options['max_files']) : (isset($options['max']) ? intval($options['max']) : 20);
        $file_type = isset($options['file_type']) ? $options['file_type'] : 'any';

        // 解析当前值，统一为数组（JSON数组优先）
        $current_files = array();
        if (is_array($field_value)) {
            $current_files = $field_value;
        } elseif (is_string($field_value) && $field_value !== '') {
            $decoded = json_decode($field_value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $current_files = $decoded;
            } else {
                // 兼容逗号分隔ID字符串或单个ID，转换为带id键的数组
                $ids = strpos($field_value, ',') !== false ? explode(',', $field_value) : array($field_value);
                $ids = array_values(array_filter(array_map('trim', $ids), function($v){ return $v !== ''; }));
                $current_files = array_map(function($id){ return array('id' => is_numeric($id) ? intval($id) : $id); }, $ids);
            }
        }
        
        // 补充文件信息（URL、标题等）
        $enriched_files = array();
        foreach ($current_files as $file_data) {
            $file_id = isset($file_data['id']) ? $file_data['id'] : 0;
            $numeric_id = is_numeric($file_id) ? intval($file_id) : 0;
            if ($numeric_id > 0) {
                $media = new CMS_Media($numeric_id);
                if ($media->exists()) {
                    $enriched_files[] = array(
                        'id' => $numeric_id,
                        'url' => $media->get_url(),
                        'title' => $media->get_title() ?: $media->get_filename(),
                        'alt' => $media->get_alt() ?: $media->get_title() ?: $media->get_filename(),
                        'filename' => $media->get_filename(),
                        'mime_type' => $media->get_mime_type(),
                        'index' => isset($file_data['index']) ? $file_data['index'] : count($enriched_files)
                    );
                }
            } elseif (isset($file_data['url']) && $file_data['url'] !== '') {
                // 非数字ID（如MD5哈希）或数据已包含URL，直接使用原始数据
                $enriched_files[] = array(
                    'id' => $file_id,
                    'url' => $file_data['url'],
                    'title' => isset($file_data['title']) ? $file_data['title'] : '',
                    'alt' => isset($file_data['alt']) ? $file_data['alt'] : (isset($file_data['title']) ? $file_data['title'] : ''),
                    'filename' => isset($file_data['filename']) ? $file_data['filename'] : '',
                    'mime_type' => isset($file_data['mime_type']) ? $file_data['mime_type'] : '',
                    'index' => isset($file_data['index']) ? $file_data['index'] : count($enriched_files)
                );
            }
        }
        $current_files = $enriched_files;

        // 映射到特色图像组件配置
        // 使用universal变体支持所有文件类型
        $component_config = array(
            'field_name' => isset($this->field['name']) ? $this->field['name'] : ('model_field_' . $field_key),
            'field_id' => isset($this->field['id']) ? $this->field['id'] : ('custom-field-' . $field_key),
            'multiple' => ($max_files !== 1),
            'max_images' => $max_files,
            'current_images' => $current_files,
            'sortable' => ($max_files !== 1),
            'file_type' => $file_type,
            'variant' => 'universal', // 通用变体，支持所有文件类型
            'show_preview' => true,
            'enable_debug' => false,
            'modal_id' => 'media-modal-' . $field_key
        );

        // 静态渲染特色图像组件（自动处理JS/CSS资源加载）
        $html = TodoFeaturedImagesComponent::renderStatic($component_config);
    
        // 使用基类的wrap_field方法包装
        return $this->wrap_field($html);
    }

    /**
     * 检查特色图像组件是否可用
     *
     * @return bool
     */
    private function is_component_available() {
        return class_exists('TodoFeaturedImagesComponent');
    }
    
    /**
     * 验证字段值
     *
     * @param mixed $value 字段值
     * @return bool|string 验证结果，true表示通过，字符串表示错误信息
     */
    public function validate($value) {
        // 从字段options中解析配置
        $opts = isset($this->field['options']) ? (is_array($this->field['options']) ? $this->field['options'] : (json_decode($this->field['options'], true) ?: [])) : [];
        $file_type = $opts['file_type'] ?? 'any';
        $type_name = $file_type === 'image' ? '图片' : '文件';
        
        // 必填验证
        if (($this->field['is_required'] ?? false) && empty($value)) {
            return "请选择{$type_name}";
        }
        
        // 如果值为空，跳过其他验证
        if (empty($value)) {
            return true;
        }
        
        // 解析文件ID（支持JSON数组、数组对象、逗号分隔）
        $file_ids = array();
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                $file_ids = array();
            } elseif (strpos($value, ',') !== false) {
                $file_ids = explode(',', $value);
            } else {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    // 如果是对象数组，提取id
                    if (!empty($decoded) && isset($decoded[0]) && is_array($decoded[0]) && isset($decoded[0]['id'])) {
                        $file_ids = array_column($decoded, 'id');
                    } else {
                        $file_ids = $decoded;
                    }
                } else {
                    $file_ids = array($value);
                }
            }
        } elseif (is_array($value)) {
            // 如果是对象数组，提取id
            if (!empty($value) && isset($value[0]) && is_array($value[0]) && isset($value[0]['id'])) {
                $file_ids = array_column($value, 'id');
            } else {
                $file_ids = $value;
            }
        }
        
        // 验证文件数量（从options读取，0或空表示不限制）
        $max_files = isset($opts['max_files']) ? intval($opts['max_files']) : 0;
        if ($max_files > 0 && count($file_ids) > $max_files) {
            return "最多只能选择 {$max_files} 个{$type_name}";
        }
        
        // 验证每个文件ID
        foreach ($file_ids as $file_id) {
            $file_id = trim($file_id);
            if (empty($file_id)) {
                continue;
            }
            
            if (!is_numeric($file_id)) {
                return "{$type_name}ID格式不正确";
            }
            
            $media = new CMS_Media($file_id);
            if (!$media->exists()) {
                return "{$type_name} ID {$file_id} 不存在";
            }
            
            // 根据字段配置验证文件类型
            if ($file_type !== 'any') {
                $mime_type = $media->get_mime_type();
                $valid = false;
                
                switch ($file_type) {
                    case 'image':
                        $valid = strpos($mime_type, 'image/') === 0;
                        break;
                    case 'video':
                        $valid = strpos($mime_type, 'video/') === 0;
                        break;
                    case 'audio':
                        $valid = strpos($mime_type, 'audio/') === 0;
                        break;
                    case 'document':
                        $valid = in_array($mime_type, [
                            'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                            'text/plain', 'text/rtf'
                        ]);
                        break;
                    case 'archive':
                        $valid = in_array($mime_type, [
                            'application/zip', 'application/x-zip-compressed', 'application/x-rar-compressed', 'application/x-7z-compressed'
                        ]);
                        break;
                }
                
                if (!$valid) {
                    return "文件 {$media->get_filename()} 不是有效的{$type_name}格式";
                }
            }
        }
        
        return true;
    }
    
    /**
     * 自定义格式化（重写基类方法）
     *
     * @param mixed $value 字段值
     * @return string 格式化后的值
     */
    protected function custom_format($value) {
        return $this->format_save_value($value);
    }
    
    /**
     * 格式化保存值
     *
     * @param mixed $value 原始值
     * @return string 格式化后的值
     */
    public function format_save_value($value) {
        if (empty($value)) {
            return '';
        }
        
        // 解析原始结构
        $items = array();
        $is_object_list = false;

        if (is_string($value)) {
            $value = trim($value);
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $items = $decoded;
                $is_object_list = true;
            } elseif (strpos($value, ',') !== false) {
                $items = explode(',', $value);
            } else {
                $items = array($value);
            }
        } elseif (is_array($value)) {
            $items = $value;
            if (isset($value[0]) && is_array($value[0])) {
                $is_object_list = true;
            }
        }

        // 如果包含对象数组结构（例如包含 url/id/title 等）
        if ($is_object_list && !empty($items)) {
            $normalized_items = array();
            foreach ($items as $item) {
                if (is_array($item)) {
                    $norm_item = $item;
                    if (isset($norm_item['url']) && is_string($norm_item['url']) && $norm_item['url'] !== '') {
                        if (function_exists('todo_media_url_to_relative')) {
                            $norm_item['url'] = todo_media_url_to_relative($norm_item['url']);
                        }
                    }
                    $normalized_items[] = $norm_item;
                } elseif (is_numeric($item)) {
                    $normalized_items[] = array('id' => intval($item));
                }
            }
            return !empty($normalized_items) ? json_encode($normalized_items, JSON_UNESCAPED_UNICODE) : '';
        }

        // 纯数字ID列表处理
        $clean_ids = array();
        foreach ($items as $id) {
            if (is_array($id) && isset($id['id'])) {
                $id = $id['id'];
            }
            $id = is_string($id) ? trim($id) : $id;
            if (!empty($id) && is_numeric($id)) {
                $clean_ids[] = intval($id);
            }
        }
        
        // 去重并保持顺序
        $clean_ids = array_values(array_unique($clean_ids, SORT_NUMERIC));
        
        // 返回逗号分隔的字符串
        return implode(',', $clean_ids);
    }
    
    /**
     * 获取配置字段
     *
     * @return array 配置字段数组
     */
    public static function get_config_fields() {
        return array(
            array(
                'key' => 'file_type',
                'title' => '文件类型',
                'type' => 'select',
                'default' => 'any',
                'options' => array(
                    'any' => '所有文件',
                    'image' => '图片文件',
                    'video' => '视频文件',
                    'audio' => '音频文件',
                    'document' => '文档文件',
                    'archive' => '压缩文件'
                ),
                'description' => '限制可选择的文件类型'
            ),
            array(
                'key' => 'max_files',
                'title' => '最大文件数量',
                'type' => 'number',
                'default' => 20,
                'description' => '设置为1表示单文件模式，大于1表示多文件模式',
                'min' => 1,
                'max' => 100
            ),
            array(
                'key' => 'field_description',
                'title' => '字段说明',
                'type' => 'textarea',
                'default' => '',
                'description' => '显示在字段下方的说明文字'
            )
        );
    }
}