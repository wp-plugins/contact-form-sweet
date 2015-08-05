<?php

/**
 * Description of class-sweetcf-process
 * Process class for processing the contact form once it has been submitted
 * Functions are called statically, so no need to instantiate the class
 */
class SWEETCF_Process {

	static $global_options, $form_options, $form_id_num;
	static $form_data;	// The data from the form, used to re-populate the form if there are errors
	static $form_processed = false;
	static $form_errors = array(); // form entry errors
	static $uploaded_files;
	static $form_action_url;
	static $redirect_enable; // boolean: redirect after successful submit?
	static $meta_string;	// used for meta refresh
	static $email_msg, $email_msg_print, $php_eol, $selected_subject;
	static $email_header = array(); // Fields for the email header
	static $email_set_wp = array();	// used in mail send function
	static $email_fields; // this holds the fields to display in sending an email
	static $text_type_fields = array(
		'text',
		'textarea',
		'email',
		'password',
		'url'
	);
	static $select_type_fields = array(
		//'checkbox', // broke required
		'checkbox-multiple',
		'select',
		'select-multiple',
		'radio'
	);

	static function process_form() {
		// Invoked at init via add_action
		// Do we process one of our forms now?
		if (isset($_POST['sw_contact_action']) && ( 'send' == $_POST['sw_contact_action'] ) && isset($_POST['form_id']) && is_numeric($_POST['form_id'])) {
			self::$form_id_num = (int) $_POST['form_id'];
		} else { // Error: no form id in $_POST
			return;
		}
		// prevent double action
		if (self::$form_processed) {
			return;
		}
		// begin logic that redirects on forged form token.
		$token = 'ok';
		if (!isset($_POST['fs_postonce_' . self::$form_id_num]) || empty($_POST['fs_postonce_' . self::$form_id_num]) || strpos($_POST['fs_postonce_' . self::$form_id_num], ',') === false) {
			$token = 'bad';
		}
		$vars = explode(',', $_POST['fs_postonce_' . self::$form_id_num]);
		if (empty($vars[0]) || empty($vars[1]) || !preg_match("/^[0-9]+$/", $vars[1])) {
			$token = 'bad';
		}
		if (wp_hash($vars[1]) != $vars[0]) {
			$token = 'bad';
		}
		if ($token == 'bad') {
			// forgery token was no good,  so redirect and blank the form
			self::$form_action_url = SWEETCF_Display::get_form_action_url();
			wp_redirect(self::$form_action_url);
			exit;
		}

		if (!self::$global_options) {	self::$global_options = SWEETCF_Utils::get_global_options(); }
		
		self::$form_options = SWEETCF_Utils::get_form_options(self::$form_id_num, $use_defauilts = true);

		// Do some security checks
		self::check_security();

		self::validate_data();

		self::$form_processed = true;

		if (empty(self::$form_errors)) {
			// Send the email, cleanup attachments, redirect.
			self::prepare_email();
			if (self::$form_options['email_keep_attachments'] != 'true') {
				self::email_sent_cleanup_attachments();
			}
			self::email_sent_redirect(); // TODO
		}

		if (!empty(self::$uploaded_files)) {
			// unlink (delete) attachment temp files
			foreach ((array) self::$uploaded_files as $path) {
				@unlink($path);
			}
		}
	}

	static function check_security() {
		// check for various types of intrusion
		/* 		global $sweetcf_enable_ip_bans, $fsc_banned_ips;
		  // check for banned ip
		  if ( $sweetcf_enable_ip_bans && in_array( $_SERVER['REMOTE_ADDR'], $fsc_banned_ips ) )
		  wp_die( __( 'Your IP is Banned', 'sweetcontact' ) ); 
		*/
		$forbidden = self::spamcheckpost();
		if ($forbidden) {
			wp_die("$forbidden");
		}
	}

	static function validate_data() {
		// Sanitize and validate the data on the form
		self::$php_eol = (!defined('PHP_EOL')) ? (($eol = strtolower(substr(PHP_OS, 0, 3))) == 'win') ? "\r\n" : (($eol == 'mac') ? "\r" : "\n") : PHP_EOL;
		self::$php_eol = (!self::$php_eol) ? "\n" : self::$php_eol;
		self::$form_action_url = SWEETCF_Display::get_form_action_url();

		$special_slugs = array('f_name', 'm_name', 'mi_name', 'l_name', 'email2', 'mailto_id');
		foreach ($special_slugs as $special) {
			if (isset($_POST[$special])) {
				// Check for newline injection attempts
				self::forbidifnewlines($_POST[$special]);
				self::$form_data[$special] = SWEETCF_Utils::clean_input($_POST[$special]);
			}
		}

		// Get the email-to contact
		$cid = self::$form_data['mailto_id'];
		if (empty($cid)) {
			self::$form_errors['contact'] = ( self::$form_options['error_contact_select'] != '') ? self::$form_options['error_contact_select'] : __('Selecting a contact is required.', 'sweetcontact');
		} else {
			$frm_id = self::$form_id_num;
			$contacts = SWEETCF_Options::get_contact_list();
			$contact = ( isset($contacts[0])) ? $contacts[0] : false;
			if (!isset($contact['CONTACT'])) {
				self::$form_errors['contact'] = __('Requested Contact not found.', 'sweetcontact');
			}
		}
		// Setup the email and contact name for email
		self::$email_fields['email_to'] = ( isset($contact['EMAIL']) ) ? SWEETCF_Utils::clean_input($contact['EMAIL']) : '';
		self::$email_fields['name_to'] = ( isset($contact['CONTACT']) ) ? SWEETCF_Utils::clean_input($contact['CONTACT']) : '';

		// some people want labels and fields inline, some want the fields on new line
		$inline_or_newline = self::$php_eol;
		if (self::$form_options['email_inline_label'] == 'true') { $inline_or_newline = ' '; }

		self::$email_fields['name_to'] = str_replace('&#39;', "'", self::$email_fields['name_to']);
		self::$email_fields['name_to'] = str_replace('&quot;', '"', self::$email_fields['name_to']);
		self::$email_fields['name_to'] = str_replace('&amp;', '&', self::$email_fields['name_to']);
		self::$email_msg = self::make_bold(__('To:', 'sweetcontact')) . $inline_or_newline . self::$email_fields['name_to'] . self::$php_eol . self::$php_eol;

		$fields_in_use = array();
		foreach (self::$form_options['fields'] as $key => $field) {
			if ('true' == $field['disable'] || 'fieldset-close' == $field['type']) { continue; }
			$fields_in_use[$field['slug']] = 1;
			if ('fieldset' == $field['type']) {
				self::$email_msg .= self::make_bold($field['label']) . $inline_or_newline;
				continue;
			}

			// Check for newline injection attempts
			if (in_array($field['type'], self::$text_type_fields) && $field['type'] != 'textarea') {
				if (!empty($_POST[$field['slug']])) {
					self::forbidifnewlines($_POST[$field['slug']]);
				}
			}

			// Add sanitized data from POST to the form data array
			if (isset($_POST[$field['slug']])) {
				if ('textarea' == $field['type'] && 'true' == self::$form_options['textarea_html_allow']) {
					self::$form_data[$field['slug']] = wp_kses_data(stripslashes($_POST[$field['slug']])); // allow only some safe html
				} else {
					self::$form_data[$field['slug']] = SWEETCF_Utils::clean_input($_POST[$field['slug']]);
				}
			}
			// Set up values for unchecked checkboxes and unselected radio types
			else if ('checkbox' == $field['type'] || 'radio' == $field['type']) {
				self::$form_data[$field['slug']] = '';
			} else if ('checkbox-multiple' == $field['type']) {
				self::$form_data[$field['slug']] = array();
			}

			if (in_array($field['type'], self::$select_type_fields)) {
				//if ( 'checkbox' != $field['type'] ) {
				// select, select-multiple, checkbox-multiple require at least one item to be selected
				if ('subject' == $field['slug'] && 'select' == $field['type']) {
					self::$selected_subject = self::validate_subject_select($field);
				} else if ('select' == $field['type']) {
					self::validate_select($field['slug'], $field);
				} else if ('true' == $field['req']) {
					if (!isset($_POST[$field['slug']])) {
						self::$form_errors[$field['slug']] = (self::$form_options['error_select'] != '') ? self::$form_options['error_select'] : __('At least one item in this field is required.', 'sweetcontact');
					}
				}
				//}
			} else if ('hidden' != $field['type'] && 'attachment' != $field['type']) {
				if ('true' == $field['placeholder'] && $field['default'] != '' && isset($_POST[$field['slug']])) {
					// strip out the placeholder they posted with
					$examine_placeholder_input = stripslashes($_POST[$field['slug']]);
					if ($field['default'] == $examine_placeholder_input) {
						$_POST[$field['slug']] = '';
					}
				}
				// The name and email fields are validated separately
				if ('full_name' == $field['slug']) {
					self::validate_name($field, $inline_or_newline);
				} else if ('email' == $field['slug']) {
					self::validate_email($field['req'], $inline_or_newline);
				} else if ('email' == $field['type']) { // extra field email type
					self::validate_email_type($field['slug'], $field['req']);
				} else if ('url' == $field['type']) { // extra field email type
					self::validate_url_type($field['slug'], $field['req']);
				} else if ('true' == $field['req'] && $_POST[$field['slug']] == '') {
					self::$form_errors[$field['slug']] = ( self::$form_options['error_field'] != '') ? self::$form_options['error_field'] : __('This field is required.', 'sweetcontact');
				}
			}

			// Max len validate (text type fields, and date?)
			if (in_array($field['type'], self::$text_type_fields) && $field['max_len'] != '' && strlen($_POST[$field['slug']]) > $field['max_len']) {
				self::$form_errors[$field['slug']] = sprintf(( self::$form_options['error_maxlen'] != '') ? self::$form_options['error_maxlen'] : __('Maximum of %d characters exceeded.', 'sweetcontact'), $field['max_len']);
			}

			// Regex validate (not for hidden, checkbox/m, select/m, radio)
			if (!in_array($field['type'], self::$select_type_fields) && 'hidden' != $field['type'] && 'checkbox' != $field['type'] && $field['regex'] != '') {
				if ('true' == $field['req'] && empty($_POST[$field['slug']])) {
					self::$form_errors[$field['slug']] = ( self::$form_options['error_field'] != '') ? self::$form_options['error_field'] : __('This field is required.', 'sweetcontact');
				} else if (!empty($_POST[$field['slug']]) && !preg_match($field['regex'], $_POST[$field['slug']])) {
					self::$form_errors[$field['slug']] = ($field['regex_error'] != '') ? $field['regex_error'] : __('Invalid input.', 'sweetcontact');
				}
			}

			// filter hook for form input validation
			self::$form_errors = apply_filters('sweetcontact_form_validate', self::$form_errors, self::$form_id_num);

			switch ($field['type']) {
				case 'text' :
				case 'email':
				case 'hidden':
				case 'textarea' :
				case 'password' :
				case 'url' :
					if ('full_name' != $field['slug'] && 'email' != $field['slug']) {
						if (self::$form_data[$field['slug']] == '' && self::$form_options['email_hide_empty'] == 'true') {
							
						} else {
							if ('subject' == $field['slug']) {
								$this_label = (self::$form_options['title_subj'] != '') ? self::$form_options['title_subj'] : __('Subject:', 'sweetcontact');
								self::$email_msg .= self::make_bold($this_label) . $inline_or_newline;
							} elseif ('message' == $field['slug']) {
								$this_label = (self::$form_options['title_mess'] != '') ? self::$form_options['title_mess'] : __('Message:', 'sweetcontact');
								self::$email_msg .= self::make_bold($this_label) . $inline_or_newline;
							} else {
								self::$email_msg .= self::make_bold($field['label']) . $inline_or_newline;
							}
							self::$email_fields[$field['slug']] = self::$form_data[$field['slug']];
							self::$email_msg .= self::$form_data[$field['slug']] . self::$php_eol . self::$php_eol;
						}
					}
					break;

				case 'checkbox' :
					if (empty(self::$form_data[$field['slug']]) && self::$form_options['email_hide_empty'] == 'true') {
						
					} else {
						if ('1' == self::$form_data[$field['slug']]) {
							self::$email_msg .= self::make_bold($field['label']) . $inline_or_newline;
							//self::$email_fields[$field['slug']] = '* '.__('selected', 'sweetcontact');
							self::$email_fields[$field['slug']] = __('selected', 'sweetcontact');
							self::$email_msg .= self::$email_fields[$field['slug']] . self::$php_eol . self::$php_eol;
						}
					}
					break;

				case 'radio' :
					// the response is the number of a single option
					// Get the options list
					$opts_array = explode("\n", $field['options']);
					if ('' == $opts_array[0] && 'checkbox' == $field['type'])
						$opts_array[0] = $field['label']; // use the field name as the option name
					if (!isset($opts_array[self::$form_data[$field['slug']] - 1]) && self::$form_options['email_hide_empty'] == 'true') {
						
					} else {
						if (isset($opts_array[self::$form_data[$field['slug']] - 1])) {
							self::$email_msg .= self::make_bold($field['label']) . $inline_or_newline;
							//self::$email_fields[$field['slug']] = ' * ' . $opts_array[self::$form_data[$field['slug']]-1];
							self::$email_fields[$field['slug']] = $opts_array[self::$form_data[$field['slug']] - 1];
							// is this key==value set? use the key
							if (preg_match('/^(.*)(==)(.*)$/', self::$email_fields[$field['slug']], $matches)) {
								self::$email_fields[$field['slug']] = $matches[1];
							}
							self::$email_msg .= self::$email_fields[$field['slug']] . self::$php_eol . self::$php_eol;
						}
					}
					break;

				case 'select' :
					$chosen = '';
					if ('subject' == $field['slug'] && 'select' == $field['type']) {
						$chosen = self::$selected_subject;
					} else {
						// response(s) are in an array
						// was anything selected?
						if (!empty(self::$form_data[$field['slug']])) {
							$opts_array = explode("\n", $field['options']);
							if (preg_match('/^\[.*]$/', trim($opts_array[0]))) // "[Please select]"
								unset($opts_array[0]);
							else
								$opts_array = array_combine(range(1, count($opts_array)), array_values($opts_array));
							foreach ($opts_array as $k => $v) {
								if (in_array($k, self::$form_data[$field['slug']])) {
									// is this key==value set? use the key
									if (preg_match('/^(.*)(==)(.*)$/', $v, $matches))
										$v = $matches[1];
									$chosen .= $v; // only one should be selected
								}
							}
						}
					}
					if ($chosen == '' && self::$form_options['email_hide_empty'] == 'true') {
						
					} else {
						if ('subject' == $field['slug'] && 'select' == $field['type']) {
							$this_label = (self::$form_options['title_subj'] != '') ? self::$form_options['title_subj'] : __('Subject:', 'sweetcontact');
							self::$email_msg .= self::make_bold($this_label) . $inline_or_newline;
						} else {
							self::$email_msg .= self::make_bold($field['label']) . $inline_or_newline;
						}
						self::$email_fields[$field['slug']] = $chosen;
						self::$email_msg .= $chosen . self::$php_eol . self::$php_eol;
					}
					break;
				case 'select-multiple' :
				case 'checkbox-multiple' :
					// response(s) are in an array
					$chosen = '';
					// was anything selected?
					if (!empty(self::$form_data[$field['slug']])) {
						$opts_array = explode("\n", $field['options']);
						if (count(self::$form_data[$field['slug']]) > 1) { // prefix with ' * ' for multiple selections
							foreach ($opts_array as $k => $v) {
								if (in_array($k + 1, self::$form_data[$field['slug']])) {
									// is this key==value set? use the key
									if (preg_match('/^(.*)(==)(.*)$/', $v, $matches))
										$v = $matches[1];
									$chosen .= ' * ' . $v;
								}
							}
						} else {
							foreach ($opts_array as $k => $v) { // no prefix ' * ' on single selections
								if (in_array($k + 1, self::$form_data[$field['slug']])) {
									// is this key==value set? use the key
									if (preg_match('/^(.*)(==)(.*)$/', $v, $matches))
										$v = $matches[1];
									$chosen .= $v;
								}
							}
						}
					}
					if ($chosen == '' && self::$form_options['email_hide_empty'] == 'true') {
						
					} else {
						self::$email_msg .= self::make_bold($field['label']) . $inline_or_newline;
						self::$email_fields[$field['slug']] = $chosen;
						self::$email_msg .= $chosen . self::$php_eol . self::$php_eol;
					}
					break;

				case 'date' :
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
					$not_chosen = 0;
					if ('true' != $field['req'] && ( $cal_date_array[self::$form_options['date_format']] == $_POST[$field['slug']] || empty($_POST[$field['slug']]) )) { // not required, no date picked
						// this field wasn't set to required, no date picked, skip it
						$not_chosen = 1;
					} else if (!self::validate_date(self::$form_data[$field['slug']], self::$form_id_num)) { // picked a date
						self::$form_errors[$field['slug']] = sprintf((self::$form_options['error_date'] != '') ? self::$form_options['error_date'] : __('Please select a valid date in this format: %s.', 'sweetcontact'), $cal_date_array[self::$form_options['date_format']]);
					} else {
						if ($not_chosen && self::$form_options['email_hide_empty'] == 'true') {
							
						} else {
							self::$email_msg .= self::make_bold($field['label']) . $inline_or_newline;
							self::$email_fields[$field['slug']] = self::$form_data[$field['slug']];
							self::$email_msg .= self::$form_data[$field['slug']] . self::$php_eol . self::$php_eol;
						}
					}
					break;

				case 'time' :
					$not_chosen = 0;
					if (self::$form_options['time_format'] == '12') {
						$concat_time = self::$form_data[$field['slug']]['h'] . ':' . self::$form_data[$field['slug']]['m'] . ' ' . self::$form_data[$field['slug']]['ap'];
						if ('true' != $field['req'] && ( empty(self::$form_data[$field['slug']]['h']) && empty(self::$form_data[$field['slug']]['m']) && empty(self::$form_data[$field['slug']]['ap']) )) { // not required, no time picked
							// this field wasn't set to required, no times picked, skip it
							$not_chosen = 1;
							$concat_time = '';
						} else if ('true' != $field['req'] && !self::validate_time_ap(self::$form_data[$field['slug']]['h'], self::$form_data[$field['slug']]['m'], self::$form_data[$field['slug']]['ap'])) { // selection is incomplete
							self::$form_errors[$field['slug']] = ( self::$form_options['error_time'] != '') ? self::$form_options['error_time'] : __('The time selections are incomplete, select all or none.', 'sweetcontact');
						} else if ('true' == $field['req'] && (!preg_match("/^[0-9]{2}$/", self::$form_data[$field['slug']]['h']) || !preg_match("/^[0-9]{2}$/", self::$form_data[$field['slug']]['m']) || empty(self::$form_data[$field['slug']]['ap']) )) { // not picked a time
							self::$form_errors[$field['slug']] = ( self::$form_options['error_field'] != '') ? self::$form_options['error_field'] : __('This field is required.', 'sweetcontact');
						}
					} else {
						// 24 hour format with no am/pm select field
						$concat_time = self::$form_data[$field['slug']]['h'] . ':' . self::$form_data[$field['slug']]['m'];
						if ('true' != $field['req'] && ( empty(self::$form_data[$field['slug']]['h']) && empty(self::$form_data[$field['slug']]['m']) )) { // not required, no time picked
							// this field wasn't set to required, no times picked, skip it
							$not_chosen = 1;
							$concat_time = '';
						} else if ('true' != $field['req'] && !self::validate_time(self::$form_data[$field['slug']]['h'], self::$form_data[$field['slug']]['m'])) { // selection is incomplete
							self::$form_errors[$field['slug']] = ( self::$form_options['error_time'] != '') ? self::$form_options['error_time'] : __('The time selections are incomplete, select all or none.', 'sweetcontact');
						} else if ('true' == $field['req'] && (!preg_match("/^[0-9]{2}$/", self::$form_data[$field['slug']]['h']) || !preg_match("/^[0-9]{2}$/", self::$form_data[$field['slug']]['m']) )) { // not picked a time
							self::$form_errors[$field['slug']] = ( self::$form_options['error_field'] != '') ? self::$form_options['error_field'] : __('This field is required.', 'sweetcontact');
						}
					}
					if ($not_chosen && self::$form_options['email_hide_empty'] == 'true') {
						
					} else {
						self::$email_msg .= self::make_bold($field['label']) . $inline_or_newline;
						self::$email_fields[$field['slug']] = $concat_time;
						self::$email_msg .= $concat_time . self::$php_eol . self::$php_eol;
					}
					break;

				case 'attachment' :
					self::validate_attach($field['slug'], $field['req'], $field['label'], $inline_or_newline);
					break;

				default :
			}
		}
		// Add any hidden fields added by shortcodes
		// Used only for sending email. If the form is reloaded, hidden fields will be added from the shortcode.
		$frm_id = self::$form_id_num;
		if (self::$global_options['enable_php_sessions'] == 'true' && !empty($_SESSION["fsc_shortcode_hidden_$frm_id"])) {
			$hidden_fields = $_SESSION["fsc_shortcode_hidden_$frm_id"];
			foreach ($hidden_fields as $key => $value) {
				if ($key != '' && $value != '') {
					if ($key == 'form_page') { // page url
						self::$email_msg .= self::make_bold(__('Form Page', 'sweetcontact')) . $inline_or_newline . esc_url(self::$form_action_url) . self::$php_eol . self::$php_eol;
						self::$email_fields['form_page'] = esc_url(self::$form_action_url);
					} else {
						self::$email_msg .= self::make_bold($key) . $inline_or_newline . stripslashes($value) . self::$php_eol . self::$php_eol;
						self::$email_fields[$key] = $value;
					}
				}
			}
		}

		// filter hook to add any custom fields to email_fields array (not validated)
		self::$email_fields = apply_filters('sw_contact_email_fields', self::$email_fields, self::$form_id_num);
		// filter hook to add any custom fields to email message (not validated)
		self::$email_msg = apply_filters('sw_contact_email_msg', self::$email_msg, $inline_or_newline, self::$php_eol, self::$form_id_num);
		if (self::$form_options['print_form_enable'] == 'true') {
			self::$email_msg_print = self::$email_msg;
			//self::$email_msg_print .= self::make_bold( 'Time:' ) . $inline_or_newline;
			//self::$email_msg_print .= date_i18n(get_option('date_format').' '.get_option('time_format'), time() );
		}
		self::$email_fields['date_time'] = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), time());
		self::$email_fields['ip_address'] = (isset($_SERVER['REMOTE_ADDR'])) ? $_SERVER['REMOTE_ADDR'] : 'n/a';
		
		self::check_captcha();

		// check honeypot, if enabled
		if (self::$form_options['honeypot_enable'] == 'true' && !isset(self::$form_errors['captcha'])) {
			$honeypot_slug = SWEETCF_Display::get_todays_honeypot_slug($fields_in_use);
			if (!empty($_POST[$honeypot_slug])) {
				self::$form_errors[$honeypot_slug] = (self::$form_options['error_spambot'] != '') ? self::$form_options['error_spambot'] : __('Possible spam bot. Try again.', 'sweetcontact');
			}
		}

		if (self::$form_options['sender_info_enable'] == 'true') {
			self::$email_msg .= self::get_user_info(); // adds sender info to email
		}

		// filter hook for modifying the complete email message
		self::$email_msg = apply_filters('sw_contact_email_message', self::$email_msg, self::$email_fields, $inline_or_newline, self::$php_eol, self::$form_id_num);

		return;
	}

	static function validate_time($hr, $min) {
		// 24 hour format with no am/pm select field
		// Checks time input to find out if time was selectors were selected but incomplete
		// was all time inputs selected?
		if (preg_match("/^[0-9]{2}$/", $hr) && preg_match("/^[0-9]{2}$/", $min))
			return true;

		// were none time inputs not selected
		if (!preg_match("/^[0-9]{2}$/", $hr) && !preg_match("/^[0-9]{2}$/", $min))
			return true;

		// only some were selected, but not all
		return false;
	}

	static function validate_time_ap($hr, $min, $ap) {
		// 12 hour format with am/pm select field
		// Checks time input to find out if time was selectors were selected but incomplete
		// was all time inputs selected?
		if (preg_match("/^[0-9]{2}$/", $hr) && preg_match("/^[0-9]{2}$/", $min) && !empty($ap))
			return true;

		// were none time inputs not selected
		if (!preg_match("/^[0-9]{2}$/", $hr) && !preg_match("/^[0-9]{2}$/", $min) && empty($ap))
			return true;

		// only some were selected, but not all
		return false;
	}

	static function validate_date($input, $form_id_num) {
		// Checks date input for proper formatting of actual calendar dates
		// Matches the date format and also validates month and number of days in a month.
		// All leap year dates allowed.

		if (!self::$form_options)
			self::$form_options = SWEETCF_Utils::get_form_options($form_id_num, $use_defaults = true);

		$date_format = self::$form_options['date_format'];
		// find the delimiter of the date_format setting: slash, dash, or dot
		if (strpos($date_format, '/')) {
			$delim = '/';
			$regexdelim = '\/';
		} else if (strpos($date_format, '-')) {
			$delim = '-';
			$regexdelim = '-';
		} else if (strpos($date_format, '.')) {
			$delim = '.';
			$regexdelim = '\.';
		}

		if ($date_format == "mm${delim}dd${delim}yyyy")
			$regex = "/^(((0[13578]|(10|12))${regexdelim}(0[1-9]|[1-2][0-9]|3[0-1]))|(02${regexdelim}(0[1-9]|[1-2][0-9]))|((0[469]|11)${regexdelim}(0[1-9]|[1-2][0-9]|30)))${regexdelim}[0-9]{4}$/";

		if ($date_format == "dd${delim}mm${delim}yyyy")
			$regex = "/^(((0[1-9]|[1-2][0-9]|3[0-1])${regexdelim}(0[13578]|(10|12)))|((0[1-9]|[1-2][0-9])${regexdelim}02)|((0[1-9]|[1-2][0-9]|30)${regexdelim}(0[469]|11)))${regexdelim}[0-9]{4}$/";

		if ($date_format == "yyyy${delim}mm${delim}dd")
			$regex = "/^[0-9]{4}${regexdelim}(((0[13578]|(10|12))${regexdelim}(0[1-9]|[1-2][0-9]|3[0-1]))|(02${regexdelim}(0[1-9]|[1-2][0-9]))|((0[469]|11)${regexdelim}(0[1-9]|[1-2][0-9]|30)))$/";

		if (!preg_match($regex, $input))
			return false;
		else
			return true;
	}

	static function validate_name($field, $inline_or_newline) {
		// validates all the standard name inputs
		// The name components are already sanitized and stored in self::$form_data

		$placeh_name_fail = $placeh_fname_fail = $placeh_lname_fail = $placeh_mname_fail = $placeh_miname_fail = 0;

		// If the name is required, make sure it is there
		if ('true' == $field['req']) {
			switch (self::$form_options['name_format']) {
				case 'name':
					if ('' == self::$form_data['full_name'] || $placeh_name_fail) {
						self::$form_errors['full_name'] = (self::$form_options['error_name'] != '') ? self::$form_options['error_name'] : __('Your name is required.', 'sweetcontact');
						if ($placeh_name_fail) { self::$form_data['full_name'] = $field['default']; }
					}
					break;
				default:
					// middle initial is allowed to be empty
					if (empty(self::$form_data['f_name']) || $placeh_fname_fail) {
						self::$form_errors['f_name'] = (self::$form_options['error_name'] != '') ? self::$form_options['error_name'] : __('Your name is required.', 'sweetcontact');
						if ($placeh_fname_fail) { self::$form_data['f_name'] = $f_default; }
					}
					if (empty(self::$form_data['l_name']) || $placeh_lname_fail) {
						self::$form_errors['l_name'] = (self::$form_options['error_name'] != '') ? self::$form_options['error_name'] : __('Your name is required.', 'sweetcontact');
						if ($placeh_lname_fail) { self::$form_data['l_name'] = $l_default; }
					}
					if (self::$form_options['name_format'] == 'first_middle_last') {
						if ($placeh_mname_fail) { self::$form_data['m_name'] = $m_default; }
					}
					if (self::$form_options['name_format'] == 'first_middle_i_last') {
						if ($placeh_miname_fail) { self::$form_data['mi_name'] = $mi_default; }
					}
			}
		}

		// If necessary, adjust the name case
		foreach (array('full_name', 'f_name', 'm_name', 'l_name') as $fld) {
			if (!empty(self::$form_data[$fld]))
				self::$form_data[$fld] = SWEETCF_Utils::name_case(self::$form_data[$fld]);
		}

		// Add the name to the email message
		switch (self::$form_options['name_format']) {
			case 'name':
				if (self::$form_data['full_name'] == '' && self::$form_options['email_hide_empty'] == 'true') {
					
				} else {
					$this_label = (self::$form_options['title_name'] != '') ? self::$form_options['title_name'] : __('Name:', 'sweetcontact');
					self::$email_msg .= self::make_bold($this_label) . $inline_or_newline;
					self::$email_msg .= self::$form_data['full_name'] . self::$php_eol . self::$php_eol;
				}
				break;
			case 'first_last':
				self::$email_msg .= (self::$form_options['title_fname'] != '') ? self::$form_options['title_fname'] : __('First Name:', 'sweetcontact');
				self::$email_msg .= ' ' . self::$form_data['f_name'] . self::$php_eol;
				self::$email_msg .= (self::$form_options['title_lname'] != '') ? self::$form_options['title_lname'] : __('Last Name:', 'sweetcontact');
				self::$email_msg .= ' ' . self::$form_data['l_name'] . self::$php_eol . self::$php_eol;
				self::$email_fields['first_name'] = self::$form_data['f_name'];
				self::$email_fields['last_name'] = self::$form_data['l_name'];
				break;
			case 'first_middle_i_last':
				self::$email_msg .= (self::$form_options['title_fname'] != '') ? self::$form_options['title_fname'] : __('First Name:', 'sweetcontact');
				self::$email_msg .= ' ' . self::$form_data['f_name'] . self::$php_eol;
				if (self::$form_data['mi_name'] != '' && !$placeh_miname_fail) {
					self::$email_msg .= (self::$form_options['title_miname'] != '') ? self::$form_options['title_miname'] : __('Middle Initial:', 'sweetcontact');
					self::$email_msg .= ' ' . self::$form_data['mi_name'] . self::$php_eol;
				}
				self::$email_msg .= (self::$form_options['title_lname'] != '') ? self::$form_options['title_lname'] : __('Last Name:', 'sweetcontact');
				self::$email_msg .= ' ' . self::$form_data['l_name'] . self::$php_eol . self::$php_eol;
				break;
			case 'first_middle_last':
				self::$email_msg .= (self::$form_options['title_fname'] != '') ? self::$form_options['title_fname'] : __('First Name:', 'sweetcontact');
				self::$email_msg .= ' ' . self::$form_data['f_name'] . self::$php_eol;
				if (self::$form_data['m_name'] != '' && !$placeh_mname_fail) {
					self::$email_msg .= (self::$form_options['title_mname'] != '') ? self::$form_options['title_mname'] : __('Middle Name:', 'sweetcontact');
					self::$email_msg .= ' ' . self::$form_data['m_name'] . self::$php_eol;
				}
				self::$email_msg .= (self::$form_options['title_lname'] != '') ? self::$form_options['title_lname'] : __('Last Name:', 'sweetcontact');
				self::$email_msg .= ' ' . self::$form_data['l_name'] . self::$php_eol . self::$php_eol;
		}

		// Build the name string for the email
		self::$email_fields['from_name'] = '';
		if (!empty(self::$form_data['full_name']))
			self::$email_fields['from_name'] .= self::$form_data['full_name'];
		if (!empty(self::$form_data['f_name']))
			self::$email_fields['from_name'] .= self::$form_data['f_name'];
		if (!empty(self::$form_data['mi_name']))
			self::$email_fields['from_name'] .= ' ' . self::$form_data['mi_name'];
		if (!empty(self::$form_data['m_name']))
			self::$email_fields['from_name'] .= ' ' . self::$form_data['m_name'];
		if (!empty(self::$form_data['l_name']))
			self::$email_fields['from_name'] .= ' ' . self::$form_data['l_name'];
	}

	static function validate_email($req, $inline_or_newline) {
		// validates all the standard email inputs
		if (isset($_POST['email'])) { $email = strtolower(SWEETCF_Utils::clean_input($_POST['email'])); }
		if ('true' == self::$form_options['double_email']) {
			$req = 'true';
			if (isset($_POST['email2'])) { $email2 = strtolower(SWEETCF_Utils::clean_input($_POST['email2'])); }
		}

		if ('true' == $req) {
			if (!SWEETCF_Utils::validate_email(self::$form_data['email'])) {
				self::$form_errors['email'] = (self::$form_options['error_email'] != '') ? self::$form_options['error_email'] : __('A proper email address is required.', 'sweetcontact');
			}
			if ('true' == self::$form_options['double_email'] && !SWEETCF_Utils::validate_email($email2)) {
				self::$form_errors['email2'] = (self::$form_options['error_email'] != '') ? self::$form_options['error_email'] : __('A proper email address is required.', 'sweetcontact');
			}
			if ('true' == self::$form_options['double_email'] && !empty($email) && !empty($email2) && ($email != $email2)) {
				self::$form_errors['email2'] = (self::$form_options['error_email2'] != '') ? self::$form_options['error_email2'] : __('The two email addresses did not match.', 'sweetcontact');
			}
		}
		if (empty($email) && self::$form_options['email_hide_empty'] == 'true') {
			
		} else {
			$this_label = (self::$form_options['title_email'] != '') ? self::$form_options['title_email'] : __('Email:', 'sweetcontact');
			self::$email_msg .= self::make_bold($this_label) . $inline_or_newline;
			self::$email_fields['from_email'] = self::$form_data['email'];
			self::$email_msg .= self::$email_fields['from_email'] . self::$php_eol . self::$php_eol;
		}
	}

	static function validate_email_type($slug, $req) {
		// validates extra field type that is email
		if ('true' == $req) {
			if (!SWEETCF_Utils::validate_email(self::$form_data[$slug])) {
				self::$form_errors[$slug] = (self::$form_options['error_email'] != '') ? self::$form_options['error_email'] : __('A proper email address is required.', 'sweetcontact');
			}
		} else if (!empty(self::$form_data[$slug])) {
			if (!SWEETCF_Utils::validate_email(self::$form_data[$slug])) // was not required but something filled it, so ckeck
				self::$form_errors[$slug] = (self::$form_options['error_email_check'] != '') ? self::$form_options['error_email_check'] : __('Not a proper email address.', 'sweetcontact');
		}
	}

	static function validate_url_type($slug, $req) {
		// validates extra fiedld type that is url
		if ('true' == $req) {
			if (!SWEETCF_Utils::validate_url(self::$form_data[$slug])) {
				self::$form_errors[$slug] = (self::$form_options['error_url'] != '') ? self::$form_options['error_url'] : __('Invalid URL.', 'sweetcontact');
			}
		} else if (!empty(self::$form_data[$slug])) {
			if (!SWEETCF_Utils::validate_url(self::$form_data[$slug])) { // was not required but something filled it, so ckeck
				self::$form_errors[$slug] = (self::$form_options['error_url'] != '') ? self::$form_options['error_url'] : __('Invalid URL.', 'sweetcontact');
			}
		}
	}

	static function validate_subject_select($field) {
		// validates subject type that is select
		// response(s) are in an array
		$sid = self::$form_data['subject'][0];
		$opts_array = explode("\n", $field['options']);
		if (preg_match('/^\[.*]$/', trim($opts_array[0]))) // "[Please select]"
			unset($opts_array[0]);
		else
			$opts_array = array_combine(range(1, count($opts_array)), array_values($opts_array)); //0 key becomes 1
		if (empty($sid)) {
			self::$form_errors['subject'] = (self::$form_options['error_subject'] != '') ? self::$form_options['error_subject'] : __('Selecting a subject is required.', 'sweetcontact');
		} else if (empty($opts_array) || !isset($opts_array[$sid])) {
			self::$form_errors['subject'] = __('Requested subject not found.', 'sweetcontact');
		} else {
			return $opts_array[$sid];
		}
	}

	static function validate_select($slug, $field) {
		// validates extra field type that is select
		// response(s) are in an array
		$sid = self::$form_data[$slug][0];
		$opts_array = explode("\n", $field['options']);
		if (preg_match('/^\[.*]$/', trim($opts_array[0]))) { // "[Please select]"
			unset($opts_array[0]);
		} else {
			$opts_array = array_combine(range(1, count($opts_array)), array_values($opts_array)); //0 key becomes 1
		}
		if ('true' == $field['req']) {
			if (empty($sid)) {
				self::$form_errors[$slug] = ( self::$form_options['error_field'] != '') ? self::$form_options['error_field'] : __('This field is required.', 'sweetcontact');
			} else if (empty($opts_array) || !isset($opts_array[$sid])) {
				self::$form_errors[$slug] = ( self::$form_options['error_field'] != '') ? self::$form_options['error_field'] : __('This field is required.', 'sweetcontact');
			}
		}
	}

	static function validate_attach($slug, $req, $label, $inline_or_newline) {
		// validates and saves uploaded file attchments for file attach field types.
		// also sets errors if the file did not upload or was not accepted.	
		// Test if a file was selected for attach.
		$field_file['name'] = '';
		if (isset($_FILES[$slug])) {
			$field_file = $_FILES[$slug];
		}

		if ('true' == $req && empty($field_file['name'])) {
			self::$form_errors[$slug] = ( self::$form_options['error_field'] != '') ? self::$form_options['error_field'] : __('This field is required.', 'sweetcontact');
			return;
		}
		if ($field_file['name'] != '') { // may not be required
			if (self::$form_options['php_mailer_enable'] == 'php') {
				self::$form_errors[$slug] = __('Attachments not supported.', 'sweetcontact');
				return;
			} else if (($field_file['error'] && UPLOAD_ERR_NO_FILE != $field_file['error']) || !is_uploaded_file($field_file['tmp_name'])) {
				self::$form_errors[$slug] = __('Attachment upload failed.', 'sweetcontact');
				return;
			} else if (empty($field_file['tmp_name'])) {
				self::$form_errors[$slug] = ( self::$form_options['error_field'] != '') ? self::$form_options['error_field'] : __('This field is required.', 'sweetcontact');
				return;
			} else {

				// check file types
				$file_type_pattern = self::$form_options['attach_types'];
				if ($file_type_pattern == '')
					$file_type_pattern = 'doc,docx,pdf,txt,gif,jpg,jpeg,png';
				$file_type_pattern = str_replace(',', '|', self::$form_options['attach_types']);
				$file_type_pattern = str_replace(' ', '', $file_type_pattern);
				$file_type_pattern = trim($file_type_pattern, '|');
				$file_type_pattern = '(' . $file_type_pattern . ')';
				$file_type_pattern = '/\.' . $file_type_pattern . '$/i';

				if (!preg_match($file_type_pattern, $field_file['name'])) {
					self::$form_errors[$slug] = __('Attachment file type not allowed.', 'sweetcontact');
					return;
				}

				// check size
				$allowed_size = 1048576; // 1mb default
				if (preg_match('/^([[0-9.]+)([kKmM]?[bB])?$/', self::$form_options['attach_size'], $matches)) {
					$allowed_size = (int) $matches[1];
					$kbmb = strtolower($matches[2]);
					if ('kb' == $kbmb) {
						$allowed_size *= 1024;
					} elseif ('mb' == $kbmb) {
						$allowed_size *= 1024 * 1024;
					}
				}
				if ($field_file['size'] > $allowed_size) {
					self::$form_errors[$slug] = __('Attachment file size is too large.', 'sweetcontact');
					return;
				}

				$filename = $field_file['name'];

				// safer file names for scripts.
				if (preg_match('/\.(php|pl|py|rb|js|cgi)\d?$/', $filename))
					$filename .= '.txt';

				$filename = wp_unique_filename(SWCF_ATTACH_DIR, $filename);
				$new_file = trailingslashit(SWCF_ATTACH_DIR) . $filename;

				if (false === @move_uploaded_file($field_file['tmp_name'], $new_file)) {
					self::$form_errors[$slug] = __('Attachment upload failed while moving file.', 'sweetcontact');
					return;
				}

				// uploaded only readable for the owner process
				@chmod($new_file, 0400);
				self::$uploaded_files[$slug] = $new_file;

				self::$email_msg .= self::make_bold($label) . $inline_or_newline;
				self::$email_fields[$slug] = __('File is attached:', 'sweetcontact') . ' ' . $filename;
				self::$email_msg .= ' ' . self::$email_fields[$slug] . self::$php_eol . self::$php_eol;
			} // end else (no errors)
		} else {
			if (self::$form_options['email_hide_empty'] == 'true') {
				
			} else {
				// no file was attached, and it was not required
				self::$email_msg .= self::make_bold($label) . $inline_or_newline;
				self::$email_fields[$slug] = __('No file attached', 'sweetcontact');
				self::$email_msg .= ' ' . __('No file attached', 'sweetcontact') . self::$php_eol . self::$php_eol;
			}
		}
	}

	static function get_user_info() {
		// Gathers user info to include in the email message
		// Returns the user info string
		global $current_user, $user_ID; // see if current WP user
		get_currentuserinfo();

		// lookup country info for this ip
		// geoip lookup using Visitor Maps and Who's Online plugin
		$geo_loc = '';


		if (file_exists(WP_PLUGIN_DIR . '/visitor-maps/include-whos-online-geoip.php') && file_exists(WP_PLUGIN_DIR . '/visitor-maps/GeoLiteCity.dat')) {
			require_once(WP_PLUGIN_DIR . '/visitor-maps/include-whos-online-geoip.php');
			$gi = geoip_open_VMWO(WP_PLUGIN_DIR . '/visitor-maps/GeoLiteCity.dat', VMWO_GEOIP_STANDARD);
			$record = geoip_record_by_addr_VMWO($gi, $_SERVER['REMOTE_ADDR']);
			geoip_close_VMWO($gi);
			$li = array();
			$li['city_name'] = (isset($record->city)) ? $record->city : '';
			$li['state_name'] = (isset($record->country_code) && isset($record->region)) ? $GEOIP_REGION_NAME[$record->country_code][$record->region] : '';
			$li['state_code'] = (isset($record->region)) ? strtoupper($record->region) : '';
			$li['country_name'] = (isset($record->country_name)) ? $record->country_name : '--';
			$li['country_code'] = (isset($record->country_code)) ? strtoupper($record->country_code) : '--';
			$li['latitude'] = (isset($record->latitude)) ? $record->latitude : '0';
			$li['longitude'] = (isset($record->longitude)) ? $record->longitude : '0';
			if ($li['city_name'] != '') {
				if ($li['country_code'] == 'US') {
					$geo_loc = $li['city_name'];
					if ($li['state_code'] != '')
						$geo_loc = $li['city_name'] . ', ' . strtoupper($li['state_code']);
				} else {	// all non us countries
					$geo_loc = $li['city_name'] . ', ' . strtoupper($li['country_code']);
				}
			} else {
				$geo_loc = '~ ' . $li['country_name'];
			}
		}
		// add some info about sender to the email message
		$userdomain = '';
		$userdomain = gethostbyaddr($_SERVER['REMOTE_ADDR']);
		$user_info_string = '';
		if (self::$form_options['email_html'] == 'true')
			$user_info_string = '<div style="background:#eee;border:1px solid gray;color:gray;padding:1em;margin:1em 0;">';
		if ($user_ID != '') {
			//user logged in
			if ($current_user->user_login != '')
				$user_info_string .= __('From a WordPress user', 'sweetcontact') . ': ' . $current_user->user_login . self::$php_eol;
			if ($current_user->user_email != '')
				$user_info_string .= __('User email', 'sweetcontact') . ': ' . $current_user->user_email . self::$php_eol;
			if ($current_user->user_firstname != '')
				$user_info_string .= __('User first name', 'sweetcontact') . ': ' . $current_user->user_firstname . self::$php_eol;
			if ($current_user->user_lastname != '')
				$user_info_string .= __('User last name', 'sweetcontact') . ': ' . $current_user->user_lastname . self::$php_eol;
			if ($current_user->display_name != '')
				$user_info_string .= __('User display name', 'sweetcontact') . ': ' . $current_user->display_name . self::$php_eol;
		}
		$user_info_string .= __('Sent from (ip address)', 'sweetcontact') . ': ' . esc_attr($_SERVER['REMOTE_ADDR']) . " ($userdomain)" . self::$php_eol;
		if ($geo_loc != '') {
			$user_info_string .= __('Location', 'sweetcontact') . ': ' . $geo_loc . self::$php_eol;
			self::$form_data['sender_location'] = __('Location', 'sweetcontact') . ': ' . $geo_loc;
		}
		$user_info_string .= __('Date/Time', 'sweetcontact') . ': ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), time()) . self::$php_eol;
		$user_info_string .= __('Coming from (referer)', 'sweetcontact') . ': ' . esc_url(self::$form_action_url) . self::$php_eol;
		$user_info_string .= __('Using (user agent)', 'sweetcontact') . ': ' . SWEETCF_Utils::clean_input($_SERVER['HTTP_USER_AGENT']) . self::$php_eol . self::$php_eol;
		if (self::$form_options['email_html'] == 'true')
			$user_info_string .= '</div>';

		return($user_info_string);
	}

	static function check_captcha() { // Captcha check if enabled
		global $swcf_captcha_instance;
		if (SWEETCF_Display::is_captcha_enabled(self::$form_id_num)) {
			if (isset($_POST['captcha_code'])) { // Regular captcha
				$captcha_code = SWEETCF_Utils::clean_input($_POST['captcha_code']);

				if (self::$global_options['enable_php_sessions'] == 'true') { // only if PHP sessions enabled
					//captcha with PHP sessions
					if (!isset($_SESSION['securimage_code_ctf_' . self::$form_id_num]) || empty($_SESSION['securimage_code_ctf_' . self::$form_id_num])) {
						self::$form_errors['captcha'] = __('That CAPTCHA was incorrect. Try again.', 'sweetcontact');
					} else {
						if (empty($captcha_code)) {
							self::$form_errors['captcha'] = (self::$form_options['error_captcha_blank'] != '') ? self::$form_options['error_captcha_blank'] : __('Please complete the CAPTCHA.', 'sweetcontact');
						} else {
							require_once SWCF_CAPTCHA_PATH . '/securimage.php';
							$img = new Securimage_ctf();
							$img->form_num = self::$form_id_num; // makes compatible with multi-forms on same page
							$valid = $img->check("$captcha_code");
							// has the right CAPTCHA code has been entered?
							if ($valid == true) {
								// ok can continue
							} else {
								self::$form_errors['captcha'] = (self::$form_options['error_captcha_wrong'] != '') ? self::$form_options['error_captcha_wrong'] : __('That CAPTCHA was incorrect.', 'sweetcontact');
							}
						}
					}
				} else { //captcha without PHP sessions
					if (empty($captcha_code)) {
						self::$form_errors['captcha'] = (self::$form_options['error_captcha_blank'] != '') ? self::$form_options['error_captcha_blank'] : __('Please complete the CAPTCHA.', 'sweetcontact');
					} else if (!isset($_POST['swcf_captcha_prefix' . self::$form_id_num]) || empty($_POST['swcf_captcha_prefix' . self::$form_id_num])) {
						// this error means PHP session error, or they sat on the page more than 30 min
						self::$form_errors['captcha'] = __('That CAPTCHA was incorrect. Try again.', 'sweetcontact');
					} else {
						$prefix = 'xxxxxx';
						if (isset($_POST['swcf_captcha_prefix' . self::$form_id_num]) && is_string($_POST['swcf_captcha_prefix' . self::$form_id_num]) && preg_match('/^[a-zA-Z0-9]{15,17}$/', $_POST['swcf_captcha_prefix' . self::$form_id_num])) {
							$prefix = $_POST['swcf_captcha_prefix' . self::$form_id_num];
						}
						if (is_readable(SWCF_CAPTCHA_PATH . '/cache/' . $prefix . '.php')) {
							include( SWCF_CAPTCHA_PATH . '/cache/' . $prefix . '.php' );
							// has the right CAPTCHA code has been entered?
							if (0 == strcasecmp($captcha_code, $captcha_word)) {
								// captcha was matched
								@unlink(SWCF_CAPTCHA_PATH . '/cache/' . $prefix . '.php');
								// ok can continue
							} else {
								self::$form_errors['captcha'] = (self::$form_options['error_captcha_wrong'] != '') ? self::$form_options['error_captcha_wrong'] : __('That CAPTCHA was incorrect.', 'sweetcontact');
							}
						} else {
							// this error means cache read error, or they sat on the page more than 30 min
							self::$form_errors['captcha'] = __('That CAPTCHA was incorrect. Try again.', 'sweetcontact');
						}
					}
				}
			} else { // Sweet Captcha
				if ( !defined('SWCF_CAPTCHA_OK') ) { sweetcontact_plugins_loaded(); }
				/*if ( SWCF_CAPTCHA_OK ) {
					$res = array (
						'sckey' => ( isset($_POST['sckey']) ? $_POST['sckey'] : '' ),
						'scvalue' => ( isset($_POST['scvalue']) ? $_POST['scvalue'] : '' )
					);
					initCaptchaInstance();
					if ( $swcf_captcha_instance->registered ) {
						if ( $swcf_captcha_instance->check( $res ) != 'true' ) {
							self::$form_errors['captcha'] = '<strong>'.__( 'ERROR', 'captcha' ) . '</strong>: ' . __(SWCF_CAPTCHA_ERROR_MESSAGE_BR, 'captcha' );
						}
					}
				}*/
			}
		} 
	}

	static function forbidifnewlines($input) {
		// check posted input for email injection attempts
		// Check for these common exploits
		// if you edit any of these do not break the syntax of the regex
		$input_expl = "/(<CR>|<LF>|\r|\n|%0a|%0d|content-type|mime-version|content-transfer-encoding|to:|bcc:|cc:|document.cookie|document.write|onmouse|onkey|onclick|onload)/i";
		// Loop through each POST'ed value and test if it contains one of the exploits fromn $input_expl:
		if (is_string($input)) {
			$v = strtolower($input);
			$v = str_replace('donkey', '', $v); // fixes invalid input with "donkey" in string
			$v = str_replace('monkey', '', $v); // fixes invalid input with "monkey" in string
			if (preg_match($input_expl, $v)) {
				// XXX someday make these messages editable in settings
				wp_die(__('Illegal characters in POST. Possible email injection attempt', 'sweetcontact'));
			}
		}
	}

	static function spamcheckpost() {
		// helps spam protect the postaction
		// blocks contact form posted from other domains
		if (!isset($_SERVER['HTTP_USER_AGENT'])) {
			return __('Invalid User Agent', 'sweetcontact');
		}

		// Make sure the form was indeed POST'ed:
		if (!$_SERVER['REQUEST_METHOD'] == "POST") {
			return __('Invalid POST', 'sweetcontact');
		}

		// Make sure the form was posted from an approved host name.
		if (self::$form_options['domain_protect'] == 'true') {
			$print_authHosts = '';
			$uri = parse_url(get_option('home'));
			$domain_arr = preg_replace("/^www\./i", '', $uri['host']);
			if (!is_array($domain_arr))
				$domain_arr = array("$domain_arr");

			// Additional allowed domain names(optional): from the form edit 'Security' tab
			$more_domains = explode("\n", trim(self::$form_options['domain_protect_names']));
			if (!empty($more_domains))
				$domain_arr = array_merge($more_domains, $domain_arr);

			// Host names from where the form is authorized to be posted from:
			$domain_arr = array_map('strtolower', $domain_arr);
			foreach ($domain_arr as $each_domain) {
				$print_authHosts .= ', ' . $each_domain;
			}

			// Where have we been posted from?
			if (isset($_SERVER['HTTP_REFERER']) and trim($_SERVER['HTTP_REFERER']) != '') {
				$fromArray = parse_url(strtolower($_SERVER['HTTP_REFERER']));
				$test_url = preg_replace("/^www\./i", '', $fromArray['host']);
				if (!in_array($test_url, $domain_arr))
					return sprintf(__('Invalid HTTP_REFERER domain. The domain name posted from does not match the allowed domain names from the form edit Security tab: %s', 'sweetcontact'), $print_authHosts);
			}
		} // end if domain protect
		// check posted input for email injection attempts
		// Check for these common exploits
		// if you edit any of these do not break the syntax of the regex
		$input_expl = "/(%0a|%0d)/i";
		// Loop through each POST'ed value and test if it contains one of the exploits fromn $input_expl:
		foreach ($_POST as $k => $v) {
			if (is_string($v)) {
				$v = strtolower($v);
				$v = str_replace('donkey', '', $v); // fixes invalid input with "donkey" in string
				$v = str_replace('monkey', '', $v); // fixes invalid input with "monkey" in string
				if (preg_match($input_expl, $v)) {
					// XXX someday make these messages editable in settings
					return __('Illegal characters in POST. Possible email injection attempt', 'sweetcontact');
				}
			}
		}

		return 0;
	}

	static function prepare_email() { // Prepare and send email
		
		self::$form_options['email_html'] = true; // TODO testing
		$php_mailer_enable = true;//self::$form_options['php_mailer_enable'] = ''; // TODO testing
		//var_export(self::$form_options['form_name']);
		$from_email = get_option( 'admin_email' );
    $to = '';
    $subject = self::$form_options['form_name'] . ' | ' . self::$email_fields['subject'];
		$msg = nl2br(self::$email_fields['message']); // self::$email_msg
    $attachments = array();
    $headers = "";
		
		if (!self::$global_options) {	self::$global_options = SWEETCF_Utils::get_global_options(); }
		SWEETCF_Display::$contacts = SWEETCF_Options::get_contact_list();
		
		if ( self::$global_options['email']['email_to'] == 'custom' ) { // TODO: Wrap into the function
			$to = self::$global_options['email']['custom_email'];
			$wp_user = self::$global_options['email']['custom_email'];
		} else {
			global $wpdb; 
			$wp_user = ( isset(self::$form_options['wp_user']) && (intval(self::$form_options['wp_user']) > 0) ) ? intval(self::$form_options['wp_user']) : 1; // By default - admin
			if ( $wp_user ) {
				$user = $wpdb->get_row("SELECT user_login, user_email FROM $wpdb->users WHERE ID='$wp_user'", 0); 
				$to = $user->user_email;
			}
		}
		if ( $to == '' ) {
			return false; // TODO
		}
		//echo '<hr>email_fields: '; var_dump(self::$email_fields); echo '<hr>';
		$site_name = get_bloginfo('name');
		$headers = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-type: text/html; charset=utf-8' . "\r\n";
    //$headers .= 'From: ' . self::$email_fields['from_name'] . ' <'. self::$email_fields['from_email'] . '>'. "\r\n";
		$headers .= 'From: "'. $site_name . '"<'. $from_email . '>' . "\r\n";
		//$headers .= 'Reply-To: ' . self::$email_fields['from_name'] . '<'. self::$email_fields['from_email'] . '>' . "\r\n";
		$msg = '<html><head><title>' . __("sweetContact from ", 'sweetcontact') . $site_name . '</title></head>
			<body>
			<table>
				<tr><td width="160">' . __("Name", 'sweetcontact') . '</td><td>' . self::$email_fields['from_name'] . '</td></tr>
				<tr><td>' . __("Email", 'sweetcontact') . '</td><td>' . self::$email_fields['from_email'] . '</td></tr>
				<tr><td>' . __("Subject", 'sweetcontact') . '</td><td>' . self::$email_fields['subject'] . '</td></tr>
				<tr><td>' . __("Message", 'sweetcontact') . '</td><td>' . $msg . '</td></tr>
				<tr><td>' . __("Site", 'sweetcontact') . '</td><td>' . get_bloginfo("url") . '</td></tr>
			</table>
		</body></html>';
    if ( $php_mailer_enable ) {
			return wp_mail($to, stripslashes($subject), stripslashes($msg), $headers, $attachments);
    } else {
			return @mail($to, $subject, $msg , $headers);
		}
		
		// Export option: filter posted data based on admin settings
		//$posted_data_export = self::export_convert(self::$email_fields, self::$form_options['export_rename'], self::$form_options['export_ignore'], self::$form_options['export_add'], 'array');
		// hook for other plugins to use (just after message posted)
		//$posted_data = (object) array('form_number' => self::$form_id_num, 'title' => self::$form_options['form_name'], 'posted_data' => $posted_data_export, 'uploaded_files' => (array) self::$uploaded_files);
		//do_action_ref_array('swcf_contact_mail_sent', array(&$posted_data));
	}

	static function set_wp_from_email() { // used in function prepare_email
		return self::$email_set_wp['from_email'];
	}

	static function set_wp_from_name() { // used in function prepare_email
		return self::$email_set_wp['from_name'];
	}

	static function set_wp_mail_sender($phpmailer) { // used in function prepare_email
		// add Sender for Return-path to wp_mail
		$phpmailer->Sender = self::$email_set_wp['mail_sender'];
	}

	static function email_sent_cleanup_attachments() {
		// clean up the attachment directory after email sent

		if (!empty(self::$uploaded_files)) {
			// unlink attachment temp files individually
			foreach ((array) self::$uploaded_files as $path) {
				@unlink($path);
			}
			// full directory sweep cleanup
			//self::clean_temp_dir( SWCF_ATTACH_DIR, 3 );
		}
	}

	static function email_sent_redirect() {
		// displays thank you after email is sent, Redirct after email sent
		self::$redirect_enable = self::$form_options['redirect_enable'];

		//var_dump(self::$form_options); return;
		if (self::$form_options['redirect_enable'] == 'true') {
			self::$redirect_enable = 'true';
			$ctf_redirect_url = self::$form_options['redirect_url'];
		}
		// allow shortcode redirect to override options redirect settings
		if (self::$global_options['enable_php_sessions'] == 'true' && // this feature only works when PHP sessions are enabled
			$_SESSION['fsc_shortcode_redirect_' . self::$form_id_num] != '') {
			self::$redirect_enable = 'true';
			$ctf_redirect_url = strip_tags($_SESSION['fsc_shortcode_redirect_' . self::$form_id_num]);
		}
		
		if (self::$redirect_enable == 'true') {
			if ($ctf_redirect_url == '#') {
				$ctf_redirect_url = self::$form_action_url;
			}
			// filter hook for changing the redirect URL. You could make a function that changes it based on fields
			$ctf_redirect_url = apply_filters('sw_contact_redirect_url', $ctf_redirect_url, self::$email_fields, self::$form_data['mailto_id'], self::$form_id_num);

			// redirect query string code
			if (self::$form_options['redirect_query'] == 'true') {
				// build query string
				$query_string = self::export_convert(self::$email_fields, self::$form_options['redirect_rename'], self::$form_options['redirect_ignore'], self::$form_options['redirect_add'], 'query');
				if (!preg_match("/\?/", $ctf_redirect_url))
					$ctf_redirect_url .= '?' . $query_string;
				else
					$ctf_redirect_url .= '&' . $query_string;
			}

			$ctf_redirect_timeout = absint(self::$form_options['redirect_seconds']); // time in seconds to wait before loading another Web page

			if ($ctf_redirect_timeout == 0) {
				// use wp_redirect when timeout seconds is 0.
				// So now if you set the timeout to 0 seconds, then post the form, it gets instantly redirected to the redirect URL
				// and you are responsible to display the "your message has been sent, thank you" message there.
				wp_redirect($ctf_redirect_url);
				exit;
			}

			// meta refresh page timer feature allows some seconds to to display the "your message has been sent, thank you" message.
			self::$meta_string = "<meta http-equiv=\"refresh\" content=\"$ctf_redirect_timeout;URL=$ctf_redirect_url\">\n";
			if (is_admin())
				add_action('admin_head', 'SWEETCF_Process::meta_refresh', 1);
			else
				add_action('wp_head', 'SWEETCF_Process::meta_refresh', 1);
		} // end if (self::$redirect_enable == 'true')
	}

	static function meta_refresh() {
		echo self::$meta_string;
	}

	static function export_convert($posted_data, $rename, $ignore, $add, $return = 'array') {
		$query_string = '';
		$posted_data_export = array();
		//rename field names array
		$rename_fields = array();
		$rename_fields_test = explode("\n", $rename);
		if (!empty($rename_fields_test)) {
			foreach ($rename_fields_test as $line) {
				if (preg_match("/=/", $line)) {
					list($key, $value) = explode("=", $line);
					$key = trim($key);
					$value = trim($value);
					if ($key != '' && $value != '')
						$rename_fields[$key] = $value;
				}
			}
		}
		// add fields
		$add_fields_test = explode("\n", $add);
		if (!empty($add_fields_test)) {
			foreach ($add_fields_test as $line) {
				if (preg_match("/=/", $line)) {
					list($key, $value) = explode("=", $line);
					$key = trim($key);
					$value = trim($value);
					if ($key != '' && $value != '') {
						if ($return == 'array')
							$posted_data_export[$key] = $value;
						else
							$query_string .= $key . '=' . urlencode(stripslashes($value)) . '&';
					}
				}
			}
		}
		//ignore field names array
		$ignore_fields = array();
		$ignore_fields = array_map('trim', explode("\n", $ignore));
		// $posted_data is an array of the form name value pairs
		foreach ($posted_data as $key => $value) {
			if (is_string($value)) {
				if (in_array($key, $ignore_fields))
					continue;
				$key = ( isset($rename_fields[$key]) ) ? $rename_fields[$key] : $key;
				if ($return == 'array')
					$posted_data_export[$key] = $value;
				else
					$query_string .= $key . '=' . urlencode(stripslashes($value)) . '&';
			}
		}
		if ($return == 'array')
			return $posted_data_export;
		else
			return $query_string;
	}

	static function clean_temp_dir($dir, $minutes = 30) {
		// needed for emptying temp directories for attachments
		// garbage collection    // deletes all files over xx minutes old in a temp directory
		if (!is_dir($dir) || !is_readable($dir) || !is_writable($dir)) {
			return false;
		}
		$count = 0;
		$list = array();
		$handle = @opendir($dir);
		if ($handle) {
			while (false !== ( $file = readdir($handle) )) {
				if ($file == '.' || $file == '..' || $file == '.htaccess' || $file == 'index.php')
					continue;

				$stat = @stat($dir . $file);
				if (( $stat['mtime'] + $minutes * 60 ) < time()) {
					@unlink($dir . $file);
					$count += 1;
				} else {
					$list[$stat['mtime']] = $file;
				}
			}
			closedir($handle);
			// purge xx amount of files based on age to limit a DOS flood attempt. Oldest ones first, limit 500
			if (isset($list) && count($list) > 499) {
				ksort($list);
				$ct = 1;
				foreach ($list as $k => $v) {
					if ($ct > 499)
						@unlink($dir . $v);
					$ct += 1;
				}
			}
		}
		return $count;
	}

	static function make_bold($label) {
		// makes bold html email labels
		if (self::$form_options['email_html'] == 'true') {
			return '<b>' . $label . '</b>';
		} else {
			return $label;
		}
	}

}

?>
