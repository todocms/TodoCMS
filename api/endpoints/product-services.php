<?php
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

function api_get_product_service_items($params)
{
    if (!function_exists('todo_shop_get_service_settings')) {
        if (class_exists('Todo_Function_Loader')) {
            Todo_Function_Loader::load_function('shop-service-settings');
        } else {
            $p = defined('ABSPATH') ? ABSPATH . 'core/functions/shop-service-settings.php' : '';
            if ($p && file_exists($p)) {
                require_once $p;
            }
        }
    }
    $settings = function_exists('todo_shop_get_service_settings') ? todo_shop_get_service_settings() : array();
    $items = isset($settings['product_service_items']) && is_array($settings['product_service_items']) ? $settings['product_service_items'] : array();

    $normalized = [];
    $seen = [];

    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $code = isset($it['code']) ? todo_sanitize_key($it['code']) : '';
        $label = isset($it['label']) ? sanitize_text_field($it['label']) : '';
        if ($code === '' || $label === '' || isset($seen[$code])) {
            continue;
        }
        $seen[$code] = true;

        $images = [];
        if (isset($it['images'])) {
            $raw = $it['images'];
            $decoded = null;
            if (is_string($raw)) {
                $trim = trim($raw);
                if ($trim !== '' && ($trim[0] === '[' || $trim[0] === '{')) {
                    $decoded = json_decode($trim, true);
                }
            } elseif (is_array($raw)) {
                $decoded = $raw;
            }
            if (is_array($decoded)) {
                foreach ($decoded as $img) {
                    $url = '';
                    if (is_array($img) && isset($img['url'])) {
                        $url = (string) $img['url'];
                    } elseif (is_string($img)) {
                        $url = $img;
                    }
                    $url = trim($url);
                    if ($url === '') {
                        continue;
                    }
                    if (function_exists('api_normalize_media_url')) {
                        $url = (string) api_normalize_media_url($url);
                    }
                    if ($url !== '' && !in_array($url, $images, true)) {
                        $images[] = $url;
                    }
                }
            }
        }

        $normalized[] = [
            'code' => $code,
            'label' => $label,
            'images' => $images,
        ];
    }

    return [
        'items' => $normalized,
    ];
}

function api_get_product_service_item($params)
{
    $code = isset($params['id']) ? todo_sanitize_key($params['id']) : '';
    if ($code === '') {
        send_api_error(400, '缺少服务项 code');
    }

    $list = api_get_product_service_items([]);
    $items = isset($list['items']) && is_array($list['items']) ? $list['items'] : [];
    foreach ($items as $it) {
        if (is_array($it) && isset($it['code']) && $it['code'] === $code) {
            return $it;
        }
    }

    send_api_error(404, '服务项不存在');
}

register_api_route('product-services', 'GET', 'api_get_product_service_items');
register_api_route('product-services/([a-z0-9_-]+)', 'GET', 'api_get_product_service_item');
