<?php
/**
 * 多语言支持函数
 *
 * 提供多语言支持的核心函数，包括文本翻译、语言切换和本地化功能。
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 加载 LocaleContext
$locale_class = ABSPATH . 'core/classes/class-locale-context.php';
if (file_exists($locale_class)) {
    require_once $locale_class;
}

/**
 * 获取当前语言
 */
if (!function_exists('get_current_language')) {
    function get_current_language()
    {
        return str_replace('-', '_', LocaleContext::get_instance()->get_locale());
    }
}

/**
 * 获取可用语言列表
 */
if (!function_exists('get_available_languages')) {
    function get_available_languages()
    {
        // 避免递归调用，如果 language.php 已加载，则不应进入此处
        // 如果未加载，则返回默认值
        if (class_exists('LanguageRegistry')) {
            return LanguageRegistry::get_instance()->get_languages_hierarchical(true);
        }
        return array('zh-CN' => array('name' => '简体中文', 'native_name' => '简体中文', 'lang_code' => 'zh-cn', 'flag' => '', 'default' => true));
    }
}

/**
 * 获取语言信息
 *
 * @param string $language_code 语言代码
 * @return array|false 语言信息，如果语言不存在则返回false
 */
function get_language_info($language_code)
{
    $available_languages = get_available_languages();

    if (isset($available_languages[$language_code])) {
        return $available_languages[$language_code];
    }

    return false;
}

/**
 * 获取默认语言
 */
if (!function_exists('get_default_language')) {
    function get_default_language()
    {
        return LanguageRegistry::get_instance()->get_default_locale();
    }
}

/**
 * 翻译文本
 *
 * @param string $text 要翻译的文本
 * @param string $domain 文本域
 * @return string 翻译后的文本
 */
if (!function_exists('__')) {
    function __($text, $domain = 'default')
    {
        return CMS_Translation::translate($text, $domain);
    }
}

/**
 * 翻译文本并输出
 *
 * @param string $text 要翻译的文本
 * @param string $domain 文本域
 */
if (!function_exists('_e')) {
    function _e($text, $domain = 'default')
    {
        echo __($text, $domain);
    }
}

/**
 * 翻译文本并进行HTML转义
 *
 * @param string $text 要翻译的文本
 * @param string $domain 文本域
 * @return string 翻译并转义后的文本
 */
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default')
    {
        return esc_html(__($text, $domain));
    }
}

/**
 * 翻译文本，进行HTML转义并输出
 *
 * @param string $text 要翻译的文本
 * @param string $domain 文本域
 */
if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = 'default')
    {
        echo esc_html__($text, $domain);
    }
}

/**
 * 翻译文本并进行属性转义
 *
 * @param string $text 要翻译的文本
 * @param string $domain 文本域
 * @return string 翻译并转义后的文本
 */
if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = 'default')
    {
        return esc_attr(__($text, $domain));
    }
}

/**
 * 翻译文本，进行属性转义并输出
 *
 * @param string $text 要翻译的文本
 * @param string $domain 文本域
 */
if (!function_exists('esc_attr_e')) {
    function esc_attr_e($text, $domain = 'default')
    {
        echo esc_attr__($text, $domain);
    }
}

/**
 * 翻译文本（带上下文）
 *
 * @param string $text 要翻译的文本
 * @param string $context 上下文
 * @param string $domain 文本域
 * @return string 翻译后的文本
 */
if (!function_exists('_x')) {
    function _x($text, $context, $domain = 'default')
    {
        return CMS_Translation::translate_with_context($text, $context, $domain);
    }
}

/**
 * 翻译文本（带上下文）并进行HTML转义
 *
 * @param string $context 上下文
 * @param string $text 要翻译的文本
 * @param string $domain 文本域
 * @return string 翻译并转义后的文本
 */
if (!function_exists('esc_html_x')) {
    function esc_html_x($context, $text, $domain = 'default')
    {
        return esc_html(_x($text, $context, $domain));
    }
}

/**
 * 翻译文本（带上下文）并进行属性转义
 *
 * @param string $context 上下文
 * @param string $text 要翻译的文本
 * @param string $domain 文本域
 * @return string 翻译并转义后的文本
 */
if (!function_exists('esc_attr_x')) {
    function esc_attr_x($context, $text, $domain = 'default')
    {
        return esc_attr(_x($text, $context, $domain));
    }
}

/**
 * 翻译单复数形式
 *
 * @param string $single 单数形式
 * @param string $plural 复数形式
 * @param int $number 数量
 * @param string $domain 文本域
 * @return string 翻译后的文本
 */
if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = 'default')
    {
        return CMS_Translation::translate_plural($single, $plural, $number, $domain);
    }
}

/**
 * 翻译单复数形式并进行HTML转义
 *
 * @param string $single 单数形式
 * @param string $plural 复数形式
 * @param int $number 数量
 * @param string $domain 文本域
 * @return string 翻译并转义后的文本
 */
if (!function_exists('esc_html_n')) {
    function esc_html_n($single, $plural, $number, $domain = 'default')
    {
        return esc_html(_n($single, $plural, $number, $domain));
    }
}

/**
 * 翻译单复数形式（带上下文）
 *
 * @param string $single 单数形式
 * @param string $plural 复数形式
 * @param int $number 数量
 * @param string $context 上下文
 * @param string $domain 文本域
 * @return string 翻译后的文本
 */
if (!function_exists('_nx')) {
    function _nx($single, $plural, $number, $context, $domain = 'default')
    {
        return CMS_Translation::translate_plural_with_context($single, $plural, $number, $context, $domain);
    }
}

/**
 * 加载默认文本域
 *
 * @param string $locale 语言区域设置
 * @return bool 是否成功加载
 */
if (!function_exists('load_default_textdomain')) {
    function load_default_textdomain($locale = '')
    {
        if (empty($locale)) {
            $locale = get_current_language();
        }

        $path = ABSPATH . 'assets/languages';

        return CMS_Translation::load_textdomain('default', $path, $locale);
    }
}

/**
 * 加载主题文本域
 *
 * @param string $domain 文本域
 * @param string $theme_path 主题路径
 * @return bool 是否成功加载
 */
if (!function_exists('load_theme_textdomain')) {
    function load_theme_textdomain($domain, $theme_path)
    {
        $locale = get_current_language();
        $path = $theme_path . '/languages';

        return CMS_Translation::load_textdomain($domain, $path, $locale);
    }
}

/**
 * 获取语言HTML属性
 *
 * @return string HTML语言属性
 */
if (!function_exists('language_attributes')) {
    function language_attributes()
    {
        $locale = get_current_language();
        $lang = str_replace('_', '-', $locale);

        $output = 'lang="' . esc_attr($lang) . '"';

        // 如果是RTL语言，添加dir属性
        if (is_rtl()) {
            $output .= ' dir="rtl"';
        }

        return $output;
    }
}

/**
 * 检查是否是RTL语言
 *
 * @return bool 是否是RTL语言
 */
if (!function_exists('is_rtl')) {
    function is_rtl()
    {
        $locale = get_current_language();
        $rtl_locales = array('ar', 'fa', 'he', 'ur');

        foreach ($rtl_locales as $rtl_locale) {
            if (strpos($locale, $rtl_locale) === 0) {
                return true;
            }
        }

        return false;
    }
}

/**
 * 根据本地化设置格式化日期
 *
 * @param string $format 日期格式
 * @param int $timestamp 时间戳
 * @param bool $gmt 是否使用GMT时间
 * @return string 格式化后的日期
 */
if (!function_exists('date_i18n')) {
    function date_i18n($format, $timestamp = null, $gmt = false)
    {
        if ($timestamp === null) {
            $timestamp = time();
        }

        if ($gmt) {
            $timestamp += get_option('gmt_offset') * HOUR_IN_SECONDS;
        }

        $locale = get_current_language();
        $original_locale = setlocale(LC_TIME, 0);

        // 尝试设置区域设置
        $set_locale = setlocale(LC_TIME, $locale . '.UTF-8', $locale);

        // 格式化日期
        $date = date($format, $timestamp);

        // 恢复原始区域设置
        setlocale(LC_TIME, $original_locale);

        return $date;
    }
}

/**
 * 根据本地化设置格式化数字
 *
 * @param float $number 要格式化的数字
 * @param int $decimals 小数位数
 * @return string 格式化后的数字
 */
if (!function_exists('number_format_i18n')) {
    function number_format_i18n($number, $decimals = 0)
    {
        $locale = get_current_language();
        $original_locale = setlocale(LC_NUMERIC, 0);

        // 尝试设置区域设置
        $set_locale = setlocale(LC_NUMERIC, $locale . '.UTF-8', $locale);

        // 获取区域设置信息
        $locale_info = localeconv();

        // 格式化数字
        $formatted = number_format($number, $decimals, $locale_info['decimal_point'], $locale_info['thousands_sep']);

        // 恢复原始区域设置
        setlocale(LC_NUMERIC, $original_locale);

        return $formatted;
    }
}

/**
 * 获取语言URL
 *
 * @param string $language_code 语言代码
 * @return string 语言URL
 */
// get_language_url 已移除，统一由 core/functions/language.php 提供最新实现。
// 请使用 LanguageRegistry + LocaleContext 的新架构，并调用 core/functions/language.php 中的 get_language_url。

/**
 * 从URL部分重建URL
 *
 * @param array $parts URL部分
 * @return string 重建的URL
 */
// build_url 已移除，统一由 core/functions/language.php 提供最新实现。
// 请使用 core/functions/language.php 中的 build_url，以保持统一的URL重建逻辑。

/**
 * 添加查询参数到URL
 *
 * @param string $key 参数名
 * @param string $value 参数值
 * @param string $url URL
 * @return string 添加参数后的URL
 */
// add_query_arg 已移除，统一由 core/functions/language.php 提供最新实现。

/**
 * 从URL中移除查询参数
 *
 * @param string $key 参数名
 * @param string $url URL
 * @return string 移除参数后的URL
 */
// remove_query_arg 已移除，统一由 core/functions/language.php 提供最新实现。

/**
 * 检查语言代码是否有效
 *
 * @param string $language_code 语言代码
 * @return bool 语言代码是否有效
 */
// is_valid_language 已移除，统一由 core/functions/language.php 提供最新实现（含 canonical_locale 标准化与注册校验）。
// current_locale 已移除，统一由 core/functions/language.php 提供最新实现（LocaleContext + 规范化）。
