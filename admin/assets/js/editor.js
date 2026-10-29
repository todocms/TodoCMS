/**
 * 简化的编辑器脚本
 * 只保留基本的文本编辑功能
 */

(function($, todo) {
    todo.editor = todo.editor || {};

    /**
     * 简化的编辑器切换器
     */
    function SwitchEditors() {
        var exports = {};

        function init() {
            // 处理编辑器切换按钮点击事件
            jQuery(function($) {
                $(document).on('click', '.todo-switch-editor', function(event) {
                    var $target = $(this),
                        id = $target.attr('data-todo-editor-id'),
                        mode = $target.hasClass('switch-tmce') ? 'tmce' : 'html';

                    // 只支持HTML模式
                    if (mode === 'html') {
                        switchToHtml(id);
                    } else {
                        // 切换到HTML模式
                        switchToHtml(id);
                    }
                });
            });
        }

        /**
         * 切换到HTML模式
         */
        function switchToHtml(id) {
            var $wrap = $('#todo-' + id + '-wrap'),
                $textarea = $('#' + id);

            if (!$wrap.length || !$textarea.length) {
                return;
            }

            // 移除所有模式类
            $wrap.removeClass('tmce-active html-active');
            // 添加HTML模式类
            $wrap.addClass('html-active');

            // 显示文本区域
            $textarea.show();

            // 更新按钮状态
            $wrap.find('.switch-tmce').removeClass('active');
            $wrap.find('.switch-html').addClass('active');

            // 触发切换事件
            $(document).trigger('todo-editor-switch', [id, 'html']);
        }

        // 导出函数
        exports.init = init;
        exports.switchToHtml = switchToHtml;

        return exports;
    }

    // 初始化
    var switchEditors = SwitchEditors();
    switchEditors.init();

    // 全局函数，用于向后兼容
    window.switchEditor = function(id, mode) {
        if (mode === 'html') {
            switchEditors.switchToHtml(id);
        } else {
            // 切换到HTML模式
            switchEditors.switchToHtml(id);
        }
    };

    // 导出到todo.editor
    todo.editor.switchEditors = switchEditors;

})(jQuery, window.todo || {});

/**
 * 简化的编辑器初始化函数
 */
function todo_editor_init(settings) {
    // 只初始化HTML模式
    if (settings && settings.id) {
        var $wrap = jQuery('#todo-' + settings.id + '-wrap');
        if ($wrap.length) {
            $wrap.removeClass('tmce-active').addClass('html-active');
            jQuery('#' + settings.id).show();
        }
    }
}

// 页面加载完成后初始化所有编辑器
jQuery(document).ready(function($) {
    $('.todo-editor-wrap').each(function() {
        var $wrap = $(this),
            id = $wrap.attr('id');
        
        if (id && id.indexOf('todo-') === 0) {
            var editorId = id.replace('todo-', '').replace('-wrap', '');
            todo_editor_init({id: editorId});
        }
    });
});
