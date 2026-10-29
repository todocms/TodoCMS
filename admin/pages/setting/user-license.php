<?php
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

if (!current_user_can('manage_options')) {
    todo_die('您没有足够的权限访问此页面。');
}

$api_url = defined('TODO_UPDATE_SERVER_URL') ? TODO_UPDATE_SERVER_URL : '';
if ($api_url === '') {
    $cfg = function_exists('todo_get_update_config') ? todo_get_update_config() : array('api' => '');
    $opt_api = function_exists('todo_get_option') ? (string) todo_get_option('todo_update_server_url') : '';
    $api_url = $opt_api ?: ($cfg['api'] ?? '');
}

$saved_key = function_exists('todo_get_option') ? (string) todo_get_option('todo_license_key') : '';
$saved_domain = function_exists('todo_get_option') ? (string) todo_get_option('todo_update_license_domain') : '';
if ($saved_domain === '' && isset($_SERVER['HTTP_HOST'])) {
    $saved_domain = preg_replace('/:\\d+$/', '', strtolower($_SERVER['HTTP_HOST']));
}

if (class_exists('Todo_Core_Kernel') && is_callable(['Todo_Core_Kernel', 'get_client_fingerprint'])) {
    $client_fingerprint = Todo_Core_Kernel::get_client_fingerprint();
} elseif (function_exists('todo_get_client_fingerprint')) {
    $client_fingerprint = todo_get_client_fingerprint();
} elseif (class_exists('Todo_System_Utils') && is_callable(['Todo_System_Utils', 'get_client_fingerprint'])) {
    $client_fingerprint = Todo_System_Utils::get_client_fingerprint();
} else {
    $client_fingerprint = '';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_GET['logout']) && $_GET['logout'] === '1') {
    unset($_SESSION['license_authed'], $_SESSION['license_key'], $_SESSION['license_password']);
    header('Location: ' . todo_admin_url('index.php?page=user-license'));
    exit;
}

$is_authed = !empty($_SESSION['license_authed']) && !empty($_SESSION['license_key']) && !empty($_SESSION['license_password']);
$login_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'license_login') {
    if (!class_exists('Todo_Security_Manager') || !Todo_Security_Manager::verify_request_csrf('user_license', '_todononce')) {
        $login_error = '安全验证失败，请重试。';
    } else {
        $posted_key = strtolower(trim((string)($_POST['license_key'] ?? '')));
        $posted_pw = trim((string)($_POST['password'] ?? ''));
        if ($posted_key === '' || $posted_pw === '') {
            $login_error = '请输入授权码和密码';
        } else {
            $api_verify_url = rtrim($api_url, '/') . '/api.php';
            $payload = json_encode(array(
                'action' => 'user_login',
                'license_key' => $posted_key,
                'password' => $posted_pw,
                'server_id' => $client_fingerprint,
            ));
            $ch = curl_init($api_verify_url);
            curl_setopt_array($ch, array(
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
            ));
            $resp = curl_exec($ch);
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resp === false || $http_status === 0) {
                $login_error = '无法连接到授权服务器';
            } else {
                $json = json_decode($resp, true);
                if (!is_array($json) || ($json['code'] ?? 0) !== 200) {
                    if (($json['code'] ?? 0) === 429 && isset($json['data']['wait_minutes'])) {
                        $login_error = '尝试次数过多，请在 ' . (int)$json['data']['wait_minutes'] . ' 分钟后再试。';
                    } else {
                        $msg = is_array($json) ? ($json['message'] ?? '未知错误') : '响应格式错误';
                        $login_error = '登录失败: ' . $msg;
                    }
                } else {
                    $_SESSION['license_authed'] = true;
                    $_SESSION['license_key'] = $posted_key;
                    $_SESSION['license_password'] = $posted_pw;
                    $is_authed = true;
                }
            }
        }
    }
}
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title">授权管理</h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=updates'); ?>" class="button button-secondary">返回系统升级</a>
            <button id="logout-btn" type="button" class="button button-secondary" onclick="handleLogout()" style="display:none;">退出授权管理</button>
        </div>
    </div>

    <?php if ($api_url === ''): ?>
        <div class="notice notice-warning">
            <p>请先在「系统升级 → 更新服务器设置」中配置更新服务器 API 地址，并保存设置。</p>
        </div>
    <?php else: ?>

    <?php if (!$is_authed): ?>
    <div class="admin-card" id="license-login-card" style="max-width: 400px; margin: 0 auto;">
        <div class="admin-card-header">
            <h3 class="admin-card-title flex items-center gap-1">
                <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 48 48">
                    <path d="M0 0h48v48H0z" fill="none"></path>
                    <path fill="currentColor" stroke="currentColor" stroke-linejoin="round" stroke-width="4" d="m23.999 5l-6.113 12.478L4 19.49l10.059 9.834L11.654 43L24 36.42L36.345 43L33.96 29.325L44 19.491l-13.809-2.013z"></path>
                </svg>
                授权登录
            </h3>
        </div>
        <div class="admin-card-content">
            <div class="form-group m-0">
                <?php if ($login_error !== ''): ?>
                <div class="notice notice-error" style="margin-top: 0;font-size:13px;"><?php echo esc_html($login_error); ?></div>
                <?php endif; ?>
                <div id="login-error" class="notice notice-error" style="margin-top: 0;font-size: 13px;display:none;"></div>
                <div id="login-loading" class="notice notice-info" style="margin-top: 0;font-size: 13px;display:none;">正在登录...</div>
            </div>
            <form id="license-login-form" method="post" onsubmit="return handleLoginSubmit(event)">
                <?php Todo_Security_Manager::output_csrf_field('user_license'); ?>
                <input type="hidden" name="action" value="license_login">
                <div class="form-group">
                    <label>授权码</label>
                    <input type="text" id="login-license-key" name="license_key" value="<?php echo esc_attr($saved_key); ?>" style="width:100%;" placeholder="请输入授权码" required>
                </div>
                <div class="form-group">
                    <label>密码</label>
                    <div style="display: inline; position: relative;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" style="position: absolute; right: 0; top: 0; padding: 5px;width: 40px; height: 25px; opacity: 0.2; cursor: pointer;" onmouseover="this.style.opacity='1';" onmouseout="this.style.opacity='0.2';" onclick="var p = this.nextElementSibling; p.type = p.type === 'password' ? 'text' : 'password';">
                            <path d="M0 0h24v24H0z" fill="none" />
                            <path fill="currentColor" d="M2 5.27L3.28 4L20 20.72L18.73 22l-3.08-3.08c-1.15.38-2.37.58-3.65.58c-5 0-9.27-3.11-11-7.5c.69-1.76 1.79-3.31 3.19-4.54zM12 9a3 3 0 0 1 3 3a3 3 0 0 1-.17 1L11 9.17A3 3 0 0 1 12 9m0-4.5c5 0 9.27 3.11 11 7.5a11.8 11.8 0 0 1-4 5.19l-1.42-1.43A9.86 9.86 0 0 0 20.82 12A9.82 9.82 0 0 0 12 6.5c-1.09 0-2.16.18-3.16.5L7.3 5.47c1.44-.62 3.03-.97 4.7-.97M3.18 12A9.82 9.82 0 0 0 12 17.5c.69 0 1.37-.07 2-.21L11.72 15A3.064 3.064 0 0 1 9 12.28L5.6 8.87c-.99.85-1.82 1.91-2.42 3.13" />
                        </svg>
                        <input type="password" id="login-password" name="password" style="width:100%;" placeholder="请输入16位密码" required>
                    </div>
                </div>
                <div class="m-t-20">
                    <button type="submit" class="button button-primary w-100">登 录</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($is_authed): ?>
    <div id="license-dashboard">

        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">授权信息</h3>
            </div>
            <div class="admin-card-content">
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th style="min-width: 100px;opacity: 0.7;">授权码</th>
                            <td id="info-license-key" style="word-break: break-all;">-</td>
                        </tr>
                        <tr>
                            <th style="min-width: 100px;opacity: 0.7;">状态</th>
                            <td id="info-status">-</td>
                        </tr>
                        <tr>
                            <th style="min-width: 100px;opacity: 0.7;">到期时间</th>
                            <td id="info-expires">-</td>
                        </tr>
                        <tr>
                            <th style="min-width: 100px;opacity: 0.7;">绑定服务器</th>
                            <td>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span id="info-server">-</span>
                                    <button type="button" class="btn button-secondary flex items-center gap-1" onclick="handleUnbindServer()">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24">
                                            <path d="M0 0h24v24H0z" fill="none"></path>
                                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-miterlimit="10" stroke-width="1.5" d="m12 7.729l2.711-2.715a3.028 3.028 0 0 1 5.168 2.14a3 3 0 0 1-.904 2.131L16.267 12M12 16.238L9.251 18.99a3 3 0 0 1-4.252 0a3 3 0 0 1 .022-4.237L7.766 12m8.674 4.44l2.122 2.122M5.438 5.379L7.56 7.5m10.253 6.75H21M14.25 18v3M9.75 6.188V3M6.188 9.75H3"></path>
                                        </svg>
                                        解绑服务器</button>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th style="min-width: 100px;opacity: 0.7;">年度换绑上限</th>
                            <td id="info-rebinds">-</td>
                        </tr>
                        <tr>
                            <th style="min-width: 100px;opacity: 0.7;">备注</th>
                            <td id="info-notes">-</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-header no-border">
                <h3 class="admin-card-title">域名管理</h3>
            </div>
            <div class="admin-card-content">
                <div class="form-group m-0">
                    <div id="domain-error" class="notice notice-error" style="font-size: 13px;display:none;"></div>
                    <div id="domain-success" class="notice notice-success" style="font-size: 13px;display:none;"></div>
                    <div id="domain-loading" class="notice notice-info" style="font-size: 13px;display:none;">处理中...</div>
                </div>

                <table class="admin-table" id="domains-table">
                    <thead>
                        <tr>
                            <th style="min-width: 100px;">域名</th>
                            <th>服务到期</th>
                            <th>绑定时间</th>
                            <th>变更上限</th>
                            <th style="min-width: 100px;text-align:right;">操作</th>
                        </tr>
                    </thead>
                    <tbody id="domains-tbody">
                        <tr class="empty">
                            <td colspan="5">
                                <div class="no-items"><p>暂无绑定域名。</p></div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-header">
                <h3 class="admin-card-title">修改密码</h3>
            </div>
            <div class="admin-card-content">
                <div class="form-group m-0">
                    <div id="pwd-error" class="notice notice-error" style="font-size: 13px;display:none;"></div>
                    <div id="pwd-success" class="notice notice-success" style="font-size: 13px;display:none;"></div>
                    <div id="pwd-loading" class="notice notice-info" style="font-size: 13px;display:none;">处理中...</div>
                </div>
                <div class="form-group">
                    <label>新密码</label>
                    <div style="display: inline; position: relative;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" style="position: absolute; right: 0; top: 0; padding: 5px;width: 40px; height: 25px; opacity: 0.2; cursor: pointer;" onmouseover="this.style.opacity='1';" onmouseout="this.style.opacity='0.2';" onclick="var p = this.nextElementSibling; p.type = p.type === 'password' ? 'text' : 'password';">
                            <path d="M0 0h24v24H0z" fill="none" />
                            <path fill="currentColor" d="M2 5.27L3.28 4L20 20.72L18.73 22l-3.08-3.08c-1.15.38-2.37.58-3.65.58c-5 0-9.27-3.11-11-7.5c.69-1.76 1.79-3.31 3.19-4.54zM12 9a3 3 0 0 1 3 3a3 3 0 0 1-.17 1L11 9.17A3 3 0 0 1 12 9m0-4.5c5 0 9.27 3.11 11 7.5a11.8 11.8 0 0 1-4 5.19l-1.42-1.43A9.86 9.86 0 0 0 20.82 12A9.82 9.82 0 0 0 12 6.5c-1.09 0-2.16.18-3.16.5L7.3 5.47c1.44-.62 3.03-.97 4.7-.97M3.18 12A9.82 9.82 0 0 0 12 17.5c.69 0 1.37-.07 2-.21L11.72 15A3.064 3.064 0 0 1 9 12.28L5.6 8.87c-.99.85-1.82 1.91-2.42 3.13" />
                        </svg>
                        <input type="password" id="new-password" style="width:50%;" class="mw-100" placeholder="至少16位大小写字母+数字+特殊字符" minlength="16">
                    </div>
                </div>
                <div class="form-group">
                    <label>确认新密码</label>
                    <div style="display: inline; position: relative;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" style="position: absolute; right: 0; top: 0; padding: 5px;width: 40px; height: 25px; opacity: 0.2; cursor: pointer;" onmouseover="this.style.opacity='1';" onmouseout="this.style.opacity='0.2';" onclick="var p = this.nextElementSibling; p.type = p.type === 'password' ? 'text' : 'password';">
                            <path d="M0 0h24v24H0z" fill="none" />
                            <path fill="currentColor" d="M2 5.27L3.28 4L20 20.72L18.73 22l-3.08-3.08c-1.15.38-2.37.58-3.65.58c-5 0-9.27-3.11-11-7.5c.69-1.76 1.79-3.31 3.19-4.54zM12 9a3 3 0 0 1 3 3a3 3 0 0 1-.17 1L11 9.17A3 3 0 0 1 12 9m0-4.5c5 0 9.27 3.11 11 7.5a11.8 11.8 0 0 1-4 5.19l-1.42-1.43A9.86 9.86 0 0 0 20.82 12A9.82 9.82 0 0 0 12 6.5c-1.09 0-2.16.18-3.16.5L7.3 5.47c1.44-.62 3.03-.97 4.7-.97M3.18 12A9.82 9.82 0 0 0 12 17.5c.69 0 1.37-.07 2-.21L11.72 15A3.064 3.064 0 0 1 9 12.28L5.6 8.87c-.99.85-1.82 1.91-2.42 3.13" />
                        </svg>
                        <input type="password" id="confirm-password" style="width:50%;" class="mw-100" placeholder="再次输入新密码" minlength="16">
                    </div>
                </div>
                <div class="m-t-20">
                    <button type="button" class="button button-primary" onclick="handleUpdatePassword()">修改密码</button>
                </div>
            </div>
        </div>

        <div class="admin-card" style="margin-top: 20px;">
            <div class="admin-card-header">
                <h3 class="admin-card-title">转赠说明</h3>
            </div>
            
            <div class="admin-card-content">
                <p>转赠授权码到example.com：</p>
                <ul style="margin: 10px 15px">
                    <li>1、变更域名为example.com。</li>
                    <li>2、修改密码（密码可能多人转手，存在泄漏风险）。</li>
                    <li>3、解绑服务器（最后一步，因为服务器解绑后，授权管理将不可用，需要重新绑定登录)。</li>
                </ul>
                <p class="description">请确保在 换绑 及 变更 上限内完成。</p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<template id="todo-tpl-change-domain-modal">
    <form id="change-domain-form">
        <div class="todo-modal-body-content">
            <table class="form-table w-120">
                <tr>
                    <th>当前域名</th>
                    <td>
                        <input type="text" id="change-old-domain-display" readonly style="background:#f5f5f5;">
                    </td>
                </tr>
                <tr>
                    <th>新域名 <span class="required">*</span></th>
                    <td>
                        <input type="text" id="change-new-domain-input" placeholder="如 example.com" required>
                        <p class="description">请输入新的域名地址</p>
                    </td>
                </tr>
            </table>
        </div>
    </form>
</template>

<script type="text/javascript">
(function() {
    var API_URL = <?php echo json_encode($api_url); ?>;
    var SERVER_ID = <?php echo json_encode($client_fingerprint); ?>;
    var IS_AUTHED = <?php echo $is_authed ? 'true' : 'false'; ?>;
    var STORAGE_KEY = 'todocms_license_auth';
    var currentLicenseKey = <?php echo $is_authed ? json_encode($_SESSION['license_key']) : "''"; ?>;
    var currentPassword = <?php echo $is_authed ? json_encode($_SESSION['license_password']) : "''"; ?>;

    function showEl(id) { var el = document.getElementById(id); if (el) el.style.display = ''; }
    function hideEl(id) { var el = document.getElementById(id); if (el) el.style.display = 'none'; }
    function setHtml(id, html) { var el = document.getElementById(id); if (el) el.innerHTML = html; }
    function val(id) { var el = document.getElementById(id); return el ? el.value : ''; }
    function setVal(id, v) { var el = document.getElementById(id); if (el) el.value = v; }

    function apiCall(action, data, onSuccess, onError) {
        var payload = data || {};
        payload.action = action;
        if (SERVER_ID) {
            payload.server_id = SERVER_ID;
        }
        fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.code === 200) {
                onSuccess(json.data);
            } else {
                onError(json.message || '请求失败');
            }
        })
        .catch(function(err) {
            onError('网络错误: ' + (err.message || '未知错误'));
        });
    }

    function renderDomainList(domains) {
        var tbody = document.getElementById('domains-tbody');
        if (!domains || domains.length === 0) {
            tbody.innerHTML = '<tr class="empty"><td colspan="5"><div class="no-items"><p>暂无绑定域名。</p></div></td></tr>';
            return;
        }
        var rows = '';
        domains.forEach(function(d) {
            var created = d.created_at ? new Date(d.created_at * 1000).toLocaleDateString('zh-CN') : '-';
            var expire = d.expires_at > 0 ? new Date(d.expires_at * 1000).toLocaleDateString('zh-CN') : '永久';
            var changeCount = d.domain_change_count || 0;
            var maxChanges = d.max_domain_changes || 3;
            var changeLimit = changeCount + '/' + maxChanges;
            if (changeCount === maxChanges) {
                changeLimit = '<span style="color: #d43838;">' + changeLimit + '</span>';
            }
            rows += '<tr>' +
                '<td>' + escapeHtml(d.domain || '-') + '</td>' +
                '<td>' + expire + '</td>' +
                '<td><span style="color:#999;">' + created + '</span></td>' +
                '<td>' + changeLimit + '</td>' +
                '<td style="text-align:right;"><button type="button" class="button button-secondary" onclick="handleChangeDomain(\'' + escapeHtml(d.domain || '') + '\')" style="padding:2px 10px;font-size:12px;">变更域名</button></td>' +
                '</tr>';
        });
        tbody.innerHTML = rows;
    }

    function renderDashboard(data) {
        setHtml('info-license-key', data.license_key || '-');
        var statusMap = { 'active': '<span style="color:green;">激活</span>', 'disabled': '<span style="color: #d43838;">已禁用</span>' };
        setHtml('info-status', statusMap[data.status] || data.status || '-');
        setHtml('info-expires', data.expires_at > 0 ? new Date(data.expires_at * 1000).toLocaleDateString('zh-CN') : '永久授权');
        setHtml('info-server', data.bound_server_id_masked || '未绑定');
        var rebindsUsed = data.rebinds_used_this_year || 0;
        var rebindsMax = data.max_rebinds_per_year || 0;
        if (rebindsUsed === rebindsMax) {
            setHtml('info-rebinds', '<span style="color: #d43838;">' + rebindsUsed + '/' + rebindsMax + '</span>');
        } else {
            setHtml('info-rebinds', rebindsUsed + '/' + rebindsMax);
        }
        setHtml('info-notes', data.notes || '-');
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML.replace(/'/g, "\\'");
    }

    function saveAuth() {
        var auth = { lk: val('login-license-key'), pw: val('login-password') };
        try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(auth)); } catch(e) {}
    }

    function clearAuth() {
        try { sessionStorage.removeItem(STORAGE_KEY); } catch(e) {}
    }

    function loadAuth() {
        try {
            var raw = sessionStorage.getItem(STORAGE_KEY);
            if (raw) {
                var auth = JSON.parse(raw);
                if (auth.lk && auth.pw) {
                    setVal('login-license-key', auth.lk);
                    setVal('login-password', auth.pw);
                }
            }
        } catch(e) {}
    }

    function loadInfo() {
        hideEl('domain-error');
        hideEl('domain-success');
        showEl('domain-loading');
        apiCall('user_list_domains', { license_key: currentLicenseKey, password: currentPassword }, function(data) {
            hideEl('domain-loading');
            renderDomainList(data.domains || []);
        }, function(err) {
            hideEl('domain-loading');
            showEl('domain-error');
            setHtml('domain-error', err);
        });
        apiCall('user_get_info', { license_key: currentLicenseKey, password: currentPassword }, function(data) {
            renderDashboard(data);
        }, function(err) {
            showEl('domain-error');
            setHtml('domain-error', err);
            if (err === 'user_not_found' || err === 'password_incorrect' || err === 'license_not_activated' || err === 'server_mismatch') {
                handleLogout();
            }
        });
    }

    window.handleLoginSubmit = function(e) {
        var lk = val('login-license-key').trim();
        var pw = val('login-password').trim();
        if (!lk || !pw) {
            showEl('login-error');
            setHtml('login-error', '请输入授权码和密码');
            return false;
        }
        showEl('login-loading');
        hideEl('login-error');
        saveAuth();
        return true;
    };

    var changeDomainCurrent = '';

    window.handleChangeDomain = function(oldDomain) {
        changeDomainCurrent = oldDomain;
        TodoModal.form({
            templateId: 'todo-tpl-change-domain-modal',
            title: '变更域名',
            submitText: '确认变更',
            cancelText: '取消',
            onOpen: function(modal) {
                modal.element.querySelector('#change-old-domain-display').value = oldDomain;
                modal.element.querySelector('#change-new-domain-input').value = '';
            },
            onSubmit: function(formData, form) {
                var modalEl = form ? form.closest('.todo-modal-overlay') : document.querySelector('.todo-modal-overlay.show');
                var newDomainInput = modalEl ? modalEl.querySelector('#change-new-domain-input') : document.getElementById('change-new-domain-input');
                var newDomain = newDomainInput ? newDomainInput.value.trim().toLowerCase() : '';
                if (!newDomain) return;
                if (newDomain === changeDomainCurrent) {
                    TodoModal.toast('新域名与当前域名相同，无需变更。', 'warning');
                    return;
                }
                showEl('domain-loading');
                hideEl('domain-error');
                hideEl('domain-success');
                apiCall('user_change_domain', {
                    license_key: currentLicenseKey,
                    password: currentPassword,
                    old_domain: changeDomainCurrent,
                    new_domain: newDomain
                }, function(data) {
                    hideEl('domain-loading');
                    showEl('domain-success');
                    setHtml('domain-success', '域名已从「' + escapeHtml(changeDomainCurrent) + '」变更为「' + escapeHtml(newDomain) + '」');
                    TodoModal.closeAll();
                    loadInfo();
                }, function(err) {
                    hideEl('domain-loading');
                    showEl('domain-error');
                    setHtml('domain-error', err);
                });
            }
        });
    };

    function validatePasswordStrength(pwd) {
        if (pwd.length < 16) {
            return '新密码至少需要16位字符';
        }
        if (!/[0-9]/.test(pwd)) {
            return '新密码必须包含数字';
        }
        if (!/[A-Z]/.test(pwd)) {
            return '新密码必须包含大写字母';
        }
        if (!/[a-z]/.test(pwd)) {
            return '新密码必须包含小写字母';
        }
        if (!/[^A-Za-z0-9]/.test(pwd)) {
            return '新密码必须包含特殊字符（如 !@#$%^&* 等）';
        }
        return null;
    }

    window.handleUpdatePassword = function() {
        var np = val('new-password').trim();
        var cp = val('confirm-password').trim();
        var strengthErr = validatePasswordStrength(np);
        if (strengthErr) {
            showEl('pwd-error');
            setHtml('pwd-error', strengthErr);
            return;
        }
        if (np !== cp) {
            showEl('pwd-error');
            setHtml('pwd-error', '两次输入的密码不一致');
            return;
        }
        showEl('pwd-loading');
        hideEl('pwd-error');
        hideEl('pwd-success');
        apiCall('user_update_password', { license_key: currentLicenseKey, password: currentPassword, new_password: np }, function(data) {
            hideEl('pwd-loading');
            handleLogout();
        }, function(err) {
            hideEl('pwd-loading');
            showEl('pwd-error');
            setHtml('pwd-error', err);
        });
    };

    window.handleUnbindServer = function() {
        if (!confirm('确定要解绑当前服务器吗？\n\n解绑后授权码需要重新验证激活。\n年度换绑次数有限，请谨慎操作。')) return;
        showEl('domain-loading');
        hideEl('domain-error');
        hideEl('domain-success');
        apiCall('user_unbind_server', { license_key: currentLicenseKey, password: currentPassword }, function(data) {
            hideEl('domain-loading');
            TodoModal.toast('服务器已成功解绑，即将退出授权管理。', 'success');
            setTimeout(function() {
                handleLogout();
            }, 3000);
        }, function(err) {
            hideEl('domain-loading');
            showEl('domain-error');
            setHtml('domain-error', '解绑失败: ' + err);
            if (err === 'license_not_activated' || err === 'server_mismatch') {
                handleLogout();
            }
        });
    };

    window.handleLogout = function() {
        clearAuth();
        window.location.href = window.location.pathname + '?page=user-license&logout=1';
    };

    document.addEventListener('DOMContentLoaded', function() {
        if (IS_AUTHED) {
            showEl('logout-btn');
            loadInfo();
        } else {
            loadAuth();
        }
    });
})();
</script>
