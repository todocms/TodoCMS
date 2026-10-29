<?php
/**
 * 媒体上传模态框组件
 *
 * 类似TodoCMS的媒体库实现，包含上传文件和媒体库功能
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DOING_AJAX') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 确保核心函数可用
if (!function_exists('get_option')) {
    require_once dirname(__DIR__, 2) . '/core/functions/core.php';
}

// 确保配置函数可用（todocms风格）
if (!function_exists('todo_get_option')) {
    $___todo_paths = array(
        dirname(__DIR__, 2) . '/core/functions/todo-functions.php',
        dirname(__DIR__, 2) . '/core/classes/class-config-manager.php'
    );
    foreach ($___todo_paths as $___path) {
        if (file_exists($___path)) {
            require_once $___path;
            if (function_exists('todo_get_option')) {
                break;
            }
        }
    }
}

// 确保安全管理器类已加载
if (!class_exists('Todo_Security_Manager')) {
    // 尝试多种路径加载安全管理器
    $security_manager_paths = array(
        ADMIN_DIR . '/includes/class-security-manager.php',
        dirname(__DIR__) . '/includes/class-security-manager.php',
        __DIR__ . '/../includes/class-security-manager.php'
    );
    
    foreach ($security_manager_paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            break;
        }
    }
}

// 确保 todo_csrf_field 函数可用
if (!function_exists('todo_csrf_field')) {
    /**
     * 临时 CSRF 字段函数（如果安全管理器未加载）
     */
    function todo_csrf_field($action, $name = '_csrf_token', $echo = true) {
        if (class_exists('Todo_Security_Manager')) {
            $field = Todo_Security_Manager::csrf_field($action, $name);
            if ($echo) {
                echo $field;
            }
            return $field;
        }
        
        // 备用实现 - 生成简单的token
        $token = md5($action . session_id() . time());
        
        // 简单的属性转义函数
        $esc_name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $esc_token = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
        
        $field = '<input type="hidden" name="' . $esc_name . '" value="' . $esc_token . '" />';
        
        if ($echo) {
            echo $field;
            return '';
        }
        return $field;
    }
}

class TodoMediaModal {

    private static $rendered_modal_ids = array();
    
    /**
     * 渲染媒体选择器模态框
     *
     * @param string|array $modal_id_or_options 模态框ID或选项数组
     * @param array $options 模态框选项（当第一个参数是字符串时使用）
     * @return string HTML内容
     */
    public static function render($modal_id_or_options = array(), $options = array()) {
        // 处理参数兼容性
        if (is_string($modal_id_or_options)) {
            // 第一个参数是字符串，作为modal_id
            $options['modal_id'] = $modal_id_or_options;
        } else {
            // 第一个参数是数组，作为options
            $options = $modal_id_or_options;
        }
        $defaults = array(
            'title' => '选择或上传媒体',
            'button_text' => '选择文件',
            'multiple' => false,
            'type' => 'file', // image, video, audio, file
            'modal_id' => 'media-modal',
            'input_id' => '',
            'preview_id' => '',
            'callback' => ''
        );
        
        // 确保$options是数组
        if (!is_array($options)) {
            $options = array();
        }
        
        $options = array_merge($defaults, $options);

        $modal_id = isset($options['modal_id']) ? (string) $options['modal_id'] : 'media-modal';
        if (isset(self::$rendered_modal_ids[$modal_id])) {
            return '';
        }
        self::$rendered_modal_ids[$modal_id] = true;

        $current_user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $current_user_obj = ($current_user_id > 0 && class_exists('CMS_User')) ? new CMS_User($current_user_id) : null;
        $is_platform_admin = false;
        if ($current_user_id > 0 && function_exists('todo_is_super_admin') && todo_is_super_admin($current_user_id)) {
            $is_platform_admin = true;
        } elseif (is_object($current_user_obj) && method_exists($current_user_obj, 'has_role')) {
            $is_platform_admin = $current_user_obj->has_role('administrator') || $current_user_obj->has_role('admin');
        }
        
        // 解析提示集匹配规则 [title弹窗标题|id弹窗ID|提示信息]
        $notice_html = '';
        $raw_notice_rules = function_exists('get_option') ? (string) get_option('media_modal_notice_rules', '') : '';
        if ($raw_notice_rules !== '') {
            if (preg_match_all('/\[([^\|\]]*)\|([^\|\]]*)\|([^\]]*)\]/u', $raw_notice_rules, $matches, PREG_SET_ORDER)) {
                $matched_notice = '';
                $current_title = trim((string) $options['title']);
                $current_id = trim((string) $options['modal_id']);

                // 匹配优先级：
                // 1. title + id 双重精准匹配
                // 2. 仅 title 精准匹配（rule_id 为空）
                // 3. 仅 id 匹配（rule_title 为空）
                foreach ($matches as $m) {
                    $rule_title = trim($m[1]);
                    $rule_id = trim($m[2]);
                    $rule_notice = trim($m[3]);
                    if ($rule_title !== '' && $rule_id !== '' && $rule_title === $current_title && $rule_id === $current_id) {
                        $matched_notice = $rule_notice;
                        break;
                    }
                }
                if ($matched_notice === '') {
                    foreach ($matches as $m) {
                        $rule_title = trim($m[1]);
                        $rule_id = trim($m[2]);
                        $rule_notice = trim($m[3]);
                        if ($rule_title !== '' && $rule_title === $current_title && $rule_id === '') {
                            $matched_notice = $rule_notice;
                            break;
                        }
                    }
                }
                if ($matched_notice === '') {
                    foreach ($matches as $m) {
                        $rule_title = trim($m[1]);
                        $rule_id = trim($m[2]);
                        $rule_notice = trim($m[3]);
                        if ($rule_id !== '' && $rule_id === $current_id && $rule_title === '') {
                            $matched_notice = $rule_notice;
                            break;
                        }
                    }
                }
                if ($matched_notice !== '') {
                    $notice_html = '<span class="media-modal-notice">' . $matched_notice . '</span>';
                }
            }
        }

        ob_start();
        ?>
        <div id="<?php echo esc_attr($options['modal_id']); ?>" class="media-modal-overlay" data-multiple="<?php echo $options['multiple'] ? 'true' : 'false'; ?>" data-type="<?php echo esc_attr($options['type']); ?>" data-callback="<?php echo esc_attr($options['callback']); ?>" data-raw-title="<?php echo esc_attr($options['title']); ?>">
            <div class="media-modal-container">
                <div class="media-modal-header">
                    <h2 class="media-modal-title"><?php echo $options['title'] . $notice_html; ?></h2>
                    <button type="button" class="media-modal-close">✕</button>
                </div>
                
                <div class="media-modal-content">
                    <!-- 标签页导航 -->
                    <div class="media-modal-tabs">
                        <button type="button" class="media-tab-button active" data-tab="upload">
                            <i class="htmlicon">↟</i>
                            上传文件
                        </button>
                        <button type="button" class="media-tab-button" data-tab="library">
                            <i class="htmlicon" style="font-size: 12px;">♫</i>
                            媒体库
                        </button>
                        <button type="button" class="media-tab-button" data-tab="directory">
                            <i class="htmlicon" style="font-size: 12px;">Ξ</i>
                            文件目录
                            <?php if ($is_platform_admin): ?>
                                <span class="clear-folder" id="clear-folder" title="清理空文件夹"><i class="icon todofont todo-Clear"></i></span>
                            <?php endif; ?>
                        </button>
                        <div class="directory-tree" id="directory-tree" style="display: none;">
                            <div class="directory-loading">
                                <span class="spinner"></span>
                                加载目录中...
                            </div>
                        </div>
                    </div>
                    
                    <!-- 上传文件标签页 -->
                    <div class="media-tab-content" id="upload-tab">
                        <div class="upload-area">
                            <div class="upload-dropzone" id="upload-dropzone">
                                <div class="upload-dropzone-content">
                                    <div class="upload-icon">
                                        ↑
                                    </div>
                                    <h3>拖拽文件到这里上传</h3>
                                    <p>或者</p>
                                    <button type="button" class="button button-primary" id="select-files-btn">
                                        选择文件
                                    </button>
                                    <input type="file" id="file-input" style="display: none;" 
                                           <?php echo $options['multiple'] ? 'multiple' : ''; ?>
                                           accept="<?php echo self::get_accept_types($options['type']); ?>">
                                </div>
                            </div>
                            
                            <!-- URL输入区域 -->
                            <div class="url-upload-area">
                                <div class="url-upload-section">
                                    <h4>通过URL添加图片</h4>
                                    <div class="url-input-group">
                                        <input type="url" id="<?php echo esc_attr($options['modal_id']); ?>-image-url-input" placeholder="输入图片URL地址..." class="url-input">
                                        <button type="button" class="button button-secondary" id="add-url-btn">添加图片</button>
                                    </div>

                                </div>
                            </div>
                            
                            <!-- 上传进度 -->
                            <div class="upload-progress" id="upload-progress" style="display: none;">
                                <div class="progress-bar">
                                    <div class="progress-fill" id="progress-fill"></div>
                                </div>
                                <div class="progress-text" id="progress-text">上传中...</div>
                            </div>
                            
                            <!-- 上传结果 -->
                            <div class="upload-results" id="upload-results"></div>
                        </div>
                    </div>
                    
                    <!-- 媒体库标签页 -->
                    <div class="media-tab-content p-0" id="library-tab" style="display: none;">
                        <div class="flex justify-between h-100">
                            <div class="media-library flex flex-col">
                                <!-- 搜索和过滤 -->
                                <div class="media-toolbar">
                                    <div class="media-search">
                                        <input type="search" id="<?php echo esc_attr($options['modal_id']); ?>-media-search" placeholder="搜索媒体文件...">
                                    </div>
                                    <div class="media-filter">
                                        <select id="<?php echo esc_attr($options['modal_id']); ?>-media-type-filter">
                                            <option value="">所有类型</option>
                                            <option value="image">图片</option>
                                            <option value="video">视频</option>
                                            <option value="audio">音频</option>
                                            <option value="document">文档</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <!-- 媒体网格 -->
                                <div class="media-grid flex flex-wrap h-100 over-auto" id="media-grid">
                                    <div class="loading-spinner" id="media-loading">
                                        <span class="spinner"></span>
                                        加载中...
                                    </div>
                                </div>
                                
                                <!-- 分页 -->
                                <div class="media-pagination" id="media-pagination" style="display: none;">
                                    <button type="button" id="prev-btn" class="btn button-primary pagination-btn" style="margin-left: auto;" disabled>上一页</button>
                                    <span id="pagination-info" class="pagination-info">第 1 页，共 1 页</span>
                                    <button type="button" id="next-btn" class="btn button-primary pagination-btn" disabled>下一页</button>
                                </div>
                            </div>
                            <!-- 媒体库详情侧边栏 -->
                            <div class="media-sidebar" id="media-sidebar" style="display: none;">
                                <div class="media-details">
                                    <div class="media-actions">
                                        <button type="button" class="button button-primary" id="select-media-btn">
                                            <?php echo esc_html($options['button_text']); ?>
                                        </button>
                                    </div>
                                    <button type="button" class="btn button-secondary m-b-20" id="parse-docx-btn-media" style="display:none;"><i class="icon todofont todo-FileCodeOne"></i> 解析内容导入</button>
                                    <div class="media-preview" id="media-preview"></div>
                                    <div class="media-info" id="media-info"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 文件目录标签页 -->
                    <div class="media-tab-content p-0" id="directory-tab" style="display: none;">
                        <div class="flex justify-between h-100">
                            <div class="media-library flex flex-col">
                                <!-- 搜索和过滤 -->
                                <div class="media-toolbar">
                                    <div class="media-search">
                                        <input type="search" id="<?php echo esc_attr($options['modal_id']); ?>-directory-search" placeholder="查找当前目录(不含子级)文件...">
                                    </div>
                                    <div class="media-filter">
                                        <select id="<?php echo esc_attr($options['modal_id']); ?>-directory-type-filter">
                                            <option value="">所有类型</option>
                                            <option value="image">图片</option>
                                            <option value="video">视频</option>
                                            <option value="audio">音频</option>
                                            <option value="document">文档</option>
                                            <option value="unlink">未记录</option>
                                        </select>
                                        <div class="flex items-center gap-2" id="directory-bulk-actions" style="margin: -2px 0px; display: none;">
                                            <label for="<?php echo esc_attr($options['modal_id']); ?>-directory-select-all" class="nofor shrink-0" style="display: inline-flex; align-items: center; gap: 6px;">
                                                <input type="checkbox" name="directory-select-all" id="<?php echo esc_attr($options['modal_id']); ?>-directory-select-all">
                                                <span id="<?php echo esc_attr($options['modal_id']); ?>-directory-select-all-text" style="font-size: 14px; margin: 0;">全选</span>
                                            </label>
                                            <?php if ($is_platform_admin): ?>
                                                <button type="button" class="button button-secondary shrink-0 delete-btn" id="delete-directory-btn">删除</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- 媒体网格 -->
                                <div class="media-grid flex flex-wrap h-100 over-auto" id="directory-media-grid">
                                    <div class="no-media">
                                        请点击左侧目录查看文件
                                    </div>
                                </div>

                                <!-- 分页 -->
                                <div class="media-pagination" id="directory-pagination" style="display: none;">
                                    <select id="directory-per-page" class="per-page-select" style="padding: 0 10px;min-height: 20px;min-width: 100px;font-size: 12px;">
                                        <option value="20" selected>20条/页</option>
                                        <option value="100">100条/页</option>
                                        <option value="200">200条/页</option>
                                        <option value="500">500条/页</option>
                                    </select>
                                    <button type="button" id="directory-prev-btn" class="btn button-primary pagination-btn" style="margin-left: auto;" disabled>上一页</button>
                                    <span id="directory-pagination-info" class="pagination-info">第 1 页，共 1 页</span>
                                    <button type="button" id="directory-next-btn" class="btn button-primary pagination-btn" disabled>下一页</button>
                                </div>
                            </div>
                            <!-- 文件目录详情侧边栏 -->
                            <div class="media-sidebar" id="directory-sidebar" style="display: none;">
                                <div class="media-details">
                                    <div class="media-actions flex justify-between items-center">
                                        <button type="button" class="button button-primary" id="select-directory-btn">
                                            <?php echo esc_html($options['button_text']); ?>
                                        </button>
                                    </div>
                                    <button type="button" class="btn button-secondary m-b-20" id="parse-docx-btn" style="display:none;"><i class="icon todofont todo-FileCodeOne"></i> 解析内容导入</button>
                                    <div class="media-preview" id="directory-preview"></div>
                                    <div class="media-info" id="directory-info"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- JavaScript -->
        <script type="text/javascript">
        window.initTodoMediaModalLogic_<?php echo md5($options['modal_id']); ?> = function() {
            // 传递PHP选项到JavaScript
            const options = <?php echo json_encode($options, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            
            // 动态设置AJAX路径：使用PHP生成的绝对路径，避免相对路径在不同URL结构（如缺少尾部斜杠）下失效
            const ajaxBasePath = '<?php echo rtrim(todo_get_site_url(), '/') . '/admin/ajax/'; ?>';
            const ajaxUrlSuffix = '<?php 
                $params = [];
                if (isset($_GET['site']) && is_numeric($_GET['site']) && (int) $_GET['site'] > 0) {
                    $params[] = 'site=' . (int) $_GET['site'];
                } elseif (!$is_platform_admin && function_exists('todo_current_site_id') && todo_current_site_id() > 0) {
                    $params[] = 'site=' . todo_current_site_id();
                }
                if (function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()) {
                    $params[] = 'format=iframe';
                }
                echo !empty($params) ? '?' . implode('&', $params) : '';
            ?>';

            const addQueryArg = (url, params) => {
                if (!params) return url;
                let p = typeof params === 'string' ? params : new URLSearchParams(params).toString();
                p = p.replace(/^\?+/, '');
                if (!p) return url;
                return url + (url.indexOf('?') !== -1 ? '&' : '?') + p;
            };

            const domTool = (window.TodoDOM && typeof window.TodoDOM.get === 'function') ? window.TodoDOM : {
                get: function(id) {
                    if (!id) return null;
                    let el = null;
                    try { el = document.getElementById(id); } catch (e) {}
                    if (!el && window.parent && window.parent !== window) {
                        try { el = window.parent.document.getElementById(id); } catch (e) {}
                    }
                    return el;
                }
            };
            
            // 全局AJAX路径配置
            window.TodoMediaAjaxPaths = {
                mediaLibrary: addQueryArg(ajaxBasePath + 'media-library.php', ajaxUrlSuffix),
                mediaUpload: addQueryArg(ajaxBasePath + 'media-upload.php', ajaxUrlSuffix),
                mediaUrlDownload: addQueryArg(ajaxBasePath + 'media-url-download.php', ajaxUrlSuffix),
                directoryTree: addQueryArg(ajaxBasePath + 'directory-tree.php', ajaxUrlSuffix),
                directoryContent: addQueryArg(ajaxBasePath + 'directory-content.php', ajaxUrlSuffix),
                mediaAddExisting: addQueryArg(ajaxBasePath + 'media-add-existing.php', ajaxUrlSuffix)
            };
            
            // 媒体设置配置
            window.TodoMediaConfig = {
                enableUpload: <?php echo json_encode(todo_get_option('enable_upload', true)); ?>,
                allowedFileTypes: '<?php echo esc_js(todo_get_option('allowed_file_types', 'jpg,jpeg,png,gif,webp,svg,mp4,avi,mov,mp3,wav,pdf,doc,docx,xls,xlsx,txt,rar,zip')); ?>',
                // 使用统一的上传配置来源，获取字节数；若不可用则回退到“上传设置”中的 max_upload_size（MB）
                maxFileSize: <?php
                    $___todo_max_bytes = null;
                    if (class_exists('TodoUploadConfig')) {
                        $___todo_max_bytes = (int) TodoUploadConfig::get_max_upload_size();
                    } else {
                        $___todo_mb = (int) todo_get_option('max_upload_size', 10);
                        $___todo_max_bytes = ($___todo_mb > 0 ? $___todo_mb : 10) * 1024 * 1024;
                    }
                    echo $___todo_max_bytes;
                ?> // bytes
            };
            
            // 初始化CSRF token
            if (window.TodoCSRFManager && !TodoCSRFManager.getToken('upload_media')) {
                // 如果没有upload_media的token，创建一个隐藏的表单来初始化token
                const hiddenForm = document.createElement('form');
                hiddenForm.style.display = 'none';
                hiddenForm.innerHTML = '<?php echo todo_csrf_field("upload_media", "_todononce", false); ?><input type="hidden" name="action" value="upload_media">';
                document.body.appendChild(hiddenForm);
                
                // 重新收集tokens
                if (TodoCSRFManager.collectTokens) {
                    TodoCSRFManager.collectTokens();
                }
            }
            
            // 初始化 directory_tree CSRF 令牌
            if (window.TodoCSRFManager && !TodoCSRFManager.getToken('directory_tree')) {
                const hiddenForm = document.createElement('form');
                hiddenForm.style.display = 'none';
                hiddenForm.innerHTML = '<?php echo Todo_Security_Manager::csrf_field("directory_tree", "_csrf_token"); ?><input type="hidden" name="action" value="directory_tree">';
                document.body.appendChild(hiddenForm);
                
                // 重新收集tokens
                if (TodoCSRFManager.collectTokens) {
                    TodoCSRFManager.collectTokens();
                }
            }
            
            // 初始化 url_download CSRF 令牌
            if (window.TodoCSRFManager && !TodoCSRFManager.getToken('url_download')) {
                const hiddenForm = document.createElement('form');
                hiddenForm.style.display = 'none';
                hiddenForm.innerHTML = '<?php echo todo_csrf_field("url_download", "_todononce", false); ?><input type="hidden" name="action" value="url_download">';
                document.body.appendChild(hiddenForm);
                
                // 重新收集tokens
                if (TodoCSRFManager.collectTokens) {
                    TodoCSRFManager.collectTokens();
                }
            }
            
            // 初始化 directory_content CSRF 令牌
            if (window.TodoCSRFManager && !TodoCSRFManager.getToken('directory_content')) {
                const hiddenForm = document.createElement('form');
                hiddenForm.style.display = 'none';
                hiddenForm.innerHTML = '<?php echo todo_csrf_field("directory_content", "_todononce", false); ?><input type="hidden" name="action" value="directory_content">';
                document.body.appendChild(hiddenForm);
                
                // 重新收集tokens
                if (TodoCSRFManager.collectTokens) {
                    TodoCSRFManager.collectTokens();
                }
            }

            // 初始化 media-library CSRF 令牌（用于“添加现有文件入库”）
            if (window.TodoCSRFManager && !TodoCSRFManager.getToken('media-library')) {
                const hiddenForm = document.createElement('form');
                hiddenForm.style.display = 'none';
                hiddenForm.innerHTML = '<?php echo Todo_Security_Manager::csrf_field("media-library", "_csrf_token"); ?><input type="hidden" name="action" value="media-library">';
                document.body.appendChild(hiddenForm);

                // 重新收集tokens
                if (TodoCSRFManager.collectTokens) {
                    TodoCSRFManager.collectTokens();
                }
            }

            // 初始化 get_unlinked_files CSRF 令牌（用于“未记录”筛选）
            if (window.TodoCSRFManager && !TodoCSRFManager.getToken('get_unlinked_files')) {
                const hiddenForm = document.createElement('form');
                hiddenForm.style.display = 'none';
                hiddenForm.innerHTML = '<?php echo Todo_Security_Manager::csrf_field("get_unlinked_files", "_csrf_token"); ?><input type="hidden" name="action" value="get_unlinked_files">';
                document.body.appendChild(hiddenForm);

                // 重新收集tokens
                if (TodoCSRFManager.collectTokens) {
                    TodoCSRFManager.collectTokens();
                }
            }
            // 初始化 parse_docx CSRF 令牌
            if (window.TodoCSRFManager && !TodoCSRFManager.getToken('parse_docx')) {
                const hiddenForm = document.createElement('form');
                hiddenForm.style.display = 'none';
                hiddenForm.innerHTML = '<?php echo todo_csrf_field("parse_docx", "_todononce", false); ?><input type="hidden" name="action" value="parse_docx">';
                document.body.appendChild(hiddenForm);

                if (TodoCSRFManager.collectTokens) {
                    TodoCSRFManager.collectTokens();
                }
            }

            const targetId = '<?php echo esc_js($options['modal_id']); ?>';

            const modal = (function() {
                let m = null;
                try { m = document.getElementById(targetId); } catch(e) {}
                if (!m) {
                    try { m = document.querySelector('.media-modal-overlay'); } catch(e) {}
                }
                
                if (!m && window.parent && window.parent !== window) {
                    try {
                        if (window.parent.document) {
                            var parentEl = window.parent.document.getElementById(targetId);
                            if (parentEl) return parentEl;
                        }
                    } catch (e) {}
                }
                return m;
            })();
            
            if (!modal) return;

            function getModalBody() {
                try {
                    if (window.parent && window.parent !== window && window.parent.document && window.parent.document.body) {
                        const parentDoc = window.parent.document;
                        if (parentDoc.getElementById(targetId)) {
                            return parentDoc.body;
                        }
                    }
                } catch (e) {}
                
                try {
                    const doc = (modal && modal.ownerDocument) ? modal.ownerDocument : document;
                    return (doc && doc.body) ? doc.body : document.body;
                } catch (e) {
                    return document.body;
                }
            }
            
            // 永远将 modal 追加移动到 document.body 底部，彻底防止嵌入任何局部容器内引发样式或模糊异常
            (function relocateModalToBody() {
                try {
                    const doc = (modal && modal.ownerDocument) ? modal.ownerDocument : document;
                    if (doc && doc.body && modal.parentElement !== doc.body) {
                        doc.body.appendChild(modal);
                    }
                } catch (e) {}
            })();

            if (modal.getAttribute('data-initialized') === 'true') {
                return;
            }
            
            modal.setAttribute('data-initialized', 'true');
            
            const closeBtn = modal.querySelector('.media-modal-close');
            const tabButtons = modal.querySelectorAll('.media-tab-button');
            const tabContents = modal.querySelectorAll('.media-tab-content');
            const selectFilesBtn = modal.querySelector('#select-files-btn');
            const fileInput = modal.querySelector('#file-input');
            const uploadDropzone = modal.querySelector('#upload-dropzone');
            const uploadProgress = modal.querySelector('#upload-progress');
            const progressFill = modal.querySelector('#progress-fill');
            const progressText = modal.querySelector('#progress-text');
            const uploadResults = modal.querySelector('#upload-results');
            const mediaGrid = modal.querySelector('#media-grid');
            const mediaLoading = modal.querySelector('#media-loading');
            // 媒体库tab的详情元素
            const mediaSidebar = modal.querySelector('#media-sidebar');
            const mediaPreview = modal.querySelector('#media-preview');
            const mediaInfo = modal.querySelector('#media-info');
            const selectMediaBtn = modal.querySelector('#select-media-btn');
            
            // 文件目录tab的详情元素
            const directorySidebar = modal.querySelector('#directory-sidebar');
            const directoryPreview = modal.querySelector('#directory-preview');
            const directoryInfo = modal.querySelector('#directory-info');
            const selectDirectoryBtn = modal.querySelector('#select-directory-btn');
            
            const mediaSearch = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-media-search');
            const mediaTypeFilter = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-media-type-filter');
            
            let selectedMedia = null;
            // 目录标签页的多选状态数组（用于未入库文件的多选管理）
            let selectedMediaItems = [];
            let currentPage = 1;
            let totalPages = 1;
            let mediaLibraryRequestId = 0;
            let mediaPaginationLoadingKey = null;
            let searchTimeout = null;
            let currentTab = 'upload'; // 当前活动标签页
            let currentDirectory = ''; // 当前目录
            let directoryTreeLoaded = false; // 目录树是否已加载
            let currentDirectoryPath = ''; // 当前目录路径
            let directoryPaginationLoadingKey = null;
            // 分页与滚动恢复状态（用于区分打开弹窗时恢复到缓存页/滚动位置，还是手动切换时回到第一页/顶部）
            let libraryRestorePage = 1;
            let libraryRestoreScrollTop = 0;
            let directoryRestorePage = 1;
            let directoryRestoreScrollTop = 0;
            
            // 选择状态缓存（按模态框ID存储），用于关闭后再次打开时恢复选中项
            let selectionCache = window.TodoMediaSelectionCache || {};
            const modalKey = '<?php echo esc_js($options['modal_id']); ?>';

            // 分页与滚动状态本地缓存（按模态框ID隔离，区分媒体库与文件目录两个容器）
            const modalStateStorageKey = 'todo_media_pagination_cache';

            function readModalStateCache() {
                try {
                    const raw = localStorage.getItem(modalStateStorageKey);
                    return raw ? (JSON.parse(raw) || {}) : {};
                } catch (e) {
                    return {};
                }
            }

            function writeModalStateCache(cache) {
                try {
                    localStorage.setItem(modalStateStorageKey, JSON.stringify(cache));
                } catch (e) {
                    console.warn('保存媒体弹窗状态缓存失败:', e);
                }
            }

            function getViewState(view) {
                try {
                    const cache = readModalStateCache();
                    const root = cache[modalKey] || {};
                    let viewEntry = root[view] || {};
                    // 兼容旧版仅保存媒体库页码的数据结构 { page: number }
                    if (view === 'library' && !viewEntry.page && root.page && Number(root.page) >= 1) {
                        viewEntry = { page: Math.floor(Number(root.page)), scrollTop: 0 };
                    }
                    const page = viewEntry.page && Number(viewEntry.page) >= 1 ? Math.floor(Number(viewEntry.page)) : 1;
                    const scrollTop = viewEntry.scrollTop && Number(viewEntry.scrollTop) >= 0 ? Math.floor(Number(viewEntry.scrollTop)) : 0;
                    return { page: page, scrollTop: scrollTop };
                } catch (e) {
                    return { page: 1, scrollTop: 0 };
                }
            }

            function saveViewPage(view, page) {
                try {
                    const cache = readModalStateCache();
                    const root = cache[modalKey] || {};
                    const viewEntry = root[view] || {};
                    const pageNum = Math.floor(Number(page));
                    if (pageNum >= 1) {
                        viewEntry.page = pageNum;
                    } else {
                        delete viewEntry.page;
                    }
                    root[view] = viewEntry;
                    cache[modalKey] = root;
                    writeModalStateCache(cache);
                } catch (e) {
                    console.warn('保存媒体分页缓存失败:', e);
                }
            }

            function clearViewPage(view) {
                try {
                    const cache = readModalStateCache();
                    const root = cache[modalKey];
                    if (!root || !root[view]) return;
                    delete root[view].page;
                    writeModalStateCache(cache);
                } catch (e) {
                    console.warn('清除媒体分页缓存失败:', e);
                }
            }

            function saveViewScroll(view, scrollTop) {
                try {
                    const cache = readModalStateCache();
                    const root = cache[modalKey] || {};
                    const viewEntry = root[view] || {};
                    let st = Math.floor(Number(scrollTop));
                    if (!st || st < 0) st = 0;
                    viewEntry.scrollTop = st;
                    root[view] = viewEntry;
                    cache[modalKey] = root;
                    writeModalStateCache(cache);
                } catch (e) {
                    console.warn('保存媒体滚动位置缓存失败:', e);
                }
            }

            function saveSelectionState() {
                try {
                    const isMultiple = modal.getAttribute('data-multiple') === 'true';
                    selectionCache[modalKey] = {
                        tab: currentTab,
                        items: Array.isArray(selectedMediaItems) ? selectedMediaItems.slice() : [],
                        last: selectedMedia || null,
                        isMultiple: isMultiple
                    };
                    window.TodoMediaSelectionCache = selectionCache;
                } catch (e) {
                    console.warn('保存媒体选择状态失败:', e);
                }
            }
            
            function getDirname(path) {
                if (!path || typeof path !== 'string') return '';
                const idx = path.lastIndexOf('/');
                if (idx <= 0) return '';
                return path.slice(0, idx);
            }
            
            // 在当前tab恢复已缓存的选中状态，并自动展开详情侧栏
            function restoreSelectionInCurrentTab() {
                try {
                    const cached = selectionCache[modalKey];
                    if (!cached || !cached.items || cached.items.length === 0) return;
                    
                    if (currentTab === 'library') {
                        const grid = modal.querySelector('#media-grid');
                        if (!grid) return;
                        const selectedIds = cached.items.map(it => it.id).filter(Boolean);
                        const selectedPaths = cached.items.map(it => it.path).filter(Boolean);
                        grid.querySelectorAll('.media-item').forEach(el => {
                            const id = el.dataset.id;
                            const path = el.dataset.path;
                            if ((id && selectedIds.includes(id)) || (path && selectedPaths.includes(path))) {
                                el.classList.add('selected');
                            }
                        });
                        selectedMediaItems = cached.items.slice();
                        selectedMedia = cached.last || cached.items[cached.items.length - 1] || null;
                        if (selectedMedia) {
                            displayMediaDetails(selectedMedia);
                            if (mediaSidebar) mediaSidebar.style.display = 'block';
                        }
                    } else if (currentTab === 'directory') {
                        const grid = modal.querySelector('#directory-media-grid');
                        if (!grid) return;
                        const selectedPaths = cached.items.map(it => it.path).filter(Boolean);
                        grid.querySelectorAll('#directory-media-grid .media-item').forEach(el => {
                            const path = el.dataset.path;
                            if (path && selectedPaths.includes(path)) {
                                el.classList.add('selected');
                                const checkbox = el.querySelector('input[type="checkbox"]');
                                if (checkbox) checkbox.checked = true;
                            }
                        });
                        selectedMediaItems = cached.items.slice();
                        const last = cached.items[cached.items.length - 1] || null;
                        if (last) {
                            showUnlinkedFileDetails(last);
                            if (directorySidebar) directorySidebar.style.display = 'block';
                        }
                    }
                } catch (e) {
                    console.warn('恢复媒体选择状态失败:', e);
                }
            }
            
            // 关闭模态框
            function closeModal() {
                // 关闭前保存当前选择状态，便于下次打开时恢复
                saveSelectionState();
                
                // 关闭前保存两个容器的滚动位置，便于下次打开恢复
                if (mediaGrid) saveViewScroll('library', mediaGrid.scrollTop);
                const dirScrollGrid = modal.querySelector('#directory-media-grid');
                if (dirScrollGrid) saveViewScroll('directory', dirScrollGrid.scrollTop);
                
                modal.classList.remove('show');
                modal.style.display = 'none';
                const modalBody = getModalBody();
                modalBody.style.overflow = '';
                modalBody.classList.remove('show-modal');
                modalBody.removeAttribute('data-overlay');
                
                // 清空内存中的选择状态（不影响已缓存的状态）
                selectedMedia = null;
                selectedMediaItems = [];
                
                // 隐藏所有tab的详情侧边栏与解析按钮
                mediaSidebar.style.display = 'none';
                directorySidebar.style.display = 'none';
                modal.querySelectorAll('#parse-docx-btn, #parse-docx-btn-media').forEach(btn => {
                    btn.style.display = 'none';
                });
                
                // 清除所有选中样式与详情内容，避免下次打开状态不一致
                modal.querySelectorAll('.media-item.selected').forEach(el => el.classList.remove('selected'));
                if (mediaPreview) mediaPreview.innerHTML = '';
                if (mediaInfo) mediaInfo.innerHTML = '';
                if (directoryPreview) directoryPreview.innerHTML = '';
                if (directoryInfo) directoryInfo.innerHTML = '';
                
                // 关闭后恢复多选状态为 false，避免下次打开残留为多选
                modal.setAttribute('data-multiple', 'false');
                
                // 如果在media页面，关闭模态框后自动刷新页面
                const urlParams = new URLSearchParams(window.location.search);
                const currentPage = urlParams.get('page');
                if (currentPage === 'media') {
                    window.location.reload();
                }
            }
            
            // 显示模态框
            function showModal() {
                modal.style.display = 'flex';
                modal.style.alignItems = 'center';
                modal.style.justifyContent = 'center';
                modal.classList.add('show');
                const modalBody = getModalBody();
                modalBody.style.overflow = 'hidden';
                modalBody.classList.add('show-modal');
                modalBody.setAttribute('data-overlay', modal.id);
                
                // 初始化过滤器状态
                initializeFilters();
                
                // 如果存在缓存的选择状态，优先恢复到对应tab
                const cached = window.TodoMediaSelectionCache && window.TodoMediaSelectionCache['<?php echo esc_js($options['modal_id']); ?>'];
                if (cached && cached.tab) {
                    // 切换到缓存的tab（不会触发加载两次，因为 switchTab 内部处理）
                    // 打开时恢复上次浏览的页码与滚动位置（媒体库和文件目录分别处理）
                    switchTab(cached.tab, { restorePage: true });
                }
                
                // 根据设置禁用上传交互
                if (window.TodoMediaConfig && window.TodoMediaConfig.enableUpload === false) {
                    if (selectFilesBtn) {
                        selectFilesBtn.disabled = true;
                        selectFilesBtn.title = '已关闭媒体上传功能（设置-媒体-启用文件上传功能）';
                    }
                    if (fileInput) {
                        fileInput.disabled = true;
                    }
                    if (uploadDropzone) {
                        uploadDropzone.classList.add('disabled');
                        uploadDropzone.style.opacity = '0.6';
                        uploadDropzone.style.pointerEvents = 'none';
                        const content = uploadDropzone.querySelector('.upload-dropzone-content');
                        if (content) {
                            // 避免重复生成提示：若已有多个，清理为单个
                            const tips = content.querySelectorAll('.upload-disabled-tip');
                            if (tips.length === 0) {
                                const tip = document.createElement('div');
                                tip.className = 'upload-disabled-tip';
                                tip.style.marginTop = '8px';
                                tip.style.color = '#dc3545';
                                tip.textContent = '当前已关闭上传功能，您仍可从媒体库选择已有文件。';
                                content.appendChild(tip);
                            } else {
                                // 统一提示文本，并移除重复项
                                tips[0].textContent = '当前已关闭上传功能，您仍可从媒体库选择已有文件。';
                                for (let i = 1; i < tips.length; i++) {
                                    tips[i].remove();
                                }
                            }
                        }
                    }
                    const addUrlBtn = modal.querySelector('#add-url-btn');
                    if (addUrlBtn) {
                        addUrlBtn.disabled = true;
                        addUrlBtn.title = '已关闭媒体上传功能（设置-媒体-启用文件上传功能）';
                    }
                } else {
                    // 恢复上传交互（若曾被禁用）
                    if (selectFilesBtn) {
                        selectFilesBtn.disabled = false;
                        selectFilesBtn.removeAttribute('title');
                    }
                    if (fileInput) {
                        fileInput.disabled = false;
                    }
                    if (uploadDropzone) {
                        uploadDropzone.classList.remove('disabled');
                        uploadDropzone.style.opacity = '';
                        uploadDropzone.style.pointerEvents = '';
                        const content = uploadDropzone.querySelector('.upload-dropzone-content');
                        if (content) {
                            const tips = content.querySelectorAll('.upload-disabled-tip');
                            tips.forEach(t => t.remove());
                        }
                    }
                    const addUrlBtn2 = modal.querySelector('#add-url-btn');
                    if (addUrlBtn2) {
                        addUrlBtn2.disabled = false;
                        addUrlBtn2.removeAttribute('title');
                    }
                }
                
                if (!cached || !cached.tab) {
                    const libState = getViewState('library');
                    libraryRestorePage = libState.page;
                    libraryRestoreScrollTop = libState.scrollTop;
                    loadMediaLibrary(libraryRestorePage);
                }
            }
            
            // 更新选择按钮文本
            function updateSelectButtonText() {
                const isMultiple = modal.getAttribute('data-multiple') === 'true';
                const selectMediaBtn = modal.querySelector('#select-media-btn');
                const selectDirectoryBtn = modal.querySelector('#select-directory-btn');
                
                // 获取基础按钮文本（去掉多选提示）
                let baseText = '选择';
                if (selectMediaBtn && selectMediaBtn.textContent) {
                    baseText = selectMediaBtn.textContent.replace(' (可多选)', '');
                }
                
                // 更新按钮文本
                const finalText = isMultiple ? baseText + ' (可多选)' : baseText;
                
                if (selectMediaBtn) {
                    selectMediaBtn.textContent = finalText;
                }
                if (selectDirectoryBtn) {
                    selectDirectoryBtn.textContent = finalText;
                }
            }
            
            // 更新目录选择按钮文本
            function updateDirectorySelectButtonText() {
                const isMultiple = modal.getAttribute('data-multiple') === 'true';
                const selectDirectoryBtn = modal.querySelector('#select-directory-btn');
                
                if (selectDirectoryBtn) {
                    // 获取基础按钮文本（去掉多选提示）
                    let baseText = '选择目录';
                    if (selectDirectoryBtn.textContent) {
                        baseText = selectDirectoryBtn.textContent.replace(' (可多选)', '');
                    }
                    
                    // 更新按钮文本
                    const finalText = isMultiple ? baseText + ' (可多选)' : baseText;
                    selectDirectoryBtn.textContent = finalText;
                }
            }

            // 标签页切换
            function switchTab(tabName, opts) {
                // 更新当前活动标签页
                currentTab = tabName;
                
                tabButtons.forEach(btn => {
                    btn.classList.toggle('active', btn.dataset.tab === tabName);
                });
                
                tabContents.forEach(content => {
                    content.style.display = content.id === tabName + '-tab' ? 'block' : 'none';
                });
                
                // 清空当前选中项
                selectedMedia = null;
                // 同步清空目录页多选数组
                selectedMediaItems = [];
                
                // 隐藏所有tab的详情侧边栏
                mediaSidebar.style.display = 'none';
                directorySidebar.style.display = 'none';
                
                // 切换标签页时清空directory-info和预览（限定在当前模态框作用域）
                if (tabName !== 'directory') {
                    const directoryInfo = modal.querySelector('#directory-info');
                    const directoryPreview = modal.querySelector('#directory-preview');
                    if (directoryInfo) directoryInfo.innerHTML = '';
                    if (directoryPreview) directoryPreview.innerHTML = '';
                }
                
                // 切换到媒体库tab时总是重新加载数据
                if (tabName === 'library') {
                    // 切换到媒体库前，清空目录Tab的多选状态和样式
                    const directoryGridEl = modal.querySelector('#directory-media-grid');
                    if (directoryGridEl) {
                        directoryGridEl.querySelectorAll('.media-item.selected').forEach(el => el.classList.remove('selected'));
                    }
                    selectedMediaItems = [];

                    currentDirectory = ''; // 重置当前目录
                    const libState = getViewState('library');
                    libraryRestorePage = (opts && opts.restorePage) ? libState.page : 1;
                    libraryRestoreScrollTop = (opts && opts.restorePage) ? libState.scrollTop : 0;
                    // 打开时恢复上次浏览的页码与滚动位置，其余场景强制刷新到第一页
                    loadMediaLibrary(libraryRestorePage, '');
                }
                
                // 如果切换到文件目录tab，加载目录树
                if (tabName === 'directory') {
                    // 进入目录页前，清空媒体库已选的选中样式和数组，避免跨Tab残留
                    modal.querySelectorAll('#media-grid .media-item.selected').forEach(item => item.classList.remove('selected'));
                    selectedMedia = null;
                    
                    if (!directoryTreeLoaded) {
                        modal.querySelector('#directory-tree').style.display = 'block';
                        loadDirectoryTree();
                        directoryTreeLoaded = true;
                    } else {
                        modal.querySelector('#directory-tree').style.display = 'block';
                        // 恢复搜索和过滤器状态
                        restoreDirectoryFilters();
                        // 重新加载当前目录内容
                        if (currentDirectoryPath) {
                            const dirState = getViewState('directory');
                            directoryRestorePage = (opts && opts.restorePage) ? dirState.page : 1;
                            directoryRestoreScrollTop = (opts && opts.restorePage) ? dirState.scrollTop : 0;
                            loadDirectoryContent(currentDirectoryPath, directorySearchTerm, directoryTypeFilter, directoryRestorePage);
                        } else {
                            // 如果没有当前目录路径，显示提示信息
                            loadDirectoryTree();
                            var grid = modal.querySelector('#directory-media-grid');
                            if (grid) {
                                grid.innerHTML = '<div class="no-media">请点击左侧目录查看文件</div>';
                            }
                        }
                    }
                }
                
                // 更新选择按钮文本以反映当前tab和多选状态
                updateSelectButtonText();
            }
            
            // 文件名长度与重名处理（限制为20个字符，超出则截断并追加时间戳）
            function formatFilenameWithLimit(originalName) {
                const lastDot = originalName.lastIndexOf('.');
                const ext = lastDot > 0 ? originalName.substring(lastDot + 1) : '';
                let base = lastDot > 0 ? originalName.substring(0, lastDot) : originalName;

                // 清理非法字符（保留中英文、数字、下划线、短横线、点）
                base = base.trim().replace(/[^a-zA-Z0-9\u4e00-\u9fa5._-]/g, '_');

                const MAX = 20;
                if (base.length > MAX) {
                    const ts = Math.floor(Date.now() / 1000).toString(); // 10位时间戳
                    const suffix = '-' + ts;
                    const keep = Math.max(0, MAX - suffix.length);
                    base = base.slice(0, keep) + suffix;
                }

                return ext ? base + '.' + ext.toLowerCase() : base;
            }

            // 文件上传
            function uploadFiles(files) {
                if (!files || files.length === 0) return;
                
                // 前置安全检查：全局开关
                if (window.TodoMediaConfig && window.TodoMediaConfig.enableUpload === false) {
                    showMessage('已关闭媒体上传功能（设置-媒体-启用文件上传功能）。', 'warning');
                    return;
                }
                
                // 前置安全检查：前端扩展名白名单与大小限制
                const allowedExts = (window.TodoMediaConfig && window.TodoMediaConfig.allowedFileTypes)
                    ? window.TodoMediaConfig.allowedFileTypes.split(',').map(s => s.trim().toLowerCase()).filter(Boolean)
                    : [];
                const maxSize = (window.TodoMediaConfig && window.TodoMediaConfig.maxFileSize) ? window.TodoMediaConfig.maxFileSize : (10 * 1024 * 1024);
                
                const disallowed = [];
                const oversize = [];
                
                Array.from(files).forEach(f => {
                    const name = f.name || '';
                    const dot = name.lastIndexOf('.');
                    const ext = dot > -1 ? name.substring(dot + 1).toLowerCase() : '';
                    if (allowedExts.length && ext && !allowedExts.includes(ext)) {
                        disallowed.push(name);
                    }
                    if (f.size > maxSize) {
                        oversize.push(`${name} (${(f.size/1024/1024).toFixed(2)}MB)`);
                    }
                });
                
                if (disallowed.length) {
                    showMessage('以下文件的类型不被允许：\n' + disallowed.join('\n'), 'error');
                    return;
                }
                if (oversize.length) {
                    showMessage('以下文件超过大小限制：\n' + oversize.join('\n'), 'error');
                    return;
                }
                
                uploadProgress.style.display = 'block';
                uploadResults.innerHTML = '';
                
                const formData = new FormData();
                Array.from(files).forEach((file) => {
                    const safeName = formatFilenameWithLimit(file.name || '');
                    formData.append('files[]', file, safeName);
                });
                formData.append('action', 'upload_media');
                
                // 添加CSRF token
                if (window.TodoCSRFManager) {
                    const token = TodoCSRFManager.getToken('upload_media');
                    if (token) {
                        formData.append('_todononce', token);
                    }
                }
                
                // 获取当前分类ID（如果存在）
                const urlParams = new URLSearchParams(window.location.search);
                const categoryId = urlParams.get('id');
                if (categoryId && (window.location.href.includes('categories.php') || window.location.href.includes('product-categories.php'))) {
                    formData.append('category_id', categoryId);
                }
                
                const xhr = new XMLHttpRequest();

                let currentDisplayPercent = 0;
                let targetPercent = 0;
                let progressAnimFrame = null;
                
                function updateProgressSmoothly(target) {
                    targetPercent = Math.max(targetPercent, Math.min(100, target));
                    if (!progressAnimFrame) {
                        const step = function() {
                            if (currentDisplayPercent < targetPercent) {
                                // 平滑追赶目标进度，每次增加剩余差距的15%或至少0.5%
                                const diff = targetPercent - currentDisplayPercent;
                                currentDisplayPercent += Math.max(0.5, diff * 0.15);
                                if (currentDisplayPercent > targetPercent) {
                                    currentDisplayPercent = targetPercent;
                                }
                                const roundPercent = Math.min(100, Math.round(currentDisplayPercent));
                                progressFill.style.width = roundPercent + '%';
                                progressText.textContent = '上传中... ' + roundPercent + '%';
                            }
                            if (currentDisplayPercent < targetPercent) {
                                progressAnimFrame = requestAnimationFrame(step);
                            } else {
                                progressAnimFrame = null;
                            }
                        };
                        progressAnimFrame = requestAnimationFrame(step);
                    }
                }

                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable && e.total > 0) {
                        const rawPercent = (e.loaded / e.total) * 100;
                        // 服务器处理完成前最高显示到 95%
                        const target = Math.min(95, rawPercent);
                        updateProgressSmoothly(target);
                    }
                });
                
                xhr.addEventListener('load', function() {
                    updateProgressSmoothly(100);
                    setTimeout(() => {
                        uploadProgress.style.display = 'none';
                        if (progressAnimFrame) {
                            cancelAnimationFrame(progressAnimFrame);
                            progressAnimFrame = null;
                        }
                    }, 300);
                    
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            displayUploadResults(response);
                            loadMediaLibrary(); // 刷新媒体库
                        } catch (e) {
                            console.error('解析响应失败:', e);
                        }
                    } else {
                        console.error('上传失败:', xhr.statusText);
                    }
                });
                
                xhr.addEventListener('error', function() {
                    uploadProgress.style.display = 'none';
                    if (progressAnimFrame) {
                        cancelAnimationFrame(progressAnimFrame);
                        progressAnimFrame = null;
                    }
                    console.error('上传出错');
                });
                
                xhr.open('POST', window.TodoMediaAjaxPaths.mediaUpload);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.send(formData);
            }
            
            // 显示上传结果
            function displayUploadResults(results) {
                uploadResults.innerHTML = '';
                
                results.forEach(result => {
                    const item = document.createElement('div');
                    item.className = 'upload-result-item';
                    
                    const preview = document.createElement('div');
                    preview.className = 'upload-result-preview';
                    
                    if (result.success && result.type && result.type.startsWith('image/')) {
                        const img = document.createElement('img');
                        // 优先使用原图URL，确保图片能正常显示
                        img.src = result.url || result.thumbnail;
                        img.onerror = function() {
                            // 如果图片加载失败，显示默认图标
                            preview.innerHTML = '<i class="toicon">📷</i>';
                        };
                        preview.appendChild(img);
                    } else {
                        preview.innerHTML = '<i class="toicon">📄</i>';
                    }
                    
                    const info = document.createElement('div');
                    info.className = 'upload-result-info';
                    const metaText = result.success ? (result.size || '') : (result.error || '上传失败');
                    info.innerHTML = `
                        <div class="upload-result-name">${result.name}</div>
                        <div class="upload-result-meta" style="${result.success ? '' : 'color:#e55;font-size:12px;'}">${metaText}</div>
                    `;
                    
                    const status = document.createElement('div');
                    status.className = 'upload-result-status ' + (result.success ? 'up-success' : 'up-error');
                    status.innerHTML = result.success ? 
                        '<i class="toicon">✔</i>' : 
                        '<i class="toicon">✘</i>';
                    
                    item.appendChild(preview);
                    item.appendChild(info);
                    item.appendChild(status);
                    
                    uploadResults.appendChild(item);
                });
            }

            function setMediaPaginationLoading(isLoading) {
                const paginationEl = modal.querySelector('#media-pagination');
                const prevBtn = modal.querySelector('#prev-btn');
                const nextBtn = modal.querySelector('#next-btn');
                const infoEl = modal.querySelector('#pagination-info');

                if (!paginationEl || !infoEl) return;

                if (isLoading) {
                    if (prevBtn && !prevBtn.dataset.originalText) prevBtn.dataset.originalText = prevBtn.textContent;
                    if (nextBtn && !nextBtn.dataset.originalText) nextBtn.dataset.originalText = nextBtn.textContent;
                    if (!infoEl.dataset.originalHtml) infoEl.dataset.originalHtml = infoEl.innerHTML;

                    if (prevBtn) {
                        prevBtn.disabled = true;
                        prevBtn.textContent = '加载中...';
                    }
                    if (nextBtn) {
                        nextBtn.disabled = true;
                        nextBtn.textContent = '加载中...';
                    }

                    infoEl.innerHTML = '<span class="spinner"></span> 加载中...';
                    return;
                }

                if (prevBtn && prevBtn.dataset.originalText) {
                    prevBtn.textContent = prevBtn.dataset.originalText;
                    delete prevBtn.dataset.originalText;
                }
                if (nextBtn && nextBtn.dataset.originalText) {
                    nextBtn.textContent = nextBtn.dataset.originalText;
                    delete nextBtn.dataset.originalText;
                }

                if (infoEl.dataset.originalHtml) {
                    const stillLoading = /加载中/.test(infoEl.textContent || '');
                    if (stillLoading) {
                        infoEl.innerHTML = infoEl.dataset.originalHtml;
                    }
                    delete infoEl.dataset.originalHtml;
                }
            }

            function setDirectoryPaginationLoading(isLoading) {
                const paginationEl = modal.querySelector('#directory-pagination');
                const prevBtn = modal.querySelector('#directory-prev-btn');
                const nextBtn = modal.querySelector('#directory-next-btn');
                const infoEl = modal.querySelector('#directory-pagination-info');

                if (!paginationEl || !infoEl) return;

                if (isLoading) {
                    if (prevBtn && !prevBtn.dataset.originalText) prevBtn.dataset.originalText = prevBtn.textContent;
                    if (nextBtn && !nextBtn.dataset.originalText) nextBtn.dataset.originalText = nextBtn.textContent;
                    if (!infoEl.dataset.originalHtml) infoEl.dataset.originalHtml = infoEl.innerHTML;

                    if (prevBtn) {
                        prevBtn.disabled = true;
                        prevBtn.textContent = '加载中...';
                    }
                    if (nextBtn) {
                        nextBtn.disabled = true;
                        nextBtn.textContent = '加载中...';
                    }

                    infoEl.innerHTML = '<span class="spinner"></span> 加载中...';
                    return;
                }

                if (prevBtn && prevBtn.dataset.originalText) {
                    prevBtn.textContent = prevBtn.dataset.originalText;
                    delete prevBtn.dataset.originalText;
                }
                if (nextBtn && nextBtn.dataset.originalText) {
                    nextBtn.textContent = nextBtn.dataset.originalText;
                    delete nextBtn.dataset.originalText;
                }

                if (infoEl.dataset.originalHtml) {
                    const stillLoading = /加载中/.test(infoEl.textContent || '');
                    if (stillLoading) {
                        infoEl.innerHTML = infoEl.dataset.originalHtml;
                    }
                    delete infoEl.dataset.originalHtml;
                }
            }
            
            // 加载媒体库
            function loadMediaLibrary(page = 1, directory = '') {
                currentDirectory = directory; // 更新当前目录
                currentTab = 'library';
                mediaLoading.style.display = 'block';
                const grid = mediaGrid; // 确保在错误处理中可以访问grid

                const requestId = ++mediaLibraryRequestId;
                mediaPaginationLoadingKey = requestId;
                setMediaPaginationLoading(true);
                
                // 清空当前选中项
                selectedMedia = null;
                mediaSidebar.style.display = 'none';
                
                // 转换前端类型值为MIME类型前缀
                let typeFilter = mediaTypeFilter ? mediaTypeFilter.value : '';
                
                // 如果选择的是非媒体库文件，调用专门的函数
                if (typeFilter === 'unlink') {
                    loadUnlinkedFiles(directory || currentDirectory, page);
                    if (requestId === mediaLibraryRequestId) {
                        mediaLoading.style.display = 'none';
                        mediaPaginationLoadingKey = null;
                        setMediaPaginationLoading(false);
                    }
                    return;
                }
                
                if (typeFilter) {
                    switch(typeFilter) {
                        case 'image':
                            typeFilter = 'image';
                            break;
                        case 'video':
                            typeFilter = 'video';
                            break;
                        case 'audio':
                            typeFilter = 'audio';
                            break;
                        case 'document':
                            typeFilter = 'document';
                            break;
                        default:
                            typeFilter = '';
                    }
                }
                
                const searchValue = mediaSearch ? mediaSearch.value : '';

                const params = new URLSearchParams({
                    action: 'get_media',
                    page: page,
                    search: searchValue,
                    type: typeFilter,
                    directory: directory || currentDirectory,
                    include_types: '1' // 确保包含文件类型信息
                });
                
                fetch(addQueryArg(window.TodoMediaAjaxPaths.mediaLibrary, params), {
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! Status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (requestId !== mediaLibraryRequestId) return;
                        const pagination = data.pagination || {};
                        // 缓存页码可能超出当前实际页数（例如数据被删除），此时回退到最后一页
                        if (pagination.total_pages > 0 && pagination.current_page > pagination.total_pages) {
                            loadMediaLibrary(pagination.total_pages, directory);
                            return;
                        }
                        displayMediaGrid(data.media);
                        displayPagination(pagination);
                        // 恢复上次已选中的媒体并展开详情
                        restoreSelectionInCurrentTab();
                        // 恢复到缓存的滚动位置（若当前页与缓存页一致）
                        restoreLibraryScroll();
                    })
                    .catch(error => {
                        if (requestId !== mediaLibraryRequestId) return;
                        console.error('加载媒体库失败:', error);
                        grid.innerHTML = '<div class="no-media error-message">加载媒体库失败，请检查网络连接或刷新页面重试</div>';
                        const infoEl = modal.querySelector('#pagination-info');
                        if (infoEl) infoEl.textContent = '加载失败，请重试';
                    })
                    .finally(() => {
                        if (requestId !== mediaLibraryRequestId) return;
                        mediaLoading.style.display = 'none';
                        mediaPaginationLoadingKey = null;
                        setMediaPaginationLoading(false);
                    });
            }
            
            // 加载未记录文件（未入库的文件）
            function loadUnlinkedFiles(directory = '', page = 1, search = '') {
                const directoryGrid = modal.querySelector('#directory-media-grid');
                if (!directoryGrid) return;

                const loadingKey = 'unlinked:' + (++unlinkedContentRequestId);
                directoryPaginationLoadingKey = loadingKey;
                setDirectoryPaginationLoading(true);
                
                // 显示加载状态
                directoryGrid.innerHTML = '<div class="loading-spinner"><span class="spinner"></span>加载中...</div>';
                
                // 标记本次未记录文件请求ID，避免旧响应覆盖新内容
                const reqId = unlinkedContentRequestId;
                
                // 使用传入的搜索参数，如果没有则使用当前搜索词
                const searchTerm = search || directorySearchTerm || '';
                
                const params = new URLSearchParams({
                    action: 'get_unlinked_files',
                    page: page,
                    per_page: directoryPerPage,
                    directory: directory || currentDirectoryPath,
                    search: searchTerm
                });
                
                // 添加CSRF令牌（健壮处理：尝试多种方式获取token）
                (function ensureUnlinkedCsrf() {
                    let token = null;
                    if (window.TodoCSRFManager) {
                        token = TodoCSRFManager.getToken('get_unlinked_files');
                        if (!token && TodoCSRFManager.collectTokens) {
                            TodoCSRFManager.collectTokens();
                            token = TodoCSRFManager.getToken('get_unlinked_files');
                        }
                    }
                    // 兜底：直接读取隐藏字段
                    if (!token) {
                        const input = document.querySelector('input[name="_csrf_token"]');
                        if (input && input.value) token = input.value;
                    }
                    if (token) params.append('_csrf_token', token);
                })();
                
                fetch(addQueryArg(ajaxBasePath + 'unlinked-files.php', ajaxUrlSuffix), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: params.toString()
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! Status: ${response.status}`);
                        }
                        return response.text().then(text => {
                            try {
                                return JSON.parse(text);
                            } catch (e) {
                                console.error('Invalid JSON response:', text);
                                throw new Error('服务器返回了无效的响应: ' + text.substring(0, 100));
                            }
                        });
                    })
                    .then(data => {
                        if (data.success) {
                            // 请求竞态保护：若已有新请求，则忽略旧响应
                            if (reqId !== unlinkedContentRequestId) { return; }
                            const pagination = data.pagination || {};
                            // 缓存页码可能超出当前实际页数（例如数据被删除），此时回退到最后一页
                            if (pagination.total_pages > 0 && pagination.current_page > pagination.total_pages) {
                                loadUnlinkedFiles(directory, pagination.total_pages, searchTerm);
                                return;
                            }
                            displayUnlinkedFilesInDirectory(data.files || []);
                            // 更新并显示分页（未记录文件也支持分页）
                            if (data.pagination) {
                                displayDirectoryPagination(data.pagination);
                            } else {
                                directoryCurrentPage = 1;
                                directoryTotalPages = 1;
                                const paginationEl = modal.querySelector('#directory-pagination');
                                if (paginationEl) paginationEl.style.display = 'none';
                                clearViewPage('directory');
                            }
                            // 恢复滚动位置（若当前页与缓存页一致）
                            restoreDirectoryScroll();
                            // 根据当前筛选器更新批量操作显示（未记录时显示）
                            const directoryTypeSelect = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-type-filter');
                            const bulkActions = modal.querySelector('#directory-bulk-actions');
                            if (directoryTypeSelect && bulkActions) {
                                const currentFilter = directoryTypeSelect.value;
                                if (currentFilter === 'unlink') {
                                    bulkActions.style.display = 'inline-flex';
                                    // 确保“添加到媒体库”按钮存在，并根据勾选状态启用
                                    if (typeof ensureAddToLibraryButton === 'function') ensureAddToLibraryButton();
                                    if (typeof updateAddToLibraryButtonState === 'function') updateAddToLibraryButtonState();
                                } else {
                                    bulkActions.style.display = 'none';
                                }
                            }
                            // 每次加载完成后重置全选UI
                            if (typeof resetSelectAllUI === 'function') {
                                resetSelectAllUI();
                            }
                            // 恢复上次已选中的目录文件并展开详情
                            restoreSelectionInCurrentTab();
                        } else {
                            throw new Error(data.message || data.error || '加载失败');
                        }
                    })
                    .catch(error => {
                        console.error('加载非媒体库文件失败:', error);
                        // 仅在最新请求失败时显示错误，避免旧请求失败覆盖新内容
                        if (reqId !== unlinkedContentRequestId) { return; }
                        directoryGrid.innerHTML = '<div class="no-media error-message">加载非媒体库文件失败: ' + error.message + '</div>';
                        const infoEl = modal.querySelector('#directory-pagination-info');
                        if (infoEl) infoEl.textContent = '加载失败，请重试';
                    })
                    .finally(() => {
                        const isLatest = reqId === unlinkedContentRequestId && directoryPaginationLoadingKey === loadingKey;
                        if (!isLatest) return;
                        directoryPaginationLoadingKey = null;
                        setDirectoryPaginationLoading(false);
                    });
            }
            
            // 在目录网格中显示未记录文件
            function displayUnlinkedFilesInDirectory(files) {
                const directoryGrid = modal.querySelector('#directory-media-grid');
                if (!directoryGrid) return;
                
                directoryGrid.innerHTML = '';
                
                if (!files || files.length === 0) {
                    directoryGrid.innerHTML = '<div class="no-media">当前目录下没有找到非媒体库文件</div>';
                    return;
                }
                
                files.forEach(file => {
                    const mediaItem = document.createElement('div');
                    mediaItem.className = 'media-item unlinked-file';
                    // 目录未入库文件：补充必要的 dataset，便于多选统一收集
                    mediaItem.dataset.path = file.path;
                    mediaItem.dataset.url = file.url || '';
                    mediaItem.dataset.title = file.name || '';
                    mediaItem.dataset.filename = file.name || '';
                    mediaItem.dataset.type = getFileType(file.name || '');
                    mediaItem.dataset.size = file.size || '';
                    mediaItem.dataset.seoKeywords = file.seo_keywords || '';
                    // 使用路径构造稳定的临时ID，避免为空
                    mediaItem.dataset.id = 'unlinked:' + (file.path || '');
                    
                    // 添加复选框
                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.className = 'media-item-checkbox';
                    checkbox.style.position = 'absolute';
                    checkbox.style.top = '5px';
                    checkbox.style.left = '5px';
                    checkbox.style.zIndex = '10';
                    
                    const preview = document.createElement('div');
                    preview.className = 'media-item-preview';
                    preview.style.position = 'relative';
                    preview.appendChild(checkbox);
                    
                    // 勾选项变化时，同步“全选/取消”文字与主控勾选状态，并更新入库按钮可用状态
                    checkbox.addEventListener('change', function() {
                        const allCheckboxes = modal.querySelectorAll('.media-item.unlinked-file input[type="checkbox"]');
                        const checkedCount = [...allCheckboxes].filter(cb => cb.checked).length;
                        const selectAllText = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-select-all-text');
                        const selectAllMain = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-select-all');
                        const anySelected = checkedCount > 0;
                        if (selectAllText) selectAllText.textContent = anySelected ? '取消' : '全选';
                        if (selectAllMain) selectAllMain.checked = anySelected;
                        updateAddToLibraryButtonState();
                    });
                    
                    // 根据文件类型显示不同的图标
                    const fileType = getFileType(file.name);
                    if (fileType.startsWith('image/')) {
                        const img = document.createElement('img');
                        img.src = file.url;
                        img.alt = file.name;
                        img.onerror = function() {
                            this.style.display = 'none';
                            const icon = document.createElement('div');
                            icon.className = 'file-icon image-icon';
                            icon.innerHTML = '🖼️';
                            preview.appendChild(icon);
                        };
                        preview.appendChild(img);
                    } else {
                        const icon = document.createElement('div');
                        icon.className = 'file-icon';
                        if (fileType.startsWith('video/')) {
                            icon.innerHTML = '🎬';
                            icon.className += ' video-icon';
                        } else if (fileType.startsWith('audio/')) {
                            icon.innerHTML = '🎵';
                            icon.className += ' audio-icon';
                        } else if (fileType.includes('pdf')) {
                            icon.innerHTML = '📄';
                            icon.className += ' pdf-icon';
                        } else {
                            icon.innerHTML = '📁';
                            icon.className += ' file-icon';
                        }
                        preview.appendChild(icon);
                    }
                    
                    const info = document.createElement('div');
                    info.className = 'media-item-info';
                    
                    const title = document.createElement('div');
                    title.className = 'media-item-title';
                    title.textContent = file.name.replace(/\.[^/.]+$/, "");
                    title.title = file.name;
                    
                    const meta = document.createElement('div');
                    meta.className = 'media-item-meta';
                    meta.innerHTML = `${file.date || file.modified || ''}`;
                    
                    const mime = document.createElement('div');
                    mime.className = 'media-item-mime';
                    const tmpMimeType = getFileType(file.name);
                    mime.innerHTML = '未入库｜' + getChineseCategoryLabel(tmpMimeType, file.name);
                    
                    info.appendChild(title);
                    info.appendChild(meta);
                    info.appendChild(mime);
                    
                    mediaItem.appendChild(preview);
                    mediaItem.appendChild(info);
                    
                    // 添加点击事件（排除复选框点击）
                    mediaItem.addEventListener('click', function(e) {
                        // 如果点击的是复选框，不触发文件选择
                        if (e.target.type === 'checkbox') {
                            e.stopPropagation();
                            return;
                        }
                        selectUnlinkedFileInDirectory(file, this);
                    });
                    
                    // 复选框点击事件
                    checkbox.addEventListener('click', function(e) {
                        e.stopPropagation();
                    });
                    
                    directoryGrid.appendChild(mediaItem);
                });
            }
            
            // 在目录中选择未记录文件
            function selectUnlinkedFileInDirectory(file, element) {
                // 检查是否为多选模式
                const isMultiple = modal.getAttribute('data-multiple') === 'true';
                
                // 如果在media页面，不可以多选
                const urlParams = new URLSearchParams(window.location.search);
                const currentPage = urlParams.get('page');

                if (isMultiple && currentPage !== 'media') {
                    // 多选模式：切换选中状态
                    if (element.classList.contains('selected')) {
                        element.classList.remove('selected');
                        // 从选中列表中移除
                        selectedMediaItems = selectedMediaItems.filter(item => item.path !== file.path);
                    } else {
                        element.classList.add('selected');
                        // 添加到选中列表
                        if (!selectedMediaItems.some(item => item.path === file.path)) {
                            selectedMediaItems.push({
                                id: 'unlinked_' + Date.now(),
                                path: file.path,
                                url: file.url,
                                name: file.name,
                                type: getFileType(file.name),
                                isUnlinked: true
                            });
                        }
                    }
                } else {
                    // 单选模式：移除其他选中状态
                    modal.querySelectorAll('#directory-media-grid .media-item.selected').forEach(item => {
                        item.classList.remove('selected');
                    });
                    
                    // 添加选中状态
                    element.classList.add('selected');
                    
                    // 单选模式，直接设置选中项
                    selectedMediaItems = [{
                        id: 'unlinked_' + Date.now(),
                        path: file.path,
                        url: file.url,
                        name: file.name,
                        type: getFileType(file.name),
                        isUnlinked: true
                    }];
                }

                // 显示文件详情侧边栏
                showUnlinkedFileDetails(file);
                if (currentTab === 'directory') {
                    if (directorySidebar) directorySidebar.style.display = 'block';
                } else if (currentTab === 'library') {
                    if (mediaSidebar) mediaSidebar.style.display = 'block';
                }
            }
            
            // 选择非媒体库文件
            function selectUnlinkedFile(file) {
                // 检查是否为多选模式
                const isMultiple = modal.getAttribute('data-multiple') === 'true';
                
                const selectedItem = document.querySelector(`[data-path="${file.path}"]`);
                
                if (isMultiple) {
                    // 多选模式：切换选中状态
                    if (selectedItem && selectedItem.classList.contains('selected')) {
                        selectedItem.classList.remove('selected');
                    } else if (selectedItem) {
                        selectedItem.classList.add('selected');
                    }
                } else {
                    // 单选模式：移除其他选中状态
                    document.querySelectorAll('.media-item.selected').forEach(item => {
                        item.classList.remove('selected');
                    });
                    
                    // 添加选中状态
                    if (selectedItem) {
                        selectedItem.classList.add('selected');
                    }
                }
                
                // 显示文件详情
                showUnlinkedFileDetails(file);
            }
            
            // 显示非媒体库文件详情
            function showUnlinkedFileDetails(file) {
                // 根据当前tab确定使用哪个详情容器
                let sidebar, preview, selectBtn;
                if (currentTab === 'library') {
                    sidebar = mediaSidebar;
                    preview = modal.querySelector('#media-preview');
                    selectBtn = modal.querySelector('#select-media-btn');
                } else if (currentTab === 'directory') {
                    sidebar = directorySidebar;
                    preview = modal.querySelector('#directory-preview');
                    selectBtn = modal.querySelector('#select-directory-btn');
                } else {
                    // 默认使用媒体库的元素
                    sidebar = mediaSidebar;
                    preview = modal.querySelector('#media-preview');
                    selectBtn = modal.querySelector('#select-media-btn');
                }
                
                // 显示预览
                const fileType = getFileType(file.name);
                if (fileType.startsWith('image/')) {
                    preview.innerHTML = `<img src="${file.url}" alt="${file.name}">`;
                } else {
                    preview.innerHTML = `
                        <div class="file-preview">
                            <div class="file-icon-large">
                                ${fileType.startsWith('video/') ? '🎬' : 
                                  fileType.startsWith('audio/') ? '🎵' : 
                                  fileType.includes('pdf') ? '📄' : '📁'}
                            </div>
                        </div>
                    `;
                }
                
                // 同步更新directory-info信息
                const directoryInfo = modal.querySelector('#directory-info');
                if (directoryInfo) {
                    directoryInfo.innerHTML = `
                        <div class="media-info-item">
                            <div class="media-info-label">文件名</div>
                            <div class="media-info-value">${((file.title || file.name || '未命名文件') + '').replace(/\.[^/.]+$/, '')}</div>
                        </div>
                        <div class="media-info-item">
                            <div class="media-info-label">文件类型</div>
                            <div class="media-info-value">${getDisplayMimeType(file.type || fileType, file.name)}</div>
                        </div>
                        <div class="media-info-item">
                            <div class="media-info-label">上传日期</div>
                            <div class="media-info-value">${file.date || file.modified || '未知日期'}</div>
                        </div>
                        <div class="media-info-item">
                            <div class="media-info-label">文件大小</div>
                            <div class="media-info-value">${file.size || '未知大小'}</div>
                        </div>
                        <div class="media-info-item">
                            <div class="media-info-label">URL</div>
                            <div class="media-info-value">${file.url || file.path || '无URL'}</div>
                        </div>
                    `;
                }
                
                // 更新选择按钮：统一交由全局选择处理器（selectMediaBtn 的事件）收集 .selected 项
                // 这里不再设置自定义 onclick，避免与全局处理器重复触发
                selectBtn.onclick = null;
                
                // 处理 DOCX 解析按钮的显示与隐藏（只在单文件选中且存在活动编辑器时显示）
                const parseDocxBtns = modal.querySelectorAll('#parse-docx-btn, #parse-docx-btn-media');
                const isSingleSelected = !selectedMediaItems || selectedMediaItems.length <= 1;
                const hasActiveEditor = !!(typeof getActiveUEditor === 'function' && getActiveUEditor());
                
                parseDocxBtns.forEach(parseDocxBtn => {
                    if (!isSingleSelected || !hasActiveEditor) {
                        parseDocxBtn.style.display = 'none';
                        return;
                    }
                    
                    const itemName = (file.filename || file.name || file.title || '').toString().toLowerCase();
                    const itemPath = (file.path || '').toString().toLowerCase();
                    const itemUrl = (file.url || '').toString().toLowerCase();
                    
                    const isDocx = itemName.endsWith('.docx') || itemPath.endsWith('.docx') || itemUrl.endsWith('.docx');
                    
                    if (isDocx) {
                        parseDocxBtn.style.display = 'block';
                        // 绑定解析事件
                        parseDocxBtn.onclick = function() {
                            handleDocxParse(file.path || file.url);
                        };
                    } else {
                        parseDocxBtn.style.display = 'none';
                    }
                });
                
                sidebar.style.display = 'block';
            }
            

            
            // 显示媒体网格
            function displayMediaGrid(media) {
                const grid = mediaGrid;
                grid.innerHTML = '';
                
                if (!media || media.length === 0) {
                    grid.innerHTML = '<div class="no-media">没有找到文件</div>';
                    return;
                }
                
                // 确保媒体项有正确的类型信息
                media.forEach(item => {
                    if (!item.type || item.type === 'file') {
                        // 优先使用文件名，其次使用URL来获取类型
                        const filename = item.title || (item.url ? item.url.split('/').pop() : '');
                        item.type = getFileType(filename);
                    }
                    
                    // 确保有缩略图URL
                    if (!item.thumbnail && item.type.startsWith('image/')) {
                        item.thumbnail = item.url;
                    }
                });
                
                media.forEach(item => {
                    const mediaItem = document.createElement('div');
                    mediaItem.className = 'media-item';
                    mediaItem.dataset.id = item.id;
                    mediaItem.dataset.url = item.url || '';
                    mediaItem.dataset.title = item.title || '';
                    mediaItem.dataset.alt = item.alt || '';
                    mediaItem.dataset.type = item.type || '';
                    mediaItem.dataset.filename = item.name || item.filename || '';
                    mediaItem.dataset.path = item.path || '';
                    mediaItem.dataset.size = item.size || '';
                    mediaItem.dataset.dimensions = item.dimensions || '';
                    mediaItem.dataset.seoKeywords = item.seo_keywords || '';
                    
                    const preview = document.createElement('div');
                    preview.className = 'media-item-preview';
                    
                    if (item.type && item.type.startsWith('image/')) {
                        const img = document.createElement('img');
                        img.src = item.thumbnail || item.url;
                        preview.appendChild(img);
                    } else {
                        preview.innerHTML = '<i class="toicon">📄</i>';
                    }
                    
                    const seoKw = (item.seo_keywords || '').toString().trim();
                    const extLabel = getChineseCategoryLabel(item.type, item.name || item.filename);
                    const mimeText = seoKw ? (seoKw + '-' + extLabel) : extLabel;
                    
                    const info = document.createElement('div');
                    info.className = 'media-item-info';
                    info.innerHTML = `
                        <div class="media-item-title">${item.title}</div>
                        <div class="media-item-meta">${item.date}</div>
                        <div class="media-item-mime">${mimeText}</div>
                    `;
                    
                    mediaItem.appendChild(preview);
                    mediaItem.appendChild(info);
                    
                    mediaItem.addEventListener('click', function() {
                        selectMediaItem(item, mediaItem);
                    });
                    
                    grid.appendChild(mediaItem);
                });
            }
            
            // 选择媒体项
            function selectMediaItem(item, element) {
                // 检查是否为多选模式
                const isMultiple = modal.getAttribute('data-multiple') === 'true';
                
                // 如果在media页面，不可以多选
                const urlParams = new URLSearchParams(window.location.search);
                const currentPage = urlParams.get('page');

                if (isMultiple && currentPage !== 'media') {
                    // 多选模式：切换选中状态
                    if (element.classList.contains('selected')) {
                        element.classList.remove('selected');
                        // 从选中列表中移除
                        selectedMediaItems = selectedMediaItems.filter(selectedItem => {
                            return selectedItem.id !== item.id && selectedItem.path !== item.path;
                        });
                    } else {
                        element.classList.add('selected');
                        // 添加到选中列表
                        if (!selectedMediaItems.some(selectedItem => selectedItem.id === item.id || selectedItem.path === item.path)) {
                            selectedMediaItems.push(item);
                        }
                    }
                    
                    // 更新selectedMedia为最后选中的项目
                    const selectedItems = modal.querySelectorAll('.media-item.selected');
                    if (selectedItems.length > 0) {
                        selectedMedia = item;
                        displayMediaDetails(item);
                        // 根据当前tab显示对应的侧边栏
                        if (currentTab === 'library') {
                            mediaSidebar.style.display = 'block';
                        } else if (currentTab === 'directory') {
                            directorySidebar.style.display = 'block';
                        }
                    } else {
                        selectedMedia = null;
                        mediaSidebar.style.display = 'none';
                        directorySidebar.style.display = 'none';
                    }
                } else {
                    // 单选模式：移除之前的选中状态
                    modal.querySelectorAll('.media-item.selected').forEach(el => {
                        el.classList.remove('selected');
                    });
                    
                    // 添加选中状态
                    element.classList.add('selected');
                    selectedMedia = item;
                    selectedMediaItems = [item]; // 单选模式下只有一个选中项
                    
                    // 显示详情
                    displayMediaDetails(item);
                    // 根据当前tab显示对应的侧边栏
                    if (currentTab === 'library') {
                        mediaSidebar.style.display = 'block';
                    } else if (currentTab === 'directory') {
                        directorySidebar.style.display = 'block';
                    }
                }
            }
            
            // 显示媒体详情
            function displayMediaDetails(item) {
                // 根据当前tab确定使用哪个详情容器
                let previewElement, infoElement;
                if (currentTab === 'library') {
                    previewElement = mediaPreview;
                    infoElement = mediaInfo;
                } else if (currentTab === 'directory') {
                    previewElement = directoryPreview;
                    infoElement = directoryInfo;
                } else {
                    // 默认使用媒体库的元素
                    previewElement = mediaPreview;
                    infoElement = mediaInfo;
                }
                // 不在此处移除批量操作区的“添加到媒体库”按钮，避免在查看详情时被误删
                
                // 清空预览区域
                previewElement.innerHTML = '';
                
                // 预览处理
                if (item.type && (item.type.startsWith('image/') || item.type === 'image')) {
                    const img = document.createElement('img');
                    img.src = item.url;
                    img.alt = item.title || '图片预览';
                    img.onerror = function() {
                        // 图片加载失败时显示默认图标
                        previewElement.innerHTML = '<div class="file-preview"><i class="toicon">📷</i></div>';
                    };
                    previewElement.appendChild(img);
                    if (window.TodoCMS && window.TodoCMS.Utils && window.TodoCMS.Utils.ImagePreview) {
                        window.TodoCMS.Utils.ImagePreview.ensureCloneInContainer(previewElement, { previewClass: 'previewimg' });
                    } else {
                        const previewImg = img.cloneNode(true);
                        previewImg.classList.add('previewimg');
                        previewElement.appendChild(previewImg);
                    }
                } else {
                    previewElement.innerHTML = '<div class="file-preview"><i class="toicon">📄</i></div>';
                }
                
                // 信息
                let infoHTML = `
                    <div class="media-info-item">
                        <div class="media-info-label">文件名</div>
                        <div class="media-info-value">${((item.title || item.name || '未命名文件') + '').replace(/\.[^/.]+$/, '')}</div>
                    </div>
                    <div class="media-info-item">
                        <div class="media-info-label">文件类型</div>
                        <div class="media-info-value">${getDisplayMimeType(item.type, item.name || item.filename)}</div>
                    </div>
                    <div class="media-info-item">
                        <div class="media-info-label">上传日期</div>
                        <div class="media-info-value">${item.date || '未知日期'}</div>
                    </div>
                    <div class="media-info-item">
                        <div class="media-info-label">文件大小</div>
                        <div class="media-info-value">${item.size || '未知大小'}</div>
                    </div>
                    <div class="media-info-item">
                        <div class="media-info-label">URL</div>
                        <div class="media-info-value">${item.url || '无URL'}</div>
                    </div>
                `;
                
                // 如果是未记录的文件，仅提示状态；入库操作统一在上方批量操作区进行（勾选后显示按钮）
                if (item.isUnlinked || (!item.id || item.id == 0)) {
                    infoHTML += `
                        <div class="media-info-item unlinked-notice">
                            <div class="media-info-label">状态</div>
                            <div class="media-info-value">此文件未添加到媒体库（请勾选后使用上方“添加到媒体库”按钮）</div>
                        </div>
                    `;
                }
                
                infoElement.innerHTML = infoHTML;
                
                // 处理 DOCX 解析按钮的显示与隐藏（只在单文件选中且存在活动编辑器时显示）
                const parseDocxBtns = modal.querySelectorAll('#parse-docx-btn, #parse-docx-btn-media');
                const isSingleSelected = !selectedMediaItems || selectedMediaItems.length <= 1;
                const hasActiveEditor = !!(typeof getActiveUEditor === 'function' && getActiveUEditor());
                
                parseDocxBtns.forEach(parseDocxBtn => {
                    if (!isSingleSelected || !hasActiveEditor) {
                        parseDocxBtn.style.display = 'none';
                        return;
                    }
                    
                    const itemName = (item.filename || item.name || item.title || '').toString().toLowerCase();
                    const itemPath = (item.path || '').toString().toLowerCase();
                    const itemUrl = (item.url || '').toString().toLowerCase();
                    
                    const isDocx = itemName.endsWith('.docx') || itemPath.endsWith('.docx') || itemUrl.endsWith('.docx');
                    
                    if (isDocx) {
                        parseDocxBtn.style.display = 'block';
                        // 绑定解析事件
                        parseDocxBtn.onclick = function() {
                            handleDocxParse(item.path || item.url);
                        };
                    } else {
                        parseDocxBtn.style.display = 'none';
                    }
                });
            }
            
            // 显示分页
            function displayPagination(pagination) {
                const mediaGrid = modal.querySelector('#media-grid');
                const paginationEl = modal.querySelector('#media-pagination');
                const prevBtn = modal.querySelector('#prev-btn');
                const nextBtn = modal.querySelector('#next-btn');
                const infoEl = modal.querySelector('#pagination-info');
                
                if (!pagination || !paginationEl) return;
                
                // 归一化页码：无数据时按单页处理，避免显示无效页码
                const rawPage = Math.max(1, Math.floor(Number(pagination.current_page) || 1));
                const displayTotalPages = Math.max(1, Math.floor(Number(pagination.total_pages) || 1));
                const displayPage = rawPage > displayTotalPages ? displayTotalPages : rawPage;
                
                // 更新全局变量
                currentPage = displayPage;
                totalPages = displayTotalPages;
                
                // 更新分页信息
                if (infoEl) {
                    infoEl.innerHTML = '<b>第 ' + displayPage + ' 页</b>，共 ' + displayTotalPages + ' 页';
                }
                
                // 更新按钮状态
                if (prevBtn) {
                    prevBtn.disabled = displayPage <= 1;
                }
                if (nextBtn) {
                    nextBtn.disabled = displayPage >= displayTotalPages;
                }
                
                // 控制分页显示：只有当页数大于1时才显示分页
                if (displayTotalPages > 1) {
                    paginationEl.style.display = 'flex';
                    mediaGrid.style.paddingBottom = '0';
                    // 存在多页时保存当前页码，便于下次打开继续浏览
                    saveViewPage('library', displayPage);
                } else {
                    paginationEl.style.display = 'none';
                    mediaGrid.style.paddingBottom = '15px';
                    // 无分页（只有一页或没有数据）时清除缓存，避免下次打开跳到无效页码
                    clearViewPage('library');
                }
            }
            
            // 恢复媒体库滚动位置：仅当实际加载页码与缓存页一致时恢复，否则回到顶部
            function restoreLibraryScroll() {
                if (!mediaGrid) return;
                mediaGrid.scrollTop = (currentPage === libraryRestorePage) ? libraryRestoreScrollTop : 0;
            }
            
            // 恢复文件目录滚动位置：仅当实际加载页码与缓存页一致时恢复，否则回到顶部
            function restoreDirectoryScroll() {
                const directoryGrid = modal.querySelector('#directory-media-grid');
                if (!directoryGrid) return;
                directoryGrid.scrollTop = (directoryCurrentPage === directoryRestorePage) ? directoryRestoreScrollTop : 0;
            }
            
            // URL功能相关元素
            const imageUrlInput = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-image-url-input');
            const addUrlBtn = modal.querySelector('#add-url-btn');
            
            let previewedImageData = null;
            
            // URL图片直接下载功能
            function downloadImageFromUrl(url) {
                if (!url) return;
                
                // 前置安全检查：全局开关
                if (window.TodoMediaConfig && window.TodoMediaConfig.enableUpload === false) {
                    showMessage('已关闭媒体上传功能（设置-媒体-启用文件上传功能）。', 'warning');
                    return;
                }
                
                // 前置安全检查：根据 URL 的扩展名进行白名单校验
                try {
                    const u = new URL(url);
                    const basename = u.pathname.split('/').pop() || '';
                    const dot = basename.lastIndexOf('.');
                    const ext = dot > -1 ? basename.substring(dot + 1).toLowerCase() : '';
                    const allowedExts = (window.TodoMediaConfig && window.TodoMediaConfig.allowedFileTypes)
                        ? window.TodoMediaConfig.allowedFileTypes.split(',').map(s => s.trim().toLowerCase()).filter(Boolean)
                        : [];
                    if (allowedExts.length && ext && !allowedExts.includes(ext)) {
                        showMessage('该URL文件类型不被允许：' + ext, 'error');
                        return;
                    }
                } catch (e) {
                    // ignore parse error, let server validate
                }
                
                // 显示加载状态
                addUrlBtn.disabled = true;
                addUrlBtn.textContent = '下载中...';
                
                // 获取标题
                const title = getFilenameFromUrl(url);
                
                // 准备下载数据
                const formData = new FormData();
                formData.append('action', 'download_url_image');
                formData.append('url', url);
                if (title) {
                    formData.append('title', title);
                }
                
                // 添加CSRF令牌
                if (window.TodoCSRFManager) {
                    const token = TodoCSRFManager.getToken('url_download');
                    if (token) {
                        formData.append('_todononce', token);
                    }
                }
                
                // 发送下载请求
                fetch(window.TodoMediaAjaxPaths.mediaUrlDownload, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // 下载成功，显示在upload-results区域
                        const result = {
                            success: true,
                            name: data.title || data.filename,
                            url: data.url,
                            type: data.type,
                            size: data.size,
                            id: data.id,
                            thumbnail: data.thumbnail
                        };
                        
                        // 自动选中并直接回调插入到页面组件
                        const mediaData = {
                            id: data.id,
                            title: result.name,
                            url: data.url,
                            type: data.type,
                            size: data.size,
                            thumbnail: data.thumbnail
                        };

                        // 执行全局/局部注册的 mediaSelectCallback 回调函数
                        if (window.mediaSelectCallback && typeof window.mediaSelectCallback === 'function') {
                            const isMultiple = modal.getAttribute('data-multiple') === 'true';
                            const callbackData = isMultiple ? [mediaData] : mediaData;
                            window.mediaSelectCallback(callbackData);
                        }

                        // 如果关联了 inputId / previewId，自动填充
                        const inputId = modal.getAttribute('data-input-id');
                        const previewId = modal.getAttribute('data-preview-id');
                        if (inputId) {
                            const input = document.getElementById(inputId);
                            if (input) input.value = mediaData.url;
                        }
                        if (previewId) {
                            const preview = document.getElementById(previewId);
                            if (preview) {
                                if (preview.tagName === 'IMG') {
                                    preview.src = mediaData.url;
                                } else {
                                    preview.innerHTML = `<img src="${mediaData.url}" alt="${mediaData.title}">`;
                                }
                            }
                        }

                        // 关闭模态框
                        closeModal();

                        // 显示成功消息
                        if (data.is_existing) {
                            showMessage('已识别已有图片并完成插入', 'success');
                        } else {
                            showMessage('图片添加并插入成功', 'success');
                        }
                    } else {
                        // 下载失败
                        const result = {
                            success: false,
                            name: title,
                            error: data.error || '下载失败'
                        };
                        addUploadResult(result);
                        showMessage('下载失败: ' + (data.error || '未知错误'), 'error');
                    }
                })
                .catch(error => {
                    console.error('下载错误:', error);
                    const result = {
                        success: false,
                        name: title,
                        error: '网络错误'
                    };
                    addUploadResult(result);
                    showMessage('下载失败: 网络错误', 'error');
                })
                .finally(() => {
                    // 恢复按钮状态
                    addUrlBtn.disabled = false;
                    addUrlBtn.textContent = '添加图片';
                });
            }
            
            // 从URL/相对路径获取文件名（限制主体长度≤20，超出则截断并追加时间戳以防重名）
            function getFilenameFromUrl(url) {
                try {
                    if (!url || typeof url !== 'string') return '未命名图片';

                    let pathname = url;
                    // 如果是完整 URL (如 http://... 或 https://... 或 //...)
                    if (/^(https?:)?\/\//i.test(url)) {
                        try {
                            pathname = new URL(url, window.location.origin).pathname;
                        } catch (err) {
                            pathname = url.split('?')[0].split('#')[0];
                        }
                    } else {
                        // 本地相对路径，如 /content/uploads/allimg/20240927/1-24092F00024947.jpg
                        pathname = url.split('?')[0].split('#')[0];
                    }

                    const rawFilename = pathname.split('/').pop() || '未命名图片';
                    let filename = rawFilename;
                    try {
                        filename = decodeURIComponent(rawFilename);
                    } catch (e) {}

                    // 提取不含扩展名的主体
                    let base = filename;
                    if (filename !== '未命名图片') {
                        const lastDotIndex = filename.lastIndexOf('.');
                        if (lastDotIndex > 0) {
                            base = filename.substring(0, lastDotIndex);
                        }
                    }

                    // 清理非法字符（保留中英文、数字、下划线、短横线、点）
                    base = base.trim().replace(/[^a-zA-Z0-9\u4e00-\u9fa5._-]/g, '_');

                    if (!base) base = '未命名图片';

                    // 长度限制与去重处理
                    const MAX = 20;
                    if (base.length > MAX) {
                        const ts = Math.floor(Date.now() / 1000).toString(); // 10位时间戳
                        const suffix = '-' + ts;
                        const keep = Math.max(0, MAX - suffix.length);
                        base = base.slice(0, keep) + suffix;
                    }

                    return base;
                } catch (e) {
                    return '未命名图片';
                }
            }
            
            // 添加单个上传结果到显示区域
            function addUploadResult(result) {
                // 如果upload-results容器不存在，创建它
                if (!uploadResults.querySelector('.upload-result-item')) {
                    // 清空容器，准备显示新结果
                    // uploadResults.innerHTML = '';
                }
                
                const item = document.createElement('div');
                item.className = 'upload-result-item';
                
                const preview = document.createElement('div');
                preview.className = 'upload-result-preview';
                
                if (result.success && result.type && result.type.startsWith('image/')) {
                    const img = document.createElement('img');
                    // 优先使用原图URL，确保图片能正常显示
                    img.src = result.url || result.thumbnail;
                    img.onerror = function() {
                        // 如果图片加载失败，显示默认图标
                        preview.innerHTML = '<i class="toicon">📷</i>';
                    };
                    preview.appendChild(img);
                } else {
                    preview.innerHTML = '<i class="toicon">📄</i>';
                }
                
                const info = document.createElement('div');
                info.className = 'upload-result-info';
                info.innerHTML = `
                    <div class="upload-result-name">${result.name}</div>
                    <div class="upload-result-meta">${result.size || ''}</div>
                    ${result.error ? `<div class="upload-result-error">${result.error}</div>` : ''}
                `;
                
                const status = document.createElement('div');
                status.className = 'upload-result-status ' + (result.success ? 'up-success' : 'up-error');
                status.innerHTML = result.success ? 
                    '<i class="toicon">✔</i>' : 
                    '<i class="toicon">✘</i>';
                
                item.appendChild(preview);
                item.appendChild(info);
                item.appendChild(status);
                
                // 添加点击事件（成功的项目可以选择）
                if (result.success && result.id) {
                    item.style.cursor = 'pointer';
                    item.addEventListener('click', function() {
                        const mediaData = {
                            id: result.id,
                            title: result.name,
                            url: result.url,
                            type: result.type,
                            size: result.size,
                            thumbnail: result.thumbnail
                        };
                        
                        // 检查是否为多选模式
                        const isMultiple = modal.getAttribute('data-multiple') === 'true';
                        
                        if (isMultiple) {
                            // 多选模式：切换选中状态
                            if (item.classList.contains('selected')) {
                                item.classList.remove('selected');
                            } else {
                                item.classList.add('selected');
                            }
                            
                            // 更新selectedMedia为最后选中的项目
                            const selectedItems = uploadResults.querySelectorAll('.upload-result-item.selected');
                            if (selectedItems.length > 0) {
                                selectedMedia = mediaData;
                            } else {
                                selectedMedia = null;
                            }
                        } else {
                            // 单选模式：移除其他选中状态
                            uploadResults.querySelectorAll('.upload-result-item.selected').forEach(el => {
                                el.classList.remove('selected');
                            });
                            
                            // 添加选中状态
                            item.classList.add('selected');
                            selectedMedia = mediaData;
                        }
                        
                        // 显示详情
                        displayMediaDetails(selectedMedia);
                        // 根据当前tab显示对应的侧边栏
                        if (currentTab === 'library') {
                            mediaSidebar.style.display = 'block';
                        } else if (currentTab === 'directory') {
                            directorySidebar.style.display = 'block';
                        }
                    });
                }
                
                uploadResults.appendChild(item);
            }
            
            // 重置URL输入
            function resetUrlInput() {
                imageUrlInput.value = '';
            }
            
            // URL相关事件绑定
            addUrlBtn.addEventListener('click', function() {
                const url = imageUrlInput.value.trim();
                if (url) {
                    downloadImageFromUrl(url);
                } else {
                    showMessage('请输入图片URL地址', 'warning');
                }
            });

            // 回车键触发下载
            imageUrlInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    addUrlBtn.click();
                }
            });
            
            // 事件绑定
            closeBtn.addEventListener('click', closeModal);
            
            // 根据调用场景设置取消按钮的文本和行为
            function setupCancelButton() {
                const currentPage = new URLSearchParams(window.location.search).get('page');
                const isFeaturedImageContext = window.location.href.includes('featured-image') || 
                                             document.querySelector('.featured-image-modal') !== null ||
                                             window.opener !== null; // 检查是否在弹窗中
            }
            
            // 初始化取消按钮
            setupCancelButton();
            
            // 点击overlay背景关闭模态框
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeModal();
                }
            });
            
            // ESC键关闭模态框
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('show')) {
                    closeModal();
                }
            });
            
            // 标签页切换
            tabButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    switchTab(this.dataset.tab);
                });
            });
            
            // 文件选择
            selectFilesBtn.addEventListener('click', function() {
                fileInput.click();
            });
            
            fileInput.addEventListener('change', function() {
                if (this.files.length > 0) {
                    uploadFiles(this.files);
                }
            });
            
            // 拖拽上传
            uploadDropzone.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.classList.add('dragover');
            });
            
            uploadDropzone.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
            });
            
            uploadDropzone.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
                uploadFiles(e.dataTransfer.files);
            });
            
            // 搜索
            if (mediaSearch) {
                mediaSearch.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        loadMediaLibrary(1);
                    }, 500);
                });
            }
            
            // 类型过滤
            if (mediaTypeFilter) {
                mediaTypeFilter.addEventListener('change', function() {
                    loadMediaLibrary(1);
                });
            }
            
            // 媒体库分页按钮事件
            const mediaPrevBtn = modal.querySelector('#prev-btn');
            const mediaNextBtn = modal.querySelector('#next-btn');
            
            if (mediaPrevBtn) {
                mediaPrevBtn.addEventListener('click', function() {
                    if (mediaPaginationLoadingKey) return;
                    if (currentPage > 1) {
                        loadMediaLibrary(currentPage - 1);
                    }
                });
            }
            
            if (mediaNextBtn) {
                mediaNextBtn.addEventListener('click', function() {
                    if (mediaPaginationLoadingKey) return;
                    if (currentPage < totalPages) {
                        loadMediaLibrary(currentPage + 1);
                    }
                });
            }
            
            // 记录媒体库滚动位置（防抖），用于下次打开恢复到相同位置
            let libraryScrollTimer = null;
            if (mediaGrid) {
                mediaGrid.addEventListener('scroll', function() {
                    clearTimeout(libraryScrollTimer);
                    libraryScrollTimer = setTimeout(() => {
                        saveViewScroll('library', mediaGrid.scrollTop);
                    }, 150);
                });
            }
            
            // 记录文件目录滚动位置（防抖），用于下次打开恢复到相同位置
            let directoryScrollTimer = null;
            const directoryScrollGrid = modal.querySelector('#directory-media-grid');
            if (directoryScrollGrid) {
                directoryScrollGrid.addEventListener('scroll', function() {
                    clearTimeout(directoryScrollTimer);
                    directoryScrollTimer = setTimeout(() => {
                        saveViewScroll('directory', directoryScrollGrid.scrollTop);
                    }, 150);
                });
            }
            
            // Directory分页相关变量
            let directoryCurrentPage = 1;
            let directoryTotalPages = 1;
            let directoryPerPage = 20; // 每页显示条数，与下拉默认值同步
            
            // Directory分页按钮事件
            const directoryPrevBtn = modal.querySelector('#directory-prev-btn');
            const directoryNextBtn = modal.querySelector('#directory-next-btn');
            
            if (directoryPrevBtn) {
                directoryPrevBtn.addEventListener('click', function() {
                    if (directoryPaginationLoadingKey) return;
                    if (directoryCurrentPage > 1) {
                        loadDirectoryContent(currentDirectoryPath, directorySearchTerm, directoryTypeFilter, directoryCurrentPage - 1);
                    }
                });
            }
            
            if (directoryNextBtn) {
                directoryNextBtn.addEventListener('click', function() {
                    if (directoryPaginationLoadingKey) return;
                    if (directoryCurrentPage < directoryTotalPages) {
                        loadDirectoryContent(currentDirectoryPath, directorySearchTerm, directoryTypeFilter, directoryCurrentPage + 1);
                    }
                });
            }
            
            // 每页条数下拉切换事件
            const perPageSelect = modal.querySelector('#directory-per-page');
            if (perPageSelect) {
                perPageSelect.addEventListener('change', function() {
                    directoryPerPage = parseInt(this.value, 10) || 20;
                    // 重置到第一页并重新加载
                    directoryCurrentPage = 1;
                    if (directoryTypeFilter === 'unlink') {
                        loadUnlinkedFiles(currentDirectoryPath, 1, directorySearchTerm);
                    } else if (currentDirectoryPath) {
                        loadDirectoryContent(currentDirectoryPath, directorySearchTerm, directoryTypeFilter, 1);
                    }
                });
            }
            
            // Directory分页显示函数
            function displayDirectoryPagination(pagination) {
                const directoryGrid = modal.querySelector('#directory-media-grid');
                const paginationEl = modal.querySelector('#directory-pagination');
                const prevBtn = modal.querySelector('#directory-prev-btn');
                const nextBtn = modal.querySelector('#directory-next-btn');
                const infoEl = modal.querySelector('#directory-pagination-info');
                
                if (!pagination || !paginationEl) return;
                
                // 归一化页码：无数据时按单页处理，避免显示无效页码
                const rawPage = Math.max(1, Math.floor(Number(pagination.current_page) || 1));
                const displayTotalPages = Math.max(1, Math.floor(Number(pagination.total_pages) || 1));
                const displayPage = rawPage > displayTotalPages ? displayTotalPages : rawPage;
                
                // 更新全局变量
                directoryCurrentPage = displayPage;
                directoryTotalPages = displayTotalPages;
                
                // 更新分页信息
                if (infoEl) {
                    infoEl.innerHTML = '<b>第 ' + displayPage + ' 页</b>，共 ' + displayTotalPages + ' 页';
                }
                
                // 更新按钮状态
                if (prevBtn) {
                    prevBtn.disabled = displayPage <= 1;
                }
                if (nextBtn) {
                    nextBtn.disabled = displayPage >= displayTotalPages;
                }
                
                // 控制分页显示：只有当页数大于1时才显示分页
                if (displayTotalPages > 1) {
                    paginationEl.style.display = 'flex';
                    if (directoryGrid) directoryGrid.style.paddingBottom = '0';
                    // 存在多页时保存当前页码，便于下次打开继续浏览
                    saveViewPage('directory', displayPage);
                } else {
                    paginationEl.style.display = 'none';
                    if (directoryGrid) directoryGrid.style.paddingBottom = '15px';
                    // 无分页（只有一页或没有数据）时清除缓存，避免下次打开跳到无效页码
                    clearViewPage('directory');
                }
            }
            
            // 目录浏览器功能
            const directoryTree = modal.querySelector('.directory-tree');
            const directoryTreeCache = new Map();
            let directoryTreeRequestId = 0;
            let directoryTreeAbortController = null;

            if (directoryTree) {
                directoryTree.addEventListener('click', function(e) {
                    const item = e.target.closest('.directory-item');
                    if (!item || !directoryTree.contains(item)) return;
                    const path = item.dataset.path || '';
                    if (
                        item.classList.contains('directory-parent') ||
                        item.classList.contains('directory-current')
                    ) {
                        selectDirectory(path);
                    } else if (item.classList.contains('directory-folder')) {
                        // 有子目录：进入目录树；无子目录（叶子节点）：直接加载内容，不刷新目录树
                        if (item.dataset.hasChildren === '1') {
                            selectDirectory(path);
                        } else {
                            selectLeafDirectory(path, item);
                        }
                    }
                });
            }
            
            // 加载目录树
            function loadDirectoryTree(path = '') {
                const cacheKey = path || '';
                const cached = directoryTreeCache.get(cacheKey);
                if (cached && (Date.now() - cached.time) < 5 * 60 * 1000) {
                    renderDirectoryTree(cached.directories, path);
                    return Promise.resolve(true);
                }

                const requestId = ++directoryTreeRequestId;

                if (directoryTreeAbortController) {
                    try { directoryTreeAbortController.abort(); } catch (e) {}
                }
                directoryTreeAbortController = new AbortController();

                directoryTree.innerHTML = '<div class="directory-loading">正在加载目录...</div>';
                
                // 准备请求数据
                const requestData = {
                    action: 'get_directory_tree',
                    path: path,
                    count_mode: 'shallow'
                };
                
                // 添加CSRF令牌
                if (window.TodoCSRFManager) {
                    const token = TodoCSRFManager.getToken('directory_tree');
                    if (token) {
                        requestData._todononce = token;
                    }
                }
                
                return fetch(window.TodoMediaAjaxPaths.directoryTree, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(requestData),
                    signal: directoryTreeAbortController.signal
                })
                .then(response => {
                    // 检查响应状态
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status} ${response.statusText}`);
                    }
                    
                    // 先获取响应文本
                    return response.text().then(text => {
                        try {
                            // 尝试解析为JSON
                            return JSON.parse(text);
                        } catch (e) {
                            // 如果不是有效JSON，抛出错误并包含响应内容
                            console.error('Invalid JSON response:', text);
                            throw new Error('服务器返回了无效的响应: ' + text.substring(0, 100));
                        }
                    });
                })
                .then(data => {
                    if (requestId !== directoryTreeRequestId) { return false; }
                    if (data.success) {
                        directoryTreeCache.set(cacheKey, { directories: data.directories, time: Date.now() });
                        renderDirectoryTree(data.directories, path);
                        return true;
                    } else {
                        directoryTree.innerHTML = '<div class="directory-error">加载目录失败: ' + (data.message || '未知错误') + '</div>';
                        return false;
                    }
                })
                .catch(error => {
                    if (error && error.name === 'AbortError') { return false; }
                    if (requestId !== directoryTreeRequestId) { return false; }
                    console.error('Directory loading error:', error);
                    directoryTree.innerHTML = '<div class="directory-error">加载目录失败: ' + error.message + '</div>';
                    return false;
                });
            }
            
            // 渲染目录树
            function renderDirectoryTree(directories, basePath = '') {
                let html = '';
                
                // 添加返回上级目录选项（如果不在根目录）
                if (basePath) {
                    const parentPath = basePath.split('/').slice(0, -1).join('/');
                    html += `
                        <div class="directory-item directory-parent" data-path="${parentPath}">
                            <span class="directory-name"><i class="toicon">↩︎</i> 返回上级</span>
                        </div>
                        <div class="directory-item directory-current ${currentDirectory === basePath ? 'active' : ''}" data-path="${basePath}">
                            <span class="directory-name">${basePath}/</span>
                        </div>
                    `;
                }
                
                // 添加子目录
                directories.forEach(dir => {
                    const fullPath = basePath ? basePath + '/' + dir.name : dir.name;
                    const hasChildren = dir.has_children ? '1' : '0';
                    html += `
                        <div class="directory-item directory-folder" data-path="${fullPath}" data-has-children="${hasChildren}">
                            <span class="directory-name">${dir.name}</span>
                            <span class="directory-count">（${dir.file_count})</span>
                        </div>
                    `;
                });
                
                directoryTree.innerHTML = html;
            }
            
            // 选择叶子目录（无子目录）：直接加载内容，不刷新目录树
            function selectLeafDirectory(path, item) {
                currentDirectory = path;
                
                // 如果当前不在目录标签页，才切换
                if (currentTab !== 'directory') {
                    switchTab('directory');
                }
                
                // 在当前目录树中高亮选中项
                directoryTree.querySelectorAll('.directory-item').forEach(el => {
                    el.classList.remove('active');
                });
                if (item) {
                    item.classList.add('active');
                }
                
                // 直接加载该目录的文件内容
                loadDirectoryContent(path, '', '', 1);
            }
            
            // 选择目录进行筛选
            function selectDirectory(path) {
                currentDirectory = path;
                
                // 如果当前不在目录标签页，才切换，避免重复触发加载与竞态
                if (currentTab !== 'directory') {
                    switchTab('directory');
                }
                
                // 更新目录树显示选中目录的子目录
                loadDirectoryTree(path).then(success => {
                    if (success) {
                        // 更新活动状态
                        directoryTree.querySelectorAll('.directory-item').forEach(item => {
                            item.classList.remove('active');
                        });
                        
                        // 安全地添加active类
                        const targetItem = directoryTree.querySelector(`[data-path="${path}"]`);
                        if (targetItem) {
                            targetItem.classList.add('active');
                        }
                    }
                });
                
                // 在文件目录tab中显示目录内容
                loadDirectoryContent(path, '', '', 1);
            }
            
            // 目录浏览器相关变量

            let directorySearchTerm = '';
            let directoryTypeFilter = '';
            // 请求竞态保护：仅渲染最后一次请求的结果
            let directoryContentRequestId = 0;
            let unlinkedContentRequestId = 0;
            
            // 加载目录内容
            function loadDirectoryContent(path, search = '', typeFilter = '', page = 1) {
                const directoryGrid = modal.querySelector('#directory-media-grid');
                if (!directoryGrid) return;
                
                // 更新当前状态
                currentDirectoryPath = path;
                directorySearchTerm = search;
                directoryTypeFilter = typeFilter;
                
                // 标记本次请求ID，用于避免异步竞态导致的旧结果覆盖新结果
                const requestId = ++directoryContentRequestId;
                const loadingKey = 'content:' + requestId;
                directoryPaginationLoadingKey = loadingKey;
                setDirectoryPaginationLoading(true);
                
                // 同步更新筛选器UI控件的值
                const directoryTypeSelect = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-type-filter');
                if (directoryTypeSelect && typeFilter !== undefined) {
                    directoryTypeSelect.value = typeFilter;
                }
                
                // 如果选择的是未记录文件，调用专门的函数（传递目录、页码、搜索词，保持分页和筛选状态）
                if (typeFilter === 'unlink') {
                    loadUnlinkedFiles(path, page || 1, search || '');
                    return;
                }
                
                // 显示加载状态
                directoryGrid.innerHTML = '<div class="loading-spinner"><span class="spinner"></span>加载中...</div>';
                
                // 转换前端类型值为MIME类型前缀
                let convertedTypeFilter = typeFilter;
                if (convertedTypeFilter) {
                    switch(convertedTypeFilter) {
                        case 'image':
                            convertedTypeFilter = 'image';
                            break;
                        case 'video':
                            convertedTypeFilter = 'video';
                            break;
                        case 'audio':
                            convertedTypeFilter = 'audio';
                            break;
                        case 'document':
                            convertedTypeFilter = 'document';
                            break;
                        default:
                            convertedTypeFilter = '';
                    }
                }
                
                // 构建请求数据对象
                const requestData = {
                    action: 'get_directory_content',
                    path: path || '',
                    search: search,
                    type: convertedTypeFilter,
                    include_types: '1', // 强制包含文件类型信息
                    page: page || 1,
                    per_page: directoryPerPage // 动态每页条数
                };
                
                // 添加CSRF令牌
                if (window.TodoCSRFManager) {
                    const token = TodoCSRFManager.getToken('directory_content');
                    if (token) {
                        requestData._todononce = token;
                    }
                }
                
                // 构建请求参数
                const params = new URLSearchParams(requestData);
                
                // 发送AJAX请求获取目录内容
                fetch(window.TodoMediaAjaxPaths.directoryContent, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: params.toString()
                })
                .then(response => {
                    // 检查响应状态
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status} ${response.statusText}`);
                    }
                    
                    // 先获取响应文本
                    return response.text().then(text => {
                        try {
                            // 尝试解析为JSON
                            return JSON.parse(text);
                        } catch (e) {
                            // 如果不是有效JSON，抛出错误并包含响应内容
                            console.error('Invalid JSON response:', text);
                            throw new Error('服务器返回了无效的响应: ' + text.substring(0, 100));
                        }
                    });
                })
                .then(data => {
                    if (data.success) {
                        // 请求竞态保护：若已发起更新的请求，忽略旧响应
                        if (requestId !== directoryContentRequestId) { return; }
                        const pagination = data.data.pagination || {};
                        // 缓存页码可能超出当前实际页数（例如数据被删除），此时回退到最后一页
                        if (pagination.total_pages > 0 && pagination.current_page > pagination.total_pages) {
                            loadDirectoryContent(path, search, typeFilter, pagination.total_pages);
                            return;
                        }
                        renderDirectoryGrid(data.data.files, path);
                        
                        // 处理分页信息
                        if (data.data.pagination) {
                            displayDirectoryPagination(data.data.pagination);
                        } else {
                            directoryCurrentPage = 1;
                            directoryTotalPages = 1;
                            const paginationEl = modal.querySelector('#directory-pagination');
                            if (paginationEl) paginationEl.style.display = 'none';
                            clearViewPage('directory');
                        }
                        // 恢复滚动位置（若当前页与缓存页一致）
                        restoreDirectoryScroll();
                        
                        // 加载完成后根据当前筛选器状态更新UI
                        const directoryTypeSelect = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-type-filter');
                        const bulkActions = modal.querySelector('#directory-bulk-actions');
                        
                        if (directoryTypeSelect && bulkActions) {
                            const currentFilter = directoryTypeSelect.value;
                            
                            // 如果当前筛选器是"未记录"，显示批量操作区域，并确保“添加到媒体库”按钮存在与状态正确
                            if (currentFilter === 'unlink') {
                                bulkActions.style.display = 'inline-flex';
                                if (typeof ensureAddToLibraryButton === 'function') {
                                    ensureAddToLibraryButton();
                                }
                                if (typeof updateAddToLibraryButtonState === 'function') {
                                    updateAddToLibraryButtonState();
                                }
                            } else {
                                bulkActions.style.display = 'none';
                            }
                        }
                        // 重置全选UI
                        if (typeof resetSelectAllUI === 'function') {
                            resetSelectAllUI();
                        }
                    } else {
                        directoryGrid.innerHTML = '<div class="error">加载失败: ' + (data.message || '未知错误') + '</div>';
                        const infoEl = modal.querySelector('#directory-pagination-info');
                        if (infoEl) infoEl.textContent = '加载失败，请重试';
                    }
                })
                .catch(error => {
                    console.error('Error loading directory content:', error);
                    // 仅在最新请求失败时显示错误，避免旧请求失败覆盖新内容
                    if (requestId !== directoryContentRequestId) { return; }
                    directoryGrid.innerHTML = '<div class="error">加载失败，请重试</div>';
                    const infoEl = modal.querySelector('#directory-pagination-info');
                    if (infoEl) infoEl.textContent = '加载失败，请重试';
                })
                .finally(() => {
                    const isLatest = requestId === directoryContentRequestId && directoryPaginationLoadingKey === loadingKey;
                    if (!isLatest) return;
                    directoryPaginationLoadingKey = null;
                    setDirectoryPaginationLoading(false);
                });
            }
            
            // 获取当前活动/可用的 UEditor 实例（支持全站多实例与自定义字段）
            function getActiveUEditor() {
                if (window.TodoActiveEditor && typeof window.TodoActiveEditor.execCommand === 'function') {
                    return window.TodoActiveEditor;
                }
                if (window.ue && typeof window.ue.execCommand === 'function') {
                    return window.ue;
                }
                
                if (window.UE && (window.UE.instants || window.UE.instances)) {
                    const instants = window.UE.instants || window.UE.instances;
                    let lastValidInst = null;
                    for (const key in instants) {
                        if (Object.prototype.hasOwnProperty.call(instants, key)) {
                            const inst = instants[key];
                            if (inst && typeof inst.execCommand === 'function') {
                                if (typeof inst.isFocus === 'function' && inst.isFocus()) {
                                    return inst;
                                }
                                lastValidInst = inst;
                            }
                        }
                    }
                    if (lastValidInst) {
                        return lastValidInst;
                    }
                }
                
                return null;
            }

            // DOCX解析与导入处理
            function handleDocxParse(filePath) {
                const activeEditor = getActiveUEditor();
                if (!activeEditor) {
                    if (window.TODO_UI && window.TODO_UI.toast) {
                        window.TODO_UI.toast.error('当前页面未找到可用的 UEditor 编辑器实例！');
                    }
                    return;
                }
                
                if (window.TODO_UI && window.TODO_UI.toast) {
                    window.TODO_UI.toast.info('正在解析 Word 文档，请稍候...', 3000);
                }
                
                const parseBtns = modal.querySelectorAll('#parse-docx-btn, #parse-docx-btn-media');
                parseBtns.forEach(btn => {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="icon todofont todo-Loading-one loading"></i> 解析中...';
                });
                
                const formData = new FormData();
                formData.append('action', 'parse_docx');
                formData.append('file_path', filePath);
                // 增加 CSRF 令牌
                let csrfToken = '';
                if (window.TodoCSRFManager) {
                    csrfToken = TodoCSRFManager.getToken('parse_docx') || TodoCSRFManager.getToken('default');
                }
                if (!csrfToken && document.querySelector('meta[name="csrf-token"]')) {
                    csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                }
                
                if (csrfToken) {
                    formData.append('csrf_token', csrfToken);
                    formData.append('_todononce', csrfToken);
                }
                
                fetch(addQueryArg(ajaxBasePath + 'parse-docx.php', ajaxUrlSuffix), {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                })
                .then(response => response.json())
                .then(res => {
                    parseBtns.forEach(btn => {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="icon todofont todo-FileCodeOne"></i> 解析内容导入';
                    });
                    
                    if (res.success) {
                        if (window.TODO_UI && window.TODO_UI.toast) {
                            window.TODO_UI.toast.success('文档解析成功！', 2000);
                        }
                        // 追加 HTML 内容到当前编辑器上下文底部
                        activeEditor.execCommand('insertHtml', res.data.html);
                        // 隐藏解析导入按钮
                        parseBtns.forEach(btn => btn.style.display = 'none');
                        // 关闭媒体弹窗（优先调用当前闭包的 closeModal，再回退全局函数与 DOM 强制隐藏）
                        try {
                            if (typeof closeModal === 'function') {
                                closeModal();
                            }
                            if (window.closeTodoMediaModal) {
                                window.closeTodoMediaModal('<?php echo esc_js($options['modal_id']); ?>');
                            }
                            // 兜底：强行关闭当前页面所有显示的媒体弹窗
                            document.querySelectorAll('.media-modal-overlay.show, .media-modal.show, [id*="media-modal"]').forEach(m => {
                                if (m.style.display !== 'none' || m.classList.contains('show')) {
                                    m.classList.remove('show');
                                    m.style.display = 'none';
                                }
                            });
                        } catch (err) {
                            console.warn('关闭媒体弹窗异常:', err);
                        }
                    } else {
                        if (window.TODO_UI && window.TODO_UI.toast) {
                            window.TODO_UI.toast.error(res.message || '解析失败', 3000);
                        }
                    }
                })
                .catch(error => {
                    parseBtns.forEach(btn => {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="icon todofont todo-FileCodeOne"></i> 解析内容导入';
                    });
                    console.error('DOCX parsing error:', error);
                    if (window.TODO_UI && window.TODO_UI.toast) {
                        window.TODO_UI.toast.error('网络错误', 3000);
                    }
                });
            }

            // 渲染目录网格
            function renderDirectoryGrid(files, path) {
                const directoryGrid = modal.querySelector('#directory-media-grid');
                if (!files || files.length === 0) {
                    directoryGrid.innerHTML = '<div class="directory-empty"><p>没有找到文件</p></div>';
                    return;
                }
                
                let html = '';
                
                files.forEach(file => {
                    // 使用服务器返回的MIME类型，如果没有则根据文件名推断
                    const rawMimeType = (file.type || '').toString().trim();
                    const mimeType = rawMimeType || getFileType(file.name);
                    const ref = (file.url || file.name || '').toString();
                    const mimeForDetection = rawMimeType && rawMimeType !== 'application/octet-stream' && rawMimeType !== 'text/plain'
                        ? rawMimeType
                        : '';

                    const isImage = mimeForDetection ? mimeForDetection.indexOf('image/') === 0 : /\.(jpg|jpeg|jpe|png|gif|webp|bmp|svg|ico|heic)$/i.test(ref);
                    const isVideo = mimeForDetection ? mimeForDetection.indexOf('video/') === 0 : /\.(mp4|m4v|avi|mov|wmv|flv|webm|mkv)$/i.test(ref);
                    const isAudio = mimeForDetection ? mimeForDetection.indexOf('audio/') === 0 : /\.(mp3|m4a|m4b|wav|ogg|aac|flac)$/i.test(ref);
                    const isDocument = mimeForDetection ? mimeForDetection.indexOf('document/') === 0 : /\.(pdf|doc|docx|xls|xlsx|ppt|pptx|txt|csv|sql)$/i.test(ref);
                    const isZip = mimeForDetection ? mimeForDetection.indexOf('zip/') === 0 : /\.(zip|rar|7z|tar|gz)$/i.test(ref);

                    let previewHtml = '';
                    if (isImage) {
                        previewHtml = `<img src="${file.url}" alt="${file.name}" loading="lazy">`;
                    } else if (isVideo) {
                        previewHtml = '<div style="font-size: 40px; color: #a0a0aa;">▶</div>';
                    } else if (isAudio) {
                        previewHtml = '<div style="font-size: 40px; color: #a0a0aa;">♫</div>';
                    } else if (isDocument) {
                        previewHtml = '<div style="font-size: 40px; color: #a0a0aa;">⎗</div>';
                    } else if (isZip) {
                        previewHtml = '<div style="font-size: 40px; color: #a0a0aa;">❒</div>';
                    } else {
                        previewHtml = '<div style="font-size: 30px; color: #a0a0aa;">?</div>';
                    }
                    
                    // 为未记录的文件生成临时ID
                    const fileId = file.id || `temp_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
                    
                    const fileKw = (file.seo_keywords || '').toString().trim();
                    const fileExtLabel = getChineseCategoryLabel(mimeType, file.name);
                    const fileMimeText = fileKw ? (fileKw + '-' + fileExtLabel) : fileExtLabel;
                    
                    html += `
                            <div class="media-item" 
                                 data-id="${fileId}" 
                                 data-path="${file.path}" 
                                 data-url="${file.url}" 
                                 data-type="${mimeType}"
                                 data-title="${file.name.replace(/\.[^/.]+$/, '')}"
                                 data-alt="${file.name.replace(/\.[^/.]+$/, '')}"
                                 data-filename="${file.name}"
                                 data-date="${file.date || ''}"
                                 data-size="${file.size || ''}"
                                 data-seo-keywords="${file.seo_keywords || ''}">
                            <div class="media-item-preview">
                                ${previewHtml}
                            </div>
                            <div class="media-item-info">
                                <div class="media-item-title" title="${file.name}">${file.name.replace(/\.[^/.]+$/, "")}</div>
                                <div class="media-item-meta">${file.date || ''}</div>
                                <div class="media-item-mime">${fileMimeText}</div>
                            </div>
                        </div>
                    `;
                });
                
                directoryGrid.innerHTML = html;
                
                // 若处于“未记录”模式，为每个文件注入复选框并标记为 unlinked-file
                try {
                    const directoryTypeSelect = document.getElementById('<?php echo esc_js($options['modal_id']); ?>-directory-type-filter');
                    const inUnlinkMode = directoryTypeSelect && directoryTypeSelect.value === 'unlink';
                    if (inUnlinkMode) {
                        const items = directoryGrid.querySelectorAll('.media-item');
                        items.forEach(it => {
                            it.classList.add('unlinked-file');
                            const prev = it.querySelector('.media-item-preview');
                            if (prev) {
                                prev.style.position = 'relative';
                                const cb = document.createElement('input');
                                cb.type = 'checkbox';
                                cb.className = 'media-item-checkbox';
                                cb.style.position = 'absolute';
                                cb.style.top = '5px';
                                cb.style.left = '5px';
                                cb.style.zIndex = '10';
                                cb.addEventListener('click', function(ev){ ev.stopPropagation(); });
                                cb.addEventListener('change', function(){
                                    // 同步“全选/取消”文字与主控勾选状态，并更新入库按钮可用状态
                                    const allCheckboxes = directoryGrid.querySelectorAll('.media-item.unlinked-file input[type="checkbox"]');
                                    const checkedCount = [...allCheckboxes].filter(x => x.checked).length;
                                    const selectAllText = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-select-all-text');
                                    const selectAllMain = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-select-all');
                                    const anySelected = checkedCount > 0;
                                    if (selectAllText) selectAllText.textContent = anySelected ? '取消' : '全选';
                                    if (selectAllMain) selectAllMain.checked = anySelected;
                                    if (typeof updateAddToLibraryButtonState === 'function') updateAddToLibraryButtonState();
                                });
                                prev.appendChild(cb);
                            }
                        });
                        // 初次渲染时刷新按钮状态
                        if (typeof ensureAddToLibraryButton === 'function') ensureAddToLibraryButton();
                        if (typeof updateAddToLibraryButtonState === 'function') updateAddToLibraryButtonState();
                    }
                } catch (e) { console.warn('注入未记录文件复选框失败：', e); }
                
                // 绑定文件点击事件
                directoryGrid.querySelectorAll('.media-item').forEach(item => {
                    item.addEventListener('click', function(e) {
                        // 防止重复点击
                        if (this.classList.contains('processing')) {
                            return;
                        }
                        
                        // 标记为处理中
                        this.classList.add('processing');
                        
                        // 立即设置选中样式，避免延迟导致“未选择任何文件”
                        try {
                            const isMultiple = modal.getAttribute('data-multiple') === 'true';
                            // 单选模式才提前设置选中样式；多选交给后续的切换逻辑处理，避免被反向取消
                            if (!isMultiple) {
                                modal.querySelectorAll('#directory-media-grid .media-item.selected').forEach(i => { if (i !== this) i.classList.remove('selected'); });
                                this.classList.add('selected');
                                // 立即显示目录侧边栏，提供即时反馈
                                if (currentTab === 'directory' && directorySidebar) {
                                    directorySidebar.style.display = 'block';
                                }
                            }
                            updateDirectorySelectButtonText();
                        } catch (err) { console.warn('即时设置选中样式失败:', err); }
                        
                        // 使用requestAnimationFrame优化性能
                        requestAnimationFrame(() => {
                            try {
                                // 创建文件对象
                                const filename = this.dataset.path.split('/').pop();
                                const titleWithoutExt = filename.replace(/\.[^/.]+$/, "");
                                const fileObj = {
                                    name: filename,
                                    url: this.dataset.url,
                                    path: this.dataset.path,
                                    type: this.dataset.type || getFileType(filename)
                                };
                                
                                // 创建媒体对象用于统一的选择逻辑
                                const mediaData = {
                                    id: this.dataset.id || 0,
                                    url: this.dataset.url,
                                    path: this.dataset.path,
                                    title: titleWithoutExt,
                                    type: this.dataset.type || getFileType(filename),
                                    date: this.dataset.date || '未知日期',
                                    size: this.dataset.size || '未知大小',
                                    thumbnail: this.dataset.url,
                                    name: filename,
                                    filename: filename,
                                    isUnlinked: !this.dataset.id || this.dataset.id == 0
                                };
                                
                                // 使用统一的选择逻辑
                                selectMediaItem(mediaData, this);
                                
                                // 延迟执行其他操作以提高响应速度
                                setTimeout(() => {
                                    // 更新选择按钮文本
                                    updateDirectorySelectButtonText();
                                }, 10);
                                
                            } catch (error) {
                                console.error('目录文件点击处理错误:', error);
                            } finally {
                                // 移除处理中标记
                                setTimeout(() => {
                                    this.classList.remove('processing');
                                }, 50);
                            }
                        });
                    });
                });
            }

            // 添加现有文件到媒体库
            function addFileToMediaLibrary(mediaData, element) {
                if (!mediaData || !mediaData.path) {
                    console.error('无效的媒体数据，缺少文件路径');
                    showMessage('无法添加：无效的文件路径', 'error');
                    return;
                }

                // 准备请求参数
                const params = new URLSearchParams();
                params.append('action', 'add_existing_file');
                params.append('file_path', mediaData.path);

                // 添加CSRF令牌（media-library）
                try {
                    if (window.TodoCSRFManager) {
                        const token = TodoCSRFManager.getToken('media-library');
                        if (token) {
                            params.append('_csrf_token', token);
                        } else {
                            console.warn('CSRF token(media-library) 不存在，将可能被拒绝');
                        }
                    }
                } catch (err) {
                    console.warn('获取CSRF令牌失败：', err);
                }

                // 发送请求
                fetch(window.TodoMediaAjaxPaths.mediaAddExisting, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: params.toString()
                })
                .then(res => res.text())
                .then(text => {
                    let data;
                    try { data = JSON.parse(text); } catch (e) {
                        throw new Error('服务器返回无效JSON：' + text.substring(0, 120));
                    }

                    if (!data.success) {
                        const msg = (data.message || '添加到媒体库失败');
                        showMessage(msg, 'error');
                        return;
                    }

                    // 更新元素与数据的ID
                    const newId = data.data && (data.data.media_id || data.data.id);
                    if (newId) {
                        element.dataset.id = String(newId);
                        mediaData.id = String(newId);
                    }

                    // 选中并展示详情
                    selectMediaItem(mediaData, element);
                    displayMediaDetails(mediaData);
                    if (mediaSidebar) mediaSidebar.style.display = 'block';

                    showMessage('文件已添加到媒体库', 'success');
                })
                .catch(err => {
                    console.error('添加现有文件失败：', err);
                    showMessage('添加到媒体库失败：' + err.message, 'error');
                });
            }

            // 批量添加选中未记录文件到媒体库（根据复选框选择）
            function addSelectedUnlinkedFilesToLibrary() {
                try {
                    const directoryTypeSelect = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-type-filter');

                    // 仅在"未记录"模式下启用
                    if (directoryTypeSelect && directoryTypeSelect.value !== 'unlink') {
                        showMessage('请先将类型筛选切换至"未记录"再进行批量入库。', 'warning');
                        return;
                    }
                    
                    // 收集已勾选的未记录文件
                    const checked = modal.querySelectorAll('.media-item.unlinked-file input[type="checkbox"]:checked');
                    if (!checked || checked.length === 0) {
                        showMessage('请勾选需要添加到媒体库的文件。', 'warning');
                        return;
                    }

                    const elements = Array.from(checked)
                        .map(cb => cb.closest('.media-item'))
                        .filter(el => el && el.dataset && el.dataset.path);

                    const total = elements.length;
                    let index = 0;
                    let successCount = 0;
                    let failCount = 0;

                    let addBtn = modal.querySelector('#add-to-media-library-btn');
                    if (!addBtn && typeof ensureAddToLibraryButton === 'function') {
                        addBtn = ensureAddToLibraryButton();
                    }

                    const originalBtnText = addBtn ? addBtn.textContent : '';
                    if (addBtn) {
                    	addBtn.disabled = true;
                        addBtn.textContent = `添加中...0/${total}`;
                    }

                    // 逐个顺序处理，减少并发对服务器的压力
                    const processNext = () => {
                        if (index >= total) {
                            // 完成
                            if (addBtn) {
                                addBtn.disabled = false;
                                addBtn.style.opacity = '1';
                                addBtn.textContent = originalBtnText || '媒体入库';
                            }

                            // 刷新目录视图，移除已入库文件
                            try {
                                if (typeof currentDirectoryPath !== 'undefined' && currentDirectoryPath) {
                                    loadDirectoryContent(currentDirectoryPath, directorySearchTerm, directoryTypeFilter, 1);
                                }
                            } catch (e) { console.warn('刷新目录失败：', e); }

                            const finalMsg = `添加完成：成功 ${successCount} 个，失败 ${failCount} 个。`;
                            showMessage(finalMsg, failCount ? 'warning' : 'success');

                            // 重置全选UI
                            if (typeof resetSelectAllUI === 'function') {
                                resetSelectAllUI();
                            }
                            return;
                        }

                        const el = elements[index++];
                        // 已有数字ID则跳过
                        const id = (el.dataset.id || '');
                        if (/^\d+$/.test(id)) { processNext(); return; }

                        const filename = el.dataset.filename || el.dataset.title || '';
                        const mediaData = {
                            id: 0,
                            url: el.dataset.url || '',
                            path: el.dataset.path || '',
                            title: (filename || '').replace(/\.[^/.]+$/, ''),
                            type: el.dataset.type || getFileType(filename),
                            size: el.dataset.size || ''
                        };

                        if (!mediaData.path) { failCount++; processNext(); return; }

                        // 组装请求参数（与单个入库一致）
                        const params = new URLSearchParams();
                        params.append('action', 'add_existing_file');
                        params.append('file_path', mediaData.path);
                        try {
                            if (window.TodoCSRFManager) {
                                const token = TodoCSRFManager.getToken('media-library');
                                if (token) params.append('_csrf_token', token);
                            }
                        } catch (err) { console.warn('获取CSRF令牌失败：', err); }

                        fetch(window.TodoMediaAjaxPaths.mediaAddExisting, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: params.toString()
                        })
                        .then(res => res.text())
                        .then(text => {
                            let json;
                            try { json = JSON.parse(text); } catch (e) { throw new Error('无效的服务器响应'); }
                            if (!json.success) {
                                failCount++;
                                console.warn('添加失败：', json.message || text);
                                return;
                            }

                            // 更新DOM状态
                            const newId = json.data && (json.data.media_id || json.data.id);
                            if (newId) {
                                el.dataset.id = String(newId);
                                const cb = el.querySelector('input[type="checkbox"]');
                                if (cb) cb.checked = false;
                            }
                            successCount++;
                        })
                        .catch(err => {
                            failCount++;
                            console.error('添加失败：', err);
                        })
                        .finally(() => {
                            if (addBtn) {
                                addBtn.textContent = `添加中...${Math.min(index, total)}/${total}`;
                            }
                            // 继续处理下一个
                            processNext();
                        });
                    };

                    processNext();
                } catch (e) {
                    console.error('批量添加过程异常：', e);
                    showMessage('批量添加失败：' + e.message, 'error');
                }
            }

            // 目录搜索和过滤事件监听
            const directorySearchInput = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-search');
            const directoryTypeSelect = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-type-filter');
            
            // 目录搜索超时变量
            let directorySearchTimeout;
            
            // 搜索输入事件
            if (directorySearchInput) {
                directorySearchInput.addEventListener('input', function() {
                    clearTimeout(directorySearchTimeout);
                    directorySearchTimeout = setTimeout(() => {
                        if (currentDirectoryPath) {
                            loadDirectoryContent(currentDirectoryPath, this.value, directoryTypeFilter, 1);
                        }
                    }, 500);
                });
            }
            
            // 类型过滤事件
            if (directoryTypeSelect) {
                directoryTypeSelect.addEventListener('change', function() {
                    directoryTypeFilter = this.value;
                    
                    // 控制批量操作区域显示（仅在未记录模式下）
                    const bulkActions = modal.querySelector('#directory-bulk-actions');
                    if (bulkActions) {
                        if (this.value === 'unlink') {
                            bulkActions.style.display = 'inline-flex';
                            // 确保“添加到媒体库”按钮存在，并根据勾选状态启用
                            ensureAddToLibraryButton();
                            updateAddToLibraryButtonState();
                        } else {
                            bulkActions.style.display = 'none';
                            let addBtn = modal.querySelector('#add-to-media-library-btn');
                            if (!addBtn && typeof ensureAddToLibraryButton === 'function') {
                                addBtn = ensureAddToLibraryButton();
                            }
                            if (addBtn) {
                                addBtn.style.display = 'none';
                                addBtn.disabled = true;
                                addBtn.style.opacity = '0.6';
                            }
                        }
                    }
                    
                    // 重置全选控件和项目勾选状态
                    const selectAll = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-select-all');
                    const selectAllText = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-select-all-text');
                    if (selectAll) selectAll.checked = false;
                    if (selectAllText) selectAllText.textContent = '全选';
                    modal.querySelectorAll('.media-item.unlinked-file input[type="checkbox"]').forEach(cb => cb.checked = false);
                    
                    // 勾选已清空后，刷新按钮状态
                    updateAddToLibraryButtonState();
                    
                    if (currentDirectoryPath) {
                        loadDirectoryContent(currentDirectoryPath, directorySearchTerm, this.value, 1);
                    }
                });
            }

            
            // 恢复目录过滤器状态
            function restoreDirectoryFilters() {
                const directorySearchInput = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-search');
                const directoryTypeSelect = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-type-filter');
                
                if (directorySearchInput && directorySearchTerm) {
                    directorySearchInput.value = directorySearchTerm;
                }
                
                if (directoryTypeSelect && directoryTypeFilter) {
                    directoryTypeSelect.value = directoryTypeFilter;
                }
                
                // 恢复批量操作区域显示状态
                const bulkActions = modal.querySelector('#directory-bulk-actions');
                if (bulkActions) {
                    bulkActions.style.display = directoryTypeFilter === 'unlink' ? 'inline-flex' : 'none';
                }
            }
            
            // 初始化过滤器状态
            function initializeFilters() {
                // 重置目录过滤器状态（如果需要保持会话状态，可以从localStorage读取）
                const directorySearchInput = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-search');
                const directoryTypeSelect = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-type-filter');
                
                if (directorySearchInput) {
                    directorySearchInput.value = directorySearchTerm || '';
                }
                
                if (directoryTypeSelect) {
                    directoryTypeSelect.value = directoryTypeFilter || '';
                }
            }
            
            // 获取中文分类标签（根据MIME或扩展名）
            function getChineseCategoryLabel(mimeType, filename) {
                const mt = (mimeType || '').toLowerCase();
                const name = (filename || '').toString();
                let ext = '';
                // 优先：从文件名/URL中提取后缀
                if (name.includes('.')) {
                    const last = name.split('?')[0].split('#')[0];
                    const parts = last.split('.');
                    ext = (parts.length > 1 ? parts.pop() : '').toLowerCase();
                }
                // 兜底：根据 MIME 映射常见后缀
                const mimeToExt = {
                    'image/jpeg': 'jpg',
                    'image/png': 'png',
                    'image/gif': 'gif',
                    'image/webp': 'webp',
                    'image/bmp': 'bmp',
                    'image/svg+xml': 'svg',
                    'image/x-icon': 'ico',
                    'image/vnd.microsoft.icon': 'ico',
                    'image/heic': 'heic',
                    'video/mp4': 'mp4',
                    'video/x-msvideo': 'avi',
                    'video/avi': 'avi',
                    'video/quicktime': 'mov',
                    'video/x-ms-wmv': 'wmv',
                    'video/webm': 'webm',
                    'video/x-flv': 'flv',
                    'video/x-matroska': 'mkv',
                    'audio/mpeg': 'mp3',
                    'audio/mp4': 'm4a',
                    'audio/wav': 'wav',
                    'audio/ogg': 'ogg',
                    'audio/aac': 'aac',
                    'audio/flac': 'flac',
                    'application/pdf': 'pdf',
                    'application/msword': 'doc',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'docx',
                    'application/vnd.ms-excel': 'xls',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'xlsx',
                    'application/vnd.ms-powerpoint': 'ppt',
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'pptx',
                    'text/plain': 'txt',
                    'text/csv': 'csv',
                    'application/rtf': 'rtf',
                    'application/xml': 'xml',
                    'application/json': 'json',
                    'text/sql': 'sql',
                    'application/zip': 'zip',
                    'application/x-zip-compressed': 'zip',
                    'application/x-rar': 'rar',
                    'application/vnd.rar': 'rar',
                    'application/x-rar-compressed': 'rar',
                    'application/x-7z-compressed': '7z',
                    'application/x-tar': 'tar',
                    'application/gzip': 'gz',
                    'application/x-bzip2': 'bz2',
                    'application/x-xz': 'xz'
                };
                if (!ext && mt) {
                    ext = mimeToExt[mt] || '';
                }
                // 统一：若解析为 'jpeg' 则显示 'jpg'
                if (ext === 'jpeg') ext = 'jpg';
                // 返回小写后缀；无结果返回空串
                return ext || '';
            }

            // 显示完整 MIME 类型（优先使用传入的 MIME，无则根据文件名推断）
            function getDisplayMimeType(mimeType, filename) {
                const mt = (mimeType || '').toString().trim();
                if (mt) return mt;
                if (!filename) return 'application/octet-stream';
                return getFileType(filename);
            }
            
            // 获取文件类型（简单扩展名到MIME）
            function getFileType(filename) {
                const base = (filename || '').toString().split('?')[0].split('#')[0];
                const ext = (base.includes('.') ? base.split('.').pop() : '').toLowerCase();
                if (['jpg', 'jpeg'].includes(ext)) return 'image/jpeg';
                if (['png'].includes(ext)) return 'image/png';
                if (['gif'].includes(ext)) return 'image/gif';
                if (['webp'].includes(ext)) return 'image/webp';
                if (['svg', 'svgz'].includes(ext)) return 'image/svg+xml';
                if (['ico'].includes(ext)) return 'image/x-icon';
                if (['mp4'].includes(ext)) return 'video/mp4';
                if (['avi'].includes(ext)) return 'video/avi';
                if (['mov'].includes(ext)) return 'video/quicktime';
                if (['wmv'].includes(ext)) return 'video/x-ms-wmv';
                if (['mp3'].includes(ext)) return 'audio/mpeg';
                if (['wav'].includes(ext)) return 'audio/wav';
                if (['ogg'].includes(ext)) return 'audio/ogg';
                // 常见文档与压缩扩展兜底（用于预览图标）
                if (['pdf','doc','docx','xls','xlsx','ppt','pptx','rtf','txt','md'].includes(ext)) return 'application/octet-stream';
                if (['zip','rar','7z','tar','gz','bz2','xz'].includes(ext)) return 'application/octet-stream';
                return 'application/octet-stream';
            }
            
            // 全局可用的媒体文件转 HTML 工具（在编辑器场景中：图片使用 <img>，非图片统一使用 <a rel="nofollow">）
            function formatMediaHtmlForEditor(media) {
                if (!media) return '';
                const url = media.url || media.path || '';
                if (!url) return '';
                
                const title = media.title || media.alt || media.filename || media.name || (url.split('/').pop()) || '媒体文件';
                const type = (media.type || '').toString().toLowerCase();
                const isImage = type.startsWith('image/') || /\.(jpg|jpeg|png|gif|webp|bmp|svg)$/i.test(url);
                
                if (isImage) {
                    return `<img src="${url}" alt="${title}" title="${title}" />`;
                } else {
                    return `<a href="${url}" rel="nofollow" target="_blank" title="${title}">${title}</a>`;
                }
            }
            window.formatMediaHtmlForEditor = formatMediaHtmlForEditor;
            
            // 选择媒体
            selectMediaBtn.addEventListener('click', function() {
                // 获取所有选中的媒体项
                const selectedItems = modal.querySelectorAll('.media-item.selected');
                
                if (selectedItems.length > 0) {
                    // 收集所有选中的媒体数据
                    const selectedMediaArray = [];
                    selectedItems.forEach(item => {
                        const mediaData = {
                            id: item.dataset.id,
                            url: item.dataset.url,
                            title: item.dataset.title || '',
                            alt: item.dataset.alt || '',
                            type: item.dataset.type || '',
                            filename: item.dataset.filename || '',
                            name: item.dataset.filename || '',
                            path: item.dataset.path || '',
                            size: item.dataset.size || '',
                            dimensions: item.dataset.dimensions || '',
                            seo_keywords: item.dataset.seoKeywords || item.dataset.seo_keywords || ''
                        };
                        selectedMediaArray.push(mediaData);
                    });
                    
                    // 从模态框数据属性获取当前配置
                    const currentModal = modal;
                    
                    // 执行回调
                    if (window.mediaSelectCallback && typeof window.mediaSelectCallback === 'function') {
                        // 检查是否为多选模式
                        const isMultiple = currentModal.getAttribute('data-multiple') === 'true';
                        
                        // 根据多选模式决定回调数据格式
                        let callbackData;
                        if (isMultiple) {
                            // 多选模式：始终传递数组
                            callbackData = selectedMediaArray;
                        } else {
                            // 单选模式：传递单个对象
                            callbackData = selectedMediaArray[0];
                        }
                        
                        window.mediaSelectCallback(callbackData);
                    }
                    const inputId = currentModal ? currentModal.getAttribute('data-input-id') : '';
                    const previewId = currentModal ? currentModal.getAttribute('data-preview-id') : '';
                    
                    // 填充输入框（使用第一个选中的媒体）
                    if (inputId && selectedMediaArray.length > 0) {
                        const input = domTool.get(inputId);
                        if (input) {
                            input.value = selectedMediaArray[0].url;
                        }
                    }
                    
                    // 更新预览（使用第一个选中的媒体）
                    if (previewId && selectedMediaArray.length > 0) {
                        const preview = domTool.get(previewId);
                        const firstMedia = selectedMediaArray[0];
                        if (preview && firstMedia.type && firstMedia.type.startsWith('image/')) {
                            preview.innerHTML = `
                                <div class="featured-image-item">
                                    <div class="featured-image-preview">
                                        <img src="${firstMedia.url}" alt="${firstMedia.title}" style="width: 32px; height: 32px;">
                                    </div>
                                    <div class="featured-image-actions">
                                        <button type="button" class="button remove-featured-image" title="移除图标" onclick="removeSiteFavicon(); return false;">
                                            x
                                        </button>
                                    </div>
                                </div>
                            `;
                        }
                    }
                    
                    closeModal();
                } else {
                    console.error('未选择任何媒体');
                    showMessage('请先选择要插入的媒体文件', 'warning');
                }
            });
            
            // 选择目录文件
            selectDirectoryBtn.addEventListener('click', function() {
                // 根据当前tab获取所有选中的项目
                let selectedItems;
                if (currentTab === 'directory') {
                    // 目录tab：获取目录网格中选中的项目
                    selectedItems = modal.querySelectorAll('#directory-media-grid .media-item.selected');
                } else {
                    // 其他tab：获取媒体网格中选中的项目
                    selectedItems = modal.querySelectorAll('#media-grid .media-item.selected');
                }
                
                if (selectedItems.length > 0) {
                    // 收集所有选中的文件数据
                    const selectedFilesArray = [];
                    selectedItems.forEach(item => {
                        const fileData = {
                            id: item.dataset.id || '',
                            url: item.dataset.url,
                            title: item.dataset.title || item.dataset.filename || '',
                            alt: item.dataset.alt || '',
                            type: item.dataset.type || '',
                            filename: item.dataset.filename || '',
                            name: item.dataset.filename || '',
                            path: item.dataset.path || '',
                            size: item.dataset.size || '',
                            dimensions: item.dataset.dimensions || '',
                            seo_keywords: item.dataset.seoKeywords || item.dataset.seo_keywords || ''
                        };
                        selectedFilesArray.push(fileData);
                    });
                    
                    // 从模态框数据属性获取当前配置
                    const currentModal = modal;
                    
                    // 执行回调
                    if (window.mediaSelectCallback && typeof window.mediaSelectCallback === 'function') {
                        // 检查是否为多选模式
                        const isMultiple = currentModal.getAttribute('data-multiple') === 'true';
                        
                        // 根据多选模式决定回调数据格式
                        let callbackData;
                        if (isMultiple) {
                            // 多选模式：始终传递数组
                            callbackData = selectedFilesArray;
                        } else {
                            // 单选模式：传递单个对象
                            callbackData = selectedFilesArray[0];
                        }
                        
                        window.mediaSelectCallback(callbackData);
                    }
                    
                    const inputId = currentModal ? currentModal.getAttribute('data-input-id') : '';
                    const previewId = currentModal ? currentModal.getAttribute('data-preview-id') : '';
                    
                    // 填充输入框（使用第一个选中的文件）
                    if (inputId && selectedFilesArray.length > 0) {
                        const input = domTool.get(inputId);
                        if (input) {
                            input.value = selectedFilesArray[0].url;
                        }
                    }
                    
                    // 更新预览（使用第一个选中的文件）
                    if (previewId && selectedFilesArray.length > 0) {
                        const preview = domTool.get(previewId);
                        const firstFile = selectedFilesArray[0];
                        if (preview && firstFile.type && firstFile.type.startsWith('image/')) {
                            preview.innerHTML = `
                                <div class="featured-image-item">
                                    <div class="featured-image-preview">
                                        <img src="${firstFile.url}" alt="${firstFile.title}" style="width: 32px; height: 32px;">
                                    </div>
                                    <div class="featured-image-actions">
                                        <button type="button" class="button remove-featured-image" title="移除图标" onclick="removeSiteFavicon(); return false;">
                                            x
                                        </button>
                                    </div>
                                </div>
                            `;
                        }
                    }
                    
                    closeModal();
                } else {
                    console.error('未选择任何文件');
                    showMessage('请先选择要插入的文件', 'warning');
                }
            });
            
            // 点击背景关闭
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeModal();
                }
            });
            
            // ESC键关闭
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('show')) {
                    closeModal();
                }
            });
            
            // 显示消息提示
            function showMessage(message, type = 'info') {
                // 创建消息元素
                const messageEl = document.createElement('div');
                messageEl.className = `media-message media-message-${type}`;
                messageEl.textContent = message;
                
                // 添加样式
                messageEl.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    padding: 12px 20px;
                    border-radius: 4px;
                    color: white;
                    font-size: 14px;
                    z-index: 10001;
                    max-width: 300px;
                    word-wrap: break-word;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                    transition: opacity 0.3s ease;
                `;
                
                // 根据类型设置背景色
                switch (type) {
                    case 'success':
                        messageEl.style.backgroundColor = '#28a745';
                        break;
                    case 'error':
                        messageEl.style.backgroundColor = '#dc3545';
                        break;
                    case 'warning':
                        messageEl.style.backgroundColor = '#ffc107';
                        messageEl.style.color = '#212529';
                        break;
                    default:
                        messageEl.style.backgroundColor = '#17a2b8';
                }
                
                // 添加到页面
                let targetBody = document.body;
                if (window.parent && window.parent.document && window.parent.document.body && window.parent !== window) {
                    try {
                        targetBody = window.parent.document.body;
                    } catch (e) {
                        // 跨域限制或其他错误，回退到当前 body
                    }
                }
                targetBody.appendChild(messageEl);
                
                // 5秒后自动移除
                setTimeout(() => {
                    messageEl.style.opacity = '0';
                    setTimeout(() => {
                        if (messageEl.parentNode) {
                            messageEl.parentNode.removeChild(messageEl);
                        }
                    }, 300);
                }, 5000);
            }
            
            // 清理空文件夹功能
            function initCleanFoldersFeature() {
                const cleanButton = modal.querySelector('#clear-folder');
                if (cleanButton && !cleanButton.hasAttribute('data-initialized')) {
                    cleanButton.setAttribute('data-initialized', 'true');
                    
                    cleanButton.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        TodoModal.confirm({
                            title: '清理空文件夹',
                            message: '确定要清理所有空文件夹吗？',
                            onConfirm: function() {
                                cleanEmptyFolders();
                            }
                        });
                    });
                }
            }
            
            // 执行清理空文件夹
            function cleanEmptyFolders() {
                const cleanButton = modal.querySelector('#clear-folder');
                const originalTitle = cleanButton.title;
                
                // 显示加载状态
                cleanButton.style.opacity = '0.5';
                cleanButton.title = '正在清理...';
                cleanButton.style.pointerEvents = 'none';
                
                // 获取CSRF令牌
                const csrfToken = TodoCSRFManager.getToken('default');
                
                const formData = new FormData();
                formData.append('_todononce', csrfToken);
                
                fetch(addQueryArg(ajaxBasePath + 'media-clean-folders.php', ajaxUrlSuffix), {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    // 处理响应消息
                    if (data.message) {
                        // 根据成功/失败情况显示不同颜色的消息
                        if (data.failed_count > 0 && data.deleted_count > 0) {
                            // 部分成功
                            showMessage(data.message, 'warning');
                            console.warn('部分文件夹删除失败:', data.failed_folders);
                        } else if (data.failed_count > 0) {
                            // 全部失败
                            showMessage(data.message, 'error');
                            console.error('删除失败:', data.failed_folders);
                        } else if (data.deleted_count > 0) {
                            // 全部成功
                            showMessage(data.message || `清理完成！删除了 ${data.deleted_count} 个空文件夹。`, 'success');
                        } else {
                            // 没有空文件夹
                            showMessage(data.message || '没有发现空文件夹。', 'info');
                        }
                        
                        // 如果有删除成功的，刷新目录树
                        if (data.deleted_count > 0 && typeof loadDirectoryTree === 'function') {
                            loadDirectoryTree();
                        }
                    } else if (data.success) {
                        // 兼容旧格式
                        if (data.deleted_count > 0) {
                            showMessage(`清理完成！删除了 ${data.deleted_count} 个空文件夹。`, 'success');
                            if (typeof loadDirectoryTree === 'function') {
                                loadDirectoryTree();
                            }
                        } else {
                            showMessage('没有发现空文件夹。', 'info');
                        }
                    } else {
                        showMessage('清理失败: ' + (data.error || '未知错误'), 'error');
                    }
                })
                .catch(error => {
                    console.error('清理空文件夹失败:', error);
                    showMessage('清理失败，请稍后重试。', 'error');
                })
                .finally(() => {
                    // 恢复按钮状态
                    cleanButton.style.opacity = '1';
                    cleanButton.title = originalTitle;
                    cleanButton.style.pointerEvents = 'auto';
                });
            }
            
            // 删除未记录文件功能
            function initDeleteUnlinkedFilesFeature() {
                const deleteBtn = modal.querySelector('#delete-directory-btn');
                if (deleteBtn && !deleteBtn.hasAttribute('data-initialized')) {
                    deleteBtn.setAttribute('data-initialized', 'true');
                    
                    deleteBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        // 获取当前选中的文件
                        const selectedFiles = getSelectedUnlinkedFiles();
                        
                        if (selectedFiles.length === 0) {
                            showMessage('请勾选要删除的文件。', 'warning');
                            return;
                        }
                        
                        const fileNames = selectedFiles.map(file => file.name).join('\n');
                        const confirmMessage = `确定要删除以下 ${selectedFiles.length} 个文件吗？将从文件目录中直接删除不可恢复。<br><br>${fileNames.replace(/\n/g, '<br>')}`;
                        
                        TodoModal.confirm({
                            message: confirmMessage,
                            onConfirm: function() {
                                deleteUnlinkedFiles(selectedFiles);
                            }
                        });
                    });
                }
            }
            
            // 获取选中的未记录文件
            function getSelectedUnlinkedFiles() {
                const selectedFiles = [];
                const checkedItems = modal.querySelectorAll('.media-item.unlinked-file input[type="checkbox"]:checked');
                
                checkedItems.forEach(checkbox => {
                    const mediaItem = checkbox.closest('.media-item');
                    if (mediaItem && mediaItem.dataset.path) {
                        selectedFiles.push({
                            name: mediaItem.dataset.filename || mediaItem.dataset.title,
                            path: mediaItem.dataset.path
                        });
                    }
                });
                
                return selectedFiles;
            }
            
            // 执行删除未记录文件
            function deleteUnlinkedFiles(files) {
                const deleteBtn = modal.querySelector('#delete-directory-btn');
                const originalText = deleteBtn.textContent;
                
                // 显示加载状态
                deleteBtn.style.opacity = '0.5';
                deleteBtn.textContent = '删除中...';
                deleteBtn.style.pointerEvents = 'none';
                
                // 获取CSRF令牌
                const csrfToken = TodoCSRFManager.getToken('default');
                
                const formData = new FormData();
                formData.append('_todononce', csrfToken);
                formData.append('files', JSON.stringify(files));
                
                fetch(addQueryArg(ajaxBasePath + 'media-delete-unlinked.php', ajaxUrlSuffix), {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const msg = data.message || `删除完成！成功删除了 ${data.deleted_count} 个文件。`;
                        showMessage(msg, 'success');
                        
                        // 如果有部分失败，输出到控制台便于排查
                        if (Array.isArray(data.errors) && data.errors.length) {
                            console.warn('部分文件删除失败:', data.errors);
                        }
                        
                        // 仅在确实有删除时刷新目录
                        if (data.deleted_count > 0 && typeof currentDirectoryPath !== 'undefined' && currentDirectoryPath) {
                            loadDirectoryContent(currentDirectoryPath, directorySearchTerm, directoryTypeFilter, 1);
                        }
                        
                        // 清空详情面板
                        const preview = modal.querySelector('#directory-preview');
                        const info = modal.querySelector('#directory-info');
                        if (preview) preview.innerHTML = '';
                        if (info) info.innerHTML = '';
                        
                    } else {
                        const errMsg = data.message || data.error || '未知错误';
                        showMessage('删除失败: ' + errMsg, 'error');
                    }
                })
                .catch(error => {
                    console.error('删除未记录文件失败:', error);
                    showMessage('删除失败，请稍后重试。', 'error');
                })
                .finally(() => {
                    // 恢复按钮状态
                    deleteBtn.style.opacity = '1';
                    deleteBtn.textContent = originalText;
                    deleteBtn.style.pointerEvents = 'auto';
                });
            }
            
            // 初始化清理功能
            initCleanFoldersFeature();
            
            // 初始化删除未记录文件功能
            initDeleteUnlinkedFilesFeature();
            
            // 辅助：确保批量入库按钮存在并绑定事件（目录批量区域）
            function ensureAddToLibraryButton() {
                const bulkActions = modal.querySelector('#directory-bulk-actions');
                if (!bulkActions) return null;
                let addBtn = modal.querySelector('#add-to-media-library-btn');
                if (!addBtn) {
                    addBtn = document.createElement('button');
                    addBtn.type = 'button';
                    addBtn.id = 'add-to-media-library-btn';
                    addBtn.className = 'button button-primary shrink-0';
                    addBtn.textContent = '媒体入库';
                    bulkActions.appendChild(addBtn);
                    addBtn.onclick = function() {
                        try { addSelectedUnlinkedFilesToLibrary(); } catch (e) {
                            console.error('批量添加到媒体库时发生错误：', e);
                            showMessage('添加到媒体库失败：' + e.message, 'error');
                        }
                    };
                }
                addBtn.style.display = '';
                return addBtn;
            }

            // 辅助：根据勾选数量更新“添加到媒体库”按钮的启用状态（目录批量区域）
            function updateAddToLibraryButtonState() {
                let addBtn = modal.querySelector('#add-to-media-library-btn');
                if (!addBtn && typeof ensureAddToLibraryButton === 'function') {
                    addBtn = ensureAddToLibraryButton();
                }
                if (!addBtn) return;
                const checked = modal.querySelectorAll('.media-item.unlinked-file input[type="checkbox"]:checked');
                const hasSelected = checked && checked.length > 0;
                addBtn.disabled = !hasSelected;
                addBtn.style.opacity = hasSelected ? '1' : '0.6';
            }

            // 初始化删除按钮状态（同时初始化批量入库按钮）
            function initDeleteButtonState() {
                const bulkActions = modal.querySelector('#directory-bulk-actions');
                const directoryTypeSelect = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-type-filter');
                
                if (bulkActions && directoryTypeSelect) {
                    // 根据当前筛选条件设置批量操作显示状态
                    if (directoryTypeSelect.value === 'unlink') {
                        bulkActions.style.display = 'inline-flex';
                        // 确保存在“添加到媒体库”按钮并绑定批量入库
                        ensureAddToLibraryButton();
                        updateAddToLibraryButtonState();
                    } else {
                        bulkActions.style.display = 'none';
                        let addBtn = modal.querySelector('#add-to-media-library-btn');
                        if (!addBtn && typeof ensureAddToLibraryButton === 'function') {
                            addBtn = ensureAddToLibraryButton();
                        }
                        if (addBtn) {
                            addBtn.style.display = 'none';
                            addBtn.disabled = true;
                            addBtn.style.opacity = '0.6';
                        }
                    }
                }
            }
            
            // 初始化批量操作区域（含全选/删除）
            initDeleteButtonState();
            
            // 全选/取消：统一绑定到 label（仅在"未记录"模式下作用于当前页）
            // 支持跨框架：使用 modal.querySelector 而不是 document.getElementById
            const selectAllCheckbox = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-select-all');
            const selectAllText = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-select-all-text');
                        
            
            // 当主复选框通过键盘或脚本变更时，仅同步文案（不触发批量选中逻辑）
            if (selectAllCheckbox && selectAllText) {
                selectAllCheckbox.addEventListener('change', function() {
                    const directoryTypeSelect = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-type-filter');
                    if (!directoryTypeSelect || directoryTypeSelect.value !== 'unlink') {
                        return;
                    }
                    selectAllText.textContent = this.checked ? '取消' : '全选';
                                
                    const checkboxes = modal.querySelectorAll('.media-item.unlinked-file input[type="checkbox"]');
                    const anySelected = [...checkboxes].some(cb => cb.checked);
                    if (anySelected) {
                        // 取消
                        checkboxes.forEach(cb => { cb.checked = false; });
                    } else {
                        // 全选
                        checkboxes.forEach(cb => { cb.checked = true; });
                    }
                                
                    // 切换后刷新入库按钮可用状态
                    if (typeof updateAddToLibraryButtonState === 'function') {
                        updateAddToLibraryButtonState();
                    }
                });
            }
                        
            // 当目录内容重新渲染或分页切换时，重置全选文字
            function resetSelectAllUI() {
                const selectAll = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-select-all');
                const text = modal.querySelector('#<?php echo esc_js($options['modal_id']); ?>-directory-select-all-text');
                if (selectAll) selectAll.checked = false;
                if (text) text.textContent = '全选';
            }
            
            // 暴露关键函数到全局作用域
            window.showModal = showModal;
            window.closeModal = closeModal;
            window.loadMediaLibrary = loadMediaLibrary;
            // 额外暴露带ID作用域的函数，避免多模态框时相互覆盖
            window['showModal_' + modal.id] = showModal;
            window['closeModal_' + modal.id] = closeModal;
            window['loadMediaLibrary_' + modal.id] = loadMediaLibrary;
        };
        (function() {
             var fn = window.initTodoMediaModalLogic_<?php echo md5($options['modal_id']); ?>;
             fn();
             if (document.readyState === 'loading') {
                 document.addEventListener('DOMContentLoaded', fn);
             }
        })();
        
        // 全局函数定义（在IIFE外部）
        window.showUploadModal = function() {
            if (typeof window.showModal === 'function') {
                window.showModal();
            } else {
                console.error('媒体模态框未初始化');
            }
        };
        
        window.TodoMediaNoticeRules = <?php echo json_encode(function_exists('get_option') ? (string) get_option('media_modal_notice_rules', '') : ''); ?>;

        window.selectMediaForField = function(options) {
            // 防御：若传入的 modal_id 不存在，回退到当前页面已渲染的第一个媒体模态框
            function resolveModal(targetId) {
                var docs = [document];
                if (window.parent && window.parent !== window) {
                    try {
                        if (window.parent.document) {
                            docs.push(window.parent.document);
                        }
                    } catch (e) {}
                }

                for (var i = 0; i < docs.length; i++) {
                    var doc = docs[i];
                    if (!doc) continue;
                    if (targetId) {
                        var m = doc.getElementById(targetId);
                        if (m) return m;
                    }
                    var list = doc.querySelectorAll('.media-modal-overlay');
                    if (list && list.length) {
                        return list[0];
                    }
                }
                return null;
            }
            if (typeof window.showModal !== 'function') {
                console.error('媒体模态框未初始化');
                return;
            }
            
            const defaults = {
                title: '选择媒体文件',
                button_text: '选择',
                multiple: false,
                type: 'image',
                modal_id: 'media-modal',
                input_id: '',
                preview_id: '',
                callback: null,
                default_tab: 'library'
            };
            
            options = Object.assign({}, defaults, options);

            var __targetModalEl = resolveModal(options.modal_id) || document.getElementById('media-modal');
            const modalTitle = __targetModalEl ? __targetModalEl.querySelector('.media-modal-title') : document.querySelector('.media-modal-title');
            if (modalTitle && options.title) {
                var noticeHtml = '';
                var rawRules = window.TodoMediaNoticeRules || '';
                if (rawRules) {
                    var curTitle = String(options.title || '').trim();
                    var curId = String(options.modal_id || '').trim();
                    var matchedNotice = '';
                    var re = /\[([^\|\]]*)\|([^\|\]]*)\|([^\]]*)\]/g;
                    var match;
                    var rules = [];
                    while ((match = re.exec(rawRules)) !== null) {
                        rules.push({
                            title: String(match[1] || '').trim(),
                            id: String(match[2] || '').trim(),
                            notice: String(match[3] || '').trim()
                        });
                    }
                    // 匹配优先级：
                    // 1. title + id 双重精准匹配
                    // 2. 仅 title 匹配（rule_id 为空）
                    // 3. 仅 id 匹配（rule_title 为空）
                    for (var r = 0; r < rules.length; r++) {
                        if (rules[r].title && rules[r].id && rules[r].title === curTitle && rules[r].id === curId) {
                            matchedNotice = rules[r].notice;
                            break;
                        }
                    }
                    if (!matchedNotice) {
                        for (var r = 0; r < rules.length; r++) {
                            if (rules[r].title && rules[r].title === curTitle && !rules[r].id) {
                                matchedNotice = rules[r].notice;
                                break;
                            }
                        }
                    }
                    if (!matchedNotice) {
                        for (var r = 0; r < rules.length; r++) {
                            if (rules[r].id && rules[r].id === curId && !rules[r].title) {
                                matchedNotice = rules[r].notice;
                                break;
                            }
                        }
                    }
                    if (matchedNotice) {
                        noticeHtml = '<span class="media-modal-notice">' + matchedNotice + '</span>';
                    }
                }
                modalTitle.innerHTML = options.title + noticeHtml;
            }

            // 动态更新选择按钮文本
            const selectButton = __targetModalEl ? __targetModalEl.querySelector('#select-media-btn') : document.querySelector('#select-media-btn');
            const selectDirectoryButton = __targetModalEl ? __targetModalEl.querySelector('#select-directory-btn') : document.querySelector('#select-directory-btn');
            
            if (selectButton && options.button_text) {
                selectButton.textContent = options.button_text;
            }
            if (selectDirectoryButton && options.button_text) {
                selectDirectoryButton.textContent = options.button_text;
            }

            // 设置模态框的数据属性来标识当前用途（带回退）
            const modal = resolveModal(options.modal_id) || document.getElementById('media-modal');
            if (!modal) { return; }
            // 记录当前用途与目标字段
            modal.setAttribute('data-current-id', options.modal_id || modal.id);
            modal.setAttribute('data-input-id', options.input_id || '');
            modal.setAttribute('data-preview-id', options.preview_id || '');
            modal.setAttribute('data-multiple', options.multiple ? 'true' : 'false');
            modal.setAttribute('data-type', options.type || 'file');

            // 同步上传<input type="file">的 multiple 属性
            const fileInput = modal.querySelector('#file-input');
            if (fileInput) {
                if (options.multiple) {
                    fileInput.setAttribute('multiple', 'multiple');
                } else {
                    fileInput.removeAttribute('multiple');
                }

                (function updateAcceptByType() {
                    function computeAccept(t) {
                        const type = String(t || '').toLowerCase();
                        if (type === 'image') return 'image/*';
                        if (type === 'video') return 'video/*';
                        if (type === 'audio') return 'audio/*';
                        const raw = (window.TodoMediaConfig && window.TodoMediaConfig.allowedFileTypes) ? String(window.TodoMediaConfig.allowedFileTypes) : '';
                        const exts = raw.split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
                        if (!exts.length) return '*/*';
                        const uniq = Array.from(new Set(exts));
                        return uniq.map(ext => '.' + ext).join(',');
                    }
                    const accept = computeAccept(options.type);
                    if (accept) {
                        fileInput.setAttribute('accept', accept);
                    }
                })();
            }

            (function updateLibraryFilterByType() {
                try {
                    const select = modal.querySelector('#' + modal.id + '-media-type-filter');
                    if (!select) return;
                    const t = String(options.type || '').toLowerCase();
                    if (t === 'image' || t === 'video' || t === 'audio') {
                        select.value = t;
                    } else {
                        select.value = '';
                    }
                } catch (e) {}
            })();

            // 显示模态框（针对特定modal实例）
            if (window['showModal_' + modal.id]) {
                window['showModal_' + modal.id]();
            } else {
                window.showModal();
            }

            // 设置选择回调
            if (options.callback && typeof options.callback === 'function') {
                window.mediaSelectCallback = options.callback;
            }
            
            // 如果是多选模式，更新按钮文本提示
            if (options.multiple) {
                if (selectButton) {
                    selectButton.textContent = options.button_text + ' (可多选)';
                }
                if (selectDirectoryButton) {
                    selectDirectoryButton.textContent = options.button_text + ' (可多选)';
                }
            }
            
            // 激活指定的默认tab
            if (options.default_tab && options.default_tab !== 'library') {
                setTimeout(() => {
                    const tabButton = modal.querySelector(`[data-tab="${options.default_tab}"]`);
                    if (tabButton) {
                        tabButton.click();
                    }
                }, 100); // 延迟确保模态框完全显示
            }
        };
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * 获取文件类型的accept属性
     *
     * @param string $type 文件类型
     * @return string accept属性值
     */
    private static function get_accept_types($type) {
        // 从设置中获取允许的文件类型（扩展名列表）
        $allowed_types = todo_get_option('allowed_file_types', 'jpg,jpeg,png,gif,webp,svg,bmp,ico,mp4,avi,mov,wmv,flv,webm,mkv,mp3,wav,ogg,aac,flac,m4a,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,rtf,zip,rar,7z,tar,gz,csv,xml,json');
        $exts = array_filter(array_map('strtolower', array_map('trim', explode(',', $allowed_types ?? ''))));
        $map = function_exists('todo_supported_mime_types_flat') ? todo_supported_mime_types_flat() : [];
        
        switch ($type) {
            case 'image':
                return 'image/*';
            case 'video':
                return 'video/*';
            case 'audio':
                return 'audio/*';
            case 'file':
            default:
                if (empty($exts)) return '*/*';
                $accept = [];
                foreach ($exts as $ext) {
                    $accept[] = '.' . $ext; // 扩展名
                    if (isset($map[$ext])) {
                        $accept[] = $map[$ext]; // 对应MIME
                    }
                }
                // 去重
                $accept = array_values(array_unique($accept));
                return implode(',', $accept);
        }
    }
    
    /**
     * 渲染媒体选择按钮
     *
     * @param array $options 按钮选项
     * @return string HTML内容
     */
    public static function render_button($options = array()) {
        $defaults = array(
            'text' => '选择媒体',
            'class' => 'button',
            'modal_id' => 'media-modal'
        );
        
        $options = array_merge($defaults, $options);
        
        return sprintf(
            '<button type="button" class="%s" onclick="showUploadModal()">%s</button>',
            esc_attr($options['class']),
            esc_html($options['text'])
        );
    }
}

/**
 * 辅助函数：渲染媒体模态框
 */
function todo_media_modal($modal_id = 'media-modal', $options = array()) {
    echo TodoMediaModal::render($modal_id, $options);
}

/**
 * 辅助函数：渲染媒体选择按钮
 */
function todo_media_button($options = array()) {
    echo TodoMediaModal::render_button($options);
}
