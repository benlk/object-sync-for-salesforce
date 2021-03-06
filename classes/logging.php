<?php
/**
 * @file
 */

if ( ! class_exists( 'Object_Sync_Salesforce' ) ) {
	die();
}

/**
 * Log events based on plugin settings
 */
class Salesforce_Logging extends WP_Logging {

	protected $wpdb;
	protected $version;
	protected $text_domain;

	public $enabled;
	public $statuses_to_log;


	/**
	* Constructor which sets content type and pruning
	*
	* @param object $wpdb
	* @param string $version
	* @param string $text_domain
	* @throws \Exception
	*/
	public function __construct( $wpdb, $version, $text_domain ) {
		$this->wpdb = $wpdb;
		$this->version = $version;
		$this->text_domain = $text_domain;

		$this->enabled = get_option( 'object_sync_for_salesforce_enable_logging', false );
		$this->statuses_to_log = get_option( 'object_sync_for_salesforce_statuses_to_log', array() );

		$this->schedule_name = 'wp_logging_prune_routine';

		$this->init();

	}

	/**
	* Start. This creates a schedule for pruning logs, and also the custom content type
	*
	* @throws \Exception
	*/
	private function init() {
		if ( '1' === $this->enabled ) {
			add_filter( 'cron_schedules', array( $this, 'add_prune_interval' ) );
			add_filter( 'wp_log_types', array( $this, 'set_log_types' ), 10, 1 );
			add_filter( 'wp_logging_should_we_prune', array( $this, 'set_prune_option' ), 10, 1 );
			add_filter( 'wp_logging_prune_when', array( $this, 'set_prune_age' ), 10, 1 );
			add_filter( 'wp_logging_prune_query_args', array( $this, 'set_prune_args' ), 10, 1 );

			$schedule_unit = get_option( 'object_sync_for_salesforce_logs_how_often_unit', '' );
			$schedule_number = get_option( 'object_sync_for_salesforce_logs_how_often_number', '' );
			$frequency = $this->get_schedule_frequency( $schedule_unit, $schedule_number );
			$key = $frequency['key'];

			if ( ! wp_next_scheduled( $this->schedule_name ) ) {
				wp_schedule_event( time(), $key, $this->schedule_name );
			}
		}
	}

	/**
	* add interval to wp schedules based on admin settings
	*
	* @return array $frequency
	*/
	public function add_prune_interval( $schedules ) {

		$schedule_unit = get_option( 'object_sync_for_salesforce_logs_how_often_unit', '' );
		$schedule_number = get_option( 'object_sync_for_salesforce_logs_how_often_number', '' );
		$frequency = $this->get_schedule_frequency( $schedule_unit, $schedule_number );
		$key = $frequency['key'];
		$seconds = $frequency['seconds'];

		$schedules[ $key ] = array(
			'interval' => $seconds * $schedule_number,
			'display' => 'Every ' . $schedule_number . ' ' . $schedule_unit,
		);

		return $schedules;

	}

	/**
	* Convert the schedule frequency from the admin settings into an array
	* interval must be in seconds for the class to use it
	*
	*/
	public function get_schedule_frequency( $unit, $number ) {

		switch ( $unit ) {
			case 'minutes':
				$seconds = 60;
				break;
			case 'hours':
				$seconds = 3600;
				break;
			case 'days':
				$seconds = 86400;
				break;
			default:
				$seconds = 0;
		}

		$key = $unit . '_' . $number;

		return array(
			'key' => $key,
			'seconds' => $seconds,
		);

	}

	/**
	* Set terms for Salesforce logs
	*
	* @param array $terms
	* @return array $terms
	*/
	public function set_log_types( $terms ) {
		$terms[] = 'salesforce';
		return $terms;
	}

	/**
	* Should logs be pruned at all?
	*
	* @param string $should_we_prune
	* @return string $should_we_prune
	*/
	public function set_prune_option( $should_we_prune ) {
		$should_we_prune = get_option( 'object_sync_for_salesforce_prune_logs', $should_we_prune );
		if ( '1' === $should_we_prune ) {
			$should_we_prune = true;
		}
		return $should_we_prune;
	}

	/**
	* Set how often to prune the Salesforce logs
	*
	* @param string $how_old
	* @return string $how_old
	*/
	public function set_prune_age( $how_old ) {
		$value = get_option( 'object_sync_for_salesforce_logs_how_old', '' ) . ' ago';
		if ( '' !== $value ) {
			return $value;
		} else {
			return $how_old;
		}
	}

	/**
	* Set arguments for only getting the Salesforce logs
	*
	* @param array $args
	* @return array $args
	*/
	public function set_prune_args( $args ) {
		$args['wp_log_type'] = 'salesforce';
		return $args;
	}

	/**
	 * Setup new log entry
	 *
	 * Check and see if we should log anything, and if so, send it to add()
	 *
	 * @access      public
	 * @since       1.0
	 *
	 * @uses        self::add()
	 *
	 * @return      none
	*/

	public function setup( $title, $message, $trigger = 0, $parent = 0, $status ) {
		if ( '1' === $this->enabled && in_array( $status, $this->statuses_to_log ) ) {
			$triggers_to_log = get_option( 'object_sync_for_salesforce_triggers_to_log', array() );
			if ( in_array( $trigger, $triggers_to_log ) || 0 === $trigger ) {
				$this->add( $title, $message, $parent );
			}
		}
	}

	/**
	 * Create new log entry
	 *
	 * This is just a simple and fast way to log something. Use self::insert_log()
	 * if you need to store custom meta data
	 *
	 * @access      public
	 * @since       1.0
	 *
	 * @uses        self::insert_log()
	 *
	 * @return      int The ID of the new log entry
	*/

	public static function add( $title = '', $message = '', $parent = 0, $type = 'salesforce' ) {

		$log_data = array(
			'post_title'   => $title,
			'post_content' => $message,
			'post_parent'  => $parent,
			'log_type'     => $type,
		);

		return self::insert_log( $log_data );

	}


	/**
	 * Easily retrieves log items for a particular object ID
	 *
	 * @access      private
	 * @since       1.0
	 *
	 * @uses        self::get_connected_logs()
	 *
	 * @return      array
	*/

	public static function get_logs( $object_id = 0, $type = 'salesforce', $paged = null ) {
		return self::get_connected_logs(
			array(
				'post_parent' => $object_id,
				'paged' => $paged,
				'log_type' => $type,
			)
		);
	}


	/**
	 * Retrieve all connected logs
	 *
	 * Used for retrieving logs related to particular items, such as a specific purchase.
	 *
	 * @access  private
	 * @since   1.0
	 *
	 * @uses    wp_parse_args()
	 * @uses    get_posts()
	 * @uses    get_query_var()
	 * @uses    self::valid_type()
	 *
	 * @return  array / false
	*/

	public static function get_connected_logs( $args = array() ) {

		$defaults = array(
			'post_parent'    => 0,
			'post_type'      => 'wp_log',
			'posts_per_page' => 10,
			'post_status'    => 'publish',
			'paged'          => get_query_var( 'paged' ),
			'log_type'       => 'salesforce',
		);

		$query_args = wp_parse_args( $args, $defaults );

		if ( $query_args['log_type'] && self::valid_type( $query_args['log_type'] ) ) {

			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'wp_log_type',
					'field'    => 'slug',
					'terms'    => $query_args['log_type'],
				),
			);

		}

		$logs = get_posts( $query_args );

		if ( $logs ) {
			return $logs;
		}

		// no logs found
		return false;

	}


	/**
	 * Retrieves number of log entries connected to particular object ID
	 *
	 * @access  private
	 * @since   1.0
	 *
	 * @uses    WP_Query()
	 * @uses    self::valid_type()
	 *
	 * @return  int
	*/

	public static function get_log_count( $object_id = 0, $type = 'salesforce', $meta_query = null ) {

		$query_args = array(
			'post_parent'    => $object_id,
			'post_type'      => 'wp_log',
			'posts_per_page' => 100,
			'post_status'    => 'publish',
		);

		if ( ! empty( $type ) && self::valid_type( $type ) ) {

			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'wp_log_type',
					'field'    => 'slug',
					'terms'    => $type,
				),
			);

		}

		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query;
		}

		$logs = new WP_Query( $query_args );

		return (int) $logs->post_count;

	}

}
