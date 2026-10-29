<?php
// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
/**
 * 管理后台新建用户页面
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    // 获取表单数据
    $username = isset($_POST['user_login']) ? sanitize_user($_POST['user_login']) : '';
    $email = isset($_POST['user_email']) ? sanitize_email($_POST['user_email']) : '';
    $password = isset($_POST['user_pass']) ? $_POST['user_pass'] : '';
    $confirm_password = isset($_POST['user_pass_confirm']) ? $_POST['user_pass_confirm'] : '';
    $first_name = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
    $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
    $display_name = isset($_POST['display_name']) ? sanitize_text_field($_POST['display_name']) : '';
    $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';
    $role = isset($_POST['role']) ? $_POST['role'] : 'subscriber';
    $send_notification = isset($_POST['send_user_notification']) ? true : false;

    // 验证数据
    $errors = array();

    if (empty($username)) {
        $errors[] = '用户名不能为空。';
    } elseif (username_exists($username)) {
        $errors[] = '该用户名已被使用。';
    }

    // 验证笔名
    if ($first_name === $username) {
        $errors[] = '笔名不能与用户名相同，这可能会带来安全隐患。';
    }

    // 验证电话号码格式
    if (!empty($phone)) {
        $phone_digits = preg_replace('/[^0-9]/', '', $phone);
        $digit_count = strlen($phone_digits);
        if (!preg_match('/^\+?[0-9\s\-\(\)\.]{7,20}$/', $phone) || $digit_count < 7 || $digit_count > 15) {
            $errors[] = '请输入有效的电话号码。';
        }
    }

    if (empty($email)) {
        $errors[] = '电子邮箱不能为空。';
    } elseif (!is_email($email)) {
        $errors[] = '请输入有效的电子邮箱地址。';
    } elseif (email_exists($email)) {
        $errors[] = '该电子邮箱已被使用。';
    }

    if (empty($password)) {
        $errors[] = '密码不能为空。';
    } elseif (strlen($password) < 8) {
        $errors[] = '密码长度必须至少为8个字符。';
    } elseif ($password !== $confirm_password) {
        $errors[] = '两次输入的密码不匹配。';
    }

    // 如果没有错误，创建用户
    if (empty($errors)) {
        $user_data = array(
            'user_login' => $username,
            'user_email' => $email,
            'user_pass' => $password,
            'display_name' => $display_name ?: $username,
            'role' => $role,
            'user_registered' => function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s'),
            'meta' => array(
                'first_name' => $first_name,
                'phone' => $phone,
                'description' => $description,
            ),
        );

        $user_id = todo_insert_user($user_data);

        if (is_todo_error($user_id)) {
            $errors[] = $user_id->get_error_message();
        } else {
            // 发送通知邮件
            $mail_failed_message = '';
            if ($send_notification) {
                // 构建模板变量（与 mail/settings.php 中 profile_update 类型定义的变量对应）
                $mail_data = [
                    'username'     => $username,
                    'password'     => $password,
                    'email'        => $email,
                    'display_name' => $display_name ?: $username,
                    'first_name'   => $first_name,
                    'site_name'    => get_option('site_title', 'TodoCMS'),
                ];

                $sent = false;
                try {
                    // 优先使用"个人资料"邮件模板（fixed_slug: profile_update_verify）
                    if (function_exists('todo_get_email_template') && todo_get_email_template('profile_update_verify')) {
                        $sent = todo_send_template_mail($email, 'profile_update_verify', $mail_data);
                    } else {
                        // 模板未配置时，回退到纯文本邮件
                        $subject = sprintf('欢迎加入 %s', get_option('site_title'));
                        $message = sprintf(
                            "您好 %s,\n\n欢迎加入我们的网站！您可以使用以下信息登录：\n\n用户名: %s\n密码: %s\n登录地址: %s\n\n感谢您的加入！\n\n%s",
                            $first_name ? $first_name : $username,
                            $username,
                            $password,
                            todo_url('/admin/'),
                            get_option('site_title')
                        );
                        $sent = todo_mail($email, $subject, $message);
                    }

                    if (!$sent) {
                        $mail_failed_message = '用户创建成功，但通知邮件发送失败，请检查邮件配置。若使用 QQ 邮箱：需在邮箱后台开启 SMTP 服务，并使用授权码（非登录密码）进行发信；如提示登录频繁/系统繁忙，等待 10-15 分钟后再试。';
                    }
                } catch (Throwable $e) {
                    $mail_failed_message = '用户创建成功，但通知邮件发送失败：' . $e->getMessage();
                }
            }

            // 初始化或设置用户等级
            $level_manager = function_exists('todo_get_user_level_manager') ? todo_get_user_level_manager() : null;
            if ($level_manager) {
                $posted_level_id = isset($_POST['user_level_id']) ? intval($_POST['user_level_id']) : 0;
                if ($posted_level_id > 0) {
                    $level_obj = $level_manager->get_user_level($posted_level_id);
                    $points = $level_obj ? (int) $level_obj->min_points : null;
                    $level_manager->update_user_level($user_id, $posted_level_id, $points, 'manual');
                } else {
                    // 自动分配最低等级
                    $level_manager->init_user_level($user_id);
                }
            }

            // 设置成功消息，并使用 PRG 模式避免重复提交
            if (function_exists('todo_set_flash_message')) {
                if ($mail_failed_message !== '') {
                    todo_set_flash_message($mail_failed_message, 'warning');
                } else {
                    todo_set_flash_message('用户创建成功。', 'success');
                }
            }
            if (function_exists('todo_admin_url')) {
                todo_redirect(todo_admin_url('index.php?page=user-new'));
            } else {
                todo_redirect(todo_admin_url('index.php?page=user-new'));
            }
            return; // 终止后续输出
        }
    }
}

// 可用角色
$roles = array(
    'administrator' => '管理员',
    'editor' => '编辑',
    'author' => '作者',
    'subscriber' => '订阅者'
);

// 获取用户等级列表（仅展示激活的）
$level_manager = function_exists('todo_get_user_level_manager') ? todo_get_user_level_manager() : null;
$levels = $level_manager ? $level_manager->get_user_levels(array('is_active' => 1)) : array();
?>

<div class="admin-user-new-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">添加新用户</h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=users'); ?>" class="button button-secondary">返回用户列表</a>
            <a href="<?php echo todo_admin_url('index.php?page=user-batch'); ?>" class="button">批量添加用户</a>
        </div>
    </div>

    <?php
    // 显示 PRG 闪存消息
    if (function_exists('todo_get_flash_message')) {
        $flash = todo_get_flash_message();
        if ($flash && !empty($flash['message'])) {
            $type = isset($flash['type']) ? $flash['type'] : 'success';
            echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible">';
            echo '<p>' . esc_html($flash['message']) . '</p>';
            echo '<button type="button" class="notice-dismiss">✕</button>';
            echo '</div>';
        }
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

    <div class="admin-card single-mode">
        <div class="admin-card-header">
            <h3 class="admin-card-title">新用户信息</h3>
        </div>
        <div class="admin-card-content">
            <form method="post" class="admin-user-form" action="<?php echo todo_admin_url('index.php?page=user-new'); ?>">
                <input type="hidden" name="action" value="create_user">

                <div class="form-row">
                    <div class="form-group">
                        <label for="user_login">用户名 <span class="required">*</span></label>
                        <input type="text" name="user_login" id="user_login"
                            value="<?php echo isset($username) ? esc_attr($username) : ''; ?>" class="regular-text"
                            required>
                        <p class="description">用户名只能包含字母、数字、下划线、连字符、点号和@符号。</p>
                    </div>

                    <div class="form-group">
                        <label for="user_email">电子邮箱 <span class="required">*</span></label>
                        <input type="email" name="user_email" id="user_email"
                            value="<?php echo isset($email) ? esc_attr($email) : ''; ?>" class="regular-text" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">笔名</label>
                        <input type="text" name="first_name" id="first_name"
                            value="<?php echo isset($first_name) ? esc_attr($first_name) : ''; ?>" class="regular-text">
                        <p class="description">用于显示文章作者名字，禁止与用户名相同。</p>
                    </div>

                    <div class="form-group">
                        <label for="phone">电话号码</label>
                        <input type="tel" name="phone" id="phone"
                            value="<?php echo isset($phone) ? esc_attr($phone) : ''; ?>" class="regular-text"
                            pattern="^\+?[0-9\s\-\(\)\.]{7,20}$" title="请输入有效的电话号码">
                        <p class="description">支持国内及国际格式电话号码。</p>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="display_name">显示名称</label>
                        <input type="text" name="display_name" id="display_name"
                            value="<?php echo isset($display_name) ? esc_attr($display_name) : ''; ?>"
                            class="regular-text">
                        <p class="description">这将是公开显示的名称。</p>
                    </div>

                    <div class="form-group">
                        <label for="description">个人说明</label>
                        <textarea name="description" id="description" rows="3"
                            class="regular-text"><?php echo isset($description) ? esc_textarea($description) : ''; ?></textarea>
                        <p class="description">关于您的一些个人介绍。</p>
                    </div>
                </div>

                <div class="admin-card-header">
                    <h3 class="admin-card-title">账户管理</h3>
                </div>

                <div class="form-group">
                    <label for="user_pass">密码 <span class="required">*</span></label>
                    <div class="password-field">
                        <input type="password" name="user_pass" id="user_pass" class="regular-text" required
                            autocomplete="new-password">
                        <button type="button" class="button button-secondary show-password"
                            data-target="user_pass">显示</button>
                    </div>
                    <div id="password-strength" class="password-strength">
                        <div class="password-strength-meter"></div>
                        <div class="password-strength-text">密码强度指示器</div>
                    </div>
                    <p class="description">提示：密码应至少包含8个字符，并且包含大小写字母、数字和特殊字符。</p>
                </div>

                <div class="form-group">
                    <label for="user_pass_confirm">确认密码 <span class="required">*</span></label>
                    <div class="password-field">
                        <input type="password" name="user_pass_confirm" id="user_pass_confirm" class="regular-text"
                            required autocomplete="new-password">
                        <button type="button" class="button button-secondary show-password"
                            data-target="user_pass_confirm">显示</button>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="role">角色</label>
                        <select name="role" id="role">
                            <?php foreach ($roles as $role_id => $role_name): ?>
                                <option value="<?php echo $role_id; ?>" <?php selected(isset($role) ? $role : 'subscriber', $role_id); ?>>
                                    <?php echo $role_name; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="user_level_id">等级</label>
                        <?php if (!empty($levels)): ?>
                            <select name="user_level_id" id="user_level_id">
                                <option value="0" <?php echo (isset($_POST['user_level_id']) && intval($_POST['user_level_id']) === 0) ? 'selected' : ''; ?>>自动分配(最低等级)</option>
                                <?php foreach ($levels as $lvl): ?>
                                    <option value="<?php echo (int) $lvl->id; ?>" <?php echo (isset($_POST['user_level_id']) && intval($_POST['user_level_id']) === (int) $lvl->id) ? 'selected' : ''; ?>>
                                        <?php echo esc_html($lvl->level_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">为新用户设置初始会员等级，或选择自动分配最低等级。</p>
                        <?php else: ?>
                            <p class="description">暂无可选等级，请先在“用户等级”中添加。</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="send_user_notification" value="1">
                        向新用户发送账户详情的电子邮件
                    </label>
                </div>

                <div class="form-submit">
                    <input type="submit" name="submit" value="添加新用户" class="button button-primary">
                </div>
            </form>
        </div>
    </div>

</div>

<style>
    /* 用户表单特定样式 */
    .admin-user-form .form-group {
        margin-bottom: 20px;
    }

    .admin-user-form .form-row {
        display: flex;
        flex-wrap: wrap;
        margin: 0 -10px;
    }

    .admin-user-form .form-row .form-group {
        flex: 1;
        min-width: 250px;
        padding: 0 10px;
    }

    .admin-user-form label {
        margin-bottom: 5px;
        font-weight: 500;
    }

    .admin-user-form .regular-text {
        width: 100%;
    }

    .password-field {
        display: flex;
        align-items: center;
    }

    .password-field input {
        flex: 1;
        margin-right: 5px;
    }

    .password-strength {
        margin-top: 5px;
        margin-right: 66px;
    }

    .password-strength-meter {
        height: 4px;
        background-color: #eceef1;
        margin-bottom: 5px;
        width: 0;
        transition: width 0.3s, background-color 0.3s;
    }

    .password-strength-text {
        font-size: 12px;
        color: #666;
    }
</style>

<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        // 密码显示/隐藏切换
        var showPasswordButtons = document.querySelectorAll('.show-password');

        showPasswordButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var targetId = this.getAttribute('data-target');
                var passwordField = document.getElementById(targetId);

                if (passwordField.type === 'password') {
                    passwordField.type = 'text';
                    this.textContent = '隐藏';
                } else {
                    passwordField.type = 'password';
                    this.textContent = '显示';
                }
            });
        });

        // 密码强度检测
        var passwordField = document.getElementById('user_pass');
        var strengthMeter = document.querySelector('.password-strength-meter');
        var strengthText = document.querySelector('.password-strength-text');

        if (passwordField && strengthMeter && strengthText) {
            passwordField.addEventListener('input', function () {
                var password = this.value;
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
            });
        }

        // 表单验证
        var userForm = document.querySelector('.admin-user-form');
        var confirmPasswordField = document.getElementById('user_pass_confirm');

        if (userForm && passwordField && confirmPasswordField) {
            userForm.addEventListener('submit', function (e) {
                // 检查密码匹配
                if (passwordField.value !== confirmPasswordField.value) {
                    e.preventDefault();
                    TodoCMS.Utils.showMessage('两次输入的密码不匹配。', 'warning');
                    confirmPasswordField.focus();
                    return false;
                }

                // 检查密码长度
                if (passwordField.value.length < 8) {
                    e.preventDefault();
                    TodoCMS.Utils.showMessage('密码长度必须至少为8个字符。', 'warning');
                    passwordField.focus();
                    return false;
                }

                return true;
            });
        }
    });
</script>
</body>

</html>
