/**
 * TodoCMS Ajax 分页器
 *
 * 处理非主分页（pager != main）的 Ajax 异步加载。
 * 点击翻页时通过 Ajax 请求 /api/ajax-pager 获取渲染后的 HTML，
 * 仅替换 .ajax-pager-container 区域，不刷新页面、不改变 URL。
 *
 * 状态持久化：
 * - 每次 Ajax 加载成功后，将页码存入 sessionStorage
 * - 页面刷新/主分页跳转后，自动从 sessionStorage 恢复各块分页的状态
 * - 各 Ajax 块分页完全独立，互不影响
 * - 新标签页打开时状态重置为第 1 页
 *
 * @package TodoCMS
 * @version 1.2.0
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'todocms_ajax_pager_state';

    window.TodoAjaxPager = { initialized: false };

    /**
     * 读取 sessionStorage 中所有分页状态
     * @returns {Object} { pagerName: pageNum, ... }
     */
    function readState() {
        try {
            var raw = sessionStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : {};
        } catch (e) {
            return {};
        }
    }

    /**
     * 保存单个分页状态到 sessionStorage
     */
    function saveState(pagerName, pageNum) {
        try {
            var state = readState();
            if (pageNum <= 1) {
                delete state[pagerName]; // 第 1 页不需要存，减少存储
            } else {
                state[pagerName] = pageNum;
            }
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) {
            // sessionStorage 不可用时静默失败
        }
    }

    /**
     * 从 URL 中提取分页参数值
     * 兼容 /?paged_article=2 和 /?paged_paged_article=2 两种格式
     */
    function extractPageFromUrl(url, pagerName) {
        var candidates = [
            'paged_' + pagerName,
            'paged_paged_' + pagerName,
            pagerName
        ];
        try {
            var u = new URL(url, window.location.origin);
            for (var i = 0; i < candidates.length; i++) {
                var val = u.searchParams.get(candidates[i]);
                if (val) return parseInt(val, 10) || 1;
            }
        } catch (e) {
            for (var j = 0; j < candidates.length; j++) {
                var re = new RegExp('[?&]' + candidates[j].replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([0-9]+)');
                var m = url.match(re);
                if (m) return parseInt(m[1], 10) || 1;
            }
        }
        return 1;
    }

    /**
     * 获取当前 URL 中某个分页的页码（用于初始恢复时 URL 优先）
     */
    function getPageFromCurrentUrl(pagerName) {
        return extractPageFromUrl(window.location.href, pagerName);
    }

    /**
     * 判断容器当前渲染的是否为第 1 页（检查分页导航中是否有 current 标记）
     */
    function isShowingPage1(container) {
        var currentEl = container.querySelector('.pagination-number.current');
        if (!currentEl) return true; // 没有分页导航，默认为第 1 页
        var num = parseInt(currentEl.textContent, 10);
        return num === 1;
    }

    /**
     * 显示加载状态
     */
    function showLoading(container) {
        hideLoading(container);
        var overlay = document.createElement('div');
        overlay.className = 'ajax-pager-loading';
        overlay.innerHTML = '<div class="ajax-pager-spinner"></div>';
        overlay.style.cssText =
            'position:absolute;top:0;left:0;right:0;bottom:0;' +
            'background:rgba(255,255,255,0.7);display:flex;' +
            'align-items:center;justify-content:center;z-index:10;' +
            'border-radius:4px;';
        var spinner = overlay.querySelector('.ajax-pager-spinner');
        if (spinner) {
            spinner.style.cssText =
                'width:32px;height:32px;border:3px solid #ddd;' +
                'border-top-color:#0073aa;border-radius:50%;' +
                'animation:ajax-pager-spin 0.8s linear infinite;';
        }
        if (!document.getElementById('ajax-pager-keyframes')) {
            var style = document.createElement('style');
            style.id = 'ajax-pager-keyframes';
            style.textContent =
                '@keyframes ajax-pager-spin{to{transform:rotate(360deg)}}';
            document.head.appendChild(style);
        }
        if (!container.style.position || container.style.position === 'static') {
            container.style.position = 'relative';
        }
        container.appendChild(overlay);
    }

    function hideLoading(container) {
        var overlay = container.querySelector('.ajax-pager-loading');
        if (overlay) overlay.remove();
    }

    /**
     * 显示错误信息
     */
    function showError(container, message) {
        var old = container.querySelector('.ajax-pager-error');
        if (old) old.remove();
        var err = document.createElement('div');
        err.className = 'ajax-pager-error';
        err.style.cssText =
            'padding:12px 16px;background:#fff3f3;color:#c00;' +
            'border:1px solid #fcc;border-radius:4px;margin:8px 0;';
        err.textContent = message || __t('加载失败');
        container.insertBefore(err, container.firstChild);
        setTimeout(function () {
            if (err.parentNode) err.remove();
        }, 5000);
    }

    /**
     * 初始化单个 Ajax 分页容器
     */
    function initContainer(container) {
        var pagerName = container.getAttribute('data-pager');
        var siteUrl = (window.TODO && window.TODO.SITE_URL) ||
            (document.querySelector('meta[name="site-url"]') ? document.querySelector('meta[name="site-url"]').getAttribute('content') : window.location.origin);
        siteUrl = siteUrl.replace(/\/+$/, '');
        var apiUrl = container.getAttribute('data-api-url') || siteUrl + '/api/ajax-pager';

        if (!pagerName) {
            console.warn('[AjaxPager] Container is missing the data-pager attribute; skipping:', container);
            return;
        }

        // 使用事件委托监听容器内所有分页链接的点击
        container.addEventListener('click', function (e) {
            var link = e.target.closest('a.pagination-btn, a.pagination-number, .pagination-numbers a');
            if (!link || !link.href) return;

            var paginationNav = link.closest('.pagination-nav');
            if (!paginationNav) return;

            e.preventDefault();
            e.stopPropagation();

            var pageNum = extractPageFromUrl(link.href, pagerName);

            if (pageNum < 1) pageNum = 1;
            loadPage(container, pagerName, apiUrl, pageNum);
        }, true);

        // === 状态恢复 ===
        // 优先级：URL 参数 > sessionStorage
        var restorePage = getPageFromCurrentUrl(pagerName);
        if (restorePage <= 1) {
            var state = readState();
            if (state[pagerName] && state[pagerName] > 1) {
                restorePage = state[pagerName];
            }
        }

        // 如果服务器渲染的是第 1 页，但需要恢复到其他页，触发 Ajax 加载
        if (restorePage > 1 && isShowingPage1(container)) {
            loadPage(container, pagerName, apiUrl, restorePage);
        } else if (restorePage > 1) {
            // 服务器已经渲染了正确的页（URL 带参数），同步 sessionStorage
            saveState(pagerName, restorePage);
        }
    }

    /**
     * 通过 Ajax 加载指定页的内容
     */
    function loadPage(container, pagerName, apiUrl, pageNum) {
        showLoading(container);

        var url = apiUrl + '?pager=' + encodeURIComponent(pagerName) +
            '&api_page=' + pageNum;
            
        var lang = document.documentElement.lang;
        if (lang) {
            url += '&lang=' + encodeURIComponent(lang);
        }
        
        // 传递当前页面URL，以便后端生成正确的分页基础链接
        var currentUrl = window.location.pathname + window.location.search;
        url += '&current_url=' + encodeURIComponent(currentUrl);

        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.timeout = 15000;

        xhr.onload = function () {
            hideLoading(container);
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.status === 'success' && resp.data && resp.data.html) {
                        container.innerHTML = resp.data.html;
                        // 持久化当前页码
                        saveState(pagerName, pageNum);
                    } else if (resp.data && resp.data.html) {
                        container.innerHTML = resp.data.html;
                        saveState(pagerName, pageNum);
                    } else {
                        showError(container, resp.message || __t('服务器返回异常'));
                    }
                } catch (e) {
                    console.error('[AjaxPager] JSON Parsing failed:', e);
                    showError(container, __t('响应解析失败'));
                }
            } else {
                showError(container, __t('请求失败') + ': HTTP ' + xhr.status);
            }
        };

        xhr.onerror = function () {
            hideLoading(container);
            showError(container, __t('网络错误'));
        };

        xhr.ontimeout = function () {
            hideLoading(container);
            showError(container, __t('请求超时'));
        };

        xhr.send();
    }

    /**
     * 初始化所有 Ajax 分页容器
     */
    function init() {

        try {
            var containers = document.querySelectorAll('.ajax-pager-container');

            for (var i = 0; i < containers.length; i++) {
                initContainer(containers[i]);
            }

            window.TodoAjaxPager.initialized = true;
        } catch (e) {
            console.error('[AjaxPager] Initialization failed:', e);
        }
    }

    function ready(fn) {
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            setTimeout(fn, 1);
        } else {
            document.addEventListener('DOMContentLoaded', function () {
                setTimeout(fn, 1);
            });
        }
    }

    ready(init);
})();
