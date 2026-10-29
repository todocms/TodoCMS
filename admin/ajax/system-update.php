<?php

if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}
if (!defined('TODO_LOADED')) {
    define('TODO_LOADED', true);
}

header('Content-Type: application/json; charset=utf-8');

try {
    // 核心安全保障：即使用户中途关闭了浏览器或弹窗，也必须让 PHP 强制把当前这一步（特别是文件替换）执行完毕，防止出现文件换到一半系统崩溃的“半残”状态
    if (function_exists('ignore_user_abort')) {
        @ignore_user_abort(true);
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!class_exists('Todo_Session_Manager')) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => '会话管理器无法加载'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    Todo_Session_Manager::init();
    if (!Todo_Session_Manager::is_admin_logged_in()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '权限不足'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('current_user_can') && !current_user_can('manage_options')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '缺少更新管理权限'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (class_exists('Todo_Security_Manager')) {
        Todo_Security_Manager::verify_ajax_csrf('system_upgrade');
    }
    if (!class_exists('Todo_Update_Manager')) {
        $um = ABSPATH . 'core/classes/class-update-manager.php';
        if (is_file($um)) {
            require_once $um;
        }
    }
    if (!class_exists('Todo_Update_Manager')) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => '更新组件无法加载'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('todo_has_update_authorization') && !todo_has_update_authorization()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '未获得更新授权'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $op = isset($_POST['op']) ? (string) $_POST['op'] : (isset($_GET['op']) ? (string) $_GET['op'] : '');
    $op = trim($op);
    if ($op === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '缺少 op 参数'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $baseDir = rtrim((string) ABSPATH, '/') . '/content/updates';
    $jobDir = $baseDir . '/jobs';
    if (!is_dir($jobDir)) {
        @mkdir($jobDir, 0775, true);
    }
    // 添加目录安全防护文件，防止目录列出及静态文件直接访问
    if (!is_file($baseDir . '/.htaccess')) {
        @file_put_contents($baseDir . '/.htaccess', "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
    }
    if (!is_file($baseDir . '/index.html')) {
        @file_put_contents($baseDir . '/index.html', '');
    }
    if (!is_file($jobDir . '/.htaccess')) {
        @file_put_contents($jobDir . '/.htaccess', "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
    }
    if (!is_file($jobDir . '/index.html')) {
        @file_put_contents($jobDir . '/index.html', '');
    }

    // 自动清理多余及过期（超过 300 秒）的临时任务文件
    if (is_dir($jobDir)) {
        $now = time();
        $files = @scandir($jobDir);
        if (is_array($files)) {
            foreach ($files as $f) {
                if ($f === '.' || $f === '..' || $f === '.htaccess' || $f === 'index.html') {
                    continue;
                }
                $fp = $jobDir . '/' . $f;
                if (is_file($fp) && ($now - @filemtime($fp)) > 300) {
                    @unlink($fp);
                }
            }
        }
    }

    $read_job = function ($job_id) use ($jobDir) {
        $p = $jobDir . '/job_' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $job_id) . '.json';
        if (!is_file($p)) {
            return null;
        }
        $raw = (string) @file_get_contents($p);
        $data = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($data) ? $data : null;
    };

    $delete_job_files = function ($job_id) use ($jobDir) {
        $job_id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $job_id);
        if ($job_id === '') {
            return;
        }
        $p = $jobDir . '/job_' . $job_id . '.json';
        $lock = $jobDir . '/job_' . $job_id . '.lock';
        $tmp = $p . '.tmp';
        if (is_file($p)) @unlink($p);
        if (is_file($lock)) @unlink($lock);
        if (is_file($tmp)) @unlink($tmp);
    };

    $write_job = function ($job) use ($jobDir) {
        $job_id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($job['id'] ?? ''));
        if ($job_id === '') {
            return false;
        }
        $p = $jobDir . '/job_' . $job_id . '.json';
        $tmp = $p . '.tmp';
        $job['updated_at'] = time();
        $raw = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($raw === false) {
            return false;
        }
        if (@file_put_contents($tmp, $raw, LOCK_EX) === false) {
            return false;
        }
        return @rename($tmp, $p);
    };

    $with_job_lock = function ($job_id, callable $fn) use ($jobDir) {
        $job_id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $job_id);
        if ($job_id === '') {
            return ['success' => false, 'error' => '无效 job_id'];
        }
        $lock = $jobDir . '/job_' . $job_id . '.lock';
        $fp = @fopen($lock, 'c+');
        if (!$fp) {
            return ['success' => false, 'error' => '无法创建锁文件'];
        }
        if (!@flock($fp, LOCK_EX)) {
            @fclose($fp);
            return ['success' => false, 'error' => '无法获取锁'];
        }
        try {
            return $fn();
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    };

    $make_response = function ($job) {
        $total = isset($job['steps']) && is_array($job['steps']) ? count($job['steps']) : 0;
        $idx = isset($job['step_index']) ? (int) $job['step_index'] : 0;
        $current = min($idx, $total);
        $step = '';
        if ($idx < $total && isset($job['steps'][$idx])) {
            $step = (string) $job['steps'][$idx];
        }
        return [
            'id' => $job['id'] ?? '',
            'mode' => $job['mode'] ?? '',
            'version' => $job['version'] ?? '',
            'status' => $job['status'] ?? '',
            'step' => $step,
            'step_index' => $idx,
            'total_steps' => $total,
            'progress_current' => $current,
            'progress_total' => $total,
            'message' => $job['message'] ?? '',
            'error' => $job['error'] ?? '',
            'logs' => $job['logs'] ?? [],
            'redirect' => $job['redirect'] ?? '',
            'meta' => $job['meta'] ?? [],
        ];
    };

    if ($op === 'start') {
        $mode = isset($_POST['mode']) ? trim((string) $_POST['mode']) : 'full';
        $version = isset($_POST['version']) ? trim((string) $_POST['version']) : '';

        $allowed = ['download', 'install', 'backup', 'full'];
        if (!in_array($mode, $allowed, true)) {
            $mode = 'full';
        }

        $steps = [];
        if ($mode === 'backup') {
            $steps = ['backup'];
        } elseif ($mode === 'download') {
            $steps = ['request_download', 'download', 'persist_meta'];
        } elseif ($mode === 'install') {
            $steps = ['load_package', 'backup', 'install', 'db_update'];
        } else {
            $steps = ['request_download', 'download', 'persist_meta', 'backup', 'install', 'db_update'];
        }

        // 自动清理超过 1 小时的临时任务文件，防止积累
        if (is_dir($jobDir)) {
            $now = time();
            $files = @scandir($jobDir);
            if (is_array($files)) {
                foreach ($files as $f) {
                    if ($f === '.' || $f === '..') {
                        continue;
                    }
                    $fp = $jobDir . '/' . $f;
                    // 如果文件最后修改时间距今超过 3600 秒（1小时），则安全删除
                    if (is_file($fp) && ($now - @filemtime($fp)) > 3600) {
                        @unlink($fp);
                    }
                }
            }
        }

        $job_id = bin2hex(function_exists('random_bytes') ? random_bytes(8) : openssl_random_pseudo_bytes(8));
        $job = [
            'id' => $job_id,
            'mode' => $mode,
            'version' => $version,
            'status' => 'pending',
            'steps' => $steps,
            'step_index' => 0,
            'created_at' => time(),
            'updated_at' => time(),
            'logs' => [],
            'meta' => [],
            'message' => '',
            'error' => '',
        ];

        if (!$write_job($job)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => '创建任务失败'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(['success' => true, 'data' => $make_response($job)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $job_id = isset($_POST['job_id']) ? (string) $_POST['job_id'] : (isset($_GET['job_id']) ? (string) $_GET['job_id'] : '');
    $job_id = trim($job_id);
    if ($job_id === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '缺少 job_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($op === 'status') {
        $job = $read_job($job_id);
        if (!$job) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => '任务不存在'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['success' => true, 'data' => $make_response($job)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($op === 'cancel') {
        $res = $with_job_lock($job_id, function () use ($job_id, $read_job, $write_job, $delete_job_files, $make_response) {
            $job = $read_job($job_id);
            if (!$job) {
                return ['success' => false, 'error' => '任务不存在'];
            }
            if (in_array(($job['status'] ?? ''), ['done', 'done_relogin'], true)) {
                return ['success' => true, 'data' => $make_response($job)];
            }
            $job['status'] = 'cancelled';
            $job['message'] = '任务已取消';
            $resp = ['success' => true, 'data' => $make_response($job)];
            $delete_job_files($job_id);
            return $resp;
        });
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($op !== 'step') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '无效 op'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $res = $with_job_lock($job_id, function () use ($job_id, $read_job, $write_job, $delete_job_files, $make_response, $baseDir) {
        $job = $read_job($job_id);
        if (!$job) {
            return ['success' => false, 'error' => '任务不存在'];
        }

        $status = (string) ($job['status'] ?? '');
        if (in_array($status, ['done', 'error', 'cancelled'], true)) {
            return ['success' => true, 'data' => $make_response($job)];
        }

        $steps = isset($job['steps']) && is_array($job['steps']) ? $job['steps'] : [];
        $idx = isset($job['step_index']) ? (int) $job['step_index'] : 0;
        if ($idx < 0) {
            $idx = 0;
        }
        if ($idx >= count($steps)) {
            $job['status'] = 'done';
            $job['message'] = '已完成';
            $write_job($job);
            return ['success' => true, 'data' => $make_response($job)];
        }

        $step = (string) $steps[$idx];
        $job['status'] = 'running';
        $job['message'] = $step;
        $job['error'] = '';
        $write_job($job);

        $log = function ($line) use (&$job) {
            $line = trim((string) $line);
            if ($line === '') {
                return;
            }
            if (!isset($job['logs']) || !is_array($job['logs'])) {
                $job['logs'] = [];
            }
            $job['logs'][] = '[' . date('H:i:s') . '] ' . $line;
            if (count($job['logs']) > 200) {
                $job['logs'] = array_slice($job['logs'], -200);
            }
        };

        $fail = function ($error) use (&$job, $write_job, $make_response) {
            $job['status'] = 'error';
            $job['error'] = (string) $error;
            $job['message'] = '失败';
            $write_job($job);
            return ['success' => true, 'data' => $make_response($job)];
        };

        $ok = false;
        try {
            if ($step === 'request_download') {
                $version = (string) ($job['version'] ?? '');
                $log('申请下载：' . ($version !== '' ? $version : 'latest'));
                $req = Todo_Update_Manager::request_download($version);
                if (empty($req['ok']) || empty($req['download_url'])) {
                    return $fail('下载申请失败：' . (string) ($req['error'] ?? 'unknown'));
                }
                $job['meta']['download_url'] = (string) $req['download_url'];
                $job['meta']['download_version'] = (string) ($req['version'] ?? $version);
                $job['version'] = (string) ($req['version'] ?? $version);
                $ok = true;
            } elseif ($step === 'download') {
                $url = (string) ($job['meta']['download_url'] ?? '');
                if ($url === '') {
                    return $fail('缺少下载地址');
                }
                $log('下载更新包');
                $dl = Todo_Update_Manager::download_package($url);
                if (empty($dl['ok']) || empty($dl['path'])) {
                    return $fail('下载失败：' . (string) ($dl['error'] ?? 'unknown'));
                }
                $job['meta']['zip_path'] = (string) $dl['path'];
                $ok = true;
            } elseif ($step === 'persist_meta') {
                $zip = (string) ($job['meta']['zip_path'] ?? '');
                if ($zip === '' || !is_file($zip)) {
                    return $fail('更新包不存在');
                }
                $meta = [
                    'version' => (string) ($job['version'] ?? ''),
                    'path' => $zip,
                    'time' => time(),
                ];
                $p = rtrim((string) $baseDir, '/') . '/last_download.json';
                if (!is_dir(dirname($p))) {
                    @mkdir(dirname($p), 0775, true);
                }
                @file_put_contents($p, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $log('已写入下载记录');
                $ok = true;
            } elseif ($step === 'load_package') {
                if (function_exists('todo_has_update_authorization') && !todo_has_update_authorization()) {
                    return $fail('未获更新授权');
                }
                $p = rtrim((string) $baseDir, '/') . '/last_download.json';
                $zip = '';
                if (is_file($p)) {
                    $meta = json_decode((string) @file_get_contents($p), true);
                    $zip = is_array($meta) ? (string) ($meta['path'] ?? '') : '';
                }
                if ($zip === '' || !is_file($zip)) {
                    return $fail('未找到已下载的更新包');
                }
                $job['meta']['zip_path'] = $zip;
                $log('已加载更新包：' . basename($zip));
                $ok = true;
            } elseif ($step === 'backup') {
                $log('创建备份');
                $backup = Todo_Update_Manager::backup_before_upgrade();
                if (empty($backup['ok']) || empty($backup['path'])) {
                    return $fail('备份失败：' . (string) ($backup['error'] ?? 'unknown'));
                }
                $job['meta']['backup_path'] = (string) $backup['path'];
                $log('备份完成：' . basename((string) $backup['path']));
                $ok = true;
            } elseif ($step === 'install') {
                $zip = (string) ($job['meta']['zip_path'] ?? '');
                if ($zip === '' || !is_file($zip)) {
                    return $fail('更新包不存在');
                }
                $log('安装更新包');
                $ins = Todo_Update_Manager::install_update($zip);
                if (empty($ins['ok'])) {
                    $errCode = (string) ($ins['error'] ?? 'unknown');
                    if ($errCode === 'permission_denied') {
                        $files = !empty($ins['unwritable_files']) && is_array($ins['unwritable_files']) ? implode(', ', $ins['unwritable_files']) : '';
                        $total = (int) ($ins['total_unwritable'] ?? 0);
                        $msg = "权限不足：共有 {$total} 个文件/目录缺乏写权限（如: {$files}）。更新已安全终止，未修改任何文件。";
                        return $fail($msg);
                    }
                    if ($errCode === 'copy_failed_partial') {
                        return $fail('覆盖更新遇到文件权限错误，部分文件未能拷贝完成。');
                    }
                    return $fail('安装失败：' . $errCode);
                }
                if (!empty($ins['adminBase'])) {
                    $job['meta']['admin_base_file'] = (string) $ins['adminBase'];
                }
                $ok = true;
            } elseif ($step === 'db_update') {
                $log('执行数据库同步');
                if (function_exists('todo_db_schema_sync')) {
                    todo_db_schema_sync();
                }
                if (function_exists('todo_maybe_run_db_updates')) {
                    $log('执行数据库更新');
                    $dbu = todo_maybe_run_db_updates();
                    if (is_array($dbu) && empty($dbu['ok'])) {
                        return $fail('数据库更新失败：' . (string) ($dbu['error'] ?? 'unknown'));
                    }
                    if (is_array($dbu)) {
                        $job['meta']['db_update'] = $dbu;
                    }
                } else {
                    $log('数据库更新器不存在，跳过');
                }
                $ok = true;
            } else {
                return $fail('未知步骤：' . $step);
            }
        } catch (Throwable $e) {
            return $fail($e->getMessage());
        }

        if (!$ok) {
            return $fail('步骤执行失败');
        }

        $job['step_index'] = $idx + 1;
        if ($job['step_index'] >= count($steps)) {
            $is_install_job = in_array($job['mode'], ['install', 'full'], true);
            if ($is_install_job) {
                $job['status'] = 'done_relogin';

                if (class_exists('Todo_Core_Kernel') && is_callable(['Todo_Core_Kernel', 'update_option_best_effort'])) {
                    Todo_Core_Kernel::update_option_best_effort('todo_force_relogin', time());
                } else {
                    if (function_exists('todo_update_option')) {
                        todo_update_option('todo_force_relogin', time());
                    } elseif (class_exists('CMS_Option')) {
                        CMS_Option::update('todo_force_relogin', time());
                    }
                }

                $adminBase = trim((string) ($job['meta']['admin_base_file'] ?? ''));
                if ($adminBase === '') {
                    $adminBase = 'todo-login';
                }

                $login_msg = urlencode('版本更新已完成，请重新登录');

                $site_url = function_exists('todo_get_site_url') ? todo_get_site_url() : '';
                $base_admin = rtrim($site_url, '/') . '/admin/';
                $job['redirect'] = $base_admin . $adminBase . '.php?login_message=' . $login_msg;
                
                unset($_SESSION['admin_user_id'], $_SESSION['todo_admin_uid'], $_SESSION['user_id']);
                @session_destroy();
                
            } else {
                $job['status'] = 'done';
            }
            $job['message'] = '已完成';
            // 清理 meta 敏感路径
            unset($job['meta']['backup_path'], $job['meta']['zip_path'], $job['meta']['download_url']);
        } else {
            $job['status'] = 'pending';
            $job['message'] = '就绪';
        }
        $resp_data = ['success' => true, 'data' => $make_response($job)];
        if (in_array($job['status'], ['done', 'done_relogin'], true)) {
            $delete_job_files($job_id);
        } else {
            $write_job($job);
        }
        return $resp_data;
    });

    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '系统更新接口异常', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
