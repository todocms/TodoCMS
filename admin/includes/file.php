<?php
/**
 * TodoCMS File Functions
 *
 * @package TodoCMS
 * @subpackage Administration
 */

// 防止直接访问（但允许AJAX请求）
if (!defined('ABSPATH') || (!defined('TODO_LOADED') && !defined('DOING_AJAX'))) {
    exit('Direct access denied');
}

/**
 * Validates a file name and path against an allowed set of rules.
 *
 * A return value of `1` means the file path contains directory traversal.
 *
 * A return value of `2` means the file path contains a Windows drive path.
 *
 * A return value of `3` means the file is not in the allowed files list.
 *
 * @since 1.0.0
 *
 * @param string $file          File path.
 * @param string[] $allowed_files Optional. Array of allowed files. Default empty array.
 * @return int 0 means nothing is wrong, greater than 0 means something was wrong.
 */
function validate_file($file, $allowed_files = array())
{
    // `../` on its own is not allowed:
    if ('../' === $file) {
        return 1;
    }

    // More than one occurrence of `../` is not allowed:
    if (preg_match_all('#\.\./#', $file, $matches, PREG_SET_ORDER) && (count($matches) > 1)) {
        return 1;
    }

    // `../` which does not occur at the end of the path is not allowed:
    if (false !== strpos($file, '../') && '../' !== mb_substr($file, -3, 3)) {
        return 1;
    }

    // Files not in the allowed file list are not allowed:
    if (!empty($allowed_files) && !in_array($file, $allowed_files, true)) {
        return 3;
    }

    // Absolute Windows drive paths are not allowed:
    if (':' === substr(str_replace('\\', '/', $file), 1, 1)) {
        return 2;
    }

    return 0;
}

/**
 * Validates a file name and path against an allowed set of rules for editing.
 *
 * @since 1.0.0
 *
 * @param string $file          File path.
 * @param string[] $allowed_files Optional. Array of allowed files. Default empty array.
 * @return string|null File path on success, null on failure.
 */
function validate_file_to_edit($file, $allowed_files = array())
{
    $code = validate_file($file, $allowed_files);

    if (!$code) {
        return $file;
    }

    switch ($code) {
        case 1:
            if (function_exists('todo_die')) {
                todo_die(__('Sorry, can\'t edit files with ".." in the name. If you are trying to edit a file in your TodoCMS home directory, you can just type the name of the file in.'));
            } else {
                die('Sorry, can\'t edit files with ".." in the name.');
            }
            break;

        case 2:
            if (function_exists('todo_die')) {
                todo_die(__('Sorry, can\'t call files with their real path.'));
            } else {
                die('Sorry, can\'t call files with their real path.');
            }
            break;

        case 3:
            if (function_exists('todo_die')) {
                todo_die(__('Sorry, that file cannot be edited.'));
            } else {
                die('Sorry, that file cannot be edited.');
            }
            break;
    }

    return null;
}

/**
 * Retrieve list of allowed mime types and file extensions.
 *
 * @since 1.0.0
 *
 * @return array Array of mime types keyed by the file extension regex corresponding to those types.
 */
function get_allowed_mime_types()
{
    static $mimes = false;

    if (!$mimes) {
        // Accepted MIME types are set here as PCRE unless provided.
        $mimes = array(
            // Image formats.
            'jpg|jpeg|jpe' => 'image/jpeg',
            'gif' => 'image/gif',
            'png' => 'image/png',
            'bmp' => 'image/bmp',
            'tiff|tif' => 'image/tiff',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'heic' => 'image/heic',
            // Video formats.
            'asf|asx' => 'video/x-ms-asf',
            'wmv' => 'video/x-ms-wmv',
            'wmx' => 'video/x-ms-wmx',
            'wm' => 'video/x-ms-wm',
            'avi' => 'video/avi',
            'divx' => 'video/divx',
            'flv' => 'video/x-flv',
            'mov|qt' => 'video/quicktime',
            'mpeg|mpg|mpe' => 'video/mpeg',
            'mp4|m4v' => 'video/mp4',
            'ogv' => 'video/ogg',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            '3gp|3gpp' => 'video/3gpp',
            '3g2|3gp2' => 'video/3gpp2',
            // Text formats.
            'txt|asc|c|cc|h|srt' => 'text/plain',
            'csv' => 'text/csv',
            'tsv' => 'text/tab-separated-values',
            'ics' => 'text/calendar',
            'rtx' => 'text/richtext',
            'css' => 'text/css',
            'htm|html' => 'text/html',
            'vtt' => 'text/vtt',
            'dfxp' => 'application/ttaf+xml',
            // Audio formats.
            'mp3|m4a|m4b' => 'audio/mpeg',
            'aac' => 'audio/aac',
            'ra|ram' => 'audio/x-realaudio',
            'wav' => 'audio/wav',
            'ogg|oga' => 'audio/ogg',
            'flac' => 'audio/flac',
            'mid|midi' => 'audio/midi',
            'wma' => 'audio/x-ms-wma',
            'wax' => 'audio/x-ms-wax',
            'mka' => 'audio/x-matroska',
            // Misc application formats.
            'rtf' => 'application/rtf',
            'js' => 'application/javascript',
            'pdf' => 'application/pdf',
            'swf' => 'application/x-shockwave-flash',
            'class' => 'application/java',
            'tar' => 'application/x-tar',
            'zip' => 'application/zip',
            'gz|gzip' => 'application/x-gzip',
            'rar' => 'application/rar',
            '7z' => 'application/x-7z-compressed',
            'exe' => 'application/x-msdownload',
            'psd' => 'application/octet-stream',
            'xcf' => 'application/octet-stream',
            // MS Office formats.
            'doc' => 'application/msword',
            'pot|pps|ppt' => 'application/vnd.ms-powerpoint',
            'wri' => 'application/vnd.ms-write',
            'xla|xls|xlt|xlw' => 'application/vnd.ms-excel',
            'mdb' => 'application/vnd.ms-access',
            'mpp' => 'application/vnd.ms-project',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'docm' => 'application/vnd.ms-word.document.macroEnabled.12',
            'dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
            'dotm' => 'application/vnd.ms-word.template.macroEnabled.12',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
            'xlsb' => 'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
            'xltx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
            'xltm' => 'application/vnd.ms-excel.template.macroEnabled.12',
            'xlam' => 'application/vnd.ms-excel.addin.macroEnabled.12',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'pptm' => 'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
            'ppsx' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
            'ppsm' => 'application/vnd.ms-powerpoint.slideshow.macroEnabled.12',
            'potx' => 'application/vnd.openxmlformats-officedocument.presentationml.template',
            'potm' => 'application/vnd.ms-powerpoint.template.macroEnabled.12',
            'ppam' => 'application/vnd.ms-powerpoint.addin.macroEnabled.12',
            'sldx' => 'application/vnd.openxmlformats-officedocument.presentationml.slide',
            'sldm' => 'application/vnd.ms-powerpoint.slide.macroEnabled.12',
            'onetoc|onetoc2|onetmp|onepkg' => 'application/onenote',
            'oxps' => 'application/oxps',
            'xps' => 'application/vnd.ms-xpsdocument',
            // OpenOffice formats.
            'odt' => 'application/vnd.oasis.opendocument.text',
            'odp' => 'application/vnd.oasis.opendocument.presentation',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'odg' => 'application/vnd.oasis.opendocument.graphics',
            'odc' => 'application/vnd.oasis.opendocument.chart',
            'odb' => 'application/vnd.oasis.opendocument.database',
            'odf' => 'application/vnd.oasis.opendocument.formula',
            // TodoCMS specific formats.
            'todo' => 'application/todo',
        );
    }

    /**
     * Filters the list of allowed mime types and file extensions.
     *
     * @since 1.0.0
     *
     * @param array $mimes Mime types keyed by the file extension regex corresponding
     *                     to those types.
     */
    if (function_exists('apply_filters')) {
        return apply_filters('upload_mimes', $mimes);
    }

    return $mimes;
}

/**
 * Checks if a file is an image.
 *
 * @since 1.0.0
 *
 * @param string $path File path to test if valid image.
 * @return bool True if valid image, false if not valid image.
 */
function file_is_valid_image($path)
{
    $size = @getimagesize($path);
    return !empty($size);
}

/**
 * Checks if a file is displayable in the browser.
 *
 * @since 1.0.0
 *
 * @param string $path File path to test.
 * @return bool True if the file is displayable, false otherwise.
 */
function file_is_displayable_image($path)
{
    $displayable_image_types = array(IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_BMP);

    $info = @getimagesize($path);
    if (empty($info)) {
        $result = false;
    } elseif (!in_array($info[2], $displayable_image_types, true)) {
        $result = false;
    } else {
        $result = true;
    }

    /**
     * Filters whether the current image is displayable in the browser.
     *
     * @since 1.0.0
     *
     * @param bool   $result Whether the image can be displayed. Default true.
     * @param string $path   Path to the image.
     */
    if (function_exists('apply_filters')) {
        return apply_filters('file_is_displayable_image', $result, $path);
    }

    return $result;
}

/**
 * Sanitizes a filename, replacing whitespace with dashes.
 * 
 * NOTE: This function is now defined in core/functions/media.php
 * This comment is kept for reference only.
 */
// function sanitize_file_name() - moved to core/functions/media.php