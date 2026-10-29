/**
 * TodoCMS Default HTML Theme JavaScript
 * 
 * @package TodoCMS
 * @subpackage Default_HTML_Theme
 * @since 1.0.0
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
    
    // 当文档准备就绪时执行
    $(document).ready(function() {
        
        // 搜索表单增强
        enhanceSearchForm();
        
        // 平滑滚动
        initSmoothScroll();
        
        // 返回顶部按钮
        initBackToTop();
        
        // 评论表单增强
        enhanceCommentForm();
        
        // 图片灯箱效果
        initImageLightbox();
        
        // 文章目录
        initTableOfContents();
        
        // 代码高亮
        initCodeHighlight();
        
        // 社交分享
        initSocialShare();
        
        // 无限滚动加载
        initInfiniteScroll();
    });
    
    /**
     * 搜索表单增强
     */
    function enhanceSearchForm() {
        // 搜索框焦点效果
        $('.search-field').on('focus', function() {
            $(this).closest('.search-form').addClass('focused');
        }).on('blur', function() {
            $(this).closest('.search-form').removeClass('focused');
        });
        
        // 搜索建议（如果需要的话）
        $('.search-field').on('input', function() {
            var query = $(this).val();
            if (query.length > 2) {
                // 这里可以添加搜索建议功能
                // loadSearchSuggestions(query);
            }
        });
    }
    
    /**
     * 平滑滚动
     */
    function initSmoothScroll() {
        $('a[href*="#"]:not([href="javascript:void(0);"])').click(function() {
            if (location.pathname.replace(/^\//, '') == this.pathname.replace(/^\//, '') && location.hostname == this.hostname) {
                var target = $(this.hash);
                target = target.length ? target : $('[name=' + this.hash.slice(1) + ']');
                if (target.length) {
                    $('html, body').animate({
                        scrollTop: target.offset().top - 80
                    }, 1000);
                    return false;
                }
            }
        });
    }
    
    /**
     * 返回顶部按钮
     */
    function initBackToTop() {
        // 添加返回顶部按钮
        if (!$('#back-to-top').length) {
            $('body').append('<button id="back-to-top" class="back-to-top" title="' + __t("返回顶部") + '"><span>↑</span></button>');
        }
        
        var $backToTop = $('#back-to-top');
        
        // 滚动时显示/隐藏按钮
        $(window).scroll(function() {
            if ($(this).scrollTop() > 300) {
                $backToTop.fadeIn();
            } else {
                $backToTop.fadeOut();
            }
        });
        
        // 点击返回顶部
        $backToTop.click(function() {
            $('html, body').animate({scrollTop: 0}, 800);
            return false;
        });
    }
    
    /**
     * 评论表单增强
     */
    function enhanceCommentForm() {
        // 评论表单验证
        $('#commentform').on('submit', function(e) {
            var $form = $(this);
            var $comment = $form.find('#comment');
            var $author = $form.find('#author');
            var $email = $form.find('#email');
            
            // 清除之前的错误提示
            $form.find('.error').removeClass('error');
            
            var hasError = false;
            
            // 验证评论内容
            if ($comment.val().trim() === '') {
                $comment.addClass('error');
                hasError = true;
            }
            
            // 验证姓名
            if ($author.length && $author.val().trim() === '') {
                $author.addClass('error');
                hasError = true;
            }
            
            // 验证邮箱
            if ($email.length && $email.val().trim() !== '') {
                var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test($email.val())) {
                    $email.addClass('error');
                    hasError = true;
                }
            }
            
            if (hasError) {
                e.preventDefault();
                alert(__t("请检查表单中的错误信息"));
            }
        });
        
        // 字符计数
        $('#comment').on('input', function() {
            var $this = $(this);
            var maxLength = 1000;
            var currentLength = $this.val().length;
            
            if (!$this.siblings('.char-count').length) {
                $this.after('<div class="char-count"></div>');
            }
            
            var $counter = $this.siblings('.char-count');
            $counter.text(currentLength + '/' + maxLength);
            
            if (currentLength > maxLength) {
                $counter.addClass('over-limit');
            } else {
                $counter.removeClass('over-limit');
            }
        });
    }
    
    /**
     * 工具函数：防抖
     */
    function debounce(func, wait, immediate) {
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
    }
    
    /**
     * 工具函数：节流
     */
    function throttle(func, limit) {
        var inThrottle;
        return function() {
            var args = arguments;
            var context = this;
            if (!inThrottle) {
                func.apply(context, args);
                inThrottle = true;
                setTimeout(function() {
                    inThrottle = false;
                }, limit);
            }
        };
    }
    
    /**
     * 图片灯箱效果
     */
    function initImageLightbox() {
        // 为内容区域的图片添加灯箱效果
        $('.entry-content img').each(function() {
            var $img = $(this);
            if (!$img.parent('a').length) {
                $img.wrap('<a href="' + $img.attr('src') + '" class="lightbox-trigger"></a>');
            }
        });
        
        // 灯箱点击事件
        $(document).on('click', '.lightbox-trigger', function(e) {
            e.preventDefault();
            var imgSrc = $(this).attr('href');
            showLightbox(imgSrc);
        });
        
        // 创建灯箱
        function showLightbox(imgSrc) {
            var lightboxHtml = '<div id="lightbox-overlay">' +
                '<div class="lightbox-container">' +
                '<img src="' + imgSrc + '" alt="">' +
                '<button class="lightbox-close">&times;</button>' +
                '</div>' +
                '</div>';
            
            $('body').append(lightboxHtml);
            $('#lightbox-overlay').fadeIn();
            
            // 关闭灯箱
            $(document).on('click', '#lightbox-overlay, .lightbox-close', function(e) {
                if (e.target === this) {
                    $('#lightbox-overlay').fadeOut(function() {
                        $(this).remove();
                    });
                }
            });
            
            // ESC键关闭
            $(document).on('keyup', function(e) {
                if (e.keyCode === 27) {
                    $('#lightbox-overlay').fadeOut(function() {
                        $(this).remove();
                    });
                }
            });
        }
    }
    
    /**
     * 文章目录
     */
    function initTableOfContents() {
        var $content = $('.entry-content');
        var $headings = $content.find('h1, h2, h3, h4, h5, h6');
        
        if ($headings.length > 2) {
            var tocHtml = '<div id="table-of-contents">' +
                '<h4>' + __t("文章目录") + '</h4>' +
                '<ul class="toc-list"></ul>' +
                '<button class="toc-toggle">' + __t("目录") + '</button>' +
                '</div>';
            
            $('.widget-area').prepend(tocHtml);
            
            var $tocList = $('.toc-list');
            
            $headings.each(function(index) {
                var $heading = $(this);
                var id = 'heading-' + index;
                var text = $heading.text();
                var level = parseInt($heading.prop('tagName').substr(1));
                
                $heading.attr('id', id);
                
                var tocItem = '<li class="toc-level-' + level + '">' +
                    '<a href="#' + id + '">' + text + '</a>' +
                    '</li>';
                
                $tocList.append(tocItem);
            });
            
            // 目录切换
            $('.toc-toggle').click(function() {
                $('.toc-list').slideToggle();
                $(this).toggleClass('active');
            });
            
            // 滚动高亮
            $(window).scroll(throttle(function() {
                var scrollTop = $(window).scrollTop();
                var current = '';
                
                $headings.each(function() {
                    var $heading = $(this);
                    if ($heading.offset().top - 100 <= scrollTop) {
                        current = $heading.attr('id');
                    }
                });
                
                $('.toc-list a').removeClass('active');
                if (current) {
                    $('.toc-list a[href="#' + current + '"]').addClass('active');
                }
            }, 100));
        }
    }
    
    /**
     * 代码高亮
     */
    function initCodeHighlight() {
        // 为代码块添加复制按钮
        $('pre code').each(function() {
            var $code = $(this);
            var $pre = $code.parent();
            
            if (!$pre.find('.code-copy').length) {
                $pre.css('position', 'relative');
                $pre.append('<button class="code-copy" title="' + __t("复制代码") + '">' + __t("复制") + '</button>');
            }
        });
        
        // 复制代码功能
        $(document).on('click', '.code-copy', function() {
            var $button = $(this);
            var code = $button.siblings('code').text();
            
            if (navigator.clipboard) {
                navigator.clipboard.writeText(code).then(function() {
                    $button.text(__t("已复制")).addClass('copied').addClass('copied');
                    setTimeout(function() {
                        $button.text(__t("复制")).removeClass('copied').removeClass('copied');
                    }, 2000);
                });
            }
        });
    }
    
    /**
     * 社交分享
     */
    function initSocialShare() {
        if ($('.entry-content').length) {
            var shareHtml = '<div class="social-share">' +
                '<h4>' + __t("分享文章") + '</h4>' +
                '<div class="share-buttons">' +
                '<a href="javascript:void(0);" class="share-weibo" title="' + __t("分享到微博") + '">' + __t("分享到微博") + '</a>' +
                '<a href="javascript:void(0);" class="share-wechat" title="' + __t("分享到微信") + '">' + __t("分享到微信") + '</a>' +
                '<a href="javascript:void(0);" class="share-qq" title="' + __t("分享到") + 'QQ">' + __t("分享到") + 'QQ</a>' +
                '<a href="javascript:void(0);" class="share-qzone" title="' + __t("分享到空间") + '">' + __t("分享到空间") + '</a>' +
                '<a href="javascript:void(0);" class="share-copy" title="' + __t("复制链接") + '">' + __t("复制链接") + '</a>' +
                '</div>' +
                '</div>';
            
            //$('.entry-footer').append(shareHtml);
            
            var url = encodeURIComponent(window.location.href);
            var title = encodeURIComponent(document.title);
            
            // 微博分享
            $('.share-weibo').attr('href', 'https://service.weibo.com/share/share.php?url=' + url + '&title=' + title);
            
            // QQ分享
            $('.share-qq').attr('href', 'https://connect.qq.com/widget/shareqq/index.html?url=' + url + '&title=' + title);
            
            // 复制链接
            $('.share-copy').click(function(e) {
                e.preventDefault();
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(window.location.href).then(function() {
                        alert(__t("链接已复制到剪贴板"));
                    });
                }
            });
            
            // 微信分享（显示二维码）
            $('.share-wechat').click(function(e) {
                e.preventDefault();
                showWechatQR();
            });
        }
    }
    
    /**
     * 显示微信分享二维码
     */
    function showWechatQR() {
        var qrHtml = '<div id="wechat-qr-overlay">' +
            '<div class="qr-container">' +
            '<h4>' + __t("微信扫码分享") + '</h4>' +
            '<div class="qr-code"></div>' +
            '<p>' + __t("使用微信扫描二维码分享到朋友圈") + '</p>' +
            '<button class="qr-close">' + __t("关闭") + '</button>' +
            '</div>' +
            '</div>';
        
        $('body').append(qrHtml);
        
        // 生成二维码（这里使用在线API，实际项目中建议使用本地库）
        var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(window.location.href);
        $('.qr-code').html('<img src="' + qrUrl + '" alt="' + __t("二维码") + '"' + '">');
        
        $('#wechat-qr-overlay').fadeIn();
        
        // 关闭二维码
        $(document).on('click', '#wechat-qr-overlay, .qr-close', function(e) {
            if (e.target === this) {
                $('#wechat-qr-overlay').fadeOut(function() {
                    $(this).remove();
                });
            }
        });
    }
    
    /**
     * 无限滚动加载
     */
    function initInfiniteScroll() {
        var $pagination = $('.pagination');
        var $nextLink = $pagination.find('.next');
        
        if ($nextLink.length && $('.post').length) {
            var loading = false;
            var page = 2;
            
            $(window).scroll(throttle(function() {
                if (loading) return;
                
                var scrollTop = $(window).scrollTop();
                var windowHeight = $(window).height();
                var documentHeight = $(document).height();
                
                if (scrollTop + windowHeight >= documentHeight - 1000) {
                    loadMorePosts();
                }
            }, 250));
            
            function loadMorePosts() {
                if (loading) return;
                loading = true;
                
                // 显示加载指示器
                $('.content-area').append('<div class="loading-more"><div class="loading"></div><p>' + __t("加载更多") + '</p></div>');
                
                // 这里应该通过AJAX加载更多文章
                // 示例代码，实际需要根据后端API调整
                setTimeout(function() {
                    $('.loading-more').remove();
                    loading = false;
                    page++;
                }, 2000);
            }
        }
    }
    
})(jQuery);
