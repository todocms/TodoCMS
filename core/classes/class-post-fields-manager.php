<?php
/**
 * Post Fields Manager - 统一字段管理接口
 * 负责所有自定义字段的保存、加载和验证
 * 作为TodoCMS字段管理的主要接口
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class Todo_Post_Fields_Manager
{

    /**
     * 单例实例
     */
    private static $instance = null;

    private static $runtime_model_custom_fields = [];

    private static $runtime_field_exists = [];

    /**
     * 获取单例实例
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 保存文章字段
     * @param int $post_id 文章ID
     * @param array $field_data 字段数据
     * @return bool 保存结果
     */
    public function save_post_fields($post_id, $field_data)
    {
        if (empty($post_id) || empty($field_data)) {
            return false;
        }

        global $tododb;

        try {
            // 预处理语句，避免字符串拼接
            $sql = "INSERT INTO " . $tododb->prefix . "post_custom_fields (post_id, field_key, field_value)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)";

            foreach ($field_data as $field_key => $field_value) {
                $tododb->query($sql, [
                    intval($post_id),
                    $field_key,
                    maybe_serialize($field_value)
                ]);
            }

            return true;
        } catch (Exception $e) {
            error_log('保存文章字段失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取文章字段值（仅返回真正的自定义字段）
     * @param int $post_id 文章ID
     * @return array 字段值数组
     */
    public function get_post_fields_values($post_id)
    {
        if (empty($post_id)) {
            return array();
        }

        $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
        $cache_key = 'post_fields_values_' . (int) $post_id;
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get($cache_key, 'post_fields', $lang);
            if ($cached !== false) {
                return $cached;
            }
        }

        global $tododb;

        // 获取文章的模型ID进行智能判断
        $model_id = $this->get_post_model_id($post_id);

        try {
            $sql = "SELECT field_key, field_value FROM " . $tododb->prefix . "post_custom_fields WHERE post_id = ?";
            $meta_data = $tododb->get_results($sql, [$post_id]);

            $field_values = array();
            if ($meta_data) {
                foreach ($meta_data as $meta) {
                    // 处理对象和数组两种格式
                    $field_key = is_object($meta) ? $meta->field_key : $meta['field_key'];
                    $field_value = is_object($meta) ? $meta->field_value : $meta['field_value'];

                    // 排除系统保留前缀
                    if (strpos($field_key, '_todo_') === 0 || strpos($field_key, '_system_') === 0 || strpos($field_key, '_csrf_') === 0) {
                        continue;
                    }

                    $clean_key = strpos($field_key, 'model_field_') === 0 ? str_replace('model_field_', '', $field_key) : $field_key;
                    $val = maybe_unserialize($field_value);
                    if (function_exists('todo_map_inline_translations_deep')) {
                        $val = todo_map_inline_translations_deep($val);
                    }
                    $field_values[$clean_key] = $val;
                    if ($clean_key !== $field_key) {
                        $field_values[$field_key] = $val;
                    }
                }
            }

            // 补全特定数据模型专属固定字段表（如 todo_model_fields_article / todo_model_fields_product）
            $model_type = $this->get_post_model_type($post_id);
            if ($model_type && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $model_type)) {
                $m_table = $tododb->prefix . 'model_fields_' . $model_type;
                if ($tododb->table_exists($m_table)) {
                    $m_row = $tododb->get_row($tododb->prepare("SELECT * FROM {$m_table} WHERE post_id = %d LIMIT 1", $post_id));
                    if (is_object($m_row)) {
                        $m_row = (array) $m_row;
                    }
                    if (is_array($m_row)) {
                        foreach ($m_row as $mk => $mv) {
                            if ($mk !== 'field_id' && $mk !== 'post_id' && $mk !== 'created_at' && $mk !== 'updated_at') {
                                if (!isset($field_values[$mk]) || $field_values[$mk] === '' || $field_values[$mk] === null) {
                                    $val = maybe_unserialize($mv);
                                    if (function_exists('todo_map_inline_translations_deep')) {
                                        $val = todo_map_inline_translations_deep($val);
                                    }
                                    $field_values[$mk] = $val;
                                }
                            }
                        }
                    }
                }
            }

            if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set($cache_key, $field_values, $ttl, 'post_fields', $lang);
            }
            return $field_values;
        } catch (Exception $e) {
            error_log('获取文章字段值失败: ' . $e->getMessage());
            if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set($cache_key, array(), $ttl, 'post_fields', $lang);
            }
            return array();
        }
    }
    /**
     * 验证字段数据
     * @param array $data 字段数据
     * @param array $fields 字段配置数组
     * @return array 验证结果
     */
    public function validate_fields($data, $fields)
    {
        $errors = array();

        foreach ($fields as $field) {
            // 确保 $field 是数组格式
            if (!is_array($field)) {
                continue;
            }

            // 检查必需的字段键是否存在
            if (!isset($field['field_key']) || !isset($field['field_name'])) {
                continue;
            }

            $field_key = $field['field_key'];
            $value = isset($data[$field_key]) ? $data[$field_key] : '';

            // 检查必填字段
            if (isset($field['is_required']) && $field['is_required'] && empty($value)) {
                $errors[$field_key] = $field['field_name'] . ' 不能为空';
                continue;
            }

            // 检查字段长度
            $field_options = isset($field['field_options']) ? $field['field_options'] : '';
            $options = json_decode($field_options, true) ?: array();
            if (isset($options['max_length']) && mb_strlen($value) > $options['max_length']) {
                $errors[$field_key] = $field['field_name'] . ' 长度不能超过 ' . $options['max_length'] . ' 个字符';
            }
        }

        return array(
            'success' => empty($errors),
            'errors' => $errors
        );
    }

    /**
     * 统一的自定义字段保存接口
     * 支持多种调用方式：post_id+model_id, content_id+model_key
     * @param mixed $identifier 文章ID或内容ID
     * @param mixed $model_identifier 模型ID或模型标识
     * @param array $custom_fields 自定义字段数据
     * @param string $save_type 保存类型：'post'(默认) 或 'content'
     * @return bool
     */
    public function save_custom_fields($identifier, $model_identifier, $custom_fields, $save_type = 'post')
    {
        if (empty($identifier) || empty($custom_fields)) {
            return true; // 没有自定义字段时返回成功
        }

        // 根据保存类型调用不同的处理方法
        switch ($save_type) {
            case 'content':
                return $this->save_content_custom_fields($identifier, $model_identifier, $custom_fields);
            case 'post':
            default:
                return $this->save_post_custom_fields($identifier, $model_identifier, $custom_fields);
        }
    }

    /**
     * 保存文章自定义字段到post_custom_fields表
     * @param int $post_id 文章ID
     * @param int $model_id 模型ID
     * @param array $custom_fields 自定义字段数据
     * @return bool
     */
    private function save_post_custom_fields($post_id, $model_id, $custom_fields)
    {
        global $tododb;

        // 使用智能化过滤，传入模型ID进行精确判断
        $filtered_fields = $this->filter_custom_fields($custom_fields, $model_id);

        if (empty($filtered_fields)) {
            return true; // 没有有效的自定义字段
        }

        try {
            // 开始事务
            $tododb->begin_transaction();

            foreach ($filtered_fields as $field_key => $field_value) {
                // 清理字段键 - 移除model_field_前缀
                $clean_field_key = str_replace('model_field_', '', $field_key);
                // 兼容旧的custom_field_前缀
                $clean_field_key = str_replace('custom_field_', '', $clean_field_key);

                // 再次检查是否为自定义字段
                if (!$this->is_custom_field($clean_field_key, $model_id)) {
                    continue; // 跳过非自定义字段
                }

                // 获取字段类型（从model_fields表）
                $field_type = $this->get_custom_field_type($model_id, $clean_field_key);

                // 处理字段值
                $processed_value = $this->process_custom_field_value($field_value, $field_type);

                // 检查字段值是否为空
                if (empty($processed_value) && $processed_value !== '0') {
                    // 如果字段值为空，删除对应记录（参数化）
                    $delete_sql = "DELETE FROM " . $tododb->prefix . "post_custom_fields 
                                   WHERE post_id = ? 
                                   AND model_id = ? 
                                   AND field_key = ?";
                    $result = $tododb->query($delete_sql, [intval($post_id), intval($model_id), $clean_field_key]);

                    if ($result === false) {
                        throw new Exception("删除空自定义字段失败: {$clean_field_key}");
                    }
                } else {
                    // 保存到post_custom_fields表（参数化）
                    $sql = "INSERT INTO " . $tododb->prefix . "post_custom_fields 
                            (post_id, model_id, field_key, field_value, field_type) 
                            VALUES (?, ?, ?, ?, ?) 
                            ON DUPLICATE KEY UPDATE 
                            field_value = VALUES(field_value),
                            field_type = VALUES(field_type),
                            updated_at = CURRENT_TIMESTAMP";
                    $result = $tododb->query($sql, [
                        intval($post_id),
                        intval($model_id),
                        $clean_field_key,
                        $processed_value,
                        $field_type
                    ]);

                    if ($result === false) {
                        throw new Exception("保存自定义字段失败: {$clean_field_key}");
                    }
                }
            }

            // 提交事务
            $tododb->commit();
            return true;

        } catch (Exception $e) {
            // 回滚事务
            $tododb->rollback();
            error_log('自定义字段保存失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 保存内容自定义字段到custom_field_values表
     * @param int $content_id 内容ID
     * @param string $model_key 模型标识
     * @param array $custom_fields 自定义字段数据
     * @return bool
     */
    private function save_content_custom_fields($content_id, $model_key, $custom_fields)
    {
        global $tododb;

        try {
            // 开始事务
            $tododb->begin_transaction();

            foreach ($custom_fields as $field_name => $field_value) {
                // 处理字段值
                $processed_value = is_array($field_value) ? json_encode($field_value) : $field_value;

                // 检查字段值是否为空
                if (empty($processed_value) && $processed_value !== '0') {
                    // 如果字段值为空，删除对应记录
                    $delete_sql = "DELETE FROM " . $tododb->prefix . "custom_field_values 
                                   WHERE content_id = ? 
                                   AND model_key = ? 
                                   AND field_name = ?";
                    $result = $tododb->query($delete_sql, [intval($content_id), $model_key, $field_name]);

                    if ($result === false) {
                        throw new Exception("删除空内容自定义字段失败: {$field_name}");
                    }
                } else {
                    // 保存到custom_field_values表
                    $sql = "INSERT INTO " . $tododb->prefix . "custom_field_values 
                            (content_id, model_key, field_name, field_value, created_at) 
                            VALUES (?, ?, ?, ?, NOW()) 
                            ON DUPLICATE KEY UPDATE 
                            field_value = VALUES(field_value), 
                            updated_at = NOW()";
                    $result = $tododb->query($sql, [intval($content_id), $model_key, $field_name, $processed_value]);

                    if ($result === false) {
                        throw new Exception("保存内容自定义字段失败: {$field_name}");
                    }
                }
            }

            // 提交事务
            $tododb->commit();
            return true;

        } catch (Exception $e) {
            // 回滚事务
            $tododb->rollback();
            error_log('内容自定义字段保存失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取自定义字段类型
     * @param int $model_id 模型ID
     * @param string $field_key 字段键
     * @return string 字段类型
     */
    private function get_custom_field_type($model_id, $field_key)
    {
        global $tododb;

        try {
            $sql = "SELECT field_type FROM " . $tododb->prefix . "model_fields 
                    WHERE model_id = ? AND field_key = ?";

            $result = $tododb->get_var($sql, [intval($model_id), $field_key]);

            return $result ? $result : 'text';

        } catch (Exception $e) {
            error_log('获取自定义字段类型失败: ' . $e->getMessage());
            return 'text';
        }
    }

    /**
     * 处理自定义字段值
     * @param mixed $value 字段值
     * @param string $field_type 字段类型
     * @return string 处理后的值
     */
    private function process_custom_field_value($value, $field_type)
    {
        switch ($field_type) {
            case 'checkbox':
            case 'multi_select':
            case 'multi_file':
            case 'image':
            case 'file':
            case 'video':
            case 'attachment':
                if (is_array($value)) {
                    if (function_exists('todo_media_url_to_relative')) {
                        array_walk_recursive($value, function (&$v) {
                            if (is_string($v) && $v !== '') {
                                $v = todo_media_url_to_relative($v);
                            }
                        });
                    }
                    return json_encode($value, JSON_UNESCAPED_UNICODE);
                } elseif (is_string($value) && $value !== '' && function_exists('todo_media_url_to_relative')) {
                    $trimmed = trim($value);
                    if (strpos($trimmed, '[') === 0 || strpos($trimmed, '{') === 0) {
                        $decoded = json_decode($trimmed, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            array_walk_recursive($decoded, function (&$v) {
                                if (is_string($v) && $v !== '') {
                                    $v = todo_media_url_to_relative($v);
                                }
                            });
                            return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        }
                    }
                    return todo_media_url_to_relative($trimmed);
                }
                return $value;

            case 'number':
            case 'decimal':
            case 'money':
                return is_numeric($value) ? $value : '0';

            case 'switch':
                return $value ? '1' : '0';

            case 'datetime':
                return !empty($value) ? date('Y-m-d H:i:s', strtotime($value)) : '';

            case 'html':
            case 'editor':
            case 'rich_editor':
                return todo_kses_post($value);

            case 'textarea':
                return sanitize_textarea_field($value);

            default:
                return sanitize_text_field($value);
        }
    }

    /**
     * 统一的字段获取接口
     * @param mixed $identifier 文章ID或内容ID
     * @param mixed $model_identifier 模型ID或模型标识
     * @param string $get_type 获取类型：'post'(默认) 或 'content'
     * @return array 字段值数组
     */
    public function get_custom_fields($identifier, $model_identifier = null, $get_type = 'post')
    {
        switch ($get_type) {
            case 'content':
                return $this->get_content_custom_fields($identifier, $model_identifier);
            case 'post':
            default:
                return $this->get_post_fields_values($identifier);
        }
    }

    /**
     * 获取内容自定义字段
     * @param int $content_id 内容ID
     * @param string $model_key 模型标识
     * @return array 字段值数组
     */
    private function get_content_custom_fields($content_id, $model_key)
    {
        global $tododb;

        try {
            $sql = "SELECT field_name, field_value FROM " . $tododb->prefix . "custom_field_values 
                    WHERE content_id = ? AND model_key = ?";

            $results = $tododb->get_results($sql, [intval($content_id), $model_key]);
            $custom_fields = array();

            if ($results) {
                foreach ($results as $row) {
                    $field_name = is_object($row) ? $row->field_name : $row['field_name'];
                    $field_value = is_object($row) ? $row->field_value : $row['field_value'];

                    // 尝试解析JSON值
                    $decoded_value = json_decode($field_value, true);
                    $custom_fields[$field_name] = $decoded_value !== null ? $decoded_value : $field_value;
                }
            }

            return $custom_fields;

        } catch (Exception $e) {
            error_log('获取内容自定义字段失败: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * 验证字段权限
     * @param int $user_id 用户ID
     * @param string $action 操作类型
     * @return bool
     */
    public function validate_field_permission($user_id, $action = 'edit')
    {
        // 实现字段权限验证逻辑
        return current_user_can('edit_posts');
    }

    /**
     * 获取文章的模型ID
     * 从 post-custom-fields-fix.php 合并而来
     * 
     * @param int $post_id 文章ID
     * @return int|null 模型ID
     */
    public function get_post_model_id($post_id)
    {
        global $tododb;

        if (empty($post_id)) {
            return null;
        }

        // Persistent cache using Todo_Cache_Manager (works across requests)
        $cache_key = 'post_model_id_' . (int) $post_id;
        $ttl = function_exists('todo_get_fragment_cache_ttl') ? (int) todo_get_fragment_cache_ttl() : 120;
        $lang = function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : null);
        if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get($cache_key, 'models', $lang);
            if ($cached !== false) {
                return $cached;
            }
        }

        try {
            // 1) 优先：直接从 posts.model_id 读取
            $post_sql = "SELECT model_id, category_id FROM {$tododb->prefix}posts WHERE ID = ?";
            $post = $tododb->get_row($post_sql, [$post_id]);
            if ($post && !empty($post->model_id)) {
                $result = intval($post->model_id);
                if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
                    Todo_Cache_Manager::set($cache_key, $result, $ttl, 'models', $lang);
                }
                return $result;
            }

            // 2) 回退：从 term_models 映射（规范表）读取模型ID
            $category_id = ($post && !empty($post->category_id)) ? intval($post->category_id) : 0;
            if ($category_id > 0) {
                $term_sql = "SELECT m.model_id FROM {$tododb->prefix}term_models tm LEFT JOIN {$tododb->prefix}models m ON tm.model_id = m.model_id WHERE tm.term_id = ? LIMIT 1";
                $mid = $tododb->get_var($term_sql, [$category_id]);
                if (!empty($mid)) {
                    $result = intval($mid);
                    if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
                        Todo_Cache_Manager::set($cache_key, $result, $ttl, 'models', $lang);
                    }
                    return $result;
                }
            }

            if ($ttl !== 0 && class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set($cache_key, null, $ttl, 'models', $lang);
            }
            return null;

        } catch (Exception $e) {
            if (function_exists('todo_log_error')) {
                todo_log_error('获取文章模型ID失败: ' . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * 获取文章的数据模型类型
     * 从 post-custom-fields-fix.php 合并而来
     *
     * @param int $post_id 文章ID
     * @return string|null 模型类型（如：article, product, images等）
     */
    public function get_post_model_type($post_id)
    {
        global $tododb;

        if (empty($post_id)) {
            return null;
        }

        try {
            // 1) 直接从文章表读取类型/模型
            $post_sql = "SELECT post_type, model_id, category_id FROM {$tododb->prefix}posts WHERE ID = ?";
            $post = $tododb->get_row($post_sql, [$post_id]);

            if ($post) {
                // a) 优先使用显式的 post_type
                if (!empty($post->post_type)) {
                    return trim($post->post_type);
                }

                // b) 其次使用文章自身的 model_id 映射到 models.model_type
                $post_model_id = isset($post->model_id) ? intval($post->model_id) : 0;
                if ($post_model_id > 0) {
                    $type_sql = "SELECT model_type FROM {$tododb->prefix}models WHERE model_id = ?";
                    $type_val = $tododb->get_var($type_sql, [$post_model_id]);
                    if (!empty($type_val)) {
                        return $type_val;
                    }
                }
            }

            // 2) 回退：根据栏目绑定关系获取模型ID → 再到 models 表取类型
            $model_id = null;
            $category_id = ($post && isset($post->category_id)) ? intval($post->category_id) : 0;

            if ($category_id > 0) {
                if (!$model_id) {
                    $term_sql = "SELECT model_id FROM {$tododb->prefix}term_models WHERE term_id = ?";
                    $term_row = $tododb->get_row($term_sql, [$category_id]);
                    if ($term_row && !empty($term_row->model_id)) {
                        $model_id = intval($term_row->model_id);
                    }
                }
            }

            if ($model_id) {
                $sql = "SELECT model_type FROM {$tododb->prefix}models WHERE model_id = ?";
                $result = $tododb->get_var($sql, [$model_id]);
                if (!empty($result)) {
                    return $result;
                }
            }

            // 3) 最终回退：未知类型
            return null;
        } catch (Exception $e) {
            if (function_exists('todo_log_error')) {
                todo_log_error('获取文章模型类型失败: ' . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * 获取模型的自定义字段列表
     * 从 post-custom-fields-fix.php 合并而来
     * 
     * @param int|string $model_id 模型ID或模型标识
     * @return array 自定义字段列表
     */
    public function get_model_custom_fields($model_id)
    {
        global $tododb;

        if (empty($model_id)) {
            return array();
        }

        if (isset(self::$runtime_model_custom_fields[$model_id])) {
            return self::$runtime_model_custom_fields[$model_id];
        }

        // 缓存键
        $cache_key = 'model_custom_fields_' . $model_id;

        // 尝试从缓存获取（优先使用统一缓存API）
        $cached_fields = false;
        if (function_exists('todo_cache_get')) {
            $cached_fields = todo_cache_get($cache_key, 'models');
            if ($cached_fields !== false) {
                return $cached_fields;
            }
        }

        try {
            // 如果是数字，直接作为ID查询
            if (is_numeric($model_id)) {
                $sql = "SELECT field_key FROM {$tododb->prefix}model_fields WHERE model_id = ?";
                $results = $tododb->get_results($sql, [$model_id]);
            } else {
                // 如果是字符串，先查询模型ID
                $model_sql = "SELECT model_id FROM {$tododb->prefix}models WHERE model_key = ?";
                $model_row = $tododb->get_row($model_sql, [$model_id]);

                if (!$model_row) {
                    return array();
                }

                $sql = "SELECT field_key FROM {$tododb->prefix}model_fields WHERE model_id = ?";
                $results = $tododb->get_results($sql, [$model_row->model_id]);
            }

            $custom_fields = array();
            if ($results) {
                foreach ($results as $row) {
                    $custom_fields[] = is_object($row) ? $row->field_key : $row['field_key'];
                }
            }

            // 缓存结果（缓存5分钟）
            if (function_exists('todo_cache_set')) {
                todo_cache_set($cache_key, $custom_fields, 300, 'models');
            }

            self::$runtime_model_custom_fields[$model_id] = $custom_fields;
            return $custom_fields;

        } catch (Exception $e) {
            if (function_exists('todo_log_error')) {
                todo_log_error('获取模型自定义字段失败: ' . $e->getMessage());
            }
            return array();
        }
    }

    /**
     * 智能判断是否为自定义字段
     * 从 post-custom-fields-fix.php 合并而来
     * 
     * @param string $field_key 字段键名
     * @param int|string $model_id 模型ID或模型标识
     * @return bool 是否为自定义字段
     */
    public function is_custom_field($field_key, $model_id = null)
    {
        // 清理字段键 - 移除model_field_前缀以获取真实字段名
        $clean_field_key = $field_key;
        if (strpos($field_key, 'model_field_') === 0) {
            $clean_field_key = str_replace('model_field_', '', $field_key);
        }

        // 特殊meta字段白名单 - 这些字段虽然不在model_fields表中，但应该被允许保存
        $allowed_meta_fields = array(
            '_custom_fields',      // 自定义字段数据
            '_seo_data',           // SEO数据
            '_post_settings'      // 文章设置
        );

        // 如果是白名单中的字段，直接返回true
        if (in_array($clean_field_key, $allowed_meta_fields)) {
            return true;
        }

        // 检查是否以系统前缀开头
        $system_prefixes = array(
            '_todo_',      // TodoCMS系统前缀
            '_system_',    // 系统前缀
            '_internal_',  // 内部前缀
            '_csrf_',      // CSRF令牌前缀
            '_todononce',      // Nonce前缀
        );

        foreach ($system_prefixes as $prefix) {
            if (strpos($clean_field_key, $prefix) === 0) {
                return false;
            }
        }

        // 只有在 todo_model_fields 表中定义的字段才是自定义字段
        if ($model_id) {
            if (isset(self::$runtime_model_custom_fields[$model_id])) {
                $model_custom_fields = self::$runtime_model_custom_fields[$model_id];
            } else {
                $model_custom_fields = $this->get_model_custom_fields($model_id);
                self::$runtime_model_custom_fields[$model_id] = $model_custom_fields;
            }
            return in_array($clean_field_key, $model_custom_fields);
        }

        // 如果没有提供模型ID，检查是否在所有模型的自定义字段中
        // 这样可以确保即使没有明确的模型ID，自定义字段也能被识别
        try {
            global $tododb;
            if (!$tododb) {
                return false;
            }

            if (isset(self::$runtime_field_exists[$clean_field_key])) {
                return self::$runtime_field_exists[$clean_field_key];
            }

            $sql = "SELECT COUNT(*) FROM {$tododb->prefix}model_fields WHERE field_key = ?";
            $count = $tododb->get_var($sql, [$clean_field_key]);
            $exists = $count > 0;
            self::$runtime_field_exists[$clean_field_key] = $exists;
            return $exists;

        } catch (Exception $e) {
            // 如果查询失败，返回false
            return false;
        }
    }

    /**
     * 智能过滤自定义字段
     * 从 post-custom-fields-fix.php 合并而来
     * 
     * @param array $fields 字段数组
     * @param int|string $model_id 模型ID或模型标识
     * @return array 过滤后的自定义字段数组
     */
    public function filter_custom_fields($fields, $model_id = null)
    {
        if (!is_array($fields)) {
            return array();
        }

        $custom_fields = array();

        foreach ($fields as $key => $value) {
            if ($this->is_custom_field($key, $model_id)) {
                $custom_fields[$key] = $value;
            }
        }

        return $custom_fields;
    }
}
