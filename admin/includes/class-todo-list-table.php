<?php
/**
 * Base class for displaying a list of items in an HTML table.
 *
 * @package TodoCMS
 * @version 1.0.0
 */

if (!class_exists('Todo_List_Table')) {
    abstract class Todo_List_Table {

        /**
         * The current list of items.
         *
         * @var array
         */
        public $items;

        /**
         * The total number of items.
         *
         * @var int
         */
        protected $total_items;

        /**
         * The total number of pages.
         *
         * @var int
         */
        protected $total_pages;

        /**
         * The number of items to show per page.
         *
         * @var int
         */
        protected $per_page;

        /**
         * Constructor.
         *
         * @param array $args
         */
        public function __construct($args = array()) {
            $this->per_page = $args['per_page'] ?? 20;
        }

        /**
         * Sets the pagination arguments.
         *
         * @param array $args
         */
        protected function set_pagination_args($args) {
            $this->total_items = isset($args['total_items']) ? (int) $args['total_items'] : 0;
            if (!empty($args['per_page'])) {
                $this->per_page = (int) $args['per_page'];
            }

            if ($this->per_page > 0) {
                $this->total_pages = ceil($this->total_items / $this->per_page);
            } else {
                $this->total_pages = 0;
            }
        }

        /**
         * Prepares the list of items for displaying.
         */
        abstract public function prepare_items();

        /**
         * Gets a list of columns.
         *
         * @return array
         */
        abstract public function get_columns();

        /**
         * Gets a list of sortable columns.
         *
         * @return array
         */
        protected function get_sortable_columns() {
            return array();
        }

        /**
         * Gets the name of the default primary column.
         *
         * @return string
         */
        protected function get_primary_column_name() {
            $columns = $this->get_columns();
            $keys = array_keys($columns);
            return $keys[0];
        }

        /**
         * Renders the table.
         */
        public function display() {
            $this->display_tablenav('top');
            $this->display_table();
            $this->display_tablenav('bottom');
        }

        /**
         * Renders the navigation for the table.
         *
         * @param string $which
         */
        protected function display_tablenav($which) {
            echo '<div class="tablenav ' . esc_attr($which) . '">';
            $this->pagination($which);
            echo '<br class="clear" />';
            echo '</div>';
        }

        /**
         * Renders the pagination.
         *
         * @param string $which
         */
        protected function pagination($which) {
            if (empty($this->total_items)) {
                return;
            }

            $total_pages = $this->total_pages;

            if ($total_pages > 1) {
                $current_page = $this->get_pagenum();
                $base_url = remove_query_arg('paged');

                $page_links = paginate_links(array(
                    'base' => $base_url . '%_%',
                    'format' => '&paged=%#%',
                    'prev_text' => __('❮'),
                    'next_text' => __('❯'),
                    'total' => $total_pages,
                    'current' => $current_page,
                ));

                if ($page_links) {
                    echo '<div class="tablenav-pages">' . $page_links . '</div>';
                }
            }
        }

        /**
         * Gets the current page number.
         *
         * @return int
         */
        protected function get_pagenum() {
            return isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        }

        /**
         * Renders the table itself.
         */
        protected function display_table() {
            echo '<table class="todocms-list-table widefat fixed striped">';
            $this->print_column_headers();
            echo '<tbody>';
            $this->display_rows_or_placeholder();
            echo '</tbody>';
            echo '</table>';
        }

        /**
         * Renders the column headers.
         */
        protected function print_column_headers() {
            $columns = $this->get_columns();
            $sortable = $this->get_sortable_columns();
            $current_orderby = $_GET['orderby'] ?? '';
            $current_order = $_GET['order'] ?? 'asc';

            echo '<thead><tr>';
            foreach ($columns as $column_key => $column_display_name) {
                $class = "manage-column column-$column_key";
                if ($column_key === $this->get_primary_column_name()) {
                    $class .= ' column-primary';
                }

                $sort_link = '';
                if (isset($sortable[$column_key])) {
                    $class .= ' sorted';
                    $order = ($current_orderby === $column_key && $current_order === 'asc') ? 'desc' : 'asc';
                    $sort_link = add_query_arg(array(
                        'orderby' => $column_key,
                        'order' => $order,
                    ));
                    $class .= $current_orderby === $column_key ? ' ' . $current_order : '';
                }

                echo '<th class="' . esc_attr($class) . '">';
                if ($sort_link) {
                    echo '<a href="' . esc_url($sort_link) . '"><span>' . esc_html($column_display_name) . '</span><span class="sorting-indicator"></span></a>';
                } else {
                    echo esc_html($column_display_name);
                }
                echo '</th>';
            }
            echo '</tr></thead>';
        }

        /**
         * Renders the rows or a placeholder if no items are found.
         */
        protected function display_rows_or_placeholder() {
            if ($this->has_items()) {
                $this->display_rows();
            } else {
                echo '<tr><td colspan="' . count($this->get_columns()) . '">';
                $this->no_items();
                echo '</td></tr>';
            }
        }

        /**
         * Renders the table rows.
         */
        protected function display_rows() {
            foreach ($this->items as $item) {
                $this->single_row($item);
            }
        }

        /**
         * Renders a single table row.
         *
         * @param object $item
         */
        protected function single_row($item) {
            echo '<tr>';
            $this->single_row_columns($item);
            echo '</tr>';
        }

        /**
         * Renders the columns for a single row.
         *
         * @param object $item
         */
        protected function single_row_columns($item) {
            $columns = $this->get_columns();
            $primary = $this->get_primary_column_name();

            foreach ($columns as $column_name => $display_name) {
                $classes = "$column_name column-$column_name";
                if ($primary === $column_name) {
                    $classes .= ' has-row-actions';
                }

                echo '<td class="' . esc_attr($classes) . '">';

                if (method_exists($this, 'column_' . $column_name)) {
                    echo call_user_func(array($this, 'column_' . $column_name), $item);
                } else {
                    echo $this->column_default($item, $column_name);
                }

                if ($primary === $column_name) {
                    echo $this->handle_row_actions($item, $column_name, $primary);
                }
                echo '</td>';
            }
        }

        /**
         * Renders the default column content.
         *
         * @param object $item
         * @param string $column_name
         * @return string
         */
        protected function column_default($item, $column_name) {
            return $item->$column_name ?? '';
        }

        /**
         * Handles row actions.
         *
         * @param object $item
         * @param string $column_name
         * @param string $primary
         * @return string
         */
        protected function handle_row_actions($item, $column_name, $primary) {
            if ($primary !== $column_name) {
                return '';
            }

            $actions = $this->get_row_actions($item);
            if (empty($actions)) {
                return '';
            }

            $action_links = array();
            foreach ($actions as $action => $link) {
                $action_links[] = '<span class="' . esc_attr($action) . '">' . $link . '</span>';
            }

            return '<div class="row-actions">' . implode(' | ', $action_links) . '</div>';
        }

        /**
         * Gets the row actions.
         *
         * @param object $item
         * @return array
         */
        protected function get_row_actions($item) {
            return array();
        }

        /**
         * Checks if there are items to display.
         *
         * @return bool
         */
        public function has_items() {
            return !empty($this->items);
        }

        /**
         * Message to be displayed when there are no items.
         */
        public function search_box($text, $input_id) {
            $input_id = $input_id ? $input_id : 'search-input';
            $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';

            echo '<p class="search-box">';
            echo '<label class="screen-reader-text" for="' . esc_attr($input_id) . '">' . esc_html($text) . ':</label>';
            echo '<input type="search" id="' . esc_attr($input_id) . '" name="s" value="' . esc_attr($search) . '" />';
            echo '<input type="submit" class="button" value="' . esc_attr($text) . '" />';
            if (isset($_REQUEST['page'])) {
                echo '<input type="hidden" name="page" value="' . esc_attr($_REQUEST['page']) . '" />';
            }
            echo '</p>';
        }

        public function row_actions($actions) {
            if (empty($actions) || !is_array($actions)) {
                return '';
            }
            $action_links = array();
            foreach ($actions as $action => $link) {
                $action_links[] = '<span class="' . esc_attr($action) . '\">' . $link . '</span>';
            }
            return '<div class="row-actions">' . implode(' | ', $action_links) . '</div>';
        }

        public function no_items() {
            _e('No items found.');
        }
    }
}
