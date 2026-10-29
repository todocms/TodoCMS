<?php
/**
 * 特色图像组件
 *
 * 支持单图/多图选择、拖拽排序、删除功能的特色图像管理组件
 * 集成媒体模态框进行图片选择
 * 
 * 重构特性：
 * - 模块化设计，职责分离
 * - 配置管理独立
 * - 资源管理优化
 * - 更好的错误处理
 *
 * @package TodoCMS
 * @version 2.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DOING_AJAX') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 确保媒体模态框组件已加载
if (!class_exists('TodoMediaModal')) {
    require_once __DIR__ . '/media-modal.php';
}

/**
 * 特色图片组件配置管理类
 */
class FeaturedImagesConfig {
    
    /**
     * 获取默认配置
     * 
     * @return array
     */
    public static function getDefaults() {
        return array(
            'field_name' => 'featured_images',
            'field_id' => 'featured-images',
            'title' => '特色图片',
            'description' => '为您的内容设置特色图片',
            'multiple' => true,
            'max_images' => 10,
            'current_images' => array(),
            'sortable' => true,
            'show_preview' => true,
            'preview_size' => 'thumbnail',
            'file_type' => 'any', // any|image|video|audio|document|archive
            'variant' => 'featured', // featured|universal（通用多文件字段）
            'enable_debug' => false
        );
    }
    
    /**
     * 合并和验证配置
     * 
     * @param array $options 用户配置
     * @return array 验证后的配置
     */
    public static function merge($options = array()) {
        $defaults = self::getDefaults();
        $config = array_merge($defaults, $options);

        $normalized_type = isset($config['file_type']) ? strtolower((string) $config['file_type']) : 'any';
        if ($normalized_type === 'any' || $normalized_type === 'document' || $normalized_type === 'archive') {
            $normalized_type = 'file';
        }

        if (!isset($config['modal_id']) || $config['modal_id'] === '') {
            $config['modal_id'] = 'media-modal';
        }
        
        // 验证配置
        $config['max_images'] = max(1, intval($config['max_images']));
        $config['multiple'] = (bool) $config['multiple'];
        $config['sortable'] = (bool) $config['sortable'];
        $config['show_preview'] = (bool) $config['show_preview'];
        
        // 确保current_images是数组
        if (!is_array($config['current_images'])) {
            $config['current_images'] = array();
        }
        
        return $config;
    }
}

/**
 * 特色图片HTML渲染器
 */
class FeaturedImagesRenderer {
    
    private $config;
    
    public function __construct($config) {
        $this->config = $config;
    }
    
    /**
     * 渲染主要组件HTML
     * 
     * @return string
     */
    public function renderComponent() {
        $component_id = $this->config['field_id'];
        $modal_id = isset($this->config['modal_id']) ? $this->config['modal_id'] : ($this->config['field_id'] . '-modal');
        
        ob_start();
        ?>
        <?php $extra_class = ($this->config['variant'] === 'universal') ? ' universal-images-component' : ''; ?>
        <?php echo $this->renderHiddenField(); ?>
        <div class="featured-images-component<?php echo $extra_class; ?>" 
             id="<?php echo esc_attr($component_id); ?>"
             data-field-name="<?php echo esc_attr($this->config['field_name']); ?>"
             data-multiple="<?php echo $this->config['multiple'] ? 'true' : 'false'; ?>"
             data-max-images="<?php echo esc_attr($this->config['max_images']); ?>"
             data-current-images='<?php echo esc_attr(json_encode($this->config['current_images'])); ?>'
             data-file-type="<?php echo esc_attr($this->config['file_type']); ?>"
             data-variant="<?php echo esc_attr($this->config['variant']); ?>"
             data-enable-debug="<?php echo $this->config['enable_debug'] ? 'true' : 'false'; ?>">
            <?php echo $this->renderPreviewArea(); ?>
            <?php echo $this->renderActions($modal_id); ?>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * 渲染隐藏字段
     * 
     * @return string
     */
    private function renderHiddenField() {
        $field_id = $this->config['field_id'];
        return sprintf(
            '<input type="hidden" name="%s" id="%s_data" value="%s" />',
            esc_attr($this->config['field_name']),
            esc_attr($field_id),
            esc_attr(json_encode($this->config['current_images']))
        );
    }
    
    /**
     * 渲染预览区域
     * 
     * @return string
     */
    private function renderPreviewArea() {
        $preview_id = $this->config['field_id'] . '-preview';
        $preview_class = ($this->config['variant'] === 'universal') ? 'universal-images-preview' : 'featured-images-preview';
        
        ob_start();
        ?>
        <div class="<?php echo esc_attr($preview_class); ?>" id="<?php echo esc_attr($preview_id); ?>">
            <?php if (!empty($this->config['current_images'])): ?>
                <?php foreach ($this->config['current_images'] as $index => $image): ?>
                    <?php echo $this->renderImageItem($image, $index); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * 渲染操作按钮区域
     * 
     * @param string $modal_id
     * @return string
     */
    private function renderActions($modal_id) {
        ob_start();
        $add_btn_extra_class = ($this->config['variant'] === 'universal') ? ' add-universal-image' : '';
        ?>
        <div class="featured-images-actions">
            <button type="button" 
                    class="button button-secondary add-featured-image<?php echo $add_btn_extra_class; ?>" 
                    data-modal-id="<?php echo esc_attr($modal_id); ?>"
                    data-field-id="<?php echo esc_attr($this->config['field_id']); ?>"
                    data-multiple="<?php echo $this->config['multiple'] ? 'true' : 'false'; ?>"
                    data-file-type="<?php echo esc_attr($this->config['file_type']); ?>"
                    data-max-images="<?php echo esc_attr($this->config['max_images']); ?>">
                <i class="toicon">✚</i>
            </button>
            
            <?php if ($this->config['multiple'] && $this->config['sortable'] && count($this->config['current_images']) > 1): ?>
            <p class="description">拖拽项目可调整顺序</p>
            <?php endif; ?>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * 渲染单个图片项
     * 
     * @param array $image 图片数据
     * @param int $index 索引
     * @return string
     */
    public function renderImageItem($image, $index) {
        $image_url = isset($image['url']) ? $image['url'] : '';
        $image_id = isset($image['id']) ? $image['id'] : '';
        $image_title = isset($image['title']) ? $image['title'] : '';
        $image_alt = isset($image['alt']) ? $image['alt'] : '';
        $display_url = $image_url;
        if ($display_url && function_exists('todo_media_url_to_absolute')) {
            $display_url = todo_media_url_to_absolute($display_url);
        }

        $image_seo_keywords = isset($image['seo_keywords']) ? trim((string)$image['seo_keywords']) : (isset($image['keywords']) ? trim((string)$image['keywords']) : '');
        if ($image_seo_keywords === '' && !empty($image_id) && function_exists('todo_get_media_seo_keywords_by_id')) {
            $image_seo_keywords = todo_get_media_seo_keywords_by_id($image_id);
        }

        $clean_image_title = (string)$image_title;
        if (!empty($image_seo_keywords)) {
            $kw_tags = array_filter(array_map('trim', preg_split('/[\s,]+/', strtolower($image_seo_keywords))));
            foreach ($kw_tags as $tag) {
                if (!empty($tag)) {
                    while (stripos($clean_image_title, $tag) === 0) {
                        $clean_image_title = substr($clean_image_title, strlen($tag));
                    }
                }
            }
        }

        $is_universal = ($this->config['variant'] === 'universal');
        $item_class = $is_universal ? 'universal-image-item' : 'featured-image-item';
        $preview_class = $is_universal ? 'universal-image-preview' : 'featured-image-preview';
        $actions_class = $is_universal ? 'universal-image-actions' : 'featured-image-actions';
        $title_class = $is_universal ? 'universal-image-title' : 'featured-image-title';
        $placeholder_class = $is_universal ? 'universal-image-placeholder' : 'featured-image-placeholder';
        
        ob_start();
        ?>
        <div class="<?php echo esc_attr($item_class); ?>" data-index="<?php echo esc_attr($index); ?>" data-id="<?php echo esc_attr($image_id); ?>" data-url="<?php echo esc_attr($image_url); ?>" data-seo-keywords="<?php echo esc_attr($image_seo_keywords); ?>">
            <div class="<?php echo esc_attr($preview_class); ?>">
                <?php 
                    $mime = isset($image['mime']) ? trim((string)$image['mime']) : (isset($image['mime_type']) ? trim((string)$image['mime_type']) : '');
                    $type = isset($image['type']) ? strtolower(trim((string)$image['type'])) : '';
                    $url_path = $image_url ? (parse_url($image_url, PHP_URL_PATH) ?: $image_url) : '';
                    $ext = $url_path ? strtolower(pathinfo($url_path, PATHINFO_EXTENSION)) : '';
                    $by_type_image = in_array($type, ['image','img','picture'], true);
                    $by_type_video = in_array($type, ['video','vid','movie'], true);
                    $by_type_audio = in_array($type, ['audio','music','sound'], true);
                    $is_image = $by_type_image
                        || (is_string($mime) && strpos($mime, 'image/') === 0)
                        || in_array($ext, ['jpg','jpeg','png','gif','webp','svg','ico','avif','heic','heif'], true);
                    $is_video = $by_type_video
                        || (is_string($mime) && strpos($mime, 'video/') === 0)
                        || in_array($ext, ['mp4','m4v','mov','avi','webm','mkv','flv','wmv','3gp','ts','m3u8'], true);
                    $is_audio = $by_type_audio
                        || (is_string($mime) && strpos($mime, 'audio/') === 0)
                        || in_array($ext, ['mp3','m4a','aac','wav','ogg','flac','wma'], true);
                    $icon_class = 'universal-file-icon';
                ?>
                <?php if ($image_url && $is_image): ?>
                    <img src="<?php echo esc_url($display_url); ?>" 
                         alt="<?php echo esc_attr($image_alt); ?>" 
                         title="<?php echo esc_attr($url_path); ?>" />
                <?php else: ?>
                    <?php 
                        $type_class = $is_video ? ' video' : ($is_audio ? ' audio' : ' document');
                        $icon = $is_video ? '🎬' : ($is_audio ? '🎵' : '📄');
                    ?>
                    <div class="<?php echo esc_attr($icon_class . $type_class); ?>">
                        <?php echo $icon; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="<?php echo esc_attr($actions_class); ?>">
                <button type="button" class="button remove-featured-image remove-universal-image" 
                        data-index="<?php echo esc_attr($index); ?>" 
                        title="移除"
                        data-handler-bound="1">
                    x
                </button>
            </div>
            
            <div class="featured-image-info universal-image-info">
                <div class="<?php echo esc_attr($title_class); ?>">
                    <?php if (!empty($image_seo_keywords)): ?><span class="media-seo-kw-badge"><?php echo esc_html($image_seo_keywords); ?></span><?php endif; ?><span class="image-title-text"><?php echo esc_html($clean_image_title); ?></span>
                </div>
            </div>
        </div>

        <?php
        
        return ob_get_clean();
    }
}

/**
 * 特色图片资源管理器
 */
class FeaturedImagesAssets {
    
    private static $assets_loaded = false;
    
    /**
     * 获取JavaScript代码
     * 
     * @return string
     */
    public static function getJavaScript() {
        // 使用安全的版本获取逻辑，避免未定义常量导致错误
        $ver = defined('TODO_VERSION') ? TODO_VERSION : '1.0.0';
        $js_url = todo_admin_url('assets/js/featured-images-component.js?v=' . rawurlencode($ver));
        return '<script src="' . $js_url . '" type="text/javascript"></script>';
    }
    
    /**
     * 渲染备用脚本（当主脚本不可用时）
     * @return string
     */
    private function renderFallbackScript() {
        return '
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // 检查独立JS文件是否加载成功
            if (typeof window.FeaturedImagesComponent === "undefined") {
                console.error("特色图像组件JS文件加载失败，请检查文件路径：" + "' . todo_admin_url('assets/js/featured-images-component.js') . '");
                TodoCMS.Utils.showMessage("特色图像组件加载失败，请刷新页面重试或联系管理员。", "error");
            }
        });
        </script>
        ';
    }
    
    /**
     * 渲染所有资源
     * 
     * @return string
     */
    public static function render() {
        self::$assets_loaded = true;
        
        return self::getJavaScript();
    }
}

/**
 * 特色图像组件主控制器
 */
class TodoFeaturedImagesComponent {
    
    private $config;
    private $renderer;

    private static $rendered_modal_ids = array();
    
    /**
     * 构造函数
     * 
     * @param array $options 配置选项
     */
    public function __construct($options = array()) {
        $this->config = FeaturedImagesConfig::merge($options);
        $this->renderer = new FeaturedImagesRenderer($this->config);
    }
    
    /**
     * 渲染组件
     * 
     * @return string
     */
    public function render() {
        try {
            ob_start();
            
            // 渲染主要组件
            echo $this->renderer->renderComponent();
            
            // 确保媒体模态框（含selectMediaForField等全局函数）已渲染到页面
            $this->renderMediaModal();
            
            // 渲染资源文件（JS库）
            echo FeaturedImagesAssets::render();
            
            // 渲染实例特定的JavaScript初始化代码
            echo $this->renderInstanceScript();
            
            return ob_get_clean();
            
        } catch (Exception $e) {
            error_log('[ERROR] TodoFeaturedImagesComponent::render() failed: ' . $e->getMessage());
            return '<!-- ERROR: Featured images component render failed: ' . esc_html($e->getMessage()) . ' -->';
        }
    }
    
    /**
     * 只渲染HTML部分（不包含script标签）
     * 用于动态加载场景，避免script被JSON编码后无法执行
     * 
     * @return string
     */
    public function renderHTMLOnly() {
        try {
            ob_start();
            
            // 只渲染组件HTML部分（不包含script）
            echo $this->renderer->renderComponent();
            
            return ob_get_clean();
            
        } catch (Exception $e) {
            error_log('[ERROR] TodoFeaturedImagesComponent::renderHTMLOnly() failed: ' . $e->getMessage());
            return '<!-- ERROR: Featured images component HTML render failed: ' . esc_html($e->getMessage()) . ' -->';
        }
    }
    
    /**
     * 渲染媒体模态框
     */
    private function renderMediaModal() {
        try {
            if (class_exists('TodoMediaModal')) {
                $modal_id = isset($this->config['modal_id']) ? $this->config['modal_id'] : ($this->config['field_id'] . '-modal');
                if (isset(self::$rendered_modal_ids[$modal_id])) {
                    return;
                }
                self::$rendered_modal_ids[$modal_id] = true;
                ob_start();
                $modal_type = $this->config['file_type'] ?? 'any';
                if ($modal_type === 'any' || $modal_type === 'document' || $modal_type === 'archive') {
                    $modal_type = 'file';
                }
                $modal_title_map = array(
                    'image' => '选择图片',
                    'video' => '选择视频',
                    'audio' => '选择音频',
                    'file'  => '选择文件'
                );
                $modal_title = isset($modal_title_map[$modal_type]) ? $modal_title_map[$modal_type] : '选择文件';

                echo TodoMediaModal::render($modal_id, array(
                    'title' => $modal_title,
                    'multiple' => $this->config['multiple'],
                    'type' => $modal_type,
                    'modal_id' => $modal_id
                ));
                echo ob_get_clean();

                echo '<script type="text/javascript">jQuery(function($){var modalId=' . json_encode($modal_id) . ';var modal=document.getElementById(modalId);if(!modal)return;var dups=document.querySelectorAll("#"+modalId);if(dups&&dups.length>1){for(var i=1;i<dups.length;i++){if(dups[i]&&dups[i].parentNode){dups[i].parentNode.removeChild(dups[i]);}}modal=dups[0];}if(modal.parentNode!==document.body){document.body.appendChild(modal);}});</script>';
            } else {
                echo '<!-- WARNING: TodoMediaModal class not found -->';
            }
        } catch (Exception $e) {
            echo '<!-- ERROR: Media modal render failed: ' . esc_html($e->getMessage()) . ' -->';
        }
    }
    
    /**
     * 渲染实例特定的JavaScript初始化代码
     * 
     * @return string
     */
    private function renderInstanceScript() {
        ob_start();
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // 特色图像组件实例初始化
            if (typeof window.FeaturedImagesComponent !== "undefined") {
                // 初始化特色图片组件
                var featuredImagesConfig = {
                    fieldId: "<?php echo esc_js($this->config['field_id']); ?>",
                    fieldName: "<?php echo esc_js($this->config['field_name']); ?>",
                    multiple: <?php echo $this->config['multiple'] ? 'true' : 'false'; ?>,
                    maxImages: <?php echo intval($this->config['max_images']); ?>,
                    enableDebug: <?php echo $this->config['enable_debug'] ? 'true' : 'false'; ?>,
                    fileType: "<?php echo esc_js($this->config['file_type']); ?>",
                    variant: "<?php echo esc_js($this->config['variant']); ?>"
                };
                
                var featuredImagesComponent = new window.FeaturedImagesComponent(featuredImagesConfig);
                // 组件构造函数中已自动调用init()，无需重复调用
            } else {
                console.error("FeaturedImagesComponent未定义，请确保已正确加载featured-images-component.js文件");
            }
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * 静态渲染方法
     * 
     * @param array $options 配置选项
     * @return string
     */
    public static function renderStatic($options = array()) {
        $component = new self($options);
        return $component->render();
    }
    
    /**
     * 静态渲染方法（仅HTML）
     * 用于动态加载场景
     * 
     * @param array $options 配置选项
     * @return string
     */
    public static function renderStaticHTMLOnly($options = array()) {
        $component = new self($options);
        return $component->renderHTMLOnly();
    }
}

/**
 * 渲染特色图像组件的全局函数
 *
 * @param array $options 组件选项（可包含 current_images 或完整配置）
 * @param bool $html_only 是否只返回HTML（用于动态加载，不包含script标签）
 * @return string HTML内容
 */
function todo_render_featured_images_component($options = array(), $html_only = false) {
    // 如果第一个参数是数组且包含配置键，则直接使用
    // 否则假设它是current_images数组
    if (is_array($options) && (isset($options['field_id']) || isset($options['field_name']))) {
        // 这是完整的配置数组
        $config = $options;
    } else {
        // 向后兼容：第一个参数是current_images
        $config = array('current_images' => $options);
    }
    
    // 启用调试（如果需要）
    if (defined('TODO_DEBUG') && TODO_DEBUG) {
        $config['enable_debug'] = true;
    }
    
    try {
        $component = new TodoFeaturedImagesComponent($config);
        
        // 根据html_only参数选择渲染方法
        if ($html_only) {
            $result = $component->renderHTMLOnly();
        } else {
            $result = $component->render();
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log('[ERROR] todo_render_featured_images_component() failed: ' . $e->getMessage());
        return '<!-- ERROR: Featured images component failed: ' . esc_html($e->getMessage()) . ' -->';
    }
}
