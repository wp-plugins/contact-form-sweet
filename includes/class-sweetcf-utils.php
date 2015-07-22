<?php

/**
 * Description of class-sweetcf-utils
 * Utility class sets default variables, wp actions, and sanitize functions
 * Functions are called statically, so no need to instantiate the class
 */

class SWEETCF_Utils {

	static $global_defaults, $form_defaults, $field_defaults;
	static $global_options, $form_options, $admin_notices;

	static function setup() { // Come here when the plugin is run
		add_action('plugins_loaded', 'SWEETCF_Utils::swcf_init_languages', 1);

		// will start PHP session only if they are enabled (not enabled by default)
		add_action('init', 'SWEETCF_Utils::swcf_init_session', 1);

		// process the form POST logic
		add_action('init', 'SWEETCF_Process::process_form', 10);

		// use shortcode to print the contact form or process contact form logic
		// can use dashes or underscores: [sweetcontact-form] or [sweetcontact_form]
		//add_shortcode('sweetcontact_form', 'SWEETCF_Display::process_short_code', 1);
		add_shortcode('sweetcontact-form', 'SWEETCF_Display::process_short_code', 1);

		// If you want to use shortcodes in your widgets or footer
		add_filter('widget_text', 'do_shortcode');
		add_filter('wp_footer', 'do_shortcode');

		if (is_admin()) {
			// Set up admin actions
			add_action('admin_menu', 'SWEETCF_Options::register_options_page');
			add_action('admin_init', 'SWEETCF_Options::initialize_options');

			add_action('admin_notices', 'SWEETCF_Utils::admin_notice');

			add_action('admin_enqueue_scripts', 'SWEETCF_Utils::enqueue_admin_scripts');

			add_action('admin_footer', 'SWEETCF_Utils::swcf_admin_footer');

			// adds "Settings" link to the plugin action page
			add_filter('plugin_action_links', 'SWEETCF_Utils::swcf_plugin_action_links', 10, 2);
		} else {
			add_action('wp_enqueue_scripts', 'SWEETCF_Utils::enqueue_frontend_scripts');
			add_action('wp_footer', 'SWEETCF_Utils::swcf_wp_footer');
		}

		return;
	}

	static function swcf_init_languages() {
		if (function_exists('load_plugin_textdomain')) {
			load_plugin_textdomain('sweetcontact', false, SWCF_LANGUAGES);
		}
	}

	static function swcf_init_session() {
		self::get_global_options();
		// start the PHP session if enabled - used by shortcode attributes (and the CAPTCHA, but only when enable_php_sessions)
		// PHP Sessions are no longer enabled by default allowing for best compatibility with servers, caching, themes, and other plugins.
		// This should resolve any PHP sessions related issues some users had.
		if (self::$global_options['enable_php_sessions'] == 'true') {
			SWEETCF_Utils::start_session();
		}
	}

	static function enqueue_admin_scripts($hook) {
		// Add jquery and css for tabs on options page only for this plugin
		if (strpos($hook, 'sweetcontact') > 0) {
			wp_enqueue_script('thickbox'); wp_enqueue_style('thickbox'); wp_enqueue_script('jquery-ui-core'); wp_enqueue_script('jquery-ui-tabs'); wp_enqueue_script('jquery-ui-sortable');
			wp_enqueue_script('sweetcf_scripts_admin', SWCF_INCLUDES . '/sweetcf-scripts-admin.js', false, SWCF_BUILD);
			wp_enqueue_script('sweetcf_scripts', SWCF_INCLUDES . '/sweetcf-scripts.js', false, SWCF_BUILD);

			$translation_array = array(
				'save_changes' => __('Save Changes', 'sweetcontact'),
				'send_test' => __('Send Test', 'sweetcontact'),
				'copy_settings' => __('Copy Settings', 'sweetcontact'),
				'backup_settings' => __('Backup Settings', 'sweetcontact'),
				'restore_settings' => __('Restore Settings', 'sweetcontact'),
				'confirm_change' => __('Are you sure you want to permanently make this change?', 'sweetcontact'),
				'unsaved_changes' => __('You have unsaved changes.', 'sweetcontact'),
				'reset_form' => __('Reset Form', 'sweetcontact'),
				'reset_all_styles' => __('Reset Styles on all forms', 'sweetcontact'),
				'delete_form' => __('Delete Form', 'sweetcontact'),
			);
			wp_localize_script('sweetcf_scripts_admin', 'sweetcf_transl', $translation_array);
			wp_enqueue_style('sweetcf-styles-admin', SWCF_INCLUDES . '/sweetcf-styles-admin.css', false, SWCF_BUILD);
		}
	}

	static function enqueue_frontend_scripts($hook) {
		wp_enqueue_style('sweetcf-styles-frontend', SWCF_INCLUDES . '/sweetcf-styles.css', false, SWCF_BUILD);
	}

	static function add_date_js() {
		// add js for forms with date fields
		//wp_enqueue_style( 'swcf_date_style', SWCF_INCLUDES . '/ctf_epoch_styles.css', false, SWCF_BUILD );
		wp_enqueue_script('swcf_date_js', SWCF_INCLUDES . '/ctf_epoch_classes.js', false, SWCF_BUILD);

		echo SWEETCF_Display::$add_date_js;

		$string = '  var';
		$date_var_string = '';
		foreach (SWEETCF_Display::$add_date_js_array as $v) {
			$date_var_string .= ' dp_cal' . "$v,";
		}
		$date_var_string = substr($date_var_string, 0, -1);
		$string .= "$date_var_string;\n";
		if (SWEETCF_Display::$swcf_use_window_onload) { $string .= '  window.onload = function () { '; }
		foreach (SWEETCF_Display::$add_date_js_array as $v) {
			$string .= "    dp_cal$v = new Epoch('epoch_popup$v','popup',document.getElementById('swcf_field$v'));\n";
		}
		if (SWEETCF_Display::$swcf_use_window_onload) { $string .= "  };\n"; }

		$string .= "</script>\n";
		echo $string;
		?>
		<script type="text/javascript">
		//<![CDATA[
			var swcf_css = "\n\
		<style type='text/css'>\n\
		@import url('<?php echo SWCF_INCLUDES . '/ctf_epoch_styles.css' . '?ver=' . SWCF_BUILD; ?>');\n\
		</style>\n\
		";
			jQuery(document).ready(function($) {
				$('head').append(swcf_css);
			});
		//]]>
		</script>
		<?php
		echo "<!-- sweetContact Form plugin - end date field js -->\n\n";
	}

	static function swcf_wp_footer() {
		// Add javascript and css 
		if (isset(SWEETCF_Display::$add_swcf_script) && SWEETCF_Display::$add_swcf_script) {
			wp_enqueue_script('jquery-ui-core');
			wp_enqueue_script('sweetcf_scripts', SWCF_INCLUDES . '/sweetcf-scripts.js', false, SWCF_BUILD);
		}
		if (isset(SWEETCF_Display::$add_placeholder_script) && SWEETCF_Display::$add_placeholder_script) {
			// makes placeholder work on old browsers
			wp_enqueue_script('swcf_placeholders', SWCF_INCLUDES . '/sweetcf-placeholders.min.js', false, SWCF_BUILD);
		}
		if (isset(SWEETCF_Display::$add_date_js) && SWEETCF_Display::$add_date_js != '') {
			// add js for forms with date fields
			SWEETCF_Utils::add_date_js();
		}
	}

	static function swcf_admin_footer() {
		// add placeholder javascript in form preview page only if needed
		if (isset(SWEETCF_Display::$placeholder) && SWEETCF_Display::$placeholder) {
			// makes placeholder work on old browsers
			wp_enqueue_script('swcf_placeholders', SWCF_INCLUDES . '/sweetcf-placeholders.min.js', false, SWCF_BUILD);
		}
		if (isset(SWEETCF_Display::$add_date_js) && SWEETCF_Display::$add_date_js != '') {
			// add js for forms with date fields
			SWEETCF_Utils::add_date_js();
		}
	}

	static function admin_notice() { // Displays admin notices, if any, at top of the screen
		// The notice will appear the next time the WP 'admin_notices' action occurs
		self::get_global_options();
		if (!empty(self::$global_options['admin_notices'])) {
			foreach (self::$global_options['admin_notices'] as $notice) {
				echo $notice;
			}
			unset(self::$global_options['admin_notices']);
			update_option('sweetcontact_global', self::$global_options);
		}
	}

	static function add_admin_notice($key, $text, $class, $style) { // Adds an admin notice, to be displayed at the top of the screen
		if ( empty($text) ) { return; }
		self::get_global_options();
		self::$global_options['admin_notices'][$key] = '    <div class="' . $class . '" style="' . $style . '"><p>' . $text . '</p></div>';
		update_option('sweetcontact_global', self::$global_options);
	}

	static function swcf_plugin_action_links($links, $file) { //Static so we don't call plugin_basename on every plugin row.
		static $this_plugin;
		if (!$this_plugin) { $this_plugin = plugin_basename(SWCF_FILE); }
		if ($file == $this_plugin) {
			$settings_link = '<a href="plugins.php?page=sweetcontact">' . __('Settings', 'sweetcontact') . '</a>';
			array_unshift($links, $settings_link); // before other links
		}
		return $links;
	}

	static function start_session() {
		// Start PHP Session  - used optionally by CAPTCHA and shortcode attributes
		// NOTE: PHP sessions are OFF by default! and not recommended for best compatibility with servers, caching, themes, and other plugins
		// this has to be set before any header output
		// start cookie session
		if (!isset($_SESSION)) { // play nice with other plugins
			//set the $_SESSION cookie into HTTPOnly mode for better security
			if (version_compare(PHP_VERSION, '5.2.0') >= 0)	// supported on PHP version 5.2.0  and higher
				@ini_set("session.cookie_httponly", 1);
			session_cache_limiter('private, must-revalidate');
			session_start();
		}
	}

	static function get_global_options() {
		// get plugin options from the WP Options table
		// if the options array does not exist, use the defaults
		// Load global options
		self::$global_options = get_option('sweetcontact_global');
		if (!self::$global_options) {
			// Global options array does not exist, so create it
			if (!isset(self::$global_defaults)) { self::set_defaults(); }
			update_option('sweetcontact_global', self::$global_defaults);
			self::$global_options = get_option('sweetcontact_global');
		}

		// if I added any new $global_defaults settings without changing the plugin version number
		// fill in any missing $global_options with the $global_defaults value so there will be no errors
		if (!isset(self::$global_defaults)) { self::set_defaults(); }
		if (is_array(self::$global_options)) { self::$global_options = array_merge(self::$global_defaults, self::$global_options); }

		return(self::$global_options);
	}

	static function get_form_options($form_num, $use_defaults) {
		// Get form options for $form_num from WP Options table
		// If $use_defaults is true, use defaults if form does not exist

		if (!isset(self::$global_defaults)) { self::set_defaults(); }
		if (!isset(self::$global_options)) { self::get_global_options(); } // Load global options if necessary

		$form_options = false;
		if (is_numeric($form_num) && $form_num > 0) {
			// Load form options
			$form_option_name = 'sweetcontact_form' . $form_num;
			$form_options = get_option($form_option_name);
			if (!$form_options && $use_defaults) { // Form options array doesn't exist, so create it
				if ("" == self::$form_defaults['form_name']) {
					self::$form_defaults['form_name'] = __('Form', 'sweetcontact') . ' ' . $form_num;
				}
				update_option($form_option_name, self::$form_defaults);
				$form_options = get_option($form_option_name);
			}
		}
		// if I added any new $form_defaults settings without changing the plugin version number
		// fill in any missing $form_options with the $form_defaults value so there will be no errors
		if (is_array($form_options)) {
			$form_options = array_merge(self::$form_defaults, $form_options);
		}
		return($form_options);
	}

	static function set_defaults() {
		// Set up default values
		// Default global options array
		self::$global_defaults = array(
			'swcf_version' => SWCF_VERSION,
			'enable_php_sessions' => 'false',
			'email' => array (
				'email_to' => 'user', // or 'custom'
				'wp_user' => '1', // By default, we chose the first WP user 
				'custom_email' => '',
			),

			'num_standard_fields' => '4', // Number of fields defined as standard fields
			// .. if you change something below, there are lots of other changes needed to be done as well !
			'max_form_num' => '2', // Highest form ID (used to assign ID to new form)
			'form_list' => array(
				'1' => 'Form 1',
				'2' => 'Form 2'
			)
		);

		// Default style settings
		$style_defaults = self::set_style_defaults();

		// Default options for a single contact form
		self::$form_defaults = array(
			'form_name' => __('New Form', 'sweetcontact'),
			'welcome' => __('Comments or questions are welcome.', 'sweetcontact'),
			'after_form_note' => '',
			
			'text_message_sent' => 'Thank you for contacting us',
			
			'php_mailer_enable' => 'wordpress',
			'email_from' => '',
			'email_from_enforced' => 'false',
			'email_reply_to' => '',
			'email_bcc' => '',
			'email_subject' => get_option('blogname') . ' ' . __('Contact:', 'sweetcontact'),
			'email_subject_list' => '',
			'name_format' => 'name',
			'preserve_space_enable' => 'false',
			'double_email' => 'false',
			'name_case_enable' => 'false',
			'sender_info_enable' => 'true',
			'domain_protect' => 'true',
			'domain_protect_names' => '',
			'anchor_enable' => 'true',
			'email_check_dns' => 'false',
			'email_html' => 'true',
			'email_inline_label' => 'false',
			'email_hide_empty' => 'false',
			'print_form_enable' => 'false',
			'email_keep_attachments' => 'false',
			'captcha_enable' => '1',
			'captcha_small' => 'false',
			'captcha_perm' => 'false',
			'captcha_perm_level' => 'read',
			'design_type'=>'1', // 1-horizontal, 2-vertical
			'honeypot_enable' => 'false',
			'redirect_enable' => 'true',
			'redirect_seconds' => '3',
			'redirect_url' => get_option('home'),
			'redirect_query' => 'false',
			'redirect_ignore' => '',
			'redirect_rename' => '',
			'redirect_add' => '',
			'redirect_email_off' => 'false',
			'silent_send' => 'off',
			'silent_url' => '',
			'silent_ignore' => '',
			'silent_rename' => '',
			'silent_add' => '',
			'silent_email_off' => 'false',
			'export_ignore' => '',
			'export_rename' => '',
			'export_add' => '',
			'export_email_off' => 'false',
			'date_format' => 'mm/dd/yyyy',
			'cal_start_day' => '0',
			'time_format' => '12',
			'attach_types' => 'doc,docx,pdf,txt,gif,jpg,jpeg,png',
			'attach_size' => '1mb',
			'textarea_html_allow' => 'false',
			'enable_areyousure' => 'false',
			'auto_respond_enable' => 'false',
			'auto_respond_html' => 'false',
			'auto_respond_from_name' => get_option('blogname'),
			'auto_respond_from_email' => get_option('admin_email'),
			'auto_respond_reply_to' => get_option('admin_email'),
			'auto_respond_subject' => '',
			'auto_respond_message' => '',
			'req_field_indicator_enable' => 'true',
			'req_field_label_enable' => 'true',
			'req_field_indicator' => ' *',
			'border_enable' => 'false',
			'external_style' => 'false',
			'aria_required' => 'false',
			'auto_fill_enable' => 'true',
			'form_attributes' => '',
			'submit_attributes' => '',
			'title_border' => __('Contact Form', 'sweetcontact'),
			'title_dept' => '',
			'title_select' => '',
			'title_name' => '',
			'title_fname' => '',
			'title_mname' => '',
			'title_miname' => '',
			'title_lname' => '',
			'title_email' => '',
			'title_email2' => '',
			'title_subj' => '',
			'title_mess' => '',
			'title_capt' => '',
			'title_submit' => '',
			'title_reset' => '',
			'title_areyousure' => '',
			'text_print_button' => '',
			'tooltip_required' => '',
			'tooltip_captcha' => '',
			'tooltip_refresh' => '',
			'tooltip_filetypes' => '',
			'tooltip_filesize' => '',
			'enable_reset' => 'false',
			'error_contact_select' => '',
			'error_name' => '',
			'error_email' => '',
			'error_email_check' => '',
			'error_email2' => '',
			'error_url' => '',
			'error_date' => '',
			'error_time' => '',
			'error_maxlen' => '',
			'error_field' => '',
			'error_subject' => '',
			'error_select' => '',
			'error_input' => '',
			'error_captcha_blank' => '',
			'error_captcha_wrong' => '',
			'error_correct' => '',
			'error_spambot' => '',
			'fields' => array(),
		);

		// Merge in the style settings
		// Do it this way so we also have the style settings in a separate array to make validation easier
		self::$form_defaults = array_merge(self::$form_defaults, $style_defaults);

		self::get_field_defaults();

		// Add the standard fields (Name, Email, Subject, Message)
		// The main plugin file defines constants to refer to the standard field codes
		$name = array(
			'standard' => '1', // standard field number, otherwise '0' (internal) NEW
			'req' => 'true',
			'label' => __('Name:', 'sweetcontact'),
			'slug' => 'full_name',
			'type' => 'text'
		);
		$email = array(
			'standard' => '2', // standard field number, otherwise '0' (internal) NEW
			'req' => 'true',
			'label' => __('Email:', 'sweetcontact'),
			'slug' => 'email',
			'type' => 'text'
		);

		$subject = array(
			'standard' => '3', // standard field number, otherwise '0' (internal) NEW
			'req' => 'true',
			'label' => __('Subject:', 'sweetcontact'),
			'slug' => 'subject',
			'type' => 'text'
		);
		$message = array(
			'standard' => '4', // standard field number, otherwise '0' (internal) NEW
			'req' => 'true',
			'label' => __('Message:', 'sweetcontact'),
			'slug' => 'message',
			'type' => 'textarea'
		);

		// Add the standard fields to the form fields array
		self::$form_defaults['fields'][] = array_merge(self::$field_defaults, $name);
		self::$form_defaults['fields'][] = array_merge(self::$field_defaults, $email);
		self::$form_defaults['fields'][] = array_merge(self::$field_defaults, $subject);
		self::$form_defaults['fields'][] = array_merge(self::$field_defaults, $message);

		return(self::$form_defaults);
	}

	static function set_style_defaults() {
		// Set up default style values
		// Called by set_defaults() and SWEETCF_Options::validate()

		$style_defaults = array(
			// labels on top (default)
			// Alignment DIVs
			'form_style' => 'width:99%; max-width:555px;', // Form DIV, how wide is the form DIV
			'left_box_style' => 'float:left; width:55%; max-width:270px;', // left box DIV, container for vcita
			'right_box_style' => 'float:left; width:235px;', // right box DIV, container for vcita
			'clear_style' => 'clear:both;', // clear both
			'field_left_style' => 'clear:left; float:left; width:99%; max-width:550px; margin-right:10px;', // field left (wider)
			'field_prefollow_style' => 'clear:left; float:left; width:99%; margin-right:10px;', // field pre follow (narrower)
			'field_follow_style' => 'float:left; padding-left:10px; width:99%;', // field follow
			'title_style' => 'text-align:left; padding-top:5px;', // Input labels alignment DIV
			'field_div_style' => 'text-align:left;', // Input fields alignment DIV
			'captcha_div_style_sm' => 'float:left; width:100%; height:auto; padding-top:2px;', // Small CAPTCHA DIV
			'captcha_div_style_m' => 'float:left; width:100%; height:auto; padding-top:2px;', // Large CAPTCHA DIV
			'captcha_image_style' => 'border-style:none; margin:0; padding:0px; padding-right:5px; float:left;', // CAPTCHA alignment
			'captcha_reload_image_style' => 'border-style:none; margin:0; padding:0px; vertical-align:bottom;', // CAPTCHA image alignment
			'submit_div_style' => 'text-align:left; clear:both; padding-top:15px;', // Submit DIV
			'border_style' => 'border:1px solid black; width:99%; max-width:550px; padding:10px;', // style of the form fieldset box (if enabled)
			// Styles of labels, fields and text
			'required_style' => 'text-align:left;', // required field indicator
			'required_text_style' => 'text-align:left;', // required field text
			'hint_style' => 'font-size:x-small; font-weight:normal;', // small text hints like please enter your email again
			'error_style' => 'text-align:left; color:red;', // Input validation messages
			'redirect_style' => 'text-align:left;', // Redirecting message
			'fieldset_style' => 'border:1px solid black; width:97%; max-width:500px; padding:10px;', // style of the fieldset box (for field)
			'label_style' => 'text-align:left;', // Field labels
			'option_label_style' => 'display:inline;', // Options labels
			'field_style' => 'text-align:left; margin:0; width:99%;', // Input text fields  (out of place here?)
			'captcha_input_style' => 'text-align:left; margin:0; width:50px;', // CAPTCHA input field
			'textarea_style' => 'text-align:left; margin:0; width:99%; height:120px;', // Input Textarea
			'select_style' => 'text-align:left;', // Input Select
			'checkbox_style' => 'width:13px;', // Input checkbox
			'radio_style' => 'width:13px;', // Input radio
			'placeholder_style' => 'opacity:0.6; color:#333333;', // placeholder style
			'button_style' => 'cursor:pointer; margin:0;', // Submit button
			'reset_style' => 'cursor:pointer; margin:0;', // Reset button
			'powered_by_style' => 'font-size:x-small; font-weight:normal; padding-top:5px; text-align:center;', // the "powered by" link
		);


		return($style_defaults);
	}

	static function get_field_defaults() {
		// Default array for a single field
		self::$field_defaults = array(
			'standard' => '0', // standard field number, otherwise '0' (internal) NEW
			'options' => '', // Options list for select, radio, and checkbox-multiple
			'default' => '',
			'inline' => 'false', // Should checkboxes and radio buttons be displayed inline?
			'req' => 'false', // required field?
			'disable' => 'false',
			'follow' => 'false', // controls if this field will be displayed following the previous one on the same line
			'hide_label' => 'false', // controls if this field will have a hidden label on the form
			'placeholder' => 'false', // controls if the default text will be a placeholder
			'label' => __('New Field:', 'sweetcontact'),
			'slug' => '', // slug used for query vars, subject and email tags
			'type' => 'text',
			'max_len' => '',
			'label_css' => '',
			'input_css' => '',
			'attributes' => '',
			'regex' => '',
			'regex_error' => '',
			'notes' => '',
			'notes_after' => '',
		);

		return (self::$field_defaults);
	}

	static function get_form_defaults() { // Returns the defaults for a form
		if (empty(self::$form_defaults)) { self::set_defaults(); }
		return(self::$form_defaults);
	}

	static function update_lang($form_options) {
		//  global SWEETCF_Options::$form_options, SWEETCF_Options::$form_optionsion_defaults;
		// Update a few language options in the form options array
		if ($form_options['welcome'] == 'Comments or questions are welcome.') {
			$form_options['welcome'] = __('Comments or questions are welcome.', 'sweetcontact');
		}
		if ($form_options['email_subject'] == get_option('blogname') . ' ' . 'Contact:') {
			$form_options['email_subject'] = get_option('blogname') . ' ' . __('Contact:', 'sweetcontact');
		}
	}

	// checks proper email syntax (not perfect, none of these are, but this is the best I can find)
	static function validate_email($email) {
		//check for all the non-printable codes in the standard ASCII set,
		//including null bytes and newlines, and return false immediately if any are found.
		if (preg_match("/[\\000-\\037]/", $email)) {
			return false;
		}
		// regular expression used to perform the email syntax check
		//$pattern = "/^[-a-z0-9~!$%^&*_=+}{\'?]+(\.[-a-z0-9~!$%^&*_=+}{\'?]+)*@([a-z0-9_][-a-z0-9_]*(\.[-a-z0-9_]+)*\.(aero|arpa|biz|com|coop|edu|gov|info|int|mil|museum|name|net|org|pro|travel|mobi|asia|cat|jobs|tel|[a-z][a-z])|([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}))(:[0-9]{1,5})?$/i";
		//$pattern = "/^([_a-zA-Z0-9-]+)(\.[_a-zA-Z0-9-]+)*@([a-zA-Z0-9-]+)(\.[a-zA-Z0-9-]+)*(\.[a-zA-Z]{2,4})$/i";
		$pattern = "/^[-_a-z0-9\'+*$^&%=~!?{}]++(?:\.[-_a-z0-9\'+*$^&%=~!?{}]+)*+@(?:(?![-.])[-a-z0-9.]+(?<![-.])\.[a-z]{2,6}|\d{1,3}(?:\.\d{1,3}){3})(?::\d++)?$/iD";
		if (!preg_match($pattern, $email)) {
			return false;
		}
		// Make sure the domain exists with a DNS check (if enabled in options)
		// MX records are not mandatory for email delivery, this is why this function also checks A and CNAME records.
		// if the checkdnsrr function does not exist (skip this extra check, the syntax check will have to do)
		// checkdnsrr available in Linux: PHP 4.3.0 and higher & Windows: PHP 5.3.0 and higher
		if (!empty(SWEETCF_Process::$form_options['email_check_dns']) && SWEETCF_Process::$form_options['email_check_dns'] == 'true') {
			if (function_exists('checkdnsrr')) {
				list($user, $domain) = explode('@', $email);
				if (!checkdnsrr($domain . '.', 'MX') &&
					!checkdnsrr($domain . '.', 'A') &&
					!checkdnsrr($domain . '.', 'CNAME')) {
					// domain not found in DNS
					return false;
				}
			}
		}
		return true;
	}

	static function trim_array(&$a) {
		// Trim string elements in an array, recursing nested arrays
		// Parameter: $a is an array, passed by reference so we can change its value
		foreach ($a as $key => $val) {
			if (is_array($val)) {
				self::trim_array($val);
				$a[$key] = $val;
			} else if (is_string($val)) {
				$a[$key] = trim($val);
			}
		}
	}

	static function unencode_html(&$a) {
		// Unencode html entities in an array, recursing nested arrays
		// unencode < > & " ' (less than, greater than, ampersand, double quote, single quote).
		// Parameter: $a is an array, passed by reference so we can change its value		
		foreach ($a as $key => $val) {
			if (is_array($val)) {
				self::unencode_html($val);
				$a[$key] = $val;
			} else if (is_string($val)) {
				$a[$key] = str_replace('&lt;', '<', $val);
				$a[$key] = str_replace('&gt;', '>', $val);
				$a[$key] = str_replace('&#39;', "'", $val);
				$a[$key] = str_replace('&quot;', '"', $val);
				$a[$key] = str_replace('&amp;', '&', $val);
			}
		}
	}

	// functions for protecting and validating form input vars
	static function clean_input($string, $preserve_space = 0) {
		// cleans an input string, or an array of strings
		if (is_string($string)) {
			if ($preserve_space) { return self::sanitize_string(strip_tags(stripslashes($string)), $preserve_space); }
			return trim(self::sanitize_string(strip_tags(stripslashes($string))));
		} elseif (is_array($string)) {
			reset($string);
			while (list($key, $value) = each($string)) {
				$string[$key] = self::clean_input($value, $preserve_space);
			}
			return $string;
		} else {
			return $string;
		}
	}

	// functions for protecting and validating form vars
	static function sanitize_string($string, $preserve_space = 0) {
		if (!$preserve_space) { $string = preg_replace("/ +/", ' ', trim($string)); }
		return preg_replace("/[<>]/", '_', $string);
	}

	static function name_case($name) {
		// A function knowing about name case (i.e. caps on McDonald etc)
		// Usage: $name = name_case($name);	
		// Consider moving this function to SWEETCF_Process

		if (SWEETCF_Process::$form_options['name_case_enable'] !== 'true') {
			return $name; // name_case setting is disabled for si contact
		}
		if ($name == '') { return ''; }
		$break = 0;
		$newname = strtoupper($name[0]);
		for ($i = 1; $i < strlen($name); $i++) {
			$subed = substr($name, $i, 1);
			if (((ord($subed) > 64) && (ord($subed) < 123)) ||
				((ord($subed) > 48) && (ord($subed) < 58))) {
				$word_check = substr($name, $i - 2, 2);
				if (!strcasecmp($word_check, 'Mc') || !strcasecmp($word_check, "O'")) {
					$newname .= strtoupper($subed);
				} else if ($break) {
					$newname .= strtoupper($subed);
				} else {
					$newname .= strtolower($subed);
				}
				$break = 0;
			} else {
				// not a letter - a boundary
				$newname .= $subed;
				$break = 1;
			}
		}
		return $newname;
	}

	static function validate_url($url) {
	// checks proper url syntax (not perfect, none of these are, but this is the best I can find)
	// tutorialchip.com/php/preg_match-examples-7-useful-code-snippets/

		$regex = "((https?|ftp)\:\/\/)?"; // Scheme
		$regex .= "([a-zA-Z0-9+!*(),;?&=\$_.-]+(\:[a-zA-Z0-9+!*(),;?&=\$_.-]+)?@)?"; // User and Pass
		$regex .= "([a-zA-Z0-9-.]*)\.([a-zA-Z]{2,6})"; // Host or IP
		$regex .= "(\:[0-9]{2,5})?"; // Port
		$regex .= "(\/#\!)?"; // Path hash bang  (twitter))
		$regex .= "(\/([a-zA-Z0-9+\$_-]\.?)+)*\/?"; // Path
		$regex .= "(\?[a-zA-Z+&\$_.-][a-zA-Z0-9;:@&%=+\/\$_.-]*)?"; // GET Query
		$regex .= "(#[a-zA-Z_.-][a-zA-Z0-9+\$_.-]*)?"; // Anchor

		return preg_match("/^$regex$/", $url);
	}
}
?>
