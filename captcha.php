<?php

define('SWCF_CAPTCHA_APP_ID', 'captcha_app_id');
define('SWCF_CAPTCHA_KEY', 'captcha_key');
define('SWCF_CAPTCHA_SECRET', 'captcha_secret');

define('SWCF_CAPTCHA_ERROR_MESSAGE_BR', __('You chose the wrong image solution for the security challenge.<br>Please read the instructions and try again.', 'captcha'));
define('SWCF_CAPTCHA_CONNECT_ERROR', __("It seems that your site / server can't reach sweetcontactform.com,<br> please ask your hosting provider to open your site to http://www.sweetcontactform.com:80 for it to function correctly.<br>Thank you,<br>Support Team.", 'captcha'));

if (!defined('SWCF_CAPTCHA_SITE_URL')) {
	define('SWCF_CAPTCHA_SITE_URL', 'https://www.sweetcontactform.com');
}
if (!defined('SWCF_CAPTCHA_DIR_NAME')) {
	define('SWCF_CAPTCHA_DIR_NAME', basename(dirname(__FILE__)));
}
if (!defined('SWCF_SWCF_CAPTCHA_URL')) {
	define('SWCF_SWCF_CAPTCHA_URL', WP_PLUGIN_URL . '/' . SWCF_CAPTCHA_DIR_NAME);
}
if (!defined('SWCF_CAPTCHA_PHP_PATH')) {
	define('SWCF_CAPTCHA_PHP_PATH', SWCF_SWCF_CAPTCHA_URL . '/captcha.php');
}
if (!defined('SWCF_CAPTCHA_NOT_REGISTERED')) {
	define('SWCF_CAPTCHA_NOT_REGISTERED', __('Email address is not registered at ', 'sweetcontact') . '<a target="_blank" href="' . SWCF_CAPTCHA_SITE_URL . '">Captcha</a>');
}

$swcf_captcha_instance = null;

function initCaptchaInstance() {
	return true;
	global $swcf_captcha_instance;

	if ( $swcf_captcha_instance ) { // Only one instance should exist !
		return true;
	}

	/*delete_option(SWCF_CAPTCHA_APP_ID);
	delete_option(SWCF_CAPTCHA_KEY);
	delete_option(SWCF_CAPTCHA_SECRET);*/

	$error = '';
	$sweetcontact_captcha_app_id = get_option(SWCF_CAPTCHA_APP_ID);
	
	$swcf_captcha_instance = new SweetContactCaptcha(
		$sweetcontact_captcha_app_id, get_option(SWCF_CAPTCHA_KEY), get_option(SWCF_CAPTCHA_SECRET),	SWCF_CAPTCHA_PHP_PATH
	);

	if ( !$sweetcontact_captcha_app_id && isset( $_REQUEST['settings-updated'] ) && $_REQUEST['settings-updated'] == 'true' ) { // try to register
		$error = SWCF_NOT_READY;
		$swcf_captcha_instance->registered = false;
		
		SWEETCF_Options::$contacts = (SWEETCF_Options::$contacts) ? SWEETCF_Options::$contacts : SWEETCF_Options::get_contact_list();
		$email = isset(SWEETCF_Options::$contacts[0]["EMAIL"]) ? SWEETCF_Options::$contacts[0]["EMAIL"] : '';
		$params = array(
			'platform' => 'sweetcontact',
			'website' => get_option('siteurl'),
			'email' => $email,
		);
		
		$swcf_captcha_instance = new SweetContactCaptcha();
		$result = json_decode($swcf_captcha_instance->submit_register_form($params), true);
		$result['error'] = isset($result['error']) ? $result['error'] : '';
		
		if ($result['error']) {
			if (strpos($result['error'], 'This website is already registered') !== false) {
				$swcf_captcha_instance->registered = true;
			} else {
				$error = $result['error'];
			}
		} else {
			if (isset($result['app_id'])) {
				update_option(SWCF_CAPTCHA_APP_ID, $result['app_id']);
				update_option(SWCF_CAPTCHA_KEY, $result['key']);
				update_option(SWCF_CAPTCHA_SECRET, $result['secret']);
				$swcf_captcha_instance->registered = true;
			}
		}
	}

	$swcf_captcha_instance->error = $error;

	return true;
}

function sweetcontact_captcha_is_registered() {
	return true;
	global $swcf_captcha_instance;
	initCaptchaInstance();
	return $swcf_captcha_instance->registered;
}

function sweetcontact_captcha_shortcode($atts = array()) {
	return '';
	if ( !sweetcontact_captcha_is_registered() ) {
		return '';
	}
	global $swcf_captcha_instance;
	return ( ( function_exists('captcha_header') ) ? captcha_header() : '' ) . $swcf_captcha_instance->get_html();
}

/**
 * Handles remote negotiation
 * @version 1.1
 */
if (isset($_POST['ajax']) && ($method = $_POST['ajax'])) {
	/*initCaptchaInstance();
	echo $swcf_captcha_instance->$method(isset($_POST['params']) ? $_POST['params'] : array());*/
}

class SweetContactCaptcha {

	private $appid;
	private $key;
	private $secret;
	private $path;
	public $registered;
	private $api_host;
	public $error;
	
	function __construct($appid = '', $key = '', $secret = '', $path = '') {
		$this->appid = $appid;
		$this->key = $key;
		$this->secret = $secret;
		$this->path = $path;
		$this->api_host = parse_url(SWCF_CAPTCHA_SITE_URL, PHP_URL_HOST);
		$this->registered = (!empty($appid) && !empty($key) && !empty($secret) );
		$this->error = '';
	}

	private function api($method, $params) {
		$basic = array(
			'method' => $method,
			'appid' => $this->appid,
			'key' => $this->key,
			'path' => $this->path,
			'user_ip' => $_SERVER['REMOTE_ADDR'],
			'user_agent' => $_SERVER['HTTP_USER_AGENT'],
			'platform' => 'sweetcontact'
		);

		if (is_admin()) {
			return $this->call(array_merge(isset($params[0]) ? $params[0] : $params, $basic));
		} else {
			if ($this->registered) {
				return $this->call(array_merge(isset($params[0]) ? $params[0] : $params, $basic));
			} else {
				//return '<span style="color: red;">'.__('Your plugin is not setup yet', 'captcha').'</span>';
				return '';
			}
		}
	}

	private function call($params) {
		$param_data = "";
		foreach ($params as $param_name => $param_value) {
			$param_data .= urlencode($param_name) . '=' . urlencode($param_value) . '&';
		}

		$fs = fsockopen($this->api_host, 80, $errno, $errstr, 10 /* The connection timeout, in seconds */);
		if (!$fs) {
			if (isset($params['check'])) {
				return '<div class="error captcha" style="text-align: left; ">' . $this->call_error($errstr, $errno) . '</div>';
			}
			return ''; //$this->call_error($errstr, $errno);
		} else
		if (isset($params['check'])) {
			return '';
		}

		$req = "POST /api.php HTTP/1.0\r\n";
		$req .= "Host: " . $this->api_host . "\r\n";
		$req .= "Content-Type: application/x-www-form-urlencoded\r\n";
		$req .= "Referer: " . $_SERVER['HTTP_HOST'] . "\r\n";
		$req .= "Content-Length: " . strlen($param_data) . "\r\n\r\n";
		$req .= $param_data;

		$response = '';
		fwrite($fs, $req);
		while (!feof($fs)) {
			$response .= fgets($fs, 1160);
		}
		fclose($fs);

		$response_arr = explode("\r\n\r\n", $response, 2);
		return $response_arr[1];
	}

	private function call_error($errstr, $errno) {
		return "<p style='color:red;'>" . CAPTCHA_CONNECT_ERROR . "</p><a style='text-decoration:underline;' href='javascript:void(0)' onclick='javascript:jQuery(\"#captcha-error-details\").toggle();'>Details</a><span id='captcha-error-details' style='display: none;'><br>$errstr ($errno)</span>";
	}

	public function __call($method, $params) {
		return $this->api($method, $params);
	}

	public function check_access() {
		echo $this->api('get_html', array('check' => 1));
	}

}

?>