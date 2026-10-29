<?php
/**
 * TodoCMS 用户管理菜单
 *
 * @package TodoCMS
 * @subpackage Administration
 */

// 防止直接访问（但允许AJAX请求）
if (!defined('ABSPATH') || (!defined('TODO_LOADED') && !defined('DOING_AJAX'))) {
    exit('Direct access denied');
}

/**
 * 添加用户管理菜单
 */
function todo_add_user_menu()
{
    // 检查用户权限
    if (!current_user_can('manage_users')) {
        return;
    }

    // 添加用户管理主菜单
    add_menu_page(
        '用户管理',           // 页面标题
        '用户管理',           // 菜单标题
        'manage_users',       // 权限要求
        'users',              // 菜单slug
        'todo_users_page',    // 回调函数
        25                    // 位置
    );

    // 添加子菜单
    add_submenu_page(
        'users',              // 父菜单slug
        '所有用户',           // 页面标题
        '所有用户',           // 菜单标题
        'manage_users',       // 权限要求
        'users',              // 菜单slug
        'todo_users_page'     // 回调函数
    );

    add_submenu_page(
        'users',
        '添加用户',
        '添加用户',
        'create_users',
        'user-new',
        'todo_user_new_page'
    );

    add_submenu_page(
        'users',
        '批量添加',
        '批量添加',
        'create_users',
        'user-batch',
        'todo_user_batch_page'
    );

    add_submenu_page(
        'users',
        '用户等级',
        '用户等级',
        'manage_user_levels',
        'user-levels',
        'todo_user_levels_page'
    );

    add_submenu_page(
        'users',
        '升级套餐',
        '升级套餐',
        'manage_upgrade_packages',
        'upgrade-packages',
        'todo_upgrade_packages_page'
    );

    add_submenu_page(
        'users',
        '积分系统',
        '积分系统',
        'manage_points',
        'points-system',
        'todo_points_system_page'
    );
}

/**
 * 用户页面回调函数
 */
function todo_users_page()
{
    include_once ADMIN_DIR . '/pages/user/users.php';
}

function todo_user_new_page()
{
    include_once ADMIN_DIR . '/pages/user/user-new.php';
}

function todo_user_batch_page()
{
    include_once ADMIN_DIR . '/pages/user/user-batch.php';
}

function todo_user_levels_page()
{
    include_once ADMIN_DIR . '/pages/user/user-levels.php';
}

function todo_upgrade_packages_page()
{
    include_once ADMIN_DIR . '/pages/user/upgrade-packages.php';
}

function todo_points_system_page()
{
    include_once ADMIN_DIR . '/pages/user/points-system.php';
}

/**
 * 高亮当前菜单项
 */
function todo_user_menu_highlight($parent_file)
{
    global $pagenow, $plugin_page;

    // 用户相关页面
    $user_pages = array(
        'users',
        'user-new',
        'user-edit',
        'user-batch',
        'user-levels',
        'upgrade-packages',
        'points-system'
    );

    if (isset($_GET['page']) && in_array($_GET['page'], $user_pages)) {
        return 'users';
    }

    return $parent_file;
}

/**
 * 设置用户菜单样式
 */
function todo_user_menu_styles()
{
    global $pagenow;

    if (
        isset($_GET['page']) && in_array($_GET['page'], array(
            'users',
            'user-new',
            'user-edit',
            'user-batch',
            'user-levels',
            'upgrade-packages',
            'points-system'
        ))
    ) {
        ?>
        <style>
            .user-level-badge {
                display: inline-block;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 500;
                color: #fff;
                margin-left: 5px;
            }

            .user-level-badge.level-1 {
                background-color: #999;
            }

            .user-level-badge.level-2 {
                background-color: #46b450;
            }

            .user-level-badge.level-3 {
                background-color: #00a0d2;
            }

            .user-level-badge.level-4 {
                background-color: #826eb4;
            }

            .user-level-badge.level-5 {
                background-color: #d54e21;
            }

            .upgrade-package-status {
                display: inline-block;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 500;
                color: #fff;
            }

            .upgrade-package-status.active {
                background-color: #46b450;
            }

            .upgrade-package-status.inactive {
                background-color: #999;
            }
        </style>
        <?php
    }
}

/**
 * 获取待处理用户等级申请数量
 */
function todo_get_pending_level_requests_count()
{
    global $tododb;

    try {
        $count = $tododb->get_var("
            SELECT COUNT(*) as count 
            FROM " . TABLE_PREFIX . "user_level_records 
            WHERE status = 'pending'
        ");

        return $count ? intval($count) : 0;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * 获取待处理套餐订单数量
 */
function todo_get_pending_package_orders_count()
{
    global $tododb;

    try {
        $count = $tododb->get_var("
            SELECT COUNT(*) as count 
            FROM " . TABLE_PREFIX . "package_purchase_records 
            WHERE status = 'pending'
        ");

        return $count ? intval($count) : 0;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * 在菜单标题中显示待处理数量徽章
 */
function todo_user_menu_badges()
{
    global $menu, $submenu;

    // 获取待处理数量
    $pending_levels = todo_get_pending_level_requests_count();
    $pending_packages = todo_get_pending_package_orders_count();

    // 为用户等级菜单添加徽章
    if ($pending_levels > 0 && isset($submenu['users'])) {
        foreach ($submenu['users'] as $key => $item) {
            if ($item[2] === 'user-levels') {
                $submenu['users'][$key][0] = '用户等级 <span class="awaiting-mod count-' . $pending_levels . '"><span class="pending-count">' . $pending_levels . '</span></span>';
                break;
            }
        }
    }

    // 为升级套餐菜单添加徽章
    if ($pending_packages > 0 && isset($submenu['users'])) {
        foreach ($submenu['users'] as $key => $item) {
            if ($item[2] === 'upgrade-packages') {
                $submenu['users'][$key][0] = '升级套餐 <span class="awaiting-mod count-' . $pending_packages . '"><span class="pending-count">' . $pending_packages . '</span></span>';
                break;
            }
        }
    }
}

// 注册钩子
add_action('admin_menu', 'todo_add_user_menu');
add_filter('parent_file', 'todo_user_menu_highlight');
add_action('admin_head', 'todo_user_menu_styles');
add_action('admin_menu', 'todo_user_menu_badges', 999);

/**
 * 检查用户等级功能是否启用
 */
function todo_is_user_level_enabled()
{
    return get_option('enable_user_levels', true);
}

/**
 * 检查升级套餐功能是否启用
 */
function todo_is_upgrade_package_enabled()
{
    return get_option('enable_upgrade_packages', true);
}