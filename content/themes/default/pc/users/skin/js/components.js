/**
 * TodoCMS 用户中心组件 JavaScript 文件
 * 提供各种可复用的UI组件
 */

(function($) {
    'use strict';
    
    // 组件命名空间
    var TodoComponents = {
        
        // 分页组件
        Pagination: {
            init: function(container, options) {
                var defaults = {
                    currentPage: 1,
                    totalPages: 1,
                    showPages: 5,
                    showFirst: true,
                    showLast: true,
                    showPrev: true,
                    showNext: true,
                    onPageChange: null
                };
                
                var settings = $.extend({}, defaults, options);
                var $container = $(container);
                
                this.render($container, settings);
                this.bindEvents($container, settings);
            },
            
            render: function($container, settings) {
                var html = '<nav aria-label="' + __t('分页导航') + '"><ul class="pagination">';
                
                // 首页
                if (settings.showFirst && settings.currentPage > 1) {
                    html += '<li class="page-item"><a class="page-link" href="javascript:void(0);" data-page="1">' + __t('首页') + '</a></li>';
                }
                
                // 上一页
                if (settings.showPrev && settings.currentPage > 1) {
                    html += '<li class="page-item"><a class="page-link" href="javascript:void(0);" data-page="' + (settings.currentPage - 1) + '">上一页</a></li>';
                }
                
                // 页码
                var startPage = Math.max(1, settings.currentPage - Math.floor(settings.showPages / 2));
                var endPage = Math.min(settings.totalPages, startPage + settings.showPages - 1);
                
                if (endPage - startPage + 1 < settings.showPages) {
                    startPage = Math.max(1, endPage - settings.showPages + 1);
                }
                
                for (var i = startPage; i <= endPage; i++) {
                    var activeClass = i === settings.currentPage ? ' active' : '';
                    html += '<li class="page-item' + activeClass + '"><a class="page-link" href="javascript:void(0);" data-page="' + i + '">' + i + '</a></li>';
                }
                
                // 下一页
                if (settings.showNext && settings.currentPage < settings.totalPages) {
                    html += '<li class="page-item"><a class="page-link" href="javascript:void(0);" data-page="' + (settings.currentPage + 1) + '">' + __t('下一页') + '</a></li>';
                }
                
                // 末页
                if (settings.showLast && settings.currentPage < settings.totalPages) {
                    html += '<li class="page-item"><a class="page-link" href="javascript:void(0);" data-page="' + settings.totalPages + '">' + __t('末页') + '</a></li>';
                }
                
                html += '</ul></nav>';
                
                $container.html(html);
            },
            
            bindEvents: function($container, settings) {
                $container.off('click.pagination').on('click.pagination', '.page-link', function(e) {
                    e.preventDefault();
                    var page = parseInt($(this).data('page'));
                    if (page && page !== settings.currentPage && typeof settings.onPageChange === 'function') {
                        settings.onPageChange(page);
                    }
                });
            }
        },
        
        // 表格组件
        DataTable: {
            init: function(container, options) {
                var defaults = {
                    data: [],
                    columns: [],
                    pagination: true,
                    pageSize: 10,
                    currentPage: 1,
                    sortable: true,
                    selectable: false,
                    searchable: false,
                    emptyText: __t('暂无数据'),
                    onRowClick: null,
                    onSelectionChange: null
                };
                
                var settings = $.extend({}, defaults, options);
                var $container = $(container);
                
                $container.data('datatable-settings', settings);
                
                this.render($container, settings);
                this.bindEvents($container, settings);
            },
            
            render: function($container, settings) {
                var html = '<div class="datatable-wrapper">';
                
                // 搜索框
                if (settings.searchable) {
                    html += '<div class="datatable-header">';
                    html += '<div class="datatable-search">';
                    html += '<input type="text" class="form-control" placeholder="' + __t('搜索') + '"' + '" />';
                    html += '</div>';
                    html += '</div>';
                }
                
                // 表格
                html += '<div class="table-responsive">';
                html += '<table class="table table-striped">';
                
                // 表头
                html += '<thead><tr>';
                if (settings.selectable) {
                    html += '<th width="50"><input type="checkbox" class="select-all" /></th>';
                }
                
                $.each(settings.columns, function(index, column) {
                    var sortClass = settings.sortable && column.sortable !== false ? ' sortable' : '';
                    var width = column.width ? ' width="' + column.width + '"' : '';
                    html += '<th' + width + ' class="' + sortClass + '" data-field="' + column.field + '">';
                    html += column.title;
                    if (sortClass) {
                        html += ' <i class="sort-icon"></i>';
                    }
                    html += '</th>';
                });
                
                html += '</tr></thead>';
                
                // 表体
                html += '<tbody>';
                html += this.renderRows(settings);
                html += '</tbody>';
                
                html += '</table>';
                html += '</div>';
                
                // 分页
                if (settings.pagination) {
                    html += '<div class="datatable-pagination"></div>';
                }
                
                html += '</div>';
                
                $container.html(html);
                
                // 渲染分页
                if (settings.pagination) {
                    this.renderPagination($container, settings);
                }
            },
            
            renderRows: function(settings) {
                var html = '';
                var data = this.getPageData(settings);
                
                if (data.length === 0) {
                    var colspan = settings.columns.length + (settings.selectable ? 1 : 0);
                    html += '<tr><td colspan="' + colspan + '" class="text-center text-muted py-4">' + settings.emptyText + '</td></tr>';
                } else {
                    $.each(data, function(index, row) {
                        html += '<tr data-index="' + index + '">';
                        
                        if (settings.selectable) {
                            html += '<td><input type="checkbox" class="select-item" value="' + (row.id || index) + '" /></td>';
                        }
                        
                        $.each(settings.columns, function(colIndex, column) {
                            var value = row[column.field] || '';
                            
                            if (typeof column.render === 'function') {
                                value = column.render(value, row, index);
                            } else if (column.type === 'date' && value) {
                                value = TodoUser.formatDate(new Date(value));
                            } else if (column.type === 'currency' && value) {
                                var currSym = (window.TODO && window.TODO.CURRENCY_SYMBOL) || '';
                                value = currSym + parseFloat(value).toFixed(2);
                            }
                            
                            html += '<td>' + value + '</td>';
                        });
                        
                        html += '</tr>';
                    });
                }
                
                return html;
            },
            
            getPageData: function(settings) {
                if (!settings.pagination) {
                    return settings.data;
                }
                
                var start = (settings.currentPage - 1) * settings.pageSize;
                var end = start + settings.pageSize;
                
                return settings.data.slice(start, end);
            },
            
            renderPagination: function($container, settings) {
                if (!settings.pagination || settings.data.length === 0) {
                    return;
                }
                
                var totalPages = Math.ceil(settings.data.length / settings.pageSize);
                var $pagination = $container.find('.datatable-pagination');
                
                TodoComponents.Pagination.init($pagination, {
                    currentPage: settings.currentPage,
                    totalPages: totalPages,
                    onPageChange: function(page) {
                        settings.currentPage = page;
                        $container.find('tbody').html(TodoComponents.DataTable.renderRows(settings));
                        TodoComponents.DataTable.renderPagination($container, settings);
                    }
                });
            },
            
            bindEvents: function($container, settings) {
                // 搜索
                $container.off('input.datatable').on('input.datatable', '.datatable-search input', TodoUser.debounce(function() {
                    var keyword = $(this).val().toLowerCase();
                    settings.filteredData = settings.data.filter(function(row) {
                        return Object.values(row).some(function(value) {
                            return String(value).toLowerCase().indexOf(keyword) !== -1;
                        });
                    });
                    
                    settings.data = keyword ? settings.filteredData : settings.originalData || settings.data;
                    settings.currentPage = 1;
                    
                    $container.find('tbody').html(TodoComponents.DataTable.renderRows(settings));
                    TodoComponents.DataTable.renderPagination($container, settings);
                }, 300));
                
                // 排序
                $container.off('click.datatable-sort').on('click.datatable-sort', 'th.sortable', function() {
                    var $th = $(this);
                    var field = $th.data('field');
                    var currentSort = $th.data('sort') || 'asc';
                    var newSort = currentSort === 'asc' ? 'desc' : 'asc';
                    
                    // 清除其他列的排序状态
                    $container.find('th.sortable').removeClass('sort-asc sort-desc').data('sort', '');
                    
                    // 设置当前列的排序状态
                    $th.addClass('sort-' + newSort).data('sort', newSort);
                    
                    // 排序数据
                    settings.data.sort(function(a, b) {
                        var aVal = a[field] || '';
                        var bVal = b[field] || '';
                        
                        if (typeof aVal === 'string') {
                            aVal = aVal.toLowerCase();
                            bVal = bVal.toLowerCase();
                        }
                        
                        if (newSort === 'asc') {
                            return aVal > bVal ? 1 : -1;
                        } else {
                            return aVal < bVal ? 1 : -1;
                        }
                    });
                    
                    settings.currentPage = 1;
                    $container.find('tbody').html(TodoComponents.DataTable.renderRows(settings));
                    TodoComponents.DataTable.renderPagination($container, settings);
                });
                
                // 行点击
                $container.off('click.datatable-row').on('click.datatable-row', 'tbody tr', function(e) {
                    if ($(e.target).is('input[type="checkbox"]')) {
                        return;
                    }
                    
                    var index = $(this).data('index');
                    var rowData = TodoComponents.DataTable.getPageData(settings)[index];
                    
                    if (typeof settings.onRowClick === 'function') {
                        settings.onRowClick(rowData, index);
                    }
                });
                
                // 选择
                if (settings.selectable) {
                    $container.off('change.datatable-select').on('change.datatable-select', '.select-all', function() {
                        var checked = $(this).prop('checked');
                        $container.find('.select-item').prop('checked', checked);
                        
                        if (typeof settings.onSelectionChange === 'function') {
                            var selected = checked ? TodoComponents.DataTable.getPageData(settings) : [];
                            settings.onSelectionChange(selected);
                        }
                    });
                    
                    $container.off('change.datatable-item').on('change.datatable-item', '.select-item', function() {
                        var total = $container.find('.select-item').length;
                        var checked = $container.find('.select-item:checked').length;
                        
                        $container.find('.select-all').prop('checked', total === checked);
                        
                        if (typeof settings.onSelectionChange === 'function') {
                            var selected = [];
                            $container.find('.select-item:checked').each(function() {
                                var value = $(this).val();
                                var rowData = settings.data.find(function(row) {
                                    return (row.id || row.index) == value;
                                });
                                if (rowData) {
                                    selected.push(rowData);
                                }
                            });
                            settings.onSelectionChange(selected);
                        }
                    });
                }
            },
            
            // 更新数据
            updateData: function(container, data) {
                var $container = $(container);
                var settings = $container.data('datatable-settings');
                
                if (settings) {
                    settings.originalData = settings.originalData || settings.data;
                    settings.data = data;
                    settings.currentPage = 1;
                    
                    $container.find('tbody').html(this.renderRows(settings));
                    this.renderPagination($container, settings);
                }
            },
            
            // 获取选中的行
            getSelected: function(container) {
                var $container = $(container);
                var settings = $container.data('datatable-settings');
                var selected = [];
                
                if (settings) {
                    $container.find('.select-item:checked').each(function() {
                        var value = $(this).val();
                        var rowData = settings.data.find(function(row) {
                            return (row.id || row.index) == value;
                        });
                        if (rowData) {
                            selected.push(rowData);
                        }
                    });
                }
                
                return selected;
            }
        },
        
        // 上传组件
        Uploader: {
            init: function(container, options) {
                var defaults = {
                    url: (window.TODO && window.TODO.join ? window.TODO.join(window.TODO.API_BASE || (((document.querySelector('meta[name="site-url"]')?.getAttribute('content')) || window.location.origin).replace(/\/+$/, '') + '/api'), '/upload') : ((((document.querySelector('meta[name="site-url"]')?.getAttribute('content')) || window.location.origin).replace(/\/+$/, '') + '/api/upload'))),
                    method: 'POST',
                    multiple: false,
                    accept: '*/*',
                    maxSize: 10 * 1024 * 1024, // 10MB
                    maxFiles: 10,
                    autoUpload: true,
                    showPreview: true,
                    onSuccess: null,
                    onError: null,
                    onProgress: null
                };
                
                var settings = $.extend({}, defaults, options);
                var $container = $(container);
                
                $container.data('uploader-settings', settings);
                
                this.render($container, settings);
                this.bindEvents($container, settings);
            },
            
            render: function($container, settings) {
                var html = '<div class="uploader-wrapper">';
                
                // 上传区域
                html += '<div class="upload-area">';
                html += '<input type="file" class="upload-input" style="display: none;"';
                if (settings.multiple) {
                    html += ' multiple';
                }
                if (settings.accept !== '*/*') {
                    html += ' accept="' + settings.accept + '"';
                }
                html += ' />';
                
                html += '<div class="upload-trigger">';
                html += '<i class="icon-upload"></i>';
                html += '<p>' + __t('点击或拖拽文件到此处上传') + '</p>';
                html += '<p class="text-muted">' + __t('支持格式') + ': ' + (settings.accept === '*/*' ? __t('所有格式') : settings.accept) + '</p>';
                html += '</div>';
                html += '</div>';
                
                // 文件列表
                if (settings.showPreview) {
                    html += '<div class="upload-list"></div>';
                }
                
                html += '</div>';
                
                $container.html(html);
            },
            
            bindEvents: function($container, settings) {
                var $input = $container.find('.upload-input');
                var $trigger = $container.find('.upload-trigger');
                var $list = $container.find('.upload-list');
                
                // 点击上传
                $trigger.off('click.uploader').on('click.uploader', function() {
                    $input.click();
                });
                
                // 文件选择
                $input.off('change.uploader').on('change.uploader', function() {
                    var files = this.files;
                    if (files.length > 0) {
                        TodoComponents.Uploader.handleFiles($container, files, settings);
                    }
                });
                
                // 拖拽上传
                $trigger.off('dragover.uploader dragenter.uploader drop.uploader')
                    .on('dragover.uploader dragenter.uploader', function(e) {
                        e.preventDefault();
                        $(this).addClass('drag-over');
                    })
                    .on('dragleave.uploader', function(e) {
                        e.preventDefault();
                        $(this).removeClass('drag-over');
                    })
                    .on('drop.uploader', function(e) {
                        e.preventDefault();
                        $(this).removeClass('drag-over');
                        
                        var files = e.originalEvent.dataTransfer.files;
                        if (files.length > 0) {
                            TodoComponents.Uploader.handleFiles($container, files, settings);
                        }
                    });
            },
            
            handleFiles: function($container, files, settings) {
                var $list = $container.find('.upload-list');
                
                Array.from(files).forEach(function(file, index) {
                    // 检查文件大小
                    if (file.size > settings.maxSize) {
                        TodoUser.showMessage(__t('文件') + ' "' + file.name + '" ' + __t('超过大小限制'), 'error');
                        return;
                    }
                    
                    // 检查文件数量
                    if ($list.children().length >= settings.maxFiles) {
                        TodoUser.showMessage(__t('文件数量超过限制'), 'error');
                        return;
                    }
                    
                    // 添加到列表
                    var fileId = 'file_' + Date.now() + '_' + index;
                    var fileItem = TodoComponents.Uploader.createFileItem(file, fileId);
                    $list.append(fileItem);
                    
                    // 自动上传
                    if (settings.autoUpload) {
                        TodoComponents.Uploader.uploadFile($container, file, fileId, settings);
                    }
                });
            },
            
            createFileItem: function(file, fileId) {
                var html = '<div class="upload-item" data-file-id="' + fileId + '">';
                
                // 预览
                if (file.type.startsWith('image/')) {
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        $('.upload-item[data-file-id="' + fileId + '"] .file-preview').html('<img src="' + e.target.result + '" alt="' + __t('预览') + '" />');
                    };
                    reader.readAsDataURL(file);
                    html += '<div class="file-preview"></div>';
                } else {
                    html += '<div class="file-icon"><i class="icon-file"></i></div>';
                }
                
                html += '<div class="file-info">';
                html += '<div class="file-name">' + file.name + '</div>';
                html += '<div class="file-size">' + TodoUser.formatFileSize(file.size) + '</div>';
                html += '<div class="file-progress"><div class="progress-bar"></div></div>';
                html += '</div>';
                
                html += '<div class="file-actions">';
                html += '<button type="button" class="btn btn-sm btn-danger remove-file">' + __t('删除') + '</button>';
                html += '</div>';
                
                html += '</div>';
                
                return html;
            },
            
            uploadFile: function($container, file, fileId, settings) {
                var $item = $container.find('.upload-item[data-file-id="' + fileId + '"]');
                var $progress = $item.find('.progress-bar');
                
                var formData = new FormData();
                formData.append('file', file);
                
                // 添加CSRF令牌
                if (TodoUser.config.csrfToken) {
                    formData.append('_token', TodoUser.config.csrfToken);
                }
                
                $.ajax({
                    url: settings.url,
                    type: settings.method,
                    data: formData,
                    processData: false,
                    contentType: false,
                    xhr: function() {
                        var xhr = new window.XMLHttpRequest();
                        xhr.upload.addEventListener('progress', function(e) {
                            if (e.lengthComputable) {
                                var percent = Math.round((e.loaded / e.total) * 100);
                                $progress.css('width', percent + '%');
                                
                                if (typeof settings.onProgress === 'function') {
                                    settings.onProgress(percent, file, fileId);
                                }
                            }
                        }, false);
                        return xhr;
                    },
                    success: function(response) {
                        $item.addClass('upload-success');
                        $progress.css('width', '100%');
                        
                        if (typeof settings.onSuccess === 'function') {
                            settings.onSuccess(response, file, fileId);
                        }
                    },
                    error: function(xhr, status, error) {
                        $item.addClass('upload-error');
                        
                        var message = __t('上传失败');
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            message = xhr.responseJSON.message;
                        }
                        
                        $item.find('.file-info').append('<div class="error-message text-danger">' + message + '</div>');
                        
                        if (typeof settings.onError === 'function') {
                            settings.onError(xhr, status, error, file, fileId);
                        }
                    }
                });
            },
            
            // 手动上传
            upload: function(container) {
                var $container = $(container);
                var settings = $container.data('uploader-settings');
                
                $container.find('.upload-item:not(.upload-success):not(.upload-error)').each(function() {
                    var fileId = $(this).data('file-id');
                    var file = $(this).data('file');
                    
                    if (file) {
                        TodoComponents.Uploader.uploadFile($container, file, fileId, settings);
                    }
                });
            },
            
            // 清空文件列表
            clear: function(container) {
                $(container).find('.upload-list').empty();
            }
        }
    };
    
    // 暴露到全局
    window.TodoComponents = TodoComponents;
    
})(jQuery);