<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

/**
 * TodoCMS 系统升级页面
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 检查用户权限
if (!current_user_can('update_core') && !current_user_can('manage_options')) {
    todo_die('您没有足够的权限访问此页面。');
}

// 共享页面全局更新授权状态
$is_update_auth = function_exists('todo_has_update_authorization') ? (bool) todo_has_update_authorization() : false;

// 处理升级操作
$upgrade_message = '';
$upgrade_error = '';
$upgrade_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('system_upgrade', '_todononce')) {
        $upgrade_error = '安全验证失败，请重试。';
    } else {
        $action = isset($_POST['action']) ? $_POST['action'] : '';
        $auth_required_actions = array('check_updates', 'download_update', 'install_update', 'auto_check_updates');
        if (in_array($action, $auth_required_actions, true) && !$is_update_auth) {
            $upgrade_error = '系统未授权或授权状态无效，禁止执行更新相关操作。';
            $action = '';
        }

        switch ($action) {
            case 'auto_check_updates':
                $result = Todo_Update_Manager::check_updates();
                $resp = ['success' => true, 'data' => ['has_update' => false]];
                if (!empty($result['error'])) {
                    $resp = ['success' => false, 'error' => (string) $result['error']];
                } else {
                    $cacheDir = rtrim((string) ABSPATH, '/') . '/content/updates';
                    if (!is_dir($cacheDir)) {
                        @mkdir($cacheDir, 0775, true);
                    }
                    @file_put_contents($cacheDir . '/last_check.json', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    if (!empty($result['has_update'])) {
                        $info = $result['update_info'] ?? [];
                        $version = (string) ($info['version'] ?? ($result['latest_version'] ?? ''));
                        $resp['data'] = ['has_update' => true, 'version' => $version];
                    }
                }
                while (ob_get_level()) {
                    ob_end_clean();
                }
                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode($resp, JSON_UNESCAPED_UNICODE);
                exit;

            case 'check_updates':
                // 远程检查更新
                $result = Todo_Update_Manager::check_updates();
                if (!empty($result['error'])) {
                    $err = (string) $result['error'];
                    $upgrade_error = '检查更新失败：' . esc_html($err);
                } else {
                    // 将检查结果写入缓存，供页面展示或后续操作
                    $cacheDir = rtrim((string) ABSPATH, '/') . '/content/updates';
                    if (!is_dir($cacheDir)) {
                        @mkdir($cacheDir, 0775, true);
                    }
                    @file_put_contents($cacheDir . '/last_check.json', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $upgrade_message = '检查更新完成';
                }
                break;
            case 'verify_license_key':
                $license_key = isset($_POST['license_key']) ? trim((string) $_POST['license_key']) : '';
                if ($license_key === '') {
                    $upgrade_error = '授权码不能为空';
                    break;
                }
                $domain = '';
                if (function_exists('todo_get_option')) {
                    $domain = (string) todo_get_option('todo_update_license_domain');
                }
                if ($domain === '') {
                    $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
                }
                $domain = preg_replace('#^https?://#i', '', (string) $domain);
                $domain = preg_replace('/:\d+$/', '', (string) $domain);
                $domain = preg_replace('/^www\./i', '', (string) $domain);
                $domain = strtolower((string) $domain);
                $kernel_path = ABSPATH . 'core/classes/class-core-kernel.php';
                if (is_file($kernel_path) && !class_exists('Todo_Core_Kernel')) {
                    require_once $kernel_path;
                }
                $server_id = class_exists('Todo_Core_Kernel') ? Todo_Core_Kernel::get_client_fingerprint() : '';
                if ($server_id === '') {
                    $upgrade_error = '服务器标识获取失败';
                    break;
                }
                $cfg = function_exists('todo_get_update_config') ? todo_get_update_config() : array();
                $apiUrl = '';
                if (function_exists('todo_get_option')) {
                    $apiUrl = trim((string) todo_get_option('todo_update_server_url'));
                }
                if ($apiUrl === '' && is_array($cfg) && isset($cfg['api'])) {
                    $apiUrl = trim((string) $cfg['api']);
                }
                if ($apiUrl === '' && defined('TODO_GEINMO_API_URL')) {
                    $apiUrl = trim((string) constant('TODO_GEINMO_API_URL'));
                }
                if ($apiUrl !== '') {
                    $apiUrl = trim((string) $apiUrl);
                    $apiUrl = trim($apiUrl, " \t\n\r\0\x0B`'\"");
                    $apiUrl = rtrim($apiUrl, " \t\n\r\0\x0B`'\":)");
                    $apiUrl = preg_replace('/\s+/', '', $apiUrl);
                    if ($apiUrl && !preg_match('#^https?://#i', $apiUrl)) {
                        $apiUrl = 'http://' . $apiUrl;
                    }
                }
                if ($apiUrl === '') {
                    $upgrade_error = '未配置授权服务器地址，请先保存“更新服务器 API 地址”。';
                    break;
                }
                if (!filter_var($apiUrl, FILTER_VALIDATE_URL)) {
                    $upgrade_error = '授权服务器地址格式不正确';
                    break;
                }

                $platformSecret = '';
                if (class_exists('Todo_Core_Kernel') && is_callable(['Todo_Core_Kernel', 'get_geinmo_platform_secret'])) {
                    $platformSecret = (string) Todo_Core_Kernel::get_geinmo_platform_secret($license_key, $domain);
                }
                if ($platformSecret === '') {
                    $upgrade_error = '核心授权模块缺少签名方法';
                    break;
                }
                if (function_exists('todo_update_option')) {
                    todo_update_option('todo_license_key', strtolower($license_key));
                }
                if (function_exists('todo_set_option')) {
                    todo_set_option('todo_license_key', strtolower($license_key));
                }
                $server_base = preg_replace('#/api\\.php$#i', '', (string) $apiUrl);
                $server_base = rtrim((string) $server_base, '/');
                $verify2 = class_exists('Todo_Core_Kernel') ? Todo_Core_Kernel::verify_license($server_base) : false;
                if (!$verify2 || !is_array($verify2) || empty($verify2['valid'])) {
                    $err = '授权验证失败';
                    if (is_array($verify2) && isset($verify2['error'])) {
                        $err = (string) $verify2['error'];
                    }
                    if (function_exists('todo_delete_option')) {
                        todo_delete_option('todo_license_key');
                    }
                    if (class_exists('Todo_Config_Manager')) {
                        Todo_Config_Manager::delete('todo_license_key');
                    }
                    $upgrade_error = $err;
                    break;
                }
                $upgrade_message = '授权验证通过';
                break;

            case 'download_update':
                // 申请下载令牌与URL，并下载更新包
                $version = isset($_POST['version']) ? trim((string) $_POST['version']) : '';
                $req = Todo_Update_Manager::request_download($version);
                if (empty($req['ok'])) {
                    $upgrade_error = '下载申请失败：' . esc_html($req['error'] ?? 'unknown');
                    break;
                }
                $dl = Todo_Update_Manager::download_package($req['download_url']);
                if (empty($dl['ok'])) {
                    $upgrade_error = '下载更新包失败：' . esc_html($dl['error'] ?? 'unknown');
                    break;
                }
                // 写缓存记录最近下载的包路径
                $cacheDir = rtrim((string) ABSPATH, '/') . '/content/updates';
                if (!is_dir($cacheDir)) {
                    @mkdir($cacheDir, 0775, true);
                }
                $meta = array(
                    'version' => $req['version'],
                    'path' => $dl['path'],
                    'time' => time(),
                );
                @file_put_contents($cacheDir . '/last_download.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $upgrade_message = '更新包已下载：' . basename($dl['path']);
                break;

            case 'install_update':
                // 安装最近下载的更新包
                $cacheDir = rtrim((string) ABSPATH, '/') . '/content/updates';
                $metaFile = $cacheDir . '/last_download.json';
                $zipPath = '';
                $downloaded_version = '';
                if (file_exists($metaFile)) {
                    $meta = json_decode(@file_get_contents($metaFile), true);
                    $zipPath = is_array($meta) ? ($meta['path'] ?? '') : '';
                    $downloaded_version = is_array($meta) ? (string) ($meta['version'] ?? '') : '';
                }
                if (!$zipPath || !file_exists($zipPath)) {
                    $upgrade_error = '未找到已下载的更新包，请先执行下载。';
                    break;
                }
                // 先执行备份
                $backup = Todo_Update_Manager::backup_before_upgrade();
                if (empty($backup['ok'])) {
                    $upgrade_error = '安装前备份失败：' . esc_html($backup['error'] ?? 'unknown');
                    break;
                }
                // 安装
                $ins = Todo_Update_Manager::install_update($zipPath);
                if (empty($ins['ok'])) {
                    $upgrade_error = '安装失败：' . esc_html($ins['error'] ?? 'unknown');
                    break;
                }
                if (function_exists('todo_maybe_run_db_updates')) {
                    $dbu = todo_maybe_run_db_updates();
                    if (is_array($dbu) && empty($dbu['ok'])) {
                        $upgrade_error = '数据库更新失败：' . esc_html($dbu['error'] ?? 'unknown');
                        break;
                    }
                }
                if ($downloaded_version !== '' && function_exists('todo_set_cms_version')) {
                    @todo_set_cms_version($downloaded_version);
                }
                if (is_file($cacheDir . '/last_check.json')) {
                    @unlink($cacheDir . '/last_check.json');
                }
                if (is_file($metaFile)) {
                    @unlink($metaFile);
                }
                $upgrade_message = '安装完成。备份文件：' . basename($backup['path']);
                break;

            case 'backup_before_upgrade':
                // 创建备份
                $backup = Todo_Update_Manager::backup_before_upgrade();
                if (empty($backup['ok'])) {
                    $upgrade_error = '备份失败：' . esc_html($backup['error'] ?? 'unknown');
                } else {
                    $upgrade_message = '备份完成：' . basename($backup['path']);
                }
                break;

            case 'clear_update_log':
                // 清空升级日志
                $logPath = defined('TODO_UPDATE_LOG_FILE') ? TODO_UPDATE_LOG_FILE : (ABSPATH . '/logs/update.log');
                if (file_exists($logPath)) {
                    // 尝试截断文件
                    $ok = @file_put_contents($logPath, '');
                    if ($ok === false) {
                        $upgrade_error = '清空日志失败：无法写入日志文件。';
                    } else {
                        $upgrade_message = '升级日志已清空';
                    }
                } else {
                    $upgrade_message = '升级日志文件不存在，无需清空';
                }
                break;
        }

        // 保存更新服务器设置（PRG前处理）
        if ($action === 'save_update_settings') {
            $api = isset($_POST['todo_update_server_url']) ? trim((string) $_POST['todo_update_server_url']) : '';
            $download = isset($_POST['todo_download_server_url']) ? trim((string) $_POST['todo_download_server_url']) : '';
            $domain = isset($_POST['todo_update_license_domain']) ? trim((string) $_POST['todo_update_license_domain']) : '';
            $auto_enabled = isset($_POST['todo_auto_update_enabled']) ? 1 : 0;
            $auto_window = isset($_POST['todo_auto_update_window']) ? trim((string) $_POST['todo_auto_update_window']) : '02:00-05:00';

            // 基础规范化
            if ($api !== '') {
                $api = trim($api, " \t\n\r\0\x0B`'\"");
                $api = rtrim($api, " \t\n\r\0\x0B`'\":)");
                $api = preg_replace('/\s+/', '', $api);
            }
            if ($download !== '') {
                $download = trim($download, " \t\n\r\0\x0B`'\"");
                $download = rtrim($download, " \t\n\r\0\x0B`'\":)");
                $download = preg_replace('/\s+/', '', $download);
            }
            if ($api && !preg_match('#^https?://#i', $api)) {
                $api = 'http://' . $api;
            }
            if ($download && !preg_match('#^https?://#i', $download)) {
                $download = 'http://' . $download;
            }
            if ($domain) {
                $domain = preg_replace('#^https?://#i', '', $domain);
                $domain = preg_replace('/:\\d+$/', '', $domain);
                $domain = strtolower($domain);
            }

            if (function_exists('todo_update_option')) {
                todo_update_option('todo_update_server_url', $api);
                todo_update_option('todo_download_server_url', $download);
                todo_update_option('todo_update_license_domain', $domain);
                todo_update_option('todo_auto_update_enabled', $auto_enabled);
            }
            $upgrade_message = '更新设置已保存';
        }

        // 实现PRG模式防止重复提交
        if (!empty($upgrade_message) || !empty($upgrade_error)) {
            $is_success = (!empty($upgrade_message) && empty($upgrade_error));
            $qs = $is_success ? ('message=' . urlencode($upgrade_message)) : ('error=' . urlencode($upgrade_error));
            $redirect_url = todo_admin_url('index.php?page=updates&' . $qs);

            if (function_exists('todo_should_return_json') && todo_should_return_json()) {
                todo_send_response($is_success, $is_success ? $upgrade_message : $upgrade_error, null, $redirect_url, []);
            }

            if (function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()) {
                while (ob_get_level()) {
                    ob_end_clean();
                }
                echo "<script type=\"text/javascript\">window.location.href = '$redirect_url';</script>";
            } else {
                todo_redirect($redirect_url);
            }
            exit;
        }
    }
}

// 获取URL参数中的消息与错误
if (isset($_GET['message'])) {
    $upgrade_message = $_GET['message'];
}
if (isset($_GET['error'])) {
    $upgrade_error = $_GET['error'];
}

// 获取当前系统信息
$current_version = function_exists('todo_get_cms_version') ? todo_get_cms_version() : (defined('TODO_VERSION') ? TODO_VERSION : '1.0.0');
$php_version = phpversion();
$mysql_version = '';

// 获取MySQL版本
try {
    global $tododb;
    if ($tododb) {
        // 统一兼容 todocms 数据库封装与原生 PDO，避免将 PDOStatement 当作数组使用
        if (is_object($tododb) && method_exists($tododb, 'get_var') && !($tododb instanceof PDO)) {
            // CMS_DB 封装：直接取单值
            $mysql_version = (string) $tododb->get_var("SELECT VERSION()");
        } elseif ($tododb instanceof PDO) {
            // 原生 PDO：query 返回 PDOStatement，需要 fetchColumn
            $stmt = $tododb->query("SELECT VERSION()");
            if ($stmt instanceof PDOStatement) {
                $mysql_version = (string) $stmt->fetchColumn();
                try {
                    $stmt->closeCursor();
                } catch (Throwable $e) {
                }
            }
        } else {
            // 兜底：使用通用选择器（若存在）
            if (function_exists('todo_db_select')) {
                $rows = todo_db_select("SELECT VERSION() as version");
                if (!empty($rows)) {
                    $mysql_version = (string) $rows[0]['version'];
                }
            }
        }
    }
} catch (Exception $e) {
    $mysql_version = '无法获取';
}

// 可用更新：从最近一次检查结果构建
$available_updates = [];
$lastCheckPath = rtrim((string) ABSPATH, '/') . '/content/updates/last_check.json';
if (file_exists($lastCheckPath)) {
    $lastData = json_decode(@file_get_contents($lastCheckPath), true);
    if (is_array($lastData) && !empty($lastData['has_update'])) {
        $info = $lastData['update_info'] ?? [];
        $size_bytes = (int) ($info['download_size'] ?? 0);
        $package_type = !empty($info['package_type']) ? (string)$info['package_type'] : (!empty($info['delta_available']) ? 'diff' : 'full');
        $available_updates[] = array(
            'version' => (string) ($info['version'] ?? ($lastData['latest_version'] ?? '')),
            'release_date' => (string) ($info['release_date'] ?? ''),
            'package_type' => $package_type,
            'description' => !empty($info['changelog']) ? (is_array($info['changelog']) ? implode('；', $info['changelog']) : (string) $info['changelog']) : '发现新版本可用',
            'size_bytes' => $size_bytes,
            'critical' => false,
        );
    }
}

// 检查系统要求
$system_requirements = [
    'php_version' => [
        'required' => '7.4.0',
        'current' => $php_version,
        'status' => version_compare($php_version, '7.4.0', '>=')
    ],
    'mysql_version' => [
        'required' => '5.7.0',
        'current' => $mysql_version,
        'status' => version_compare($mysql_version, '5.7.0', '>=')
    ],
    'disk_space' => [
        'required' => '100 MB',
        'current' => '500 MB',
        'status' => true
    ]
];

?>

<div class="admin-page">
    <?php if ($upgrade_error): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html($upgrade_error); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <?php if ($upgrade_message): ?>
        <div class="notice notice-info is-dismissible">
            <p><?php echo esc_html($upgrade_message); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <!-- 可用更新 -->
    <?php if (!empty($available_updates)): ?>
        <div class="admin-card">
            <div class="admin-card-header no-border">
                <h3 class="admin-card-title">可用更新</h3>
            </div>
            <div class="admin-card-content">
           		<?php foreach ($available_updates as $update): ?>
                <div class="update-item blueline"
                    style="padding: 15px 15px 15px 25px; margin-bottom: 15px;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px 20px;">
                        <div>
                            <h3 style="color: #0082e2;">
                                TodoCMS <?php echo esc_html($update['version']); ?><?php if ($update['critical']): ?>
                                <span style="background: #d53651;color: white;margin-left: 10px;padding: 2px 6px;border-radius: 3px;font-size: 12px;font-weight: normal;vertical-align: text-top;">重要</span>
                                <?php endif; ?>
                            </h3>
                            <p style="font-size: 13px;">
                                发布日期: <?php echo esc_html($update['release_date']); ?><span style="opacity: 0.3; margin: 0 10px;">|</span>
                                大小: <?php
                                    $bytes = (int) ($update['size_bytes'] ?? 0);
                                    $sizeText = '';
                                    if ($bytes > 0) {
                                        if (function_exists('size_format')) {
                                            $sizeText = (string) size_format($bytes, 2);
                                        } elseif (function_exists('todo_format_bytes')) {
                                            $sizeText = (string) todo_format_bytes($bytes, 2);
                                        } elseif (function_exists('todo_format_file_size')) {
                                            $sizeText = (string) todo_format_file_size($bytes);
                                        } else {
                                            $sizeText = $bytes . ' bytes';
                                        }
                                    } else {
                                        $sizeText = '未知大小';
                                    }
                                    echo esc_html($sizeText);
                                ?><span style="opacity: 0.3; margin: 0 10px;">|</span>
                                类型: <?php echo (($update['package_type'] ?? '') === 'diff') ? '差量补丁' : '全量包'; ?>
                            </p>
                        </div>
                        <div style="flex-shrink: 0;">
                            <?php
                            $downloadedForThisVersion = false;
                            $cacheDir = ABSPATH . '/content/updates';
                            $metaFile = $cacheDir . '/last_download.json';
                            if (file_exists($metaFile)) {
                                $m = json_decode(@file_get_contents($metaFile), true);
                                $zipPath = is_array($m) ? (string)($m['path'] ?? '') : '';
                                $zipVer  = is_array($m) ? (string)($m['version'] ?? '') : '';
                                if ($zipPath !== '' && is_file($zipPath) && $zipVer !== '' && $zipVer === (string)$update['version']) {
                                    $downloadedForThisVersion = true;
                                }
                            }
                            ?>
                            <?php if (!$downloadedForThisVersion): ?>
                                <form method="post" action="<?php echo todo_admin_url('index.php?page=updates'); ?>" style="display: inline-block;" class="todo-request">
                                    <?php Todo_Security_Manager::output_csrf_field('system_upgrade'); ?>
                                    <input type="hidden" name="action" value="download_update">
                                    <input type="hidden" name="version" value="<?php echo esc_attr($update['version']); ?>">
                                    <button type="submit" class="button button-secondary">下载</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="<?php echo todo_admin_url('index.php?page=updates'); ?>" style="display: inline-block;" class="todo-request">
                                    <?php Todo_Security_Manager::output_csrf_field('system_upgrade'); ?>
                                    <input type="hidden" name="action" value="install_update">
                                    <input type="hidden" name="version" value="<?php echo esc_attr($update['version']); ?>">
                                    <button type="submit" class="button button-primary">安装</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <p style="font-size: 14px;white-space: pre-wrap;max-height: 140px;overflow: auto;width: 100%;f le: fle;"><?php echo esc_html($update['description']); ?></p>
                    </div>
                </div>
           		<?php endforeach; ?>
            </div>
        </div>
    <?php else: ?>

    <?php endif; ?>

    <!-- 当前系统信息 -->
    <div class="admin-card">
        <div class="admin-card-header no-border">
            <h3 class="admin-card-title">当前系统信息</h3>
        </div>
        <div class="admin-card-content">
            <table class="admin-table">
                <tr>
                    <th>TodoCMS 版本</th>
                    <td style="min-width: 120px;"><strong><?php echo esc_html($current_version); ?></strong></td>
                    <th>PHP 版本</th>
                    <td style="min-width: 100px;"><?php echo esc_html($php_version); ?></td>
                </tr>
                <tr>
                    <th>最后检查更新</th>
                    <td style="min-width: 120px;">
                        <?php
                        $lastCheckPath = rtrim((string) ABSPATH, '/') . '/content/updates/last_check.json';
                        if (file_exists($lastCheckPath)) {
                            $lastData = json_decode(@file_get_contents($lastCheckPath), true);
                            $ts = is_array($lastData) ? ($lastData['raw']['timestamp'] ?? time()) : time();
                            echo esc_html(date('Y-m-d H:i:s', (int) $ts));
                        } else {
                            echo '从未检查';
                        }
                        ?>
                    </td>
                    <th>MySQL 版本</th>
                    <td style="min-width: 100px;"><?php echo esc_html($mysql_version); ?></td>
                </tr>
            </table>

            <form method="post" action="<?php echo todo_admin_url('index.php?page=updates'); ?>" style="margin-top: 20px;" class="todo-request">
                <?php Todo_Security_Manager::output_csrf_field('system_upgrade'); ?>
                <input type="hidden" name="action" value="check_updates">
                <button type="submit" class="button button-secondary">检查更新</button>
            </form>
        </div>
    </div>

    <!-- 更新服务器设置 -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h3 class="admin-card-title">更新服务器设置</h3>
        </div>
        <div class="admin-card-content">
            <?php 
            // 强制重新加载配置，确保回填数据是数据库中的最新状态
            if (class_exists('Todo_Config_Manager')) Todo_Config_Manager::reload();
            $cfg = function_exists('todo_get_update_config') ? todo_get_update_config() : array('api' => '', 'download' => '', 'domain' => '', 'pubkey' => '');
            $opt_api = function_exists('todo_get_option') ? (string) todo_get_option('todo_update_server_url') : '';
            $opt_dl = function_exists('todo_get_option') ? (string) todo_get_option('todo_download_server_url') : '';
            $opt_domain = function_exists('todo_get_option') ? (string) todo_get_option('todo_update_license_domain') : '';
            $opt_auto_enabled = function_exists('todo_get_option') ? (int) todo_get_option('todo_auto_update_enabled') : 0;
            
            $val_api = $opt_api ?: ($cfg['api'] ?? '');
            $val_dl = $opt_dl ?: ($cfg['download'] ?? '');
            $val_domain = $opt_domain ?: ($cfg['domain'] ?? '');
            ?>
            <form method="post" action="<?php echo todo_admin_url('index.php?page=updates'); ?>" class="todo-request">
                <?php Todo_Security_Manager::output_csrf_field('system_upgrade'); ?>
                <input type="hidden" name="action" value="save_update_settings">
                <div class="form-group">
                    <label for="site-title">更新服务器 API 地址</label>
                    <input type="text" name="todo_update_server_url" value="<?php echo esc_attr($val_api); ?>"
                        style="width: 50%;" class="mw-100">
                    <p class="description">如：http://服务地址/api.php</p>
                </div>
                <div class="form-group">
                    <label for="site-title">下载服务器地址</label>
                    <input type="text" name="todo_download_server_url" value="<?php echo esc_attr($val_dl); ?>"
                        style="width: 50%;" class="mw-100">
                    <p class="description">如：http://服务地址/download.php</p>
                </div>
                <div class="form-group">
                    <label for="site-title">授权域名<span class="description" id="license-domain-desc">（变更域名请下方重新“验证授权”）</span></label>
                    <input type="text" name="todo_update_license_domain"
                        value="<?php echo esc_attr($val_domain); ?>" style="width: 50%;" class="mw-100"
                        onfocus="var d=document.getElementById('license-domain-desc');if(d)d.style.color='#d43838';"
                        onblur="var d=document.getElementById('license-domain-desc');if(d)d.style.color='';">
                    <p class="description">如：example.com、www.example.com、127.0.0.1</p>
                </div>
                <div class="form-group">
                    <label><input type="checkbox" name="todo_auto_update_enabled" value="1" <?php echo $opt_auto_enabled ? 'checked' : ''; ?>> 自动更新</label>  
                    <p class="description">开启自动更新（官方发布新版本后，系统将及时享受最新漏洞修补）。</p>
                </div>
                <div class="m-t-20">
                    <button type="submit" class="button button-primary">保存设置</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 授权设置 -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h3 class="admin-card-title">授权设置</h3>
        </div>
        <div class="admin-card-content">
            <?php
                $saved_key = function_exists('todo_get_option') ? (string) todo_get_option('todo_license_key') : '';
            ?>
            <form method="post" action="<?php echo todo_admin_url('index.php?page=updates'); ?>" class="todo-request">
                <?php Todo_Security_Manager::output_csrf_field('system_upgrade'); ?>
                <input type="hidden" name="action" value="verify_license_key">
                <div class="form-group">
                    <label>授权码</label>
                    <input type="text" name="license_key" value="<?php echo esc_attr($saved_key); ?>" style="width: 50%;" class="mw-100" placeholder="请输入授权码">
                    <p class="description">请先完成“更新服务器设置”，授权需要域名绑定验证。</p>
                </div>
                <div class="flex items-center flex-wrap justify-between gap-x-10 gap-y-2 m-t-20">
                    <button type="submit" class="button button-secondary">验证授权</button>
                    <?php if (function_exists('todo_has_update_authorization') && todo_has_update_authorization() && !empty($opt_api) && !empty($saved_key)): ?>
                    <a href="<?php echo todo_admin_url('index.php?page=user-license'); ?>" class="button button-secondary flex items-center gap-1">
                        <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 48 48">
                            <path d="M0 0h48v48H0z" fill="none"></path>
                            <path fill="currentColor" stroke="currentColor" stroke-linejoin="round" stroke-width="4" d="m23.999 5l-6.113 12.478L4 19.49l10.059 9.834L11.654 43L24 36.42L36.345 43L33.96 29.325L44 19.491l-13.809-2.013z"></path>
                        </svg>
                        授权管理</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- 系统要求检查 -->
    <div class="admin-card">
        <div class="admin-card-header no-border">
            <h3 class="admin-card-title">系统要求检查</h3>
        </div>
        <div class="admin-card-content" style="overflow: auto;">
            <table class="widefat">
                <thead>
                    <tr>
                        <th style="min-width: 120px;">项目</th>
                        <th style="min-width: 100px;">要求</th>
                        <th style="min-width: 100px;">当前</th>
                        <th style="min-width: 80px;">状态</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($system_requirements as $key => $req): ?>
                        <tr>
                            <td>
                                <?php
                                switch ($key) {
                                    case 'php_version':
                                        echo 'PHP 版本';
                                        break;
                                    case 'mysql_version':
                                        echo 'MySQL 版本';
                                        break;
                                    case 'disk_space':
                                        echo '磁盘空间';
                                        break;
                                    default:
                                        echo $key;
                                }
                                ?>
                            </td>
                            <td>> <?php echo esc_html($req['required']); ?></td>
                            <td><?php echo esc_html($req['current']); ?></td>
                            <td>
                                <?php if ($req['status']): ?>
                                    <span style="color: green;">✓ 满足</span>
                                <?php else: ?>
                                    <span style="color: #d43838;">✗ 不满足</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 升级前备份 -->
    <div class="admin-card">
        <div class="admin-card-header no-border">
            <h3 class="admin-card-title">升级前备份</h3>
        </div>
        <div class="admin-card-content">
            <p class="m-b-10">默认进行自动备份，以防升级过程中出现问题。</p>
            <pre class="pre">
备份程序会排除以下目录，如需要请手动备份。
/content/backups
/content/cache
/content/uploads
/content/updates
/logs
</pre>
            <form method="post" action="<?php echo todo_admin_url('index.php?page=updates'); ?>" style="margin-top: 20px;" class="todo-request">
                <?php Todo_Security_Manager::output_csrf_field('system_upgrade'); ?>
                <input type="hidden" name="action" value="backup_before_upgrade">
                <button type="submit" class="button button-secondary">创建备份（包含主要目录及SQL）</button>
            </form>
        </div>
    </div>

    <!-- 升级日志 -->
    <div class="admin-card">
        <div class="admin-card-header no-border">
            <h3 class="admin-card-title">升级日志</h3>
        </div>
        <div class="admin-card-content">
            <div class="flex items-center justify-between">
                <p>下方显示的是最近的升级日志。</p>
                <form method="post" action="<?php echo todo_admin_url('index.php?page=updates'); ?>" onsubmit="return TodoCMS.Utils.confirmAction(this, '确定要清空升级日志吗？');" style="margin:0;" class="todo-request">
                    <?php Todo_Security_Manager::output_csrf_field('system_upgrade'); ?>
                    <input type="hidden" name="action" value="clear_update_log">
                    <button type="submit" class="button button-secondary">清空日志</button>
                </form>    
            </div>
            <div class="pre" style="font-family: monospace; max-height: 300px; overflow-y: auto; margin-top:10px;">
                <?php
                $logPath = defined('TODO_UPDATE_LOG_FILE') ? TODO_UPDATE_LOG_FILE : (ABSPATH . '/logs/update.log');
                if (file_exists($logPath)) {
                    $lines = @file($logPath);
                    if ($lines) {
                        foreach ($lines as $line) {
                            echo '<p style="margin:0;">' . esc_html(rtrim($line)) . '</p>';
                        }
                    } else {
                        echo '<p style="margin:0;">暂无日志内容。</p>';
                    }
                } else {
                    echo '<p style="margin:0;">尚未生成升级日志。</p>';
                }
                ?>
            </div>
        </div>
    </div>
</div>

<style>
    .form-table th {
        width: 200px;
        font-weight: 500;
    }

    .widefat {
        width: 100%;
        border-collapse: collapse;
    }

    .widefat th,
    .widefat td {
        padding: 8px 10px;
        border: 1px solid #eceef1;
        text-align: left;
    }

    .widefat th {
        background: #f9f9f9;
        font-weight: 500;
    }
</style>
<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        var UPDATE_AUTH = <?php echo (function_exists('todo_has_update_authorization') && todo_has_update_authorization()) ? 'true' : 'false'; ?>;
        function findNonce(form) {
            var el = form ? form.querySelector('input[name="_todononce"]') : null;
            return el && el.value ? String(el.value) : '';
        }

        function ajaxPost(url, data, onOk, onErr) {
            if (!window.TodoRequest || typeof window.TodoRequest.ajax !== 'function') {
                onErr(new Error('TodoRequest not available'));
                return;
            }
            window.TodoRequest.ajax({
                url: url,
                method: 'POST',
                data: data,
                onSuccess: function (resp) { onOk(resp); },
                onError: function (err) { onErr(err); }
            });
        }

        function runJob(mode, version, nonce) {
            var ajaxUrl = "<?php echo esc_js(todo_admin_url('admin-ajax.php')); ?>";

            var startData = new FormData();
            startData.append('action', 'system_update');
            startData.append('op', 'start');
            startData.append('mode', mode);
            if (version) startData.append('version', version);
            if (nonce) startData.append('_todononce', nonce);

            var modal = null;
            if (window.TodoModal && typeof window.TodoModal.progress === 'function') {
                modal = window.TodoModal.progress({
                    title: mode === 'backup' ? '创建备份' : '系统更新',
                    statusText: '准备中…（关闭弹窗将停止在当前步骤，可再次点击继续）',
                    showLogs: true
                });
                modal.updateProgress(0, 1, '准备中…');
            }

            var stopped = false;
            if (modal && typeof modal.close === 'function') {
                var _close = modal.close;
                modal.close = function () {
                    stopped = true;
                    return _close.apply(this, arguments);
                };
            }

            ajaxPost(ajaxUrl, startData, function (resp) {
                if (!resp || resp.success !== true || !resp.data || !resp.data.id) {
                    if (modal && typeof modal.complete === 'function') {
                        modal.complete(false, (resp && (resp.error || resp.message)) ? (resp.error || resp.message) : '启动失败');
                    } else if (window.TodoCMS && TodoCMS.Utils && TodoCMS.Utils.showMessage) {
                        TodoCMS.Utils.showMessage('启动失败', 'error');
                    }
                    return;
                }

                var jobId = String(resp.data.id);
                var total = resp.data.total_steps ? Number(resp.data.total_steps) : 0;
                if (modal && total > 0) {
                    modal.updateProgress(0, total, '已启动');
                }

                function stepOnce() {
                    if (stopped) return;

                    var stepData = new FormData();
                    stepData.append('action', 'system_update');
                    stepData.append('op', 'step');
                    stepData.append('job_id', jobId);
                    if (nonce) stepData.append('_todononce', nonce);

                    ajaxPost(ajaxUrl, stepData, function (r2) {
                        var d = r2 && r2.data ? r2.data : null;
                        if (!r2 || r2.success !== true || !d) {
                            if (modal && typeof modal.complete === 'function') {
                                modal.complete(false, (r2 && (r2.error || r2.message)) ? (r2.error || r2.message) : '执行失败');
                            }
                            return;
                        }

                        var t = d.total_steps ? Number(d.total_steps) : total;
                        var c = d.progress_current ? Number(d.progress_current) : 0;
                        if (d.status === 'done' || d.status === 'done_relogin') {
                            c = t;
                        }
                        var statusText = d.step ? ('当前步骤：' + d.step) : (d.message || '处理中');
                        var logs = Array.isArray(d.logs) ? d.logs : [];
                        var lastLog = logs.length ? logs[logs.length - 1] : '';

                        if (modal && typeof modal.updateProgress === 'function') {
                            modal.updateProgress(c, t, statusText, lastLog);
                        }

                        if (d.status === 'done' || d.status === 'done_relogin') {
                            var isRelogin = d.status === 'done_relogin';
                            var targetUrl = (d && d.redirect) ? d.redirect : '';
                            
                            if (isRelogin) {
                                var countdown = 10;
                                if (modal && typeof modal.complete === 'function') {
                                    modal.complete(true, '版本更新已完成，' + countdown + 's后重新登录');
                                }
                                var timer = setInterval(function() {
                                    countdown--;
                                    if (countdown > 0) {
                                        if (modal && typeof modal.complete === 'function') {
                                            modal.complete(true, '版本更新已完成，' + countdown + 's后重新登录');
                                        }
                                    } else {
                                        clearInterval(timer);
                                        var topWin = (window.parent && window.parent !== window) ? window.parent : window;
                                        if (targetUrl) {
                                            topWin.location.href = targetUrl;
                                        } else {
                                            try {
                                                var currentPath = topWin.location.pathname;
                                                topWin.location.href = currentPath + '?login_message=' + encodeURIComponent('版本更新已完成，请重新登录');
                                            } catch (e) {
                                                topWin.location.reload();
                                            }
                                        }
                                    }
                                }, 1000);
                            } else {
                                if (modal && typeof modal.complete === 'function') {
                                    modal.complete(true, '已完成');
                                }
                                setTimeout(function () { 
                                    if (window.parent !== window) {
                                        window.parent.location.reload();
                                    } else {
                                        window.location.reload();
                                    }
                                }, 800);
                            }
                            return;
                        }
                        if (d.status === 'error' || d.status === 'cancelled') {
                            var msg = d.error || d.message || '失败';
                            if (modal && typeof modal.complete === 'function') {
                                modal.complete(false, msg);
                            }
                            return;
                        }

                        setTimeout(stepOnce, 200);
                    }, function () {
                        if (modal && typeof modal.complete === 'function') {
                            modal.complete(false, '网络错误');
                        }
                    });
                }

                stepOnce();
            }, function () {
                if (modal && typeof modal.complete === 'function') {
                    modal.complete(false, '网络错误');
                }
            });
        }

        function bind(form, mode) {
            if (!form) return;
            if (!UPDATE_AUTH) return;
            form.addEventListener('submit', function (e) {
                if (!window.TodoRequest || !window.TodoModal) {
                    return;
                }
                e.preventDefault();
                var version = '';
                var vEl = form.querySelector('input[name="version"]');
                if (vEl && vEl.value) version = String(vEl.value);
                runJob(mode, version, findNonce(form));
            });
        }

        document.querySelectorAll('form.todo-request input[name="action"][value="download_update"]').forEach(function (el) {
            bind(el.closest('form'), 'download');
        });
        document.querySelectorAll('form.todo-request input[name="action"][value="install_update"]').forEach(function (el) {
            bind(el.closest('form'), 'install');
        });
        document.querySelectorAll('form.todo-request input[name="action"][value="backup_before_upgrade"]').forEach(function (el) {
            bind(el.closest('form'), 'backup');
        });

        // 自动更新检测与执行逻辑
        var AUTO_UPDATE_ENABLED = <?php echo $opt_auto_enabled ? 'true' : 'false'; ?>;
        var AVAILABLE_UPDATES = <?php echo json_encode($available_updates); ?>;
        
        if (AUTO_UPDATE_ENABLED && UPDATE_AUTH) {
            var anyForm = document.querySelector('form.todo-request');
            var nonce = anyForm ? findNonce(anyForm) : '';
            
            if (AVAILABLE_UPDATES && AVAILABLE_UPDATES.length > 0) {
                // 如果当前已有可用更新（通过页面渲染得知），直接弹窗安装
                var firstUpdate = AVAILABLE_UPDATES[0];
                setTimeout(function() {
                    runJob('full', firstUpdate.version, nonce);
                }, 500);
            } else {
                // 否则，在当前会话中仅自动检测一次，避免每次刷新都检测
                if (!sessionStorage.getItem('todo_auto_update_checked')) {
                    sessionStorage.setItem('todo_auto_update_checked', '1');
                    
                    var checkData = new FormData();
                    checkData.append('action', 'auto_check_updates');
                    if (nonce) checkData.append('_todononce', nonce);
                    
                    ajaxPost(window.location.href, checkData, function(resp) {
                        if (resp && resp.success && resp.data && resp.data.has_update && resp.data.version) {
                            // 检测到新版本，自动弹窗开始安装全流程
                            runJob('full', resp.data.version, nonce);
                        }
                    }, function(err) {
                        console.warn('自动检测更新失败', err);
                    });
                }
            }
        }
    });
</script>
