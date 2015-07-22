<?php
function sweetcontact_admin_notices() {
	if ( defined('SWCF_SWEETCAPTCHA_PROBLEM') ) { return; }
	define( 'SWCF_SWEETCAPTCHA_OK', (function_exists('sweetcontact_sweetcaptcha_is_registered') && sweetcontact_sweetcaptcha_is_registered()) );

	if (sweetcontact_sweetcaptcha_is_registered()) {
		global $swcf_sweetcaptcha_instance;
		$swcf_sweetcaptcha_instance->get_html();
	}

	$sweetcaptcha_problem = '';
	if ( !SWCF_SWEETCAPTCHA_OK ) {
		$sweetcaptcha_problem = SWCF_NOT_READY;
	}
	define( 'SWCF_SWEETCAPTCHA_PROBLEM', __( $sweetcaptcha_problem, 'sweetcontact' ) );
	//echo '<hr>SWCF_SWEETCAPTCHA_PROBLEM: '.SWCF_SWEETCAPTCHA_PROBLEM.'<hr>';
	if ( SWCF_SWEETCAPTCHA_PROBLEM ) {
		wp_enqueue_style( 'wp-pointer' ); wp_enqueue_script( 'jquery-ui' ); wp_enqueue_script( 'wp-pointer' ); wp_enqueue_script( 'utils' );
		//SWEETCF_Utils::add_admin_notice('swcf-sweetcaptcha-problem',SWCF_SWEETCAPTCHA_PROBLEM, 'error ', 'color: red; font-size: 14px; font-weight: bold;	text-align: center;');
		echo '<div class="error sweetcaptcha" style="text-align: center; ">
      <p style="color: red; font-size: 14px; font-weight: bold;">' . SWCF_SWEETCAPTCHA_PROBLEM
		. '</p></div>'
		;

		//self::$global_options['admin_notices'][$key] = '    <div class="error" style="color: red; font-size: 14px; font-weight: bold;	text-align: center;"><p>' . SWCF_SWEETCAPTCHA_PROBLEM . '</p></div>';
		//add_action('admin_notices', 'sweetcontact_popup_setup');
		sweetcontact_popup_setup();
	}
}

function sweetcontact_popup_setup() { ?>
	<style>
		#sweetcontact-popup-header {background-color: #D81378; border-color: #D81378;}
		#sweetcontact-popup-header:before {color:#D81378;}
	</style>
	<script type="text/javascript">
		//<![CDATA[
		;(function($) {
			var setup = function() {
				$('#toplevel_page_sweetcontact').pointer({
						content: '<h3 id="sweetcontact-popup-header"><?php echo SWCF_SWEETCAPTCHA_PROBLEM?></h3>',
						position: {
							edge: 'left', // arrow direction
							align: 'center' // vertical alignment
						},
						pointerWidth: 350,
						close: function() {
						}
				}).pointer('open');
			};
			$(window).bind('load.wp-pointers', setup);
		})(jQuery);
		//]]>
	</script>
	<?php
}

?>