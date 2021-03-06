<?php
/**
 * @file
 */

if ( ! class_exists( 'Object_Sync_Salesforce' ) ) {
	die();
}

/**
 * Map objects and records between WordPress and Salesforce
 */
class Salesforce_Mapping {

	protected $wpdb;
	protected $version;
	protected $text_domain;
	protected $logging;

	protected $fieldmap_table;
	protected $object_map_table;

	public $sync_off;
	public $sync_wordpress_create;
	public $sync_wordpress_update;
	public $sync_wordpress_delete;
	public $sync_sf_create;
	public $sync_sf_update;
	public $sync_sf_delete;
	public $wordpress_events;
	public $salesforce_events;

	public $direction_wordpress_sf;
	public $direction_sf_wordpress;
	public $direction_sync;

	public $direction_wordpress;
	public $direction_salesforce;

	public $salesforce_default_record_type;

	public $array_delimiter;

	public $name_length;

	public $status_success;
	public $status_error;

	/**
	* Constructor which sets up links between the systems
	*
	* @param object $wpdb
	* @param string $version
	* @param string $text_domain
	* @param object $logging
	* @throws \Exception
	*/
	public function __construct( $wpdb, $version, $text_domain, $logging ) {
		$this->wpdb = $wpdb;
		$this->version = $version;
		$this->text_domain = $text_domain;
		$this->logging = $logging;

		$this->fieldmap_table = $this->wpdb->prefix . 'salesforce_field_map';
		$this->object_map_table = $this->wpdb->prefix . 'salesforce_object_map';

		// this is how we define when syncing should occur on each field map
		// it gets used in the admin settings, as well as the push/pull methods to see if something should happen
		// i don't know why it uses these bit flags, but can't think of a reason not to keep the convention
		$this->sync_off = 0x0000;
		$this->sync_wordpress_create = 0x0001;
		$this->sync_wordpress_update = 0x0002;
		$this->sync_wordpress_delete = 0x0004;
		$this->sync_sf_create = 0x0008;
		$this->sync_sf_update = 0x0010;
		$this->sync_sf_delete = 0x0020;

		// define which events are initialized by which system
		$this->wordpress_events = array( $this->sync_wordpress_create, $this->sync_wordpress_update, $this->sync_wordpress_delete );
		$this->salesforce_events = array( $this->sync_sf_create, $this->sync_sf_update, $this->sync_sf_delete );

		// constants for the directions to map things
		$this->direction_wordpress_sf = 'wp_sf';
		$this->direction_sf_wordpress = 'sf_wp';
		$this->direction_sync = 'sync';

		$this->direction_wordpress = array( $this->direction_wordpress_sf, $this->direction_sync );
		$this->direction_salesforce = array( $this->direction_sf_wordpress, $this->direction_sync );

		// this is used when we map a record with default or Master
		$this->salesforce_default_record_type = 'default';

		// salesforce has multipicklists and they have a delimiter
		$this->array_delimiter = ';';

		// max length for a mapping field
		$this->name_length = 128;

		// statuses for object sync
		$this->status_success = 1;
		$this->status_error = 0;

	}

	/**
	* Create a fieldmap row between a WordPress and Salesforce object
	*
	* @param array $posted
	* @param array $wordpress_fields
	* @param array $salesforce_fields
	* @throws \Exception
	*/
	public function create_fieldmap( $posted = array(), $wordpress_fields = array(), $salesforce_fields = array() ) {
		$data = $this->setup_fieldmap_data( $posted, $wordpress_fields, $salesforce_fields );
		$insert = $this->wpdb->insert( $this->fieldmap_table, $data );
		if ( 1 === $insert ) {
			return $this->wpdb->insert_id;
		} else {
			return false;
		}
	}

	/**
	* Get one or more fieldmap rows between a WordPress and Salesforce object
	*
	* @param int $id
	* @param array $conditions
	* @param bool $reset
	* @return $map or $mappings
	* @throws \Exception
	*/
	public function get_fieldmaps( $id = null, $conditions = array(), $reset = false ) {
		$table = $this->fieldmap_table;
		if ( null !== $id ) { // get one fieldmap
			$map = $this->wpdb->get_row( 'SELECT * FROM ' . $table . ' WHERE id = ' . $id, ARRAY_A );
			$map['salesforce_record_types_allowed'] = maybe_unserialize( $map['salesforce_record_types_allowed'] );
			$map['fields'] = maybe_unserialize( $map['fields'] );
			$map['sync_triggers'] = maybe_unserialize( $map['sync_triggers'] );
			return $map;
		} elseif ( ! empty( $conditions ) ) { // get multiple but with a limitation
			$mappings = array();
			$record_type = '';

			if ( ! empty( $conditions ) ) {
				$where = ' WHERE ';
				$i = 0;
				foreach ( $conditions as $key => $value ) {
					if ( 'salesforce_record_type' === $key ) {
						$record_type = $value;
					} else {
						$i++;
						if ( $i > 1 ) {
							$where .= ' AND ';
						}
						$where .= '`' . $key . '`' . ' = "' . $value . '"';
					}
				}
			} else {
				$where = '';
			}

			$mappings = $this->wpdb->get_results( 'SELECT * FROM ' . $table . $where . ' ORDER BY `weight`', ARRAY_A );

			if ( ! empty( $mappings ) ) {
				$mappings = $this->prepare_fieldmap_data( $mappings, $record_type );
			}

			return $mappings;

		} else { // get all of em

			$mappings = $this->wpdb->get_results( "SELECT `id`, `label`, `wordpress_object`, `salesforce_object`, `salesforce_record_types_allowed`, `salesforce_record_type_default`, `fields`, `pull_trigger_field`, `sync_triggers`, `push_async`, `push_drafts`, `weight` FROM $table" , ARRAY_A );

			if ( ! empty( $mappings ) ) {
				$mappings = $this->prepare_fieldmap_data( $mappings );
			}

			return $mappings;
		} // End if().
	}

	public function get_mapped_fields( $mapping, $directions = array() ) {
		$mapped_fields = array();
		foreach ( $mapping['fields'] as $fields ) {
			if ( empty( $directions ) || in_array( $fields['direction'], $directions ) ) {
				// Some field map types (Relation) store a collection of SF objects.
				if ( is_array( $fields['salesforce_field'] ) && ! isset( $fields['salesforce_field']['label'] ) ) {
					foreach ( $fields['salesforce_field'] as $sf_field ) {
						$mapped_fields[ $sf_field['label'] ] = $sf_field['label'];
					}
				} else { // The rest are just a name/value pair.
					$mapped_fields[ $fields['salesforce_field']['label'] ] = $fields['salesforce_field']['label'];
				}
			}
		}

		if ( ! empty( $this->get_mapped_record_types ) ) {
			$mapped_fields['RecordTypeId'] = 'RecordTypeId';
		}

		return $mapped_fields;
	}

	public function get_mapped_record_types( $mapping ) {
		return $mapping['salesforce_record_type_default'] === $this->salesforce_default_record_type ? array() : array_filter( maybe_unserialize( $mapping['salesforce_record_types_allowed'] ) );
	}

	/**
	* Update a fieldmap row between a WordPress and Salesforce object
	*
	* @param array $posted
	* @param array $wordpress_fields
	* @param array $salesforce_fields
	* @param int $id
	* @return $map
	* @throws \Exception
	*/
	public function update_fieldmap( $posted = array(), $wordpress_fields = array(), $salesforce_fields = array(), $id = '' ) {
		$data = $this->setup_fieldmap_data( $posted, $wordpress_fields, $salesforce_fields );
		$update = $this->wpdb->update(
			$this->fieldmap_table,
			$data,
			array(
				'id' => $id,
			)
		);
		if ( false === $update ) {
			return false;
		} else {
			return true;
		}
	}

	/**
	* Setup fieldmap data
	* Sets up the database entry for mapping the object types between Salesforce and WordPress
	*
	* @param array $posted
	* @param array $wordpress_fields
	* @param array $salesforce_fields
	* @return $data
	*/
	private function setup_fieldmap_data( $posted = array(), $wordpress_fields = array(), $salesforce_fields = array() ) {
		$data = array(
			'label' => $posted['label'],
			'name' => sanitize_title( $posted['label'] ),
			'salesforce_object' => $posted['salesforce_object'],
			'wordpress_object' => $posted['wordpress_object'],
		);
		if ( isset( $posted['wordpress_field'] ) && is_array( $posted['wordpress_field'] ) && isset( $posted['salesforce_field'] ) && is_array( $posted['salesforce_field'] ) ) {
			$setup['fields'] = array();
			foreach ( $posted['wordpress_field'] as $key => $value ) {
				$method_key = array_search( $value, array_column( $wordpress_fields, 'key' ) );
				if ( ! isset( $posted['direction'][ $key ] ) ) {
					$posted['direction'][ $key ] = 'sync';
				}
				if ( ! isset( $posted['is_prematch'][ $key ] ) ) {
					$posted['is_prematch'][ $key ] = false;
				}
				if ( ! isset( $posted['is_key'][ $key ] ) ) {
					$posted['is_key'][ $key ] = false;
				}
				if ( ! isset( $posted['is_delete'][ $key ] ) ) {
					$posted['is_delete'][ $key ] = false;
				}
				if ( false === $posted['is_delete'][ $key ] ) {
					$updateable_key = array_search( $posted['salesforce_field'][ $key ], array_column( $salesforce_fields, 'name' ) );
					$setup['fields'][ $key ] = array(
						'wordpress_field' => array(
							'label' => sanitize_text_field( $posted['wordpress_field'][ $key ] ),
							'methods' => $wordpress_fields[ $method_key ]['methods'],
						),
						'salesforce_field' => array(
							'label' => sanitize_text_field( $posted['salesforce_field'][ $key ] ),
							'updateable' => $salesforce_fields[ $updateable_key ]['updateable'],
						),
						'is_prematch' => sanitize_text_field( $posted['is_prematch'][ $key ] ),
						'is_key' => sanitize_text_field( $posted['is_key'][ $key ] ),
						'direction' => sanitize_text_field( $posted['direction'][ $key ] ),
						'is_delete' => sanitize_text_field( $posted['is_delete'][ $key ] ),
					);
				}
			}
			$data['fields'] = maybe_serialize( $setup['fields'] );
		} // End if().

		if ( isset( $posted['salesforce_record_types_allowed'] ) ) {
			$data['salesforce_record_types_allowed'] = maybe_serialize( $posted['salesforce_record_types_allowed'] );
		} else {
			$data['salesforce_record_types_allowed'] = maybe_serialize(
				array(
					$this->salesforce_default_record_type => $this->salesforce_default_record_type,
				)
			);
		}
		if ( isset( $posted['salesforce_record_type_default'] ) ) {
			$data['salesforce_record_type_default'] = $posted['salesforce_record_type_default'];
		} else {
			$data['salesforce_record_type_default'] = maybe_serialize( $this->salesforce_default_record_type );
		}
		if ( isset( $posted['pull_trigger_field'] ) ) {
			$data['pull_trigger_field'] = $posted['pull_trigger_field'];
		}
		if ( isset( $posted['sync_triggers'] ) && is_array( $posted['sync_triggers'] ) ) {
			$setup['sync_triggers'] = array();
			foreach ( $posted['sync_triggers'] as $key => $value ) {
				$setup['sync_triggers'][ $key ] = esc_html( $posted['sync_triggers'][ $key ] );
			}
		} else {
			$setup['sync_triggers'] = array();
		}
		$data['sync_triggers'] = maybe_serialize( $setup['sync_triggers'] );
		if ( isset( $posted['pull_trigger_field'] ) ) {
			$data['pull_trigger_field'] = $posted['pull_trigger_field'];
		}
		$data['push_async'] = isset( $posted['push_async'] ) ? $posted['push_async'] : '';
		$data['push_drafts'] = isset( $posted['push_drafts'] ) ? $posted['push_drafts'] : '';
		$data['weight'] = isset( $posted['weight'] ) ? $posted['weight'] : '';
		return $data;
	}

	/**
	* Delete a fieldmap row between a WordPress and Salesforce object
	*
	* @param array $id
	* @throws \Exception
	*/
	public function delete_fieldmap( $id = '' ) {
		$data = array(
			'id' => $id,
		);
		$delete = $this->wpdb->delete( $this->fieldmap_table, $data );
		if ( 1 === $delete ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	* Create an object map row between a WordPress and Salesforce object
	*
	* @param array $posted
	* @throws \Exception
	*/
	public function create_object_map( $posted = array() ) {
		$data = $this->setup_object_map_data( $posted );
		$data['created'] = current_time( 'mysql' );
		// check to see if we don't know the salesforce id, or if this is pending
		// if it is pending, the map will get updated after it finishes running
		if ( 0 !== $data['salesforce_id'] || 'pending' === $data['action'] ) {
			unset( $data['action'] );
			$insert = $this->wpdb->insert( $this->object_map_table, $data );
		} else {
			$status = 'error';
			if ( isset( $this->logging ) ) {
				$logging = $this->logging;
			} elseif ( class_exists( 'Salesforce_Logging' ) ) {
				$logging = new Salesforce_Logging( $this->wpdb, $this->version, $this->text_domain );
			}
			$logging->setup(
				__( ucfirst( $status ) . ': Mapping: error caused by trying to map the WordPress ' . $data['wordpress_object'] . ' with ID of ' . $data['wordpress_id'] . ' to Salesforce ID of 0, which is invalid.', $this->text_domain ),
				'',
				0,
				0,
				$status
			);
			return false;
		}
		if ( 1 === $insert ) {
			return $this->wpdb->insert_id;
		} elseif ( false !== strpos( $this->wpdb->last_error, 'Duplicate entry' ) ) {
			$mapping = $this->load_by_salesforce( $data['salesforce_id'] );
			$id = $mapping['id'];
			$status = 'error';
			if ( isset( $this->logging ) ) {
				$logging = $this->logging;
			} elseif ( class_exists( 'Salesforce_Logging' ) ) {
				$logging = new Salesforce_Logging( $this->wpdb, $this->version, $this->text_domain );
			}
			$logging->setup(
				__( ucfirst( $status ) . ': Mapping: there is already a WordPress object mapped to the Salesforce object ' . $data['salesforce_id'] . ' and the mapping object ID is ' . $id, $this->text_domain ),
				'',
				0,
				0,
				$status
			);
			return $id;
		} else {
			return false;
		}
	}

	/**
	* Get one or more object map rows between WordPress and Salesforce objects
	*
	* @param array $conditions
	* @param bool $reset
	* @return $map or $mappings
	* @throws \Exception
	*/
	public function get_object_maps( $conditions = array(), $reset = false ) {
		$table = $this->object_map_table;
		$order = ' ORDER BY object_updated, created';
		if ( ! empty( $conditions ) ) { // get multiple but with a limitation
			$mappings = array();

			if ( ! empty( $conditions ) ) {
				$where = ' WHERE ';
				$i = 0;
				foreach ( $conditions as $key => $value ) {
					$i++;
					if ( $i > 1 ) {
						$where .= ' AND ';
					}
					$where .= '`' . $key . '`' . ' = "' . $value . '"';
				}
			} else {
				$where = '';
			}

			$mappings = $this->wpdb->get_results( 'SELECT * FROM ' . $table . $where . $order, ARRAY_A );
			if ( ! empty( $mappings ) && 1 === $this->wpdb->num_rows ) {
				$mappings = $mappings[0];
			}
		} else { // get all of em
			$mappings = $this->wpdb->get_results( 'SELECT * FROM ' . $table . $order, ARRAY_A );
			if ( ! empty( $mappings ) && 1 === $this->wpdb->num_rows ) {
				$mappings = $mappings[0];
			}
		}

		return $mappings;

	}

	/**
	* Update an object map row between a WordPress and Salesforce object
	*
	* @param array $posted
	* @param array $id
	* @return $map
	* @throws \Exception
	*/
	public function update_object_map( $posted = array(), $id = '' ) {
		$data = $this->setup_object_map_data( $posted );
		if ( ! isset( $data['object_updated'] ) ) {
			$data['object_updated'] = current_time( 'mysql' );
		}
		$update = $this->wpdb->update(
			$this->object_map_table,
			$data,
			array(
				'id' => $id,
			)
		);
		if ( false === $update ) {
			return false;
		} else {
			return true;
		}
	}

	/**
	* Setup the data for the object map
	*
	* @param array $posted
	* @return $data
	*/
	private function setup_object_map_data( $posted = array() ) {
		$data = $posted;
		return $data;
	}

	/**
	* Delete an object map row between a WordPress and Salesforce object
	*
	* @param array $id
	* @throws \Exception
	*/
	public function delete_object_map( $id = '' ) {
		$data = array(
			'id' => $id,
		);
		$delete = $this->wpdb->delete( $this->object_map_table, $data );
		if ( 1 === $delete ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Returns Salesforce object mappings for a given WordPress object.
	 *
	 * @param string $object_type
	 *   Type of object to load.
	 * @param int $object_id
	 *   Unique identifier of the target object to load.
	 * @param bool $reset
	 *   Whether or not the cache should be cleared and fetch from current data.
	 *
	 * @return SalesforceMappingObject
	 *   The requested SalesforceMappingObject or FALSE if none was found.
	 */
	public function load_by_wordpress( $object_type, $object_id, $reset = false ) {
		$conditions = array(
			'wordpress_id' => $object_id,
			'wordpress_object' => $object_type,
		);
		return $this->get_object_maps( $conditions, $reset );
	}

	/**
	* Returns Salesforce object mappings for a given Salesforce object.
	*
	* @param string $salesforce_id
	*   Type of object to load.
	* @param bool $reset
	*   Whether or not the cache should be cleared and fetch from current data.
	*
	* @return array $map
	*   The most recent fieldmap
	*/
	public function load_by_salesforce( $salesforce_id, $reset = false ) {
		$conditions = array(
			'salesforce_id' => $salesforce_id,
		);

		$map = $this->get_object_maps( $conditions, $reset );

		if ( isset( $map[0] ) && is_array( $map[0] ) && count( $map ) > 1 ) {
			$status = 'notice';
			$log = '';
			$log .= 'Mapping: there is more than one mapped WordPress object for the Salesforce object ' . $salesforce_id . '. These WordPress IDs are: ';
			$i = 0;
			foreach ( $map as $mapping ) {
				$i++;
				if ( isset( $mapping['wordpress_id'] ) ) {
					$log .= 'object type: ' . $mapping['wordpress_object'] . ', id: ' . $mapping['wordpress_id'];
				}
				if ( count( $map ) !== $i ) {
					$log .= '; ';
				} else {
					$log .= '.';
				}
			}
			$map = $map[0];
			// create log entry for multiple maps
			if ( isset( $this->logging ) ) {
				$logging = $this->logging;
			} elseif ( class_exists( 'Salesforce_Logging' ) ) {
				$logging = new Salesforce_Logging( $this->wpdb, $this->version, $this->text_domain );
			}
			$logging->setup(
				__( ucfirst( $status ) . ': Mapping: there is more than one mapped WordPress object for the Salesforce object ' . $salesforce_id, $this->text_domain ),
				$log,
				0,
				0,
				$status
			);
		}

		return $map;
	}

	/**
	* Map values between WordPress and Salesforce objects.
	*
	* @param array $mapping
	*   Mapping object.
	* @param array $object
	*   WordPress or Salesforce object data.
	* @param array $trigger
	*   What triggered this mapping?
	* @param bool $use_soap
	*   Flag to enforce use of the SOAP API.
	* @param bool $is_new
	*   Indicates whether a mapping object for this entity already exists.
	*
	* @return array
	*   Associative array of key value pairs.
	*/
	public function map_params( $mapping, $object, $trigger, $use_soap = false, $is_new = true ) {

		$params = array();

		foreach ( $mapping['fields'] as $fieldmap ) {

			$wordpress_haystack = array_values( $this->wordpress_events );
			$salesforce_haystack = array_values( $this->salesforce_events );

			// skip fields that aren't being pushed to Salesforce.
			if ( in_array( $trigger, $wordpress_haystack ) && ! in_array( $fieldmap['direction'], array_values( $this->direction_wordpress ) ) ) {
				// the trigger is a wordpress trigger, but the fieldmap direction is not a wordpress direction
				continue;
			}

			// skip fields that aren't being pulled from Salesforce.
			if ( in_array( $trigger, $salesforce_haystack ) && ! in_array( $fieldmap['direction'], array_values( $this->direction_salesforce ) ) ) {
				// the trigger is a salesforce trigger, but the fieldmap direction is not a salesforce direction
				continue;
			}

			$salesforce_field = $fieldmap['salesforce_field']['label'];
			$wordpress_field = $fieldmap['wordpress_field']['label'];

			// a wordpress event caused this
			if ( in_array( $trigger, array_values( $wordpress_haystack ) ) ) {

				// Skip fields that aren't updateable when mapping params because salesforce will error otherwise
				if ( 1 !== (int) $fieldmap['salesforce_field']['updateable'] ) {
					continue;
				}

				$params[ $fieldmap['salesforce_field']['label'] ] = $object[ $fieldmap['wordpress_field']['label'] ];

				// if the field is a key in salesforce, remove it from $params to avoid upsert errors from salesforce
				// but still put its name in the params array so we can check for it later
				if ( '1' === $fieldmap['is_key'] ) {
					if ( ! $use_soap ) {
						unset( $params[ $salesforce_field ] );
					}
					$params['key'] = array(
						'salesforce_field' => $salesforce_field,
						'wordpress_field' => $wordpress_field,
						'value' => $object[ $fieldmap['wordpress_field']['label'] ],
					);
				}

				// if the field is a prematch in salesforce, put its name in the params array so we can check for it later
				if ( '1' === $fieldmap['is_prematch'] ) {
					$params['prematch'] = array(
						'salesforce_field' => $salesforce_field,
						'wordpress_field' => $wordpress_field,
						'value' => $object[ $fieldmap['wordpress_field']['label'] ],
					);
				}
			} elseif ( in_array( $trigger, $salesforce_haystack ) ) {

				// a salesforce event caused this
				// make an array because we need to store the methods for each field as well
				$params[ $fieldmap['wordpress_field']['label'] ] = array();
				$params[ $wordpress_field ]['value'] = $object[ $fieldmap['salesforce_field']['label'] ];

				// if the field is a key in salesforce, remove it from $params to avoid upsert errors from salesforce
				// but still put its name in the params array so we can check for it later
				if ( '1' === $fieldmap['is_key'] ) {
					if ( ! $use_soap ) {
						unset( $params[ $fieldmap['wordpress_field']['label'] ] );
					}
					$params['key'] = array(
						'salesforce_field' => $salesforce_field,
						'wordpress_field' => $wordpress_field,
						'value' => $object[ $fieldmap['salesforce_field']['label'] ],
						'method_read' => $fieldmap['wordpress_field']['methods']['read'],
						'method_create' => $fieldmap['wordpress_field']['methods']['create'],
						'method_update' => $fieldmap['wordpress_field']['methods']['update'],
					);
				}

				// if the field is a prematch in salesforce, put its name in the params array so we can check for it later
				if ( '1' === $fieldmap['is_prematch'] ) {
					$params['prematch'] = array(
						'salesforce_field' => $salesforce_field,
						'wordpress_field' => $wordpress_field,
						'value' => $object[ $fieldmap['salesforce_field']['label'] ],
						'method_read' => $fieldmap['wordpress_field']['methods']['read'],
						'method_create' => $fieldmap['wordpress_field']['methods']['create'],
						'method_update' => $fieldmap['wordpress_field']['methods']['update'],
					);
				}

				switch ( $trigger ) {
					case $this->sync_sf_create:
						$params[ $wordpress_field ]['method_modify'] = $fieldmap['wordpress_field']['methods']['create'];
						break;
					case $this->sync_sf_update:
						$params[ $wordpress_field ]['method_modify'] = $fieldmap['wordpress_field']['methods']['update'];
						break;
					case $this->sync_sf_delete:
						$params[ $wordpress_field ]['method_modify'] = $fieldmap['wordpress_field']['methods']['delete'];
						break;
				}

				$params[ $wordpress_field ]['method_read'] = $fieldmap['wordpress_field']['methods']['read'];

			} // End if().
		} // End foreach().

		return $params;

	}

	/**
	* Prepare field map data for use
	*
	* @param array $mappings
	*   Array of fieldmaps
	* @param string $record_type
	*   Optional Salesforce record type to see if it is allowed or not
	*
	* @return array $mappings
	*   Associative array of field maps ready to use
	*/
	private function prepare_fieldmap_data( $mappings, $record_type = '' ) {

		foreach ( $mappings as $id => $mapping ) {
			$mappings[ $id ]['salesforce_record_types_allowed'] = maybe_unserialize( $mapping['salesforce_record_types_allowed'] );
			$mappings[ $id ]['fields'] = maybe_unserialize( $mapping['fields'] );
			$mappings[ $id ]['sync_triggers'] = maybe_unserialize( $mapping['sync_triggers'] );
			if ( '' !== $record_type && ! in_array( $record_type, $mappings[ $id ]['salesforce_record_types_allowed'] ) ) {
				unset( $mappings[ $id ] );
			}
		}

		return $mappings;

	}

}
