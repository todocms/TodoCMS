<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
/**
 * 新增广告页面 - 优化版
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    todo_die('Direct access not permitted');
}

// 加载必要的类和函数
require_once TODO_ADMIN_PATH . '/components/featured-images-component.php';

// 初始化广告管理器
$ads_manager = Todo_Ads_Manager::init();

// 检查权限
if (!current_user_can('manage_ads')) {
    todo_die('您没有管理广告的权限。');
}

$message = '';
$error = '';

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_ad') {
    // 验证nonce
    if (!Todo_Security_Manager::verify_request_csrf('add_ad', '_todononce')) {
        todo_die('安全验证失败。');
    }

    // 获取表单数据
    $title = sanitize_text_field($_POST['title']);
    $type = sanitize_text_field($_POST['type']);
    $content = isset($_POST['content']) ? $_POST['content'] : ''; // 根据类型处理
    $link_url = isset($_POST['link_url']) ? esc_url_raw($_POST['link_url']) : '';
    $target = isset($_POST['target']) ? sanitize_text_field($_POST['target']) : '_self';
    $sort_order = intval($_POST['sort_order']);
    // 统一状态为整型 1/0，兼容旧表单字符串 'active'/'inactive'
    $status_raw = $_POST['status'] ?? 1;
    if (is_numeric($status_raw)) {
        $status = intval($status_raw) ? 1 : 0;
    } else {
        $status_str = strtolower(trim((string) $status_raw));
        $status = ($status_str === 'active') ? 1 : 0;
    }
    // 保留到分钟/秒的时间信息，避免被截断为 00:00:00
    $start_date = isset($_POST['start_date']) && $_POST['start_date'] ? date('Y-m-d H:i:s', strtotime($_POST['start_date'])) : null;
    $end_date = isset($_POST['end_date']) && $_POST['end_date'] ? date('Y-m-d H:i:s', strtotime($_POST['end_date'])) : null;

    // 新增字段
    $image_url = isset($_POST['image_url']) ? esc_url_raw($_POST['image_url']) : '';
    $group_id = intval($_POST['group_id'] ?? 0);
    $is_white_text = isset($_POST['is_white_text']) ? 1 : 0;
    $start_time = sanitize_text_field($_POST['start_time'] ?? '');
    $end_time = sanitize_text_field($_POST['end_time'] ?? '');

    // 处理图片组数据（每组可包含多张图片，组内图片共享同一标题和其它参数）
    $ad_groups_input = isset($_POST['ad_groups']) && is_array($_POST['ad_groups']) ? $_POST['ad_groups'] : array();
    $ad_group_objects = array();
    $flattened_image_urls = array();

    if (!empty($ad_groups_input)) {
        foreach ($ad_groups_input as $gidx => $group) {
            $group_title = sanitize_text_field($group['title'] ?? '');
            $group_link = esc_url_raw($group['link'] ?? '');
            $group_target = sanitize_text_field($group['target'] ?? '_self');
            $group_white = !empty($group['white_text']) ? 1 : 0;
            $group_content = isset($group['content']) ? $group['content'] : '';

            // 组内图片字段来自特色图片组件隐藏字段，可能是JSON字符串或数组
            $images_raw = $group['images'] ?? '[]';
            $images_arr = array();
            if (is_array($images_raw)) {
                $images_arr = $images_raw;
            } else {
                $decoded = json_decode($images_raw, true);
                if (is_array($decoded)) {
                    $images_arr = $decoded;
                }
            }

            // 规范化 target
            if (!in_array($group_target, array('_self', '_blank'), true)) {
                $group_target = '_self';
            }

            // 保留每张图片的完整元数据（id、url、title、alt），并拍平URL用于兼容字段
            $group_images = array();
            foreach ($images_arr as $img) {
                if (is_array($img)) {
                    $item = array(
                        'id' => isset($img['id']) ? intval($img['id']) : 0,
                        'url' => isset($img['url']) ? esc_url_raw($img['url']) : '',
                        'title' => isset($img['title']) ? sanitize_text_field($img['title']) : '',
                        'alt' => isset($img['alt']) ? sanitize_text_field($img['alt']) : ''
                    );
                    if (!empty($item['url'])) {
                        $group_images[] = $item;
                        $flattened_image_urls[] = $item['url'];
                    }
                } else {
                    // 仅有字符串URL的兼容处理
                    $u = esc_url_raw((string) $img);
                    if (!empty($u)) {
                        $group_images[] = array('id' => 0, 'url' => $u, 'title' => '', 'alt' => '');
                        $flattened_image_urls[] = $u;
                    }
                }
            }

            $ad_group_objects[] = array(
                'title' => $group_title,
                'link' => $group_link,
                'target' => $group_target,
                'white_text' => $group_white,
                'content' => $group_content, // 原样存储HTML，输出时做白名单过滤
                'images' => $group_images,
            );
        }
    }

    // 兼容旧版：images字段存储为拍平后的URL数组，content存储完整分组结构
    $images = !empty($flattened_image_urls) ? json_encode($flattened_image_urls) : '';
    // content 存储完整结构：包含分组与每张图的元数据
    $images_grouped_json = json_encode(array(
        'version' => '1.0',
        'type' => 'image_groups',
        'groups' => $ad_group_objects
    ));

    // 处理多媒体组数据（每组可包含多个媒体，组内共享参数）
    $ad_media_groups_input = isset($_POST['ad_media_groups']) && is_array($_POST['ad_media_groups']) ? $_POST['ad_media_groups'] : array();
    $ad_media_group_objects = array();
    if (!empty($ad_media_groups_input)) {
        foreach ($ad_media_groups_input as $midx => $group) {
            $group_title = sanitize_text_field($group['title'] ?? '');
            $group_link = esc_url_raw($group['link'] ?? '');
            $group_target = sanitize_text_field($group['target'] ?? '_self');
            $group_white = !empty($group['white_text']) ? 1 : 0;
            $group_content = isset($group['content']) ? $group['content'] : '';

            if (!in_array($group_target, array('_self', '_blank'), true)) {
                $group_target = '_self';
            }

            $medias_raw = $group['medias'] ?? '[]';
            $medias_arr = array();
            if (is_array($medias_raw)) {
                $medias_arr = $medias_raw;
            } else {
                $decoded = json_decode($medias_raw, true);
                if (is_array($decoded)) {
                    $medias_arr = $decoded;
                }
            }

            $group_medias = array();
            foreach ($medias_arr as $media) {
                if (is_array($media)) {
                    $item = array(
                        'id' => isset($media['id']) ? intval($media['id']) : 0,
                        'url' => isset($media['url']) ? esc_url_raw($media['url']) : '',
                        'title' => isset($media['title']) ? sanitize_text_field($media['title']) : '',
                        'alt' => isset($media['alt']) ? sanitize_text_field($media['alt']) : ''
                    );
                    if (!empty($item['url'])) {
                        $group_medias[] = $item;
                    }
                } else {
                    $u = esc_url_raw((string) $media);
                    if (!empty($u)) {
                        $group_medias[] = array('id' => 0, 'url' => $u, 'title' => '', 'alt' => '');
                    }
                }
            }

            $ad_media_group_objects[] = array(
                'title' => $group_title,
                'link' => $group_link,
                'target' => $group_target,
                'white_text' => $group_white,
                'content' => $group_content, // 原样存储HTML，输出时做白名单过滤
                'medias' => $group_medias,
            );
        }
    }

    // content 存储多媒体完整结构
    $medias_grouped_json = json_encode(array(
        'version' => '1.0',
        'type' => 'media_groups',
        'groups' => $ad_media_group_objects
    ));

    // 代码广告内容（支持多代码块，存储为结构化JSON，兼容旧字段）
    $code_grouped_json = '';
    if (!empty($_POST['ad_html_contents']) && is_array($_POST['ad_html_contents'])) {
        $items = array();
        foreach ($_POST['ad_html_contents'] as $c) {
            $t = (string) $c;
            if (trim($t) !== '') {
                $items[] = array('html' => $t);
            }
        }
        $code_grouped_json = json_encode(array(
            'version' => '1.0',
            'type' => 'html_codes',
            'items' => $items
        ));
    } elseif (isset($_POST['html_content'])) {
        $t = (string) $_POST['html_content'];
        $code_grouped_json = json_encode(array(
            'version' => '1.0',
            'type' => 'html_codes',
            'items' => trim($t) !== '' ? array(array('html' => $t)) : array()
        ));
    }

    // 验证必填字段
    if (empty($title) || empty($type)) {
        $error = '请填写所有必填字段。';
    } else {
        // 根据广告类型处理内容
        switch ($type) {
            case 'image':
                // 使用分组JSON结构存入content，保留images拍平数组作兼容
                $content = $images_grouped_json;
                break;
            case 'media':
                // 多媒体类型（组结构存入content）
                $content = $medias_grouped_json;
                break;
            case 'code':
                // 保存为结构化JSON，避免多个代码合并为一个
                $content = $code_grouped_json;
                break;
        }

        // 使用 Todo_Ads_Manager 创建广告
        $data = array(
            'title' => $title,
            'type' => $type,
            'content' => $content,
            'image_url' => $image_url,
            'images' => $images,
            'group_id' => $group_id,
            'is_white_text' => $is_white_text,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'link_url' => $link_url,
            'target' => $target,
            'sort_order' => $sort_order,
            'status' => $status,
            'start_date' => $start_date,
            'end_date' => $end_date
        );

        $result = Todo_Ads_Manager::create_ad($data);

        if ($result !== false) {
            if (function_exists('todo_add_admin_notice')) {
                todo_add_admin_notice('添加成功。', 'success');
            }
            todo_redirect(todo_admin_url('index.php?page=ads'));
            exit;
        } else {
            $error = '添加广告失败，请重试。';
        }
    }
}

// 获取广告分组
$ad_groups = Todo_Ads_Manager::get_ad_groups(array('status' => 'active'));
?>

<div class="admin-ad-new-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">添加新广告</h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=ads'); ?>" class="button button-secondary">返回广告列表</a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html($error); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>
    <form method="post" class="admin-ad-form todo-request">
        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">广告基本信息</h3>
            </div>
            <div class="admin-card-content">
                <?php echo Todo_Security_Manager::csrf_field('add_ad', '_todononce'); ?>
                <input type="hidden" name="action" value="add_ad">

                <div class="form-group">
                    <label for="title">广告名称 <span class="required">*</span></label>
                    <input name="title" type="text" id="title"
                        value="<?php echo isset($_POST['title']) ? esc_attr($_POST['title']) : ''; ?>" required>
                    <p class="description">广告的显示名称，仅用于后台管理。</p>
                </div>

                <div class="form-group">
                    <label for="type">广告类型 <span class="required">*</span></label>
                    <select name="type" id="type" required>
                        <option value="">请选择广告类型</option>
                        <option value="image" <?php selected(isset($_POST['type']) ? $_POST['type'] : '', 'image'); ?>>
                            📷 图片广告</option>
                        <option value="media" <?php selected(isset($_POST['type']) ? $_POST['type'] : '', 'media'); ?>>
                            🎬 多媒体广告</option>
                        <option value="code" <?php selected(isset($_POST['type']) ? $_POST['type'] : '', 'code'); ?>>💻
                            HTML代码</option>
                    </select>
                    <p class="description">选择广告的类型。</p>
                    <div id="type-hint" class="type-hint" style="display: none;"></div>
                </div>

                <!-- 图片广告字段 -->
                <div id="image-fields" class="ad-type-fields" style="display: none;">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">图片广告设置</h3>
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="padding: 0 30px;">
                            <div id="ad-image-groups-container" class="flex flex-wrap gap-5">
                                <!-- 图片组模板（隐藏，仅用于克隆）。每个图片组内包含一个独立的特色图片组件，多张图片共享同一组的参数 -->
                                <div class="ad-image-group" style="display: none;">
                                    <div class="image-item-card">
                                        <div class="image-item-header">
                                            <span class="image-item-number">图片组 #1</span>
                                            <button type="button" class="btn button-link-delete"
                                                onclick="removeAdImageGroup(this)">删除</button>
                                        </div>

                                        <div class="image-item-content">
                                            <!-- 该组的图片选择（多选），使用独立的特色图片组件实例） -->
                                            <?php
                                            if (function_exists('todo_render_featured_images_component')) {
                                                echo todo_render_featured_images_component(array(
                                                    'field_id' => 'ad_image_group_template',
                                                    'field_name' => 'ad_groups[0][images]',
                                                    'current_images' => array(),
                                                    'multiple' => true,
                                                    'max_images' => 20,
                                                    'file_type' => 'image',
                                                    'enable_debug' => false,
                                                    'variant' => 'universal'
                                                ));
                                            } else {
                                                echo '<p class="description">特色图片组件未加载，无法渲染图片组选择器。</p>';
                                            }
                                            ?>

                                            <!-- 该组的共享设置（标题、链接、支持html内容、打开方式、样式） -->
                                            <div class="image-settings-section">
                                                <div class="setting-row">
                                                    <div class="setting-group">
                                                        <label>组标题</label>
                                                        <input type="text" name="ad_groups[0][title]" placeholder="广告标题"
                                                            class="regular-text">
                                                    </div>
                                                    <div class="setting-group">
                                                        <label>链接地址</label>
                                                        <input type="url" name="ad_groups[0][link]"
                                                            placeholder="http://或https://" class="regular-text"
                                                            pattern="https?://.*"
                                                            title="请输入完整的URL地址，包含http://或https://">
                                                    </div>
                                                </div>

                                                <div class="setting-row">
                                                    <div class="setting-group">
                                                        <label>广告内容</label>
                                                        <textarea name="ad_groups[0][content]" placeholder="内容支持HTML"
                                                            class="regular-text"></textarea>
                                                    </div>
                                                </div>

                                                <div class="setting-row">
                                                    <div>
                                                        <label>打开方式</label>
                                                        <select name="ad_groups[0][target]" class="regular-text">
                                                            <option value="_self">当前窗口</option>
                                                            <option value="_blank">新窗口</option>
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <span>特殊样式</span>
                                                        <label style="margin: 10px 0 0;">
                                                            <input type="checkbox" name="ad_groups[0][white_text]"
                                                                value="1">
                                                            Light效果
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="admin-form-submit">
                                <button type="button" class="button button-primary" onclick="addAdImageGroup()">+
                                    添加图片组</button>
                            </div>
                            <p class="description">每个“图片组”可添加多张图片，该组图片共享同一标题、链接、打开方式和样式。</p>
                        </div>
                    </div>
                </div>

                <!-- 多媒体广告字段（重构为循环子广告块布局） -->
                <div id="media-fields" class="ad-type-fields" style="display: none;">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">多媒体广告设置</h3>
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="padding: 0 30px;">
                            <div id="ad-media-container" class="flex flex-wrap gap-5">
                                <!-- 多媒体组模板（隐藏，仅用于克隆）。每个多媒体组内包含一个独立的特色组件，组内媒体共享同一组参数 -->
                                <div class="ad-image-group ad-media-item" style="display: none;">
                                    <div class="image-item-card">
                                        <div class="image-item-header">
                                            <span class="image-item-number">多媒体组 #1</span>
                                            <button type="button" class="btn button-link-delete"
                                                onclick="removeAdMedia(this)">删除</button>
                                        </div>
                                        <div class="image-item-content">
                                            <?php
                                            if (function_exists('todo_render_featured_images_component')) {
                                                echo todo_render_featured_images_component(array(
                                                    'field_id' => 'ad_media_group_template',
                                                    'field_name' => 'ad_media_groups[0][medias]',
                                                    'current_images' => array(),
                                                    'multiple' => true,
                                                    'max_images' => 20,
                                                    'file_type' => 'video',
                                                    'media_type' => 'video',
                                                    'enable_debug' => false,
                                                    'variant' => 'universal'
                                                ));
                                            } else {
                                                echo '<p class="description">特色多媒体组件未加载，无法渲染广告多媒体选择器。</p>';
                                            }
                                            ?>

                                            <!-- 该组的共享设置（标题、链接、支持html内容、打开方式、样式） -->
                                            <div class="image-settings-section">
                                                <div class="setting-row">
                                                    <div class="setting-group">
                                                        <label>组标题</label>
                                                        <input type="text" name="ad_media_groups[0][title]"
                                                            placeholder="广告标题" class="regular-text">
                                                    </div>
                                                    <div class="setting-group">
                                                        <label>链接地址</label>
                                                        <input type="url" name="ad_media_groups[0][link]"
                                                            placeholder="http://或https://" class="regular-text"
                                                            pattern="https?://.*"
                                                            title="请输入完整的URL地址，包含http://或https://">
                                                    </div>
                                                </div>

                                                <div class="setting-row">
                                                    <div class="setting-group">
                                                        <label>广告内容</label>
                                                        <textarea name="ad_media_groups[0][content]"
                                                            placeholder="内容支持HTML" class="regular-text"></textarea>
                                                    </div>
                                                </div>

                                                <div class="setting-row">
                                                    <div>
                                                        <label>打开方式</label>
                                                        <select name="ad_media_groups[0][target]" class="regular-text">
                                                            <option value="_self">当前窗口</option>
                                                            <option value="_blank">新窗口</option>
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <span>特殊样式</span>
                                                        <label style="margin: 10px 0 0;">
                                                            <input type="checkbox" name="ad_media_groups[0][white_text]"
                                                                value="1">
                                                            Light效果
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="admin-form-submit">
                                <button type="button" class="button button-primary" onclick="addAdMedia()">+
                                    添加多媒体</button>
                            </div>
                            <p class="description">可添加多个多媒体广告，每个可独立设置标题、链接等属性。</p>
                        </div>
                    </div>
                </div>
                <!-- 代码广告字段（重构为循环子广告块布局） -->
                <div id="code-fields" class="ad-type-fields" style="display: none;">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">代码广告设置</h3>
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="padding: 0 30px;">
                            <div id="ad-code-container" class="flex flex-wrap gap-5">
                                <div class="ad-image-group ad-code-item" style="display: none;">
                                    <div class="image-item-card">
                                        <div class="image-item-header">
                                            <span class="image-item-number code-item-number">代码 #1</span>
                                            <button type="button" class="btn button-link-delete"
                                                onclick="removeAdCode(this)">删除</button>
                                        </div>
                                        <div class="image-item-content">
                                            <div class="code-settings-section">
                                                <div class="setting-row">
                                                    <div class="setting-group" style="width:100%;">
                                                        <label>广告代码</label>
                                                        <textarea name="ad_html_contents[0]" rows="6"
                                                            class="large-text code" style="min-width: 100%;"
                                                            placeholder="自定义HTML代码，支持JavaScript"></textarea>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="admin-form-submit">
                                <button type="button" class="button button-primary" onclick="addAdCode()">+
                                    添加代码广告</button>
                            </div>
                            <p class="description">可添加多个代码广告，每个可独立设置内容。</p>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="group_id">广告分组</label>
                        <select name="group_id" id="group_id">
                            <option value="0">无分组</option>
                            <?php foreach ($ad_groups as $group): ?>
                                <option value="<?php echo $group['id']; ?>" <?php selected(isset($_POST['group_id']) ? $_POST['group_id'] : '', $group['id']); ?>>
                                    <?php echo esc_html($group['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">选择广告所属的分组。</p>
                    </div>

                    <div class="form-group">
                        <label for="status">状态</label>
                        <select name="status" id="status">
                            <option value="1" <?php selected(isset($_POST['status']) ? intval($_POST['status']) : 1, 1); ?>>激活</option>
                            <option value="0" <?php selected(isset($_POST['status']) ? intval($_POST['status']) : 1, 0); ?>>停用</option>
                        </select>
                        <p class="description">广告的显示状态。</p>
                    </div>
                    <div class="form-group">
                        <label for="sort_order">排序</label>
                        <input name="sort_order" type="number" id="sort_order"
                            value="<?php echo isset($_POST['sort_order']) ? esc_attr($_POST['sort_order']) : '0'; ?>"
                            class="regular-text" min="0">
                        <p class="description">数字越小排序越靠前。</p>
                    </div>
                </div>

                <div class="admin-card-header">
                    <h3 class="admin-card-title">时间设置</h3>
                    <span class="description">留空则永久显示</span>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="start_date">开始日期</label>
                        <input name="start_date" type="datetime-local" id="start_date"
                            value="<?php echo isset($_POST['start_date']) ? esc_attr($_POST['start_date']) : ''; ?>"
                            class="regular-text">
                        <p class="description">广告开始显示的日期时间。</p>
                    </div>

                    <div class="form-group">
                        <label for="end_date">结束日期</label>
                        <input name="end_date" type="datetime-local" id="end_date"
                            value="<?php echo isset($_POST['end_date']) ? esc_attr($_POST['end_date']) : ''; ?>"
                            class="regular-text">
                        <p class="description">广告停止显示的日期时间。</p>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="start_time">每日开始时间</label>
                        <input name="start_time" type="time" id="start_time"
                            value="<?php echo isset($_POST['start_time']) ? esc_attr($_POST['start_time']) : ''; ?>"
                            class="regular-text">
                        <p class="description">每日开始显示的时间。</p>
                    </div>

                    <div class="form-group">
                        <label for="end_time">每日结束时间</label>
                        <input name="end_time" type="time" id="end_time"
                            value="<?php echo isset($_POST['end_time']) ? esc_attr($_POST['end_time']) : ''; ?>"
                            class="regular-text">
                        <p class="description">每日停止显示的时间。</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="admin-form-submit">
            <input type="submit" name="submit" value="添加广告" class="button button-primary">
        </div>
    </form>
</div>

<style>
    .admin-ad-form .form-row {
        display: flex;
        flex-wrap: wrap;
        gap: 0 20px;
    }

    .admin-ad-form .regular-text {
        width: 100%;
    }

    .mbody .ad-type-fields .form-group {
        flex: 1;
        padding: 0 !important;
    }

    .ad-type-fields.hidden {
        opacity: 0;
    }

    .type-hint {
        background: #e7f3ff;
        border: 1px solid #dae7f2;
        border-radius: 4px;
        padding: 8px 12px;
        margin-top: 8px;
        font-size: 13px;
        color: #135e96;
        animation: fadeIn 0.3s ease-in-out;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(-5px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* 广告图片卡片样式 */
    .ad-image-group {
        border: 1px solid #eceef1;
        border-radius: 6px;
        background: #fff;
    }

    .mbody .ad-image-group {
        flex: 1;
    }

    .image-item-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 6px 16px;
        background: #f6f7f7;
        border-bottom: 1px solid #eceef1;
        border-radius: 6px 6px 0 0;
    }

    .image-item-number {
        font-weight: 500;
        color: #1d2327;
    }

    .image-item-content {
        padding: 16px;
    }

    .image-item-content .universal-images-preview {
        justify-content: flex-start;
    }

    .image-settings-section {
        padding-top: 10px;
    }

    .setting-row {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 12px;
    }

    .setting-row:last-child {
        margin-bottom: 0;
    }

    .setting-group {
        flex: 1;
    }

    .button-link-delete {
        color: #d63638 !important;
        border-color: transparent !important;
        background: transparent !important;
    }

    .button-link-delete:hover {
        color: #fff !important;
        background: #d63638 !important;
        border-color: #d63638 !important;
    }
</style>

<script type="text/javascript">
    // 优化的广告类型字段切换函数
    function toggleAdTypeFields() {
        const type = document.getElementById('type').value;
        const fields = document.querySelectorAll('.ad-type-fields');
        const typeHint = document.getElementById('type-hint');

        // 立即隐藏所有字段
        fields.forEach((field, index) => {
            field.style.display = 'none';
            field.style.opacity = '0';
        });

        // 显示对应类型的字段
        if (type) {
            const targetField = document.getElementById(type + '-fields');

            if (targetField) {
                // 立即显示字段
                targetField.style.display = 'block';
                targetField.style.opacity = '1';

                // 确保对应的div容器可见
                const container = document.querySelector('.admin-ad-new-page');
                if (container) {
                    container.style.display = 'block';
                }

                // 特殊处理：不同类型需要初始化默认克隆
                if (type === 'image') {
                    setTimeout(() => {
                        initializeAdImageGroups();
                    }, 100);
                } else if (type === 'media') {
                    setTimeout(() => {
                        initializeAdMediaGroups();
                    }, 100);
                } else if (type === 'code') {
                    setTimeout(() => {
                        initializeAdCodeItems();
                    }, 100);
                }
            } else {
                console.error(`❌ 未找到目标字段: ${type + '-fields'}`);
            }

            // 显示类型提示
            const hints = {
                'image': '图片广告，支持单图或多图展示，多组则循环每个组的并取组内第一张图片，仅有一个组则循环组内的所有图片。',
                'media': '多媒体广告，支持视频、音频等多媒体文件',
                'code': '自定义HTML/JavaScript代码广告'
            };

            if (hints[type]) {
                typeHint.textContent = hints[type];
                typeHint.style.display = 'inline-block';
            }
        } else {
            typeHint.style.display = 'none';
        }

        // 更新表单验证
        updateFormValidation(type);
    }

    // 初始化多媒体组容器
    function initializeAdMediaGroups() {
        const container = document.getElementById('ad-media-container');
        if (!container) return;
        const groups = container.querySelectorAll('.ad-media-item:not([style*="display: none"])');
        if (groups.length === 0) {
            addAdMedia();
        }
    }

    // 初始化代码项容器
    function initializeAdCodeItems() {
        const container = document.getElementById('ad-code-container');
        if (!container) return;
        const items = container.querySelectorAll('.ad-code-item:not([style*="display: none"])');
        if (items.length === 0) {
            addAdCode();
        }
    }

    // 初始化图片组容器
    function initializeAdImageGroups() {
        const container = document.getElementById('ad-image-groups-container');
        if (!container) return;
        const groups = container.querySelectorAll('.ad-image-group:not([style*="display: none"])');
        if (groups.length === 0) {
            addAdImageGroup();
        }
    }

    // 更新表单验证规则
    function updateFormValidation(type) {
        // 移除所有字段的必填验证
        const allInputs = document.querySelectorAll('.ad-type-fields input, .ad-type-fields textarea, .ad-type-fields select');
        allInputs.forEach(input => {
            input.removeAttribute('required');
        });

        // 根据广告类型添加相应的必填验证
        switch (type) {
            case 'image':
                // 图片广告至少需要一张图片
                break;
            case 'media':
                // 多媒体广告通过组件选择文件，不设置额外的必填属性
                break;
            case 'code':
                // 代码广告通过容器校验至少一项非空，不设置单字段必填（见 validateForm 中的检查）
                break;
        }
    }

    function openMediaModal(targetInputId, multiple = false) {
        if (typeof window.selectMediaForField !== 'function') {
            console.error('媒体模态框未初始化');
            return;
        }

        const targetElement = document.getElementById(targetInputId);
        const isImageField = targetElement ? targetElement.classList.contains('image-url-input') : (targetInputId !== 'media_url');

        selectMediaForField({
            title: multiple ? (isImageField ? '选择多张图片' : '选择文件') : (isImageField ? '选择图片' : '选择媒体'),
            button_text: isImageField ? '插入图片' : '插入媒体',
            multiple: !!multiple,
            type: isImageField ? 'image' : 'file',
            input_id: targetInputId,
            callback: function (selected) {
                let url = '';
                if (Array.isArray(selected)) {
                    if (selected.length) {
                        url = selected[0].url || '';
                    }
                } else if (selected && typeof selected === 'object') {
                    url = selected.url || '';
                }

                const el = document.getElementById(targetInputId);
                if (el && url) {
                    el.value = url;
                    if (isImageField || el.classList.contains('image-url-input')) {
                        // 更新图片预览（仅图片字段）
                        updateImagePreview(el, url);
                    }
                }
            }
        });
    }

    // 多媒体组管理函数（复刻图片组逻辑，统一样式与结构）
    function addAdMedia() {
        const container = document.getElementById('ad-media-container');
        if (!container) return;
        const template = container.querySelector('.ad-media-item');
        if (!template) return;

        const visibleGroups = container.querySelectorAll('.ad-media-item:not([style*="display: none"])');
        const newIndex = visibleGroups.length;

        const node = template.cloneNode(true);
        node.style.display = 'block';
        node.querySelectorAll('.media-modal-overlay').forEach(el => el.remove());

        // 更新组标题编号（沿用image样式类）
        const num = node.querySelector('.image-item-number');
        if (num) num.textContent = `多媒体组 #${newIndex + 1}`;

        const groupFieldId = `ad_media_group_${newIndex}`;

        // 更新组内特色组件容器的id/name
        const hiddenInput = node.querySelector('input[type="hidden"][name^="ad_media_groups["]');
        if (hiddenInput) {
            hiddenInput.id = `${groupFieldId}_data`;
            hiddenInput.name = `ad_media_groups[${newIndex}][medias]`;
        }

        const componentWrap = node.querySelector('.featured-images-component, .universal-images-component');
        if (componentWrap) {
            componentWrap.id = groupFieldId;
            componentWrap.setAttribute('data-field-name', `ad_media_groups[${newIndex}][medias]`);
            componentWrap.setAttribute('data-max-images', '20');
            componentWrap.setAttribute('data-multiple', 'true');
            componentWrap.setAttribute('data-file-type', 'video');
        }

        const addBtn = node.querySelector('.add-featured-image, .add-universal-image');
        if (addBtn) {
            addBtn.setAttribute('data-field-id', groupFieldId);
            addBtn.setAttribute('data-multiple', 'true');
        }

        // 更新共享字段name
        const titleInput = node.querySelector('input[name^="ad_media_groups[0][title]"]');
        if (titleInput) titleInput.name = `ad_media_groups[${newIndex}][title]`;
        const linkInput = node.querySelector('input[name^="ad_media_groups[0][link]"]');
        if (linkInput) linkInput.name = `ad_media_groups[${newIndex}][link]`;
        const targetSelect = node.querySelector('select[name^="ad_media_groups[0][target]"]');
        if (targetSelect) targetSelect.name = `ad_media_groups[${newIndex}][target]`;
        const whiteCheckbox = node.querySelector('input[type="checkbox"][name^="ad_media_groups[0][white_text]"]');
        if (whiteCheckbox) whiteCheckbox.name = `ad_media_groups[${newIndex}][white_text]`;
        const contentTextarea = node.querySelector('textarea[name^="ad_media_groups[0][content]"]');
        if (contentTextarea) contentTextarea.name = `ad_media_groups[${newIndex}][content]`;

        container.appendChild(node);

        // 初始化该组的特色组件实例（视频）
        if (window.FeaturedImagesComponent) {
            new window.FeaturedImagesComponent({
                fieldId: groupFieldId,
                fieldName: `ad_media_groups[${newIndex}][medias]`,
                multiple: true,
                maxImages: 20,
                fileType: 'video',
                enableDebug: false,
                containerEl: node.querySelector(`#${groupFieldId}`)
            });
        }
    }

    function removeAdMedia(button) {
        const group = button.closest('.ad-media-item');
        const container = document.getElementById('ad-media-container');
        if (!group || !container) return;

        const visibleGroups = container.querySelectorAll('.ad-media-item:not([style*="display: none"])');
        if (visibleGroups.length <= 1) {
            TodoCMS.Utils.showMessage('至少需要保留一个多媒体组', 'warning');
            return;
        }

        group.remove();

        // 重新编号与name重排
        const groups = container.querySelectorAll('.ad-media-item:not([style*="display: none"])');
        groups.forEach((node, idx) => {
            const num = node.querySelector('.image-item-number');
            if (num) num.textContent = `多媒体组 #${idx + 1}`;
            const groupFieldId = `ad_media_group_${idx}`;
            const hiddenInput = node.querySelector('input[type="hidden"][id$="_data"]');
            if (hiddenInput) {
                hiddenInput.id = `${groupFieldId}_data`;
                hiddenInput.name = `ad_media_groups[${idx}][medias]`;
            }
            const componentWrap = node.querySelector('.featured-images-component, .universal-images-component');
            if (componentWrap) {
                componentWrap.id = groupFieldId;
                componentWrap.setAttribute('data-field-name', `ad_media_groups[${idx}][medias]`);
            }
            const addBtn = node.querySelector('.add-featured-image, .add-universal-image');
            if (addBtn) {
                addBtn.setAttribute('data-field-id', groupFieldId);
            }
            const titleInput = node.querySelector('input[name$="[title]"]');
            if (titleInput) titleInput.name = `ad_media_groups[${idx}][title]`;
            const linkInput = node.querySelector('input[name$="[link]"]');
            if (linkInput) linkInput.name = `ad_media_groups[${idx}][link]`;
            const targetSelect = node.querySelector('select[name$="[target]"]');
            if (targetSelect) targetSelect.name = `ad_media_groups[${idx}][target]`;
            const whiteCheckbox = node.querySelector('input[type="checkbox"][name$="[white_text]"]');
            if (whiteCheckbox) whiteCheckbox.name = `ad_media_groups[${idx}][white_text]`;
            const contentTextarea = node.querySelector('textarea[name$="[content]"]');
            if (contentTextarea) contentTextarea.name = `ad_media_groups[${idx}][content]`;
        });
    }

    // 代码广告项管理（与图片/多媒体一致，默认至少一个项）
    function addAdCode() {
        const container = document.getElementById('ad-code-container');
        if (!container) return;
        const template = container.querySelector('.ad-code-item');
        if (!template) return;

        const visible = container.querySelectorAll('.ad-code-item:not([style*="display: none"])');
        const newIndex = visible.length;

        const node = template.cloneNode(true);
        node.style.display = 'block';
        node.querySelectorAll('.media-modal-overlay').forEach(el => el.remove());

        const num = node.querySelector('.code-item-number');
        if (num) num.textContent = `代码 #${newIndex + 1}`;

        // 重命名 textarea 为数组索引并启用（模板中默认 disabled，克隆后启用）
        const textarea = node.querySelector('textarea');
        if (textarea) { textarea.disabled = false; textarea.name = `ad_html_contents[${newIndex}]`; }

        container.appendChild(node);
    }

    function removeAdCode(button) {
        const item = button.closest('.ad-code-item');
        const container = document.getElementById('ad-code-container');
        if (!item || !container) return;

        const visible = container.querySelectorAll('.ad-code-item:not([style*="display: none"])');
        if (visible.length <= 1) {
            TodoCMS.Utils.showMessage('至少需要保留一个代码广告', 'warning');
            return;
        }

        TodoCMS.Utils.confirm('确定要删除这个代码广告吗？', function () {
                item.remove();

                // 重新编号与name重排
                const items = container.querySelectorAll('.ad-code-item:not([style*="display: none"])');
                items.forEach((node, idx) => {
                    const num = node.querySelector('.code-item-number');
                    if (num) num.textContent = `代码 #${idx + 1}`;
                    const textarea = node.querySelector('textarea');
                    if (textarea) textarea.name = `ad_html_contents[${idx}]`;
                });
        });
    }

    // 图片组管理函数
    function addAdImageGroup() {
        const container = document.getElementById('ad-image-groups-container');
        if (!container) return;
        const template = container.querySelector('.ad-image-group');
        if (!template) return;

        // 计算新的组索引
        const visibleGroups = container.querySelectorAll('.ad-image-group:not([style*="display: none"])');
        const newIndex = visibleGroups.length; // 从0开始

        // 克隆模板
        const node = template.cloneNode(true);
        node.style.display = 'block';

        // 更新组标题编号
        const num = node.querySelector('.image-item-number');
        if (num) num.textContent = `图片组 #${newIndex + 1}`;

        // 生成唯一field_id，并更新子控件的name为数组索引形式
        const groupFieldId = `ad_image_group_${newIndex}`;

        // 更新组内特色组件容器的id/name
        const hiddenInput = node.querySelector('input[type="hidden"][name^="ad_groups["]');
        if (hiddenInput) {
            hiddenInput.id = `${groupFieldId}_data`;
            hiddenInput.name = `ad_groups[${newIndex}][images]`;
        }

        const componentWrap = node.querySelector('.featured-images-component, .universal-images-component');
        if (componentWrap) {
            componentWrap.id = groupFieldId;
            componentWrap.setAttribute('data-field-name', `ad_groups[${newIndex}][images]`);
            componentWrap.setAttribute('data-max-images', '20');
            componentWrap.setAttribute('data-multiple', 'true');
            componentWrap.setAttribute('data-file-type', 'image');
        }

        // 更新操作按钮的modal-id和field-id
        const addBtn = node.querySelector('.add-featured-image, .add-universal-image');
        if (addBtn) {
            addBtn.setAttribute('data-field-id', groupFieldId);
            addBtn.setAttribute('data-multiple', 'true');
        }

        // 更新共享字段name
        const titleInput = node.querySelector('input[name^="ad_groups[0][title]"]');
        if (titleInput) titleInput.name = `ad_groups[${newIndex}][title]`;
        const linkInput = node.querySelector('input[name^="ad_groups[0][link]"]');
        if (linkInput) linkInput.name = `ad_groups[${newIndex}][link]`;
        const targetSelect = node.querySelector('select[name^="ad_groups[0][target]"]');
        if (targetSelect) targetSelect.name = `ad_groups[${newIndex}][target]`;
        const whiteCheckbox = node.querySelector('input[type="checkbox"][name^="ad_groups[0][white_text]"]');
        if (whiteCheckbox) whiteCheckbox.name = `ad_groups[${newIndex}][white_text]`;
        const contentTextarea = node.querySelector('textarea[name^="ad_groups[0][content]"]');
        if (contentTextarea) contentTextarea.name = `ad_groups[${newIndex}][content]`;

        container.appendChild(node);

        // 初始化该组的特色图片组件实例
        if (window.FeaturedImagesComponent) {
            new window.FeaturedImagesComponent({
                fieldId: groupFieldId,
                fieldName: `ad_groups[${newIndex}][images]`,
                multiple: true,
                maxImages: 20,
                fileType: 'image',
                enableDebug: false,
                containerEl: node.querySelector(`#${groupFieldId}`)
            });
        }
    }

    function removeAdImageGroup(button) {
        const group = button.closest('.ad-image-group');
        const container = document.getElementById('ad-image-groups-container');
        if (!group || !container) return;

        const visibleGroups = container.querySelectorAll('.ad-image-group:not([style*="display: none"])');
        if (visibleGroups.length <= 1) {
            TodoCMS.Utils.showMessage('至少需要保留一个图片组', 'warning');
            return;
        }

        TodoCMS.Utils.confirm('确定要删除这个图片组吗？', function () {
                group.remove();

                // 重新编号与name重排
                const groups = container.querySelectorAll('.ad-image-group:not([style*="display: none"])');
                groups.forEach((node, idx) => {
                    const num = node.querySelector('.image-item-number');
                    if (num) num.textContent = `图片组 #${idx + 1}`;
                    const groupFieldId = `ad_image_group_${idx}`;
                    const hiddenInput = node.querySelector('input[type="hidden"][id$="_data"]');
                    if (hiddenInput) {
                        hiddenInput.id = `${groupFieldId}_data`;
                        hiddenInput.name = `ad_groups[${idx}][images]`;
                    }
                    const componentWrap = node.querySelector('.featured-images-component, .universal-images-component');
                    if (componentWrap) {
                        componentWrap.id = groupFieldId;
                        componentWrap.setAttribute('data-field-name', `ad_groups[${idx}][images]`);
                    }
                    const addBtn = node.querySelector('.add-featured-image, .add-universal-image');
                    if (addBtn) {
                        addBtn.setAttribute('data-field-id', groupFieldId);
                    }
                    const titleInput = node.querySelector('input[name$="[title]"]');
                    if (titleInput) titleInput.name = `ad_groups[${idx}][title]`;
                    const linkInput = node.querySelector('input[name$="[link]"]');
                    if (linkInput) linkInput.name = `ad_groups[${idx}][link]`;
                    const targetSelect = node.querySelector('select[name$="[target]"]');
                    if (targetSelect) targetSelect.name = `ad_groups[${idx}][target]`;
                    const whiteCheckbox = node.querySelector('input[type="checkbox"][name$="[white_text]"]');
                    if (whiteCheckbox) whiteCheckbox.name = `ad_groups[${idx}][white_text]`;
                    const contentTextarea = node.querySelector('textarea[name$="[content]"]');
                    if (contentTextarea) contentTextarea.name = `ad_groups[${idx}][content]`;
                });
        });
    }
    function selectAdImage(button) {
        const imageControls = button.closest('.image-controls');
        const input = imageControls.querySelector('.image-url-input');
        if (!input) return;

        if (typeof window.selectMediaForField !== 'function') {
            console.error('媒体模态框未初始化');
            return;
        }

        window.selectMediaForField({
            title: '选择图片',
            button_text: '插入图片',
            multiple: false,
            type: 'image',
            callback: function (selected) {
                let url = '';
                if (Array.isArray(selected)) {
                    if (selected.length) url = selected[0].url || '';
                } else if (selected && typeof selected === 'object') {
                    url = selected.url || '';
                }
                if (url) {
                    input.value = url;
                    updateImagePreview(input, url);
                }
            }
        });
    }

    // 更新图片预览
    function updateImagePreview(input, imageUrl) {
        const previewSection = input.closest('.image-preview-section');
        if (!previewSection) return;

        const previewImg = previewSection.querySelector('.preview-img');
        const placeholder = previewSection.querySelector('.preview-placeholder');

        if (imageUrl && imageUrl.trim() !== '') {
            previewImg.src = imageUrl;
            previewImg.style.display = 'block';
            placeholder.style.display = 'none';

            // 添加图片加载错误处理
            previewImg.onerror = function () {
                this.style.display = 'none';
                placeholder.style.display = 'block';
                placeholder.textContent = '图片加载失败';
            };

            previewImg.onload = function () {
                placeholder.style.display = 'none';
            };
        } else {
            previewImg.style.display = 'none';
            previewImg.src = '';
            placeholder.style.display = 'block';
            placeholder.textContent = '点击选择图片';
        }
    }

    // 页面加载时初始化
    document.addEventListener('DOMContentLoaded', function () {
        // 为广告类型选择器添加变化监听
        const typeSelect = document.getElementById('type');
        if (typeSelect) {
            typeSelect.addEventListener('change', function () {
                toggleAdTypeFields();
            });
        } else {
            console.error('❌ 未找到类型选择器');
        }

        // 初始化广告类型字段显示
        toggleAdTypeFields();

        // 页面初始时，确保每种类型容器都至少有一个默认项（避免切换前空白）
        initializeAdImageGroups();
        initializeAdMediaGroups();
        initializeAdCodeItems();

        // 添加表单提交验证
        const form = document.querySelector('.admin-ad-form');
        if (form) {
            form.addEventListener('submit', function (e) {
                if (!validateForm()) {
                    e.preventDefault();
                    return false;
                }
            });
        }
    });

    // 表单验证函数
    function validateForm() {
        const type = document.getElementById('type').value;

        if (!type) {
            TodoCMS.Utils.showMessage('请选择广告类型', 'warning');
            return false;
        }

        // 根据广告类型进行特定验证
        switch (type) {
            case 'image':
                let hasValidImage = false;
                // 优先采用新版“图片组 + 特色图片组件”的验证
                const groupsContainer = document.getElementById('ad-image-groups-container');
                if (groupsContainer) {
                    const groups = groupsContainer.querySelectorAll('.ad-image-group:not([style*="display: none"])');
                    groups.forEach(group => {
                        const hidden = group.querySelector('input[type="hidden"][name$="[images]"]');
                        if (!hidden) return;
                        try {
                            const arr = JSON.parse(hidden.value || '[]');
                            if (Array.isArray(arr) && arr.length > 0) {
                                hasValidImage = true;
                            }
                        } catch (e) { }
                    });
                }
                if (!hasValidImage) {
                    TodoCMS.Utils.showMessage('图片广告至少需要选择一张图片', 'warning');
                    return false;
                }
                break;
            case 'media':
                // 多媒体广告至少需要选择一个媒体文件（任一可见组内）
                const mediaContainer = document.getElementById('ad-media-container');
                const visibleMediaGroups = mediaContainer ? mediaContainer.querySelectorAll('.ad-media-item:not([style*="display: none"])') : [];
                let hasValidMedia = false;
                visibleMediaGroups.forEach(group => {
                    const hiddenInputs = group.querySelectorAll('input[type="hidden"][name$="[medias]"]');
                    hiddenInputs.forEach(input => {
                        try {
                            const val = input.value || '[]';
                            const arr = JSON.parse(val);
                            if (Array.isArray(arr) && arr.length > 0) {
                                hasValidMedia = true;
                            }
                        } catch (e) { }
                    });
                });
                if (!hasValidMedia) {
                    TodoCMS.Utils.showMessage('请至少为一个多媒体组选择媒体文件', 'warning');
                    return false;
                }
                break;
            case 'code':
                // 至少需要一个代码项非空
                const codeContainer = document.getElementById('ad-code-container');
                const codeTextareas = codeContainer ? codeContainer.querySelectorAll('textarea[name^="ad_html_contents["]') : [];
                let hasCode = false;
                codeTextareas.forEach(t => { if (t.value && t.value.trim() !== '') hasCode = true; });
                if (!hasCode) {
                    TodoCMS.Utils.showMessage('请输入至少一条HTML代码内容', 'warning');
                    return false;
                }
                break;
        }

        return true;
    }
</script>
