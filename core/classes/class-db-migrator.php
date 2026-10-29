<?php
/**
 * Todo_DB_Migrator
 * 
 * 集中收拢并统一接管全站数据库 Schema 初始化与版本升级迁移
 * 彻底替换废除旧版正则提取与分散函数升级机制
 * 
 * @package TodoCMS
 * @subpackage Database
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access denied');
}

class Todo_DB_Migrator
{
    /**
     * 数据库版本 Option 键名
     */
    const OPTION_KEY = 'todo_db_version';

    /**
     * 获取系统目标要求版本（直接与主系统版本号 TODO_VERSION 同步）
     *
     * @return string
     */
    public static function get_target_version()
    {
        if (defined('TODO_VERSION')) {
            return (string) TODO_VERSION;
        }
        if (function_exists('todo_get_cms_version')) {
            return (string) todo_get_cms_version();
        }
        return '1.0.0';
    }

    /**
     * 检查并自动执行数据库结构升级
     *
     * @return bool
     */
    public static function check_and_run()
    {
        $target_version = self::get_target_version();
        $current_version = self::get_current_db_version();

        // 当数据库版本落后于系统主版本号时触发升级与结构同步
        if (version_compare($current_version, $target_version, '<')) {
            return self::run_migration($current_version, $target_version);
        }

        return true;
    }

    /**
     * 获取当前数据库已记录的版本号
     *
     * @return string
     */
    public static function get_current_db_version()
    {
        if (function_exists('todo_get_db_version')) {
            $v = todo_get_db_version();
            if (!empty($v) && $v !== '0') {
                return (string) $v;
            }
        }

        if (function_exists('get_option')) {
            $version = get_option(self::OPTION_KEY);
            if (!empty($version)) {
                return (string) $version;
            }
        }

        global $tododb;
        if ($tododb && method_exists($tododb, 'get_var')) {
            $table = (isset($tododb->prefix) ? $tododb->prefix : 'todo_') . 'options';
            if (self::table_exists($table)) {
                $v = $tododb->get_var("SELECT option_value FROM `{$table}` WHERE option_name = '" . self::OPTION_KEY . "' LIMIT 1");
                if (!empty($v)) {
                    return (string) $v;
                }
            }
        }

        return '0.0.0';
    }

    /**
     * 执行全量 Schema 载入与表结构无损升级
     *
     * @param string $from_version
     * @param string $to_version
     * @return bool
     */
    public static function run_migration($from_version = '0.0.0', $to_version = '', $prefix_filter = '')
    {
        if (empty($to_version)) {
            $to_version = self::get_target_version();
        }

        // 1. 载入 core/db/ 目录下全量 Schema 文件
        self::load_schema_files();

        // 2. 执行版本专项迁移函数（优先处理破坏性变动如字段重命名、旧数据格式转换等）
        self::run_version_migrations($from_version, $to_version);

        // 3. 执行所有模块的表结构建表与初始化
        self::execute_schema_initializers();

        // 4. 运行 dbDelta 增量比较与补全字段/索引（单一事实来源，支持按表前缀过滤）
        self::run_dbdelta_sync($prefix_filter);

        // 5. 更新数据库版本号与主系统版本号对齐
        self::update_db_version($to_version);

        return true;
    }

    /**
     * 运行特定版本之间的专项迁移处理函数
     * 用于处理 dbDelta 无法自动完成的破坏性改动（如字段重命名 RENAME COLUMN、旧数据结构清洗拆分等）
     *
     * @param string $from_version 当前已有数据库版本
     * @param string $to_version 目标升级版本
     */
    public static function run_version_migrations($from_version = '0.0.0', $to_version = '')
    {
        if (empty($to_version)) {
            $to_version = self::get_target_version();
        }

        // 注册版本专项迁移处理函数映射（按版本号升序排列）
        $version_migrations = [
            '1.0.6' => 'migrate_to_1_0_6',
            // '1.1.0' => 'migrate_to_1_1_0',
            // '2.0.0' => 'migrate_to_2_0_0',
        ];

        foreach ($version_migrations as $ver => $method) {
            // 当旧版本低于指定版本，且目标版本达到或超过指定版本时，触发专属迁移逻辑
            if (version_compare($from_version, $ver, '<') && version_compare($to_version, $ver, '>=')) {
                if (method_exists(__CLASS__, $method)) {
                    try {
                        call_user_func([__CLASS__, $method], $from_version, $to_version);
                    } catch (Throwable $e) {
                        error_log("Todo_DB_Migrator Version Migration Exception ({$ver}): " . $e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * 示例：v1.0.6 专项迁移函数（用于示例处理字段重命名或数据格式清洗）
     */
    protected static function migrate_to_1_0_6($from_version, $to_version)
    {
        // 示例：如果旧版本存在旧列 user_name，重命名为 username
        // self::rename_column_if_exists('users', 'user_name', 'username', "VARCHAR(60) NOT NULL DEFAULT '' COMMENT '用户名'");
    }

    /**
     * 获取 core/db/ 目录下所有 Schema 结构定义文件列表
     * 支持动态自动扫描（自动包含未来新增的任何 *-schema.php 模块）
     *
     * @return array
     */
    public static function get_schema_files()
    {
        $schema_dir = ABSPATH . '/core/db/';
        if (!is_dir($schema_dir)) {
            return [];
        }
        $all_files = glob($schema_dir . '*.php');
        $schema_files = [];
        $exclude = ['class-db.php', 'init-schema.php'];
        if (is_array($all_files)) {
            foreach ($all_files as $filepath) {
                $filename = basename($filepath);
                if (!in_array($filename, $exclude, true)) {
                    $schema_files[] = $filename;
                }
            }
        }
        return $schema_files;
    }

    /**
     * 引入 core/db/ 目录下的所有 schema 定义文件
     */
    public static function load_schema_files()
    {
        $schema_dir = ABSPATH . '/core/db/';
        $files = self::get_schema_files();

        foreach ($files as $file) {
            $filepath = $schema_dir . $file;
            if (file_exists($filepath)) {
                require_once $filepath;
            }
        }
    }

    /**
     * 执行全量建表函数
     */
    public static function execute_schema_initializers()
    {
        $functions = [
            'create_core_tables',
            'create_model_tables',
            'create_shop_tables',
            'create_member_tables',
            'create_user_level_tables',
            'create_upgrade_package_tables',
            'create_extend_tables',
            'create_taxonomy_tables',
            'create_term_meta_tables',
            'init_term_attributes_tables',
            'todo_create_language_tables',
            'todo_create_multilang_extra_tables',
            'todo_create_payment_tables',
            'create_plugin_meta_table',
        ];

        foreach ($functions as $func) {
            if (function_exists($func)) {
                try {
                    call_user_func($func);
                } catch (Throwable $e) {
                    error_log("Todo_DB_Migrator Error in {$func}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * 调用 dbDelta 深度补充缺失的表列与索引
     */
    private static function run_dbdelta_sync($prefix_filter = '')
    {
        $upgrade_file = ABSPATH . '/admin/includes/upgrade.php';
        if (file_exists($upgrade_file)) {
            require_once $upgrade_file;
        }

        if (!function_exists('dbDelta')) {
            return;
        }

        global $tododb;
        if (!$tododb) {
            return;
        }

        // 统一调用全量动态解析提取的最新 Schema SQL 集合（支持前缀过滤）
        $sqls = self::get_registered_schema_sqls($prefix_filter);
        if (!empty($sqls)) {
            try {
                dbDelta($sqls, true);
            } catch (Throwable $e) {
                error_log("Todo_DB_Migrator dbDelta Sync Error: " . $e->getMessage());
            }
        }
    }

    /**
     * 动态从 core/db/ 目录下的 Schema 定义文件中收集与提取全量 CREATE TABLE 语句
     * 真正实现“单一事实来源（Single Source of Truth）”，消除在 Migrator 中重复硬编码手写表结构 SQL 的冗余
     *
     * @param string $prefix_filter 可选的表前缀过滤串
     * @return array
     */
    public static function get_registered_schema_sqls($prefix_filter = '')
    {
        global $tododb;
        if (!$tododb) {
            return [];
        }

        $prefix = isset($tododb->prefix) ? (string) $tododb->prefix : (defined('TABLE_PREFIX') ? (string) TABLE_PREFIX : 'todo_');
        $charset_collate = method_exists($tododb, 'get_charset_collate') ? (string) $tododb->get_charset_collate() : 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $schema_dir = ABSPATH . '/core/db/';
        if (!is_dir($schema_dir)) {
            return [];
        }

        $files = self::get_schema_files();

        $sqls = [];
        $table_names_seen = [];

        foreach ($files as $file) {
            $filepath = $schema_dir . $file;
            if (!is_file($filepath)) {
                continue;
            }
            $content = (string) @file_get_contents($filepath);
            if ($content === '') {
                continue;
            }

            $offset = 0;
            while (($pos = stripos($content, 'CREATE TABLE', $offset)) !== false) {
                $semicolon = strpos($content, ';', $pos);
                if ($semicolon === false) {
                    $offset = $pos + 12;
                    continue;
                }
                $raw_sql = substr($content, $pos, $semicolon - $pos);

                // 若单引号数量不匹配，说明分号处于单引号字符串常量内部（如 COMMENT='...;'）
                while ($semicolon !== false && (substr_count($raw_sql, "'") % 2 !== 0)) {
                    $semicolon = strpos($content, ';', $semicolon + 1);
                    if ($semicolon !== false) {
                        $raw_sql = substr($content, $pos, $semicolon - $pos);
                    }
                }

                if ($semicolon === false) {
                    $offset = $pos + 12;
                    continue;
                }
                $offset = $semicolon + 1;

                $raw_sql = trim($raw_sql);
                // 不可从右侧裁剪单引号 '，因为 SQL COMMENT 结尾的单引号是合法 SQL 语法
                $raw_sql = rtrim($raw_sql, "\"\r\n\t; ");

                if (strpos($raw_sql, 'table_name') !== false) {
                    continue;
                }

                $sql = str_replace(
                    ['{$tododb->prefix}', '{$prefix}', '$tododb->prefix', '$prefix', '$charset_collate', '{$charset_collate}'],
                    [$prefix, $prefix, $prefix, $prefix, $charset_collate, $charset_collate],
                    $raw_sql
                );

                $sql = preg_replace('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS/i', 'CREATE TABLE', $sql);

                if (preg_match('/CREATE\s+TABLE\s+([`a-zA-Z0-9_]+)/i', $sql, $m)) {
                    $clean_table = str_replace('`', '', $m[1]);
                    
                    // 如果指定了表前缀过滤条件，则过滤掉非指定前缀开头的表
                    if (!empty($prefix_filter) && is_string($prefix_filter)) {
                        if (strpos($clean_table, $prefix_filter) !== 0) {
                            continue;
                        }
                    }

                    if (!isset($table_names_seen[$clean_table])) {
                        $table_names_seen[$clean_table] = true;
                        $sqls[] = $sql;
                    }
                }
            }
        }

        return $sqls;
    }

    /**
     * 更新数据库版本号记录，使其与主系统版本打平
     *
     * @param string $version
     */
    public static function update_db_version($version = null)
    {
        if (empty($version)) {
            $version = self::get_target_version();
        }

        if (function_exists('todo_set_db_version')) {
            todo_set_db_version($version);
        }

        if (function_exists('update_option')) {
            update_option(self::OPTION_KEY, $version);
            update_option('db_version', $version);
        }

        global $tododb;
        if ($tododb && method_exists($tododb, 'query')) {
            $table = (isset($tododb->prefix) ? $tododb->prefix : 'todo_') . 'options';
            if (self::table_exists($table)) {
                $escaped_v = esc_sql($version);
                $sql = "INSERT INTO `{$table}` (option_name, option_value, group_name) 
                        VALUES ('" . self::OPTION_KEY . "', '{$escaped_v}', 'general') 
                        ON DUPLICATE KEY UPDATE option_value = '{$escaped_v}'";
                $tododb->query($sql);
            }
        }
    }

    /**
     * 辅助工具：检查表是否存在
     */
    public static function table_exists($table_name)
    {
        global $tododb;
        if (!$tododb) {
            return false;
        }
        $res = $tododb->get_var("SHOW TABLES LIKE '{$table_name}'");
        return !empty($res);
    }

    /**
     * 辅助工具：检查数据列是否存在
     */
    public static function column_exists($table_name, $column_name)
    {
        global $tododb;
        if (!$tododb) {
            return false;
        }
        $prefix = isset($tododb->prefix) ? $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_');
        $clean_table = preg_replace('/^([a-zA-Z0-9]+_)?/', '', str_replace('`', '', $table_name));
        $table = $prefix . $clean_table;
        $column = str_replace('`', '', $column_name);
        $res = $tododb->get_results("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return !empty($res);
    }

    /**
     * 辅助工具：安全的字段重命名（解决 dbDelta 无法自动重命名字段的问题）
     */
    public static function rename_column_if_exists($table_name, $old_column, $new_column, $definition)
    {
        global $tododb;
        if (!$tododb) {
            return false;
        }
        $prefix = isset($tododb->prefix) ? $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_');
        $clean_table = preg_replace('/^([a-zA-Z0-9]+_)?/', '', str_replace('`', '', $table_name));
        $table = $prefix . $clean_table;
        if (self::table_exists($table) && self::column_exists($table, $old_column) && !self::column_exists($table, $new_column)) {
            $sql = "ALTER TABLE `{$table}` CHANGE COLUMN `{$old_column}` `{$new_column}` {$definition}";
            return $tododb->query($sql) !== false;
        }
        return false;
    }
}
