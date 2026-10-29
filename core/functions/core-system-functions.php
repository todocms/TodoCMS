<?php
/**
 * TodoCMS 核心系统函数
 * 高优先级缺失函数修复
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 获取原始引用页面
 * @return string
 */
function todo_get_raw_referer()
{
    return isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
}

/**
 * 验证重定向URL
 * @param string $url
 * @return bool
 */
function todo_validate_redirect($url)
{
    if (empty($url)) {
        return false;
    }

    // 检查是否为相对URL
    if (strpos($url, '/') === 0) {
        return true;
    }

    // 检查是否为同域URL
    $site_url = get_site_url();
    if (strpos($url, $site_url) === 0) {
        return true;
    }

    return false;
}

/**
 * 获取数据库连接
 * @return CMS_DB|null 在安装过程中可能返回 null
 */
function get_database_connection()
{
    // 在安装过程中，如果配置文件不存在，返回 null 而不是抛出异常
    if (defined('INSTALLING') && INSTALLING) {
        // 检查配置文件是否存在
        $config_file = defined('ABSPATH') ? ABSPATH . 'config.php' : dirname(dirname(__DIR__)) . '/config.php';
        if (!file_exists($config_file)) {
            // 配置文件不存在，这是正常的（在安装步骤1和步骤2）
            return null;
        }
        // 检查配置常量是否已定义
        if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASSWORD')) {
            // 配置常量未定义，这也是正常的（在安装步骤1和步骤2）
            return null;
        }
    }

    // 使用单例模式获取数据库连接
    if (class_exists('CMS_DB')) {
        try {
            $db = CMS_DB::get_instance();
            // 在安装模式下，如果连接失败，返回 null
            if (defined('INSTALLING') && INSTALLING && !$db->is_connected()) {
                return null;
            }
            return $db;
        } catch (Exception $e) {
            // 在安装模式下，不抛出异常
            if (defined('INSTALLING') && INSTALLING) {
                error_log('安装模式下数据库连接失败: ' . $e->getMessage());
                return null;
            }
            throw $e;
        }
    }

    // 如果CMS_DB类不存在，尝试加载并创建实例
    try {
        require_once ABSPATH . 'core/db/class-db.php';
        $db = CMS_DB::get_instance();
        // 在安装模式下，如果连接失败，返回 null
        if (defined('INSTALLING') && INSTALLING && !$db->is_connected()) {
            return null;
        }
        return $db;
    } catch (Exception $e) {
        // 在安装模式下，不抛出异常
        if (defined('INSTALLING') && INSTALLING) {
            error_log('安装模式下数据库连接失败: ' . $e->getMessage());
            return null;
        }
        error_log('Failed to get database connection: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * 获取数据库配置
 * @return array
 */
function get_database_config()
{
    return [
        'host' => defined('DB_HOST') ? DB_HOST : 'localhost',
        'database' => defined('DB_NAME') ? DB_NAME : 'todocms',
        'username' => defined('DB_USER') ? DB_USER : 'root',
        'password' => defined('DB_PASSWORD') ? DB_PASSWORD : ''
    ];
}

/**
 * PDO Replace 函数 - 为PDO实例添加replace功能
 * 
 * @param PDO $pdo PDO实例
 * @param string $table 表名
 * @param array $data 数据数组
 * @return bool|PDOStatement
 */
function pdo_replace($pdo, $table, $data)
{
    if (!$pdo instanceof PDO) {
        return false;
    }

    // 构建字段和值的数组
    $fields = array_keys($data);
    $values = array_values($data);

    // 转义表名（简单处理）
    $table = str_replace('`', '``', $table);

    // 构建字段列表
    $field_list = '`' . implode('`, `', array_map(function ($field) {
        return str_replace('`', '``', $field);
    }, $fields)) . '`';

    // 构建占位符
    $placeholders = str_repeat('?,', count($values) - 1) . '?';

    // 构建REPLACE查询
    $sql = "REPLACE INTO `{$table}` ({$field_list}) VALUES ({$placeholders})";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        return $stmt;
    } catch (PDOException $e) {
        error_log('PDO Replace error: ' . $e->getMessage());
        return false;
    }
}

/**
 * 为全局$tododb添加replace方法的包装函数
 * 
 * @param string $table 表名
 * @param array $data 数据数组
 * @return bool
 */
function tododb_replace($table, $data)
{
    global $tododb;
    return pdo_replace($tododb, $table, $data);
}