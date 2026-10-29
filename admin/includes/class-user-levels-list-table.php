<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../');
}

class User_Levels_List_Table extends Todo_List_Table {
    private $level_manager;

    public function __construct() {
        parent::__construct(array(
            'singular' => 'user_level',
            'plural'   => 'user_levels',
            'ajax'     => false
        ));
        $this->level_manager = todo_get_user_level_manager();
    }

    public function get_columns() {
        return array(
            'cb' => '<input type="checkbox" />',
            'level_name' => '等级名称',
            'level_slug' => '标识',
            'min_points' => '最低积分',
            'discount_rate' => '折扣率',
            'level_color' => '颜色',
            'level_order' => '排序',
            'is_active' => '状态',
            'user_count' => '用户数'
        );
    }

    public function prepare_items() {
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array($columns, $hidden, $sortable);

        $per_page = 20;
        $current_page = $this->get_pagenum();
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';
        $status = isset($_REQUEST['status']) ? $_REQUEST['status'] : '';

        // 构建查询参数
        $args = array(
            'search' => $search,
            'orderby' => (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : 'level_order',
            'order' => (!empty($_REQUEST['order'])) ? $_REQUEST['order'] : 'asc',
            'number' => $per_page,
            'offset' => ($current_page - 1) * $per_page
        );

        // 添加状态筛选
        if ($status !== '') {
            $args['is_active'] = ($status === 'active') ? 1 : 0;
        }

        // 获取总数
        $total_items = $this->level_manager->get_user_levels(array_merge($args, array('count' => true)));

        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page
        ));

        // 获取数据
        $this->items = $this->level_manager->get_user_levels($args);
    }

    protected function column_default($item, $column_name) {
        switch ($column_name) {
            case 'level_slug':
                return '<code>' . esc_html($item->level_slug) . '</code>';
            case 'min_points':
                return '<span class="points-badge">' . number_format($item->min_points) . '</span>';
            case 'discount_rate':
                $rate = isset($item->discount_rate) ? floatval($item->discount_rate) : 0;
                $rate_str = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
                return '<span class="discount-badge">' . $rate_str . '%</span>';
            case 'level_color':
                return '<div class="color-preview" style="background-color: ' . esc_attr($item->level_color) . '" title="' . esc_attr($item->level_color) . '"></div>';
            case 'is_active':
                return '<span class="status-' . ($item->is_active ? 'active' : 'inactive') . '">' . ($item->is_active ? '启用' : '禁用') . '</span>';
            case 'user_count':
                $count = $this->level_manager->get_level_user_count($item->id);
                if ($count > 0) {
                    return '<a href="' . todo_admin_url('index.php?page=users&level=' . $item->id) . '">' . number_format($count) . '</a>';
                }
                return '0';
            default:
                return esc_html($item->$column_name);
        }
    }

    protected function column_level_name($item) {
        $actions = array(
            'edit'   => sprintf('<a href="?page=%s&action=%s&level=%s">编辑</a>', $_REQUEST['page'], 'edit', $item->id),
            'delete' => sprintf('<a href="javascript:void(0);" class="delete-level submitdelete" data-level-id="%d" data-level-name="%s">删除</a>', $item->id, esc_attr($item->level_name)),
        );
        
        $level_name = esc_html($item->level_name);
        if ($item->level_icon) {
            $level_name = '<i class="' . esc_attr($item->level_icon) . '"></i> ' . $level_name;
        }
        
        return sprintf('<strong><a href="?page=%s&action=%s&level=%s">%s</a></strong>%s', 
            $_REQUEST['page'], 'edit', $item->id, $level_name, $this->row_actions($actions));
    }

    protected function column_cb($item) {
        return sprintf('<input type="checkbox" name="level_ids[]" value="%s" />', $item->id);
    }

    protected function get_sortable_columns() {
        return array(
            'level_name' => array('level_name', false),
            'min_points' => array('min_points', false),
            'level_order' => array('level_order', true), // true means it's the default sort
            'is_active' => array('is_active', false)
        );
    }

    protected function get_bulk_actions() {
        return array(
            'activate' => '启用',
            'deactivate' => '禁用',
            'delete' => '删除'
        );
    }

    public function no_items() {
        echo '没有找到用户等级。';
    }

    /**
     * 显示状态筛选链接
     */
    protected function get_views() {
        $views = array();
        $current = isset($_REQUEST['status']) ? $_REQUEST['status'] : 'all';
        
        // 获取统计数据
        $all_count = $this->level_manager->get_user_levels(array('count' => true));
        $active_count = $this->level_manager->get_user_levels(array('count' => true, 'is_active' => 1));
        $inactive_count = $this->level_manager->get_user_levels(array('count' => true, 'is_active' => 0));
        
        $views['all'] = sprintf(
            '<a href="%s"%s>所有等级 <span class="count">(%d)</span></a>',
            remove_query_arg('status'),
            $current === 'all' ? ' class="current"' : '',
            $all_count
        );
        
        $views['active'] = sprintf(
            '<a href="%s"%s>已启用 <span class="count">(%d)</span></a>',
            add_query_arg('status', 'active'),
            $current === 'active' ? ' class="current"' : '',
            $active_count
        );
        
        $views['inactive'] = sprintf(
            '<a href="%s"%s>已禁用 <span class="count">(%d)</span></a>',
            add_query_arg('status', 'inactive'),
            $current === 'inactive' ? ' class="current"' : '',
            $inactive_count
        );
        
        return $views;
    }

    /**
     * 显示表格上方的导航
     */
    protected function display_tablenav($which) {
        if ('top' === $which) {
            todo_nonce_field('bulk-' . $this->_args['plural']);
        }
        ?>
        <div class="tablenav <?php echo esc_attr($which); ?>">
            <?php if ($this->has_items()): ?>
                <div class="alignleft actions bulkactions">
                    <?php $this->bulk_actions($which); ?>
                </div>
            <?php endif;
            $this->extra_tablenav($which);
            $this->pagination($which);
            ?>
            <br class="clear" />
        </div>
        <?php
    }

    /**
     * 额外的表格导航元素
     */
    protected function extra_tablenav($which) {
        // 可以在这里添加额外的筛选器或操作
    }
}