<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

/**
 * 添加新友情链接页面
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
    todo_die('您没有添加友情链接的权限。');
}

$message = '';
$error = '';

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_link') {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('add_link', '_todononce')) {
        todo_admin_notice('安全验证失败，请重试。', 'error');
    } else {
        // 获取表单数据并确保类型安全
        $name = isset($_POST['name']) ? (string) trim($_POST['name']) : '';
        $url = isset($_POST['url']) ? (string) trim($_POST['url']) : '';
        $description = isset($_POST['description']) ? (string) trim($_POST['description']) : '';
        // 兼容特色图像组件：logo字段可能为JSON（组件隐藏字段）或纯URL字符串
        $logo_input = isset($_POST['logo']) ? (string) trim($_POST['logo']) : '';
        $logo = '';
        // 优先解析 JSON
        if ($logo_input !== '') {
            $decoded = json_decode($logo_input, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (is_array($decoded)) {
                    // 组件通常返回数组列表，取第一项或对象
                    if (!empty($decoded)) {
                        $first = reset($decoded);
                        if (is_array($first)) {
                            $candidate = '';
                            if (isset($first['url'])) {
                                $candidate = (string) $first['url'];
                            } elseif (isset($first['path'])) {
                                $candidate = (string) $first['path'];
                            }
                            $logo = normalize_media_url($candidate);
                        }
                    } else {
                        $candidate = isset($decoded['url']) ? (string) $decoded['url'] : (isset($decoded['path']) ? (string) $decoded['path'] : '');
                        $logo = normalize_media_url($candidate);
                    }
                }
            } else {
                // 非JSON，直接归一化
                $logo = normalize_media_url($logo_input);
            }
        }
        // 如未获取到，兼容组件隐藏字段 logo_data（JSON）
        if ($logo === '' && isset($_POST['logo_data'])) {
            $ld = (string) $_POST['logo_data'];
            $decoded2 = json_decode($ld, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (is_array($decoded2)) {
                    if (!empty($decoded2)) {
                        $first2 = reset($decoded2);
                        if (is_array($first2)) {
                            $candidate2 = isset($first2['url']) ? (string) $first2['url'] : (isset($first2['path']) ? (string) $first2['path'] : '');
                            $logo = normalize_media_url($candidate2);
                        }
                    } else {
                        $candidate2 = isset($decoded2['url']) ? (string) $decoded2['url'] : (isset($decoded2['path']) ? (string) $decoded2['path'] : '');
                        $logo = normalize_media_url($candidate2);
                    }
                }
            }
        }
        $category = isset($_POST['category']) ? (string) trim($_POST['category']) : 'general';
        $target = isset($_POST['target']) ? (string) $_POST['target'] : '_blank';
        $sort_order = isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 0;
        $status = isset($_POST['status']) ? (int) $_POST['status'] : 1;

        // 验证必填字段
        if (empty($name)) {
            todo_admin_notice('链接名称不能为空。', 'error');
        } elseif (empty($url)) {
            todo_admin_notice('链接地址不能为空。', 'error');
        } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
            todo_admin_notice('请输入有效的链接地址。', 'error');
        } else {
            // 插入数据库
            global $tododb;
            $data = array(
                'name' => $name,
                'url' => $url,
                'description' => $description,
                'logo' => $logo,
                'category' => $category,
                'target' => $target,
                'sort_order' => $sort_order,
                'status' => $status
            );

            $result = $tododb->insert(TABLE_PREFIX . 'links', $data);

            if ($result !== false) {
                todo_admin_notice('友情链接添加成功。', 'success');
                todo_redirect(todo_admin_url('index.php?page=links'));
            } else {
                todo_admin_notice('添加友情链接失败，请重试。', 'error');
            }
        }
    }
}

// 初始化所有变量，确保它们总是被定义
$name = isset($name) ? $name : '';
$url = isset($url) ? $url : '';
$description = isset($description) ? $description : '';
$logo = isset($logo) ? $logo : '';
$category = isset($category) ? $category : 'general';
$target = isset($target) ? $target : '_blank';
$sort_order = isset($sort_order) ? $sort_order : 0;
$status = isset($status) ? $status : 1;

// 确保所有变量都是正确的类型，防止HTML元素渲染
$name = (string) $name;
$url = (string) $url;
$description = (string) $description;
$logo = (string) $logo;
$category = (string) $category;
$target = (string) $target;
$sort_order = (int) $sort_order;
$status = (int) $status;

// 获取现有分类
global $tododb;
$categories_query = "SELECT DISTINCT category FROM " . TABLE_PREFIX . "links WHERE category != '' ORDER BY category";
$existing_categories = $tododb->get_results($categories_query);

// 确保转义函数存在并正常工作
if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        if (is_array($text) || is_object($text)) {
            return '';
        }
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

// 安全的属性转义函数
function safe_attr($value)
{
    if (is_array($value) || is_object($value)) {
        return '';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
// 归一化媒体URL（转换为相对路径入库，保持外部链接不动）
function normalize_media_url($u)
{
    $u = trim((string) $u);
    if ($u === '')
        return '';
    if (function_exists('todo_media_url_to_relative')) {
        return todo_media_url_to_relative($u);
    }
    return $u;
}

// 加载特色图像组件（如未加载）
if (!function_exists('todo_render_featured_images_component')) {
    require_once ABSPATH . 'admin/components/featured-images-component.php';
}

// 预构建LOGO初始值供组件显示
$logo_images = array();
if (!empty($logo) && filter_var($logo, FILTER_VALIDATE_URL)) {
    $logo_images[] = array(
        'id' => '',
        'url' => $logo,
        'title' => 'LOGO',
        'alt' => 'LOGO'
    );
}
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">添加新友情链接</h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=links'); ?>" class="button button-secondary">返回列表</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html($message); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html($error); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <div class="admin-card">
        <div class="admin-card-header">
            <h3 class="admin-card-title">友情链接信息</h3>
        </div>
        <div class="admin-card-content">
            <form method="post" action="" class="link-form todo-request">
                <?php echo Todo_Security_Manager::csrf_field('add_link'); ?>
                <input type="hidden" name="action" value="add_link">

                <div class="form-group">
                    <label for="name">链接名称 <span class="required">*</span></label>
                    <input type="text" id="name" name="name" value="<?php echo safe_attr($name); ?>"
                        class="regular-text" required>
                    <p class="description">友情链接的显示名称</p>
                </div>

                <div class="form-group">
                    <label for="url">链接地址 <span class="required">*</span></label>
                    <input type="url" id="url" name="url" value="<?php echo safe_attr($url); ?>" class="regular-text"
                        required placeholder="https://example.com">
                    <p class="description">完整的链接地址，包含 http:// 或 https://</p>
                </div>

                <div class="form-group">
                    <label for="description">链接描述</label>
                    <textarea id="description" name="description" rows="3"
                        class="large-text"><?php echo esc_textarea($description); ?></textarea>
                    <p class="description">可选的链接描述信息</p>
                </div>

                <div class="form-group">
                    <label for="link_logo_data">LOGO</label>
                    <div class="media-upload-group" style="max-width: 220px;">
                        <?php
                        // 使用特色图像组件（单图模式，file_type=image）
                        // 注意：组件隐藏字段使用 logo_data，实际提交到数据库的字段为 logo（URL）
                        echo todo_render_featured_images_component(array(
                            'field_id' => 'link_logo',
                            'field_name' => 'logo_data',
                            'current_images' => $logo_images,
                            'multiple' => false,
                            'max_images' => 1,
                            'file_type' => 'image',
                            'variant' => 'featured',
                            'enable_debug' => false
                        ));
                        ?>
                        <input type="hidden" name="logo" id="logo_url" value="<?php echo safe_attr($logo); ?>">
                        <script type="text/javascript">
                            jQuery(function ($) {
                                var $container = $('#link_logo');
                                function syncLogoFromComponent(images) {
                                    var url = '';
                                    if (Array.isArray(images) && images.length > 0 && images[0] && images[0].url) {
                                        url = images[0].url;
                                    }
                                    $('#logo_url').val(url);
                                }
                                $container.on('todo:images-updated', function (e, payload) {
                                    if (payload && payload.images) {
                                        syncLogoFromComponent(payload.images);
                                    }
                                });
                                // 首次加载时从隐藏字段或组件数据初始化
                                var initialVal = $('#link_logo_data').val();
                                if (initialVal) {
                                    try {
                                        var parsed = JSON.parse(initialVal);
                                        syncLogoFromComponent(parsed);
                                    } catch (e) { }
                                }
                            });
                        </script>
                        <p class="description">选择或上传友情链接LOGO</p>
                    </div>
                </div>

                <div class="form-group">
                    <label for="category">链接分类</label>
                    <input type="text" id="category" name="category" value="<?php echo safe_attr($category); ?>"
                        class="regular-text" list="category-list">
                    <datalist id="category-list">
                        <option value="general">常规</option>
                        <option value="partner">合作伙伴</option>
                        <option value="friend">友站</option>
                        <option value="sponsor">赞助商</option>
                        <?php foreach ($existing_categories as $cat): ?>
                            <option value="<?php echo safe_attr($cat->category); ?>">
                                <?php echo esc_html($cat->category); ?>
                            </option>
                        <?php endforeach; ?>
                    </datalist>
                    <p class="description">用于分组管理友情链接</p>
                </div>

                <div class="form-group">
                    <label for="target">打开方式</label>
                    <select id="target" name="target">
                        <option value="_blank" <?php selected($target, '_blank'); ?>>新窗口打开</option>
                        <option value="_self" <?php selected($target, '_self'); ?>>当前窗口打开</option>
                    </select>
                    <p class="description">点击链接时的打开方式</p>
                </div>

                <div class="form-group">
                    <label for="sort_order">排序</label>
                    <input type="number" id="sort_order" name="sort_order" value="<?php echo safe_attr($sort_order); ?>"
                        class="small-text" min="0" step="1">
                    <p class="description">数字越小排序越靠前，默认为0</p>
                </div>

                <div class="form-group">
                    <label for="status">状态</label>
                    <select id="status" name="status">
                        <option value="1" <?php selected($status, 1); ?>>启用</option>
                        <option value="0" <?php selected($status, 0); ?>>禁用</option>
                    </select>
                    <p class="description">是否在前台显示此链接</p>
                </div>

                <div class="form-submit">
                    <button type="submit" class="button button-primary">添加友情链接</button>
                    <a href="<?php echo todo_admin_url('index.php?page=links'); ?>" class="button button-secondary">返回列表</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script type="text/javascript">
    // 链接新增页无需额外JS：特色图像组件会自动完成初始化和事件绑定
</script>
