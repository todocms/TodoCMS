<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

/**
 * TodoCMS 多语言管理主页面
 * 
 * @package TodoCMS
 * @subpackage Language
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access denied');
}

// 检查用户权限
if (!current_user_can('manage_languages')) {
    todo_die('您没有权限访问此页面');
}

// 加载新语言注册中心与函数
require_once ABSPATH . 'core/functions/language.php';

// 确保 todo_add_admin_notice 函数可用
if (!function_exists('todo_add_admin_notice')) {
    require_once ABSPATH . 'core/functions/missing-core-functions.php';
}

// 防缓存：避免浏览器/代理缓存导致保存后第一次刷新不生效
@header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
@header('Pragma: no-cache');

$registry = LanguageRegistry::get_instance();
// 确保注册中心在本请求生命周期内已初始化（包含 POST 保存场景）
$registry->init();
// 渲染端统一通过 $registry->get_*() 获取实时数据（取消 $routing_mode/$default_locale/$config_languages 等中间变量）
// 兼容性防护：若有遗留模板/代码使用 $routing_mode 变量，提供只读回退值以避免 Notice
if (!isset($routing_mode)) {
    $routing_mode = $registry->get_routing_mode();
}

$language_theme_map_option_key = 'language_theme_map';

// 处理表单提交
if ($_POST && Todo_Security_Manager::verify_request_csrf('language_management')) {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'update_language_status':
            $locale = $_POST['locale'] ?? '';
            $enabled = isset($_POST['enabled']) ? (int) $_POST['enabled'] : 0;
            if ($locale) {
                if (todo_update_language_enabled($locale, $enabled)) {
                    // 重新载入注册表
                    $registry->init();
                    todo_add_admin_notice('语言状态已更新', 'success');
                    // PRG: 保存成功后重定向到 GET，避免重复提交，确保显示最新值
                    $redirect = $_SERVER['REQUEST_URI'];
                    $redirect .= (strpos($redirect, '?') !== false ? '&' : '?') . 't=' . microtime(true);
                    todo_redirect($redirect);
                    exit;
                } else {
                    todo_add_admin_notice('语言状态更新失败', 'error');
                }
            }
            break;
        case 'set_default_locale':
            $locale = $_POST['locale'] ?? '';
            if ($locale) {
                if (todo_set_default_locale($locale)) {
                    $registry->init();
                    // 强绑定：默认语言强制使用默认主题（移除显式映射）
                    $map = get_option($language_theme_map_option_key, array());
                    if (is_string($map)) {
                        $decoded = json_decode($map, true);
                        $map = is_array($decoded) ? $decoded : array();
                    }
                    $locNorm = str_replace('_', '-', $locale);
                    $deleted = false;
                    if (isset($map[$locNorm])) {
                        unset($map[$locNorm]);
                        $deleted = true;
                    }
                    if (isset($map[$locale])) {
                        unset($map[$locale]);
                        $deleted = true;
                    }
                    if ($deleted) {
                        update_option($language_theme_map_option_key, json_encode($map));
                    }

                    todo_add_admin_notice('默认语言已设为：' . esc_html($locale) . '（已强制使用默认主题且无路径前缀）', 'success');
                    // PRG: 保存成功后重定向到 GET，避免重复提交，确保显示最新值
                    $redirect = $_SERVER['REQUEST_URI'];
                    $redirect .= (strpos($redirect, '?') !== false ? '&' : '?') . 't=' . microtime(true);
                    todo_redirect($redirect);
                    exit;
                } else {
                    todo_add_admin_notice('设置默认语言失败', 'error');
                }
            }
            break;
        case 'set_fallback_locale':
            $locale = $_POST['locale'] ?? '';
            if ($locale) {
                if (todo_set_fallback_locale($locale)) {
                    $registry->init();
                    todo_add_admin_notice('回退语言已设置为：' . esc_html($locale), 'success');
                    // PRG: 保存成功后重定向到 GET，避免重复提交，确保显示最新值
                    $redirect = $_SERVER['REQUEST_URI'];
                    $redirect .= (strpos($redirect, '?') !== false ? '&' : '?') . 't=' . microtime(true);
                    todo_redirect($redirect);
                    exit;
                } else {
                    todo_add_admin_notice('设置回退语言失败', 'error');
                }
            }
            break;
        case 'add_language':
            $data = array(
                'mark' => isset($_POST['mark']) ? trim($_POST['mark']) : '',
                'locale' => $_POST['locale'] ?? '',
                'title' => isset($_POST['title']) ? trim($_POST['title']) : '',
                'path_prefix' => isset($_POST['path_prefix']) ? trim($_POST['path_prefix']) : '',
                'domain' => isset($_POST['domain']) ? trim($_POST['domain']) : '',
                'enabled' => isset($_POST['enabled']) ? (int) $_POST['enabled'] : 1,
                'parent_id' => isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? trim($_POST['parent_id']) : null,
                'sort_order' => isset($_POST['sort_order']) ? intval($_POST['sort_order']) : 0,
            );
            $res = todo_add_language($data);
            if ($res === true) {
                $registry->init();
                todo_add_admin_notice('语言已新增：' . esc_html($data['locale']), 'success');
                // PRG: 保存成功后重定向到 GET，避免重复提交，确保显示最新值
                $redirect = $_SERVER['REQUEST_URI'];
                $redirect .= (strpos($redirect, '?') !== false ? '&' : '?') . 't=' . microtime(true);
                todo_redirect($redirect);
                exit;
            } else {
                $msg = is_string($res) ? $res : '新增语言失败，请检查输入或重复的标记/Locale';
                todo_add_admin_notice($msg, 'error');
            }
            break;
        case 'delete_language':
            $locale = $_POST['locale'] ?? '';
            if ($locale) {
                if (todo_delete_language($locale)) {
                    $registry->init();
                    todo_add_admin_notice('语言已删除：' . esc_html($locale), 'success');
                    // PRG: 保存成功后重定向到 GET，避免重复提交，确保显示最新值
                    $redirect = $_SERVER['REQUEST_URI'];
                    $redirect .= (strpos($redirect, '?') !== false ? '&' : '?') . 't=' . microtime(true);
                    todo_redirect($redirect);
                    exit;
                } else {
                    todo_add_admin_notice('删除语言失败', 'error');
                }
            }
            break;
        case 'set_routing_mode':
            $mode = $_POST['routing_mode'] ?? '';
            if (in_array($mode, ['path_prefix', 'domain'], true)) {
                if (todo_set_routing_mode($mode)) {
                    $registry->init();
                    todo_add_admin_notice('路由模式已更新为：' . esc_html($registry->get_routing_mode()), 'success');
                    // PRG: 保存成功后重定向到 GET，避免重复提交，确保显示最新值
                    $redirect = $_SERVER['REQUEST_URI'];
                    $redirect .= (strpos($redirect, '?') !== false ? '&' : '?') . 't=' . microtime(true);
                    todo_redirect($redirect);
                    exit;
                } else {
                    todo_add_admin_notice('更新路由模式失败', 'error');
                }
            } else {
                todo_add_admin_notice('无效的路由模式', 'error');
            }
            break;
        case 'update_language_mapping':
            $locale = $_POST['locale'] ?? '';
            $updates = array();
            if (isset($_POST['path_prefix'])) {
                $updates['path_prefix'] = trim($_POST['path_prefix']);
            }
            if (isset($_POST['domain'])) {
                $updates['domain'] = trim($_POST['domain']);
            }
            if ($locale && !empty($updates)) {
                if (todo_update_language_mapping($locale, $updates)) {
                    $registry->init();
                    todo_add_admin_notice('映射已更新', 'success');
                    // PRG: 保存成功后重定向到 GET，避免重复提交，确保显示最新值
                    $redirect = $_SERVER['REQUEST_URI'];
                    $redirect .= (strpos($redirect, '?') !== false ? '&' : '?') . 't=' . microtime(true);
                    todo_redirect($redirect);
                    exit;
                } else {
                    todo_add_admin_notice('更新映射失败：可能存在重复的前缀或域名', 'error');
                }
            }
            break;
        case 'update_language_theme':
            $locale = $_POST['locale'] ?? '';
            $theme = isset($_POST['theme']) ? trim($_POST['theme']) : '';
            if ($locale) {
                // 默认语言不允许设置显式主题映射，统一使用系统默认主题（等价于不设置映射）
                $default_locale = $registry->get_default_locale();
                $is_default_locale = ($default_locale === $locale || str_replace('_', '-', $default_locale) === str_replace('_', '-', $locale));
                if ($is_default_locale) {
                    $theme = '';
                }

                $locNorm = str_replace('_', '-', $locale);

                // 统一获取与解析现有映射及系统默认主题
                $default_theme_slug = CMS_Theme::get_active_theme()->get_stylesheet();
                $map = get_option($language_theme_map_option_key, array());
                if (is_string($map)) {
                    $decoded = json_decode($map, true);
                    $map = is_array($decoded) ? $decoded : array();
                }
                if (!is_array($map)) {
                    $map = array();
                }
                $prev = isset($map[$locNorm]) ? $map[$locNorm] : null;

                // 若选择的是系统默认主题，则等价于不设置映射（避免“默认主题”和默认主题条目重复）
                if ($theme === $default_theme_slug) {
                    $theme = '';
                }

                $saved = false;
                if ($theme === '') {
                    // 移除映射；若本就为空，则视为无变化但成功
                    if (isset($map[$locNorm])) {
                        unset($map[$locNorm]);
                        $saved = update_option($language_theme_map_option_key, json_encode($map));
                    } else {
                        $saved = true; // 无变化也当作成功
                    }
                } else {
                    // 校验与写入（允许未启用语言；只校验语言是否已注册与主题是否存在）
                    if (preg_match('/^[A-Za-z0-9_-]+$/', $theme) && $registry->is_supported_locale($locNorm, false) && theme_exists($theme)) {
                        if ($prev === $theme) {
                            $saved = true; // 无变化
                        } else {
                            $map[$locNorm] = $theme;
                            $saved = update_option($language_theme_map_option_key, json_encode($map));
                        }
                    } else {
                        $saved = false; // 无效主题或语言不受支持
                    }
                }

                if ($saved) {
                    $registry->init();
                    todo_add_admin_notice(($prev === ($theme === '' ? null : $theme)) ? '主题映射未变化' : '主题映射已更新', 'success');
                    // PRG: 保存成功后重定向到 GET，避免重复提交，且清理已有 t 参数避免重复追加
                    $redirect = $_SERVER['REQUEST_URI'];
                    $parts = parse_url($redirect);
                    $path = isset($parts['path']) ? $parts['path'] : '';
                    $query = array();
                    if (!empty($parts['query'])) {
                        parse_str($parts['query'], $query);
                    }
                    unset($query['t']);
                    $qs = http_build_query($query);
                    $redirect = $path . (strlen($qs) ? '?' . $qs . '&' : '?') . 't=' . microtime(true);
                    todo_redirect($redirect);
                    exit;
                } else {
                    todo_add_admin_notice('更新主题映射失败：请检查主题或语言', 'error');
                }
            }
            break;

        case 'update_language_currency':
            $locale = $_POST['locale'] ?? '';
            if ($locale) {
                $updates = [
                    'currency_code' => $_POST['currency_code'] ?? '',
                    'currency_exchange_rate' => $_POST['currency_exchange_rate'] ?? 1,
                    'currency_status' => $_POST['currency_status'] ?? 1,
                    'is_default_currency' => $_POST['is_default_currency'] ?? 0,
                ];
                if (isset($_POST['currency_symbol_mode'])) {
                    $mode = in_array($_POST['currency_symbol_mode'], ['b2c', 'b2b'], true) ? $_POST['currency_symbol_mode'] : 'b2c';
                    update_option('currency_symbol_mode', $mode);
                }
                $default_locale = $registry->get_default_locale();
                $is_default_locale = ($default_locale === $locale || str_replace('_', '-', $default_locale) === str_replace('_', '-', $locale));
                if ($is_default_locale) {
                    $updates['currency_exchange_rate'] = 1;
                    $updates['is_default_currency'] = 1;
                    $updates['currency_status'] = 1; // 业务逻辑：默认语言则汇率自动为1，且自动设为默认
                } else if (empty($updates['currency_status'])) {
                    // 如果货币状态关闭，则自动选取默认语言货币类型
                    $all_langs = $registry->get_languages(false);
                    $default_currency_code = '';
                    foreach ($all_langs as $code => $info) {
                        if (!empty($info['is_default_currency'])) {
                            $default_currency_code = $info['currency_code'] ?? '';
                            break;
                        }
                    }
                    if (!$default_currency_code) {
                        foreach ($all_langs as $code => $info) {
                            if ($code === $default_locale || str_replace('_', '-', $code) === str_replace('_', '-', $default_locale)) {
                                $default_currency_code = $info['currency_code'] ?? '';
                                break;
                            }
                        }
                    }
                    $updates['currency_code'] = $default_currency_code;
                }
                if (todo_update_language_currency($locale, $updates)) {
                    $registry->init();
                    todo_add_admin_notice('货币设置已更新', 'success');
                } else {
                    todo_add_admin_notice('货币设置更新失败', 'error');
                }
                $redirect = $_SERVER['REQUEST_URI'];
                $parts = parse_url($redirect);
                $path = isset($parts['path']) ? $parts['path'] : '';
                $query = array();
                if (!empty($parts['query'])) {
                    parse_str($parts['query'], $query);
                }
                unset($query['t']);
                $qs = http_build_query($query);
                $redirect = $path . (strlen($qs) ? '?' . $qs . '&' : '?') . 't=' . microtime(true);
                todo_redirect($redirect);
                exit;
            }
            break;

        case 'update_language_site_association':
            $locale = $_POST['locale'] ?? '';
            if ($locale) {
                $site_ids = array();
                if (!empty($_POST['site_ids']) && is_array($_POST['site_ids'])) {
                    $site_ids = array_map('intval', $_POST['site_ids']);
                }
                $default_site_id = isset($_POST['default_site_id']) ? intval($_POST['default_site_id']) : 0;

                if (todo_update_language_site_association($locale, [
                    'site_ids' => $site_ids,
                    'default_site_id' => $default_site_id,
                ])) {
                    $registry->init();
                    todo_add_admin_notice('站点关联已更新', 'success');
                } else {
                    todo_add_admin_notice('站点关联更新失败', 'error');
                }
                $redirect = $_SERVER['REQUEST_URI'];
                $parts = parse_url($redirect);
                $path = isset($parts['path']) ? $parts['path'] : '';
                $query = array();
                if (!empty($parts['query'])) {
                    parse_str($parts['query'], $query);
                }
                unset($query['t']);
                $qs = http_build_query($query);
                $redirect = $path . (strlen($qs) ? '?' . $qs . '&' : '?') . 't=' . microtime(true);
                todo_redirect($redirect);
                exit;
            }
            break;

        // 新增：统一行保存（前缀/域名/主题/启用）
        case 'update_language_row':
            $locale = $_POST['locale'] ?? '';
            if ($locale) {
                $ok = true;
                $messages = array();

                // 1) 排序
                if (isset($_POST['sort_order'])) {
                    $sort_order = intval($_POST['sort_order']);
                    if (todo_update_language_sort_order($locale, $sort_order)) {
                        $messages[] = '排序已更新';
                    } else {
                        $ok = false;
                        $messages[] = '排序更新失败';
                    }
                }

                // 2) 前缀/域名映射
                $updates = array();
                if (isset($_POST['path_prefix'])) {
                    $updates['path_prefix'] = trim($_POST['path_prefix']);
                }
                if (isset($_POST['domain'])) {
                    $updates['domain'] = trim($_POST['domain']);
                }
                if (!empty($updates)) {
                    if (todo_update_language_mapping($locale, $updates)) {
                        $messages[] = '映射已更新';
                    } else {
                        $ok = false;
                        $messages[] = '映射更新失败：可能存在重复的前缀或域名';
                    }
                }

                // 3) 主题映射
                if (isset($_POST['theme'])) {
                    $theme = trim($_POST['theme']);
                    $locNorm = str_replace('_', '-', $locale);

                    // 统一获取与解析现有映射
                    $default_theme_slug = CMS_Theme::get_active_theme()->get_stylesheet();
                    $map = get_option($language_theme_map_option_key, array());
                    if (is_string($map)) {
                        $decoded = json_decode($map, true);
                        $map = is_array($decoded) ? $decoded : array();
                    }
                    if (!is_array($map)) {
                        $map = array();
                    }
                    $prev = isset($map[$locNorm]) ? $map[$locNorm] : null;

                    // 若选择的是系统默认主题，则等价于不设置映射（避免“默认主题”和默认主题条目重复）
                    if ($theme === $default_theme_slug) {
                        $theme = '';
                    }

                    $saved = false; // 标记写入/处理结果

                    if ($theme === '') {
                        // 移除映射；若本就为空，则视为无变化但成功
                        if (isset($map[$locNorm])) {
                            unset($map[$locNorm]);
                            $saved = update_option($language_theme_map_option_key, json_encode($map));
                        } else {
                            $saved = true; // 无变化也当作成功
                        }
                    } else {
                        // 校验与写入（允许未启用语言；只校验语言是否已注册与主题是否存在）
                        if (preg_match('/^[A-Za-z0-9_-]+$/', $theme) && $registry->is_supported_locale($locNorm, false) && theme_exists($theme)) {
                            if ($prev === $theme) {
                                $saved = true; // 无变化
                            } else {
                                $map[$locNorm] = $theme;
                                $saved = update_option($language_theme_map_option_key, json_encode($map));
                            }
                        } else {
                            $saved = false; // 无效主题或语言不受支持
                        }
                    }

                    if ($saved) {
                        // 根据是否变化给出更准确的提示
                        $messages[] = ($prev === ($theme === '' ? null : $theme)) ? '主题映射未变化' : '主题映射已更新';
                    } else {
                        $ok = false;
                        $messages[] = '主题映射更新失败';
                    }
                }

                // 4) 启用状态
                if (isset($_POST['enabled'])) {
                    $enabled = (int) $_POST['enabled'];
                    $default_locale = $registry->get_default_locale();
                    if ($enabled === 0 && $default_locale === $locale) {
                        $ok = false;
                        $messages[] = '默认语言不可禁用';
                    } else {
                        if (todo_update_language_enabled($locale, $enabled)) {
                            $messages[] = '状态已更新';
                        } else {
                            $ok = false;
                            $messages[] = '状态更新失败';
                        }
                    }
                }

                if ($ok) {
                    $registry->init();
                    todo_add_admin_notice('语言行已保存：' . esc_html($locale), 'success');
                    $redirect = $_SERVER['REQUEST_URI'];
                    $parts = parse_url($redirect);
                    $path = isset($parts['path']) ? $parts['path'] : '';
                    $query = array();
                    if (!empty($parts['query'])) {
                        parse_str($parts['query'], $query);
                    }
                    unset($query['t']);
                    $qs = http_build_query($query);
                    $redirect = $path . (strlen($qs) ? '?' . $qs . '&' : '?') . 't=' . microtime(true);
                    todo_redirect($redirect);
                    exit;
                } else {
                    todo_add_admin_notice('保存失败：' . esc_html(implode('；', $messages)), 'error');
                }
            }
            break;
    }
} elseif ($_POST) {
    // CSRF 验证失败或令牌过期的提示（帮助定位为何没有任何消息显示）
    $action = isset($_POST['action']) ? $_POST['action'] : '(未提交)';
    todo_add_admin_notice('CSRF 验证失败或令牌过期，操作未执行。请刷新页面后重试。action=' . esc_html($action), 'error');
}

// 确保每次渲染前读取最新配置（防止在本请求早期已有实例化的情况下显示旧值）
$registry->init();
$supported_languages = $registry->get_languages(true);
// 获取按层级排序的语言列表
$hierarchical_supported_languages = $registry->get_languages_hierarchical(true);

// 新增：可用主题与语言-主题映射
$themes_available = get_themes();
// 当前系统默认主题（目录名）
$default_active_theme_stylesheet = CMS_Theme::get_active_theme()->get_stylesheet();
$language_theme_map = get_option($language_theme_map_option_key, null);
if ($language_theme_map === null) {
    $language_theme_map = get_option('language_theme_map', array());
}
if (is_string($language_theme_map)) {
    $decoded = json_decode($language_theme_map, true);
    $language_theme_map = is_array($decoded) ? $decoded : array();
}
if (!is_array($language_theme_map)) {
    $language_theme_map = array();
}

// 预加载所有站点与语言-站点关联数据（供前端站点关联弹窗使用）
$all_sites_for_modal = array();
if (function_exists('todo_sites_table_exists') && todo_sites_table_exists()) {
    $all_sites_raw = todo_get_sites(array('status' => 'active'));
    foreach ($all_sites_raw as $s) {
        $all_sites_for_modal[] = array(
            'id' => (int) $s->id,
            'name' => $s->name,
            'slug' => $s->slug,
        );
    }
}
// 获取所有语言的站点关联信息
$language_site_associations = array();
$all_langs = $registry->get_languages(false);
foreach ($all_langs as $code => $info) {
    $language_site_associations[$code] = array(
        'site_ids' => isset($info['site_ids']) && is_array($info['site_ids']) ? array_map('intval', $info['site_ids']) : array(),
        'default_site_id' => isset($info['default_site_id']) ? intval($info['default_site_id']) : 0,
    );
}

// 语言统计（按映射主题域进行统计）
// 确保数据库连接对象已初始化（避免 Undefined variable: tododb）
global $tododb;
if (!isset($tododb) || !is_object($tododb)) {
    $tododb = new CMS_DB();
    $GLOBALS['tododb'] = $tododb;
}
$language_stats = array();
try {
    // 使用层级排序的语言列表来生成统计
    foreach ($hierarchical_supported_languages as $code => $info) {
        // 规范化 locale 作为语言代码键
        $locale = isset($info['locale']) ? $info['locale'] : $code;
        $locNorm = str_replace('_', '-', $locale);
        if (function_exists('canonical_locale')) {
            $locNorm = canonical_locale($locNorm);
        }

        // 确定语言映射主题：优先 language_theme_map 其后 info['theme']，最后系统默认主题
        $candidate_theme = '';
        if (isset($language_theme_map[$locNorm]) && $language_theme_map[$locNorm] !== '') {
            $candidate_theme = preg_replace('/[^A-Za-z0-9_-]/', '', $language_theme_map[$locNorm]);
        } elseif (!empty($info['theme'])) {
            $candidate_theme = preg_replace('/[^A-Za-z0-9_-]/', '', $info['theme']);
        }
        if ($candidate_theme === '') {
            $candidate_theme = $default_active_theme_stylesheet;
        }

        // 统计该主题域下的词汇总数（兼容历史：theme 为空时按 file_path 推断）
        $where_theme = "(lt.theme = ? OR (lt.theme IS NULL AND lt.file_path LIKE ?))";
        $total_terms = (int) $tododb->get_var(
            "SELECT COUNT(*) FROM {$tododb->prefix}language_terms lt WHERE $where_theme",
            [$candidate_theme, '%/content/themes/' . $candidate_theme . '/%']
        );

        // 统计该语言在该主题域下已存在译文的词汇数量（按 term 去重）
        $translated_terms = 0;
        if ($total_terms > 0) {
            $translated_terms = (int) $tododb->get_var(
                "SELECT COUNT(DISTINCT lt.id)
                   FROM {$tododb->prefix}language_terms lt
                   INNER JOIN {$tododb->prefix}language_translations t
                           ON t.term_id = lt.id
                          AND t.language_code = ?
                          AND t.translated_text IS NOT NULL AND t.translated_text != ''
                  WHERE $where_theme",
                [$locNorm, $candidate_theme, '%/content/themes/' . $candidate_theme . '/%']
            );
        }

        $pending_terms = max(0, $total_terms - $translated_terms);
        $completion_rate = ($total_terms > 0) ? round(($translated_terms / $total_terms) * 100, 2) : 0;

        $language_stats[$locNorm] = array(
            'language_info' => array(
                'title' => isset($info['title']) ? $info['title'] : $locNorm,
                'native_name' => isset($info['native_name']) ? $info['native_name'] : (isset($info['title']) ? $info['title'] : $locNorm),
            ),
            'completion_rate' => $completion_rate,
            'translated_terms' => $translated_terms,
            'pending_terms' => $pending_terms,
            'total_terms' => $total_terms,
            'theme' => $candidate_theme,
        );
    }
} catch (Exception $e) {
    // 出错时保底占位，避免页面崩溃
    foreach ($supported_languages as $code => $info) {
        $language_stats[$code] = array(
            'language_info' => array('title' => isset($info['title']) ? $info['title'] : $code),
            'completion_rate' => 0,
            'translated_terms' => 0,
            'pending_terms' => 0,
            'total_terms' => 0,
        );
    }
}
?>
<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title verse">“儿童相见不相识，笑问客从何处来。”<span>——贺知章《回乡偶书》</span></h2>
        <div class="admin-page-actions">
            <a href="<?php echo todo_admin_url('index.php?page=language-translations'); ?>" class="button button-secondary">全部词汇翻译</a>
        </div>
    </div>

    <!-- 语言统计概览 -->
    <div class="todo-stats-grid">
        <?php
        // 按层级排序显示统计
        foreach ($hierarchical_supported_languages as $code => $info):
            if (!isset($language_stats[$code]))
                continue;
            $stats = $language_stats[$code];
            $level = isset($info['_level']) ? (int) $info['_level'] : 0;
            $display_title = $level > 0 ? str_repeat('-', $level) . ' ' . (isset($stats['language_info']['native_name']) ? $stats['language_info']['native_name'] : (isset($stats['language_info']['title']) ? $stats['language_info']['title'] : $code)) : (isset($stats['language_info']['native_name']) ? $stats['language_info']['native_name'] : (isset($stats['language_info']['title']) ? $stats['language_info']['title'] : $code));
            ?>
            <div class="todo-stat-item">
                <div class="todo-stat-header">
                    <h4><?php echo esc_html($display_title); ?>
                    </h4>
                    <span class="todo-stat-code"><?php echo esc_html($code); ?></span>
                    <a href="javascript:void(0);" onclick="openCurrencyModal('<?php echo esc_js($code); ?>', <?php echo ($code === $registry->get_default_locale() || str_replace('_', '-', $registry->get_default_locale()) === str_replace('_', '-', $code)) ? 'true' : 'false'; ?>, '<?php echo esc_js($info['currency_code'] ?? ''); ?>', '<?php echo esc_js($info['currency_exchange_rate'] ?? 1); ?>', <?php echo isset($info['currency_status']) ? (int)$info['currency_status'] : 1; ?>, <?php echo isset($info['is_default_currency']) ? (int)$info['is_default_currency'] : 0; ?>, '<?php echo esc_js(get_option('currency_symbol_mode', 'b2c')); ?>')" class="btn button-link" style="padding: 2px 6px;">币</a>
                    <a href="javascript:void(0);" onclick="openSiteModal('<?php echo esc_js($code); ?>')" class="btn button-link" style="padding: 2px 6px;">站</a>
                </div>
                <div class="todo-stat-body">
                    <div class="todo-progress">
                        <div class="todo-progress-bar" style="width: <?php echo $stats['completion_rate']; ?>%"></div>
                    </div>
                    <div class="todo-stat-details">
                        <span class="description m-0">完成度: <b><?php echo $stats['completion_rate']; ?>%</b></span>
                        <span class="description m-0">总词汇: <b><?php echo $stats['total_terms']; ?></b></span>
                        <span class="description m-0">已翻译: <b><?php echo $stats['translated_terms']; ?></b></span>
                        <span class="description m-0">待翻译: <b><?php echo $stats['pending_terms']; ?></b></span>
                    </div>
                </div>
                <div class="flex items-center justify-between">
                    <p class="description m-0">映射主题
                        <?php $mapped_theme_slug = isset($language_theme_map[$code]) ? $language_theme_map[$code] : $default_active_theme_stylesheet; ?>
                        <a href="<?php echo esc_url(todo_admin_url('index.php?page=language-template-detail&theme=' . urlencode($mapped_theme_slug))); ?>" class="status-active m-l-05"><?php echo esc_html($mapped_theme_slug); ?></a>
                    </p>
                    <a href="<?php echo todo_admin_url('index.php?page=language-terms&lang=' . $code); ?>" class="btn button-link">词汇管理</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- 语言列表与状态管理 -->
    <div class="admin-card">
        <div class="admin-card-header no-border">
            <h3 class="admin-card-title">语言列表与状态管理</h3>
        </div>
        <div class="admin-card-content">
            <p class="description m-0">当前默认语言只能映射默认主题且不可编辑，如需切换默认主题请在右上角“主题”中进行管理。</p>
            <p class="description">前缀可以生成地址 .com/{前缀}/，默认语言要映射到主域名请前缀留空。</p>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th style="width: 80px;">排序</th>
                        <th>标记</th>
                        <th>Locale</th>
                        <th style="min-width: 100px;">标题</th>
                        <th>前缀</th>
                        <th>域名</th>
                        <th>语言映射主题</th>
                        <th>启用</th>
                        <th style="min-width: 180px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // 获取按层级排序的语言列表
                    $hierarchical_languages = $registry->get_languages_hierarchical(false);
                    foreach ($hierarchical_languages as $loc => $info):
                        $level = isset($info['_level']) ? (int) $info['_level'] : 0;
                        $display_title = $level > 0 ? str_repeat('-', $level) . ' ' . $info['title'] : $info['title'];
                        $locNorm = str_replace('_', '-', $info['locale']);
                        if (function_exists('canonical_locale')) {
                            $locNorm = canonical_locale($locNorm);
                        }
                        $domain_value = isset($info['domain']) ? $info['domain'] : '';
                        $default_locale = $registry->get_default_locale();
                        $is_default_lang = (!empty($info['is_default']) || $info['locale'] === $default_locale || str_replace('_', '-', $default_locale) === $locNorm);
                        ?>
                        <tr>
                            <td>
                                <input type="number" name="sort_order"
                                    form="lang-row-<?php echo esc_attr($info['locale']); ?>"
                                    value="<?php echo esc_attr(isset($info['sort_order']) ? intval($info['sort_order']) : 0); ?>"
                                    style="min-width: unset;width: 60px;" min="0">
                            </td>
                            <td style="max-width: 55px;"><?php echo esc_html($info['mark']); ?></td>
                            <td style="font-size: 13px; max-width: 100px;"><?php echo esc_html($info['locale']); ?></td>
                            <td style="max-width: 250px;">
                                <?php echo esc_html($display_title); ?>
                            </td>
                            <td>
                                <input type="text" name="path_prefix"
                                    form="lang-row-<?php echo esc_attr($info['locale']); ?>"
                                    value="<?php echo $is_default_lang ? '' : esc_attr($info['path_prefix'] ?? ''); ?>"
                                    placeholder="<?php echo $is_default_lang ? '无前缀' : '如 ' . esc_attr($info['mark']); ?>"
                                    <?php echo $is_default_lang ? 'disabled title="默认语言使用根路径，无路径前缀"' : ''; ?>
                                    style="min-width: unset;width: 60px;">
                            </td>
                            <td>
                                <input type="text" name="domain" form="lang-row-<?php echo esc_attr($info['locale']); ?>"
                                    value="<?php echo esc_attr($domain_value); ?>" placeholder="如 en.example.com"
                                    style="min-width: unset;width: 160px;">
                            </td>
                            <td style="max-width: 300px;">
                                <?php if (!$is_default_lang): ?>
                                    <select name="theme" form="lang-row-<?php echo esc_attr($info['locale']); ?>"
                                        style="min-width: unset;width: 150px;" <?php echo !empty($info['enabled']) ? '' : 'disabled'; ?>>
                                        <option value="" <?php echo (!isset($language_theme_map[$locNorm]) || $language_theme_map[$locNorm] === $default_active_theme_stylesheet) ? 'selected' : ''; ?>>默认主题</option>
                                        <?php foreach ($themes_available as $tn => $tinfo): ?>
                                            <?php if ($tn === $default_active_theme_stylesheet) {
                                                continue;
                                            } // 隐藏当前系统默认主题，避免与“默认主题”重复 ?>
                                            <option value="<?php echo esc_attr($tn); ?>" <?php echo (isset($language_theme_map[$locNorm]) && $language_theme_map[$locNorm] === $tn) ? 'selected' : ''; ?>>
                                                <?php echo esc_html(isset($tinfo['title']) ? $tinfo['title'] : $tn); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <?php
                                    $default_theme_name = isset($themes_available[$default_active_theme_stylesheet]['title']) ? $themes_available[$default_active_theme_stylesheet]['title'] : $default_active_theme_stylesheet;
                                    ?>
                                    <span class="btn button-success"
                                        style="min-width: 150px;">默认主题：<br><?php echo esc_html($default_theme_name); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <select name="enabled" form="lang-row-<?php echo esc_attr($info['locale']); ?>"
                                    style="min-width: unset;width: 70px;">
                                    <option value="1" <?php selected(!empty($info['enabled']), true); ?>>启用</option>
                                    <option value="0" <?php selected(!empty($info['enabled']), false); ?>     <?php echo !empty($info['is_default']) ? 'disabled' : ''; ?>>禁用</option>
                                </select>
                            </td>
                            <td style="position: relative;">
                                <?php if (empty($info['is_default'])): ?>
                                    <form method="post" style="display: inline-block;">
                                        <?php echo Todo_Security_Manager::csrf_field('language_management'); ?>
                                        <input type="hidden" name="action" value="set_default_locale">
                                        <input type="hidden" name="locale" value="<?php echo esc_attr($info['locale']); ?>">
                                        <button type="submit" class="btn button-secondary">设为默认</button>
                                    </form>
                                <?php else: ?>
                                    <span class="btn button-success" style="min-width: 112px;">前台默认语言</span>
                                <?php endif; ?>
                                <form method="post" id="lang-row-<?php echo esc_attr($info['locale']); ?>"
                                    style="display:inline-block;">
                                    <?php echo Todo_Security_Manager::csrf_field('language_management'); ?>
                                    <input type="hidden" name="action" value="update_language_row">
                                    <input type="hidden" name="locale" value="<?php echo esc_attr($info['locale']); ?>">
                                    <input type="hidden" name="sort_order" value="">
                                    <input type="hidden" name="path_prefix" value="">
                                    <input type="hidden" name="domain" value="">
                                    <input type="hidden" name="theme" value="">
                                    <input type="hidden" name="enabled" value="">
                                    <button type="submit" class="btn button-secondary">✓</button>
                                </form>
                                <?php if (empty($info['is_default'])): ?>
                                    <form method="post" style="display: inline-block;">
                                        <?php echo Todo_Security_Manager::csrf_field('language_management'); ?>
                                        <input type="hidden" name="action" value="delete_language">
                                        <input type="hidden" name="locale" value="<?php echo esc_attr($info['locale']); ?>">
                                        <button type="submit" class="btn button-secondary" title="删除"
                                            onclick="return TodoCMS.Utils.confirmAction(this, '确认删除该语言？')">✗</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <script type="text/javascript">
                document.addEventListener('DOMContentLoaded', function () {
                    document.querySelectorAll('form[id^="lang-row-"]').forEach(function (form) {
                        form.addEventListener('submit', function () {
                            var id = form.id;
                            var find = function (name) {
                                return document.querySelector('[name="' + name + '"][form="' + id + '"]');
                            };
                            var set = function (name, value) {
                                var hidden = form.querySelector('input[name="' + name + '"]');
                                if (hidden) hidden.value = value;
                            };
                            var sortOrder = find('sort_order'); if (sortOrder) set('sort_order', sortOrder.value);
                            var path = find('path_prefix'); if (path) set('path_prefix', path.value);
                            var domain = find('domain'); if (domain) set('domain', domain.value);
                            var theme = find('theme'); if (theme) set('theme', theme.value);
                            var enabled = find('enabled'); if (enabled) set('enabled', enabled.value);
                        });
                    });
                });
            </script>
        </div>
    </div>

    <!-- 路由模式设置 -->
    <div class="admin-card">
        <div class="admin-card-header no-border">
            <h3 class="admin-card-title">路由模式设置</h3>
        </div>
        <div class="admin-card-content">
            <form method="post">
                <?php echo Todo_Security_Manager::csrf_field('language_management'); ?>
                <input type="hidden" name="action" value="set_routing_mode">
                <div class="form-group">
                    <div class="flex gap-2 flex-wrap">
                        <select name="routing_mode" id="routing_mode">
                            <option value="path_prefix" <?php selected($registry->get_routing_mode(), 'path_prefix'); ?>>
                                路径前缀模式（如 /zh/）</option>
                            <option value="domain" <?php selected($registry->get_routing_mode(), 'domain'); ?>>独立域名模式
                            </option>
                        </select>
                        <button type="submit" class="button button-secondary">设置路由</button>
                    </div>
                    <p class="description">说明：路径前缀模式将在URL添加语言前缀；独立域名模式自动在 languages.php 为每种语言配置域名。</p>
                </div>
            </form>
        </div>
    </div>

    <!-- 默认与回退语言设置 -->
    <div class="admin-card">
        <div class="admin-card-header no-border">
            <h3 class="admin-card-title">默认与回退语言设置</h3>
        </div>
        <div class="admin-card-content">
            <form method="post">
                <?php echo Todo_Security_Manager::csrf_field('language_management'); ?>
                <input type="hidden" name="action" value="set_default_locale">
                <div class="form-group">
                    <div class="flex gap-2 flex-wrap">
                        <select name="locale" id="default_locale">
                            <?php
                            $hierarchical_langs = $registry->get_languages_hierarchical(false);
                            foreach ($hierarchical_langs as $loc => $info):
                                $level = isset($info['_level']) ? (int) $info['_level'] : 0;
                                $display_title = $level > 0 ? str_repeat('-', $level) . ' ' . $info['title'] : $info['title'];
                                ?>
                                <option value="<?php echo esc_attr($info['locale']); ?>" <?php selected($registry->get_default_locale(), $info['locale']); ?>>
                                    <?php echo esc_html($display_title); ?> (<?php echo esc_html($info['locale']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button button-secondary">设置默认语言</button>
                    </div>
                </div>
            </form>

            <form method="post">
                <?php echo Todo_Security_Manager::csrf_field('language_management'); ?>
                <input type="hidden" name="action" value="set_fallback_locale">
                <div class="form-group">
                    <div class="flex gap-2 flex-wrap">
                        <select name="locale" id="fallback_locale">
                            <?php
                            $hierarchical_langs = $registry->get_languages_hierarchical(false);
                            foreach ($hierarchical_langs as $loc => $info):
                                $level = isset($info['_level']) ? (int) $info['_level'] : 0;
                                $display_title = $level > 0 ? str_repeat('-', $level) . ' ' . $info['title'] : $info['title'];
                                ?>
                                <option value="<?php echo esc_attr($info['locale']); ?>" <?php selected($registry->get_fallback_locale(), $info['locale']); ?>>
                                    <?php echo esc_html($display_title); ?> (<?php echo esc_html($info['locale']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button button-secondary">设置回退语言</button>
                    </div>
                    <p class="description">说明：默认语言用于无语言上下文时；回退语言用于指定语言缺少内容时的回退。</p>
                </div>
            </form>
        </div>
    </div>

    <!-- 新增语言 -->
    <div class="admin-card">
        <div class="admin-card-header no-border">
            <h3 class="admin-card-title">新增语言</h3>
        </div>
        <div class="admin-card-content">
            <form method="post">
                <?php echo Todo_Security_Manager::csrf_field('language_management'); ?>
                <input type="hidden" name="action" value="add_language">
                <div class="form-group">
                    <label for="new_mark">标记：</label>
                    <input type="text" name="mark" id="new_mark" placeholder="如 zh、en" style="width:100px">
                </div>
                <div class="form-group">
                    <label for="new_locale">Locale：<span class="description">严格统一如 zh-CN 样式</span></label>
                    <input type="text" name="locale" id="new_locale" placeholder="如 zh-CN、en-US" style="width:140px">
                </div>
                <div class="form-group">
                    <label for="new_title">标题：</label>
                    <input type="text" name="title" id="new_title" placeholder="显示名称" style="width:160px">
                </div>
                <div class="form-group">
                    <label for="new_prefix">前缀：</label>
                    <input type="text" name="path_prefix" id="new_prefix" placeholder="如 zh、en" style="width:120px">
                </div>
                <div class="form-group">
                    <label for="new_domain">域名：</label>
                    <input type="text" name="domain" id="new_domain" placeholder="如 en.example.com" style="width:160px">
                </div>
                <div class="form-group">
                    <label for="new_parent_id">父级：<span class="description">非必填</span></label>
                    <select name="parent_id" id="new_parent_id" style="width:200px;">
                        <option value="">无（根级语言）</option>
                        <?php
                        // 获取按层级排序的所有语言用于选择父级
                        $all_langs_hierarchical = $registry->get_languages_hierarchical(false);
                        foreach ($all_langs_hierarchical as $lang_loc => $lang_info):
                            $lang_level = isset($lang_info['_level']) ? (int) $lang_info['_level'] : 0;
                            // 只允许选择层级小于4级的语言作为父级（因为新增的语言会是子级，所以父级最多4级）
                            if ($lang_level < 4):
                                $lang_display = $lang_level > 0 ? str_repeat('-', $lang_level) . ' ' . $lang_info['title'] : $lang_info['title'];
                                ?>
                                <option value="<?php echo esc_attr($lang_loc); ?>">
                                    <?php echo esc_html($lang_display . ' (' . $lang_loc . ')'); ?>
                                </option>
                                <?php
                            endif;
                        endforeach;
                        ?>
                    </select>
                    <p class="description" style="margin-top:5px;">选择父级语言后，该语言将作为子语言显示，最多支持5级层级。</p>
                </div>
                <div class="form-group">
                    <label for="new_enabled">启用：</label>
                    <select name="enabled" id="new_enabled">
                        <option value="1">启用</option>
                        <option value="0">禁用</option>
                    </select>
                </div>
                <p class="description">注意：当路由模式为 "域名" 时，优先使用域名映射；当为 "路径前缀" 时，优先使用前缀映射。</p>
                <div class="form-submit">
                    <button type="submit" class="button button-primary">保存语言</button>
                </div>
            </form>
        </div>
    </div>

</div>

<style>
    .todo-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .todo-stat-item {
        border-radius: 3px;
        padding: 10px 20px;
        background: #fff;
        box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
    }

    .todo-stat-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        margin-bottom: 10px;
    }

    .todo-stat-header h4 {
        margin: 0;
        color: #282832;
    }

    .todo-stat-code {
        margin-left: auto;
        background: #efeff5;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 12px;
        color: #6c6c75;
    }

    .todo-progress {
        width: 100%;
        height: 8px;
        background: #efeff5;
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 10px;
    }

    .todo-progress-bar {
        height: 100%;
        background: linear-gradient(90deg, #4CAF50, #8BC34A);
        transition: width 0.3s ease;
    }

    .todo-stat-details {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        font-size: 14px;
        color: #6c6c75;
        margin-bottom: 10px;
    }

    .todo-ajax-notice.button-success {
        position: absolute;
        left: 10px;
        top: 50%;
        margin-top: -13px;
        line-height: 17px;
    }

    .todo-ajax-notice.button-error {
        margin-top: 10px;
    }
</style>

<?php
$todo_language_form_action = todo_admin_url('index.php?page=language');
$todo_language_iframe_mode = function_exists('todo_is_iframe_mode') && todo_is_iframe_mode();
if ($todo_language_iframe_mode) {
    $todo_language_form_action = add_query_arg('format', 'iframe', $todo_language_form_action);
}
$todo_language_form_target = $todo_language_iframe_mode ? 'todo-admin-iframe' : '';
?>
<template id="todo-tpl-currency-modal">
    <div data-slot="title">货币设置</div>
    <div data-slot="body">
        <form method="post" id="currencyForm" action="<?php echo esc_url($todo_language_form_action); ?>"<?php echo $todo_language_form_target ? ' target="' . esc_attr($todo_language_form_target) . '"' : ''; ?>>
            <?php echo Todo_Security_Manager::csrf_field('language_management'); ?>
            <input type="hidden" name="action" value="update_language_currency">
            <input type="hidden" name="locale" id="currency_locale" value="">
            
            <table class="form-table w-120">
                <tr>
                    <th>货币名称</th>
                    <td>
                        <select name="currency_code" id="currency_code" class="regular-text">
                            <option value="">请选择</option>
                            <?php
                            $all_currencies = function_exists('todo_get_currencies_list') ? todo_get_currencies_list() : [];
                            foreach ($all_currencies as $c_code => $c_info) {
                                $c_label = sprintf('%s (%s/%s)', $c_info['name'], $c_code, $c_info['symbol']);
                                echo '<option value="' . esc_attr($c_code) . '">' . esc_html($c_label) . '</option>';
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>汇率值</th>
                    <td>
                        <input type="number" step="0.0001" name="currency_exchange_rate" id="currency_exchange_rate" value="1" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th>货币状态</th>
                    <td>
                        <label style="margin-right: 15px;"><input type="radio" name="currency_status" value="1" checked> 开启</label>
                        <label><input type="radio" name="currency_status" value="0"> 关闭</label>
                    </td>
                </tr>
                <tr>
                    <th>设为默认</th>
                    <td>
                        <label style="margin-right: 15px;"><input type="radio" name="is_default_currency" value="1"> 是</label>
                        <label><input type="radio" name="is_default_currency" value="0" checked> 否</label>
                        <p class="description" style="color:#d32f2f;">注：默认货币汇率必须为1</p>
                    </td>
                </tr>
                <tr id="currency_symbol_mode_row" style="display: none;">
                    <th>符号特征</th>
                    <td>
                        <label style="margin-right: 15px;"><input type="radio" name="currency_symbol_mode" value="b2c" checked> B2C <span class="description">(¥ / $)</span></label>
                        <label><input type="radio" name="currency_symbol_mode" value="b2b"> B2B <span class="description">(CNY / USD)</span></label>
                        <p class="description">B2C 统一使用货币符号，B2B 统一使用 ISO 代码规范。</p>
                    </td>
                </tr>
            </table>
        </form>
    </div>
    <div data-slot="footer">
        <button type="button" class="button button-secondary todo-modal-cancel">取消</button>
        <button type="submit" form="currencyForm" class="button button-primary">保存设置</button>
    </div>
</template>

<script>
function openCurrencyModal(locale, isDefault, currencyCode, exchangeRate, currencyStatus, isDefaultCurrency, currencySymbolMode) {
    const modal = new TodoModal({
        templateId: 'todo-tpl-currency-modal',
        destroyOnClose: true
    });
    
    const form = modal.element.querySelector('#currencyForm');
    
    // 提交前解除禁用，确保字段被序列化，提交后异步关闭弹窗
    form.addEventListener('submit', function() {
        form.querySelectorAll(':disabled').forEach(el => el.disabled = false);
        setTimeout(function() { try { modal.close(); } catch(e){} }, 0);
    });

    form.querySelector('#currency_locale').value = locale;
    form.querySelector('#currency_code').value = currencyCode || '';
    form.querySelector('#currency_exchange_rate').value = exchangeRate || '1';
    
    form.querySelectorAll('input[name="currency_status"]').forEach(r => r.checked = (r.value == (currencyStatus !== undefined ? currencyStatus : 1)));
    form.querySelectorAll('input[name="is_default_currency"]').forEach(r => r.checked = (r.value == (isDefaultCurrency !== undefined ? isDefaultCurrency : 0)));

    const symbolModeRow = form.querySelector('#currency_symbol_mode_row');
    const updateSymbolModeVisibility = function() {
        const isDef = form.querySelector('input[name="is_default_currency"]:checked');
        if (symbolModeRow) {
            symbolModeRow.style.display = (isDefault || (isDef && isDef.value == '1')) ? '' : 'none';
        }
    };
    form.querySelectorAll('input[name="is_default_currency"]').forEach(r => {
        r.addEventListener('change', updateSymbolModeVisibility);
    });
    const curMode = currencySymbolMode || 'b2c';
    form.querySelectorAll('input[name="currency_symbol_mode"]').forEach(r => r.checked = (r.value === curMode));
    updateSymbolModeVisibility();

    if (isDefault) {
        form.querySelector('#currency_exchange_rate').value = '1';
        form.querySelector('#currency_exchange_rate').readOnly = true;
        form.querySelectorAll('input[name="is_default_currency"]').forEach(r => {
            if (r.value == '1') r.checked = true;
            r.disabled = true;
        });
        form.querySelectorAll('input[name="currency_status"]').forEach(r => {
            if (r.value == '1') r.checked = true;
            r.disabled = true;
        });
    } else {
        form.querySelector('#currency_exchange_rate').readOnly = false;
        form.querySelectorAll('input[name="is_default_currency"]').forEach(r => {
            r.disabled = false;
        });
        form.querySelectorAll('input[name="currency_status"]').forEach(r => {
            r.disabled = false;
        });
    }
    
    modal.open();
}

// ======================== 预加载站点关联数据 ========================
var TODO_SITE_ASSOCIATION_DATA = {
    allSites: <?php echo json_encode($all_sites_for_modal, JSON_UNESCAPED_UNICODE); ?>,
    associations: <?php echo json_encode($language_site_associations, JSON_UNESCAPED_UNICODE); ?>
};
</script>

<template id="todo-tpl-site-modal">
    <div data-slot="title">站点关联设置</div>
    <div data-slot="body">
        <form method="post" id="siteAssociationForm" action="<?php echo esc_url($todo_language_form_action); ?>"<?php echo $todo_language_form_target ? ' target="' . esc_attr($todo_language_form_target) . '"' : ''; ?>>
            <?php echo Todo_Security_Manager::csrf_field('language_management'); ?>
            <input type="hidden" name="action" value="update_language_site_association">
            <input type="hidden" name="locale" id="site_assoc_locale" value="">
            <input type="hidden" name="default_site_id" id="site_assoc_default_site_id" value="">

            <table class="form-table w-120">
                <tr>
                    <th>关联站点</th>
                    <td style="min-width: 180px;">
                        <div class="site-checklist" style="max-height:260px;overflow-y:auto;border:1px solid #eceef1;padding:5px 10px 0;border-radius:3px;">
                            <?php if (empty($all_sites_for_modal)): ?>
                                <p class="description">暂无站点，请先在「站点管理」中创建站点。</p>
                            <?php else: foreach ($all_sites_for_modal as $_s): ?>
                                <label style="display:flex;align-items:center;cursor:pointer;">
                                    <input type="checkbox" name="site_ids[]" value="<?php echo (int)$_s['id']; ?>" class="site-checkbox m-0">
                                    <span><?php echo esc_html($_s['name']); ?> <em style="display: inline-block;font-size: 12px;color: #999;padding: 0 5px;background-color: #f1f1f1;"><?php echo esc_html('site'.$_s['id']); ?></em></span>
                                    <span class="default-badge status-active" style="display:none;">默认</span>
                                </label>
                            <?php endforeach; endif; ?>
                        </div>
                    </td>
                </tr>
                <tr id="default-site-row" style="display:none;">
                    <th>设为默认站点</th>
                    <td>
                        <select name="default_site_select" id="site_assoc_default_select" class="regular-text">
                            <option value="">-- 请选择 --</option>
                        </select>
                        <p class="description">一种语言下只能有一个默认站点，前台切换语言时默认显示。</p>
                    </td>
                </tr>
            </table>
        </form>
    </div>
    <div data-slot="footer">
        <button type="button" class="button button-secondary todo-modal-cancel">取消</button>
        <button type="submit" form="siteAssociationForm" class="button button-primary">保存设置</button>
    </div>
</template>

<script>
function openSiteModal(locale) {
    const modal = new TodoModal({
        templateId: 'todo-tpl-site-modal',
        destroyOnClose: true
    });

    const form = modal.element.querySelector('#siteAssociationForm');

    // 提交前同步 default_site_id，提交后异步关闭弹窗
    form.addEventListener('submit', function() {
        const sel = modal.element.querySelector('#site_assoc_default_select');
        if (sel) {
            modal.element.querySelector('#site_assoc_default_site_id').value = sel.value;
        }
        setTimeout(function() { try { modal.close(); } catch(e){} }, 0);
    });

    // 填入 locale
    modal.element.querySelector('#site_assoc_locale').value = locale;

    // 读取该语言已有数据
    var assoc = TODO_SITE_ASSOCIATION_DATA.associations[locale] || { site_ids: [], default_site_id: 0 };
    var siteIds = assoc.site_ids.map(function(v) { return parseInt(v); });
    var defaultSiteId = parseInt(assoc.default_site_id) || 0;

    // 设置 hidden default_site_id
    modal.element.querySelector('#site_assoc_default_site_id').value = defaultSiteId;

    // 勾选已关联站点 & 显示默认徽标 & 绑定事件
    var labels = modal.element.querySelectorAll('.site-checklist label');
    labels.forEach(function(label) {
        var cb = label.querySelector('.site-checkbox');
        var badge = label.querySelector('.default-badge');
        if (!cb) return;
        var sid = parseInt(cb.value);
        cb.checked = (siteIds.indexOf(sid) !== -1);
        if (badge) {
            badge.style.display = (sid === defaultSiteId) ? '' : 'none';
        }
        cb.addEventListener('change', function() { onSiteCheckboxChange(modal); });
    });

    // 构建默认站点下拉
    onSiteCheckboxChange(modal);

    // 预选当前默认站点
    if (defaultSiteId > 0) {
        var sel = modal.element.querySelector('#site_assoc_default_select');
        if (sel) sel.value = String(defaultSiteId);
    }

    modal.open();
}

function onSiteCheckboxChange(modal) {
    var el = modal.element;
    var defaultRow = el.querySelector('#default-site-row');
    var defaultSelect = el.querySelector('#site_assoc_default_select');
    var hiddenInput = el.querySelector('#site_assoc_default_site_id');

    if (!defaultRow || !defaultSelect) return;

    var checked = el.querySelectorAll('.site-checkbox:checked');

    if (checked.length > 0) {
        defaultRow.style.display = '';

        var currentVal = hiddenInput ? hiddenInput.value : '';
        var optionsHtml = '<option value="">-- 请选择 --</option>';
        var labels = el.querySelectorAll('.site-checklist label');
        labels.forEach(function(label) {
            var cb = label.querySelector('.site-checkbox');
            if (cb && cb.checked) {
                var nameEl = label.querySelector('span');
                var name = nameEl ? nameEl.textContent.replace(/\s*\(.*\)\s*$/, '').trim() : ('站点 ' + cb.value);
                var sel = (cb.value == currentVal) ? ' selected' : '';
                optionsHtml += '<option value="' + cb.value + '"' + sel + '>' + name + '</option>';
            }
        });
        defaultSelect.innerHTML = optionsHtml;

        // 如果当前值不在新选项中，清空
        if (hiddenInput && !defaultSelect.querySelector('option[value="' + hiddenInput.value + '"]')) {
            hiddenInput.value = '';
        }

        // 如果用户未选择默认站点，则取第一个关联站点作为默认
        if (!defaultSelect.value && checked.length > 0) {
            var firstSiteId = checked[0].value;
            defaultSelect.value = firstSiteId;
            if (hiddenInput) hiddenInput.value = firstSiteId;
        }
    } else {
        defaultRow.style.display = 'none';
    }
}
</script>
<script type="text/javascript">
    // 确保TodoCMS对象存在，提供后备方案
    if (typeof window.TodoCMS === 'undefined') {
        window.TodoCMS = {};
    }
    if (typeof window.TodoCMS.Utils === 'undefined') {
        window.TodoCMS.Utils = {};
    }
    (function () {
        const AJAX_URL =
            "<?php echo defined('ADMIN_URL') ? ADMIN_URL : rtrim(todo_admin_url(), '/'); ?>/ajax/language/save-config.php";
        const CSRF_REFRESH_URL =
            "<?php echo defined('ADMIN_URL') ? ADMIN_URL : rtrim(todo_admin_url(), '/'); ?>/ajax/csrf-refresh.php";
        async function refreshNonce(action) {
            const fd2 = new FormData();
            fd2.append('csrf_action', action);
            const r = await fetch(CSRF_REFRESH_URL, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: fd2,
                credentials: 'same-origin'
            });
            const data = await r.json();
            if (data && data.success && data.token) { return data.token; }
            throw new Error('CSRF刷新失败');
        }
        function updateAllNonce(token) {
            document.querySelectorAll('input[name="_todononce"]').forEach(el => { el.value = token; });
        }
        const INTERCEPT_ACTIONS = new Set([
            'update_language_status', 'set_default_locale', 'set_fallback_locale', 'set_routing_mode',
            'update_language_mapping', 'delete_language', 'add_language', 'update_language_row'
        ]);

        function showNotice(form, ok, msg) {
            let box = form.querySelector('.todo-ajax-notice');
            if (!box) {
                box = document.createElement('div');
                box.className = 'btn todo-ajax-notice ' + (ok ? 'button-success' : 'button-error');
                form.appendChild(box);
            }
            box.textContent = msg || (ok ? '操作成功' : '操作失败');
            box.classList.remove('button-success', 'button-error');
            box.classList.add(ok ? 'button-success' : 'button-error');
            setTimeout(() => { box.remove(); }, 3000);
        }

        async function safeFetch(url, options) {
            try {
                const resp = await fetch(url, options);
                return resp;
            } catch (err) {
                // 如果 fetch 被包装并在 403 时抛出，尝试构造一个仿真的响应对象
                if (err && (err.status === 403 || err.message.includes('403'))) {
                    return { status: 403, json: async () => ({ error: 'Invalid nonce' }) };
                }
                throw err;
            }
        }

        document.addEventListener('submit', async function (e) {
            const form = e.target;
            if (!(form instanceof HTMLFormElement)) return;
            const actionInput = form.querySelector('input[name="action"]');
            const actionName = actionInput ? actionInput.value : '';
            if (!INTERCEPT_ACTIONS.has(actionName)) return;

            e.preventDefault();
            const csrfInput = form.querySelector('input[name="_todononce"]');
            if (!csrfInput || !csrfInput.value) {
                showNotice(form, false, '安全校验缺失');
                return;
            }

            const fd = new FormData(form);
            try {
                let resp = await safeFetch(AJAX_URL, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd
                });

                if (resp.status === 403 || resp.status === 401) {
                    // 尝试刷新 token 并重试一次
                    try {
                        const newToken = await refreshNonce('language_management');
                        updateAllNonce(newToken);
                        fd.set('_todononce', newToken);
                        resp = await safeFetch(AJAX_URL, {
                            method: 'POST',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                            body: fd
                        });
                    } catch (retryErr) {
                        console.error('CSRF Refresh failed:', retryErr);
                    }
                }

                const raw = await resp.text();
                if (!raw) {
                    throw new Error('Empty response (HTTP ' + resp.status + ')');
                }
                let data;
                try {
                    data = JSON.parse(raw);
                } catch (parseErr) {
                    throw new Error('Invalid JSON (HTTP ' + resp.status + '): ' + raw.slice(0, 200));
                }
                const ok = !!data.success;
                showNotice(form, ok, data.message || (ok ? '已保存' : '操作失败'));

                if (ok) {
                    // 延迟刷新页面以展示最新状态，加入时间戳绕过缓存
                    setTimeout(() => {
                        const url = new URL(window.location.href);
                        url.searchParams.set('t', Date.now());
                        window.location.replace(url.toString());
                    }, 500);
                }
            } catch (err) {
                console.error('Config save error:', err);
                showNotice(form, false, '请求异常，请刷新重试');
            }
        });
    })();
</script>
