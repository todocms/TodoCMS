<?php
/**
 * AI Agent 专用集成端点
 *
 * 为外部 AI Agent（如 Claude、Cursor、GPTs、Dify、Antigravity、自动化工作流）提供
 * 站点自省上下文、智能发文、商品管理及 OpenAPI 规范接口。
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

/**
 * 极轻量安全 Markdown 转 HTML 解析器
 * 确保 Agent 发送的 Markdown 内容能完美转换为适合 CMS 存储与展示的 HTML
 */
if (!function_exists('todo_agent_markdown_to_html')) {
    function todo_agent_markdown_to_html($markdown) {
        $text = (string) $markdown;
        if (trim($text) === '') {
            return '';
        }

        // 如果已经是 HTML（含标签），则直接返回并做基础过滤
        if (preg_match('#^<[a-z][\s\S]*>$#i', trim($text))) {
            return function_exists('todo_kses_post') ? todo_kses_post($text) : $text;
        }

        // 规范化换行
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // 提取并保护代码块
        $code_blocks = [];
        $text = preg_replace_callback('/```([a-zA-Z0-9_-]*)\n([\s\S]*?)\n```/', function ($matches) use (&$code_blocks) {
            $index = count($code_blocks);
            $lang = htmlspecialchars(trim($matches[1]), ENT_QUOTES, 'UTF-8');
            $code = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
            $cls = $lang ? " class=\"language-{$lang}\"" : '';
            $code_blocks[$index] = "<pre><code{$cls}>{$code}</code></pre>";
            return "%%CODEBLOCK_{$index}%%";
        }, $text);

        // 行内代码
        $text = preg_replace_callback('/`([^`]+)`/', function ($m) {
            return '<code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code>';
        }, $text);

        // 标题 # ~ ######
        for ($i = 6; $i >= 1; $i--) {
            $hashes = str_repeat('#', $i);
            $text = preg_replace('/^' . $hashes . '\s+(.+)$/m', "<h{$i}>$1</h{$i}>", $text);
        }

        // 粗体与斜体
        $text = preg_replace('/\*\*\*(.*?)\*\*\*/s', '<strong><em>$1</em></strong>', $text);
        $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*([^\*\n]+)\*/', '<em>$1</em>', $text);

        // 图片与链接
        $text = preg_replace('/!\[(.*?)\]\((.*?)\)/', '<img src="$2" alt="$1" style="max-width:100%;height:auto;" />', $text);
        $text = preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $text);

        // 引用块
        $text = preg_replace('/^>\s*(.+)$/m', '<blockquote>$1</blockquote>', $text);

        // 无序列表与有序列表处理（按行分块）
        $lines = explode("\n", $text);
        $in_ul = false;
        $in_ol = false;
        $processed_lines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^[-*+]\s+(.+)$/', $trimmed, $m)) {
                if (!$in_ul) {
                    if ($in_ol) { $processed_lines[] = '</ol>'; $in_ol = false; }
                    $processed_lines[] = '<ul>';
                    $in_ul = true;
                }
                $processed_lines[] = '<li>' . $m[1] . '</li>';
            } elseif (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $m)) {
                if (!$in_ol) {
                    if ($in_ul) { $processed_lines[] = '</ul>'; $in_ul = false; }
                    $processed_lines[] = '<ol>';
                    $in_ol = true;
                }
                $processed_lines[] = '<li>' . $m[1] . '</li>';
            } else {
                if ($in_ul) { $processed_lines[] = '</ul>'; $in_ul = false; }
                if ($in_ol) { $processed_lines[] = '</ol>'; $in_ol = false; }

                if ($trimmed !== '' && strpos($trimmed, '<h') !== 0 && strpos($trimmed, '<blockquote') !== 0 && strpos($trimmed, '%%CODEBLOCK_') !== 0) {
                    $processed_lines[] = '<p>' . $trimmed . '</p>';
                } else {
                    $processed_lines[] = $line;
                }
            }
        }
        if ($in_ul) { $processed_lines[] = '</ul>'; }
        if ($in_ol) { $processed_lines[] = '</ol>'; }

        $html = implode("\n", $processed_lines);

        // 还原代码块
        foreach ($code_blocks as $idx => $code_html) {
            $html = str_replace("%%CODEBLOCK_{$idx}%%", $code_html, $html);
        }

        return function_exists('todo_kses_post') ? todo_kses_post($html) : $html;
    }
}

/**
 * 1. 获取站点上下文自省信息（Agent Context）
 * 提供给 Agent 了解站点的语言、分类池、标签池和可操作模型
 *
 * @param array $params
 * @return array
 */
function api_agent_get_context($params) {
    global $tododb;

    $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : (defined('DB_PREFIX') ? DB_PREFIX : 'todo_');

    // 站点基本信息
    $site_name = function_exists('get_option') ? get_option('site_name', 'TodoCMS') : 'TodoCMS';
    $site_url = function_exists('get_site_url') ? get_site_url() : '';
    $current_lang = function_exists('get_current_language') ? get_current_language() : 'zh';
    $supported_langs = function_exists('get_supported_languages') ? get_supported_languages() : ['zh', 'en'];

    // 获取系统数据模型列表
    $models_list = [];
    try {
        $models_rows = $tododb->get_results("
            SELECT model_id, model_name, model_key, model_type, model_description
            FROM {$prefix}models
            WHERE model_status = 'active'
            ORDER BY model_id ASC
        ");
        if ($models_rows) {
            foreach ($models_rows as $mr) {
                $models_list[] = [
                    'id' => intval($mr->model_id),
                    'name' => (string) $mr->model_name,
                    'key' => (string) $mr->model_key,
                    'type' => (string) $mr->model_type,
                    'description' => (string) $mr->model_description
                ];
            }
        }
    } catch (Exception $e) {
        $models_list = [];
    }

    // 获取所有可用分类列表（通过 term_models 关联 models 表获取精准模型类型）
    $categories = [];
    try {
        $cats_query = "
            SELECT 
                t.term_id, 
                t.name, 
                t.slug, 
                tt.parent, 
                tt.description, 
                COALESCE(m.model_key, m.model_type, 'article') AS model_type,
                m.model_name,
                m.model_id
            FROM {$prefix}terms t
            INNER JOIN {$prefix}term_taxonomy tt ON t.term_id = tt.term_id
            LEFT JOIN {$prefix}term_models tm ON t.term_id = tm.term_id
            LEFT JOIN {$prefix}models m ON tm.model_id = m.model_id
            WHERE tt.taxonomy = 'category'
            ORDER BY tt.parent ASC, tt.term_order ASC, t.term_id ASC
        ";
        $cat_rows = $tododb->get_results($cats_query);
        if ($cat_rows) {
            foreach ($cat_rows as $row) {
                $categories[] = [
                    'id' => intval($row->term_id),
                    'name' => (string) $row->name,
                    'slug' => (string) $row->slug,
                    'parent_id' => intval($row->parent),
                    'model_id' => !empty($row->model_id) ? intval($row->model_id) : 1,
                    'model_type' => !empty($row->model_type) ? (string) $row->model_type : 'article',
                    'model_name' => !empty($row->model_name) ? (string) $row->model_name : '文章模型'
                ];
            }
        }
    } catch (Exception $e) {
        $categories = [];
    }

    // 获取热门标签（从独立 tags 表查询）
    $tags = [];
    try {
        $tags_query = "
            SELECT tag_id, tag_title, tag_slug, tag_count
            FROM {$prefix}tags
            WHERE tag_status = 'active'
            ORDER BY tag_count DESC, tag_id ASC
            LIMIT 50
        ";
        $tag_rows = $tododb->get_results($tags_query);
        if ($tag_rows) {
            foreach ($tag_rows as $row) {
                $tags[] = [
                    'id' => intval($row->tag_id),
                    'name' => (string) $row->tag_title,
                    'slug' => (string) $row->tag_slug,
                    'count' => intval($row->tag_count)
                ];
            }
        }
    } catch (Exception $e) {
        $tags = [];
    }

    return [
        'site_info' => [
            'name' => $site_name,
            'url' => $site_url,
            'current_language' => $current_lang,
            'supported_languages' => $supported_langs
        ],
        'capabilities' => [
            'markdown_supported' => true,
            'post_statuses' => ['publish', 'draft', 'pending'],
            'product_sale_statuses' => ['on_sale', 'off_sale', 'out_of_stock']
        ],
        'models' => $models_list,
        'categories' => $categories,
        'popular_tags' => $tags
    ];
}

/**
 * 2. Agent 专属文章发布端点
 * 支持 Markdown 自动转 HTML、分类名称自动对齐、标签数组自创建、草稿/审核流
 *
 * @param array $params
 * @return array
 */
function api_agent_publish_post($params) {
    global $tododb;

    // 安全防线：校验发文权限 (publish 或 admin)
    if (class_exists('Todo_API')) {
        $api_instance = Todo_API::init();
        if (method_exists($api_instance, 'current_key_can') && !$api_instance->current_key_can('publish')) {
            send_api_error(403, '权限不足：当前使用的 API Key 未被授予文章发布与编辑权限 (publish)');
        }
    }

    $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : (defined('DB_PREFIX') ? DB_PREFIX : 'todo_');

    // 必填参数校验
    $title = isset($params['title']) ? trim((string) $params['title']) : '';
    if ($title === '') {
        send_api_error(400, '缺少必要参数: title');
    }

    $raw_content = isset($params['content']) ? (string) $params['content'] : '';
    if (trim($raw_content) === '') {
        send_api_error(400, '缺少必要参数: content');
    }

    // Markdown 自动转换
    $is_markdown = !isset($params['is_markdown']) || !empty($params['is_markdown']);
    $content_html = $is_markdown ? todo_agent_markdown_to_html($raw_content) : (function_exists('todo_kses_post') ? todo_kses_post($raw_content) : $raw_content);

    // 摘要（若无则自动截取纯文本前 150 字）
    $excerpt = isset($params['excerpt']) ? trim((string) $params['excerpt']) : '';
    if ($excerpt === '') {
        $plain = strip_tags($content_html);
        $excerpt = mb_substr(trim($plain), 0, 150, 'UTF-8');
    }

    // 状态：默认为 draft 保障人工安全审核；也可以指定 publish
    $allowed_statuses = ['publish', 'draft', 'pending'];
    $status = isset($params['status']) && in_array(strtolower((string)$params['status']), $allowed_statuses, true)
        ? strtolower((string)$params['status'])
        : 'draft';

    // 作者归属
    $current_user_id = function_exists('get_current_user_id') ? get_current_user_id() : 1;
    if ($current_user_id <= 0) {
        $current_user_id = 1;
    }

    $now = current_time('mysql');
    $now_gmt = current_time('mysql', true);
    $slug = !empty($params['slug']) ? sanitize_title($params['slug']) : sanitize_title($title);

    // 关联或查找分类
    $category_id = 0;
    if (!empty($params['category'])) {
        $cat_input = trim((string) $params['category']);
        if (ctype_digit($cat_input)) {
            $category_id = intval($cat_input);
        } else {
            // 根据名称或别名查找
            $found_id = $tododb->get_var($tododb->prepare("SELECT term_id FROM {$prefix}terms WHERE name = %s OR slug = %s LIMIT 1", $cat_input, $cat_input));
            if ($found_id) {
                $category_id = intval($found_id);
            }
        }
    }

    // 根据分类查询绑定的模型ID及模型标识
    $model_id = 1;
    $post_type = 'article';
    if ($category_id > 0) {
        $bound_model_row = $tododb->get_row($tododb->prepare("
            SELECT m.model_id, m.model_key, m.model_type
            FROM {$prefix}term_models tm
            INNER JOIN {$prefix}models m ON tm.model_id = m.model_id
            WHERE tm.term_id = %d
            LIMIT 1
        ", $category_id));

        if ($bound_model_row) {
            $model_id = intval($bound_model_row->model_id);
            $post_type = !empty($bound_model_row->model_key) ? (string)$bound_model_row->model_key : 'article';
        }
    }

    $seo_title = !empty($params['seo_title']) ? sanitize_text_field($params['seo_title']) : '';
    $seo_description = !empty($params['seo_description']) ? sanitize_text_field($params['seo_description']) : '';
    $seo_keywords = !empty($params['seo_keywords']) ? sanitize_text_field($params['seo_keywords']) : '';

    // 插入主表
    $insert_data = [
        'post_author' => $current_user_id,
        'category_id' => $category_id,
        'model_id' => $model_id,
        'post_date' => $now,
        'post_date_gmt' => $now_gmt,
        'post_content' => $content_html,
        'post_title' => sanitize_text_field($title),
        'post_excerpt' => sanitize_text_field($excerpt),
        'post_status' => $status,
        'comment_status' => 'open',
        'post_name' => $slug,
        'post_modified' => $now,
        'post_modified_gmt' => $now_gmt,
        'post_type' => $post_type,
        'seo_title' => $seo_title,
        'seo_description' => $seo_description,
        'seo_keywords' => $seo_keywords
    ];

    $res = $tododb->insert("{$prefix}posts", $insert_data);
    if (!$res) {
        $err = !empty($tododb->last_error) ? $tododb->last_error : '数据库插入失败';
        send_api_error(500, '写入文章数据失败: ' . $err);
    }

    $post_id = intval($tododb->insert_id());

    if ($category_id > 0 && function_exists('add_post_term')) {
        add_post_term($post_id, $category_id, 'category');
    }

    // 关联标签（使用独立标签系统，自动匹配/创建）
    if (!empty($params['tags']) && is_array($params['tags'])) {
        if (function_exists('todo_set_post_tags')) {
            todo_set_post_tags($post_id, $params['tags']);
        } elseif (class_exists('CMS_Tags_Manager')) {
            (new CMS_Tags_Manager())->set_post_tags($post_id, $params['tags']);
        }
    }

    // 存储 SEO 及 Agent 元数据
    if (function_exists('update_post_meta')) {
        if (!empty($params['seo_title'])) {
            update_post_meta($post_id, 'seo_title', sanitize_text_field($params['seo_title']));
        }
        if (!empty($params['seo_description'])) {
            update_post_meta($post_id, 'seo_description', sanitize_text_field($params['seo_description']));
        }
        if (!empty($params['seo_keywords'])) {
            update_post_meta($post_id, 'seo_keywords', sanitize_text_field($params['seo_keywords']));
        }
        // 标记此文章由 AI Agent 创建
        update_post_meta($post_id, '_created_by_agent', '1');
        $agent_name = !empty($params['agent_name']) ? sanitize_text_field($params['agent_name']) : 'AI Assistant';
        update_post_meta($post_id, '_agent_source', $agent_name);
    }

    // 清除相关缓存
    if (class_exists('Todo_Cache_Manager') && is_callable(['Todo_Cache_Manager', 'deleteByPattern'])) {
        Todo_Cache_Manager::deleteByPattern('post_*');
    }

    return [
        'id' => $post_id,
        'title' => $title,
        'status' => $status,
        'url' => function_exists('get_permalink') ? get_permalink($post_id) : '',
        'created_at' => $now,
        'message' => $status === 'publish' ? '文章已成功发布' : '文章已保存为草稿，等待人工审核'
    ];
}

/**
 * 3. Agent 快速更新商品库存、售价与状态
 *
 * @param array $params
 * @return array
 */
function api_agent_update_product_stock($params) {
    global $tododb;

    // 安全防线：校验商品管理权限 (products:manage 或 admin)
    if (class_exists('Todo_API')) {
        $api_instance = Todo_API::init();
        if (method_exists($api_instance, 'current_key_can') && !$api_instance->current_key_can('products:manage')) {
            send_api_error(403, '权限不足：当前使用的 API Key 未被授予商品管理权限 (products:manage)');
        }
    }

    $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : (defined('DB_PREFIX') ? DB_PREFIX : 'todo_');
    $table_mfp = $prefix . 'model_fields_product';

    if (!isset($params['product_id']) || intval($params['product_id']) <= 0) {
        send_api_error(400, '缺少有效参数: product_id');
    }

    $product_id = intval($params['product_id']);

    // 检查商品是否存在
    $post_exists = $tododb->get_var($tododb->prepare("SELECT ID FROM {$prefix}posts WHERE ID = %d AND post_type = 'product' LIMIT 1", $product_id));
    if (!$post_exists) {
        send_api_error(404, '指定的商品不存在');
    }

    $update_fields = [];
    $update_args = [];

    if (isset($params['stock'])) {
        $update_fields[] = 'stock = %d';
        $update_args[] = intval($params['stock']);
    }

    if (isset($params['price'])) {
        $update_fields[] = 'price = %f';
        $update_args[] = floatval($params['price']);
    }

    if (isset($params['original_price'])) {
        $update_fields[] = 'original_price = %f';
        $update_args[] = floatval($params['original_price']);
    }

    if (isset($params['sale_status'])) {
        $st = strtolower(trim((string)$params['sale_status']));
        if ($st === 'on_sale' || $st === 'online') {
            $update_fields[] = 'sale_status = %s';
            $update_args[] = 'online';
        } elseif ($st === 'off_sale' || $st === 'offline') {
            $update_fields[] = 'sale_status = %s';
            $update_args[] = 'offline';
        }
    }

    if (empty($update_fields)) {
        send_api_error(400, '未提供需要更新的商品字段 (stock, price, original_price, sale_status)');
    }

    // 检查是否有 model_fields_product 记录
    $row_exists = $tododb->get_var($tododb->prepare("SELECT post_id FROM {$table_mfp} WHERE post_id = %d LIMIT 1", $product_id));

    if ($row_exists) {
        $sql = "UPDATE {$table_mfp} SET " . implode(', ', $update_fields) . " WHERE post_id = %d";
        $update_args[] = $product_id;
        $tododb->query($tododb->prepare($sql, ...$update_args));
    } else {
        // 插入记录
        $insert_data = [
            'post_id' => $product_id,
            'stock' => isset($params['stock']) ? intval($params['stock']) : 0,
            'price' => isset($params['price']) ? floatval($params['price']) : 0,
            'original_price' => isset($params['original_price']) ? floatval($params['original_price']) : 0,
            'sale_status' => isset($params['sale_status']) ? sanitize_text_field($params['sale_status']) : 'on_sale'
        ];
        $tododb->insert($table_mfp, $insert_data);
    }

    // 更新商品更新时间
    $now = current_time('mysql');
    $tododb->query($tododb->prepare("UPDATE {$prefix}posts SET post_modified = %s, post_modified_gmt = %s WHERE ID = %d", $now, current_time('mysql', true), $product_id));

    // 清除缓存
    if (class_exists('Todo_Cache_Manager') && is_callable(['Todo_Cache_Manager', 'deleteByPattern'])) {
        Todo_Cache_Manager::deleteByPattern('product_*');
    }

    return [
        'product_id' => $product_id,
        'updated_fields' => array_keys($params),
        'message' => '商品属性与库存更新成功'
    ];
}

/**
 * 4. 动态输出符合 OpenAPI 3.0.0 规范的 Spec
 * 外部平台（GPTs Actions, Dify, Coze, Postman）一键导入此 URL 即可自动化对接
 *
 * @param array $params
 * @return array
 */
function api_agent_get_openapi($params) {
    $base_url = function_exists('get_site_url') ? rtrim(get_site_url(), '/') : '';
    $api_server = $base_url . '/api/v1';

    $spec = [
        'openapi' => '3.0.0',
        'info' => [
            'title' => 'TodoCMS Agent API',
            'version' => '1.0.0',
            'description' => 'TodoCMS 官方 AI Agent 规范接口，支持文章发布、商品管理、上下文自省与分类标签检索。'
        ],
        'servers' => [
            [
                'url' => $api_server,
                'description' => 'TodoCMS 当前 API 服务器'
            ]
        ],
        'components' => [
            'securitySchemes' => [
                'ApiKeyAuth' => [
                    'type' => 'apiKey',
                    'in' => 'header',
                    'name' => 'X-API-Key',
                    'description' => 'TodoCMS 生成的 API 密钥'
                ],
                'BearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'JWT'
                ]
            ]
        ],
        'security' => [
            ['ApiKeyAuth' => []],
            ['BearerAuth' => []]
        ],
        'paths' => [
            '/agent/context' => [
                'get' => [
                    'operationId' => 'todocms_get_context',
                    'summary' => '获取站点的自省上下文（分类树、标签池、语言和状态定义）',
                    'responses' => [
                        '200' => [
                            'description' => '站点上下文结构'
                        ]
                    ]
                ]
            ],
            '/agent/posts/publish' => [
                'post' => [
                    'operationId' => 'todocms_publish_post',
                    'summary' => '以 AI Agent 身份智能发布文章（支持 Markdown 转 HTML、分类/标签对齐、草稿审核）',
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['title', 'content'],
                                    'properties' => [
                                        'title' => ['type' => 'string', 'description' => '文章标题'],
                                        'content' => ['type' => 'string', 'description' => '文章正文（支持 Markdown 语法）'],
                                        'status' => ['type' => 'string', 'enum' => ['draft', 'publish', 'pending'], 'default' => 'draft', 'description' => '状态：默认 draft 保存草稿以待审核，亦可填 publish 直接发布'],
                                        'category' => ['type' => 'string', 'description' => '所属分类名称或 ID'],
                                        'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '文章标签列表'],
                                        'excerpt' => ['type' => 'string', 'description' => '文章摘要（可选）'],
                                        'seo_title' => ['type' => 'string', 'description' => 'SEO 标题（可选）'],
                                        'seo_description' => ['type' => 'string', 'description' => 'SEO 描述（可选）'],
                                        'seo_keywords' => ['type' => 'string', 'description' => 'SEO 关键词（可选）'],
                                        'agent_name' => ['type' => 'string', 'description' => '操作此操作的 Agent 标识（可选）']
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'responses' => [
                        '200' => ['description' => '发布结果，包含文章 ID 和状态']
                    ]
                ]
            ],
            '/posts' => [
                'get' => [
                    'operationId' => 'todocms_list_posts',
                    'summary' => '查询文章列表（支持分类、标签、关键词筛选及分页）',
                    'parameters' => [
                        ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 1]],
                        ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 10]],
                        ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string']],
                        ['name' => 'category', 'in' => 'query', 'schema' => ['type' => 'string']]
                    ],
                    'responses' => [
                        '200' => ['description' => '文章列表及分页信息']
                    ]
                ]
            ],
            '/posts/{id}' => [
                'get' => [
                    'operationId' => 'todocms_get_post',
                    'summary' => '获取单篇文章的详细内容与 SEO 元数据',
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]
                    ],
                    'responses' => [
                        '200' => ['description' => '文章详情数据']
                    ]
                ]
            ],
            '/products' => [
                'get' => [
                    'operationId' => 'todocms_list_products',
                    'summary' => '查询商品列表（含价格、库存、规格与上下架状态）',
                    'parameters' => [
                        ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 1]],
                        ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 10]],
                        ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string']]
                    ],
                    'responses' => [
                        '200' => ['description' => '商品列表数据']
                    ]
                ]
            ],
            '/agent/products/update-stock' => [
                'post' => [
                    'operationId' => 'todocms_update_product_stock',
                    'summary' => '快捷更新商品库存、售价与销售状态',
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['product_id'],
                                    'properties' => [
                                        'product_id' => ['type' => 'integer', 'description' => '商品 ID'],
                                        'stock' => ['type' => 'integer', 'description' => '调整后的库存数量'],
                                        'price' => ['type' => 'number', 'description' => '调整后的售价'],
                                        'original_price' => ['type' => 'number', 'description' => '调整后的划线原价'],
                                        'sale_status' => ['type' => 'string', 'enum' => ['on_sale', 'off_sale', 'out_of_stock'], 'description' => '销售状态']
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'responses' => [
                        '200' => ['description' => '更新结果']
                    ]
                ]
            ],
            '/agent/products/create' => [
                'post' => [
                    'operationId' => 'todocms_create_product',
                    'summary' => '创建并上架新商品（支持售价、划线价、库存、分类与富文本/Markdown介绍）',
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['title', 'price'],
                                    'properties' => [
                                        'title' => ['type' => 'string', 'description' => '商品名称'],
                                        'price' => ['type' => 'number', 'description' => '商品售价'],
                                        'original_price' => ['type' => 'number', 'description' => '划线原价（可选）'],
                                        'stock' => ['type' => 'integer', 'description' => '库存数量', 'default' => 0],
                                        'category' => ['type' => 'string', 'description' => '所属商品分类名称或 ID'],
                                        'content' => ['type' => 'string', 'description' => '商品详细介绍与规格描述（支持 Markdown）'],
                                        'sale_status' => ['type' => 'string', 'enum' => ['on_sale', 'off_sale'], 'default' => 'on_sale']
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'responses' => [
                        '200' => ['description' => '商品创建成功']
                    ]
                ]
            ],
            '/agent/i18n/scan' => [
                'get' => [
                    'operationId' => 'todocms_scan_missing_translations',
                    'summary' => '智能扫描全站模板、接口与数据库中标记的多语言词汇，提取指定目标语言包中缺失的未翻译条目',
                    'parameters' => [
                        ['name' => 'theme', 'in' => 'query', 'schema' => ['type' => 'string', 'default' => 'default'], 'description' => '主题目录名，默认 default'],
                        ['name' => 'lang', 'in' => 'query', 'schema' => ['type' => 'string', 'default' => 'en_US'], 'description' => '目标语言代号（如 en_US, ja_JP, zh_TW）'],
                        ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 100], 'description' => '返回缺失词条的最大数量（0 为全部）']
                    ],
                    'responses' => [
                        '200' => ['description' => '扫描结果统计与缺失词汇列表']
                    ]
                ]
            ],
            '/agent/i18n/update' => [
                'post' => [
                    'operationId' => 'todocms_update_language_pack',
                    'summary' => '将 AI Agent 翻译后的键值对增量合并并安全写回目标主题的语言包文件，自动刷新翻译缓存',
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['lang', 'translations'],
                                    'properties' => [
                                        'lang' => ['type' => 'string', 'description' => '目标语言代号，如 en_US'],
                                        'theme' => ['type' => 'string', 'default' => 'default', 'description' => '主题名称'],
                                        'translations' => [
                                            'type' => 'object',
                                            'additionalProperties' => ['type' => 'string'],
                                            'description' => '待写入的翻译键值对字典（如 {"原文": "Translated Text"}）'
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'responses' => [
                        '200' => ['description' => '写入合并结果']
                    ]
                ]
            ],
            '/agent/system/flush-cache' => [
                'post' => [
                    'operationId' => 'todocms_flush_cache',
                    'summary' => '清理 TodoCMS 系统与组件缓存（支持全量缓存、文章缓存、商品缓存或多语言翻译缓存）',
                    'requestBody' => [
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'type' => [
                                            'type' => 'string',
                                            'enum' => ['all', 'posts', 'products', 'i18n'],
                                            'default' => 'all',
                                            'description' => '要清理的缓存域'
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'responses' => [
                        '200' => ['description' => '缓存清理状态信息']
                    ]
                ]
            ],
            '/agent/media/upload' => [
                'post' => [
                    'operationId' => 'todocms_upload_media',
                    'summary' => '由 Agent 下载并转存外部图片/媒体至站内媒体库，返回站内附件地址与元数据',
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'url' => ['type' => 'string', 'description' => '外部图片或媒体的公开网络 URL'],
                                        'base64_data' => ['type' => 'string', 'description' => '图片的 Base64 编码字符串（可选）'],
                                        'title' => ['type' => 'string', 'description' => '媒体标题'],
                                        'alt' => ['type' => 'string', 'description' => '图片 Alt 描述，用于 SEO']
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'responses' => [
                        '200' => ['description' => '上传成功，返回媒体 ID 与 URL']
                    ]
                ]
            ],
            '/agent/comments' => [
                'get' => [
                    'operationId' => 'todocms_list_comments',
                    'summary' => '拉取前台评论与留言列表，供 Agent 自动识别垃圾广告或进行智能回复',
                    'parameters' => [
                        ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string', 'default' => 'pending'], 'description' => '审核状态：pending（待审核）、approved（已审核）、spam（垃圾）、all（全部）'],
                        ['name' => 'post_id', 'in' => 'query', 'schema' => ['type' => 'integer'], 'description' => '关联文章 ID'],
                        ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 1]],
                        ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 20]]
                    ],
                    'responses' => [
                        '200' => ['description' => '评论列表与分页数据']
                    ]
                ]
            ],
            '/agent/comments/audit' => [
                'post' => [
                    'operationId' => 'todocms_audit_comment',
                    'summary' => '对前台评论执行智能审核（通过、驳回、标记为垃圾、删除或追加 AI 回复）',
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['comment_id', 'action'],
                                    'properties' => [
                                        'comment_id' => ['type' => 'integer', 'description' => '评论 ID'],
                                        'action' => ['type' => 'string', 'enum' => ['approve', 'reject', 'spam', 'trash', 'delete'], 'description' => '审核动作'],
                                        'reply' => ['type' => 'string', 'description' => '管理员/AI 回复内容（可选）']
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'responses' => [
                        '200' => ['description' => '审核与回复操作结果']
                    ]
                ]
            ],
            '/agent/settings' => [
                'get' => [
                    'operationId' => 'todocms_get_settings',
                    'summary' => '查询站点的基础公开配置或特定 Key（如网站名称、公告、SEO 关键词等）',
                    'parameters' => [
                        ['name' => 'keys', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => '指定要查询的键名，以逗号分隔，留空则返回常用配置集合']
                    ],
                    'responses' => [
                        '200' => ['description' => '配置项键值对字典']
                    ]
                ],
                'post' => [
                    'operationId' => 'todocms_update_setting',
                    'summary' => '安全修改站点指定配置项（支持白名单防护）',
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['key', 'value'],
                                    'properties' => [
                                        'key' => ['type' => 'string', 'description' => '配置项键名'],
                                        'value' => ['type' => 'string', 'description' => '新配置值']
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'responses' => [
                        '200' => ['description' => '更新结果']
                    ]
                ]
            ],
            '/agent/theme/files' => [
                'get' => [
                    'operationId' => 'todocms_list_theme_files',
                    'summary' => '查看当前主题下的模板结构树，便于 Agent 分析页面结构、SEO 标签及组件分布',
                    'parameters' => [
                        ['name' => 'theme', 'in' => 'query', 'schema' => ['type' => 'string', 'default' => 'default'], 'description' => '主题名称'],
                        ['name' => 'device', 'in' => 'query', 'schema' => ['type' => 'string', 'default' => 'all'], 'description' => '设备端目录过滤：all, pc, mobile']
                    ],
                    'responses' => [
                        '200' => ['description' => '模板文件树列表']
                    ]
                ]
            ],
            '/agent/theme/file' => [
                'get' => [
                    'operationId' => 'todocms_get_theme_file',
                    'summary' => '安全读取指定主题模板文件内容，用于 Agent 进行结构化分析（如 SEO 审查、DOM 改造）',
                    'parameters' => [
                        ['name' => 'file_path', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string'], 'description' => '模板相对路径，例如 pc/checkout.html 或 pc/lists_article.html'],
                        ['name' => 'theme', 'in' => 'query', 'schema' => ['type' => 'string', 'default' => 'default']]
                    ],
                    'responses' => [
                        '200' => ['description' => '模板文件完整内容与最后修改时间']
                    ]
                ],
                'post' => [
                    'operationId' => 'todocms_update_theme_file',
                    'summary' => '安全写回优化后的主题模板代码，具备防路径穿越、自动创建备份与即时编译刷新机制',
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['file_path', 'content'],
                                    'properties' => [
                                        'file_path' => ['type' => 'string', 'description' => '模板文件相对路径，如 pc/lists_article.html'],
                                        'content' => ['type' => 'string', 'description' => '优化修改后的完整模板内容'],
                                        'theme' => ['type' => 'string', 'default' => 'default', 'description' => '主题名称'],
                                        'backup' => ['type' => 'boolean', 'default' => true, 'description' => '是否在写回前自动备份原文件']
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'responses' => [
                        '200' => ['description' => '保存结果与备份路径']
                    ]
                ]
            ]
        ]
    ];

    return $spec;
}

/**
 * 5. Agent 创建并上架新商品
 *
 * @param array $params
 * @return array
 */
function api_agent_create_product($params) {
    global $tododb;

    // 安全防线：校验商品创建权限 (products:manage 或 admin)
    if (class_exists('Todo_API')) {
        $api_instance = Todo_API::init();
        if (method_exists($api_instance, 'current_key_can') && !$api_instance->current_key_can('products:manage')) {
            send_api_error(403, '权限不足：当前使用的 API Key 未被授予商品管理权限 (products:manage)');
        }
    }

    $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : (defined('DB_PREFIX') ? DB_PREFIX : 'todo_');
    $table_mfp = $prefix . 'model_fields_product';

    $title = isset($params['title']) ? trim((string) $params['title']) : '';
    if ($title === '') {
        send_api_error(400, '缺少必要参数: title (商品标题)');
    }

    $price = isset($params['price']) ? floatval($params['price']) : 0.0;
    $original_price = isset($params['original_price']) ? floatval($params['original_price']) : 0.0;
    $stock = isset($params['stock']) ? intval($params['stock']) : 0;
    $sale_status_raw = strtolower(trim((string) ($params['sale_status'] ?? 'online')));
    $sale_status = in_array($sale_status_raw, ['online', 'on_sale'], true) ? 'online' : 'offline';
    $raw_content = isset($params['content']) ? (string) $params['content'] : '';
    $content_html = todo_agent_markdown_to_html($raw_content);

    $current_user_id = function_exists('get_current_user_id') ? get_current_user_id() : 1;
    if ($current_user_id <= 0) $current_user_id = 1;

    $now = current_time('mysql');
    $now_gmt = current_time('mysql', true);
    $slug = !empty($params['slug']) ? sanitize_title($params['slug']) : sanitize_title($title);

    // 查找分类
    $category_id = 0;
    if (!empty($params['category'])) {
        $cat_input = trim((string) $params['category']);
        if (ctype_digit($cat_input)) {
            $category_id = intval($cat_input);
        } else {
            $found_id = $tododb->get_var($tododb->prepare("SELECT term_id FROM {$prefix}terms WHERE name = %s OR slug = %s LIMIT 1", $cat_input, $cat_input));
            if ($found_id) $category_id = intval($found_id);
        }
    }

    // 若未指定分类，则自动寻找产品模型绑定的默认分类
    if ($category_id <= 0) {
        $default_cat = $tododb->get_var("
            SELECT tm.term_id 
            FROM {$prefix}term_models tm
            INNER JOIN {$prefix}models m ON tm.model_id = m.model_id
            WHERE m.model_key = 'product' OR m.model_type = 'product'
            ORDER BY tm.term_id ASC LIMIT 1
        ");
        if ($default_cat) {
            $category_id = intval($default_cat);
        }
    }

    // 插入主表 (post_type = 'product', model_id = 2)
    $insert_post = [
        'post_author' => $current_user_id,
        'category_id' => $category_id,
        'model_id' => 2,
        'post_date' => $now,
        'post_date_gmt' => $now_gmt,
        'post_content' => $content_html,
        'post_title' => sanitize_text_field($title),
        'post_excerpt' => isset($params['excerpt']) ? sanitize_text_field($params['excerpt']) : '',
        'post_status' => 'publish',
        'comment_status' => 'open',
        'post_name' => $slug,
        'post_modified' => $now,
        'post_modified_gmt' => $now_gmt,
        'post_type' => 'product',
        'seo_title' => isset($params['seo_title']) ? sanitize_text_field($params['seo_title']) : '',
        'seo_description' => isset($params['seo_description']) ? sanitize_text_field($params['seo_description']) : '',
        'seo_keywords' => isset($params['seo_keywords']) ? sanitize_text_field($params['seo_keywords']) : ''
    ];

    $res = $tododb->insert("{$prefix}posts", $insert_post);
    if (!$res) {
        $err = !empty($tododb->last_error) ? $tododb->last_error : '数据库插入失败';
        send_api_error(500, '创建商品主记录失败: ' . $err);
    }

    $product_id = intval($tododb->insert_id());

    // 插入 model_fields_product 表
    $insert_mfp = [
        'post_id' => $product_id,
        'price' => $price,
        'original_price' => $original_price,
        'stock' => $stock,
        'sale_status' => $sale_status
    ];
    $tododb->insert($table_mfp, $insert_mfp);

    if ($category_id > 0 && function_exists('add_post_term')) {
        add_post_term($product_id, $category_id, 'category');
    }

    // 商品标签关联
    if (!empty($params['tags']) && is_array($params['tags'])) {
        if (function_exists('todo_set_post_tags')) {
            todo_set_post_tags($product_id, $params['tags']);
        } elseif (class_exists('CMS_Tags_Manager')) {
            (new CMS_Tags_Manager())->set_post_tags($product_id, $params['tags']);
        }
    }

    // 清除商品缓存
    if (class_exists('Todo_Cache_Manager') && is_callable(['Todo_Cache_Manager', 'deleteByPattern'])) {
        Todo_Cache_Manager::deleteByPattern('product_*');
    }

    return [
        'product_id' => $product_id,
        'title' => $title,
        'price' => $price,
        'stock' => $stock,
        'sale_status' => $sale_status,
        'url' => function_exists('get_permalink') ? get_permalink($product_id) : '',
        'message' => '商品创建并上架成功'
    ];
}

/**
 * 6. Agent 扫描全站多语言词汇并提取待补译条目
 *
 * @param array $params
 * @return array
 */
function api_agent_scan_i18n($params) {
    if (!class_exists('Todo_Language_Scanner')) {
        $scanner_file = ABSPATH . 'core/classes/class-language-scanner.php';
        if (file_exists($scanner_file)) {
            require_once $scanner_file;
        } else {
            send_api_error(500, '多语言扫描器组件未安装');
        }
    }

    $theme = !empty($params['theme']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$params['theme']) : 'default';
    $lang = !empty($params['lang']) ? preg_replace('/[^a-zA-Z0-9_]/', '', (string)$params['lang']) : 'en_US';
    $limit = isset($params['limit']) ? intval($params['limit']) : 100;

    $result = Todo_Language_Scanner::get_missing_translations($lang, $theme, $limit);
    return $result;
}

/**
 * 7. Agent 将翻译结果合并写回语言包
 *
 * @param array $params
 * @return array
 */
function api_agent_update_i18n($params) {
    if (!class_exists('Todo_Language_Scanner')) {
        $scanner_file = ABSPATH . 'core/classes/class-language-scanner.php';
        if (file_exists($scanner_file)) {
            require_once $scanner_file;
        } else {
            send_api_error(500, '多语言扫描器组件未安装');
        }
    }

    $lang = !empty($params['lang']) ? preg_replace('/[^a-zA-Z0-9_]/', '', (string)$params['lang']) : '';
    if (empty($lang)) {
        send_api_error(400, '缺少必要参数: lang（目标语言代号，如 en_US）');
    }

    $translations = isset($params['translations']) ? $params['translations'] : null;
    if (!is_array($translations) || empty($translations)) {
        send_api_error(400, '缺少必要参数或翻译字典为空: translations');
    }

    $theme = !empty($params['theme']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$params['theme']) : 'default';

    $res = Todo_Language_Scanner::update_language_pack($lang, $translations, $theme);
    if (empty($res['success'])) {
        send_api_error(500, $res['message'] ?? '写回语言包失败');
    }

    return $res;
}

/**
 * 8. Agent 清理系统或业务缓存
 *
 * @param array $params
 * @return array
 */
function api_agent_flush_cache($params) {
    // 安全防线：校验系统缓存清理权限 (system:manage 或 admin)
    if (class_exists('Todo_API')) {
        $api_instance = Todo_API::init();
        if (method_exists($api_instance, 'current_key_can') && !$api_instance->current_key_can('system:manage')) {
            send_api_error(403, '权限不足：当前使用的 API Key 未被授予系统维护与缓存清理权限 (system:manage)');
        }
    }

    $type = !empty($params['type']) ? strtolower(trim((string)$params['type'])) : 'all';
    $cleared = [];

    if ($type === 'posts' || $type === 'all') {
        if (class_exists('Todo_Cache_Manager') && is_callable(['Todo_Cache_Manager', 'deleteByPattern'])) {
            Todo_Cache_Manager::deleteByPattern('post_*');
        }
        $cleared[] = 'posts';
    }

    if ($type === 'products' || $type === 'all') {
        if (class_exists('Todo_Cache_Manager') && is_callable(['Todo_Cache_Manager', 'deleteByPattern'])) {
            Todo_Cache_Manager::deleteByPattern('product_*');
        }
        $cleared[] = 'products';
    }

    if ($type === 'i18n' || $type === 'all') {
        if (class_exists('CMS_Translation') && is_callable(['CMS_Translation', 'clear_cache'])) {
            CMS_Translation::clear_cache();
        }
        $cleared[] = 'i18n';
    }

    if ($type === 'all') {
        if (class_exists('Todo_Cache_Manager') && is_callable(['Todo_Cache_Manager', 'flush_all'])) {
            Todo_Cache_Manager::flush_all();
        }
        $cleared[] = 'system_all';
    }

    return [
        'success' => true,
        'type' => $type,
        'cleared_scopes' => $cleared,
        'message' => '指定缓存已成功清除'
    ];
}

/**
 * 9. Agent 转存并入库外链图片/媒体文件
 *
 * @param array $params
 * @return array
 */
function api_agent_upload_media($params) {
    global $tododb;
    $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : (defined('DB_PREFIX') ? DB_PREFIX : 'todo_');

    $url = isset($params['url']) ? trim((string)$params['url']) : '';
    $base64 = isset($params['base64_data']) ? trim((string)$params['base64_data']) : '';
    $title = isset($params['title']) ? trim((string)$params['title']) : '';
    $alt = isset($params['alt']) ? trim((string)$params['alt']) : '';

    if ($url === '' && $base64 === '') {
        send_api_error(400, '缺少必要参数: url 或 base64_data 至少提供一项');
    }

    $raw_content = '';
    $suggested_ext = 'jpg';

    if ($url !== '') {
        // SSRF 防护：校验非私有/本地 IP
        $parsed = parse_url($url);
        if (empty($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            send_api_error(400, 'URL 协议不合法，仅支持 http / https');
        }
        $host = $parsed['host'] ?? '';
        if (empty($host) || in_array(strtolower($host), ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            send_api_error(403, '禁止请求本地主机资源');
        }
        $ip = gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            send_api_error(403, '受限的请求地址：禁止访问私有内网或保留地址');
        }

        // 推测扩展名
        $path_part = parse_url($url, PHP_URL_PATH);
        if ($path_part) {
            $ext = strtolower(pathinfo($path_part, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'avif'], true)) {
                $suggested_ext = $ext;
            }
        }

        // 下载外链内容 (超时 25 秒)
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 25,
                'user_agent' => 'Mozilla/5.0 (compatible; TodoCMS-Agent/1.0; +https://todocms.com)',
                'follow_location' => 1,
                'max_redirects' => 5
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        $raw_content = @file_get_contents($url, false, $ctx);
        if ($raw_content === false || strlen($raw_content) === 0) {
            send_api_error(502, '抓取外部图片失败或网络超时: ' . $url);
        }
    } else {
        // Base64 解码
        if (preg_match('#^data:image/([a-zA-Z0-9_+]+);base64,#', $base64, $m)) {
            $suggested_ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
            $base64 = substr($base64, strlen($m[0]));
        }
        $raw_content = base64_decode($base64);
        if ($raw_content === false || strlen($raw_content) === 0) {
            send_api_error(400, 'Base64 数据解码失败');
        }
    }

    // 目录创建：content/uploads/YYYY/MM/
    $sub_dir = date('Y/m');
    $upload_dir = ABSPATH . 'content/uploads/' . $sub_dir;
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0755, true);
    }

    $safe_name = !empty($params['filename']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo($params['filename'], PATHINFO_FILENAME)) : '';
    if (empty($safe_name)) {
        $safe_name = 'agent_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    }
    $final_filename = $safe_name . '.' . $suggested_ext;
    $final_path = $upload_dir . '/' . $final_filename;

    if (@file_put_contents($final_path, $raw_content) === false) {
        send_api_error(500, '写入媒体文件失败，请检查 content/uploads 写入权限');
    }
    @chmod($final_path, 0644);

    // 探测图片元数据
    $file_size = strlen($raw_content);
    $width = null;
    $height = null;
    $mime_type = 'image/jpeg';
    if (function_exists('getimagesize')) {
        $img_info = @getimagesize($final_path);
        if ($img_info) {
            $width = $img_info[0];
            $height = $img_info[1];
            $mime_type = $img_info['mime'] ?? $mime_type;
        }
    }

    $relative_path = 'content/uploads/' . $sub_dir . '/' . $final_filename;
    $site_url = function_exists('get_site_url') ? rtrim(get_site_url(), '/') : '';
    $public_url = $site_url . '/' . $relative_path;

    if (empty($title)) {
        $title = !empty($alt) ? $alt : pathinfo($final_filename, PATHINFO_FILENAME);
    }

    $current_user_id = function_exists('get_current_user_id') ? get_current_user_id() : 1;
    if ($current_user_id <= 0) $current_user_id = 1;

    $now = current_time('mysql');
    $media_data = [
        'media_title' => sanitize_text_field($title),
        'media_filename' => $final_filename,
        'media_path' => $relative_path,
        'media_url' => $public_url,
        'media_type' => $mime_type,
        'media_size' => $file_size,
        'media_width' => $width,
        'media_height' => $height,
        'media_alt' => sanitize_text_field($alt),
        'upload_user' => $current_user_id,
        'upload_time' => $now,
        'media_status' => 'active',
        'created_at' => $now,
        'updated_at' => $now
    ];

    $res = $tododb->insert("{$prefix}media", $media_data);
    $media_id = $res ? intval($tododb->insert_id()) : 0;

    return [
        'media_id' => $media_id,
        'title' => $title,
        'filename' => $final_filename,
        'url' => $public_url,
        'path' => $relative_path,
        'width' => $width,
        'height' => $height,
        'size' => $file_size,
        'mime' => $mime_type,
        'message' => '外部媒体已成功转存至 TodoCMS 媒体库'
    ];
}

/**
 * 10. Agent 查询前台评论与留言列表
 *
 * @param array $params
 * @return array
 */
function api_agent_list_comments($params) {
    global $tododb;
    $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : (defined('DB_PREFIX') ? DB_PREFIX : 'todo_');

    $status = isset($params['status']) ? strtolower(trim((string)$params['status'])) : 'pending';
    $post_id = isset($params['post_id']) ? intval($params['post_id']) : 0;
    $page = max(1, isset($params['page']) ? intval($params['page']) : 1);
    $per_page = max(1, min(100, isset($params['per_page']) ? intval($params['per_page']) : 20));
    $offset = ($page - 1) * $per_page;

    $where_clauses = ["1=1"];
    $query_params = [];

    if ($status === 'pending') {
        $where_clauses[] = "c.comment_approved = '0'";
    } elseif ($status === 'approved') {
        $where_clauses[] = "c.comment_approved = '1'";
    } elseif ($status === 'spam') {
        $where_clauses[] = "c.comment_approved = 'spam'";
    } elseif ($status === 'trash') {
        $where_clauses[] = "c.comment_approved = 'trash'";
    }

    if ($post_id > 0) {
        $where_clauses[] = "c.comment_post_ID = %d";
        $query_params[] = $post_id;
    }

    $where_sql = implode(' AND ', $where_clauses);

    $count_sql = "SELECT COUNT(*) FROM {$prefix}comments c WHERE {$where_sql}";
    $total = !empty($query_params) ? intval($tododb->get_var($tododb->prepare($count_sql, ...$query_params))) : intval($tododb->get_var($count_sql));

    $data_sql = "
        SELECT 
            c.comment_ID, 
            c.comment_post_ID, 
            c.comment_author, 
            c.comment_author_email, 
            c.comment_author_IP, 
            c.comment_date, 
            c.comment_content, 
            c.comment_approved, 
            c.admin_reply, 
            c.replied_at, 
            p.post_title
        FROM {$prefix}comments c
        LEFT JOIN {$prefix}posts p ON c.comment_post_ID = p.ID
        WHERE {$where_sql}
        ORDER BY c.comment_ID DESC
        LIMIT %d, %d
    ";
    $query_params[] = $offset;
    $query_params[] = $per_page;

    $rows = $tododb->get_results($tododb->prepare($data_sql, ...$query_params));
    $list = [];
    if ($rows) {
        foreach ($rows as $r) {
            $status_str = 'pending';
            if ($r->comment_approved === '1') $status_str = 'approved';
            elseif ($r->comment_approved === 'spam') $status_str = 'spam';
            elseif ($r->comment_approved === 'trash') $status_str = 'trash';

            $list[] = [
                'id' => intval($r->comment_ID),
                'post_id' => intval($r->comment_post_ID),
                'post_title' => (string)($r->post_title ?? ''),
                'author' => (string)$r->comment_author,
                'email' => (string)$r->comment_author_email,
                'ip' => (string)$r->comment_author_IP,
                'content' => (string)$r->comment_content,
                'date' => (string)$r->comment_date,
                'status' => $status_str,
                'admin_reply' => (string)($r->admin_reply ?? ''),
                'replied_at' => (string)($r->replied_at ?? '')
            ];
        }
    }

    return [
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => ceil($total / $per_page),
        'items' => $list
    ];
}

/**
 * 11. Agent 审核或回复前台评论
 *
 * @param array $params
 * @return array
 */
function api_agent_audit_comment($params) {
    global $tododb;
    $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : (defined('DB_PREFIX') ? DB_PREFIX : 'todo_');

    $comment_id = isset($params['comment_id']) ? intval($params['comment_id']) : 0;
    if ($comment_id <= 0) {
        send_api_error(400, '缺少有效参数: comment_id');
    }

    $action = isset($params['action']) ? strtolower(trim((string)$params['action'])) : '';
    $allowed_actions = ['approve', 'reject', 'spam', 'trash', 'delete', 'reply'];
    if (!in_array($action, $allowed_actions, true)) {
        send_api_error(400, '无效的操作类型: ' . $action . '（可选 approve, reject, spam, trash, delete, reply）');
    }

    $exists = $tododb->get_var($tododb->prepare("SELECT comment_ID FROM {$prefix}comments WHERE comment_ID = %d LIMIT 1", $comment_id));
    if (!$exists) {
        send_api_error(404, '指定的评论不存在');
    }

    // 安全防线：校验评论审核权限
    if (class_exists('Todo_API')) {
        $api_instance = Todo_API::init();
        if (method_exists($api_instance, 'current_key_can') && !$api_instance->current_key_can('comments:audit')) {
            send_api_error(403, '权限不足：当前使用的 API Key 未被授予评论审核管理权限 (comments:audit)');
        }
    }

    if ($action === 'delete') {
        // 第一道防线：Agent 触发删除操作时自动降级为移入回收站（trash），杜绝物理毁灭性破坏
        $tododb->query($tododb->prepare("UPDATE {$prefix}comments SET comment_approved = 'trash' WHERE comment_ID = %d", $comment_id));
        return ['comment_id' => $comment_id, 'action' => 'trash', 'safe_mode' => true, 'message' => '评论已安全移至回收站（未物理抹除，后台可还原）'];
    }

    $update_fields = [];
    $update_args = [];

    if ($action === 'approve') {
        $update_fields[] = "comment_approved = '1'";
    } elseif ($action === 'reject') {
        $update_fields[] = "comment_approved = '0'";
    } elseif ($action === 'spam') {
        $update_fields[] = "comment_approved = 'spam'";
    } elseif ($action === 'trash') {
        $update_fields[] = "comment_approved = 'trash'";
    }

    if (isset($params['reply']) && trim((string)$params['reply']) !== '') {
        $reply_text = sanitize_text_field(trim((string)$params['reply']));
        $update_fields[] = "admin_reply = %s";
        $update_args[] = $reply_text;
        $update_fields[] = "replied_at = %s";
        $update_args[] = current_time('mysql');
    }

    if (!empty($update_fields)) {
        $sql = "UPDATE {$prefix}comments SET " . implode(', ', $update_fields) . " WHERE comment_ID = %d";
        $update_args[] = $comment_id;
        $tododb->query($tododb->prepare($sql, ...$update_args));
    }

    return [
        'comment_id' => $comment_id,
        'action' => $action,
        'message' => '评论审核/回复操作成功'
    ];
}

/**
 * 12. Agent 查询站点配置
 *
 * @param array $params
 * @return array
 */
function api_agent_get_settings($params) {
    $keys_input = isset($params['keys']) ? $params['keys'] : '';
    $requested_keys = [];
    if (is_array($keys_input)) {
        $requested_keys = array_filter(array_map('trim', $keys_input));
    } elseif (is_string($keys_input) && trim($keys_input) !== '') {
        $requested_keys = array_filter(array_map('trim', explode(',', $keys_input)));
    }

    if (empty($requested_keys)) {
        $requested_keys = [
            'site_name', 'site_description', 'site_keywords', 'site_copyright',
            'unified_notice', 'posts_per_page', 'default_category', 'timezone_string',
            'date_format', 'time_format', 'site_charset', 'comment_moderation',
            'cookie_consent_enabled', 'cookie_consent_post_id', 'cookie_consent_google_mode_v2',
            'cookie_consent_audit_log'
        ];
    }

    // 排除敏感系统机密
    $sensitive_blacklist = ['secret', 'token', 'password', 'api_key', 'salt', 'hash', 'private'];
    $result = [];

    foreach ($requested_keys as $k) {
        $is_sensitive = false;
        foreach ($sensitive_blacklist as $sb) {
            if (stripos($k, $sb) !== false) {
                $is_sensitive = true;
                break;
            }
        }
        if ($is_sensitive) continue;

        $val = function_exists('get_option') ? get_option($k, null) : null;
        $result[$k] = $val;
    }

    return $result;
}

/**
 * 13. Agent 更新站点配置（支持单项 key/value 或 settings 字典批量更新）
 *
 * @param array $params
 * @return array
 */
function api_agent_update_setting($params) {
    // 安全防线：校验系统配置管理权限 (system:manage 或 admin)
    if (class_exists('Todo_API')) {
        $api_instance = Todo_API::init();
        if (method_exists($api_instance, 'current_key_can') && !$api_instance->current_key_can('system:manage')) {
            send_api_error(403, '权限不足：当前使用的 API Key 未被授予系统配置修改权限 (system:manage)');
        }
    }

    $sensitive_blacklist = [
        'secret', 'token', 'password', 'api_key', 'api_keys', 'salt', 'hash', 'private', 
        'db_version', 'cms_version', 'admin_email', 'siteurl', 'home', 'active_plugins',
        'users_can_register', 'default_role', 'jwt_secret_key'
    ];

    $settings_to_update = [];
    if (!empty($params['settings']) && is_array($params['settings'])) {
        $settings_to_update = $params['settings'];
    } elseif (isset($params['key'])) {
        $k = trim((string)$params['key']);
        if ($k !== '') {
            $settings_to_update[$k] = isset($params['value']) ? $params['value'] : '';
        }
    }

    if (empty($settings_to_update)) {
        send_api_error(400, '缺少必要参数: key 与 value，或 settings 字典');
    }

    $updated_count = 0;
    $updated_keys = [];

    foreach ($settings_to_update as $key => $value) {
        $k = trim((string)$key);
        if ($k === '') continue;

        // 敏感防护拦截
        foreach ($sensitive_blacklist as $sb) {
            if (stripos($k, $sb) !== false) {
                send_api_error(403, '禁止通过 Agent 修改系统核心安全敏感配置项: ' . $k);
            }
        }

        if (function_exists('update_option')) {
            update_option($k, $value);
        } elseif (class_exists('CMS_Option')) {
            CMS_Option::update($k, $value);
        }

        $updated_keys[] = $k;
        $updated_count++;
    }

    return [
        'success' => true,
        'updated_count' => $updated_count,
        'updated_keys' => $updated_keys,
        'message' => '站点配置已成功更新: ' . implode(', ', $updated_keys)
    ];
}

/**
 * 13.1 Agent 全面扫描主题的 SEO 友好度
 *
 * @param array $params
 * @return array
 */
function api_agent_audit_theme_seo($params) {
    $theme = !empty($params['theme']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$params['theme']) : 'default';
    $theme_dir = ABSPATH . 'content/themes/' . $theme;
    $real_theme = realpath($theme_dir);
    if (!$real_theme || !is_dir($real_theme)) {
        send_api_error(404, "主题目录不存在: {$theme}");
    }

    $scan_dir = $real_theme . '/pc';
    if (!is_dir($scan_dir)) {
        $scan_dir = $real_theme;
    }

    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($scan_dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    $h1_issues = [];
    $img_issues = [];
    $total_images = 0;
    $images_missing_alt = 0;
    $images_missing_lazy = 0;

    foreach ($rii as $file) {
        if ($file->isFile() && in_array(strtolower($file->getExtension()), ['html', 'htm', 'php'], true)) {
            $pathname = $file->getPathname();
            if (strpos($pathname, '/backups') !== false || strpos($pathname, '/.backup') !== false) continue;

            $rel_path = str_replace($real_theme . '/', '', $pathname);
            $content = @file_get_contents($pathname);
            if ($content === false) continue;

            // 1. 检查 H1 标签
            preg_match_all('/<h1\b[^>]*>(.*?)<\/h1>/is', $content, $h1_matches);
            $h1_count = count($h1_matches[0]);
            if ($h1_count > 1) {
                $h1_issues[] = [
                    'file' => $rel_path,
                    'count' => $h1_count,
                    'details' => array_map('strip_tags', $h1_matches[0]),
                    'suggestion' => '页面存在多个 H1 标签，建议仅保留 1 个核心主标题，其余副标题降级为 H2 或 div'
                ];
            } elseif ($h1_count === 1 && $file->getFilename() === 'header.html') {
                $h1_issues[] = [
                    'file' => $rel_path,
                    'count' => 1,
                    'details' => ['header.html 固定渲染 <h1 class="site-title">'],
                    'suggestion' => 'header.html 在所有内页都被引入，导致内页出现双 H1 冲突。建议使用 TodoCMS 原生标签 {{todo:if_home}}<h1 class="site-title">...</h1>{else}<div class="site-title">...</div>{{/todo:if_home}} 实现首页输出 H1，内页输出 div'
                ];
            }

            // 2. 检查 img 标签
            preg_match_all('/<img\b([^>]*?)>/is', $content, $img_matches);
            if (!empty($img_matches[0])) {
                foreach ($img_matches[1] as $idx => $attrs) {
                    $total_images++;
                    $has_alt = (bool)preg_match('/\balt\s*=\s*(["\'])(.*?)\1/i', $attrs);
                    $has_lazy = (bool)preg_match('/\bloading\s*=\s*(["\'])lazy\1/i', $attrs);

                    if (!$has_alt || !$has_lazy) {
                        if (!$has_alt) $images_missing_alt++;
                        if (!$has_lazy) $images_missing_lazy++;

                        $img_issues[] = [
                            'file' => $rel_path,
                            'img_tag' => htmlspecialchars(substr($img_matches[0][$idx], 0, 100)),
                            'missing_alt' => !$has_alt,
                            'missing_lazy' => !$has_lazy
                        ];
                    }
                }
            }
        }
    }

    return [
        'theme' => $theme,
        'summary' => [
            'h1_conflict_files_count' => count($h1_issues),
            'total_images_scanned' => $total_images,
            'images_missing_alt' => $images_missing_alt,
            'images_missing_lazy' => $images_missing_lazy
        ],
        'h1_issues' => $h1_issues,
        'sample_image_issues' => array_slice($img_issues, 0, 15),
        'recommendation' => '建议使用 TodoCMS 真实语法 {{todo:if_home}}...{else}...{{/todo:if_home}} 治理双 H1 问题，并为缺少 lazy 的图片注入 loading="lazy"'
    ];
}

/**
 * 13.2 Agent 查询 TodoCMS 官方原生模板标签库手册（基于 template-tags-overrides.php）
 * 杜绝凭空臆造或混淆其他 CMS 语法，所有模板操作严格遵从 TodoCMS 官方标签定义
 *
 * @param array $params
 * @return array
 */
function api_agent_query_template_tag($params) {
    $manual_file = ABSPATH . 'admin/pages/theme/template-tags-overrides.php';
    if (!file_exists($manual_file)) {
        send_api_error(500, '模板标签手册数据源文件不存在');
    }

    $all_tags = include $manual_file;
    if (!is_array($all_tags)) {
        send_api_error(500, '加载模板标签手册失败');
    }

    $tag_name = isset($params['tag']) ? trim(strtolower((string)$params['tag'])) : '';
    $keyword = isset($params['keyword']) ? trim(strtolower((string)$params['keyword'])) : '';
    $group = isset($params['group']) ? trim((string)$params['group']) : '';

    // 1. 精确匹配标签名
    if ($tag_name !== '' && isset($all_tags[$tag_name])) {
        $tag_data = $all_tags[$tag_name];
        $tag_data['tag_name'] = $tag_name;
        return [
            'matched' => 'exact',
            'tag' => $tag_data
        ];
    }

    // 2. 关键词模糊检索
    $results = [];
    $max_results = 20;

    foreach ($all_tags as $k => $info) {
        $k_lower = strtolower($k);
        $title_lower = strtolower($info['title'] ?? '');
        $intro_lower = strtolower($info['intro'] ?? '');
        $tag_keywords = strtolower($info['tag'] ?? '');
        $grp = $info['group'] ?? '';

        if ($group !== '' && stripos($grp, $group) === false) {
            continue;
        }

        $match = false;
        if ($tag_name !== '' && strpos($k_lower, $tag_name) !== false) {
            $match = true;
        } elseif ($keyword !== '') {
            if (strpos($k_lower, $keyword) !== false || strpos($title_lower, $keyword) !== false || strpos($intro_lower, $keyword) !== false || strpos($tag_keywords, $keyword) !== false) {
                $match = true;
            }
        } elseif ($tag_name === '' && $keyword === '' && $group !== '') {
            $match = true;
        }

        if ($match) {
            $results[] = [
                'tag_name' => $k,
                'title' => $info['title'] ?? '',
                'group' => $grp,
                'intro' => $info['intro'] ?? '',
                'usage' => $info['usage'] ?? [],
                'examples' => array_slice($info['examples'] ?? [], 0, 2),
                'notes' => $info['notes'] ?? []
            ];

            if (count($results) >= $max_results) {
                break;
            }
        }
    }

    return [
        'total_matched' => count($results),
        'results' => $results,
        'help' => '可传 tag 参数精确获取某标签的全部参数与示例，如 tag="if_home" 或 tag="anywhere_post"'
    ];
}

/**
 * 14. Agent 列出指定主题下的模板文件树
 *
 * @param array $params
 * @return array
 */
function api_agent_list_theme_files($params) {
    $theme = !empty($params['theme']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$params['theme']) : 'default';
    $device = !empty($params['device']) ? strtolower(trim((string)$params['device'])) : 'all';

    $theme_dir = ABSPATH . 'content/themes/' . $theme;
    $real_theme = realpath($theme_dir);
    if (!$real_theme || !is_dir($real_theme)) {
        send_api_error(404, "指定的主题目录不存在: {$theme}");
    }

    $scan_paths = [$real_theme];
    if ($device === 'pc' && is_dir($real_theme . '/pc')) {
        $scan_paths = [$real_theme . '/pc'];
    } elseif ($device === 'mobile' && is_dir($real_theme . '/mobile')) {
        $scan_paths = [$real_theme . '/mobile'];
    }

    $files_list = [];
    $allowed_exts = ['html', 'htm', 'php', 'json', 'css', 'js'];

    foreach ($scan_paths as $sp) {
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sp, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($rii as $file) {
            if ($file->isFile()) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, $allowed_exts, true)) {
                    $pathname = $file->getPathname();
                    // 排除隐藏文件或自动备份目录
                    if (strpos($pathname, '/.backup') !== false || strpos($pathname, '/backups') !== false || strpos($pathname, '/.git') !== false) {
                        continue;
                    }
                    $rel_path = str_replace($real_theme . '/', '', $pathname);
                    $files_list[] = [
                        'file_path' => $rel_path,
                        'name' => $file->getFilename(),
                        'size' => $file->getSize(),
                        'last_modified' => date('Y-m-d H:i:s', $file->getMTime())
                    ];
                }
            }
        }
    }

    usort($files_list, function($a, $b) {
        return strcmp($a['file_path'], $b['file_path']);
    });

    return [
        'theme' => $theme,
        'device_filter' => $device,
        'total_files' => count($files_list),
        'files' => $files_list
    ];
}

/**
 * 15. Agent 读取指定主题模板内容
 *
 * @param array $params
 * @return array
 */
function api_agent_get_theme_file($params) {
    $theme = !empty($params['theme']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$params['theme']) : 'default';
    $file_path = isset($params['file_path']) ? trim((string)$params['file_path']) : '';
    if ($file_path === '') {
        send_api_error(400, '缺少必要参数: file_path (例如 pc/lists_article.html)');
    }

    // 防穿越检测
    if (strpos($file_path, '..') !== false || strpos($file_path, ':') !== false) {
        send_api_error(403, '非法的模板路径参数');
    }

    $theme_dir = ABSPATH . 'content/themes/' . $theme;
    $target_file = $theme_dir . '/' . ltrim($file_path, '/');
    $real_target = realpath($target_file);
    $real_theme = realpath($theme_dir);

    if (!$real_target || !$real_theme || strpos($real_target, $real_theme) !== 0 || !is_file($real_target)) {
        send_api_error(404, "找不到指定的模板文件: {$file_path}");
    }

    $content = @file_get_contents($real_target);
    if ($content === false) {
        send_api_error(500, "读取模板文件失败: {$file_path}");
    }

    return [
        'theme' => $theme,
        'file_path' => $file_path,
        'size' => filesize($real_target),
        'last_modified' => date('Y-m-d H:i:s', filemtime($real_target)),
        'content' => $content
    ];
}

/**
 * 16. Agent 优化并写回主题模板文件（带自动安全备份）
 *
 * @param array $params
 * @return array
 */
function api_agent_update_theme_file($params) {
    // 第三道防线：校验 API Key 是否具备主题模板修改权限 (theme:manage 或 admin)
    if (class_exists('Todo_API')) {
        $api_instance = Todo_API::init();
        if (method_exists($api_instance, 'current_key_can') && !$api_instance->current_key_can('theme:manage')) {
            send_api_error(403, '权限不足：当前使用的 API Key 未被授予主题代码修改权限 (theme:manage)');
        }
    }

    // 第三道防线：系统级安全熔断开关。默认禁止 Agent 在线修改任何模板，除非站长在 config.php 中显式放行
    if (!defined('TODO_AGENT_ALLOW_THEME_EDIT') || !TODO_AGENT_ALLOW_THEME_EDIT) {
        send_api_error(403, '安全熔断拦截：主题文件在线修改已被系统安全策略全局锁定。如确需 AI 优化主题代码，请在 config.php 中配置 define(\'TODO_AGENT_ALLOW_THEME_EDIT\', true);');
    }

    $theme = !empty($params['theme']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$params['theme']) : 'default';
    $file_path = isset($params['file_path']) ? trim((string)$params['file_path']) : '';
    if ($file_path === '') {
        send_api_error(400, '缺少必要参数: file_path');
    }
    if (!isset($params['content'])) {
        send_api_error(400, '缺少必要参数: content (优化后的模板代码)');
    }

    if (strpos($file_path, '..') !== false || strpos($file_path, ':') !== false) {
        send_api_error(403, '非法的模板路径参数');
    }

    $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    // 坚决将 php 移出允许修改列表！严禁 Agent 在线生成或修改可执行 PHP 脚本，杜绝 WebShell 与 RCE 风险
    $allowed_exts = ['html', 'htm', 'json', 'css', 'js'];
    if (!in_array($ext, $allowed_exts, true)) {
        send_api_error(403, '安全限制：仅允许修改前端模板格式 (html, htm, css, js, json)，严禁在线写入 PHP 等服务端脚本代码');
    }

    $theme_dir = ABSPATH . 'content/themes/' . $theme;
    $real_theme = realpath($theme_dir);
    if (!$real_theme || !is_dir($real_theme)) {
        send_api_error(404, "主题目录不存在: {$theme}");
    }

    $target_file = $theme_dir . '/' . ltrim($file_path, '/');
    $parent_dir = dirname($target_file);
    if (!is_dir($parent_dir)) {
        @mkdir($parent_dir, 0755, true);
    }

    $new_content = (string)$params['content'];
    $backup_created = null;
    $should_backup = !isset($params['backup']) || !empty($params['backup']);

    // 自动备份机制
    if ($should_backup && file_exists($target_file)) {
        $backup_dir = $real_theme . '/backups/' . date('Ymd');
        if (!is_dir($backup_dir)) {
            @mkdir($backup_dir, 0755, true);
        }
        $backup_filename = date('His') . '_' . str_replace('/', '_', ltrim($file_path, '/'));
        $backup_full = $backup_dir . '/' . $backup_filename;
        if (@copy($target_file, $backup_full)) {
            $backup_created = str_replace(ABSPATH, '', $backup_full);
        }
    }

    // 原子写入
    $tmp_file = $target_file . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp_file, $new_content) === false) {
        send_api_error(500, "无法写入临时模板文件，请检查目录权限: {$parent_dir}");
    }

    if (!@rename($tmp_file, $target_file)) {
        @unlink($tmp_file);
        send_api_error(500, "保存模板文件失败: {$target_file}");
    }

    // 清理模板解析/页面编译缓存
    if (class_exists('Todo_Cache_Manager') && is_callable(['Todo_Cache_Manager', 'flush_all'])) {
        Todo_Cache_Manager::flush_all();
    }

    return [
        'success' => true,
        'theme' => $theme,
        'file_path' => $file_path,
        'size' => strlen($new_content),
        'backup_path' => $backup_created,
        'message' => "主题模板 [{$file_path}] 已成功优化保存并生成原文件备份"
    ];
}

// 注册路由
register_api_route('agent/context', 'GET', 'api_agent_get_context');
register_api_route('agent/posts/publish', 'POST', 'api_agent_publish_post');
register_api_route('agent/products/update-stock', 'POST', 'api_agent_update_product_stock');
register_api_route('agent/products/create', 'POST', 'api_agent_create_product');
register_api_route('agent/i18n/scan', 'GET', 'api_agent_scan_i18n');
register_api_route('agent/i18n/update', 'POST', 'api_agent_update_i18n');
register_api_route('agent/system/flush-cache', 'POST', 'api_agent_flush_cache');
register_api_route('agent/media/upload', 'POST', 'api_agent_upload_media');
register_api_route('agent/comments', 'GET', 'api_agent_list_comments');
register_api_route('agent/comments/audit', 'POST', 'api_agent_audit_comment');
register_api_route('agent/settings', 'GET', 'api_agent_get_settings');
register_api_route('agent/settings', 'POST', 'api_agent_update_setting');
register_api_route('agent/theme/files', 'GET', 'api_agent_list_theme_files');
register_api_route('agent/theme/file', 'GET', 'api_agent_get_theme_file');
register_api_route('agent/theme/file', 'POST', 'api_agent_update_theme_file');
register_api_route('agent/theme/audit-seo', 'GET', 'api_agent_audit_theme_seo');
register_api_route('agent/theme/tags', 'GET', 'api_agent_query_template_tag');
register_api_route('agent/openapi\.json', 'GET', 'api_agent_get_openapi');

