<?php

// If this file is called directly, abort.
use Jet_Form_Builder\Actions\Action_Handler;
use Jet_Form_Builder\Actions\Types\Base;

if ( ! defined( 'WPINC' ) ) {
	die();
}

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
		add_action( 'jet-form-builder/gateways/before-send', array( $this, 'after_form_send' ) );
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
		if ( empty( $settings['enable_expiration_period'] ) ) {
			return;
		}

		$post_id = $post_action && method_exists( $post_action, 'get_inserted_id' )
			? $post_action->get_inserted_id()
			: $action_handler->get_inserted_post_id( $action_instance->_id );
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
		foreach ( $pending as $args ) {
			$this->apply_expiration_settings( $args[0], $args[1], $args[2] );
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
		return array_values( array_diff( get_post_types( array( 'show_ui' => true, 'show_in_rest' => true ) ), array( 'attachment' ) ) );
	}

	public function post_editor_assets() {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || ! $screen->is_block_editor() || ! in_array( $screen->post_type, $this->expiration_post_types(), true ) ) {
			return;
		}
		wp_enqueue_script(
			'jet-engine-pep-post-editor',
			$this->plugin_url( 'assets/js/post.editor.js' ),
			array( 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-data', 'wp-element', 'wp-components', 'wp-i18n' ),
			JET_ENGINE_POST_EP_VERSION,
			true
		);
	}

	/** Expose one atomic expiration setting in edit context, independent of custom-fields support. */
	public function register_expiration_rest_field() {
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

	public function update_expiration_rest_field( $value, $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'jet_pep_forbidden', __( 'You cannot edit expiration for this post.', 'jet-engine-post-expiration-period' ), array( 'status' => 403 ) );
		}
		if ( ! is_array( $value ) || ! isset( $value['enabled'], $value['date'], $value['action'] )
			|| ! is_bool( $value['enabled'] ) || ! is_string( $value['date'] ) || ! in_array( $value['action'], array( 'draft', 'trash' ), true ) ) {
			return new WP_Error( 'jet_pep_invalid_expiration', __( 'Invalid expiration settings.', 'jet-engine-post-expiration-period' ), array( 'status' => 400 ) );
		}
		if ( ! $value['enabled'] ) {
			if ( ! $this->delete_meta_expiration( $post->ID ) ) {
				return new WP_Error( 'jet_pep_save_failed', __( 'Expiration could not be cancelled.', 'jet-engine-post-expiration-period' ), array( 'status' => 500 ) );
			}
			return true;
		}
		$current = $this->get_expiration_rest_field( array( 'id' => $post->ID ) );
		if ( $value['enabled'] === $current['enabled'] && $value['date'] === $current['date'] && $value['action'] === $current['action'] ) {
			return true;
		}
		if ( 'trash' === $value['action'] && ! current_user_can( 'delete_post', $post->ID ) ) {
			return new WP_Error( 'jet_pep_forbidden', __( 'You cannot schedule moving this post to trash.', 'jet-engine-post-expiration-period' ), array( 'status' => 403 ) );
		}
		$timestamp = $this->parse_expiration_date( $value['date'] );
		$same_date = $current['enabled'] && $value['date'] === $current['date'];
		if ( ! $same_date && ! $timestamp ) {
			return new WP_Error( 'jet_pep_invalid_date', __( 'Expiration was not changed. Enter a valid future date.', 'jet-engine-post-expiration-period' ), array( 'status' => 400 ) );
		}
		$saved = $same_date
			? $this->update_expiration_action( $post->ID, $value['date'], $value['action'] )
			: $this->init_expiration( $timestamp, $post->ID, $value['action'] );
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
		return $this->init_expiration( $timestamp, $post_id, $settings['expiration_action'] ?? 'draft' );
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

	private function with_expiration_lock( $post_id, $callback, $timeout = 2 ) {
		global $wpdb;

		// The period, action and calendar marker are stored in separate rows.
		$lock = 'jet_pep_' . md5( DB_NAME . ':' . $wpdb->postmeta . ':' . (int) $post_id );
		if ( isset( $this->expiration_locks[ $lock ] )
			|| '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, $timeout ) ) ) {
			return false;
		}
		$this->expiration_locks[ $lock ] = true;
		try {
			wp_cache_delete( $post_id, 'post_meta' );
			return $callback();
		} finally {
			unset( $this->expiration_locks[ $lock ] );
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
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
			$this->clear_expiration_meta( $id );
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
                AND CAST(postmeta_2.meta_value AS UNSIGNED) < CASE WHEN EXISTS (
                    SELECT 1 FROM $wpdb->postmeta AS calendar
                    WHERE calendar.post_id = postmeta_2.post_id
                    AND calendar.meta_key = %s AND calendar.meta_value = postmeta_2.meta_value
                ) THEN %d ELSE %d END",
			$this->action_meta_key, $this->period_meta_key, $this->calendar_meta_key,
			$this->expiration_cutoff(), $this->legacy_expiration_cutoff() );

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
			if ( ! $timestamp || $timestamp >= $cutoff ) {
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
		$type = get_post_type_object( $post_type );
		if ( ! $post || ! $type || ! $type->show_ui || 'attachment' === $post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
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
		if ( empty( $_POST['jet_pep_enabled'] ) ) {
			if ( ! $this->delete_meta_expiration( $post_id ) ) {
				set_transient( 'jet_pep_error_' . get_current_user_id(), __( 'Expiration could not be cancelled.', 'jet-engine-post-expiration-period' ), MINUTE_IN_SECONDS );
			}
			return;
		}
		$date = isset( $_POST['jet_pep_date'] ) && is_string( $_POST['jet_pep_date'] ) ? sanitize_text_field( wp_unslash( $_POST['jet_pep_date'] ) ) : '';
		$action = isset( $_POST['jet_pep_action'] ) && is_string( $_POST['jet_pep_action'] ) ? sanitize_key( $_POST['jet_pep_action'] ) : '';
		$current = (int) get_post_meta( $post_id, $this->period_meta_key, true );
		$same_date = $current && $date === $this->expiration_display_date( $post_id, $current );
		if ( $same_date && $action === get_post_meta( $post_id, $this->action_meta_key, true ) ) {
			return;
		}
		if ( 'trash' === $action && ! current_user_can( 'delete_post', $post_id ) ) {
			set_transient( 'jet_pep_error_' . get_current_user_id(), __( 'You cannot schedule moving this post to trash.', 'jet-engine-post-expiration-period' ), MINUTE_IN_SECONDS );
			return;
		}
		$timestamp = $this->parse_expiration_date( $date );
		if ( ( ! $same_date && ! $timestamp ) || ! in_array( $action, array( 'draft', 'trash' ), true ) ) {
			set_transient( 'jet_pep_error_' . get_current_user_id(), __( 'Expiration was not changed. Enter a valid future date and choose Draft or Trash.', 'jet-engine-post-expiration-period' ), MINUTE_IN_SECONDS );
			return;
		}
		$saved = $same_date
			? $this->update_expiration_action( $post_id, $date, $action )
			: $this->init_expiration( $timestamp, $post_id, $action );
		if ( ! $saved ) {
			set_transient( 'jet_pep_error_' . get_current_user_id(), __( 'Expiration was not changed. Could not save expiration settings.', 'jet-engine-post-expiration-period' ), MINUTE_IN_SECONDS );
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

