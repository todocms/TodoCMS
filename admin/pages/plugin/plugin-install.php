<?php
/**
 * 管理后台插件安装页面
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 检查用户权限
if (!current_user_can('install_plugins')) {
    todo_die('您没有足够的权限访问此页面。');
}

// 处理插件上传和安装
$message = '';
$error = '';

if (isset($_POST['install-plugin-submit']) && isset($_FILES['plugin_zip'])) {
    // 检查nonce
    if (!Todo_Security_Manager::verify_request_csrf('install-plugin', '_todononce')) {
        $error = '安全验证失败。请重试。';
    } else {
        if (function_exists('get_option') && !get_option('enable_upload', true)) {
            $error = '文件上传功能已被管理员禁用';
        }

        $file = $_FILES['plugin_zip'];

        if (empty($error)) {
            // 检查文件类型
            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($file_ext !== 'zip') {
                $error = '请上传有效的ZIP文件。';
            } else {
            $max_size = null;
            if (class_exists('TodoUploadConfig')) {
                $max_size = (int) TodoUploadConfig::get_max_upload_size();
            }
            if ($max_size === null) {
                $max_size_mb = function_exists('get_option') ? get_option('max_upload_size', null) : null;
                if ($max_size_mb !== null && is_numeric($max_size_mb)) {
                    $max_size_mb = (int) $max_size_mb;
                    if ($max_size_mb <= 0) {
                        $max_size = 0;
                    } else {
                        $max_size = $max_size_mb * 1024 * 1024;
                    }
                }
            }
            if ($max_size === null) {
                $parse_size = function ($size) {
                    $unit = preg_replace('/[^bkmgtpezy]/i', '', $size ?? '');
                    $num = preg_replace('/[^0-9\.]/', '', $size ?? '');
                    $num = ($num === '' ? 0 : (float) $num);
                    if ($unit) {
                        return (int) round($num * pow(1024, stripos('bkmgtpezy', $unit[0])));
                    }
                    return (int) round($num);
                };
                $max_size = min($parse_size(ini_get('upload_max_filesize')), $parse_size(ini_get('post_max_size')));
            }
            if ((int) $max_size <= 0) {
                $error = '文件上传功能已被管理员禁用';
            } elseif (isset($file['size']) && (int) $file['size'] > (int) $max_size) {
                $max_mb = round(((int) $max_size) / (1024 * 1024), 2);
                $error = '插件包大小不能超过' . $max_mb . 'MB';
            }

            if (empty($error)) {
                // 处理文件上传
                $upload_dir = TODO_CONTENT_DIR . '/uploads/temp';

                // 确保上传目录存在
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                // realpath 前缀校验
                $uploads_real = realpath(TODO_CONTENT_DIR . '/uploads');
                $temp_real = realpath($upload_dir);
                if ($uploads_real === false || $temp_real === false || strpos($temp_real, $uploads_real) !== 0) {
                    $error = '非法上传目录';
                } else {
                    // 使用安全文件名
                    $safe_name = sanitize_file_name(basename($file['name']));
                    $temp_file = $upload_dir . '/' . $safe_name;
                }

                if (isset($temp_file)) {
                    if (!is_uploaded_file($file['tmp_name'])) {
                        $error = '非法上传源';
                    } elseif (move_uploaded_file($file['tmp_name'], $temp_file)) {
                        @chmod($temp_file, 0644);
                        // 解压插件
                        $result = install_plugin_from_zip($temp_file);

                        // 删除临时文件
                        @unlink($temp_file);

                        if (is_todo_error($result)) {
                            $error = $result->get_error_message();
                        } else {
                            $message = '插件安装成功。<a href="' . todo_admin_url('index.php?page=plugins') . '">管理插件</a>';
                        }
                    } else {
                        $error = '上传文件时出错。请重试。';
                    }
                } else {
                    $error = '上传文件时出错。请重试。';
                }
            }
        }
        }
    }
}

// 获取插件目录中的特色插件
$featured_plugins = array(
    array(
        'name' => 'SEO优化工具',
        'slug' => 'seo-optimizer',
        'version' => '1.2.0',
        'author' => 'TodoCMS团队',
        'description' => '全面的SEO优化工具，帮助您提高网站在搜索引擎中的排名。包括元标记优化、XML站点地图生成、内容分析等功能。',
        'rating' => 4.8,
        'num_ratings' => 128,
        'active_installs' => '10,000+',
        'last_updated' => '2023-10-15',
    ),
    array(
        'name' => '安全防护',
        'slug' => 'security-shield',
        'version' => '2.1.5',
        'author' => 'TodoCMS团队',
        'description' => '全面保护您的网站免受恶意攻击。包括登录保护、文件监控、防火墙和恶意软件扫描等功能。',
        'rating' => 4.9,
        'num_ratings' => 256,
        'active_installs' => '50,000+',
        'last_updated' => '2023-11-05',
    ),
    array(
        'name' => '缓存加速器',
        'slug' => 'cache-booster',
        'version' => '3.0.2',
        'author' => 'TodoCMS团队',
        'description' => '提高网站加载速度和性能。包括页面缓存、浏览器缓存、数据库优化和资源压缩等功能。',
        'rating' => 4.7,
        'num_ratings' => 189,
        'active_installs' => '30,000+',
        'last_updated' => '2023-09-28',
    ),
    array(
        'name' => '表单生成器',
        'slug' => 'form-builder',
        'version' => '1.5.3',
        'author' => 'TodoCMS团队',
        'description' => '轻松创建各种类型的表单。包括联系表单、调查问卷、注册表单等，支持多种字段类型和验证规则。',
        'rating' => 4.6,
        'num_ratings' => 145,
        'active_installs' => '20,000+',
        'last_updated' => '2023-10-22',
    ),
);

// 获取热门插件
$popular_plugins = array(
    array(
        'name' => '社交媒体分享',
        'slug' => 'social-share',
        'version' => '2.3.1',
        'author' => 'TodoCMS团队',
        'description' => '在您的网站上添加社交媒体分享按钮，支持多种社交平台和自定义样式。',
        'rating' => 4.5,
        'num_ratings' => 112,
        'active_installs' => '15,000+',
        'last_updated' => '2023-08-15',
    ),
    array(
        'name' => '图片库增强',
        'slug' => 'gallery-enhancer',
        'version' => '1.7.0',
        'author' => 'TodoCMS团队',
        'description' => '增强网站图片库功能，支持灯箱效果、滑块、瀑布流等多种展示方式。',
        'rating' => 4.4,
        'num_ratings' => 98,
        'active_installs' => '8,000+',
        'last_updated' => '2023-09-10',
    ),
    array(
        'name' => '备份与恢复',
        'slug' => 'backup-restore',
        'version' => '2.5.2',
        'author' => 'TodoCMS团队',
        'description' => '自动备份您的网站数据和文件，支持定时备份和一键恢复功能。',
        'rating' => 4.8,
        'num_ratings' => 176,
        'active_installs' => '25,000+',
        'last_updated' => '2023-11-12',
    ),
    array(
        'name' => '多语言支持',
        'slug' => 'multilingual',
        'version' => '3.1.4',
        'author' => 'TodoCMS团队',
        'description' => '让您的网站支持多种语言，包括内容翻译、语言切换器和区域设置管理。',
        'rating' => 4.7,
        'num_ratings' => 134,
        'active_installs' => '18,000+',
        'last_updated' => '2023-10-05',
    ),
);

// 获取最新插件
$newest_plugins = array(
    array(
        'name' => '电子商务套件',
        'slug' => 'ecommerce-suite',
        'version' => '1.0.0',
        'author' => 'TodoCMS团队',
        'description' => '将您的网站转变为功能齐全的在线商店，包括产品管理、购物车、支付网关和订单处理。',
        'rating' => 4.2,
        'num_ratings' => 24,
        'active_installs' => '1,000+',
        'last_updated' => '2023-11-20',
    ),
    array(
        'name' => '会员系统',
        'slug' => 'membership',
        'version' => '1.1.0',
        'author' => 'TodoCMS团队',
        'description' => '创建会员专区和付费内容，支持多级会员、订阅计划和内容限制。',
        'rating' => 4.3,
        'num_ratings' => 32,
        'active_installs' => '2,000+',
        'last_updated' => '2023-11-15',
    ),
    array(
        'name' => '广告管理器',
        'slug' => 'ad-manager',
        'version' => '1.0.2',
        'author' => 'TodoCMS团队',
        'description' => '管理网站广告位，支持多种广告类型、定向投放和广告统计。',
        'rating' => 4.0,
        'num_ratings' => 18,
        'active_installs' => '500+',
        'last_updated' => '2023-11-08',
    ),
    array(
        'name' => '内容编辑器增强',
        'slug' => 'editor-enhancer',
        'version' => '1.0.1',
        'author' => 'TodoCMS团队',
        'description' => '增强内容编辑器功能，添加更多格式选项、媒体嵌入和排版工具。',
        'rating' => 4.1,
        'num_ratings' => 15,
        'active_installs' => '800+',
        'last_updated' => '2023-11-18',
    ),
);

// 当前标签页
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'featured';
?>

<div class="admin-plugin-install-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">添加插件</h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=plugins'); ?>" class="button">已安装的插件</a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo $message; ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo $error; ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <div class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-section">
                <h3 class="admin-card-title">添加插件</h3>
            </div>
            <?php if ($current_tab !== 'upload'): ?>
                <div class="admin-card-actions">
                    <form method="get" class="search-form">
                        <input type="hidden" name="page" value="plugin-install">
                        <input type="hidden" name="tab" value="<?php echo esc_attr($current_tab); ?>">
                        <input type="search" name="s" value="<?php echo isset($_GET['s']) ? esc_attr($_GET['s']) : ''; ?>" placeholder="搜索插件...">
                        <button type="submit" class="button button-secondary">搜索</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <div class="admin-card-content">
            <div class="admin-filter-bar">
                <ul class="admin-filters">
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=plugin-install&tab=featured'); ?>" class="<?php echo $current_tab === 'featured' ? 'current' : ''; ?>">特色</a>
                    </li>
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=plugin-install&tab=popular'); ?>" class="<?php echo $current_tab === 'popular' ? 'current' : ''; ?>">热门</a>
                    </li>
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=plugin-install&tab=new'); ?>" class="<?php echo $current_tab === 'new' ? 'current' : ''; ?>">最新</a>
                    </li>
                    <li>
                        <a href="<?php echo todo_admin_url('index.php?page=plugin-install&tab=upload'); ?>" class="<?php echo $current_tab === 'upload' ? 'current' : ''; ?>">上传插件</a>
                    </li>
                </ul>
            </div>

            <?php if ($current_tab === 'upload'): ?>
                <div class="plugin-upload-section">
                    <p>如果您有插件的ZIP文件，可以在此处上传并安装。</p>

                    <form method="post" enctype="multipart/form-data" class="plugin-upload-form">
                        <?php echo Todo_Security_Manager::csrf_field('install-plugin', '_todononce'); ?>
                        <div class="form-field">
                            <input type="file" name="plugin_zip" id="plugin_zip" accept=".zip" required>
                        </div>

                        <button type="submit" name="install-plugin-submit" class="button button-primary">安装插件</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="plugin-browser">
                    <div class="plugins-list">
                        <?php
                        // 根据当前标签页显示不同的插件列表
                        $plugins_to_display = array();

                        switch ($current_tab) {
                            case 'featured':
                                $plugins_to_display = $featured_plugins;
                                break;

                            case 'popular':
                                $plugins_to_display = $popular_plugins;
                                break;

                            case 'new':
                                $plugins_to_display = $newest_plugins;
                                break;
                        }

                        // 搜索过滤
                        if (isset($_GET['s']) && !empty($_GET['s'])) {
                            $search = strtolower($_GET['s']);
                            $filtered_plugins = array();

                            foreach ($plugins_to_display as $plugin) {
                                if (
                                    strpos(strtolower($plugin['name']), $search) !== false ||
                                    strpos(strtolower($plugin['description']), $search) !== false ||
                                    strpos(strtolower($plugin['author']), $search) !== false
                                ) {
                                    $filtered_plugins[] = $plugin;
                                }
                            }

                            $plugins_to_display = $filtered_plugins;
                        }

                        if (empty($plugins_to_display)): ?>
                            <div class="no-plugins-found">
                                <p>没有找到匹配的插件。</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($plugins_to_display as $plugin): ?>
                                <div class="plugin-card">
                                    <div class="plugin-card-top">
                                        <div class="plugin-icon">
                                            <img src="<?php echo esc_attr($plugin['slug']); ?>" alt="<?php echo esc_attr($plugin['name']); ?>">
                                        </div>

                                        <div class="plugin-info">
                                            <h3 class="plugin-name"><?php echo $plugin['name']; ?></h3>

                                            <div class="plugin-actions">
                                                <a href="<?php echo todo_admin_url('index.php?page=plugin-install&action=install&plugin=' . urlencode($plugin['slug'])); ?>"
                                                    class="button button-primary">安装</a>
                                                <a href="javascript:void(0);" class="plugin-details-toggle">详情</a>
                                            </div>

                                            <div class="plugin-description">
                                                <?php echo $plugin['description']; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="plugin-card-bottom">
                                        <div class="plugin-meta">
                                            <div class="plugin-version">
                                                <span class="label">版本:</span>
                                                <span class="value"><?php echo $plugin['version']; ?></span>
                                            </div>

                                            <div class="plugin-author">
                                                <span class="label">作者:</span>
                                                <span class="value"><?php echo $plugin['author']; ?></span>
                                            </div>

                                            <div class="plugin-rating">
                                                <span class="label">评分:</span>
                                                <span class="value">
                                                    <div class="star-rating" title="<?php echo $plugin['rating']; ?> / 5">
                                                        <?php
                                                        $rating = $plugin['rating'];
                                                        $full_stars = floor($rating);
                                                        $half_star = ($rating - $full_stars) >= 0.5;
                                                        $empty_stars = 5 - $full_stars - ($half_star ? 1 : 0);

                                                        for ($i = 0; $i < $full_stars; $i++) {
                                                            echo '<span class="star star-full"></span>';
                                                        }

                                                        if ($half_star) {
                                                            echo '<span class="star star-half"></span>';
                                                        }

                                                        for ($i = 0; $i < $empty_stars; $i++) {
                                                            echo '<span class="star star-empty"></span>';
                                                        }
                                                        ?>
                                                    </div>
                                                    <span class="num-ratings">(<?php echo $plugin['num_ratings']; ?>)</span>
                                                </span>
                                            </div>
                                        </div>

                                        <div class="plugin-stats">
                                            <div class="active-installs">
                                                <span class="label">活跃安装:</span>
                                                <span class="value"><?php echo $plugin['active_installs']; ?></span>
                                            </div>

                                            <div class="last-updated">
                                                <span class="label">最后更新:</span>
                                                <span class="value"><?php echo $plugin['last_updated']; ?></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="plugin-details" style="display: none;">
                                        <div class="plugin-details-content">
                                            <h4>插件详情</h4>
                                            <p><?php echo $plugin['description']; ?></p>

                                            <div class="plugin-details-section">
                                                <h5>版本历史</h5>
                                                <ul>
                                                    <li><strong><?php echo $plugin['version']; ?></strong> - 初始版本发布</li>
                                                </ul>
                                            </div>

                                            <div class="plugin-details-section">
                                                <h5>安装说明</h5>
                                                <ol>
                                                    <li>点击"安装"按钮安装插件</li>
                                                    <li>安装完成后，前往插件页面激活插件</li>
                                                    <li>根据插件说明配置相关设置</li>
                                                </ol>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    /* 插件安装页面特定样式 */
    .plugin-upload-section {
        max-width: 600px;
        margin: 20px 0 0;
    }

    .plugin-upload-form .form-field {
        margin: 15px 0;
    }

    .plugin-upload-form label {
        display: block;
        margin-bottom: 5px;
        font-weight: 500;
    }

    .plugins-list {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 20px;
    }

    .plugin-card {
        border: 1px solid #eceef1;
        border-radius: 3px;
        background-color: #fff;
        position: relative;
        display: flex;
        flex-direction: column;
    }

    .plugin-card-top {
        padding: 20px;
        display: flex;
        border-bottom: 1px solid #eceef1;
    }

    .plugin-icon {
        width: 60px;
        height: 60px;
        margin-right: 15px;
        flex-shrink: 0;
    }

    .plugin-icon img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 3px;
    }

    .plugin-info {
        flex-grow: 1;
    }

    .plugin-name {
        margin: 0 0 10px 0;
        font-size: 16px;
        color: #333;
    }

    .plugin-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }

    .plugin-details-toggle {
        font-size: 14px;
    }

    .plugin-description {
        color: #999;
        font-size: 13px;
        line-height: 1.5;
    }

    .plugin-card-bottom {
        padding: 15px 20px;
        background-color: #f7f7f7;
        display: flex;
        justify-content: space-between;
        font-size: 12px;
        color: #666;
        flex: 1;
    }

    .plugin-meta,
    .plugin-stats {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .plugin-meta>div,
    .plugin-stats>div {
        display: flex;
        align-items: center;
    }

    .label {
        margin-right: 5px;
        font-weight: 500;
    }

    .star-rating {
        display: inline-flex;
        align-items: center;
        margin-right: 5px;
    }

    .star {
        display: inline-block;
        width: 14px;
        height: 14px;
        background-repeat: no-repeat;
        background-position: center;
    }

    .star-full {
        background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"><path fill="%23ffb900" d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>');
    }

    .star-half {
        background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"><path fill="%23ffb900" d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21 12 17.27z"/><path fill="%23e0e0e0" d="M12 17.27V2L9.19 8.63 2 9.24l5.46 4.73L5.82 21 12 17.27z"/></svg>');
    }

    .star-empty {
        background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"><path fill="%23e0e0e0" d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>');
    }

    .num-ratings {
        color: #999;
    }

    .plugin-details {
        max-height: 320px;
        padding: 20px;
        border-top: 1px solid #eceef1;
        background-color: #f9f9f9;
        overflow-y: auto;
    }

    .plugin-details-section {
        margin-top: 15px;
    }

    .plugin-details-section h5 {
        margin: 0 0 10px 0;
        font-size: 14px;
    }

    .plugin-details-section ul, .plugin-details-section ol {
        margin-left: 20px;
    }

    .no-plugins-found {
        grid-column: 1 / -1;
        padding: 20px;
        text-align: center;
        background-color: #f7f7f7;
        border-radius: 3px;
    }
</style>

<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        // 插件详情切换
        var detailsToggles = document.querySelectorAll('.plugin-details-toggle');

        detailsToggles.forEach(function (toggle) {
            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                var card = this.closest('.plugin-card');
                var details = card.querySelector('.plugin-details');

                if (details.style.display === 'none') {
                    details.style.display = 'block';
                    this.textContent = '收起';
                } else {
                    details.style.display = 'none';
                    this.textContent = '详情';
                }
            });
        });

        // 插件安装确认
        var installButtons = document.querySelectorAll('.plugin-actions .button-primary');

        installButtons.forEach(function (button) {
            button.addEventListener('click', function (e) {
                e.preventDefault(); // Prevent default immediately
                var pluginName = this.closest('.plugin-card').querySelector('.plugin-name').textContent;
                var installUrl = this.href; // Assuming the button is an anchor tag or has logic to handle navigation

                // If it's a button in a form (which seems implied by previous logic not showing href usage), adjust accordingly.
                // But looking at the code, it prevents default then does nothing if cancelled.
                // If confirmed, we need to proceed.
                // Let's see if it's a link or a submit button. The selector is .plugin-actions .button-primary.
                // If it's a link (<a>), we need to navigate. If it's a submit, we submit.
                // The original code was: if (!confirm(...)) { e.preventDefault(); }
                // This implies if confirm is true, it continues (navigates or submits).

                var target = this;

                TodoModal.confirm({
                    title: '安装插件',
                    message: '您确定要安装插件 "' + pluginName + '" 吗？',
                    onConfirm: function () {
                        if (target.tagName === 'A') {
                            window.location.href = target.href;
                        } else if (target.type === 'submit' || target.tagName === 'BUTTON') {
                            if (target.form) {
                                target.form.submit();
                            }
                        }
                    }
                });
            });
        });
    });
</script>
