<?php
/**
 * 管理后台底部模板
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access denied');
}

// 获取CMS版本
$cms_version = function_exists('todo_get_cms_version') ? todo_get_cms_version() : (defined('TODO_VERSION') ? TODO_VERSION : '1.0.0');

// 获取PHP版本
$php_version = phpversion();

// 获取MySQL版本
$mysql_version = 'Unknown';
if (isset($GLOBALS['tododb']) && $GLOBALS['tododb']) {
    try {
        $mysql_cached = false;
        if (class_exists('Todo_Cache_Manager')) {
            $cached_version = Todo_Cache_Manager::get('mysql_version', 'system', 'global');
            if ($cached_version !== false && $cached_version !== null && $cached_version !== '') {
                $mysql_version = $cached_version;
                $mysql_cached = true;
            }
        }
        if (!$mysql_cached) {
            $mysql_version = $GLOBALS['tododb']->get_var("SELECT VERSION()");
            if (class_exists('Todo_Cache_Manager') && $mysql_version) {
                Todo_Cache_Manager::set('mysql_version', $mysql_version, 3600, 'system', 'global');
            }
        }
    } catch (Exception $e) {
        $mysql_version = 'Error';
    }
} elseif (function_exists('todo_get_mysql_version')) {
    $mysql_version = todo_get_mysql_version();
}

// 获取执行时间
$execution_time_raw = 0;
if (defined('CMS_START_TIME')) {
    $execution_time_raw = microtime(true) - CMS_START_TIME;
    $execution_time = number_format($execution_time_raw, 3);
} else {
    // 如果CMS_START_TIME未定义，使用当前时间作为默认值
    $execution_time = '0.000';
}

// 获取内存使用量
$memory_usage = function_exists('size_format') ? size_format(memory_get_usage()) : number_format(memory_get_usage() / 1024 / 1024, 2) . ' MB';

// 获取数据库查询次数
$tododb_queries = 0;
if (isset($GLOBALS['tododb']) && $GLOBALS['tododb'] && method_exists($GLOBALS['tododb'], 'query_count')) {
    try {
        $tododb_queries = $GLOBALS['tododb']->query_count();
    } catch (Exception $e) {
        $tododb_queries = 0;
    }
}

$todo_perf_debug_json = '';
if (defined('TODO_DEBUG') && TODO_DEBUG) {
    $query_log = [];
    if (isset($GLOBALS['tododb']) && $GLOBALS['tododb'] && method_exists($GLOBALS['tododb'], 'get_query_log')) {
        try {
            $query_log = $GLOBALS['tododb']->get_query_log();
        } catch (Exception $e) {
            $query_log = [];
        }
    }

    $normalize_sql = static function ($sql) {
        $sql = preg_replace('/\s+/', ' ', trim((string) $sql));
        $sql = preg_replace("/'[^']*'/", '?', $sql);
        $sql = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql);
        return $sql;
    };

    $duplicate_groups = [];
    foreach ($query_log as $entry) {
        $normalized_query = $normalize_sql($entry['query'] ?? '');
        if ($normalized_query === '') {
            continue;
        }

        if (!isset($duplicate_groups[$normalized_query])) {
            $duplicate_groups[$normalized_query] = [
                'signature' => $normalized_query,
                'query' => $entry['query'] ?? '',
                'count' => 0,
                'caller' => $entry['caller'] ?? 'unknown',
                'location' => ($entry['file'] ?? 'unknown') . ':' . (int) ($entry['line'] ?? 0),
            ];
        }

        $duplicate_groups[$normalized_query]['count']++;
    }

    $duplicate_groups = array_values(array_filter($duplicate_groups, static function ($item) {
        return isset($item['count']) && $item['count'] > 1;
    }));

    usort($duplicate_groups, static function ($a, $b) {
        return ($b['count'] ?? 0) <=> ($a['count'] ?? 0);
    });

    $duplicate_groups = array_slice($duplicate_groups, 0, 15);

    $recent_queries = array_slice(array_reverse($query_log), 0, 15);
    $recent_queries = array_map(static function ($entry) {
        return [
            'query' => $entry['query'] ?? '',
            'caller' => $entry['caller'] ?? 'unknown',
            'location' => ($entry['file'] ?? 'unknown') . ':' . (int) ($entry['line'] ?? 0),
        ];
    }, $recent_queries);

    $simulate_prod_latency_enabled = defined('TODO_SIMULATE_PROD_LATENCY') && TODO_SIMULATE_PROD_LATENCY;
    $simulate_prod_latency_ms = 0;
    if ($simulate_prod_latency_enabled) {
        if (defined('TODO_SIMULATE_PROD_LATENCY') && is_numeric(TODO_SIMULATE_PROD_LATENCY)) {
            $simulate_prod_latency_ms = (float) TODO_SIMULATE_PROD_LATENCY;
        } else {
            $simulate_prod_latency_ms = 5.0;
        }
    }

    $perf_monitor_debug_data = null;
    if (class_exists('Todo_Performance_Monitor') && method_exists('Todo_Performance_Monitor', 'get_timing_debug_data')) {
        $perf_monitor_debug_data = Todo_Performance_Monitor::get_timing_debug_data();
    }

    $todo_perf_debug_payload = [
        'summary' => [
            'executionTimeMs' => round($execution_time_raw * 1000, 2),
            'executionTimeSeconds' => (float) $execution_time,
            'memoryUsage' => $memory_usage,
            'queryCount' => (int) $tododb_queries,
            'loggedQueryCount' => count($query_log),
            'duplicateGroupCount' => count($duplicate_groups),
            'perfMonitorEnabled' => defined('TODO_ENABLE_PERF_MONITOR') && TODO_ENABLE_PERF_MONITOR,
            'queryLogEnabled' => !empty($query_log),
            'simulateProdLatencyEnabled' => $simulate_prod_latency_enabled,
            'simulateProdLatencyMs' => $simulate_prod_latency_ms,
            'perfMonitorDbTotalMs' => isset($perf_monitor_debug_data['dbTotalMs']) ? (float) $perf_monitor_debug_data['dbTotalMs'] : 0,
            'perfMonitorDbCount' => isset($perf_monitor_debug_data['dbCount']) ? (int) $perf_monitor_debug_data['dbCount'] : 0,
            'requestUri' => $_SERVER['REQUEST_URI'] ?? '',
        ],
        'duplicates' => $duplicate_groups,
        'recentQueries' => $recent_queries,
        'monitor' => $perf_monitor_debug_data,
    ];

    $todo_perf_debug_json = json_encode(
        $todo_perf_debug_payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    if (!is_string($todo_perf_debug_json)) {
        $todo_perf_debug_json = '';
    }
}

// 获取当前年份
$current_year = date('Y');

// 获取站点标题
$site_title = get_option('site_title', 'TodoCMS');
// 处理行内多语言
if (!function_exists('todo_parse_inline_translations') && file_exists(ABSPATH . 'core/functions/multilang-fields.php')) {
    require_once ABSPATH . 'core/functions/multilang-fields.php';
}
if (function_exists('todo_parse_inline_translations')) {
    $site_title = todo_parse_inline_translations($site_title);
}
?>
<?php if (!isset($_GET['format']) || $_GET['format'] !== 'iframe'): ?>
    <div class="admin-footer">
        <div class="admin-footer-right">
            v<?php echo $cms_version; ?> | PHP <?php echo $php_version; ?> | MySQL <?php echo $mysql_version; ?>
        </div>

        <div class="admin-footer-debug">
            <?php if (defined('TODO_DEBUG') && TODO_DEBUG): ?>
                <div class="admin-footer-debug-info">
                    执行时间: <?php echo $execution_time; ?> 秒 |
                    内存使用: <?php echo $memory_usage; ?> |
                    数据库查询: <?php echo $tododb_queries; ?> 次
                    <?php if ($todo_perf_debug_json !== ''): ?>
                        <button type="button" class="btn button-secondary button-small todo-perf-toggle" aria-expanded="false" aria-controls="todo-perf-panel">
                            性能诊断
                        </button>
                    <?php endif; ?>
                </div>
                <?php if ($todo_perf_debug_json !== ''): ?>
                    <div id="todo-perf-panel" class="todo-perf-panel" hidden>
                        <div class="todo-perf-panel-header">
                            <div>
                                <strong>本地性能诊断</strong>
                                <p>刷新页面后查看重复 SQL、最近 SQL 与 Server-Timing，便于在本地还原线上卡顿。</p>
                                <div class="todo-perf-source-tabs" style="margin-top: 8px; display: flex; gap: 8px;">
                                    <button type="button" class="btn button-primary button-small todo-perf-tab" data-source="iframe">当前子页面 (Iframe)</button>
                                    <button type="button" class="btn button-secondary button-small todo-perf-tab" data-source="parent">外壳框架 (Shell)</button>
                                </div>
                            </div>
                            <div class="todo-perf-actions">
                                <button type="button" class="btn button-secondary button-small" data-action="copy-summary">复制摘要</button>
                                <button type="button" class="btn button-small" data-action="collapse-panel">收起</button>
                            </div>
                        </div>

                        <div class="todo-perf-grid" data-role="summary-cards"></div>

                        <div class="todo-perf-section">
                            <div class="todo-perf-section-header">
                                <strong>重复 SQL</strong>
                                <span>按结构归并，优先排查循环查询和 N+1。</span>
                            </div>
                            <div class="todo-perf-list" data-role="duplicate-list"></div>
                        </div>

                        <div class="todo-perf-section">
                            <div class="todo-perf-section-header">
                                <strong>最近 SQL</strong>
                                <span>查看当前请求尾部执行的查询与调用位置。</span>
                            </div>
                            <div class="todo-perf-list" data-role="recent-list"></div>
                        </div>

                        <div class="todo-perf-section">
                            <div class="todo-perf-section-header">
                                <strong>Server-Timing</strong>
                                <span>浏览器侧读取响应头，确认响应头链路是否透出指标。</span>
                            </div>
                            <div class="todo-perf-list" data-role="timing-list"></div>
                        </div>

                        <div class="todo-perf-section">
                            <div class="todo-perf-section-header">
                                <strong>性能监控聚合</strong>
                                <span>来自 `get_timing_html_comment()` 的同源数据，用于查看 DB 总耗时与 Top Queries。</span>
                            </div>
                            <div class="todo-perf-list" data-role="monitor-list"></div>
                        </div>
                    </div>

                    <script type="application/json" id="todo-perf-debug-data"><?php echo $todo_perf_debug_json; ?></script>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="admin-footer-copyright" style="font-family: sans-serif;">
            &copy; <?php echo $current_year; ?>     <?php echo $site_title; ?>.
        </div>

        <div class="totop">
            <a href="javascript:void(0);" class="top">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M13 20h-2V8l-5.5 5.5l-1.42-1.42L12 4.16l7.92 7.92l-1.42 1.42L13 8z"></path></svg>
            </a>
        </div>
    </div><!-- .admin-footer -->
    </div><!-- .admin-container -->
<?php else: ?>
    <?php if (defined('TODO_DEBUG') && TODO_DEBUG && $todo_perf_debug_json !== ''): ?>
        <script type="application/json" id="todo-perf-debug-data"><?php echo $todo_perf_debug_json; ?></script>
        <script type="text/javascript">
            (function () {
                try {
                    if (window.parent && window.parent !== window) {
                        var el = document.getElementById('todo-perf-debug-data');
                        if (el && el.textContent) {
                            var payload = JSON.parse(el.textContent || '{}');
                            if (window.performance && typeof window.performance.getEntriesByType === 'function') {
                                var navs = window.performance.getEntriesByType('navigation') || [];
                                var res = window.performance.getEntriesByType('resource') || [];
                                var entries = [];
                                navs.concat(res).forEach(function (entry) {
                                    if (entry && entry.serverTiming && entry.serverTiming.length) {
                                        entry.serverTiming.forEach(function (timing) {
                                            entries.push({
                                                source: entry.entryType === 'navigation' ? 'document (iframe)' : (entry.name || 'resource'),
                                                name: timing.name || '',
                                                duration: typeof timing.duration === 'number' ? timing.duration : 0,
                                                description: timing.description || ''
                                            });
                                        });
                                    }
                                });
                                entries.sort(function (a, b) { return (b.duration || 0) - (a.duration || 0); });
                                payload.iframeServerTiming = entries.slice(0, 20);
                            }
                            window.parent.postMessage({ type: 'todo-perf-iframe-sync', payload: payload }, '*');
                        }
                    }
                } catch (e) {}
            })();
        </script>
    <?php endif; ?>
<?php endif; ?>
</div><!-- .admin-container/admin-main -->

<?php
// 触发admin footer钩子
if (function_exists('todo_do_admin_action')) {
    todo_do_admin_action('footer');
}
?>

<?php
// 输出页脚脚本（in_footer=true）
if (function_exists('todo_print_scripts')) {
    todo_print_scripts(true);
}
?>

<script type="text/javascript">
    // 侧边栏菜单切换
    document.addEventListener('DOMContentLoaded', function () {
        <?php if (!isset($_GET['format']) || $_GET['format'] !== 'iframe'): ?>
            // 已经通过PHP处理了活动菜单的显示，这里只需要处理点击事件
            var menuItems = document.querySelectorAll('.admin-menu-item > a');

            menuItems.forEach(function (item) {
                item.addEventListener('click', function (e) {
                    var parent = this.parentNode;
                    var submenu = parent.querySelector('.admin-submenu');

                    if (submenu && !e.target.closest('.admin-submenu')) {
                        e.preventDefault();

                        // 如果当前菜单项已经是活动状态，则切换它的状态
                        if (parent.classList.contains('active')) {
                            parent.classList.remove('active');
                        } else {
                            // 关闭所有其他活动的菜单项
                            var activeItems = document.querySelectorAll('.admin-menu-item.active');
                            activeItems.forEach(function (activeItem) {
                                if (activeItem !== parent) {
                                    activeItem.classList.remove('active');
                                }
                            });

                            // 打开当前菜单项
                            parent.classList.add('active');
                        }
                    }
                });
            });
        <?php endif; ?>

        // 通知关闭按钮（使用事件委托支持动态加载的消息）
        document.addEventListener('click', function (e) {
            var dismissButton = e.target.closest('.notice-dismiss');
            if (dismissButton) {
                var notice = dismissButton.closest('.notice');
                if (notice) {
                    notice.style.transition = 'opacity 0.3s ease, margin 0.3s ease, padding 0.3s ease, height 0.3s ease';
                    notice.style.opacity = '0';
                    setTimeout(function () {
                        notice.style.margin = '0';
                        notice.style.padding = '0';
                        notice.style.height = '0';
                        notice.style.overflow = 'hidden';
                        setTimeout(function () {
                            notice.remove();
                        }, 300);
                    }, 100);
                }
            }
        });

        // 返回顶部平滑滚动
        var toTopBtn = document.querySelector('.totop a.top');
        if (toTopBtn) {
            toTopBtn.addEventListener('click', function(e) {
                e.preventDefault();
                
                // 兼容window滚动
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });
        }

        var perfToggle = document.querySelector('.todo-perf-toggle');
        var perfPanel = document.getElementById('todo-perf-panel');
        var perfDataScript = document.getElementById('todo-perf-debug-data');

        if (perfToggle && perfPanel && perfDataScript) {
            var perfDataParent = null;
            var perfDataIframe = null;
            var currentSource = 'iframe'; // 优先展示子页面性能数据

            try {
                perfDataParent = JSON.parse(perfDataScript.textContent || '{}');
            } catch (e) {
                perfDataParent = null;
            }

            var summaryContainer = perfPanel.querySelector('[data-role="summary-cards"]');
            var duplicateList = perfPanel.querySelector('[data-role="duplicate-list"]');
            var recentList = perfPanel.querySelector('[data-role="recent-list"]');
            var timingList = perfPanel.querySelector('[data-role="timing-list"]');
            var monitorList = perfPanel.querySelector('[data-role="monitor-list"]');
            var collapseButton = perfPanel.querySelector('[data-action="collapse-panel"]');
            var copyButton = perfPanel.querySelector('[data-action="copy-summary"]');
            var tabButtons = perfPanel.querySelectorAll('.todo-perf-tab');

            function getActivePerfData() {
                if (currentSource === 'iframe') {
                    return perfDataIframe || perfDataParent;
                }
                return perfDataParent;
            }

            function updateTabState() {
                tabButtons.forEach(function (btn) {
                    var source = btn.getAttribute('data-source');
                    if (source === currentSource) {
                        btn.className = 'btn button-primary button-small todo-perf-tab';
                    } else {
                        btn.className = 'btn button-secondary button-small todo-perf-tab';
                    }
                });
            }

            tabButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    currentSource = btn.getAttribute('data-source') || 'iframe';
                    updateTabState();
                    renderPerfPanel();
                });
            });

            function updateToggleButton() {
                var activeData = getActivePerfData();
                if (activeData && activeData.summary) {
                    var isSub = (currentSource === 'iframe' && perfDataIframe);
                    var prefix = isSub ? '性能诊断 (子页面: ' : '性能诊断 (';
                    perfToggle.textContent = prefix + activeData.summary.executionTimeMs + ' ms / ' + activeData.summary.queryCount + ' SQL)';
                }
            }

            window.addEventListener('message', function (e) {
                if (e.data && e.data.type === 'todo-perf-iframe-sync' && e.data.payload) {
                    perfDataIframe = e.data.payload;
                    updateToggleButton();
                    if (!perfPanel.hasAttribute('hidden') && currentSource === 'iframe') {
                        renderPerfPanel();
                    }
                }
            });

            window.addEventListener('todo-perf-nav-complete', function () {
                updateToggleButton();
                if (!perfPanel.hasAttribute('hidden')) {
                    renderPerfPanel();
                }
            });

            function escapeHtml(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function renderEmpty(container, text) {
                container.innerHTML = '<div class="todo-perf-empty">' + escapeHtml(text) + '</div>';
            }

            function getLatencyLabel(summary) {
                if (!summary || !summary.simulateProdLatencyEnabled) {
                    return '未开启';
                }
                return '已开启（约 ' + summary.simulateProdLatencyMs + 'ms / SQL）';
            }

            function getServerTimingEntries() {
                var activeData = getActivePerfData();
                if (currentSource === 'iframe' && activeData && activeData.iframeServerTiming) {
                    return activeData.iframeServerTiming;
                }

                if (!window.performance || typeof window.performance.getEntriesByType !== 'function') {
                    return [];
                }

                var entries = [];
                var navigationEntries = window.performance.getEntriesByType('navigation') || [];
                var resourceEntries = window.performance.getEntriesByType('resource') || [];
                var allEntries = navigationEntries.concat(resourceEntries);

                allEntries.forEach(function (entry) {
                    if (!entry || !entry.serverTiming || !entry.serverTiming.length) {
                        return;
                    }

                    entry.serverTiming.forEach(function (timing) {
                        entries.push({
                            source: entry.entryType === 'navigation' ? 'document' : (entry.name || 'resource'),
                            name: timing.name || '',
                            duration: typeof timing.duration === 'number' ? timing.duration : 0,
                            description: timing.description || ''
                        });
                    });
                });

                entries.sort(function (a, b) {
                    return (b.duration || 0) - (a.duration || 0);
                });

                return entries.slice(0, 20);
            }

            function renderSummaryCards(summary, serverTimingEntries) {
                if (!summaryContainer || !summary) {
                    return;
                }

                var isIframe = (currentSource === 'iframe' && perfDataIframe);
                var clientNav = (window.TodoPerfTracker && window.TodoPerfTracker.lastNav) ? window.TodoPerfTracker.lastNav : null;
                var clientNavText = '直接刷新 / 未发起导航';
                if (clientNav && clientNav.durationMs > 0) {
                    var browserCost = Math.max(0, clientNav.durationMs - summary.executionTimeMs);
                    clientNavText = clientNav.durationMs + ' ms (服务端 ' + summary.executionTimeMs + ' ms / 浏览器与网络 ' + browserCost + ' ms)';
                }

                var cards = [
                    { label: isIframe ? '子页面 URL' : '页面 URL', value: summary.requestUri || '未知' },
                    { label: '页面点击至加载完成', value: clientNavText },
                    { label: '服务端请求耗时', value: summary.executionTimeMs + ' ms' },
                    { label: '数据库查询', value: summary.queryCount + ' 次' },
                    { label: '已记录 SQL', value: summary.loggedQueryCount + ' 条' },
                    { label: '重复 SQL 组', value: summary.duplicateGroupCount + ' 组' },
                    { label: '延迟模拟', value: getLatencyLabel(summary) },
                    { label: 'Server-Timing', value: serverTimingEntries.length ? ('已捕获 ' + serverTimingEntries.length + ' 项') : (summary.perfMonitorEnabled ? '等待读取' : '未开启') },
                    { label: '监控DB总耗时', value: summary.perfMonitorDbTotalMs > 0 ? (summary.perfMonitorDbTotalMs.toFixed(2) + ' ms') : '暂无数据' }
                ];

                summaryContainer.innerHTML = cards.map(function (card) {
                    return '<div class="todo-perf-card"><span>' + escapeHtml(card.label) + '</span><strong>' + escapeHtml(card.value) + '</strong></div>';
                }).join('');
            }

            function renderQueryList(container, items, emptyText) {
                if (!container) {
                    return;
                }

                if (!items || !items.length) {
                    renderEmpty(container, emptyText);
                    return;
                }

                container.innerHTML = items.map(function (item) {
                    var meta = [];
                    if (item.count) {
                        meta.push('重复 ' + item.count + ' 次');
                    }
                    if (item.caller) {
                        meta.push(item.caller);
                    }
                    if (item.location) {
                        meta.push(item.location);
                    }

                    return '<div class="todo-perf-item">' +
                        '<div class="todo-perf-item-meta">' + escapeHtml(meta.join(' | ')) + '</div>' +
                        '<pre class="todo-perf-sql">' + escapeHtml(item.query || item.signature || '') + '</pre>' +
                        '</div>';
                }).join('');
            }

            function renderServerTiming(entries) {
                if (!timingList) {
                    return;
                }

                if (!entries.length) {
                    renderEmpty(timingList, '暂无 Server-Timing 指标；请确认 TODO_ENABLE_PERF_MONITOR 已开启。');
                    return;
                }

                timingList.innerHTML = entries.map(function (item) {
                    var desc = item.description ? (' | ' + item.description) : '';
                    return '<div class="todo-perf-item">' +
                        '<div class="todo-perf-item-meta">' + escapeHtml(item.source + ' | ' + item.name + ' | ' + item.duration.toFixed(2) + ' ms' + desc) + '</div>' +
                        '</div>';
                }).join('');
            }

            function renderMonitorData(monitor) {
                if (!monitorList) {
                    return;
                }

                if (!monitor || !monitor.enabled) {
                    renderEmpty(monitorList, '性能监控聚合未开启；请确认 TODO_ENABLE_PERF_MONITOR 与 TODO_DEBUG。');
                    return;
                }

                var sections = [];
                sections.push(
                    '<div class="todo-perf-item">' +
                    '<div class="todo-perf-item-meta">' + escapeHtml('DB Total | ' + Number(monitor.dbTotalMs || 0).toFixed(2) + ' ms | ' + (monitor.dbCount || 0) + ' queries') + '</div>' +
                    '</div>'
                );

                if (monitor.metrics && monitor.metrics.length) {
                    monitor.metrics.slice(0, 8).forEach(function (item) {
                        var meta = item.name + ' | ' + Number(item.duration || 0).toFixed(2) + ' ms';
                        if (item.description) {
                            meta += ' | ' + item.description;
                        }
                        sections.push(
                            '<div class="todo-perf-item">' +
                            '<div class="todo-perf-item-meta">' + escapeHtml(meta) + '</div>' +
                            '</div>'
                        );
                    });
                }

                if (monitor.topQueries && monitor.topQueries.length) {
                    monitor.topQueries.slice(0, 10).forEach(function (item) {
                        sections.push(
                            '<div class="todo-perf-item">' +
                            '<div class="todo-perf-item-meta">' + escapeHtml('Top Query | ' + Number(item.duration || 0).toFixed(2) + ' ms | ' + (item.count || 0) + ' calls') + '</div>' +
                            '<pre class="todo-perf-sql">' + escapeHtml(item.sqlPreview || item.sql || '') + '</pre>' +
                            '</div>'
                        );
                    });
                }

                monitorList.innerHTML = sections.join('');
            }

            function buildCopyText(serverTimingEntries) {
                var activeData = getActivePerfData();
                if (!activeData || !activeData.summary) {
                    return '';
                }

                var clientNav = (window.TodoPerfTracker && window.TodoPerfTracker.lastNav) ? window.TodoPerfTracker.lastNav : null;
                var navLine = '客户端点击至加载完成: 直接刷新 / 未发起导航';
                if (clientNav && clientNav.durationMs > 0) {
                    var browserCost = Math.max(0, clientNav.durationMs - activeData.summary.executionTimeMs);
                    navLine = '客户端点击至加载完成: ' + clientNav.durationMs + ' ms (服务端: ' + activeData.summary.executionTimeMs + ' ms | 浏览器/网络: ' + browserCost + ' ms | 点击时刻: ' + (clientNav.startTimestamp || '') + ')';
                }

                var lines = [
                    '视图: ' + (currentSource === 'iframe' && perfDataIframe ? '子页面 (Iframe)' : '外壳框架 (Shell)'),
                    '请求: ' + (activeData.summary.requestUri || ''),
                    navLine,
                    '服务端请求耗时: ' + activeData.summary.executionTimeMs + ' ms',
                    '查询数: ' + activeData.summary.queryCount,
                    '已记录 SQL: ' + activeData.summary.loggedQueryCount,
                    '重复 SQL 组: ' + activeData.summary.duplicateGroupCount,
                    '延迟模拟: ' + getLatencyLabel(activeData.summary),
                    '监控DB总耗时: ' + (activeData.summary.perfMonitorDbTotalMs > 0 ? (activeData.summary.perfMonitorDbTotalMs.toFixed(2) + ' ms') : '暂无数据')
                ];

                if (activeData.duplicates && activeData.duplicates.length) {
                    lines.push('重复 SQL Top:');
                    activeData.duplicates.slice(0, 5).forEach(function (item) {
                        lines.push('- ' + item.count + 'x | ' + (item.location || item.caller || 'unknown') + ' | ' + (item.query || item.signature || ''));
                    });
                }

                if (serverTimingEntries.length) {
                    lines.push('Server-Timing:');
                    serverTimingEntries.slice(0, 8).forEach(function (item) {
                        lines.push('- ' + item.source + ' | ' + item.name + ' | ' + item.duration.toFixed(2) + ' ms' + (item.description ? ' | ' + item.description : ''));
                    });
                }

                if (activeData.monitor && activeData.monitor.topQueries && activeData.monitor.topQueries.length) {
                    lines.push('性能监控 Top Queries:');
                    activeData.monitor.topQueries.slice(0, 5).forEach(function (item) {
                        lines.push('- ' + Number(item.duration || 0).toFixed(2) + ' ms | ' + (item.count || 0) + ' calls | ' + (item.sqlPreview || item.sql || ''));
                    });
                }

                return lines.join('\n');
            }

            function renderPerfPanel() {
                var activeData = getActivePerfData();
                if (!activeData) {
                    return;
                }

                updateTabState();
                var serverTimingEntries = getServerTimingEntries();
                renderSummaryCards(activeData.summary, serverTimingEntries);
                renderQueryList(duplicateList, activeData.duplicates, '当前视图未发现重复 SQL。');
                renderQueryList(recentList, activeData.recentQueries, '当前视图没有可展示的 SQL 记录。');
                renderServerTiming(serverTimingEntries);
                renderMonitorData(activeData.monitor);
            }

            perfToggle.addEventListener('click', function () {
                var isHidden = perfPanel.hasAttribute('hidden');
                if (isHidden) {
                    perfPanel.removeAttribute('hidden');
                    perfToggle.setAttribute('aria-expanded', 'true');
                    renderPerfPanel();
                } else {
                    perfPanel.setAttribute('hidden', 'hidden');
                    perfToggle.setAttribute('aria-expanded', 'false');
                }
            });

            if (collapseButton) {
                collapseButton.addEventListener('click', function () {
                    perfPanel.setAttribute('hidden', 'hidden');
                    perfToggle.setAttribute('aria-expanded', 'false');
                });
            }

            if (copyButton) {
                copyButton.addEventListener('click', function () {
                    var copyText = buildCopyText(getServerTimingEntries());
                    if (!copyText) {
                        return;
                    }

                    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                        navigator.clipboard.writeText(copyText).then(function () {
                            if (window.TodoCMS && TodoCMS.Utils && typeof TodoCMS.Utils.showMessage === 'function') {
                                TodoCMS.Utils.showMessage('性能摘要已复制', 'success', 2000);
                            }
                        }).catch(function () {
                            if (window.TodoCMS && TodoCMS.Utils && typeof TodoCMS.Utils.showMessage === 'function') {
                                TodoCMS.Utils.showMessage('复制失败，请手动复制', 'warning', 2500);
                            }
                        });
                    }
                });
            }

            if (window.PerformanceObserver) {
                try {
                    var perfObserver = new PerformanceObserver(function () {
                        if (!perfPanel.hasAttribute('hidden')) {
                            renderPerfPanel();
                        }
                    });
                    perfObserver.observe({ type: 'navigation', buffered: true });
                    perfObserver.observe({ type: 'resource', buffered: true });
                } catch (e) {
                    // 忽略不支持的浏览器环境
                }
            }
        }

    });
</script>
</body>

</html>
