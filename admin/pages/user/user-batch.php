<?php
// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
/**
 * 管理后台批量新建用户页面
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 检查用户权限
if (!current_user_can('create_users')) {
    todo_die('您没有足够的权限访问此页面。');
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_batch_users') {
    // 获取批量表单数据
    $batch_usernames = isset($_POST['batch_usernames']) ? trim($_POST['batch_usernames']) : '';
    $batch_password = isset($_POST['batch_password']) ? $_POST['batch_password'] : '';
    $batch_role = isset($_POST['batch_role']) ? $_POST['batch_role'] : 'subscriber';

    // 验证数据
    $errors = array();

    if (empty($batch_usernames)) {
        $errors[] = '用户名列表不能为空。';
    }

    if (empty($batch_password)) {
        $errors[] = '密码不能为空。';
    } elseif (strlen($batch_password) < 8) {
        $errors[] = '密码长度必须至少为8个字符。';
    }

    // 如果没有错误，处理批量创建
    if (empty($errors)) {
        $usernames = explode("\n", $batch_usernames);
        $success_count = 0;
        $failed_usernames = array();

        foreach ($usernames as $username) {
            $username = trim($username);

            // 检查用户名是否符合要求（只允许字母和数字）
            if (!preg_match('/^[a-zA-Z0-9]+$/', $username)) {
                $failed_usernames[] = $username . ' (包含非法字符)';
                continue;
            }

            // 检查用户名是否已存在
            if (username_exists($username)) {
                $failed_usernames[] = $username . ' (已存在)';
                continue;
            }

            // 创建用户
            $user_data = array(
                'user_login' => $username,
                'user_pass' => $batch_password,
                'role' => $batch_role,
                'user_email' => $username . '@example.com', // 临时邮箱
                'user_registered' => function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s')
            );

            $user_id = todo_insert_user($user_data);

            if (is_todo_error($user_id)) {
                $failed_usernames[] = $username . ' (' . $user_id->get_error_message() . ')';
            } else {
                $success_count++;
            }
        }

        // 设置处理结果消息
        if ($success_count > 0) {
            $_SESSION['batch_success'] = "成功创建 {$success_count} 个用户。";
        }

        if (!empty($failed_usernames)) {
            $_SESSION['batch_failed'] = "以下用户创建失败：\n" . implode("\n", $failed_usernames);
        }

        todo_redirect(todo_admin_url('index.php?page=users'));
        exit;
    }
}

// 获取所有可用角色
$roles = array(
    'administrator' => '管理员',
    'editor' => '编辑',
    'author' => '作者',
    'subscriber' => '订阅者'
);
?>

<div class="admin-user-batch-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">批量添加用户</h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=users'); ?>" class="button button-secondary">返回用户列表</a>
            <a href="<?php echo todo_admin_url('index.php?page=user-new'); ?>" class="button">单个添加用户</a>
        </div>
    </div>

    <?php
    // 显示批量添加的成功消息
    if (isset($_SESSION['batch_success'])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . $_SESSION['batch_success'] . '</p><button type="button" class="notice-dismiss">✕</button></div>';
        unset($_SESSION['batch_success']);
    }

    // 显示批量添加的失败消息
    if (isset($_SESSION['batch_failed'])) {
        echo '<div class="notice notice-error is-dismissible"><p>部分用户创建失败：</p><pre>' . $_SESSION['batch_failed'] . '</pre><button type="button" class="notice-dismiss">✕</button></div>';
        unset($_SESSION['batch_failed']);
    }

    // 显示表单验证错误
    if (!empty($errors)): ?>
        <div class="notice notice-error is-dismissible">
            <?php foreach ($errors as $error): ?>
                <p><?php echo $error; ?></p>
            <?php endforeach; ?>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <div class="admin-card">
        <div class="admin-card-header">
            <h3 class="admin-card-title">批量用户信息</h3>
        </div>
        <div class="admin-card-content">
            <form method="post" class="admin-user-form" action="<?php echo todo_admin_url('index.php?page=user-batch'); ?>">
                <input type="hidden" name="action" value="create_batch_users">

                <div class="form-group">
                    <label for="batch_usernames">用户名列表 <span class="required">*</span></label>
                    <textarea name="batch_usernames" id="batch_usernames" rows="10" class="regular-text" placeholder="示例格式：
user1
user2
user3" required></textarea>
                    <p class="description">每行输入一个用户名，只允许字母和数字的组合。系统会自动过滤不符合要求的用户名。</p>
                </div>

                <div class="admin-card-header">
                    <h3 class="admin-card-title">账户管理</h3>
                </div>

                <div class="form-group">
                    <label for="batch_password">统一密码 <span class="required">*</span></label>
                    <div class="password-field">
                        <input type="password" name="batch_password" id="batch_password" class="regular-text" required
                            autocomplete="new-password">
                        <button type="button" class="button button-secondary show-password"
                            data-target="batch_password">显示</button>
                    </div>
                    <div id="batch-password-strength" class="password-strength">
                        <div class="password-strength-meter"></div>
                        <div class="password-strength-text">密码强度指示器</div>
                    </div>
                    <p class="description">为所有批量添加的用户设置相同的密码，密码长度必须至少为8个字符。建议使用字母、数字和特殊字符的组合。</p>
                </div>

                <div class="form-group">
                    <label for="batch_role">统一角色</label>
                    <select name="batch_role" id="batch_role">
                        <?php foreach ($roles as $role_id => $role_name): ?>
                            <option value="<?php echo $role_id; ?>" <?php selected('subscriber', $role_id); ?>>
                                <?php echo $role_name; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">为所有批量添加的用户设置相同的角色。</p>
                </div>

                <div class="form-submit">
                    <input type="submit" name="submit" value="批量添加用户" class="button button-primary">
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .admin-user-form {
        max-width: 800px;
        margin: 0 auto;
    }

    .admin-user-form .form-group {
        margin-bottom: 20px;
    }

    .admin-user-form .form-row {
        display: flex;
        gap: 20px;
        margin-bottom: 20px;
    }

    .admin-user-form .form-row .form-group {
        flex: 1;
        margin-bottom: 0;
    }

    .admin-user-form label {
        margin-bottom: 5px;
        font-weight: 500;
    }

    .admin-user-form .regular-text {
        width: 100%;
        padding: 8px;
    }

    .admin-user-form textarea.regular-text {
        min-height: 200px;
        font-family: monospace;
    }

    .password-field {
        display: flex;
        gap: 10px;
    }

    .password-field input {
        flex: 1;
    }

    .password-strength {
        margin-top: 5px;
        margin-right: 66px;
    }

    .password-strength-meter {
        height: 4px;
        background-color: #eee;
        margin-bottom: 5px;
    }

    .password-strength-meter.very-weak {
        background-color: #d63638;
        width: 20%;
    }

    .password-strength-meter.weak {
        background-color: #d63638;
        width: 40%;
    }

    .password-strength-meter.medium {
        background-color: #dba617;
        width: 60%;
    }

    .password-strength-meter.strong {
        background-color: #2271b1;
        width: 80%;
    }

    .password-strength-meter.very-strong {
        background-color: #008a20;
        width: 100%;
    }

    .password-strength-text {
        font-size: 12px;
        color: #646970;
    }
</style>

<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        // 密码强度检测函数
        function checkPasswordStrength(password, strengthMeter, strengthText) {
            var strength = 0;
            var feedback = '';

            if (password.length > 0) {
                // 长度检查
                if (password.length >= 8) strength += 1;
                // 包含小写字母
                if (/[a-z]/.test(password)) strength += 1;
                // 包含大写字母
                if (/[A-Z]/.test(password)) strength += 1;
                // 包含数字
                if (/[0-9]/.test(password)) strength += 1;
                // 包含特殊字符
                if (/[^a-zA-Z0-9]/.test(password)) strength += 1;

                // 设置强度指示器
                switch (strength) {
                    case 1:
                        strengthMeter.style.width = '20%';
                        strengthMeter.style.backgroundColor = '#c63e78';
                        feedback = '非常弱';
                        break;
                    case 2:
                        strengthMeter.style.width = '40%';
                        strengthMeter.style.backgroundColor = '#e53e3e';
                        feedback = '弱';
                        break;
                    case 3:
                        strengthMeter.style.width = '60%';
                        strengthMeter.style.backgroundColor = '#eecf00';
                        feedback = '中等';
                        break;
                    case 4:
                        strengthMeter.style.width = '80%';
                        strengthMeter.style.backgroundColor = '#91e153';
                        feedback = '强';
                        break;
                    case 5:
                        strengthMeter.style.width = '100%';
                        strengthMeter.style.backgroundColor = '#00c25a';
                        feedback = '非常强';
                        break;
                    default:
                        strengthMeter.style.width = '0';
                        feedback = '密码强度指示器';
                }

                strengthText.textContent = feedback;
            } else {
                strengthMeter.style.width = '0';
                strengthText.textContent = '密码强度指示器';
            }
        }

        // 密码显示/隐藏切换
        var passwordToggles = document.querySelectorAll('.show-password');
        passwordToggles.forEach(function (toggle) {
            toggle.addEventListener('click', function () {
                var targetId = this.getAttribute('data-target');
                var passwordInput = document.getElementById(targetId);

                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    this.textContent = '隐藏';
                } else {
                    passwordInput.type = 'password';
                    this.textContent = '显示';
                }
            });
        });

        // 批量模式密码强度检测
        var batchPasswordField = document.getElementById('batch_password');
        var batchStrengthMeter = document.querySelector('#batch-password-strength .password-strength-meter');
        var batchStrengthText = document.querySelector('#batch-password-strength .password-strength-text');

        if (batchPasswordField && batchStrengthMeter && batchStrengthText) {
            batchPasswordField.addEventListener('input', function () {
                checkPasswordStrength(this.value, batchStrengthMeter, batchStrengthText);
            });
        }
    });
</script>
