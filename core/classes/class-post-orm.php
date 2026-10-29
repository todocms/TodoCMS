<?php
/**
 * Post ORM 模型
 * 
 * 优化的Post模型，支持批量加载meta和terms，避免N+1查询
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

require_once ABSPATH . 'core/classes/class-orm-base.php';

/**
 * Post ORM 模型
 */
class Todo_Post_ORM extends Todo_ORM_Base
{

    /**
     * 表名（不含前缀）
     * @var string
     */
    protected static $table = 'posts';

    /**
     * 主键字段名
     * @var string
     */
    protected static $primary_key = 'ID';

    /**
     * Meta数据缓存（批量加载）
     * @var array [post_id => [key => value]]
     */
    protected static $meta_cache = [];

    /**
     * Terms缓存（批量加载）
     * @var array [post_id => [taxonomy => [terms]]]
     */
    protected static $terms_cache = [];

    /**
     * 已批量加载的Post IDs
     * @var array
     */
    protected static $loaded_post_ids = [];

    /**
     * 构造函数
     * 
     * @param array $attributes 属性数据
     * @param bool $exists 是否已存在
     */
    public function __construct($attributes = [], $exists = false)
    {
        parent::__construct($attributes, $exists);

        // 自动加载meta和terms（如果已批量加载）
        if ($exists && $this->get_key()) {
            $this->load_cached_meta();
            $this->load_cached_terms();
        }
    }

    /**
     * 获取meta值
     * 
     * @param string $key Meta键名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get_meta($key, $default = null)
    {
        $post_id = $this->get_key();
        if (!$post_id) {
            return $default;
        }

        // 检查缓存中是否有该 key 的数据
        if (isset(self::$meta_cache[$post_id][$key])) {
            return self::$meta_cache[$post_id][$key];
        }

        // 如果未批量加载，则单个加载
        // 注意：需要检查是否真的已经加载过，而不仅仅是检查 key 是否存在
        // 因为 load_cached_meta() 可能会初始化一个空数组，但此时数据还未加载
        if (!in_array($post_id, self::$loaded_post_ids)) {
            $this->load_single_meta();
            // 标记为已加载（即使没有数据也要标记，避免重复查询）
            if (!in_array($post_id, self::$loaded_post_ids)) {
                self::$loaded_post_ids[] = $post_id;
            }
        }

        return isset(self::$meta_cache[$post_id][$key])
            ? self::$meta_cache[$post_id][$key]
            : $default;
    }

    /**
     * 获取所有meta
     * 
     * @return array
     */
    public function get_all_meta()
    {
        $post_id = $this->get_key();
        if (!$post_id) {
            return [];
        }

        // 如果未批量加载，则单个加载
        // 注意：需要检查是否真的已经加载过，而不仅仅是检查 key 是否存在
        // 因为 load_cached_meta() 可能会初始化一个空数组，但此时数据还未加载
        if (!in_array($post_id, self::$loaded_post_ids)) {
            $this->load_single_meta();
            // 标记为已加载（即使没有数据也要标记，避免重复查询）
            if (!in_array($post_id, self::$loaded_post_ids)) {
                self::$loaded_post_ids[] = $post_id;
            }
        }

        return isset(self::$meta_cache[$post_id])
            ? self::$meta_cache[$post_id]
            : [];
    }

    /**
     * 获取terms
     * 
     * @param string $taxonomy 分类法名称
     * @return array
     */
    public function get_terms($taxonomy = 'category')
    {
        $post_id = $this->get_key();
        if (!$post_id) {
            return [];
        }

        // 检查缓存中是否有该分类法的数据
        if (isset(self::$terms_cache[$post_id][$taxonomy])) {
            return self::$terms_cache[$post_id][$taxonomy];
        }

        // 如果未批量加载，则单个加载
        // 注意：需要检查是否真的已经加载过，而不仅仅是检查 key 是否存在
        // 因为 load_cached_terms() 可能会初始化一个空数组，但此时数据还未加载
        if (!in_array($post_id, self::$loaded_post_ids)) {
            $this->load_single_terms();
            // 标记为已加载（即使没有数据也要标记，避免重复查询）
            if (!in_array($post_id, self::$loaded_post_ids)) {
                self::$loaded_post_ids[] = $post_id;
            }
        }

        return isset(self::$terms_cache[$post_id][$taxonomy])
            ? self::$terms_cache[$post_id][$taxonomy]
            : [];
    }

    /**
     * 从缓存加载meta
     */
    protected function load_cached_meta()
    {
        $post_id = $this->get_key();
        if (isset(self::$meta_cache[$post_id])) {
            return;
        }
        // 不再自动初始化为空数组，以免干扰静态获取器的判断
    }

    /**
     * 从缓存加载terms
     */
    protected function load_cached_terms()
    {
        $post_id = $this->get_key();
        if (isset(self::$terms_cache[$post_id])) {
            return;
        }
        // 不再自动初始化为空数组，以免干扰静态获取器的判断
    }

    /**
     * 单个加载meta（用于未批量加载的情况）
     */
    protected function load_single_meta()
    {
        $post_id = $this->get_key();
        if (!$post_id) {
            return;
        }

        global $tododb;
        if (!$tododb) {
            return;
        }

        self::$meta_cache[$post_id] = [];

        // 1. Load from post_custom_fields
        $query = "SELECT field_key, field_value FROM {$tododb->prefix}post_custom_fields WHERE post_id = ?";
        $results = $tododb->get_results($query, [$post_id]);

        if ($results) {
            foreach ($results as $row) {
                $key = is_object($row) ? $row->field_key : $row['field_key'];
                $value = is_object($row) ? $row->field_value : $row['field_value'];
                self::$meta_cache[$post_id][$key] = maybe_unserialize($value);
            }
        }

        // 2. Load from postmeta
        $query_pm = "SELECT meta_key, meta_value FROM {$tododb->prefix}postmeta WHERE post_id = ?";
        $results_pm = $tododb->get_results($query_pm, [$post_id]);

        if ($results_pm) {
            foreach ($results_pm as $row) {
                $key = is_object($row) ? $row->meta_key : $row['meta_key'];
                $value = is_object($row) ? $row->meta_value : $row['meta_value'];
                // postmeta data overrides post_custom_fields if key collision (or vice versa? usually postmeta is standard)
                // Let's assume they are distinct or merge strategy. Standard CMS behavior is array of values for same key.
                // But Todo seems to use single value per key in this cache structure?
                // self::$meta_cache[$post_id][$key] = ...
                // If we overwrite, we might lose data. But Todo_Post_ORM seems to assume single value?
                // maybe_unserialize handles serialization.
                self::$meta_cache[$post_id][$key] = maybe_unserialize($value);
            }
        }
    }

    /**
     * 单个加载terms（用于未批量加载的情况）
     */
    protected function load_single_terms()
    {
        $post_id = $this->get_key();
        if (!$post_id) {
            return;
        }

        global $tododb;
        if (!$tododb) {
            return;
        }
        $query = "SELECT t.term_id, t.name, t.slug, tt.taxonomy 
                  FROM {$tododb->prefix}term_relationships tr
                  INNER JOIN {$tododb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                  INNER JOIN {$tododb->prefix}terms t ON tt.term_id = t.term_id
                  WHERE tr.object_id = ?";
        $results = $tododb->get_results($query, [$post_id]);

        self::$terms_cache[$post_id] = [];
        if ($results) {
            foreach ($results as $row) {
                $taxonomy = is_object($row) ? $row->taxonomy : $row['taxonomy'];
                if (!isset(self::$terms_cache[$post_id][$taxonomy])) {
                    self::$terms_cache[$post_id][$taxonomy] = [];
                }
                self::$terms_cache[$post_id][$taxonomy][] = is_object($row) ? (array) $row : $row;
            }
        }
    }

    /**
     * 批量预加载meta（静态方法，供查询构建器调用）
     * 
     * @param array $post_ids Post ID数组
     */
    public static function eager_load_meta($post_ids)
    {
        if (empty($post_ids)) {
            return;
        }

        // 过滤已加载的
        $ids_to_load = array_diff($post_ids, self::$loaded_post_ids);
        if (empty($ids_to_load)) {
            return;
        }

        global $tododb;
        if (!$tododb) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids_to_load), '?'));
        $query = "SELECT post_id, field_key, field_value 
                  FROM {$tododb->prefix}post_custom_fields 
                  WHERE post_id IN ({$placeholders})";

        $results = $tododb->get_results($query, $ids_to_load);

        // 填充缓存 (post_custom_fields)
        if ($results) {
            foreach ($results as $row) {
                $post_id = is_object($row) ? $row->post_id : $row['post_id'];
                $key = is_object($row) ? $row->field_key : $row['field_key'];
                $value = is_object($row) ? $row->field_value : $row['field_value'];

                if (!isset(self::$meta_cache[$post_id])) {
                    self::$meta_cache[$post_id] = [];
                }

                self::$meta_cache[$post_id][$key] = maybe_unserialize($value);
            }
        }

        // 填充缓存 (postmeta)
        $query_pm = "SELECT post_id, meta_key, meta_value 
                     FROM {$tododb->prefix}postmeta 
                     WHERE post_id IN ({$placeholders})";

        $results_pm = $tododb->get_results($query_pm, $ids_to_load);

        if ($results_pm) {
            foreach ($results_pm as $row) {
                $post_id = is_object($row) ? $row->post_id : $row['post_id'];
                $key = is_object($row) ? $row->meta_key : $row['meta_key'];
                $value = is_object($row) ? $row->meta_value : $row['meta_value'];

                if (!isset(self::$meta_cache[$post_id])) {
                    self::$meta_cache[$post_id] = [];
                }

                self::$meta_cache[$post_id][$key] = maybe_unserialize($value);
            }
        }

        // 双重查询完成后，才对仍未获得任何 meta 的 ID 标记为空缓存
        foreach ($ids_to_load as $id) {
            if (!isset(self::$meta_cache[$id])) {
                self::$meta_cache[$id] = [];
            }
        }

        // 标记为已加载
        self::$loaded_post_ids = array_merge(self::$loaded_post_ids, $ids_to_load);
    }

    /**
     * 批量预加载terms（静态方法，供查询构建器调用）
     * 
     * @param array $post_ids Post ID数组
     */
    public static function eager_load_terms($post_ids)
    {
        if (empty($post_ids)) {
            return;
        }

        // 过滤已加载的
        $ids_to_load = array_diff($post_ids, array_keys(self::$terms_cache));
        if (empty($ids_to_load)) {
            return;
        }

        global $tododb;
        if (!$tododb) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids_to_load), '?'));
        $query = "SELECT tr.object_id as post_id, t.term_id, t.name, t.slug, tt.taxonomy, tt.description, tt.count 
                  FROM {$tododb->prefix}term_relationships tr
                  INNER JOIN {$tododb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                  INNER JOIN {$tododb->prefix}terms t ON tt.term_id = t.term_id
                  WHERE tr.object_id IN ({$placeholders})";

        $results = $tododb->get_results($query, $ids_to_load);

        // 初始化缓存
        foreach ($ids_to_load as $id) {
            self::$terms_cache[$id] = [];
        }

        // 填充缓存
        if ($results) {
            foreach ($results as $row) {
                $post_id = is_object($row) ? $row->post_id : $row['post_id'];
                $taxonomy = is_object($row) ? $row->taxonomy : $row['taxonomy'];

                if (!isset(self::$terms_cache[$post_id][$taxonomy])) {
                    self::$terms_cache[$post_id][$taxonomy] = [];
                }

                self::$terms_cache[$post_id][$taxonomy][] = is_object($row) ? (array) $row : $row;
            }
        }
    }

    /**
     * 批量预加载所有关系（meta + terms）
     * 
     * @param array $post_ids Post ID数组
     * @param array $options 选项 ['meta' => true, 'terms' => true, 'multilang' => true]
     */
    public static function eager_load_all($post_ids, $options = [])
    {
        if (empty($post_ids)) {
            return;
        }

        // 默认选项
        $defaults = [
            'meta' => true,
            'terms' => true,
            'multilang' => true,
            'tags' => true,
            'categories' => true,
            'authors' => false,
            'comments' => false,
            'thumbnails' => false
        ];
        $options = array_merge($defaults, $options);

        // 去重并过滤无效ID
        $post_ids = array_unique(array_filter(array_map('intval', $post_ids)));
        if (empty($post_ids)) {
            return;
        }

        // 批量加载meta（同步填充 ORM 缓存与原生全局 postmeta 缓存池）
        if ($options['meta']) {
            self::eager_load_meta($post_ids);
            if (function_exists('update_post_meta_cache')) {
                update_post_meta_cache($post_ids);
            }
        }

        // 批量加载terms（同步填充 ORM 缓存与原生全局 terms 缓存池）
        if ($options['terms'] || $options['categories']) {
            self::eager_load_terms($post_ids);
            if (function_exists('update_post_term_cache')) {
                update_post_term_cache($post_ids, 'category');
                update_post_term_cache($post_ids, 'post_tag');
            }
        }

        // 批量加载多语言数据
        if ($options['multilang']) {
            $language_code = function_exists('get_current_language')
                ? get_current_language()
                : (function_exists('todo_get_current_language')
                    ? todo_get_current_language()
                    : null);

            if ($language_code && function_exists('todo_prime_post_translations')) {
                todo_prime_post_translations($post_ids, $language_code);
            }
            if ($language_code && function_exists('todo_get_posts_multilang_batch')) {
                todo_get_posts_multilang_batch($post_ids, $language_code, [
                    'include_seo' => true,
                    'prefer_custom_url' => true,
                    'fallback' => true
                ]);
            }
        }

        // 批量加载作者
        if ($options['authors'] && class_exists('CMS_User') && method_exists('CMS_User', 'prime_cache')) {
            global $tododb;
            $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
            $author_ids = $tododb->get_col("SELECT DISTINCT post_author FROM {$tododb->prefix}posts WHERE ID IN ($placeholders)", $post_ids, 0);
            if (!empty($author_ids)) {
                CMS_User::prime_cache($author_ids);
                // 同时预加载 Todo_User_Manager 缓存，避免 current_user_can 触发 N+1 查询
                if (class_exists('Todo_User_Manager') && method_exists('Todo_User_Manager', 'prime_cache')) {
                    Todo_User_Manager::prime_cache($author_ids);
                }
            }
        }

        // 批量加载评论数
        if ($options['comments'] && class_exists('CMS_Comment') && method_exists('CMS_Comment', 'prime_comment_counts')) {
            CMS_Comment::prime_comment_counts($post_ids);
        }

    }

    /**
     * 获取指定Post的meta（静态方法，供get_post_meta使用）
     * 
     * @param int $post_id Post ID
     * @return array|null Meta数组，如果未加载返回null
     */
    public static function get_meta_for_post($post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return null;
        }

        // 检查是否已批量加载
        if (isset(self::$meta_cache[$post_id])) {
            return self::$meta_cache[$post_id];
        }

        return null;
    }

    /**
     * 获取指定文章的分类/标签缓存
     * 
     * @param int $post_id 文章ID
     * @param string $taxonomy 分类法
     * @return array|null
     */
    public static function get_terms_for_post($post_id, $taxonomy = 'category')
    {
        $post_id = (int) $post_id;
        if (isset(self::$terms_cache[$post_id])) {
            if ($taxonomy === 'any') {
                return self::$terms_cache[$post_id];
            }
            return isset(self::$terms_cache[$post_id][$taxonomy]) ? self::$terms_cache[$post_id][$taxonomy] : null;
        }
        return null;
    }

    /**
     * 清除缓存
     * 
     * @param int|null $post_id 如果指定，只清除该Post的缓存
     */
    public static function clear_cache($post_id = null)
    {
        if ($post_id !== null) {
            unset(self::$meta_cache[$post_id]);
            unset(self::$terms_cache[$post_id]);
            self::$loaded_post_ids = array_diff(self::$loaded_post_ids, [$post_id]);
        } else {
            self::$meta_cache = [];
            self::$terms_cache = [];
            self::$loaded_post_ids = [];
        }
    }

    /**
     * 魔术方法：兼容旧代码
     * 
     * @param string $key 属性名
     * @return mixed
     */
    public function __get($key)
    {
        // 兼容旧代码中的属性访问
        if ($key === 'ID' || $key === 'id') {
            return $this->get_key();
        }

        // 尝试获取meta
        if (strpos($key, 'meta_') === 0) {
            $meta_key = substr($key, 5);
            return $this->get_meta($meta_key);
        }

        return parent::__get($key);
    }
}
