<?php
/**
 * 标对导入插件 - 设置页面
 * 
 * @package DataMappingImport
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('TODO_VERSION')) {
    exit('Direct access denied');
}

// 检查用户权限
if (!current_user_can('manage_options')) {
    todo_die('权限不足');
}

// 强制初始化安全管理器和会话
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 生成CSRF令牌 - 使用Todo_Security_Manager统一管理
if (class_exists('Todo_Security_Manager')) {
    // 强制初始化安全管理器
    Todo_Security_Manager::init();
    
    $get_database_structure_nonce = Todo_Security_Manager::generate_csrf_token('get_database_structure');
    $parse_sql_file_nonce = Todo_Security_Manager::generate_csrf_token('parse_sql_file');
    $get_import_logs_nonce = Todo_Security_Manager::generate_csrf_token('get_import_logs');
    $load_mapping_template_nonce = Todo_Security_Manager::generate_csrf_token('load_mapping_template');
    $save_mapping_template_nonce = Todo_Security_Manager::generate_csrf_token('save_mapping_template');
    $delete_mapping_template_nonce = Todo_Security_Manager::generate_csrf_token('delete_mapping_template');
    $execute_import_nonce = Todo_Security_Manager::generate_csrf_token('execute_import');
    $get_import_progress_nonce = Todo_Security_Manager::generate_csrf_token('get_import_progress');
    $clear_import_logs_nonce = Todo_Security_Manager::generate_csrf_token('clear_import_logs');
} else {
    // 回退到旧方法
    $get_database_structure_nonce = todo_create_nonce('get_database_structure');
    $parse_sql_file_nonce = todo_create_nonce('parse_sql_file');
    $get_import_logs_nonce = todo_create_nonce('get_import_logs');
    $load_mapping_template_nonce = todo_create_nonce('load_mapping_template');
    $save_mapping_template_nonce = todo_create_nonce('save_mapping_template');
    $delete_mapping_template_nonce = todo_create_nonce('delete_mapping_template');
    $execute_import_nonce = todo_create_nonce('execute_import');
    $get_import_progress_nonce = todo_create_nonce('get_import_progress');
    $clear_import_logs_nonce = todo_create_nonce('clear_import_logs');
}

// 获取PHP上传限制
function convert_to_bytes($size) {
    $size = trim($size);
    $last = strtolower($size[strlen($size) - 1]);
    $size = (int) $size;
    switch ($last) {
        case 'g': $size *= 1024;
        case 'm': $size *= 1024;
        case 'k': $size *= 1024;
    }
    return $size;
}
$upload_max_filesize = ini_get('upload_max_filesize');
$post_max_size = ini_get('post_max_size');
$upload_max_bytes = convert_to_bytes($upload_max_filesize);
$post_max_bytes = convert_to_bytes($post_max_size);
$max_upload_bytes = min($upload_max_bytes, $post_max_bytes);

// 调试输出（强制启用以排查问题）
error_log('数据映射导入插件 - Nonce生成调试:');
error_log('get_database_structure_nonce: ' . ($get_database_structure_nonce ? $get_database_structure_nonce : 'NULL/EMPTY'));
error_log('Session状态: ' . (session_status() === PHP_SESSION_ACTIVE ? '活跃' : '未启动'));
error_log('Todo_Security_Manager存在: ' . (class_exists('Todo_Security_Manager') ? '是' : '否'));
error_log('当前用户ID: ' . get_current_user_id());
error_log('用户权限: ' . (current_user_can('manage_options') ? '有权限' : '无权限'));

// 页面调试信息显示
echo '<!-- 数据映射导入插件调试信息 -->';
echo '<!-- get_database_structure_nonce: ' . ($get_database_structure_nonce ? $get_database_structure_nonce : 'NULL/EMPTY') . ' -->';
echo '<!-- Session状态: ' . (session_status() === PHP_SESSION_ACTIVE ? '活跃' : '未启动') . ' -->';
echo '<!-- Todo_Security_Manager存在: ' . (class_exists('Todo_Security_Manager') ? '是' : '否') . ' -->';

// 加载插件资源文件
// 获取插件目录名（优先使用从父页面传入的变量）
$plugin_dir_name = isset($current_plugin_dir) ? $current_plugin_dir : (isset($plugin_dir) ? $plugin_dir : 'data-mapping-import');

// 如果 $plugin_url 未定义，则使用 plugin_dir_url 函数获取
if (!isset($plugin_url) || empty($plugin_url)) {
    if (function_exists('plugin_dir_url')) {
        $plugin_url = plugin_dir_url($plugin_dir_name);
    } elseif (function_exists('todo_plugin_url')) {
        $plugin_url = todo_plugin_url($plugin_dir_name);
    } elseif (function_exists('todo_get_plugin_url')) {
        $plugin_url = todo_get_plugin_url($plugin_dir_name);
    } elseif (function_exists('todo_get_site_url')) {
        $plugin_url = todo_get_site_url('content/plugins/' . $plugin_dir_name . '/');
    } elseif (function_exists('site_url')) {
        $plugin_url = site_url('content/plugins/' . $plugin_dir_name . '/');
    } else {
        // 降级处理：从当前URL计算
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $script_name = isset($_SERVER['SCRIPT_NAME']) ? dirname(dirname($_SERVER['SCRIPT_NAME'])) : '';
        $plugin_url = $protocol . $host . $script_name . '/content/plugins/' . $plugin_dir_name . '/';
    }
}
// 确保 $plugin_url 以斜杠结尾
$plugin_url = rtrim($plugin_url, '/') . '/';
?>

<!-- 加载插件样式和脚本 -->
<link rel="stylesheet" href="<?php echo $plugin_url; ?>/assets/css/data-mapping-import.css?v=1.0.0">

<!-- 先注入全局配置，确保在外部JS加载前可用 -->
<script type="text/javascript">
window.dataMappingConfig = {
    ajaxurl: '<?php echo todo_admin_url('admin-ajax.php'); ?>',
    tablePrefix: '<?php echo esc_js(isset($GLOBALS['tododb']->prefix) ? $GLOBALS['tododb']->prefix : 'todo_'); ?>',
    nonces: {
        get_database_structure: '<?php echo $get_database_structure_nonce; ?>',
        parse_sql_file: '<?php echo $parse_sql_file_nonce; ?>',
        get_import_logs: '<?php echo $get_import_logs_nonce; ?>',
        load_mapping_template: '<?php echo $load_mapping_template_nonce; ?>',
        save_mapping_template: '<?php echo $save_mapping_template_nonce; ?>',
        delete_mapping_template: '<?php echo $delete_mapping_template_nonce; ?>',
        execute_import: '<?php echo $execute_import_nonce; ?>',
        get_import_progress: '<?php echo $get_import_progress_nonce; ?>',
        clear_import_logs: '<?php echo $clear_import_logs_nonce; ?>',
        sync_db_schema: '<?php echo todo_create_nonce('sync_db_schema'); ?>'
    },
    messages: {
        upload_success: '文件上传成功',
        parse_error: 'SQL文件解析失败',
        mapping_saved: '映射模板保存成功',
        import_success: '数据导入成功',
        import_error: '数据导入失败',
        permission_denied: '权限不足',
        csrf_failed: 'CSRF验证失败'
    },
    uploadLimits: {
        maxSize: <?php echo $max_upload_bytes; ?>,
        maxSizeFormatted: '<?php echo $upload_max_filesize; ?>',
        postMaxSize: <?php echo $post_max_bytes; ?>,
        postMaxSizeFormatted: '<?php echo $post_max_size; ?>'
    }
};
</script>

<!-- 加载插件脚本 -->
<script src="<?php echo $plugin_url; ?>assets/js/data-mapping-import.js?v=1.0.0" type="text/javascript"></script>

<div class="admin-card">
    <div class="admin-card-header no-border">
        <div class="admin-card-actions">
            <button type="button" class="button button-primary shrink-0" id="upload-sql-btn">
                <i class="icon todofont todo-Upload"></i> 上传SQL文件
            </button>
            <input type="file" id="sql-file-input" accept=".sql" style="display: none;">
            <span class="show-notice" id="show-notice"></span>
        </div>
        
        <div class="admin-card-actions shrink-0">
            <button type="button" class="button button-secondary" id="load-template-btn">
                历史映射
            </button>
            <button type="button" class="button button-secondary" id="save-template-btn" disabled>
                保存映射
            </button>
            <button type="button" class="button button-secondary" id="clear-mapping-btn">
                <i class="icon todofont todo-Clear"></i> 清空映射
            </button>
        </div>
    </div>

    <div class="admin-card-content">
        <!-- 主要内容区域 -->
        <div class="mapping-content">
            <!-- 左侧：源数据库结构 -->
            <div class="mapping-panel source-panel">
                <div class="panel-header">
                    <h3>源数据结构</h3>
                </div>
                
                <div class="panel-content">
                    <div id="source-structure-tree" class="structure-tree">
                        <div class="empty-state">
                            <span class="toicon" style="font-size: 30px;width: 46px;height: 46px;margin-bottom: 10px;color: #efefef;border: 1px solid #efefef;border-radius: 100%;">↑</span>
                            <p>请上传SQL文件以查看数据库结构</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 中间：映射关系显示 -->
            <div class="mapping-relations">
                <div class="relations-header">
                    <h4>映射关系</h4>
                    <span class="mapping-count">0 个</span>
                </div>
                
                <div class="relations-content" id="mapping-relations-list">
                    <div class="empty-relations">
                        <p>从左边拖拽字段建立映射关系</p>
                    </div>
                </div>
            </div>
            
            <!-- 右侧：目标数据库结构 -->
            <div class="mapping-panel target-panel">
                <div class="panel-header" style="display: flex; justify-content: space-between; align-items: center;">
                    <h3>目标数据结构</h3>
                    <button type="button" id="btn-sync-db-schema" class="btn button-secondary button-small" title="全量对齐并补全缺少的数据库表列与索引，不会覆盖旧数据。">
                        初始化
                    </button>
                </div>
                
                <div class="panel-content">
                    <div id="target-structure-tree" class="structure-tree">
                        <div class="loading-state">
                            <span class="toicon spin">✤</span>
                            <p>正在加载数据库结构...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 底部：导入配置和日志 -->
        <div class="mapping-footer">
            <div class="footer-tabs">
                <button class="tab-button active" data-tab="config">导入配置</button>
                <button class="tab-button" data-tab="logs">导入日志</button>
            </div>
            
            <div class="tab-content">
                <!-- 导入配置 -->
                <div id="config-tab" class="tab-panel active">
                    <div class="config-options">
                        <div class="config-group">
                            <label>
                                <input type="checkbox" id="truncate-before-import" checked>
                                导入前清空目标表数据
                            </label>
                        </div>
                        
                        <div class="config-group">
                            <label>
                                <input type="checkbox" id="ignore-duplicates">
                                忽略重复数据
                            </label>
                            <p class="description">（如果唯一键值(如ID)已存在，则忽略该条记录，继续处理下一条）</p>
                        </div>
                        
                        <div class="config-group">
                            <label for="batch-size">批量处理大小：</label>
                            <input type="number" id="batch-size" value="1000" min="100" max="10000">
                            <span class="description">每批处理的记录数，建议1000-5000</span>
                        </div>
                        <span class="import-tips">如果更新数据，请附带唯一键值（如ID），否则会直接插入数据</span>
                    
<pre class="pre">
映射关系只能单表映射。

映射关系替换值正则表达式
格式：/pattern#replacement/ 或 /pattern#replacement/flags
使用 # 作为分隔符，避免 replacement 中的 / 干扰，替换字符不需要反斜杠；|/开头则直接处理目标数据。
示例1：/\/uploads[^"'\s>]+#/content$0/ 会将 /uploads 替换为 /content/uploads
示例2：/https:\/\/www\.example\.com#/content/ 会将 https://www.example.com 替换为 /content
</pre>
                        
                    </div>
                </div>
                
                <!-- 导入日志 -->
                <div id="logs-tab" class="tab-panel">
                    <button type="button" class="btn button-secondary" id="clear-logs-btn" style="display: none;">
                        清空日志
                    </button>
                    <div id="import-logs-content">
                        <p>暂无导入日志</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="admin-form-submit">
    <button type="button" class="button button-primary" id="execute-import-btn" disabled>
        <i class="icon todofont todo-DataUser"></i> 开始写入目标数据
    </button>
</div>

<!-- 字段详情对话框 -->
<div id="field-details-dialog" title="字段详情" style="display: none;">
    <!-- 内容将通过JavaScript动态填充 -->
</div>

<!-- 字段信息面板 -->
<div id="field-info-panel" style="display: none; position: fixed; top: 20px; right: 20px; width: 300px; z-index: 9999;">
    <!-- 内容将通过JavaScript动态填充 -->
</div>

<!-- 映射确认对话框 -->
<div id="mapping-confirm-dialog" title="确认字段映射" style="display: none;">
    <!-- 内容将通过JavaScript动态填充 -->
</div>

<!-- 样式定义 -->
<style>
</style>

<script type="text/javascript">
// 配置全局变量
window.dataMappingConfig = {
    ajaxurl: '<?php echo todo_admin_url('admin-ajax.php'); ?>',
    tablePrefix: '<?php echo esc_js(isset($GLOBALS['tododb']->prefix) ? $GLOBALS['tododb']->prefix : 'todo_'); ?>',
    nonces: {
        get_database_structure: '<?php echo $get_database_structure_nonce; ?>',
        parse_sql_file: '<?php echo $parse_sql_file_nonce; ?>',
        get_import_logs: '<?php echo $get_import_logs_nonce; ?>',
        load_mapping_template: '<?php echo $load_mapping_template_nonce; ?>',
        save_mapping_template: '<?php echo $save_mapping_template_nonce; ?>',
        delete_mapping_template: '<?php echo $delete_mapping_template_nonce; ?>',
        execute_import: '<?php echo $execute_import_nonce; ?>',
        get_import_progress: '<?php echo $get_import_progress_nonce; ?>',
        clear_import_logs: '<?php echo $clear_import_logs_nonce; ?>',
        sync_db_schema: '<?php echo todo_create_nonce('sync_db_schema'); ?>'
    },
    messages: {
        upload_success: '文件上传成功',
        parse_error: 'SQL文件解析失败',
        mapping_saved: '映射模板保存成功',
        import_success: '数据导入成功',
        import_error: '数据导入失败',
        permission_denied: '权限不足',
        csrf_failed: 'CSRF验证失败'
    },
    uploadLimits: {
        maxSize: <?php echo $max_upload_bytes; ?>,
        maxSizeFormatted: '<?php echo $upload_max_filesize; ?>',
        postMaxSize: <?php echo $post_max_bytes; ?>,
        postMaxSizeFormatted: '<?php echo $post_max_size; ?>'
    }
};

// 页面加载完成后初始化
jQuery(document).ready(function($) {
    // 标签页切换
    $('.tab-button').on('click', function() {
        var tab = $(this).data('tab');
        
        $('.tab-button').removeClass('active');
        $(this).addClass('active');
        
        $('.tab-panel').removeClass('active');
        $('#' + tab + '-tab').addClass('active');
    });
});
</script>
<script src="<?php echo todo_admin_url('assets/js/csrf-manager.js'); ?>" type="text/javascript"></script>