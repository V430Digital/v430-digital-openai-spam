<?php
/**
 * Plugin Name: V430 CF7 OpenAI Spam Check
 * Plugin URI: https://v430.it
 * Description: Classifies Contact Form 7 messages via OpenAI and blocks spam automatically.
 * Version: 1.0.1
 * Author: V430 Digital
 * Author URI: https://v430.it
 * Text Domain: v430-cf7-openai-spam
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Plugin version.
 */
define( 'V430_CF7_OPENAI_VERSION', '1.0.0' );

/**
 * Plugin constants.
 */
define( 'V430_CF7_OPENAI_PLUGIN_FILE', __FILE__ );
define( 'V430_CF7_OPENAI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'V430_CF7_OPENAI_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'V430_CF7_OPENAI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Debug constant (false by default).
 */
if ( ! defined( 'V430_CF7_OPENAI_DEBUG' ) ) {
	define( 'V430_CF7_OPENAI_DEBUG', false );
}

/**
 * Main plugin class.
 */
class V430_CF7_OpenAI_Spam {
	
	/**
	 * Instance of this class.
	 *
	 * @var V430_CF7_OpenAI_Spam
	 */
	private static $instance = null;

	/**
	 * Admin class instance.
	 *
	 * @var V430_CF7_OpenAI_Admin
	 */
	private $admin;

	/**
	 * Editor Panel class instance.
	 *
	 * @var V430_CF7_OpenAI_EditorPanel
	 */
	private $editor_panel;

	/**
	 * Classifier class instance.
	 *
	 * @var V430_CF7_OpenAI_Classifier
	 */
	private $classifier;

	/**
	 * Get the singleton instance.
	 *
	 * @return V430_CF7_OpenAI_Spam
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->define_hooks();
	}

	/**
	 * Load the required dependencies.
	 */
	private function load_dependencies() {
		require_once V430_CF7_OPENAI_PLUGIN_PATH . 'includes/Admin.php';
		require_once V430_CF7_OPENAI_PLUGIN_PATH . 'includes/EditorPanel.php';
		require_once V430_CF7_OPENAI_PLUGIN_PATH . 'includes/Classifier.php';
	}

	/**
	 * Define the core hooks.
	 */
	private function define_hooks() {
		// Activation hook
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		
		// Initialization
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		
		// Check CF7 dependency
		add_action( 'admin_notices', array( $this, 'check_cf7_dependency' ) );
		
		// Initialize components only if CF7 is active
		if ( $this->is_cf7_active() ) {
			$this->admin = new V430_CF7_OpenAI_Admin();
			$this->editor_panel = new V430_CF7_OpenAI_EditorPanel();
			$this->classifier = new V430_CF7_OpenAI_Classifier();
			
			// Hook into CF7 spam detection
			add_filter( 'wpcf7_spam', array( $this, 'check_spam' ), 10, 2 );
		}
	}

	/**
	 * Plugin activation.
	 */
	public function activate() {
		// Nothing to do on activation
	}

	/**
	 * Initialize the plugin.
	 */
	public function init() {
		// Plugin initialization
	}

	/**
	 * Load plugin textdomain.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 
			'v430-cf7-openai-spam', 
			false, 
			dirname( V430_CF7_OPENAI_PLUGIN_BASENAME ) . '/languages' 
		);
	}

	/**
	 * Check if Contact Form 7 is active.
	 *
	 * @return bool
	 */
	private function is_cf7_active() {
		return is_plugin_active( 'contact-form-7/wp-contact-form-7.php' ) || 
			   function_exists( 'wpcf7' );
	}

	/**
	 * Display admin notice if CF7 is not active.
	 */
	public function check_cf7_dependency() {
		if ( ! current_user_can( 'activate_plugins' ) || $this->is_cf7_active() ) {
			return;
		}

		echo '<div class="notice notice-error is-dismissible">';
		echo '<p>' . sprintf(
			/* translators: %1$s: Contact Form 7 link start, %2$s: link end, %3$s: plugin name */
			__( '%1$sContact Form 7%2$s is required for %3$s to work properly.', 'v430-cf7-openai-spam' ),
			'<a href="https://wordpress.org/plugins/contact-form-7/" target="_blank">',
			'</a>',
			'<strong>V430 CF7 OpenAI Spam Check</strong>'
		) . '</p>';
		echo '</div>';
	}

	/**
	 * Hook into CF7 spam detection.
	 *
	 * This method uses a "fail-open" approach: if any error occurs during classification
	 * (API errors, network issues, invalid responses, etc.), the submission is treated as
	 * NOT SPAM to avoid blocking legitimate form submissions. All errors are logged to
	 * WordPress error log for monitoring and debugging.
	 *
	 * @param bool $spam Current spam status.
	 * @param WPCF7_Submission $submission The submission object.
	 * @return bool Updated spam status.
	 */
	public function check_spam( $spam, $submission = null ) {
		// If already marked as spam by other filters, keep it that way
		if ( $spam ) {
			return $spam;
		}

		// Fail-open: if no API key set, don't mark as spam
		$api_key = get_option( 'V430_OPENAI_API_KEY', '' );
		if ( empty( $api_key ) ) {
			if ( V430_CF7_OPENAI_DEBUG ) {
				error_log( 'V430 CF7 OpenAI: No API key set, skipping spam check' );
			}
			return $spam;
		}

		// Get the contact form
		$contact_form = null;
		if ( $submission && method_exists( $submission, 'get_contact_form' ) ) {
			$contact_form = $submission->get_contact_form();
		} else {
			// Fallback: try to get current form
			$contact_form = wpcf7_get_current_contact_form();
		}

		if ( ! $contact_form ) {
			if ( V430_CF7_OPENAI_DEBUG ) {
				error_log( 'V430 CF7 OpenAI: Could not get contact form, skipping spam check' );
			}
			return $spam;
		}

		// Check if OpenAI spam check is enabled for this form
		$form_settings = $contact_form->prop( '_v430_openai_settings' );
		if ( empty( $form_settings['enable'] ) ) {
			if ( V430_CF7_OPENAI_DEBUG ) {
				error_log( 'V430 CF7 OpenAI: Spam check not enabled for form ID ' . $contact_form->id() );
			}
			return $spam;
		}

		// Get form data
		$form_data = $this->get_form_data( $contact_form );
		if ( empty( $form_data ) ) {
			if ( V430_CF7_OPENAI_DEBUG ) {
				error_log( 'V430 CF7 OpenAI: No form data found, skipping spam check' );
			}
			return $spam;
		}

		// Classify the submission
		$classification = $this->classifier->classify( $form_data );
		
		// Handle classification result
		if ( is_wp_error( $classification ) ) {
			// Always log errors to WordPress error log, regardless of debug mode
			error_log( 
				sprintf(
					'V430 CF7 OpenAI Spam Check - Classification Error [Form ID: %d]: %s (Code: %s)',
					$contact_form->id(),
					$classification->get_error_message(),
					$classification->get_error_code()
				)
			);
			
			// Additional debug info if debug mode is enabled
			if ( V430_CF7_OPENAI_DEBUG ) {
				error_log( 'V430 CF7 OpenAI: Full error data: ' . print_r( $classification->get_error_data(), true ) );
			}
			
			// Fail-open: treat as not spam to allow form submission
			return $spam;
		}

		// Apply spam logic based on classification and settings
		if ( 'spam' === $classification ) {
			if ( V430_CF7_OPENAI_DEBUG ) {
				error_log( 'V430 CF7 OpenAI: Classified as spam for form ID ' . $contact_form->id() );
			}
			return true;
		}

		if ( 'job_request' === $classification && ! empty( $form_settings['job_as_spam'] ) ) {
			if ( V430_CF7_OPENAI_DEBUG ) {
				error_log( 'V430 CF7 OpenAI: Classified as job request (marked as spam) for form ID ' . $contact_form->id() );
			}
			return true;
		}

		if ( V430_CF7_OPENAI_DEBUG ) {
			error_log( 'V430 CF7 OpenAI: Classified as ' . $classification . ' (not spam) for form ID ' . $contact_form->id() );
		}

		return $spam; // Not spam
	}

	/**
	 * Get form data from submission.
	 *
	 * @param WPCF7_ContactForm $contact_form The contact form.
	 * @return array Form field data.
	 */
	private function get_form_data( $contact_form ) {
		$data = array();
		
		$tags = $contact_form->scan_form_tags();
		foreach ( $tags as $tag ) {
			if ( empty( $tag->name ) || in_array( $tag->basetype, array( 'submit', 'file' ) ) ) {
				continue;
			}

			$value = isset( $_POST[ $tag->name ] ) ? $_POST[ $tag->name ] : '';
			
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'sanitize_text_field', $value ) );
			} else {
				$value = sanitize_textarea_field( $value );
			}

			if ( ! empty( $value ) ) {
				$data[ $tag->name ] = $value;
			}
		}

		return $data;
	}
}

/**
 * Initialize the plugin.
 */
function v430_cf7_openai_spam_init() {
	V430_CF7_OpenAI_Spam::get_instance();
}

// Start the plugin
add_action( 'plugins_loaded', 'v430_cf7_openai_spam_init' );