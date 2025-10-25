<?php
/**
 * Admin functionality for V430 CF7 OpenAI Spam Check plugin.
 *
 * @package V430_CF7_OpenAI_Spam
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Admin class for V430 CF7 OpenAI Spam Check.
 */
class V430_CF7_OpenAI_Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->define_hooks();
	}

	/**
	 * Define admin-specific hooks.
	 */
	private function define_hooks() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 11 );
		add_action( 'admin_init', array( $this, 'init_settings' ) );
	}

	/**
	 * Add admin menu under Contact Form 7.
	 */
	public function add_admin_menu() {
		// Only add menu if CF7 is active
		if ( ! function_exists( 'wpcf7' ) ) {
			return;
		}

		add_submenu_page(
			'wpcf7',
			__( 'OpenAI Anti Spam', 'v430-cf7-openai-spam' ),
			__( 'OpenAI Anti Spam', 'v430-cf7-openai-spam' ),
			'manage_options',
			'v430-cf7-openai-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Initialize settings using WordPress Settings API.
	 */
	public function init_settings() {
		register_setting(
			'v430_cf7_openai_settings',
			'V430_OPENAI_API_KEY',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_api_key' ),
				'default'           => '',
			)
		);

		add_settings_section(
			'v430_cf7_openai_api_section',
			__( 'OpenAI API Configuration', 'v430-cf7-openai-spam' ),
			array( $this, 'render_api_section' ),
			'v430_cf7_openai_settings'
		);

		add_settings_field(
			'V430_OPENAI_API_KEY',
			__( 'OpenAI API Key', 'v430-cf7-openai-spam' ),
			array( $this, 'render_api_key_field' ),
			'v430_cf7_openai_settings',
			'v430_cf7_openai_api_section'
		);
	}

	/**
	 * Sanitize the API key.
	 *
	 * @param string $api_key The API key to sanitize.
	 * @return string Sanitized API key.
	 */
	public function sanitize_api_key( $api_key ) {
		$api_key = sanitize_text_field( $api_key );
		
		// Basic validation: OpenAI API keys start with 'sk-'
		if ( ! empty( $api_key ) && ! str_starts_with( $api_key, 'sk-' ) ) {
			add_settings_error(
				'V430_OPENAI_API_KEY',
				'invalid_api_key',
				__( 'Invalid OpenAI API key format. API keys should start with "sk-".', 'v430-cf7-openai-spam' ),
				'error'
			);
			// Return the old value if validation fails
			return get_option( 'V430_OPENAI_API_KEY', '' );
		}

		return $api_key;
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'v430-cf7-openai-spam' ) );
		}

		// Handle form submission
		if ( isset( $_POST['submit'] ) ) {
			check_admin_referer( 'v430_cf7_openai_settings_action', 'v430_cf7_openai_settings_nonce' );
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			
			<div class="notice notice-info">
				<p>
					<?php 
					printf(
						/* translators: %1$s: OpenAI link start, %2$s: link end */
						__( 'You need an OpenAI API key to use this plugin. Get one from %1$sOpenAI%2$s.', 'v430-cf7-openai-spam' ),
						'<a href="https://platform.openai.com/api-keys" target="_blank">',
						'</a>'
					);
					?>
				</p>
			</div>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'v430_cf7_openai_settings' );
				do_settings_sections( 'v430_cf7_openai_settings' );
				wp_nonce_field( 'v430_cf7_openai_settings_action', 'v430_cf7_openai_settings_nonce' );
				submit_button();
				?>
			</form>

			<div class="notice notice-warning">
				<h3><?php _e( 'Important Notes:', 'v430-cf7-openai-spam' ); ?></h3>
				<ul>
					<li><?php _e( 'This plugin sends form data to OpenAI for spam classification.', 'v430-cf7-openai-spam' ); ?></li>
					<li><?php _e( 'No form data is stored by this plugin - only sent to OpenAI for analysis.', 'v430-cf7-openai-spam' ); ?></li>
					<li><?php _e( 'If the API fails, submissions will NOT be blocked (fail-open behavior).', 'v430-cf7-openai-spam' ); ?></li>
					<li><?php _e( 'You must enable spam checking individually for each contact form.', 'v430-cf7-openai-spam' ); ?></li>
				</ul>
			</div>

			<?php $this->render_test_section(); ?>
		</div>
		<?php
	}

	/**
	 * Render the API section description.
	 */
	public function render_api_section() {
		echo '<p>' . esc_html__( 'Configure your OpenAI API key to enable spam classification.', 'v430-cf7-openai-spam' ) . '</p>';
	}

	/**
	 * Render the API key field.
	 */
	public function render_api_key_field() {
		$api_key = get_option( 'V430_OPENAI_API_KEY', '' );
		$masked_key = '';
		
		if ( ! empty( $api_key ) ) {
			// Mask the API key for display (show first 7 and last 4 characters)
			$masked_key = substr( $api_key, 0, 7 ) . str_repeat( '*', max( 0, strlen( $api_key ) - 11 ) ) . substr( $api_key, -4 );
		}
		
		?>
		<input 
			type="password" 
			id="V430_OPENAI_API_KEY" 
			name="V430_OPENAI_API_KEY" 
			value="<?php echo esc_attr( $api_key ); ?>"
			class="regular-text"
			placeholder="sk-..."
			autocomplete="off"
		/>
		<?php if ( ! empty( $masked_key ) ) : ?>
			<p class="description">
				<?php 
				printf(
					/* translators: %s: masked API key */
					__( 'Current key: %s', 'v430-cf7-openai-spam' ),
					'<code>' . esc_html( $masked_key ) . '</code>'
				);
				?>
			</p>
		<?php endif; ?>
		<p class="description">
			<?php _e( 'Enter your OpenAI API key. It should start with "sk-".', 'v430-cf7-openai-spam' ); ?>
		</p>
		<?php
	}

	/**
	 * Render a test section to verify API connectivity.
	 */
	private function render_test_section() {
		$api_key = get_option( 'V430_OPENAI_API_KEY', '' );
		
		if ( empty( $api_key ) ) {
			return;
		}

		?>
		<div class="card">
			<h2><?php _e( 'API Connection Test', 'v430-cf7-openai-spam' ); ?></h2>
			<p><?php _e( 'Test your API key configuration:', 'v430-cf7-openai-spam' ); ?></p>
			
			<button type="button" id="test-api-connection" class="button button-secondary">
				<?php _e( 'Test API Connection', 'v430-cf7-openai-spam' ); ?>
			</button>
			
			<div id="api-test-result" style="margin-top: 10px;"></div>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('#test-api-connection').on('click', function() {
				var button = $(this);
				var result = $('#api-test-result');
				
				button.prop('disabled', true).text('<?php echo esc_js( __( 'Testing...', 'v430-cf7-openai-spam' ) ); ?>');
				result.html('');
				
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'v430_test_openai_connection',
						nonce: '<?php echo wp_create_nonce( 'v430_test_openai' ); ?>'
					},
					success: function(response) {
						if (response.success) {
							result.html('<div class="notice notice-success inline"><p>' + response.data + '</p></div>');
						} else {
							result.html('<div class="notice notice-error inline"><p>' + response.data + '</p></div>');
						}
					},
					error: function() {
						result.html('<div class="notice notice-error inline"><p><?php echo esc_js( __( 'Connection test failed.', 'v430-cf7-openai-spam' ) ); ?></p></div>');
					},
					complete: function() {
						button.prop('disabled', false).text('<?php echo esc_js( __( 'Test API Connection', 'v430-cf7-openai-spam' ) ); ?>');
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Check if API key is valid and set.
	 *
	 * @return bool
	 */
	public static function is_api_key_valid() {
		$api_key = get_option( 'V430_OPENAI_API_KEY', '' );
		return ! empty( $api_key ) && str_starts_with( $api_key, 'sk-' );
	}

	/**
	 * Get masked API key for display.
	 *
	 * @return string
	 */
	public static function get_masked_api_key() {
		$api_key = get_option( 'V430_OPENAI_API_KEY', '' );
		
		if ( empty( $api_key ) ) {
			return '';
		}

		return substr( $api_key, 0, 7 ) . str_repeat( '*', max( 0, strlen( $api_key ) - 11 ) ) . substr( $api_key, -4 );
	}
}

// Add AJAX handler for API connection test
add_action( 'wp_ajax_v430_test_openai_connection', 'v430_handle_openai_connection_test' );

/**
 * Handle AJAX request for OpenAI connection test.
 */
function v430_handle_openai_connection_test() {
	// Verify nonce
	if ( ! wp_verify_nonce( $_POST['nonce'], 'v430_test_openai' ) ) {
		wp_die( __( 'Security check failed.', 'v430-cf7-openai-spam' ) );
	}

	// Check user capabilities
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'You do not have sufficient permissions.', 'v430-cf7-openai-spam' ) );
	}

	$api_key = get_option( 'V430_OPENAI_API_KEY', '' );
	
	if ( empty( $api_key ) ) {
		wp_send_json_error( __( 'No API key configured.', 'v430-cf7-openai-spam' ) );
	}

	// Test API connection with a simple request
	$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
		'timeout' => 10,
		'headers' => array(
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
		),
		'body' => json_encode( array(
			'model' => 'gpt-4o-mini',
			'messages' => array(
				array(
					'role' => 'user',
					'content' => 'test'
				)
			),
			'max_tokens' => 1
		) )
	) );

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( 
			sprintf(
				/* translators: %s: error message */
				__( 'Connection failed: %s', 'v430-cf7-openai-spam' ),
				$response->get_error_message()
			)
		);
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	
	if ( $response_code === 200 ) {
		wp_send_json_success( __( 'API connection successful!', 'v430-cf7-openai-spam' ) );
	} elseif ( $response_code === 401 ) {
		wp_send_json_error( __( 'Invalid API key.', 'v430-cf7-openai-spam' ) );
	} else {
		$response_body = wp_remote_retrieve_body( $response );
		$error_data = json_decode( $response_body, true );
		$error_message = isset( $error_data['error']['message'] ) ? $error_data['error']['message'] : __( 'Unknown error', 'v430-cf7-openai-spam' );
		
		wp_send_json_error( 
			sprintf(
				/* translators: %1$d: HTTP status code, %2$s: error message */
				__( 'API error (%1$d): %2$s', 'v430-cf7-openai-spam' ),
				$response_code,
				$error_message
			)
		);
	}
}