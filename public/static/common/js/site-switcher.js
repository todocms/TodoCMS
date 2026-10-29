/**
 * TodoCMS 站点切换器 JavaScript
 *
 * @package TodoCMS
 * @subpackage Language
 */

(function() {
    'use strict';

    var TodoSiteSwitcher = {

        init: function() {
            this.bindEvents();
            this.handleOutsideClick();
        },

        bindEvents: function() {
            var toggleButtons = document.querySelectorAll('.todo-site-switcher.site-dropdown-style .current-site');
            toggleButtons.forEach(function(button) {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    TodoSiteSwitcher.toggleDropdown(button);
                });
            });

            var siteOptions = document.querySelectorAll('.todo-site-switcher .site-option');
            siteOptions.forEach(function(option) {
                option.addEventListener('click', function(e) {
                    TodoSiteSwitcher.selectSite(this, e);
                });
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    TodoSiteSwitcher.closeAllDropdowns();
                }
            });
        },

        toggleDropdown: function(button) {
            var dropdown = button.nextElementSibling;
            var isOpen = dropdown.style.display === 'block';

            this.closeAllDropdowns();

            if (!isOpen) {
                this.openDropdown(button, dropdown);
            }
        },

        openDropdown: function(button, dropdown) {
            dropdown.style.display = 'block';
            button.classList.add('active');

            setTimeout(function() {
                dropdown.classList.add('show');
            }, 10);
        },

        closeDropdown: function(button, dropdown) {
            dropdown.classList.remove('show');
            button.classList.remove('active');

            setTimeout(function() {
                dropdown.style.display = 'none';
            }, 200);
        },

        closeAllDropdowns: function() {
            var switchers = document.querySelectorAll('.todo-site-switcher.site-dropdown-style');
            switchers.forEach(function(switcher) {
                var button = switcher.querySelector('.current-site');
                var dropdown = switcher.querySelector('.site-dropdown-list');
                if (button && dropdown && dropdown.style.display === 'block') {
                    TodoSiteSwitcher.closeDropdown(button, dropdown);
                }
            });
        },

        selectSite: function(option, event) {
            var href = option.getAttribute('href');
            if (href && href !== '#') {
                window.location.href = href;
            } else if (event) {
                event.preventDefault();
            }
        },

        handleOutsideClick: function() {
            document.addEventListener('click', function(e) {
                var isSwitcher = e.target.closest('.todo-site-switcher');
                if (!isSwitcher) {
                    TodoSiteSwitcher.closeAllDropdowns();
                }
            });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            TodoSiteSwitcher.init();
        });
    } else {
        TodoSiteSwitcher.init();
    }

    window.TodoSiteSwitcher = TodoSiteSwitcher;
})();
