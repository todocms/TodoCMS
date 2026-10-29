/**
 * 特色图片组件JavaScript
 * TodoCMS Featured Images Component
 * @version 1.0.0
 */

(function($) {
    'use strict';
    
    // 特色图片组件类
    window.FeaturedImagesComponent = function(config) {
        this.config = $.extend({
            fieldId: 'featured_images',
            fieldName: 'featured_images',
            multiple: true,
            maxImages: 6,
            enableDebug: false,
            fileType: 'any'
        }, config);
        
        this.container = null;
        this.previewArea = null;
        this.addButton = null;
        this.currentImages = [];
        
        this.init();
    };
    
    // 原型方法
    FeaturedImagesComponent.prototype = {

        getBaseUrl: function() {
            if (window.TODO && typeof window.TODO.SITE_URL === 'string' && window.TODO.SITE_URL) {
                return window.TODO.SITE_URL.replace(/\/+$/, '');
            }
            return (window.location && window.location.origin) ? window.location.origin : '';
        },

        showMessage: function(message, type) {
            if (window.TodoCMS && window.TodoCMS.Utils && typeof window.TodoCMS.Utils.showMessage === 'function') {
                window.TodoCMS.Utils.showMessage(message, type || 'info');
            } else if (typeof TodoModal !== 'undefined' && typeof TodoModal.toast === 'function') {
                TodoModal.toast(message, type || 'info');
            }
        },

        isAvatarSquareCropEnabled: function() {
            return this.config && this.config.fieldId === 'avatar';
        },

        ensureAvatarCropStyles: function() {
            if (document.getElementById('todo-avatar-crop-styles')) return;
            var style = document.createElement('style');
            style.id = 'todo-avatar-crop-styles';
            style.textContent =
                '.todo-avatar-crop-overlay { position: fixed; top: 100px; left: 50%; transform: translateX(-50%); display: flex; align-items: center; justify-content: center; }' +
                '.todo-avatar-crop-modal{width:min(720px,94vw);background:#fff;border-radius:3px;box-shadow:0 5px 15px rgba(0, 0, 0, 0.3);overflow:hidden;}' +
                '.todo-avatar-crop-header{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #eee;}' +
                '.todo-avatar-crop-title{font-size:14px;font-weight:500;margin:0;}' +
                '.todo-avatar-crop-body{display:flex;gap:16px;padding:16px;flex-wrap:wrap;}' +
                '.todo-avatar-crop-stage{flex:1;min-width:320px;display:flex;align-items:center;justify-content:center;}' +
                '.todo-avatar-crop-canvas{width:360px;height:360px;max-width:86vw;max-height:86vw;border-radius:10px;background:#f3f4f6;touch-action:none;}' +
                '.todo-avatar-crop-controls{width:260px;min-width:220px;display:flex;flex-direction:column;gap:10px;}' +
                '.todo-avatar-crop-controls label{font-size:12px;color:#444;}' +
                '.todo-avatar-crop-footer{display:flex;justify-content:flex-end;gap:10px;padding:14px 16px;}' +
                '.todo-avatar-crop-btn{height:32px;line-height:30px;padding:0 12px;border:1px solid #c3c4c7;background:#f6f7f7;border-radius:3px;cursor:pointer;}' +
                '.todo-avatar-crop-btn.primary{background:#2271b1;border-color:#2271b1;color:#fff;}';
            document.head.appendChild(style);
        },

        fetchImageAsBlobUrl: function(url) {
            var self = this;
            var abs = self.toAbsoluteUrl(self.toRelativeUrl(url) || url);
            return fetch(abs, { credentials: 'include' })
                .then(function(res) {
                    if (!res.ok) throw new Error('fetch_failed');
                    return res.blob();
                })
                .then(function(blob) {
                    return URL.createObjectURL(blob);
                });
        },

        cropSquareAndUpload: function(media) {
            var self = this;
            self.ensureAvatarCropStyles();

            var srcUrl = media && (media.url || media.thumbnail) ? (media.url || media.thumbnail) : '';
            if (!srcUrl) {
                return Promise.reject(new Error('no_source'));
            }

            return self.fetchImageAsBlobUrl(srcUrl).then(function(blobUrl) {
                return new Promise(function(resolve, reject) {
                    var img = new Image();
                    img.onload = function() {
                        try {
                            var resultPromise = self.openSquareCropModal(img);
                            resultPromise.then(function(blob) {
                                resolve(blob);
                            }).catch(reject);
                        } catch (e) {
                            reject(e);
                        } finally {
                            try { URL.revokeObjectURL(blobUrl); } catch (e) {}
                        }
                    };
                    img.onerror = function() {
                        try { URL.revokeObjectURL(blobUrl); } catch (e) {}
                        reject(new Error('image_load_failed'));
                    };
                    img.src = blobUrl;
                });
            }).then(function(croppedBlob) {
                return self.uploadCroppedImageBlob(croppedBlob);
            });
        },

        openSquareCropModal: function(imgEl) {
            var self = this;
            var viewport = 360;
            var canvas = document.createElement('canvas');
            canvas.width = viewport;
            canvas.height = viewport;
            canvas.className = 'todo-avatar-crop-canvas';
            var ctx = canvas.getContext('2d');

            var overlay = document.createElement('div');
            overlay.className = 'todo-avatar-crop-overlay';
            var modal = document.createElement('div');
            modal.className = 'todo-avatar-crop-modal';

            var header = document.createElement('div');
            header.className = 'todo-avatar-crop-header';
            var title = document.createElement('div');
            title.className = 'todo-avatar-crop-title';
            title.textContent = '头像裁剪';
            header.appendChild(title);
            modal.appendChild(header);

            var body = document.createElement('div');
            body.className = 'todo-avatar-crop-body';
            var stage = document.createElement('div');
            stage.className = 'todo-avatar-crop-stage';
            stage.appendChild(canvas);
            body.appendChild(stage);

            var controls = document.createElement('div');
            controls.className = 'todo-avatar-crop-controls';
            var zoomLabel = document.createElement('label');
            zoomLabel.textContent = '缩放';
            var zoom = document.createElement('input');
            zoom.type = 'range';
            zoom.min = '1';
            zoom.max = '3';
            zoom.step = '0.01';
            zoom.value = '1';
            controls.appendChild(zoomLabel);
            controls.appendChild(zoom);

            var hint = document.createElement('div');
            hint.style.fontSize = '12px';
            hint.style.color = '#666';
            hint.textContent = '拖拽图片调整位置';
            controls.appendChild(hint);

            body.appendChild(controls);
            modal.appendChild(body);

            var footer = document.createElement('div');
            footer.className = 'todo-avatar-crop-footer';
            var cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'todo-avatar-crop-btn';
            cancelBtn.textContent = '取消';
            var okBtn = document.createElement('button');
            okBtn.type = 'button';
            okBtn.className = 'todo-avatar-crop-btn primary';
            okBtn.textContent = '确认裁剪';
            footer.appendChild(cancelBtn);
            footer.appendChild(okBtn);
            modal.appendChild(footer);

            overlay.appendChild(modal);
            document.body.appendChild(overlay);

            var imgW = imgEl.naturalWidth || imgEl.width;
            var imgH = imgEl.naturalHeight || imgEl.height;
            var baseScale = Math.max(viewport / imgW, viewport / imgH);
            var scale = baseScale;
            var dx = (viewport - imgW * scale) / 2;
            var dy = (viewport - imgH * scale) / 2;

            function render() {
                ctx.clearRect(0, 0, viewport, viewport);
                ctx.fillStyle = '#f3f4f6';
                ctx.fillRect(0, 0, viewport, viewport);
                ctx.imageSmoothingEnabled = true;
                ctx.imageSmoothingQuality = 'high';
                ctx.drawImage(imgEl, dx, dy, imgW * scale, imgH * scale);
            }

            function clamp() {
                var w = imgW * scale;
                var h = imgH * scale;
                if (w < viewport) {
                    dx = (viewport - w) / 2;
                } else {
                    dx = Math.min(0, Math.max(viewport - w, dx));
                }
                if (h < viewport) {
                    dy = (viewport - h) / 2;
                } else {
                    dy = Math.min(0, Math.max(viewport - h, dy));
                }
            }

            var dragging = false;
            var lastX = 0;
            var lastY = 0;

            function getPoint(e) {
                if (e.touches && e.touches.length) {
                    return { x: e.touches[0].clientX, y: e.touches[0].clientY };
                }
                return { x: e.clientX, y: e.clientY };
            }

            function onDown(e) {
                e.preventDefault();
                dragging = true;
                var p = getPoint(e);
                lastX = p.x;
                lastY = p.y;
            }
            function onMove(e) {
                if (!dragging) return;
                e.preventDefault();
                var p = getPoint(e);
                var mx = p.x - lastX;
                var my = p.y - lastY;
                lastX = p.x;
                lastY = p.y;
                dx += mx;
                dy += my;
                clamp();
                render();
            }
            function onUp() {
                dragging = false;
            }

            canvas.addEventListener('mousedown', onDown);
            canvas.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
            canvas.addEventListener('touchstart', onDown, { passive: false });
            canvas.addEventListener('touchmove', onMove, { passive: false });
            canvas.addEventListener('touchend', onUp);

            zoom.addEventListener('input', function() {
                var z = parseFloat(zoom.value || '1');
                var centerX = viewport / 2;
                var centerY = viewport / 2;
                var prevScale = scale;
                scale = baseScale * z;
                dx = centerX - ((centerX - dx) * (scale / prevScale));
                dy = centerY - ((centerY - dy) * (scale / prevScale));
                clamp();
                render();
            });

            render();

            function cleanup() {
                try { document.body.removeChild(overlay); } catch (e) {}
                canvas.removeEventListener('mousedown', onDown);
                canvas.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                canvas.removeEventListener('touchstart', onDown);
                canvas.removeEventListener('touchmove', onMove);
                canvas.removeEventListener('touchend', onUp);
            }

            return new Promise(function(resolve, reject) {
                cancelBtn.addEventListener('click', function() {
                    cleanup();
                    reject(new Error('cancelled'));
                });
                overlay.addEventListener('click', function(e) {
                    if (e.target === overlay) {
                        cleanup();
                        reject(new Error('cancelled'));
                    }
                });
                okBtn.addEventListener('click', function() {
                    okBtn.disabled = true;
                    cancelBtn.disabled = true;
                    var out = document.createElement('canvas');
                    out.width = 512;
                    out.height = 512;
                    var octx = out.getContext('2d');
                    octx.imageSmoothingEnabled = true;
                    octx.imageSmoothingQuality = 'high';
                    octx.drawImage(canvas, 0, 0, viewport, viewport, 0, 0, 512, 512);
                    out.toBlob(function(blob) {
                        cleanup();
                        if (!blob) {
                            reject(new Error('blob_failed'));
                            return;
                        }
                        resolve(blob);
                    }, 'image/jpeg', 0.92);
                });
            });
        },

        uploadCroppedImageBlob: function(blob) {
            var self = this;
            return new Promise(function(resolve, reject) {
                var endpoint = (window.TodoMediaAjaxPaths && window.TodoMediaAjaxPaths.mediaUpload) ? window.TodoMediaAjaxPaths.mediaUpload : '/admin/ajax/media-upload.php';
                var token = '';
                if (window.TodoCSRFManager && typeof window.TodoCSRFManager.getToken === 'function') {
                    token = window.TodoCSRFManager.getToken('upload_media') || '';
                }
                if (!token) {
                    reject(new Error('missing_csrf'));
                    return;
                }

                var filename = 'avatar-' + Date.now() + '.jpg';
                var file = new File([blob], filename, { type: 'image/jpeg' });
                var formData = new FormData();
                formData.append('files[]', file, filename);
                formData.append('action', 'upload_media');
                formData.append('_todononce', token);

                var xhr = new XMLHttpRequest();
                xhr.onload = function() {
                    if (xhr.status !== 200) {
                        reject(new Error('upload_failed'));
                        return;
                    }
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        var first = Array.isArray(resp) ? resp[0] : resp;
                        if (!first || first.success !== true) {
                            reject(new Error(first && first.error ? first.error : 'upload_failed'));
                            return;
                        }
                        resolve({
                            id: first.id || '',
                            url: first.url || first.thumbnail || '',
                            thumbnail: first.thumbnail || first.url || '',
                            title: first.name || first.original_name || filename,
                            mime_type: first.type || 'image/jpeg',
                            _todoCropped: true
                        });
                    } catch (e) {
                        reject(new Error('bad_response'));
                    }
                };
                xhr.onerror = function() {
                    reject(new Error('upload_failed'));
                };
                xhr.open('POST', endpoint);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.send(formData);
            });
        },

        getBasePathPrefix: function() {
            try {
                var base = this.getBaseUrl();
                if (!base) return '';
                var u = new URL(base + '/', window.location.origin);
                var p = (u && u.pathname) ? u.pathname : '';
                p = (p || '').replace(/\/+$/, '');
                return p === '/' ? '' : p;
            } catch (e) {
                return '';
            }
        },

        toAbsoluteUrl: function(urlOrPath) {
            if (!urlOrPath) return '';
            var s = ('' + urlOrPath).trim();
            if (!s) return '';
            if (/^https?:\/\//i.test(s) || /^\/\//.test(s) || s.indexOf('data:') === 0) return s;

            var rel = this.toRelativeUrl(s);
            if (!rel) return s;
            var prefix = this.getBasePathPrefix();
            if (prefix) {
                if (rel.indexOf(prefix + '/') !== 0) {
                    return prefix + (rel.charAt(0) === '/' ? rel : ('/' + rel));
                }
            }
            return rel;
        },

        toRelativeUrl: function(url) {
            if (!url) return '';
            var s = ('' + url).trim();
            if (!s) return '';
            if (s.charAt(0) === '{' || s.charAt(0) === '[') return s;
            var prefix = this.getBasePathPrefix();
            if (s.charAt(0) === '/' && !/^\/\//.test(s)) {
                if (prefix && s.indexOf(prefix + '/') === 0) {
                    s = s.substring(prefix.length);
                    if (!s) s = '/';
                }
                var m = s.match(/\/(content\/(?:uploads|assets|themes|plugins)\/.*)$/i);
                if (m) {
                    return '/' + m[1];
                }
                return s.charAt(0) === '/' ? s : '/' + s;
            }
            if (!/^https?:\/\//i.test(s) && !/^\/\//.test(s)) {
                if (s.charAt(0) !== '/') s = '/' + s.replace(/^\/+/, '');
                if (prefix && s.indexOf(prefix + '/') === 0) {
                    s = s.substring(prefix.length);
                    if (!s) s = '/';
                }
                var m2 = s.match(/\/(content\/(?:uploads|assets|themes|plugins)\/.*)$/i);
                if (m2) {
                    return '/' + m2[1];
                }
                return s;
            }
            try {
                var base = this.getBaseUrl() || (window.location ? window.location.origin : '');
                var bu = new URL(base + '/', window.location.origin);
                var u = new URL(s, window.location.origin);
                var bh = (bu.hostname || '').toLowerCase().replace(/^www\./, '');
                var uh = (u.hostname || '').toLowerCase().replace(/^www\./, '');
                if (!bh || !uh || bh !== uh) return s;
                var rel = u.pathname + (u.search || '') + (u.hash || '');
                if (prefix && rel.indexOf(prefix + '/') === 0) {
                    rel = rel.substring(prefix.length);
                    if (!rel) rel = '/';
                }
                var m3 = rel.match(/\/(content\/(?:uploads|assets|themes|plugins)\/.*)$/i);
                if (m3) {
                    return '/' + m3[1];
                }
                if (rel.charAt(0) !== '/') rel = '/' + rel;
                return rel;
            } catch (e) {
                return s;
            }
        },
        
        // 初始化组件
        init: function() {
            // 支持传入容器元素，兼容动态克隆的组件
            if (this.config.containerEl) {
                this.container = $(this.config.containerEl);
            } else {
                this.container = $('#' + this.config.fieldId);
            }
            if (this.container.length === 0) {
                this.debugLog('容器未找到: ' + this.config.fieldId);
                return;
            }

            // 防重复初始化：若已初始化则直接返回，避免重复事件绑定导致二次弹窗
            if (this.container.data('featuredImagesInitialized')) {
                return;
            }
            this.container.data('featuredImagesInitialized', true);
            // 存储实例，便于委托事件在动态场景下访问
            this.container.data('featuredImagesInstance', this);
            
            // 兼容通用组件的预览区域类名
            this.previewArea = this.container.find('.featured-images-preview, .universal-images-preview');
            // 兼容通用组件的按钮类名
            this.addButton = this.container.find('.add-featured-image, .add-universal-image');
            
            this.bindEvents();
            this.initSortable();
            this.loadCurrentImages();  
        },
        
        // 绑定事件
        bindEvents: function() {
            var self = this;
            
            // 添加图片按钮点击事件（使用命名空间并在绑定前解绑，防重复）
            this.addButton.off('click.featuredImages')
                .on('click.featuredImages', function(e) {
                    e.preventDefault();
                    self.handleAddImage(this);
                });
            
            // 删除图片事件（事件委托，使用命名空间并在绑定前解绑，防重复）
            this.previewArea.off('click.featuredImages', '.remove-featured-image, .remove-universal-image')
                .on('click.featuredImages', '.remove-featured-image, .remove-universal-image', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    self.handleRemoveImage(this);
                });

            this.previewArea
                .off('mouseenter.featuredImagesPreview', '.featured-image-preview, .universal-image-preview')
                .on('mouseenter.featuredImagesPreview', '.featured-image-preview, .universal-image-preview', function() {
                    if (window.TodoCMS && window.TodoCMS.Utils && window.TodoCMS.Utils.ImagePreview) {
                        window.TodoCMS.Utils.ImagePreview.adjustPosition($(this), { previewSelector: 'img.previewimg', edgeCompensation: -30 });
                    }
                })
                .off('mouseleave.featuredImagesPreview', '.featured-image-preview, .universal-image-preview')
                .on('mouseleave.featuredImagesPreview', '.featured-image-preview, .universal-image-preview', function() {
                    if (window.TodoCMS && window.TodoCMS.Utils && window.TodoCMS.Utils.ImagePreview) {
                        window.TodoCMS.Utils.ImagePreview.resetPosition($(this), { previewSelector: 'img.previewimg' });
                    } else {
                        $(this).find('img.previewimg').css({ left: '', marginLeft: '', right: '', marginRight: '' });
                    }
                });
        },
        
        // 处理添加图片
        handleAddImage: function(button) {
            var self = this;
            var $button = $(button);
            var maxImages = parseInt($button.data('max-images')) || this.config.maxImages;
            // 确保 previewArea 已就绪，防止 this.previewArea 为空导致报错
            if (!this.previewArea || typeof this.previewArea.find !== 'function') {
                var $cont = $button.closest('.featured-images-component');
                if ($cont && $cont.length) {
                    if (!this.container || !this.container.length) {
                        this.container = $cont;
                    }
                    this.previewArea = $cont.find('.featured-images-preview, .universal-images-preview');
                }
            }
            var currentCount = (this.previewArea && typeof this.previewArea.find === 'function')
                ? this.previewArea.find('.featured-image-item, .universal-image-item').length
                : $button.closest('.featured-images-component').find('.featured-image-item, .universal-image-item').length;
            
            // 检查图片数量限制
            if (currentCount >= maxImages) {
                    if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils && TodoCMS.Utils.showMessage) {
                        TodoCMS.Utils.showMessage('最多只能添加 ' + maxImages + ' 张图片', 'warning');
                    } else {
                        console.warn('最多只能添加 ' + maxImages + ' 张图片');
                    }
                    return;
                }
            
            // 使用通用选择器打开媒体模态框，并确保多选状态正确
            var modalId = $button.data('modal-id') || (this.config.fieldId + '-modal') || 'media-modal';
            var isMultipleAttr = $button.attr('data-multiple');
            var isMultiple = (typeof isMultipleAttr !== 'undefined') ? (isMultipleAttr === 'true') : !!this.config.multiple;
            
            // 支持跨框架调用：尝试在当前窗口和父窗口中查找selectMediaForField函数
            var selectMediaForField = window.selectMediaForField;
            var targetWindow = window;
            
            // 如果当前窗口没有，尝试从父窗口获取
            if (typeof selectMediaForField !== 'function' && window.parent && window.parent !== window) {
                try {
                    if (typeof window.parent.selectMediaForField === 'function') {
                        selectMediaForField = window.parent.selectMediaForField;
                        targetWindow = window.parent;
                        self.debugLog('使用父窗口的selectMediaForField');
                    }
                } catch (e) {
                    self.debugLog('无法访问父窗口的selectMediaForField:', e.message);
                }
            }
            
            if (typeof selectMediaForField === 'function') {
                // 根据配置的文件类型决定模态框类型
                var ft = (self.config.fileType || '').toLowerCase();
                var modalType = 'file';
                if (ft === 'image') modalType = 'image';
                else if (ft === 'video') modalType = 'video';
                else if (ft === 'audio') modalType = 'audio';
                else modalType = 'file'; // any、document 等都归为 file

                var titleMap = {
                    image: '选择图片',
                    video: '选择视频',
                    audio: '选择音频',
                    file:  '选择文件'
                };

                // 使用找到的selectMediaForField函数，并使用正确的上下文
                selectMediaForField.call(targetWindow, {
                    title: titleMap[modalType] || '选择文件',
                    button_text: '选择',
                    multiple: isMultiple,
                    type: modalType,
                    modal_id: modalId,
                    callback: function(selectedMedia) {
                        self.debugLog('媒体选择回调', selectedMedia);
                        var mediaArray = Array.isArray(selectedMedia) ? selectedMedia : [selectedMedia];
                        self.addSelectedImages(mediaArray);
                        // 不需要手动关闭，media-modal会自动关闭
                    }
                });
            } else {
                // 兜底：仍然尝试使用 showModal，但无法变更 data-multiple
                window.mediaSelectCallback = function(selectedMedia) {
                    self.debugLog('媒体选择回调', selectedMedia);
                    var mediaArray = Array.isArray(selectedMedia) ? selectedMedia : [selectedMedia];
                    self.addSelectedImages(mediaArray);
                };
                if (typeof window.showModal === 'function') {
                    window.showModal(modalId);
                    this.debugLog('媒体模态框已显示');
                } else {
                    var fallbackModal = document.getElementById(modalId) || document.getElementById('media-modal');
                    if (fallbackModal) {
                        fallbackModal.style.display = 'flex';
                        fallbackModal.style.alignItems = 'center';
                        fallbackModal.style.justifyContent = 'center';
                        fallbackModal.classList.add('show');
                        document.body.style.overflow = 'hidden';
                    }
                    this.debugLog('媒体模态框已显示（兜底方案）');
                }
            }
        },
        
        // 处理删除图片
        handleRemoveImage: function(button) {
            var self = this;
            var $button = $(button);
            var $item = $button.closest('.featured-image-item, .universal-image-item');
            var index = $item.data('index');
            
            $item.fadeOut(300, function() {
                $(this).remove();
                // 删除完成后重新更新数据和状态
                self.updateImageData();
                self.updatePreviewState();
            });
            
            this.debugLog('图片已删除', { index: index });
        },
        
        // 添加选中的图片
        addSelectedImages: function(selectedMedia) {
            if (!selectedMedia || selectedMedia.length === 0) {
                this.debugLog('没有选中的媒体文件');
                return;
            }
            
            var self = this;
            var multiple = this.config.multiple;

            if (this.isAvatarSquareCropEnabled()) {
                var first = Array.isArray(selectedMedia) ? selectedMedia[0] : selectedMedia;
                var ref = (first && (first.mime_type || first.type)) ? (first.mime_type || first.type) : '';
                var urlRef = (first && (first.url || first.thumbnail)) ? (first.url || first.thumbnail) : '';
                var looksImage = (ref && ('' + ref).toLowerCase().indexOf('image/') === 0) || /\.(jpg|jpeg|jpe|png|gif|webp|bmp|svg|heic)$/i.test((urlRef || '').split('?')[0].split('#')[0]);
                var alreadyCropped = !!(first && first._todoCropped);
                if (first && looksImage && !alreadyCropped) {
                    self.cropSquareAndUpload(first).then(function(croppedMedia) {
                        self.addSelectedImages([croppedMedia]);
                    }).catch(function(err) {
                        if (err && err.message === 'cancelled') {
                            return;
                        }
                        self.showMessage('头像裁剪失败，请重试或先将图片上传到媒体库后再选择。', 'error');
                    });
                    return;
                }
            }
            
            // 如果是单选模式，清空现有图片
            if (!multiple) {
                this.previewArea.empty();
                this.currentImages = [];
            }
            
            // 添加新图片
            $.each(selectedMedia, function(index, media) {
                var itemHtml = self.createItemHtml(media, self.currentImages.length, self.config.fileType);
                var $item = $(itemHtml);
                // 为非图片类型提供数据回填所需的信息
                $item.data('media', media);
                if (media && typeof media === 'object') {
                    if (media.url) { $item.attr('data-url', self.toRelativeUrl(media.url)); }
                    if (media.mime_type) { $item.attr('data-mime', media.mime_type); }
                }
                self.previewArea.append($item);
                if (window.TodoCMS && window.TodoCMS.Utils && window.TodoCMS.Utils.ImagePreview) {
                    window.TodoCMS.Utils.ImagePreview.ensureClones($item, { containerSelector: '.featured-image-preview, .universal-image-preview', previewClass: 'previewimg' });
                }
                self.currentImages.push(media);
            });
            
            this.updateImageData();
            this.updatePreviewState();
            
            this.debugLog('图片添加完成', {
                count: selectedMedia.length,
                total: this.currentImages.length
            });
        },
        
        // 创建通用项HTML（图片/视频/音频/文档）
        createItemHtml: function(media, index, fileType) {
            var url = media.url || media.thumbnail || '';
            var storedUrl = this.toRelativeUrl(url);
            var displayUrl = this.toAbsoluteUrl(storedUrl);
            var title = media.title || media.filename || '未命名文件';
            var id = media.id || '';
            var mime = media.mime_type || '';
            var draggableAttr = this.config.multiple ? ' draggable="true"' : '';
            var normalizedMime = (mime || '').toString().trim().toLowerCase();
            var mimeForDetection = (normalizedMime && normalizedMime !== 'application/octet-stream' && normalizedMime !== 'text/plain') ? normalizedMime : '';
            var ref = (media.filename || url || '').toString().split('?')[0].split('#')[0];
            var isImage = mimeForDetection ? mimeForDetection.indexOf('image/') === 0 : /\.(jpg|jpeg|jpe|png|gif|webp|bmp|svg|ico|heic)$/i.test(ref);
            var isVideo = mimeForDetection ? mimeForDetection.indexOf('video/') === 0 : /\.(mp4|m4v|avi|mov|wmv|flv|webm|mkv)$/i.test(ref);
            var isAudio = mimeForDetection ? mimeForDetection.indexOf('audio/') === 0 : /\.(mp3|m4a|m4b|wav|ogg|aac|flac)$/i.test(ref);
            var isDocument = mimeForDetection
                ? (/^application\/pdf/.test(mimeForDetection) ||
                    /^application\/msword/.test(mimeForDetection) ||
                    /^application\/vnd\.ms-excel/.test(mimeForDetection) ||
                    /^application\/vnd\.ms-powerpoint/.test(mimeForDetection) ||
                    /^application\/vnd\.openxmlformats-officedocument\.wordprocessingml/.test(mimeForDetection) ||
                    /^application\/vnd\.openxmlformats-officedocument\.spreadsheetml/.test(mimeForDetection) ||
                    /^application\/vnd\.openxmlformats-officedocument\.presentationml/.test(mimeForDetection) ||
                    /^text\/(csv|sql)/.test(mimeForDetection))
                : /\.(pdf|doc|docx|xls|xlsx|ppt|pptx|txt|csv|sql)$/i.test(ref);
            var isZip = mimeForDetection
                ? (/^application\/zip/.test(mimeForDetection) ||
                    /^application\/x-zip-compressed/.test(mimeForDetection) ||
                    /^application\/x-rar/.test(mimeForDetection) ||
                    /^application\/vnd\.rar/.test(mimeForDetection) ||
                    /^application\/x-rar-compressed/.test(mimeForDetection) ||
                    /^application\/x-7z-compressed/.test(mimeForDetection) ||
                    /^application\/x-tar/.test(mimeForDetection) ||
                    /^application\/gzip/.test(mimeForDetection))
                : /\.(zip|rar|7z|tar|gz)$/i.test(ref);
            
            var previewHtml = '';
            if (isImage) {
                previewHtml = '<img src="' + displayUrl + '" alt="' + title + '" title="' + storedUrl + '">';
            } else if (isVideo) {
                previewHtml = '<div style="font-size: 40px; color: #a0a0aa;">▶</div>';
            } else if (isAudio) {
                previewHtml = '<div style="font-size: 40px; color: #a0a0aa;">♫</div>';
            } else if (isDocument) {
                previewHtml = '<div style="font-size: 40px; color: #a0a0aa;">⎗</div>';
            } else if (isZip) {
                previewHtml = '<div style="font-size: 40px; color: #a0a0aa;">❒</div>';
            } else {
                previewHtml = '<div style="font-size: 30px; color: #a0a0aa;">?</div>';
            }
            
            // 容器类名兼容
            var itemClass = 'featured-image-item';
            var previewClass = 'featured-image-preview';
            var actionsClass = 'featured-image-actions';
            var titleClass = 'featured-image-title';
            if (this.container.hasClass('universal-images-component')) {
                itemClass = 'universal-image-item';
                previewClass = 'universal-image-preview';
                actionsClass = 'universal-image-actions';
                titleClass = 'universal-image-title';
            }
            
            var seoKeywords = (media.seo_keywords || '').toString().trim();
            var kwBadgeHtml = seoKeywords ? '<span class="media-seo-kw-badge">' + seoKeywords + '</span>' : '';
            var cleanTitle = (title || '').toString();
            if (seoKeywords) {
                var kwTags = seoKeywords.toLowerCase().split(/[\s,]+/);
                kwTags.forEach(function(tag) {
                    tag = tag.trim();
                    if (tag) {
                        while (cleanTitle.toLowerCase().indexOf(tag) === 0) {
                            cleanTitle = cleanTitle.substring(tag.length);
                        }
                    }
                });
            }

            return '<div class="' + itemClass + '" data-index="' + index + '" data-id="' + id + '" data-url="' + (storedUrl || '') + '" data-mime="' + (mime || '') + '" data-seo-keywords="' + (seoKeywords || '') + '"' + draggableAttr + '>' +
                '<div class="' + previewClass + '">' + previewHtml + '</div>' +
                '<div class="' + actionsClass + '">' +
                    '<button type="button" class="button remove-universal-image remove-featured-image" title="移除">x</button>' +
                '</div>' +
                '<div class="featured-image-info universal-image-info">' +
                    '<div class="' + titleClass + '">' + kwBadgeHtml + '<span class="image-title-text">' + cleanTitle + '</span></div>' +
                '</div>' +
            '</div>';
        },
        
        // 更新图片数据
        updateImageData: function() {
            var self = this;
            var imageData = [];
            
            this.previewArea.find('.featured-image-item, .universal-image-item').each(function(index) {
                var $item = $(this);
                var $img = $item.find('img');
                var url = $item.attr('data-url') || '';
                if (!url) {
                    url = $img.length ? $img.attr('src') : '';
                }
                if (!url) {
                    // 尝试从数据上还原URL（通用文件可能没有img）
                    var media = $item.data('media');
                    if (media && media.url) url = media.url;
                }
                url = self.toRelativeUrl(url);
                var seoKeywords = $item.attr('data-seo-keywords') || $item.data('seo-keywords') || '';
                var $titleText = $item.find('.image-title-text');
                var cleanTitle = $titleText.length ? $titleText.text() : ($item.find('.featured-image-title, .universal-image-title').contents().filter(function() { return this.nodeType === 3; }).text() || '');
                cleanTitle = (cleanTitle || '').toString().trim();
                if (seoKeywords) {
                    var kwTags = seoKeywords.toLowerCase().split(/[\s,]+/);
                    kwTags.forEach(function(tag) {
                        tag = tag.trim();
                        if (tag) {
                            while (cleanTitle.toLowerCase().indexOf(tag) === 0) {
                                cleanTitle = cleanTitle.substring(tag.length);
                            }
                        }
                    });
                }
                imageData.push({
                    id: $item.data('id') || '',
                    url: url,
                    title: cleanTitle,
                    seo_keywords: seoKeywords,
                    index: index
                });
                
                // 更新索引
                $item.data('index', index);
            });

            
            // 更新隐藏字段：优先在同一组内按 name 精确定位，避免隐藏模板的重复 ID 干扰
            var $group = this.container.closest('.ad-image-group, .ad-media-item, .ad-code-item');
            var selectorByName = 'input[type="hidden"][name="' + this.config.fieldName + '"]';
            var $hiddenField = $group.length ? $group.find(selectorByName) : $();

            if ($hiddenField.length === 0) {
                // 回退1：在组件容器相邻位置查找（隐藏字段通常是容器的兄弟节点）
                var $siblings = this.container.siblings('input[type="hidden"][name="' + this.config.fieldName + '"]');
                if ($siblings.length) {
                    $hiddenField = $siblings.first();
                }
            }

            if ($hiddenField.length === 0) {
                // 回退2：在当前组件容器内按ID查找
                $hiddenField = this.container.find('#' + this.config.fieldId + '_data');
            }
            if ($hiddenField.length === 0) {
                // 回退3：全局按ID查找（兼容旧结构）
                $hiddenField = $('#' + this.config.fieldId + '_data');
            }
            if ($hiddenField.length === 0) {
                // 最后回退：创建一个隐藏字段
                $hiddenField = $('<input type="hidden" id="' + this.config.fieldId + '_data" name="' + this.config.fieldName + '">');
                if ($group.length) {
                    $group.append($hiddenField);
                } else {
                    this.container.before($hiddenField);
                }
            }

            $hiddenField.val(JSON.stringify(imageData));
            
            this.currentImages = imageData;
            
            // 向外部发布事件，便于父页面同步子项配置
            try {
                if (typeof this.container.trigger === 'function') {
                    this.container.trigger('todo:images-updated', [{
                        images: imageData,
                        fieldId: this.config.fieldId,
                        fieldName: this.config.fieldName
                    }]);
                } else if (this.container[0] && typeof this.container[0].dispatchEvent === 'function') {
                    var evt = new CustomEvent('todo:images-updated', { detail: { images: imageData, fieldId: this.config.fieldId, fieldName: this.config.fieldName } });
                    this.container[0].dispatchEvent(evt);
                }
            } catch (e) {
                // 忽略事件派发异常
            }
        },
        
        // 更新预览状态
        updatePreviewState: function() {
            var count = this.previewArea.find('.featured-image-item, .universal-image-item').length;
            var maxImages = this.config.maxImages;
            
            // 更新添加按钮状态
            if (count >= maxImages) {
                this.addButton.hide();
            } else {
                this.addButton.show();
            }
            
            // 更新容器属性
            this.container.attr('data-count', count);
        },
        
        // 初始化拖拽排序
        initSortable: function() {
            var self = this;
            
            if (this.config.multiple) {
                // 检查jQuery UI sortable是否可用
                if ($.fn.sortable) {
                    this.previewArea.sortable({
                        items: '.featured-image-item, .universal-image-item',
                        cursor: 'move',
                        opacity: 0.8,
                        placeholder: 'ui-sortable-placeholder',
                        tolerance: 'pointer',
                        containment: 'parent',
                        update: function(event, ui) {
                            self.updateImageData();
                            self.debugLog('图片排序已更新');
                        },
                        start: function(event, ui) {
                            ui.placeholder.height(ui.item.height());
                        }
                    });
                    
                    this.previewArea.addClass('sortable');
                } else {
                    // 如果jQuery UI不可用，使用原生HTML5拖拽
                    this.initNativeDragSort();
                }
            }
        },
        
        // 原生HTML5拖拽排序
        initNativeDragSort: function() {
            var self = this;
            
            this.previewArea.on('dragstart', '.featured-image-item, .universal-image-item', function(e) {
                $(this).addClass('dragging');
                e.originalEvent.dataTransfer.effectAllowed = 'move';
                e.originalEvent.dataTransfer.setData('text/html', $(this)[0].outerHTML);
                self.draggedElement = $(this);
            });
            
            this.previewArea.on('dragover', '.featured-image-item, .universal-image-item', function(e) {
                e.preventDefault();
                e.originalEvent.dataTransfer.dropEffect = 'move';
                
                var $this = $(this);
                var $dragging = $('.dragging');
                
                if ($this[0] !== $dragging[0]) {
                    var rect = this.getBoundingClientRect();
                    var midY = rect.top + rect.height / 2;
                    
                    if (e.originalEvent.clientY < midY) {
                        $this.before($dragging);
                    } else {
                        $this.after($dragging);
                    }
                }
            });
            
            this.previewArea.on('dragend', '.featured-image-item, .universal-image-item', function(e) {
                $(this).removeClass('dragging');
                self.updateImageData();
                self.debugLog('原生拖拽排序完成');
            });
            
            // 为图片项添加draggable属性（兼容通用项）
            this.previewArea.on('mouseenter', '.featured-image-item, .universal-image-item', function() {
                $(this).attr('draggable', true);
            });
            
            this.previewArea.addClass('native-sortable');
        },
        
        // 加载当前图片（优先从已渲染的DOM读取，避免重复渲染）
        loadCurrentImages: function() {
            var existingCount = this.previewArea.find('.featured-image-item, .universal-image-item').length;
            if (existingCount > 0) {
                // 直接从DOM构建 currentImages
                if (window.TodoCMS && window.TodoCMS.Utils && window.TodoCMS.Utils.ImagePreview) {
                    window.TodoCMS.Utils.ImagePreview.ensureClones(this.previewArea, { containerSelector: '.featured-image-preview, .universal-image-preview', previewClass: 'previewimg' });
                }
                this.updateImageData();
                this.updatePreviewState();
                return;
            }

            var currentImagesData = this.container.data('current-images');
            // 兼容字符串JSON
            if (typeof currentImagesData === 'string') {
                try { currentImagesData = JSON.parse(currentImagesData); } catch (e) { currentImagesData = []; }
            }
            
            if (currentImagesData && Array.isArray(currentImagesData)) {
                this.currentImages = currentImagesData;
                
                var self = this;
                $.each(currentImagesData, function(index, media) {
                    var imageHtml = self.createItemHtml(media, index, self.config.fileType);
                    self.previewArea.append(imageHtml);
                });
                if (window.TodoCMS && window.TodoCMS.Utils && window.TodoCMS.Utils.ImagePreview) {
                    window.TodoCMS.Utils.ImagePreview.ensureClones(this.previewArea, { containerSelector: '.featured-image-preview, .universal-image-preview', previewClass: 'previewimg' });
                }
                
                this.updatePreviewState();
                // 初始化后同步隐藏字段并派发事件
                this.updateImageData();
            }
        },
        
        // 调试日志
        debugLog: function(message, data) {
            if (!this.config.enableDebug) {
                return;
            }
            
            var timestamp = new Date().toLocaleTimeString();
            var logMessage = '[' + timestamp + '] FeaturedImages: ' + message;
            
            if (data) {
                console.log(logMessage, data);
            } else {
                console.log(logMessage);
            }
            
            // 如果页面有调试区域，也显示在页面上
            var $debugArea = $('.featured-images-debug');
            if ($debugArea.length > 0) {
                var debugHtml = '<div>[' + timestamp + '] ' + message;
                if (data) {
                    debugHtml += '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
                }
                debugHtml += '</div>';
                $debugArea.append(debugHtml);
                $debugArea.scrollTop($debugArea[0].scrollHeight);
            }
        }
    };
    
    // 自动初始化组件
    $(document).ready(function() {
        $('.featured-images-component').each(function() {
            var $container = $(this);
            var config = {
                fieldId: $container.attr('id'),
                fieldName: $container.data('field-name') || 'featured_images',
                multiple: $container.data('multiple') !== false,
                maxImages: parseInt($container.data('max-images')) || 6,
                enableDebug: $container.data('enable-debug') === true,
                fileType: ($container.data('file-type') || 'any')
            };
            
            new window.FeaturedImagesComponent(config);
        });

        // 全局委托：支持动态克隆出来的组件中的添加/删除按钮
        $(document)
            .off('click.featuredImagesDelegated', '.featured-images-component .add-featured-image, .featured-images-component .add-universal-image')
            .on('click.featuredImagesDelegated', '.featured-images-component .add-featured-image, .featured-images-component .add-universal-image', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var $container = $btn.closest('.featured-images-component');
                if ($container.length === 0) return;

                var instance = $container.data('featuredImagesInstance');
                if (!instance) {
                    // 动态初始化实例，避免由于克隆导致的事件丢失
                    var cfg = {
                        containerEl: $container,
                        fieldId: $container.attr('id') || ('featured_' + Date.now()),
                        fieldName: $container.data('field-name') || 'featured_images',
                        multiple: $container.data('multiple') !== false,
                        maxImages: parseInt($container.data('max-images')) || 6,
                        enableDebug: $container.data('enable-debug') === true,
                        fileType: ($container.data('file-type') || 'any')
                    };
                    instance = new window.FeaturedImagesComponent(cfg);
                }
                instance.handleAddImage(this);
            })
            .off('click.featuredImagesDelegated', '.featured-images-component .remove-featured-image, .featured-images-component .remove-universal-image')
            .on('click.featuredImagesDelegated', '.featured-images-component .remove-featured-image, .featured-images-component .remove-universal-image', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var $btn = $(this);
                var $container = $btn.closest('.featured-images-component');
                var instance = $container.data('featuredImagesInstance');
                if (!instance) {
                    var cfg = {
                        containerEl: $container,
                        fieldId: $container.attr('id') || ('featured_' + Date.now()),
                        fieldName: $container.data('field-name') || 'featured_images',
                        multiple: $container.data('multiple') !== false,
                        maxImages: parseInt($container.data('max-images')) || 6,
                        enableDebug: $container.data('enable-debug') === true,
                        fileType: ($container.data('file-type') || 'any')
                    };
                    instance = new window.FeaturedImagesComponent(cfg);
                }
                instance.handleRemoveImage(this);
            });
    });
    
})(jQuery);
