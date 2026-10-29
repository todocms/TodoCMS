/**
 * 会员注册页面JavaScript
 */

$(document).ready(function() {
    // 初始化页面
    initRegisterPage();
    
    // 绑定事件
    bindEvents();
});

/**
 * 初始化注册页面
 */
function initRegisterPage() {
    // 检查URL参数
    var urlParams = new URLSearchParams(window.location.search);
    var registerType = urlParams.get('type');
    
    if (registerType === 'mobile') {
        switchTab('mobile');
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
    
    // 账号注册表单提交
    $('#accountRegisterForm').on('submit', function(e) {
        e.preventDefault();
        submitAccountRegister();
    });
    
    // 手机注册表单提交
    $('#mobileRegisterForm').on('submit', function(e) {
        e.preventDefault();
        submitMobileRegister();
    });
    
    // 验证码图片点击刷新
    $('.captcha-img').on('click', function() {
        refreshCaptcha($(this));
    });
    
    // 发送短信验证码
    $('.send-sms-btn').on('click', function() {
        sendSmsCode('register');
    });
    
    // 用户名输入验证
    $('#username').on('blur', function() {
        validateUsername($(this).val());
    });
    
    // 邮箱输入验证
    $('#email').on('blur', function() {
        validateEmail($(this).val());
    });
    
    // 手机号输入验证
    $('#mobile, #mobile_phone').on('input', function() {
        validateMobile($(this).val());
    });
    
    // 密码确认验证
    $('#password_confirm').on('blur', function() {
        validatePasswordConfirm();
    });
    
    // 密码强度显示
    $('#password').on('input', function() {
        showPasswordStrength($(this).val());
    });
    
    // Enter键提交
    $('.register-form input').on('keypress', function(e) {
        if (e.which === 13) {
            $(this).closest('form').submit();
        }
    });
}

/**
 * 切换注册方式
 */
function switchTab(tab) {
    $('.tab-btn').removeClass('active');
    $('.register-form').removeClass('active');
    
    $('[data-tab="' + tab + '"]').addClass('active');
    $('#' + tab + 'RegisterForm').addClass('active');
    
    // 清空表单
    $('.register-form')[0].reset();
    $('.field-error').removeClass('field-error');
    $('.error-message').remove();
    $('.success-message').remove();
}

/**
 * 账号注册提交
 */
function submitAccountRegister() {
    var $form = $('#accountRegisterForm');
    var $btn = $form.find('.register-btn');
    
    // 表单验证
    if (!validateAccountRegisterForm()) {
        return false;
    }
    
    // 显示加载状态
    showLoading($btn, __t('注册中'));
    
    // 提交数据
    $.ajax({
        url: $form.attr('action'),
        type: 'POST',
        data: $form.serialize(),
        success: function(response) {
            if (response.success) {
                layer.msg(response.message || __t('注册成功'), {icon: 1}, function() {
                    window.location.href = response.data && response.data.redirect_url ? response.data.redirect_url : ((((document.querySelector('meta[name="site-url"]')?.getAttribute('content')) || window.location.origin).replace(/\/+$/, '')) + '/?user=profile');
                });
            } else {
                layer.msg(response.message || __t('注册失败'), {icon: 2});
                refreshCaptcha($('#accountRegisterForm .captcha-img'));
            }
        },
        error: function() {
            layer.msg(__t('网络错误'), {icon: 2});
        },
        complete: function() {
            hideLoading($btn, __t('注册'));
        }
    });
}

/**
 * 手机注册提交
 */
function submitMobileRegister() {
    var $form = $('#mobileRegisterForm');
    var $btn = $form.find('.register-btn');
    
    // 表单验证
    if (!validateMobileRegisterForm()) {
        return false;
    }
    
    // 显示加载状态
    showLoading($btn, __t('注册中'));
    
    // 提交数据
    $.ajax({
        url: $form.attr('action'),
        type: 'POST',
        data: $form.serialize(),
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                layer.msg(response.message || __t('注册成功'), {icon: 1}, function() {
                    window.location.href = response.data && response.data.redirect_url ? response.data.redirect_url : ((((document.querySelector('meta[name="site-url"]')?.getAttribute('content')) || window.location.origin).replace(/\/+$/, '')) + '/?user=profile');
                });
            } else {
                layer.msg(response.message || __t('注册失败'), {icon: 2});
                refreshCaptcha($('#mobileRegisterForm .captcha-img'));
            }
        },
        error: function() {
            layer.msg(__t('网络错误'), {icon: 2});
        },
        complete: function() {
            hideLoading($btn, __t('注册'));
        }
    });
}

/**
 * 验证账号注册表单
 */
function validateAccountRegisterForm() {
    var isValid = true;
    
    // 清除之前的错误
    $('.field-error').removeClass('field-error');
    $('.error-message').remove();
    
    // 验证用户名
    var username = $('#username').val().trim();
    if (!username) {
        showFieldError('#username', __t('请输入用户名'));
        isValid = false;
    } else if (username.length < 3 || username.length > 20) {
        showFieldError('#username', __t('用户名长度为3-20个字符'));
        isValid = false;
    } else if (!/^[a-zA-Z0-9_\u4e00-\u9fa5]+$/.test(username)) {
        showFieldError('#username', __t('用户名只能包含中英文、数字、下划线'));
        isValid = false;
    }
    
    // 验证密码
    var password = $('#password').val();
    if (!password) {
        showFieldError('#password', __t('请输入密码'));
        isValid = false;
    } else if (password.length < 6 || password.length > 20) {
        showFieldError('#password', __t('密码长度为6-20个字符'));
        isValid = false;
    }
    
    // 验证重复密码
    var passwordConfirm = $('#password_confirm').val();
    if (!passwordConfirm) {
        showFieldError('#password_confirm', __t('请确认密码'));
        isValid = false;
    } else if (password !== passwordConfirm) {
        showFieldError('#password_confirm', __t('两次输入的密码不一致'));
        isValid = false;
    }
    
    // 验证手机号
    var mobile = $('#mobile').val().trim();
    if (!mobile) {
        showFieldError('#mobile', __t('请输入手机号码'));
        isValid = false;
    } else if (!validateMobile(mobile)) {
        showFieldError('#mobile', __t('请输入正确的手机号码'));
        isValid = false;
    }
    
    // 验证邮箱
    var email = $('#email').val().trim();
    if (!email) {
        showFieldError('#email', __t('请输入邮箱地址'));
        isValid = false;
    } else if (!validateEmail(email)) {
        showFieldError('#email', __t('请输入正确的邮箱地址'));
        isValid = false;
    }
    
    // 验证验证码
    var captcha = $('#captcha').val().trim();
    if (!captcha) {
        showFieldError('#captcha', __t('请输入验证码'));
        isValid = false;
    }
    
    // 验证协议同意
    if (!$('input[name="agreement"]').is(':checked')) {
        layer.msg(__t('请同意用户协议和隐私政策'), {icon: 2});
        isValid = false;
    }
    
    return isValid;
}

/**
 * 验证手机注册表单
 */
function validateMobileRegisterForm() {
    var isValid = true;
    
    // 清除之前的错误
    $('.field-error').removeClass('field-error');
    $('.error-message').remove();
    
    // 验证手机号
    var mobile = $('#mobile_phone').val().trim();
    if (!mobile) {
        showFieldError('#mobile_phone', __t('请输入手机号码'));
        isValid = false;
    } else if (!validateMobile(mobile)) {
        showFieldError('#mobile_phone', __t('请输入正确的手机号码'));
        isValid = false;
    }
    
    // 验证图形验证码
    var captcha = $('#mobile_captcha').val().trim();
    if (!captcha) {
        showFieldError('#mobile_captcha', __t('请输入图形验证码'));
        isValid = false;
    }
    
    // 验证短信验证码
    var smsCode = $('#sms_code_register').val().trim();
    if (!smsCode) {
        showFieldError('#sms_code_register', __t('请输入短信验证码'));
        isValid = false;
    } else if (!/^\d{6}$/.test(smsCode)) {
        showFieldError('#sms_code_register', __t('请输入短信验证码'));
        isValid = false;
    }
    
    // 验证协议同意
    if (!$('#mobileRegisterForm input[name="agreement"]').is(':checked')) {
        layer.msg(__t('请同意用户协议和隐私政策'), {icon: 2});
        isValid = false;
    }
    
    return isValid;
}

/**
 * 验证用户名唯一性
 */
function validateUsername(username) {
    if (!username || username.length < 3) {
        return;
    }
    
    $.ajax({
        url: (window.TODO && window.TODO.join ? window.TODO.join(window.TODO.API_BASE || (((document.querySelector('meta[name="site-url"]')?.getAttribute('content')) || window.location.origin).replace(/\/+$/, '') + '/api'), '/user/check-username') : ((((document.querySelector('meta[name="site-url"]')?.getAttribute('content')) || window.location.origin).replace(/\/+$/, '') + '/api/user/check-username'))),
        type: 'POST',
        data: {
            username: username,
            _csrf_token: $('input[name="_csrf_token"]').val()
        },
        dataType: 'json',
        success: function(response) {
            if (!response.available) {
                showFieldError('#username', __t('用户名已存在'));
            } else {
                showFieldSuccess('#username', __t('用户名可用'));
            }
        }
    });
}

/**
 * 验证邮箱唯一性
 */
function validateEmail(email) {
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        return false;
    }
    
    $.ajax({
        url: (window.TODO && window.TODO.join ? window.TODO.join(window.TODO.API_BASE || (((document.querySelector('meta[name="site-url"]')?.getAttribute('content')) || window.location.origin).replace(/\/+$/, '') + '/api'), '/user/check-email') : ((((document.querySelector('meta[name="site-url"]')?.getAttribute('content')) || window.location.origin).replace(/\/+$/, '') + '/api/user/check-email'))),
        type: 'POST',
        data: {
            email: email,
            _csrf_token: $('input[name="_csrf_token"]').val()
        },
        dataType: 'json',
        success: function(response) {
            if (!response.available) {
                showFieldError('#email', __t('邮箱已存在'));
            } else {
                showFieldSuccess('#email', __t('邮箱可用'));
            }
        }
    });
    
    return true;
}

/**
 * 验证手机号格式
 */
function validateMobile(mobile) {
    return /^1[3-9]\d{9}$/.test(mobile);
}

/**
 * 验证密码确认
 */
function validatePasswordConfirm() {
    var password = $('#password').val();
    var passwordConfirm = $('#password_confirm').val();
    
    if (passwordConfirm && password !== passwordConfirm) {
        showFieldError('#password_confirm', __t('两次输入的密码不一致'));
        return false;
    } else if (passwordConfirm && password === passwordConfirm) {
        showFieldSuccess('#password_confirm', __t('密码确认正确'));
        return true;
    }
}

/**
 * 显示密码强度
 */
function showPasswordStrength(password) {
    if (!password) {
        return;
    }
    
    var strength = 0;
    var $strengthIndicator = $('#password').siblings('.password-strength');
    
    // 如果没有强度指示器，创建一个
    if ($strengthIndicator.length === 0) {
        $('#password').after('<div class="password-strength"><div class="strength-bar"></div><span class="strength-text"></span></div>');
        $strengthIndicator = $('#password').siblings('.password-strength');
    }
    
    // 计算强度
    if (password.length >= 6) strength++;
    if (password.length >= 8) strength++;
    if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
    if (/\d/.test(password)) strength++;
    if (/[^a-zA-Z\d]/.test(password)) strength++;
    
    var strengthText = [__t('很弱'), __t('弱'), __t('一般'), __t('强'), __t('很强')][strength];
    var strengthClass = ['very-weak', 'weak', 'fair', 'strong', 'very-strong'][strength];
    
    $strengthIndicator.find('.strength-bar')
        .removeClass('very-weak weak fair strong very-strong')
        .addClass(strengthClass)
        .css('width', (strength * 20) + '%');
    
    $strengthIndicator.find('.strength-text').text(__t('密码强度') + ': ' + strengthText);
}

/**
 * 显示字段错误
 */
function showFieldError(selector, message) {
    var $field = $(selector);
    $field.addClass('field-error');
    
    // 移除已存在的消息
    $field.siblings('.error-message, .success-message').remove();
    
    // 添加错误消息
    $field.after('<div class="error-message">' + message + '</div>');
}

/**
 * 显示字段成功
 */
function showFieldSuccess(selector, message) {
    var $field = $(selector);
    $field.removeClass('field-error');
    
    // 移除已存在的消息
    $field.siblings('.error-message, .success-message').remove();
    
    // 添加成功消息
    $field.after('<div class="success-message">' + message + '</div>');
}

/**
 * 发送短信验证码
 */
function sendSmsCode(type) {
    var mobile = $('#mobile_phone').val().trim();
    var captcha = $('#mobile_captcha').val().trim();
    
    // 验证手机号
    if (!mobile) {
        layer.msg(__t('请输入手机号码'), {icon: 2});
        $('#mobile_phone').focus();
        return;
    }
    
    if (!validateMobile(mobile)) {
        layer.msg(__t('请输入正确的手机号码'), {icon: 2});
        $('#mobile_phone').focus();
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
                layer.msg(__t(response.message || __t('发送失败')), {icon: 2});
                $btn.removeClass('disabled').text(__t('发送验证码'));
                // 刷新图形验证码
                refreshCaptcha($('#mobileRegisterForm .captcha-img'));
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
    var timestamp = new Date().getTime();
    var src = $img.attr('src').split('?')[0];
    $img.attr('src', src + '?' + timestamp);
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
