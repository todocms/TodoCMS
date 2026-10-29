<?php
/**
 * 前台留言表单提交接口
 *
 * @package TodoCMS
 */

// 开启输出缓冲
ob_start();

// 加载核心引导文件
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/core/bootstrap.php';
require_once CORE_DIR . '/functions/function-loader.php';
Todo_Function_Loader::load_core_functions();

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

header('Content-Type: application/json; charset=utf-8');

// 仅接受 POST 请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => __('请求方法不允许')], JSON_UNESCAPED_UNICODE);
    exit;
}

// PRG 防重复提交守卫：必须在验证码消耗之前，重复请求直接返回缓存结果
Todo_PRG_Guard::guard_endpoint('guestbook_submit', 30);

// 获取用户 IP
$client_ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');

// 验证滑块验证码
if (!isset($_SESSION['todo_captcha_verified']) || $_SESSION['todo_captcha_verified'] !== true) {
    echo json_encode(['success' => false, 'message' => __('请先完成安全验证')], JSON_UNESCAPED_UNICODE);
    exit;
}
// 验证成功后消耗掉，防止重放
$_SESSION['todo_captcha_verified'] = false;

// 获取并验证表单数据
$guest_name    = isset($_POST['guest_name']) ? trim((string) $_POST['guest_name']) : '';
$guest_email   = isset($_POST['guest_email']) ? trim((string) $_POST['guest_email']) : '';
$guest_phone   = isset($_POST['guest_phone']) ? trim((string) $_POST['guest_phone']) : '';
$guest_subject = isset($_POST['guest_subject']) ? trim((string) $_POST['guest_subject']) : '';
$guest_message = isset($_POST['guest_message']) ? trim((string) $_POST['guest_message']) : '';
$category_id   = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;

// === 输入安全清洗：去除 HTML 标签，防止存储型 XSS ===
// 留言板不需要支持 HTML，纯文本即可
$guest_name    = strip_tags($guest_name);
$guest_subject = strip_tags($guest_subject);
$guest_message = strip_tags($guest_message);
$guest_phone   = strip_tags($guest_phone);

// 清洗后重新 trim（strip_tags 可能留空白）
$guest_name    = trim($guest_name);
$guest_subject = trim($guest_subject);
$guest_message = trim($guest_message);
$guest_phone   = trim($guest_phone);

// 过滤 Unicode 控制字符（防止双向文本欺骗等攻击）
$guest_name    = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u', '', $guest_name);
$guest_subject = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u', '', $guest_subject);
$guest_message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u', '', $guest_message);

// 必填字段验证
if ($guest_name === '') {
    echo json_encode(['success' => false, 'message' => __('请输入姓名')], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($guest_message === '') {
    echo json_encode(['success' => false, 'message' => __('请输入留言内容')], JSON_UNESCAPED_UNICODE);
    exit;
}

// 长度限制
if (mb_strlen($guest_name) > 50) {
    echo json_encode(['success' => false, 'message' => __('姓名不能超过50个字符')], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($guest_message) > 2000) {
    echo json_encode(['success' => false, 'message' => __('留言内容不能超过2000个字符')], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($guest_subject) > 200) {
    echo json_encode(['success' => false, 'message' => __('主题不能超过200个字符')], JSON_UNESCAPED_UNICODE);
    exit;
}

// 验证邮箱格式
if ($guest_email !== '' && !filter_var($guest_email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => __('邮箱格式不正确')], JSON_UNESCAPED_UNICODE);
    exit;
}

// 验证电话格式（仅允许数字、空格、短横线、加号、括号）
if ($guest_phone !== '' && !preg_match('/^[0-9\s\-\+\(\)]{7,30}$/', $guest_phone)) {
    echo json_encode(['success' => false, 'message' => __('电话格式不正确')], JSON_UNESCAPED_UNICODE);
    exit;
}

// 防灌水：同一 IP 短时间提交频率限制（仅针对验证码失败）
if (function_exists('todo_check_captcha_lockout')) {
    $lockout = todo_check_captcha_lockout($client_ip);
    if ($lockout) {
        echo json_encode(['success' => false, 'message' => sprintf(__('操作过于频繁，请在 %s 分钟后再试'), $lockout['wait_minutes'])], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

global $tododb;

// === 防刷留言：成功提交频率限制 ===

// 1. 同一 IP 速率限制：每分钟最多 1 条
$one_min_ago = date('Y-m-d H:i:s', time() - 60);
$recent_count = (int) $tododb->get_var(
    "SELECT COUNT(*) FROM {$tododb->prefix}login_attempts 
     WHERE login_name = '__GUESTBOOK_SUBMIT__' AND ip_address = ? AND attempt_time > ?",
    [$client_ip, $one_min_ago]
);
if ($recent_count >= 1) {
    echo json_encode(['success' => false, 'message' => __('提交过于频繁，请稍后再试')], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. 同一 IP 每日上限：最多 5 条
$one_day_ago = date('Y-m-d H:i:s', time() - 86400);
$day_count = (int) $tododb->get_var(
    "SELECT COUNT(*) FROM {$tododb->prefix}login_attempts 
     WHERE login_name = '__GUESTBOOK_SUBMIT__' AND ip_address = ? AND attempt_time > ?",
    [$client_ip, $one_day_ago]
);
if ($day_count >= 5) {
    echo json_encode(['success' => false, 'message' => __('今日留言次数已达上限，请明天再试')], JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. 同一 Session 冷却：两次提交间隔至少 60 秒
if (isset($_SESSION['last_guestbook_submit_time'])) {
    $elapsed = time() - (int) $_SESSION['last_guestbook_submit_time'];
    if ($elapsed < 60) {
        echo json_encode(['success' => false, 'message' => sprintf(__('提交过于频繁，请 %d 秒后再试'), (60 - $elapsed))], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 4. 内容去重：同一 IP 60 秒内不允许提交相同内容
if ($guest_message !== '') {
    $dup = $tododb->get_var(
        "SELECT COUNT(*) FROM {$tododb->prefix}posts 
         WHERE post_type = 'guestbook' AND post_content = ? 
         AND post_date > ?",
        [$guest_message, $one_min_ago]
    );
    if ($dup > 0) {
        echo json_encode(['success' => false, 'message' => __('请勿重复提交相同内容')], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 确定留言状态（是否需要审核）
$need_approval = function_exists('get_option') ? get_option('guestbook_need_approval', true) : true;
// 如果未启用验证码则不强制审核
if (function_exists('get_option') && !get_option('captcha_enabled', false)) {
    $need_approval = false;
}
$post_status = $need_approval ? 'pending' : 'publish';

$post_title = $guest_subject !== '' ? $guest_subject : mb_substr($guest_message, 0, 50);

// 获取 guestbook 模型 ID（用于关联自定义字段和模型）
$guestbook_model_id = 0;
if (class_exists('Todo_Model_Manager')) {
    $guestbook_model_id = (int) Todo_Model_Manager::get_model_id_by_key('guestbook');
}
// 如果上面的方法无效，直接从 models 表查询
if (!$guestbook_model_id) {
    $model_row = $tododb->get_row(
        "SELECT model_id FROM " . TABLE_PREFIX . "models WHERE model_key = 'guestbook' LIMIT 1"
    );
    $guestbook_model_id = $model_row ? (int) $model_row->model_id : 0;
}

try {
    $tododb->begin_transaction();

    // 插入 posts 表
    $post_data = [
        'post_title'        => $post_title,
        'post_content'      => $guest_message,
        'post_status'       => $post_status,
        'post_type'         => 'guestbook',
        'post_author'       => 0,
        'comment_status'    => 'open',
        'post_excerpt'      => '',
        'seo_description'   => '',
        'model_id'          => $guestbook_model_id,
    ];
    
    // 分类
    if ($category_id > 0) {
        $post_data['category_id'] = $category_id;
    }

    $result = $tododb->insert(TABLE_PREFIX . 'posts', $post_data);
    if (!$result) {
        throw new Exception('插入留言失败');
    }

    $post_id = $tododb->insert_id();

    // 生成 post_name
    $slug = function_exists('todo_generate_slug') 
        ? todo_generate_slug($post_title, $post_id) 
        : sanitize_title($post_title) . '-' . $post_id;
    
    $tododb->update(TABLE_PREFIX . 'posts', ['post_name' => $slug], ['ID' => $post_id]);

    // 存储固定字段到模型专用表 model_fields_guestbook
    $model_fields_data = [
        'post_id'        => $post_id,
        'contact_name'   => $guest_name,
        'contact_email'  => $guest_email,
        'contact_phone'  => $guest_phone,
        'message_status' => 'pending',
    ];

    $exists = $tododb->get_var(
        "SELECT COUNT(*) FROM " . TABLE_PREFIX . "model_fields_guestbook WHERE post_id = ?",
        [$post_id]
    );

    if ($exists) {
        $tododb->update(TABLE_PREFIX . 'model_fields_guestbook', $model_fields_data, ['post_id' => $post_id]);
    } else {
        $tododb->insert(TABLE_PREFIX . 'model_fields_guestbook', $model_fields_data);
    }

    // 记录 IP 到 post_custom_fields（非模型固定字段）
    if ($client_ip) {
        $tododb->query(
            "INSERT INTO " . TABLE_PREFIX . "post_custom_fields (post_id, model_id, field_key, field_value, field_type)
             VALUES (?, ?, ?, ?, 'text')
             ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)",
            [$post_id, $guestbook_model_id, 'submit_ip', $client_ip]
        );
    }

    // 查询模型字段类型与配置（用于判断 multi_file 等特殊字段及校验规则）
    $model_fields_meta = [];
    $model_field_types = [];
    if ($guestbook_model_id > 0) {
        $field_rows = $tododb->get_results(
            "SELECT field_key, field_type, field_name, is_required, field_options FROM " . TABLE_PREFIX . "model_fields WHERE model_id = ?",
            [$guestbook_model_id]
        );
        if (is_array($field_rows)) {
            foreach ($field_rows as $row) {
                $row = is_object($row) ? (array) $row : $row;
                $fkey = $row['field_key'] ?? '';
                if ($fkey !== '') {
                    $model_field_types[$fkey] = $row['field_type'] ?? 'text';
                    $opts = [];
                    if (!empty($row['field_options'])) {
                        $decoded_opts = json_decode($row['field_options'], true);
                        if (is_array($decoded_opts)) {
                            $opts = $decoded_opts;
                        } elseif (function_exists('maybe_unserialize')) {
                            $unser = maybe_unserialize($row['field_options']);
                            if (is_array($unser)) {
                                $opts = $unser;
                            }
                        }
                    }
                    $model_fields_meta[$fkey] = [
                        'type'        => $row['field_type'] ?? 'text',
                        'name'        => $row['field_name'] ?? $fkey,
                        'is_required' => !empty($row['is_required']),
                        'options'     => $opts,
                    ];
                }
            }
        }
    }

    // 保存模型自定义字段（文本类）
    $known_fields = ['guest_name', 'guest_email', 'guest_phone', 'guest_subject', 'guest_message', 'category_id'];
    $region_suffixes = ['_province', '_city', '_district', '_town', '_detail'];
    foreach ($_POST as $key => $value) {
        if (!in_array($key, $known_fields, true) && is_string($value) && $value !== '') {
            // 跳过地区字段的子字段（_province/_city 等），它们汇总后通过隐藏域提交
            foreach ($region_suffixes as $suffix) {
                if (substr($key, -strlen($suffix)) === $suffix) {
                    $base_key = substr($key, 0, -strlen($suffix));
                    if (isset($model_field_types[$base_key]) && $model_field_types[$base_key] === 'region') {
                        continue 2; // 跳到外层 foreach 的下一条
                    }
                }
            }
            $ftype = $model_field_types[$key] ?? 'text';
            // multi_file 字段走 $_FILES 处理流程，不由 $_POST 写入
            if ($ftype === 'multi_file') {
                continue;
            }
            $clean_val = trim($value);
            if ($clean_val !== '' && function_exists('todo_media_url_to_relative')) {
                $clean_val = todo_media_url_to_relative($clean_val);
            }
            $tododb->query(
                "INSERT INTO " . TABLE_PREFIX . "post_custom_fields (post_id, model_id, field_key, field_value, field_type)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)",
                [$post_id, $guestbook_model_id, $key, $clean_val, $ftype]
            );
        }
    }

    // 处理文件上传字段（multi_file 类型）
    // 强制受后台安全策略监管：全局上传开关、大小限制、类型白名单、危险后缀黑名单拦截、木马内容检测
    $multi_file_fields = array_filter($model_fields_meta, function ($m) {
        return ($m['type'] ?? '') === 'multi_file';
    });

    if (!empty($multi_file_fields)) {
        $enable_upload = function_exists('get_option') ? get_option('enable_upload', true) : true;
        $max_allowed_size = 10485760;
        if (class_exists('TodoUploadConfig')) {
            $max_allowed_size = (int) TodoUploadConfig::get_max_upload_size();
        } elseif (function_exists('get_option')) {
            $mb = get_option('max_upload_size', null);
            if ($mb !== null && is_numeric($mb) && (int) $mb > 0) {
                $max_allowed_size = (int) $mb * 1024 * 1024;
            }
        }
        $size_limit_text = function_exists('size_format') ? size_format($max_allowed_size) : ($max_allowed_size / 1048576) . 'MB';

        foreach ($multi_file_fields as $field_key => $fmeta) {
            $field_title = $fmeta['name'] ?? $field_key;
            $f_opts = $fmeta['options'] ?? [];
            $max_files = isset($f_opts['max_files']) && (int) $f_opts['max_files'] > 0 ? min((int) $f_opts['max_files'], 20) : 10;
            $target_file_type = $f_opts['file_type'] ?? 'any'; // 'image', 'document', 'archive', 'any'

            $file_info = $_FILES[$field_key] ?? null;
            $files = [];

            if ($file_info && !empty($file_info['name'])) {
                if (is_array($file_info['name'])) {
                    foreach ($file_info['name'] as $i => $name) {
                        if (empty($name) || ($file_info['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                            continue;
                        }
                        $files[] = [
                            'name'     => $name,
                            'type'     => $file_info['type'][$i] ?? '',
                            'tmp_name' => $file_info['tmp_name'][$i] ?? '',
                            'error'    => $file_info['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                            'size'     => $file_info['size'][$i] ?? 0,
                        ];
                    }
                } else {
                    if (($file_info['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $files[] = $file_info;
                    }
                }
            }

            // 必填校验
            if ($fmeta['is_required'] && empty($files)) {
                throw new Exception(sprintf(__('请上传%s'), $field_title));
            }

            if (empty($files)) {
                continue;
            }

            // 上传总开关检查
            if (!$enable_upload || $max_allowed_size <= 0) {
                throw new Exception(__('文件上传功能已被管理员禁用'));
            }

            // 文件数量限制
            if (count($files) > $max_files) {
                throw new Exception(sprintf(__('%s最多只能上传 %d 个文件'), $field_title, $max_files));
            }

            $uploaded_ids = [];
            foreach ($files as $file) {
                if ($file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !file_exists($file['tmp_name'])) {
                    throw new Exception(sprintf(__('文件 [%s] 上传失败，错误码：%s'), $file['name'], $file['error']));
                }

                if (!is_uploaded_file($file['tmp_name'])) {
                    throw new Exception(__('非法上传源文件'));
                }

                if ($file['size'] > $max_allowed_size) {
                    throw new Exception(sprintf(__('文件 [%s] 大小超过系统限制（最大允许 %s）'), $file['name'], $size_limit_text));
                }

                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                // 校验文件类型是否在后台允许列表中（全部受后台配置动态管理，不使用硬编码黑名单）
                $filetype = todo_check_filetype($file['name']);
                if (empty($filetype['ext']) || (class_exists('TodoUploadConfig') && !TodoUploadConfig::is_type_allowed($filetype['type'], $filetype['ext']))) {
                    throw new Exception(sprintf(__('系统未开放该类型文件上传（%s）'), $ext ?: $file['name']));
                }

                // 校验字段配置的 file_type（如模型字段明确配置仅允许图片）
                if ($target_file_type === 'image') {
                    $img_info = @getimagesize($file['tmp_name']);
                    if ($img_info === false) {
                        throw new Exception(sprintf(__('文件 [%s] 不是有效的图片文件'), $file['name']));
                    }
                }

                // 执行上传
                $media_id = CMS_Media::upload($file, [
                    'upload_user'       => 0,
                    'media_description' => __('前台留言板附件')
                ]);
                if ($media_id) {
                    $uploaded_ids[] = $media_id;
                } else {
                    throw new Exception(sprintf(__('文件 [%s] 保存失败'), $file['name']));
                }
            }

            if (!empty($uploaded_ids)) {
                $file_list = [];
                foreach ($uploaded_ids as $index => $media_id) {
                    $attachment = CMS_Media::get_attachment($media_id);
                    if ($attachment) {
                        $file_url = $attachment['url'];
                        if (function_exists('todo_media_url_to_relative')) {
                            $file_url = todo_media_url_to_relative($file_url);
                        }
                        $file_list[] = [
                            'id'    => (int) $attachment['id'],
                            'url'   => $file_url,
                            'title' => $attachment['title'],
                            'index' => $index,
                        ];
                    }
                }
                $stored_value = json_encode($file_list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $tododb->query(
                    "INSERT INTO " . TABLE_PREFIX . "post_custom_fields (post_id, model_id, field_key, field_value, field_type)
                     VALUES (?, ?, ?, ?, 'multi_file')
                     ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)",
                    [$post_id, $guestbook_model_id, $field_key, $stored_value]
                );
            }
        }
    }

    // 处理分类关联
    if ($category_id > 0 && function_exists('todo_set_post_categories')) {
        todo_set_post_categories($post_id, [$category_id]);
    }

    $tododb->commit();

    // 触发插入钩子（邮件通知等）
    if (function_exists('todo_do_action')) {
        $postarr = array_merge($post_data, [
            'contact_name'  => $guest_name,
            'contact_email' => $guest_email,
            'contact_phone' => $guest_phone,
        ]);
        todo_do_action('todo_post_inserted', $post_id, $postarr);
    }

    // 记录提交成功（用于速率限制统计）
    $tododb->insert(
        "{$tododb->prefix}login_attempts",
        [
            'login_name'   => '__GUESTBOOK_SUBMIT__',
            'ip_address'   => (string) $client_ip,
            'success'      => 1,
            'attempt_time' => current_time('mysql'),
        ]
    );

    // 记录 Session 最后提交时间
    $_SESSION['last_guestbook_submit_time'] = time();

    echo json_encode([
        'success'       => true,
        'message'       => __('留言提交成功'),
        'need_approval' => $need_approval,
        'post_id'       => $post_id,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if (isset($tododb) && method_exists($tododb, 'rollback')) {
        $tododb->rollback();
    }
    
    error_log('留言提交失败: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => __('提交失败')], JSON_UNESCAPED_UNICODE);
}

exit;
