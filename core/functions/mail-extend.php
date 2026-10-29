<?php
/**
 * 邮件扩展函数
 *
 * @package TodoCMS
 */
/**
 * 获取邮件模板
 *
 * @param string $slug 模板标识
 * @return object|false 模板对象或false
 */
function todo_get_email_template($slug) {
    global $tododb;
    $tpl = $tododb->get_row("SELECT * FROM {$tododb->prefix}email_templates WHERE slug = ?", [$slug]);
    if (!$tpl) {
        $tpl = $tododb->get_row("SELECT * FROM {$tododb->prefix}email_templates WHERE category = ? ORDER BY id DESC LIMIT 1", [$slug]);
    }
    return $tpl;
}
/**
 * 检查是否应该发送管理员通知邮件
 * 
 * @param string $notification_type 通知类型（如 'guestbook', 'register' 等）
 * @return bool 是否应该发送
 */
function todo_should_send_admin_notification($notification_type) {
    // 获取管理员邮箱
    $admin_email = CMS_Option::get('mail_admin_email', '');
    if (empty($admin_email)) {
        return false; // 没有设置管理员邮箱，不发送
    }
    
    // 获取启用的通知选项
    $notifications = CMS_Option::get('mail_notification_options', []);
    if (!is_array($notifications)) {
        return false;
    }
    
    // 兼容旧版 admin_login 选项，对应新的 admin_login_notify
    if ($notification_type === 'admin_login_notify' && in_array('admin_login', $notifications)) {
        return true;
    }
    
    // 如果通知类型在启用列表中，则发送
    return in_array($notification_type, $notifications);
}
/**
 * 发送管理员通知邮件
 * 
 * @param string $notification_type 通知类型
 * @param string $template_slug 模板标识
 * @param array $data 变量替换数据
 * @return bool 是否发送成功
 */
function todo_send_admin_notification($notification_type, $template_slug, $data = []) {
    // 检查是否应该发送
    if (!todo_should_send_admin_notification($notification_type)) {
        return false; // 该类型的通知未启用，不发送
    }
    
    // 获取管理员邮箱
    $admin_email = CMS_Option::get('mail_admin_email', '');
    if (empty($admin_email)) {
        return false;
    }
    
    // 支持多个邮箱（逗号分隔）
    $emails = array_map('trim', explode(',', $admin_email));
    $success = true;
    
    foreach ($emails as $email) {
        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $result = todo_send_template_mail($email, $template_slug, $data);
            if (!$result) {
                $success = false;
            }
        }
    }
    
    return $success;
}
/**
 * 发送模板邮件
 *
 * @param string $to 收件人
 * @param string $slug 模板标识
 * @param array $data 变量替换数据 ['username' => 'test']
 * @param bool $check_notification 是否检查通知选项（默认false，直接发送）
 * @return bool 是否发送成功
 */
function todo_mail_inject_preview_text($content, $preview_text)
{
    $preview_text = trim((string) $preview_text);
    if ($preview_text === '') {
        return $content;
    }
    $safe_preview = htmlspecialchars($preview_text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $pad = str_repeat('&#8203;', 120);
    $preheader = '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all;">' . $safe_preview . $pad . '</div>';
    if (is_string($content) && preg_match('/<body\b[^>]*>/i', $content)) {
        return preg_replace_callback('/<body\b[^>]*>/i', function ($m) use ($preheader) {
            return $m[0] . $preheader;
        }, $content, 1);
    }
    return $preheader . (string) $content;
}
function todo_parse_multilang_title($title)
{
    $title = (string) $title;
    $base = trim(preg_replace('/\[[^\]]*\]/', '', $title));
    $map = [];
    if (preg_match_all('/\[([a-zA-Z0-9_-]{1,20})\|([^\]]*)\]/', $title, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $lang = strtolower((string) ($m[1] ?? ''));
            $val = trim((string) ($m[2] ?? ''));
            if ($lang !== '' && $val !== '') {
                $map[$lang] = $val;
            }
        }
    }
    if ($base === '') {
        $base = trim($title);
    }
    return [$base, $map];
}
/**
 * 根据目标语言提取文本中的多语言标签块 [en-US|Text][zh-CN|文本] 或 默认中文[en|Text]
 * 
 * @param string $text 包含多语言标签的字符串
 * @param string $target_lang 目标语言代码
 * @return string
 */
function todo_resolve_multilang_text($text, $target_lang) {
    $text = (string) $text;
    if ($text === '' || strpos($text, '[') === false) {
        return $text;
    }

    $target_lang = strtolower(str_replace('_', '-', trim((string) $target_lang)));
    if ($target_lang === '') {
        $target_lang = function_exists('get_default_language') ? get_default_language() : 'en';
    }
    $target_lang = strtolower(str_replace('_', '-', $target_lang));
    $short_lang = explode('-', $target_lang)[0];

    $def_lang = function_exists('get_default_language') ? get_default_language() : 'en';
    $def_lang = strtolower(str_replace('_', '-', $def_lang));
    $def_short = explode('-', $def_lang)[0];

    // 提取所有 [lang|val] 标签进行精确语言匹配
    if (preg_match_all('/\[([a-zA-Z0-9_-]{1,15})\|([^\]]*)\]/s', $text, $matches, PREG_SET_ORDER)) {
        $tag_map = [];
        foreach ($matches as $m) {
            $t_lang = strtolower(str_replace('_', '-', trim($m[1])));
            $t_short = explode('-', $t_lang)[0];
            $val = $m[2];
            $tag_map[$t_lang] = $val;
            if (!isset($tag_map[$t_short])) {
                $tag_map[$t_short] = $val;
            }
        }

        $matched_val = $tag_map[$target_lang] ?? ($tag_map[$short_lang] ?? null);
        $base_text = trim(preg_replace('/\[[a-zA-Z0-9_-]{1,15}\|[^\]]*\]/s', '', $text));

        // 如果目标语言在标签中有明确匹配
        if ($matched_val !== null) {
            // 如果除了标签外没有基础文本，或者基础文本是被标签覆盖的默认前缀/后缀
            if ($base_text === '' || preg_match('/^(?:[^\[\]]+\s*\[[a-zA-Z0-9_-]{1,15}\|.*\]|\[[a-zA-Z0-9_-]{1,15}\|.*\]\s*[^\[\]]+)$/s', trim($text))) {
                return $matched_val;
            }
        } else {
            // 目标语言没有对应 [lang|val] 标签且除了标签外没有基础文本 (如 "[en|My god][zh|风动文化]")
            if ($base_text === '') {
                $def_val = $tag_map[$def_lang] ?? ($tag_map[$def_short] ?? reset($tag_map));
                if ($def_val !== false && $def_val !== null) {
                    return $def_val;
                }
            }
        }
    }

    // 逐个/逐组替换多语言标签块（用于处理嵌入式如 "前缀 [zh|中文][en|English] 后缀" 或 默认文本[zh|中文] 当target为en时）
    $pattern = '/(?:\[[a-zA-Z0-9_-]{1,15}\|[^\]]*\])+/s';
    $result = preg_replace_callback($pattern, function($match) use ($target_lang, $short_lang) {
        $cluster = $match[0];
        if (preg_match_all('/\[([a-zA-Z0-9_-]{1,15})\|([^\]]*)\]/s', $cluster, $tags, PREG_SET_ORDER)) {
            $exact = null;
            $short = null;
            foreach ($tags as $idx => $t) {
                $l = strtolower(str_replace('_', '-', trim($t[1])));
                $s = explode('-', $l)[0];
                $v = $t[2];
                if ($l === $target_lang) { $exact = $v; break; }
                if ($s === $short_lang && $short === null) { $short = $v; }
            }

            if ($exact !== null) return $exact;
            if ($short !== null) return $short;
            
            // 目标语言在当前嵌入块中无匹配，清除该多语言标签块
            return '';
        }
        return $cluster;
    }, $text);

    return trim(preg_replace('/\s+/', ' ', $result));
}
function todo_resolve_sender_display_name($sender_name_input, $site_title, $target_lang = '')
{
    $sender_name_input = trim((string) $sender_name_input);
    $site_title = (string) $site_title;

    $def_lang = function_exists('get_default_language') ? get_default_language() : 'en';

    // 1. 如果 $sender_name_input 为空，使用 $site_title
    if ($sender_name_input === '') {
        $text = $site_title;
    }
    // 2. 如果 $sender_name_input 是语言选择标签（如 [zh] 或 [en]）
    elseif (preg_match('/^\[([a-zA-Z0-9_-]{1,20})\]$/', $sender_name_input, $m)) {
        $tag_lang = strtolower((string) ($m[1] ?? ''));
        if (empty($target_lang)) {
            $target_lang = $tag_lang;
        }
        $text = $site_title;
    }
    else {
        $text = $sender_name_input;
    }

    if (empty($target_lang)) {
        $target_lang = $def_lang;
    }

    return todo_resolve_multilang_text($text, $target_lang);
}
function todo_resolve_site_name_for_template($sender_name_input, $site_title, $target_lang = '')
{
    return todo_resolve_sender_display_name($sender_name_input, $site_title, $target_lang);
}
function todo_escape_template_text($value)
{
    $value = (string) $value;
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = strip_tags($value);
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function todo_mail_replace_template_var($text, $key, $value)
{
    $text = (string) $text;
    $key = (string) $key;
    if ($key === '') {
        return $text;
    }
    $value = (string) $value;
    $quoted = preg_quote($key, '/');
    $pattern = '/(?:\{|\x{FF5B})[\p{Cf}\s]*' . $quoted . '[\p{Cf}\s]*(?:\}|\x{FF5D})/u';
    $out = @preg_replace_callback($pattern, function () use ($value) {
        return $value;
    }, $text);
    if ($out === null) {
        return str_replace('{' . $key . '}', $value, $text);
    }
    return $out;
}
function todo_send_template_mail($to, $slug, $data = [], $check_notification = false, $meta = []) {
    $template = todo_get_email_template($slug);
    
    if (!$template) {
        todo_log_email($to, "Template: $slug", 'failed', 'Template not found');
        return false;
    }
    
    // 如果启用通知检查，且模板有分类，则检查通知选项
    if ($check_notification && !empty($template->category)) {
        if (!todo_should_send_admin_notification($template->category)) {
            // 该类型的通知未启用，不发送
            return false;
        }
    }
    
    $site_title = CMS_Option::get('site_title', 'TodoCMS');
    if (!is_array($data)) {
        $data = [];
    }
    if (!array_key_exists('site_name', $data) || trim((string) ($data['site_name'] ?? '')) === '') {
        $data['site_name'] = $site_title;
    }
    $subject = (isset($meta['subject']) && trim((string) $meta['subject']) !== '') ? trim((string) $meta['subject']) : $template->subject;
    $content = $template->content;
    $preview_text = isset($template->variables) ? trim((string) $template->variables) : '';
    
    // 解析目标语言代码
    $target_lang = $data['order_lang'] ?? ($meta['order_lang'] ?? ($data['lang'] ?? ($data['language'] ?? '')));
    
    // 如果存在目标语言，对 $data 中的标量变量先进行多语言解析（如 site_name、status 等）
    if (!empty($target_lang) && function_exists('todo_resolve_multilang_text')) {
        foreach ($data as $k => $v) {
            if (is_scalar($v)) {
                $data[$k] = todo_resolve_multilang_text((string) $v, $target_lang);
            }
        }
    }

    // 替换变量（做两轮替换，确保嵌套在 {content} 或 {reply_content} 内的变量能被完整解析）
    for ($pass = 0; $pass < 2; $pass++) {
        foreach ($data as $key => $value) {
            $value = is_scalar($value) || (is_object($value) && method_exists($value, '__toString')) ? (string) $value : '';
            $subject = todo_mail_replace_template_var($subject, $key, $value);
            $content = todo_mail_replace_template_var($content, $key, $value);
            if ($preview_text !== '') {
                $preview_text = todo_mail_replace_template_var($preview_text, $key, $value);
            }
        }
    }
    
    // 解析主题、内容及预览文案中的多语言标签块
    if (!empty($target_lang) && function_exists('todo_resolve_multilang_text')) {
        $subject = todo_resolve_multilang_text($subject, $target_lang);
        $content = todo_resolve_multilang_text($content, $target_lang);
        if ($preview_text !== '') {
            $preview_text = todo_resolve_multilang_text($preview_text, $target_lang);
        }
    }
    
    $content = todo_mail_inject_preview_text($content, $preview_text);
    
    if (!is_array($meta)) {
        $meta = [];
    }
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    // 设置发件人
    $smtp_user = CMS_Option::get('mail_smtp_user');
    if (!empty($smtp_user)) {
        $sender_name = todo_resolve_sender_display_name($template->sender_name ?? '', $site_title, $target_lang);
        $sender_name = trim((string) $sender_name);
        if ($sender_name !== '') {
            $encoded_name = '=?UTF-8?B?' . base64_encode($sender_name) . '?=';
            $headers[] = "From: $encoded_name <$smtp_user>";
        }
    }
    $message_id = isset($meta['message_id']) ? trim((string) $meta['message_id']) : '';
    if ($message_id === '') {
        $message_id = todo_generate_message_id();
    }
    if ($message_id !== '') {
        $headers[] = 'Message-ID: ' . $message_id;
    }
    $thread_key = isset($meta['thread_key']) ? trim((string) $meta['thread_key']) : '';
    if ($thread_key !== '') {
        $headers[] = 'X-Todo-Thread-Key: ' . $thread_key;
    }
    $related_type = isset($meta['related_type']) ? trim((string) $meta['related_type']) : '';
    $related_id = isset($meta['related_id']) ? intval($meta['related_id']) : 0;
    if ($related_type !== '') {
        $headers[] = 'X-Todo-Related-Type: ' . $related_type;
    }
    if ($related_id > 0) {
        $headers[] = 'X-Todo-Related-Id: ' . $related_id;
    }
    $in_reply_to = isset($meta['in_reply_to']) ? trim((string) $meta['in_reply_to']) : '';
    if ($in_reply_to !== '' && function_exists('todo_imap_normalize_msgid')) {
        $in_reply_to = todo_imap_normalize_msgid($in_reply_to);
    }
    $references_line = '';
    if ($in_reply_to !== '') {
        $refs_raw = isset($meta['references']) ? $meta['references'] : '';
        $refs_list = [];
        if (is_array($refs_raw)) {
            $refs_list = $refs_raw;
        } else {
            $refs_list = preg_split('/\s+/', trim((string) $refs_raw)) ?: [];
        }
        $refs = [];
        foreach ($refs_list as $r) {
            $r = trim((string) $r);
            if ($r === '') continue;
            if (function_exists('todo_imap_normalize_msgid')) {
                $r = todo_imap_normalize_msgid($r);
            }
            if ($r !== '') $refs[] = $r;
        }
        $refs[] = $in_reply_to;
        $refs = array_values(array_unique(array_filter(array_map('trim', $refs))));
        $references_line = !empty($refs) ? implode(' ', $refs) : '';
        $headers[] = 'In-Reply-To: ' . $in_reply_to;
        if ($references_line !== '') {
            $headers[] = 'References: ' . $references_line;
        }
    }
    $thread_index = isset($meta['thread_index']) ? trim((string) $meta['thread_index']) : '';
    $thread_topic = isset($meta['thread_topic']) ? trim((string) $meta['thread_topic']) : '';
    $conversation_index = isset($meta['conversation_index']) ? trim((string) $meta['conversation_index']) : '';
    $conversation_topic = isset($meta['conversation_topic']) ? trim((string) $meta['conversation_topic']) : '';
    if ($thread_key !== '' && ($thread_index === '' || $thread_topic === '' || $conversation_index === '' || $conversation_topic === '')) {
        try {
            global $tododb;
            $table = is_object($tododb) && isset($tododb->prefix) ? ($tododb->prefix . 'email_logs') : '';
            if ($table !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                $cols = todo_ensure_email_logs_schema($table, $tododb);
                $sel = ['id'];
                if (in_array('thread_index', $cols, true)) $sel[] = 'thread_index';
                if (in_array('thread_topic', $cols, true)) $sel[] = 'thread_topic';
                if (in_array('conversation_index', $cols, true)) $sel[] = 'conversation_index';
                if (in_array('conversation_topic', $cols, true)) $sel[] = 'conversation_topic';
                $conds = [];
                if (in_array('thread_index', $cols, true)) $conds[] = "COALESCE(thread_index,'') <> ''";
                if (in_array('conversation_index', $cols, true)) $conds[] = "COALESCE(conversation_index,'') <> ''";
                if (in_array('thread_topic', $cols, true)) $conds[] = "COALESCE(thread_topic,'') <> ''";
                if (in_array('conversation_topic', $cols, true)) $conds[] = "COALESCE(conversation_topic,'') <> ''";
                $where_extra = !empty($conds) ? (' AND (' . implode(' OR ', $conds) . ')') : '';
                $last = $tododb->get_row("SELECT " . implode(',', $sel) . " FROM `{$table}` WHERE thread_key = ?{$where_extra} ORDER BY id DESC LIMIT 1", [$thread_key]);
                if ($last) {
                    if ($thread_index === '' && isset($last->thread_index)) {
                        $thread_index = trim((string) $last->thread_index);
                    }
                    if ($thread_topic === '' && isset($last->thread_topic)) {
                        $thread_topic = trim((string) $last->thread_topic);
                    }
                    if ($conversation_index === '' && isset($last->conversation_index)) {
                        $conversation_index = trim((string) $last->conversation_index);
                    }
                    if ($conversation_topic === '' && isset($last->conversation_topic)) {
                        $conversation_topic = trim((string) $last->conversation_topic);
                    }
                    if ($thread_index !== '') {
                        $thread_index = todo_outlook_thread_index_child($thread_index);
                    }
                    if ($conversation_index !== '') {
                        $conversation_index = todo_outlook_thread_index_child($conversation_index);
                    }
                }
            }
        } catch (Throwable $e) {
        }
    }
    if ($thread_key !== '' && $thread_index === '') {
        $thread_index = todo_outlook_thread_index_root();
    }
    if ($thread_key !== '' && $conversation_index === '') {
        $conversation_index = $thread_index;
    }
    if ($thread_topic === '') {
        $topic = trim((string) $subject);
        $loop = 0;
        while ($loop < 6) {
            $prev = $topic;
            $topic = preg_replace('/^\s*(re|fw|fwd)\s*:\s*/i', '', $topic);
            $topic = preg_replace('/^\s*(回复|答复)\s*:\s*/u', '', $topic);
            $topic = trim((string) $topic);
            if ($topic === $prev) break;
            $loop++;
        }
        $thread_topic = $topic;
    }
    if ($conversation_topic === '') {
        $conversation_topic = $thread_topic;
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
    $result = todo_mail($to, $subject, $content, $headers);
    
    // 记录日志
    if ($result) {
        todo_log_email($to, $subject, 'sent', '', [
            'content' => $content,
            'type' => isset($meta['type']) ? (string) $meta['type'] : 'notification',
            'direction' => 'out',
            'from_email' => (string) ($smtp_user ?: ''),
            'message_id' => $message_id,
            'in_reply_to' => $in_reply_to,
            'references' => $references_line,
            'thread_index' => $thread_index,
            'thread_topic' => $thread_topic,
            'conversation_index' => $conversation_index,
            'conversation_topic' => $conversation_topic,
            'thread_key' => $thread_key,
            'related_type' => $related_type,
            'related_id' => $related_id,
            'is_read' => 1,
        ]);
    } else {
        todo_log_email($to, $subject, 'failed', 'Mail function returned false', [
            'content' => $content,
            'type' => isset($meta['type']) ? (string) $meta['type'] : 'notification',
            'direction' => 'out',
            'from_email' => (string) ($smtp_user ?: ''),
            'message_id' => $message_id,
            'in_reply_to' => $in_reply_to,
            'references' => $references_line,
            'thread_index' => $thread_index,
            'thread_topic' => $thread_topic,
            'conversation_index' => $conversation_index,
            'conversation_topic' => $conversation_topic,
            'thread_key' => $thread_key,
            'related_type' => $related_type,
            'related_id' => $related_id,
            'is_read' => 1,
        ]);
    }
    
    return $result;
}
/**
 * 记录邮件日志
 *
 * @param string $recipient 收件人
 * @param string $subject 主题
 * @param string $status 状态
 * @param string $error_message 错误信息
 */
function todo_generate_message_id()
{
    $host = '';
    if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])) {
        $host = trim($_SERVER['HTTP_HOST']);
    }
    if ($host === '' && function_exists('get_option')) {
        $site_url = (string) get_option('site_url', '');
        if ($site_url !== '') {
            $host = (string) parse_url($site_url, PHP_URL_HOST);
        }
    }
    $host = preg_replace('/[^a-zA-Z0-9.-]/', '', (string) $host);
    if ($host === '') {
        $host = 'localhost';
    }
    try {
        $rand = bin2hex(random_bytes(10));
    } catch (Throwable $e) {
        $rand = bin2hex((string) uniqid('', true));
        $rand = preg_replace('/[^a-f0-9]/', '', (string) $rand);
        if ($rand === '') {
            $rand = md5((string) microtime(true));
        }
    }
    return '<todo.' . $rand . '.' . time() . '@' . $host . '>';
}
function todo_ensure_email_logs_schema($table, $db = null)
{
    if (!is_string($table) || $table === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return [];
    }
    if ($db === null) {
        global $tododb;
        $db = $tododb ?? null;
    }
    if (!is_object($db)) {
        return [];
    }
    static $ensured = [];
    if (isset($ensured[$table]) && is_array($ensured[$table]) && !empty($ensured[$table])) {
        return $ensured[$table];
    }
    $cols = [];
    try {
        if ($db instanceof PDO) {
            $stmt = $db->query("SHOW COLUMNS FROM `{$table}`");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            if ($stmt) {
                try {
                    $stmt->closeCursor();
                } catch (Throwable $e) {
                }
            }
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    if (is_array($r) && isset($r['Field'])) {
                        $cols[] = (string) $r['Field'];
                    }
                }
            }
        } elseif (method_exists($db, 'get_results')) {
            $rows = $db->get_results("SHOW COLUMNS FROM `{$table}`");
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    if (is_object($r) && isset($r->Field)) {
                        $cols[] = (string) $r->Field;
                    } elseif (is_array($r) && isset($r['Field'])) {
                        $cols[] = (string) $r['Field'];
                    }
                }
            }
        } else {
            $ensured[$table] = [];
            return [];
        }
    } catch (Throwable $e) {
        $ensured[$table] = [];
        return [];
    }
    $want = [
        'direction' => "ALTER TABLE `{$table}` ADD COLUMN direction varchar(10) NOT NULL DEFAULT 'out'",
        'from_email' => "ALTER TABLE `{$table}` ADD COLUMN from_email varchar(255) NOT NULL DEFAULT ''",
        'from_name' => "ALTER TABLE `{$table}` ADD COLUMN from_name varchar(100) DEFAULT ''",
        'message_id' => "ALTER TABLE `{$table}` ADD COLUMN message_id varchar(255) DEFAULT NULL",
        'in_reply_to' => "ALTER TABLE `{$table}` ADD COLUMN in_reply_to varchar(255) DEFAULT NULL",
        'references' => "ALTER TABLE `{$table}` ADD COLUMN `references` text DEFAULT NULL",
        'thread_index' => "ALTER TABLE `{$table}` ADD COLUMN thread_index varchar(255) DEFAULT NULL",
        'thread_topic' => "ALTER TABLE `{$table}` ADD COLUMN thread_topic varchar(255) DEFAULT NULL",
        'conversation_index' => "ALTER TABLE `{$table}` ADD COLUMN conversation_index text DEFAULT NULL",
        'conversation_topic' => "ALTER TABLE `{$table}` ADD COLUMN conversation_topic varchar(255) DEFAULT NULL",
        'related_type' => "ALTER TABLE `{$table}` ADD COLUMN related_type varchar(50) NOT NULL DEFAULT ''",
        'related_id' => "ALTER TABLE `{$table}` ADD COLUMN related_id int(11) NOT NULL DEFAULT 0",
        'thread_key' => "ALTER TABLE `{$table}` ADD COLUMN thread_key varchar(100) NOT NULL DEFAULT ''",
        'imap_uid' => "ALTER TABLE `{$table}` ADD COLUMN imap_uid int(11) NOT NULL DEFAULT 0",
        'imap_mailbox' => "ALTER TABLE `{$table}` ADD COLUMN imap_mailbox varchar(100) NOT NULL DEFAULT ''",
        'received_at' => "ALTER TABLE `{$table}` ADD COLUMN received_at datetime NULL",
        'synced_at' => "ALTER TABLE `{$table}` ADD COLUMN synced_at datetime NULL",
        'is_read' => "ALTER TABLE `{$table}` ADD COLUMN is_read tinyint(1) NOT NULL DEFAULT 0",
        'has_reply' => "ALTER TABLE `{$table}` ADD COLUMN has_reply tinyint(1) NOT NULL DEFAULT 0",
        'replied_at' => "ALTER TABLE `{$table}` ADD COLUMN replied_at datetime NULL",
    ];
    foreach ($want as $col => $sql) {
        if (!in_array($col, $cols, true)) {
            try {
                $ok = false;
                if ($db instanceof PDO) {
                    $ok = $db->exec($sql);
                } elseif (method_exists($db, 'query')) {
                    $ok = $db->query($sql);
                }
                if ($ok !== false) {
                    $cols[] = $col;
                }
            } catch (Throwable $e) {
            }
        }
    }
    $existing_indexes = [];
    try {
        if ($db instanceof PDO) {
            $stmt = $db->query("SHOW INDEX FROM `{$table}`");
            $idx_rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            if ($stmt) {
                try {
                    $stmt->closeCursor();
                } catch (Throwable $e) {
                }
            }
            if (is_array($idx_rows)) {
                foreach ($idx_rows as $r) {
                    if (is_array($r) && isset($r['Key_name'])) {
                        $existing_indexes[(string) $r['Key_name']] = true;
                    }
                }
            }
        } elseif (method_exists($db, 'get_results')) {
            $idx_rows = $db->get_results("SHOW INDEX FROM `{$table}`");
            if (is_array($idx_rows)) {
                foreach ($idx_rows as $r) {
                    if (is_object($r) && isset($r->Key_name)) {
                        $existing_indexes[(string) $r->Key_name] = true;
                    } elseif (is_array($r) && isset($r['Key_name'])) {
                        $existing_indexes[(string) $r['Key_name']] = true;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $existing_indexes = [];
    }
    $index_sqls = [];
    if (in_array('message_id', $cols, true)) {
        if (!isset($existing_indexes['idx_message_id'])) {
            $index_sqls[] = "ALTER TABLE `{$table}` ADD INDEX idx_message_id (message_id(191))";
        }
    }
    if (in_array('thread_key', $cols, true)) {
        if (!isset($existing_indexes['idx_thread_key'])) {
            $index_sqls[] = "ALTER TABLE `{$table}` ADD INDEX idx_thread_key (thread_key(50))";
        }
    }
    if (in_array('related_type', $cols, true) && in_array('related_id', $cols, true)) {
        if (!isset($existing_indexes['idx_related'])) {
            $index_sqls[] = "ALTER TABLE `{$table}` ADD INDEX idx_related (related_type(50), related_id)";
        }
    }
    if (in_array('imap_uid', $cols, true) && in_array('imap_mailbox', $cols, true)) {
        if (!isset($existing_indexes['idx_imap'])) {
            $index_sqls[] = "ALTER TABLE `{$table}` ADD INDEX idx_imap (imap_mailbox(50), imap_uid)";
        }
    }
    foreach ($index_sqls as $sql) {
        try {
            if ($db instanceof PDO) {
                $db->exec($sql);
            } elseif (method_exists($db, 'query')) {
                $db->query($sql);
            }
        } catch (Throwable $e) {
        }
    }
    $ensured[$table] = $cols;
    return $cols;
}
function todo_log_email($recipient, $subject, $status, $error_message = '', $extra = []) {
    global $tododb;
    try {
        $db = $tododb ?? null;
        $prefix = '';
        if (is_object($db) && isset($db->prefix)) {
            $prefix = (string) $db->prefix;
        }
        if ($prefix === '' && defined('DB_PREFIX')) {
            $prefix = (string) DB_PREFIX;
        }
        if ($prefix === '' && defined('TABLE_PREFIX')) {
            $prefix = (string) TABLE_PREFIX;
        }
        if ($prefix === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
            $prefix = 'todo_';
        }
        $table = $prefix . 'email_logs';
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return;
        }
        $pdo = null;
        if ($db instanceof PDO) {
            $pdo = $db;
        } elseif (is_object($db) && method_exists($db, 'get_connection')) {
            $tmp = $db->get_connection();
            if ($tmp instanceof PDO) {
                $pdo = $tmp;
            }
        }
        static $cols_cache = [];
        if (!isset($cols_cache[$table]) || empty($cols_cache[$table])) {
            $cols_cache[$table] = todo_ensure_email_logs_schema($table, $db);
        }
        $cols = is_array($cols_cache[$table]) ? $cols_cache[$table] : [];
        if (empty($cols)) {
            return;
        }
        $now = function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s');
        $data = [
            'subject' => $subject,
            'status' => $status,
            'error_message' => $error_message,
            'sent_at' => $now,
            'content' => '',
            'type' => 'notification',
            'to_name' => '',
            'direction' => 'out',
            'from_email' => '',
            'from_name' => '',
            'message_id' => null,
            'in_reply_to' => null,
            'references' => null,
            'thread_index' => null,
            'thread_topic' => null,
            'conversation_index' => null,
            'conversation_topic' => null,
            'related_type' => '',
            'related_id' => 0,
            'thread_key' => '',
            'imap_uid' => 0,
            'imap_mailbox' => '',
            'received_at' => null,
            'synced_at' => $now,
            'is_read' => 1,
            'has_reply' => 0,
            'replied_at' => null,
        ];
        if (in_array('recipient', $cols, true)) {
            $data['recipient'] = $recipient;
        } elseif (in_array('to_email', $cols, true)) {
            $data['to_email'] = $recipient;
        }
        if (!is_array($extra)) {
            $extra = [];
        }
        foreach ($extra as $k => $v) {
            if (!is_string($k) || $k === '') {
                continue;
            }
            if (in_array($k, $cols, true)) {
                if ($k === 'references') {
                    if (is_array($v)) {
                        $v = implode(' ', array_filter(array_map('trim', array_map('strval', $v))));
                    }
                    $v = trim((string) $v);
                    $data[$k] = $v === '' ? null : $v;
                    continue;
                }
                if ($k === 'received_at' || $k === 'sent_at' || $k === 'synced_at' || $k === 'replied_at') {
                    $vv = trim((string) $v);
                    $data[$k] = $vv === '' ? null : $vv;
                    continue;
                }
                if ($k === 'related_id' || $k === 'imap_uid') {
                    $data[$k] = intval($v);
                    continue;
                }
                if ($k === 'is_read' || $k === 'has_reply') {
                    $data[$k] = intval($v) ? 1 : 0;
                    continue;
                }
                if ($k === 'message_id' || $k === 'in_reply_to') {
                    $vv = trim((string) $v);
                    $data[$k] = $vv === '' ? null : $vv;
                    continue;
                }
                $data[$k] = is_scalar($v) || $v === null ? $v : (string) $v;
            }
        }
        foreach (array_keys($data) as $k) {
            if (!in_array($k, $cols, true)) {
                unset($data[$k]);
            }
        }
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                $data[$k] = todo_to_utf8_string($v);
            }
        }
        if (empty($data) || (!isset($data['recipient']) && !isset($data['to_email']))) {
            return;
        }
        if (is_object($db) && method_exists($db, 'insert')) {
            $db->insert($table, $data);
            return;
        }
        if ($pdo instanceof PDO) {
            $cols = array_keys($data);
            $quoted_cols = array_map(static fn($c) => '`' . $c . '`', $cols);
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $sql = "INSERT INTO `{$table}` (" . implode(',', $quoted_cols) . ") VALUES ({$placeholders})";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($data));
            try {
                $stmt->closeCursor();
            } catch (Throwable $e) {
            }
        }
    } catch (Throwable $e) {
        return;
    }
}
function todo_imap_polling_enabled()
{
    return (int) CMS_Option::get('mail_imap_enabled', 0) === 1;
}
function todo_imap_mailbox_string($host, $port, $mailbox, $encryption, $validate_cert)
{
    $host = trim((string) $host);
    $mailbox = trim((string) $mailbox);
    $encryption = strtolower(trim((string) $encryption));
    $port = intval($port);
    if ($port <= 0) {
        $port = 993;
    }
    if ($mailbox === '') {
        $mailbox = 'INBOX';
    }
    $flags = '/imap';
    if ($encryption === 'ssl' || $port === 993) {
        $flags .= '/ssl';
    } elseif ($encryption === 'tls') {
        $flags .= '/tls';
    }
    if (!$validate_cert) {
        $flags .= '/novalidate-cert';
    }
    return '{' . $host . ':' . $port . $flags . '}' . $mailbox;
}
function todo_imap_decode_string($string, $charset)
{
    $charset = strtoupper(trim((string) $charset));
    if ($charset === '') {
        $charset = 'UTF-8';
    }
    $string = (string) $string;
    $decoded = @imap_utf8($string);
    if (is_string($decoded) && $decoded !== '') {
        $string = $decoded;
    }
    if ($charset !== 'UTF-8' && function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($string, 'UTF-8', $charset);
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }
    }
    return $string;
}
if (!function_exists('todo_to_utf8_string')) {
    function todo_to_utf8_string($string)
    {
        $string = (string) $string;
        if ($string === '') {
            return '';
        }
        $is_utf8 = false;
        if (function_exists('seems_utf8')) {
            $is_utf8 = seems_utf8($string);
        } else {
            $is_utf8 = @preg_match('//u', $string) === 1;
        }
        if ($is_utf8) {
            return $string;
        }
        if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
            $enc = @mb_detect_encoding($string, ['UTF-8', 'GB18030', 'GBK', 'BIG5', 'ISO-8859-1', 'Windows-1252'], true);
            if (is_string($enc) && $enc !== '' && strtoupper($enc) !== 'UTF-8') {
                $converted = @mb_convert_encoding($string, 'UTF-8', $enc);
                if (is_string($converted) && $converted !== '' && @preg_match('//u', $converted) === 1) {
                    return $converted;
                }
            }
            foreach (['GB18030', 'GBK', 'BIG5'] as $from) {
                $converted = @mb_convert_encoding($string, 'UTF-8', $from);
                if (is_string($converted) && $converted !== '' && @preg_match('//u', $converted) === 1) {
                    return $converted;
                }
            }
        }
        if (function_exists('iconv')) {
            foreach (['GB18030', 'GBK', 'BIG5'] as $from) {
                $converted = @iconv($from, 'UTF-8//IGNORE', $string);
                if (is_string($converted) && $converted !== '' && @preg_match('//u', $converted) === 1) {
                    return $converted;
                }
            }
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $string);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        if (function_exists('todo_check_invalid_utf8')) {
            $converted = todo_check_invalid_utf8($string, true);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        return '';
    }
}
if (!function_exists('todo_imap_maybe_decode_base64_text')) {
    function todo_imap_maybe_decode_base64_text($text)
    {
        $text = (string) $text;
        $trim = trim($text);
        if ($trim === '' || strlen($trim) < 40) {
            return $text;
        }
        if (@preg_match('/^[A-Za-z0-9+\/=\r\n\t ]+$/', $trim) !== 1) {
            return $text;
        }
        $compact = preg_replace('/\s+/', '', $trim);
        if ($compact === '' || strlen($compact) < 40) {
            return $text;
        }
        if (strlen($compact) % 4 !== 0) {
            return $text;
        }
        $decoded = base64_decode($compact, true);
        if (!is_string($decoded) || $decoded === '') {
            return $text;
        }
        $head = ltrim(substr($decoded, 0, 512));
        $head_lower = strtolower($head);
        if (strpos($head_lower, '<html') === 0 || strpos($head_lower, '<!doctype') === 0 || strpos($head_lower, '<body') !== false || strpos($head_lower, '<div') !== false) {
            return $decoded;
        }
        return $text;
    }
}
if (!function_exists('todo_mail_strip_reply_noise')) {
    function todo_mail_strip_reply_noise($text)
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }
        $lines = preg_split("/\R/u", $text);
        if (!is_array($lines) || empty($lines)) {
            return trim($text);
        }
        $is_noise_delimiter = static function ($line) {
            $line = trim((string) $line);
            if ($line === '') {
                return false;
            }
            if (@preg_match('/-{2,}.*(以下信息|下方信息|以下内容).*(屏蔽|不需要显示|不必显示|无需显示).*-{2,}/u', $line) === 1) {
                return true;
            }
            if (@preg_match('/^(以下信息|下方信息|以下内容).*(屏蔽|不需要显示|不必显示|无需显示)/u', $line) === 1) {
                return true;
            }
            return false;
        };
        $is_header_line = static function ($line) {
            $line = trim((string) $line);
            if ($line === '') {
                return false;
            }
            if (@preg_match('/^(发件人|发送时间|收件人|主题)\s*[:：]/u', $line) === 1) {
                return true;
            }
            if (@preg_match('/^(From|Sent|To|Subject)\s*:/i', $line) === 1) {
                return true;
            }
            return false;
        };
        $is_gmail_quote_header = static function ($line) {
            $line = trim((string) $line);
            if ($line === '') {
                return false;
            }
            if (@preg_match('/^On\s+.+\s+wrote:\s*$/i', $line) === 1) {
                return true;
            }
            if (@preg_match('/^在\s*.+\s*写道[:：]\s*$/u', $line) === 1) {
                return true;
            }
            if (@preg_match('/^.+\s*于\s*\d{4}\s*年\s*\d{1,2}\s*月\s*\d{1,2}\s*日.*写道[:：]\s*$/u', $line) === 1) {
                return true;
            }
            if (@preg_match('/^.+\s*于\s*.+\s*写道[:：]\s*$/u', $line) === 1) {
                return true;
            }
            if (@preg_match('/^[-\s]*Forwarded message[-\s]*$/i', $line) === 1) {
                return true;
            }
            if (@preg_match('/^[-\s]*Begin forwarded message[-\s]*$/i', $line) === 1) {
                return true;
            }
            return false;
        };

        $is_mobile_signature = static function ($line) {
            $line = trim((string) $line);
            if ($line === '') {
                return false;
            }
            if (@preg_match('/^(?:发自我的|Sent\s+from\s+my\s+)(?:iPhone|iPad|Mac|Android|Samsung|Huawei|Xiaomi)\b/i', $line) === 1) {
                return true;
            }
            if (@preg_match('/^(?:获取|Get)\s*Outlook\s*(?:for\s*(?:iOS|Android|Mac|Windows))?/i', $line) === 1) {
                return true;
            }
            if (@preg_match('/^Sent\s+from\s+Outlook\b/i', $line) === 1) {
                return true;
            }
            return false;
        };
        $out = [];
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            $raw_line = (string) $lines[$i];
            $line = trim($raw_line);
            if ($line !== '') {
                if (@preg_match('/(发自我的iPhone|发自我的iPad|Sent\s+from\s+my\s+iPhone|Sent\s+from\s+my\s+iPad)/iu', $raw_line, $mm, PREG_OFFSET_CAPTURE) === 1) {
                    $pos = intval($mm[0][1] ?? 0);
                    $before = trim((string) substr($raw_line, 0, $pos));
                    if ($before !== '') {
                        $out[] = rtrim((string) $before);
                    }
                    break;
                }
                if (@preg_match('/-{2,}\s*(Original Message|原始邮件|原邮件)\s*-{2,}/iu', $raw_line, $mm, PREG_OFFSET_CAPTURE) === 1) {
                    $pos = intval($mm[0][1] ?? 0);
                    $before = trim((string) substr($raw_line, 0, $pos));
                    if ($before !== '') {
                        $out[] = rtrim((string) $before);
                    }
                    break;
                }
                if (@preg_match('/发件人\s*[:：].*发送时间\s*[:：].*(收件人\s*[:：].*)?(主题\s*[:：].*)/u', $raw_line) === 1) {
                    $p = mb_strpos($raw_line, '发件人');
                    if ($p !== false) {
                        $before = trim((string) mb_substr($raw_line, 0, $p, 'UTF-8'));
                        if ($before !== '') {
                            $out[] = rtrim((string) $before);
                        }
                        break;
                    }
                }
            }
            if ($is_noise_delimiter($line)) {
                break;
            }
            if ($line !== '' && (@preg_match('/^-{2,}\s*(Original Message|原始邮件|原邮件)\s*-{2,}$/iu', $line) === 1)) {
                break;
            }
            if ($is_gmail_quote_header($line)) {
                break;
            }
            if ($is_mobile_signature($line)) {
                break;
            }
            if ($line !== '' && (@preg_match('/(?:获取|Get)\s*Outlook\s*(?:for\s*(?:iOS|Android|Mac|Windows))?|Sent\s+from\s+Outlook/iu', $line) === 1)) {
                $parts = preg_split('/(?:获取|Get)\s*Outlook\s*(?:for\s*(?:iOS|Android|Mac|Windows))?|Sent\s+from\s+Outlook/iu', $raw_line, 2);
                $before = isset($parts[0]) ? trim((string) $parts[0]) : '';
                if ($before !== '') {
                    $out[] = $before;
                }
                break;
            }
            if ($is_header_line($line)) {
                $look = 0;
                $hits = 0;
                $has_subject = false;
                $k = $i;
                while ($k < $n && $look < 20) {
                    $l = trim((string) $lines[$k]);
                    if ($l === '') {
                        $k++;
                        $look++;
                        continue;
                    }
                    if ($is_header_line($l)) {
                        $hits++;
                        if (@preg_match('/^(主题)\s*[:：]/u', $l) === 1 || @preg_match('/^(Subject)\s*:/i', $l) === 1) {
                            $has_subject = true;
                        }
                        $k++;
                        $look++;
                        continue;
                    }
                    break;
                }
                if (($hits >= 3 && $has_subject) || $hits >= 4) {
                    break;
                }
            }
            $out[] = rtrim((string) $raw_line);
        }
        $result = implode("\n", $out);
        $result = preg_replace("/\n{3,}/", "\n\n", (string) $result);
        return trim((string) $result);
    }
}
if (!function_exists('todo_mail_render_chat_bubble_html')) {
    function todo_mail_render_chat_bubble_html($plain, $raw = '', $direction = '')
    {
        $plain = trim((string) $plain);
        $raw = (string) $raw;
        $direction = trim((string) $direction);
        $esc = static function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $page_scheme = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http');
        $normalize_http_url = static function ($url) use ($page_scheme) {
            $url = trim((string) $url);
            if ($url === '') return '';
            if (@preg_match('/^data:/i', $url) === 1) return $url;
            if (strpos($url, '//') === 0) {
                return $page_scheme . ':' . $url;
            }
            if ($url[0] === '/') {
                if (function_exists('todo_get_site_url')) {
                    return (string) todo_get_site_url($url);
                }
                return $page_scheme . '://' . (function_exists('todo_get_http_host') ? todo_get_http_host() : 'localhost') . $url;
            }
            if (@preg_match('/^https?:\\/\\//i', $url) === 1) {
                if ($page_scheme === 'https' && stripos($url, 'http://') === 0) {
                    return 'https://' . substr($url, 7);
                }
                return $url;
            }
            return '';
        };
        $is_valid_url = static function ($url) {
            $url = trim((string) $url);
            if ($url === '') return false;
            if (@preg_match('/^https?:\\/\\//i', $url) !== 1) return false;
            return filter_var($url, FILTER_VALIDATE_URL) !== false;
        };
        $is_valid_img_src = static function ($url) {
            $url = trim((string) $url);
            if ($url === '') return false;
            if (@preg_match('/^https?:\\/\\//i', $url) === 1) return filter_var($url, FILTER_VALIDATE_URL) !== false;
            if (@preg_match('/^data:image\\/(png|jpe?g|gif|webp);base64,[a-z0-9+\\/\\s=]+$/i', $url) === 1) return true;
            return false;
        };
        $sanitize_raw_html = static function ($raw_html) use ($esc, $is_valid_url, $is_valid_img_src, $normalize_http_url) {
            $raw_html = (string) $raw_html;
            if ($raw_html === '') {
                return '';
            }
            $raw_html = html_entity_decode($raw_html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!class_exists('DOMDocument')) {
                return '';
            }
            $old = libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $wrapped = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $raw_html;
            $loaded = false;
            try {
                $loaded = @$dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            } catch (Throwable $e) {
                $loaded = false;
            }
            if (!$loaded) {
                try {
                    $loaded = @$dom->loadHTML($wrapped);
                } catch (Throwable $e) {
                    $loaded = false;
                }
            }
            libxml_clear_errors();
            libxml_use_internal_errors($old);
            if (!$loaded) {
                return '';
            }
            $body = null;
            $bodys = $dom->getElementsByTagName('body');
            if ($bodys && $bodys->length > 0) {
                $body = $bodys->item(0);
            }
            $root = $body ?: $dom->documentElement;
            if (!$root) {
                return '';
            }
            $link_count = 0;
            $img_count = 0;
            $max_links = 30;
            $max_imgs = 10;
            $walk = function ($node) use (&$walk, &$link_count, &$img_count, $max_links, $max_imgs, $esc, $is_valid_url, $is_valid_img_src, $normalize_http_url) {
                if (!$node) return '';
                if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
                    return $esc($node->nodeValue);
                }
                if ($node->nodeType !== XML_ELEMENT_NODE) {
                    return '';
                }
                $tag = strtolower((string) $node->nodeName);
                if (in_array($tag, ['script', 'style', 'meta', 'link', 'object', 'embed', 'iframe'], true)) {
                    return '';
                }
                if ($tag === 'br') {
                    return '<br>';
                }
                if ($tag === 'img') {
                    if ($img_count >= $max_imgs) return '';
                    $src = trim((string) $node->getAttribute('src'));
                    $src = $normalize_http_url($src);
                    if (!$is_valid_img_src($src)) return '';
                    $alt = (string) $node->getAttribute('alt');
                    $img_count++;
                    $s = $esc($src);
                    $a = $esc($alt);
                    return '<a href="' . $s . '" target="_blank" rel="nofollow noopener noreferrer"><img src="' . $s . '" alt="' . $a . '" loading="lazy" referrerpolicy="no-referrer"></a>';
                }
                $inner = '';
                if ($node->hasChildNodes()) {
                    foreach ($node->childNodes as $ch) {
                        $inner .= $walk($ch);
                    }
                }
                if ($tag === 'a') {
                    if ($link_count >= $max_links) {
                        return $inner;
                    }
                    $href = trim((string) $node->getAttribute('href'));
                    $href = $normalize_http_url($href);
                    if (!$is_valid_url($href)) {
                        return $inner;
                    }
                    $link_count++;
                    $h = $esc($href);
                    return '<a href="' . $h . '" target="_blank" rel="nofollow noopener noreferrer">' . ($inner !== '' ? $inner : $h) . '</a>';
                }
                if ($tag === 'video') {
                    $src = trim((string) $node->getAttribute('src'));
                    $src = $normalize_http_url($src);
                    if ($is_valid_url($src)) {
                        $s = $esc($src);
                        return '<video src="' . $s . '" controls style="max-width: 330px;"></video>';
                    }
                    return '';
                }
                if (in_array($tag, ['p', 'div', 'span', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'blockquote'], true)) {
                    $wrap = $tag;
                    if ($wrap === 'p') $wrap = 'div';
                    return '<' . $wrap . '>' . $inner . '</' . $wrap . '>';
                }
                return $inner;
            };
            $out = '';
            foreach ($root->childNodes as $ch) {
                $out .= $walk($ch);
            }
            $out = trim((string) $out);
            if ($out === '') return '';
            return $out;
        };
        if ($direction === 'out' && $raw !== '' && (stripos($raw, '<a') !== false || stripos($raw, '<img') !== false || stripos($raw, '<video') !== false || stripos($raw, '&lt;a') !== false || stripos($raw, '&lt;img') !== false || stripos($raw, '&lt;video') !== false)) {
            $safe = $sanitize_raw_html($raw);
            if ($safe !== '') {
                return $safe;
            }
        }
        $html = $esc($plain);
        $html = str_replace(["\r\n", "\r", "\n"], "<br>", $html);
        $html = preg_replace_callback('~(https?://[^\s<]+)~i', function ($m) use ($esc, $is_valid_url) {
            $full = (string) ($m[1] ?? '');
            $url = rtrim($full, ".,;:!?)]}\"'");
            $trail = substr($full, strlen($url));
            if (!$is_valid_url($url)) {
                return $esc($full);
            }
            $u = $esc($url);
            $t = $trail !== '' ? $esc($trail) : '';
            return '<a href="' . $u . '" target="_blank" rel="nofollow noopener noreferrer">' . $u . '</a>' . $t;
        }, (string) $html);
        if ($direction === 'out') {
            $imgs = [];
            $raw_img_source = '';
            if ($raw !== '' && (stripos($raw, '<img') !== false || stripos($raw, '&lt;img') !== false)) {
                $raw_img_source = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (preg_match_all('/<img\\b[^>]*>/i', $raw_img_source, $mm) === 1 || (is_array($mm) && !empty($mm[0]))) {
                    foreach (($mm[0] ?? []) as $tag) {
                        $src = '';
                        if (preg_match('/\\bsrc\\s*=\\s*(?:"([^"]+)"|\\x27([^\\x27]+)\\x27|([^\\s>]+))/i', (string) $tag, $m2) === 1) {
                            $src = (string) ($m2[1] ?? ($m2[2] ?? ($m2[3] ?? '')));
                        }
                        $src = trim($src);
                        $src = $normalize_http_url($src);
                        if (!$is_valid_img_src($src)) {
                            continue;
                        }
                        $alt = '';
                        if (preg_match('/\\balt\\s*=\\s*(?:"([^"]*)"|\\x27([^\\x27]*)\\x27|([^\\s>]+))/i', (string) $tag, $m3) === 1) {
                            $alt = (string) ($m3[1] ?? ($m3[2] ?? ($m3[3] ?? '')));
                        }
                        $key = $src . '|' . $alt;
                        if (isset($imgs[$key])) continue;
                        $imgs[$key] = ['src' => $src, 'alt' => $alt];
                        if (count($imgs) >= 6) break;
                    }
                }
            }
            if (!empty($imgs)) {
                $html .= '<div class="todo-mail-chat-images">';
                foreach ($imgs as $it) {
                    $src = $esc((string) ($it['src'] ?? ''));
                    $alt = $esc((string) ($it['alt'] ?? ''));
                    $html .= '<a href="' . $src . '" target="_blank" rel="nofollow noopener noreferrer"><img src="' . $src . '" alt="' . $alt . '" loading="lazy" referrerpolicy="no-referrer"></a>';
                }
                $html .= '</div>';
            }
        }
        return (string) $html;
    }
}
function todo_imap_extract_body($imap, $msgno)
{
    $structure = @imap_fetchstructure($imap, $msgno);
    if (!$structure) {
        $raw = @imap_body($imap, $msgno, FT_PEEK);
        return is_string($raw) ? $raw : '';
    }
    $fetch_part = function ($part_no, $encoding) use ($imap, $msgno) {
        $data = @imap_fetchbody($imap, $msgno, $part_no, FT_PEEK);
        if (!is_string($data)) {
            return '';
        }
        $enc = intval($encoding);
        if ($enc === 3) {
            return (string) base64_decode($data);
        }
        if ($enc === 4) {
            return (string) quoted_printable_decode($data);
        }
        return $data;
    };
    $find_part = function ($struct, $want) use (&$find_part) {
        if (!isset($struct->parts) || !is_array($struct->parts)) {
            return null;
        }
        foreach ($struct->parts as $idx => $p) {
            $part_no = (string) ($idx + 1);
            $type = isset($p->type) ? intval($p->type) : -1;
            $sub = isset($p->subtype) ? strtolower((string) $p->subtype) : '';
            if ($type === 0) {
                if ($want === 'html' && $sub === 'html') {
                    return ['no' => $part_no, 'enc' => $p->encoding ?? 0, 'charset' => todo_imap_part_charset($p)];
                }
                if ($want === 'plain' && ($sub === 'plain' || $sub === '')) {
                    return ['no' => $part_no, 'enc' => $p->encoding ?? 0, 'charset' => todo_imap_part_charset($p)];
                }
            }
            if (isset($p->parts) && is_array($p->parts)) {
                $r = $find_part($p, $want);
                if ($r) {
                    $r['no'] = $part_no . '.' . $r['no'];
                    return $r;
                }
            }
        }
        return null;
    };
    if (!function_exists('todo_imap_part_charset')) {
        function todo_imap_part_charset($p)
        {
            $charset = '';
            if (isset($p->parameters) && is_array($p->parameters)) {
                foreach ($p->parameters as $param) {
                    if (isset($param->attribute) && strtolower((string) $param->attribute) === 'charset') {
                        $charset = (string) ($param->value ?? '');
                        break;
                    }
                }
            }
            if ($charset === '' && isset($p->dparameters) && is_array($p->dparameters)) {
                foreach ($p->dparameters as $param) {
                    if (isset($param->attribute) && strtolower((string) $param->attribute) === 'charset') {
                        $charset = (string) ($param->value ?? '');
                        break;
                    }
                }
            }
            return $charset;
        }
    }
    $html_part = $find_part($structure, 'html');
    if ($html_part) {
        $body = $fetch_part($html_part['no'], $html_part['enc']);
        $body = todo_imap_decode_string($body, $html_part['charset'] ?? '');
        $body = todo_imap_maybe_decode_base64_text($body);
        return todo_to_utf8_string($body);
    }
    $plain_part = $find_part($structure, 'plain');
    if ($plain_part) {
        $body = $fetch_part($plain_part['no'], $plain_part['enc']);
        $body = todo_imap_decode_string($body, $plain_part['charset'] ?? '');
        $body = todo_imap_maybe_decode_base64_text($body);
        return todo_to_utf8_string($body);
    }
    $raw = @imap_body($imap, $msgno, FT_PEEK);
    if (!is_string($raw)) {
        return '';
    }
    $raw = todo_imap_maybe_decode_base64_text($raw);
    return todo_to_utf8_string($raw);
}
function todo_imap_normalize_msgid($v)
{
    $v = trim((string) $v);
    if ($v === '') {
        return '';
    }
    if ($v[0] !== '<') {
        $v = '<' . $v;
    }
    if (substr($v, -1) !== '>') {
        $v = $v . '>';
    }
    return $v;
}
function todo_imap_find_thread_by_headers($in_reply_to, $references)
{
    global $tododb;
    if (!is_object($tododb)) {
        return null;
    }
    $table = $tododb->prefix . 'email_logs';
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return null;
    }
    todo_ensure_email_logs_schema($table, $tododb);
    $candidates = [];
    $irt = todo_imap_normalize_msgid($in_reply_to);
    if ($irt !== '') {
        $candidates[] = $irt;
        $candidates[] = trim($irt, '<>');
    }
    $refs = [];
    if (is_array($references)) {
        $refs = $references;
    } else {
        $refs = preg_split('/\s+/', trim((string) $references)) ?: [];
    }
    foreach ($refs as $r) {
        $r = todo_imap_normalize_msgid($r);
        if ($r !== '') {
            $candidates[] = $r;
            $candidates[] = trim($r, '<>');
        }
    }
    $candidates = array_values(array_unique(array_filter(array_map('trim', $candidates))));
    if (empty($candidates)) {
        return null;
    }
    $placeholders = implode(',', array_fill(0, count($candidates), '?'));
    $sql = "SELECT * FROM `{$table}` WHERE message_id IN ({$placeholders}) ORDER BY id DESC LIMIT 1";
    $row = $tododb->get_row($sql, $candidates);
    return $row ?: null;
}
function todo_imap_normalize_subject($subject)
{
    $s = trim((string) $subject);
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/\s+/', ' ', $s);
    $s = trim((string) $s);
    $loop = 0;
    while ($loop < 6) {
        $prev = $s;
        $s = preg_replace('/^\s*(re|fw|fwd)\s*:\s*/i', '', $s);
        $s = preg_replace('/^\s*(回复|答复)\s*:\s*/u', '', $s);
        $s = trim((string) $s);
        if ($s === $prev) {
            break;
        }
        $loop++;
    }
    if (function_exists('mb_strtolower')) {
        $s = mb_strtolower($s, 'UTF-8');
    } else {
        $s = strtolower($s);
    }
    return trim((string) $s);
}
function todo_imap_extract_header_value($raw_header, $name)
{
    if (!is_string($raw_header) || $raw_header === '' || !is_string($name) || $name === '') {
        return '';
    }
    $name = preg_quote($name, '/');
    if (preg_match('/^' . $name . ':\s*(.*(?:\r?\n[ \t].*)*)/mi', $raw_header, $m) !== 1) {
        return '';
    }
    $v = (string) ($m[1] ?? '');
    $v = preg_replace("/\r?\n[ \t]+/", ' ', $v);
    return trim((string) $v);
}
function todo_outlook_thread_index_root()
{
    $unix = microtime(true);
    $filetime = (int) round(($unix + 11644473600) * 10000000);
    if ($filetime < 0) {
        $filetime = 0;
    }
    $hi40 = $filetime >> 24;
    $time5 = '';
    for ($i = 4; $i >= 0; $i--) {
        $time5 .= chr(($hi40 >> ($i * 8)) & 0xFF);
    }
    try {
        $seed = random_bytes(16);
    } catch (Throwable $e) {
        $seed = substr(md5((string) microtime(true) . '|' . (string) mt_rand(), true), 0, 16);
    }
    $bin = "\x01" . $time5 . $seed;
    return rtrim((string) base64_encode($bin));
}
function todo_outlook_thread_index_child($parent)
{
    $parent = trim((string) $parent);
    if ($parent === '') {
        return '';
    }
    $bin = base64_decode($parent, true);
    if (!is_string($bin) || $bin === '') {
        return $parent;
    }
    $unix = microtime(true);
    $filetime = (int) round(($unix + 11644473600) * 10000000);
    if ($filetime < 0) {
        $filetime = 0;
    }
    $hi40 = $filetime >> 24;
    $suffix = '';
    for ($i = 4; $i >= 0; $i--) {
        $suffix .= chr(($hi40 >> ($i * 8)) & 0xFF);
    }
    return rtrim((string) base64_encode($bin . $suffix));
}
function todo_imap_find_thread_by_subject($subject, $from_email)
{
    global $tododb;
    if (!is_object($tododb)) {
        return null;
    }
    $table = $tododb->prefix . 'email_logs';
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return null;
    }
    $cols = todo_ensure_email_logs_schema($table, $tododb);
    if (!is_array($cols)) {
        $cols = [];
    }
    $norm = todo_imap_normalize_subject($subject);
    if ($norm === '') {
        return null;
    }
    $from_email = trim((string) $from_email);
    $rows = [];
    if ($from_email !== '') {
        $addr_conds = [];
        if (in_array('from_email', $cols, true)) {
            $addr_conds[] = 'from_email = ?';
        }
        if (in_array('recipient', $cols, true)) {
            $addr_conds[] = 'recipient = ?';
        }
        if (in_array('to_email', $cols, true)) {
            $addr_conds[] = 'to_email = ?';
        }
        $addr_sql = '';
        $params = [];
        if (!empty($addr_conds)) {
            $addr_sql = ' AND (' . implode(' OR ', $addr_conds) . ')';
            $params = array_fill(0, count($addr_conds), $from_email);
        }
        $rows = $tododb->get_results(
            "SELECT * FROM `{$table}` WHERE thread_key <> ''{$addr_sql} ORDER BY id DESC LIMIT 200",
            $params
        );
    } else {
        $rows = $tododb->get_results(
            "SELECT * FROM `{$table}` WHERE thread_key <> '' ORDER BY id DESC LIMIT 200",
            []
        );
    }
    if (!is_array($rows) || empty($rows)) {
        return null;
    }
    foreach ($rows as $r) {
        $subj = is_object($r) ? (string) ($r->subject ?? '') : (is_array($r) ? (string) ($r['subject'] ?? '') : '');
        if (todo_imap_normalize_subject($subj) === $norm) {
            return $r;
        }
    }
    return null;
}
function todo_run_imap_polling($args = [])
{
    $force = false;
    $return_stats = false;
    $limit = 80;
    if (is_array($args)) {
        $force = !empty($args['force']);
        $return_stats = !empty($args['return_stats']);
        if (array_key_exists('limit', $args)) {
            $limit = max(1, intval($args['limit']));
        }
    } elseif (is_bool($args)) {
        $force = $args;
    } elseif (is_numeric($args)) {
        $limit = max(1, intval($args));
    }
    $stats = [
        'ok' => false,
        'reason' => '',
        'error' => '',
        'mailbox' => '',
        'host' => '',
        'inserted' => 0,
        'notified' => 0,
        'skipped_existing' => 0,
        'last_uid' => 0,
        'max_uid' => 0,
        'candidate_uids' => 0,
        'processed' => 0,
    ];
    if (!$force && !todo_imap_polling_enabled()) {
        $stats['reason'] = 'disabled';
        return $return_stats ? $stats : 0;
    }
    if (!function_exists('imap_open')) {
        $stats['reason'] = 'imap_missing';
        return $return_stats ? $stats : 0;
    }
    $now = time();
    $last_poll = intval(CMS_Option::get('mail_imap_last_poll', 0));
    if (!$force && $last_poll > 0 && ($now - $last_poll) < 240) {
        $stats['reason'] = 'throttled';
        return $return_stats ? $stats : 0;
    }
    $host = (string) CMS_Option::get('mail_imap_host', '');
    $port = intval(CMS_Option::get('mail_imap_port', 993));
    $user = (string) CMS_Option::get('mail_imap_user', '');
    $pass = (string) CMS_Option::get('mail_imap_pass', '');
    $mailbox = (string) CMS_Option::get('mail_imap_mailbox', 'INBOX');
    $encryption = (string) CMS_Option::get('mail_imap_encryption', 'ssl');
    $validate_cert = (int) CMS_Option::get('mail_imap_validate_cert', 0) === 1;
    $stats['mailbox'] = (string) $mailbox;
    $stats['host'] = (string) $host;
    if ($host === '' || $user === '' || $pass === '') {
        $stats['reason'] = 'config_missing';
        return $return_stats ? $stats : 0;
    }
    CMS_Option::update('mail_imap_last_poll', $now, 'general');
    $mailbox_str = todo_imap_mailbox_string($host, $port, $mailbox, $encryption, $validate_cert);
    $imap = @imap_open($mailbox_str, $user, $pass, OP_READONLY);
    if (!$imap) {
        $err = @imap_last_error();
        $stats['reason'] = 'connect_failed';
        $stats['error'] = is_string($err) ? $err : '';
        return $return_stats ? $stats : 0;
    }
    global $tododb;
    $table = is_object($tododb) ? ($tododb->prefix . 'email_logs') : (DB_PREFIX . 'email_logs');
    if (is_object($tododb)) {
        todo_ensure_email_logs_schema($table, $tododb);
    }
    $last_uid_key = 'mail_imap_last_uid_' . strtolower((string) $mailbox);
    $last_uid = intval(CMS_Option::get($last_uid_key, 0));
    $stats['last_uid'] = $last_uid;
    $since_ts = $now - 172800;
    if ($last_poll > 0) {
        $since_ts = max($since_ts, $last_poll - 3600);
    }
    if ($since_ts > $now) {
        $since_ts = $now;
    }
    $since_str = date('d-M-Y', $since_ts);
    $search_criteria = 'SINCE "' . $since_str . '"';
    $msgs = @imap_search($imap, $search_criteria, SE_UID);
    if ($msgs === false || $msgs === null || !is_array($msgs)) {
        $err = @imap_last_error();
        $msgs = @imap_search($imap, 'ALL', SE_UID);
        if ($msgs === false || $msgs === null || !is_array($msgs)) {
            @imap_close($imap);
            $stats['reason'] = 'search_failed';
            $stats['error'] = is_string($err) ? $err : '';
            return $return_stats ? $stats : 0;
        }
    }
    if (empty($msgs)) {
        @imap_close($imap);
        $stats['ok'] = true;
        $stats['reason'] = 'no_messages';
        return $return_stats ? $stats : 0;
    }
    sort($msgs);
    $candidate_uids = [];
    if ($last_uid > 0) {
        foreach ($msgs as $uid) {
            $uid = intval($uid);
            if ($uid > $last_uid) {
                $candidate_uids[] = $uid;
            }
        }
    } else {
        foreach ($msgs as $uid) {
            $candidate_uids[] = intval($uid);
        }
    }
    if (empty($candidate_uids)) {
        @imap_close($imap);
        $stats['ok'] = true;
        $stats['reason'] = 'no_new';
        return $return_stats ? $stats : 0;
    }
    $stats['candidate_uids'] = count($candidate_uids);
    if (count($candidate_uids) > $limit) {
        $candidate_uids = array_slice($candidate_uids, -$limit);
    }
    sort($candidate_uids);
    $max_uid = $last_uid;
    $inserted = 0;
    $notified = 0;
    $skipped_existing = 0;
    $processed = 0;
    foreach ($candidate_uids as $uid) {
        $uid = intval($uid);
        $max_uid = max($max_uid, $uid);
        $processed++;
        $msgno = @imap_msgno($imap, $uid);
        if (!$msgno) {
            continue;
        }
        $overview_list = @imap_fetch_overview($imap, (string) $uid, FT_UID);
        $overview = is_array($overview_list) && isset($overview_list[0]) ? $overview_list[0] : null;
        $raw_header = @imap_fetchheader($imap, $msgno, FT_PREFETCHTEXT);
        $header = is_string($raw_header) ? @imap_rfc822_parse_headers($raw_header) : null;
        $subject = '';
        if ($overview && isset($overview->subject)) {
            $subject = todo_imap_decode_string((string) $overview->subject, '');
        } elseif ($header && isset($header->subject)) {
            $subject = todo_imap_decode_string((string) $header->subject, '');
        }
        $from_email = '';
        $from_name = '';
        if ($header && isset($header->from) && is_array($header->from) && isset($header->from[0])) {
            $f = $header->from[0];
            $from_email = isset($f->mailbox, $f->host) ? ((string) $f->mailbox . '@' . (string) $f->host) : '';
            $from_name = isset($f->personal) ? todo_imap_decode_string((string) $f->personal, '') : '';
        }
        $date_str = '';
        if ($overview && isset($overview->date)) {
            $date_str = (string) $overview->date;
        } elseif ($header && isset($header->date)) {
            $date_str = (string) $header->date;
        }
        $received_at = '';
        if ($date_str !== '') {
            $ts = strtotime($date_str);
            if ($ts !== false) {
                $received_at = date('Y-m-d H:i:s', $ts);
            }
        }
        $message_id = '';
        $in_reply_to = '';
        $references = '';
        $thread_index = '';
        $thread_topic = '';
        $conversation_index = '';
        $conversation_topic = '';
        if ($header) {
            if (isset($header->message_id)) {
                $message_id = todo_imap_normalize_msgid((string) $header->message_id);
            }
            if (isset($header->in_reply_to)) {
                $in_reply_to = todo_imap_normalize_msgid((string) $header->in_reply_to);
            }
            if (isset($header->references)) {
                $references = trim((string) $header->references);
            }
        }
        if (is_string($raw_header) && $raw_header !== '') {
            $thread_index = todo_imap_extract_header_value($raw_header, 'Thread-Index');
            $thread_topic = todo_imap_extract_header_value($raw_header, 'Thread-Topic');
            $conversation_index = todo_imap_extract_header_value($raw_header, 'Conversation-Index');
            $conversation_topic = todo_imap_extract_header_value($raw_header, 'Conversation-Topic');
        }
        if (is_object($tododb)) {
            if ($message_id !== '') {
                $exists = $tododb->get_var("SELECT id FROM `{$table}` WHERE message_id = ? LIMIT 1", [$message_id]);
                if ($exists) {
                    $skipped_existing++;
                    continue;
                }
            }
            $exists_uid = $tododb->get_var("SELECT id FROM `{$table}` WHERE imap_mailbox = ? AND imap_uid = ? LIMIT 1", [strtolower($mailbox), $uid]);
            if ($exists_uid) {
                $skipped_existing++;
                continue;
            }
        }
        $matched = todo_imap_find_thread_by_headers($in_reply_to, $references);
        if (!$matched && $subject !== '') {
            $matched = todo_imap_find_thread_by_subject($subject, $from_email);
        }
        $thread_key = '';
        $related_type = '';
        $related_id = 0;
        $reply_to_log_id = 0;
        if ($matched) {
            $thread_key = isset($matched->thread_key) ? (string) $matched->thread_key : (isset($matched['thread_key']) ? (string) $matched['thread_key'] : '');
            $related_type = isset($matched->related_type) ? (string) $matched->related_type : (isset($matched['related_type']) ? (string) $matched['related_type'] : '');
            $related_id = isset($matched->related_id) ? intval($matched->related_id) : (isset($matched['related_id']) ? intval($matched['related_id']) : 0);
            $reply_to_log_id = isset($matched->id) ? intval($matched->id) : (isset($matched['id']) ? intval($matched['id']) : 0);
        }
        if ($references === '' && $in_reply_to !== '' && $matched) {
            $base_refs = '';
            if (is_object($matched) && isset($matched->references)) {
                $base_refs = trim((string) $matched->references);
            } elseif (is_array($matched) && isset($matched['references'])) {
                $base_refs = trim((string) $matched['references']);
            }
            $base_mid = '';
            if (is_object($matched) && isset($matched->message_id)) {
                $base_mid = trim((string) $matched->message_id);
            } elseif (is_array($matched) && isset($matched['message_id'])) {
                $base_mid = trim((string) $matched['message_id']);
            }
            $refs_list = $base_refs !== '' ? (preg_split('/\s+/', $base_refs) ?: []) : [];
            if ($base_mid !== '' && function_exists('todo_imap_normalize_msgid')) {
                $base_mid = todo_imap_normalize_msgid($base_mid);
            }
            if ($base_mid !== '') {
                $refs_list[] = $base_mid;
            }
            $refs_list[] = $in_reply_to;
            $refs = [];
            foreach ($refs_list as $r) {
                $r = trim((string) $r);
                if ($r === '') continue;
                if (function_exists('todo_imap_normalize_msgid')) {
                    $r = todo_imap_normalize_msgid($r);
                }
                if ($r !== '') $refs[] = $r;
            }
            $refs = array_values(array_unique(array_filter(array_map('trim', $refs))));
            $references = !empty($refs) ? implode(' ', $refs) : '';
        }
        if ($thread_key === '' && $from_email !== '' && is_object($tododb)) {
            $comment_table = $tododb->prefix . 'comments';
            $c = $tododb->get_row(
                "SELECT comment_ID, comment_post_ID, comment_author, comment_author_email FROM `{$comment_table}` WHERE comment_parent = 0 AND comment_type = 'inquiry' AND comment_author_email = ? ORDER BY comment_date DESC LIMIT 1",
                [$from_email]
            );
            if ($c) {
                $cid = isset($c->comment_ID) ? intval($c->comment_ID) : (isset($c['comment_ID']) ? intval($c['comment_ID']) : 0);
                if ($cid > 0) {
                    $thread_key = 'inquiry:' . $cid;
                    $related_type = 'product_inquiry';
                    $related_id = $cid;
                }
            }
        }
        $body = todo_imap_extract_body($imap, $msgno);
        $flags_seen = ($overview && isset($overview->seen) && (int) $overview->seen === 1) ? 1 : 0;
        todo_log_email($user, $subject !== '' ? $subject : '(no subject)', 'received', '', [
            'content' => $body,
            'type' => 'reply',
            'direction' => 'in',
            'from_email' => $from_email,
            'from_name' => $from_name,
            'message_id' => $message_id,
            'in_reply_to' => $in_reply_to,
            'references' => $references,
            'thread_index' => $thread_index,
            'thread_topic' => $thread_topic,
            'conversation_index' => $conversation_index,
            'conversation_topic' => $conversation_topic,
            'thread_key' => $thread_key,
            'related_type' => $related_type,
            'related_id' => $related_id,
            'imap_uid' => $uid,
            'imap_mailbox' => strtolower($mailbox),
            'received_at' => $received_at,
            'is_read' => $flags_seen ? 1 : 0,
        ]);
        $inserted++;
        if ($reply_to_log_id > 0 && is_object($tododb)) {
            $tododb->update($table, [
                'has_reply' => 1,
                'replied_at' => $received_at !== '' ? $received_at : $now,
            ], ['id' => $reply_to_log_id]);
        }
    }
    CMS_Option::update($last_uid_key, $max_uid, 'general');
    @imap_close($imap);
    $stats['ok'] = true;
    $stats['reason'] = 'synced';
    $stats['inserted'] = $inserted;
    $stats['notified'] = $notified;
    $stats['skipped_existing'] = $skipped_existing;
    $stats['max_uid'] = $max_uid;
    $stats['processed'] = $processed;
    return $return_stats ? $stats : $inserted;
}
if (function_exists('add_action')) {
    add_action('cms_init', function () {
        if (!todo_imap_polling_enabled()) {
            return;
        }
        if (!class_exists('Todo_Scheduler')) {
            return;
        }
        $hook = 'todo_imap_poll';
        if (Todo_Scheduler::nextScheduled($hook) <= 0) {
            Todo_Scheduler::scheduleEvent(time() + 60, 'five_minutes', $hook);
        }
    }, 20);
    add_action('todo_imap_poll', 'todo_run_imap_polling');
}
/**
 * 保存草稿
 *
 * @param string $recipient 收件人
 * @param string $subject 主题
 * @param string $content 内容
 * @param int $id 草稿ID（可选，用于更新）
 * @return int|false 草稿ID或false
 */
function todo_save_email_draft($recipient, $subject, $content, $id = 0, $preview_text = '') {
    global $tododb;
    
    $data = [
        'recipient' => $recipient,
        'subject' => $subject,
        'content' => $content,
        'updated_at' => current_time('mysql'),
        'preview_text' => (string) $preview_text,
        'sender_name' => func_num_args() >= 6 ? (string) func_get_arg(5) : ''
    ];
    
    if ($id > 0) {
        $tododb->update($tododb->prefix . 'email_drafts', $data, ['id' => $id]);
        return $id;
    } else {
        $data['created_at'] = current_time('mysql');
        return $tododb->insert($tododb->prefix . 'email_drafts', $data);
    }
}
/**
 * 删除草稿
 *
 * @param int $id 草稿ID
 * @return bool
 */
function todo_delete_email_draft($id) {
    global $tododb;
    return $tododb->delete($tododb->prefix . 'email_drafts', ['id' => $id]);
}
/**
 * 发送欢迎邮件
 * 优先使用邮件模板，如果模板不存在则使用默认HTML格式
 *
 * @param int $user_id 用户ID
 * @return bool
 */
function todo_send_welcome_email($user_id) {
    $user = get_user_by('id', $user_id);
    if (!$user) {
        return false;
    }
    
    $data = [
        'username' => $user->user_login,
        'email' => $user->user_email,
        'site_name' => get_option('site_title', 'TodoCMS'),
        'display_name' => $user->display_name
    ];
    
    // 尝试使用欢迎邮件模板
    $template = todo_get_email_template('welcome');
    if ($template) {
        return todo_send_template_mail($user->user_email, 'welcome', $data);
    }
    
    // 如果没有模板，使用默认HTML格式
    $subject = '欢迎加入 ' . $data['site_name'];
    $message = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">';
    $message .= '<h2 style="color: #333;">欢迎加入 ' . esc_html($data['site_name']) . '</h2>';
    $message .= '<p>您好 <strong>' . esc_html($data['display_name']) . '</strong>，</p>';
    $message .= '<p>您的账号已成功创建。</p>';
    $message .= '<div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;">';
    $message .= '<p style="margin: 5px 0;"><strong>用户名：</strong>' . esc_html($data['username']) . '</p>';
    $message .= '<p style="margin: 5px 0;"><strong>邮箱：</strong>' . esc_html($data['email']) . '</p>';
    $message .= '</div>';
    $message .= '<p>如有任何问题，请随时联系我们。</p>';
    $message .= '<hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">';
    $message .= '<p style="color: #999; font-size: 12px;">' . esc_html($data['site_name']) . '</p>';
    $message .= '</div>';
    
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    return todo_mail($user->user_email, $subject, $message, $headers);
}
/**
 * 发送验证码邮件
 * 支持多种类型的验证码邮件，优先使用数据库中的邮件模板
 *
 * @param string $email 邮箱地址
 * @param string $code 验证码
 * @param string $type 类型 (register, login, admin_login, forgot_password等)
 * @return bool
 */
function todo_send_verification_code_email($email, $code, $type = 'register') {
    $data = [
        'code' => $code,
        'email' => $email,
        'site_name' => get_option('site_title', 'TodoCMS')
    ];
    
    // 根据类型选择不同的模板
    $template_mapping = [
        'register' => 'register_verify',
        'login' => 'email_verify',
        'admin_login_verify' => 'admin_login_verify',
        'forgot_password' => 'forgot_password_verify'
    ];
    
    $template_slug = isset($template_mapping[$type]) ? $template_mapping[$type] : 'email_verify';
    
    // 尝试使用指定的模板
    $template = todo_get_email_template($template_slug);
    if ($template) {
        return todo_send_template_mail($email, $template_slug, $data);
    }
    
    // 如果没有找到指定模板，尝试使用通用的 email_verify 模板
    if ($template_slug !== 'email_verify') {
        $template = todo_get_email_template('email_verify');
        if ($template) {
            return todo_send_template_mail($email, 'email_verify', $data);
        }
    }
    
    // 如果都没有模板，使用默认的HTML格式
    $type_names = [
        'register' => '注册验证',
        'login' => '登录验证',
        'admin_login_verify' => '后台登录验证',
        'forgot_password' => '密码重置验证'
    ];
    $type_name = isset($type_names[$type]) ? $type_names[$type] : '邮箱验证';
    
    $subject = $type_name . '码';
    $message = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">';
    $message .= '<h2 style="color: #333;">' . $type_name . '码</h2>';
    $message .= '<p>您的验证码是：<strong style="font-size: 24px; color: #007bff;">' . esc_html($code) . '</strong></p>';
    $message .= '<p style="color: #666;">验证码有效期为15分钟，请勿泄露给他人。</p>';
    $message .= '<hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">';
    $message .= '<p style="color: #999; font-size: 12px;">' . esc_html($data['site_name']) . '</p>';
    $message .= '</div>';
    
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    return todo_mail($email, $subject, $message, $headers);
}
/**
 * 生成并发送登录验证码
 *
 * @param string $email 邮箱地址
 * @return bool 成功返回true，失败返回false
 */
function todo_generate_and_send_login_code($email) {
    global $tododb;
    
    // 检查邮箱是否属于已注册用户
    $user = get_user_by('email', $email);
    if (!$user) {
        return false;
    }
    
    // 生成6位数字验证码
    $code = (string) rand(100000, 999999);
    $expires_at = date('Y-m-d H:i:s', time() + 15 * 60); // 15分钟有效期
    
    $table = $tododb->prefix . 'email_codes';
    
    // 保存验证码到数据库
    $result = $tododb->insert($table, [
        'email' => $email,
        'code' => $code,
        'type' => 'admin_login_verify',
        'expires_at' => $expires_at,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        'created_at' => current_time('mysql')
    ]);
    
    if ($result === false) {
        return false;
    }
    
    // 发送邮件
    if (!todo_send_verification_code_email($email, $code, 'admin_login_verify')) {
        return false;
    }
    
    return true;
}
/**
 * 验证邮箱验证码
 *
 * @param string $email 邮箱地址
 * @param string $code 验证码
 * @param string $type 类型
 * @return bool
 */
function todo_verify_email_code($email, $code, $type = 'register') {
    global $tododb;
    
    $table = $tododb->prefix . 'email_codes';
    
    // 查询有效的验证码
    $row = $tododb->get_row(
        "SELECT * FROM $table 
         WHERE email = ? AND code = ? AND type = ? AND used = 0 AND expires_at > NOW() 
         ORDER BY created_at DESC LIMIT 1",
        [$email, $code, $type]
    );
    
    if ($row) {
        // 标记为已使用
        $tododb->update($table, ['used' => 1, 'used_at' => current_time('mysql')], ['id' => $row->id]);
        return true;
    }
    
    return false;
}
/**
 * 发送管理员登录成功通知
 *
 * @param int $user_id 用户ID
 * @return bool
 */
function todo_notify_admin_login($user_id) {
    $user = get_user_by('id', $user_id);
    if (!$user) {
        return false;
    }
    $login_time = current_time('mysql');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $data = [
        'username' => $user->user_login,
        'login_time' => $login_time,
        'ip' => $ip,
        'user_agent' => $user_agent,
        'site_name' => get_option('site_title', 'TodoCMS')
    ];
    return todo_send_admin_notification('admin_login_notify', 'admin_login_notify', $data);
}
/**
 * 发送评论回复通知邮件给用户
 *
 * @param int $comment_id 回复评论的ID
 * @param string $template_slug 可选的模板标识
 * @return bool
 */
function todo_send_comment_reply_notification($comment_id, $template_slug = '') {
    global $tododb;
    
    // 获取回复评论
    $reply = $tododb->get_row("SELECT * FROM {$tododb->prefix}comments WHERE comment_ID = ?", [$comment_id]);
    if (!$reply || !$reply->comment_parent) {
        return false;
    }
    
    // 获取父评论（被回复的评论）
    $parent = $tododb->get_row("SELECT * FROM {$tododb->prefix}comments WHERE comment_ID = ?", [$reply->comment_parent]);
    if (!$parent) {
        return false;
    }
    $to_email = trim((string) ($parent->comment_author_email ?? ''));
    if ($to_email === '') {
        $parent_user_id = intval($parent->user_id ?? 0);
        if ($parent_user_id > 0) {
            $u = $tododb->get_row("SELECT user_email, display_name FROM {$tododb->prefix}users WHERE ID = ? LIMIT 1", [$parent_user_id]);
            if ($u && isset($u->user_email)) {
                $to_email = trim((string) $u->user_email);
            }
        }
    }
    if ($to_email === '') {
        if (function_exists('todo_log_email')) {
            $subject = 'comment_reply';
            if (function_exists('todo_get_email_template') && $template_slug !== '') {
                $tpl = todo_get_email_template($template_slug);
                if ($tpl && isset($tpl->subject)) {
                    $subject = (string) $tpl->subject;
                }
            }
            todo_log_email('', $subject, 'failed', '未找到收件人邮箱（comment_author_email 为空）', [
                'type' => 'notification',
                'direction' => 'out',
                'thread_key' => 'comment:' . intval($parent->comment_ID ?? 0),
                'related_type' => 'comment',
                'related_id' => intval($parent->comment_ID ?? 0),
                'is_read' => 1,
            ]);
        }
        return false;
    }
    
    // 获取文章信息
    $post = $tododb->get_row("SELECT * FROM {$tododb->prefix}posts WHERE ID = ?", [$reply->comment_post_ID]);
    
    $data = [
        'author' => $parent->comment_author,
        'parent_content' => $parent->comment_content,
        'reply_author' => $reply->comment_author,
        'reply_content' => $reply->comment_content,
        'post_title' => $post ? $post->post_title : 'Unknown',
        'post_url' => $post ? todo_get_permalink($post->ID) : todo_url(),
        'site_name' => CMS_Option::get('site_title', 'TodoCMS')
    ];
    
    // 如果没有指定模板，尝试使用默认模板 slug
    if (empty($template_slug)) {
        $template_slug = 'comment_reply_default';
    }
    
    return todo_send_template_mail($to_email, $template_slug, $data);
}
/**
 * 发送产品咨询回复通知
 *
 * @param int $inquiry_id 咨询ID
 * @param string $reply_content 回复内容
 * @param string $template_slug 模板标识
 * @return bool
 */
function todo_send_inquiry_reply_notification($inquiry_id, $reply_content, $template_slug = '') {
    global $tododb;
    
    // 获取咨询
    $inquiry = $tododb->get_row("SELECT * FROM {$tododb->prefix}product_inquiries WHERE id = ?", [$inquiry_id]);
    if (!$inquiry || empty($inquiry->email)) {
        return false;
    }
    
    // 获取产品
    $product_title = 'Unknown Product';
    $product_url = '';
    if (!empty($inquiry->product_id)) {
        $product = $tododb->get_row("SELECT post_title, post_name FROM {$tododb->prefix}posts WHERE ID = ?", [$inquiry->product_id]);
        if ($product) {
            $product_title = $product->post_title;
            $product_url = todo_get_permalink($inquiry->product_id);
        }
    }
    
    $data = [
        'name' => $inquiry->name,
        'email' => $inquiry->email,
        'subject' => $inquiry->subject ?: 'Product Inquiry Reply',
        'inquiry_content' => $inquiry->content,
        'reply_content' => $reply_content,
        'product_title' => $product_title,
        'product_url' => $product_url,
        'site_name' => CMS_Option::get('site_title', 'TodoCMS')
    ];
    
    if (empty($template_slug)) {
        $template_slug = 'inquiry_reply';
    }
    
    return todo_send_template_mail($inquiry->email, $template_slug, $data);
}
/**
 * 发送网站留言回复通知
 *
 * @param int $message_id 留言ID
 * @param string $reply_content 回复内容
 * @param string $template_slug 模板标识
 * @return bool
 */
function todo_send_message_reply_notification($message_id, $reply_content, $template_slug = '', $custom_subject = '') {
    global $tododb;
    
    $send_fallback = function ($to, $subject, $message_content, $reply_content, $site_name, $error_reason = '') {
        $to = trim((string) $to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            if (function_exists('todo_log_email')) {
                todo_log_email($to, (string) $subject, 'failed', $error_reason !== '' ? $error_reason : 'Invalid recipient email');
            }
            return false;
        }
        $safe_subject = trim((string) $subject);
        if ($safe_subject === '') {
            $safe_subject = '您的留言已收到回复';
        }
        $html = "<html><head><meta charset=\"UTF-8\"><title>" . htmlspecialchars($safe_subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</title></head><body>";
        $html .= "<h2>" . htmlspecialchars((string) $site_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</h2>";
        $html .= "<p>您的留言已收到我们的回复：</p>";
        $html .= "<div style=\"background-color:#f9f9f9;padding:15px;border-left:4px solid #0073aa;margin:20px 0;\">";
        $html .= "<strong>您的留言：</strong><br>" . nl2br(htmlspecialchars((string) $message_content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . "</div>";
        $html .= "<div style=\"background-color:#e8f5e8;padding:15px;border-left:4px solid #46b450;margin:20px 0;\">";
        $html .= "<strong>我们的回复：</strong><br>" . nl2br(htmlspecialchars((string) $reply_content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . "</div>";
        $html .= "<p>感谢您的关注！</p></body></html>";
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $mail_message_id = function_exists('todo_generate_message_id') ? (string) todo_generate_message_id() : '';
        if ($mail_message_id !== '') {
            $headers[] = 'Message-ID: ' . $mail_message_id;
        }
        $result = function_exists('todo_mail') ? todo_mail($to, $safe_subject, $html, $headers) : false;
        if (function_exists('todo_log_email')) {
            if ($result) {
                todo_log_email($to, $safe_subject, 'sent', '', [
                    'content' => $html,
                    'type' => 'reply',
                    'direction' => 'out',
                    'message_id' => $mail_message_id,
                    'is_read' => 1,
                ]);
            } else {
                todo_log_email($to, $safe_subject, 'failed', $error_reason !== '' ? $error_reason : 'Mail function returned false');
            }
        }
        return $result;
    };
    // 获取留言 (从 posts 表，适应 Guestbook 模型)
    $post = $tododb->get_row("SELECT * FROM {$tododb->prefix}posts WHERE ID = ?", [$message_id]);
    if (!$post) {
        // 尝试从旧表查询（兼容过渡期）
        $old_msg = $tododb->get_row("SELECT * FROM {$tododb->prefix}website_messages WHERE id = ?", [$message_id]);
        if ($old_msg) {
            $data = [
                'name' => $old_msg->name,
                'email' => $old_msg->email,
                'subject' => $old_msg->subject,
                'message_content' => todo_escape_template_text($old_msg->content),
                'reply_content' => $reply_content,
                'site_name' => todo_resolve_site_name_for_template('', CMS_Option::get('site_title', 'TodoCMS'))
            ];
            if (empty($template_slug)) $template_slug = 'message_reply';
            $custom_subject = trim((string) $custom_subject);
            if ($custom_subject !== '') {
                $data['subject'] = $custom_subject;
            }
            $template = function_exists('todo_get_email_template') ? todo_get_email_template($template_slug) : false;
            if (!$template) {
                return $send_fallback($old_msg->email, $data['subject'] ?? '您的留言已收到回复', $old_msg->content, $reply_content, $data['site_name'], "Template not found: {$template_slug}");
            }
            if ($custom_subject === '') {
                return todo_send_template_mail($old_msg->email, $template_slug, $data);
            }
            $data['site_name'] = todo_resolve_site_name_for_template($template->sender_name ?? '', CMS_Option::get('site_title', 'TodoCMS'));
            $subject = $custom_subject;
            $content = $template->content;
            $preview_text = isset($template->variables) ? trim((string) $template->variables) : '';
            foreach ($data as $key => $value) {
                $value = is_scalar($value) || (is_object($value) && method_exists($value, '__toString')) ? (string) $value : '';
                $subject = str_replace('{' . $key . '}', $value, $subject);
                $content = str_replace('{' . $key . '}', $value, $content);
                if ($preview_text !== '') {
                    $preview_text = str_replace('{' . $key . '}', $value, $preview_text);
                }
            }
            $content = todo_mail_inject_preview_text($content, $preview_text);
            $headers = ['Content-Type: text/html; charset=UTF-8'];
            $smtp_user = CMS_Option::get('mail_smtp_user');
            if (!empty($smtp_user) && function_exists('todo_resolve_sender_display_name')) {
                $site_title = CMS_Option::get('site_title', 'TodoCMS');
                $sender_name = todo_resolve_sender_display_name($template->sender_name ?? '', $site_title);
                $sender_name = trim((string) $sender_name);
                if ($sender_name !== '') {
                    $encoded_name = '=?UTF-8?B?' . base64_encode($sender_name) . '?=';
                    $headers[] = "From: $encoded_name <$smtp_user>";
                }
            }
            $mail_message_id = function_exists('todo_generate_message_id') ? (string) todo_generate_message_id() : '';
            if ($mail_message_id !== '') {
                $headers[] = 'Message-ID: ' . $mail_message_id;
            }
            $result = todo_mail($old_msg->email, $subject, $content, $headers);
            if ($result) {
                todo_log_email($old_msg->email, $subject, 'sent', '', [
                    'content' => $content,
                    'type' => 'reply',
                    'direction' => 'out',
                    'from_email' => (string) ($smtp_user ?: ''),
                    'message_id' => $mail_message_id,
                    'is_read' => 1,
                ]);
            } else {
                todo_log_email($old_msg->email, $subject, 'failed', 'Mail function returned false');
            }
            return $result;
        }
        return false;
    }
    
    // 获取联系信息 (从 post_custom_fields 表)
    $cf_results = $tododb->get_results("SELECT field_key, field_value FROM {$tododb->prefix}post_custom_fields WHERE post_id = ?", [$message_id]);
    $cf = [];
    if ($cf_results) {
        foreach ($cf_results as $row) {
            $cf[$row->field_key] = $row->field_value;
        }
    }
    
    $email = isset($cf['contact_email']) ? (string) $cf['contact_email'] : '';
    if ($email === '') {
        try {
            $mf = $tododb->get_row("SELECT contact_email, contact_name, contact_phone FROM {$tododb->prefix}model_fields_guestbook WHERE post_id = ? LIMIT 1", [$message_id], 'ARRAY_A');
            if (is_array($mf)) {
                if ($email === '' && !empty($mf['contact_email'])) {
                    $email = (string) $mf['contact_email'];
                }
                if (!empty($mf['contact_name']) && empty($cf['contact_name'])) {
                    $cf['contact_name'] = $mf['contact_name'];
                }
                if (!empty($mf['contact_phone']) && empty($cf['contact_phone'])) {
                    $cf['contact_phone'] = $mf['contact_phone'];
                }
            }
        } catch (Throwable $e) {
        }
    }
    if ($email === '') {
        if (function_exists('todo_log_email')) {
            todo_log_email('', 'message_reply', 'failed', 'contact_email not found');
        }
        return false;
    }
    
    $data = [
        'name' => isset($cf['contact_name']) ? $cf['contact_name'] : 'Guest',
        'email' => $email,
        'subject' => $post->post_title,
        'message_content' => todo_escape_template_text($post->post_content),
        'reply_content' => $reply_content,
        'site_name' => todo_resolve_site_name_for_template('', CMS_Option::get('site_title', 'TodoCMS'))
    ];
    
    if (empty($template_slug)) {
        $template_slug = 'message_reply';
    }
    $custom_subject = trim((string) $custom_subject);
    $template = function_exists('todo_get_email_template') ? todo_get_email_template($template_slug) : false;
    if (!$template) {
        return $send_fallback($email, $custom_subject !== '' ? $custom_subject : '您的留言已收到回复', $post->post_content, $reply_content, $data['site_name'], "Template not found: {$template_slug}");
    }
    if ($custom_subject === '') {
        return todo_send_template_mail($email, $template_slug, $data);
    }
    $data['site_name'] = todo_resolve_site_name_for_template($template->sender_name ?? '', CMS_Option::get('site_title', 'TodoCMS'));
    $subject = $custom_subject;
    $content = $template->content;
    $preview_text = isset($template->variables) ? trim((string) $template->variables) : '';
    foreach ($data as $key => $value) {
        $value = is_scalar($value) || (is_object($value) && method_exists($value, '__toString')) ? (string) $value : '';
        $subject = str_replace('{' . $key . '}', $value, $subject);
        $content = str_replace('{' . $key . '}', $value, $content);
        if ($preview_text !== '') {
            $preview_text = str_replace('{' . $key . '}', $value, $preview_text);
        }
    }
    $content = todo_mail_inject_preview_text($content, $preview_text);
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    $smtp_user = CMS_Option::get('mail_smtp_user');
    if (!empty($smtp_user) && function_exists('todo_resolve_sender_display_name')) {
        $site_title = CMS_Option::get('site_title', 'TodoCMS');
        $sender_name = todo_resolve_sender_display_name($template->sender_name ?? '', $site_title);
        $sender_name = trim((string) $sender_name);
        if ($sender_name !== '') {
            $encoded_name = '=?UTF-8?B?' . base64_encode($sender_name) . '?=';
            $headers[] = "From: $encoded_name <$smtp_user>";
        }
    }
    $mail_message_id = function_exists('todo_generate_message_id') ? (string) todo_generate_message_id() : '';
    if ($mail_message_id !== '') {
        $headers[] = 'Message-ID: ' . $mail_message_id;
    }
    $result = todo_mail($email, $subject, $content, $headers);
    if ($result) {
        todo_log_email($email, $subject, 'sent', '', [
            'content' => $content,
            'type' => 'reply',
            'direction' => 'out',
            'from_email' => (string) ($smtp_user ?: ''),
            'message_id' => $mail_message_id,
            'is_read' => 1,
        ]);
    } else {
        todo_log_email($email, $subject, 'failed', 'Mail function returned false');
    }
    return $result;
}
/**
 * 发送商品评价回复通知
 *
 * @param int $review_id 评价ID
 * @param string $reply_content 回复内容
 * @param string $template_slug 模板标识
 * @return bool
 */
function todo_send_review_reply_notification($review_id, $reply_content, $template_slug = '') {
    global $tododb;
    $review_id = intval($review_id);
    if ($review_id <= 0) {
        return false;
    }
    if (empty($template_slug)) {
        $template_slug = 'review_reply';
    }
    $comment = $tododb->get_row("SELECT * FROM {$tododb->prefix}comments WHERE comment_ID = ? LIMIT 1", [$review_id]);
    if ($comment && isset($comment->comment_type) && (string) $comment->comment_type === 'review') {
        $to_email = trim((string) ($comment->comment_author_email ?? ''));
        if ($to_email !== '') {
            $product_id = intval($comment->comment_post_ID ?? 0);
            $product_title = 'Unknown Product';
            $product_url = '';
            if ($product_id > 0) {
                $product = $tododb->get_row("SELECT post_title FROM {$tododb->prefix}posts WHERE ID = ? LIMIT 1", [$product_id]);
                if ($product && isset($product->post_title)) {
                    $product_title = (string) $product->post_title;
                }
                if (function_exists('todo_get_permalink')) {
                    $product_url = (string) todo_get_permalink($product_id);
                }
            }
            $data = [
                'name' => (string) ($comment->comment_author ?? 'User'),
                'email' => $to_email,
                'rating' => intval($comment->comment_rating ?? 0),
                'review_content' => (string) ($comment->comment_content ?? ''),
                'reply_content' => (string) $reply_content,
                'product_title' => $product_title,
                'product_url' => $product_url,
                'site_name' => CMS_Option::get('site_title', 'TodoCMS')
            ];
            return todo_send_template_mail($to_email, $template_slug, $data);
        }
        return false;
    }
    $reviews_table = $tododb->prefix . 'order_reviews';
    $review = $tododb->get_row("SELECT r.*, u.user_email, u.display_name FROM {$reviews_table} r LEFT JOIN {$tododb->prefix}users u ON r.user_id = u.ID WHERE r.id = ? LIMIT 1", [$review_id]);
    if (!$review || empty($review->user_email)) {
        return false;
    }
    $product_title = 'Unknown Product';
    $product_url = '';
    if (!empty($review->product_id)) {
        $pid = intval($review->product_id);
        $product = $tododb->get_row("SELECT post_title FROM {$tododb->prefix}posts WHERE ID = ? LIMIT 1", [$pid]);
        if ($product && isset($product->post_title)) {
            $product_title = (string) $product->post_title;
        }
        if (function_exists('todo_get_permalink')) {
            $product_url = (string) todo_get_permalink($pid);
        }
    }
    $data = [
        'name' => $review->display_name ?: 'User',
        'email' => $review->user_email,
        'rating' => intval($review->rating ?? 0),
        'review_content' => (string) ($review->content ?? ''),
        'reply_content' => (string) $reply_content,
        'product_title' => $product_title,
        'product_url' => $product_url,
        'site_name' => CMS_Option::get('site_title', 'TodoCMS')
    ];
    return todo_send_template_mail($review->user_email, $template_slug, $data);
}
/**
 * 新网站留言（guestbook）插入时通知管理员
 *
 * 通过 todo_post_inserted 钩子自动调用。
 * 支持变量：{name}, {email}, {phone}, {subject}, {message_content}, {created_at}, {site_name}
 *
 * @param int   $post_id  新建 post 的 ID
 * @param array $postarr  插入 post 时的原始数组
 */
function todo_notify_admin_new_guestbook($post_id, $postarr = []) {
    global $tododb;
    // 从数据库获取 post_type（postarr 不一定携带该字段）
    $pt = $tododb->get_var(
        "SELECT post_type FROM {$tododb->prefix}posts WHERE ID = ?",
        [$post_id]
    );
    if ($pt !== 'guestbook') {
        return;
    }
    // 检查管理员通知是否启用
    if (!todo_should_send_admin_notification('guestbook')) {
        return;
    }
    // 从模型专用表读取固定字段（contact_name / contact_email / contact_phone）
    $cf = [];
    $mf_row = $tododb->get_row(
        "SELECT contact_name, contact_email, contact_phone FROM {$tododb->prefix}model_fields_guestbook WHERE post_id = ?",
        [$post_id],
        'ARRAY_A'
    );
    if (is_array($mf_row)) {
        $cf = $mf_row;
    }
    // 读取 posts 基础信息
    $post = $tododb->get_row(
        "SELECT * FROM {$tododb->prefix}posts WHERE ID = ?",
        [$post_id]
    );
    $data = [
        'name'            => $cf['contact_name']  ?? '未知用户',
        'email'           => $cf['contact_email'] ?? '',
        'phone'           => $cf['contact_phone'] ?? '',
        'subject'         => $post ? $post->post_title   : '',
        'message_content' => $post ? $post->post_content : '',
        'created_at'      => $post ? $post->created_at   : current_time('mysql'),
        'site_name'       => CMS_Option::get('site_title', 'TodoCMS'),
    ];
    todo_send_admin_notification('guestbook', 'guestbook', $data);
}

/**
 * 发送管理员订单付款通知邮件
 *
 * @param int|array $order 订单ID或订单数据数组
 * @return bool 是否成功发送
 */
function todo_notify_admin_order_payment($order) {
    static $sent_payment_notifications = [];

    if (!todo_should_send_admin_notification('order_payment')) {
        return false;
    }

    if (is_numeric($order)) {
        $order_id = (int)$order;
        if (class_exists('Todo_Order_Manager')) {
            $order = Todo_Order_Manager::get_order($order_id, false);
        } else {
            global $tododb;
            $order = $tododb->get_row("SELECT * FROM {$tododb->prefix}orders WHERE id = ?", [$order_id], 'ARRAY_A');
        }
    } else {
        $order_id = (int)($order['id'] ?? 0);
    }

    if (!is_array($order) || empty($order) || empty($order_id)) {
        return false;
    }

    $is_recharge_notify = !empty($order['is_recharge']) || (is_string($order['order_no'] ?? '') && strpos($order['order_no'], 'RC') === 0);
    $notification_key = ($is_recharge_notify ? 'recharge_' : '') . $order_id;

    if (isset($sent_payment_notifications[$notification_key])) {
        return true;
    }
    $sent_payment_notifications[$notification_key] = true;

    $user_id = (int)($order['user_id'] ?? 0);
    $buyer_name = '';
    $buyer_email = '';

    if ($user_id > 0) {
        $buyer_name = $order['customer_name'] ?? ($order['username'] ?? '');
        $buyer_email = $order['user_email'] ?? '';
        if ((empty($buyer_name) || empty($buyer_email)) && function_exists('get_userdata')) {
            $user_info = get_userdata($user_id);
            if ($user_info) {
                if (empty($buyer_name)) {
                    $buyer_name = $user_info->display_name ?: $user_info->user_login;
                }
                if (empty($buyer_email)) {
                    $buyer_email = $user_info->user_email;
                }
            }
        }
    }

    $shipping_addr = $order['shipping_address_data'] ?? (
        !empty($order['shipping_address']) && is_string($order['shipping_address'])
            ? json_decode($order['shipping_address'], true)
            : []
    );
    if (!is_array($shipping_addr)) {
        $shipping_addr = [];
    }

    if (empty($buyer_email)) {
        $buyer_email = $order['guest_email'] 
            ?? ($order['contact_email'] 
            ?? ($order['custom_fields']['guest_email'] 
            ?? ($order['custom_fields']['_guest_email'] 
            ?? ($order['custom_fields']['contact_email'] 
            ?? ($shipping_addr['guest_email'] 
            ?? ($shipping_addr['email'] ?? ''))))));
    }

    if (empty($buyer_name)) {
        $buyer_name = $order['contact_name'] 
            ?? ($shipping_addr['name'] 
            ?? ($shipping_addr['recipient_name'] 
            ?? ($order['custom_fields']['contact_name'] ?? '')));
        if (empty($buyer_name)) {
            $buyer_name = '访客';
        }
    }

    if (empty($buyer_email)) {
        $buyer_email = '未提供邮箱';
    }

    $amount = !empty($order['payment_amount']) && floatval($order['payment_amount']) > 0 
        ? floatval($order['payment_amount']) 
        : floatval($order['total_amount'] ?? 0);

    $total_amount_fmt = function_exists('todo_format_admin_price') 
        ? todo_format_admin_price($amount) 
        : (function_exists('todo_get_default_currency_config') ? todo_get_default_currency_config()['symbol'] . number_format($amount, 2) : '￥' . number_format($amount, 2));

    $raw_payment_method = $order['payment_method'] ?? '';
    $raw_shipping_method = $order['shipping_method'] ?? ($order['delivery_method'] ?? '');
    $shipping_fee = isset($order['shipping_fee']) ? floatval($order['shipping_fee']) : null;

    $data = [
        'order_no'        => $order['order_no'] ?? $order_id,
        'total_amount'    => $total_amount_fmt,
        'buyer_name'      => $buyer_name,
        'buyer_email'     => $buyer_email,
        'payment_method'  => todo_format_mail_payment_method($raw_payment_method),
        'shipping_method' => $is_recharge_notify ? '账户充值' : todo_format_mail_shipping_method($raw_shipping_method, $shipping_fee),
        'created_at'      => $order['paid_at'] ?? ($order['created_at'] ?? date('Y-m-d H:i:s')),
        'site_name'       => CMS_Option::get('site_title', 'TodoCMS'),
    ];

    return todo_send_admin_notification('order_payment', 'order_payment', $data);
}

/**
 * 格式化邮件支付方式文本（动态读取后端真实配置）
 *
 * @param string $method_code 支付方式代码
 * @return string
 */
function todo_format_mail_payment_method($method_code) {
    $method_code = trim((string)$method_code);
    if ($method_code === '') {
        return '';
    }

    if (!function_exists('todo_get_payment_methods')) {
        $pm_file = ABSPATH . 'includes/shop/payment-methods.php';
        if (file_exists($pm_file)) {
            require_once $pm_file;
        }
    }

    if (function_exists('todo_get_payment_methods')) {
        $methods_list = todo_get_payment_methods();
        if (is_array($methods_list)) {
            foreach ($methods_list as $m) {
                if (is_array($m) && isset($m['code']) && (string)$m['code'] === $method_code && !empty($m['name'])) {
                    return (string)$m['name'];
                }
            }
        }
    }

    if (function_exists('todo_shop_get_service_settings')) {
        $settings = todo_shop_get_service_settings();
        if (isset($settings['payment_methods']) && is_array($settings['payment_methods'])) {
            foreach ($settings['payment_methods'] as $pm) {
                if (is_array($pm) && isset($pm['code']) && (string)$pm['code'] === $method_code && !empty($pm['name'])) {
                    return (string)$pm['name'];
                }
            }
        }
    }

    return $method_code;
}

/**
 * 格式化邮件配送方式文本（动态读取后端真实配置及费用）
 *
 * @param string $shipping_code 配送代码
 * @param float|null $shipping_fee 运费金额
 * @return string
 */
function todo_format_mail_shipping_method($shipping_code, $shipping_fee = null) {
    $shipping_code = trim((string)$shipping_code);
    if ($shipping_code === '') {
        return '';
    }

    if (!function_exists('todo_get_shipping_methods')) {
        $settings_file = ABSPATH . 'core/functions/shop-service-settings.php';
        if (file_exists($settings_file)) {
            require_once $settings_file;
        }
    }

    $name = '';
    if (function_exists('todo_get_shipping_methods')) {
        $configured_methods = todo_get_shipping_methods(false);
        if (is_array($configured_methods)) {
            foreach ($configured_methods as $m) {
                if (isset($m['code']) && (string)$m['code'] === $shipping_code) {
                    $name = !empty($m['name']) ? (string)$m['name'] : '';
                    if ($shipping_fee === null && isset($m['fee'])) {
                        $shipping_fee = floatval($m['fee']);
                    }
                    break;
                }
            }
        }
    }

    if (empty($name)) {
        if ($shipping_code === 'virtual') {
            $name = '虚拟自动交付';
        } else {
            $name = $shipping_code;
        }
    }

    if ($shipping_fee !== null && is_numeric($shipping_fee)) {
        $fee_val = floatval($shipping_fee);
        if ($fee_val > 0) {
            $fee_fmt = function_exists('todo_format_admin_price')
                ? todo_format_admin_price($fee_val)
                : (function_exists('todo_get_default_currency_config') ? (todo_get_default_currency_config()['symbol'] ?? '￥') . number_format($fee_val, 2) : '￥' . number_format($fee_val, 2));
            return "{$name} ({$fee_fmt})";
        } else {
            return "{$name} (免费)";
        }
    }

    return $name;
}

/**
 * 发送管理员订单发货通知邮件
 *
 * @param int|array $order 订单ID或订单数据数组
 * @return bool 是否成功发送
 */
function todo_notify_admin_order_shipping($order, $shipping_data = []) {
    static $sent_shipping_notifications = [];

    if (!todo_should_send_admin_notification('order_shipping')) {
        return false;
    }

    if (is_numeric($order)) {
        $order_id = (int)$order;
        if (class_exists('Todo_Order_Manager')) {
            $order = Todo_Order_Manager::get_order($order_id, false);
        } else {
            global $tododb;
            $order = $tododb->get_row("SELECT * FROM {$tododb->prefix}orders WHERE id = ?", [$order_id], 'ARRAY_A');
        }
    } else {
        $order_id = (int)($order['id'] ?? 0);
    }

    if (!is_array($order) || empty($order) || empty($order_id)) {
        return false;
    }

    if (isset($sent_shipping_notifications[$order_id])) {
        return true;
    }
    $sent_shipping_notifications[$order_id] = true;

    $express_company = !empty($shipping_data['express_company']) ? $shipping_data['express_company'] : ($order['express_company'] ?? '');
    $express_no = !empty($shipping_data['tracking_no']) ? $shipping_data['tracking_no'] : ($order['tracking_no'] ?? ($order['tracking_number'] ?? ''));

    $data = [
        'order_no'        => $order['order_no'] ?? $order_id,
        'express_company' => $express_company ?: '无',
        'express_no'      => $express_no ?: '无',
        'shipping_time'   => date('Y-m-d H:i:s'),
        'site_name'       => CMS_Option::get('site_title', 'TodoCMS'),
    ];

    return todo_send_admin_notification('order_shipping', 'order_shipping', $data);
}

// 注册钩子：新文章插入时触发留言通知；订单支付/货到付款/线下转账提交时触发管理员订单通知
if (function_exists('todo_add_action')) {
    todo_add_action('todo_post_inserted', 'todo_notify_admin_new_guestbook', 10, 2);
    todo_add_action('todo_payment_completed', 'todo_notify_admin_order_payment', 10, 1);
    todo_add_action('todo_order_cod_submitted', 'todo_notify_admin_order_payment', 10, 1);
    todo_add_action('todo_order_offline_submitted', 'todo_notify_admin_order_payment', 10, 1);
}

