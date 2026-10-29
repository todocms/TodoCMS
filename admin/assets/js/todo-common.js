/**
 * TodoCMS 通用JavaScript函数库
 * 统一管理常用的JavaScript函数，避免重复定义
 * 
 * @version 1.0.0
 * @author TodoCMS Team
 */

// 确保TodoCMS命名空间存在
if (typeof window.TodoCMS === 'undefined') {
    window.TodoCMS = {};
}

// DOM 操作工具，支持跨 iframe 访问被移动到父级的元素
var TodoDOM = window.TodoDOM = {
    get: function(id) {
        if (!id) return null;
        var el = document.getElementById(id);
        if (!el && window.parent && window.parent.document && window.parent.document !== document) {
            try {
                el = window.parent.document.getElementById(id);
            } catch (e) {
                console.warn('Access parent document failed:', e);
            }
        }
        return el;
    },
    query: function(selector) {
        if (!selector) return null;
        var el = document.querySelector(selector);
        if (!el && window.parent && window.parent.document && window.parent.document !== document) {
            try {
                el = window.parent.document.querySelector(selector);
            } catch (e) {
                console.warn('Access parent document failed:', e);
            }
        }
        return el;
    },
    queryAll: function(selector) {
        if (!selector) return [];
        var els = document.querySelectorAll(selector);
        if (els.length === 0 && window.parent && window.parent.document && window.parent.document !== document) {
            try {
                els = window.parent.document.querySelectorAll(selector);
            } catch (e) {
                console.warn('Access parent document failed:', e);
            }
        }
        return els;
    }
};

// 通用工具函数
window.TodoCMS.Utils = {

    /**
     * 防抖函数
     * @param {Function} fn  目标函数
     * @param {number}   delay 延迟毫秒数
     * @returns {Function}
     */
    debounce: function(fn, delay) {
        if (window.TodoRequest && typeof window.TodoRequest.debounce === 'function') {
            return window.TodoRequest.debounce(fn, delay);
        }
        var timer = null;
        return function() {
            var ctx  = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function() { fn.apply(ctx, args); }, delay || 300);
        };
    },

    /**
     * 节流函数（指定时间内最多执行一次）
     * @param {Function} fn  目标函数
     * @param {number}   wait 间隔毫秒数
     * @returns {Function}
     */
    throttle: function(fn, wait) {
        var lastTime = 0;
        return function() {
            var now  = Date.now();
            var ctx  = this;
            var args = arguments;
            if (now - lastTime >= (wait || 300)) {
                lastTime = now;
                fn.apply(ctx, args);
            }
        };
    },

    /**
     * 生成 UUID v4（素沼实现）
     * @returns {string}
     */
    generateUUID: function() {
        if (window.TodoRequest && typeof window.TodoRequest.generateUUID === 'function') {
            return window.TodoRequest.generateUUID();
        }
        if (typeof crypto !== 'undefined' && crypto.randomUUID) {
            return crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            var r = (Math.random() * 16) | 0;
            var v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    },

    /**
     * 关闭模态框
     * @param {string} modalId 模态框ID
     */
    closeModal: function(modalId) {
        var modal = typeof TodoDOM !== 'undefined' ? TodoDOM.get(modalId) : document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'none';
            modal.classList.remove('show');
            // 移除body的overflow hidden样式（如果有的话）
            if(document.body.getAttribute('data-overlay') === '2') {
                document.body.style.overflow = '';
                document.body.classList.remove('show-modal');
                document.body.removeAttribute('data-overlay');
            }
        }
    },
    
    /**
     * 显示模态框
     * @param {string} modalId 模态框ID
     * @param {string} displayMode 显示模式 (block, flex等，默认flex)
     */
    showModal: function(modalId, displayMode) {
        var modal = typeof TodoDOM !== 'undefined' ? TodoDOM.get(modalId) : document.getElementById(modalId);
        if (modal) {
            modal.style.display = displayMode || 'flex';
            modal.classList.add('show');
            // 防止背景滚动
            document.body.style.overflow = 'hidden';
            document.body.classList.add('show-modal');
            if(!document.body.getAttribute('data-overlay')) {
                document.body.setAttribute('data-overlay', 2);
            }
        }
    },

    /**
     * 打开模态框 (showModal 的别名)
     */
    openModal: function(modalId, displayMode) {
        this.showModal(modalId, displayMode);
    },
    
    /**
     * 更新购物车数量
     * @param {function} callback 回调函数
     */
    updateCartCount: function(callback) {
        var params = new URLSearchParams();
        params.append('cart_action', 'get');
        fetch('/includes/ajax/cart-handler.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: params.toString()
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            var count = 0;
            if (data && data.success && data.cart) {
                if (typeof data.cart.count !== 'undefined') {
                    count = data.cart.count;
                } else if (Array.isArray(data.cart.items)) {
                    count = data.cart.items.length;
                }
            } else if (data && typeof data.cart_count !== 'undefined') {
                // 兼容某些接口返回的 cart_count 字段
                count = data.cart_count;
            }
            
            // 更新所有购物车计数元素
            var cartCountElements = document.querySelectorAll('.cart-count, #cart-count, [data-cart-count]');
            cartCountElements.forEach(function(element) {
                if (element.querySelector && element.querySelector('strong')) {
                    element.querySelector('strong').textContent = count;
                } else {
                    element.textContent = count;
                }
            });
            
            // 执行回调函数
            if (typeof callback === 'function') {
                callback(count);
            }
        })
        .catch(function(error) {
            console.error('更新购物车数量失败:', error);
        });
    },
    
    /**
     * 显示消息提示
     * @param {string} message 消息内容
     * @param {string} type 消息类型 (success, error, warning, info)
     * @param {number} duration 显示时长（毫秒）
     */
    showMessage: function(message, type, duration) {
        type = type || 'info';
        duration = duration || 3000;
        
        // 优先使用统一的 TodoModal.toast
        if (typeof TodoModal !== 'undefined' && typeof TodoModal.toast === 'function') {
            TodoModal.toast(message, type, { duration: duration });
            return;
        }
        
        // 跨iframe查找父级窗口的 TodoModal
        if (window.parent && window.parent !== window && window.parent.TodoModal && typeof window.parent.TodoModal.toast === 'function') {
            try {
                window.parent.TodoModal.toast(message, type, { duration: duration });
                return;
            } catch (e) {}
        }
        
        // 创建消息元素
        var messageDiv = document.createElement('div');
        messageDiv.className = 'todo-message todo-message-' + type;
        messageDiv.innerHTML = message;
        messageDiv.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 4px;
            color: white;
            z-index: 10000;
            max-width: 300px;
            word-wrap: break-word;
        `;
        
        // 设置不同类型的背景色
        switch(type) {
            case 'success':
                messageDiv.style.backgroundColor = '#28a745';
                break;
            case 'error':
                messageDiv.style.backgroundColor = '#dc3545';
                break;
            case 'warning':
                messageDiv.style.backgroundColor = '#ffc107';
                messageDiv.style.color = '#212529';
                break;
            default:
                messageDiv.style.backgroundColor = '#17a2b8';
        }
        
        // 添加到页面
        var targetBody = document.body;
        if (window.parent && window.parent.document && window.parent.document.body && window.parent !== window) {
            try {
                targetBody = window.parent.document.body;
            } catch (e) {
                // 跨域限制或其他错误，回退到当前 body
            }
        }
        targetBody.appendChild(messageDiv);
        
        // 自动移除
        setTimeout(function() {
            if (messageDiv.parentNode) {
                messageDiv.parentNode.removeChild(messageDiv);
            }
        }, duration);
    },
    
    /**
     * 确认对话框
     * @param {string} message 确认消息
     * @param {function} callback 确认后的回调函数
     */
    confirm: function(message, callback) {
        // 支持跨框架调用：尝试在当前窗口和父窗口中查找TodoModal
        var TodoModalInstance = window.TodoModal;
        
        if (typeof TodoModalInstance === 'undefined' && window.parent && window.parent !== window) {
            try {
                if (typeof window.parent.TodoModal !== 'undefined') {
                    TodoModalInstance = window.parent.TodoModal;
                    console.log('[TodoCMS.Utils.confirm] 使用父窗口的TodoModal');
                } else if (window.top && window.top.TodoModal) {
                    TodoModalInstance = window.top.TodoModal;
                    console.log('[TodoCMS.Utils.confirm] 使用顶层窗口的TodoModal');
                }
            } catch (e) {
                console.warn('[TodoCMS.Utils.confirm] 无法访问外部窗口的TodoModal:', e.message);
            }
        }
        
        if (typeof TodoModalInstance !== 'undefined') {
            TodoModalInstance.confirm({
                title: '提示',
                message: message,
                confirmText: '确定',
                cancelText: '取消',
                onConfirm: function() {
                    if (typeof callback === 'function') {
                        callback();
                    }
                }
            });
        } else {
            // 降级方案：使用原生confirm
            if (confirm(message)) {
                if (typeof callback === 'function') {
                    callback();
                }
            }
        }
    },
    
    /**
     * 确认操作（用于链接点击或表单提交）
     * @param {HTMLElement} element 触发元素
     * @param {string} message 确认消息
     * @returns {boolean} 始终返回false以阻止默认行为
     */
    confirmAction: function(element, message) {
        var target = element;
        if (target && typeof target.preventDefault === 'function') {
            target = target.currentTarget || target.target || null;
        }

        var href = '';
        var tagName = '';
        if (typeof target === 'string') {
            href = target;
            tagName = 'a';
        } else if (target && typeof target.getAttribute === 'function') {
            href = target.getAttribute('href');
            if (!href || href === '#' || href.indexOf('javascript:') === 0) {
                href = target.getAttribute('data-href');
            }
            tagName = (target.tagName || '').toLowerCase();
        }

        var getSubmitterFromForm = function (form) {
            if (!form || !form.ownerDocument) return null;
            var active = form.ownerDocument.activeElement;
            if (!active || active.form !== form) return null;
            var t = (active.type || '').toLowerCase();
            if (t === 'submit' || t === 'image') return active;
            if (active.tagName && active.tagName.toLowerCase() === 'button') {
                var bt = (active.getAttribute('type') || 'submit').toLowerCase();
                if (bt === 'submit') return active;
            }
            return null;
        };

        var submitForm = function (form, submitter) {
            if (!form) return;
            if (window.TodoRequest && typeof window.TodoRequest.submitForm === 'function' && form.classList && form.classList.contains('todo-request')) {
                window.TodoRequest.submitForm(form, { submitter: submitter || null });
                return;
            }
            if (submitter && submitter.name) {
                var intent = form.ownerDocument.createElement('input');
                intent.type = 'hidden';
                intent.name = submitter.name;
                intent.value = submitter.value || '1';
                form.appendChild(intent);
            }
            form.submit();
        };
        
        var submitter = (tagName === 'form') ? getSubmitterFromForm(target) : (tagName === 'input' || tagName === 'button' ? target : null);
        
        TodoCMS.Utils.confirm(message, function() {
            if (tagName === 'a' && href && href !== '#' && href !== 'javascript:;') {
                // 使用 TodoRequest 统一处理重定向
                if (window.TodoRequest && typeof window.TodoRequest.redirect === 'function') {
                    window.TodoRequest.redirect(href);
                } else {
                    window.location.href = href;
                }
            } else if (tagName === 'form') {
                submitForm(target, submitter);
            } else if (tagName === 'input' || tagName === 'button') {
                 if (target && target.form) {
                     submitForm(target.form, target);
                 }
            }
        });
        
        return false;
    },
    
    /**
     * AJAX请求封装 - 委托给 TodoRequest.ajax
     * 自动注入 X-TODO-Nonce 头（如果能获取到对应 action 的CSRF令牌）
     * @param {object} options 请求选项
     *  - url: 请求地址
     *  - method: 请求方法（默认POST）
     *  - headers: 额外请求头
     *  - data: 请求体（FormData或URL编码字符串）
     *  - csrfAction: 指定用于获取令牌的动作名（可选）
     */
    ajax: function(options) {
        // 计算CSRF动作名
        var actionName = (options && options.csrfAction) || null;
        try {
            if (!actionName) {
                // 从 FormData 尝试读取
                if (options.data && typeof FormData !== 'undefined' && options.data instanceof FormData) {
                    actionName = options.data.get('action') || options.action || null;
                } else if (typeof options.data === 'string') {
                    // 从 URL 编码字符串中提取
                    var m = options.data.match(/(?:^|&)action=([^&]+)/);
                    if (m && m[1]) {
                        actionName = decodeURIComponent(m[1]);
                    }
                } else if (options && options.action) {
                    actionName = options.action;
                }
            }
        } catch (e) {
            // 忽略解析错误
        }
        
        // 注入 X-TODO-Nonce（若可获取）
        try {
            if (actionName && window.todoCSRF && typeof window.todoCSRF.getToken === 'function') {
                var token = window.todoCSRF.getToken(actionName);
                if (token) {
                    options.headers = options.headers || {};
                    options.headers['X-TODO-Nonce'] = token;
                }
            }
        } catch (e) {
            // 忽略token获取错误
        }
        
        // 委托给 TodoRequest
        if (window.TodoRequest && typeof window.TodoRequest.ajax === 'function') {
            return window.TodoRequest.ajax(options);
        }
        
        // 降级方案：直接使用 fetch
        console.warn('[TodoCMS.Utils.ajax] TodoRequest not available, using fallback');
        var defaults = {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            }
        };
        var merged = Object.assign({}, defaults, options || {});
        merged.headers = Object.assign({}, defaults.headers, (options && options.headers) || {});
        
        return fetch(merged.url, {
            method: merged.method,
            headers: merged.headers,
            body: merged.data
        })
        .then(function(response) { return response.json(); });
    },

    /**
     * 初始化标识符自动生成（拼音 + ID）
     * @param {object} options 配置项
     *  - nameInputId: 名称输入框ID
     *  - slugInputId: 标识符输入框ID
     *  - ajaxAction: AJAX 动作名
     *  - id: 当前对象ID
     *  - ajaxUrl: AJAX 地址（可选，默认 admin-ajax.php）
     *  - nameParam: 名称参数名（可选，默认 name）
     *  - idParam: ID参数名（可选，默认 id）
     */
    initSlugGenerator: function(options) {
        const nameInput = document.getElementById(options.nameInputId);
        const slugInput = document.getElementById(options.slugInputId);
        if (!nameInput || !slugInput) return;

        const hasId = Object.prototype.hasOwnProperty.call(options, 'id');
        const objectId = hasId ? options.id : 0;
        const ajaxUrl = options.ajaxUrl || 'admin-ajax.php';
        let slugTimer = null;
        let userModified = slugInput.dataset.userModified === 'true';

        const generateSlug = (name) => {
            const params = new URLSearchParams();
            params.append('action', options.ajaxAction);
            params.append('sub_action', 'generate_slug');
            params.append(options.nameParam || 'name', name);
            if (hasId) params.append(options.idParam || 'id', String(objectId));
            if (options.extraParams && typeof options.extraParams === 'object') {
                Object.keys(options.extraParams).forEach(function(key) {
                    const val = options.extraParams[key];
                    if (val === undefined || val === null) return;
                    params.append(key, String(val));
                });
            }
            
            // 尝试获取 CSRF Token
            const form = nameInput.closest('form');
            const csrfField = form ? (form.querySelector('input[name="_csrf_token"]') || form.querySelector('input[name="_todononce"]')) : null;
            if (csrfField) params.append(csrfField.name, csrfField.value);

            return fetch(ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: params.toString(),
                credentials: 'same-origin'
            }).then(res => res.json()).then(data => {
                if (data && data.success && data.slug && !userModified) {
                    slugInput.value = data.slug;
                    // 触发 input 事件以通知其他监听器（如实时验证）
                    slugInput.dispatchEvent(new Event('input'));
                }
            }).catch(() => {
                // 本地兜底：简易ASCII化
                const fallback = name.toLowerCase()
                    .normalize('NFKD').replace(/[\u0300-\u036f]/g, '')
                    .replace(/[^a-z0-9\s-]/g, '')
                    .trim().replace(/[\s-]+/g, '-')
                    .replace(/-+/g, '-').replace(/^-|-$/g, '');
                if (!userModified) {
                    slugInput.value = objectId > 0 && fallback ? (fallback + '-' + objectId) : fallback;
                }
            });
        };

        nameInput.addEventListener('input', function() {
            if (userModified) return;
            const name = this.value.trim();
            clearTimeout(slugTimer);
            slugTimer = setTimeout(() => {
                if (name) {
                    generateSlug(name);
                } else {
                    slugInput.value = '';
                }
            }, 300);
        });

        slugInput.addEventListener('input', function(e) {
            if (e && e.isTrusted === false) return;
            const current = (this.value || '').trim();
            if (current === '') {
                userModified = false;
                delete this.dataset.userModified;
                return;
            }
            userModified = true;
            this.dataset.userModified = 'true';
        });
    },

    ImagePreview: (function() {
        function isJqueryObject(obj) {
            return !!(obj && obj.jquery);
        }

        function unwrap(obj) {
            if (!obj) return null;
            if (isJqueryObject(obj)) return obj[0] || null;
            return obj;
        }

        function getPreviewImg(containerEl, options) {
            var previewClass = (options && options.previewClass) ? String(options.previewClass) : 'previewimg';
            var previewSelector = (options && options.previewSelector) ? String(options.previewSelector) : ('img.' + previewClass);
            return containerEl ? containerEl.querySelector(previewSelector) : null;
        }

        function getSourceImg(containerEl, options) {
            var previewClass = (options && options.previewClass) ? String(options.previewClass) : 'previewimg';
            var sourceSelector = (options && options.sourceSelector) ? String(options.sourceSelector) : ('img:not(.' + previewClass + ')');
            return containerEl ? containerEl.querySelector(sourceSelector) : null;
        }

        function ensureCloneInContainer(container, options) {
            var containerEl = unwrap(container);
            if (!containerEl) return null;

            var existing = getPreviewImg(containerEl, options);
            if (existing) return existing;

            var src = getSourceImg(containerEl, options);
            if (!src) return null;

            var previewClass = (options && options.previewClass) ? String(options.previewClass) : 'previewimg';
            var clone = src.cloneNode(true);
            if (clone && clone.classList) {
                clone.classList.add(previewClass);
            }
            containerEl.appendChild(clone);
            return clone;
        }

        function ensureClones(root, options) {
            var rootEl = unwrap(root);
            if (!rootEl) return;

            var selector = options && options.containerSelector ? String(options.containerSelector) : '';
            if (!selector) {
                ensureCloneInContainer(rootEl, options);
                return;
            }

            var nodes = rootEl.querySelectorAll(selector);
            if (!nodes || nodes.length === 0) return;
            for (var i = 0; i < nodes.length; i++) {
                ensureCloneInContainer(nodes[i], options);
            }
        }

        function resetPosition(container, options) {
            var containerEl = unwrap(container);
            if (!containerEl) return;

            var img = getPreviewImg(containerEl, options);
            if (!img) return;

            img.style.left = '';
            img.style.marginLeft = '';
            img.style.right = '';
            img.style.marginRight = '';
        }

        function adjustPosition(container, options) {
            var containerEl = unwrap(container);
            if (!containerEl) return;

            var img = getPreviewImg(containerEl, options);
            if (!img) return;

            var edgeCompensation = (options && typeof options.edgeCompensation === 'number') ? options.edgeCompensation : -30;

            var run = function() {
                if (!img || !document.documentElement.contains(img)) return;

                var computed = window.getComputedStyle ? window.getComputedStyle(img) : null;
                var isHidden = !img.getClientRects().length || (computed && computed.display === 'none');
                var prevDisplay = img.style.display;
                var prevVisibility = img.style.visibility;

                if (isHidden) {
                    img.style.display = 'block';
                    img.style.visibility = 'hidden';
                }

                var rect = img.getBoundingClientRect();
                var vw = document.documentElement.clientWidth || window.innerWidth || 0;

                if (rect.left < 0) {
                    img.style.left = '0px';
                    img.style.marginLeft = edgeCompensation + 'px';
                } else if (rect.right > vw) {
                    img.style.right = '0px';
                    img.style.marginRight = edgeCompensation + 'px';
                }

                var scrollParentTop = null;
                var parentNode = containerEl.parentElement;
                while (parentNode && parentNode !== document.body && parentNode !== document.documentElement) {
                    var parentStyle = window.getComputedStyle ? window.getComputedStyle(parentNode) : null;
                    if (parentStyle) {
                        var overflowY = parentStyle.overflowY || parentStyle.overflow || '';
                        if (overflowY === 'hidden' || overflowY === 'scroll' || overflowY === 'auto') {
                            scrollParentTop = parentNode.getBoundingClientRect().top;
                            break;
                        }
                    }
                    parentNode = parentNode.parentElement;
                }

                if (scrollParentTop !== null && rect.top < scrollParentTop) {
                    img.style.top = '140%';
                } else if (rect.top < 0) {
                    img.style.top = '88px';
                }

                if (isHidden) {
                    img.style.display = prevDisplay || '';
                    img.style.visibility = prevVisibility || '';
                }
            };

            if (typeof requestAnimationFrame === 'function') {
                requestAnimationFrame(run);
            } else {
                setTimeout(run, 0);
            }
        }

        return {
            ensureCloneInContainer: ensureCloneInContainer,
            ensureClones: ensureClones,
            adjustPosition: adjustPosition,
            resetPosition: resetPosition
        };
    })()
};

// 暴露常用函数到全局（兼容旧代码）
window.closeModal = window.TodoCMS.Utils.closeModal;
window.showModal = window.TodoCMS.Utils.showModal;
window.openModal = window.TodoCMS.Utils.openModal;
window.updateCartCount = window.TodoCMS.Utils.updateCartCount;

// 初始化脚本（如需）
document.addEventListener('DOMContentLoaded', function() {
    try {
        var search = window.location.search || '';
        var isIframeMode = /(?:^|[?&])format=iframe(?:&|$)/.test(search);

        // 只有在iframe模式下才自动补全URL
        if (!isIframeMode) {
            return;
        }

        // 使用 TodoRequest.normalizeUrl 统一处理 URL
        if (!window.TodoRequest || typeof window.TodoRequest.normalizeUrl !== 'function') {
            console.warn('[TodoCMS] TodoRequest.normalizeUrl not available');
            return;
        }

        function isInternalAdminUrl(url) {
            if (!url) return false;
            if (url.charAt(0) === '#') return false;
            if (url.indexOf('javascript:') === 0) return false;

            // 绝对URL：必须同源且指向/admin/index.php
            if (/^https?:\/\//i.test(url)) {
                var loc = window.location;
                if (url.indexOf(loc.protocol + '//' + loc.host) !== 0) {
                    return false;
                }
                return url.indexOf('/admin/index.php') !== -1;
            }

            // 相对URL：只处理后台index.php或以?开头的内部链接
            if (url.indexOf('index.php') === 0) return true;
            if (url.indexOf('?') === 0) return true;

            return false;
        }

        // 处理所有链接
        var links = document.querySelectorAll('a[href]');
        links.forEach(function(link) {
            var href = link.getAttribute('href');
            if (!isInternalAdminUrl(href)) {
                return;
            }
            link.setAttribute('href', window.TodoRequest.normalizeUrl(href));
        });

        // 处理所有表单
        var forms = document.querySelectorAll('form');
        forms.forEach(function(form) {
            var action = form.getAttribute('action');
            if (!action || action === '') {
                // 未显式指定action时，浏览器默认提交到当前URL
                action = window.location.pathname + window.location.search;
            }
            if (!isInternalAdminUrl(action)) {
                return;
            }
            form.setAttribute('action', window.TodoRequest.normalizeUrl(action));
        });
    } catch (e) {
        console.warn('[TodoCMS] iframe URL 补全失败:', e);
    }
});

document.addEventListener('click', function (e) {
    if (e.defaultPrevented) {
        return;
    }
    var t = e.target;
    if (!t) return;
    var a = t.closest ? t.closest('a.js-todo-post-action') : null;
    if (!a) return;
    var href = a.getAttribute('href') || '';
    if (!href || href === '#' || href.indexOf('javascript:') === 0) return;
    e.preventDefault();

    var confirmMsg = a.getAttribute('data-confirm') || '';
    var doPost = function () {
        // ── 防重复点击：链接正在处理中则忽略（锁放在确认之后，取消确认不会残留锁） ──
        if (a._todoActionBusy) {
            return;
        }
        a._todoActionBusy = true;
        if (!window.TodoRequest || typeof window.TodoRequest.ajax !== 'function') {
            a._todoActionBusy = false;
            window.location.href = href;
            return;
        }
        var fd = new FormData();
        try {
            var u = new URL(href, window.location.href);
            u.searchParams.forEach(function (v, k) {
                fd.append(k, v);
            });
        } catch (err) {
        }
        if (!fd.has('action')) {
            var act = a.getAttribute('data-action') || '';
            if (act) fd.append('action', act);
        }
        if (!fd.has('_todononce')) {
            try {
                var nonceEl = document.querySelector('input[name="_todononce"]');
                var nonceVal = nonceEl && nonceEl.value ? ('' + nonceEl.value) : '';
                if (nonceVal) fd.append('_todononce', nonceVal);
            } catch (err) {
            }
        }
        window.TodoRequest.ajax({
            url: href,
            method: 'POST',
            data: fd,
            onComplete: function() {
                // 请求完成后释放锁定（无论成功还是失败）
                a._todoActionBusy = false;
            },
            onError: function() {
                a._todoActionBusy = false;
            }
        });
    };

    if (confirmMsg) {
        try {
            if (window.parent && window.parent !== window && window.parent.TodoCMS && window.parent.TodoCMS.Utils && typeof window.parent.TodoCMS.Utils.confirm === 'function') {
                window.parent.TodoCMS.Utils.confirm(confirmMsg, doPost);
                return;
            }
        } catch (err) {
        }
        if (window.TodoCMS && TodoCMS.Utils && typeof TodoCMS.Utils.confirm === 'function') {
            TodoCMS.Utils.confirm(confirmMsg, doPost);
            return;
        }
        if (confirm(confirmMsg)) {
            doPost();
        }
        return;
    }

    doPost();
});
