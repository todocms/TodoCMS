<?php
/**
 * 安装脚本
 *
 * 引导用户完成CMS系统的安装过程
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

// 在加载 autoload 之前定义关键常量，避免 autoload 触发数据库连接时这些常量未定义
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/');
}

// 定义安装常量（必须在 autoload 之前定义，因为 autoload 可能会触发数据库连接）
if (!defined('INSTALLING')) {
    define('INSTALLING', true);
}

require_once dirname(__FILE__) . '/vendor/autoload.php';

// 检查是否已安装（仅在非强制模式下检查）
if ((!isset($_GET['force']) || $_GET['force'] != '1') && file_exists(ABSPATH . 'config.php')) {
    require_once(ABSPATH . 'config.php');
    if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASSWORD')) {
        try {

            if (function_exists('todo_get_db_connection')) {
                $test_db = todo_get_db_connection();
            } else {
                // 安装时的备用连接方式
                $test_db = new PDO(
                    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                    DB_USER,
                    DB_PASSWORD,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
            }

            // 检查是否存在系统配置表和 installed 记录
            $stmt = $test_db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?");
            $stmt->execute([DB_NAME, TABLE_PREFIX . 'system_config']);
            $table_exists = $stmt->fetchColumn() > 0;
            $stmt->closeCursor();

            if ($table_exists) {
                $stmt = $test_db->prepare("SELECT config_value FROM " . TABLE_PREFIX . "system_config WHERE config_key = 'installed' LIMIT 1");
                $stmt->execute();
                $installed_val = $stmt->fetchColumn();
                $stmt->closeCursor();

                if ($installed_val === '1' || $installed_val === 1 || $installed_val === 'true') {
                    die('<h1>系统已安装</h1><p>TodoCMS 已经安装完成。如需重新安装，请先删除 config.php 文件并清空数据库。</p>');
                }
            }
        } catch (Exception $e) {
            // 数据库连接失败，继续安装流程
        }
    }
}

// 直接加载必要的文件，而不是通过index.php
require_once(ABSPATH . 'core/functions/formatting.php');

// 定义表前缀常量
if (file_exists(ABSPATH . 'config.php')) {
    require_once(ABSPATH . 'config.php');
    if (!defined('TABLE_PREFIX')) {
        define('TABLE_PREFIX', 'todo_');
    }
} elseif (!defined('TABLE_PREFIX')) {
    define('TABLE_PREFIX', 'todo_');
}

/**
 * 安装过程中的选项获取函数
 * 注意：此函数仅在安装过程中使用，与 core/functions/core.php 中的标准实现分离
 * 
 * @param string $option 选项名称
 * @param mixed $default 默认值
 * @return mixed 选项值
 */
function get_option_for_install($option, $default = false)
{
    global $tododb;

    if (!$tododb) {
        return $default;
    }

    try {
        // 使用新的 system_config 表
        $result = $tododb->query(
            "SELECT config_value FROM " . TABLE_PREFIX . "system_config WHERE config_key = ? AND group_name = ? LIMIT 1",
            [$option, 'general']
        );

        if ($result && count($result) > 0) {
            $row = $result[0];
            $value = $row['config_value'];
            // 尝试反序列化值，如果失败则返回原始值
            if ($value !== null && is_string($value) && preg_match('/^[aOs]:\d+:/', $value)) {
                $unserialized = @unserialize($value);
                if ($unserialized !== false || $value === 'b:0;') {
                    return $unserialized;
                }
            }
            return $value;
        }
    } catch (Exception $e) {
        error_log('获取选项失败: ' . $e->getMessage());
    }

    return $default;
}

/**
 * 获取站点URL
 *
 * @param string $path 路径
 * @return string 站点URL
 */
function todo_url($path = '')
{
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';

    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';

    $script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));

    $base_url = $protocol . $host;

    if ($script_dir !== '/' && $script_dir !== '') {
        $base_url .= $script_dir;
    }

    if (!empty($path)) {
        $base_url .= '/' . ltrim($path, '/');
    }

    return rtrim($base_url, '/');
}

/**
 * 更新选项值
 *
 * @param string $option 选项名称
 * @param mixed $value 选项值
 * @return bool 是否成功
 */
function update_option($option, $value)
{
    global $tododb;

    if (!$tododb) {
        return false;
    }

    try {
        // 如果值是数组，将其序列化
        if (is_array($value)) {
            $value = serialize($value);
        }

        // 使用 schema.php 中定义的 system_config 表结构（主键为 config_id）
        $exists = $tododb->query(
            "SELECT config_id FROM " . TABLE_PREFIX . "system_config WHERE config_key = ? AND group_name = ? LIMIT 1",
            [$option, 'general']
        );

        if ($exists && count($exists) > 0) {
            // 更新选项
            $result = $tododb->query(
                "UPDATE " . TABLE_PREFIX . "system_config SET config_value = ? WHERE config_key = ? AND group_name = ?",
                [$value, $option, 'general']
            );
        } else {
            // 插入选项
            $result = $tododb->query(
                "INSERT INTO " . TABLE_PREFIX . "system_config (config_key, config_value, group_name) VALUES (?, ?, ?)",
                [$option, $value, 'general']
            );
        }

        return $result !== false;
    } catch (Exception $e) {
        return false;
    }
}

// 安装步骤
$step = isset($_GET['step']) ? (int) $_GET['step'] : 1;

// 安装头部
function install_header()
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>TodoCMS 安装</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                line-height: 1.6;
                color: #2f313a;
                background-color: #f2f4f7;
                max-width: 800px;
                margin: 0 auto;
                padding: 20px;
            }
            h1 {
                font-weight: 500;
            }
            p {
                color: #999;
            }
            th {
                color: #999;
                font-weight: normal;
            }
            .step {
                background: #fff;
                box-shadow: 0 3px 5px rgba(0, 0, 0, 0.01);
                padding: 20px 40px;
                margin-bottom: 20px;
                border-radius: 5px;
            }
            .form-group {
                margin-bottom: 15px;
            }
            label {
                display: block;
                margin-bottom: 5px;
                font-weight: 500;
            }
            input[type="text"],
            input[type="password"],
            input[type="email"] {
                width: 100%;
                padding: 8px;
                border: 1px solid #eee;
                border-radius: 3px;
                box-sizing: border-box;
            }
            button {
                background: #0073aa;
                color: white;
                border: none;
                padding: 10px 15px;
                border-radius: 3px;
                cursor: pointer;
            }
            button:hover {
                background: #005f8b;
            }
            .description {
                font-size: 13px;
                font-style: italic;
                margin: 5px 0 0;
            }
            .inline-label {
                display: inline-block;
                margin: 0 0 0 -3px;
                font-weight: 400;
                font-size: 12px;
                flex-shrink: 0;
            }
            .password-toggle {
                margin-top: 6px;
                user-select: none;
            }
            .error {
                color: #d63638;
                background: #ffebe8;
                border: 1px solid #d63638;
                padding: 10px 15px;
                margin-bottom: 15px;
                border-radius: 3px;
            }
            .success {
                color: #00a32a;
                background: #edfaef;
                border: 1px solid #00a32a;
                padding: 10px;
                margin-bottom: 15px;
                border-radius: 3px;
            }
        </style>
    </head>
    <body>
        <h1>TodoCMS 安装</h1>';
}

// 安装底部
function install_footer()
{
    echo '</body>
    </html>';
}

// 显示错误信息
function show_error($message)
{
    echo '<div class="error">' . $message . '</div>';
}

// 显示成功信息
function show_success($message)
{
    echo '<div class="success">' . $message . '</div>';
}

// 检查系统要求
function check_requirements()
{
    $requirements = [];

    // 检查PHP版本
    $php_version = phpversion();
    $requirements['php_version'] = [
        'name' => 'PHP版本',
        'required' => '7.4.0',
        'current' => $php_version,
        'status' => version_compare($php_version, '7.4.0', '>=')
    ];

    // 检查MySQL扩展
    $mysql_extension = extension_loaded('mysqli') || extension_loaded('pdo_mysql');
    $requirements['mysql_extension'] = [
        'name' => 'MySQL扩展',
        'required' => '已安装',
        'current' => $mysql_extension ? '已安装' : '未安装',
        'status' => $mysql_extension
    ];

    // 检查JSON扩展
    $json_extension = extension_loaded('json');
    $requirements['json_extension'] = [
        'name' => 'JSON扩展',
        'required' => '已安装',
        'current' => $json_extension ? '已安装' : '未安装',
        'status' => $json_extension
    ];

    // 检查目录权限
    $writable_dirs = [
        '.' => is_writable('.'),
        './core' => is_writable('./core'),
        './content/themes' => is_writable('./content/themes'),
        './content/plugins' => is_writable('./content/plugins'),
        './content/uploads' => is_writable('./content/uploads')
    ];

    $all_writable = !in_array(false, $writable_dirs);
    $requirements['directory_permissions'] = [
        'name' => '目录权限',
        'required' => '可写',
        'current' => $all_writable ? '可写' : '部分目录不可写',
        'status' => $all_writable
    ];

    return $requirements;
}

// 安装步骤1：系统要求检查
function install_step1()
{
    $requirements = check_requirements();
    $can_proceed = true;

    echo '<div class="step">
        <h2>步骤1：系统要求检查</h2>
        <p>安装前，请确保您的服务器满足以下要求：</p>
        
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <th style="text-align: left; padding: 8px; border-bottom: 1px solid #eee;">要求</th>
                <th style="text-align: left; padding: 8px; border-bottom: 1px solid #eee;">最低要求</th>
                <th style="text-align: left; padding: 8px; border-bottom: 1px solid #eee;">当前状态</th>
                <th style="text-align: left; padding: 8px; border-bottom: 1px solid #eee;">结果</th>
            </tr>';

    foreach ($requirements as $requirement) {
        $status_color = $requirement['status'] ? 'green' : 'red';
        $status_text = $requirement['status'] ? '通过' : '未通过';

        echo '<tr>
            <td style="padding: 8px; border-bottom: 1px solid #eee;">' . $requirement['name'] . '</td>
            <td style="padding: 8px; border-bottom: 1px solid #eee;">' . $requirement['required'] . '</td>
            <td style="padding: 8px; border-bottom: 1px solid #eee;">' . $requirement['current'] . '</td>
            <td style="padding: 8px; border-bottom: 1px solid #eee; color: ' . $status_color . ';">' . $status_text . '</td>
        </tr>';

        if (!$requirement['status']) {
            $can_proceed = false;
        }
    }

    echo '</table>';

    // 显示不可写的目录列表
    if (!$requirements['directory_permissions']['status']) {
        $writable_dirs = [
            '.' => is_writable('.'),
            './core' => is_writable('./core'),
            './content/themes' => is_writable('./content/themes'),
            './content/plugins' => is_writable('./content/plugins'),
            './content/uploads' => is_writable('./content/content/uploads')
        ];

        echo '<div style="margin-top: 15px;">
            <h3 style="color: red;">以下目录权限未通过：</h3>
            <ul>';

        foreach ($writable_dirs as $dir => $writable) {
            if (!$writable) {
                echo '<li style="color: red;">' . $dir . ' - 不可写</li>';
            }
        }

        echo '</ul>
            <p>请确保上述目录存在且有写入权限。在Linux/Unix系统上，您可以使用以下命令：</p>
            <pre>chmod -R 755 [目录路径]</pre>
            <p>或者，如果需要更高权限：</p>
            <pre>chmod -R 777 [目录路径]</pre>
        </div>';
    }

    if (!$can_proceed) {
        echo '<p style="color: red;">您的服务器不满足安装要求，请解决上述问题后再继续。</p>';
    } else {
        echo '<p style="margin-top: 20px;"><a href="install.php?';
        echo isset($_GET['force']) && $_GET['force'] == '1' ? 'force=1&' : '';
        echo 'step=2"><button>开 始</button></a></p>';
    }

    echo '</div>';
}

// 安装步骤2：数据库配置
function install_step2()
{
    // 在配置/重新配置数据库前清理原有遗留缓存，确保安装环境纯净
    todo_install_purge_all_caches();

    $error = '';
    $config_sample = file_get_contents('config-sample.php');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $tododb_name = trim((string) ($_POST['db_name'] ?? ''));
        $tododb_user = trim((string) ($_POST['db_user'] ?? ''));
        $tododb_password = (string) ($_POST['db_password'] ?? '');
        $tododb_host = trim((string) ($_POST['db_host'] ?? ''));
        $table_prefix = trim((string) ($_POST['table_prefix'] ?? ''));

        // 验证输入
        if (empty($tododb_name) || empty($tododb_user) || empty($tododb_host) || empty($table_prefix)) {
            $error = '所有字段都是必填的。';
        } else {
            // 尝试连接数据库（先进行非异常的预检查，避免直接抛出PDO异常导致调试器中断）
            $precheck_ok = true;
            if (function_exists('mysqli_connect')) {
                // 使用输出缓冲进一步吞掉底层扩展可能输出的告警文本
                ob_start();
                $mysqli = @mysqli_connect($tododb_host, $tododb_user, $tododb_password);
                ob_end_clean();
                if (!$mysqli) {
                    $err = function_exists('mysqli_connect_error') ? mysqli_connect_error() : '未知错误';
                    $error = '无法连接到数据库：' . $err . '。请确认用户名与密码正确。';
                    $precheck_ok = false;
                } else {
                    if (!@mysqli_select_db($mysqli, $tododb_name)) {
                        $safe_dbname = htmlspecialchars($tododb_name, ENT_QUOTES, 'UTF-8');
                        $error = '无法选择数据库：' . $safe_dbname . '。请确认该数据库已创建且当前用户有权限访问。';
                        $precheck_ok = false;
                    }
                    @mysqli_close($mysqli);
                }
            }
            if ($precheck_ok) {
                try {
                    // 使用完整的DSN，包括端口和字符集
                    $dsn = "mysql:host={$tododb_host};dbname={$tododb_name};charset=utf8mb4";
                    $options = [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ];
                    // 安装时直接创建PDO连接（因为连接管理器可能还未初始化）
                    $pdo = new PDO($dsn, $tododb_user, $tododb_password, $options);
                    // 测试连接是否成功
                    $pdo->query("SELECT 1");

                    // 如果是强制重新安装，先删除已存在的表
                    if (isset($_GET['force']) && $_GET['force'] == '1') {
                        try {
                            // 获取所有表名
                            $stmt = $pdo->prepare("SHOW TABLES LIKE '" . $table_prefix . "%'");
                            $stmt->execute();
                            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

                            if (!empty($tables)) {
                                // 禁用外键检查
                                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

                                // 删除所有表
                                foreach ($tables as $table_name) {
                                    $pdo->exec("DROP TABLE IF EXISTS `$table_name`");
                                }

                                // 启用外键检查
                                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                            }
                        } catch (PDOException $e) {
                            error_log('删除已存在的表失败: ' . $e->getMessage());
                            // 继续安装，不中断流程
                        }
                    }

                    // 生成安全密钥
                    $auth_key = generate_random_key();
                    $secure_auth_key = generate_random_key();
                    $logged_in_key = generate_random_key();
                    $nonce_key = generate_random_key();
                    $auth_salt = generate_random_key();
                    $secure_auth_salt = generate_random_key();
                    $logged_in_salt = generate_random_key();
                    $nonce_salt = generate_random_key();

                    // 替换配置文件中的占位符
                    $config_content = str_replace(
                        [
                            '{{DB_NAME}}',
                            '{{DB_USER}}',
                            '{{DB_PASSWORD}}',
                            '{{DB_HOST}}',
                            '{{TABLE_PREFIX}}',
                            '{{AUTH_KEY}}',
                            '{{SECURE_AUTH_KEY}}',
                            '{{LOGGED_IN_KEY}}',
                            '{{NONCE_KEY}}',
                            '{{AUTH_SALT}}',
                            '{{SECURE_AUTH_SALT}}',
                            '{{LOGGED_IN_SALT}}',
                            '{{NONCE_SALT}}'
                        ],
                        [
                            $tododb_name,
                            $tododb_user,
                            $tododb_password,
                            $tododb_host,
                            $table_prefix,
                            $auth_key,
                            $secure_auth_key,
                            $logged_in_key,
                            $nonce_key,
                            $auth_salt,
                            $secure_auth_salt,
                            $logged_in_salt,
                            $nonce_salt
                        ],
                        $config_sample
                    );

                    // 写入配置文件
                    // 如果是强制重新安装，先检查是否需要删除已存在的配置文件
                    if (isset($_GET['force']) && $_GET['force'] == '1' && file_exists('config.php')) {
                        // 尝试删除已存在的配置文件
                        if (!unlink('config.php')) {
                            $error = '无法删除已存在的配置文件。请确保目录具有写入权限。';
                        }
                    }

                    if (empty($error) && file_put_contents('config.php', $config_content)) {
                        // 重定向到下一步，保持force参数
                        $force_param = (isset($_GET['force']) && $_GET['force'] == '1') ? 'force=1&' : '';
                        // 清空缓冲，避免已输出内容阻止重定向
                        if (ob_get_level()) {
                            ob_end_clean();
                        }
                        header('Location: install.php?' . $force_param . 'step=3');
                        exit;
                    } else if (empty($error)) {
                        $error = '无法写入配置文件。请确保目录具有写入权限。';
                    }
                } catch (PDOException $e) {
                    $error = '数据库连接失败：' . $e->getMessage();
                }
            }
        }
    }

    echo '<div class="step">
        <h2>步骤2：数据库配置</h2>
        <p>请核对您的数据库连接详细信息：</p>';

    if ($error) {
        show_error($error);
    }

    // 保持force参数
    $force_param = (isset($_GET['force']) && $_GET['force'] == '1') ? 'force=1&' : '';
    echo '<form method="post" action="install.php?' . $force_param . 'step=2">
            <div class="form-group">
                <label for="db_host">数据库主机</label>
                <input type="text" id="db_host" name="db_host" value="' . (isset($_POST['db_host']) ? htmlspecialchars($_POST['db_host']) : 'localhost') . '" required>
            </div>

            <div class="form-group">
                <label for="db_name">数据库名称</label>
                <input type="text" id="db_name" name="db_name" value="' . (isset($_POST['db_name']) ? htmlspecialchars($_POST['db_name'], ENT_QUOTES, 'UTF-8') : 'todocms') . '" required>
            </div>
            
            <div class="form-group">
                <label for="db_user">数据库用户名</label>
                <input type="text" id="db_user" name="db_user" value="' . (isset($_POST['db_user']) ? htmlspecialchars($_POST['db_user'], ENT_QUOTES, 'UTF-8') : 'root') . '" required>
            </div>
            
            <div class="form-group">
                <label for="db_password">数据库密码</label>
                <div style="position: relative;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" style="position: absolute; right: 0; top: 0; padding: 9px 12px; opacity: 0.2; cursor: pointer;" onmouseover="this.style.opacity=\'1\';" onmouseout="this.style.opacity=\'0.2\';" onclick="var p = this.nextElementSibling; p.type = p.type === \'password\' ? \'text\' : \'password\';">
                        <path d="M0 0h24v24H0z" fill="none" />
                        <path fill="currentColor" d="M2 5.27L3.28 4L20 20.72L18.73 22l-3.08-3.08c-1.15.38-2.37.58-3.65.58c-5 0-9.27-3.11-11-7.5c.69-1.76 1.79-3.31 3.19-4.54zM12 9a3 3 0 0 1 3 3a3 3 0 0 1-.17 1L11 9.17A3 3 0 0 1 12 9m0-4.5c5 0 9.27 3.11 11 7.5a11.8 11.8 0 0 1-4 5.19l-1.42-1.43A9.86 9.86 0 0 0 20.82 12A9.82 9.82 0 0 0 12 6.5c-1.09 0-2.16.18-3.16.5L7.3 5.47c1.44-.62 3.03-.97 4.7-.97M3.18 12A9.82 9.82 0 0 0 12 17.5c.69 0 1.37-.07 2-.21L11.72 15A3.064 3.064 0 0 1 9 12.28L5.6 8.87c-.99.85-1.82 1.91-2.42 3.13" />
                    </svg>
                    <input type="password" id="db_password" name="db_password" value="' . (isset($_POST['db_password']) ? htmlspecialchars($_POST['db_password'], ENT_QUOTES, 'UTF-8') : '') . '" autocomplete="current-password">
                </div>
            </div>
            
            <div class="form-group">
                <label for="table_prefix">表前缀</label>
                <input type="text" id="table_prefix" name="table_prefix" value="' . (isset($_POST['table_prefix']) ? htmlspecialchars($_POST['table_prefix']) : 'todo_') . '" required>
            </div>';
    // 如果是强制重新安装，显示相关信息
    if (isset($_GET['force']) && $_GET['force'] == '1') {
        echo '<p style="color: red;"><strong>注意：</strong> 您正在进行强制重新安装。如果数据库中已存在表，它们将被删除并重新创建。</p>';
    }
    echo '<div class="form-group">
                <button type="submit" id="install-submit">继 续</button>
            </div>
        </form>
        <script type="text/javascript">
        (function(){
            var btn = document.getElementById("install-submit");
            if(!btn){return;}
            var form = btn.closest("form");
            if(!form){return;}
            var submitted = false;
            form.addEventListener("submit", function(e){
                if(submitted){e.preventDefault();return;}
                submitted = true;
                btn.disabled = true;
                btn.innerText = "正在初始化数据表，请勿离开...";
            });
        })();
        </script>
    </div>';
}

// 安装步骤3：创建数据库表
function install_step3()
{
    $error = '';
    $success = false;

    // 加载配置文件
    if (file_exists('config.php')) {
        require_once 'config.php';

        // 确保数据库连接已初始化
        global $tododb;
        if (!$tododb || !($tododb instanceof CMS_DB)) {
            // 重新初始化数据库连接，使用CMS_DB类与create_db.php保持一致
            $tododb = new CMS_DB();
        }

        // 检查数据库连接是否成功
        if (!$tododb->is_connected()) {
            $error = '数据库连接失败：' . $tododb->last_error();
        }

        // 如果连接成功，继续执行
        if (empty($error)) {
            // 如果是强制重新安装，先尝试删除已存在的表
            if (isset($_GET['force']) && $_GET['force'] == '1') {
                try {
                    // 特别处理system_config表，确保删除可能存在结构冲突的表
                    $tododb->query("DROP TABLE IF EXISTS " . TABLE_PREFIX . "system_config");

                    // 获取所有表名
                    $tables = $tododb->get_results("SHOW TABLES LIKE '" . TABLE_PREFIX . "%'");

                    if (!empty($tables)) {
                        // 禁用外键检查
                        $tododb->query("SET FOREIGN_KEY_CHECKS = 0");

                        // 删除所有表
                        foreach ($tables as $table) {
                            $table_name = reset($table); // 获取表名
                            $tododb->query("DROP TABLE IF EXISTS `$table_name`");
                        }

                        // 启用外键检查
                        $tododb->query("SET FOREIGN_KEY_CHECKS = 1");
                    }
                } catch (Exception $e) {
                    error_log('删除已存在的表失败: ' . $e->getMessage());
                    // 继续安装，不中断流程
                }
            }

            // 检查表是否已存在（可能在步骤2中已创建）
            $tables_exist = false;
            try {
                $result = $tododb->get_var("SHOW TABLES LIKE '" . TABLE_PREFIX . "users'");
                $tables_exist = !empty($result);
            } catch (Exception $e) {
                error_log('步骤3：检查表存在性失败: ' . $e->getMessage());
            }

            if (!$tables_exist) {
                // 创建数据库表
                if (create_database_tables()) {
                    $success = true;
                } else {
                    $error = '创建数据库表失败。';
                }
            } else {
                $success = true;
            }

            // 插入默认数据（无论表是否新创建都需要检查）
            if ($success) {
                if (insert_default_data()) {
                    error_log('步骤3：默认数据插入成功');
                } else {
                    error_log('步骤3：插入默认数据失败');
                    $error = '插入默认数据失败。';
                }
            }
        }
    } else {
        $error = '配置文件不存在。请返回上一步。';
    }

    echo '<div class="step">
        <h2>步骤3：创建数据库表</h2>';

    if ($error) {
        show_error($error);
        $force_param = (isset($_GET['force']) && $_GET['force'] == '1') ? 'force=1&' : '';
        echo '<p><a href="install.php?' . $force_param . 'step=2"><button>返回</button></a></p>';
    } elseif ($success) {
        show_success('数据库表创建成功！');
        // 保持force参数
        $force_param = (isset($_GET['force']) && $_GET['force'] == '1') ? 'force=1&' : '';
        echo '<p><a href="install.php?' . $force_param . 'step=4"><button>很好，继续</button></a></p>';
    }

    echo '</div>';
}

// 安装步骤4：创建管理员账户
function install_step4()
{
    global $tododb;

    $success = false;
    $error = '';

    // 确保数据库连接已初始化
    if (!$tododb || !($tododb instanceof CMS_DB)) {
        $tododb = new CMS_DB();
        if (!$tododb->is_connected()) {
            $error = '数据库连接失败，请检查配置。';
        }
    }

    // 如果表单已提交
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_admin'])) {

        // 获取表单数据
        $username = isset($_POST['username']) ? trim($_POST['username']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $site_title = isset($_POST['site_title']) ? trim($_POST['site_title']) : 'TodoCMS';
        $site_description = isset($_POST['site_description']) ? trim($_POST['site_description']) : '基于PHP的内容管理系统';

        // 验证输入
        if (empty($username) || empty($password) || empty($email) || empty($site_title)) {
            $error = '所有字段都是必填的。';
        } elseif ($password !== $confirm_password) {
            $error = '两次输入的密码不匹配。';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = '请输入有效的电子邮件地址。';
        } else {
            if (!create_database_tables()) {
                $error = '创建数据库表失败。';
            } else {
                // 如果是强制重新安装，先检查是否已存在管理员用户
                if (isset($_GET['force']) && $_GET['force'] == '1') {
                    try {
                        // 检查users表是否存在
                        $tables = $tododb->get_results("SHOW TABLES LIKE '" . TABLE_PREFIX . "users'");
                        if (!empty($tables)) {
                            // 删除现有的管理员用户
                            $admin_users = $tododb->get_results(
                                "SELECT ID FROM " . TABLE_PREFIX . "users u 
                                JOIN " . TABLE_PREFIX . "user_meta m ON u.ID = m.user_id 
                                WHERE m.meta_key = 'role' AND m.meta_value = 'administrator'"
                            );

                            if (!empty($admin_users)) {
                                foreach ($admin_users as $admin) {
                                    // 删除用户元数据
                                    $tododb->query("DELETE FROM " . TABLE_PREFIX . "user_meta WHERE user_id = ?", $admin->ID);
                                    // 删除用户
                                    $tododb->query("DELETE FROM " . TABLE_PREFIX . "users WHERE ID = ?", $admin->ID);
                                }
                            }
                        }
                    } catch (Exception $e) {
                        error_log('删除已存在的管理员用户失败: ' . $e->getMessage());
                        // 继续安装，不中断流程
                    }
                }
            }

            // 创建管理员账户（使用安全公开昵称，防止前端暴露真实登录名）
            $user_data = [
                'user_login' => $username,
                'user_pass' => password_hash($password, PASSWORD_DEFAULT),
                'user_nicename' => 'admin',
                'user_email' => $email,
                'user_registered' => function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s'),
                'user_status' => 0,
                'display_name' => '站长'
            ];

            // 开始事务
            $tododb->begin_transaction();
            error_log('开始创建管理员账户事务');

            try {
                // 插入用户
                $fields = implode(', ', array_keys($user_data));
                $placeholders = implode(', ', array_fill(0, count($user_data), '?'));

                $query = "INSERT INTO " . TABLE_PREFIX . "users ($fields) VALUES ($placeholders)";
                $result = $tododb->query($query, array_values($user_data));

                if ($result === false) {
                    throw new Exception('Failed to create admin user: ' . $tododb->last_error());
                }

                // 获取用户ID
                $user_id = $tododb->insert_id();
                if (!$user_id) {
                    throw new Exception('Failed to get last insert ID');
                }

                // 设置用户角色为管理员
                $meta_result = $tododb->query(
                    "INSERT INTO " . TABLE_PREFIX . "user_meta (user_id, meta_key, meta_value) VALUES (?, ?, ?)",
                    [$user_id, 'roles', serialize(['administrator'])]
                );

                // 添加管理员必要权限
                $admin_capabilities = array(
                    'access_admin' => true,
                    'manage_options' => true,
                    'edit_posts' => true,
                    'publish_posts' => true,
                    'moderate_comments' => true,
                    'manage_categories' => true,
                    'manage_users' => true,
                    'create_users' => true,
                    'delete_users' => true,
                    'list_users' => true,
                    'promote_users' => true,

                    'upload_files' => true,
                    'manage_plugins' => true,
                    'activate_plugins' => true,
                    'edit_plugins' => true,
                    'install_plugins' => true,
                    'update_plugins' => true,
                    'delete_plugins' => true,
                    'install_themes' => true,
                    'switch_themes' => true,
                    'edit_themes' => true,
                    'update_themes' => true,
                    'delete_themes' => true,
                    'edit_files' => true,
                    'import' => true,
                    'export' => true,
                    'manage_security' => true,
                    'manage_api' => true
                );

                $meta_result = $tododb->query(
                    "INSERT INTO " . TABLE_PREFIX . "user_meta (user_id, meta_key, meta_value) VALUES (?, ?, ?)",
                    [$user_id, 'capabilities', serialize($admin_capabilities)]
                );

                if ($meta_result === false) {
                    throw new Exception('Failed to set user role: ' . $tododb->last_error());
                }

                // 更新站点标题和描述
                update_option('site_title', $site_title);
                update_option('site_description', $site_description);
                update_option('admin_email', $email);

                // 设置站点URL
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
                $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
                $site_url = $protocol . $host;
                update_option('site_url', $site_url);

                // 设置默认后台登录文件名并重置更名计时器
                update_option('site_adminbasefile', 'todo-login');
                update_option('todo_admin_last_rotate_time', time());
                update_option('todo_auto_rotate_admin_entry', '1');

                // 初始化角色和权限
                $roles = [
                    'administrator' => [
                        'name' => '管理员',
                        'capabilities' => [
                            'access_admin' => true,
                            'manage_options' => true,
                            'edit_posts' => true,
                            'publish_posts' => true,
                            'moderate_comments' => true,
                            'manage_categories' => true,
                            'manage_links' => true,
                            'upload_files' => true,
                            'edit_others_posts' => true,
                            'edit_published_posts' => true,
                            'delete_others_posts' => true,
                            'delete_published_posts' => true,
                            'manage_users' => true,
                            'create_users' => true,
                            'delete_users' => true,
                            'promote_users' => true,
                            'edit_theme_options' => true,
                            'import' => true,
                            'export' => true,
                            'list_users' => true,
                            'manage_plugins' => true,
                            'activate_plugins' => true,
                            'edit_plugins' => true,
                            'install_plugins' => true,
                            'update_plugins' => true,
                            'delete_plugins' => true,
                            'install_themes' => true,
                            'update_themes' => true,
                            'edit_themes' => true,
                            'delete_themes' => true,
                            'update_core' => true,
                            'unfiltered_html' => true,
                        ]
                    ],
                    'editor' => [
                        'name' => '编辑',
                        'capabilities' => [
                            'access_admin' => true,
                            'edit_posts' => true,
                            'edit_others_posts' => true,
                            'edit_published_posts' => true,
                            'publish_posts' => true,
                            'delete_posts' => true,
                            'delete_others_posts' => true,
                            'delete_published_posts' => true,
                            'manage_categories' => true,
                            'manage_links' => true,
                            'moderate_comments' => true,
                            'upload_files' => true,
                            'unfiltered_html' => true,
                        ]
                    ],
                    'author' => [
                        'name' => '作者',
                        'capabilities' => [
                            'access_admin' => true,
                            'edit_posts' => true,
                            'edit_published_posts' => true,
                            'publish_posts' => true,
                            'delete_posts' => true,
                            'delete_published_posts' => true,
                            'upload_files' => true,
                        ]
                    ]
                ];

                // 保存角色配置
                update_option('cms_roles', $roles);

                // 提交事务
                $tododb->commit();

                // 设置数据库版本和安装标记（只有在管理员账户创建成功后才设置）
                update_option('db_version', '1.0.0');
                update_option('installed', '1');

                // 安装完成彻底全清缓存（包含内存对象缓存、编译模板、文件缓存与 OPcache）
                todo_install_purge_all_caches();

                $success = true;

                // 生成随机文件名并重命名install.php
                // 如果是强制重新安装，不重命名install.php文件
                if (!isset($_GET['force']) || $_GET['force'] != '1') {
                    $random_suffix = substr(md5(time()), 0, 17);
                    $new_filename = 'install_' . $random_suffix . '.php';
                    @rename(__FILE__, dirname(__FILE__) . '/' . $new_filename);
                }
            } catch (Exception $e) {
                // 回滚事务
                $tododb->rollback();
                error_log('事务回滚: ' . $e->getMessage());
                $error = '创建管理员账户失败：' . $e->getMessage();
            }
        }
    }

    echo '<div class="step">
        <h2>步骤4：创建管理员账户</h2>';

    if ($success) {
        show_success('安装成功！您的CMS已准备就绪。');
        echo '<p><a href="' . todo_url() . '/admin/todo-login.php"><button>进入管理后台</button></a></p>';
        echo '<p style="font-size: 14px;line-height: normal;">管理后台地址：域名/admin/todo-login.php（入口页面会随机更换名称，请在根目录/admin/中查看）</p>';
    } else {
        if ($error) {
            show_error($error);
        }

        // 保持force参数
        $force_param = (isset($_GET['force']) && $_GET['force'] == '1') ? 'force=1&' : '';
        echo '<form method="post" action="install.php?' . $force_param . 'step=4">
                <input type="hidden" name="submit_admin" value="1">
                <div class="form-group">
                    <label for="username">用户名</label>
                    <input type="text" id="username" name="username" value="' . (isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '') . '" required autocomplete="username">
                    <p class="description">不要用 admin，请取一个你自己好记但别人不认识的名字</p>
                </div>
                
                <div class="form-group">
                    <label for="password">密码</label>
                    <div style="position: relative;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" style="position: absolute; right: 0; top: 0; padding: 9px 12px; opacity: 0.2; cursor: pointer;" onmouseover="this.style.opacity=\'1\';" onmouseout="this.style.opacity=\'0.2\';" onclick="var p = this.nextElementSibling; p.type = p.type === \'password\' ? \'text\' : \'password\';">
                            <path d="M0 0h24v24H0z" fill="none" />
                            <path fill="currentColor" d="M2 5.27L3.28 4L20 20.72L18.73 22l-3.08-3.08c-1.15.38-2.37.58-3.65.58c-5 0-9.27-3.11-11-7.5c.69-1.76 1.79-3.31 3.19-4.54zM12 9a3 3 0 0 1 3 3a3 3 0 0 1-.17 1L11 9.17A3 3 0 0 1 12 9m0-4.5c5 0 9.27 3.11 11 7.5a11.8 11.8 0 0 1-4 5.19l-1.42-1.43A9.86 9.86 0 0 0 20.82 12A9.82 9.82 0 0 0 12 6.5c-1.09 0-2.16.18-3.16.5L7.3 5.47c1.44-.62 3.03-.97 4.7-.97M3.18 12A9.82 9.82 0 0 0 12 17.5c.69 0 1.37-.07 2-.21L11.72 15A3.064 3.064 0 0 1 9 12.28L5.6 8.87c-.99.85-1.82 1.91-2.42 3.13" />
                        </svg>
                        <input type="password" id="password" name="password" value="' . (isset($_POST['password']) ? htmlspecialchars($_POST['password']) : '') . '" required autocomplete="new-password">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">确认密码</label>
                    <input type="password" id="confirm_password" name="confirm_password" value="' . (isset($_POST['confirm_password']) ? htmlspecialchars($_POST['confirm_password']) : '') . '" required autocomplete="new-password">
                </div>
                
                <div class="form-group">
                    <label for="email">电子邮件</label>
                    <input type="email" id="email" name="email" value="' . (isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '') . '" required>
                    <p class="description">请填写真实邮箱，后面用处大咧</p>
                </div>
                
                <div class="form-group">
                    <label for="site_title">站点标题</label>
                    <input type="text" id="site_title" name="site_title" value="' . (isset($_POST['site_title']) ? htmlspecialchars($_POST['site_title']) : 'TodoCMS') . '" required>
                </div>
                
                <div class="form-group">
                    <label for="site_description">站点描述</label>
                    <input type="text" id="site_description" name="site_description" value="' . (isset($_POST['site_description']) ? htmlspecialchars($_POST['site_description']) : '基于PHP的内容管理系统') . '">
                </div>';
        // 如果是强制重新安装，显示相关信息
        if (isset($_GET['force']) && $_GET['force'] == '1') {
            echo '<p style="color: red;"><strong>注意：</strong> 您正在进行强制重新安装。如果数据库中已存在管理员用户，它们将被删除并重新创建。</p>';
        }
        echo '<div class="form-group">
                    <button type="submit">完 成</button>
                </div>
            </form>';
    }

    echo '</div>';
}

// 生成随机密钥
function generate_random_key()
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_ []{}<>~`+=,.;:/?|';
    $key = '';

    for ($i = 0; $i < 64; $i++) {
        $key .= substr($chars, rand(0, strlen($chars) - 1), 1);
    }

    return $key;
}

// 创建数据库表
function create_database_tables()
{
    global $tododb;

    // 加载所有schema文件 - 与create_db.php保持一致
    require_once __DIR__ . '/core/db/schema.php';
    require_once __DIR__ . '/core/db/model-schema.php';
    require_once __DIR__ . '/core/db/shop-schema.php';
    require_once __DIR__ . '/core/db/taxonomy-schema.php';
    require_once __DIR__ . '/core/db/member-schema.php';
    require_once __DIR__ . '/core/db/extend-schema.php';
    require_once __DIR__ . '/core/db/init-schema.php';

    // 确保taxonomy-schema.php中的函数可用
    if (!function_exists('create_taxonomy_tables')) {
        error_log('create_taxonomy_tables函数不存在，taxonomy-schema.php可能未正确加载');
        return false;
    }

    try {
        // 检查数据库连接
        if (!$tododb || !($tododb instanceof CMS_DB) || !$tododb->is_connected()) {
            error_log('数据库连接未初始化或连接失败');
            return false;
        }

        // 记录开始创建表
        error_log('开始创建数据库表...');

        // 确保没有活动的事务
        if (method_exists($tododb, 'ensure_no_transaction')) {
            $tododb->ensure_no_transaction();
        }

        // 注意：不在此处开始事务，由init_database函数负责事务处理

        // 使用init_database函数创建所有数据库表
        if (function_exists('init_database')) {
            try {
                if (!init_database()) {
                    error_log('初始化数据库失败');
                    if (method_exists($tododb, 'rollback')) {
                        $tododb->rollback();
                    }
                    return false;
                }
            } catch (Exception $e) {
                error_log('初始化数据库异常: ' . $e->getMessage());
                if (method_exists($tododb, 'rollback')) {
                    $tododb->rollback();
                }
                return false;
            }
        } else {
            // 如果init_database函数不存在，则使用create_core_tables函数
            if (function_exists('create_core_tables')) {
                if (!create_core_tables()) {
                    error_log('创建核心表失败');
                    if (method_exists($tododb, 'rollback')) {
                        $tododb->rollback();
                    }
                    return false;
                }
            } else {
                error_log('无法找到数据库初始化函数');
                return false;
            }

            // 调用其他表创建函数
            if (function_exists('create_model_tables')) {
                if (!create_model_tables()) {
                    error_log('创建数据模型表失败');
                    if (method_exists($tododb, 'rollback')) {
                        $tododb->rollback();
                    }
                    return false;
                }
            }

            if (function_exists('create_shop_tables')) {
                if (!create_shop_tables()) {
                    error_log('创建商城表失败');
                    if (method_exists($tododb, 'rollback')) {
                        $tododb->rollback();
                    }
                    return false;
                }
            }

            if (function_exists('create_member_tables')) {
                if (!create_member_tables()) {
                    error_log('创建会员系统表失败');
                    if (method_exists($tododb, 'rollback')) {
                        $tododb->rollback();
                    }
                    return false;
                }
            }

            // 创建用户数据清理存储过程
            if (function_exists('create_user_cleanup_procedures')) {
                if (!create_user_cleanup_procedures()) {
                    error_log('创建用户数据清理存储过程失败');
                    // 存储过程创建失败不影响主要安装流程
                }
            }

            if (function_exists('create_extend_tables')) {
                if (!create_extend_tables()) {
                    error_log('创建扩展功能表失败');
                    if (method_exists($tododb, 'rollback')) {
                        $tododb->rollback();
                    }
                    return false;
                }
            }
        }

        // 注意：不在此处提交事务，由init_database函数负责提交事务

        error_log('所有数据库表创建成功');
        return true;
    } catch (Exception $e) {
        error_log('创建数据库表失败: ' . $e->getMessage());
        // 注意：不在此处回滚事务，由init_database函数负责回滚事务
        return false;
    }
}

// 插入默认数据
function insert_default_data()
{
    global $tododb;

    try {
        // 检查数据库连接
        if (!$tododb || !($tododb instanceof CMS_DB) || !$tododb->is_connected()) {
            error_log('数据库连接未初始化或连接失败');
            return false;
        }

        error_log('开始插入默认数据...');

        // 避免在部分安装或旧环境下因缺失表导致 PDOException: 1146 Table doesn't exist
        if (!defined('ABSPATH')) {
            // 安装脚本通常定义了 ABSPATH；如果未定义，补充定义以便加载架构文件
            define('ABSPATH', __DIR__ . '/');
        }
        // 加载架构定义文件
        if (!function_exists('create_taxonomy_tables') || !function_exists('create_term_meta_tables')) {
            @require_once ABSPATH . 'core/db/taxonomy-schema.php';
        }
        if (!function_exists('create_model_tables')) {
            @require_once ABSPATH . 'core/db/model-schema.php';
        }
        // 分类与分类法基础表
        $needTaxonomy = (
            !$tododb->table_exists('terms') ||
            !$tododb->table_exists('term_taxonomy') ||
            !$tododb->table_exists('term_relationships')
        );
        if ($needTaxonomy && function_exists('create_taxonomy_tables')) {
            create_taxonomy_tables();
            if (function_exists('create_term_meta_tables')) {
                create_term_meta_tables();
            }
        }
        // 栏目-模型关联表
        if (!$tododb->table_exists('term_models') && function_exists('create_model_tables')) {
            // create_model_tables 幂等创建相关表，确保 term_models 存在
            create_model_tables();
        }

        // 开始事务
        if (method_exists($tododb, 'begin_transaction')) {
            if (!$tododb->begin_transaction()) {
                error_log('无法开始事务');
                return false;
            }
        }

        // 插入默认选项 - 使用新的配置系统
        $default_options = [
            'default_category' => '1',
            'posts_per_page' => '10',
            'date_format' => 'Y-m-d',
            'time_format' => 'H:i',
            'site_charset' => 'UTF-8',
            'comment_moderation' => '0',
            'cms_version' => TODO_VERSION,
            'timezone' => 'Asia/Shanghai',
            'timezone_string' => 'Asia/Shanghai',
            'points_usable_categories' => '',
            'todo_admin_last_rotate_time' => time(),
            'todo_auto_rotate_admin_entry' => '1'
        ];

        foreach ($default_options as $option_name => $option_value) {
            error_log('正在处理选项: ' . $option_name . ' = ' . $option_value);
            $result = update_option($option_name, $option_value);

            if ($result === false) {
                error_log('处理选项 ' . $option_name . ' 失败，数据库错误: ' . $tododb->last_error());
                return false;
            } else {
                error_log('选项 ' . $option_name . ' 处理成功');
            }
        }

        // 初始化语言注册中心配置文件 core/config/languages.php 并同步数据库中的语言信息
        $default_language_config = array(
            'regex' => '/^[a-z]{2}(?:-[A-Z]{2})?$/',
            'routing' => array(
                'mode' => 'path_prefix',
            ),
            'fallback_locale' => 'zh-CN',
            'whitelist' => array(
                0 => 'zh-CN',
                1 => 'en-US',
            ),
            'languages' => array(
                0 => array(
                    'mark' => 'zh',
                    'locale' => 'zh-CN',
                    'title' => '简体中文',
                    'is_default' => true,
                    'domain' => NULL,
                    'path_prefix' => '',
                    'enabled' => true,
                    'sort_order' => 1,
                    'flag' => 'cn',
                    'currency_code' => 'CNY',
                    'currency_exchange_rate' => 1.0,
                    'currency_status' => 1,
                    'is_default_currency' => 1,
                    'site_ids' => array(
                        0 => 1,
                    ),
                    'default_site_id' => 1,
                ),
                1 => array(
                    'mark' => 'en',
                    'locale' => 'en-US',
                    'title' => 'English',
                    'is_default' => false,
                    'domain' => NULL,
                    'path_prefix' => 'en',
                    'enabled' => true,
                    'sort_order' => 2,
                    'flag' => 'us',
                    'is_default_currency' => 0,
                    'currency_code' => 'USD',
                    'currency_exchange_rate' => 0.15,
                    'currency_status' => 1,
                    'site_ids' => array(
                        0 => 1,
                    ),
                    'default_site_id' => 1,
                ),
            ),
            'domain_locale_map' => array(),
        );

        // 确保语言配置文件目录存在并写入语言配置
        $lang_config_dir = ABSPATH . 'core/config';
        if (!is_dir($lang_config_dir)) {
            @mkdir($lang_config_dir, 0755, true);
        }
        if (function_exists('todo_write_languages_config')) {
            todo_write_languages_config($default_language_config, ABSPATH . 'core/config/languages.php');
        } else {
            $export_str = var_export($default_language_config, true);
            $php_content = "<?php\n/**\n * TodoCMS 语言注册中心\n * 自动生成于 " . date('c') . "\n */\nreturn " . $export_str . ";";
            @file_put_contents(ABSPATH . 'core/config/languages.php', $php_content);
        }

        // 同步语言配置到数据库 languages 表
        if (function_exists('todo_update_language_in_db')) {
            foreach ($default_language_config['languages'] as $lang_item) {
                todo_update_language_in_db($lang_item['locale'], $lang_item);
            }
        }

        // 创建所有数据模型对应的默认分类
        $default_categories = [
            ['name' => '文章分类', 'slug' => 'articles', 'model_id' => 1, 'description' => '文章模型默认分类'],
            ['name' => '产品分类', 'slug' => 'products', 'model_id' => 2, 'description' => '产品模型默认分类'],
            ['name' => '图集分类', 'slug' => 'images', 'model_id' => 3, 'description' => '图集模型默认分类'],
            ['name' => '下载分类', 'slug' => 'downloads', 'model_id' => 4, 'description' => '下载模型默认分类'],
            ['name' => '视频分类', 'slug' => 'media', 'model_id' => 5, 'description' => '视频模型默认分类'],
            ['name' => '单页分类', 'slug' => 'single', 'model_id' => 6, 'description' => '单页模型默认分类'],
            ['name' => '专题分类', 'slug' => 'special', 'model_id' => 7, 'description' => '专题模型默认分类'],
            ['name' => '留言分类', 'slug' => 'guestbook', 'model_id' => 8, 'description' => '留言模型默认分类'],
            ['name' => '招聘分类', 'slug' => 'recruit', 'model_id' => 9, 'description' => '招聘模型默认分类']
        ];

        foreach ($default_categories as $category) {
            // 检查分类是否已存在
            $check_category = $tododb->query(
                "SELECT term_id FROM " . TABLE_PREFIX . "terms WHERE slug = ?",
                [$category['slug']]
            );

            if ($check_category && count($check_category) > 0) {
                // 分类已存在，使用现有的term_id
                $category_term_id = $check_category[0]['term_id'];

                // 更新分类名称
                $tododb->query(
                    "UPDATE " . TABLE_PREFIX . "terms SET name = ? WHERE term_id = ?",
                    [$category['name'], $category_term_id]
                );

                // 检查分类法是否存在
                $check_category_taxonomy = $tododb->query(
                    "SELECT term_taxonomy_id FROM " . TABLE_PREFIX . "term_taxonomy WHERE term_id = ? AND taxonomy = ?",
                    [$category_term_id, 'category']
                );

                if ($check_category_taxonomy && count($check_category_taxonomy) > 0) {
                    // 分类法已存在，更新它
                    $tododb->query(
                        "UPDATE " . TABLE_PREFIX . "term_taxonomy SET description = ?, parent = ? WHERE term_id = ? AND taxonomy = ?",
                        [$category['description'], 0, $category_term_id, 'category']
                    );
                } else {
                    // 分类法不存在，插入新分类法
                    $tododb->query(
                        "INSERT INTO " . TABLE_PREFIX . "term_taxonomy (term_id, taxonomy, description, parent, count) VALUES (?, ?, ?, ?, ?)",
                        [$category_term_id, 'category', $category['description'], 0, 0]
                    );
                }
            } else {
                // 分类不存在，插入新分类
                $category_result = $tododb->query(
                    "INSERT INTO " . TABLE_PREFIX . "terms (name, slug, term_group) VALUES (?, ?, ?)",
                    [$category['name'], $category['slug'], 0]
                );

                if ($category_result !== false) {
                    $category_term_id = $tododb->insert_id();

                    // 插入分类法
                    $tododb->query(
                        "INSERT INTO " . TABLE_PREFIX . "term_taxonomy (term_id, taxonomy, description, parent, count) VALUES (?, ?, ?, ?, ?)",
                        [$category_term_id, 'category', $category['description'], 0, 0]
                    );
                } else {
                    error_log('插入分类 ' . $category['name'] . ' 失败: ' . $tododb->last_error());
                    continue;
                }
            }

            // 为分类绑定对应的数据模型
            if ($category_term_id > 0) {
                // 检查是否已存在模型绑定
                $existing_category_binding = $tododb->query(
                    "SELECT term_id FROM " . TABLE_PREFIX . "term_models WHERE term_id = ? AND model_id = ?",
                    [$category_term_id, $category['model_id']]
                );

                if (!$existing_category_binding || count($existing_category_binding) == 0) {
                    // 绑定对应的数据模型
                    $model_binding_result = $tododb->query(
                        "INSERT INTO " . TABLE_PREFIX . "term_models (term_id, model_id) VALUES (?, ?)",
                        [$category_term_id, $category['model_id']]
                    );

                    if ($model_binding_result === false) {
                        error_log('绑定分类 ' . $category['name'] . ' 到模型 ' . $category['model_id'] . ' 失败: ' . $tododb->last_error());
                    }
                }
            }
        }

        // 检查Hello World文章是否已存在
        $check_post = $tododb->query(
            "SELECT ID FROM " . TABLE_PREFIX . "posts WHERE post_name = ?",
            ['hello-world']
        );

        $now = date('Y-m-d H:i:s');
        $gmt_now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $default_featured_image = json_encode([
            [
                'id' => '',
                'url' => '/content/themes/default/pc/screenshot.png',
                'title' => 'TodoCMS',
                'index' => 0
            ]
        ], JSON_UNESCAPED_SLASHES);
        $default_content = '&lt;p&gt;欢迎使用TodoCMS！这是您的第一篇文章。您可以编辑或删除它，然后开始创建您的内容！&lt;/p&gt;&lt;p&gt;&lt;img src=&quot;/content/themes/default/pc/screenshot.png&quot; width=&quot;50%&quot; alt=&quot;TodoCMS Screenshot&quot;/&gt;&lt;/p&gt;';

        if ($check_post && count($check_post) > 0) {
            // 文章已存在，更新它
            $post_id = $check_post[0]['ID'];
            $result = $tododb->query(
                "UPDATE " . TABLE_PREFIX . "posts SET post_content = ?, post_title = ?, featured_image = ?, post_modified = ?, post_modified_gmt = ?, seo_title = ?, seo_keywords = ?, seo_description = ? WHERE ID = ?",
                [$default_content, 'Hello World', $default_featured_image, $now, $gmt_now, 'Hello World - TodoCMS欢迎页', 'TodoCMS,欢迎,第一篇文章,内容管理系统', '欢迎使用TodoCMS！这是您的第一篇文章。', $post_id]
            );
        } else {
            // 文章不存在，插入新文章
            $result = $tododb->query(
                "INSERT INTO " . TABLE_PREFIX . "posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, featured_image, post_status, comment_status, post_name, post_modified, post_modified_gmt, model_id, post_type, seo_title, seo_keywords, seo_description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [1, $now, $gmt_now, $default_content, 'Hello World', '', $default_featured_image, 'publish', 'open', 'hello-world', $now, $gmt_now, 1, 'article', 'Hello World - TodoCMS欢迎页', 'TodoCMS,欢迎,第一篇文章,内容管理系统', '欢迎使用TodoCMS！这是您的第一篇文章。']
            );
        }

        if ($result === false) {
            error_log('处理Hello World文章失败: ' . $tododb->last_error());
            return false;
        }

        // 获取文章ID（用于关联分类）
        if (!isset($post_id)) {
            $post_id = $tododb->insert_id();
        }

        // 为默认文章关联"文章分类"分类
        if ($post_id > 0) {
            // 获取文章分类的term_id
            $article_category = $tododb->query(
                "SELECT term_id FROM " . TABLE_PREFIX . "terms WHERE slug = ?",
                ['articles']
            );

            if ($article_category && count($article_category) > 0) {
                $article_term_id = $article_category[0]['term_id'];

                // 获取"文章分类"的term_taxonomy_id
                $term_taxonomy = $tododb->query(
                    "SELECT term_taxonomy_id FROM " . TABLE_PREFIX . "term_taxonomy WHERE term_id = ? AND taxonomy = ?",
                    [$article_term_id, 'category']
                );

                if ($term_taxonomy && count($term_taxonomy) > 0) {
                    $term_taxonomy_id = $term_taxonomy[0]['term_taxonomy_id'];

                    // 检查是否已存在关联
                    $existing_relation = $tododb->query(
                        "SELECT object_id FROM " . TABLE_PREFIX . "term_relationships WHERE object_id = ? AND term_taxonomy_id = ?",
                        [$post_id, $term_taxonomy_id]
                    );

                    if (!$existing_relation || count($existing_relation) == 0) {
                        // 创建文章与分类的关联
                        $relation_result = $tododb->query(
                            "INSERT INTO " . TABLE_PREFIX . "term_relationships (object_id, term_taxonomy_id, term_order) VALUES (?, ?, ?)",
                            [$post_id, $term_taxonomy_id, 0]
                        );

                        if ($relation_result !== false) {
                            // 更新分类的文章计数
                            $tododb->query(
                                "UPDATE " . TABLE_PREFIX . "term_taxonomy SET count = count + 1 WHERE term_taxonomy_id = ?",
                                [$term_taxonomy_id]
                            );
                        } else {
                            error_log('关联默认文章到文章分类失败: ' . $tododb->last_error());
                            // 不返回false，因为这不是致命错误
                        }
                    }
                }
            }
        }

        // 提交事务
        if (method_exists($tododb, 'commit')) {
            if (!$tododb->commit()) {
                error_log('提交事务失败');
                return false;
            }
        }

        // 调用扩展模块的默认数据插入
        if (function_exists('insert_extend_default_data')) {
            if (!insert_extend_default_data()) {
                error_log('插入扩展默认数据失败');
                return false;
            }
        }

        error_log('所有默认数据插入成功');
        return true;
    } catch (Exception $e) {
        error_log('插入默认数据时出错: ' . $e->getMessage());

        // 回滚事务
        if (method_exists($tododb, 'rollback')) {
            $tododb->rollback();
        }

        return false;
    }
}

/**
 * 彻底清除系统与物理文件缓存（确保二次重装/环境安装绝对纯净）
 */
function todo_install_purge_all_caches()
{
    // 1. 调用框架统一缓存管理 API
    if (class_exists('Todo_Unified_Cache_Manager') && method_exists('Todo_Unified_Cache_Manager', 'clear_all_caches')) {
        try {
            Todo_Unified_Cache_Manager::clear_all_caches();
        } catch (Throwable $e) {
            error_log('Todo_Unified_Cache_Manager 清理失败: ' . $e->getMessage());
        }
    }

    if (function_exists('todo_clear_all_caches')) {
        try {
            todo_clear_all_caches();
        } catch (Throwable $e) {
            error_log('todo_clear_all_caches 清理失败: ' . $e->getMessage());
        }
    }

    // 2. 物理彻底清空 content/cache 目录下的文件
    $cache_dir = defined('ABSPATH') ? ABSPATH . 'content/cache' : __DIR__ . '/content/cache';
    if (is_dir($cache_dir)) {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }
        } catch (Throwable $e) {
            error_log('物理清理 content/cache 异常: ' . $e->getMessage());
        }
    }

    // 3. 重置 PHP OPcache 字节码缓存
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
}

// 输出页面（启用输出缓冲以支持 PRG 重定向）
if (!ob_get_level()) {
    ob_start();
}
install_header();

switch ($step) {
    case 1:
        install_step1();
        break;
    case 2:
        install_step2();
        break;
    case 3:
        install_step3();
        break;
    case 4:
        install_step4();
        break;
    default:
        install_step1();
        break;
}

install_footer();

// 输出缓冲到浏览器（非重定向情况下）
if (ob_get_level()) {
    ob_end_flush();
}
