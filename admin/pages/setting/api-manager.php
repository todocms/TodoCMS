<?php
/**
 * API 与 AI Agent 管理页面
 *
 * 提供 AI Agent（Claude, Cursor, Antigravity, Dify, GPTs）的 MCP 连接配置、
 * API 密钥生成管理、OpenAPI 导出、双向 Webhook 事件通知及全站 API 路由自省。
 *
 * @package TodoCMS
 * @version 2.1.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

// 检查权限
if (!todo_current_user_can('manage_api') && !todo_current_user_can('manage_options')) {
    todo_redirect(todo_admin_url('index.php?page=dashboard'));
    exit;
}

if (function_exists('todo_is_demo_admin_session') && todo_is_demo_admin_session()) {
    todo_redirect(todo_admin_url('index.php?page=dashboard'));
    exit;
}

// 加载 API 核心类和函数
if (file_exists(ABSPATH . 'core/classes/class-api.php')) {
    require_once ABSPATH . 'core/classes/class-api.php';
}
require_once ABSPATH . 'core/functions/api-functions.php';

// 加载 Agent Webhook
if (file_exists(ABSPATH . 'core/classes/class-agent-webhook.php')) {
    require_once ABSPATH . 'core/classes/class-agent-webhook.php';
}

$notice_msg = '';
$notice_type = 'success';

$current_user_id = function_exists('get_current_user_id') ? get_current_user_id() : 1;
if ($current_user_id <= 0) $current_user_id = 1;

// 处理表单提交操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agent_action'])) {
    $action = trim((string) $_POST['agent_action']);

    // 1. 创建或自定义生成新 API Key
    if ($action === 'create_api_key' || $action === 'generate_api_key') {
        $valid_keys = get_option('api_keys', []);
        if (!is_array($valid_keys)) {
            $valid_keys = [];
        }

        $key_name = !empty($_POST['key_name']) ? sanitize_text_field(trim((string)$_POST['key_name'])) : 'AI Agent 密钥';
        $custom_key = !empty($_POST['custom_key']) ? trim((string)$_POST['custom_key']) : '';
        
        // 如果用户自定义了 Key 则做安全清洗（仅限字母数字下划线连字符），否则生成强随机 Hex Key
        if ($custom_key !== '') {
            $new_key = preg_replace('/[^a-zA-Z0-9_\-]/', '', $custom_key);
            if (strlen($new_key) < 16) {
                $notice_msg = '自定义 API Key 长度过短，为保障安全至少需 16 位字符！';
                $notice_type = 'error';
            }
        } else {
            $new_key = bin2hex(random_bytes(24));
        }

        if (empty($notice_msg)) {
            // 获取选中的权限 Scope
            $allowed_scopes = ['read', 'publish', 'products:manage', 'comments:audit', 'posts:delete', 'system:manage', 'theme:manage'];
            $selected_scopes = [];
            if (!empty($_POST['scopes']) && is_array($_POST['scopes'])) {
                foreach ($_POST['scopes'] as $s) {
                    $clean_s = sanitize_text_field($s);
                    if (in_array($clean_s, $allowed_scopes, true)) {
                        $selected_scopes[] = $clean_s;
                    }
                }
            }
            if (empty($selected_scopes)) {
                // 默认安全最小权限：仅允许只读与发文/草稿
                $selected_scopes = ['read', 'publish'];
            }

            $key_id = 'key_' . bin2hex(random_bytes(6));
            $valid_keys[$key_id] = [
                'id' => $key_id,
                'name' => $key_name,
                'key' => $new_key,
                'user_id' => $current_user_id,
                'scopes' => $selected_scopes,
                'created_at' => date('Y-m-d H:i:s')
            ];

            update_option('api_keys', $valid_keys);
            $notice_msg = '新 API Key [' . esc_html($key_name) . '] 已成功创建并配置权限！';
            $notice_type = 'success';
        }
    }

    // 2. 删除指定的 API Key
    if ($action === 'delete_api_key') {
        $del_id = trim((string) ($_POST['key_id'] ?? ''));
        $valid_keys = get_option('api_keys', []);
        if (is_array($valid_keys) && isset($valid_keys[$del_id])) {
            unset($valid_keys[$del_id]);
            update_option('api_keys', $valid_keys);
            $notice_msg = '指定 API Key 已彻底吊销并删除！';
            $notice_type = 'success';
        }
    }

    // 3. 保存 Webhook 配置
    if ($action === 'save_webhook') {
        $enabled = isset($_POST['agent_webhook_enabled']) ? 1 : 0;
        $url = trim((string) ($_POST['agent_webhook_url'] ?? ''));
        $secret = trim((string) ($_POST['agent_webhook_secret'] ?? ''));
        $events = isset($_POST['agent_webhook_events']) && is_array($_POST['agent_webhook_events']) 
            ? array_map('sanitize_text_field', $_POST['agent_webhook_events']) 
            : ['post_published'];

        update_option('agent_webhook_enabled', $enabled);
        update_option('agent_webhook_url', $url);
        update_option('agent_webhook_secret', $secret);
        update_option('agent_webhook_events', $events);

        $notice_msg = 'Agent Webhook 配置已成功保存！';
        $notice_type = 'success';
    }

    // 4. 发送 Webhook 测试 Ping
    if ($action === 'test_webhook') {
        $result = Todo_Agent_Webhook::trigger('ping', [
            'message' => 'TodoCMS Agent Webhook 测试联通性探针',
            'sender' => 'TodoCMS Admin UI'
        ]);

        if (!empty($result['success'])) {
            $notice_msg = 'Webhook 测试推送成功！远端端点响应状态码: ' . ($result['code'] ?? 200);
            $notice_type = 'success';
        } else {
            $notice_msg = 'Webhook 测试失败: ' . ($result['message'] ?? '网络异常或远端拒绝');
            $notice_type = 'error';
        }
    }
}

// 获取并格式化所有 API Key 列表
$raw_keys = get_option('api_keys', []);
$normalized_keys = [];
$current_api_key = '';

if (is_array($raw_keys)) {
    foreach ($raw_keys as $k => $item) {
        if (is_string($item)) {
            $normalized_keys[$k] = [
                'id' => $k,
                'name' => '默认遗留密钥 #' . $k,
                'key' => $item,
                'scopes' => ['read', 'publish', 'products:manage', 'comments:audit'], // 默认无高危权限
                'created_at' => '历史创建'
            ];
            if (empty($current_api_key)) $current_api_key = $item;
        } elseif (is_array($item) && !empty($item['key'])) {
            $normalized_keys[$k] = [
                'id' => $k,
                'name' => !empty($item['name']) ? $item['name'] : 'API Key',
                'key' => $item['key'],
                'scopes' => isset($item['scopes']) && is_array($item['scopes']) ? $item['scopes'] : ['read', 'publish'],
                'created_at' => !empty($item['created_at']) ? $item['created_at'] : '未知时间'
            ];
            if (empty($current_api_key)) $current_api_key = $item['key'];
        }
    }
}

// Webhook 配置项
$webhook_enabled = (bool) get_option('agent_webhook_enabled', false);
$webhook_url = (string) get_option('agent_webhook_url', '');
$webhook_secret = (string) get_option('agent_webhook_secret', '');
$webhook_events = get_option('agent_webhook_events', ['post_published']);
if (!is_array($webhook_events)) $webhook_events = ['post_published'];

// 站点信息与路径
$site_url = function_exists('todo_get_site_url') ? rtrim(todo_get_site_url(), '/') : (function_exists('get_site_url') ? rtrim(get_site_url(), '/') : '');
$api_base_url = $site_url . '/api/v1';
$openapi_url = $api_base_url . '/agent/openapi.json';
$mcp_script_path = ABSPATH . 'tools/todocms-mcp/index.js';

// 加载端点并获取所有路由
$api_endpoints_dir = ABSPATH . 'api/endpoints/';
if (is_dir($api_endpoints_dir)) {
    $endpoint_files = glob($api_endpoints_dir . '*.php');
    foreach ($endpoint_files as $endpoint_file) {
        require_once $endpoint_file;
    }
}
$api = Todo_API::get_instance();
$routes = $api->get_routes();

// 路由说明辅助
function todo_get_endpoint_description($endpoint) {
    $map = [
        'agent/context' => '【AI Agent】获取全站自省元信息（分类树、标签池、语言和状态定义）',
        'agent/posts/publish' => '【AI Agent】智能发文（支持 Markdown 转 HTML、分类/标签自动关联、草稿审核流）',
        'agent/products/update-stock' => '【AI Agent】快速调价与修改库存、销售状态',
        'agent/products/create' => '【AI Agent】创建并上架新商品（支持售价、划线价、库存、分类与富文本）',
        'agent/openapi' => '【AI Agent】动态生成标准 OpenAPI 3.0.0 规范，供 Dify/GPTs/Coze 一键导入',
        'posts' => '文章管理接口（支持列表、详情、增删改、点赞、浏览量计数）',
        'products' => '产品管理接口（支持按会员等级动态计价、多规格/变体及库存查询）',
        'categories' => '分类/栏目接口',
        'tags' => '标签接口',
        'orders' => '订单管理接口（支持创建、支付、取消及回调通知）',
        'cart' => '购物车管理接口',
        'coupons' => '优惠券管理接口',
        'media' => '媒体文件与图片上传/管理接口'
    ];

    foreach ($map as $key => $desc) {
        if (strpos($endpoint, $key) === 0 || strpos($endpoint, '/' . $key) !== false) {
            return $desc;
        }
    }
    return '系统 RESTful API 端点';
}

$admin_title = 'API 与 AI Agent 智能体管理';
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title"><?php echo esc_html($admin_title); ?></h2>
    </div>

    <?php if (!empty($notice_msg)): ?>
        <div class="notice notice-<?php echo $notice_type === 'success' ? 'success' : 'error'; ?> is-dismissible">
            <p><?php echo esc_html($notice_msg); ?></p>
            <button type="button" class="notice-dismiss" onclick="this.parentElement.remove();">✕</button>
        </div>
    <?php endif; ?>

    <!-- AI Agent 核心配置区域 -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <!-- 密钥管理卡片 -->
        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">Agent 专用 API Key</h3>
                <div class="admin-card-actions">
                    <button type="button" class="button button-primary button-small" onclick="toggleNewKeyForm()">+ 新增/自定义 Key</button>
                </div>
            </div>
            <div class="admin-card-content">
                <!-- 新增 / 自定义密钥表单（可折叠） -->
                <div id="newKeyFormContainer" style="display: none; margin-bottom: 15px;">
                    <form method="post">
                        <input type="hidden" name="agent_action" value="create_api_key">
                        <div class="form-group">
                            <label>名称</label>
                            <input type="text" name="key_name" placeholder="例如：写作助手" required class="flex-1" style="width: 100%;">
                        </div>
                        <div class="form-group">
                            <label>自定义 API Key<span class="description">(留空则系统自动随机生成强密钥)</span></label>
                            <input type="text" name="custom_key" placeholder="支持自定义任意16位以上字符串" class="flex-1" style="width: 100%; font-family: monospace;">
                        </div>
                        <div class="form-group">
                            <label>授予权限作用域<span class="description">(Scopes 最小权限原则)</span></label>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 13px;">
                                <label><input type="checkbox" name="scopes[]" value="read" checked> <span>只读查询 (read)</span></label>
                                <label><input type="checkbox" name="scopes[]" value="publish" checked> <span>发文/草稿 (publish)</span></label>
                                <label><input type="checkbox" name="scopes[]" value="products:manage" checked> <span>商品与库存 (products)</span></label>
                                <label><input type="checkbox" name="scopes[]" value="comments:audit" checked> <span>评论审核与回复</span></label>
                                <label style="color: #dc2626;"><input type="checkbox" name="scopes[]" value="posts:delete"> <span><strong>删除文章 [高危]</strong></span></label>
                                <label style="color: #dc2626;"><input type="checkbox" name="scopes[]" value="system:manage"> <span><strong>修改系统配置 [高危]</strong></span></label>
                                <label style="color: #dc2626;"><input type="checkbox" name="scopes[]" value="theme:manage"> <span><strong>修改主题模板 [极高危]</strong></span></label>
                            </div>
                            <p class="description">提示：日常发文及问答 Agent 建议<strong>严禁勾选高危权限</strong>。即便 Agent 遭受提示词注入（Prompt Injection），系统也会因缺少 Scope 直接在内核拦截！</p>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="button button-primary">确认保存并生成 Key</button>
                            <button type="button" class="button button-secondary" onclick="toggleNewKeyForm()">取消</button>
                        </div>
                    </form>
                </div>

                <!-- 已存在的 API Key 列表 -->
                <?php if (!empty($normalized_keys)): ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>用途备注</th>
                                <th style="width: 230px;min-width: 230px;">密钥<span class="description">(API Key)</span></th>
                                <th>权限 Scopes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($normalized_keys as $k_id => $k_item): ?>
                                <tr>
                                    <td>
                                        <?php echo esc_html($k_item['name']); ?>
                                        <p class="description"><?php echo esc_html($k_item['created_at']); ?></p>
                                    </td>
                                    <td>
                                        <div class="flex flex-col gap-1 items-start">
                                            <input type="password" id="key_input_<?php echo esc_attr($k_id); ?>" value="<?php echo esc_attr($k_item['key']); ?>" readonly style="min-width: auto;width: 100%;">
                                            <div class="flex gap-1 items-center w-100">
                                                <button type="button" class="btn" style="flex-shrink: 0;" onclick="toggleSingleKey('<?php echo esc_js($k_id); ?>')"><i class="icon todofont todo-PreviewOpen"></i></button>
                                                <button type="button" class="btn" style="flex-shrink: 0;" onclick="copyToClipboard('<?php echo esc_js($k_item['key']); ?>', '已复制该 API Key！')">复制</button>
                                                <form method="post" style="margin-inline-start: auto;flex-shrink: 0;" onsubmit="return confirm('确定要彻底吊销此 API Key 吗？');">
                                                    <input type="hidden" name="agent_action" value="delete_api_key">
                                                    <input type="hidden" name="key_id" value="<?php echo esc_attr($k_id); ?>">
                                                    <button type="submit" class="btn" style="color: #ef4444;">吊销</button>
                                                </form>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php foreach ($k_item['scopes'] as $sc): ?>
                                            <?php 
                                                $is_danger = in_array($sc, ['posts:delete', 'theme:manage', 'system:manage'], true);
                                                $bg = $is_danger ? '#fee2e2' : '#e0f2fe';
                                                $col = $is_danger ? '#991b1b' : '#0369a1';
                                            ?>
                                            <span style="display: inline-block; background: <?php echo $bg; ?>; color: <?php echo $col; ?>; font-size: 11px; padding: 1px 6px; border-radius: 4px;">
                                                <?php echo esc_html($sc); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color: #64748b; font-size: 13px;">尚未生成任何 API Key。请点击右上角「+ 新增/自定义 Key」生成。</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- OpenAPI 规范卡片 -->
        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">OpenAPI 3.0 规范接入</h3>
                <div class="admin-card-actions">
                    <span class="status-pending">Dify / GPTs / Coze</span>
                </div>
            </div>
            <div class="admin-card-content">
                <div class="form-group">
                    <label for="openapiSpecUrl">OpenAPI 规范地址<span class="description">(Spec URL)</span></label>
                    <div class="flex gap-2 flex-wrap">
                        <input type="text" id="openapiSpecUrl" class="flex-1" readonly value="<?php echo esc_attr($openapi_url); ?>">
                        <button type="button" class="button button-secondary" onclick="copyToClipboard('<?php echo esc_js($openapi_url); ?>', 'OpenAPI 链接已复制！')">
                            复制链接
                        </button>
                        <a href="<?php echo esc_url($openapi_url); ?>" target="_blank" class="button button-secondary">
                            预览 Spec ↗
                        </a>
                    </div>
                    <p class="description">直接在 Dify、Coze、OpenAI Custom GPTs 中选择 “Import from URL”，即可一键生成全套操作动作（Actions）。</p>
                </div>

                <div class="notice notice-sm m-0">
                    <p><strong>导入步骤提示：</strong>在 GPTs/Dify 中导入上述 URL 后，认证选择 <code>API Key</code> &rarr; <code>Custom Header</code> 填入 <code>X-API-Key</code>，即可随时发文与管理商品。</p>
                </div>
            </div>
        </div>
    </div>

    <!-- MCP (Model Context Protocol) 实时配置生成器 -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h3 class="admin-card-title">Model Context Protocol (MCP) 一键配置</h3>
            <div class="admin-card-actions">
                <button type="button" class="button button-primary" onclick="copyMcpConfig()">
                    一键复制 MCP 配置
                </button>
            </div>
        </div>
        <div class="admin-card-content">
            <div class="form-group">
                <p class="description m-b-10">系统已根据当前服务器路径和 API Key 自动生成了完整的 MCP 客户端配置代码。将下方 JSON 代码段直接复制到您的 Claude Desktop 或 Cursor 配置文件中即可：</p>

                <?php
                $mcp_json_snippet = json_encode([
                    'mcpServers' => [
                        'todocms' => [
                            'command' => 'node',
                            'args' => [
                                $mcp_script_path
                            ],
                            'env' => [
                                'TODOCMS_API_URL' => $api_base_url,
                                'TODOCMS_API_KEY' => !empty($current_api_key) ? $current_api_key : 'YOUR_API_KEY_HERE',
                                'TODOCMS_SAFE_MODE' => 'true'
                            ]
                        ]
                    ]
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                ?>

                <pre id="mcpConfigPre" class="pre" style="white-space: pre; overflow-x: auto; font-family: monospace;"><?php echo esc_html($mcp_json_snippet); ?></pre>

                <!-- 核心安全管辖变量与能力说明指南 -->
                <div class="m-t-20 m-b-20">
                    <h4 class="m-b-10">AI 安全防线核心变量管辖权与能力说明</h4>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th style="width: 250px;">安全变量</th>
                                <th style="width: 160px;">生效位置</th>
                                <th style="width: 130px; min-width: 130px;">默认值</th>
                                <th>管辖能力与定位</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>TODOCMS_SAFE_MODE</code></td>
                                <td>
                                    MCP 客户端
                                    <span class="tb-meta">Claude / Cursor env</span>
                                </td>
                                <td><span class="status-active">true (推荐)</span></td>
                                <td><span class="tb-description"><strong>人机协同防误触守则（防静默破坏）：</strong>允许发文、改库存等正常操作；在执行删除文章等破坏性动作时，强制拦截并要求 AI 必须向人类汇报、获得人类明确许可（传 <code>confirm: true</code>）后方可放行。</span></td>
                            </tr>
                            <tr>
                                <td><code>TODOCMS_READ_ONLY</code></td>
                                <td>
                                    MCP 客户端
                                    <span class="tb-meta">Claude / Cursor env</span>
                                </td>
                                <td><span class="status-inactive">false</span></td>
                                <td><span class="tb-description"><strong>全局只读熔断（封死一切写接口）：</strong>设为 <code>true</code> 时，客户端直接阻断发文、改库存、改配置、删文章等所有写操作，仅允许检索与自省。专供对外的智能客服或问答机器人。</span></td>
                            </tr>
                            <tr>
                                <td><code>TODO_AGENT_ALLOW_THEME_EDIT</code></td>
                                <td>
                                    CMS 服务端
                                    <span class="tb-meta">config.php 常量</span>
                                </td>
                                <td><span class="status-reject">false (默认锁死)</span></td>
                                <td><span class="tb-description"><strong>服务器物理防篡改锁（防文件篡改）：</strong>服务端内核物理硬开关。服务端未在 <code>config.php</code> 显式配置为 <code>true</code> 时，即便 API Key 权限极高也无法通过 API 更改任何模板代码（且即便开启也严禁写入 PHP，杜绝 RCE 提权）。</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="notice notice-sm m-0">
                <p><strong>配置文件路径参考：</strong></p>
                <p>&bull; <strong>Claude Desktop:</strong> <code style="word-wrap: break-word;">~/Library/Application Support/Claude/claude_desktop_config.json</code></p>
                <p>&bull; <strong>Cursor / Antigravity:</strong> 项目根目录下的 <code>.cursor/mcp.json</code></p>
            </div>
        </div>
    </div>

    <!-- Agent 双向 Webhook 事件通知 -->
    <div class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-section">
                <h3 class="admin-card-title">双向联动 Webhook（事件触发 Agent）</h3>
                <p class="admin-card-subtitle">当 TodoCMS 内部发生重要业务事件时，系统会自动发送带数字签名的 Webhook 给外部 Agent</p>
            </div>
            <div class="admin-card-actions">
                <span class="<?php echo $webhook_enabled ? 'status-active' : 'status-inactive'; ?>">
                    <?php echo $webhook_enabled ? '已启用推送' : '未启用'; ?>
                </span>
            </div>
        </div>
        <div class="admin-card-content">
            <form method="post">
                <input type="hidden" name="agent_action" value="save_webhook">
                <table class="form-table">
                    <tr>
                        <th>启用 Webhook 推送</th>
                        <td>
                            <label>
                                <input type="checkbox" name="agent_webhook_enabled" value="1" <?php checked($webhook_enabled, true); ?>>
                                <span>开启主动推送</span>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Agent Webhook 端点 URL</th>
                        <td>
                            <input type="url" name="agent_webhook_url" value="<?php echo esc_attr($webhook_url); ?>" placeholder="https://your-agent-platform.com/api/webhook/..." class="regular-text" style="min-width: 50%;">
                            <p class="description">接收 POST 请求的外部 Agent 目标地址（如 Dify、n8n、FastGPT、自建服务）</p>
                        </td>
                    </tr>
                    <tr>
                        <th>签名密钥 (Secret)</th>
                        <td>
                            <input type="text" name="agent_webhook_secret" value="<?php echo esc_attr($webhook_secret); ?>" placeholder="用于生成 X-TodoCMS-Signature 校验码（可选）" class="regular-text" style="min-width: 50%;">
                            <p class="description">用于向接收端验证消息来源的 HMAC SHA256 签名密钥</p>
                        </td>
                    </tr>
                    <tr>
                        <th>订阅事件 (Events)</th>
                        <td>
                            <div class="form-group m-b-05">
                                <label>
                                    <input type="checkbox" name="agent_webhook_events[]" value="post_published" <?php checked(in_array('post_published', $webhook_events, true), true); ?>>
                                    <span><strong>新文章发布 (post_published)</strong> — 触发 Agent 自动多语言翻译、生成社媒推文与提炼知识</span>
                                </label>
                            </div>
                            <div class="form-group m-b-05">
                                <label>
                                    <input type="checkbox" name="agent_webhook_events[]" value="stock_low" <?php checked(in_array('stock_low', $webhook_events, true), true); ?>>
                                    <span><strong>商品库存报警 (stock_low)</strong> — 商品库存归零或不足时触发 Agent 预警</span>
                                </label>
                            </div>
                        </td>
                    </tr>
                </table>

                <div class="form-submit m-0">
                    <button type="submit" class="button button-primary">
                        保存 Webhook 配置
                    </button>
                </div>
            </form>

            <?php if (!empty($webhook_url)): ?>
                <div class="form-submit flex items-center gap-3">
                    <form method="post" class="m-0">
                        <input type="hidden" name="agent_action" value="test_webhook">
                        <button type="submit" class="button button-secondary">
                            发送测试 Webhook Ping
                        </button>
                    </form>
                    <span class="description m-0">可实时验证目标服务器是否能正常接收 TodoCMS 的事件请求</span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- RESTful 路由清单 -->
    <div class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-section">
                <h3 class="admin-card-title">全站 RESTful 路由与 Agent 端点清单</h3>
                <p class="admin-card-subtitle">系统已自动探测并注册的 RESTful API 路由及 AI Agent 操作端点</p>
            </div>
        </div>
        <div class="admin-card-content">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th style="width: 280px;">端点路径 (Endpoint)</th>
                        <th style="width: 90px;">方法</th>
                        <th style="width: 240px;">处理函数 (Handler)</th>
                        <th>功能说明</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($routes)): ?>
                        <tr class="empty"><td colspan="4" class="no-items">暂无已注册接口</td></tr>
                    <?php else: ?>
                        <?php foreach ($routes as $endpoint => $methods): ?>
                            <?php foreach ($methods as $method => $handler): 
                                $is_agent_route = strpos($endpoint, 'agent/') === 0;
                                $method_upper = strtoupper($method);
                                $method_class = 'status-inactive';
                                if ($method_upper === 'GET') {
                                    $method_class = 'status-active';
                                } elseif ($method_upper === 'POST') {
                                    $method_class = 'status-pending';
                                } elseif ($method_upper === 'DELETE') {
                                    $method_class = 'status-reject';
                                }
                            ?>
                                <tr>
                                    <td>
                                        <code>/api/v1/<?php echo esc_html($endpoint); ?></code>
                                        <?php if ($is_agent_route): ?>
                                            <span class="status-active" style="display: inline-block;">AI Agent</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="<?php echo $method_class; ?>">
                                            <?php echo $method_upper; ?>
                                        </span>
                                    </td>
                                    <td class="tb-meta">
                                        <code><?php 
                                        if (is_string($handler)) {
                                            echo esc_html($handler);
                                        } elseif (is_array($handler)) {
                                            echo esc_html(get_class($handler[0]) . '::' . $handler[1]);
                                        } else {
                                            echo 'Closure';
                                        }
                                        ?></code>
                                    </td>
                                    <td>
                                        <span class="tb-description"><?php echo esc_html(todo_get_endpoint_description($endpoint)); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function toggleNewKeyForm() {
    var form = document.getElementById('newKeyFormContainer');
    if (form) {
        form.style.display = (form.style.display === 'none' || form.style.display === '') ? 'block' : 'none';
    }
}

function toggleSingleKey(id) {
    var input = document.getElementById('key_input_' + id);
    if (input) {
        input.type = input.type === 'password' ? 'text' : 'password';
    }
}

function toggleKeyVisibility() {
    var input = document.getElementById('agentApiKeyInput');
    var icon = document.getElementById('eyeIcon');
    if (input && icon) {
        if (input.type === 'password') {
            input.type = 'text';
            icon.innerText = '隐藏';
        } else {
            input.type = 'password';
            icon.innerText = '显示';
        }
    }
}

function copyToClipboard(text, successMsg) {
    if (!text) {
        alert('内容为空，无法复制');
        return;
    }
    navigator.clipboard.writeText(text).then(function() {
        alert(successMsg || '已复制到剪贴板！');
    }, function() {
        var tempInput = document.createElement('input');
        tempInput.value = text;
        document.body.appendChild(tempInput);
        tempInput.select();
        document.execCommand('copy');
        document.body.removeChild(tempInput);
        alert(successMsg || '已复制到剪贴板！');
    });
}

function copyMcpConfig() {
    var code = document.getElementById('mcpConfigPre').innerText;
    copyToClipboard(code, 'MCP 配置代码已成功复制！粘贴至 Claude Desktop 或 Cursor 配置文件中即可。');
}
</script>
