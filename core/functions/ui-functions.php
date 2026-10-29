<?php
/**
 * TodoCMS 表单和UI函数库
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问（但允许AJAX请求）
if (!defined('ABSPATH') && !defined('DOING_AJAX')) {
    exit('Direct access denied');
}

/**
 * 输出提交按钮
 *
 * @param string $text 按钮文本
 * @param string $type 按钮类型
 * @param string $name 按钮名称
 * @param bool $wrap 是否包装
 * @param array $other_attributes 其他属性
 * @return string
 */
if (!function_exists('submit_button')) {
    function submit_button($text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null)
    {
        if (null === $text) {
            $text = todo_translate('Save Changes');
        }

        $class = 'button';
        if ($type) {
            $class .= ' button-' . $type;
        }

        $attributes = '';
        if (is_array($other_attributes)) {
            foreach ($other_attributes as $attribute => $value) {
                $attributes .= ' ' . $attribute . '="' . todo_esc_attr($value) . '"';
            }
        }

        $button = sprintf(
            '<input type="submit" name="%s" id="%s" class="%s" value="%s"%s />',
            esc_attr($name),
            esc_attr($name),
            esc_attr($class),
            esc_attr($text),
            $attributes
        );

        if ($wrap) {
            $button = '<p class="submit">' . $button . '</p>';
        }

        echo $button;
        return $button;
    }
}

/**
 * 获取登录URL
 *
 * @param string $redirect 重定向URL
 * @return string
 */
if (!function_exists('todo_login_url')) {
    function todo_login_url($redirect = '')
    {
        if (function_exists('todo_get_login_url')) {
            return todo_get_login_url($redirect);
        }

        $login_url = function_exists('todo_get_site_url') ? todo_get_site_url('/login/') : home_url('/login/');

        if (!empty($redirect)) {
            $login_url = add_query_arg('redirect_to', urlencode($redirect), $login_url);
        }

        if (function_exists('get_current_language') && function_exists('get_language_url')) {
            $current_lang = get_current_language();
            if (!empty($current_lang)) {
                $login_url = get_language_url($current_lang, $login_url);
            }
        }

        return $login_url;
    }
}

/**
 * 获取附件图片
 *
 * @param int $attachment_id 附件ID
 * @param string $size 图片尺寸
 * @param bool $icon 是否显示图标
 * @param array $attr 属性
 * @return string
 */
if (!function_exists('todo_get_attachment_image')) {
    function todo_get_attachment_image($attachment_id, $size = 'thumbnail', $icon = false, $attr = '')
    {
        global $tododb;

        if (!$tododb) {
            return '';
        }

        $attachment = $tododb->get_row(
            "SELECT * FROM {$tododb->prefix}posts WHERE ID = ? AND post_type = 'attachment'",
            array($attachment_id)
        );

        if (!$attachment) {
            return '';
        }

        $src = todo_get_attachment_image_url($attachment_id, $size);
        if (!$src) {
            return '';
        }

        $default_attr = array(
            'src' => $src,
            'class' => "attachment-$size size-$size",
            'alt' => trim(strip_tags($attachment->post_title)),
        );

        if (is_array($attr)) {
            $attr = array_merge($default_attr, $attr);
        } else {
            $attr = $default_attr;
        }

        $html = '<img';
        foreach ($attr as $name => $value) {
            $html .= " $name=" . '"' . esc_attr($value) . '"';
        }
        $html .= ' />';

        return $html;
    }
}

/**
 * 获取附件图片URL
 *
 * @param int $attachment_id 附件ID
 * @param string $size 图片尺寸
 * @return string
 */
if (!function_exists('todo_get_attachment_image_url')) {
    function todo_get_attachment_image_url($attachment_id, $size = 'thumbnail')
    {
        global $tododb;

        if (!$tododb) {
            return '';
        }

        $file = get_attached_file($attachment_id);
        if (!$file) {
            return '';
        }

        $upload_dir = todo_upload_dir(false);
        $file_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $file);

        return $file_url;
    }
}