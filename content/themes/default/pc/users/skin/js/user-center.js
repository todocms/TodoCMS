/**
 * 用户中心 JavaScript
 * 处理用户中心页面的交互功能
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        // 初始化用户中心功能
        initUserCenter();
        
        // 移动端菜单切换
        initMobileMenu();
        
        // 表单验证
        initFormValidation();
        
        // 工具提示
        initTooltips();
    });
    
    /**
     * 初始化用户中心
     */
    function initUserCenter() {
        // 更新购物车数量
        updateCartCount();
        
        // 自动刷新用户数据
        if (typeof window.updateCartCount === 'function') {
            setInterval(updateCartCount, 30000); // 每30秒更新一次
        }
    }
    
    /**
     * 更新购物车数量
     */
    function updateCartCount() {
        if (typeof window.updateCartCount === 'function') {
            window.updateCartCount();
        }
    }
    
    /**
     * 初始化移动端菜单
     */
    function initMobileMenu() {
        // 移动端菜单按钮
        $('.mobile-menu-btn').on('click', function() {
            $('.user-sidebar').addClass('active');
            $('.mobile-overlay').addClass('active');
            $('body').css('overflow', 'hidden');
        });
        
        // 关闭移动端菜单
        $('.mobile-overlay').on('click', function() {
            closeMobileMenu();
        });
        
        // ESC键关闭菜单
        $(document).on('keydown', function(e) {
            if (e.keyCode === 27) {
                closeMobileMenu();
            }
        });
        
        function closeMobileMenu() {
            $('.user-sidebar').removeClass('active');
            $('.mobile-overlay').removeClass('active');
            $('body').css('overflow', '');
        }
        
        // 响应式处理
        $(window).on('resize', function() {
            if ($(window).width() > 1024) {
                closeMobileMenu();
            }
        });
    }
    
    /**
     * 初始化表单验证
     */
    function initFormValidation() {
        // 实时验证
        $('input[type="email"]').on('blur', function() {
            var email = $(this).val();
            if (email && !isValidEmail(email)) {
                showFieldError($(this), __t('请输入有效的邮箱地址'));
            } else {
                clearFieldError($(this));
            }
        });
        
        $('input[type="tel"]').on('blur', function() {
            var phone = $(this).val();
            if (phone && !isValidPhone(phone)) {
                showFieldError($(this), __t('请输入正确的手机号码'));
            } else {
                clearFieldError($(this));
            }
        });
    }
    
    /**
     * 验证邮箱格式
     */
    function isValidEmail(email) {
        var regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return regex.test(email);
    }
    
    /**
     * 验证手机号格式
     */
    function isValidPhone(phone) {
        var regex = /^1[3-9]\d{9}$/;
        return regex.test(phone);
    }
    
    /**
     * 显示字段错误
     */
    function showFieldError($field, message) {
        clearFieldError($field);
        $field.addClass('error');
        $field.after('<span class="field-error">' + message + '</span>');
    }
    
    /**
     * 清除字段错误
     */
    function clearFieldError($field) {
        $field.removeClass('error');
        $field.next('.field-error').remove();
    }
    
    /**
     * 初始化工具提示
     */
    function initTooltips() {
        // 使用 title 属性显示工具提示
        $('[title]').each(function() {
            $(this).tooltip({
                placement: 'top',
                trigger: 'hover'
            });
        });
    }
    
    /**
     * 显示加载状态
     */
    function showLoading($element) {
        $element.addClass('loading').prop('disabled', true);
    }
    
    /**
     * 隐藏加载状态
     */
    function hideLoading($element) {
        $element.removeClass('loading').prop('disabled', false);
    }
    
    /**
     * 显示消息提示
     */
    function showMessage(message, type) {
        type = type || 'info';
        var $message = $('<div class="user-message user-message-' + type + '">' + message + '</div>');
        $('body').append($message);
        
        setTimeout(function() {
            $message.addClass('show');
        }, 100);
        
        setTimeout(function() {
            $message.removeClass('show');
            setTimeout(function() {
                $message.remove();
            }, 300);
        }, 3000);
    }
    
    // 导出全局函数
    window.UserCenter = {
        showLoading: showLoading,
        hideLoading: hideLoading,
        showMessage: showMessage,
        updateCartCount: updateCartCount
    };
    
})(jQuery);

