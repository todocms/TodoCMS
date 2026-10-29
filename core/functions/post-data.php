<?php
/**
 * TodoCMS 全局Post数据管理函数
 *
 * 提供全局post数据管理功能，支持数据栈保存和恢复
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 全局post数据栈，用于保存和恢复post数据
 */
global $todocms_post_stack;
$todocms_post_stack = [];

/**
 * 重置全局post数据
 *
 * 恢复到查询前的全局post状态
 */
function todo_reset_postdata()
{
    global $post, $todocms_post_stack;

    if (!empty($todocms_post_stack)) {
        $post = array_pop($todocms_post_stack);
    } else {
        $post = null;
    }

    // 重置其他全局变量
    global $id, $authordata, $currentday, $currentmonth, $page, $pages, $multipage, $more, $numpages;

    if ($post && is_object($post)) {
        $id = (int) $post->ID;
    } else {
        $id = 0;
    }

    $authordata = null;
    $currentday = '';
    $currentmonth = '';
    $page = 1;
    $pages = [];
    $multipage = 0;
    $more = 1;
    $numpages = 1;
}

/**
 * 保存当前全局post数据到栈中
 *
 * 在开始新的查询循环前调用，以便后续恢复
 */
function todo_save_postdata()
{
    global $post, $todocms_post_stack;

    if (!is_array($todocms_post_stack)) {
        $todocms_post_stack = [];
    }

    array_push($todocms_post_stack, $post);
}

/**
 * 设置全局post数据
 *
 * @param object|array $post_data 文章数据
 */
function todo_setup_postdata($post_data)
{
    global $post, $id, $authordata, $currentday, $currentmonth, $page, $pages, $multipage, $more, $numpages;

    if (is_array($post_data)) {
        $post = (object) $post_data;
    } else {
        $post = $post_data;
    }

    if ($post && is_object($post)) {
        $id = (int) $post->ID;

        // 设置其他相关全局变量
        $currentday = '';
        $currentmonth = '';
        $page = 1;
        $pages = [];
        $multipage = 0;
        $more = 1;
        $numpages = 1;

        // 如果有内容分页，处理分页
        if (isset($post->post_content)) {
            $content = $post->post_content;
            if (strpos($content, '<!--nextpage-->') !== false) {
                $pages = explode('<!--nextpage-->', $content);
                $numpages = count($pages);
                $multipage = 1;
                if (!isset($_GET['page']) || $_GET['page'] < 1) {
                    $page = 1;
                } else {
                    $page = (int) $_GET['page'];
                    if ($page > $numpages) {
                        $page = $numpages;
                    }
                }
                $more = ($page < $numpages) ? 1 : 0;
            }
        }
    }
}

/**
 * 获取当前文章ID
 *
 * @return int 当前文章ID
 */
function todo_get_the_id()
{
    global $post;

    if ($post && is_object($post) && isset($post->ID)) {
        return (int) $post->ID;
    }

    return 0;
}

/**
 * 输出当前文章标题
 */
function todo_the_title()
{
    echo todo_get_the_title();
}

/**
 * 获取当前文章内容
 *
 * @param string $more_link_text 更多链接文本（可选，未使用但保持兼容性）
 * @param bool $strip_teaser 是否去除摘要（可选，未使用但保持兼容性）
 * @param int|null $post_id 文章ID（可选，未使用但保持兼容性）
 * @return string 文章内容
 */
function todo_get_the_content($more_link_text = '', $strip_teaser = false, $post_id = null)
{
    global $post, $page, $pages, $multipage, $more;

    $target_post = null;
    if ($post_id !== null && (int)$post_id > 0) {
        $target_post = get_post($post_id);
    }
    if (!$target_post) {
        $target_post = $post;
    }

    if (!$target_post || !is_object($target_post) || !isset($target_post->post_content)) {
        return '';
    }

    // 可见性优先（私密/密码保护），其后判定阅读权限（等级/管理员/公开）
    $access = todo_can_read_post($target_post);
    if (!$access['allowed']) {
        if (!empty($access['requires_password'])) {
            return todo_get_password_form_html(isset($target_post->ID) ? $target_post->ID : null);
        }
        return todo_get_permission_block_html($access['reason']);
    }

    // 内容：优先使用聚合函数翻译内容
    $content_source = null;
    if (isset($target_post->ID)) {
        // 允许 pending 状态的翻译在前端展示，避免编辑未审核导致不显示
        $agg = todo_get_post_multilang($target_post->ID, null, ['fallback' => true, 'status_allowed' => ['approved']]);
        if (is_array($agg) && isset($agg['content']) && $agg['content'] !== '') {
            $content_source = $agg['content'];
        }
    }
    $content = $content_source !== null ? $content_source : $target_post->post_content;

    // 处理分页内容（如果文章使用分页，则以分页内容优先）
    // 注意：当已命中翻译内容时，避免使用默认主稿的分页片段覆盖翻译内容。
    if ($content_source === null && $multipage && isset($pages[$page - 1])) {
        $content = $pages[$page - 1];
    }

    // 如果内容被实体编码（例如存储了 &lt;p&gt;），进行解码转换
    if (is_string($content) && $content !== '') {
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // 解析受信任的正文特殊模板标签（如 {{todo:cookie_table}}）
    if (is_string($content) && $content !== '' && strpos($content, '{{todo:') !== false) {
        $content = todo_parse_post_special_tags($content, $target_post);
    }

    // 应用内容过滤器
    $content = apply_filters('todo_the_content', $content);

    return $content;
}

/**
 * 解析文章正文中被允许的特殊模板标签
 * 仅允许在后台“欧盟 Cookie 合规设置 -> 正文特殊解析标签”中明确配置的标签被解析
 * 自动适配当前语言环境与多语言翻译，未配置的标签原样保留纯文本
 *
 * @param string $content 文章内容
 * @param object|null $target_post 当前文章对象
 * @return string 解析后的内容
 */
function todo_parse_post_special_tags($content, $target_post = null)
{
    if (!is_string($content) || $content === '' || strpos($content, '{{todo:') === false) {
        return $content;
    }

    $raw_allowed = function_exists('get_option') ? get_option('post_allowed_template_tags', '{{todo:cookie_table}}') : '{{todo:cookie_table}}';
    $raw_allowed = trim((string)$raw_allowed);
    if ($raw_allowed === '') {
        return $content;
    }

    // 分割并归一化允许的标签列表（严格要求以 {{todo: 开头）
    $items = preg_split('/[,，\r\n]+/', $raw_allowed);
    $allowed_tags = [];
    foreach ($items as $item) {
        $item = trim($item);
        if ($item === '') continue;
        // 严格匹配 {{todo:tagname}} 格式，不支持简写形式
        if (preg_match('/^\{\{todo:([a-zA-Z0-9_]+)(?:[^}]*)?\}\}$/', $item, $matches)) {
            $allowed_tags[] = strtolower($matches[1]);
        }
    }
    $allowed_tags = array_unique(array_filter($allowed_tags));

    if (empty($allowed_tags)) {
        return $content;
    }

    // 构建正文上下文数据供编译器使用
    $context_data = [];
    if (is_object($target_post)) {
        $context_data['post'] = $target_post;
        if (isset($target_post->ID)) {
            $context_data['post_id'] = $target_post->ID;
            $context_data['id'] = $target_post->ID;
        }
    }

    // 匹配并解析正文中的特定标签：{{todo:tag_name ...}}
    return preg_replace_callback('/\{\{todo:([a-zA-Z0-9_]+)([^}]*)\}\}/', function($m) use ($allowed_tags, $context_data) {
        $tag_name = strtolower($m[1]);
        if (!in_array($tag_name, $allowed_tags, true)) {
            // 未在白名单中配置的标签保持原样，不予执行
            return $m[0];
        }

        if (class_exists('CMS_Template_Compiler_V2')) {
            try {
                return CMS_Template_Compiler_V2::render_string($m[0], $context_data);
            } catch (\Throwable $e) {
                error_log('[TodoCMS] parse_post_special_tags error: ' . $e->getMessage());
                return $m[0];
            }
        }
        return $m[0];
    }, $content);
}

/**
 * 输出当前文章内容
 */
function todo_the_content()
{
    echo todo_get_the_content();
}

/**
 * 获取当前文章摘要
 *
 * @param int $length 摘要长度
 * @return string 文章摘要
 */
function todo_get_the_excerpt($length = 150, $post_obj = null)
{
    global $post;

    $current_post = $post;
    if ($post_obj !== null) {
        if (is_numeric($post_obj) && function_exists('get_post')) {
            $current_post = get_post($post_obj);
        } elseif (is_object($post_obj)) {
            $current_post = $post_obj;
        }
    }

    if (!$current_post || !is_object($current_post)) {
        return '';
    }

    // 优先使用聚合函数的翻译摘要
    if (isset($current_post->ID)) {
        // 允许 pending 状态的翻译在前端展示，避免编辑未审核导致不显示
        $agg = todo_get_post_multilang($current_post->ID, null, ['fallback' => true, 'status_allowed' => ['approved']]);
        if (is_array($agg) && isset($agg['excerpt']) && $agg['excerpt'] !== '') {
            $txt = html_entity_decode($agg['excerpt'], ENT_QUOTES, 'UTF-8');
            // 解析受信任的正文特殊模板标签（与 post_content 保持一致）
            if (strpos($txt, '{{todo:') !== false) {
                $txt = todo_parse_post_special_tags($txt, $current_post);
            }
            $txt = strip_tags($txt);
            if (function_exists('todo_parse_inline_translations')) {
                $txt = todo_parse_inline_translations($txt);
            }
            if (mb_strlen($txt) > $length) {
                return mb_substr($txt, 0, $length);
            }
            return $txt;
        }
    }

    // 如果有手动摘要，使用手动摘要
    if (isset($current_post->post_excerpt) && !empty($current_post->post_excerpt)) {
        $txt = html_entity_decode($current_post->post_excerpt, ENT_QUOTES, 'UTF-8');
        // 解析受信任的正文特殊模板标签（与 post_content 保持一致）
        if (strpos($txt, '{{todo:') !== false) {
            $txt = todo_parse_post_special_tags($txt, $current_post);
        }
        $txt = strip_tags($txt);
        if (function_exists('todo_parse_inline_translations')) {
            $txt = todo_parse_inline_translations($txt);
        }
        if (mb_strlen($txt) > $length) {
            return mb_substr($txt, 0, $length);
        }
        return $txt;
    }

    // 兼容对象中的别名字段 excerpt（部分渲染管线会填充）
    if (isset($current_post->excerpt) && !empty($current_post->excerpt)) {
        $txt = html_entity_decode($current_post->excerpt, ENT_QUOTES, 'UTF-8');
        // 解析受信任的正文特殊模板标签（与 post_content 保持一致）
        if (strpos($txt, '{{todo:') !== false) {
            $txt = todo_parse_post_special_tags($txt, $current_post);
        }
        $txt = strip_tags($txt);
        if (function_exists('todo_parse_inline_translations')) {
            $txt = todo_parse_inline_translations($txt);
        }
        if (mb_strlen($txt) > $length) {
            return mb_substr($txt, 0, $length);
        }
        return $txt;
    }

    // 否则从内容中生成摘要
    if (isset($current_post->post_content)) {
        $content = html_entity_decode($current_post->post_content, ENT_QUOTES, 'UTF-8');
        // 解析受信任的正文特殊模板标签（与 post_content 保持一致）
        if (strpos($content, '{{todo:') !== false) {
            $content = todo_parse_post_special_tags($content, $current_post);
        }
        $content = strip_tags($content);
        if (function_exists('todo_parse_inline_translations')) {
            $content = todo_parse_inline_translations($content);
        }
        if (mb_strlen($content) > $length) {
            return mb_substr($content, 0, $length);
        }
        return $content;
    }

    return '';
}

/**
 * 获取当前文章永久链接
 *
 * @return string 文章链接
 */
function todo_get_the_permalink()
{
    global $post;

    if (!$post || !is_object($post) || !isset($post->ID)) {
        return '';
    }

    // 使用统一封装的永久链接（由 todo_get_permalink 注入语言前缀）
    return esc_url(todo_get_permalink($post->ID));
}

/**
 * 输出当前文章永久链接
 */
function todo_the_permalink()
{
    echo todo_get_the_permalink();
}

/**
 * 检查当前文章是否有特色图片
 *
 * @return bool 是否有特色图片
 */
function todo_has_post_thumbnail($post_obj = null)
{
    global $post;

    $current_post = $post;
    if ($post_obj !== null) {
        if (is_numeric($post_obj) && function_exists('get_post')) {
            $current_post = get_post($post_obj);
        } elseif (is_object($post_obj)) {
            $current_post = $post_obj;
        }
    }

    if (!$current_post || !is_object($current_post) || !isset($current_post->ID)) {
        return false;
    }

    return !empty($current_post->featured_image);
}

/**
 * 获取当前文章特色图片
 *
 * @param string $size 图片尺寸
 * @param array $attr 图片属性
 * @return string 图片HTML
 */
function todo_get_the_post_thumbnail($size = 'medium', $attr = [])
{
    global $post;

    if (!$post || !is_object($post) || !isset($post->ID)) {
        return '';
    }

    if (empty($post->featured_image)) {
        return '';
    }

    $alt = isset($attr['alt']) ? $attr['alt'] : todo_get_the_title();
    $class = isset($attr['class']) ? $attr['class'] : '';

    return sprintf(
        '<img src="%s" alt="%s" class="%s">',
        esc_url($post->featured_image),
        esc_attr($alt),
        esc_attr($class)
    );
}

/**
 * 输出当前文章特色图片
 *
 * @param string $size 图片尺寸
 * @param array $attr 图片属性
 */
function todo_the_post_thumbnail($size = 'medium', $attr = [])
{
    echo todo_get_the_post_thumbnail($size, $attr);
}

/**
 * 获取附件URL
 *
 * @param int $attachment_id 附件ID
 * @return string 附件URL
 */
function todo_get_attachment_url($attachment_id)
{
    // 这里需要根据实际的媒体管理系统来实现
    // 暂时返回空字符串
    return '';
}
