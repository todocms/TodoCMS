/**
 * 广告轮播JavaScript控制器
 * TodoCMS 主题 - 首页轮播图功能
 */

class HeroSlider {
    constructor(container, options = {}) {
        this.container = container;
        this.track = container.querySelector('.slider-track');
        this.realSlides = Array.from(container.querySelectorAll('.slide'));
        this.slides = this.realSlides;
        this.indicators = Array.from(container.querySelectorAll('.indicator'));
        this.indicatorsContainer = container.querySelector('.slider-indicators');
        this.paginationEl = container.querySelector('.slider_pagination');
        this.controlsEl = container.querySelector('.slider-controls');
        this.paginationCurrentEl = this.paginationEl ? this.paginationEl.querySelector('.current') : null;
        this.paginationTotalEl = this.paginationEl ? this.paginationEl.querySelector('.total') : null;
        this.paginationCurrentTrackEl = null;
        this.paginationCurrentTotal = 0;
        this.paginationCurrentNumber = 0;
        this.paginationCurrentTrackIndex = 0;
        this.paginationCurrentPendingJumpTrackIndex = null;
        this.paginationCurrentHandlers = null;
        this.loadingEl = container.querySelector('.slider-loading');
        this.prevBtn = container.querySelector('.slider-prev');
        this.nextBtn = container.querySelector('.slider-next');
        this.previewEl = container.querySelector('.slider-preview');
        this.previewTrackEl = null;
        this.previewMode = 'next';
        this.previewHandlers = null;
        this.previewTotal = 0;
        this.previewCurrentIndex = -1;
        this.previewCurrentTrackIndex = 0;
        this.previewPendingJumpTrackIndex = null;
        this.canvasEl = null;
        this.canvasCtx = null;
        this.webglCanvasEl = null;
        this.webglCtx = null;
        this.webglProgram = null;
        this.webglLocations = null;
        this.webglBuffer = null;
        this.webglTextures = null;
        this.webglActiveImages = { current: null, next: null };
        this.webglSupported = null;
        this.canvasMetrics = { cssWidth: 0, cssHeight: 0, ratio: 1 };
        this.canvasTransitionState = null;
        this.canvasRafId = null;
        this.canvasContentState = null;
        this.effectBufferCanvas = null;
        this.effectBufferCtx = null;
        this.pixelBufferCanvas = null;
        this.pixelBufferCtx = null;
        this.nativeDragStartHandler = null;
        this.preloadedSlideImages = [];
        this.preloadSlidePromises = [];
        this.preloadAllPromise = null;
        
        // 配置选项
        this.options = {
            autoPlay: false,
            autoPlayInterval: 5000,
            pauseOnHover: true,
            showControls: true,
            showIndicators: true,
            showLoading: true,
            transition: 'fade',
            canvasEffect: 'fade',
            canvasContent: 'fixed',
            canvasDuration: 700,
            canvasScale: 1,
            canvasMaxPixelRatio: 2,
            listPerView: 1,
            listStep: 1,
            listGap: 0,
            listLoop: true,
            ...options
        };
        
        // 状态变量
        this.currentSlide = -1;
        this.isPlaying = this.options.autoPlay;
        this.autoPlayTimer = null;
        this.isTransitioning = false;
        this.currentTrackIndex = 0;
        this.pendingLoopJump = null;
        this.suppressClickUntil = 0;
        this.dragState = null;
        this.listConfig = null;
        
        this.init();
    }

    formatSlideNumber(value, total) {
        const n = Math.max(0, Number(value) || 0);
        const t = Math.max(0, Number(total) || 0);
        const width = Math.max(2, String(t || 0).length);
        return String(n).padStart(width, '0');
    }

    setupPaginationCurrentScroller() {
        if (!this.paginationEl || !this.paginationCurrentEl) {
            return;
        }

        const totalSlides = this.realSlides.length;
        if (totalSlides <= 0) {
            this.paginationCurrentEl.textContent = '';
            return;
        }

        this.paginationCurrentTotal = totalSlides;
        this.paginationCurrentNumber = 0;
        this.paginationCurrentTrackIndex = 0;
        this.paginationCurrentPendingJumpTrackIndex = null;
        this.paginationCurrentEl.innerHTML = '';

        const track = document.createElement('span');
        track.className = 'slider-pagination-current-track';
        this.paginationCurrentEl.appendChild(track);
        this.paginationCurrentTrackEl = track;

        const makeItem = (num) => {
            const item = document.createElement('span');
            item.className = 'slider-pagination-current-item';
            item.textContent = this.formatSlideNumber(num, totalSlides);
            return item;
        };

        track.appendChild(makeItem(totalSlides));
        for (let n = 1; n <= totalSlides; n++) {
            track.appendChild(makeItem(n));
        }
        track.appendChild(makeItem(1));

        const onTransitionEnd = (e) => {
            if (!this.paginationCurrentTrackEl) return;
            if (e.target !== this.paginationCurrentTrackEl) return;
            if (e.propertyName !== 'transform') return;
            if (this.paginationCurrentPendingJumpTrackIndex === null) return;
            const jumpTo = this.paginationCurrentPendingJumpTrackIndex;
            this.paginationCurrentPendingJumpTrackIndex = null;
            this.setPaginationCurrentTrackIndex(jumpTo, false);
        };

        track.addEventListener('transitionend', onTransitionEnd);
        this.paginationCurrentHandlers = { onTransitionEnd };
    }

    getPaginationCurrentItemHeightPx() {
        if (!this.paginationCurrentEl) return 0;
        const rect = this.paginationCurrentEl.getBoundingClientRect();
        const h = rect && Number.isFinite(rect.height) ? rect.height : 0;
        if (h > 0) return h;
        const computed = window.getComputedStyle(this.paginationCurrentEl);
        const lineHeight = parseFloat(computed.lineHeight || '');
        return Number.isFinite(lineHeight) && lineHeight > 0 ? lineHeight : 0;
    }

    setPaginationCurrentTrackIndex(trackIndex, animate = true) {
        if (!this.paginationCurrentTrackEl) return;
        const total = this.paginationCurrentTotal;
        const maxIndex = Math.max(0, total + 1);
        const idx = Math.max(0, Math.min(maxIndex, Number(trackIndex) || 0));
        const itemH = this.getPaginationCurrentItemHeightPx();
        const y = itemH > 0 ? (-idx * itemH) : 0;

        if (!animate) {
            this.paginationCurrentTrackEl.style.transition = 'none';
            this.paginationCurrentTrackEl.style.transform = `translate3d(0, ${y}px, 0)`;
            this.paginationCurrentTrackIndex = idx;
            void this.paginationCurrentTrackEl.offsetWidth;
            this.paginationCurrentTrackEl.style.transition = '';
            return;
        }

        this.paginationCurrentTrackEl.style.transform = `translate3d(0, ${y}px, 0)`;
        this.paginationCurrentTrackIndex = idx;
    }

    updatePagination(index = this.currentSlide, force = false, direction = null) {
        if (!this.paginationEl || (!this.paginationCurrentEl && !this.paginationTotalEl)) {
            return;
        }

        const totalSlides = this.realSlides.length;
        const current = totalSlides > 0 ? Math.max(1, Math.min(totalSlides, Number(index) + 1)) : 0;

        if (this.paginationTotalEl) {
            this.paginationTotalEl.textContent = this.formatSlideNumber(totalSlides, totalSlides);
        }
        if (!this.paginationCurrentEl) return;

        if (!this.paginationCurrentTrackEl || this.paginationCurrentTotal !== totalSlides) {
            this.setupPaginationCurrentScroller();
        }

        if (!this.paginationCurrentTrackEl || totalSlides <= 0) {
            this.paginationCurrentEl.textContent = this.formatSlideNumber(current, totalSlides);
            return;
        }

        const targetNumber = current;
        const dir = direction === 'prev' ? 'prev' : 'next';

        if (force || this.paginationCurrentNumber <= 0) {
            this.paginationCurrentPendingJumpTrackIndex = null;
            this.setPaginationCurrentTrackIndex(targetNumber, false);
            this.paginationCurrentNumber = targetNumber;
            return;
        }

        if (dir === 'next' && this.paginationCurrentNumber === totalSlides && targetNumber === 1) {
            this.paginationCurrentPendingJumpTrackIndex = 1;
            this.setPaginationCurrentTrackIndex(totalSlides + 1, true);
            this.paginationCurrentNumber = targetNumber;
            return;
        }

        if (dir === 'prev' && this.paginationCurrentNumber === 1 && targetNumber === totalSlides) {
            this.paginationCurrentPendingJumpTrackIndex = totalSlides;
            this.setPaginationCurrentTrackIndex(0, true);
            this.paginationCurrentNumber = targetNumber;
            return;
        }

        this.paginationCurrentPendingJumpTrackIndex = null;
        this.setPaginationCurrentTrackIndex(targetNumber, true);
        this.paginationCurrentNumber = targetNumber;
    }
    
    init() {
        if (this.realSlides.length === 0) {
            return;
        }
        
        this.options.transition = this.normalizeTransition(this.options.transition);
        if (this.options.transition === 'canvas' && !this.isCanvasSupported()) {
            this.options.transition = 'fade';
        }
        this.options.canvasContent = this.normalizeCanvasContent(this.options.canvasContent);
        this.applyTransitionMode();
        this.applyCanvasContentMode();
        this.setupSlideMode();
        this.buildIndicators();
        this.setupCanvasMode();
        this.preloadSlideImages();
        this.disableNativeDrag();
        this.setupEventListeners();
        this.setupAutoPlay();
        this.setupAccessibility();
        this.setupPreview();
        this.setupPaginationCurrentScroller();
        
        // 确保第一张幻灯片是激活状态
        this.showSlide(0, true);

        this.handleLoading();
    }
    
    handleLoading() {
        if (!this.loadingEl) {
            return;
        }
        this.loadingEl.style.display = '';

        const waitPromise = this.options.transition === 'canvas' && this.preloadAllPromise
            ? this.preloadAllPromise
            : Promise.all(
                Array.from(this.container.querySelectorAll('.slide img')).map(img => new Promise(resolve => {
                    if (img.complete) {
                        resolve(true);
                        return;
                    }
                    const done = () => resolve(true);
                    img.addEventListener('load', done, { once: true });
                    img.addEventListener('error', done, { once: true });
                }))
            );

        if (!this.options.showLoading && this.loadingEl) {
            this.loadingEl.style.display = 'none';
        }

        waitPromise.then(() => {
            if (this.loadingEl) {
                this.loadingEl.style.display = 'none';
            }
            if (this.options.transition === 'canvas') {
                this.redrawCanvas();
            }
        });

        // 监听来自异步懒加载引擎派发的 thumbhash-loaded 事件，原图就绪后自动重绘 Canvas
        const handleThumbHashLoaded = (e) => {
            if (this.options.transition === 'canvas') {
                this.preloadSlideImages();
                this.redrawCanvas();
            }
        };
        this.container.addEventListener('thumbhash-loaded', handleThumbHashLoaded);
        window.addEventListener('thumbhash-loaded', handleThumbHashLoaded);
    }

    getSlideImageUrl(slide) {
        if (!slide) return '';
        const img = slide.querySelector('img');
        if (img) {
            const dataSrc = img.getAttribute('data-src') || img.dataset.src || '';
            const src = img.getAttribute('src') || img.currentSrc || '';
            return String(dataSrc || src || '').trim();
        }
        return String(slide.getAttribute('data-src') || slide.getAttribute('data-url') || '').trim();
    }

    preloadSlideImages() {
        if (this.options.transition !== 'canvas') {
            this.preloadAllPromise = null;
            this.preloadedSlideImages = [];
            this.preloadSlidePromises = [];
            return;
        }

        const slides = this.realSlides || [];
        this.preloadedSlideImages = new Array(slides.length).fill(null);
        this.preloadSlidePromises = slides.map((slide, index) => {
            const url = this.getSlideImageUrl(slide);
            if (!url) return Promise.resolve(false);

            const img = new Image();
            img.decoding = 'async';
            const promise = new Promise(resolve => {
                const done = (ok) => resolve(Boolean(ok));
                if (img.complete && img.naturalWidth > 0 && img.naturalHeight > 0) {
                    this.preloadedSlideImages[index] = img;
                    done(true);
                    return;
                }
                img.addEventListener('load', () => {
                    this.preloadedSlideImages[index] = img;
                    if (this.options.transition === 'canvas') {
                        this.redrawCanvas();
                    }
                    done(true);
                }, { once: true });
                img.addEventListener('error', () => done(false), { once: true });
            });

            img.src = url;
            return promise;
        });

        this.preloadAllPromise = Promise.all(this.preloadSlidePromises).then(() => true);
    }

    buildIndicators() {
        if (!this.indicatorsContainer) {
            this.indicators = [];
            return;
        }
        if (!this.options.showIndicators || this.realSlides.length <= 1) {
            this.indicatorsContainer.innerHTML = '';
            this.indicatorsContainer.style.display = 'none';
            this.indicators = [];
            // 单条时同步隐藏 controls 和 pagination
            if (this.controlsEl) this.controlsEl.style.display = 'none';
            if (this.paginationEl) this.paginationEl.style.display = 'none';
            return;
        }

        this.indicatorsContainer.style.display = '';
        this.indicatorsContainer.innerHTML = '';

        const isList = this.options.transition === 'list';
        const total = this.realSlides.length;
        const step = isList ? this.getListStepValue() : 1;
        const perView = isList && this.listConfig && Number.isFinite(this.listConfig.perView) ? this.listConfig.perView : 1;
        const loop = isList && this.listConfig ? Boolean(this.listConfig.loop) : true;
        const maxStart = isList ? Math.max(0, total - Math.max(1, perView)) : 0;
        const indicatorCount = isList
            ? Math.max(1, (loop ? Math.ceil(total / Math.max(1, step)) : (Math.ceil(maxStart / Math.max(1, step)) + 1)))
            : total;

        for (let i = 0; i < indicatorCount; i++) {
            const indicator = document.createElement('button');
            indicator.type = 'button';
            indicator.className = 'indicator';
            if (isList) {
                const slideIndex = loop
                    ? (Math.min(total - 1, i * step))
                    : Math.min(maxStart, i * step);
                indicator.setAttribute('data-slide-index', String(slideIndex));
                indicator.setAttribute('aria-label', __t('转到第') + `${i + 1} 页`);
            } else {
                indicator.setAttribute('aria-label', __t('转到幻灯片') + `${i + 1}`);
            }
            this.indicatorsContainer.appendChild(indicator);
        }

        this.indicators = Array.from(this.indicatorsContainer.querySelectorAll('.indicator'));
    }

    setupPreview() {
        if (!this.previewEl) {
            return;
        }

        const total = this.realSlides.length;
        if (total <= 1) {
            this.previewEl.innerHTML = '';
            this.previewEl.style.display = 'none';
            return;
        }

        this.previewEl.style.display = '';
        this.previewEl.classList.remove('is-visible');
        this.previewEl.innerHTML = '';
        this.previewMode = 'next';
        this.previewTotal = total;
        this.previewCurrentIndex = -1;
        this.previewCurrentTrackIndex = 0;
        this.previewPendingJumpTrackIndex = null;

        const track = document.createElement('div');
        track.className = 'slider-preview-track';
        this.previewEl.appendChild(track);
        this.previewTrackEl = track;

        const createItem = (slide, index) => {
            const item = document.createElement('div');
            item.className = 'slider-preview-slide';
            item.setAttribute('data-preview-index', String(index));

            const slideType = slide ? (slide.getAttribute('data-type') || '').toLowerCase() : '';
            const imgUrl = this.getSlideImageUrl(slide);
            const video = slide ? slide.querySelector('video') : null;

            if (slideType === 'video' || video) {
                const videoUrl = slide ? (slide.getAttribute('data-url') || (video ? (video.getAttribute('src') || video.currentSrc || '') : '')).trim() : '';
                if (videoUrl) {
                    const v = document.createElement('video');
                    v.src = videoUrl;
                    v.preload = 'metadata';
                    v.muted = true;
                    v.playsInline = true;
                    v.setAttribute('playsinline', '');
                    item.appendChild(v);
                }
            } else if (slideType === 'html') {
                const htmlUrl = slide ? (slide.getAttribute('data-url') || '').trim() : '';
                if (htmlUrl) {
                    const iframe = document.createElement('iframe');
                    iframe.src = htmlUrl;
                    iframe.scrolling = 'no'; 
                    iframe.style.cssText = 'width:100%;height:100%;border:0;background-color:#fff;display:block;overflow:hidden;';
                    item.appendChild(iframe);
                }
            } else if (imgUrl) {
                const img = document.createElement('img');
                img.src = imgUrl;
                img.alt = slide ? (slide.getAttribute('data-alt') || '') : '';
                img.loading = 'lazy';
                item.appendChild(img);
            }

            return item;
        };

        const last = this.realSlides[total - 1];
        const first = this.realSlides[0];
        track.appendChild(createItem(last, -1));

        this.realSlides.forEach((slide, index) => {
            track.appendChild(createItem(slide, index));
        });

        track.appendChild(createItem(first, total));

        const update = () => this.updatePreview();
        this.container.addEventListener('slideChange', update);

        const showNext = () => {
            this.previewMode = 'next';
            this.previewEl.classList.add('is-visible');
            this.updatePreview();
        };
        const showPrev = () => {
            this.previewMode = 'prev';
            this.previewEl.classList.add('is-visible');
            this.updatePreview();
        };
        const hidePreview = () => {
            this.previewEl.classList.remove('is-visible');
        };

        const onPreviewTransitionEnd = (e) => {
            if (!this.previewTrackEl) return;
            if (e.target !== this.previewTrackEl) return;
            if (e.propertyName !== 'transform') return;
            if (this.previewPendingJumpTrackIndex === null) return;
            const jumpTo = this.previewPendingJumpTrackIndex;
            this.previewPendingJumpTrackIndex = null;
            this.setPreviewTrackIndex(jumpTo, false);
        };
        track.addEventListener('transitionend', onPreviewTransitionEnd);

        if (this.nextBtn) {
            this.nextBtn.addEventListener('mouseenter', showNext);
            this.nextBtn.addEventListener('focus', showNext);
            this.nextBtn.addEventListener('mouseleave', hidePreview);
            this.nextBtn.addEventListener('blur', hidePreview);
        }
        if (this.prevBtn) {
            this.prevBtn.addEventListener('mouseenter', showPrev);
            this.prevBtn.addEventListener('focus', showPrev);
            this.prevBtn.addEventListener('mouseleave', hidePreview);
            this.prevBtn.addEventListener('blur', hidePreview);
        }

        this.previewHandlers = { update, showNext, showPrev, hidePreview, onPreviewTransitionEnd };
        this.updatePreview();
    }

    isRTL() {
        return document.documentElement.dir === 'rtl' ||
               document.body.dir === 'rtl' ||
               (this.container && getComputedStyle(this.container).direction === 'rtl');
    }

    setPreviewTrackIndex(trackIndex, animate = true) {
        if (!this.previewTrackEl) return;
        const idx = Math.max(0, Math.min((this.previewTotal || 0) + 1, Number(trackIndex) || 0));
        const sign = this.isRTL() ? 1 : -1;
        const transformStr = `translate3d(${sign * idx * 100}%, 0, 0)`;

        if (!animate) {
            this.previewTrackEl.style.transition = 'none';
            this.previewTrackEl.style.transform = transformStr;
            this.previewCurrentTrackIndex = idx;
            void this.previewTrackEl.offsetWidth;
            this.previewTrackEl.style.transition = '';
            return;
        }
        this.previewTrackEl.style.transform = transformStr;
        this.previewCurrentTrackIndex = idx;
    }

    getPreviewTargetIndex(mode = this.previewMode) {
        const total = this.realSlides.length;
        if (total <= 0) return -1;

        const current = this.currentSlide >= 0 ? this.currentSlide : 0;
        const m = mode === 'prev' ? 'prev' : 'next';

        if (this.options.transition !== 'list') {
            return m === 'prev'
                ? (current - 1 + total) % total
                : (current + 1) % total;
        }

        const step = this.getListStepValue();
        const perView = this.listConfig && Number.isFinite(this.listConfig.perView) ? this.listConfig.perView : 1;
        const loop = this.listConfig ? Boolean(this.listConfig.loop) : true;

        if (loop) {
            return m === 'prev'
                ? (current - step + total) % total
                : (current + step) % total;
        }

        const maxStart = Math.max(0, total - Math.max(1, perView));
        const clamped = Math.max(0, Math.min(maxStart, current));

        if (m === 'next') {
            return Math.min(maxStart, clamped + step);
        }

        return Math.max(0, Math.floor((clamped - 1) / step) * step);
    }

    updatePreview() {
        if (!this.previewEl || !this.previewTrackEl || this.previewTotal <= 1) {
            return;
        }

        const targetIndex = this.getPreviewTargetIndex(this.previewMode);
        this.previewEl.setAttribute('data-preview-mode', this.previewMode === 'prev' ? 'prev' : 'next');
        const visible = this.previewEl.classList.contains('is-visible');

        if (targetIndex < 0) {
            return;
        }

        if (this.previewCurrentIndex < 0) {
            this.previewPendingJumpTrackIndex = null;
            this.setPreviewTrackIndex(targetIndex + 1, false);
            this.previewCurrentIndex = targetIndex;
            return;
        }

        const mode = this.previewMode === 'prev' ? 'prev' : 'next';
        const total = this.previewTotal;

        if (mode === 'next' && this.previewCurrentIndex === total - 1 && targetIndex === 0) {
            this.previewPendingJumpTrackIndex = 1;
            this.setPreviewTrackIndex(total + 1, visible);
            this.previewCurrentIndex = targetIndex;
            return;
        }

        if (mode === 'prev' && this.previewCurrentIndex === 0 && targetIndex === total - 1) {
            this.previewPendingJumpTrackIndex = total;
            this.setPreviewTrackIndex(0, visible);
            this.previewCurrentIndex = targetIndex;
            return;
        }

        this.previewPendingJumpTrackIndex = null;
        this.setPreviewTrackIndex(targetIndex + 1, visible);
        this.previewCurrentIndex = targetIndex;
    }

    setupEventListeners() {
        // 控制按钮事件
        if (this.prevBtn) {
            this.prevBtn.addEventListener('click', () => this.prevSlide());
        }
        
        if (this.nextBtn) {
            this.nextBtn.addEventListener('click', () => this.nextSlide());
        }
        
        // 指示器事件
        this.indicators.forEach((indicator, index) => {
            indicator.addEventListener('click', () => {
                const target = indicator.getAttribute('data-slide-index');
                const n = parseInt(target, 10);
                this.goToSlide(Number.isFinite(n) ? n : index);
            });
        });
        
        // 鼠标悬停暂停
        if (this.options.pauseOnHover) {
            this.container.addEventListener('mouseenter', () => this.pause());
            this.container.addEventListener('mouseleave', () => this.resume());
        }

        this.container.addEventListener('click', (e) => {
            if (Date.now() < this.suppressClickUntil) {
                e.preventDefault();
                e.stopPropagation();
            }
        }, true);
        
        // 键盘导航
        document.addEventListener('keydown', (e) => {
            if (this.container.matches(':hover')) {
                switch (e.key) {
                    case 'ArrowLeft':
                        e.preventDefault();
                        this.prevSlide();
                        break;
                    case 'ArrowRight':
                        e.preventDefault();
                        this.nextSlide();
                        break;
                    case ' ':
                        e.preventDefault();
                        this.toggleAutoPlay();
                        break;
                }
            }
        });

        const supportsPointer = typeof window !== 'undefined' && 'PointerEvent' in window;
        if (supportsPointer) {
            this.setupDragEvents();
        } else {
            this.setupTouchEvents();
        }
        
        // 窗口大小变化时重新计算
        window.addEventListener('resize', () => {
            this.handleResize();
        });
    }
    
    setupTouchEvents() {
        let startX = 0;
        let startY = 0;
        let endX = 0;
        let endY = 0;
        
        this.container.addEventListener('touchstart', (e) => {
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
        }, { passive: true });
        
        this.container.addEventListener('touchend', (e) => {
            endX = e.changedTouches[0].clientX;
            endY = e.changedTouches[0].clientY;
            
            const deltaX = endX - startX;
            const deltaY = endY - startY;
            
            // 确保是水平滑动且距离足够
            if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 50) {
                if (deltaX > 0) {
                    this.prevSlide();
                } else {
                    this.nextSlide();
                }
            }
        }, { passive: true });
    }

    setupDragEvents() {
        this.dragState = {
            active: false,
            pointerId: null,
            startX: 0,
            startY: 0,
            startTranslateX: 0,
            deltaX: 0,
            deltaY: 0,
            moved: false,
            captured: false
        };

        this.container.addEventListener('pointerdown', (e) => {
            if (!['fade', 'slide', 'canvas', 'list'].includes(this.options.transition)) return;
            if ((this.options.transition === 'slide' || this.options.transition === 'list') && !this.track) return;
            if (this.isTransitioning) return;
            if (e.pointerType === 'mouse' && e.button !== 0) return;
            if (this.options.transition === 'canvas' && (!this.isCanvasSupported() || this.realSlides.length <= 1)) return;
            if (this.options.transition === 'fade' && this.realSlides.length <= 1) return;
            if (e.target && e.target.closest && e.target.closest('.slider-controls, .slider-indicators')) return;

            if (this.options.transition === 'canvas' && (!this.canvasEl || !this.canvasCtx)) {
                this.setupCanvasMode();
            }

            this.dragState.active = true;
            this.dragState.pointerId = e.pointerId;
            this.dragState.startX = e.clientX;
            this.dragState.startY = e.clientY;
            this.dragState.deltaX = 0;
            this.dragState.deltaY = 0;
            this.dragState.moved = false;
            this.dragState.captured = false;
            this.dragState.startTranslateX = (this.options.transition === 'slide' || this.options.transition === 'list') ? this.getTrackTranslateX() : 0;
        });

        this.container.addEventListener('pointermove', (e) => {
            if (!this.dragState || !this.dragState.active) return;
            if (this.dragState.pointerId !== e.pointerId) return;

            this.dragState.deltaX = e.clientX - this.dragState.startX;
            this.dragState.deltaY = e.clientY - this.dragState.startY;

            if (!this.dragState.moved) {
                if (Math.abs(this.dragState.deltaY) > Math.abs(this.dragState.deltaX) && Math.abs(this.dragState.deltaY) > 10) {
                    this.finishDrag(e, true);
                    return;
                }
                if (Math.abs(this.dragState.deltaX) < 4) {
                    return;
                }
                this.dragState.moved = true;

                if (this.options.transition === 'slide' || this.options.transition === 'list' || this.options.transition === 'canvas' || this.options.transition === 'fade') {
                    this.pause();
                    this.container.classList.add('dragging');
                    if (!this.dragState.captured) {
                        try {
                            this.container.setPointerCapture(e.pointerId);
                            this.dragState.captured = true;
                        } catch (err) {
                        }
                    }
                }
            }

            e.preventDefault();

            const isRTL = this.isRTL();
            const rawDx = this.dragState.deltaX;
            const effectiveRawDx = isRTL ? -rawDx : rawDx;
            const width = (this.options.transition === 'list' ? this.getTrackUnitWidth() : this.getContainerWidth()) || 1;
            let maxDx = width;
            if (this.options.transition === 'list') {
                const direction = effectiveRawDx >= 0 ? 'prev' : 'next';
                const maxUnits = this.getMaxListDragUnits(direction);
                maxDx = width * Math.max(0, maxUnits);
            }
            const dx = Math.max(-maxDx, Math.min(maxDx, rawDx));

            if (this.options.transition === 'canvas') {
                this.renderCanvasDrag(dx);
                return;
            }

            if (!['slide', 'list'].includes(this.options.transition)) {
                return;
            }
            if (!this.track) return;
            const base = this.dragState.startTranslateX;
            const min = isRTL ? (base - maxDx) : (base - maxDx);
            const max = isRTL ? (base + maxDx) : (base + maxDx);
            const raw = base + dx;
            const x = Math.max(Math.min(min, max), Math.min(Math.max(min, max), raw));
            this.track.style.transform = `translate3d(${x}px, 0, 0)`;
        }, { passive: false });

        this.container.addEventListener('pointerup', (e) => {
            if (!this.dragState || !this.dragState.active) return;
            if (this.dragState.pointerId !== e.pointerId) return;
            this.finishDrag(e, false);
        });

        this.container.addEventListener('pointercancel', (e) => {
            if (!this.dragState || !this.dragState.active) return;
            if (this.dragState.pointerId !== e.pointerId) return;
            this.finishDrag(e, true);
        });
    }

    finishDrag(e, cancel) {
        if (!this.dragState) return;

        const moved = this.dragState.moved;
        const dx = this.dragState.deltaX;
        const isRTL = this.isRTL();
        const effectiveDx = isRTL ? -dx : dx;

        this.dragState.active = false;
        this.dragState.pointerId = null;
        this.container.classList.remove('dragging');

        if (this.dragState.captured) {
            try {
                if (e && e.pointerId !== undefined) {
                    this.container.releasePointerCapture(e.pointerId);
                }
            } catch (err) {
            }
        }

        if (this.options.transition === 'canvas') {
            this.finishCanvasDrag(moved, dx, cancel);
            return;
        }

        if (!['slide', 'list'].includes(this.options.transition)) {
            if (!moved || cancel) {
                this.resume();
                return;
            }

            this.suppressClickUntil = Date.now() + 400;

            const width = this.getContainerWidth() || 1;
            const threshold = Math.min(120, Math.max(40, width * 0.18));

            if (effectiveDx > threshold) {
                this.prevSlide();
            } else if (effectiveDx < -threshold) {
                this.nextSlide();
            }

            this.resume();
            return;
        }

        if (!moved) {
            this.resume();
            return;
        }

        if (cancel) {
            this.resume();
            const totalSlides = this.realSlides.length;
            const cloneCount = (this.options.transition === 'list' && this.listConfig && Number.isFinite(this.listConfig.cloneCount)) ? this.listConfig.cloneCount : 0;
            const trackIndex = this.options.transition === 'list'
                ? ((cloneCount > 0 && totalSlides > 1) ? (cloneCount + this.currentSlide) : this.currentSlide)
                : (totalSlides > 1 ? this.currentSlide + 1 : this.currentSlide);
            this.pendingLoopJump = null;
            const sign = isRTL ? 1 : -1;
            const targetX = sign * trackIndex * this.getTrackUnitWidth();
            const currentX = this.getTrackTranslateX();
            if (Math.abs(currentX - targetX) < 0.5) {
                this.isTransitioning = false;
                this.jumpToTrackIndex(trackIndex);
                return;
            }
            this.isTransitioning = true;
            this.animateToTrackIndex(trackIndex);
            return;
        }

        this.suppressClickUntil = Date.now() + 400;

        const isList = this.options.transition === 'list';
        const width = (isList ? this.getTrackUnitWidth() : this.getContainerWidth()) || 1;
        const threshold = Math.min(120, Math.max(40, width * 0.18));

        if (isList && this.realSlides.length > 0) {
            const absDx = Math.abs(dx);
            const dir = effectiveDx > 0 ? 'prev' : 'next';
            const total = this.realSlides.length;
            const step = this.getListStepValue();
            const maxUnits = this.getMaxListDragUnits(dir);
            const loop = this.listConfig ? Boolean(this.listConfig.loop) : true;
            const maxStart = this.listConfig ? Math.max(0, total - Math.max(1, this.listConfig.perView)) : Math.max(0, total - 1);
            const current = loop ? this.currentSlide : Math.max(0, Math.min(maxStart, this.currentSlide));

            if (absDx > threshold && maxUnits > 0) {
                const unitsMoved = Math.min(
                    maxUnits,
                    Math.max(1, Math.floor((absDx + width * 0.25) / width))
                );
                let target = current + (dir === 'next' ? unitsMoved : -unitsMoved);
                if (step > 1) {
                    target = dir === 'next'
                        ? (Math.ceil(target / step) * step)
                        : (Math.floor(target / step) * step);
                }
                this.showSlide(target, false, dir);
            } else {
                const totalSlides = this.realSlides.length;
                const cloneCount = (this.listConfig && Number.isFinite(this.listConfig.cloneCount)) ? this.listConfig.cloneCount : 0;
                const trackIndex = (cloneCount > 0 && totalSlides > 1) ? (cloneCount + this.currentSlide) : this.currentSlide;
                this.pendingLoopJump = null;
                const sign = isRTL ? 1 : -1;
                const targetX = sign * trackIndex * this.getTrackUnitWidth();
                const currentX = this.getTrackTranslateX();
                if (Math.abs(currentX - targetX) < 0.75) {
                    this.isTransitioning = false;
                    this.jumpToTrackIndex(trackIndex);
                } else {
                    this.isTransitioning = true;
                    this.animateToTrackIndex(trackIndex);
                }
            }
        } else if (effectiveDx > threshold) {
            this.prevSlide();
        } else if (effectiveDx < -threshold) {
            this.nextSlide();
        } else {
            const totalSlides = this.realSlides.length;
            const cloneCount = (this.options.transition === 'list' && this.listConfig && Number.isFinite(this.listConfig.cloneCount)) ? this.listConfig.cloneCount : 0;
            const trackIndex = this.options.transition === 'list'
                ? ((cloneCount > 0 && totalSlides > 1) ? (cloneCount + this.currentSlide) : this.currentSlide)
                : (totalSlides > 1 ? this.currentSlide + 1 : this.currentSlide);
            this.pendingLoopJump = null;
            this.isTransitioning = true;
            this.animateToTrackIndex(trackIndex);
        }

        this.resume();
    }

    getCanvasDragTargets(dx) {
        const total = this.realSlides.length;
        if (total <= 1) {
            return { from: this.currentSlide, to: this.currentSlide, direction: 'next' };
        }
        const effectiveDx = this.isRTL() ? -dx : dx;
        const dir = effectiveDx > 0 ? 'prev' : 'next';
        const from = this.currentSlide;
        const to = dir === 'prev'
            ? (from - 1 + total) % total
            : (from + 1) % total;
        return { from, to, direction: dir };
    }

    renderCanvasDrag(dx) {
        if (this.options.transition !== 'canvas') return;
        if (!this.canvasEl || !this.canvasCtx) return;
        if (this.currentSlide < 0 || this.realSlides.length <= 1) return;

        this.resizeCanvas();
        this.clearCanvas();

        const width = this.getContainerWidth() || 1;
        const clampedDx = Math.max(-width, Math.min(width, dx));
        const { from, to, direction } = this.getCanvasDragTargets(clampedDx);
        const dragProgress = Math.max(0, Math.min(1, Math.abs(clampedDx) / width));
        const fromImg = this.getSlideImageEl(from);
        const toImg = this.getSlideImageEl(to);
        const fromReady = this.isImageReadyForTexture(fromImg);
        const toReady = this.isImageReadyForTexture(toImg);

        if (direction === 'next') {
            if (fromReady) {
                this.drawSlideToCanvas(from, { x: clampedDx, alpha: 1 });
            }
            if (toReady) {
                this.drawSlideToCanvas(to, { x: clampedDx + width, alpha: 1 });
                this.renderCanvasDragOverlay(from, to, direction, clampedDx + width, dragProgress);
            }
            this.applyCanvasContentTransformsForDrag(from, to, direction, clampedDx, width);
            return;
        }

        if (fromReady) {
            this.drawSlideToCanvas(from, { x: clampedDx, alpha: 1 });
        }
        if (toReady) {
            this.drawSlideToCanvas(to, { x: clampedDx - width, alpha: 1 });
            this.renderCanvasDragOverlay(from, to, direction, clampedDx - width, dragProgress);
        }
        this.applyCanvasContentTransformsForDrag(from, to, direction, clampedDx, width);
    }

    renderCanvasDragOverlay(fromIndex, toIndex, direction, incomingX, dragProgress) {
        const effect = String(this.options.canvasEffect || '').trim().toLowerCase();

        if (effect !== 'displace') {
            if (this.webglCanvasEl) {
                this.webglCanvasEl.style.display = 'none';
                this.webglCanvasEl.style.transform = '';
                this.webglCanvasEl.style.opacity = '';
            }
        }

        if (effect === 'glitch') {
            if (!this.canvasCtx) return;
            if (dragProgress <= 0.05) return;

            const buffer = this.renderSlideToEffectBuffer(toIndex);
            if (!buffer) return;

            const { cssWidth, cssHeight } = this.canvasMetrics;
            const intensity = Math.max(0, Math.min(1, (dragProgress - 0.05) / 0.95));
            const baseSeed = Math.floor(dragProgress * 1000) + (direction === 'prev' ? 17 : 23);
            const slices = 6;
            const shiftBase = 18 + 52 * intensity;

            for (let i = 0; i < slices; i++) {
                const r1 = this.rand01(baseSeed + i * 11.3);
                const r2 = this.rand01(baseSeed + i * 17.9);
                const r3 = this.rand01(baseSeed + i * 29.7);
                const y = Math.floor(r1 * cssHeight);
                const h = Math.max(6, Math.floor((r2 * 22 + 6) * (0.7 + intensity)));
                const shift = (r3 * 2 - 1) * shiftBase;
                const sy = Math.max(0, Math.min(cssHeight - 1, y));
                const sh = Math.max(1, Math.min(cssHeight - sy, h));

                this.canvasCtx.save();
                this.canvasCtx.globalAlpha = (0.12 + 0.22 * intensity);
                this.canvasCtx.drawImage(buffer, 0, sy, cssWidth, sh, incomingX + shift, sy, cssWidth, sh);
                this.canvasCtx.restore();
            }
            return;
        }

        if (effect === 'pixelate') {
            if (!this.canvasCtx) return;
            if (dragProgress <= 0.04) return;
            const intensity = Math.max(0, Math.min(1, (dragProgress - 0.04) / 0.96));
            const pixelSize = 1 + (1 - intensity) * 40;
            this.drawSlidePixelated(toIndex, pixelSize, 0.85 * intensity, incomingX);
            return;
        }

        if (effect === 'displace') {
            this.renderWebGLDragOverlay(fromIndex, toIndex, incomingX, dragProgress, direction);
        }
    }

    renderWebGLDragOverlay(fromIndex, toIndex, incomingX, dragProgress, direction) {
        if (!this.webglCanvasEl) return;
        if (dragProgress <= 0.02) {
            this.hideWebGLCanvas();
            return;
        }
        if (!this.ensureWebGL()) {
            this.hideWebGLCanvas();
            return;
        }

        const mixFactor = Math.max(0, Math.min(1, dragProgress));
        const intensity = Math.max(0, Math.min(1, (mixFactor - 0.05) / 0.95));
        if (intensity <= 0.01) {
            this.hideWebGLCanvas();
            return;
        }

        this.resizeWebGL();
        this.webglCanvasEl.style.display = '';
        this.webglCanvasEl.style.visibility = 'hidden';
        this.webglCanvasEl.style.opacity = '0';
        const ok = this.renderWebGLDisplace(fromIndex, toIndex, mixFactor, direction, 0.12 + 0.18 * intensity);
        if (!ok) {
            this.hideWebGLCanvas();
            return;
        }
        if (this.webglCtx) this.webglCtx.flush();
        this.compositeWebGLDragOverlayToCanvas(incomingX, 0.8 * intensity);
        this.hideWebGLCanvas();
    }

    compositeWebGLDragOverlayToCanvas(x, alpha = 1) {
        if (!this.canvasCtx || !this.webglCanvasEl) return;
        const { cssWidth, cssHeight } = this.canvasMetrics;
        if (cssWidth <= 0 || cssHeight <= 0) return;

        this.canvasCtx.save();
        this.canvasCtx.globalAlpha = Math.max(0, Math.min(1, Number(alpha) || 0));
        this.canvasCtx.drawImage(this.webglCanvasEl, x, 0, cssWidth, cssHeight);
        this.canvasCtx.restore();
    }

    animateCanvasDrag(fromDx, toDx, onDone) {
        const start = (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
        const duration = 220;
        const from = Number(fromDx) || 0;
        const to = Number(toDx) || 0;

        const step = (now) => {
            const tNow = typeof now === 'number' ? now : ((typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now());
            const p = Math.max(0, Math.min(1, (tNow - start) / duration));
            const eased = this.easeOutCubic(p);
            const dx = from + (to - from) * eased;
            this.renderCanvasDrag(dx);
            if (p < 1) {
                this.canvasRafId = requestAnimationFrame(step);
                return;
            }
            this.canvasRafId = null;
            if (typeof onDone === 'function') onDone();
        };

        this.cancelCanvasAnimation();
        this.canvasRafId = requestAnimationFrame(step);
    }

    finishCanvasDrag(moved, dx, cancel) {
        const width = this.getContainerWidth() || 1;
        const clampedDx = Math.max(-width, Math.min(width, dx));

        if (!moved || cancel) {
            this.animateCanvasDrag(clampedDx, 0, () => {
                this.resetCanvasContentTransforms();
                this.hideWebGLCanvas();
                this.isTransitioning = false;
                this.redrawCanvas();
                this.resume();
            });
            return;
        }

        this.suppressClickUntil = Date.now() + 400;
        const threshold = Math.min(120, Math.max(40, width * 0.18));
        const isRTL = this.isRTL();
        const effectiveDx = isRTL ? -clampedDx : clampedDx;
        const shouldPrev = effectiveDx > threshold;
        const shouldNext = effectiveDx < -threshold;

        if (!shouldPrev && !shouldNext) {
            this.animateCanvasDrag(clampedDx, 0, () => {
                this.resetCanvasContentTransforms();
                this.hideWebGLCanvas();
                this.isTransitioning = false;
                this.redrawCanvas();
                this.resume();
            });
            return;
        }

        const { to, direction } = this.getCanvasDragTargets(clampedDx);
        const targetDx = shouldPrev ? (isRTL ? -width : width) : (isRTL ? width : -width);
        this.animateCanvasDrag(clampedDx, targetDx, () => {
            this.resetCanvasContentTransforms();
            this.hideWebGLCanvas();
            this.isTransitioning = false;
            this.showSlideCanvas(to, true, direction);
            this.resume();
        });
    }
    
    setupAutoPlay() {
        if (this.options.autoPlay && this.realSlides.length > 1) {
            this.startAutoPlay();
        }
    }
    
    setupAccessibility() {
        // 为轮播容器添加ARIA属性
        this.container.setAttribute('role', 'region');
        this.container.setAttribute('aria-label', __t('图片轮播'));
        if (!this.container.hasAttribute('tabindex')) {
            this.container.tabIndex = -1;
        }
        
        // 为幻灯片添加ARIA属性
        this.realSlides.forEach((slide, index) => {
            slide.setAttribute('role', 'tabpanel');
            slide.setAttribute('aria-label', __t('幻灯片') + `${index + 1} / ${this.realSlides.length}`);
            slide.setAttribute('aria-hidden', index !== this.currentSlide ? 'true' : 'false');
        });
        
        // 为控制按钮添加ARIA属性
        if (this.prevBtn) {
            this.prevBtn.setAttribute('aria-label', __t('上一张幻灯片'));
        }
        
        if (this.nextBtn) {
            this.nextBtn.setAttribute('aria-label', __t('下一张幻灯片'));
        }
        
        // 为指示器添加ARIA属性
        this.indicators.forEach((indicator, index) => {
            indicator.setAttribute('role', 'tab');
            indicator.setAttribute('aria-label', __t('转到幻灯片') + `${index + 1}`);
            indicator.setAttribute('aria-selected', index === this.currentSlide ? 'true' : 'false');
        });
    }

    defocusIfHidingFocusedSlide(nextIndex) {
        if (typeof document === 'undefined') return;
        const activeEl = document.activeElement;
        if (!activeEl || activeEl === document.body) return;
        for (let i = 0; i < this.realSlides.length; i++) {
            if (i === nextIndex) continue;
            const slide = this.realSlides[i];
            if (!slide) continue;
            if (!slide.contains(activeEl)) continue;
            try {
                if (typeof activeEl.blur === 'function') activeEl.blur();
            } catch (e) {
            }
            try {
                this.container.focus({ preventScroll: true });
            } catch (e) {
                try {
                    this.container.focus();
                } catch (e2) {
                }
            }
            return;
        }
    }

    normalizeTransition(value) {
        const allowed = ['fade', 'slide', 'canvas', 'list'];
        const transition = String(value || '').trim().toLowerCase();
        return allowed.includes(transition) ? transition : 'fade';
    }

    applyTransitionMode() {
        const modeClasses = ['transition-fade', 'transition-slide', 'transition-canvas', 'transition-list'];
        this.container.classList.remove(...modeClasses);
        this.container.classList.add(`transition-${this.options.transition}`);
    }

    normalizeCanvasContent(value) {
        const v = String(value || '').trim().toLowerCase();
        return v === 'follow' ? 'follow' : 'fixed';
    }

    applyCanvasContentMode() {
        const classes = ['canvas-content-fixed', 'canvas-content-follow'];
        this.container.classList.remove(...classes);
        const mode = this.normalizeCanvasContent(this.options.canvasContent);
        this.options.canvasContent = mode;
        this.container.classList.add(`canvas-content-${mode}`);
        if (mode === 'fixed') {
            this.resetCanvasContentTransforms();
        }
    }

    resetCanvasContentTransforms() {
        const state = this.canvasContentState;
        if (!state) return;

        const indices = [state.from, state.to].filter(i => Number.isInteger(i) && i >= 0);
        indices.forEach((i) => {
            const slide = this.realSlides[i];
            if (!slide) return;
            slide.style.transform = '';
            slide.style.opacity = '';
            slide.style.transition = '';
            slide.style.willChange = '';
        });

        this.canvasContentState = null;
    }

    applyCanvasContentTransformsForDrag(fromIndex, toIndex, direction, clampedDx, width) {
        if (this.options.transition !== 'canvas') return;
        if (this.options.canvasContent !== 'follow') return;

        const prevState = this.canvasContentState;
        if (!prevState || prevState.from !== fromIndex || prevState.to !== toIndex) {
            this.resetCanvasContentTransforms();
        }

        const fromSlide = this.realSlides[fromIndex];
        const toSlide = this.realSlides[toIndex];
        if (!fromSlide || !toSlide) return;

        const incomingX = direction === 'next' ? (clampedDx + width) : (clampedDx - width);

        fromSlide.style.willChange = 'transform';
        fromSlide.style.transition = 'none';
        fromSlide.style.opacity = '1';
        fromSlide.style.transform = `translate3d(${clampedDx}px, 0, 0)`;

        toSlide.style.willChange = 'transform';
        toSlide.style.transition = 'none';
        toSlide.style.opacity = '1';
        toSlide.style.transform = `translate3d(${incomingX}px, 0, 0)`;

        this.canvasContentState = { from: fromIndex, to: toIndex };
    }

    applyCanvasContentTransformsForTransition(fromIndex, toIndex, fromX, toX) {
        if (this.options.transition !== 'canvas') return;
        if (this.options.canvasContent !== 'follow') return;

        const prevState = this.canvasContentState;
        if (!prevState || prevState.from !== fromIndex || prevState.to !== toIndex) {
            this.resetCanvasContentTransforms();
        }

        const fromSlide = this.realSlides[fromIndex];
        const toSlide = this.realSlides[toIndex];
        if (!fromSlide || !toSlide) return;

        fromSlide.style.willChange = 'transform';
        fromSlide.style.transition = 'none';
        fromSlide.style.opacity = '1';
        fromSlide.style.transform = `translate3d(${fromX}px, 0, 0)`;

        toSlide.style.willChange = 'transform';
        toSlide.style.transition = 'none';
        toSlide.style.opacity = '1';
        toSlide.style.transform = `translate3d(${toX}px, 0, 0)`;

        this.canvasContentState = { from: fromIndex, to: toIndex };
    }

    isWhiteTextSlide(slide) {
        if (!slide) return false;

        const attr = slide.getAttribute('data-white-text');
        if (attr !== null && attr !== undefined && String(attr).trim() !== '') {
            const s = String(attr).trim().toLowerCase();
            return ['1', 'true', 'yes', 'on'].includes(s);
        }

        return slide.classList.contains('whitecolor');
    }

    syncContainerTheme(index = this.currentSlide) {
        const slide = this.realSlides[index];
        const shouldUseWhite = this.isWhiteTextSlide(slide);
        this.container.classList.toggle('whitecolor', shouldUseWhite);
    }

    getTransitionDirection(index) {
        if (this.currentSlide < 0 || this.realSlides.length <= 1) {
            return 'next';
        }

        if (index === (this.currentSlide + 1) % this.realSlides.length) {
            return 'next';
        }

        if (index === (this.currentSlide - 1 + this.realSlides.length) % this.realSlides.length) {
            return 'prev';
        }

        return index > this.currentSlide ? 'next' : 'prev';
    }

    setupSlideMode() {
        if (!['slide', 'list'].includes(this.options.transition)) {
            return;
        }

        if (!this.track) {
            const container = this.container.querySelector('.slider-container') || this.container;
            const track = document.createElement('div');
            track.className = 'slider-track';
            const slides = Array.from(container.querySelectorAll(':scope > .slide'));
            slides.forEach(s => track.appendChild(s));
            container.appendChild(track);
            this.track = track;
        }

        Array.from(this.track.querySelectorAll(':scope > .slide[data-clone="1"]')).forEach((el) => {
            try {
                el.remove();
            } catch (e) {
            }
        });

        this.realSlides = Array.from(this.track.querySelectorAll(':scope > .slide')).filter(s => !s.hasAttribute('data-clone'));
        this.slides = this.realSlides;

        this.listConfig = null;

        if (this.options.transition === 'slide') {
            if (this.realSlides.length > 1) {
                const first = this.realSlides[0];
                const last = this.realSlides[this.realSlides.length - 1];
                const cloneFirst = first.cloneNode(true);
                const cloneLast = last.cloneNode(true);
                cloneFirst.setAttribute('data-clone', '1');
                cloneLast.setAttribute('data-clone', '1');
                cloneFirst.setAttribute('aria-hidden', 'true');
                cloneLast.setAttribute('aria-hidden', 'true');
                this.track.insertBefore(cloneLast, this.track.firstChild);
                this.track.appendChild(cloneFirst);
                this.slides = Array.from(this.track.querySelectorAll(':scope > .slide'));
            }

            this.currentTrackIndex = this.realSlides.length > 1 ? 1 : 0;
            this.jumpToTrackIndex(this.currentTrackIndex);
        } else if (this.options.transition === 'list') {
            const total = this.realSlides.length;
            const perView = Math.max(1, parseInt(this.options.listPerView, 10) || 1);
            const step = Math.max(1, parseInt(this.options.listStep, 10) || 1);
            const gap = Math.max(0, Number(this.options.listGap) || 0);
            const loop = Boolean(this.options.listLoop);
            const cloneCount = loop && total > 1 ? Math.min(total, Math.max(perView + step - 1, perView, step)) : 0;

            if (this.track) {
                this.track.style.gap = gap ? `${gap}px` : '';
            }

            if (cloneCount > 0) {
                const firstClonesSrc = this.realSlides.slice(0, cloneCount);
                const lastClonesSrc = this.realSlides.slice(total - cloneCount);

                const prependClones = lastClonesSrc.map((slide) => {
                    const clone = slide.cloneNode(true);
                    clone.setAttribute('data-clone', '1');
                    clone.setAttribute('aria-hidden', 'true');
                    return clone;
                });

                for (let i = prependClones.length - 1; i >= 0; i--) {
                    this.track.insertBefore(prependClones[i], this.track.firstChild);
                }

                firstClonesSrc.forEach((slide) => {
                    const clone = slide.cloneNode(true);
                    clone.setAttribute('data-clone', '1');
                    clone.setAttribute('aria-hidden', 'true');
                    this.track.appendChild(clone);
                });

                this.slides = Array.from(this.track.querySelectorAll(':scope > .slide'));
            }

            this.listConfig = { perView, step, gap, loop, cloneCount };
            this.currentTrackIndex = cloneCount > 0 ? cloneCount : 0;
            this.jumpToTrackIndex(this.currentTrackIndex);
        }

        this.track.addEventListener('transitionend', (e) => {
            if (e.target !== this.track || e.propertyName !== 'transform') {
                return;
            }

            if (this.pendingLoopJump !== null) {
                const jumpIndex = this.pendingLoopJump;
                this.pendingLoopJump = null;
                this.jumpToTrackIndex(jumpIndex);
            }

            this.isTransitioning = false;
        });

        this.container.querySelectorAll('img').forEach(img => {
            img.setAttribute('draggable', 'false');
        });
    }

    isCanvasSupported() {
        if (typeof document === 'undefined') return false;
        const canvas = document.createElement('canvas');
        return !!(canvas.getContext && canvas.getContext('2d'));
    }

    getCanvasContainer() {
        return this.container.querySelector('.slider-container') || this.container;
    }

    setupCanvasMode() {
        if (this.options.transition !== 'canvas') {
            return;
        }

        const host = this.getCanvasContainer();
        if (!host) return;

        let canvas = this.container.querySelector('canvas.hero-slider-canvas');
        if (!canvas) {
            canvas = document.createElement('canvas');
            canvas.className = 'hero-slider-canvas';
            host.insertBefore(canvas, host.firstChild || null);
        }

        this.canvasEl = canvas;
        this.canvasCtx = canvas.getContext('2d', { alpha: false });

        let webglCanvas = this.container.querySelector('canvas.hero-slider-webgl');
        if (!webglCanvas) {
            webglCanvas = document.createElement('canvas');
            webglCanvas.className = 'hero-slider-webgl';
            webglCanvas.style.display = 'none';
            host.insertBefore(webglCanvas, canvas.nextSibling || null);
        }
        this.webglCanvasEl = webglCanvas;

        this.resizeCanvas();

        this.container.querySelectorAll('.slide img').forEach(img => {
            if (img.complete) return;
            const onDone = () => this.redrawCanvas();
            img.addEventListener('load', onDone, { once: true });
            img.addEventListener('error', onDone, { once: true });
        });
    }

    isWebGLSupported() {
        if (this.webglSupported !== null) return this.webglSupported;
        if (typeof document === 'undefined') {
            this.webglSupported = false;
            return false;
        }
        const canvas = document.createElement('canvas');
        let gl = null;
        try {
            gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
        } catch (e) {
            gl = null;
        }
        this.webglSupported = !!gl;
        return this.webglSupported;
    }

    ensureWebGL() {
        if (!this.webglCanvasEl) return false;
        if (this.webglCtx && this.webglProgram && this.webglLocations && this.webglBuffer) return true;

        const gl = this.webglCanvasEl.getContext('webgl', { alpha: false, antialias: false, premultipliedAlpha: false }) ||
            this.webglCanvasEl.getContext('experimental-webgl');
        if (!gl) return false;

        const vsSource = `
            attribute vec2 aPosition;
            varying vec2 vUv;
            void main() {
                vUv = (aPosition + 1.0) * 0.5;
                gl_Position = vec4(aPosition, 0.0, 1.0);
            }
        `;

        const fsSource = `
            precision mediump float;
            varying vec2 vUv;
            uniform sampler2D uCurrent;
            uniform sampler2D uNext;
            uniform float uDispFactor;
            uniform float uIntensity;
            uniform float uSign;
            uniform vec2 uCurScale;
            uniform vec2 uCurOffset;
            uniform vec2 uNextScale;
            uniform vec2 uNextOffset;

            vec2 coverUv(vec2 uv, vec2 scale, vec2 offset) {
                return offset + uv * scale;
            }
            void main() {
                vec2 uv = vUv;
                vec2 uvCurBase = coverUv(uv, uCurScale, uCurOffset);
                vec2 uvNextBase = coverUv(uv, uNextScale, uNextOffset);

                vec4 orig1 = texture2D(uCurrent, uvCurBase);
                vec4 orig2 = texture2D(uNext, uvNextBase);
                float disp1 = (orig2.r * 2.0 - 1.0) * uIntensity * uSign;
                float disp2 = (orig1.r * 2.0 - 1.0) * uIntensity * uSign;
                vec4 current = texture2D(uCurrent, uvCurBase + vec2(0.0, uDispFactor * disp1));
                vec4 next = texture2D(uNext, uvNextBase + vec2(0.0, -(1.0 - uDispFactor) * disp2));
                gl_FragColor = mix(current, next, uDispFactor);
            }
        `;

        const compile = (type, source) => {
            const shader = gl.createShader(type);
            if (!shader) return null;
            gl.shaderSource(shader, source);
            gl.compileShader(shader);
            if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
                gl.deleteShader(shader);
                return null;
            }
            return shader;
        };

        const vs = compile(gl.VERTEX_SHADER, vsSource);
        const fs = compile(gl.FRAGMENT_SHADER, fsSource);
        if (!vs || !fs) return false;

        const program = gl.createProgram();
        if (!program) return false;
        gl.attachShader(program, vs);
        gl.attachShader(program, fs);
        gl.linkProgram(program);
        gl.deleteShader(vs);
        gl.deleteShader(fs);
        if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
            gl.deleteProgram(program);
            return false;
        }

        const buffer = gl.createBuffer();
        if (!buffer) {
            gl.deleteProgram(program);
            return false;
        }
        gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
        gl.bufferData(
            gl.ARRAY_BUFFER,
            new Float32Array([
                -1, -1,
                1, -1,
                -1, 1,
                -1, 1,
                1, -1,
                1, 1
            ]),
            gl.STATIC_DRAW
        );

        const aPosition = gl.getAttribLocation(program, 'aPosition');
        const uCurrent = gl.getUniformLocation(program, 'uCurrent');
        const uNext = gl.getUniformLocation(program, 'uNext');
        const uDispFactor = gl.getUniformLocation(program, 'uDispFactor');
        const uIntensity = gl.getUniformLocation(program, 'uIntensity');
        const uSign = gl.getUniformLocation(program, 'uSign');
        const uCurScale = gl.getUniformLocation(program, 'uCurScale');
        const uCurOffset = gl.getUniformLocation(program, 'uCurOffset');
        const uNextScale = gl.getUniformLocation(program, 'uNextScale');
        const uNextOffset = gl.getUniformLocation(program, 'uNextOffset');
        if (aPosition < 0 || !uCurrent || !uNext || !uDispFactor || !uIntensity || !uSign || !uCurScale || !uCurOffset || !uNextScale || !uNextOffset) {
            gl.deleteBuffer(buffer);
            gl.deleteProgram(program);
            return false;
        }

        const makeTex = () => {
            const tex = gl.createTexture();
            if (!tex) return null;
            gl.bindTexture(gl.TEXTURE_2D, tex);
            gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
            gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
            gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
            gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
            return tex;
        };

        const tex0 = makeTex();
        const tex1 = makeTex();
        if (!tex0 || !tex1) {
            if (tex0) gl.deleteTexture(tex0);
            if (tex1) gl.deleteTexture(tex1);
            gl.deleteBuffer(buffer);
            gl.deleteProgram(program);
            return false;
        }

        this.webglCtx = gl;
        this.webglProgram = program;
        this.webglBuffer = buffer;
        this.webglLocations = { aPosition, uCurrent, uNext, uDispFactor, uIntensity, uSign, uCurScale, uCurOffset, uNextScale, uNextOffset };
        this.webglTextures = { current: tex0, next: tex1 };
        return true;
    }

    getCoverUvTransform(img, containerWidth, containerHeight) {
        const iw = Number(img && (img.naturalWidth || img.width)) || 1;
        const ih = Number(img && (img.naturalHeight || img.height)) || 1;
        const cw = Math.max(1, Number(containerWidth) || 1);
        const ch = Math.max(1, Number(containerHeight) || 1);

        const imageAspect = iw / ih;
        const containerAspect = cw / ch;

        let scaleX = 1;
        let scaleY = 1;
        let offsetX = 0;
        let offsetY = 0;

        if (imageAspect > containerAspect) {
            scaleX = containerAspect / imageAspect;
            offsetX = (1 - scaleX) / 2;
        } else if (imageAspect < containerAspect) {
            scaleY = imageAspect / containerAspect;
            offsetY = (1 - scaleY) / 2;
        }

        return { scaleX, scaleY, offsetX, offsetY };
    }

    resizeWebGL() {
        if (!this.webglCanvasEl || !this.webglCtx) return;

        const host = this.getCanvasContainer();
        const rect = host.getBoundingClientRect();
        const cssWidth = Math.max(1, Math.round(rect.width));
        const cssHeight = Math.max(1, Math.round(rect.height));
        const ratio = this.getEffectiveCanvasRatio();

        this.webglCanvasEl.style.width = `${cssWidth}px`;
        this.webglCanvasEl.style.height = `${cssHeight}px`;

        const targetWidth = Math.max(1, Math.round(cssWidth * ratio));
        const targetHeight = Math.max(1, Math.round(cssHeight * ratio));
        if (this.webglCanvasEl.width !== targetWidth) this.webglCanvasEl.width = targetWidth;
        if (this.webglCanvasEl.height !== targetHeight) this.webglCanvasEl.height = targetHeight;

        this.webglCtx.viewport(0, 0, targetWidth, targetHeight);
    }

    hideWebGLCanvas() {
        if (!this.webglCanvasEl) return;
        this.webglCanvasEl.style.display = 'none';
        this.webglCanvasEl.style.visibility = '';
        this.webglCanvasEl.style.transform = '';
        this.webglCanvasEl.style.opacity = '';
    }

    isImageReadyForTexture(img) {
        if (!img) return false;
        if (!img.complete) return false;
        const w = Number(img.naturalWidth || 0);
        const h = Number(img.naturalHeight || 0);
        return w > 0 && h > 0;
    }

    uploadWebGLTexture(slot, imgEl) {
        if (!this.webglCtx || !this.webglTextures) return false;
        const gl = this.webglCtx;
        const tex = slot === 'next' ? this.webglTextures.next : this.webglTextures.current;
        if (!tex) return false;

        const img = imgEl;
        if (!this.isImageReadyForTexture(img)) return false;

        try {
            gl.bindTexture(gl.TEXTURE_2D, tex);
            gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, 1);
            gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, img);
        } catch (e) {
            return false;
        }
        return true;
    }

    renderWebGLDisplace(fromIndex, toIndex, dispFactor, direction, intensity = 0.3) {
        if (!this.webglCtx || !this.webglProgram || !this.webglLocations || !this.webglBuffer || !this.webglTextures) return false;

        const gl = this.webglCtx;
        const loc = this.webglLocations;

        gl.disable(gl.DEPTH_TEST);
        gl.disable(gl.CULL_FACE);
        gl.disable(gl.BLEND);
        gl.clearColor(0, 0, 0, 1);
        gl.clear(gl.COLOR_BUFFER_BIT);

        gl.useProgram(this.webglProgram);
        gl.bindBuffer(gl.ARRAY_BUFFER, this.webglBuffer);
        gl.enableVertexAttribArray(loc.aPosition);
        gl.vertexAttribPointer(loc.aPosition, 2, gl.FLOAT, false, 0, 0);

        const currentImg = this.getSlideImageEl(fromIndex);
        const nextImg = this.getSlideImageEl(toIndex);
        if (!this.isImageReadyForTexture(currentImg) || !this.isImageReadyForTexture(nextImg)) return false;

        if (this.webglActiveImages.current !== currentImg) {
            if (!this.uploadWebGLTexture('current', currentImg)) return false;
            this.webglActiveImages.current = currentImg;
        }
        if (this.webglActiveImages.next !== nextImg) {
            if (!this.uploadWebGLTexture('next', nextImg)) return false;
            this.webglActiveImages.next = nextImg;
        }

        gl.activeTexture(gl.TEXTURE0);
        gl.bindTexture(gl.TEXTURE_2D, this.webglTextures.current);
        gl.uniform1i(loc.uCurrent, 0);

        gl.activeTexture(gl.TEXTURE1);
        gl.bindTexture(gl.TEXTURE_2D, this.webglTextures.next);
        gl.uniform1i(loc.uNext, 1);

        gl.uniform1f(loc.uDispFactor, Math.max(0, Math.min(1, dispFactor)));
        gl.uniform1f(loc.uIntensity, Math.max(0, Math.min(1, Number(intensity) || 0)));
        gl.uniform1f(loc.uSign, direction === 'prev' ? -1 : 1);
        const { cssWidth, cssHeight } = this.canvasMetrics;
        const curT = this.getCoverUvTransform(currentImg, cssWidth, cssHeight);
        const nextT = this.getCoverUvTransform(nextImg, cssWidth, cssHeight);
        gl.uniform2f(loc.uCurScale, curT.scaleX, curT.scaleY);
        gl.uniform2f(loc.uCurOffset, curT.offsetX, curT.offsetY);
        gl.uniform2f(loc.uNextScale, nextT.scaleX, nextT.scaleY);
        gl.uniform2f(loc.uNextOffset, nextT.offsetX, nextT.offsetY);

        gl.drawArrays(gl.TRIANGLES, 0, 6);
        gl.flush();
        return true;
    }

    startWebGLDisplaceTransition(fromIndex, toIndex, direction, duration) {
        if (!this.isWebGLSupported()) return false;
        if (!this.ensureWebGL()) return false;

        if (!this.webglCanvasEl) return false;
        this.cancelCanvasAnimation();
        this.hideWebGLCanvas();
        this.resizeWebGL();

        const start = (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
        const dur = Math.max(120, Number(duration) || 700);

        const step = (now) => {
            if (!this.canvasTransitionState) return;
            const tNow = typeof now === 'number' ? now : ((typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now());
            const p = Math.max(0, Math.min(1, (tNow - start) / dur));
            const eased = this.easeOutCubic(p);

            this.webglCanvasEl.style.display = '';
            const ok = this.renderWebGLDisplace(fromIndex, toIndex, eased, direction);
            if (!ok) {
                this.hideWebGLCanvas();
                this.canvasTransitionState = null;
                this.isTransitioning = false;
                return;
            }

            if (p < 1) {
                this.canvasRafId = requestAnimationFrame(step);
                return;
            }

            this.canvasRafId = null;
            this.hideWebGLCanvas();
            this.canvasTransitionState = null;
            this.isTransitioning = false;
            this.redrawCanvas();
        };

        this.canvasTransitionState = { fromIndex, toIndex, direction };
        this.canvasRafId = requestAnimationFrame(step);
        return true;
    }

    disableNativeDrag() {
        if (this.nativeDragStartHandler) return;
        this.container.querySelectorAll('img').forEach(img => {
            img.setAttribute('draggable', 'false');
        });
        this.nativeDragStartHandler = (e) => {
            e.preventDefault();
        };
        this.container.addEventListener('dragstart', this.nativeDragStartHandler);
    }

    getEffectiveCanvasRatio() {
        const dpr = typeof window !== 'undefined' ? (window.devicePixelRatio || 1) : 1;
        const scale = Number(this.options.canvasScale) || 1;
        const max = Number(this.options.canvasMaxPixelRatio) || 2;
        const ratio = dpr * scale;
        return Math.max(1, Math.min(max, ratio));
    }

    resizeCanvas() {
        if (!this.canvasEl || !this.canvasCtx) return;

        const host = this.getCanvasContainer();
        const rect = host.getBoundingClientRect();
        const cssWidth = Math.max(1, Math.round(rect.width));
        const cssHeight = Math.max(1, Math.round(rect.height));
        const ratio = this.getEffectiveCanvasRatio();

        this.canvasEl.style.width = `${cssWidth}px`;
        this.canvasEl.style.height = `${cssHeight}px`;

        const targetWidth = Math.max(1, Math.round(cssWidth * ratio));
        const targetHeight = Math.max(1, Math.round(cssHeight * ratio));
        if (this.canvasEl.width !== targetWidth) this.canvasEl.width = targetWidth;
        if (this.canvasEl.height !== targetHeight) this.canvasEl.height = targetHeight;

        this.canvasMetrics = { cssWidth, cssHeight, ratio };
        this.canvasCtx.setTransform(ratio, 0, 0, ratio, 0, 0);
    }

    getSlideImageEl(index) {
        const preloaded = this.preloadedSlideImages && this.preloadedSlideImages[index];
        if (this.isImageReadyForTexture(preloaded)) {
            return preloaded;
        }
        const slide = this.realSlides[index];
        if (!slide) return null;
        const img = slide.querySelector('img');
        if (img) return img;
        return null;
    }

    clearCanvas() {
        if (!this.canvasCtx) return;
        const { cssWidth, cssHeight } = this.canvasMetrics;
        this.canvasCtx.clearRect(0, 0, cssWidth, cssHeight);
    }

    drawImageCover(ctx, img, dx, dy, dWidth, dHeight) {
        const sWidth = img.naturalWidth || img.width;
        const sHeight = img.naturalHeight || img.height;
        if (!sWidth || !sHeight) return;

        const sRatio = sWidth / sHeight;
        const dRatio = dWidth / dHeight;

        let sx = 0;
        let sy = 0;
        let sw = sWidth;
        let sh = sHeight;

        if (sRatio > dRatio) {
            sh = sHeight;
            sw = Math.round(sh * dRatio);
            sx = Math.round((sWidth - sw) / 2);
            sy = 0;
        } else {
            sw = sWidth;
            sh = Math.round(sw / dRatio);
            sx = 0;
            sy = Math.round((sHeight - sh) / 2);
        }

        ctx.drawImage(img, sx, sy, sw, sh, dx, dy, dWidth, dHeight);
    }

    drawSlideToCanvas(index, opts = {}) {
        if (!this.canvasCtx) return;
        const img = this.getSlideImageEl(index);
        if (!img) return;
        if (!this.isImageReadyForTexture(img)) return;

        const { cssWidth, cssHeight } = this.canvasMetrics;
        const alpha = typeof opts.alpha === 'number' ? opts.alpha : 1;
        const x = typeof opts.x === 'number' ? opts.x : 0;
        const y = typeof opts.y === 'number' ? opts.y : 0;
        const scale = typeof opts.scale === 'number' ? opts.scale : 1;

        const dw = cssWidth * scale;
        const dh = cssHeight * scale;
        const dx = x + (cssWidth - dw) / 2;
        const dy = y + (cssHeight - dh) / 2;

        this.canvasCtx.save();
        this.canvasCtx.globalAlpha = Math.max(0, Math.min(1, alpha));
        this.drawImageCover(this.canvasCtx, img, dx, dy, dw, dh);
        this.canvasCtx.restore();
    }

    ensureEffectBuffer(cssWidth, cssHeight) {
        if (!this.effectBufferCanvas) {
            this.effectBufferCanvas = document.createElement('canvas');
            this.effectBufferCtx = this.effectBufferCanvas.getContext('2d', { alpha: false });
        }
        if (!this.effectBufferCtx) return null;

        const w = Math.max(1, Math.round(cssWidth));
        const h = Math.max(1, Math.round(cssHeight));
        if (this.effectBufferCanvas.width !== w) this.effectBufferCanvas.width = w;
        if (this.effectBufferCanvas.height !== h) this.effectBufferCanvas.height = h;
        return this.effectBufferCtx;
    }

    renderSlideToEffectBuffer(index) {
        const { cssWidth, cssHeight } = this.canvasMetrics;
        const ctx = this.ensureEffectBuffer(cssWidth, cssHeight);
        if (!ctx) return null;

        ctx.clearRect(0, 0, cssWidth, cssHeight);
        const img = this.getSlideImageEl(index);
        if (!img || !img.complete) return null;
        this.drawImageCover(ctx, img, 0, 0, cssWidth, cssHeight);
        return this.effectBufferCanvas;
    }

    ensurePixelBuffer(w, h) {
        if (!this.pixelBufferCanvas) {
            this.pixelBufferCanvas = document.createElement('canvas');
            this.pixelBufferCtx = this.pixelBufferCanvas.getContext('2d', { alpha: false });
        }
        if (!this.pixelBufferCtx) return null;

        const width = Math.max(1, Math.round(w));
        const height = Math.max(1, Math.round(h));
        if (this.pixelBufferCanvas.width !== width) this.pixelBufferCanvas.width = width;
        if (this.pixelBufferCanvas.height !== height) this.pixelBufferCanvas.height = height;
        return this.pixelBufferCtx;
    }

    clamp01(v) {
        return Math.max(0, Math.min(1, v));
    }

    lerp(a, b, t) {
        return a + (b - a) * t;
    }

    easeOutCubic(t) {
        const x = this.clamp01(t);
        return 1 - Math.pow(1 - x, 3);
    }

    drawSlidePixelated(index, pixelSize, alpha, x = 0) {
        if (!this.canvasCtx) return;
        const img = this.getSlideImageEl(index);
        if (!this.isImageReadyForTexture(img)) return;

        const { cssWidth, cssHeight } = this.canvasMetrics;
        const size = Math.max(1, Math.round(pixelSize));
        const w = Math.max(1, Math.round(cssWidth / size));
        const h = Math.max(1, Math.round(cssHeight / size));
        const ctx = this.ensurePixelBuffer(w, h);
        if (!ctx) return;

        ctx.clearRect(0, 0, w, h);
        this.drawImageCover(ctx, img, 0, 0, w, h);

        const prevSmoothing = this.canvasCtx.imageSmoothingEnabled;
        this.canvasCtx.save();
        this.canvasCtx.globalAlpha = Math.max(0, Math.min(1, alpha));
        this.canvasCtx.imageSmoothingEnabled = false;
        this.canvasCtx.drawImage(this.pixelBufferCanvas, 0, 0, w, h, x, 0, cssWidth, cssHeight);
        this.canvasCtx.restore();
        this.canvasCtx.imageSmoothingEnabled = prevSmoothing;
    }

    rand01(seed) {
        const s = Math.sin(seed) * 10000;
        return s - Math.floor(s);
    }

    cancelCanvasAnimation() {
        if (this.canvasRafId) {
            cancelAnimationFrame(this.canvasRafId);
            this.canvasRafId = null;
        }
        this.canvasTransitionState = null;
    }

    redrawCanvas() {
        if (this.options.transition !== 'canvas') return;
        if (!this.canvasEl || !this.canvasCtx) return;
        if (this.isTransitioning) return;
        this.hideWebGLCanvas();
        this.resizeCanvas();
        this.clearCanvas();
        if (this.currentSlide >= 0) {
            this.drawSlideToCanvas(this.currentSlide);
        }
    }

    getContainerWidth() {
        const w = this.container.clientWidth;
        return w > 0 ? w : this.container.getBoundingClientRect().width;
    }

    getTrackUnitWidth() {
        if (this.options.transition === 'list') {
            return this.getListUnitWidth();
        }
        return this.getContainerWidth();
    }

    getListGapWidth() {
        const configured = Number(this.options.listGap);
        if (Number.isFinite(configured) && configured >= 0) return configured;
        if (!this.track) return 0;
        const style = window.getComputedStyle(this.track);
        const gap = style.columnGap || style.gap || '0px';
        const n = parseFloat(gap);
        return Number.isFinite(n) ? n : 0;
    }

    getListItemWidth() {
        const perView = this.listConfig && Number.isFinite(this.listConfig.perView) ? this.listConfig.perView : 1;
        const slide = this.realSlides && this.realSlides.length ? this.realSlides[0] : null;
        if (slide) {
            const rect = slide.getBoundingClientRect();
            if (rect.width > 0) return rect.width;
        }
        const containerWidth = this.getContainerWidth();
        return containerWidth > 0 ? (containerWidth / Math.max(1, perView)) : 0;
    }

    getListUnitWidth() {
        const item = this.getListItemWidth();
        const gap = this.getListGapWidth();
        const unit = item + gap;
        return unit > 0 ? unit : 1;
    }

    getMaxListDragUnits(direction) {
        const total = this.realSlides ? this.realSlides.length : 0;
        if (total <= 1) return 0;

        const config = this.listConfig || { perView: 1, loop: true, cloneCount: 0 };
        const perView = Number.isFinite(config.perView) ? config.perView : 1;
        const loop = Boolean(config.loop);
        const cloneCount = Number.isFinite(config.cloneCount) ? config.cloneCount : 0;

        if (loop) {
            return Math.max(1, Math.min(total, cloneCount || 1));
        }

        const maxStart = Math.max(0, total - Math.max(1, perView));
        const current = Math.max(0, Math.min(maxStart, this.currentSlide));
        if (direction === 'prev') return current;
        return Math.max(0, maxStart - current);
    }

    getListStepValue() {
        const attr = this.container
            ? (this.container.getAttribute('data-step') || this.container.getAttribute('data-list-step'))
            : null;
        const attrN = parseInt(attr, 10);
        if (Number.isFinite(attrN) && attrN > 0) return attrN;

        const configStep = this.listConfig && Number.isFinite(this.listConfig.step) ? this.listConfig.step : null;
        if (Number.isFinite(configStep) && configStep > 0) return configStep;

        const opt = parseInt(this.options.listStep, 10);
        return Number.isFinite(opt) && opt > 0 ? opt : 1;
    }

    getTrackTranslateX() {
        if (!this.track) return 0;
        const style = window.getComputedStyle(this.track);
        const transform = style.transform || '';
        if (!transform || transform === 'none') return 0;
        const match = transform.match(/matrix(3d)?\((.+)\)/);
        if (!match) return 0;
        const parts = match[2].split(',').map(s => parseFloat(s.trim()));
        if (match[1] === '3d') {
            return Number.isFinite(parts[12]) ? parts[12] : 0;
        }
        return Number.isFinite(parts[4]) ? parts[4] : 0;
    }

    animateToTrackIndex(trackIndex) {
        if (!this.track) return;
        const sign = this.isRTL() ? 1 : -1;
        const x = sign * trackIndex * this.getTrackUnitWidth();
        this.track.style.transition = '';
        this.track.style.transform = `translate3d(${x}px, 0, 0)`;
        this.currentTrackIndex = trackIndex;
    }

    jumpToTrackIndex(trackIndex) {
        if (!this.track) return;
        const sign = this.isRTL() ? 1 : -1;
        const x = sign * trackIndex * this.getTrackUnitWidth();
        this.track.style.transition = 'none';
        this.track.style.transform = `translate3d(${x}px, 0, 0)`;
        this.currentTrackIndex = trackIndex;
        void this.track.offsetWidth;
        this.track.style.transition = '';
    }
    
    showSlide(index, force = false, direction = this.getTransitionDirection(index)) {
        if (this.options.transition === 'canvas') {
            this.showSlideCanvas(index, force, direction);
            return;
        }

        if (this.isTransitioning || (!force && index === this.currentSlide)) {
            return;
        }

        const prevSlide = this.currentSlide;
        const totalSlides = this.realSlides.length;
        let nextIndex = index;
        if (this.options.transition === 'list' && totalSlides > 0) {
            const perView = this.listConfig && Number.isFinite(this.listConfig.perView) ? this.listConfig.perView : 1;
            const loop = this.listConfig ? Boolean(this.listConfig.loop) : true;
            if (!loop) {
                const maxStart = Math.max(0, totalSlides - Math.max(1, perView));
                nextIndex = Math.max(0, Math.min(maxStart, nextIndex));
            } else {
                nextIndex = ((nextIndex % totalSlides) + totalSlides) % totalSlides;
            }
        }

        this.currentSlide = nextIndex;

        this.defocusIfHidingFocusedSlide(nextIndex);
        const isList = this.options.transition === 'list';
        let listVisibleSet = null;
        if (isList && totalSlides > 0) {
            const perView = this.listConfig && Number.isFinite(this.listConfig.perView) ? this.listConfig.perView : 1;
            const count = Math.min(totalSlides, Math.max(1, perView));
            const loop = this.listConfig ? Boolean(this.listConfig.loop) : true;
            listVisibleSet = new Set();
            for (let k = 0; k < count; k++) {
                const idx = loop ? ((nextIndex + k) % totalSlides) : (nextIndex + k);
                if (idx >= 0 && idx < totalSlides) {
                    listVisibleSet.add(idx);
                }
            }
        }

        this.realSlides.forEach((slide, i) => {
            const active = listVisibleSet ? listVisibleSet.has(i) : (i === nextIndex);
            slide.classList.toggle('active', active);
            slide.setAttribute('aria-hidden', active ? 'false' : 'true');
        });
        
        let activeIndicatorIndex = isList ? 0 : nextIndex;
        if (isList) {
            const step = this.getListStepValue();
            const loop = this.listConfig ? Boolean(this.listConfig.loop) : true;
            activeIndicatorIndex = loop
                ? Math.max(0, Math.min(this.indicators.length - 1, Math.floor(nextIndex / step)))
                : Math.max(0, Math.min(this.indicators.length - 1, Math.floor((nextIndex + step - 1) / step)));
        }

        this.indicators.forEach((indicator, i) => {
            indicator.classList.toggle('active', i === activeIndicatorIndex);
            indicator.setAttribute('aria-selected', i === activeIndicatorIndex ? 'true' : 'false');
        });

        this.syncContainerTheme(nextIndex);
        
        // 触发自定义事件
        this.container.dispatchEvent(new CustomEvent('slideChange', {
            detail: {
                currentSlide: nextIndex,
                previousSlide: prevSlide,
                totalSlides: totalSlides
            }
        }));

        this.updatePagination(nextIndex, force, direction);

        if (this.options.transition === 'slide' && this.track && totalSlides > 0) {
            if (force) {
                this.pendingLoopJump = null;
                this.jumpToTrackIndex(totalSlides > 1 ? index + 1 : index);
                this.isTransitioning = false;
                return;
            }

            this.isTransitioning = true;
            this.pendingLoopJump = null;

            let targetTrackIndex = totalSlides > 1 ? index + 1 : index;
            if (totalSlides > 1 && prevSlide >= 0) {
                if (direction === 'next' && prevSlide === totalSlides - 1 && index === 0) {
                    targetTrackIndex = totalSlides + 1;
                    this.pendingLoopJump = 1;
                } else if (direction === 'prev' && prevSlide === 0 && index === totalSlides - 1) {
                    targetTrackIndex = 0;
                    this.pendingLoopJump = totalSlides;
                }
            }

            const targetX = -targetTrackIndex * this.getTrackUnitWidth();
            const currentX = this.getTrackTranslateX();
            if (Math.abs(currentX - targetX) < 0.75) {
                const jumpIndex = this.pendingLoopJump;
                this.pendingLoopJump = null;
                this.jumpToTrackIndex(targetTrackIndex);
                if (jumpIndex !== null) {
                    this.jumpToTrackIndex(jumpIndex);
                }
                this.isTransitioning = false;
                return;
            }

            this.animateToTrackIndex(targetTrackIndex);
            return;
        }

        if (this.options.transition === 'list' && this.track && totalSlides > 0) {
            const config = this.listConfig || { perView: 1, step: 1, gap: 0, loop: true, cloneCount: 0 };
            const cloneCount = Number.isFinite(config.cloneCount) ? config.cloneCount : 0;
            const loop = Boolean(config.loop);
            const perView = Number.isFinite(config.perView) ? config.perView : 1;
            const maxStart = Math.max(0, totalSlides - Math.max(1, perView));
            const clamped = loop ? nextIndex : Math.max(0, Math.min(maxStart, nextIndex));

            if (force || totalSlides <= 1 || !loop || cloneCount <= 0) {
                this.pendingLoopJump = null;
                const target = cloneCount > 0 ? (cloneCount + clamped) : clamped;
                this.jumpToTrackIndex(target);
                this.isTransitioning = false;
                return;
            }

            this.isTransitioning = true;
            this.pendingLoopJump = null;

            let targetTrackIndex = cloneCount + clamped;
            if (prevSlide >= 0) {
                if (direction === 'next' && clamped < prevSlide) {
                    targetTrackIndex = cloneCount + totalSlides + clamped;
                    this.pendingLoopJump = cloneCount + clamped;
                } else if (direction === 'prev' && clamped > prevSlide) {
                    targetTrackIndex = cloneCount + clamped - totalSlides;
                    this.pendingLoopJump = cloneCount + clamped;
                }
            }

            const targetX = -targetTrackIndex * this.getTrackUnitWidth();
            const currentX = this.getTrackTranslateX();
            if (Math.abs(currentX - targetX) < 0.75) {
                const jumpIndex = this.pendingLoopJump;
                this.pendingLoopJump = null;
                this.jumpToTrackIndex(targetTrackIndex);
                if (jumpIndex !== null) {
                    this.jumpToTrackIndex(jumpIndex);
                }
                this.isTransitioning = false;
                return;
            }

            this.animateToTrackIndex(targetTrackIndex);
            return;
        }

        this.isTransitioning = true;
        setTimeout(() => {
            this.isTransitioning = false;
        }, 600);
    }

    showSlideCanvas(index, force = false, direction = this.getTransitionDirection(index)) {
        if (this.isTransitioning || (!force && index === this.currentSlide)) {
            return;
        }

        if (!this.canvasEl || !this.canvasCtx) {
            this.setupCanvasMode();
        }

        this.resetCanvasContentTransforms();

        const prevSlide = this.currentSlide;
        const totalSlides = this.realSlides.length;
        this.currentSlide = index;

        this.defocusIfHidingFocusedSlide(index);
        this.realSlides.forEach((slide, i) => {
            slide.classList.toggle('active', i === index);
            slide.setAttribute('aria-hidden', i !== index ? 'true' : 'false');
        });

        this.indicators.forEach((indicator, i) => {
            indicator.classList.toggle('active', i === index);
            indicator.setAttribute('aria-selected', i === index ? 'true' : 'false');
        });

        this.syncContainerTheme(index);

        this.container.dispatchEvent(new CustomEvent('slideChange', {
            detail: {
                currentSlide: index,
                previousSlide: prevSlide,
                totalSlides: totalSlides
            }
        }));

        this.updatePagination(index, force, direction);

        if (!this.canvasEl || !this.canvasCtx) {
            this.isTransitioning = true;
            setTimeout(() => {
                this.isTransitioning = false;
            }, 600);
            return;
        }

        this.resizeCanvas();

        if (force || prevSlide < 0 || totalSlides <= 1) {
            this.cancelCanvasAnimation();
            this.isTransitioning = false;
            this.hideWebGLCanvas();
            this.clearCanvas();
            this.drawSlideToCanvas(index);
            this.resetCanvasContentTransforms();
            return;
        }

        const effectRaw = String(this.options.canvasEffect || '').trim().toLowerCase();
        const effect = ['fade', 'slide', 'circle', 'pixelate', 'glitch', 'displace'].includes(effectRaw) ? effectRaw : 'fade';
        const duration = Math.max(120, Number(this.options.canvasDuration) || 700);

        this.cancelCanvasAnimation();
        this.isTransitioning = true;
        this.canvasTransitionState = null;

        if (effect === 'displace') {
            const ok = this.startWebGLDisplaceTransition(prevSlide, index, direction, duration);
            if (!ok) {
                this.canvasTransitionState = {
                    fromIndex: prevSlide,
                    toIndex: index,
                    direction,
                    effect: 'fade',
                    startTime: (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now(),
                    duration
                };
                const step = (now) => {
                    if (!this.canvasTransitionState) return;
                    const tNow = typeof now === 'number' ? now : ((typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now());
                    const elapsed = tNow - this.canvasTransitionState.startTime;
                    const p = Math.max(0, Math.min(1, elapsed / this.canvasTransitionState.duration));
                    const eased = this.easeOutCubic(p);

                    this.clearCanvas();
                    this.renderCanvasTransition(prevSlide, index, eased, 'fade', direction);

                    if (p < 1) {
                        this.canvasRafId = requestAnimationFrame(step);
                        return;
                    }

                    this.canvasRafId = null;
                    this.canvasTransitionState = null;
                    this.isTransitioning = false;
                    this.clearCanvas();
                    this.drawSlideToCanvas(index);
                    this.resetCanvasContentTransforms();
                };
                this.canvasRafId = requestAnimationFrame(step);
            }
            return;
        }

        this.canvasTransitionState = {
            fromIndex: prevSlide,
            toIndex: index,
            direction,
            effect,
            startTime: (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now(),
            duration
        };

        const step = (now) => {
            if (!this.canvasTransitionState) return;
            const tNow = typeof now === 'number' ? now : ((typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now());
            const elapsed = tNow - this.canvasTransitionState.startTime;
            const p = Math.max(0, Math.min(1, elapsed / this.canvasTransitionState.duration));
            const eased = this.easeOutCubic(p);

            this.clearCanvas();
            this.renderCanvasTransition(
                this.canvasTransitionState.fromIndex,
                this.canvasTransitionState.toIndex,
                eased,
                this.canvasTransitionState.effect,
                this.canvasTransitionState.direction
            );

            if (p < 1) {
                this.canvasRafId = requestAnimationFrame(step);
                return;
            }

            this.canvasRafId = null;
            this.canvasTransitionState = null;
            this.isTransitioning = false;
            this.clearCanvas();
            this.drawSlideToCanvas(index);
            this.resetCanvasContentTransforms();
        };

        this.canvasRafId = requestAnimationFrame(step);
    }

    renderCanvasTransition(fromIndex, toIndex, progress, effect, direction) {
        const { cssWidth, cssHeight } = this.canvasMetrics;
        const p = this.clamp01(progress);

        if (effect === 'slide') {
            const sign = direction === 'prev' ? -1 : 1;
            const fromX = -sign * p * cssWidth;
            const toX = sign * (1 - p) * cssWidth;
            this.drawSlideToCanvas(fromIndex, { x: fromX, alpha: 1 });
            this.drawSlideToCanvas(toIndex, { x: toX, alpha: 1 });
            this.applyCanvasContentTransformsForTransition(fromIndex, toIndex, fromX, toX);
            return;
        }

        if (effect === 'circle') {
            this.drawSlideToCanvas(fromIndex, { alpha: 1 });
            const cx = direction === 'prev' ? cssWidth * 0.25 : cssWidth * 0.75;
            const cy = cssHeight * 0.5;
            const r = Math.sqrt(cssWidth * cssWidth + cssHeight * cssHeight) * 0.6 * p;
            this.canvasCtx.save();
            this.canvasCtx.beginPath();
            this.canvasCtx.arc(cx, cy, Math.max(1, r), 0, Math.PI * 2);
            this.canvasCtx.clip();
            this.drawSlideToCanvas(toIndex, { alpha: 1 });
            this.canvasCtx.restore();
            return;
        }

        if (effect === 'pixelate') {
            const pixelSize = 1 + (1 - p) * 40;
            this.drawSlideToCanvas(fromIndex, { alpha: 1 - p });
            this.drawSlidePixelated(toIndex, pixelSize, 1);
            if (p > 0.92) {
                this.drawSlideToCanvas(toIndex, { alpha: (p - 0.92) / 0.08 });
            }
            return;
        }

        if (effect === 'glitch') {
            this.drawSlideToCanvas(fromIndex, { alpha: 1 - p * 0.6 });
            const buffer = this.renderSlideToEffectBuffer(toIndex);
            if (!buffer) {
                this.drawSlideToCanvas(toIndex, { alpha: p });
                return;
            }

            this.canvasCtx.save();
            this.canvasCtx.globalAlpha = p;
            this.canvasCtx.drawImage(buffer, 0, 0, cssWidth, cssHeight, 0, 0, cssWidth, cssHeight);
            this.canvasCtx.restore();

            const strength = (1 - Math.abs(0.5 - p) * 2) * 1.0;
            const baseSeed = Math.floor(p * 1000) + (direction === 'prev' ? 17 : 23);
            const slices = 8;

            for (let i = 0; i < slices; i++) {
                const r1 = this.rand01(baseSeed + i * 11.3);
                const r2 = this.rand01(baseSeed + i * 17.9);
                const r3 = this.rand01(baseSeed + i * 29.7);
                const y = Math.floor(r1 * cssHeight);
                const h = Math.max(6, Math.floor((r2 * 28 + 6) * (0.6 + strength)));
                const shift = (r3 * 2 - 1) * (40 + 80 * strength);
                const sy = Math.max(0, Math.min(cssHeight - 1, y));
                const sh = Math.max(1, Math.min(cssHeight - sy, h));

                this.canvasCtx.save();
                this.canvasCtx.globalAlpha = p * (0.22 + 0.35 * strength);
                this.canvasCtx.drawImage(buffer, 0, sy, cssWidth, sh, shift, sy, cssWidth, sh);
                this.canvasCtx.restore();
            }
            return;
        }

        this.drawSlideToCanvas(fromIndex, { alpha: 1 });
        this.drawSlideToCanvas(toIndex, { alpha: p });
    }
    
    nextSlide() {
        const total = this.realSlides.length;
        if (total <= 1) return;
        if (this.options.transition === 'list') {
            const step = this.getListStepValue();
            const perView = this.listConfig && Number.isFinite(this.listConfig.perView) ? this.listConfig.perView : 1;
            const loop = this.listConfig ? Boolean(this.listConfig.loop) : true;
            if (!loop) {
                const maxStart = Math.max(0, total - Math.max(1, perView));
                const nextIndex = Math.min(maxStart, this.currentSlide + step);
                if (nextIndex === this.currentSlide) {
                    this.stopAutoPlay();
                    return;
                }
                this.showSlide(nextIndex, false, 'next');
                return;
            }
            const nextIndex = (this.currentSlide + step) % total;
            this.showSlide(nextIndex, false, 'next');
            return;
        }
        const nextIndex = (this.currentSlide + 1) % total;
        this.showSlide(nextIndex, false, 'next');
    }
    
    prevSlide() {
        const total = this.realSlides.length;
        if (total <= 1) return;
        if (this.options.transition === 'list') {
            const step = this.getListStepValue();
            const loop = this.listConfig ? Boolean(this.listConfig.loop) : true;
            if (!loop) {
                const perView = this.listConfig && Number.isFinite(this.listConfig.perView) ? this.listConfig.perView : 1;
                const maxStart = Math.max(0, total - Math.max(1, perView));
                const current = Math.max(0, Math.min(maxStart, this.currentSlide));
                const prevIndex = Math.max(0, Math.floor((current - 1) / step) * step);
                this.showSlide(prevIndex, false, 'prev');
                return;
            }
            const prevIndex = (this.currentSlide - step + total) % total;
            this.showSlide(prevIndex, false, 'prev');
            return;
        }
        const prevIndex = (this.currentSlide - 1 + total) % total;
        this.showSlide(prevIndex, false, 'prev');
    }
    
    goToSlide(index) {
        if (index >= 0 && index < this.realSlides.length) {
            this.showSlide(index, false, this.getTransitionDirection(index));
        }
    }
    
    startAutoPlay() {
        if (this.realSlides.length <= 1) return;
        
        this.stopAutoPlay();
        this.autoPlayTimer = setInterval(() => {
            if (document.hidden) return;
            this.nextSlide();
        }, this.options.autoPlayInterval);
        this.isPlaying = true;
    }
    
    stopAutoPlay() {
        if (this.autoPlayTimer) {
            clearInterval(this.autoPlayTimer);
            this.autoPlayTimer = null;
        }
        this.isPlaying = false;
    }
    
    pause() {
        if (this.isPlaying) {
            this.stopAutoPlay();
        }
    }
    
    resume() {
        if (this.options.autoPlay && !this.isPlaying) {
            this.startAutoPlay();
        }
    }
    
    toggleAutoPlay() {
        if (this.isPlaying) {
            this.stopAutoPlay();
        } else {
            this.startAutoPlay();
        }
    }
    
    handleResize() {
        if (this.options.transition === 'slide' && this.track) {
            const totalSlides = this.realSlides.length;
            const trackIndex = totalSlides > 1 ? this.currentSlide + 1 : this.currentSlide;
            this.pendingLoopJump = null;
            this.jumpToTrackIndex(trackIndex);
            this.updatePagination(this.currentSlide, true);
            return;
        }
        if (this.options.transition === 'list' && this.track) {
            const totalSlides = this.realSlides.length;
            const config = this.listConfig || { cloneCount: 0 };
            const cloneCount = Number.isFinite(config.cloneCount) ? config.cloneCount : 0;
            const trackIndex = (cloneCount > 0 && totalSlides > 1) ? (cloneCount + this.currentSlide) : this.currentSlide;
            this.pendingLoopJump = null;
            this.jumpToTrackIndex(trackIndex);
            this.updatePagination(this.currentSlide, true);
            return;
        }
        if (this.options.transition === 'canvas') {
            this.redrawCanvas();
            this.resizeWebGL();
            this.updatePagination(this.currentSlide, true);
        }
    }
    
    // 公共API方法
    getCurrentSlide() {
        return this.currentSlide;
    }
    
    getTotalSlides() {
        return this.realSlides.length;
    }
    
    isAutoPlaying() {
        return this.isPlaying;
    }
    
    // 销毁轮播
    destroy() {
        this.stopAutoPlay();
        this.cancelCanvasAnimation();
        if (this.webglCtx && this.webglTextures) {
            try {
                if (this.webglTextures.current) this.webglCtx.deleteTexture(this.webglTextures.current);
                if (this.webglTextures.next) this.webglCtx.deleteTexture(this.webglTextures.next);
            } catch (e) {
            }
        }
        if (this.webglCtx && this.webglBuffer) {
            try {
                this.webglCtx.deleteBuffer(this.webglBuffer);
            } catch (e) {
            }
        }
        if (this.webglCtx && this.webglProgram) {
            try {
                this.webglCtx.deleteProgram(this.webglProgram);
            } catch (e) {
            }
        }
        this.webglCtx = null;
        this.webglProgram = null;
        this.webglLocations = null;
        this.webglBuffer = null;
        this.webglTextures = null;
        this.webglActiveImages = { current: null, next: null };
        if (this.nativeDragStartHandler) {
            this.container.removeEventListener('dragstart', this.nativeDragStartHandler);
            this.nativeDragStartHandler = null;
        }
        
        // 移除事件监听器
        if (this.prevBtn) {
            this.prevBtn.removeEventListener('click', () => this.prevSlide());
        }
        
        if (this.nextBtn) {
            this.nextBtn.removeEventListener('click', () => this.nextSlide());
        }
        
        this.indicators.forEach((indicator, index) => {
            indicator.removeEventListener('click', () => this.goToSlide(index));
        });

        if (this.previewHandlers) {
            const { update, showNext, showPrev, hidePreview, onPreviewTransitionEnd } = this.previewHandlers;
            this.container.removeEventListener('slideChange', update);
            if (this.previewTrackEl && onPreviewTransitionEnd) {
                this.previewTrackEl.removeEventListener('transitionend', onPreviewTransitionEnd);
            }
            if (this.nextBtn) {
                this.nextBtn.removeEventListener('mouseenter', showNext);
                this.nextBtn.removeEventListener('focus', showNext);
                this.nextBtn.removeEventListener('mouseleave', hidePreview);
                this.nextBtn.removeEventListener('blur', hidePreview);
            }
            if (this.prevBtn) {
                this.prevBtn.removeEventListener('mouseenter', showPrev);
                this.prevBtn.removeEventListener('focus', showPrev);
                this.prevBtn.removeEventListener('mouseleave', hidePreview);
                this.prevBtn.removeEventListener('blur', hidePreview);
            }
            this.previewHandlers = null;
        }

        if (this.paginationCurrentHandlers && this.paginationCurrentTrackEl) {
            const { onTransitionEnd } = this.paginationCurrentHandlers;
            if (onTransitionEnd) {
                this.paginationCurrentTrackEl.removeEventListener('transitionend', onTransitionEnd);
            }
            this.paginationCurrentHandlers = null;
        }
        
    }
}

// 自动初始化
document.addEventListener('DOMContentLoaded', function() {
    const sliders = document.querySelectorAll('.hero-slider');
    
    sliders.forEach(slider => {
        // 检查是否有幻灯片
        const slides = slider.querySelectorAll('.slide');
        if (slides.length > 0) {
            const parseBool = (v, defaultValue) => {
                if (v === null || v === undefined || v === '') return defaultValue;
                const s = String(v).trim().toLowerCase();
                if (['1', 'true', 'yes', 'on'].includes(s)) return true;
                if (['0', 'false', 'no', 'off'].includes(s)) return false;
                return defaultValue;
            };
            const parseIntSafe = (v, defaultValue) => {
                const n = parseInt(v, 10);
                return Number.isFinite(n) && n > 0 ? n : defaultValue;
            };
            const parseNonNegativeNumber = (v, defaultValue) => {
                if (v === null || v === undefined || v === '') return defaultValue;
                const n = Number(v);
                return Number.isFinite(n) && n >= 0 ? n : defaultValue;
            };

            const dataAutoPlay = slider.getAttribute('data-autoplay');
            const dataInterval = slider.getAttribute('data-interval');
            const dataTransition = slider.getAttribute('data-transition');
            const dataCanvasEffect = slider.getAttribute('data-canvas-effect');
            const dataCanvasContent = slider.getAttribute('data-canvas-content');
            const dataCanvasDuration = slider.getAttribute('data-canvas-duration');
            const dataCanvasScale = slider.getAttribute('data-canvas-scale');
            const dataCanvasMaxRatio = slider.getAttribute('data-canvas-max-ratio');
            const dataListPerView = slider.getAttribute('data-per-view') || slider.getAttribute('data-list-per-view');
            const dataListStep = slider.getAttribute('data-step') || slider.getAttribute('data-list-step');
            const dataListGap = slider.getAttribute('data-gap') || slider.getAttribute('data-list-gap');
            const dataListLoop = slider.getAttribute('data-loop') || slider.getAttribute('data-list-loop');
            const dataShowLoading = slider.getAttribute('data-show-loading') || slider.getAttribute('data-loading');

            slider._heroSlider = new HeroSlider(slider, {
                autoPlay: parseBool(dataAutoPlay, false),
                autoPlayInterval: parseIntSafe(dataInterval, 5000),
                pauseOnHover: true,
                showLoading: parseBool(dataShowLoading, true),
                transition: dataTransition || 'fade',
                canvasEffect: dataCanvasEffect || 'fade',
                canvasContent: dataCanvasContent || 'fixed',
                canvasDuration: parseIntSafe(dataCanvasDuration, 700),
                canvasScale: parseIntSafe(dataCanvasScale, 1),
                canvasMaxPixelRatio: parseIntSafe(dataCanvasMaxRatio, 2),
                listPerView: parseIntSafe(dataListPerView, 1),
                listStep: parseIntSafe(dataListStep, 1),
                listGap: parseNonNegativeNumber(dataListGap, 0),
                listLoop: parseBool(dataListLoop, true)
            });
        } else {
            // 如果没有幻灯片，隐藏轮播容器
            //slider.style.display = 'none';
        }
    });
});

// 导出类供外部使用
if (typeof module !== 'undefined' && module.exports) {
    module.exports = HeroSlider;
} else if (typeof window !== 'undefined') {
    window.HeroSlider = HeroSlider;
}
