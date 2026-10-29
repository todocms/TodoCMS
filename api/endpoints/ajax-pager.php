<?php
/**
 * Ajax 分页 API 端点
 *
 * 处理非主分页的 Ajax 请求，返回渲染后的 HTML 片段。
 * URL: GET /api/ajax-pager?pager=<name>&page=<N>
 *
 * 模板文件命名约定：
 *   默认主题目录下 ajax-pager-{name}.html（如 ajax-pager-article.html）
 *   如果需要自定义映射，在 $template_map 中显式指定。
 *
 * @package TodoCMS
 * @version 1.1.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

// 注册 API 路由
register_api_route('ajax-pager', 'GET', function ($params) {
    $pager = isset($params['pager']) ? sanitize_key($params['pager']) : '';
    $page  = isset($params['api_page']) ? max(1, intval($params['api_page'])) : (isset($params['page']) && $params['page'] !== 'api' ? max(1, intval($params['page'])) : 1);
    $current_url = isset($params['current_url']) && !is_array($params['current_url']) ? filter_var((string) $params['current_url'], FILTER_SANITIZE_URL) : '';

    if ($pager === '' || $pager === 'main') {
        send_api_error(400, '分页名称无效');
        return;
    }

    // 显式模板映射（优先级高于命名约定）
    $template_map = [];

    // 按约定查找：ajax-pager-{name}.html
    $theme_dir = get_template_directory();

    if (isset($template_map[$pager])) {
        $partial_file = $template_map[$pager];
    } else {
        $partial_file = 'ajax-pager-' . $pager . '.html';
    }

    $template_path = $theme_dir . '/' . $partial_file;

    if (!file_exists($template_path)) {
        send_api_error(
            404,
            '分页模板文件不存在。请在主题目录创建 ' . $partial_file . ' 文件。' .
            "\n示例：包含 {{todo:loop posts|...|pager=" . $pager . "}} ... {{/todo:loop}} 和上/下分页导航"
        );
        return;
    }

    $query_context = array(
        'paged_' . $pager => $page,
        'paged_overrides' => array(
            'paged_' . $pager => $page
        )
    );
    if ($current_url !== '') {
        $query_context['current_url'] = $current_url;
        $current_uri_path = (string) parse_url($current_url, PHP_URL_PATH);
        $current_uri_query = (string) parse_url($current_url, PHP_URL_QUERY);
        $query_context['request_uri'] = $current_uri_path . ($current_uri_query !== '' ? '?' . $current_uri_query : '');
    }

    // 确保模板引擎已初始化且依赖已加载
    if (!class_exists('CMS_Template_Compiler_V2')) {
        send_api_error(500, '模板引擎未加载');
        return;
    }
    if (!CMS_Template_Compiler_V2::init()) {
        send_api_error(500, '模板引擎初始化失败');
        return;
    }

    try {
        // 标记为 Ajax 请求，抑制模板引擎中的诊断注释输出
        if (!defined('DOING_AJAX')) {
            define('DOING_AJAX', true);
        }
        if (function_exists('todo_set_query_context_overrides')) {
            todo_set_query_context_overrides($query_context);
        }

        // 渲染模板部分文件，返回 HTML 片段
        $html = CMS_Template_Compiler_V2::render_template($template_path, []);

        return ['html' => $html];
    } catch (Exception $e) {
        error_log('[Ajax Pager] 渲染失败: ' . $e->getMessage());
        send_api_error(500, '渲染失败: ' . $e->getMessage());
    } finally {
        if (function_exists('todo_clear_query_context_overrides')) {
            todo_clear_query_context_overrides();
        }
    }
});
