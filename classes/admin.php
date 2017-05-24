<?php
/**
 * @file
 */

if ( ! class_exists( 'Object_Sync_Salesforce' ) ) {
	die();
}

/**
 * Create default WordPress admin functionality for Salesforce to configure the plugin.
 */
class Object_Sync_Sf_Admin {

	protected $wpdb;
	protected $version;
	protected $login_credentials;
	protected $text_domain;
	protected $salesforce;
	protected $wordpress;
	protected $mappings;
	protected $push;
	protected $pull;
	protected $schedulable_classes;

	/**
	* @var string
	* Default path for the Salesforce authorize URL
	*/
	public $default_authorize_url_path;

	/**
	* @var string
	* Default path for the Salesforce token URL
	*/
	public $default_token_url_path;

	/**
	* @var string
	* What version of the Salesforce API should be the default on the settings screen.
	* Users can edit this, but they won't see a correct list of all their available versions until WordPress has
	* been authenticated with Salesforce.
	*/
	public $default_api_version;

	/**
	* @var bool
	* Default for whether to limit to triggerable items
	* Users can edit this
	*/
	public $default_triggerable;

	/**
	* @var bool
	* Default for whether to limit to updateable items
	* Users can edit this
	*/
	public $default_updateable;

	/**
	* @var int
	* Default pull throttle for how often Salesforce can pull
	* Users can edit this
	*/
	public $default_pull_throttle;

	/**
	* Constructor which sets up admin pages
	*
	* @param object $wpdb
	* @param string $version
	* @param array $login_credentials
	* @param string $text_domain
	* @param object $wordpress
	* @param object $salesforce
	* @param object $mappings
	* @param object $push
	* @param object $pull
	* @param object $logging
	* @param array $schedulable_classes
	* @throws \Exception
	*/
	public function __construct( $wpdb, $version, $login_credentials, $text_domain, $wordpress, $salesforce, $mappings, $push, $pull, $logging, $schedulable_classes ) {
		$this->wpdb = $wpdb;
		$this->version = $version;
		$this->login_credentials = $login_credentials;
		$this->text_domain = $text_domain;
		$this->wordpress = $wordpress;
		$this->salesforce = $salesforce;
		$this->mappings = $mappings;
		$this->push = $push;
		$this->pull = $pull;
		$this->logging = $logging;
		$this->schedulable_classes = $schedulable_classes;

		// default authorize url path
		$this->default_authorize_url_path = '/services/oauth2/authorize';
		// default token url path
		$this->default_token_url_path = '/services/oauth2/token';
		// what Salesforce API version to start the settings with. This is only used in the settings form
		$this->default_api_version = '40.0';
		// default pull throttle for avoiding going over api limits
		$this->default_pull_throttle = 5;
		// default setting for triggerable items
		$this->default_triggerable = true;
		// default setting for updateable items
		$this->default_updateable = true;

		$this->add_actions();

	}

	/**
	* Create the action hooks to create the admin page(s)
	*
	*/
	public function add_actions() {
		add_action( 'admin_init', array( $this, 'salesforce_settings_forms' ) );
		add_action( 'admin_init', array( $this, 'notices' ) );
		add_action( 'admin_post_post_fieldmap', array( $this, 'prepare_fieldmap_data' ) );

		add_action( 'admin_post_delete_fieldmap', array( $this, 'delete_fieldmap' ) );
		add_action( 'wp_ajax_get_salesforce_object_description', array( $this, 'get_salesforce_object_description' ) );
		add_action( 'wp_ajax_get_wordpress_object_description', array( $this, 'get_wordpress_object_fields' ) );
		add_action( 'wp_ajax_get_wp_sf_object_fields', array( $this, 'get_wp_sf_object_fields' ) );
		add_action( 'wp_ajax_push_to_salesforce', array( $this, 'push_to_salesforce' ) );
		add_action( 'wp_ajax_pull_from_salesforce', array( $this, 'pull_from_salesforce' ) );
		add_action( 'wp_ajax_refresh_mapped_data', array( $this, 'refresh_mapped_data' ) );

		add_action( 'edit_user_profile', array( $this, 'show_salesforce_user_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_salesforce_user_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_salesforce_user_fields' ) );

	}

	/**
	* Create WordPress admin options page
	*
	*/
	public function create_admin_menu() {
		$title = __( 'Salesforce', $this->text_domain );
		add_options_page( $title, $title, 'configure_salesforce', 'object-sync-salesforce-admin', array( $this, 'show_admin_page' ) );
	}

	/**
	* Render full admin pages in WordPress
	* This allows other plugins to add tabs to the Salesforce settings screen
	*
	* todo: better front end: html, organization of html into templates, css, js
	*
	*/
	public function show_admin_page() {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';
		$allowed = $this->check_wordpress_admin_permissions();
		if ( false === $allowed ) {
			return;
		}
		$tabs = array(
			'settings' => 'Settings',
			'authorize' => 'Authorize',
			'fieldmaps' => 'Fieldmaps',
			'schedule' => 'Scheduling',
		); // this creates the tabs for the admin

		// optionally make tab(s) for logging and log settings
		$logging_enabled = get_option( 'object_sync_for_salesforce_enable_logging', false );
		$tabs['log_settings'] = 'Log Settings';

		// filter for extending the tabs available on the page
		// currently it will go into the default switch case for $tab
		$tabs = apply_filters( 'object_sync_for_salesforce_settings_tabs', $tabs );

		$tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'settings';
		$this->tabs( $tabs, $tab );

		$consumer_key = $this->login_credentials['consumer_key'];
		$consumer_secret = $this->login_credentials['consumer_secret'];
		$callback_url = $this->login_credentials['callback_url'];
		$text_domain = $this->text_domain;

		try {
			switch ( $tab ) {
				case 'authorize':
					if ( isset( $_GET['code'] ) ) {
						$is_authorized = $this->salesforce['sfapi']->request_token( esc_attr( $_GET['code'] ) );
						echo "<script>window.location = '$callback_url';</script>";
					} elseif ( true === $this->salesforce['is_authorized'] ) {
							require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/authorized.php' );
							$this->status( $this->salesforce['sfapi'] );
					} elseif ( true === is_object ( $this->salesforce['sfapi'] ) && isset( $consumer_key ) && isset( $consumer_secret ) ) {
						echo '<p><a class="button button-primary" href="' . $this->salesforce['sfapi']->get_authorization_code() . '">' . esc_html( 'Connect to Salesforce' ) . '</a></p>';
					} else {
						$message = __( 'Salesforce needs to be authorized to connect to this website but the credentials are missing. Use the <a href="' . get_admin_url( null, 'options-general.php?page=object-sync-salesforce-admin&tab=settings' ) . '">Settings</a> tab to add them.', $this->text_domain );
						require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/error.php' );
					}
					break;
				case 'fieldmaps':
					if ( isset( $_GET['method'] ) ) {

						$method = esc_attr( $_GET['method'] );
						$error_url = get_admin_url( null, 'options-general.php?page=object-sync-salesforce-admin&tab=fieldmaps&method=' . $method );
						$success_url = get_admin_url( null, 'options-general.php?page=object-sync-salesforce-admin&tab=fieldmaps' );

						if ( isset( $_GET['transient'] ) ) {
							$transient = esc_html( $_GET['transient'] );
							$posted = get_transient( $transient );
						}

						if ( isset( $posted ) && is_array( $posted ) ) {
							$map = $posted;
						} elseif ( 'edit' === $method || 'clone' === $method || 'delete' === $method ) {
							$map = $this->mappings->get_fieldmaps( $_GET['id'] );
						}

						if ( isset( $map ) && is_array( $map ) ) {
							$label = $map['label'];
							$salesforce_object = $map['salesforce_object'];
							$salesforce_record_types_allowed = maybe_unserialize( $map['salesforce_record_types_allowed'] );
							$salesforce_record_type_default = $map['salesforce_record_type_default'];
							$wordpress_object = $map['wordpress_object'];
							$pull_trigger_field = $map['pull_trigger_field'];
							$fieldmap_fields = $map['fields'];
							$sync_triggers = $map['sync_triggers'];
							$push_async = $map['push_async'];
							$push_drafts = $map['push_drafts'];
							$weight = $map['weight'];
						}

						if ( 'add' === $method || 'edit' === $method || 'clone' === $method ) {
							require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/fieldmaps-add-edit-clone.php' );
						} elseif ( 'delete' === $method ) {
							require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/fieldmaps-delete.php' );
						}
					} else {
						$fieldmaps = $this->mappings->get_fieldmaps();
						require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/fieldmaps-list.php' );
					} // End if().
					break;
				case 'logout':
					$message = $this->logout();
					echo '<p>' . $message . '</p>';
					break;
				case 'clear_schedule':
					if ( isset( $_GET['schedule_name'] ) )  {
						$schedule_name = urlencode( $_GET['schedule_name'] );
					}
					$message = $this->clear_schedule( $schedule_name );
					echo '<p>' . $message . '</p>';
					break;
				case 'settings':
					$consumer_key = $this->login_credentials['consumer_key'];
					$consumer_secret = $this->login_credentials['consumer_secret'];
					if ( isset( $consumer_key ) && isset( $consumer_secret ) && ! empty( $consumer_key ) && ! empty( $consumer_secret ) ) {
						if ( true === $this->salesforce['is_authorized'] ) {
							require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/settings.php' );
						} else {
							$message = __( 'Salesforce needs to be authorized to connect to this website. Use the <a href="' . $callback_url . '">Authorize tab</a> to connect.', $this->text_domain );
							require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/error.php' );
							require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/settings.php' );
						}
					} else {
						require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/settings.php' );
					}
					break;
				default:
					$include_settings = apply_filters( 'object_sync_for_salesforce_settings_tab_include_settings', true, $tab );
					$content_before = apply_filters( 'object_sync_for_salesforce_settings_tab_content_before', null, $tab );
					$content_after = apply_filters( 'object_sync_for_salesforce_settings_tab_content_after', null, $tab );
					if ( null !== $content_before ) {
						echo $content_before;
					}
					if ( true === $include_settings ) {
						require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/settings.php' );
					}
					if ( null !== $content_after ) {
						echo $content_after;
					}
					break;
			} // End switch().
		} catch ( SalesforceApiException $ex ) {
				echo 'Error ' . $ex->getCode() . ', ' . $ex->getMessage();
		} catch ( Exception $ex ) {
			echo 'Error ' . $ex->getCode() . ', ' . $ex->getMessage();
		} // End try().
		echo '</div>';
	}

	/**
	* Create default WordPress admin settings form for salesforce
	* This is for the Settings page/tab
	*
	*/
	public function salesforce_settings_forms() {
		$page = isset( $_GET['tab'] ) ? $_GET['tab'] : 'settings';
		$section = isset( $_GET['tab'] ) ? $_GET['tab'] : 'settings';

		$input_callback_default = array( $this, 'display_input_field' );
		$input_checkboxes_default = array( $this, 'display_checkboxes' );
		$input_select_default = array( $this, 'display_select' );
		$link_default = array( $this, 'display_link' );

		$all_field_callbacks = array(
			'text' => $input_callback_default,
			'checkboxes' => $input_checkboxes_default,
			'select' => $input_select_default,
			'link' => $link_default,
		);

		$this->fields_settings( 'settings', 'settings', $all_field_callbacks );
		$this->fields_fieldmaps( 'fieldmaps', 'objects' );
		$this->fields_scheduling( 'schedule', 'schedule', $all_field_callbacks );
		$this->fields_log_settings( 'log_settings', 'log_settings', $all_field_callbacks );
	}

	/**
	* Fields for the Settings tab
	* This runs add_settings_section once, as well as add_settings_field and register_setting methods for each option
	*
	* @param string $page
	* @param string $section
	* @param string $input_callback
	*/
	private function fields_settings( $page, $section, $callbacks ) {
		add_settings_section( $page, ucwords( $page ), null, $page );
		$salesforce_settings = array(
			'consumer_key' => array(
				'title' => 'Consumer Key',
				'callback' => $callbacks['text'],
				'page' => $page,
				'section' => $section,
				'args' => array(
					'type' => 'text',
					'validate' => 'sanitize_text_field',
					'desc' => '',
					'constant' => 'OBJECT_SYNC_SF_SALESFORCE_CONSUMER_KEY',
				),

			),
			'consumer_secret' => array(
				'title' => 'Consumer Secret',
				'callback' => $callbacks['text'],
				'page' => $page,
				'section' => $section,
				'args' => array(
					'type' => 'text',
					'validate' => 'sanitize_text_field',
					'desc' => '',
					'constant' => 'OBJECT_SYNC_SF_SALESFORCE_CONSUMER_SECRET',
				),
			),
			'callback_url' => array(
				'title' => 'Callback URL',
				'callback' => $callbacks['text'],
				'page' => $page,
				'section' => $section,
				'args' => array(
					'type' => 'url',
					'validate' => 'sanitize_text_field',
					'desc' => '',
					'constant' => 'OBJECT_SYNC_SF_SALESFORCE_CALLBACK_URL',
				),
			),
			'login_base_url' => array(
				'title' => 'Login Base URL',
				'callback' => $callbacks['text'],
				'page' => $page,
				'section' => $section,
				'args' => array(
					'type' => 'url',
					'validate' => 'sanitize_text_field',
					'desc' => '',
					'constant' => 'OBJECT_SYNC_SF_SALESFORCE_LOGIN_BASE_URL',
				),
			),
			'authorize_url_path' => array(
				'title' => 'Authorize URL Path',
				'callback' => $callbacks['text'],
				'page' => $page,
				'section' => $section,
				'args' => array(
					'type' => 'text',
					'validate' => 'sanitize_text_field',
					'desc' => 'For most Salesforce installs, this should not be changed.',
					'constant' => 'OBJECT_SYNC_SF_SALESFORCE_AUTHORIZE_URL_PATH',
					'default' => $this->default_authorize_url_path,
				),
			),
			'token_url_path' => array(
				'title' => 'Token URL Path',
				'callback' => $callbacks['text'],
				'page' => $page,
				'section' => $section,
				'args' => array(
					'type' => 'text',
					'validate' => 'sanitize_text_field',
					'desc' => 'For most Salesforce installs, this should not be changed.',
					'constant' => 'OBJECT_SYNC_SF_SALESFORCE_TOKEN_URL_PATH',
					'default' => $this->default_token_url_path,
				),
			),
			'api_version' => array(
				'title' => 'Salesforce API Version',
				'callback' => $callbacks['text'],
				'page' => $page,
				'section' => $section,
				'args' => array(
					'type' => 'text',
					'validate' => 'sanitize_text_field',
					'desc' => '',
					'constant' => 'OBJECT_SYNC_SF_SALESFORCE_API_VERSION',
					'default' => $this->default_api_version,
				),
			),
			'object_filters' => array(
				'title' => 'Limit Salesforce Objects',
				'callback' => $callbacks['checkboxes'],
				'page' => $page,
				'section' => $section,
				'args' => array(
					'type' => 'checkboxes',
					'validate' => 'sanitize_text_field',
					'desc' => 'Allows you to limit which Salesforce objects can be mapped',
					'items' => array(
						'triggerable' => array(
							'text' => 'Only Triggerable objects',
							'id' => 'triggerable',
							'desc' => '',
							'default' => $this->default_triggerable,
						),
						'updateable' => array(
							'text' => 'Only Updateable objects',
							'id' => 'updateable',
							'desc' => '',
							'default' => $this->default_updateable,
						),
					),
				),
			),
			'pull_throttle' => array(
				'title' => 'Pull throttle (seconds)',
				'callback' => $callbacks['text'],
				'page' => $page,
				'section' => $section,
				'args' => array(
					'type' => 'number',
					'validate' => 'sanitize_text_field',
					'desc' => 'Number of seconds to wait between repeated salesforce pulls.<br>Prevents the webserver from becoming overloaded in case of too many cron runs, or webhook usage.',
					'constant' => '',
					'default' => $this->default_pull_throttle,
				),
			),
			'debug_mode' => array(
				'title' => 'Debug mode?',
				'callback' => $callbacks['text'],
				'page' => $page,
				'section' => $section,
				'args' => array(
					'type' => 'checkbox',
					'validate' => 'sanitize_text_field',
					'desc' => 'Debug mode can, combined with the Log Settings, log things like Salesforce API requests. It can create <strong>a lot</strong> of entries if enabled; it is not recommended to use it in a production environment.',
					'constant' => '',
				),
			),
		);

		if ( true === is_object( $this->salesforce['sfapi'] ) && true === $this->salesforce['sfapi']->is_authorized() ) {
			$salesforce_settings['api_version'] = array(
				'title' => 'Salesforce API Version',
				'callback' => $callbacks['select'],
				'page' => $page,
				'section' => $section,
				'args' => array(
					'type' => 'select',
					'validate' => 'sanitize_text_field',
					'desc' => '',
					'constant' => 'OBJECT_SYNC_SF_SALESFORCE_API_VERSION',
					'items' => $this->version_options(),
				),
			);
		}

		foreach ( $salesforce_settings as $key => $attributes ) {
			$id = 'object_sync_for_salesforce_' . $key;
			$name = 'object_sync_for_salesforce_' . $key;
			$title = $attributes['title'];
			$callback = $attributes['callback'];
			$validate = $attributes['args']['validate'];
			$page = $attributes['page'];
			$section = $attributes['section'];
			$args = array_merge(
				$attributes['args'],
				array(
					'title' => $title,
					'id' => $id,
					'label_for' => $id,
					'name' => $name,
				)
			);

			// if there is a constant and it is defined, don't run a validate function
			if ( isset( $attributes['args']['constant'] ) && defined( $attributes['args']['constant'] ) ) {
				$validate = '';
			}

			add_settings_field( $id, $title, $callback, $page, $section, $args );
			register_setting( $page, $id, array( $this, $validate ) );
		}
	}

	/**
	* Fields for the Fieldmaps tab
	* This runs add_settings_section once, as well as add_settings_field and register_setting methods for each option
	*
	* @param string $page
	* @param string $section
	* @param string $input_callback
	*/
	private function fields_fieldmaps( $page, $section, $input_callback = '' ) {
		add_settings_section( $page, ucwords( $page ), null, $page );
	}

	/**
	* Fields for the Scheduling tab
	* This runs add_settings_section once, as well as add_settings_field and register_setting methods for each option
	*
	* @param string $page
	* @param string $section
	* @param string $input_callback
	*/
	private function fields_scheduling( $page, $section, $callbacks ) {
		foreach ( $this->schedulable_classes as $key => $value ) {
			add_settings_section( $key, $value['label'], null, $page );
			$schedule_settings = array(
				$key . '_schedule_number' => array(
					'title' => __( 'Run schedule every', $this->text_domain ),
					'callback' => $callbacks['text'],
					'page' => $page,
					'section' => $key,
					'args' => array(
						'type' => 'number',
						'validate' => 'absint',
						'desc' => '',
						'constant' => '',
					),
				),
				$key . '_schedule_unit' => array(
					'title' => __( 'Time unit', $this->text_domain ),
					'callback' => $callbacks['select'],
					'page' => $page,
					'section' => $key,
					'args' => array(
						'type' => 'select',
						'validate' => 'sanitize_text_field',
						'desc' => '',
						'items' => array(
							'minutes' => array(
								'text' => 'Minutes',
								'value' => 'minutes',
							),
							'hours' => array(
								'text' => 'Hours',
								'value' => 'hours',
							),
							'days' => array(
								'text' => 'Days',
								'value' => 'days',
							),
						),
					),
				),
				$key . '_clear_button' => array(
					'title' => __( 'This queue has ' . $this->get_schedule_count( $key ) . ' ' . ( $this->get_schedule_count( $key ) === '1' ? 'item' : 'items' ), $this->text_domain ),
					'callback' => $callbacks['link'],
					'page' => $page,
					'section' => $key,
					'args' => array(
						'label' => 'Clear this queue',
						'desc' => '',
						'url' => '?page=object-sync-salesforce-admin&amp;tab=clear_schedule&amp;schedule_name=' . $key,
						'link_class' => 'button button-secondary',
					),
				),
			);
			foreach ( $schedule_settings as $key => $attributes ) {
				$id = 'object_sync_for_salesforce_' . $key;
				$name = 'object_sync_for_salesforce_' . $key;
				$title = $attributes['title'];
				$callback = $attributes['callback'];
				$page = $attributes['page'];
				$section = $attributes['section'];
				$args = array_merge(
					$attributes['args'],
					array(
						'title' => $title,
						'id' => $id,
						'label_for' => $id,
						'name' => $name
					)
				);
				add_settings_field( $id, $title, $callback, $page, $section, $args );
				register_setting( $page, $id );
			}
		} // End foreach().
	}

	/**
	* Fields for the Log Settings tab
	* This runs add_settings_section once, as well as add_settings_field and register_setting methods for each option
	*
	* @param string $page
	* @param string $section
	* @param array $callbacks
	*/
	private function fields_log_settings( $page, $section, $callbacks ) {
		add_settings_section( $page, ucwords( str_replace( '_', ' ', $page ) ), null, $page );
		$log_settings = array(
			'enable_logging' => array(
				'title' => 'Enable Logging?',
				'callback' => $callbacks['text'],
				'page' => $page,
				'section' => $section,
				'args' => array(
					'type' => 'checkbox',
					'validate' => 'absint',
					'desc' => '',
					'constant' => '',
				),
			),
			'statuses_to_log' => array(
				'title' => 'Statuses to log',
				'callback' => $callbacks['checkboxes'],
				'page' => $page,
				'section' => $section,
				'args' => array(
					'type' => 'checkboxes',
					'validate' => 'sanitize_text_field',
					'desc' => 'these are the statuses to log',
					'items' => array(
						'error' => array(
							'text' => 'Error',
							'id' => 'error',
							'desc' => '',
						),
						'success' => array(
							'text' => 'Success',
							'id' => 'success',
							'desc' => '',
						),
						'notice' => array(
							'text' => 'Notice',
							'id' => 'notice',
							'desc' => '',
						),
						'debug' => array(
							'text' => 'Debug',
							'id' => 'debug',
							'desc' => '',
						),
					),
				),
			),
			'prune_logs' => array(
				'title' => 'Automatically delete old log entries?',
				'callback' => $callbacks['text'],
				'page' => $page,
				'section' => $section,
				'args' => array(
					'type' => 'checkbox',
					'validate' => 'absint',
					'desc' => '',
					'constant' => '',
				),
			),
			'logs_how_old' => array(
				'title' => 'Age to delete log entries',
				'callback' => $callbacks['text'],
				'page' => $page,
				'section' => $section,
				'args' => array(
					'type' => 'text',
					'validate' => 'sanitize_text_field',
					'desc' => 'If automatic deleting is enabled, it will affect logs this old.',
					'default' => '2 weeks',
					'constant' => '',
				),
			),
			'logs_how_often_number' => array(
				'title' => __( 'Check for old logs every', $this->text_domain ),
				'callback' => $callbacks['text'],
				'page' => $page,
				'section' => $section,
				'args' => array(
					'type' => 'number',
					'validate' => 'absint',
					'desc' => '',
					'default' => '1',
					'constant' => '',
				),
			),
			'logs_how_often_unit' => array(
				'title' => __( 'Time unit', $this->text_domain ),
					'callback' => $callbacks['select'],
					'page' => $page,
					'section' => $section,
					'args' => array(
						'type' => 'select',
						'validate' => 'sanitize_text_field',
						'desc' => 'These two fields are how often the site will check for logs to delete.',
						'items' => array(
							'minutes' => array(
								'text' => 'Minutes',
								'value' => 'minutes',
							),
							'hours' => array(
								'text' => 'Hours',
								'value' => 'hours',
							),
							'days' => array(
								'text' => 'Days',
								'value' => 'days',
							),
						),
					),
			),
			'triggers_to_log' => array(
				'title' => 'Triggers to log',
				'callback' => $callbacks['checkboxes'],
				'page' => $page,
				'section' => $section,
				'args' => array(
					'type' => 'checkboxes',
					'validate' => 'sanitize_text_field',
					'desc' => 'these are the triggers to log',
					'items' => array(
						$this->mappings->sync_wordpress_create => array(
							'text' => 'WordPress create',
							'id' => 'wordpress_create',
							'desc' => '',
						),
						$this->mappings->sync_wordpress_update => array(
							'text' => 'WordPress update',
							'id' => 'wordpress_update',
							'desc' => '',
						),
						$this->mappings->sync_wordpress_delete => array(
							'text' => 'WordPress delete',
							'id' => 'wordpress_delete',
							'desc' => '',
						),
						$this->mappings->sync_sf_create => array(
							'text' => 'Salesforce create',
							'id' => 'sf_create',
							'desc' => '',
						),
						$this->mappings->sync_sf_update => array(
							'text' => 'Salesforce update',
							'id' => 'sf_update',
							'desc' => '',
						),
						$this->mappings->sync_sf_delete => array(
							'text' => 'Salesforce delete',
							'id' => 'sf_delete',
							'desc' => '',
						),
					),
				),
			),
		);
		foreach ( $log_settings as $key => $attributes ) {
			$id = 'object_sync_for_salesforce_' . $key;
			$name = 'object_sync_for_salesforce_' . $key;
			$title = $attributes['title'];
			$callback = $attributes['callback'];
			$page = $attributes['page'];
			$section = $attributes['section'];
			$args = array_merge(
				$attributes['args'],
				array(
					'title' => $title,
					'id' => $id,
					'label_for' => $id,
					'name' => $name,
				)
			);
			add_settings_field( $id, $title, $callback, $page, $section, $args );
			register_setting( $page, $id );
		}
	}

	/**
	* Create the notices, settings, and conditions by which admin notices should appear
	*
	*/
	public function notices() {

		require_once plugin_dir_path( __FILE__ ) . '../classes/admin_notice.php';

		$notices = array(
			'permission' => array(
				'condition' => false === $this->check_wordpress_admin_permissions(),
				'message' => "Your account does not have permission to edit the Salesforce REST API plugin's settings.",
				'type' => 'error',
				'dismissible' => false,
			),
			'fieldmap' => array(
				'condition' => isset( $_GET['transient'] ),
				'message' => 'Errors kept this fieldmap from being saved.',
				'type' => 'error',
				'dismissible' => true,
			),
		);

		$domain = $this->text_domain;

		foreach ( $notices as $key => $value ) {

			$condition = $value['condition'];
			$message = $value['message'];

			if ( isset( $value['dismissible'] ) ) {
				$dismissible = $value['dismissible'];
			} else {
				$dismissible = false;
			}

			if ( isset( $value['domain'] ) ) {
				$domain = $value['domain'];
			}

			if ( isset( $value['type'] ) ) {
				$type = $value['type'];
			} else {
				$type = '';
			}

			if ( ! isset( $value['template'] ) ) {
				$template = '';
			}

			if ( $condition ) {
				new Object_Sync_Sf_Admin_Notice( $condition, $message, $domain, $dismissible, $type, $template );
			}
		}

	}

	/**
	* Get all the Salesforce object settings for fieldmapping
	* This takes either the $_POST array via ajax, or can be directly called with a $data array
	*
	* @param array $data
	* data must contain a salesforce_object
	* can optionally contain a type
	* @return array $object_settings
	*/
	public function get_salesforce_object_description( $data = array() ) {
		$ajax = false;
		if ( empty( $data ) ) {
			$data = $_POST;
			$ajax = true;
		}

		$object_description = array();

		if ( ! empty( $data['salesforce_object'] ) ) {
			$object = $this->salesforce['sfapi']->object_describe( sanitize_key( $data['salesforce_object'] ) );

			$object_fields = array();
			$include_record_types = array();

			// these can come from ajax, so we should esc_attr them
			$include = isset( $data['include'] ) ? (array) $data['include'] : array();
			$include = array_map( 'esc_attr', $include );

			if ( in_array( 'fields', $include ) || empty( $include ) ) {
				$type = isset( $data['field_type'] ) ? esc_attr( $data['field_type'] ) : ''; // can come from ajax, so esc_attr
				foreach ( $object['data']['fields'] as $key => $value ) {
					if ( '' === $type || $type === $value['type'] ) {
						$object_fields[ $key ] = $value;
					}
				}
				$object_description['fields'] = $object_fields;
			}

			if ( in_array( 'recordTypeInfos', $include ) ) {
				if ( isset( $object['data']['recordTypeInfos'] ) && count( $object['data']['recordTypeInfos'] ) > 1 ) {
					foreach ( $object['data']['recordTypeInfos'] as $type ) {
						$object_record_types[ $type['recordTypeId'] ] = $type['name'];
					}
					$object_description['recordTypeInfos'] = $object_record_types;
				}
			}
		}

		if ( true === $ajax ) {
			wp_send_json_success( $object_description );
		} else {
			return $object_description;
		}
	}

	/**
	* Get Salesforce object fields for fieldmapping
	*
	* @param array $data
	* data must contain a salesforce_object
	* can optionally contain a type for the field
	* @return array $object_fields
	*/
	public function get_salesforce_object_fields( $data = array() ) {

		if ( ! empty( $data['salesforce_object'] ) ) {
			$object = $this->salesforce['sfapi']->object_describe( esc_attr( $data['salesforce_object'] ) );
			$object_fields = array();
			$type = isset( $data['type'] ) ? esc_attr( $data['type'] ) : '';
			$include_record_types = isset( $data['include_record_types'] ) ? esc_attr( $data['include_record_types'] ) : false;
			foreach ( $object['data']['fields'] as $key => $value ) {
				if ( '' === $type || $type === $value['type'] ) {
					$object_fields[ $key ] = $value;
				}
			}
			if ( true === $include_record_types ) {
				$object_record_types = array();
				if ( isset( $object['data']['recordTypeInfos'] ) && count( $object['data']['recordTypeInfos'] ) > 1 ) {
					foreach ( $object['data']['recordTypeInfos'] as $type ) {
						$object_record_types[ $type['recordTypeId'] ] = $type['name'];
					}
				}
			}
		}

		return $object_fields;

	}

	/**
	* Get WordPress object fields for fieldmapping
	* This takes either the $_POST array via ajax, or can be directly called with a $wordpress_object field
	*
	* @param string $wordpress_object
	* @return array $object_fields
	*/
	public function get_wordpress_object_fields( $wordpress_object = '' ) {
		$ajax = false;
		if ( empty( $wordpress_object ) ) {
			$wordpress_object = sanitize_key( $_POST['wordpress_object'] );
			$ajax = true;
		}

		$object_fields = $this->wordpress->get_wordpress_object_fields( $wordpress_object );

		if ( true === $ajax ) {
			wp_send_json_success( $object_fields );
		} else {
			return $object_fields;
		}
	}

	/**
	* Get WordPress and Salesforce object fields together for fieldmapping
	* This takes either the $_POST array via ajax, or can be directly called with $wordpress_object and $salesforce_object fields
	*
	* @param string $wordpress_object
	* @param string $salesforce_object
	* @return array $object_fields
	*/
	public function get_wp_sf_object_fields( $wordpress_object = '', $salesforce = '' ) {
		if ( empty( $wordpress_object ) ) {
			$wordpress_object = sanitize_key( $_POST['wordpress_object'] );
		}
		if ( empty( $salesforce_object ) ) {
			$salesforce_object = sanitize_key( $_POST['salesforce_object'] );
		}

		$object_fields['wordpress'] = $this->get_wordpress_object_fields( $wordpress_object );
		$object_fields['salesforce'] = $this->get_salesforce_object_fields(
			array(
				'salesforce_object' => $salesforce_object,
			)
		);

		if ( ! empty( $_POST ) ) {
			wp_send_json_success( $object_fields );
		} else {
			return $object_fields;
		}
	}

	/**
	* Manually push the WordPress object to Salesforce
	* This takes either the $_POST array via ajax, or can be directly called with $wordpress_object and $wordpress_id fields
	*
	* @param string $wordpress_object
	* @param int $wordpress_id
	*/
	public function push_to_salesforce( $wordpress_object = '', $wordpress_id = '' ) {
		if ( empty( $wordpress_object ) && empty( $wordpress_id ) ) {
			$wordpress_object = sanitize_key( $_POST['wordpress_object'] );
			$wordpress_id = sanitize_key( $_POST['wordpress_id'] );
		}
		$data = $this->wordpress->get_wordpress_object_data( $wordpress_object, $wordpress_id );
		$result = $this->push->manual_object_update( $data, $wordpress_object );

		if ( ! empty( $_POST['wordpress_object'] ) && ! empty( $_POST['wordpress_id'] ) ) {
			wp_send_json_success( $result );
		} else {
			return $result;
		}

	}

	/**
	* Manually pull the Salesforce object into WordPress
	* This takes either the $_POST array via ajax, or can be directly called with $salesforce_id fields
	*
	* @param string $salesforce_id
	* @param string $wordpress_object
	*/
	public function pull_from_salesforce( $salesforce_id = '', $wordpress_object = '' ) {
		if ( empty( $wordpress_object ) && empty( $salesforce_id ) ) {
			$wordpress_object = sanitize_key( $_POST['wordpress_object'] );
			$salesforce_id = sanitize_key( $_POST['salesforce_id'] );
		}
		$type = $this->salesforce['sfapi']->get_sobject_type( $salesforce_id );
		$result = $this->pull->manual_pull( $type, $salesforce_id, $wordpress_object ); // we want the wp object to make sure we get the right fieldmap
		if ( ! empty( $_POST ) ) {
			wp_send_json_success( $result );
		} else {
			return $result;
		}
	}

	/**
	* Manually pull the Salesforce object into WordPress
	* This takes either the $_POST array via ajax, or can be directly called with $salesforce_id fields
	*
	* @param string $salesforce_id
	* @param string $wordpress_object
	*/
	public function refresh_mapped_data( $mapping_id = '' ) {
		if ( empty( $mapping_id ) ) {
			$mapping_id = sanitize_key( $_POST['mapping_id'] );
		}
		$result = $this->mappings->get_object_maps(
			array(
				'id' => $mapping_id,
			)
		);
		if ( ! empty( $_POST ) ) {
			wp_send_json_success( $result );
		} else {
			return $result;
		}
	}

	/**
	* Prepare fieldmap data and redirect after processing
	* This runs when the create or update forms are submitted
	* It is public because it depends on an admin hook
	* It then calls the Object_Sync_Sf_Mapping class and sends prepared data over to it, then redirects to the correct page
	* This method does include error handling, by loading the submission in a transient if there is an error, and then deleting it upon success
	*
	*/
	public function prepare_fieldmap_data() {
		$error = false;
		$cachekey = md5( json_encode( $_POST ) ); // we should not have to worry about md5 collision attacks here, right?

		if ( ! isset( $_POST['label'] ) || ! isset( $_POST['salesforce_object'] ) || ! isset( $_POST['wordpress_object'] ) ) {
			$error = true;
		}
		if ( $error === true ) {
			set_transient( $cachekey, $_POST, 0 );
			if ( '' !== $cachekey ) {
				$url = esc_url_raw( $_POST['redirect_url_error'] ) . '&transient=' . $cachekey;
			}
		} else { // there are no errors
			// send the row to the fieldmap class
			// if it is add or clone, use the create method
			$method = sanitize_key( $_POST['method'] );
			$salesforce_fields = $this->get_salesforce_object_fields(
				array(
					'salesforce_object' => $_POST['salesforce_object'],
				)
			);

			$wordpress_fields = $this->get_wordpress_object_fields( $_POST['wordpress_object'] );

			if ( 'add' === $method || 'clone' === $method ) {
				$result = $this->mappings->create_fieldmap( $_POST, $wordpress_fields, $salesforce_fields );
			} elseif ( 'edit' === $method ) { // if it is edit, use the update method
				$id = esc_attr( $_POST['id'] );
				$result = $this->mappings->update_fieldmap( $_POST, $wordpress_fields, $salesforce_fields, $id );
			}

			if ( false === $result ) { // if the database didn't save, it's still an error
				set_transient( $cachekey, $_POST, 0 );
				if ( '' !== $cachekey ) {
					$url = esc_url_raw( $_POST['redirect_url_error'] ) . '&transient=' . $cachekey;
				}
			} else {
				if ( isset( $_POST['transient'] ) ) { // there was previously an error saved. can delete it now.
					delete_transient( esc_attr( $_POST['transient'] ) );
				}
				// then send the user to the list of fieldmaps
				$url = esc_url_raw( $_POST['redirect_url_success'] );
			}
		}
		wp_redirect( $url );
		exit();
	}

	/**
	* Delete fieldmap data and redirect after processing
	* This runs when the delete link is clicked, after the user confirms
	* It is public because it depends on an admin hook
	* It then calls the Object_Sync_Sf_Mapping class and the delete method
	*
	*/
	public function delete_fieldmap() {
		if ( $_POST['id'] ) {
			$result = $this->mappings->delete_fieldmap( $_POST['id'] );
			if ( true === $result ) {
				$url = esc_url_raw( $_POST['redirect_url_success'] );
			} else {
				$url = esc_url_raw( $_POST['redirect_url_error'] ) . '&id=' . $_POST['id'];
			}
			wp_redirect( $url );
			exit();
		}
	}

	/**
	* Default display for <input> fields
	*
	* @param array $args
	*/
	public function display_input_field( $args ) {
		$type   = $args['type'];
		$id     = $args['label_for'];
		$name   = $args['name'];
		$desc   = $args['desc'];
		$checked = '';

		$class = 'regular-text';

		if ( 'checkbox' === $type ) {
			$class = 'checkbox';
		}

		if ( ! isset( $args['constant'] ) || ! defined( $args['constant'] ) ) {
			$value  = esc_attr( get_option( $id, '' ) );
			if ( 'checkbox' === $type ) {
				if ( '1' === $value ) {
					$checked = 'checked ';
				}
				$value = 1;
			}
			if ( '' === $value && isset( $args['default'] ) && '' !== $args['default'] ) {
				$value = $args['default'];
			}
			echo '<input type="' . $type. '" value="' . $value . '" name="' . $name . '" id="' . $id . '"
			class="' . $class . ' code" ' . $checked . ' />';
			if ( '' !== $desc ) {
				echo '<p class="description">' . $desc . '</p>';
			}
		} else {
			echo '<p><code>Defined in wp-config.php</code></p>';
		}
	}

	/**
	* Display for multiple checkboxes
	* Above method can handle a single checkbox as it is
	*
	* @param array $args
	*/
	public function display_checkboxes( $args ) {
		$type = 'checkbox';
		$name = $args['name'];
		$options = get_option( $name, array() );
		foreach ( $args['items'] as $key => $value ) {
			$text = $value['text'];
			$id = $value['id'];
			$desc = $value['desc'];
			$checked = '';
			if ( is_array( $options ) && in_array( $key, $options ) ) {
				$checked = 'checked';
			} elseif ( is_array( $options ) && empty( $options ) ) {
				if ( isset( $value['default'] ) && true === $value['default'] ) {
					$checked = 'checked';
				}
			}
			echo '<div class="checkbox"><label><input type="' . $type. '" value="' . $key . '" name="' . $name . '[]" id="' . $id . '" ' . $checked . ' />' . $text . '</label></div>';
			if ( '' !== $desc ) {
				echo '<p class="description">' . $desc . '</p>';
			}
		}
	}

	/**
	* Display for a dropdown
	*
	* @param array $args
	*/
	public function display_select( $args ) {
		$type   = $args['type'];
		$id     = $args['label_for'];
		$name   = $args['name'];
		$desc   = $args['desc'];
		if ( ! isset( $args['constant'] ) || ! defined( $args['constant'] ) ) {
			$current_value = get_option( $name );
			echo '<div><select id="' . $id . '" name="' . $name . '"><option value="">- Select one -</option>';
			foreach ( $args['items'] as $key => $value ) {
				$text = $value['text'];
				$value = $value['value'];
				$selected = '';
				if ( $key === $current_value || $value === $current_value ) {
					$selected = ' selected';
				}
				echo '<option value="' . $value . '"' . $selected . '>' . $text . '</option>';
			}
			echo '</select>';
			if ( '' !== $desc ) {
				echo '<p class="description">' . $desc . '</p>';
			}
			echo '</div>';
		} else {
			echo '<p><code>Defined in wp-config.php</code></p>';
		}
	}

	/**
	* Dropdown formatted list of Salesforce API versions
	*
	* @return array $args
	*/
	private function version_options() {
		$versions = $this->salesforce['sfapi']->get_api_versions();
		$args = array();
		foreach ( $versions['data'] as $key => $value ) {
			$args[] = array(
				'value' => $value['version'],
				'text' => $value['label'] . ' (' . $value['version'] . ')',
			);
		}
		return $args;
	}

	/**
	* Default display for <a href> links
	*
	* @param array $args
	*/
	public function display_link( $args ) {
		$label   = $args['label'];
		$desc   = $args['desc'];
		$url = $args['url'];
		if ( isset( $args['link_class'] ) ) {
			$class = ' class="' . $args['link_class'] . '"';
		} else {
			$class = '';
		}

		echo '<p><a' . $class . ' href="' . $url . '">' . $label . '</a></p>';

		if ( '' !== $desc ) {
			echo '<p class="description">' . $desc . '</p>';
		}

	}

	/**
	* Run a demo of Salesforce API call on the authenticate tab after WordPress has authenticated with it
	*
	* @param object $sfapi
	*/
	private function status( $sfapi ) {

		$versions = $sfapi->get_api_versions();

		// format this array into html so users can see the versions
		$versions_is_cached = true === $versions['cached'] ? '' : 'not ';
		$versions_from_cache = true === $versions['from_cache'] ? 'were' : 'were not';
		$versions_is_redo = true === $versions['is_redo'] ? '' : 'not ';
		$versions_andorbut = true === $versions['from_cache'] ? 'and' : 'but';

		$contacts = $sfapi->query( 'SELECT Name, Id from Contact LIMIT 100' );

		// format this array into html so users can see the contacts
		$contacts_is_cached = true === $contacts['cached'] ? '' : 'not ';
		$contacts_from_cache = true === $contacts['from_cache'] ? 'were' : 'were not';
		$contacts_andorbut = true === $contacts['from_cache'] ? 'and' : 'but';
		$contacts_is_redo = true === $contacts['is_redo'] ? '' : 'not ';

		require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/status.php' );

	}

	/**
	* Deauthorize WordPress from Salesforce.
	* This deletes the tokens from the database; it does not currently do anything in Salesforce
	* For this plugin at this time, that is the decision we are making: don't do any kind of authorization stuff inside Salesforce
	*/
	private function logout() {
		$this->access_token = delete_option( 'object_sync_for_salesforce_access_token' );
		$this->instance_url = delete_option( 'object_sync_for_salesforce_instance_url' );
		$this->refresh_token = delete_option( 'object_sync_for_salesforce_refresh_token' );
		return 'You have been logged out. You can use use the connect button to log in again.';
	}

	/**
	* Check Wordpress Admin permissions
	* Check if the current user is allowed to access the Salesforce plugin options
	*/
	private function check_wordpress_admin_permissions() {

		// one programmatic way to give this capability to additional user roles is the
		// object_sync_for_salesforce_roles_configure_salesforce hook
		// it runs on activation of this plugin, and will assign the below capability to any role
		// coming from the hook

		// alternatively, other roles can get this capability in whatever other way you like
		// point is: to administer this plugin, you need this capability

		if ( ! current_user_can( 'configure_salesforce' ) ) {
			return false;
		} else {
			return true;
		}

	}

	/**
	* Show what we know about this user's relationship to a Salesforce object, if any
	* @param object $user
	*
	*/
	public function show_salesforce_user_fields( $user ) {
		if ( true === $this->check_wordpress_admin_permissions() ) {
			$mapping = $this->mappings->load_by_wordpress( 'user', $user->ID );
			$fieldmap = $this->mappings->get_fieldmaps(
				null, // id field must be null for multiples
				array(
					'wordpress_object' => 'user',
				)
			);
			if ( isset( $mapping['id'] ) && ! isset( $_GET['edit_salesforce_mapping'] ) ) {
				require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/user-profile-salesforce.php' );
			} elseif ( ! empty( $fieldmap ) ) { // is the user mapped to something already?
				if ( isset( $_GET['edit_salesforce_mapping'] ) && 'true' === urlencode( $_GET['edit_salesforce_mapping'] ) ) {
					require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/user-profile-salesforce-change.php' );
				} else {
					require_once( plugin_dir_path( __FILE__ ) . '/../templates/admin/user-profile-salesforce-map.php' );
				}
			}
		}
	}

	/**
	* If the user profile has been mapped to Salesforce, do it
	* @param int $user_id
	*
	*/
	public function save_salesforce_user_fields( $user_id ) {
		if ( isset( $_POST['salesforce_update_mapped_user'] ) && urlencode( '1' === $_POST['salesforce_update_mapped_user'] ) ) {
			$mapping_object = $this->mappings->get_object_maps(
				array(
					'wordpress_id' => $user_id,
					'wordpress_object' => 'user',
				)
			);
			$mapping_object['salesforce_id'] = $_POST['salesforce_id'];
			$result = $this->mappings->update_object_map( $mapping_object, $mapping_object['id'] );
		} elseif ( isset( $_POST['salesforce_create_mapped_user'] ) && '1' === urlencode( $_POST['salesforce_create_mapped_user'] ) ) {
			// if a Salesforce ID was entered
			if ( isset( $_POST['salesforce_id'] ) && ! empty( $_POST['salesforce_id'] ) ) {
				$mapping_object = $this->create_object_map( $user_id, 'user', $_POST['salesforce_id'] );
			} elseif ( isset( $_POST['push_new_user_to_salesforce'] ) ) {
				// otherwise, create a new record in Salesforce
				$result = $this->push_to_salesforce( 'user', $user_id );
			}
		}
	}

	/**
	* Render tabs for settings pages in admin
	* @param array $tabs
	* @param string $tab
	*/
	private function tabs( $tabs, $tab = '' ) {

		$consumer_key = $this->login_credentials['consumer_key'];
		$consumer_secret = $this->login_credentials['consumer_secret'];
		$callback_url = $this->login_credentials['callback_url'];
		$text_domain = $this->text_domain;

		$current_tab = $tab;
		screen_icon();
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $tab_key => $tab_caption ) {
			$active = $current_tab === $tab_key ? 'nav-tab-active' : '';
			if ( 'settings' === $tab_key || ( isset( $consumer_key ) && isset( $consumer_secret ) && ! empty( $consumer_key ) && ! empty( $consumer_secret ) ) ) {
				echo '<a class="nav-tab ' . $active . '" href="?page=object-sync-salesforce-admin&tab=' . $tab_key . '">' . $tab_caption . '</a>';
			}
		}
		echo '</h2>';

		if ( isset( $_GET['tab'] ) ) {
			$tab = urlencode( $_GET['tab'] );
		} else {
			$tab = '';
		}
	}

	/**
	* Clear schedule
	* This clears the schedule if the user clicks the button
	*/
	private function clear_schedule( $schedule_name = '' ) {
		if ( '' !== $schedule_name ) {
			$schedule = $this->schedule( $schedule_name );
			$schedule->cancel_by_name( $schedule_name );
			return 'You have cleared the ' . $schedule_name . ' schedule.';
		} else {
			return 'You need to specify the name of the schedule you want to clear.';
		}
	}

	private function get_schedule_count( $schedule_name = '' ) {
		if ( '' !== $schedule_name ) {
			$schedule = $this->schedule( $schedule_name );
			return $this->schedule->count_queue_items( $schedule_name );
		} else {
			return 'unknown';
		}
	}

	/**
	* Load the schedule class
	*/
	private function schedule( $schedule_name ) {
		if ( ! class_exists( 'Object_Sync_Sf_Schedule' ) && file_exists( plugin_dir_path( __FILE__ ) . '../vendor/autoload.php' ) ) {
			require_once plugin_dir_path( __FILE__ ) . '../vendor/autoload.php';
			require_once plugin_dir_path( __FILE__ ) . '../classes/schedule.php';
		}
		$schedule = new Object_Sync_Sf_Schedule( $this->wpdb, $this->version, $this->login_credentials, $this->text_domain, $this->wordpress, $this->salesforce, $this->mappings, $schedule_name, $this->logging, $this->schedulable_classes );
		$this->schedule = $schedule;
		return $schedule;
	}

	/**
	* Create an object map between a WordPress object and a Salesforce object
	*
	* @param int $wordpress_id
	*   Unique identifier for the WordPress object
	* @param string $wordpress_object
	*   What kind of object is it?
	* @param string $salesforce_id
	*   Unique identifier for the Salesforce object
	* @param string $action
	*   Did we push or pull?
	*
	* @return int $wpdb->insert_id
	*   This is the database row for the map object
	*
	*/
	private function create_object_map( $wordpress_id, $wordpress_object, $salesforce_id, $action = '' ) {
		// Create object map and save it
		$mapping_object = $this->mappings->create_object_map(
			array(
				'wordpress_id' => $wordpress_id, // wordpress unique id
				'salesforce_id' => $salesforce_id, // salesforce unique id. we don't care what kind of object it is at this point
				'wordpress_object' => $wordpress_object, // keep track of what kind of wp object this is
				'last_sync' => current_time( 'mysql' ),
				'last_sync_action' => $action,
				'last_sync_status' => $this->mappings->status_success,
				'last_sync_message' => __( 'Mapping object updated via function: ', $this->text_domain ) . __FUNCTION__,
			)
		);

		return $mapping_object;

	}

}
