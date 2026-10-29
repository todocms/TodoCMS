<?php
if (!defined('DOING_AJAX') || !DOING_AJAX) {
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Direct access denied'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

require_once dirname(dirname(dirname(__FILE__))) . '/core/bootstrap.php';

if (!is_user_logged_in() || !todo_current_user_can('manage_options')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '权限不足'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!class_exists('Todo_Security_Manager') || !Todo_Security_Manager::verify_request_csrf('mail_settings_nonce', '_todononce')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('todo_run_imap_polling')) {
    require_once dirname(dirname(dirname(__FILE__))) . '/core/functions/mail-extend.php';
}

$thread_key = trim((string) ($_POST['thread_key'] ?? ''));
$last_id = intval($_POST['last_id'] ?? 0);
if ($thread_key === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少 thread_key'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!todo_imap_polling_enabled()) {
    echo json_encode([
        'success' => true,
        'imap_enabled' => false,
        'new_count' => 0,
        'latest_id' => $last_id,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stats = todo_run_imap_polling(['force' => true, 'return_stats' => true, 'limit' => 30]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'IMAP 同步失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

global $tododb;
if (!is_object($tododb)) {
    $tododb = new CMS_DB();
}
if (function_exists('todo_ensure_email_logs_schema')) {
    todo_ensure_email_logs_schema($tododb->prefix . 'email_logs', $tododb);
}

$table = $tododb->prefix . 'email_logs';
$new_count = intval($tododb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE thread_key = ? AND id > ?", [$thread_key, $last_id]));
$latest_id = intval($tododb->get_var("SELECT id FROM `{$table}` WHERE thread_key = ? ORDER BY id DESC LIMIT 1", [$thread_key])) ?: $last_id;

$messages = [];
if ($new_count > 0) {
    $me_avatar_url = '';
    $me_initial = '';
    $inq_email = '';
    $inq_initial = '';
    if (preg_match('/^inquiry:(\d+)$/', $thread_key, $m)) {
        $inq_id = intval($m[1]);
        if ($inq_id > 0) {
            $comment_table = $tododb->prefix . 'comments';
            $c = $tododb->get_row("SELECT comment_author, comment_author_email FROM `{$comment_table}` WHERE comment_ID = ? LIMIT 1", [$inq_id]);
            if ($c && is_object($c)) {
                $inq_email = strtolower(trim((string) ($c->comment_author_email ?? '')));
                $inq_name = trim((string) ($c->comment_author ?? ''));
                if ($inq_name !== '') {
                    if (function_exists('mb_substr')) {
                        $inq_initial = (string) mb_substr($inq_name, 0, 1, 'UTF-8');
                    } elseif (preg_match('/^./u', $inq_name, $mm)) {
                        $inq_initial = (string) ($mm[0] ?? '');
                    } else {
                        $inq_initial = strtoupper(substr($inq_name, 0, 1));
                    }
                } elseif ($inq_email !== '') {
                    $inq_initial = strtoupper(substr($inq_email, 0, 1));
                }
            }
        }
    }
    if (function_exists('get_current_user_id') && function_exists('get_user_avatar_url')) {
        $uid = intval(get_current_user_id());
        if ($uid > 0) {
            $me_avatar_url = (string) get_user_avatar_url($uid, 36);
            if ($me_avatar_url !== '' && (stripos($me_avatar_url, 'admin/assets/images/default-avatar.svg') !== false || stripos($me_avatar_url, 'default-avatar.svg') !== false)) {
                $me_avatar_url = '';
            }
            if ($me_avatar_url !== '' && stripos($me_avatar_url, 'seed_avatar_') !== false && preg_match('/\.[a-z0-9]{2,6}(\?|$)/i', $me_avatar_url) !== 1) {
                $me_avatar_url = '';
            }
            $me_name = '';
            if (function_exists('get_user_display_name')) {
                $me_name = (string) get_user_display_name($uid);
            } elseif (function_exists('get_userdata')) {
                $u = get_userdata($uid);
                if ($u && is_object($u)) {
                    if (is_callable([$u, 'get_display_name'])) {
                        $me_name = (string) $u->get_display_name();
                    } elseif (isset($u->display_name)) {
                        $me_name = (string) $u->display_name;
                    }
                }
            }
            $me_name = trim((string) $me_name);
            if ($me_name !== '') {
                if (function_exists('mb_substr')) {
                    $me_initial = (string) mb_substr($me_name, 0, 1, 'UTF-8');
                } elseif (preg_match('/^./u', $me_name, $mm)) {
                    $me_initial = (string) ($mm[0] ?? '');
                } else {
                    $me_initial = strtoupper(substr($me_name, 0, 1));
                }
            }
        }
    }
    $rows = $tododb->get_results(
        "SELECT * FROM `{$table}` WHERE thread_key = ? AND id > ? ORDER BY id ASC LIMIT 50",
        [$thread_key, $last_id]
    );
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $dir = is_object($r) ? (string) ($r->direction ?? 'out') : (is_array($r) ? (string) ($r['direction'] ?? 'out') : 'out');
            $dir = $dir === 'in' ? 'in' : 'out';
            $is_in = $dir === 'in';
            $from_email = is_object($r) ? (string) ($r->from_email ?? '') : (is_array($r) ? (string) ($r['from_email'] ?? '') : '');
            $to_email = '';
            if ($is_in) {
                $to_email = is_object($r) ? (string) ($r->to_email ?? ($r->recipient ?? '')) : (is_array($r) ? (string) ($r['to_email'] ?? ($r['recipient'] ?? '')) : '');
            } else {
                $to_email = is_object($r) ? (string) ($r->recipient ?? ($r->to_email ?? '')) : (is_array($r) ? (string) ($r['recipient'] ?? ($r['to_email'] ?? '')) : '');
            }

            $time = '';
            if (is_object($r)) {
                $time = (string) (($r->received_at ?? '') !== '' ? $r->received_at : (($r->sent_at ?? '') !== '' ? $r->sent_at : ($r->created_at ?? '')));
            } elseif (is_array($r)) {
                $time = (string) (($r['received_at'] ?? '') !== '' ? $r['received_at'] : (($r['sent_at'] ?? '') !== '' ? $r['sent_at'] : ($r['created_at'] ?? '')));
            }
            $subj = is_object($r) ? (string) ($r->subject ?? '') : (is_array($r) ? (string) ($r['subject'] ?? '') : '');
            $raw = is_object($r) ? (string) ($r->content ?? '') : (is_array($r) ? (string) ($r['content'] ?? '') : '');
            if (function_exists('todo_imap_maybe_decode_base64_text')) {
                $raw = (string) todo_imap_maybe_decode_base64_text($raw);
            }
            if (function_exists('todo_to_utf8_string')) {
                $raw = (string) todo_to_utf8_string($raw);
            }
            $plain = function_exists('todo_html_to_plain_text') ? todo_html_to_plain_text($raw) : strip_tags($raw);
            if (function_exists('todo_mail_strip_reply_noise')) {
                $plain = todo_mail_strip_reply_noise($plain);
            }
            $plain = trim((string) $plain);
            $html = function_exists('todo_mail_render_chat_bubble_html') ? todo_mail_render_chat_bubble_html($plain, $raw, $dir) : $plain;

            $avatar_url = '';
            $avatar_initial = '';
            if ($is_in) {
                $from_name = '';
                if ($from_email !== '' && function_exists('get_user_by') && function_exists('get_user_avatar_url')) {
                    $u = get_user_by('email', $from_email);
                    if ($u && is_object($u) && is_callable([$u, 'get_id'])) {
                        $avatar_url = (string) get_user_avatar_url($u->get_id(), 36);
                        if (is_callable([$u, 'get_display_name'])) {
                            $from_name = (string) $u->get_display_name();
                        } elseif (isset($u->display_name)) {
                            $from_name = (string) $u->display_name;
                        }
                    }
                }
                if ($avatar_url !== '' && (stripos($avatar_url, 'admin/assets/images/default-avatar.svg') !== false || stripos($avatar_url, 'default-avatar.svg') !== false)) {
                    $avatar_url = '';
                }
                if ($avatar_url !== '' && stripos($avatar_url, 'seed_avatar_') !== false && preg_match('/\.[a-z0-9]{2,6}(\?|$)/i', $avatar_url) !== 1) {
                    $avatar_url = '';
                }
                $from_name = trim((string) $from_name);
                if ($from_name !== '') {
                    if (function_exists('mb_substr')) {
                        $avatar_initial = (string) mb_substr($from_name, 0, 1, 'UTF-8');
                    } elseif (preg_match('/^./u', $from_name, $mm)) {
                        $avatar_initial = (string) ($mm[0] ?? '');
                    } else {
                        $avatar_initial = strtoupper(substr($from_name, 0, 1));
                    }
                } elseif ($inq_email !== '' && strtolower(trim((string) $from_email)) === $inq_email && $inq_initial !== '') {
                    $avatar_initial = $inq_initial;
                } elseif ($from_email !== '') {
                    $avatar_initial = strtoupper(substr($from_email, 0, 1));
                } else {
                    $avatar_initial = 'U';
                }
            } else {
                $avatar_url = $me_avatar_url;
                $avatar_initial = $me_initial !== '' ? $me_initial : '我';
            }

            $id_val = is_object($r) ? intval($r->id ?? 0) : (is_array($r) ? intval($r['id'] ?? 0) : 0);
            $message_id = is_object($r) ? (string) ($r->message_id ?? '') : (is_array($r) ? (string) ($r['message_id'] ?? '') : '');
            $in_reply_to = is_object($r) ? (string) ($r->in_reply_to ?? '') : (is_array($r) ? (string) ($r['in_reply_to'] ?? '') : '');
            $references = is_object($r) ? (string) ($r->references ?? '') : (is_array($r) ? (string) ($r['references'] ?? '') : '');
            $thread_index = is_object($r) ? (string) ($r->thread_index ?? '') : (is_array($r) ? (string) ($r['thread_index'] ?? '') : '');
            $thread_topic = is_object($r) ? (string) ($r->thread_topic ?? '') : (is_array($r) ? (string) ($r['thread_topic'] ?? '') : '');
            $conversation_index = is_object($r) ? (string) ($r->conversation_index ?? '') : (is_array($r) ? (string) ($r['conversation_index'] ?? '') : '');
            $conversation_topic = is_object($r) ? (string) ($r->conversation_topic ?? '') : (is_array($r) ? (string) ($r['conversation_topic'] ?? '') : '');
            $messages[] = [
                'id' => $id_val,
                'direction' => $dir,
                'time' => $time,
                'subject' => $subj,
                'thread_key' => $thread_key,
                'from' => $from_email,
                'to' => $to_email,
                'plain' => $plain,
                'html' => $html,
                'message_id' => $message_id,
                'in_reply_to' => $in_reply_to,
                'references' => $references,
                'thread_index' => $thread_index,
                'thread_topic' => $thread_topic,
                'conversation_index' => $conversation_index,
                'conversation_topic' => $conversation_topic,
                'avatar_url' => $avatar_url,
                'avatar_initial' => $avatar_initial,
            ];
        }
    }
}

echo json_encode([
    'success' => true,
    'imap_enabled' => true,
    'new_count' => $new_count,
    'latest_id' => $latest_id,
    'messages' => $messages,
    'stats' => is_array($stats) ? [
        'ok' => !empty($stats['ok']),
        'reason' => (string) ($stats['reason'] ?? ''),
        'inserted' => intval($stats['inserted'] ?? 0),
        'processed' => intval($stats['processed'] ?? 0),
        'skipped_existing' => intval($stats['skipped_existing'] ?? 0),
    ] : null,
], JSON_UNESCAPED_UNICODE);
exit;
