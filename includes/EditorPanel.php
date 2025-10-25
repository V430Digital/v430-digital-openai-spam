<?php
/**
 * Editor Panel functionality for V430 CF7 OpenAI Spam Check plugin.
 * Adds the "OpenAi Spam Check" tab to Contact Form 7 editor.
 *
 * @package V430_CF7_OpenAI_Spam
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Editor Panel class for adding CF7 editor tab.
 */
class V430_CF7_OpenAI_EditorPanel {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->define_hooks();
	}

	/**
	 * Define editor panel hooks.
	 */
	private function define_hooks() {
		add_filter( 'wpcf7_editor_panels', array( $this, 'add_editor_panel' ) );
		add_action( 'wpcf7_save_contact_form', array( $this, 'save_form_settings' ) );
		add_filter( 'wpcf7_contact_form_properties', array( $this, 'ensure_form_properties' ), 10, 2 );
		add_filter( 'wpcf7_pre_construct_contact_form_properties', array( $this, 'ensure_form_properties' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
	}

	/**
	 * Add OpenAI spam check panel to CF7 editor.
	 *
	 * @param array $panels Existing editor panels.
	 * @return array Modified panels array.
	 */
	public function add_editor_panel( $panels ) {
		$panels['openai-spam-panel'] = array(
			'title'    => __( 'OpenAi Spam Check', 'v430-cf7-openai-spam' ),
			'callback' => array( $this, 'render_editor_panel' ),
		);

		return $panels;
	}

	/**
	 * Render the OpenAI spam check editor panel.
	 *
	 * @param WPCF7_ContactForm $contact_form The contact form object.
	 */
	public function render_editor_panel( $contact_form ) {
		$settings = $this->get_form_settings( $contact_form );
		$api_key_valid = V430_CF7_OpenAI_Admin::is_api_key_valid();
		
		?>
		<div class="v430-openai-panel">
			<?php if ( ! $api_key_valid ) : ?>
				<div class="notice notice-error inline v430-api-key-warning">
					<p>
						<strong><?php _e( 'OpenAI API Key Required', 'v430-cf7-openai-spam' ); ?></strong><br>
						<?php 
						printf(
							/* translators: %1$s: settings page link start, %2$s: link end */
							__( 'You need to configure your OpenAI API key in %1$ssettings%2$s before enabling spam detection.', 'v430-cf7-openai-spam' ),
							'<a href="' . admin_url( 'admin.php?page=v430-cf7-openai-settings' ) . '">',
							'</a>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="openai-enable">
								<?php _e( 'Spam Detection', 'v430-cf7-openai-spam' ); ?>
							</label>
						</th>
						<td>
							<label>
								<input 
									type="checkbox" 
									id="openai-enable" 
									name="v430-openai-enable" 
									value="1" 
									<?php checked( $settings['enable'], 1 ); ?>
									<?php disabled( ! $api_key_valid ); ?>
								/>
								<?php _e( 'Enable OpenAI Anti Spam', 'v430-cf7-openai-spam' ); ?>
							</label>
							<p class="description">
								<?php _e( 'Enable automatic spam detection using OpenAI for this contact form.', 'v430-cf7-openai-spam' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="openai-job-as-spam">
								<?php _e( 'Job Requests', 'v430-cf7-openai-spam' ); ?>
							</label>
						</th>
						<td>
							<label>
								<input 
									type="checkbox" 
									id="openai-job-as-spam" 
									name="v430-openai-job-as-spam" 
									value="1" 
									<?php checked( $settings['job_as_spam'], 1 ); ?>
									<?php disabled( ! $api_key_valid ); ?>
								/>
								<?php _e( 'Consider job requests as spam', 'v430-cf7-openai-spam' ); ?>
							</label>
							<p class="description">
								<?php _e( 'If enabled, messages classified as job requests will be marked as spam.', 'v430-cf7-openai-spam' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php if ( $api_key_valid ) : ?>
				<div class="notice notice-info inline">
					<p>
						<strong><?php _e( 'How it works:', 'v430-cf7-openai-spam' ); ?></strong><br>
						<?php _e( 'Form submissions are sent to OpenAI for classification into three categories: spam, job_request, or lead. Based on your settings above, spam and optionally job requests will be blocked.', 'v430-cf7-openai-spam' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="notice notice-warning inline">
				<p>
					<strong><?php _e( 'Privacy Notice:', 'v430-cf7-openai-spam' ); ?></strong><br>
					<?php _e( 'Form data will be sent to OpenAI for analysis. No data is stored by this plugin, but OpenAI may process the data according to their privacy policy.', 'v430-cf7-openai-spam' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Save form settings when the contact form is saved.
	 *
	 * @param WPCF7_ContactForm $contact_form The contact form object.
	 */
	public function save_form_settings( $contact_form ) {
		// Verify nonce for security
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'wpcf7-save-contact-form_' . $contact_form->id() ) ) {
			return;
		}

		$settings = array();

		// Save "Enable OpenAI Anti Spam" setting
		$settings['enable'] = isset( $_POST['v430-openai-enable'] ) ? 1 : 0;

		// Save "Consider job requests as spam" setting
		$settings['job_as_spam'] = isset( $_POST['v430-openai-job-as-spam'] ) ? 1 : 0;

		// Get existing form properties
		$properties = $contact_form->get_properties();
		$properties['_v430_openai_settings'] = $settings;
		
		// Save the updated properties
		$contact_form->set_properties( $properties );

		if ( V430_CF7_OPENAI_DEBUG ) {
			error_log( 'V430 CF7 OpenAI: Saved settings for form ID ' . $contact_form->id() . ': ' . print_r( $settings, true ) );
		}
	}

	/**
	 * Ensure form properties include our settings.
	 *
	 * @param array $properties Form properties.
	 * @param WPCF7_ContactForm $contact_form The contact form object.
	 * @return array Modified properties.
	 */
	public function ensure_form_properties( $properties, $contact_form = null ) {
		if ( ! isset( $properties['_v430_openai_settings'] ) ) {
			$properties['_v430_openai_settings'] = array(
				'enable' => 0,
				'job_as_spam' => 0,
			);
		}

		return $properties;
	}

	/**
	 * Get form settings for a specific contact form.
	 *
	 * @param WPCF7_ContactForm $contact_form The contact form object.
	 * @return array Form settings.
	 */
	private function get_form_settings( $contact_form ) {
		$default_settings = array(
			'enable' => 0,
			'job_as_spam' => 0,
		);

		$settings = $contact_form->prop( '_v430_openai_settings' );
		
		if ( ! is_array( $settings ) ) {
			$settings = $default_settings;
		} else {
			$settings = wp_parse_args( $settings, $default_settings );
		}

		return $settings;
	}

	/**
	 * Enqueue admin scripts and styles for the editor panel.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_admin_scripts( $hook_suffix ) {
		// Only load on CF7 admin pages
		if ( false === strpos( $hook_suffix, 'wpcf7' ) ) {
			return;
		}

		// Enqueue our admin CSS
		wp_enqueue_style(
			'v430-cf7-openai-admin',
			V430_CF7_OPENAI_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			V430_CF7_OPENAI_VERSION
		);

		// Add inline styles if CSS file doesn't exist
		$css_file = V430_CF7_OPENAI_PLUGIN_PATH . 'assets/css/admin.css';
		if ( ! file_exists( $css_file ) ) {
			$inline_css = '
				.v430-openai-panel .v430-api-key-warning {
					border-left-color: #dc3232 !important;
					background-color: #ffeaea;
				}
				.v430-openai-panel .form-table th {
					width: 200px;
				}
				.v430-openai-panel .notice.inline {
					margin: 15px 0;
					padding: 8px 12px;
				}
				.v430-openai-panel .notice.inline p {
					margin: 0.5em 0;
				}
			';
			wp_add_inline_style( 'wp-admin', $inline_css );
		}
	}

	/**
	 * Get form settings by form ID (static method for external use).
	 *
	 * @param int|WPCF7_ContactForm $form_id_or_object Form ID or contact form object.
	 * @return array Form settings.
	 */
	public static function get_settings( $form_id_or_object ) {
		$default_settings = array(
			'enable' => 0,
			'job_as_spam' => 0,
		);

		if ( is_numeric( $form_id_or_object ) ) {
			$contact_form = wpcf7_contact_form( $form_id_or_object );
		} else {
			$contact_form = $form_id_or_object;
		}

		if ( ! $contact_form ) {
			return $default_settings;
		}

		$settings = $contact_form->prop( '_v430_openai_settings' );
		
		if ( ! is_array( $settings ) ) {
			return $default_settings;
		}

		return wp_parse_args( $settings, $default_settings );
	}
}