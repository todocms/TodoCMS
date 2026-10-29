<?php
/**
 * TodoCMS AI Agent Webhook 调度与事件通知系统
 *
 * 负责在 TodoCMS 发生关键业务事件（文章发布、商品库存报警、新评论等）时，
 * 向外部配置的 AI Agent（如 Dify、FastGPT、n8n、自动化流程）发送结构化 Webhook，实现双向互动。
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

class Todo_Agent_Webhook {
    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * 获取单例
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造函数，注册系统事件钩子
     */
    private function __construct() {
        // 监听文章发布/状态变更
        add_action('publish_post', [$this, 'on_post_published'], 10, 2);
        add_action('todo_post_published', [$this, 'on_post_published'], 10, 2);
    }

    /**
     * 文章发布事件钩子
     */
    public function on_post_published($post_id, $post = null) {
        $post_id = intval($post_id);
        if ($post_id <= 0) return;

        // 避免重复触发或草稿触发
        if (!$post && function_exists('get_post')) {
            $post = get_post($post_id);
        }

        $title = '';
        $excerpt = '';
        $url = function_exists('get_permalink') ? get_permalink($post_id) : '';

        if (is_object($post)) {
            $title = $post->post_title ?? '';
            $excerpt = $post->post_excerpt ?? mb_substr(strip_tags($post->post_content ?? ''), 0, 150);
        } elseif (is_array($post)) {
            $title = $post['post_title'] ?? '';
            $excerpt = $post['post_excerpt'] ?? mb_substr(strip_tags($post['post_content'] ?? ''), 0, 150);
        }

        self::trigger('post_published', [
            'post_id' => $post_id,
            'title' => $title,
            'excerpt' => $excerpt,
            'url' => $url,
            'published_at' => current_time('mysql')
        ]);
    }

    /**
     * 触发指定事件的 Webhook 推送
     *
     * @param string $event 事件名称（如 post_published, stock_low, ping）
     * @param array $payload 业务数据
     * @return array 发送结果详情 ['success' => bool, 'code' => int, 'message' => string]
     */
    public static function trigger($event, $payload = []) {
        $enabled = (bool) get_option('agent_webhook_enabled', false);
        $target_url = trim((string) get_option('agent_webhook_url', ''));
        $secret = trim((string) get_option('agent_webhook_secret', ''));
        $allowed_events = get_option('agent_webhook_events', ['post_published']);

        if (!is_array($allowed_events)) {
            $allowed_events = ['post_published'];
        }

        // 测试事件 ping 无需检查 enabled 开关
        if ($event !== 'ping') {
            if (!$enabled || empty($target_url)) {
                return ['success' => false, 'code' => 0, 'message' => 'Webhook 未启用或未配置接收端点'];
            }

            if (!in_array($event, $allowed_events, true)) {
                return ['success' => false, 'code' => 0, 'message' => "事件 {$event} 未在订阅列表中"];
            }
        }

        if (empty($target_url)) {
            return ['success' => false, 'code' => 0, 'message' => '未配置 Webhook URL'];
        }

        $timestamp = time();
        $site_name = get_option('site_name', 'TodoCMS');
        $site_url = function_exists('get_site_url') ? get_site_url() : '';

        $data_to_send = [
            'event' => $event,
            'timestamp' => $timestamp,
            'site' => [
                'name' => $site_name,
                'url' => $site_url
            ],
            'data' => $payload
        ];

        $json_body = json_encode($data_to_send, JSON_UNESCAPED_UNICODE);

        // 计算 HMAC-SHA256 签名（若配置了 secret）
        $headers = [
            'Content-Type: application/json',
            'User-Agent: TodoCMS-Agent-Webhook/1.0',
            'X-TodoCMS-Event: ' . $event,
            'X-TodoCMS-Timestamp: ' . $timestamp
        ];

        if (!empty($secret)) {
            $signature = hash_hmac('sha256', "{$timestamp}.{$json_body}", $secret);
            $headers[] = 'X-TodoCMS-Signature: ' . $signature;
        }

        // 发送快速 HTTP POST 请求（3秒超时）
        $response = self::send_http_request($target_url, $json_body, $headers);
        return $response;
    }

    /**
     * 极轻量 cURL HTTP POST 发送器
     */
    private static function send_http_request($url, $body, $headers) {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'code' => 0, 'message' => '服务器未启用 cURL 扩展'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'code' => $http_code, 'message' => 'cURL Error: ' . $error];
        }

        $is_success = ($http_code >= 200 && $http_code < 300);
        return [
            'success' => $is_success,
            'code' => $http_code,
            'message' => $is_success ? '推送成功' : "远端返回状态码: {$http_code}",
            'response' => $result
        ];
    }
}

// 自动初始化 Webhook 监听
Todo_Agent_Webhook::get_instance();
