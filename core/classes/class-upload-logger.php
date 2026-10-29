<?php
/**
 * 上传日志管理器
 *
 * 负责记录和管理所有文件上传活动，包括：
 * 1. 上传成功日志
 * 2. 上传失败日志  
 * 3. 安全警告日志
 * 4. 系统错误日志
 *
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

class TodoUploadLogger
{

    /**
     * 日志表名
     */
    const LOG_TABLE = 'todo_upload_logs';

    /**
     * 日志类型常量
     */
    const LOG_TYPE_SUCCESS = 'success';
    const LOG_TYPE_ERROR = 'error';
    const LOG_TYPE_SECURITY = 'security';
    const LOG_TYPE_SYSTEM = 'system';

    /**
     * 记录成功上传
     */
    public static function log_upload($media, $context = '', $field_name = '')
    {
        global $tododb;

        $user_id = todo_get_current_user_id();
        $user_ip = todo_get_user_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $log_data = array(
            'log_type' => self::LOG_TYPE_SUCCESS,
            'user_id' => $user_id,
            'user_ip' => $user_ip,
            'user_agent' => $user_agent,
            'file_name' => $media['filename'],
            'file_path' => $media['file_path'],
            'file_size' => $media['file_size'],
            'file_type' => $media['mime_type'],
            'context' => $context,
            'field_name' => $field_name,
            'media_id' => $media['id'],
            'message' => '文件上传成功',
            'extra_data' => json_encode(array(
                'original_name' => $media['original_filename'] ?? $media['filename'],
                'upload_time' => $media['upload_time'] ?? date('Y-m-d H:i:s'),
                'file_hash' => $media['file_hash'] ?? ''
            )),
            'created_at' => date('Y-m-d H:i:s')
        );

        return $tododb->insert(self::LOG_TABLE, $log_data);
    }

    /**
     * 记录上传错误
     */
    public static function log_error($message, $file_info = null, $context = '', $field_name = '')
    {
        global $tododb;

        $user_id = todo_get_current_user_id();
        $user_ip = todo_get_user_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $log_data = array(
            'log_type' => self::LOG_TYPE_ERROR,
            'user_id' => $user_id,
            'user_ip' => $user_ip,
            'user_agent' => $user_agent,
            'file_name' => $file_info['name'] ?? '',
            'file_path' => '',
            'file_size' => $file_info['size'] ?? 0,
            'file_type' => $file_info['type'] ?? '',
            'context' => $context,
            'field_name' => $field_name,
            'media_id' => 0,
            'message' => $message,
            'extra_data' => json_encode(array(
                'error_details' => $file_info,
                'server_info' => array(
                    'php_version' => PHP_VERSION,
                    'memory_usage' => memory_get_usage(true),
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size')
                )
            )),
            'created_at' => date('Y-m-d H:i:s')
        );

        return $tododb->insert(self::LOG_TABLE, $log_data);
    }

    /**
     * 记录安全警告
     */
    public static function log_security_warning($file_info, $reason, $context = '', $field_name = '')
    {
        global $tododb;

        $user_id = todo_get_current_user_id();
        $user_ip = todo_get_user_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $log_data = array(
            'log_type' => self::LOG_TYPE_SECURITY,
            'user_id' => $user_id,
            'user_ip' => $user_ip,
            'user_agent' => $user_agent,
            'file_name' => $file_info['name'] ?? '',
            'file_path' => '',
            'file_size' => $file_info['size'] ?? 0,
            'file_type' => $file_info['type'] ?? '',
            'context' => $context,
            'field_name' => $field_name,
            'media_id' => 0,
            'message' => '安全检查失败：' . $reason,
            'extra_data' => json_encode(array(
                'security_reason' => $reason,
                'file_details' => $file_info,
                'request_details' => array(
                    'referer' => $_SERVER['HTTP_REFERER'] ?? '',
                    'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                    'request_method' => $_SERVER['REQUEST_METHOD'] ?? ''
                )
            )),
            'created_at' => date('Y-m-d H:i:s')
        );

        // 安全警告需要立即通知管理员
        self::notify_security_alert($log_data);

        return $tododb->insert(self::LOG_TABLE, $log_data);
    }

    /**
     * 记录系统日志
     */
    public static function log_system($message, $extra_data = array())
    {
        global $tododb;

        $log_data = array(
            'log_type' => self::LOG_TYPE_SYSTEM,
            'user_id' => 0,
            'user_ip' => todo_get_user_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'file_name' => '',
            'file_path' => '',
            'file_size' => 0,
            'file_type' => '',
            'context' => 'system',
            'field_name' => '',
            'media_id' => 0,
            'message' => $message,
            'extra_data' => json_encode($extra_data),
            'created_at' => date('Y-m-d H:i:s')
        );

        return $tododb->insert(self::LOG_TABLE, $log_data);
    }

    /**
     * 获取日志列表
     */
    public static function get_logs($page = 1, $per_page = 20, $log_type = 'all', $filters = array())
    {
        global $tododb;

        $offset = ($page - 1) * $per_page;

        // 构建查询条件
        $where_conditions = array();
        $where_params = array();

        if ($log_type !== 'all') {
            $where_conditions[] = 'log_type = %s';
            $where_params[] = $log_type;
        }

        // 日期范围过滤
        if (!empty($filters['date_from'])) {
            $where_conditions[] = 'created_at >= %s';
            $where_params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where_conditions[] = 'created_at <= %s';
            $where_params[] = $filters['date_to'] . ' 23:59:59';
        }

        // 用户过滤
        if (!empty($filters['user_id'])) {
            $where_conditions[] = 'user_id = %d';
            $where_params[] = intval($filters['user_id']);
        }

        // IP过滤
        if (!empty($filters['user_ip'])) {
            $where_conditions[] = 'user_ip = %s';
            $where_params[] = $filters['user_ip'];
        }

        // 文件类型过滤
        if (!empty($filters['file_type'])) {
            $where_conditions[] = 'file_type LIKE %s';
            $where_params[] = '%' . $filters['file_type'] . '%';
        }

        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }

        // 获取总数
        $count_sql = "SELECT COUNT(*) FROM " . self::LOG_TABLE . " " . $where_clause;
        $total = $tododb->get_var($count_sql, $where_params);

        // 获取日志列表
        $sql = "SELECT l.*, u.username, u.display_name 
                FROM " . self::LOG_TABLE . " l 
                LEFT JOIN " . TABLE_PREFIX . "users u ON l.user_id = u.id 
                " . $where_clause . " 
                ORDER BY l.created_at DESC 
                LIMIT %d OFFSET %d";

        $params = array_merge($where_params, array($per_page, $offset));

        $logs = $tododb->get_results($sql, $params, ARRAY_A);

        // 处理日志数据
        foreach ($logs as &$log) {
            $log['extra_data'] = json_decode($log['extra_data'], true);
            $log['file_size_formatted'] = todo_format_bytes($log['file_size']);
            $log['created_at_formatted'] = todo_format_date($log['created_at']);

            // 添加用户信息
            if ($log['user_id'] > 0) {
                $log['user_display'] = $log['display_name'] ?: $log['username'];
            } else {
                $log['user_display'] = '系统';
            }
        }

        return array(
            'logs' => $logs,
            'total' => intval($total),
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page)
        );
    }

    /**
     * 获取日志统计（别名方法）
     */
    public static function get_log_statistics($days = 30)
    {
        return self::get_statistics($days);
    }

    /**
     * 清除旧日志（别名方法）
     */
    public static function clear_old_logs($days_old = 30)
    {
        return self::clear_logs('all', $days_old);
    }

    /**
     * 获取日志统计
     */
    public static function get_statistics($days = 30)
    {
        global $tododb;

        $date_from = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $sql = "SELECT 
                    log_type,
                    COUNT(*) as count,
                    SUM(file_size) as total_size
                FROM " . self::LOG_TABLE . " 
                WHERE created_at >= %s 
                GROUP BY log_type";

        $results = $tododb->get_results($sql, array($date_from), ARRAY_A);

        $stats = array(
            'total_uploads' => 0,
            'total_errors' => 0,
            'total_security_warnings' => 0,
            'total_size' => 0,
            'by_type' => array()
        );

        foreach ($results as $result) {
            $stats['by_type'][$result['log_type']] = array(
                'count' => intval($result['count']),
                'total_size' => intval($result['total_size']),
                'total_size_formatted' => todo_format_bytes($result['total_size'])
            );

            switch ($result['log_type']) {
                case self::LOG_TYPE_SUCCESS:
                    $stats['total_uploads'] = intval($result['count']);
                    $stats['total_size'] += intval($result['total_size']);
                    break;
                case self::LOG_TYPE_ERROR:
                    $stats['total_errors'] = intval($result['count']);
                    break;
                case self::LOG_TYPE_SECURITY:
                    $stats['total_security_warnings'] = intval($result['count']);
                    break;
            }
        }

        $stats['total_size_formatted'] = todo_format_bytes($stats['total_size']);

        return $stats;
    }

    /**
     * 清除日志
     */
    public static function clear_logs($log_type = 'all', $days_old = 30)
    {
        global $tododb;

        $date_threshold = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));

        $where_conditions = array('created_at < %s');
        $where_params = array($date_threshold);

        if ($log_type !== 'all') {
            $where_conditions[] = 'log_type = %s';
            $where_params[] = $log_type;
        }

        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

        $sql = "DELETE FROM " . self::LOG_TABLE . " " . $where_clause;

        return $tododb->query($sql, $where_params);
    }

    /**
     * 安全警告通知
     */
    private static function notify_security_alert($log_data)
    {
        // 检查是否启用了安全通知
        if (!todo_get_option('upload_security_notifications', true)) {
            return;
        }

        // 获取管理员邮箱
        $admin_email = todo_get_option('admin_email');
        if (empty($admin_email)) {
            return;
        }

        $subject = '[' . todo_get_option('site_name', 'TodoCMS') . '] 上传安全警告';

        $message = "检测到可疑的文件上传尝试：\n\n";
        $message .= "时间：" . $log_data['created_at'] . "\n";
        $message .= "用户ID：" . $log_data['user_id'] . "\n";
        $message .= "IP地址：" . $log_data['user_ip'] . "\n";
        $message .= "文件名：" . $log_data['file_name'] . "\n";
        $message .= "文件类型：" . $log_data['file_type'] . "\n";
        $message .= "警告原因：" . $log_data['message'] . "\n";
        $message .= "用户代理：" . $log_data['user_agent'] . "\n\n";
        $message .= "请及时检查系统安全状况。";

        // 发送邮件通知
        todo_mail($admin_email, $subject, $message);
    }
}