<?php
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

if (!session_id()) {
    session_start();
}

global $tododb;

$log_table = TABLE_PREFIX . 'search_logs';
$hot_table = TABLE_PREFIX . 'search_hot';

// ========== 加载搜索配置 ==========
$search_config_raw = function_exists('todo_get_option') ? todo_get_option('search_config', '') : '';
$search_config     = !empty($search_config_raw) ? json_decode($search_config_raw, true) : [];
if (!is_array($search_config)) $search_config = [];

// 默认值
$defaults = [
    'search_mode'     => 'smart',
    'segment_match'   => 'any',
    'max_results'     => 0,
    'rate_limit'      => 60,
    'rate_max'        => 5,
    'lock_duration'   => 120,
    'blocked_cats'    => [],
    'sensitive_words' => ['eval', 'call_user_func_array', 'file_put_contents', 'phpinfo', 'shell_exec'],
];
foreach ($defaults as $k => $v) {
    if (!isset($search_config[$k])) $search_config[$k] = $v;
}

// ========== 确定当前 tab ==========
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'hot';

// ========== 处理热搜开关 ==========
if (isset($_GET['action']) && $_GET['action'] === 'toggle_hot' && isset($_GET['id']) && isset($_GET['_todononce'])) {
    $id    = (int)$_GET['id'];
    $nonce = $_GET['_todononce'];
    if (function_exists('todo_verify_nonce') && todo_verify_nonce($nonce, 'toggle_hot_' . $id)) {
        if ($tododb->table_exists($hot_table)) {
            $current = $tododb->get_var("SELECT is_hot FROM {$hot_table} WHERE id = ?", [$id]);
            $new_val = $current ? 0 : 1;
            $tododb->update($hot_table, ['is_hot' => $new_val], ['id' => $id]);
        }
    }
    if ($new_val) {
        echo '<div class="notice notice-success"><p>已设为热搜</p></div>';
    } else {
        echo '<div class="notice notice-info"><p>已取消热搜</p></div>';
    }
}

// ========== 处理清空日志 ==========
if (isset($_GET['action']) && $_GET['action'] === 'clear' && isset($_GET['_todononce'])) {
    $nonce = $_GET['_todononce'];
    if (function_exists('todo_verify_nonce') && todo_verify_nonce($nonce, 'clear_search_logs')) {
        if ($tododb->table_exists($log_table)) {
            $tododb->query("TRUNCATE TABLE {$log_table}");
        }
        if ($tododb->table_exists($hot_table)) {
            $tododb->query("TRUNCATE TABLE {$hot_table}");
        }
        echo '<div class="notice notice-success"><p>搜索记录已清空！</p></div>';
    }
}

// ========== 处理配置保存 ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_search_config'])) {
    // 优先使用更强大的 verify_request_csrf
    $csrf_ok = false;
    if (class_exists('Todo_Security_Manager') && method_exists('Todo_Security_Manager', 'verify_request_csrf')) {
        $csrf_ok = Todo_Security_Manager::verify_request_csrf('save_search_config', '_todononce');
    } elseif (function_exists('todo_verify_nonce') && isset($_POST['_todononce'])) {
        $csrf_ok = todo_verify_nonce($_POST['_todononce'], 'save_search_config');
    }

    if ($csrf_ok) {
        $search_config['search_mode']     = in_array($_POST['search_mode'] ?? '', ['smart', 'exact', 'segment'], true) ? $_POST['search_mode'] : 'smart';
        $search_config['segment_match']   = in_array($_POST['segment_match'] ?? '', ['any', 'all'], true) ? $_POST['segment_match'] : 'any';
        $search_config['max_results']     = max(0, (int)($_POST['max_results'] ?? 0));
        $search_config['rate_limit']      = max(1, (int)($_POST['rate_limit'] ?? 60));
        $search_config['rate_max']        = max(1, (int)($_POST['rate_max'] ?? 5));
        $search_config['lock_duration']   = max(10, (int)($_POST['lock_duration'] ?? 120));
        $search_config['blocked_cats']    = isset($_POST['blocked_cats']) && is_array($_POST['blocked_cats']) ? array_filter(array_map('intval', $_POST['blocked_cats'])) : [];
        $sw_raw                             = trim($_POST['sensitive_words'] ?? '');
        $search_config['sensitive_words'] = $sw_raw !== '' ? array_values(array_unique(array_filter(array_map('trim', explode("\n", $sw_raw))))) : [];

        // 同步更新系统配置表与选项表，确保前后端完全一致
        $sc_json = json_encode($search_config);
        if (function_exists('todo_set_option')) {
            todo_set_option('search_config', $sc_json);
        }
        if (function_exists('update_option')) {
            update_option('search_config', $sc_json);
        }
        
        // 强制刷新当前页面的配置变量
        $search_config_raw = json_encode($search_config);
        
        echo '<div class="notice notice-success"><p>搜索配置已保存！</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>安全验证失败，请刷新页面重试！</p></div>';
    }
}

// ========== 热搜数据 ==========
$hot_per_page = 30;
$hot_page     = max(1, intval($_GET['paged'] ?? 1));
$hot_offset   = ($hot_page - 1) * $hot_per_page;

$hot_total = 0;
$hot_items = [];
if ($tododb->table_exists($hot_table)) {
    $hot_total  = (int) $tododb->get_var("SELECT COUNT(*) FROM {$hot_table}");
    $hot_items  = $tododb->get_results("SELECT * FROM {$hot_table} ORDER BY is_hot DESC, search_count DESC LIMIT {$hot_per_page} OFFSET {$hot_offset}") ?: [];
}

// ========== 明细日志分页 ==========
$per_page   = 30;
$page       = max(1, intval($_GET['paged'] ?? 1));
$offset     = ($page - 1) * $per_page;

$total       = 0;
$logs        = [];
$total_pages = 1;

if ($tododb->table_exists($log_table)) {
    $total       = (int) $tododb->get_var("SELECT COUNT(*) FROM {$log_table}");
    $logs        = $tododb->get_results("SELECT * FROM {$log_table} ORDER BY created_at DESC LIMIT {$per_page} OFFSET {$offset}");
    $total_pages = max(1, ceil($total / $per_page));
}

// ========== 栏目列表（用于屏蔽栏目选择） ==========
// 已改用 render_hierarchical_categories_selector 统一组件

$sensitive_words_str = implode("\n", $search_config['sensitive_words']);
$nonce_save = function_exists('todo_create_nonce') ? todo_create_nonce('save_search_config') : '';
$clear_nonce = function_exists('todo_create_nonce') ? todo_create_nonce('clear_search_logs') : '';
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title verse">"知彼知己，百战不殆。"<span>——《孙子兵法》</span></h2>
        <div class="admin-page-actions">
            <a href="?page=tags" class="button button-secondary">返回Tags</a>
        </div>
    </div>

    <!-- ============ Tabs 导航 ============ -->
    <ul class="admin-tabs">
        <li>
            <a href="?page=search-logs&tab=hot" class="<?php echo $current_tab === 'hot' ? 'active' : ''; ?>">
                热搜管理
            </a>
        </li>
        <li>
            <a href="?page=search-logs&tab=config" class="<?php echo $current_tab === 'config' ? 'active' : ''; ?>">
                搜索配置
            </a>
        </li>
        <li>
            <a href="?page=search-logs&tab=logs" class="<?php echo $current_tab === 'logs' ? 'active' : ''; ?>">
                搜索记录
            </a>
        </li>
    </ul>

    <!-- ============ Tab: 热搜管理 ============ -->
    <div class="tab-panel" id="tab-hot" style="<?php echo $current_tab === 'hot' ? '' : 'display:none;'; ?>">
        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">热搜管理</h3>
                <span class="description m-0">
                    标记为「热搜」的关键词将在前台搜索框下拉中优先推荐
                </span>
            </div>
            <div class="admin-card-content">
                <?php if (!empty($hot_items)): ?>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th>关键词</th>
                            <th style="width: 90px;">搜索次数</th>
                            <th style="width: 90px;">结果数</th>
                            <th style="width: 90px;">热搜状态</th>
                            <th style="width: 100px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hot_items as $item):
                            $id     = is_object($item) ? (int)$item->id : (int)($item['id'] ?? 0);
                            $kw     = is_object($item) ? (string)($item->keyword ?? '') : (string)($item['keyword'] ?? '');
                            $cnt    = is_object($item) ? (int)($item->search_count ?? 0) : (int)($item['search_count'] ?? 0);
                            $rc     = is_object($item) ? (int)($item->results_count ?? 0) : (int)($item['results_count'] ?? 0);
                            $is_hot = is_object($item) ? (int)($item->is_hot ?? 0) : (int)($item['is_hot'] ?? 0);
                            $tn_nonce  = function_exists('todo_create_nonce') ? todo_create_nonce('toggle_hot_' . $id) : '';
                        ?>
                        <tr>
                            <td style="text-align: center; color: #999;"><?php echo $id; ?></td>
                            <td><strong<?php echo $is_hot ? ' style="color: #f60;"' : ''; ?>><?php echo esc_html($kw); ?></strong></td>
                            <td style="text-align: center;"><?php echo $cnt; ?></td>
                            <td style="text-align: center;"><?php echo $rc; ?></td>
                            <td style="text-align: center;">
                                <?php if ($is_hot): ?>
                                    <span class="tb-meta" style="color: #f57c00;">&starf; 热搜</span>
                                <?php else: ?>
                                    <span class="tb-meta">普通</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <a href="?page=search-logs&tab=hot&action=toggle_hot&id=<?php echo $id; ?>&_todononce=<?php echo $tn_nonce; ?>"
                                   class="btn <?php echo $is_hot ? 'button-secondary' : 'button-primary'; ?>">
                                    <?php echo $is_hot ? '取消热搜' : '设为热搜'; ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php
                echo todo_render_pagination(array(
                    'total_items'  => $hot_total,
                    'per_page'     => $hot_per_page,
                    'current_page' => $hot_page,
                    'base_url'     => todo_admin_url('index.php?page=search-logs'),
                    'add_args'     => array('tab' => 'hot'),
                ));
                ?>
                <?php else: ?>
                <div class="no-items">
                    <p>暂无搜索数据，用户在前台搜索后自动出现。</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ============ Tab: 搜索配置 ============ -->
    <div class="tab-panel" id="tab-config" style="<?php echo $current_tab === 'config' ? '' : 'display:none;'; ?>">
        <form method="post" action="?page=search-logs&tab=config">
            <?php if (function_exists('Todo_Security_Manager') && method_exists('Todo_Security_Manager', 'csrf_field')) {
                echo Todo_Security_Manager::csrf_field('save_search_config');
            } else {
                echo '<input type="hidden" name="_todononce" value="' . esc_html($nonce_save) . '">';
            } ?>
            <input type="hidden" name="save_search_config" value="1">

            <!-- 搜索模式 -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">搜索模式</h3>
                </div>
                <div class="admin-card-content">
                    <table class="form-table">
                        <tr>
                            <th>搜索模式</th>
                            <td>
                                <label>
                                    <input type="radio" name="search_mode" value="smart" <?php echo $search_config['search_mode'] === 'smart' ? 'checked' : ''; ?>>
                                    智能模糊
                                </label>
                                <p class="description m-b-10">自动处理关键词，支持部分匹配和拼音搜索</p>
                                <label>
                                    <input type="radio" name="search_mode" value="exact" <?php echo $search_config['search_mode'] === 'exact' ? 'checked' : ''; ?>>
                                    标题完全匹配
                                </label>
                                <p class="description m-b-10">精确匹配标题，性能最佳</p>
                                <label>
                                    <input type="radio" name="search_mode" value="segment" <?php echo $search_config['search_mode'] === 'segment' ? 'checked' : ''; ?>>
                                    标题分词
                                </label>
                                <p class="description">将搜索词拆分为多个词，分别匹配</p>
                            </td>
                        </tr>
                        <tr id="segment-match-row" style="<?php echo $search_config['search_mode'] !== 'segment' ? 'display:none;' : ''; ?>">
                            <th>分词匹配</th>
                            <td>
                                <label>
                                    <input type="radio" name="segment_match" value="any" <?php echo $search_config['segment_match'] === 'any' ? 'checked' : ''; ?>>
                                    任意词汇
                                </label>
                                <p class="description m-b-10">匹配任意一个分词即显示</p>
                                <label>
                                    <input type="radio" name="segment_match" value="all" <?php echo $search_config['segment_match'] === 'all' ? 'checked' : ''; ?>>
                                    全部词汇
                                </label>
                                <p class="description">需要匹配全部分词</p>
                            </td>
                        </tr>
                        <tr>
                            <th>最大搜索结果</th>
                            <td>
                                <input type="number" name="max_results" value="<?php echo !empty($search_config['max_results']) ? (int)$search_config['max_results'] : ''; ?>" min="0" max="100000" placeholder="0" style="width: 120px; min-width: 120px; text-align: center;">
                                <span>条</span>
                                <p class="description">提示：限制前台搜索最多计算与返回的结果总数，防止海量数据下深分页全表统计卡顿。留空或填 0 则不限制。</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- 搜索频率限制 -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">搜索频率</h3>
                </div>
                <div class="admin-card-content">
                    <table class="form-table">
                        <tr>
                            <th>时间窗口</th>
                            <td>
                                <div class="flex flex-wrap gap-2">
                                    <span>每</span>
                                    <input type="number" name="rate_limit" value="<?php echo (int)$search_config['rate_limit']; ?>" min="1" max="86400" style="width: 90px; min-width: 90px; text-align: center;">
                                    <span>秒内最多搜索</span>
                                    <input type="number" name="rate_max" value="<?php echo (int)$search_config['rate_max']; ?>" min="1" max="1000" style="width: 90px; min-width: 90px; text-align: center;">
                                    <span>次</span>
                                </div>
                                <p class="description">提示：设置合理频率防止恶意搜索占用服务器资源</p>
                            </td>
                        </tr>
                        <tr>
                            <th>锁定时长</th>
                            <td>
                                <input type="number" name="lock_duration" value="<?php echo (int)$search_config['lock_duration']; ?>" min="10" max="86400" style="width: 90px; min-width: 90px; text-align: center;">
                                <span>秒</span>
                                <p class="description">提示：超出频率限制后将锁定该用户，在此期间拒绝搜索请求</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- 屏蔽栏目 -->
            <div class="admin-card">
                <div class="admin-card-header no-border">
                    <h3 class="admin-card-title">屏蔽栏目</h3>
                </div>
                <div class="admin-card-content">
                    <?php render_hierarchical_categories_selector(array(
                        'name' => 'blocked_cats[]',
                        'id' => 'blocked-cats',
                        'selected' => $search_config['blocked_cats'],
                        'multiple' => true,
                        'show_checkbox' => true,
                        'class' => 'hierarchical-categories-selector',
                        'description' => '被选中的栏目内容将不在搜索结果中出现。'
                    )); ?>
                </div>
            </div>

            <!-- 搜索敏感词 -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">搜索敏感词</h3>
                </div>
                <div class="admin-card-content">
                    <p class="description m-b-10">每行一个敏感词，搜索包含敏感词的请求将被拒绝</p>
                    <textarea name="sensitive_words" rows="8" class="large-text mw-100 code" style="width: 50%;"><?php echo esc_html($sensitive_words_str); ?></textarea>
                    <p class="description">提示：已内置过滤 &lt; &gt; " ; , @ &amp; # \ * 等特殊字符</p>
                </div>
            </div>

            <div class="admin-form-submit">
                <button type="submit" class="button button-primary">保存配置</button>
            </div>
        </form>
    </div>

    <!-- ============ Tab: 搜索记录明细 ============ -->
    <div class="tab-panel" id="tab-logs" style="<?php echo $current_tab === 'logs' ? '' : 'display:none;'; ?>">
        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">搜索记录明细</h3>
                <div class="admin-card-actions gap-5">
                    <span class="description m-0">总搜索次数：<strong><?php echo $total; ?></strong></span>
                    <a href="?page=search-logs&action=clear&_todononce=<?php echo $clear_nonce; ?>" class="btn button-secondary" onclick="event.preventDefault(); var url = this.href; TodoModal.confirm({ title: '确认清空', message: '确定要清空所有搜索记录吗？此操作不可恢复。', type: 'danger', confirmText: '确认清空', onConfirm: function() { window.location.href = url; } }); return false;">
                        清空记录
                    </a>
                </div>
            </div>
            <div class="admin-card-content">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th>搜索关键词</th>
                            <th style="width: 100px;">结果数</th>
                            <th style="width: 130px;">IP 地址</th>
                            <th style="width: 160px;">搜索时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($logs)): ?>
                            <?php foreach ($logs as $log):
                                $id       = is_object($log) ? (int)$log->id : (int)($log['id'] ?? 0);
                                $keyword  = is_object($log) ? (string)($log->keyword ?? '') : (string)($log['keyword'] ?? '');
                                $results  = is_object($log) ? (int)($log->results_count ?? 0) : (int)($log['results_count'] ?? 0);
                                $ip       = is_object($log) ? (string)($log->ip_address ?? '') : (string)($log['ip_address'] ?? '');
                                $created  = is_object($log) ? (string)($log->created_at ?? '') : (string)($log['created_at'] ?? '');
                            ?>
                            <tr>
                                <td style="text-align: center; color: #999;"><?php echo $id; ?></td>
                                <td><strong><?php echo esc_html($keyword); ?></strong></td>
                                <td style="text-align: center;"><?php echo $results; ?></td>
                                <td style="text-align: center; font-size: 12px; color: #999;"><?php echo esc_html($ip); ?></td>
                                <td style="text-align: center; font-size: 12px; color: #999;"><?php echo esc_html($created); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5">
                                    <div class="no-items">
                                        <p>未找到记录。</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <!-- 分页 -->
                <?php
                echo todo_render_pagination(array(
                    'total_items'  => $total,
                    'per_page'     => $per_page,
                    'current_page' => $page,
                    'base_url'     => todo_admin_url('index.php?page=search-logs'),
                    'add_args'     => array('tab' => 'logs'),
                ));
                ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 搜索模式切换：显示/隐藏分词匹配选项
    var modeRadios = document.querySelectorAll('input[name="search_mode"]');
    var segmentRow = document.getElementById('segment-match-row');
    modeRadios.forEach(function(r) {
        r.addEventListener('change', function() {
            segmentRow.style.display = this.value === 'segment' ? '' : 'none';
        });
    });

    // Tab 切换（保留 URL 参数切换，此处仅处理视觉）
});
</script>
