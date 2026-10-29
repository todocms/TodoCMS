/**
 * 标对导入插件 JavaScript
 */

(function ($) {
    'use strict';

    // --- 跨框架代理设置 (Cross-Frame Proxy Setup) ---
    // 确保在iframe中运行时，TodoModal和TodoCMS.Utils代理到父窗口
    if (window.parent && window.parent !== window) {
        try {
            // 代理 TodoModal
            if (typeof window.parent.TodoModal !== 'undefined') {
                var CrossFrameTodoModal = function(options) {
                    return new window.parent.TodoModal(options);
                };
                // 代理静态方法
                var methodsToProxy = [
                    'confirm', 'deleteConfirm', 'alert', 
                    'success', 'error', 'warning', 'info',
                    'showLoading', 'hideLoading', 'progress', 'toast', 'form'
                ];
                methodsToProxy.forEach(function(method) {
                    if (typeof window.parent.TodoModal[method] === 'function') {
                        CrossFrameTodoModal[method] = function() {
                            return window.parent.TodoModal[method].apply(window.parent.TodoModal, arguments);
                        };
                    }
                });
                window.TodoModal = CrossFrameTodoModal;
            }

            // 代理 TodoCMS.Utils
            if (typeof window.parent.TodoCMS !== 'undefined' && window.parent.TodoCMS.Utils) {
                if (!window.TodoCMS) window.TodoCMS = {};
                if (!window.TodoCMS.Utils) window.TodoCMS.Utils = {};
                
                ['showMessage', 'confirm'].forEach(function(method) {
                     if (typeof window.parent.TodoCMS.Utils[method] === 'function') {
                         window.TodoCMS.Utils[method] = function() {
                             return window.parent.TodoCMS.Utils[method].apply(window.parent.TodoCMS.Utils, arguments);
                         };
                     }
                });
            }

            // 代理 TodoRequest
            if (typeof window.parent.TodoRequest !== 'undefined') {
                window.TodoRequest = window.parent.TodoRequest;
            }
        } catch (e) {
            console.warn('[DataMapping] 跨框架代理设置失败:', e);
        }
    }
    
    // 辅助函数：在当前上下文或父窗口查找元素
    function $find(selector) {
        // 优先在当前文档查找
        var $el = $(selector);
        if ($el.length > 0) return $el;

        // 如果在iframe中，尝试在父窗口文档查找（用于查找弹窗元素）
        if (window.parent && window.parent !== window) {
            try {
                var $parentEl = $(window.parent.document).find(selector);
                if ($parentEl.length > 0) return $parentEl;
            } catch (e) {
                // 忽略跨域错误
            }
        }
        return $el; // 返回空集或本地集
    }

    // 配置
    const config = window.dataMappingConfig || {
        ajaxurl: (window.TODO && window.TODO.join ? window.TODO.join((window.TODO.ADMIN_BASE || (window.location.origin + '/admin')), 'admin-ajax.php') : '/admin/admin-ajax.php'),
        nonce: '',
        nonces: {
            get_database_structure: '',
            parse_sql_file: '',
            get_import_logs: '',
            load_mapping_template: '',
            save_mapping_template: '',
            execute_import: '',
            get_import_progress: '',
            clear_import_logs: ''
        },
        messages: {
            upload_success: '文件上传成功',
            parse_error: 'SQL文件解析失败',
            mapping_saved: '映射模板保存成功',
            import_success: '数据导入成功',
            import_error: '数据导入失败'
        }
    };

    // 全局变量
    let sourceStructure = null;
    let targetStructure = null;
    let mappingRelations = [];
    let currentTemplate = null;
    let uploadedSqlFile = null; // 保存上传的SQL文件信息
    // 进度轮询定时器
    let importProgressTimer = null;
    // 记录上次已显示的任务错误长度，避免轮询时重复追加
    let lastJobErrorLength = 0;
    // 通知清除定时器
    let noticeClearTimer = null;
    // 倒计时定时器
    let countdownTimer = null;
    // 倒计时秒数
    let countdownSeconds = 0;
    // 上一次的进度百分比（用于平滑随机波动）
    let lastProgressPercentage = 0;
    // 弹窗实例
    let templateModal = null;
    let progressModal = null;

    // 初始化
    $(document).ready(function () {
        if (!document.getElementById('execute-import-btn')) {
            return;
        }
        initializePlugin();
        bindEvents();
        loadTargetStructure();
    });

    /**
     * 初始化插件
     */
    function initializePlugin() {
        // 初始化拖拽功能
        initializeDragDrop();

        // 初始化标签页
        initializeTabs();

        // 检查是否有保存的状态
        restoreState();
    }

    /**
     * 绑定事件
     */
    function bindEvents() {
        // 先解绑所有事件，防止重复绑定
        $('#upload-sql-btn').off('click');
        $('#sql-file-input').off('change');
        $('#load-template-btn').off('click');
        $('#save-template-btn').off('click');
        $('#clear-mapping-btn').off('click');

        // 文件上传
        $('#upload-sql-btn').on('click', function () {
            $('#sql-file-input').click();
        });

        $('#sql-file-input').on('change', handleFileUpload);

        // 模板管理
        $('#load-template-btn').on('click', showLoadTemplateModal);
        $('#save-template-btn').on('click', showSaveTemplateModal);

        // 清空映射
        $('#clear-mapping-btn').on('click', clearAllMappings);

        // 执行导入
        $('#execute-import-btn').on('click', executeImport);

        // 标签页切换
        $('.tab-button').on('click', function () {
            switchTab($(this).data('tab'));
        });

        // 树节点展开/折叠
        $(document).on('click', '.tree-toggle', toggleTreeNode);

        // 移除映射关系
        $(document).on('click', '.remove-mapping', removeMappingRelation);

        // 清空导入日志
        $('#clear-logs-btn').on('click', clearImportLogs);

        // 一键初始化/全量同步数据库结构
        $('#btn-sync-db-schema').off('click').on('click', syncDatabaseSchema);
    }

    /**
     * 初始化拖拽功能
     */
    function initializeDragDrop() {
        // 源字段可拖拽
        $(document).on('mousedown', '.source-panel .field-node', function (e) {
            if (e.which === 1) { // 左键
                initiateDrag($(this), e);
            }
        });

        // 目标字段可放置
        $(document).on('dragover', '.target-panel .field-node', function (e) {
            e.preventDefault();
            $(this).addClass('drop-target');
        });

        $(document).on('dragleave', '.target-panel .field-node', function (e) {
            $(this).removeClass('drop-target');
        });

        $(document).on('drop', '.target-panel .field-node', function (e) {
            e.preventDefault();
            handleFieldDrop($(this));
        });
    }

    /**
     * 开始拖拽
     */
    function initiateDrag($element, event) {
        $element.addClass('dragging');

        // 创建拖拽助手
        const $helper = $element.clone().addClass('drag-helper');
        $('body').append($helper);

        // 跟随鼠标移动
        $(document).on('mousemove.drag', function (e) {
            $helper.css({
                position: 'fixed',
                left: e.clientX + 10,
                top: e.clientY + 10,
                zIndex: 10000,
                pointerEvents: 'none'
            });
        });

        // 结束拖拽
        $(document).on('mouseup.drag', function () {
            cleanupDragState();
        });
    }

    /**
     * 清理拖拽状态
     */
    function cleanupDragState() {
        $('.source-panel .field-node').removeClass('dragging');
        $('.drag-helper').remove();
        $('.drop-target').removeClass('drop-target');
        $(document).off('.drag');
    }

    /**
     * 处理字段放置
     */
    function handleFieldDrop($targetField) {
        const $sourceField = $('.source-panel .field-node.dragging');

        if ($sourceField.length === 0) {
            return;
        }

        const sourceData = getFieldData($sourceField);
        const targetData = getFieldData($targetField);

        // 立即清理拖拽状态
        cleanupDragState();

        // 检查是否已存在映射
        const existingMapping = mappingRelations.find(m =>
            m.target.table === targetData.table && m.target.field === targetData.field
        );

        if (existingMapping) {
            showNotice('目标字段已存在映射关系', 'warning');
            return;
        }

        // 创建映射关系
        const mapping = {
            id: generateMappingId(),
            source: sourceData,
            target: targetData,
            created: new Date().toISOString()
        };

        mappingRelations.push(mapping);

        // 更新界面
        updateMappingDisplay();
        updateFieldStatus();
        updateImportButton();

        showNotice('映射关系创建成功', 'success');
    }

    /**
     * 获取字段数据
     */
    function getFieldData($fieldElement) {
        return {
            database: $fieldElement.data('database'),
            table: $fieldElement.data('table'),
            field: $fieldElement.data('field'),
            type: $fieldElement.data('type'),
            nullable: $fieldElement.data('nullable'),
            default: $fieldElement.data('default')
        };
    }

    /**
     * 生成映射ID
     */
    function generateMappingId() {
        return 'mapping_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }

    /**
     * 更新映射关系显示
     */
    function updateMappingDisplay() {
        const $container = $('#mapping-relations-list');

        if (mappingRelations.length === 0) {
            $container.html(`
                <div class="empty-relations">
                    <span class="toicon" style="font-size: 40px; line-height: normal;">☜</span>
                    <p>从左边拖拽字段建立映射关系</p>
                </div>
            `);
        } else {
            let html = '';
            mappingRelations.forEach(mapping => {
                const rawDefault = (mapping.source && mapping.source.default != null) ? String(mapping.source.default) : '';
                const defaultValue = rawDefault.replace(/^''$/, '');
                const hasDefaultValue = defaultValue.trim() !== '';
                const shouldShowInput = hasDefaultValue; // 默认仅当有值时才显示
                // HTML 属性转义，防止value中的引号破坏HTML结构
                const escapedDefaultValue = defaultValue.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                
                html += `
                    <div class="mapping-relation" data-mapping-id="${mapping.id}">
                        <button class="remove-mapping" title="移除映射">✕</button>
                        <div class="maskline"></div>
                        <div class="flex flex-col relative">
                            <div class="source-field">
                                <em>${mapping.source.table}</em>
                                <b class="flex flex-col">
                                    <div class="flex items-center justify-between">
                                        ${mapping.source.field}
                                        <span class="btn toggle-default-input" style="${shouldShowInput ? 'visibility: hidden;' : ''}"
                                              data-mapping-id="${mapping.id}" 
                                              title="不等式">≠</span>
                                    </div>
                                    <input type="text" 
                                           class="source-default-value" 
                                           placeholder="替换值（支持正则）"
                                           data-mapping-id="${mapping.id}"
                                           style="padding: 5px;min-width: auto;background: none;border: none;border-bottom: 1px solid #eceef1;border-radius: 0;${shouldShowInput ? '' : 'display: none;'}" 
                                           value="${escapedDefaultValue}" />
                                </b>
                                <span class="field-type ${mapping.source.type}">${mapping.source.type}</span>
                            </div>
                            <div class="target-field">
                                <em>${mapping.target.table}</em><b>${mapping.target.field}</b>
                                <span class="field-type ${mapping.target.type}">${mapping.target.type}</span>
                            </div>
                        </div>
                    </div>
                `;
            });
            $container.html(html);
            $container.scrollTop($container[0].scrollHeight);
            
            // 绑定不等式按钮点击事件，切换输入框显示/隐藏
            $container.find('.toggle-default-input').on('click', function() {
                const mappingId = $(this).data('mapping-id');
                const $input = $container.find(`.source-default-value[data-mapping-id="${mappingId}"]`);
                const $btn = $(this);
                
                if ($input.is(':visible')) {
                    // 隐藏输入框
                    $input.hide();
                    $btn.css('visibility', 'visible');
                    // 如果输入框为空，清除默认值
                    const currentValue = $input.val().trim();
                    if (!currentValue) {
                        const mapping = mappingRelations.find(m => m.id === mappingId);
                        if (mapping && mapping.source) {
                            delete mapping.source.default;
                            saveState();
                        }
                    }
                } else {
                    // 显示输入框并聚焦
                    $input.show().focus();
                    $btn.css('visibility', 'hidden');
                }
            });
            
            // 绑定输入框变化事件，更新映射关系中的默认值
            $container.find('.source-default-value').on('input change', function() {
                const mappingId = $(this).data('mapping-id');
                const defaultValue = $(this).val().trim();
                const mapping = mappingRelations.find(m => m.id === mappingId);
                if (mapping) {
                    if (defaultValue) {
                        mapping.source.default = defaultValue;
                        // 确保不等式按钮是激活状态（隐藏按钮，显示输入框）
                        $container.find(`.toggle-default-input[data-mapping-id="${mappingId}"]`).css('visibility', 'hidden');
                    } else {
                        delete mapping.source.default;
                        // 如果值为空，取消激活状态（但不隐藏，因为用户可能还在编辑）
                        $container.find(`.toggle-default-input[data-mapping-id="${mappingId}"]`).css('visibility', 'visible');
                    }
                    // 保存状态
                    saveState();
                }
            });
            
            // 绑定输入框失焦事件，如果为空则隐藏
            $container.find('.source-default-value').on('blur', function() {
                const mappingId = $(this).data('mapping-id');
                const value = $(this).val().trim();
                if (!value) {
                    // 如果没有值，隐藏输入框并显示按钮
                    $(this).hide();
                    $container.find(`.toggle-default-input[data-mapping-id="${mappingId}"]`).css('visibility', 'visible');
                    // 清除映射关系中的默认值
                    const mapping = mappingRelations.find(m => m.id === mappingId);
                    if (mapping && mapping.source) {
                        delete mapping.source.default;
                        saveState();
                    }
                }
            });
        }

        // 更新计数
        $('.mapping-count').text(`${mappingRelations.length} 个`);
    }

    /**
     * 更新字段状态
     */
    function updateFieldStatus() {
        // 重置所有字段状态
        $('.field-node').removeClass('mapped');

        // 标记已映射的字段
        mappingRelations.forEach(mapping => {
            $(`.source-panel .field-node[data-table="${mapping.source.table}"][data-field="${mapping.source.field}"]`)
                .addClass('mapped');
            $(`.target-panel .field-node[data-table="${mapping.target.table}"][data-field="${mapping.target.field}"]`)
                .addClass('mapped');
        });
    }

    /**
     * 更新导入按钮状态
     */
    function updateImportButton() {
        const hasSource = sourceStructure !== null;
        const hasMappings = mappingRelations.length > 0;
        const hasFile = uploadedSqlFile && uploadedSqlFile.path;

        const enabled = !!(hasSource && hasMappings && hasFile);
        $('#execute-import-btn').prop('disabled', !enabled);
        $('#save-template-btn').prop('disabled', !(hasSource && hasMappings));
    }

    /**
     * 移除映射关系
     */
    function removeMappingRelation(e) {
        e.stopPropagation();

        const mappingId = $(this).closest('.mapping-relation').data('mapping-id');
        mappingRelations = mappingRelations.filter(m => m.id !== mappingId);

        updateMappingDisplay();
        updateFieldStatus();
        updateImportButton();

        showNotice('映射关系已移除', 'info');
    }

    /**
     * 清空所有映射
     */
    function clearAllMappings() {
        if (mappingRelations.length === 0) {
            return;
        }

        const clearMappings = function() {
            mappingRelations = [];
            updateMappingDisplay();
            updateFieldStatus();
            updateImportButton();
            showNotice('所有映射关系已清空', 'info');
        };
        
        if (typeof TodoModal !== 'undefined') {
            TodoModal.confirm({
                title: '确认清空映射',
                message: '确定要清空所有映射关系吗？',
                onConfirm: clearMappings
            });
        } else {
            // Fallback to TodoCMS.Utils if available
            if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils && TodoCMS.Utils.confirm) {
                TodoCMS.Utils.confirm('确定要清空所有映射关系吗？', clearMappings);
            } else {
                console.warn('TodoModal not available for confirmation');
                // Auto-confirm or fail safely? 
                // To strictly follow "no browser confirm", we avoid window.confirm.
                // But we shouldn't block the action if it's critical? 
                // Ideally TodoModal should be available.
            }
        }
    }

    /**
     * 处理文件上传
     */
    /**
     * 格式化文件大小
     */
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }

    function handleFileUpload(e) {
        const file = e.target.files[0];
        if (!file) {
            return;
        }

        if (!file.name.toLowerCase().endsWith('.sql')) {
            showNotice('请选择SQL文件', 'error');
            // 重置文件输入框，允许重新选择
            $(e.target).val('');
            return;
        }

        // 检查文件大小
        const maxSize = config.uploadLimits ? config.uploadLimits.maxSize : (8 * 1024 * 1024); // 默认8MB
        if (file.size > maxSize) {
            const maxSizeFormatted = config.uploadLimits ? config.uploadLimits.maxSizeFormatted : '8MB';
            const fileSizeFormatted = formatFileSize(file.size);
            showNotice(`文件大小 ${fileSizeFormatted} 超过服务器限制 ${maxSizeFormatted}，请选择较小的文件或联系管理员调整PHP配置（post_max_size 和 upload_max_filesize）`, 'error');
            // 重置文件输入框，允许重新选择
            $(e.target).val('');
            return;
        }

        const formData = new FormData();
        formData.append('sql_file', file);
        formData.append('action', 'parse_sql_file');
        formData.append('_parse_sql_file_todononce', config.nonces.parse_sql_file);

        // 清除之前的状态，显示加载状态
        showLoadingState('#source-structure-tree', '正在解析SQL文件...');

        TodoRequest.ajax({
            url: config.ajaxurl,
            method: 'POST',
            data: formData,
            onSuccess: function (response) {
                if (response.data && response.data.structure) {
                    sourceStructure = response.data.structure;
                    uploadedSqlFile = {
                        name: file.name,
                        path: response.data.filepath || response.data.filename || file.name,
                        size: file.size
                    };
                    renderSourceStructure(sourceStructure);
                    updateImportButton();
                    saveState();
                    showNotice(config.messages.upload_success, 'success');
                    $('#sql-file-input').val('');
                } else {
                    const errorMsg = config.messages.parse_error;
                    showNotice(errorMsg, 'error');
                    resetSourceStructureToInitial();
                    $('#sql-file-input').val('');
                }
            },
            onError: function (error) {
                console.error('[handleFileUpload] AJAX失败:', error);
                let serverMsg = (error && error.data && error.data.message) || '文件上传失败';
                showNotice(serverMsg, 'error');
                resetSourceStructureToInitial();
                $('#sql-file-input').val('');
            }
        });
    }
    /**
     * 加载目标数据库结构
     */
    function loadTargetStructure() {
        // 显示加载状态
        $('#target-structure-tree').html('<div class="loading-state"><span class="toicon spin">✤</span><p>正在加载数据库结构...</p></div>');

        TodoRequest.ajax({
            url: config.ajaxurl,
            method: 'POST',
            data: {
                action: 'get_database_structure',
                _get_database_structure_todononce: config.nonces.get_database_structure
            },
            onSuccess: function (response) {
                var struct = null;
                if (response.data) {
                    struct = response.data.structure || response.data; // 兼容服务端返回两种结构
                }
                if (struct) {
                    targetStructure = struct;
                    renderTargetStructure(targetStructure);
                } else {
                    showNotice('加载数据库结构失败: 数据无效', 'error');
                    showEmptyState('#target-structure-tree', '无法加载数据库结构');
                }
            },
            onError: function (error) {
                console.error('[loadTargetStructure] AJAX失败:', error);
                let errorMsg = (error && error.data && error.data.message) || '加载数据库结构失败';
                showNotice(errorMsg, 'error');
                showEmptyState('#target-structure-tree', '无法加载数据库结构');
            }
        });
    }

    /**
     * 渲染源数据库结构
     */
    function renderSourceStructure(structure) {
        if (!structure || typeof structure !== 'object') {
            $('#source-structure-tree').html('<div class="empty-state">暂无数据结构</div>');
            return;
        }
        const html = renderDatabaseTree(structure, 'source');
        $('#source-structure-tree').html(html);
    }

    /**
     * 渲染目标数据库结构
     */
    function renderTargetStructure(structure) {
        const html = renderDatabaseTree(structure, 'target');
        $('#target-structure-tree').html(html);
    }

    /**
     * 渲染数据库树
     */
    function renderDatabaseTree(structure, type) {
        if (!structure || typeof structure !== 'object') {
            return '<div class="empty-state">数据结构无效</div>';
        }

        let html = '';

        Object.keys(structure).forEach(dbName => {
            const database = structure[dbName];
            html += `
                <div class="tree-node database-node">
                    <div class="tree-node-content" data-level="database">
                        <span class="tree-toggle">▼</span>
                        <span class="tree-icon toicon" style="font-size: 26px;line-height: 0;margin: 2px 0 0 -3px;">▤</span>
                        <span class="tree-label">${dbName}</span>
                        <span class="tree-meta">${database.tables ? Object.keys(database.tables).length : 0} 表</span>
                    </div>
                    <div class="tree-children expanded">
                        ${database.tables ? renderTablesTree(database.tables, dbName, type) : '<div class="empty-state">暂无表</div>'}
                    </div>
                </div>
            `;
        });

        return html;
    }

    /**
     * 渲染表树
     */
    function renderTablesTree(tables, dbName, type) {
        if (!tables || typeof tables !== 'object') {
            return '<div class="empty-state">表结构无效</div>';
        }

        let html = '';

        Object.keys(tables).forEach(tableName => {
            const table = tables[tableName];
            html += `
                <div class="tree-node table-node">
                    <div class="tree-node-content" data-level="table">
                        <span class="tree-toggle">▶</span>
                        <span class="tree-icon toicon" style="font-size: 26px;line-height: 0;margin-top: -3px;">◲</span>
                        <div class="flex flex-wrap items-center">
                            <span class="tree-label">${tableName}</span>
                            <span class="tree-meta">${table.fields ? table.fields.length : 0} 字段</span>
                        </div>
                    </div>
                    <div class="tree-children">
                        ${table.fields ? renderFieldsTree(table.fields, dbName, tableName, type) : '<div class="empty-state">暂无字段</div>'}
                    </div>
                </div>
            `;
        });

        return html;
    }

    /**
     * 渲染字段树
     */
    function renderFieldsTree(fields, dbName, tableName, type) {
        if (!fields || !Array.isArray(fields)) {
            return '<div class="empty-state">字段数据无效</div>';
        }

        let html = '';

        fields.forEach(field => {
            const draggable = type === 'source' ? 'draggable="true"' : '';
            const droppable = type === 'target' ? 'droppable' : '';

            html += `
                <div class="tree-node field-node ${droppable}" 
                     ${draggable}
                     data-database="${dbName}"
                     data-table="${tableName}"
                     data-field="${field.name}"
                     data-type="${field.type}"
                     data-nullable="${field.nullable}"
                     data-default="${field.default || ''}">
                    <div class="tree-node-content" data-level="field">
                        <span class="tree-icon toicon" style="font-size: 12px;line-height: 0;margin: 3px 0 0 6px;">■</span>
                        <span class="tree-label">${field.name}</span>
                        <span class="field-type ${field.type}">${field.type}</span>
                        ${field.nullable ? '<span class="tree-meta">NULL</span>' : ''}
                    </div>
                </div>
            `;
        });

        return html;
    }

    /**
     * 切换树节点
     */
    function toggleTreeNode(e) {
        e.stopPropagation();

        const $toggle = $(this);
        const $content = $toggle.closest('.tree-node-content');
        const $children = $content.siblings('.tree-children');

        if ($children.hasClass('expanded')) {
            $children.removeClass('expanded');
            $toggle.text('▶');
            $content.removeClass('expanded');
        } else {
            $children.addClass('expanded');
            $toggle.text('▼');
            $content.addClass('expanded');
        }
    }

    /**
     * 初始化标签页
     */
    function initializeTabs() {
        $('.tab-button').first().addClass('active');
        $('.tab-panel').first().addClass('active');
    }

    /**
     * 切换标签页
     */
    function switchTab(tabName) {
        $('.tab-button').removeClass('active');
        $('.tab-panel').removeClass('active');

        $(`.tab-button[data-tab="${tabName}"]`).addClass('active');
        $(`#${tabName}-tab`).addClass('active');

        // 根据标签页加载内容
        if (tabName === 'logs') {
            loadImportLogs();
        }
    }

    /**
     * 加载导入日志
     */
    function loadImportLogs() {
        TodoRequest.ajax({
            url: config.ajaxurl,
            method: 'POST',
            data: {
                action: 'get_import_logs',
                _get_import_logs_todononce: config.nonces.get_import_logs
            },
            onSuccess: function (response) {
                if (response.success && response.data && response.data.logs) {
                    renderImportLogs(response.data.logs);
                } else {
                    $('#import-logs-content').html('<p>暂无导入日志</p>');
                }
            },
            onError: function (error) {
                console.error('[loadImportLogs] AJAX失败:', error);
                $('#import-logs-content').html('<p>加载日志失败</p>');
            }
        });
    }

    /**
     * 渲染导入日志
     */
    function renderImportLogs(logs) {
        if (!logs || logs.length === 0) {
            $('#import-logs-content').html('<p>暂无导入日志</p>');
            $('#clear-logs-btn').hide();
            return;
        }

        $('#clear-logs-btn').show();

        let html = '<table class="admin-table widefat fixed striped">';
        html += '<thead><tr><th>时间</th><th>状态</th><th>记录数</th></tr></thead>';
        html += '<tbody>';

        logs.forEach(log => {
            const statusClass = {
                'completed': 'success',
                'failed': 'error',
                'processing': 'warning',
                'pending': 'info'
            }[log.import_status] || 'info';

            html += `
                <tr>
                    <td>${formatDateTime(log.created_time)}</td>
                    <td><span class="status-${statusClass}">${log.import_status}</span></td>
                    <td>${log.imported_records}/${log.total_records}</td>
                </tr>
            `;
        });

        html += '</tbody></table>';
        $('#import-logs-content').html(html);
    }

    /**
     * 清空导入日志
     */
    function clearImportLogs() {
        const clearLogs = function() {
            TodoRequest.ajax({
                url: config.ajaxurl,
                method: 'POST',
                data: {
                    action: 'clear_import_logs',
                    _clear_import_logs_todononce: config.nonces.clear_import_logs
                },
                onSuccess: function (response) {
                    showNotice('日志已清空', 'success');
                    loadImportLogs(); // 重新加载列表
                },
                onError: function (error) {
                    showNotice(error.message || '清空日志失败', 'error');
                }
            });
        };
        
        if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils && TodoCMS.Utils.confirm) {
            TodoCMS.Utils.confirm('确定要清空所有导入日志吗？', clearLogs);
        } else if (confirm('确定要清空所有导入日志吗？')) {
            clearLogs();
        }
    }

    /**
     * 显示加载模板模态框
     */
    function showLoadTemplateModal() {
        if (typeof TodoModal === 'undefined') {
            showNotice('弹窗组件未加载', 'error');
            return;
        }

        // 创建模板列表视图 HTML
        const listViewHtml = `
            <div id="template-list-view">
                <table class="admin-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>模板名称</th>
                            <th>描述</th>
                            <th>创建时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody id="template-list-tbody">
                        <!-- 动态加载 -->
                    </tbody>
                </table>
            </div>
        `;

        // 如果弹窗已存在，先销毁
        if (templateModal) {
            templateModal.destroy();
        }

        templateModal = new TodoModal({
            size: 'md',
            destroyOnClose: false
        });

        templateModal.setTitle('加载映射模板')
            .setContent(listViewHtml)
            .setFooter([
                {
                    text: '导出JSON',
                    class: 'button button-secondary',
                    handler: () => {
                        templateModal.close();
                        exportMappingJSON();
                    }
                },
                {
                    text: '导入JSON',
                    class: 'button button-secondary m-r-auto',
                    handler: () => {
                        templateModal.close();
                        importMappingJSON();
                    }
                },
                {
                    text: '取消',
                    class: 'button button-secondary',
                    handler: () => {
                        templateModal.close();
                    }
                }
            ])
            .open();

        loadTemplateList();
    }

    /**
     * 显示保存模板模态框
     */
    function showSaveTemplateModal() {
        if (mappingRelations.length === 0) {
            showNotice('请先建立映射关系', 'warning');
            return;
        }

        if (typeof TodoModal === 'undefined') {
            alert('弹窗组件未加载');
            return;
        }

        // 创建保存模板表单 HTML
        const saveViewHtml = `
            <form id="save-template-form">
                <table class="form-table">
                    <tr>
                        <th><label for="template-name">模板名称：</label></th>
                        <td><input type="text" id="template-name" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="template-description">模板描述：</label></th>
                        <td><textarea id="template-description" class="large-text" rows="3"></textarea></td>
                    </tr>
                </table>
            </form>
        `;

        // 如果弹窗已存在，先销毁
        if (templateModal) {
            templateModal.destroy();
        }

        templateModal = new TodoModal({
            size: 'md',
            destroyOnClose: false
        });

        templateModal.setTitle('保存映射模板')
            .setContent(saveViewHtml)
            .setFooter([
                {
                    text: '取消',
                    class: 'button button-secondary',
                    handler: () => {
                        templateModal.close();
                    }
                },
                {
                    text: '确定',
                    class: 'button button-primary',
                    handler: () => {
                        saveTemplateConfirm();
                    }
                }
            ])
            .open();

        // 清空表单
        setTimeout(() => {
            $('#template-name').val('');
            $('#template-description').val('');
        }, 100);
    }

    /**
     * 加载模板列表
     */
    function loadTemplateList() {
        TodoRequest.ajax({
            url: config.ajaxurl,
            method: 'POST',
            data: {
                action: 'load_mapping_template',
                _load_mapping_template_todononce: config.nonces.load_mapping_template
            },
            onSuccess: function (response) {
                renderTemplateList(response.data.templates);
            },
            onError: function () {
                $find('#template-list-tbody').html('<tr><td colspan="4">加载模板失败</td></tr>');
            }
        });
    }

    /**
     * 渲染模板列表
     */
    function renderTemplateList(templates) {
        let html = '';

        if (templates.length === 0) {
            html = '<tr><td colspan="4">暂无保存的模板</td></tr>';
        } else {
            templates.forEach(template => {
                html += `
                    <tr>
                        <td>${template.name}</td>
                        <td>${template.description || '-'}</td>
                        <td>${formatDateTime(template.created_time)}</td>
                        <td>
                            <button class="button load-template" data-template-id="${template.id}">加载</button>
                            <button class="button delete-template" data-template-id="${template.id}">删除</button>
                        </td>
                    </tr>
                `;
            });
        }

        $('#template-list-tbody').html(html);

        // 绑定事件
        $('.load-template').on('click', function () {
            loadTemplate($(this).data('template-id'));
        });

        $('.delete-template').on('click', function () {
            deleteTemplate($(this).data('template-id'));
        });
    }

    /**
     * 执行导入
     */
    function executeImport() {
        if (mappingRelations.length === 0) {
            showNotice('请先建立映射关系', 'warning');
            return;
        }

        // 检查是否有上传的SQL文件（含有效路径）
        if (!uploadedSqlFile || !uploadedSqlFile.path) {
            showNotice('请先上传SQL文件', 'warning');
            return;
        }

        // 检查必要的 nonce
        if (!config.nonces || !config.nonces.execute_import) {
            showNotice('安全令牌缺失，请刷新页面重试', 'error');
            console.error('execute_import nonce 未定义:', config.nonces);
            return;
        }

        // 使用 getCurrentMappingConfig() 构建干净的映射配置：
        // - 剥离数据库 schema 的 DEFAULT 值（避免将其误作 source_default 覆盖真实数据）
        // - 包含用户在 UI 输入框中显式填写的 user_default
        const cleanConfig = getCurrentMappingConfig();
        const importConfig = {
            truncate_before_import: $('#truncate-before-import').is(':checked'),
            ignore_duplicates: $('#ignore-duplicates').is(':checked'),
            batch_size: parseInt($('#batch-size').val()) || 1000,
            mappings: cleanConfig.mappings || mappingRelations
        };

        // 构建确认消息HTML
        const confirmMessage = '<p>此操作将开始数据导入，请确认后继续。</p>' +
            '<div style="text-align: left; margin-top: 15px; padding: 10px 15px; background-color: #f5f5f5; border-radius: 4px;">' +
            '<strong>导入配置：</strong><br>' +
            '<span style="display: inline-block; margin-top: 8px; line-height: 1.8;">' +
            '- 导入前清空目标表数据：' + (importConfig.truncate_before_import ? '<strong style="color: #d63638;">是</strong>' : '否') + '<br>' +
            '- 忽略重复数据：' + (importConfig.ignore_duplicates ? '是' : '否') + '<br>' +
            '- 批量处理大小：' + importConfig.batch_size + ' 条/批<br>' +
            '- 映射关系数量：' + mappingRelations.length + ' 个' +
            '</span>' +
            '</div>';

        // 执行导入的实际逻辑
        const doExecuteImport = function() {
            showProgressModal();

            $.ajax({
                url: config.ajaxurl,
                type: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                data: {
                    action: 'execute_import',
                    _execute_import_todononce: config.nonces.execute_import,
                    source_file: uploadedSqlFile ? uploadedSqlFile.path : '',
                    mapping_config: JSON.stringify(importConfig),
                    import_config: JSON.stringify({
                        batch_size: importConfig.batch_size,
                        truncate_before_import: importConfig.truncate_before_import,
                        ignore_duplicates: importConfig.ignore_duplicates
                    })
                },
                success: function (response) {
                    if (response.success) {
                        handleImportProgress(response.data.task_id);
                    } else {
                        hideProgressModal();
                        showNotice(response.data.message || config.messages.import_error, 'error');
                    }
                },
                error: function () {
                    hideProgressModal();
                    showNotice(config.messages.import_error, 'error');
                }
            });
        };

        // 显示确认对话框
        TodoModal.confirm({
            title: '确认开始导入',
            message: confirmMessage,
            confirmText: '开始导入',
            cancelText: '取消',
            size: 'md',
            onConfirm: doExecuteImport
        });
    }

    /**
     * 处理导入进度
     */
    function handleImportProgress(jobId) {
        // 清理之前的轮询，以免二次导入时显示上一次的记录
        if (importProgressTimer) {
            try { clearTimeout(importProgressTimer); } catch (e) { }
            importProgressTimer = null;
        }
        lastJobErrorLength = 0;

        const checkProgress = () => {
            TodoRequest.ajax({
                url: config.ajaxurl,
                method: 'POST',
                data: {
                    action: 'get_import_progress',
                    _get_import_progress_todononce: config.nonces.get_import_progress,
                    task_id: jobId
                },
                onSuccess: function (response) {
                    if (response.success) {
                        updateProgressDisplay(response.data);

                        // 每次轮询都尝试显示新的错误行（如果有）
                        if (response.data && response.data.errors && response.data.errors.length > lastJobErrorLength) {
                            const $errorContainer = $find('#progress-errors-container');
                            const $errorList = $find('#progress-errors-list');

                            if ($errorContainer.length && $errorList.length) {
                                $errorContainer.show();
                                for (let i = lastJobErrorLength; i < response.data.errors.length; i++) {
                                    $errorList.append(`<li>${response.data.errors[i]}</li>`);
                                }
                                $errorList.scrollTop($errorList[0].scrollHeight);
                            }
                            lastJobErrorLength = response.data.errors.length;
                        }

                        if (response.data && typeof response.data.error_message === 'string' && response.data.error_message.length > 0) {
                            const err = response.data.error_message;
                            if (err.length > lastJobErrorLength) {
                                const delta = err.substring(lastJobErrorLength);
                                if ($find('#progress-logs-text').length) {
                                    // 显示日志容器
                                    $find('.todo-modal-progress-logs').show();
                                    const currentLogs = $find('#progress-logs-text').val();
                                    $find('#progress-logs-text').val((currentLogs ? currentLogs + '\n' : '') + delta);
                                    $find('#progress-logs-text').scrollTop($find('#progress-logs-text')[0].scrollHeight);
                                }
                                lastJobErrorLength = err.length;
                            }
                        }

                        if (response.data.status === 'completed' || response.data.status === 'failed') {
                            // 停止倒计时
                            stopCountdown();
                            
                            // 启用关闭按钮
                            if (progressModal && progressModal.element) {
                                const closeBtn = progressModal.element.querySelector('.todo-modal-footer .button');
                                if (closeBtn) {
                                    closeBtn.disabled = false;
                                }
                            }
                            
                            if (importProgressTimer) { try { clearTimeout(importProgressTimer); } catch (e) { } }
                            importProgressTimer = null;
                            if (response.data.status === 'completed') {
                                showNotice(config.messages.import_success, 'success');
                            } else if (response.data.status === 'failed') {
                                const msg = (response.data && response.data.error_message) ? response.data.error_message : config.messages.import_error;
                                showNotice(msg, 'error');
                                // 追加到日志区域
                                if ($find('#progress-logs-text').length) {
                                    // 显示日志容器
                                    $find('.todo-modal-progress-logs').show();
                                    const currentLogs = $find('#progress-logs-text').val();
                                    $find('#progress-logs-text').val((currentLogs ? currentLogs + '\n' : '') + msg);
                                    $find('#progress-logs-text').scrollTop($find('#progress-logs-text')[0].scrollHeight);
                                }
                            }
                        } else {
                            importProgressTimer = setTimeout(checkProgress, 500); // 缩短轮询间隔到500ms，确保实时更新
                        }
                    }
                },
                onError: function () {
                    // 启用关闭按钮
                    if (progressModal && progressModal.element) {
                        const closeBtn = progressModal.element.querySelector('.todo-modal-footer .button');
                        if (closeBtn) {
                            closeBtn.disabled = false;
                        }
                    }
                    if (importProgressTimer) { try { clearTimeout(importProgressTimer); } catch (e) { } }
                    importProgressTimer = null;
                    showNotice('获取进度失败', 'error');
                }
            });
        };

        checkProgress();
    }

    /**
     * 计算带随机波动的进度百分比（基于倒计时）
     * @param {number} baseSeconds 基础秒数（倒计时秒数）
     * @param {number} maxPercentage 最大进度百分比（90或100）
     * @returns {number} 带随机波动的进度百分比
     */
    function calculateProgressWithRandom(baseSeconds, maxPercentage) {
        // 基础进度：每秒1%
        const baseProgress = Math.min(baseSeconds, maxPercentage);
        
        // 添加正向随机波动：0%到+3%的随机数，让进度更自然，但只能前进
        const randomOffset = Math.random() * 3; // 0% 到 +3%
        
        // 计算新进度（基础进度 + 正向随机波动）
        let newProgress = baseProgress + randomOffset;
        
        // 确保不会倒退：新进度至少等于上一次的进度
        newProgress = Math.max(lastProgressPercentage, newProgress);
        
        // 确保不超过上限
        newProgress = Math.min(newProgress, maxPercentage);
        
        // 更新上一次的进度值
        lastProgressPercentage = newProgress;
        
        return Math.round(newProgress);
    }

    /**
     * 更新进度条显示（假进度，基于倒计时）
     * @param {number} percentage 进度百分比（0-100），如果传入-1则基于倒计时自动计算
     */
    function updateProgressBar(percentage) {
        let finalPercentage;
        
        if (percentage === -1) {
            // 自动计算：基于倒计时，带随机波动
            finalPercentage = calculateProgressWithRandom(countdownSeconds, 90);
        } else {
            // 直接使用传入的百分比（用于完成时设置为100%）
            finalPercentage = Math.max(0, Math.min(100, Math.round(percentage)));
            lastProgressPercentage = finalPercentage;
        }
        
        // 优先使用 progressModal 实例更新
        if (progressModal && progressModal.element) {
            const $percentage = $(progressModal.element).find('.todo-modal-progress-percentage');
            const $fill = $(progressModal.element).find('.todo-modal-progress-fill');
            
            if ($percentage.length) $percentage.text(finalPercentage + '%');
            if ($fill.length) $fill.css('width', finalPercentage + '%');
            return;
        }

        if ($find('.todo-modal-progress-percentage').length) {
            $find('.todo-modal-progress-percentage').text(finalPercentage + '%');
        }
        if ($find('.todo-modal-progress-fill').length) {
            $find('.todo-modal-progress-fill').css('width', finalPercentage + '%');
        }
    }

    /**
     * 更新进度显示
     */
    function updateProgressDisplay(data) {
        const total = (typeof data.total !== 'undefined') ? data.total : (data.total_records || 0);
        const imported = (typeof data.imported !== 'undefined') ? data.imported : (data.imported_records || 0);
        const status = data.status || '';
        
        // 如果任务已完成，停止倒计时并设置进度为100%
        if (isTaskFinished(status)) {
            stopCountdown();
            // 任务完成时，进度设置为100%
            updateProgressBar(100);
            
            // 优先使用 progressModal 实例更新
            if (progressModal && progressModal.element) {
                const $modal = $(progressModal.element);
                $modal.find('#progress-current').text(imported);
                $modal.find('#progress-total').text(total);
                $modal.find('#progress-records-info').show();
                $modal.find('#progress-countdown').text(countdownSeconds + 's');
            } else {
                // 任务完成时才显示记录数信息
                if ($find('#progress-current').length) {
                    $find('#progress-current').text(imported);
                }
                if ($find('#progress-total').length) {
                    $find('#progress-total').text(total);
                }
                if ($find('#progress-records-info').length) {
                    $find('#progress-records-info').show();
                }
                if ($find('#progress-countdown').length) {
                    $find('#progress-countdown').text(countdownSeconds + 's');
                }
            }
        } else {
            // 任务进行中：基于倒计时计算假进度（带随机波动，最高90%）
            updateProgressBar(-1); // -1 表示自动计算
            
            // 优先使用 progressModal 实例更新
            if (progressModal && progressModal.element) {
                $(progressModal.element).find('#progress-records-info').hide();
            } else {
                // 隐藏记录数信息
                if ($find('#progress-records-info').length) {
                    $find('#progress-records-info').hide();
                }
            }
        }
        
        // 更新状态文本
        const statusText = data.status_text || data.status;
        if (isTaskFinished(status)) {
            // 任务完成或失败时，只显示状态文本，不显示倒计时
            if (statusText && statusText !== '') {
                $find('#progress-status-text').text(statusText);
            }
            currentStatusText = ''; // 清空状态文本
        } else {
            // 任务进行中：保存状态文本，倒计时会自动合并显示
            if (statusText && statusText !== '') {
                $find('#progress-status-text').text(statusText);
                // 立即更新显示（合并倒计时）
                updateCountdownText();
            } else {
                currentStatusText = '';
                // 只显示倒计时
                updateCountdownText();
            }
        }

        if (data.logs) {
            // 显示日志容器
            $find('.todo-modal-progress-logs').show();
            const currentLogs = $find('#progress-logs-text').val();
            $find('#progress-logs-text').val(currentLogs + '\n' + data.logs);
            $find('#progress-logs-text').scrollTop($find('#progress-logs-text')[0].scrollHeight);
        }
    }

    // 工具函数 - 已改为使用 TodoModal 组件
    function closeModal() {
        if (templateModal) {
            templateModal.close();
        }
    }

    function showProgressModal() {
        if (typeof TodoModal === 'undefined') {
            alert('弹窗组件未加载');
            return;
        }

        resetProgressDisplay();

        // 如果进度弹窗已存在，先销毁
        if (progressModal) {
            progressModal.destroy();
        }

        // 创建进度弹窗内容 HTML
        const progressHtml = `
            <div class="todo-modal-progress-content">
                <div class="todo-modal-progress-info">
                    <div class="todo-modal-progress-stats">
                        <span id="progress-records-info" style="display: none;">
                            <span id="progress-current">0</span> / <span id="progress-total">0</span> 条记录
                        </span>
                        <span class="todo-modal-progress-percentage">0%</span>
                    </div>
                    <div class="todo-modal-progress-bar">
                        <div class="todo-modal-progress-fill" style="width: 0%"></div>
                    </div>
                    <div class="todo-modal-progress-status">
                        <span id="progress-status-text">正在执行导入，请勿离开... <b>0s</b></span>
                    </div>
                </div>
                <div class="todo-modal-progress-errors" id="progress-errors-container" style="display: none;">
                    <h4>错误信息:</h4>
                    <ul id="progress-errors-list"></ul>
                </div>
                <div class="todo-modal-progress-logs" style="display: none;">
                    <textarea id="progress-logs-text" class="todo-modal-progress-logs-text" readonly rows="5"></textarea>
                </div>
            </div>
        `;

        progressModal = new TodoModal({
            size: 'md',
            closable: false,
            backdrop: false,
            keyboard: false,
            destroyOnClose: false
        });

        progressModal.setTitle('数据导入进度')
            .setContent(progressHtml)
            .setFooter([
                {
                    text: '关闭',
                    class: 'button button-secondary',
                    disabled: true,
                    handler: () => {
                        hideProgressModal();
                    }
                }
            ])
            .open();

        // 启动倒计时
        startCountdown();
    }
    
    // 保存当前状态文本（用于倒计时更新时合并显示）
    let currentStatusText = '';
    
    /**
     * 启动倒计时（从0秒开始，每秒+1）
     */
    function startCountdown() {
        // 清除之前的定时器
        if (countdownTimer !== null) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }
        
        countdownSeconds = 0;
        currentStatusText = '';
        
        // 重置进度相关变量
        lastProgressPercentage = 0;
        
        // 初始显示（进度为0%）
        updateCountdownText();
        updateProgressBar(0);
        
        // 每秒更新一次
        countdownTimer = setInterval(() => {
            countdownSeconds++;
            updateCountdownText();
            // 同步更新假进度（基于倒计时，带随机波动，最高90%）
            updateProgressBar(-1); // -1 表示自动计算
        }, 1000);
    }
    
    /**
     * 格式化时间显示（H:M:S格式）
     * @param {number} seconds 总秒数
     * @returns {string} 格式化后的时间字符串
     */
    function formatTime(seconds) {
        if (seconds < 60) {
            return seconds + 's';
        } else if (seconds < 3600) {
            const minutes = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return minutes + ':' + (secs < 10 ? '0' : '') + secs + 's';
        } else {
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            const secs = seconds % 60;
            return hours + ':' + (minutes < 10 ? '0' : '') + minutes + ':' + (secs < 10 ? '0' : '') + secs;
        }
    }
    
    /**
     * 更新倒计时文本
     */
    function updateCountdownText() {
        // 如果任务已完成，不更新倒计时
        if (countdownTimer === null) {
            return;
        }
        
        const timeText = formatTime(countdownSeconds);
        const html = (currentStatusText && currentStatusText !== '') 
            ? currentStatusText + ' <b>' + timeText + '</b>'
            : '正在执行导入，请勿离开... <b>' + timeText + '</b>';
        
        // 优先使用 progressModal 实例更新
        if (progressModal && progressModal.element) {
            const $status = $(progressModal.element).find('#progress-status-text');
            if ($status.length) {
                $status.html(html);
                return;
            }
        }
        
        $find('#progress-status-text').html(html);
    }
    
    /**
     * 检查任务是否已完成（用于判断是否停止倒计时）
     */
    function isTaskFinished(status) {
        return status === 'completed' || status === 'failed';
    }
    
    /**
     * 停止倒计时
     */
    function stopCountdown() {
        if (countdownTimer !== null) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }
    }

    function resetProgressDisplay() {
        // 停止之前的倒计时
        stopCountdown();
        
        // 重置进度数值与状态
        if ($find('#progress-current').length) {
            $find('#progress-current').text('0');
        }
        if ($find('#progress-total').length) {
            $find('#progress-total').text('0');
        }
        // 隐藏记录数信息
        if ($find('#progress-records-info').length) {
            $find('#progress-records-info').hide();
        }
        // 使用统一的进度条更新函数
        updateProgressBar(0);
        if ($find('#progress-status-text').length) {
            $find('#progress-status-text').html('正在执行导入，请勿离开... <b>0s</b>');
        }
        // 清空日志并隐藏日志容器
        if ($find('#progress-logs-text').length) {
            $find('#progress-logs-text').val('');
            $find('.todo-modal-progress-logs').hide();
        }
        // 清空错误信息并隐藏错误容器
        if ($find('#progress-errors-list').length) {
            $find('#progress-errors-list').empty();
            $find('#progress-errors-container').hide();
        }
        
        // 重置状态变量
        countdownSeconds = 0;
        currentStatusText = '';
        lastProgressPercentage = 0;
    }

    function hideProgressModal() {
        // 停止所有定时器
        if (importProgressTimer) {
            try { clearTimeout(importProgressTimer); } catch (e) { }
            importProgressTimer = null;
        }
        stopCountdown();
        if (progressModal) {
            progressModal.close();
        }
    }

    function showNotice(message, type) {
        if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils && TodoCMS.Utils.showMessage) {
            TodoCMS.Utils.showMessage(message, type);
        } else {
            // Fallback for when TodoCMS is not loaded (e.g. standalone or error)
            console.log(`[${type.toUpperCase()}] ${message}`);
            $('#show-notice').html(`<p class="notice-${type}">${message}</p>`);
            if (noticeClearTimer !== null) clearTimeout(noticeClearTimer);
            noticeClearTimer = setTimeout(() => { $('#show-notice').html(''); }, 5000);
        }
    }

    function showLoadingState(selector, message) {
        $(selector).html(`
            <div class="loading-state">
                <span class="toicon spin">✤</span>
                <p>${message}</p>
            </div>
        `);
    }

    function showEmptyState(selector, message) {
        $(selector).html(`
            <div class="empty-state">
                <p>${message}</p>
            </div>
        `);
    }

    /**
     * 恢复源数据库结构的初始状态（包含图标）
     */
    function resetSourceStructureToInitial() {
        $('#source-structure-tree').html(`
            <div class="empty-state">
                <span class="toicon" style="font-size: 30px;width: 46px;height: 46px;margin-bottom: 10px;color: #efefef;border: 1px solid #efefef;border-radius: 100%;">↑</span>
                <p>请上传SQL文件以查看数据库结构</p>
            </div>
        `);
    }

    function formatDateTime(dateString) {
        const date = new Date(dateString);
        return date.toLocaleString('zh-CN');
    }

    function saveState() {
        const state = {
            sourceStructure,
            targetStructure,
            mappingRelations,
            currentTemplate,
            uploadedSqlFile
        };
        try {
            localStorage.setItem('dataMappingImport_state', JSON.stringify(state));
        } catch (e) {
            console.warn('保存状态到本地存储失败:', e);
        }
    }

    function restoreState() {
        const saved = localStorage.getItem('dataMappingImport_state');
        if (saved) {
            try {
                const state = JSON.parse(saved);
                // 恢复源结构
                if (state.sourceStructure) {
                    sourceStructure = state.sourceStructure;
                    renderSourceStructure(sourceStructure);
                }
                // 恢复映射关系
                if (state.mappingRelations) {
                    mappingRelations = state.mappingRelations;
                    updateMappingDisplay();
                    updateFieldStatus();
                }
                // 恢复已上传的SQL文件信息（用于刷新后保持“开始导入”按钮可用）
                if (state.uploadedSqlFile && (state.uploadedSqlFile.path || state.uploadedSqlFile.name)) {
                    uploadedSqlFile = state.uploadedSqlFile;
                    try { console.log('[restoreState] 恢复已上传SQL文件:', uploadedSqlFile); } catch (e) { }
                }
                updateImportButton();
            } catch (e) {
                console.warn('Failed to restore state:', e);
            }
        }
    }

    // 模态框确认处理函数
    function saveTemplateConfirm() {
        const templateName = $find('#template-name').val();
        if (!templateName) {
            showNotice('请输入模板名称', 'error');
            return;
        }

        // 获取当前映射配置
        const mappingConfig = getCurrentMappingConfig();
        // 获取模板描述
        const templateDescription = $find('#template-description').val();

        // 保存模板
        TodoRequest.ajax({
            url: config.ajaxurl,
            method: 'POST',
            data: {
                action: 'save_mapping_template',
                template_name: templateName,
                description: templateDescription,
                mapping_config: JSON.stringify(mappingConfig),
                _save_mapping_template_todononce: config.nonces.save_mapping_template
            },
            onSuccess: function (response) {
                showNotice('模板保存成功', 'success');
                if (templateModal) {
                    templateModal.close();
                }
                // 如果加载模板弹窗打开，重新加载列表
                if (templateModal && templateModal.isOpen) {
                    loadTemplateList();
                }
            },
            onError: function () {
                showNotice('保存失败，请重试', 'error');
            }
        });
    }

    function loadTemplateConfirm() {
        let selectedTemplate = $find('#template-list-tbody tr.selected').data('template-id');
        if (!selectedTemplate) {
            selectedTemplate = $find('.template-item.selected').data('template-id');
        }
        if (!selectedTemplate) {
            showNotice('请选择要加载的模板', 'error');
            return;
        }

        // 加载模板
        TodoRequest.ajax({
            url: config.ajaxurl,
            method: 'POST',
            data: {
                action: 'load_mapping_template',
                template_id: selectedTemplate,
                _load_mapping_template_todononce: config.nonces.load_mapping_template
            },
            onSuccess: function (response) {
                let cfg = response.data.template.mapping_config;
                if (typeof cfg === 'string') {
                    try { cfg = JSON.parse(cfg); } catch (e) { }
                }
                // 先清空当前映射关系，再应用模板
                mappingRelations = [];
                updateMappingDisplay();
                updateFieldStatus();
                updateImportButton();

                if (cfg) {
                    applyMappingConfig(cfg);
                    showNotice('模板加载成功', 'success');
                    if (templateModal) {
                        templateModal.close();
                    }
                } else {
                    showNotice('模板内容无效', 'error');
                }
            },
            onError: function () {
                showNotice('加载失败，请重试', 'error');
            }
        });
    }

    function executeImportConfirm() {
        // 确认执行导入
        if (templateModal) {
            templateModal.close();
        }
        executeImport();
    }

    function getCurrentMappingConfig() {
        // 统一导出为新版配置优先（mappings 数组），并兼容旧版字段（source_table/target_table/field_mappings）
        const mappings = (Array.isArray(mappingRelations) ? mappingRelations : []).map(m => {
            // 获取输入框的当前值（如果存在）
            const $input = $(`.source-default-value[data-mapping-id="${m.id}"]`);
            // 仅当输入框可见时才使用其值（避免隐藏状态下的误操作）
            const userDefaultVal = ($input.length && $input.is(':visible')) ? $input.val().trim() : '';
            
            return {
                id: m.id || ('mapping_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9)),
                source: {
                    database: m.source && m.source.database ? m.source.database : ($(`.source-panel .field-node[data-table="${m.source && m.source.table}"][data-field="${m.source && m.source.field}"]`).data('database') || ''),
                    table: m.source && m.source.table ? m.source.table : '',
                    field: m.source && m.source.field ? m.source.field : '',
                    type: m.source && m.source.type ? m.source.type : ($(`.source-panel .field-node[data-table="${m.source && m.source.table}"][data-field="${m.source && m.source.field}"]`).data('type') || ''),
                    user_default: userDefaultVal || undefined  // 只在用户显式填写时才包含；切勿使用 DB schema 的 DEFAULT 值
                },
                target: {
                    database: m.target && m.target.database ? m.target.database : ($(`.target-panel .field-node[data-table="${m.target && m.target.table}"][data-field="${m.target && m.target.field}"]`).data('database') || ''),
                    table: m.target && m.target.table ? m.target.table : '',
                    field: m.target && m.target.field ? m.target.field : '',
                    type: m.target && m.target.type ? m.target.type : ($(`.target-panel .field-node[data-table="${m.target && m.target.table}"][data-field="${m.target && m.target.field}"]`).data('type') || '')
                },
                created: m.created || new Date().toISOString()
            };
        });

        // 旧版兼容：仅当唯一表时设置 source_table/target_table，并生成 field_mappings
        const sourceTables = Array.from(new Set(mappings.map(x => x.source && x.source.table).filter(Boolean)));
        const targetTables = Array.from(new Set(mappings.map(x => x.target && x.target.table).filter(Boolean)));
        const legacy = {
            source_table: sourceTables.length === 1 ? sourceTables[0] : '',
            target_table: targetTables.length === 1 ? targetTables[0] : '',
            field_mappings: {}
        };
        mappings.forEach(x => {
            if (x.source && x.source.field && x.target && x.target.field) {
                legacy.field_mappings[x.source.field] = x.target.field;
            }
        });

        // 导入选项（保持与现有 UI 的选择器一致）
        const import_options = {
            batch_size: $('#batch-size').val() || 1000,
            truncate_before_import: $('#truncate-before-import').is(':checked'),
            ignore_duplicates: $('#ignore-duplicates').is(':checked'),
            // 兼容可能存在的旧控件
            skip_duplicates: $('#skip-duplicates').is(':checked'),
            update_existing: $('#update-existing').is(':checked')
        };

        // 新版映射优先，同时包含旧版字段，便于后端或旧逻辑兼容
        return Object.assign({
            mappings,
            import_options
        }, legacy);
    }

    /**
     * 导出映射配置为 JSON 文件并触发下载
     */
    function exportMappingJSON() {
        if (mappingRelations.length === 0) {
            showNotice('请先建立映射关系', 'warning');
            return;
        }

        const config = getCurrentMappingConfig();
        // 规范化：去除旧版兼容字段，仅保留新版格式
        const exportConfig = {
            version: '2.0',
            exported_at: new Date().toISOString(),
            source_table: typeof config.source_table === 'string' ? config.source_table : '',
            target_table: typeof config.target_table === 'string' ? config.target_table : '',
            mappings: config.mappings,
            import_options: config.import_options
        };

        const jsonStr = JSON.stringify(exportConfig, null, 2);
        const blob = new Blob([jsonStr], { type: 'application/json' });
        const url = URL.createObjectURL(blob);

        const timestamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
        const filename = `data-mapping-${timestamp}.json`;

        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);

        showNotice('映射配置已导出: ' + filename, 'success');
    }

    /**
     * 从 JSON 文件导入映射配置（直接入库为模板，不替换当前工作面）
     */
    function importMappingJSON() {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = '.json,application/json';

        input.onchange = function () {
            const file = input.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function (e) {
                try {
                    const raw = JSON.parse(e.target.result);

                    // 支持新旧两种格式：新版有 mappings 数组，旧版有 field_mappings 对象
                    let mappingConfig;
                    if (raw && raw.mappings && Array.isArray(raw.mappings)) {
                        // 新版格式
                        mappingConfig = {
                            source_table: raw.source_table || '',
                            target_table: raw.target_table || '',
                            mappings: raw.mappings,
                            import_options: raw.import_options || {}
                        };
                    } else if (raw && typeof raw.field_mappings === 'object') {
                        // 旧版格式兼容
                        mappingConfig = {
                            source_table: raw.source_table || '',
                            target_table: raw.target_table || '',
                            field_mappings: raw.field_mappings,
                            import_options: raw.import_options || raw.options || {}
                        };
                    } else if (raw && Array.isArray(raw) && raw.length > 0 && raw[0].source && raw[0].target) {
                        // 纯 mappings 数组
                        mappingConfig = {
                            mappings: raw,
                            import_options: {}
                        };
                    } else {
                        showNotice('无法识别的配置文件格式，请检查 JSON 内容', 'error');
                        return;
                    }

                    // 将文件名（去掉扩展名）作为模板名称
                    const templateName = file.name.replace(/\.json$/i, '');
                    // 用导出时间或当前时间作为描述
                    const templateDesc = raw.exported_at
                        ? '导入自 ' + file.name + '（导出时间: ' + raw.exported_at + '）'
                        : '导入自 ' + file.name;

                    // 直接入库保存为模板，不替换当前工作面
                    TodoRequest.ajax({
                        url: config.ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'save_mapping_template',
                            template_name: templateName,
                            description: templateDesc,
                            mapping_config: JSON.stringify(mappingConfig),
                            _save_mapping_template_todononce: config.nonces.save_mapping_template
                        },
                        onSuccess: function () {
                            showNotice('模板导入成功: ' + templateName, 'success');
                            // 如果加载模板弹窗打开，刷新列表
                            if (templateModal && templateModal.isOpen) {
                                loadTemplateList();
                            }
                        },
                        onError: function () {
                            showNotice('模板导入失败，请重试', 'error');
                        }
                    });
                } catch (err) {
                    showNotice('JSON 解析失败: ' + err.message, 'error');
                }
            };
            reader.onerror = function () {
                showNotice('文件读取失败', 'error');
            };
            reader.readAsText(file);
        };

        input.click();
    }

    function applyMappingConfig(mappingConfig) {
        // 兼容两种配置：
        // 1) 新版：{ mappings: [ {id, source: {table, field, ...}, target: {...}} ], import_options: {...} }
        // 2) 旧版：{ source_table, target_table, field_mappings: {sourceField: targetField}, import_options: {...} }

        const applyImportOptions = (opts) => {
            if (!opts) return;
            if (typeof opts.batch_size !== 'undefined') $('#batch-size').val(opts.batch_size);
            if (typeof opts.skip_duplicates !== 'undefined') $('#skip-duplicates').prop('checked', !!opts.skip_duplicates);
            if (typeof opts.update_existing !== 'undefined') $('#update-existing').prop('checked', !!opts.update_existing);
        };

        // 新版优先
        if (mappingConfig && Array.isArray(mappingConfig.mappings)) {
            // 如果同时提供了旧版表选择，则先应用，以确保字段树渲染完成
            if (mappingConfig.source_table) {
                $('#source-table').val(mappingConfig.source_table).trigger('change');
            }
            if (mappingConfig.target_table) {
                $('#target-table').val(mappingConfig.target_table).trigger('change');
            }

            setTimeout(() => {
                // 构造映射关系
                mappingRelations = mappingConfig.mappings.map(m => {
                    const sTable = m.source && m.source.table ? m.source.table : '';
                    const sField = m.source && m.source.field ? m.source.field : '';
                    const tTable = m.target && m.target.table ? m.target.table : '';
                    const tField = m.target && m.target.field ? m.target.field : '';

                    const $sNode = $(`.source-panel .field-node[data-table="${sTable}"][data-field="${sField}"]`);
                    const $tNode = $(`.target-panel .field-node[data-table="${tTable}"][data-field="${tField}"]`);

                    const sourceObj = {
                        database: (m.source && m.source.database) || ($sNode.data('database') || ''),
                        table: sTable,
                        field: sField,
                        type: (m.source && m.source.type) || ($sNode.data('type') || '')
                    };
                    // 保留用户设定的默认值（兼容旧版 default 和新版 user_default）
                    if (m.source && (m.source.user_default || m.source.default)) {
                        sourceObj.default = m.source.user_default || m.source.default;
                    }
                    
                    return {
                        id: m.id || ('mapping_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9)),
                        source: sourceObj,
                        target: {
                            database: (m.target && m.target.database) || ($tNode.data('database') || ''),
                            table: tTable,
                            field: tField,
                            type: (m.target && m.target.type) || ($tNode.data('type') || '')
                        },
                        created: m.created || new Date().toISOString()
                    };
                });

                updateMappingDisplay();
                updateFieldStatus();
                updateImportButton();
                applyImportOptions(mappingConfig.import_options || mappingConfig.options);
            }, 400);
            return;
        }

        // 旧版回退
        if (mappingConfig) {
            if (mappingConfig.source_table) {
                $('#source-table').val(mappingConfig.source_table).trigger('change');
            }
            if (mappingConfig.target_table) {
                $('#target-table').val(mappingConfig.target_table).trigger('change');
            }

            if (mappingConfig.field_mappings && typeof mappingConfig.field_mappings === 'object') {
                setTimeout(() => {
                    const sTable = mappingConfig.source_table || $('#source-table').val();
                    const tTable = mappingConfig.target_table || $('#target-table').val();
                    const relations = [];

                    Object.keys(mappingConfig.field_mappings).forEach(sourceField => {
                        const targetField = mappingConfig.field_mappings[sourceField];
                        const $sNode = $(`.source-panel .field-node[data-table="${sTable}"][data-field="${sourceField}"]`);
                        const $tNode = $(`.target-panel .field-node[data-table="${tTable}"][data-field="${targetField}"]`);

                        relations.push({
                            id: 'mapping_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9),
                            source: {
                                database: $sNode.data('database') || '',
                                table: sTable,
                                field: sourceField,
                                type: $sNode.data('type') || ''
                            },
                            target: {
                                database: $tNode.data('database') || '',
                                table: tTable,
                                field: targetField,
                                type: $tNode.data('type') || ''
                            },
                            created: new Date().toISOString()
                        });
                    });

                    mappingRelations = relations;
                    updateMappingDisplay();
                    updateFieldStatus();
                    updateImportButton();
                    applyImportOptions(mappingConfig.import_options || mappingConfig.options);
                }, 500);
            } else {
                // 仅应用导入选项
                applyImportOptions(mappingConfig.import_options || mappingConfig.options);
            }
        }
    }

    function loadTemplateList() {
        // 加载模板列表（通过 load_mapping_template 动作且不传 template_id 获取列表）
        TodoRequest.ajax({
            url: config.ajaxurl,
            method: 'POST',
            data: {
                action: 'load_mapping_template',
                _load_mapping_template_todononce: config.nonces.load_mapping_template
            },
            onSuccess: function (response) {
                if (response && response.data && response.data.templates) {
                    updateTemplateList(response.data.templates);
                } else {
                    $find('#template-list-tbody').html('<tr><td colspan="4">加载模板列表失败</td></tr>');
                }
            },
            onError: function () {
                $find('#template-list-tbody').html('<tr><td colspan="4">加载模板列表失败</td></tr>');
            }
        });
    }

    function updateTemplateList(templates) {
        // 使用表格展示模板列表
        const $tbody = $find('#template-list-tbody');
        if ($tbody.length === 0) return;
        
        $tbody.empty();
        let html = '';
        if (!templates || templates.length === 0) {
            html = '<tr><td colspan="4">暂无保存的模板</td></tr>';
        } else {
            templates.forEach(template => {
                const name = template.name || '-';
                const desc = template.description || '-';
                const created = template.created_time ? formatDateTime(template.created_time) : (template.created_at ? template.created_at : '-');
                html += `
                    <tr data-template-id="${template.id}">
                        <td style="line-height: normal;font-size: 14px;min-width: 90px;"><strong>${name}</strong></td>
                        <td style="line-height: normal;font-size: 12px;">${desc}</td>
                        <td style="line-height: normal;font-size: 12px;width: 95px;">${created}</td>
                        <td style="width: 180px;">
                            <button class="btn button-secondary delete-template" data-template-id="${template.id}">删除</button>
                            <button class="btn button-primary use-template" data-template-id="${template.id}">使用映射模版</button>
                        </td>
                    </tr>
                `;
            });
        }
        $tbody.html(html);

        // 绑定“使用映射模版”事件
        $tbody.off('click', '.use-template').on('click', '.use-template', function () {
            const templateId = $(this).data('template-id');
            TodoRequest.ajax({
                url: config.ajaxurl,
                method: 'POST',
                data: {
                    action: 'load_mapping_template',
                    _load_mapping_template_todononce: config.nonces.load_mapping_template,
                    template_id: templateId
                },
                onSuccess: function (response) {
                    if (response && response.success && response.data && response.data.template) {
                        let cfg = response.data.template.mapping_config;
                        if (typeof cfg === 'string') {
                            try { cfg = JSON.parse(cfg); } catch (e) { }
                        }
                        // 清空当前映射关系并应用模板配置
                        mappingRelations = [];
                        updateMappingDisplay();
                        updateFieldStatus();
                        updateImportButton();

                        // 严格校验模板内容是否为可用对象
                        const isObj = (val) => val && typeof val === 'object' && !Array.isArray(val);
                        const validCfg = isObj(cfg) && (Array.isArray(cfg.mappings) || (cfg.field_mappings && typeof cfg.field_mappings === 'object'));
                        if (validCfg) {
                            applyMappingConfig(cfg);
                            // 等待映射渲染完成后再提示
                            setTimeout(() => {
                                if (Array.isArray(mappingRelations) && mappingRelations.length > 0) {
                                    showNotice('已使用映射模版', 'success');
                                    if (templateModal) {
                                        templateModal.close();
                                    }
                                } else {
                                    showNotice('模板内容无效或未匹配到任何字段，请检查模板内容', 'error');
                                }
                            }, 600);
                        } else {
                            showNotice('模板内容无效', 'error');
                        }
                    } else {
                        const msg = (response && response.data && response.data.message) ? response.data.message : '加载失败';
                        showNotice(msg, 'error');
                    }
                },
                onError: function () {
                    showNotice('加载失败，请重试', 'error');
                }
            });
        });

        // 绑定“删除模板”事件
        $tbody.off('click', '.delete-template').on('click', '.delete-template', function () {
            const templateId = $(this).data('template-id');
            if (!templateId) return;
            const deleteTemplate = function() {
                TodoRequest.ajax({
                    url: config.ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'delete_mapping_template',
                        template_id: templateId,
                        _delete_mapping_template_todononce: config.nonces.delete_mapping_template
                    },
                    onSuccess: function (response) {
                        if (response && response.success) {
                            showNotice('模板已删除', 'success');
                            loadTemplateList();
                        } else {
                            const msg = (response && response.data && response.data.message) ? response.data.message : '删除失败';
                            showNotice(msg, 'error');
                        }
                    },
                    onError: function () {
                        showNotice('删除失败，请重试', 'error');
                    }
                });
            };
            
            TodoModal.deleteConfirm({
                title: '确认删除模板',
                message: '确定要删除该映射模版吗？',
                onConfirm: deleteTemplate
            });
        });
    }

    /**
     * 一键初始化 / 全量对齐数据库结构
     */
    function syncDatabaseSchema() {
        var $btn = $('#btn-sync-db-schema');
        var originalHtml = $btn.html();
        var defaultPrefix = (config && config.tablePrefix) ? config.tablePrefix : 'todo_';

        var executeSync = function (selectedPrefix) {
            $btn.prop('disabled', true).html('对齐中...');

            $.ajax({
                url: config.ajaxurl,
                type: 'POST',
                data: {
                    action: 'sync_db_schema',
                    table_prefix: selectedPrefix,
                    _sync_db_schema_todononce: (config.nonces && config.nonces.sync_db_schema) ? config.nonces.sync_db_schema : ''
                },
                dataType: 'json',
                success: function (res) {
                    $btn.prop('disabled', false).html(originalHtml);
                    if (res && res.success) {
                        var msg = (res.data && res.data.message) ? res.data.message : '数据库结构对齐成功！';
                        showNotice(msg, 'success');
                        if (typeof loadTargetStructure === 'function') {
                            loadTargetStructure();
                        }
                    } else {
                        var errMsg = (res && res.data && res.data.message) ? res.data.message : ((res && res.error) ? res.error : '同步失败');
                        showNotice(errMsg, 'error');
                    }
                },
                error: function () {
                    $btn.prop('disabled', false).html(originalHtml);
                    showNotice('网络通信失败，数据库结构同步失败', 'error');
                }
            });
        };

        var promptHtml = 
            '<div style="margin-bottom:12px;color:#444;font-size:13px;line-height:1.5;">' +
                '为防止污染或误伤数据库中的其他非系统表，请输入限制对齐的<b>数据表前缀</b>：' +
            '</div>' +
            '<div style="margin-bottom:10px;">' +
                '<label style="display:block;margin-bottom:4px;font-weight:600;font-size:13px;color:#333;">限制表前缀：</label>' +
                '<input type="text" id="sync-table-prefix-input" value="' + defaultPrefix + '" class="regular-text" placeholder="如: todo_（留空表示不限定前缀）" style="width:100%;box-sizing:border-box;padding:6px 10px;border:1px solid #ccd0d4;border-radius:4px;" />' +
            '</div>' +
            '<div style="color:#888;font-size:12px;">' +
                '提示：仅匹配该前缀开头的表才会进行结构增量补全，绝对不会覆盖或删除原有数据。' +
            '</div>';

        if (window.TodoModal && typeof window.TodoModal.confirm === 'function') {
            window.TodoModal.confirm({
                title: '初始化数据库结构',
                message: promptHtml,
                type: 'info',
                onConfirm: function () {
                    var $input = $find('#sync-table-prefix-input');
                    var val = ($input && $input.length > 0) ? $input.val() : defaultPrefix;
                    if (val === undefined || val === null) val = defaultPrefix;
                    executeSync($.trim(val));
                }
            });
        } else {
            var inputPrefix = prompt('请输入要对齐初始化的表前缀（留空表示不限定前缀）：', defaultPrefix);
            if (inputPrefix !== null) {
                executeSync($.trim(inputPrefix));
            }
        }
    }

    // 页面卸载时保存状态
    $(window).on('beforeunload', saveState);

})(jQuery);
