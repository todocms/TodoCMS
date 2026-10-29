<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

/**
 * 独立标签管理页面
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 确保session已启动
if (!session_id()) {
    session_start();
}

// 引入媒体模态框组件
require_once ABSPATH . 'admin/components/media-modal.php';
// 初始化标签管理器
$tags_manager = new CMS_Tags_Manager();

// 处理添加标签
if (isset($_POST['add_tag'])) {
    // 验证CSRF token - 使用统一的验证方法
    if (!Todo_Security_Manager::verify_request_csrf('add_tag')) {
        todo_die('安全验证失败，请刷新页面后重试。');
    }

    $tag_data = [
        'tag_name' => trim($_POST['tag_name'] ?? ''),
        'tag_slug' => trim($_POST['tag_slug'] ?? ''),
        'tag_description' => trim($_POST['tag_description'] ?? ''),
        'tag_color' => trim($_POST['tag_color'] ?? ''),
        'tag_icon' => trim($_POST['tag_icon'] ?? ''),
        'tag_media_directory' => trim($_POST['tag_media_directory'] ?? ''),
        'tag_order' => intval($_POST['tag_order'] ?? 0),
        'auto_inlink' => intval($_POST['auto_inlink'] ?? 0),
        'auto_image' => intval($_POST['auto_image'] ?? 0)
    ];

    if (empty($tag_data['tag_name'])) {
        $error_message = '标签名称不能为空！';
    } else {
        $tag_id = $tags_manager->create_tag(
            $tag_data['tag_name'],
            $tag_data['tag_description'],
            $tag_data['tag_color'],
            $tag_data['tag_icon'],
            $tag_data['tag_order'],
            $tag_data['tag_media_directory'],
            $tag_data['auto_inlink'],
            $tag_data['auto_image']
        );
        if ($tag_id) {
            if (function_exists('todo_add_admin_notice')) {
                todo_add_admin_notice('标签已成功添加！', 'success');
            }
            todo_redirect(todo_admin_url('index.php?page=tags'));
            exit;
        } else {
            $error_message = '创建标签失败，标签名称可能已存在！';
        }
    }
}

// 处理编辑标签
if (isset($_POST['update_tag'])) {
    // 验证CSRF token - 使用统一的验证方法
    if (!Todo_Security_Manager::verify_request_csrf('update_tag')) {
        todo_die('安全验证失败，请刷新页面后重试。');
    }

    $tag_id = intval($_POST['tag_id'] ?? 0);
    $tag_data = [
        'tag_name' => trim($_POST['tag_name'] ?? ''),
        'tag_slug' => trim($_POST['tag_slug'] ?? ''),
        'tag_description' => trim($_POST['tag_description'] ?? ''),
        'tag_color' => trim($_POST['tag_color'] ?? ''),
        'tag_icon' => trim($_POST['tag_icon'] ?? ''),
        'tag_media_directory' => trim($_POST['tag_media_directory'] ?? ''),
        'tag_order' => intval($_POST['tag_order'] ?? 0),
        'tag_status' => $_POST['tag_status'] ?? 'active',
        'auto_inlink' => intval($_POST['auto_inlink'] ?? 0),
        'auto_image' => intval($_POST['auto_image'] ?? 0)
    ];

    if (empty($tag_data['tag_name'])) {
        $error_message = '标签名称不能为空！';
    } else {
        if ($tags_manager->update_tag($tag_id, $tag_data)) {
            if (function_exists('todo_add_admin_notice')) {
                todo_add_admin_notice('标签已成功更新！', 'success');
            }
            todo_redirect(todo_admin_url('index.php?page=tags'));
            exit;
        } else {
            $error_message = '更新标签失败！';
        }
    }
}

// 处理删除标签
if (isset($_POST['delete_tag']) && isset($_POST['tag_id'])) {
    // 验证CSRF token - 使用与批量删除相同的action以共用token
    if (!Todo_Security_Manager::verify_request_csrf('bulk_delete_tags')) {
        todo_die('安全验证失败，请刷新页面后重试。');
    }

    $tag_id = intval($_POST['tag_id']);

    if ($tags_manager->delete_tag($tag_id)) {
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice('标签已成功删除！', 'success');
        }
        todo_redirect(todo_admin_url('index.php?page=tags'));
        exit;
    } else {
        $error_message = '删除标签失败！';
    }
}

// 处理批量操作
if (isset($_POST['bulk_action']) && isset($_POST['tag_ids'])) {
    // 验证CSRF token - 使用统一的验证方法
    if (!Todo_Security_Manager::verify_request_csrf('bulk_delete_tags')) {
        todo_die('安全验证失败，请刷新页面后重试。');
    }

    $bulk_action = $_POST['bulk_action'];
    $tag_ids = $_POST['tag_ids'];

    if (!empty($tag_ids) && $bulk_action == 'delete') {
        $success_count = 0;
        $error_count = 0;

        foreach ($tag_ids as $tag_id) {
            $tag_id = intval($tag_id);
            if ($tags_manager->delete_tag($tag_id)) {
                $success_count++;
            } else {
                $error_count++;
            }
        }

        if ($success_count > 0) {
            if (function_exists('todo_add_admin_notice')) {
                $msg = "已成功删除 {$success_count} 个标签！" . ($error_count > 0 ? " {$error_count} 个标签删除失败。" : "");
                todo_add_admin_notice($msg, $error_count > 0 ? 'warning' : 'success');
            }
            todo_redirect(todo_admin_url('index.php?page=tags'));
            exit;
        } else {
            $error_message = '没有标签被删除！';
        }
    } else {
        error_log('批量操作条件不满足: tag_ids empty=' . (empty($tag_ids) ? 'true' : 'false') . ', bulk_action=' . $bulk_action);
        if (empty($tag_ids)) {
            $error_message = '请选择要删除的标签。';
        } elseif ($bulk_action != 'delete') {
            $error_message = '无效的批量操作。';
        }
    }
}

// 获取要编辑的标签
$edit_tag = null;
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['tag_id'])) {
    $tag_id = intval($_GET['tag_id']);
    $edit_tag = $tags_manager->get_tag($tag_id);
    // 映射数据库字段到表单字段
    if ($edit_tag) {
        $edit_tag['tag_name'] = $edit_tag['tag_title'];
        $edit_tag['tag_description'] = $edit_tag['tag_desc'];
    }
}

// 获取筛选参数
$search = trim($_GET['search'] ?? '');
$category_id = intval($_GET['category_id'] ?? 0);
$model_id = intval($_GET['model_id'] ?? 0);
$status = $_GET['status'] ?? 'active';

// 分页参数
$per_page = 20;
$current_page = max(1, intval($_GET['paged'] ?? 1));
$offset = ($current_page - 1) * $per_page;

// 排序参数
$orderby = trim((string) ($_GET['orderby'] ?? 'post_count'));
$order = strtolower(trim((string) ($_GET['order'] ?? 'desc'))) === 'desc' ? 'DESC' : 'ASC';
$allowed_orderby = array('tag_order', 'tag_title', 'post_count', 'created_at');
if (!in_array($orderby, $allowed_orderby, true)) {
    $orderby = 'post_count';
}

// 获取标签列表
$args = [
    'search' => $search,
    'category_id' => $category_id,
    'model_id' => $model_id,
    'status' => $status,
    'limit' => $per_page,
    'offset' => $offset,
    'orderby' => $orderby,
    'order' => $order
];

$tags = $tags_manager->get_tags($args);
$total_tags = $tags_manager->get_tags_count($args);
$total_pages = ceil($total_tags / $per_page);

// 获取栏目列表（用于筛选，包含层级关系）
global $tododb;
$categories_query = "SELECT t.term_id as category_id, t.name as category_name, tt.parent, 
                           m.model_name
                    FROM {$tododb->prefix}terms AS t
                    INNER JOIN {$tododb->prefix}term_taxonomy AS tt ON t.term_id = tt.term_id
                    LEFT JOIN {$tododb->prefix}term_models AS tm ON t.term_id = tm.term_id
                    LEFT JOIN {$tododb->prefix}models AS m ON tm.model_id = m.model_id
                    WHERE tt.taxonomy = 'category'
                      AND NOT EXISTS (
                          SELECT 1 FROM {$tododb->prefix}term_attributes
                          WHERE term_id = t.term_id AND JSON_UNQUOTE(JSON_EXTRACT(attributes, '$.trashed')) = '1'
                      )
                    ORDER BY t.term_id ASC";
$all_categories = $tododb->query($categories_query) ?: [];

$categories = [];
if (!empty($all_categories)) {
    $by_parent = [];
    foreach ($all_categories as $c) {
        $pid = isset($c['parent']) ? (int) $c['parent'] : 0;
        if (!isset($by_parent[$pid])) {
            $by_parent[$pid] = [];
        }
        $by_parent[$pid][] = $c;
    }

    $walker = null;
    $walker = function ($parent_id, $level) use (&$walker, &$by_parent, &$categories) {
        if (!isset($by_parent[$parent_id])) {
            return;
        }
        foreach ($by_parent[$parent_id] as $c) {
            $cat_item = $c;
            $cat_item['level'] = $level;
            $categories[] = $cat_item;
            $walker((int) $c['category_id'], $level + 1);
        }
    };
    $walker(0, 0);
}

// 获取数据模型列表（用于筛选）
$models_query = "SELECT * FROM {$tododb->prefix}models WHERE model_status = 'active' ORDER BY model_name";
$models = $tododb->query($models_query) ?: [];
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title verse">“山重水复疑无路，柳暗花明又一村。”<span>——陆游《游山西村》</span></h2>
        <div class="admin-page-actions">
            <a href="index.php?page=search-logs" class="button">搜索词</a>
        </div>
    </div>

    <?php if (isset($error_message)): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html($error_message); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <div class="admin-row">
        <!-- 左侧：添加/编辑表单 -->
        <div class="admin-col-l" style="width: 260px;">
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">
                        <?php echo $edit_tag ? '编辑标签' : '添加新标签'; ?>
                    </h3>
                </div>
                <div class="admin-card-content">
                    <?php if ($edit_tag): ?>
                        <!-- 编辑标签表单 -->
                        <form method="post" id="edit-tag-form" class="todo-request" action="<?php echo todo_admin_url('index.php?page=tags&action=edit&tag_id=' . $edit_tag['tag_id']); ?>">
                            <input type="hidden" name="tag_id" value="<?php echo $edit_tag['tag_id']; ?>">
                            <?php echo Todo_Security_Manager::csrf_field('update_tag'); ?>

                            <div class="form-group">
                                <label for="tag_name">标签名称 <span class="required">*</span></label>
                                <input type="text" id="tag_name" name="tag_name"
                                    value="<?php echo htmlspecialchars($edit_tag['tag_name']); ?>" class="regular-text"
                                    required>
                            </div>

                            <div class="form-group">
                                <label for="tag_slug">标签别名</label>
                                <input type="text" id="tag_slug" name="tag_slug"
                                    value="<?php echo htmlspecialchars($edit_tag['tag_slug']); ?>" class="regular-text">
                            </div>

                            <div class="form-group">
                                <label for="tag_description">标签描述</label>
                                <textarea id="tag_description" name="tag_description" rows="3"
                                    class="large-text"><?php echo htmlspecialchars($edit_tag['tag_description']); ?></textarea>
                            </div>

                            <div class="form-group">
                                <label for="tag_color">标签颜色</label>
                                <input type="color" id="tag_color" name="tag_color"
                                    value="<?php echo htmlspecialchars($edit_tag['tag_color']); ?>"
                                    style="width: 60px; height: 35px; min-height: auto; padding: 0; border: none;">
                            </div>

                            <div class="form-group">
                                <label for="tag_icon">标签图标</label>
                                <input type="text" id="tag_icon" name="tag_icon"
                                    value="<?php echo htmlspecialchars($edit_tag['tag_icon']); ?>" placeholder="如：fa-tag"
                                    class="regular-text">
                                <p class="description">图标class。</p>
                            </div>

                            <div class="form-group">
                                <label for="tag_media_directory">自动图片</label>
                                <div class="flex-col items-center gap-2">
                                    <input type="text" id="tag_media_directory" name="tag_media_directory"
                                        value="<?php echo htmlspecialchars($edit_tag['tag_media_directory'] ?? ''); ?>"
                                        style="width: 100%; margin-bottom: 5px;" placeholder="图片目录">
                                    <div class="flex items-center gap-2 justify-between">
                                        <button type="button" class="button button-secondary" style="width: 100%;" id="tag-select-directory-btn">
                                            选择目录
                                        </button>
                                        <button type="button" class="button button-secondary tag-clear-directory-btn" title="清除绑定">✕</button>
                                    </div>
                                </div>
                                <p class="description">留空不自动关联。</p>
                            </div>

                            <div class="form-group">
                                <label for="auto_inlink">自动内链</label>
                                <input type="number" id="auto_inlink" name="auto_inlink"
                                    value="<?php echo intval($edit_tag['auto_inlink'] ?? 0); ?>"
                                    min="0" class="small-text" placeholder="0">
                                <p class="description">最大内链tag数量，留空或0则关闭，<br>命中该tag的文章自动包裹为内链，<br>只取内容中第一个tag的配置。</p>
                            </div>

                            <div class="form-group">
                                <label for="auto_image">自动插图</label>
                                <input type="number" id="auto_image" name="auto_image"
                                    value="<?php echo intval($edit_tag['auto_image'] ?? 0); ?>"
                                    min="0" class="small-text" placeholder="0">
                                <p class="description">插图数量（最大受自动内链数限制），<br>需同时设置"自动内链"和"自动图片<br>目录"，留空或0则关闭，<br>只取内容中第一个tag的配置。</p>
                            </div>

                            <div class="form-group">
                                <label for="tag_order">排序</label>
                                <input type="number" id="tag_order" name="tag_order"
                                    value="<?php echo $edit_tag['tag_order']; ?>" min="0" class="small-text">
                            </div>

                            <div class="form-group">
                                <label for="tag_status">状态</label>
                                <select id="tag_status" name="tag_status" class="regular-select">
                                    <option value="active" <?php echo $edit_tag['tag_status'] == 'active' ? 'selected' : ''; ?>>启用</option>
                                    <option value="inactive" <?php echo $edit_tag['tag_status'] == 'inactive' ? 'selected' : ''; ?>>禁用</option>
                                </select>
                            </div>

                            <div class="form-submit">
                                <a href="<?php echo todo_admin_url('index.php?page=tags'); ?>" class="button button-secondary">取消</a>
                                <button type="submit" name="update_tag" class="button button-primary">更新标签</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <!-- 添加标签表单 -->
                        <form method="post" id="add-tag-form" class="todo-request" action="<?php echo todo_admin_url('index.php?page=tags'); ?>">
                            <?php echo Todo_Security_Manager::csrf_field('add_tag'); ?>
                            <div class="form-group">
                                <label for="tag_name">标签名称 <span class="required">*</span></label>
                                <input type="text" id="tag_name" name="tag_name" class="regular-text" required>
                            </div>

                            <div class="form-group">
                                <label for="tag_slug">标签别名</label>
                                <input type="text" id="tag_slug" name="tag_slug" class="regular-text">
                            </div>

                            <div class="form-group">
                                <label for="tag_description">标签描述</label>
                                <textarea id="tag_description" name="tag_description" rows="3"
                                    class="large-text"></textarea>
                            </div>

                            <div class="form-group">
                                <label for="tag_order">排序</label>
                                <input type="number" id="tag_order" name="tag_order" value="0" min="0" class="small-text">
                            </div>

                            <div class="form-submit">
                                <button type="submit" name="add_tag" class="button button-primary">添加标签</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 右侧：筛选和列表 -->
        <div class="admin-col-r">
            <!-- 筛选器 -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">标签列表</h3>
                    <div class="admin-card-actions">
                        <span class="description m-0"><?php echo $total_tags; ?> 个标签</span>
                    </div>
                </div>
                <div class="admin-card-content">
                    <div class="admin-filter-bar">
                        <form method="get" class="tag-filter-form todo-request" action="<?php echo todo_admin_url('index.php'); ?>">
                            <input type="hidden" name="page" value="tags">
                            <?php if (isset($_GET['site'])): ?>
                                <input type="hidden" name="site" value="<?php echo esc_attr($_GET['site']); ?>">
                            <?php endif; ?>
                            <?php if (function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()): ?>
                                <input type="hidden" name="format" value="iframe">
                            <?php endif; ?>

                            <div class="filter-row">
                                <div class="filter-field">
                                    <input type="text" id="search" name="search"
                                        value="<?php echo htmlspecialchars($search); ?>" placeholder="搜索标签名称或描述"
                                        class="regular-text" style="min-width: 150px;">
                                </div>

                                <div class="filter-field">
                                    <select id="status" name="status" class="regular-select" style="min-width: unset;">
                                        <option value="">全部状态</option>
                                        <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>启用
                                        </option>
                                        <option value="inactive" <?php echo $status == 'inactive' ? 'selected' : ''; ?>>禁用
                                        </option>
                                    </select>
                                </div>

                                <div class="filter-field">
                                    <select id="category_id" name="category_id" class="regular-select" style="min-width: 150px;">
                                        <option value="0">全部栏目</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo $category['category_id']; ?>" <?php echo $category_id == $category['category_id'] ? 'selected' : ''; ?>>
                                                <?php echo ($category['level'] > 0 ? str_repeat('-', $category['level']) . ' ' : ''); ?>
                                                <?php echo htmlspecialchars($category['category_name']); ?>
                                                <?php if (!empty($category['model_name'])): ?>
                                                    (<?php echo htmlspecialchars($category['model_name']); ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="filter-field">
                                    <select id="model_id" name="model_id" class="regular-select" style="min-width: 150px;">
                                        <option value="0">全部模型</option>
                                        <?php foreach ($models as $model): ?>
                                            <option value="<?php echo $model['model_id']; ?>" <?php echo $model_id == $model['model_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($model['model_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="filter-actions">
                                    <button type="submit" class="button button-secondary">筛选</button>
                                    <?php if ($search !== '' || $category_id || $model_id || (isset($_GET['status']) && $status !== 'active')): ?>
                                        <a href="<?php echo todo_admin_url('index.php?page=tags'); ?>" class="btn-normal m-l-05">重置</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>

                    <form method="post" id="tags-filter" class="todo-request" action="<?php echo todo_admin_url('index.php?page=tags'); ?>">
                        <?php echo Todo_Security_Manager::csrf_field('bulk_delete_tags'); ?>

                        <table class="admin-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <td class="check-column">
                                        <input id="cb-select-all-1" type="checkbox">
                                    </td>
                                    <?php echo todo_admin_render_sort_th('名称', 'tag_title', 'column-primary', array('query' => $_GET, 'base_url' => todo_admin_url('index.php'), 'current_orderby' => $orderby, 'current_order' => strtolower($order), 'style' => 'min-width: 160px;')); ?>
                                    <th>描述</th>
                                    <th style="min-width: 150px;">内链/插图</th>
                                    <th>颜色</th>
                                    <?php echo todo_admin_render_sort_th('文章数', 'post_count', 'column-posts', array('query' => $_GET, 'base_url' => todo_admin_url('index.php'), 'current_orderby' => $orderby, 'current_order' => strtolower($order), 'style' => 'min-width: 80px;')); ?>
                                    <th style="min-width: 80px;">状态</th>
                                    <?php echo todo_admin_render_sort_th('排序', 'tag_order', 'column-order', array('query' => $_GET, 'base_url' => todo_admin_url('index.php'), 'current_orderby' => $orderby, 'current_order' => strtolower($order))); ?>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (empty($tags)): ?>
                                    <tr>
                                        <td colspan="8">
                                            <div class="no-items">
                                                <p>未找到标签。</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($tags as $tag): ?>
                                        <tr id="tag-<?php echo $tag['tag_id']; ?>">
                                            <th>
                                                <input type="checkbox" name="tag_ids[]" value="<?php echo $tag['tag_id']; ?>">
                                            </th>
                                            <td class="name has-row-actions column-primary">
                                                <strong>
                                                    <a href="<?php echo todo_admin_url('index.php?page=tags&action=edit&tag_id=' . $tag['tag_id']); ?>"
                                                        class="row-title">
                                                        <?php if ($tag['tag_icon']): ?>
                                                            <i class="<?php echo htmlspecialchars($tag['tag_icon']); ?>"></i>
                                                        <?php endif; ?>
                                                        <?php 
                                                        $raw_tag_title = $tag['tag_title'] ?? '';
                                                        $clean_tag_title = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($raw_tag_title) : $raw_tag_title;
                                                        $clean_tag_title = trim(preg_replace('/\[[a-z]{2}(?:-[a-z]{2})?\|.*?\]/is', '', $clean_tag_title));
                                                        $clean_tag_title = trim(preg_replace('/\[:[a-z]{2}(?:-[a-z]{2})?\].*?\[:\]/is', '', $clean_tag_title));
                                                        echo htmlspecialchars($clean_tag_title ?: $raw_tag_title); 
                                                        ?>
                                                    </a>
                                                </strong>
                                                <div class="row-actions">
                                                    <span class="edit">
                                                        <a
                                                            href="<?php echo todo_admin_url('index.php?page=tags&action=edit&tag_id=' . $tag['tag_id']); ?>">编辑</a>
                                                        |
                                                    </span>
                                                    <span class="delete">
                                                        <a href="javascript:void(0);" class="delete-single-tag submitdelete"
                                                            data-tag-id="<?php echo $tag['tag_id']; ?>">删除</a> |
                                                    </span>
                                                    <span class="view">
                                                        <a href="<?php echo esc_url(todo_get_tag_link($tag['tag_id'])); ?>" target="_blank">查看</a>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="description">
                                                <?php echo htmlspecialchars($tag['tag_desc']); ?>
                                            </td>
                                            <td>
                                                <div class="tb-meta">
                                                <code style="display: block;"><?php echo htmlspecialchars($tag['tag_media_directory']); ?></code>
                                                <?php if ($tag['auto_inlink']): echo '内链' . htmlspecialchars($tag['auto_inlink']); endif; ?><?php if ($tag['auto_image']): echo ' / 插图' . htmlspecialchars($tag['auto_image']); endif; ?>
                                                </div>
                                            </td>
                                            <td class="color">
                                                <?php if ($tag['tag_color']): ?>
                                                    <span class="tag-color-preview"
                                                        style="background-color: <?php echo htmlspecialchars($tag['tag_color']); ?>;"></span>
                                                    <code><?php echo htmlspecialchars($tag['tag_color']); ?></code>
                                                <?php endif; ?>
                                            </td>
                                            <td class="posts column-posts">
                                                <a href="<?php echo todo_admin_url('index.php?page=posts&tag_id=' . $tag['tag_id']); ?>"><?php echo intval($tag['post_count'] ?? 0); ?></a>
                                            </td>
                                            <td>
                                                <span class="status-<?php echo $tag['tag_status']; ?>">
                                                    <?php echo $tag['tag_status'] == 'active' ? '启用' : '禁用'; ?>
                                                </span>
                                            </td>
                                            <td class="order column-order">
                                                <?php echo $tag['tag_order']; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>

                            <tfoot>
                                <tr>
                                    <td>
                                        <input id="cb-select-all-2" type="checkbox">
                                    </td>
                                    <?php echo todo_admin_render_sort_th('名称', 'tag_title', 'column-primary', array('query' => $_GET, 'base_url' => todo_admin_url('index.php'), 'current_orderby' => $orderby, 'current_order' => strtolower($order))); ?>
                                    <th>描述</th>
                                    <th>别名</th>
                                    <th>颜色</th>
                                    <?php echo todo_admin_render_sort_th('文章数', 'post_count', 'column-posts', array('query' => $_GET, 'base_url' => todo_admin_url('index.php'), 'current_orderby' => $orderby, 'current_order' => strtolower($order))); ?>
                                    <th>状态</th>
                                    <?php echo todo_admin_render_sort_th('排序', 'tag_order', 'column-order', array('query' => $_GET, 'base_url' => todo_admin_url('index.php'), 'current_orderby' => $orderby, 'current_order' => strtolower($order))); ?>
                                </tr>
                            </tfoot>
                        </table>

                        <div class="bulk-actions-bar" style="margin: 10px 0 0;">
                            <div class="bulk-actions">
                                <select name="bulk_action" id="bulk-action-selector-bottom" class="regular-select">
                                    <option value="-1">批量操作</option>
                                    <option value="delete">删除</option>
                                </select>
                                <button type="submit" class="button button-secondary action" id="doaction">应用</button>
                            </div>

                            <?php if ($total_pages > 1): ?>
                                <?php
                                $pagination_add_args = array();
                                if ($search !== '') {
                                    $pagination_add_args['search'] = $search;
                                }
                                if ($category_id) {
                                    $pagination_add_args['category_id'] = $category_id;
                                }
                                if ($model_id) {
                                    $pagination_add_args['model_id'] = $model_id;
                                }
                                if ($status !== '') {
                                    $pagination_add_args['status'] = $status;
                                }
                                if (isset($_GET['site'])) {
                                    $pagination_add_args['site'] = $_GET['site'];
                                }
                                if ($orderby !== '') {
                                    $pagination_add_args['orderby'] = $orderby;
                                }
                                $pagination_add_args['order'] = strtolower($order) === 'desc' ? 'desc' : 'asc';

                                echo todo_render_pagination(array(
                                    'total_items' => $total_tags,
                                    'per_page' => $per_page,
                                    'current_page' => $current_page,
                                    'base_url' => todo_admin_url('index.php?page=tags'),
                                    'add_args' => $pagination_add_args,
                                    'show_count' => false,
                                ));
                                ?>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// 渲染媒体模态框用于标签媒体目录选择
echo TodoMediaModal::render('tag-media-directory-modal', array(
    'title' => '选择媒体目录',
    'button_text' => '确认目录',
    'multiple' => false,
    'type' => 'file'
));
?>

<style>
    .filter-field {
        flex: 1;
    }

    .tag-color-preview {
        display: inline-block;
        width: 20px;
        height: 15px;
        border-radius: 2px;
        vertical-align: middle;
        margin-right: 5px;
    }
</style>

<script type="text/javascript">
    // 确认删除
    document.addEventListener('DOMContentLoaded', function () {
        // 单个标签删除
        const deleteSingleLinks = document.querySelectorAll('.delete-single-tag');
        deleteSingleLinks.forEach(link => {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                const tagId = this.dataset.tagId;

                TodoModal.deleteConfirm({
                    title: '确认删除标签',
                    message: '确定要删除此标签吗？',
                    onConfirm: function () {
                        // 创建表单数据
                        const formData = new FormData();
                        formData.append('delete_tag', '1');
                        formData.append('tag_id', tagId);

                        // 获取CSRF token（从批量删除表单中获取）
                        const csrfField = document.querySelector('#tags-filter input[name="_todononce"]');
                        if (csrfField) {
                            formData.append('_todononce', csrfField.value);
                        } else {
                            console.error('未找到CSRF token');
                            TodoCMS.Utils.showMessage('安全验证失败，请刷新页面重试。', 'error');
                            return;
                        }

                        // 发送AJAX请求
                        fetch(window.location.href, {
                            method: 'POST',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                                'Accept': 'application/json'
                            },
                            body: formData
                        })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    // 刷新页面并携带成功消息
                                    const url = new URL(window.location.href);
                                    url.searchParams.set('message', 'tag_deleted');
                                    window.location.href = url.toString();
                                } else {
                                    TodoCMS.Utils.showMessage(data.message || '删除失败，请重试。', 'error');
                                }
                            })
                            .catch(error => {
                                console.error('删除失败:', error);
                                TodoCMS.Utils.showMessage('删除失败，请重试。', 'error');
                            });
                    }
                });
            });
        });

        // 批量删除确认
        const tagsFilter = document.getElementById('tags-filter');
        if (tagsFilter) {
            tagsFilter.addEventListener('submit', function (e) {
                const bulkActionTop = document.getElementById('bulk-action-selector-top');
                const bulkActionBottom = document.getElementById('bulk-action-selector-bottom');
                const checkedBoxes = document.querySelectorAll('input[name="tag_ids[]"]:checked');

                // 获取当前选中的批量操作值
                let selectedAction = '';
                if (bulkActionTop && bulkActionTop.value !== '-1') {
                    selectedAction = bulkActionTop.value;
                    // 同步到底部选择器
                    if (bulkActionBottom) {
                        bulkActionBottom.value = selectedAction;
                    }
                } else if (bulkActionBottom && bulkActionBottom.value !== '-1') {
                    selectedAction = bulkActionBottom.value;
                    // 同步到顶部选择器
                    if (bulkActionTop) {
                        bulkActionTop.value = selectedAction;
                    }
                }

                // 确保表单中有bulk_action字段
                let bulkActionInput = tagsFilter.querySelector('input[name="bulk_action"]');
                if (!bulkActionInput) {
                    bulkActionInput = document.createElement('input');
                    bulkActionInput.type = 'hidden';
                    bulkActionInput.name = 'bulk_action';
                    tagsFilter.appendChild(bulkActionInput);
                }
                bulkActionInput.value = selectedAction;

                if (selectedAction === 'delete') {
                    if (checkedBoxes.length === 0) {
                        TodoCMS.Utils.showMessage('请先选择要删除的标签。', 'warning');
                        e.preventDefault();
                        return;
                    }
                    e.preventDefault();
                    TodoModal.deleteConfirm({
                        title: '确认删除标签',
                        message: '确定要删除所选的 ' + checkedBoxes.length + ' 个标签吗？',
                        onConfirm: function () {
                            tagsFilter.submit();
                        }
                    });
                } else if (selectedAction === '' || selectedAction === '-1') {
                    // 如果没有选择操作但有选中的项目，提示用户
                    if (checkedBoxes.length > 0) {
                        TodoCMS.Utils.showMessage('请选择要执行的批量操作。', 'warning');
                        e.preventDefault();
                    }
                }
            });
        }

        // 全选/取消全选
        const selectAllTop = document.getElementById('cb-select-all-1');
        const selectAllBottom = document.getElementById('cb-select-all-2');
        const checkboxes = document.querySelectorAll('input[name="tag_ids[]"]');

        function toggleAll(checked) {
            checkboxes.forEach(checkbox => {
                checkbox.checked = checked;
            });
            selectAllTop.checked = checked;
            selectAllBottom.checked = checked;
        }

        if (selectAllTop) {
            selectAllTop.addEventListener('change', function () {
                toggleAll(this.checked);
            });
        }

        if (selectAllBottom) {
            selectAllBottom.addEventListener('change', function () {
                toggleAll(this.checked);
            });
        }

        if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils && TodoCMS.Utils.initSlugGenerator) {
            TodoCMS.Utils.initSlugGenerator({
                nameInputId: 'tag_name',
                slugInputId: 'tag_slug',
                ajaxAction: 'slug_generator',
                id: <?php echo !empty($edit_tag) ? intval($edit_tag['tag_id']) : 0; ?>,
                nameParam: 'name',
                idParam: 'id',
                extraParams: {
                    type: 'tag'
                }
            });
        }

        // 标签绑定媒体目录功能
        var openBtn = document.getElementById('tag-select-directory-btn');
        var dirInput = document.getElementById('tag_media_directory');
        var clearBtn = document.querySelector('.tag-clear-directory-btn');
        var MODAL_ID = 'tag-media-directory-modal';

        function getTagMediaModal() {
            return document.getElementById(MODAL_ID)
                || (window.parent && window.parent.document.getElementById(MODAL_ID));
        }

        // 清除按钮
        if (clearBtn && dirInput) {
            clearBtn.addEventListener('click', function() {
                dirInput.value = '';
            });
        }

        // 打开模态框按钮
        if (openBtn) {
            openBtn.addEventListener('click', function() {
                var modal = getTagMediaModal();
                if (!modal) {
                    console.error('媒体模态框未渲染');
                    return;
                }
                if (typeof window.selectMediaForField === 'function') {
                    window.selectMediaForField({
                        modal_id: MODAL_ID,
                        title: '选择媒体目录',
                        button_text: '确认目录',
                        multiple: false,
                        type: 'file',
                        default_tab: 'directory'
                    });
                } else {
                    window.showModal();
                }
            });
        }

        // 监听模态框关闭：读取 active 的 directory-folder，填入表单
        function captureDirectoryOnClose() {
            var modal = getTagMediaModal();
            if (!modal) return;
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(m) {
                    if (m.attributeName === 'class') {
                        var isShown = modal.classList.contains('show');
                        if (!isShown) {
                            var active = modal.querySelector('.directory-item.directory-folder.active');
                            if (active && active.dataset.path) {
                                if (dirInput) dirInput.value = 'content/uploads/' + active.dataset.path;
                            }
                        }
                    }
                });
            });
            observer.observe(modal, { attributes: true, attributeFilter: ['class'] });
        }
        captureDirectoryOnClose();
    });
</script>
