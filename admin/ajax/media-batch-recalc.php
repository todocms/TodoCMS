<?php
/**
 * 全量刷新所有媒体引用计数
 *
 * 优化策略：反向索引 (needle → media_ids) + 分块正则，
 * 每条文本最多 ~20 次 preg_match_all，替代几千次 stripos。
 *
 * @package TodoCMS
 */

ini_set('display_errors', 0);
error_reporting(0);

if (!defined('IS_ADMIN')) {
    define('IS_ADMIN', true);
}
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}

require_once ABSPATH . 'config.php';
require_once ABSPATH . 'core/bootstrap.php';
require_once ABSPATH . 'core/functions/url-functions.php';

if (class_exists('Todo_Session_Manager')) {
    Todo_Session_Manager::init();
}
if (!class_exists('Todo_Session_Manager') || !Todo_Session_Manager::is_admin_logged_in()) {
    http_response_code(403);
    die('权限不足');
}

// 禁止超时
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '512M');
if (function_exists('set_time_limit') && !ini_get('safe_mode')) {
    @set_time_limit(0);
}

$is_ajax = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
);

global $cms_db;
$tododb   = $cms_db ?: new CMS_DB();
$prefix   = TABLE_PREFIX;
$start_ts = microtime(true);

// ========================================
// Step 1: 加载所有媒体 → 构建反向索引
// ========================================
$all_media = $tododb->get_results(
    "SELECT media_id, media_url, media_path, media_filename FROM `{$prefix}media` ORDER BY media_id"
);
$total_media = count($all_media);

// needle_map_lower: 小写 needle → [media_id, ...]
// 用于从 preg_match_all 返回的匹配文本快速反查 media_id
$needle_map_lower = [];
foreach ($all_media as $m) {
    $mid  = (int) $m->media_id;
    $url  = (string) ($m->media_url ?? '');
    $path = (string) ($m->media_path ?? '');
    $fn   = (string) ($m->media_filename ?? '');

    $add = function($s) use ($mid, &$needle_map_lower) {
        $s = trim((string)$s);
        if ($s === '') return;
        $ls = mb_strtolower($s, 'UTF-8');
        if (!isset($needle_map_lower[$ls])) $needle_map_lower[$ls] = [];
        if (!in_array($mid, $needle_map_lower[$ls], true)) {
            $needle_map_lower[$ls][] = $mid;
        }
    };

    if ($url !== '') {
        $add($url);
        $add(basename($url));
        $p = @parse_url($url, PHP_URL_PATH);
        if ($p) { $add($p); $add(basename($p)); }
    }
    if ($path !== '') {
        $add($path);
        $add('/' . ltrim($path, '/'));
        $add(basename($path));
    }
    if ($fn !== '') $add($fn);
    $add('"id":' . $mid);
    $add('"media_id":' . $mid);
    $add('todo-image-' . $mid);
    $add('data-id="' . $mid . '"');
}

// ========================================
// Step 2: 构建分块正则
// ========================================
$all_needles = array_keys($needle_map_lower);
$regex_chunks = [];
$chunk_size = 150;
foreach (array_chunk($all_needles, $chunk_size) as $chunk) {
    $escaped = [];
    foreach ($chunk as $n) {
        $escaped[] = preg_quote($n, '#');
    }
    $regex_chunks[] = '#(' . implode('|', $escaped) . ')#iu';
}

/**
 * 分块正则匹配文本，返回命中的 media_id 集合。
 * 
 * 关键：用小写 needle 建立正则（大小写不敏感匹配），
 * preg_match_all 返回的是文本原始大小写，strtolower 后查 needle_map_lower。
 */
function match_text_chunked($text, $regex_chunks, &$needle_map_lower) {
    $found = [];
    foreach ($regex_chunks as $regex) {
        if (!@preg_match_all($regex, $text, $m)) continue;
        foreach ($m[0] as $matched) {
            $key = mb_strtolower($matched, 'UTF-8');
            if (isset($needle_map_lower[$key])) {
                foreach ($needle_map_lower[$key] as $mid) {
                    $found[$mid] = true;
                }
            }
        }
        // 已命中此 chunk，但继续扫其他 chunk（不同 chunk 含不同 needle）
    }
    return $found;
}

// ========================================
// Step 3: DB 工具
// ========================================
function _t_exists($tododb, $short) {
    static $cache = [];
    if (isset($cache[$short])) return $cache[$short];
    try {
        $r = $tododb->get_results(
            "SHOW TABLES LIKE '" . str_replace(['_', '%'], ['\\_', '\\%'], TABLE_PREFIX . $short) . "'"
        );
        $cache[$short] = !empty($r);
        return $cache[$short];
    } catch (Throwable $e) {
        $cache[$short] = false;
        return false;
    }
}

// ========================================
// Step 4: 扫描所有表
// ========================================
$ref_posts = []; // mid => [pid => true]  按 post_id 去重
$ref_other = []; // mid => count          非文章引用

function add_post_ref(&$refs, $mid, $pid) {
    if (!isset($refs[$mid])) $refs[$mid] = [];
    $refs[$mid][(int)$pid] = true;
}
function add_other_ref(&$refs, $mid, $c = 1) {
    if (!isset($refs[$mid])) $refs[$mid] = 0;
    $refs[$mid] += $c;
}

$prefix_posts = $prefix . 'posts';
$PREF = $prefix; // shortcut

// 4a. posts
if (_t_exists($tododb, 'posts')) {
    $offset = 0; $limit = 2000;
    while (true) {
        $rows = $tododb->get_results(
            "SELECT ID, post_content, featured_image FROM `{$PREF}posts` WHERE post_status != 'trash' LIMIT {$limit} OFFSET {$offset}"
        );
        if (!$rows || !is_array($rows)) break;

        foreach ($rows as $row) {
            $pid  = (int) $row->ID;
            $found = [];

            $c = (string) ($row->post_content ?? '');
            if ($c !== '') {
                foreach (match_text_chunked($c, $regex_chunks, $needle_map_lower) as $mid => $_) {
                    $found[$mid] = true;
                }
            }

            $f = (string) ($row->featured_image ?? '');
            if ($f !== '') {
                foreach (match_text_chunked($f, $regex_chunks, $needle_map_lower) as $mid => $_) {
                    $found[$mid] = true;
                }
            }

            foreach ($found as $mid => $_) {
                add_post_ref($ref_posts, $mid, $pid);
            }
        }

        $offset += count($rows);
        if (count($rows) < $limit) break;
    }
}

// 4b. post_custom_fields
if (_t_exists($tododb, 'post_custom_fields') && _t_exists($tododb, 'posts')) {
    $offset = 0; $limit = 2000;
    while (true) {
        $rows = $tododb->get_results(
            "SELECT cf.post_id, cf.field_value FROM `{$PREF}post_custom_fields` cf
             INNER JOIN `{$PREF}posts` p ON p.ID = cf.post_id
             WHERE p.post_status != 'trash' LIMIT {$limit} OFFSET {$offset}"
        );
        if (!$rows || !is_array($rows)) break;

        foreach ($rows as $row) {
            $text = (string) ($row->field_value ?? '');
            if ($text === '') continue;
            $pid  = (int) $row->post_id;
            foreach (match_text_chunked($text, $regex_chunks, $needle_map_lower) as $mid => $_) {
                add_post_ref($ref_posts, $mid, $pid);
            }
        }

        $offset += count($rows);
        if (count($rows) < $limit) break;
    }
}

// 4c. postmeta
if (_t_exists($tododb, 'postmeta') && _t_exists($tododb, 'posts')) {
    $offset = 0; $limit = 2000;
    while (true) {
        $rows = $tododb->get_results(
            "SELECT pm.post_id, pm.meta_value FROM `{$PREF}postmeta` pm
             INNER JOIN `{$PREF}posts` p ON p.ID = pm.post_id
             WHERE p.post_status != 'trash' LIMIT {$limit} OFFSET {$offset}"
        );
        if (!$rows || !is_array($rows)) break;

        foreach ($rows as $row) {
            $text = (string) ($row->meta_value ?? '');
            if ($text === '') continue;
            $pid  = (int) $row->post_id;
            foreach (match_text_chunked($text, $regex_chunks, $needle_map_lower) as $mid => $_) {
                add_post_ref($ref_posts, $mid, $pid);
            }
        }

        $offset += count($rows);
        if (count($rows) < $limit) break;
    }
}

// 4d. system_config
if (_t_exists($tododb, 'system_config')) {
    $rows = $tododb->get_results("SELECT config_value FROM `{$PREF}system_config`");
    if ($rows && is_array($rows)) {
        foreach ($rows as $row) {
            $text = (string) ($row->config_value ?? '');
            if ($text === '') continue;
            foreach (match_text_chunked($text, $regex_chunks, $needle_map_lower) as $mid => $_) {
                add_other_ref($ref_other, $mid);
            }
        }
    }
}

// 4e. user_meta
if (_t_exists($tododb, 'user_meta')) {
    $rows = $tododb->get_results("SELECT meta_value FROM `{$PREF}user_meta`");
    if ($rows && is_array($rows)) {
        foreach ($rows as $row) {
            $text = (string) ($row->meta_value ?? '');
            if ($text === '') continue;
            foreach (match_text_chunked($text, $regex_chunks, $needle_map_lower) as $mid => $_) {
                add_other_ref($ref_other, $mid);
            }
        }
    }
}

// 4f. model_fields 动态表
try {
    $ref = new ReflectionClass('CMS_Media');
    if ($ref->hasMethod('get_model_fields_text_columns')) {
        $m = $ref->getMethod('get_model_fields_text_columns');
        $m->setAccessible(true);
        $model_tables = $m->invoke(null, $tododb);
        if (!empty($model_tables) && _t_exists($tododb, 'posts')) {
            foreach ($model_tables as $table_name => $cols) {
                if (empty($cols) || !in_array('post_id', $cols, true)) continue;
                foreach ($cols as $col) {
                    if (in_array($col, ['post_id', 'field_id', 'created_at', 'updated_at'])) continue;
                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $col)) continue;

                    $offset = 0; $limit = 2000;
                    while (true) {
                        $rows = $tododb->get_results(
                            "SELECT mf.post_id, mf.`{$col}` FROM `{$table_name}` mf
                             INNER JOIN `{$PREF}posts` p ON p.ID = mf.post_id
                             WHERE p.post_status != 'trash' LIMIT {$limit} OFFSET {$offset}"
                        );
                        if (!$rows || !is_array($rows)) break;

                        foreach ($rows as $row) {
                            $text = (string) ($row->$col ?? '');
                            if ($text === '') continue;
                            $pid  = (int) $row->post_id;
                            foreach (match_text_chunked($text, $regex_chunks, $needle_map_lower) as $mid => $_) {
                                add_post_ref($ref_posts, $mid, $pid);
                            }
                        }

                        $offset += count($rows);
                        if (count($rows) < $limit) break;
                    }
                }
            }
        }
    }
} catch (Throwable $e) {}

// 4g. links
if (_t_exists($tododb, 'links')) {
    $rows = $tododb->get_results("SELECT logo FROM `{$PREF}links`");
    if ($rows && is_array($rows)) {
        foreach ($rows as $row) {
            $text = (string) ($row->logo ?? '');
            if ($text === '') continue;
            foreach (match_text_chunked($text, $regex_chunks, $needle_map_lower) as $mid => $_) {
                add_other_ref($ref_other, $mid);
            }
        }
    }
}

// 4h. ads
if (_t_exists($tododb, 'ads')) {
    $rows = $tododb->get_results("SELECT image_url, images, content FROM `{$PREF}ads`");
    if ($rows && is_array($rows)) {
        foreach ($rows as $row) {
            $text = ((string) ($row->image_url ?? '')) . ' ' . ((string) ($row->images ?? '')) . ' ' . ((string) ($row->content ?? ''));
            if (trim($text) === '') continue;
            foreach (match_text_chunked($text, $regex_chunks, $needle_map_lower) as $mid => $_) {
                add_other_ref($ref_other, $mid);
            }
        }
    }
}

// 4i. term_attributes (for category images and other term metas)
if (_t_exists($tododb, 'term_attributes')) {
    $rows = $tododb->get_results("SELECT attributes FROM `{$PREF}term_attributes`");
    if ($rows && is_array($rows)) {
        foreach ($rows as $row) {
            $text = (string) ($row->attributes ?? '');
            if ($text === '') continue;
            foreach (match_text_chunked($text, $regex_chunks, $needle_map_lower) as $mid => $_) {
                add_other_ref($ref_other, $mid);
            }
        }
    }
}

// 4j. sites
if (_t_exists($tododb, 'sites')) {
    $rows = $tododb->get_results("SELECT * FROM `{$PREF}sites`");
    if ($rows && is_array($rows)) {
        foreach ($rows as $row) {
            $text = json_encode($row, JSON_UNESCAPED_UNICODE);
            if ($text === '') continue;
            foreach (match_text_chunked($text, $regex_chunks, $needle_map_lower) as $mid => $_) {
                add_other_ref($ref_other, $mid);
            }
        }
    }
}

// ========================================
// Step 5: 计算 usage_count
// ========================================
$usage_map = [];
foreach ($all_media as $m) {
    $mid = (int) $m->media_id;
    $pc  = isset($ref_posts[$mid]) ? count($ref_posts[$mid]) : 0;
    $oc  = isset($ref_other[$mid]) ? $ref_other[$mid] : 0;
    $cnt = $pc + $oc;
    if ($cnt > 0) $usage_map[$mid] = $cnt;
}

// ========================================
// Step 6: 批量 UPDATE
// ========================================
$media_table = $prefix . 'media';
$tododb->query("UPDATE `{$media_table}` SET usage_count = 0, is_attached = 0");

if (!empty($usage_map)) {
    foreach (array_chunk(array_keys($usage_map), 500, true) as $chunk_ids) {
        $cases = [];
        $ids   = [];
        foreach ($chunk_ids as $mid) {
            $cases[] = "WHEN {$mid} THEN " . (int)$usage_map[$mid];
            $ids[]   = $mid;
        }
        $tododb->query(
            "UPDATE `{$media_table}` SET usage_count = CASE media_id " . implode(' ', $cases)
            . " END, is_attached = 1 WHERE media_id IN (" . implode(',', $ids) . ")"
        );
    }
}

$unrecited = $total_media - count($usage_map);
$elapsed   = round(microtime(true) - $start_ts, 2);
$memory_mb = round(memory_get_peak_usage(true) / 1048576, 1);

if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode([
        'success'     => true,
        'total_media' => $total_media,
        'referenced'  => count($usage_map),
        'unrecited'   => $unrecited,
        'elapsed'     => $elapsed,
        'memory_mb'   => $memory_mb,
    ]);
    exit;
}

?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>批量刷新媒体引用计数 - TodoCMS</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    background:#f5f5f5;color:#333;padding:40px 20px;
    display:flex;justify-content:center;align-items:flex-start;min-height:100vh;
}
.card{
    background:#fff;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.08);
    padding:32px;max-width:520px;width:100%;
}
h2{font-size:18px;margin-bottom:20px;color:#1d2327;}
.done-box{
    background:#edfaef;border:1px solid #a8e6a1;border-radius:8px;
    padding:24px;text-align:center;
}
.done-box h3{font-size:16px;color:#1e7e34;margin-bottom:8px;}
.done-box .stat{font-size:14px;color:#555;margin-bottom:4px;line-height:1.8;}
.done-box .stat strong{color:#1d2327;}
.perf{font-size:12px;color:#999;margin-top:12px;}
.actions{margin-top:20px;text-align:center;}
.btn{
    display:inline-block;padding:8px 18px;border-radius:4px;font-size:13px;
    cursor:pointer;text-decoration:none;border:none;
}
.btn-outline{background:#fff;color:#2271b1;border:1px solid #2271b1;}
.btn-outline:hover{background:#f0f6fc;}
</style>
</head>
<body>
<div class="card">
    <h2>批量刷新媒体引用计数</h2>
    <div class="done-box">
        <h3>全部完成！</h3>
        <div class="stat">共 <strong><?php echo number_format($total_media); ?></strong> 个媒体文件</div>
        <div class="stat">已被引用: <strong><?php echo number_format(count($usage_map)); ?></strong> 个</div>
        <div class="stat">未被引用: <strong><?php echo number_format($unrecited); ?></strong> 个</div>
        <div class="perf">耗时 <?php echo $elapsed; ?> 秒 &nbsp;|&nbsp; 内存 <?php echo $memory_mb; ?> MB</div>
    </div>
    <div class="actions">
        <p style="font-size:13px;color:#888;margin-bottom:8px;">可以关闭此标签页，回到主页面点击「查询未附加媒体」查看结果。</p>
        <a href="?reset=1" class="btn btn-outline">重新执行</a>
    </div>
</div>
</body>
</html>
