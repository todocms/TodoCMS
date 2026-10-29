<?php
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

if (!current_user_can('manage_options')) {
    todo_die('您没有足够的权限访问此页面。');
}

/**
 * @package TodoCMS
 * @version 1.0.0
 */

$roles = array(
    'administrator' => '管理员',
    'editor' => '编辑',
    'author' => '作者',
);

$modules = array(
    'stats' => array(
        'label' => '统计与概览',
        'desc' => '控制看板统计与概览数据查看。',
        'caps' => array('view_stats'),
    ),
    'posts' => array(
        'label' => '文章与内容',
        'desc' => '控制文章查看、撰写、发布与编辑删除。',
        'caps' => array('edit_posts', 'publish_posts', 'edit_published_posts', 'delete_posts', 'unfiltered_html'),
    ),
    'categories' => array(
        'label' => '栏目与标签',
        'desc' => '控制分类栏目、Tags标签及文档属性。',
        'caps' => array('manage_categories'),
    ),
    'media' => array(
        'label' => '媒体',
        'desc' => '控制媒体库与上传功能。',
        'caps' => array('upload_files', 'manage_media'),
    ),
    'comments' => array(
        'label' => '评论与互动',
        'desc' => '控制评论审核、留言/订阅等互动管理。',
        'caps' => array('moderate_comments', 'manage_comments'),
    ),
    'ads' => array(
        'label' => '广告与友链',
        'desc' => '控制广告管理、广告分组及友情链接。',
        'caps' => array('manage_ads'),
    ),
    'users' => array(
        'label' => '用户管理',
        'desc' => '控制用户列表、新增、编辑与删除。',
        'caps' => array('list_users', 'create_users', 'edit_users', 'delete_users', 'promote_users', 'manage_users'),
    ),
    'user_extended' => array(
        'label' => '用户扩展功能',
        'desc' => '控制用户等级、升级套餐及积分系统。',
        'caps' => array('manage_user_levels', 'manage_upgrade_packages', 'manage_points'),
    ),
    'themes' => array(
        'label' => '主题与外观',
        'desc' => '控制主题管理与外观配置。',
        'caps' => array('switch_themes', 'edit_themes', 'edit_theme_options', 'customize', 'manage_themes'),
    ),
    'plugins' => array(
        'label' => '插件与扩展',
        'desc' => '控制插件管理、安装与启用。',
        'caps' => array('manage_plugins', 'install_plugins', 'activate_plugins', 'update_plugins', 'delete_plugins'),
    ),
    'models' => array(
        'label' => '模型',
        'desc' => '控制模型与字段管理相关功能。',
        'caps' => array('manage_models', 'edit_models'),
    ),
    'languages' => array(
        'label' => '语言',
        'desc' => '控制语言包与翻译管理相关功能。',
        'caps' => array('manage_languages'),
    ),
    'system' => array(
        'label' => '系统设置与工具',
        'desc' => '控制系统设置、核心更新、工具与高级配置。',
        'caps' => array('manage_options', 'manage_seo', 'manage_global_vars', 'manage_api', 'update_core', 'manage_menus', 'manage_mail', 'manage_sites', 'manage_cache', 'manage_trash', 'import', 'export'),
    ),
);

$module_caps = array();
foreach ($modules as $m) {
    foreach (($m['caps'] ?? array()) as $cap) {
        if (is_string($cap) && $cap !== '') {
            $module_caps[$cap] = true;
        }
    }
}

$selected_role = isset($_GET['role']) ? (string) $_GET['role'] : 'editor';
if (!isset($roles[$selected_role])) {
    $selected_role = 'editor';
}

$current_roles_option = get_option('cms_roles', array());
if (!is_array($current_roles_option)) {
    $current_roles_option = array();
}

$default_caps_map = array();
if (function_exists('get_role_capabilities')) {
    foreach ($roles as $rid => $rname) {
        $caps = get_role_capabilities($rid);
        $default_caps_map[$rid] = is_array($caps) ? $caps : array();
    }
}

$ensure_role_option = function ($rid, $rname, $existing) use ($default_caps_map) {
    $entry = is_array($existing) ? $existing : array();
    if (!isset($entry['name']) || !is_string($entry['name']) || $entry['name'] === '') {
        $entry['name'] = $rname;
    }
    if (!isset($entry['capabilities']) || !is_array($entry['capabilities'])) {
        $entry['capabilities'] = isset($default_caps_map[$rid]) ? $default_caps_map[$rid] : array();
    }
    return $entry;
};

foreach ($roles as $rid => $rname) {
    $current_roles_option[$rid] = $ensure_role_option($rid, $rname, $current_roles_option[$rid] ?? array());
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_access_settings') {
    if (!class_exists('Todo_Security_Manager') || !Todo_Security_Manager::verify_request_csrf('access_settings_action', '_todononce')) {
        todo_die('安全验证失败');
    }

    $posted_role = isset($_POST['role']) ? (string) $_POST['role'] : '';
    if (!isset($roles[$posted_role])) {
        todo_die('非法角色');
    }

    if ($posted_role === 'administrator') {
        if (function_exists('todo_set_flash_message')) {
            todo_set_flash_message('管理员角色默认拥有全部权限，不支持在此页面修改。', 'warning');
        }
        todo_redirect(todo_admin_url('index.php?page=access-settings&role=administrator' . (todo_is_iframe_mode() ? '&format=iframe' : '')));
        exit;
    }

    $is_reset = (isset($_POST['action_type']) && $_POST['action_type'] === 'reset') || (isset($_POST['reset_role']) && $_POST['reset_role'] === '1');

    if ($is_reset) {
        $default_caps = function_exists('get_role_capabilities') ? get_role_capabilities($posted_role) : array();
        if (!is_array($default_caps)) {
            $default_caps = array();
        }
        $default_caps['access_admin'] = true;

        $current_roles_option[$posted_role]['capabilities'] = $default_caps;

        update_option('cms_roles', $current_roles_option, 'security');

        if (class_exists('Todo_User_Manager') && method_exists('Todo_User_Manager', 'flush_permissions_cache')) {
            Todo_User_Manager::flush_permissions_cache();
        }

        if (function_exists('todo_set_flash_message')) {
            $role_label = $roles[$posted_role] ?? $posted_role;
            todo_set_flash_message("已成功重置“{$role_label}”角色的权限为系统默认配置。", 'success');
        }

        todo_redirect(todo_admin_url('index.php?page=access-settings&role=' . urlencode($posted_role) . (todo_is_iframe_mode() ? '&format=iframe' : '')));
        exit;
    }

    $posted_modules = isset($_POST['modules']) ? $_POST['modules'] : array();
    if (!is_array($posted_modules)) {
        $posted_modules = array();
    }
    $posted_modules = array_values(array_unique(array_filter(array_map(function ($v) {
        return is_string($v) ? trim($v) : '';
    }, $posted_modules))));

    $existing_caps = $current_roles_option[$posted_role]['capabilities'] ?? array();
    if (!is_array($existing_caps)) {
        $existing_caps = array();
    }

    foreach ($modules as $m) {
        foreach (($m['caps'] ?? array()) as $cap) {
            if (is_string($cap) && $cap !== '') {
                unset($existing_caps[$cap]);
            }
        }
    }

    foreach ($posted_modules as $mid) {
        if (!isset($modules[$mid]) || !is_array($modules[$mid])) {
            continue;
        }
        foreach (($modules[$mid]['caps'] ?? array()) as $cap) {
            if (is_string($cap) && $cap !== '') {
                $existing_caps[$cap] = true;
            }
        }
    }

    $existing_caps['access_admin'] = true;

    $current_roles_option[$posted_role]['capabilities'] = $existing_caps;

    update_option('cms_roles', $current_roles_option, 'security');

    if (class_exists('Todo_User_Manager') && method_exists('Todo_User_Manager', 'flush_permissions_cache')) {
        Todo_User_Manager::flush_permissions_cache();
    }

    if (function_exists('todo_set_flash_message')) {
        todo_set_flash_message('访问设置已保存。', 'success');
    }

    todo_redirect(todo_admin_url('index.php?page=access-settings&role=' . urlencode($posted_role) . (todo_is_iframe_mode() ? '&format=iframe' : '')));
    exit;
}

$flash = function_exists('todo_get_flash_message') ? todo_get_flash_message() : null;
$selected_caps = $current_roles_option[$selected_role]['capabilities'] ?? array();
if (!is_array($selected_caps)) {
    $selected_caps = array();
}
$selected_modules = array();
foreach ($modules as $mid => $m) {
    $enabled = false;
    foreach (($m['caps'] ?? array()) as $cap) {
        if (isset($selected_caps[$cap]) && $selected_caps[$cap]) {
            $enabled = true;
            break;
        }
    }
    if ($enabled) {
        $selected_modules[$mid] = true;
    }
}
?>

<div class="admin-user-new-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">访问设置</h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=users' . (todo_is_iframe_mode() ? '&format=iframe' : '')); ?>" class="button button-secondary">返回用户列表</a>
        </div>
    </div>

    <?php if ($flash && !empty($flash['message'])): ?>
        <div class="notice notice-<?php echo esc_attr($flash['type'] ?? 'success'); ?> is-dismissible">
            <p><?php echo esc_html($flash['message']); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <div class="admin-card single-mode">
        <div class="admin-card-header">
            <h3 class="admin-card-title">权限配置</h3>
        </div>
        <div class="admin-card-content">
            <form method="get" action="<?php echo todo_admin_url('index.php'); ?>" class="form-row" style="margin-bottom: 15px;">
                <input type="hidden" name="page" value="access-settings">
                <?php if (todo_is_iframe_mode()): ?>
                    <input type="hidden" name="format" value="iframe">
                <?php endif; ?>
                <div class="form-group">
                    <label for="role">选择角色</label>
                    <select name="role" id="role" class="regular-text" onchange="this.form.submit()">
                        <?php foreach ($roles as $rid => $rname): ?>
                            <option value="<?php echo esc_attr($rid); ?>" <?php echo $rid === $selected_role ? 'selected' : ''; ?>>
                                <?php echo esc_html($rname); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">为不同后台角色配置可访问的模块，“订阅者”无后台权限不在配置范围内。</p>
                </div>
            </form>

            <form method="post" action="<?php echo todo_admin_url('index.php?page=access-settings&role=' . urlencode($selected_role) . (todo_is_iframe_mode() ? '&format=iframe' : '')); ?>">
                <?php if (class_exists('Todo_Security_Manager')): ?>
                    <?php echo Todo_Security_Manager::csrf_field('access_settings_action', '_todononce'); ?>
                <?php else: ?>
                    <?php if (function_exists('todo_nonce_field')) todo_nonce_field('access_settings_action', '_todononce'); ?>
                <?php endif; ?>
                <input type="hidden" name="action" value="save_access_settings">
                <input type="hidden" name="role" value="<?php echo esc_attr($selected_role); ?>">

                <?php if ($selected_role === 'administrator'): ?>
                    <div class="notice notice-warning">
                        <p>管理员角色默认拥有全部权限，不支持在此页面修改。</p>
                    </div>
                <?php endif; ?>

                <div class="access-settings-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div>
                        <div class="admin-card-header no-border" style="opacity: 0.5;">
                            <h3 class="admin-card-title">后台访问</h3>
                        </div>
                        <div class="form-group" style="margin-bottom: 12px;">
                            <label>
                                <input type="checkbox" checked disabled>
                                <span>
                                    <strong>访问后台</strong>
                                    <span style="color: #999; font-size: 12px; display: block; margin-left: 25px;">允许进入管理后台页面（强制开启：access_admin）</span>
                                </span>
                            </label>
                        </div>
                    </div>
                    <?php foreach ($modules as $mid => $m): ?>
                        <?php
                        $checked = isset($selected_modules[$mid]) && $selected_modules[$mid];
                        $disabled = ($selected_role === 'administrator');
                        $caps_text = '';
                        if (!empty($m['caps']) && is_array($m['caps'])) {
                            $caps_text = implode(', ', array_values(array_filter(array_map('strval', $m['caps']))));
                        }
                        ?>
                        <div>
                            <div class="admin-card-header no-border" style="opacity: 0.5;">
                                <h3 class="admin-card-title"><?php echo esc_html($m['label'] ?? $mid); ?></h3>
                            </div>
                            <div class="form-group" style="margin-bottom: 12px;">
                                <label>
                                    <input type="checkbox" name="modules[]" value="<?php echo esc_attr($mid); ?>" <?php echo $checked ? 'checked' : ''; ?> <?php echo $disabled ? 'disabled' : ''; ?>>
                                    <span>
                                        <strong><?php echo esc_html($m['desc'] ?? ''); ?></strong>
                                        <span style="color: #999; font-size: 12px; display: block; margin-left: 25px;">
                                            <?php echo $caps_text !== '' ? esc_html('关联权限：' . $caps_text) : ''; ?>
                                        </span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="form-submit" style="display: flex; gap: 10px; align-items: center; margin-top: 20px;">
                    <button type="submit" name="action_type" value="save" class="button button-primary" <?php echo $selected_role === 'administrator' ? 'disabled' : ''; ?>>保存设置</button>
                    <button type="submit" name="action_type" value="reset" class="button button-secondary" onclick="event.preventDefault(); var btn = this; TodoModal.confirm({ title: '确认重置', message: '确定要将“<?php echo esc_js($roles[$selected_role] ?? $selected_role); ?>”角色的权限重置为系统默认配置吗？', type: 'warning', confirmText: '确认重置', onConfirm: function() { var form = btn.form; if (!form) return; var input = document.createElement('input'); input.type = 'hidden'; input.name = btn.name; input.value = btn.value; form.appendChild(input); form.submit(); } }); return false;" <?php echo $selected_role === 'administrator' ? 'disabled' : ''; ?>>重置角色权限</button>
                </div>
            </form>
        </div>
    </div>
</div>
