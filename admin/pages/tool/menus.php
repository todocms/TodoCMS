<?php
// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
/**
 * 菜单管理页面 - 优化版
 *
 * 提供创建和管理网站导航菜单的功能
 * 优化了界面布局、交互体验和功能完整性
 *
 * @package TodoCMS
 * @version 2.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 确保session已启动
if (!session_id()) {
    session_start();
}

// 检查用户权限
if (!current_user_can('manage_menus') && !current_user_can('manage_options')) {
    todo_die('您没有足够的权限访问此页面。');
}

// 处理菜单操作
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$menu_id = isset($_GET['menu_id']) ? intval($_GET['menu_id']) : 0;

// PRG: 处理重定向后的提示信息，避免重复提交
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['updated'])) {
        $success_message = '菜单更新成功';
    } elseif (isset($_GET['created'])) {
        $success_message = '菜单创建成功';
    } elseif (isset($_GET['duplicated'])) {
        $success_message = '菜单复制成功';
    } elseif (isset($_GET['deleted'])) {
        $success_message = '菜单删除成功';
    }
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 验证nonce
    if (!Todo_Security_Manager::verify_request_csrf('menu_action', '_todononce')) {
        todo_die('安全验证失败，请重新提交。');
    }

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_menu':
                // 添加新菜单
                $menu_name = isset($_POST['menu_name']) ? sanitize_text_field($_POST['menu_name']) : '';
                $menu_location = isset($_POST['menu_location']) ? sanitize_text_field($_POST['menu_location']) : '';
                $menu_description = isset($_POST['menu_description']) ? sanitize_text_field($_POST['menu_description']) : '';

                if (empty($menu_name)) {
                    $error_message = '菜单名称不能为空';
                } else {
                    // 保存菜单
                    $menu_id = save_menu([
                        'name' => $menu_name,
                        'location' => $menu_location,
                        'description' => $menu_description
                    ]);

                    if ($menu_id) {
                        $success_message = '菜单创建成功';
                        // PRG模式：重定向到编辑页面，避免重复提交
                        $redirect_url = add_query_arg(array(
                            'page' => 'menus',
                            'action' => 'edit',
                            'menu_id' => $menu_id,
                            'created' => '1'
                        ), todo_admin_url('index.php'));
                        
                        todo_redirect($redirect_url);
                        exit;
                    } else {
                        $error_message = '菜单创建失败';
                    }
                }
                break;

            case 'update_menu':
                // 更新菜单
                $menu_id = isset($_POST['menu_id']) ? intval($_POST['menu_id']) : 0;
                $menu_name = isset($_POST['menu_name']) ? sanitize_text_field($_POST['menu_name']) : '';
                $menu_location = isset($_POST['menu_location']) ? sanitize_text_field($_POST['menu_location']) : '';
                $menu_description = isset($_POST['menu_description']) ? sanitize_text_field($_POST['menu_description']) : '';
                $menu_items = isset($_POST['menu_items']) ? $_POST['menu_items'] : [];

                // 处理菜单项数据
                $processed_items = [];
                if (!empty($menu_items) && is_array($menu_items)) {
                    foreach ($menu_items as $key => $item) {
                        if (!empty($item['title']) && !empty($item['url'])) {
                            $processed_items[$key] = [
                                'title' => sanitize_text_field($item['title']),
                                'url' => esc_url_raw($item['url']),
                                'type' => sanitize_text_field($item['type'] ?? 'custom'),
                                'object_id' => intval($item['object_id'] ?? 0),
                                // 保留原始的父级键值以便在 update_menu 中进行键映射（可能为字符串或负数等非纯整数）
                                'parent' => isset($item['parent']) ? sanitize_text_field($item['parent']) : 0,
                                'order' => intval($item['order'] ?? 0),
                                'depth' => intval($item['depth'] ?? 0),
                                'attr_title' => sanitize_text_field($item['attr_title'] ?? ''),
                                'icon' => $item['icon'] ?? '',
                                'css_class' => sanitize_text_field($item['css_class'] ?? ''),
                                'new_window' => !empty($item['new_window']) ? 1 : 0
                            ];
                        }
                    }
                }

                if (empty($menu_name)) {
                    $error_message = '菜单名称不能为空';
                } elseif ($menu_id <= 0) {
                    $error_message = '无效的菜单ID';
                } else {
                    // 更新菜单
                    $result = update_menu($menu_id, [
                        'name' => $menu_name,
                        'location' => $menu_location,
                        'description' => $menu_description,
                        'items' => $processed_items
                    ]);

                    if ($result) {
                        // PRG: Redirect after successful update to prevent resubmission
                        todo_redirect(todo_admin_url('index.php?page=menus&action=edit&menu_id=' . $menu_id . '&updated=1'));
                    } else {
                        $error_message = '菜单更新失败，请确认菜单是否存在';
                    }
                }
                break;

            case 'delete_menu':
                // 删除菜单
                $menu_id = isset($_POST['menu_id']) ? intval($_POST['menu_id']) : 0;

                if ($menu_id > 0) {
                    $result = delete_menu($menu_id);

                    if ($result) {
                        // PRG模式：重定向到列表页面，避免重复提交
                        $redirect_url = add_query_arg(array(
                            'page' => 'menus',
                            'deleted' => '1'
                        ), todo_admin_url('index.php'));

                        todo_redirect($redirect_url);
                        exit;
                    } else {
                        $error_message = '菜单删除失败';
                    }
                }
                break;

            case 'duplicate_menu':
                // 复制菜单
                $menu_id = isset($_POST['menu_id']) ? intval($_POST['menu_id']) : 0;

                if ($menu_id > 0) {
                    $result = duplicate_menu($menu_id);

                    if ($result) {
                        // PRG: Redirect after duplicate
                        todo_redirect(todo_admin_url('index.php?page=menus&duplicated=1'));
                    } else {
                        $error_message = '菜单复制失败';
                    }
                }
                break;
        }
    }
}

// 显示页面内容
?>
<link rel="stylesheet" href="<?php echo todo_admin_url('assets/css/menus.css'); ?>">

<?php
// 引入通用分类选择器组件
require_once ABSPATH . 'admin/components/hierarchical-categories-selector.php';
// 输出分类选择器样式和脚本
enqueue_hierarchical_categories_selector_styles();
?>
<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title verse">“欲穷千里目，更上一层楼。”<span>——王之涣《登鹳雀楼》</span></h2>
        <div class="admin-page-actions">
            <?php if ($action === 'list'): ?>
                <a href="<?php echo todo_admin_url('index.php?page=menus&action=add'); ?>" class="button button-primary">添加菜单</a>
            <?php elseif ($action === 'edit' || $action === 'add'): ?>
                <a href="<?php echo todo_admin_url('index.php?page=menus'); ?>" class="button button-secondary">返回列表</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($error_message)): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html($error_message); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <?php if (isset($success_message)): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html($success_message); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <?php if ($action === 'list'): ?>
        <!-- 菜单列表 -->
        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">所有菜单</h3>
                <div class="admin-card-actions">
                    <span class="description m-0">
                        <?php
                        $menus = get_menus();
                        echo count($menus) . ' 个菜单';
                        ?>
                    </span>
                </div>
            </div>

            <?php if (!empty($menus)): ?>
                <div class="admin-card-content">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th style="min-width: 160px;">菜单名称</th>
                                <th>位置</th>
                                <th style="min-width: 200px;">描述</th>
                                <th>结构项</th>
                                <th style="width: 160px; min-width: 160px;">更新于</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($menus as $menu): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($menu['name']); ?></strong>
                                        <div class="row-actions">
                                            <a href="index.php?page=menus&action=edit&menu_id=<?php echo $menu['id']; ?>">编辑</a> | 
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="action" value="duplicate_menu">
                                                <input type="hidden" name="menu_id" value="<?php echo $menu['id']; ?>">
                                                <?php echo Todo_Security_Manager::csrf_field('menu_action', '_todononce'); ?>
                                                <button type="submit" class="btn-normal" title="复制菜单">复制</button>
                                            </form> |
                                            <form method="post" style="display:inline;" class="delete-menu-form"
                                                data-menu-name="<?php echo esc_attr($menu['name']); ?>">
                                                <input type="hidden" name="action" value="delete_menu">
                                                <input type="hidden" name="menu_id" value="<?php echo $menu['id']; ?>">
                                                <?php echo Todo_Security_Manager::csrf_field('menu_action', '_todononce'); ?>
                                                <button type="submit" class="btn-normal submitdelete" title="删除菜单">删除</button>
                                            </form>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($menu['location'])): ?>
                                            <?php echo esc_html($menu['location']); ?>
                                        <?php else: ?>
                                            未分配
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="tb-description"><?php echo esc_html($menu['description']); ?></div>
                                    </td>
                                    <td>
                                        <?php echo count($menu['items']); ?> 个
                                    </td>
                                    <td>
                                        <div class="tb-description">
                                            <?php echo isset($menu['updated_at']) ? date('Y-m-d H:i', strtotime($menu['updated_at'])) : '未知'; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="admin-card-content">
                    <div class="no-items">
                        <h4>还没有菜单</h4>
                        <p class="description">创建您的第一个导航菜单来组织网站内容。</p>
                        <br>
                        <a href="<?php echo todo_admin_url('index.php?page=menus&action=add'); ?>" class="button button-primary">
                            <i class="toicon">✚</i> 创建菜单
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- 菜单位置说明 -->
        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">菜单位置</h3>
            </div>
            <div class="admin-card-content">
                <h4>主导航 (primary)</h4>
                <p class="description m-0">网站顶部的主要导航菜单</p>
        
                <h4 class="m-t-10">页脚导航 (footer)</h4>
                <p class="description m-0">网站底部的辅助导航菜单</p>
            
                <h4 class="m-t-10">侧边栏导航 (sidebar)</h4>
                <p class="description m-0">侧边栏中的导航菜单</p>
            </div>
        </div>

    <?php elseif ($action === 'add'): ?>
        <!-- 添加新菜单 -->
        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">添加新菜单</h3>
            </div>
            <div class="admin-card-content">
                <form method="post" action="<?php echo todo_admin_url('index.php?page=menus&action=add'); ?>" class="menu-form">
                    <input type="hidden" name="action" value="add_menu">
                    <?php echo Todo_Security_Manager::csrf_field('menu_action', '_todononce'); ?>

                    <div class="form-group">
                        <label for="menu_name">菜单名称<span class="required">*</span></label>
                        <input type="text" id="menu_name" name="menu_name" placeholder="输入菜单名称" required>
                        <p class="description">为您的菜单起一个容易识别的名称</p>
                    </div>

                    <div class="form-group">
                        <label for="menu_location">菜单位置</label>
                        <select id="menu_location" name="menu_location">
                            <option value="">-- 选择位置 --</option>
                            <option value="primary">主导航</option>
                            <option value="footer">页脚导航</option>
                            <option value="sidebar">侧边栏导航</option>
                        </select>
                        <p class="description">选择菜单在网站中的显示位置</p>
                    </div>

                    <div class="form-group">
                        <label for="menu_description">菜单描述</label>
                        <textarea id="menu_description" name="menu_description" rows="3"
                            placeholder="输入菜单描述（可选）"></textarea>
                        <p class="description">简要描述这个菜单的用途</p>
                    </div>

                    <div class="form-submit">
                        <a href="<?php echo todo_admin_url('index.php?page=menus'); ?>" class="button button-secondary">取消</a>
                        <button type="submit" class="button button-primary">创建菜单</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 菜单位置说明 -->
        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">菜单位置</h3>
            </div>
            <div class="admin-card-content">
                <h4>主导航 (primary)</h4>
                <p class="description">网站顶部的主要导航菜单</p>
        
                <h4>页脚导航 (footer)</h4>
                <p class="description">网站底部的辅助导航菜单</p>
            
                <h4>侧边栏导航 (sidebar)</h4>
                <p class="description">侧边栏中的导航菜单</p>
            </div>
        </div>

    <?php elseif ($action === 'edit' && $menu_id > 0): ?>
        <!-- 编辑菜单 -->
        <?php
        // 获取菜单信息
        $menu = get_menu($menu_id);

        if (!$menu):
            ?>
            <div class="notice notice-error">
                <p>菜单不存在</p>
            </div>
            <a href="<?php echo todo_admin_url('index.php?page=menus'); ?>" class="button button-primary">
                返回菜单列表
            </a>
        <?php else: ?>
            <form method="post" action="<?php echo todo_admin_url('index.php?page=menus&action=edit&menu_id=' . $menu_id); ?>"
                        class="menu-form" id="menu-form">
                <div class="admin-card full-card">
                    <div class="admin-card-content">
                        <input type="hidden" name="action" value="update_menu">
                        <input type="hidden" name="menu_id" value="<?php echo $menu_id; ?>">
                        <?php echo Todo_Security_Manager::csrf_field('menu_action', '_todononce'); ?>
                        <div class="flex gap-5 flex-wrap">
                            <div class="menu-edit" style="padding-right: 20px; border-right: 1px solid #eceef1;">
                                <div class="form-group">
                                    <h3 class="admin-card-header" style="padding: 12px 0 15px;">编辑</h3>
                                    <label for="menu_name">菜单名称<span class="required">*</span></label>
                                    <input type="text" id="menu_name" name="menu_name"
                                        value="<?php echo esc_attr($menu['name']); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label for="menu_location">菜单位置</label>
                                    <select id="menu_location" name="menu_location">
                                        <option value="">-- 选择位置 --</option>
                                        <option value="primary" <?php selected($menu['location'], 'primary'); ?>>主导航</option>
                                        <option value="footer" <?php selected($menu['location'], 'footer'); ?>>页脚导航</option>
                                        <option value="sidebar" <?php selected($menu['location'], 'sidebar'); ?>>侧边栏导航</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="menu_description">菜单描述</label>
                                    <textarea id="menu_description" name="menu_description" rows="3"
                                        placeholder="输入菜单描述（可选）"><?php echo esc_textarea($menu['description'] ?? ''); ?></textarea>
                                </div>
                            </div>

                            <!-- 菜单构建器 -->
                            <div class="menu-builder">
                                <!-- 选项卡导航 -->
                                <div class="menu-tabs" style="width: 40%;">
                                    <div class="tab-nav">
                                        <button type="button" class="tab-button active" data-tab="categories">
                                            <i class="toicon">⊟</i> 栏目
                                        </button>
                                        <button type="button" class="tab-button" data-tab="tags">
                                            <i class="toicon">𖤘</i> 标签
                                        </button>
                                        <button type="button" class="tab-button" data-tab="custom">
                                            <i class="toicon">𖥩</i> 自定义
                                        </button>
                                    </div>

                                    <!-- 栏目选项卡 -->
                                    <div class="tab-content active" id="tab-categories">
                                        <div class="items-list" id="categories-list">
                                            <?php
                                            // 使用通用分类选择器组件
                                            $categories = get_menu_categories();
                                            $selected_categories = [];

                                            // 配置分类选择器参数
                                            $selector_args = array(
                                                'name' => 'add_category[]',
                                                'id' => 'add_category',
                                                'selected' => $selected_categories,
                                                'multiple' => true,
                                                'show_checkbox' => true,
                                                'class' => 'hierarchical-categories-selector menu-categories-selector',
                                                'description' => '',
                                                'hide_empty' => false,
                                                'taxonomy' => 'category'
                                            );

                                            // 渲染分类选择器
                                            if (!empty($categories)):
                                                render_hierarchical_categories_selector($selector_args);
                                            else:
                                                ?>
                                                <div class="no-items">
                                                    <p>暂无栏目。</p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="tab-actions">
                                            <button type="button" class="button button-link add-to-menu"
                                                data-type="categories">
                                                添加到菜单 <b>❯</b>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- 标签选项卡 -->
                                    <div class="tab-content" id="tab-tags" style="padding-top: 20px;">
                                        <div class="tab-search">
                                            <input type="text" class="search-input" placeholder="搜索标签..." id="search-tags">
                                        </div>
                                        <div class="items-list" id="tags-list">
                                            <?php
                                            $tags = get_menu_tags();
                                            if (!empty($tags)):
                                                foreach ($tags as $tag):
                                                    $tag_url = '/tag/' . intval($tag['id']) . '/';
                                                    ?>
                                                    <label class="item-checkbox">
                                                        <input type="checkbox" name="add_tag[]" value="<?php echo $tag['id']; ?>"
                                                            data-title="<?php echo esc_attr($tag['name']); ?>"
                                                            data-url="<?php echo esc_attr($tag_url); ?>">
                                                        <span class="item-title"><?php echo esc_html($tag['name']); ?></span>
                                                        <span class="item-url"><?php echo esc_html($tag_url); ?></span>
                                                    </label>
                                                <?php
                                                endforeach;
                                            else:
                                                ?>
                                                <div class="no-items">
                                                    <p>暂无标签。</p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="tab-actions">
                                            <button type="button" class="button button-link add-to-menu"
                                                data-type="tags">
                                                添加到菜单 <b>❯</b>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- 自定义链接选项卡 -->
                                    <div class="tab-content" id="tab-custom" style="padding-top: 20px;">
                                        <div class="custom-link-form">
                                            <div class="form-group">
                                                <label for="custom_url" class="form-label">URL</label>
                                                <input type="text" id="custom_url" name="custom_url" class="form-input"
                                                    placeholder="https://example.com">
                                            </div>

                                            <div class="form-group">
                                                <label for="custom_title" class="form-label">链接文本</label>
                                                <input type="text" id="custom_title" name="custom_title" class="form-input"
                                                    placeholder="链接标题">
                                            </div>

                                            <div class="form-group">
                                                <label for="custom_description" class="form-label">描述</label>
                                                <input type="text" id="custom_description" name="custom_description"
                                                    class="form-input" placeholder="可选：链接描述">
                                            </div>
                                        </div>
                                        <div class="tab-actions">
                                            <button type="button" class="button button-link add-to-menu"
                                                data-type="custom">
                                                添加到菜单 <b>❯</b>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- 菜单结构 -->
                                <div class="admin-card" style="width: 60%; box-shadow: none; margin-bottom: 0;">
                                    <div class="admin-card-header m-0" style="border: none; padding-right: 0;">
                                        <h3 class="admin-card-title">菜单结构</h3>
                                        <div class="structure-actions">
                                            <button type="button" class="btn button-secondary" id="expand-all">
                                                展开
                                            </button>
                                            <button type="button" class="btn button-secondary" id="collapse-all">
                                                收起
                                            </button>
                                        </div>
                                    </div>

                                    <div class="menu-items-container" id="menu-items-container">
                                        <?php
                                        if (!empty($menu['items'])):
                                            foreach ($menu['items'] as $item):
                                                $item_id = $item['id'] ?? 0; // 使用实际的菜单项ID
                                                ?>
                                                <div class="menu-item" data-id="<?php echo $item_id; ?>"
                                                    data-depth="<?php echo $item['depth'] ?? 0; ?>">
                                                    <div class="menu-item-bar">
                                                        <div class="menu-item-handle">
                                                            <i class="toicon">≡</i>
                                                        </div>
                                                        <div class="menu-item-title">
                                                            <span class="item-title"><?php echo esc_html($item['title']); ?></span>
                                                            <span class="item-type"><?php echo esc_html($item['type']); ?></span>
                                                        </div>
                                                        <div class="menu-item-controls">
                                                            <button type="button" class="item-toggle" title="展开/折叠">
                                                                <i class="toicon">▼</i>
                                                            </button>
                                                            <button type="button" class="item-delete" title="删除">
                                                                <i class="toicon">✕</i>
                                                            </button>
                                                        </div>
                                                    </div>

                                                    <div class="menu-item-settings">
                                                        <div class="menu-item-form">
                                                            <div class="form-row">
                                                                <div class="form-group">
                                                                    <label class="form-label">导航标签</label>
                                                                    <input type="text" name="menu_items[<?php echo $item_id; ?>][title]"
                                                                        value="<?php echo esc_attr($item['title']); ?>"
                                                                        class="form-input">
                                                                </div>

                                                                <div class="form-group">
                                                                    <label class="form-label">URL</label>
                                                                    <input type="text" name="menu_items[<?php echo $item_id; ?>][url]"
                                                                        value="<?php echo esc_attr($item['url']); ?>"
                                                                        class="form-input">
                                                                </div>
                                                            </div>

                                                            <div class="form-row">
                                                                <div class="form-group">
                                                                    <label class="form-label">标题属性</label>
                                                                    <input type="text"
                                                                        name="menu_items[<?php echo $item_id; ?>][attr_title]"
                                                                        value="<?php echo esc_attr($item['attr_title'] ?? ''); ?>"
                                                                        class="form-input" placeholder="鼠标悬停时显示的文字">
                                                                </div>

                                                                <div class="form-group">
                                                                    <label class="form-label">CSS类</label>
                                                                    <input type="text"
                                                                        name="menu_items[<?php echo $item_id; ?>][css_class]"
                                                                        value="<?php echo esc_attr($item['css_class'] ?? ''); ?>"
                                                                        class="form-input" placeholder="可选的CSS类名">
                                                                </div>
                                                            </div>

                                                            <div class="form-row">
                                                                <div class="form-group" style="flex: 0 0 100%;">
                                                                    <label class="form-label">图标 (HTML/SVG)</label>
                                                                    <textarea name="menu_items[<?php echo $item_id; ?>][icon]" 
                                                                        class="form-input" rows="3" placeholder="例如: <img src='...'> 或 <svg>...</svg>"><?php echo esc_textarea($item['icon'] ?? ''); ?></textarea>
                                                                    <p class="description">支持 img、svg 等 HTML 标签</p>
                                                                </div>
                                                            </div>

                                                            <div class="form-row" style="margin-bottom: 0;">
                                                                <div class="checkbox-group">
                                                                    <label class="checkbox-label">
                                                                        <input type="checkbox"
                                                                            name="menu_items[<?php echo $item_id; ?>][new_window]"
                                                                            value="1" <?php checked(isset($item['new_window']) && $item['new_window']); ?>>
                                                                        <span class="checkmark"></span>
                                                                        在新窗口/标签页中打开链接
                                                                    </label>
                                                                </div>
                                                            </div>

                                                            <input type="hidden" name="menu_items[<?php echo $item_id; ?>][type]"
                                                                value="<?php echo esc_attr($item['type']); ?>">
                                                            <input type="hidden" name="menu_items[<?php echo $item_id; ?>][object_id]"
                                                                value="<?php echo esc_attr($item['object_id']); ?>">
                                                            <input type="hidden" name="menu_items[<?php echo $item_id; ?>][parent]"
                                                                value="<?php echo esc_attr($item['parent_id'] ?? 0); ?>"
                                                                class="menu-item-parent">
                                                            <input type="hidden" name="menu_items[<?php echo $item_id; ?>][order]"
                                                                value="<?php echo esc_attr($item['sort_order'] ?? $item['order'] ?? 0); ?>"
                                                                class="menu-item-order">
                                                            <input type="hidden" name="menu_items[<?php echo $item_id; ?>][depth]"
                                                                value="<?php echo esc_attr($item['depth'] ?? 0); ?>"
                                                                class="menu-item-depth">
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php
                                            endforeach;
                                        else:
                                            ?>
                                            <div class="no-menu-items">
                                                <p>暂无菜单项</p>
                                                <p class="help-text">从左侧选择页面、栏目、标签或添加自定义链接</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="menu-structure-help" style="margin-top: 20px;">
                                        <em>拖动菜单项可以调整顺序和层级关系</em>
                                    </div>
                                </div>
                            </div>

                        </div>    
                    </div>
                </div>
                <div class="admin-form-submit">
                    <button type="submit" class="button button-primary">保存菜单</button>
                    <a href="<?php echo todo_admin_url('index.php?page=menus'); ?>" class="button button-secondary">
                        返回菜单列表
                    </a>
                </div>
            </form>
        <?php endif; ?>

    <?php else: ?>
        <!-- 无效操作 -->
        <div class="notice notice-error">
            <p>无效的操作</p>
        </div>
        <a href="<?php echo todo_admin_url('index.php?page=menus'); ?>" class="button button-primary">
            返回菜单列表
        </a>
    <?php endif; ?>
</div>

<!-- 菜单管理脚本 -->
<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {
        // 选项卡切换
        const tabButtons = document.querySelectorAll('.tab-button');
        const tabContents = document.querySelectorAll('.tab-content');

        tabButtons.forEach(button => {
            button.addEventListener('click', function () {
                const tabId = this.dataset.tab;

                // 移除所有活动状态
                tabButtons.forEach(btn => btn.classList.remove('active'));
                tabContents.forEach(content => content.classList.remove('active'));

                // 设置当前活动状态
                this.classList.add('active');
                document.getElementById('tab-' + tabId).classList.add('active');
            });
        });
        // 初始化菜单项缩进（根据父子关系计算层级）
        function initializeMenuIndentation() {
            const container = document.getElementById('menu-items-container');
            if (!container) return;
            const items = Array.from(container.querySelectorAll('.menu-item'));
            const idToEl = {};
            const idToDepth = {};

            items.forEach(el => {
                const id = parseInt(el.dataset.id) || 0;
                if (id) idToEl[id] = el;
            });

            function getDepth(el) {
                const id = parseInt(el.dataset.id) || 0;
                if (id && idToDepth[id] !== undefined) return idToDepth[id];

                const parentInput = el.querySelector('.menu-item-parent');
                const parentId = parseInt(parentInput ? parentInput.value : '') || 0;
                if (parentId) {
                    const parentEl = idToEl[parentId] || null;
                    if (parentEl) {
                        const parentDepth = getDepth(parentEl);
                        const depth = Math.min(parentDepth + 1, 3);
                        idToDepth[id] = depth;
                        return depth;
                    }
                }

                const depthInput = el.querySelector('.menu-item-depth');
                let depth = parseInt(depthInput ? depthInput.value : '');
                if (!isNaN(depth) && depth >= 0) {
                    idToDepth[id] = depth;
                    return depth;
                }
                if (!parentId) {
                    idToDepth[id] = 0;
                    return 0;
                }

                const parentEl = idToEl[parentId] || null;
                if (!parentEl) {
                    idToDepth[id] = 0;
                    return 0;
                }

                const parentDepth = getDepth(parentEl);
                depth = Math.min(parentDepth + 1, 3); // 与拖拽逻辑保持一致，最大层级3
                idToDepth[id] = depth;
                return depth;
            }

            items.forEach(el => {
                const depth = getDepth(el);
                el.setAttribute('data-depth', depth);
                el.style.marginLeft = (depth * 30) + 'px';
                const depthInput = el.querySelector('.menu-item-depth');
                if (depthInput) depthInput.value = depth;
            });
        }

        // 根据父子关系重排菜单项，确保子级紧跟在父级后面
        function reorderMenuItemsByHierarchy() {
            const container = document.getElementById('menu-items-container');
            if (!container) return;
            const items = Array.from(container.querySelectorAll('.menu-item'));
            if (items.length === 0) return;

            const idToEl = new Map();
            const idToOrder = new Map();
            const parentMap = new Map();
            const roots = [];

            items.forEach(el => {
                const id = parseInt(el.dataset.id) || 0;
                idToEl.set(id, el);
                const orderInput = el.querySelector('.menu-item-order');
                const order = parseInt(orderInput ? orderInput.value : '0') || 0;
                idToOrder.set(id, order);
            });

            items.forEach(el => {
                const id = parseInt(el.dataset.id) || 0;
                const parentInput = el.querySelector('.menu-item-parent');
                const parentId = parseInt(parentInput ? parentInput.value : '0') || 0;
                if (parentId && idToEl.has(parentId)) {
                    if (!parentMap.has(parentId)) parentMap.set(parentId, []);
                    parentMap.get(parentId).push(id);
                } else {
                    roots.push(id);
                }
            });

            const sortByOrder = (ids) => ids.sort((a, b) => (idToOrder.get(a) || 0) - (idToOrder.get(b) || 0));
            sortByOrder(roots);
            for (const [pid, children] of parentMap.entries()) {
                sortByOrder(children);
            }

            const frag = document.createDocumentFragment();
            const visit = (id) => {
                const el = idToEl.get(id);
                if (el) frag.appendChild(el);
                const children = parentMap.get(id) || [];
                children.forEach(childId => visit(childId));
            };
            roots.forEach(id => visit(id));

            container.appendChild(frag);
        }

        // 页面初始加载时：先重排，再应用缩进，并同步排序索引
        reorderMenuItemsByHierarchy();
        initializeMenuIndentation();
        if (typeof updateMenuOrder === 'function') {
            updateMenuOrder();
        }

        // 标签搜索功能
        const searchTagsInput = document.getElementById('search-tags');
        if (searchTagsInput) {
            searchTagsInput.addEventListener('input', function () {
                const searchTerm = this.value.toLowerCase();
                const items = document.querySelectorAll('#tags-list .item-checkbox');

                items.forEach(item => {
                    const title = item.querySelector('.item-title').textContent.toLowerCase();
                    const url = item.querySelector('.item-url').textContent.toLowerCase();

                    if (title.includes(searchTerm) || url.includes(searchTerm)) {
                        item.style.display = 'flex';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
        }

        // 添加到菜单功能
        const addToMenuButtons = document.querySelectorAll('.add-to-menu');
        const menuItemsContainer = document.getElementById('menu-items-container');

        addToMenuButtons.forEach(button => {
            button.addEventListener('click', function () {
                const type = this.dataset.type;
                let itemsToAdd = [];

                if (type === 'custom') {
                    // 处理自定义链接
                    const url = document.getElementById('custom_url').value.trim();
                    const title = document.getElementById('custom_title').value.trim();
                    const description = document.getElementById('custom_description').value.trim();

                    if (url && title) {
                        itemsToAdd.push({
                            title: title,
                            url: url,
                            type: 'custom',
                            object_id: 0,
                            attr_title: description
                        });

                        // 清空表单
                        document.getElementById('custom_url').value = '';
                        document.getElementById('custom_title').value = '';
                        document.getElementById('custom_description').value = '';
                    } else {
                        if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils) {
                            TodoCMS.Utils.showMessage('请填写URL和链接文本', 'warning');
                        }
                        return;
                    }
                } else {
                    // 处理其他类型的项目
                    let checkboxName = `add_${type}[]`;
                    // 修正name属性不匹配问题
                    if (type === 'categories') {
                        checkboxName = 'add_category[]';
                    } else if (type === 'tags') {
                        checkboxName = 'add_tag[]';
                    }

                    // 优先按 name 选择，其次按组件类名选择，增强兼容性
                    let checkboxes = document.querySelectorAll(`input[name="${checkboxName}"]:checked`);
                    if (checkboxes.length === 0 && type === 'categories') {
                        checkboxes = document.querySelectorAll('#tab-categories .category-input:checked');
                    }
                    if (checkboxes.length === 0 && type === 'tags') {
                        checkboxes = document.querySelectorAll('#tab-tags input[name="add_tag[]"]:checked');
                    }

                    if (!checkboxes || checkboxes.length === 0) {
                        if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils) {
                            TodoCMS.Utils.showMessage('请选择要添加的项目', 'warning');
                        }
                        return;
                    }

                    checkboxes.forEach(checkbox => {
                        let itemType = type;
                        if (type === 'categories') {
                            itemType = 'category';
                        } else if (type === 'tags') {
                            itemType = 'tag';
                        }

                        // 获取分类数据
                        let title = checkbox.dataset.title || checkbox.getAttribute('data-title');
                        let url = checkbox.dataset.url || checkbox.getAttribute('data-url');

                        // 如果是通用分类选择器，从父级label获取数据
                        if (!title || !url) {
                            const label = checkbox.closest('label');
                            if (label) {
                                // 获取label中的文本内容（排除checkbox本身）
                                const labelText = label.textContent.trim();
                                // 移除前面的空白字符和缩进符号
                                const cleanText = labelText.replace(/^[\s—-]+/, '').trim();

                                if (cleanText) {
                                    // 分离标题和模型信息
                                    const parts = cleanText.split(' - ');
                                    title = parts[0] || cleanText;

                                    // 根据分类ID生成URL
                                    if (checkbox.value) {
                                        url = '/category/' + checkbox.value;
                                    }
                                }
                            }
                        }

                        // 如果仍然没有获取到数据，使用默认值
                        if (!title) {
                            title = (itemType === 'tag' ? '标签 ' : '分类 ') + (checkbox.value || '未知');
                        }
                        if (!url) {
                            if (itemType === 'tag') {
                                url = '/tag/' + (checkbox.value || '0') + '/';
                            } else {
                                url = '/category/' + (checkbox.value || '0');
                            }
                        }

                        // 防止缺少 slug 时把 tag 保存成 /tag//，这会在小程序端退化成全部文章。
                        if (itemType === 'tag' && /^\/tag\/(?:\/)?$/.test(url)) {
                            url = '/tag/' + (checkbox.value || '0') + '/';
                        }

                        itemsToAdd.push({
                            title: title,
                            url: url,
                            type: itemType,
                            object_id: checkbox.value
                        });
                        checkbox.checked = false;
                    });
                }

                // 添加菜单项到结构中
                if (itemsToAdd.length > 0) {
                    addMenuItems(itemsToAdd);
                }
            });
        });

        // 添加菜单项函数
        function addMenuItems(items) {
            // 移除空状态提示
            const noItemsMsg = menuItemsContainer.querySelector('.no-menu-items');
            if (noItemsMsg) {
                noItemsMsg.remove();
            }

            items.forEach(item => {
                const newIndex = Date.now() + Math.random(); // 生成唯一ID
                const menuItem = createMenuItemElement(item, newIndex);
                menuItemsContainer.appendChild(menuItem);
                initMenuItemEvents(menuItem);
            });

            // 重新初始化排序功能
            initSortable();
        }

        // 创建菜单项元素
        function createMenuItemElement(item, index) {
            const div = document.createElement('div');
            div.className = 'menu-item';
            div.dataset.id = index;
            div.dataset.depth = '0';

            div.innerHTML = `
            <div class="menu-item-bar">
                <div class="menu-item-handle" title="拖拽排序">
                    <i class="toicon">≡</i>
                </div>
                <div class="menu-item-title">
                    <span class="item-title">${item.title}</span>
                    <span class="item-type">${item.type}</span>
                </div>
                <div class="menu-item-controls">
                    <button type="button" class="item-toggle" title="展开/折叠">
                        <i class="toicon">▼</i>
                    </button>
                    <button type="button" class="item-delete" title="删除">
                        <i class="toicon">✕</i>
                    </button>
                </div>
            </div>
            
            <div class="menu-item-settings">
                <div class="menu-item-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">导航标签</label>
                            <input type="text" name="menu_items[${index}][title]" value="${item.title}" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">URL</label>
                            <input type="text" name="menu_items[${index}][url]" value="${item.url}" class="form-input">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">标题属性</label>
                            <input type="text" name="menu_items[${index}][attr_title]" value="${item.attr_title || ''}" class="form-input" placeholder="鼠标悬停时显示的文字">
                        </div>
                        <div class="form-group">
                            <label class="form-label">CSS类</label>
                            <input type="text" name="menu_items[${index}][css_class]" value="" class="form-input" placeholder="可选的CSS类名">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="flex: 0 0 100%;">
                            <label class="form-label">图标 (HTML/SVG)</label>
                            <textarea name="menu_items[${index}][icon]" class="form-input" rows="3" placeholder="例如: <img src='...'> 或 <svg>...</svg>">${item.icon || ''}</textarea>
                            <p class="description">支持 &lt;img&gt;、&lt;svg&gt; 等 HTML 标签</p>
                        </div>
                    </div>
                    <div class="form-row" style="margin-bottom: 0;">
                        <div class="checkbox-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="menu_items[${index}][new_window]" value="1">
                                <span class="checkmark"></span>
                                在新窗口/标签页中打开链接
                            </label>
                        </div>
                    </div>
                    <input type="hidden" name="menu_items[${index}][type]" value="${item.type}">
                    <input type="hidden" name="menu_items[${index}][object_id]" value="${item.object_id}">
                    <input type="hidden" name="menu_items[${index}][parent]" value="0" class="menu-item-parent">
                    <input type="hidden" name="menu_items[${index}][order]" value="${menuItemsContainer.children.length}" class="menu-item-order">
                    <input type="hidden" name="menu_items[${index}][depth]" value="0" class="menu-item-depth">
                </div>
            </div>
        `;

            return div;
        }

        // 初始化菜单项事件
        function initMenuItemEvents(menuItem) {
            // 初始化菜单项的缩进
            const depth = parseInt(menuItem.querySelector('.menu-item-depth').value) || 0;
            menuItem.style.marginLeft = depth * 30 + 'px';
            menuItem.setAttribute('data-depth', depth);

            // 展开/折叠
            const toggleButton = menuItem.querySelector('.item-toggle');
            const settings = menuItem.querySelector('.menu-item-settings');

            toggleButton.addEventListener('click', function () {
                const isExpanded = settings.style.display === 'block';
                settings.style.display = isExpanded ? 'none' : 'block';

                const icon = this.querySelector('i');
                icon.textContent = isExpanded ? '▼' : '▲';
            });

            // 删除
            const deleteButton = menuItem.querySelector('.item-delete');
            deleteButton.addEventListener('click', function () {
                const deleteItem = function () {
                    menuItem.remove();
                    updateMenuOrder();

                    // 如果没有菜单项了，显示空状态
                    if (menuItemsContainer.children.length === 0) {
                        const noItemsDiv = document.createElement('div');
                        noItemsDiv.className = 'no-menu-items';
                        noItemsDiv.innerHTML = `
                        <p>暂无菜单项</p>
                        <p class="help-text">从左侧选择页面、栏目、标签或添加自定义链接</p>
                    `;
                        menuItemsContainer.appendChild(noItemsDiv);
                    }
                };

                TodoModal.deleteConfirm({
                    title: '确认删除',
                    message: '确定要删除此菜单项吗？',
                    onConfirm: deleteItem
                });
            });
        }

        // 初始化现有菜单项
        const existingMenuItems = document.querySelectorAll('.menu-item');
        existingMenuItems.forEach(item => {
            initMenuItemEvents(item);
        });

        // 初始化现有菜单项的缩进显示
        function initMenuItems() {
            const menuItems = document.querySelectorAll('#menu-items-container .menu-item');
            menuItems.forEach(item => {
                const depthInput = item.querySelector('.menu-item-depth');
                const depth = parseInt(depthInput ? depthInput.value : 0) || 0;
                item.style.marginLeft = depth * 30 + 'px';
                item.setAttribute('data-depth', depth);

                // 确保深度输入框存在
                if (!depthInput) {
                    const newDepthInput = document.createElement('input');
                    newDepthInput.type = 'hidden';
                    newDepthInput.className = 'menu-item-depth';
                    newDepthInput.value = depth;
                    item.appendChild(newDepthInput);
                }
            });
        }

        // 初始化菜单项
        initMenuItems();

        // 初始化拖拽排序功能
        function initSortable() {
            if (typeof $ !== 'undefined' && $.fn.sortable) {
                // 先销毁现有的sortable实例
                if ($('#menu-items-container').hasClass('ui-sortable')) {
                    $('#menu-items-container').sortable('destroy');
                }

                $('#menu-items-container').sortable({
                    items: '.menu-item',
                    handle: '.menu-item-handle',
                    placeholder: 'ui-sortable-placeholder',
                    helper: function (e, item) {
                        var helper = item.clone();
                        helper.addClass('ui-sortable-helper');
                        return helper;
                    },
                    tolerance: 'pointer',
                    cursor: 'move',
                    opacity: 0.8,
                    axis: 'y',
                    containment: 'parent',
                    start: function (event, ui) {
                        ui.placeholder.height(ui.item.height());
                        ui.placeholder.width(ui.item.width());
                        ui.placeholder.css({
                            'visibility': 'visible',
                            'background': '#f0f0f0',
                            'border': '1px dashed #ccc'
                        });

                        // 记录原始深度
                        ui.item.data('original-depth', parseInt(ui.item.find('.menu-item-depth').val()) || 0);
                    },
                    sort: function (event, ui) {
                        // 计算新的深度基于鼠标位置
                        var containerOffset = ui.item.parent().offset().left;
                        var mouseOffset = event.pageX;
                        var relativeOffset = mouseOffset - containerOffset;
                        var newDepth = Math.floor(relativeOffset / 30); // 每30px为一个层级
                        newDepth = Math.max(0, Math.min(newDepth, 3)); // 限制最大深度为3

                        // 更新占位符的缩进
                        ui.placeholder.css('margin-left', newDepth * 30 + 'px');
                        ui.placeholder.attr('data-depth', newDepth);
                    },
                    stop: function (event, ui) {
                        // 从占位符获取深度
                        var newDepth = parseInt(ui.placeholder.attr('data-depth')) || 0;

                        // 更新菜单项的深度和缩进
                        ui.item.find('.menu-item-depth').val(newDepth);
                        ui.item.attr('data-depth', newDepth);
                        ui.item.css('margin-left', newDepth * 30 + 'px');

                        updateMenuOrder();
                    }
                });
            }
        }

        // 更新菜单项顺序和层级关系
        function updateMenuOrder() {
            const menuItems = document.querySelectorAll('#menu-items-container .menu-item');
            menuItems.forEach((item, index) => {
                const depth = parseInt(item.querySelector('.menu-item-depth').value) || 0;
                let parentId = 0;

                // 更新排序
                const orderInput = item.querySelector('.menu-item-order');
                if (orderInput) {
                    orderInput.value = index;
                }

                // 查找父级项目
                if (depth > 0) {
                    for (let i = index - 1; i >= 0; i--) {
                        const prevItem = menuItems[i];
                        const prevDepth = parseInt(prevItem.querySelector('.menu-item-depth').value) || 0;

                        if (prevDepth === depth - 1) {
                            // 找到直接父级项目：使用 data-id 作为父级索引
                            const parentKey = prevItem.dataset.id || null;
                            if (parentKey) {
                                parentId = parentKey;
                            }
                            break;
                        } else if (prevDepth < depth - 1) {
                            // 深度差距太大，停止查找
                            break;
                        }
                    }
                }

                // 更新父级ID
                const parentInput = item.querySelector('.menu-item-parent');
                if (parentInput) {
                    parentInput.value = parentId;
                }

                // 更新视觉缩进
                item.style.marginLeft = depth * 30 + 'px';
            });
        }

        // 初始化排序功能
        initSortable();

        // 表单提交前: 仅校正顺序/层级，避免重复提交 menu_items 字段
        const menuForm = document.getElementById('menu-form');
        if (menuForm) {
            menuForm.addEventListener('submit', function (e) {
                // 提交前更新排序与父子关系，确保现有表单字段值正确
                updateMenuOrder();
                // 不再创建任何隐藏字段，避免与表单内已有 menu_items[...] 字段重复
            });
        }

        // 全部展开/折叠
        const expandAllBtn = document.getElementById('expand-all');
        const collapseAllBtn = document.getElementById('collapse-all');

        if (expandAllBtn) {
            expandAllBtn.addEventListener('click', function () {
                const allSettings = document.querySelectorAll('.menu-item-settings');
                const allToggles = document.querySelectorAll('.item-toggle i');

                allSettings.forEach(settings => settings.style.display = 'block');
                allToggles.forEach(icon => icon.textContent = '▲');
            });
        }

        if (collapseAllBtn) {
            collapseAllBtn.addEventListener('click', function () {
                const allSettings = document.querySelectorAll('.menu-item-settings');
                const allToggles = document.querySelectorAll('.item-toggle i');

                allSettings.forEach(settings => settings.style.display = 'none');
                allToggles.forEach(icon => icon.textContent = '▼');
            });
        }

        // 删除菜单确认
        const deleteMenuForms = document.querySelectorAll('.delete-menu-form');
        deleteMenuForms.forEach(function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                const menuName = form.getAttribute('data-menu-name') || '此菜单';

                // 使用 TodoModal 确认对话框（如果可用），否则使用原生 confirm
                if (typeof TodoModal !== 'undefined' && TodoModal.deleteConfirm) {
                    TodoModal.deleteConfirm({
                        title: '确认删除菜单',
                        message: '确定要删除菜单"' + menuName + '"吗？将同时删除该菜单下的所有菜单项。',
                        onConfirm: function () {
                            form.submit();
                        }
                    });
                } else {
                    // 回退到原生 confirm
                    if (confirm('确定要删除菜单"' + menuName + '"吗？将同时删除该菜单下的所有菜单项。')) {
                        form.submit();
                    }
                }
            });
        });
    });
</script>
