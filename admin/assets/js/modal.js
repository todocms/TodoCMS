/**
 * 通用弹窗组件
 * @package TodoCMS
 * @version 2.0.0
 * 
 * 功能特性：
 * - 基础弹窗（支持自定义内容）
 * - 确认对话框
 * - 删除确认对话框
 * - 消息提示（成功、错误、警告、信息）
 * - 加载状态遮罩（全局和局部）
 * - 进度弹窗
 * - Toast 消息提示
 * - 表单弹窗（增删改查）
 */

(function() {
    'use strict';

    /**
     * 获取目标文档（支持跨框架）
     * 优先返回父窗口文档，确保弹窗显示在最外层
     */
    function getDoc() {
        try {
            if (window.parent && window.parent.document && window.parent !== window) {
                return window.parent.document;
            }
        } catch (e) {
        }
        return document;
    }

    /**
     * 弹窗主类
     */
    class TodoModal {
        constructor(options = {}) {
            this.userOptions = { ...options };
            this.options = {
                size: 'md', // sm, md, lg, xl, fullscreen
                type: 'default', // default, danger, success, warning, info
                closable: true,
                backdrop: true, // 点击背景关闭
                keyboard: true, // ESC键关闭
                animation: true, // 动画效果
                destroyOnClose: true, // 关闭时销毁
                zIndex: 9999,
                width: null,
                maxWidth: null,
                ...options
            };
            
            this.isOpen = false;
            this.element = null;
            this.container = null;
            this.onConfirm = null;
            this.onCancel = null;
            this.onClose = null;
            this.escHandler = null;
            this.loadingOverlay = null;
            this.backdropDownHandler = null;
            this.backdropClickHandler = null;
            this.backdropDownEvent = null;
            this._backdropPressed = false;
            
            if (!TodoModal._instances) {
                TodoModal._instances = new Set();
            }
            TodoModal._instances.add(this);

            this.applyTemplateOptions();
            this.init();
        }
        
        applyTemplateOptions() {
            if (!this.options.templateId) return;
            const tmpl = document.getElementById(this.options.templateId);
            if (!tmpl) return;

            const boolAttr = (value, defaultValue) => {
                if (value === undefined || value === null || value === '') return defaultValue;
                const normalized = String(value).toLowerCase();
                if (normalized === '0' || normalized === 'false' || normalized === 'no') return false;
                if (normalized === '1' || normalized === 'true' || normalized === 'yes') return true;
                return defaultValue;
            };

            const size = tmpl.getAttribute('data-size');
            const type = tmpl.getAttribute('data-type');
            const zIndex = tmpl.getAttribute('data-z-index');
            const width = tmpl.getAttribute('data-width');
            const maxWidth = tmpl.getAttribute('data-max-width');

            if (size && this.userOptions.size === undefined) this.options.size = size;
            if (type && this.userOptions.type === undefined) this.options.type = type;
            if (zIndex && this.userOptions.zIndex === undefined) this.options.zIndex = Number(zIndex) || this.options.zIndex;
            if (width && this.userOptions.width === undefined) this.options.width = width;
            if (maxWidth && this.userOptions.maxWidth === undefined) this.options.maxWidth = maxWidth;

            if (this.userOptions.closable === undefined) this.options.closable = boolAttr(tmpl.getAttribute('data-closable'), this.options.closable);
            if (this.userOptions.backdrop === undefined) this.options.backdrop = boolAttr(tmpl.getAttribute('data-backdrop'), this.options.backdrop);
            if (this.userOptions.keyboard === undefined) this.options.keyboard = boolAttr(tmpl.getAttribute('data-keyboard'), this.options.keyboard);
            if (this.userOptions.animation === undefined) this.options.animation = boolAttr(tmpl.getAttribute('data-animation'), this.options.animation);
            if (this.userOptions.destroyOnClose === undefined) this.options.destroyOnClose = boolAttr(tmpl.getAttribute('data-destroy-on-close'), this.options.destroyOnClose);

            const title = tmpl.getAttribute('data-title');
            if (title && this.userOptions.title === undefined) this.options.title = title;
        }

        init() {
            const doc = getDoc();
            // 创建弹窗HTML结构
            this.element = doc.createElement('div');
            this.element.className = 'todo-modal-overlay';
            this.element.style.zIndex = this.options.zIndex;
            this.element.innerHTML = this.getTemplate();
            
            // 添加到页面
            doc.body.appendChild(this.element);
            
            // 获取容器引用
            this.container = this.element.querySelector('.todo-modal-container');
            this.applyDimensions();
            
            // 设置标题（如果提供了）
            if (this.options.title) {
                this.setTitle(this.options.title);
            }

            // 处理模板ID（如果提供了）
            if (this.options.templateId) {
                this.applyTemplateSlots(this.options.templateId);
            }
            // 处理内容（如果提供了）
            else if (this.options.content) {
                this.setContent(this.options.content);
            }
            
            // 设置底部按钮（如果提供了）
            if (this.options.footerContent !== undefined) {
                this.setFooterContent(this.options.footerContent);
            } else if (this.options.footer) {
                this.setFooter(this.options.footer);
            }
            
            // 绑定事件
            this.bindEvents();
        }

        applyTemplateSlots(templateId) {
            const tmpl = document.getElementById(templateId);
            if (!tmpl || !tmpl.content) return this;

            const fragment = tmpl.content.cloneNode(true);

            const pickSlot = (slotName) => {
                return fragment.querySelector(`[data-slot="${slotName}"], [data-todo-slot="${slotName}"], [data-modal-slot="${slotName}"]`);
            };

            const titleSlot = pickSlot('title');
            if (titleSlot && this.userOptions.title === undefined) {
                const t = (titleSlot.textContent || '').trim();
                if (t) this.setTitle(t);
            }
            if (titleSlot && titleSlot.parentNode) titleSlot.parentNode.removeChild(titleSlot);

            const footerSlot = pickSlot('footer');
            if (footerSlot && this.userOptions.footer === undefined && this.userOptions.footerContent === undefined) {
                const nodes = Array.from(footerSlot.childNodes);
                this.setFooterContent(nodes);
                if (footerSlot.parentNode) footerSlot.parentNode.removeChild(footerSlot);
            }

            const bodySlot = pickSlot('body');
            if (bodySlot) {
                const nodes = Array.from(bodySlot.childNodes);
                this.setContent(nodes);
            } else {
                this.setContent(fragment);
            }

            return this;
        }
        
        getTemplate() {
            const sizeClass = `todo-modal-${this.options.size}`;
            const typeClass = this.options.type !== 'default' ? ` todo-modal-${this.options.type}` : '';
            const animationClass = this.options.animation ? ' todo-modal-animated' : '';
            
            return `
                <div class="todo-modal-container ${sizeClass}${typeClass}${animationClass}">
                    <div class="todo-modal-header">
                        <h3 class="todo-modal-title"></h3>
                        ${this.options.closable ? '<button type="button" class="todo-modal-close" aria-label="关闭">✕</button>' : ''}
                    </div>
                    <div class="todo-modal-body"></div>
                    <div class="todo-modal-footer" style="display: none;"></div>
                    <div class="todo-modal-loading-overlay" style="display: none;">
                        <div class="todo-modal-spinner"></div>
                        <div class="todo-modal-loading-text">加载中...</div>
                    </div>
                </div>
            `;
        }
        
        bindEvents() {
            const closeButtons = this.element.querySelectorAll('.todo-modal-close, .todo-modal-cancel');
            
            // 关闭按钮事件
            closeButtons.forEach((btn) => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.close();
                });
            });

            if (this.container) {
                this.linkHandler = (e) => {
                    const a = e.target && e.target.closest ? e.target.closest('a') : null;
                    if (!a) return;
                    if (!this.options || !this.options.__todoSourceWindow) return;

                    const href = a.getAttribute('href');
                    if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
                    if (a.getAttribute('target') === '_blank') return;

                    const sourceWin = this.options.__todoSourceWindow;
                    if (!sourceWin || sourceWin.closed) return;

                    let url = href;
                    try {
                        if (sourceWin.TodoRequest && typeof sourceWin.TodoRequest.normalizeUrl === 'function') {
                            url = sourceWin.TodoRequest.normalizeUrl(url, 'iframe');
                        }
                    } catch (err) {}

                    e.preventDefault();
                    e.stopPropagation();
                    this.close();

                    try {
                        sourceWin.location.href = url;
                    } catch (err) {
                        try { window.location.href = url; } catch (e2) {}
                    }
                };

                this.container.addEventListener('click', this.linkHandler, true);
            }
            
            // 背景点击事件
            if (this.options.backdrop) {
                const supportsPointer = typeof window !== 'undefined' && ('PointerEvent' in window);
                this.backdropDownEvent = supportsPointer ? 'pointerdown' : 'mousedown';

                this.backdropDownHandler = (e) => {
                    this._backdropPressed = (e.target === this.element);
                };

                this.backdropClickHandler = (e) => {
                    const shouldClose = this._backdropPressed && (e.target === this.element);
                    this._backdropPressed = false;
                    if (shouldClose) {
                        this.close();
                    }
                };

                this.element.addEventListener(this.backdropDownEvent, this.backdropDownHandler);
                this.element.addEventListener('click', this.backdropClickHandler);
            }
            
            // 阻止容器点击事件冒泡
            if (this.container) {
                this.container.addEventListener('click', (e) => {
                    e.stopPropagation();
                });
            }
            
            // ESC键事件
            if (this.options.keyboard) {
                this.escHandler = (e) => {
                    if (e.key === 'Escape' && this.isOpen) {
                        this.close();
                    }
                };
                getDoc().addEventListener('keydown', this.escHandler);
            }
        }

        applyDimensions() {
            if (!this.container) return;
            if (this.options.width) {
                this.container.style.width = typeof this.options.width === 'number' ? this.options.width + 'px' : this.options.width;
            }
            if (this.options.maxWidth) {
                this.container.style.maxWidth = typeof this.options.maxWidth === 'number' ? this.options.maxWidth + 'px' : this.options.maxWidth;
            }
        }
        
        setTitle(title) {
            const titleElement = this.element.querySelector('.todo-modal-title');
            if (titleElement) {
                titleElement.textContent = title;
            }
            return this;
        }
        
        setContent(content) {
            const bodyElement = this.element.querySelector('.todo-modal-body');
            if (!bodyElement) return this;
            
            if (typeof content === 'string') {
                bodyElement.innerHTML = content;
            } else if (content && typeof content === 'object' && typeof content.nodeType === 'number') {
                bodyElement.innerHTML = '';
                bodyElement.appendChild(content);
            } else if (content && typeof content === 'object' && typeof content.length === 'number') {
                bodyElement.innerHTML = '';
                Array.from(content).forEach((node) => {
                    if (node && typeof node.nodeType === 'number') {
                        bodyElement.appendChild(node);
                    }
                });
            } else if (content instanceof NodeList || Array.isArray(content)) {
                bodyElement.innerHTML = '';
                Array.from(content).forEach((node) => {
                    bodyElement.appendChild(node);
                });
            }
            return this;
        }
        
        setFooter(buttons) {
            const footerElement = this.element.querySelector('.todo-modal-footer');
            if (!footerElement) return this;
            
            footerElement.innerHTML = '';
            
            if (!buttons || buttons.length === 0) {
                footerElement.style.display = 'none';
                return this;
            }
            
            footerElement.removeAttribute('style');
            
            buttons.forEach(button => {
                const doc = getDoc();
                const btn = doc.createElement('button');
                btn.type = button.type || 'button';
                btn.className = button.class || button.className || 'button';
                btn.textContent = button.text || button.label || '';
                
                // 支持 id 属性
                if (button.id) {
                    btn.id = button.id;
                }
                
                // 支持 style 属性（可以是字符串或对象）
                if (button.style) {
                    if (typeof button.style === 'string') {
                        btn.style.cssText = button.style;
                    } else if (typeof button.style === 'object') {
                        Object.assign(btn.style, button.style);
                    }
                }
                
                if (button.icon) {
                    const doc = getDoc();
                    const icon = doc.createElement('span');
                    icon.className = button.icon;
                    icon.style.marginRight = '5px';
                    btn.insertBefore(icon, btn.firstChild);
                }
                
                if (button.disabled) {
                    btn.disabled = true;
                }
                
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (button.handler) {
                        // ── 防重复点击：handler 执行期间禁用按钮 ──
                        if (btn._todoBtnBusy) return;
                        btn._todoBtnBusy = true;

                        const origHTML     = btn.innerHTML;
                        const origDisabled = btn.disabled;
                        const origClass    = btn.className;
                        const loadingText  = btn.getAttribute('data-loading-text') || null;

                        // 主要确认/提交类按钮才展示 spinner
                        const isPrimary = btn.classList.contains('button-primary') ||
                                          btn.classList.contains('btn-primary') ||
                                          btn.classList.contains('btn-danger') ||
                                          btn.classList.contains('button-danger');

                        if (isPrimary) {
                            btn.disabled = true;
                            btn.classList.add('todo-btn-loading');
                            btn.setAttribute('aria-busy', 'true');
                            if (loadingText) {
                                btn.innerHTML =
                                    '<span class="todo-btn-spinner" aria-hidden="true"></span>' +
                                    '<span class="todo-btn-label">' + loadingText + '</span>';
                            } else {
                                btn.innerHTML =
                                    '<span class="todo-btn-spinner" aria-hidden="true"></span>' +
                                    '<span class="todo-btn-label">' + (btn.textContent || '处理中...') + '</span>';
                            }
                        } else {
                            btn.disabled = true;
                        }

                        const restoreBtn = () => {
                            btn._todoBtnBusy = false;
                            btn.disabled     = origDisabled;
                            btn.innerHTML    = origHTML;
                            btn.className    = origClass;
                            btn.removeAttribute('aria-busy');
                            btn.classList.remove('todo-btn-loading');
                        };

                        let result;
                        try {
                            result = button.handler.call(this, e);
                        } catch (handlerErr) {
                            console.error('[TodoModal] Button handler threw:', handlerErr);
                            restoreBtn();
                            return;
                        }

                        // 支持 handler 返回 Promise（异步操作）
                        if (result && typeof result.then === 'function') {
                            result.then((val) => {
                                restoreBtn();
                                if (val !== false && button.close !== false) {
                                    this.close();
                                }
                            }).catch((err) => {
                                console.error('[TodoModal] Async handler error:', err);
                                restoreBtn();
                            });
                            return;
                        }

                        // 同步 handler
                        restoreBtn();
                        // 如果返回 false，不关闭弹窗
                        if (result === false) {
                            return;
                        }
                    }
                    // 默认行为：点击按钮后关闭弹窗（除非 handler 返回 false）
                    if (button.close !== false) {
                        this.close();
                    }
                });
                
                footerElement.appendChild(btn);
            });
            
            return this;
        }

        setFooterContent(content) {
            const footerElement = this.element.querySelector('.todo-modal-footer');
            if (!footerElement) return this;

            footerElement.innerHTML = '';

            if (!content || (Array.isArray(content) && content.length === 0)) {
                footerElement.style.display = 'none';
                return this;
            }

            footerElement.removeAttribute('style');

            if (typeof content === 'string') {
                footerElement.innerHTML = content;
            } else if (content && typeof content === 'object' && typeof content.nodeType === 'number') {
                footerElement.appendChild(content);
            } else if (content && typeof content === 'object' && typeof content.length === 'number') {
                Array.from(content).forEach((node) => {
                    if (!node) return;
                    if (typeof node === 'string') {
                        footerElement.insertAdjacentHTML('beforeend', node);
                        return;
                    }
                    if (typeof node.nodeType === 'number') {
                        footerElement.appendChild(node);
                    }
                });
            } else if (content instanceof NodeList || Array.isArray(content)) {
                Array.from(content).forEach(node => {
                    if (!node) return;
                    footerElement.appendChild(node);
                });
            }

            return this;
        }
        
        showLoading(text = '加载中...') {
            const loadingOverlay = this.element.querySelector('.todo-modal-loading-overlay');
            const loadingText = loadingOverlay ? loadingOverlay.querySelector('.todo-modal-loading-text') : null;
            
            if (loadingOverlay) {
                loadingOverlay.style.display = 'flex';
                if (loadingText) {
                    loadingText.textContent = text;
                }
            }
            return this;
        }
        
        hideLoading() {
            const loadingOverlay = this.element.querySelector('.todo-modal-loading-overlay');
            if (loadingOverlay) {
                loadingOverlay.style.display = 'none';
            }
            return this;
        }
        
        open() {
            if (this.isOpen) return this;
            
            this.isOpen = true;
            this.element.classList.add('show');
            const docBody = getDoc().body;
            docBody.style.overflow = 'hidden';
            docBody.classList.add('show-modal');
            // 使用引用计数替代布尔标志，支持多弹窗叠加场景正确处理
            const current = parseInt(docBody.getAttribute('data-overlay') || '0', 10);
            docBody.setAttribute('data-overlay', String(current + 1));
            
            // 触发打开事件
            this.triggerEvent('open');

            if (typeof this.options.onOpen === 'function') {
                this.options.onOpen(this);
            }
            
            return this;
        }
        
        close() {
            if (!this.isOpen) return this;
            
            this.isOpen = false;
            this.element.classList.remove('show');
            
            // 延迟满足动画帧后再登记计数减少，确保弹窗退出动画完成后才恢复滚动居中层入口
            // 立即恢复滚动（采用引用计数，避免多弹窗叠加时过早恢复）
            TodoModal.decrementOverlay();
            
            // 触发关闭事件
            this.triggerEvent('close');

            if (typeof this.options.onClose === 'function') {
                this.options.onClose(this);
            }
            
            if (this.onCancel) {
                this.onCancel();
            }
            
            if (this.onClose) {
                this.onClose();
            }
            
            // 如果设置了关闭时销毁，则销毁弹窗
            if (this.options.destroyOnClose) {
                setTimeout(() => {
                    if (!this.isOpen) {
                        this.destroy();
                    }
                }, 300);
            }
            
            return this;
        }
        
        destroy() {
            // 移除 ESC 事件监听
            if (this.escHandler) {
                getDoc().removeEventListener('keydown', this.escHandler);
                this.escHandler = null;
            }

            if (this.container && this.linkHandler) {
                this.container.removeEventListener('click', this.linkHandler, true);
                this.linkHandler = null;
            }

            if (this.element) {
                if (this.backdropDownHandler && this.backdropDownEvent) {
                    this.element.removeEventListener(this.backdropDownEvent, this.backdropDownHandler);
                }
                if (this.backdropClickHandler) {
                    this.element.removeEventListener('click', this.backdropClickHandler);
                }
            }
            this.backdropDownHandler = null;
            this.backdropClickHandler = null;
            this.backdropDownEvent = null;
            this._backdropPressed = false;
            
            // 移除元素
            if (this.element && this.element.parentNode) {
                this.element.parentNode.removeChild(this.element);
            }
            
            // 弹窗销毁时确保引用计数展示（如果 destroy 在 close 之前直接调用）
            // 注意：如果是由 close() 触发的，decrementOverlay 已在 close() 里执行过一次，
            // destroy 关心的是 元素移除，不再重复操作计数
            // （只有外部直接调用 destroy 而未经过 close 时，才需减计数）
            if (this.isOpen) {
                // destroy 直接调用时（跳过了 close）才进行计数操作
                TodoModal.decrementOverlay();
            }
            
            // 清理引用
            this.element = null;
            this.container = null;
            this.isOpen = false;

            if (TodoModal._instances) {
                TodoModal._instances.delete(this);
            }
        }
        
        triggerEvent(eventName, detail = {}) {
            const event = new CustomEvent(`todo-modal-${eventName}`, {
                detail: { modal: this, ...detail },
                bubbles: true,
                cancelable: true
            });
            if (this.element) {
                this.element.dispatchEvent(event);
            }
        }
        
        onConfirmCallback(callback) {
            this.onConfirm = callback;
            return this;
        }
        
        onCancelCallback(callback) {
            this.onCancel = callback;
            return this;
        }
        
        onCloseCallback(callback) {
            this.onClose = callback;
            return this;
        }
        
        // 更新选项
        updateOptions(options) {
            this.options = { ...this.options, ...options };
            return this;
        }
    }

    TodoModal.closeAll = function(options = {}) {
        const doc = getDoc();
        const instances = TodoModal._instances ? Array.from(TodoModal._instances) : [];

        instances.forEach((modal) => {
            if (!modal) return;
            if (options.destroy) {
                modal.destroy();
            } else {
                modal.close();
            }
        });

        if (options.destroy) {
            const overlays = doc.querySelectorAll('.todo-modal-overlay');
            overlays.forEach((el) => el.remove());
            TodoModal.restoreScroll();
        }
    };

    /**
     * 静态方法：通用显示弹窗
     */
    TodoModal.show = function(options = {}) {
        const modal = new TodoModal({
            size: options.size || 'md',
            ...options
        });
        
        if (options.title) modal.setTitle(options.title);
        if (options.content) modal.setContent(options.content);
        
        if (options.footer === undefined && options.footerContent === undefined) {
            const confirmText = options.confirmText || options.confirm || '确定';
            const cancelText = options.cancelText || options.cancel || '取消';
            const buttons = [];
            
            if (options.showCancel !== false) {
                buttons.push({
                    text: cancelText,
                    class: 'button button-secondary',
                    handler: function() {
                        if (options.onCancel) options.onCancel.call(this, modal.element);
                        return true;
                    }
                });
            }
            
            buttons.push({
                text: confirmText,
                class: 'button button-primary',
                handler: function() {
                    if (options.onConfirm) {
                        const result = options.onConfirm.call(this, modal.element);
                        return result !== false;
                    }
                    return true;
                }
            });
            
            modal.setFooter(buttons);
        }
        
        return modal.open();
    };

    /**
     * 静态方法：确认对话框
     */
    TodoModal.confirm = function(options = {}) {
        const modal = new TodoModal({
            size: options.size || 'sm',
            type: options.type || 'default',
            ...options
        });
        
        const title = options.title || '确认操作';
        const message = options.message || '确定要执行此操作吗？';
        const confirmText = options.confirmText || options.confirm || '确认';
        const cancelText = options.cancelText || options.cancel || '取消';
        
        modal.setTitle(title)
             .setContent(`<div class="todo-modal-message">${message}</div>`)
             .setFooter([
                 {
                     text: cancelText,
                     class: 'button button-secondary',
                     handler: () => {
                         if (options.onCancel) {
                             options.onCancel();
                         }
                         return true;
                     }
                 },
                 {
                     text: confirmText,
                     class: 'button button-primary',
                     handler: () => {
                         if (options.onConfirm) {
                             const result = options.onConfirm();
                             // 如果返回 false，不关闭弹窗
                             return result !== false;
                         }
                         return true;
                     }
                 }
             ])
             .open();
        
        return modal;
    };

    /**
     * 静态方法：删除确认对话框
     */
    TodoModal.deleteConfirm = function(options = {}) {
        let content = ``;
        
        if (options.message) {
            content += `<p class="m-b-10">${options.message}</p>`;
        }

        if (options.itemInfo) {
            content += '<dl class="todo-modal-item-info">';
            Object.keys(options.itemInfo).forEach(key => {
                content += `<dt>${key}：</dt><dd>${options.itemInfo[key]}</dd>`;
            });
            content += '</dl>';
        }

        return TodoModal.confirm({
            ...options,
            title: options.title || '确认删除',
            message: content,
            confirmText: options.confirmText || '确认删除',
            cancelText: options.cancelText || '取消',
            type: 'danger'
        });
    };

    /**
     * 静态方法：信息提示框
     */
    TodoModal.alert = function(options = {}) {
        const type = options.type || 'info'; // success, error, warning, info
        const typeMap = {
            success: { icon: '✓', title: '成功' },
            error: { icon: '✕', title: '错误' },
            warning: { icon: '⚠', title: '警告' },
            info: { icon: 'ℹ', title: '提示' }
        };
        
        const typeInfo = typeMap[type] || typeMap.info;
        
        const modal = new TodoModal({
            size: options.size || 'sm',
            type: type,
            ...options
        });
        
        const title = options.title || typeInfo.title;
        const message = options.message || '';
        const buttonText = options.buttonText || options.button || '确定';
        
        let content = `<div class="todo-modal-alert-content todo-modal-alert-${type}">`;
        content += `<div class="todo-modal-alert-icon">${typeInfo.icon}</div>`;
        content += `<div class="todo-modal-alert-message">${message}</div>`;
        content += '</div>';
        
        modal.setTitle(title)
             .setContent(content)
             .setFooter([
                 {
                     text: buttonText,
                     class: 'button button-primary',
                     handler: () => {
                         if (options.onConfirm) {
                             options.onConfirm();
                         }
                         return true;
                     }
                 }
             ])
             .open();
        
        return modal;
    };

    /**
     * 静态方法：成功提示
     */
    TodoModal.success = function(message, options = {}) {
        return TodoModal.alert({
            type: 'success',
            message: message,
            ...options
        });
    };

    /**
     * 静态方法：错误提示
     */
    TodoModal.error = function(message, options = {}) {
        return TodoModal.alert({
            type: 'error',
            message: message,
            ...options
        });
    };

    /**
     * 静态方法：警告提示
     */
    TodoModal.warning = function(message, options = {}) {
        return TodoModal.alert({
            type: 'warning',
            message: message,
            ...options
        });
    };

    /**
     * 静态方法：信息提示
     */
    TodoModal.info = function(message, options = {}) {
        return TodoModal.alert({
            type: 'info',
            message: message,
            ...options
        });
    };

    /**
     * 静态方法：显示加载遮罩（全局）
     */
    TodoModal.showLoading = function(text = '加载中...', options = {}) {
        const doc = getDoc();
        // 如果已存在全局加载遮罩，先移除
        const existing = doc.getElementById('todo-global-loading-overlay');
        if (existing) {
            existing.remove();
        }
        
        const overlay = doc.createElement('div');
        overlay.id = 'todo-global-loading-overlay';
        overlay.className = 'todo-global-loading-overlay';
        overlay.style.zIndex = options.zIndex || 10000;
        overlay.innerHTML = `
            <div class="todo-global-loading-content">
                <div class="todo-modal-spinner"></div>
                <div class="todo-global-loading-text">${text}</div>
            </div>
        `;
        
        doc.body.appendChild(overlay);
        doc.body.style.overflow = 'hidden';
        doc.body.classList.add('show-modal');
        // 使用引用计数，与普通弹窗一致
        const currentCount = parseInt(doc.body.getAttribute('data-overlay') || '0', 10);
        doc.body.setAttribute('data-overlay', String(currentCount + 1));
        
        return {
            hide: function() {
                if (overlay && overlay.parentNode) {
                    overlay.parentNode.removeChild(overlay);
                }
                TodoModal.decrementOverlay();
            },
            update: function(newText) {
                const textEl = overlay.querySelector('.todo-global-loading-text');
                if (textEl) {
                    textEl.textContent = newText;
                }
            }
        };
    };

    /**
     * 静态方法：隐藏加载遮罩（全局）
     */
    TodoModal.hideLoading = function() {
        const doc = getDoc();
        const overlay = doc.getElementById('todo-global-loading-overlay');
        if (overlay) {
            overlay.remove();
        }
        TodoModal.restoreScroll();
    };

    /**
     * 静态方法：引用计数减1，归零时就恢复 body 滚动
     */
    TodoModal.decrementOverlay = function() {
        const doc = getDoc();
        const current = parseInt(doc.body.getAttribute('data-overlay') || '0', 10);
        if (current <= 1) {
            // 已经是最后一个弹窗，彻底恢复
            doc.body.style.overflow = '';
            doc.body.classList.remove('show-modal');
            doc.body.removeAttribute('data-overlay');
        } else {
            doc.body.setAttribute('data-overlay', String(current - 1));
        }
    };

    /**
     * 静态方法：手动强制恢复页面滚动（忽略计数，彻底重置）
     */
    TodoModal.restoreScroll = function() {
        const doc = getDoc();
        doc.body.style.overflow = '';
        doc.body.classList.remove('show-modal');
        doc.body.removeAttribute('data-overlay');
    };

    /**
     * 静态方法：进度弹窗
     */
    TodoModal.progress = function(options = {}) {
        const modal = new TodoModal({
            size: options.size || 'md',
            closable: options.closable !== false,
            backdrop: options.backdrop !== false,
            keyboard: false, // 进度弹窗不允许 ESC 关闭
            destroyOnClose: false,
            ...options
        });
        
        const title = options.title || '处理中';
        
        let content = '<div class="todo-modal-progress-content">';
        content += '<div class="todo-modal-progress-info">';
        content += '<div class="todo-modal-progress-stats">';
        content += '<span class="todo-modal-progress-current">0</span>';
        content += ' / <span class="todo-modal-progress-total">0</span>';
        content += ' <span class="todo-modal-progress-percentage">0%</span>';
        content += '</div>';
        content += '<div class="todo-modal-progress-bar">';
        content += '<div class="todo-modal-progress-fill" style="width: 0%"></div>';
        content += '</div>';
        content += '<div class="todo-modal-progress-status">';
        content += '<span class="todo-modal-progress-status-text">' + (options.statusText || '正在处理，请稍候...') + '</span>';
        content += '</div>';
        content += '</div>';
        
        if (options.showLogs) {
            content += '<div class="todo-modal-progress-logs" style="display: none;">';
            content += '<textarea class="todo-modal-progress-logs-text" readonly rows="5"></textarea>';
            content += '</div>';
        }
        
        content += '</div>';
        
        modal.setTitle(title)
             .setContent(content)
             .setFooter([
                 {
                     text: options.closeText || '关闭',
                     class: 'button button-secondary',
                     disabled: true,
                     handler: () => {
                         if (options.onClose) {
                             options.onClose();
                         }
                         return true;
                     }
                 }
             ])
             .open();
        
        // 添加更新进度的方法
        modal.updateProgress = function(current, total, statusText, logText) {
            const currentEl = this.element.querySelector('.todo-modal-progress-current');
            const totalEl = this.element.querySelector('.todo-modal-progress-total');
            const percentageEl = this.element.querySelector('.todo-modal-progress-percentage');
            const fillEl = this.element.querySelector('.todo-modal-progress-fill');
            const statusTextEl = this.element.querySelector('.todo-modal-progress-status-text');
            const logsEl = this.element.querySelector('.todo-modal-progress-logs');
            const logsTextEl = this.element.querySelector('.todo-modal-progress-logs-text');
            const closeBtn = this.element.querySelector('.todo-modal-footer .button');
            
            if (currentEl) currentEl.textContent = current || 0;
            if (totalEl) totalEl.textContent = total || 0;
            
            let percentage = total > 0 ? Math.round((current / total) * 100) : 0;
            percentage = Math.max(0, Math.min(100, percentage));

            // 获取或初始化平滑动画控制对象
            if (!this._progressAnimState) {
                this._progressAnimState = { current: 0, target: 0, animFrame: null };
            }

            const animState = this._progressAnimState;
            animState.target = percentage;

            const animateStep = () => {
                if (animState.current < animState.target) {
                    const diff = animState.target - animState.current;
                    animState.current += Math.max(0.5, diff * 0.2);
                    if (animState.current > animState.target) {
                        animState.current = animState.target;
                    }
                } else if (animState.current > animState.target) {
                    animState.current = animState.target;
                }

                const displayPercentage = Math.round(animState.current);
                if (percentageEl) percentageEl.textContent = displayPercentage + '%';
                if (fillEl) fillEl.style.width = displayPercentage + '%';

                if (animState.current < animState.target) {
                    animState.animFrame = requestAnimationFrame(animateStep);
                } else {
                    animState.animFrame = null;
                }
            };

            if (!animState.animFrame) {
                animState.animFrame = requestAnimationFrame(animateStep);
            }
            
            if (statusText && statusTextEl) {
                statusTextEl.textContent = statusText;
            }
            
            if (logText && logsTextEl) {
                if (logsEl) logsEl.style.display = 'block';
                const currentLogs = logsTextEl.value;
                logsTextEl.value = (currentLogs ? currentLogs + '\n' : '') + logText;
                logsTextEl.scrollTop = logsTextEl.scrollHeight;
            }
            
            // 如果完成，启用关闭按钮
            if (current >= total && total > 0 && closeBtn) {
                closeBtn.disabled = false;
            }
        };
        
        // 完成进度
        modal.complete = function(success, message) {
            const fillEl = this.element.querySelector('.todo-modal-progress-fill');
            const statusTextEl = this.element.querySelector('.todo-modal-progress-status-text');
            const closeBtn = this.element.querySelector('.todo-modal-footer .button');
            
            if (fillEl) {
                fillEl.style.width = '100%';
                fillEl.classList.add(success ? 'success' : 'error');
            }
            
            if (statusTextEl && message) {
                statusTextEl.textContent = message;
            }
            
            if (closeBtn) {
                closeBtn.disabled = false;
            }
            
            if (options.onComplete) {
                options.onComplete(success, message);
            }
        };
        
        return modal;
    };

    /**
     * 静态方法：Toast 消息提示
     */
    TodoModal.toast = function(message, type = 'info', options = {}) {
        const typeMap = {
            success: { icon: '✔', class: 'success' },
            error: { icon: '✘', class: 'error' },
            warning: { icon: '⚠', class: 'warning' },
            info: { icon: 'ℹ', class: 'info' }
        };
        
        const typeInfo = typeMap[type] || typeMap.info;
        const duration = options.duration !== undefined ? options.duration : 3000;
        const position = options.position || 'top-right'; // top-right, top-left, bottom-right, bottom-left, top-center, bottom-center
        
        const doc = getDoc();
        const toast = doc.createElement('div');
        toast.className = `todo-toast todo-toast-${typeInfo.class} todo-toast-${position}`;
        toast.innerHTML = `
            <div class="todo-toast-icon">${typeInfo.icon}</div>
            <div class="todo-toast-message">${message}</div>
            <button type="button" class="todo-toast-close">✕</button>
        `;
        
        // 添加到容器
        let container = doc.getElementById('todo-toast-container');
        if (!container) {
            container = doc.createElement('div');
            container.id = 'todo-toast-container';
            container.className = `todo-toast-container todo-toast-${position}`;
            doc.body.appendChild(container);
        } else {
            container.className = `todo-toast-container todo-toast-${position}`;
        }
        
        container.appendChild(toast);
        
        // 触发显示动画
        setTimeout(() => {
            toast.classList.add('show');
        }, 10);
        
        // 关闭按钮
        const closeBtn = toast.querySelector('.todo-toast-close');
        const closeToast = () => {
            toast.classList.remove('show');
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        };
        
        if (closeBtn) {
            closeBtn.addEventListener('click', closeToast);
        }
        
        // 自动关闭
        if (duration > 0) {
            setTimeout(closeToast, duration);
        }
        
        return {
            close: closeToast
        };
    };

    /**
     * 静态方法：表单弹窗
     */
    TodoModal.form = function(options = {}) {
        const modal = new TodoModal({
            size: options.size || 'md',
            ...options
        });
        
        const title = options.title || '表单';
        const submitText = options.submitText || '提交';
        const cancelText = options.cancelText || '取消';
        
        modal.setTitle(title);
        
        // 只有在明确提供了 formContent 时才设置内容，否则可能覆盖 templateId 加载的内容
        if (options.formContent) {
            modal.setContent(options.formContent);
        }
        
        modal.setFooter([
            {
                text: cancelText,
                class: 'button button-secondary',
                handler: () => {
                    if (options.onCancel) {
                        options.onCancel();
                    }
                    return true;
                }
            },
                 {
                     text: submitText,
                     class: 'button button-primary',
                     handler: (e) => {
                         const form = modal.element.querySelector('form');
                         if (form) {
                             // 触发表单提交事件
                             const submitEvent = new Event('submit', { bubbles: true, cancelable: true });
                             const result = form.dispatchEvent(submitEvent);
                             
                             if (!result) {
                                 return false; // 阻止默认提交
                             }
                             
                             if (options.onSubmit) {
                                 const formData = new FormData(form);
                                 const result = options.onSubmit(formData, form);
                                 if (result === false) {
                                     return false; // 阻止关闭
                                 }
                             }
                         } else if (options.onSubmit) {
                             const result = options.onSubmit(null, null);
                             if (result === false) {
                                 return false;
                             }
                         }
                         return true;
                     }
                 }
             ])
             .open();
        
        return modal;
    };

    // 全局暴露
    if (typeof window !== 'undefined') {
        window.TodoModal = TodoModal;
    }
    
    // 兼容旧版本 API
    if (typeof window !== 'undefined' && !window.TodoModal) {
        window.TodoModal = TodoModal;
    }

    // --- 入口兴趣处：beforeunload 兑底清理 ---
    // 当页面即将卸载时（如表单提交后重定向、关闭标签页），
    // 如果此时有弹窗正运行（data-overlay 存在），强制清除滚动锁定。
    // 这样即便浏览器在跳转前没来得及执行 close() 里的 decrementOverlay，
    // 新页面加载后也不会继承到 body 的 overflow:hidden 状态。
    // （注：正常重定向后 body 就是全新的，本来不会继承。但处理 pushState SPA 场景或
    //  同页面用 fetch 提交后局部渲染的情况，这个兑底非常必要。)
    if (typeof window !== 'undefined') {
        window.addEventListener('beforeunload', function() {
            try {
                const doc = getDoc();
                if (doc.body.hasAttribute('data-overlay')) {
                    doc.body.style.overflow = '';
                    doc.body.classList.remove('show-modal');
                    doc.body.removeAttribute('data-overlay');
                }
            } catch (e) {}
        });
    }

    /**
     * 跨框架支持包装器
     * 自动在iframe中调用父窗口的TodoModal，确保弹窗显示在父页面
     */
    (function() {
        // 如果当前在iframe中，且父窗口有TodoModal，则创建代理
        if (window.parent && window.parent !== window) {
            try {
                // 检查父窗口是否有TodoModal
                if (typeof window.parent.TodoModal !== 'undefined') {
                    // 保留原始的TodoModal引用
                    var OriginalTodoModal = TodoModal;

                    var resolveTemplateToContent = function(options) {
                        if (!options || !options.templateId) return options;
                        var tmpl = document.getElementById(options.templateId);
                        if (!tmpl || !tmpl.content) return options;

                        var boolAttr = function(value, defaultValue) {
                            if (value === undefined || value === null || value === '') return defaultValue;
                            var normalized = String(value).toLowerCase();
                            if (normalized === '0' || normalized === 'false' || normalized === 'no') return false;
                            if (normalized === '1' || normalized === 'true' || normalized === 'yes') return true;
                            return defaultValue;
                        };

                        var size = tmpl.getAttribute('data-size');
                        var type = tmpl.getAttribute('data-type');
                        var zIndex = tmpl.getAttribute('data-z-index');
                        var width = tmpl.getAttribute('data-width');
                        var maxWidth = tmpl.getAttribute('data-max-width');

                        if (size && options.size === undefined) options.size = size;
                        if (type && options.type === undefined) options.type = type;
                        if (zIndex && options.zIndex === undefined) options.zIndex = Number(zIndex) || 9999;
                        if (width && options.width === undefined) options.width = width;
                        if (maxWidth && options.maxWidth === undefined) options.maxWidth = maxWidth;

                        if (options.closable === undefined) options.closable = boolAttr(tmpl.getAttribute('data-closable'), true);
                        if (options.backdrop === undefined) options.backdrop = boolAttr(tmpl.getAttribute('data-backdrop'), true);
                        if (options.keyboard === undefined) options.keyboard = boolAttr(tmpl.getAttribute('data-keyboard'), true);
                        if (options.animation === undefined) options.animation = boolAttr(tmpl.getAttribute('data-animation'), true);
                        if (options.destroyOnClose === undefined) options.destroyOnClose = boolAttr(tmpl.getAttribute('data-destroy-on-close'), true);

                        var dataTitle = tmpl.getAttribute('data-title');
                        if (dataTitle && options.title === undefined) options.title = dataTitle;

                        var fragment = tmpl.content.cloneNode(true);
                        var pickSlot = function(slotName) {
                            return fragment.querySelector('[data-slot="' + slotName + '"],[data-todo-slot="' + slotName + '"],[data-modal-slot="' + slotName + '"]');
                        };

                        var titleSlot = pickSlot('title');
                        if (titleSlot && options.title === undefined) {
                            var t = (titleSlot.textContent || '').trim();
                            if (t) options.title = t;
                        }
                        if (titleSlot && titleSlot.parentNode) titleSlot.parentNode.removeChild(titleSlot);

                        var footerSlot = pickSlot('footer');
                        if (footerSlot && options.footer === undefined && options.footerContent === undefined) {
                            options.footerContent = footerSlot.innerHTML;
                            if (footerSlot.parentNode) footerSlot.parentNode.removeChild(footerSlot);
                        }

                        var bodySlot = pickSlot('body');
                        if (bodySlot) {
                            options.content = bodySlot.innerHTML;
                        } else {
                            var container = document.createElement('div');
                            container.appendChild(fragment);
                            options.content = container.innerHTML;
                        }

                        delete options.templateId;
                        return options;
                    };
                    
                    // 创建一个代理构造函数，支持 new 操作
                    var CrossFrameTodoModal = function(options) {
                        options = resolveTemplateToContent(options || {});
                        if (!options.__todoSourceWindow) options.__todoSourceWindow = window;
                        // 调用父窗口的TodoModal构造函数
                        return new window.parent.TodoModal(options);
                    };
                    
                    // 需要代理的静态方法列表
                    var methodsToProxy = [
                        'show', 'confirm', 'deleteConfirm', 'alert', 
                        'success', 'error', 'warning', 'info',
                        'showLoading', 'hideLoading', 'restoreScroll', 'decrementOverlay',
                        'progress', 'toast', 'form', 'closeAll'
                    ];
                    
                    // 为每个静态方法创建代理
                    methodsToProxy.forEach(function(method) {
                        CrossFrameTodoModal[method] = function() {
                            // 预处理参数，解析 templateId
                            var args = Array.from(arguments);
                            
                            // 查找参数中的 options 对象 (通常是最后一个参数，或者特定的参数位置)
                            // 简单起见，遍历所有参数，如果是对象且有 templateId，则处理
                            args.forEach(function(arg) {
                                if (arg && typeof arg === 'object' && arg.templateId) {
                                    resolveTemplateToContent(arg);
                                }
                                if (arg && typeof arg === 'object' && !arg.__todoSourceWindow) {
                                    arg.__todoSourceWindow = window;
                                }
                            });
                            
                            return window.parent.TodoModal[method].apply(window.parent.TodoModal, args);
                        };
                    });
                    
                    // 保留原始的TodoModal引用
                    CrossFrameTodoModal._original = OriginalTodoModal;
                    CrossFrameTodoModal._isProxy = true;
                    
                    // 替换当前窗口的TodoModal
                    window.TodoModal = CrossFrameTodoModal;
                    
                }
            } catch (e) {
                // 跨域限制或其他错误，保持当前的TodoModal不变
                console.warn('[TodoModal] 无法访问父窗口的TodoModal:', e.message);
            }
        }
    })();

})();
