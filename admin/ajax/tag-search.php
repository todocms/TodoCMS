<?php
/**
 * AJAX 标签搜索处理器
 *
 * 根据关键词搜索现有标签，用于标签自动完成功能
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

// 检查权限
if (!current_user_can('edit_posts')) {
    echo json_encode(['error' => '没有权限执行此操作']);
    exit;
}

// 获取搜索词
$query_term = isset($_POST['q']) ? trim($_POST['q']) : '';

error_log("Tag search query: " . $query_term);

if (empty($query_term)) {
    echo json_encode(['success' => true, 'tags' => []]);
    exit;
}

global $tododb;

// 搜索标签（使用模糊查询，仅搜索独立的 todo_tags 表）
$search_like = '%' . $tododb->esc_like($query_term) . '%';

// 1. 从 todo_tags (独立标签表) 搜索 - 现代推荐方式
$query_tags = "SELECT tag_id AS id, tag_title AS name, tag_slug AS slug
              FROM " . TABLE_PREFIX . "tags
              WHERE tag_status = 'active' AND (tag_title LIKE ? OR tag_slug LIKE ?) 
              LIMIT 20";

// 2. 从 todo_terms (分类表中的标签) 搜索 - 历史/辅助库
$query_terms = "SELECT t.term_id AS id, t.name, t.slug
               FROM " . TABLE_PREFIX . "terms t 
               INNER JOIN " . TABLE_PREFIX . "term_taxonomy tt ON t.term_id = tt.term_id 
               WHERE tt.taxonomy = 'post_tag' AND (t.name LIKE ? OR t.slug LIKE ?) 
               LIMIT 20";

$all_tags = [];
$seen_names = [];

try {
    // 优先加载独立标签表的库
    $tags_lib = todo_db_select($query_tags, [$search_like, $search_like]);
    if ($tags_lib) {
        foreach ($tags_lib as $t) {
            $raw_name = isset($t['name']) ? $t['name'] : (isset($t['tag_title']) ? $t['tag_title'] : (is_object($t) ? ($t->name ?? ($t->tag_title ?? '')) : ''));
            if ($raw_name) {
                $clean_name = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($raw_name) : $raw_name;
                $clean_name = trim(preg_replace('/\[[a-z]{2}(?:-[a-z]{2})?\|.*?\]/is', '', $clean_name));
                $clean_name = trim(preg_replace('/\[:[a-z]{2}(?:-[a-z]{2})?\].*?\[:\]/is', '', $clean_name));
                $clean_name = $clean_name ?: $raw_name;

                $all_tags[] = [
                    'id' => isset($t['id']) ? $t['id'] : (isset($t['tag_id']) ? $t['tag_id'] : (is_object($t) ? ($t->id ?? ($t->tag_id ?? 0)) : 0)),
                    'name' => $clean_name,
                    'slug' => isset($t['slug']) ? $t['slug'] : (isset($t['tag_slug']) ? $t['tag_slug'] : (is_object($t) ? ($t->slug ?? ($t->tag_slug ?? '')) : ''))
                ];
                $seen_names[$clean_name] = true;
            }
        }
    }

    // 补充加载旧版 terms 表中的标签
    $terms_lib = todo_db_select($query_terms, [$search_like, $search_like]);
    if ($terms_lib) {
        foreach ($terms_lib as $t) {
            $raw_name = isset($t['name']) ? $t['name'] : (is_object($t) ? ($t->name ?? '') : '');
            if ($raw_name) {
                $clean_name = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($raw_name) : $raw_name;
                $clean_name = trim(preg_replace('/\[[a-z]{2}(?:-[a-z]{2})?\|.*?\]/is', '', $clean_name));
                $clean_name = trim(preg_replace('/\[:[a-z]{2}(?:-[a-z]{2})?\].*?\[:\]/is', '', $clean_name));
                $clean_name = $clean_name ?: $raw_name;

                if (!isset($seen_names[$clean_name])) {
                    $all_tags[] = [
                        'id' => isset($t['id']) ? $t['id'] : (is_object($t) ? ($t->id ?? ($t->term_id ?? 0)) : 0),
                        'name' => $clean_name,
                        'slug' => isset($t['slug']) ? $t['slug'] : (is_object($t) ? ($t->slug ?? '') : '')
                    ];
                    $seen_names[$clean_name] = true;
                }
            }
        }
    }
} catch (Throwable $e) {
    if (defined('TODO_DEBUG') && TODO_DEBUG) {
        error_log("Unified tag search error: " . $e->getMessage());
    }
}

// 返回统一后的前 10 个结果
echo json_encode(['success' => true, 'tags' => array_slice($all_tags, 0, 10)]);
exit;
