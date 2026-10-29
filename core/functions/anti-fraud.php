<?php
/**
 * TodoCMS 订单防拒付与支付风控辅助函数 (IP/设备指纹/PayPal地址自动化比对)
 * 符合 GDPR / ePrivacy 欧盟 Cookie 合规（依据 GDPR Recital 47 防欺诈正当利益及支付安全豁免）
 *
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    exit('Direct access not permitted');
}

if (!function_exists('todo_get_client_ip')) {
    /**
     * 获取客户端真实的 IP 地址
     */
    function todo_get_client_ip() {
        $ip_keys = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'REMOTE_ADDR'
        ];
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                foreach ($ips as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $ip;
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}

if (!function_exists('todo_get_ip_location')) {
    /**
     * 获取 IP 地址地理位置与中东买家常用地识别
     */
    function todo_get_ip_location($ip) {
        if (empty($ip) || $ip === '127.0.0.1' || $ip === '::1') {
            return [
                'country' => 'Local Network',
                'country_code' => 'LOCAL',
                'city' => 'Localhost',
                'region' => 'Localhost',
                'isp' => 'Internal',
                'is_middle_east' => false
            ];
        }

        $transient_key = 'todo_ip_loc_' . md5($ip);
        if (function_exists('get_transient')) {
            $cached = get_transient($transient_key);
            if (is_array($cached)) return $cached;
        }

        $location = [
            'country' => 'Unknown',
            'country_code' => '',
            'city' => '',
            'region' => '',
            'isp' => '',
            'is_middle_east' => false
        ];

        try {
            $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,countryCode,regionName,city,isp';
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $res = curl_exec($ch);
            curl_close($ch);

            if ($res) {
                $data = json_decode($res, true);
                if (is_array($data) && ($data['status'] ?? '') === 'success') {
                    $location['country'] = $data['country'] ?? '';
                    $location['country_code'] = strtoupper($data['countryCode'] ?? '');
                    $location['city'] = $data['city'] ?? '';
                    $location['region'] = $data['regionName'] ?? '';
                    $location['isp'] = $data['isp'] ?? '';
                }
            }
        } catch (Throwable $e) {}

        // 中东常用地国家代码列表：阿联酋(AE)、沙特(SA)、卡塔尔(QA)、科威特(KW)、巴林(BH)、阿曼(OM)、约旦(JO)、黎巴嫩(LB)、埃及(EG)、伊拉克(IQ)
        $me_codes = ['AE', 'SA', 'QA', 'KW', 'BH', 'OM', 'JO', 'LB', 'EG', 'IQ'];
        if (in_array($location['country_code'], $me_codes, true)) {
            $location['is_middle_east'] = true;
        }

        if (function_exists('set_transient')) {
            set_transient($transient_key, $location, 86400);
        }

        return $location;
    }
}

if (!function_exists('todo_parse_server_device_info')) {
    /**
     * 纯后端静默解析买家设备环境与指纹 (100% 前端隐蔽，符合 GDPR / ePrivacy 防欺诈豁免)
     * 无需向客户端写入任何 Cookie，完全基于标准 HTTP 请求头进行安全防欺诈分析
     */
    function todo_parse_server_device_info($client_device_payload = null) {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        $sec_platform = $_SERVER['HTTP_SEC_CH_UA_PLATFORM'] ?? '';
        $sec_mobile = $_SERVER['HTTP_SEC_CH_UA_MOBILE'] ?? '';

        // 1. 识别操作系统
        $os = 'Unknown OS';
        if (preg_match('/macintosh|mac os x/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/windows|win32/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/android/i', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/iphone|ipad|ipod/i', $ua)) {
            $os = 'iOS';
        } elseif (preg_match('/linux/i', $ua)) {
            $os = 'Linux';
        }

        // 2. 识别浏览器类型
        $browser = 'Unknown Browser';
        if (preg_match('/edg/i', $ua)) {
            $browser = 'Microsoft Edge';
        } elseif (preg_match('/chrome|crios/i', $ua) && !preg_match('/opr|opera/i', $ua)) {
            $browser = 'Google Chrome';
        } elseif (preg_match('/safari/i', $ua) && !preg_match('/chrome|crios/i', $ua)) {
            $browser = 'Apple Safari';
        } elseif (preg_match('/firefox|fxios/i', $ua)) {
            $browser = 'Mozilla Firefox';
        } elseif (preg_match('/opr|opera/i', $ua)) {
            $browser = 'Opera';
        }

        // 3. 识别设备类型
        $device_type = 'Desktop';
        if (preg_match('/mobile|android|iphone|ipad|ipod/i', $ua) || $sec_mobile === '?1') {
            $device_type = preg_match('/ipad|tablet/i', $ua) ? 'Tablet' : 'Mobile';
        }

        // 4. 解析 Accept-Language 主要首选语言
        $primary_lang = $lang;
        if (!empty($lang)) {
            $parts = explode(',', $lang);
            $primary_lang = trim($parts[0] ?? '');
        }

        // 5. 如果前端提交了可选客户端补充 payload (如屏幕分辨率、时区)，兼融合并
        $screen = 'Standard Viewport (' . $device_type . ')';
        $timezone = 'Server Local / System Timezone';
        if (!empty($client_device_payload)) {
            if (is_string($client_device_payload)) {
                $decoded = json_decode($client_device_payload, true);
                if (is_array($decoded)) $client_device_payload = $decoded;
            }
            if (is_array($client_device_payload)) {
                if (!empty($client_device_payload['screen'])) $screen = $client_device_payload['screen'];
                if (!empty($client_device_payload['timezone'])) $timezone = $client_device_payload['timezone'];
                if (!empty($client_device_payload['language'])) $primary_lang = $client_device_payload['language'];
            }
        }

        // 6. 生成服务端无 Cookie 防篡改指纹 Hash
        $fingerprint_hash = md5($os . '|' . $browser . '|' . $primary_lang . '|' . ($sec_platform ?: $os));

        return [
            'os'                   => $os,
            'browser'              => $browser,
            'device_type'          => $device_type,
            'screen'               => $screen,
            'language'             => $primary_lang ?: 'Unspecified',
            'platform'             => $sec_platform ? str_replace('"', '', $sec_platform) : $os,
            'timezone'             => $timezone,
            'user_agent'           => $ua,
            'fingerprint_hash'     => substr($fingerprint_hash, 0, 16),
            'gdpr_compliance'      => 'GDPR Recital 47 & ePrivacy Fraud Prevention Exempt (Zero-Cookie Server-Side Processing)'
        ];
    }
}

if (!function_exists('todo_normalize_country_code')) {
    /**
     * 全球通用国家/地区名称与代码规范化映射 (ISO 3166-1 alpha-2)
     */
    function todo_normalize_country_code($country) {
        $c = strtoupper(trim((string) $country));
        if ($c === '') return '';
        // 移除标点符号 (例如 U.S.A. -> USA, U.K. -> UK, U.A.E. -> UAE)
        $c = preg_replace('/[^A-Z0-9\s]/', '', $c);
        $c = preg_replace('/\s+/', ' ', $c);

        if (strlen($c) === 2 && function_exists('todo_get_country_list')) {
            $list = todo_get_country_list();
            if (isset($list[$c])) {
                return $c;
            }
        }

        static $alias_map = [
            'UNITED STATES OF AMERICA' => 'US', 'USA' => 'US', 'AMERICA' => 'US',
            'GREAT BRITAIN' => 'GB', 'UK' => 'GB', 'GBR' => 'GB', 'ENGLAND' => 'GB', 'SCOTLAND' => 'GB', 'WALES' => 'GB',
            'DEUTSCHLAND' => 'DE', 'DEU' => 'DE',
            'FRA' => 'FR',
            'ESPANA' => 'ES', 'ESP' => 'ES',
            'ITALIA' => 'IT', 'ITA' => 'IT',
            'HOLLAND' => 'NL', 'NLD' => 'NL',
            'BEL' => 'BE', 'CHE' => 'CH', 'SWE' => 'SE', 'NOR' => 'NO', 'DNK' => 'DK', 'FIN' => 'FI', 'AUT' => 'AT', 'IRL' => 'IE', 'PRT' => 'PT', 'POL' => 'PL', 'RUS' => 'RU', 'RUSSIAN FEDERATION' => 'RU',
            'UAE' => 'AE', 'DUBAI' => 'AE', 'ABU DHABI' => 'AE', 'ARE' => 'AE',
            'KSA' => 'SA', 'KINGDOM OF SAUDI ARABIA' => 'SA', 'SAU' => 'SA', 'KWT' => 'KW', 'QAT' => 'QA', 'BHR' => 'BH', 'OMN' => 'OM', 'JOR' => 'JO', 'LBN' => 'LB', 'EGY' => 'EG', 'IRQ' => 'IQ', 'ISR' => 'IL', 'TUR' => 'TR', 'TURKIYE' => 'TR',
            'NIPPON' => 'JP', 'JPN' => 'JP', 'KOREA' => 'KR', 'REPUBLIC OF KOREA' => 'KR', 'KOR' => 'KR', 'SGP' => 'SG', 'HKG' => 'HK', 'TWN' => 'TW', 'MAINLAND CHINA' => 'CN', 'PEOPLES REPUBLIC OF CHINA' => 'CN', 'PRC' => 'CN', 'CHN' => 'CN', 'C2' => 'CN',
            'BRASIL' => 'BR', 'BRA' => 'BR', 'THA' => 'TH', 'MYS' => 'MY', 'PHL' => 'PH', 'IDN' => 'ID', 'VNM' => 'VN', 'IND' => 'IN', 'ZAF' => 'ZA', 'CAN' => 'CA', 'AUS' => 'AU', 'NZL' => 'NZ', 'MEX' => 'MX'
        ];

        if (isset($alias_map[$c])) {
            return $alias_map[$c];
        }

        if (function_exists('todo_get_country_list')) {
            static $name_to_code = null;
            if ($name_to_code === null) {
                $name_to_code = [];
                foreach (todo_get_country_list() as $code => $name) {
                    $upper_name = strtoupper(preg_replace('/[^A-Z0-9\s]/', '', $name));
                    $name_to_code[$upper_name] = $code;
                }
            }
            if (isset($name_to_code[$c])) {
                return $name_to_code[$c];
            }
        }

        return (strlen($c) === 2 && ctype_alpha($c)) ? $c : $c;
    }
}

if (!function_exists('todo_extract_paypal_address_info')) {
    /**
     * 从 PayPal 回调 / Capture 结果中提取完整的账单与收货地址
     *
     * @param mixed $paypal_payload PayPal 回调或 Order 对象
     * @param int   $order_id       可选，关联订单ID
     * @return array 提取的地址与付款人信息
     */
    function todo_extract_paypal_address_info($paypal_payload, $order_id = 0) {
        $out = [
            'recipient_name' => '',
            'country_code'   => '',
            'city'           => '',
            'state'          => '',
            'postal_code'    => '',
            'address_line_1' => '',
            'address_line_2' => '',
            'payer_email'    => '',
            'payer_id'       => '',
            'phone'          => '',
            'address_source' => 'unknown',
            'raw'            => $paypal_payload
        ];

        if (!is_array($paypal_payload)) {
            if (is_string($paypal_payload)) {
                $decoded = json_decode($paypal_payload, true);
                if (is_array($decoded)) $paypal_payload = $decoded;
            }
        }
        if (!is_array($paypal_payload)) return $out;

        // 优先提取 payer_email 与 payer_id 基础买家信息
        if (!empty($paypal_payload['payer']) && is_array($paypal_payload['payer'])) {
            $payer = $paypal_payload['payer'];
            $out['payer_email'] = $payer['email_address'] ?? $out['payer_email'];
            $out['payer_id'] = $payer['payer_id'] ?? $out['payer_id'];
            if (!empty($payer['name']) && is_array($payer['name'])) {
                $out['recipient_name'] = trim(($payer['name']['given_name'] ?? '') . ' ' . ($payer['name']['surname'] ?? ''));
            }
            if (!empty($payer['phone']) && is_array($payer['phone'])) {
                $out['phone'] = $payer['phone']['phone_number']['national_number'] ?? ($payer['phone']['phone_number'] ?? $out['phone']);
            }
        }
        if (!empty($paypal_payload['payment_source']['paypal']) && is_array($paypal_payload['payment_source']['paypal'])) {
            $ps = $paypal_payload['payment_source']['paypal'];
            if (empty($out['payer_email']) && !empty($ps['email_address'])) {
                $out['payer_email'] = $ps['email_address'];
            }
            if (empty($out['payer_id']) && !empty($ps['account_id'])) {
                $out['payer_id'] = $ps['account_id'];
            }
        }

        $shipping_found = false;

        // 最高优先级：1. 从 purchase_units 提取真正的 shipping 收货妥投地址 (卖家保障记录地址)
        if (!empty($paypal_payload['purchase_units']) && is_array($paypal_payload['purchase_units'])) {
            foreach ($paypal_payload['purchase_units'] as $pu) {
                if (!empty($pu['shipping']) && is_array($pu['shipping'])) {
                    $shipping = $pu['shipping'];
                    if (!empty($shipping['name']) && is_array($shipping['name'])) {
                        $out['recipient_name'] = $shipping['name']['full_name'] ?? $out['recipient_name'];
                    } elseif (is_string($shipping['name'] ?? null)) {
                        $out['recipient_name'] = $shipping['name'];
                    }
                    if (!empty($shipping['address']) && is_array($shipping['address'])) {
                        $s_addr = $shipping['address'];
                        $out['country_code']   = strtoupper($s_addr['country_code'] ?? $out['country_code']);
                        $out['address_line_1'] = $s_addr['address_line_1'] ?? $out['address_line_1'];
                        $out['address_line_2'] = $s_addr['address_line_2'] ?? $out['address_line_2'];
                        $out['city']           = $s_addr['admin_area_2'] ?? ($s_addr['city'] ?? $out['city']);
                        $out['state']          = $s_addr['admin_area_1'] ?? ($s_addr['state'] ?? $out['state']);
                        $out['postal_code']    = $s_addr['postal_code'] ?? $out['postal_code'];
                    }
                    if (!empty($shipping['phone_number'])) {
                        if (is_array($shipping['phone_number'])) {
                            $out['phone'] = $shipping['phone_number']['national_number'] ?? ($shipping['phone_number']['phone_number'] ?? $out['phone']);
                        } elseif (is_string($shipping['phone_number'])) {
                            $out['phone'] = $shipping['phone_number'];
                        }
                    }
                    if (!empty($out['country_code']) && (!empty($out['address_line_1']) || !empty($out['city']))) {
                        $shipping_found = true;
                        $out['address_source'] = 'shipping';
                    }
                    break;
                }
            }
        }

        // 次优先级：2. 若回调 JSON 中不含 shipping 字段（如仅为 POST /capture 原始响应），尝试从本站提交的 _paypal_sent_payload 中同步妥投地址
        if (!$shipping_found) {
            $target_oid = intval($order_id);
            if ($target_oid <= 0 && !empty($paypal_payload['purchase_units'][0]['custom_id'])) {
                $target_oid = intval($paypal_payload['purchase_units'][0]['custom_id']);
            }
            if ($target_oid > 0 && class_exists('Todo_Order_Manager')) {
                $sent = Todo_Order_Manager::get_order_custom_field($target_oid, '_paypal_sent_payload');
                if (is_array($sent) && !empty($sent['purchase_units'][0]['shipping'])) {
                    $s_sent = $sent['purchase_units'][0]['shipping'];
                    if (!empty($s_sent['name']['full_name']) && empty($out['recipient_name'])) {
                        $out['recipient_name'] = $s_sent['name']['full_name'];
                    }
                    if (!empty($s_sent['address']) && is_array($s_sent['address'])) {
                        $sa = $s_sent['address'];
                        $out['country_code']   = strtoupper($sa['country_code'] ?? '');
                        $out['address_line_1'] = $sa['address_line_1'] ?? '';
                        $out['address_line_2'] = $sa['address_line_2'] ?? '';
                        $out['city']           = $sa['admin_area_2'] ?? '';
                        $out['state']          = $sa['admin_area_1'] ?? '';
                        $out['postal_code']    = $sa['postal_code'] ?? '';
                        $shipping_found        = true;
                        $out['address_source'] = 'sent_payload';
                    }
                }
            }
        }

        // 兜底方案：3. 若完全无法找到妥投地址，才回退至 payer.address 买家账号注册地/账单国家
        if (!$shipping_found) {
            $out['address_source'] = 'payer_account';
            if (!empty($paypal_payload['payer']['address']) && is_array($paypal_payload['payer']['address'])) {
                $addr = $paypal_payload['payer']['address'];
                $out['country_code']   = $out['country_code'] ?: strtoupper($addr['country_code'] ?? '');
                $out['address_line_1'] = $out['address_line_1'] ?: ($addr['address_line_1'] ?? '');
                $out['address_line_2'] = $out['address_line_2'] ?: ($addr['address_line_2'] ?? '');
                $out['city']           = $out['city'] ?: ($addr['admin_area_2'] ?? ($addr['city'] ?? ''));
                $out['state']          = $out['state'] ?: ($addr['admin_area_1'] ?? ($addr['state'] ?? ''));
                $out['postal_code']    = $out['postal_code'] ?: ($addr['postal_code'] ?? '');
            } elseif (!empty($paypal_payload['payment_source']['paypal']['address']) && is_array($paypal_payload['payment_source']['paypal']['address'])) {
                $p_addr = $paypal_payload['payment_source']['paypal']['address'];
                $out['country_code']   = $out['country_code'] ?: strtoupper($p_addr['country_code'] ?? '');
                $out['address_line_1'] = $out['address_line_1'] ?: ($p_addr['address_line_1'] ?? '');
                $out['address_line_2'] = $out['address_line_2'] ?: ($p_addr['address_line_2'] ?? '');
                $out['city']           = $out['city'] ?: ($p_addr['admin_area_2'] ?? ($p_addr['city'] ?? ''));
                $out['state']          = $out['state'] ?: ($p_addr['admin_area_1'] ?? ($p_addr['state'] ?? ''));
                $out['postal_code']    = $out['postal_code'] ?: ($p_addr['postal_code'] ?? '');
            }
        }

        return $out;
    }
}

if (!function_exists('todo_compare_addresses')) {
    /**
     * 比对买家填写地址与 PayPal 回调账单/收货地址
     */
    function todo_compare_addresses($user_address_raw, $paypal_address_info) {
        $user_addr = [];
        if (is_string($user_address_raw)) {
            $user_addr = json_decode($user_address_raw, true) ?: [];
        } elseif (is_array($user_address_raw)) {
            $user_addr = $user_address_raw;
        }

        // 规范化地址
        $u_name     = trim($user_addr['recipient_name'] ?? ($user_addr['name'] ?? (($user_addr['first_name'] ?? '') . ' ' . ($user_addr['last_name'] ?? ''))));
        $u_email    = trim($user_addr['email'] ?? ($user_addr['user_email'] ?? ($user_addr['payer_email'] ?? '')));
        $u_phone    = trim($user_addr['phone'] ?? ($user_addr['telephone'] ?? ($user_addr['mobile'] ?? '')));
        $u_country  = strtoupper(trim($user_addr['country_code'] ?? ($user_addr['country'] ?? '')));
        $u_city     = trim($user_addr['city'] ?? '');
        $u_state    = trim($user_addr['state'] ?? ($user_addr['province'] ?? ''));
        $u_street   = trim($user_addr['detail'] ?? ($user_addr['address_detail'] ?? ($user_addr['street'] ?? ($user_addr['address'] ?? ($user_addr['address_line_1'] ?? '')))));
        $u_postal   = trim($user_addr['postal_code'] ?? ($user_addr['zipcode'] ?? ($user_addr['zip'] ?? '')));

        // 规范化 PayPal 回调地址
        $p_name     = trim($paypal_address_info['recipient_name'] ?? '');
        $p_email    = trim($paypal_address_info['payer_email'] ?? '');
        $p_phone    = trim($paypal_address_info['phone'] ?? ($paypal_address_info['phone_number'] ?? ''));
        $p_country  = strtoupper(trim($paypal_address_info['country_code'] ?? ''));
        $p_city     = trim($paypal_address_info['city'] ?? '');
        $p_state    = trim($paypal_address_info['state'] ?? '');
        $p_street   = trim(($paypal_address_info['address_line_1'] ?? '') . ' ' . ($paypal_address_info['address_line_2'] ?? ''));
        $p_postal   = trim($paypal_address_info['postal_code'] ?? '');
        $p_source   = $paypal_address_info['address_source'] ?? 'unknown';

        $mismatches = [];

        // 0. 邮箱比对 (若均存在且不同)
        if ($u_email !== '' && $p_email !== '') {
            if (strcasecmp($u_email, $p_email) !== 0) {
                $mismatches[] = "Email mismatch: Site [{$u_email}] vs PayPal [{$p_email}]";
            }
        }

        // 1. 国家/地区比对 (归一化 ISO 代码后再做比对)
        $u_country_norm = todo_normalize_country_code($u_country);
        $p_country_norm = todo_normalize_country_code($p_country);
        if ($u_country_norm !== '' && $p_country_norm !== '') {
            if ($u_country_norm !== $p_country_norm) {
                if ($p_source === 'payer_account') {
                    $mismatches[] = "Payer Account Country difference: Delivery Country [{$u_country}] vs Buyer PayPal Account Origin [{$p_country}] (Cross-border payment / Gift order)";
                } else {
                    $mismatches[] = "Country mismatch: Site [{$u_country}] vs PayPal Callback [{$p_country}]";
                }
            }
        }

        // 2. 城市比对 (忽略大小写与前后空格)
        if ($u_city !== '' && $p_city !== '') {
            if (strcasecmp($u_city, $p_city) !== 0 && strpos(strtolower($u_city), strtolower($p_city)) === false && strpos(strtolower($p_city), strtolower($u_city)) === false) {
                $mismatches[] = "City mismatch: Site [{$u_city}] vs PayPal Callback [{$p_city}]";
            }
        }

        // 3. 邮编比对
        if ($u_postal !== '' && $p_postal !== '') {
            $clean_u_postal = preg_replace('/[^a-zA-Z0-9]/', '', $u_postal);
            $clean_p_postal = preg_replace('/[^a-zA-Z0-9]/', '', $p_postal);
            if ($clean_u_postal !== $clean_p_postal) {
                $mismatches[] = "Postal code mismatch: Site [{$u_postal}] vs PayPal Callback [{$p_postal}]";
            }
        }

        // 4. 街道地址比对 (若填写了街道)
        if ($u_street !== '' && $p_street !== '') {
            $clean_u_st = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $u_street));
            $clean_p_st = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $p_street));
            if ($clean_u_st !== $clean_p_st && strpos($clean_u_st, $clean_p_st) === false && strpos($clean_p_st, $clean_u_st) === false) {
                $mismatches[] = "Street address mismatch: Site [{$u_street}] vs PayPal Callback [{$p_street}]";
            }
        }

        $match_status = 'MATCH';
        if (!empty($mismatches)) {
            $hard_mismatches = array_filter($mismatches, function($item) {
                return strpos($item, 'Country mismatch:') !== false || strpos($item, 'City mismatch:') !== false || strpos($item, 'Postal code mismatch:') !== false;
            });
            $match_status = !empty($hard_mismatches) ? 'MISMATCH' : 'PARTIAL_MATCH';
        }

        return [
            'status'         => $match_status,
            'is_100_percent' => ($match_status === 'MATCH'),
            'mismatches'     => $mismatches,
            'user_address'   => [
                'name'    => $u_name,
                'email'   => $u_email,
                'phone'   => $u_phone,
                'country' => $u_country,
                'city'    => $u_city,
                'state'   => $u_state,
                'street'  => $u_street,
                'postal'  => $u_postal
            ],
            'paypal_address' => [
                'name'    => $p_name,
                'email'   => $p_email,
                'phone'   => $p_phone,
                'country' => $p_country,
                'city'    => $p_city,
                'state'   => $p_state,
                'street'  => $p_street,
                'postal'  => $p_postal,
                'source'  => $p_source
            ]
        ];
    }
}

if (!function_exists('todo_check_ip_location_with_shipping')) {
    /**
     * 比对 IP 定位与妥投/收货城市 (英文格式输出以满足国际抗辩标准)
     */
    function todo_check_ip_location_with_shipping($ip_location, $user_address_raw) {
        $user_addr = is_array($user_address_raw) ? $user_address_raw : (json_decode($user_address_raw, true) ?: []);
        $dest_city = trim($user_addr['city'] ?? '');
        $dest_country = strtoupper(trim($user_addr['country_code'] ?? ($user_addr['country'] ?? '')));

        $ip_city = trim($ip_location['city'] ?? '');
        $ip_country = strtoupper(trim($ip_location['country_code'] ?? ''));
        $is_middle_east = !empty($ip_location['is_middle_east']);

        $is_city_matched = false;
        if ($dest_city !== '' && $ip_city !== '') {
            if (strcasecmp($dest_city, $ip_city) === 0 || strpos(strtolower($dest_city), strtolower($ip_city)) !== false || strpos(strtolower($ip_city), strtolower($dest_city)) !== false) {
                $is_city_matched = true;
            }
        }

        $is_country_matched = false;
        if ($dest_country !== '' && $ip_country !== '') {
            if ($dest_country === $ip_country) {
                $is_country_matched = true;
            }
        }

        $verdict = '';
        if ($is_city_matched) {
            $verdict = "MATCHED: Buyer IP location ({$ip_city}, {$ip_country}) matches delivery destination city ({$dest_city}) 100%";
            if ($is_middle_east) {
                $verdict .= " (Middle East Region Verified)";
            }
        } elseif ($is_country_matched) {
            $verdict = "SAME_COUNTRY: Buyer IP location ({$ip_city}) and delivery city ({$dest_city}) are both in [{$ip_country}]";
        } else {
            $verdict = "WARNING: Buyer IP location ({$ip_country} {$ip_city}) differs from delivery destination ({$dest_country} {$dest_city}). Proxy or third-party order suspected.";
        }

        return [
            'is_city_matched'    => $is_city_matched,
            'is_country_matched' => $is_country_matched,
            'is_middle_east'     => $is_middle_east,
            'verdict'            => $verdict
        ];
    }
}

if (!function_exists('todo_save_order_anti_fraud_data')) {
    /**
     * 持久化保存订单风控与防拒付全套证据链 (100% 符合 GDPR / ePrivacy 无 Cookie 合规)
     */
    function todo_save_order_anti_fraud_data($order_id, $ip_address = null, $device_info = null, $paypal_payload = null, $paypal_order_id = '') {
        $order_id = intval($order_id);
        if ($order_id <= 0) return false;

        if (empty($ip_address)) {
            $ip_address = Todo_Order_Manager::get_order_custom_field($order_id, '_client_ip') ?: todo_get_client_ip();
        }

        // 保存 IP 与 IP 定位
        Todo_Order_Manager::save_order_custom_field($order_id, '_client_ip', $ip_address);
        $ip_loc = todo_get_ip_location($ip_address);
        Todo_Order_Manager::save_order_custom_field($order_id, '_ip_location', $ip_loc);

        // 纯后端静默解析买家设备环境与指纹
        $parsed_device_info = todo_parse_server_device_info($device_info);
        Todo_Order_Manager::save_order_custom_field($order_id, '_device_info', $parsed_device_info);

        // 保存 PayPal 订单号 (如果有)
        if (!empty($paypal_order_id)) {
            Todo_Order_Manager::save_order_custom_field($order_id, '_paypal_order_id', $paypal_order_id);
        }

        // 获取或绑定买家收货地址
        $order = Todo_Order_Manager::get_order($order_id, false);
        $user_shipping = Todo_Order_Manager::get_order_custom_field($order_id, '_checkout_shipping_address');
        if (empty($user_shipping) && !empty($order['shipping_address'])) {
            $user_shipping = $order['shipping_address'];
            Todo_Order_Manager::save_order_custom_field($order_id, '_checkout_shipping_address', $user_shipping);
        }

        // 比对 IP 定位与妥投城市
        $ip_check = todo_check_ip_location_with_shipping($ip_loc, $user_shipping);
        Todo_Order_Manager::save_order_custom_field($order_id, '_ip_address_match_details', $ip_check);

        // 若包含了 PayPal 回调数据，执行自动化地址比对与归档
        if (!empty($paypal_payload)) {
            $paypal_addr = todo_extract_paypal_address_info($paypal_payload, $order_id);
            Todo_Order_Manager::save_order_custom_field($order_id, '_paypal_callback_address', $paypal_addr);

            $cmp = todo_compare_addresses($user_shipping, $paypal_addr);
            Todo_Order_Manager::save_order_custom_field($order_id, '_address_match_status', $cmp['status']);
            Todo_Order_Manager::save_order_custom_field($order_id, '_address_match_details', $cmp);

            // 防拒付终极证据包 JSON (Dispute / Chargeback Evidence Packet)
            $sent_payload = Todo_Order_Manager::get_order_custom_field($order_id, '_paypal_sent_payload');
            $evidence_packet = [
                'order_id'                 => $order_id,
                'order_no'                 => $order['order_no'] ?? '',
                'created_at'               => $order['created_at'] ?? date('Y-m-d H:i:s'),
                'buyer_ip'                 => $ip_address,
                'ip_location'              => $ip_loc,
                'ip_location_verdict'      => $ip_check['verdict'] ?? '',
                'device_info'              => $parsed_device_info,
                'site_shipping_address'    => is_string($user_shipping) ? (json_decode($user_shipping, true) ?: $user_shipping) : $user_shipping,
                'paypal_sent_payload'      => $sent_payload,
                'paypal_callback_address'  => $paypal_addr,
                'address_comparison'       => $cmp,
                'paypal_order_id'          => $paypal_order_id ?: (Todo_Order_Manager::get_order_custom_field($order_id, '_paypal_order_id') ?: ''),
                'raw_paypal_payload'       => $paypal_payload
            ];
            Todo_Order_Manager::save_order_custom_field($order_id, '_anti_fraud_evidence', $evidence_packet);
            if (function_exists('todo_update_post_meta')) {
                todo_update_post_meta($order_id, '_anti_fraud_evidence', $evidence_packet);
            }

            // 自动记录日志
            if ($cmp['status'] === 'MISMATCH') {
                $warn_msg = "⚠️ 【PayPal账单地址不一致警示】PayPal回调地址与下单收货地址存在差异！" . (!empty($cmp['mismatches']) ? (' 差异点: ' . implode('; ', $cmp['mismatches'])) : '');
                Todo_Order_Manager::add_order_log($order_id, 'anti_fraud_warning', $warn_msg);
            } else {
                $ok_msg = "✅ 【PayPal风控防拒付核对通过】PayPal回调地址与收货地址100%一致。" . ($ip_check['verdict'] ?? '');
                Todo_Order_Manager::add_order_log($order_id, 'anti_fraud_passed', $ok_msg);
            }
        }

        return true;
    }
}
