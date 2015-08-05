<?php
/*
 * Wordpress will run the code in this file when the user deletes the plugin
 * 
 */
// Be sure that Wordpress is deleting the plugin
if (defined('WP_UNINSTALL_PLUGIN')) {
	// settings get deleted when plugin is deleted from admin plugins page
	delete_option('sweetcontact_global');
	delete_option(SWCF_CAPTCHA_APP_ID);
	delete_option(SWCF_CAPTCHA_KEY);
	delete_option(SWCF_CAPTCHA_SECRET);
	// delete up to 100 forms (a unique configuration for each contact form)
	for ($i = 1; $i <= 100; $i++) { // TODO: optimal decision ?
		delete_option("sweetcontact_form$i");
	}
}  
?>