<?php
/**
 * TodoCMS 插件管理器
 * 
 * 管理插件的安装、激活、停用、卸载和钩子系统
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 确保钩子函数已加载
if (!function_exists('todo_do_action')) {
    require_once ABSPATH . 'core/functions/todo-hooks.php';
}

class Todo_Plugin_Manager
{
    /**
     * 安全调用日志函数（确保函数存在）
     * 
     * @param string $level 日志级别 (info, error, warning, debug)
     * @param string $message 日志消息
     * @param array $context 上下文信息
     */
    private static function safe_log($level, $message, $context = [])
    {
        $func_name = 'todo_log_' . $level;
        if (function_exists($func_name)) {
            call_user_func($func_name, $message, $context);
        } elseif (class_exists('Todo_Logger')) {
            // 使用静态方法调用
            switch ($level) {
                case 'info':
                    Todo_Logger::info($message, $context);
                    break;
                case 'error':
                    Todo_Logger::error($message, $context);
                    break;
                case 'warning':
                    Todo_Logger::warning($message, $context);
                    break;
                case 'debug':
                    Todo_Logger::debug($message, $context);
                    break;
                default:
                    Todo_Logger::log($level, $message, $context);
                    break;
            }
        } else {
            // 最后的后备方案：使用 error_log
            error_log("[Todo_Plugin_Manager] {$level}: {$message}");
        }
    }

    /**
     * 数据库连接
     * @var CMS_DB
     */
    private static $tododb;

    /**
     * 插件目录
     */
    private static $plugins_dir = 'content/plugins';

    /**
     * 已激活的插件
     * @var array
     */
    private static $active_plugins = array();

    /**
     * 插件缓存
     * @var array
     */
    private static $plugins_cache = array();

    /**
     * 钩子注册表
     * @var array
     */
    private static $hooks = array();

    /**
     * 过滤器注册表
     * @var array
     */
    private static $filters = array();

    /**
     * 单例实例
     * @var Todo_Plugin_Manager
     */
    private static $instance = null;

    /**
     * 插件激活钩子
     * @var array
     */
    private static $activation_hooks = array();

    /**
     * 插件停用钩子
     * @var array
     */
    private static $deactivation_hooks = array();

    /**
     * 插件卸载钩子
     * @var array
     */
    private static $uninstall_hooks = array();

    /**
     * 插件状态常量
     */
    const STATUS_ACTIVE = 1;
    const STATUS_INACTIVE = 0;

    /**
     * 私有构造函数，防止直接实例化
     */
    private function __construct()
    {
        global $tododb;
        self::$tododb = $tododb;
    }

    /**
     * 获取单例实例
     * @return Todo_Plugin_Manager
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 确保数据库连接已初始化
     */
    private static function ensure_db_connection()
    {
        global $tododb;

        // 统一接管全局连接，优先使用 CMS_DB 封装
        if (self::$tododb === null) {
            self::$tododb = $tododb;
        }

        // 如果不是 CMS_DB，则强制创建 CMS_DB，避免 PDO::query 第二参被当作 fetch mode 导致错误
        if (!self::$tododb || !(self::$tododb instanceof CMS_DB)) {
            self::$tododb = new CMS_DB();
        }

        // 最终校验
        if (!self::$tododb || !is_object(self::$tododb)) {
            throw new Exception('Database connection is not available or invalid');
        }
    }

    /**
     * 防止克隆
     */
    private function __clone()
    {
    }

    /**
     * 防止反序列化
     */
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton");
    }

    /**
     * 初始化插件管理器
     */
    public static function init()
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }
        $initialized = true;

        // 获取单例实例
        $instance = self::get_instance();

        // 加载激活的插件
        self::load_active_plugins();

        // 初始化钩子系统
        self::init_hooks();
    }

    /**
     * 获取所有插件
     * 
     * @param bool $include_inactive 是否包含未激活的插件
     * @return array
     */
    public static function get_plugins($include_inactive = true)
    {
        $plugins = array();

        // 确保数据库连接已初始化
        self::ensure_db_connection();

        // 检查表是否存在，如果不存在则跳过数据库查询
        if (self::$tododb->table_exists(TABLE_PREFIX . 'plugins')) {
            // 从数据库获取插件信息
            try {
                $where_sql = $include_inactive ? '' : 'WHERE status = \'active\'';
                $query = "SELECT * FROM " . TABLE_PREFIX . "plugins {$where_sql} ORDER BY name";
                $results = self::$tododb->query($query);

                if ($results && is_array($results)) {
                    foreach ($results as $row) {
                        // 获取插件配置
                        $row['config'] = self::get_plugin_config($row['directory']);
                        $row['meta'] = self::get_plugin_meta($row['id']);
                        $plugins[$row['directory']] = $row;
                    }
                }
            } catch (Exception $e) {
                error_log('Plugin Manager get_plugins error: ' . $e->getMessage());
            }
        }

        // 扫描插件目录，查找未安装的插件（仅在包含未激活插件时）
        if ($include_inactive) {
            $plugins_path = TODO_ROOT . '/' . self::$plugins_dir;
            if (is_dir($plugins_path)) {
                $dirs = scandir($plugins_path);
                foreach ($dirs as $dir) {
                    if ($dir === '.' || $dir === '..' || !is_dir($plugins_path . '/' . $dir)) {
                        continue;
                    }

                    if (!isset($plugins[$dir])) {
                        // 未安装的插件
                        $config = self::get_plugin_config($dir);
                        if ($config) {
                            $plugins[$dir] = array(
                                'id' => 0,
                                'name' => $config['name'] ?? $dir,
                                'directory' => $dir,
                                'version' => $config['version'] ?? '1.0.0',
                                'author' => $config['author'] ?? '',
                                'description' => $config['description'] ?? '',
                                'status' => 0,
                                'installed' => false,
                                'config' => $config,
                                'meta' => array()
                            );
                        }
                    }
                }
            }
        }

        return $plugins;
    }

    /**
     * 获取插件信息
     * 
     * @param string $plugin_dir 插件目录名
     * @return array|null
     */
    public static function get_plugin($plugin_dir)
    {
        if (isset(self::$plugins_cache[$plugin_dir])) {
            return self::$plugins_cache[$plugin_dir];
        }

        // 确保数据库连接已初始化
        self::ensure_db_connection();

        $query = "SELECT * FROM " . TABLE_PREFIX . "plugins WHERE directory = ?";
        $row = self::$tododb->get_row($query, [$plugin_dir], 'ARRAY_A');

        if ($row && is_array($row)) {
            // 获取插件配置和元数据
            $row['config'] = self::get_plugin_config($plugin_dir);
            $row['meta'] = self::get_plugin_meta($row['id']);
            $row['installed'] = true; // 标记为已安装

            self::$plugins_cache[$plugin_dir] = $row;
            return $row;
        }

        // 检查是否存在未安装的插件
        $config = self::get_plugin_config($plugin_dir);
        if ($config) {
            return array(
                'id' => 0,
                'name' => $config['name'] ?? $plugin_dir,
                'directory' => $plugin_dir,
                'version' => $config['version'] ?? '1.0.0',
                'author' => $config['author'] ?? '',
                'description' => $config['description'] ?? '',
                'status' => 0,
                'installed' => false,
                'config' => $config,
                'meta' => array()
            );
        }

        return null;
    }

    /**
     * 安装插件
     * 
     * @param string $plugin_dir 插件目录名
     * @return bool|array 成功返回true，失败返回错误信息
     */
    public static function install_plugin($plugin_dir)
    {
        // 确保数据库连接已初始化
        self::ensure_db_connection();

        // 检查插件是否存在
        $config = self::get_plugin_config($plugin_dir);
        if (!$config) {
            return array('error' => '插件不存在或配置文件无效');
        }

        // 检查是否已安装
        $existing = self::get_plugin($plugin_dir);
        if ($existing && isset($existing['installed']) && $existing['installed']) {
            return array('error' => '插件已安装');
        }

        // 验证插件结构
        $validation = self::validate_plugin_structure($plugin_dir);
        if ($validation !== true) {
            return array('error' => $validation);
        }

        // 检查依赖
        $dependency_check = self::check_plugin_dependencies($config);
        if ($dependency_check !== true) {
            return array('error' => $dependency_check);
        }

        // 安装插件到数据库
        $plugin_file = isset($config['main_file']) ? $config['main_file'] : 'plugin.php';
        // 使用 CMS_DB 封装进行插入，避免直接依赖 PDO
        $insert_id = self::$tododb->insert(TABLE_PREFIX . 'plugins', [
            'name' => $config['name'],
            'directory' => $plugin_dir,
            'version' => $config['version'],
            'author' => $config['author'],
            'description' => $config['description'],
            'plugin_file' => $plugin_file,
            'status' => 'inactive'
        ]);

        if ($insert_id !== false) {
            $plugin_id = (int) $insert_id;

            // 保存插件配置
            if (!empty($config['settings'])) {
                foreach ($config['settings'] as $key => $value) {
                    self::save_plugin_meta($plugin_id, $key, $value);
                }
            }

            // 运行插件安装脚本
            self::run_plugin_install_script($plugin_dir);

            // 清除缓存
            unset(self::$plugins_cache[$plugin_dir]);

            // 清除数据库查询缓存，避免状态读取到旧值
            if (function_exists('todo_clear_db_cache')) {
                todo_clear_db_cache('*plugins*');
            }
            if (class_exists('Todo_Database_Optimizer')) {
                try {
                    Todo_Database_Optimizer::getInstance()->clearTableCache(TABLE_PREFIX . 'plugins');
                    Todo_Database_Optimizer::getInstance()->clearTableCache(TABLE_PREFIX . 'plugin_meta');
                } catch (Throwable $e) { /* ignore */
                }
            }
            if (function_exists('clear_table_cache')) {
                clear_table_cache();
            }

            return true;
        }

        return array('error' => '安装失败');
    }

    public static function sync_plugins_to_db()
    {
        self::ensure_db_connection();
        $plugins_path = TODO_ROOT . '/' . self::$plugins_dir;
        if (!is_dir($plugins_path)) {
            return 0;
        }
        $inserted = 0;
        $dirs = scandir($plugins_path);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            $full = $plugins_path . '/' . $dir;
            if (!is_dir($full)) {
                continue;
            }
            $exists = self::$tododb->get_row("SELECT id FROM " . TABLE_PREFIX . "plugins WHERE directory = ?", [$dir], 'ARRAY_A');
            if ($exists && isset($exists['id'])) {
                continue;
            }
            $config = self::get_plugin_config($dir);
            if (!$config || !is_array($config)) {
                continue;
            }
            $plugin_file = isset($config['main_file']) ? $config['main_file'] : 'plugin.php';
            $ok = self::$tododb->insert(TABLE_PREFIX . 'plugins', [
                'name' => $config['name'] ?? $dir,
                'directory' => $dir,
                'version' => $config['version'] ?? '1.0.0',
                'author' => $config['author'] ?? '',
                'description' => $config['description'] ?? '',
                'plugin_file' => $plugin_file,
                'status' => 'inactive'
            ]);
            if ($ok !== false) {
                $inserted++;
            }
        }
        if (function_exists('todo_clear_db_cache')) {
            todo_clear_db_cache('*plugins*');
        }
        return $inserted;
    }

    /**
     * 激活插件
     * 
     * @param string $plugin_dir 插件目录名
     * @return bool|array
     */
    public static function activate_plugin($plugin_dir)
    {
        // 确保数据库连接为 CMS_DB
        self::ensure_db_connection();

        $plugin = self::get_plugin($plugin_dir);
        if (!$plugin) {
            return array('error' => '插件不存在');
        }

        if (!isset($plugin['installed']) || !$plugin['installed']) {
            // 先安装插件
            $install_result = self::install_plugin($plugin_dir);
            if ($install_result !== true) {
                return $install_result;
            }
            $plugin = self::get_plugin($plugin_dir);
        }

        if ($plugin['status'] == 'active' || $plugin['status'] == self::STATUS_ACTIVE) {
            return array('error' => '插件已激活');
        }

        // 检查依赖
        $config = self::get_plugin_config($plugin_dir);
        $dependency_check = self::check_plugin_dependencies($config);
        if ($dependency_check !== true) {
            return array('error' => $dependency_check);
        }

        // 激活插件
        $query = "UPDATE " . TABLE_PREFIX . "plugins SET status = 'active', activation_time = NOW() WHERE directory = ?";
        $affected = self::$tododb->query($query, [$plugin_dir]);

        // 数据库错误
        if ($affected === false) {
            return array('error' => '激活失败：数据库错误');
        }

        // 未更新任何记录，进一步核验记录是否存在/状态
        if ((int) $affected === 0) {
            $check = self::$tododb->query("SELECT id, status FROM " . TABLE_PREFIX . "plugins WHERE directory = ?", [$plugin_dir]);
            if (!$check || empty($check)) {
                return array('error' => '激活失败：插件记录不存在');
            }
            $row0 = $check[0];
            $status_val = is_array($row0) ? ($row0['status'] ?? null) : (isset($row0->status) ? $row0->status : null);
            if ($status_val === 'active' || $status_val == self::STATUS_ACTIVE || (int) $status_val === 1) {
                return array('error' => '插件已激活');
            }
            return array('error' => '激活失败：未更新任何记录');
        }

        // 成功更新，开始执行钩子与脚本
        // 先加载插件主文件，确保激活钩子已完成注册
        self::load_plugin($plugin_dir);

        // 运行插件激活脚本
        self::run_plugin_activation_script($plugin_dir);

        // 执行激活钩子（此时已加载主文件，能正确命中 register_activation_hook 注册的回调）
        self::run_activation_hook($plugin_dir);

        // 清除缓存
        unset(self::$plugins_cache[$plugin_dir]);
        self::$active_plugins[] = $plugin_dir;

        // 清除数据库查询缓存，避免状态读取到旧值
        if (function_exists('todo_clear_db_cache')) {
            todo_clear_db_cache('*plugins*');
        }
        if (class_exists('Todo_Database_Optimizer')) {
            try {
                Todo_Database_Optimizer::getInstance()->clearTableCache(TABLE_PREFIX . 'plugins');
                Todo_Database_Optimizer::getInstance()->clearTableCache(TABLE_PREFIX . 'plugin_meta');
            } catch (Throwable $e) { /* ignore */
            }
        }
        if (function_exists('clear_table_cache')) {
            clear_table_cache();
        }

        // 清除 active_plugins_list 缓存
        if (class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::delete('active_plugins_list', 'plugins', 'global');
        }

        return true;
    }

    /**
     * 停用插件
     * 
     * @param string $plugin_dir 插件目录名
     * @return bool|array
     */
    public static function deactivate_plugin($plugin_dir)
    {
        // 确保数据库连接为 CMS_DB
        self::ensure_db_connection();

        $plugin = self::get_plugin($plugin_dir);
        if (!$plugin || ($plugin['status'] != 'active' && $plugin['status'] != self::STATUS_ACTIVE)) {
            return array('error' => '插件未激活');
        }

        // 检查是否有其他插件依赖此插件
        $dependents = self::get_plugin_dependents($plugin_dir);
        if (!empty($dependents)) {
            return array('error' => '有其他插件依赖此插件: ' . implode(', ', $dependents));
        }

        // 先更新数据库状态，再触发插件脚本/钩子，避免插件内部逻辑影响记录存在性
        $query = "UPDATE " . TABLE_PREFIX . "plugins SET status = 'inactive' WHERE directory = ?";
        $affected = self::$tododb->query($query, [$plugin_dir]);

        // 数据库错误
        if ($affected === false) {
            return array('error' => '停用失败：数据库错误');
        }

        // 未更新任何记录，进一步核验记录是否存在/状态
        if ((int) $affected === 0) {
            $check = self::$tododb->get_row("SELECT id, status FROM " . TABLE_PREFIX . "plugins WHERE directory = ?", [$plugin_dir], 'ARRAY_A');
            if (!$check) {
                // 若记录不存在，可能是插件的停用脚本/钩子自行卸载了记录，视为停用成功，继续清理流程
                // 为稳妥起见，这里不返回错误，保持状态一致性
            } else {
                $status_val = $check['status'] ?? null;
                if ($status_val !== 'inactive' && $status_val != self::STATUS_INACTIVE && (string) $status_val !== '0') {
                    return array('error' => '停用失败：未更新任何记录');
                }
            }
            // 若数据库中已是非激活状态或记录已移除，则继续走清理流程，保证内存状态一致
        }

        // 运行插件停用脚本（在状态更新之后执行）
        self::run_plugin_deactivation_script($plugin_dir);

        // 执行停用钩子（在状态更新之后执行）
        self::run_deactivation_hook($plugin_dir);

        // 清除缓存
        unset(self::$plugins_cache[$plugin_dir]);

        // 从激活列表中移除
        $key = array_search($plugin_dir, self::$active_plugins);
        if ($key !== false) {
            unset(self::$active_plugins[$key]);
        }

        // 清除数据库查询缓存，避免状态读取到旧值
        if (function_exists('todo_clear_db_cache')) {
            todo_clear_db_cache('*plugins*');
        }
        if (class_exists('Todo_Database_Optimizer')) {
            try {
                Todo_Database_Optimizer::getInstance()->clearTableCache(TABLE_PREFIX . 'plugins');
                Todo_Database_Optimizer::getInstance()->clearTableCache(TABLE_PREFIX . 'plugin_meta');
            } catch (Throwable $e) { /* ignore */
            }
        }
        if (function_exists('clear_table_cache')) {
            clear_table_cache();
        }

        // 清除 active_plugins_list 缓存
        if (class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::delete('active_plugins_list', 'plugins', 'global');
        }

        return true;
    }

    /**
     * 卸载插件
     * 
     * @param string $plugin_dir 插件目录名
     * @param bool $delete_files 是否删除文件
     * @return bool|array
     */
    public static function uninstall_plugin($plugin_dir, $delete_files = false)
    {
        // 确保数据库连接已初始化
        self::ensure_db_connection();

        $plugin = self::get_plugin($plugin_dir);
        if (!$plugin) {
            return array('error' => '插件不存在');
        }

        if ($plugin['status'] == 'active' || $plugin['status'] == self::STATUS_ACTIVE) {
            return array('error' => '不能卸载激活的插件');
        }

        // 检查是否有其他插件依赖此插件
        $dependents = self::get_plugin_dependents($plugin_dir);
        if (!empty($dependents)) {
            return array('error' => '有其他插件依赖此插件: ' . implode(', ', $dependents));
        }

        // 开始事务
        self::$tododb->begin_transaction();

        try {
            // 删除插件元数据
            $delete_meta_query = "DELETE FROM " . TABLE_PREFIX . "plugin_meta WHERE plugin_id = ?";
            $deleted = self::$tododb->query($delete_meta_query, [$plugin['id']]);
            if ($deleted === false) {
                throw new Exception('Failed to delete plugin meta');
            }

            // 删除插件记录
            $delete_plugin_query = "DELETE FROM " . TABLE_PREFIX . "plugins WHERE id = ?";
            $result = self::$tododb->query($delete_plugin_query, [$plugin['id']]);

            if ($result === false) {
                throw new Exception('Failed to uninstall plugin');
            }

            // 运行插件卸载脚本
            self::run_plugin_uninstall_script($plugin_dir);

            // 执行卸载钩子
            self::run_uninstall_hook($plugin_dir);

            // 删除插件文件
            if ($delete_files) {
                $plugin_path = TODO_ROOT . '/' . self::$plugins_dir . '/' . $plugin_dir;
                if (is_dir($plugin_path)) {
                    self::delete_directory($plugin_path);
                }
            }

            // 提交事务
            self::$tododb->commit();

            // 清除缓存
            unset(self::$plugins_cache[$plugin_dir]);

            // 清除数据库查询缓存，避免状态读取到旧值
            if (function_exists('todo_clear_db_cache')) {
                todo_clear_db_cache('*plugins*');
            }
            if (class_exists('Todo_Database_Optimizer')) {
                try {
                    Todo_Database_Optimizer::getInstance()->clearTableCache(TABLE_PREFIX . 'plugins');
                    Todo_Database_Optimizer::getInstance()->clearTableCache(TABLE_PREFIX . 'plugin_meta');
                } catch (Throwable $e) { /* ignore */
                }
            }
            if (function_exists('clear_table_cache')) {
                clear_table_cache();
            }

            // 清除 active_plugins_list 缓存
            if (class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::delete('active_plugins_list', 'plugins', 'global');
            }

            return true;

        } catch (Exception $e) {
            // 回滚事务
            self::$tododb->rollback();
            self::safe_log('error', 'Plugin uninstallation failed: ' . $e->getMessage());
            return array('error' => '卸载失败');
        }
    }

    /**
     * 添加动作钩子
     * 
     * @param string $hook_name 钩子名称
     * @param callable $callback 回调函数
     * @param int $priority 优先级
     * @param int $accepted_args 接受的参数数量
     */
    public static function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1)
    {
        if (class_exists('Todo_Core_Kernel')) {
            return Todo_Core_Kernel::register_hook($hook_name, $callback, $priority, $accepted_args);
        }
        return false;
    }

    /**
     * 执行动作钩子
     * 
     * @param string $hook_name 钩子名称
     * @param mixed ...$args 参数
     */
    public static function do_action($hook_name, ...$args)
    {
        if (class_exists('Todo_Core_Kernel')) {
            return Todo_Core_Kernel::execute_hook($hook_name, ...$args);
        }
    }

    /**
     * 添加过滤器
     * 
     * @param string $filter_name 过滤器名称
     * @param callable $callback 回调函数
     * @param int $priority 优先级
     * @param int $accepted_args 接受的参数数量
     */
    public static function add_filter($filter_name, $callback, $priority = 10, $accepted_args = 1)
    {
        return self::add_action($filter_name, $callback, $priority, $accepted_args);
    }

    /**
     * 应用过滤器
     * 
     * @param string $filter_name 过滤器名称
     * @param mixed $value 要过滤的值
     * @param mixed ...$args 额外参数
     * @return mixed
     */
    public static function apply_filters($filter_name, $value, ...$args)
    {
        if (class_exists('Todo_Core_Kernel')) {
            return Todo_Core_Kernel::filter_data($filter_name, $value, ...$args);
        }
        return $value;
    }

    /**
     * 移除动作钩子
     * 
     * @param string $hook_name 钩子名称
     * @param callable $callback 回调函数
     * @param int $priority 优先级
     * @return bool
     */
    public static function remove_action($hook_name, $callback, $priority = 10)
    {
        if (!isset(self::$hooks[$hook_name][$priority])) {
            return false;
        }

        foreach (self::$hooks[$hook_name][$priority] as $key => $callback_data) {
            if ($callback_data['callback'] === $callback) {
                unset(self::$hooks[$hook_name][$priority][$key]);
                return true;
            }
        }

        return false;
    }

    /**
     * 移除过滤器
     * 
     * @param string $filter_name 过滤器名称
     * @param callable $callback 回调函数
     * @param int $priority 优先级
     * @return bool
     */
    public static function remove_filter($filter_name, $callback, $priority = 10)
    {
        if (!isset(self::$filters[$filter_name][$priority])) {
            return false;
        }

        foreach (self::$filters[$filter_name][$priority] as $key => $callback_data) {
            if ($callback_data['callback'] === $callback) {
                unset(self::$filters[$filter_name][$priority][$key]);
                return true;
            }
        }

        return false;
    }

    /**
     * 获取插件配置
     * 
     * @param string $plugin_dir 插件目录名
     * @return array|null
     */
    private static function get_plugin_config($plugin_dir)
    {
        // 尝试读取PHP配置文件
        $php_config_file = TODO_ROOT . '/' . self::$plugins_dir . '/' . $plugin_dir . '/plugin.php';
        if (file_exists($php_config_file)) {
            $config = include $php_config_file;
            if (is_array($config)) {
                return $config;
            }
        }

        return null;
    }

    /**
     * 验证插件结构
     * 
     * @param string $plugin_dir 插件目录名
     * @return bool|string
     */
    private static function validate_plugin_structure($plugin_dir)
    {
        $plugin_path = TODO_ROOT . '/' . self::$plugins_dir . '/' . $plugin_dir;

        if (!is_dir($plugin_path)) {
            return '插件目录不存在';
        }

        // 获取插件配置
        $config = self::get_plugin_config($plugin_dir);

        // 检查主文件
        $main_file = null;

        if ($config && is_array($config) && (!empty($config['main']) || !empty($config['entry']) || !empty($config['main_file']))) {
            // 配置文件中指定了主文件（兼容 main/entry/main_file 键；值可为字符串或数组）
            $main_rel = null;
            foreach (array('main', 'entry', 'main_file') as $k) {
                if (!empty($config[$k])) {
                    $main_rel = $config[$k];
                    break;
                }
            }
            if (is_array($main_rel)) {
                foreach ($main_rel as $cand) {
                    if (is_string($cand) && $cand !== '') {
                        $main_rel = $cand;
                        break;
                    }
                }
            }
            if (is_string($main_rel) && $main_rel !== '') {
                $main_file = $plugin_path . '/' . ltrim($main_rel, '/');
            }
        }
        // 按优先级查找主文件（回退策略，当未能从配置中解析出主文件时）
        if (!$main_file || !file_exists($main_file)) {
            // 按优先级查找主文件
            $possible_files = [
                $plugin_path . '/' . $plugin_dir . '.php',  // 目录名.php
                $plugin_path . '/index.php',                // index.php
            ];

            // 如果plugin.php不是配置文件，也加入候选
            $php_config_file = $plugin_path . '/plugin.php';
            if (file_exists($php_config_file)) {
                $content = file_get_contents($php_config_file);
                if (!preg_match('/^\s*<\?php\s+return\s+array/', $content)) {
                    $possible_files[] = $php_config_file;
                }
            }

            foreach ($possible_files as $file) {
                if (file_exists($file)) {
                    $main_file = $file;
                    break;
                }
            }
        }

        if (!$main_file || !file_exists($main_file)) {
            return '缺少插件主文件 (' . $plugin_dir . '.php 或 index.php)';
        }

        return true;
    }

    /**
     * 检查插件依赖
     * 
     * @param array $config 插件配置
     * @return bool|string
     */
    private static function check_plugin_dependencies($config)
    {
        if (empty($config['dependencies'])) {
            return true;
        }

        // 统一读取依赖配置
        $deps = $config['dependencies'];

        // 先处理非插件型依赖
        if (isset($deps['php_extensions'])) {
            foreach ($deps['php_extensions'] as $extension) {
                if (!extension_loaded($extension)) {
                    return "缺少PHP扩展: {$extension}";
                }
            }
        }
        if (isset($deps['todo_modules'])) {
            // 预留：如需实现模块依赖校验，可在此补充逻辑
        }

        // 辅助：标准化单条依赖检查（字符串或数组）
        $check_one_dep = function ($dependency, $failOnMissing = true) {
            if (is_string($dependency)) {
                $dep_name = $dependency;
                $dep_version = null;
            } elseif (is_array($dependency)) {
                $dep_name = $dependency['name']
                    ?? ($dependency['plugin'] ?? ($dependency['directory'] ?? ($dependency[0] ?? null)));
                $dep_version = $dependency['version']
                    ?? ($dependency['min_version'] ?? ($dependency[1] ?? null));
            } else {
                $dep_name = null;
                $dep_version = null;
            }

            if (!$dep_name) {
                if (function_exists('error_log')) {
                    error_log('Plugin dependency entry missing name: ' . print_r($dependency, true));
                }
                return true; // 跳过无效项，不阻断
            }

            $dep_plugin = self::get_plugin($dep_name);
            if (!$dep_plugin || (($dep_plugin['status'] ?? null) != self::STATUS_ACTIVE)) {
                return $failOnMissing ? "缺少依赖插件: {$dep_name}" : true; // 可选依赖不阻断
            }

            if ($dep_version && isset($dep_plugin['version']) && version_compare($dep_plugin['version'], $dep_version, '<')) {
                return "依赖插件 {$dep_name} 版本过低，需要 {$dep_version}";
            }

            return true;
        };

        // 如果包含 required/optional/conflicts 结构，分开处理
        $hasStructured = isset($deps['required']) || isset($deps['optional']) || isset($deps['conflicts']);
        if ($hasStructured) {
            // 1) 必需依赖：缺失直接阻断
            if (!empty($deps['required'])) {
                foreach ((array) $deps['required'] as $dependency) {
                    $res = $check_one_dep($dependency, true);
                    if ($res !== true) {
                        return $res;
                    }
                }
            }
            // 2) 冲突依赖：已启用则阻断
            if (!empty($deps['conflicts'])) {
                foreach ((array) $deps['conflicts'] as $dependency) {
                    // 仅解析名称，用于判断是否已启用
                    $name = is_string($dependency) ? $dependency : ($dependency['name']
                        ?? ($dependency['plugin'] ?? ($dependency['directory'] ?? ($dependency[0] ?? null))));
                    if ($name) {
                        $conflict = self::get_plugin($name);
                        if ($conflict && (($conflict['status'] ?? null) == self::STATUS_ACTIVE)) {
                            return "冲突插件已启用: {$name}";
                        }
                    }
                }
            }
            // 3) 可选依赖：不阻断，仅日志提示
            if (!empty($deps['optional'])) {
                foreach ((array) $deps['optional'] as $dependency) {
                    $res = $check_one_dep($dependency, false);
                    if ($res !== true && function_exists('error_log')) {
                        // 仅记录可选依赖未满足的信息，不影响激活
                        error_log('[TodoCMS] Optional dependency not satisfied: ' . $res);
                    }
                }
            }
            return true;
        }

        // 向后兼容：旧式平铺依赖数组
        $plugin_dependencies = $deps;
        unset($plugin_dependencies['php_extensions'], $plugin_dependencies['todo_modules']);
        foreach ($plugin_dependencies as $dependency) {
            $res = $check_one_dep($dependency, true);
            if ($res !== true) {
                return $res;
            }
        }

        return true;
    }

    /**
     * 获取依赖此插件的其他插件
     * 
     * @param string $plugin_dir 插件目录名
     * @return array
     */
    private static function get_plugin_dependents($plugin_dir)
    {
        $dependents = array();
        $active_plugins = self::get_plugins(false);

        foreach ($active_plugins as $plugin) {
            $config = $plugin['config'];
            if (!empty($config['dependencies'])) {
                foreach ($config['dependencies'] as $dependency) {
                    if (is_string($dependency)) {
                        $dep_name = $dependency;
                    } elseif (is_array($dependency)) {
                        $dep_name = $dependency['name']
                            ?? ($dependency['plugin'] ?? ($dependency['directory'] ?? ($dependency[0] ?? null)));
                    } else {
                        $dep_name = null;
                    }
                    if ($dep_name === $plugin_dir) {
                        $dependents[] = $plugin['name'] ?? $plugin_dir;
                        break;
                    }
                }
            }
        }

        return $dependents;
    }

    /**
     * 加载激活的插件
     */
    private static function load_active_plugins()
    {
        // 确保数据库连接已初始化
        self::ensure_db_connection();

        // 尝试从缓存获取
        if (class_exists('Todo_Cache_Manager')) {
            $cached_plugins = Todo_Cache_Manager::get('active_plugins_list', 'plugins', 'global');
            if ($cached_plugins !== false && is_array($cached_plugins)) {
                foreach ($cached_plugins as $dir) {
                    self::load_plugin($dir);
                    self::$active_plugins[] = $dir;
                }
                return;
            }
        }

        // 检查表是否存在
        if (!self::$tododb->table_exists(TABLE_PREFIX . 'plugins')) {
            return;
        }

        $query = "SELECT directory FROM " . TABLE_PREFIX . "plugins WHERE status = 'active' ORDER BY name";
        $result = self::$tododb->query($query);

        $active_dirs = array();

        // self::$tododb->query() 在 SELECT 时返回的是结果数组，而不是 PDOStatement
        if (is_array($result) && !empty($result)) {
            foreach ($result as $row) {
                if (isset($row['directory'])) {
                    self::load_plugin($row['directory']);
                    self::$active_plugins[] = $row['directory'];
                    $active_dirs[] = $row['directory'];
                }
            }
        }

        // 保存到缓存 (使用 86400 秒有效期)
        if (class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::set('active_plugins_list', $active_dirs, 86400, 'plugins', 'global');
        }
    }

    /**
     * 加载单个插件
     * 
     * @param string $plugin_dir 插件目录名
     */
    private static function load_plugin($plugin_dir)
    {
        $plugin_path = TODO_ROOT . '/' . self::$plugins_dir . '/' . $plugin_dir;

        // 获取插件配置
        $config = self::get_plugin_config($plugin_dir);

        // 确定主文件路径
        $main_file = null;

        if ($config && is_array($config) && (!empty($config['main']) || !empty($config['entry']) || !empty($config['main_file']))) {
            // 配置文件中指定了主文件（兼容 main/entry/main_file 键；值可为字符串或数组）
            $main_rel = null;
            foreach (array('main', 'entry', 'main_file') as $k) {
                if (!empty($config[$k])) {
                    $main_rel = $config[$k];
                    break;
                }
            }
            if (is_array($main_rel)) {
                foreach ($main_rel as $cand) {
                    if (is_string($cand) && $cand !== '') {
                        $main_rel = $cand;
                        break;
                    }
                }
            }
            if (is_string($main_rel) && $main_rel !== '') {
                $main_file = $plugin_path . '/' . ltrim($main_rel, '/');
            }
        }
        // 按优先级查找主文件（回退策略，当未能从配置中解析出主文件或文件不存在时）
        if (!$main_file || !file_exists($main_file)) {
            $possible_files = [
                $plugin_path . '/' . $plugin_dir . '.php',  // 目录名.php
                $plugin_path . '/index.php',                // index.php
                $plugin_path . '/plugin.php'                // plugin.php (如果不是配置文件)
            ];

            foreach ($possible_files as $file) {
                if (file_exists($file)) {
                    // 如果是plugin.php，检查是否为配置文件
                    if (basename($file) === 'plugin.php') {
                        $content = file_get_contents($file);
                        // 如果文件开头是<?php
                        if (preg_match('/^\s*<\?php\s+return\s+array/', $content)) {
                            continue;
                        }
                    }
                    $main_file = $file;
                    break;
                }
            }
        }

        // 加载主文件
        if ($main_file && file_exists($main_file)) {
            try {
                include_once $main_file;
            } catch (Exception $e) {
                self::safe_log('error', 'Plugin main file load error: ' . $e->getMessage());
                return false;
            }
        } else {
            self::safe_log('error', 'Plugin main file not found for: ' . $plugin_dir);
            return false;
        }

        // 加载依赖文件
        if ($config && is_array($config) && !empty($config['dependencies'])) {
            // 处理文件依赖（如果存在files字段）
            if (isset($config['dependencies']['files']) && is_array($config['dependencies']['files'])) {
                foreach ($config['dependencies']['files'] as $dependency) {
                    if (is_string($dependency)) {
                        $dep_file = $plugin_path . '/' . $dependency;
                        if (file_exists($dep_file)) {
                            try {
                                include_once $dep_file;
                            } catch (Exception $e) {
                                self::safe_log('error', 'Plugin dependency load error: ' . $e->getMessage());
                            }
                        }
                    }
                }
            }
            // 如果dependencies是数组格式（向后兼容）
            elseif (is_array($config['dependencies']) && !isset($config['dependencies']['files'])) {
                foreach ($config['dependencies'] as $dependency) {
                    if (is_string($dependency)) {
                        $dep_file = $plugin_path . '/' . $dependency;
                        if (file_exists($dep_file)) {
                            try {
                                include_once $dep_file;
                            } catch (Exception $e) {
                                self::safe_log('error', 'Plugin dependency load error: ' . $e->getMessage());
                            }
                        }
                    }
                }
            }
        }

        // 初始化插件实例（如果插件类有get_instance方法）
        $plugin_class = null;
        if ($config && isset($config['hooks']['activation'])) {
            $activation_hook = $config['hooks']['activation'];
            if (strpos($activation_hook, '::') !== false) {
                list($plugin_class, $method) = explode('::', $activation_hook, 2);
            }
        }

        // 尝试常见的插件类名模式
        if (!$plugin_class) {
            $possible_classes = [
                ucfirst($plugin_dir) . '_Plugin',
                str_replace('-', '_', ucfirst($plugin_dir)) . '_Plugin',
                str_replace('-', '', ucfirst($plugin_dir)) . '_Plugin'
            ];

            foreach ($possible_classes as $class_name) {
                if (class_exists($class_name)) {
                    $plugin_class = $class_name;
                    break;
                }
            }
        }

        // 如果找到插件类且有get_instance方法，则初始化
        if ($plugin_class && class_exists($plugin_class) && method_exists($plugin_class, 'get_instance')) {
            try {
                call_user_func(array($plugin_class, 'get_instance'));
                self::safe_log('info', "Plugin initialized: $plugin_class");
            } catch (Exception $e) {
                self::safe_log('error', "Plugin initialization failed for $plugin_class: " . $e->getMessage());
            }
        }

        // 统一注册入口：优先按照配置文件 hooks.register，其次尝试类静态 register() 或函数式 todo_register_plugin_*
        try {
            $registered = self::register_plugin_entry($plugin_dir, $config);
            // 如果找不到注册入口，这是正常的（不是所有插件都需要显式注册函数）
            // 只在调试模式下记录，避免日志污染
            if (!$registered && defined('TODO_DEBUG') && TODO_DEBUG) {
                self::safe_log('info', 'Plugin ' . $plugin_dir . ' does not have a register entry, using class initialization only.');
            }
        } catch (Throwable $e) {
            // 只在调试模式下记录错误，避免日志污染
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                self::safe_log('error', 'Plugin register entry failed for ' . $plugin_dir . ': ' . $e->getMessage());
            }
        }

        // 统一生命周期钩子自动注册：若插件类实现了 activate/deactivate/uninstall，但未显式注册，则自动桥接
        try {
            if (!isset(self::$activation_hooks[$plugin_dir]) || !isset(self::$deactivation_hooks[$plugin_dir]) || !isset(self::$uninstall_hooks[$plugin_dir])) {
                // 尝试获取实例（若为单例）或直接实例化
                $instance = null;
                if ($plugin_class && class_exists($plugin_class)) {
                    if (method_exists($plugin_class, 'get_instance')) {
                        try {
                            $instance = call_user_func(array($plugin_class, 'get_instance'));
                        } catch (Throwable $e) {
                            $instance = null;
                        }
                    }
                    if (!$instance) {
                        // 尝试无参构造
                        try {
                            $instance = new $plugin_class();
                        } catch (Throwable $e) { /* 忽略 */
                        }
                    }

                    // 注册激活钩子
                    if (!isset(self::$activation_hooks[$plugin_dir])) {
                        if ($instance && method_exists($instance, 'activate')) {
                            self::register_activation_hook($plugin_dir, array($instance, 'activate'));
                        } elseif (is_callable(array($plugin_class, 'activate'))) {
                            self::register_activation_hook($plugin_dir, array($plugin_class, 'activate'));
                        }
                    }

                    // 注册停用钩子
                    if (!isset(self::$deactivation_hooks[$plugin_dir])) {
                        if ($instance && method_exists($instance, 'deactivate')) {
                            self::register_deactivation_hook($plugin_dir, array($instance, 'deactivate'));
                        } elseif (is_callable(array($plugin_class, 'deactivate'))) {
                            self::register_deactivation_hook($plugin_dir, array($plugin_class, 'deactivate'));
                        }
                    }

                    // 注册卸载钩子
                    if (!isset(self::$uninstall_hooks[$plugin_dir])) {
                        if ($instance && method_exists($instance, 'uninstall')) {
                            self::register_uninstall_hook($plugin_dir, array($instance, 'uninstall'));
                        } elseif (is_callable(array($plugin_class, 'uninstall'))) {
                            self::register_uninstall_hook($plugin_dir, array($plugin_class, 'uninstall'));
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // 自动注册失败不阻断插件运行
            self::safe_log('warning', 'Auto-bridge lifecycle hooks failed for ' . $plugin_dir . ': ' . $e->getMessage());
        }

        // 建议性规范校验（不阻断运行）：仅在管理后台展示提示
        try {
            $validation = self::validate_plugin_recommendations($plugin_dir, $config);
            if (!empty($validation['errors']) || !empty($validation['warnings'])) {
                $summary = [];
                if (!empty($validation['errors'])) {
                    $summary[] = '错误: ' . implode('; ', $validation['errors']);
                }
                if (!empty($validation['warnings'])) {
                    $summary[] = '建议: ' . implode('; ', $validation['warnings']);
                }
                $msg = '插件 [' . $plugin_dir . '] 规范校验结果：' . implode(' | ', $summary) . '。请参考后台 docs《插件开发标准》的“插件骨架”和“钩子目录”完成升级。';
                if (function_exists('todo_add_admin_notice') && (defined('IS_ADMIN') || (function_exists('current_user_can') && current_user_can('activate_plugins')))) {
                    todo_add_admin_notice($msg, 'warning');
                } else {
                    self::safe_log('warning', $msg);
                }
            }
        } catch (Throwable $e) {
            // 校验异常不影响插件加载
            self::safe_log('warning', 'Plugin recommendation validation failed for ' . $plugin_dir . ': ' . $e->getMessage());
        }

        return true;
    }

    /**
     * 初始化钩子系统
     */
    private static function init_hooks()
    {
        // 注册核心钩子
        self::register_core_hooks();
    }

    /**
     * 注册核心钩子
     */
    private static function register_core_hooks()
    {
        // 系统初始化钩子
        if (function_exists('do_action')) {
            do_action('todo_init');
        }
        self::do_action('todo_init');

        // 插件加载完成钩子
        if (function_exists('do_action')) {
            do_action('todo_plugins_loaded');
        }
        self::do_action('todo_plugins_loaded');
    }

    /**
     * 统一注册入口：解析并调用插件定义的注册方法/函数（幂等）
     * @param string $plugin_dir 插件目录名
     * @param array|null $config 插件配置
     * @return bool 是否找到并调用
     */
    private static function register_plugin_entry($plugin_dir, $config = null)
    {
        static $registered = array();
        if (isset($registered[$plugin_dir])) {
            return true;
        }

        $candidates = array();

        // 1) 配置声明的入口 hooks.register
        if (is_array($config) && isset($config['hooks']) && isset($config['hooks']['register'])) {
            $entry = $config['hooks']['register'];
            if (is_string($entry) && strpos($entry, '::') !== false) {
                list($cls, $method) = explode('::', $entry, 2);
                if (class_exists($cls) && method_exists($cls, $method)) {
                    $candidates[] = array($cls, $method);
                }
            } elseif (is_string($entry)) {
                $candidates[] = $entry; // 函数名
            }
        }

        // 2) 常见插件类：静态 register()
        $classCandidates = array(
            ucfirst($plugin_dir) . '_Plugin',
            str_replace('-', '_', ucfirst($plugin_dir)) . '_Plugin',
            str_replace('-', '', ucfirst($plugin_dir)) . '_Plugin',
        );
        foreach ($classCandidates as $cls) {
            if (class_exists($cls) && method_exists($cls, 'register') && is_callable(array($cls, 'register'))) {
                $candidates[] = array($cls, 'register');
            }
        }

        // 3) 函数式候选
        $slug = strtolower($plugin_dir);
        $slug2 = str_replace('-', '_', $slug);
        $funcCandidates = array(
            "todo_register_plugin_{$slug2}",
            "todo_register_{$slug2}",
            "{$slug2}_register",
            "register_{$slug2}",
        );
        foreach ($funcCandidates as $fn) {
            if (function_exists($fn)) {
                $candidates[] = $fn;
            }
        }

        foreach ($candidates as $cb) {
            // 找到第一个可用入口即调用
            self::invoke_register_callable($cb, $plugin_dir, $config);
            $registered[$plugin_dir] = true;
            return true;
        }

        return false;
    }

    /**
     * 尝试以合适的参数调用注册回调
     * 兼容 0/1/2 参数：(), (array $config), (string $plugin_dir, array $config)
     *
     * @param callable $callable
     * @param string $plugin_dir
     * @param array|null $config
     * @return mixed
     */
    private static function invoke_register_callable($callable, $plugin_dir, $config)
    {
        if (is_array($callable)) {
            $ref = new ReflectionMethod($callable[0], $callable[1]);
        } else {
            $ref = new ReflectionFunction($callable);
        }
        $required = $ref->getNumberOfRequiredParameters();
        $total = $ref->getNumberOfParameters();

        if ($required === 0) {
            return call_user_func($callable);
        } elseif ($required === 1) {
            // 倾向传入配置
            return call_user_func($callable, $config);
        } else {
            // 传入目录与配置
            return call_user_func($callable, $plugin_dir, $config);
        }
    }

    /**
     * 插件推荐规范校验（非阻断）
     *
     * @param string $plugin_dir 插件目录名
     * @param array|null $config 插件配置
     * @return array {errors:[], warnings:[]}
     */
    private static function validate_plugin_recommendations($plugin_dir, $config = null)
    {
        $errors = array();
        $warnings = array();

        $plugin_path = TODO_ROOT . '/' . self::$plugins_dir . '/' . $plugin_dir;

        // 配置清单建议
        if (!$config || !is_array($config)) {
            $warnings[] = '建议提供 plugin.php 配置清单（返回数组）';
        } else {
            // 基本字段建议
            $basicKeys = array('name', 'version', 'description', 'author');
            foreach ($basicKeys as $k) {
                if (!isset($config[$k]) || $config[$k] === '') {
                    $warnings[] = '配置缺少字段: ' . $k;
                }
            }
            // 建议声明 hooks.register
            if (!isset($config['hooks']) || !isset($config['hooks']['register'])) {
                $warnings[] = '建议在配置中声明 hooks.register 作为统一注册入口';
            }
        }

        // 钩子目录建议
        $hasHooksDir = is_dir($plugin_path . '/hooks') || file_exists($plugin_path . '/hooks.php');
        if (!$hasHooksDir) {
            $warnings[] = '建议提供 hooks 目录或 hooks.php 用于集中管理动作/过滤器/AJAX钩子';
        }

        // 统一注册入口存在性检查（探测但不执行）
        $hasRegister = false;
        if ($config && isset($config['hooks']['register'])) {
            $hasRegister = true;
        } else {
            $slug = strtolower($plugin_dir);
            $slug2 = str_replace('-', '_', $slug);
            $funcCandidates = array(
                "todo_register_plugin_{$slug2}",
                "todo_register_{$slug2}",
                "{$slug2}_register",
                "register_{$slug2}",
            );
            foreach ($funcCandidates as $fn) {
                if (function_exists($fn)) {
                    $hasRegister = true;
                    break;
                }
            }
            if (!$hasRegister) {
                $classCandidates = array(
                    ucfirst($plugin_dir) . '_Plugin',
                    str_replace('-', '_', ucfirst($plugin_dir)) . '_Plugin',
                    str_replace('-', '', ucfirst($plugin_dir)) . '_Plugin',
                );
                foreach ($classCandidates as $cls) {
                    if (class_exists($cls) && method_exists($cls, 'register')) {
                        $hasRegister = true;
                        break;
                    }
                }
            }
        }
        if (!$hasRegister) {
            $warnings[] = '未检测到统一注册入口（register），建议尽快补充以兼容未来版本';
        }

        return array('errors' => $errors, 'warnings' => $warnings);
    }

    /**
     * 保存插件元数据
     * 
     * @param int $plugin_id 插件ID
     * @param string $meta_key 元数据键
     * @param mixed $meta_value 元数据值
     * @return bool
     */
    public static function save_plugin_meta($plugin_id, $meta_key, $meta_value)
    {
        // 确保数据库连接已初始化
        self::ensure_db_connection();

        $query = "INSERT INTO " . TABLE_PREFIX . "plugin_meta 
                  (plugin_id, meta_key, meta_value, created_at) 
                  VALUES (?, ?, ?, NOW()) 
                  ON DUPLICATE KEY UPDATE 
                  meta_value = VALUES(meta_value), updated_at = NOW()";

        $meta_value_json = is_array($meta_value) ? json_encode($meta_value) : $meta_value;

        $result = self::$tododb->query($query, [$plugin_id, $meta_key, $meta_value_json]);
        return $result !== false;
    }

    /**
     * 获取插件元数据
     * 
     * @param int $plugin_id 插件ID
     * @param string $meta_key 元数据键（可选）
     * @return array|string|null
     */
    public static function get_plugin_meta($plugin_id, $meta_key = null)
    {
        // 确保数据库连接已初始化
        self::ensure_db_connection();

        if ($meta_key) {
            $query = "SELECT meta_value FROM " . TABLE_PREFIX . "plugin_meta 
                      WHERE plugin_id = ? AND meta_key = ?";
            $results = self::$tododb->query($query, [$plugin_id, $meta_key]);

            if ($results && is_array($results) && count($results) > 0) {
                $value = $results[0]['meta_value'];
                $decoded = json_decode($value, true);
                return $decoded !== null ? $decoded : $value;
            }

            return null;
        } else {
            $query = "SELECT meta_key, meta_value FROM " . TABLE_PREFIX . "plugin_meta WHERE plugin_id = ?";
            $results = self::$tododb->query($query, [$plugin_id]);

            $meta = array();
            if ($results && is_array($results)) {
                foreach ($results as $row) {
                    $value = $row['meta_value'];
                    $decoded = json_decode($value, true);
                    $meta[$row['meta_key']] = $decoded !== null ? $decoded : $value;
                }
            }

            return $meta;
        }
    }

    /**
     * 删除插件元数据
     * 
     * @param int $plugin_id 插件ID
     * @param string $meta_key 元数据键（可选）
     * @return bool
     */
    public static function delete_plugin_meta($plugin_id, $meta_key = null)
    {
        // 确保数据库连接已初始化
        self::ensure_db_connection();

        if ($meta_key) {
            $query = "DELETE FROM " . TABLE_PREFIX . "plugin_meta WHERE plugin_id = ? AND meta_key = ?";
            $result = self::$tododb->query($query, [$plugin_id, $meta_key]);
            return $result !== false;
        } else {
            $query = "DELETE FROM " . TABLE_PREFIX . "plugin_meta WHERE plugin_id = ?";
            $result = self::$tododb->query($query, [$plugin_id]);
            return $result !== false;
        }
    }

    /**
     * 运行插件安装脚本
     * 
     * @param string $plugin_dir 插件目录名
     */
    private static function run_plugin_install_script($plugin_dir)
    {
        $install_script = TODO_ROOT . '/' . self::$plugins_dir . '/' . $plugin_dir . '/install.php';

        if (file_exists($install_script)) {
            try {
                include_once $install_script;

                if (function_exists('todo_plugin_install')) {
                    todo_plugin_install();
                }
            } catch (Exception $e) {
                self::safe_log('error', 'Plugin install script error: ' . $e->getMessage());
            }
        }
    }

    /**
     * 运行插件激活脚本
     * 
     * @param string $plugin_dir 插件目录名
     */
    private static function run_plugin_activation_script($plugin_dir)
    {
        $activation_script = TODO_ROOT . '/' . self::$plugins_dir . '/' . $plugin_dir . '/activate.php';

        if (file_exists($activation_script)) {
            try {
                include_once $activation_script;

                if (function_exists('todo_plugin_activate')) {
                    todo_plugin_activate();
                }
            } catch (Exception $e) {
                self::safe_log('error', 'Plugin activation script error: ' . $e->getMessage());
            }
        }
    }

    /**
     * 运行插件停用脚本
     * 
     * @param string $plugin_dir 插件目录名
     */
    private static function run_plugin_deactivation_script($plugin_dir)
    {
        $deactivation_script = TODO_ROOT . '/' . self::$plugins_dir . '/' . $plugin_dir . '/deactivate.php';

        if (file_exists($deactivation_script)) {
            try {
                include_once $deactivation_script;

                if (function_exists('todo_plugin_deactivate')) {
                    todo_plugin_deactivate();
                }
            } catch (Exception $e) {
                self::safe_log('error', 'Plugin deactivation script error: ' . $e->getMessage());
            }
        }
    }

    /**
     * 运行插件卸载脚本
     * 
     * @param string $plugin_dir 插件目录名
     */
    private static function run_plugin_uninstall_script($plugin_dir)
    {
        $uninstall_script = TODO_ROOT . '/' . self::$plugins_dir . '/' . $plugin_dir . '/uninstall.php';

        if (file_exists($uninstall_script)) {
            try {
                include_once $uninstall_script;

                if (function_exists('todo_plugin_uninstall')) {
                    todo_plugin_uninstall();
                }
            } catch (Exception $e) {
                self::safe_log('error', 'Plugin uninstall script error: ' . $e->getMessage());
            }
        }
    }

    /**
     * 删除目录及其内容
     * 
     * @param string $dir 目录路径
     * @return bool
     */
    private static function delete_directory($dir)
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), array('.', '..'));

        foreach ($files as $file) {
            $path = $dir . '/' . $file;

            if (is_dir($path)) {
                self::delete_directory($path);
            } else {
                unlink($path);
            }
        }

        return rmdir($dir);
    }

    /**
     * 获取插件统计
     * 
     * @return array
     */
    public static function get_plugin_stats()
    {
        // 确保数据库连接为 CMS_DB
        self::ensure_db_connection();

        $stats = array(
            'total' => 0,
            'active' => 0,
            'inactive' => 0
        );

        $query = "SELECT status, COUNT(*) as count FROM " . TABLE_PREFIX . "plugins GROUP BY status";
        $rows = self::$tododb->get_results($query, [], 'ARRAY_A');

        if ($rows && is_array($rows)) {
            foreach ($rows as $row) {
                $count = isset($row['count']) ? (int) $row['count'] : 0;
                $stats['total'] += $count;
                if (isset($row['status']) && $row['status'] === 'active') {
                    $stats['active'] = $count;
                } else {
                    $stats['inactive'] += $count;
                }
            }
        }

        return $stats;
    }

    /**
     * 获取激活的插件列表
     * 
     * @return array
     */
    public static function get_active_plugins()
    {
        return self::$active_plugins;
    }

    /**
     * 检查插件是否激活
     * 
     * @param string $plugin_dir 插件目录名
     * @return bool
     */
    public static function is_plugin_active($plugin_dir)
    {
        return in_array($plugin_dir, self::$active_plugins);
    }

    /**
     * 注册插件激活钩子
     * 
     * @param string $plugin_dir 插件目录名
     * @param callable $callback 回调函数
     */
    public static function register_activation_hook($plugin_dir, $callback)
    {
        self::$activation_hooks[$plugin_dir] = $callback;
    }

    /**
     * 注册插件停用钩子
     * 
     * @param string $plugin_dir 插件目录名
     * @param callable $callback 回调函数
     */
    public static function register_deactivation_hook($plugin_dir, $callback)
    {
        self::$deactivation_hooks[$plugin_dir] = $callback;
    }

    /**
     * 注册插件卸载钩子
     * 
     * @param string $plugin_dir 插件目录名
     * @param callable $callback 回调函数
     */
    public static function register_uninstall_hook($plugin_dir, $callback)
    {
        self::$uninstall_hooks[$plugin_dir] = $callback;
    }

    /**
     * 执行插件激活钩子
     * 
     * @param string $plugin_dir 插件目录名
     */
    private static function run_activation_hook($plugin_dir)
    {
        if (isset(self::$activation_hooks[$plugin_dir]) && is_callable(self::$activation_hooks[$plugin_dir])) {
            call_user_func(self::$activation_hooks[$plugin_dir]);
            return;
        }
        // Fallback：未注册时尝试直接调用插件类的 activate 方法
        $class = self::get_plugin_class_name($plugin_dir);
        if (class_exists($class)) {
            $instance = null;
            if (method_exists($class, 'get_instance')) {
                try {
                    $instance = call_user_func(array($class, 'get_instance'));
                } catch (Throwable $e) {
                    $instance = null;
                }
            }
            if (!$instance) {
                try {
                    $instance = new $class();
                } catch (Throwable $e) {
                    $instance = null;
                }
            }
            if ($instance && method_exists($instance, 'activate')) {
                try {
                    call_user_func(array($instance, 'activate'));
                } catch (Throwable $e) { /* 忽略 */
                }
            } elseif (is_callable(array($class, 'activate'))) {
                try {
                    call_user_func(array($class, 'activate'));
                } catch (Throwable $e) { /* 忽略 */
                }
            }
        }
    }

    /**
     * 执行插件停用钩子
     * 
     * @param string $plugin_dir 插件目录名
     */
    private static function run_deactivation_hook($plugin_dir)
    {
        if (isset(self::$deactivation_hooks[$plugin_dir]) && is_callable(self::$deactivation_hooks[$plugin_dir])) {
            call_user_func(self::$deactivation_hooks[$plugin_dir]);
            return;
        }
        // Fallback：未注册时尝试直接调用插件类的 deactivate 方法
        $class = self::get_plugin_class_name($plugin_dir);
        if (class_exists($class)) {
            $instance = null;
            if (method_exists($class, 'get_instance')) {
                try {
                    $instance = call_user_func(array($class, 'get_instance'));
                } catch (Throwable $e) {
                    $instance = null;
                }
            }
            if (!$instance) {
                try {
                    $instance = new $class();
                } catch (Throwable $e) {
                    $instance = null;
                }
            }
            if ($instance && method_exists($instance, 'deactivate')) {
                try {
                    call_user_func(array($instance, 'deactivate'));
                } catch (Throwable $e) { /* 忽略 */
                }
            } elseif (is_callable(array($class, 'deactivate'))) {
                try {
                    call_user_func(array($class, 'deactivate'));
                } catch (Throwable $e) { /* 忽略 */
                }
            }
        }
    }

    /**
     * 执行插件卸载钩子
     * 
     * @param string $plugin_dir 插件目录名
     */
    private static function run_uninstall_hook($plugin_dir)
    {
        if (isset(self::$uninstall_hooks[$plugin_dir]) && is_callable(self::$uninstall_hooks[$plugin_dir])) {
            call_user_func(self::$uninstall_hooks[$plugin_dir]);
            return;
        }
        // Fallback：未注册时尝试直接调用插件类的 uninstall 方法
        $class = self::get_plugin_class_name($plugin_dir);
        if (class_exists($class)) {
            $instance = null;
            if (method_exists($class, 'get_instance')) {
                try {
                    $instance = call_user_func(array($class, 'get_instance'));
                } catch (Throwable $e) {
                    $instance = null;
                }
            }
            if (!$instance) {
                try {
                    $instance = new $class();
                } catch (Throwable $e) {
                    $instance = null;
                }
            }
            if ($instance && method_exists($instance, 'uninstall')) {
                try {
                    call_user_func(array($instance, 'uninstall'));
                } catch (Throwable $e) { /* 忽略 */
                }
            } elseif (is_callable(array($class, 'uninstall'))) {
                try {
                    call_user_func(array($class, 'uninstall'));
                } catch (Throwable $e) { /* 忽略 */
                }
            }
        }
    }

    /**
     * 获取插件类名
     * 
     * @param string $plugin_dir 插件目录名
     * @return string
     */
    private static function get_plugin_class_name($plugin_dir)
    {
        // 尝试多种类名格式（与load_main_file方法中的逻辑一致）
        $possible_classes = [
            'Todo_' . str_replace(['-', '_'], '', ucwords($plugin_dir, '-_')) . '_Plugin',
            'Todo_' . str_replace(['-', '_'], '', ucwords($plugin_dir, '-_')),
            ucfirst($plugin_dir) . '_Plugin',
            str_replace('-', '_', ucfirst($plugin_dir)) . '_Plugin',
            str_replace('-', '', ucfirst($plugin_dir)) . '_Plugin'
        ];

        foreach ($possible_classes as $class_name) {
            if (class_exists($class_name)) {
                return $class_name;
            }
        }

        // 返回默认格式（向后兼容）
        return 'Todo_' . str_replace(['-', '_'], '', ucwords($plugin_dir, '-_')) . '_Plugin';
    }
}

// 便捷函数
if (!function_exists('todo_get_plugins')) {
    /**
     * 获取所有插件
     * 
     * @param bool $include_inactive 是否包含未激活的插件
     * @return array
     */
    function todo_get_plugins($include_inactive = true)
    {
        return Todo_Plugin_Manager::get_plugins($include_inactive);
    }
}

if (!function_exists('todo_activate_plugin')) {
    /**
     * 激活插件
     * 
     * @param string $plugin_dir 插件目录名
     * @return bool|array
     */
    function todo_activate_plugin($plugin_dir)
    {
        return Todo_Plugin_Manager::activate_plugin($plugin_dir);
    }
}

if (!function_exists('todo_deactivate_plugin')) {
    /**
     * 停用插件
     * 
     * @param string $plugin_dir 插件目录名
     * @return bool|array
     */
    function todo_deactivate_plugin($plugin_dir)
    {
        return Todo_Plugin_Manager::deactivate_plugin($plugin_dir);
    }
}

if (!function_exists('todo_add_action')) {
    /**
     * 添加动作钩子
     * 
     * @param string $hook_name 钩子名称
     * @param callable $callback 回调函数
     * @param int $priority 优先级
     * @param int $accepted_args 接受的参数数量
     */
    function todo_add_action($hook_name, $callback, $priority = 10, $accepted_args = 1)
    {
        Todo_Plugin_Manager::add_action($hook_name, $callback, $priority, $accepted_args);
    }
}

if (!function_exists('todo_do_action')) {
    /**
     * 执行动作钩子
     * 
     * @param string $hook_name 钩子名称
     * @param mixed ...$args 参数
     */
    function todo_do_action($hook_name, ...$args)
    {
        Todo_Plugin_Manager::do_action($hook_name, ...$args);
    }
}

if (!function_exists('todo_add_filter')) {
    /**
     * 添加过滤器
     * 
     * @param string $filter_name 过滤器名称
     * @param callable $callback 回调函数
     * @param int $priority 优先级
     * @param int $accepted_args 接受的参数数量
     */
    function todo_add_filter($filter_name, $callback, $priority = 10, $accepted_args = 1)
    {
        Todo_Plugin_Manager::add_filter($filter_name, $callback, $priority, $accepted_args);
    }
}

if (!function_exists('todo_apply_filters')) {
    /**
     * 应用过滤器
     * 
     * @param string $filter_name 过滤器名称
     * @param mixed $value 要过滤的值
     * @param mixed ...$args 额外参数
     * @return mixed
     */
    function todo_apply_filters($filter_name, $value, ...$args)
    {
        return Todo_Plugin_Manager::apply_filters($filter_name, $value, ...$args);
    }
}

if (!function_exists('todo_is_plugin_active')) {
    /**
     * 检查插件是否激活
     * 
     * @param string $plugin_dir 插件目录名
     * @return bool
     */
    function todo_is_plugin_active($plugin_dir)
    {
        return Todo_Plugin_Manager::is_plugin_active($plugin_dir);
    }
}

// 全局函数包装器