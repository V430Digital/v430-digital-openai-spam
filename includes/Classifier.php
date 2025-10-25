<?php
/**
 * OpenAI Classifier for V430 CF7 OpenAI Spam Check plugin.
 * Handles communication with OpenAI API for spam classification.
 *
 * @package V430_CF7_OpenAI_Spam
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * OpenAI Classifier class.
 */
class V430_CF7_OpenAI_Classifier {

	/**
	 * OpenAI API endpoint for chat completions.
	 */
	const API_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

	/**
	 * Default model to use.
	 */
	const DEFAULT_MODEL = 'gpt-4o-mini';

	/**
	 * Request timeout in seconds.
	 */
	const REQUEST_TIMEOUT = 10;

	/**
	 * Maximum retry attempts.
	 */
	const MAX_RETRIES = 1;

	/**
	 * Valid classification labels.
	 */
	const VALID_LABELS = array( 'spam', 'job_request', 'lead' );

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Constructor is intentionally empty
	}

	/**
	 * Classify form data using OpenAI.
	 *
	 * @param array $fields Associative array of form field data.
	 * @return string|WP_Error Classification result ('spam', 'job_request', 'lead') or WP_Error on failure.
	 */
	public function classify( $fields ) {
		// Validate input
		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return new WP_Error( 'invalid_input', __( 'Invalid or empty field data provided.', 'v430-cf7-openai-spam' ) );
		}

		// Get API key
		$api_key = get_option( 'V430_OPENAI_API_KEY', '' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'OpenAI API key not configured.', 'v430-cf7-openai-spam' ) );
		}

		// Prepare message content
		$message_content = $this->prepare_message_content( $fields );
		if ( empty( $message_content ) ) {
			return new WP_Error( 'empty_content', __( 'No content to classify.', 'v430-cf7-openai-spam' ) );
		}

		// Build the complete prompt with the exact specification from instructions
		$prompt = $this->build_prompt( $message_content );

		// Make API request with retry logic
		$response = $this->make_api_request( $api_key, $prompt );
		
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Parse and validate response
		$classification = $this->parse_response( $response );
		
		if ( is_wp_error( $classification ) ) {
			return $classification;
		}

		return $classification;
	}

	/**
	 * Prepare message content from form fields.
	 *
	 * @param array $fields Form field data.
	 * @return string Formatted message content.
	 */
	private function prepare_message_content( $fields ) {
		$content_parts = array();

		foreach ( $fields as $field_name => $field_value ) {
			if ( empty( $field_value ) ) {
				continue;
			}

			// Clean and format field data
			$clean_value = $this->clean_field_value( $field_value );
			if ( ! empty( $clean_value ) ) {
				$content_parts[] = $field_name . ': ' . $clean_value;
			}
		}

		return implode( "\n", $content_parts );
	}

	/**
	 * Clean field value for processing.
	 *
	 * @param mixed $value Field value.
	 * @return string Cleaned value.
	 */
	private function clean_field_value( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ', ', $value );
		}

		// Convert to string and sanitize
		$value = (string) $value;
		$value = sanitize_textarea_field( $value );
		
		// Remove excessive whitespace
		$value = preg_replace( '/\s+/', ' ', $value );
		$value = trim( $value );

		return $value;
	}

	/**
	 * Build the complete prompt as specified in instructions.
	 *
	 * @param string $message_content The prepared message content.
	 * @return string Complete prompt.
	 */
	private function build_prompt( $message_content ) {
		// This is the EXACT prompt from the instructions - DO NOT MODIFY
		$prompt = "[ROLE] You are a text classifier.
[CONTEXT] You receive a generic text and must label it.
[GOAL] Return only one of these three labels: spam, job_request, lead.
[CONSTRAINTS] No explanation, no extra text, no symbols.
[OUTPUT SPEC] Output exactly one lowercase word from the allowed labels.
[QUALITY BAR] Output is valid only if it matches one of the 3 labels.
[FAIL-SAFES] If ambiguous → choose the most plausible label without explanation.
[INPUT TEXT]
" . $message_content;

		return $prompt;
	}

	/**
	 * Make API request to OpenAI with retry logic.
	 *
	 * @param string $api_key The OpenAI API key.
	 * @param string $prompt The complete prompt.
	 * @return array|WP_Error API response or error.
	 */
	private function make_api_request( $api_key, $prompt ) {
		$model = apply_filters( 'v430_cf7_openai_model', self::DEFAULT_MODEL );
		
		$request_body = array(
			'model' => $model,
			'messages' => array(
				array(
					'role' => 'user',
					'content' => $prompt
				)
			),
			'max_tokens' => 10, // We only need a single word response
			'temperature' => 0, // Deterministic responses
		);

		$request_args = array(
			'timeout' => self::REQUEST_TIMEOUT,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type' => 'application/json',
			),
			'body' => json_encode( $request_body ),
		);

		// Attempt request with retry logic
		$attempts = 0;
		$max_attempts = self::MAX_RETRIES + 1;

		while ( $attempts < $max_attempts ) {
			$attempts++;

			if ( V430_CF7_OPENAI_DEBUG ) {
				error_log( "V430 CF7 OpenAI: Making API request (attempt {$attempts}/{$max_attempts})" );
			}

			$response = wp_remote_post( self::API_ENDPOINT, $request_args );

			// Handle network errors
			if ( is_wp_error( $response ) ) {
				if ( V430_CF7_OPENAI_DEBUG ) {
					error_log( 'V430 CF7 OpenAI: Network error: ' . $response->get_error_message() );
				}

				// Retry on timeout or network errors
				if ( $attempts < $max_attempts && in_array( $response->get_error_code(), array( 'http_request_failed', 'timeout' ) ) ) {
					sleep( 1 ); // Brief backoff
					continue;
				}

				return new WP_Error( 'network_error', 
					sprintf(
						/* translators: %s: error message */
						__( 'Network error: %s', 'v430-cf7-openai-spam' ),
						$response->get_error_message()
					)
				);
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );

			// Handle successful response
			if ( $response_code >= 200 && $response_code < 300 ) {
				if ( V430_CF7_OPENAI_DEBUG ) {
					error_log( 'V430 CF7 OpenAI: Successful API response' );
				}
				return json_decode( $response_body, true );
			}

			// Handle rate limiting (429) - retry once
			if ( $response_code === 429 && $attempts < $max_attempts ) {
				if ( V430_CF7_OPENAI_DEBUG ) {
					error_log( 'V430 CF7 OpenAI: Rate limited, retrying...' );
				}
				sleep( 2 ); // Longer backoff for rate limiting
				continue;
			}

			// Handle other HTTP errors
			$error_data = json_decode( $response_body, true );
			$error_message = isset( $error_data['error']['message'] ) ? $error_data['error']['message'] : __( 'Unknown API error', 'v430-cf7-openai-spam' );

			if ( V430_CF7_OPENAI_DEBUG ) {
				error_log( "V430 CF7 OpenAI: API error ({$response_code}): {$error_message}" );
			}

			return new WP_Error( 'api_error',
				sprintf(
					/* translators: %1$d: HTTP status code, %2$s: error message */
					__( 'API error (%1$d): %2$s', 'v430-cf7-openai-spam' ),
					$response_code,
					$error_message
				)
			);
		}

		// This should never be reached, but just in case
		return new WP_Error( 'max_retries_exceeded', __( 'Maximum retry attempts exceeded.', 'v430-cf7-openai-spam' ) );
	}

	/**
	 * Parse and validate the API response.
	 *
	 * @param array $response The decoded API response.
	 * @return string|WP_Error Classification result or error.
	 */
	private function parse_response( $response ) {
		// Validate response structure
		if ( ! isset( $response['choices'] ) || ! is_array( $response['choices'] ) || empty( $response['choices'] ) ) {
			if ( V430_CF7_OPENAI_DEBUG ) {
				error_log( 'V430 CF7 OpenAI: Invalid response structure: ' . print_r( $response, true ) );
			}
			return new WP_Error( 'invalid_response', __( 'Invalid API response structure.', 'v430-cf7-openai-spam' ) );
		}

		$first_choice = $response['choices'][0];
		if ( ! isset( $first_choice['message']['content'] ) ) {
			if ( V430_CF7_OPENAI_DEBUG ) {
				error_log( 'V430 CF7 OpenAI: Missing content in response: ' . print_r( $first_choice, true ) );
			}
			return new WP_Error( 'missing_content', __( 'Missing content in API response.', 'v430-cf7-openai-spam' ) );
		}

		$classification = trim( strtolower( $first_choice['message']['content'] ) );

		// Strict validation: only accept exact matches
		if ( ! in_array( $classification, self::VALID_LABELS, true ) ) {
			if ( V430_CF7_OPENAI_DEBUG ) {
				error_log( "V430 CF7 OpenAI: Invalid classification '{$classification}', expected one of: " . implode( ', ', self::VALID_LABELS ) );
			}
			return new WP_Error( 'invalid_classification', 
				sprintf(
					/* translators: %s: received classification */
					__( 'Invalid classification received: %s', 'v430-cf7-openai-spam' ),
					$classification
				)
			);
		}

		if ( V430_CF7_OPENAI_DEBUG ) {
			error_log( "V430 CF7 OpenAI: Valid classification: {$classification}" );
		}

		return $classification;
	}

	/**
	 * Test API connectivity (static method for admin use).
	 *
	 * @param string $api_key The API key to test.
	 * @return bool|WP_Error True if connection successful, WP_Error otherwise.
	 */
	public static function test_connection( $api_key = null ) {
		if ( null === $api_key ) {
			$api_key = get_option( 'V430_OPENAI_API_KEY', '' );
		}

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'No API key provided.', 'v430-cf7-openai-spam' ) );
		}

		$classifier = new self();
		$test_fields = array( 'test' => 'test connection' );
		
		$result = $classifier->classify( $test_fields );
		
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}
}