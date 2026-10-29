<?php

if (!defined('DOING_AJAX') || !DOING_AJAX) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Direct access denied']);
    exit;
}

global $tododb;
if (!isset($tododb)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '数据库连接不可用']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$action = sanitize_text_field($_POST['action'] ?? '');
if ($action !== 'recruit_apply') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

try {
    // CSRF 验证：token 字段必须存在，缺失即拒绝（防止跳过验证攻击）
    $token_candidates = [];
    if (!empty($_POST['_todononce'])) {
        $token_candidates[] = $_POST['_todononce'];
    }
    if (!empty($_POST['_csrf_token'])) {
        $token_candidates[] = $_POST['_csrf_token'];
    }
    if (empty($token_candidates) || !class_exists('Todo_Security_Manager')) {
        // token 字段缺失或安全类不可用，强制拒绝
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
        exit;
    }
    $csrf_ok = false;
    foreach ($token_candidates as $candidate) {
        if (Todo_Security_Manager::verify_csrf_token($candidate, 'recruit_apply')) {
            $csrf_ok = true;
            break;
        }
    }
    if (!$csrf_ok) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
        exit;
    }

    $post_id = intval($_POST['job_id'] ?? $_POST['post_id'] ?? 0);
    if ($post_id <= 0) {
        throw new Exception('无效的职位ID');
    }

    $post_obj = function_exists('get_post') ? get_post($post_id) : null;
    if (!$post_obj || !isset($post_obj->post_type) || $post_obj->post_type !== 'recruit') {
        throw new Exception('职位不存在');
    }

    $name = sanitize_text_field($_POST['name'] ?? '');
    $phone = sanitize_text_field($_POST['phone'] ?? '');
    $email = sanitize_text_field($_POST['email'] ?? '');
    $expected_salary = sanitize_text_field($_POST['expected_salary'] ?? '');
    $introduction = isset($_POST['introduction']) ? sanitize_textarea_field($_POST['introduction']) : '';

    if ($name === '' || $phone === '' || $email === '') {
        throw new Exception('请填写姓名、手机号与邮箱');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('邮箱格式不正确');
    }

    $table = $tododb->prefix . 'recruit_applications';

    $ip = function_exists('todo_get_user_ip') ? todo_get_user_ip() : '';
    $user_agent = todo_server_string('HTTP_USER_AGENT');

    $now = date('Y-m-d H:i:s');
    $recent = $tododb->get_var(
        "SELECT COUNT(*) FROM {$table} WHERE post_id = ? AND ip_address = ? AND created_at >= DATE_SUB(?, INTERVAL 10 MINUTE) AND status <> 'deleted'",
        [$post_id, $ip, $now]
    );
    if (intval($recent) > 0) {
        throw new Exception('提交过于频繁，请稍后再试');
    }

    $resume_url = '';
    if (!empty($_FILES['resume']) && is_array($_FILES['resume']) && isset($_FILES['resume']['tmp_name']) && $_FILES['resume']['tmp_name'] !== '') {
        $file = $_FILES['resume'];
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('简历上传失败');
        }
        $orig = isset($file['name']) ? (string) $file['name'] : '';
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx'];
        if (!in_array($ext, $allowed, true)) {
            throw new Exception('简历仅支持 PDF/DOC/DOCX');
        }
        if (!function_exists('todo_upload_dir')) {
            throw new Exception('上传功能不可用');
        }
        $upload_dir = todo_upload_dir(true);
        $base_path = rtrim($upload_dir['path'], '/');
        $base_url = rtrim($upload_dir['url'], '/');
        $sub = $base_path . '/recruit';
        if (!is_dir($sub)) {
            mkdir($sub, 0755, true);
        }
        $sub_real = realpath($sub);
        $base_real = realpath($base_path);
        if ($sub_real === false || $base_real === false || strpos($sub_real, $base_real) !== 0) {
            throw new Exception('上传目录不可用');
        }
        $filename = 'resume_' . $post_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $sub_real . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new Exception('简历保存失败');
        }
        chmod($dest, 0644);
        $resume_url = $base_url . '/recruit/' . $filename;
    }

    $user_id = 0;
    if (function_exists('get_current_user_id')) {
        $user_id = intval(get_current_user_id());
    }

    $insert = [
        'post_id' => $post_id,
        'user_id' => $user_id,
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'expected_salary' => $expected_salary,
        'introduction' => $introduction,
        'resume_url' => $resume_url,
        'status' => 'new',
        'ip_address' => $ip,
        'user_agent' => $user_agent,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $ok = $tododb->insert($table, $insert);
    if ($ok === false) {
        throw new Exception('投递失败');
    }

    $apply_count = intval($tododb->get_var("SELECT COUNT(*) FROM {$table} WHERE post_id = ? AND status <> 'deleted'", [$post_id]));

    echo json_encode([
        'success' => true,
        'message' => '申请提交成功',
        'data' => [
            'application_id' => method_exists($tododb, 'insert_id') ? $tododb->insert_id() : null,
            'apply_count' => $apply_count,
        ]
    ]);
    exit;
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
