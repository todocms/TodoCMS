$(document).ready(function() {
    // 全局多语言自动翻译 Helper
    var __t = function(str) {
        if (!str) return '';
        if (window.TODO_LANG && window.TODO_LANG[str]) {
            return window.TODO_LANG[str];
        }
        if (typeof str === 'string' && str.indexOf('[') !== -1 && /\[[a-z]{2}(?:-[A-Z]{2})?\|[^\]]*\]/i.test(str)) {
            var currLang = (document.documentElement.lang || window.TODO_CURR_LANG || 'zh-CN').toLowerCase();
            var shortLang = currLang.substring(0, 2);
            var regExpCurr = new RegExp('\\[(?:' + currLang + '|' + shortLang + ')\\|([^\\]]*)\\]', 'i');
            var match = str.match(regExpCurr);
            if (match) return match[1];
            var matchAny = str.match(/\[[a-z]{2}(?:-[A-Z]{2})?\|([^\]]*)\]/i);
            if (matchAny) return matchAny[1];
        }
        return str;
    };
    window.__t = __t;
    
    let currentStep = 1;
    let resendTimer = 0;
    let resendInterval;
    
    // 步骤切换
    function showStep(step) {
        $('.step').removeClass('active completed');
        $('.step[data-step="' + step + '"]').addClass('active');
        
        for (let i = 1; i < step; i++) {
            $('.step[data-step="' + i + '"]').addClass('completed');
        }
        
        $('.forgot-form').removeClass('active');
        if (step === 1) {
            $('#verify-identity').addClass('active');
        } else if (step === 2) {
            $('#reset-password').addClass('active');
        } else if (step === 3) {
            $('#reset-success').addClass('active');
        }
        
        currentStep = step;
    }
    
    function showMessage(message, type) {
        const messageClass = type === 'error' ? 'error-message' : 'success-message';
        const messageHtml = '<div class="' + messageClass + '">' + message + '</div>';
        
        $('.error-message, .success-message').remove();
        $('.forgot-form.active .form-step').prepend(messageHtml);
        
        if (type === 'success') {
            setTimeout(function() {
                $('.success-message').fadeOut(300, function() {
                    $(this).remove();
                });
            }, 3000);
        }
    }
    
    function showLoading(button, text) {
        button.prop('disabled', true).addClass('loading').text(text);
    }
    
    function hideLoading(button, text) {
        button.prop('disabled', false).removeClass('loading').text(text);
    }
    
    // 重新发送倒计时
    function startResendTimer() {
        resendTimer = 60;
        const $resendBtn = $('#resend-code-btn');
        $resendBtn.prop('disabled', true);
        
        clearInterval(resendInterval);
        resendInterval = setInterval(function() {
            resendTimer--;
            $resendBtn.text(__t('重新发送') + ' ' + resendTimer + 's');
            
            if (resendTimer <= 0) {
                clearInterval(resendInterval);
                $resendBtn.prop('disabled', false).text(__t('重新发送'));
            }
        }, 1000);
    }
    
    // 步骤1：验证身份 (触发表格提交前先触发滑块验证)
    $('#verify-identity').on('submit', function(e) {
        e.preventDefault();
        
        const $form = $(this);
        const $btn = $form.find('.next-btn');
        const account = $('#account').val().trim();
        
        if (!account) {
            showMessage(__t('请输入邮箱或手机号'), 'error');
            return;
        }
        
        // 弹出滑块拼图验证码弹窗
        if (window.Captcha) {
            window.Captcha.show(function() {
                // 拼图验证成功后，自动提交 AJAX 请求发送重置验证码
                sendResetCode($form, $btn, account);
            });
        } else {
            sendResetCode($form, $btn, account);
        }
    });

    function sendResetCode($form, $btn, account) {
        showLoading($btn, __t('发送中'));
        
        $.ajax({
            url: $form.attr('action'),
            type: 'POST',
            data: {
                action: 'send_code',
                account: account,
                captcha: 'bypass', // 滑块拼图已在后端验证通过
                _csrf_token: $form.find('input[name="_csrf_token"]').val()
            },
            dataType: 'json',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            success: function(response) {
                hideLoading($btn, __t('发送验证码'));
                
                if (response.success) {
                    $('#hidden_account').val(account);
                    $('#hidden_type').val(response.data ? response.data.type : 'auto');
                    $('#sent-tip').text(response.message || __t('验证码已发送，请查收'));
                    
                    showMessage(response.message || __t('验证码已发送，请查收'), 'success');
                    
                    setTimeout(function() {
                        showStep(2);
                        startResendTimer();
                    }, 3000);
                } else {
                    showMessage(response.message || __t('验证失败'), 'error');
                }
            },
            error: function(xhr) {
                hideLoading($btn, __t('发送验证码'));
                let errMsg = __t('网络错误');
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errMsg = xhr.responseJSON.message;
                }
                showMessage(errMsg, 'error');
            }
        });
    }
    
    // 步骤2：确认验证码并重置密码
    $('#reset-password').on('submit', function(e) {
        e.preventDefault();
        
        const $form = $(this);
        const $btn = $form.find('.reset-btn');
        const resetCode = $('#reset_code').val().trim();
        const newPassword = $('#new_password').val();
        const confirmPassword = $('#confirm_password').val();
        
        if (!resetCode) {
            showMessage(__t('请输入验证码'), 'error');
            return;
        }
        
        if (!newPassword) {
            showMessage(__t('请输入新密码'), 'error');
            return;
        }
        
        if (newPassword.length < 6) {
            showMessage(__t('密码长度不能少于6位'), 'error');
            return;
        }
        
        if (newPassword !== confirmPassword) {
            showMessage(__t('两次输入的密码不一致'), 'error');
            return;
        }
        
        showLoading($btn, __t('重置中'));
        
        $.ajax({
            url: $form.attr('action'),
            type: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            success: function(response) {
                hideLoading($btn, __t('确认重置'));
                
                if (response.success) {
                    showStep(3);
                } else {
                    showMessage(response.message || __t('重置失败，请重试'), 'error');
                }
            },
            error: function(xhr) {
                hideLoading($btn, __t('确认重置'));
                let errMsg = __t('网络错误');
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errMsg = xhr.responseJSON.message;
                }
                showMessage(errMsg, 'error');
            }
        });
    });
    
    // 重新发送验证码 (同样需要滑块人机验证)
    window.resendResetCode = function() {
        const account = $('#hidden_account').val();
        if (!account) {
            showMessage(__t('账号信息丢失，请重新操作'), 'error');
            showStep(1);
            return;
        }
        
        const $btn = $('#resend-code-btn');
        if (window.Captcha) {
            window.Captcha.show(function() {
                doResendCode($btn, account);
            });
        } else {
            doResendCode($btn, account);
        }
    };

    function doResendCode($btn, account) {
        showLoading($btn, __t('发送中'));
        
        $.ajax({
            url: $('#verify-identity').attr('action'),
            type: 'POST',
            data: {
                action: 'send_code',
                account: account,
                type: $('#hidden_type').val() || 'auto',
                captcha: 'bypass',
                _csrf_token: $('#verify-identity input[name="_csrf_token"]').val()
            },
            dataType: 'json',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            success: function(response) {
                hideLoading($btn, __t('重新发送'));
                if (response.success) {
                    showMessage(response.message || __t('验证码已重新发送'), 'success');
                    startResendTimer();
                } else {
                    showMessage(response.message || __t('发送失败'), 'error');
                }
            },
            error: function(xhr) {
                hideLoading($btn, __t('重新发送'));
                let errMsg = __t('网络错误');
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errMsg = xhr.responseJSON.message;
                }
                showMessage(errMsg, 'error');
            }
        });
    }
    
    // 刷新图形验证码
    $('.captcha-img').on('click', function() {
        const src = $(this).attr('src').split('?')[0];
        $(this).attr('src', src + '?' + Math.random());
    });
    
    // 确认密码输入监听
    $('#confirm_password').on('input', function() {
        const newPassword = $('#new_password').val();
        const confirmPassword = $(this).val();
        
        if (confirmPassword && newPassword !== confirmPassword) {
            $(this).css('border-color', '#dc3545');
        } else {
            $(this).css('border-color', '#e9ecef');
        }
    });
    
    // 输入框获取焦点时清除错误提示
    $('input').on('focus', function() {
        $('.error-message').fadeOut(300, function() {
            $(this).remove();
        });
    });
    
    // 初始化步骤
    showStep(1);
});