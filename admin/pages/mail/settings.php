<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

/**
 * 邮件设置页面
 *
 * @package TodoCMS
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 确保邮件扩展函数已加载
if (!function_exists('todo_log_email')) {
    require_once ABSPATH . 'core/functions/mail-extend.php';
}

// 确保媒体库模态框已加载
if (!class_exists('TodoMediaModal') && file_exists(ABSPATH . 'admin/components/media-modal.php')) {
    require_once ABSPATH . 'admin/components/media-modal.php';
}

global $tododb;

// 检查权限（TodoCMS风格）
if (!todo_current_user_can('manage_mail') && !todo_current_user_can('manage_options')) {
    todo_die('您没有足够的权限访问此页面。');
}

// 获取当前Tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// 邮件通知类型定义
// 说明：这些选项控制是否向管理员邮箱发送特定类型的邮件通知
// 注意：这仅控制通知邮件的发送，不影响业务功能本身（如留言、注册等功能仍可正常使用）
$notification_types = [
    'comment_reply' => [
        'label' => '文章评论回复',
        'description' => '回复文章评论时通知评论者。变量：{author}, {parent_content}, {reply_author}, {reply_content}, {post_title}, {post_url}, {site_name}',
        'is_template_only' => true
    ],
    'inquiry_reply' => [
        'label' => '产品询盘回复',
        'description' => '回复用户产品咨询时通知用户。变量：{name}, {email}, {subject}, {inquiry_content}, {reply_content}, {product_title}, {product_url}, {site_name}',
        'is_template_only' => true
    ],
    'message_reply' => [
        'label' => '网站留言回复',
        'description' => '回复网站留言时通知留言者。变量：{name}, {email}, {subject}, {message_content}, {reply_content}, {site_name}',
        'is_template_only' => true
    ],
    'subscribe' => [
        'label' => '订阅邮件',
        'description' => '进行订阅群发时使用的邮件模板分类。变量：{name}, {email}, {site_name}',
        'is_template_only' => true
    ],
    'review_reply' => [
        'label' => '商品评价回复',
        'description' => '回复用户商品评价时通知用户。变量：{name}, {email}, {rating}, {review_content}, {reply_content}, {product_title}, {product_url}, {site_name}',
        'is_template_only' => true
    ],
    'register' => [
        'label' => '会员注册验证码',
        'description' => '当新用户注册时，发送验证码邮件。变量：{code}, {email}, {site_name}',
        'fixed_slug' => 'register_verify',
        'is_template_only' => true
    ],
    'email_verify' => [
        'label' => '会员登录验证码',
        'description' => '用户登录会员中心时，发送验证码邮件。变量：{code}, {email}, {site_name}',
        'fixed_slug' => 'email_verify',
        'is_template_only' => true
    ],
    'forgot_password' => [
        'label' => '找回密码验证码',
        'description' => '当用户申请找回密码时，发送验证码邮件。变量：{code}, {email}, {site_name}',
        'fixed_slug' => 'forgot_password_verify',
        'is_template_only' => true
    ],
    'bind_email' => [
        'label' => '绑定邮箱验证码',
        'description' => '当用户绑定邮箱时，发送验证码邮件。变量：{code}, {email}, {site_name}',
        'fixed_slug' => 'bind_email_verify',
        'is_template_only' => true
    ],
    'admin_login_verify' => [
        'label' => '后台登录验证码',
        'description' => '管理员登录时发送的双重验证验证码。变量：{code}, {email}, {site_name}',
        'fixed_slug' => 'admin_login_verify',
        'is_template_only' => true
    ],
    'profile_update' => [
        'label' => '个人资料',
        'description' => '向新用户发送账户信息邮件（新建用户时触发）。变量：{username}, {password}, {email}, {display_name}, {first_name}, {site_name}',
        'fixed_slug' => 'profile_update_verify',
        'is_template_only' => true
    ],
    'password_reset' => [
        'label' => '密码重置',
        'description' => '当用户重置密码成功后，向用户发送通知邮件。变量：{username}, {email}, {display_name}, {reset_time}, {site_name}',
        'fixed_slug' => 'password_reset_notify',
        'is_template_only' => true
    ],
    'order_payment' => [
        'label' => '订单付款',
        'description' => '当订单完成付款时，向管理员发送通知邮件。变量：{order_no}, {total_amount}, {buyer_name}, {buyer_email}, {payment_method}, {shipping_method}, {created_at}, {site_name}'
    ],
    'order_shipping' => [
        'label' => '订单发货',
        'description' => '当订单发货时，向管理员发送通知邮件'
    ],
    'comment' => [
        'label' => '新评论通知',
        'description' => '当有新的文章评论时，向管理员发送通知邮件。变量：{post_title}, {author_name}, {author_email}, {content}, {created_at}, {post_url}, {site_name}'
    ],
    'product_inquiry' => [
        'label' => '新询盘通知',
        'description' => '当有新的产品询盘时，向管理员发送通知邮件。变量：{name}, {email}, {content}, {product_title}, {product_id}, {product_url}, {site_name}',
        'fixed_slug' => 'product_inquiry'
    ],
    'guestbook' => [
        'label' => '新留言通知',
        'description' => '当有新的留言提交时，向管理员发送通知邮件。变量：{name}, {email}, {subject}, {message_content}, {created_at}, {site_name}',
        'fixed_slug' => 'guestbook'
    ],
    'new_subscription' => [
        'label' => '新订阅通知',
        'description' => '当有新的用户通过前台订阅时通知管理员。变量：{email}, {name}, {source}, {status}, {created_at}',
        'fixed_slug' => 'new_subscription'
    ],
    'admin_login_notify' => [
        'label' => '后台安全通知',
        'description' => '管理员成功登录后台的安全提醒。变量：{username}, {login_time}, {ip}, {user_agent}, {site_name}',
        'fixed_slug' => 'admin_login_notify'
    ]
];

$custom_categories_opt = CMS_Option::get('mail_template_custom_categories', []);
if (!is_array($custom_categories_opt)) {
    $custom_categories_opt = [];
}
if (!isset($custom_categories_opt['send']) || !is_array($custom_categories_opt['send'])) {
    $custom_categories_opt['send'] = [];
}
if (!isset($custom_categories_opt['notify']) || !is_array($custom_categories_opt['notify'])) {
    $custom_categories_opt['notify'] = [];
}
foreach (['send', 'notify'] as $g) {
    foreach ($custom_categories_opt[$g] as $ck => $cv) {
        $ck = todo_sanitize_key((string) $ck);
        if ($ck === '' || isset($notification_types[$ck])) {
            continue;
        }
        $label = is_array($cv) ? (string) ($cv['label'] ?? '') : (string) $cv;
        $label = trim($label);
        if ($label === '') {
            continue;
        }
        $notification_types[$ck] = [
            'label' => $label,
            'description' => '',
            'is_custom' => true,
            'template_group' => $g
        ];
    }
}

$message = '';
$error = '';

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 验证Nonce
    if (!isset($_POST['_todononce']) || !Todo_Security_Manager::verify_request_csrf('mail_settings_nonce', '_todononce')) {
        $error = '安全验证失败，请重试。';
    } else {
        // 保存模板
        if ($active_tab === 'templates' && $action === 'edit') {
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            $name = trim((string) ($_POST['name'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? ''));
            $slug = trim((string) ($_POST['slug'] ?? ''));
            if (!empty($category) && isset($notification_types[$category]['fixed_slug']) && !empty($notification_types[$category]['fixed_slug'])) {
                $slug = $notification_types[$category]['fixed_slug'];
            }
            $sender_name = trim((string) ($_POST['sender_name'] ?? ''));
            $subject = trim((string) ($_POST['subject'] ?? ''));
            $content = (string) ($_POST['content'] ?? '');
            if (function_exists('todo_convert_content_urls_to_relative')) {
                $content = todo_convert_content_urls_to_relative($content);
            }
            $preview_text = trim($_POST['preview_text'] ?? '');

            if (empty($slug) || empty($name) || empty($subject)) {
                $error = '请填写所有必填项。';
            } else {
                // 提前检查 slug 是否重复
                $existing = $tododb->get_var(
                    "SELECT id FROM {$tododb->prefix}email_templates WHERE slug = ? LIMIT 1",
                    [$slug]
                );

                $data = [
                    'slug'        => $slug,
                    'name'        => $name,
                    'category'    => $category,
                    'sender_name' => $sender_name,
                    'subject'     => $subject,
                    'content'     => $content,
                    'variables'   => $preview_text,
                    'updated_at'  => current_time('mysql')
                ];

                if ($id > 0) {
                    // 编辑：允许同一条记录保留原 slug；若其他记录已用此 slug 则报错
                    if ($existing && intval($existing) !== $id) {
                        $error = '标识（Slug）“<strong>' . esc_html($slug) . '</strong>”已被其他模板使用，请更换一个唯一的标识。';
                    } else {
                        $tododb->update($tododb->prefix . 'email_templates', $data, ['id' => $id]);
                        $message = '模板已更新。';
                    }
                } else {
                    // 新建：slug 不能已存在
                    if ($existing) {
                        $existing_name = $tododb->get_var(
                            "SELECT name FROM {$tododb->prefix}email_templates WHERE slug = ? LIMIT 1",
                            [$slug]
                        );
                        $error = '标识（Slug）“<strong>' . esc_html($slug) . '</strong>”已存在（模板：' . esc_html($existing_name) . '），请使用不同的标识，或直接 <a href="' . todo_admin_url('index.php?page=mail&tab=templates&action=edit&id=' . intval($existing)) . '">编辑该模板</a>。';
                    } else {
                        $data['created_at'] = current_time('mysql');
                        $new_id = $tododb->insert($tododb->prefix . 'email_templates', $data);
                        if ($new_id !== false && $new_id > 0) {
                            todo_admin_notice('模板已创建。', 'success');
                            todo_redirect(todo_admin_url("index.php?page=mail&tab=templates&action=edit&id={$new_id}"));
                            exit;
                        } else {
                            $error = '模板创建失败，请稍后重试。';
                        }
                    }
                }
            }

        }

        // 发送测试邮件
        if ($active_tab === 'send_test') {
            $to = trim($_POST['test_email']);
            $slug = trim($_POST['template_slug']);

            if (empty($to) || empty($slug)) {
                $error = '请填写收件人和选择模板。';
            } else {
                // 模拟数据
                $mock_data = ['username' => 'TestUser', 'code' => '123456'];
                if (todo_send_template_mail($to, $slug, $mock_data)) {
                    todo_admin_notice('测试邮件已发送。', 'success');
                } else {
                    todo_admin_notice('发送失败，请检查日志。', 'error');
                }
                todo_redirect(todo_admin_url('index.php?page=mail&tab=send_test'));
                exit;
            }
        }

        // 清空发送日志
        if ($active_tab === 'logs' && isset($_POST['clear_logs'])) {
            $table = $tododb->prefix . 'email_logs';
            $ok = $tododb->query("TRUNCATE TABLE {$table}") !== false;
            if ($ok) {
                todo_admin_notice('日志已清空。', 'success');
            } else {
                todo_admin_notice('清空失败，请稍后重试。', 'error');
            }
            todo_redirect(todo_admin_url('index.php?page=mail&tab=logs'));
            exit;
        }

        // 保存草稿
        if ($active_tab === 'drafts' && ($action === 'edit' || $action === 'new')) {
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            $sender_name = trim($_POST['sender_name'] ?? '');
            $recipient = trim($_POST['recipient']);
            $subject = trim($_POST['subject']);
            $content = $_POST['content'];
            $preview_text = trim($_POST['preview_text'] ?? '');

            if (isset($_POST['send_now'])) {
                // 立即发送
                $headers = ['Content-Type: text/html; charset=UTF-8'];
                $send_content = function_exists('todo_mail_inject_preview_text') ? todo_mail_inject_preview_text($content, $preview_text) : $content;
                $smtp_user = CMS_Option::get('mail_smtp_user');
                $resolved_sender = '';
                if (!empty($smtp_user) && function_exists('todo_resolve_sender_display_name')) {
                    $site_title = CMS_Option::get('site_title', 'TodoCMS');
                    $resolved_sender = todo_resolve_sender_display_name($sender_name, $site_title);
                    $resolved_sender = trim((string) $resolved_sender);
                    if ($resolved_sender !== '') {
                        $encoded_name = '=?UTF-8?B?' . base64_encode($resolved_sender) . '?=';
                        $headers[] = "From: $encoded_name <$smtp_user>";
                    }
                }
                $message_id = function_exists('todo_generate_message_id') ? (string) todo_generate_message_id() : '';
                if ($message_id !== '') {
                    $headers[] = 'Message-ID: ' . $message_id;
                }
                if (todo_mail($recipient, $subject, $send_content, $headers)) {
                    todo_log_email($recipient, $subject, 'sent', '', [
                        'content' => $send_content,
                        'type' => 'draft',
                        'direction' => 'out',
                        'from_email' => (string) ($smtp_user ?: ''),
                        'from_name' => (string) ($resolved_sender ?: ''),
                        'message_id' => $message_id,
                        'is_read' => 1,
                    ]);
                    todo_admin_notice('邮件已发送。', 'success');
                    /* if ($id > 0) {
                        todo_delete_email_draft($id); // 发送后删除草稿
                    } */
                    todo_redirect(todo_admin_url('index.php?page=mail&tab=drafts'));
                    exit;
                } else {
                    $error = '发送失败。';
                    todo_log_email($recipient, $subject, 'failed', 'Direct send failed');
                }
            } else {
                // 保存草稿
                $new_id = todo_save_email_draft($recipient, $subject, $content, $id, $preview_text, $sender_name);
                todo_admin_notice('草稿已保存。', 'success');
                todo_redirect(todo_admin_url("index.php?page=mail&tab=drafts&action=edit&id={$new_id}"));
                exit;
            }
        }

        if ($active_tab === 'conversation' && isset($_POST['send_reply'])) {
            $thread_key_post = trim((string) ($_POST['thread_key'] ?? ''));
            $inquiry_id_post = intval($_POST['inquiry_id'] ?? 0);
            if ($thread_key_post === '' && $inquiry_id_post > 0) {
                $thread_key_post = 'inquiry:' . $inquiry_id_post;
            }

            $reply_content = trim((string) ($_POST['reply_content'] ?? ''));
            if ($thread_key_post === '') {
                todo_admin_notice('无效的对话线程。', 'error');
                todo_redirect(todo_admin_url('index.php?page=mail&tab=logs'));
                exit;
            }
            if ($reply_content === '') {
                todo_admin_notice('请输入回复内容。', 'error');
                todo_redirect(todo_admin_url('index.php?page=mail&tab=conversation&thread=' . urlencode($thread_key_post)));
                exit;
            }

            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            if (!isset($_SESSION['todo_mail_send_tokens']) || !is_array($_SESSION['todo_mail_send_tokens'])) {
                $_SESSION['todo_mail_send_tokens'] = [];
            }
            $send_token = trim((string) ($_POST['send_token'] ?? ''));
            if ($send_token === '') {
                $send_token = hash('sha256', $thread_key_post . '|' . $reply_content . '|' . (string) floor(time() / 10));
            }
            $now_ts = time();
            foreach ($_SESSION['todo_mail_send_tokens'] as $tk => $exp) {
                if (!is_int($exp) && !is_numeric($exp)) {
                    unset($_SESSION['todo_mail_send_tokens'][$tk]);
                    continue;
                }
                if ($now_ts > intval($exp)) {
                    unset($_SESSION['todo_mail_send_tokens'][$tk]);
                }
            }
            if (isset($_SESSION['todo_mail_send_tokens'][$send_token]) && $now_ts <= intval($_SESSION['todo_mail_send_tokens'][$send_token])) {
                todo_admin_notice('正在发送中，请勿重复提交。', 'error');
                todo_redirect(todo_admin_url('index.php?page=mail&tab=conversation&thread=' . urlencode($thread_key_post)));
                exit;
            }
            $_SESSION['todo_mail_send_tokens'][$send_token] = $now_ts + 120;

            if (function_exists('todo_ensure_email_logs_schema')) {
                todo_ensure_email_logs_schema($tododb->prefix . 'email_logs', $tododb);
            }

            $last_any = $tododb->get_row(
                "SELECT * FROM {$tododb->prefix}email_logs WHERE thread_key = ? ORDER BY COALESCE(received_at, sent_at, created_at) DESC, id DESC LIMIT 1",
                [$thread_key_post]
            );
            $last_in = $tododb->get_row(
                "SELECT * FROM {$tododb->prefix}email_logs WHERE thread_key = ? AND direction = 'in' ORDER BY COALESCE(received_at, created_at) DESC, id DESC LIMIT 1",
                [$thread_key_post]
            );

            $to_email = '';
            if ($last_in && isset($last_in->from_email)) {
                $to_email = trim((string) $last_in->from_email);
            }
            $smtp_user_for_pick = (string) CMS_Option::get('mail_smtp_user', '');
            $smtp_user_for_pick = trim($smtp_user_for_pick);
            $is_email_fn = function_exists('is_email') ? 'is_email' : null;
            $pick_valid_email = function ($v) use ($is_email_fn, $smtp_user_for_pick) {
                $v = trim((string) $v);
                if ($v === '') return '';
                if ($smtp_user_for_pick !== '' && strcasecmp($v, $smtp_user_for_pick) === 0) return '';
                if ($is_email_fn && !$is_email_fn($v)) return '';
                return $v;
            };
            if ($to_email === '' && $last_any) {
                $dir_any = isset($last_any->direction) ? trim((string) $last_any->direction) : '';
                if ($dir_any === 'in') {
                    if (isset($last_any->from_email)) {
                        $to_email = $pick_valid_email($last_any->from_email);
                    }
                } elseif ($dir_any === 'out') {
                    if (isset($last_any->recipient)) {
                        $to_email = $pick_valid_email($last_any->recipient);
                    }
                    if ($to_email === '' && isset($last_any->to_email)) {
                        $to_email = $pick_valid_email($last_any->to_email);
                    }
                } else {
                    if (isset($last_any->recipient)) {
                        $to_email = $pick_valid_email($last_any->recipient);
                    }
                    if ($to_email === '' && isset($last_any->to_email)) {
                        $to_email = $pick_valid_email($last_any->to_email);
                    }
                    if ($to_email === '' && isset($last_any->from_email)) {
                        $to_email = $pick_valid_email($last_any->from_email);
                    }
                }
            }
            if ($to_email === '') {
                $recent = $tododb->get_results(
                    "SELECT direction, from_email, recipient, to_email FROM {$tododb->prefix}email_logs WHERE thread_key = ? ORDER BY COALESCE(received_at, sent_at, created_at) DESC, id DESC LIMIT 20",
                    [$thread_key_post]
                );
                if (is_array($recent) && !empty($recent)) {
                    foreach ($recent as $r) {
                        $dir = is_object($r) ? trim((string) ($r->direction ?? '')) : (is_array($r) ? trim((string) ($r['direction'] ?? '')) : '');
                        $from = is_object($r) ? (string) ($r->from_email ?? '') : (is_array($r) ? (string) ($r['from_email'] ?? '') : '');
                        $recip = is_object($r) ? (string) ($r->recipient ?? '') : (is_array($r) ? (string) ($r['recipient'] ?? '') : '');
                        $to = is_object($r) ? (string) ($r->to_email ?? '') : (is_array($r) ? (string) ($r['to_email'] ?? '') : '');
                        if ($dir === 'in') {
                            $to_email = $pick_valid_email($from);
                        } elseif ($dir === 'out') {
                            $to_email = $pick_valid_email($recip);
                            if ($to_email === '') {
                                $to_email = $pick_valid_email($to);
                            }
                        } else {
                            $to_email = $pick_valid_email($from);
                            if ($to_email === '') {
                                $to_email = $pick_valid_email($recip);
                            }
                            if ($to_email === '') {
                                $to_email = $pick_valid_email($to);
                            }
                        }
                        if ($to_email !== '') {
                            break;
                        }
                    }
                }
            }

            if ($to_email === '' && preg_match('/^inquiry:(\d+)$/', $thread_key_post, $mm)) {
                $inq_id = intval($mm[1]);
                if ($inq_id > 0) {
                    $comment_table = $tododb->prefix . 'comments';
                    $to_email = $pick_valid_email($tododb->get_var(
                        "SELECT comment_author_email FROM `{$comment_table}` WHERE comment_ID = ? LIMIT 1",
                        [$inq_id]
                    ));
                }
            }

            if ($to_email === '') {
                todo_admin_notice('无法确定收件人邮箱（请先确保对话中至少有一封“收”的邮件）。', 'error');
                todo_redirect(todo_admin_url('index.php?page=mail&tab=conversation&thread=' . urlencode($thread_key_post)));
                exit;
            }

            $base_subject = '';
            if ($last_any && isset($last_any->subject)) {
                $base_subject = trim((string) $last_any->subject);
            }
            if ($base_subject === '') {
                $base_subject = 'Re:';
            }
            if (!preg_match('/^\s*re\s*:/i', $base_subject)) {
                $base_subject = 'Re: ' . $base_subject;
            }

            $ref_src = $last_any ?: $last_in;
            $in_reply_to = '';
            $references = '';
            if ($ref_src) {
                if (isset($ref_src->message_id)) {
                    $in_reply_to = trim((string) $ref_src->message_id);
                }
                if ($in_reply_to === '' && isset($ref_src->in_reply_to)) {
                    $in_reply_to = trim((string) $ref_src->in_reply_to);
                }
                if (isset($ref_src->references)) {
                    $references = trim((string) $ref_src->references);
                }
            }
            if ($in_reply_to !== '' && function_exists('todo_imap_normalize_msgid')) {
                $in_reply_to = todo_imap_normalize_msgid($in_reply_to);
            }

            $refs = [];
            if ($references !== '') {
                $refs = preg_split('/\s+/', $references) ?: [];
            }
            if ($in_reply_to !== '') {
                $refs[] = $in_reply_to;
            }
            $refs = array_values(array_unique(array_filter(array_map('trim', $refs))));
            $references_line = !empty($refs) ? implode(' ', $refs) : '';

            $thread_index = '';
            $thread_topic = '';
            $conversation_index = '';
            $conversation_topic = '';
            if ($ref_src) {
                if (isset($ref_src->thread_index)) {
                    $thread_index = trim((string) $ref_src->thread_index);
                }
                if (isset($ref_src->thread_topic)) {
                    $thread_topic = trim((string) $ref_src->thread_topic);
                }
                if (isset($ref_src->conversation_index)) {
                    $conversation_index = trim((string) $ref_src->conversation_index);
                }
                if (isset($ref_src->conversation_topic)) {
                    $conversation_topic = trim((string) $ref_src->conversation_topic);
                }
            }
            if (($thread_index === '' || $conversation_index === '') && is_object($tododb)) {
                try {
                    $table = $tododb->prefix . 'email_logs';
                    if (preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                        $pick = $tododb->get_row(
                            "SELECT thread_index, thread_topic, conversation_index, conversation_topic FROM `{$table}` WHERE thread_key = ? AND (COALESCE(thread_index,'') <> '' OR COALESCE(conversation_index,'') <> '' OR COALESCE(thread_topic,'') <> '' OR COALESCE(conversation_topic,'') <> '') ORDER BY id DESC LIMIT 1",
                            [$thread_key_post]
                        );
                        if ($pick) {
                            if ($thread_index === '' && isset($pick->thread_index)) {
                                $thread_index = trim((string) $pick->thread_index);
                            }
                            if ($thread_topic === '' && isset($pick->thread_topic)) {
                                $thread_topic = trim((string) $pick->thread_topic);
                            }
                            if ($conversation_index === '' && isset($pick->conversation_index)) {
                                $conversation_index = trim((string) $pick->conversation_index);
                            }
                            if ($conversation_topic === '' && isset($pick->conversation_topic)) {
                                $conversation_topic = trim((string) $pick->conversation_topic);
                            }
                        }
                    }
                } catch (Throwable $e) {
                }
            }
            if ($thread_topic === '') {
                $topic = trim((string) $base_subject);
                $loop = 0;
                while ($loop < 6) {
                    $prev = $topic;
                    $topic = preg_replace('/^\s*(re|fw|fwd)\s*:\s*/i', '', $topic);
                    $topic = preg_replace('/^\s*(回复|答复)\s*:\s*/u', '', $topic);
                    $topic = trim((string) $topic);
                    if ($topic === $prev) {
                        break;
                    }
                    $loop++;
                }
                $thread_topic = $topic;
            }
            if ($conversation_topic === '') {
                $conversation_topic = $thread_topic;
            }
            if ($thread_index !== '' && function_exists('todo_outlook_thread_index_child')) {
                $thread_index = todo_outlook_thread_index_child($thread_index);
            } elseif ($thread_index === '' && function_exists('todo_outlook_thread_index_root')) {
                $thread_index = todo_outlook_thread_index_root();
            }
            if ($conversation_index !== '' && function_exists('todo_outlook_thread_index_child')) {
                $conversation_index = todo_outlook_thread_index_child($conversation_index);
            } elseif ($conversation_index === '') {
                $conversation_index = $thread_index;
            }

            $smtp_user = (string) CMS_Option::get('mail_smtp_user', '');
            $site_title = (string) CMS_Option::get('site_title', 'TodoCMS');
            $sender_display = $site_title;
            if (function_exists('todo_resolve_sender_display_name')) {
                $resolved = todo_resolve_sender_display_name('', $site_title);
                $resolved = trim((string) $resolved);
                if ($resolved !== '') {
                    $sender_display = $resolved;
                }
            }

            if ($smtp_user === '') {
                todo_admin_notice('SMTP 发件账号未设置：请先在“常规设置”里填写 SMTP 账号（通常就是发件邮箱）。', 'error');
                todo_redirect(todo_admin_url('index.php?page=mail&tab=general'));
                exit;
            }

            $headers = ['Content-Type: text/html; charset=UTF-8'];
            if ($smtp_user !== '') {
                $encoded_name = '=?UTF-8?B?' . base64_encode($sender_display) . '?=';
                $headers[] = "From: {$encoded_name} <{$smtp_user}>";
            }

            $message_id = function_exists('todo_generate_message_id') ? (string) todo_generate_message_id() : '';
            if ($message_id !== '') {
                $headers[] = 'Message-ID: ' . $message_id;
            }
            if ($in_reply_to !== '') {
                $headers[] = 'In-Reply-To: ' . $in_reply_to;
            }
            if ($references_line !== '') {
                $headers[] = 'References: ' . $references_line;
            }
            if ($thread_index !== '') {
                $headers[] = 'Thread-Index: ' . $thread_index;
            }
            if ($thread_topic !== '') {
                $headers[] = 'Thread-Topic: ' . $thread_topic;
            }
            if ($conversation_index !== '') {
                $headers[] = 'Conversation-Index: ' . $conversation_index;
            }
            if ($conversation_topic !== '') {
                $headers[] = 'Conversation-Topic: ' . $conversation_topic;
            }

            // 支持管理员在对话框中发送 HTML 内容或纯文本，并保留文本换行
            $is_html_content = (preg_match('/<[a-z][\s\S]*>/i', $reply_content) === 1);
            // 兜底修复：自动将 HTML 中的相对路径 (如 src="/content/uploads/...") 转换为包含本站域名与子目录的完整 URL
            if ($is_html_content) {
                $site_base_url = function_exists('todo_get_site_url') ? rtrim(todo_get_site_url(), '/') : '';
                if ($site_base_url !== '') {
                    $reply_content = preg_replace_callback('/(src|href)=(["\'])\/([^"\']+)\2/i', function ($m) use ($site_base_url) {
                        $attr = $m[1];
                        $quote = $m[2];
                        $path = '/' . $m[3];
                        // 避开已有子目录重合的二次拼接
                        $path_dir = parse_url($site_base_url, PHP_URL_PATH) ?? '';
                        if ($path_dir !== '' && strpos($path, $path_dir) === 0) {
                            $host = parse_url($site_base_url, PHP_URL_SCHEME) . '://' . parse_url($site_base_url, PHP_URL_HOST) . (parse_url($site_base_url, PHP_URL_PORT) ? (':' . parse_url($site_base_url, PHP_URL_PORT)) : '');
                            return $attr . '=' . $quote . $host . $path . $quote;
                        }
                        return $attr . '=' . $quote . $site_base_url . $path . $quote;
                    }, $reply_content);
                }
                // 如果包含 HTML 标签（如 <img>/<video>），将标签外的纯文本换行符转为 <br />
                $formatted_content = nl2br($reply_content);
                $html_body = '<div style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.7; color: #111;">' . $formatted_content . '</div>';
            } else {
                $safe_text = esc_html($reply_content);
                $html_body = '<div style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.7; color: #111; white-space: pre-wrap;">' . nl2br($safe_text) . '</div>';
            }

            $ok = function_exists('todo_mail') ? todo_mail($to_email, $base_subject, $html_body, $headers) : false;

            $related_type = '';
            $related_id = 0;
            if ($last_any) {
                $related_type = isset($last_any->related_type) ? (string) $last_any->related_type : '';
                $related_id = isset($last_any->related_id) ? intval($last_any->related_id) : 0;
            }
            if ($related_type === '' && preg_match('/^inquiry:(\d+)$/', $thread_key_post, $mm)) {
                $related_type = 'product_inquiry';
                $related_id = intval($mm[1]);
            }

            if (function_exists('todo_log_email')) {
                todo_log_email($to_email, $base_subject, $ok ? 'sent' : 'failed', $ok ? '' : 'Conversation reply send failed', [
                    'content' => $html_body,
                    'type' => 'reply',
                    'direction' => 'out',
                    'from_email' => $smtp_user,
                    'from_name' => $sender_display,
                    'message_id' => $message_id,
                    'in_reply_to' => $in_reply_to,
                    'references' => $references_line,
                    'thread_index' => $thread_index,
                    'thread_topic' => $thread_topic,
                    'conversation_index' => $conversation_index,
                    'conversation_topic' => $conversation_topic,
                    'thread_key' => $thread_key_post,
                    'related_type' => $related_type,
                    'related_id' => $related_id,
                    'is_read' => 1,
                ]);
            }

            if ($ok) {
                todo_admin_notice('已发送。', 'success');
            } else {
                todo_admin_notice('发送失败，请检查邮件日志。', 'error');
            }

            if ($inquiry_id_post > 0) {
                todo_redirect(todo_admin_url('index.php?page=mail&tab=conversation&inquiry_id=' . $inquiry_id_post));
                exit;
            }
            todo_redirect(todo_admin_url('index.php?page=mail&tab=conversation&thread=' . urlencode($thread_key_post)));
            exit;
        }

        // 保存常规设置
        if ($active_tab === 'general') {
            $smtp_host = trim($_POST['mail_smtp_host']);
            $smtp_port = intval($_POST['mail_smtp_port']);
            $smtp_user = trim($_POST['mail_smtp_user']);
            $smtp_pass = trim($_POST['mail_smtp_pass']);
            $admin_email = trim($_POST['mail_admin_email']);
            $notifications = isset($_POST['mail_notification_options']) ? $_POST['mail_notification_options'] : [];
            $imap_enabled = isset($_POST['mail_imap_enabled']) ? 1 : 0;
            $imap_host = trim((string) ($_POST['mail_imap_host'] ?? ''));
            $imap_port = intval($_POST['mail_imap_port'] ?? 993);
            $imap_user = trim((string) ($_POST['mail_imap_user'] ?? ''));
            $imap_pass = trim((string) ($_POST['mail_imap_pass'] ?? ''));
            $imap_mailbox = trim((string) ($_POST['mail_imap_mailbox'] ?? 'INBOX'));
            $imap_encryption = trim((string) ($_POST['mail_imap_encryption'] ?? 'ssl'));
            $imap_validate_cert = isset($_POST['mail_imap_validate_cert']) ? 1 : 0;
            $imap_test = isset($_POST['imap_test']) ? 1 : 0;
            $imap_sync_now = isset($_POST['imap_sync_now']) ? 1 : 0;

            $options = [
                'mail_smtp_host' => $smtp_host,
                'mail_smtp_port' => $smtp_port,
                'mail_smtp_user' => $smtp_user,
                'mail_admin_email' => $admin_email,
                'mail_notification_options' => $notifications,
                'mail_imap_enabled' => $imap_enabled,
                'mail_imap_host' => $imap_host,
                'mail_imap_port' => $imap_port,
                'mail_imap_user' => $imap_user,
                'mail_imap_mailbox' => $imap_mailbox,
                'mail_imap_encryption' => $imap_encryption,
                'mail_imap_validate_cert' => $imap_validate_cert,
            ];
            
            if (!empty($smtp_pass)) {
                $options['mail_smtp_pass'] = $smtp_pass;
            }
            if (!empty($imap_pass)) {
                $options['mail_imap_pass'] = $imap_pass;
            }
            
            CMS_Option::update_batch($options, 'mail');

            if (function_exists('todo_add_admin_notice')) {
                if ($imap_test !== 1 && $imap_sync_now !== 1) {
                    todo_add_admin_notice('设置已保存。', 'success');
                }
            }

            if ($imap_test === 1) {
                if (!function_exists('imap_open')) {
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice('IMAP 扩展未启用：请在 PHP 中安装/启用 imap 扩展后再测试。', 'error');
                    }
                } else {
                    $mailbox_str = function_exists('todo_imap_mailbox_string')
                        ? todo_imap_mailbox_string($imap_host, $imap_port, $imap_mailbox, $imap_encryption, $imap_validate_cert === 1)
                        : ('{' . $imap_host . ':' . $imap_port . '/imap' . (($imap_encryption === 'ssl' || $imap_port === 993) ? '/ssl' : ($imap_encryption === 'tls' ? '/tls' : '')) . ($imap_validate_cert === 1 ? '' : '/novalidate-cert') . '}' . ($imap_mailbox !== '' ? $imap_mailbox : 'INBOX'));

                    $imap = @imap_open($mailbox_str, $imap_user, $imap_pass, OP_READONLY);
                    if (!$imap) {
                        $err = @imap_last_error();
                        $err = is_string($err) && $err !== '' ? $err : '连接失败（请检查主机/端口/授权码/加密方式/目录名）';
                        if (function_exists('todo_add_admin_notice')) {
                            todo_add_admin_notice('IMAP 测试失败：' . (string) $err, 'error');
                        }
                    } else {
                        $count = 0;
                        $check = @imap_check($imap);
                        if ($check && isset($check->Nmsgs)) {
                            $count = intval($check->Nmsgs);
                        }
                        $root = preg_replace('/\}.*$/', '}', (string) $mailbox_str);
                        $boxes = @imap_list($imap, $root, '*');
                        $box_names = [];
                        if (is_array($boxes)) {
                            foreach ($boxes as $b) {
                                $b = (string) $b;
                                $box_names[] = preg_replace('/^.*\}/', '', $b);
                                if (count($box_names) >= 8) {
                                    break;
                                }
                            }
                        }
                        @imap_close($imap);
                        $more = !empty($box_names) ? (' 可用目录示例：' . implode(', ', $box_names)) : '';
                        if (function_exists('todo_add_admin_notice')) {
                            todo_add_admin_notice('IMAP 连接成功。当前目录邮件数：' . intval($count) . '。' . $more, 'success');
                        }
                    }
                }
            }

            if ($imap_sync_now === 1) {
                if (!function_exists('todo_run_imap_polling')) {
                    if (function_exists('todo_add_admin_notice')) {
                        todo_add_admin_notice('IMAP 同步不可用：缺少 todo_run_imap_polling。', 'error');
                    }
                } else {
                    CMS_Option::update('mail_imap_last_poll', 0, 'general');
                    $stats = todo_run_imap_polling(['force' => true, 'return_stats' => true]);
                    if (!is_array($stats)) {
                        $n = is_numeric($stats) ? intval($stats) : 0;
                        if (function_exists('todo_add_admin_notice')) {
                            todo_add_admin_notice('IMAP 已同步。本次新增入库邮件：' . $n . '。', 'success');
                        }
                    } else {
                        $ok = !empty($stats['ok']);
                        $reason = (string) ($stats['reason'] ?? '');
                        $err = trim((string) ($stats['error'] ?? ''));
                        $inserted = intval($stats['inserted'] ?? 0);
                        $skipped = intval($stats['skipped_existing'] ?? 0);
                        $processed = intval($stats['processed'] ?? 0);
                        $candidate = intval($stats['candidate_uids'] ?? 0);

                        if (!$ok) {
                            $msg = 'IMAP 同步未执行。';
                            if ($reason === 'imap_missing') {
                                $msg = 'IMAP 扩展未启用：请在 PHP 中安装/启用 imap 扩展后再同步。';
                            } elseif ($reason === 'config_missing') {
                                $msg = 'IMAP 配置不完整：请检查 IMAP 地址 / 账号 / 授权码。';
                            } elseif ($reason === 'connect_failed') {
                                $msg = 'IMAP 连接失败：' . ($err !== '' ? $err : '请检查主机/端口/授权码/加密方式/目录名');
                            } elseif ($reason === 'search_failed') {
                                $msg = 'IMAP 搜索失败：' . ($err !== '' ? $err : '请稍后重试或检查服务器日志');
                            }
                            if (function_exists('todo_add_admin_notice')) {
                                todo_add_admin_notice($msg, 'error');
                            }
                        } else {
                            if ($reason === 'no_new') {
                                if (function_exists('todo_add_admin_notice')) {
                                    todo_add_admin_notice('IMAP 已同步：没有发现新邮件。', 'success');
                                }
                            } else {
                                $msg = 'IMAP 已同步。本次新增入库邮件：' . $inserted . '。';
                                if ($candidate > 0) {
                                    $msg .= ' 本次候选：' . $candidate . '，实际处理：' . $processed . '，跳过重复：' . $skipped . '。';
                                }
                                if (function_exists('todo_add_admin_notice')) {
                                    todo_add_admin_notice($msg, 'success');
                                }
                            }
                        }
                    }
                }
            }
            
            // 使用 PRG 模式：重定向到同一页面，避免重复提交并刷新数据
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            todo_redirect(todo_admin_url('index.php?page=mail&tab=general'));
            exit;
        }
    }
}

// 处理删除操作
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $delete_action = (string) ($_POST['action'] ?? '');
} else {
    $delete_action = '';
}
if ($delete_action === 'delete') {
    $nonce = (string) ($_POST['_todononce'] ?? '');
    if (todo_verify_nonce($nonce, 'delete_mail_item')) {
        $id = intval($_POST['id'] ?? 0);
        if ($active_tab === 'templates') {
            $tododb->delete($tododb->prefix . 'email_templates', ['id' => $id]);
            todo_admin_notice('模板已删除。', 'success');
        } elseif ($active_tab === 'drafts') {
            $tododb->delete($tododb->prefix . 'email_drafts', ['id' => $id]);
            todo_admin_notice('草稿已删除。', 'success');
        }
        todo_redirect(todo_admin_url("index.php?page=mail&tab={$active_tab}"));
        exit;
    } else {
        todo_admin_notice('安全验证失败，请重试。', 'error');
        todo_redirect(todo_admin_url("index.php?page=mail&tab={$active_tab}"));
        exit;
    }
}

// 批量删除
if ($delete_action === 'batch_delete') {
    $nonce = (string) ($_POST['_todononce'] ?? '');
    if (!todo_verify_nonce($nonce, 'batch_delete_mail_items')) {
        todo_admin_notice('安全验证失败，请重试。', 'error');
        todo_redirect(todo_admin_url("index.php?page=mail&tab={$active_tab}"));
        exit;
    }
    $raw_ids = isset($_POST['item_ids']) ? $_POST['item_ids'] : [];
    if (!is_array($raw_ids) || empty($raw_ids)) {
        todo_admin_notice('请至少选择一项。', 'warning');
        todo_redirect(todo_admin_url("index.php?page=mail&tab={$active_tab}"));
        exit;
    }
    $ids = array_filter(array_map('intval', $raw_ids), function($v) { return $v > 0; });
    $deleted = 0;
    if (!empty($ids)) {
        foreach ($ids as $item_id) {
            if ($active_tab === 'templates') {
                $ok = $tododb->delete($tododb->prefix . 'email_templates', ['id' => $item_id]);
            } else {
                $ok = $tododb->delete($tododb->prefix . 'email_drafts', ['id' => $item_id]);
            }
            if ($ok !== false) $deleted++;
        }
    }
    todo_admin_notice("已删除 {$deleted} 项。", 'success');
    todo_redirect(todo_admin_url("index.php?page=mail&tab={$active_tab}"));
    exit;
}

if ($delete_action === 'copy' && $active_tab === 'templates') {
    $nonce = (string) ($_POST['_todononce'] ?? '');
    if (!todo_verify_nonce($nonce, 'copy_mail_template')) {
        todo_admin_notice('安全验证失败，请重试。', 'error');
        todo_redirect(todo_admin_url("index.php?page=mail&tab={$active_tab}"));
        exit;
    }

    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        todo_admin_notice('无效的模板ID。', 'error');
        todo_redirect(todo_admin_url("index.php?page=mail&tab={$active_tab}"));
        exit;
    }

    $tpl = $tododb->get_row("SELECT * FROM {$tododb->prefix}email_templates WHERE id = ? LIMIT 1", [$id], 'ARRAY_A');
    if (!$tpl || !is_array($tpl)) {
        todo_admin_notice('模板不存在或已被删除。', 'error');
        todo_redirect(todo_admin_url("index.php?page=mail&tab={$active_tab}"));
        exit;
    }

    $base_slug = trim((string) ($tpl['slug'] ?? ''));
    if ($base_slug === '') {
        $base_slug = 'template';
    }
    $new_slug = $base_slug . '-copy';
    $idx = 2;
    while (true) {
        $exists = $tododb->get_var(
            "SELECT id FROM {$tododb->prefix}email_templates WHERE slug = ? LIMIT 1",
            [$new_slug]
        );
        if (!$exists) {
            break;
        }
        $new_slug = $base_slug . '-copy-' . $idx;
        $idx++;
    }

    $new_name = '副本_' . trim((string) ($tpl['name'] ?? ''));
    $now = current_time('mysql');

    $data = $tpl;
    unset($data['id']);
    $data['slug'] = $new_slug;
    $data['name'] = $new_name;
    $data['created_at'] = $now;
    $data['updated_at'] = $now;

    $new_id = $tododb->insert($tododb->prefix . 'email_templates', $data);
    if ($new_id !== false && intval($new_id) > 0) {
        todo_admin_notice('模板已复制。', 'success');
        todo_redirect(todo_admin_url('index.php?page=mail&tab=templates&action=edit&id=' . intval($new_id)));
        exit;
    }

    todo_admin_notice('复制失败，请稍后重试。', 'error');
    todo_redirect(todo_admin_url("index.php?page=mail&tab={$active_tab}"));
    exit;
}

// 添加模板分类
if (($active_tab === 'templates') && (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')) {
    $category_action = (string) ($_POST['action'] ?? '');
} else {
    $category_action = '';
}

if ($active_tab === 'templates' && $category_action === 'add_category') {
    $nonce = $_POST['_todononce'] ?? '';
    if (!todo_verify_nonce($nonce, 'add_mail_template_category')) {
        todo_admin_notice('安全验证失败，请重试。', 'error');
        todo_redirect(todo_admin_url('index.php?page=mail&tab=templates'));
        exit;
    }

    $group = strtolower(trim((string) ($_POST['group'] ?? '')));
    $group = in_array($group, ['send', 'notify'], true) ? $group : 'send';
    $label = trim((string) ($_POST['label'] ?? ''));
    if ($label === '') {
        todo_admin_notice('请输入分类名称。', 'error');
        todo_redirect(todo_admin_url('index.php?page=mail&tab=templates'));
        exit;
    }

    $opt = CMS_Option::get('mail_template_custom_categories', []);
    if (!is_array($opt)) {
        $opt = [];
    }
    if (!isset($opt['send']) || !is_array($opt['send'])) {
        $opt['send'] = [];
    }
    if (!isset($opt['notify']) || !is_array($opt['notify'])) {
        $opt['notify'] = [];
    }

    $label_norm = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
    foreach ($notification_types as $k => $info) {
        if (!is_array($info) || empty($info['label'])) {
            continue;
        }
        if (($info['template_group'] ?? null) !== null) {
            if ((string) ($info['template_group'] ?? '') !== $group) {
                continue;
            }
        } else {
            if ($group === 'send' && empty($info['is_template_only'])) {
                continue;
            }
            if ($group === 'notify' && !empty($info['is_template_only'])) {
                continue;
            }
        }
        $cmp = trim((string) $info['label']);
        $cmp = function_exists('mb_strtolower') ? mb_strtolower($cmp, 'UTF-8') : strtolower($cmp);
        if ($cmp === $label_norm) {
            todo_admin_notice('分类名称已存在，请换一个。', 'error');
            todo_redirect(todo_admin_url('index.php?page=mail&tab=templates'));
            exit;
        }
    }

    $base = todo_sanitize_key($label);
    if ($base === '') {
        $base = 'custom';
    }
    $new_key = $group . '_' . $base;
    $idx = 2;
    while (isset($notification_types[$new_key]) || isset($opt['send'][$new_key]) || isset($opt['notify'][$new_key])) {
        $new_key = $group . '_' . $base . '_' . $idx;
        $idx++;
    }

    $opt[$group][$new_key] = [
        'label' => $label
    ];
    CMS_Option::update('mail_template_custom_categories', $opt, 'mail');

    todo_redirect(todo_admin_url('index.php?page=mail&tab=templates&category=' . urlencode($new_key)));
    exit;
}

// 删除模板分类（仅自定义分类）
if ($active_tab === 'templates' && $category_action === 'delete_category') {
    $nonce = $_POST['_todononce'] ?? '';
    if (!todo_verify_nonce($nonce, 'delete_mail_template_category')) {
        todo_admin_notice('安全验证失败，请重试。', 'error');
        todo_redirect(todo_admin_url('index.php?page=mail&tab=templates'));
        exit;
    }

    $group = strtolower(trim((string) ($_POST['group'] ?? '')));
    $group = in_array($group, ['send', 'notify'], true) ? $group : 'send';
    $key = todo_sanitize_key((string) ($_POST['key'] ?? ''));
    if ($key === '') {
        todo_admin_notice('无效的分类。', 'error');
        todo_redirect(todo_admin_url('index.php?page=mail&tab=templates'));
        exit;
    }

    $opt = CMS_Option::get('mail_template_custom_categories', []);
    if (!is_array($opt) || !isset($opt[$group]) || !is_array($opt[$group]) || !isset($opt[$group][$key])) {
        todo_admin_notice('分类不存在或不可删除。', 'error');
        todo_redirect(todo_admin_url('index.php?page=mail&tab=templates'));
        exit;
    }

    unset($opt[$group][$key]);
    CMS_Option::update('mail_template_custom_categories', $opt, 'mail');

    $tododb->update($tododb->prefix . 'email_templates', ['category' => ''], ['category' => $key]);

    $active_category = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
    if ($active_category === $key) {
        todo_redirect(todo_admin_url('index.php?page=mail&tab=templates'));
        exit;
    }
    todo_redirect(todo_admin_url('index.php?page=mail&tab=templates&category=' . urlencode($active_category)));
    exit;
}

// 处理草稿立即发送
if ($active_tab === 'drafts') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $draft_action = (string) ($_POST['action'] ?? '');
    } else {
        $draft_action = '';
    }
} else {
    $draft_action = '';
}
if ($active_tab === 'drafts' && $draft_action === 'send') {
    $nonce = (string) ($_POST['_todononce'] ?? '');
    if (!todo_verify_nonce($nonce, 'send_mail_draft')) {
        todo_admin_notice('安全验证失败，请重试。', 'error');
        todo_redirect(todo_admin_url('index.php?page=mail&tab=drafts'));
        exit;
    }

    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        todo_admin_notice('无效的草稿ID。', 'error');
        todo_redirect(todo_admin_url('index.php?page=mail&tab=drafts'));
        exit;
    }

    $draft = $tododb->get_row("SELECT * FROM {$tododb->prefix}email_drafts WHERE id = ?", [$id]);
    if (!$draft) {
        todo_admin_notice('草稿不存在或已被删除。', 'error');
        todo_redirect(todo_admin_url('index.php?page=mail&tab=drafts'));
        exit;
    }

    $recipient = trim((string) ($draft->recipient ?? ''));
    $subject = trim((string) ($draft->subject ?? ''));
    $content = (string) ($draft->content ?? '');
    $preview_text = trim((string) ($draft->preview_text ?? ''));
    $sender_name = trim((string) ($draft->sender_name ?? ''));

    if ($recipient === '' || $subject === '' || $content === '') {
        todo_admin_notice('草稿缺少必要信息（收件人/主题/内容）。', 'error');
        todo_redirect(todo_admin_url('index.php?page=mail&tab=drafts&action=edit&id=' . $id));
        exit;
    }

    $headers = ['Content-Type: text/html; charset=UTF-8'];
    $send_content = function_exists('todo_mail_inject_preview_text') ? todo_mail_inject_preview_text($content, $preview_text) : $content;
    $smtp_user = CMS_Option::get('mail_smtp_user');
    $resolved_sender = '';
    if (!empty($smtp_user) && function_exists('todo_resolve_sender_display_name')) {
        $site_title = CMS_Option::get('site_title', 'TodoCMS');
        $resolved_sender = todo_resolve_sender_display_name($sender_name, $site_title);
        $resolved_sender = trim((string) $resolved_sender);
        if ($resolved_sender !== '') {
            $encoded_name = '=?UTF-8?B?' . base64_encode($resolved_sender) . '?=';
            $headers[] = "From: $encoded_name <$smtp_user>";
        }
    }
    $message_id = function_exists('todo_generate_message_id') ? (string) todo_generate_message_id() : '';
    if ($message_id !== '') {
        $headers[] = 'Message-ID: ' . $message_id;
    }
    $ok = todo_mail($recipient, $subject, $send_content, $headers);
    if ($ok) {
        if (function_exists('todo_log_email')) {
            todo_log_email($recipient, $subject, 'sent', '', [
                'content' => $send_content,
                'type' => 'draft',
                'direction' => 'out',
                'from_email' => (string) ($smtp_user ?: ''),
                'from_name' => (string) ($resolved_sender ?: ''),
                'message_id' => $message_id,
                'is_read' => 1,
            ]);
        }
        /* if (function_exists('todo_delete_email_draft')) {
            todo_delete_email_draft($id);
        } else {
            $tododb->delete($tododb->prefix . 'email_drafts', ['id' => $id]);
        } */
        todo_admin_notice('邮件已发送。', 'success');
        todo_redirect(todo_admin_url('index.php?page=mail&tab=drafts'));
        exit;
    }

    if (function_exists('todo_log_email')) {
        todo_log_email($recipient, $subject, 'failed', 'Draft send failed');
    }
    todo_admin_notice('发送失败，请检查邮件日志。', 'error');
    todo_redirect(todo_admin_url('index.php?page=mail&tab=drafts&action=edit&id=' . $id));
    exit;
}

// 获取数据列表
if ($active_tab === 'templates' && $action === 'list') {
    $templates = $tododb->get_results("SELECT * FROM {$tododb->prefix}email_templates ORDER BY id DESC", []);
} elseif ($active_tab === 'conversation') {
    $thread_key = isset($_GET['thread']) ? trim((string) $_GET['thread']) : '';
    $inquiry_id = intval($_GET['inquiry_id'] ?? 0);
    $log_id = intval($_GET['log_id'] ?? 0);

    if ($thread_key === '' && $inquiry_id > 0) {
        $thread_key = 'inquiry:' . $inquiry_id;
    }

    if ($thread_key === '' && $log_id > 0) {
        $maybe = $tododb->get_row("SELECT * FROM {$tododb->prefix}email_logs WHERE id = ? LIMIT 1", [$log_id]);
        if ($maybe) {
            $thread_key = (string) ($maybe->thread_key ?? '');
            if ($thread_key === '' && !empty($maybe->related_type) && !empty($maybe->related_id)) {
                if ((string) $maybe->related_type === 'product_inquiry') {
                    $thread_key = 'inquiry:' . intval($maybe->related_id);
                }
            }
        }
    }

    if (function_exists('todo_ensure_email_logs_schema')) {
        todo_ensure_email_logs_schema($tododb->prefix . 'email_logs', $tododb);
    }

    $thread_messages = [];
    if ($thread_key !== '') {
        if (function_exists('todo_run_imap_polling')) {
            todo_run_imap_polling();
        }
        $thread_messages = $tododb->get_results(
            "SELECT * FROM {$tododb->prefix}email_logs WHERE thread_key = ? ORDER BY COALESCE(received_at, sent_at, created_at) ASC, id ASC",
            [$thread_key]
        );
        $tododb->query("UPDATE {$tododb->prefix}email_logs SET is_read = 1 WHERE thread_key = ? AND direction = 'in' AND is_read = 0", [$thread_key]);
    }

    $thread_inquiry = null;
    $thread_inquiry_id = 0;
    if (preg_match('/^inquiry:(\d+)$/', $thread_key, $m)) {
        $thread_inquiry_id = intval($m[1]);
    }
    if ($thread_inquiry_id > 0 && function_exists('todo_get_comment')) {
        $thread_inquiry = todo_get_comment($thread_inquiry_id);
    }
} elseif ($active_tab === 'logs') {
    $page_num = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
    $per_page = 20;
    $offset = ($page_num - 1) * $per_page;
    $logs_search = trim((string) ($_GET['search'] ?? ''));
    $logs_status = trim((string) ($_GET['status'] ?? ''));
    $logs_direction = trim((string) ($_GET['direction'] ?? ''));
    $logs_range = trim((string) ($_GET['range'] ?? ''));
    $logs_date_from = trim((string) ($_GET['date_from'] ?? ''));
    $logs_date_to = trim((string) ($_GET['date_to'] ?? ''));

    $logs_orderby = trim((string) ($_GET['orderby'] ?? 'time'));
    $logs_order = strtolower(trim((string) ($_GET['order'] ?? 'desc'))) === 'asc' ? 'ASC' : 'DESC';
    $allowed_orderby = ['id', 'time'];
    if (!in_array($logs_orderby, $allowed_orderby, true)) {
        $logs_orderby = 'time';
    }

    if (function_exists('todo_run_imap_polling')) {
        todo_run_imap_polling();
    }
    if (function_exists('todo_ensure_email_logs_schema')) {
        todo_ensure_email_logs_schema($tododb->prefix . 'email_logs', $tododb);
    }
    $table = $tododb->prefix . 'email_logs';
    $email_log_cols = function_exists('todo_ensure_email_logs_schema') ? todo_ensure_email_logs_schema($table, $tododb) : [];
    $email_log_cols = is_array($email_log_cols) ? $email_log_cols : [];
    $email_log_colset = [];
    foreach ($email_log_cols as $c) {
        $c = (string) $c;
        if ($c !== '') $email_log_colset[$c] = true;
    }
    $time_expr = "COALESCE(received_at, sent_at, created_at)";
    $where = [];
    $params = [];
    if ($logs_search !== '') {
        $like = '%' . $logs_search . '%';
        $search_conds = [];
        if (isset($email_log_colset['subject'])) {
            $search_conds[] = "COALESCE(subject,'') LIKE ?";
            $params[] = $like;
        }
        if (isset($email_log_colset['recipient'])) {
            $search_conds[] = "COALESCE(recipient,'') LIKE ?";
            $params[] = $like;
        }
        if (isset($email_log_colset['to_email'])) {
            $search_conds[] = "COALESCE(to_email,'') LIKE ?";
            $params[] = $like;
        }
        if (isset($email_log_colset['from_email'])) {
            $search_conds[] = "COALESCE(from_email,'') LIKE ?";
            $params[] = $like;
        }
        if (isset($email_log_colset['error_message'])) {
            $search_conds[] = "COALESCE(error_message,'') LIKE ?";
            $params[] = $like;
        }
        if (empty($search_conds)) {
            $search_conds[] = "COALESCE(subject,'') LIKE ?";
            $params[] = $like;
        }
        $where[] = '(' . implode(' OR ', $search_conds) . ')';
    }
    if ($logs_status !== '' && in_array($logs_status, ['sent', 'failed', 'received'], true) && isset($email_log_colset['status'])) {
        $where[] = "status = ?";
        $params[] = $logs_status;
    }
    if ($logs_direction !== '' && in_array($logs_direction, ['in', 'out'], true) && isset($email_log_colset['direction'])) {
        $where[] = "direction = ?";
        $params[] = $logs_direction;
    }

    $date_from = '';
    $date_to = '';
    if ($logs_date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $logs_date_from) === 1) {
        $date_from = $logs_date_from . ' 00:00:00';
    }
    if ($logs_date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $logs_date_to) === 1) {
        $date_to = $logs_date_to . ' 23:59:59';
    }
    if ($date_from === '' && $date_to === '' && $logs_range !== '') {
        $now = function_exists('current_time') ? (string) current_time('mysql') : date('Y-m-d H:i:s');
        $now_ts = strtotime($now);
        if ($now_ts === false) {
            $now_ts = time();
        }
        if ($logs_range === 'today') {
            $date_from = date('Y-m-d 00:00:00', $now_ts);
        } elseif ($logs_range === '7d') {
            $date_from = date('Y-m-d H:i:s', $now_ts - 7 * 86400);
        } elseif ($logs_range === '30d') {
            $date_from = date('Y-m-d H:i:s', $now_ts - 30 * 86400);
        } elseif ($logs_range === '90d') {
            $date_from = date('Y-m-d H:i:s', $now_ts - 90 * 86400);
        }
    }
    if ($date_from !== '') {
        $where[] = "{$time_expr} >= ?";
        $params[] = $date_from;
    }
    if ($date_to !== '') {
        $where[] = "{$time_expr} <= ?";
        $params[] = $date_to;
    }

    $where_sql = !empty($where) ? (' WHERE ' . implode(' AND ', $where)) : '';
    $order_by_sql = $time_expr;
    if ($logs_orderby === 'id') {
        $order_by_sql = 'id';
    }

    $total_logs = intval($tododb->get_var("SELECT COUNT(*) FROM {$table}{$where_sql}", $params));
    $list_params = $params;
    $list_params[] = $per_page;
    $list_params[] = $offset;
    $logs = $tododb->get_results("SELECT * FROM {$table}{$where_sql} ORDER BY {$order_by_sql} {$logs_order}, id DESC LIMIT ? OFFSET ?", $list_params);
    $total_pages = ceil($total_logs / $per_page);
} elseif ($active_tab === 'drafts' && $action === 'list') {
    $drafts = $tododb->get_results("SELECT * FROM {$tododb->prefix}email_drafts ORDER BY updated_at DESC");
}

?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title verse">“寄书常不达，况乃未休兵。”<span>——杜甫</span></h2>
        <div class="admin-page-actions">
            <?php if ($active_tab === 'templates' && $action === 'list'): ?>
                <a href="<?php echo todo_admin_url('?page=mail&tab=templates&action=edit'); ?>" class="button button-primary">新建模板</a>
            <?php elseif ($active_tab === 'templates' && $action === 'edit'): ?>
                <a href="<?php echo todo_admin_url('?page=mail&tab=templates'); ?>" class="button button-secondary">返回列表</a>
            <?php elseif ($active_tab === 'drafts' && $action === 'list'): ?>
                <a href="<?php echo todo_admin_url('?page=mail&tab=drafts&action=new'); ?>" class="button button-primary">写邮件</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html($message); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo $error; ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <ul class="admin-tabs">
        <li>
            <a href="<?php echo todo_admin_url('?page=mail&tab=general'); ?>" class="<?php echo $active_tab === 'general' ? 'active' : ''; ?>">
                常规设置
            </a>
        </li>
        <li>
            <a href="<?php echo todo_admin_url('?page=mail&tab=templates'); ?>" class="<?php echo $active_tab === 'templates' ? 'active' : ''; ?>">
                模板管理
            </a>
        </li>
        <li>
            <a href="<?php echo todo_admin_url('?page=mail&tab=drafts'); ?>" class="<?php echo $active_tab === 'drafts' ? 'active' : ''; ?>">
                草稿箱
            </a>
        </li>
        <li>
            <a href="<?php echo todo_admin_url('?page=mail&tab=logs'); ?>" class="<?php echo $active_tab === 'logs' ? 'active' : ''; ?>">
                发送日志
            </a>
        </li>
        <li>
            <a href="<?php echo todo_admin_url('?page=mail&tab=send_test'); ?>" class="<?php echo $active_tab === 'send_test' ? 'active' : ''; ?>">
                发送测试
            </a>
        </li>
    </ul>

    <!-- 常规设置 -->
    <?php if ($active_tab === 'general'):
        $smtp_host = CMS_Option::get('mail_smtp_host');
        $smtp_port = CMS_Option::get('mail_smtp_port', 465);
        $smtp_user = CMS_Option::get('mail_smtp_user');
        $smtp_pass = CMS_Option::get('mail_smtp_pass');
        $admin_email = CMS_Option::get('mail_admin_email');
        $notifications = CMS_Option::get('mail_notification_options', []);
        $imap_enabled = (int) CMS_Option::get('mail_imap_enabled', 0);
        $imap_host = (string) CMS_Option::get('mail_imap_host', '');
        $imap_port = (int) CMS_Option::get('mail_imap_port', 993);
        $imap_user = (string) CMS_Option::get('mail_imap_user', '');
        $imap_pass = (string) CMS_Option::get('mail_imap_pass', '');
        $imap_mailbox = (string) CMS_Option::get('mail_imap_mailbox', 'INBOX');
        $imap_encryption = (string) CMS_Option::get('mail_imap_encryption', 'ssl');
        $imap_validate_cert = (int) CMS_Option::get('mail_imap_validate_cert', 0);
        ?>
        <form method="post" class="admin-settings-form todo-request" autocomplete="off">
            <input type="hidden" name="_todononce" value="<?php echo todo_create_nonce('mail_settings_nonce'); ?>">

            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">SMTP 设置</h3>
                </div>
                <div class="admin-card-content">
                    <div class="form-group">
                        <label for="mail_smtp_host">SMTP 地址</label>
                        <input type="text" name="mail_smtp_host" id="mail_smtp_host"
                            value="<?php echo esc_attr($smtp_host); ?>" class="regular-text">
                        <p class="description">发送邮箱的smtp地址。如: smtp.qq.com或smtp.gmail.com</p>
                    </div>

                    <div class="form-group">
                        <label for="mail_smtp_port">SMTP 端口</label>
                        <input type="number" name="mail_smtp_port" id="mail_smtp_port"
                            value="<?php echo esc_attr($smtp_port); ?>" class="small-text">
                        <p class="description">注意：大部分默认为465，具体请参看各SMTP服务商的设置说明，极少部分是25或其他端口号。</p>
                    </div>

                    <div class="form-group">
                        <label for="mail_smtp_user">发信邮箱账号</label>
                        <input type="email" name="mail_smtp_user" id="mail_smtp_user"
                            value="<?php echo esc_attr($smtp_user); ?>" class="regular-text" autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label for="mail_smtp_pass">邮箱授权码</label>
                        <input type="text" name="mail_smtp_pass" id="mail_smtp_pass"
                            value="<?php echo esc_attr($smtp_pass); ?>" class="regular-text" autocomplete="off">
                        <p class="description">使用发送邮件的邮箱授权码。具体请查看邮件服务商的说明。</p>
                    </div>
                </div>
            </div>

            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">管理员设置</h3>
                </div>
                <div class="admin-card-content">
                    <div class="form-group">
                        <label for="mail_admin_email">管理员邮箱</label>
                        <input type="text" name="mail_admin_email" id="mail_admin_email"
                            value="<?php echo esc_attr($admin_email); ?>" class="large-text w-100">
                        <p class="description">多个邮箱可以用逗号隔开。当启用接收提醒选项时，系统会向这些邮箱发送通知邮件。</p>
                    </div>

                    <div class="form-group">
                        <label>接收提醒选项</label>
                        <p class="description" style="margin-bottom: 10px;">
                            选择您希望接收哪些类型的邮件通知。取消勾选后，系统将不再向管理员邮箱发送该类型的通知邮件，但相关业务功能（如留言、注册等）仍可正常使用。</p>
                        <fieldset>
                            <?php foreach ($notification_types as $key => $info): ?>
                                <?php if (!empty($info['is_template_only'])) continue; ?>
                                <?php if (!empty($info['is_custom'])) continue; ?>
                                <div style="margin-bottom: 10px;">
                                    <label style="display: flex; align-items: baseline;">
                                        <input type="checkbox" name="mail_notification_options[]"
                                            value="<?php echo esc_attr($key); ?>" <?php echo in_array($key, $notifications) ? 'checked' : ''; ?> style="margin-right: 8px;">
                                        <div>
                                            <strong><?php echo esc_html($info['label']); ?></strong><span class="description"><?php echo (empty($info['is_template_only']) ? '(通知管理员)' : ''); ?></span>
                                            <p class="description">
                                                <?php echo esc_html($info['description']); ?></p>
                                        </div>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </fieldset>
                    </div>
                </div>
            </div>

            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">IMAP 轮询监听</h3>
                    <div class="admin-card-actions">
                        <button type="submit" name="imap_test" value="1" class="button button-secondary m-r-10">测试 IMAP 连接</button>
                        <button type="submit" name="imap_sync_now" value="1" class="button button-secondary">立即同步一次</button>
                    </div>
                </div>
                <div class="admin-card-content">
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="mail_imap_enabled" value="1" <?php echo $imap_enabled === 1 ? 'checked' : ''; ?>>
                            启用 IMAP 拉取（每 5 分钟触发一次）
                        </label>
                    </div>

                    <div class="form-group">
                        <label for="mail_imap_host">IMAP 地址</label>
                        <input type="text" name="mail_imap_host" id="mail_imap_host" value="<?php echo esc_attr($imap_host); ?>" class="regular-text">
                    </div>

                    <div class="form-group">
                        <label for="mail_imap_port">IMAP 端口</label>
                        <input type="number" name="mail_imap_port" id="mail_imap_port" value="<?php echo esc_attr($imap_port); ?>" class="small-text">
                    </div>

                    <div class="form-group">
                        <label for="mail_imap_encryption">加密方式</label>
                        <select name="mail_imap_encryption" id="mail_imap_encryption" class="regular-select">
                            <option value="ssl" <?php echo $imap_encryption === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                            <option value="tls" <?php echo $imap_encryption === 'tls' ? 'selected' : ''; ?>>TLS</option>
                            <option value="none" <?php echo $imap_encryption === 'none' ? 'selected' : ''; ?>>无</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="mail_imap_user">IMAP 账号</label>
                        <input type="text" name="mail_imap_user" id="mail_imap_user" value="<?php echo esc_attr($imap_user); ?>" class="regular-text" autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label for="mail_imap_pass">IMAP 授权码</label>
                        <input type="text" name="mail_imap_pass" id="mail_imap_pass" value="<?php echo esc_attr($imap_pass); ?>" class="regular-text" autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label for="mail_imap_mailbox">监听邮箱目录</label>
                        <input type="text" name="mail_imap_mailbox" id="mail_imap_mailbox" value="<?php echo esc_attr($imap_mailbox); ?>" class="regular-text">
                        <p class="description">常见：INBOX。不同服务商可能是 INBOX 或其他目录名。</p>
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="mail_imap_validate_cert" value="1" <?php echo $imap_validate_cert === 1 ? 'checked' : ''; ?>>
                            校验证书
                        </label>
                        <p class="description">勾选：校验服务器 SSL 证书（更安全）。不勾选：使用 /novalidate-cert，适合证书链校验失败的环境（不推荐长期关闭）。</p>
                    </div>
                </div>
            </div>

            <div class="admin-form-submit">
                <button type="submit" class="button button-primary">保存设置</button>
            </div>
        </form>

        <!-- 模板管理 -->
    <?php elseif ($active_tab === 'templates'): ?>
        <?php if ($action === 'list'): ?>
            <div class="admin-card">
                <div class="admin-card-content">
                    <div class="admin-filter-bar m-0">
                        <?php
                        $active_category = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
                        $category_counts = [];
                        if (is_array($templates)) {
                            foreach ($templates as $t) {
                                if (!is_object($t)) continue;
                                $ck = (string) ($t->category ?? '');
                                if (!isset($category_counts[$ck])) $category_counts[$ck] = 0;
                                $category_counts[$ck]++;
                            }
                        }

                        $group_of = function ($key, $info) {
                            if (is_array($info) && isset($info['template_group'])) {
                                $g = strtolower(trim((string) $info['template_group']));
                                if (in_array($g, ['send', 'notify'], true)) {
                                    return $g;
                                }
                            }
                            if (!empty($info['is_template_only'])) {
                                return 'send';
                            }
                            return 'notify';
                        };

                        $send_categories = [];
                        $notify_categories = [];
                        foreach ($notification_types as $k => $info) {
                            $g = $group_of($k, $info);
                            if ($g === 'send') {
                                $send_categories[$k] = $info;
                            } else {
                                $notify_categories[$k] = $info;
                            }
                        }

                        $send_add_url = todo_admin_url('index.php?page=mail&tab=templates&action=add_category&group=send&_todononce=' . todo_create_nonce('add_mail_template_category'));
                        $notify_add_url = todo_admin_url('index.php?page=mail&tab=templates&action=add_category&group=notify&_todononce=' . todo_create_nonce('add_mail_template_category'));
                        ?>

                        <div class="form-group">
                            <label for="mail_admin_email">发送模版</label>
                            <div class="flex flex-wrap gap-2" data-filter-group="send">
                                <?php foreach ($send_categories as $key => $info):
                                    $cnt = (int) ($category_counts[$key] ?? 0);
                                    $is_active = ($active_category !== '' && $active_category === (string) $key);
                                    $href = todo_admin_url('index.php?page=mail&tab=templates&category=' . urlencode((string) $key));
                                    $is_custom = !empty($info['is_custom']);
                                    $delete_url = $is_custom ? todo_admin_url('index.php?page=mail&tab=templates&action=delete_category&group=send&key=' . urlencode((string) $key) . '&_todononce=' . todo_create_nonce('delete_mail_template_category')) : '';
                                    ?>
                                    <span class="todo-template-tag <?php echo $is_custom ? 'is-custom' : ''; ?>">
                                        <a href="<?php echo $href; ?>"
                                            class="btn <?php echo $is_active ? 'button-primary' : 'button-secondary'; ?> js-template-category-filter"
                                            data-group="send"
                                            data-label="<?php echo esc_attr((string) $info['label']); ?>"
                                            data-category="<?php echo esc_attr((string) $key); ?>">
                                            <?php echo esc_html($info['label']); ?><?php echo $cnt > 0 ? '<b class="m-l-05" style="color: #ff8a00;">' . (string) $cnt . '</b>' : ''; ?>
                                        </a>
                                        <?php if ($is_custom): ?>
                                            <a class="todo-template-tag-del js-template-category-delete" href="<?php echo $delete_url; ?>" data-group="send" data-category="<?php echo esc_attr((string) $key); ?>">×</a>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                                <button type="button" class="btn button-secondary js-template-category-add" data-filter-group="send" data-add-url="<?php echo esc_attr($send_add_url); ?>">+</button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="mail_admin_email">通知模版</label>
                            <div class="flex flex-wrap gap-2" data-filter-group="notify">
                                <?php foreach ($notify_categories as $key => $info):
                                    $cnt = (int) ($category_counts[$key] ?? 0);
                                    $is_active = ($active_category !== '' && $active_category === (string) $key);
                                    $href = todo_admin_url('index.php?page=mail&tab=templates&category=' . urlencode((string) $key));
                                    $is_custom = !empty($info['is_custom']);
                                    $delete_url = $is_custom ? todo_admin_url('index.php?page=mail&tab=templates&action=delete_category&group=notify&key=' . urlencode((string) $key) . '&_todononce=' . todo_create_nonce('delete_mail_template_category')) : '';
                                    ?>
                                    <span class="todo-template-tag <?php echo $is_custom ? 'is-custom' : ''; ?>">
                                        <a href="<?php echo $href; ?>"
                                            class="btn <?php echo $is_active ? 'button-primary' : 'button-secondary'; ?> js-template-category-filter"
                                            data-group="notify"
                                            data-label="<?php echo esc_attr((string) $info['label']); ?>"
                                            data-category="<?php echo esc_attr((string) $key); ?>">
                                            <?php echo esc_html($info['label']); ?><?php echo $cnt > 0 ? '<b class="m-l-05" style="color: #ff8a00;">' . (string) $cnt . '</b>' : ''; ?>
                                        </a>
                                        <?php if ($is_custom): ?>
                                            <a class="todo-template-tag-del js-template-category-delete" href="<?php echo $delete_url; ?>" data-group="notify" data-category="<?php echo esc_attr((string) $key); ?>">×</a>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                                <button type="button" class="btn button-secondary js-template-category-add" data-filter-group="notify" data-add-url="<?php echo esc_attr($notify_add_url); ?>">+</button>
                            </div>
                        </div>
                        <script>
                            (function () {
                                var activeCategory = <?php echo json_encode($active_category, JSON_UNESCAPED_UNICODE); ?>;
                                var filterButtons = function () {
                                    return Array.prototype.slice.call(document.querySelectorAll('.js-template-category-filter'));
                                };
                                var tableRows = function () {
                                    return Array.prototype.slice.call(document.querySelectorAll('tr.js-template-row'));
                                };
                                var emptyRowEl = function () {
                                    return document.getElementById('todo-template-empty-row');
                                };

                                function setActive(cat) {
                                    filterButtons().forEach(function (b) {
                                        var isActive = (cat !== '' && b.getAttribute('data-category') === cat);
                                        b.classList.toggle('button-primary', isActive);
                                        b.classList.toggle('button-secondary', !isActive);
                                    });
                                }

                                function applyFilter(cat) {
                                    var visible = 0;
                                    tableRows().forEach(function (r) {
                                        var rc = r.getAttribute('data-category') || '';
                                        var show = (cat === '' || rc === cat);
                                        r.style.display = show ? '' : 'none';
                                        if (show) visible++;
                                    });
                                    var emptyRow = emptyRowEl();
                                    if (emptyRow) {
                                        emptyRow.style.display = visible === 0 ? '' : 'none';
                                    }
                                }

                                function updateUrl(cat) {
                                    try {
                                        var url = new URL(window.location.href);
                                        if (cat) {
                                            url.searchParams.set('category', cat);
                                        } else {
                                            url.searchParams.delete('category');
                                        }
                                        window.history.replaceState({}, '', url.toString());
                                    } catch (e) {}
                                }

                                function normalize(s) {
                                    return (s || '').toString().replace(/\s+/g, ' ').trim().toLowerCase();
                                }

                                function openAddForm(group, addUrl, container, addBtn) {
                                    if (!container) return;
                                    if (container.querySelector('.js-template-category-add-form')) return;
                                    if (!addBtn) {
                                        addBtn = container.querySelector('.js-template-category-add');
                                    }
                                    if (addBtn) {
                                        addBtn.dataset.prevDisplay = addBtn.style.display || '';
                                        addBtn.style.display = 'none';
                                    }
                                    var wrap = document.createElement('span');
                                    wrap.className = 'js-template-category-add-form todo-template-add-form';
                                    wrap.innerHTML = '<input type="text" class="regular-text todo-template-add-input" placeholder="请输入分类名称" style="width: 150px;padding: 0 10px;min-height: 27px;min-width: auto;font-size: 13px;">' +
                                        '<button type="button" class="btn button-primary todo-template-add-confirm">确定</button>' +
                                        '<button type="button" class="btn button-secondary todo-template-add-cancel">×</button>';
                                    container.appendChild(wrap);

                                    var input = wrap.querySelector('.todo-template-add-input');
                                    var btnOk = wrap.querySelector('.todo-template-add-confirm');
                                    var btnCancel = wrap.querySelector('.todo-template-add-cancel');

                                    function close() {
                                        if (addBtn) {
                                            addBtn.style.display = addBtn.dataset.prevDisplay || '';
                                            addBtn.dataset.prevDisplay = '';
                                        }
                                        wrap.remove();
                                    }

                                    function submit() {
                                        var val = (input && input.value) ? input.value.trim() : '';
                                        if (!val) {
                                            if (window.TodoCMS && TodoCMS.Utils && TodoCMS.Utils.showMessage) {
                                                TodoCMS.Utils.showMessage('请输入分类名称', 'warning');
                                            }
                                            input && input.focus();
                                            return;
                                        }
                                        var exists = false;
                                        filterButtons().forEach(function (b) {
                                            if (b.getAttribute('data-group') !== group) return;
                                            if (normalize(b.getAttribute('data-label')) === normalize(val)) {
                                                exists = true;
                                            }
                                        });
                                        if (exists) {
                                            if (window.TodoCMS && TodoCMS.Utils && TodoCMS.Utils.showMessage) {
                                                TodoCMS.Utils.showMessage('分类名称已存在，请换一个', 'warning');
                                            }
                                            input && input.focus();
                                            return;
                                        }
                                        if (window.TodoRequest && typeof window.TodoRequest.ajax === 'function') {
                                            var fd = new FormData();
                                            fd.append('action', 'add_category');
                                            fd.append('group', group);
                                            fd.append('label', val);
                                            try {
                                                var u = new URL(addUrl, window.location.href);
                                                var n = u.searchParams.get('_todononce') || '';
                                                if (n) fd.append('_todononce', n);
                                            } catch (e) {}
                                            window.TodoRequest.ajax({ url: addUrl, method: 'POST', data: fd });
                                            return;
                                        }
                                        if (window.TodoCMS && TodoCMS.Utils && TodoCMS.Utils.showMessage) {
                                            TodoCMS.Utils.showMessage('请求组件未加载，请刷新页面重试', 'error');
                                        }
                                    }

                                    btnOk && btnOk.addEventListener('click', submit);
                                    btnCancel && btnCancel.addEventListener('click', close);
                                    input && input.addEventListener('keydown', function (e) {
                                        if (e.key === 'Enter') {
                                            e.preventDefault();
                                            submit();
                                        }
                                        if (e.key === 'Escape') {
                                            e.preventDefault();
                                            close();
                                        }
                                    });
                                    input && input.focus();
                                }

                                function bindEvents() {
                                    function confirmInBestContext(message, onOk) {
                                        try {
                                            if (window.parent && window.parent !== window && window.parent.TodoCMS && window.parent.TodoCMS.Utils && typeof window.parent.TodoCMS.Utils.confirm === 'function') {
                                                window.parent.TodoCMS.Utils.confirm(message, function () { onOk && onOk(); });
                                                return;
                                            }
                                        } catch (e) {}
                                        if (window.TodoCMS && TodoCMS.Utils && typeof TodoCMS.Utils.confirm === 'function') {
                                            TodoCMS.Utils.confirm(message, function () { onOk && onOk(); });
                                            return;
                                        }
                                        if (confirm(message)) {
                                            onOk && onOk();
                                        }
                                    }

                                    filterButtons().forEach(function (b) {
                                        b.addEventListener('click', function (e) {
                                            e.preventDefault();
                                            var cat = b.getAttribute('data-category') || '';
                                            if (cat === activeCategory) {
                                                cat = '';
                                            }
                                            activeCategory = cat;
                                            setActive(activeCategory);
                                            applyFilter(activeCategory);
                                            updateUrl(activeCategory);
                                        });
                                    });

                                    Array.prototype.slice.call(document.querySelectorAll('.js-template-category-add')).forEach(function (btn) {
                                        btn.addEventListener('click', function () {
                                            var group = btn.getAttribute('data-filter-group') || '';
                                            var addUrl = btn.getAttribute('data-add-url') || '';
                                            if (!group || !addUrl) return;
                                            var container = btn.parentElement;
                                            openAddForm(group, addUrl, container, btn);
                                        });
                                    });

                                    Array.prototype.slice.call(document.querySelectorAll('.js-template-category-delete')).forEach(function (del) {
                                        del.addEventListener('click', function (e) {
                                            e.preventDefault();
                                            confirmInBestContext('确定删除该分类吗？该分类下模板将变为未分类。', function () {
                                                var href = del.getAttribute('href') || '';
                                                if (window.TodoRequest && typeof window.TodoRequest.ajax === 'function') {
                                                    var fd = new FormData();
                                                    fd.append('action', 'delete_category');
                                                    fd.append('group', del.getAttribute('data-group') || '');
                                                    fd.append('key', del.getAttribute('data-category') || '');
                                                    try {
                                                        var u = new URL(href, window.location.href);
                                                        var n = u.searchParams.get('_todononce') || '';
                                                        if (n) fd.append('_todononce', n);
                                                    } catch (e) {}
                                                    window.TodoRequest.ajax({ url: href, method: 'POST', data: fd });
                                                    return;
                                                }
                                                if (window.TodoCMS && TodoCMS.Utils && TodoCMS.Utils.showMessage) {
                                                    TodoCMS.Utils.showMessage('请求组件未加载，请刷新页面重试', 'error');
                                                }
                                            });
                                        });
                                    });
                                }

                                window.addEventListener('DOMContentLoaded', function () {
                                    bindEvents();
                                    setActive(activeCategory);
                                    applyFilter(activeCategory);
                                });
                            })();
                        </script>
                        <style>
                            .todo-template-tag { position: relative; display: inline-flex; }
                            .todo-template-tag-del { position: absolute; top: -6px; right: -6px; width: 16px; height: 16px; border-radius: 8px; background: #fff; border: 1px solid #eceef1; font-size: 12px; line-height: 13px; text-align: center; text-decoration: none; opacity: 0; pointer-events: none; }
                            .todo-template-tag.is-custom:hover .todo-template-tag-del { opacity: 1; pointer-events: auto; }
                            .todo-template-add-form { display: inline-flex; align-items: center; gap: 6px; }
                        </style>
                    </div>

                    <form id="js-mail-batch-form-templates" method="post">
                        <input type="hidden" name="action" value="batch_delete">
                        <input type="hidden" name="_todononce" value="<?php echo todo_create_nonce('batch_delete_mail_items'); ?>">
                    <table class="admin-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th class="check-column"><input type="checkbox" id="js-mail-check-all-templates" title="全选"></th>
                                <th>ID</th>
                                <th style="width: 120px; min-width: 120px;">名称</th>
                                <th>分类</th>
                                <th>标识 (Slug)</th>
                                <th style="width: 120px; min-width: 200px;">主题</th>
                                <th style="width: 150px; min-width: 150px;">更新时间</th>
                                <th style="width: 120px; min-width: 120px;">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($templates):
                                $active_category = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
                                foreach ($templates as $tpl):
                                    $tpl_cat = (string) ($tpl->category ?? '');
                                    $row_style = ($active_category !== '' && $tpl_cat !== $active_category) ? 'display:none;' : '';
                                    ?>
                                    <tr class="js-template-row" data-category="<?php echo esc_attr($tpl_cat); ?>" style="<?php echo $row_style; ?>">
                                        <td><input type="checkbox" name="item_ids[]" value="<?php echo (int)$tpl->id; ?>" class="js-mail-row-check" form="js-mail-batch-form-templates"></td>
                                        <td><?php echo $tpl->id; ?></td>
                                        <td><strong><a
                                                     href="<?php echo todo_admin_url('?page=mail&tab=templates&action=edit&id=' . $tpl->id); ?>"><?php echo esc_html($tpl->name); ?></a></strong>
                                        </td>
                                        <td><?php echo isset($notification_types[$tpl->category]) ? esc_html($notification_types[$tpl->category]['label']) : esc_html($tpl->category); ?>
                                        </td>
                                        <td><code><?php echo esc_html($tpl->slug); ?></code></td>
                                        <td><span class="tb-meta"><?php echo esc_html($tpl->subject); ?><span></td>
                                        <td><span class="tb-meta"><?php echo esc_html($tpl->updated_at); ?></span></td>
                                        <td>
                                            <div class="row-actions">
                                                <span class="edit"><a href="<?php echo todo_admin_url('?page=mail&tab=templates&action=edit&id=' . $tpl->id); ?>">编辑</a> | </span>
                                                <span class="copy"><a href="<?php echo todo_admin_url('?page=mail&tab=templates&template_action=copy&id=' . $tpl->id . '&_todononce=' . todo_create_nonce('copy_mail_template')); ?>" class="js-todo-post-action" data-action="copy">复制</a> | </span>
                                                <span class="trash"><a href="<?php echo todo_admin_url('?page=mail&tab=templates&action=delete&id=' . $tpl->id . '&_todononce=' . todo_create_nonce('delete_mail_item')); ?>" class="submitdelete js-todo-post-action" data-confirm="确定删除吗？">删除</a></span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach;
                            endif; ?>
                            <tr id="todo-template-empty-row" class="empty" style="<?php echo empty($templates) ? '' : 'display:none;'; ?>">
                                <td colspan="8">
                                    <div class="no-items">
                                        <p>暂无模板。</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    </form>
                    <!-- 批量操作栏 -->
                    <div id="js-mail-bulk-bar-templates" class="admin-pagination" style="display:none;">
                        <div class="info"><b id="js-mail-bulk-count-templates">0</b> 项已选中</div>
                        <div class="pagination" style="gap: 10px;">
                            <button type="button" class="button button-secondary" id="js-mail-bulk-cancel-templates">取消选择</button>
                            <button type="button" class="button" style="background:#dc3232;color:#fff;border-color:#dc3232;" id="js-mail-bulk-delete-templates">批量删除</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="admin-form-submit">
                <a href="<?php echo todo_admin_url('?page=mail&tab=templates&action=edit'); ?>" class="button button-primary">新建模板</a>
            </div>

        <?php elseif ($action === 'edit'):
            $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
            $tpl = $id ? $tododb->get_row("SELECT * FROM {$tododb->prefix}email_templates WHERE id = ?", [$id]) : null;
            ?>
            <form method="post" class="todo-request">
                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title"><?php echo $id > 0 ? '编辑模板' : '新建模板'; ?></h3>
                    </div>
                    <div class="admin-card-content">
                        <input type="hidden" name="_todononce" value="<?php echo todo_create_nonce('mail_settings_nonce'); ?>">
                        <input type="hidden" name="id" value="<?php echo $id; ?>">

                        <div class="form-group">
                            <label for="category">模板分类 <span class="description">[*] 固定标识分类，可以创建多个模板，但系统仅识别固定的标识名。</span></label>
                            <select name="category" id="category" class="regular-select">
                                <option value="">-- 默认分类 --</option>
                                <?php
                                $group_of = function ($key, $info) {
                                    if (is_array($info) && isset($info['template_group'])) {
                                        $g = strtolower(trim((string) $info['template_group']));
                                        if (in_array($g, ['send', 'notify'], true)) {
                                            return $g;
                                        }
                                    }
                                    if (!empty($info['is_template_only'])) {
                                        return 'send';
                                    }
                                    return 'notify';
                                };
                                $send_categories = [];
                                $notify_categories = [];
                                foreach ($notification_types as $k => $info) {
                                    $g = $group_of($k, $info);
                                    if ($g === 'send') {
                                        $send_categories[$k] = $info;
                                    } else {
                                        $notify_categories[$k] = $info;
                                    }
                                }
                                ?>
                                <optgroup label="发送模版">
                                    <?php foreach ($send_categories as $key => $info): ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php echo ($tpl && $tpl->category === $key) ? 'selected' : ''; ?>><?php echo esc_html( !empty($info['fixed_slug']) ? '[ ' . $info['label'] . ' ]' : $info['label'] ); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="通知模版">
                                    <?php foreach ($notify_categories as $key => $info): ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php echo ($tpl && $tpl->category === $key) ? 'selected' : ''; ?>><?php echo esc_html( !empty($info['fixed_slug']) ? '[ ' . $info['label'] . ' ]' : $info['label'] ); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                            <div id="category-variable-hint" class="notice notice-success" style="font-size: 13px; display:none;"></div>
                        </div>
                        <script type="text/javascript">
                        (function(){
                            // 分类元数据：包含 description 和 fixed_slug（若有）
                            var categoryMeta = <?php echo json_encode(array_map(function($v){
                                return [
                                    'description' => $v['description'],
                                    'fixed_slug'  => $v['fixed_slug'] ?? null,
                                    'is_template_only' => !empty($v['is_template_only'])
                                ];
                            }, $notification_types), JSON_UNESCAPED_UNICODE); ?>;

                            var sel       = document.getElementById('category');
                            var hint      = document.getElementById('category-variable-hint');
                            var slugInput = document.getElementById('slug');
                            var slugHint  = document.getElementById('slug-fixed-hint');

                            function update() {
                                if (!sel) return;
                                if (!hint) hint = document.getElementById('category-variable-hint');
                                if (!slugInput) slugInput = document.getElementById('slug');
                                if (!slugHint) slugHint = document.getElementById('slug-fixed-hint');

                                var val  = sel.value;
                                var meta = val ? categoryMeta[val] : null;

                                // ① 分类变量说明提示
                                if (hint) {
                                    if (meta && meta.description) {
                                        hint.innerHTML = meta.description;
                                        hint.style.display = 'block';
                                    } else {
                                        hint.style.display = 'none';
                                    }
                                }

                                // ② Slug 固定提示与强行锁定逻辑（对具有固定 slug 的分类强制插入且只读不可更改）
                                if (slugHint && meta && meta.fixed_slug) {
                                    var fs = meta.fixed_slug;
                                    slugHint.innerHTML =
                                        '此分类由系统固定标识，<strong style="color: #dc3232;">系统将强制使用模板标识：' + fs + '</strong>（不可更改）。';
                                    slugHint.style.display = 'block';
                                    if (slugInput) {
                                        slugInput.value = fs;
                                        slugInput.readOnly = true;
                                        slugInput.style.backgroundColor = '#f0f0f1';
                                        slugInput.style.cursor = 'not-allowed';
                                    }
                                } else {
                                    if (slugHint) {
                                        slugHint.style.display = 'none';
                                    }
                                    if (slugInput) {
                                        slugInput.readOnly = false;
                                        slugInput.style.backgroundColor = '';
                                        slugInput.style.cursor = '';
                                    }
                                }
                            }

                            if (sel) {
                                sel.addEventListener('change', update);
                            }
                            update();
                        })();
                        </script>

                        <div class="form-group">
                            <label for="name">模板名称 <span class="required">*</span></label>
                            <input type="text" name="name" id="name" value="<?php echo $tpl ? esc_attr($tpl->name) : ''; ?>"
                                class="regular-text" required>
                        </div>

                        <div class="form-group">
                            <label for="slug">模板标识<span class="description">(Slug)</span><span class="required">*</span></label>
                            <input type="text" name="slug" id="slug" value="<?php echo $tpl ? esc_attr($tpl->slug) : ''; ?>"
                                class="regular-text" required>
                            <div id="slug-fixed-hint" class="notice notice-error" style="font-size: 13px; display:none; margin-top:10px;"></div>
                            <p class="description">用于代码调用的唯一标识，如 register_verify、admin_login_verify，请勿重复。</p>
                        </div>

                        <div class="form-group">
                            <label for="sender_name">发件人<span class="description">(选填)</span></label>
                            <input type="text" name="sender_name" id="sender_name"
                                value="<?php echo ($tpl && isset($tpl->sender_name)) ? esc_attr($tpl->sender_name) : ''; ?>" class="regular-text">
                            <p class="description">留空自动取网站标题。多语言则输入[en]等语言符号匹配网站标题。</p>
                        </div>

                        <div class="form-group">
                            <label for="subject">邮件主题<span class="description">(邮件标题)</span><span class="required">*</span></label>
                            <input type="text" name="subject" id="subject" style="width: 50%;"
                                value="<?php echo $tpl ? esc_attr($tpl->subject) : ''; ?>" class="large-text" required>
                        </div>

                        <div class="form-group">
                            <label for="preview_text">预览文本<span class="description">(选填)</span></label>
                            <textarea name="preview_text" id="preview_text" class="large-text" style="width: 50%;"
                                rows="2"><?php echo $tpl && isset($tpl->variables) ? esc_textarea($tpl->variables) : ''; ?></textarea>
                            <p class="description">隐藏文本，会在邮件客户端收件箱列表中显示为摘要预览，支持与正文相同的变量替换。如不填写则由客户端自动截取正文文本。</p>
                        </div>

                        <div class="form-group">
                            <label>邮件内容 <span class="required">*</span></label>
                            <?php
                            $tpl_content = $tpl ? $tpl->content : '';
                            if (function_exists('todo_convert_content_urls_to_absolute')) {
                                $tpl_content = todo_convert_content_urls_to_absolute($tpl_content);
                            }
                            ?>
                            <script id="editor" name="content" type="text/plain"
                                style="width:100%;height:400px;"><?php echo $tpl_content; ?></script>
                        </div>
                    </div>
                </div>
                <div class="admin-form-submit">
                    <button type="submit" class="button button-primary">保存模板</button>
                    <a href="<?php echo todo_admin_url('?page=mail&tab=templates'); ?>" class="button button-secondary">返回列表</a>
                </div>
            </form>
        <?php endif; ?>

        <!-- 草稿箱 -->
    <?php elseif ($active_tab === 'drafts'): ?>
        <?php if ($action === 'list'): ?>
            <div class="admin-card">
                <div class="admin-card-header no-border">
                    <h3 class="admin-card-title">邮件草稿</h3>
                </div>
                <div class="admin-card-content">
                    <form id="js-mail-batch-form-drafts" method="post">
                        <input type="hidden" name="action" value="batch_delete">
                        <input type="hidden" name="_todononce" value="<?php echo todo_create_nonce('batch_delete_mail_items'); ?>">
                    <table class="admin-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th class="check-column"><input type="checkbox" id="js-mail-check-all-drafts" title="全选"></th>
                                <th>ID</th>
                                <th>收件人</th>
                                <th style="min-width: 200px;">主题</th>
                                <th style="min-width: 200px;">更新时间</th>
                                <th style="min-width: 180px; width: 180px;">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($drafts):
                                foreach ($drafts as $draft): ?>
                                    <tr>
                                        <td><input type="checkbox" name="item_ids[]" value="<?php echo (int)$draft->id; ?>" class="js-mail-row-check" form="js-mail-batch-form-drafts"></td>
                                        <td><?php echo $draft->id; ?></td>
                                        <td><?php echo esc_html($draft->recipient); ?></td>
                                        <td><?php echo esc_html($draft->subject); ?></td>
                                        <td><?php echo $draft->updated_at; ?></td>
                                        <td>
                                            <div class="row-actions">
                                                <a href="<?php echo todo_admin_url("?page=mail&tab=drafts&action=edit&id={$draft->id}"); ?>">继续编辑</a> |
                                                <a href="<?php echo todo_nonce_url("?page=mail&tab=drafts&action=send&id={$draft->id}", 'send_mail_draft'); ?>"
                                                    class="js-todo-post-action" data-confirm="确定要发送此邮件吗？">立即发送</a> |
                                                <a href="<?php echo todo_nonce_url("?page=mail&tab=drafts&action=delete&id={$draft->id}", 'delete_mail_item'); ?>"
                                                    class="submitdelete js-todo-post-action" data-confirm="确定要删除此草稿吗？">删除</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach;
                            else: ?>
                                <tr class="empty">
                                    <td colspan="6">
                                        <div class="no-items">
                                            <p>暂无邮件草稿。</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </form>
                    <!-- 批量操作栏 -->
                    <div id="js-mail-bulk-bar-drafts" class="admin-pagination" style="display:none;">
                        <div class="info"><b id="js-mail-bulk-count-drafts">0</b> 项已选中</div>
                        <div class="pagination" style="gap: 10px;">
                            <button type="button" class="button button-secondary" id="js-mail-bulk-cancel-drafts">取消选择</button>
                            <button type="button" class="button" style="background:#dc3232;color:#fff;border-color:#dc3232;" id="js-mail-bulk-delete-drafts">批量删除</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="admin-form-submit">
                <a href="<?php echo todo_admin_url('?page=mail&tab=drafts&action=new'); ?>" class="button button-primary">写邮件</a>
            </div>

        <?php elseif ($action === 'edit' || $action === 'new'):
            $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
            $draft = $id ? $tododb->get_row("SELECT * FROM {$tododb->prefix}email_drafts WHERE id = ?", [$id]) : null;
            ?>
            <form method="post" class="todo-request">
                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title"><?php echo $id > 0 ? '编辑草稿' : '写邮件'; ?></h3>
                    </div>
                    <div class="admin-card-content">
                        <input type="hidden" name="_todononce" value="<?php echo todo_create_nonce('mail_settings_nonce'); ?>">
                        <input type="hidden" name="id" value="<?php echo $id; ?>">

                        <div class="form-group">
                            <label for="sender_name">发件人<span class="description">(选填)</span></label>
                            <input type="text" name="sender_name" id="sender_name"
                                value="<?php echo $draft && isset($draft->sender_name) ? esc_attr($draft->sender_name) : ''; ?>" class="regular-text">
                            <p class="description">留空自动取网站标题。多语言则输入[en]等语言符号匹配网站标题。</p>
                        </div>

                        <div class="form-group">
                            <label for="recipient">收件人 <span class="required">*</span></label>
                            <input type="email" name="recipient" id="recipient"
                                value="<?php echo $draft ? esc_attr($draft->recipient) : ''; ?>" class="regular-text" required>
                        </div>

                        <div class="form-group">
                            <label for="subject">主题 <span class="required">*</span></label>
                            <input type="text" name="subject" id="subject"
                                value="<?php echo $draft ? esc_attr($draft->subject) : ''; ?>" class="large-text" required>
                        </div>

                        <div class="form-group">
                            <label for="preview_text">预览文本<span class="description">(选填)</span></label>
                            <textarea name="preview_text" id="preview_text" class="large-text" style="width: 50%;"
                                rows="2"><?php echo $draft && isset($draft->preview_text) ? esc_textarea($draft->preview_text) : ''; ?></textarea>
                            <p class="description">隐藏文本，会在邮件客户端收件箱列表中显示为摘要预览，支持与正文相同的变量替换。如不填写则由客户端自动截取正文文本。</p>
                        </div>

                        <div class="form-group">
                            <label>内容 <span class="required">*</span></label>
                            <script id="editor" name="content" type="text/plain"
                                style="width:100%;height:400px;"><?php echo $draft ? $draft->content : ''; ?></script>
                        </div>  
                    </div>
                </div>
                <div class="admin-form-submit">
                    <button type="submit" name="send_now" class="button button-primary"
                        onclick="return TodoCMS.Utils.confirmAction(this, '确定立即发送吗？')"><span class="toicon" style="transform: rotate(316deg); margin-top: -4px;">➤</span> 立即发送</button>
                    <button type="submit" name="save_draft" class="button button-secondary">保存草稿</button>
                    <a href="<?php echo todo_admin_url('?page=mail&tab=drafts'); ?>" class="button button-secondary">返回列表</a>
                </div>
            </form>
        <?php endif; ?>

        <!-- 对话窗口 -->
    <?php elseif ($active_tab === 'conversation'): ?>
        <div class="admin-card">
            <div class="admin-card-header">
                <div class="admin-card-title-section">
                    <h3 class="admin-card-title">对话窗口</h3>
                    <div class="admin-card-subtitle">
                        <?php echo esc_html($thread_key ?? ''); ?>
                    </div>
                </div>
                <div class="admin-card-actions">
                    <a href="<?php echo todo_admin_url('index.php?page=product-inquiries'); ?>" class="button button-secondary">返回询盘</a>
                    <a href="<?php echo todo_admin_url('index.php?page=mail&tab=logs'); ?>" class="button button-secondary">返回日志</a>
                    <?php
                    $format_mode = isset($_GET['format']) ? trim((string) $_GET['format']) : '';
                    $iframe_mode_param = ($format_mode === 'iframe');
                    ?>
                    <?php if ($iframe_mode_param): ?>
                        <?php
                        $open_tab_url = todo_admin_url('index.php?page=mail&tab=conversation');
                        if (!empty($inquiry_id)) {
                            $open_tab_url .= '&inquiry_id=' . intval($inquiry_id);
                        } elseif (!empty($thread_key)) {
                            $open_tab_url .= '&thread=' . urlencode((string) $thread_key);
                        } elseif (!empty($log_id)) {
                            $open_tab_url .= '&log_id=' . intval($log_id);
                        }
                        if (strpos($open_tab_url, 'format=') === false) {
                            $open_tab_url .= '&format=iframe';
                        }
                        ?>
                        <a href="<?php echo esc_url($open_tab_url); ?>" class="button button-secondary" id="js-mail-open-newtab" target="_blank" rel="noopener noreferrer" title="新标签页打开" style="display:none;">⭤</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="admin-card-content" style="padding:0;">
                <style>
                    .todo-mail-chat { height: 68vh; display: flex; flex-direction: column; background: #ededed; }
                    .todo-mail-chat-top { background: #fff; border-bottom: 1px solid #e6e6e6; padding: 10px 20px; }
                    .todo-mail-chat-messages { flex: 1; overflow: auto; padding: 10px 20px; }
                    .todo-mail-chat-row { display: flex; align-items: flex-end; gap: 10px; padding: 20px 0; }
                    .todo-mail-chat-row.out { justify-content: flex-end; }
                    .todo-mail-chat-avatar { width: 36px; height: 36px; border-radius: 3px; background: #cfd5db; flex: 0 0 36px; display: flex; align-items: center; justify-content: center; font-weight: 500; color: #3b3f45; }
                    .todo-mail-chat-avatar img { width: 100%; height: 100%; border-radius: 3px; object-fit: cover; display: block; }
                    .todo-mail-chat-bubble { max-width: 70vw; background: #fff; border-radius: 10px; padding: 10px 12px; color: #111; word-break: break-all; white-space: pre-wrap; line-height: 1.65; box-shadow: 0 1px 0 rgba(0, 0, 0, .04); }
                    .todo-mail-chat-row.out .todo-mail-chat-bubble { background: #95ec69; }
                    .todo-mail-chat-bubble a { color: #2563eb; text-decoration: underline; }
                    .todo-mail-chat-bubble img { max-width: 400px; height: auto; border-radius: 6px; display: block; }
                    .todo-mail-chat-images { margin-top: 6px; display: flex; flex-direction: column; gap: 6px; }
                    .todo-mail-chat-meta { font-size: 12px; color: #8a8f98; margin: 0 0 4px 0; display: flex; gap: 10px; flex-wrap: wrap; }
                    .todo-mail-chat-subject { font-size: 12px; color: #6b7280; margin: 0 0 6px 0; }
                    .todo-mail-chat-composer { background: #fff; border-top: 1px solid #e6e6e6; padding: 10px 10px; display: flex; gap: 10px; align-items: flex-end; }
                    .todo-mail-chat-input { flex: 1; min-height: 44px; max-height: 240px; resize: vertical; border: 1px solid #d7d7d7; border-radius: 8px; padding: 10px 10px; font-size: 14px; line-height: 1.5; background: #fff; }
                    .todo-mail-chat-send { height: 44px; padding: 0 16px; border-radius: 8px; }
                </style>

                <?php
                $imap_enabled_view = (int) CMS_Option::get('mail_imap_enabled', 0) === 1 ? 1 : 0;
                $thread_last_id = 0;
                if (!empty($thread_messages) && is_array($thread_messages)) {
                    foreach ($thread_messages as $tm) {
                        $tid = is_object($tm) && isset($tm->id) ? intval($tm->id) : (is_array($tm) && isset($tm['id']) ? intval($tm['id']) : 0);
                        if ($tid > $thread_last_id) {
                            $thread_last_id = $tid;
                        }
                    }
                }
                ?>

                <div class="todo-mail-chat" id="js-mail-chat" data-thread-key="<?php echo esc_attr((string) ($thread_key ?? '')); ?>" data-last-id="<?php echo intval($thread_last_id); ?>" data-imap-enabled="<?php echo intval($imap_enabled_view); ?>">
                    <div class="todo-mail-chat-top">
                        <?php if (!empty($thread_inquiry)): ?>
                            <?php
                            $inq_author = is_object($thread_inquiry) ? (string) ($thread_inquiry->comment_author ?? '') : '';
                            $inq_email = is_object($thread_inquiry) ? (string) ($thread_inquiry->comment_author_email ?? '') : '';
                            $inq_date = is_object($thread_inquiry) ? (string) ($thread_inquiry->comment_date ?? '') : '';
                            $inq_avatar_url = '';
                            if ($inq_email !== '' && function_exists('get_user_by') && function_exists('get_user_avatar_url')) {
                                $u = get_user_by('email', $inq_email);
                                if ($u && is_object($u) && is_callable([$u, 'get_id'])) {
                                    $url = (string) get_user_avatar_url($u->get_id(), 36);
                                    $bad = $url !== '' && (stripos($url, 'admin/assets/images/default-avatar.svg') !== false || stripos($url, 'default-avatar.svg') !== false);
                                    if (!$bad && $url !== '' && stripos($url, 'seed_avatar_') !== false && preg_match('/\.[a-z0-9]{2,6}(\?|$)/i', $url) !== 1) {
                                        $bad = true;
                                    }
                                    if (!$bad) {
                                        $inq_avatar_url = $url;
                                    }
                                }
                            }
                            $pid = is_object($thread_inquiry) ? intval($thread_inquiry->comment_post_ID ?? 0) : 0;
                            $ptitle = '';
                            $purl = '';
                            if ($pid > 0 && function_exists('get_post')) {
                                $p = get_post($pid);
                                if ($p) {
                                    $ptitle = (string) ($p->post_title ?? '');
                                    if (function_exists('get_permalink')) {
                                        $purl = (string) get_permalink($pid);
                                    }
                                }
                            }
                            ?>
                            <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <div class="todo-mail-chat-avatar">
                                        <?php if ($inq_avatar_url !== ''): ?>
                                            <img src="<?php echo esc_url($inq_avatar_url); ?>" alt="" width="36" height="36">
                                        <?php else: ?>
                                            <?php
                                            $inq_initial = 'U';
                                            $inq_name = trim((string) $inq_author);
                                            if ($inq_name !== '') {
                                                if (function_exists('mb_substr')) {
                                                    $inq_initial = (string) mb_substr($inq_name, 0, 1, 'UTF-8');
                                                } elseif (preg_match('/^./u', $inq_name, $mm)) {
                                                    $inq_initial = (string) ($mm[0] ?? 'U');
                                                } else {
                                                    $inq_initial = strtoupper(substr($inq_name, 0, 1));
                                                }
                                            } elseif ($inq_email !== '') {
                                                $inq_initial = strtoupper(substr(trim((string) $inq_email), 0, 1));
                                            }
                                            echo esc_html($inq_initial);
                                            ?>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div><strong><?php echo esc_html($inq_author); ?></strong><span class="description">（<?php echo esc_html($inq_email); ?>）</span></div>
                                        <div class="description m-0"><?php echo esc_html($inq_date); ?></div>
                                    </div>
                                </div>
                                <div>
                                    <?php if ($ptitle !== ''): ?>
                                        <a href="<?php echo esc_url($purl); ?>" target="_blank"><?php echo esc_html($ptitle); ?></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="description">同一主题内的邮件会聚合到此对话。</div>
                        <?php endif; ?>
                    </div>

                    <div class="todo-mail-chat-messages" id="js-mail-chat-messages">
                        <?php if (!empty($thread_messages) && is_array($thread_messages)): ?>
                            <?php foreach ($thread_messages as $m): ?>
                                <?php
                                $dir = isset($m->direction) ? (string) $m->direction : 'out';
                                $is_in = $dir === 'in';
                                $time = (string) (($m->received_at ?? '') !== '' ? $m->received_at : (($m->sent_at ?? '') !== '' ? $m->sent_at : ($m->created_at ?? '')));
                                $subj = trim((string) ($m->subject ?? ''));
                                $from = $is_in ? (string) ($m->from_email ?? '') : (string) ($m->from_email ?? ($smtp_user ?? ''));
                                $to = $is_in ? (string) ($m->to_email ?? '') : (string) ($m->to_email ?? ($m->recipient ?? ''));
                                $raw = (string) ($m->content ?? '');
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
                                $bubble_html = function_exists('todo_mail_render_chat_bubble_html') ? todo_mail_render_chat_bubble_html($plain, $raw, $dir) : esc_html($plain);

                                $avatar_html = '';
                                $avatar_fallback = '';
                                if ($is_in) {
                                    if ($from !== '' && function_exists('get_avatar')) {
                                        $avatar_html = (string) get_avatar($from, 36, '', $from);
                                        if ($avatar_html !== '' && (stripos($avatar_html, 'admin/assets/images/default-avatar.svg') !== false || stripos($avatar_html, 'default-avatar.svg') !== false)) {
                                            $avatar_html = '';
                                        }
                                        if ($avatar_html !== '' && preg_match('/\bsrc\s*=\s*([\'"])([^\'"]+)\1/i', $avatar_html, $mm)) {
                                            $src = trim((string) ($mm[2] ?? ''));
                                            if ($src !== '' && stripos($src, 'seed_avatar_') !== false && preg_match('/\.[a-z0-9]{2,6}(\?|$)/i', $src) !== 1) {
                                                $avatar_html = '';
                                            }
                                        }
                                    }
                                    $from_name = '';
                                    if ($from !== '' && function_exists('get_user_by')) {
                                        $u = get_user_by('email', $from);
                                        if ($u && is_object($u)) {
                                            if (is_callable([$u, 'get_display_name'])) {
                                                $from_name = (string) $u->get_display_name();
                                            } elseif (isset($u->display_name)) {
                                                $from_name = (string) $u->display_name;
                                            }
                                        }
                                    }
                                    $from_name = trim((string) $from_name);
                                    if ($from_name === '') {
                                        $inq_em = isset($inq_email) ? strtolower(trim((string) $inq_email)) : '';
                                        $inq_nm = isset($inq_author) ? trim((string) $inq_author) : '';
                                        if ($inq_em !== '' && $inq_nm !== '' && strtolower(trim((string) $from)) === $inq_em) {
                                            $from_name = $inq_nm;
                                        }
                                    }
                                    if ($from_name !== '') {
                                        if (function_exists('mb_substr')) {
                                            $avatar_fallback = (string) mb_substr($from_name, 0, 1, 'UTF-8');
                                        } elseif (preg_match('/^./u', $from_name, $mm)) {
                                            $avatar_fallback = (string) ($mm[0] ?? '');
                                        } else {
                                            $avatar_fallback = strtoupper(substr($from_name, 0, 1));
                                        }
                                    }
                                } else {
                                    if (function_exists('get_current_user_id') && function_exists('get_user_avatar_url')) {
                                        $uid = intval(get_current_user_id());
                                        if ($uid > 0) {
                                            $me_url = (string) get_user_avatar_url($uid, 36);
                                            $me_bad = $me_url !== '' && (stripos($me_url, 'admin/assets/images/default-avatar.svg') !== false || stripos($me_url, 'default-avatar.svg') !== false);
                                            if (!$me_bad && $me_url !== '' && stripos($me_url, 'seed_avatar_') !== false && preg_match('/\.[a-z0-9]{2,6}(\?|$)/i', $me_url) !== 1) {
                                                $me_bad = true;
                                            }
                                            if ($me_url !== '' && !$me_bad) {
                                                $avatar_html = '<img src="' . esc_url($me_url) . '" alt="" width="36" height="36">';
                                            }
                                        }
                                    }
                                    $me_name = '';
                                    if (function_exists('get_current_user_id')) {
                                        $uid = intval(get_current_user_id());
                                        if ($uid > 0) {
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
                                        }
                                    }
                                    $me_name = trim((string) $me_name);
                                    if ($me_name !== '') {
                                        if (function_exists('mb_substr')) {
                                            $avatar_fallback = (string) mb_substr($me_name, 0, 1, 'UTF-8');
                                        } elseif (preg_match('/^./u', $me_name, $mm)) {
                                            $avatar_fallback = (string) ($mm[0] ?? '');
                                        } else {
                                            $avatar_fallback = strtoupper(substr($me_name, 0, 1));
                                        }
                                    } else {
                                        $avatar_fallback = '我';
                                    }
                                }

                                $avatar_text = $avatar_fallback !== '' ? $avatar_fallback : ($is_in ? ($from !== '' ? strtoupper(substr($from, 0, 1)) : 'U') : '我');
                                ?>
                                <div class="todo-mail-chat-row <?php echo $is_in ? 'in' : 'out'; ?>">
                                    <?php if ($is_in): ?>
                                        <div class="todo-mail-chat-avatar"><?php echo $avatar_html !== '' ? $avatar_html : esc_html($avatar_text); ?></div>
                                    <?php endif; ?>
                                    <div style="margin-bottom: -5px;display:flex;flex-direction:column;align-items:<?php echo $is_in ? 'flex-start' : 'flex-end'; ?>;">
                                        <div class="todo-mail-chat-meta">
                                            <span><?php echo esc_html($is_in ? ('收｜' . $from) : ('发｜' . $to)); ?></span>
                                            <span><?php echo esc_html($time); ?></span>
                                        </div>
                                        <?php if ($subj !== ''): ?>
                                            <div class="todo-mail-chat-subject"><?php echo esc_html($subj); ?></div>
                                        <?php endif; ?>
                                        <div class="todo-mail-chat-bubble"><?php echo $bubble_html; ?></div>
                                    </div>
                                    <?php if (!$is_in): ?>
                                        <div class="todo-mail-chat-avatar"><?php echo $avatar_html !== '' ? $avatar_html : esc_html($avatar_text); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-items">
                                <p>暂无对话记录。</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <form method="post" class="todo-request todo-mail-chat-composer" autocomplete="off">
                        <input type="hidden" name="_todononce" value="<?php echo todo_create_nonce('mail_settings_nonce'); ?>">
                        <input type="hidden" name="thread_key" value="<?php echo esc_attr((string) ($thread_key ?? '')); ?>">
                        <input type="hidden" name="inquiry_id" value="<?php echo intval($thread_inquiry_id ?? 0); ?>">
                        <input type="hidden" name="send_token" value="">
                        <button type="button" class="button button-secondary todo-mail-chat-media-btn" id="js-mail-chat-insert-img" title="选择图像插入" style="height: 38px;border-radius: 8px;padding: 0 8px;display: inline-flex;align-items: center;justify-content: center;gap: 4px;">
                            <i class="icon todofont todo-Pic" style="font-size: 20px;"></i>
                        </button>
                        <textarea class="todo-mail-chat-input" id="js-mail-reply-content" name="reply_content" placeholder="输入消息" required></textarea>
                        <button type="submit" name="send_reply" value="1" class="button button-primary todo-mail-chat-send">发送</button>
                    </form>
                </div>

                <script>
                    (function () {
                        var box = document.getElementById('js-mail-chat-messages');
                        if (box) {
                            box.scrollTop = box.scrollHeight;
                        }
                        var openBtn = document.getElementById('js-mail-open-newtab');
                        if (openBtn) {
                            var inFrame = false;
                            try {
                                inFrame = (window.self !== window.top);
                            } catch (err) {
                                inFrame = true;
                            }
                            if (inFrame) {
                                openBtn.style.display = '';
                            }
                        }
                        var input = document.getElementById('js-mail-reply-content');
                        if (!input) return;
                        var imgBtn = document.getElementById('js-mail-chat-insert-img');
                        if (imgBtn) {
                            imgBtn.addEventListener('click', function (e) {
                                e.preventDefault();
                                if (typeof window.selectMediaForField === 'function') {
                                    window.selectMediaForField({
                                        title: '选择插入图片',
                                        button_text: '插入到对话',
                                        multiple: true,
                                        type: 'image',
                                        modal_id: 'mail-media-modal',
                                        callback: function(selectedMedia) {
                                            var mediaList = Array.isArray(selectedMedia) ? selectedMedia : [selectedMedia];
                                            var htmlToInsert = '';
                                            mediaList.forEach(function(media) {
                                                if (media && media.url) {
                                                    var url = media.url;
                                                    if (url && !/^(https?:)?\/\//i.test(url) && /^data:/i.test(url) === false) {
                                                        var siteUrl = '<?php echo esc_js(rtrim(function_exists("todo_get_site_url") ? todo_get_site_url() : "", "/")); ?>';
                                                        if (!siteUrl && typeof window.TODO_SITE_URL !== 'undefined') {
                                                            siteUrl = (window.TODO_SITE_URL || '').replace(/\/+$/, '');
                                                        }
                                                        if (!siteUrl) {
                                                            var meta = document.querySelector('meta[name="site-url"]');
                                                            if (meta) siteUrl = (meta.getAttribute('content') || '').replace(/\/+$/, '');
                                                        }
                                                        if (!siteUrl) {
                                                            siteUrl = window.location.origin;
                                                        }
                                                        if (url.indexOf('/') === 0) {
                                                            var path = siteUrl.replace(/^https?:\/\/[^\/]+/i, '');
                                                            if (path && url.indexOf(path) === 0) {
                                                                url = window.location.origin + url;
                                                            } else {
                                                                url = siteUrl + url;
                                                            }
                                                        } else {
                                                            url = siteUrl + '/' + url;
                                                        }
                                                    }
                                                    var mime = (media.mime_type || '').toLowerCase();
                                                    var filename = media.filename || media.title || url.substring(url.lastIndexOf('/') + 1) || '附件';
                                                    var ext = url.substring(url.lastIndexOf('.') + 1).toLowerCase();
                                                    
                                                    if (mime.indexOf('image') === 0 || ['jpg','jpeg','png','gif','webp','svg','bmp'].indexOf(ext) !== -1) {
                                                        htmlToInsert += '<img src="' + url + '" />\n';
                                                    } else if (mime.indexOf('video') === 0 || ['mp4','webm','ogg','mov','avi','mkv'].indexOf(ext) !== -1) {
                                                        htmlToInsert += '<video src="' + url + '" controls style="max-width: 330px;"></video>\n';
                                                    } else {
                                                        htmlToInsert += '<a href="' + url + '" target="_blank">' + filename + '</a>\n';
                                                    }
                                                }
                                            });
                                            if (!htmlToInsert) return;

                                            var startPos = input.selectionStart || 0;
                                            var endPos = input.selectionEnd || 0;
                                            var val = input.value;
                                            input.value = val.substring(0, startPos) + htmlToInsert + val.substring(endPos);
                                            input.focus();
                                            input.selectionStart = input.selectionEnd = startPos + htmlToInsert.length;
                                        }
                                    });
                                } else {
                                    TodoModal.warning('媒体库插件未成功加载');
                                }
                            });
                        }

                        var composerForm = input.closest('form');
                        var isSending = false;
                        if (composerForm) {
                            composerForm.addEventListener('submit', function (e) {
                                if (isSending) {
                                    e.preventDefault();
                                    return;
                                }
                                isSending = true;
                                var btn = composerForm.querySelector('button[name="send_reply"]');
                                var tokenEl = composerForm.querySelector('input[name="send_token"]');
                                if (tokenEl && !tokenEl.value) {
                                    tokenEl.value = String(Date.now()) + '_' + Math.random().toString(16).slice(2);
                                }
                                if (btn) {
                                    btn.disabled = true;
                                    btn.textContent = '发送中...';
                                }
                                input.readOnly = true;
                            });
                        }

                        var chat = document.getElementById('js-mail-chat');
                        if (!chat) return;
                        var imapEnabled = chat.getAttribute('data-imap-enabled') === '1';
                        var threadKey = chat.getAttribute('data-thread-key') || '';
                        var lastId = parseInt(chat.getAttribute('data-last-id') || '0', 10) || 0;
                        var nonceEl = chat.querySelector('input[name="_todononce"]');
                        var nonce = nonceEl ? (nonceEl.value || '') : '';
                        var ajaxUrl = (window.ajaxurl || 'admin-ajax.php');

                        if (!imapEnabled || !threadKey || !nonce) return;

                        var isPolling = false;
                        function escText(s) {
                            if (s === undefined || s === null) return '';
                            return String(s);
                        }
                        function buildRow(msg) {
                            var dir = msg && msg.direction === 'in' ? 'in' : 'out';
                            var isIn = dir === 'in';
                            var from = escText(msg.from || '');
                            var to = escText(msg.to || '');
                            var time = escText(msg.time || '');
                            var subj = escText(msg.subject || '').trim();
                            var plain = escText(msg.plain || '').trim();
                            var html = escText(msg.html || '');
                            var threadKeyVal = escText(msg.thread_key || '').trim();
                            var midVal = escText(msg.message_id || '').trim();
                            var irtVal = escText(msg.in_reply_to || '').trim();
                            var refsVal = escText(msg.references || '').trim();
                            var tidxVal = escText(msg.thread_index || '').trim();
                            var ttopicVal = escText(msg.thread_topic || '').trim();
                            var cidxVal = escText(msg.conversation_index || '').trim();
                            var ctopicVal = escText(msg.conversation_topic || '').trim();
                            var avatarUrl = escText(msg.avatar_url || '').trim();
                            if (avatarUrl && (avatarUrl.indexOf('admin/assets/images/default-avatar.svg') !== -1 || avatarUrl.indexOf('default-avatar.svg') !== -1)) {
                                avatarUrl = '';
                            }
                            if (avatarUrl && avatarUrl.indexOf('seed_avatar_') !== -1 && !(/\.[a-z0-9]{2,6}(\?|$)/i.test(avatarUrl))) {
                                avatarUrl = '';
                            }
                            var avatarInitial = escText(msg.avatar_initial || '').trim();

                            var row = document.createElement('div');
                            row.className = 'todo-mail-chat-row ' + dir;
                            row.setAttribute('data-id', escText(msg.id || '0'));

                            var avatar = document.createElement('div');
                            avatar.className = 'todo-mail-chat-avatar';
                            if (avatarUrl) {
                                var img = document.createElement('img');
                                img.src = avatarUrl;
                                img.alt = '';
                                img.width = 36;
                                img.height = 36;
                                avatar.appendChild(img);
                            } else {
                                if (isIn) {
                                    avatar.textContent = avatarInitial ? avatarInitial : (from ? from.substring(0, 1).toUpperCase() : 'U');
                                } else {
                                    avatar.textContent = avatarInitial ? avatarInitial : '我';
                                }
                            }

                            var body = document.createElement('div');
                            body.style.display = 'flex';
                            body.style.marginBottom = '-5px';
                            body.style.flexDirection = 'column';
                            body.style.alignItems = isIn ? 'flex-start' : 'flex-end';

                            var meta = document.createElement('div');
                            meta.className = 'todo-mail-chat-meta';
                            var meta1 = document.createElement('span');
                            meta1.textContent = isIn ? ('收｜' + from) : ('发｜' + to);
                            var meta2 = document.createElement('span');
                            meta2.textContent = time;
                            meta.appendChild(meta1);
                            meta.appendChild(meta2);

                            body.appendChild(meta);

                            if (subj) {
                                var sdiv = document.createElement('div');
                                sdiv.className = 'todo-mail-chat-subject';
                                sdiv.textContent = subj;
                                body.appendChild(sdiv);
                            }

                            var bubble = document.createElement('div');
                            bubble.className = 'todo-mail-chat-bubble';
                            if (html) {
                                bubble.innerHTML = html;
                            } else {
                                bubble.textContent = plain;
                            }
                            body.appendChild(bubble);

                            if (isIn) {
                                row.appendChild(avatar);
                                row.appendChild(body);
                            } else {
                                row.appendChild(body);
                                row.appendChild(avatar);
                            }

                            return row;
                        }
                        function pollOnce() {
                            if (isPolling) return;
                            isPolling = true;
                            var params = new URLSearchParams();
                            params.append('action', 'mail_imap_poll');
                            params.append('thread_key', threadKey);
                            params.append('last_id', String(lastId));
                            params.append('_todononce', nonce);

                            fetch(ajaxUrl, {
                                method: 'POST',
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                                },
                                body: params.toString()
                            }).then(function (res) {
                                return res.json();
                            }).then(function (json) {
                                if (json && json.success) {
                                    var latestId = parseInt(json.latest_id || '0', 10) || 0;
                                    if (latestId > lastId) lastId = latestId;
                                    var msgs = (json.messages && Array.isArray(json.messages)) ? json.messages : [];
                                    if (msgs.length > 0 && box) {
                                        var nearBottom = (box.scrollHeight - box.scrollTop - box.clientHeight) < 120;
                                        for (var i = 0; i < msgs.length; i++) {
                                            var m = msgs[i];
                                            var el = buildRow(m);
                                            box.appendChild(el);
                                        }
                                        if (nearBottom) {
                                            box.scrollTop = box.scrollHeight;
                                        }
                                    }
                                }
                            }).catch(function () {
                            }).finally(function () {
                                isPolling = false;
                            });
                        }

                        pollOnce();
                        window.setInterval(pollOnce, 10000);
                    })();
                </script>
                <?php
                if (class_exists('TodoMediaModal')) {
                    echo TodoMediaModal::render('mail-media-modal', ['multiple' => true]);
                }
                ?>
            </div>
        </div>

        <!-- 发送日志 -->
    <?php elseif ($active_tab === 'logs'): ?>
        <div class="admin-card">
            <div class="admin-card-header no-border">
                <div class="admin-card-actions justify-between gap-y-3 flex-wrap w-100">
                    <form method="get" class="todo-request" action="<?php echo todo_admin_url('index.php'); ?>">
                        <input type="hidden" name="page" value="mail">
                        <input type="hidden" name="tab" value="logs">
                        <?php if (function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()): ?>
                            <input type="hidden" name="format" value="iframe">
                        <?php endif; ?>
                        <?php if (!empty($logs_orderby)): ?>
                            <input type="hidden" name="orderby" value="<?php echo esc_attr((string) $logs_orderby); ?>">
                        <?php endif; ?>
                        <?php if (!empty($logs_order)): ?>
                            <input type="hidden" name="order" value="<?php echo esc_attr(strtolower((string) $logs_order)); ?>">
                        <?php endif; ?>

                        <div class="flex gap-x-1 gap-y-3 flex-wrap items-center">
                            <input type="text" name="search" value="<?php echo htmlspecialchars((string) ($logs_search ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="搜索邮箱/主题关键字" class="regular-text" style="min-width: 120px;">

                            <select name="status" class="regular-select" style="min-width: unset;">
                                <option value="">全部状态</option>
                                <option value="sent" <?php echo (string) ($logs_status ?? '') === 'sent' ? 'selected' : ''; ?>>成功</option>
                                <option value="failed" <?php echo (string) ($logs_status ?? '') === 'failed' ? 'selected' : ''; ?>>失败</option>
                                <option value="received" <?php echo (string) ($logs_status ?? '') === 'received' ? 'selected' : ''; ?>>收到</option>
                            </select>

                            <select name="direction" class="regular-select" style="min-width: unset;">
                                <option value="">收+发</option>
                                <option value="in" <?php echo (string) ($logs_direction ?? '') === 'in' ? 'selected' : ''; ?>>仅收</option>
                                <option value="out" <?php echo (string) ($logs_direction ?? '') === 'out' ? 'selected' : ''; ?>>仅发</option>
                            </select>

                            <select name="range" class="regular-select" style="min-width: unset;">
                                <option value="">全部时间</option>
                                <option value="today" <?php echo (string) ($logs_range ?? '') === 'today' ? 'selected' : ''; ?>>今天</option>
                                <option value="7d" <?php echo (string) ($logs_range ?? '') === '7d' ? 'selected' : ''; ?>>近 7 天</option>
                                <option value="30d" <?php echo (string) ($logs_range ?? '') === '30d' ? 'selected' : ''; ?>>近 30 天</option>
                                <option value="90d" <?php echo (string) ($logs_range ?? '') === '90d' ? 'selected' : ''; ?>>近 90 天</option>
                            </select>

                            <input type="date" name="date_from" value="<?php echo esc_attr((string) ($logs_date_from ?? '')); ?>" class="regular-text" style="min-width: unset;">

                            <input type="date" name="date_to" value="<?php echo esc_attr((string) ($logs_date_to ?? '')); ?>" class="regular-text" style="min-width: unset;">

                            <button type="submit" class="button button-secondary">筛选</button>
                            <?php
                            $has_filter = (string) ($logs_search ?? '') !== '' || (string) ($logs_status ?? '') !== '' || (string) ($logs_direction ?? '') !== '' || (string) ($logs_range ?? '') !== '' || (string) ($logs_date_from ?? '') !== '' || (string) ($logs_date_to ?? '') !== '';
                            ?>
                            <?php if ($has_filter): ?>
                                <a href="<?php echo todo_admin_url('index.php?page=mail&tab=logs'); ?>" class="btn-normal m-l-05">重置</a>
                            <?php endif; ?>
                        </div>
                    </form>
                    <form method="post" action="<?php echo todo_admin_url('index.php?page=mail&tab=logs'); ?>" class="todo-request" style="display:inline-block;">
                        <input type="hidden" name="_todononce" value="<?php echo todo_create_nonce('mail_settings_nonce'); ?>">
                        <button type="submit" name="clear_logs" value="1" class="button button-secondary" onclick="return TodoCMS.Utils.confirmAction(this, '清空全部邮件发送日志？会同时删除所有对话记录！');">清空日志</button>
                    </form>
                </div>
            </div>
            <div class="admin-card-content">
                <table class="admin-table widefat fixed striped">
                    <thead>
                        <tr>
                            <?php if (function_exists('todo_admin_render_sort_th')): ?>
                                <?php echo todo_admin_render_sort_th('ID', 'id', 'check-column', array('query' => $_GET, 'base_url' => todo_admin_url('index.php'), 'current_orderby' => $logs_orderby ?? 'time', 'current_order' => strtolower($logs_order ?? 'desc'))); ?>
                            <?php else: ?>
                                <th class="check-column">ID</th>
                            <?php endif; ?>
                            <th>收/发</th>
                            <th style="width: 170px; min-width: 200px;">主题</th>
                            <th style="width: 80px; min-width: 80px;">状态</th>
                            <th style="width: 170px; min-width: 170px;">错误信息</th>
                            <?php if (function_exists('todo_admin_render_sort_th')): ?>
                                <?php echo todo_admin_render_sort_th('时间', 'time', '', array('query' => $_GET, 'base_url' => todo_admin_url('index.php'), 'current_orderby' => $logs_orderby ?? 'time', 'current_order' => strtolower($logs_order ?? 'desc'), 'style' => 'width: 172px; min-width: 172px;')); ?>
                            <?php else: ?>
                                <th style="width: 172px; min-width: 172px;">时间</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($logs):
                            foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo $log->id; ?></td>
                                    <?php
                                    $direction = isset($log->direction) ? (string) $log->direction : 'out';
                                    $addr = '';
                                    if ($direction === 'in') {
                                        $addr = (string) ($log->from_email ?? '');
                                    } else {
                                        $addr = (string) ($log->recipient ?? ($log->to_email ?? ''));
                                    }
                                    $thread_key = isset($log->thread_key) ? trim((string) $log->thread_key) : '';
                                    $related_type = isset($log->related_type) ? (string) $log->related_type : '';
                                    $related_id = isset($log->related_id) ? intval($log->related_id) : 0;
                                    $conv_url = '';
                                    if ($related_type === 'product_inquiry' && $related_id > 0) {
                                        $conv_url = todo_admin_url('index.php?page=mail&tab=conversation&inquiry_id=' . $related_id);
                                    } elseif ($thread_key !== '') {
                                        $conv_url = todo_admin_url('index.php?page=mail&tab=conversation&thread=' . urlencode($thread_key));
                                    }
                                    ?>
                                    <td><span class="tb-description" style="white-space: nowrap;"><?php echo esc_html(($direction === 'in' ? '收' : '发') . '｜' . $addr); ?></span></td>
                                    <td style="word-break: break-all;">
                                        <?php if ($conv_url !== ''): ?>
                                            <a href="<?php echo $conv_url; ?>"><?php echo esc_html($log->subject ?? ''); ?></a>
                                        <?php else: ?>
                                            <span class="tb-description"><?php echo esc_html($log->subject ?? ''); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $log_status = $log->status ?? ''; ?>
                                        <?php if ($log_status === 'sent'): ?>
                                            <span class="status-active">成功</span>
                                        <?php elseif ($log_status === 'failed'): ?>
                                            <span class="status-reject">失败</span>
                                        <?php elseif ($log_status === 'received'): ?>
                                            <span class="status-pending">收到</span>
                                        <?php else: ?>
                                            <span class="status-inactive">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="tb-meta"><?php echo esc_html($log->error_message ?? ''); ?></span></td>
                                    <td><span class="tb-description"><?php echo esc_html(($log->received_at ?? '') !== '' ? $log->received_at : ($log->sent_at ?? '')); ?></span></td>
                                </tr>
                            <?php endforeach; else: ?>
                            <tr class="empty">
                                <td colspan="6">
                                    <div class="no-items">
                                        <p>暂无日志。</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- 分页 -->
                <?php if ($total_pages > 1): ?>
                    <?php
                    $pagination_add_args = [];
                    if (!empty($logs_search)) $pagination_add_args['search'] = $logs_search;
                    if (!empty($logs_status)) $pagination_add_args['status'] = $logs_status;
                    if (!empty($logs_direction)) $pagination_add_args['direction'] = $logs_direction;
                    if (!empty($logs_range)) $pagination_add_args['range'] = $logs_range;
                    if (!empty($logs_date_from)) $pagination_add_args['date_from'] = $logs_date_from;
                    if (!empty($logs_date_to)) $pagination_add_args['date_to'] = $logs_date_to;
                    if (!empty($logs_orderby)) $pagination_add_args['orderby'] = $logs_orderby;
                    if (!empty($logs_order)) $pagination_add_args['order'] = strtolower($logs_order);
                    echo todo_render_pagination(array(
                        'total_items' => $total_logs,
                        'per_page' => $per_page,
                        'current_page' => $page_num,
                        'base_url' => todo_admin_url('index.php?page=mail&tab=logs'),
                        'page_param' => 'p',
                        'add_args' => $pagination_add_args,
                        'show_count' => true,
                    ));
                    ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- 发送测试 -->
    <?php elseif ($active_tab === 'send_test'): ?>
        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">发送测试邮件</h3>
            </div>
            <div class="admin-card-content">
                <form method="post" class="todo-request">
                    <input type="hidden" name="_todononce" value="<?php echo todo_create_nonce('mail_settings_nonce'); ?>">

                    <div class="form-group">
                        <label for="test_email">测试收件人 <span class="required">*</span></label>
                        <input type="email" name="test_email" id="test_email" class="regular-text" required>
                    </div>

                    <div class="form-group">
                        <label for="template_slug">选择模板 <span class="required">*</span></label>
                        <select name="template_slug" id="template_slug" class="regular-select">
                            <option value="">-- 请选择模板 --</option>
                            <?php
                            $all_templates = $tododb->get_results("SELECT slug, name FROM {$tododb->prefix}email_templates ORDER BY name");
                            foreach ($all_templates as $t): ?>
                                <option value="<?php echo esc_attr($t->slug); ?>"><?php echo esc_html($t->name); ?>
                                    (<?php echo esc_html($t->slug); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <p class="description">注：测试邮件将使用模拟变量数据发送（username: TestUser, code: 123456）。</p>

                    <div class="form-submit">
                        <button type="submit" class="button button-primary"><span class="toicon" style="transform: rotate(316deg); margin-top: -4px;">➤</span> 发送测试邮件</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- UEditor 资源 -->
<?php if (in_array($active_tab, ['templates', 'drafts']) && ($action === 'edit' || $action === 'new')): ?>
    <script type="text/javascript">
        // 兜底定义 TodoDOM 和 TodoCMS，确保媒体组件在 iframe 中正常运行
        window.TodoDOM = window.TodoDOM || {
            get: function(id) { return document.getElementById(id); }
        };
        window.TodoCMS = window.TodoCMS || {};
        window.TodoCMS.Utils = window.TodoCMS.Utils || {
            showMessage: function(msg, type) {
                if (typeof TodoModal !== 'undefined' && typeof TodoModal.toast === 'function') {
                    TodoModal.toast(msg, type || 'info');
                }
            }
        };
    </script>
    <?php
    // 引入并渲染媒体模态框组件，用于 UEditor 的多图上传功能
    require_once ADMIN_DIR . '/components/media-modal.php';
    echo TodoMediaModal::render('mail-media-modal', ['multiple' => true]);
    ?>

    <script type="text/javascript">
        // 修复 UEditor 路径中可能包含查询字符串导致语言文件加载失败的问题
        var ueditorHome = "<?php echo todo_admin_url('assets/js/ueditor/'); ?>";
        window.UEDITOR_HOME_URL = ueditorHome.split('?')[0];
    </script>
    <script type="text/javascript" src="<?php echo todo_admin_url('assets/js/ueditor/ueditor.config.js'); ?>"></script>
    <script type="text/javascript" src="<?php echo todo_admin_url('assets/js/ueditor/ueditor.all.js'); ?>"></script>
    <script type="text/javascript">
        // 修复iframe跨框架调用时UEditor的Mask组件访问错误
        (function() {
            var protectUEditorUI = function() {
                try {
                    if (window.UE && window.UE.ui && window.UE.ui.Mask) {
                        var originalIsHidden = window.UE.ui.Mask.prototype.isHidden;
                        if (originalIsHidden && !originalIsHidden._protected) {
                            window.UE.ui.Mask.prototype.isHidden = function() {
                                try {
                                    if (this.getDom && typeof this.getDom === 'function') {
                                        var dom = this.getDom();
                                        if (!dom) return true;
                                    }
                                    return originalIsHidden.call(this);
                                } catch (e) { return true; }
                            };
                            window.UE.ui.Mask.prototype.isHidden._protected = true;
                        }
                    }
                    if (window.baidu && window.baidu.editor && window.baidu.editor.ui && window.baidu.editor.ui.Mask) {
                        var baiduOriginalIsHidden = window.baidu.editor.ui.Mask.prototype.isHidden;
                        if (baiduOriginalIsHidden && !baiduOriginalIsHidden._protected) {
                            window.baidu.editor.ui.Mask.prototype.isHidden = function() {
                                try {
                                    if (this.getDom && typeof this.getDom === 'function') {
                                        var dom = this.getDom();
                                        if (!dom) return true;
                                    }
                                    return baiduOriginalIsHidden.call(this);
                                } catch (e) { return true; }
                            };
                            window.baidu.editor.ui.Mask.prototype.isHidden._protected = true;
                        }
                    }
                } catch (e) {}
            };
            protectUEditorUI();
            setTimeout(protectUEditorUI, 50);
            setTimeout(protectUEditorUI, 200);
            setTimeout(protectUEditorUI, 1000);
        })();

        // 兜底定义 $EDITORUI
        if (typeof window.$EDITORUI === 'undefined') {
            window.$EDITORUI = {
                setHover: function(el) {
                    if (el && el.className && el.className.indexOf("hover") == -1) {
                        el.className += " hover";
                    }
                },
                setOut: function(el) {
                    if (el && el.className && el.className.indexOf("hover") != -1) {
                        el.className = el.className.replace(" hover", "");
                    }
                }
            };
        }

        // 定义 OpenImagesList 以集成媒体库
        window.OpenImagesList = function(e, el) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            
            // 优先使用 TodoCMS 媒体库组件
            if (typeof window.selectMediaForField === 'function') {
                window.selectMediaForField({
                    title: '选择图片',
                    button_text: '插入图片',
                    multiple: true,
                    type: 'image',
                    modal_id: 'mail-media-modal',
                    callback: function(selectedMedia) {
                        if (typeof UE !== 'undefined') {
                            var editor = UE.getEditor('editor');
                            if (editor) {
                                var mediaList = Array.isArray(selectedMedia) ? selectedMedia : [selectedMedia];
                                var html = '';
                                mediaList.forEach(function(media) {
                                    if (media && media.url) {
                                        var url = media.url;
                                        if (url && !/^(https?:)?\/\//i.test(url) && /^data:/i.test(url) === false) {
                                            var siteUrl = '<?php echo esc_js(rtrim(function_exists("todo_get_site_url") ? todo_get_site_url() : "", "/")); ?>';
                                            if (!siteUrl && typeof window.TODO !== 'undefined' && window.TODO.SITE_ROOT) {
                                                siteUrl = window.TODO.SITE_ROOT.replace(/\/+$/, '');
                                            }
                                            if (!siteUrl) {
                                                var metaRoot = document.querySelector('meta[name="site-root"]');
                                                if (metaRoot) siteUrl = (metaRoot.getAttribute('content') || '').replace(/\/+$/, '');
                                            }
                                            if (!siteUrl) {
                                                var metaUrl = document.querySelector('meta[name="site-url"]');
                                                if (metaUrl) siteUrl = (metaUrl.getAttribute('content') || '').replace(/\/+$/, '');
                                            }
                                            if (!siteUrl) {
                                                siteUrl = window.location.origin;
                                            }
                                            if (url.indexOf('/') === 0) {
                                                var path = siteUrl.replace(/^https?:\/\/[^\/]+/i, '');
                                                if (path && url.indexOf(path) === 0) {
                                                    url = window.location.origin + url;
                                                } else {
                                                    url = siteUrl + url;
                                                }
                                            } else {
                                                url = siteUrl + '/' + url;
                                            }
                                        }
                                        var mime = (media.mime_type || '').toLowerCase();
                                        var filename = media.filename || media.title || url.substring(url.lastIndexOf('/') + 1) || '附件';
                                        var ext = url.substring(url.lastIndexOf('.') + 1).toLowerCase();
                                        
                                        if (mime.indexOf('image') === 0 || ['jpg','jpeg','png','gif','webp','svg','bmp'].indexOf(ext) !== -1) {
                                            html += '<img src="' + url + '" />';
                                        } else if (mime.indexOf('video') === 0 || ['mp4','webm','ogg','mov','avi','mkv'].indexOf(ext) !== -1) {
                                            html += '<video src="' + url + '" controls style="max-width: 330px;"></video>';
                                        } else {
                                            html += '<a href="' + url + '" target="_blank">' + filename + '</a>';
                                        }
                                    }
                                });
                                if (html) {
                                    editor.execCommand('insertHtml', html);
                                }
                            }
                        }
                    }
                });
            } else {
                // 降级方案：使用 UEditor 原生对话框
                if (typeof UE !== 'undefined') {
                    var editor = UE.getEditor('editor');
                    if (editor && editor.getDialog) {
                        editor.getDialog('insertimage').open();
                    }
                }
            }
        };

        // 延迟初始化编辑器
        setTimeout(function() {
            if (typeof UE !== 'undefined') {
                try {
                    UE.delEditor('editor');
                } catch (e) {}
                
                var ue = UE.getEditor('editor', {
                    initialFrameHeight: 400,
                    autoHeightEnabled: false,
                    autoFloatEnabled: false,
                    enableAutoSave: false,
                    zIndex: 900
                });
            }
        }, 200);

    </script>
<?php endif; ?>

<script>
(function () {
    function initBulkSelect(opts) {
        var checkAll  = document.getElementById(opts.checkAllId);
        var bulkBar   = document.getElementById(opts.barId);
        var countEl   = document.getElementById(opts.countId);
        var cancelBtn = document.getElementById(opts.cancelId);
        var deleteBtn = document.getElementById(opts.deleteId);
        var form      = document.getElementById(opts.formId);

        if (!checkAll || !bulkBar || !form) return;

        function getChecks() {
            return Array.prototype.slice.call(
                form.querySelectorAll('input.js-mail-row-check')
            );
        }

        function getVisibleChecks() {
            return getChecks().filter(function (cb) {
                var tr = cb.closest('tr');
                return tr && tr.style.display !== 'none';
            });
        }

        function updateBar() {
            var checked = getVisibleChecks().filter(function (cb) { return cb.checked; });
            var total   = getVisibleChecks().length;
            var n = checked.length;

            if (countEl) countEl.textContent = n;
            if (bulkBar) bulkBar.style.display = n > 0 ? 'flex' : 'none';

            // 更新全选框三态
            if (n === 0) {
                checkAll.checked = false;
                checkAll.indeterminate = false;
            } else if (n === total) {
                checkAll.checked = true;
                checkAll.indeterminate = false;
            } else {
                checkAll.checked = false;
                checkAll.indeterminate = true;
            }
        }

        // 全选/取消全选
        checkAll.addEventListener('change', function () {
            getVisibleChecks().forEach(function (cb) {
                cb.checked = checkAll.checked;
            });
            updateBar();
        });

        // 单行 checkbox 联动
        form.addEventListener('change', function (e) {
            if (e.target && e.target.classList.contains('js-mail-row-check')) {
                updateBar();
            }
        });

        // 取消选择
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                checkAll.checked = false;
                checkAll.indeterminate = false;
                getChecks().forEach(function (cb) { cb.checked = false; });
                updateBar();
            });
        }

        // 批量删除
        if (deleteBtn) {
            deleteBtn.addEventListener('click', function () {
                var n = getVisibleChecks().filter(function (cb) { return cb.checked; }).length;
                if (n === 0) { return; }
                var msg = '确定要删除选中的 ' + n + ' 项吗？此操作不可撤销。';
                var doSubmit = function () { form.submit(); };
                if (window.TodoCMS && TodoCMS.Utils && typeof TodoCMS.Utils.confirm === 'function') {
                    TodoCMS.Utils.confirm(msg, doSubmit);
                } else if (window.confirm(msg)) {
                    doSubmit();
                }
            });
        }

        // 初始化（页面已有选中状态时同步）
        updateBar();
    }

    document.addEventListener('DOMContentLoaded', function () {
        // 模板列表
        initBulkSelect({
            checkAllId: 'js-mail-check-all-templates',
            barId:      'js-mail-bulk-bar-templates',
            countId:    'js-mail-bulk-count-templates',
            cancelId:   'js-mail-bulk-cancel-templates',
            deleteId:   'js-mail-bulk-delete-templates',
            formId:     'js-mail-batch-form-templates'
        });
        // 草稿列表
        initBulkSelect({
            checkAllId: 'js-mail-check-all-drafts',
            barId:      'js-mail-bulk-bar-drafts',
            countId:    'js-mail-bulk-count-drafts',
            cancelId:   'js-mail-bulk-cancel-drafts',
            deleteId:   'js-mail-bulk-delete-drafts',
            formId:     'js-mail-batch-form-drafts'
        });
    });
})();
</script>
