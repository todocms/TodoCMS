<?php
/**
 * @package TodoCMS
 * @version 1.0.0
 */

return [
    'value' => function ($attrs, $content, $data) {
        $name = isset($attrs['name']) ? (string) $attrs['name'] : '';
        if ($name === '') {
            return '';
        }
        if (is_array($data) && array_key_exists($name, $data)) {
            $v = $data[$name];
            if (is_string($v) || is_numeric($v) || is_bool($v)) {
                return (string) $v;
            }
            if (is_object($v) && method_exists($v, '__toString')) {
                return (string) $v;
            }
        }
        return '';
    },
    'user_default_address_text' => function ($attrs, $content, $data) {
        static $cache = [];

        $uid = 0;
        if (function_exists('todo_get_current_user_id')) {
            $uid = (int) todo_get_current_user_id();
        } elseif (function_exists('get_current_user_id')) {
            $uid = (int) get_current_user_id();
        } elseif (function_exists('todo_get_current_user')) {
            $u = todo_get_current_user();
            if (is_object($u) && isset($u->ID)) {
                $uid = (int) $u->ID;
            } elseif (is_array($u) && isset($u['ID'])) {
                $uid = (int) $u['ID'];
            }
        }

        if ($uid <= 0) {
            return '';
        }

        if (array_key_exists($uid, $cache)) {
            return $cache[$uid];
        }

        $row = null;
        if (function_exists('get_user_default_address')) {
            $row = get_user_default_address($uid);
        }
        if (empty($row) || !is_array($row)) {
            $cache[$uid] = '';
            return '';
        }

        $province = $row['province'] ?? '';
        $city = $row['city'] ?? '';
        $district = $row['district'] ?? '';
        $detail = trim((string) ($row['address'] ?? ($row['detailed_address'] ?? '')));
        $zip = trim((string) ($row['zipcode'] ?? ($row['postal_code'] ?? '')));

        $has_delimiter = (strpos($province, ' | ') !== false);
        $region_type = $row['region_type'] ?? ($has_delimiter ? 'intl' : 'cn');

        if ($has_delimiter || $region_type === 'intl') {
            $country = '';
            $state = '';
            if ($has_delimiter) {
                list($c_tmp, $s_tmp) = explode(' | ', $province, 2);
                $country = trim($c_tmp);
                $state = trim($s_tmp);
            } else {
                $country = trim($province);
            }
            
            $parts = [];
            if ($detail !== '') $parts[] = $detail;
            if ($city !== '') $parts[] = $city;
            $state_zip = $state;
            if ($zip !== '') {
                $state_zip = $state_zip !== '' ? ($state_zip . ' ' . $zip) : $zip;
            }
            if ($state_zip !== '') $parts[] = $state_zip;
            if ($country !== '') $parts[] = $country;
            $text = implode(', ', $parts);
        } else {
            $p_name = function_exists('todo_regions_find_name') ? todo_regions_find_name($province) : '';
            $c_name = function_exists('todo_regions_find_name') ? todo_regions_find_name($city) : '';
            $d_name = function_exists('todo_regions_find_name') ? todo_regions_find_name($district) : '';
            $prov_disp = !empty($p_name) ? $p_name : $province;
            $city_disp = !empty($c_name) ? $c_name : $city;
            $dist_disp = !empty($d_name) ? $d_name : $district;
            
            $parts = array_filter([$prov_disp, $city_disp, $dist_disp, $detail]);
            $text = implode(' ', $parts);
        }
        if ($text === '') {
            $cache[$uid] = '';
            return '';
        }

        if (function_exists('esc_html')) {
            $cache[$uid] = esc_html($text);
            return $cache[$uid];
        }
        $cache[$uid] = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return $cache[$uid];
    },
];
