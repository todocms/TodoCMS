<?php

if (!defined('DOING_AJAX') || !DOING_AJAX) {
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        http_response_code(403);
        echo json_encode(array('error' => 'Direct access denied'));
        exit;
    }
}

$sub_action = isset($_POST['sub_action']) ? $_POST['sub_action'] : '';
if ($sub_action !== 'generate_slug') {
    echo json_encode(array('error' => '无效的操作'));
    exit;
}

$type = sanitize_text_field($_POST['type'] ?? '');
$name = sanitize_text_field($_POST['name'] ?? '');
$id = isset($_POST['id']) ? intval($_POST['id']) : 0;

if ($type === '' || $name === '') {
    echo json_encode(array('error' => '缺少必要参数'));
    exit;
}

function todo_slug_generator_verify_csrf_by_type($type) {
    if (!class_exists('Todo_Security_Manager')) {
        return false;
    }

    $candidates = array();
    $nonce_field = null;

    switch ($type) {
        case 'tag':
            $nonce_field = '_todononce';
            $candidates = array('add_tag', 'update_tag');
            break;
        case 'doc_property':
            $nonce_field = '_todononce';
            $candidates = array('doc_properties_action');
            break;
        case 'ad_group':
            $nonce_field = '_todononce';
            $candidates = array('add_ad_group', 'edit_ad_group');
            break;
        case 'model_field':
            $nonce_field = '_todononce';
            $candidates = array('add_field', 'model_field_edit');
            break;
        case 'global_var_field':
            $nonce_field = '_todononce';
            $candidates = array('global_vars_action');
            break;
        case 'user_level':
            $nonce_field = '_csrf_token';
            $candidates = array('user_levels_manage');
            break;
        default:
            $nonce_field = null;
            $candidates = array('default');
            break;
    }

    foreach ($candidates as $action) {
        if (Todo_Security_Manager::verify_request_csrf($action, $nonce_field)) {
            return true;
        }
    }

    if (Todo_Security_Manager::verify_request_csrf('default', $nonce_field)) {
        return true;
    }

    return false;
}

function todo_slug_generator_check_permission($type) {
    switch ($type) {
        case 'tag':
            return current_user_can('manage_categories') || current_user_can('edit_posts') || current_user_can('manage_options');
        case 'doc_property':
            return current_user_can('manage_options');
        case 'ad_group':
            return current_user_can('manage_ads') || current_user_can('edit_posts') || current_user_can('manage_options');
        case 'model_field':
            return current_user_can('manage_options');
        case 'global_var_field':
            return current_user_can('manage_options');
        case 'user_level':
            return current_user_can('manage_user_levels') || current_user_can('manage_users') || current_user_can('manage_options');
        default:
            return current_user_can('manage_options');
    }
}

function todo_slug_generator_to_key($text, $default, $id = 0) {
    $key = todo_generate_slug($text, 0, $default);
    $key = strtolower((string) $key);
    $key = str_replace('-', '_', $key);
    $key = preg_replace('/[^a-z0-9_]/', '_', $key);
    $key = preg_replace('/_+/', '_', $key);
    $key = trim($key, '_');
    if ($key === '') {
        $key = $default . ($id > 0 ? '_' . $id : '');
    }
    return $key;
}

if (!todo_slug_generator_check_permission($type)) {
    http_response_code(403);
    echo json_encode(array('error' => '权限不足'));
    exit;
}

if (!todo_slug_generator_verify_csrf_by_type($type)) {
    http_response_code(403);
    echo json_encode(array('error' => 'CSRF验证失败'));
    exit;
}

try {
    switch ($type) {
        case 'tag': {
            $slug = todo_generate_slug($name, $id, 'tag');

            global $tododb;
            if (!$tododb) {
                echo json_encode(array('success' => true, 'slug' => $slug));
                break;
            }

            $prefix = isset($tododb->prefix) ? $tododb->prefix : TABLE_PREFIX;
            $table = $prefix . 'tags';
            $exclude_id = $id > 0 ? $id : 0;

            $candidate = $slug;
            $counter = 1;
            while (true) {
                $params = array($candidate);
                $sql = "SELECT tag_id FROM {$table} WHERE tag_slug = ?";
                if ($exclude_id > 0) {
                    $sql .= " AND tag_id != ?";
                    $params[] = $exclude_id;
                }
                $sql .= " LIMIT 1";

                $exists = $tododb->get_var($sql, $params);
                if (empty($exists)) {
                    break;
                }
                $candidate = $slug . '-' . $counter;
                $counter++;
            }

            echo json_encode(array('success' => true, 'slug' => $candidate));
            break;
        }

        case 'doc_property': {
            if (defined('ABSPATH')) {
                $func_path = ABSPATH . 'admin/includes/doc-properties-functions.php';
                if (file_exists($func_path)) {
                    require_once $func_path;
                }
            }

            $slug = todo_generate_slug($name, $id, 'property');
            $slug = preg_replace('/[^a-zA-Z0-9_-]/', '-', $slug);
            $slug = preg_replace('/-+/', '-', $slug);
            $slug = trim($slug, '-');
            if ($slug === '' || preg_match('/^[0-9]/', $slug)) {
                $slug = 'property' . ($id > 0 ? '-' . $id : '');
            }

            if (function_exists('doc_property_exists')) {
                $exclude_id = $id > 0 ? $id : null;
                if (doc_property_exists($slug, $exclude_id)) {
                    $counter = 2;
                    $candidate = $slug . '-' . $counter;
                    while (doc_property_exists($candidate, $exclude_id)) {
                        $counter++;
                        $candidate = $slug . '-' . $counter;
                    }
                    $slug = $candidate;
                }
            }

            echo json_encode(array('success' => true, 'slug' => $slug));
            break;
        }

        case 'ad_group': {
            $code = todo_slug_generator_to_key($name, 'group', $id);

            if (class_exists('Todo_Ads_Manager')) {
                Todo_Ads_Manager::init();
                $exclude_id = $id > 0 ? $id : 0;
                $base = $code;
                $candidate = $base;
                $counter = 2;
                while (true) {
                    $existing = Todo_Ads_Manager::get_ad_group_by_code($candidate);
                    if (empty($existing) || ($exclude_id > 0 && intval($existing['id'] ?? 0) === $exclude_id)) {
                        break;
                    }
                    $candidate = $base . '_' . $counter;
                    $counter++;
                }
                $code = $candidate;
            }

            echo json_encode(array('success' => true, 'slug' => $code));
            break;
        }

        case 'model_field': {
            $model_id = isset($_POST['model_id']) ? intval($_POST['model_id']) : 0;
            if ($model_id <= 0) {
                echo json_encode(array('error' => '缺少模型ID'));
                break;
            }

            $key = todo_slug_generator_to_key($name, 'field', $id);
            if (class_exists('CMS_Model')) {
                $model = new CMS_Model($model_id);
                $fields = $model->get_fields();

                $exclude_field_id = $id > 0 ? $id : 0;
                $base = $key;
                $candidate = $base;
                $counter = 2;
                while (true) {
                    $exists = false;
                    if (!empty($fields) && is_array($fields)) {
                        foreach ($fields as $f) {
                            if (is_array($f)) {
                                $f = (object) $f;
                            }
                            $f_key = isset($f->field_key) ? (string) $f->field_key : '';
                            $f_id = isset($f->field_id) ? intval($f->field_id) : 0;
                            if ($f_key !== '' && $f_key === $candidate && ($exclude_field_id <= 0 || $f_id !== $exclude_field_id)) {
                                $exists = true;
                                break;
                            }
                        }
                    }
                    if (!$exists) {
                        break;
                    }
                    $candidate = $base . '_' . $counter;
                    $counter++;
                }
                $key = $candidate;
            }

            echo json_encode(array('success' => true, 'slug' => $key));
            break;
        }

        case 'global_var_field': {
            $key = todo_slug_generator_to_key($name, 'field', $id);
            $exclude_index = $id > 0 ? $id : -1;

            $existing_fields = function_exists('get_option') ? get_option('global_vars_fields', array()) : array();
            if (!is_array($existing_fields)) {
                $existing_fields = array();
            }

            $base = $key;
            $candidate = $base;
            $counter = 2;
            while (true) {
                $exists = false;
                foreach ($existing_fields as $idx => $existing_field) {
                    if (!is_array($existing_field)) {
                        continue;
                    }
                    if ($exclude_index >= 0 && intval($idx) === $exclude_index) {
                        continue;
                    }
                    if (isset($existing_field['field_key']) && $existing_field['field_key'] === $candidate) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    break;
                }
                $candidate = $base . '_' . $counter;
                $counter++;
            }

            echo json_encode(array('success' => true, 'slug' => $candidate));
            break;
        }

        case 'user_level': {
            $slug = todo_generate_slug($name, $id, 'level');

            $manager = function_exists('todo_get_user_level_manager') ? todo_get_user_level_manager() : null;
            $exclude_id = $id > 0 ? $id : null;
            if ($manager && method_exists($manager, 'level_slug_exists')) {
                if ($manager->level_slug_exists($slug, $exclude_id)) {
                    $counter = 1;
                    $candidate = $slug . '-' . $counter;
                    while ($manager->level_slug_exists($candidate, $exclude_id)) {
                        $counter++;
                        $candidate = $slug . '-' . $counter;
                    }
                    $slug = $candidate;
                }
            }

            echo json_encode(array('success' => true, 'slug' => $slug));
            break;
        }

        default:
            echo json_encode(array('error' => '不支持的类型'));
            break;
    }
} catch (Exception $e) {
    echo json_encode(array('error' => '操作失败：' . $e->getMessage()));
}

exit;
