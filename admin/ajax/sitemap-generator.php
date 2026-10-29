<?php
/**
 * AJAX Sitemap Generator Handler
 * Supports both full generation and incremental updates
 *
 * @package TodoCMS
 * @version 2.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 检查权限
if (!current_user_can('manage_options')) {
    echo json_encode(['error' => '权限不足']);
    exit;
}

// 验证 CSRF
if (!Todo_Security_Manager::verify_request_csrf('update_seo_settings', '_todononce')) {
    echo json_encode(['error' => '安全验证失败']);
    exit;
}

// 加载 Sitemap Service
if (!class_exists('Todo_Sitemap_Service')) {
    Todo_Function_Loader::load_function('sitemap-service');
}

// 判断是否增量模式
$mode = isset($_POST['mode']) ? $_POST['mode'] : 'full';

try {
    if ($mode === 'incremental') {
        $results = Todo_Sitemap_Service::generate_incremental();

        // 统计变更情况
        $changed_types  = [];
        $unchanged_types = [];
        foreach ($results as $type => $status) {
            if ($status === 'no_changes') {
                $unchanged_types[] = $type;
            } elseif ($status) {
                $changed_types[] = $type;
            }
        }

        if (empty($changed_types) && !empty($unchanged_types)) {
            echo json_encode([
                'success' => true,
                'mode'    => 'incremental',
                'message' => '没有检测到内容变更，所有站点地图均为最新。',
                'details' => $results,
            ]);
        } else {
            $msg_parts = [];
            if (!empty($changed_types)) {
                $msg_parts[] = '以下类型已更新: ' . implode(', ', $changed_types);
            }
            if (!empty($unchanged_types)) {
                $msg_parts[] = '以下类型无变更已跳过: ' . implode(', ', $unchanged_types);
            }
            echo json_encode([
                'success' => true,
                'mode'    => 'incremental',
                'message' => implode('。', $msg_parts),
                'details' => $results,
            ]);
        }
    } else {
        // 全量生成
        $results = Todo_Sitemap_Service::generate_all();

        $success_count = 0;
        $failed = [];
        foreach ($results as $type => $status) {
            if ($status) {
                $success_count++;
            } else {
                $failed[] = $type;
            }
        }

        if (empty($failed)) {
            echo json_encode([
                'success' => true,
                'mode'    => 'full',
                'message' => '所有站点地图已成功生成并更新。',
                'details' => $results,
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'mode'    => 'full',
                'message' => '部分站点地图生成失败: ' . implode(', ', $failed),
                'details' => $results,
            ]);
        }
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'mode'    => $mode,
        'error'   => '生成过程中出错: ' . $e->getMessage(),
    ]);
}
exit;
