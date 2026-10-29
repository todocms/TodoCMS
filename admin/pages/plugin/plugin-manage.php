<?php
/**
 * 插件管理页面
 * 
 * @package TodoCMS
 * @subpackage Admin
 */

// 防止直接访问
if (!defined('TODO_ADMIN')) {
    exit('Direct access denied');
}

// 检查用户权限
if (!current_user_can('manage_plugins')) {
    todo_die('您没有权限访问此页面。');
}

// 获取插件信息
$plugin = isset($_GET['plugin']) ? sanitize_text_field($_GET['plugin']) : '';
if (empty($plugin)) {
    todo_die('未指定插件。');
}

// 验证插件是否存在（使用与插件管理器相同的方式）
$plugin_path = null;
$plugins_dir = 'content/plugins'; // 与 Todo_Plugin_Manager 保持一致

// 优先使用 TODO_ROOT
if (defined('TODO_ROOT')) {
    $plugin_path = TODO_ROOT . '/' . $plugins_dir . '/' . $plugin;
} elseif (defined('ABSPATH')) {
    // 降级：使用 ABSPATH
    $plugin_path = ABSPATH . $plugins_dir . '/' . $plugin;
} elseif (defined('PLUGIN_DIR')) {
    // 降级：使用 PLUGIN_DIR（可能是绝对路径或相对路径）
    $plugin_path = PLUGIN_DIR . '/' . $plugin;
    // 如果是相对路径，尝试从当前文件位置计算
    if (!is_dir($plugin_path) && strpos($plugin_path, '/') !== 0) {
        $plugin_path = dirname(dirname(dirname(__DIR__))) . '/' . $plugin_path;
    }
} else {
    // 最后降级：从当前文件位置计算
    $plugin_path = dirname(dirname(dirname(__DIR__))) . '/' . $plugins_dir . '/' . $plugin;
}

// 检查插件目录是否存在
if (!is_dir($plugin_path)) {
    // 尝试使用 get_plugin_info 检查插件是否在数据库中注册
    if (function_exists('get_plugin_info')) {
        $plugin_data = get_plugin_info($plugin);
        if ($plugin_data) {
            // 插件在数据库中，但目录不存在，尝试重新构建路径
            if (defined('TODO_ROOT')) {
                $plugin_path = TODO_ROOT . '/' . $plugins_dir . '/' . $plugin;
            } elseif (defined('ABSPATH')) {
                $plugin_path = ABSPATH . $plugins_dir . '/' . $plugin;
            } else {
                $plugin_path = dirname(dirname(dirname(__DIR__))) . '/' . $plugins_dir . '/' . $plugin;
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

$plugin_dir = $plugin_path;

// 获取插件信息
$plugin_info = array();

// 尝试从插件管理器获取插件信息
if (function_exists('get_plugin_info')) {
    $plugin_data = get_plugin_info($plugin);
    if ($plugin_data && is_array($plugin_data)) {
        // 转换格式
        $plugin_info['name'] = $plugin_data['name'] ?? $plugin;
        $plugin_info['version'] = $plugin_data['version'] ?? '未知';
        $plugin_info['description'] = $plugin_data['description'] ?? '暂无描述';
        $plugin_info['author'] = $plugin_data['author'] ?? '未知';
        $plugin_info['plugin_uri'] = $plugin_data['plugin_uri'] ?? '';
        $plugin_info['author_uri'] = $plugin_data['author_uri'] ?? '';
    }
}

// 如果还没有获取到信息，尝试从文件读取
if (empty($plugin_info['name']) || $plugin_info['name'] === $plugin) {
    // 按优先级查找主文件
    $possible_files = [
        $plugin_dir . '/' . $plugin . '.php',  // 目录名.php
        $plugin_dir . '/index.php',            // index.php
        $plugin_dir . '/plugin.php'            // plugin.php
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
            $plugin_info = array_merge($plugin_info, array(
                'name' => $file_data['Name'] ?? $plugin_info['name'] ?? $plugin,
                'version' => $file_data['Version'] ?? $plugin_info['version'] ?? '未知',
                'description' => $file_data['Description'] ?? $plugin_info['description'] ?? '暂无描述',
                'author' => $file_data['Author'] ?? $plugin_info['author'] ?? '未知',
                'plugin_uri' => $file_data['PluginURI'] ?? $plugin_info['plugin_uri'] ?? '',
                'author_uri' => $file_data['AuthorURI'] ?? $plugin_info['author_uri'] ?? ''
            ));
        }
    }
}

// 设置默认值
$plugin_info = array_merge(array(
    'name' => $plugin,
    'version' => '未知',
    'description' => '暂无描述',
    'author' => '未知',
    'plugin_uri' => '',
    'author_uri' => ''
), $plugin_info);

// 检查插件是否激活
$active_plugins = get_active_plugins();
$is_active = is_plugin_installed($plugin) && is_plugin_active($plugin);

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('plugin_manage_' . $plugin, '_todononce')) {
        todo_die('安全验证失败。');
    }
    
    $action = $_POST['action'];
    $redirect_url = 'index.php?page=plugin-manage&plugin=' . urlencode($plugin);
    
    switch ($action) {
        case 'activate':
            if (!$is_active) {
                $result = activate_plugin($plugin);
                if (is_todo_error($result)) {
                    $msg = '';
                    if (is_object($result) && method_exists($result, 'get_error_message')) {
                        $msg = $result->get_error_message();
                    } elseif (is_array($result) && isset($result['error'])) {
                        $msg = (string)$result['error'];
                    } else {
                        $msg = '插件激活失败';
                    }
                    todo_admin_notice($msg, 'error');
                } else {
                    todo_admin_notice('插件已成功激活。', 'success');
                    $redirect_url .= '&activated=1';
                }
            }
            break;
            
        case 'deactivate':
            if ($is_active) {
                deactivate_plugin($plugin);
                todo_admin_notice('插件已停用。', 'success');
                $redirect_url .= '&deactivated=1';
            }
            break;
    }
    
    todo_redirect($redirect_url);
    exit;
}

// 显示通知消息
if (isset($_GET['activated'])) {
    todo_admin_notice('插件已成功激活。', 'success');
} elseif (isset($_GET['deactivated'])) {
    todo_admin_notice('插件已停用。', 'success');
}
?>

<div class="wrap">
    <h1>插件管理: <?php echo esc_html($plugin_info['name']); ?></h1>
    
    <div class="plugin-info-card">
        <div class="plugin-info-header">
            <h2><?php echo esc_html($plugin_info['name']); ?></h2>
            <p class="plugin-version">版本: <?php echo esc_html($plugin_info['version']); ?></p>
        </div>
        
        <div class="plugin-info-body">
            <p class="plugin-description"><?php echo esc_html($plugin_info['description']); ?></p>
            
            <div class="plugin-meta">
                <p><strong>作者:</strong> 
                    <?php if (!empty($plugin_info['author_uri'])): ?>
                        <a href="<?php echo esc_url($plugin_info['author_uri']); ?>" target="_blank"><?php echo esc_html($plugin_info['author']); ?></a>
                    <?php else: ?>
                        <?php echo esc_html($plugin_info['author']); ?>
                    <?php endif; ?>
                </p>
                
                <?php if (!empty($plugin_info['plugin_uri'])): ?>
                <p><strong>插件主页:</strong> 
                    <a href="<?php echo esc_url($plugin_info['plugin_uri']); ?>" target="_blank"><?php echo esc_url($plugin_info['plugin_uri']); ?></a>
                </p>
                <?php endif; ?>
                
                <p><strong>状态:</strong> 
                    <span class="plugin-status <?php echo $is_active ? 'active' : 'inactive'; ?>">
                        <?php echo $is_active ? '已激活' : '未激活'; ?>
                    </span>
                </p>
            </div>
        </div>
        
        <div class="plugin-info-actions">
            <form method="post" style="display: inline;">
                <?php echo Todo_Security_Manager::csrf_field('plugin_manage_' . $plugin); ?>
                
                <?php if ($is_active): ?>
                    <input type="hidden" name="action" value="deactivate">
                    <button type="submit" class="button button-secondary" data-action="deactivate">停用插件</button>
                <?php else: ?>
                    <input type="hidden" name="action" value="activate">
                    <button type="submit" class="button button-primary">激活插件</button>
                <?php endif; ?>
            </form>
            
            <a href="<?php echo todo_admin_url('index.php?page=plugins'); ?>" class="button button-secondary">返回插件列表</a>
            
            <?php
            // 检查是否有设置页面
            $settings_file = $plugin_dir . '/admin/settings.php';
            $has_json_settings = isset($plugin_info['settings']) && !empty($plugin_info['settings']);
            
            if (file_exists($settings_file) || $has_json_settings): ?>
                <a href="<?php echo todo_admin_url('index.php?page=plugin-settings&plugin=' . urlencode($plugin)); ?>" class="button button-secondary">插件设置</a>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (isset($plugin_info['features']) && is_array($plugin_info['features'])): ?>
    <div class="plugin-features">
        <h3>插件特性</h3>
        <ul>
            <?php foreach ($plugin_info['features'] as $feature): ?>
                <li><?php echo esc_html($feature); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    
    <?php if (isset($plugin_info['screenshots']) && is_array($plugin_info['screenshots'])): ?>
    <div class="plugin-screenshots">
        <h3>截图</h3>
        <div class="screenshot-gallery">
            <?php foreach ($plugin_info['screenshots'] as $screenshot): ?>
                <div class="screenshot-item">
                    <img src="<?php echo esc_url(PLUGIN_DIR . '/' . $plugin . '/' . $screenshot['src']); ?>" alt="<?php echo esc_attr($screenshot['caption']); ?>">
                    <p><?php echo esc_html($screenshot['caption']); ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.plugin-info-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    margin: 20px 0;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}

.plugin-info-header {
    border-bottom: 1px solid #eee;
    padding-bottom: 15px;
    margin-bottom: 15px;
}

.plugin-info-header h2 {
    margin: 0 0 5px 0;
    font-size: 1.4em;
}

.plugin-version {
    color: #666;
    margin: 0;
    font-size: 0.9em;
}

.plugin-description {
    font-size: 1.1em;
    line-height: 1.5;
    margin-bottom: 15px;
}

.plugin-meta p {
    margin: 5px 0;
}

.plugin-status {
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 0.9em;
    font-weight: 500;
}

.plugin-status.active {
    background: #d4edda;
    color: #155724;
}

.plugin-status.inactive {
    background: #f8d7da;
    color: #721c24;
}

.plugin-info-actions {
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid #eee;
}

.plugin-info-actions .button {
    margin-right: 10px;
}

.plugin-features {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    margin: 20px 0;
}

.plugin-features ul {
    list-style: disc;
    margin-left: 20px;
}

.plugin-features li {
    margin: 5px 0;
}

.plugin-screenshots {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    margin: 20px 0;
}

.screenshot-gallery {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
}

.screenshot-item {
    flex: 1;
    min-width: 300px;
    text-align: center;
}

.screenshot-item img {
    max-width: 100%;
    height: auto;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.screenshot-item p {
    margin: 10px 0 0 0;
    font-style: italic;
    color: #666;
}
</style>

<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function() {
    var deactivateButton = document.querySelector('button[data-action="deactivate"]');
    if (deactivateButton) {
        deactivateButton.addEventListener('click', function(e) {
            e.preventDefault();
            var form = this.closest('form');
            TodoModal.confirm({
                title: '确认停用插件',
                message: '确定要停用此插件吗？',
                type: 'warning',
                onConfirm: function() {
                    form.submit();
                }
            });
        });
    }
});
</script>