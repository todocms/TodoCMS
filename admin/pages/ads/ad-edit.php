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
 * 广告编辑页面 - 采用与 ad-new.php 一致的布局/样式/逻辑
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    todo_die('Direct access not permitted');
}

// 加载必要的类和组件（与 ad-new.php 保持一致）
require_once TODO_ADMIN_PATH . '/components/featured-images-component.php';

// 初始化广告管理器
$ads_manager = Todo_Ads_Manager::init();

// 权限校验
if (!current_user_can('manage_ads')) {
    todo_die('您没有管理广告的权限。');
}

// 获取广告ID
$ad_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($ad_id <= 0) {
    todo_die('缺少有效的广告ID。');
}

// 读取广告数据
$ad = $ads_manager->get_ad($ad_id);
if (!$ad) {
    todo_die('广告不存在');
}

$message = '';
$error = '';

// PRG 提示处理
if (isset($_GET['notice'])) {
    if ($_GET['notice'] === 'success') {
        $message = isset($_GET['msg']) ? urldecode((string) $_GET['msg']) : '';
    } else {
        $error = isset($_GET['msg']) ? urldecode((string) $_GET['msg']) : '';
    }
}

// 处理表单提交（更新）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_ad') {
    // 验证CSRF
    if (!Todo_Security_Manager::verify_request_csrf('edit_ad', '_todononce')) {
        todo_die('安全验证失败。');
    }

    // 基础字段
    $title = sanitize_text_field($_POST['title'] ?? '');
    $type = sanitize_text_field($_POST['type'] ?? '');
    $link_url = isset($_POST['link_url']) ? esc_url_raw($_POST['link_url']) : '';
    $target = isset($_POST['target']) ? sanitize_text_field($_POST['target']) : '_self';
    $sort_order = intval($_POST['sort_order'] ?? 0);
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
    $group_id = intval($_POST['group_id'] ?? 0);

    // 每日时间段
    $start_time = sanitize_text_field($_POST['start_time'] ?? '');
    $end_time = sanitize_text_field($_POST['end_time'] ?? '');

    // 规范化 target
    if (!in_array($target, array('_self', '_blank'), true)) {
        $target = '_self';
    }

    // 处理图片组（与 ad-new.php 逻辑一致）
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

            if (!in_array($group_target, array('_self', '_blank'), true)) {
                $group_target = '_self';
            }

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
                'content' => $group_content,
                'images' => $group_images,
            );
        }
    }
    $images = !empty($flattened_image_urls) ? json_encode($flattened_image_urls) : '';
    $images_grouped_json = json_encode(array(
        'version' => '1.0',
        'type' => 'image_groups',
        'groups' => $ad_group_objects
    ));

    // 处理多媒体组（与 ad-new.php 逻辑一致）
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
                'content' => $group_content,
                'medias' => $group_medias,
            );
        }
    }
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
                // 存储为对象，便于后续扩展（如标题、备注等）
                $items[] = array('html' => $t);
            }
        }
        $code_grouped_json = json_encode(array(
            'version' => '1.0',
            'type' => 'html_codes',
            'items' => $items
        ));
    } elseif (isset($_POST['html_content'])) {
        // 单字段兼容：仍然存入结构化JSON，只有一个item
        $t = (string) $_POST['html_content'];
        $code_grouped_json = json_encode(array(
            'version' => '1.0',
            'type' => 'html_codes',
            'items' => trim($t) !== '' ? array(array('html' => $t)) : array()
        ));
    }

    // 验证必填
    if (empty($title) || empty($type)) {
        $error = '请填写所有必填字段。';
    } else {
        // 根据类型确定 content
        switch ($type) {
            case 'image':
                $content = $images_grouped_json;
                break;
            case 'media':
                $content = $medias_grouped_json;
                break;
            case 'code':
            default:
                // 保存为结构化JSON，避免多个代码合并为一个
                $content = $code_grouped_json;
                break;
        }

        $data = array(
            'title' => $title,
            'type' => $type,
            'content' => $content,
            'images' => $images,
            'link_url' => $link_url,
            'target' => $target,
            'group_id' => $group_id,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'sort_order' => $sort_order,
            'status' => $status,
            'start_date' => $start_date,
            'end_date' => $end_date
        );

        $result = Todo_Ads_Manager::update_ad($ad_id, $data);
        if ($result) {
            todo_redirect(todo_admin_url('index.php?page=ad-edit&id=' . $ad_id . '&notice=success&msg=' . urlencode('广告更新成功！')));
            exit;
        } else {
            $error = '广告更新失败！';
        }
    }
}

// 获取广告分组
$ad_groups = Todo_Ads_Manager::get_ad_groups(array('status' => 'active'));

// 解析已存内容用于表单预填
$ad_type = $ad['type'];
$decoded_content = null;
if (!empty($ad['content'])) {
    $maybe = json_decode($ad['content'], true);
    if (is_array($maybe)) {
        $decoded_content = $maybe;
    }
}
?>

<div class="admin-ad-new-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">编辑广告</h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=ads'); ?>" class="button button-secondary">返回广告列表</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html($message); ?></p><button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html($error); ?></p><button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <form method="post" class="admin-ad-form todo-request">
        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">广告基本信息</h3>
            </div>
            <div class="admin-card-content">
                <?php echo Todo_Security_Manager::csrf_field('edit_ad', '_todononce'); ?>
                <input type="hidden" name="action" value="edit_ad">

                <div class="form-group">
                    <label for="title">广告名称 <span class="required">*</span></label>
                    <input name="title" type="text" id="title"
                        value="<?php echo esc_attr(isset($_POST['title']) ? $_POST['title'] : $ad['title']); ?>"
                        required>
                    <p class="description">广告的显示名称，仅用于后台管理。</p>
                </div>

                <div class="form-group">
                    <label for="type">广告类型 <span class="required">*</span></label>
                    <select name="type" id="type" required>
                        <option value="">请选择广告类型</option>
                        <option value="image" <?php selected(isset($_POST['type']) ? $_POST['type'] : $ad_type, 'image'); ?>>📷 图片广告</option>
                        <option value="media" <?php selected(isset($_POST['type']) ? $_POST['type'] : $ad_type, 'media'); ?>>🎬 多媒体广告</option>
                        <option value="code" <?php selected(isset($_POST['type']) ? $_POST['type'] : $ad_type, 'code'); ?>>💻 HTML代码</option>
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
                                <!-- 图片组模板（隐藏） -->
                                <div class="ad-image-group" style="display: none;">
                                    <div class="image-item-card">
                                        <div class="image-item-header">
                                            <span class="image-item-number">图片组 #1</span>
                                            <button type="button" class="btn button-link-delete"
                                                onclick="removeAdImageGroup(this)">删除</button>
                                        </div>
                                        <div class="image-item-content">
                                            <?php
                                            if (function_exists('todo_render_featured_images_component')) {
                                                // 使用模板专用的占位ID，避免与首个实际图片组（索引0）产生ID重复
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
                                                                value="1"> Light效果
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php
                                // 预填已有图片组
                                if (($ad_type === 'image') && $decoded_content && ($decoded_content['type'] ?? '') === 'image_groups' && !empty($decoded_content['groups'])) {
                                    $idx = 0;
                                    foreach ($decoded_content['groups'] as $g) {
                                        $gid = 'ad_image_group_' . $idx;
                                        $current_images = isset($g['images']) && is_array($g['images']) ? $g['images'] : array();
                                        ?>
                                        <div class="ad-image-group" style="display: block;">
                                            <div class="image-item-card">
                                                <div class="image-item-header">
                                                    <span class="image-item-number">图片组 #<?php echo ($idx + 1); ?></span>
                                                    <button type="button" class="btn button-link-delete"
                                                        onclick="removeAdImageGroup(this)">删除</button>
                                                </div>
                                                <div class="image-item-content">
                                                    <?php
                                                    echo todo_render_featured_images_component(array(
                                                        'field_id' => $gid,
                                                        'field_name' => 'ad_groups[' . $idx . '][images]',
                                                        'current_images' => $current_images,
                                                        'multiple' => true,
                                                        'max_images' => 20,
                                                        'file_type' => 'image',
                                                        'enable_debug' => false,
                                                        'variant' => 'universal'
                                                    ));
                                                    ?>
                                                    <div class="image-settings-section">
                                                        <div class="setting-row">
                                                            <div class="setting-group">
                                                                <label>组标题</label>
                                                                <input type="text"
                                                                    name="ad_groups[<?php echo esc_attr($idx); ?>][title]"
                                                                    value="<?php echo esc_attr($g['title'] ?? ''); ?>"
                                                                    class="regular-text">
                                                            </div>
                                                            <div class="setting-group">
                                                                <label>链接地址</label>
                                                                <input type="url"
                                                                    name="ad_groups[<?php echo esc_attr($idx); ?>][link]"
                                                                    value="<?php echo esc_attr($g['link'] ?? ''); ?>"
                                                                    class="regular-text" pattern="https?://.*"
                                                                    title="请输入完整的URL地址，包含http://或https://">
                                                            </div>
                                                        </div>
                                                        <div class="setting-row">
                                                            <div class="setting-group">
                                                                <label>广告内容</label>
                                                                <textarea
                                                                    name="ad_groups[<?php echo esc_attr($idx); ?>][content]"
                                                                    class="regular-text"><?php echo esc_textarea($g['content'] ?? ''); ?></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="setting-row">
                                                            <div>
                                                                <label>打开方式</label>
                                                                <select name="ad_groups[<?php echo esc_attr($idx); ?>][target]"
                                                                    class="regular-text">
                                                                    <option value="_self" <?php selected($g['target'] ?? '_self', '_self'); ?>>当前窗口</option>
                                                                    <option value="_blank" <?php selected($g['target'] ?? '_self', '_blank'); ?>>新窗口</option>
                                                                </select>
                                                            </div>
                                                            <div>
                                                                <span>特殊样式</span>
                                                                <label style="margin: 10px 0 0;">
                                                                    <input type="checkbox"
                                                                        name="ad_groups[<?php echo esc_attr($idx); ?>][white_text]"
                                                                        value="1" <?php checked(!empty($g['white_text'])); ?>>
                                                                    Light效果
                                                                </label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php
                                        $idx++;
                                    }
                                }
                                ?>
                            </div>
                            <div class="admin-form-submit">
                                <button type="button" class="button button-primary" onclick="addAdImageGroup()">+
                                    添加图片组</button>
                            </div>
                            <p class="description">每个“图片组”可添加多张图片，该组图片共享同一标题、链接、打开方式和样式。</p>
                        </div>
                    </div>
                </div>

                <!-- 多媒体广告字段 -->
                <div id="media-fields" class="ad-type-fields" style="display: none;">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">多媒体广告设置</h3>
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="padding: 0 30px;">
                            <div id="ad-media-container" class="flex flex-wrap gap-5">
                                <!-- 模板（隐藏） -->
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
                                                // 使用模板专用的占位ID，避免与首个实际多媒体组（索引0）产生ID重复
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
                                                                value="1"> Light效果
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php
                                if (($ad_type === 'media') && $decoded_content && ($decoded_content['type'] ?? '') === 'media_groups' && !empty($decoded_content['groups'])) {
                                    $idx = 0;
                                    foreach ($decoded_content['groups'] as $g) {
                                        $gid = 'ad_media_group_' . $idx;
                                        $current_medias = isset($g['medias']) && is_array($g['medias']) ? $g['medias'] : array();
                                        ?>
                                        <div class="ad-image-group ad-media-item" style="display: block;">
                                            <div class="image-item-card">
                                                <div class="image-item-header">
                                                    <span class="image-item-number">多媒体组 #<?php echo ($idx + 1); ?></span>
                                                    <button type="button" class="btn button-link-delete"
                                                        onclick="removeAdMedia(this)">删除</button>
                                                </div>
                                                <div class="image-item-content">
                                                    <?php
                                                    echo todo_render_featured_images_component(array(
                                                        'field_id' => $gid,
                                                        'field_name' => 'ad_media_groups[' . $idx . '][medias]',
                                                        'current_images' => $current_medias,
                                                        'multiple' => true,
                                                        'max_images' => 20,
                                                        'file_type' => 'video',
                                                        'media_type' => 'video',
                                                        'enable_debug' => false,
                                                        'variant' => 'universal'
                                                    ));
                                                    ?>
                                                    <div class="image-settings-section">
                                                        <div class="setting-row">
                                                            <div class="setting-group">
                                                                <label>组标题</label>
                                                                <input type="text"
                                                                    name="ad_media_groups[<?php echo esc_attr($idx); ?>][title]"
                                                                    value="<?php echo esc_attr($g['title'] ?? ''); ?>"
                                                                    class="regular-text">
                                                            </div>
                                                            <div class="setting-group">
                                                                <label>链接地址</label>
                                                                <input type="url"
                                                                    name="ad_media_groups[<?php echo esc_attr($idx); ?>][link]"
                                                                    value="<?php echo esc_attr($g['link'] ?? ''); ?>"
                                                                    class="regular-text" pattern="https?://.*"
                                                                    title="请输入完整的URL地址，包含http://或https://">
                                                            </div>
                                                        </div>
                                                        <div class="setting-row">
                                                            <div class="setting-group">
                                                                <label>广告内容</label>
                                                                <textarea
                                                                    name="ad_media_groups[<?php echo esc_attr($idx); ?>][content]"
                                                                    class="regular-text"><?php echo esc_textarea($g['content'] ?? ''); ?></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="setting-row">
                                                            <div>
                                                                <label>打开方式</label>
                                                                <select
                                                                    name="ad_media_groups[<?php echo esc_attr($idx); ?>][target]"
                                                                    class="regular-text">
                                                                    <option value="_self" <?php selected($g['target'] ?? '_self', '_self'); ?>>当前窗口</option>
                                                                    <option value="_blank" <?php selected($g['target'] ?? '_self', '_blank'); ?>>新窗口</option>
                                                                </select>
                                                            </div>
                                                            <div>
                                                                <span>特殊样式</span>
                                                                <label style="margin: 10px 0 0;">
                                                                    <input type="checkbox"
                                                                        name="ad_media_groups[<?php echo esc_attr($idx); ?>][white_text]"
                                                                        value="1" <?php checked(!empty($g['white_text'])); ?>>
                                                                    Light效果
                                                                </label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php
                                        $idx++;
                                    }
                                }
                                ?>
                            </div>
                            <div class="admin-form-submit">
                                <button type="button" class="button button-primary" onclick="addAdMedia()">+
                                    添加多媒体</button>
                            </div>
                            <p class="description">可添加多个多媒体广告，每个可独立设置标题、链接等属性。</p>
                        </div>
                    </div>
                </div>

                <!-- 代码广告字段 -->
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
                                                            placeholder="自定义HTML代码，支持JavaScript" disabled></textarea>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php
                                // 预填代码项（尽量兼容：若content可解析出数组则按数组渲染，否则单项填充原始字符串）
                                if ($ad_type === 'code') {
                                    $code_items = array();
                                    if (is_array($decoded_content) && !empty($decoded_content['items']) && is_array($decoded_content['items'])) {
                                        foreach ($decoded_content['items'] as $it) {
                                            if (is_array($it) && isset($it['html']))
                                                $code_items[] = (string) $it['html'];
                                            elseif (is_string($it))
                                                $code_items[] = $it;
                                        }
                                    }
                                    if (empty($code_items) && !empty($ad['content'])) {
                                        $code_items[] = (string) $ad['content'];
                                    }
                                    if (!empty($code_items)) {
                                        foreach ($code_items as $i => $html) {
                                            ?>
                                            <div class="ad-image-group ad-code-item" style="display: block;">
                                                <div class="image-item-card">
                                                    <div class="image-item-header">
                                                        <span class="image-item-number code-item-number">代码
                                                            #<?php echo ($i + 1); ?></span>
                                                        <button type="button" class="btn button-link-delete"
                                                            onclick="removeAdCode(this)">删除</button>
                                                    </div>
                                                    <div class="image-item-content">
                                                        <div class="code-settings-section">
                                                            <div class="setting-row">
                                                                <div class="setting-group" style="width:100%;">
                                                                    <label>广告代码</label>
                                                                    <textarea name="ad_html_contents[<?php echo $i; ?>]" rows="6"
                                                                        class="large-text code" style="min-width: 100%;"
                                                                        placeholder="自定义HTML代码，支持JavaScript"><?php echo esc_textarea($html); ?></textarea>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php
                                        }
                                    }
                                }
                                ?>
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
                                <option value="<?php echo $group['id']; ?>" <?php selected(isset($_POST['group_id']) ? $_POST['group_id'] : ($ad['group_id'] ?? 0), $group['id']); ?>>
                                    <?php echo esc_html($group['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">选择广告所属的分组。</p>
                    </div>
                    <div class="form-group">
                        <label for="status">状态</label>
                        <select name="status" id="status">
                            <option value="1" <?php selected(isset($_POST['status']) ? intval($_POST['status']) : intval($ad['status'] ?? 1), 1); ?>>激活</option>
                            <option value="0" <?php selected(isset($_POST['status']) ? intval($_POST['status']) : intval($ad['status'] ?? 1), 0); ?>>停用</option>
                        </select>
                        <p class="description">广告的显示状态。</p>
                    </div>
                    <div class="form-group">
                        <label for="sort_order">排序</label>
                        <input name="sort_order" type="number" id="sort_order"
                            value="<?php echo esc_attr(isset($_POST['sort_order']) ? $_POST['sort_order'] : ($ad['sort_order'] ?? 0)); ?>"
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
                            value="<?php echo esc_attr(isset($_POST['start_date']) ? $_POST['start_date'] : (!empty($ad['start_date']) ? date('Y-m-d\TH:i', strtotime($ad['start_date'])) : '')); ?>"
                            class="regular-text">
                        <p class="description">广告开始显示的日期时间。</p>
                    </div>
                    <div class="form-group">
                        <label for="end_date">结束日期</label>
                        <input name="end_date" type="datetime-local" id="end_date"
                            value="<?php echo esc_attr(isset($_POST['end_date']) ? $_POST['end_date'] : (!empty($ad['end_date']) ? date('Y-m-d\TH:i', strtotime($ad['end_date'])) : '')); ?>"
                            class="regular-text">
                        <p class="description">广告停止显示的日期时间。</p>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="start_time">每日开始时间</label>
                        <input name="start_time" type="time" id="start_time"
                            value="<?php echo esc_attr(isset($_POST['start_time']) ? $_POST['start_time'] : ($ad['start_time'] ?? '')); ?>"
                            class="regular-text">
                        <p class="description">每日开始显示的时间。</p>
                    </div>
                    <div class="form-group">
                        <label for="end_time">每日结束时间</label>
                        <input name="end_time" type="time" id="end_time"
                            value="<?php echo esc_attr(isset($_POST['end_time']) ? $_POST['end_time'] : ($ad['end_time'] ?? '')); ?>"
                            class="regular-text">
                        <p class="description">每日停止显示的时间。</p>
                    </div>
                </div>

            </div>
        </div>
        <div class="admin-form-submit">
            <input type="submit" name="submit" value="更新广告" class="button button-primary">
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
    // 优化的广告类型字段切换（与 ad-new.php 同步）
    function toggleAdTypeFields() {
        const type = document.getElementById('type').value;
        const fields = document.querySelectorAll('.ad-type-fields');
        const typeHint = document.getElementById('type-hint');
        fields.forEach(field => { field.style.display = 'none'; field.style.opacity = '0'; });
        if (type) {
            const targetField = document.getElementById(type + '-fields');
            if (targetField) { targetField.style.display = 'block'; targetField.style.opacity = '1'; }
            const hints = { 'image': '图片广告，支持单图或多图展示，多组则循环每个组的并取组内第一张图片，仅有一个组则循环组内的所有图片。', 'media': '多媒体广告，支持视频、音频等多媒体文件', 'code': '自定义HTML/JavaScript代码广告' };
            if (hints[type]) { typeHint.textContent = hints[type]; typeHint.style.display = 'inline-block'; }
        } else { typeHint.style.display = 'none'; }
        updateFormValidation(type);
    }

    function initializeAdImageGroups() {
        const container = document.getElementById('ad-image-groups-container');
        if (!container) return;
        const groups = container.querySelectorAll('.ad-image-group:not([style*="display: none"])');
        if (groups.length === 0) { addAdImageGroup(); }
    }
    function initializeAdMediaGroups() {
        const container = document.getElementById('ad-media-container');
        if (!container) return;
        const groups = container.querySelectorAll('.ad-media-item:not([style*="display: none"])');
        if (groups.length === 0) { addAdMedia(); }
    }
    function initializeAdCodeItems() {
        const container = document.getElementById('ad-code-container');
        if (!container) return;
        const items = container.querySelectorAll('.ad-code-item:not([style*="display: none"])');
        if (items.length === 0) { addAdCode(); }
    }

    function updateFormValidation(type) {
        const allInputs = document.querySelectorAll('.ad-type-fields input, .ad-type-fields textarea, .ad-type-fields select');
        allInputs.forEach(input => { input.removeAttribute('required'); });
        switch (type) {
            case 'image': break; case 'media': break; case 'code': break;
        }
    }

    // 添加/删除 图片组
    function addAdImageGroup() {
        const container = document.getElementById('ad-image-groups-container');
        if (!container) return;
        const template = container.querySelector('.ad-image-group');
        if (!template) return;
        const visible = container.querySelectorAll('.ad-image-group:not([style*="display: none"])');
        const newIndex = visible.length;
        const node = template.cloneNode(true); node.style.display = 'block';
        node.querySelectorAll('.media-modal-overlay').forEach(el => el.remove());
        const num = node.querySelector('.image-item-number'); if (num) num.textContent = `图片组 #${newIndex + 1}`;
        const groupFieldId = `ad_image_group_${newIndex}`;
        const hiddenInput = node.querySelector('input[type="hidden"][name^="ad_groups["]');
        if (hiddenInput) { hiddenInput.id = `${groupFieldId}_data`; hiddenInput.name = `ad_groups[${newIndex}][images]`; }
        const componentWrap = node.querySelector('.featured-images-component, .universal-images-component');
        if (componentWrap) {
            componentWrap.id = groupFieldId;
            componentWrap.setAttribute('data-field-name', `ad_groups[${newIndex}][images]`);
            componentWrap.setAttribute('data-max-images', '20');
            componentWrap.setAttribute('data-multiple', 'true');
            componentWrap.setAttribute('data-file-type', 'image');
        }
        const addBtn = node.querySelector('.add-featured-image, .add-universal-image');
        if (addBtn) {
            addBtn.setAttribute('data-field-id', groupFieldId);
            addBtn.setAttribute('data-multiple', 'true');
        }
        const titleInput = node.querySelector('input[name^="ad_groups[0][title]"]'); if (titleInput) titleInput.name = `ad_groups[${newIndex}][title]`;
        const linkInput = node.querySelector('input[name^="ad_groups[0][link]"]'); if (linkInput) linkInput.name = `ad_groups[${newIndex}][link]`;
        const targetSelect = node.querySelector('select[name^="ad_groups[0][target]"]'); if (targetSelect) targetSelect.name = `ad_groups[${newIndex}][target]`;
        const whiteCheckbox = node.querySelector('input[type="checkbox"][name^="ad_groups[0][white_text]"]'); if (whiteCheckbox) whiteCheckbox.name = `ad_groups[${newIndex}][white_text]`;
        const contentTextarea = node.querySelector('textarea[name^="ad_groups[0][content]"]'); if (contentTextarea) contentTextarea.name = `ad_groups[${newIndex}][content]`;
        container.appendChild(node);
        if (window.FeaturedImagesComponent) {
            new window.FeaturedImagesComponent({ fieldId: groupFieldId, fieldName: `ad_groups[${newIndex}][images]`, multiple: true, maxImages: 20, fileType: 'image', enableDebug: false, containerEl: node.querySelector(`#${groupFieldId}`) });
        }
    }
    function removeAdImageGroup(button) {
        const container = document.getElementById('ad-image-groups-container');
        const group = button.closest('.ad-image-group'); if (!group || !container) return;
        const visible = container.querySelectorAll('.ad-image-group:not([style*="display: none"])');
        if (visible.length <= 1) { TodoCMS.Utils.showMessage('至少需要保留一个图片组', 'warning'); return; }

        TodoCMS.Utils.confirm('确定要删除这个图片组吗？', function () {
                group.remove();
                const groups = container.querySelectorAll('.ad-image-group:not([style*="display: none"])');
                groups.forEach((node, idx) => {
                    const num = node.querySelector('.image-item-number'); if (num) num.textContent = `图片组 #${idx + 1}`;
                    const groupFieldId = `ad_image_group_${idx}`;
                    const hiddenInput = node.querySelector('input[type="hidden"][id$="_data"]'); if (hiddenInput) { hiddenInput.id = `${groupFieldId}_data`; hiddenInput.name = `ad_groups[${idx}][images]`; }
                    const componentWrap = node.querySelector('.featured-images-component, .universal-images-component'); if (componentWrap) { componentWrap.id = groupFieldId; componentWrap.setAttribute('data-field-name', `ad_groups[${idx}][images]`); }
                    const addBtn = node.querySelector('.add-featured-image, .add-universal-image'); if (addBtn) { addBtn.setAttribute('data-field-id', groupFieldId); }
                    const titleInput = node.querySelector('input[name$="[title]"]'); if (titleInput) titleInput.name = `ad_groups[${idx}][title]`;
                    const linkInput = node.querySelector('input[name$="[link]"]'); if (linkInput) linkInput.name = `ad_groups[${idx}][link]`;
                    const targetSelect = node.querySelector('select[name$="[target]"]'); if (targetSelect) targetSelect.name = `ad_groups[${idx}][target]`;
                    const whiteCheckbox = node.querySelector('input[type="checkbox"][name$="[white_text]"]'); if (whiteCheckbox) whiteCheckbox.name = `ad_groups[${idx}][white_text]`;
                    const contentTextarea = node.querySelector('textarea[name$="[content]"]'); if (contentTextarea) contentTextarea.name = `ad_groups[${idx}][content]`;
                });
        });
    }

    // 添加/删除 多媒体组
    function addAdMedia() {
        const container = document.getElementById('ad-media-container');
        if (!container) return; const template = container.querySelector('.ad-media-item'); if (!template) return;
        const visible = container.querySelectorAll('.ad-media-item:not([style*="display: none"])'); const newIndex = visible.length;
        const node = template.cloneNode(true); node.style.display = 'block';
        node.querySelectorAll('.media-modal-overlay').forEach(el => el.remove());
        const num = node.querySelector('.image-item-number'); if (num) num.textContent = `多媒体组 #${newIndex + 1}`;
        const groupFieldId = `ad_media_group_${newIndex}`;
        const hiddenInput = node.querySelector('input[type="hidden"][name^="ad_media_groups["]'); if (hiddenInput) { hiddenInput.id = `${groupFieldId}_data`; hiddenInput.name = `ad_media_groups[${newIndex}][medias]`; }
        const componentWrap = node.querySelector('.featured-images-component, .universal-images-component'); if (componentWrap) {
            componentWrap.id = groupFieldId; componentWrap.setAttribute('data-field-name', `ad_media_groups[${newIndex}][medias]`); componentWrap.setAttribute('data-max-images', '20'); componentWrap.setAttribute('data-multiple', 'true'); componentWrap.setAttribute('data-file-type', 'video');
        }
        const addBtn = node.querySelector('.add-featured-image, .add-universal-image'); if (addBtn) { addBtn.setAttribute('data-field-id', groupFieldId); addBtn.setAttribute('data-multiple', 'true'); }
        const titleInput = node.querySelector('input[name^="ad_media_groups[0][title]"]'); if (titleInput) titleInput.name = `ad_media_groups[${newIndex}][title]`;
        const linkInput = node.querySelector('input[name^="ad_media_groups[0][link]"]'); if (linkInput) linkInput.name = `ad_media_groups[${newIndex}][link]`;
        const targetSelect = node.querySelector('select[name^="ad_media_groups[0][target]"]'); if (targetSelect) targetSelect.name = `ad_media_groups[${newIndex}][target]`;
        const whiteCheckbox = node.querySelector('input[type="checkbox"][name^="ad_media_groups[0][white_text]"]'); if (whiteCheckbox) whiteCheckbox.name = `ad_media_groups[${newIndex}][white_text]`;
        const contentTextarea = node.querySelector('textarea[name^="ad_media_groups[0][content]"]'); if (contentTextarea) contentTextarea.name = `ad_media_groups[${newIndex}][content]`;
        container.appendChild(node);
        if (window.FeaturedImagesComponent) {
            new window.FeaturedImagesComponent({ fieldId: groupFieldId, fieldName: `ad_media_groups[${newIndex}][medias]`, multiple: true, maxImages: 20, fileType: 'video', mediaType: 'video', enableDebug: false, containerEl: node.querySelector(`#${groupFieldId}`) });
        }
    }
    function removeAdMedia(button) {
        const container = document.getElementById('ad-media-container');
        const group = button.closest('.ad-media-item'); if (!group || !container) return;
        const visible = container.querySelectorAll('.ad-media-item:not([style*="display: none"])'); if (visible.length <= 1) { TodoCMS.Utils.showMessage('至少需要保留一个多媒体组', 'warning'); return; }

        TodoCMS.Utils.confirm('确定要删除这个多媒体组吗？', function () {
                group.remove();
                const groups = container.querySelectorAll('.ad-media-item:not([style*="display: none"])');
                groups.forEach((node, idx) => {
                    const num = node.querySelector('.image-item-number'); if (num) num.textContent = `多媒体组 #${idx + 1}`;
                    const groupFieldId = `ad_media_group_${idx}`;
                    const hiddenInput = node.querySelector('input[type="hidden"][id$="_data"]'); if (hiddenInput) { hiddenInput.id = `${groupFieldId}_data`; hiddenInput.name = `ad_media_groups[${idx}][medias]`; }
                    const componentWrap = node.querySelector('.featured-images-component, .universal-images-component'); if (componentWrap) { componentWrap.id = groupFieldId; componentWrap.setAttribute('data-field-name', `ad_media_groups[${idx}][medias]`); }
                    const addBtn = node.querySelector('.add-featured-image, .add-universal-image'); if (addBtn) { addBtn.setAttribute('data-field-id', groupFieldId); }
                    const titleInput = node.querySelector('input[name$="[title]"]'); if (titleInput) titleInput.name = `ad_media_groups[${idx}][title]`;
                    const linkInput = node.querySelector('input[name$="[link]"]'); if (linkInput) linkInput.name = `ad_media_groups[${idx}][link]`;
                    const targetSelect = node.querySelector('select[name$="[target]"]'); if (targetSelect) targetSelect.name = `ad_media_groups[${idx}][target]`;
                    const whiteCheckbox = node.querySelector('input[type="checkbox"][name$="[white_text]"]'); if (whiteCheckbox) whiteCheckbox.name = `ad_media_groups[${idx}][white_text]`;
                    const contentTextarea = node.querySelector('textarea[name$="[content]"]'); if (contentTextarea) contentTextarea.name = `ad_media_groups[${idx}][content]`;
                });
        });
    }

    // 添加/删除 代码项
    function addAdCode() {
        const container = document.getElementById('ad-code-container'); if (!container) return;
        const template = container.querySelector('.ad-code-item'); if (!template) return;
        const visible = container.querySelectorAll('.ad-code-item:not([style*="display: none"])');
        const newIndex = visible.length;
        const node = template.cloneNode(true); node.style.display = 'block';
        const num = node.querySelector('.code-item-number'); if (num) num.textContent = `代码 #${newIndex + 1}`;
        const textarea = node.querySelector('textarea'); if (textarea) { textarea.disabled = false; textarea.name = `ad_html_contents[${newIndex}]`; }
        container.appendChild(node);
    }
    function removeAdCode(button) {
        const container = document.getElementById('ad-code-container');
        const item = button.closest('.ad-code-item'); if (!item || !container) return;
        const visible = container.querySelectorAll('.ad-code-item:not([style*="display: none"])'); if (visible.length <= 1) { TodoCMS.Utils.showMessage('至少需要保留一个代码项', 'warning'); return; }

        TodoCMS.Utils.confirm('确定要删除这个代码项吗？', function () {
                item.remove();
                const items = container.querySelectorAll('.ad-code-item:not([style*="display: none"])');
                items.forEach((node, idx) => {
                    const num = node.querySelector('.code-item-number'); if (num) num.textContent = `代码 #${idx + 1}`;
                    const textarea = node.querySelector('textarea'); if (textarea) textarea.name = `ad_html_contents[${idx}]`;
                });
        });
    }

    // 初始化
    (function () {
        const typeSelect = document.getElementById('type');
        if (typeSelect) {
            typeSelect.addEventListener('change', function () { toggleAdTypeFields(); });
            toggleAdTypeFields();
        }
        // 若无预填，则创建默认项
        initializeAdImageGroups();
        initializeAdMediaGroups();
        initializeAdCodeItems();

        // 表单提交验证
        const form = document.querySelector('.admin-ad-form');
        if (form) {
            form.addEventListener('submit', function (e) {
                if (!validateForm()) { e.preventDefault(); }
            });
        }
    })();

    function validateForm() {
        const type = document.getElementById('type') ? document.getElementById('type').value : '';
        if (type === 'image') {
            const container = document.getElementById('ad-image-groups-container');
            if (!container) return true;
            const groups = container.querySelectorAll('.ad-image-group:not([style*="display: none"])');
            if (groups.length === 0) { TodoCMS.Utils.showMessage('请至少添加一个图片组', 'warning'); return false; }
            return true;
        }
        if (type === 'media') {
            const container = document.getElementById('ad-media-container');
            if (!container) return true;
            const groups = container.querySelectorAll('.ad-media-item:not([style*="display: none"])');
            if (groups.length === 0) { TodoCMS.Utils.showMessage('请至少添加一个多媒体组', 'warning'); return false; }
            return true;
        }
        if (type === 'code') {
            const container = document.getElementById('ad-code-container'); if (!container) return true;
            const items = container.querySelectorAll('.ad-code-item:not([style*="display: none"]) textarea');
            for (let i = 0; i < items.length; i++) {
                if (items[i].value.trim() !== '') return true;
            }
            TodoCMS.Utils.showMessage('请至少填写一项代码内容', 'warning');
            return false;
        }
        return true;
    }
</script>
