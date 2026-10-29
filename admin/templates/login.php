<?php
/**
 * 管理后台登录模板
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow', true);
    echo '404 Not Found';
    exit;
}

// 强制设置为 HTML 响应，避免被错误地当作纯文本输出
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
}

if (!function_exists('todo_get_site_url')) {
    function todo_get_site_url($path = '') {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '';
        $dir = rtrim(dirname($script), '/');
        $dir = preg_replace('#/admin(/.*)?$#', '', $dir);
        if ($dir === '/' || $dir === '\\' || $dir === '.') { $dir = ''; }
        $prefix = $dir ? $dir : '';
        $base = $protocol . '://' . $host . $prefix;
        $norm = ltrim($path, '/');
        return rtrim($base, '/') . ($norm !== '' ? '/' . $norm : '');
    }
}

// 硬编码站点标题，避免依赖get_option函数
$site_title = defined('TODO_ADMIN_LOGIN_TITLE') ? TODO_ADMIN_LOGIN_TITLE : 'TodoCMS';

// 获取重定向URL
$redirect_to = isset($_GET['redirect_to']) ? $_GET['redirect_to'] : 'index.php?page=dashboard';

// 获取登录错误信息
$login_error = isset($login_error) ? $login_error : '';

// 获取登录成功消息
$login_message = isset($_GET['message']) ? (string) $_GET['message'] : '';
if ($login_message === '' && isset($_GET['login_message'])) {
    $login_message = (string) $_GET['login_message'];
}

// 获取用户名
$username = isset($_POST['username']) ? $_POST['username'] : '';

// 获取登录页面标题
$login_title = '登录 &lsaquo; ' . $site_title;

// 输出HTML头部
?><!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="generator" content="TodoCMS">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title><?php echo $login_title; ?></title>
    <style>
        body { background-color: #f9fafd; font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
        .login-container { max-width: 320px; margin: 100px auto; padding: 30px 30px 20px; background-color: #fff; border-radius: 3px; box-shadow: 0 3px 5px rgba(0, 0, 0, 0.05); }
        .login-logo { text-align: center; margin-bottom: 20px; }
        .login-logo h1 { line-height: 32px; font-size: 24px; color: #282832; margin: 0; }
        .login-logo h1 span { position: absolute; margin: -3px 0 0 -3px; font-size: 12px; line-height: 19px; padding: 0 5px; border-radius: 10px; color: #fff; font-weight: normal; background-color: #555; display: inline-block; }
        .login-form { margin-top: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; color: #a0a0aa; }
        input[type="text"], input[type="password"] { width: 100%; padding: 10px; font-size: 1rem; font-weight: 500; border: 1px solid #eceef1; border-radius: 3px; box-sizing: border-box; }
        input[type="checkbox"] { margin-right: 5px; }
        .button { display: inline-block; background: #0073aa; color: white; border: none; padding: 10px 15px; border-radius: 3px; cursor: pointer; width: 100%; font-size: 16px; }
        .button:hover { background: #005f8b; }
        .secondary { width: auto; background-color: #eff2f4; color: #666; font-size: 14px; min-width: 150px; }
        .secondary:hover { background-color: #eff2f4; color: #333; }
        .login-error { color: #d63638; background: #ffebe8; border: 1px solid #d63638; padding: 10px; margin-bottom: 15px; border-radius: 3px; }
        .login-message { color: #00a32a; background: #edfaef; border: 1px solid #00a32a; padding: 10px; margin-bottom: 15px; border-radius: 3px; }
        .login-footer { margin-top: 15px; font-size: 14px; color: #777; display: flex; align-items: center; justify-content: space-between; }
        .login-footer a { color: #0073aa; text-decoration: none; }
        .login-footer a:hover { text-decoration: underline; }
        #send-verify-code-btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .countdown { color: #999; font-size: 12px; }

        /* Captcha Styles */
        #captcha-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: #f9fafd; z-index: 9999; justify-content: center; align-items: flex-start; }
        .captcha-box { background: #fff; padding: 20px; border-radius: 5px; width: 340px; box-shadow: 0 3px 5px rgba(0, 0, 0, 0.05); transform: translateY(-10%); animation: captchashow 0.3s ease-out forwards; }
        .mbody .captcha-box { border-radius: 14px; }
        .captcha-img-box { position: relative; width: 300px; height: 150px; background: #eee; margin: 10px auto; overflow: hidden; border-radius: 4px; }
        .captcha-img-box:before { content: '☯'; left: 50%; top: 50%; position: absolute; transform: translate(-50%, -50%); z-index: 0; font-size: 50px; line-height: 0; vertical-align: middle; opacity: 0.1; animation: spin 1s linear infinite; }
        @keyframes spin {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }
        @keyframes captchashow {
            0% { transform: translateY(-10%); }
            100% { transform: translateY(150px); }
        }
        #captcha-piece { position: absolute; top: 0; left: 0; height: 100%; z-index: 2; }
        .captcha-slider-box { position: relative; width: 285px; height: 40px; padding: 0 5px; background: #f7f9fa; margin: 15px auto 10px; border-radius: 20px; border: 1px solid #e4e7eb; }
        #captcha-slider-bar { position: absolute; left: -1px; top: -1px; height: 42px; width: 0; background: #d1e9ff; border-radius: 20px 0 0 20px; border: 1px solid #1991fa; border-right: 0; box-sizing: border-box; }
        #captcha-slider-btn { position: absolute; left: -1px; top: -1px; height: 40px; width: 40px; background: #fff; border-radius: 50%; box-shadow: 0 0 5px rgba(0, 0, 0, 0.2); cursor: pointer; text-align: center; display: flex; align-items: center; justify-content: center; color: #666; border: 1px solid #ddd; z-index: 3; transition: background 0.2s; -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; }
        #captcha-slider-btn:hover { background: #1991fa; color: #fff; border-color: #1991fa; }
        .captcha-text { text-align: center; line-height: 40px; color: #999; font-size: 12px; user-select: none; text-indent: 30px; overflow: hidden; height: 100%; }
        .captcha-footer { text-align: right; }
        .captcha-btn { background: none; border: none; cursor: pointer; font-size: 13px; padding: 5px 10px; }
        #captcha-refresh { color: #0073aa; }
        #captcha-close { color: #999; }
        .mbody .login-container { border-radius: 14px; }
    </style>
    <?php $asset_ver = function_exists('todo_get_cms_version') ? todo_get_cms_version() : (defined('TODO_VERSION') ? TODO_VERSION : '1.0.0'); ?>
    <link rel="stylesheet" href="<?php echo todo_admin_url('assets/css/custom.css'); ?>?v=<?php echo $asset_ver; ?>">
</head>
<body<?php echo (function_exists('todo_is_mobile') && todo_is_mobile()) ? ' class="mbody"' : ''; ?>>
    <div id="captcha-modal">
        <div class="captcha-box">
            <h3 style="margin:0 0 15px; font-size:16px; font-weight: normal;">安全验证</h3>
            <div class="captcha-img-box">
                <img id="captcha-bg" style="width:100%; height:100%; display:block; position: relative;">
                <img id="captcha-piece" style="position:absolute; top:0; left:0; height:50px;">
            </div>
            <div class="captcha-slider-box">
                <div id="captcha-slider-bar"></div>
                <div id="captcha-slider-btn">➝</div>
                <div class="captcha-text">拖动滑块完成拼图</div>
            </div>
            <div class="captcha-footer">
                 <button type="button" id="captcha-close" class="captcha-btn">关闭</button>
                 <button type="button" id="captcha-refresh" class="captcha-btn">刷新</button>
            </div>
        </div>
    </div>
    <div class="login-container">
        <div class="login-logo">
            <h1><?php echo $site_title; ?><?php if (defined('TODO_DEMO_LOGIN_PROXY') && TODO_DEMO_LOGIN_PROXY): ?><span>demo</span><?php endif; ?></h1>
        </div>
        
        <div class="login-tips login-error" style="<?php echo empty($login_error) ? 'display: none;' : ''; ?>">
            <?php echo $login_error; ?>
        </div>
        
        <div class="login-tips login-message" style="<?php echo empty($login_message) ? 'display: none;' : ''; ?>">
            <?php 
            if (!empty($login_message)) {
                switch ($login_message) {
                    case 'loggedout':
                        echo '您已成功退出登录。';
                        break;
                    case 'password_reset':
                        echo '您的密码已重置。';
                        break;
                    case 'session_expired':
                        echo '您的会话已过期，请重新登录。';
                        break;
                    case 'upgrade_completed':
                    case '版本更新已完成，请重新登录':
                        echo '版本更新已完成，请重新登录';
                        break;
                    default:
                        // 不再显示任何未在白名单中的外部信息，防止钓鱼
                        break;
                }
            }
            ?>
        </div>
        
        <?php
            $is_demo_login = (defined('TODO_DEMO_LOGIN_PROXY') && TODO_DEMO_LOGIN_PROXY);
            $_todo_entry = todo_get_admin_entry_filename();
            $login_endpoint = $is_demo_login
                ? todo_admin_url('login.php')
                : todo_admin_url(($_todo_entry ?: basename(__FILE__, '.php')) . '.php');
        ?>
        <form id="todo-login-form" class="login-form" method="post" action="<?php echo $login_endpoint; ?>">
            <input type="hidden" name="action" value="login">
            <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($redirect_to); ?>">
            <?php echo Todo_Security_Manager::csrf_field('login_action', '_todononce'); ?>
            
            <div class="form-group">
                <label for="username">账号 / 邮箱</label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" required autofocus autocomplete="username">
            </div>
            
            <div class="form-group">
                <label for="password">密码</label>
                <div style="position: relative;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" style="position: absolute; right: 0; top: 0; padding: 12px; opacity: 0.2; cursor: pointer;" onmouseover="this.style.opacity='1';" onmouseout="this.style.opacity='0.2';" onclick="var p = this.nextElementSibling; p.type = p.type === 'password' ? 'text' : 'password';">
                        <path d="M0 0h24v24H0z" fill="none" />
                        <path fill="currentColor" d="M2 5.27L3.28 4L20 20.72L18.73 22l-3.08-3.08c-1.15.38-2.37.58-3.65.58c-5 0-9.27-3.11-11-7.5c.69-1.76 1.79-3.31 3.19-4.54zM12 9a3 3 0 0 1 3 3a3 3 0 0 1-.17 1L11 9.17A3 3 0 0 1 12 9m0-4.5c5 0 9.27 3.11 11 7.5a11.8 11.8 0 0 1-4 5.19l-1.42-1.43A9.86 9.86 0 0 0 20.82 12A9.82 9.82 0 0 0 12 6.5c-1.09 0-2.16.18-3.16.5L7.3 5.47c1.44-.62 3.03-.97 4.7-.97M3.18 12A9.82 9.82 0 0 0 12 17.5c.69 0 1.37-.07 2-.21L11.72 15A3.064 3.064 0 0 1 9 12.28L5.6 8.87c-.99.85-1.82 1.91-2.42 3.13" />
                    </svg>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                </div>
            </div>
                      
            <?php
            // 检查是否启用邮箱验证
            $email_verify_enabled = defined('TODO_ADMIN_LOGIN_EMAIL_VERIFY') ? TODO_ADMIN_LOGIN_EMAIL_VERIFY : true;
            if (defined('TODO_DEMO_LOGIN_PROXY') && TODO_DEMO_LOGIN_PROXY) {
                $email_verify_enabled = false;
            }
            ?>
            <?php if ($email_verify_enabled): ?>
            <div class="form-group" id="email-verify-group">
                <label for="verification_code">邮箱验证</label>
                <div style="display: flex; gap: 10px;">
                    <input type="text" id="verification_code" name="verification_code" maxlength="6" style="flex: 1;letter-spacing: 3px;" pattern="[0-9]{6}" autocomplete="one-time-code">
                    <button type="button" id="send-verify-code-btn" class="button secondary">发送验证码</button>
                </div>
                <p class="description" id="verify-code-hint" style="margin: 5px 0 0;color: #666;font-size: 13px;"></p>
            </div>
            <?php endif; ?>
            
            <div class="form-group" style="margin-top: 30px;">
                <button type="submit" id="login-submit-btn" class="button">登 录</button>
            </div>
        </form>
        
        <div class="login-footer">
            <a href="<?php echo todo_get_site_url(); ?>">前台主页</a>
        </div>
    </div>
    
    <script type="text/javascript">
    (function() {
        // TodoCMS Utilities polyfill
        var TodoCMS = window.TodoCMS || {};
        TodoCMS.Utils = TodoCMS.Utils || {
            showMessage: function(msg, type) {
                this.hideMessage();
                if (msg) {
                    var isSuccess = type === 'success';
                    var tipEl = document.querySelector('.login-tips.login-' + (isSuccess ? 'message' : 'error'));
                    if (tipEl) {
                        tipEl.innerHTML = msg;
                        tipEl.style.display = 'block';
                    }
                }
            },
            hideMessage: function() {
                var tips = document.querySelectorAll('.login-tips');
                for (var i = 0; i < tips.length; i++) {
                    tips[i].style.display = 'none';
                    tips[i].innerHTML = '';
                }
            }
        };

        // Captcha Logic
        var Captcha = {
            modal: document.getElementById('captcha-modal'),
            bg: document.getElementById('captcha-bg'),
            piece: document.getElementById('captcha-piece'),
            sliderBtn: document.getElementById('captcha-slider-btn'),
            sliderBar: document.getElementById('captcha-slider-bar'),
            refreshBtn: document.getElementById('captcha-refresh'),
            closeBtn: document.getElementById('captcha-close'),
            isDragging: false,
            startX: 0,
            onSuccess: null,
            powSeed: null,
            powDifficulty: 3,
            trackData: [],

            sha256: function(str) {
                function rightRotate(value, amount) {
                    return (value >>> amount) | (value << (32 - amount));
                }
                var mathPow = Math.pow;
                var maxWord = mathPow(2, 32);
                var i, j;
                var result = "";
                var bytes = [];
                var h = [
                    0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
                    0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19
                ];
                var k = [
                    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
                    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
                    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
                    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
                    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
                    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
                    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
                    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
                ];
                var asciiBitLength = str.length * 8;
                for (i = 0; i < str.length; i++) bytes.push(str.charCodeAt(i));
                bytes.push(0x80);
                while ((bytes.length % 64) !== 56) bytes.push(0);
                var words = [];
                for (i = 0; i < bytes.length; i += 4) {
                    words.push((bytes[i] << 24) | (bytes[i + 1] << 16) | (bytes[i + 2] << 8) | (bytes[i + 3]));
                }
                words.push(Math.floor(asciiBitLength / maxWord));
                words.push(asciiBitLength & 0xffffffff);
                for (j = 0; j < words.length;) {
                    var w = words.slice(j, j += 16), oldH = h.slice(0);
                    for (i = 0; i < 64; i++) {
                        var w15 = w[i - 15], w2 = w[i - 2], a = h[0], e = h[4];
                        var s0 = (rightRotate(w15, 7) ^ rightRotate(w15, 18) ^ (w15 >>> 3));
                        var s1 = (rightRotate(w2, 17) ^ rightRotate(w2, 19) ^ (w2 >>> 10));
                        if (i >= 16) w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
                        var S1 = (rightRotate(e, 6) ^ rightRotate(e, 11) ^ rightRotate(e, 25));
                        var ch = ((e & h[5]) ^ ((~e) & h[6]));
                        var temp1 = (h[7] + S1 + ch + k[i] + w[i]) | 0;
                        var S0 = (rightRotate(a, 2) ^ rightRotate(a, 13) ^ rightRotate(a, 22));
                        var maj = ((a & h[1]) ^ (a & h[2]) ^ (h[1] & h[2]));
                        var temp2 = (S0 + maj) | 0;
                        h[7] = h[6]; h[6] = h[5]; h[5] = h[4]; h[4] = (h[3] + temp1) | 0;
                        h[3] = h[2]; h[2] = h[1]; h[1] = h[0]; h[0] = (temp1 + temp2) | 0;
                    }
                    for (i = 0; i < 8; i++) h[i] = (h[i] + oldH[i]) | 0;
                }
                for (i = 0; i < 8; i++) {
                    var hex = (h[i] >>> 0).toString(16);
                    result += ("00000000" + hex).slice(-8);
                }
                return result;
            },

            solvePoW: function() {
                if (!this.powSeed) return '0';
                var prefix = '0'.repeat(this.powDifficulty || 3);
                var nonce = 0;
                while (nonce < 100000) {
                    var hashVal = this.sha256(this.powSeed + nonce);
                    if (hashVal && hashVal.indexOf(prefix) === 0) {
                        return nonce;
                    }
                    nonce++;
                }
                return nonce;
            },
            
            init: function() {
                var self = this;
                this.refreshBtn.addEventListener('click', function() { self.load(); });
                this.closeBtn.addEventListener('click', function() { self.hide(); });
                
                this.sliderBtn.addEventListener('mousedown', function(e) { self.startDrag(e); });
                this.sliderBtn.addEventListener('touchstart', function(e) { self.startDrag(e); }, { passive: false });
                document.addEventListener('mousemove', function(e) { self.onDrag(e); });
                document.addEventListener('touchmove', function(e) { self.onDrag(e); }, { passive: false });
                document.addEventListener('mouseup', function(e) { self.stopDrag(e); });
                document.addEventListener('touchend', function(e) { self.stopDrag(e); });
            },
            
            show: function(callback) {
                this.onSuccess = callback;
                this.modal.style.display = 'flex';
                this.load();
            },
            
            hide: function() {
                this.modal.style.display = 'none';
            },
            
            load: function() {
                var self = this;
                this.trackData = [];
                // Reset slider
                this.sliderBtn.style.left = '-1px';
                this.sliderBar.style.width = '0px';
                this.sliderBar.style.borderWidth = '0px';
                this.piece.style.left = '-1px';
                
                var formData = new FormData();
                formData.append('action', 'get_captcha');
                
                fetch('<?php echo $login_endpoint; ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.bg) {
                        self.bg.src = data.bg;
                        self.piece.src = data.piece;
                        self.piece.style.top = data.y + 'px'; 
                        self.powSeed = data.pow_seed || null;
                        self.powDifficulty = data.pow_difficulty || 3;
                    } else {
                        self.hide();
                        TodoCMS.Utils.showMessage(data.message || '加载验证码失败', 'error');
                    }
                })
                .catch(function(err) {
                    self.hide();
                    TodoCMS.Utils.showMessage('加载验证码失败，请检查登录地址后重试', 'error');
                });
            },
            
            startDrag: function(e) {
                this.isDragging = true;
                this.startX = (e.clientX !== undefined) ? e.clientX : (e.touches ? e.touches[0].clientX : 0);
                var startY = (e.clientY !== undefined) ? e.clientY : (e.touches ? e.touches[0].clientY : 0);
                this.trackData = [{ x: 0, y: startY, t: Date.now() }];
            },
            
            onDrag: function(e) {
                if (!this.isDragging) return;
                var clientX = (e.clientX !== undefined) ? e.clientX : (e.touches ? e.touches[0].clientX : 0);
                var clientY = (e.clientY !== undefined) ? e.clientY : (e.touches ? e.touches[0].clientY : 0);
                var moveX = clientX - this.startX;
                if (moveX < 0) moveX = 0;
                if (moveX > 260) moveX = 260; // 300 - 40
                
                this.trackData.push({
                    x: Math.round(moveX),
                    y: Math.round(clientY),
                    t: Date.now()
                });

                this.sliderBtn.style.left = moveX * 0.98 + 'px';
                this.sliderBar.style.width = moveX + 20 + 'px';
                this.sliderBar.style.borderWidth = '1px';
                this.piece.style.left = moveX * 0.96 + 'px';
                
                // 阻止移动端页面滚动
                if (e.cancelable) e.preventDefault();
            },
            
            stopDrag: function(e) {
                if (!this.isDragging) return;
                this.isDragging = false;
                
                var x = parseInt(this.sliderBtn.style.left);
                this.verify(x);
            },
            
            verify: function(x) {
                var self = this;
                var powNonce = this.solvePoW();

                var formData = new FormData();
                formData.append('action', 'verify_captcha');
                formData.append('x', x);
                formData.append('pow_nonce', powNonce);
                formData.append('track', JSON.stringify(this.trackData));
                
                fetch('<?php echo $login_endpoint; ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        self.hide();
                        if (self.onSuccess) self.onSuccess();
                    } else {
                        // 如果被锁定，不要自动重载，直接报错
                        if (data.message && data.message.indexOf('频繁') !== -1) {
                            self.hide();
                            TodoCMS.Utils.showMessage(data.message, 'error');
                        } else {
                            TodoCMS.Utils.showMessage(data.message || '验证失败，请重试', 'error');
                            self.load();
                        }
                    }
                })
                .catch(function(err) {
                    TodoCMS.Utils.showMessage('验证请求失败，请重试', 'error');
                    self.load();
                });
            }
        };
        Captcha.init();

        var emailVerifyEnabled = <?php echo $email_verify_enabled ? 'true' : 'false'; ?>;
        var loginForm   = document.getElementById('todo-login-form');
        var sendBtn     = document.getElementById('send-verify-code-btn');
        var usernameInput = document.getElementById('username');
        var passwordInput = document.getElementById('password');
        var verifyCodeInput = document.getElementById('verification_code');
        var verifyHint  = document.getElementById('verify-code-hint');
        var submitBtn   = document.getElementById('login-submit-btn');
        var countdown   = 0;
        var countdownTimer = null;
        var SS_KEY = 'todo_login_cd'; // sessionStorage key for countdown end timestamp

        // ---------- 消息提示 ----------
        function showTip(msg, type) { TodoCMS.Utils.showMessage(msg, type); }
        function hideTip()          { TodoCMS.Utils.hideMessage(); }

        // ---------- 倒计时 ----------
        function startCountdown(seconds) {
            if (countdownTimer) clearInterval(countdownTimer);
            countdown = Math.max(0, Math.round(seconds));
            if (countdown <= 0) { resetSendBtn(); return; }
            // 记录结束时间戳，避免刷新后重置
            sessionStorage.setItem(SS_KEY, Date.now() + countdown * 1000);
            tickCountdown();
            countdownTimer = setInterval(tickCountdown, 1000);
        }

        function tickCountdown() {
            var end = parseInt(sessionStorage.getItem(SS_KEY) || '0');
            countdown = Math.ceil((end - Date.now()) / 1000);
            if (countdown <= 0) {
                clearInterval(countdownTimer);
                countdownTimer = null;
                sessionStorage.removeItem(SS_KEY);
                resetSendBtn();
                verifyHint.textContent = '';
            } else {
                sendBtn.disabled = true;
                sendBtn.textContent = '重新发送 ' + countdown + 's';
            }
        }

        function resetSendBtn() {
            sendBtn.disabled = false;
            sendBtn.textContent = '发送验证码';
        }

        // ---------- 校验凭证是否填写 ----------
        function checkCredentials() {
            return usernameInput.value.trim() !== '' && passwordInput.value !== '';
        }

        // ---------- 监听输入，隐藏旧消息 ----------
        usernameInput.addEventListener('input', function() { if (checkCredentials()) hideTip(); });
        passwordInput.addEventListener('input', function() { if (checkCredentials()) hideTip(); });
        if (verifyCodeInput) {
            verifyCodeInput.addEventListener('input', function() { if (verifyCodeInput.value.trim()) hideTip(); });
        }

        // ---------- 页面加载时恢复倒计时 ----------
        if (sendBtn && verifyCodeInput) {
            (function resumeCountdown() {
                var end = parseInt(sessionStorage.getItem(SS_KEY) || '0');
                var remaining = Math.ceil((end - Date.now()) / 1000);
                if (remaining > 0) {
                    verifyCodeInput.required = true;
                    countdownTimer = setInterval(tickCountdown, 1000);
                    tickCountdown();
                }
            })();
        }

        // ---------- 发送验证码 ----------
        if (sendBtn) {
            sendBtn.addEventListener('click', function() {
            if (!checkCredentials()) {
                showTip('请输入用户名和密码', 'error'); return;
            }
            // 触发人机验证
            Captcha.show(function() {
                var username = usernameInput.value.trim();
                var password = passwordInput.value;
                var userEmail = '';

                var fd = new FormData();
                fd.append('action', 'check_credentials');
                fd.append('username', username);
                fd.append('password', password);
                fd.append('_todononce', document.querySelector('input[name="_todononce"]').value);

                sendBtn.disabled = true;
                sendBtn.textContent = '验证中...';

                fetch('<?php echo $login_endpoint; ?>', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) throw new Error(data.message || '用户名或密码错误');
                    userEmail = data.email;
                    var fd2 = new FormData();
                    fd2.append('action', 'send_login_code');
                    fd2.append('email', data.email);
                    fd2.append('_todononce', document.querySelector('input[name="_todononce"]').value);
                    return fetch('<?php echo $login_endpoint; ?>', { method: 'POST', body: fd2 });
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) throw new Error(data.message || '发送失败');
                    if (verifyCodeInput) verifyCodeInput.required = true;
                    var parts = userEmail.split('@');
                    var n = parts[0];
                    var masked = (n.length > 3 ? n.substring(0,3) : n.substring(0,1)) + '***';
                    if (verifyHint) verifyHint.textContent = '验证码已发送到 ' + masked + '@' + (parts[1] || '') + '，请查收';
                    startCountdown(60);
                    hideTip();
                })
                .catch(function(err) {
                    showTip(err.message || '发送验证码失败，请重试', 'error');
                    resetSendBtn();
                    if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
                });
            });
        });
        }

        // ---------- 表单 AJAX 提交 ----------
        function doSubmit() {
            submitBtn.disabled = true;
            submitBtn.textContent = '登录中...';

            var fd = new FormData(loginForm);
            fetch('<?php echo $login_endpoint; ?>', { method: 'POST', body: fd })
            .then(function(r) {
                var ct = r.headers.get('Content-Type') || '';
                if (ct.indexOf('application/json') !== -1) {
                    return r.json().then(function(data) {
                        throw new Error(data.message || '登录失败');
                    });
                }
                if (r.url && r.url.indexOf('page=dashboard') !== -1) {
                    sessionStorage.removeItem(SS_KEY);
                    window.location.href = r.url;
                    return;
                }
                return r.text().then(function(html) {
                    var parser = new DOMParser();
                    var doc = parser.parseFromString(html, 'text/html');
                    var errEl = doc.querySelector('.login-error');
                    var errMsg = errEl ? errEl.textContent.trim() : '';
                    if (errMsg) {
                        throw new Error(errMsg);
                    }
                    window.location.reload();
                });
            })
            .catch(function(err) {
                showTip(err.message || '登录失败，请重试', 'error');
                submitBtn.disabled = false;
                submitBtn.textContent = '登录';
            });
        }

        loginForm.addEventListener('submit', function(e) {
            e.preventDefault();
            hideTip();
            
            if (emailVerifyEnabled) {
                doSubmit();
            } else {
                Captcha.show(function() {
                    doSubmit();
                });
            }
        });

        // 初始化发送按钮状态（根据输入实时联动）
        function updateSendBtn() {
            if (!sendBtn) return;
            if (countdownTimer || (parseInt(sessionStorage.getItem(SS_KEY) || '0') > Date.now())) {
                return; // 倒计时进行中，不干预
            }
            sendBtn.disabled = !checkCredentials();
        }
        usernameInput.addEventListener('input', updateSendBtn);
        passwordInput.addEventListener('input', updateSendBtn);
        updateSendBtn(); // 初始化一次
    })();
    </script>
</body>
</html>
<?php
// 结束页面输出
exit;
