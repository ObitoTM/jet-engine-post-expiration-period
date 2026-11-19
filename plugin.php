<?php

// If this file is called directly, abort.
use Jet_Form_Builder\Actions\Action_Handler;
use Jet_Form_Builder\Actions\Types\Base;

if ( ! defined( 'WPINC' ) ) {
	die();
}

class Jet_Engine_Post_PE_Lock_Exception extends RuntimeException {}

class Jet_Engine_Post_PE {
	/**
	 * Instance.
	 *
	 * Holds the plugin instance.
	 *
	 * @since 1.0.0
	 * @access public
	 * @static
	 *
	 * @var Jet_Engine_Post_PE
	 */
	public static $instance = null;

	public $can_init = false;

	public $is_valid_period = false;

	public $daily_event = 'jet-engine-pep/daily_check_expirations';
	public $period_meta_key = '_jet_pep_period';
	private $calendar_meta_key = '_jet_pep_calendar_period';
	private $expiration_locks = array();
	public $action_meta_key = '_jet_pep_action';

	public $expired_posts;
	private $pending_expirations = array();
	private $pending_legacy_expirations = array();

	public function __construct() {
		$this->can_init = $this->can_init();
		$this->init();
	}

	public function can_init() {
		return function_exists( 'jet_engine' ) || function_exists( 'jet_form_builder' );
	}

	public function init() {
		if ( ! $this->can_init ) {
			return;
		}

		$this->hooks();

		add_action( 'init', function () {

			if ( ! function_exists( 'jet_engine' ) ) {
				return;
			}

			$pathinfo = pathinfo( JET_ENGINE_POST_EP_PLUGIN_BASE );

			jet_engine()->modules->updater->register_plugin( array(
				'slug'    => $pathinfo['filename'],
				'file'    => JET_ENGINE_POST_EP_PLUGIN_BASE,
				'version' => JET_ENGINE_POST_EP_VERSION,
			) );

		}, 12 );
	}

	public function hooks() {
		add_action( 'init', array( $this, 'set_daily_cron' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_expiration_meta_box' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save_expiration_meta_box' ) );
		add_action( 'admin_notices', array( $this, 'expiration_admin_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'post_editor_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_expiration_rest_field' ) );
		add_action( 'jet-form-builder/modifier/after-run', array( $this, 'after_post_modifier' ) );
		// Older JFB also runs actions in payment callbacks, outside the form response.
		add_action( 'jet-form-builder/after-trigger-event', array( $this, 'after_form_send' ) );
		add_action( 'jet-form-builder/gateways/before-send', array( $this, 'after_gateway_actions' ), 10, 2 );
		add_action( 'jet-form-builder/form-handler/after-send', array( $this, 'after_form_send' ) );
		add_action(
			'jet-engine/forms/booking/notifications/fields-after',
			array( $this, 'add_fields_to_insert_post' )
		);
		add_action(
			'jet-engine/forms/notifications/after-post-insert',
			array( $this, 'handle_post_action' ),
			1, 2
		);
		add_action(
			$this->daily_event,
			array( $this, 'on_daily_check_expirations' )
		);
		add_action(
			'jet-form-builder/editor-assets/before',
			array( $this, 'jet_form_builder_assets' )
		);

		add_action(
			'jet-form-builder/action/after-post-insert',
			array( $this, 'handle_post_action_form_builder' ), 10, 3
		);
		add_action(
			'jet-form-builder/action/after-post-update',
			array( $this, 'handle_post_update_form_builder' ), 10, 3
		);
	}

	public function handle_post_update_form_builder( $action_instance, $action_handler, $post_action = null ) {
		// Existing forms remain creation-only unless updates are explicitly enabled.
		if ( 'create_update' !== ( $action_instance->settings['expiration_apply_on'] ?? 'create' ) ) {
			if ( $this->pending_legacy_expirations ) {
				$this->flush_pending_legacy_expiration( $action_handler->get_inserted_post_id( $action_instance->_id ) );
			}
			return;
		}
		$this->handle_post_action_form_builder( $action_instance, $action_handler, $post_action );
	}

	/**
	 * @param Base $action_instance
	 * @param Action_Handler $action_handler
	 */
	public function handle_post_action_form_builder( $action_instance, $action_handler, $post_action = null ) {
		$settings = $action_instance->settings;
		if ( empty( $settings['enable_expiration_period'] ) && ! $this->pending_legacy_expirations ) {
			return;
		}

		$post_id = $post_action && method_exists( $post_action, 'get_inserted_id' )
			? $post_action->get_inserted_id()
			: $action_handler->get_inserted_post_id( $action_instance->_id );
		// A preceding action has finished writing its mapped meta at this point.
		$this->flush_pending_legacy_expiration( $post_id );
		if ( empty( $settings['enable_expiration_period'] ) ) {
			return;
		}
		$request = function_exists( 'jet_fb_context' )
			? jet_fb_context()->resolve_request()
			: (array) $action_handler->request_data;

		// JFB writes mapped meta after its insert/update action hook fires.
		if ( 'post_meta' === ( $settings['expiration_source'] ?? 'fixed' ) ) {
			$args = array( $settings, $request, $post_id );
			if ( $post_action ) {
				$this->pending_expirations[ spl_object_hash( $post_action ) ] = $args;
			} else {
				// Older JFB has neither the third argument nor modifier/after-run.
				$this->pending_legacy_expirations[ $post_id ] = $args;
			}
			return;
		}
		$this->apply_expiration_settings( $settings, $request, $post_id );
	}

	private function flush_pending_legacy_expiration( $post_id ) {
		if ( ! isset( $this->pending_legacy_expirations[ $post_id ] ) ) {
			return;
		}
		$args = $this->pending_legacy_expirations[ $post_id ];
		unset( $this->pending_legacy_expirations[ $post_id ] );
		$this->apply_expiration_settings( $args[0], $args[1], $args[2] );
	}

	public function after_post_modifier( $modifier ) {
		$action = $modifier->get_action();
		$key = spl_object_hash( $action );
		if ( ! isset( $this->pending_expirations[ $key ] ) ) {
			return;
		}
		$args = $this->pending_expirations[ $key ];
		unset( $this->pending_expirations[ $key ] );
		$this->apply_expiration_settings( $args[0], $args[1], $args[2] );
	}

	public function after_form_send() {
		$pending = $this->pending_legacy_expirations;
		$this->pending_legacy_expirations = array();
		$error = null;
		foreach ( $pending as $args ) {
			try {
				$this->apply_expiration_settings( $args[0], $args[1], $args[2] );
			} catch ( \Jet_Form_Builder\Exceptions\Action_Exception $exception ) {
				$error = $error ?: $exception;
			}
		}
		if ( $error ) {
			throw $error;
		}
	}

	public function after_gateway_actions( $status, $action_error ) {
		try {
			$this->after_form_send();
		} catch ( \Jet_Form_Builder\Exceptions\Action_Exception $exception ) {
			if ( $action_error ) {
				return;
			}
			$filter = function ( $args, $response ) use ( &$filter, $exception ) {
				remove_filter( 'jet-fb/response-handler/query-args', $filter );
				$status_info = new \Jet_Form_Builder\Form_Messages\Status_Info( $response->args['status'] );
				if ( $status_info->is_success() ) {
					$args['status'] = $response->manager->parse_status( $exception->get_form_status() );
				}
				return $args;
			};
			add_filter( 'jet-fb/response-handler/query-args', $filter, 10, 2 );
		}
	}

	public function jet_form_builder_assets() {
		$this->admin_assets( 'post.php' );
		wp_enqueue_script(
			'jet-engine-pep',
			$this->plugin_url( 'assets/js/builder.editor.js' ),
			array( 'wp-hooks', 'wp-i18n', 'wp-element', 'wp-components' ),
			JET_ENGINE_POST_EP_VERSION,
			true
		);
	}

	public function admin_assets( $hook ) {
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_style( 'jet-engine-pep-admin', $this->plugin_url( 'assets/css/admin.css' ), array(), JET_ENGINE_POST_EP_VERSION );
		}
	}

	public function expiration_post_types() {
		$form_type = function_exists( 'jet_form_builder' ) ? jet_form_builder()->post_type->slug() : 'jet-form-builder';
		return array_values( array_diff( get_post_types(), array( $form_type ) ) );
	}

	public function post_editor_assets() {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || ! $screen->is_block_editor() || ! in_array( $screen->post_type, $this->expiration_post_types(), true ) ) {
			return;
		}
		wp_enqueue_script(
			'jet-engine-pep-post-editor',
			$this->plugin_url( 'assets/js/post.editor.js' ),
			array( 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-data', 'wp-element', 'wp-components', 'wp-i18n', 'wp-date' ),
			JET_ENGINE_POST_EP_VERSION,
			true
		);
		$post_id = get_the_ID();
		$type = get_post_type_object( $screen->post_type );
		$can_trash = $post_id ? current_user_can( 'delete_post', $post_id ) : current_user_can( $type->cap->delete_posts );
		wp_add_inline_script( 'jet-engine-pep-post-editor', 'window.jetPEP = ' . wp_json_encode( array( 'canTrash' => $can_trash ) ) . ';', 'before' );
	}

	/** Expose one atomic expiration setting in edit context, independent of custom-fields support. */
	public function register_expiration_rest_field() {
		foreach ( $this->expiration_post_types() as $post_type ) {
			add_filter( 'rest_pre_insert_' . $post_type, array( $this, 'validate_expiration_rest_request' ), 10, 2 );
		}
		register_rest_field( $this->expiration_post_types(), 'jet_pep_expiration', array(
			'get_callback' => array( $this, 'get_expiration_rest_field' ),
			'update_callback' => array( $this, 'update_expiration_rest_field' ),
			'schema' => array(
				'type' => 'object',
				'context' => array( 'edit' ),
				'additionalProperties' => false,
				'required' => array( 'enabled', 'date', 'action' ),
				'properties' => array(
					'enabled' => array( 'type' => 'boolean' ),
					'date' => array( 'type' => 'string' ),
					'action' => array( 'type' => 'string', 'enum' => array( 'draft', 'trash' ) ),
				),
			),
		) );
	}

	public function get_expiration_rest_field( $object ) {
		$post_id = (int) $object['id'];
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return null;
		}
		$timestamp = (int) get_post_meta( $post_id, $this->period_meta_key, true );
		$action = get_post_meta( $post_id, $this->action_meta_key, true );
		return array(
			'enabled' => (bool) $timestamp,
			'date' => $this->expiration_display_date( $post_id, $timestamp ),
			'action' => in_array( $action, array( 'draft', 'trash' ), true ) ? $action : 'draft',
		);
	}

	public function validate_expiration_rest_request( $prepared_post, $request ) {
		if ( is_wp_error( $prepared_post ) || ! $request->has_param( 'jet_pep_expiration' ) ) {
			return $prepared_post;
		}
		$type = get_post_type_object( substr( current_filter(), strlen( 'rest_pre_insert_' ) ) );
		$valid = $this->validate_expiration_setting( $request['jet_pep_expiration'], (int) $request['id'], $type );
		return is_wp_error( $valid ) ? $valid : $prepared_post;
	}

	private function validate_expiration_setting( $value, $post_id, $post_type = null, $current = null ) {
		if ( ! is_array( $value ) || ! isset( $value['enabled'], $value['date'], $value['action'] )
			|| ! is_bool( $value['enabled'] ) || ! is_string( $value['date'] ) || ! in_array( $value['action'], array( 'draft', 'trash' ), true ) ) {
			return new WP_Error( 'jet_pep_invalid_expiration', __( 'Invalid expiration settings.', 'jet-engine-post-expiration-period' ), array( 'status' => 400 ) );
		}
		if ( ! $value['enabled'] ) {
			return true;
		}
		$current = $current ?? ( $post_id ? $this->get_expiration_rest_field( array( 'id' => $post_id ) ) : null );
		if ( $current && $value['enabled'] === $current['enabled'] && $value['date'] === $current['date'] && $value['action'] === $current['action'] ) {
			return true;
		}
		$can_trash = $post_id ? current_user_can( 'delete_post', $post_id ) : ( $post_type && current_user_can( $post_type->cap->delete_posts ) );
		if ( 'trash' === $value['action'] && ! $can_trash ) {
			return new WP_Error( 'jet_pep_forbidden', __( 'You cannot schedule moving this post to trash.', 'jet-engine-post-expiration-period' ), array( 'status' => 403 ) );
		}
		$same_date = $current && $current['enabled'] && $value['date'] === $current['date'];
		if ( ! $same_date && ! $this->parse_expiration_date( $value['date'] ) ) {
			return new WP_Error( 'jet_pep_invalid_date', __( 'Expiration was not changed. Enter a valid future date.', 'jet-engine-post-expiration-period' ), array( 'status' => 400 ) );
		}
		return true;
	}

	public function update_expiration_rest_field( $value, $post ) {
		return $this->save_expiration_setting( $value, $post->ID );
	}

	private function save_expiration_setting( $value, $post_id, $current = null ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'jet_pep_forbidden', __( 'You cannot edit expiration for this post.', 'jet-engine-post-expiration-period' ), array( 'status' => 403 ) );
		}
		$valid = $this->validate_expiration_setting( $value, $post_id, null, $current );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( ! $value['enabled'] ) {
			if ( ! $this->delete_meta_expiration( $post_id ) ) {
				return new WP_Error( 'jet_pep_save_failed', __( 'Expiration could not be cancelled.', 'jet-engine-post-expiration-period' ), array( 'status' => 500 ) );
			}
			return true;
		}
		$current = $current ?? $this->get_expiration_rest_field( array( 'id' => $post_id ) );
		if ( $value['date'] === $current['date'] && $value['action'] === $current['action'] && $current['enabled'] ) {
			return true;
		}
		$saved = $current['enabled'] && $value['date'] === $current['date']
			? $this->update_expiration_action( $post_id, $value['date'], $value['action'] )
			: $this->init_expiration( $this->parse_expiration_date( $value['date'] ), $post_id, $value['action'] );
		if ( ! $saved ) {
			return new WP_Error( 'jet_pep_save_failed', __( 'Expiration was not changed. Could not save expiration settings.', 'jet-engine-post-expiration-period' ), array( 'status' => 500 ) );
		}
		return true;
	}

	public function add_fields_to_insert_post() {
		$this->require_template( 'form-notification-insert-post-fields' );
	}

	public function handle_post_action( $notification, $form ) {
		if ( empty( $notification['enable_expiration_period'] ) ) {
			return;
		}
		$period = $this->filter_period( $notification['expiration_period'] ?? '' );
		if ( ! $period ) {
			return;
		}
		$this->init_expiration( $period, $form->data['inserted_post_id'] ?? 0, $notification['expiration_action'] ?? 'draft' );
	}

	/** Read the source when a form creates a post or explicitly applies expiration on update. */
	public function apply_expiration_settings( $settings, $request, $post_id ) {
		if ( empty( $settings['enable_expiration_period'] ) || ! $post_id || ! get_post_status( $post_id ) ) {
			return false;
		}
		$source = $settings['expiration_source'] ?? 'fixed';
		switch ( $source ) {
			case 'fixed':
				$value = $settings['expiration_period'] ?? '';
				break;
			case 'field':
				$key = $settings['expiration_field'] ?? '';
				$value = is_string( $key ) ? ( $request[ $key ] ?? '' ) : '';
				break;
			case 'post_meta':
				$key = $settings['expiration_meta_key'] ?? '';
				$value = is_string( $key ) && '' !== $key ? get_post_meta( $post_id, $key, true ) : '';
				break;
			default:
				return false;
		}
		$type = 'fixed' === $source ? 'days' : ( $settings['expiration_value_type'] ?? 'days' );
		$timestamp = 'days' === $type ? $this->filter_period( $value ) : ( 'date' === $type ? $this->parse_expiration_date( $value ) : false );
		if ( ! $timestamp ) {
			return false; // Invalid/empty values must not clear an existing schedule.
		}
		$action = $settings['expiration_action'] ?? 'draft';
		if ( ! in_array( $action, array( 'draft', 'trash' ), true ) ) {
			return false;
		}
		if ( ! $this->init_expiration( $timestamp, $post_id, $action ) ) {
			throw new \Jet_Form_Builder\Exceptions\Action_Exception( 'failed', __( 'Could not save post expiration.', 'jet-engine-post-expiration-period' ) );
		}
		return true;
	}

	public function filter_period( $period ) {
		if ( ! is_scalar( $period ) || is_bool( $period ) || ! preg_match( '/^[0-9]+$/D', (string) $period ) || (float) $period < 1 || (float) $period > 365000 ) {
			return false;
		}
		return current_datetime()->setTime( 0, 0 )->modify( '+' . (int) $period . ' days' )->getTimestamp();
	}

	/** Accept a calendar date or JFB Date field's UTC-encoded timestamp, with day precision. */
	public function parse_expiration_date( $value ) {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return false;
		}
		$value = trim( (string) $value );
		if ( preg_match( '/^[0-9]+$/D', $value ) ) {
			$timestamp = (float) $value;
			if ( $timestamp <= 0 || $timestamp > 253402300799 ) {
				return false;
			}
			// JFB's Date field uses strtotime() in WordPress's UTC PHP timezone.
			// Preserve its selected calendar day, including on sites west of UTC.
			$value = gmdate( 'Y-m-d', (int) $timestamp );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) ) {
			return false;
		}
		try {
			$date = new DateTimeImmutable( $value, wp_timezone() );
			$errors = DateTimeImmutable::getLastErrors();
			if ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) {
				return false;
			}
			return $date->getTimestamp() > current_datetime()->setTime( 0, 0 )->getTimestamp() ? $date->getTimestamp() : false;
		} catch ( Exception $exception ) {
			return false;
		}
	}

	public function init_expiration( $expiration_period, $post_id, $expiration_action ) {
		if ( ! $post_id || ! get_post_status( $post_id ) || ! is_numeric( $expiration_period ) || $expiration_period <= time()
			|| ! in_array( $expiration_action, array( 'draft', 'trash' ), true ) ) {
			return false;
		}
		// The shared daily job owns cron scheduling; post saves only change metadata.
		return $this->set_meta_expiration( $post_id, (int) $expiration_period, $expiration_action );
	}

	private function acquire_expiration_lock( $lock, $timeout ) {
		$connection = $lock->connection;

		$value = ( time() + 300 ) . ':' . wp_generate_uuid4();
		$deadline = microtime( true ) + $timeout;
		do {
			$previous_errors = $connection->suppress_errors( true );
			try {
				$inserted = $connection->insert( $lock->options, array( 'option_name' => $lock->name, 'option_value' => $value, 'autoload' => 'no' ) );
			} finally {
				$connection->suppress_errors( $previous_errors );
			}
			if ( 1 === $inserted ) {
				return $value;
			}
			$previous = $connection->get_var( $connection->prepare( "SELECT option_value FROM $lock->options WHERE option_name = %s", $lock->name ) );
			if ( $previous && (int) $previous < time()
				&& 1 === $connection->update( $lock->options, array( 'option_value' => $value ), array( 'option_name' => $lock->name, 'option_value' => $previous ) ) ) {
				return $value;
			}
			if ( microtime( true ) >= $deadline ) {
				return false;
			}
			usleep( 100000 );
		} while ( true );
	}

	private function refresh_expiration_lock( $lock ) {

		// Keep the owner UUID stable for SQL already in flight; renew with a distinct revision.
		$owner = strrchr( $lock->value, ':' );
		$value = ( time() + 300 ) . ':' . wp_generate_uuid4() . $owner;
		$updated = $lock->connection->update( $lock->options, array( 'option_value' => $value ),
			array( 'option_name' => $lock->name, 'option_value' => $lock->value ) );
		if ( 1 !== $updated ) {
			throw new Jet_Engine_Post_PE_Lock_Exception();
		}
		$lock->value = $value;
	}

	private function with_expiration_lock( $post_id, $callback, $timeout = 2 ) {
		global $wpdb;

		$connection = $wpdb;
		$options = $wpdb->options;
		$postmeta = $wpdb->postmeta;
		$posts = $wpdb->posts;
		$scope = $options . ':' . $post_id;
		if ( isset( $this->expiration_locks[ $scope ] ) ) {
			return false;
		}
		$lock = (object) array( 'connection' => $connection, 'options' => $options, 'name' => 'jet_pep_lock_' . $post_id );
		$value = $this->acquire_expiration_lock( $lock, $timeout );
		if ( ! $value ) {
			return false;
		}
		$lock->value = $value;
		$this->expiration_locks[ $scope ] = true;
		$owner = strrchr( $value, ':' );
		$post_write_started = false;
		$refreshing = false;
		$refresh = function () use ( $lock, &$refreshing ) {
			if ( $refreshing ) {
				return;
			}
			$refreshing = true;
			try {
				$this->refresh_expiration_lock( $lock );
			} finally {
				$refreshing = false;
			}
		};
		// Fence the actual writes as well as the PHP callback if an abandoned lease is taken over.
		$guard = function ( $sql ) use ( $post_id, $connection, $options, $postmeta, $posts, $owner, $refresh, &$post_write_started ) {
			global $wpdb;
			if ( $wpdb !== $connection || $wpdb->postmeta !== $postmeta ) {
				return $sql;
			}
			$metadata_write = preg_match( '/^(?:INSERT INTO|UPDATE|DELETE FROM) ' . preg_quote( $postmeta, '/' ) . '\b/', str_replace( '`', '', $sql ) );
			$post_write = preg_match( '/^UPDATE ' . preg_quote( $posts, '/' ) . '\b.*WHERE ID = ' . $post_id . '\b/s', str_replace( '`', '', $sql ) );
			// Guard writes without changing connection state before reads such as FOUND_ROWS().
			if ( $metadata_write || $post_write || ( $post_write_started && preg_match( '/^\s*(?:INSERT|REPLACE|UPDATE|DELETE)\b/i', $sql ) ) ) {
				$refresh();
			}
			if ( ! $metadata_write && ! $post_write ) {
				return $sql;
			}
			$fence = $connection->prepare( "EXISTS (SELECT 1 FROM $options WHERE option_name = %s AND SUBSTR(option_value, -%d) = %s)",
				'jet_pep_lock_' . $post_id, strlen( $owner ), $owner );
			$sql = rtrim( $sql, "; \t\r\n" );
			if ( 0 === strpos( $sql, 'INSERT INTO' ) ) {
				return preg_replace( '/ VALUES \((.*)\)$/s', ' SELECT $1 WHERE ' . $fence, $sql );
			}
			$post_write_started = $post_write || $post_write_started;
			return $sql . ' AND ' . $fence;
		};
		$transition_guard = function ( $new_status, $old_status, $post ) use ( $post_id, $connection, $postmeta, $refresh ) {
			global $wpdb;
			if ( $wpdb === $connection && $wpdb->postmeta === $postmeta && $post->ID === (int) $post_id ) {
				$refresh();
			}
		};
		add_filter( 'query', $guard, PHP_INT_MAX );
		add_action( 'transition_post_status', $transition_guard, PHP_INT_MIN, 3 );
		try {
			wp_cache_delete( $post_id, 'post_meta' );
			$result = $callback();
			$refresh();
			return $result;
		} catch ( Jet_Engine_Post_PE_Lock_Exception $exception ) {
			return false;
		} finally {
			remove_filter( 'query', $guard, PHP_INT_MAX );
			remove_action( 'transition_post_status', $transition_guard, PHP_INT_MIN );
			$connection->delete( $options, array( 'option_name' => $lock->name, 'option_value' => $lock->value ) );
			unset( $this->expiration_locks[ $scope ] );
			wp_cache_delete( $post_id, 'post_meta' );
		}
	}

	public function set_meta_expiration( $post_id, $expiration_period, $expiration_action ) {
		return $this->with_expiration_lock( $post_id, function () use ( $post_id, $expiration_period, $expiration_action ) {
			$previous_period = get_post_meta( $post_id, $this->period_meta_key, true );
			$previous_action = get_post_meta( $post_id, $this->action_meta_key, true );
			update_post_meta( $post_id, $this->period_meta_key, $expiration_period );
			if ( (int) get_post_meta( $post_id, $this->period_meta_key, true ) !== (int) $expiration_period ) {
				return false;
			}
			if ( $this->save_expiration_action( $post_id, $expiration_action ) ) {
				// Bind the calendar marker to this timestamp so stale markers do not classify another date.
				update_post_meta( $post_id, $this->calendar_meta_key, $expiration_period );
				if ( $this->is_calendar_expiration( $post_id, $expiration_period ) ) {
					return true;
				}
				$this->restore_expiration_meta( $post_id, $this->action_meta_key, $previous_action, $expiration_action );
			}
			$this->restore_expiration_meta( $post_id, $this->period_meta_key, $previous_period, $expiration_period );
			return false;
		} );
	}

	private function update_expiration_action( $post_id, $date, $action ) {
		return $this->with_expiration_lock( $post_id, function () use ( $post_id, $date, $action ) {
			$timestamp = (int) get_post_meta( $post_id, $this->period_meta_key, true );
			return $timestamp && $date === $this->expiration_display_date( $post_id, $timestamp )
				&& $this->save_expiration_action( $post_id, $action );
		} );
	}

	private function save_expiration_action( $post_id, $action ) {
		update_post_meta( $post_id, $this->action_meta_key, $action );
		return get_post_meta( $post_id, $this->action_meta_key, true ) === $action;
	}

	private function restore_expiration_meta( $post_id, $key, $previous, $written ) {
		if ( '' === $previous ) {
			delete_post_meta( $post_id, $key, $written );
		} else {
			update_post_meta( $post_id, $key, $previous, $written );
		}
	}

	public function delete_meta_expiration( $id ) {
		return $this->with_expiration_lock( $id, function () use ( $id ) {
			$deleted = array();
			foreach ( array( $this->action_meta_key, $this->period_meta_key, $this->calendar_meta_key ) as $key ) {
				$previous = get_post_meta( $id, $key );
				delete_post_meta( $id, $key );
				if ( metadata_exists( 'post', $id, $key ) ) {
					foreach ( $deleted as $restore_key => $values ) {
						foreach ( $values as $value ) {
							add_post_meta( $id, $restore_key, wp_slash( $value ) );
						}
					}
					return false;
				}
				$deleted[ $key ] = $previous;
			}
			return true;
		} );
	}

	private function clear_expiration_meta( $id ) {
		delete_post_meta( $id, $this->action_meta_key );
		delete_post_meta( $id, $this->period_meta_key );
		delete_post_meta( $id, $this->calendar_meta_key );
	}

	public function set_daily_cron() {
		// Keep one shared job at site midnight. Re-align after timezone/DST changes.
		$next = $this->expiration_cutoff();
		$event = wp_get_scheduled_event( $this->daily_event );
		$overdue = $event && $event->timestamp <= time();
		if ( $event && 'daily' === $event->schedule && DAY_IN_SECONDS === $event->interval
			&& ( $overdue || ( $event->timestamp <= $next && '00:00:00' === wp_date( 'H:i:s', $event->timestamp, wp_timezone() ) ) ) ) {
			return;
		}
		// Keep a missed legacy check ready; its callback will align the next run.
		if ( $overdue ) {
			$next = $event->timestamp;
		}
		// Register the replacement first; a failure must preserve previous jobs.
		if ( ! wp_schedule_event( $next, 'daily', $this->daily_event ) ) {
			return;
		}
		foreach ( _get_cron_array() as $timestamp => $hooks ) {
			foreach ( $hooks[ $this->daily_event ] ?? array() as $scheduled ) {
				if ( (int) $timestamp !== $next || ! empty( $scheduled['args'] ) ) {
					wp_unschedule_event( $timestamp, $this->daily_event, $scheduled['args'] );
				}
			}
		}
	}

	/** First instant of tomorrow in the site timezone; today and missed dates are due. */
	public function expiration_cutoff() {
		return current_datetime()->modify( 'tomorrow' )->setTime( 0, 0 )->getTimestamp();
	}

	private function legacy_expiration_cutoff() {
		$now = time();
		return $now - $now % DAY_IN_SECONDS + DAY_IN_SECONDS;
	}

	private function is_calendar_expiration( $post_id, $timestamp ) {
		return (int) get_post_meta( $post_id, $this->calendar_meta_key, true ) === (int) $timestamp;
	}

	private function expiration_display_date( $post_id, $timestamp ) {
		if ( ! $timestamp ) {
			return '';
		}
		if ( $this->is_calendar_expiration( $post_id, $timestamp ) ) {
			return wp_date( 'Y-m-d', $timestamp, wp_timezone() );
		}
		// The legacy UTC date becomes eligible at the first site midnight on or after it begins.
		$utc_midnight = $timestamp - $timestamp % DAY_IN_SECONDS;
		$site_date = ( new DateTimeImmutable( '@' . $utc_midnight ) )->setTimezone( wp_timezone() );
		$site_midnight = $site_date->setTime( 0, 0 );
		if ( $site_midnight->getTimestamp() < $utc_midnight ) {
			$site_midnight = $site_midnight->modify( 'tomorrow' );
		}
		return $site_midnight->format( 'Y-m-d' );
	}

	public function find_expired_posts() {
		global $wpdb;

		$sql = $wpdb->prepare( "SELECT DISTINCT postmeta_1.post_id, postmeta_1.meta_value as expiration_action
                FROM $wpdb->postmeta as postmeta_1
                JOIN $wpdb->postmeta as postmeta_2 ON postmeta_1.post_id = postmeta_2.post_id
                WHERE postmeta_1.meta_key = %s AND postmeta_2.meta_key = %s
                AND postmeta_1.meta_value IN ('draft', 'trash')
                AND CAST(postmeta_2.meta_value AS UNSIGNED) > 0
                AND CASE WHEN EXISTS (
                    SELECT 1 FROM $wpdb->postmeta AS calendar
                    WHERE calendar.post_id = postmeta_2.post_id
                    AND calendar.meta_key = %s AND calendar.meta_value = postmeta_2.meta_value
                ) THEN CAST(postmeta_2.meta_value AS UNSIGNED) < %d
                ELSE CAST(postmeta_2.meta_value AS UNSIGNED) >= %d
                    AND CAST(postmeta_2.meta_value AS UNSIGNED) < %d END",
			$this->action_meta_key, $this->period_meta_key, $this->calendar_meta_key,
			$this->expiration_cutoff(), $this->legacy_expiration_cutoff() - DAY_IN_SECONDS, $this->legacy_expiration_cutoff() );

		$this->expired_posts = $wpdb->get_results( $sql, ARRAY_A );
	}


	public function on_daily_check_expirations() {

		$this->find_expired_posts();

		if ( ! empty( $this->expired_posts ) ) {
			$this->schedule_posts();
		}
		$this->set_daily_cron();
	}


	public function schedule_posts() {
		if ( empty( $this->expired_posts ) ) {
			return;
		}

		foreach ( $this->expired_posts as $post ) {
			$this->expire_scheduled_post( $post['post_id'] );
		}
	}

	public function expire_scheduled_post( $post_id ) {
		$this->with_expiration_lock( $post_id, function () use ( $post_id ) {
			$timestamp = (int) get_post_meta( $post_id, $this->period_meta_key, true );
			$calendar = $this->is_calendar_expiration( $post_id, $timestamp );
			$cutoff = $calendar ? $this->expiration_cutoff() : $this->legacy_expiration_cutoff();
			if ( ! $timestamp || $timestamp >= $cutoff || ( ! $calendar && $timestamp < $cutoff - DAY_IN_SECONDS ) ) {
				return;
			}
			$action = get_post_meta( $post_id, $this->action_meta_key, true );
			if ( $this->on_expiration( $post_id, $action )
				&& $timestamp === (int) get_post_meta( $post_id, $this->period_meta_key, true )
				&& $calendar === $this->is_calendar_expiration( $post_id, $timestamp )
				&& $action === get_post_meta( $post_id, $this->action_meta_key, true ) ) {
				$this->clear_expiration_meta( $post_id );
			}
		}, 0 );
	}

	public function on_expiration( $post_id, $expiration_action ) {
		if ( ! in_array( $expiration_action, array( 'draft', 'trash' ), true ) ) {
			return false;
		}
		if ( ! get_post_status( $post_id )
		     || get_post_status( $post_id ) === $expiration_action ) {
			return true;
		}

		$func_name = 'expiration_' . $expiration_action;
		if ( is_callable( [ $this, $func_name ] ) ) {
			return $this->$func_name( $post_id );
		}
	}

	public function expiration_draft( $id ) {
		$update_post = array(
			'ID'          => $id,
			'post_status' => 'draft'
		);

		$result = wp_update_post( $update_post, true );
		return ! is_wp_error( $result ) && (bool) $result;
	}

	public function expiration_trash( $id ) {
		// With the trash disabled, WordPress permanently deletes; never do that here.
		if ( defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS ) {
			return false;
		}
		return (bool) wp_trash_post( $id );
	}

	public function add_expiration_meta_box( $post_type, $post ) {
		$screen = get_current_screen();
		if ( $screen && $screen->is_block_editor() ) {
			return;
		}
		if ( ! $post || ! in_array( $post_type, $this->expiration_post_types(), true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		add_meta_box( 'jet-engine-post-expiration', __( 'Post Expiration', 'jet-engine-post-expiration-period' ), array( $this, 'render_expiration_meta_box' ), $post_type, 'side', 'default' );
	}

	public function render_expiration_meta_box( $post ) {
		$timestamp = (int) get_post_meta( $post->ID, $this->period_meta_key, true );
		$action = get_post_meta( $post->ID, $this->action_meta_key, true );
		$date = $this->expiration_display_date( $post->ID, $timestamp );
		wp_nonce_field( 'jet_pep_save_' . $post->ID, 'jet_pep_nonce' );
		?>
		<p><label><input type="checkbox" name="jet_pep_enabled" value="1" <?php checked( (bool) $timestamp ); ?>> <?php esc_html_e( 'Enable expiration', 'jet-engine-post-expiration-period' ); ?></label></p>
		<p><label for="jet-pep-date"><?php esc_html_e( 'Expires on', 'jet-engine-post-expiration-period' ); ?></label>
		<input id="jet-pep-date" type="date" name="jet_pep_date" value="<?php echo esc_attr( $date ); ?>"></p>
		<p><label for="jet-pep-action"><?php esc_html_e( 'On expiration', 'jet-engine-post-expiration-period' ); ?></label>
		<select id="jet-pep-action" name="jet_pep_action">
			<option value="draft" <?php selected( $action, 'draft' ); ?>><?php esc_html_e( 'Set to draft', 'jet-engine-post-expiration-period' ); ?></option>
			<option value="trash" <?php selected( $action, 'trash' ); ?>><?php esc_html_e( 'Move to trash', 'jet-engine-post-expiration-period' ); ?></option>
		</select></p>
		<?php
	}

	public function save_expiration_meta_box( $post_id ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id )
			|| ! current_user_can( 'edit_post', $post_id ) || ! isset( $_POST['jet_pep_nonce'] ) || ! is_string( $_POST['jet_pep_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['jet_pep_nonce'] ) ), 'jet_pep_save_' . $post_id ) ) {
			return;
		}
		$enabled = ! empty( $_POST['jet_pep_enabled'] );
		$value = array(
			'enabled' => $enabled,
			'date' => isset( $_POST['jet_pep_date'] ) && is_string( $_POST['jet_pep_date'] ) ? sanitize_text_field( wp_unslash( $_POST['jet_pep_date'] ) ) : '',
			'action' => $enabled ? ( isset( $_POST['jet_pep_action'] ) && is_string( $_POST['jet_pep_action'] ) ? sanitize_key( $_POST['jet_pep_action'] ) : '' ) : 'draft',
		);
		$current = $enabled ? $this->get_expiration_rest_field( array( 'id' => $post_id ) ) : null;
		if ( $current ) {
			// Classic saves still replace an empty/invalid stored action with the selected one.
			$current['action'] = get_post_meta( $post_id, $this->action_meta_key, true );
		}
		$saved = $this->save_expiration_setting( $value, $post_id, $current );
		if ( is_wp_error( $saved ) ) {
			$message = in_array( $saved->get_error_code(), array( 'jet_pep_invalid_expiration', 'jet_pep_invalid_date' ), true )
				? __( 'Expiration was not changed. Enter a valid future date and choose Draft or Trash.', 'jet-engine-post-expiration-period' )
				: $saved->get_error_message();
			set_transient( 'jet_pep_error_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
		}
	}

	public function expiration_admin_notice() {
		$key = 'jet_pep_error_' . get_current_user_id();
		$message = get_transient( $key );
		if ( $message ) {
			delete_transient( $key );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	public function require_template( $template ) {
		$path = JET_ENGINE_POST_EP_PATH . 'templates' . DIRECTORY_SEPARATOR;
		require( $path . $template . '.php' );
	}

	public function plugin_url( $path ) {
		return JET_ENGINE_POST_EP_URL . $path;
	}

	/**
	 * Instance.
	 *
	 * Ensures only one instance of the plugin class is loaded or can be loaded.
	 *
	 * @return Jet_Engine_Post_PE instance of the class.
	 * @since 1.0.0
	 * @access public
	 * @static
	 *
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

}

Jet_Engine_Post_PE::instance();

