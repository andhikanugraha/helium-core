<?php

// HeliumPartitionedRecord
// HeliumRecord with vertical partitions

// Work in progress: vertical partitioning support
// Goal: performance
// Specification:
//		Data located in vertical partitions should be accessible
//		just like columns located in the main table
// Strategy:
//		->add_vertical_partition()
//		->map_vertical_partitions()
//		modify ->_columns()
//		->extended_find()
//		modify ->find() to do ->extended_find if other columns are found
// Vertical partitions are to be treated low-level.
// Do NOT create any separate object for partitions.

abstract class HeliumPartitionedRecord extends HeliumRecord {

	public $id = 0;

	// Associations
	public $_associations = array(	'one-to-one' => array(),
									'one-to-many' => array(),
									'many-to-many' => array());

	// The table name and associate
	public $_table_name = '';
	public $_associate = ''; // the object that is associated to this one.

	// true if the record exists in the database.
	public $_exists = false;

	protected $_model = ''; // a lowercase, underscored version of the class name

	public $_columns = array();
	public $_column_types = array();

	public $_auto_serialize = array();
	
	/* Vertical partitioning properties */

	public $_is_vertically_partitioned = false;
	// True if vertically partitioned
	
	public $_vertical_partition_foreign_key = '';
	// The foreign key in partitions pointing to the main table

	public $_vertical_partitions = array();
	// An array of names of tables which are partitions of this table

	public $_vertical_partition_column_map = array();
	// Key: name of columns
	// Value: name of table which holds the physical column
	
	public $_vertical_partition_column_types = array();
	// Key: name of columns
	// Value: type of the column according to the vertical partition
	
	public $_mapped_vertical_partitions = array();
	// An array of vertical partitions which columns have been mapped
	
	public $_vertical_partition_original_values = array();
	// Key: name of column
	// Value: value of the field, wherever the original table was
	
	public $_vertical_partition_table_map = array();

	public function __construct($default = true) {
		$class_name = get_class($this);

		if (!$this->_table_name) {
			$table = Inflector::tableize($class_name);
			$this->_table_name = $table;
		}

		$model = Inflector::underscore($class_name);
		$this->_model = $model;

		$this->init();

		if ($default) {
			// fetch column types
			$db = Helium::db();
			$table = $this->_table_name;

			$this->_column_types = $this->_fetch_column_types($table);
			$this->_columns = array_keys($this->_column_types);
			$this->defaults();
		}
	}
	
	protected function _fetch_column_types($table) {
		$db = Helium::db();

		$query = $db->get_results("SHOW COLUMNS FROM `$table`");

		$columns = array();

		foreach ($query as $row) {
			$field = $row->Field;
			$type = $row->Type;

			$pos = strpos($type, '(');
			if ($pos > 0)
				$type = substr($type, 0, $pos);
			
			$type = strtolower($type);
			switch ($type) {
				case 'bit':
					$type = 'bool';
					break;
				case 'tinyint':
					if ($length == 1) {
						$type = 'bool';
						break;
					}
				case 'smallint':
				case 'int':
				case 'mediumint':
				case 'bigint':
					$type = 'int';
					break;
				case 'float':
				case 'double':
				case 'decimal':
					$type = 'float';
					break;
				case 'date':
				case 'time':
				case 'datetime':
				case 'timestamp':
				case 'year':
					$type = 'datetime';
					break;
				// to do: support the other column types (BLOB, etc)
				default:
					$type = 'string';
			}

			$columns[$field] = $type;
		}

		return $columns;
	}

	// Overloading for association AND vertical partition support

	public function __get($association_id) {
		if ($this->$association_id)
			return $this->$association_id;

		if ($this->_is_vertically_partitioned) {
			// Overloading for vertical partition support
			// Process: If an undefined property belongs to a vertical partition,
			//			map all the properties from that vertical partition.
			$this->map_vertical_partitions();

			if ($table_name = $this->_vertical_partition_column_map[$association_id]) {
				// The property belongs to a partition
				$this->fetch_vertical_partition_values($table_name);
				
				return $this->$association_id;
			}
		}
		
		return parent::__get($association_id);
	}

	public function __set($association_id, $value) {
		/* Temporary: For partitioned records, we'll ignore __set overloading */
		$this->$association_id = $value;
	}

	public function __isset($name) {
		return ($this->_associations['one-to-one'][$name] || 
				$this->_associations['one-to-many'][$name] ||
				$this->_associations['many-to-many'][$name]);
	}

	// manipulation functions

	public function save() {
		// Use MySQL transactions
		$db = Helium::db();
		
		$prev_autocommit = $db->autocommit();

		try {
			$table = $this->_table_name;

			$db->autocommit(false);

			$this->before_save();

			if ($this->exists()) {
				$this->_update();

				$vertical_delta = array();
				if ($this->_is_vertically_partitioned) {
					// Find the delta of tables (list of tables that need to be modified)
					foreach ($this->_vertical_partition_column_map as $col => $tab) {
						if (!in_array($tab, $vertical_delta)) {
							$current = $this->$col;
							$original = $this->_vertical_partition_original_values[$col];
							if ($current != $original) {
								$vertical_delta[] = $tab;
							}
						}
					}
				}
				array_walk($vertical_delta, array($this, '_update'));
			}
			else {
				$this->_insert();
				
				if ($this->_is_vertically_partitioned) {
					foreach ($this->_vertical_partitions as $tab) {
						$this->_insert($tab);
					}
				}
			}

			// Save associated partition records

			$db->commit();

			$this->after_save();

			$db->autocommit($prev_autocommit);
		}
		catch (HeliumException $e) {
			$db->rollback();
			$db->autocommit($prev_autocommit);

			$e->output(); exit;
			throw new HeliumException('Saving partitioned record failed.');
		}

		return true;
	}

	protected function _db_values($table = '') {
		if (!$table)
			$table = $this->_table_name;

		$db = Helium::db();
		$fields = array();

		$source = array();
		if ($table == $this->_table_name)
			$source = $this->_column_types;
		elseif ($this->_is_vertically_partitioned) {
			$source = array();
			foreach ($this->_vertical_partition_table_map[$table] as $col) {
				$col_type = $this->_vertical_partition_column_types[$col];
				$source[$col] = $col_type;
			}
		}

		foreach ($source as $field => $type) {
			$value = $this->$field;

			if (in_array($field, $this->_auto_serialize))
				$value = serialize($value);

			switch ($type) {
				case 'bool':
					$value = $value ? 1 : 0;
					break;
				case 'datetime':
					if (is_object($value))
						$value = $value->mysql_datetime();
			}

			$value = (string) $value;
			$value = $db->escape($value);
			$fields[$field] = $value;
		}

		return $fields;
	}

	protected function _update($table = '') {
		echo $table;
		if (!$table)
			$table = $this->_table_name;

		$db = Helium::db();

		$query = array();
		foreach ($this->_db_values($table) as $field => $value) {
			$query[] = "`$field`='$value'";
		}
		if (in_array('updated_at', $this->_columns($table)))
			$query[] = "`updated_at`=NOW()";

		$query = implode(', ', $query);

		$id = $this->id;

		if ($table == $this->_table_name)
			$query = "UPDATE $table SET $query WHERE `id`='$id'";
		else
			$query = "UPDATE $table SET $query WHERE `{$this->_vertical_partition_foreign_key}`='$id'";

		$query = $db->query($query);
	}

	protected function _insert($table = '') {
		if (!$table)
			$table = $this->_table_name;

		$db = Helium::db();

		$fields = $values = array();
		foreach ($this->_db_values($table) as $field => $value) {	
			if ($field == $this->_vertical_partition_foreign_key)
				$value = $this->id;
			elseif (!$this->$field || $field == 'created_at' || $field == 'updated_at')
				continue;

			$fields[] = "`$field`";
			$values[] = "'$value'";
		}

		if (in_array('created_at', $this->_columns($table))) {
			$fields[] = "`created_at`";
			$values[] = "NOW()";
		}

		$fields = implode(', ', $fields);
		$values = implode(', ', $values);

		$query = "INSERT INTO $table ($fields) VALUES ($values)";

		$query = $db->query($query);

		if ($table == $this->_table_name)
			$this->id = $db->insert_id;

		$this->_exists = true;
	}

	public function destroy() {
		if (!$this->exists())
			return false;

		// Use MySQL transactions
		$db = Helium::db();
		
		$prev_autocommit = $db->autocommit();
		
		try {
			$db->autocommit(false);

			$table = $this->_table_name;
			$id = $this->id;

			$this->before_destroy();

			$query = $db->query("DELETE FROM `$table` WHERE `id`='$id'");

			if ($this->_is_vertically_partitioned) {
				// Destroy associated partition records
				$base_query = "DELETE FROM `%s` WHERE `%s`='%s'";
				foreach ($this->_vertical_partitions as $part) {
					$q = $db->prepare($base_query, $part, $this->_vertical_partition_foreign_key, $id);
					$db->query($q);
				}
			}

			if ($query) {
				$unset = $this->_columns();
				foreach ($unset as $field)
					unset($this->$field);
			}

			$db->commit();

			$db->autocommit(true);
		}
		catch (HeliumException $e) {
			$db->rollback();
			$db->autocommit($prev_autocommit);

			throw new HeliumException('Deleting partitioned record failed.');
		}
	}

	// under-the-hood database functions

	public function _columns($table = '') {
		$cols = array_keys($this->_column_types);

		if ($table && $table != $this->_table_name)
			$cols = $this->_vertical_partition_table_map[$table];
		elseif ($this->_is_vertically_partitioned) {
			$this->map_vertical_partitions();
			$cols = array_merge($cols, array_keys($this->_vertical_partition_column_map));
		}
		return $cols;
	}

	// Vertical partitioning support
	
	protected function add_vertical_partition($table_name) {
		if (!$this->_is_vertically_partitioned) {
			$this->_is_vertically_partitioned = true;
			$this->_vertical_partition_foreign_key = Inflector::singularize($this->_table_name) . '_id';
		}

		$this->_vertical_partitions[] = $table_name;
	}

	// Overwrite the following function with a statically defined column map for greater performance
	public function map_vertical_partitions() {
		// Fill ->_vertical_partition_column_map
		if (!$this->_is_vertically_partitioned)
			return;

		foreach ($this->_vertical_partitions as $table_name) {
			if (!in_array($table_name, $this->_mapped_vertical_partitions)) {
				$column_types = $this->_fetch_column_types($table_name);
				$this->_vertical_partition_table_map[$table_name] = array();

				foreach ($column_types as $col => $type) {
					$this->_vertical_partition_table_map[$table_name][] = $col;
					if ($col != $this->_vertical_partition_foreign_key)
						$this->_vertical_partition_column_map[$col] = $table_name;
					$this->_vertical_partition_column_types[$col] = $type;
				}

				$this->_mapped_vertical_partitions[] = $table_name;
			}
		}
	}
	
	protected function fetch_vertical_partition_values($table_name) {
		// Fill $this with values from the vertical partition contained in table $table_name
		// We use the value parsing algorithm from RecordCollection: HeliumRecordCollection::prepare_value
		if ($this->exists()) {
			// This record exists
			// Fill in with data fetched from a SELECT query
			$db = Helium::db();

			$base_query = "SELECT * FROM `%s` WHERE `%s`='%s' LIMIT 0,1";
			$query = $db->prepare($base_query, $table_name, $this->_vertical_partition_foreign_key, $this->id);

			$select = $db->get_row($query, ARRAY_A);

			if ($select) {
				foreach ($select as $col => $value) {
					$col_type = $this->_vertical_partition_column_types[$col];
					$this->_vertical_partition_original_values[$col] = 	 
						HeliumRecordCollection::prepare_value($value, $col_type);
					if (!$this->$col)
						$this->$col = $this->_vertical_partition_original_values[$col];
				}
			}
			else {
				/* query failed */
				// throw something here?
			}
		}
		else {
			// This record does not exist
			// Fill in with blank values
			$cols = $this->_vertical_partition_table_map[$table_name];
			foreach ($cols as $col) {
				$col_type = $this->_vertical_partition_column_types[$col];
				$this->$col = HeliumRecordCollection::prepare_value('', $col_type);
			}
		}
	}
}


