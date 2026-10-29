/**
 * TodoCMS Motion Runtime Engine (弱+细微动效运行时)
 * 基于开源 Web Animations 引擎 Motion (Motion One) 构建
 * 
 * 核心设计原则：弱+细 (Subtle & Delicate)
 * - 首屏错落入场 (Hero Stagger Entrance)
 * - 视口滚动监听触发 (Scroll InView Reveal)
 * - 按钮微缩放与箭头联动 (Micro-Interactions)
 * - 尊重无障碍 prefers-reduced-motion
 */
(function(window) {
    'use strict';

    var Motion = window.Motion;
    var prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var DEFAULT_EASING = [0.16, 1, 0.3, 1]; // 极轻柔优雅弹性减速曲线
    var DEFAULT_DURATION = 0.6; // 600ms 丝滑克制

    var TodoMotion = {
        init: function() {
            if (!window.Motion || prefersReduced) {
                // 优雅降级：直接显示所有元素
                document.querySelectorAll('[data-motion]').forEach(function(el) {
                    el.style.opacity = '1';
                    el.style.transform = 'none';
                });
                return;
            }

            this.bindInView();
            this.bindHeroEntrance();
            this.bindScrollIndicators();
        },

        /**
         * 1. 滚动视口触发展现 (InView Reveal)
         */
        bindInView: function() {
            var self = this;
            var elements = document.querySelectorAll('[data-motion]:not([data-motion-trigger="load"])');
            
            elements.forEach(function(el) {
                // 预置初始隐动态，避免首帧闪烁
                if (!el.getAttribute('data-motion-initialized')) {
                    el.setAttribute('data-motion-initialized', 'true');
                    el.style.opacity = '0';
                }

                Motion.inView(el, function(info) {
                    var type = el.getAttribute('data-motion') || 'fade-up';
                    var delay = parseFloat(el.getAttribute('data-motion-delay')) || 0;
                    var duration = parseFloat(el.getAttribute('data-motion-duration')) || DEFAULT_DURATION;

                    self.animateElement(el, type, delay, duration);
                }, { amount: 0.15 });
            });
        },

        /**
         * 2. 首屏 Hero 阶梯入场动画 (First-Screen Stagger)
         */
        bindHeroEntrance: function() {
            var self = this;
            var heroElements = document.querySelectorAll('[data-motion-trigger="load"], .hero-section [data-motion]');
            
            if (heroElements.length === 0) return;

            var delayCounter = 0.05;
            heroElements.forEach(function(el) {
                var type = el.getAttribute('data-motion') || 'fade-up';
                var customDelay = parseFloat(el.getAttribute('data-motion-delay'));
                var delay = !isNaN(customDelay) ? customDelay : delayCounter;
                var duration = parseFloat(el.getAttribute('data-motion-duration')) || DEFAULT_DURATION;

                self.animateElement(el, type, delay, duration);
                delayCounter += 0.08; // 阶梯增量 80ms
            });
        },

        /**
         * 3. 基础微动效原子执行器
         */
        animateElement: function(el, type, delay, duration) {
            var keyframes = { opacity: [0, 1] };
            var ease = DEFAULT_EASING;

            switch (type) {
                case 'fade-up':
                    keyframes.transform = ['translateY(16px)', 'translateY(0px)'];
                    break;
                case 'fade-down':
                    keyframes.transform = ['translateY(-16px)', 'translateY(0px)'];
                    break;
                case 'fade-left':
                    keyframes.transform = ['translateX(20px)', 'translateX(0px)'];
                    break;
                case 'fade-right':
                    keyframes.transform = ['translateX(-20px)', 'translateX(0px)'];
                    break;
                case 'scale-up':
                    keyframes.transform = ['scale(0.96)', 'scale(1)'];
                    break;
                case 'fade-in':
                default:
                    // 纯淡入
                    break;
            }

            Motion.animate(el, keyframes, {
                duration: duration,
                delay: delay,
                easing: ease
            });
        },

        /**
         * 4. 滚动进度指示与视差微交互
         */
        bindScrollIndicators: function() {
            var scrollBar = document.querySelector('[data-motion-scroll-progress]');
            if (scrollBar && Motion.scroll) {
                Motion.scroll(function(progress) {
                    scrollBar.style.transform = 'scaleX(' + progress.y.progress + ')';
                });
            }
        }
    };

    window.TodoMotion = TodoMotion;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            TodoMotion.init();
        });
    } else {
        TodoMotion.init();
    }
})(window);
