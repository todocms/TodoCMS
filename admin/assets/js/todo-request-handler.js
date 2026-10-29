/**
 * TodoCMS 统一请求处理器
 * 
 * 解决iframe和非iframe模式下的请求处理差异
 * 提供统一的API用于表单提交、AJAX请求、重定向和弹窗显示
 * 内置防重复提交（PRG）和防抖机制
 * 
 * @version 1.1.0
 * @package TodoCMS
 */

(function(window) {
    'use strict';

    // 工具：生成 UUID v4（用作唯一提交键）
    function generateUUID() {
        if (typeof crypto !== 'undefined' && crypto.randomUUID) {
            return crypto.randomUUID();
        }
        // 兼容旧环境
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            var r = (Math.random() * 16) | 0;
            var v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    // 工具：防抖函数
    function debounce(fn, delay) {
        var timer = null;
        return function() {
            var ctx  = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function() {
                fn.apply(ctx, args);
            }, delay);
        };
    }

    // 提交守卫：防止并发/重复请求
    var SubmitGuard = {
        /**
         * 进行中的请求集合（指纹 → Promise）
         * 相同指纹的并发请求直接返回已有 Promise，不重复发送
         */
        _inflight: {},

        /**
         * 根据 URL + 方法 + 数据 生成请求指纹
         * 只用于 POST/PUT/DELETE/PATCH 写操作
         */
        fingerprint: function(url, method, data) {
            var m = (method || 'POST').toUpperCase();
            if (m === 'GET' || m === 'HEAD') return null;
            var dataStr = '';
            try {
                if (data instanceof FormData) {
                    // FormData 无法直接序列化，取前几个字段作为指纹
                    var pairs = [];
                    var count = 0;
                    data.forEach(function(v, k) {
                        if (count < 8 && typeof v === 'string') {
                            pairs.push(k + '=' + v.substring(0, 64));
                            count++;
                        }
                    });
                    dataStr = pairs.join('&');
                } else if (typeof data === 'string') {
                    dataStr = data.substring(0, 512);
                } else if (data && typeof data === 'object') {
                    dataStr = JSON.stringify(data).substring(0, 512);
                }
            } catch (e) {}
            return m + ':' + (url || '') + '|' + dataStr;
        },

        /**
         * 判断是否有相同指纹的请求正在进行中
         */
        isInflight: function(fp) {
            return fp && !!this._inflight[fp];
        },

        /**
         * 注册进行中的请求
         */
        register: function(fp, promise) {
            if (!fp) return;
            this._inflight[fp] = promise;
        },

        /**
         * 注销请求（完成或失败后调用）
         */
        release: function(fp) {
            if (!fp) return;
            delete this._inflight[fp];
        }
    };

    // 工具：表单提交按钮加载态管理
    var FormLoadingState = {
        /**
         * 禁用表单所有提交按钮，记录原始文本
         * @param {HTMLFormElement} form
         * @param {HTMLElement|null} submitter 触发提交的按钮（优先处理）
         * @returns {Function} restore — 调用后恢复按钮状态
         */
        lock: function(form, submitter) {
            var restoreList = [];

            function lockBtn(btn, isMain) {
                if (!btn || btn.disabled) return;
                var origText  = btn.textContent || btn.value || '';
                var origHtml  = btn.innerHTML;
                var origClass = btn.className;
                var loadText  = btn.getAttribute('data-loading-text') || (isMain ? '提交中...' : origText);

                btn.disabled = true;
                btn.setAttribute('data-original-text', origHtml);
                btn.setAttribute('aria-busy', 'true');
                btn.classList.add('todo-btn-loading');

                if (isMain) {
                    // 主按钮：注入 spinner + 文字
                    btn.innerHTML =
                        '<span class="todo-btn-spinner" aria-hidden="true"></span>' +
                        '<span class="todo-btn-label">' + loadText + '</span>';
                }

                restoreList.push(function() {
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                    btn.className = origClass;
                    btn.removeAttribute('aria-busy');
                    btn.classList.remove('todo-btn-loading');
                    btn.removeAttribute('data-original-text');
                });
            }

            try {
                if (submitter) {
                    lockBtn(submitter, true);
                }
                // 同时锁定表单内其他提交按钮（防止切换按钮重复提交）
                var ownerDoc = (form && form.ownerDocument) ? form.ownerDocument : document;
                var btns = form ? form.querySelectorAll(
                    '[type="submit"],[type="image"],button:not([type="button"]):not([type="reset"])'
                ) : [];
                for (var i = 0; i < btns.length; i++) {
                    if (btns[i] !== submitter) {
                        lockBtn(btns[i], false);
                    }
                }
            } catch (e) {}

            // 返回恢复函数
            return function restore() {
                restoreList.forEach(function(fn) { try { fn(); } catch (e) {} });
            };
        }
    };

    // 检测当前是否在iframe模式
    function isIframeMode() {
        var search = window.location.search || '';
        return /(?:^|[?&])format=iframe(?:&|$)/.test(search);
    }

    // 检测是否在iframe中运行
    function isInIframe() {
        try {
            return window.self !== window.top;
        } catch (e) {
            return true;
        }
    }

    /**
     * TodoRequest - 统一请求处理器
     */
    var TodoRequest = {
        
        /**
         * 获取当前运行模式
         * @returns {string} 'iframe' | 'normal'
         */
        getMode: function() {
            return (isIframeMode() || isInIframe()) ? 'iframe' : 'normal';
        },

        /**
         * 规范化URL - 根据当前模式自动添加format参数
         * @param {string} url - 原始URL
         * @param {boolean} forceMode - 强制指定模式 ('iframe' | 'normal' | null)
         * @returns {string} 规范化后的URL
         */
        normalizeUrl: function(url, forceMode) {
            if (!url || url.charAt(0) === '#' || url.indexOf('javascript:') === 0) {
                return url;
            }

            var mode = forceMode || this.getMode();
            if (/[?&]target=top(?:&|$)/.test(url) || /([?&])format=normal(?:&|$)/.test(url)) {
                mode = 'normal';
            }
            
            // 分离hash
            var hash = '';
            var hashIndex = url.indexOf('#');
            if (hashIndex !== -1) {
                hash = url.substring(hashIndex);
                url = url.substring(0, hashIndex);
            }

            url = url.replace(/([?&])target=top(&|$)/g, function(match, p1, p2) {
                return p2 ? p1 : '';
            });

            // 移除现有的format参数
            url = url.replace(/([?&])format=(iframe|normal)(&|$)/g, function(match, p1, p2, p3) {
                return p3 ? p1 : '';
            });

            // 添加format参数
            if (mode === 'iframe') {
                var sep = url.indexOf('?') === -1 ? '?' : '&';
                url = url + sep + 'format=iframe';
            }

            return url + hash;
        },

        /**
         * 提交表单 - 统一处理iframe和非iframe模式
         * @param {HTMLFormElement|string} form - 表单元素或表单ID
         * @param {object} options - 选项
         *   - onSuccess: 成功回调
         *   - onError: 错误回调
         *   - async: 是否异步提交（默认true）
         */
        submitForm: function(form, options) {
            options = options || {};
            
            if (typeof form === 'string') {
                form = document.getElementById(form) || document.querySelector(form);
            }

            if (!form || form.tagName !== 'FORM') {
                console.error('[TodoRequest] Invalid form element');
                return;
            }

            // ── 防重复提交：如果表单已处于提交中状态，直接忽略 ──
            if (form._todoSubmitting) {
                console.warn('[TodoRequest] Form is already submitting, ignored.');
                return;
            }
            form._todoSubmitting = true;

            // 规范化表单action
            var action = form.getAttribute('action') || window.location.href;
            form.setAttribute('action', this.normalizeUrl(action));

            // 异步提交
            if (options.async !== false) {
                this._submitFormAsync(form, options, options.submitter || null);
            } else {
                // 同步提交（传统 POST 跳转）
                // 同步提交完成后浏览器会卸载页面，无需手动恢复状态
                if (window.HTMLFormElement && window.HTMLFormElement.prototype && typeof window.HTMLFormElement.prototype.submit === 'function') {
                    window.HTMLFormElement.prototype.submit.call(form);
                } else {
                    form.submit();
                }
            }
        },

        /**
         * 异步提交表单
         * @private
         */
        _submitFormAsync: function(form, options, submitter) {
            var self = this;

            // ── 加载态：禁用提交按钮，防止二次点击 ──
            var restoreButtons = FormLoadingState.lock(form, submitter);

            // 释放提交锁 + 恢复按钮的统一函数
            var releaseForm = function() {
                form._todoSubmitting = false;
                restoreButtons();
            };

            var actionUrl = form.getAttribute('action') || window.location.href;
            actionUrl = this.normalizeUrl(actionUrl);

            var methodAttr = form.getAttribute('method') || '';
            var method = 'POST';
            if (typeof methodAttr === 'string' && methodAttr !== '') {
                method = methodAttr;
            } else if (typeof form.method === 'string' && form.method !== '') {
                method = form.method;
            }

            var formData;
            try {
                // 关键点：跨框架提交时，必须使用表单所属窗口的 FormData 构造函数
                // 否则获取不到表单内的数据（跨 execution context 的限制）
                var FormDataConstructor = (form.ownerDocument && form.ownerDocument.defaultView && form.ownerDocument.defaultView.FormData) 
                    ? form.ownerDocument.defaultView.FormData 
                    : window.FormData;
                
                if (submitter) {
                    formData = new FormDataConstructor(form, submitter);
                } else {
                    formData = new FormDataConstructor(form);
                }
            } catch (e) {
                console.warn('[TodoRequest] Cross-context FormData creation failed, falling back to local constructor', e);
                formData = new FormData(form);
            }

            if (submitter && submitter.name && !formData.has(submitter.name)) {
                formData.append(submitter.name, submitter.value || '1');
            }

            // 确保包含format参数
            if (this.getMode() === 'iframe' && !formData.has('format')) {
                formData.append('format', 'iframe');
            }

            var methodUpper = (method || 'POST').toString().toUpperCase();
            if (methodUpper === 'GET' || methodUpper === 'HEAD') {
                var nextUrl;
                try {
                    var u = new URL(actionUrl, window.location.href);
                    formData.forEach(function(value, key) {
                        if (value && (typeof File !== 'undefined') && (value instanceof File)) {
                            return;
                        }
                        if (value && (typeof Blob !== 'undefined') && (value instanceof Blob)) {
                            return;
                        }
                        u.searchParams.set(key, value);
                    });
                    nextUrl = u.toString();
                } catch (e) {
                    var qs = new URLSearchParams();
                    formData.forEach(function(value, key) {
                        if (value && (typeof File !== 'undefined') && (value instanceof File)) {
                            return;
                        }
                        if (value && (typeof Blob !== 'undefined') && (value instanceof Blob)) {
                            return;
                        }
                        qs.set(key, value);
                    });
                    nextUrl = actionUrl + (actionUrl.indexOf('?') === -1 ? '?' : '&') + qs.toString();
                }

                releaseForm();
                self.redirect(nextUrl);
                return;
            }

            // ── 生成唯一提交键，附加到 FormData 和请求头 ──
            var submitKey = generateUUID();
            formData.append('_submit_key', submitKey);

            var reqHeaders = {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-Todo-Submit-Key': submitKey
            };

            fetch(actionUrl, {
                method: method || 'POST',
                body: formData,
                headers: reqHeaders
            })
            .then(function(response) {
                var contentType = (response.headers && response.headers.get) ? (response.headers.get('content-type') || '') : '';
                if (contentType.indexOf('application/json') !== -1) {
                    return response.json();
                }
                return response.text().then(function(text) {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error('Non-JSON response: ' + (text || '').slice(0, 200));
                    }
                });
            })
            .then(function(data) {
                releaseForm();
                self._handleResponse(data, options);
            })
            .catch(function(error) {
                releaseForm();
                console.error('[TodoRequest] Form submission failed:', error);
                if (options.onError) {
                    options.onError(error);
                }
            });
        },

        /**
         * AJAX请求 - 统一处理
         * @param {object} options - 请求选项
         *   - url: 请求URL
         *   - method: 请求方法（默认POST）
         *   - data: 请求数据（FormData或对象）
         *   - onSuccess: 成功回调
         *   - onError: 错误回调
         */
        ajax: function(options) {
            var self = this;
            options = options || {};

            var url = this.normalizeUrl(options.url || window.location.href);
            var method = options.method || 'POST';
            var data = options.data;
            var methodUpper = (method || 'POST').toUpperCase();

            // ── 写操作防重复并发：生成请求指纹，相同指纹的并发请求直接返回第一个 Promise ──
            var isWriteOp = (methodUpper !== 'GET' && methodUpper !== 'HEAD');
            var fp = isWriteOp ? SubmitGuard.fingerprint(url, methodUpper, data) : null;

            if (fp && SubmitGuard.isInflight(fp) && options.allowConcurrent !== true) {
                console.warn('[TodoRequest] Duplicate concurrent request blocked:', url);
                // 如果调用方希望知道被跳过了，可以通过 onSkip 回调处理
                if (typeof options.onSkip === 'function') {
                    options.onSkip();
                }
                return;
            }

            // 处理数据
            var body;
            var headers = {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            };

            // ── 为写操作附加唯一提交键（供服务端 PRG 幂等守卫使用）──
            var submitKey = '';
            if (isWriteOp && options.noSubmitKey !== true) {
                submitKey = generateUUID();
                headers['X-Todo-Submit-Key'] = submitKey;
            }

            if (data && (data instanceof FormData || Object.prototype.toString.call(data) === '[object FormData]')) {
                body = data;
                // 确保包含format参数
                if (this.getMode() === 'iframe' && !data.has('format')) {
                    data.append('format', 'iframe');
                }
                // 写操作时将提交键注入 FormData（供 PHP $_POST 读取）
                if (submitKey && !data.has('_submit_key')) {
                    data.append('_submit_key', submitKey);
                }
            } else if (typeof data === 'object' && data !== null) {
                // 检查是否是跨窗口的 HTMLFormElement
                if (data.tagName === 'FORM') {
                    var FormDataConstructor = (data.ownerDocument && data.ownerDocument.defaultView && data.ownerDocument.defaultView.FormData) 
                        ? data.ownerDocument.defaultView.FormData 
                        : window.FormData;
                    body = new FormDataConstructor(data);
                } else {
                    // 转换为FormData
                    body = new FormData();
                    for (var key in data) {
                        if (data.hasOwnProperty(key)) {
                            var value = data[key];
                            if (Array.isArray(value)) {
                                // 处理数组：使用 key[] 格式追加多个值
                                for (var i = 0; i < value.length; i++) {
                                    body.append(key + '[]', value[i]);
                                }
                            } else {
                                body.append(key, value);
                            }
                        }
                    }
                }
                if (this.getMode() === 'iframe' && !body.has('format')) {
                    body.append('format', 'iframe');
                }
                if (submitKey && !body.has('_submit_key')) {
                    body.append('_submit_key', submitKey);
                }
            } else if (typeof data === 'string') {
                body = data;
                headers['Content-Type'] = 'application/x-www-form-urlencoded';
                // 添加format参数
                if (this.getMode() === 'iframe' && !/[?&]format=/.test(body)) {
                    body += (body ? '&' : '') + 'format=iframe';
                }
                // 写操作注入提交键
                if (submitKey && body.indexOf('_submit_key=') === -1) {
                    body += (body ? '&' : '') + '_submit_key=' + submitKey;
                }
            }

            var promise = fetch(url, {
                method: method,
                body: body,
                headers: headers
            })
            .then(function(response) {
                var contentType = (response.headers && response.headers.get) ? (response.headers.get('content-type') || '') : '';
                if (contentType.indexOf('application/json') !== -1) {
                    return response.json();
                }
                return response.text().then(function(text) {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error('Non-JSON response: ' + (text || '').slice(0, 200));
                    }
                });
            })
            .then(function(data) {
                SubmitGuard.release(fp);
                self._handleResponse(data, options);
                if (options.onComplete) {
                    options.onComplete(data);
                }
            })
            .catch(function(error) {
                SubmitGuard.release(fp);
                console.error('[TodoRequest] AJAX request failed:', error);
                if (options.onError) {
                    options.onError(error);
                }
                if (options.onComplete) {
                    options.onComplete(error);
                }
            });

            // 注册进行中的请求
            if (fp) {
                SubmitGuard.register(fp, promise);
            }

            return promise;
        },

        /**
         * 处理响应 - 统一处理成功、错误、重定向
         * @private
         */
        _handleResponse: function(data, options) {
            if (data && data.data && typeof data.data.html === 'string' && data.data.html !== '') {
                try {
                    document.open();
                    document.write(data.data.html);
                    document.close();
                } catch (e) {
                    window.location.reload();
                }
                return;
            }

            // 执行回调 (优先执行，确保关闭弹窗等清理逻辑不被跳过)
            if (data.success && options.onSuccess) {
                options.onSuccess(data);
            } else if (!data.success && options.onError) {
                options.onError(data);
            }

            // 处理重定向
            if (data.redirect) {
                this.redirect(data.redirect);
                return;
            }

            // 处理消息提示
            if (data.message) {
                var type = data.success ? 'success' : 'error';
                this.showMessage(data.message, type);
            }
        },

        /**
         * 重定向 - 统一处理iframe和非iframe模式
         * @param {string} url - 目标URL
         */
        redirect: function(url) {
            url = this.normalizeUrl(url);

            if (this.getMode() === 'iframe' && isInIframe()) {
                // 如果URL中明确包含 format=iframe，则在当前iframe内跳转
                if (/(?:^|[?&])format=iframe(?:&|$)/.test(url)) {
                    window.location.href = url;
                    return;
                }

                // 否则更新父窗口URL（跳出iframe）
                try {
                    window.parent.location.href = url.replace(/[?&]format=iframe(&|$)/, function(m, p1) {
                        return p1 === '&' ? '&' : '';
                    });
                } catch (e) {
                    // 跨域限制，回退到当前窗口重定向
                    window.location.href = url;
                }
            } else {
                // 正常模式：直接重定向
                window.location.href = url;
            }
        },

        /**
         * 显示消息 - 委托给 TodoCMS.Utils.showMessage
         * @param {string} message - 消息内容
         * @param {string} type - 消息类型 (success, error, warning, info)
         * @param {number} duration - 显示时长（毫秒）
         */
        showMessage: function(message, type, duration) {
            if (window.TodoCMS && window.TodoCMS.Utils && window.TodoCMS.Utils.showMessage) {
                window.TodoCMS.Utils.showMessage(message, type, duration);
            } else if (typeof TodoModal !== 'undefined' && typeof TodoModal.toast === 'function') {
                TodoModal.toast(message, type, { duration: duration });
            }
        },

        /**
         * 显示模态框 - 统一处理iframe和非iframe模式
         * @param {object} options - 模态框选项
         */
        showModal: function(options) {
            // 在iframe模式下，尝试在父窗口显示
            if (this.getMode() === 'iframe' && isInIframe()) {
                try {
                    if (window.parent.TodoModal) {
                        window.parent.TodoModal.show(options);
                        return;
                    }
                } catch (e) {
                    console.warn('[TodoRequest] Cannot access parent modal:', e);
                }
            }

            // 降级方案：在当前窗口显示
            if (window.TodoModal) {
                window.TodoModal.show(options);
            }
        }
    };

    // 暴露到全局
    window.TodoRequest = TodoRequest;

    // 兼容旧代码
    window.todoRequest = TodoRequest;

    // 暴露内部工具（供高级用例使用）
    TodoRequest.generateUUID     = generateUUID;
    TodoRequest.debounce         = debounce;
    TodoRequest.SubmitGuard      = SubmitGuard;
    TodoRequest.FormLoadingState = FormLoadingState;

    // 自动绑定 .todo-request 表单
    document.addEventListener('submit', function(e) {
        if (e.defaultPrevented) {
            return;
        }
        var target = e.target;
        if (target && target.tagName === 'FORM' && target.classList.contains('todo-request')) {
            e.preventDefault();
            TodoRequest.submitForm(target, { submitter: e.submitter || null });
        }
    });

    // 全局 PRG：监听普通（非 .todo-request）表单的重复提交
    // 对含有 data-prg="true" 属性的表单提供原生表单级防重保护
    document.addEventListener('submit', function(e) {
        if (e.defaultPrevented) return;
        var target = e.target;
        if (!target || target.tagName !== 'FORM') return;
        // 已被 .todo-request 处理的表单不再处理
        if (target.classList.contains('todo-request')) return;
        // 仅对 data-prg="true" 标记的表单做原生防重
        if (target.getAttribute('data-prg') !== 'true') return;

        if (target._todoSubmitting) {
            e.preventDefault();
            console.warn('[TodoRequest] Native form duplicate submit blocked.');
            return;
        }
        target._todoSubmitting = true;
        // 同步提交：浏览器页面跳走后自动释放，无需手动恢复
    }, true); // 捕获阶段，确保在 HTML submit handler 之前拦截

})(window);
