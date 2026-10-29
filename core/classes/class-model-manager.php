<?php
/**
 * 数据模型管理器
 *
 * 统一管理所有数据模型类的加载和调用
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class Todo_Model_Manager
{
    /**
     * 已注册的模型类
     *
     * @var array
     */
    private static $registered_models = [];

    /**
     * 模型类映射
     *
     * @var array
     */
    private static $model_classes = [
        'article' => 'CMS_Post_Type_Article',
        'product' => 'CMS_Post_Type_Product',
        'images' => 'CMS_Post_Type_Images',
        'download' => 'CMS_Post_Type_Download',
        'media' => 'CMS_Post_Type_Media',
        'single' => 'CMS_Post_Type_Single',
        'special' => 'CMS_Post_Type_Special',
        'guestbook' => 'CMS_Post_Type_Guestbook',
        'recruit' => 'CMS_Post_Type_Recruit'
    ];

    private static $category_model_cache = [];

    /**
     * 初始化模型管理器
     */
    public static function init()
    {
        self::load_model_classes();
        self::register_models();
    }

    /**
     * 加载所有模型类文件
     */
    private static function load_model_classes()
    {
        $posts_dir = CORE_DIR . '/classes/posts/';

        foreach (self::$model_classes as $model_key => $class_name) {
            $file_path = $posts_dir . 'class-post-type-' . $model_key . '.php';
            if (file_exists($file_path)) {
                require_once $file_path;
                continue;
            }
            $alt_path = $posts_dir . 'class-model-' . $model_key . '.php';
            if (file_exists($alt_path)) {
                require_once $alt_path;
            }
        }
    }

    /**
     * 注册所有模型
     */
    private static function register_models()
    {
        foreach (self::$model_classes as $model_key => $class_name) {
            if (class_exists($class_name)) {
                self::$registered_models[$model_key] = $class_name;
            }
        }
    }

    /**
     * 获取模型类
     *
     * @param string $model_key 模型标识
     * @return string|null 模型类名
     */
    public static function get_model_class($model_key)
    {
        // 首先检查是否为内置模型
        if (isset(self::$registered_models[$model_key])) {
            return self::$registered_models[$model_key];
        }

        // 检查是否为自定义模型
        if (self::is_custom_model($model_key)) {
            return 'CMS_Post_Type_Custom';
        }

        return null;
    }

    /**
     * 获取模型配置
     *
     * @param string $model_key 模型标识
     * @return array|null 模型配置
     */
    public static function get_model_config($model_key)
    {
        $class_name = self::get_model_class($model_key);
        if ($class_name && method_exists($class_name, 'get_model_config')) {
            return call_user_func([$class_name, 'get_model_config']);
        }
        return null;
    }

    /**
     * 获取模型字段
     *
     * @param string $model_key 模型标识
     * @return array 字段配置数组
     */
    public static function get_model_fields($model_key)
    {
        // 检查是否为自定义模型
        if (self::is_custom_model($model_key)) {
            $custom_instance = self::get_custom_model_instance($model_key);
            if ($custom_instance) {
                return $custom_instance->get_all_fields();
            }
            return [];
        }

        // 处理内置模型
        $class_name = self::get_model_class($model_key);
        if ($class_name && method_exists($class_name, 'get_default_fields')) {
            return call_user_func([$class_name, 'get_default_fields']);
        }
        return [];
    }

    /**
     * 验证模型字段数据
     *
     * @param string $model_key 模型标识
     * @param array $data 提交的数据
     * @return array 验证结果
     */
    public static function validate_model_fields($model_key, $data)
    {
        // 检查是否为自定义模型
        if (self::is_custom_model($model_key)) {
            $custom_instance = self::get_custom_model_instance($model_key);
            if ($custom_instance) {
                return $custom_instance->validate_fields($data);
            }
            return ['valid' => true, 'errors' => []];
        }

        // 处理内置模型
        $class_name = self::get_model_class($model_key);
        if ($class_name && method_exists($class_name, 'validate_fields')) {
            return call_user_func([$class_name, 'validate_fields'], $data);
        }
        return ['valid' => true, 'errors' => []];
    }

    /**
     * 保存模型字段数据
     *
     * @param string $model_key 模型标识
     * @param int $post_id 文章ID
     * @param array $data 字段数据
     * @return bool 保存是否成功
     */
    public static function save_model_fields($model_key, $post_id, $data)
    {
        // 检查是否为自定义模型
        if (self::is_custom_model($model_key)) {
            $custom_instance = self::get_custom_model_instance($model_key);
            if ($custom_instance) {
                return $custom_instance->save_fields($post_id, $data);
            }
            return false;
        }

        // 处理内置模型
        $class_name = self::get_model_class($model_key);
        if ($class_name && method_exists($class_name, 'save_fields')) {
            return call_user_func([$class_name, 'save_fields'], $post_id, $data);
        }
        return false;
    }

    /**
     * 获取模型字段值
     *
     * @param string $model_key 模型标识
     * @param int $post_id 文章ID
     * @return array 字段值数组
     */
    public static function get_model_field_values($model_key, $post_id)
    {
        // 检查是否为自定义模型
        if (self::is_custom_model($model_key)) {
            $custom_instance = self::get_custom_model_instance($model_key);
            if ($custom_instance) {
                // 合并固定字段和自定义字段的值
                $fixed_values = $custom_instance->get_fixed_field_values($post_id);
                $custom_values = $custom_instance->get_custom_field_values($post_id);
                return array_merge($fixed_values, $custom_values);
            }
            return [];
        }

        // 处理内置模型
        $class_name = self::get_model_class($model_key);
        if ($class_name && method_exists($class_name, 'get_field_values')) {
            return call_user_func([$class_name, 'get_field_values'], $post_id);
        }
        return [];
    }

    /**
     * 获取文章字段值
     *
     * @param int $post_id 文章ID
     * @param array $category_ids 栏目ID数组
     * @return array 字段值数组
     */
    public static function get_post_fields_values($post_id, $category_ids = [])
    {
        // 优先使用模型字段组件的函数
        if (function_exists('get_post_model_fields_values')) {
            return get_post_model_fields_values($post_id, $category_ids);
        }

        // 回退到字段管理器（TodoCMS风格）
        $fields_manager = Todo_Post_Fields_Manager::get_instance();
        return $fields_manager->get_post_fields_values($post_id);
    }

    /**
     * 获取模型信息
     *
     * @param string $model_key 模型标识
     * @return array|null 模型信息
     */
    public static function get_model($model_key)
    {
        return self::get_model_config($model_key);
    }

    /**
     * 获取所有已注册的模型
     *
     * @return array 模型列表
     */
    public static function get_all_models()
    {
        $models = [];
        foreach (self::$registered_models as $model_key => $class_name) {
            $config = self::get_model_config($model_key);
            if ($config) {
                $models[$model_key] = $config;
            }
        }
        return $models;
    }

    /**
     * 检查模型是否存在
     *
     * @param string $model_key 模型标识
     * @return bool
     */
    public static function model_exists($model_key)
    {
        return isset(self::$registered_models[$model_key]);
    }

    /**
     * 根据模型标识获取模型ID
     *
     * @param string $model_key 模型标识
     * @return int|null 模型ID，如果不存在返回null
     */
    public static function get_model_id_by_key($model_key)
    {
        global $tododb;

        if (empty($model_key)) {
            return null;
        }

        // Persistent cache using Todo_Cache_Manager
        $cache_key = 'model_id_by_key_' . $model_key;
        if (class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get($cache_key, 'models');
            if ($cached !== false) {
                return $cached;
            }
        }

        // 查询数据库获取模型ID
        $sql = "SELECT model_id FROM " . TABLE_PREFIX . "models WHERE model_key = ?";
        $model_id_val = $tododb->get_var($sql, [$model_key]);
        $result = $model_id_val !== null ? (int) $model_id_val : null;

        if (class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::set($cache_key, $result, 300, 'models');
        }
        return $result;
    }

    /**
     * 检查是否为自定义模型
     *
     * @param string $model_key 模型标识
     * @return bool
     */
    public static function is_custom_model($model_key)
    {
        global $tododb;

        if (empty($model_key)) {
            return false;
        }

        // 如果是内置模型，返回false
        if (isset(self::$model_classes[$model_key])) {
            return false;
        }

        // 查询数据库检查是否存在该自定义模型
        try {
            $sql = "SELECT model_id FROM " . TABLE_PREFIX . "models WHERE model_key = ? AND is_system = 0";
            $result = $tododb->get_row($sql, [$model_key]);
            return !empty($result);
        } catch (Exception $e) {
            error_log('检查自定义模型失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取自定义模型实例
     *
     * @param string $model_key 模型标识
     * @return CMS_Post_Type_Custom|null
     */
    public static function get_custom_model_instance($model_key)
    {
        if (!self::is_custom_model($model_key)) {
            return null;
        }

        // 传递model_key而不是model_id，让基类自己处理
        return new CMS_Post_Type_Custom($model_key);
    }

    /**
     * 检查模型是否启用商城功能
     *
     * @param string $model_key 模型标识
     * @return bool
     */
    public static function is_commerce_model($model_key)
    {
        $class_name = self::get_model_class($model_key);
        if ($class_name && method_exists($class_name, 'is_commerce_enabled')) {
            return call_user_func([$class_name, 'is_commerce_enabled']);
        }
        return false;
    }

    /**
     * 获取商城模型配置
     *
     * @param string $model_key 模型标识
     * @return array
     */
    public static function get_commerce_config($model_key)
    {
        $class_name = self::get_model_class($model_key);
        if ($class_name && method_exists($class_name, 'get_commerce_config')) {
            return call_user_func([$class_name, 'get_commerce_config']);
        }
        return [];
    }

    /**
     * 根据栏目获取绑定的模型
     *
     * @param int $category_id 栏目ID
     * @return string|null 模型标识
     */
    public static function get_category_model($category_id)
    {
        global $tododb;

        $category_id = (int) $category_id;
        if ($category_id <= 0) {
            return 'article';
        }
        if (self::$category_model_cache === null || empty(self::$category_model_cache)) {
            if (self::$category_model_cache === null) {
                self::$category_model_cache = [];
            }
            if ($tododb && method_exists($tododb, 'table_exists') && $tododb->table_exists('term_models')) {
                $rows = $tododb->get_results("SELECT tm.term_id, m.model_key FROM {$tododb->prefix}term_models tm JOIN {$tododb->prefix}models m ON tm.model_id = m.model_id");
                if ($rows && is_array($rows)) {
                    foreach ($rows as $r) {
                        self::$category_model_cache[(int) $r->term_id] = $r->model_key;
                    }
                }
            }
        }

        if (isset(self::$category_model_cache[$category_id])) {
            return self::$category_model_cache[$category_id];
        }

        $sql = "SELECT m.model_key FROM {$tododb->prefix}term_models tm JOIN {$tododb->prefix}models m ON tm.model_id = m.model_id WHERE tm.term_id = ? LIMIT 1";
        $model_key = $tododb->get_var($sql, [$category_id]);

        // 如果没有找到，默认返回article模型
        $model_key = $model_key ?: 'article';
        self::$category_model_cache[$category_id] = $model_key;
        return $model_key;
    }

    /**
     * 批量获取栏目绑定的模型
     *
     * @param array $category_ids 栏目ID数组
     * @return array [category_id => model_key]
     */
    public static function get_category_models_batch($category_ids)
    {
        global $tododb;

        if (empty($category_ids)) {
            return [];
        }

        $category_ids = array_map('intval', $category_ids);
        $ids_str = implode(',', $category_ids);

        $sql = "SELECT tm.term_id, m.model_key 
                FROM {$tododb->prefix}term_models tm 
                JOIN {$tododb->prefix}models m ON tm.model_id = m.model_id 
                WHERE tm.term_id IN ($ids_str)";

        $results = $tododb->get_results($sql);

        $map = [];
        if ($results) {
            foreach ($results as $row) {
                $map[$row->term_id] = $row->model_key;
                self::$category_model_cache[(int) $row->term_id] = $row->model_key;
            }
        }

        // 对于未找到模型的栏目，默认返回 'article'
        foreach ($category_ids as $id) {
            if (!isset($map[$id])) {
                $map[$id] = 'article';
            }
            if (!isset(self::$category_model_cache[(int) $id])) {
                self::$category_model_cache[(int) $id] = $map[$id];
            }
        }

        return $map;
    }

    /**
     * 获取使用指定模型的栏目列表
     *
     * @param string $model_key 模型标识
     * @return array 栏目列表
     */
    public static function get_model_categories($model_key)
    {
        global $tododb;

        // 从terms表联合查询获取使用指定模型的栏目
        $sql = "SELECT t.term_id, t.name, t.slug, tt.description, tt.parent, tt.count
             FROM {$tododb->prefix}terms t
             LEFT JOIN {$tododb->prefix}term_taxonomy tt ON t.term_id = tt.term_id
             LEFT JOIN {$tododb->prefix}term_models tm ON t.term_id = tm.term_id
             LEFT JOIN {$tododb->prefix}models m ON tm.model_id = m.model_id
             WHERE m.model_key = ? AND tt.taxonomy = 'category'
             ORDER BY t.name ASC";
        $categories = $tododb->get_results($sql, [$model_key]);
        return $categories ?: [];
    }

    /**
     * 根据模型ID获取模型标识
     *
     * @param int $model_id 模型ID
     * @return string|null 模型标识，如果不存在返回null
     */
    public static function get_model_key_by_id($model_id)
    {
        global $tododb;

        if (empty($model_id)) {
            return null;
        }

        // Persistent cache using Todo_Cache_Manager
        $cache_key = 'model_key_by_id_' . (int) $model_id;
        if (class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get($cache_key, 'models');
            if ($cached !== false) {
                return $cached;
            }
        }

        // 查询数据库获取模型标识
        $sql = "SELECT model_key FROM " . TABLE_PREFIX . "models WHERE model_id = ?";
        $model_key_val = $tododb->get_var($sql, [$model_id]);
        $result = $model_key_val !== null ? $model_key_val : null;

        if (class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::set($cache_key, $result, 300, 'models');
        }
        return $result;
    }
}

// 初始化模型管理器
Todo_Model_Manager::init();
