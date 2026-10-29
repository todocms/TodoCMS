<?php
/**
 * 选项类
 *
 * 处理系统选项的获取、更新和删除
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 确保核心函数已加载
if (!function_exists('current_time')) {
    require_once ABSPATH . 'core/functions/todo-core-functions.php';
}

// 确保钩子函数已加载
if (!function_exists('todo_do_action')) {
    require_once ABSPATH . 'core/functions/todo-hooks.php';
}

class CMS_Option
{
    /**
     * 选项缓存
     *
     * @var array
     */
    private static $cache = [];

    /**
     * 自动加载选项
     *
     * @var bool
     */
    private static $autoload = [];

    /**
     * 标记自动加载选项是否已加载
     *
     * @var bool
     */
    private static $autoload_loaded = false;

    /**
     * 标记 system_config 表是否存在
     * @var bool|null
     */
    private static $table_exists = null;

    /**
     * 获取选项值
     *
     * @param string $option 选项名称
     * @param mixed $default 默认值
     * @return mixed 选项值
     */
    public static function get($option, $default = false)
    {
        // 检查缓存
        if (isset(self::$cache[$option])) {
            $value = self::$cache[$option];
        } else {
            // 优化：如果已加载自动加载选项且选项不存在于缓存中，则直接返回默认值
            // 避免对不存在的选项进行N+1次数据库查询
            if (self::$autoload_loaded) {
                return $default;
            }

            global $tododb;

            // 统一获取 CMS_DB 连接（使用单例模式）
            $db = null;
            if ($tododb instanceof CMS_DB) {
                $db = $tododb;
            } elseif (class_exists('CMS_DB')) {
                try {
                    $db = CMS_DB::get_instance();
                } catch (Exception $e) {
                    // 数据库连接失败，返回默认值
                    error_log('CMS_Option: 数据库连接失败 - ' . $e->getMessage());
                    return $default;
                }
            } else {
                // 数据库连接不可用，返回默认值
                return $default;
            }

            // 检查 system_config 表是否存在（带缓存）
            if (self::$table_exists === null) {
                try {
                    $table_name = TABLE_PREFIX . "system_config";
                    if (function_exists('todo_db_table_exists')) {
                        self::$table_exists = todo_db_table_exists('system_config');
                    } else if (function_exists('table_exists')) {
                        self::$table_exists = table_exists($table_name);
                    } else {
                        $count = $db->get_var("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . $table_name . "'");
                        self::$table_exists = ($count && intval($count) > 0);
                    }
                } catch (Exception $e) {
                    // 查询失败，假定不存在
                    error_log('CMS_Option: 检查表存在性失败 - ' . $e->getMessage());
                    self::$table_exists = false;
                }
            }

            if (!self::$table_exists) {
                return $default;
            }

            // 从 system_config 表获取选项
            try {
                $query = "SELECT config_value, config_type FROM " . TABLE_PREFIX . "system_config WHERE config_key = ? LIMIT 1";
                $result = $db->get_results($query, [$option]);

                if ($result && count($result) > 0) {
                    // 处理stdClass对象或关联数组
                    $row = $result[0];
                    $config_value = is_object($row) ? $row->config_value : $row['config_value'];
                    $config_type = is_object($row) ? $row->config_type : $row['config_type'];

                    // 根据配置类型处理值
                    $value = self::convert_value($config_value, $config_type);
                    self::$cache[$option] = $value;
                } else {
                    // 使用默认值
                    $value = $default;
                }
            } catch (Exception $e) {
                // 查询失败，返回默认值
                error_log('CMS_Option: 获取选项失败 - ' . $e->getMessage());
                $value = $default;
            }
        }

        if (function_exists('todo_is_demo_admin') && todo_is_demo_admin()) {
            if (preg_match('/(site_adminbasefile|todo_admin_last_rotate_time|license|salt|key)/i', $option)) {
                 return $default;
            }
        }

        // 应用过滤器
        return todo_apply_filters('option_' . $option, $value, $option);
    }

    /**
     * 更新选项值
     *
     * @param string $option 选项名称
     * @param mixed $value 选项值
     * @param string $group_name 配置分组
     * @return bool 是否成功更新
     */
    public static function update($option, $value, $group_name = 'general', $clear_cache = true)
    {
        if (function_exists('todo_is_demo_admin_session') && todo_is_demo_admin_session()) {
            return false;
        }

        global $tododb;

        // 统一获取 CMS_DB 连接（使用单例模式）
        $db = null;
        if ($tododb instanceof CMS_DB) {
            $db = $tododb;
        } elseif (class_exists('CMS_DB')) {
            try {
                $db = CMS_DB::get_instance();
            } catch (Exception $e) {
                // 数据库连接失败
                error_log('CMS_Option::update: 数据库连接失败 - ' . $e->getMessage());
                return false;
            }
        } else {
            // 数据库连接不可用
            return false;
        }

        // 确定配置类型和值
        $config_type = self::get_config_type($value);
        $config_value = self::prepare_value($value);

        try {
            // 在更新之前取旧值
            $oldValue = self::get($option, null);
            // 检查选项是否已存在
            $exists = self::exists($option);

            if ($exists) {
                // 更新现有选项
                $result = $db->update(
                    TABLE_PREFIX . 'system_config',
                    [
                        'config_value' => $config_value,
                        'config_type' => $config_type,
                        'updated_at' => current_time('mysql')
                    ],
                    ['config_key' => $option]
                );
            } else {
                // 插入新选项
                $result = $db->insert(
                    TABLE_PREFIX . 'system_config',
                    [
                        'config_key' => $option,
                        'config_value' => $config_value,
                        'config_type' => $config_type,
                        'group_name' => $group_name,
                        'created_at' => current_time('mysql')
                    ]
                );
            }

            if ($result !== false) {
                // 更新缓存
                self::$cache[$option] = $value;

                // 清除自动加载缓存
                if ($clear_cache && class_exists('Todo_Cache_Manager')) {
                    Todo_Cache_Manager::delete('autoload_options', 'options', 'global');
                }

                // 执行选项更新钩子
                todo_do_action('update_option', $option, $value, $group_name);
                todo_do_action("update_option_{$option}", $value, $group_name);

                // 语言主题映射更新钩子（新方案）
                if ($option === 'language_theme_map') {
                    todo_do_action('language_theme_map_updated', $value, $oldValue);
                }

                return true;
            }

            return false;
        } catch (Exception $e) {
            // 数据库操作失败
            error_log('CMS_Option::update: 数据库操作失败 - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 删除选项
     *
     * @param string $option 选项名称
     * @return bool 是否成功删除
     */
    public static function delete($option)
    {
        if (function_exists('todo_is_demo_admin_session') && todo_is_demo_admin_session()) {
            return false;
        }

        global $tododb;

        // 统一获取 CMS_DB 连接（使用单例模式）
        $db = null;
        if ($tododb instanceof CMS_DB) {
            $db = $tododb;
        } elseif (class_exists('CMS_DB')) {
            try {
                $db = CMS_DB::get_instance();
            } catch (Exception $e) {
                // 数据库连接失败
                error_log('CMS_Option::delete: 数据库连接失败 - ' . $e->getMessage());
                return false;
            }
        } else {
            // 数据库连接不可用
            return false;
        }

        try {
            // 检查选项是否存在
            if (!self::exists($option)) {
                return false;
            }

            // 从数据库删除选项
            $result = $db->delete(TABLE_PREFIX . 'system_config', ['config_key' => $option]);

            if ($result !== false) {
                // 从缓存中移除
                unset(self::$cache[$option]);

                // 清除自动加载缓存
                if (class_exists('Todo_Cache_Manager')) {
                    Todo_Cache_Manager::delete('autoload_options', 'options', 'global');
                }

                // 执行选项删除钩子
                todo_do_action('delete_option', $option);
                todo_do_action("delete_option_{$option}");

                return true;
            }

            return false;
        } catch (Exception $e) {
            // 数据库操作失败
            error_log('CMS_Option::delete: 数据库操作失败 - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 检查选项是否存在
     *
     * @param string $option 选项名称
     * @return bool 选项是否存在
     */
    public static function exists($option)
    {
        // 检查缓存
        if (isset(self::$cache[$option])) {
            return true;
        }

        global $tododb;

        // 统一获取 CMS_DB 连接（使用单例模式）
        $db = null;
        if ($tododb instanceof CMS_DB) {
            $db = $tododb;
        } elseif (class_exists('CMS_DB')) {
            try {
                $db = CMS_DB::get_instance();
            } catch (Exception $e) {
                // 数据库连接失败
                error_log('CMS_Option::exists: 数据库连接失败 - ' . $e->getMessage());
                return false;
            }
        } else {
            // 数据库连接不可用
            return false;
        }

        try {
            // 检查 system_config 表是否存在
            if (function_exists('table_exists')) {
                if (!table_exists(TABLE_PREFIX . 'system_config')) return false;
            } else {
                $table_check = $db->get_var("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?", [TABLE_PREFIX . 'system_config']);
                if (!$table_check || intval($table_check) == 0) {
                    return false;
                }
            }

            // 从数据库检查选项
            $query = "SELECT COUNT(*) FROM " . TABLE_PREFIX . "system_config WHERE config_key = ?";
            $count = $db->get_var($query, [$option]);

            return $count > 0;
        } catch (Exception $e) {
            // 数据库操作失败
            error_log('CMS_Option::exists: 数据库操作失败 - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 加载自动加载选项（从 system_config 表加载所有配置）
     */
    public static function load_autoload_options()
    {
        // 尝试从缓存加载
        if (class_exists('Todo_Cache_Manager')) {
            $cached_options = Todo_Cache_Manager::get('autoload_options', 'options', 'global');
            if ($cached_options !== false && is_array($cached_options)) {
                self::$cache = array_merge(self::$cache, $cached_options);
                self::$autoload_loaded = true;
                return;
            }
        }

        global $tododb;

        // 检查数据库连接是否存在
        if (!$tododb || !($tododb instanceof CMS_DB)) {
            // 尝试初始化数据库连接（使用单例模式）
            if (!$tododb && class_exists('CMS_DB')) {
                try {
                    $tododb = CMS_DB::get_instance();
                    $GLOBALS['tododb'] = $tododb;
                } catch (Exception $e) {
                    // 数据库连接失败
                    error_log('CMS_Option::load_autoload_options: 数据库连接失败 - ' . $e->getMessage());
                    return;
                }
            } else {
                // 数据库连接不可用
                return;
            }
        }

        try {
            // 获取所有配置选项
            $query = "SELECT config_key, config_value, config_type FROM " . TABLE_PREFIX . "system_config";
            $results = $tododb->get_results($query);

            if ($results) {
                foreach ($results as $result) {
                    // 处理stdClass对象或关联数组
                    $option = is_object($result) ? $result->config_key : $result['config_key'];
                    $config_value = is_object($result) ? $result->config_value : $result['config_value'];
                    $config_type = is_object($result) ? $result->config_type : $result['config_type'];

                    $value = self::convert_value($config_value, $config_type);

                    // 更新缓存
                    self::$cache[$option] = $value;
                }

                // 设置缓存
                if (class_exists('Todo_Cache_Manager')) {
                    Todo_Cache_Manager::set('autoload_options', self::$cache, 3600, 'options', 'global');
                }
            }

            // 标记自动加载完成
            self::$autoload_loaded = true;
        } catch (Exception $e) {
            // 数据库操作失败
            error_log('CMS_Option::load_autoload_options: 数据库操作失败 - ' . $e->getMessage());
        }
    }

    /**
     * 设置缓存选项值（用于预加载）
     *
     * @param string $option 选项名称
     * @param mixed $value 选项值
     */
    public static function set_cached($option, $value)
    {
        self::$cache[$option] = $value;
    }

    /**
     * 获取所有选项
     *
     * @return array 所有选项
     */
    public static function get_all()
    {
        global $tododb;

        // 获取所有选项
        $query = "SELECT config_key, config_value, config_type FROM " . TABLE_PREFIX . "system_config";
        $results = $tododb->get_results($query);

        $options = [];

        if ($results) {
            foreach ($results as $result) {
                // 处理stdClass对象或关联数组
                $option = is_object($result) ? $result->config_key : $result['config_key'];
                $config_value = is_object($result) ? $result->config_value : $result['config_value'];
                $config_type = is_object($result) ? $result->config_type : $result['config_type'];

                $value = self::convert_value($config_value, $config_type);

                // 更新缓存
                self::$cache[$option] = $value;

                // 添加到结果
                $options[$option] = $value;
            }
        }

        return $options;
    }

    /**
     * 获取以特定前缀开头的所有选项
     *
     * @param string $prefix 选项前缀
     * @return array 匹配的选项
     */
    public static function get_by_prefix($prefix)
    {
        global $tododb;

        // 获取所有匹配前缀的选项
        $query = "SELECT config_key, config_value, config_type FROM " . TABLE_PREFIX . "system_config WHERE config_key LIKE ?";
        $results = $tododb->get_results($query, [$prefix . '%']);

        $options = [];

        if ($results) {
            foreach ($results as $result) {
                // 处理stdClass对象或关联数组
                $option = is_object($result) ? $result->config_key : $result['config_key'];
                $config_value = is_object($result) ? $result->config_value : $result['config_value'];
                $config_type = is_object($result) ? $result->config_type : $result['config_type'];

                $value = self::convert_value($config_value, $config_type);

                // 更新缓存
                self::$cache[$option] = $value;

                // 添加到结果
                $options[$option] = $value;
            }
        }

        return $options;
    }

    /**
     * 批量更新选项
     *
     * @param array $options 选项数组
     * @return bool 是否全部成功更新
     */
    public static function update_batch($options, $group_name = 'general')
    {
        if (!is_array($options) || empty($options)) {
            return false;
        }

        $success = true;
        $any_updated = false;

        foreach ($options as $option => $value) {
            // 批量更新时不立即清除缓存
            $result = self::update($option, $value, $group_name, false);
            if ($result) {
                $any_updated = true;
            }
            $success = $success && $result;
        }

        // 如果有任何更新，统一清除缓存
        if ($any_updated && class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::delete('autoload_options', 'options', 'global');
        }

        return $success;
    }

    /**
     * 批量删除选项
     *
     * @param array $options 选项名称数组
     * @return bool 是否全部成功删除
     */
    public static function delete_batch($options)
    {
        if (!is_array($options) || empty($options)) {
            return false;
        }

        $success = true;

        foreach ($options as $option) {
            $result = self::delete($option);
            $success = $success && $result;
        }

        return $success;
    }

    /**
     * 清除选项缓存
     *
     * @param string $option 选项名称，如果为空则清除所有缓存
     */
    public static function clear_cache($option = '')
    {
        if (empty($option)) {
            self::$cache = [];
        } else {
            unset(self::$cache[$option]);
        }
    }

    /**
     * 根据配置类型转换值
     *
     * @param string $value 配置值
     * @param string $type 配置类型
     * @return mixed 转换后的值
     */
    private static function convert_value($value, $type)
    {
        switch ($type) {
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'integer':
                return (int) $value;
            case 'float':
                return (float) $value;
            case 'array':
            case 'object':
                return maybe_unserialize($value);
            case 'json':
                return json_decode($value, true);
            default:
                return $value;
        }
    }

    /**
     * 根据值类型确定配置类型
     *
     * @param mixed $value 配置值
     * @return string 配置类型
     */
    private static function get_config_type($value)
    {
        if (is_bool($value)) {
            return 'boolean';
        } elseif (is_int($value)) {
            return 'integer';
        } elseif (is_float($value)) {
            return 'float';
        } elseif (is_array($value) || is_object($value)) {
            return 'array';
        } else {
            return 'string';
        }
    }

    /**
     * 准备存储的值
     *
     * @param mixed $value 原始值
     * @return string 准备存储的值
     */
    private static function prepare_value($value)
    {
        if (is_array($value) || is_object($value)) {
            return maybe_serialize($value);
        } elseif (is_bool($value)) {
            return $value ? '1' : '0';
        } else {
            return (string) $value;
        }
    }
}