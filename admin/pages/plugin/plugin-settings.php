<?php
/**
 * 插件设置页面
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 检查用户权限
if (!current_user_can('manage_options')) {
    todo_die('您没有足够的权限访问此页面。');
}

// 获取插件目录
$plugin_dir = isset($_GET['plugin']) ? sanitize_text_field($_GET['plugin']) : '';

if (empty($plugin_dir)) {
    todo_die('未指定插件。');
}

// 验证插件是否存在（使用与插件管理器相同的方式）
$plugin_path = null;
$plugins_dir = 'content/plugins'; // 与 Todo_Plugin_Manager 保持一致

// 优先使用 TODO_ROOT
if (defined('TODO_ROOT')) {
    $plugin_path = TODO_ROOT . '/' . $plugins_dir . '/' . $plugin_dir;
} elseif (defined('ABSPATH')) {
    // 降级：使用 ABSPATH
    $plugin_path = ABSPATH . $plugins_dir . '/' . $plugin_dir;
} elseif (defined('PLUGIN_DIR')) {
    // 降级：使用 PLUGIN_DIR（可能是绝对路径或相对路径）
    $plugin_path = PLUGIN_DIR . '/' . $plugin_dir;
    // 如果是相对路径，尝试从当前文件位置计算
    if (!is_dir($plugin_path) && strpos($plugin_path, '/') !== 0) {
        $plugin_path = dirname(dirname(dirname(__DIR__))) . '/' . $plugin_path;
    }
} else {
    // 最后降级：从当前文件位置计算
    $plugin_path = dirname(dirname(dirname(__DIR__))) . '/' . $plugins_dir . '/' . $plugin_dir;
}

// 检查插件目录是否存在
if (!is_dir($plugin_path)) {
    // 尝试使用 get_plugin_info 检查插件是否在数据库中注册
    if (function_exists('get_plugin_info')) {
        $plugin_data = get_plugin_info($plugin_dir);
        if ($plugin_data) {
            // 插件在数据库中，但目录不存在，尝试重新构建路径
            if (defined('TODO_ROOT')) {
                $plugin_path = TODO_ROOT . '/' . $plugins_dir . '/' . $plugin_dir;
            } elseif (defined('ABSPATH')) {
                $plugin_path = ABSPATH . $plugins_dir . '/' . $plugin_dir;
            } else {
                $plugin_path = dirname(dirname(dirname(__DIR__))) . '/' . $plugins_dir . '/' . $plugin_dir;
            }

            // 如果还是不存在，给出更详细的错误信息
            if (!is_dir($plugin_path)) {
                todo_die('插件目录不存在：' . htmlspecialchars($plugin_path) . '。插件可能在数据库中注册，但文件已被删除。');
            }
        } else {
            todo_die('插件不存在。请检查插件目录：' . htmlspecialchars($plugin_path));
        }
    } else {
        todo_die('插件目录不存在：' . htmlspecialchars($plugin_path));
    }
}

// 验证插件是否已激活
$is_active = false;
if (function_exists('is_plugin_active')) {
    $is_active = is_plugin_active($plugin_dir);
} else {
    // 降级处理
    $active_plugins = function_exists('get_active_plugins') ? get_active_plugins() : array();
    $is_active = in_array($plugin_dir, $active_plugins);
}

if (!$is_active) {
    todo_die('插件未激活。请先激活插件后再访问设置页面。');
}

// 构建插件设置文件路径
$settings_file = $plugin_path . '/admin/settings.php';

if (!file_exists($settings_file)) {
    todo_die('插件设置页面不存在：' . htmlspecialchars($settings_file) . '。请检查插件是否包含 admin/settings.php 文件。');
}

// 获取插件信息
$plugin_info = array();

// 尝试从插件管理器获取插件信息
if (function_exists('get_plugin_info')) {
    $plugin_data = get_plugin_info($plugin_dir);
    if ($plugin_data && is_array($plugin_data)) {
        // 转换格式
        $plugin_info['Name'] = $plugin_data['name'] ?? $plugin_dir;
        $plugin_info['Version'] = $plugin_data['version'] ?? '未知';
        $plugin_info['Description'] = $plugin_data['description'] ?? '暂无描述';
        $plugin_info['Author'] = $plugin_data['author'] ?? '未知';
        $plugin_info['PluginURI'] = $plugin_data['plugin_uri'] ?? '';
        $plugin_info['AuthorURI'] = $plugin_data['author_uri'] ?? '';
    }
}

// 如果还没有获取到信息，尝试从文件读取
if (empty($plugin_info['Name']) || $plugin_info['Name'] === $plugin_dir) {
    // 按优先级查找主文件
    $possible_files = [
        $plugin_path . '/' . $plugin_dir . '.php',  // 目录名.php
        $plugin_path . '/index.php',                // index.php
        $plugin_path . '/plugin.php'                // plugin.php
    ];

    $main_file = null;
    foreach ($possible_files as $file) {
        if (file_exists($file)) {
            // 如果是plugin.php，检查是否为配置文件
            if (basename($file) === 'plugin.php') {
                $content = @file_get_contents($file);
                // 如果文件开头是配置文件格式，跳过
                if ($content && preg_match('/^\s*<\?php\s+return\s+array/', $content)) {
                    continue;
                }
            }
            $main_file = $file;
            break;
        }
    }

    if ($main_file && file_exists($main_file)) {
        $file_data = get_plugin_data($main_file);
        if (!empty($file_data)) {
            // 合并文件数据，文件数据优先
            $plugin_info = array_merge($plugin_info, $file_data);
        }
    }

    // 如果还是找不到，尝试查找所有PHP文件
    if (empty($plugin_info['Name']) || $plugin_info['Name'] === $plugin_dir) {
        $plugin_files = glob($plugin_path . '/*.php');
        foreach ($plugin_files as $file) {
            $file_data = get_plugin_data($file);
            if (!empty($file_data['Name'])) {
                $plugin_info = array_merge($plugin_info, $file_data);
                break;
            }
        }
    }
}

$plugin_name = !empty($plugin_info['Name']) ? $plugin_info['Name'] : $plugin_dir;

?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title"><?php echo esc_html($plugin_name); ?></h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=plugins'); ?>" class="button button-secondary">返回列表</a>
        </div>
    </div>

    <?php
    // 包含插件的设置页面
    try {
        // 设置插件上下文变量
        $current_plugin_dir = $plugin_dir;
        $current_plugin_info = $plugin_info;

        // 定义插件URL（供插件设置文件使用）
        // 使用 plugin_dir_url 函数获取正确的插件URL
        if (function_exists('plugin_dir_url')) {
            $plugin_url = plugin_dir_url($plugin_dir);
        } elseif (function_exists('todo_plugin_url')) {
            $plugin_url = todo_plugin_url($plugin_dir);
        } elseif (function_exists('todo_get_plugin_url')) {
            $plugin_url = todo_get_plugin_url($plugin_dir);
        } else {
            // 降级处理：手动构建URL
            if (function_exists('todo_get_site_url')) {
                $plugin_url = todo_get_site_url('content/plugins/' . $plugin_dir . '/');
            } elseif (function_exists('site_url')) {
                $plugin_url = site_url('content/plugins/' . $plugin_dir . '/');
            } else {
                // 最后降级：从当前URL计算
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
                $script_name = isset($_SERVER['SCRIPT_NAME']) ? dirname(dirname($_SERVER['SCRIPT_NAME'])) : '';
                $plugin_url = $protocol . $host . $script_name . '/content/plugins/' . $plugin_dir . '/';
            }
        }

        // 确保 $plugin_url 以斜杠结尾
        $plugin_url = rtrim($plugin_url, '/') . '/';

        // 包含插件设置文件
        include $settings_file;

    } catch (Exception $e) {
        echo '<div class="notice notice-error is-dismissible"><p>加载插件设置时出错: ' . esc_html($e->getMessage()) . '</p><button type="button" class="notice-dismiss">✕</button></div>';
    }
    ?>
</div>