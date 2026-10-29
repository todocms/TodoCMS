$(document).ready(function() {
    // 获取站点URL（支持子目录部署）
    var baseUrl = (window.TODO && window.TODO.SITE_URL) ||
        (document.querySelector('meta[name="site-url"]') ? document.querySelector('meta[name="site-url"]').getAttribute('content') : window.location.origin);
    baseUrl = baseUrl.replace(/\/+$/, '');

    // 头像上传
    $('.avatar-preview').on('click', function() {
        $('#avatarUpload').trigger('click');
    });
    
    $('#avatarUpload').on('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        
        // 文件大小检查
        if (file.size > 2 * 1024 * 1024) {
            layer.msg(__t('文件大小不能超过2MB'), {icon: 2});
            return;
        }
        
        // 文件类型检查
        if (!file.type.match(/image\/(jpeg|jpg|png)/)) {
            layer.msg(__t('只支持JPG、PNG格式图片'), {icon: 2});
            return;
        }
        
        // 预览图片
        const reader = new FileReader();
        reader.onload = function(e) {
            $('#avatarPreview').attr('src', e.target.result);
        };
        reader.readAsDataURL(file);
        
        // 上传头像
        uploadAvatar(file);
    });
    
    function uploadAvatar(file) {
        const formData = new FormData();
        formData.append('avatar', file);
        formData.append('_csrf_token', $('input[name="_csrf_token"]').val());
        
        layer.load(2);
        
        $.ajax({
            url: baseUrl + '/user/upload-avatar',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                layer.closeAll('loading');
                
                if (response.success) {
                    layer.msg(__t('头像上传成功'), {icon: 1});
                    // 更新页面上的所有头像
                    $('.user-avatar, .avatar').attr('src', response.avatar_url);
                } else {
                    layer.msg(response.message || __t('上传失败'), {icon: 2});
                }
            },
            error: function() {
                layer.closeAll('loading');
                layer.msg(__t('网络错误'), {icon: 2});
            }
        });
    }
    
    // 基本信息表单提交
    $('#basicInfoForm').on('submit', function(e) {
        e.preventDefault();
        
        const $form = $(this);
        const $btn = $form.find('.save-btn');
        
        // 表单验证
        if (!validateBasicInfo()) return;
        
        showLoading($btn, __t('保存中'));
        
        $.ajax({
            url: baseUrl + '/user/update-profile',
            type: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            success: function(response) {
                hideLoading($btn, __t('保存信息'));
                
                if (response.success) {
                    layer.msg(__t('信息保存成功'), {icon: 1});
                } else {
                    layer.msg(response.message || __t('保存失败'), {icon: 2});
                }
            },
            error: function() {
                hideLoading($btn, __t('保存信息'));
                layer.msg(__t('网络错误'), {icon: 2});
            }
        });
    });
    
    // 密码修改表单提交
    $('#passwordForm').on('submit', function(e) {
        e.preventDefault();
        
        const $form = $(this);
        const $btn = $form.find('.save-btn');
        
        // 表单验证
        if (!validatePassword()) return;
        
        showLoading($btn, __t('修改中'));
        
        $.ajax({
            url: baseUrl + '/user/change-password',
            type: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            success: function(response) {
                hideLoading($btn, __t('修改密码'));
                
                if (response.success) {
                    layer.msg(__t('密码修改成功'), {icon: 1});
                    $form[0].reset();
                } else {
                    layer.msg(response.message || __t('修改失败'), {icon: 2});
                }
            },
            error: function() {
                hideLoading($btn, __t('修改密码'));
                layer.msg(__t('网络错误'), {icon: 2});
            }
        });
    });
    
    // 隐私设置表单提交
    $('#privacyForm').on('submit', function(e) {
        e.preventDefault();
        
        const $form = $(this);
        const $btn = $form.find('.save-btn');
        
        showLoading($btn, __t('保存中'));
        
        $.ajax({
            url: baseUrl + '/user/update-privacy',
            type: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            success: function(response) {
                hideLoading($btn, __t('保存设置'));
                
                if (response.success) {
                    layer.msg(__t('设置保存成功'), {icon: 1});
                } else {
                    layer.msg(response.message || __t('保存失败'), {icon: 2});
                }
            },
            error: function() {
                hideLoading($btn, __t('保存设置'));
                layer.msg(__t('网络错误'), {icon: 2});
            }
        });
    });
    
    // 安全操作按钮点击
    $('.action-btn').on('click', function() {
        const action = $(this).data('action');
        
        switch (action) {
            case 'change-password':
                $('html, body').animate({
                    scrollTop: $('.password-section').offset().top - 100
                }, 500);
                break;
            case 'verify-email':
                verifyEmail();
                break;
            case 'change-email':
                changeEmail();
                break;
            case 'bind-phone':
                bindPhone();
                break;
            case 'verify-phone':
                verifyPhone();
                break;
            case 'change-phone':
                changePhone();
                break;
        }
    });
    
    // 表单验证函数
    function validateBasicInfo() {
        const email = $('#email').val().trim();
        
        if (!email) {
            layer.msg(__t('请输入邮箱地址'), {icon: 2});
            return false;
        }
        
        if (!isValidEmail(email)) {
            layer.msg(__t('请输入有效的邮箱地址'), {icon: 2});
            return false;
        }
        
        return true;
    }
    
    function validatePassword() {
        const currentPassword = $('#current_password').val();
        const newPassword = $('#new_password').val();
        const confirmPassword = $('#confirm_password').val();
        
        if (!currentPassword) {
            layer.msg(__t('请输入当前密码'), {icon: 2});
            return false;
        }
        
        if (!newPassword) {
            layer.msg(__t('请输入新密码'), {icon: 2});
            return false;
        }
        
        if (newPassword.length < 6 || newPassword.length > 20) {
            layer.msg(__t('密码长度为6-20个字符'), {icon: 2});
            return false;
        }
        
        if (newPassword !== confirmPassword) {
            layer.msg(__t('两次输入的密码不一致'), {icon: 2});
            return false;
        }
        
        return true;
    }
    
    function isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }
    
    function showLoading(button, text) {
        button.prop('disabled', true).addClass('loading').text(text);
    }
    
    function hideLoading(button, text) {
        button.prop('disabled', false).removeClass('loading').text(text);
    }
    
    // 邮箱验证
    function verifyEmail() {
        layer.confirm(__t('确定发送验证邮件'), {icon: 3, title: '{{todo:t text="提示"}}', btn: [__t('确定'), __t('取消')]}, function(index) {
            layer.close(index);
            
            $.ajax({
                url: baseUrl + '/user/send-email-verification',
                type: 'POST',
                data: {
                    _csrf_token: $('input[name="_csrf_token"]').val()
                },
                success: function(response) {
                    if (response.success) {
                        layer.msg(__t('验证邮件已发送，请查收'), {icon: 1});
                    } else {
                        layer.msg(response.message || __t('发送失败'), {icon: 2});
                    }
                },
                error: function() {
                    layer.msg(__t('网络错误'), {icon: 2});
                }
            });
        });
    }
    
    // 修改邮箱
    function changeEmail() {
        layer.prompt({
            title: __t('修改邮箱'),
            formType: 0,
            value: ''
        }, function(value, index) {
            if (!isValidEmail(value)) {
                layer.msg(__t('请输入有效的邮箱地址'), {icon: 2});
                return;
            }
            
            layer.close(index);
            
            $.ajax({
                url: baseUrl + '/user/change-email',
                type: 'POST',
                data: {
                    email: value,
                    _csrf_token: $('input[name="_csrf_token"]').val()
                },
                success: function(response) {
                    if (response.success) {
                        layer.msg(__t('邮箱修改成功'), {icon: 1});
                        location.reload();
                    } else {
                        layer.msg(response.message || __t('修改失败'), {icon: 2});
                    }
                },
                error: function() {
                    layer.msg(__t('网络错误'), {icon: 2});
                }
            });
        });
    }
    
    // 绑定手机
    function bindPhone() {
        layer.prompt({
            title: __t('绑定手机'),
            formType: 0,
            value: ''
        }, function(value, index) {
            value = $.trim(value);
            if (!/^\+?[\d\s\-()]{6,20}$/.test(value)) {
                layer.msg(__t('请输入有效的手机号码'), {icon: 2});
                return;
            }
            
            layer.close(index);
            
            $.ajax({
                url: baseUrl + '/user/bind-phone',
                type: 'POST',
                data: {
                    phone: value,
                    _csrf_token: $('input[name="_csrf_token"]').val()
                },
                success: function(response) {
                    if (response.success) {
                        layer.msg(__t('手机绑定成功'), {icon: 1});
                        location.reload();
                    } else {
                        layer.msg(response.message || __t('绑定失败'), {icon: 2});
                    }
                },
                error: function() {
                    layer.msg(__t('网络错误'), {icon: 2});
                }
            });
        });
    }
    
    // 验证手机
    function verifyPhone() {
        layer.confirm(__t('确定发送验证短信'), {icon: 3, title: '{{todo:t text="提示"}}', btn: [__t('确定'), __t('取消')]}, function(index) {
            layer.close(index);
            
            $.ajax({
                url: baseUrl + '/user/send-phone-verification',
                type: 'POST',
                data: {
                    _csrf_token: $('input[name="_csrf_token"]').val()
                },
                success: function(response) {
                    if (response.success) {
                        layer.msg(__t('验证短信已发送'), {icon: 1});
                    } else {
                        layer.msg(response.message || __t('发送失败'), {icon: 2});
                    }
                },
                error: function() {
                    layer.msg(__t('网络错误'), {icon: 2});
                }
            });
        });
    }
    
    // 修改手机
    function changePhone() {
        layer.prompt({
            title: __t('修改手机号'),
            formType: 0,
            value: ''
        }, function(value, index) {
            value = $.trim(value);
            if (!/^\+?[\d\s\-()]{6,20}$/.test(value)) {
                layer.msg(__t('请输入有效的手机号码'), {icon: 2});
                return;
            }
            
            layer.close(index);
            
            $.ajax({
                url: baseUrl + '/user/change-phone',
                type: 'POST',
                data: {
                    phone: value,
                    _csrf_token: $('input[name="_csrf_token"]').val()
                },
                success: function(response) {
                    if (response.success) {
                        layer.msg(__t('手机号修改成功'), {icon: 1});
                        location.reload();
                    } else {
                        layer.msg(response.message || __t('修改失败'), {icon: 2});
                    }
                },
                error: function() {
                    layer.msg(__t('网络错误'), {icon: 2});
                }
            });
        });
    }
    
    // 实时密码确认验证
    $('#confirm_password').on('input', function() {
        const newPassword = $('#new_password').val();
        const confirmPassword = $(this).val();
        
        if (confirmPassword && newPassword !== confirmPassword) {
            $(this).css('border-color', '#dc3545');
        } else {
            $(this).css('border-color', '#e9ecef');
        }
    });
});