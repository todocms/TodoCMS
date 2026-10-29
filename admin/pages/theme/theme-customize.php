<?php
/**
 * 主题自定义编辑页面
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    die('Direct access denied');
}

// 检查用户权限
if (!current_user_can('edit_themes') && !current_user_can('customize') && !current_user_can('edit_theme_options') && !current_user_can('manage_themes') && !current_user_can('manage_options')) {
    todo_die('您没有足够的权限访问此页面。');
}

/**
 * 检查路径是否为绝对路径
 */
if (!function_exists('path_is_absolute')) {
    function path_is_absolute($path)
    {
        return (substr($path, 0, 1) === '/' || preg_match('/^[a-zA-Z]:\\/', $path));
    }
}

// 获取当前主题
$current_theme = get_current_theme();
$theme_path = $current_theme->get_theme_dir();

// 确保使用绝对路径
if (!path_is_absolute($theme_path)) {
    $theme_path = ABSPATH . $theme_path;
}

// 检查路径是否存在
if (!is_dir($theme_path)) {
    die('主题目录不存在');
}

// 处理AJAX请求：获取验证码
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_captcha') {
    header('Content-Type: application/json');
    $client_ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');

    // 1. 检查是否因验证码尝试失败被锁定
    if (function_exists('todo_check_captcha_lockout')) {
        $lockout = todo_check_captcha_lockout($client_ip);
        if ($lockout) {
            echo json_encode(['success' => false, 'message' => "操作过于频繁，请在 {$lockout['wait_minutes']} 分钟后再试"]);
            exit;
        }
    }

    // 2. 检查是否刷新过于频繁（独立限制，不计入验证失败次数）
    if (function_exists('todo_check_captcha_refresh_lockout')) {
        $refresh_lockout = todo_check_captcha_refresh_lockout($client_ip);
        if ($refresh_lockout) {
            echo json_encode(['success' => false, 'message' => $refresh_lockout['message']]);
            exit;
        }
    }

    // 注册刷新记录
    if (function_exists('todo_register_captcha_attempt')) {
        todo_register_captcha_attempt($client_ip, true, 'refresh');
    }

    require_once CORE_DIR . '/classes/class-sliding-captcha.php';
    $captcha = new Todo_Sliding_Captcha();
    echo json_encode($captcha->create());
    exit;
}

// 处理AJAX请求：验证验证码
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_captcha') {
    header('Content-Type: application/json');
    $client_ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');

    // 检查是否被锁定
    if (function_exists('todo_check_captcha_lockout')) {
        $lockout = todo_check_captcha_lockout($client_ip);
        if ($lockout) {
            echo json_encode(['success' => false, 'message' => "操作过于频繁，请在 {$lockout['wait_minutes']} 分钟后再试"]);
            exit;
        }
    }

    require_once CORE_DIR . '/classes/class-sliding-captcha.php';
    $captcha = new Todo_Sliding_Captcha();
    $x = isset($_POST['x']) ? floatval($_POST['x']) : 0;

    if ($captcha->verify($x)) {
        $_SESSION['theme_edit_captcha_verified'] = true;
        // 验证成功，重置尝试次数
        if (function_exists('todo_register_captcha_attempt')) {
            todo_register_captcha_attempt($client_ip, true, 'verify');
        }
        echo json_encode(['success' => true]);
    } else {
        $_SESSION['theme_edit_captcha_verified'] = false;
        // 验证失败，记录尝试
        if (function_exists('todo_register_captcha_attempt')) {
            todo_register_captcha_attempt($client_ip, false, 'verify');
        }
        echo json_encode(['success' => false, 'message' => '验证失败']);
    }
    exit;
}

// 获取动态密保问题
$security_question = get_option('theme_security_question');
$security_answer = get_option('theme_security_answer');
$has_security_setup = !empty($security_question) && !empty($security_answer);

// 处理安全验证
$security_verified = false;
$security_error = '';

// 处理安全设置提交
if (isset($_POST['security_setup'])) {
    $new_question = sanitize_text_field($_POST['setup_question']);
    $new_answer = trim($_POST['setup_answer']);

    if (empty($new_question) || empty($new_answer)) {
        $security_error = '问题和答案不能为空。';
    } else {
        if (update_option('theme_security_question', $new_question) && update_option('theme_security_answer', $new_answer)) {
            // 设置成功后重定向，避免刷新重复提交，并确保重新加载选项
            header('Location: index.php?page=theme-customize&setup_success=1');
            exit;
        } else {
            $security_error = '保存设置失败，请检查数据库权限。';
        }
    }
}

// 处理安全验证提交
if (isset($_POST['security_verify'])) {
    // 检查人机验证
    if (!isset($_SESSION['theme_edit_captcha_verified']) || !$_SESSION['theme_edit_captcha_verified']) {
        $security_error = '请先完成人机验证。';
    } else {
        $user_answer = trim($_POST['security_answer']);
        if (strtolower($user_answer) === strtolower($security_answer)) {
            $security_verified = true;
            // 设置会话标记，有效期30分钟
            $_SESSION['theme_edit_verified'] = time() + 1800;
            $_SESSION['theme_edit_user'] = get_current_user_id();
            // 验证通过后清除验证码状态
            unset($_SESSION['theme_edit_captcha_verified']);
            
            // 使用 PRG 模式：重定向到当前页面，避免刷新重复提交
            $redirect_url = 'index.php?page=theme-customize';
            if (isset($_GET['file'])) {
                $redirect_url .= '&file=' . urlencode(sanitize_text_field($_GET['file']));
            }
            header('Location: ' . $redirect_url);
            exit;
        } else {
            $security_error = '答案不正确，请重试。';
            // 验证失败也清除验证码状态，要求重新滑动
            unset($_SESSION['theme_edit_captcha_verified']);
        }
    }
}

// 检查会话验证状态
if (
    isset($_SESSION['theme_edit_verified']) &&
    $_SESSION['theme_edit_verified'] > time() &&
    $_SESSION['theme_edit_user'] == get_current_user_id()
) {
    $security_verified = true;
}

// 处理文件编辑
$edit_file = '';
$file_content = '';
$save_message = '';
$save_error = '';

// 处理重定向后的成功消息 (PRG 模式)
if (isset($_GET['setup_success'])) {
    $save_message = '安全密保问题已设置成功。';
}
if (isset($_GET['save_success'])) {
    $save_message = '文件已成功保存。';
}
if (isset($_GET['clean_success'])) {
    $save_message = '已成功清理 ' . intval($_GET['clean_success']) . ' 个备份文件。';
}

if ($security_verified) {
    // 获取要编辑的文件
    $edit_file = isset($_GET['file']) ? sanitize_text_field($_GET['file']) : '';

    // 处理清理备份
    if (isset($_POST['clean_backups']) && $security_verified) {
        $backup_path = $theme_path . '/backups';
        $cleaned_count = 0;
        $clean_error = '';

        if (!file_exists($backup_path) || !is_dir($backup_path)) {
            $clean_error = '备份目录不存在：' . $backup_path;
        } elseif (!is_writable($backup_path)) {
            $clean_error = '备份目录无写入权限，无法删除文件：' . $backup_path;
        } else {
            $backup_files = glob($backup_path . '/*.bak') ?: array();
            $failed_files = array();
            foreach ($backup_files as $backup_file) {
                if (!is_file($backup_file)) {
                    continue;
                }
                if (@unlink($backup_file)) {
                    $cleaned_count++;
                } else {
                    $failed_files[] = basename($backup_file);
                }
            }

            if ($cleaned_count > 0 && empty($failed_files)) {
                header('Location: index.php?page=theme-customize&clean_success=' . $cleaned_count . (isset($_GET['file']) ? '&file=' . urlencode(sanitize_text_field($_GET['file'])) : ''));
                exit;
            }

            if ($cleaned_count === 0 && empty($failed_files)) {
                $save_message = '没有找到需要清理的备份文件。';
            } elseif (!empty($failed_files)) {
                $clean_error = '部分备份文件未能删除（可能是权限问题）：' . implode('、', array_slice($failed_files, 0, 8)) . (count($failed_files) > 8 ? '...' : '');
                if ($cleaned_count > 0) {
                    $save_message = '已成功清理 ' . $cleaned_count . ' 个备份文件。';
                }
            }
        }
    }

    // 处理文件保存
    if (isset($_POST['save_file']) && isset($_POST['file_content'])) {
        $file_to_save = sanitize_text_field($_POST['file_name']);
        $content_to_save = $_POST['file_content']; // 不过滤内容

        // 验证文件路径安全性
        if (is_valid_theme_file($file_to_save, $theme_path)) {
            $full_path = $theme_path . '/' . $file_to_save;

            // 检查主题目录权限
            if (!is_writable($theme_path)) {
                $save_error = '主题目录没有写入权限，请检查目录权限设置。';
            } else {
                // 尝试创建备份（不强制要求成功）
                $backup_created = false;
                $backup_message = '';
                $backup_path = $theme_path . '/backups';

                if (file_exists($full_path)) {
                    // 确保备份目录存在
                    if (!file_exists($backup_path)) {
                        @mkdir($backup_path, 0775, true);
                    }

                    // 尝试创建备份
                    if (file_exists($backup_path) && is_writable($backup_path)) {
                        $backup_file = $backup_path . '/' . basename($file_to_save) . '.' . date('Y-m-d-H-i-s') . '.bak';
                        if (@copy($full_path, $backup_file)) {
                            $backup_created = true;
                            $backup_message = ' 备份文件：' . basename($backup_file);
                        }
                    }
                }

                // 保存文件（无论备份是否成功）
                if (file_put_contents($full_path, $content_to_save) !== false) {
                    // 清除模板缓存
                    if (function_exists('clear_template_cache')) {
                        clear_template_cache();
                    }
                    
                    // 使用 PRG 模式：重定向到当前页面
                    header('Location: index.php?page=theme-customize&file=' . urlencode($file_to_save) . '&save_success=1');
                    exit;
                } else {
                    $save_error = '保存文件失败，请检查文件权限。';
                }
            }
        } else {
            $save_error = '无效的文件路径。';
        }
    }

    // 读取文件内容
    if ($edit_file && is_valid_theme_file($edit_file, $theme_path)) {
        $full_path = $theme_path . '/' . $edit_file;
        if (file_exists($full_path)) {
            $file_content = file_get_contents($full_path);
        }
    }
}

/**
 * 验证主题文件路径是否安全
 */
function is_valid_theme_file($file, $theme_path)
{
    // 防止目录遍历攻击
    if (strpos($file, '..') !== false || strpos($file, '/') === 0) {
        return false;
    }

    // 只允许编辑特定类型的文件
    $allowed_extensions = ['html', 'htm', 'css', 'js', 'json'];
    $file_extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    if (!in_array($file_extension, $allowed_extensions)) {
        return false;
    }

    // 确保文件在主题目录内
    $full_path = $theme_path . '/' . $file;
    $real_path = realpath(dirname($full_path));

    // 规范化路径，处理 . 和 ..
    $normalized_path = realpath(dirname($full_path)) . '/' . basename($full_path);
    return strpos($normalized_path, realpath($theme_path)) === 0;
}

/**
 * 获取主题文件列表
 */
function get_theme_files($theme_path)
{
    $files = [];
    $allowed_extensions = ['html', 'htm', 'css', 'js', 'json'];

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($theme_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $extension = strtolower($file->getExtension());

                if (in_array($extension, $allowed_extensions)) {
                    // 获取相对路径
                    $relative_path = $file->getPathname();

                    // 移除主题路径前缀
                    if (strpos($relative_path, $theme_path) === 0) {
                        $relative_path = substr($relative_path, strlen($theme_path));
                        // 移除开头的路径分隔符
                        $relative_path = ltrim($relative_path, '/\\');
                        // 统一使用正斜杠
                        $relative_path = str_replace('\\', '/', $relative_path);

                        $files[] = $relative_path;
                    }
                }
            }
        }
    } catch (Exception $e) {
        // 静默处理异常，返回空数组
        return [];
    }

    // 自定义排序：包含文件夹路径的文件排在前面
    usort($files, function ($a, $b) {
        $a_has_folder = strpos($a, '/') !== false;
        $b_has_folder = strpos($b, '/') !== false;

        // 如果一个有文件夹路径，一个没有，有文件夹路径的排前面
        if ($a_has_folder && !$b_has_folder) {
            return -1;
        } elseif (!$a_has_folder && $b_has_folder) {
            return 1;
        }

        // 都有或都没有文件夹路径时，按字母顺序排序
        return strcmp($a, $b);
    });

    return $files;
}

$theme_files = get_theme_files($theme_path);
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">当前默认主题：<?php echo esc_html($current_theme->get_name()); ?></h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=themes'); ?>" class="button button-secondary">返回主题管理</a>
        </div>
    </div>
    
    <?php if ($save_message): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html($save_message); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <?php if ($save_error || (isset($clean_error) && $clean_error)): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html($save_error ?: $clean_error); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <?php if (!$security_verified): ?>
        <!-- 安全验证表单 -->
        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">安全验证</h3>
            </div>
            <div class="admin-card-content">
                <?php if (!$has_security_setup): ?>
                    <div class="security-notice">
                        <strong>首次使用：</strong>请设置一个安全密保问题，用于后续进入主题编辑时的身份验证。
                    </div>

                    <?php if ($security_error): ?>
                        <div class="notice notice-error is-dismissible">
                            <p><?php echo esc_html($security_error); ?></p>
                            <button type="button" class="notice-dismiss">✕</button>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="">
                        <div class="form-group">
                            <label for="setup_question">设置问题：</label>
                            <input type="text" id="setup_question" name="setup_question" required class="form-control" placeholder="例如：我的生日是？">
                        </div>

                        <div class="form-group">
                            <label for="setup_answer">设置答案：</label>
                            <input type="text" id="setup_answer" name="setup_answer" required class="form-control" placeholder="请输入答案">
                        </div>

                        <div class="form-submit">
                            <button type="submit" name="security_setup" class="button button-primary">保存设置</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="security-notice">
                        <strong>警告：</strong>主题编辑功能可以直接修改网站文件，请确保您了解相关风险。<br>
                        为了确保安全，请回答以下问题：
                    </div>

                    <?php if ($security_error): ?>
                        <div class="notice notice-error is-dismissible">
                            <p><?php echo esc_html($security_error); ?></p>
                            <button type="button" class="notice-dismiss">✕</button>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="" id="security-verify-form">
                        <div class="form-group">
                            <label>问题：</label>
                            <p class="security-question"><?php echo esc_html($security_question); ?></p>
                        </div>

                        <div class="form-group">
                            <label for="security_answer">答案：</label>
                            <input type="text" id="security_answer" name="security_answer" required class="form-control">
                        </div>

                        <div class="form-submit">
                            <button type="button" id="verify-trigger-btn" class="button button-primary">验证</button>
                            <button type="submit" name="security_verify" id="real-submit-btn" style="display:none;"></button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- 人机验证弹窗 -->
        <div id="captcha-modal" class="todo-captcha-modal">
            <div class="captcha-box">
                <div class="captcha-header">
                    <h3 style="margin:0; font-size:16px; font-weight: normal;">安全验证</h3>
                    <button type="button" id="captcha-close-top" class="captcha-close-btn">✕</button>
                </div>
                <div class="captcha-img-box">
                    <img id="captcha-bg" style="width:100%; height:100%; display:block; position: relative;">
                    <img id="captcha-piece" style="position:absolute; top:0; left:0; height:50px;">
                </div>
                <div class="captcha-slider-box">
                    <div id="captcha-slider-bar"></div>
                    <div id="captcha-slider-btn">➝</div>
                    <div class="captcha-text">拖动滑块完成拼图</div>
                </div>
                <div class="captcha-footer">
                     <button type="button" id="captcha-close" class="captcha-btn">关闭</button>
                     <button type="button" id="captcha-refresh" class="captcha-btn">刷新</button>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- 主题编辑界面 -->
        <div class="theme-editor-container">
            <div class="admin-card file-browser m-0">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">主题文件</h3>
                </div>
                <div class="admin-card-content">
                    <div class="file-browser-content">
                        <ul class="file-list">
                            <?php foreach ($theme_files as $file): ?>
                                <li class="file-item">
                                    <a href="?page=theme-customize&file=<?php echo urlencode($file); ?>"
                                        class="file-link <?php echo $file === $edit_file ? 'active' : ''; ?>">
                                        <span class="file-icon"></span>
                                        <?php echo esc_html($file); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <div class="backup-management">
                            <h4>备份管理</h4>
                            <?php
                            $backup_path = $theme_path . '/backups';
                            $backup_count = 0;
                            if (file_exists($backup_path)) {
                                $backup_files = glob($backup_path . '/*.bak');
                                $backup_count = count($backup_files);
                            }
                            ?>
                            <p>当前备份文件数量: <?php echo $backup_count; ?></p>

                            <?php if ($backup_count > 0): ?>
                                <form method="post" action=""
                                    onsubmit="return TodoCMS.Utils.confirmAction(this, '确定要清理所有备份文件吗？')">
                                    <button type="submit" name="clean_backups" class="button button-secondary">清理所有备份</button>
                                </form>
                            <?php else: ?>
                                <p class="description">暂无备份文件</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="admin-card file-editor">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">编辑文件</h3>
                </div>

                <?php if ($edit_file): ?>
                    <div class="editor-toolbar">
                        <span class="file-info">正在编辑: <?php echo esc_html($edit_file); ?></span>
                        <span class="file-size">文件大小: <?php echo strlen($file_content); ?> 字节</span>
                    </div>

                    <form method="post" action="">
                        <input type="hidden" name="file_name" value="<?php echo esc_attr($edit_file); ?>">
                        <div class="admin-card-content">
                            <div class="code-editor-wrapper">
                                <div class="code-editor-gutter" aria-hidden="true"></div>
                                <textarea id="file_content" name="file_content" wrap="off" spellcheck="false" autocomplete="off" autocorrect="off" autocapitalize="off"
                                    class="code-editor"><?php echo esc_textarea($file_content); ?></textarea>
                            </div>
                        </div>
                        <div class="editor-actions">
                            <button type="submit" name="save_file" class="button button-primary">保存文件</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="admin-card-content">
                        <p>请从左侧选择要编辑的文件。</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
    .theme-editor-container {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
    }

    .file-browser {
        flex: 0 0 300px;
        max-height: 600px;
    }

    .mbody .file-browser {
        flex: 100%;
    }

    .file-editor {
        flex: 1;
    }

    .file-browser-content {
        max-height: 500px;
        overflow-y: auto;
    }

    .file-list {
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .file-item {
        margin: 0;
        padding: 0;
    }

    .file-link {
        display: flex;
        word-break: break-all;
        font-size: 12px;
        line-height: 14px;
        align-items: center;
        padding: 5px 12px 5px 5px;
        text-decoration: none;
        color: #666;
        border-left: 3px solid transparent;
        transition: all 0.2s ease;
    }

    .file-link:hover {
        background-color: #f5f5f5;
        color: #006dbf;
    }

    .file-link.active {
        color: #006dbf;
    }

    .file-icon {
        display: inline-block;
        width: 16px;
        height: 16px;
        margin-right: 5px;
        margin-top: 2px;
        background: url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTYiIGhlaWdodD0iMTYiIHZpZXdCb3g9IjAgMCAxNiAxNiIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggZD0iTTkgMUg0QzMuNDQ3NzIgMSAzIDEuNDQ3NzIgMyAyVjE0QzMgMTQuNTUyMyAzLjQ0NzcyIDE1IDQgMTVIMTJDMTIuNTUyMyAxNSAxMyAxNC41NTIzIDEzIDE0VjVMOSAxWiIgc3Ryb2tlPSJjdXJyZW50Q29sb3IiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIi8+CjxwYXRoIGQ9Ik05IDFWNUgxMyIgc3Ryb2tlPSJjdXJyZW50Q29sb3IiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIi8+Cjwvc3ZnPgo=') no-repeat center;
        background-size: contain;
        vertical-align: middle;
        flex-shrink: 0;
        opacity: 0.2;
    }

    .code-editor {
        width: 100%;
        height: 400px;
        font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
        font-size: 13px;
        line-height: 1.4;
        border: 1px solid #eceef1;
        padding: 10px;
        resize: vertical;
        background-color: #f9f9f9;
        tab-size: 4;
        overflow: auto;
    }

    .code-editor-wrapper {
        display: flex;
        width: 100%;
        height: 400px;
        border: 1px solid #eceef1;
        background-color: #f9f9f9;
    }

    .code-editor-wrapper .code-editor {
        flex: 1 1 auto;
        min-width: 0;
        height: 100%;
        border: 0;
        background-color: transparent;
        resize: none;
        padding-left: 12px;
    }

    .code-editor-gutter {
        flex: 0 0 48px;
        padding: 10px 8px 10px 10px;
        border-right: 1px solid #eceef1;
        color: #999;
        text-align: right;
        background-color: #f3f4f6;
        overflow: hidden;
        user-select: none;
        font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
        font-size: 13px;
        line-height: 1.4;
        white-space: pre;
    }

    .editor-toolbar {
        background-color: #006dbf;
        padding: 8px 15px;
        border-bottom: 1px solid #eceef1;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 12px;
        color: #fff;
    }

    .editor-actions {
        padding: 15px;
        background-color: #fff;
        border-top: 1px solid #eceef1;
    }

    .security-notice {
        background-color: #fff3cd;
        border: 1px solid #ffeaa7;
        color: #856404;
        padding: 15px;
        border-radius: 4px;
        margin-bottom: 20px;
    }

    .security-question {
        font-weight: 500;
        color: #0073aa;
        margin: 10px 0;
    }

    .backup-management {
        margin-top: 20px;
        padding-top: 15px;
        border-top: 1px solid #eceef1;
    }

    .backup-management h4 {
        margin: 0 0 10px 0;
        font-size: 14px;
        color: #333;
    }

    .backup-management p {
        margin: 5px 0;
        font-size: 12px;
        color: #666;
    }

    .backup-management .description {
        font-style: italic;
    }

    /* 自定义滚动条样式 */
    .file-list::-webkit-scrollbar {
        width: 6px;
    }

    .file-list::-webkit-scrollbar-track {
        background: #f1f1f1;
    }

    .file-list::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 3px;
    }

    .file-list::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }
</style>

<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        // 动态调整代码编辑器高度
        function adjustEditorHeight() {
            var fileBrowser = document.querySelector('.file-browser');
            var editor = document.getElementById('file_content');
            var wrapper = editor ? editor.closest('.code-editor-wrapper') : null;
            var gutter = wrapper ? wrapper.querySelector('.code-editor-gutter') : null;

            if (fileBrowser && editor) {
                var browserHeight = fileBrowser.offsetHeight;
                var editorToolbar = document.querySelector('.editor-toolbar');
                var toolbarHeight = editorToolbar ? editorToolbar.offsetHeight : 0;

                // 计算编辑器应该的高度（减去工具栏高度和一些边距）
                var newHeight = Math.max(300, browserHeight - toolbarHeight - 130);
                if (wrapper) {
                    wrapper.style.height = newHeight + 'px';
                }
                editor.style.height = wrapper ? '100%' : (newHeight + 'px');
                if (gutter) {
                    gutter.style.height = wrapper ? '100%' : (newHeight + 'px');
                }
            }
        }

        // 页面加载完成后调整高度
        setTimeout(adjustEditorHeight, 100);

        // 窗口大小改变时重新调整
        window.addEventListener('resize', function () {
            setTimeout(adjustEditorHeight, 100);
        });

        // 监听文件浏览器内容变化
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                if (mutation.type === 'childList') {
                    setTimeout(adjustEditorHeight, 100);
                }
            });
        });

        var fileBrowser = document.querySelector('.file-browser-content');
        if (fileBrowser) {
            observer.observe(fileBrowser, { childList: true, subtree: true });
        }

        // 代码编辑器增强
        var editor = document.getElementById('file_content');
        if (editor) {
            var wrapper = editor.closest('.code-editor-wrapper');
            var gutter = wrapper ? wrapper.querySelector('.code-editor-gutter') : null;

            var rafId = null;
            var renderLineNumbers = function () {
                if (!gutter) return;
                var lines = editor.value.split('\n').length || 1;
                var nums = new Array(lines);
                for (var i = 0; i < lines; i++) nums[i] = String(i + 1);
                gutter.textContent = nums.join('\n');
                gutter.scrollTop = editor.scrollTop;
            };
            var scheduleRender = function () {
                if (rafId) return;
                rafId = window.requestAnimationFrame(function () {
                    rafId = null;
                    renderLineNumbers();
                });
            };

            renderLineNumbers();

            editor.addEventListener('scroll', function () {
                if (!gutter) return;
                gutter.scrollTop = editor.scrollTop;
            });
            editor.addEventListener('input', scheduleRender);
            editor.addEventListener('change', scheduleRender);

            // 添加快捷键支持
            editor.addEventListener('keydown', function (e) {
                // Ctrl+S 保存
                if (e.ctrlKey && e.key === 's') {
                    e.preventDefault();
                    document.querySelector('button[name="save_file"]').click();
                }

                // Tab键插入4个空格而不是跳转
                if (e.key === 'Tab') {
                    e.preventDefault();
                    var start = this.selectionStart;
                    var end = this.selectionEnd;
                    this.value = this.value.substring(0, start) + '    ' + this.value.substring(end);
                    this.selectionStart = this.selectionEnd = start + 4;
                    scheduleRender();
                }
            });
        }

        // 文件保存确认
        var saveButton = document.querySelector('button[name="save_file"]');
        if (saveButton) {
            saveButton.addEventListener('click', function (e) {
                e.preventDefault();
                TodoModal.confirm({
                    title: '确认保存',
                    message: '确定要保存此文件吗？系统将自动创建备份。',
                    onConfirm: function () {
                        var btn = document.querySelector('button[name="save_file"]');
                        var form = btn ? (btn.form || btn.closest('form')) : null;
                        if (!form) return;

                        if (typeof form.requestSubmit === 'function' && btn) {
                            form.requestSubmit(btn);
                        } else {
                            var intent = form.querySelector('input[type="hidden"][name="save_file"]');
                            if (!intent) {
                                intent = document.createElement('input');
                                intent.type = 'hidden';
                                intent.name = 'save_file';
                                intent.value = '1';
                                form.appendChild(intent);
                            }
                            form.submit();
                        }
                    }
                });
            });
        }

        var domTool = window.TodoDOM || (typeof TodoDOM !== 'undefined' ? TodoDOM : null);
        var getEl = function (id) {
            if (domTool && typeof domTool.get === 'function') {
                return domTool.get(id);
            }
            var el = document.getElementById(id);
            if (!el && window.parent && window.parent !== window) {
                try {
                    el = window.parent.document.getElementById(id);
                } catch (e) {
                }
            }
            return el;
        };

        var Captcha = {
            modal: null,
            bg: null,
            piece: null,
            sliderBtn: null,
            sliderBar: null,
            refreshBtn: null,
            closeBtn: null,
            closeTopBtn: null,
            isDragging: false,
            startX: 0,
            onSuccess: null,
            powSeed: null,
            powDifficulty: 3,
            trackData: [],

            bindElements: function () {
                this.modal = getEl('captcha-modal');
                this.bg = getEl('captcha-bg');
                this.piece = getEl('captcha-piece');
                this.sliderBtn = getEl('captcha-slider-btn');
                this.sliderBar = getEl('captcha-slider-bar');
                this.refreshBtn = getEl('captcha-refresh');
                this.closeBtn = getEl('captcha-close');
                this.closeTopBtn = getEl('captcha-close-top');
            },

            sha256: function(str) {
                function rightRotate(value, amount) {
                    return (value >>> amount) | (value << (32 - amount));
                }
                var mathPow = Math.pow;
                var maxWord = mathPow(2, 32);
                var i, j;
                var result = "";
                var bytes = [];
                var h = [
                    0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
                    0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19
                ];
                var k = [
                    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
                    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
                    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
                    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
                    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
                    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
                    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
                    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
                ];
                var asciiBitLength = str.length * 8;
                for (i = 0; i < str.length; i++) bytes.push(str.charCodeAt(i));
                bytes.push(0x80);
                while ((bytes.length % 64) !== 56) bytes.push(0);
                var words = [];
                for (i = 0; i < bytes.length; i += 4) {
                    words.push((bytes[i] << 24) | (bytes[i + 1] << 16) | (bytes[i + 2] << 8) | (bytes[i + 3]));
                }
                words.push(Math.floor(asciiBitLength / maxWord));
                words.push(asciiBitLength & 0xffffffff);
                for (j = 0; j < words.length;) {
                    var w = words.slice(j, j += 16), oldH = h.slice(0);
                    for (i = 0; i < 64; i++) {
                        var w15 = w[i - 15], w2 = w[i - 2], a = h[0], e = h[4];
                        var s0 = (rightRotate(w15, 7) ^ rightRotate(w15, 18) ^ (w15 >>> 3));
                        var s1 = (rightRotate(w2, 17) ^ rightRotate(w2, 19) ^ (w2 >>> 10));
                        if (i >= 16) w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
                        var S1 = (rightRotate(e, 6) ^ rightRotate(e, 11) ^ rightRotate(e, 25));
                        var ch = ((e & h[5]) ^ ((~e) & h[6]));
                        var temp1 = (h[7] + S1 + ch + k[i] + w[i]) | 0;
                        var S0 = (rightRotate(a, 2) ^ rightRotate(a, 13) ^ rightRotate(a, 22));
                        var maj = ((a & h[1]) ^ (a & h[2]) ^ (h[1] & h[2]));
                        var temp2 = (S0 + maj) | 0;
                        h[7] = h[6]; h[6] = h[5]; h[5] = h[4]; h[4] = (h[3] + temp1) | 0;
                        h[3] = h[2]; h[2] = h[1]; h[1] = h[0]; h[0] = (temp1 + temp2) | 0;
                    }
                    for (i = 0; i < 8; i++) h[i] = (h[i] + oldH[i]) | 0;
                }
                for (i = 0; i < 8; i++) {
                    var hex = (h[i] >>> 0).toString(16);
                    result += ("00000000" + hex).slice(-8);
                }
                return result;
            },

            solvePoW: function() {
                if (!this.powSeed) return '0';
                var prefix = '0'.repeat(this.powDifficulty || 3);
                var nonce = 0;
                while (nonce < 100000) {
                    var hashVal = this.sha256(this.powSeed + nonce);
                    if (hashVal && hashVal.indexOf(prefix) === 0) {
                        return nonce;
                    }
                    nonce++;
                }
                return nonce;
            },

            inited: false,
            init: function () {
                this.bindElements();
                if (this.inited) return;
                this.inited = true;
                var self = this;
                if (this.refreshBtn) this.refreshBtn.addEventListener('click', function () { self.load(); });
                if (this.closeBtn) this.closeBtn.addEventListener('click', function () { self.hide(); });
                if (this.closeTopBtn) this.closeTopBtn.addEventListener('click', function () { self.hide(); });

                if (this.sliderBtn) {
                    this.sliderBtn.addEventListener('mousedown', function (e) { self.startDrag(e); });
                    this.sliderBtn.addEventListener('touchstart', function (e) { self.startDrag(e); }, { passive: false });
                }
                document.addEventListener('mousemove', function (e) { self.onDrag(e); });
                document.addEventListener('touchmove', function (e) { self.onDrag(e); }, { passive: false });
                document.addEventListener('mouseup', function (e) { self.stopDrag(e); });
                document.addEventListener('touchend', function (e) { self.stopDrag(e); });

                if (window.parent && window.parent !== window) {
                    try {
                        window.parent.document.addEventListener('mousemove', function (e) { self.onDrag(e); });
                        window.parent.document.addEventListener('touchmove', function (e) { self.onDrag(e); }, { passive: false });
                        window.parent.document.addEventListener('mouseup', function (e) { self.stopDrag(e); });
                        window.parent.document.addEventListener('touchend', function (e) { self.stopDrag(e); });
                    } catch (ex) {}
                }
            },

            show: function (callback) {
                this.init();
                this.onSuccess = callback;
                if (this.modal) this.modal.style.display = 'flex';
                this.load();
            },

            hide: function () {
                if (this.modal) this.modal.style.display = 'none';
            },

            load: function () {
                var self = this;
                this.trackData = [];
                // Reset slider
                this.sliderBtn.style.left = '-1px';
                this.sliderBar.style.width = '0px';
                this.sliderBar.style.borderWidth = '0px';
                this.piece.style.left = '-1px';

                var formData = new FormData();
                formData.append('action', 'get_captcha');

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (data.bg) {
                            self.bg.src = data.bg;
                            self.piece.src = data.piece;
                            self.piece.style.top = data.y + 'px';
                            self.powSeed = data.pow_seed || null;
                            self.powDifficulty = data.pow_difficulty || 3;
                        } else {
                            self.hide();
                            TodoCMS.Utils.showMessage(data.message || '加载验证码失败', 'error');
                        }
                    })
                    .catch(function (err) {
                        self.hide();
                        TodoCMS.Utils.showMessage('加载验证码失败，请重试', 'error');
                    });
            },
            
            startDrag: function (e) {
                this.isDragging = true;
                this.startX = (e.clientX !== undefined) ? e.clientX : (e.touches ? e.touches[0].clientX : 0);
                var startY = (e.clientY !== undefined) ? e.clientY : (e.touches ? e.touches[0].clientY : 0);
                this.trackData = [{ x: 0, y: startY, t: Date.now() }];
            },

            onDrag: function (e) {
                if (!this.isDragging) return;
                var clientX = (e.clientX !== undefined) ? e.clientX : (e.touches ? e.touches[0].clientX : 0);
                var clientY = (e.clientY !== undefined) ? e.clientY : (e.touches ? e.touches[0].clientY : 0);
                var moveX = clientX - this.startX;
                if (moveX < 0) moveX = 0;
                if (moveX > 260) moveX = 260;

                this.sliderBtn.style.left = moveX * 0.98 + 'px';
                this.sliderBar.style.width = moveX + 20 + 'px';
                this.sliderBar.style.borderWidth = '1px';
                this.piece.style.left = moveX * 0.96 + 'px';

                // 阻止移动端页面滚动
                if (e.cancelable) e.preventDefault();
            },
            
            stopDrag: function(e) {
                if (!this.isDragging) return;
                this.isDragging = false;
                
                var x = parseInt(this.sliderBtn.style.left);
                this.verify(x);
            },
            
            verify: function(x) {
                var self = this;
                var powNonce = this.solvePoW();

                var formData = new FormData();
                formData.append('action', 'verify_captcha');
                formData.append('x', x);
                formData.append('pow_nonce', powNonce);
                formData.append('track', JSON.stringify(this.trackData));
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        self.hide();
                        if (self.onSuccess) self.onSuccess();
                    } else {
                        // 如果被锁定，不要自动重载，直接报错
                        if (data.message && data.message.indexOf('频繁') !== -1) {
                            self.hide();
                            TodoCMS.Utils.showMessage(data.message, 'error');
                        } else {
                            TodoCMS.Utils.showMessage(data.message || '验证失败，请重试', 'warning');
                            self.load();
                        }
                    }
                })
                .catch(function(err) {
                    TodoCMS.Utils.showMessage('验证请求失败，请重试', 'error');
                    self.load();
                });
            }
        };
        Captcha.init();

        // Security Verify Trigger
        var verifyTriggerBtn = document.getElementById('verify-trigger-btn');
        if (verifyTriggerBtn) {
            verifyTriggerBtn.addEventListener('click', function(e) {
                var answer = document.getElementById('security_answer').value.trim();
                if (!answer) {
                    TodoCMS.Utils.showMessage('请输入答案', 'warning');
                    return;
                }
                
                Captcha.show(function() {
                    document.getElementById('real-submit-btn').click();
                });
            });
        }
    });
</script>
