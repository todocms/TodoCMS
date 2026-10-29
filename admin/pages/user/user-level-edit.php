<?php
/**
 * 用户等级编辑/新建表单
 *
 * @package TodoCMS
 * @version 1.2.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

?>
<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title"><?php echo esc_html($page_title); ?></h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=user-levels'); ?>" class="button button-secondary">返回列表</a>
        </div>
    </div>

    <?php if (isset($_GET['notice']) && $_GET['notice'] === 'slug-normalized'): ?>
        <div class="notice notice-success is-dismissible">
            <p>已根据名称规范化等级标识为：<strong><?php echo esc_html(isset($_GET['final_slug']) ? $_GET['final_slug'] : ''); ?></strong></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>
    <form method="post" id="level-form">
        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title"><?php echo $edit_level ? '编辑用户等级' : '添加新用户等级'; ?></h3>
            </div>
            
            <div class="admin-card-content">
                <?php echo Todo_Security_Manager::get_csrf_field('user_levels_manage', '_csrf_token'); ?>
                <input type="hidden" name="action" value="<?php echo $edit_level ? 'update' : 'create'; ?>">
                <?php if ($edit_level): ?>
                    <input type="hidden" name="level_id" value="<?php echo $edit_level->id; ?>">
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="level_name">等级名称<span class="required">*</span></label>
                        <input type="text" id="level_name" name="level_name" 
                                value="<?php echo $edit_level ? esc_attr($edit_level->level_name) : ''; ?>" 
                                placeholder="请输入等级名称" required>
                        <p class="description">用户等级的显示名称</p>
                    </div>
                    
                    <div class="form-group col-md-6">
                        <label for="level_slug">等级标识<span class="required">*</span></label>
                        <input type="text" id="level_slug" name="level_slug" 
                                value="<?php echo $edit_level ? esc_attr($edit_level->level_slug) : ''; ?>" 
                                placeholder="level-slug" required>
                        <p class="description">只能包含小写字母、数字、下划线和连字符，用于程序调用</p>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label for="min_points">最低积分<span class="required">*</span></label>
                        <input type="number" id="min_points" name="min_points" 
                                value="<?php echo $edit_level ? $edit_level->min_points : 0; ?>" 
                                min="0" step="1" required>
                        <p class="description">达到此等级所需的最低积分</p>
                    </div>
                    
                    <div class="form-group col-md-4">
                        <label for="level_order">排序</label>
                        <input type="number" id="level_order" name="level_order" 
                                value="<?php echo $edit_level ? $edit_level->level_order : 0; ?>" 
                                min="0" step="1">
                        <p class="description">数字越小排序越靠前</p>
                    </div>
                    
                    <div class="form-group col-md-4">
                        <label for="level_color">等级颜色</label>
                        <input type="color" id="level_color" name="level_color" class="color-picker" 
                                value="<?php echo $edit_level ? esc_attr($edit_level->level_color) : '#007cba'; ?>">
                        <p class="description">等级标识颜色</p>
                    </div>

                    <div class="form-group col-md-4">
                        <label for="discount_rate">折扣率(%)</label>
                        <input type="number" id="discount_rate" name="discount_rate"
                                value="<?php echo $edit_level && isset($edit_level->discount_rate) && $edit_level->discount_rate !== '' ? number_format((float)$edit_level->discount_rate, 2, '.', '') : '100.00'; ?>"
                                min="0" max="100" step="0.01" placeholder="0-100">
                        <p class="description">用于商品“自动折扣”计价：100 表示原价（不打折），90 表示九折，0 表示免费</p>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="level_icon">图标</label>
                        <input type="text" id="level_icon" name="level_icon" 
                                value="<?php echo $edit_level ? esc_attr($edit_level->level_icon) : ''; ?>" 
                                placeholder="如：custom-star">
                        <p class="description">图标class用于前端自定义</p>
                    </div>
                    
                    <div class="form-group col-md-6">
                        <label>状态</label>
                        <div class="form-control-static">
                            <label>
                                <input type="checkbox" name="is_active" value="1" 
                                        <?php echo (!$edit_level || $edit_level->is_active) ? 'checked' : ''; ?>>
                                启用此等级
                            </label>
                        </div>
                        <p class="description">禁用的等级不会在前台显示</p>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-12">
                        <label for="description">等级描述</label>
                        <textarea id="description" name="description" rows="3" 
                                    placeholder="请输入等级描述..."><?php echo $edit_level ? esc_textarea($edit_level->description) : ''; ?></textarea>
                        <p class="description">等级的详细描述信息</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="admin-form-submit">
            <a href="<?php echo todo_admin_url('index.php?page=user-levels'); ?>" class="button button-secondary">取消</a>
            <button type="submit" class="button button-primary">
                <?php echo $edit_level ? '更新等级' : '创建等级'; ?>
            </button>
        </div>
    </form>
</div>

<style>
.form-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0 20px;
}

.col-md-4 {
    flex: 0 0 33.333333%;
    max-width: 33.333333%;
}

.col-md-6 {
    flex: 0 0 50%;
    max-width: 50%;
}

.col-md-12 {
    flex: 0 0 100%;
    max-width: 100%;
}

.color-picker {
    height: 35px;
    min-height: auto !important;
    padding: 0 !important;
    border: none !important;
    cursor: pointer;
}

@media (max-width: 768px) {
    .form-row {
        flex-direction: column;
        margin: 0 0 20px;
    }
    
    .col-md-4,
    .col-md-6,
    .col-md-12 {
        flex: 0 0 100%;
        max-width: 100%;
        padding: 0;
    }
}
</style>

<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function() {
    // 自动生成标识（后端拼音+ID）
    if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils && TodoCMS.Utils.initSlugGenerator) {
        TodoCMS.Utils.initSlugGenerator({
            nameInputId: 'level_name',
            slugInputId: 'level_slug',
            ajaxAction: 'user_level',
            id: <?php echo $edit_level ? intval($edit_level->id) : 0; ?>,
            nameParam: 'level_name',
            idParam: 'level_id'
        });
    }
    
    // 表单验证
    const levelForm = document.getElementById('level-form');
    if (levelForm) {
        levelForm.addEventListener('submit', function(e) {
            const levelName = document.getElementById('level_name').value.trim();
            const levelSlug = document.getElementById('level_slug').value.trim();
            const minPoints = document.getElementById('min_points').value;
            
            if (!levelName) {
                TodoCMS.Utils.showMessage('请输入等级名称', 'warning');
                e.preventDefault();
                return;
            }
            
            if (!levelSlug) {
                TodoCMS.Utils.showMessage('请输入等级标识', 'warning');
                e.preventDefault();
                return;
            }
            
            if (!/^[a-z0-9_-]+$/.test(levelSlug)) {
                TodoCMS.Utils.showMessage('等级标识只能包含小写字母、数字、下划线和连字符', 'warning');
                e.preventDefault();
                return;
            }
            
            if (minPoints < 0) {
                TodoCMS.Utils.showMessage('最低积分不能小于0', 'warning');
                e.preventDefault();
                return;
            }
        });
    }
    
    // 颜色选择器预览
    const colorPicker = document.getElementById('level_color');
    if (colorPicker) {
        colorPicker.addEventListener('change', function() {
            this.style.borderColor = this.value;
        });
        
        // 初始化颜色
        colorPicker.style.borderColor = colorPicker.value;
    }
});
</script>
