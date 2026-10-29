<?php
/**
 * 全局用户选择器模态框组件
 * 可通过用户名、ID、邮箱等查询并选择用户，支持单选/多选。
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('TODO_ADMIN') && !defined('DOING_AJAX') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 确保核心函数可用
if (!function_exists('admin_url')) {
    require_once dirname(__DIR__, 2) . '/core/functions/core.php';
}

class TodoUserSelectorModal {
    public static function render($options = array()) {
        $defaults = array(
            'modal_id' => 'todo-user-selector-modal',
            'title' => '选择用户',
            'multiple' => false,
            'button_text' => '确认选择',
            'cancel_text' => '取消',
            'target_input' => '', // 目标输入框CSS选择器（单选或多选, 输出用户ID或ID列表）
            'target_info' => '',  // 目标信息容器CSS选择器（可选，用于展示已选用户信息）
            'callback' => ''      // JS回调函数名称（可选，优先于target_*）
        );
        $options = array_merge($defaults, is_array($options) ? $options : array());

        $nonce = todo_create_nonce('user_search');

        ob_start();
        ?>
        <div id="<?php echo esc_attr($options['modal_id']); ?>" class="todo-user-selector-overlay" data-multiple="<?php echo $options['multiple'] ? 'true' : 'false'; ?>" data-callback="<?php echo esc_attr($options['callback']); ?>" data-target-input="<?php echo esc_attr($options['target_input']); ?>" data-target-info="<?php echo esc_attr($options['target_info']); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
            <div class="todo-user-selector-container">
                <div class="dus-header">
                    <h3 class="dus-title"><?php echo esc_html($options['title']); ?></h3>
                    <button type="button" class="dus-close">✕</button>
                </div>
                <div class="dus-toolbar">
                    <div class="dus-search">
                        <input type="search" id="dus-search-input" placeholder="支持用户名 / ID / 邮箱 搜索...">
                        <button type="button" class="button button-secondary" id="dus-search-btn">搜索</button>
                    </div>
                    <div class="dus-actions">
                        <label class="dus-select-all" style="display: none;">
                            <input type="checkbox" id="dus-select-all"> 全选
                        </label>
                    </div>
                </div>
                <div class="dus-body">
                    <div id="dus-user-list" class="dus-user-list">
                        <div class="no-items">
                            <p>请输入关键词进行搜索。</p>
                        </div>
                    </div>
                </div>
                <div class="dus-footer">
                    <button type="button" class="button button-secondary dus-cancel"><?php echo esc_html($options['cancel_text']); ?></button>
                    <button type="button" class="button button-primary dus-confirm"><?php echo esc_html($options['button_text']); ?></button>
                </div>
            </div>
        </div>

        <script type="text/javascript">
        (function(){
            const targetDoc = (function(){
                try {
                    if (window.parent && window.parent.document && window.parent !== window) {
                        return window.parent.document;
                    }
                } catch (e) {}
                return document;
            })();

            const targetWin = (function(){
                try {
                    if (targetDoc && targetDoc.defaultView) return targetDoc.defaultView;
                } catch (e) {}
                try {
                    if (window.parent && window.parent !== window) return window.parent;
                } catch (e) {}
                return window;
            })();

            let overlay = targetDoc.getElementById('<?php echo esc_js($options['modal_id']); ?>') || document.getElementById('<?php echo esc_js($options['modal_id']); ?>');
            if (!overlay) return;

            if (overlay.ownerDocument !== targetDoc) {
                try {
                    targetDoc.body.appendChild(overlay);
                } catch (e) {}
            }

            const listEl = overlay.querySelector('#dus-user-list');
            const searchInput = overlay.querySelector('#dus-search-input');
            const searchBtn = overlay.querySelector('#dus-search-btn');
            const btnClose = overlay.querySelector('.dus-close');
            const btnCancel = overlay.querySelector('.dus-cancel');
            const btnConfirm = overlay.querySelector('.dus-confirm');
            const selectAllWrap = overlay.querySelector('.dus-select-all');
            const selectAll = overlay.querySelector('#dus-select-all');

            let currentPage = 1;
            let multiple = overlay.dataset.multiple === 'true';
            let callbackName = overlay.dataset.callback || '';
            let targetInput = overlay.dataset.targetInput || '';
            let targetInfo = overlay.dataset.targetInfo || '';

            let prefilledIds = new Set();

            function getCSRFToken(){
                try {
                    if (window.todoCSRF && todoCSRF.tokens && todoCSRF.tokens.user_search && todoCSRF.tokens.user_search.value) {
                        return todoCSRF.tokens.user_search.value;
                    }
                } catch(e){}
                return overlay.dataset.nonce || '';
            }

            function open(opts){
                opts = opts || {};
                prefilledIds.clear();
                overlay.classList.add('show');
                if (!overlay.dataset.prevOverflow) {
                    overlay.dataset.prevOverflow = targetDoc.body.style.overflow || '';
                }
                targetDoc.body.style.overflow = 'hidden';
                targetDoc.body.classList.add('show-modal');
                if(!targetDoc.body.getAttribute('data-overlay')) {
                    targetDoc.body.setAttribute('data-overlay', 'user-selector');
                }
                const prefill = (opts.prefill !== undefined && opts.prefill !== null) ? String(opts.prefill).trim() : '';
                if (prefill !== '') {
                    const matched = prefill.match(/\d+/g);
                    if (matched) {
                        matched.forEach(id => prefilledIds.add(parseInt(id, 10)));
                    }
                    searchInput.value = prefill;
                    searchInput.focus();
                    search(1);
                    return;
                }
                searchInput.value = '';
                searchInput.focus();
                renderEmpty('请输入关键词进行搜索');
            }

            function close(){
                overlay.classList.remove('show');
                if(targetDoc.body.getAttribute('data-overlay') === 'user-selector') {
                    targetDoc.body.style.overflow = overlay.dataset.prevOverflow || '';
                    targetDoc.body.classList.remove('show-modal');
                    targetDoc.body.removeAttribute('data-overlay');
                }
                delete overlay.dataset.prevOverflow;
            }

            function renderEmpty(msg){
                listEl.innerHTML = '<div class="no-items"><p>' + (msg || '暂无数据。') + '</p></div>';
                toggleSelectAll(false);
            }

            function toggleSelectAll(show){
                if (multiple) {
                    selectAllWrap.style.display = show ? 'block' : 'none';
                } else {
                    selectAllWrap.style.display = 'none';
                }
            }

            function search(page){
                const keyword = searchInput.value.trim();
                currentPage = page || 1;
                listEl.innerHTML = '<div class="no-items"><p>搜索中...</p></div>';

                const formData = new FormData();
                formData.append('search', keyword);
                formData.append('page', currentPage);
                const token = getCSRFToken();
                formData.append('_todononce', token);

                fetch('<?php echo todo_admin_url('ajax/user-search.php'); ?>', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                }).then(r => r.text()).then(t => {
                    let data;
                    try { data = JSON.parse(t); } catch(e) { throw new Error('响应为空或非JSON'); }
                    if (data && data.error) throw new Error(data.error);
                    if (!data || data.success !== true) throw new Error((data && data.message) || '搜索失败');
                    renderList((data.data && data.data.users) ? data.data.users : []);
                }).catch(err => {
                    console.error('用户搜索失败:', err);
                    renderEmpty('搜索失败: ' + err.message);
                });
            }

            function renderList(users){
                if (!users.length) { renderEmpty('未找到匹配的用户'); return; }
                toggleSelectAll(true);
                let html = '';
                users.forEach(u => {
                    const uid = parseInt(u.id, 10);
                    const isChecked = prefilledIds.has(uid);
                    html += `
                        <div class="dus-item ${isChecked ? 'is-selected' : ''}" data-id="${u.id}" data-name="${u.display_name || u.username}" data-email="${u.email}" data-balance="${(u.balance||0)}">
                            ${multiple ? `<input type="checkbox" class="dus-check" ${isChecked ? 'checked' : ''}>` : ''}
                            <div class="dus-info">
                                <div class="dus-name">${u.display_name || u.username} <small style="color:#999;">#${u.id}</small></div>
                                <div class="dus-meta">${u.email ? u.email + ' | ' : ''}余额：<?php echo todo_get_default_currency_config()['symbol']; ?>${Number(u.balance||0).toFixed(2)}</div>
                            </div>
                        </div>`;
                });
                listEl.innerHTML = html;
                updateSelectAllState();
            }

            function getSelected(){
                const items = listEl.querySelectorAll('.dus-item');
                const selected = [];
                items.forEach(el => {
                    if (multiple) {
                        const c = el.querySelector('.dus-check');
                        if (c && c.checked) { selected.push(elData(el)); }
                    }
                });
                return selected;
            }

            function elData(el){
                return {
                    id: parseInt(el.getAttribute('data-id')), 
                    name: el.getAttribute('data-name'),
                    email: el.getAttribute('data-email'),
                    balance: parseFloat(el.getAttribute('data-balance') || '0')
                };
            }

            function updateSelectAllState() {
                if (!multiple || !selectAll) return;
                const checks = listEl.querySelectorAll('.dus-check');
                if (checks.length === 0) {
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                    return;
                }
                const checkedCount = Array.from(checks).filter(c => c.checked).length;
                if (checkedCount === 0) {
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                } else if (checkedCount === checks.length) {
                    selectAll.checked = true;
                    selectAll.indeterminate = false;
                } else {
                    selectAll.checked = false;
                    selectAll.indeterminate = true;
                }
            }

            // 事件绑定
            btnClose.addEventListener('click', close);
            btnCancel.addEventListener('click', close);
            searchBtn.addEventListener('click', () => search(1));
            searchInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); search(1); }});
            searchInput.addEventListener('search', function() {
                if (this.value.trim() === '') {
                    renderEmpty('请输入关键词进行搜索');
                } else {
                    search(1);
                }
            });
            selectAll.addEventListener('change', function(){
                listEl.querySelectorAll('.dus-check').forEach(c => { 
                    c.checked = selectAll.checked; 
                    const item = c.closest('.dus-item');
                    if (item) {
                        const uid = parseInt(item.getAttribute('data-id'), 10);
                        if (selectAll.checked) {
                            prefilledIds.add(uid);
                            item.classList.add('is-selected');
                        } else {
                            prefilledIds.delete(uid);
                            item.classList.remove('is-selected');
                        }
                    }
                });
                updateSelectAllState();
            });

            listEl.addEventListener('click', function(e){
                const item = e.target.closest('.dus-item');
                if (!item) return;
                const uid = parseInt(item.getAttribute('data-id'), 10);
                if (multiple) {
                    const c = item.querySelector('.dus-check');
                    if (e.target !== c) { 
                        c.checked = !c.checked; 
                    }
                    if (c.checked) {
                        prefilledIds.add(uid);
                        item.classList.add('is-selected');
                    } else {
                        prefilledIds.delete(uid);
                        item.classList.remove('is-selected');
                    }
                    updateSelectAllState();
                } else {
                    // 单选：直接确认并回填
                    const data = elData(item);
                    applySelection([data]);
                    close();
                }
            });

            btnConfirm.addEventListener('click', function(){
                if (multiple) {
                    const sels = getSelected();
                    if (!sels.length) {
                        const utils = (targetWin.TodoCMS && targetWin.TodoCMS.Utils) ? targetWin.TodoCMS.Utils : (window.TodoCMS && window.TodoCMS.Utils ? window.TodoCMS.Utils : null);
                        if (utils && typeof utils.showMessage === 'function') {
                            utils.showMessage('请至少选择一个用户', 'warning');
                        }
                        return;
                    }
                    applySelection(sels);
                    close();
                }
            });

            function applySelection(sels){
                // 优先回调
                if (callbackName && typeof targetWin[callbackName] === 'function') {
                    try { targetWin[callbackName](sels); } catch(e) { console.error(e); }
                    return;
                }
                // 回填到输入框
                if (targetInput) {
                    try {
                        const input = targetDoc.querySelector(targetInput);
                        if (input) {
                            input.value = multiple ? sels.map(s => s.id).join(",") : (sels[0] ? sels[0].id : '');
                            input.dispatchEvent(new Event('change'));
                        }
                    } catch(e) { console.error(e); }
                }
                // 展示信息
                if (targetInfo) {
                    try {
                        const box = targetDoc.querySelector(targetInfo);
                        if (box) {
                            let html = '';
                            sels.forEach(s => {
                                html += `<div style="font-size: 14px;background-color: #efeff5;padding: 5px 10px;border-radius: 3px;"><p>${s.name}<em class="description m-0"> #${s.id}</em></p><p class="description m-0">${s.email}</p>余额：<?php echo todo_get_default_currency_config()['symbol']; ?>${Number(s.balance||0).toFixed(2)}</div>`;
                            });
                            box.innerHTML = html;
                            box.style.display = 'flex';
                        }
                    } catch(e) { console.error(e); }
                }
            }

            // 对外暴露打开方法
            targetWin.todoOpenUserSelector = function(opts){
                opts = opts || {};
                multiple = !!opts.multiple;
                overlay.dataset.multiple = multiple ? 'true' : 'false';
                callbackName = opts.callback || '';
                targetInput = opts.targetInput || '';
                targetInfo = opts.targetInfo || '';
                if (opts.title) overlay.querySelector('.dus-title').textContent = opts.title;
                open(opts);
            };

            // 自动绑定具有 .todo-user-select-btn 的按钮
            targetDoc.addEventListener('click', function(e){
                const btn = e.target.closest('.todo-user-select-btn');
                if (!btn) return;
                const ti = btn.getAttribute('data-target-input') || '';
                const tf = btn.getAttribute('data-target-info') || '';
                const mul = (btn.getAttribute('data-multiple') || 'false') === 'true';
                const title = btn.getAttribute('data-title') || '选择用户';
                if (typeof targetWin.todoOpenUserSelector === 'function') {
                    let prefill = '';
                    if (ti) {
                        try {
                            const input = targetDoc.querySelector(ti);
                            if (input && typeof input.value === 'string') {
                                prefill = input.value.trim();
                            }
                        } catch (e) {}
                    }
                    targetWin.todoOpenUserSelector({ multiple: mul, targetInput: ti, targetInfo: tf, title: title, prefill: prefill });
                }
            }, true);
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}

function todo_user_selector_modal($options = array()) {
    echo TodoUserSelectorModal::render($options);
}
