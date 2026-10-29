/**
 * TodoCMS 用户中心 JavaScript 文件
 * 提供用户中心的通用功能和交互
 */

(function($) {
    'use strict';

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
    
    // 获取站点URL（支持子目录部署）
    var siteUrl = (window.TODO && window.TODO.SITE_URL) ||
        (document.querySelector('meta[name="site-url"]') ? document.querySelector('meta[name="site-url"]').getAttribute('content') : window.location.origin);
    siteUrl = siteUrl.replace(/\/+$/, '');

    // 全局配置
    var TodoUser = {
        config: {
            ajaxTimeout: 30000,
            csrfToken: $('meta[name="csrf-token"]').attr('content'),
            baseUrl: siteUrl,
            userUrl: siteUrl + '/user'
        },
        
        // 初始化
        init: function() {
            this.bindEvents();
            this.initComponents();
            this.setupAjax();
        },
        
        // 绑定事件
        bindEvents: function() {
            // 侧边栏菜单激活状态
            this.setActiveMenu();
            
            // 表格全选功能
            this.bindSelectAll();
            
            // 确认删除
            this.bindConfirmDelete();
            
            // 表单验证
            this.bindFormValidation();
            
            // 标签页切换
            this.bindTabSwitch();
            
            // 模态框
            this.bindModal();
            
            // 返回顶部
            this.bindBackToTop();
        },
        
        // 初始化组件
        initComponents: function() {
            // 初始化工具提示
            this.initTooltips();
            
            // 初始化日期选择器
            this.initDatePicker();
            
            // 初始化图片上传
            this.initImageUpload();
            
            // 初始化地区选择
            this.initRegionSelector();
        },
        
        // 设置AJAX默认配置
        setupAjax: function() {
            var self = this;
            
            $.ajaxSetup({
                timeout: self.config.ajaxTimeout,
                beforeSend: function(xhr) {
                    // 添加CSRF令牌
                    if (self.config.csrfToken) {
                        xhr.setRequestHeader('X-CSRF-TOKEN', self.config.csrfToken);
                    }
                    // 标识AJAX请求
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                },
                error: function(xhr, status, error) {
                    self.handleAjaxError(xhr, status, error);
                }
            });
        },
        
        // 处理AJAX错误
        handleAjaxError: function(xhr, status, error) {
            var message = __t('请求失败，请重试');
            
            if (xhr.status === 401) {
                message = __t('登录已过期，请重新登录');
                setTimeout(function() {
                    var base = (window.TODO && window.TODO.SITE_URL) || (TodoUser.config && TodoUser.config.baseUrl) || window.location.origin;
                    base = String(base || '').replace(/\/+$/, '');
                    window.location.href = base + '/?user=login';
                }, 2000);
            } else if (xhr.status === 403) {
                message = __t('没有权限执行此操作');
            } else if (xhr.status === 404) {
                message = __t('请求的资源不存在');
            } else if (xhr.status === 500) {
                message = __t('服务器内部错误');
            } else if (status === 'timeout') {
                message = __t('请求超时，请重试');
            }
            
            this.showMessage(message, 'error');
        },
        
        // 设置激活菜单
        setActiveMenu: function() {
            var currentPath = window.location.pathname;
            $('.sidebar-menu a').each(function() {
                var href = $(this).attr('href');
                if (href && currentPath.indexOf(href) === 0) {
                    $(this).addClass('active');
                    return false;
                }
            });
        },
        
        // 绑定全选功能
        bindSelectAll: function() {
            $(document).on('change', '.select-all', function() {
                var checked = $(this).prop('checked');
                var target = $(this).data('target') || '.select-item';
                $(target).prop('checked', checked);
                TodoUser.updateBatchActions();
            });
            
            $(document).on('change', '.select-item', function() {
                var total = $('.select-item').length;
                var checked = $('.select-item:checked').length;
                $('.select-all').prop('checked', total === checked);
                TodoUser.updateBatchActions();
            });
        },
        
        // 更新批量操作按钮状态
        updateBatchActions: function() {
            var checked = $('.select-item:checked').length;
            var $batchActions = $('.batch-actions');
            
            if (checked > 0) {
                $batchActions.removeClass('d-none');
                $batchActions.find('.selected-count').text(checked);
            } else {
                $batchActions.addClass('d-none');
            }
        },
        
        // 绑定确认删除
        bindConfirmDelete: function() {
            $(document).on('click', '[data-confirm-delete]', function(e) {
                e.preventDefault();
                var $this = $(this);
                var message = $this.data('confirm-delete') || __t('确定删除');
                var url = $this.attr('href') || $this.data('url');
                
                TodoUser.confirm(message, function() {
                    if (url) {
                        window.location.href = url;
                    } else if ($this.is('form')) {
                        $this.submit();
                    }
                });
            });
        },
        
        // 绑定表单验证
        bindFormValidation: function() {
            $(document).on('submit', 'form[data-validate]', function(e) {
                var $form = $(this);
                var isValid = TodoUser.validateForm($form);
                
                if (!isValid) {
                    e.preventDefault();
                    return false;
                }
            });
            
            // 实时验证
            $(document).on('blur', '.form-control[required]', function() {
                TodoUser.validateField($(this));
            });
        },
        
        // 表单验证
        validateForm: function($form) {
            var isValid = true;
            
            $form.find('.form-control[required]').each(function() {
                if (!TodoUser.validateField($(this))) {
                    isValid = false;
                }
            });
            
            return isValid;
        },
        
        // 字段验证
        validateField: function($field) {
            var rawVal = $field.val();
            var value = (rawVal !== null && rawVal !== undefined) ? String(rawVal).trim() : '';
            var type = $field.attr('type');
            var isValid = true;
            var message = '';
            
            // 必填验证
            if ($field.prop('required') && !value) {
                isValid = false;
                message = __t('此字段为必填项');
            }
            
            // 邮箱验证
            if (isValid && type === 'email' && value) {
                var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(value)) {
                    isValid = false;
                    message = __t('请输入有效的邮箱地址');
                }
            }
            
            // 手机号验证（支持国际跨境号码格式）
            if (isValid && $field.hasClass('mobile') && value) {
                var mobileRegex = /^\+?[\d\s\-()]{6,20}$/;
                if (!mobileRegex.test($.trim(value))) {
                    isValid = false;
                    message = __t('请输入有效的手机号码');
                }
            }
            
            // 密码长度验证
            if (isValid && type === 'password' && value) {
                var minLength = parseInt($field.attr('minlength')) || 6;
                if (value.length < minLength) {
                    isValid = false;
                    message = __t('密码长度至少') + minLength;
                }
            }
            
            // 确认密码验证
            if (isValid && $field.hasClass('confirm-password') && value) {
                var passwordField = $field.data('password-field') || 'input[type="password"]:first';
                var password = $(passwordField).val();
                if (value !== password) {
                    isValid = false;
                    message = __t('两次输入的密码不一致');
                }
            }
            
            // 显示验证结果
            this.showFieldValidation($field, isValid, message);
            
            return isValid;
        },
        
        // 显示字段验证结果
        showFieldValidation: function($field, isValid, message) {
            $field.removeClass('is-valid is-invalid');
            $field.siblings('.invalid-feedback, .valid-feedback').remove();
            
            if (isValid) {
                $field.addClass('is-valid');
            } else {
                $field.addClass('is-invalid');
                if (message) {
                    $field.after('<div class="invalid-feedback">' + message + '</div>');
                }
            }
        },
        
        // 绑定标签页切换
        bindTabSwitch: function() {
            $(document).on('click', '[data-toggle="tab"]', function(e) {
                e.preventDefault();
                var $this = $(this);
                var target = $this.attr('href');
                
                // 移除所有激活状态
                $this.closest('.nav-tabs').find('.nav-link').removeClass('active');
                $('.tab-pane').removeClass('show active');
                
                // 激活当前标签
                $this.addClass('active');
                $(target).addClass('show active');
            });
        },
        
        // 绑定模态框
        bindModal: function() {
            // 显示模态框
            $(document).on('click', '[data-toggle="modal"]', function(e) {
                e.preventDefault();
                var target = $(this).data('target');
                TodoUser.showModal(target);
            });
            
            // 关闭模态框
            $(document).on('click', '[data-dismiss="modal"], .modal-backdrop', function() {
                TodoUser.hideModal();
            });
            
            // 阻止模态框内容区域点击关闭
            $(document).on('click', '.modal-dialog', function(e) {
                e.stopPropagation();
            });
        },
        
        // 显示模态框
        showModal: function(selector) {
            var $modal = $(selector);
            if ($modal.length) {
                $modal.addClass('show').css('display', 'flex');
                $('body').addClass('modal-open');
            }
        },
        
        // 隐藏模态框
        hideModal: function() {
            $('.modal').removeClass('show').css('display', 'none');
            $('body').removeClass('modal-open');
        },
        
        // 绑定返回顶部
        bindBackToTop: function() {
            var $backToTop = $('.back-to-top');
            
            if ($backToTop.length) {
                $(window).scroll(function() {
                    if ($(this).scrollTop() > 300) {
                        $backToTop.fadeIn();
                    } else {
                        $backToTop.fadeOut();
                    }
                });
                
                $backToTop.click(function(e) {
                    e.preventDefault();
                    $('html, body').animate({scrollTop: 0}, 500);
                });
            }
        },
        
        // 初始化工具提示
        initTooltips: function() {
            $('[data-toggle="tooltip"]').each(function() {
                var $this = $(this);
                var title = $this.attr('title') || $this.data('title');
                
                $this.hover(function() {
                    var tooltip = '<div class="tooltip">' + title + '</div>';
                    $('body').append(tooltip);
                    
                    var $tooltip = $('.tooltip');
                    var offset = $this.offset();
                    
                    $tooltip.css({
                        top: offset.top - $tooltip.outerHeight() - 5,
                        left: offset.left + ($this.outerWidth() - $tooltip.outerWidth()) / 2
                    }).fadeIn();
                }, function() {
                    $('.tooltip').remove();
                });
            });
        },
        
        // 初始化日期选择器
        initDatePicker: function() {
            if (typeof laydate !== 'undefined') {
                $('.date-picker').each(function() {
                    var $this = $(this);
                    laydate.render({
                        elem: this,
                        type: $this.data('type') || 'date',
                        format: $this.data('format') || 'yyyy-MM-dd'
                    });
                });
            }
        },
        
        // 初始化图片上传
        initImageUpload: function() {
            $('.image-upload').each(function() {
                var $container = $(this);
                var $input = $container.find('input[type="file"]');
                var $preview = $container.find('.image-preview');
                
                $input.change(function() {
                    var file = this.files[0];
                    if (file) {
                        if (file.type.indexOf('image/') !== 0) {
                            TodoUser.showMessage(__t('请选择图片文件'), 'error');
                            return;
                        }
                        
                        var reader = new FileReader();
                        reader.onload = function(e) {
                            $preview.html('<img src="' + e.target.result + '" alt="' + __t('预览图片') + '">');
                        };
                        reader.readAsDataURL(file);
                    }
                });
            });
        },
        
        // 初始化地区选择器
        initRegionSelector: function() {
            var $province = $('.region-province');
            var $city = $('.region-city');
            var $district = $('.region-district');
            
            if ($province.length && typeof regionData !== 'undefined') {
                // 加载省份
                this.loadRegionOptions($province, regionData, __t('请选择省份'));
                
                // 省份变化时加载城市
                $province.change(function() {
                    var provinceCode = $(this).val();
                    $city.empty().append('<option value="">' + __t('请选择城市') + '</option>');
                    $district.empty().append('<option value="">' + __t('请选择区县') + '</option>');
                    
                    if (provinceCode && regionData[provinceCode]) {
                        TodoUser.loadRegionOptions($city, regionData[provinceCode], __t('请选择城市'));
                    }
                });
                
                // 城市变化时加载区县
                $city.change(function() {
                    var provinceCode = $province.val();
                    var cityCode = $(this).val();
                    $district.empty().append('<option value="">' + __t('请选择区县') + '</option>');
                    
                    if (provinceCode && cityCode && regionData[provinceCode] && regionData[provinceCode][cityCode]) {
                        TodoUser.loadRegionOptions($district, regionData[provinceCode][cityCode], __t('请选择区县'));
                    }
                });
            }
        },
        
        // 加载地区选项
        loadRegionOptions: function($select, data, placeholder) {
            $select.empty();
            if (placeholder) {
                $select.append('<option value="">' + placeholder + '</option>');
            }
            
            $.each(data, function(code, name) {
                $select.append('<option value="' + code + '">' + name + '</option>');
            });
        },
        
        // 显示消息
        showMessage: function(message, type, duration) {
            type = type || 'info';
            duration = duration || 3000;
            
            if (typeof layer !== 'undefined') {
                var icon = 0; // 默认图标
                switch (type) {
                    case 'success':
                        icon = 1;
                        break;
                    case 'error':
                        icon = 2;
                        break;
                    case 'warning':
                        icon = 0;
                        break;
                    case 'info':
                        icon = 0;
                        break;
                }
                
                layer.msg(message, {
                    icon: icon,
                    time: duration
                });
            } else {
                // 简单的消息显示
                var $message = $('<div class="alert alert-' + type + ' alert-dismissible" style="position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px;">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    message +
                    '</div>');
                
                $('body').append($message);
                
                setTimeout(function() {
                    $message.fadeOut(function() {
                        $(this).remove();
                    });
                }, duration);
            }
        },
        
        // 确认对话框
        confirm: function(message, callback, cancelCallback) {
            if (typeof layer !== 'undefined') {
                layer.confirm(message, {
                    icon: 3,
                    title: __t('确认操作'),
                    btn: [__t('确定'), __t('取消')]
                }, function(index) {
                    if (callback) callback();
                    layer.close(index);
                }, function() {
                    if (cancelCallback) cancelCallback();
                });
            }
        },
        
        // 加载中状态
        showLoading: function(target) {
            var $target = target ? $(target) : $('body');
            var $loading = $('<div class="loading-overlay" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.8); z-index: 999; display: flex; align-items: center; justify-content: center;"><div class="spinner"></div><span style="margin-inline-start: 10px;">' + __t('加载中') + '</span></div>');
            
            if (!target) {
                $loading.css('position', 'fixed');
            }
            
            $target.css('position', 'relative').append($loading);
        },
        
        // 隐藏加载状态
        hideLoading: function(target) {
            var $target = target ? $(target) : $('body');
            $target.find('.loading-overlay').remove();
        },
        
        // 格式化日期
        formatDate: function(date, format) {
            format = format || 'yyyy-MM-dd';
            
            if (typeof date === 'string') {
                date = new Date(date);
            }
            
            var year = date.getFullYear();
            var month = ('0' + (date.getMonth() + 1)).slice(-2);
            var day = ('0' + date.getDate()).slice(-2);
            var hour = ('0' + date.getHours()).slice(-2);
            var minute = ('0' + date.getMinutes()).slice(-2);
            var second = ('0' + date.getSeconds()).slice(-2);
            
            return format
                .replace('yyyy', year)
                .replace('MM', month)
                .replace('dd', day)
                .replace('HH', hour)
                .replace('mm', minute)
                .replace('ss', second);
        },
        
        // 格式化文件大小
        formatFileSize: function(bytes) {
            if (bytes === 0) return '0 B';
            
            var k = 1024;
            var sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },
        
        // 防抖函数
        debounce: function(func, wait, immediate) {
            var timeout;
            return function() {
                var context = this, args = arguments;
                var later = function() {
                    timeout = null;
                    if (!immediate) func.apply(context, args);
                };
                var callNow = immediate && !timeout;
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
                if (callNow) func.apply(context, args);
            };
        },
        
        // 节流函数
        throttle: function(func, wait) {
            var timeout;
            return function() {
                var context = this, args = arguments;
                if (!timeout) {
                    timeout = setTimeout(function() {
                        timeout = null;
                        func.apply(context, args);
                    }, wait);
                }
            };
        },
        
        // 获取URL参数
        getUrlParam: function(name) {
            var reg = new RegExp('(^|&)' + name + '=([^&]*)(&|$)');
            var r = window.location.search.substr(1).match(reg);
            if (r != null) return unescape(r[2]);
            return null;
        },
        
        // 设置URL参数
        setUrlParam: function(name, value) {
            var url = new URL(window.location);
            url.searchParams.set(name, value);
            window.history.replaceState({}, '', url);
        },
        
        // 复制到剪贴板
        copyToClipboard: function(text) {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    TodoUser.showMessage(__t('复制成功'), 'success');
                }).catch(function() {
                    TodoUser.showMessage(__t('复制失败'), 'error');
                });
            } else {
                // 兼容旧浏览器
                var textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    document.execCommand('copy');
                    TodoUser.showMessage(__t('复制成功'), 'success');
                } catch (err) {
                    TodoUser.showMessage(__t('复制失败'), 'error');
                }
                document.body.removeChild(textArea);
            }
        }
    };

    if ($ && $.fn && typeof $.fn.modal !== 'function') {
        $.fn.modal = function(action) {
            if (action === 'show') {
                return this.each(function() {
                    var id = this && this.id ? ('#' + this.id) : null;
                    if (id) {
                        TodoUser.showModal(id);
                    }
                });
            }
            if (action === 'hide') {
                TodoUser.hideModal();
                return this;
            }
            return this;
        };
    }
    
    // 页面加载完成后初始化
    $(document).ready(function() {
        TodoUser.init();
    });
    
    // 暴露到全局
    window.TodoUser = TodoUser;
    
})(jQuery);
