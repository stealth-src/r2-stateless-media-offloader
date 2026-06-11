<?php
/**
 * Admin settings screen — dual credential model + Test Connection.
 *
 * @package R2Offload
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the settings screen, handles saves, and the AJAX connection test.
 */
class Admin_Settings {

	const PAGE_SLUG    = 'r2offload-settings';
	const NONCE_ACTION = 'r2offload_save_settings';
	const NONCE_FIELD  = 'r2offload_settings_nonce';
	const AJAX_ACTION  = 'r2offload_test_connection';

	/**
	 * Editable, non-secret settings shown as text inputs.
	 *
	 * @var string[]
	 */
	private $text_fields = array( 'account_id', 'access_key', 'bucket', 'custom_domain', 'cache_control', 'path_prefix' );

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook the admin menu, save handler, scripts, and AJAX endpoint.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_test_connection' ) );
		// Add a "Settings" link to the plugin's row on the Plugins screen.
		add_filter( 'plugin_action_links_' . plugin_basename( R2OFFLOAD_PLUGIN_FILE ), array( $this, 'add_action_links' ) );
	}

	/**
	 * Add Settings → R2 Offload.
	 */
	public function add_menu_page() {
		add_options_page(
			__( 'R2 Offload', 'r2-stateless-media-offloader' ),
			__( 'R2 Offload', 'r2-stateless-media-offloader' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Add a "Settings" action link next to Deactivate on wp-admin/plugins.php.
	 *
	 * @param string[] $links Existing plugin action links.
	 * @return string[]
	 */
	public function add_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'options-general.php' ) ) ),
			esc_html__( 'Settings', 'r2-stateless-media-offloader' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Persist settings on POST. Secrets and constant-locked fields are handled
	 * with care: a constant-locked field is never written to the DB, and the
	 * secret is only overwritten when a new value is actually submitted.
	 */
	public function maybe_save() {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$existing = get_option( Settings::OPTION_KEY, array() );
		$existing = is_array( $existing ) ? $existing : array();
		$new      = $existing;

		// Drop any key now locked by a wp-config constant so a stale DB copy
		// can't linger (or silently re-activate if the constant is removed).
		foreach ( array_merge( $this->text_fields, array( 'mode', 'secret_key' ) ) as $key ) {
			if ( $this->settings->is_constant( $key ) ) {
				unset( $new[ $key ] );
			}
		}

		foreach ( $this->text_fields as $key ) {
			if ( $this->settings->is_constant( $key ) ) {
				continue; // Locked by wp-config — never store.
			}
			// sanitize_text_field() returns '' for an array/object, so a crafted
			// array submission is handled safely without a warning. Inlined so the
			// ValidatedSanitizedInput sniff sees the unslash+sanitize together.
			$new[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- maybe_save() verifies the nonce before this runs.
		}

		// Mode (radio) — only the two known values; default to the safe on-ramp.
		if ( ! $this->settings->is_constant( 'mode' ) ) {
			$mode         = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'cdn';
			$new['mode'] = in_array( $mode, array( 'cdn', 'stateless' ), true ) ? $mode : 'cdn';
		}

		// Secret: only overwrite when a non-empty value is submitted, so the
		// "leave blank to keep" password field doesn't wipe a stored secret.
		// Stored as plaintext — use R2OFFLOAD_SECRET_KEY in wp-config.php for
		// environments that require the key to stay off the database.
		$raw_secret = isset( $_POST['secret_key'] ) ? wp_unslash( $_POST['secret_key'] ) : '';
		// Cast a crafted array submission to '' (don't warn), then trim — R2
		// keys are whitespace-free, so trimming only guards against an
		// accidentally-pasted leading/trailing space or newline.
		$submitted = is_string( $raw_secret ) ? trim( $raw_secret ) : '';
		if ( '' !== $submitted ) {
			$new['secret_key'] = $submitted;
		} elseif ( isset( $new['secret_key'] ) && 0 === strpos( (string) $new['secret_key'], 'r2enc:' ) ) {
			// Auto-migrate: a legacy encrypted blob is still sitting in the DB.
			// Use decrypt() directly — NOT get() — so constant values never bleed
			// into the DB.
			$migrated = $this->settings->decrypt( (string) $new['secret_key'] );
			if ( '' !== $migrated ) {
				// Decryption succeeded — store plaintext going forward.
				$new['secret_key'] = $migrated;
			} elseif ( defined( 'R2OFFLOAD_SECRET_KEY' ) && '' !== trim( (string) R2OFFLOAD_SECRET_KEY ) ) {
				// Decryption failed but the constant provides a usable credential.
				// Drop the undecryptable blob so is_constant() takes over cleanly
				// and secret_decrypt_failed() stops firing on future requests.
				// (A defined-but-empty constant is no fallback — keep the blob and
				// let the notice prompt a re-enter.)
				unset( $new['secret_key'] );
			}
			// Otherwise (no constant, decrypt failed) leave the blob — the admin
			// notice will prompt the user to re-enter the key.
		}

		// autoload = false: keep credentials (incl. the encrypted secret) out of
		// the autoloaded options cache that loads on every request. update_option's
		// autoload arg only takes effect when the value actually changes, so on a
		// no-op save it wouldn't flip an option that was somehow created with
		// autoload on. Enforce it explicitly where core supports it (WP 6.6+);
		// on older versions the first save created the row via add_option(...,
		// false), so it is already correct.
		update_option( Settings::OPTION_KEY, $new, false );
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( Settings::OPTION_KEY, false );
		}
		// Drop the Settings instance's memoised copy so any get() that runs after
		// this save (a hook on the redirect path, or if redirect headers were
		// already sent) reads the new values rather than the stale ones.
		$this->settings->flush_request_cache();

		// Store the success notice in a plugin-owned transient keyed by user so
		// it survives the redirect and is consumed exactly once. Deliberately
		// outside the WP settings_errors system: ?updated and ?settings-updated
		// both trigger core to add its own "Settings saved." to that queue,
		// producing a duplicate on every known WP version we've tested.
		set_transient( 'r2offload_settings_saved_' . get_current_user_id(), 1, 60 );

		wp_safe_redirect(
			add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'options-general.php' ) )
		);
		exit;
	}

	/**
	 * Enqueue the inline Test Connection script on our page only.
	 *
	 * @param string $hook_suffix
	 */
	public function enqueue( $hook_suffix ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_script( 'jquery' );
		$data = array(
			'action'  => self::AJAX_ACTION,
			'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
			'testing' => __( 'Testing…', 'r2-stateless-media-offloader' ),
			'failed'  => __( 'Connection test failed.', 'r2-stateless-media-offloader' ),
		);
		wp_add_inline_script( 'jquery', 'window.R2OFFLOAD=' . wp_json_encode( $data ) . ';' );
		wp_add_inline_script( 'jquery', $this->inline_js() );
	}

	/**
	 * The Test Connection client script.
	 *
	 * @return string
	 */
	private function inline_js() {
		return <<<'JS'
jQuery(function($){
	var $out = $('#r2offload-test-result');
	// Render a message as plain text — never as HTML — to avoid injecting
	// server-supplied content (e.g. an R2 error body) as markup.
	function show(cls, msg){
		$out.attr('class','notice ' + cls + ' inline').empty().append($('<p>').text(msg)).show();
	}

	// --- Test Connection against the values CURRENTLY in the form (unsaved) ---
	var $btn = $('#r2offload-test-connection');
	if ( $btn.length ) {
		$btn.on('click', function(e){
			e.preventDefault();
			$btn.prop('disabled', true);
			show('notice-info', R2OFFLOAD.testing);
			$.post(ajaxurl, {
				action: R2OFFLOAD.action,
				nonce: R2OFFLOAD.nonce,
				account_id: $('#r2offload_account_id').val() || '',
				access_key: $('#r2offload_access_key').val() || '',
				secret_key: $('#r2offload_secret_key').val() || '',
				bucket: $('#r2offload_bucket').val() || '',
				custom_domain: $('#r2offload_custom_domain').val() || ''
			})
				.done(function(res){
					var ok = res && res.success;
					var msg = (res && res.data && res.data.message) ? res.data.message : (ok ? 'OK' : R2OFFLOAD.failed);
					show(ok ? 'notice-success' : 'notice-error', msg);
				})
				.fail(function(){ show('notice-error', R2OFFLOAD.failed); })
				.always(function(){ $btn.prop('disabled', false); });
		});
	}

	// --- Revert unsaved changes to the R2 CREDENTIAL fields only ---
	var $revert = $('#r2offload-revert');
	if ( $revert.length ) {
		var $cred = $('#r2offload_account_id, #r2offload_access_key, #r2offload_secret_key, #r2offload_bucket');
		var snap = {};
		$cred.each(function(){ snap[this.id] = this.value; });
		function credDirty(){
			var d = false;
			$cred.each(function(){ if ( (this.value || '') !== (snap[this.id] || '') ) { d = true; } });
			return d;
		}
		function refreshRevert(){ $revert.toggle( credDirty() ); }
		$cred.on('input change', refreshRevert);
		$revert.on('click', function(e){
			e.preventDefault();
			$cred.each(function(){ this.value = ( snap[this.id] || '' ); });
			$out.hide();
			refreshRevert();
		});
		refreshRevert(); // Hidden initially (credentials unchanged).
	}
});
JS;
	}

	/**
	 * AJAX: test the R2 credentials currently in the form — which may be UNSAVED —
	 * so the user can verify before saving. Nothing is persisted.
	 */
	public function ajax_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'r2-stateless-media-offloader' ) ), 403 );
			return; // wp_send_json_error already exits; explicit for static analysis.
		}
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		// Carry the posted (possibly unsaved) form values as overrides on a
		// THROWAWAY Settings instance. Fields not posted — and a blank secret (the
		// password field is empty when unchanged) — fall back to the saved value;
		// wp-config constants still win. Persists nothing.
		$overrides = array();
		foreach ( array( 'account_id', 'access_key', 'bucket', 'custom_domain' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$overrides[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer() above verifies the nonce.
			}
		}
		// Secret: only override when a value was actually typed; trim only (a secret
		// is opaque — never run it through sanitize_text_field), and never echo/store it.
		if ( isset( $_POST['secret_key'] ) ) {
			$raw    = wp_unslash( $_POST['secret_key'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above. WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- opaque secret, trimmed below, never echoed/stored.
			$secret = is_string( $raw ) ? trim( $raw ) : '';
			if ( '' !== $secret ) {
				$overrides['secret_key'] = $secret;
			}
		}

		$probe = new Settings();
		$probe->set_overrides( $overrides );

		if ( ! $probe->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Enter your Account ID, Access Key ID, Secret Access Key and Bucket, then test.', 'r2-stateless-media-offloader' ) ) );
			return; // wp_send_json_error already exits; explicit for static analysis.
		}

		$result = ( new R2_Client( $probe ) )->test_connection();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return; // wp_send_json_error already exits; explicit for static analysis.
		}
		wp_send_json_success( array( 'message' => __( 'Connected to R2 successfully.', 'r2-stateless-media-offloader' ) ) );
	}

	/**
	 * Render the settings page. Exposes $settings to the template.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = $this->settings; // Used by the template include.
		$nonce_action = self::NONCE_ACTION;
		$nonce_field  = self::NONCE_FIELD;
		require R2OFFLOAD_PLUGIN_DIR . 'templates/settings-page.php';
	}
}
