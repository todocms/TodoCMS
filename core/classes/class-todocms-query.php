<?php
/**
 * TodoCMS Query 类
 *
 * 查询类，用于查询文章和自定义内容类型
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class TodoCMS_Query
{
    /**
     * 查询参数
     *
     * @var array
     */
    public $query_vars = [];

    /**
     * 查询结果
     *
     * @var array
     */
    public $posts = [];

    /**
     * 当前文章索引
     *
     * @var int
     */
    public $current_post = -1;

    /**
     * 文章总数
     *
     * @var int
     */
    public $post_count = 0;

    /**
     * 找到的文章总数
     *
     * @var int
     */
    public $found_posts = 0;

    /**
     * 最大页数
     *
     * @var int
     */
    public $max_num_pages = 0;

    /**
     * 当前文章对象
     *
     * @var object
     */
    public $post;

    /**
     * 是否在循环中
     *
     * @var bool
     */
    public $in_the_loop = false;

    /**
     * 构造函数
     *
     * @param array $args 查询参数
     */
    public function __construct($args = [])
    {
        $this->init($args);
    }

    /**
     * 初始化查询
     *
     * @param array $args 查询参数
     */
    public function init($args = [])
    {
        $this->query_vars = $this->parse_query_vars($args);
        $this->query();
    }

    /**
     * 解析查询参数
     *
     * @param array $args 查询参数
     * @return array 解析后的参数
     */
    private function parse_query_vars($args)
    {
        $defaults = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [],
            'category' => '',
            'tag' => '',
            'author' => '',
            'search' => '',
            'include' => [],
            'exclude' => [],
            'offset' => 0,
            'paged' => 1,
            'site_id' => null // 站点ID (Phase 3)
        ];

        return array_merge($defaults, $args);
    }

    /**
     * 执行查询
     */
    private function query()
    {
        global $tododb;

        $sql = $this->build_sql();
        $results = $tododb->get_results($sql['query'], $sql['params']);

        if ($results) {
            $this->posts = $results;
            $this->post_count = count($results);

            // 性能优化：批量预加载文章的 Meta、ORM 和 多语言 Data，防止循环中的 N+1 查询
            if ($this->post_count > 0) {
                $post_ids = [];
                foreach ($this->posts as $p) {
                    $post_ids[] = is_object($p) ? (int)$p->ID : (int)$p['ID'];
                }
                $post_ids = array_filter(array_unique($post_ids));
                if (!empty($post_ids)) {
                    if (class_exists('Todo_Post_ORM')) {
                        Todo_Post_ORM::eager_load_all($post_ids);
                    }
                    if (function_exists('update_post_meta_cache')) {
                        update_post_meta_cache($post_ids);
                    }
                    if (function_exists('todo_preload_post_translations')) {
                        $lang = function_exists('get_current_language') ? get_current_language() : (function_exists('todo_get_current_language') ? todo_get_current_language() : 'zh-CN');
                        todo_preload_post_translations($post_ids, $lang);
                    }
                }
            }

            // 计算总数和页数
            if ($this->query_vars['posts_per_page'] > 0) {
                $count_sql = $this->build_count_sql();
                $total_results = $tododb->get_var($count_sql['query'], $count_sql['params']);
                $this->found_posts = (int) $total_results;
                $this->max_num_pages = ceil($this->found_posts / $this->query_vars['posts_per_page']);
            } else {
                $this->found_posts = $this->post_count;
                $this->max_num_pages = 1;
            }
        } else {
            $this->posts = [];
            $this->post_count = 0;
            $this->found_posts = 0;
            $this->max_num_pages = 0;
        }

        $this->current_post = -1;
        $this->in_the_loop = false;
    }

    /**
     * 构建SQL查询
     *
     * @return array 包含query和params的数组
     */
    private function build_sql()
    {
        global $tododb;
        $table = TABLE_PREFIX . 'posts';
        $where_conditions = [];
        $params = [];

        // 多语言过滤：非主语言下，仅显示显式绑定的文章
        $language_code = (function_exists('get_current_language') ? get_current_language() : (function_exists('todo_get_current_language') ? todo_get_current_language() : null));
        if ($language_code) {
            $language_code = str_replace('_', '-', $language_code);
        }
        $apply_i18n = (!defined('IS_ADMIN') || !IS_ADMIN);

        if ($apply_i18n && $language_code) {
            $default_lang = function_exists('get_default_language') ? get_default_language() : 'zh-CN';
            $default_lang = str_replace('_', '-', $default_lang);
            $is_main_lang = ($language_code === $default_lang);

            if (!$is_main_lang) {
                // 严格模式：使用 JSON_CONTAINS 确保语言代码在数组中精确匹配
                $where_conditions[] = "EXISTS (SELECT 1 FROM " . TABLE_PREFIX . "postmeta WHERE post_id = " . TABLE_PREFIX . "posts.ID AND meta_key = '_enabled_languages' AND JSON_CONTAINS(meta_value, ?))";
                $params[] = '"' . $language_code . '"';
            }
        }

        $is_admin = (defined('IS_ADMIN') && IS_ADMIN);
        if (isset($this->query_vars['site_id']) && $this->query_vars['site_id'] !== null) {
            $current_site_id = (int) $this->query_vars['site_id'];
        } elseif ($is_admin) {
            $current_site_id = (isset($_GET['site']) && is_numeric($_GET['site']) && (int) $_GET['site'] > 0) ? (int) $_GET['site'] : 0;
        } else {
            $current_site_id = function_exists('todo_current_site_id') ? (int) todo_current_site_id(true) : 0;
        }
        $apply_site_filter = ($current_site_id > 0);

        $has_sp = function_exists('todo_site_posts_has_bindings') ? todo_site_posts_has_bindings() : (function_exists('todo_site_posts_table_exists') && todo_site_posts_table_exists());

        $site_join = '';
        if ($apply_site_filter && $has_sp) {
            $site_table = TABLE_PREFIX . 'site_posts';
            $site_join = " LEFT JOIN $site_table sp ON (" . TABLE_PREFIX . "posts.ID = sp.post_id AND sp.site_id = $current_site_id)";
            $where_conditions[] = "((sp.post_id IS NOT NULL AND sp.is_visible = 1) OR NOT EXISTS (SELECT 1 FROM $site_table spx WHERE spx.post_id = " . TABLE_PREFIX . "posts.ID))";
            $status_expr = "COALESCE(sp.status, " . TABLE_PREFIX . "posts.post_status)";
            $where_conditions[] = "{$status_expr} = ?";
            $params[] = ($is_admin) ? $this->query_vars['post_status'] : 'publish';
        } else {
            $where_conditions[] = "post_status = ?";
            $params[] = $this->query_vars['post_status'];
        }

        $where_conditions[] = "post_type = ?";
        $params[] = $this->query_vars['post_type'];

        // 搜索条件
        if (!empty($this->query_vars['search'])) {
            $where_conditions[] = "(post_title LIKE ? OR post_content LIKE ?)";
            $search_term = '%' . $this->query_vars['search'] . '%';
            $params[] = $search_term;
            $params[] = $search_term;
        }

        // 作者条件
        if (!empty($this->query_vars['author'])) {
            $where_conditions[] = "post_author = ?";
            $params[] = $this->query_vars['author'];
        }

        // 包含特定ID
        if (!empty($this->query_vars['include'])) {
            $placeholders = str_repeat('?,', count($this->query_vars['include']) - 1) . '?';
            $where_conditions[] = "ID IN ($placeholders)";
            $params = array_merge($params, $this->query_vars['include']);
        }

        // 排除特定ID
        if (!empty($this->query_vars['exclude'])) {
            $placeholders = str_repeat('?,', count($this->query_vars['exclude']) - 1) . '?';
            $where_conditions[] = "ID NOT IN ($placeholders)";
            $params = array_merge($params, $this->query_vars['exclude']);
        }

        // Meta查询
        $meta_join = '';
        if (!empty($this->query_vars['meta_query'])) {
            $meta_conditions = $this->build_meta_query($this->query_vars['meta_query'], $params);
            if ($meta_conditions['join']) {
                $meta_join = $meta_conditions['join'];
            }
            if ($meta_conditions['where']) {
                $where_conditions[] = $meta_conditions['where'];
                $params = $meta_conditions['params'];
            }
        }

        // 构建完整查询
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        // 排序
        $orderby = $this->build_orderby();

        // 分页
        $limit_clause = '';
        if ($this->query_vars['posts_per_page'] > 0) {
            $offset = ($this->query_vars['paged'] - 1) * $this->query_vars['posts_per_page'] + $this->query_vars['offset'];
            $limit_clause = "LIMIT $offset, " . $this->query_vars['posts_per_page'];
        } elseif ($this->query_vars['offset'] > 0) {
            $limit_clause = "LIMIT " . $this->query_vars['offset'] . ", 18446744073709551615";
        }

        $query = "SELECT " . TABLE_PREFIX . "posts.* FROM $table $site_join $meta_join $where_clause $orderby $limit_clause";

        return [
            'query' => $query,
            'params' => $params
        ];
    }

    /**
     * 构建计数SQL查询
     *
     * @return array 包含query和params的数组
     */
    private function build_count_sql()
    {
        global $tododb;
        $table = TABLE_PREFIX . 'posts';
        $where_conditions = [];
        $params = [];

        // 多语言过滤：非主语言下，仅显示显式绑定的文章
        $language_code = (function_exists('get_current_language') ? get_current_language() : (function_exists('todo_get_current_language') ? todo_get_current_language() : null));
        if ($language_code) {
            $language_code = str_replace('_', '-', $language_code);
        }
        $apply_i18n = (!defined('IS_ADMIN') || !IS_ADMIN);

        if ($apply_i18n && $language_code) {
            $default_lang = function_exists('get_default_language') ? get_default_language() : 'zh-CN';
            $default_lang = str_replace('_', '-', $default_lang);
            $is_main_lang = ($language_code === $default_lang);

            if (!$is_main_lang) {
                // 严格模式：必须在 _enabled_languages 中包含当前语言代码
                $where_conditions[] = "EXISTS (SELECT 1 FROM " . TABLE_PREFIX . "postmeta WHERE post_id = " . TABLE_PREFIX . "posts.ID AND meta_key = '_enabled_languages' AND meta_value LIKE ?)";
                $params[] = '%"' . $tododb->esc_like($language_code) . '"%';
            }
        }

        $is_admin = (defined('IS_ADMIN') && IS_ADMIN);
        if (isset($this->query_vars['site_id']) && $this->query_vars['site_id'] !== null) {
            $current_site_id = (int) $this->query_vars['site_id'];
        } elseif ($is_admin) {
            $current_site_id = (isset($_GET['site']) && is_numeric($_GET['site']) && (int) $_GET['site'] > 0) ? (int) $_GET['site'] : 0;
        } else {
            $current_site_id = function_exists('todo_current_site_id') ? (int) todo_current_site_id(true) : 0;
        }
        $apply_site_filter = ($current_site_id > 0);

        $has_sp = function_exists('todo_site_posts_has_bindings') ? todo_site_posts_has_bindings() : (function_exists('todo_site_posts_table_exists') && todo_site_posts_table_exists());

        $site_join = '';
        if ($apply_site_filter && $has_sp) {
            $site_table = TABLE_PREFIX . 'site_posts';
            $site_join = " LEFT JOIN $site_table sp ON (" . TABLE_PREFIX . "posts.ID = sp.post_id AND sp.site_id = $current_site_id)";
            $where_conditions[] = "((sp.post_id IS NOT NULL AND sp.is_visible = 1) OR NOT EXISTS (SELECT 1 FROM $site_table spx WHERE spx.post_id = " . TABLE_PREFIX . "posts.ID))";
            $status_expr = "COALESCE(sp.status, " . TABLE_PREFIX . "posts.post_status)";
            $where_conditions[] = "{$status_expr} = ?";
            $params[] = ($is_admin) ? $this->query_vars['post_status'] : 'publish';
        } else {
            $where_conditions[] = "post_status = ?";
            $params[] = $this->query_vars['post_status'];
        }

        $where_conditions[] = "post_type = ?";
        $params[] = $this->query_vars['post_type'];

        // 搜索条件
        if (!empty($this->query_vars['search'])) {
            $where_conditions[] = "(post_title LIKE ? OR post_content LIKE ?)";
            $search_term = '%' . $this->query_vars['search'] . '%';
            $params[] = $search_term;
            $params[] = $search_term;
        }

        // 作者条件
        if (!empty($this->query_vars['author'])) {
            $where_conditions[] = "post_author = ?";
            $params[] = $this->query_vars['author'];
        }

        // 包含特定ID
        if (!empty($this->query_vars['include'])) {
            $placeholders = str_repeat('?,', count($this->query_vars['include']) - 1) . '?';
            $where_conditions[] = "ID IN ($placeholders)";
            $params = array_merge($params, $this->query_vars['include']);
        }

        // 排除特定ID
        if (!empty($this->query_vars['exclude'])) {
            $placeholders = str_repeat('?,', count($this->query_vars['exclude']) - 1) . '?';
            $where_conditions[] = "ID NOT IN ($placeholders)";
            $params = array_merge($params, $this->query_vars['exclude']);
        }

        // Meta查询
        $meta_join = '';
        if (!empty($this->query_vars['meta_query'])) {
            $meta_conditions = $this->build_meta_query($this->query_vars['meta_query'], $params);
            if ($meta_conditions['join']) {
                $meta_join = $meta_conditions['join'];
            }
            if ($meta_conditions['where']) {
                $where_conditions[] = $meta_conditions['where'];
                $params = $meta_conditions['params'];
            }
        }

        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        $query = "SELECT COUNT(*) FROM $table $site_join $meta_join $where_clause";

        return [
            'query' => $query,
            'params' => $params
        ];
    }

    /**
     * 构建Meta查询
     *
     * @param array $meta_query Meta查询参数
     * @param array $params 当前参数数组
     * @return array 包含join、where和params的数组
     */
    private function build_meta_query($meta_query, $params)
    {
        if (empty($meta_query)) {
            return ['join' => '', 'where' => '', 'params' => $params];
        }

        $meta_table = TABLE_PREFIX . 'post_custom_fields';
        $join_clauses = [];
        $where_clauses = [];
        $meta_count = 0;

        foreach ($meta_query as $meta_condition) {
            if (!isset($meta_condition['key'])) {
                continue;
            }

            $meta_count++;
            $alias = "pm$meta_count";

            $join_clauses[] = "INNER JOIN $meta_table $alias ON (" . TABLE_PREFIX . "posts.ID = $alias.post_id)";

            $meta_where = "$alias.field_key = ?";
            $params[] = $meta_condition['key'];

            if (isset($meta_condition['value'])) {
                $compare = isset($meta_condition['compare']) ? $meta_condition['compare'] : '=';

                switch ($compare) {
                    case 'LIKE':
                        $meta_where .= " AND $alias.field_value LIKE ?";
                        $params[] = '%' . $meta_condition['value'] . '%';
                        break;
                    case 'NOT LIKE':
                        $meta_where .= " AND $alias.field_value NOT LIKE ?";
                        $params[] = '%' . $meta_condition['value'] . '%';
                        break;
                    case '>':
                        $meta_where .= " AND CAST($alias.field_value AS DECIMAL) > ?";
                        $params[] = $meta_condition['value'];
                        break;
                    case '>=':
                        $meta_where .= " AND CAST($alias.field_value AS DECIMAL) >= ?";
                        $params[] = $meta_condition['value'];
                        break;
                    case '<':
                        $meta_where .= " AND CAST($alias.field_value AS DECIMAL) < ?";
                        $params[] = $meta_condition['value'];
                        break;
                    case '<=':
                        $meta_where .= " AND CAST($alias.field_value AS DECIMAL) <= ?";
                        $params[] = $meta_condition['value'];
                        break;
                    case '!=':
                    case '<>':
                        $meta_where .= " AND $alias.field_value != ?";
                        $params[] = $meta_condition['value'];
                        break;
                    case 'IN':
                        if (is_array($meta_condition['value'])) {
                            $placeholders = str_repeat('?,', count($meta_condition['value']) - 1) . '?';
                            $meta_where .= " AND $alias.field_value IN ($placeholders)";
                            $params = array_merge($params, $meta_condition['value']);
                        }
                        break;
                    case 'NOT IN':
                        if (is_array($meta_condition['value'])) {
                            $placeholders = str_repeat('?,', count($meta_condition['value']) - 1) . '?';
                            $meta_where .= " AND $alias.field_value NOT IN ($placeholders)";
                            $params = array_merge($params, $meta_condition['value']);
                        }
                        break;
                    default: // '='
                        $meta_where .= " AND $alias.field_value = ?";
                        $params[] = $meta_condition['value'];
                        break;
                }
            }

            $where_clauses[] = "($meta_where)";
        }

        return [
            'join' => implode(' ', $join_clauses),
            'where' => implode(' AND ', $where_clauses),
            'params' => $params
        ];
    }

    /**
     * 构建排序子句
     *
     * @return string 排序子句
     */
    private function build_orderby()
    {
        $orderby = $this->query_vars['orderby'];
        $order = strtoupper($this->query_vars['order']);

        if (!in_array($order, ['ASC', 'DESC'])) {
            $order = 'DESC';
        }

        switch ($orderby) {
            case 'ID':
                return "ORDER BY ID $order";
            case 'title':
                return "ORDER BY post_title $order";
            case 'date':
                return "ORDER BY post_date $order";
            case 'modified':
                return "ORDER BY post_modified $order";
            case 'menu_order':
                return "ORDER BY menu_order $order, post_date $order";
            case 'site_order': // Phase 3: 站点特定排序
                return "ORDER BY sp.sort_order $order, post_date $order";
            case 'rand':
                return "ORDER BY RAND()";
            case 'meta_value':
                if (isset($this->query_vars['meta_key'])) {
                    return "ORDER BY field_value $order";
                }
                return "ORDER BY post_date $order";
            case 'meta_value_num':
                if (isset($this->query_vars['meta_key'])) {
                    return "ORDER BY CAST(field_value AS DECIMAL) $order";
                }
                return "ORDER BY post_date $order";
            default:
                // 如果应用了站点过滤，默认使用站点排序
                $current_site_id = (int) (isset($this->query_vars['site_id']) ? $this->query_vars['site_id'] : (function_exists('todo_current_site_id') ? todo_current_site_id(false) : 0));
                if ($current_site_id > 0 && (!defined('IS_ADMIN') || !IS_ADMIN)) {
                    return "ORDER BY sp.sort_order $order, post_date $order";
                }
                return "ORDER BY post_date $order";
        }
    }

    /**
     * 检查是否有文章
     *
     * @return bool 是否有文章
     */
    public function have_posts()
    {
        if ($this->current_post + 1 < $this->post_count) {
            return true;
        } elseif ($this->current_post + 1 == $this->post_count && $this->post_count > 0) {
            $this->rewind_posts();
        }

        $this->in_the_loop = false;
        return false;
    }

    /**
     * 设置下一个文章
     */
    public function the_post()
    {
        global $post;

        $this->in_the_loop = true;

        if ($this->current_post == -1) {
            $this->current_post = 0;
        } else {
            $this->current_post++;
        }

        $this->post = $this->posts[$this->current_post];
        $post = $this->post; // 设置全局$post变量

        // 设置全局post数据
        $this->setup_postdata($this->post);
    }

    /**
     * 设置文章数据
     *
     * @param object $post 文章对象
     */
    public function setup_postdata($post)
    {
        global $id, $authordata, $currentday, $currentmonth, $page, $pages, $multipage, $more, $numpages;

        if (is_object($post)) {
            $id = (int) $post->ID;
        }
    }

    /**
     * 重置文章循环
     */
    public function rewind_posts()
    {
        $this->current_post = -1;
        if ($this->post_count > 0) {
            $this->post = $this->posts[0];
        }
    }

    /**
     * 获取查询的SQL语句（用于调试）
     *
     * @return string SQL语句
     */
    public function get_sql()
    {
        $sql_data = $this->build_sql();
        return $sql_data['query'];
    }
}
