<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
/**
 * 文章分类管理页面
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

if (!current_user_can('manage_categories')) {
    todo_die('您没有足够的权限访问此页面。');
}

// 获取所有可用模型（按ID升序排序）
$available_models = get_available_models();
if (is_array($available_models)) {
    usort($available_models, function($a, $b) {
        return intval($a['model_id']) <=> intval($b['model_id']);
    });
}

// 获取当前操作
$action = isset($_GET['action']) ? $_GET['action'] : '';

$message = null;
$message_type = 'info';

// 引入分类元数据功能
require_once ABSPATH . 'core/db/term-attributes-schema.php';

// 引入模型字段组件
require_once ABSPATH . 'admin/components/model-fields-component.php';

// 引入特色图片组件（栏目图片选择器依赖）
if (!function_exists('todo_render_featured_images_component')) {
    require_once ABSPATH . 'admin/components/featured-images-component.php';
}

// 处理AJAX请求获取模型字段
if ($action === 'get_model_fields' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $model_id = isset($_POST['model_id']) ? intval($_POST['model_id']) : 0;
    
    if ($model_id > 0) {
        // 获取模型键
        $model_key = Todo_Model_Manager::get_model_key_by_id($model_id);
        if ($model_key) {
            // 使用get_component_model_fields获取所有字段（包括category_only=1的字段）
            $model_fields = get_component_model_fields($model_key, array(), true);
        } else {
            $model_fields = get_model_fields($model_id, true);
        }
        
        if (!empty($model_fields)) {
            ?>
            <h3>自定义字段</h3>
            <?php foreach ($model_fields as $field): ?>
                <div class="form-field">
                    <label for="custom-<?php echo $field['field_key']; ?>"><?php echo esc_html($field['field_label']); ?></label>
                    <?php render_field_input($field, '', 'custom_' . $field['field_key']); ?>
                    <?php if (!empty($field['field_description'])): ?>
                        <p><?php echo esc_html($field['field_description']); ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php
            $html = ob_get_clean();
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'html' => $html
            ]);
        } else {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => '该模型没有分类字段'
            ]);
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => '无效的模型ID'
        ]);
    }
    exit;
}

/**
 * 渲染字段输入控件
 *
 * @param array $field 字段配置
 * @param mixed $value 字段值
 * @param string $name_prefix 字段名前缀
 */
function render_field_input($field, $value = '', $name_prefix = '') {
    $field_key = isset($field['field_key']) ? $field['field_key'] : '';
    $field_value = $value;
    $field_name = $name_prefix ? $name_prefix : $field_key;
    $field_id = 'field_' . $field_key;
    $is_required = isset($field['is_required']) && $field['is_required'] == 1;

    // 统一调用渲染器并直接输出（确保名称使用传入的前缀，便于保存到 custom_ 前缀）
    echo Todo_Custom_Field_Renderer::render_field($field, $field_value, [
        'name' => $field_name,
        'id' => $field_id
    ]);
    return;
}

// 获取分类列表
$categories = get_categories(array(
    'hide_empty' => false,
    'orderby' => 'term_id',
    'order' => 'ASC'
));

// 批量预加载分类元数据缓存，避免N+1查询
if (!empty($categories)) {
    // 兼容对象和数组
    $term_ids = array_map(function($item) {
        return is_object($item) ? $item->term_id : $item['term_id'];
    }, $categories);
    update_term_meta_cache($term_ids);
}

// 处理分类添加
if (isset($_POST['add_category'])) {
    if (!Todo_Security_Manager::verify_request_csrf('category_action')) {
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice('安全验证失败，请重新提交。', 'error');
        }
        todo_redirect(todo_admin_url('index.php?page=categories'));
        exit;
    } else {
    $name = isset($_POST['cat_name']) ? trim($_POST['cat_name']) : '';
    $slug = isset($_POST['cat_slug']) ? trim($_POST['cat_slug']) : '';
    $description = isset($_POST['cat_description']) ? trim($_POST['cat_description']) : '';
    $parent = isset($_POST['cat_parent']) ? intval($_POST['cat_parent']) : 0;
    $model_id = isset($_POST['cat_model']) ? intval($_POST['cat_model']) : 0;
    
    if (empty($name)) {
        $message = '分类名称不能为空！';
        $message_type = 'error';
    } elseif (empty($model_id)) {
        $message = '必须选择数据模型！';
        $message_type = 'error';
    } else {
        // 如果没有提供别名，根据名称生成
        if (empty($slug)) {
            $slug = sanitize_title($name);
        }
        
        // 添加分类
        $result = todo_insert_term($name, 'category', array(
            'slug' => $slug,
            'description' => $description,
            'parent' => $parent
        ));
        
        if (is_todo_error($result)) {
            $message = $result->get_error_message();
            $message_type = 'error';
        } else {
            // 设置栏目关联的模型
            $term_id = $result['term_id'];
            $model_result = set_term_model($term_id, $model_id);
            
            // 保存扩展属性
            $is_hidden = isset($_POST['cat_is_hidden']) ? 1 : 0;
            $hidden_start = isset($_POST['cat_hidden_start']) ? trim($_POST['cat_hidden_start']) : '';
            $hidden_end = isset($_POST['cat_hidden_end']) ? trim($_POST['cat_hidden_end']) : '';
            $access_level = isset($_POST['cat_access_level']) ? intval($_POST['cat_access_level']) : 0;
            $list_template = isset($_POST['cat_list_template']) ? trim($_POST['cat_list_template']) : '';
            $view_template = isset($_POST['cat_view_template']) ? trim($_POST['cat_view_template']) : '';
            
            // 处理栏目图片数据（支持特色图片组件格式）
            $category_image = '';
            if (isset($_POST['cat_image'])) {
                $cat_image_input = $_POST['cat_image'];
                if (is_string($cat_image_input) && $cat_image_input !== '') {
                    // 检查是否为JSON格式
                    $decoded = json_decode($cat_image_input, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $category_image = $cat_image_input; // 保存JSON格式
                    } else {
                        $category_image = trim($cat_image_input); // 保存字符串格式
                    }
                }
            }
            
            $seo_title = isset($_POST['cat_seo_title']) ? trim($_POST['cat_seo_title']) : '';
            $seo_keywords = isset($_POST['cat_seo_keywords']) ? (function_exists('todo_sanitize_keywords') ? todo_sanitize_keywords($_POST['cat_seo_keywords']) : trim($_POST['cat_seo_keywords'])) : '';
            $seo_description = isset($_POST['cat_seo_description']) ? trim($_POST['cat_seo_description']) : '';
            
            update_term_meta($term_id, 'is_hidden', $is_hidden);
            update_term_meta($term_id, 'hidden_start', $hidden_start);
            update_term_meta($term_id, 'hidden_end', $hidden_end);
            update_term_meta($term_id, 'access_level', $access_level);
            update_term_meta($term_id, 'list_template', $list_template);
            update_term_meta($term_id, 'view_template', $view_template);
            update_term_meta($term_id, 'category_image', $category_image);
            update_term_meta($term_id, 'seo_title', $seo_title);
            update_term_meta($term_id, 'seo_keywords', $seo_keywords);
            update_term_meta($term_id, 'seo_description', $seo_description);
            $key_ctx = 'category:' . intval($term_id);
            if (class_exists('Todo_Cache_Manager')) {
                $langs = array(null);
                if (class_exists('LanguageRegistry')) {
                    $registry = LanguageRegistry::get_instance();
                    $lang_map = $registry->get_languages(true);
                    if (is_array($lang_map)) { $langs = array_keys($lang_map); }
                }
                foreach ($langs as $loc) {
                    Todo_Cache_Manager::delete('page_title|' . $key_ctx, 'fragments', $loc);
                    Todo_Cache_Manager::delete('meta_title|' . $key_ctx, 'fragments', $loc);
                    Todo_Cache_Manager::delete('og_title|' . $key_ctx, 'fragments', $loc);
                }
            }
            
            // 保存自定义字段值
            if ($model_id > 0) {
                // 获取模型键
                $model_key = Todo_Model_Manager::get_model_key_by_id($model_id);
                if ($model_key) {
                    // 使用get_component_model_fields获取所有字段（包括category_only=1的字段）
                    $model_fields = get_component_model_fields($model_key, array($term_id), true);
                } else {
                    $model_fields = get_model_fields($model_id, true); // 仅获取分类字段
                }
                
                foreach ($model_fields as $field) {
                    $field_key = 'custom_' . $field['field_key'];
                    if (isset($_POST[$field_key])) {
                        $field_value = $_POST[$field_key];
                        update_term_meta($term_id, $field['field_key'], $field_value);
                    }
                }
            }
            
            if ($model_result) {
                // PRG: 保存成功后重定向，防止重复提交
                if (function_exists('todo_add_admin_notice')) {
                    todo_add_admin_notice('分类已成功添加。', 'success');
                }
                todo_redirect(todo_admin_url('index.php?page=categories&action=edit&id=' . intval($term_id), true));
                exit;
            } else {
                if (function_exists('todo_add_admin_notice')) {
                    todo_add_admin_notice('分类已添加，但模型关联失败，请检查模型配置。', 'warning');
                }
                todo_redirect(todo_admin_url('index.php?page=categories&action=edit&id=' . intval($term_id), true));
                exit;
            }
        }
    }
    }
}

// 处理分类编辑
if (isset($_POST['edit_category'])) {
    if (!Todo_Security_Manager::verify_request_csrf('category_action')) {
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice('安全验证失败，请重新提交。', 'error');
        }
        todo_redirect(todo_admin_url('index.php?page=categories'));
        exit;
    } else {
    $term_id = isset($_POST['term_id']) ? intval($_POST['term_id']) : 0;
    $name = isset($_POST['cat_name']) ? trim($_POST['cat_name']) : '';
    $slug = isset($_POST['cat_slug']) ? trim($_POST['cat_slug']) : '';
    $description = isset($_POST['cat_description']) ? trim($_POST['cat_description']) : '';
    $parent = isset($_POST['cat_parent']) ? intval($_POST['cat_parent']) : 0;
    $model_id = isset($_POST['cat_model']) ? intval($_POST['cat_model']) : 0;
    
    if (empty($name)) {
        $message = '分类名称不能为空！';
        $message_type = 'error';
    } elseif (empty($model_id)) {
        $message = '必须选择数据模型！';
        $message_type = 'error';
    } else {
        // 如果没有提供别名，根据名称生成
        if (empty($slug)) {
            $slug = sanitize_title($name);
        }
        
        // 更新分类
        $result = todo_update_term($term_id, 'category', array(
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'parent' => $parent
        ));
        
        if (is_todo_error($result)) {
            $message = $result->get_error_message();
            $message_type = 'error';
        } else {
            // 更新栏目关联的模型
            $model_result = set_term_model($term_id, $model_id);
            
            // 更新扩展属性
            $is_hidden = isset($_POST['cat_is_hidden']) ? 1 : 0;
            $hidden_start = isset($_POST['cat_hidden_start']) ? trim($_POST['cat_hidden_start']) : '';
            $hidden_end = isset($_POST['cat_hidden_end']) ? trim($_POST['cat_hidden_end']) : '';
            $access_level = isset($_POST['cat_access_level']) ? intval($_POST['cat_access_level']) : 0;
            $list_template = isset($_POST['cat_list_template']) ? trim($_POST['cat_list_template']) : '';
            $view_template = isset($_POST['cat_view_template']) ? trim($_POST['cat_view_template']) : '';
            $category_image = isset($_POST['cat_image']) ? trim($_POST['cat_image']) : '';
            $seo_title = isset($_POST['cat_seo_title']) ? trim($_POST['cat_seo_title']) : '';
            $seo_keywords = isset($_POST['cat_seo_keywords']) ? (function_exists('todo_sanitize_keywords') ? todo_sanitize_keywords($_POST['cat_seo_keywords']) : trim($_POST['cat_seo_keywords'])) : '';
            $seo_description = isset($_POST['cat_seo_description']) ? trim($_POST['cat_seo_description']) : '';
            
            update_term_meta($term_id, 'is_hidden', $is_hidden);
            update_term_meta($term_id, 'hidden_start', $hidden_start);
            update_term_meta($term_id, 'hidden_end', $hidden_end);
            update_term_meta($term_id, 'access_level', $access_level);
            update_term_meta($term_id, 'list_template', $list_template);
            update_term_meta($term_id, 'view_template', $view_template);
            update_term_meta($term_id, 'category_image', $category_image);
            update_term_meta($term_id, 'seo_title', $seo_title);
            update_term_meta($term_id, 'seo_keywords', $seo_keywords);
            update_term_meta($term_id, 'seo_description', $seo_description);
            $key_ctx = 'category:' . intval($term_id);
            if (class_exists('Todo_Cache_Manager')) {
                $langs = array(null);
                if (class_exists('LanguageRegistry')) {
                    $registry = LanguageRegistry::get_instance();
                    $lang_map = $registry->get_languages(true);
                    if (is_array($lang_map)) { $langs = array_keys($lang_map); }
                }
                foreach ($langs as $loc) {
                    Todo_Cache_Manager::delete('page_title|' . $key_ctx, 'fragments', $loc);
                    Todo_Cache_Manager::delete('meta_title|' . $key_ctx, 'fragments', $loc);
                    Todo_Cache_Manager::delete('og_title|' . $key_ctx, 'fragments', $loc);
                }
            }
            
            // 更新自定义字段值
            if ($model_id > 0) {
                // 获取模型键
                $model_key = Todo_Model_Manager::get_model_key_by_id($model_id);
                if ($model_key) {
                    // 使用get_component_model_fields获取所有字段（包括category_only=1的字段）
                    $model_fields = get_component_model_fields($model_key, array($term_id), true);
                } else {
                    $model_fields = get_model_fields($model_id, true); // 仅获取分类字段
                }
                
                foreach ($model_fields as $field) {
                    $field_key = 'custom_' . $field['field_key'];
                    if (isset($_POST[$field_key])) {
                        $field_value = $_POST[$field_key];
                        update_term_meta($term_id, $field['field_key'], $field_value);
                    }
                }
            }
            
            if ($model_result) {
                // PRG: 更新成功后重定向，防止重复提交
                if (function_exists('todo_add_admin_notice')) {
                    todo_add_admin_notice('分类已成功更新。', 'success');
                }
                todo_redirect(todo_admin_url('index.php?page=categories&action=edit&id=' . intval($term_id), true));
                exit;
            } else {
                if (function_exists('todo_add_admin_notice')) {
                    todo_add_admin_notice('分类已更新，但模型关联失败，请检查模型配置。', 'warning');
                }
                todo_redirect(todo_admin_url('index.php?page=categories&action=edit&id=' . intval($term_id), true));
                exit;
            }
        }
    }
    }
}

// 处理单个操作：移至回收站
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'trash' && isset($_POST['id'])) {
    if (!Todo_Security_Manager::verify_request_csrf('category_action')) {
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice('安全验证失败，请重新提交。', 'error');
        }
        todo_redirect(todo_admin_url('index.php?page=categories'));
        exit;
    }

    $term_id = intval($_POST['id']);
    $result = todo_trash_term($term_id, 'category');
    if (is_todo_error($result)) {
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice($result->get_error_message(), 'error');
        }
        todo_redirect(todo_admin_url('index.php?page=categories'));
        exit;
    }
    if (function_exists('todo_add_admin_notice')) {
        todo_add_admin_notice('分类已移至回收站！', 'success');
    }
    todo_redirect(todo_admin_url('index.php?page=categories'));
    exit;
}

// 获取所有可用站点（用于变更站点功能）
$all_sites = [];
$is_super_admin = (function_exists('todo_is_super_admin') && todo_is_super_admin());
if ($is_super_admin) {
    global $tododb;
    if ($tododb->table_exists('sites')) {
        $all_sites = $tododb->get_results("SELECT id, name FROM {$tododb->prefix}sites ORDER BY id ASC");
    }
}

// 处理批量操作（含移至回收站/恢复/永久删除/变更站点）
if (isset($_POST['bulk_action']) && isset($_POST['category_ids']) && is_array($_POST['category_ids'])) {
    if (!Todo_Security_Manager::verify_request_csrf('category_action')) {
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice('安全验证失败，请重新提交。', 'error');
        }
        todo_redirect(todo_admin_url('index.php?page=categories'));
        exit;
    }

    $bulk_action = $_POST['bulk_action'];
    $category_ids = array_map('intval', $_POST['category_ids']);

    if ($bulk_action === 'trash') {
        $count = 0;
        foreach ($category_ids as $term_id) {
            $result = todo_trash_term($term_id, 'category');
            if (!is_todo_error($result)) {
                $count++;
            }
        }

        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice($count . ' 个分类已移至回收站', 'success');
        }
        todo_redirect(todo_admin_url('index.php?page=categories'));
        exit;
    } elseif ($bulk_action === 'change_site') {
        if ($is_super_admin) {
            global $tododb;
            // 支持多选：target_site_id[] 为数组；若未提交或为空则视为「全局可见」
            $raw_site_ids = isset($_POST['target_site_id']) ? (array) $_POST['target_site_id'] : [];
            $target_site_ids = array_values(array_unique(array_filter(array_map('intval', $raw_site_ids), function ($v) { return $v > 0; })));

            $count = 0;
            foreach ($category_ids as $term_id) {
                // 先清除旧映射
                $tododb->delete($tododb->prefix . 'site_terms', ['term_id' => $term_id]);
                if (!empty($target_site_ids)) {
                    // 绑定选中的各站点
                    foreach ($target_site_ids as $sid) {
                        todo_ensure_term_site_mapping($term_id, ['site_id' => $sid]);
                    }
                }
                // 若 $target_site_ids 为空，不写入任何映射 → 分类对所有站点可见（全局）
                $count++;
            }
            if (function_exists('todo_add_admin_notice')) {
                if (empty($target_site_ids)) {
                    todo_add_admin_notice(sprintf('已将 %d 个分类设为全局可见（不绑定任何站点）。', $count), 'success');
                } else {
                    todo_add_admin_notice(sprintf('已将 %d 个分类归属变更至站点：%s。', $count, implode(', ', array_map(function($s){ return 'site'.$s; }, $target_site_ids))), 'success');
                }
            }
        }
        todo_redirect(todo_admin_url('index.php?page=categories'));
        exit;
    }
}

// 获取当前编辑的分类
$edit_category = null;
$edit_category_model = null;
$edit_category_meta = array();
$edit_category_custom_fields = array();
if ($action === 'edit' && isset($_GET['id'])) {
    $term_id = intval($_GET['id']);
    $edit_category_data = get_term($term_id, 'category');
    if ($edit_category_data) {
        $edit_category = (object) $edit_category_data;
        // 获取分类元数据
        $edit_category_meta = get_term_attributes($term_id);
        // 获取关联模型
        $edit_category_model = get_term_model($term_id);
        // 获取自定义字段数据
        if ($edit_category_model && isset($edit_category_model['model_id'])) {
            $model_fields = get_term_visible_fields($term_id, true);
            foreach ($model_fields as $field) {
                $field_value = get_term_attributes($term_id, $field['field_key'], true);
                $edit_category_custom_fields[$field['field_key']] = $field_value;
            }
        }
    }
}

// 获取分页参数
$per_page = 20; // 每页显示的分类数量
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

// 获取所有分类（复用上方已获取并过滤的列表）
// $categories 已在文件头部获取并过滤了回收站分类

// 构建分类层级结构
$hierarchical_categories = array();
function build_category_hierarchy($categories, $parent = 0, $level = 0) {
    $result = array();
    
    foreach ($categories as $category) {
        if ($category->parent == $parent) {
            $category->level = $level;
            $result[] = $category;
            $children = build_category_hierarchy($categories, $category->term_id, $level + 1);
            $result = array_merge($result, $children);
        }
    }
    
    return $result;
}

$hierarchical_categories = build_category_hierarchy($categories);

// 计算总分类数
$total_categories = count($hierarchical_categories);

// 分页处理
$total_pages = ceil($total_categories / $per_page);
$offset = ($current_page - 1) * $per_page;

// 获取当前页的分类
$paged_categories = array_slice($hierarchical_categories, $offset, $per_page);

// 批量预加载栏目模型缓存，避免N+1查询
if (!empty($paged_categories)) {
    // 兼容对象和数组
    $term_ids = array_map(function($item) {
        return is_object($item) ? $item->term_id : $item['term_id'];
    }, $paged_categories);
    update_term_model_cache($term_ids);
}

// --- 全局数据作用域：预加载 site badge 映射 ---
global $tododb;
$__todo_show_site_badge = false;
$__todo_term_site_badges = [];
if (isset($tododb) && is_object($tododb)) {
    // 判断当前用户是否为管理员
    $is_admin_for_site_badge = false;
    $uid_for_site_badge = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    if ($uid_for_site_badge > 0 && class_exists('Todo_Session_Manager') && method_exists('Todo_Session_Manager', 'can_access_admin')) {
        $is_admin_for_site_badge = (bool) Todo_Session_Manager::can_access_admin($uid_for_site_badge);
    }
    if (!$is_admin_for_site_badge && function_exists('todo_get_current_user')) {
        $u = todo_get_current_user();
        $roles_raw = [];
        if (is_array($u)) {
            if (isset($u['roles'])) { $roles_raw = $u['roles']; }
            elseif (isset($u['meta']) && is_array($u['meta']) && isset($u['meta']['roles'])) { $roles_raw = $u['meta']['roles']; }
            elseif (isset($u['role'])) { $roles_raw = $u['role']; }
        } elseif (is_object($u)) {
            if (isset($u->roles)) { $roles_raw = $u->roles; }
            elseif (isset($u->meta) && is_array($u->meta) && isset($u->meta['roles'])) { $roles_raw = $u->meta['roles']; }
            elseif (isset($u->role)) { $roles_raw = $u->role; }
        }
        $roles = is_array($roles_raw) ? $roles_raw : ($roles_raw ? [$roles_raw] : []);
        $roles = array_map(function ($r) { return strtolower(trim((string) $r)); }, $roles);
        $is_admin_for_site_badge = !empty(array_intersect(['super_admin', 'superadmin', 'admin', 'administrator'], $roles));
    }
    if (!$is_admin_for_site_badge && function_exists('todo_is_super_admin') && todo_is_super_admin()) {
        $is_admin_for_site_badge = true;
    }
    if (!$is_admin_for_site_badge && function_exists('current_user_can') && current_user_can('manage_options')) {
        $is_admin_for_site_badge = true;
    }

    // 判断是否多站点实例（活跃站点数>1）
    $is_multisite_instance = false;
    if ($is_admin_for_site_badge && $tododb->table_exists('sites')) {
        $sites_table = $tododb->prefix . 'sites';
        $active_site_count = (int) $tododb->get_var("SELECT COUNT(*) FROM {$sites_table} WHERE status = 'active'");
        $is_multisite_instance = ($active_site_count > 1);
    }

    $__todo_show_site_badge = ($is_multisite_instance && $is_admin_for_site_badge);
    // 全局数据作用域下：只要是管理员且 sites 表存在，强制显示 badge 以区分分类归属
    if (!$__todo_show_site_badge && $is_admin_for_site_badge && $tododb->table_exists('sites')) {
        $__todo_show_site_badge = true;
    }

    if ($__todo_show_site_badge && !empty($paged_categories) && $tododb->table_exists('site_terms')) {
        $term_ids_for_map = [];
        foreach ($paged_categories as $c) {
            if (is_object($c) && isset($c->term_id)) {
                $term_ids_for_map[] = (int) $c->term_id;
            } elseif (is_array($c) && isset($c['term_id'])) {
                $term_ids_for_map[] = (int) $c['term_id'];
            }
        }
        $term_ids_for_map = array_values(array_unique(array_filter($term_ids_for_map, function ($v) {
            return (int) $v > 0;
        })));

        if (!empty($term_ids_for_map)) {
            $placeholders = implode(',', array_fill(0, count($term_ids_for_map), '?'));
            $site_terms_table = $tododb->prefix . 'site_terms';
            $rows = $tododb->get_results(
                "SELECT term_id, GROUP_CONCAT(DISTINCT site_id ORDER BY site_id ASC SEPARATOR ',') AS site_ids
                 FROM {$site_terms_table}
                 WHERE term_id IN ({$placeholders}) AND is_visible = 1
                 GROUP BY term_id",
                $term_ids_for_map
            );
            foreach ((array) $rows as $r) {
                if (!is_object($r) || !isset($r->term_id) || !isset($r->site_ids)) { continue; }
                $ids = array_values(array_filter(array_map('intval', explode(',', (string) $r->site_ids)), function ($v) {
                    return (int) $v > 0;
                }));
                if (empty($ids)) { continue; }
                $labels = array_map(function ($sid, $index) { 
                    return ($index === 0 ? 'site' : '') . (int) $sid; 
                }, $ids, array_keys($ids));
                $__todo_term_site_badges[(int) $r->term_id] = implode(',', $labels);
            }
        }
    }
}
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title"><?php echo $action === 'add' ? '添加新分类' : ($action === 'edit' ? '编辑分类' : '分类列表'); ?></h2>
        <?php if ($action !== 'add'): ?>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=categories&action=add'); ?>" class="button">添加新分类</a>
        </div>
        <?php endif; ?>
    </div>

    
    <div class="admin-row">
        <?php if ($action === 'add' || $action === 'edit'): ?>
        <div class="admin-col-l admin-card">
            <div class="admin-card-content" style="max-width: 530px;">
                <form method="post" action="<?php echo todo_admin_url('index.php?page=categories' . ($action === 'edit' ? '&action=edit&id=' . $term_id : '&action=add')); ?>" class="todo-request">
                    <?php echo Todo_Security_Manager::csrf_field('category_action', '_todononce'); ?>
                    <?php if ($action === 'edit' && $edit_category): ?>
                    <input type="hidden" name="term_id" value="<?php echo $edit_category->term_id; ?>">
                    <?php endif; ?>
                    
                    <div class="flex gap-x-5 flex-wrap justify-between">
                        <div class="form-group">
                            <label for="cat-name">栏目名称<span class="required">*</span></label>
                            <input type="text" name="cat_name" id="cat-name" value="<?php echo $edit_category ? esc_attr($edit_category->name) : ''; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="cat-slug">别名</label>
                            <input type="text" name="cat_slug" id="cat-slug" value="<?php echo $edit_category ? esc_attr($edit_category->slug) : ''; ?>">
                            <p class="description">在URL中使用的名称。</p>
                        </div>
                    </div>
                    
                    <div class="flex gap-x-5 flex-wrap justify-between">
                        <div class="form-group">
                            <label for="cat-parent">父分类</label>
                            <select name="cat_parent" id="cat-parent">
                                <option value="0">无</option>
                                <?php foreach ($hierarchical_categories as $category): ?>
                                    <?php if ($edit_category && $category->term_id === $edit_category->term_id) continue; ?>
                                    <option value="<?php echo $category->term_id; ?>" <?php echo $edit_category && $category->term_id === $edit_category->parent ? 'selected' : ''; ?>>
                                        <?php echo str_repeat('—', $category->level); ?> <?php echo esc_html(function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($category->name) : $category->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">分类层级关系。</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="cat-model">数据模型<span class="required">*</span></label>
                            <select name="cat_model" id="cat-model" style="max-width: 220px;" required>
                                <option value="">请选择数据模型</option>
                                <?php foreach ($available_models as $model): ?>
                                    <option value="<?php echo $model['model_id']; ?>" <?php echo $edit_category_model && $model['model_id'] === $edit_category_model['model_id'] ? 'selected' : ''; ?>>
                                        <?php echo esc_html($model['model_name']); ?> (<?php echo esc_html($model['model_key']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">数据模型决定内容字段和展示。</p>
                        </div>
                    </div>
                    
                    <div>
                        <label for="cat-description">描述</label>
                        <textarea name="cat_description" id="cat-description" class="w-100"><?php echo $edit_category ? esc_textarea($edit_category->description) : ''; ?></textarea>
                    </div>
                    
                    <!-- 扩展属性 -->
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">扩展属性</h3>
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="cat_is_hidden" value="1" <?php echo ($edit_category && isset($edit_category_meta['is_hidden']) && $edit_category_meta['is_hidden']) ? 'checked' : ''; ?>>
                            隐藏栏目
                        </label>
                        <p class="description">勾选后，此栏目将在前台隐藏。</p>
                        <div class="flex gap-5 flex-wrap m-t-10">
                            <div class="w-50">
                                <label for="cat-hidden-start">有效开始时间</label>
                                <input type="datetime-local" class="w-100" name="cat_hidden_start" id="cat-hidden-start" value="<?php echo $edit_category && isset($edit_category_meta['hidden_start']) ? esc_attr(str_replace(' ', 'T', $edit_category_meta['hidden_start'])) : ''; ?>">
                            </div>
                            <div class="w-50">
                                <label for="cat-hidden-end">有效结束时间</label>
                                <input type="datetime-local" class="w-100" name="cat_hidden_end" id="cat-hidden-end" value="<?php echo $edit_category && isset($edit_category_meta['hidden_end']) ? esc_attr(str_replace(' ', 'T', $edit_category_meta['hidden_end'])) : ''; ?>">
                            </div>
                        </div>
                        <p class="description">选填。设置之后，在有效时间以外将自动把该栏目屏蔽/隐藏。</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="cat-access-level">访问权限</label>
                        <select name="cat_access_level" id="cat-access-level">
                            <option value="0" <?php echo ($edit_category && isset($edit_category_meta['access_level']) && $edit_category_meta['access_level'] == 0) ? 'selected' : ''; ?>>公开访问</option>
                        <option value="1" <?php echo ($edit_category && isset($edit_category_meta['access_level']) && $edit_category_meta['access_level'] == 1) ? 'selected' : ''; ?>>会员访问</option>
                        <option value="2" <?php echo ($edit_category && isset($edit_category_meta['access_level']) && $edit_category_meta['access_level'] == 2) ? 'selected' : ''; ?>>管理员访问</option>
                        </select>
                        <p class="description">设置此栏目的访问权限级别。</p>
                    </div>
                    
                    <div class="flex gap-x-5 flex-wrap justify-between">
                        <div class="form-group">
                            <label for="cat-list-template">栏目模板</label>
                            <select name="cat_list_template" id="cat-list-template">
                                <?php if ($edit_category_model): ?>
                                    <?php
                                    $model_key = $edit_category_model['model_key'];
                                    $template_dir = get_template_directory();
                                    $saved_list_template = isset($edit_category_meta['list_template']) ? (string)$edit_category_meta['list_template'] : '';

                                    // 获取模型的默认列表模板（从 models 表的 list_template 字段）
                                    $model_default_list = $edit_category_model['list_template'] ?? '';
                                    $default_file = '';
                                    $default_name = '';
                                    if (!empty($model_default_list) && file_exists($template_dir . '/' . $model_default_list)) {
                                        $default_name = $model_default_list;
                                        $default_file = $template_dir . '/' . $model_default_list;
                                    } else {
                                        $default_file = $template_dir . '/lists_' . $model_key . '.html';
                                        $default_name = file_exists($default_file) ? basename($default_file) : '';
                                    }

                                    // 默认选中：未保存或等于默认模板文件名
                                    $use_default_selected = ($saved_list_template === '' || ($default_name !== '' && $saved_list_template === $default_name));
                                    ?>
                                    <option value="" <?php echo $use_default_selected ? 'selected' : ''; ?>>使用默认模板<?php echo $default_name ? ' (' . htmlspecialchars($default_name) . ')' : ''; ?></option>
                                    <?php
                                    // 收集 lists_<model_key>*.html 模板，避免默认模板重复
                                    $candidates = glob($template_dir . '/lists_' . $model_key . '*.html') ?: array();
                                    // 如果模型配置了自定义 list_template，按其词干也做 glob 匹配同类模板
                                    if (!empty($model_default_list)) {
                                        $stem = pathinfo($model_default_list, PATHINFO_FILENAME); // e.g. lists_custom-cat
                                        $siblings = glob($template_dir . '/' . $stem . '*.html') ?: [];
                                        $candidates = array_merge($candidates, $siblings);
                                    }
                                    $candidates = array_unique($candidates);
                                    $seen = array();
                                    foreach ($candidates as $file) {
                                        $name = basename($file);
                                        $base = preg_replace('/\.html$/i', '', $name);
                                        if (isset($seen[$base])) { continue; }
                                        $seen[$base] = true;
                                        // 默认模板不在下拉选项中展示，避免与“使用默认模板”重复
                                        if ($default_name !== '' && $name === $default_name) {
                                            continue;
                                        }
                                        $selected = (!$use_default_selected && $saved_list_template === $name) ? 'selected' : '';
                                        echo '<option value="' . htmlspecialchars($name) . '" ' . $selected . '>' . htmlspecialchars($name) . '</option>';
                                    }
                                    ?>
                                <?php else: ?>
                                    <option value="">使用默认模板</option>
                                <?php endif; ?>
                            </select>
                            <p class="description">选择此栏目使用的列表页模板。</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="cat-view-template">内容模板</label>
                            <select name="cat_view_template" id="cat-view-template">
                                <?php if ($edit_category_model): ?>
                                    <?php
                                    $model_key = $edit_category_model['model_key'];
                                    $template_dir = get_template_directory();
                                    $selected_meta = ($edit_category && isset($edit_category_meta['view_template'])) ? $edit_category_meta['view_template'] : '';
                                    
                                    // 获取模型默认 view_template（从 models 表）
                                    $model_default_view = $edit_category_model['view_template'] ?? '';
                                    if (is_dir($template_dir)) {
                                        $template_files = glob($template_dir . '/view_' . $model_key . '*.html') ?: [];
                                        // 如果模型配置了自定义 view_template，按其词干也做 glob 匹配同类模板
                                        if (!empty($model_default_view)) {
                                            $stem = pathinfo($model_default_view, PATHINFO_FILENAME); // e.g. view_custom-cat
                                            $siblings = glob($template_dir . '/' . $stem . '*.html') ?: [];
                                            $template_files = array_merge($template_files, $siblings);
                                        }
                                        $template_files = array_unique($template_files);
                                        
                                        // 默认模板：优先使用模型配置的 view_template，其次 view_{model_key}.html
                                        $default_display = '';
                                        if (!empty($model_default_view) && file_exists($template_dir . '/' . $model_default_view)) {
                                            $default_display = $model_default_view;
                                        } else {
                                            $default_file = $template_dir . '/view_' . $model_key . '.html';
                                            $default_display = file_exists($default_file) ? basename($default_file) : '';
                                        }
                                        // 使用默认模板选项，选中条件：未保存或等于默认文件名
                                        $selected_default = (empty($selected_meta) || (!empty($default_display) && $selected_meta === $default_display)) ? 'selected' : '';
                                        echo '<option value="" ' . $selected_default . '>使用默认模板' . ($default_display ? ' (' . esc_html($default_display) . ')' : '') . '</option>';
                                        // 其他匹配的模板（排除默认）
                                        foreach ($template_files as $template_file) {
                                            $display_name = basename($template_file);
                                            if ($default_display && $display_name === $default_display) { continue; }
                                            $selected_attr = ($selected_meta === $display_name) ? 'selected' : '';
                                            echo '<option value="' . esc_attr($display_name) . '" ' . $selected_attr . '>' . esc_html($display_name) . '</option>';
                                        }
                                    } else {
                                        // 主题目录不可用，仍显示默认选项
                                        $selected_default = empty($selected_meta) ? 'selected' : '';
                                        echo '<option value="" ' . $selected_default . '>使用默认模板</option>';
                                    }
                                    ?>
                                <?php else: ?>
                                    <option value="">使用默认模板</option>
                                <?php endif; ?>
                            </select>
                            <p class="description">选择此栏目内容页使用的模板。</p>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <h4>栏目图片</h4>
                        <div class="media-upload-group">
                            <?php
                            // 准备栏目图片数据
                            $cat_image_data = array();
                            if ($edit_category && isset($edit_category_meta['category_image']) && !empty($edit_category_meta['category_image'])) {
                                $cat_image_raw = $edit_category_meta['category_image'];
                                if (is_string($cat_image_raw) && $cat_image_raw !== '') {
                                    // 尝试解析JSON格式
                                    $decoded = json_decode($cat_image_raw, true);
                                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                        $cat_image_data = $decoded;
                                    } else {
                                        // 单个URL字符串
                                        $cat_image_data = array(
                                            array('url' => $cat_image_raw)
                                        );
                                    }
                                } elseif (is_array($cat_image_raw)) {
                                    $cat_image_data = $cat_image_raw;
                                }
                            }
                            
                            if (function_exists('todo_render_featured_images_component')) {
                                echo todo_render_featured_images_component(array(
                                    'field_id' => 'cat_image_group',
                                    'field_name' => 'cat_image',
                                    'current_images' => $cat_image_data,
                                    'multiple' => true,
                                    'max_images' => 6,
                                    'enable_debug' => false
                                ));
                            } else {
                                echo '<p class="description">特色图片组件未加载，无法渲染栏目图片选择器。</p>';
                            }
                            ?>
                        </div>
                        <p class="description">选择栏目的代表图片。</p>
                    </div>
                    
                    <!-- SEO设置 -->
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">栏目SEO设置</h3>
                    </div>
                    
                    <div class="flex gap-x-5 flex-wrap justify-between">
                        <div class="form-group">
                            <label for="cat-seo-title">SEO标题</label>
                            <input type="text" name="cat_seo_title" id="cat-seo-title" value="<?php echo $edit_category && isset($edit_category_meta['seo_title']) ? esc_attr($edit_category_meta['seo_title']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="cat-seo-keywords">SEO关键字</label>
                            <input type="text" name="cat_seo_keywords" id="cat-seo-keywords" value="<?php echo $edit_category && isset($edit_category_meta['seo_keywords']) ? esc_attr($edit_category_meta['seo_keywords']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div>
                        <label for="cat-seo-description">SEO描述</label>
                        <textarea name="cat_seo_description" id="cat-seo-description" rows="3" class="w-100"><?php echo $edit_category && isset($edit_category_meta['seo_description']) ? esc_textarea($edit_category_meta['seo_description']) : ''; ?></textarea>
                    </div>
                    
                    <!-- 自定义字段容器 -->
                    <div id="custom-fields-container"></div>
                    
                    <div class="form-submit">
                        <input type="submit" name="<?php echo $action === 'add' ? 'add_category' : 'edit_category'; ?>" class="button button-primary" value="<?php echo $action === 'add' ? '添加新分类' : '更新分类'; ?>">
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="admin-col-r admin-card">
            <?php if ($action === 'add' || $action === 'edit'): ?>
            <div class="admin-card-header">
                <h3 class="admin-card-title">分类列表</h3>
            </div>
            <?php endif; ?>

            <div class="admin-card-content">
                <form method="post" action="" id="categories-filter">
                    <?php echo Todo_Security_Manager::csrf_field('category_action', '_todononce'); ?>
                    <div class="bulk-actions-bar">
                        <div class="bulk-actions">
                            <select name="bulk_action" style="min-width: 140px;" onchange="todoToggleChangeSitePanel(this, 'cats-change-site-panel')">
                                <option value="-1">批量操作</option>
                                <option value="trash">移至回收站</option>
                                <?php if ($is_super_admin && count($all_sites) > 1): ?>
                                    <option value="change_site">变更站点</option>
                                <?php endif; ?>
                            </select>
                             <?php if ($is_super_admin && count($all_sites) > 1): ?>
                                <span id="cats-change-site-panel" style="display:none; flex-direction: column; align-items:center; gap:6px;">
                                    <select name="target_site_id[]" multiple style="min-width: 150px; height: 67px; padding: 2px 5px;" title="按住 Ctrl/Cmd 可多选；不选任何项则设为全局可见">
                                        <?php foreach ($all_sites as $site_item): ?>
                                            <option value="<?php echo esc_attr($site_item->id); ?>">site<?php echo (int)$site_item->id; ?> <?php echo esc_html($site_item->name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span style="font-size:12px;color:#888;">不选全局可见 shift↑+多选</span>
                                </span>
                            <?php endif; ?>
                            <input type="submit" class="button button-secondary" value="应用">
                        </div>
                    </div>
                    
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th class="check-column">
                                    <input type="checkbox" id="cb-select-all-1">
                                </th>
                                <th style="min-width: 180px;">名称</th>
                                <th style="min-width: 160px;">描述</th>
                                <th style="min-width: 80px;">文章</th>
                                <th style="min-width: 100px;">数据模型</th>
                            </tr>
                        </thead>
                        
                        <tbody>
                            <?php if (empty($paged_categories)): ?>
                            <tr>
                                <td colspan="6">
                                    <div class="no-items">
                                        <p>未找到分类。</p>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($paged_categories as $category): ?>
                                <?php
                                $format = (isset($_GET['format']) && $_GET['format'] === 'iframe') ? '&format=iframe' : '';
                                $cat_model = get_term_model($category->term_id);
                                $frontend_cat_url = function_exists('todo_get_category_link') ? todo_get_category_link($category->term_id) : (function_exists('get_category_link') ? get_category_link($category->term_id) : '#');
                                ?>
                                <tr>
                                    <th>
                                        <input type="checkbox" name="category_ids[]" value="<?php echo $category->term_id; ?>">
                                    </th>
                                    <td>
                                        <div class="flex gap-2" style="color: #999;">
                                            <?php if($category->level): ?>
                                            <div style="flex-shrink: 0;"><?php echo str_repeat('—', $category->level); ?></div>
                                            <?php endif; ?>
                                            <div>
                                                <?php if ($__todo_show_site_badge && isset($__todo_term_site_badges[$category->term_id])): ?>
                                                    <em class="site-badge" style="display: inline-block;font-size: 12px;color: #999;padding: 0 5px;font-weight: normal;background-color: #f1f1f1;max-width: 100px;overflow: hidden;text-overflow: ellipsis;vertical-align: middle;"><?php echo esc_html($__todo_term_site_badges[$category->term_id]); ?></em>
                                                <?php endif; ?>
                                                <strong>
                                                    <a href="<?php echo todo_admin_url('index.php?page=categories&action=edit&id=' . $category->term_id); ?>">
                                                        <?php echo esc_html(function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($category->name) : $category->name); ?>
                                                    </a>
                                                    <em style="display: inline-block; font-weight: normal;"><?php echo $category->slug; ?></em>
                                                </strong>
                                                <div class="row-actions">
                                                    <span class="edit"><a href="<?php echo todo_admin_url('index.php?page=categories&action=edit&id=' . $category->term_id); ?>">编辑</a> | </span>
                                                    <span class="trash"><a href="<?php echo todo_admin_url('index.php?page=categories&action=trash&id=' . $category->term_id); ?>" class="submitdelete js-todo-post-action" data-confirm="确定要将此分类移至回收站吗？">移至回收站</a> | </span>
                                                    <span class="view"><a href="<?php echo esc_url($frontend_cat_url); ?>" target="_blank">查看</a></span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><div class="tb-description"><?php echo function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($category->description) : $category->description; ?></div></td>
                                    <td class="posts column-posts">
                                        <a href="<?php echo todo_admin_url('index.php?page=posts&cat=' . (int)$category->term_id); ?>"><?php echo (int)$category->active_post_count; ?></a>
                                    </td>
                                    <td>
                                        <?php echo $cat_model ? esc_html($cat_model['model_name']) : '<span style="color:#999;">未设置</span>'; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th>
                                    <input type="checkbox" id="cb-select-all-2">
                                </th>
                                <th>名称</th>
                                <th>描述</th>
                                <th>文章</th>
                                <th>数据模型</th>
                            </tr>
                        </tfoot>
                    </table>
                    
                    <!-- 分页导航 -->
                    <div class="admin-pagination">
                        <span class="info">共 <?php echo $total_categories; ?> 个分类</span>
                        <?php 
                        // 生成分页链接
                        echo paginate_links(array(
                            'total' => $total_categories,
                            'per_page' => $per_page,
                            'current_page' => $current_page,
                            'base_url' => 'index.php?page=categories',
                            'add_fragment' => isset($_GET['action']) ? '&action=' . $_GET['action'] : ''
                        ));
                        ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- 模型字段组件样式 -->
<style>
.model-field .form-text {
    margin-top: 5px;
    font-size: 13px;
    color: #aaa;
    font-style: italic;
    display: block;
}

.model-field input[type="checkbox"], .model-field input[type="radio"] {
    margin-right: 5px;
}
</style>

<script type="text/javascript">
// 切换"变更站点"多选面板显示
function todoToggleChangeSitePanel(selectEl, panelId) {
    var panel = document.getElementById(panelId);
    if (!panel) return;
    panel.style.display = (selectEl.value === 'change_site') ? 'flex' : 'none';
}

// 图片选择功能
var currentImageFieldId = '';
var isMultipleSelection = false;

function selectImage(fieldId) {
    currentImageFieldId = fieldId;
    isMultipleSelection = false;
    showUploadModal();
}

function selectSingleImage(fieldId) {
    currentImageFieldId = fieldId;
    isMultipleSelection = false;
    showUploadModal();
}

function selectMultipleImages(fieldId) {
    currentImageFieldId = fieldId;
    isMultipleSelection = true;
    showUploadModal();
}

function setSelectedMedia(media) {
    if (isMultipleSelection) {
        addMultipleImages(media);
    } else {
        setSingleImage(media);
    }
}

function setSingleImage(media) {
    if (!currentImageFieldId) return;
    
    // 检查是否存在ID字段，如果不存在则跳过
    var idField = document.getElementById(currentImageFieldId + '_id');
    if (idField) {
        idField.value = media.id;
    }
    
    // 设置URL字段
    var urlField = document.getElementById(currentImageFieldId);
    if (urlField) {
        urlField.value = media.url;
    }
    
    // 更新预览
    var preview = document.getElementById('preview_' + currentImageFieldId);
    if (preview) {
        preview.innerHTML = '<img src="' + media.url + '" style="max-width: 150px; max-height: 150px;" alt="预览">' +
                           '<p><a href="javascript:void(0);" onclick="removeImage(\'' + currentImageFieldId + '\'); return false;">移除图片</a></p>';
    }
}

function addMultipleImages(mediaArray) {
    if (!currentImageFieldId || !Array.isArray(mediaArray)) return;
    
    var currentValue = document.getElementById(currentImageFieldId).value;
    var currentImages = currentValue ? currentValue.split(',') : [];
    var currentIds = document.getElementById(currentImageFieldId + '_ids').value;
    var currentIdArray = currentIds ? currentIds.split(',') : [];
    
    mediaArray.forEach(function(media) {
        currentImages.push(media.url);
        currentIdArray.push(media.id);
    });
    
    document.getElementById(currentImageFieldId).value = currentImages.join(',');
    document.getElementById(currentImageFieldId + '_ids').value = currentIdArray.join(',');
    
    updateMultiImagePreview(currentImageFieldId, currentImages);
}

function removeImage(fieldId) {
    // 检查是否存在ID字段，如果存在则清空
    var idField = document.getElementById(fieldId + '_id');
    if (idField) {
        idField.value = '';
    }
    
    // 清空URL字段
    var urlField = document.getElementById(fieldId);
    if (urlField) {
        urlField.value = '';
    }
    
    // 清空预览
    var preview = document.getElementById('preview_' + fieldId);
    if (preview) {
        preview.innerHTML = '<p class="no-image">没有选择图片</p>';
    }
}

function removeMultiImage(fieldId, index) {
    var currentValue = document.getElementById(fieldId).value;
    var currentImages = currentValue ? currentValue.split(',') : [];
    var currentIds = document.getElementById(fieldId + '_ids').value;
    var currentIdArray = currentIds ? currentIds.split(',') : [];
    
    currentImages.splice(index, 1);
    currentIdArray.splice(index, 1);
    
    document.getElementById(fieldId).value = currentImages.join(',');
    document.getElementById(fieldId + '_ids').value = currentIdArray.join(',');
    
    updateMultiImagePreview(fieldId, currentImages);
}

function updateMultiImagePreview(fieldId, images) {
    var preview = document.getElementById('preview_' + fieldId);
    
    if (images.length === 0 || (images.length === 1 && !images[0])) {
        preview.innerHTML = '<p class="no-images">没有选择图片</p>';
        return;
    }
    
    var html = '';
    images.forEach(function(img, index) {
        if (img.trim()) {
            html += '<div class="image-preview-item" data-index="' + index + '">';
            html += '<img src="' + img + '" style="max-width: 100px; max-height: 100px; margin: 5px;" alt="预览">';
            html += '<button type="button" class="remove-image-btn" onclick="removeMultiImage(\'' + fieldId + '\', ' + index + ')">×</button>';
            html += '</div>';
        }
    });
    
    preview.innerHTML = html;
}

// 模型变更时更新模板选项和自定义字段
document.addEventListener('DOMContentLoaded', function() {
    var modelSelect = document.getElementById('cat-model');
    var listTemplateSelect = document.getElementById('cat-list-template');
    var viewTemplateSelect = document.getElementById('cat-view-template');
    
    if (modelSelect && listTemplateSelect && viewTemplateSelect) {
        modelSelect.addEventListener('change', function() {
            var modelId = this.value;
            
            // 记录当前选中值（用于编辑场景保留选中项）
            var prevListValue = listTemplateSelect.value || '';
            var prevViewValue = viewTemplateSelect.value || '';
            // 清空并设置默认选项
            listTemplateSelect.innerHTML = '<option value="">使用默认模板</option>';
            viewTemplateSelect.innerHTML = '<option value="">使用默认模板</option>';
            
            if (modelId) {
                // 通过AJAX获取列表模板
                fetchCategoryListTemplates(modelId, prevListValue);
                // 通过AJAX获取内容模板（按模型）
                fetchCategoryViewTemplates(modelId, prevViewValue);
                // 动态加载自定义字段
                loadCustomFields(modelId);
            } else {
                // 无模型则清空自定义字段
                loadCustomFields('');
            }
        });

        function fetchCategoryListTemplates(modelId, preserveValue) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', (window.TODO && window.TODO.ADMIN_AJAX) ? window.TODO.ADMIN_AJAX : '/admin/admin-ajax.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.success && Array.isArray(resp.templates)) {
                                // 先保留第一项默认模板
                                var optionsHtml = '<option value="">使用默认模板</option>';
                                resp.templates.forEach(function(t) {
                                    var name = t.display || t.file || '';
                                    if (name) {
                                        var selectedAttr = (preserveValue && name === preserveValue) ? ' selected' : '';
                                        optionsHtml += '<option value="' + name.replace(/"/g,'&quot;') + '"' + selectedAttr + '>' + name.replace(/</g,'&lt;') + '</option>';
                                    }
                                });
                                listTemplateSelect.innerHTML = optionsHtml;
                            }
                        } catch(e) {
                            console.error('解析列表模板失败', e);
                        }
                    }
                }
            };
            var nonceInput = document.querySelector('input[name="_todononce"]');
            var nonce = nonceInput ? nonceInput.value : '';
            var params = 'action=' + encodeURIComponent('get_list_templates') +
                         '&model_id=' + encodeURIComponent(modelId) +
                         '&_todononce=' + encodeURIComponent(nonce);
            xhr.send(params);
        }

        function fetchCategoryViewTemplates(modelId, preserveValue) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', (window.TODO && window.TODO.ADMIN_AJAX) ? window.TODO.ADMIN_AJAX : '/admin/admin-ajax.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.success && Array.isArray(resp.templates)) {
                                var optionsHtml = '<option value="">使用默认模板</option>';
                                resp.templates.forEach(function(t) {
                                    var name = t.display || t.file || '';
                                    if (name) {
                                        var selectedAttr = (preserveValue && name === preserveValue) ? ' selected' : '';
                                        optionsHtml += '<option value="' + name.replace(/"/g,'&quot;') + '"' + selectedAttr + '>' + name.replace(/</g,'&lt;') + '</option>';
                                    }
                                });
                                viewTemplateSelect.innerHTML = optionsHtml;
                            }
                        } catch(e) {
                            console.error('解析内容模板失败', e);
                        }
                    }
                }
            };
            var nonceInput = document.querySelector('input[name="_todononce"]');
            var nonce = nonceInput ? nonceInput.value : '';
            var params = 'action=' + encodeURIComponent('get_view_templates') +
                         '&model_id=' + encodeURIComponent(modelId) +
                         '&_todononce=' + encodeURIComponent(nonce);
            xhr.send(params);
        }
    }
    // 全选/取消全选
    var selectAlls = document.querySelectorAll('#cb-select-all-1, #cb-select-all-2');
    var checkboxes = document.querySelectorAll('input[name="category_ids[]"]');
    
    selectAlls.forEach(function(selectAll) {
        selectAll.addEventListener('change', function() {
            var isChecked = this.checked;
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = isChecked;
            });
            // 同步另一个全选框
            selectAlls.forEach(function(el) {
                el.checked = isChecked;
            });
        });
    });

    // 更新单个复选框状态时更新全选框状态
    checkboxes.forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            var allChecked = true;
            for (var i = 0; i < checkboxes.length; i++) {
                if (!checkboxes[i].checked) {
                    allChecked = false;
                    break;
                }
            }
            selectAlls.forEach(function(el) {
                el.checked = allChecked;
            });
        });
    });

        // 批量操作验证
        var categoriesFilter = document.getElementById('categories-filter');
        if (categoriesFilter) {
            categoriesFilter.addEventListener('submit', function(e) {
                var bulkAction = this.querySelector('select[name="bulk_action"]').value;
                var checkedBoxes = this.querySelectorAll('input[name="category_ids[]"]:checked');

                if (bulkAction !== '-1' && checkedBoxes.length === 0) {
                    TodoCMS.Utils.showMessage('请先选择要操作的分类。', 'warning');
                    e.preventDefault();
                    return;
                }

                if (bulkAction === 'trash') {
                    if (!confirm('确定要将所选分类移至回收站吗？')) {
                        e.preventDefault();
                    }
                }
            });
        }

        // 页面初次加载时，如已选中数据模型，立即加载自定义字段，避免用户必须切换一次模型才显示
    if (modelSelect && modelSelect.value) {
        // 初次加载根据已选模型填充模板与字段（保留既有选中值，避免编辑时丢失选择）
        var initPrevList = listTemplateSelect.value || '';
        var initPrevView = viewTemplateSelect.value || '';
        fetchCategoryListTemplates(modelSelect.value, initPrevList);
        fetchCategoryViewTemplates(modelSelect.value, initPrevView);
        loadCustomFields(modelSelect.value);
    }
});

// 动态加载自定义字段
function loadCustomFields(modelId) {
    var customFieldsContainer = document.getElementById('custom-fields-container');
    
    if (!customFieldsContainer) {
        return;
    }
    
    if (!modelId) {
        customFieldsContainer.innerHTML = '';
        return;
    }
    
    // 显示加载状态
    customFieldsContainer.innerHTML = '<p>正在加载自定义字段...</p>';
    
    // 发送AJAX请求获取模型字段
    var xhr = new XMLHttpRequest();
    xhr.open('POST', (window.TODO && window.TODO.ADMIN_AJAX) ? window.TODO.ADMIN_AJAX : '/admin/admin-ajax.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    var data = response.data || response;
                    if (response.success) {
                        var html = data.html || '';
                        if (typeof jQuery !== 'undefined') {
                            jQuery(customFieldsContainer).html(html);
                        } else {
                            customFieldsContainer.innerHTML = html;
                        }

                        ensureUEditorLoaded(function () {
                            initCategoryEditors(customFieldsContainer);
                        });
                    } else {
                        var msg = response.message || data.message || data.error || '未知错误';
                        customFieldsContainer.innerHTML = '<p style="color: #d63638;">加载字段失败: ' + msg + '</p>';
                    }
                } catch (e) {
                    customFieldsContainer.innerHTML = '<p style="color: #d63638;">解析响应失败</p>';
                }
            } else {
                customFieldsContainer.innerHTML = '<p style="color: #d63638;">请求失败</p>';
            }
        }
    };
    
    var nonceInput = document.querySelector('input[name="_todononce"]');
    var nonce = nonceInput ? nonceInput.value : '';
    var termId = <?php echo isset($term_id) ? intval($term_id) : 0; ?>;
    var params = 'action=' + encodeURIComponent('get_model_fields') +
                 '&model_id=' + encodeURIComponent(modelId) +
                 '&for_category=1' +
                 '&_todononce=' + encodeURIComponent(nonce);
    if (termId > 0) {
        params += '&categories[]=' + encodeURIComponent(termId);
    }
    xhr.send(params);
}

function ensureUEditorLoaded(callback) {
    if (window.UE && typeof window.UE.getEditor === 'function') {
        callback();
        return;
    }

    var base = (window.TODO && window.TODO.ADMIN_BASE) ? window.TODO.ADMIN_BASE : '/admin/';
    if (base.slice(-1) !== '/') base += '/';

    window.UEDITOR_HOME_URL = base + 'assets/js/ueditor/';

    var sources = [
        base + 'assets/js/ueditor/ueditor.config.js',
        base + 'assets/js/ueditor/ueditor.all.js',
        base + 'assets/js/ueditor/lang/zh-cn/zh-cn.js'
    ];

    var head = document.getElementsByTagName('head')[0] || document.documentElement;

    function loadNext(i) {
        if (i >= sources.length) {
            callback();
            return;
        }

        var src = sources[i];
        var existing = document.querySelector('script[src="' + src + '"]');
        if (existing) {
            loadNext(i + 1);
            return;
        }

        var script = document.createElement('script');
        script.type = 'text/javascript';
        script.src = src;
        script.onload = function () { loadNext(i + 1); };
        script.onerror = function () { callback(); };
        head.appendChild(script);
    }

    loadNext(0);
}

function initCategoryEditors(container) {
    if (!container || !window.UE || typeof window.UE.getEditor !== 'function') {
        return;
    }

    if (typeof window.OpenImagesList !== 'function') {
        window.OpenImagesList = function (event, element, multiple = true) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }

            // 根据来源元素或当前活动编辑器，解析目标UE实例
            function resolveEditorFromEvent(el) {
                if (!el || !window.UE || !UE.getEditor) return null;
                try {
                    // 1. 优先尝试从 UEditor 实例池中匹配包含该元素的实例（最可靠）
                    var instants = UE.instants || UE.instances || {};
                    for (var id in instants) {
                        var inst = instants[id];
                        if (inst && inst.container && (inst.container == el || inst.container.contains(el))) {
                            return inst;
                        }
                    }
                    // 2. 兼容支持closest的浏览器，优先查找UE容器
                    if (el.closest) {
                        var container = el.closest('.todo-editor, .form-control.todo-editor, .rich-editor');
                        if (container && container.id) {
                            return UE.getEditor(container.id);
                        }
                    }
                    // 3. 回退：逐层向上查找包含的容器id
                    var node = el;
                    var maxDepth = 50;
                    while (node && node !== document && maxDepth-- > 0) {
                        if (node.id && (node.id.indexOf('field_') === 0 || node.id.indexOf('editbox_') === 0 || node.id.indexOf('todo_editor_') === 0)) {
                            return UE.getEditor(node.id);
                        }
                        node = node.parentNode;
                    }
                } catch (e) { }
                return null;
            }

            var editorInstance = resolveEditorFromEvent(element) || window.currentUE || null;
            if (!editorInstance && window.UE) {
                var instants = UE.instants || UE.instances || {};
                for (var k in instants) {
                    if (Object.prototype.hasOwnProperty.call(instants, k)) {
                        editorInstance = instants[k];
                        break;
                    }
                }
            }

            if (!editorInstance || !editorInstance.execCommand) {
                console.error('OpenImagesList: 找不到编辑器实例');
                return false;
            }

            var targetWindow = window;
            var selectMediaForField = window.selectMediaForField;
            if (typeof selectMediaForField !== 'function' && window.parent && window.parent !== window) {
                try {
                    if (typeof window.parent.selectMediaForField === 'function') {
                        targetWindow = window.parent;
                        selectMediaForField = window.parent.selectMediaForField;
                    }
                } catch (e) { }
            }

            if (typeof selectMediaForField !== 'function') {
                try {
                    editorInstance.execCommand('insertimage');
                } catch (e) { }
                return false;
            }

            selectMediaForField.call(targetWindow, {
                title: multiple ? '选择多张图片' : '选择图片',
                button_text: '插入图片',
                multiple: multiple,
                type: 'image',
                callback: function (selectedMedia) {
                    if (!editorInstance || !editorInstance.execCommand) {
                        return;
                    }

                    var mediaArray = Array.isArray(selectedMedia) ? selectedMedia : (selectedMedia ? [selectedMedia] : []);
                    if (mediaArray.length === 0) return;

                    var html = '';
                    mediaArray.forEach(function (media) {
                        if (media && media.url) {
                            if (typeof window.formatMediaHtmlForEditor === 'function') {
                                html += window.formatMediaHtmlForEditor(media);
                            } else {
                                var title = media.title || media.alt || media.filename || media.name || '';
                                var isImage = (media.type && media.type.indexOf('image/') === 0) || /\.(jpg|jpeg|png|gif|webp|bmp|svg)$/i.test(media.url || '');
                                if (isImage) {
                                    html += '<img src="' + media.url + '" alt="' + title + '" title="' + title + '" />';
                                } else {
                                    html += '<a href="' + media.url + '" rel="nofollow" target="_blank" title="' + title + '">' + (title || media.url) + '</a>';
                                }
                            }
                        }
                    });

                    if (html) {
                        try {
                            // 确保编辑器焦点
                            editorInstance.focus();
                            // 插入HTML内容
                            editorInstance.execCommand('inserthtml', html);
                        } catch (err) {
                            console.error('插入图片时出错:', err);
                        }
                    }
                }
            });

            return false;
        };
    }

    var base = (window.TODO && window.TODO.ADMIN_BASE) ? window.TODO.ADMIN_BASE : '/admin/';
    if (base.slice(-1) !== '/') base += '/';
    window.UEDITOR_HOME_URL = base + 'assets/js/ueditor/';

    var editorAreas = container.querySelectorAll('div[data-field-type="editor"] textarea, textarea.todo-editor, textarea.rich-editor');
    if (!editorAreas || editorAreas.length === 0) {
        return;
    }

    editorAreas.forEach(function (textarea) {
        var id = textarea.getAttribute('id');
        if (!id) {
            id = 'todo_editor_' + Math.random().toString(36).slice(2);
            textarea.setAttribute('id', id);
        }

        try {
            if (window.UE && UE.instances && UE.instances[id]) {
                UE.instances[id].destroy();
            }
        } catch (e) {}

        var editor = UE.getEditor(id, {
            initialFrameHeight: 300,
            autoHeightEnabled: true,
            elementPathEnabled: false,
            wordCount: true,
            enableAutoSave: false,
            serverUrl: ((window.TODO && window.TODO.join) ? window.TODO.join(window.TODO.ADMIN_BASE, 'assets/js/ueditor/php/controller.php') : '/admin/assets/js/ueditor/php/controller.php')
        });

        if (editor && typeof editor.ready === 'function') {
            editor.ready(function () {
                window.currentUE = editor;
                try {
                    editor.addListener('focus', function () { window.currentUE = editor; });
                    editor.addListener('click', function () { window.currentUE = editor; });
                    editor.addListener('selectionchange', function () { window.currentUE = editor; });
                    editor.addListener('contentChange', function () {
                        textarea.value = editor.getContent();
                    });
                } catch (e) {}
            });
        }
    });
}
</script>

<?php
require_once ABSPATH . 'admin/components/media-modal.php';
?>
