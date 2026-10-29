<?php
/**
 * 管理后台主题管理页面
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 检查用户权限
if (!current_user_can('switch_themes') && !current_user_can('manage_themes') && !current_user_can('edit_themes') && !current_user_can('edit_theme_options') && !current_user_can('customize') && !current_user_can('manage_options')) {
    todo_die('您没有足够的权限访问此页面。');
}

// 获取当前操作
$action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') ? (isset($_POST['action']) ? (string) $_POST['action'] : '') : '';

// 处理主题操作
if ($action) {
    $theme_slug = isset($_POST['theme']) ? sanitize_key($_POST['theme']) : '';

    if (empty($theme_slug)) {
        todo_die('未指定主题。');
    }

    switch ($action) {
        case 'activate':
            if (current_user_can('switch_themes') || current_user_can('manage_themes') || current_user_can('manage_options')) {
                // CSRF 校验（统一使用安全管理器）
                if (!Todo_Security_Manager::verify_request_csrf('activate_theme_' . $theme_slug, '_todononce')) {
                    $error = '安全校验失败，请刷新页面后重试。';
                    break;
                }
                // 激活主题
                $result = activate_theme($theme_slug);

                if (is_todo_error($result)) {
                    $error = $result->get_error_message();
                } else {
                    $message = [
                        'type' => 'success',
                        'content' => '主题已激活，并已自动清理所有前端缓存。'
                    ];
                    // 设置激活成功标志，用于JavaScript刷新页面
                    $theme_activated = true;
                }
            } else {
                $error = '您没有权限切换主题。';
            }
            break;

        // 删除功能已被禁用
        case 'delete':
            $error = '删除功能已被禁用，以确保系统稳定性。';
            break;
    }
}

// 获取所有可用主题
$themes_data = get_themes();
$themes = [];

// 将主题数据转换为主题对象
foreach ($themes_data as $theme_name => $theme_info) {
    $themes[] = new CMS_Theme($theme_name);
}

// 获取当前激活的主题
$current_theme = get_current_theme();
?>

<div class="admin-themes-page">
    <?php if (isset($message)): ?>
        <div class="notice notice-<?php echo $message['type']; ?> is-dismissible">
            <p><?php echo $message['content']; ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo $error; ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <div class="admin-card">
        <div class="admin-card-header">
            <h3 class="admin-card-title">当前默认主题</h3>
            <div class="admin-card-actions">
                <a href="<?php echo todo_admin_url('index.php?page=template-tags'); ?>"
                    class="btn-normal">模板标签手册</a>
            </div>
        </div>
        <div class="admin-card-content">
            <div class="theme-card current-theme">
                <div class="theme-screenshot">
                    <?php if ($current_theme->get_screenshot_url()): ?>
                        <img src="<?php echo $current_theme->get_screenshot_url(); ?>"
                            alt="<?php echo esc_attr($current_theme->get_name()); ?>">
                    <?php else: ?>
                        <div class="theme-no-screenshot">无预览图</div>
                    <?php endif; ?>
                </div>
                <div class="theme-info">
                    <h4 class="theme-name">
                        <?php echo $current_theme->get_name(); ?>
                        <span class="theme-version">版本: <?php echo $current_version = $current_theme->get_version(); ?></span>
                    </h4>
                    <p class="theme-description"><?php echo $current_theme->get_description(); ?></p>
                    <div class="theme-author">
                        作者: <?php echo $current_theme->get_author(); ?> ｜
                        <a href="<?php echo $current_theme->get_author_url(); ?>" target="_blank">作者主页</a>
                    </div>
                    <div class="theme-actions">
                        <?php if (current_user_can('edit_themes') || current_user_can('customize') || current_user_can('manage_themes') || current_user_can('manage_options')): ?>
                            <a href="<?php echo todo_admin_url('index.php?page=theme-customize'); ?>" class="button button-secondary">自定义</a>
                        <?php endif; ?>
                        <a href="<?php echo todo_admin_url('index.php?page=theme-studio&theme=' . urlencode($current_theme->get_stylesheet())); ?>" class="button button-secondary"><i class="fa fa-magic" style="margin-right: 4px;"></i>主题工作室</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <h3 class="admin-card-title">可用主题</h3>
        </div>
        <div class="admin-card-content">
            <div class="themes-grid">
                <?php foreach ($themes as $theme): ?>
                    <?php if ($theme->get_stylesheet() !== $current_theme->get_stylesheet()): ?>
                        <div class="theme-card">
                            <div class="theme-screenshot">
                                <?php if ($theme->get_screenshot_url()): ?>
                                    <img src="<?php echo $theme->get_screenshot_url(); ?>"
                                        alt="<?php echo esc_attr($theme->get_name()); ?>">
                                <?php else: ?>
                                    <div class="theme-no-screenshot">无预览图</div>
                                <?php endif; ?>
                            </div>
                            <div class="theme-info">
                                <h4 class="theme-name">
                                    <?php echo $theme->get_name(); ?>
                                    <span class="theme-version">版本: <?php echo $theme->get_version(); ?></span>
                                </h4>
                                <div class="theme-actions">
                                    <?php if (current_user_can('switch_themes') || current_user_can('manage_themes') || current_user_can('manage_options')): ?>
                                        <a href="index.php?page=themes&action=activate&theme=<?php echo $theme->get_stylesheet(); ?>&amp;_todononce=<?php echo Todo_Security_Manager::generate_csrf_token('activate_theme_' . $theme->get_stylesheet()); ?>"
                                            class="btn button-primary js-todo-post-action">激活</a>
                                    <?php endif; ?>
                                    <a href="javascript:void(0);" class="btn theme-preview-btn"
                                        data-theme="<?php echo $theme->get_stylesheet(); ?>"
                                        data-theme-name="<?php echo esc_attr($theme->get_name()); ?>">预览</a>
                                </div>
                            </div>
                            <div class="theme-details">
                                <div class="theme-description"><?php echo $theme->get_description(); ?></div>
                                <div class="theme-author">作者: <?php echo $theme->get_author(); ?> ｜
                                    <a href="<?php echo $theme->get_author_url(); ?>" target="_blank">作者主页</a>
                                </div>
                                <div class="theme-tags">
                                    标签: <?php echo implode(', ', $theme->get_tags()); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<style>
    /* 主题管理页面特定样式 */
    .themes-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 380px));
        gap: 20px;
    }

    .theme-card {
        border: 1px solid #eceef1;
        border-radius: 3px;
        overflow: hidden;
        transition: box-shadow 0.3s;
    }

    .theme-card:hover {
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
    }

    .theme-screenshot {
        position: relative;
        height: 200px;
        overflow: hidden;
        background-color: #f5f5f5;
    }

    .theme-screenshot img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        object-position: top;
    }

    .theme-no-screenshot {
        display: flex;
        align-items: center;
        justify-content: center;
        height: 100%;
        color: #888;
        font-style: italic;
    }

    .theme-info {
        padding: 15px;
    }

    .theme-name {
        margin: 0 0 10px;
        font-size: 16px;
        font-weight: 500;
    }

    .theme-version {
        font-size: 12px;
        font-weight: normal;
        color: #666;
        margin-left: 10px;
    }

    .theme-description {
        margin-bottom: 10px;
        line-height: 26px;
        color: #555;
    }

    .theme-author {
        font-size: 13px;
        color: #666;
        margin-bottom: 10px;
    }

    .theme-actions {
        display: flex;
        gap: 5px;
        justify-content: space-between;
        margin-top: auto;
    }

    .theme-details {
        padding: 15px;
        border-top: 1px solid #eee;
        font-size: 13px;
        max-height: 150px;
        overflow: auto;
    }

    .theme-tags {
        margin-top: 10px;
        color: #666;
    }

    .current-theme {
        display: flex;
        flex-wrap: wrap;
        border: none;
        border-radius: 0;
    }

    .current-theme .theme-screenshot {
        flex: 0 0 300px;
        max-width: 300px;
    }

    .mbody .current-theme .theme-screenshot {
        flex: 100%;
        max-width: 100%;
    }

    .current-theme img {
        border-radius: 3px;
    }

    .current-theme .theme-info {
        flex: 1;
        padding: 0 0 0 20px;
        display: flex;
        flex-direction: column;
    }

    .mbody .current-theme .theme-info {
        padding: 20px 0 0 0;
    }

    .current-theme:hover {
        box-shadow: none;
    }
</style>

<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        // 主题激活成功后自动刷新页面
        <?php if (isset($theme_activated) && $theme_activated): ?>
            // 检测是否有成功消息，如果有则延迟刷新页面以显示消息
            var successNotice = document.querySelector('.notice.notice-success');
            if (successNotice) {
                // 显示成功消息2秒后刷新页面
                setTimeout(function () {
                    // 刷新到干净的主题页面（移除URL参数）
                    var baseUrl = window.location.pathname;
                    var pageParam = '?page=themes';
                    // 如果URL中已有其他参数（如hash），保留它们
                    if (window.location.hash) {
                        pageParam += window.location.hash;
                    }
                    window.location.href = baseUrl + pageParam;
                }, 2000);
            }
        <?php endif; ?>

        // 主题预览功能
        var previewLinks = document.querySelectorAll('.theme-preview-btn');

        previewLinks.forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var themeSlug = this.getAttribute('data-theme');
                var themeName = this.getAttribute('data-theme-name');

                // 打开预览窗口
                var previewUrl = '<?php echo todo_url(); ?>/?preview=1&theme=' + themeSlug;
                var previewWindow = window.open(previewUrl, 'theme-preview', 'width=1200,height=800,scrollbars=yes,resizable=yes');

                if (previewWindow) {
                    previewWindow.focus();
                } else {
                    if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils) {
                        TodoCMS.Utils.showMessage('请允许弹出窗口以预览主题', 'warning');
                    }
                }
            });
        });

        // AI 转化配置导出
        var exportBtns = document.querySelectorAll('.btn-export-ai-conversion');
        exportBtns.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var themeSlug = this.getAttribute('data-theme');
                var fd = new FormData();
                fd.append('action', 'export_theme_ai_conversion');
                fd.append('theme', themeSlug);
                fd.append('_todononce', '<?php echo Todo_Security_Manager::generate_csrf_token('common_ajax_nonce'); ?>');
                // 统一使用通用 ajaxUrl，如果未定义则推测
                var ajaxUrl = '<?php echo todo_admin_url('ajax/post-ajax.php'); ?>';
                
                if (window.TodoModal && typeof TodoModal.showLoading === 'function') {
                    TodoModal.showLoading('正在生成 AI 转化配置文件...');
                }

                fetch(ajaxUrl, {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(r) { return r.json(); })
                .then(function(json) {
                    if (window.TodoModal && typeof TodoModal.hideLoading === 'function') {
                        TodoModal.hideLoading();
                    }
                    if (json.success && json.data) {
                        var blob = new Blob([JSON.stringify(json.data, null, 2)], { type: 'application/json' });
                        var link = document.createElement('a');
                        link.href = URL.createObjectURL(blob);
                        link.download = json.filename || ('theme-conversion-' + themeSlug + '.json');
                        link.click();
                    } else {
                        TodoModal.error('导出失败: ' + (json.message || '未知错误'));
                    }
                })
                .catch(function(err) {
                    if (window.TodoModal && typeof TodoModal.hideLoading === 'function') {
                        TodoModal.hideLoading();
                    }
                    console.error(err);
                    TodoModal.error('导出出错');
                });
            });
        });

        // 主题卡片点击展开详情
        var themeCards = document.querySelectorAll('.theme-card:not(.current-theme)');

        themeCards.forEach(function (card) {
            card.addEventListener('click', function (e) {
                // 如果点击的是按钮，不展开详情
                if (e.target.tagName === 'A' || e.target.closest('a')) {
                    return;
                }

                // 切换详情显示状态
                var details = this.querySelector('.theme-details');
                if (details) {
                    details.style.display = details.style.display === 'none' ? 'block' : 'none';
                }
            });

            // 初始隐藏详情
            var details = card.querySelector('.theme-details');
            if (details) {
                details.style.display = 'none';
            }
        });
    });
</script>
