/**
 * TodoCMS 语言切换器 JavaScript
 * 
 * @package TodoCMS
 * @subpackage Language
 */

(function() {
    'use strict';
    
    // 语言切换器对象
    var TodoLanguageSwitcher = {
        
        // 初始化
        init: function() {
            this.bindEvents();
            this.handleOutsideClick();
        },
        
        // 绑定事件
        bindEvents: function() {
            // 下拉菜单切换
            const toggleButtons = document.querySelectorAll('.todo-language-switcher.dropdown-style .current-language');
            toggleButtons.forEach((button) => {
                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    TodoLanguageSwitcher.toggleDropdown(button);
                });
            });
            
            // 语言选择
            var languageOptions = document.querySelectorAll('.todo-language-switcher .language-option');
            languageOptions.forEach(function(option) {
                option.addEventListener('click', function(e) {
                    TodoLanguageSwitcher.selectLanguage(this, e);
                });
            });
            
            // 键盘导航支持
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    TodoLanguageSwitcher.closeAllDropdowns();
                }
            });
        },
        
        // 切换下拉菜单
        toggleDropdown: function(button) {
            var dropdown = button.nextElementSibling;
            var isOpen = dropdown.style.display === 'block';
            
            // 关闭所有其他下拉菜单
            this.closeAllDropdowns();
            
            if (!isOpen) {
                this.openDropdown(button, dropdown);
            }
        },
        
        // 打开下拉菜单
        openDropdown: function(button, dropdown) {
            dropdown.style.display = 'block';
            button.classList.add('active');
            
            // 添加动画类
            setTimeout(function() {
                dropdown.classList.add('show');
            }, 10);
            
            // 设置焦点到第一个选项
            var firstOption = dropdown.querySelector('.language-option');
            if (firstOption) {
                firstOption.focus();
            }
        },
        
        // 关闭下拉菜单
        closeDropdown: function(button, dropdown) {
            dropdown.classList.remove('show');
            button.classList.remove('active');
            
            setTimeout(function() {
                dropdown.style.display = 'none';
            }, 200);
        },
        
        // 关闭所有下拉菜单
        closeAllDropdowns: function() {
            var switchers = document.querySelectorAll('.todo-language-switcher.dropdown-style');
            switchers.forEach(function(switcher) {
                var button = switcher.querySelector('.current-language');
                var dropdown = switcher.querySelector('.language-dropdown');
                
                if (button && dropdown && dropdown.style.display === 'block') {
                    TodoLanguageSwitcher.closeDropdown(button, dropdown);
                }
            });
        },
        
        // 选择语言
        selectLanguage: function(option, event) {
            var href = option.getAttribute('href');
            var targetLang = option.getAttribute('data-lang');
            
            if (href && href !== '#') {
                // 在跳转前写入语言持久化 Cookie（便于无前缀/参数场景的回退识别）
                if (targetLang) {
                    try { this.setLanguageCookie(targetLang, 180); } catch (e) {}
                }
                
                // 显示加载状态
                this.showLoadingState(option);
                
                // 跳转到新语言页面
                window.location.href = href;
            } else {
                if (event) { event.preventDefault(); }
            }
        },
        
        // 显示加载状态
        showLoadingState: function(option) {
            var originalText = option.innerHTML;
            option.innerHTML = '<span style="flex: 1;padding: 5px 10px 10px;font-size: 20px;text-align: center;line-height: 0;">...</span>';
            option.style.opacity = '0.6';
            option.style.pointerEvents = 'none';
        },
        
        // 处理外部点击
        handleOutsideClick: function() {
            document.addEventListener('click', function(e) {
                var isLanguageSwitcher = e.target.closest('.todo-language-switcher');
                
                if (!isLanguageSwitcher) {
                    TodoLanguageSwitcher.closeAllDropdowns();
                }
            });
        },
        
        // 获取当前语言
        getCurrentLanguage: function() {
            return document.documentElement.getAttribute('lang') || this.getDefaultLanguage();
        },
        
        // 获取默认语言
        getDefaultLanguage: function() {
            // 从页面的 data 属性或配置中获取
            var html = document.documentElement;
            return html.getAttribute('lang') || 'zh-CN';
        },
        
        // 设置语言Cookie
        setLanguageCookie: function(languageCode, days) {
            days = days || 30;
            var expires = new Date();
            expires.setTime(expires.getTime() + (days * 24 * 60 * 60 * 1000));
            
            document.cookie = 'todo_locale=' + languageCode + 
                             ';expires=' + expires.toUTCString() + 
                             ';path=/;SameSite=Lax';
        },
        
        // 获取语言Cookie
        getLanguageCookie: function() {
            var name = 'todo_locale=';
            var decodedCookie = decodeURIComponent(document.cookie);
            var ca = decodedCookie.split(';');
            
            for (var i = 0; i < ca.length; i++) {
                var c = ca[i];
                while (c.charAt(0) === ' ') {
                    c = c.substring(1);
                }
                if (c.indexOf(name) === 0) {
                    return c.substring(name.length, c.length);
                }
            }
            return '';
        }
    };
    
    // 旧模式全局函数已移除，交互由 TodoLanguageSwitcher 的事件绑定统一处理

    
    // DOM加载完成后初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            TodoLanguageSwitcher.init();
        });
    } else {
        TodoLanguageSwitcher.init();
    }
    
    // 导出到全局
    window.TodoLanguageSwitcher = TodoLanguageSwitcher;
    
})();

/**
 * 模板标签翻译处理
 */
(function() {
    'use strict';
    
    var TodoLanguageTemplate = {
        
        // 初始化模板翻译
        init: function() {
            this.processLanguageTags();
        },
        
        // 处理页面中的语言标签
        processLanguageTags: function() {
            var currentLang = this.getCurrentLanguage();
            
            // 查找所有语言标签
            var walker = document.createTreeWalker(
                document.body,
                NodeFilter.SHOW_TEXT,
                null,
                false
            );
            
            var textNodes = [];
            var node;
            
            while (node = walker.nextNode()) {
                if (this.hasLanguageTag(node.textContent)) {
                    textNodes.push(node);
                }
            }
            
            // 处理找到的文本节点
            textNodes.forEach(function(textNode) {
                TodoLanguageTemplate.processTextNode(textNode, currentLang);
            });
        },
        
        // 检查文本是否包含语言标签
        hasLanguageTag: function(text) {
            return /\{[^}]+\|todo_lang\}/g.test(text);
        },
        
        // 处理单个文本节点
        processTextNode: function(textNode, currentLang) {
            var originalText = textNode.textContent;
            var processedText = this.processLanguageTagsInText(originalText, currentLang);
            
            if (processedText !== originalText) {
                textNode.textContent = processedText;
            }
        },
        
        // 处理文本中的语言标签
        processLanguageTagsInText: function(text, currentLang) {
            return text.replace(/\{([^}|]+)(\|([^}]*))?\|todo_lang\}/g, function(match, originalText, contextPart, context) {
                // 这里应该调用翻译API，暂时返回原文
                return TodoLanguageTemplate.getTranslation(originalText, context, currentLang);
            });
        },
        
        // 获取翻译（这里应该连接到后端API）
        getTranslation: function(originalText, context, targetLang) {
            // 暂时返回原文，实际应该调用翻译API
            return originalText;
        },
        
        // 获取当前语言
        getCurrentLanguage: function() {
            var lang = document.documentElement.getAttribute('lang');
            if (lang) {
                return lang;
            }
            // 从Cookie获取
            var langFromCookie = this.getLanguageCookie();
            if (langFromCookie) {
                return langFromCookie;
            }
            
            // 默认语言
            return 'zh-CN';
        },
        
        // 获取语言Cookie
        getLanguageCookie: function() {
            var name = 'todo_locale=';
            var decodedCookie = decodeURIComponent(document.cookie);
            var ca = decodedCookie.split(';');
            
            for (var i = 0; i < ca.length; i++) {
                var c = ca[i];
                while (c.charAt(0) === ' ') {
                    c = c.substring(1);
                }
                if (c.indexOf(name) === 0) {
                    return c.substring(name.length, c.length);
                }
            }
            return '';
        }
    };
    
    // DOM加载完成后初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            TodoLanguageTemplate.init();
        });
    } else {
        TodoLanguageTemplate.init();
    }
    
    // 导出到全局
    window.TodoLanguageTemplate = TodoLanguageTemplate;
    
})();