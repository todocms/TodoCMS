<?php
/**
 * 管理后台插件管理页面
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 检查用户权限
if (!current_user_can('activate_plugins')) {
    todo_die('您没有足够的权限访问此页面。');
}

// 获取当前操作
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
if (class_exists('Todo_Plugin_Manager') && method_exists('Todo_Plugin_Manager', 'sync_plugins_to_db')) {
    Todo_Plugin_Manager::sync_plugins_to_db();
}

// 构建重定向URL基础部分
$redirect_base = 'index.php?page=plugins';
if (isset($_GET['status']) && $_GET['status'] !== 'all') {
    $redirect_base .= '&status=' . urlencode($_GET['status']);
}
if (isset($_GET['s']) && !empty($_GET['s'])) {
    $redirect_base .= '&s=' . urlencode($_GET['s']);
}

// 处理插件操作（GET请求，需要重定向）
if ($action && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $plugin_file = isset($_REQUEST['plugin']) ? $_REQUEST['plugin'] : '';

    if (empty($plugin_file)) {
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice('未指定插件。', 'error');
        }
        todo_redirect(todo_admin_url($redirect_base));
        exit;
    }

    $redirect_url = $redirect_base;

    switch ($action) {
        case 'activate':
            if (current_user_can('activate_plugins')) {
                // 如果传入的是目录名，直接使用；如果是文件路径，保持原样
                $plugin_to_activate = $plugin_file;

                // 检查是否为目录名格式（不包含.php扩展名）
                if (!strpos($plugin_file, '.php')) {
                    // 这是目录名，直接传递给activate_plugin函数
                    // activate_plugin函数已经修改为支持目录名
                    $plugin_to_activate = $plugin_file;
                }

                $result = activate_plugin($plugin_to_activate);

                if (is_todo_error($result)) {
                    $error_msg = (is_object($result) && method_exists($result, 'get_error_message'))
                        ? $result->get_error_message()
                        : (is_array($result) && isset($result['error']) ? $result['error'] : '未知错误');
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice($error_msg, 'error');
                    }
                } else {
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice('插件已激活。', 'success');
                    }
                }
            } else {
                if (function_exists('todo_add_admin_notice')) {
                    todo_add_admin_notice('您没有权限激活插件。', 'error');
                }
            }
            break;

        case 'deactivate':
            if (current_user_can('activate_plugins')) {
                // 停用插件
                $result = deactivate_plugin($plugin_file);

                if (is_todo_error($result)) {
                    $error_msg = (is_object($result) && method_exists($result, 'get_error_message'))
                        ? $result->get_error_message()
                        : (is_array($result) && isset($result['error']) ? $result['error'] : '未知错误');
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice($error_msg, 'error');
                    }
                } elseif ($result === true) {
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice('插件已停用。', 'success');
                    }
                } else {
                    $err = '插件停用失败：' . (is_string($result) ? $result : '未知错误');
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice($err, 'error');
                    }
                }
            } else {
                if (function_exists('todo_add_admin_notice')) {
                    todo_add_admin_notice('您没有权限停用插件。', 'error');
                }
            }
            break;

        case 'delete':
            if (current_user_can('delete_plugins')) {
                // 检查插件是否为激活状态
                if (is_plugin_active($plugin_file)) {
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice('无法删除激活的插件。请先停用插件。', 'error');
                    }
                } else {
                    // 删除插件
                    $result = delete_plugin($plugin_file);

                    if (is_todo_error($result)) {
                        $error_msg = (is_object($result) && method_exists($result, 'get_error_message'))
                            ? $result->get_error_message()
                            : (is_array($result) && isset($result['error']) ? $result['error'] : '未知错误');
                        if (function_exists('todo_add_admin_notice')) {
                            todo_add_admin_notice($error_msg, 'error');
                        }
                    } else {
                        if (function_exists('todo_add_admin_notice')) {
                            todo_add_admin_notice('插件已删除。', 'success');
                        }
                    }
                }
            } else {
                if (function_exists('todo_add_admin_notice')) {
                    todo_add_admin_notice('您没有权限删除插件。', 'error');
                }
            }
            break;
    }

    // 重定向到列表页，防止重复提交
    todo_redirect(todo_admin_url($redirect_url));
    exit;
}

// 处理批量操作（POST请求，需要重定向）
if (isset($_POST['action']) && isset($_POST['checked']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $bulk_action = $_POST['action'];
    $checked_plugins = $_POST['checked'];

    $redirect_url = $redirect_base;

    if (!empty($checked_plugins)) {
        switch ($bulk_action) {
            case 'activate-selected':
                if (current_user_can('activate_plugins')) {
                    $success_count = 0;
                    foreach ($checked_plugins as $plugin_file) {
                        $plugin_to_activate = $plugin_file;
                        if (!strpos($plugin_file, '.php')) {
                            $plugin_to_activate = $plugin_file;
                        }
                        $result = activate_plugin($plugin_to_activate);
                        if (!is_todo_error($result)) {
                            $success_count++;
                        }
                    }
                    if ($success_count > 0) {
                        if (function_exists('todo_add_admin_notice')) {
                            todo_add_admin_notice('已成功激活 ' . $success_count . ' 个插件。', 'success');
                        }
                    } else {
                        if (function_exists('todo_add_admin_notice')) {
                            todo_add_admin_notice('插件激活失败。', 'error');
                        }
                    }
                } else {
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice('您没有权限激活插件。', 'error');
                    }
                }
                break;

            case 'deactivate-selected':
                if (current_user_can('activate_plugins')) {
                    $success_count = 0;
                    foreach ($checked_plugins as $plugin_file) {
                        $result = deactivate_plugin($plugin_file);
                        if (!is_todo_error($result) && $result === true) {
                            $success_count++;
                        }
                    }
                    if ($success_count > 0) {
                        if (function_exists('todo_add_admin_notice')) {
                            todo_add_admin_notice('已成功停用 ' . $success_count . ' 个插件。', 'success');
                        }
                    } else {
                        if (function_exists('todo_add_admin_notice')) {
                            todo_add_admin_notice('插件停用失败。', 'error');
                        }
                    }
                } else {
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice('您没有权限停用插件。', 'error');
                    }
                }
                break;

            case 'delete-selected':
                if (current_user_can('delete_plugins')) {
                    $active_plugins = array();
                    $deleted_count = 0;

                    foreach ($checked_plugins as $plugin_file) {
                        if (is_plugin_active($plugin_file)) {
                            $active_plugins[] = $plugin_file;
                        } else {
                            $result = delete_plugin($plugin_file);
                            if (!is_todo_error($result)) {
                                $deleted_count++;
                            }
                        }
                    }

                    if ($deleted_count > 0) {
                        if (function_exists('todo_add_admin_notice')) {
                            todo_add_admin_notice('已成功删除 ' . $deleted_count . ' 个插件。', 'success');
                        }
                    }

                    if (!empty($active_plugins)) {
                        if (function_exists('todo_add_admin_notice')) {
                            todo_add_admin_notice('无法删除激活的插件。请先停用插件。', 'error');
                        }
                    }
                } else {
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice('您没有权限删除插件。', 'error');
                    }
                }
                break;
        }
    }

    // 重定向到列表页，防止重复提交
    todo_redirect(todo_admin_url($redirect_url));
    exit;
}

// 获取所有插件
$plugins = get_plugins();

// 获取激活的插件
$active_plugins = get_active_plugins();

// 获取插件统计数据（从数据库查询，更准确）
$plugin_stats = array('total' => 0, 'active' => 0, 'inactive' => 0);
if (class_exists('Todo_Plugin_Manager') && method_exists('Todo_Plugin_Manager', 'get_plugin_stats')) {
    $plugin_stats = Todo_Plugin_Manager::get_plugin_stats();
}

// 搜索条件
$search = isset($_GET['s']) ? trim($_GET['s']) : '';

// 筛选插件列表
if (!empty($search)) {
    $filtered_plugins = array();

    foreach ($plugins as $plugin_file => $plugin_data) {
        // 获取插件目录路径
        $plugin_dir_path = TODO_PLUGIN_DIR . '/' . $plugin_file;

        // 查找插件主文件
        $plugin_main_file = null;
        if (is_dir($plugin_dir_path)) {
            $possible_files = [
                $plugin_dir_path . '/' . $plugin_file . '.php',
                $plugin_dir_path . '/index.php',
                $plugin_dir_path . '/plugin.php'
            ];

            foreach ($possible_files as $file) {
                if (file_exists($file)) {
                    $plugin_main_file = $file;
                    break;
                }
            }
        }

        // 如果是从数据库获取的数据，需要转换为标准格式
        if (isset($plugin_data['name']) && !isset($plugin_data['Name'])) {
            // 从插件文件头部获取详细信息
            $file_data = $plugin_main_file ? get_plugin_data($plugin_main_file) : array();

            // 合并数据，优先使用文件头部信息
            $plugin_info = array_merge($plugin_data, $file_data);

            // 如果文件头部没有信息，使用数据库信息作为备用
            if (empty($file_data['Name'])) {
                $plugin_info['Name'] = $plugin_data['name'] ?? basename($plugin_file, '.php');
            }
            if (empty($file_data['Description'])) {
                $plugin_info['Description'] = $plugin_data['description'] ?? '';
            }
            if (empty($file_data['Author'])) {
                $plugin_info['Author'] = $plugin_data['author'] ?? '';
            }
        } else {
            $plugin_info = $plugin_data;
        }

        // 确保必要的字段存在
        $plugin_info['Name'] = $plugin_info['Name'] ?? basename($plugin_file, '.php');
        $plugin_info['Description'] = $plugin_info['Description'] ?? '';
        $plugin_info['Author'] = $plugin_info['Author'] ?? '';

        if (
            stripos($plugin_info['Name'], $search) !== false ||
            stripos($plugin_info['Description'], $search) !== false ||
            stripos($plugin_info['Author'], $search) !== false
        ) {
            $filtered_plugins[$plugin_file] = $plugin_data;
        }
    }

    $plugins = $filtered_plugins;
}

// 按状态筛选
$status = isset($_GET['status']) ? $_GET['status'] : 'all';

if ($status !== 'all') {
    $filtered_plugins = array();
    foreach ($plugins as $plugin_file => $plugin_data) {
        $installed = is_plugin_installed($plugin_file);

        // 优先从插件数据中获取状态（数据库中的状态更准确）
        $plugin_status = isset($plugin_data['status']) ? $plugin_data['status'] : null;

        // 更宽松的状态检查：支持多种状态值格式
        $is_active_from_db = false;
        if ($plugin_status !== null) {
            // 转换为字符串进行比较，支持 'active', 1, '1', true 等
            $status_str = (string) $plugin_status;
            $is_active_from_db = (
                $status_str === 'active' ||
                $status_str === '1' ||
                $plugin_status === 1 ||
                $plugin_status === true ||
                (is_numeric($plugin_status) && (int) $plugin_status === 1)
            );
        }

        // 同时检查 is_plugin_active() 函数（用于兼容）
        $is_active_from_func = false;
        if ($installed && function_exists('is_plugin_active')) {
            $is_active_from_func = is_plugin_active($plugin_file);
        }

        // 如果数据库状态是 active，则认为插件已激活
        $is_active = $is_active_from_db || $is_active_from_func;

        // 根据状态筛选
        if (($status === 'active' && $is_active) || ($status === 'inactive' && $installed && !$is_active)) {
            $filtered_plugins[$plugin_file] = $plugin_data;
        }
    }
    $plugins = $filtered_plugins;
}

// 计算插件数量（使用数据库统计，只统计已安装的插件）
$all_count = isset($plugin_stats['total']) ? intval($plugin_stats['total']) : 0;
$active_count = isset($plugin_stats['active']) ? intval($plugin_stats['active']) : 0;
$inactive_count = isset($plugin_stats['inactive']) ? intval($plugin_stats['inactive']) : 0;

// 分页
$per_page = 20;
$paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$total_items = count($plugins);
$total_pages = $total_items > 0 ? (int) ceil($total_items / $per_page) : 0;
if ($total_pages > 1) {
    if ($paged > $total_pages) {
        $paged = $total_pages;
    }
    $offset = ($paged - 1) * $per_page;
    $plugins = array_slice($plugins, $offset, $per_page, true);
}
?>

<div class="admin-plugins-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title verse">“问渠那得清如许？为有源头活水来。”<span>——朱熹《观书有感》</span></h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=plugin-install'); ?>" class="button button-primary">添加新插件</a>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-section">
                <h3 class="admin-card-title">插件列表</h3>
            </div>

            <div class="admin-card-actions">
                <form method="get" class="search-form">
                    <input type="hidden" name="page" value="plugins">
                    <?php if ($status !== 'all'): ?>
                        <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
                    <?php endif; ?>
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="搜索已安装的插件...">
                    <button type="submit" class="button button-secondary">搜索</button>
                </form>
            </div>
        </div>

        <div class="admin-card-content">
            <div class="admin-filter-bar">
                <ul class="admin-filters">
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=plugins'); ?>" class="<?php echo $status === 'all' ? 'current' : ''; ?>">
                            所有 <span class="count">(<?php echo $all_count; ?>)</span>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=plugins&status=active'); ?>"
                            class="<?php echo $status === 'active' ? 'current' : ''; ?>">
                            已激活 <span class="count">(<?php echo $active_count; ?>)</span>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=plugins&status=inactive'); ?>"
                            class="<?php echo $status === 'inactive' ? 'current' : ''; ?>">
                            未激活 <span class="count">(<?php echo $inactive_count; ?>)</span>
                        </a>
                    </li>
                </ul>
            </div>

            <form method="post" class="admin-plugins-form">
                <div class="bulk-actions-bar">
                    <div class="bulk-actions">
                        <select name="action">
                            <option value="-1">批量操作</option>
                            <?php if (current_user_can('activate_plugins')): ?>
                                <option value="activate-selected">激活</option>
                                <option value="deactivate-selected">停用</option>
                            <?php endif; ?>
                            <?php if (current_user_can('delete_plugins')): ?>
                                <option value="delete-selected">删除</option>
                            <?php endif; ?>
                        </select>
                        <button type="submit" class="button button-secondary">应用</button>
                    </div>
                </div>

                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="check-column">
                                <input type="checkbox" id="select-all-plugins">
                            </th>
                            <th style="min-width: 220px;">插件</th>
                            <th style="min-width: 400px;">描述</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($plugins)): ?>
                            <tr class="empty">
                                <td colspan="3">
                                    <div class="no-items">
                                        <p>没有找到插件。</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($plugins as $plugin_file => $plugin_data): ?>
                                <?php
                                $is_installed = is_plugin_installed($plugin_file);

                                // 优先从插件数据中获取状态（数据库中的状态更准确）
                                $plugin_status = isset($plugin_data['status']) ? $plugin_data['status'] : null;

                                // 更宽松的状态检查：支持多种状态值格式
                                $is_active_from_db = false;
                                if ($plugin_status !== null) {
                                    // 转换为字符串进行比较，支持 'active', 1, '1', true 等
                                    $status_str = (string) $plugin_status;
                                    $is_active_from_db = (
                                        $status_str === 'active' ||
                                        $status_str === '1' ||
                                        $plugin_status === 1 ||
                                        $plugin_status === true ||
                                        (is_numeric($plugin_status) && (int) $plugin_status === 1)
                                    );
                                }

                                // 同时检查 is_plugin_active() 函数（用于兼容）
                                $is_active_from_func = false;
                                if ($is_installed && function_exists('is_plugin_active')) {
                                    $is_active_from_func = is_plugin_active($plugin_file);
                                }

                                // 如果数据库状态是 active，则认为插件已激活
                                $is_active = $is_active_from_db || $is_active_from_func;

                                // 调试信息（仅在调试模式下）
                                if (defined('TODO_DEBUG') && TODO_DEBUG) {
                                    error_log("Plugin {$plugin_file}: status={$plugin_status}, is_active_from_db=" . ($is_active_from_db ? 'true' : 'false') . ", is_active_from_func=" . ($is_active_from_func ? 'true' : 'false') . ", is_active=" . ($is_active ? 'true' : 'false'));
                                }

                                // 获取插件目录路径
                                $plugin_dir_path = TODO_PLUGIN_DIR . '/' . $plugin_file;

                                // 查找插件主文件
                                $plugin_main_file = null;
                                if (is_dir($plugin_dir_path)) {
                                    $possible_files = [
                                        $plugin_dir_path . '/' . $plugin_file . '.php',
                                        $plugin_dir_path . '/index.php',
                                        $plugin_dir_path . '/plugin.php'
                                    ];

                                    foreach ($possible_files as $file) {
                                        if (file_exists($file)) {
                                            $plugin_main_file = $file;
                                            break;
                                        }
                                    }
                                }

                                // 如果是从数据库获取的数据，需要转换为标准格式
                                if (isset($plugin_data['name']) && !isset($plugin_data['Name'])) {
                                    // 从插件文件头部获取详细信息
                                    $file_data = $plugin_main_file ? get_plugin_data($plugin_main_file) : array();

                                    // 合并数据，优先使用文件头部信息
                                    $plugin_info = array_merge($plugin_data, $file_data);

                                    // 如果文件头部没有信息，使用数据库信息作为备用
                                    if (empty($file_data['Name'])) {
                                        $plugin_info['Name'] = $plugin_data['name'] ?? basename($plugin_file, '.php');
                                    }
                                    if (empty($file_data['Description'])) {
                                        $plugin_info['Description'] = $plugin_data['description'] ?? '';
                                    }
                                    if (empty($file_data['Version'])) {
                                        $plugin_info['Version'] = $plugin_data['version'] ?? '1.0.0';
                                    }
                                    if (empty($file_data['Author'])) {
                                        $plugin_info['Author'] = $plugin_data['author'] ?? '';
                                    }
                                } else {
                                    $plugin_info = $plugin_data;
                                }

                                // 确保必要的字段存在
                                $plugin_info['Name'] = $plugin_info['Name'] ?? basename($plugin_file, '.php');
                                $plugin_info['Description'] = $plugin_info['Description'] ?? '';
                                $plugin_info['Version'] = $plugin_info['Version'] ?? '1.0.0';
                                $plugin_info['Author'] = $plugin_info['Author'] ?? '';
                                $plugin_info['PluginURI'] = $plugin_info['PluginURI'] ?? '';
                                ?>
                                <tr class="<?php echo $is_active ? 'active' : 'inactive'; ?>">
                                    <td>
                                        <input type="checkbox" name="checked[]" value="<?php echo $plugin_file; ?>">
                                    </td>
                                    <td class="plugin-title">
                                        <?php
                                        // 检查插件是否有设置页面或管理界面
                                        $has_settings = false;
                                        $settings_url = '';

                                        // 获取插件目录名（确保是目录名而不是文件路径）
                                        $plugin_dir = $plugin_file;

                                        // 如果 $plugin_file 包含路径分隔符，提取目录名
                                        if (strpos($plugin_file, '/') !== false) {
                                            $plugin_dir = basename($plugin_file);
                                        }

                                        // 如果插件已激活（从数据库或函数检查），则检查设置页面
                                        // 注意：即使 $is_active 判断有问题，如果数据库状态是 active，也应该显示链接
                                        if ($is_active || $is_active_from_db) {
                                            // 确保 TODO_PLUGIN_DIR 已定义
                                            if (!defined('TODO_PLUGIN_DIR')) {
                                                if (defined('TODO_CONTENT_DIR')) {
                                                    define('TODO_PLUGIN_DIR', TODO_CONTENT_DIR . '/plugins');
                                                } elseif (defined('ABSPATH')) {
                                                    define('TODO_PLUGIN_DIR', ABSPATH . 'content/plugins');
                                                } elseif (defined('TODO_ROOT')) {
                                                    define('TODO_PLUGIN_DIR', TODO_ROOT . '/content/plugins');
                                                }
                                            }

                                            // 构建设置文件路径（使用多种可能的路径格式）
                                            $possible_settings_paths = [
                                                TODO_PLUGIN_DIR . '/' . $plugin_dir . '/admin/settings.php',
                                                (defined('TODO_ROOT') ? TODO_ROOT : (defined('ABSPATH') ? rtrim(ABSPATH, '/') : '')) . '/content/plugins/' . $plugin_dir . '/admin/settings.php',
                                                (defined('ABSPATH') ? ABSPATH : '') . 'content/plugins/' . $plugin_dir . '/admin/settings.php'
                                            ];

                                            $settings_file = null;
                                            foreach ($possible_settings_paths as $path) {
                                                if (!empty($path) && file_exists($path)) {
                                                    $settings_file = $path;
                                                    break;
                                                }
                                            }

                                            // 检查是否有admin/settings.php文件
                                            if ($settings_file !== null) {
                                                $has_settings = true;
                                                $settings_url = todo_admin_url('index.php?page=plugin-settings&plugin=' . urlencode($plugin_dir));
                                            } else {
                                                // 如果没有设置页面，但插件已激活，则链接到插件管理页面
                                                // 插件管理页面总是可用的（只要插件已激活）
                                                $has_settings = true;
                                                $settings_url = todo_admin_url('index.php?page=plugin-manage&plugin=' . urlencode($plugin_dir));
                                            }
                                        }

                                        // 调试信息（仅在调试模式下）
                                        if (defined('TODO_DEBUG') && TODO_DEBUG && $is_active_from_db) {
                                            error_log("Plugin {$plugin_dir}: is_active={$is_active}, is_active_from_db={$is_active_from_db}, has_settings={$has_settings}, settings_url={$settings_url}");
                                        }
                                        ?>

                                        <?php if ($has_settings): ?>
                                            <strong><a href="<?php echo $settings_url; ?>"
                                                    title="<?php echo $has_settings && strpos($settings_url, 'plugin-settings') !== false ? '插件设置' : '插件管理'; ?>"><?php echo esc_html($plugin_info['Name']); ?></a></strong>
                                        <?php else: ?>
                                            <strong><?php echo esc_html($plugin_info['Name']); ?></strong>
                                        <?php endif; ?>
                                        <div class="row-actions">
                                            <?php if ($is_active): ?>
                                                <span class="deactivate">
                                                    <a
                                                        href="<?php echo todo_admin_url('index.php?page=plugins&action=deactivate&plugin=' . urlencode($plugin_file)); ?>">停用</a>
                                                </span>
                                                |
                                            <?php else: ?>
                                                <span class="activate">
                                                    <a
                                                        href="<?php echo todo_admin_url('index.php?page=plugins&action=activate&plugin=' . urlencode($plugin_file)); ?>">激活</a>
                                                </span>
                                                |
                                            <?php endif; ?>

                                            <?php if (!$is_active && current_user_can('delete_plugins')): ?>
                                                <span class="delete">
                                                    <a href="<?php echo todo_admin_url('index.php?page=plugins&action=delete&plugin=' . urlencode($plugin_file)); ?>"
                                                        class="submitdelete delete-plugin"
                                                        data-plugin-name="<?php echo esc_attr($plugin_info['Name']); ?>">删除</a>
                                                </span>
                                                |
                                            <?php endif; ?>
                                            <span class="export-ai">
                                                <a href="javascript:void(0);" class="btn-export-plugin-ai-conversion" data-plugin="<?php echo urlencode($plugin_file); ?>">导出AI转化配置</a>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="tb-description">
                                        <div class="tb-description">
                                            <?php echo esc_html($plugin_info['Description']); ?>
                                        </div>
                                        <div class="tb-meta">
                                            <span class="plugin-version">版本
                                                <?php echo esc_html($plugin_info['Version']); ?></span> |
                                            <span class="plugin-author">作者:
                                                <?php echo esc_html($plugin_info['Author']); ?></span>
                                            <?php if (!empty($plugin_info['PluginURI'])): ?>
                                                | <a href="<?php echo esc_url($plugin_info['PluginURI']); ?>"
                                                    target="_blank">插件主页</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1): ?>
                    <?php
                    $pagination_add_args = array();
                    if ($status !== 'all') {
                        $pagination_add_args['status'] = $status;
                    }
                    if (!empty($search)) {
                        $pagination_add_args['s'] = $search;
                    }

                    echo todo_render_pagination(array(
                        'total_items' => $total_items,
                        'per_page' => $per_page,
                        'current_page' => $paged,
                        'base_url' => todo_admin_url('index.php?page=plugins'),
                        'add_args' => $pagination_add_args,
                        'show_count' => true,
                    ));
                    ?>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<style>
    /* 插件管理页面特定样式 */
</style>

<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        // 全选/取消全选
        var selectAll = document.getElementById('select-all-plugins');
        var checkboxes = document.querySelectorAll('input[name="checked[]"]');

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checkboxes.forEach(function (checkbox) {
                    checkbox.checked = selectAll.checked;
                });
            });
        }

        // 删除插件确认
        var deleteLinks = document.querySelectorAll('.delete-plugin');

        deleteLinks.forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var pluginName = this.getAttribute('data-plugin-name');
                var href = this.getAttribute('href');

                TodoModal.confirm({
                    title: '确认删除插件',
                    message: '您确定要删除插件 "' + pluginName + '" 吗？',
                    onConfirm: function () {
                        if (href) {
                            window.location.href = href;
                        }
                    }
                });
            });
        });

        // 批量操作确认
        var pluginsForm = document.querySelector('.admin-plugins-form');

        if (pluginsForm) {
            pluginsForm.addEventListener('submit', function (e) {
                var action = document.querySelector('select[name="action"]').value;
                var checkedBoxes = document.querySelectorAll('input[name="checked[]"]:checked');

                if (action === '-1') {
                    e.preventDefault();
                    TodoCMS.Utils.showMessage('请选择一个批量操作。', 'warning');
                    return;
                }

                if (checkedBoxes.length === 0) {
                    e.preventDefault();
                    TodoCMS.Utils.showMessage('请选择至少一个插件。', 'warning');
                    return;
                }

                if (action === 'delete-selected') {
                    e.preventDefault();
                    TodoModal.deleteConfirm({
                        title: '确认删除插件',
                        message: '您确定要删除选定的插件吗？',
                        onConfirm: function () {
                            pluginsForm.submit();
                        }
                    });
                }
            });
        }

        // AI 转化配置导出
        var exportBtns = document.querySelectorAll('.btn-export-plugin-ai-conversion');
        exportBtns.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var pluginSlug = this.getAttribute('data-plugin');
                var fd = new FormData();
                fd.append('action', 'export_plugin_ai_conversion');
                fd.append('plugin', pluginSlug);
                fd.append('_todononce', '<?php echo Todo_Security_Manager::generate_csrf_token('common_ajax_nonce'); ?>');
                var ajaxUrl = '<?php echo todo_admin_url('ajax/post-ajax.php'); ?>';
                
                if (window.TodoModal && typeof TodoModal.showLoading === 'function') {
                    TodoModal.showLoading('正在生成 AI 转化配置文件...');
                }

                fetch(ajaxUrl, {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(r) { return r.json(); })
                .then(function(json) {
                    if (window.TodoModal && typeof TodoModal.hideLoading === 'function') {
                        TodoModal.hideLoading();
                    }
                    if (json.success && json.data) {
                        var blob = new Blob([JSON.stringify(json.data, null, 2)], { type: 'application/json' });
                        var link = document.createElement('a');
                        link.href = URL.createObjectURL(blob);
                        link.download = json.filename || ('plugin-conversion-' + pluginSlug + '.json');
                        link.click();
                    } else {
                        TodoModal.error('导出失败: ' + (json.message || '未知错误'));
                    }
                })
                .catch(function(err) {
                    if (window.TodoModal && typeof TodoModal.hideLoading === 'function') {
                        TodoModal.hideLoading();
                    }
                    console.error(err);
                    TodoModal.error('导出出错');
                });
            });
        });
    });
</script>
