<?php
/**
 * TodoCMS Meta Boxes for TodoCMS
 *
 * @package TodoCMS
 * @subpackage Administration
 */

// Don't load directly
if (!defined('ABSPATH')) {
    die('-1');
}

// 确保加载核心函数
if (!function_exists('is_sticky')) {
    require_once ABSPATH . 'core/functions/post.php';
}

/**
 * Post submit meta box
 */
function post_submit_meta_box($post, $args = array()) {
    global $action;
    
    // 基于用户权限的发布能力检查
    $can_publish = current_user_can('publish_posts');
    ?>
    <div class="submitbox" id="submitpost">
        <div id="minor-publishing">
            <div style="display:none;">
                <?php submit_button(__('Save'), '', 'save'); ?>
            </div>
            
            <div id="minor-publishing-actions">
                <div id="save-action">
                    <?php if ('publish' != $post->post_status && 'future' != $post->post_status && 'pending' != $post->post_status) { ?>
                        <input <?php if ('private' == $post->post_status) { ?>style="display:none"<?php } ?> type="submit" name="save" id="save-post" value="<?php esc_attr_e('Save Draft'); ?>" class="button" />
                        <span class="spinner"></span>
                    <?php } elseif ('pending' == $post->post_status && $can_publish) { ?>
                        <input type="submit" name="save" id="save-post" value="<?php esc_attr_e('Save as Pending'); ?>" class="button" />
                        <span class="spinner"></span>
                    <?php } ?>
                </div>
                
                <div id="preview-action">
                    <?php
                    $preview_link = esc_url(get_preview_post_link($post));
                    if ($preview_link) {
                        $preview_button = __('Preview');
                        ?>
                        <a class="preview button" href="<?php echo $preview_link; ?>" target="todo-preview-<?php echo (int) $post->ID; ?>" id="post-preview"><?php echo $preview_button; ?></a>
                        <input type="hidden" name="todo-preview" id="todo-preview" value="" />
                        <?php
                    }
                    ?>
                    <span class="spinner"></span>
                </div>
                
                <div class="clear"></div>
            </div>
            
            <div id="misc-publishing-actions">
                <div class="misc-pub-section misc-pub-post-status">
                    <?php _e('Status:'); ?> <span id="post-status-display">
                    <?php
                    switch ($post->post_status) {
                        case 'private':
                            _e('Privately Published');
                            break;
                        case 'publish':
                            _e('Published');
                            break;
                        case 'future':
                            _e('Scheduled');
                            break;
                        case 'pending':
                            _e('Pending Review');
                            break;
                        case 'draft':
                        case 'auto-draft':
                        default:
                            _e('Draft');
                            break;
                    }
                    ?>
                    </span>
                    
                    <?php if ('publish' == $post->post_status || ('private' == $post->post_status && $can_publish)) { ?>
                        <a href="#post_status" <?php if ('private' == $post->post_status) { ?>style="display:none;" <?php } ?>class="edit-post-status hide-if-no-js" role="button"><span aria-hidden="true"><?php _e('Edit'); ?></span> <span class="screen-reader-text"><?php _e('Edit status'); ?></span></a>
                        
                        <div id="post-status-select" class="hide-if-js">
                            <input type="hidden" name="hidden_post_status" id="hidden_post_status" value="<?php echo esc_attr(('auto-draft' == $post->post_status) ? 'draft' : $post->post_status); ?>" />
                            <label for="post_status" class="screen-reader-text"><?php _e('Set status'); ?></label>
                            <select name="post_status" id="post_status">
                                <?php if ('publish' == $post->post_status) : ?>
                                    <option<?php selected($post->post_status, 'publish'); ?> value='publish'><?php _e('Published'); ?></option>
                                <?php elseif ('private' == $post->post_status) : ?>
                                    <option<?php selected($post->post_status, 'private'); ?> value='publish'><?php _e('Privately Published'); ?></option>
                                <?php elseif ('future' == $post->post_status) : ?>
                                    <option<?php selected($post->post_status, 'future'); ?> value='future'><?php _e('Scheduled'); ?></option>
                                <?php endif; ?>
                                <option<?php selected($post->post_status, 'pending'); ?> value='pending'><?php _e('Pending Review'); ?></option>
                                <?php if ('auto-draft' == $post->post_status) : ?>
                                    <option<?php selected($post->post_status, 'auto-draft'); ?> value='draft'><?php _e('Draft'); ?></option>
                                <?php else : ?>
                                    <option<?php selected($post->post_status, 'draft'); ?> value='draft'><?php _e('Draft'); ?></option>
                                <?php endif; ?>
                            </select>
                            <a href="#post_status" class="save-post-status hide-if-no-js button"><?php _e('OK'); ?></a>
                            <a href="#post_status" class="cancel-post-status hide-if-no-js button-cancel"><?php _e('Cancel'); ?></a>
                        </div>
                    <?php } ?>
                </div>
                
                <div class="misc-pub-section misc-pub-visibility" id="visibility">
                    <?php _e('Visibility:'); ?> <span id="post-visibility-display">
                    <?php
                    $post_password = get_post_meta($post->ID, 'post_password', true);
                    if ('private' == $post->post_status) {
                        $post_password = '';
                        $visibility = 'private';
                        $visibility_trans = __('Private');
                    } elseif (!empty($post_password)) {
                        $visibility = 'password';
                        $visibility_trans = __('Password protected');
                    } elseif ($post->post_type == 'article' && is_sticky($post->ID)) {
                        $visibility = 'public';
                        $visibility_trans = __('Public, Sticky');
                    } else {
                        $visibility = 'public';
                        $visibility_trans = __('Public');
                    }
                    
                    echo esc_html($visibility_trans);
                    ?>
                    </span>
                    
                    <a href="#visibility" class="edit-visibility hide-if-no-js" role="button"><span aria-hidden="true"><?php _e('Edit'); ?></span> <span class="screen-reader-text"><?php _e('Edit visibility'); ?></span></a>
                    
                    <div id="post-visibility-select" class="hide-if-js">
                        <input type="hidden" name="hidden_post_password" id="hidden-post-password" value="<?php echo esc_attr($post_password); ?>" />
                        <?php if ($post->post_type == 'article') : ?>
                            <input type="checkbox" style="display:none" name="hidden_post_sticky" id="hidden-post-sticky" value="sticky" <?php checked(is_sticky($post->ID)); ?> />
                        <?php endif; ?>
                        
                        <input type="hidden" name="hidden_post_visibility" id="hidden-post-visibility" value="<?php echo esc_attr($visibility); ?>" />
                        <input type="radio" name="visibility" id="visibility-radio-public" value="public" <?php checked($visibility, 'public'); ?> /> <label for="visibility-radio-public" class="selectit"><?php _e('Public'); ?></label><br />
                        <input type="radio" name="visibility" id="visibility-radio-readable" value="readable" <?php checked($visibility, 'readable'); ?> /> <label for="visibility-radio-readable" class="selectit">可读</label><br />
                        
                        <?php if ($post->post_type == 'article' && current_user_can('edit_others_posts')) : ?>
                            <span id="sticky-span"><input id="sticky" name="sticky" type="checkbox" value="sticky" <?php checked(is_sticky($post->ID)); ?> /> <label for="sticky" class="selectit"><?php _e('Stick this post to the front page'); ?></label><br /></span>
                        <?php endif; ?>
                        
                        <input type="radio" name="visibility" id="visibility-radio-password" value="password" <?php checked($visibility, 'password'); ?> /> <label for="visibility-radio-password" class="selectit"><?php _e('Password protected'); ?></label><br />
                        <span id="password-span"><label for="post_password" class="screen-reader-text"><?php _e('Password:'); ?></label><input type="text" name="post_password" id="post_password" value="<?php echo esc_attr($post_password); ?>"  maxlength="255" /><br /></span>
                        
                        <input type="radio" name="visibility" id="visibility-radio-private" value="private" <?php checked($visibility, 'private'); ?> /> <label for="visibility-radio-private" class="selectit"><?php _e('Private'); ?></label><br />
                        
                        <p>
                            <a href="#visibility" class="save-post-visibility hide-if-no-js button"><?php _e('OK'); ?></a>
                            <a href="#visibility" class="cancel-post-visibility hide-if-no-js button-cancel"><?php _e('Cancel'); ?></a>
                        </p>
                    </div>
                </div>
                
                <?php
                // translators: Publish box date format, see https://secure.php.net/date
                $datef = __('M j, Y @ H:i');
                if (0 != $post->ID) {
                    if ('future' == $post->post_status) { // scheduled for publishing at a future date
                        /* translators: Post date information. %s: Date on which the post is currently scheduled to be published */
                        $stamp = __('Scheduled for: <b>%1$s</b>');
                    } elseif ('publish' == $post->post_status || 'private' == $post->post_status) { // already published
                        /* translators: Post date information. %s: Date on which the post was published */
                        $stamp = __('Published on: <b>%1$s</b>');
                    } elseif ('0000-00-00 00:00:00' == $post->post_date_gmt) { // draft, 1 or more saves, no date specified
                        $stamp = __('Publish <b>immediately</b>');
                    } elseif (time() < strtotime($post->post_date_gmt . ' +0000')) { // draft, 1 or more saves, future date specified
                        /* translators: Post date information. %s: Date on which the post is currently scheduled to be published */
                        $stamp = __('Schedule for: <b>%1$s</b>');
                    } else { // draft, 1 or more saves, date specified
                        /* translators: Post date information. %s: Date on which the post is currently scheduled to be published */
                        $stamp = __('Publish on: <b>%1$s</b>');
                    }
                    $date = date_i18n($datef, strtotime($post->post_date));
                } else { // draft (no saves, and thus no date specified)
                    $stamp = __('Publish <b>immediately</b>');
                    $date = date_i18n($datef, strtotime(current_time('mysql')));
                }
                
                if (!empty($post->post_date_gmt) && ('0000-00-00 00:00:00' != $post->post_date_gmt)) {
                    $post_time = mysql2date('G', $post->post_date_gmt, false);
                } else {
                    $post_time = time();
                }
                ?>
                
                <div class="misc-pub-section curtime misc-pub-curtime">
                    <span id="timestamp">
                        <?php printf($stamp, $date); ?>
                    </span>
                    <a href="#edit_timestamp" class="edit-timestamp hide-if-no-js" role="button">
                        <span aria-hidden="true"><?php _e('Edit'); ?></span>
                        <span class="screen-reader-text"><?php _e('Edit date and time'); ?></span>
                    </a>
                    <fieldset id="timestampdiv" class="hide-if-js">
                        <legend class="screen-reader-text"><?php _e('Date and time'); ?></legend>
                        <?php touch_time(($action == 'edit'), 1); ?>
                    </fieldset>
                </div>
                
                <?php
                /**
                 * Fires after the post time/date setting in the Publish meta box.
                 *
                 * @since 2.9.0
                 * @since 4.4.0 Added the `$post` parameter.
                 *
                 * @param CMS_Post $post CMS_Post object for the current post.
                 */
                do_action('post_submitbox_misc_actions', $post);
                ?>
            </div>
            <div class="clear"></div>
        </div>
        
        <div id="major-publishing-actions">
            <?php
            /**
             * Fires at the beginning of the publishing actions section of the Publish meta box.
             *
             * @since 2.7.0
             * @since 4.9.0 Added the `$post` parameter.
             *
             * @param CMS_Post|null $post CMS_Post object for the current post on Edit Post screen,
             *                           null on Edit Link screen.
             */
            do_action('post_submitbox_start', $post);
            ?>
            <div id="delete-action">
                <?php
                if (current_user_can('delete_post', $post->ID)) {
                    if (!EMPTY_TRASH_DAYS) {
                        $delete_text = __('Delete Permanently');
                    } else {
                        $delete_text = __('Move to Trash');
                    }
                    ?>
                    <a class="submitdelete deletion" href="<?php echo get_delete_post_link($post->ID); ?>"><?php echo $delete_text; ?></a>
                    <?php
                }
                ?>
            </div>
            
            <div id="publishing-action">
                <span class="spinner"></span>
                <?php
                if (!in_array($post->post_status, array('publish', 'future', 'private')) || 0 == $post->ID) {
                    if ($can_publish) :
                        if (!empty($post->post_date_gmt) && time() < strtotime($post->post_date_gmt . ' +0000')) : ?>
                            <input name="original_publish" type="hidden" id="original_publish" value="<?php esc_attr_e('Schedule'); ?>" />
                            <?php submit_button(__('Schedule'), 'primary large', 'publish', false); ?>
                        <?php else : ?>
                            <input name="original_publish" type="hidden" id="original_publish" value="<?php esc_attr_e('Publish'); ?>" />
                            <?php submit_button(__('Publish'), 'primary large', 'publish', false); ?>
                        <?php endif;
                    else : ?>
                        <input name="original_publish" type="hidden" id="original_publish" value="<?php esc_attr_e('Submit for Review'); ?>" />
                        <?php submit_button(__('Submit for Review'), 'primary large', 'publish', false); ?>
                    <?php endif;
                } else { ?>
                    <input name="original_publish" type="hidden" id="original_publish" value="<?php esc_attr_e('Update'); ?>" />
                    <input name="save" type="submit" class="button button-primary button-large" id="publish" value="<?php esc_attr_e('Update'); ?>" />
                <?php } ?>
            </div>
            <div class="clear"></div>
        </div>
    </div>
    <?php
}

/**
 * Post format meta box
 */
function post_format_meta_box($post, $box) {
    // 检查当前数据模型是否支持文章格式
    $model_supports_formats = in_array($post->post_type, array('article', 'media', 'images'));
    if (theme_supports('post-formats') && $model_supports_formats) :
        $post_formats = get_theme_support('post-formats');
        
        if (is_array($post_formats[0])) :
            $post_format = get_post_format($post->ID);
            if (!$post_format) {
                $post_format = '0';
            }
            // Add in the current one if it isn't there yet, in case the current theme doesn't support it.
            if ($post_format && !in_array($post_format, $post_formats[0])) {
                $post_formats[0][] = $post_format;
            }
            ?>
            <div id="post-formats-select">
                <fieldset>
                    <legend class="screen-reader-text"><?php _e('Post Formats'); ?></legend>
                    <input type="radio" name="post_format" class="post-format" id="post-format-0" value="0" <?php checked($post_format, '0'); ?> /> <label for="post-format-0" class="post-format-icon post-format-standard"><?php echo get_post_format_string('standard'); ?></label>
                    <?php foreach ($post_formats[0] as $format) : ?>
                        <br /><input type="radio" name="post_format" class="post-format" id="post-format-<?php echo esc_attr($format); ?>" value="<?php echo esc_attr($format); ?>" <?php checked($post_format, $format); ?> /> <label for="post-format-<?php echo esc_attr($format); ?>" class="post-format-icon post-format-<?php echo esc_attr($format); ?>"><?php echo esc_html(get_post_format_string($format)); ?></label>
                    <?php endforeach; ?>
                </fieldset>
            </div>
            <?php
        endif;
    endif;
}

/**
 * Categories meta box
 */
function post_categories_meta_box($post, $box) {
    $defaults = array('taxonomy' => 'category');
    if (!isset($box['args']) || !is_array($box['args'])) {
        $args = array();
    } else {
        $args = $box['args'];
    }
    
    $r = todo_parse_args($args, $defaults);
    $tax_name = esc_attr($r['taxonomy']);
    $taxonomy = get_taxonomy($r['taxonomy']);
    ?>
    <div id="taxonomy-<?php echo $tax_name; ?>" class="categorydiv">
        <ul id="<?php echo $tax_name; ?>-tabs" class="category-tabs">
            <li class="tabs"><a href="#<?php echo $tax_name; ?>-all"><?php echo $taxonomy->labels->all_items; ?></a></li>
            <li class="hide-if-no-js"><a href="#<?php echo $tax_name; ?>-pop"><?php _e('Most Used'); ?></a></li>
        </ul>
        
        <div id="<?php echo $tax_name; ?>-pop" class="tabs-panel" style="display: none;">
            <ul id="<?php echo $tax_name; ?>checklist-pop" class="categorychecklist form-no-clear" >
                <?php $popular_ids = todo_popular_terms_checklist($tax_name); ?>
            </ul>
        </div>
        
        <div id="<?php echo $tax_name; ?>-all" class="tabs-panel">
            <?php
            $name = ($tax_name == 'category') ? 'post_category' : 'tax_input[' . $tax_name . ']';
            echo "<input type='hidden' name='{$name}[]' value='0' />"; // Allows for an empty term set to be sent. 0 is an invalid Term ID and will be ignored by empty() checks.
            ?>
            <ul id="<?php echo $tax_name; ?>checklist" data-todo-lists="list:<?php echo $tax_name; ?>" class="categorychecklist form-no-clear">
                <?php todo_terms_checklist($post->ID, array('taxonomy' => $tax_name, 'popular_cats' => $popular_ids)); ?>
            </ul>
        </div>
        
        <?php if (current_user_can($taxonomy->cap->edit_terms)) : ?>
            <div id="<?php echo $tax_name; ?>-adder" class="todo-hidden-children">
                <a id="<?php echo $tax_name; ?>-add-toggle" href="#<?php echo $tax_name; ?>-add" class="hide-if-no-js taxonomy-add-new">
                    <?php
                    /* translators: %s: add new taxonomy label */
                    printf(__('+ %s'), $taxonomy->labels->add_new_item);
                    ?>
                </a>
                <p id="<?php echo $tax_name; ?>-add" class="category-add todo-hidden-child">
                    <label class="screen-reader-text" for="new<?php echo $tax_name; ?>"><?php echo $taxonomy->labels->add_new_item; ?></label>
                    <input type="text" name="new<?php echo $tax_name; ?>" id="new<?php echo $tax_name; ?>" class="form-required form-input-tip" value="<?php echo esc_attr($taxonomy->labels->new_item_name); ?>" aria-required="true"/>
                    <label class="screen-reader-text" for="new<?php echo $tax_name; ?>_parent">
                        <?php echo $taxonomy->labels->parent_item_colon; ?>
                    </label>
                    <?php
                    $parent_dropdown_args = array(
                        'taxonomy'         => $tax_name,
                        'hide_empty'       => 0,
                        'name'             => 'new' . $tax_name . '_parent',
                        'orderby'          => 'name',
                        'hierarchical'     => 1,
                        'show_option_none' => '&mdash; ' . $taxonomy->labels->parent_item . ' &mdash;'
                    );
                    
                    /**
                     * Filters the arguments for the taxonomy parent dropdown on the Post Edit page.
                     *
                     * @since 4.4.0
                     *
                     * @param array $parent_dropdown_args {
                     *     Optional. Array of arguments to generate parent dropdown.
                     *
                     *     @type string   $taxonomy         Name of the taxonomy to retrieve.
                     *     @type bool     $hide_if_empty    True to skip generating markup if no
                     *                                      categories are found. Default 0.
                     *     @type string   $name             Value for the 'name' attribute
                     *                                      of the select element.
                     *                                      Default "new{$tax_name}_parent".
                     *     @type string   $orderby          Which column to use for ordering
                     *                                      terms. Default 'name'.
                     *     @type bool|int $hierarchical     Whether to traverse the taxonomy
                     *                                      hierarchy. Default 1.
                     *     @type string   $show_option_none Text to display for the "none" option.
                     *                                      Default "— {$parent} —", where `$parent`
                     *                                      is 'parent_item' taxonomy label.
                     * }
                     */
                    $parent_dropdown_args = apply_filters('post_edit_category_parent_dropdown_args', $parent_dropdown_args);
                    
                    todo_dropdown_categories($parent_dropdown_args);
                    ?>
                    <input type="button" id="<?php echo $tax_name; ?>-add-submit" data-todo-lists="add:<?php echo $tax_name; ?>checklist:<?php echo $tax_name; ?>-add" class="button category-add-submit" value="<?php echo esc_attr($taxonomy->labels->add_new_item); ?>" />
                    <?php echo todo_csrf_field('add-' . $tax_name, '_ajax_nonce-add-' . $tax_name, false); ?>
                    <span id="<?php echo $tax_name; ?>-ajax-response"></span>
                </p>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Tags meta box
 */
function post_tags_meta_box($post, $box) {
    $defaults = array('taxonomy' => 'post_tag');
    if (!isset($box['args']) || !is_array($box['args'])) {
        $args = array();
    } else {
        $args = $box['args'];
    }
    
    $r = todo_parse_args($args, $defaults);
    $tax_name = esc_attr($r['taxonomy']);
    $taxonomy = get_taxonomy($r['taxonomy']);
    $user_can_assign_terms = current_user_can($taxonomy->cap->assign_terms);
    $comma = _x(',', 'tag delimiter');
    $terms_to_edit = get_terms_to_edit($post->ID, $tax_name);
    if (!is_string($terms_to_edit)) {
        $terms_to_edit = '';
    }
    ?>
    <div class="tagsdiv" id="<?php echo $tax_name; ?>">
        <div class="jaxtag">
            <div class="nojs-tags hide-if-js">
                <label for="tax-input-<?php echo $tax_name; ?>"><?php echo $taxonomy->labels->add_or_remove_items; ?></label>
                <p><textarea name="<?php echo "tax_input[$tax_name]"; ?>" rows="3" cols="20" class="the-tags" id="tax-input-<?php echo $tax_name; ?>" <?php disabled(!$user_can_assign_terms); ?> aria-describedby="new-tag-<?php echo $tax_name; ?>-desc"><?php echo str_replace(',', $comma . ' ', $terms_to_edit); // textarea_escaped by esc_attr() ?></textarea></p>
            </div>
            
            <?php if ($user_can_assign_terms) : ?>
                <div class="ajaxtag hide-if-no-js">
                    <label class="screen-reader-text" for="new-tag-<?php echo $tax_name; ?>"><?php echo $taxonomy->labels->add_new_item; ?></label>
                    <p><input data-todo-taxonomy="<?php echo $tax_name; ?>" type="text" id="new-tag-<?php echo $tax_name; ?>" name="newtag[<?php echo $tax_name; ?>]" class="newtag form-input-tip" size="16" autocomplete="off" aria-describedby="new-tag-<?php echo $tax_name; ?>-desc" value="" />
                        <input type="button" class="button tagadd" value="<?php esc_attr_e('Add'); ?>" /></p>
                </div>
                <p class="howto" id="new-tag-<?php echo $tax_name; ?>-desc"><?php echo $taxonomy->labels->separate_items_with_commas; ?></p>
            <?php elseif (empty($terms_to_edit)) : ?>
                <p><?php echo $taxonomy->labels->no_terms; ?></p>
            <?php endif; ?>
        </div>
        
        <div class="tagchecklist"></div>
    </div>
    
    <?php if ($user_can_assign_terms) : ?>
        <p class="hide-if-no-js"><button type="button" class="button-link tagcloud-link" id="link-<?php echo $tax_name; ?>" aria-describedby="new-tag-<?php echo $tax_name; ?>-desc"><?php echo $taxonomy->labels->choose_from_most_used; ?></button></p>
    <?php endif; ?>
    <?php
}

/**
 * Excerpt meta box
 */
function post_excerpt_meta_box($post) {
    ?>
    <label class="screen-reader-text" for="excerpt"><?php _e('Excerpt'); ?></label>
    <textarea rows="1" cols="40" name="excerpt" id="excerpt"><?php echo $post->post_excerpt; // textarea_escaped ?></textarea>
    <p><?php _e('Excerpts are optional hand-crafted summaries of your content that can be used in your theme. <a href="http://codex.todocms.com/Excerpt" target="_blank">Learn more about manual excerpts</a>.'); ?></p>
    <?php
}

/**
 * Trackbacks meta box
 */
function post_trackback_meta_box($post) {
    $form_trackback = '<input type="text" name="trackback_url" id="trackback_url" class="code" value="' . esc_attr(str_replace("\n", ' ', $post->to_ping)) . '" aria-describedby="trackback-url-desc" />';
    if ('' != $post->pinged) {
        $pings = '<p>' . __('Already pinged:') . '</p><ul>';
        $already_pinged = explode("\n", trim($post->pinged));
        foreach ($already_pinged as $pinged_url) {
            $pings .= "\n\t<li>" . esc_html($pinged_url) . "</li>";
        }
        $pings .= '</ul>';
    }
    
    ?>
    <p>
        <label for="trackback_url"><?php _e('Send trackbacks to:'); ?></label>
        <?php echo $form_trackback; ?>
    </p>
    <p id="trackback-url-desc" class="howto"><?php _e('Separate multiple URLs with spaces'); ?></p>
    
    <?php
    if (!empty($pings)) {
        echo $pings;
    }
}

/**
 * Custom fields meta box
 */
function post_custom_meta_box($post) {
    ?>
    <div id="postcustomstuff">
        <div id="ajax-response"></div>
        <?php
        $metadata = has_meta($post->ID);
        foreach ($metadata as $key => $value) {
            if (is_protected_meta($metadata[$key]['meta_key'], 'post')) {
                unset($metadata[$key]);
            }
        }
        list_meta($metadata);
        meta_form($post);
        ?>
    </div>
    <p><?php _e('Custom fields can be used to add extra metadata to a post that you can <a href="http://codex.todocms.com/Using_Custom_Fields" target="_blank">use in your theme</a>.'); ?></p>
    <?php
}

/**
 * Comment status meta box
 */
function post_comment_status_meta_box($post) {
    ?>
    <input name="advanced_view" type="hidden" value="1" />
    <p class="meta-options">
        <label for="comment_status" class="selectit"><input name="comment_status" type="checkbox" id="comment_status" value="open" <?php checked($post->comment_status, 'open'); ?> /> <?php _e('Allow comments'); ?></label><br />
        <label for="ping_status" class="selectit"><input name="ping_status" type="checkbox" id="ping_status" value="open" <?php checked($post->ping_status, 'open'); ?> /> <?php _e('Allow trackbacks and pingbacks on this page'); ?></label>
    </p>
    <?php
    // 对于媒体类型的数据模型，不显示评论选项
    if (in_array($post->post_type, array('images', 'media', 'download'))) {
        return;
    }
    if (comments_open($post->ID)) {
        $comments = todo_count_comments($post->ID);
        if ($comments->total_comments || $comments->moderated) {
            ?>
            <p><a href="<?php echo esc_url(admin_url('edit-comments.php?p=' . $post->ID)); ?>"><?php
                /* translators: %s: number of comments */
                printf(_n('%s comment', '%s comments', $comments->total_comments), number_format_i18n($comments->total_comments));
                ?></a><?php
                if ($comments->moderated) {
                    /* translators: %s: number of comments */
                    printf(', ' . _n('%s pending', '%s pending', $comments->moderated), number_format_i18n($comments->moderated));
                }
                ?></p>
            <?php
        }
    }
}

/**
 * Slug meta box
 */
function post_slug_meta_box($post) {
    /** This filter is documented in todo-admin/edit-tag-form.php */
    $editable_slug = apply_filters('editable_slug', $post->post_name, $post);
    ?>
    <label class="screen-reader-text" for="post_name"><?php _e('Slug'); ?></label><input name="post_name" type="text" size="13" id="post_name" value="<?php echo esc_attr($editable_slug); ?>" />
    <?php
}

/**
 * Author meta box
 */
function post_author_meta_box($post) {
    global $user_ID;
    ?>
    <label class="screen-reader-text" for="post_author_override"><?php _e('Author'); ?></label>
    <?php
    todo_dropdown_users(array(
        'who' => 'authors',
        'name' => 'post_author_override',
        'selected' => empty($post->ID) ? $user_ID : $post->post_author,
        'include_selected' => true
    ));
}

/**
 * Revisions meta box
 */
function post_revisions_meta_box($post) {
    todo_list_post_revisions($post);
}

// Helper functions
function touch_time($edit = 1, $for_post = 1, $tab_index = 0, $multi = 0) {
    // Simplified time picker
    echo '<p>Date/Time picker would go here</p>';
}

if (!function_exists('get_delete_post_link')) {
    function get_delete_post_link($id, $deprecated = '', $force_delete = false) {
        return todo_admin_url('post.php?post=' . $id . '&action=delete');
    }
}

if (!function_exists('get_preview_post_link')) {
    function get_preview_post_link($post = null, $query_args = array(), $preview_link = '') {
        return home_url('/?preview=true&p=' . $post->ID);
    }
}

if (!function_exists('submit_button')) {
    function submit_button($text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null) {
        echo '<input type="submit" name="' . esc_attr($name) . '" class="button button-' . esc_attr($type) . '" value="' . esc_attr($text) . '" />';
    }
}

// Constants
if (!defined('EMPTY_TRASH_DAYS')) {
    define('EMPTY_TRASH_DAYS', 30);
}
