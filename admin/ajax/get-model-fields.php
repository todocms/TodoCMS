<?php
/**
 * 获取数据模型字段AJAX处理
 * 根据模型ID返回对应的字段列表
 */

// 定义必要常量
if (!defined('TODO_LOADED')) {
    define('TODO_LOADED', true);
}

if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

// 开启输出缓冲，防止杂项输出破坏JSON
if (function_exists('ob_start')) {
    ob_start();
}

// 统一JSON输出，清理可能的缓冲与杂项输出，确保返回可解析JSON（提前定义以便所有分支使用）
if (!function_exists('todo_ajax_json')) {
    function todo_ajax_json($payload)
    {
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
}

// 直接访问保护
if (
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
) {
    http_response_code(403);
    todo_ajax_json(['success' => false, 'error' => 'Direct access denied']);
}

// 设置基础路径
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}
if (file_exists(ABSPATH . 'core/bootstrap.php')) {
    require_once ABSPATH . 'core/bootstrap.php';
}

if (!class_exists('Todo_Function_Loader') && file_exists(ABSPATH . 'core/functions/function-loader.php')) {
    require_once ABSPATH . 'core/functions/function-loader.php';
}
if (class_exists('Todo_Function_Loader')) {
    Todo_Function_Loader::load_core_functions();
}

if (!function_exists('todo_admin_url') && file_exists(ABSPATH . 'admin/includes/todo-functions.php')) {
    require_once ABSPATH . 'admin/includes/todo-functions.php';
}

// 加载安全管理器（管理员上下文）
if (!defined('IS_ADMIN')) {
    define('IS_ADMIN', true);
}
// 加载Composer autoloader
if (!class_exists('Todo_Security_Manager')) {
    if (file_exists(ABSPATH . 'vendor/autoload.php')) {
        require_once ABSPATH . 'vendor/autoload.php';
    }
}

// 使用静态方法进行CSRF校验，动作与页面表单生成的令牌一致
$csrf_ok = Todo_Security_Manager::verify_request_csrf('category_action', '_todononce');
if (!$csrf_ok) {
    // 兼容：来自分类编辑页的有效Referer且管理员已登录时放行
    if (!function_exists('admin_logged_in')) {
        if (file_exists(ABSPATH . 'admin/includes/todo-functions.php')) {
        }
        if (file_exists(ABSPATH . 'core/functions/admin.php')) {
        }
    }
    $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
    $from_categories_page = strpos($referer, '/admin/index.php?page=categories') !== false;
    if (!is_user_logged_in() || !$from_categories_page) {
        header('HTTP/1.1 403 Forbidden');
        todo_ajax_json(['success' => false, 'error' => 'CSRF verification failed']);
    }
}

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    todo_ajax_json(array('success' => false, 'error' => 'Invalid request method'));
}

// 获取请求参数
$action = isset($_POST['action']) ? trim(strip_tags($_POST['action'] ?? '')) : '';
$categories = isset($_POST['categories']) ? $_POST['categories'] : array();

$model_id = isset($_POST['model_id']) ? intval($_POST['model_id']) : 0;
$for_category = isset($_POST['for_category']) ? intval($_POST['for_category']) : 0;

// 验证action
if ($action !== 'get_model_fields') {
    todo_ajax_json(array('success' => false, 'error' => 'Invalid action'));
}

// 权限校验：必须为管理员并具备分类管理或文章编辑权限
if (!is_user_logged_in() || !(current_user_can('manage_categories') || current_user_can('edit_posts') || current_user_can('publish_posts'))) {
    header('HTTP/1.1 403 Forbidden');
    todo_ajax_json(['success' => false, 'error' => 'Permission denied']);
}

// 支持两种模式：
// 1) 基于已选择的分类IDs来计算模型集合与字段（编辑已有分类、多选分类场景）
// 2) 基于模型ID直接返回该模型的字段（新建分类、尚无term_id场景）

$category_ids = array();
if (!empty($categories) && is_array($categories)) {
    // 清理分类ID
    $category_ids = array_map('intval', $categories);
    $category_ids = array_filter($category_ids, function ($id) {
        return $id > 0;
    });
}

if (empty($category_ids) && $model_id <= 0) {
    todo_ajax_json(array('success' => false, 'error' => 'No categories or model_id provided'));
}

try {
    // 引入必要的文件
    require_once ABSPATH . 'config.php';
    // 术语元数据函数（用于读取已有分类的字段值）
    require_once ABSPATH . 'core/functions/term-model-functions.php';

    // 初始化数据库连接
    global $tododb;
    if (!isset($tododb) || !($tododb instanceof CMS_DB)) {
        $tododb = CMS_DB::get_instance();
    }

    // 初始化模型管理器
    Todo_Model_Manager::init();

    // 计算模型集合
    $models = array();
    if (!empty($category_ids)) {
        foreach ($category_ids as $cat_id) {
            $sql = "SELECT m.model_key, m.model_name FROM " . $tododb->prefix . "term_models tm 
                    LEFT JOIN " . $tododb->prefix . "models m ON tm.model_id = m.model_id 
                    WHERE tm.term_id = ?";
            $result = $tododb->get_row($sql, [$cat_id]);
            if ($result && !empty($result->model_key)) {
                $models[$result->model_key] = $result->model_name;
            }
        }
    } elseif ($model_id > 0) {
        $model = $tododb->get_row("SELECT model_key, model_name FROM {$tododb->prefix}models WHERE model_id = ?", [$model_id]);
        if ($model) {
            $models[$model->model_key] = $model->model_name;
        }
    }

    if (empty($models)) {
        todo_ajax_json(array(
            'success' => true,
            'data' => array(
                'html' => '<div class="no-model-fields"><p class="text-muted">未找到数据模型。</p></div>',
                'categories' => $category_ids,
                'model_id' => $model_id
            )
        ));
    }

    // 获取模型字段
    $all_fields = array();
    foreach ($models as $model_key => $model_name) {
        // 查询数据库获取字段，考虑字段与分类的绑定关系
        if (!empty($category_ids)) {
            $category_ids_str = implode(',', array_map('intval', $category_ids));
            if ($for_category) {
                // 分类页面仅展示分类专属字段，并且遵循栏目可见性设置
                $sql = "SELECT mf.* FROM " . $tododb->prefix . "model_fields mf 
                        LEFT JOIN " . $tododb->prefix . "models m ON mf.model_id = m.model_id 
                        INNER JOIN " . $tododb->prefix . "term_field_visibility tfv ON mf.field_id = tfv.field_id 
                        WHERE m.model_key = ? AND mf.category_only = 1 AND mf.field_status = 'active'
                          AND tfv.term_id IN (" . $category_ids_str . ") AND tfv.is_visible = 1
                        ORDER BY mf.field_order ASC";
                $fields = $tododb->get_results($sql, [$model_key]);
            } else {
                // 使用UNION查询：无论是否分类专属，只要在所选栏目中标记为可见即返回（联合可见性）
                $sql = "SELECT DISTINCT * FROM (
                            (
                                SELECT mf.* FROM " . $tododb->prefix . "model_fields mf 
                                LEFT JOIN " . $tododb->prefix . "models m ON mf.model_id = m.model_id 
                                INNER JOIN " . $tododb->prefix . "term_field_visibility tfv ON mf.field_id = tfv.field_id 
                                WHERE m.model_key = ? AND mf.category_only = 0 AND mf.field_status = 'active'
                                  AND tfv.term_id IN (" . $category_ids_str . ") AND tfv.is_visible = 1
                            ) UNION (
                                SELECT mf.* FROM " . $tododb->prefix . "model_fields mf 
                                LEFT JOIN " . $tododb->prefix . "models m ON mf.model_id = m.model_id 
                                INNER JOIN " . $tododb->prefix . "term_field_visibility tfv ON mf.field_id = tfv.field_id 
                                WHERE m.model_key = ? AND mf.category_only = 1 AND mf.field_status = 'active'
                                  AND tfv.term_id IN (" . $category_ids_str . ") AND tfv.is_visible = 1
                            )
                        ) AS combined_fields ORDER BY field_order ASC";
                $fields = $tododb->get_results($sql, [$model_key, $model_key]);
            }
        } elseif ($model_id > 0) {
            // 直接按模型ID返回字段（用于新建分类或编辑分类加载模型字段）
            if ($for_category) {
                // 分类页面只显示category_only=1字段，且根据可见性控制
                $sql = "SELECT mf.* FROM {$tododb->prefix}model_fields mf WHERE mf.model_id = ? AND mf.category_only = 1 AND mf.field_status = 'active' ORDER BY mf.field_order ASC";
            } else {
                $sql = "SELECT mf.* FROM {$tododb->prefix}model_fields mf WHERE mf.model_id = ? AND mf.field_status = 'active' ORDER BY mf.field_order ASC";
            }
            $fields = $tododb->get_results($sql, [$model_id]);
        } else {
            // 如果没有分类ID且没有模型ID，只返回非分类限制的字段
            $sql = "SELECT DISTINCT mf.* FROM " . $tododb->prefix . "model_fields mf 
                    LEFT JOIN " . $tododb->prefix . "models m ON mf.model_id = m.model_id 
                    WHERE m.model_key = ? AND mf.category_only = 0 AND mf.field_status = 'active'
                    ORDER BY mf.field_order ASC";
            $fields = $tododb->get_results($sql, [$model_key]);
        }
        if (!empty($fields)) {
            foreach ($fields as $field) {
                // 解析字段选项，兼容多种存储格式（序列化、JSON、纯文本、数组）
                $field_options = array();
                if (isset($field->field_options) && !empty($field->field_options)) {
                    $raw = $field->field_options;
                    if (function_exists('maybe_unserialize')) {
                        $decoded = maybe_unserialize($raw);
                    } else {
                        $decoded = false;
                    }
                    if (is_array($decoded)) {
                        $field_options = $decoded;
                    } else {
                        $json_decoded = json_decode($raw, true);
                        if (is_array($json_decoded)) {
                            $field_options = $json_decoded;
                        } elseif (is_string($raw)) {
                            // 作为纯文本选项处理，每行格式 value|label 或仅 label
                            $lines = preg_split("/\r\n|\n|\r/", $raw);
                            $normalized = array();
                            foreach ($lines as $line) {
                                $line = trim($line);
                                if ($line === '')
                                    continue;
                                $parts = explode('|', $line, 2);
                                $val = trim($parts[0]);
                                $lab = isset($parts[1]) ? trim($parts[1]) : $val;
                                $normalized[$val] = $lab;
                            }
                            if (!empty($normalized)) {
                                $field_options = array('options' => $normalized);
                            }
                        }
                    }
                }

                $all_fields[] = array(
                    'field_name' => $field->field_key,
                    'field_title' => $field->field_label,
                    'field_type' => $field->field_type,
                    'field_note' => $field->field_description,
                    'is_required' => $field->is_required,
                    'field_default' => $field->field_default,
                    'field_options' => $field_options
                );
            }
        }
    }

    if (empty($all_fields)) {
        todo_ajax_json(array(
            'success' => true,
            'data' => array(
                'html' => '',
                'categories' => $category_ids
            )
        ));
    }

    // 生成字段HTML（统一使用 Todo_Custom_Field_Renderer 渲染）
    $html = '<div class="admin-card-header"><h3 class="admin-card-title">自定义字段</h3></div>';

    // 若仅编辑一个分类，预读取其已有字段值以便回填
    $existing_attributes = array();
    if (!empty($category_ids) && count($category_ids) === 1) {
        $existing_attributes = get_term_attributes($category_ids[0]);
        if (!is_array($existing_attributes)) {
            $existing_attributes = array();
        }
    }
    foreach ($all_fields as $field) {
        $field_id = 'field_' . $field['field_name'];
        $value = isset($existing_attributes[$field['field_name']]) ? $existing_attributes[$field['field_name']] : (isset($field['field_default']) ? $field['field_default'] : '');

        // 使用自定义字段渲染器统一渲染字段
        $html .= Todo_Custom_Field_Renderer::render_field([
            'field_key' => $field['field_name'],
            'field_label' => $field['field_title'],
            'field_type' => $field['field_type'],
            'field_description' => $field['field_note'],
            'is_required' => $field['is_required'],
            'field_options' => isset($field['field_options']) ? $field['field_options'] : []
        ], $value, [
            'name' => 'custom_' . $field['field_name'],
            'id' => $field_id
        ]);
    }

    todo_ajax_json(array(
        'success' => true,
        'data' => array(
            'html' => $html,
            'categories' => $category_ids,
            'models' => $models,
            'field_count' => count($all_fields)
        )
    ));

} catch (Throwable $e) {
    todo_ajax_json(array('success' => false, 'error' => 'Failed to load model fields: ' . $e->getMessage()));
}
