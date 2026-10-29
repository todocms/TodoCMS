/**
 * CSRF Token 管理器
 * 提供自动刷新和管理CSRF token的功能
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

(function($) {
    'use strict';
    
    // CSRF管理器对象
    window.TodoCSRFManager = {
        // 配置选项
        config: {
            refreshInterval: 30 * 60 * 1000, // 30分钟自动刷新
            warningTime: 5 * 60 * 1000,     // 5分钟前警告
            ajaxUrl: (function() {
                // 优先使用全局 window.TODO.ADMIN_AJAX
                if (window.TODO && window.TODO.ADMIN_AJAX) {
                    return window.TODO.ADMIN_AJAX;
                }
                // 次选 window.TODO.ADMIN_BASE
                if (window.TODO && window.TODO.ADMIN_BASE && window.TODO.join) {
                    return window.TODO.join(window.TODO.ADMIN_BASE, 'admin-ajax.php');
                }
                // 回退：使用当前页面的相对路径
                var currentPath = window.location.pathname;
                var adminBase = currentPath.replace(/\/[^\/]*$/, '');
                if (adminBase.indexOf('/admin') === -1) {
                    adminBase = '/admin';
                }
                return adminBase + '/admin-ajax.php';
            })(),
            debug: false
        },
        
        // 内部状态
        tokens: {},
        timers: {},
        confirmedSubmits: {}, // 已确认提交的表单标记
        
        /**
         * 初始化CSRF管理器
         */
        init: function(options) {
            this.config = $.extend(this.config, options || {});
            
            // 收集页面中的所有CSRF token
            this.collectTokens();
            
            // 设置自动刷新
            this.setupAutoRefresh();
            
            // 绑定表单提交事件
            this.bindFormEvents();
        },
        
        /**
         * 收集页面中的CSRF token
         */
        collectTokens: function() {
            var self = this;
            
            // 收集默认字段名 _todononce 的token
            $('input[name="_todononce"]').each(function() {
                var $input = $(this);
                var form = $input.closest('form');
                var action = self.getFormAction(form);
                
                self.tokens[action] = {
                    value: $input.val(),
                    element: $input,
                    form: form,
                    lastRefresh: Date.now()
                };
            });
            
            // 额外收集字段名为 _csrf_token 的token（部分AJAX接口使用该字段名）
            $('input[name="_csrf_token"]').each(function() {
                var $input = $(this);
                var form = $input.closest('form');
                var action = self.getFormAction(form);
                
                self.tokens[action] = {
                    value: $input.val(),
                    element: $input,
                    form: form,
                    lastRefresh: Date.now()
                };
            });
        },
        
        /**
         * 获取表单的action
         */
        getFormAction: function(form) {
            // 尝试从隐藏字段获取action
            var actionInput = form.find('input[name="action"]');
            if (actionInput.length) {
                return actionInput.val();
            }
            
            // 尝试从提交按钮获取action
            var submitButtons = form.find('input[type="submit"], button[type="submit"]');
            if (submitButtons.length) {
                var buttonName = submitButtons.first().attr('name');
                if (buttonName) {
                    return buttonName;
                }
            }
            
            // 默认action
            return 'default';
        },
        
        /**
         * 设置自动刷新
         */
        setupAutoRefresh: function() {
            var self = this;
            
            // 为每个token设置刷新定时器
            $.each(this.tokens, function(action, tokenData) {
                self.scheduleRefresh(action);
            });
        },
        
        /**
         * 安排token刷新
         */
        scheduleRefresh: function(action) {
            var self = this;
            
            // 清除现有定时器
            if (this.timers[action]) {
                clearTimeout(this.timers[action]);
            }
            
            // 设置新的定时器
            this.timers[action] = setTimeout(function() {
                self.refreshToken(action);
            }, this.config.refreshInterval);
        },
        
        /**
         * 刷新指定action的token
         */
        refreshToken: function(action) {
            var self = this;
            var tokenData = this.tokens[action];
            
            if (!tokenData) {
                this.log('未找到action: ' + action + ' 的token数据', 'warn');
                return;
            }
            
            TodoRequest.ajax({
                url: this.config.ajaxUrl,
                data: {
                    action: 'refresh_csrf_token',
                    csrf_action: action
                },
                onSuccess: function(response) {
                    if (response.success) {
                        // 更新token值
                        tokenData.value = response.token;
                        tokenData.element.val(response.token);
                        tokenData.lastRefresh = Date.now();
                        
                        // 安排下次刷新
                        self.scheduleRefresh(action);
                        
                        // 触发自定义事件
                        $(document).trigger('csrf-token-refreshed', [action, response.token]);
                    } else {
                        self.log('Token刷新失败: ' + (response.error || '未知错误'), 'error');
                        // 若由于未登录（logged_in === false）导致刷新失败，清除定时器，不再无休止请求
                        if (response.logged_in === false) {
                            if (self.timers[action]) {
                                clearTimeout(self.timers[action]);
                                delete self.timers[action];
                            }
                        }
                    }
                },
                onError: function(xhr, status, error) {
                    // 支持参数解析（直接传入错误对象或 (xhr, status, error) 格式）
                    var statusCode = (xhr && xhr.status) || (status && status.status) || 0;
                    var errObj = xhr || status || error;
                    var errMsg = (errObj && (errObj.message || errObj.error)) || (typeof error === 'string' ? error : '未知错误');
                    
                    self.log('Token刷新请求失败: ' + errMsg, 'error');
                    
                    // 检查是否为 HTTP 403 Forbidden（通常意味着用户未登录/会话已过期）
                    if (statusCode === 403 || statusCode === 401) {
                        self.log('用户未登录或会话过期，停止CSRF Token自动刷新定时器', 'warn');
                        if (self.timers[action]) {
                            clearTimeout(self.timers[action]);
                            delete self.timers[action];
                        }
                        return;
                    }

                    // 其他网络异常或临时错误才触发 5 秒重试机制
                    setTimeout(function() {
                        self.refreshToken(action);
                    }, 5000);
                }
            });
        },
        
        /**
         * 手动刷新所有token
         */
        refreshAllTokens: function() {
            var self = this;
            
            $.each(this.tokens, function(action) {
                self.refreshToken(action);
            });
        },
        
        /**
         * 绑定表单事件
         */
        bindFormEvents: function() {
            var self = this;
            
            // 先移除旧的事件监听器，避免重复绑定
            $(document).off('submit.csrf-check', 'form');
            
            // 表单提交前检查token是否过期（使用命名空间事件，方便后续移除）
            $(document).on('submit.csrf-check', 'form', function(e) {
                var form = $(this);
                var formElement = form[0];
                var formId = formElement.id || form.attr('action') || 'form_' + (formElement.name || Math.random().toString(36).substr(2, 9));
                var action = self.getFormAction(form);
                var tokenData = self.tokens[action];
                
                // 如果已经确认提交，跳过检查
                if (self.confirmedSubmits[formId]) {
                    delete self.confirmedSubmits[formId];
                    return true; // 允许提交
                }
                
                if (tokenData) {
                    var timeSinceRefresh = Date.now() - tokenData.lastRefresh;
                    
                    // 如果token超过警告时间，提示用户
                    if (timeSinceRefresh > self.config.warningTime) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        TodoModal.confirm({
                            message: '您的会话即将过期，是否继续提交？',
                            onConfirm: function() {
                                // 标记表单已确认，避免重复检查
                                self.confirmedSubmits[formId] = true;

                                // 提交前立即同步恢复 body 状态并关闭/销毁所有弹窗。
                                //
                                // 重要：管理后台使用 iframe 布局，modal.js 的 getDoc() 始终返回
                                // window.parent.document（父页面文档），所有弹窗相关的 body 操作
                                // 都作用在父页面的 body 上。因此这里也必须操作父页面 body，
                                // 而不是 iframe 自身的 document.body，否则会清错目标。
                                //
                                // 原因：HTMLFormElement.prototype.submit.call() 触发 iframe 页面导航，
                                // 浏览器可能在 closeAll() 内部的 restoreScroll() 执行前就完成卸载，
                                // 导致父页面 body 上的 show-modal / overflow:hidden / data-overlay 残留。
                                var targetDoc;
                                try {
                                    targetDoc = (window.parent && window.parent !== window)
                                        ? window.parent.document
                                        : document;
                                } catch (e) {
                                    targetDoc = document;
                                }
                                var targetBody = targetDoc.body;
                                if (targetBody) {
                                    targetBody.classList.remove('show-modal');
                                    targetBody.style.overflow = '';
                                    targetBody.removeAttribute('data-overlay');
                                }

                                if (typeof TodoModal !== 'undefined') {
                                    if (typeof TodoModal.closeAll === 'function') {
                                        TodoModal.closeAll({ destroy: true });
                                    } else if (typeof TodoModal.restoreScroll === 'function') {
                                        TodoModal.restoreScroll();
                                    }
                                }

                                // 使用原生 submit 方法（不会触发 jQuery 的 submit 事件）
                                // 这样可以避免再次进入检查逻辑
                                if (formElement && formElement.nodeName === 'FORM') {
                                    try {
                                        HTMLFormElement.prototype.submit.call(formElement);
                                    } catch (err) {
                                        self.log('使用原生 submit 失败，使用 jQuery 方式: ' + err.message, 'warn');
                                        $(document).off('submit.csrf-check', form);
                                        form.trigger('submit');
                                        setTimeout(function() {
                                            self.bindFormEvents();
                                        }, 100);
                                    }
                                } else {
                                    $(document).off('submit.csrf-check', form);
                                    form.trigger('submit');
                                    setTimeout(function() {
                                        self.bindFormEvents();
                                    }, 100);
                                }

                                return true; // 返回 true 以关闭弹窗
                            },
                            onCancel: function() {
                                // 取消时不做任何操作
                                return true;
                            }
                        });
                        return false;
                    }
                }
            });
        },
        
        /**
         * 获取指定action的token
         */
        getToken: function(action) {
            var tokenData = this.tokens[action];
            return tokenData ? tokenData.value : null;
        },
        
        /**
         * 生成CSRF令牌 (getToken的别名)
         */
        generateNonce: function(action) {
            return this.getToken(action);
        },
        
        /**
         * 检查token是否即将过期
         */
        isTokenExpiring: function(action) {
            var tokenData = this.tokens[action];
            if (!tokenData) {
                return false;
            }
            
            var timeSinceRefresh = Date.now() - tokenData.lastRefresh;
            return timeSinceRefresh > this.config.warningTime;
        },
        
        /**
         * 日志输出
         */
        log: function(message, level) {
            if (!this.config.debug) {
                return;
            }
            
            level = level || 'info';
            var prefix = '[CSRF Manager] ';
            
            switch (level) {
                case 'error':
                    console.error(prefix + message);
                    break;
                case 'warn':
                    console.warn(prefix + message);
                    break;
                default:
                    console.log(prefix + message);
                    break;
            }
        }
    };
    
    // 创建简化别名
    window.todoCSRF = window.TodoCSRFManager;

    // ----------------------------------------------------------------
    // 兜底清理：页面卸载时（iframe 内导航/跳转/刷新）强制恢复父页面 body。
    // 场景：表单提交触发 iframe 导航，若 closeAll 尚未完成就发生 unload，
    //       父页面 body 的 show-modal/overflow/data-overlay 将永久残留。
    // pagehide 在现代浏览器中比 beforeunload 更可靠（BFCache 也会触发）。
    // ----------------------------------------------------------------
    window.addEventListener('pagehide', function() {
        try {
            var targetDoc = (window.parent && window.parent !== window)
                ? window.parent.document
                : document;
            var targetBody = targetDoc.body;
            if (targetBody) {
                targetBody.classList.remove('show-modal');
                targetBody.style.overflow = '';
                targetBody.removeAttribute('data-overlay');
                // 同时移除遗留的弹窗 DOM（避免父页面出现孤立 overlay 节点）
                var orphans = targetDoc.querySelectorAll('.todo-modal-overlay');
                orphans.forEach(function(el) { el.parentNode && el.parentNode.removeChild(el); });
            }
        } catch (e) {
            // 跨域访问被拒绝时忽略
        }
    });

    // 页面加载完成后自动初始化（确保jQuery已加载）
    if (typeof jQuery !== 'undefined') {
        jQuery(document).ready(function() {
            // 检查是否在管理后台
            if (window.location.pathname.indexOf('/admin/') !== -1) {
                TodoCSRFManager.init({
                    debug: true // 在开发环境启用调试
                });
            }
        });
    } else {
        // jQuery未加载，等待DOMContentLoaded后重试
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof jQuery !== 'undefined') {
                    jQuery(document).ready(function() {
                        if (window.location.pathname.indexOf('/admin/') !== -1) {
                            TodoCSRFManager.init({
                                debug: true
                            });
                        }
                    });
                }
            });
        }
    }
    
})(typeof jQuery !== 'undefined' ? jQuery : null);