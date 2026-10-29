/**
 * 会员登录页面JavaScript
 */

$(document).ready(function() {
    // 初始化页面
    initLoginPage();
    
    // 绑定事件
    bindEvents();
});

/**
 * 初始化登录页面
 */
function initLoginPage() {
    // 检查URL参数
    var urlParams = new URLSearchParams(window.location.search);
    var loginType = urlParams.get('type');
    
    if (loginType === 'mobile') {
        switchTab('mobile');
    }
    
    // 记住用户名
    var savedUsername = localStorage.getItem('todo_login_username');
    if (savedUsername) {
        $('#username').val(savedUsername);
    }
}

/**
 * 绑定事件
 */
function bindEvents() {
    // 标签切换
    $('.tab-btn').on('click', function() {
        var tab = $(this).data('tab');
        switchTab(tab);
    });
    
    // 账号登录表单提交
    $('#account-login').on('submit', function(e) {
        e.preventDefault();
        submitAccountLogin();
    });
    
    // 手机登录表单提交
    $('#mobile-login').on('submit', function(e) {
        e.preventDefault();
        submitMobileLogin();
    });
    
    // 验证码图片点击刷新
    $('.captcha-img').on('click', function() {
        refreshCaptcha($(this));
    });
    
    // 发送短信验证码
    $('.send-sms-btn').on('click', function() {
        sendSmsCode('login');
    });
    
    // 手机号输入验证
    $('#mobile').on('input', function() {
        validateMobile($(this).val());
    });
    
    // Enter键提交
    $('.login-form input').on('keypress', function(e) {
        if (e.which === 13) {
            $(this).closest('form').submit();
        }
    });
}

/**
 * 切换登录方式
 */
function switchTab(tab) {
    $('.tab-btn').removeClass('active');
    $('.login-form').removeClass('active');
    
    $('[data-tab="' + tab + '"]').addClass('active');
    $('#' + tab + '-login').addClass('active');
    
    // 清空表单
    $('.login-form')[0].reset();
    $('.field-error').removeClass('field-error');
    $('.error-message').remove();
}

/**
 * 账号登录提交
 */
function submitAccountLogin() {
    var $form = $('#account-login');
    var $btn = $form.find('.login-btn');
    
    // 表单验证
    if (!validateAccountForm()) {
        return false;
    }
    
    // 显示加载状态
    showLoading($btn, __t('登录中'));
    
    // 提交数据
    $.ajax({
        url: $form.attr('action'),
        type: 'POST',
        data: $form.serialize(),
        dataType: 'json',
        beforeSend: function(xhr) {
            // 明确设置AJAX标识，避免服务端直接访问保护触发
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        },
        success: function(response) {
            if (response.success) {
                // 记住用户名
                if ($('input[name="remember"]').is(':checked')) {
                    localStorage.setItem('todo_login_username', $('#username').val());
                }
                
                // 触发登录成功事件，通知其他组件更新（如 header）
                if (typeof window.dispatchEvent !== 'undefined') {
                    var loginEvent = new CustomEvent('todo:user_logged_in', {
                        detail: {
                            user_id: response.data.user_id,
                            username: response.data.username,
                            display_name: response.data.display_name
                        }
                    });
                    window.dispatchEvent(loginEvent);
                }
                
                // 触发 jQuery 事件（兼容性）
                if (typeof $ !== 'undefined') {
                    $(window).trigger('todo:user_logged_in', [response.data]);
                }
                
                layer.msg(__t('登录成功'), {icon: 1}, function() {
                    // 优先使用服务器返回的 redirect_url，如果没有则使用默认重定向
                    var redirect = (response.data && response.data.redirect_url) ? response.data.redirect_url : getRedirectUrl();
                    
                    // 如果当前页面就是登录页面，强制刷新以确保 header 更新
                    if (window.location.pathname.indexOf('/login') !== -1 || window.location.search.indexOf('user=login') !== -1) {
                        // 延迟一下确保事件已触发
                        setTimeout(function() {
                            window.location.href = redirect;
                        }, 100);
                    } else {
                        window.location.href = redirect;
                    }
                });
            } else {
                layer.msg(response.message || __t('登录失败'), {icon: 2});
                refreshCaptcha($('#account-login .captcha-img'));
            }
        },
        error: function(xhr, status, error) {
            // 网络或解析错误，显示详细错误信息用于调试
            var errorMsg = __t('网络错误');
            if (xhr.responseText) {
                try {
                    var errorResponse = JSON.parse(xhr.responseText);
                    if (errorResponse.message) {
                        errorMsg = errorResponse.message;
                    }
                } catch (e) {
                    // 如果不是JSON，可能是HTML错误页面
                    console.error('Login API Error:', xhr.status, xhr.responseText);
                    if (xhr.status === 403) {
                        errorMsg = __t('请求被拒绝');
                    } else if (xhr.status === 404) {
                        errorMsg = __t('登录接口不存在');
                    } else if (xhr.status === 500) {
                        errorMsg = __t('服务器错误');
                    }
                }
            } else {
                console.error('Login API Network Error:', status, error, xhr.status);
            }
            layer.msg(errorMsg, {icon: 2});
            // 刷新验证码，避免旧验证码继续失败
            refreshCaptcha($('#account-login .captcha-img'));
        },
        complete: function() {
            hideLoading($btn, __t('登录'));
        }
    });
}

/**
 * 手机登录提交
 */
function submitMobileLogin() {
    var $form = $('#mobile-login');
    var $btn = $form.find('.login-btn');
    
    // 表单验证
    if (!validateMobileForm()) {
        return false;
    }
    
    // 显示加载状态
    showLoading($btn, __t('登录中'));
    
    // 提交数据
    $.ajax({
        url: $form.attr('action'),
        type: 'POST',
        data: $form.serialize(),
        dataType: 'json',
        beforeSend: function(xhr) {
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        },
        success: function(response) {
            if (response.success) {
                layer.msg(__t('登录成功'), {icon: 1}, function() {
                    // 跳转
                    var redirect = (response.data && response.data.redirect_url) ? response.data.redirect_url : getRedirectUrl();
                    window.location.href = redirect;
                });
            } else {
                layer.msg(response.message || __t('登录失败'), {icon: 2});
                refreshCaptcha($('#mobile-login .captcha-img'));
            }
        },
        error: function(xhr, status) {
            layer.msg(__t('网络错误'), {icon: 2});
            refreshCaptcha($('#mobile-login .captcha-img'));
        },
        complete: function() {
            hideLoading($btn, __t('登录'));
        }
    });
}

/**
 * 验证账号登录表单
 */
function validateAccountForm() {
    var isValid = true;
    
    // 清除之前的错误
    $('.field-error').removeClass('field-error');
    $('.error-message').remove();
    
    // 验证用户名
    var username = $('#username').val().trim();
    if (!username) {
        showFieldError('#username', __t('请输入用户名或邮箱'));
        isValid = false;
    }
    
    // 验证密码
    var password = $('#password').val();
    if (!password) {
        showFieldError('#password', __t('请输入密码'));
        isValid = false;
    } else if (password.length < 6) {
        showFieldError('#password', __t('密码长度不能少于6位'));
        isValid = false;
    }
    
    // 验证验证码
    var captcha = $('#captcha').val().trim();
    if (!captcha) {
        showFieldError('#captcha', __t('请输入验证码'));
        isValid = false;
    }
    
    return isValid;
}

/**
 * 验证手机登录表单
 */
function validateMobileForm() {
    var isValid = true;
    
    // 清除之前的错误
    $('.field-error').removeClass('field-error');
    $('.error-message').remove();
    
    // 验证手机号
    var mobile = $('#mobile').val().trim();
    if (!mobile) {
        showFieldError('#mobile', __t('请输入手机号码'));
        isValid = false;
    } else if (!validateMobile(mobile)) {
        showFieldError('#mobile', __t('请输入正确的手机号码'));
        isValid = false;
    }
    
    // 验证图形验证码
    var captcha = $('#mobile_captcha').val().trim();
    if (!captcha) {
        showFieldError('#mobile_captcha', __t('请输入图形验证码'));
        isValid = false;
    }
    
    // 验证短信验证码
    var smsCode = $('#sms_code').val().trim();
    if (!smsCode) {
        showFieldError('#sms_code', __t('请输入短信验证码'));
        isValid = false;
    } else if (!/^\d{6}$/.test(smsCode)) {
        showFieldError('#sms_code', __t('请输入短信验证码'));
        isValid = false;
    }
    
    return isValid;
}

/**
 * 验证手机号格式
 */
function validateMobile(mobile) {
    return /^1[3-9]\d{9}$/.test(mobile);
}

/**
 * 显示字段错误
 */
function showFieldError(selector, message) {
    var $field = $(selector);
    $field.addClass('field-error');
    
    // 移除已存在的错误消息
    $field.siblings('.error-message').remove();
    
    // 添加错误消息
    $field.after('<div class="error-message">' + message + '</div>');
}

/**
 * 发送短信验证码
 */
function sendSmsCode(type) {
    var mobile = $('#mobile').val().trim();
    var captcha = $('#mobile_captcha').val().trim();
    
    // 验证手机号
    if (!mobile) {
        layer.msg(__t('请输入手机号码'), {icon: 2});
        $('#mobile').focus();
        return;
    }
    
    if (!validateMobile(mobile)) {
        layer.msg(__t('请输入正确的手机号码'), {icon: 2});
        $('#mobile').focus();
        return;
    }
    
    // 验证图形验证码
    if (!captcha) {
        layer.msg(__t('请输入图形验证码'), {icon: 2});
        $('#mobile_captcha').focus();
        return;
    }
    
    var $btn = $('.send-sms-btn');
    
    // 防止重复点击
    if ($btn.hasClass('disabled')) {
        return;
    }
    
    $btn.addClass('disabled').text(__t('发送中'));
    
    $.ajax({
        url: (window.TODO && window.TODO.join ? window.TODO.join(window.TODO.API_BASE || (((document.querySelector('meta[name="site-url"]')?.getAttribute('content')) || window.location.origin).replace(/\/+$/, '') + '/api'), '/sms/send') : ((((document.querySelector('meta[name="site-url"]')?.getAttribute('content')) || window.location.origin).replace(/\/+$/, '') + '/api/sms/send'))),
        type: 'POST',
        data: {
            mobile: mobile,
            captcha: captcha,
            type: type,
            _csrf_token: $('input[name="_csrf_token"]').val()
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                layer.msg(__t('验证码发送成功'), {icon: 1});
                startCountdown($btn, 60);
            } else {
                layer.msg(response.message || __t('发送失败'), {icon: 2});
                $btn.removeClass('disabled').text(__t('发送验证码'));
                // 刷新图形验证码
                refreshCaptcha($('#mobile-login .captcha-img'));
            }
        },
        error: function(xhr) {
            var msg = (xhr && xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : __t('网络错误');
            layer.msg(msg, {icon: 2});
            $btn.removeClass('disabled').text(__t('发送验证码'));
        }
    });
}

/**
 * 开始倒计时
 */
function startCountdown($btn, seconds) {
    var countdown = seconds;
    
    var timer = setInterval(function() {
        $btn.text(countdown + 's' + __t('后重发'));
        countdown--;
        
        if (countdown < 0) {
            clearInterval(timer);
            $btn.removeClass('disabled').text(__t('发送验证码'));
        }
    }, 1000);
}

/**
 * 刷新验证码
 */
function refreshCaptcha($img) {
    var src = $img.attr('src');
    try {
        var url = new URL(src, window.location.origin);
        // 更新/追加时间戳参数，保留其他参数（如 type）
        url.searchParams.set('t', Date.now());
        $img.attr('src', url.toString());
    } catch (e) {
        // 兼容不支持 URL 的环境：手动处理查询参数
        var parts = src.split('?');
        var base = parts[0];
        var paramsStr = parts[1] || '';
        var params = new URLSearchParams(paramsStr);
        params.set('t', Date.now());
        $img.attr('src', base + '?' + params.toString());
    }
}

/**
 * 获取重定向URL
 */
function getRedirectUrl() {
    var urlParams = new URLSearchParams(window.location.search);
    var redirect = urlParams.get('redirect');
    
    if (redirect) {
        return decodeURIComponent(redirect);
    }
    
    var base = (document.querySelector('meta[name="site-url"]') && document.querySelector('meta[name="site-url"]').getAttribute('content')) || window.location.origin;
    base = String(base || '').replace(/\/+$/, '');
    return base + '/?user=profile';
}

/**
 * 显示加载状态
 */
function showLoading($btn, text) {
    $btn.addClass('loading').attr('disabled', true).text(text);
}

/**
 * 隐藏加载状态
 */
function hideLoading($btn, text) {
    $btn.removeClass('loading').attr('disabled', false).text(text);
}
