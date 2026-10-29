<?php
/**
 * 保存语言配置 AJAX 处理器（新方案）
 *
 * @package TodoCMS
 * @subpackage Language
 */

ini_set('display_errors', 0);
error_reporting(0);
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_PARSE, E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => '服务器内部错误：' . $error['message'],
            'file' => defined('ABSPATH') ? str_replace(ABSPATH, '', $error['file']) : $error['file'],
            'line' => $error['line']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

if (!defined('DOING_AJAX') || !DOING_AJAX) {
    if (
        !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
    ) {
        http_response_code(403);
        echo json_encode(['error' => 'Direct access denied']);
        exit;
    }
}

// 环境引导：确保核心函数已加载（支持直接访问该文件的AJAX请求）
if (!function_exists('todo_current_user_can')) {
    // 定义基础路径
    if (!defined('ABSPATH')) {
        define('ABSPATH', rtrim(dirname(dirname(dirname(dirname(__FILE__)))), '/') . '/');
    }
    // 定义管理后台常量
    if (!defined('IS_ADMIN')) {
        define('IS_ADMIN', true);
    }
    if (!defined('TODO_ADMIN')) {
        define('TODO_ADMIN', true);
    }
    // 定义 AJAX 常量，保证后续逻辑一致
    if (!defined('DOING_AJAX')) {
        define('DOING_AJAX', true);
    }
    // 确保会话已启动（必须在加载配置之前）
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    // 加载配置与核心引导
    if (file_exists(ABSPATH . 'config.php')) {
        require_once ABSPATH . 'config.php';
    }
    if (file_exists(ABSPATH . 'core/bootstrap.php')) {
        require_once ABSPATH . 'core/bootstrap.php';
        if (file_exists(ABSPATH . 'core/functions/load_functions.php')) {
            require_once ABSPATH . 'core/functions/load_functions.php';
        }
    } else {
        if (file_exists(ABSPATH . 'core/functions/load_functions.php')) {
            require_once ABSPATH . 'core/functions/load_functions.php';
        }
    }
}

// 确保会话已启动（双重保险）
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

if (!function_exists('theme_exists')) {
    $theme_fn = defined('ABSPATH') ? (ABSPATH . 'core/functions/theme.php') : null;
    if ($theme_fn && file_exists($theme_fn)) {
        require_once $theme_fn;
    }
}

// 权限校验：需要系统选项管理权限（与页面权限保持一致）
$has_permission = false;

// 确保用户已登录
$is_logged_in = false;
if (function_exists('is_user_logged_in')) {
    $is_logged_in = is_user_logged_in();
}

// 如果未登录，直接拒绝
if (!$is_logged_in) {
    http_response_code(403);
    echo json_encode(['error' => 'Not logged in', 'debug' => 'is_user_logged_in: false']);
    exit;
}

// 检查权限（与语言管理页面保持一致，使用 manage_options）
if (function_exists('current_user_can')) {
    $has_permission = current_user_can('manage_options');
}
// 如果没有 current_user_can，尝试使用 todo_current_user_can
if (!$has_permission && function_exists('todo_current_user_can')) {
    $has_permission = todo_current_user_can('manage_options');
}

// 如果仍然没有权限，尝试检查是否是管理员角色
if (!$has_permission) {
    // 尝试通过检查用户角色来判断
    if (function_exists('todo_get_current_user')) {
        $user = todo_get_current_user();
        if ($user) {
            $roles = array();
            if (is_array($user)) {
                $roles_raw = isset($user['roles']) ? $user['roles'] : array();
                $roles = is_array($roles_raw) ? $roles_raw : (empty($roles_raw) ? array() : array($roles_raw));
            } elseif (is_object($user)) {
                $roles_raw = isset($user->roles) ? $user->roles : array();
                $roles = is_array($roles_raw) ? $roles_raw : (empty($roles_raw) ? array() : array($roles_raw));
            }
            // 检查是否是管理员
            if (in_array('administrator', $roles) || in_array('admin', $roles)) {
                $has_permission = true;
            }
        }
    }
}

if (!$has_permission) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied', 'debug' => 'User does not have manage_options capability']);
    exit;
}

// CSRF验证：统一使用 language_management
try {
    if (!class_exists('Todo_Security_Manager')) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '安全模块未加载']);
        exit;
    }
    if (!Todo_Security_Manager::verify_request_csrf('language_management')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid nonce']);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

// -----------------------------------------------------------------------------
// 路由：处理各类保存动作
// -----------------------------------------------------------------------------
$action = isset($_POST['action']) ? sanitize_text_field($_POST['action']) : '';

try {
    switch ($action) {
        case 'update_language_status':
            $locale = canonical_locale($_POST['locale'] ?? '');
            $enabled = isset($_POST['enabled']) ? (intval($_POST['enabled']) === 1) : true;
            if ($locale === '') {
                throw new Exception('缺少 locale');
            }
            $ok = todo_update_language_enabled($locale, $enabled);
            echo json_encode(['success' => (bool) $ok, 'message' => $ok ? '已更新启用状态' : '更新失败']);
            break;

        case 'set_default_locale':
            $locale = canonical_locale($_POST['locale'] ?? '');
            if ($locale === '') {
                throw new Exception('缺少 locale');
            }
            $ok = todo_set_default_locale($locale);
            echo json_encode(['success' => (bool) $ok, 'message' => $ok ? '已设置默认语言' : '设置失败']);
            break;

        case 'set_fallback_locale':
            $locale = canonical_locale($_POST['locale'] ?? '');
            if ($locale === '') {
                throw new Exception('缺少 locale');
            }
            $ok = todo_set_fallback_locale($locale);
            echo json_encode(['success' => (bool) $ok, 'message' => $ok ? '已设置回退语言' : '设置失败']);
            break;

        case 'set_routing_mode':
            $mode = sanitize_text_field($_POST['routing_mode'] ?? '');
            if ($mode === '') {
                throw new Exception('缺少 routing_mode');
            }
            $ok = todo_set_routing_mode($mode);
            echo json_encode(['success' => (bool) $ok, 'message' => $ok ? '已更新路由模式' : '设置失败']);
            break;

        case 'update_language_mapping':
            $locale = canonical_locale($_POST['locale'] ?? '');
            if ($locale === '') {
                throw new Exception('缺少 locale');
            }
            $updates = [];
            if (isset($_POST['domain'])) {
                $updates['domain'] = sanitize_text_field($_POST['domain']);
            }
            if (isset($_POST['path_prefix'])) {
                $updates['path_prefix'] = sanitize_text_field($_POST['path_prefix']);
            }
            $ok = todo_update_language_mapping($locale, $updates);
            echo json_encode(['success' => (bool) $ok, 'message' => $ok ? '已更新映射' : '更新失败，可能重复或无效']);
            break;

        case 'add_language':
            $data = [
                'mark' => sanitize_text_field($_POST['mark'] ?? ''),
                'locale' => sanitize_text_field($_POST['locale'] ?? ''),
                'title' => sanitize_text_field($_POST['title'] ?? ''),
                'path_prefix' => sanitize_text_field($_POST['path_prefix'] ?? ''),
                'domain' => sanitize_text_field($_POST['domain'] ?? ''),
                'enabled' => intval($_POST['enabled'] ?? 1) === 1,
                'parent_id' => isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? sanitize_text_field($_POST['parent_id']) : null,
                'sort_order' => isset($_POST['sort_order']) ? intval($_POST['sort_order']) : 0,
            ];
            $result = todo_add_language($data);
            if ($result === true) {
                echo json_encode(['success' => true, 'message' => '已新增语言']);
            } else {
                $error_msg = is_string($result) ? $result : '新增失败，可能重复或格式不合法';
                echo json_encode(['success' => false, 'message' => $error_msg]);
            }
            break;

        case 'update_language_row':
            $locale = sanitize_text_field($_POST['locale'] ?? '');
            if (!$locale) {
                throw new Exception('缺少 locale');
            }
            $ok = true;
            $messages = array();

            // 1) 排序
            if (isset($_POST['sort_order'])) {
                $sort_order = intval($_POST['sort_order']);
                if (todo_update_language_sort_order($locale, $sort_order)) {
                    $messages[] = '排序已更新';
                } else {
                    $ok = false;
                    $messages[] = '排序更新失败';
                }
            }

            // 2) 前缀/域名映射
            $updates = array();
            if (isset($_POST['path_prefix'])) {
                $updates['path_prefix'] = trim($_POST['path_prefix']);
            }
            if (isset($_POST['domain'])) {
                $updates['domain'] = trim($_POST['domain']);
            }
            if (!empty($updates)) {
                if (todo_update_language_mapping($locale, $updates)) {
                    $messages[] = '映射已更新';
                } else {
                    $ok = false;
                    $messages[] = '映射更新失败：可能有重复';
                }
            }

            // 3) 主题映射 — 全局 language_theme_map 为主数据源，站点专属映射同步写入
            if (isset($_POST['theme'])) {
                $theme = trim($_POST['theme']);
                $locNorm = str_replace('_', '-', $locale);
                if (function_exists('canonical_locale')) { $locNorm = canonical_locale($locNorm); }

                $default_theme_slug = CMS_Theme::get_active_theme()->get_stylesheet();
                if ($theme === $default_theme_slug) { $theme = ''; }

                $valid = true;
                if ($theme !== '' && (!preg_match('/^[A-Za-z0-9_-]+$/', $theme) || !theme_exists($theme))) {
                    $ok = false;
                    $messages[] = '主题不存在';
                    $valid = false;
                }

                if ($valid) {
                    // 全局映射（始终写入）
                    $global_map = get_option('language_theme_map', array());
                    if (is_string($global_map)) {
                        $decoded = @json_decode($global_map, true);
                        $global_map = is_array($decoded) ? $decoded : array();
                    }
                    if ($theme === '') {
                        unset($global_map[$locNorm]);
                    } else {
                        $global_map[$locNorm] = $theme;
                    }
                    update_option('language_theme_map', json_encode($global_map));

                    $messages[] = '主题映射已处理';
                }
            }

            // 4) 启用状态
            if (isset($_POST['enabled'])) {
                $enabled = (int) $_POST['enabled'];
                if (todo_update_language_enabled($locale, $enabled)) {
                    $messages[] = '状态已更新';
                } else {
                    $ok = false;
                    $messages[] = '状态更新失败';
                }
            }

            if ($ok) {
                // 成功后重新初始化注册中心，以便后续逻辑看到新数据
                $reg = LanguageRegistry::get_instance();
                if ($reg) $reg->init();
            }

            echo json_encode(['success' => (bool) $ok, 'message' => implode('；', $messages)]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => '未知的操作：' . $action]);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
