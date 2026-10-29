<?php
/**
 * 邮件模板 AJAX 处理器
 * 
 * @package TodoCMS
 * @subpackage Admin/Ajax
 */

if (!defined('TODO_LOADED') && !defined('ABSPATH')) {
    exit;
}

// 检查权限
if (!todo_current_user_can('manage_options') && !todo_current_user_can('moderate_comments')) {
    if (function_exists('todo_send_json_error')) {
        todo_send_json_error('Permission denied', 403);
    } else {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'Permission denied']);
        exit;
    }
}

global $tododb;

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'get_templates_by_category':
        $category = $_GET['category'] ?? '';
        if (empty($category)) {
            if (function_exists('todo_send_json_error')) {
                todo_send_json_error('缺少 category 参数');
            } else {
                echo json_encode(['success' => false, 'message' => '缺少 category 参数']);
                exit;
            }
        }

        if ($category === 'all') {
            $templates = $tododb->get_results(
                "SELECT id, name, slug, subject, content FROM {$tododb->prefix}email_templates ORDER BY id DESC"
            );
        } elseif (strpos($category, ',') !== false) {
            $cats = array_filter(array_map('trim', explode(',', $category)));
            if (!empty($cats)) {
                $placeholders = implode(',', array_fill(0, count($cats), '?'));
                $templates = $tododb->get_results(
                    "SELECT id, name, slug, subject, content FROM {$tododb->prefix}email_templates WHERE category IN ($placeholders) ORDER BY id DESC",
                    $cats
                );
            } else {
                $templates = [];
            }
        } else {
            $templates = $tododb->get_results(
                "SELECT id, name, slug, subject, content FROM {$tododb->prefix}email_templates WHERE category = ? ORDER BY id DESC",
                [$category]
            );
        }

        if (function_exists('todo_send_json_success')) {
            todo_send_json_success($templates);
        } else {
            echo json_encode(['success' => true, 'data' => $templates]);
            exit;
        }
        break;

    case 'get_template_content':
        $template_id = intval($_GET['id'] ?? 0);
        $slug = $_GET['slug'] ?? '';
        
        if (!$template_id && !$slug) {
            if (function_exists('todo_send_json_error')) {
                todo_send_json_error('缺少 ID 或 Slug');
            } else {
                echo json_encode(['success' => false, 'message' => '缺少 ID 或 Slug']);
                exit;
            }
        }

        if ($template_id) {
            $template = $tododb->get_row(
                "SELECT * FROM {$tododb->prefix}email_templates WHERE id = ?",
                [$template_id]
            );
        } else {
            $template = $tododb->get_row(
                "SELECT * FROM {$tododb->prefix}email_templates WHERE slug = ?",
                [$slug]
            );
        }

        if ($template) {
            $lang = trim($_GET['lang'] ?? ($_POST['lang'] ?? ''));
            if ($lang !== '') {
                if (!function_exists('todo_resolve_multilang_text') && defined('ABSPATH') && file_exists(ABSPATH . 'core/functions/mail-extend.php')) {
                    require_once ABSPATH . 'core/functions/mail-extend.php';
                }
                if (function_exists('todo_resolve_multilang_text')) {
                    if (isset($template->subject)) {
                        $template->subject = todo_resolve_multilang_text($template->subject, $lang);
                    }
                    if (isset($template->content)) {
                        $template->content = todo_resolve_multilang_text($template->content, $lang);
                    }
                    if (isset($template->variables)) {
                        $template->variables = todo_resolve_multilang_text($template->variables, $lang);
                    }
                }
            }
            if (function_exists('todo_send_json_success')) {
                todo_send_json_success($template);
            } else {
                echo json_encode(['success' => true, 'data' => $template]);
                exit;
            }
        } else {
            if (function_exists('todo_send_json_error')) {
                todo_send_json_error('模板不存在');
            } else {
                echo json_encode(['success' => false, 'message' => '模板不存在']);
                exit;
            }
        }
        break;

    default:
        if (function_exists('todo_send_json_error')) {
            todo_send_json_error('Invalid template action');
        } else {
            echo json_encode(['error' => 'Invalid template action']);
            exit;
        }
        break;
}
