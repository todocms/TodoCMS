<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../');
}

/**
 * 推荐标签组件
 *
 * @package TodoCMS
 * @version 1.1.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

/**
 * 渲染推荐标签组件
 *
 * @param int $limit 显示标签数量限制，默认10个
 * @param string $input_id 关联的标签输入框ID，默认'post-tags'
 */
function render_suggested_tags_component($limit = 10, $input_id = 'post-tags') {
    // 获取推荐标签
    $tags_manager = new CMS_Tags_Manager();
    $suggested_tags = $tags_manager->get_suggested_tags($limit);
    
    ?>
    <div class="suggested-tags-container" id="container-<?php echo esc_attr($input_id); ?>">
        <!-- 动态搜索结果容器 -->
        <div class="dynamic-tag-search-wrapper" id="search-results-<?php echo esc_attr($input_id); ?>" style="display: none;">
            <p class="suggested-tags-label">相似标签：</p>
            <div class="dynamic-search-results"></div>
        </div>

        <?php if (!empty($suggested_tags)): ?>
        <div class="suggested-tags-wrapper">
            <p class="suggested-tags-label">推荐标签：</p>
            <div class="suggested-tags">
                <?php foreach ($suggested_tags as $tag): ?>
                    <?php 
                    $post_count = isset($tag['post_count']) ? intval($tag['post_count']) : 0;
                    $raw_title = isset($tag['tag_title']) ? $tag['tag_title'] : (isset($tag['name']) ? $tag['name'] : '');
                    $clean_title = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($raw_title) : $raw_title;
                    $clean_title = trim(preg_replace('/\[[a-z]{2}(?:-[a-z]{2})?\|.*?\]/is', '', $clean_title));
                    $clean_title = trim(preg_replace('/\[:[a-z]{2}(?:-[a-z]{2})?\].*?\[:\]/is', '', $clean_title));
                    $clean_title = $clean_title ?: $raw_title;

                    $display_text = $clean_title;
                    $display_count = '';
                    if ($post_count > 0) {
                        $display_count = '<em>' . $post_count . '</em>';
                    }
                    ?>
                    <span class="suggested-tag" data-tag="<?php echo esc_attr($clean_title); ?>" data-input-id="<?php echo esc_attr($input_id); ?>">
                        <?php echo esc_html($display_text) . $display_count; ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <style>
    .dynamic-tag-search-wrapper {
        margin-top: 5px;
        padding: 5px 10px;
        background: #f0f7fa;
        border: 1px dashed #bcd5e0;
        border-radius: 4px;
    }
    
    .suggested-tags-wrapper {
        padding-top: 5px;
    }
    
    .suggested-tags-label {
        margin: 0 0 5px 0;
        font-size: 12px;
    }
    
    .suggested-tags, .dynamic-search-results {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    
    .suggested-tag, .search-result-tag {
        display: inline-flex;
        align-items: center;
        flex-wrap: wrap;
        word-break: break-all;
        padding: 2px 8px;
        background-color: #fff;
        border: 1px solid #ddd;
        border-radius: 3px;
        font-size: 12px;
        color: #444;
        cursor: pointer;
        transition: all 0.2s ease;
        user-select: none;
    }

    .suggested-tag em {
        opacity: 0.5;
        font-size: 11px;
        font-style: normal;
        margin: 0 -4px 0 3px;
    }
    
    .search-result-tag {
        background-color: #e3f2fd;
        border-color: #bbdefb;
        color: #0d47a1;
    }
    
    .suggested-tag:hover, .search-result-tag:hover {
        background-color: #eee;
        border-color: #ccc;
        color: #000;
    }
    
    .search-result-tag:hover {
        border-color: #bbdefb;
        background-color: #bbdefb;
    }
    
    .suggested-tag.selected {
        background-color: #0073aa;
        border-color: #0073aa;
        color: #fff;
    }
    </style>
    
    <script type="text/javascript">
    (function() {
        var inputId = '<?php echo esc_js($input_id); ?>';
        var searchTimer = null;
        
        function initTagComponent() {
            var tagInput = document.getElementById(inputId);
            if (!tagInput) return;
            
            var resultsWrapper = document.getElementById('search-results-' + inputId);
            var resultsContainer = resultsWrapper.querySelector('.dynamic-search-results');
            
            // 1. 处理推荐标签点击
            document.querySelectorAll('#container-' + inputId + ' .suggested-tag').forEach(function(el) {
                el.onclick = function() {
                    toggleTag(tagInput, this.getAttribute('data-tag'), this);
                };
            });
            
            // 2. 初始化选中状态
            updateSelectedStates(tagInput);
            
            // 3. 动态搜索逻辑
            tagInput.addEventListener('input', function() {
                clearTimeout(searchTimer);
                var value = this.value;
                
                // 获取光标位置之后最后一个逗号之后的内容
                var cursorPos = this.selectionStart;
                var textBeforeCursor = value.substring(0, cursorPos);
                var parts = textBeforeCursor.split(/[,，]/);
                var currentQuery = parts[parts.length - 1].trim();
                
                if (currentQuery.length >= 1) {
                    searchTimer = setTimeout(function() {
                        performSearch(currentQuery, resultsWrapper, resultsContainer, tagInput);
                    }, 500); // 停滞 500ms 自动搜索
                } else {
                    resultsWrapper.style.display = 'none';
                }
            });
        }
        
        function performSearch(query, wrapper, container, tagInput) {
            var formData = new FormData();
            formData.append('action', 'search_tags');
            formData.append('q', query);
            
            fetch('admin-ajax.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.tags && data.tags.length > 0) {
                    container.innerHTML = '';
                    data.tags.forEach(tag => {
                        var span = document.createElement('span');
                        span.className = 'search-result-tag';
                        span.textContent = tag.name;
                        span.onclick = function() {
                            applySearchResult(tagInput, tag.name);
                            wrapper.style.display = 'none';
                        };
                        container.appendChild(span);
                    });
                    wrapper.style.display = 'block';
                } else {
                    wrapper.style.display = 'none';
                }
            })
            .catch(err => {
                console.error('Tag search failed:', err);
                wrapper.style.display = 'none';
            });
        }
        
        function applySearchResult(input, tagName) {
            var value = input.value;
            var cursorPos = input.selectionStart;
            var textBefore = value.substring(0, cursorPos);
            var textAfter = value.substring(cursorPos);
            
            // 找到光标前最后一个逗号的位置
            var lastCommaIndex = Math.max(textBefore.lastIndexOf(','), textBefore.lastIndexOf('，'));
            
            var newValue = '';
            if (lastCommaIndex === -1) {
                newValue = tagName + ', ' + textAfter;
            } else {
                newValue = textBefore.substring(0, lastCommaIndex + 1) + ' ' + tagName + ', ' + textAfter;
            }
            
            input.value = cleanTags(newValue);
            input.focus();
            updateSelectedStates(input);
        }
        
        function toggleTag(input, tagName, el) {
            var tags = input.value.split(/[,，]/).map(t => t.trim()).filter(t => t !== "");
            var index = tags.indexOf(tagName);
            
            if (index > -1) {
                tags.splice(index, 1);
                if (el) el.classList.remove('selected');
            } else {
                tags.push(tagName);
                if (el) el.classList.add('selected');
            }
            
            input.value = tags.join(', ');
            var event = new Event('change', { bubbles: true });
            input.dispatchEvent(event);
        }
        
        function updateSelectedStates(input) {
            var tags = input.value.split(/[,，]/).map(t => t.trim().toLowerCase()).filter(t => t !== "");
            document.querySelectorAll('#container-' + inputId + ' .suggested-tag').forEach(function(el) {
                var tagName = el.getAttribute('data-tag').toLowerCase();
                if (tags.includes(tagName)) {
                    el.classList.add('selected');
                } else {
                    el.classList.remove('selected');
                }
            });
        }
        
        function cleanTags(str) {
            return str.split(/[,，]/)
                .map(t => t.trim())
                .filter((t, i, a) => t !== "" && a.indexOf(t) === i)
                .join(', ');
        }
        
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initTagComponent);
        } else {
            initTagComponent();
        }
    })();
    </script>
    <?php
}