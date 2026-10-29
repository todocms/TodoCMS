<?php
/**
 * TodoCMS 配置管理器
 *
 * 提供统一的配置管理功能,包括系统配置和自定义参数设置
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class Todo_Config_Manager
{

    /**
     * 配置缓存
     * @var array
     */
    private static $config_cache = array();

    /**
     * 配置是否已加载
     * @var bool
     */
    private static $loaded = false;

    /**
     * 数据库连接
     * @var CMS_DB
     */
    private static $tododb;

    /**
     * 获取数据库表前缀
     * 
     * @return string
     */
    private static function get_db_prefix()
    {
        if (defined('DB_PREFIX')) {
            return DB_PREFIX;
        }
        if (defined('TABLE_PREFIX')) {
            return TABLE_PREFIX;
        }
        return 'todo_'; // 默认前缀
    }

    /**
     * 初始化配置管理器
     */
    public static function init()
    {
        global $tododb;
        self::$tododb = $tododb;

        if (!self::$loaded) {
            self::$loaded = true;
            self::load_config();
            // 当数据库连接在初始化阶段尚未就绪时，load_config() 会因 self::$tododb 为空而设置空缓存。
            // 此情况下必须重置 loaded 标记，让后续 get()/set() 调用可以重新触发完整的初始化流程。
            if (empty(self::$config_cache) && !is_object(self::$tododb)) {
                self::$loaded = false;
            }
        }
    }

    /**
     * 加载系统配置
     */
    private static function load_config()
    {
        // 尝试从缓存加载（与 CMS_Option 共享缓存）
        if (class_exists('Todo_Cache_Manager')) {
            $cached_options = Todo_Cache_Manager::get('autoload_options', 'options', 'global');
            if ($cached_options !== false && is_array($cached_options) && !empty($cached_options)) {
                self::$config_cache = $cached_options;
                return;
            }
        }

        // 检查数据库连接是否存在
        if (!self::$tododb || !is_object(self::$tododb)) {
            // 数据库未准备好，初始化空配置
            self::$config_cache = array();
            return;
        }

        // 获取数据库表前缀
        $prefix = self::get_db_prefix();

        // 检查表是否存在
        $table_exists = false;
        if (function_exists('table_exists')) {
            $table_exists = table_exists($prefix . 'system_config');
        } elseif (method_exists(self::$tododb, 'table_exists')) {
            $table_exists = self::$tododb->table_exists($prefix . 'system_config');
        } else {
            try {
                $cnt = self::$tododb->get_var(
                    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?",
                    [$prefix . 'system_config']
                );
                $table_exists = ($cnt && intval($cnt) > 0);
            } catch (Throwable $e) {
                $table_exists = false;
            }
        }
        if (!$table_exists) {
            // 表不存在，初始化空配置
            self::$config_cache = array();
            return;
        }

        $query = "SELECT config_key, config_value FROM " . $prefix . "system_config";
        $results = self::$tododb->get_results($query);

        if ($results) {
            foreach ($results as $row) {
                self::$config_cache[$row->config_key] = maybe_unserialize($row->config_value);
            }
            
            // 设置缓存（如果缓存未命中）
            if (class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set('autoload_options', self::$config_cache, 3600, 'options', 'global');
            }
        }
    }

    /**
     * 获取配置值
     *
     * @param string $key 配置键名
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        if (!self::$loaded) {
            self::init();
        }

        return isset(self::$config_cache[$key]) ? self::$config_cache[$key] : $default;
    }

    /**
     * 设置配置值
     *
     * @param string $key 配置键名
     * @param mixed $value 配置值
     * @param bool $autoload 是否自动加载
     * @return bool
     */
    public static function set($key, $value, $autoload = true)
    {
        if (!self::$loaded) {
            self::init();
        }

        // 检查数据库连接是否存在
        if (!self::$tododb || !is_object(self::$tododb)) {
            // 数据库未准备好，只更新内存缓存
            self::$config_cache[$key] = $value;
            return false;
        }

        $serialized_value = maybe_serialize($value);
        $prefix = self::get_db_prefix();

        // 插入前先判断是否存在
        if (self::$tododb->get_var("SELECT COUNT(*) FROM " . $prefix . "system_config WHERE config_key = ?", [$key]) > 0) {
            // 已存在则更新
            $updated = self::$tododb->update($prefix . 'system_config', ['config_value' => $serialized_value], ['config_key' => $key]);
            self::$config_cache[$key] = $value;
            
            if ($updated !== false && class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::delete('autoload_options', 'options', 'global');
            }
            
            return ($updated !== false);
        } else {
            // 不存在则插入
            $inserted = self::$tododb->insert(
                $prefix . 'system_config',
                array(
                    'config_key' => $key,
                    'config_value' => $serialized_value,
                    'config_type' => 'string',
                    'group_name' => 'general',
                    'is_system' => 0
                )
            );

            if ($inserted) {
                self::$config_cache[$key] = $value;
                if (class_exists('Todo_Cache_Manager')) {
                    Todo_Cache_Manager::delete('autoload_options', 'options', 'global');
                }
                return true;
            }
            return false;
        }
    }

    /**
     * 删除配置
     *
     * @param string $key 配置键名
     * @return bool
     */
    public static function delete($key)
    {
        // 检查数据库连接是否存在
        if (!self::$tododb || !is_object(self::$tododb)) {
            // 数据库未准备好，只从内存缓存删除
            if (isset(self::$config_cache[$key])) {
                unset(self::$config_cache[$key]);
            }
            return false;
        }

        $prefix = self::get_db_prefix();
        $deleted = self::$tododb->delete(
            $prefix . 'system_config',
            array('config_key' => $key)
        );

        if ($deleted) {
            unset(self::$config_cache[$key]);
            if (class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::delete('autoload_options', 'options', 'global');
            }
            return true;
        }

        return false;
    }

    /**
     * 获取所有配置
     *
     * @return array
     */
    public static function get_all_config()
    {
        if (!self::$loaded) {
            self::init();
        }

        return self::$config_cache;
    }

    /**
     * 清除缓存
     */
    public static function clear_cache()
    {
        self::$config_cache = array();
        self::$loaded = false;
    }

    /**
     * 重新加载配置
     */
    public static function reload()
    {
        self::clear_cache();
        self::init();
    }

    /**
     * 批量设置配置
     *
     * @param array $configs 配置数组
     * @return bool
     */
    public static function set_batch($configs)
    {
        $success = true;

        foreach ($configs as $key => $value) {
            if (!self::set($key, $value)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * 导出配置
     *
     * @param array $keys 要导出的配置键名，为空则导出全部
     * @return array
     */
    public static function export_config($keys = array())
    {
        if (!self::$loaded) {
            self::init();
        }

        if (empty($keys)) {
            return self::$config_cache;
        }

        $exported = array();
        foreach ($keys as $key) {
            if (isset(self::$config_cache[$key])) {
                $exported[$key] = self::$config_cache[$key];
            }
        }

        return $exported;
    }

    /**
     * 导入配置
     *
     * @param array $configs 配置数组
     * @param bool $overwrite 是否覆盖现有配置
     * @return bool
     */
    public static function import_config($configs, $overwrite = false)
    {
        $success = true;

        foreach ($configs as $key => $value) {
            if ($overwrite || !isset(self::$config_cache[$key])) {
                if (!self::set($key, $value)) {
                    $success = false;
                }
            }
        }

        return $success;
    }
}

// 便捷函数
if (!function_exists('todo_get_option')) {
    /**
     * 获取配置选项
     *
     * @param string $key 配置键名
     * @param mixed $default 默认值
     * @return mixed
     */
    function todo_get_option($key, $default = null)
    {
        return Todo_Config_Manager::get($key, $default);
    }
}

if (!function_exists('todo_set_option')) {
    /**
     * 设置配置选项
     *
     * @param string $key 配置键名
     * @param mixed $value 配置值
     * @param bool $autoload 是否自动加载
     * @return bool
     */
    function todo_set_option($key, $value, $autoload = true)
    {
        return Todo_Config_Manager::set($key, $value, $autoload);
    }
}

?>
