<?php
/**
 * MySQL兼容性函数
 * 处理MySQL严格模式和GROUP BY兼容性问题
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 设置MySQL兼容模式
 * 临时禁用ONLY_FULL_GROUP_BY以兼容旧版本查询
 */
function todo_set_mysql_compatibility_mode()
{
    global $tododb;

    // 静态缓存，避免单次请求重复执行
    static $mode_set = false;
    if ($mode_set) {
        return true;
    }

    try {
        // 优化：先检查是否已经设置过（通过选项缓存），避免每次请求都查数据库
        if (function_exists('get_option')) {
            $compatibility_checked = get_option('todo_mysql_compatibility_checked');
            if ($compatibility_checked === 'yes') {
                $mode_set = true;
                return true;
            }
        }

        // 获取当前SQL模式
        $current_mode = $tododb->get_var("SELECT @@sql_mode");
        if ($current_mode !== null && $current_mode !== '') {
            // 如果包含ONLY_FULL_GROUP_BY，则移除它
            if (strpos($current_mode, 'ONLY_FULL_GROUP_BY') !== false) {
                $new_mode = str_replace('ONLY_FULL_GROUP_BY,', '', $current_mode);
                $new_mode = str_replace(',ONLY_FULL_GROUP_BY', '', $new_mode);
                $new_mode = str_replace('ONLY_FULL_GROUP_BY', '', $new_mode);

                // 清理多余的逗号
                $new_mode = preg_replace('/,+/', ',', $new_mode);
                $new_mode = trim($new_mode, ',');

                // 设置新的SQL模式
                $tododb->query("SET sql_mode = '$new_mode'");

                // 标记本次请求已设置
                $mode_set = true;

                // 标记已检查（如果是持久化配置，这里可以保存，但考虑到重启MySQL可能重置，暂时不持久化保存'checked'状态，
                // 或者我们可以保存一个带时间戳的transient，每天检查一次？）
                // 为安全起见，我们仅在本次请求内缓存。如果想要极致性能，可以保存到option并设TTL。
                // 这里我们使用 transient 缓存 24 小时
                if (function_exists('set_transient')) {
                    set_transient('todo_mysql_compatibility_check', 'ok', 86400);
                }

                return true;
            } else {
                // 已经兼容，记录缓存
                if (function_exists('set_transient')) {
                    set_transient('todo_mysql_compatibility_check', 'ok', 86400);
                }
            }
        }
    } catch (Exception $e) {
        error_log('MySQL兼容性设置失败: ' . $e->getMessage());
        return false;
    }

    $mode_set = true;
    return false;
}

/**
 * 恢复MySQL默认模式
 */
function todo_restore_mysql_default_mode()
{
    global $tododb;

    try {
        // 恢复默认的严格模式
        $default_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
        $tododb->query("SET sql_mode = '$default_mode'");
        return true;
    } catch (Exception $e) {
        error_log('MySQL模式恢复失败: ' . $e->getMessage());
        return false;
    }
}

/**
 * 检查当前MySQL模式是否兼容
 */
function todo_check_mysql_compatibility()
{
    global $tododb;

    try {
        // 优化：使用 transient 缓存
        if (function_exists('get_transient')) {
            if (get_transient('todo_mysql_compatibility_check') === 'ok') {
                return true;
            }
        }

        $current_mode = $tododb->get_var("SELECT @@sql_mode");
        if (is_string($current_mode)) {
            return strpos($current_mode, 'ONLY_FULL_GROUP_BY') === false;
        }
    } catch (Exception $e) {
        error_log('MySQL兼容性检查失败: ' . $e->getMessage());
    }

    return false;
}

/**
 * 在需要时自动设置兼容模式
 */
function todo_auto_mysql_compatibility()
{
    // 静态变量防止重复执行
    static $ran = false;
    if ($ran)
        return;
    $ran = true;

    // 优化：优先检查缓存
    if (function_exists('get_transient')) {
        if (get_transient('todo_mysql_compatibility_check') === 'ok') {
            return;
        }
    }

    if (!todo_check_mysql_compatibility()) {
        todo_set_mysql_compatibility_mode();
    }
}

// 在系统初始化时自动设置兼容模式
add_action('cms_init', 'todo_auto_mysql_compatibility');
