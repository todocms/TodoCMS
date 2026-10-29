<?php
/**
 * TodoCMS Taxonomy Functions for TodoCMS
 *
 * @package TodoCMS
 * @subpackage Administration
 */

// Don't load directly
if (!defined('ABSPATH')) {
    die('-1');
}

/**
 * Get taxonomy
 */
function get_taxonomy($taxonomy) {
    $taxonomies = array(
        'category' => (object) array(
            'name' => 'category',
            'label' => 'Categories',
            'labels' => (object) array(
                'name' => 'Categories',
                'singular_name' => 'Category',
                'all_items' => 'All Categories',
                'add_new_item' => 'Add New Category',
                'new_item_name' => 'New Category Name',
                'parent_item' => 'Parent Category',
                'parent_item_colon' => 'Parent Category:',
                'add_or_remove_items' => 'Add or remove categories',
                'separate_items_with_commas' => 'Separate categories with commas',
                'choose_from_most_used' => 'Choose from the most used categories',
                'no_terms' => 'No categories'
            ),
            'hierarchical' => true,
            'public' => true,
            'cap' => (object) array(
                'assign_terms' => 'edit_posts',
                'edit_terms' => 'manage_categories'
            )
        ),
        'post_tag' => (object) array(
            'name' => 'post_tag',
            'label' => 'Tags',
            'labels' => (object) array(
                'name' => 'Tags',
                'singular_name' => 'Tag',
                'all_items' => 'All Tags',
                'add_new_item' => 'Add New Tag',
                'new_item_name' => 'New Tag Name',
                'parent_item' => '',
                'parent_item_colon' => '',
                'add_or_remove_items' => 'Add or remove tags',
                'separate_items_with_commas' => 'Separate tags with commas',
                'choose_from_most_used' => 'Choose from the most used tags',
                'no_terms' => 'No tags'
            ),
            'hierarchical' => false,
            'public' => true,
            'cap' => (object) array(
                'assign_terms' => 'edit_posts',
                'edit_terms' => 'manage_categories'
            )
        )
    );
    
    return isset($taxonomies[$taxonomy]) ? $taxonomies[$taxonomy] : false;
}

/**
 * Get terms to edit
 */
function get_terms_to_edit($post_id, $taxonomy = 'post_tag') {
    $post_id = (int) $post_id;
    if (!$post_id) {
        return false;
    }
    
    $terms = todo_get_object_terms($post_id, $taxonomy, array('fields' => 'names'));
    if (!$terms || is_todo_error($terms)) {
        return false;
    }
    
    if (is_array($terms)) {
        $terms = implode(',', $terms);
    }
    
    return esc_textarea($terms);
}

/**
 * Get object terms
 */
function todo_get_object_terms($object_ids, $taxonomies, $args = array()) {
    // Simplified - return empty array
    return array();
}

/**
 * Terms checklist
 */
function todo_terms_checklist($post_id = 0, $args = array()) {
    $defaults = array(
        'descendants_and_self' => 0,
        'selected_cats' => false,
        'popular_cats' => false,
        'walker' => null,
        'taxonomy' => 'category',
        'checked_ontop' => true
    );
    
    $args = todo_parse_args($args, $defaults);
    
    if (empty($args['walker']) || !($args['walker'] instanceof Walker)) {
        $walker = new Walker_Category_Checklist;
    } else {
        $walker = $args['walker'];
    }
    
    $taxonomy = $args['taxonomy'];
    $descendants_and_self = (int) $args['descendants_and_self'];
    
    $args['popular_cats'] = empty($args['popular_cats']) ? array() : $args['popular_cats'];
    
    $checked_categories = array();
    if ($post_id) {
        $checked_categories = todo_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
    }
    
    $categories = (array) get_terms($taxonomy, array('get' => 'all'));
    
    if ($descendants_and_self) {
        $self = get_category($descendants_and_self);
        array_unshift($categories, $self);
    }
    
    if ($args['checked_ontop']) {
        // Put checked cats on top
        echo call_user_func_array(array($walker, 'walk'), array($categories, 0, $args));
    } else {
        echo call_user_func_array(array($walker, 'walk'), array($categories, 0, $args));
    }
}

/**
 * Popular terms checklist
 */
function todo_popular_terms_checklist($taxonomy, $default = 0, $number = 10, $echo = true) {
    $post = get_post();
    
    // Initialize checked categories array
    $checked_categories = array();
    if ($post && isset($post->ID)) {
        $checked_categories = todo_get_object_terms($post->ID, $taxonomy, array('fields' => 'ids'));
        if (is_todo_error($checked_categories)) {
            $checked_categories = array();
        }
    }
    
    $terms = get_terms($taxonomy, array(
        'orderby' => 'count',
        'order' => 'DESC',
        'number' => $number,
        'hierarchical' => false
    ));
    
    $popular_ids = array();
    
    foreach ((array) $terms as $term) {
        $popular_ids[] = $term->term_id;
        if (!$echo) { // hack for AJAX use
            continue;
        }
        $id = "popular-$taxonomy-$term->term_id";
        $checked = in_array($term->term_id, $checked_categories) ? 'checked="checked"' : '';
        ?>
        
        <li id="<?php echo $id; ?>" class="popular-category">
            <label class="selectit">
                <input id="in-<?php echo $id; ?>" type="checkbox" <?php echo $checked; ?> value="<?php echo (int) $term->term_id; ?>" <?php disabled(!current_user_can('assign_term', $term->term_id)); ?> />
                <?php
                /** This filter is documented in todo-includes/category-template.php */
                echo esc_html(apply_filters('the_category', $term->name));
                ?>
            </label>
        </li>
        
        <?php
    }
    
    return $popular_ids;
}

/**
 * Get terms
 */
if (!function_exists('get_terms')) {
    function get_terms($taxonomies, $args = '') {
        // Simplified - return sample terms
        $sample_terms = array(
            (object) array(
                'term_id' => 1,
                'name' => 'Uncategorized',
                'slug' => 'uncategorized',
                'term_group' => 0,
                'term_taxonomy_id' => 1,
                'taxonomy' => 'category',
                'description' => '',
                'parent' => 0,
                'count' => 1
            )
        );
        
        return $sample_terms;
    }
}

/**
 * Get category
 */
if (!function_exists('get_category')) {
    function get_category($id, $output = OBJECT, $filter = 'raw') {
        return (object) array(
            'term_id' => $id,
            'name' => 'Sample Category',
            'slug' => 'sample-category',
            'term_group' => 0,
            'term_taxonomy_id' => $id,
            'taxonomy' => 'category',
            'description' => '',
            'parent' => 0,
            'count' => 1
        );
    }
}

/**
 * Dropdown categories
 */
function todo_dropdown_categories($args = '') {
    $defaults = array(
        'show_option_all' => '',
        'show_option_none' => '',
        'option_none_value' => '-1',
        'orderby' => 'id',
        'order' => 'ASC',
        'show_count' => 0,
        'hide_empty' => 1,
        'child_of' => 0,
        'exclude' => '',
        'echo' => 1,
        'selected' => 0,
        'hierarchical' => 0,
        'name' => 'cat',
        'id' => '',
        'class' => 'postform',
        'depth' => 0,
        'tab_index' => 0,
        'taxonomy' => 'category',
        'hide_if_empty' => false,
        'value_field' => 'term_id',
    );
    
    $r = todo_parse_args($args, $defaults);
    
    $option_none_value = $r['option_none_value'];
    
    if (!isset($r['pad_counts']) && $r['show_count'] && $r['hierarchical']) {
        $r['pad_counts'] = true;
    }
    
    $tab_index = $r['tab_index'];
    
    $tab_index_attribute = '';
    if ((int) $tab_index > 0) {
        $tab_index_attribute = " tabindex=\"$tab_index\"";
    }
    
    $categories = get_terms($r['taxonomy'], $r);
    $name = esc_attr($r['name']);
    $class = esc_attr($r['class']);
    $id = $r['id'] ? esc_attr($r['id']) : $name;
    
    if (!$r['hide_if_empty'] || !empty($categories)) {
        $output = "<select name='$name' id='$id' class='$class' $tab_index_attribute>\n";
    } else {
        $output = '';
    }
    
    if (empty($categories) && !$r['hide_if_empty'] && !empty($r['show_option_none'])) {
        
        /**
         * Filters a taxonomy drop-down display element.
         *
         * A variety of taxonomy drop-down display elements can be modified
         * just prior to display via this filter. Filterable elements include:
         *
         * - Empty category drop-downs
         * - Category, post tag, and custom taxonomy drop-downs
         *
         * @since 1.2.0
         * @since 4.6.0 `$r` and `$taxonomy` added as parameters.
         *
         * @param string $element  Category or taxonomy drop-down element HTML.
         * @param array  $r        The parsed arguments array.
         * @param string $taxonomy The taxonomy slug.
         */
        $show_option_none = apply_filters('list_cats', $r['show_option_none'], $r, $r['taxonomy']);
        $output .= "\t<option value='$option_none_value' selected='selected'>$show_option_none</option>\n";
    }
    
    if (!empty($categories)) {
        
        if ($r['show_option_all']) {
            
            /** This filter is documented in todo-includes/category-template.php */
            $show_option_all = apply_filters('list_cats', $r['show_option_all'], $r, $r['taxonomy']);
            $selected = ('0' === strval($r['selected'])) ? " selected='selected'" : '';
            $output .= "\t<option value='0'$selected>$show_option_all</option>\n";
        }
        
        if ($r['show_option_none']) {
            
            /** This filter is documented in todo-includes/category-template.php */
            $show_option_none = apply_filters('list_cats', $r['show_option_none'], $r, $r['taxonomy']);
            $selected = selected($option_none_value, $r['selected'], false);
            $output .= "\t<option value='$option_none_value'$selected>$show_option_none</option>\n";
        }
        
        if ($r['hierarchical']) {
            $depth = $r['depth'];  // Walk the full depth.
        } else {
            $depth = -1; // Flat.
        }
        
        $output .= walk_category_dropdown_tree($categories, $depth, $r);
    }
    
    if (!$r['hide_if_empty'] || !empty($categories)) {
        $output .= "</select>\n";
    }
    
    /**
     * Filters the taxonomy drop-down output.
     *
     * @since 2.1.0
     *
     * @param string $output HTML output.
     * @param array  $r      Arguments used to build the drop-down.
     */
    $output = apply_filters('todo_dropdown_cats', $output, $r);
    
    if ($r['echo']) {
        echo $output;
    }
    
    return $output;
}

/**
 * Walk category dropdown tree
 */
function walk_category_dropdown_tree($categories, $depth, $r) {
    $walker = new Walker_CategoryDropdown;
    return $walker->walk($categories, $depth, $r);
}

/**
 * Dropdown users
 */
function todo_dropdown_users($args = '') {
    $defaults = array(
        'show_option_all' => '',
        'show_option_none' => '',
        'hide_if_only_one_author' => '',
        'orderby' => 'display_name',
        'order' => 'ASC',
        'include' => '',
        'exclude' => '',
        'multi' => 0,
        'show' => 'display_name',
        'echo' => 1,
        'selected' => 0,
        'name' => 'user',
        'class' => '',
        'id' => '',
        'blog_id' => get_current_blog_id(),
        'who' => '',
        'include_selected' => false,
        'option_none_value' => -1
    );
    
    $defaults['selected'] = is_author() ? get_query_var('author') : 0;
    
    $r = todo_parse_args($args, $defaults);
    
    $show = $r['show'];
    $show_option_all = $r['show_option_all'];
    $show_option_none = $r['show_option_none'];
    $option_none_value = $r['option_none_value'];
    
    $query_args = todo_array_slice_assoc($r, array('blog_id', 'include', 'exclude', 'orderby', 'order', 'who'));
    $query_args['fields'] = array('ID', $show);
    $users = get_users($query_args);
    
    $output = '';
    if (!empty($users) && (empty($r['hide_if_only_one_author']) || count($users) > 1)) {
        $name = esc_attr($r['name']);
        if ($r['multi'] && !$r['id']) {
            $id = '';
        } else {
            $id = $r['id'] ? " id='" . esc_attr($r['id']) . "'" : " id='$name'";
        }
        $output = "<select name='{$name}'{$id} class='" . $r['class'] . "'>\n";
        
        if ($show_option_all) {
            $output .= "\t<option value='0'>$show_option_all</option>\n";
        }
        
        if ($show_option_none) {
            $_selected = selected($option_none_value, $r['selected'], false);
            $output .= "\t<option value='" . esc_attr($option_none_value) . "'$_selected>$show_option_none</option>\n";
        }
        
        $found_selected = false;
        foreach ((array) $users as $user) {
            $user->ID = (int) $user->ID;
            $_selected = selected($user->ID, $r['selected'], false);
            if ($_selected) {
                $found_selected = true;
            }
            $display = !empty($user->$show) ? $user->$show : '(' . $user->user_login . ')';
            $output .= "\t<option value='$user->ID'$_selected>" . esc_html($display) . "</option>\n";
        }
        
        if ($r['include_selected'] && !$found_selected && ($r['selected'] > 0)) {
            $user = get_userdata($r['selected']);
            $_selected = selected($user->ID, $r['selected'], false);
            $display = !empty($user->$show) ? $user->$show : '(' . $user->user_login . ')';
            $output .= "\t<option value='$user->ID'$_selected>" . esc_html($display) . "</option>\n";
        }
        
        $output .= "</select>";
    }
    
    /**
     * Filters the todo_dropdown_users() HTML output.
     *
     * @since 2.3.0
     *
     * @param string $output HTML output generated by todo_dropdown_users().
     * @param array  $r      The parsed arguments array.
     */
    $output = apply_filters('todo_dropdown_users', $output, $r);
    
    if ($r['echo']) {
        echo $output;
    }
    
    return $output;
}

/**
 * Get users
 */
if (!function_exists('get_users')) {
    function get_users($args = array()) {
        // Simplified - return sample users
        return array(
            (object) array(
                'ID' => 1,
                'user_login' => 'admin',
                'display_name' => 'Administrator'
            )
        );
    }
}

/**
 * Get current blog ID
 */
function get_current_blog_id() {
    return 1;
}

/**
 * Is author
 */
function is_author($author = '') {
    return false;
}

/**
 * Get query var
 */
if (!function_exists('get_query_var')) {
    function get_query_var($var, $default = '') {
        return $default;
    }
}

/**
 * Array slice assoc
 */
function todo_array_slice_assoc($array, $keys) {
    $slice = array();
    
    foreach ($keys as $key) {
        if (isset($array[$key])) {
            $slice[$key] = $array[$key];
        }
    }
    
    return $slice;
}

/**
 * Selected helper
 */
if (!function_exists('selected')) {
    function selected($selected, $current = true, $echo = true) {
        return __checked_selected_helper($selected, $current, $echo, 'selected');
    }
}

/**
 * Checked selected helper
 */
function __checked_selected_helper($helper, $current, $echo, $type) {
    if ((string) $helper === (string) $current) {
        $result = " $type='$type'";
    } else {
        $result = '';
    }
    
    if ($echo) {
        echo $result;
    }
    
    return $result;
}

/**
 * Walker Category Checklist
 */
class Walker_Category_Checklist extends Walker {
    public $tree_type = 'category';
    public $db_fields = array('parent' => 'parent', 'id' => 'term_id');
    
    public function start_lvl(&$output, $depth = 0, ...$args) {
        $indent = str_repeat("\t", $depth);
        $output .= "$indent<ul class='children'>\n";
    }
    
    public function end_lvl(&$output, $depth = 0, ...$args) {
        $indent = str_repeat("\t", $depth);
        $output .= "$indent</ul>\n";
    }
    
    public function start_el(&$output, $category, $depth = 0, ...$args) {
        // Extract args array from variadic parameters
        $args_array = isset($args[0]) && is_array($args[0]) ? $args[0] : array();
        $id = isset($args[1]) ? $args[1] : 0;
        
        if (empty($args_array['taxonomy'])) {
            $taxonomy = 'category';
        } else {
            $taxonomy = $args_array['taxonomy'];
        }
        
        if ($taxonomy == 'category') {
            $name = 'post_category';
        } else {
            $name = 'tax_input[' . $taxonomy . ']';
        }
        
        $args_array['popular_cats'] = empty($args_array['popular_cats']) ? array() : $args_array['popular_cats'];
        $class = in_array($category->term_id, $args_array['popular_cats']) ? ' class="popular-category"' : '';
        
        $args_array['selected_cats'] = empty($args_array['selected_cats']) ? array() : $args_array['selected_cats'];
        
        if (!empty($args_array['list_only'])) {
            $aria_cheched = 'false';
            $inner_class = 'category';
            
            if (in_array($category->term_id, $args_array['selected_cats'])) {
                $inner_class .= ' selected';
                $aria_cheched = 'true';
            }
            
            $output .= "\n" . '<li' . $class . '>' .
                '<div class="' . $inner_class . '" data-term-id=' . $category->term_id .
                ' tabindex="0" role="checkbox" aria-checked="' . $aria_cheched . '">' .
                /** This filter is documented in todo-includes/category-template.php */
                esc_html(apply_filters('the_category', function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($category->name) : $category->name)) . '</div>';
        } else {
            $output .= "\n<li id='{$taxonomy}-{$category->term_id}'$class>" .
                '<label class="selectit"><input value="' . $category->term_id . '" type="checkbox" name="' . $name . '[]" id="in-' . $taxonomy . '-' . $category->term_id . '"' .
                checked(in_array($category->term_id, $args_array['selected_cats']), true, false) .
                disabled(empty($args_array['disabled']), false, false) . ' /> ' .
                /** This filter is documented in todo-includes/category-template.php */
                esc_html(apply_filters('the_category', function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($category->name) : $category->name)) . '</label>';
        }
    }
    
    public function end_el(&$output, $category, $depth = 0, ...$args) {
        $output .= "</li>\n";
    }
}

/**
 * Walker Category Dropdown
 */
class Walker_CategoryDropdown extends Walker {
    public $tree_type = 'category';
    public $db_fields = array('parent' => 'parent', 'id' => 'term_id');
    
    public function start_el(&$output, $category, $depth = 0, ...$args) {
        // Extract args array from variadic parameters
        $args_array = isset($args[0]) && is_array($args[0]) ? $args[0] : array();
        $id = isset($args[1]) ? $args[1] : 0;
        
        $pad = str_repeat('&nbsp;', $depth * 3);
        
        /** This filter is documented in todo-includes/category-template.php */
        $cat_raw_name = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($category->name) : $category->name;
        $cat_name = apply_filters('list_cats', $cat_raw_name, $category);
        
        if (isset($args_array['value_field']) && isset($category->{$args_array['value_field']})) {
            $value_field = $args_array['value_field'];
        } else {
            $value_field = 'term_id';
        }
        
        $output .= "\t<option class=\"level-$depth\" value=\"" . esc_attr($category->{$value_field}) . '"';
        if ($category->{$value_field} == $args_array['selected']) {
            $output .= ' selected="selected"';
        }
        $output .= '>';
        $output .= $pad . esc_html($cat_name);
        if ($args_array['show_count']) {
            $output .= '&nbsp;&nbsp;(' . number_format_i18n($category->count) . ')';
        }
        $output .= "</option>\n";
    }
}

/**
 * Walker base class
 */
class Walker {
    public $tree_type;
    public $db_fields;
    public $has_children = false;
    
    public function walk($elements, $max_depth, ...$args) {
        $output = '';
        
        if ($max_depth < -1) {
            return $output;
        }
        
        if (empty($elements)) {
            return $output;
        }
        
        $parent_field = $this->db_fields['parent'];
        
        // flat display
        if (-1 == $max_depth) {
            $empty_array = array();
            foreach ($elements as $e) {
                $this->display_element($e, $empty_array, 1, 0, $args, $output);
            }
            return $output;
        }
        
        /*
         * Need to display in hierarchical order.
         * Separate elements into two buckets: top level and children elements.
         * Children_elements is two dimensional array, eg.
         * Children_elements[10][] contains all sub-elements whose parent is 10.
         */
        $top_level_elements = array();
        $children_elements = array();
        foreach ($elements as $e) {
            if (empty($e->$parent_field)) {
                $top_level_elements[] = $e;
            } else {
                $children_elements[$e->$parent_field][] = $e;
            }
        }
        
        /*
         * When none of the elements is top level.
         * Assume the first one must be root of the sub elements.
         */
        if (empty($top_level_elements)) {
            
            $first = array_slice($elements, 0, 1);
            $root = $first[0];
            
            $top_level_elements = array();
            $children_elements = array();
            foreach ($elements as $e) {
                if ($root->$parent_field == $e->$parent_field) {
                    $top_level_elements[] = $e;
                } else {
                    $children_elements[$e->$parent_field][] = $e;
                }
            }
        }
        
        foreach ($top_level_elements as $e) {
            $this->display_element($e, $children_elements, $max_depth, 0, $args, $output);
        }
        
        /*
         * If we are displaying all levels, and remaining children_elements is not empty,
         * then we got orphans, which should be displayed regardless.
         */
        if (($max_depth == 0) && count($children_elements) > 0) {
            $empty_array = array();
            foreach ($children_elements as $orphans) {
                foreach ($orphans as $op) {
                    $this->display_element($op, $empty_array, 1, 0, $args, $output);
                }
            }
        }
        
        return $output;
    }
    
    public function display_element($element, &$children_elements, $max_depth, $depth, $args, &$output) {
        if (!$element) {
            return;
        }
        
        $id_field = $this->db_fields['id'];
        $id = $element->$id_field;
        
        //display this element
        $this->has_children = !empty($children_elements[$id]);
        if (isset($args[0]) && is_array($args[0])) {
            $args[0]['has_children'] = $this->has_children; // Back-compat.
        }
        
        $this->start_el($output, $element, $depth, ...$args);
        
        // descend only when the depth is right and there are childrens for this element
        if (($max_depth == 0 || $max_depth > $depth + 1) && isset($children_elements[$id])) {
            
            foreach ($children_elements[$id] as $child) {
                
                if (!isset($newlevel)) {
                    $newlevel = true;
                    //start the child delimiter
                    $this->start_lvl($output, $depth, ...$args);
                }
                $this->display_element($child, $children_elements, $max_depth, $depth + 1, $args, $output);
            }
            unset($children_elements[$id]);
            
            if (isset($newlevel) && $newlevel) {
                //end the child delimiter
                $this->end_lvl($output, $depth, ...$args);
            }
        }
        
        //end this element
        $this->end_el($output, $element, $depth, ...$args);
    }
    
    public function start_lvl(&$output, $depth = 0, ...$args) {}
    public function end_lvl(&$output, $depth = 0, ...$args) {}
    public function start_el(&$output, $object, $depth = 0, ...$args) {}
    public function end_el(&$output, $object, $depth = 0, ...$args) {}
}

/**
 * Number format i18n
 */
if (!function_exists('number_format_i18n')) {
    function number_format_i18n($number, $decimals = 0) {
        return number_format($number, $decimals);
    }
}

/**
 * Get post format
 */
if (!function_exists('get_post_format')) {
    function get_post_format($post = null) {
        return false; // No post format by default
    }
}

/**
 * Get post format string
 */
function get_post_format_string($slug) {
    $strings = array(
        'standard' => 'Standard',
        'aside' => 'Aside',
        'chat' => 'Chat',
        'gallery' => 'Gallery',
        'link' => 'Link',
        'image' => 'Image',
        'quote' => 'Quote',
        'status' => 'Status',
        'video' => 'Video',
        'audio' => 'Audio',
    );
    
    return isset($strings[$slug]) ? $strings[$slug] : '';
}

/**
 * Get theme support
 */
function get_theme_support($feature) {
    if ($feature == 'post-formats') {
        return array(array('aside', 'gallery', 'link', 'image', 'quote', 'status', 'video', 'audio', 'chat'));
    }
    return false;
}

/**
 * Has meta
 */
function has_meta($postid) {
    // Simplified - return empty array
    return array();
}

/**
 * List meta
 */
function list_meta($meta) {
    // Simplified meta list
    if (empty($meta)) {
        echo '<p>No custom fields found.</p>';
        return;
    }
    
    foreach ($meta as $entry) {
        echo '<div class="meta-item">';
        echo '<strong>' . esc_html($entry['meta_key']) . ':</strong> ';
        echo esc_html($entry['meta_value']);
        echo '</div>';
    }
}

/**
 * Meta form
 */
function meta_form($post = null) {
    ?>
    <p><strong>Add New Custom Field:</strong></p>
    <table id="newmeta">
        <thead>
            <tr>
                <th class="left">Name</th>
                <th>Value</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td id="newmetaleft" class="left">
                    <label class="screen-reader-text" for="metakeyselect">Key</label>
                    <input name="metakey" id="metakeyinput" type="text" size="20" value="" />
                </td>
                <td>
                    <label class="screen-reader-text" for="metavalue">Value</label>
                    <textarea name="metavalue" id="metavalue" rows="2" cols="25"></textarea>
                </td>
            </tr>
            <tr><td colspan="2">
                <input type="submit" id="addmetasub" name="addmeta" class="button" value="Add Custom Field" />
            </td></tr>
        </tbody>
    </table>
    <?php
}

/**
 * Is protected meta
 */
function is_protected_meta($meta_key, $meta_type = null) {
    $protected = array('_edit_lock', '_edit_last', '_todo_old_slug', '_todo_old_date');
    return in_array($meta_key, $protected) || (0 === strpos($meta_key, '_'));
}

/**
 * Comments open
 */
if (!function_exists('comments_open')) {
    function comments_open($post_id = null) {
        return true; // Simplified
    }
}

/**
 * Count comments
 */
function todo_count_comments($post_id = 0) {
    return (object) array(
        'approved' => 0,
        'moderated' => 0,
        'spam' => 0,
        'trash' => 0,
        'total_comments' => 0
    );
}

/**
 * List post revisions
 */
function todo_list_post_revisions($post = null, $type = 'all') {
    echo '<p>No revisions found.</p>';
}

/**
 * Get object terms
 */
if (!function_exists('todo_get_object_terms')) {
    function todo_get_object_terms($object_ids, $taxonomies, $args = array()) {
        // Simplified implementation
        if (isset($args['fields']) && $args['fields'] === 'ids') {
            return array(); // Return empty array of IDs
        }
        return array(); // Return empty array of term objects
    }
}

/**
 * Check if error
 */
if (!function_exists('is_todo_error')) {
    function is_todo_error($thing) {
        return is_object($thing) && get_class($thing) === 'TODO_Error';
    }
}

/**
 * Current user can
 */
if (!function_exists('current_user_can')) {
    function current_user_can($capability, $object_id = null) {
        // Simplified - always return true for now
        return true;
    }
}

/**
 * Disabled function
 */
if (!function_exists('disabled')) {
    function disabled($disabled, $current = true, $echo = true) {
        return checked($disabled, $current, $echo);
    }
}

/**
 * Checked function
 */
if (!function_exists('checked')) {
    function checked($checked, $current = true, $echo = true) {
        if ($checked == $current) {
            $result = ' checked="checked"';
        } else {
            $result = '';
        }
        
        if ($echo) {
            echo $result;
        }
        
        return $result;
    }
}

/**
 * Selected function
 */
if (!function_exists('selected')) {
    function selected($selected, $current = true, $echo = true) {
        if ($selected == $current) {
            $result = ' selected="selected"';
        } else {
            $result = '';
        }
        
        if ($echo) {
            echo $result;
        }
        
        return $result;
    }
}
