<?php
/**
 * 层级分类选择器组件
 * 
 * 功能特性：
 * 1. 支持层级分类显示
 * 2. 支持复选框和下拉选择两种模式
 * 3. 支持按模型过滤分类
 * 4. 智能模型限制：在post-new和post-edit页面自动启用同模型限制，
 *    选择某个模型的分类后，其他模型的分类将被禁用，
 *    当取消所有选择时，所有分类重新变为可选
 * 5. 在其他页面需要明确设置 'restrict_to_same_model' => true 来启用限制
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问 - 此脚本只能在管理员环境中运行
if (!defined('IS_ADMIN') && !defined('DOING_AJAX') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

/**
 * 渲染层级分类选择器
 * 
 * @param array $args 配置参数
 * @return void
 */
function render_hierarchical_categories_selector($args = array()) {
    // 默认参数
    $defaults = array(
        'name' => 'categories[]',
        'id' => 'categories',
        'selected' => array(),
        'multiple' => true,
        'show_checkbox' => true,
        'show_select' => false,
        'class' => 'hierarchical-categories-selector',
        'description' => '',
        'hide_empty' => false,
        'taxonomy' => 'category',
        'model_id' => 0,
        'site_id' => 0,  // 站点过滤：>0 时只显示该站点可见的分类
        'filter_by_model' => false,
        'restrict_to_same_model' => false,
        'model_filter_target' => ''
    );
    
    // 合并参数
    $args = array_merge($defaults, $args);
    
    // 获取分类数据
    $get_cats_args = array(
        'hide_empty' => $args['hide_empty'],
        'orderby' => 'term_id',
        'order' => 'ASC'
    );
    if ($args['site_id'] > 0) {
        $get_cats_args['site_id'] = $args['site_id'];
    }
    
    if ($args['filter_by_model'] && $args['model_id'] > 0) {
        $categories = get_categories_by_model($args['model_id'], $get_cats_args);
    } else {
        $categories = get_categories($get_cats_args);
    }

    // 性能优化：批量预加载所有分类的模型关联信息和元数据
    // 避免在渲染循环中产生 N+1 查询
    if (!empty($categories) && is_array($categories)) {
        $term_ids = [];
        foreach ($categories as &$cat) {
            $tid = is_object($cat) ? (int) ($cat->term_id ?? 0) : (int) ($cat['term_id'] ?? 0);
            if ($tid > 0) $term_ids[] = $tid;

            // 多语言分类名称清理：在后端选择器中仅输出主语言/基础分类名，不原文输出多语言标记（如 产品分类[en|hhjj]）
            $raw_name = is_object($cat) ? ($cat->name ?? ($cat->term_name ?? '')) : ($cat['name'] ?? ($cat['term_name'] ?? ''));
            if (is_string($raw_name) && $raw_name !== '') {
                $clean_name = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($raw_name) : $raw_name;
                $clean_name = trim(preg_replace('/\[[a-z]{2}(?:-[a-z]{2})?\|.*?\]/is', '', $clean_name));
                $clean_name = trim(preg_replace('/\[:[a-z]{2}(?:-[a-z]{2})?\].*?\[:\]/is', '', $clean_name));
                if (is_object($cat)) {
                    $cat->name = $clean_name ?: $raw_name;
                } else {
                    $cat['name'] = $clean_name ?: $raw_name;
                }
            }
        }
        unset($cat);

        if (!empty($term_ids)) {
            if (function_exists('update_term_model_cache')) {
                update_term_model_cache($term_ids);
            }
            if (function_exists('update_term_meta_cache')) {
                update_term_meta_cache($term_ids);
            }
        }
    }
    
    // 构建层级分类
    $hierarchical_categories = build_category_hierarchy_component($categories, 0, 0, 'term_id', 'ASC');
    
    // 确保selected是数组
    if (!is_array($args['selected'])) {
        $args['selected'] = array($args['selected']);
    }
    
    // 渲染HTML
    $data_attrs = 'data-restrict-to-same-model="' . ($args['restrict_to_same_model'] ? 'true' : 'false') . '"';
    if (!empty($args['model_filter_target'])) {
        $data_attrs .= ' data-model-filter-target="' . esc_attr($args['model_filter_target']) . '"';
    }
    
    echo '<div class="' . esc_attr($args['class']) . '" id="' . esc_attr($args['id']) . '_wrapper" ' . $data_attrs . '>';
    
    if ($args['show_checkbox']) {
        render_checkbox_categories($hierarchical_categories, $args);
    } elseif ($args['show_select']) {
        render_select_categories($hierarchical_categories, $args);
    } else {
        render_checkbox_categories($hierarchical_categories, $args);
    }
    
    if (!empty($args['description'])) {
        echo '<p class="description">' . esc_html($args['description']) . '</p>';
    }
    
    echo '</div>';
}

/**
 * 根据模型获取分类列表（组件专用）
 * 
 * @param mixed $model_id 模型ID或模型键名
 * @param array $args 查询参数
 * @return array
 */
function get_categories_by_model($model_id, $args = array()) {
    global $tododb;
    
    // 如果传入的是字符串，则按模型键名查询
    if (is_string($model_id)) {
        $sql_model = "SELECT * FROM {$tododb->prefix}models WHERE model_key = ?";
        $model = $tododb->get_row($sql_model, [$model_id]);
        if ($model) {
            $model_id = $model->model_id;
        } else {
            return array();
        }
    } else {
        // 获取模型信息
        $sql_model = "SELECT * FROM {$tododb->prefix}models WHERE model_id = ?";
        $model = $tododb->get_row($sql_model, [$model_id]);
    }
    
    if (!$model) {
        return array();
    }
    
    // 默认参数
    $defaults = array(
        'hide_empty' => false,
        'orderby' => 'term_id',
        'order' => 'ASC'
    );
    
    $args = array_merge($defaults, $args);
    
    // 构建查询 - 获取所有分类，标记与该模型关联的分类，并获取分类绑定的模型信息
    $is_admin_context = (defined('IS_ADMIN') && IS_ADMIN);
    $current_site_id = 0;
    if (!$is_admin_context) {
        $current_site_id = function_exists('todo_current_site_id') ? (int) todo_current_site_id(true) : 0;
    } elseif (isset($_GET['site']) && is_numeric($_GET['site']) && (int) $_GET['site'] > 0) {
        $current_site_id = (int) $_GET['site'];
    }
    $site_join = '';
    $site_where = '';
    if (function_exists('todo_site_terms_table_exists') && todo_site_terms_table_exists()) {
        $site_terms_table = $tododb->prefix . 'site_terms';
        if ($current_site_id > 0) {
            $site_join = " LEFT JOIN {$site_terms_table} st ON (t.term_id = st.term_id AND st.site_id = " . intval($current_site_id) . ")";
            $site_where = " AND ((st.term_id IS NOT NULL AND st.is_visible = 1) OR NOT EXISTS (SELECT 1 FROM {$site_terms_table} stx WHERE stx.term_id = t.term_id AND stx.site_id <> 0))";
        }
    }

    // 性能优化：快速获取被标记为回收站(trashed)的分类ID
    static $trashed_term_ids = null;
    if ($trashed_term_ids === null) {
        $trashed_term_ids = [];
        if (function_exists('todo_db_table_exists') && todo_db_table_exists('term_attributes')) {
            $t_rows = $tododb->get_results(
                "SELECT term_id FROM {$tododb->prefix}term_attributes WHERE attributes LIKE '%\"trashed\":\"1\"%' OR attributes LIKE '%\"trashed\":1%'"
            );
            if ($t_rows && is_array($t_rows)) {
                foreach ($t_rows as $tr) {
                    $tr_id = is_object($tr) ? $tr->term_id : $tr['term_id'];
                    $trashed_term_ids[] = (int) $tr_id;
                }
            }
        }
    }

    $trashed_where = '';
    if (!empty($trashed_term_ids)) {
        $trashed_where = " AND t.term_id NOT IN (" . implode(',', array_map('intval', $trashed_term_ids)) . ")";
    }

    $query = "
        SELECT t.*, tt.*,
               tm.model_id as bound_model_id,
               CASE WHEN tm.model_id = ? THEN 1 ELSE 0 END as is_model_category
        FROM {$tododb->prefix}terms t
        {$site_join}
        JOIN {$tododb->prefix}term_taxonomy tt ON t.term_id = tt.term_id
        LEFT JOIN {$tododb->prefix}term_models tm ON t.term_id = tm.term_id
        WHERE tt.taxonomy = ?
        {$site_where}
        {$trashed_where}
    ";
    
    // 处理空分类
    if ($args['hide_empty']) {
        $query .= " AND tt.count > 0";
    }
    
    // 不在SQL层面排序，让PHP的层级构建函数处理排序
    // 只按模型关联性排序，确保关联的分类优先显示
    $query .= " ORDER BY is_model_category DESC";
    
    // 执行查询
    $categories = $tododb->get_results($query, [$model_id, 'category']);
    
    // 内存处理模型名称映射
    $models_map = [];
    if (class_exists('CMS_Model')) {
        $all_models = CMS_Model::get_models();
        if (is_array($all_models)) {
            foreach ($all_models as $m) {
                $m_obj = (object) $m;
                $models_map[(int) $m_obj->model_id] = $m_obj->model_name;
            }
        }
    }

    // 为每个分类添加可用性标记与绑定模型名称
    foreach ($categories as $index => $category) {
        // 确保 $category 是对象
        if (is_array($category)) {
            $category = (object)$category;
        }
        
        $bm_id = (int) ($category->bound_model_id ?? 0);
        $category->bound_model_name = ($bm_id > 0 && isset($models_map[$bm_id])) ? $models_map[$bm_id] : '无模型';
        $category->is_available = ($category->is_model_category == 1);
        
        // 确保name属性存在
        if (!isset($category->name) && isset($category->term_name)) {
            $category->name = $category->term_name;
        }
        // 清理多语言标识，仅展示主语言分类名
        if (is_string($category->name) && $category->name !== '') {
            $clean_name = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($category->name) : $category->name;
            $clean_name = trim(preg_replace('/\[[a-z]{2}(?:-[a-z]{2})?\|.*?\]/is', '', $clean_name));
            $clean_name = trim(preg_replace('/\[:[a-z]{2}(?:-[a-z]{2})?\].*?\[:\]/is', '', $clean_name));
            $category->name = $clean_name ?: $category->name;
        }

        // 更新数组中的对象
        $categories[$index] = $category;
    }
    
    return $categories;
}

/**
 * 构建层级分类结构（组件专用）- 优化版本 (O(N) 复杂度)
 * 
 * @param array $categories 分类数组
 * @param int $parent 父分类ID
 * @param int $level 层级深度
 * @param string $orderby 排序字段
 * @param string $order 排序方向
 * @return array
 */
function build_category_hierarchy_component($categories, $parent = 0, $level = 0, $orderby = 'term_id', $order = 'ASC') {
    // 第一次调用时，建立以 parent_id 为索引的数组
    static $indexed_categories = null;
    if ($level === 0) {
        $indexed_categories = array();
        foreach ($categories as $cat) {
            $p_id = intval($cat->parent);
            if (!isset($indexed_categories[$p_id])) {
                $indexed_categories[$p_id] = array();
            }
            $indexed_categories[$p_id][] = $cat;
        }
    }
    
    if (!isset($indexed_categories[$parent])) {
        if ($level === 0) $indexed_categories = null; // 清理静态变量
        return array();
    }
    
    $current_level_categories = $indexed_categories[$parent];
    
    // 对当前层级的分类进行排序
    usort($current_level_categories, function($a, $b) use ($orderby, $order) {
        $field_a = isset($a->$orderby) ? $a->$orderby : 0;
        $field_b = isset($b->$orderby) ? $b->$orderby : 0;
        
        if ($orderby === 'term_id') {
            $diff = intval($field_a) - intval($field_b);
        } else {
            $diff = (float)$field_a - (float)$field_b;
        }
        
        if ($diff == 0) {
            return strcasecmp($a->name ?? '', $b->name ?? '');
        }
        
        return ($order === 'DESC') ? ($diff > 0 ? -1 : 1) : ($diff > 0 ? 1 : -1);
    });
    
    $result = array();
    foreach ($current_level_categories as $category) {
        $category->level = $level;
        $result[] = $category;
        
        $children = build_category_hierarchy_component($categories, $category->term_id, $level + 1, $orderby, $order);
        if (!empty($children)) {
            foreach ($children as $child) {
                $result[] = $child;
            }
        }
    }
    
    // 根层级执行完毕后清理静态变量
    if ($level === 0) {
        $indexed_categories = null;
    }
    
    return $result;
}

/**
 * 渲染复选框形式的分类选择器
 * 
 * @param array $categories 层级分类数组
 * @param array $args 配置参数
 * @return void
 */
function render_checkbox_categories($categories, $args) {
    if (empty($categories)) {
        echo '<p>没有分类目录。</p>';
        return;
    }
    
    $multiple_attr = $args['multiple'] ? '[]' : '';
    $input_type = $args['multiple'] ? 'checkbox' : 'radio';
    
    echo '<div class="hierarchical-categories-checklist">';
    echo '<ul>';
    
    foreach ($categories as $category) {
        $checked = in_array($category->term_id, $args['selected']) ? 'checked' : '';
        $indent = str_repeat('—', $category->level);
        
        // 检查是否可用（如果启用了模型过滤）
        $disabled = '';
        $class_attr = '';
        $is_available = true;
        
        if (isset($category->is_available) && !$category->is_available) {
            $is_available = false;
            $disabled = 'disabled';
            $class_attr = 'class="unavailable-category"';
        }
        
        // 性能优化：在渲染循环中使用静态局部缓存减少 get_term_model 调用
        static $model_cache = [];
        $tid = intval($category->term_id);
        
        if (!isset($model_cache[$tid])) {
            $boundModel = get_term_model($tid);
            if (is_array($boundModel) && !empty($boundModel['model_id'])) {
                $model_cache[$tid] = [
                    'id' => intval($boundModel['model_id']),
                    'key' => $boundModel['model_key'] ?? ''
                ];
            } else {
                $model_cache[$tid] = ['id' => 0, 'key' => ''];
            }
        }
        
        $category_model_id = $model_cache[$tid]['id'];
        $category_model_key = $model_cache[$tid]['key'];
        
        echo '<li ' . $class_attr . ' data-model-id="' . $category_model_id . '" data-model-key="' . $category_model_key . '">';
        echo '<label>';
        
        if ($is_available) {
            // 可用分类：正常的input元素，添加data-model-id和data-model-key属性
            echo '<input type="' . $input_type . '" name="' . esc_attr($args['name']) . '" value="' . $category->term_id . '" ' . $checked . ' data-model-id="' . $category_model_id . '" data-model-key="' . $category_model_key . '" class="category-input"> ';
        } else {
            // 不可用分类：显示disabled的input用于展示，但不包含在表单提交中
            echo '<input type="' . $input_type . '" value="' . $category->term_id . '" ' . $checked . ' ' . $disabled . ' style="opacity: 0.5;" data-model-id="' . $category_model_id . '" data-model-key="' . $category_model_key . '" class="category-input"> ';
            // 如果该分类原本被选中，添加一个hidden input来保持选中状态
            if ($checked) {
                echo '<input type="hidden" name="' . esc_attr($args['name']) . '" value="' . $category->term_id . '">';
            }
        }
        
        echo '<div' . ($is_available ? '' : ' style="color: #999; font-style: italic;"') . '>';
        $display_cat_name = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($category->name) : $category->name;
        echo '<em style="color: #999;">' . $indent . '</em> ' . esc_html($display_cat_name);
        if (!empty($category->bound_model_name)) {
            echo '<span style="color: #999; font-weight: normal;">·' . esc_html($category->bound_model_name) . '</span>';
        } else {
            echo '<span style="color: #999;">·无模型</span>';
        }
        echo '</div>';
        echo '</label>';
        echo '</li>';
    }
    
    echo '</ul>';
    echo '</div>';
}

/**
 * 渲染下拉选择形式的分类选择器
 * 
 * @param array $categories 层级分类数组
 * @param array $args 配置参数
 * @return void
 */
function render_select_categories($categories, $args) {
    if (empty($categories)) {
        echo '<select name="' . esc_attr($args['name']) . '" id="' . esc_attr($args['id']) . '" disabled>';
        echo '<option value="">没有分类目录</option>';
        echo '</select>';
        return;
    }
    
    $multiple_attr = $args['multiple'] ? 'multiple' : '';
    $class_attr = !empty($args['select_class']) ? 'class="' . esc_attr($args['select_class']) . '"' : '';
    
    echo '<select name="' . esc_attr($args['name']) . '" id="' . esc_attr($args['id']) . '" ' . $multiple_attr . ' ' . $class_attr . '>';
    
    if (!$args['multiple']) {
        echo '<option value="">请选择分类</option>';
    }
    
    foreach ($categories as $category) {
        $selected = in_array($category->term_id, $args['selected']) ? 'selected' : '';
        $indent = str_repeat('—', $category->level);
        
        echo '<option value="' . $category->term_id . '" ' . $selected . '>';
        $display_cat_name = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($category->name) : $category->name;
        echo $indent . esc_html($display_cat_name);
        echo '</option>';
    }
    
    echo '</select>';
}

/**
 * 输出层级分类选择器的CSS样式
 * 
 * @return void
 */
function enqueue_hierarchical_categories_selector_styles() {
    echo get_hierarchical_categories_selector_scripts();
}

/**
 * 获取层级分类选择器的JavaScript脚本
 * 
 * @return string
 */
function get_hierarchical_categories_selector_scripts() {
    return '
    <script type="text/javascript">
    document.addEventListener("DOMContentLoaded", function() {
        // 为分类选择器添加变化检测
        const categoryInputs = document.querySelectorAll(".hierarchical-categories-selector input[type=\"checkbox\"], .hierarchical-categories-selector input[type=\"radio\"]");
        const submitButton = document.querySelector("button[type=\"submit\"]");
        const form = submitButton ? submitButton.closest("form") : null;
        
        // 检查当前页面是否为post-new或post-edit
        const currentUrl = window.location.href;
        const isPostEditPage = currentUrl.includes("page=post-new") || currentUrl.includes("page=post-edit");
        
        // 检查是否启用了同模型限制
        const selectorElement = document.querySelector(".hierarchical-categories-selector");
        const explicitRestriction = selectorElement && selectorElement.dataset.restrictToSameModel === "true";
        
        // 在post-new和post-edit页面自动启用限制，其他页面需要明确设置
        const restrictToSameModel = isPostEditPage || explicitRestriction;
        
        // 同模型限制处理函数
        function handleSameModelRestriction() {
            if (!restrictToSameModel) return;
            
            const checkedInputs = document.querySelectorAll(".hierarchical-categories-selector input.category-input:checked");
            const allInputs = document.querySelectorAll(".hierarchical-categories-selector input.category-input");
            
            if (checkedInputs.length === 0) {
                // 没有选中任何项，恢复所有项为可选状态
                allInputs.forEach(input => {
                    input.disabled = false;
                    input.style.opacity = "1";
                    const li = input.closest("li");
                    if (li) li.style.opacity = "1";
                });
                return;
            }
            
            // 获取已选中项的模型ID
            const selectedModelIds = new Set();
            checkedInputs.forEach(input => {
                const modelId = input.dataset.modelId;
                if (modelId && modelId !== "0") {
                    selectedModelIds.add(modelId);
                }
            });
            
            // 如果选中了多个不同模型的项，只保留第一个模型
            if (selectedModelIds.size > 1) {
                const firstModelId = checkedInputs[0].dataset.modelId;
                checkedInputs.forEach(input => {
                    if (input.dataset.modelId !== firstModelId) {
                        input.checked = false;
                    }
                });
                selectedModelIds.clear();
                selectedModelIds.add(firstModelId);
            }
            
            // 禁用其他模型的项
            allInputs.forEach(input => {
                const inputModelId = input.dataset.modelId;
                const shouldDisable = inputModelId && inputModelId !== "0" && !selectedModelIds.has(inputModelId);
                
                if (shouldDisable) {
                    input.disabled = true;
                    input.style.opacity = "0.5";
                    const li = input.closest("li");
                    if (li) li.style.opacity = "0.5";
                } else {
                    input.disabled = false;
                    input.style.opacity = "1";
                    const li = input.closest("li");
                    if (li) li.style.opacity = "1";
                }
            });
        }
        
        // 为分类输入框添加变化监听
        const categoryInputsForRestriction = document.querySelectorAll(".hierarchical-categories-selector input.category-input");
        categoryInputsForRestriction.forEach(input => {
            input.addEventListener("change", handleSameModelRestriction);
        });
        
        // 初始化时执行一次
        handleSameModelRestriction();

        // 自动滚动到第一个已选中的分类，提升交互友好性
        // 当分类列表较长时，让容器滚动到第一个选中项可见，无需用户手动查找
        document.querySelectorAll(".hierarchical-categories-checklist").forEach(function(container) {
            var firstChecked = container.querySelector("input.category-input:checked");
            if (!firstChecked) return;
            var li = firstChecked.closest("li");
            if (!li) return;
            // 用 getBoundingClientRect() 差值 + scrollTop 计算正确的滚动目标。
            // 原因：li.offsetTop 是相对于 offsetParent 的偏移，而 <ul> 使用 flex
            // 布局且无 position 定位，offsetParent 会越过容器层级导致计算偏差。
            // getBoundingClientRect() 返回视口级绝对坐标，两者之差即 li
            // 在容器当前可视区内的相对位置，叠加 scrollTop 得到正确的滚动偏移量。
            var liRect        = li.getBoundingClientRect();
            var containerRect = container.getBoundingClientRect();
            // li 相对容器可视区顶部的当前位置（已含当前 scrollTop 影响）
            var relativeTop   = liRect.top - containerRect.top;
            // 目标：让选中项在容器内垂直居中
            var scrollTarget  = container.scrollTop + relativeTop
                                - (container.clientHeight / 4)
                                + (li.clientHeight / 2);
            container.scrollTop = scrollTarget > 0 ? scrollTarget : 0;
        });
        
        // 自动绑定模型过滤功能
        const categorySelectors = document.querySelectorAll(".hierarchical-categories-selector[data-model-filter-target]");
        categorySelectors.forEach(selector => {
            const targetId = selector.getAttribute("data-model-filter-target");
            const modelSelect = document.getElementById(targetId);
            
            if (modelSelect) {
                // 监听模型选择器变化
                modelSelect.addEventListener("change", function() {
                    const selectedModel = this.value;
                    window.filterCategoriesByModel(selector, selectedModel);
                });
                
                // 初始化时也触发一次过滤
                if (modelSelect.value) {
                    window.filterCategoriesByModel(selector, modelSelect.value);
                } else {
                    // 没有选择模型时，禁用所有分类
                    window.filterCategoriesByModel(selector, "");
                }
            }
        });
        
        if (categoryInputs.length > 0 && submitButton && form) {
            // 记录初始状态
            const initialState = Array.from(categoryInputs).map(input => ({
                name: input.name,
                value: input.value,
                checked: input.checked
            }));
            
            // 添加隐藏字段来标记分类是否已更改
            let categoryChangedField = form.querySelector("input[name=\"categories_changed\"]");
            if (!categoryChangedField) {
                categoryChangedField = document.createElement("input");
                categoryChangedField.type = "hidden";
                categoryChangedField.name = "categories_changed";
                categoryChangedField.value = "0";
                form.appendChild(categoryChangedField);
            }
            
            // 监听分类选择变化
            categoryInputs.forEach(input => {
                input.addEventListener("change", function() {
                    // 检查是否有变化
                    const currentState = Array.from(categoryInputs).map(inp => ({
                        name: inp.name,
                        value: inp.value,
                        checked: inp.checked
                    }));
                    
                    const hasChanges = !arraysEqual(initialState, currentState);
                    
                    // 更新隐藏字段值
                    categoryChangedField.value = hasChanges ? "1" : "0";
                    
                    if (hasChanges) {
                        // 添加视觉提示
                        submitButton.style.backgroundColor = "#006dbf";
                        submitButton.style.borderColor = "#006dbf";
                        
                        // 添加提示文本
                        let changeHint = document.querySelector(".category-change-hint");
                        if (!changeHint) {
                            changeHint = document.createElement("p");
                            changeHint.className = "category-change-hint";
                            changeHint.style.color = "#0073aa";
                            changeHint.style.fontStyle = "italic";
                            changeHint.style.fontSize = "13px";
                            changeHint.style.lineHeight = "normal";
                            changeHint.textContent = "分类选择已更改，请注意保存更新。";
                            
                            const categorySelector = document.querySelector(".hierarchical-categories-selector");
                            if (categorySelector) {
                                categorySelector.appendChild(changeHint);
                            }
                        }
                    } else {
                        // 移除提示
                        const changeHint = document.querySelector(".category-change-hint");
                        if (changeHint) {
                            changeHint.remove();
                        }
                        
                        // 恢复按钮样式
                        submitButton.style.backgroundColor = "";
                        submitButton.style.borderColor = "";
                    }
                });
            });
        }
        
        // 数组比较函数
        function arraysEqual(a, b) {
            if (a.length !== b.length) return false;
            
            for (let i = 0; i < a.length; i++) {
                if (a[i].name !== b[i].name || a[i].value !== b[i].value || a[i].checked !== b[i].checked) {
                    return false;
                }
            }
            
            return true;
        }
    });
    
    // 全局函数：根据模型过滤分类
    window.filterCategoriesByModel = function(categoryContainer, modelIdentifier) {
        if (!categoryContainer) return;
        
        const categoryItems = categoryContainer.querySelectorAll("li[data-model-id]");
        const categoryInputs = categoryContainer.querySelectorAll("input.category-input");
        
        if (!modelIdentifier || modelIdentifier === "") {
            // 没有选择模型，显示所有分类但禁用
            categoryItems.forEach(item => {
                item.style.display = "list-item";
                const input = item.querySelector("input.category-input");
                if (input) {
                    input.disabled = true;
                    input.style.opacity = "0.5";
                }
                item.style.opacity = "0.5";
            });
            return;
        }
        
        let matchedCount = 0;
        
        // 选择了模型，只显示该模型的分类
        categoryItems.forEach(item => {
            const itemModelId = item.getAttribute("data-model-id");
            const itemModelKey = item.getAttribute("data-model-key");
            const input = item.querySelector("input.category-input");
            
            // 支持通过model_id或model_key匹配
            const isMatch = (itemModelId === modelIdentifier) || (itemModelKey === modelIdentifier);
            
            if (isMatch) {
                // 显示匹配模型的分类
                item.style.display = "list-item";
                item.style.opacity = "1";
                if (input) {
                    input.disabled = false;
                    input.style.opacity = "1";
                }
                matchedCount++;
            } else {
                // 隐藏不匹配模型的分类
                item.style.display = "none";
                if (input) {
                    input.checked = false; // 取消选中
                    input.disabled = true;
                }
            }
        });
        
        // 检查是否有可用的分类
        if (matchedCount === 0) {
            // 没有匹配的分类，显示提示信息
            let noMatchHint = categoryContainer.querySelector(".no-categories-hint");
            if (!noMatchHint) {
                noMatchHint = document.createElement("p");
                noMatchHint.className = "no-categories-hint";
                noMatchHint.style.color = "#999";
                noMatchHint.style.fontStyle = "italic";
                noMatchHint.style.padding = "10px";
                noMatchHint.textContent = "该模型暂无可用分类";
                categoryContainer.appendChild(noMatchHint);
            }
            noMatchHint.style.display = "block";
        } else {
            // 有匹配的分类，隐藏提示信息
            const noMatchHint = categoryContainer.querySelector(".no-categories-hint");
            if (noMatchHint) {
                noMatchHint.style.display = "none";
            }
        }
    };
    
    </script>
    ';
}
?>
