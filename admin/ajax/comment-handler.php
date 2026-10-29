<?php
/**
 * TodoCMS 统一评论/留言/咨询处理AJAX接口
 * 
 * 处理所有类型的评论（评价、咨询、留言、通用评论）相关的AJAX请求
 * 
 * @package TodoCMS
 * @version 2.0.0
 */

if (!defined('DOING_AJAX') || !DOING_AJAX) {
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        http_response_code(403);
        echo json_encode(['error' => 'Direct access denied']);
        exit;
    }
}

// 加载核心文件
require_once dirname(dirname(dirname(__FILE__))) . '/core/bootstrap.php';

if (!function_exists('todo_send_comment_reply_notification') || !function_exists('todo_send_message_reply_notification') || !function_exists('todo_send_inquiry_reply_notification') || !function_exists('todo_send_template_mail') || !function_exists('todo_log_email')) {
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
    }
    $mail_extend_path = rtrim(ABSPATH, '/') . '/core/functions/mail-extend.php';
    if (is_file($mail_extend_path)) {
        require_once $mail_extend_path;
    }
}

// 获取操作类型
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 检查用户权限：只有后台管理类操作才强制要求后台 manage_comments 权限
$public_actions = ['add_comment', 'add_inquiry', 'add_message', 'get_comment_tree', 'get_comments_list', 'like_comment'];
if (!in_array($action, $public_actions, true)) {
    if (!is_user_logged_in() || !todo_current_user_can('manage_comments')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => __('权限不足')]);
        exit;
    }
}

// 验证CSRF令牌（仅针对后台管理修改类操作）
$write_actions = [
    'update_comment_status',
    'update_review_status',
    'update_message_status',
    'update_inquiry_status',
    'update_status',
    'delete_comment',
    'delete_review',
    'delete_message',
    'delete_inquiry',
    'reply_comment',
    'reply_review',
    'reply_message',
    'reply_inquiry',
];
if (in_array($action, $write_actions)) {
    if (!Todo_Security_Manager::verify_request_csrf($action)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => __('CSRF验证失败')]);
        exit;
    }
}

try {
    switch ($action) {
        case 'get_comment_details':
        case 'get_review_details':
        case 'get_inquiry':
            handle_get_comment_details();
            break;
            
        case 'get_message':
            handle_get_message_details();
            break;
            
        case 'update_comment_status':
        case 'update_review_status':
        case 'update_message_status':
        case 'update_inquiry_status':
        case 'update_status':
            handle_update_comment_status();
            break;
            
        case 'reply_comment':
        case 'reply_review':
        case 'reply_message':
        case 'reply_inquiry':
            handle_reply_comment();
            break;
            
        case 'delete_comment':
        case 'delete_review':
        case 'delete_message':
        case 'delete_inquiry':
            handle_delete_comment();
            break;
            
        case 'add_comment':
        case 'add_message':
        case 'add_inquiry':
            handle_add_comment();
            break;
            
        case 'get_comment_tree':
            handle_get_comment_tree();
            break;

        case 'get_comments_list':
            handle_get_comments_list();
            break;

        case 'like_comment':
            handle_like_comment();
            break;
            
        default:
            throw new Exception(__('无效的操作类型') . ': ' . $action);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

/**
 * 获取评论详情
 */
function handle_get_comment_details() {
    $comment_id = intval(
        $_POST['comment_id']
            ?? $_GET['comment_id']
            ?? $_POST['review_id']
            ?? $_GET['review_id']
            ?? $_POST['inquiry_id']
            ?? $_GET['inquiry_id']
            ?? 0
    );
    if ($comment_id <= 0) throw new Exception(__('无效的评论ID'));
    
    $details = todo_get_comment_details($comment_id);
    if (!$details) throw new Exception(__('评论不存在'));
    
    echo json_encode(['success' => true, 'data' => $details]);
    exit;
}

function handle_get_message_details() {
    global $tododb;
    $comment_id = intval(
        $_POST['comment_id']
            ?? $_GET['comment_id']
            ?? $_POST['message_id']
            ?? $_GET['message_id']
            ?? 0
    );
    if ($comment_id <= 0) throw new Exception(__('无效的留言ID'));

    /**
     * 解析 model_fields.field_options 为 value => label 映射
     */
    $parse_options_label_map = function ($raw) {
        $map = [];
        if (empty($raw) || !is_string($raw)) return $map;
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            if (isset($decoded['options']) && is_string($decoded['options'])) {
                $raw = $decoded['options'];
            } elseif (isset($decoded['options']) && is_array($decoded['options'])) {
                return $decoded['options'];
            } else {
                return $decoded;
            }
        }
        $unser = function_exists('maybe_unserialize') ? maybe_unserialize($raw) : @unserialize($raw);
        if (is_array($unser)) {
            if (isset($unser['options']) && is_string($unser['options'])) {
                $raw = $unser['options'];
            } elseif (isset($unser['options']) && is_array($unser['options'])) {
                return $unser['options'];
            }
        }
        $lines = preg_split('/\r\n|\r|\n/', trim($raw));
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (strpos($line, '|') !== false) {
                list($v, $l) = explode('|', $line, 2);
                $map[trim($v)] = trim($l);
            }
        }
        return $map;
    };

    $format_custom_fields_for_message = function ($model_id, $term_id, $fields) use ($tododb, $parse_options_label_map) {
        $fields = is_array($fields) ? $fields : [];
        $base_keys = ['contact_name', 'contact_email', 'contact_phone', 'subject', 'message_status', 'phone'];

        $label_map = [];
        $type_map = [];
        $option_label_map = []; // field_key => [option_value => option_label]
        $visible_map = [];
        $model_id = intval($model_id);
        $term_id = intval($term_id);

        if ($model_id > 0) {
            try {
                $rows = $tododb->get_results(
                    "SELECT mf.field_key, mf.field_label, mf.field_name, mf.field_type, mf.field_options, mf.category_only, tfv.is_visible
                     FROM {$tododb->prefix}model_fields mf
                     LEFT JOIN {$tododb->prefix}term_field_visibility tfv
                       ON tfv.field_id = mf.field_id AND tfv.term_id = ?
                     WHERE mf.model_id = ?",
                    [$term_id, $model_id]
                );
                if (is_array($rows)) {
                    foreach ($rows as $r) {
                        if (!is_object($r)) continue;
                        $k = (string) ($r->field_key ?? '');
                        if ($k === '') continue;
                        $label = (string) ($r->field_label ?? '');
                        if ($label === '') $label = (string) ($r->field_name ?? '');
                        if ($label === '') $label = $k;
                        $label_map[$k] = $label;
                        $type_map[$k] = (string) ($r->field_type ?? 'text');

                        // 解析 select/radio/checkbox 的选项 label 映射
                        $ft = $type_map[$k];
                        $raw = $r->field_options ?? '';
                        if (in_array($ft, ['select', 'radio', 'checkbox'], true) && $raw !== '') {
                            $option_label_map[$k] = $parse_options_label_map($raw);
                        }

                        $category_only = intval($r->category_only ?? 0) === 1;
                        $has_visibility = isset($r->is_visible);
                        $is_visible = $has_visibility ? intval($r->is_visible) === 1 : true;
                        $visible_map[$k] = $category_only ? ($has_visibility && $is_visible) : $is_visible;
                    }
                }
            } catch (Throwable $e) {
                $label_map = [];
                $type_map = [];
                $visible_map = [];
            }
        }

        $filtered = [];
        $display = [];
        foreach ($fields as $k => $v) {
            if (!is_string($k) || $k === '' || strpos($k, '_') === 0) continue;
            if (in_array($k, $base_keys, true)) continue;

            if ($model_id > 0) {
                if (!isset($visible_map[$k]) || !$visible_map[$k]) continue;
            }

            if (is_string($v) && function_exists('maybe_unserialize')) {
                $v = maybe_unserialize($v);
            }

            $filtered[$k] = $v;

            $vs = $v;
            if (is_array($vs) || is_object($vs)) {
                $vs = json_encode($vs, JSON_UNESCAPED_UNICODE);
            }

            $field_type = $type_map[$k] ?? 'text';

            // 解析区域字段：代码|代码|... -> 名称 名称 ...
            if ($field_type === 'region' && function_exists('todo_regions_find_name') && is_scalar($vs) && (string) $vs !== '') {
                $parts = explode('|', (string) $vs);
                $names = [];
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($part === '') continue;
                    $n = todo_regions_find_name($part);
                    $names[] = $n !== '' ? $n : $part;
                }
                $vs = implode(' ', $names);
            }

            // 解析 select/radio/checkbox 值 -> 显示标签
            if (in_array($field_type, ['select', 'radio', 'checkbox'], true)
                && is_scalar($vs)
                && isset($option_label_map[$k])) {
                $ol = $option_label_map[$k];
                $sv = (string) $vs;
                if (strpos($sv, ',') !== false) {
                    $vals = array_map('trim', explode(',', $sv));
                    $labels = [];
                    foreach ($vals as $val) {
                        $labels[] = $ol[$val] ?? $val;
                    }
                    $vs = implode(', ', $labels);
                } else {
                    $vs = $ol[$sv] ?? $sv;
                }
            }

            // 解析 file / multi_file 类型及图片路径：调用后端绝对地址解析器 todo_media_url_to_absolute 拼接子目录
            if ($field_type === 'multi_file' && is_string($vs) && $vs !== '') {
                $decoded = json_decode($vs, true);
                if (is_array($decoded)) {
                    $urls = [];
                    foreach ($decoded as $file) {
                        if (isset($file['url']) && is_string($file['url'])) {
                            $urls[] = function_exists('todo_media_url_to_absolute') ? todo_media_url_to_absolute($file['url']) : $file['url'];
                        }
                    }
                    $vs = implode(', ', $urls);
                }
            } elseif (($field_type === 'file' || preg_match('#\.(png|jpe?g|gif|webp|bmp|svg)($|\?|#)#i', (string) $vs)) && is_string($vs) && $vs !== '') {
                if (strpos($vs, ',') !== false) {
                    $parts = array_map('trim', explode(',', $vs));
                    $abs_parts = [];
                    foreach ($parts as $part) {
                        $abs_parts[] = function_exists('todo_media_url_to_absolute') ? todo_media_url_to_absolute($part) : $part;
                    }
                    $vs = implode(', ', $abs_parts);
                } else {
                    $vs = function_exists('todo_media_url_to_absolute') ? todo_media_url_to_absolute($vs) : $vs;
                }
            }

            $display[] = [
                'key' => $k,
                'title' => $label_map[$k] ?? $k,
                'value' => is_scalar($vs) ? (string) $vs : '',
                'field_type' => $field_type,
            ];
        }
        return [$filtered, $display];
    };

    $details = todo_get_comment_details($comment_id);
    if (!$details) {
        $post = function_exists('get_post') ? get_post($comment_id) : null;
        if (!$post) throw new Exception(__('留言不存在'));
        if (is_array($post)) $post = (object) $post;

        $cf_rows = [];
        try {
            $cf_rows = $tododb->get_results(
                "SELECT field_key, field_value FROM {$tododb->prefix}post_custom_fields WHERE post_id = ?",
                [$comment_id]
            );
        } catch (Throwable $e) {
            $cf_rows = [];
        }
        $cf = [];
        if (is_array($cf_rows)) {
            foreach ($cf_rows as $r) {
                if (!is_object($r)) continue;
                $k = (string) ($r->field_key ?? '');
                if ($k === '') continue;
                $cf[$k] = $r->field_value ?? '';
            }
        }

        $post_model_key = (string) ($post->post_type ?? '');
        if ($post_model_key === 'guestbook') {
            try {
                $mf = $tododb->get_row(
                    "SELECT * FROM {$tododb->prefix}model_fields_guestbook WHERE post_id = ?",
                    [$comment_id]
                );
                if ($mf) {
                    $mf_arr = is_array($mf) ? $mf : (array) $mf;
                    foreach ($mf_arr as $k => $v) {
                        if (!is_string($k) || $k === '' || in_array($k, ['field_id', 'post_id', 'created_at', 'updated_at'], true)) {
                            continue;
                        }
                        $cf[$k] = $v;
                    }
                }
            } catch (Throwable $e) {
            }
        }

        $author_email = '';
        $author_name = '';
        if (function_exists('get_user_by')) {
            $u = get_user_by('id', intval($post->post_author ?? 0));
            if ($u) {
                $author_name = (string) (!empty($u->display_name) ? $u->display_name : __('管理员'));
                $author_email = (string) ($u->user_email ?? '');
            }
        }

        $form_type = '默认';
        if (function_exists('get_term')) {
            $t = get_term(intval($post->category_id ?? 0), 'category');
            if ($t && !empty($t->name)) $form_type = (string) $t->name;
        }

        $model_id = intval($post->model_id ?? 0);
        if ($model_id <= 0) {
            try {
                $gb_model_id = $tododb->get_var("SELECT model_id FROM {$tododb->prefix}models WHERE model_key = 'guestbook' LIMIT 1");
                $model_id = intval($gb_model_id);
            } catch (Throwable $e) {
                $model_id = 0;
            }
        }
        [$filtered_cf, $display_cf] = $format_custom_fields_for_message($model_id, intval($post->category_id ?? 0), $cf);

        $message = [
            'id' => (int) ($post->ID ?? 0),
            'name' => (string) ($cf['contact_name'] ?? ($post->post_title ?? $author_name)),
            'email' => (string) ($cf['contact_email'] ?? $author_email),
            'phone' => (string) ($cf['contact_phone'] ?? ''),
            'subject' => (string) ($cf['subject'] ?? ($post->post_title ?? '')),
            'content' => todo_html_to_plain_text((string) ($post->post_content ?? '')),
            'comment_parent' => 0,
            'post_id' => (int) ($post->ID ?? 0),
            'created_at' => (string) ($post->post_date ?? ''),
            'status' => (string) ($post->post_status ?? ''),
            'form_type' => $form_type,
            'custom_fields' => $filtered_cf,
            'custom_fields_display' => $display_cf,
        ];

        echo json_encode(['success' => true, 'data' => ['message' => $message, 'raw' => $post]]);
        exit;
    }

    $meta = [];
    if (is_object($details) && isset($details->meta) && is_array($details->meta)) {
        $meta = $details->meta;
    }

    $phone = '';
    if (isset($meta['phone']) && $meta['phone'] !== '') {
        $phone = (string) $meta['phone'];
    } elseif (isset($meta['contact_phone']) && $meta['contact_phone'] !== '') {
        $phone = (string) $meta['contact_phone'];
    }

    $subject = '';
    if (isset($meta['subject']) && $meta['subject'] !== '') {
        $subject = (string) $meta['subject'];
    }

    $status_raw = is_object($details) ? ($details->comment_approved ?? '') : '';
    $status_raw = is_string($status_raw) || is_numeric($status_raw) ? (string) $status_raw : '';

    $status = $status_raw;
    if ($status_raw === '1') {
        $status = 'approved';
    } elseif ($status_raw === '0') {
        $status = 'pending';
    } elseif ($status_raw === 'spam' || $status_raw === '-1') {
        $status = 'spam';
    } elseif ($status_raw === 'trash' || $status_raw === '-2') {
        $status = 'trash';
    }

    $custom_fields = $meta;
    unset($custom_fields['phone'], $custom_fields['contact_phone'], $custom_fields['subject']);
    foreach ($custom_fields as $k => $v) {
        if (is_string($k) && strpos($k, '_') === 0) {
            unset($custom_fields[$k]);
        }
    }

    $model_id = 0;
    $term_id = 0;
    $post_obj = null;
    $post_id = (int) ($details->comment_post_ID ?? 0);
    if ($post_id > 0 && function_exists('get_post')) {
        $post_obj = get_post($post_id);
        if ($post_obj && is_array($post_obj)) $post_obj = (object) $post_obj;
    }
    if ($post_obj) {
        $model_id = intval($post_obj->model_id ?? 0);
        $term_id = intval($post_obj->category_id ?? 0);
    }
    if ($model_id <= 0) {
        try {
            $gb_model_id = $tododb->get_var("SELECT model_id FROM {$tododb->prefix}models WHERE model_key = 'guestbook' LIMIT 1");
            $model_id = intval($gb_model_id);
        } catch (Throwable $e) {
            $model_id = 0;
        }
    }
    [$filtered_cf, $display_cf] = $format_custom_fields_for_message($model_id, $term_id, $custom_fields);

    $decoded_content = todo_html_to_plain_text($details->comment_content ?? '');

    $message = [
        'id' => (int) ($details->comment_ID ?? 0),
        'name' => (string) ($details->comment_author ?? ''),
        'email' => (string) ($details->comment_author_email ?? ''),
        'phone' => $phone,
        'subject' => $subject,
        'content' => $decoded_content,
        'comment_parent' => (int) ($details->comment_parent ?? 0),
        'post_id' => (int) ($details->comment_post_ID ?? 0),
        'created_at' => (string) ($details->comment_date ?? ''),
        'status' => $status,
        'custom_fields' => $filtered_cf,
        'custom_fields_display' => $display_cf,
    ];

    echo json_encode(['success' => true, 'data' => ['message' => $message, 'raw' => $details]]);
    exit;
}

/**
 * 更新评论状态
 */
function handle_update_comment_status() {
    global $tododb;
    $comment_id = intval(
        $_POST['comment_id']
            ?? $_POST['message_id']
            ?? $_POST['review_id']
            ?? $_POST['inquiry_id']
            ?? 0
    );
    $status = $_POST['status'] ?? '';
    
    if ($comment_id <= 0) throw new Exception(__('无效的评论ID'));
    if (!in_array($status, ['approve', 'approved', 'hold', 'pending', 'spam', 'trash'])) {
        throw new Exception(__('无效的状态'));
    }
    
    $result = todo_set_comment_status($comment_id, $status);
    if (!$result) {
        $post = function_exists('get_post') ? get_post($comment_id) : null;
        if (!$post) throw new Exception(__('更新状态失败'));

        $ok = false;
        if ($status === 'trash' || $status === 'spam') {
            $ok = function_exists('todo_trash_post') ? todo_trash_post($comment_id) : false;
        } elseif ($status === 'approve' || $status === 'approved') {
            $ok = function_exists('todo_publish_post') ? todo_publish_post($comment_id) : false;
        } elseif ($status === 'hold' || $status === 'pending') {
            try {
                $ok = $tododb->update("{$tododb->prefix}posts", ['post_status' => 'draft'], ['ID' => $comment_id]) !== false;
            } catch (Throwable $e) {
                $ok = false;
            }
        }

        if (!$ok) throw new Exception(__('更新状态失败'));
    }
    
    echo json_encode(['success' => true, 'message' => __('状态更新成功')]);
    exit;
}

/**
 * 回复评论
 */
function handle_reply_comment() {
    $parent_id = intval($_POST['comment_id'] ?? $_POST['parent_id'] ?? $_POST['message_id'] ?? $_POST['review_id'] ?? $_POST['inquiry_id'] ?? 0);
    $content = trim($_POST['reply_content'] ?? $_POST['comment_content'] ?? '');
    
    if ($parent_id <= 0) throw new Exception(__('无效的评论ID'));
    
    $action = $_POST['action'] ?? '';
    $notify_user = (isset($_POST['notify_user']) ? 1 : 0) || (isset($_POST['send_email']) && ($_POST['send_email'] === '1' || $_POST['send_email'] === 1 || $_POST['send_email'] === 'on')) ? 1 : 0;
    $template_slug = sanitize_text_field($_POST['template_slug'] ?? '');
    $content_for_email = $content;
    $content_for_store = $content;
    if ($content_for_store === '' && $notify_user && $template_slug !== '' && function_exists('todo_get_email_template')) {
        $tpl = todo_get_email_template($template_slug);
        if ($tpl) {
            $content_for_store = '（' . __('邮件模板回复') . '）';
        }
    }
    if ($content_for_store === '') throw new Exception(__('回复内容不能为空'));
    $token_key = 'reply_comment|' . (string) $action . '|' . intval($parent_id) . '|' . (string) $content_for_store . '|' . intval($notify_user) . '|' . (string) $template_slug;
    if (function_exists('todo_claim_submit_token') && !todo_claim_submit_token($token_key, 120)) {
        throw new Exception(__('正在发送中，请勿重复提交'));
    }

    // 获取父评论以确定类型和关联ID
    if ($action === 'reply_message' && function_exists('get_post')) {
        $post = get_post($parent_id);
        if ($post && is_array($post)) $post = (object) $post;
        if ($post && (($post->post_type ?? '') === 'guestbook' || ($post->post_type ?? '') === 'message')) {
            $user_id = todo_get_current_user_id();
            $user = todo_get_user($user_id);
            $reply_data = [
                'comment_post_ID' => $parent_id,
                'comment_parent' => 0,
                'comment_author' => $user->display_name ?? $user->username ?? 'Admin',
                'comment_author_email' => $user->email ?? '',
                'comment_content' => $content_for_store,
                'comment_type' => 'guestbook',
                'user_id' => $user_id,
                'comment_approved' => 0
            ];
            $reply_id = todo_create_comment($reply_data);
            if (!$reply_id) throw new Exception(__('回复失败'));

            if ($notify_user && function_exists('todo_send_message_reply_notification')) {
                todo_send_message_reply_notification($parent_id, $content_for_email, $template_slug);
            }

            echo json_encode(['success' => true, 'message' => __('回复成功'), 'reply_id' => $reply_id]);
            exit;
        }
    }

    $parent = todo_get_comment($parent_id);
    if (!$parent) {
        // 尝试从 todo_product_reviews 中匹配评价主记录
        global $tododb;
        $pr_table = $tododb->prefix . 'product_reviews';
        $pr_row = $tododb->get_row($tododb->prepare("SELECT * FROM {$pr_table} WHERE id = %d", $parent_id), OBJECT);
        if ($pr_row) {
            // 更新 todo_product_reviews 上的商家回复
            $tododb->update(
                $pr_table,
                ['reply_content' => $content_for_store, 'reply_time' => current_time('mysql')],
                ['id' => $parent_id]
            );

            // 同时将二级回复插入到 todo_comments 表（comment_parent = parent_id, comment_type = 'review'）
            $user_id = todo_get_current_user_id();
            $user = todo_get_user($user_id);
            $reply_data = [
                'comment_post_ID' => (int) $pr_row->product_id,
                'comment_parent' => $parent_id,
                'comment_author' => $user->display_name ?? $user->username ?? 'Admin',
                'comment_author_email' => $user->email ?? '',
                'comment_content' => $content_for_store,
                'comment_type' => 'review',
                'user_id' => $user_id,
                'comment_approved' => 1
            ];
            $reply_id = todo_create_comment($reply_data);

            if ($notify_user && function_exists('todo_send_review_reply_notification')) {
                todo_send_review_reply_notification($parent_id, $content_for_email, $template_slug);
            }

            echo json_encode(['success' => true, 'message' => __('回复成功'), 'reply_id' => $reply_id ?: $parent_id]);
            exit;
        }

        if ($action === 'reply_message') {
            $post = function_exists('get_post') ? get_post($parent_id) : null;
            if (!$post) throw new Exception(__('留言不存在'));

            $user_id = todo_get_current_user_id();
            $user = todo_get_user($user_id);
            $reply_data = [
                'comment_post_ID' => $parent_id,
                'comment_parent' => 0,
                'comment_author' => $user->display_name ?? $user->username ?? 'Admin',
                'comment_author_email' => $user->email ?? '',
                'comment_content' => $content_for_store,
                'comment_type' => 'guestbook',
                'user_id' => $user_id,
                'comment_approved' => 0
            ];
            $reply_id = todo_create_comment($reply_data);
            if (!$reply_id) throw new Exception(__('回复失败'));

            if ($notify_user && function_exists('todo_send_message_reply_notification')) {
                todo_send_message_reply_notification($parent_id, $content_for_email, $template_slug);
            }

            echo json_encode(['success' => true, 'message' => __('回复成功'), 'reply_id' => $reply_id]);
            exit;
        }
        throw new Exception(__('父评论不存在'));
    }
    
    $user_id = todo_get_current_user_id();
    $user = todo_get_user($user_id);
    
    $reply_data = [
        'comment_post_ID' => $parent->comment_post_ID,
        'comment_parent' => $parent_id,
        'comment_author' => $user->display_name ?? $user->username ?? 'Admin',
        'comment_author_email' => $user->email ?? '',
        'comment_content' => $content_for_store,
        'comment_type' => $parent->comment_type ?? 'comment',
        'user_id' => $user_id,
        'comment_approved' => 0
    ];
    
    $reply_id = todo_create_comment($reply_data);
    if (!$reply_id) throw new Exception(__('回复失败'));
    
    // 同时更新主评论的 admin_reply 字段以保持向后兼容
    $c_obj = new CMS_Comment($parent_id);
    $c_obj->update([
        'admin_reply' => $content_for_store,
        'replied_at' => current_time('mysql')
    ]);

    if ($notify_user && function_exists('todo_send_comment_reply_notification')) {
        todo_send_comment_reply_notification($reply_id, $template_slug);
    }
    
    echo json_encode(['success' => true, 'message' => __('回复成功'), 'reply_id' => $reply_id]);
    exit;
}

/**
 * 删除评论
 */
function handle_delete_comment() {
    $comment_id = intval($_POST['comment_id'] ?? 0);
    $force = isset($_POST['force']) && $_POST['force'] == '1';
    
    if ($comment_id <= 0) throw new Exception(__('无效的评论ID'));
    
    $result = todo_delete_comment($comment_id, $force);
    if (!$result) {
        $post = function_exists('get_post') ? get_post($comment_id) : null;
        if (!$post) throw new Exception(__('删除失败'));
        $result = function_exists('todo_delete_post') ? todo_delete_post($comment_id, $force) : false;
        if (!$result) throw new Exception(__('删除失败'));
    }
    
    echo json_encode(['success' => true, 'message' => __('删除成功')]);
    exit;
}

/**
 * 添加评论 (兼容前端)
 */
function handle_add_comment() {
    global $tododb;
    $client_ip = function_exists('todo_get_client_ip') 
        ? todo_get_client_ip() 
        : (function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));

    // 1. IP 黑名单检测
    $blacklist_str = function_exists('get_option') ? (string) get_option('comment_blacklist', '') : '';
    if ($blacklist_str !== '') {
        $black_list = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $blacklist_str))));
        foreach ($black_list as $bad) {
            if ($bad !== '' && (strpos($client_ip, $bad) !== false || (isset($_POST['author_email']) && strpos($_POST['author_email'], $bad) !== false))) {
                throw new Exception(__('您的 IP 或邮箱已被列入黑名单，禁止发表评论'));
            }
        }
    }

    $post_id = intval($_POST['post_id'] ?? $_POST['comment_post_ID'] ?? $_POST['product_id'] ?? 0);
    $content = sanitize_textarea_field($_POST['content'] ?? $_POST['comment'] ?? $_POST['comment_content'] ?? '');
    $parent_id = intval($_POST['parent_id'] ?? $_POST['comment_parent'] ?? 0);

    global $action;
    $default_type = 'comment';
    $post_type = function_exists('get_post_type') ? get_post_type($post_id) : '';

    if ($action === 'add_inquiry') {
        $default_type = 'inquiry';
    } elseif ($action === 'add_message') {
        $default_type = 'message';
    } elseif ($post_type === 'product' && (isset($_POST['rating']) || (isset($_POST['comment_type']) && $_POST['comment_type'] === 'review'))) {
        $default_type = 'review';
    }
    $type = $_POST['comment_type'] ?? $default_type;
    
    // 电商评价与回复权限划分逻辑：
    // 顶级评价 ($parent_id == 0 且 $type == 'review') 只能由已完成订单生成，禁止前端直接发起顶级打分评价
    if ($type === 'review' && $parent_id <= 0) {
        throw new Exception(__('商品顶级评价仅允许买家在已完成订单中发起，请前往【我的订单】进行评价'));
    }

    if (empty($content)) throw new Exception(__('内容不能为空'));
    if (mb_strlen($content) < 2) throw new Exception(__('评论内容太短'));
    if (mb_strlen($content) > 1000) throw new Exception(__('评论内容不能超过 1000 字'));

    // 2. 防灌水 & 提交频率限制 (同一 IP 60秒内最多发表 1 条)
    $one_min_ago = date('Y-m-d H:i:s', time() - 60);
    $recent_count = (int) $tododb->get_var(
        "SELECT COUNT(*) FROM {$tododb->prefix}comments 
         WHERE comment_author_IP = ? AND comment_date > ?",
        [$client_ip, $one_min_ago]
    );
    if ($recent_count >= 1) {
        throw new Exception(__('发表过于频繁，请 60 秒后再试'));
    }

    // 3. 防刷屏：同一 IP 60秒内禁止重复提交完全相同的内容
    $dup_count = (int) $tododb->get_var(
        "SELECT COUNT(*) FROM {$tododb->prefix}comments 
         WHERE comment_author_IP = ? AND comment_content = ? AND comment_date > ?",
        [$client_ip, $content, $one_min_ago]
    );
    if ($dup_count > 0) {
        throw new Exception(__('请勿重复提交完全相同的内容'));
    }
    
    $author_name = sanitize_text_field($_POST['author_name'] ?? $_POST['author'] ?? '');
    $author_email = sanitize_email($_POST['author_email'] ?? $_POST['email'] ?? '');
    $author_website = esc_url_raw($_POST['author_website'] ?? $_POST['url'] ?? '');

    // 尝试获取当前前台登录用户
    $user_id = 0;
    if (class_exists('Todo_Session_Manager')) {
        $user_id = intval(Todo_Session_Manager::get_frontend_user_id() ?: 0);
    }
    if ($user_id <= 0 && function_exists('todo_get_current_user_id')) {
        $user_id = intval(todo_get_current_user_id());
    }

    if ($user_id > 0 && function_exists('todo_get_user')) {
        $curr_user = todo_get_user($user_id);
        if ($curr_user) {
            if (is_array($curr_user)) $curr_user = (object) $curr_user;
            if (empty($author_name)) {
                $author_name = $curr_user->display_name ?? $curr_user->username ?? $curr_user->user_name ?? (__('用户') . $user_id);
            }
            if (empty($author_email)) {
                $author_email = $curr_user->email ?? $curr_user->user_email ?? ($user_id . '@user.local');
            }
        }
    }

    // 如果前台游客未填姓名/邮箱，自动填充缺省标识（支持免注册临时身份无阻回复）
    if (empty($author_name)) {
        $author_name = __('热心网友');
    }
    if (empty($author_email)) {
        $author_email = 'guest_' . time() . '@guest.local';
    }

    // 检查评论强制登录设置
    $req_login = function_exists('get_option') ? get_option('comment_registration', 0) : 0;
    if ($req_login && $user_id <= 0) {
        throw new Exception(__('请先登录后再发表评论'));
    }
    
    $reply_to_id = intval($_POST['parent_id'] ?? $_POST['comment_parent'] ?? 0);
    $reply_to_name = '';

    // 若回复的是子评论或评价主记录，追溯顶层根评价/评论，保持双层结构并统一类型
    if ($parent_id > 0) {
        if ($type === 'review') {
            // 当前上下文为商品评价/追评：优先检查当前产品下的已回复记录 (comments 表)，否则匹配主评价 (product_reviews 表)
            $parent_row = $tododb->get_row(
                "SELECT comment_author, comment_parent, comment_type, comment_post_ID 
                 FROM {$tododb->prefix}comments 
                 WHERE comment_ID = ? AND comment_type = 'review' AND comment_post_ID = ?",
                [$parent_id, $post_id]
            );
            if ($parent_row) {
                $reply_to_name = $parent_row->comment_author;
                $p_parent = intval($parent_row->comment_parent);
                if ($p_parent > 0) {
                    $parent_id = $p_parent;
                }
            } else {
                // 匹配 todo_product_reviews 评价主记录 (限定 product_id)
                $pr_row = $tododb->get_row(
                    "SELECT pr.id, pr.product_id, pr.user_id, COALESCE(u.display_name, u.user_login, '买家') as user_name 
                     FROM {$tododb->prefix}product_reviews pr 
                     LEFT JOIN {$tododb->prefix}users u ON pr.user_id = u.id 
                     WHERE pr.id = ? AND pr.product_id = ?",
                    [$parent_id, $post_id]
                );
                if ($pr_row) {
                    $reply_to_name = (string) $pr_row->user_name;
                }
            }
        } else {
            // 常规文章/页面/留言评论：限定在当前 post_id 且排除 review 类型
            $parent_row = $tododb->get_row(
                "SELECT comment_author, comment_parent, comment_type, comment_post_ID 
                 FROM {$tododb->prefix}comments 
                 WHERE comment_ID = ? AND comment_post_ID = ? AND comment_type != 'review'",
                [$parent_id, $post_id]
            );
            if ($parent_row) {
                $reply_to_name = $parent_row->comment_author;
                $p_parent = intval($parent_row->comment_parent);
                if ($p_parent > 0) {
                    $parent_id = $p_parent;
                }
            }
        }
    }

    $comment_data = [
        'post_id' => $post_id,
        'comment_post_ID' => $post_id,
        'parent_id' => $parent_id,
        'comment_parent' => $parent_id,
        'author_name' => $author_name,
        'comment_author' => $author_name,
        'author_email' => $author_email,
        'comment_author_email' => $author_email,
        'author_website' => $author_website,
        'comment_author_url' => $author_website,
        'content' => $content,
        'comment_content' => $content,
        'comment_type' => $type,
        'user_id' => $user_id,
    ];
    
    // 处理评分 (仅当是顶级商品评价且来自订单时才记录，对评价的回复不打分)
    if ($type === 'review' && $parent_id <= 0 && todo_post_string('rating') !== '') {
        $comment_data['comment_rating'] = todo_post_int('rating');
    } else {
        $comment_data['comment_rating'] = 0;
    }
    
    $comment_id = todo_create_comment($comment_data);
    if (!$comment_id) throw new Exception(__('提交失败'));
    
    $lang_code = sanitize_text_field($_POST['lang_code'] ?? ($_POST['lang'] ?? ''));
    if (empty($lang_code)) {
        if (function_exists('get_current_language')) {
            $lang_code = get_current_language();
        } elseif (class_exists('LocaleContext')) {
            $lang_code = LocaleContext::get_instance()->get_locale();
        }
    }
    if (!empty($lang_code) && class_exists('Todo_Comment_Manager')) {
        Todo_Comment_Manager::save_comment_meta($comment_id, 'lang_code', $lang_code);
    }

    if (!empty($reply_to_name) && class_exists('Todo_Comment_Manager')) {
        Todo_Comment_Manager::save_comment_meta($comment_id, 'reply_to_name', $reply_to_name);
    }
    if ($reply_to_id > 0 && class_exists('Todo_Comment_Manager')) {
        Todo_Comment_Manager::save_comment_meta($comment_id, 'reply_to_id', $reply_to_id);
    }
    
    echo json_encode([
        'success' => true, 
        'comment_id' => intval($comment_id),
        'message' => __('提交成功')
    ]);
    exit;
}

/**
 * 获取评论/留言分页列表 (支持 hot 热门 / newest 最新 排序与展开加载)
 */
function handle_get_comments_list() {
    global $tododb;
    $post_id = intval($_REQUEST['post_id'] ?? 0);
    $type = sanitize_text_field($_REQUEST['type'] ?? 'comment');
    $sort = sanitize_text_field($_REQUEST['sort'] ?? 'hot'); // hot | newest
    $page = max(1, intval($_REQUEST['page'] ?? 1));
    $default_per_page = function_exists('get_option') ? (int)get_option('comments_per_page', 20) : 20;
    if ($default_per_page <= 0) $default_per_page = 20;
    $per_page = isset($_REQUEST['per_page']) && is_numeric($_REQUEST['per_page']) ? max(1, min(100, intval($_REQUEST['per_page']))) : $default_per_page;
    $parent_id = intval($_REQUEST['parent_id'] ?? 0);

    $offset = ($page - 1) * $per_page;

    // 默认查询已批准评论
    $status = '1';
    $type_where = ($type !== 'all') ? "AND comment_type = " . $tododb->prepare('%s', $type) : "";
    $post_where = ($post_id > 0) ? "AND comment_post_ID = " . intval($post_id) : "";

    // 排序逻辑
    $order_by_sql = "c.comment_date DESC";
    if ($sort === 'hot') {
        // 热度规则：优先按 (likes_count * 3 + rating * 2) 数值降序，再按时间降序
        $order_by_sql = "(CAST(COALESCE(m_likes.meta_value, '0') AS UNSIGNED) * 3 + c.comment_rating * 2) DESC, c.comment_date DESC";
    }

    $likes_join = "LEFT JOIN {$tododb->prefix}commentmeta m_likes ON (c.comment_ID = m_likes.comment_id AND m_likes.meta_key = 'likes_count')";

    // 如果是单独请求某个主评论下的全部子评论
    if ($parent_id > 0) {
        $parent_author = '';
        if ($type === 'review') {
            $pr_table = $tododb->prefix . 'product_reviews';
            $users_table = $tododb->prefix . 'users';
            $pr_where = "pr.id = %d";
            if ($post_id > 0) {
                $pr_where .= " AND pr.product_id = " . intval($post_id);
            }
            $pr_row = $tododb->get_row($tododb->prepare(
                "SELECT pr.user_id, COALESCE(u.display_name, u.user_login, '游客') as user_name FROM {$pr_table} pr LEFT JOIN {$users_table} u ON pr.user_id = u.id WHERE {$pr_where}",
                $parent_id
            ));
            if ($pr_row) {
                $parent_author = esc_html($pr_row->user_name);
            } else {
                $parent_comment = todo_get_comment($parent_id);
                if ($parent_comment && ($parent_comment->comment_type ?? '') === 'review') {
                    $parent_author = esc_html($parent_comment->comment_author);
                }
            }
        } else {
            $parent_comment = todo_get_comment($parent_id);
            if ($parent_comment && ($parent_comment->comment_type ?? '') !== 'review') {
                $parent_author = esc_html($parent_comment->comment_author);
            }
        }
        
        $type_sub_where = ($type !== 'all') ? "AND c.comment_type = " . $tododb->prepare('%s', $type) : "";
        $post_sub_where = ($post_id > 0) ? "AND c.comment_post_ID = " . intval($post_id) : "";
        $sub_sql = "SELECT c.*, COALESCE(m_likes.meta_value, 0) as likes_count, m_reply.meta_value as reply_to_name 
                    FROM {$tododb->prefix}comments c 
                    {$likes_join}
                    LEFT JOIN {$tododb->prefix}commentmeta m_reply ON (c.comment_ID = m_reply.comment_id AND m_reply.meta_key = 'reply_to_name')
                    WHERE c.comment_approved = '1' AND c.comment_parent = ? {$type_sub_where} {$post_sub_where}
                    ORDER BY c.comment_date ASC";
        $sub_rows = $tododb->get_results($sub_sql, [$parent_id]);
        $replies = [];
        foreach ($sub_rows as $sub) {
            $target_name = !empty($sub->reply_to_name) ? esc_html($sub->reply_to_name) : $parent_author;
            $replies[] = [
                'comment_id' => intval($sub->comment_ID),
                'author_name' => esc_html($sub->comment_author),
                'author_avatar' => mb_strtoupper(mb_substr($sub->comment_author, 0, 1, 'UTF-8')),
                'author_website' => esc_url($sub->comment_author_url),
                'comment_date' => esc_html($sub->comment_date),
                'comment_content' => nl2br(esc_html($sub->comment_content)),
                'likes_count' => intval($sub->likes_count),
                'reply_to_name' => $target_name
            ];
        }
        echo json_encode([
            'success' => true,
            'parent_id' => $parent_id,
            'replies' => $replies
        ]);
        exit;
    }

    // 特殊情况：评价类型为 review，主评价保存在 todo_product_reviews，回复保存在 todo_comments
    if ($type === 'review') {
        $pr_table = $tododb->prefix . 'product_reviews';
        $users_table = $tododb->prefix . 'users';

        $pr_where = ["pr.status = 1"];
        $pr_params = [];
        if ($post_id > 0) {
            $pr_where[] = "pr.product_id = ?";
            $pr_params[] = $post_id;
        }
        $pr_where_sql = implode(' AND ', $pr_where);

        $pr_order = "pr.created_at DESC";
        if ($sort === 'hot') {
            $pr_order = "pr.rating DESC, pr.created_at DESC";
        }

        $count_pr_sql = "SELECT COUNT(*) FROM {$pr_table} pr WHERE {$pr_where_sql}";
        $total = (int) $tododb->get_var($count_pr_sql, $pr_params);

        $pr_sql = "SELECT pr.*, COALESCE(u.display_name, u.user_login, '游客') as user_name
                   FROM {$pr_table} pr
                   LEFT JOIN {$users_table} u ON pr.user_id = u.id
                   WHERE {$pr_where_sql}
                   ORDER BY {$pr_order}
                   LIMIT {$offset}, {$per_page}";
        $rows = $tododb->get_results($pr_sql, $pr_params);
        $items = [];

        foreach ($rows as $row) {
            $cid = intval($row->id);
            $rating = intval($row->rating ?? 5);

            // 解析图片
            $images = [];
            if (!empty($row->images)) {
                $decoded_imgs = json_decode($row->images, true);
                if (is_array($decoded_imgs)) {
                    $images = array_map(function($u) {
                        return function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($u) : (function_exists('todo_media_url_to_absolute') ? todo_media_url_to_absolute($u) : esc_url($u));
                    }, $decoded_imgs);
                }
            }

            // 抓取该评价在 todo_comments 中的回复 (前 2 条)
            $sub_sql = "SELECT c.*, COALESCE(m_likes.meta_value, 0) as likes_count, m_reply.meta_value as reply_to_name 
                        FROM {$tododb->prefix}comments c 
                        {$likes_join}
                        LEFT JOIN {$tododb->prefix}commentmeta m_reply ON (c.comment_ID = m_reply.comment_id AND m_reply.meta_key = 'reply_to_name')
                        WHERE c.comment_approved = '1' AND c.comment_parent = ? AND c.comment_type = 'review'
                        ORDER BY c.comment_date ASC LIMIT 2";
            $sub_rows = $tododb->get_results($sub_sql, [$cid]);

            // 获取子评论总数
            $sub_count_sql = "SELECT COUNT(*) FROM {$tododb->prefix}comments WHERE comment_approved = '1' AND comment_parent = ? AND comment_type = 'review'";
            $sub_total = (int) $tododb->get_var($sub_count_sql, [$cid]);

            $replies = [];
            foreach ($sub_rows as $sub) {
                $target_name = !empty($sub->reply_to_name) ? esc_html($sub->reply_to_name) : esc_html($row->user_name);
                $replies[] = [
                    'comment_id' => intval($sub->comment_ID),
                    'author_name' => esc_html($sub->comment_author),
                    'author_avatar' => mb_strtoupper(mb_substr($sub->comment_author, 0, 1, 'UTF-8')),
                    'author_website' => esc_url($sub->comment_author_url),
                    'comment_date' => esc_html($sub->comment_date),
                    'comment_content' => nl2br(esc_html($sub->comment_content)),
                    'likes_count' => intval($sub->likes_count),
                    'reply_to_name' => $target_name
                ];
            }

            $display_author_name = (!empty($row->user_name) && $row->user_name !== '游客') ? esc_html($row->user_name) : __('游客');
            $items[] = [
                'comment_id' => $cid,
                'author_name' => $display_author_name,
                'author_avatar' => mb_strtoupper(mb_substr($display_author_name, 0, 1, 'UTF-8')),
                'author_website' => '',
                'comment_date' => esc_html($row->created_at),
                'comment_content' => nl2br(esc_html($row->content)),
                'likes_count' => 0,
                'rating' => $rating,
                'rating_percent' => ($rating / 5) * 100,
                'spec_name' => !empty($row->spec_name) ? esc_html($row->spec_name) : '',
                'images' => $images,
                'replies' => $replies,
                'sub_total' => $sub_total,
                'has_more_replies' => ($sub_total > count($replies))
            ];
        }

        $has_more = ($offset + count($items)) < $total;

        echo json_encode([
            'success' => true,
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'has_more' => $has_more,
            'items' => $items
        ]);
        exit;
    }

    // 获取顶级评论总数
    $count_sql = "SELECT COUNT(*) FROM {$tododb->prefix}comments c WHERE c.comment_approved = '1' AND c.comment_parent = ? {$post_where} {$type_where}";
    $total = (int) $tododb->get_var($count_sql, [$parent_id]);

    $spec_join = "LEFT JOIN {$tododb->prefix}commentmeta m_spec ON (c.comment_ID = m_spec.comment_id AND m_spec.meta_key = 'spec_name')";
    $imgs_join = "LEFT JOIN {$tododb->prefix}commentmeta m_imgs ON (c.comment_ID = m_imgs.comment_id AND m_imgs.meta_key = 'images')";

    // 查询当前页主列表
    $sql = "SELECT c.*, COALESCE(m_likes.meta_value, 0) as likes_count, m_spec.meta_value as spec_name, m_imgs.meta_value as images_json 
            FROM {$tododb->prefix}comments c
            {$likes_join}
            {$spec_join}
            {$imgs_join}
            WHERE c.comment_approved = '1' AND c.comment_parent = ? {$post_where} {$type_where}
            ORDER BY {$order_by_sql}
            LIMIT {$offset}, {$per_page}";
    
    $rows = $tododb->get_results($sql, [$parent_id]);
    $items = [];

    foreach ($rows as $row) {
        $cid = intval($row->comment_ID);
        $rating = intval($row->comment_rating ?? 0);
        
        // 解析图片列表
        $images = [];
        if (!empty($row->images_json)) {
            $decoded_imgs = json_decode($row->images_json, true);
            if (is_array($decoded_imgs)) {
                $images = array_map(function($u) {
                    return function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($u) : (function_exists('todo_media_url_to_absolute') ? todo_media_url_to_absolute($u) : esc_url($u));
                }, $decoded_imgs);
            }
        }
        
        // 抓取该顶级评论下的回复 (最热或最新的前 2 条)
        $sub_type = ($type !== 'all') ? $type : 'comment';
        $sub_sql = "SELECT c.*, COALESCE(m_likes.meta_value, 0) as likes_count, m_reply.meta_value as reply_to_name 
                    FROM {$tododb->prefix}comments c 
                    {$likes_join}
                    LEFT JOIN {$tododb->prefix}commentmeta m_reply ON (c.comment_ID = m_reply.comment_id AND m_reply.meta_key = 'reply_to_name')
                    WHERE c.comment_approved = '1' AND c.comment_parent = ? AND c.comment_type = ?
                    ORDER BY c.comment_date ASC LIMIT 2";
        $sub_rows = $tododb->get_results($sub_sql, [$cid, $sub_type]);

        // 获取子评论总数
        $sub_count_sql = "SELECT COUNT(*) FROM {$tododb->prefix}comments WHERE comment_approved = '1' AND comment_parent = ? AND comment_type = ?";
        $sub_total = (int) $tododb->get_var($sub_count_sql, [$cid, $sub_type]);

        $replies = [];
        foreach ($sub_rows as $sub) {
            $target_name = !empty($sub->reply_to_name) ? esc_html($sub->reply_to_name) : esc_html($row->comment_author);
            $replies[] = [
                'comment_id' => intval($sub->comment_ID),
                'author_name' => esc_html($sub->comment_author),
                'author_avatar' => mb_strtoupper(mb_substr($sub->comment_author, 0, 1, 'UTF-8')),
                'author_website' => esc_url($sub->comment_author_url),
                'comment_date' => esc_html($sub->comment_date),
                'comment_content' => nl2br(esc_html($sub->comment_content)),
                'likes_count' => intval($sub->likes_count),
                'reply_to_name' => $target_name
            ];
        }

        $items[] = [
            'comment_id' => $cid,
            'author_name' => esc_html($row->comment_author),
            'author_avatar' => mb_strtoupper(mb_substr($row->comment_author, 0, 1, 'UTF-8')),
            'author_website' => esc_url($row->comment_author_url),
            'comment_date' => esc_html($row->comment_date),
            'comment_content' => nl2br(esc_html($row->comment_content)),
            'likes_count' => intval($row->likes_count),
            'rating' => $rating,
            'rating_percent' => ($rating / 5) * 100,
            'spec_name' => !empty($row->spec_name) ? esc_html($row->spec_name) : '',
            'images' => $images,
            'replies' => $replies,
            'sub_total' => $sub_total,
            'has_more_replies' => ($sub_total > count($replies))
        ];
    }

    $has_more = ($offset + count($items)) < $total;

    echo json_encode([
        'success' => true,
        'page' => $page,
        'per_page' => $per_page,
        'total' => $total,
        'has_more' => $has_more,
        'items' => $items
    ]);
    exit;
}

/**
 * 评论/留言点赞
 */
function handle_like_comment() {
    $comment_id = intval($_POST['comment_id'] ?? $_GET['comment_id'] ?? 0);
    if ($comment_id <= 0) {
        echo json_encode(['success' => false, 'message' => __('无效的评论ID')]);
        exit;
    }

    $c_obj = new CMS_Comment($comment_id);
    $meta = $c_obj->get_all_meta();
    $existing = isset($meta['likes_count']) ? intval($meta['likes_count']) : 0;
    $new_count = $existing + 1;

    $c_obj->update_meta('likes_count', (string)$new_count);

    echo json_encode(['success' => true, 'likes_count' => $new_count]);
    exit;
}
