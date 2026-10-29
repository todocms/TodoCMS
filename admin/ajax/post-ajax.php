<?php
/**
 * 文章相关AJAX处理器
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 开启输出缓冲
ob_start();

// 直接访问保护
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}
if (!defined('IS_ADMIN')) {
    define('IS_ADMIN', true);
}

if (!defined('TODO_VERSION')) {
    define('TODO_VERSION', '1.0.0');
}

// 设置基础路径（必须在加载配置文件之前定义）
// 文件位置：admin/ajax/post-ajax.php
// 需要向上两级目录到达项目根目录
if (!defined('ABSPATH')) {
    // 从 admin/ajax/ 向上两级到项目根目录
    define('ABSPATH', rtrim(dirname(dirname(dirname(__FILE__))), '/') . '/');
}

// 检查是否为AJAX请求（宽松检查：允许DOING_AJAX常量或X-Requested-With头）
$is_ajax = defined('DOING_AJAX') && DOING_AJAX;
if (
    !$is_ajax && (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest')
) {
    http_response_code(403);
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

// 检查是否通过统一AJAX入口加载
// 如果已经加载了autoload（通过admin-ajax.php），则不需要重复加载
if (!class_exists('Composer\Autoload\ClassLoader')) {
    // 只有在未加载时才加载autoload（直接访问的情况）
    $autoload_path = ABSPATH . 'vendor/autoload.php';
    if (file_exists($autoload_path)) {
        require_once $autoload_path;
    } else {
        // 如果autoload不存在，说明路径计算错误或项目结构异常
        ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => '系统初始化失败',
            'message' => '无法加载Composer autoload文件',
            'path' => $autoload_path
        ]);
        exit;
    }
}

// 如果通过统一AJAX入口加载，这些已经完成，跳过
// 如果是直接访问，需要完成初始化
if (!defined('TODO_LOADED')) {
    // 定义管理后台常量，防止被包含的文件拒绝访问
    if (!defined('TODO_ADMIN')) {
        define('TODO_ADMIN', true);
    }
    if (!defined('IS_ADMIN')) {
        define('IS_ADMIN', true);
    }
    if (!defined('ADMIN_ACCESS')) {
        define('ADMIN_ACCESS', true);
    }

    // 加载配置文件和引导文件（autoload已加载，constants.php已自动加载）
    require_once ABSPATH . 'config.php';
    require_once ABSPATH . 'core/bootstrap.php';

    // 加载函数加载器
    require_once ABSPATH . 'core/functions/function-loader.php';

    // 使用函数加载器加载核心函数
    if (class_exists('Todo_Function_Loader')) {
        Todo_Function_Loader::load_core_functions();
    }

    // 加载文章翻译服务（包含多语言相关函数）
    $post_translation_file = ABSPATH . 'core/services/post-translation.php';
    if (file_exists($post_translation_file)) {
        require_once $post_translation_file;
    }

    // 定义TODO_LOADED标记，表示核心已加载
    define('TODO_LOADED', true);
}

if (class_exists('Todo_Core_Kernel') && is_callable(['Todo_Core_Kernel', 'check_runtime_license']) && !call_user_func(['Todo_Core_Kernel', 'check_runtime_license'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Direct access denied']);
    exit;
}

// 确保数据库连接已初始化
global $tododb;
if (!isset($tododb) || $tododb === null) {
    if (class_exists('CMS_DB')) {
        try {
            $tododb = CMS_DB::get_instance();
            $GLOBALS['tododb'] = $tododb;
        } catch (Exception $e) {
            error_log('post-ajax.php: 数据库连接初始化失败 - ' . $e->getMessage());
        }
    }
}

// 确保时区已正确初始化（AJAX 请求中显式强化）
if (function_exists('todo_init_timezone')) {
    todo_init_timezone(true);
} elseif (function_exists('get_option')) {
    $timezone = get_option('timezone_string', 'Asia/Shanghai');
    if (!empty($timezone)) {
        @date_default_timezone_set($timezone);
    }
}

// 确保文章翻译服务已加载（即使通过统一AJAX入口加载也需要）
if (!function_exists('todo_get_post_translation')) {
    $post_translation_file = ABSPATH . 'core/services/post-translation.php';
    if (file_exists($post_translation_file)) {
        require_once $post_translation_file;
    }
}

// 规范化媒体标签中的 URL 路径，支持子目录环境
if (!function_exists('todo_normalize_media_tag_path')) {
    function todo_normalize_media_tag_path($tag_html)
    {
        $site_url = function_exists('todo_get_site_url') ? todo_get_site_url('/') : (function_exists('site_url') ? site_url('/') : (defined('SITE_URL') ? SITE_URL : ''));
        $site_path = rtrim((string) parse_url($site_url, PHP_URL_PATH), '/');
        if ($site_path === '') {
            return $tag_html;
        }
        return preg_replace_callback('/\b(src|href|poster)=["\']([^"\']+)["\']/i', function ($m) use ($site_path) {
            $attr = $m[1];
            $val = $m[2];
            if (strpos($val, 'http://') === 0 || strpos($val, 'https://') === 0 || strpos($val, '//') === 0 || strpos($val, 'data:') === 0) {
                return $m[0];
            }
            if (strpos($val, '/') === 0) {
                if (strpos($val, $site_path . '/') !== 0 && $val !== $site_path) {
                    $val = $site_path . $val;
                }
            } else {
                $val = $site_path . '/' . ltrim($val, '/');
            }
            return $attr . '="' . $val . '"';
        }, $tag_html);
    }
}

// 启动会话
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 设置JSON响应头
if (ob_get_level()) {
    ob_end_clean(); 
}
header('Content-Type: application/json');

// 检查用户是否已登录（使用 Todo_Session_Manager）
if (class_exists('Todo_Session_Manager')) {
    Todo_Session_Manager::init();
    if (!Todo_Session_Manager::is_admin_logged_in()) {
        echo json_encode(['success' => false, 'message' => '用户未登录']);
        exit;
    }
} else {
    echo json_encode(['success' => false, 'message' => '会话管理器未加载']);
    exit;
}

// 验证用户权限
if (function_exists('current_user_can')) {
    if (!current_user_can('edit_posts')) {
        echo json_encode(['success' => false, 'message' => '权限不足']);
        exit;
    }
}

// ---------------------------------------------------------
// 验证CSRF令牌
// ---------------------------------------------------------
$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
$is_post_new = strpos($referer, '/admin/index.php?page=post-new') !== false;
$expected_nonce = $is_post_new ? 'create_post_nonce' : 'edit_post_nonce';

$action_nonce_map = [
    'export_plugin_ai_conversion' => 'common_ajax_nonce',
    'export_theme_ai_conversion'  => 'common_ajax_nonce'
];

if (isset($action_nonce_map[$action])) {
    $expected_nonce = $action_nonce_map[$action];
}

$csrf_ok = false;
if (class_exists('Todo_Security_Manager')) {
    $csrf_ok = Todo_Security_Manager::verify_request_csrf($expected_nonce, '_todononce');
}

if (!$csrf_ok) {
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败 (Required: ' . $expected_nonce . ')']);
    exit;
}


// 处理不同的动作
$action = sanitize_text_field($_POST['action'] ?? '');

switch ($action) {
    case 'clear_auto_draft':
        // 清除自动草稿（支持可选的post_id，用于编辑页；无post_id则清除新建页草稿）
        $user_id = function_exists('get_current_user_id') ? get_current_user_id() : 0;
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if ($user_id) {
            $base_key = 'auto_draft_' . $user_id;
            if ($post_id > 0) {
                $post_key = $base_key . '_' . $post_id;
                if (isset($_SESSION[$post_key])) {
                    unset($_SESSION[$post_key]);
                }
            }
            if (isset($_SESSION[$base_key])) {
                unset($_SESSION[$base_key]);
            }
        } else {
            // 兜底清理旧格式
            if (isset($_SESSION['auto_draft'])) {
                unset($_SESSION['auto_draft']);
            }
        }
        echo json_encode(['success' => true, 'message' => '草稿已清除']);
        break;

    case 'auto_save':
        // 自动保存草稿（统一实体保存风格）
        $title = sanitize_text_field($_POST['title'] ?? '');
        $raw_content = $_POST['content'] ?? '';
        // 强制转换为相对路径
        if (function_exists('todo_convert_content_urls_to_relative')) {
            $raw_content = todo_convert_content_urls_to_relative($raw_content);
        }
        // 与post-edit保持一致：实体存储
        $content = todo_esc_html($raw_content);

        $user_id = function_exists('get_current_user_id') ? get_current_user_id() : 0;
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $saved_at = time();

        if ($user_id) {
            // new: auto_draft_{user}；edit: auto_draft_{user}_{post}
            $key = 'auto_draft_' . $user_id . ($post_id > 0 ? ('_' . $post_id) : '');
            $_SESSION[$key] = [
                'title' => $title,
                'content' => $content,
                'saved_at' => $saved_at
            ];
        } else {
            // 兜底：旧格式
            $_SESSION['auto_draft'] = [
                'title' => $title,
                'content' => $content,
                'saved_at' => $saved_at
            ];
        }

        $saved_at_full = function_exists('format_date') ? format_date($saved_at, 'Y-m-d H:i') : date('Y-m-d H:i', $saved_at);
        echo json_encode([
            'success' => true,
            'message' => '草稿已保存',
            'saved_at' => date('H:i', $saved_at),
            'time' => date('H:i:s', $saved_at),
            'saved_at_full' => $saved_at_full,
            'saved_at_ts' => $saved_at
        ]);
        break;

    // 新增：获取指定语言翻译
    case 'get_post_translation':
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $language_code = isset($_POST['language_code']) ? sanitize_text_field($_POST['language_code']) : '';
        if ($post_id <= 0 || empty($language_code)) {
            echo json_encode(['success' => false, 'message' => '参数错误']);
            break;
        }
        try {
            $tr = todo_get_post_translation($post_id, $language_code);
            echo json_encode(['success' => true, 'data' => $tr]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // 新增：写入/更新翻译
    case 'upsert_post_translation':
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $language_code = isset($_POST['language_code']) ? sanitize_text_field($_POST['language_code']) : '';
        if ($post_id <= 0 || empty($language_code)) {
            echo json_encode(['success' => false, 'message' => '参数错误']);
            break;
        }
        $data = [
            'title' => isset($_POST['title']) ? sanitize_text_field($_POST['title']) : null,
            'content' => isset($_POST['content']) ? (function_exists('todo_convert_content_urls_to_relative') ? todo_convert_content_urls_to_relative(todo_kses_post($_POST['content'])) : todo_kses_post($_POST['content'])) : null,
            'excerpt' => isset($_POST['excerpt']) ? sanitize_text_field($_POST['excerpt']) : null,
            'seo_title' => isset($_POST['seo_title']) ? sanitize_text_field($_POST['seo_title']) : null,
            'seo_keywords' => isset($_POST['seo_keywords']) ? sanitize_text_field($_POST['seo_keywords']) : null,
            'seo_description' => isset($_POST['seo_description']) ? sanitize_text_field($_POST['seo_description']) : null,
            'status' => isset($_POST['status']) ? sanitize_text_field($_POST['status']) : null,
        ];
        if (!empty($_POST['system_fields'])) {
            $sys = json_decode($_POST['system_fields'], true);
            if (is_array($sys)) {
                if (isset($sys['title'])) {
                    $data['title'] = sanitize_text_field($sys['title']);
                }
                if (isset($sys['content'])) {
                    $data['content'] = todo_kses_post($sys['content']);
                }
                if (isset($sys['excerpt'])) {
                    $data['excerpt'] = sanitize_text_field($sys['excerpt']);
                }
                if (isset($sys['status'])) {
                    $data['status'] = sanitize_text_field($sys['status']);
                }
            }
        }
        if (!empty($_POST['fixed_fields'])) {
            $fixed = json_decode($_POST['fixed_fields'], true);
            if (is_array($fixed)) {
                if (isset($fixed['seo_title'])) {
                    $data['seo_title'] = sanitize_text_field($fixed['seo_title']);
                }
                if (isset($fixed['seo_keywords'])) {
                    $data['seo_keywords'] = sanitize_text_field($fixed['seo_keywords']);
                }
                if (isset($fixed['seo_description'])) {
                    $data['seo_description'] = sanitize_text_field($fixed['seo_description']);
                }
            }
        }
        try {
            $saved = todo_upsert_post_translation($post_id, $language_code, $data);
            // 成功后：触发缓存更新，与文章发布保持一致
            if (function_exists('canonical_locale')) {
                $language_code = canonical_locale($language_code);
            }
            if (function_exists('todo_do_action')) {
                todo_do_action('todo_post_translation_updated', $post_id, $language_code);
                // 复用内容更新钩子，确保页面缓存（内容/列表/首页）被正确清理
                todo_do_action('todo_post_updated', $post_id);
            }
            if (class_exists('Todo_Unified_Cache_Manager')) {
                Todo_Unified_Cache_Manager::clean_post_cache($post_id);
            }
            echo json_encode(['success' => true, 'data' => $saved, 'message' => '翻译已保存']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // 新增：列出文章的翻译状态
    case 'list_post_translations':
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if ($post_id <= 0) {
            echo json_encode(['success' => false, 'message' => '参数错误']);
            break;
        }

        // 按照开发规范：通过CMS_DB::get_instance()获取数据库连接，而不是使用global变量
        if (!class_exists('CMS_DB')) {
            echo json_encode(['success' => false, 'message' => '数据库类未加载']);
            break;
        }

        try {
            $db = CMS_DB::get_instance();
            if (!$db || !($db instanceof CMS_DB)) {
                throw new Exception('数据库连接未初始化');
            }

            $sql = "SELECT language_code, status, updated_at FROM " . TABLE_PREFIX . "post_translations WHERE post_id = ? ORDER BY language_code";
            $rows = $db->get_results($sql, [$post_id], 'ARRAY_A');
            echo json_encode(['success' => true, 'data' => $rows]);
        } catch (Exception $e) {
            error_log('TodoCMS Error [post-ajax]: 获取文章翻译列表失败 - ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => '数据库查询失败: ' . $e->getMessage()]);
        }
        break;

    // 自定义字段翻译：列出指定语言的所有字段
    case 'list_post_custom_field_translations':
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $language_code = isset($_POST['language_code']) ? sanitize_text_field($_POST['language_code']) : '';
        if ($post_id <= 0 || empty($language_code)) {
            echo json_encode(['success' => false, 'message' => '参数错误']);
            break;
        }
        try {
            $map = todo_list_post_custom_field_translations($post_id, $language_code);
            echo json_encode(['success' => true, 'data' => $map]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // 自定义字段翻译：批量写入/更新
    case 'upsert_post_custom_field_translations':
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $language_code = isset($_POST['language_code']) ? sanitize_text_field($_POST['language_code']) : '';
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'pending';
        if ($post_id <= 0 || empty($language_code)) {
            echo json_encode(['success' => false, 'message' => '参数错误']);
            break;
        }
        // fields 支持 JSON 字符串或普通键值对（前者优先）
        $fields = array();
        if (!empty($_POST['fields'])) {
            $decoded = json_decode($_POST['fields'], true);
            if (is_array($decoded)) {
                $fields = $decoded;
            }
        }
        // 兜底：收集以 tr_model_field_ 前缀的字段
        if (empty($fields)) {
            foreach ($_POST as $k => $v) {
                if (strpos($k, 'tr_model_field_') === 0) {
                    $key = substr($k, strlen('tr_model_field_'));
                    $fields[$key] = $v;
                }
            }
        }
        try {
            $res = todo_upsert_post_custom_field_translations($post_id, $language_code, $fields, $status);
            // 触发钩子与缓存清理在服务层已完成，这里仅返回
            echo json_encode(['success' => true, 'data' => $res, 'message' => '自定义字段翻译已保存']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // 新增：获取模型字段HTML
    case 'get_model_fields':
        $model_keys = isset($_POST['model_keys']) ? $_POST['model_keys'] : array();
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $name_prefix = isset($_POST['name_prefix']) ? sanitize_text_field($_POST['name_prefix']) : 'model_field_';
        $id_prefix = isset($_POST['id_prefix']) ? sanitize_text_field($_POST['id_prefix']) : '';

        if (empty($model_keys)) {
            echo json_encode(['success' => false, 'message' => '未指定模型']);
            break;
        }

        // 确保 model_keys 是数组并消毒，防止未经验证的键名传入数据库查询和组件函数
        if (!is_array($model_keys)) {
            $model_keys = array($model_keys);
        }
        $model_keys = array_values(array_filter(array_map('sanitize_key', $model_keys)));

        if (!defined('ADMIN_DIR')) {
            define('ADMIN_DIR', ABSPATH . 'admin');
        }

        // 引入组件文件
        require_once ADMIN_DIR . '/components/model-fields-component.php';

        try {
            // 调用生成函数，限制只生成请求的模型
            // 注意：这里需要确保即使模型没有关联分类，也能返回数据
            $data = generate_all_model_fields_data($post_id, array(), true, true, $name_prefix, $id_prefix, false, $model_keys);

            // 检查返回的数据
            if (empty($data['models']) || !is_array($data['models'])) {
                $data['models'] = array();
            }

            // 确保所有请求的模型都有数据（即使没有关联分类）
            // 如果某些模型没有数据，说明它们可能没有关联分类，需要特殊处理
            foreach ($model_keys as $model_key) {
                if (!isset($data['models'][$model_key])) {
                    // 模型没有关联分类，但仍然需要返回字段数据
                    // 使用空分类数组来生成字段数据
                    $current_field_values = $post_id ? get_post_model_fields_values($post_id, array()) : array();
                    $model_id_prefix = $id_prefix ? ($id_prefix . $model_key . '_') : '';
                    $featured_html = get_model_featured_fields_html($model_key, $current_field_values, $post_id, $name_prefix, $model_id_prefix, false, array());

                    // 获取模型字段（不限制分类）
                    $all_fields = get_component_model_fields($model_key, array());
                    $fields_html_by_key = array();
                    $field_order = array();

                    if (!empty($all_fields)) {
                        foreach ($all_fields as $field_cfg) {
                            if (!empty($field_cfg['is_fixed'])) {
                                continue;
                            }
                            $key = $field_cfg['field_key'];
                            $val = isset($current_field_values['model_field_' . $key]) ? $current_field_values['model_field_' . $key] : ($field_cfg['field_default'] ?? '');
                            $piece = render_field_html($field_cfg, $val, $post_id, $name_prefix, $model_id_prefix, false);
                            $fields_html_by_key[$key] = $piece;
                            $field_order[$key] = isset($field_cfg['field_order']) ? (int) $field_cfg['field_order'] : 0;
                        }
                    }

                    // 获取模型名称
                    global $tododb;
                    $display_name = $model_key; // 默认使用模型键
                    if (isset($tododb) && $tododb !== null) {
                        $table_prefix = isset($tododb->prefix) ? $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_');
                        $model_name = $tododb->get_var(
                            "SELECT model_name FROM " . $table_prefix . "models WHERE model_key = ? LIMIT 1",
                            array($model_key)
                        );
                        if ($model_name) {
                            $display_name = $model_name;
                        }
                    } elseif (class_exists('CMS_DB')) {
                        // 回退：使用 CMS_DB
                        $db = CMS_DB::get_instance();
                        if ($db) {
                            $table_prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_';
                            $model_name = $db->get_var(
                                "SELECT model_name FROM " . $table_prefix . "models WHERE model_key = ? LIMIT 1",
                                array($model_key)
                            );
                            if ($model_name) {
                                $display_name = $model_name;
                            }
                        }
                    }

                    // 构建模型数据
                    $data['models'][$model_key] = array(
                        'display_name' => $display_name,
                        'featured_html' => $featured_html,
                        'fields_html_by_key' => $fields_html_by_key,
                        'field_order' => $field_order,
                        'category_visibility' => array(), // 没有分类关联，可见性为空
                        'fixed_keys' => array('seo_title', 'seo_keywords', 'seo_description')
                    );
                }
            }

            echo json_encode(['success' => true, 'data' => $data['models']]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // 保存远程图片
    case 'save_remote_images':
        $urls = $_POST['urls'] ?? [];
        if (is_string($urls)) {
            $urls = json_decode($urls, true);
        }

        if (empty($urls) || !is_array($urls)) {
            echo json_encode(['success' => false, 'message' => '没有要下载的图片URL']);
            break;
        }

        // 数量上限，防止批量滥用
        if (count($urls) > 20) {
            $urls = array_slice($urls, 0, 20);
        }

        // 只允许 http/https，防止 file://、data: 等异常协议
        $urls = array_values(array_filter($urls, function($url) {
            $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?: '');
            return in_array($scheme, ['http', 'https'], true);
        }));

        if (empty($urls)) {
            echo json_encode(['success' => false, 'message' => 'URL 格式不合法，仅支持 http/https']);
            break;
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $results = [];
        $failed  = [];

        foreach ($urls as $url) {
            $local_url = todo_download_remote_image($url, $post_id);
            if (!is_todo_error($local_url)) {
                $results[$url] = $local_url;
            } else {
                $failed[] = $url;
                error_log("Failed to download remote image: $url. Error: " . $local_url->get_error_message());
            }
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => $results, 'failed' => $failed, 'count' => count($results)]);
        break;

    case 'export_translation_json':
        if (todo_is_demo_admin_session()) {
            echo json_encode(['success' => false, 'message' => '演示模式！']);
            break;
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if ($post_id <= 0) {
            echo json_encode(['success' => false, 'message' => '参数错误']);
            break;
        }

        $post = get_post($post_id);
        if (!$post) {
            echo json_encode(['success' => false, 'message' => '文章不存在']);
            break;
        }

        // 获取启用语言
        $enabled_languages = get_post_meta($post_id, '_enabled_languages', true);
        if ($enabled_languages) {
            $enabled_languages = json_decode($enabled_languages, true);
        }
        if (empty($enabled_languages)) {
            // 如果没有设置，获取系统所有启用语言
            $registry = class_exists('LanguageRegistry') ? LanguageRegistry::get_instance() : null;
            if ($registry) {
                $enabled_languages = array_keys($registry->get_languages(true));
            } else {
                global $tododb;
                $enabled_languages = (array) $tododb->get_col("SELECT code FROM {$tododb->prefix}languages WHERE status = 'active'");
            }
        }

        // 规范化语言码
        $enabled_languages = array_map('canonical_locale', (array)$enabled_languages);
        $default_language = canonical_locale(get_default_language());
        
        // 排除默认语言
        $target_languages = array_filter($enabled_languages, function($l) use ($default_language) {
            return $l !== $default_language;
        });

        // 处理内容占位符
        // 关键修复：从数据库读取的内容可能已被转义（todo_esc_html），需要先解码才能正确识别媒体标签
        $content = function_exists('todo_decode_html_entities') ? todo_decode_html_entities($post->post_content) : htmlspecialchars_decode($post->post_content, ENT_QUOTES);
        $placeholders = [];
        $index = 0;
        $pattern = '/<(img|video|audio|iframe|source|embed|object)\b[^>]*?(?:>.*?<\/\1>|\/?>)/is';
        
        $content_with_placeholders = preg_replace_callback($pattern, function($matches) use (&$placeholders, &$index) {
            $tag_html = todo_normalize_media_tag_path($matches[0]);
            $alt = ''; $title = '';
            if (preg_match('/alt=["\']([^"\']*)["\']/i', $tag_html, $m)) $alt = $m[1];
            if (preg_match('/title=["\']([^"\']*)["\']/i', $tag_html, $m)) $title = $m[1];
            
            // 采用不带引号的格式，减少 AI 混淆和正则解析压力
            $placeholder = "[[MEDIA_{$index}(alt: {$alt}, title: {$title})]]";
            $placeholders["[[MEDIA_{$index}]]"] = $tag_html;
            $index++;
            return $placeholder;
        }, $content);
        
        // 移除所有标签属性，仅保留纯净标签，以节省 AI 上下文
        if (function_exists('todo_strip_all_attributes')) {
            $content_with_placeholders = todo_strip_all_attributes($content_with_placeholders);
        }

        $export_data = [
            'version' => '1.1',
            'type' => 'post_translation',
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'source_language' => $default_language,
            'target_languages' => array_values($target_languages),
            'items' => [
                'title' => $post->post_title,
                'excerpt' => $post->post_excerpt,
                'content' => $content_with_placeholders,
                'seo_title' => $post->seo_title ?? '',
                'seo_keywords' => $post->seo_keywords ?? '',
                'seo_description' => $post->seo_description ?? ''
            ],
            'placeholders' => $placeholders,
            'instructions' => [
                'task' => "Translate the 'items' into the specified 'target_languages'.",
                'rules' => [
                    "Translate the 'alt' and 'title' values inside the placeholders [[MEDIA_N(alt: ..., title: ...)]] for each language.",
                    "Keep the placeholder structure intact: [[MEDIA_N(alt: translated_alt, title: translated_title)]].",
                    "Do NOT add quotes around the alt and title values unless they are part of the translation.",
                    "Translate and optimize SEO metadata (seo_title, seo_keywords, seo_description).",
                    "Use traditional HTML for rich text formatting (e.g., <p>, <strong>, <ul>). DO NOT use Markdown.",
                    "Strictly retain the format of this file and output it in this format (including all keys) after translation",
                    "Make sure the JSON is legal, preferably by providing a JSON code block or a directly downloadable .json file.",
                    "The JSON must contain a 'translations' key with language codes as sub-keys."
                ],
                'output_format' => [
                    'translations' => [
                        'lang-code' => [
                            'title' => 'Translated Title',
                            'excerpt' => 'Translated Excerpt',
                            'content' => 'Translated Content with [[MEDIA_N(alt: translated_alt, title: translated_title)]]',
                            'seo_title' => 'Translated SEO Title',
                            'seo_keywords' => 'Translated SEO Keywords',
                            'seo_description' => 'Translated SEO Description'
                        ]
                    ]
                ]
            ]
        ];

        echo json_encode([
            'success' => true,
            'data' => $export_data,
            'filename' => 'translation-task-post-' . $post_id . '.json'
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        break;

    case 'export_post_ai_expand':
        if (todo_is_demo_admin_session()) {
            echo json_encode(['success' => false, 'message' => '演示模式！']);
            break;
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $post = get_post($post_id);
        if (!$post) {
            echo json_encode(['success' => false, 'message' => '文章不存在']);
            break;
        }

        // 关键修复：从数据库读取的内容可能已被转义（todo_esc_html），需要先解码才能正确识别媒体标签
        $content = function_exists('todo_decode_html_entities') ? todo_decode_html_entities($post->post_content) : htmlspecialchars_decode($post->post_content, ENT_QUOTES);
        $placeholders = [];
        $index = 0;
        $pattern = '/<(img|video|audio|iframe|source|embed|object)\b[^>]*?(?:>.*?<\/\1>|\/?>)/is';
        
        $content_with_placeholders = preg_replace_callback($pattern, function($matches) use (&$placeholders, &$index) {
            $tag_html = todo_normalize_media_tag_path($matches[0]);
            $alt = ''; $title = '';
            if (preg_match('/alt=["\']([^"\']*)["\']/i', $tag_html, $m)) $alt = $m[1];
            if (preg_match('/title=["\']([^"\']*)["\']/i', $tag_html, $m)) $title = $m[1];
            
            // 采用不带引号的格式
            $placeholder = "[[MEDIA_{$index}(alt: {$alt}, title: {$title})]]";
            $placeholders["[[MEDIA_{$index}]]"] = $tag_html;
            $index++;
            return $placeholder;
        }, $content);
        
        // 移除所有标签属性，仅保留纯净标签，以节省 AI 上下文
        if (function_exists('todo_strip_all_attributes')) {
            $content_with_placeholders = todo_strip_all_attributes($content_with_placeholders);
        }

        $export_data = [
            'type' => 'ai_expand',
            'post_id' => $post_id,
            'items' => [
                'title' => $post->post_title,
                'excerpt' => $post->post_excerpt,
                'content' => $content_with_placeholders,
                'seo_title' => $post->seo_title ?? '',
                'seo_keywords' => $post->seo_keywords ?? '',
                'seo_description' => $post->seo_description ?? ''
            ],
            'placeholders' => $placeholders,
            'instructions' => [
                'task' => "Expand the provided content into a more detailed and engaging article.",
                'rules' => [
                    "Keep and update the [[MEDIA_N(alt: ..., title: ...)]] placeholders. Create or improve the 'alt' and 'title' values based on the article's new context.",
                    "Do NOT add quotes around the alt and title values inside the parenthesis.",
                    "DO NOT remove any placeholders. You may re-arrange them to fit the new content flow.",
                    "Generate optimized SEO metadata (seo_title, seo_keywords, seo_description) based on the expanded content.",
                    "Use traditional HTML for rich text formatting (e.g., <p>, <strong>, <ul>). DO NOT use Markdown.",
                    "Strictly retain the format of this file and output it in this format (including all keys) after translation",
                    "Make sure the JSON is legal, preferably by providing a JSON code block or a directly downloadable .json file.",
                    "Provide the result as a downloadable .json file with keys: 'title', 'excerpt', 'content', 'seo_title', 'seo_keywords', 'seo_description'."
                ]
            ]
        ];

        echo json_encode([
            'success' => true,
            'data' => $export_data,
            'filename' => 'ai-expand-post-' . $post_id . '.json'
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        break;

    case 'export_post_ai_create':
        if (todo_is_demo_admin_session()) {
            echo json_encode(['success' => false, 'message' => '演示模式！']);
            break;
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $post = get_post($post_id);
        if (!$post) {
            echo json_encode(['success' => false, 'message' => '文章不存在']);
            break;
        }

        // 同时提取媒体 URL 列表（给 AI 参考）和占位符映射（用于回填）
        // 先解码 HTML 实体，防止 src=&quot;...&quot; 格式导致正则匹配失败
        $decoded_content_for_media = function_exists('todo_decode_html_entities')
            ? todo_decode_html_entities($post->post_content)
            : htmlspecialchars_decode($post->post_content, ENT_QUOTES);
        $media_list = [];
        $placeholders = [];
        $site_url = rtrim(get_site_url(), '/');
        $media_tag_pattern = '/<(img|video|audio|iframe|source|embed|object)\b[^>]*?(?:>.*?<\/\1>|\/?>)/is';
        preg_replace_callback($media_tag_pattern, function($m) use (&$media_list, &$placeholders, $site_url) {
            $tag_html = todo_normalize_media_tag_path($m[0]);
            if (!preg_match('/src=["\']([^"\']+)["\']/i', $tag_html, $src_m)) return $m[0];
            $src = $src_m[1];
            $abs_src = (strpos($src, 'http') !== 0 && strpos($src, '//') !== 0)
                ? $site_url . '/' . ltrim($src, '/')
                : $src;
            if (!in_array($abs_src, $media_list)) {
                $idx = count($media_list);
                $media_list[] = $abs_src;
                $placeholders["[[MEDIA_{$idx}]]"] = $tag_html; // 保留原始标签用于回填
            }
            return $m[0];
        }, $decoded_content_for_media);

        $export_data = [
            'type' => 'ai_create',
            'post_id' => $post_id,
            'source_content' => [
                'title' => $post->post_title,
                'excerpt' => $post->post_excerpt,
                'content' => strip_tags($decoded_content_for_media),
                'seo_title' => $post->seo_title ?? '',
                'seo_keywords' => $post->seo_keywords ?? '',
                'seo_description' => $post->seo_description ?? ''
            ],
            'media_assets' => $media_list,
            'placeholders' => $placeholders, // 用于导入时回填占位符
            'instructions' => [
                'task' => "Create a completely new, original high-quality article based on the source content and provided media assets.",
                'rules' => [
                    "Use the media assets provided in 'media_assets' as <img> tags.",
                    "For each media asset, use the placeholder format: [[MEDIA_N(alt: ..., title: ...)]] where N is the index (0, 1, 2...) from 'media_assets'.",
                    "Create descriptive 'alt' and 'title' values for each media placeholder. Do NOT use quotes around them.",
                    "Generate optimized SEO metadata (seo_title, seo_keywords, seo_description) based on the created content.",
                    "Use traditional HTML for rich text formatting (e.g., <p>, <strong>, <ul>). DO NOT use Markdown.",
                    "Design a professional layout with good typography and media placement.",
                    "Strictly retain the format of this file and output it in this format (including all keys) after translation",
                    "Make sure the JSON is legal, preferably by providing a JSON code block or a directly downloadable .json file.",
                    "Provide the result as a downloadable .json file with keys: 'title', 'excerpt', 'content', 'seo_title', 'seo_keywords', 'seo_description'."
                ]
            ]
        ];

        echo json_encode([
            'success' => true,
            'data' => $export_data,
            'filename' => 'ai-create-post-' . $post_id . '.json'
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        break;

    case 'export_theme_ai_conversion':
        $theme_slug = isset($_POST['theme']) ? sanitize_key($_POST['theme']) : '';
        
        // 获取模板标签标准
        $spec_path = ABSPATH . 'docs/todo-template-tags-spec.md';
        $spec_md = file_exists($spec_path) ? (string) file_get_contents($spec_path) : '';

        $export_data = [
            'type' => 'theme_conversion_specification',
            'target_cms' => 'TodoCMS',
            'version' => '1.0.0',
            'theme_slug' => $theme_slug,
            'specification' => [
                'metadata_standard' => [
                    'file' => 'theme.json',
                    'schema' => [
                        'name' => 'Human readable name',
                        'description' => 'Brief summary',
                        'version' => 'SemVer string',
                        'author' => 'Name/Organization',
                        'author_url' => 'Website',
                        'tags' => 'Array of strings',
                        'supports' => 'Object (e.g. {"post-thumbnails": true})'
                    ]
                ],
                'template_hierarchy' => [
                    'general_rules' => [
                        'Extension' => 'Prefer .html for tag-based templates, .php for logic-heavy templates.',
                        'Priority' => 'Specific model templates > General list/view templates > index.html'
                    ],
                    'file_mapping' => [
                        'index.html' => 'Main homepage / Fallback',
                        'header.html' => 'Global header snippet',
                        'footer.html' => 'Global footer snippet',
                        'archive.html' => 'General category listing',
                        'lists_{model}.html' => 'Listing for specific model (e.g., lists_article.html, lists_product.html)',
                        'view_{model}.html' => 'Detail page for specific model (e.g., view_article.html, view_product.html)',
                        'lists_search.html' => 'Search results listing',
                        '404.html' => 'Error page'
                    ]
                ],
                'tag_system_intelligence' => [
                    'standard' => 'TodoCMS v2.0 Standard (WXML-inspired)',
                    'output_syntax' => '{{variable_name}} (Mustache-style binding)',
                    'expression_support' => 'Supports ternary, math, and logical operators inside {{ }}',
                    'logic_syntax' => 'HTML attributes with todo: prefix (Directive style)',
                    'logic_blocks' => [
                        'conditional' => '<div todo:if="{{condition}}">...</div>',
                        'loop' => '<div todo:for="{{items}}" todo:key="id" todo:for-item="item">...</div>',
                        'wrapper' => '<block todo:if="{{condition}}">...</block> (Invisible wrapper)',
                        'template' => '<todo:template name="tpl">...</todo:template>',
                        'include' => '<todo:include src="header.html"/>'
                    ],
                    'core_tags_library' => [
                        'site' => ['site_title', 'site_url', 'site_description', 'todo_head', 'todo_footer', 'public_url'],
                        'post' => ['post_title', 'post_content', 'post_excerpt', 'post_date', 'post_url', 'post_thumbnail'],
                        'nav' => ['nav_menu', 'breadcrumb'],
                        'user' => ['user_display_name', 'avatar', 'logout_url', 'if_user_logged_in']
                    ],
                    'full_spec_markdown' => $spec_md
                ],
                'asset_management' => [
                    'path_resolution' => 'Use {{public_url}} or relative paths based on theme root. Example: <img src="{{public_url}}/images/logo.png">',
                    'js_css_inclusion' => 'Include assets in header.html/footer.html.'
                ]
            ],
            'ai_instructions' => [
                    '1. Structure' => "Reorganize source folders into TodoCMS hierarchy. Create theme.json metadata.",
                    '2. Components' => "Extract common parts into header.html and footer.html. Use <todo:include src=\"header.html\"/> for inclusion.",
                    '3. Tag Mapping' => "Convert source tags to TodoCMS {{...}} for data binding and todo:* attributes for logic control. Adhere to the v2.0 'Directive' style.",
                    '4. Data Scoping' => "In loops, use the alias defined in todo:for-item (default is 'item') to access fields, e.g., {{item.title}}.",
                    '5. Asset Links' => "Update assets to be relative or use {{public_url}} inside src/href attributes."
            ]
        ];

        echo json_encode([
            'success' => true,
            'data' => $export_data,
            'filename' => 'theme-conversion-spec-' . $theme_slug . '.json'
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        break;

    case 'export_plugin_ai_conversion':
        $plugin_slug = isset($_POST['plugin']) ? sanitize_key($_POST['plugin']) : '';
        if (empty($plugin_slug)) {
            echo json_encode(['success' => false, 'message' => '未指定插件标识符']);
            break;
        }
        
        $export_data = [
            'type' => 'plugin_conversion_specification',
            'target_cms' => 'TodoCMS',
            'version' => '1.0.0',
            'plugin_slug' => $plugin_slug,
            'specification' => [
                'metadata_standard' => [
                    'file' => 'plugin.php',
                    'header_comments' => [
                        'Plugin Name' => 'Required',
                        'Description' => 'Required',
                        'Version' => 'Required',
                        'Author' => 'Optional',
                        'Author URI' => 'Optional'
                    ]
                ],
                'architecture' => [
                    'entry_point' => 'plugin.php',
                    'hooks_file' => 'hooks.php (for action/filter registrations)',
                    'admin_folder' => 'admin/ (controllers and views for backend)',
                    'includes_folder' => 'includes/ (core business logic and helper classes)'
                ],
                'api_intelligence' => [
                    'hook_system' => [
                        'functions' => ['add_action($hook, $callback)', 'add_filter($hook, $callback)', 'do_action($hook)', 'apply_filters($hook, $value)'],
                        'core_hooks' => ['todo_init', 'todo_admin_init', 'todo_head', 'todo_footer', 'todo_admin_menu']
                    ],
                    'database' => [
                        // 现代推荐写法：CMS_DB::get_instance()，$tododb 为兼容旧代码的全局实例
                        'class' => 'CMS_DB (Recommended: $db = CMS_DB::get_instance(); Legacy global: $tododb)',
                        'methods' => [
                            'get_results($sql, $params = [], $output = \'ARRAY_A\')',
                            'get_row($sql, $params = [], $output = \'ARRAY_A\')',
                            'get_var($sql, $params = [])',
                            'get_col($sql, $params = [])',
                            'insert($table, $data)',
                            'update($table, $data, $where)',
                            'delete($table, $where)'
                        ]
                    ],
                    'admin_ui' => [
                        'menu_registration' => 'todo_add_admin_menu($page_title, $menu_title, $capability, $menu_slug, $function)',
                        'styling' => 'Use standard CSS classes like .admin-card, .admin-table, .button-primary.'
                    ]
                ]
            ],
            'ai_instructions' => [
                'primary_goal' => "Reconstruct the provided plugin logic to fit the TodoCMS modular architecture.",
                'conversion_logic' => [
                    '1. Initialization' => "Define the main plugin class and entry point in plugin.php.",
                    '2. Hook Integration' => "Identify the source hooks and map them to TodoCMS actions/filters in hooks.php.",
                    '3. Admin Panel' => "Recreate any admin settings or management pages using TodoCMS admin UI components.",
                    '4. Data Layer' => 'Replace database calls with CMS_DB::get_instance() pattern. Use parameterized queries ($db->get_results($sql, [$param1])) to prevent SQL injection. Always use TABLE_PREFIX constant for table names.'
                ]
            ]
        ];

        echo json_encode([
            'success' => true,
            'data' => $export_data,
            'filename' => 'plugin-conversion-spec-' . $plugin_slug . '.json'
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        break;

    case 'import_translation_json':
        if (todo_is_demo_admin_session()) {
            echo json_encode(['success' => false, 'message' => '演示模式！']);
            break;
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $json_str = $_POST['json_content'] ?? '';
        $results = ['success_count' => 0, 'error_count' => 0, 'errors' => []];
        
        if ($post_id <= 0 || empty($json_str)) {
            echo json_encode(['success' => false, 'message' => '参数错误']);
            break;
        }

        // 尝试去除 Markdown 代码块包裹 (针对 AI 常见输出)
        if (preg_match('/^```(?:json)?\s*(\{.*?\})\s*```$/is', trim($json_str), $matches)) {
            $json_str = $matches[1];
        }

        $import_data = json_decode($json_str, true);
        if (!$import_data) {
            echo json_encode(['success' => false, 'message' => '无效的 JSON 格式: ' . json_last_error_msg()]);
            break;
        }

        // 支持两种结构：整个导出文件或仅 translations 对象
        $translations = $import_data['translations'] ?? $import_data;
        if (!is_array($translations)) {
            echo json_encode(['success' => false, 'message' => '未找到翻译数据结构']);
            break;
        }

        $placeholders = $import_data['placeholders'] ?? [];
        if (!is_array($placeholders)) $placeholders = [];
        
        // 合并：优先使用 JSON 中的，但本地缓存（前端传回）作为有力补充
        if (!empty($_POST['local_placeholders'])) {
            $local = json_decode($_POST['local_placeholders'], true);
            if (is_array($local)) {
                $placeholders = array_merge($local, $placeholders);
            }
        }
        
        // 如果上传的 JSON 中缺少占位符信息，尝试从当前文章内容中实时生成以匹配
        if (empty($placeholders)) {
            $post = get_post($post_id);
            if ($post) {
                // 关键修复：从数据库读取的内容可能已被转义，需要先解码才能正确识别媒体标签并重新生成占位符映射
                $content = function_exists('todo_decode_html_entities') ? todo_decode_html_entities($post->post_content) : htmlspecialchars_decode($post->post_content, ENT_QUOTES);
                $temp_placeholders = [];
                $temp_index = 0;
                $pattern = '/<(img|video|audio|iframe|source|embed|object)\b[^>]*?(?:>.*?<\/\1>|\/?>)/is';
                preg_replace_callback($pattern, function($m) use (&$temp_placeholders, &$temp_index) {
                    $temp_placeholders["[[MEDIA_{$temp_index}]]"] = todo_normalize_media_tag_path($m[0]);
                    $temp_index++;
                    return $m[0];
                }, $content);
                $placeholders = $temp_placeholders;
            }
        }

        $translated_data = [];
        
        // 预处理：如果 translations 包含 items 键（AI 扩写导出的标准结构），则提取 items 内容
        if (isset($translations['items']) && is_array($translations['items'])) {
            $translations = $translations['items'];
        }

        // 检查是否为平铺结构 (AI 扩写常见的直接返回 title/content 格式，或者上面提取出来的 items)
        if (isset($translations['content']) && !is_array($translations['content'])) {
            // 如果是平铺结构，默认尝试放入当前正在编辑的语言，或从任务中获取语言
            $target_lang = isset($_POST['target_lang']) ? sanitize_text_field($_POST['target_lang']) : (isset($import_data['source_language']) ? $import_data['source_language'] : 'en-US');
            $content = $translations['content'];
            if (!empty($placeholders)) {
                // 逗号在 alt 和 title 之间是可选的（AI 有时用空格代替逗号）
                $content = preg_replace_callback('/\[\[MEDIA_(\d+)(?:\(alt:\s*([\s\S]*?)\s*(?:,\s*)?title:\s*([\s\S]*?)\))?\]\]/is', function($m) use ($placeholders) {
                    $index = $m[1];
                    $key = "[[MEDIA_{$index}]]";
                    
                    // 如果在所有缓存中都找不到这个媒体标签
                    if (!isset($placeholders[$key])) {
                        // 尝试模糊匹配：如果缓存中只有一个媒体，直接用它（防止索引漂移）
                        if (count($placeholders) === 1) {
                            $tag = reset($placeholders);
                        } else {
                            return $m[0]; // 彻底没辙，保留原样
                        }
                    } else {
                        $tag = $placeholders[$key];
                    }

                    $tag = todo_normalize_media_tag_path($tag);
                    
                    // 提取 AI 生成的 alt/title (如果存在)
                    $alt_val   = isset($m[2]) ? trim($m[2], " \t\n\r\0\x0B'\"") : '';
                    $title_val = isset($m[3]) ? trim($m[3], " \t\n\r\0\x0B'\"") : '';

                    if ($alt_val !== '') {
                        if (strpos($tag, 'alt=') !== false) $tag = preg_replace('/alt=["\'][^"\']*["\']/i', 'alt="' . htmlspecialchars($alt_val, ENT_QUOTES, 'UTF-8') . '"', $tag);
                        else $tag = str_replace('<img', '<img alt="' . htmlspecialchars($alt_val, ENT_QUOTES, 'UTF-8') . '"', $tag);
                    }
                    if ($title_val !== '') {
                        if (strpos($tag, 'title=') !== false) $tag = preg_replace('/title=["\'][^"\']*["\']/i', 'title="' . htmlspecialchars($title_val, ENT_QUOTES, 'UTF-8') . '"', $tag);
                        else $tag = str_replace('<img', '<img title="' . htmlspecialchars($title_val, ENT_QUOTES, 'UTF-8') . '"', $tag);
                    }
                    return $tag;
                }, $content);
            }
            $translated_data[canonical_locale($target_lang)] = [
                'title' => $translations['title'] ?? '',
                'excerpt' => $translations['excerpt'] ?? '',
                'content' => $content,
                'seo_title' => $translations['seo_title'] ?? '',
                'seo_keywords' => $translations['seo_keywords'] ?? '',
                'seo_description' => $translations['seo_description'] ?? ''
            ];
            $results['success_count']++;
        } else {
            // 传统的语言映射结构
            foreach ($translations as $lang => $trans) {
                if (in_array($lang, ['version', 'type', 'post_id', 'post_title', 'source_language', 'target_languages', 'items', 'placeholders', 'instructions'])) continue;
                
                $lang_key = canonical_locale($lang);
                try {
                    if (!is_array($trans)) continue;

                    $content = $trans['content'] ?? '';
                    if (!empty($placeholders)) {
                        $content = preg_replace_callback('/\[\[MEDIA_(\d+)(?:\(alt:\s*([\s\S]*?)\s*(?:,\s*)?title:\s*([\s\S]*?)\))?\]\]/is', function($m) use ($placeholders) {
                            $index = $m[1];
                            $key = "[[MEDIA_{$index}]]";
                            
                            if (!isset($placeholders[$key])) {
                                if (count($placeholders) === 1) $tag = reset($placeholders);
                                else return $m[0];
                            } else {
                                $tag = $placeholders[$key];
                            }

                            $tag = todo_normalize_media_tag_path($tag);
                            
                            $alt_val   = isset($m[2]) ? trim($m[2], " \t\n\r\0\x0B'\"") : '';
                            $title_val = isset($m[3]) ? trim($m[3], " \t\n\r\0\x0B'\"") : '';

                            if ($alt_val !== '') {
                                if (strpos($tag, 'alt=') !== false) $tag = preg_replace('/alt=["\'][^"\']*["\']/i', 'alt="' . htmlspecialchars($alt_val, ENT_QUOTES, 'UTF-8') . '"', $tag);
                                else $tag = str_replace('<img', '<img alt="' . htmlspecialchars($alt_val, ENT_QUOTES, 'UTF-8') . '"', $tag);
                            }
                            if ($title_val !== '') {
                                if (strpos($tag, 'title=') !== false) $tag = preg_replace('/title=["\'][^"\']*["\']/i', 'title="' . htmlspecialchars($title_val, ENT_QUOTES, 'UTF-8') . '"', $tag);
                                else $tag = str_replace('<img', '<img title="' . htmlspecialchars($title_val, ENT_QUOTES, 'UTF-8') . '"', $tag);
                            }
                            return $tag;
                        }, $content);
                    }

                    $translated_data[$lang_key] = [
                        'title' => $trans['title'] ?? '',
                        'excerpt' => $trans['excerpt'] ?? '',
                        'content' => $content,
                        'seo_title' => $trans['seo_title'] ?? '',
                        'seo_keywords' => $trans['seo_keywords'] ?? '',
                        'seo_description' => $trans['seo_description'] ?? ''
                    ];
                    $results['success_count']++;
                } catch (Exception $e) {
                    $results['error_count']++;
                    $results['errors'][] = "语言 {$lang}: " . $e->getMessage();
                }
            }
        }

        echo json_encode([
            'success' => true,
            'message' => "解析完成：成功 {$results['success_count']} 种语言",
            'data' => $translated_data,
            'results' => $results
        ]);
        break;

    case 'save_enabled_languages':
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $enabled_languages = isset($_POST['enabled_languages']) ? $_POST['enabled_languages'] : [];

        if ($post_id <= 0) {
            echo json_encode(['success' => false, 'message' => '参数错误']);
            break;
        }

        if (!is_array($enabled_languages)) {
            $enabled_languages = [];
        }

        // 过滤和清理语言代码
        $enabled_languages = array_map('sanitize_text_field', $enabled_languages);
        $enabled_languages = array_filter($enabled_languages);
        $enabled_languages = array_values(array_unique($enabled_languages));

        // 确保主稿语言始终在列表中（如果已指定）
        $default_language = function_exists('get_default_language') ? get_default_language() : 'zh_CN';
        $default_lang_canonical = str_replace('_', '-', $default_language);
        if (strpos($default_lang_canonical, '-') !== false) {
            list($l, $r) = explode('-', $default_lang_canonical, 2);
            $default_lang_canonical = strtolower($l) . '-' . strtoupper($r);
        }
        if (!in_array($default_lang_canonical, $enabled_languages)) {
            $enabled_languages[] = $default_lang_canonical;
        }

        // 保存到 post_meta
        $result = update_post_meta($post_id, '_enabled_languages', json_encode($enabled_languages));

        if ($result !== false) {
            // 清理缓存
            if (class_exists('Todo_Unified_Cache_Manager')) {
                Todo_Unified_Cache_Manager::clean_post_cache($post_id);
            }
            if (function_exists('todo_do_action')) {
                todo_do_action('todo_post_updated', $post_id);
            }
            echo json_encode([
                'success' => true,
                'message' => '语言设置已保存',
                'enabled' => $enabled_languages
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['success' => false, 'message' => '保存失败']);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => '未知操作: ' . ($action ?? 'none')]);
        break;
}

exit;
