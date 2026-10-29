<?php
/**
 * TodoCMS Screen API
 *
 * @package TodoCMS
 * @subpackage Administration
 */

// 防止直接访问（但允许AJAX请求）
if (!defined('ABSPATH') && !defined('DOING_AJAX')) {
    exit('Direct access denied.');
}

/**
 * Screen API
 *
 * @since 3.3.0
 */
final class TODO_Screen
{
    /**
     * Any action associated with the screen. 'add' for *-add.php and *-new.php screens. Empty otherwise.
     *
     * @since 3.3.0
     * @var string
     */
    public $action;

    /**
     * The base type of the screen. This is typically the same as $id but with any post types and taxonomies stripped.
     * For example, for an $id of 'edit-post' the base would be 'edit'.
     *
     * @since 3.3.0
     * @var string
     */
    public $base;

    /**
     * The number of columns to display. Access with get_columns().
     *
     * @since 3.4.0
     * @var int
     */
    private $columns = 0;

    /**
     * The unique ID of the screen.
     *
     * @since 3.3.0
     * @var string
     */
    public $id;

    /**
     * Which admin the screen is in. network | user | site | false
     *
     * @since 3.5.0
     * @var string
     */
    public $in_admin;

    /**
     * Whether the screen is in the network admin.
     *
     * Deprecated. Use in_admin() instead.
     *
     * @since 3.3.0
     * @deprecated 3.5.0
     * @var bool
     */
    public $is_network;

    /**
     * Whether the screen is in the user admin.
     *
     * Deprecated. Use in_admin() instead.
     *
     * @since 3.3.0
     * @deprecated 3.5.0
     * @var bool
     */
    public $is_user;

    /**
     * The base menu parent.
     * This is derived from $parent_file by removing the query string and any .php extension.
     *
     * @since 3.3.0
     * @var string
     */
    public $parent_base;

    /**
     * The parent_file for the screen per the admin menu system.
     * Some $parent_file values are 'edit.php?post_type=page', 'edit.php', and 'options-general.php'.
     *
     * @since 3.3.0
     * @var string
     */
    public $parent_file;

    /**
     * The post type associated with the screen, if any.
     * The 'edit.php?post_type=page' screen has a post type of 'page'.
     * The 'edit-tags.php?taxonomy=category' screen has a post type of 'post'.
     *
     * @since 3.3.0
     * @var string
     */
    public $post_type;

    /**
     * The taxonomy associated with the screen, if any.
     * The 'edit-tags.php?taxonomy=category' screen has a taxonomy of 'category'.
     *
     * @since 3.3.0
     * @var string
     */
    public $taxonomy;

    /**
     * The help tab data associated with the screen, if any.
     *
     * @since 3.3.0
     * @var array
     */
    private $_help_tabs = array();

    /**
     * The help sidebar data associated with the screen, if any.
     *
     * @since 3.3.0
     * @var string
     */
    private $_help_sidebar = '';

    /**
     * The accessible hidden headings and text associated with the screen, if any.
     *
     * @since 4.4.0
     * @var array
     */
    private $_screen_reader_content = array();

    /**
     * Stores the result of the public show_screen_options function.
     *
     * @since 3.3.0
     * @var bool
     */
    private $_show_screen_options;

    /**
     * Stores the 'screen_settings' section of screen options.
     *
     * @since 3.3.0
     * @var string
     */
    private $_screen_settings;

    /**
     * Constructor
     *
     * @since 3.3.0
     */
    private function __construct()
    {
    }

    /**
     * Fetches a screen object.
     *
     * @since 3.3.0
     *
     * @global string $hook_suffix
     *
     * @param string|TODO_Screen $hook_name Optional. The hook name (also known as the hook suffix) used to determine the screen.
     *                                   Defaults to the current $hook_suffix global.
     * @return TODO_Screen Screen object.
     */
    public static function get($hook_name = '')
    {
        if ($hook_name instanceof TODO_Screen) {
            return $hook_name;
        }

        $screen = new self();
        $screen->id = $hook_name ?: 'post-new';
        $screen->base = 'post';
        $screen->action = 'add';
        $screen->post_type = 'post';
        $screen->taxonomy = '';
        $screen->in_admin = 'site';
        $screen->is_network = false;
        $screen->is_user = false;

        return $screen;
    }

    /**
     * Makes the screen object the current screen.
     *
     * @see set_current_screen()
     * @since 3.3.0
     *
     * @global TODO_Screen $current_screen
     */
    public function set_current_screen()
    {
        global $current_screen;
        $current_screen = $this;
    }

    /**
     * Indicates whether the screen is in a particular admin
     *
     * @since 3.5.0
     *
     * @param string $admin The admin to check against (network | user | site).
     *                      If empty any of the three admins will result in true.
     * @return bool True if the screen is in the indicated admin, false otherwise.
     */
    public function in_admin($admin = null)
    {
        if (empty($admin)) {
            return (bool) $this->in_admin;
        }

        return ($admin == $this->in_admin);
    }

    /**
     * Sets the parent information for the screen.
     * This is called in admin-header.php after the menu parent for the screen has been determined.
     *
     * @since 3.3.0
     *
     * @param string $parent_file The parent file of the screen. Typically the $parent_file global.
     */
    public function set_parentage($parent_file)
    {
        $this->parent_file = $parent_file;
        $parentFileStr = is_string($parent_file) ? $parent_file : '';
        $parts = explode('?', $parentFileStr);
        $this->parent_base = str_replace('.php', '', $parts[0] ?? '');
    }

    /**
     * Adds an option for the screen.
     * Call this in template files to add screen-specific options to the Screen Options panel.
     *
     * @since 3.3.0
     *
     * @param string $option Option ID
     * @param mixed  $args   Option-dependent arguments.
     */
    public function add_option($option, $args = array())
    {
        $this->_options[$option] = $args;
    }

    /**
     * Remove an option from the screen.
     *
     * @since 3.8.0
     *
     * @param string $option Option ID.
     */
    public function remove_option($option)
    {
        unset($this->_options[$option]);
    }

    /**
     * Remove all options from the screen.
     *
     * @since 3.8.0
     */
    public function remove_options()
    {
        $this->_options = array();
    }

    /**
     * Get the options registered for the screen.
     *
     * @since 3.8.0
     *
     * @return array Options with arguments.
     */
    public function get_options()
    {
        return $this->_options;
    }

    /**
     * Gets the arguments for an option for the screen.
     *
     * @since 3.3.0
     *
     * @param string $option Option name.
     * @param string $key    Optional. Specific array key for when the option is an array.
     *                       Default false.
     * @return mixed The option value if set, null otherwise.
     */
    public function get_option($option, $key = false)
    {
        if (!isset($this->_options[$option])) {
            return null;
        }
        if ($key) {
            if (isset($this->_options[$option][$key])) {
                return $this->_options[$option][$key];
            }
            return null;
        }
        return $this->_options[$option];
    }

    /**
     * Gets the help tabs registered for the screen.
     *
     * @since 3.4.0
     * @since 4.4.0 Help tabs are ordered by their priority.
     *
     * @return array Help tabs with arguments.
     */
    public function get_help_tabs()
    {
        $help_tabs = $this->_help_tabs;

        $priorities = array();
        foreach ($help_tabs as $help_tab) {
            if (isset($priorities[$help_tab['priority']])) {
                $priorities[$help_tab['priority']][] = $help_tab;
            } else {
                $priorities[$help_tab['priority']] = array($help_tab);
            }
        }

        ksort($priorities);

        $sorted = array();
        foreach ($priorities as $list) {
            foreach ($list as $tab) {
                $sorted[$tab['id']] = $tab;
            }
        }

        return $sorted;
    }

    /**
     * Gets the arguments for a help tab.
     *
     * @since 3.4.0
     *
     * @param string $id Help Tab ID.
     * @return array Help tab arguments.
     */
    public function get_help_tab($id)
    {
        if (!isset($this->_help_tabs[$id])) {
            return null;
        }
        return $this->_help_tabs[$id];
    }

    /**
     * Add a help tab to the contextual help for the screen.
     * Call this on the load-$pagenow hook for the relevant screen.
     *
     * @since 3.3.0
     * @since 4.4.0 The `$priority` argument was added.
     *
     * @param array $args {
     *     Array of arguments used to display the help tab.
     *
     *     @type string $title    Title for the tab. Default false.
     *     @type string $id       Tab ID. Must be HTML-safe. Default false.
     *     @type string $content  Optional. Help tab content in plain text or HTML. Default empty string.
     *     @type callable $callback Optional. A callback to generate the tab content. Default false.
     *     @type int    $priority Optional. The priority of the tab, used for ordering. Default 10.
     * }
     */
    public function add_help_tab($args)
    {
        $defaults = array(
            'title' => false,
            'id' => false,
            'content' => '',
            'callback' => false,
            'priority' => 10,
        );
        $args = todo_parse_args($args, $defaults);

        $args['id'] = sanitize_html_class($args['id']);

        // Ensure we have an ID and title.
        if (!$args['id'] || !$args['title']) {
            return;
        }

        // Allows for overriding an existing tab with that ID.
        $this->_help_tabs[$args['id']] = $args;
    }

    /**
     * Removes a help tab from the contextual help for the screen.
     *
     * @since 3.3.0
     *
     * @param string $id The help tab ID.
     */
    public function remove_help_tab($id)
    {
        unset($this->_help_tabs[$id]);
    }

    /**
     * Removes all help tabs from the contextual help for the screen.
     *
     * @since 3.3.0
     */
    public function remove_help_tabs()
    {
        $this->_help_tabs = array();
    }

    /**
     * Gets the content from a contextual help sidebar.
     *
     * @since 3.4.0
     *
     * @return string Contents of the help sidebar.
     */
    public function get_help_sidebar()
    {
        return $this->_help_sidebar;
    }

    /**
     * Add a sidebar to the contextual help for the screen.
     * Call this in template files to add a sidebar to the contextual help.
     *
     * @since 3.3.0
     *
     * @param string $content Sidebar content in plain text or HTML.
     */
    public function set_help_sidebar($content)
    {
        $this->_help_sidebar = $content;
    }

    /**
     * Gets the number of layout columns the user has selected.
     *
     * The layout_columns option controls the max number and default number of
     * columns. This method returns the number of columns within that range selected
     * by the user via Screen Options. If no selection has been made, the default
     * is returned. If the screen does not support selecting the number of layout
     * columns, 0 is returned.
     *
     * @since 3.4.0
     *
     * @return int Number of columns to display.
     */
    public function get_columns()
    {
        return $this->columns ?: 2;
    }

    /**
     * Get the accessible hidden headings and text used in the screen.
     *
     * @since 4.4.0
     *
     * @see set_screen_reader_content() For more information on the array format.
     *
     * @return array An associative array of screen reader text strings.
     */
    public function get_screen_reader_content()
    {
        return $this->_screen_reader_content;
    }

    /**
     * Add accessible hidden headings and text for the screen.
     *
     * @since 4.4.0
     *
     * @param array $content {
     *    An associative array of screen reader text strings.
     *
     *    @type string $heading_views      Screen reader text for the filter links heading.
     *                                      Default 'Filter items list'.
     *    @type string $heading_pagination Screen reader text for the pagination heading.
     *                                      Default 'Items list navigation'.
     *    @type string $heading_list       Screen reader text for the items list heading.
     *                                      Default 'Items list'.
     * }
     */
    public function set_screen_reader_content($content = array())
    {
        $defaults = array(
            'heading_views' => __('Filter items list'),
            'heading_pagination' => __('Items list navigation'),
            'heading_list' => __('Items list'),
        );
        $content = todo_parse_args($content, $defaults);

        $this->_screen_reader_content = $content;
    }

    /**
     * Remove all the accessible hidden headings and text for the screen.
     *
     * @since 4.4.0
     */
    public function remove_screen_reader_content()
    {
        $this->_screen_reader_content = array();
    }

    /**
     * Render the screen's help section.
     *
     * This will trigger the deprecated filters for backward compatibility.
     *
     * @since 3.3.0
     *
     * @param bool $render Whether to render the help section. Default true.
     */
    public function render_screen_meta($render = true)
    {
        // Simplified implementation
        if (!$render) {
            return;
        }

        echo '<div id="screen-meta" class="metabox-prefs">';
        echo '<div id="contextual-help-wrap" class="hidden" tabindex="-1" aria-label="' . esc_attr__('Contextual Help Tab') . '">';
        echo '<div id="contextual-help-back"></div>';
        echo '<div id="contextual-help-columns">';
        echo '<div class="contextual-help-tabs">';
        echo '<ul>';

        foreach ($this->get_help_tabs() as $tab) {
            $class = ' class="active"';
            $aria_selected = ' aria-selected="true"';
            echo '<li id="' . esc_attr("tab-link-{$tab['id']}") . '"' . $class . '>';
            echo '<a href="' . esc_url("#{$tab['id']}") . '" aria-controls="' . esc_attr($tab['id']) . '"' . $aria_selected . '>';
            echo esc_html($tab['title']);
            echo '</a></li>';
        }

        echo '</ul>';
        echo '</div>';
        echo '<div class="contextual-help-sidebar">';
        echo $this->get_help_sidebar();
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Checks if the screen is considered to be the block editor.
     *
     * @since 5.0.0
     *
     * @return bool True if Gutenberg editor, false if not.
     */
    public function is_block_editor()
    {
        return false; // Simplified - no block editor support
    }
}

/**
 * Parse arguments
 */
if (!function_exists('todo_parse_args')) {
    function todo_parse_args($args, $defaults = '')
    {
        if (is_object($args)) {
            $r = get_object_vars($args);
        } elseif (is_array($args)) {
            $r =& $args;
        } else {
            todo_parse_str($args, $r);
        }

        if (is_array($defaults)) {
            return array_merge($defaults, $r);
        }
        return $r;
    }
}

/**
 * Parse string
 */
function todo_parse_str($string, &$array)
{
    parse_str($string, $array);
}
