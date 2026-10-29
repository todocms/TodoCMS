<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

/**
 * 媒体库管理页面
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 加载媒体模态框组件
require_once ADMIN_DIR . '/components/media-modal.php';

// 检查用户权限（使用 Todo_Session_Manager）
$has_permission = false;
if (class_exists('Todo_Session_Manager')) {
    Todo_Session_Manager::init();
    $has_permission = Todo_Session_Manager::is_admin_logged_in();
}
if (!$has_permission) {
    // 显示错误消息但不阻止页面继续执行，这样footer仍然可以显示
    $permission_error = true;
}

$current_user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
$current_user_obj = ($current_user_id > 0 && class_exists('CMS_User')) ? new CMS_User($current_user_id) : null;
$is_platform_admin = false;
if ($current_user_id > 0 && function_exists('todo_is_super_admin') && todo_is_super_admin($current_user_id)) {
    $is_platform_admin = true;
} elseif (is_object($current_user_obj) && method_exists($current_user_obj, 'has_role')) {
    $is_platform_admin = $current_user_obj->has_role('administrator') || $current_user_obj->has_role('admin');
}

$current_site_scope_id = (isset($_GET['site']) && is_numeric($_GET['site']) && (int) $_GET['site'] > 0) ? (int) $_GET['site'] : 0;
if ($current_site_scope_id <= 0 && !$is_platform_admin && function_exists('todo_current_site_id') && todo_current_site_id() > 0) {
    $current_site_scope_id = (int) todo_current_site_id();
}
$site_qs = $current_site_scope_id > 0 ? '&site=' . $current_site_scope_id : '';
$site_search_hidden = $current_site_scope_id > 0 ? '<input type="hidden" name="site" value="' . esc_attr($current_site_scope_id) . '">' : '';

// 统一使用表格视图模式
$mode = 'list';

// 处理批量操作
$doaction = isset($_REQUEST['action']) && $_REQUEST['action'] != -1 ? $_REQUEST['action'] : false;
if (!$doaction && isset($_REQUEST['action2']) && $_REQUEST['action2'] != -1) {
    $doaction = $_REQUEST['action2'];
}

if ($doaction) {
    // 检查安全性
    if (!Todo_Security_Manager::verify_request_csrf('bulk-media', '_todononce')) {
        todo_die('安全验证失败');
    }

    $location = todo_admin_url('index.php?page=media' . ($current_site_scope_id > 0 ? '&site=' . $current_site_scope_id : ''));
    $media_ids = isset($_REQUEST['media']) ? $_REQUEST['media'] : array();

    switch ($doaction) {
        case 'delete':
            if (!$is_platform_admin) {
                todo_die('您没有足够的权限访问此页面。');
            }
            if (empty($media_ids)) {
                break;
            }

            $deleted = 0;
            foreach ($media_ids as $media_id) {
                $media_id = intval($media_id);
                if ($media_id <= 0)
                    continue;

                try {
                    $media = new CMS_Media($media_id);
                    if ($media->delete()) {
                        $deleted++;
                    }
                } catch (Exception $e) {
                    // 记录错误但继续处理其他媒体
                    error_log('删除媒体文件失败: ' . $e->getMessage());
                }
            }

            if ($deleted > 0) {
                $location = add_query_arg('deleted', $deleted, $location);
            }
            break;
    }

    // 重定向到媒体页面
    echo "<script type=\"text/javascript\">window.location.href = '$location';</script>";
    exit;
}

// 获取当前页面参数
$page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20; // 每页显示数量
$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
$mime_type = isset($_GET['mime_type']) ? sanitize_text_field($_GET['mime_type']) : '';
if ($mime_type === 'norecorded') {
    $mime_type = '';
}

// 构建查询参数
$args = array(
    'number' => $per_page,
    'offset' => ($page - 1) * $per_page,
    'orderby' => 'date',
    'order' => 'DESC',
);

if (!empty($search)) {
    $args['search'] = $search;
}

if (!empty($mime_type)) {
    $args['mime_type'] = $mime_type;
}
if ($current_site_scope_id > 0) {
    $args['site_id'] = $current_site_scope_id;
}

// 获取媒体列表和总数
$unrecited_mode = ($mime_type === 'unrecited');
if ($unrecited_mode) {
    // 未附加模式：跳过初始查询，由前端异步分段加载
    $media_list = array();
    $total_count = -1; // -1 表示"未知，待异步加载"
    $total_pages = 0;
} else {
    try {
        $media_list = CMS_Media::get_media($args);
        $total_count = CMS_Media::get_media_count($args);
        $total_pages = ceil($total_count / $per_page);
    } catch (Exception $e) {
        $media_list = array();
        $total_count = 0;
        $total_pages = 0;
        $error_message = $e->getMessage();
    }
}

?>

<?php if (isset($permission_error) && $permission_error): ?>
    <div class="notice notice-error is-dismissible">
        <p>您没有权限访问此页面</p>
        <button type="button" class="notice-dismiss">✕</button>
    </div>
<?php else: ?>
    <div class="admin-page">
        <!-- 隐藏的CSRF token表单，供JavaScript使用 -->
        <form id="csrf-token-form" style="display: none;">
            <input type="hidden" name="action" value="upload_media">
            <?php echo Todo_Security_Manager::csrf_field('upload_media', '_todononce'); ?>
            <?php echo Todo_Security_Manager::csrf_field('get_media', '_get_nonce'); ?>
            <?php echo Todo_Security_Manager::csrf_field('update_media', '_update_nonce'); ?>
            <?php echo Todo_Security_Manager::csrf_field('delete_media', '_delete_nonce'); ?>
            <?php echo Todo_Security_Manager::csrf_field('recalc_media_usage', '_recalc_nonce'); ?>
        </form>

        <div class="admin-page-header">
            <h2 class="admin-page-title verse">“等闲识得东风面，万紫千红总是春。”<span>——朱熹《春日》</span></h2>
            <div class="admin-page-actions">
                <?php if ($has_permission): ?>
                    <a id="upload-media-button" href="javascript:void(0);" class="button button-primary">添加新文件</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (isset($error_message)): ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo $error_message; ?></p>
                <button type="button" class="notice-dismiss">✕</button>
            </div>
        <?php endif; ?>

        <?php
        // 显示操作消息
        $messages = array();

        if (isset($_GET['deleted']) && $deleted = intval($_GET['deleted'])) {
            if ($deleted == 1) {
                $messages[] = '媒体文件已永久删除。';
            } else {
                $messages[] = sprintf('%s个媒体文件已永久删除。', number_format_i18n($deleted));
            }
        }

        if (!empty($messages)): ?>
            <div id="message" class="notice notice-success is-dismissible">
                <?php foreach ($messages as $msg): ?>
                    <p><?php echo $msg; ?></p>
                <?php endforeach; ?>
                <button type="button" class="notice-dismiss">✕</button>
            </div>
        <?php endif; ?>

        <div class="admin-card">
            <div class="admin-card-header">
                <div class="admin-card-title-section">
                    <h3 class="admin-card-title">媒体列表</h3>
                    <div class="admin-card-subtitle" id="media-count-subtitle">
                        <?php if ($unrecited_mode): ?>
                            <span class="unrecited-count-label">点击按钮开始查询</span>
                        <?php else: ?>
                            <?php echo $total_count; ?> 个媒体文件
                        <?php endif; ?>
                    </div>
                </div>

                <div class="admin-card-actions">
                    <form method="get" action="<?php echo todo_admin_url('index.php'); ?>" class="search-form todo-request">
                        <input type="hidden" name="page" value="media">
                        <?php echo $site_search_hidden; ?>
                        <?php if ($mime_type): ?>
                            <input type="hidden" name="mime_type" value="<?php echo esc_attr($mime_type); ?>">
                        <?php endif; ?>
                        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="搜索媒体...">
                        <button type="submit" class="button button-secondary">搜索</button>
                    </form>
                </div>
            </div>

            <div class="admin-card-content">
                <form id="posts-filter" method="GET" action="<?php echo todo_admin_url('index.php'); ?>" class="todo-request">
                    <input type="hidden" name="page" value="media">
                    <?php echo $site_search_hidden; ?>
                    <?php if ($search): ?>
                        <input type="hidden" name="s" value="<?php echo esc_attr($search); ?>">
                    <?php endif; ?>

                    <div class="admin-filter-bar">
                        <ul class="admin-filters">
                            <li>
                                <a href="<?php echo todo_admin_url('index.php?page=media' . $site_qs . ($search ? '&s=' . urlencode($search) : '')); ?>"
                                    class="<?php echo empty($mime_type) ? 'current' : ''; ?>">
                                    所有媒体项目
                                </a>
                            </li>
                            <li>
                                <a href="<?php echo todo_admin_url('index.php?page=media&mime_type=image' . $site_qs . ($search ? '&s=' . urlencode($search) : '')); ?>"
                                    class="<?php echo $mime_type === 'image' ? 'current' : ''; ?>">
                                    图像
                                </a>
                            </li>
                            <li>
                                <a href="<?php echo todo_admin_url('index.php?page=media&mime_type=audio' . $site_qs . ($search ? '&s=' . urlencode($search) : '')); ?>"
                                    class="<?php echo $mime_type === 'audio' ? 'current' : ''; ?>">
                                    音频
                                </a>
                            </li>
                            <li>
                                <a href="<?php echo todo_admin_url('index.php?page=media&mime_type=video' . $site_qs . ($search ? '&s=' . urlencode($search) : '')); ?>"
                                    class="<?php echo $mime_type === 'video' ? 'current' : ''; ?>">
                                    视频
                                </a>
                            </li>
                            <li>
                                <a href="<?php echo todo_admin_url('index.php?page=media&mime_type=application/pdf' . $site_qs . ($search ? '&s=' . urlencode($search) : '')); ?>"
                                    class="<?php echo $mime_type === 'application/pdf' ? 'current' : ''; ?>">
                                    PDF
                                </a>
                            </li>
                            <li>
                                <a href="<?php echo todo_admin_url('index.php?page=media&mime_type=application' . $site_qs . ($search ? '&s=' . urlencode($search) : '')); ?>"
                                    class="<?php echo $mime_type === 'application' ? 'current' : ''; ?>">
                                    文档
                                </a>
                            </li>
                            <li>
                                <a href="<?php echo todo_admin_url('index.php?page=media&mime_type=unrecited' . ($search ? '&s=' . urlencode($search) : '')); ?>"
                                    class="<?php echo $mime_type === 'unrecited' ? 'current' : ''; ?>">
                                    未附加
                                </a>
                            </li>
                        </ul>
                    </div>
                    <?php if ($is_platform_admin): ?>
                    <div class="bulk-actions-bar">
                        <div class="bulk-actions">
                            <select name="action" id="bulk-action-selector-top">
                                <option value="-1">批量操作</option>
                                <option value="delete">永久删除</option>
                            </select>
                            <button type="submit" id="doaction" class="button button-secondary">应用</button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <?php if ($is_platform_admin): ?>
                                <th class="check-column">
                                    <input id="cb-select-all" type="checkbox">
                                </th>
                                <?php endif; ?>
                                <th>文件</th>
                                <th style="width: 100px; min-width: 100px;">作者</th>
                                <th style="width: 120px; min-width: 120px;">附加到</th>
                                <th style="width: 150px; min-width: 150px;">日期</th>
                            </tr>
                        </thead>

                        <tbody id="the-list">
                            <?php if ($unrecited_mode): ?>
                                <tr class="empty" id="unrecited-placeholder">
                                    <td colspan="5">
                                        <div class="no-items" id="unrecited-query-area">
                                            <p style="margin-bottom: 16px; font-size: 14px; color: #666;">
                                                查询可能涉及大量媒体文件，一旦开始请勿离开页面。
                                            </p>
                                            <button type="button" id="start-unrecited-query" class="button button-primary" style="padding: 8px 20px; font-size: 14px;">
                                                开始查询文件
                                            </button>
                                            <div id="unrecited-progress" style="display: none; margin-top: 12px;">
                                                <span id="unrecited-status-text" style="color: #333;"></span>
                                                <div style="margin-top: 8px; background: #f0f0f0; border-radius: 4px; height: 6px; max-width: 300px; margin-left: auto; margin-right: auto;">
                                                    <div id="unrecited-progress-bar" style="background: #2271b1; height: 100%; border-radius: 4px; width: 0%; transition: width 0.3s;"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php elseif (empty($media_list)): ?>
                                <tr class="empty">
                                    <td colspan="5">
                                        <div class="no-items">
                                            <p>没有找到媒体项目。</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($media_list as $media_item): ?>
                                    <?php
                                    if (!is_object($media_item)) {
                                        error_log('Media item is not an object: ' . print_r($media_item, true));
                                        continue;
                                    }

                                    if (!isset($media_item->media_id)) {
                                        error_log('Media item missing media_id property: ' . print_r($media_item, true));
                                        continue;
                                    }

                                    $media = new CMS_Media($media_item);
                                    $metadata = $media->get_metadata();
                                    $item_mime_type = $media->get_mime_type();
                                    $is_image = strpos($item_mime_type, 'image/') === 0;
                                    ?>
                                    <tr id="post-<?php echo $media->get_id(); ?>">
                                        <?php if ($is_platform_admin): ?>
                                        <td>
                                            <input id="cb-select-<?php echo $media->get_id(); ?>" type="checkbox" name="media[]"
                                                value="<?php echo $media->get_id(); ?>">
                                        </td>
                                        <?php endif; ?>
                                        <td class="title column-title column-primary" data-colname="文件">
                                            <div class="flex gap-3">
                                                <a href="javascript:void(0);" class="edit-media"
                                                        data-id="<?php echo $media->get_id(); ?>">
                                                    <div class="flex-item" style="width: 110px;min-height: 35px;background-color: #ddd;border-radius: 3px;display: flex;align-items: center;justify-content: center;">
                                                        <?php if ($is_image): ?>
                                                            <img src="<?php echo $media->get_url(); ?>"
                                                            alt="<?php echo basename($media->get_title()); ?>" class="thumbnail"
                                                            style="max-width: 100px;max-height: 50px;">
                                                        <?php else: ?>
                                                            <span style="font-size: 25px;color: #a0a0aa;overflow: hidden;display: flex;">
                                                                <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24">
                                                                    <path d="M0 0h24v24H0z" fill="none"></path>
                                                                    <g fill="none">
                                                                        <path fill="currentColor" d="m15.393 4.054l-.502.557zm3.959 3.563l-.502.557zm2.302 2.537l-.685.305zM3.172 20.828l.53-.53zm17.656 0l-.53-.53zM14 21.25h-4v1.5h4zM2.75 14v-4h-1.5v4zm18.5-.437V14h1.5v-.437zM14.891 4.61l3.959 3.563l1.003-1.115l-3.958-3.563zm7.859 8.952c0-1.689.015-2.758-.41-3.714l-1.371.61c.266.598.281 1.283.281 3.104zm-3.9-5.389c1.353 1.218 1.853 1.688 2.119 2.285l1.37-.61c-.426-.957-1.23-1.66-2.486-2.79zM10.03 2.75c1.582 0 2.179.012 2.71.216l.538-1.4c-.852-.328-1.78-.316-3.248-.316zm5.865.746c-1.086-.977-1.765-1.604-2.617-1.93l-.537 1.4c.532.204.98.592 2.15 1.645zM10 21.25c-1.907 0-3.261-.002-4.29-.14c-1.005-.135-1.585-.389-2.008-.812l-1.06 1.06c.748.75 1.697 1.081 2.869 1.239c1.15.155 2.625.153 4.489.153zM1.25 14c0 1.864-.002 3.338.153 4.489c.158 1.172.49 2.121 1.238 2.87l1.06-1.06c-.422-.424-.676-1.004-.811-2.01c-.138-1.027-.14-2.382-.14-4.289zM14 22.75c1.864 0 3.338.002 4.489-.153c1.172-.158 2.121-.49 2.87-1.238l-1.06-1.06c-.424.422-1.004.676-2.01.811c-1.027.138-2.382.14-4.289.14zM21.25 14c0 1.907-.002 3.262-.14 4.29c-.135 1.005-.389 1.585-.812 2.008l1.06 1.06c.75-.748 1.081-1.697 1.239-2.869c.155-1.15.153-2.625.153-4.489zm-18.5-4c0-1.907.002-3.261.14-4.29c.135-1.005.389-1.585.812-2.008l-1.06-1.06c-.75.748-1.081 1.697-1.239 2.869C1.248 6.661 1.25 8.136 1.25 10zm7.28-8.75c-1.875 0-3.356-.002-4.511.153c-1.177.158-2.129.49-2.878 1.238l1.06 1.06c.424-.422 1.005-.676 2.017-.811c1.033-.138 2.395-.14 4.312-.14z"></path>
                                                                        <path stroke="currentColor" stroke-width="1.5" d="M13 2.5V5c0 2.357 0 3.536.732 4.268S15.643 10 18 10h4"></path>
                                                                    </g>
                                                                </svg>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </a>
                                                <div class="flex-item">
                                                    <a href="javascript:void(0);" class="edit-media"
                                                        data-id="<?php echo $media->get_id(); ?>">
                                                        <?php echo basename($media->get_file_path()); ?>
                                                    </a>
                                                    <div class="row-actions">
                                                        <span class="edit"><a href="javascript:void(0);" class="edit-media"
                                                                data-id="<?php echo $media->get_id(); ?>">编辑</a></span>
                                                        <?php if ($is_platform_admin): ?>
                                                            <span class="delete"> | <a href="javascript:void(0);" class="delete-media submitdelete"
                                                                    data-id="<?php echo $media->get_id(); ?>">永久删除</a></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="author column-author" data-colname="作者">
                                            管理员
                                        </td>
                                        <td class="parent column-parent" data-colname="附加到" style="font-size: 12px;">
                                            <?php
                                            $usage_count = isset($media_item->usage_count) ? (int) $media_item->usage_count : 0;

                                            if ($usage_count > 0) {
                                                echo '已使用 ' . number_format_i18n($usage_count) . ' 次';
                                            } else {
                                                echo '';
                                            }
                                            ?>
                                            <div class="row-actions">
                                                <a href="javascript:void(0);" class="js-media-usage-recalc" data-id="<?php echo $media->get_id(); ?>">刷新</a>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="tb-meta"><?php echo format_date($media_item->upload_time, 'Y-m-d H:i'); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>

                    </table>

                    <?php if ($total_pages > 1): ?>
                        <?php
                        $pagination_add_args = array();
                        if (!empty($mime_type)) {
                            $pagination_add_args['mime_type'] = $mime_type;
                        }
                        if (!empty($search)) {
                            $pagination_add_args['s'] = $search;
                        }

                        echo todo_render_pagination(array(
                            'total_items' => $total_count,
                            'per_page' => $per_page,
                            'current_page' => $page,
                            'base_url' => todo_admin_url('index.php?page=media'),
                            'add_args' => $pagination_add_args,
                            'show_count' => true,
                        ));
                        ?>
                    <?php endif; ?>

                    <?php echo Todo_Security_Manager::csrf_field('bulk-media', '_todononce'); ?>
                </form>
            </div>
        </div>
    </div>

    <div id="ajax-response"></div>

    <!-- 媒体上传模态框 -->
    <?php echo TodoMediaModal::render('media-upload-modal', array(
        'title' => '上传媒体',
        'button_text' => '选择文件',
        'multiple' => true,
        'file_type' => 'all',
        'callback' => 'handleMediaUpload'
    )); ?>

    <!-- 媒体编辑模态框模板 -->
    <template id="todo-tpl-media-edit-modal">
        <form id="media-edit-form" class="todo-modal-form">
            <input type="hidden" name="media_id" value="">
            <input type="hidden" name="_update_nonce" value="<?php echo Todo_Security_Manager::generate_csrf_token('update_media'); ?>">

            <div class="media-edit-container">
                <!-- 左侧：媒体预览 -->
                <div class="media-preview-section">
                    <div class="media-preview-container">
                        <div class="media-preview-large">
                            <!-- 媒体预览将在这里显示 -->
                        </div>
                        <div class="media-info-summary">
                            <div class="media-filename"></div>
                            <div class="media-filesize"></div>
                            <div class="media-dimensions"></div>
                            <div class="media-upload-date"></div>
                            <div class="media-path" style="margin-top: 5px;"></div>
                            <div class="media-content-references" style="margin-top: 10px;"></div>
                        </div>
                    </div>
                </div>

                <!-- 右侧：编辑表单 -->
                <div class="media-edit-form-section">
                    <div class="form-group">
                        <label>标题 <span class="required">*</span></label>
                        <input type="text" name="title" class="form-control" required>
                        <p class="description">媒体文件的标题，用于显示和搜索</p>
                    </div>

                    <div class="form-group">
                        <label>替代文本</label>
                        <input type="text" name="alt_text" class="form-control">
                        <p class="description">为图像设置替代文本</p>
                    </div>

                    <div class="form-group">
                        <label>说明</label>
                        <textarea name="caption" class="form-control" rows="3"></textarea>
                        <p class="description">图片说明文字，通常显示在图片下方</p>
                    </div>

                    <div class="form-group">
                        <label>描述</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                        <p class="description">详细描述此媒体文件的内容和用途</p>
                    </div>

                    <!-- SEO选项 -->
                    <div class="form-group-section">
                        <h4>其他设置</h4>
                        <div class="form-group">
                            <label>SEO标题</label>
                            <input type="text" name="seo_title" class="form-control">
                            <p class="description">搜索引擎显示的标题</p>
                        </div>
                        <div class="form-group">
                            <label>SEO描述</label>
                            <textarea name="seo_description" class="form-control" rows="2"></textarea>
                            <p class="description">搜索引擎显示的描述</p>
                        </div>
                        <div class="form-group">
                            <label>元信息</label>
                            <input type="text" name="seo_keywords" class="form-control">
                            <p class="description">多个用英文逗号“,”分隔，在SEO关键字、tags、多语言等场景下使用</p>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </template>

    <!-- 媒体删除确认模态框模板 -->
    <template id="todo-tpl-media-delete-modal">
        <div class="todo-modal-body-content">
            <div class="todo-modal-message">您确定要永久删除此媒体文件吗？将从文件目录中直接删除不可恢复。</div>
            <input type="hidden" name="media_id" value="">
        </div>
    </template>

    <script type="text/javascript">
        // 确保TodoCMS对象存在，提供后备方案
        if (typeof window.TodoCMS === 'undefined') {
            window.TodoCMS = {};
        }
        if (typeof window.TodoCMS.Utils === 'undefined') {
            window.TodoCMS.Utils = {};
        }
        if (typeof window.TodoCMS.Utils.showMessage === 'undefined') {
            window.TodoCMS.Utils.showMessage = function (message, type) {
                type = type || 'info';
                if (typeof TodoModal !== 'undefined' && typeof TodoModal.toast === 'function') {
                    TodoModal.toast(message, type);
                }
            };
        }

        if (typeof window.TodoDOM === 'undefined') {
            window.TodoDOM = {
                get: function (id) {
                    var el = document.getElementById(id);
                    if (el) return el;
                    if (window.parent && window.parent !== window) {
                        try {
                            return window.parent.document.getElementById(id);
                        } catch (e) { }
                    }
                    return null;
                },
                query: function (selector, root) { return (root || document).querySelector(selector); },
                queryAll: function (selector, root) { return Array.prototype.slice.call((root || document).querySelectorAll(selector)); }
            };
        }
    </script>

    <script type="text/javascript">
        // Helper function to build AJAX URLs with correct query parameters
        const addQueryArg = (url, params) => {
            if (!params) return url;
            let p = typeof params === 'string' ? params : new URLSearchParams(params).toString();
            p = p.replace(/^\?+/, '');
            if (!p) return url;
            return url + (url.indexOf('?') !== -1 ? '&' : '?') + p;
        };

        // 媒体上传按钮点击事件
        var uploadButton = document.getElementById('upload-media-button');
        if (uploadButton) {
            uploadButton.addEventListener('click', function (e) {
                e.preventDefault();

                // 显示媒体上传模态框
                var modalId = 'media-upload-modal';
                var showFn = window['showModal_' + modalId];
                if (typeof showFn !== 'function' && window.parent && window.parent !== window) {
                    try {
                        showFn = window.parent['showModal_' + modalId];
                    } catch (e) { }
                }

                if (typeof showFn === 'function') {
                    showFn();
                    return;
                }

                if (typeof window.showModal === 'function') {
                    window.showModal();
                    return;
                }
                if (window.parent && window.parent !== window) {
                    try {
                        if (typeof window.parent.showModal === 'function') {
                            window.parent.showModal();
                            return;
                        }
                    } catch (e) { }
                }

                var domTool = window.TodoDOM || (typeof TodoDOM !== 'undefined' ? TodoDOM : { get: function (id) { return document.getElementById(id); } });
                var modal = domTool.get(modalId);
                if (!modal) {
                    modal = document.querySelector('.media-modal-overlay');
                }
                if (modal) {
                    modal.style.display = 'flex';
                    modal.classList.add('show');
                } else {
                    TodoCMS.Utils.showMessage('媒体上传模态框未找到，请检查页面是否正确加载。', 'error');
                }
            });
        } else {
            console.error('Upload media button not found');
        }

        // 处理媒体上传回调
        window.handleMediaUpload = function (selectedMedia) {
            if (selectedMedia && selectedMedia.length > 0) {
                // 刷新页面显示新上传的媒体
                location.reload();
            }
        };

        // 处理未记录文件的添加到媒体库功能
        function addUnrecordedFileToLibrary(filePath, fileName) {
            TodoModal.confirm({
                message: '确定要将文件 "' + fileName + '" 添加到媒体库吗？',
                onConfirm: function () {
                    var formData = new FormData();
                    formData.append('action', 'add_existing_file');
                    formData.append('file_path', filePath);
                    formData.append('_csrf_token', todoCSRF.getToken('media-library'));

                    fetch('<?php echo todo_admin_url('ajax/media-add-existing.php'); ?>', {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                TodoCMS.Utils.showMessage('文件已成功添加到媒体库！', 'success');
                                setTimeout(function () {
                                    location.reload();
                                }, 1000);
                            } else {
                                TodoCMS.Utils.showMessage('添加失败：' + (data.message || '未知错误'), 'error');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            TodoCMS.Utils.showMessage('添加文件时发生错误', 'error');
                        });
                }
            });
        }

        // 为添加到媒体库按钮绑定事件
        document.addEventListener('click', function (e) {
            if (e.target.classList.contains('add-to-library-btn')) {
                e.preventDefault();
                var filePath = e.target.getAttribute('data-file-path');
                var fileName = e.target.getAttribute('data-file-name');
                addUnrecordedFileToLibrary(filePath, fileName);
            }
        });

        // ESC键关闭模态框功能已由TodoModal接管

        // 编辑媒体按钮点击事件
        document.querySelectorAll('.edit-media').forEach(function (button) {
            button.addEventListener('click', function (e) {
                e.preventDefault();
                var mediaId = this.getAttribute('data-id');
                openMediaEditModal(mediaId);
            });
        });

        document.addEventListener('click', function (e) {
            var el = e.target;
            if (!el || !el.classList || !el.classList.contains('js-media-usage-recalc')) {
                return;
            }
            e.preventDefault();
            var mediaId = el.getAttribute('data-id');
            if (!mediaId) return;

            var nonceEl = document.querySelector('#csrf-token-form [name="_recalc_nonce"]');
            var nonceVal = nonceEl ? nonceEl.value : '';

            var fd = new FormData();
            fd.append('action', 'recalc_media_usage');
            fd.append('media_id', mediaId);
            if (nonceVal) fd.append('_recalc_nonce', nonceVal);

            el.textContent = '刷新中...';
            fetch('<?php echo todo_admin_url('ajax/media-usage-recalc.php'); ?>', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.success) {
                        el.textContent = '刷新';
                        TodoCMS.Utils.showMessage('刷新失败', 'error');
                        return;
                    }
                    var tr = el.closest('tr');
                    var cell = tr ? tr.querySelector('td.parent.column-parent') : null;
                    if (cell) {
                        var count = parseInt(data.usage_count, 10) || 0;
                        var text = count > 0 ? ('已使用 ' + count + ' 次') : '';
                        var firstTextNode = null;
                        for (var i = 0; i < cell.childNodes.length; i++) {
                            if (cell.childNodes[i].nodeType === 3) {
                                firstTextNode = cell.childNodes[i];
                                break;
                            }
                        }
                        if (firstTextNode) {
                            firstTextNode.nodeValue = text;
                        } else if (text) {
                            cell.insertBefore(document.createTextNode(text), cell.firstChild);
                        }
                    }
                    el.textContent = '刷新';
                })
                .catch(function () {
                    el.textContent = '刷新';
                    TodoCMS.Utils.showMessage('刷新失败', 'error');
                });
        });

        // 删除媒体按钮点击事件
        document.querySelectorAll('.delete-media').forEach(function (button) {
            button.addEventListener('click', function (e) {
                e.preventDefault();
                var mediaId = this.getAttribute('data-id');
                openMediaDeleteModal(mediaId);
            });
        });

        // 打开媒体编辑模态框
        function openMediaEditModal(mediaId) {
            // 先立即打开弹窗并显示 Loading 状态
            const modal = TodoModal.form({
                templateId: 'todo-tpl-media-edit-modal',
                title: '编辑媒体',
                size: 'xl',
                submitText: '保存更改',
                cancelText: '取消',
                onSubmit: function(formData, form) {
                    handleMediaSave(formData, form);
                    return false; // 阻止默认提交，由handleMediaSave处理
                }
            });
            modal.showLoading('加载媒体信息中...');

            // 获取媒体信息并填充表单
            fetch(addQueryArg('<?php echo todo_admin_url('ajax/media-get.php'); ?>', { media_id: mediaId }), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(response => response.json())
                .then(data => {
                    modal.hideLoading();
                    if (data.success) {
                        var form = modal.element.querySelector('form');
                        if (form) {
                            // 填充表单
                            var setVal = function(name, val) {
                                var el = form.querySelector('[name="' + name + '"]');
                                if (el) el.value = val;
                            };
                            
                            setVal('media_id', mediaId);
                            setVal('title', data.media.title || '');
                            
                            var titleInput = form.querySelector('[name="title"]');
                            if (titleInput && !data.media.title && data.media.file_name) {
                                titleInput.placeholder = data.media.file_name;
                            } else if (titleInput) {
                                titleInput.placeholder = '输入媒体标题';
                            }
                            
                            setVal('alt_text', data.media.alt || '');
                            setVal('caption', data.media.caption || '');
                            setVal('description', data.media.description || '');
                            setVal('seo_title', data.media.seo_title || '');
                            setVal('seo_description', data.media.seo_description || '');
                            setVal('seo_keywords', data.media.seo_keywords || '');

                            // 更新预览区域
                            updateMediaPreview(data.media, modal.element);
                        }
                    } else {
                        modal.close();
                        TodoCMS.Utils.showMessage('获取媒体信息失败: ' + (data.error || '未知错误'), 'error');
                    }
                })
                .catch(error => {
                    modal.hideLoading();
                    modal.close();
                    console.error('获取媒体信息时出错:', error);
                    TodoCMS.Utils.showMessage('获取媒体信息失败，请稍后重试', 'error');
                });
        }

        // 更新媒体预览
        function updateMediaPreview(media, container) {
            const previewContainer = container.querySelector('.media-preview-large');
            const filenameEl = container.querySelector('.media-filename');
            const filesizeEl = container.querySelector('.media-filesize');
            const dimensionsEl = container.querySelector('.media-dimensions');
            const uploadDateEl = container.querySelector('.media-upload-date');
            const pathEl = container.querySelector('.media-path');
            const contentReferencesEl = container.querySelector('.media-content-references');
            
            // Helper for creating elements in the correct document context
            const doc = container.ownerDocument || document;

            // 更新文件信息
            if (filenameEl) filenameEl.textContent = media.file_name || '';
            if (filesizeEl) {
                const fileSize = media.file_size || '未知';
                const storageInfo = media.storage_info ? ` [${media.storage_info}]` : '';
                filesizeEl.textContent = '大小: ' + fileSize + storageInfo;

                if (media.file_missing === true || fileSize === '文件丢失') {
                    filesizeEl.style.color = '#d63638';
                    filesizeEl.style.fontWeight = 'bold';
                    filesizeEl.title = '警告：原始文件已丢失，可能需要重新上传';
                } else {
                    filesizeEl.style.color = '#666';
                    filesizeEl.style.fontWeight = 'normal';
                    filesizeEl.title = '';
                }
                filesizeEl.style.fontSize = '13px';
            }
            if (dimensionsEl) {
                let width = null, height = null;

                if (media.dimensions && media.dimensions.width && media.dimensions.height) {
                    width = media.dimensions.width;
                    height = media.dimensions.height;
                } else if (media.width && media.height) {
                    width = media.width;
                    height = media.height;
                }

                if (width && height && width > 0 && height > 0) {
                    dimensionsEl.textContent = '分辨率: ' + width + ' × ' + height + ' 像素';
                } else {
                    if (media.mime_type && media.mime_type.startsWith('image/') && media.file_url) {
                        const tempImg = new Image();
                        tempImg.onload = function () {
                            if (this.naturalWidth && this.naturalHeight) {
                                dimensionsEl.textContent = '分辨率: ' + this.naturalWidth + ' × ' + this.naturalHeight + ' 像素';
                            }
                        };
                        tempImg.onerror = function () {
                            dimensionsEl.textContent = '分辨率: 无法获取';
                        };
                        tempImg.src = media.file_url;
                        dimensionsEl.textContent = '分辨率: 计算中...';
                    } else {
                        dimensionsEl.textContent = '分辨率: 未知';
                    }
                }
            }
            if (uploadDateEl) {
                uploadDateEl.textContent = '上传时间: ' + (media.upload_date || '未知');
            }

            if (pathEl && media.file_url) {
                pathEl.innerHTML = '文件路径: <a href="' + media.file_url + '" target="_blank" style="word-break: break-all;">' + media.file_url + '</a>';
            }

            // 更新内容引用信息
            if (contentReferencesEl) {
                if (media.content_references && media.content_references.length > 0) {
                    let referencesHtml = '<div style="padding-top: 20px;">';
                    referencesHtml += '内容引用:<br>';

                    media.content_references.forEach(function (ref) {
                        const text = ref.reference_text || ref.post_title || ref.label || '';

                        let linkHtml = '';
                        if (ref.url && typeof ref.url === 'string') {
                            linkHtml = '<a href="' + ref.url + '" target="_blank" rel="noopener">' + text + '</a>';
                        } else if (typeof ref.post_id !== 'undefined' && ref.post_id !== null && ref.type === 'post') {
                            linkHtml = '<a href="javascript:void(0);" onclick="editPost(' + ref.post_id + ')">' + text + '</a>';
                        } else {
                            linkHtml = '<span>' + text + '</span>';
                        }

                        referencesHtml += '<div style="font-size: 12px;">';
                        referencesHtml += linkHtml;
                        referencesHtml += '</div>';
                    });

                    referencesHtml += '</div>';
                    contentReferencesEl.innerHTML = referencesHtml;
                } else {
                    contentReferencesEl.innerHTML = '<div style="padding-top: 20px; color: #999; font-size: 12px;">未被任何内容引用</div>';
                }
            }

            // 更新预览内容
            if (previewContainer) {
                previewContainer.innerHTML = '';

                if (media.mime_type && media.mime_type.startsWith('image/')) {
                    const img = doc.createElement('img');
                    img.src = media.file_url;
                    img.alt = media.alt || media.title || '';
                    img.id = 'preview-image';
                    img.style.maxWidth = '100%';
                    img.style.height = 'auto';
                    previewContainer.appendChild(img);
                } else if (media.mime_type && media.mime_type.startsWith('video/')) {
                    const video = doc.createElement('video');
                    video.src = media.file_url;
                    video.controls = true;
                    video.style.maxWidth = '100%';
                    video.style.height = 'auto';
                    previewContainer.appendChild(video);
                } else {
                    const fileIcon = doc.createElement('div');
                    fileIcon.innerHTML = '<i class="toicon" style="font-size: 100px; margin-top: 100px;">📄</i><br><span>' + (media.file_name || '文件') + '</span>';
                    fileIcon.style.textAlign = 'center';
                    previewContainer.appendChild(fileIcon);
                }
            }
        }

        // 编辑文章函数
        window.editPost = function(postId) {
            // 根据文章ID跳转到对应的编辑页面
            window.open('index.php?page=post-edit&id=' + postId, '_blank');
        };
        
        // 尝试挂载到父窗口，解决 iframe 模态框跨域/跨窗口访问问题
        try {
            if (window.parent && window.parent !== window) {
                window.parent.editPost = window.editPost;
            }
        } catch (e) {}

        // 打开媒体删除确认模态框
        function openMediaDeleteModal(mediaId) {
            TodoModal.form({
                templateId: 'todo-tpl-media-delete-modal',
                title: '删除媒体',
                size: 'sm',
                type: 'danger',
                submitText: '确认删除',
                cancelText: '取消',
                onOpen: function(modal) {
                    var container = modal.element.querySelector('.todo-modal-body-content');
                    if (container) {
                        var input = container.querySelector('input[name="media_id"]');
                        if (input) input.value = mediaId;
                    }
                },
                onSubmit: function(formData, form) {
                    performMediaDelete(mediaId);
                    return true;
                }
            });
        }
        
        // 执行媒体删除操作
        function performMediaDelete(mediaId) {
            // 获取删除操作的CSRF token
            var csrfInput = TodoDOM.query('#csrf-token-form input[name="_delete_nonce"]');
            var csrfToken = csrfInput ? csrfInput.value : '';

            fetch('<?php echo todo_admin_url('ajax/media-delete.php'); ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'action=delete_media&media_id=' + mediaId + '&_delete_nonce=' + encodeURIComponent(csrfToken)
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        // 移除媒体项
                        var mediaItem = document.querySelector(`[data-id="${mediaId}"]`);
                        if (mediaItem) {
                            mediaItem.remove();
                        }
                        // 如果是列表视图，移除表格行
                        var tableRow = document.getElementById('post-' + mediaId);
                        if (tableRow) {
                            tableRow.remove();
                        }
                        // 如果页面没有媒体项了，刷新页面
                        if (document.querySelectorAll('.media-item').length === 0 &&
                            document.querySelectorAll('#the-list tr:not(.empty)').length === 0) {
                            location.reload();
                        }
                    } else {
                        TodoCMS.Utils.showMessage('删除失败: ' + (data.error || '未知错误'), 'error');
                    }
                })
                .catch(error => {
                    console.error('删除媒体时出错:', error);
                    TodoCMS.Utils.showMessage('删除失败，请稍后重试', 'error');
                });
        }

        // 保存媒体编辑
        function handleMediaSave(formData, form) {
            if (!formData) {
                 // 兼容旧调用方式或错误调用
                 console.error('handleMediaSave called without formData');
                 return;
            }
            
            var mediaId = formData.get('media_id');
            if (!mediaId) {
                TodoCMS.Utils.showMessage('请先选择要编辑的媒体文件', 'warning');
                return;
            }

            formData.append('action', 'update_media');

            // 确保CSRF token被包含 (通常已经在模板的隐藏域中了)
            if (!formData.has('_update_nonce')) {
                var csrfInput = TodoDOM.query('#csrf-token-form input[name="_update_nonce"]');
                if (csrfInput) {
                    formData.append('_update_nonce', csrfInput.value);
                }
            }

            fetch('<?php echo todo_admin_url('ajax/media-update.php'); ?>', {
                method: 'POST',
                credentials: 'include', // 确保发送cookie（会话信息）
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(data => {
                            throw new Error(data.error || '请求失败: ' + response.status);
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        // 关闭模态框 (由调用者处理，或者在这里关闭所有)
                        TodoModal.closeAll(); // 确保关闭
                        // 刷新页面
                        location.reload();
                    } else {
                        TodoCMS.Utils.showMessage('更新媒体失败: ' + (data.error || '未知错误'), 'error');
                    }
                })
                .catch(error => {
                    console.error('更新媒体时出错:', error);
                    TodoCMS.Utils.showMessage('更新媒体失败，请稍后重试: ' + error.message, 'error');
                });
        }

        // 批量操作确认
        const postsFilter = document.getElementById('posts-filter');
        if (postsFilter) {
            postsFilter.addEventListener('submit', function (e) {
                const bulkActionTop = document.getElementById('bulk-action-selector-top');
                const checkedBoxes = document.querySelectorAll('input[name="media[]"]:checked');

                // 获取当前选中的批量操作值
                let selectedAction = '';
                if (bulkActionTop && bulkActionTop.value !== '-1') {
                    selectedAction = bulkActionTop.value;
                }

                if (selectedAction === 'delete') {
                    if (checkedBoxes.length === 0) {
                        TodoCMS.Utils.showMessage('请先选择要删除的媒体文件。', 'warning');
                        e.preventDefault();
                        return;
                    }
                    e.preventDefault();
                    
                    // 使用 TodoModal.deleteConfirm，modal.js 中的代理会自动处理跨框架调用
                    TodoModal.deleteConfirm({
                        title: '确认删除媒体',
                        message: '确定要删除所选的 ' + checkedBoxes.length + ' 个媒体文件吗？',
                        onConfirm: function () {
                            postsFilter.submit();
                        }
                    });
                } else if (selectedAction === '' || selectedAction === '-1') {
                    // 如果没有选择操作但有选中的项目，提示用户
                    if (checkedBoxes.length > 0) {
                        TodoCMS.Utils.showMessage('请选择要执行的批量操作。', 'warning');
                        e.preventDefault();
                    } else {
                        // 既没选操作也没选项目，通常不需要提示，或者提示"请选择项目"
                        // 但为了用户体验，如果有submit被触发，最好提示
                        TodoCMS.Utils.showMessage('请选择批量操作和媒体文件。', 'warning');
                        e.preventDefault();
                    }
                }
            });
        }

        // 关闭模态框
        document.querySelectorAll('.todo-modal-close').forEach(function (element) {
            element.addEventListener('click', function () {
                var modal = this.closest('.todo-modal-overlay');
                if (modal) {
                    modal.style.display = 'none';
                    modal.classList.remove('show');
                }
            });
        });

        // 全选/取消全选
        document.querySelectorAll('#cb-select-all').forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                var isChecked = this.checked;
                document.querySelectorAll('input[name="media[]"]').forEach(function (item) {
                    item.checked = isChecked;
                });
            });
        });

        // =============================================
        // 未附加媒体查询（单击全量刷新 + 分页展示）
        // =============================================
        (function () {
            var unrecitedInProgress = false;
            var unrecitedAllMedia = [];
            var UNRECITED_PER_PAGE = 50;

            var queryArea = document.getElementById('unrecited-query-area');
            var startBtn = document.getElementById('start-unrecited-query');
            var placeholderRow = document.getElementById('unrecited-placeholder');
            var progressDiv = document.getElementById('unrecited-progress');
            var statusText = document.getElementById('unrecited-status-text');
            var progressBar = document.getElementById('unrecited-progress-bar');
            var countSubtitle = document.getElementById('media-count-subtitle');

            if (!startBtn || !queryArea) return;

            var recalcUrl = '<?php echo todo_admin_url('ajax/media-batch-recalc.php'); ?>';

            startBtn.addEventListener('click', function () {
                if (unrecitedInProgress) return;
                unrecitedInProgress = true;
                unrecitedAllMedia = [];

                // 重置搜索框与 URL 中的搜索关键词
                var searchInput = document.querySelector('input[name="s"]');
                if (searchInput) searchInput.value = '';
                if (window.history && window.history.replaceState) {
                    var u = new URL(window.location.href);
                    if (u.searchParams.has('s')) {
                        u.searchParams.delete('s');
                        window.history.replaceState(null, '', u.toString());
                    }
                }

                startBtn.style.display = 'none';
                progressDiv.style.display = 'block';
                statusText.innerHTML = '正在全量刷新所有媒体引用计数 <i class="myload">.</i>';
                progressBar.style.width = '35%';

                // 一次 POST，全量刷新
                fetch(recalcUrl, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || !data.success) {
                            throw new Error(data && data.error ? data.error : '刷新失败');
                        }
                        statusText.innerHTML = '刷新完成（' + data.elapsed + '秒），正在查询未附加媒体文件 <i class="myload">.</i>';
                        progressBar.style.width = '80%';
                        return fetchUnrecitedPage(1, true);
                    })
                    .then(function (firstResult) {
                        if (!firstResult) return;

                        var totalItems = firstResult.pagination.total_items;
                        var totalPages = firstResult.pagination.total_pages;

                        if (totalItems === 0) {
                            statusText.textContent = '没有找到未附加的媒体文件。';
                            progressBar.style.width = '100%';
                            updateSubtitle(0);
                            unrecitedInProgress = false;
                            return;
                        }

                        unrecitedAllMedia = firstResult.media.slice();
                        renderUnrecitedRows(firstResult.media, true);

                        if (totalPages <= 1) {
                            statusText.textContent = '查询完成，共 ' + totalItems + ' 个未附加媒体文件。';
                            progressBar.style.width = '100%';
                            updateSubtitle(totalItems);
                            unrecitedInProgress = false;
                            return;
                        }

                        var currentPage = 2;
                        progressBar.style.width = '100%';
                        statusText.textContent = '正在加载第 2/' + totalPages + ' 页...';

                        function fetchNextPage() {
                            if (currentPage > totalPages) {
                                statusText.textContent = '查询完成，共 ' + totalItems + ' 个未附加媒体文件。';
                                updateSubtitle(totalItems);
                                unrecitedInProgress = false;
                                return;
                            }

                            statusText.textContent = '正在加载第 ' + currentPage + '/' + totalPages + ' 页...';

                            return fetchUnrecitedPage(currentPage, false)
                                .then(function (result) {
                                    if (!result) return;
                                    unrecitedAllMedia = unrecitedAllMedia.concat(result.media);
                                    renderUnrecitedRows(result.media, false);
                                    currentPage++;
                                    return new Promise(function (resolve) {
                                        setTimeout(function () { resolve(fetchNextPage()); }, 50);
                                    });
                                });
                        }

                        return fetchNextPage();
                    })
                    .catch(function (err) {
                        console.error('未附加查询错误:', err);
                        statusText.textContent = '查询失败：' + (err.message || '未知错误') + '，请重试。';
                        statusText.style.color = '#d63638';
                        startBtn.style.display = 'inline-block';
                        unrecitedInProgress = false;
                    });
            });

            /**
             * 调用 media-library.php 获取指定页的未附加媒体
             * @param {number} page
             * @param {boolean} returnTotal 是否返回总数（只第一次请求需要）
             * @returns {Promise<object>}
             */
            function fetchUnrecitedPage(page, returnTotal) {
                var params = new URLSearchParams();
                params.set('action', 'get_media');
                params.set('type', 'unattached');
                params.set('page', page);
                params.set('per_page', UNRECITED_PER_PAGE);
                params.set('orderby', 'date');
                params.set('order', 'DESC');

                <?php if ($current_site_scope_id > 0): ?>
                params.set('site', '<?php echo (int) $current_site_scope_id; ?>');
                <?php endif; ?>

                var baseUrl = '<?php echo todo_admin_url('ajax/media-library.php'); ?>';
                return fetch(addQueryArg(baseUrl, params.toString()), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (r) {
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        return r.json();
                    })
                    .then(function (data) {
                        if (!data || !data.success) {
                            throw new Error(data && data.error ? data.error : '查询失败');
                        }
                        return {
                            media: data.media || [],
                            pagination: data.pagination || { total_items: 0, total_pages: 0 }
                        };
                    });
            }

            /**
             * 渲染媒体行到表格
             * @param {Array} mediaList
             * @param {boolean} isFirst 是否首次渲染（需要清除占位行）
             */
            function renderUnrecitedRows(mediaList, isFirst) {
                var tbody = document.getElementById('the-list');
                if (!tbody) return;

                if (isFirst && placeholderRow) {
                    placeholderRow.remove();
                }

                mediaList.forEach(function (item) {
                    var tr = document.createElement('tr');
                    tr.id = 'post-' + item.id;

                    var isImage = item.type && item.type.indexOf('image/') === 0;
                    var imgHtml = isImage
                        ? '<img src="' + escapeHtml(item.url) + '" alt="' + escapeHtml(item.title || '') + '" style="max-width: 100px;max-height: 50px;">'
                        : '<span style="font-size: 25px;color: #a0a0aa;overflow: hidden;display: flex;"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"></path><g fill="none"><path fill="currentColor" d="m15.393 4.054l-.502.557zm3.959 3.563l-.502.557zm2.302 2.537l-.685.305zM3.172 20.828l.53-.53zm17.656 0l-.53-.53zM14 21.25h-4v1.5h4zM2.75 14v-4h-1.5v4zm18.5-.437V14h1.5v-.437zM14.891 4.61l3.959 3.563l1.003-1.115l-3.958-3.563zm7.859 8.952c0-1.689.015-2.758-.41-3.714l-1.371.61c.266.598.281 1.283.281 3.104zm-3.9-5.389c1.353 1.218 1.853 1.688 2.119 2.285l1.37-.61c-.426-.957-1.23-1.66-2.486-2.79zM10.03 2.75c1.582 0 2.179.012 2.71.216l.538-1.4c-.852-.328-1.78-.316-3.248-.316zm5.865.746c-1.086-.977-1.765-1.604-2.617-1.93l-.537 1.4c.532.204.98.592 2.15 1.645zM10 21.25c-1.907 0-3.261-.002-4.29-.14c-1.005-.135-1.585-.389-2.008-.812l-1.06 1.06c.748.75 1.697 1.081 2.869 1.239c1.15.155 2.625.153 4.489.153zM1.25 14c0 1.864-.002 3.338.153 4.489c.158 1.172.49 2.121 1.238 2.87l1.06-1.06c-.422-.424-.676-1.004-.811-2.01c-.138-1.027-.14-2.382-.14-4.289zM14 22.75c1.864 0 3.338.002 4.489-.153c1.172-.158 2.121-.49 2.87-1.238l-1.06-1.06c-.424.422-1.004.676-2.01.811c-1.027.138-2.382.14-4.289.14zM21.25 14c0 1.907-.002 3.262-.14 4.29c-.135 1.005-.389 1.585-.812 2.008l1.06 1.06c.75-.748 1.081-1.697 1.239-2.869c.155-1.15.153-2.625.153-4.489zm-18.5-4c0-1.907.002-3.261.14-4.29c.135-1.005.389-1.585.812-2.008l-1.06-1.06c-.75.748-1.081 1.697-1.239 2.869C1.248 6.661 1.25 8.136 1.25 10zm7.28-8.75c-1.875 0-3.356-.002-4.511.153c-1.177.158-2.129.49-2.878 1.238l1.06 1.06c.424-.422 1.005-.676 2.017-.811c1.033-.138 2.395-.14 4.312-.14z"></path><path stroke="currentColor" stroke-width="1.5" d="M13 2.5V5c0 2.357 0 3.536.732 4.268S15.643 10 18 10h4"></path></g></svg></span>';

                    var title = item.title || item.url || '';
                    var fileName = title.split('/').pop() || title;

                    tr.innerHTML = '<?php if ($is_platform_admin): ?>'
                        + '<td><input id="cb-select-' + item.id + '" type="checkbox" name="media[]" value="' + item.id + '"></td>'
                        + '<?php endif; ?>'
                        + '<td class="title column-title column-primary" data-colname="文件">'
                        + '<div class="flex gap-3">'
                        + '<a href="javascript:void(0);" class="edit-media" data-id="' + item.id + '">'
                        + '<div class="flex-item" style="width: 110px;min-height: 35px;background-color: #ddd;border-radius: 3px;display: flex;align-items: center;justify-content: center;">'
                        + imgHtml
                        + '</div></a>'
                        + '<div class="flex-item">'
                        + '<a href="javascript:void(0);" class="edit-media" data-id="' + item.id + '">' + escapeHtml(fileName) + '</a>'
                        + '<div class="row-actions">'
                        + '<span class="edit"><a href="javascript:void(0);" class="edit-media" data-id="' + item.id + '">编辑</a></span>'
                        + '<?php if ($is_platform_admin): ?>'
                        + '<span class="delete"> | <a href="javascript:void(0);" class="delete-media submitdelete" data-id="' + item.id + '">永久删除</a></span>'
                        + '<?php endif; ?>'
                        + '</div></div></div></td>'
                        + '<td class="author column-author" data-colname="作者">管理员</td>'
                        + '<td class="parent column-parent" data-colname="附加到" style="font-size: 12px;">'
                        + '<div class="row-actions"><a href="javascript:void(0);" class="js-media-usage-recalc" data-id="' + item.id + '">刷新</a></div>'
                        + '</td>'
                        + '<td><span class="tb-meta">' + escapeHtml(item.date || '') + '</span></td>';

                    tbody.appendChild(tr);

                    // 绑定 edit-media 事件
                    tr.querySelectorAll('.edit-media').forEach(function (btn) {
                        btn.addEventListener('click', function (e) {
                            e.preventDefault();
                            openMediaEditModal(item.id);
                        });
                    });
                    // 绑定 delete-media 事件
                    tr.querySelectorAll('.delete-media').forEach(function (btn) {
                        btn.addEventListener('click', function (e) {
                            e.preventDefault();
                            openMediaDeleteModal(item.id);
                        });
                    });
                });
            }

            function updateSubtitle(count) {
                if (countSubtitle) {
                    countSubtitle.innerHTML = count + ' 个媒体文件<span style="color:#999;font-size:12px;">（未附加：没有被任何内容使用）</span>';
                }
            }

            function escapeHtml(str) {
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(str));
                return div.innerHTML;
            }
        })();

        // 辅助函数：选择元素
        function selected(value, compare) {
            return value === compare ? 'selected' : '';
        }
    </script>

<?php endif; // 结束权限检查 ?>
