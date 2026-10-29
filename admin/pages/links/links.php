<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

/**
 * 友情链接管理页面
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') || !defined('TODO_ADMIN') || !defined('ADMIN_ACCESS')) {
    http_response_code(403);
    die('Direct access not permitted');
}

// 检查权限
if (!current_user_can('manage_ads')) {
    todo_die('您没有管理友情链接的权限。');
}

// 处理操作
$action = isset($_POST['action']) ? (string) $_POST['action'] : (isset($_GET['action']) ? (string) $_GET['action'] : '');

// 处理删除链接
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action === 'delete') {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('delete_link', '_todononce')) {
        todo_admin_notice('安全验证失败，请重试。', 'error');
    } else {
        $link_id = intval($_POST['id'] ?? 0);
        if ($link_id > 0) {
            global $tododb;
            $result = $tododb->delete(TABLE_PREFIX . 'links', array('id' => $link_id));
            if ($result !== false) {
                todo_add_admin_notice('友情链接删除成功。', 'success');
            } else {
                todo_add_admin_notice('删除友情链接失败。', 'error');
            }
        }
    }
    todo_redirect(todo_admin_url('index.php?page=links'));
    exit;
}

// 处理状态切换
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action === 'toggle_status') {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('toggle_link_status', '_todononce')) {
        todo_admin_notice('安全验证失败，请重试。', 'error');
    } else {
        $link_id = intval($_POST['id'] ?? 0);
        if ($link_id > 0) {
            global $tododb;
            $link = $tododb->get_row("SELECT * FROM " . TABLE_PREFIX . "links WHERE id = ?", [$link_id]);
            if ($link) {
                $new_status = $link->status == 1 ? 0 : 1;
                $result = $tododb->update(
                    TABLE_PREFIX . 'links',
                    array('status' => $new_status),
                    array('id' => $link_id)
                );
                if ($result !== false) {
                    todo_add_admin_notice('友情链接状态更新成功。', 'success');
                } else {
                    todo_add_admin_notice('更新友情链接状态失败。', 'error');
                }
            }
        }
    }
    todo_redirect(todo_admin_url('index.php?page=links'));
    exit;
}

// 获取友情链接列表
global $tododb;
$page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// 构建查询条件（参数化）
$where_clauses = array();
$params = array();
$search = isset($_GET['s']) ? trim($_GET['s']) : '';
$category_filter = isset($_GET['category']) ? trim($_GET['category']) : '';

if (!empty($search)) {
    $where_clauses[] = "(name LIKE ? OR url LIKE ? OR description LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if (!empty($category_filter)) {
    $where_clauses[] = "category = ?";
    $params[] = $category_filter;
}

$where_clause = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// 获取总数
$total_query = "SELECT COUNT(*) FROM " . TABLE_PREFIX . "links $where_clause";
$total_items = intval($tododb->get_var($total_query, $params));

// 获取链接列表
$links_query = "SELECT * FROM " . TABLE_PREFIX . "links $where_clause ORDER BY sort_order ASC, id DESC LIMIT $per_page OFFSET $offset";
$links = $tododb->get_results($links_query, $params);

// 获取所有分类
$categories_query = "SELECT DISTINCT category FROM " . TABLE_PREFIX . "links WHERE category != '' ORDER BY category";
$categories = $tododb->get_results($categories_query);

// 计算分页
$total_pages = ceil($total_items / $per_page);

?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title verse">“相知无远近，万里尚为邻。”<span>——张九龄《送韦城李少府》</span></h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=link-new&from=links'); ?>" class="button button-primary">添加新链接</a>
        </div>
    </div>

    <?php // 消息提示由全局 admin_notices 钩子处理 ?>

    <div class="admin-card">
        <div class="admin-card-header">
            <h3 class="admin-card-title">友情链接列表</h3>
            <div class="admin-card-actions">
                <form method="get" class="search-form todo-request">
                    <input type="hidden" name="page" value="links">
                    <?php if ($category_filter): ?>
                        <input type="hidden" name="category" value="<?php echo esc_attr($category_filter); ?>">
                    <?php endif; ?>
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="搜索链接...">
                    <button type="submit" class="button button-secondary">搜索</button>
                </form>
            </div>
        </div>

        <div class="admin-card-content">
            <div class="admin-filter-bar">
                <ul class="admin-filters">
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=links'); ?>" class="<?php echo empty($category_filter) ? 'current' : ''; ?>">
                            所有分类
                        </a>
                    </li>
                    <?php foreach ($categories as $cat): ?>
                        <li>
                            <a href="<?php echo todo_admin_url('index.php?page=links&category=' . urlencode($cat->category)); ?>"
                                class="<?php echo $category_filter === $cat->category ? 'current' : ''; ?>">
                                <?php echo esc_html($cat->category); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="bulk-actions-bar">
                <div class="bulk-actions">
                    <select name="bulk_action">
                        <option value="">批量操作</option>
                        <option value="delete">删除</option>
                    </select>
                    <button type="submit" class="button button-secondary">应用</button>
               
                    <form method="get" class="filter-form todo-request">
                        <input type="hidden" name="page" value="links">
                        <?php if ($search): ?>
                            <input type="hidden" name="s" value="<?php echo esc_attr($search); ?>">
                        <?php endif; ?>
                        <select name="category" style="margin-right: 5px;">
                            <option value="">所有分类</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo esc_attr($cat->category); ?>" <?php selected($category_filter, $cat->category); ?>>
                                    <?php echo esc_html($cat->category); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button button-secondary">筛选</button>
                    </form>
                </div>
            </div>

            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="check-column">
                            <input type="checkbox" id="cb-select-all">
                        </th>
                        <th style="min-width: 180px;">名称</th>
                        <th style="min-width: 220px;">链接地址</th>
                        <th>分类</th>
                        <th>打开方式</th>
                        <th>排序</th>
                        <th style="min-width: 60px;">状态</th>
                        <th style="min-width: 140px; width: 140px;">创建时间</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($links)): ?>
                        <tr class="empty">
                            <td colspan="8">
                                <div class="no-items">
                                    <p>没有找到友情链接。</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($links as $link): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="link[]" value="<?php echo $link->id; ?>">
                                </td>
                                <td>
                                    <strong>
                                        <a href="<?php echo todo_admin_url('index.php?page=link-edit&from=links&id=' . $link->id); ?>">
                                            <?php echo esc_html(function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($link->name) : $link->name); ?>
                                        </a>
                                    </strong>
                                    <div class="row-actions">
                                        <span class="edit">
                                            <a href="<?php echo todo_admin_url('index.php?page=link-edit&from=links&id=' . $link->id); ?>">编辑</a> |
                                        </span>
                                        <span class="view">
                                            <a href="<?php echo esc_url($link->url); ?>"
                                                target="<?php echo esc_attr($link->target); ?>">访问</a> |
                                        </span>
                                        <span class="toggle">
                                            <a
                                                href="<?php echo todo_admin_url('index.php?page=links&action=toggle_status&id=' . $link->id . '&_todononce=' . Todo_Security_Manager::generate_csrf_token('toggle_link_status')); ?>" class="js-todo-post-action">
                                                <?php echo $link->status == 1 ? '禁用' : '启用'; ?>
                                            </a> |
                                        </span>
                                        <span class="delete">
                                            <a href="<?php echo todo_admin_url('index.php?page=links&action=delete&id=' . $link->id . '&_todononce=' . Todo_Security_Manager::generate_csrf_token('delete_link')); ?>"
                                                class="submitdelete js-todo-post-action" data-confirm="确定要删除这个友情链接吗？">删除</a>
                                        </span>
                                    </div>
                                </td>
                                <td class="column-url">
                                    <a href="<?php echo esc_url($link->url); ?>"
                                        target="<?php echo esc_attr($link->target); ?>">
                                        <?php echo esc_html(todo_trim_words($link->url, 50)); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php echo esc_html($link->category); ?>
                                </td>
                                <td>
                                    <?php echo $link->target === '_blank' ? '新窗口' : '当前窗口'; ?>
                                </td>
                                <td>
                                    <?php echo intval($link->sort_order); ?>
                                </td>
                                <td>
                                    <?php if ($link->status == 1): ?>
                                        <span class="status-active">启用</span>
                                    <?php else: ?>
                                        <span class="status-inactive">禁用</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="tb-meta"><?php echo date('Y-m-d H:i', strtotime($link->created_at)); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
                <?php
                $pagination_add_args = array();
                if (!empty($search)) {
                    $pagination_add_args['s'] = $search;
                }
                if (!empty($category_filter)) {
                    $pagination_add_args['category'] = $category_filter;
                }

                echo todo_render_pagination(array(
                    'total_items' => $total_items,
                    'per_page' => $per_page,
                    'current_page' => $page,
                    'base_url' => todo_admin_url('index.php?page=links'),
                    'add_args' => $pagination_add_args,
                    'show_count' => true,
                ));
                ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    .column-url {
        max-width: 300px;
        word-break: break-all;
    }
</style>

<script type="text/javascript">
    jQuery(function ($) {
        // 全选功能
        $('#cb-select-all').on('change', function () {
            var checked = $(this).prop('checked');
            $('input[name="link[]"]').prop('checked', checked);
            $('#cb-select-all').prop('checked', checked);
        });

        $('input[name="link[]"]').on('change', function () {
            var total = $('input[name="link[]"]').length;
            var checked = $('input[name="link[]"]:checked').length;
            $('#cb-select-all').prop('checked', total === checked);
        });
    });
</script>
