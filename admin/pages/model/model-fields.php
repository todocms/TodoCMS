<?php
// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
/**
 * 模型字段管理页面
 *
 * 提供查看和管理模型字段的功能
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 检查用户权限
if (!current_user_can('edit_models') && !current_user_can('manage_models')) {
    todo_die('您没有足够的权限访问此页面。');
}

// 引入弹窗组件
require_once dirname(__DIR__, 2) . '/components/modal.php';

$page_title = '模型字段管理';

// 获取模型ID
$model_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['model_id']) ? intval($_GET['model_id']) : 0);

if ($model_id <= 0) {
    echo '<div class="notice notice-error is-dismissible"><p>无效的模型ID</p><button type="button" class="notice-dismiss">✕</button></div>';
    return;
}

// 获取模型信息
$model = new CMS_Model($model_id);
$model_data = $model->get_data();

if (empty($model_data)) {
    echo '<div class="notice notice-error is-dismissible"><p>模型不存在</p><button type="button" class="notice-dismiss">✕</button></div>';
    return;
}

// 处理批量操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $bulk_action = sanitize_text_field($_POST['bulk_action']);
    $field_ids = isset($_POST['field_ids']) ? array_map('intval', $_POST['field_ids']) : array();

    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('model_fields_bulk_action', '_todononce')) {
        todo_admin_notice('安全验证失败，请重试。', 'error');
    } elseif (empty($field_ids)) {
        todo_admin_notice('请选择要操作的字段', 'error');
    } else {
        $success_count = 0;
        $error_count = 0;

        foreach ($field_ids as $field_id) {
            // 检查字段是否存在且不是系统字段
            $field_obj = $model->get_field($field_id);
            if (!$field_obj || (isset($field_obj['is_system']) && $field_obj['is_system'])) {
                $error_count++;
                continue;
            }

            switch ($bulk_action) {
                case 'category_only_on':
                    $field_data = $field_obj;
                    $field_data['category_only'] = 1;
                    if (CMS_Model_Field_Methods::update_field($model, $field_id, $field_data)) {
                        $success_count++;
                    } else {
                        $error_count++;
                    }
                    break;

                case 'category_only_off':
                    $field_data = $field_obj;
                    $field_data['category_only'] = 0;
                    if (CMS_Model_Field_Methods::update_field($model, $field_id, $field_data)) {
                        $success_count++;
                    } else {
                        $error_count++;
                    }
                    break;

                case 'delete':
                    if (CMS_Model_Field_Methods::trash_field($model, $field_id)) {
                        $success_count++;
                    } else {
                        $error_count++;
                    }
                    break;

                default:
                    $error_count++;
            }
        }

        // 生成结果消息
        if ($success_count > 0) {
            $msg = "";
            switch ($bulk_action) {
                case 'category_only_on':
                    $msg = "成功设置 {$success_count} 个字段为栏目专属";
                    break;
                case 'category_only_off':
                    $msg = "成功取消 {$success_count} 个字段的栏目专属设置";
                    break;
                case 'delete':
                    $msg = "成功将 {$success_count} 个字段移至回收站";
                    break;
            }
            todo_admin_notice($msg, 'success');
        }

        if ($error_count > 0) {
            todo_admin_notice("操作失败 {$error_count} 个字段" . ($success_count > 0 ? " （{$success_count} 个成功）" : ""), 'error');
        }

        // 刷新页面防止重复提交（PRG）
        todo_redirect(todo_admin_url('index.php?page=model-fields&model_id=' . $model_id));
        exit;
    }
}

// 保存字段排序（POST）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_field_orders') {
    if (!Todo_Security_Manager::verify_request_csrf('model_fields_bulk_action', '_todononce')) {
        todo_admin_notice('安全验证失败，请重试。', 'error');
    } else {
        $orders = isset($_POST['field_order']) && is_array($_POST['field_order']) ? $_POST['field_order'] : array();
        $fullwidths = isset($_POST['layout_full_width']) && is_array($_POST['layout_full_width']) ? $_POST['layout_full_width'] : array();
        if (empty($orders) && empty($fullwidths)) {
            todo_admin_notice('没有需要保存的内容', 'error');
        } else {
            global $tododb;
            if (!isset($tododb)) {
                todo_admin_notice('数据库连接不可用', 'error');
            } else {
                $ok = 0;
                $fail = 0;
                try {
                    $tododb->begin_transaction();
                    $field_ids = array_unique(array_merge(array_keys($orders), array_keys($fullwidths)));
                    foreach ($field_ids as $field_id) {
                        $fid = intval($field_id);
                        if ($fid <= 0) {
                            continue;
                        }
                        
                        $set = [];
                        $params = [];
                        
                        if (array_key_exists($field_id, $orders)) {
                            $set[] = "field_order = ?";
                            $params[] = intval($orders[$field_id]);
                        }

                        if (array_key_exists($field_id, $fullwidths)) {
                            $current = $tododb->get_results(
                                "SELECT field_options FROM " . TABLE_PREFIX . "model_fields WHERE field_id = ? AND model_id = ? AND field_status = 'active' LIMIT 1",
                                array($fid, $model_id)
                            );
                            $raw = '';
                            if (!empty($current)) {
                                $row = $current[0];
                                $raw = is_object($row) ? (string) ($row->field_options ?? '') : (string) ($row['field_options'] ?? '');
                            }

                            $opts = [];
                            if ($raw !== '') {
                                if (function_exists('maybe_unserialize')) {
                                    $decoded = maybe_unserialize($raw);
                                } else {
                                    $decoded = @unserialize($raw);
                                }
                                if (is_array($decoded)) {
                                    $opts = $decoded;
                                } else {
                                    $json = json_decode($raw, true);
                                    if (is_array($json)) {
                                        $opts = $json;
                                    }
                                }
                            }

                            $enabled = !empty($fullwidths[$field_id]) ? 1 : 0;
                            if ($enabled) {
                                if (!isset($opts['layout']) || !is_array($opts['layout'])) {
                                    $opts['layout'] = array();
                                }
                                $opts['layout']['full_width'] = 1;
                            } else {
                                if (isset($opts['layout']) && is_array($opts['layout']) && array_key_exists('full_width', $opts['layout'])) {
                                    unset($opts['layout']['full_width']);
                                    if (empty($opts['layout'])) {
                                        unset($opts['layout']);
                                    }
                                }
                            }

                            $encoded = '';
                            if (!empty($opts)) {
                                $encoded = function_exists('maybe_serialize') ? maybe_serialize($opts) : serialize($opts);
                            }
                            $set[] = "field_options = ?";
                            $params[] = $encoded;
                        }

                        if (empty($set)) {
                            continue;
                        }

                        $params[] = $fid;
                        $params[] = $model_id;
                        $result = $tododb->query(
                            "UPDATE " . TABLE_PREFIX . "model_fields SET " . implode(', ', $set) . " WHERE field_id = ? AND model_id = ? AND field_status = 'active'",
                            $params
                        );
                        if ($result === false) {
                            $fail++;
                        } else {
                            $ok++;
                        }
                    }
                    $tododb->commit();
                } catch (Throwable $e) {
                    try {
                        $tododb->rollback();
                    } catch (Throwable $e2) {
                    }
                    $ok = 0;
                    $fail = max(1, $fail);
                }

                if ($ok > 0) {
                    todo_admin_notice("布局与排序已保存（{$ok}项）", 'success');
                }
                if ($fail > 0) {
                    todo_admin_notice("保存失败（{$fail}项）", 'error');
                }
                todo_redirect(todo_admin_url('index.php?page=model-fields&model_id=' . $model_id));
                exit;
            }
        }
    }
}

// 处理字段删除确认 (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_field_confirm') {
    // 验证CSRF令牌
    if (!Todo_Security_Manager::verify_request_csrf('delete_field', '_todononce')) {
        todo_admin_notice('安全验证失败，请重试。', 'error');
    } else {
        $field_id = isset($_POST['field_id']) ? intval($_POST['field_id']) : 0;

        if ($field_id > 0) {
            $result = $model->trash_field($field_id);

            if ($result) {
                todo_admin_notice('字段已成功移至回收站', 'success');
                todo_redirect(todo_admin_url('index.php?page=model-fields&model_id=' . $model_id));
                exit;
            } else {
                todo_admin_notice('将字段移至回收站失败', 'error');
            }
        }
    }
}

// 获取模型字段
$fields = $model->get_fields();
// 统一字段访问器，兼容数组/对象
$todo_field = function ($field, $key, $default = null) {
    if (is_array($field)) {
        return array_key_exists($key, $field) ? $field[$key] : $default;
    }
    return isset($field->$key) ? $field->$key : $default;
};

$field_visible_category_counts = [];
$field_visible_categories = [];
if (!empty($fields)) {
    global $tododb;
    if (isset($tododb)) {
        $field_ids = [];
        foreach ($fields as $f) {
            $fid = intval($todo_field($f, 'field_id', 0));
            if ($fid > 0) {
                $field_ids[] = $fid;
            }
        }
        if (!empty($field_ids)) {
            $placeholders = implode(',', array_fill(0, count($field_ids), '?'));
            $rows = $tododb->get_results(
                "SELECT field_id, COUNT(*) AS cnt FROM " . TABLE_PREFIX . "term_field_visibility WHERE field_id IN (" . $placeholders . ") AND is_visible = 1 GROUP BY field_id",
                $field_ids
            );
            if (!empty($rows)) {
                foreach ($rows as $row) {
                    $row_field_id = is_array($row) ? intval($row['field_id'] ?? 0) : intval($row->field_id ?? 0);
                    $row_cnt = is_array($row) ? intval($row['cnt'] ?? 0) : intval($row->cnt ?? 0);
                    if ($row_field_id > 0) {
                        $field_visible_category_counts[$row_field_id] = $row_cnt;
                    }
                }
            }

            $rows = $tododb->get_results(
                "SELECT tfv.field_id, t.term_id, t.name
                 FROM " . TABLE_PREFIX . "term_field_visibility tfv
                 INNER JOIN " . TABLE_PREFIX . "term_taxonomy tt ON tt.term_id = tfv.term_id AND tt.taxonomy = 'category'
                 INNER JOIN " . TABLE_PREFIX . "terms t ON t.term_id = tfv.term_id
                 WHERE tfv.field_id IN (" . $placeholders . ") AND tfv.is_visible = 1
                 ORDER BY t.term_id ASC",
                $field_ids
            );
            if (!empty($rows)) {
                foreach ($rows as $row) {
                    $row_field_id = is_array($row) ? intval($row['field_id'] ?? 0) : intval($row->field_id ?? 0);
                    $row_term_id = is_array($row) ? intval($row['term_id'] ?? 0) : intval($row->term_id ?? 0);
                    $row_name = is_array($row) ? (string) ($row['name'] ?? '') : (string) ($row->name ?? '');
                    if ($row_field_id > 0 && $row_term_id > 0 && $row_name !== '') {
                        if (!isset($field_visible_categories[$row_field_id])) {
                            $field_visible_categories[$row_field_id] = array();
                        }
                        $field_visible_categories[$row_field_id][] = array(
                            'term_id' => $row_term_id,
                            'name' => $row_name,
                        );
                    }
                }
            }
        }
    }
}

// 获取排序参数
$orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'created_at';
if ($orderby === 'field_order') {
    $orderby = 'created_at';
}
$allowed_orderby = ['created_at', 'field_name', 'field_key', 'field_type'];
if (!in_array($orderby, $allowed_orderby, true)) {
    $orderby = 'created_at';
}

$order = isset($_GET['order']) ? strtoupper(sanitize_text_field($_GET['order'])) : 'DESC';
$order = $order === 'ASC' ? 'ASC' : 'DESC';

// 获取搜索参数
$s = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

// 如果有搜索条件，过滤字段
if (!empty($s)) {
    $filtered_fields = array();
    foreach ($fields as $field) {
        $name = $todo_field($field, 'field_name', '');
        $key = $todo_field($field, 'field_key', '');
        $type = $todo_field($field, 'field_type', '');
        $desc = $todo_field($field, 'field_description', '');
        if (
            stripos($name, $s) !== false ||
            stripos($key, $s) !== false ||
            stripos($type, $s) !== false ||
            stripos($desc, $s) !== false
        ) {
            $filtered_fields[] = $field;
        }
    }
    $fields = $filtered_fields;
}

// 排序字段
if (!empty($orderby) && in_array($orderby, $allowed_orderby, true)) {
    usort($fields, function ($a, $b) use ($orderby, $order, $todo_field) {
        $aVal = $todo_field($a, $orderby, '');
        $bVal = $todo_field($b, $orderby, '');
        if ($order === 'DESC') {
            return $bVal <=> $aVal;
        } else {
            return $aVal <=> $bVal;
        }
    });
}

// 处理批量操作统计信息（PRG模式传参）
$bulk_success = isset($_GET['bulk_success']) ? intval($_GET['bulk_success']) : null;
$bulk_error = isset($_GET['bulk_error']) ? intval($_GET['bulk_error']) : null;
$bulk_action = isset($_GET['bulk_action']) ? sanitize_text_field($_GET['bulk_action']) : '';
if ($bulk_success !== null || $bulk_error !== null) {
    if (($bulk_success ?? 0) > 0) {
        if ($bulk_action === 'delete') {
            todo_admin_notice("成功将 {$bulk_success} 个字段移至回收站", 'success');
        } elseif ($bulk_action === 'category_only_on') {
            todo_admin_notice("成功设置 {$bulk_success} 个字段为栏目专属", 'success');
        } elseif ($bulk_action === 'category_only_off') {
            todo_admin_notice("成功取消 {$bulk_success} 个字段的栏目专属设置", 'success');
        }
    }
    if (($bulk_error ?? 0) > 0) {
        todo_admin_notice("操作失败 {$bulk_error} 个字段" . ((($bulk_success ?? 0) > 0) ? "（{$bulk_success} 个成功）" : ""), 'error');
    }
}
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title"><?php echo esc_html($model_data['model_name'] ?? ''); ?> - 字段管理</h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=models'); ?>" class="button button-secondary">返回模型列表</a>
            <a href="<?php echo todo_admin_url('index.php?page=models-add-field&model_id=' . $model_id); ?>"
                class="button button-primary">添加字段</a>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <h3 class="admin-card-title">字段列表</h3>
            <div class="admin-card-actions">
                <form method="get" class="search-form todo-request">
                    <input type="hidden" name="page" value="model-fields">
                    <input type="hidden" name="model_id" value="<?php echo $model_id; ?>">
                    <input type="search" name="s" value="<?php echo esc_attr($s); ?>" placeholder="搜索字段...">
                    <button type="submit" class="button button-secondary">搜索</button>
                </form>
            </div>
        </div>

        <div class="admin-card-content">
            <div class="bulk-actions-bar">
                <form method="post" id="fields-form" action="<?php echo todo_admin_url('index.php?page=model-fields&model_id=' . $model_id); ?>" class="todo-request">
                    <input type="hidden" name="page" value="model-fields">
                    <input type="hidden" name="model_id" value="<?php echo $model_id; ?>">
                    <?php echo Todo_Security_Manager::csrf_field('model_fields_bulk_action', '_todononce'); ?>

                    <div class="bulk-actions">
                        <select name="bulk_action" id="bulk-action-selector" style="min-width: 140px;">
                            <option value="">批量操作</option>
                            <option value="category_only_on">栏目专属：是</option>
                            <option value="category_only_off">栏目专属：否</option>
                            <option value="delete">移至回收站</option>
                        </select>
                        <button type="button" id="apply-bulk-action" class="button button-secondary">应用</button>
                    </div>
                </form>

                <form method="get" class="todo-request" style="margin-right: auto;">
                    <input type="hidden" name="page" value="model-fields">
                    <input type="hidden" name="model_id" value="<?php echo $model_id; ?>">
                    <div class="bulk-actions">
                        <select name="orderby" style="min-width: 90px;">
                            <option value="created_at" <?php selected($orderby, 'created_at'); ?>>按创建日期</option>
                            <option value="field_name" <?php selected($orderby, 'field_name'); ?>>按名称</option>
                            <option value="field_key" <?php selected($orderby, 'field_key'); ?>>按标识</option>
                            <option value="field_type" <?php selected($orderby, 'field_type'); ?>>按类型</option>
                        </select>

                        <select name="order" style="min-width: 90px;">
                            <option value="ASC" <?php selected($order, 'ASC'); ?>>升序</option>
                            <option value="DESC" <?php selected($order, 'DESC'); ?>>降序</option>
                        </select>

                        <button type="submit" class="button button-secondary">排序</button>
                    </div>
                </form>
            </div>

            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="check-column"><input type="checkbox" id="select-all-fields"></th>
                        <th style="width: 130px; min-width: 130px;">字段名称</th>
                        <th>字段标识</th>
                        <th>字段类型</th>
                        <th style="width: 200px; min-width: 200px;">字段描述</th>
                        <th style="width: 160px; min-width: 160px;">关联分类</th>
                        <th>专属</th>
                        <th style="width: 60px;">通栏</th>
                        <th style="width: 90px;">排序</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($fields)): ?>
                        <?php foreach ($fields as $field): ?>
                            <?php
                            $field_name = $todo_field($field, 'field_name', '(无名称)');
                            $can_edit_field = current_user_can('edit_models') || current_user_can('manage_models');
                            $can_delete_field = (current_user_can('edit_models') || current_user_can('manage_models')) && !($todo_field($field, 'is_system', false));

                            // 行操作
                            $actions = array();

                            if ($can_edit_field) {
                                $actions['edit'] = sprintf(
                                    '<a href="%s">编辑</a>',
                                    todo_admin_url('index.php?page=models-edit-field&model_id=' . $model_id . '&field_id=' . $todo_field($field, 'field_id', 0))
                                );
                            }

                            if ($can_delete_field) {
                                $actions['delete'] = sprintf(
                                    '<a href="javascript:void(0);" class="submitdelete js-trash-model-field" data-field-id="%s" data-field-name="%s">移至回收站</a>',
                                    esc_attr($todo_field($field, 'field_id', 0)),
                                    esc_attr($field_name)
                                );
                            }

                            $row_actions = '';
                            foreach ($actions as $action => $link) {
                                $row_actions .= '<span class="' . $action . '">' . $link . '</span> | ';
                            }
                            $row_actions = rtrim($row_actions, ' | ');
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="field_ids[]"
                                        value="<?php echo $todo_field($field, 'field_id', 0); ?>" class="field-checkbox"
                                        <?php echo $todo_field($field, 'is_system', false) ? 'disabled' : ''; ?>>
                                </td>
                                <td>
                                    <?php if ($can_edit_field): ?>
                                        <a href="<?php echo todo_admin_url('index.php?page=models-edit-field&model_id=' . $model_id . '&field_id=' . $todo_field($field, 'field_id', 0)); ?>"
                                            class="row-title">
                                            <?php echo esc_html($field_name); ?>
                                        </a>
                                    <?php else: ?>
                                        <strong><?php echo esc_html($field_name); ?></strong>
                                    <?php endif; ?>

                                    <div class="row-actions">
                                        <?php echo $row_actions; ?>
                                    </div>
                                </td>
                                <td><?php echo esc_html($todo_field($field, 'field_key', '')); ?></td>
                                <td><?php echo esc_html($todo_field($field, 'field_type', '')); ?></td>
                                <td><?php echo esc_html($todo_field($field, 'field_description', '')); ?></td>
                                <td>
                                    <?php
                                    $field_id = intval($todo_field($field, 'field_id', 0));
                                    $cats = $field_id > 0 ? ($field_visible_categories[$field_id] ?? array()) : array();
                                    if (empty($cats)) {
                                        echo '<span class="tb-meta">无</span>';
                                    } else {
                                        $cat_names = array();
                                        foreach ($cats as $c) {
                                            $cat_names[] = (string) ($c['name'] ?? '');
                                        }
                                        $cat_names = array_values(array_filter($cat_names, function ($n) {
                                            return is_string($n) && $n !== '';
                                        }));
                                        $title = implode('、', $cat_names);

                                        $cat_links = array();
                                        $shown = array_slice($cats, 0, 3);
                                        foreach ($shown as $c) {
                                            $tid = intval($c['term_id'] ?? 0);
                                            $tname = (string) ($c['name'] ?? '');
                                            if ($tid > 0 && $tname !== '') {
                                                $cat_links[] = '<a href="' . todo_admin_url('index.php?page=categories&action=edit&id=' . $tid) . '">' . esc_html($tname) . '</a>';
                                            }
                                        }
                                        $more = count($cats) - count($shown);
                                        $suffix = $more > 0 ? ' 等' . intval(count($cats)) . '个' : '';
                                        echo '<span class="tb-meta" title="' . esc_attr($title) . '">' . implode('、', $cat_links) . esc_html($suffix) . '</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $has_bound_categories = $field_id > 0 && !empty($field_visible_category_counts[$field_id]);
                                    ?>
                                    <?php if (!$has_bound_categories): ?>
                                        <span class="tb-meta">无</span>
                                    <?php elseif ($todo_field($field, 'category_only', true)): ?>
                                        <span class="tb-meta"><i class="icon todofont todo-FolderClose"></i> 栏目</span>
                                    <?php else: ?>
                                        <span class="tb-meta"><i class="icon todofont todo-FileCodeOne"></i> 内容</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $fullwidth_enabled = false;
                                    $raw_opts = $todo_field($field, 'field_options', '');
                                    $opts = [];
                                    if (is_string($raw_opts) && $raw_opts !== '') {
                                        if (function_exists('maybe_unserialize')) {
                                            $decoded = maybe_unserialize($raw_opts);
                                        } else {
                                            $decoded = @unserialize($raw_opts);
                                        }
                                        if (is_array($decoded)) {
                                            $opts = $decoded;
                                        } else {
                                            $json = json_decode($raw_opts, true);
                                            if (is_array($json)) {
                                                $opts = $json;
                                            }
                                        }
                                    } elseif (is_array($raw_opts)) {
                                        $opts = $raw_opts;
                                    }
                                    if (isset($opts['layout']) && is_array($opts['layout']) && !empty($opts['layout']['full_width'])) {
                                        $fullwidth_enabled = true;
                                    } elseif ($todo_field($field, 'field_type', '') === 'editor') {
                                        $fullwidth_enabled = true;
                                    }
                                    ?>
                                    <a href="javascript:void(0);" class="status-toggle status-<?php echo $fullwidth_enabled ? 'active' : 'inactive'; ?> js-toggle-fullwidth" data-field-id="<?php echo esc_attr($field_id); ?>" data-full-width="<?php echo $fullwidth_enabled ? '1' : '0'; ?>">
                                        <?php echo $fullwidth_enabled ? '是' : '否'; ?>
                                    </a>
                                </td>
                                <td>
                                    <input type="number" class="regular-text js-field-order" style="min-width: auto; width: 60px;" data-field-id="<?php echo esc_attr($field_id); ?>" value="<?php echo esc_attr(intval($todo_field($field, 'field_order', 0))); ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="empty">
                            <td colspan="9">
                                <div class="no-items">
                                    <p>暂无字段。</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="bulk-actions-bar" style="margin: 20px 0 0;">
                <div class="bulk-actions m-l-auto">
                    <button type="button" id="save-field-order" class="button button-primary">保存布局与排序</button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* 模型字段页面特定样式 */
    .admin-tablenav {
        display: flex;
        justify-content: space-between;
        margin-bottom: 10px;
    }

    .admin-filters {
        display: flex;
        gap: 5px;
        align-items: center;
    }

    .admin-search {
        display: flex;
        gap: 5px;
        align-items: center;
    }

    .admin-fields-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }

    .admin-fields-table th,
    .admin-fields-table td {
        padding: 8px 10px;
        text-align: left;
        border-bottom: 1px solid #ddd;
    }

    .admin-fields-table th {
        background-color: #f5f5f5;
        font-weight: 500;
    }

    .admin-fields-table tr:hover {
        background-color: #f9f9f9;
    }

    .column-name {
        width: 20%;
    }

    .column-key {
        width: 15%;
    }

    .column-type {
        width: 15%;
    }

    .column-description {
        width: 25%;
    }

    .column-required {
        width: 10%;
        text-align: center;
    }

    .column-actions {
        width: 15%;
        text-align: right;
    }

    .status-toggle {
        display: inline-block;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .status-toggle:hover {
        opacity: 0.8;
    }
</style>

<script type="text/javascript">
    (function () {
        function getCsrfToken() {
            var field = document.querySelector('input[name="_todononce"]');
            return field ? field.value : '';
        }

        function getSelectedIds() {
            var ids = [];
            document.querySelectorAll('.field-checkbox:checked').forEach(function (cb) {
                ids.push(cb.value);
            });
            return ids;
        }

        function toggleSelectAll(checked) {
            document.querySelectorAll('.field-checkbox').forEach(function (cb) {
                if (!cb.disabled) cb.checked = checked;
            });
        }

        var selectAllTop = document.getElementById('select-all-fields');
        if (selectAllTop) {
            selectAllTop.addEventListener('change', function () {
                toggleSelectAll(this.checked);
            });
        }

        var applyBtn = document.getElementById('apply-bulk-action');
        if (applyBtn) {
            applyBtn.addEventListener('click', function () {
                var action = document.getElementById('bulk-action-selector').value;
                var ids = getSelectedIds();

                if (!action) {
                    TodoCMS.Utils.showMessage('请选择批量操作', 'warning');
                    return;
                }

                if (ids.length === 0) {
                    TodoCMS.Utils.showMessage('请勾选需要批量操作的字段', 'warning');
                    return;
                }

                var submitForm = function () {
                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '<?php echo todo_admin_url('index.php?page=model-fields&model_id=' . $model_id); ?>';
                    form.className = 'todo-request';

                    var actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'bulk_action';
                    actionInput.value = action;
                    form.appendChild(actionInput);

                    var nonceInput = document.createElement('input');
                    nonceInput.type = 'hidden';
                    nonceInput.name = '_todononce';
                    nonceInput.value = getCsrfToken();
                    form.appendChild(nonceInput);

                    ids.forEach(function (id) {
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'field_ids[]';
                        input.value = id;
                        form.appendChild(input);
                    });

                    document.body.appendChild(form);
                    form.submit();
                };

                if (action === 'delete') {
                    TodoModal.deleteConfirm({
                        message: '确定将选中的字段移至回收站吗？',
                        onConfirm: submitForm
                    });
                } else {
                    submitForm();
                }
            });
        }

        var saveOrderBtn = document.getElementById('save-field-order');
        if (saveOrderBtn) {
            saveOrderBtn.addEventListener('click', function () {
                var orderInputs = document.querySelectorAll('input.js-field-order');
                if (!orderInputs || orderInputs.length === 0) {
                    TodoCMS.Utils.showMessage('没有可保存的排序项', 'warning');
                    return;
                }

                var existingForm = saveOrderBtn.closest ? saveOrderBtn.closest('form') : null;
                var form = existingForm || document.createElement('form');
                form.method = 'POST';
                form.action = '<?php echo todo_admin_url('index.php?page=model-fields&model_id=' . $model_id); ?>';
                form.className = 'todo-request';

                form.querySelectorAll('input.js-generated-order').forEach(function (el) {
                    el.parentNode && el.parentNode.removeChild(el);
                });

                var actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'update_field_orders';
                actionInput.className = 'js-generated-order';
                form.appendChild(actionInput);

                if (!form.querySelector('input[name="_todononce"]')) {
                    var nonceInput = document.createElement('input');
                    nonceInput.type = 'hidden';
                    nonceInput.name = '_todononce';
                    nonceInput.value = getCsrfToken();
                    nonceInput.className = 'js-generated-order';
                    form.appendChild(nonceInput);
                }

                orderInputs.forEach(function (inp) {
                    var fid = inp.getAttribute('data-field-id') || '';
                    if (!fid) return;
                    var v = parseInt(inp.value, 10);
                    if (isNaN(v)) v = 0;
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'field_order[' + fid + ']';
                    hidden.value = String(v);
                    hidden.className = 'js-generated-order';
                    form.appendChild(hidden);
                });

                var fwToggles = document.querySelectorAll('.js-toggle-fullwidth');
                fwToggles.forEach(function (el) {
                    var fid = el.getAttribute('data-field-id') || '';
                    if (!fid) return;
                    var v = el.getAttribute('data-full-width') || '0';
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'layout_full_width[' + fid + ']';
                    hidden.value = (v === '1') ? '1' : '0';
                    hidden.className = 'js-generated-order';
                    form.appendChild(hidden);
                });

                if (!existingForm) {
                    document.body.appendChild(form);
                }
                form.submit();
            });
        }

        document.addEventListener('click', function (e) {
            var toggle = e.target && e.target.closest ? e.target.closest('a.js-toggle-fullwidth') : null;
            if (!toggle) return;
            e.preventDefault();
            e.stopPropagation();
            var current = toggle.getAttribute('data-full-width') === '1';
            var next = !current;
            toggle.setAttribute('data-full-width', next ? '1' : '0');
            toggle.classList.toggle('status-active', next);
            toggle.classList.toggle('status-inactive', !next);
            toggle.textContent = next ? '是' : '否';
        }, true);

        var deleteFieldNonce = '<?php echo esc_js(todo_create_nonce('delete_field')); ?>';
        document.addEventListener('click', function (e) {
            var link = e.target && e.target.closest ? e.target.closest('a.js-trash-model-field') : null;
            if (!link) return;

            e.preventDefault();
            e.stopPropagation();

            var fieldId = link.getAttribute('data-field-id') || '';
            var fieldName = link.getAttribute('data-field-name') || '';
            if (!fieldId) return;

            var submitForm = function () {
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = '<?php echo todo_admin_url('index.php?page=model-fields&model_id=' . $model_id); ?>';
                form.className = 'todo-request';

                var actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_field_confirm';
                form.appendChild(actionInput);

                var idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'field_id';
                idInput.value = fieldId;
                form.appendChild(idInput);

                var nonceInput = document.createElement('input');
                nonceInput.type = 'hidden';
                nonceInput.name = '_todononce';
                nonceInput.value = deleteFieldNonce;
                form.appendChild(nonceInput);

                document.body.appendChild(form);
                form.submit();
            };

            if (window.TodoModal && typeof window.TodoModal.deleteConfirm === 'function') {
                TodoModal.deleteConfirm({
                    title: '将字段移至回收站',
                    message: fieldName ? ('确定将字段 \"' + fieldName + '\" 移至回收站吗？') : '确定将该字段移至回收站吗？',
                    itemInfo: fieldName ? { '字段名称': fieldName } : null,
                    confirmText: '移至回收站',
                    onConfirm: submitForm
                });
                return;
            }

            if (window.confirm(fieldName ? ('确定将字段 \"' + fieldName + '\" 移至回收站吗？') : '确定将该字段移至回收站吗？')) {
                submitForm();
            }
        }, true);
    })();
</script>
