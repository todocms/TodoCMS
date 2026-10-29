<?php
/**
 * TodoCMS Database Upgrade Functions
 * 
 * This file contains database upgrade and maintenance functions
 * similar to TodoCMS dbDelta functionality but adapted for TodoCMS
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// Prevent direct access (but allow AJAX requests)
if (!defined('ABSPATH') && !defined('DOING_AJAX')) {
    exit('Direct access denied');
}

/**
 * Modify the database based on specified SQL statements.
 * 
 * This function is similar to TodoCMS dbDelta but adapted for TodoCMS.
 * It can create new tables, add columns, modify columns, and create indexes.
 * 
 * @global CMS_DB $tododb TodoCMS database abstraction object.
 * 
 * @param string|array $queries Optional. The query to run. Can be multiple queries
 *                              in an array, or a string of queries separated by
 *                              semicolons. Default empty string.
 * @param bool         $execute Optional. Whether to execute the query or just
 *                              return the changes. Default true.
 * @return array The strings of text changes made.
 */
function dbDelta($queries = '', $execute = true)
{
    global $tododb;

    if (empty($queries)) {
        return array();
    }

    // Initialize return array
    $cqueries = array();
    $iqueries = array();
    $for_update = array();

    // Convert string to array if needed
    if (is_string($queries)) {
        $queries = explode(';', $queries);
    }

    $global_tables = array();
    $all_todocms_tables = array();

    foreach ($queries as $qry) {
        if (empty($qry)) {
            continue;
        }

        // Remove extra whitespace and normalize
        $qry = trim($qry);
        if (empty($qry)) {
            continue;
        }

        // Strip IF NOT EXISTS so the table name is parsed correctly
        $qry = preg_replace('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+/i', 'CREATE TABLE ', $qry);

        // Parse CREATE TABLE statements
        if (preg_match('|CREATE TABLE ([^ ]*)|i', $qry, $matches)) {
            $tablename = trim($matches[1], '`');

            // Store the query for this table
            $cqueries[strtolower($tablename)] = $qry;
            $for_update[$tablename] = 'Created table ' . $tablename;
        }
        // Parse INSERT statements
        elseif (preg_match('|INSERT INTO ([^ ]*)|', $qry, $matches)) {
            $tablename = trim($matches[1], '`');
            $iqueries[] = $qry;
            $for_update[$tablename] = 'Inserted data into ' . $tablename;
        }
    }

    // Process each table
    foreach ($cqueries as $table => $qry) {
        // Check if table exists
        if (todo_table_exists($table)) {
            // Table exists, check for modifications needed
            $tablefields = todo_get_table_columns($table);

            // Parse the CREATE TABLE statement to get desired structure
            $desired_fields = todo_parse_create_table($qry);

            // Compare and update if needed
            foreach ($desired_fields as $field_name => $field_definition) {
                if (!todo_column_exists($table, $field_name)) {
                    // Add new column
                    if ($execute) {
                        todo_add_column($table, $field_name, $field_definition);
                    }
                    $for_update[$table] .= ' Added column ' . $field_name;
                }
            }
        } else {
            // Table doesn't exist, create it
            if ($execute) {
                $result = $tododb->query($qry);
                if ($result === false) {
                    $for_update[$table] = 'Failed to create table ' . $table . ': ' . $tododb->last_error();
                }
            }
        }
    }

    // Execute INSERT queries
    if ($execute) {
        foreach ($iqueries as $qry) {
            $tododb->query($qry);
        }
    }

    return $for_update;
}

/**
 * Check if a database table exists
 * 
 * @param string $table_name The table name to check
 * @return bool True if table exists, false otherwise
 */
function todo_table_exists($table_name)
{
    global $tododb;

    $prefix = isset($tododb->prefix) ? (string) $tododb->prefix : '';
    $table_name = (is_string($table_name) ? $table_name : (string) $table_name);
    if ($prefix !== '' && strpos($table_name, $prefix) !== 0) {
        $table_name = $prefix . $table_name;
    }
    $cache_key = 'table_exists_' . md5($table_name);
    if (class_exists('Todo_Cache_Manager')) {
        $cached = Todo_Cache_Manager::get($cache_key, 'database', 'global');
        if ($cached !== false) {
            return $cached;
        }
    }

    // 使用 information_schema 检查表是否存在（避免 SHOW 语句中的占位符导致 SQL 1064 错误）
    $result = $tododb->get_var(
        "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1",
        [$table_name]
    );

    $exists = !empty($result);
    if (class_exists('Todo_Cache_Manager')) {
        Todo_Cache_Manager::set($cache_key, $exists, 86400, 'database', 'global');
    }

    return $exists;
}

/**
 * Get table column information
 * 
 * @param string $table_name The table name
 * @return array Array of column information
 */
function todo_get_table_columns($table_name)
{
    global $tododb;

    $prefix = isset($tododb->prefix) ? (string) $tododb->prefix : '';
    $table_name = (is_string($table_name) ? $table_name : (string) $table_name);
    if ($prefix !== '' && strpos($table_name, $prefix) !== 0) {
        $table_name = $prefix . $table_name;
    }
    $cache_key = 'table_cols_full_' . md5($table_name);
    if (class_exists('Todo_Cache_Manager')) {
        $cached = Todo_Cache_Manager::get($cache_key, 'database', 'global');
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
    }

    $columns = $tododb->get_results("DESCRIBE `{$table_name}`", ARRAY_A);

    $result = array();
    if ($columns) {
        foreach ($columns as $column) {
            $result[$column['Field']] = $column;
        }
    }

    if (class_exists('Todo_Cache_Manager')) {
        Todo_Cache_Manager::set($cache_key, $result, 86400, 'database', 'global');
    }

    return $result;
}

/**
 * Check if a column exists in a table
 * 
 * @param string $table_name The table name
 * @param string $column_name The column name
 * @return bool True if column exists, false otherwise
 */
function todo_column_exists($table_name, $column_name)
{
    $columns = todo_get_table_columns($table_name);
    return isset($columns[$column_name]);
}

/**
 * Add a column to a table
 * 
 * @param string $table_name The table name
 * @param string $column_name The column name
 * @param string $column_definition The column definition
 * @return bool True on success, false on failure
 */
function todo_add_column($table_name, $column_name, $column_definition)
{
    global $tododb;

    $prefix = isset($tododb->prefix) ? (string) $tododb->prefix : '';
    $table_name = (is_string($table_name) ? $table_name : (string) $table_name);
    if ($prefix !== '' && strpos($table_name, $prefix) !== 0) {
        $table_name = $prefix . $table_name;
    }
    $sql = "ALTER TABLE `{$table_name}` ADD COLUMN `{$column_name}` {$column_definition}";

    return $tododb->query($sql) !== false;
}

/**
 * Modify a column in a table
 * 
 * @param string $table_name The table name
 * @param string $column_name The column name
 * @param string $column_definition The new column definition
 * @return bool True on success, false on failure
 */
function todo_modify_column($table_name, $column_name, $column_definition)
{
    global $tododb;

    $prefix = isset($tododb->prefix) ? (string) $tododb->prefix : '';
    $table_name = (is_string($table_name) ? $table_name : (string) $table_name);
    if ($prefix !== '' && strpos($table_name, $prefix) !== 0) {
        $table_name = $prefix . $table_name;
    }
    $sql = "ALTER TABLE `{$table_name}` MODIFY COLUMN `{$column_name}` {$column_definition}";

    return $tododb->query($sql) !== false;
}

/**
 * Drop a column from a table
 * 
 * @param string $table_name The table name
 * @param string $column_name The column name
 * @return bool True on success, false on failure
 */
function todo_drop_column($table_name, $column_name)
{
    global $tododb;

    $prefix = isset($tododb->prefix) ? (string) $tododb->prefix : '';
    $table_name = (is_string($table_name) ? $table_name : (string) $table_name);
    if ($prefix !== '' && strpos($table_name, $prefix) !== 0) {
        $table_name = $prefix . $table_name;
    }
    $sql = "ALTER TABLE `{$table_name}` DROP COLUMN `{$column_name}`";

    return $tododb->query($sql) !== false;
}

/**
 * Create an index on a table
 * 
 * @param string $table_name The table name
 * @param string $index_name The index name
 * @param string $columns The columns for the index
 * @param string $index_type The type of index (INDEX, UNIQUE, etc.)
 * @return bool True on success, false on failure
 */
function todo_create_index($table_name, $index_name, $columns, $index_type = 'INDEX')
{
    global $tododb;

    $prefix = isset($tododb->prefix) ? (string) $tododb->prefix : '';
    $table_name = (is_string($table_name) ? $table_name : (string) $table_name);
    if ($prefix !== '' && strpos($table_name, $prefix) !== 0) {
        $table_name = $prefix . $table_name;
    }
    $sql = "ALTER TABLE `{$table_name}` ADD {$index_type} `{$index_name}` ({$columns})";

    return $tododb->query($sql) !== false;
}

/**
 * Drop an index from a table
 * 
 * @param string $table_name The table name
 * @param string $index_name The index name
 * @return bool True on success, false on failure
 */
function todo_drop_index($table_name, $index_name)
{
    global $tododb;

    $prefix = isset($tododb->prefix) ? (string) $tododb->prefix : '';
    $table_name = (is_string($table_name) ? $table_name : (string) $table_name);
    if ($prefix !== '' && strpos($table_name, $prefix) !== 0) {
        $table_name = $prefix . $table_name;
    }
    $sql = "ALTER TABLE `{$table_name}` DROP INDEX `{$index_name}`";

    return $tododb->query($sql) !== false;
}

/**
 * Parse CREATE TABLE statement to extract field definitions
 * 
 * @param string $create_sql The CREATE TABLE SQL statement
 * @return array Array of field definitions
 */
function todo_parse_create_table($create_sql)
{
    $fields = array();

    // Extract the part between parentheses
    if (preg_match('/CREATE TABLE[^(]*\((.*)\)/is', $create_sql, $matches)) {
        $table_def = $matches[1];

        // Split by commas, but be careful of commas within parentheses
        $lines = preg_split('/,(?![^()]*\))/', $table_def);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip constraints and keys
            if (preg_match('/^(PRIMARY KEY|FOREIGN KEY|FOREIGN|FULLTEXT KEY|FULLTEXT|KEY|INDEX|UNIQUE|CONSTRAINT)/i', $line)) {
                continue;
            }

            // Extract field name and definition
            if (preg_match('/^`?([^`\s]+)`?\s+(.+)$/i', $line, $field_matches)) {
                $field_name = $field_matches[1];
                $field_def = $field_matches[2];
                $fields[$field_name] = $field_def;
            }
        }
    }

    return $fields;
}

/**
 * Get current time in MySQL format
 * 
 * @param string $type The type of time to retrieve
 * @param int $gmt Whether to use GMT
 * @return string The formatted time
 */
if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0)
    {
        switch ($type) {
            case 'mysql':
                return $gmt ? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
            case 'timestamp':
                return time();
            default:
                return $gmt ? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format($type) : date($type);
        }
    }
}

/**
 * 升级过程中的选项获取函数
 * 注意：此函数仅在升级过程中使用，与 core/functions/core.php 中的标准实现分离
 * 
 * @param string $option The option name
 * @param mixed $default The default value
 * @return mixed The option value or default
 */
function get_option_for_upgrade($option, $default = false)
{
    global $tododb;

    if (!$tododb) {
        return $default;
    }

    // 使用system_config表替代废弃的options表
    $sql = "SELECT config_value FROM {$tododb->prefix}system_config WHERE config_key = ? LIMIT 1";
    $result = $tododb->get_var($sql, [$option]);

    return $result !== null ? maybe_unserialize($result) : $default;
}

// Define constants if not already defined
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
