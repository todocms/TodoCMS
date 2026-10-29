<?php
/**
 * 升级套餐编辑页面
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 权限检查
if (!todo_current_user_can('manage_users')) {
    todo_die('您没有权限访问此页面。');
}

// 获取套餐管理器和用户等级管理器
$package_manager = todo_get_upgrade_package_manager();
$level_manager = todo_get_user_level_manager();

// 获取用户等级列表
$levels = $level_manager->get_user_levels(array('is_active' => 1));

// 获取当前编辑的套餐
$edit_package = null;
$is_edit = false;

if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_package = $package_manager->get_upgrade_package($_GET['edit']);
    $is_edit = true;
    
    if (!$edit_package) {
        todo_add_admin_notice('套餐不存在或已被删除。', 'error');
        todo_redirect(todo_admin_url('index.php?page=upgrade-packages'));
        exit;
    }
    
    // 解析features字段
    if ($edit_package) {
        $raw_features = isset($edit_package->features) ? $edit_package->features : '';
        if (!empty($raw_features)) {
            $decoded = json_decode($raw_features, true);
            if (is_array($decoded)) {
                $edit_package->package_features = isset($decoded['list']) && is_array($decoded['list']) ? $decoded['list'] : array();
                $edit_package->package_icon = isset($decoded['icon']) ? $decoded['icon'] : '';
                $edit_package->package_color = isset($decoded['color']) ? $decoded['color'] : '#007cba';
            } else {
                $edit_package->package_features = array();
                $edit_package->package_icon = '';
                $edit_package->package_color = '#007cba';
            }
        } else {
            $edit_package->package_features = array();
            $edit_package->package_icon = '';
            $edit_package->package_color = '#007cba';
        }
    }
}

// 页面标题
$page_title = $is_edit ? '编辑升级套餐' : '添加升级套餐';

// 套餐类型选项
$package_types = array(
    'points' => '积分套餐',
    'direct' => '直升套餐',
    'time' => '时长套餐'
);

// 默认值
$defaults = array(
    'package_name' => '',
    'package_slug' => '',
    'target_level_id' => '',
    'package_type' => 'points',
    'points_amount' => 0,
    'price' => 0,
    'original_price' => '',
    'validity_days' => 0,
    'package_features' => array(),
    'package_icon' => 'custom-cart',
    'package_color' => '#007cba',
    'description' => '',
    'sort_order' => 0,
    'is_recommended' => 0,
    'is_active' => 1
);

// 合并默认值和编辑数据
if ($edit_package) {
    foreach ($defaults as $key => $default) {
        if (!isset($edit_package->$key)) {
            $edit_package->$key = $default;
        }
    }
} else {
    $edit_package = (object) $defaults;
}
?>

<div class="admin-page">
    <form id="package-form" method="post" action="<?php echo todo_admin_url('index.php?page=upgrade-packages'); ?>">
        <?php echo Todo_Security_Manager::get_csrf_field('upgrade_package_action', '_csrf_token'); ?>
        <input type="hidden" name="action" value="<?php echo $is_edit ? 'update' : 'create'; ?>">
        <?php if ($is_edit): ?>
        <input type="hidden" name="package_id" value="<?php echo $edit_package->id; ?>">
        <?php endif; ?>

    <div class="admin-page-header">
        <h2 class="admin-page-title"><?php echo esc_html($page_title); ?></h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=upgrade-packages'); ?>" class="button button-secondary">
                返回列表
            </a>
        </div>
    </div>

    <?php 
    // 显示系统通知
    if (function_exists('todo_admin_notices')) {
        todo_admin_notices();
    }
    ?>

    <?php if (isset($_GET['notice']) && $_GET['notice'] === 'slug-normalized' && isset($_GET['final_slug'])): ?>
        <div class="notice notice-success is-dismissible">
            已规范套餐标识为：<code><?php echo esc_html($_GET['final_slug']); ?></code>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

        <div class="admin-form-layout">
            <!-- 左侧主要内容 -->
            <div class="admin-form-main">
                <!-- 基本信息 -->
                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">基本信息</h3>
                    </div>
                    <div class="admin-card-content">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="package_name">套餐名称 <span class="required">*</span></label>
                                <input type="text" id="package_name" name="package_name" 
                                       value="<?php echo esc_attr($edit_package->package_name); ?>" 
                                       required>
                                <p class="description">套餐的显示名称，用户可见</p>
                            </div>
                            <div class="form-group">
                                <label for="package_slug">套餐标识 <span class="required">*</span></label>
                                <input type="text" id="package_slug" name="package_slug" 
                                       value="<?php echo esc_attr($edit_package->package_slug); ?>" 
                                       required placeholder="package-slug">
                                <p class="description">唯一标识符，只能包含小写字母、数字、连字符和下划线</p>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="package_type">套餐类型 <span class="required">*</span></label>
                                <select id="package_type" name="package_type" required>
                                    <?php foreach ($package_types as $type => $label): ?>
                                    <option value="<?php echo esc_attr($type); ?>" 
                                            <?php selected($edit_package->package_type, $type); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">选择套餐的功能类型</p>
                            </div>
                            <div class="form-group">
                                <label for="target_level_id">目标等级</label>
                                <select id="target_level_id" name="target_level_id">
                                    <option value="">请选择目标等级</option>
                                    <?php foreach ($levels as $level): ?>
                                    <option value="<?php echo $level->id; ?>" 
                                            <?php selected($edit_package->target_level_id, $level->id); ?>>
                                        <?php echo esc_html($level->level_name); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">用户购买后将升级到的等级</p>
                            </div>
                        </div>
                        
                        <div class="form-row type-specific" id="points-settings" style="<?php echo $edit_package->package_type !== 'points' ? 'display: none;' : ''; ?>">
                            <div class="form-group">
                                <label for="points_amount">积分数量</label>
                                <input type="number" id="points_amount" name="points_amount" 
                                       value="<?php echo esc_attr($edit_package->points_amount); ?>" 
                                       min="0">
                                <p class="description">用户购买后获得的积分数量</p>
                            </div>
                        </div>
                        
                        <div class="form-row type-specific" id="time-settings" style="<?php echo $edit_package->package_type !== 'time' ? 'display: none;' : ''; ?>">
                            <div class="form-group">
                                <label for="validity_days">有效期（天）</label>
                                <input type="number" id="validity_days" name="validity_days" 
                                       value="<?php echo esc_attr($edit_package->validity_days); ?>" 
                                       min="0">
                                <p class="description">套餐的有效期，0表示永久有效</p>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="description">套餐描述</label>
                            <textarea id="description" name="description" rows="3"><?php echo esc_textarea($edit_package->description); ?></textarea>
                            <p class="description">套餐的详细描述，支持HTML</p>
                        </div>
                    </div>
                </div>

                <!-- 价格设置 -->
                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">价格设置</h3>
                    </div>
                    <div class="admin-card-content">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="price">现售价格 <span class="required">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><?php echo todo_get_default_currency_config()['symbol']; ?></span>
                                    <input type="number" id="price" name="price" 
                                           value="<?php echo esc_attr($edit_package->price); ?>" 
                                           step="0.01" min="0" required>
                                </div>
                                <p class="description">用户实际支付的价格</p>
                            </div>
                            <div class="form-group">
                                <label for="original_price">原价</label>
                                <div class="input-group">
                                    <span class="input-group-text"><?php echo todo_get_default_currency_config()['symbol']; ?></span>
                                    <input type="number" id="original_price" name="original_price" 
                                           value="<?php echo esc_attr($edit_package->original_price); ?>" 
                                           step="0.01" min="0">
                                </div>
                                <p class="description">原价，用于显示折扣信息，留空则不显示</p>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <h4>价格预览：</h4>
                            <div class="preview-content">
                                <span class="preview-current-price"><?php echo todo_format_admin_price((float)$edit_package->price); ?></span>
                                <span class="preview-original-price" style="<?php echo empty($edit_package->original_price) ? 'display: none;' : ''; ?>"><?php echo empty($edit_package->original_price) ? '' : todo_format_admin_price((float)$edit_package->original_price); ?></span>
                                <span class="preview-discount" style="display: none;"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 套餐特性 -->
                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">套餐特性</h3>
                        <button type="button" class="button" onclick="addFeature()">添加特性</button>
                    </div>
                    <div class="admin-card-content">
                        <div id="features-container">
                            <?php if (!empty($edit_package->package_features)): ?>
                                <?php foreach ($edit_package->package_features as $index => $feature): ?>
                                <div class="feature-item" data-index="<?php echo esc_attr($index); ?>">
                                    <div class="feature-input">
                                        <input type="text" name="package_features[]" 
                                               value="<?php echo esc_attr($feature); ?>" 
                                               placeholder="输入套餐特性...">
                                        <button type="button" class="button button-secondary" onclick="removeFeature(this)"><i class="toicon">✕</i></button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <p class="description">列出套餐包含的特性和权益</p>
                    </div>
                </div>
            </div>

            <!-- 右侧设置 -->
            <div class="admin-form-sidebar">
                <!-- 发布设置 -->
                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">发布设置</h3>
                    </div>
                    <div class="admin-card-content">
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="is_active" value="1" 
                                       <?php checked($edit_package->is_active, 1); ?>>
                                <span class="checkmark"></span>
                                启用套餐
                            </label>
                            <p class="description">禁用的套餐不会在前端显示</p>
                        </div>
                        
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="is_recommended" value="1" 
                                       <?php checked($edit_package->is_recommended, 1); ?>>
                                <span class="checkmark"></span>
                                推荐套餐
                            </label>
                            <p class="description">推荐套餐会有特殊标识</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="sort_order">排序</label>
                            <input type="number" id="sort_order" name="sort_order" 
                                   value="<?php echo esc_attr($edit_package->sort_order); ?>" 
                                   min="0" style="min-width: auto; width: 100px;">
                            <p class="description">数字越小排序越靠前</p>
                        </div>
                    </div>
                </div>

                <!-- 外观设置 -->
                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">外观设置</h3>
                    </div>
                    <div class="admin-card-content">
                        <div class="form-group">
                            <label for="package_icon">图标</label>
                            <div class="icon-selector">
                                <input type="text" id="package_icon" name="package_icon" 
                                       value="<?php echo esc_attr($edit_package->package_icon); ?>" 
                                       class="icon-input" placeholder="如：custom-star">
                            </div>
                            <p class="description">图标class用于前端自定义</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="package_color">套餐颜色</label>
                            <div class="color-selector">
                                <input type="color" id="package_color" name="package_color" 
                                       value="<?php echo esc_attr($edit_package->package_color); ?>" 
                                       class="color-input">
                                <input type="text" class="color-text" 
                                       value="<?php echo esc_attr($edit_package->package_color); ?>" 
                                       readonly>
                            </div>
                            <p class="description">套餐的主题颜色</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 操作按钮 -->
        <div class="admin-form-submit m-t-0">
            <a href="<?php echo todo_admin_url('index.php?page=upgrade-packages'); ?>" class="button button-secondary">取消</a>
            <button type="submit" class="button button-primary">
                <?php echo $is_edit ? '更新套餐' : '创建套餐'; ?>
            </button>
        </div>
    </form>
</div>

<style>
.admin-form-layout {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 20px;
}

.admin-form-main {
    display: flex;
    flex-direction: column;
}

.admin-form-sidebar {
    display: flex;
    flex-direction: column;
}

/* 表单样式 */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0 20px;
}

.form-row:last-child {
    margin-bottom: 0;
}

.form-group {
    display: flex;
    flex-direction: column;
}

/* 输入组样式 */
.input-group {
    display: flex;
}

.input-group input {
    border-radius: 0 3px 3px 0;
}

.input-group-text {
    padding: 6px 8px;
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-right: 0;
    border-radius: 3px 0 0 3px;
    font-size: 13px;
    color: #666;
}

/* 复选框样式 */
.checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-weight: normal !important;
    margin-bottom: 0 !important;
}

.checkbox-label input[type="checkbox"] {
    margin: 0;
    display: none;
}

.checkmark {
    width: 16px;
    height: 16px;
    border: 1px solid #ddd;
    border-radius: 3px;
    position: relative;
    background: #fff;
}

.checkbox-label input[type="checkbox"]:checked + .checkmark {
    background: #007cba;
    border-color: #007cba;
}

.checkbox-label input[type="checkbox"]:checked + .checkmark:after {
    content: "";
    position: absolute;
    left: 5px;
    top: 2px;
    width: 3px;
    height: 7px;
    border: solid white;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

/* 特性列表样式 */
.feature-item {
    margin-bottom: 10px;
}

.feature-input {
    display: flex;
    gap: 10px;
    align-items: center;
}

/* 图标选择器样式 */
.icon-selector {
    display: flex;
    gap: 10px;
    flex-direction: column;
    align-items: flex-start;
}

.icon-input {
    flex: 1;
}

/* 颜色选择器样式 */
.color-selector {
    display: flex;
    flex-direction: column;
    gap: 10px;
    align-items: start;
}

.color-input {
    width: 50px;
    height: 35px;
    min-height: auto !important;
    padding: 0 !important;
    border: none !important;
    cursor: pointer;
}

.color-text {
    flex: 1;
    background: #f9f9f9;
}

/* 预览样式 */
.preview-content {
    display: flex;
    align-items: center;
    gap: 8px;
}

.preview-current-price {
    font-weight: 500;
    color: #d63638;
    font-size: 16px;
}

.preview-original-price {
    font-size: 14px;
    color: #666;
    text-decoration: line-through;
}

.preview-discount {
    font-size: 12px;
    background: #d63638;
    color: white;
    padding: 2px 6px;
    border-radius: 3px;
}

/* 类型特定设置 */
.type-specific {
    display: none;
}

.type-specific.active {
    display: grid;
}

/* 响应式设计 */
@media (max-width: 1024px) {
    .admin-form-layout {
        grid-template-columns: 1fr;
    }
    
    .admin-form-sidebar {
        order: -1;
    }
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }
    
    .icon-selector,
    .color-selector {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<script type="text/javascript">
// 页面加载完成后初始化
document.addEventListener('DOMContentLoaded', function() {
    initializeForm();
    initializePricePreview();
    initializePackageTypeToggle();
    initializeSlugGeneration();
});

// 初始化表单
function initializeForm() {
    // 表单验证
    const form = document.getElementById('package-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            if (!validateForm()) {
                e.preventDefault();
            }
        });
    }
}

// 表单验证
function validateForm() {
    let isValid = true;
    const errors = [];
    
    // 验证必填字段
    const requiredFields = {
        'package_name': '套餐名称',
        'package_slug': '套餐标识',
        'package_type': '套餐类型',
        'price': '现售价格'
    };
    
    for (const [field, label] of Object.entries(requiredFields)) {
        const element = document.getElementById(field);
        if (element && !element.value.trim()) {
            errors.push(`${label}不能为空`);
            element.style.borderColor = '#dc3232';
            isValid = false;
        } else if (element) {
            element.style.borderColor = '#ddd';
        }
    }
    
    // 验证套餐标识格式
    const slugField = document.getElementById('package_slug');
    if (slugField && slugField.value) {
        if (!/^[a-z0-9-]+$/.test(slugField.value)) {
            errors.push('套餐标识只能包含小写字母、数字、连字符和下划线');
            slugField.style.borderColor = '#dc3232';
            isValid = false;
        }
    }
    
    // 验证价格
    const priceField = document.getElementById('price');
    if (priceField && priceField.value) {
        const price = parseFloat(priceField.value);
        if (price < 0) {
            errors.push('价格不能为负数');
            priceField.style.borderColor = '#dc3232';
            isValid = false;
        }
    }
    
    // 显示错误信息
    if (!isValid) {
        TodoCMS.Utils.showMessage('请修正以下错误：<br>' + errors.join('<br>'), 'warning');
    }
    
    return isValid;
}

// 初始化价格预览
function initializePricePreview() {
    const priceField = document.getElementById('price');
    const originalPriceField = document.getElementById('original_price');
    
    if (priceField) {
        priceField.addEventListener('input', updatePricePreview);
    }
    
    if (originalPriceField) {
        originalPriceField.addEventListener('input', updatePricePreview);
    }
    
    updatePricePreview();
}

// 更新价格预览
function updatePricePreview() {
    const price = parseFloat(document.getElementById('price').value) || 0;
    const originalPrice = parseFloat(document.getElementById('original_price').value) || 0;
    
    const currentPriceEl = document.querySelector('.preview-current-price');
    const originalPriceEl = document.querySelector('.preview-original-price');
    const discountEl = document.querySelector('.preview-discount');
    
    const currSymbol = <?php echo json_encode(todo_get_default_currency_config()['symbol']); ?>;
    if (currentPriceEl) {
        currentPriceEl.textContent = currSymbol + price.toFixed(2);
    }
    
    if (originalPriceEl && discountEl) {
        if (originalPrice > 0 && originalPrice > price) {
            originalPriceEl.textContent = currSymbol + originalPrice.toFixed(2);
            originalPriceEl.style.display = 'inline';
            
            const discountRate = Math.round((1 - price / originalPrice) * 100);
            discountEl.textContent = discountRate + '% OFF';
            discountEl.style.display = 'inline';
        } else {
            originalPriceEl.style.display = 'none';
            discountEl.style.display = 'none';
        }
    }
}

// 初始化套餐类型切换
function initializePackageTypeToggle() {
    const typeField = document.getElementById('package_type');
    if (typeField) {
        typeField.addEventListener('change', togglePackageTypeSettings);
        togglePackageTypeSettings();
    }
}

// 切换套餐类型设置
function togglePackageTypeSettings() {
    const type = document.getElementById('package_type').value;
    const typeSpecific = document.querySelectorAll('.type-specific');
    
    typeSpecific.forEach(el => {
        el.style.display = 'none';
        el.classList.remove('active');
    });
    
    const targetEl = document.getElementById(type + '-settings');
    if (targetEl) {
        targetEl.style.display = 'grid';
        targetEl.classList.add('active');
    }
}

// 初始化标识符自动生成（与用户等级编辑交互一致：后端拼音 + ID）
function initializeSlugGeneration() {
    if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils && TodoCMS.Utils.initSlugGenerator) {
        TodoCMS.Utils.initSlugGenerator({
            nameInputId: 'package_name',
            slugInputId: 'package_slug',
            ajaxAction: 'upgrade_package',
            id: <?php echo $is_edit ? intval($edit_package->id) : 0; ?>,
            nameParam: 'package_name',
            idParam: 'package_id',
            ajaxUrl: '<?php echo esc_js(todo_admin_url('admin-ajax.php')); ?>',
            defaultSlug: 'package',
            document: document
        });
    }
}

// 生成标识符
function generateSlug(text) {
    return text
        .toLowerCase()
        .replace(/[^a-z0-9\u4e00-\u9fa5]/g, '-')
        .replace(/\u4e00-\u9fa5/g, function(match) {
            // 简单的中文转拼音处理，实际项目中可能需要更完善的库
            return 'cn';
        })
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '')
        .substring(0, 50);
}

// 添加特性
function addFeature() {
    const container = document.getElementById('features-container');
    const index = container.children.length;
    
    const featureItem = document.createElement('div');
    featureItem.className = 'feature-item';
    featureItem.dataset.index = index;
    
    featureItem.innerHTML = `
        <div class="feature-input">
            <input type="text" name="package_features[]" 
                   placeholder="输入套餐特性...">
            <button type="button" class="button button-secondary" onclick="removeFeature(this)"><i class="toicon">✕</i></button>
        </div>
    `;
    
    container.appendChild(featureItem);
    
    // 聚焦到新添加的输入框
    const newInput = featureItem.querySelector('input');
    if (newInput) {
        newInput.focus();
    }
}

// 删除特性
function removeFeature(button) {
    const featureItem = button.closest('.feature-item');
    if (featureItem) {
        featureItem.remove();
    }
}
</script>