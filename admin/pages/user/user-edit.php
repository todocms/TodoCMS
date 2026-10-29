<?php
// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
/**
 * 管理后台用户编辑页面
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 获取当前用户ID
$current_user_id = get_current_user_id();
if (!$current_user_id) {
    todo_die('您没有足够的权限访问此页面。');
}

// 获取用户ID（默认编辑自己）
$user_id = isset($_REQUEST['user_id']) ? intval($_REQUEST['user_id']) : (isset($_REQUEST['id']) ? intval($_REQUEST['id']) : $current_user_id);

// 检查用户是否存在
if (!$user_id || !get_user_by('id', $user_id)) {
    todo_die('用户不存在。');
}

// 获取用户对象
$user = new CMS_User($user_id);
if (!class_exists('TodoFeaturedImagesComponent')) {
    $featured_component = ABSPATH . 'admin/components/featured-images-component.php';
    if (file_exists($featured_component)) {
        require_once $featured_component;
    }
}

$is_self = ($current_user_id == $user_id);

// 编辑他人资料需要用户管理权限；编辑自己始终允许
if (!$is_self && !current_user_can('edit_users')) {
    todo_die('您没有足够的权限访问此页面。');
}

$current_user_obj = class_exists('CMS_User') ? new CMS_User($current_user_id) : null;
$is_platform_admin = false;
if (function_exists('todo_is_super_admin') && todo_is_super_admin($current_user_id)) {
    $is_platform_admin = true;
} elseif (is_object($current_user_obj) && method_exists($current_user_obj, 'has_role')) {
    $is_platform_admin = $current_user_obj->has_role('administrator') || $current_user_obj->has_role('admin');
}

// 限制对超级管理员/开发账号的访问（仅允许同级别的超级管理员互访，普通管理员强制驱离）
if (todo_is_super_admin($user_id) && !todo_is_super_admin($current_user_id)) {
    // 如果不是开发账号在访问另一个开发账号，跳转到自己的编辑页
    $self_edit_url = add_query_arg(array(
        'page' => 'user-edit',
        'id' => $current_user_id
    ), todo_admin_url('index.php', true));
    todo_redirect($self_edit_url);
    exit;
}

// 只有管理员可以编辑其他管理员
if (!$is_self && !$is_platform_admin && method_exists($user, 'has_role') && ($user->has_role('administrator') || $user->has_role('admin'))) {
    todo_die('您没有足够的权限编辑此用户。');
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_user') {
    // 获取表单数据
    $email = isset($_POST['user_email']) ? sanitize_email($_POST['user_email']) : '';
    $first_name = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
    $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
    $display_name = isset($_POST['display_name']) ? sanitize_text_field($_POST['display_name']) : '';

    $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';
    $role = isset($_POST['role']) ? $_POST['role'] : '';
    $new_password = isset($_POST['user_pass']) ? $_POST['user_pass'] : '';
    $confirm_password = isset($_POST['user_pass_confirm']) ? $_POST['user_pass_confirm'] : '';
    $avatar_payload = $_POST['avatar'] ?? null;

    // 验证数据
    $errors = array();

    if (empty($email)) {
        $errors[] = '电子邮箱不能为空。';
    } elseif (!is_email($email)) {
        $errors[] = '请输入有效的电子邮箱地址。';
    } else {
        // 仅当邮箱修改时才检查唯一性；避免误报自身邮箱重复
        $current_email = '';
        try {
            $current_data = $user->get_data();
            if (is_object($current_data) && isset($current_data->user_email)) {
                $current_email = $current_data->user_email;
            } elseif (is_array($current_data) && isset($current_data['user_email'])) {
                $current_email = $current_data['user_email'];
            }
        } catch (Exception $e) {
            // 忽略获取当前邮箱的异常，继续执行唯一性校验
        }
        if ($email !== $current_email) {
            $existing_id = email_exists($email);
            if ($existing_id && (int) $existing_id !== (int) $user_id) {
                $errors[] = '该电子邮箱已被其他用户使用。';
            }
        }
    }

    // 验证笔名
    if ($first_name === $user->user_login) {
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

    // 验证密码（如果提供）
    if (!empty($new_password)) {
        if (strlen($new_password) < 8) {
            $errors[] = '密码长度必须至少为8个字符。';
        } elseif ($new_password !== $confirm_password) {
            $errors[] = '两次输入的密码不匹配。';
        }
    }

    // 如果没有错误，更新用户
    if (empty($errors)) {
        $user_data = array(
            'ID' => $user_id,
            'user_email' => $email,
            'display_name' => $display_name,

            'meta' => array(
                'first_name' => $first_name,
                'phone' => $phone,
                'description' => $description
            )
        );

        // 如果提供了新密码，则更新密码
        if (!empty($new_password)) {
            $user_data['user_pass'] = $new_password;
        }

        // 更新用户数据
        error_log('Form submission - User data to update: ' . print_r($user_data, true));
        $result = todo_update_user($user_data);
        error_log('todo_update_user result: ' . (is_todo_error($result) ? 'error' : 'success'));

        if (is_todo_error($result)) {
            $error_message = $result->get_error_message();
            error_log('todo_update_user error: ' . $error_message);
            $errors[] = $error_message;
        } else {
            if ($avatar_payload !== null) {
                $payload_str = is_string($avatar_payload) ? $avatar_payload : '';
                $payload_str = stripslashes($payload_str);
                $decoded = json_decode($payload_str, true);
                $avatar_url = '';
                if (is_array($decoded) && !empty($decoded)) {
                    $first = $decoded[0];
                    if (is_array($first) && isset($first['url']) && is_string($first['url'])) {
                        $avatar_url = trim($first['url']);
                    }
                }
                if ($avatar_url !== '') {
                    if (function_exists('todo_media_url_to_relative')) {
                        $avatar_url = todo_media_url_to_relative($avatar_url);
                    }
                    update_user_meta($user_id, 'avatar', $avatar_url);
                } else {
                    delete_user_meta($user_id, 'avatar');
                }
            }

            // 更新开发权限标记 (仅超级管理员可操作)
            if (todo_is_super_admin($current_user_id)) {
                if (isset($_POST['is_developer']) && $_POST['is_developer'] === '1') {
                    update_user_meta($user_id, 'is_developer', '1');
                } else {
                    // ID为1的账号强制保持开发者身份（或者由你决定是否允许取消）
                    if ($user_id != 1) {
                        delete_user_meta($user_id, 'is_developer');
                    }
                }
            }

            // 更新用户角色（如果当前用户有权限）
            if (!$is_self && $is_platform_admin && !empty($role)) {
                $user->set_role($role);
            }

            // 更新用户等级（仅管理员，且不编辑自身）
            if (!$is_self && $is_platform_admin) {
                $level_manager = function_exists('todo_get_user_level_manager') ? todo_get_user_level_manager() : null;
                if ($level_manager && isset($_POST['user_level_id'])) {
                    $level_id = intval($_POST['user_level_id']);
                    if ($level_id > 0) {
                        $level_obj = $level_manager->get_user_level($level_id);
                        $points = $level_obj ? (int) $level_obj->min_points : null; // 若无对象则沿用当前积分
                        $level_manager->update_user_level($user_id, $level_id, $points, 'manual');
                    }
                }
            }

            // 使用 PRG 模式：重定向到同一页面，避免重复提交并刷新数据
            $redirect_url = add_query_arg(array(
                'page' => 'user-edit',
                'id' => $user_id,
                'message' => 'updated_user'
            ), todo_admin_url('index.php', true));
            
            todo_redirect($redirect_url);
            exit;
        }
    }
}

// 消息显示已由全局 admin_notices 钩子处理

// 获取用户数据
$user_data_obj = $user->get_data();
$user_data = (array) $user_data_obj; // 转换为数组
$user_meta = $user->get_meta(null, []);

// 获取所有可用角色
$roles = array(
    'administrator' => '管理员',
    'editor' => '编辑',
    'author' => '作者',
    'subscriber' => '订阅者'
);

// 获取用户当前角色
$user_roles = $user->get_roles();
$current_role = !empty($user_roles) ? reset($user_roles) : '';

// 获取用户等级列表及当前等级
$level_manager = function_exists('todo_get_user_level_manager') ? todo_get_user_level_manager() : null;
$levels = $level_manager ? $level_manager->get_user_levels(array('is_active' => 1)) : array();
$current_level_record = $level_manager ? $level_manager->get_user_current_level($user_id) : null;
$current_level_id = $current_level_record ? (int) $current_level_record->level_id : 0;

// 获取当前角色和等级名称
$current_role_name = isset($roles[$current_role]) ? $roles[$current_role] : $current_role;
$current_level_name = '暂无等级';
if ($current_level_id > 0 && !empty($levels)) {
    foreach ($levels as $lvl) {
        if ((int) $lvl->id === (int) $current_level_id) {
            $current_level_name = $lvl->level_name;
            break;
        }
    }
}

// 获取可用的显示名称选项
$display_name_options = array();

$candidates = array_filter(array(
    $user_data['user_login'] ?? '',
    $user_meta['first_name'] ?? '',
    $user_meta['nickname'] ?? '',
    $user_meta['phone'] ?? '',
    $user_data['user_email'] ?? '',
    $user_data['display_name'] ?? '',
));

foreach ($candidates as $name) {
    $display_name_options[$name] = $name;
}
?>

<div class="admin-user-edit-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">编辑用户</h2>
        <?php if (current_user_can('list_users')): ?>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=users'); ?>" class="button button-secondary">返回用户列表</a>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="notice notice-error is-dismissible">
            <?php foreach ($errors as $error): ?>
                <p><?php echo esc_html($error); ?></p>
            <?php endforeach; ?>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <div class="admin-card">
        <div class="admin-card-header hidden">
            <h3 class="admin-card-title">个人资料</h3>
        </div>
        <div class="admin-card-content">
            <form method="post" class="admin-user-form" action="<?php echo todo_admin_url('index.php?page=user-edit&id=' . $user_id); ?>">
                <input type="hidden" name="action" value="update_user">

                <div class="form-group">
                    <label>头像</label>
                    <?php
                    $current_avatar = function_exists('get_user_avatar_url') ? get_user_avatar_url($user_id, 160) : '';
                    $current_images = array();
                    if (!empty($current_avatar)) {
                        $current_images[] = array(
                            'url' => $current_avatar,
                            'title' => 'avatar',
                            'type' => 'image'
                        );
                    }
                    if (class_exists('TodoFeaturedImagesComponent')) {
                        $avatar_component = new TodoFeaturedImagesComponent(array(
                            'field_id' => 'avatar',
                            'field_name' => 'avatar',
                            'multiple' => false,
                            'max_images' => 1,
                            'sortable' => false,
                            'file_type' => 'image',
                            'variant' => 'universal',
                            'current_images' => $current_images,
                            'modal_id' => 'user-avatar-modal',
                            'enable_debug' => false
                        ));
                        echo $avatar_component->render();
                    }
                    ?>
                    <p class="description">可上传新头像覆盖；不设置时系统会自动生成默认头像。</p>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>用户名</label>
                        <input type="text"
                            value="<?php echo esc_attr(isset($user_data['user_login']) ? $user_data['user_login'] : ''); ?>"
                            class="regular-text" disabled>
                        <p class="description">用户名不能更改。</p>
                    </div>

                    <div class="form-group">
                        <label for="user_email">电子邮箱 <span class="required">*</span></label>
                        <input type="email" name="user_email" id="user_email"
                            value="<?php echo esc_attr(isset($user_data['user_email']) ? $user_data['user_email'] : ''); ?>"
                            class="regular-text" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">笔名</label>
                        <input type="text" name="first_name" id="first_name"
                            value="<?php echo esc_attr(!empty($user_meta['first_name']) ? $user_meta['first_name'] : (!empty($user_meta['nickname']) ? $user_meta['nickname'] : '')); ?>"
                            class="regular-text">
                        <p class="description">用于显示文章作者名字，禁止与用户名相同。</p>
                    </div>

                    <div class="form-group">
                        <label for="phone">电话号码</label>
                        <input type="tel" name="phone" id="phone"
                            value="<?php echo esc_attr(isset($user_meta['phone']) ? $user_meta['phone'] : ''); ?>"
                            class="regular-text" pattern="^\+?[0-9\s\-\(\)\.]{7,20}$" title="请输入有效的电话号码">
                        <p class="description">支持国内及国际格式电话号码。</p>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="display_name">显示名称</label>
                        <select name="display_name" id="display_name">
                            <?php foreach ($display_name_options as $option_value => $option_text): ?>
                                <option value="<?php echo esc_attr($option_value); ?>" <?php selected($user_data['display_name'], $option_value); ?>>
                                    <?php echo esc_html($option_text); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">这将决定此用户公开显示的名称。</p>
                    </div>

                    <div class="form-group">
                        <label for="description">个人说明</label>
                        <textarea name="description" id="description" rows="3"
                            class="regular-text"><?php echo esc_textarea(isset($user_meta['description']) ? $user_meta['description'] : ''); ?></textarea>
                        <p class="description">关于您的一些个人信息，可能会在主题中显示。</p>
                    </div>
                </div>

                <div class="admin-card-header">
                    <h3 class="admin-card-title">账户管理</h3>
                </div>

                <div class="form-group">
                    <label for="user_pass">新密码</label>
                    <div class="password-field">
                        <input type="password" name="user_pass" id="user_pass" class="regular-text"
                            autocomplete="new-password">
                        <button type="button" class="button button-secondary show-password"
                            data-target="user_pass">显示</button>
                    </div>
                    <div id="password-strength" class="password-strength">
                        <div class="password-strength-meter"></div>
                        <div class="password-strength-text">密码强度指示器</div>
                    </div>
                    <p class="description">如果您想更改密码，请在此输入新密码。否则请留空。</p>
                </div>

                <div class="form-group">
                    <label for="user_pass_confirm">确认新密码</label>
                    <div class="password-field">
                        <input type="password" name="user_pass_confirm" id="user_pass_confirm" class="regular-text"
                            autocomplete="new-password">
                        <button type="button" class="button button-secondary show-password"
                            data-target="user_pass_confirm">显示</button>
                    </div>
                </div>
                
                <div class="form-row">
                    <?php if (!$is_self && current_user_can('administrator')): ?>
                    <div class="form-group">
                        <label for="role">角色</label>
                        <select name="role" id="role">
                            <?php foreach ($roles as $role_id => $role_name): ?>
                                <option value="<?php echo esc_attr($role_id); ?>" <?php selected($current_role, $role_id); ?>>
                                    <?php echo esc_html($role_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="user_level_id">等级</label>
                        <?php if (!empty($levels)): ?>
                            <select name="user_level_id" id="user_level_id">
                                <?php foreach ($levels as $lvl): ?>
                                    <option value="<?php echo (int) $lvl->id; ?>" <?php selected($current_level_id, (int) $lvl->id); ?>>
                                        <?php echo esc_html($lvl->level_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">设置该用户的会员等级。</p>
                        <?php else: ?>
                            <p class="description">暂无可选等级，请先在“用户等级”中添加。</p>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="form-group">
                        <label>角色：<strong><?php echo esc_html($current_role_name); ?></strong></label>
                        <p class="description">用户在后台的角色。</p>
                    </div>

                    <div class="form-group">
                        <label>等级：<strong><?php echo esc_html($current_level_name); ?></strong></label>
                        <p class="description">用户在前台的会员等级。</p>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (todo_is_super_admin($current_user_id)): ?>
                <div class="form-row">
                    <div class="form-group">
                        <label>隐身设置</label>
                        <label style="font-weight: normal; cursor: pointer;">
                            <input type="checkbox" name="is_developer" value="1" <?php checked(get_user_meta($user_id, 'is_developer', true) === '1'); ?>>
                            设为隐身模式
                        </label>
                        <p class="description">开启后，该账号将对普通管理员不可见。</p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($is_platform_admin): ?>
                <div class="form-row">
                    <div class="form-group user-sessions">
                        <label>会话</label>
                        <p>
                            <?php echo Todo_Security_Manager::get_csrf_field('logout_other_sessions', '_csrf_token'); ?>
                            <button type="button" class="button button-secondary" id="log-out-everywhere"
                                data-user-id="<?php echo (int) $user_id; ?>">注销所有其他会话</button>
                        </p>
                        <p class="description">如果您注销所有其他会话，您将只保持当前浏览器的登录状态。</p>
                    </div>

                    <div class="form-group user-sessions">
                        <label>用户中心</label>
                        <?php echo Todo_Security_Manager::get_csrf_field('impersonate_user', '_csrf_token'); ?>
                        <button type="button" class="button button-secondary" id="view-usercenter"
                            data-user-id="<?php echo (int) $user_id; ?>">查看用户中心</button>
                        <p class="description">自动登录并查看用户的个人中心。</p>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-submit">
                    <input type="submit" name="submit" value="更新用户资料" class="button button-primary">
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    /* 用户编辑页面特定样式 */
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

    .user-sessions {
        margin-top: 30px;
        padding-top: 20px !important;
        border-top: 1px solid #eee;
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
                    if (password.length >= 8) {
                        strength += 1;
                    }

                    // 包含小写字母
                    if (/[a-z]/.test(password)) {
                        strength += 1;
                    }

                    // 包含大写字母
                    if (/[A-Z]/.test(password)) {
                        strength += 1;
                    }

                    // 包含数字
                    if (/[0-9]/.test(password)) {
                        strength += 1;
                    }

                    // 包含特殊字符
                    if (/[^a-zA-Z0-9]/.test(password)) {
                        strength += 1;
                    }

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
                // 如果设置了新密码，检查密码匹配
                if (passwordField.value.length > 0) {
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
                }

                return true;
            });
        }

        // 注销所有其他会话
        var logOutEverywhereButton = document.getElementById('log-out-everywhere');

        if (logOutEverywhereButton) {
            logOutEverywhereButton.addEventListener('click', function () {
                TodoModal.confirm({
                    message: '您确定要注销所有其他会话吗？',
                    onConfirm: function() {
                        var userId = logOutEverywhereButton.getAttribute('data-user-id');
                        var csrfToken = document.querySelector('#log-out-everywhere').closest('.form-group').querySelector('input[name="_csrf_token"]').value;
                        var xhr = new XMLHttpRequest();
                        var ajaxDir = (window.TODO && typeof window.TODO.AJAX_DIR === 'string' && window.TODO.AJAX_DIR)
                            ? window.TODO.AJAX_DIR
                            : ((window.TODO && window.TODO.join && window.TODO.ADMIN_BASE) ? window.TODO.join(window.TODO.ADMIN_BASE, 'ajax') : ((document.querySelector('meta[name="site-url"]')?.getAttribute('content') || window.location.origin).replace(/\/+$/, '') + '/admin/ajax'));
                        var url = (window.TODO && window.TODO.join) ? window.TODO.join(ajaxDir, 'user-session-handler.php') : (ajaxDir.replace(/\/+$/, '') + '/user-session-handler.php');
                        xhr.open('POST', url, true);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                        xhr.onload = function () {
                            try {
                                var resp = JSON.parse(xhr.responseText);
                                if (resp.success) {
                                    TodoCMS.Utils.showMessage('所有其他会话已注销。', 'success');
                                } else {
                                    var errorMsg = '操作失败：' + (resp.error || '未知错误');
                                    TodoCMS.Utils.showMessage(errorMsg, 'error');
                                }
                            } catch (e) {
                                TodoCMS.Utils.showMessage('服务器响应异常。', 'error');
                            }
                        };
                        xhr.send('action=logout_other_sessions&user_id=' + encodeURIComponent(userId) + '&_csrf_token=' + encodeURIComponent(csrfToken));
                    }
                });
            });
        }

        // 以该用户身份查看用户中心（管理员权限）
        var viewUserCenterButton = document.getElementById('view-usercenter');
        if (viewUserCenterButton) {
            viewUserCenterButton.addEventListener('click', function () {
                if (viewUserCenterButton.disabled) return;
                var userId = viewUserCenterButton.getAttribute('data-user-id');
                // 仅从同一表单分组中获取对应的CSRF令牌，避免使用其他操作的令牌
                var csrfTokenInput = viewUserCenterButton.closest('.form-group').querySelector('input[name="_csrf_token"]');
                var csrfToken = csrfTokenInput ? csrfTokenInput.value : '';
                if (!csrfToken) {
                    TodoCMS.Utils.showMessage('安全令牌缺失，请刷新页面后重试。', 'error');
                    return;
                }

                viewUserCenterButton.disabled = true;

                var xhr = new XMLHttpRequest();
                var ajaxDir = (window.TODO && typeof window.TODO.AJAX_DIR === 'string' && window.TODO.AJAX_DIR)
                    ? window.TODO.AJAX_DIR
                    : ((window.TODO && window.TODO.join && window.TODO.ADMIN_BASE) ? window.TODO.join(window.TODO.ADMIN_BASE, 'ajax') : ((document.querySelector('meta[name="site-url"]')?.getAttribute('content') || window.location.origin).replace(/\/+$/, '') + '/admin/ajax'));
                var url = (window.TODO && window.TODO.join) ? window.TODO.join(ajaxDir, 'user-session-handler.php') : (ajaxDir.replace(/\/+$/, '') + '/user-session-handler.php');
                xhr.open('POST', url, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.onload = function () {
                    viewUserCenterButton.disabled = false;
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp.success && resp.redirect_url) {
                            window.open(resp.redirect_url, '_blank');
                        } else {
                            var errorMsg = '操作失败：' + (resp.error || '未知错误');
                            TodoCMS.Utils.showMessage(errorMsg, 'error');
                        }
                    } catch (e) {
                        TodoCMS.Utils.showMessage('服务器响应异常。', 'error');
                    }
                };
                xhr.onerror = function () {
                    viewUserCenterButton.textContent = originalText;
                    viewUserCenterButton.disabled = false;
                };
                xhr.send('action=impersonate_user&user_id=' + encodeURIComponent(userId) + '&_csrf_token=' + encodeURIComponent(csrfToken));
            });
        }
    });
</script>
