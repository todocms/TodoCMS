<?php

require_once ABSPATH . 'core/functions/url-functions.php';
require_once ABSPATH . 'core/functions/term-model-functions.php';

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url)
    {
        if (!is_string($url)) {
            return '';
        }
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('/^\s*(javascript|data):/i', $url) === 1) {
            return '';
        }
        $sanitized = filter_var($url, FILTER_SANITIZE_URL);
        return is_string($sanitized) ? $sanitized : '';
    }
}

function api_normalize_media_url($url)
{
    if (!is_string($url)) {
        return false;
    }

    $url = trim($url);
    if ($url === '') {
        return false;
    }

    if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0 || strpos($url, '//') === 0) {
        return $url;
    }

    if (function_exists('todo_get_site_url')) {
        return todo_get_site_url(ltrim($url, '/'));
    }

    if (strpos($url, '/') === 0) {
        return $url;
    }

    return '/' . $url;
}

function api_get_featured_image($post_id)
{
    if (function_exists('get_post_featured_image')) {
        return api_normalize_media_url(get_post_featured_image($post_id));
    }

    global $tododb;
    if (!$post_id || !isset($tododb)) {
        return false;
    }

    $row = $tododb->get_row(
        "SELECT featured_image FROM " . TABLE_PREFIX . "posts WHERE ID = ?",
        [intval($post_id)],
        'ARRAY_A'
    );

    if (!$row || empty($row['featured_image'])) {
        return false;
    }

    $featured_image = $row['featured_image'];

    if (is_string($featured_image) && (strpos($featured_image, '[') === 0 || strpos($featured_image, '{') === 0)) {
        $decoded = json_decode($featured_image, true);
        if (is_array($decoded)) {
            if (isset($decoded[0])) {
                $first = $decoded[0];
                if (is_array($first) && isset($first['url'])) {
                    return api_normalize_media_url($first['url']);
                }
                if (is_string($first)) {
                    return api_normalize_media_url($first);
                }
            }
            if (isset($decoded['url'])) {
                return api_normalize_media_url($decoded['url']);
            }
        }
    }

    return api_normalize_media_url($featured_image);
}

function api_get_featured_images($post_id)
{
    global $tododb;
    $post_id = intval($post_id);
    if ($post_id <= 0 || !isset($tododb)) {
        return [];
    }

    $row = $tododb->get_row(
        "SELECT featured_image FROM " . TABLE_PREFIX . "posts WHERE ID = ?",
        [$post_id],
        'ARRAY_A'
    );
    if (!$row || empty($row['featured_image'])) {
        return [];
    }

    $featured_image = $row['featured_image'];
    if (!is_string($featured_image)) {
        return [];
    }
    $featured_image = trim($featured_image);
    if ($featured_image === '') {
        return [];
    }

    $urls = [];
    $push_url = function ($u) use (&$urls) {
        if (!is_string($u)) {
            return;
        }
        $u = trim($u);
        if ($u === '') {
            return;
        }
        $normalized = api_normalize_media_url($u);
        if (!$normalized) {
            return;
        }
        if (!in_array($normalized, $urls, true)) {
            $urls[] = $normalized;
        }
    };

    if ($featured_image[0] === '[' || $featured_image[0] === '{') {
        $decoded = json_decode($featured_image, true);
        if (is_array($decoded)) {
            $is_assoc = array_keys($decoded) !== range(0, count($decoded) - 1);
            if ($is_assoc) {
                if (isset($decoded['url'])) {
                    $push_url($decoded['url']);
                }
            } else {
                foreach ($decoded as $item) {
                    if (is_array($item) && isset($item['url'])) {
                        $push_url($item['url']);
                    } elseif (is_string($item)) {
                        $push_url($item);
                    }
                }
            }
        }
    } else {
        $push_url($featured_image);
    }

    return $urls;
}
