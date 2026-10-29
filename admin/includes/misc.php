<?php
/**
 * Misc TodoCMS Administration API.
 *
 * @package TodoCMS
 * @subpackage Administration
 */

// 防止直接访问（但允许AJAX请求）
if (!defined('ABSPATH') && !defined('DOING_AJAX')) {
	exit('Direct access denied.');
}

/**
 * Returns whether the server is running Apache with the mod_rewrite module loaded.
 *
 * @since 2.0.0
 *
 * @return bool
 */
function got_mod_rewrite()
{
	$got_rewrite = apache_mod_loaded('mod_rewrite', true);

	/**
	 * Filters whether Apache and mod_rewrite are present.
	 *
	 * This filter was previously used to force URL rewriting for other servers,
	 * like nginx. Use the {@see 'got_url_rewrite'} filter in got_url_rewrite() instead.
	 *
	 * @since 2.5.0
	 *
	 * @see got_url_rewrite()
	 *
	 * @param bool $got_rewrite Whether Apache and mod_rewrite are present.
	 */
	return apply_filters('got_rewrite', $got_rewrite);
}

/**
 * Returns whether the server supports URL rewriting.
 *
 * Detects Apache's mod_rewrite, IIS 7.0+ permalink support, and nginx.
 *
 * @since 3.7.0
 *
 * @global bool $is_nginx
 *
 * @return bool Whether the server supports URL rewriting.
 */
function got_url_rewrite()
{
	$got_url_rewrite = (got_mod_rewrite() || $GLOBALS['is_nginx'] || iis7_supports_permalinks());

	/**
	 * Filters whether URL rewriting is available.
	 *
	 * @since 3.7.0
	 *
	 * @param bool $got_url_rewrite Whether URL rewriting is available.
	 */
	return apply_filters('got_url_rewrite', $got_url_rewrite);
}

/**
 * Extracts strings from between the BEGIN and END markers in the .htaccess file.
 *
 * @since 1.5.0
 *
 * @param string $filename
 * @param string $marker
 * @return array An array of strings from a file (.htaccess ) from between BEGIN and END markers.
 */
function extract_from_markers($filename, $marker)
{
	$result = array();

	if (!file_exists($filename)) {
		return $result;
	}

	if ($markerdata = explode("\n", implode('', file($filename))))
		; {
		$state = false;
		foreach ($markerdata as $markerline) {
			if (strpos($markerline, '# END ' . $marker) !== false)
				$state = false;
			if ($state)
				$result[] = $markerline;
			if (strpos($markerline, '# BEGIN ' . $marker) !== false)
				$state = true;
		}
	}

	return $result;
}

/**
 * Inserts an array of strings into a file (.htaccess ), placing it between
 * BEGIN and END markers.
 *
 * Replaces existing marked info. Retains surrounding
 * data. Creates file if none exists.
 *
 * @since 1.5.0
 *
 * @param string       $filename  Filename to alter.
 * @param string       $marker    The marker to alter.
 * @param array|string $insertion The new content to insert.
 * @return bool True on write success, false on failure.
 */
function insert_with_markers($filename, $marker, $insertion)
{
	if (!file_exists($filename)) {
		if (!is_writable(dirname($filename))) {
			return false;
		}
		if (!touch($filename)) {
			return false;
		}
	} elseif (!is_writeable($filename)) {
		return false;
	}

	if (!is_array($insertion)) {
		$insertion = explode("\n", $insertion);
	}

	$start_marker = "# BEGIN {$marker}";
	$end_marker = "# END {$marker}";

	$fp = fopen($filename, 'r+');
	if (!$fp) {
		return false;
	}

	// Attempt to get a lock. If the filesystem supports locking, this will block until the lock is acquired.
	flock($fp, LOCK_EX);

	$lines = array();
	while (!feof($fp)) {
		$lines[] = rtrim(fgets($fp), "\r\n");
	}

	// Split out the existing file into the preceding lines, and those that appear after the marker
	$pre_lines = $post_lines = $existing_lines = array();
	$found_marker = $found_end_marker = false;
	foreach ($lines as $line) {
		if (!$found_marker && false !== strpos($line, $start_marker)) {
			$found_marker = true;
			continue;
		} elseif (!$found_end_marker && false !== strpos($line, $end_marker)) {
			$found_end_marker = true;
			continue;
		}
		if (!$found_marker) {
			$pre_lines[] = $line;
		} elseif ($found_marker && $found_end_marker) {
			$post_lines[] = $line;
		} else {
			$existing_lines[] = $line;
		}
	}

	// Check to see if there was a change
	if ($existing_lines === $insertion) {
		flock($fp, LOCK_UN);
		fclose($fp);

		return true;
	}

	// Generate the new file data
	$new_file_data = implode("\n", array_merge(
		$pre_lines,
		array($start_marker),
		$insertion,
		array($end_marker),
		$post_lines
	));

	// Write to the start of the file, and truncate it to that length
	fseek($fp, 0);
	$bytes = fwrite($fp, $new_file_data);
	if ($bytes) {
		ftruncate($fp, ftell($fp));
	}
	fflush($fp);
	flock($fp, LOCK_UN);
	fclose($fp);

	return (bool) $bytes;
}

/**
 * Updates the htaccess file with the current rules if it is writable.
 *
 * Always writes to the file if it exists and is writable to ensure that we
 * blank out old rules.
 *
 * @since 1.5.0
 *
 * @global TODO_Rewrite $todo_rewrite
 */
function save_mod_rewrite_rules()
{
	if (is_multisite())
		return;

	global $todo_rewrite;

	$home_path = get_home_path();
	$htaccess_file = $home_path . '.htaccess';

	/*
	 * If the file doesn't already exist check for write access to the directory
	 * and whether we have some rules. Else check for write access to the file.
	 */
	if ((!file_exists($htaccess_file) && is_writable($home_path) && $todo_rewrite->using_mod_rewrite_permalinks()) || is_writable($htaccess_file)) {
		if (got_mod_rewrite()) {
			$rules = explode("\n", $todo_rewrite->mod_rewrite_rules());
			return insert_with_markers($htaccess_file, 'TodoCMS', $rules);
		}
	}

	return false;
}

/**
 * Updates the IIS web.config file with the current rules if it is writable.
 * If the permalinks do not require rewrite rules then the rules are deleted from the web.config file.
 *
 * @since 2.8.0
 *
 * @global TODO_Rewrite $todo_rewrite
 *
 * @return bool True if web.config was updated successfully
 */
function iis7_save_url_rewrite_rules()
{
	if (is_multisite())
		return;

	global $todo_rewrite;

	$home_path = get_home_path();
	$web_config_file = $home_path . 'web.config';

	// Using win_is_writable() instead of is_writable() because of a bug in Windows PHP
	if (iis7_supports_permalinks() && ((!file_exists($web_config_file) && win_is_writable($home_path) && $todo_rewrite->using_mod_rewrite_permalinks()) || win_is_writable($web_config_file))) {
		$rule = $todo_rewrite->iis7_url_rewrite_rules(false, '', '');
		if (!empty($rule)) {
			return iis7_add_rewrite_rule($web_config_file, $rule);
		} else {
			return iis7_delete_rewrite_rule($web_config_file);
		}
	}
	return false;
}

/**
 * Update the "recently-edited" file for the plugin or theme editor.
 *
 * @since 1.5.0
 *
 * @param string $file
 */
function update_recently_edited($file)
{
	$oldfiles = (array) get_option('recently_edited');
	if ($oldfiles) {
		$oldfiles = array_reverse($oldfiles);
		$oldfiles[] = $file;
		$oldfiles = array_reverse($oldfiles);
		$oldfiles = array_unique($oldfiles);
		if (5 < count($oldfiles))
			array_pop($oldfiles);
	} else {
		$oldfiles[] = $file;
	}
	update_option('recently_edited', $oldfiles);
}

/**
 * Flushes rewrite rules if siteurl, home or page_on_front changed.
 *
 * @since 2.1.0
 *
 * @param string $old_value
 * @param string $value
 */
function update_home_siteurl($old_value, $value)
{
	if (todo_installing())
		return;

	if (is_multisite() && ms_is_switched()) {
		delete_option('rewrite_rules');
	} else {
		flush_rewrite_rules();
	}
}

/**
 * Resets global variables based on $_GET and $_POST
 *
 * This function resets global variables based on the names passed
 * in the $vars array to the value of $_POST[$var] or $_GET[$var] or ''
 * if neither is defined.
 *
 * @since 2.0.0
 *
 * @param array $vars An array of globals to reset.
 */
function todo_reset_vars($vars)
{
	foreach ($vars as $var) {
		if (empty($_POST[$var])) {
			if (empty($_GET[$var])) {
				$GLOBALS[$var] = '';
			} else {
				$GLOBALS[$var] = $_GET[$var];
			}
		} else {
			$GLOBALS[$var] = $_POST[$var];
		}
	}
}

/**
 * Displays the given administration message.
 *
 * @since 2.1.0
 *
 * @param string|TODO_Error $message
 */
function show_message($message)
{
	if (is_todo_error($message)) {
		if ($message->get_error_data() && is_string($message->get_error_data()))
			$message = $message->get_error_message() . ': ' . $message->get_error_data();
		else
			$message = $message->get_error_message();
	}
	echo "<p>$message</p>\n";
	todo_ob_end_flush_all();
	flush();
}

/**
 * @since 2.8.0
 *
 * @param string $content
 * @return array
 */
function todo_doc_link_parse($content)
{
	if (!is_string($content) || empty($content))
		return array();

	if (!function_exists('token_get_all'))
		return array();

	$tokens = token_get_all($content);
	$count = count($tokens);
	$functions = array();
	$ignore_functions = array();
	for ($t = 0; $t < $count - 2; $t++) {
		if (!is_array($tokens[$t])) {
			continue;
		}

		if (T_STRING == $tokens[$t][0] && ('(' == $tokens[$t + 1] || '(' == $tokens[$t + 2])) {
			// If it's a function or class defined locally, there's not going to be any docs available
			if ((isset($tokens[$t - 2][1]) && in_array($tokens[$t - 2][1], array('function', 'class'))) || (isset($tokens[$t - 2][0]) && T_OBJECT_OPERATOR == $tokens[$t - 1][0])) {
				$ignore_functions[] = $tokens[$t][1];
			}
			// Add this to our stack of unique references
			$functions[] = $tokens[$t][1];
		}
	}

	$functions = array_unique($functions);
	sort($functions);

	/**
	 * Filters the list of functions and classes to be ignored from the documentation lookup.
	 *
	 * @since 2.8.0
	 *
	 * @param array $ignore_functions Functions and classes to be ignored.
	 */
	$ignore_functions = apply_filters('documentation_ignore_functions', $ignore_functions);

	$ignore_functions = array_unique($ignore_functions);

	$out = array();
	foreach ($functions as $function) {
		if (in_array($function, $ignore_functions))
			continue;
		$out[] = $function;
	}

	return $out;
}

/**
 * Saves option for number of rows when listing posts, pages, comments, etc.
 *
 * @since 2.8.0
 */
function set_screen_options()
{

	if (isset($_POST['todo_screen_options']) && is_array($_POST['todo_screen_options'])) {
		check_admin_referer('screen-options-nonce', 'screenoptionnonce');

		if (!$user = todo_get_current_user())
			return;
		$option = $_POST['todo_screen_options']['option'];
		$value = $_POST['todo_screen_options']['value'];

		if ($option != sanitize_key($option))
			return;

		$map_option = $option;
		$type = str_replace('edit_', '', $map_option);
		$type = str_replace('_per_page', '', $type);
		if (in_array($type, get_taxonomies()))
			$map_option = 'edit_tags_per_page';
		elseif (in_array($type, get_post_types()))
			$map_option = 'edit_per_page';
		else
			$option = str_replace('-', '_', $option);

		switch ($map_option) {
			case 'edit_per_page':
			case 'users_per_page':
			case 'edit_comments_per_page':
			case 'upload_per_page':
			case 'edit_tags_per_page':
			case 'plugins_per_page':
			// Network admin
			case 'sites_network_per_page':
			case 'users_network_per_page':
			case 'site_users_network_per_page':
			case 'plugins_network_per_page':
			case 'themes_network_per_page':
			case 'site_themes_network_per_page':
				$value = (int) $value;
				if ($value < 1 || $value > 999)
					return;
				break;
			default:
				$screen_option = false;

				if ('_page' === substr($option, -5) || 'layout_columns' === $option) {
					/**
					 * Filters a screen option value before it is set.
					 *
					 * The filter can also be used to modify non-standard [items]_per_page
					 * settings. See the parent function for a full list of standard options.
					 *
					 * Returning false to the filter will skip saving the current option.
					 *
					 * @since 2.8.0
					 * @since 5.4.2 Only applied to options ending with '_page',
					 *              or the 'layout_columns' option.
					 *
					 * @see set_screen_options()
					 *
					 * @param mixed  $screen_option The value to save instead of the option value.
					 *                              Default false (to skip saving the current option).
					 * @param string $option        The option name.
					 * @param int    $value         The option value.
					 */
					$screen_option = apply_filters('set-screen-option', $screen_option, $option, $value); // phpcs:ignore TodoCMS.NamingConventions.ValidHookName.UseUnderscores
				}

				/**
				 * Filters a screen option value before it is set.
				 *
				 * The dynamic portion of the hook, `$option`, refers to the option name.
				 *
				 * Returning false to the filter will skip saving the current option.
				 *
				 * @since 5.4.2
				 *
				 * @see set_screen_options()
				 *
				 * @param mixed   $screen_option The value to save instead of the option value.
				 *                               Default false (to skip saving the current option).
				 * @param string  $option        The option name.
				 * @param int     $value         The option value.
				 */
				$value = apply_filters("set_screen_option_{$option}", $screen_option, $option, $value);

				if (false === $value)
					return;
				break;
		}

		update_user_meta($user->ID, $option, $value);

		$url = remove_query_arg(array('pagenum', 'apage', 'paged'), todo_get_referer());
		if (isset($_POST['mode'])) {
			$url = add_query_arg(array('mode' => $_POST['mode']), $url);
		}

		todo_safe_redirect($url);
		exit;
	}
}

/**
 * Check if rewrite rule for TodoCMS already exists in the IIS 7+ configuration file
 *
 * @since 2.8.0
 *
 * @return bool
 * @param string $filename The file path to the configuration file
 */
function iis7_rewrite_rule_exists($filename)
{
	if (!file_exists($filename))
		return false;
	if (!class_exists('DOMDocument', false)) {
		return false;
	}

	$doc = new DOMDocument();
	if ($doc->load($filename) === false)
		return false;
	$xpath = new DOMXPath($doc);
	$rules = $xpath->query('/configuration/system.webServer/rewrite/rules/rule[starts-with(@name,\'todocms\')] | /configuration/system.webServer/rewrite/rules/rule[starts-with(@name,\'TodoCMS\')]');
	if ($rules->length == 0)
		return false;
	else
		return true;
}

/**
 * Delete TodoCMS rewrite rule from web.config file if it exists there
 *
 * @since 2.8.0
 *
 * @param string $filename Name of the configuration file
 * @return bool
 */
function iis7_delete_rewrite_rule($filename)
{
	// If configuration file does not exist then rules also do not exist so there is nothing to delete
	if (!file_exists($filename))
		return true;

	if (!class_exists('DOMDocument', false)) {
		return false;
	}

	$doc = new DOMDocument();
	$doc->preserveWhiteSpace = false;

	if ($doc->load($filename) === false)
		return false;
	$xpath = new DOMXPath($doc);
	$rules = $xpath->query('/configuration/system.webServer/rewrite/rules/rule[starts-with(@name,\'todocms\')] | /configuration/system.webServer/rewrite/rules/rule[starts-with(@name,\'TodoCMS\')]');
	if ($rules->length > 0) {
		$child = $rules->item(0);
		$parent = $child->parentNode;
		$parent->removeChild($child);
		$doc->formatOutput = true;
		saveDomDocument($doc, $filename);
	}
	return true;
}

/**
 * Add TodoCMS rewrite rule to the IIS 7+ configuration file.
 *
 * @since 2.8.0
 *
 * @param string $filename The file path to the configuration file
 * @param string $rewrite_rule The XML fragment with URL Rewrite rule
 * @return bool
 */
function iis7_add_rewrite_rule($filename, $rewrite_rule)
{
	if (!class_exists('DOMDocument', false)) {
		return false;
	}

	// If configuration file does not exist then we create one.
	if (!file_exists($filename)) {
		$fp = fopen($filename, 'w');
		fwrite($fp, '<configuration/>');
		fclose($fp);
	}

	$doc = new DOMDocument();
	$doc->preserveWhiteSpace = false;

	if ($doc->load($filename) === false)
		return false;

	$xpath = new DOMXPath($doc);

	// First check if the rule already exists as in that case there is no need to re-add it
	$todocms_rules = $xpath->query('/configuration/system.webServer/rewrite/rules/rule[starts-with(@name,\'todocms\')] | /configuration/system.webServer/rewrite/rules/rule[starts-with(@name,\'TodoCMS\')]');
	if ($todocms_rules->length > 0)
		return true;

	// Check the XPath to the rewrite rule and create XML nodes if they do not exist
	$xmlnodes = $xpath->query('/configuration/system.webServer/rewrite/rules');
	if ($xmlnodes->length > 0) {
		$rules_node = $xmlnodes->item(0);
	} else {
		$rules_node = $doc->createElement('rules');

		$xmlnodes = $xpath->query('/configuration/system.webServer/rewrite');
		if ($xmlnodes->length > 0) {
			$rewrite_node = $xmlnodes->item(0);
			$rewrite_node->appendChild($rules_node);
		} else {
			$rewrite_node = $doc->createElement('rewrite');
			$rewrite_node->appendChild($rules_node);

			$xmlnodes = $xpath->query('/configuration/system.webServer');
			if ($xmlnodes->length > 0) {
				$system_webServer_node = $xmlnodes->item(0);
				$system_webServer_node->appendChild($rewrite_node);
			} else {
				$system_webServer_node = $doc->createElement('system.webServer');
				$system_webServer_node->appendChild($rewrite_node);

				$xmlnodes = $xpath->query('/configuration');
				if ($xmlnodes->length > 0) {
					$config_node = $xmlnodes->item(0);
					$config_node->appendChild($system_webServer_node);
				} else {
					$config_node = $doc->createElement('configuration');
					$doc->appendChild($config_node);
					$config_node->appendChild($system_webServer_node);
				}
			}
		}
	}

	$rule_fragment = $doc->createDocumentFragment();
	$rule_fragment->appendXML($rewrite_rule);
	$rules_node->appendChild($rule_fragment);

	$doc->encoding = "UTF-8";
	$doc->formatOutput = true;
	saveDomDocument($doc, $filename);

	return true;
}

/**
 * Saves the XML document into a file
 *
 * @since 2.8.0
 *
 * @param DOMDocument $doc
 * @param string $filename
 */
function saveDomDocument($doc, $filename)
{
	$config = $doc->saveXML();
	$config = preg_replace("/([^\r])\n/", "$1\r\n", $config);
	$fp = fopen($filename, 'w');
	fwrite($fp, $config);
	fclose($fp);
}

/**
 * Display the default admin color scheme picker (Used in user-edit.php)
 *
 * @since 3.0.0
 *
 * @global array $_todo_admin_css_colors
 *
 * @param int $user_id User ID.
 */
function admin_color_scheme_picker($user_id)
{
	global $_todo_admin_css_colors;

	if (isset($_todo_admin_css_colors) && is_array(todo_p_admin_css_colors) && !empty($_todo_admin_css_colors)) {
		$current_color = get_user_option('admin_color', $user_id);

		if (empty($current_color) || !isset($_todo_admin_css_colors[$current_color])) {
			$current_color = 'fresh';
		}

		?>
		<fieldset id="color-picker" class="scheme-list">
			<legend class="screen-reader-text"><span><?php _e('Admin Color Scheme'); ?></span></legend>
			<?php
			echo todo_csrf_field('save-color-scheme', 'color-nonce', false);
			foreach ($_todo_admin_css_colors as $color => $color_info):

				?>
				<div class="color-option <?php echo ($color == $current_color) ? 'selected' : ''; ?>">
					<input name="admin_color" id="admin_color_<?php echo esc_attr($color); ?>" type="radio"
						value="<?php echo esc_attr($color); ?>" class="tog" <?php checked($color, $current_color); ?> />
					<input type="hidden" class="css_url" value="<?php echo esc_url($color_info->url); ?>" />
					<input type="hidden" class="icon_colors"
						value="<?php echo esc_attr(todo_json_encode(array('icons' => $color_info->icon_colors))); ?>" />
					<label for="admin_color_<?php echo esc_attr($color); ?>"><?php echo esc_html($color_info->name); ?></label>
					<table class="color-palette">
						<tr>
							<?php
							foreach ($color_info->colors as $html_color) {
								?>
								<td style="background-color: <?php echo esc_attr($html_color); ?>">&nbsp;</td>
								<?php
							}
							?>
						</tr>
					</table>
				</div>
				<?php
			endforeach;
			?>
		</fieldset>
		<?php
	}
}

/**
 * @since 3.3.0
 *
 * @param int $user_id User ID.
 */
function _admin_bar_bump_cb()
{ ?>
	<style type="text/css" media="screen">
		html {
			margin-top: 32px !important;
		}

		* html body {
			margin-top: 32px !important;
		}

		@media screen and (max-width: 782px) {
			html {
				margin-top: 46px !important;
			}

			* html body {
				margin-top: 46px !important;
			}
		}
	</style>
	<?php
}

/**
 * @since 3.3.0
 *
 * @param int $user_id User ID.
 */
function _get_admin_bar_pref($context, $user)
{
	$pref = get_user_option("show_admin_bar_{$context}", $user);
	if (false === $pref)
		return true;

	return 'true' === $pref;
}
