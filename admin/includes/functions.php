<?php
/**
 * TodoCMS 后台函数库
 */

// 防止直接访问（但允许AJAX请求）
if (!defined('ABSPATH') && !defined('DOING_AJAX')) {
    exit('Direct access denied.');
}

if (!defined('TODO_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION') && !defined('DOING_AJAX')) {
    exit('Direct access not permitted');
}

/**
 * 添加后台动作钩子
 * 
 * @param string $hook 钩子名称
 * @param callable $callback 回调函数
 * @param int $priority 优先级
 */
function todo_add_admin_action($hook, $callback, $priority = 10)
{
    global $todo_admin_actions;

    if (!isset($todo_admin_actions[$hook])) {
        $todo_admin_actions[$hook] = array();
    }

    $todo_admin_actions[$hook][] = array(
        'callback' => $callback,
        'priority' => $priority
    );
}

/**
 * 执行后台动作钩子
 * 
 * @param string $hook 钩子名称
 * @param mixed $args 传递给回调函数的参数
 */
function todo_do_admin_action($hook, $args = null)
{
    global $todo_admin_actions;

    if (!empty($todo_admin_actions[$hook])) {
        // 按优先级排序
        usort($todo_admin_actions[$hook], function ($a, $b) {
            return $a['priority'] - $b['priority'];
        });

        // 执行回调
        foreach ($todo_admin_actions[$hook] as $action) {
            call_user_func($action['callback'], $args);
        }
    }
}

/**
 * 加载后台样式
 */
function todo_enqueue_admin_style($handle, $src, $deps = array(), $ver = false)
{
    global $todo_admin_styles;

    $todo_admin_styles[$handle] = array(
        'src' => $src,
        'deps' => $deps,
        'ver' => $ver
    );
}

/**
 * 输出后台样式
 */
function todo_output_admin_styles()
{
    global $todo_admin_styles;

    if (!empty($todo_admin_styles)) {
        foreach ($todo_admin_styles as $handle => $style) {
            $version = $style['ver'] ? '?ver=' . $style['ver'] : '';
            echo '<link rel="stylesheet" href="' . $style['src'] . $version . '">' . "\n";
        }
    }
}

/**
 * 转义HTML属性值
 */
if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * 转义HTML内容
 */
if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('todo_admin_render_sort_th')) {
    function todo_admin_render_sort_th($label, $orderby_key, $th_class = '', $args = array())
    {
        $query = isset($args['query']) && is_array($args['query']) ? $args['query'] : (isset($_GET) && is_array($_GET) ? $_GET : array());
        $base_url = isset($args['base_url']) ? (string) $args['base_url'] : (function_exists('todo_admin_url') ? todo_admin_url('index.php') : 'index.php');
        $style_attr = '';
        if (isset($args['style'])) {
            $style_value = (string) $args['style'];
            $style_value = trim($style_value);
            if (stripos($style_value, 'style=') === 0) {
                $style_value = trim(substr($style_value, 6));
            }
            $style_value = trim($style_value, " \t\n\r\0\x0B\"'");
            if ($style_value !== '') {
                $style_attr = ' style="' . esc_attr($style_value) . '"';
            }
        }
        $reset_paged = !isset($args['reset_paged']) || $args['reset_paged'] !== false;

        $current_orderby = isset($args['current_orderby']) ? (string) $args['current_orderby'] : (isset($query['orderby']) ? (string) $query['orderby'] : '');
        $current_order = isset($args['current_order']) ? (string) $args['current_order'] : (isset($query['order']) ? (string) $query['order'] : 'asc');
        $current_order = strtolower($current_order) === 'desc' ? 'desc' : 'asc';

        $asc_query = $query;
        $desc_query = $query;
        if ($reset_paged) {
            unset($asc_query['paged'], $desc_query['paged']);
        }
        $asc_query['orderby'] = $orderby_key;
        $asc_query['order'] = 'asc';
        $desc_query['orderby'] = $orderby_key;
        $desc_query['order'] = 'desc';

        if (function_exists('add_query_arg')) {
            $asc_url = add_query_arg($asc_query, $base_url);
            $desc_url = add_query_arg($desc_query, $base_url);
        } else {
            $asc_url = $base_url . (strpos($base_url, '?') !== false ? '&' : '?') . http_build_query($asc_query);
            $desc_url = $base_url . (strpos($base_url, '?') !== false ? '&' : '?') . http_build_query($desc_query);
        }

        $asc_on = ($current_orderby === (string) $orderby_key && $current_order === 'asc');
        $desc_on = ($current_orderby === (string) $orderby_key && $current_order === 'desc');

        $out = '<th' . $style_attr . ' class="' . esc_attr($th_class) . '">';
        $out .= esc_html($label);
        $out .= '<div class="tosort"><span class="sortbtn">';
        $out .= '<a href="' . esc_url($asc_url) . '"><i' . ($asc_on ? ' class="sorton"' : '') . '>▲</i></a>';
        $out .= '<a href="' . esc_url($desc_url) . '"><i' . ($desc_on ? ' class="sorton"' : '') . '>▼</i></a>';
        $out .= '</span></div>';
        $out .= '</th>';

        return $out;
    }
}

/**
 * 显示管理后台通知
 * 
 * @param string $message 通知消息
 * @param string $type 通知类型 (success, error, warning, info)
 * @param bool $dismissible 是否可关闭
 */
function todo_admin_notice($message, $type = 'info', $dismissible = true)
{
    $class = 'notice notice-' . $type;
    if ($dismissible) {
        $class .= ' is-dismissible';
    }

    echo '<div class="' . esc_attr($class) . '">';
    echo '<p>' . esc_html($message) . '</p>';
    if ($dismissible) {
        echo '<button type="button" class="notice-dismiss">✕</button>';
    }
    echo '</div>';
}

/**
 * 生成CSRF令牌字段
 * 
 * @param string $action 动作名称
 * @param string $name 字段名称
 * @param bool $referer 是否包含referer检查
 * @return string HTML字段
 */
if (!function_exists('todo_csrf_token_field')) {
    function todo_csrf_token_field($action, $name = '_todononce', $referer = true)
    {
        // 检查是否有Todo_Security_Manager类
        if (class_exists('Todo_Security_Manager')) {
            return Todo_Security_Manager::csrf_field($action, $name, $referer);
        }

        // 如果没有安全管理器，使用简单的令牌生成
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $token = md5(uniqid(rand(), true));
        $_SESSION['todo_csrf_tokens'][$action] = $token;

        $field = '<input type="hidden" name="' . $name . '" value="' . $token . '">';

        if ($referer) {
            $field .= '<input type="hidden" name="_todoref" value="' . (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '') . '">';
        }

        return $field;
    }
}

/**
 * 检查用户是否有编辑商城设置的权限
 * 
 * @return bool
 */
function current_user_can_edit_shop_settings()
{
    // 检查是否登录
    if (!is_user_logged_in()) {
        return false;
    }

    // 检查基本权限
    if (!current_user_can('manage_options')) {
        return false;
    }

    // 检查用户角色/状态（兼容数组/对象）
    $user = todo_get_current_user();
    $allowed_roles = array('administrator', 'shop_manager');

    $roles = array();
    $status = 0;
    if (is_array($user)) {
        $roles_raw = isset($user['roles']) ? $user['roles'] : array();
        $roles = is_array($roles_raw) ? $roles_raw : (empty($roles_raw) ? array() : array($roles_raw));
        $status = isset($user['user_status']) ? intval($user['user_status']) : 0;
    } elseif (is_object($user)) {
        $roles_raw = isset($user->roles) ? $user->roles : array();
        $roles = is_array($roles_raw) ? $roles_raw : (empty($roles_raw) ? array() : array($roles_raw));
        $status = isset($user->user_status) ? intval($user->user_status) : 0;
    }

    if (!array_intersect($allowed_roles, $roles)) {
        return false;
    }

    if ($status !== 0) {
        return false;
    }

    return true;
}

/**
 * 重写current_user_can函数以支持自定义权限
 */
if (!function_exists('current_user_can')) {
    function current_user_can($capability)
    {
        // 优先使用 Todo_User_Manager::user_can() 进行权限检查（性能优化后统一使用）
        if (class_exists('Todo_User_Manager')) {
            // 特殊权限处理
            if ($capability === 'edit_shop_settings') {
                return current_user_can_edit_shop_settings();
            }

            // 使用统一的权限检查方法
            $result = Todo_User_Manager::user_can($capability);
            return $result;
        } else {
            error_log("   - Todo_User_Manager 类不存在，使用回退逻辑");
        }

        // 回退到原有逻辑（兼容性）
        if ($capability === 'edit_shop_settings') {
            return current_user_can_edit_shop_settings();
        }

        // 其他权限检查逻辑
        if (!is_user_logged_in()) {
            return false;
        }

        $user = todo_get_current_user();
        $user_id = get_current_user_id();

        // 兼容数组/对象安全读取角色
        $roles = array();

        // 方法1：从用户对象的 roles 字段获取
        if (is_array($user)) {
            // 优先从 roles 字段获取
            if (isset($user['roles'])) {
                $roles_raw = $user['roles'];
            } elseif (isset($user['role'])) {
                // 回退到单个 role 字段
                $roles_raw = $user['role'];
            } elseif (isset($user['meta']['roles'])) {
                // 从 meta 数组中获取
                $roles_raw = $user['meta']['roles'];
            } elseif (isset($user['meta']['user_role'])) {
                // 从 meta 数组中获取 user_role
                $roles_raw = $user['meta']['user_role'];
            } else {
                $roles_raw = null;
            }
        } elseif (is_object($user)) {
            // 优先从 roles 属性获取
            if (isset($user->roles)) {
                $roles_raw = $user->roles;
            } elseif (isset($user->role)) {
                // 回退到单个 role 属性
                $roles_raw = $user->role;
            } elseif (isset($user->meta) && is_array($user->meta) && isset($user->meta['roles'])) {
                // 从 meta 对象中获取
                $roles_raw = $user->meta['roles'];
            } elseif (isset($user->meta) && is_array($user->meta) && isset($user->meta['user_role'])) {
                // 从 meta 对象中获取 user_role
                $roles_raw = $user->meta['user_role'];
            } else {
                $roles_raw = null;
            }
        }

        // 处理角色数据（可能是数组、字符串或序列化字符串）
        if ($roles_raw !== null) {
            if (is_string($roles_raw) && strpos($roles_raw, 'a:') === 0) {
                // 序列化的数组
                $unserialized = @unserialize($roles_raw);
                if ($unserialized !== false) {
                    $roles_raw = $unserialized;
                }
            }
            if (is_array($roles_raw)) {
                $roles = $roles_raw;
            } elseif (is_string($roles_raw) && !empty($roles_raw)) {
                $roles = array($roles_raw);
            }
        }

        // 如果从 role 字段获取了角色，也要检查 meta.roles 并合并
        if (is_array($user) && isset($user['meta']['roles'])) {
            $meta_roles = $user['meta']['roles'];
            if (is_array($meta_roles)) {
                $roles = array_unique(array_merge($roles, $meta_roles));
            } elseif (is_string($meta_roles) && !empty($meta_roles)) {
                if (!in_array($meta_roles, $roles)) {
                    $roles[] = $meta_roles;
                }
            }
        } elseif (is_object($user) && isset($user->meta) && is_array($user->meta) && isset($user->meta['roles'])) {
            $meta_roles = $user->meta['roles'];
            if (is_array($meta_roles)) {
                $roles = array_unique(array_merge($roles, $meta_roles));
            } elseif (is_string($meta_roles) && !empty($meta_roles)) {
                if (!in_array($meta_roles, $roles)) {
                    $roles[] = $meta_roles;
                }
            }
        }

        // 方法2：如果仍然没有角色信息，从 user_meta 表查询
        if (empty($roles) && $user_id > 0) {
            global $tododb;
            if ($tododb) {
                try {
                    // 尝试获取 roles（数组）
                    $roles_meta = $tododb->get_var(
                        "SELECT meta_value FROM " . TABLE_PREFIX . "user_meta WHERE user_id = ? AND meta_key = 'roles' LIMIT 1",
                        array($user_id)
                    );

                    if ($roles_meta) {
                        // 处理序列化的数组
                        if (is_string($roles_meta) && strpos($roles_meta, 'a:') === 0) {
                            $unserialized = @unserialize($roles_meta);
                            if ($unserialized !== false) {
                                $roles = is_array($unserialized) ? $unserialized : array($unserialized);
                            } else {
                                $roles = array($roles_meta);
                            }
                        } elseif (is_string($roles_meta) && strpos($roles_meta, '[') === 0) {
                            // JSON 格式
                            $decoded = @json_decode($roles_meta, true);
                            if (is_array($decoded)) {
                                $roles = $decoded;
                            } else {
                                $roles = array($roles_meta);
                            }
                        } else {
                            $roles = array($roles_meta);
                        }
                    } else {
                        // 回退：尝试获取 user_role（单个值）
                        $user_role = $tododb->get_var(
                            "SELECT meta_value FROM " . TABLE_PREFIX . "user_meta WHERE user_id = ? AND meta_key = 'user_role' LIMIT 1",
                            array($user_id)
                        );
                        if ($user_role) {
                            $roles = array($user_role);
                        }
                    }
                } catch (Exception $e) {
                    error_log('获取用户角色失败: ' . $e->getMessage());
                }
            }
        }

        // 如果仍然没有角色，默认为 subscriber
        if (empty($roles)) {
            $roles = array('subscriber');
            // 调试：记录为什么没有找到角色
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                error_log("current_user_can: 用户 {$user_id} 没有找到角色信息，使用默认角色 subscriber");
                error_log("current_user_can: 用户对象类型: " . (is_array($user) ? 'array' : (is_object($user) ? 'object' : 'null')));
                if ($user) {
                    error_log("current_user_can: 用户对象键: " . (is_array($user) ? implode(', ', array_keys($user)) : (is_object($user) ? implode(', ', array_keys(get_object_vars($user))) : 'N/A')));
                }
            }
        }

        // 管理员拥有所有权限
        if (in_array('administrator', $roles) || in_array('admin', $roles)) {
            return true;
        }

        switch ($capability) {
            case 'access_admin':
                // 允许管理员、编辑、作者访问后台
                $has_access = in_array('administrator', $roles) || in_array('admin', $roles) ||
                    in_array('editor', $roles) || in_array('author', $roles);

                // 如果仍然没有权限，检查 capabilities
                if (!$has_access && isset($user['meta']['capabilities']['access_admin']) && $user['meta']['capabilities']['access_admin']) {
                    $has_access = true;
                }

                // 调试：记录权限检查结果
                if (function_exists('todo_log_debug')) {
                    todo_log_debug('current_user_can(access_admin) 检查', [
                        'user_id' => $user_id,
                        'roles' => $roles,
                        'has_access' => $has_access,
                        'capabilities' => isset($user['meta']['capabilities']['access_admin']) ? $user['meta']['capabilities']['access_admin'] : 'not_set'
                    ]);
                } elseif (defined('TODO_DEBUG') && TODO_DEBUG) {
                    error_log("current_user_can(access_admin): 用户 {$user_id} 角色: " . implode(', ', $roles) . ", 结果: " . ($has_access ? 'true' : 'false'));
                }
                return $has_access;
            case 'manage_options':
                return in_array('administrator', $roles) || in_array('admin', $roles) ||
                    in_array('shop_manager', $roles);
            case 'edit_posts':
                return in_array('administrator', $roles) || in_array('editor', $roles) ||
                    in_array('author', $roles);
            case 'edit_others_posts':
                return in_array('administrator', $roles) || in_array('editor', $roles);
            case 'manage_categories':
                return in_array('administrator', $roles) || in_array('editor', $roles);
            case 'manage_users':
                return in_array('administrator', $roles) || in_array('admin', $roles);
            default:
                return false;
        }
    }
}

// 初始化全局变量
$todo_admin_actions = array();
$todo_admin_styles = array();

// 注册核心钩子
todo_add_admin_action('footer', 'todo_output_admin_styles');

// 全局注册后台通知渲染钩子，使所有后台页面自动显示会话通知
add_action('admin_notices', 'todo_admin_notices');
