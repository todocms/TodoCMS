/**
 * TodoCMS 下载配置构建器 JavaScript
 * 
 * 处理下载配置字段的交互逻辑
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止重复加载
if (typeof window.downloadBuilderInitialized === 'undefined') {
    window.downloadBuilderInitialized = true;

    /**
     * 添加下载项
     */
    window.addDownloadItem = function(fieldId) {
        const container = document.getElementById('download-list-' + fieldId);
        const wrapper = container.closest('.download-builder-wrapper');
        const maxDownloads = parseInt(wrapper.getAttribute('data-max-downloads')) || 10;
        const currentItems = container.querySelectorAll('.download-item').length;
        
        if (currentItems >= maxDownloads) {
            if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils && TodoCMS.Utils.showMessage) {
                TodoCMS.Utils.showMessage('最多只能添加 ' + maxDownloads + ' 个下载地址', 'warning');
            } else {
                console.warn('TodoCMS.Utils not loaded. Message: 最多只能添加 ' + maxDownloads + ' 个下载地址');
            }
            return;
        }
        
        const newIndex = currentItems;
        const allowFileSelect = wrapper.getAttribute('data-allow-file-select') === '1';
        const placeholder = wrapper.getAttribute('data-placeholder') || '输入地址或选择文件';
        const itemHtml = createDownloadItemHtml(newIndex, allowFileSelect, placeholder);
        
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = itemHtml;
        const newItem = tempDiv.firstElementChild;
        
        container.appendChild(newItem);
        updateDownloadData(fieldId);
    };

    /**
     * 移除下载项
     */
    window.removeDownloadItem = function(button) {
        const item = button.closest('.download-item');
        const container = item.closest('.download-list');
        const fieldId = container.id.replace('download-list-', '');
        
        item.remove();
        
        // 重新编号
        const items = container.querySelectorAll('.download-item');
        items.forEach((item, index) => {
            item.setAttribute('data-index', index);
            const title = item.querySelector('.download-item-title');
            if (title) {
                title.textContent = '#' + (index + 1);
            }
        });
        
        updateDownloadData(fieldId);
    };

    /**
     * 选择媒体文件
     */
    window.selectMediaFile = function(button) {
        // 使用全局媒体模态框进行文件选择（单选）
        if (typeof window.selectMediaForField === 'function') {
            window.selectMediaForField({
                multiple: false,
                type: 'file',
                title: '选择文件',
                button_text: '选择',
                callback: function(selected) {
                    var file = Array.isArray(selected) ? selected[0] : selected;
                    if (!file) return;

                    // 填充前面的输入框
                    var urlInput = button.previousElementSibling;
                    if (!urlInput || !urlInput.tagName || urlInput.tagName.toLowerCase() !== 'input') {
                        // 兜底查找当前项中的文本/URL输入框
                        var group = button.closest('.input-group');
                        if (group) {
                            urlInput = group.querySelector('input[type="text"], input[type="url"]');
                        }
                    }
                    if (urlInput) {
                        urlInput.value = file.url || '';
                        // 触发 change 事件，保持与其他组件一致
                        var evt = new Event('change', { bubbles: true });
                        urlInput.dispatchEvent(evt);
                    }

                    // 如果存在下载构建器的别名输入且为空，自动填入文件名
                    var item = button.closest('.download-item');
                    if (item) {
                        var aliasInput = item.querySelector('.download-alias');
                        if (aliasInput && !aliasInput.value && file.title) {
                            aliasInput.value = file.title;
                        }
                        // 更新下载数据（仅在下载构建器上下文中）
                        var container = item.closest('.download-list');
                        if (container && container.id) {
                            var fieldId = container.id.replace('download-list-', '');
                            if (typeof updateDownloadData === 'function') {
                                updateDownloadData(fieldId);
                            }
                        }
                    }
                }
            });
        } else {
            TodoCMS.Utils.showMessage('媒体库功能未加载，请手动输入下载地址', 'warning');
        }
    };

    /**
     * 创建下载项HTML
     */
    function createDownloadItemHtml(index, allowFileSelect, placeholder) {
        return `
            <div class="download-item" data-index="${index}">
                <div class="download-item-header flex gap-5 items-center">
                    <span class="download-item-title" style="font-size: 20px; color: #ccc; font-style: italic;">#${index + 1}</span>
                    <button type="button" class="btn button-link remove-download-btn" onclick="removeDownloadItem(this)">✕</button>
                </div>
                <div class="download-item-content flex gap-x-10 items-top flex-wrap" style="margin: 10px 0 0 40px;">
                    <div class="form-group">
                        <h4>下载地址</h4>
                        <div class="input-group flex flex-wrap gap-2">
                            <input type="text" class="form-control download-url" value="" placeholder="${placeholder}">
                            ${allowFileSelect ? '<button type="button" class="btn button-link select-file-btn" onclick="selectMediaFile(this)">✚ 选择文件</button>' : ''}
                        </div>
                    </div>
                    <div class="form-group">
                        <h4>别名</h4>
                        <input type="text" class="form-control download-alias" value="" placeholder="下载链接显示名称">
                    </div>
                    <div class="form-group">
                        <h4>提取码</h4>
                        <input type="text" class="form-control download-extract-code" value="">
                    </div>
                    <div class="form-group">
                        <h4>解压密码</h4>
                        <input type="text" class="form-control download-password" value="">
                    </div>
                    <div class="form-group">
                        <h4>备注</h4>
                        <textarea class="form-control download-note" rows="2"></textarea>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * 更新下载数据到隐藏字段
     */
    function updateDownloadData(fieldId) {
        const container = document.getElementById('download-list-' + fieldId);
        const hiddenInput = document.getElementById(fieldId);
        const items = container.querySelectorAll('.download-item');
        
        const downloads = [];
        
        items.forEach(item => {
            const url = item.querySelector('.download-url').value;
            const alias = item.querySelector('.download-alias').value;
            const extractCode = item.querySelector('.download-extract-code').value;
            const password = item.querySelector('.download-password').value;
            const note = item.querySelector('.download-note').value;
            
            if (url) { // 只保存有URL的项
                downloads.push({
                    url: url,
                    alias: alias,
                    extract_code: extractCode,
                    password: password,
                    note: note
                });
            }
        });
        
        hiddenInput.value = JSON.stringify(downloads);
    }

    /**
     * 绑定输入事件
     */
    function bindInputEvents() {
        document.addEventListener('input', function(e) {
            if (e.target.matches('.download-url, .download-alias, .download-extract-code, .download-password, .download-note')) {
                const item = e.target.closest('.download-item');
                const container = item.closest('.download-list');
                const fieldId = container.id.replace('download-list-', '');
                updateDownloadData(fieldId);
            }
        });
    }

    // 页面加载完成后绑定事件
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindInputEvents);
    } else {
        bindInputEvents();
    }
}