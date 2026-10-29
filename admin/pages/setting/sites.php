<?php
/**
 * 站点/门店管理页面
 */
/** @var CMS_DB $tododb */
global $tododb;
$table = $tododb->prefix . 'sites';

// 引入媒体模态框及特色图片组件 (参考 settings.php 确保组件函数可用)
if (file_exists(ADMIN_DIR . '/components/media-modal.php')) {
    include_once ADMIN_DIR . '/components/media-modal.php';
}
if (file_exists(ADMIN_DIR . '/components/featured-images-component.php')) {
    include_once ADMIN_DIR . '/components/featured-images-component.php';
}

// 检查权限
if (!todo_current_user_can('manage_sites') && !todo_current_user_can('manage_options')) {
    todo_die('您没有足够的权限访问此页面。');
}

$action = isset($_GET['action']) ? $_GET['action'] : 'list';

if ($action === 'delete') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id) {
        $site = $tododb->get_row("SELECT * FROM {$table} WHERE id = ?", [$id]);
        if ($site && !$site->is_default && (int)$id !== 1) {
            $tododb->query(sprintf("DELETE FROM {$table} WHERE id = %d", $id));
        }
    }
    echo "<script>window.location.href='index.php?page=sites';</script>";
    exit;
}

if ($action === 'add' || $action === 'edit') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $site = $id ? $tododb->get_row("SELECT * FROM {$table} WHERE id = ?", [$id]) : null;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = sanitize_text_field($_POST['site_name']);
        $slug = sanitize_text_field($_POST['site_slug']);
        $site_level = isset($_POST['site_level']) ? sanitize_text_field($_POST['site_level']) : '';
        $service_slogan = sanitize_text_field($_POST['service_slogan']);
        $site_logo = isset($_POST['site_logo']) ? $_POST['site_logo'] : ''; 
        $service_coords = sanitize_text_field($_POST['service_coords']);
        $service_address = isset($_POST['service_address']) ? sanitize_textarea_field($_POST['service_address']) : '';
        $mp_index_sections = sanitize_text_field($_POST['mp_index_sections']);
        $mp_category_banner = sanitize_text_field($_POST['mp_category_banner']);
        $mp_drawer_menu = sanitize_text_field($_POST['mp_drawer_menu']);
        $site_domains_raw = isset($_POST['site_domains']) ? sanitize_textarea_field($_POST['site_domains']) : '';

        
        // 参照 settings.php 处理 Logo (可能为 JSON 字符串)
        if (is_string($site_logo) && !empty($site_logo)) {
            $decoded = json_decode($site_logo, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // 如果是合法 JSON，由 $tododb 直接存储 JSON 字符串或后续按需处理
                // 这里保持原样或解析后处理，通常组件需要存储为 JSON 字符串
            }
        }
        
        $status = sanitize_text_field($_POST['site_status']);
        $is_default = isset($_POST['is_default']) ? 1 : 0;
        $admin_ids = isset($_POST['site_admin_ids']) && is_array($_POST['site_admin_ids'])
            ? array_map('intval', $_POST['site_admin_ids'])
            : [];
        
        if ($is_default) {
            $tododb->query("UPDATE {$table} SET is_default = 0");
        }
        
        // 准备配置数据
        $config_data = [];
        if ($id) {
            $existing_site = $tododb->get_row("SELECT config FROM {$table} WHERE id = ?", [$id]);
            if ($existing_site && !empty($existing_site->config)) {
                $config_data = json_decode($existing_site->config, true) ?: [];
            }
        }
        $config_data['mp_index_sections'] = $mp_index_sections;
        $config_data['mp_category_banner'] = $mp_category_banner;
        $config_data['mp_drawer_menu'] = $mp_drawer_menu;
        $domains = [];
        if (is_string($site_domains_raw) && trim($site_domains_raw) !== '') {
            $parts = preg_split('/[\r\n,]+/', $site_domains_raw) ?: [];
            foreach ($parts as $p) {
                $p = is_string($p) ? trim($p) : '';
                if ($p === '') continue;
                if (strpos($p, '://') !== false) {
                    $h = parse_url($p, PHP_URL_HOST);
                    $p = is_string($h) ? $h : $p;
                }
                $p = strtolower($p);
                $p = preg_replace('/:\d+$/', '', $p);
                $p = preg_replace('#/.*$#', '', $p);
                $p = trim($p, '.');
                if ($p === '') continue;
                if (!preg_match('/^(\*\.)?[a-z0-9][a-z0-9.-]*$/', $p)) continue;
                $domains[] = $p;
            }
        }
        $config_data['domains'] = array_values(array_unique($domains));
        $config_json = json_encode($config_data, JSON_UNESCAPED_UNICODE);

        if ($id) {
            $tododb->update($table, [
                'name'            => $name,
                'slug'            => $slug,
                'site_level'      => $site_level,
                'site_logo'       => $site_logo,
                'service_slogan'  => $service_slogan,
                'service_coords'  => $service_coords,
                'service_address' => $service_address,
                'status'          => $status,
                'is_default'      => $is_default,
                'config'          => $config_json
            ], ['id' => $id]);
        } else {
            $tododb->insert($table, [
                'name'            => $name,
                'slug'            => $slug,
                'site_level'      => $site_level,
                'site_logo'       => $site_logo,
                'service_slogan'  => $service_slogan,
                'service_coords'  => $service_coords,
                'service_address' => $service_address,
                'status'          => $status,
                'is_default'      => $is_default,
                'config'          => $config_json
            ]);
            $id = (int) $tododb->insert_id();
        }

        // 同步站点管理员（先删旧的再写新的）
        $roles_table = $tododb->prefix . 'user_site_roles';
        if ($id > 0 && $tododb->table_exists('user_site_roles')) {
            $tododb->query(
                "DELETE FROM {$roles_table} WHERE site_id = ? AND role = 'admin'",
                [$id]
            );
            foreach ($admin_ids as $uid) {
                if ($uid <= 0) continue;
                $tododb->query(
                    "INSERT INTO {$roles_table} (user_id, site_id, role, status)
                     VALUES (?, ?, 'admin', 'active')
                     ON DUPLICATE KEY UPDATE role = 'admin', status = 'active'",
                    [$uid, $id]
                );
            }
        }

        echo "<script>window.location.href='index.php?page=sites';</script>";
        exit;
    }

    // 获取现有管理员（编辑时）
    $site_admin_ids = [];
    $roles_table = $tododb->prefix . 'user_site_roles';
    if ($id > 0 && $tododb->table_exists('user_site_roles')) {
        $rows = $tododb->get_results(
            "SELECT user_id FROM {$roles_table} WHERE site_id = ? AND role = 'admin' AND status = 'active'",
            [$id]
        );
        foreach ((array) $rows as $r) {
            $site_admin_ids[] = (int) $r->user_id;
        }
    }

    // 查询所有用户供选择 (使用 get_users 以同步隐身过滤逻辑)
    $all_admin_users = get_users(['number' => -1, 'orderby' => 'name', 'order' => 'ASC']);

    // 增强 Logo 数据解析 (参照 settings.php 兼容 JSON/数组/字符串格式)
    $site_logo_images = [];
    if ($site && !empty($site->site_logo)) {
        $raw_logo = $site->site_logo;
        if (is_string($raw_logo) && strpos($raw_logo, '[{') === 0) {
            $decoded = json_decode($raw_logo, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $site_logo_images = $decoded;
            }
        }
        
        if (empty($site_logo_images)) {
            // 回退处理：可能是旧的纯 URL 格式
            if (is_string($raw_logo) && !empty($raw_logo)) {
                $site_logo_images = [['url' => $raw_logo]];
            } elseif (is_array($raw_logo)) {
                $site_logo_images = $raw_logo;
            }
        }
    }
    
    // 解析个性化配置
    $config_data = !empty($site->config) ? json_decode($site->config, true) : [];
    $mp_index_sections = isset($config_data['mp_index_sections']) ? $config_data['mp_index_sections'] : '';
    $mp_category_banner = isset($config_data['mp_category_banner']) ? $config_data['mp_category_banner'] : '';
    $mp_drawer_menu = isset($config_data['mp_drawer_menu']) ? $config_data['mp_drawer_menu'] : '';
    $site_domains = '';
    if (isset($config_data['domains'])) {
        if (is_array($config_data['domains'])) {
            $site_domains = implode("\n", array_filter(array_map('trim', array_map('strval', $config_data['domains']))));
        } elseif (is_string($config_data['domains'])) {
            $site_domains = $config_data['domains'];
        }
    }
    ?>
    <form method="post" class="admin-form">
        <div class="admin-page">
            <div class="admin-page-header">
                <h2 class="admin-page-title"><?php echo $id ? '编辑站点' : '添加新站点'; ?></h2>
            </div>
            <div class="admin-card">
                <div class="admin-card-header"<?php echo $site ? '' : ' style="display: none;"'; ?>>
                    <h3 class="admin-card-title"><?php echo $site ? esc_attr($site->name) : ''; ?></h3>
                </div>
                <div class="admin-card-content">
                    <div class="form-group">
                        <label for="site_name">站点名称</label>
                        <input type="text" name="site_name" id="site_name" class="regular-text mw-100" value="<?php echo $site ? esc_attr($site->name) : ''; ?>" required>
                    </div>

                    <div class="form-group">
                        <label>
                            站点 Logo
                            <div class="media-upload-group">
                                <?php
                                if (function_exists('todo_render_featured_images_component')) {
                                    echo todo_render_featured_images_component(array(
                                        'field_id' => 'site_logo_group',
                                        'field_name' => 'site_logo',
                                        'current_images' => $site_logo_images,
                                        'multiple' => false,
                                        'max_images' => 1,
                                        'enable_debug' => false
                                    ));
                                } else {
                                    echo '<input type="text" name="site_logo" id="site_logo" class="regular-text" value="' . ($site ? esc_attr($site->site_logo) : '') . '">';
                                }
                                ?>
                            </div>
                        </label>
                        <p class="description">如果不设置，将使用系统默认 Logo。</p>
                    </div>
                    <div class="form-group">
                        <label for="site_slug">站点标识 (Slug)</label>
                        <input type="text" name="site_slug" id="site_slug" class="regular-text mw-100" value="<?php echo $site ? esc_attr($site->slug) : ''; ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="site_domains">绑定域名</label>
                        <textarea name="site_domains" id="site_domains" style="min-width: 50%;" class="large-text mw-100" rows="3" placeholder="例如: example.com 或 *.example.com，每行一个域名"><?php echo esc_textarea($site_domains); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="site_level">站点等级</label>
                        <input type="text" name="site_level" id="site_level" class="regular-text" value="<?php echo $site && isset($site->site_level) ? esc_attr($site->site_level) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="is_default" value="1" <?php checked($site ? $site->is_default : 0, 1); ?>> 设置为默认站点
                        </label>
                    </div>
                    <div class="form-group">
                        <label for="site_status">状态</label>
                        <select name="site_status" id="site_status">
                            <option value="active" <?php selected($site ? $site->status : 'active', 'active'); ?>>激活</option>
                            <option value="inactive" <?php selected($site ? $site->status : '', 'inactive'); ?>>禁用</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="service_slogan">服务宗旨</label>
                        <input type="text" name="service_slogan" id="service_slogan" style="min-width: 50%;" class="regular-text" value="<?php echo $site ? esc_attr($site->service_slogan) : ''; ?>" placeholder="例如: 青翠集市 · 品质生活">
                    </div>

                    <div class="form-group">
                        <label for="service_coords">服务坐标</label>
                        <input type="text" name="service_coords" id="service_coords" style="min-width: 50%;" class="regular-text" value="<?php echo $site ? esc_attr($site->service_coords) : ''; ?>" placeholder="例如: 116.404, 39.915">
                        <p class="description">请手动输入经纬度坐标（经度,纬度）。</p>
                    </div>

                    <div class="form-group">
                        <label for="service_address">服务地址</label>
                        <textarea name="service_address" id="service_address" style="min-width: 50%;" class="large-text mw-100" rows="3" placeholder="例如: 北京市东城区长安街1号"><?php echo $site ? esc_textarea($site->service_address) : ''; ?></textarea>
                    </div>

                    <!-- 站点管理员指定 -->
                    <div class="form-group">
                        <div class="flex items-center gap-y-1 flex-wrap justify-between">
                            <label class="m-0">站点管理员</label>
                            <input type="text" id="site-admin-search" placeholder="搜索用户名/邮箱...">
                        </div>
                        <div class="site-admin-picker" style="margin: 10px 0 0;">
                            <div id="site-admin-list" style="max-height:220px;margin-top: 10px;overflow-y:auto;border-radius: 3px;border: 1px solid #eceef1;">
                                <?php if (!empty($all_admin_users)): ?>
                                    <?php foreach ($all_admin_users as $u): ?>
                                        <label class="site-admin-item" style="display:flex;align-items:center;gap: 5px;padding:6px 10px;margin: 0;cursor:pointer;transition:background .15s;"
                                                data-name="<?php echo esc_attr(strtolower($u->display_name . ' ' . $u->user_login . ' ' . $u->user_email)); ?>">
                                            <input type="checkbox" name="site_admin_ids[]" value="<?php echo (int)$u->ID; ?>"
                                                    <?php checked(in_array((int)$u->ID, $site_admin_ids, true)); ?>>
                                            <strong><?php echo esc_html($u->display_name ?: $u->user_login); ?></strong>
                                            <span class="description m-0"><?php echo esc_html($u->user_email); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p style="color:#999; font-size:13px;">暂无可用用户</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <p class="description">可指定多个管理员，站点管理员仅能管理该站点内容。</p>
                    </div>
                </div>
            </div>

            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">个性化（针对小程序）</h3>
                </div>
                <div class="admin-card-content">
                    <div class="form-group">
                        <label for="mp_index_sections">[首页] 模块与排序</label>
                        <input type="text" name="mp_index_sections" id="mp_index_sections" class="regular-text mw-100" value="<?php echo esc_attr($mp_index_sections); ?>" placeholder="[广告|5],[分类|24],[活动|63]">
                        <p class="description">示例：[广告|5],[分类|24],[活动|63]，依次对应page=ads广告管理、page=menus菜单管理、page=categories栏目管理。排序由输入决定。</p>
                    </div>

                    <div class="form-group">
                        <label for="mp_category_banner">[分类] 广告图</label>
                        <input type="text" name="mp_category_banner" id="mp_category_banner" class="regular-text mw-100" value="<?php echo esc_attr($mp_category_banner); ?>" placeholder="[全部分类|30],[搜索|98],[5|24]">
                        <p class="description">对应分类页 Banner。示例：[默认|21],[分类11|21],[分类12|24]...</p>
                    </div>

                    <div class="form-group">
                        <label for="mp_drawer_menu">[侧边栏] 菜单ID</label>
                        <input type="number" name="mp_drawer_menu" id="mp_drawer_menu" class="regular-text" value="<?php echo esc_attr($mp_drawer_menu); ?>" placeholder="例如: 12">
                        <p class="description">对应首页左侧菜单。数据来自 page=menus 菜单 ID，留空使用硬编码默认项。</p>
                    </div>
                </div>
            </div>
            <div class="admin-form-submit">
                <a href="index.php?page=sites" class="button button-secondary">取消</a>
                <button type="submit" class="button button-primary"><?php echo $id ? '更新' : '添加'; ?></button>
            </div>
        </div>
    </form>
    <style>
    /* 媒体上传组件样式 */
    .media-upload-group {
        display: flex;
        align-items: center;
        flex-direction: column;
        align-items: flex-start;
        margin-top: 10px;
    }
    .site-admin-item:hover { background: #efeff5; }
    </style>
    <script>
    (function() {
        var search = document.getElementById('site-admin-search');
        if (!search) return;
        search.addEventListener('input', function() {
            var kw = this.value.toLowerCase().trim();
            document.querySelectorAll('#site-admin-list .site-admin-item').forEach(function(el) {
                el.style.display = (!kw || el.dataset.name.indexOf(kw) !== -1) ? 'flex' : 'none';
            });
        });
    })();
    </script>
    <?php
} else {
    $sites = $tododb->get_results("SELECT * FROM {$table} ORDER BY is_default DESC, id ASC");
    ?>
    <div class="admin-page">
        <div class="admin-page-header">
            <h2 class="admin-page-title verse">“安得广厦千万间，大庇天下寒士俱欢颜。”<span>——杜甫《茅屋为秋风所破歌》</span></h2>
            <div class="admin-page-actions">
                <a href="index.php?page=sites&action=add" class="button button-primary">添加新站点</a>
            </div>
        </div>
        
        <div class="admin-card">
            <div class="admin-card-content">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="check-column">站点</th>
                            <th style="width: 60px;">默认</th>
                            <th style="min-width: 200px;">名称 / 地址</th>
                            <th style="min-width: 120px;">坐标</th>
                            <th>Slug</th>
                            <th style="width: 80px; min-width: 80px;">状态</th>
                            <th style="width: 90px; min-width: 90px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($sites)): foreach ($sites as $site):
                            // 查询该站点的管理员
                            $site_admins = [];
                            if ($tododb->table_exists('user_site_roles')) {
                                $site_admins = (array) $tododb->get_results(
                                    "SELECT u.ID, u.display_name, u.user_login
                                     FROM {$tododb->prefix}user_site_roles r
                                     INNER JOIN {$tododb->prefix}users u ON u.ID = r.user_id
                                     WHERE r.site_id = ? AND r.role = 'admin' AND r.status = 'active'
                                     ORDER BY u.display_name ASC",
                                    [$site->id]
                                );
                            }
                        ?>
                        <tr>
                            <td>site<?php echo $site->id; ?></td>
                            <td><?php echo $site->is_default ? '✅' : ''; ?></td>
                            <td>
                                <strong><?php echo esc_html($site->name); ?></strong>
                                <?php if (!empty($site_admins)): ?>
                                <div style="margin-top:4px; display:flex; flex-wrap:wrap; gap:4px;">
                                    <?php foreach ($site_admins as $sa): ?>
                                    <span style="display:inline-flex; align-items:center; gap:4px; background:#f3f3f3; border:1px solid #dbdfee; border-radius:3px; padding:0 5px; font-size:12px; color:#282832;">
                                        <?php echo esc_html($sa->display_name ?: $sa->user_login); ?>
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td><small style="color: #666;"><?php echo esc_html($site->service_coords); ?></small></td>
                            <td><code><?php echo esc_html($site->slug); ?></code></td>
                            <td>
                                <span class="<?php echo $site->status === 'active' ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $site->status === 'active' ? '激活' : '禁用'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <span class="edit"><a href="index.php?page=sites&action=edit&id=<?php echo $site->id; ?>">编辑</a> | </span>
                                    <?php if (!$site->is_default && (int)$site->id !== 1): ?>
                                    <span class="trash"><a href="index.php?page=sites&action=delete&id=<?php echo $site->id; ?>" class="submitdelete" onclick="event.preventDefault(); var url = this.href; TodoModal.confirm({ title: '确认删除', message: '确定要删除该站点吗？此操作不可恢复！', type: 'danger', confirmText: '确认删除', onConfirm: function() { window.location.href = url; } }); return false;">删除</a></span>
                                    <?php else: ?>
                                    <span class="trash" title="默认站点或根站点不可删除" style="color:#999;cursor:not-allowed;">删除</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr class="empty">
                            <td colspan="6">
                                <div class="no-items">
                                    <p>暂无站点。</p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">站点管理与多语言架构设计说明</h3>
            </div>
            
            <div class="admin-card-content">
                <h4>1. 核心架构设计</h4>
                <p>TodoCMS 采用了<strong>“站点 (Site) + 语言 (Language)”</strong>的双重维度架构设计。这种设计不仅支持单一站点的多语言切换，也支持多站点（如不同门店、子品牌）之间的物理或逻辑硬隔离。</p>
                
                <h4 style="margin-top:15px;">2. 翻译维度升级</h4>
                <p>系统的翻译维度已从早期的“仅基于语言 (Language Only)”升级为 <strong>“站点 (site_id) + 语言 (language_code)”</strong> 的复合维度：</p>
                <ul class="description" style="margin-left: 20px; list-style: disc;">
                    <li><strong>内容翻译 (Post Translations)：</strong> 基于 <code>post_id + site_id + language_code</code>。同一篇文章在不同站点可以拥有完全不同的翻译内容。</li>
                    <li><strong>自定义字段 (Custom Fields)：</strong> 基于 <code>post_id + field_key + site_id + language_code</code>。支持站点特定的扩展属性。</li>
                    <li><strong>术语与分类 (Terms)：</strong> 支持跨站点可见性控制 (<code>site_terms</code>) 及翻译映射。</li>
                    <li><strong>全局选项 (Options)：</strong> 支持 <code>site_id = 0</code> 的全局缺省配置和特定站点的重写配置。</li>
                </ul>

                <h4 style="margin-top:15px;">3. 站点隔离与可见性</h4>
                <p>通过映射表（如 <code>site_posts</code>, <code>site_terms</code>）实现内容的精准投放：</p>
                <ul class="description" style="margin-left: 20px; list-style: disc;">
                    <li><strong>硬隔离：</strong> 站点管理员仅能通过 <code>user_site_roles</code> 授权后访问其所属站点的内容。</li>
                    <li><strong>数据分发：</strong> 一条原始数据（如产品信息）可以通过关联多个 <code>site_id</code> 在不同站点同步更新，而无需重复录入。</li>
                </ul>

                <h4 style="margin-top:15px;">4. 语言回退逻辑 (Fallback)</h4>
                <p>系统自动处理多级语言降级：</p>
                <ol class="description" style="margin-left: 20px;">
                    <li>寻找当前站点指定的<strong>默认语言</strong>翻译。</li>
                    <li>寻找全局<strong>默认语言</strong>翻译。</li>
                    <li>寻找系统<strong>回退语言</strong>（通常为 <code>zh-CN</code>）。</li>
                    <li>若均未命中，则返回<strong>原始文本</strong>。</li>
                </ol>

                <h4 style="margin-top:15px;">5. 维护提醒</h4>
                <p class="description">开发人员在编写查询 SQL 时，请务必包含 <code>site_id</code> 过滤条件。在调用翻译函数时，系统会自动携带当前 Context 中的 <code>site_id</code> 和 <code>locale</code>，无需手动传递。</p>
            </div>
        </div>
    </div>
    <?php
}
