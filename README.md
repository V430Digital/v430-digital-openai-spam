# V430 CF7 OpenAI Spam Check

A WordPress plugin that uses OpenAI to classify Contact Form 7 submissions and automatically block spam.

## Features

- **AI-Powered Spam Detection**: Uses OpenAI's GPT-4o-mini model to classify form submissions
- **Three-Category Classification**: Classifies submissions as `spam`, `job_request`, or `lead`
- **Configurable Blocking**: Optionally treat job requests as spam
- **Fail-Open Design**: If API fails, submissions are NOT blocked (reliability first)
- **Per-Form Settings**: Enable/disable spam detection for individual contact forms
- **Privacy Focused**: No data stored locally, only sent to OpenAI for analysis
- **WordPress Security**: Proper nonce verification, capability checks, and input sanitization

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Contact Form 7 plugin (active)
- OpenAI API key

## Installation

1. Upload the plugin files to `/wp-content/plugins/v430-cf7-openai-spam/`
2. Activate the plugin through the WordPress admin
3. Go to **Contact > OpenAI Anti Spam** to configure your API key
4. Enable spam detection on individual contact forms

## Configuration

### 1. API Key Setup

1. Get an OpenAI API key from [OpenAI Platform](https://platform.openai.com/api-keys)
2. Go to **Contact > OpenAI Anti Spam** in WordPress admin
3. Enter your API key (starts with `sk-`)
4. Click "Test API Connection" to verify it works

### 2. Form-Level Settings

For each Contact Form 7 form:

1. Edit the contact form
2. Click the **"OpenAi Spam Check"** tab
3. Check **"Enable OpenAI Anti Spam"** to activate
4. Optionally check **"Consider job requests as spam"** if you want to block job-related inquiries

## How It Works

1. When a form is submitted, the plugin collects all field data
2. Data is sent to OpenAI with a specific classification prompt
3. OpenAI returns one of three labels: `spam`, `job_request`, or `lead`
4. Based on your settings:
   - `spam` → Always blocked
   - `job_request` → Blocked only if "Consider job requests as spam" is enabled
   - `lead` → Never blocked

## Privacy & Security

- **No Local Storage**: Form data is only sent to OpenAI, never stored by this plugin
- **Fail-Open Behavior**: If OpenAI API fails, submissions are processed normally (not blocked)
- **Minimal Data**: Only form field names and values are sent to OpenAI
- **WordPress Security**: All inputs sanitized, outputs escaped, proper nonce verification

## Debugging

Enable debug mode by adding this to your `wp-config.php`:

```php
define( 'V430_CF7_OPENAI_DEBUG', true );
```

Debug messages will be logged to your WordPress error log.

## API Usage & Costs

- Uses OpenAI's `gpt-4o-mini` model (cost-effective)
- Each form submission = 1 API call
- Minimal token usage (form data + short prompt)
- Failed requests are retried once for timeouts/rate limits

## Troubleshooting

### Plugin Not Working
- Ensure Contact Form 7 is active
- Check that your API key is valid and starts with `sk-`
- Test API connection in settings

### Forms Not Being Checked
- Verify "Enable OpenAI Anti Spam" is checked for the specific form
- Check WordPress error logs if debug mode is enabled

### API Errors
- Verify API key hasn't expired
- Check OpenAI account has sufficient credits
- Network connectivity issues usually resolve automatically

## Technical Details

### WordPress Hooks Used
- `wpcf7_spam` - Spam detection filter
- `wpcf7_editor_panels` - Adds editor tab
- `wpcf7_save_contact_form` - Saves form settings
- `admin_menu` - Adds settings page

### Classification Prompt
The plugin uses this exact prompt sent to OpenAI:

```
[ROLE] You are a text classifier.
[CONTEXT] You receive a generic text and must label it.
[GOAL] Return only one of these three labels: spam, job_request, lead.
[CONSTRAINTS] No explanation, no extra text, no symbols.
[OUTPUT SPEC] Output exactly one lowercase word from the allowed labels.
[QUALITY BAR] Output is valid only if it matches one of the 3 labels.
[FAIL-SAFES] If ambiguous → choose the most plausible label without explanation.
[INPUT TEXT]
{form_data}
```

## Developer Hooks

### Filters

- `v430_cf7_openai_model` - Change OpenAI model (default: `gpt-4o-mini`)

Example:
```php
add_filter( 'v430_cf7_openai_model', function() {
    return 'gpt-4o'; // Use full GPT-4o model
});
```

## Support

For technical support or feature requests, contact V430 Digital at https://v430.it

## License

GPL v2 or later

## Changelog

### 1.0.0
- Initial release
- OpenAI GPT-4o-mini integration
- Three-category classification (spam/job_request/lead)
- Per-form enable/disable settings
- Fail-open error handling
- WordPress admin integration