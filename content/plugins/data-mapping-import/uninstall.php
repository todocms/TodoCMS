<?php
/**
 * 插件卸载脚本
 * 清理所有相关数据和文件
 */

// 防止直接访问
if (!defined('TODO_VERSION')) {
    exit('Direct access denied');
}

/**
 * 执行插件卸载清理
 */
function data_mapping_import_uninstall() {
    global $tododb;
    
    try {
        // 1. 删除数据库表
        $tables = array(
            $tododb->prefix . 'plugin_data_mapping_templates',
            $tododb->prefix . 'plugin_data_import_jobs',
        );

        foreach ($tables as $table) {
            $tododb->query("DROP TABLE IF EXISTS `{$table}`");
        }
        
        // 2. 删除上传目录和文件
        // 统一解析上传目录（以基础uploads为根，而非按日期子目录）
        if (function_exists('todo_upload_dir')) {
            $info = todo_upload_dir(false);
            $base = isset($info['basedir']) ? $info['basedir'] : null;
        }
        if (empty($base)) {
            $base = defined('UPLOADS_DIR') ? UPLOADS_DIR : (defined('CONTENT_DIR') ? CONTENT_DIR . '/uploads' : ABSPATH . 'content/uploads');
        }
        $upload_dir = rtrim($base, '/') . '/data-mapping-import';
        if (is_dir($upload_dir)) {
            delete_directory_recursive($upload_dir);
        }
        
        // 3. 清理临时数据库（如果有残留）
        cleanup_temp_databases();
        
        // 4. 删除相关选项
        // 确保删除选项函数已加载
        if (!function_exists('todo_delete_option')) {
            $core_fn = ABSPATH . 'core/functions/todo-core-functions.php';
            if (file_exists($core_fn)) {
                require_once $core_fn;
            }
        }
        if (function_exists('todo_delete_option')) {
            todo_delete_option('data_mapping_import_version');
            todo_delete_option('data_mapping_import_settings');
        } elseif (function_exists('delete_option')) {
            delete_option('data_mapping_import_version');
            delete_option('data_mapping_import_settings');
        }
        
        // 5. 清理缓存
        if (function_exists('todo_clear_cache')) {
            todo_clear_cache();
        }
        
        // 记录卸载日志
        error_log('Data Mapping Import Plugin: Successfully uninstalled and cleaned up.');
        
    } catch (Exception $e) {
        error_log('Data Mapping Import Plugin Uninstall Error: ' . $e->getMessage());
    }
}

/**
 * 递归删除目录
 */
function delete_directory_recursive($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = array_diff(scandir($dir), array('.', '..'));
    
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            delete_directory_recursive($path);
        } else {
            unlink($path);
        }
    }
    
    return rmdir($dir);
}

/**
 * 清理临时数据库
 */
function cleanup_temp_databases() {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD);
        if ($conn->connect_error) {
            return;
        }
        
        // 查找所有临时数据库
        $result = $conn->query("SHOW DATABASES LIKE 'temp_import_%'");
        if ($result) {
            while ($row = $result->fetch_array()) {
                $temp_db = $row[0];
                $conn->query("DROP DATABASE IF EXISTS `{$temp_db}`");
            }
            $result->free();
        }
        
        $conn->close();
        
    } catch (Exception $e) {
        error_log('Cleanup temp databases error: ' . $e->getMessage());
    }
}

// 执行卸载
data_mapping_import_uninstall();

?>