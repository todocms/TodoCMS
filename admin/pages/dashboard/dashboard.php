<?php
/**
 * 管理后台首页
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('TODO_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}
// 性能计时（微成本），用于定位慢点（写入到PHP error_log）
$__t0 = microtime(true);
register_shutdown_function(function () use ($__t0) {
    $elapsed = microtime(true) - $__t0;
    if ($elapsed > 1.0) { // 超过1秒才记录，避免噪音
        error_log('[TodoCMS] admin dashboard elapsed: ' . number_format($elapsed, 3) . 's');
    }
});

// 获取站点统计信息（增加瞬时缓存，降低频繁统计的数据库压力）
$__stats_cache_key = 'todo_dashboard_stats_v4';
$__stats = function_exists('get_transient') ? get_transient($__stats_cache_key) : false;
if ($__stats === false || !is_array($__stats)) {
    $__tags_count = 0;
    if (class_exists('CMS_DB') && isset($GLOBALS['tododb']) && $GLOBALS['tododb'] instanceof CMS_DB && method_exists($GLOBALS['tododb'], 'table_exists') && $GLOBALS['tododb']->table_exists('tags')) {
        try {
            $__tags_count = (int) $GLOBALS['tododb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['tododb']->prefix}tags");
        } catch (Exception $e) {
            $__tags_count = 0;
        }
    } elseif (class_exists('CMS_Tags_Manager')) {
        try {
            $tags_manager = new CMS_Tags_Manager();
            $__tags_count = (int) $tags_manager->get_tags_count(['status' => '']);
        } catch (Exception $e) {
            $__tags_count = 0;
        }
    } else {
        $__tags_count = count_terms('tag');
        if ($__tags_count === 0) {
            $__tags_count = count_terms('post_tag');
        }
    }
    $p_counts = count_posts('any');
    $__stats = array(
        'posts' => (int) ($p_counts->publish + $p_counts->draft + $p_counts->pending + $p_counts->scheduled + $p_counts->warehouse),
        'comments' => count_comments(),
        'users' => count_users(),
        'categories' => (int) $GLOBALS['tododb']->get_var("
            SELECT COUNT(*) FROM {$GLOBALS['tododb']->prefix}term_taxonomy tt
            INNER JOIN {$GLOBALS['tododb']->prefix}terms t ON tt.term_id = t.term_id
            WHERE tt.taxonomy = 'category'
            AND NOT EXISTS (
                SELECT 1 FROM {$GLOBALS['tododb']->prefix}term_attributes
                WHERE term_id = t.term_id AND JSON_UNQUOTE(JSON_EXTRACT(attributes, '$.trashed')) = '1'
            )
        "),
        'tags' => $__tags_count,
    );
    if (function_exists('set_transient')) {
        set_transient($__stats_cache_key, $__stats, 60); // 1分钟，确保数据接近实时且避免每次刷新重复统计
    }
}
$total_posts = $__stats['posts'];
$total_comments = $__stats['comments'];
$total_users = $__stats['users'];
$total_categories = $__stats['categories'];
$total_tags = $__stats['tags'];

// 获取最近发布的文章（优化查询，减少内存使用）
// 注意：不使用 fields 限制，因为可能有问题，直接使用默认查询
$recent_posts = get_posts(array(
    'post_type' => 'any',
    'post_status' => array('publish', 'draft', 'future', 'pending'), // 显示所有非回收站状态
    'posts_per_page' => 10,
    'orderby' => 'post_date',
    'order' => 'DESC',
    'load_meta' => false,  // 不加载元数据
    'load_terms' => false  // 不加载分类和标签
));

// 批量查询 guestbook/message 帖子的联系人姓名（避免循环内 N+1 查询）
$guestbook_author_map = [];
if (!empty($recent_posts) && isset($GLOBALS['tododb'])) {
    $gb_ids = [];
    foreach ($recent_posts as $p) {
        if (in_array($p->post_type, ['guestbook', 'message'], true) && empty($p->post_author)) {
            $gb_ids[] = (int) $p->ID;
        }
    }
    if (!empty($gb_ids)) {
        try {
            $ph = implode(',', array_fill(0, count($gb_ids), '?'));
            // 从 post_custom_fields 查 contact_name
            $cf_rows = $GLOBALS['tododb']->get_results(
                "SELECT post_id, field_value FROM {$GLOBALS['tododb']->prefix}post_custom_fields WHERE post_id IN ({$ph}) AND field_key = 'contact_name'",
                $gb_ids
            );
            if (is_array($cf_rows)) {
                foreach ($cf_rows as $r) {
                    if (is_object($r) && !empty($r->field_value)) {
                        $guestbook_author_map[(int) $r->post_id] = (string) $r->field_value;
                    }
                }
            }
            // 补充：从 model_fields_guestbook 查 contact_name（覆盖可能的遗漏）
            $mf_rows = $GLOBALS['tododb']->get_results(
                "SELECT post_id, contact_name FROM {$GLOBALS['tododb']->prefix}model_fields_guestbook WHERE post_id IN ({$ph})",
                $gb_ids
            );
            if (is_array($mf_rows)) {
                foreach ($mf_rows as $r) {
                    if (is_object($r) && !empty($r->contact_name)) {
                        $pid = (int) $r->post_id;
                        if (!isset($guestbook_author_map[$pid])) {
                            $guestbook_author_map[$pid] = (string) $r->contact_name;
                        }
                    }
                }
            }
        } catch (\Exception $ignored) {}
    }
}
$recent_comments = [];
if (class_exists('Todo_Comment_Manager')) {
    $recent_comments = Todo_Comment_Manager::get_comments([
        'limit' => 4,
        'offset' => 0,
        'status' => ['0', '1'],
        'type' => ['', 'comment', 'review', 'inquiry', 'guestbook', 'message', 'reply'],
        'order_by' => 'created_at',
        'order' => 'DESC',
        'include_meta' => false,
        'include_children' => false,
    ]);
} else {
    $recent_comments = get_comments(array(
        'number' => 4,
        'status' => 'approve',
        'orderby' => 'comment_date',
        'order' => 'DESC'
    ));
}

// 获取系统信息
$mysql_version = 'Unknown';
if (isset($GLOBALS['tododb']) && $GLOBALS['tododb']) {
    try {
        $mysql_version = $GLOBALS['tododb']->get_var("SELECT VERSION()");
    } catch (Exception $e) {
        $mysql_version = 'Error';
    }
} elseif (function_exists('todo_get_mysql_version')) {
    $mysql_version = todo_get_mysql_version();
}

$__ini_to_bytes = function ($val) {
    $val = trim((string) $val);
    if ($val === '') {
        return 0;
    }
    $last = strtolower(substr($val, -1));
    $num = (float) $val;
    if ($last === 'g') {
        return (int) round($num * 1024 * 1024 * 1024);
    }
    if ($last === 'm') {
        return (int) round($num * 1024 * 1024);
    }
    if ($last === 'k') {
        return (int) round($num * 1024);
    }
    return (int) round($num);
};
$__bytes_to_human = function ($bytes) {
    $bytes = (int) $bytes;
    if ($bytes <= 0) {
        return '0';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $v = (float) $bytes;
    while ($v >= 1024 && $i < count($units) - 1) {
        $v /= 1024;
        $i++;
    }
    $precision = $i === 0 ? 0 : 1;
    return rtrim(rtrim(number_format($v, $precision, '.', ''), '0'), '.') . $units[$i];
};
$__upload_max_filesize_raw = ini_get('upload_max_filesize');
$__post_max_size_raw = ini_get('post_max_size');
$__upload_max_bytes = $__ini_to_bytes($__upload_max_filesize_raw);
$__post_max_bytes = $__ini_to_bytes($__post_max_size_raw);
$__effective_upload_bytes = 0;
if ($__upload_max_bytes > 0 && $__post_max_bytes > 0) {
    $__effective_upload_bytes = min($__upload_max_bytes, $__post_max_bytes);
} elseif ($__upload_max_bytes > 0) {
    $__effective_upload_bytes = $__upload_max_bytes;
} elseif ($__post_max_bytes > 0) {
    $__effective_upload_bytes = $__post_max_bytes;
}

$__todo_max_upload_mb = null;
if (function_exists('get_option')) {
    $__todo_max_upload_mb = get_option('max_upload_size', null);
}
$__todo_max_upload_bytes = 0;
if ($__todo_max_upload_mb !== null && is_numeric($__todo_max_upload_mb) && (float) $__todo_max_upload_mb > 0) {
    $__todo_max_upload_bytes = (int) round(((float) $__todo_max_upload_mb) * 1024 * 1024);
}
$__effective_overall_upload_bytes = $__effective_upload_bytes;
if ($__todo_max_upload_bytes > 0 && $__effective_overall_upload_bytes > 0) {
    $__effective_overall_upload_bytes = min($__effective_overall_upload_bytes, $__todo_max_upload_bytes);
} elseif ($__todo_max_upload_bytes > 0) {
    $__effective_overall_upload_bytes = $__todo_max_upload_bytes;
}

$system_info = array(
    'cms_version' => TODO_VERSION,
    'php_version' => phpversion(),
    'mysql_version' => $mysql_version,
    'server_software' => $_SERVER['SERVER_SOFTWARE'],
    'user_agent' => $_SERVER['HTTP_USER_AGENT'],
    'memory_limit' => ini_get('memory_limit'),
    'upload_max_filesize' => (string) $__upload_max_filesize_raw . '（服务器有效上限：' . $__bytes_to_human($__effective_upload_bytes) . '）',
    'max_upload_size' => (($__todo_max_upload_bytes > 0) ? ((string) $__todo_max_upload_mb . 'MB') : '未设置') . '（最终有效上限：' . $__bytes_to_human($__effective_overall_upload_bytes) . '）',
    'max_execution_time' => ini_get('max_execution_time') . ' 秒'
);

// 获取当前用户（todocms风格函数）
$current_user = todo_get_current_user();

// 如果$current_user不是对象，尝试手动初始化
if (!is_object($current_user) && is_user_logged_in()) {
    $user_id = get_current_user_id();
    if ($user_id > 0) {
        $current_user = new CMS_User();
        $current_user->init($user_id);
    }
}

// 异步检查站点更新，避免阻塞首页加载
$update_nonce = Todo_Security_Manager::generate_csrf_token('dashboard_updates');
$total_updates = 0; // 异步计算

// 显示欢迎消息
$current_time = current_time('timestamp');
$current_hour = (int) date('G', $current_time);

if ($current_hour >= 5 && $current_hour < 12) {
    $greeting = '早上好';
} elseif ($current_hour >= 12 && $current_hour < 18) {
    $greeting = '下午好';
} else {
    $greeting = '晚上好';
}
?>

<div class="admin-dashboard">
    <?php
    // 在首页加载时，直接读取上一次检查的更新缓存
    // 如果发现有核心更新，并且启用了自动更新，立即进行跳转
    $opt_auto_enabled = function_exists('todo_get_option') ? (int)todo_get_option('todo_auto_update_enabled') : 0;
    if ($opt_auto_enabled) {
        $lastCheckPath = rtrim((string) ABSPATH, '/') . '/content/updates/last_check.json';
        if (file_exists($lastCheckPath)) {
            $lastData = json_decode(@file_get_contents($lastCheckPath), true);
            if (is_array($lastData) && !empty($lastData['has_update'])) {
                $latest_version = $lastData['update_info']['version'] ?? 'unknown';
                echo '<script type="text/javascript">
                (function() {
                    var redirectKey = "todo_auto_update_redirected_' . htmlspecialchars($latest_version, ENT_QUOTES) . '";
                    if (!sessionStorage.getItem(redirectKey)) {
                        sessionStorage.setItem(redirectKey, "1");
                        window.location.href = ' . json_encode(todo_admin_url('index.php?page=updates')) . ';
                    }
                })();
                </script>';
            }
        }
    }
    ?>
    <div id="update-notice" class="hidden">
        <p>正在检查更新，请稍候…</p>
    </div>
    <script type="text/javascript">
        (function () {
            var nonce = <?php echo json_encode($update_nonce); ?>;
            var notice = document.getElementById('update-notice');
            function htmlEscape(str) { return String(str).replace(/[&<>\"]/g, function (s) { return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;" })[s]; }); }

            // 版本比较函数
            function versionCompare(v1, v2) {
                if (!v1 || !v2) return 0;
                var v1parts = String(v1).split('.'), v2parts = String(v2).split('.');
                for (var i = 0; i < Math.max(v1parts.length, v2parts.length); ++i) {
                    var val1 = parseInt(v1parts[i] || 0), val2 = parseInt(v2parts[i] || 0);
                    if (val1 > val2) return 1;
                    if (val1 < val2) return -1;
                }
                return 0;
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'admin-ajax.php', true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function () {
                try {
                    var res = JSON.parse(xhr.responseText || '{}');
                    if (res && res.success) {
                        var core = res.data.core || null;

                        // 校验核心版本是否真的更新
                        // 1. 排除未配置更新服务器或无下载地址的情况（确保不是假数据）
                        // 2. 确保最新版本严格大于当前版本
                        if (core) {
                            if (!core.latest || !core.current || !core.download_url || versionCompare(core.latest, core.current) <= 0) {
                                core = null;
                            }
                        }

                        var plugins = res.data.plugins || {};
                        var pluginCount = plugins ? Object.keys(plugins).length : 0;
                        var total = (core ? 1 : 0) + pluginCount;
                        if (total > 0) {
                            var autoUpdateEnabled = <?php echo (function_exists('todo_get_option') && todo_get_option('todo_auto_update_enabled')) ? 'true' : 'false'; ?>;
                            if (autoUpdateEnabled && core) {
                                var redirectKey = 'todo_auto_update_redirected_' + core.latest;
                                if (!sessionStorage.getItem(redirectKey)) {
                                    sessionStorage.setItem(redirectKey, '1');
                                    window.location.href = <?php echo json_encode(todo_admin_url('index.php?page=updates')); ?>;
                                    return;
                                }
                            }

                            notice.className = 'notice notice-warning is-dismissible';
                            var html = '<p><strong>更新可用:</strong> ';
                            if (core) { html += '有新版本的 CMS 核心可用（最新 ' + htmlEscape(core.latest) + "）。 "; }
                            if (pluginCount > 0) { html += '有 ' + pluginCount + ' 个插件需要更新。 '; }
                            html += '<a href="' + <?php echo json_encode(todo_admin_url('index.php?page=updates')); ?> + '">查看详情</a></p>';
                            notice.innerHTML = html + '<button type="button" class="notice-dismiss">✕</button>';
                        }
                    }
                } catch (e) {
                    notice.className = 'notice notice-error is-dismissible';
                    var errMsg = '更新检查响应解析失败';
                    // 在开发环境显示详细错误信息
                    if (xhr.responseText && xhr.responseText.length < 500) {
                        errMsg += ': ' + htmlEscape(xhr.responseText.substring(0, 200));
                    }
                    notice.innerHTML = '<p>' + errMsg + '</p>';
                    console.error('Update check parse error:', e, 'Response:', xhr.responseText);
                }
            };
            xhr.onerror = function () {
                notice.className = 'notice notice-error is-dismissible';
                notice.innerHTML = '<p>网络错误，无法检查更新。</p>';
            };
            // 通过统一AJAX入口调用，需要传递action参数
            xhr.send('action=get_updates&_todononce=' + encodeURIComponent(nonce));
        })();
    </script>

    <div class="admin-welcome-panel admin-card">
        <div class="admin-card-content">
            <h2>
                <?php echo $greeting; ?>，<?php echo is_object($current_user) ? $current_user->get_display_name() : 'Guest'; ?>！
            </h2>
            <p class="about-description">欢迎使用您的网站管理后台！</p>

            <div class="welcome-panel-column-container">
                <div class="welcome-panel-column">
                    <h3>快速开始</h3>
                    <ul>
                        <?php if (current_user_can('edit_posts') || current_user_can('publish_posts')): ?>
                            <li><a href="<?php echo todo_admin_url('index.php?page=post-new'); ?>">写一篇文章</a></li>
                        <?php endif; ?>
                        <?php if (current_user_can('manage_themes') || current_user_can('switch_themes') || current_user_can('edit_theme_options') || current_user_can('customize')): ?>
                            <li><a href="<?php echo todo_admin_url('index.php?page=themes'); ?>">更改您的主题</a></li>
                        <?php endif; ?>
                        <?php if (current_user_can('manage_options')): ?>
                            <li><a href="<?php echo todo_admin_url('index.php?page=settings'); ?>">配置网站设置</a></li>
                        <?php endif; ?>
                        <li><a href="../" target="_blank">查看您的网站</a></li>
                    </ul>
                </div>
                <div class="welcome-panel-column">
                    <h3>更多操作</h3>
                    <ul>
                        <li>
                            <?php if (current_user_can('edit_posts') || current_user_can('publish_posts')): ?>
                                <a href="<?php echo todo_admin_url('index.php?page=posts'); ?>">查看文章</a>
                            <?php else: ?>
                                <a href="../" target="_blank">查看文章</a>
                            <?php endif; ?>
                        </li>
                        <?php if (current_user_can('manage_plugins') || current_user_can('activate_plugins') || current_user_can('install_plugins') || current_user_can('update_plugins') || current_user_can('delete_plugins')): ?>
                            <li><a href="<?php echo todo_admin_url('index.php?page=plugins'); ?>">管理插件</a></li>
                        <?php endif; ?>
                        <?php if (current_user_can('list_users') || current_user_can('create_users') || current_user_can('edit_users') || current_user_can('delete_users') || current_user_can('promote_users') || current_user_can('manage_users')): ?>
                            <li><a href="<?php echo todo_admin_url('index.php?page=users'); ?>">管理用户</a></li>
                        <?php endif; ?>
                        <?php if (current_user_can('manage_options')): ?>
                            <li><a href="<?php echo todo_admin_url('index.php?page=menus'); ?>">菜单管理</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="welcome-panel-column welcome-panel-last">
                    <h3>了解更多</h3>
                    <ul>
                        <li><a href="<?php echo todo_admin_url('index.php?page=template-tags'); ?>">文档</a></li>
                        <li><a href="http://todocms.com/" target="_blank">关于 TodoCMS</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="admin-dashboard-widgets">
        <div class="admin-dashboard-column">
            <?php if (current_user_can('view_stats') || current_user_can('manage_options')): ?>
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">概览</h3>
                </div>
                <div class="admin-card-content">
                    <ul class="admin-stats">
                        <li>
                            <?php if (current_user_can('edit_posts')): ?>
                            <a href="<?php echo todo_admin_url('index.php?page=posts'); ?>">
                                <span
                                    class="admin-stats-count"><?php echo is_object($total_posts) ? $total_posts->publish : $total_posts; ?></span>
                                <span class="admin-stats-label">文章</span>
                            </a>
                            <?php else: ?>
                            <a href="javascript:void(0);">
                                <span class="admin-stats-count"><?php echo is_object($total_posts) ? $total_posts->publish : $total_posts; ?></span>
                                <span class="admin-stats-label">文章</span>
                            </a>
                            <?php endif; ?>
                        </li>
                        <li>
                            <?php if (current_user_can('moderate_comments')): ?>
                            <a href="<?php echo todo_admin_url('index.php?page=comments'); ?>">
                                <span
                                    class="admin-stats-count"><?php echo intval($total_comments); ?></span>
                                <span class="admin-stats-label">评论</span>
                            </a>
                            <?php else: ?>
                            <a href="javascript:void(0);">
                                <span class="admin-stats-count"><?php echo intval($total_comments); ?></span>
                                <span class="admin-stats-label">评论</span>
                            </a>
                            <?php endif; ?>
                        </li>
                        <li>
                            <?php if (current_user_can('list_users')): ?>
                            <a href="<?php echo todo_admin_url('index.php?page=users'); ?>">
                                <span class="admin-stats-count"><?php echo $total_users; ?></span>
                                <span class="admin-stats-label">用户</span>
                            </a>
                            <?php else: ?>
                            <a href="javascript:void(0);">
                                <span class="admin-stats-count"><?php echo $total_users; ?></span>
                                <span class="admin-stats-label">用户</span>
                            </a>
                            <?php endif; ?>
                        </li>
                        <li>
                            <?php if (current_user_can('manage_categories')): ?>
                            <a href="<?php echo todo_admin_url('index.php?page=categories'); ?>">
                                <span class="admin-stats-count"><?php echo $total_categories; ?></span>
                                <span class="admin-stats-label">分类</span>
                            </a>
                            <?php else: ?>
                            <a href="javascript:void(0);">
                                <span class="admin-stats-count"><?php echo $total_categories; ?></span>
                                <span class="admin-stats-label">分类</span>
                            </a>
                            <?php endif; ?>
                        </li>
                        <li>
                            <?php if (current_user_can('manage_categories')): ?>
                            <a href="<?php echo todo_admin_url('index.php?page=tags'); ?>">
                                <span class="admin-stats-count"><?php echo $total_tags; ?></span>
                                <span class="admin-stats-label">标签</span>
                            </a>
                            <?php else: ?>
                            <a href="javascript:void(0);">
                                <span class="admin-stats-count"><?php echo $total_tags; ?></span>
                                <span class="admin-stats-label">标签</span>
                            </a>
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">最近发布的内容</h3>
                </div>
                <div class="admin-card-content">
                    <?php if (!empty($recent_posts)): ?>
                        <ul class="admin-list">
                            <?php foreach ($recent_posts as $post): ?>
                                <?php
                                $author = get_user_by('id', $post->post_author);
                                if ($author) {
                                    $author_name = $author->get_display_name();
                                } elseif (isset($guestbook_author_map[$post->ID])) {
                                    $author_name = $guestbook_author_map[$post->ID];
                                } elseif (in_array($post->post_type, ['guestbook', 'message'], true)) {
                                    $author_name = '访客';
                                } else {
                                    $author_name = 'Unknown';
                                }
                                $post_title = $post->post_title ? $post->post_title : '(无标题)';
                                ?>
                                <li>
                                    <span class="admin-list-title">
                                        <a
                                            href="<?php echo todo_admin_url('index.php?page=post-edit&from=dashboard&id=' . $post->ID); ?>"><?php echo $post_title; ?></a>
                                        <?php if ($post->post_status !== 'publish'): ?>
                                            <span class="post-state"> —
                                                <?php
                                                $status_labels = [
                                                    'draft' => '草稿',
                                                    'pending' => '待审',
                                                    'future' => '定时',
                                                    'trash' => '回收站'
                                                ];
                                                echo isset($status_labels[$post->post_status]) ? $status_labels[$post->post_status] : $post->post_status;
                                                ?>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                    <div class="admin-list-meta">
                                        <span>
                                            <em>由</em> <?php echo $author_name; ?> <em><?php echo $post->post_type === 'guestbook' ? '留言于' : '发布于'; ?></em>
                                            <?php echo date('Y-m-d H:i', strtotime($post->post_date)); ?>
                                        </span>
                                        <em><?php echo $post->post_type; ?></em>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p>暂无文章。</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="admin-dashboard-column">
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">最近的评论</h3>
                </div>
                <div class="admin-card-content">
                    <?php if (!empty($recent_comments)): ?>
                        <ul class="admin-list">
                            <?php foreach ($recent_comments as $comment): ?>
                                <?php
                                $comment_post = get_post($comment->comment_post_ID);
                                $post_title = $comment_post ? $comment_post->post_title : '已删除文章';
                                ?>
                                <li>
                                    <span class="admin-list-title">
                                        <a
                                            href="<?php echo todo_admin_url('index.php?page=comment-edit&from=dashboard&comment_id=' . $comment->comment_ID); ?>"><?php echo get_comment_excerpt($comment->comment_ID); ?></a>
                                    </span>
                                    <div class="admin-list-meta">
                                        <span>
                                            <?php
                                            $comment_type = (string) ($comment->comment_type ?? '');
                                            $post_type = $comment_post ? (string) ($comment_post->post_type ?? '') : '';
                                            $is_reply = isset($comment->comment_parent) && intval($comment->comment_parent) > 0;
                                            $action_label = '回复于';
                                            if ($post_type === 'guestbook' || $comment_type === 'guestbook' || $comment_type === 'message' || $comment_type === '') {
                                                $action_label = '回复留言';
                                            } elseif ($comment_type === 'comment') {
                                                $action_label = $is_reply ? '回复评论' : '评论文章';
                                            } elseif ($comment_type === 'review') {
                                                $action_label = $is_reply ? '回复评价' : '评价商品';
                                            } elseif ($comment_type === 'inquiry') {
                                                $action_label = $is_reply ? '回复咨询' : '咨询产品';
                                            }
                                            ?>
                                            <em>由</em> <?php echo $comment->comment_author; ?> <em><?php echo $action_label; ?></em>
                                            <?php if ($comment_post): ?>
                                                <a
                                                    href="<?php echo todo_admin_url('index.php?page=post-edit&from=dashboard&id=' . $comment->comment_post_ID); ?>"><?php echo $post_title; ?></a>
                                            <?php else: ?>
                                                <?php echo $post_title; ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="no-items">
                            <p>暂无评论。</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (current_user_can('view_stats') || current_user_can('manage_options')): ?>
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">系统信息</h3>
                </div>
                <div class="admin-card-content">
                    <table class="admin-table admin-table-system-info">
                        <tbody>
                            <?php foreach ($system_info as $key => $value): ?>
                                <tr>
                                    <th><?php echo ucwords(str_replace('_', ' ', $key)); ?></th>
                                    <td><?php
                                    if (is_object($value)) {
                                        echo '对象: ' . get_class($value);
                                    } elseif (is_array($value)) {
                                        echo json_encode($value, JSON_UNESCAPED_UNICODE);
                                    } else {
                                        echo $value;
                                    }
                                    ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    .admin-welcome-panel {
        margin-bottom: 20px;
    }

    .welcome-panel-column-container {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        margin: 15px -15px 0;
    }

    .welcome-panel-column {
        flex: 1;
        min-width: 200px;
        padding: 0 30px;
    }

    .welcome-panel-column h3 {
        margin-top: 0;
        margin-bottom: 10px;
        font-size: 16px;
        color: #a0a0aa;
    }

    .welcome-panel-column ul {
        margin: 0;
        padding: 0;
        list-style: none;
    }

    .welcome-panel-column ul li {
        margin-bottom: 8px;
    }

    .admin-dashboard-widgets {
        display: flex;
        flex-wrap: wrap;
        margin: 0 -10px;
    }

    .admin-dashboard-column {
        flex: 1;
        min-width: 300px;
        padding: 0 10px;
    }

    .admin-stats {
        display: flex;
        flex-wrap: wrap;
        margin: 0 -5px;
        padding: 0;
        list-style: none;
    }

    .admin-stats li {
        flex: 1;
        padding: 10px 5px;
        text-align: center;
    }

    .admin-stats li a {
        display: block;
        padding: 10px;
        border-radius: 3px;
        background: linear-gradient(180deg, #f9fafd, transparent);
        transition: all 0.2s ease;
    }

    .admin-stats li a:hover {
        background-color: #eef8ff;
    }

    .admin-stats-count {
        display: block;
        font-size: 24px;
        font-weight: 500;
        color: #0073aa;
    }

    .admin-stats-label {
        display: block;
        word-break: keep-all;
        margin-top: 5px;
        color: #666;
    }

    .admin-list {
        margin: 0;
        padding: 0;
        list-style: none;
    }

    .admin-list li {
        padding: 10px 0;
        border-bottom: 1px solid #eceef1;
    }

    .admin-list li:last-child {
        border-bottom: none;
    }

    .admin-list-title {
        display: block;
        line-height: normal;
    }

    .admin-list-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 3px;
        font-size: 12px;
        color: #666;
    }

    .admin-list-meta em {
        color: #bcbcc3;
    }

    .admin-table-system-info th {
        width: 40%;
    }
</style>
