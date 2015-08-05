<?php

/**
 * Description of class-sweetcf-options
 * Class used to encapsulate functions related to the options menu.
 * Functions are called statically, so no need to instantiate the class
 */
class SWEETCF_Options {

	static $form_defaults, $style_defaults;
	static $global_options, $form_options, $contacts;
	static $current_form, $form_option_name, $current_tab;
	static $av_fld_arr, $av_fld_subj_arr;	// list of avail field tags
	static $autoresp_ok, $new_field_added, $new_field_key;

	static function get_contact_list() { // Returns a list of email contacts
		if (!self::$global_options) {	self::$global_options = SWEETCF_Utils::get_global_options(); }
		$email_options = self::$global_options['email'];
		$contacts = array();
		$wp_user_name = '';
		if ( $email_options['email_to'] == 'custom' ) {
			$contacts_list = $email_options['custom_email'];
			$wp_user = $email_options['custom_email'];
		} else {
			global $wpdb; 
			$wp_user = ( isset($email_options['wp_user']) && (intval($email_options['wp_user']) > 0) ) ? intval($email_options['wp_user']) : 1; // By default - admin
			if ( $wp_user ) {
				$user = $wpdb->get_row("SELECT user_login, user_email FROM $wpdb->users WHERE ID='$wp_user'", 0); 
				$contacts_list = $user->user_email;
				$wp_user_name = $user->user_login;
			}
		}
		if (SWEETCF_Utils::validate_email($contacts_list)) {
			$contacts[] = array('CONTACT' => $wp_user_name, 'EMAIL' => $contacts_list);
		}
		return($contacts);
	}

	static function get_form_num() {
		// Set the number of the current form. Form 1 cannot be deleted, so we can use it as the default
		self::$current_form = 1;	// This is the default
		$form_num_default = 1;
		if (isset($_REQUEST['swcf_form'])) {
			self::$current_form = $_REQUEST['swcf_form'];
		} elseif (isset($_REQUEST['_wp_http_referer'])) {
			$parts = explode('swcf_form=', $_REQUEST['_wp_http_referer']);
			if (count($parts) == 2) {
				self::$current_form = absint($parts[1]);
			}
		}

		if (!is_numeric(self::$current_form)) {
			echo '<div id="message" class="error">';
			echo __('Internal Error: Invalid form number.', 'sweetcontact');
			echo "</div>\n";
			self::$current_form = $form_num_default;
		}
		self::$form_option_name = "sweetcontact_form" . self::$current_form;

		// Check for the current tab number
		self::$current_tab = (isset($_REQUEST['swcf_tab']) && is_numeric($_REQUEST['swcf_tab'])) ? absint($_REQUEST['swcf_tab']) : 1;
	}

	static function register_options_page() {
		// Add link to Admin Menu
		//$page_title, $menu_title, $capability, $menu_slug, $function = '', $icon_url = '', $position = null )
		add_menu_page(
			__('sweetContact', 'sweetcontact'), // Page title
			__('sweetContact', 'sweetcontact'), // Menu title
			'manage_options', // Capability
			'sweetcontact', // menu_slug
			'SWEETCF_Options::display_options', // function
			SWCF_URL . '/includes/images/menu-icon.png');
	}

	static function get_options() {
		if (!isset(self::$form_defaults)) {self::$form_defaults = SWEETCF_Utils::set_defaults(); }
		if (!self::$global_options) { self::$global_options = SWEETCF_Utils::get_global_options(); }
		if (!self::$form_options) { self::$form_options = SWEETCF_Utils::get_form_options(self::$current_form, true); }

		// See if the form name has changed--if so, update it in the list
		if (( self::$global_options['form_list'][self::$current_form] <> self::$form_options['form_name'] ) && self::$form_options['form_name'] <> "") {
			self::$global_options['form_list'][self::$current_form] = self::$form_options['form_name'];
			update_option('sweetcontact_global', self::$global_options);
		}

		if (count(self::$form_options) < count(self::$form_defaults)) {
			// add missing elements from the default form options array
			self::$form_options = array_merge(self::$form_defaults, self::$form_options);
		}
	}

	static function unload_options() {
		// Forces the reload of global and form options. Called by SWEETCF_Action::restore_settings()
		self::$global_options = false;
		self::$form_options = false;
	}

	static function initialize_options() {
		self::get_form_num();	// Get the current form
		// Register settings sections
		add_settings_section('swcf_name_settings', '1. '.__('Name your sweetContact form', 'sweetcontact'), 'SWEETCF_Options::settings_name_callback', 'tab_page1');
		add_settings_section('swcf_field_settings', '2. '.__('Add fields to your form', 'sweetcontact'), 'SWEETCF_Options::settings_field_callback', 'tab_page2');
		
		add_settings_section('swcf_email_settings', '3. '.__('Configure Mail', 'sweetcontact'), 'SWEETCF_Options::settings_email_callback', 'tab_page3');
		add_settings_section('swcf_design_settings', '4. '.__('Configure the sweetContact form design', 'sweetcontact'), 'SWEETCF_Options::settings_design_callback', 'tab_page4');
		add_settings_section('swcf_thankyou_settings', '5. '.__('Configure the message the user will get after `Submit`', 'sweetcontact'), 'SWEETCF_Options::settings_thankyou_callback', 'tab_page5');
		//add_settings_section('swcf_style_settings', __('Style Settings', 'sweetcontact'), 'SWEETCF_Options::style_settings_callback', 'tab_page1');
		//add_settings_section('swcf_captcha_settings', '6. '.__('Configure security on your form', 'sweetcontact'), 'SWEETCF_Options::settings_captcha_callback', 'tab_page6');
		add_settings_section(
			'swcf_basic_settings', // ID used to identify this section and with which to register options
			'6. '.__('Add your new sweetContact form to the blog', 'sweetcontact'), // Title to be displayed on the administration page
			'SWEETCF_Options::settings_basic_callback',	'tab_page6'
		);
		register_setting('sw_contact_options', 'sweetcontact_form' . self::$current_form, 'SWEETCF_Options::validate');
	}

	static function display_options() {
		
		//echo 'initCaptchaInstance count: '; var_export($_REQUEST['initCaptchaInstance']);echo '<br>'; 
		//var_export($_REQUEST['submit_register_form']);

		$form_num = self::$current_form;
		$tab_names = array(
			__('Add fields to your form', 'sweetcontact'),
			__('Configure Mail', 'sweetcontact'),
			__('Configure the sweetContact form design', 'sweetcontact'),
			__('Configure the message the user will get after `Submit`', 'sweetcontact'),
			__('Configure security on your form', 'sweetcontact'),
			__('Add your new sweetContact form to the blog', 'sweetcontact'),
		);

		$num_tabs = count($tab_names);
		
		// Process ctf_actions, if any
		if (!empty($_POST['ctf_action'])) { SWEETCF_Action::do_ctf_action(); }

		self::get_options(); // Load the options into the options array
		SWEETCF_Utils::update_lang(self::$form_options);
		SWEETCF_Utils::update_lang(self::$form_defaults);
		self::set_fld_array();

		// Create a header in the default WordPress 'wrap' container
		?>
		<div class="wrap">
			<script type="text/javascript">
				jQuery(function() {
					//jQuery("#swcf-tabs").tabs({active: <?php //echo esc_js(self::$current_tab) - 1; ?>, selected: <?php //echo esc_js(self::$current_tab) - 1; ?>}).show();
				});
			</script>

			<?php	do_action('sw_contact_menu_links');	?>

			<h2><?php _e('sweetContact Form Settings', 'sweetcontact'); ?></h2>
		
			<?php settings_errors(); ?>

			<?php
			// Display form select control. Has a preview been selected?
			$preview = ( isset($_POST['ctf_action']) && __('Preview Form', 'sweetcontact') == $_POST['ctf_action'] ) ? true : false;
			?>
			<div class="swcf_left">
				<form id="swcf_form_control" action="<?php echo admin_url('plugins.php?page=sweetcontact&swcf_form='.self::$current_form) . '&swcf_tab=' . self::$current_tab; ?>" method="post" name="previewform">
					<input type="hidden" id="tools-admin-url" value="<?php echo admin_url("plugins.php?page=sweetcontact&swcf_form=$form_num"); ?>" />
				<?php wp_nonce_field('sw_contact_options-options', 'fs_options');
				// The value of the ctf_action field will be set by javascript when needed 
				?>
					<input type="hidden" name="ctf_action" id="ctf_action" value="<?php ( $preview ? _e('Preview Form', 'sweetcontact') : _e('Edit Form', 'sweetcontact') ) ?>" />
					<div class="swcf_select_form">
						<strong><?php _e('Select a Form', 'sweetcontact'); ?>: </strong>
						<select id="form_select" name="<?php echo self::$current_form; ?>" onchange="sweetcf_set_form('<?php _e('Add Form', 'sweetcontact'); ?>');">
				<?php
				// Display Forms List to select from
				foreach (self::$global_options['form_list'] as $key => $val) {
					echo '<option value="' . esc_attr($key) . '"';
					if ((int) self::$current_form == $key)
						echo ' selected="selected"';
					echo '>' . sprintf(__('Form %d: %s', 'sweetcontact'), esc_html($key), esc_html($val)) . "</option>\n";
				}
				//echo '<option value="0">' . esc_html(__('Add a New Form', 'sweetcontact')) . "</option>\n";
				?>
						</select>
						<span class="submit">&nbsp;<input name="new_form" id="new_form" class="button-primary" type="button" onclick="sweetcf_add_new_form('<?php _e('Add Form', 'sweetcontact'); ?>');" value="<?php _e('Add a New Form', 'sweetcontact'); ?>" /></span>
						<!-- TODO: <span class="submit">&nbsp;<input name="delete" id="delete" class="button-primary" type="button" onclick="sweetcf_delete_form(<?php //echo self::$current_form; ?>)" value="<?php _e('Delete Form', 'sweetcontact'); ?>" /></span>-->
						<!-- <input type="button" name="delete" value="<?php //esc_attr_e('Delete Form', 'sweetcontact'); ?>" onclick="sweetcf_delete_form(<?php //echo self::$current_form; ?>)" />-->
						<span class="submit">&nbsp;<input id="preview" class="button-primary" type="submit" value="<?php if ($preview) { _e('Edit Form', 'sweetcontact'); } else { _e('Preview Form', 'sweetcontact'); } ?>" name="ctf_action" /></span>
					</div>
				</form>
			</div>
			<div id="ctf-loading">
			<?php echo '<img src="' . SWCF_INCLUDES . '/loading.gif' . '" width="32" height="32" alt="' . esc_attr(__('Loading...', 'sweetcontact')) . '" />';
			?></div>
			<div class='swcf_clear'></div>

		<?php
		// If Preview is selected, preview the form.  Otherwise display the settings menu
		if ($preview) {
			echo SWEETCF_Display::process_short_code(array('form' => self::$current_form));
		} else { ?>
				<form id="swcf-optionsform" name="swcf-optionsform" class="swcf_clear" action="options.php" method="post" enctype="multipart/form-data">
			<?php wp_nonce_field('sw_contact_options-options', 'fs_options'); ?>
					<div>
						<input type="hidden" name="form-changed" id="form-changed" value="0"/>
						<input type="hidden" id="cur_tab" name="current_tab" value="<?php echo self::$current_tab; ?>"/>
						<input type="hidden" id="admin_url" value="<?php echo admin_url(); ?>"/>
					</div>
					
					<div id="swcf-tabs">
						
					<ul id="fscf-tab-list" style="display: none;"><?php // Tab labels
					for ($i = 1; $i <= $num_tabs; $i++) { 
						echo '<li id="fscf-tab' . $i . '"'.'><a href="#swcf-tabs-' . $i . '">' . esc_html($tab_names[$i - 1]) . '</a></li> ';
					} ?>
					</ul>
					<?php
					for ($i = 1; $i <= $num_tabs; $i++) { ?>
						<div id="swcf-tabs-<?php echo $i;?>"> <?php
						settings_fields('sw_contact_options');
						do_settings_sections('tab_page' . $i);
						if ( in_array($i, array(2,6)) ) { ?>
							<p class="submit"><input id="submit<?php echo $i; ?>" class="button-primary" type="submit" value="<?php esc_attr_e('Save Changes', 'sweetcontact'); ?>" onclick="document.pressed = this.value" name="submit" /><br />
							<?php _e('*By pressing the "Save Changes" button you agree to the', 'sweetcontact')?> 
							<a href="http://sweetcontactform.com/index.php/terms-conditions/" target="_blank" style="font-weight: bold"><?php _e('terms & conditions', 'sweetcontact')?></a> 
							</p>
						<?php }
						if ( $i == 6 ) {?>
							<p>For support, mail us at: <a href="mailto:support@sweetcontactform.com">support@sweetcontactform.com</a></p>
						<?php }?>
						</div>
					<?php } ?>
					</div>
					<!-- </form> -->
				<?php
				?>
			</div>

					<?php
				}
	}

	static function settings_basic_callback() { ?>
		<div class="clear"></div>
		<fieldset class="swcf_settings_group">
			<div class = "swcf_tab_content">
				<?php _e('It\'s time to add this form to your blog, just copy & paste this text to your page/post', 'sweetcontact'); ?>:
				<br />
				<!--<?php //_e('Shortcode for this form:', 'sweetcontact'); ?><br />-->
				<span style="font-weight: bold;">[sweetcontact-form form='<?php echo self::$current_form; ?>']</span>
				<?php //_e('These are the basic settings.  If you want to create a simple contact form, with default settings, you only need to fill out the form label.', 'sweetcontact'); ?>
			</div>

			<?php if (self::$current_form <> 1) { ?>
			<fieldset class="swcf_settings_group">
					<!--<legend><strong><?php //_e('Reset and Delete', 'sweetcontact'); ?></strong></legend>
					<strong><?php //_e('These options will permanently affect all tabs on this form. (Form 1 cannot be deleted).', 'sweetcontact'); ?></strong>
					<br /><br/>
					<input type="button" name="reset" value="<?php //esc_attr_e('Reset Form', 'sweetcontact'); ?>" onclick="sweetcf_reset_form()" />
					<?php //_e('Reset this form to the default values.', 'sweetcontact'); ?>
					<br/><br />
					-->
						<input type="button" class="button-primary" name="delete" value="<?php esc_attr_e('Delete Form', 'sweetcontact'); ?>" onclick="sweetcf_delete_form(<?php echo self::$current_form; ?>)" />
						<span><?php _e('Delete this form permanently.', 'sweetcontact'); ?></span>
					<!--
					<input type="button" name="reset_all_styles" value="<?php //esc_attr_e('Reset Styles on all forms', 'sweetcontact'); ?>" onclick="sweetcf_reset_all_styles()" />
					<?php //_e('Reset default style settings on all forms.', 'sweetcontact'); ?>
					-->
					<br />
			</fieldset>
			<?php } ?>
		</fieldset>
				<?php
	}

	static function settings_email_callback() { 
		global $wpdb, $current_user, $swcf_captcha_instance; 
		if (!self::$global_options) {	self::$global_options = SWEETCF_Utils::get_global_options(); }
		?>
		<div class="clear"></div>
		<fieldset class="swcf_settings_group">
		<?php
			$ctf_contacts_error_message = '';
			//echo 'global_options[email]: '; var_export(self::$global_options['email']); echo '<hr>';
			$option_email_to = self::$global_options['email']['email_to'];//self::$form_options['email_to'];
			$option_custom_email = isset(self::$global_options['email']['custom_email']) ? trim(self::$global_options['email']['custom_email']) : '';
			
			self::$contacts = (self::$contacts) ? self::$contacts : self::get_contact_list();
			//echo 'contacts: '; var_export(self::$contacts); echo '<hr>';
			if ( empty(self::$contacts) ) {
				$ctf_contacts_error_message = '<strong>'.__('ERROR: ').'</strong>'.__('You must enter a valid email address in step 3<br>If you choose a WordPress user email, make sure its entered under the user email.', 'sweetcontact');
			}
			if ( empty($ctf_contacts_error_message) ) {
				if ( $option_email_to == 'custom' ) {
					if ( ! SWEETCF_Utils::validate_email($option_custom_email) ) {
						$ctf_contacts_error_message = __('ERROR: Misconfigured "Email To" address', 'sweetcontact') . ': ' . __('	Regular email address not valid', 'sweetcontact');
					}
				}
			}
			
			if ( $ctf_contacts_error_message ) {
				echo "<div id='message' class='error'>$ctf_contacts_error_message</div>\n";
				echo "<div class='swcf-error'>$ctf_contacts_error_message</div>\n";
			}
			if ( ! function_exists('mail') ) {
				echo '<div class="swcf-error">' . __('Warning: Your web host has the mail() function disabled. PHP cannot send email.', 'sweetcontact');
				echo ' ' . __('Have them fix it. Or you can install the "WP Mail SMTP" plugin and configure it to use SMTP.', 'sweetcontact') . '</div>' . "\n";
			}
			?>
			<?php 
			if ( empty($ctf_contacts_error_message) ) {
				if (self::$form_options['captcha_enable'] != 'false') {
					if ( isset( $_REQUEST['settings-updated'] ) && ($_REQUEST['settings-updated'] == 'true') ) {
						initCaptchaInstance();
						if ( 0 ) {
							echo "<div class='swcf-error'>".__('Error registering sweetContact: ', 'sweetcontact').$swcf_captcha_instance->error."</div>\n";
						}
					}
				}
			}

			?>

			<?php 
			_e('This email will be used to deliver this form submitting.'); 
			$wp_user = ( isset(self::$global_options['email']['wp_user']) ) ? self::$global_options['email']['wp_user'] : $current_user->ID;
			$users = $wpdb->get_results("SELECT ID, user_login FROM $wpdb->users ORDER BY user_login", 0); 
			?>
			<br/><br/>
			<input type="radio" name="email_to" id="sw_contact_wpuser" value="user" <?php if ($option_email_to != 'custom') echo "checked=\"checked\" "; ?>/>
			<label style="width:135px;" for="sw_contact_wpuser"><?php _e('WordPress User', 'sweetcontact'); ?></label>
      <select name="wp_user" style="width:180px;">
          <!--<option disabled><?php //_e("Select user name", 'captcha'); ?></option>-->
          <?php	foreach ($users as $user) { ?>
            <option value="<?php echo $user->ID; ?>" 
              <?php if ($wp_user == $user->ID) echo "selected=\"selected\" "; ?>><?php echo $user->user_login; ?>
            </option>
          <?php } ?>
      </select><br/>
			<input type="radio" name="email_to" id="sw_contact_custom_email" value="custom" <?php if ($option_email_to == 'custom') echo "checked=\"checked\" "; ?>/>
			<label style="width:135px;" for="sw_contact_custom_email"><?php _e('Regular email address', 'sweetcontact'); ?></label>
			<input type="text" size="50" name="custom_email" value="<?php echo $option_custom_email;?>" />
		</fieldset>
		<?php
	}

	static function settings_name_callback() { ?>
		<div class="clear"></div>
		<fieldset class="swcf_settings_group swcf_name_settings">
				<label for="sweetcontact_form_name"><?php echo sprintf(__('Form %d label', 'sweetcontact'), self::$current_form) ?>:</label><br />
				<input name="<?php echo self::$form_option_name; ?>[form_name]" id="sweetcontact_form_name" type="text"
							 value="<?php echo esc_attr(self::$form_options['form_name']); ?>" size="35" />
				<a style="cursor:pointer;" title="<?php esc_attr_e('Click for Help!', 'sweetcontact'); ?>" onclick="toggleVisibility('sweetcontact_form_name_tip');"><?php _e('help', 'sweetcontact'); ?></a>
				<br/>
				<div class="swcf_tip" id="sweetcontact_form_name_tip"><?php _e('Enter an internal name for your form. It just helps you keep track of what you are using it for.', 'sweetcontact'); ?></div>
				<div class="swcf-field-delimiter"></div>
				<label for="sw_contact_welcome"><?php _e('Form title (this title will appear on your page/post)', 'sweetcontact'); ?>:</label><br />
				<textarea rows="3" cols="70" name="<?php echo self::$form_option_name; ?>[welcome]" id="sw_contact_welcome"><?php echo esc_textarea(self::$form_options['welcome']); ?></textarea>
				<a style="cursor:pointer;" title="<?php esc_attr_e('Click for Help!', 'sweetcontact'); ?>" onclick="toggleVisibility('sw_contact_welcome_tip');"><?php _e('help', 'sweetcontact'); ?></a>
				<div class="swcf_tip" id="sw_contact_welcome_tip"><?php _e('This is printed before the form. HTML is allowed.', 'sweetcontact'); ?></div>
		</fieldset>
		<?php 
	}
	
	static function settings_field_callback() {
		$field_type_array = array(
			'text' => __('text', 'sweetcontact'),
			'textarea' => __('textarea', 'sweetcontact'),
			'checkbox' => __('checkbox', 'sweetcontact'),
			'checkbox-multiple' => __('checkbox-multiple', 'sweetcontact'),
			'radio' => __('radio', 'sweetcontact'),
			'select' => __('select', 'sweetcontact'),
			'select-multiple' => __('select-multiple', 'sweetcontact'),
			'attachment' => __('attachment', 'sweetcontact'),
			'date' => __('date', 'sweetcontact'),
			'time' => __('time', 'sweetcontact'),
			'email' => __('email', 'sweetcontact'),
			'url' => __('url', 'sweetcontact'),
			'hidden' => __('hidden', 'sweetcontact'),
			'password' => __('password', 'sweetcontact'),
			'fieldset' => __('fieldset(box-open)', 'sweetcontact'),
			'fieldset-close' => __('fieldset(box-close)', 'sweetcontact')
		);
		$select_type_fields = array(
			'checkbox-multiple',
			'select',
			'select-multiple',
			'radio'
		);

		// Display the field options 
		if (empty(self::$new_field_added)) { ?>
			<div class="" style="padding-bottom: 7px;"><input type="button" class="button-primary" name="new_field" value="Add New Field" onclick="sweetcf_add_field('Add Field')" /></div>
		<?php } ?>
		<div class="clear"></div>
		<fieldset class="swcf_settings_group swcf_field_settings">
		<?php
		// fill in any missing defaults
		$field_opt_defaults = array(
			'hide_label' => 'false',
			'placeholder' => 'false',
		);
		$placeholder_error = 0;
		$name_format_error = 0;
		$email_format_error = 0;
		$dup_field_error = 0;
		$field_names = array();
		$fields_count = count(self::$form_options['fields']);
		foreach (self::$form_options['fields'] as $key => $field) {
			$field_opt_name = self::$form_option_name . '[fields][' . $key . ']';

			// fill in any missing field options defaults
			foreach ($field_opt_defaults as $dfkey => $dfval) {
				if (!isset($field[$dfkey]) || empty($field[$dfkey]))
					$field[$dfkey] = $dfval;
			}
			?>
				<fieldset class="swcf_field" id="field-<?php echo $key + 1; ?>">
					<legend><b><?php
					$label_changed = 0;
					// are there label overrides for standard field names? standard field labels can be renamed on the labels tab
					if (SWCF_NAME_FIELD == $field['standard']) {
						if (self::$form_options['title_name'] != '') {
							$label_changed = 1;
							echo self::$form_options['title_name'];
						} else {
							echo esc_html($field['label']);
						}
					} else if (SWCF_EMAIL_FIELD == $field['standard']) {
						if (self::$form_options['title_email'] != '') {
							$label_changed = 1;
							echo self::$form_options['title_email'];
						} else {
							echo esc_html($field['label']);
							//echo 'Email:';				// correction for old forms where it was Email Address:
							//$field['label'] = 'Email:';
						}
					} else if (SWCF_SUBJECT_FIELD == $field['standard']) {
						if (self::$form_options['title_subj'] != '') {
							$label_changed = 1;
							echo self::$form_options['title_subj'];
						} else {
							echo esc_html($field['label']);
						}
					} else if (SWCF_MESSAGE_FIELD == $field['standard']) {
						if (self::$form_options['title_mess'] != '') {
							$label_changed = 1;
							echo self::$form_options['title_mess'];
						} else {
							echo esc_html($field['label']);
						}
					} else {
						echo esc_html($field['label']);
					}
					?></b> <?php
					if ('0' != $field['standard']) {
						if ($label_changed) { _e('(standard field name was changed on the Labels tab)', 'sweetcontact'); }
						else { _e('(standard field)', 'sweetcontact'); }
					}
					?>
					</legend>
					<div style="display: inline-block;">
					<input name="<?php echo $field_opt_name . '[standard]' ?>" id="<?php echo 'swcf_contact_field' . +$key + 1 . '_standard'; ?>" type="hidden" 
								 value="<?php echo esc_attr($field['standard']); ?>" />
					<input name="<?php echo $field_opt_name . '[delete]' ?>" id="delete-<?php echo +$key + 1; ?>" type="hidden"
								 value="false" />
							<?php
							// special notices
							// new field added message
							if (!empty(self::$new_field_added) && $fields_count == $key + 1) {
								// A new field was added, show a message
								echo '<div class="swcf-notice">' . self::$new_field_added . '</div>' . "\n";
								self::$new_field_key = $key + 1;
							}
							// warn if placeholder is missing the Default text
							if ($field['placeholder'] == 'true' && $field['default'] == '') {
								if (!$placeholder_error) {
									echo '<div class="updated">';
									echo __('Caution: "Default as placeholder" setting requires "Default" setting to be filled in. Correct this on the Fields tab and click <b>Save Changes</b>', 'sweetcontact');
									echo "</div>\n";
								}
								echo '<div class="swcf-notice">' . __('Caution: "Default as placeholder" setting requires "Default" setting to be filled in. Correct this in the field details and click <b>Save Changes</b>', 'sweetcontact') . '</div>' . "\n";
								$placeholder_error = 1;
							}

							// warn if name default not in proper format
							if (SWCF_NAME_FIELD == $field['standard']) {
								$name_format_array = array(
									'name' => __('Name', 'sweetcontact'),
									'first_last' => __('First Name, Last Name', 'sweetcontact'),
									'first_middle_i_last' => __('First Name, Middle Initial, Last Name', 'sweetcontact'),
									'first_middle_last' => __('First Name, Middle Name, Last Name', 'sweetcontact'),
								);
								if ($field['default'] != '' && self::$form_options['name_format'] == 'first_last') {
									if (!preg_match('/^(.*)(==)(.*)$/', $field['default'], $matches)) { $name_format_error = 'First Name==Last Name'; }
								} else if ($field['default'] != '' && self::$form_options['name_format'] == 'first_middle_last') {
									if (!preg_match('/^(.*)(==)(.*)(==)(.*)$/', $field['default'], $matches)) { $name_format_error = 'First Name==Middle Name==Last Name'; }
								} else if ($field['default'] != '' && self::$form_options['name_format'] == 'first_middle_i_last') {
									if (!preg_match('/^(.*)(==)(.*)(==)(.*)$/', $field['default'], $matches)) { $name_format_error = 'First Name==Middle Initial==Last Name'; }
								}
								if ($name_format_error) {
									$this_name_format = $name_format_array[self::$form_options['name_format']];
									echo '<div class="updated">';
									echo sprintf(__('Caution: Name field format "%s" requires the "Default" setting to be in this example format: %s. Separate words with == separators, or empty the "Default" setting. Correct this on the Fields tab and click <b>Save Changes</b>', 'sweetcontact'), $this_name_format, $name_format_error);
									echo "</div>\n";
									echo '<div class="swcf-notice">' . sprintf(__('Caution: Name field format "%s" requires the "Default" setting to be in this example format: %s. Separate words with == separators, or empty the "Default" setting. Correct this in the field details and click <b>Save Changes</b>', 'sweetcontact'), $this_name_format, $name_format_error) . '</div>' . "\n";
								}
							}

							// warn if double email default not in proper format
							if (SWCF_EMAIL_FIELD == $field['standard'] && 'true' == self::$form_options['double_email'] && $field['default'] != '') {
								if (!preg_match('/^(.*)(==)(.*)$/', $field['default'], $matches)) {
									echo '<div class="updated">';
									echo __('Caution: When "Enable double email entry" setting is enabled, the "Default" setting should be in this example format: Email==Re-enter Email. Separate words with == separators, or empty the "Default" setting. Correct this on the Fields tab and click <b>Save Changes</b>', 'sweetcontact');
									echo "</div>\n";
									echo '<div class="swcf-notice">' . __('Caution: "When Enable double email entry" setting is enabled, the "Default" setting should be in this example format: Email==Re-enter Email. Separate words with == separators, or empty the "Default" setting. Correct this in the field details and click <b>Save Changes</b>', 'sweetcontact') . '</div>' . "\n";
								}
							}

							// Make sure field names are unique
							if (in_array($field['label'], $field_names)) {
								// We have a duplicate field label, display an error message
								if (!$dup_field_error) {
									echo '<div class="updated">';
									echo __('Caution: Duplicate field label. Now you must change the field label on the Fields tab and click <b>Save Changes</b>', 'sweetcontact');
									echo "</div>\n";
								}
								echo '<div class="swcf-notice">' . __('Caution: Duplicate field label. Change the field label and click <b>Save Changes</b>', 'sweetcontact') . '</div>' . "\n";
								$dup_field_error = 1;
							}
							$field_names[] = $field['label'];
							$k = +$key + 1;
							?>
					<label for="swcf_contact_field<?php echo +$key + 1; ?>_label"><?php _e('Label:', 'sweetcontact'); ?></label>
					<input name="<?php echo $field_opt_name . '[label]' ?>" id="swcf_contact_field<?php echo +$key + 1; ?>_label" type="text"
								 value="<?php echo esc_attr($field['label']); ?>" size="70"
								 <?php //if ($field['standard'] > 0) { echo ' readonly="readonly"'; } ?>/>

					<label for="<?php //echo 'swcf_contact_field' . +$key + 1 . '_type' ?>"><?php //echo __('Field type:', 'sweetcontact'); ?></label>
								 <?php // Disable field type select for name and message ?>
					<?php $disabled_field = '';// TODO: $disabled_field = ('0' == $field['standard'] && empty(self::$new_field_added)) ? '' : 'disabled';  ?>
					<select <?php echo $disabled_field;?> style="width: 160px; display: none;" id="<?php echo 'swcf_contact_field' . +$key + 1 . '_type' ?>"
						<?php
							if ( (SWCF_NAME_FIELD == $field['standard']) || (SWCF_MESSAGE_FIELD == $field['standard']) 
								|| (SWCF_SUBJECT_FIELD == $field['standard']) || (SWCF_EMAIL_FIELD == $field['standard']) ) 
							{
								echo ' disabled="disabled">';
							} else {
								echo ' name="' . $field_opt_name . '[type]">';
							}

							$selected = '';
							// Limit options for the Email and Subject fields
							if (SWCF_EMAIL_FIELD == $field['standard']) {
									 // Only allow 'text' and 'email' type options
									 if ($field['type'] == 'text') { $selected = ' selected="selected"'; }
									 echo '<option value="text"' . $selected . '>' . esc_html(__('text', 'sweetcontact')) . '</option>' . "\n";
									 /*$selected = '';
									 if ($field['type'] == 'email') { $selected = ' selected="selected"'; }
									 echo '<option value="email"' . $selected . '>' . esc_html(__('email', 'sweetcontact')) . '</option>' . "\n";*/
							} else if (SWCF_SUBJECT_FIELD == $field['standard']) {
									 if ($field['type'] == 'text') { $selected = ' selected="selected"'; }
									 echo '<option value="text"' . $selected . '>' . esc_html(__('text', 'sweetcontact')) . '</option>' . "\n";
									 /*
									 $selected = '';
									 if ($field['type'] == 'select') { $selected = ' selected="selected"'; }
									 echo '<option value="select"' . $selected . '>' . esc_html(__('select', 'sweetcontact')) . '</option>' . "\n";
								  */
							} else {
									 foreach ($field_type_array as $k => $v) {
										 if ($field['type'] == "$k") { $selected = ' selected="selected"'; }
										 echo '<option value="' . esc_attr($k) . '"' . $selected . '>' . esc_html($v) . '</option>' . "\n";
										 $selected = '';
									 }
							}
							?>
					</select>
					<!--<p class="submit">-->
					<?php $disabled_field = ('0' == $field['standard'] && empty(self::$new_field_added)) ? '' : 'disabled';  ?>
					<input <?php echo $disabled_field;?>  type="button" class="button-primary" name="<?php echo 'delete-field-' . $key; ?>" value="<?php esc_attr_e('Delete Field', 'sweetcontact'); ?>" onclick="sweetcf_delete_field('<?php echo $key + 1; ?>')" />
					<!--</p>-->
				
					
					<?php
					if (SWCF_NAME_FIELD == $field['standard'] || SWCF_MESSAGE_FIELD == $field['standard']) { /* Provide type field for disabled select lists */ ?>
					<input type="hidden" name="<?php echo $field_opt_name . '[type]'; ?>" value="<?php echo $field["type"]; ?>" />
					<?php } ?>
				&nbsp;&nbsp;
				
				<?php if ("true" == $field['disable'])
					echo '&nbsp;&nbsp<span class="swcf_warning_text">' . __('DISABLED', 'sweetcontact') . '</span>';
				?><br />
				<div id="field<?php echo $key; ?>" class="swcf_field_details">
				<?php
				if ('0' != $field['standard']) { _e('Standard field labels can be changed on the Labels tab.', 'sweetcontact'); echo '<br />'; }
				if (SWCF_NAME_FIELD == $field['standard']) { // Add special fields for the Name field  
					?>
						<label for="sw_contact_name_format"><?php _e('Name field format:', 'sweetcontact'); ?></label>
						<select id="sw_contact_name_format" name="<?php echo self::$form_option_name; ?>[name_format]">
						<?php
						$selected = '';
						foreach ($name_format_array as $k => $v) {
							$selected = (self::$form_options['name_format'] == "$k") ? ' selected="selected"' : '';
							echo '<option value="' . esc_attr($k) . '"' . $selected . '>' . esc_html($v) . '</option>' . "\n";
						}
						?>
						</select>
						<a style="cursor:pointer;" title="<?php esc_attr_e('Click for Help!', 'sweetcontact'); ?>" onclick="toggleVisibility('sw_contact_name_format_tip');"><?php _e('help', 'sweetcontact'); ?></a>
						<div class="swcf_tip" id="sw_contact_name_format_tip">
							<?php _e('Select how the name field is formatted on the form.', 'sweetcontact'); ?>
						</div>&nbsp;&nbsp;&nbsp;&nbsp;
						<input name="<?php echo self::$form_option_name; ?>[auto_fill_enable]" id="sw_contact_auto_fill_enable" type="checkbox" <?php if (self::$form_options['auto_fill_enable'] == 'true') echo 'checked="checked"'; ?> value="true" />
						<label for="sw_contact_auto_fill_enable"><?php _e('Enable auto form fill', 'sweetcontact'); ?>.</label>
						<a style="cursor:pointer;" title="<?php esc_attr_e('Click for Help!', 'sweetcontact'); ?>" onclick="toggleVisibility('sw_contact_auto_fill_enable_tip');"><?php _e('help', 'sweetcontact'); ?></a>
						<div class="swcf_tip" id="sw_contact_auto_fill_enable_tip">
						<?php _e('Auto form fill email address and name (username) on the contact form for logged in users who are not administrators.', 'sweetcontact'); ?>
						</div>
						<br />
				<?php }
			?>

					<label for="<?php echo 'swcf_contact_field' . +$key + 1 . '_slug' ?>"><?php echo __('Tag', 'sweetcontact'); ?>:</label>
					<input name="<?php echo $field_opt_name . '[slug]' ?>" id="<?php echo 'swcf_contact_field' . +$key + 1 . '_slug' ?>" type="text" 
								 value="<?php echo esc_attr($field['slug']); ?>" <?php if ($field['standard'] != '0') echo ' readonly'; ?> size="45" />	

					&nbsp;&nbsp;&nbsp;<input name="<?php echo $field_opt_name . '[req]' ?>" id="<?php echo 'swcf_contact_field' . +$key + 1 . '_req' ?>" type="checkbox" 
			<?php if ($field['req'] == 'true' || ( SWCF_EMAIL_FIELD == $field['standard'] && self::$form_options['double_email'] == 'true' )) echo 'checked="checked"'; ?> value="true" />
					<label for="<?php echo 'swcf_contact_field' . +$key + 1 . '_req' ?>"><?php _e('Required field', 'sweetcontact'); ?></label>&nbsp;&nbsp;

					&nbsp;&nbsp;&nbsp;<input name="<?php echo $field_opt_name . '[disable]' ?>" id="<?php echo 'swcf_contact_field' . +$key + 1 . '_disable' ?>" type="checkbox" 
			<?php if ($field['disable'] == 'true') echo 'checked="checked"'; ?> value="true" />
					<label for="<?php echo 'swcf_contact_field' . +$key + 1 . '_disable' ?>"><?php _e('Disable field', 'sweetcontact'); ?></label>

					&nbsp;&nbsp;&nbsp;<input name="<?php echo $field_opt_name . '[follow]' ?>" id="<?php echo 'swcf_contact_field' . +$key + 1 . '_follow' ?>" type="checkbox"
			<?php if ($field['follow'] == 'true') echo 'checked="checked"'; ?> value="true" />
					<label for="<?php echo 'swcf_contact_field' . +$key + 1 . '_follow' ?>"><?php _e('Follow previous field', 'sweetcontact'); ?></label><br />

					<strong><?php echo __('Field modifiers', 'sweetcontact'); ?>:</strong>&nbsp;&nbsp;
					<label for="<?php echo 'swcf_contact_field' . +$key + 1 . '_default' ?>"><?php echo __('Default', 'sweetcontact'); ?>:</label>
					<input name="<?php echo $field_opt_name . '[default]' ?>" id="<?php echo 'swcf_contact_field' . +$key + 1 . '_default' ?>" type="text"
								 value="<?php echo esc_attr($field['default']); ?>" size="45" />

					&nbsp;&nbsp;&nbsp;<input name="<?php echo $field_opt_name . '[hide_label]' ?>" id="<?php echo 'swcf_contact_field' . +$key + 1 . '_hide_label' ?>" type="checkbox"
			<?php if ($field['hide_label'] == 'true') echo 'checked="checked"'; ?> value="true" />
					<label for="<?php echo 'swcf_contact_field' . +$key + 1 . '_hide_label' ?>"><?php _e('Hide label', 'sweetcontact'); ?></label>

					&nbsp;&nbsp;&nbsp;<input name="<?php echo $field_opt_name . '[placeholder]' ?>" id="<?php echo 'swcf_contact_field' . +$key + 1 . '_placeholder' ?>" type="checkbox"
			<?php if ($field['placeholder'] == 'true') echo 'checked="checked"'; ?> value="true" />
					<label for="<?php echo 'swcf_contact_field' . +$key + 1 . '_placeholder' ?>"><?php _e('Default as placeholder', 'sweetcontact'); ?></label>


					<div class="swcf-clear"></div>
					<div class="swcf_left">
						<label for="<?php echo 'swcf_contact_field' . +$key + 1 . '_options' ?>"><?php echo __('Select options', 'sweetcontact');
						if (in_array($field['type'], $select_type_fields)) { echo ' (Required)'; } ?>:</label><br />
						<textarea rows="6" cols="40" name="<?php echo $field_opt_name . '[options]' ?>" id="<?php echo 'swcf_contact_field' . +$key + 1 . '_options' ?>"><?php echo esc_textarea(trim($field['options'])); ?></textarea></div>
					&nbsp;&nbsp;&nbsp;<input name="<?php echo $field_opt_name . '[inline]' ?>" id="<?php echo 'swcf_contact_field' . +$key + 1 . '_inline' ?>" type="checkbox"
							<?php if ($field['inline'] == 'true') echo 'checked="checked"'; ?> value="true" />
					<label for="<?php echo 'swcf_contact_field' . +$key + 1 . '_inline' ?>"><?php _e('Inline', 'sweetcontact'); ?></label>&nbsp;&nbsp;&nbsp;

					<label for="<?php echo 'swcf_contact_field' . +$key + 1 . '_max_len' ?>"><?php echo __('Max length', 'sweetcontact'); ?>:</label>
					<input name="<?php echo $field_opt_name . '[max_len]' ?>" id="<?php echo 'swcf_contact_field' . +$key + 1 . '_max_len' ?>" type="text" 
								 value="<?php echo esc_attr($field['max_len']); ?>" size="2" />

					<label for="<?php echo 'swcf_contact_field' . +$key + 1 . '_attributes' ?>"><?php echo __('Attributes', 'sweetcontact'); ?>:</label>
					<input name="<?php echo $field_opt_name . '[attributes]' ?>" id="<?php echo 'swcf_contact_field' . +$key + 1 . '_attributes' ?>" type="text" 
								 value="<?php echo esc_attr($field['attributes']); ?>" size="20" />

					<br /><label for="<?php echo 'swcf_contact_field' . +$key + 1 . '_regex' ?>"><?php echo __('Validation regex', 'sweetcontact'); ?>:</label>
					<input name="<?php echo $field_opt_name . '[regex]' ?>" id="<?php echo 'swcf_contact_field' . +$key + 1 . '_regex' ?>" type="text" 
								 value="<?php echo esc_attr($field['regex']); ?>" size="20" /><br />

					<label for="<?php echo 'swcf_contact_field' . +$key + 1 . '_regex_error' ?>"><?php echo __('Regex fail message', 'sweetcontact'); ?>:</label>
					<input name="<?php echo $field_opt_name . '[regex_error]' ?>" id="<?php echo 'swcf_contact_field' . +$key + 1 . '_regex_error' ?>" type="text" 
								 value="<?php echo esc_attr($field['regex_error']); ?>" size="35" /><br />

					<label for="<?php echo 'swcf_contact_field' . +$key + 1 . '_label_css' ?>"><?php echo __('Label CSS', 'sweetcontact'); ?>:</label>
					<input name="<?php echo $field_opt_name . '[label_css]' ?>" id="<?php echo 'swcf_contact_field' . +$key + 1 . '_label_css' ?>" type="text" 
								 value="<?php echo esc_attr($field['label_css']); ?>" size="53" />

					<br /><label for="<?php echo 'swcf_contact_field' . +$key + 1 . '_input_css' ?>"><?php echo __('Input CSS', 'sweetcontact'); ?>:</label>
					<input name="<?php echo $field_opt_name . '[input_css]' ?>" id="<?php echo 'swcf_contact_field' . +$key + 1 . '_input_css' ?>" type="text" 
								 value="<?php echo esc_attr($field['input_css']); ?>" size="53" /><br />

					<?php do_action('swcf_contact_fields_extra_modifiers', $field_opt_name, $field, $key); ?>

					<div class="clear"></div>
					<label for="<?php echo 'swcf_contact_field' . +$key + 1 . '_notes' ?>"><?php _e('HTML before form field:', 'sweetcontact'); ?></label><br />
					<textarea rows="2" cols="40" name="<?php echo $field_opt_name . '[notes]' ?>" id="<?php echo 'swcf_contact_field' . +$key + 1 . '_notes' ?>"><?php echo esc_textarea($field['notes']); ?></textarea><br />

					<label for="<?php echo 'swcf_contact_field' . +$key + 1 . '_notes_after' ?>"><?php _e('HTML after form field:', 'sweetcontact'); ?></label><br />
					<textarea rows="2" cols="40" name="<?php echo $field_opt_name . '[notes_after]' ?>" id="<?php echo 'swcf_contact_field' . +$key + 1 . '_notes_after' ?>"><?php echo esc_textarea($field['notes_after']); ?></textarea><br />

					<?php
						if (SWCF_EMAIL_FIELD == $field['standard']) {
							// Add extra field to the email field
							?>
							<br /><input name="<?php echo self::$form_option_name; ?>[double_email]" id="sw_contact_double_email" type="checkbox" <?php if (self::$form_options['double_email'] == 'true') echo 'checked="checked"'; ?> value="true" />
							<label for="sw_contact_double_email"><?php _e('Enable double email entry required on the form.', 'sweetcontact'); ?></label>
							<a style="cursor:pointer;" title="<?php esc_attr_e('Click for Help!', 'sweetcontact'); ?>" onclick="toggleVisibility('sw_contact_double_email_tip');"><?php _e('help', 'sweetcontact'); ?></a>
						<div class="swcf_tip" id="sw_contact_double_email_tip">
				<?php _e('Requires users to enter email address in two fields to help reduce mistakes. Note: "Required field" will also be set.', 'sweetcontact') ?>
						</div>
						<br />
							<?php
						}
						?>
				</div>
				</div>
			</fieldset>
			<?php } ?>
		</fieldset>

		<?php
	}

	static function settings_design_callback() { ?>
		<div class="clear"></div>
		<fieldset class="swcf_settings_group">
			<input name="<?php echo self::$form_option_name; ?>[design_type]" id="sw_contact_design_type_horizontal" type="radio" <?php if (self::$form_options['design_type'] != '2') echo ' checked="checked" '; ?> value="1" />
			<label for="sw_contact_design_type_horizontal"><?php _e('Horizontal form', 'sweetcontact'); ?></label>
			<br/>
			<input name="<?php echo self::$form_option_name; ?>[design_type]" id="sw_contact_design_type_vertical" type="radio" <?php if (self::$form_options['design_type'] == '2') echo ' checked="checked" '; ?> value="2" />
			<label for="sw_contact_design_type_vertical"><?php _e('Vertical form', 'sweetcontact'); ?></label>
		</fieldset>
		<?php
	}

	static function settings_thankyou_callback() { ?>
		<div class="clear"></div>
		<fieldset class="swcf_settings_group">
			<input name="<?php echo self::$form_option_name; ?>[redirect_enable]" id="sw_contact_thankyou_type_text" type="radio" <?php if (self::$form_options['redirect_enable'] != 'true') echo ' checked="checked" '; ?> value="false" />
			<label style="width:120px;" for="sw_contact_thankyou_type_text"><?php _e('Display text', 'sweetcontact'); ?></label>
			<input type="text" name="<?php echo self::$form_option_name; ?>[text_message_sent]" value="<?php echo self::$form_options['text_message_sent'];?>" size="60" />
			<br/>
			<input name="<?php echo self::$form_option_name; ?>[redirect_enable]" id="sw_contact_thankyou_type_redirect" type="radio" <?php if (self::$form_options['redirect_enable'] == 'true') echo ' checked="checked" '; ?> value="true" />
			<label style="width:120px;" for="sw_contact_thankyou_type_redirect"><?php _e('Redirect to page', 'sweetcontact'); ?>:</label>
			<input name="<?php echo self::$form_option_name; ?>[redirect_url]" id="sw_contact_redirect_url" type="text" value="<?php echo esc_attr(self::$form_options['redirect_url']); ?>" size="60" />
			<a style="cursor:pointer;" title="<?php esc_attr_e('Click for Help!', 'sweetcontact'); ?>" onclick="toggleVisibility('sw_contact_redirect_url_tip');"><?php _e('help', 'sweetcontact'); ?></a>
			<div class="swcf_tip" id="sw_contact_redirect_url_tip">
			<?php _e('The form will redirect to this URL after success. This can be used to redirect to the blog home page, or a custom "Thank You" page.', 'sweetcontact'); ?>
			<?php _e('Use FULL URL including http:// for best results.', 'sweetcontact'); ?>  <?php _e('You can set to # for redirect to same page.', 'sweetcontact'); ?>
			</div>
		</fieldset>
		<?php
	}

	static function settings_captcha_callback() { ?>
			<div class="clear"></div>
			<fieldset class="swcf_settings_group">
				<!--
				<input onclick="jQuery('#captcha-settings').show();" name="<?php //echo self::$form_option_name; ?>[captcha_enable]" id="sw_contact_captcha_enable" type="radio" <?php //if (self::$form_options['captcha_enable'] != '0') echo ' checked="checked" '; ?> value="1" />
				<label for="sw_contact_captcha_enable"><?php //_e('Use Captcha', 'sweetcontact'); ?></label>
				<br/>
				<input onclick="jQuery('#captcha-settings').hide();" name="<?php //echo self::$form_option_name; ?>[captcha_enable]" id="sw_contact_captcha_invisible" type="radio" <?php //if (self::$form_options['captcha_enable'] == '0') echo ' checked="checked" '; ?> value="0" />
				<label for="sw_contact_captcha_invisible"><?php //_e('invisible CAPTCHA', 'sweetcontact'); ?></label>
				<a style="cursor:pointer;" title="<?php //esc_attr_e('Click for Help!', 'sweetcontact'); ?>" onclick="toggleVisibility('sw_contact_captcha_enable_tip');"><?php //_e('help', 'sweetcontact'); ?></a>
				<div class="swcf_tip" id="sw_contact_captcha_enable_tip">
				<?php //_e('Prevents automated spam bots by requiring that users pass a CAPTCHA test before posting.', 'sweetcontact') ?>
				</div>
				<br />
				-->
				<input value="1" onclick="jQuery('#captcha-settings').toggle();" name="<?php echo self::$form_option_name; ?>[captcha_enable]" 
							 id="sw_contact_captcha_enable" type="checkbox" <?php if (self::$form_options['captcha_enable'] != 'false') echo ' checked="checked" '; ?> />
				<label for="sw_contact_captcha_enable"><?php _e('Use Captcha', 'sweetcontact'); ?></label>
				<!--
				<input name="<?php //echo self::$form_option_name; ?>[captcha_perm]" id="sw_contact_captcha_perm" type="checkbox" <?php //if (self::$form_options['captcha_perm'] == 'true') echo 'checked="checked"'; ?> value="true" />
				<label for="<?php //echo self::$form_option_name; ?>[captcha_perm]"><?php //_e('Hide CAPTCHA for', 'sweetcontact'); ?>
					<strong><?php //_e('registered', 'sweetcontact'); ?></strong> <?php //__('users who can', 'sweetcontact'); ?>:</label>
			<?php //self::sw_contact_captcha_perm_dropdown(self::$form_option_name . '[captcha_perm_level]', self::$form_options['captcha_perm_level']); ?>
				<br />
				-->
				<div id="captcha-settings" style="margin: 10px 0px 0px 10px;<?php echo (self::$form_options['captcha_enable'] == '1') ? '' : 'display: none;'; ?>">
				<?php $captcha_is_registered = function_exists('sweetcontact_captcha_is_registered') && sweetcontact_captcha_is_registered(); ?>
				<?php if ( $captcha_is_registered && 0) { ?>
					<div style="padding: 0;">
          <a href="https://www.sweetcontactform.com/accounts/signin?ref=sweetcontact" target="_blank" style="font-weight: bold"><?php _e('Log in', 'sweetcontact')?></a> <?php _e('to your Captcha account for changing your language, design and additional settings', 'sweetcontact')?>
          <div style="color: #999;"><?php _e('Your password was sent to you in your welcome email', 'sweetcontact')?></div>
					</div>
				<?php } else if ( 0 ) { 
					//self::$contacts = (self::$contacts) ? self::$contacts : SWEETCF_Options::get_contact_list();
					//$email_to = self::$contacts[0]['EMAIL'];
				?>
					<div class="swcf-error"><?php _e('ERROR: ', 'sweetcontact'); echo SWCF_NOT_READY; ?></div>
				<?php }	?>
			</div>
			</fieldset>
			<?php
	}

	/* ------------------------------------------------------------------------ * 
	* Validate and Default setup functions
	* ------------------------------------------------------------------------ */

	static function validate($text) {
		// Wordpress will call this function upon activating and when the Form Settings is submitted

		global $swcf_special_slugs;	// List of reserved slug names
		
		if (!self::$global_options) { self::$global_options = SWEETCF_Utils::get_global_options(); }
		self::$form_defaults = SWEETCF_Utils::set_defaults();
		if (!isset(self::$form_options)) { self::$form_options = SWEETCF_Utils::get_form_options(self::$current_form, false); }

		// Update global options array based on value of enable_php_sessions
		// if the POST variable enable_php_session, then the checkbox was checked
		$php_sessions = ( isset($_POST['enable_php_sessions']) ) ? 'true' : 'false';
		if ($php_sessions <> self::$global_options['enable_php_sessions']) {
			self::$global_options['enable_php_sessions'] = $php_sessions;
		}
		if ( isset($_POST['email_to']) && isset($_POST['wp_user']) && isset($_POST['custom_email']) ) {
			self::$global_options['email']['email_to'] = $_POST['email_to'];
			self::$global_options['email']['wp_user'] = $_POST['wp_user'];
			self::$global_options['email']['custom_email'] = $_POST['custom_email'];
		}
		update_option('sweetcontact_global', self::$global_options);

		SWEETCF_Utils::trim_array($text); // Trim trailing spaces

		// Special processing for certain form fields
		//if ( !isset($text['email_to']) || ('' == $text['email_to']) ) { $text['email_to'] = self::$form_defaults['email_to']; } // use default if empty
		$text['redirect_seconds'] = ( isset($text['redirect_seconds']) && is_numeric($text['redirect_seconds']) && ($text['redirect_seconds'] < 61) ) ? absint($text['redirect_seconds']) : self::$form_defaults['redirect_seconds'];
		$text['redirect_url'] = ( isset($text['redirect_url']) && !empty($text['redirect_url']) ) ? $text['redirect_url'] : self::$form_defaults['redirect_url']; // use default if empty
		if ( !isset($text['cal_start_day']) || !preg_match('/^[0-6]?$/', $text['cal_start_day'])) {
			$text['cal_start_day'] = self::$form_defaults['cal_start_day'];
		}
		$text['attach_types'] = (isset($text['attach_types'])) ? str_replace('.', '', $text['attach_types']) : '';
		if ( !isset($text['attach_size']) || ('' == $text['attach_size']) || !preg_match('/^([[0-9.]+)([kKmM]?[bB])?$/', $text['attach_size'])) {
			$text['attach_size'] = self::$form_defaults['attach_size'];
		}
		if ( !isset($text['auto_respond_from_name']) || ('' == $text['auto_respond_from_name']) ) {
			$text['auto_respond_from_name'] = self::$form_defaults['auto_respond_from_name']; // use default if empty
		}
		if ( !isset($text['auto_respond_from_email']) || ('' == $text['auto_respond_from_email']) || !SWEETCF_Utils::validate_email($text['auto_respond_from_email'])) {
			$text['auto_respond_from_email'] = self::$form_defaults['auto_respond_from_email']; // use default if empty
		}
		if ( !isset($text['auto_respond_reply_to']) || ($text['auto_respond_reply_to'] == '') || !SWEETCF_Utils::validate_email($text['auto_respond_reply_to'])) {
			$text['auto_respond_reply_to'] = self::$form_defaults['auto_respond_reply_to']; // use default if empty
		}
			
		//	$text['field_size'] = ( is_numeric( $text['field_size'] ) && $text['field_size'] > 14 ) ? absint( $text['field_size'] ) : self::$form_defaults['field_size']; // use default if empty
		//$text['captcha_field_size'] = ( is_numeric( $text['captcha_field_size'] ) && $text['captcha_field_size'] > 4 ) ? absint( $text['captcha_field_size'] ) : self::$form_defaults['captcha_field_size'];
		//$text['text_cols'] = absint( $text['text_cols'] );
		//$text['text_rows'] = absint( $text['text_rows'] );

		if (!empty($text['domain_protect_names'])) {
			$text['domain_protect_names'] = self::clean_textarea($text['domain_protect_names']);
		}
		
		//if (!empty($text['email_to'])) { $text['email_to'] = self::clean_textarea($text['email_to']); }

		// Use default style settings if styles are empty
		if (!isset(self::$style_defaults)) {
			self::$style_defaults = SWEETCF_Utils::set_style_defaults();
		}
		foreach (self::$style_defaults as $key => $val) {
			if (!isset($text[$key]) || empty($text[$key])) { $text[$key] = $val; }
		}

		// Do we need to reset all styles top this form?
		if (isset($_POST['swcf_reset_styles'])) { // reset styles feature
			$text = SWEETCF_Action::copy_styles(self::$form_defaults, $text);
		}
		// List of all checkbox settings names (except for checkboxes in fields)
		$checkboxes = array('email_from_enforced', 'preserve_space_enable', 'double_email',
			'name_case_enable', 'sender_info_enable', 'domain_protect', 'email_check_dns',
			'email_html', 'captcha_enable',
			'captcha_small', 'email_hide_empty', 'email_keep_attachments', 'print_form_enable',
			'captcha_perm', 'honeypot_enable', 'redirect_enable', 'redirect_query', 'redirect_email_off',
			'silent_email_off', 'export_email_off', 'ex_fields_after_msg', 'email_inline_label',
			'textarea_html_allow', 'enable_areyousure', 'auto_respond_enable', 'auto_respond_html',
			'req_field_indicator_enable', 'req_field_label_enable', 'border_enable', 'anchor_enable',
			'aria_required', 'auto_fill_enable', 'enable_reset',
		);

		// Set missing checkbox values to 'false' because these boxes were unchecked
		// html form checkboxes do not return anything in POST if unchecked
		// $text = array_merge($unchecked, $text);
		foreach ($checkboxes as $checkbox) {
			if (!isset($text[$checkbox])) { $text[$checkbox] = 'false'; }
		}

		// Sanitize settings fields
		$html_fields = array('welcome', 'after_form_note', 'req_field_indicator', 'text_message_sent');
		if ('true' == $text['auto_respond_html']) { $html_fields[] = 'auto_respond_message'; }
		foreach ($text as $key => $value) {
			if (is_string($value)) {
				if (in_array($key, $html_fields)) {
					//$text[$key] = wp_filter_kses( $value );  //strips too much
					$text[$key] = $value;
				} else {
					$text[$key] = strip_tags($value);
				}
			}
		}

		// Process contact form fields
		$slug_list = $swcf_special_slugs;
		// The $special_slugs list is also used in SWEETCF_Display::get_query_parms()
		// $special_slugs = array( 'f_name', 'm_name', 'mi_name', 'l_name', 'email2', 'mailto_id', 'subject_id' );
		$select_type_fields = array(
			'checkbox-multiple',
			'select',
			'select-multiple',
			'radio'
		);
		foreach ($text['fields'] as $key => $field) {
			$field['type'] = isset($field['type']) ? $field['type'] : 'text';
			if (isset($field['delete']) && "true" == $field['delete']) {
				unset($text['fields'][$key]); // Delete the field
			} else {
				unset($text['fields']['$key']['delete']);	// Don't need to keep this
				// Add 'false' to any missing checkboxes for fields
				if (!isset($field['req'])) { $text['fields'][$key]['req'] = 'false'; }
				if (!isset($field['disable'])) { $text['fields'][$key]['disable'] = 'false'; }
				if (!isset($field['follow'])) { $text['fields'][$key]['follow'] = 'false'; }
				if (!isset($field['inline'])) { $text['fields'][$key]['inline'] = 'false'; }
				if (!isset($field['hide_label'])) { $text['fields'][$key]['hide_label'] = 'false'; }
				if (!isset($field['placeholder'])) { $text['fields'][$key]['placeholder'] = 'false'; }

				// Sanitize html in form field settings
				foreach ($field as $k => $v) {
					if (is_string($v)) {
						//if ( 'notes' == $k || 'notes_after' == $k ) $text['fields'][$key][$k] = wp_filter_kses( $v );  //strips too much
						if ('notes' == $k || 'notes_after' == $k) {
							$text['fields'][$key][$k] = $v;	// allow html
						} else {
							$text['fields'][$key][$k] = strip_tags($v);	// strip html tags
						}
					}
				}

				// Make sure the field name is not blank
				if (empty($field['label'])) {
					$text['fields'][$key]['label'] = sprintf(__('Field %s', 'sweetcontact'), $key);
					$temp = sprintf(__('Field label cannot be blank.  Label set to "Field  %s". To delete a field, use the delete option.', 'sweetcontact'), $key);
					add_settings_error('swcf_field_settings', 'missing-label', $temp);
				}

				// Sanitize the slug
				$slug_changed = false;
				if (empty($field['slug'])) {
					// no slug, so make one from the label
					// the sanitize title function encodes UTF-8 characters, so we need to undo that
					// this line croaked on some chinese characters
					//$field['slug'] = substr( urldecode(sanitize_title_with_dashes(remove_accents($field['label']))), 0, SWCF_MAX_SLUG_LEN );

					$field['slug'] = remove_accents($field['label']);
					$field['slug'] = preg_replace('~([^a-zA-Z\d_ .-])~', '', $field['slug']);
					$field['slug'] = substr(urldecode(sanitize_title_with_dashes($field['slug'])), 0, SWCF_MAX_SLUG_LEN);
					if ($field['slug'] == '') { $field['slug'] = 'na'; }
					if ('-' == substr($field['slug'], strlen($field['slug']) - 1, 1)) { $field['slug'] = substr($field['slug'], 0, strlen($field['slug']) - 1); }
					$slug_changed = true;
				} else if (empty(self::$form_options['fields'][$key]['slug']) || ( $field['slug'] != self::$form_options['fields'][$key]['slug'] )) {
					// The slug has changed, so sanitize it
					// this line croaked on some chinese characters
					//$field['slug'] = substr( urldecode(sanitize_title_with_dashes(remove_accents($field['slug']))), 0, SWCF_MAX_SLUG_LEN );

					$field['slug'] = remove_accents($field['slug']);
					$field['slug'] = preg_replace('~([^a-zA-Z\d_ .-])~', '', $field['slug']);
					$field['slug'] = substr(urldecode(sanitize_title_with_dashes($field['slug'])), 0, SWCF_MAX_SLUG_LEN);
					if ($field['slug'] == '')
						$field['slug'] = 'na';
					$slug_changed = true;
				}

				// Make sure the slug is unique
				if ($slug_changed) {
					$text['fields'][$key]['slug'] = self::check_slug($field['slug'], $slug_list);
				}
			}
			$slug_list[] = $text['fields'][$key]['slug'];

			// If a select type field, make sure the select options list is not empty
			if (in_array($field['type'], $select_type_fields)) {
				// remove blank lines and trim options
				if (!empty($text['fields'][$key]['options'])) { $text['fields'][$key]['options'] = self::clean_textarea($text['fields'][$key]['options']); }
				if (empty($field['options'])) {
					$temp = sprintf(__('Select options are required for the `%s` field.', 'sweetcontact'), $field['label']);
					add_settings_error('swcf_field_settings', 'missing-options', $temp);
				}
			}

			// If date type field, check format of default (if any)
			if ( ('date' == $field['type']) && ('' != $field['default']) ) {
				if (!SWEETCF_Process::validate_date($field['default'], self::$current_form)) {
					$cal_date_array = array(
						'mm/dd/yyyy' => esc_html(__('mm/dd/yyyy', 'sweetcontact')),
						'dd/mm/yyyy' => esc_html(__('dd/mm/yyyy', 'sweetcontact')),
						'mm-dd-yyyy' => esc_html(__('mm-dd-yyyy', 'sweetcontact')),
						'dd-mm-yyyy' => esc_html(__('dd-mm-yyyy', 'sweetcontact')),
						'mm.dd.yyyy' => esc_html(__('mm.dd.yyyy', 'sweetcontact')),
						'dd.mm.yyyy' => esc_html(__('dd.mm.yyyy', 'sweetcontact')),
						'yyyy/mm/dd' => esc_html(__('yyyy/mm/dd', 'sweetcontact')),
						'yyyy-mm-dd' => esc_html(__('yyyy-mm-dd', 'sweetcontact')),
						'yyyy.mm.dd' => esc_html(__('yyyy.mm.dd', 'sweetcontact')),
					);
					$temp = sprintf(__('Default date for %s is not correctly formatted. Format should be %s.', 'sweetcontact'), $field['label'], $cal_date_array[$text['date_format']]);
					add_settings_error('swcf_field_settings', 'invalid-date', $temp);
				}
			}
		}

		SWEETCF_Utils::unencode_html($text);

		// Update the query args if necessary
		if (!isset($_POST['ctf_action']) && isset($_REQUEST['_wp_http_referer'])) {
			// Set the current tab in _wp_http_referer so that we go there after the save
			$wp_referer = remove_query_arg('swcf_tab', $_REQUEST['_wp_http_referer']);
			$wp_referer = add_query_arg('swcf_tab', $_POST['current_tab'], $wp_referer);
			$_REQUEST['_wp_http_referer'] = $wp_referer;
		}
		return( $text );
	}

// end function validate($text);

	static function check_slug($slug, $slug_list) {
		// Checks the slug, and adds a number if necessary to make it unique
		//   $slug -- the slug to be checked
		//   $slug_list -- a list of existing slugs
		// Returns the new slug
		// Duplicates have a two digit number appended to the end to make them unique
		// XXX do I neeed any messages about changing the slug?
		$numb = preg_match('/\d{2}$/', $slug, $match);

		while (in_array($slug, $slug_list)) {
			if ($numb) {
				$new_numb = sprintf("%02d", substr($slug, strlen($slug) - 2, 2) + 1);
				$slug = substr($slug, 0, strlen($slug) - 2) . $new_numb;
			} else {
				$slug .= '01';
				$numb = 1;
			}
		}

		return($slug);
	}

	static function clean_textarea($data) {
		// cleans blank lines and trims gaps from textarea list inputs
		// Returns the new data
		$new_data = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $data);
		$data_array = explode("\n", $new_data);
		$new_data = '';
		foreach ($data_array as $line) {
			$line = trim($line);
			if ($line != '')	// do not use !empty, or an option '0' is deleted
				$new_data .= "$line\n";
		}
		return trim($new_data);
	}

	static function set_fld_array() { // Set up the list of available tags for email
		self::get_options();
		self::$av_fld_arr = array();	// used to show available field tags this form
		self::$av_fld_subj_arr = array();	// used to show available field tags for this form subject
		// Fields
		foreach (self::$form_options['fields'] as $key => $field) {
			switch ($field['standard']) {
				case SWCF_NAME_FIELD :
					if ($field['disable'] == 'false') {
						switch (self::$form_options['name_format']) {
							case 'name':
								self::$av_fld_arr[] = 'from_name';
								break;
							case 'first_last':
								self::$av_fld_arr[] = 'first_name';
								self::$av_fld_arr[] = 'last_name';
								break;
							case 'first_middle_i_last':
								self::$av_fld_arr[] = 'first_name';
								self::$av_fld_arr[] = 'middle_initial';
								self::$av_fld_arr[] = 'last_name';
								break;
							case 'first_middle_last':
								self::$av_fld_arr[] = 'first_name';
								self::$av_fld_arr[] = 'middle_name';
								self::$av_fld_arr[] = 'last_name';
								break;
						}
					}
					break;

				case SWCF_EMAIL_FIELD :
					// email
					self::$autoresp_ok = 1; // used in autoresp settings below
					if ($field['disable'] == 'false') {
						self::$av_fld_arr[] = 'from_email';
					} else {
						self::$autoresp_ok = 0;
					}
					break;

				case SWCF_SUBJECT_FIELD :
					break;

				case SWCF_MESSAGE_FIELD :
					$msg_key = $key; // this is used below
					break;

				default :
					// This is an added field

					if ($field['type'] != 'fieldset-close' && $field['standard'] < 1) {
						if ($field['type'] == 'fieldset') {
							
						} else if ($field['type'] == 'attachment' && self::$form_options['php_mailer_enable'] == 'wordpress') {
							self::$av_fld_arr[] = $field['slug'];
						} else { // text, textarea, date, password, email, url, hidden, time, select, select-multiple, radio, checkbox, checkbox-multiple
							self::$av_fld_arr[] = $field['slug'];
							if ($field['type'] == 'email') { $autoresp_ok = 1; }
						}
					}
			} // end switch
		} // end foreach

		self::$av_fld_subj_arr = self::$av_fld_arr;
		self::$av_fld_arr[] = 'subject';
		if (self::$form_options['fields'][$msg_key]['disable'] == 'false') {
			self::$av_fld_arr[] = 'message';
		}
		self::$av_fld_arr[] = 'full_message';
		self::$av_fld_arr[] = 'date_time';
		self::$av_fld_arr[] = 'ip_address';
		self::$av_fld_subj_arr[] = 'form_label';
	}

	static function add_field() {
		check_admin_referer('sw_contact_options-options', 'fs_options');
		self::get_options();
		self::$form_options['fields'][] = SWEETCF_Utils::$field_defaults;
		self::$new_field_added = __('A new field has been added. Now you must edit the field name and details, then click <b>Save Changes</b>.', 'sweetcontact');
		echo '<div id="message" class="updated fade"><p>' . self::$new_field_added . '</p></div>';
	}

	static function add_form() {
		// Add a new form
		check_admin_referer('sw_contact_options-options', 'fs_options');

		if (!self::$global_options) { self::$global_options = SWEETCF_Utils::get_global_options(); }
		// Find the next form number. When forms are deleted, their form number is NOT reused
		self::$global_options['form_list'][self::$current_form] = __('New Form', 'sweetcontact');

		// Highest form ID (used to assign ID to new form)
		// When forms are deleted, the remaining forms are NOT renumberd, so max_form_num might be greater than the number of existing forms
		// recalibrate max_form_num to the highest form number (not count)
		ksort(self::$global_options['form_list']);
		self::$global_options['max_form_num'] = max(array_keys(self::$global_options['form_list']));
		update_option('sweetcontact_global', self::$global_options);
		echo '<div id="message" class="updated fade"><p>' . sprintf(__('Form %d has been added.', 'sweetcontact'), self::$current_form) . '</p></div>';

		return;
	}

	static function delete_form() { // Delete the current form
		check_admin_referer('sw_contact_options-options', 'fs_options');
		self::get_options();
		if (isset($_POST['form_num']) && is_numeric($_POST['form_num'])) {
			$form_num = absint($_POST['form_num']);
			$op_name = 'sweetcontact_form' . $form_num;
			$result = delete_option($op_name);
			if (!$result) {
				// Error deleting option
				echo '<div id="message" class="swcf-error fade"><p>' . sprintf(__('An error has occured.  Form %d could not be deleted.', 'sweetcontact'), $form_num) . '</p></div>';
			} else {
				unset(self::$global_options['form_list'][$form_num]);
				// Highest form ID (used to assign ID to new form)
				// When forms are deleted, the remaining forms are NOT renumberd, so max_form_num might be greater than
				// the number of existing forms
				ksort(self::$global_options['form_list']);
				self::$global_options['max_form_num'] = max(array_keys(self::$global_options['form_list']));
				update_option('sweetcontact_global', self::$global_options);
				echo '<div id="message" class="updated fade"><p>' . sprintf(__('Form %d has been deleted.', 'sweetcontact'), $form_num) . '</p></div>';
			}
		}
	}

}
?>