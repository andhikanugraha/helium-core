<?php

// HeliumRecord
// Helium's implementation of the Active Record model.

// goals: to not pollute the class namespace with unnecessary methods and properties.
// internal methods and properties are to be prefixed with an underscore,
// and must be defined as either private or protected.

// share the variable scope of association variables
abstract class HeliumRecordSupport {

	protected $_associations = array('one-to-one' => array(),
									'one-to-many' => array(),
									'many-to-many' => array());

	protected $_table_name = '';
}

abstract class HeliumRecord extends HeliumRecordSupport {
	private $_exists = false;

	protected $_model = ''; // a lowercase, underscored version of the class name

	protected $_columns = array();
	protected $_column_types = array();

	public function __construct() {
		$class_name = get_class($this);

		if (!$this->_table_name) {
			$table = Inflector::tableize($class_name);
			$this->_table_name = $table;
		}

		$model = Inflector::underscore($class_name);
		$this->_model = $model;

		$this->init();
	}

	/* blank methods
	   functions that can be redefined by child functions */

	// init function
	// association definitions (has_many, etc) go here
	// everything else that should be called during __construction should also be called here.
	public function init() {}

	// rebuild: called:
	// after a record is fetched from the database
	// after a record is saved (if after_save is not redefined)
	public function rebuild() {}

	// called at the beginning of save()
	public function before_save() {}
	
	// called at the end of save()
	// defaults to calling rebuild()
	public function after_save() {
		$this->rebuild();
	}

	/* finding functions
	   functions for and related to fetching records from the DB */

	// find() now uses HeliumRecordSet.
	final public static function find($conditions = null) {
		if (is_numeric($conditions)) { // we're looking for a single record with an ID.
			$multiple = self::find(array('id' => $conditions));
			return $multiple->single();
		}

		$class_name = get_called_class();
		$set = new HeliumRecordSet($class_name);

		if (is_array($conditions))
			$set->set_conditions_array($conditions);
		elseif (is_string($conditions))
			$set->set_conditions_string($conditions);

		return $set;
	}

	// invoking the object as a function fills it with data
	final public function __invoke(StdClass $result) {
		foreach ($result as $column => $value) {
			$this->$column = $value;
		}

		$this->_exists = true;
		$this->_convert_columns();

		$this->rebuild();

		return $this;
	}

	// associational functions

	final protected function has_one($model_name, $local_key = null) {
		if (!$local_key)
			$local_key = $model_name . '_id';

		$this->_associations['one-to-one'][$model_name] = $local_key;
	}

	final protected function belongs_to($model_name, $local_key = null) {
		$this->has_one($model_name, $local_key);
	}

	final protected function has_many($model_name, $foreign_key = null) {
		if (!$foreign_key)
			$foreign_key = Inflector::singularize($this->_table_name) . '_id';

		$this->_associations['one-to-many'][$model_name] = $foreign_key;
	}

	final protected function belongs_to_many($class_name, $foreign_key = null) {
		$this->has_many($class_name, $foreign_key);
	}

	final protected function has_and_belongs_to_many($plural_foreign_model) {
		$sort = array($plural_foreign_model, $this->_table_name);
		sort($sort);
		$join_table = implode('_', $sort);

		$foreign_single = Inflector::singularize($plural_foreign_model);
		$foreign_key = $foreign_single . '_id';
	
		$local_single = $this->_model;
		$local_key = $local_single . '_id';

		$this->_associations['many-to-many'][$plural_foreign_model] = array(
																	'join_table' => $join_table,
																	'foreign_key' => $foreign_key,
																	'local_key' => $local_key
																	);
	}

	// internal mapping functions for associations

	final private function _map_one_to_one_association($model_name, $local_key) {
		if ($this->$local_key !== null) {
			$class_name = Inflector::camelize($model_name);
			$return = $class_name::find($this->$local_key);
		}
		else
			$return = null;

		$this->$model_name = $return;

		return $return;
	}

	final private function _map_one_to_many_association($plural_foreign_model, $foreign_key) {
		$id = $this->id;
		if ($id !== null) {
			$foreign_class_name = Inflector::classify($plural_foreign_model);
			$return = $foreign_class_name::find(array($foreign_key => $id));
		}

		$this->$plural_foreign_model = $return;

		return $return;
	}

	final protected function _map_many_to_many_association($plural_foreign_model, $association) {
		if (!$this->id === null)
			return;

		$db = Helium::db();

		extract($association);
		// $association contains $join_table, $foreign_key, $local_key

		$foreign_class_name = Inflector::classify($plural_foreign_model);
		$associates = $foreign_class_name::find("`$join_table`.`$local_key`='{$this->id}'");

		$this->$plural_foreign_model = $associates;

		return $associates;
	}

	// overloading for association support

	final public function __get($name) {
		if ($local_key = $this->_associations['one-to-one'][$name]) {
			$this->_map_one_to_one_association($name, $local_key);
			return $this->$name;
		}
		else if ($foreign_key = $this->_associations['one-to-many'][$name]) {
			$this->_map_one_to_many_association($name, $foreign_key);
			return $this->$name;
		}
		else if ($join_table = $this->_associations['many-to-many'][$name]) {
			$this->_map_many_to_many_association($name, $join_table);
			return $this->$name;
		}
		else
			return null;
	}

	final public function __isset($name) {
		return ($this->_associations['one-to-one'][$name] || 
				$this->_associations['one-to-many'][$name] ||
				$this->_associations['many-to-many'][$name]);
	}

	// manipulation functions

	public function save() {
		$db = Helium::db();

		$table = $this->_table_name;

		$this->before_save();

		if ($this->_exists) {
			$query = array();
			foreach ($this->_db_values() as $field => $value) {
				$query[] = "`$field`='$value'";
			}
			if (in_array('updated_at', $this->_columns()))
				$query[] = "`updated_at`=NOW()";

			$query = implode(', ', $query);

			$id = $this->id;
			$query = "UPDATE $table SET $query WHERE `id`='$id'";

			$query = $db->query($query);
		}
		else {
			$fields = $values = array();
			foreach ($this->_db_values() as $field => $value) {
				if (!$this->$field || $field == 'created_at' || $field == 'updated_at')
					continue;

				$fields[] = "`$field`";
				$values[] = "'$value'";
			}

			if (in_array('created_at', $this->_columns())) {
				$fields[] = "`created_at`";
				$values[] = "NOW()";
			}

			$fields = implode(', ', $fields);
			$values = implode(', ', $values);

			$query = "INSERT INTO $table ($fields) VALUES ($values)";

			$query = $db->query($query);

			$this->id = $db->insert_id;
		}

		if (!$query)
			return false;

		$this->_exists = true;

		$this->after_save();

		return true;
	}

	// to extend in child classes, call parent::destroy();
	public function destroy() {
		$db = Helium::db();

		$table = $this->_table_name;
		$id = $this->id;

		$query = $db->query("DELETE FROM `$table` WHERE `id`='$id'");

		if ($query) {
			$unset = $this->_columns();
			foreach ($unset as $field)
				$this->$field = null;
		}
	}

	// under-the-hood database functions

	final public function _columns() {
		if (!$this->_columns) {
			$db = Helium::db();
			$table = $this->_table_name;
			$query = $db->get_results("SHOW COLUMNS FROM `$table`");

			$columns = array();
			foreach ($query as $row) {
				$field = $row->Field;
				$type = $row->Type;

				$columns[] = $field;
				if ($type == 'tinyint(1)') // boolean
					$type = 'bool';
				elseif (($pos = strpos($type, '(')) > 0)
					$type = substr($type, 0, $pos);

				$this->_column_types[$field] = $type;
			}

			$this->_columns = $columns;
		}

		return $this->_columns;
	}

	private function _convert_columns() {
		$this->_columns(); // to fetch the column types if not yet fetched

		foreach ($this->_column_types as $field => $type) {
			$value = $this->$field;

			switch ($type) {
			case 'bool':
				$value = $value ? true : false;
				break;
			case 'int':
			case 'tinyint':
			case 'bigint':
				$value = Helium::numval($value);
				break;
			case 'datetime':
			case 'date':
			case 'timestamp':
				$value = strtotime($value);
				break;
			case 'varchar':
			case 'char':
			case 'text':
			default:
				$value = strval($value); // actually, this isn't necessary.
			}

			$this->$field = $value;
		}
	}

	private function _db_values() {
		$db = Helium::db();
		$fields = array();

		$this->_columns();

		foreach ($this->_column_types as $field => $type) {
			$value = $this->$field;

			switch ($type) {
			case 'bool':
				$value = $value ? 1 : 0;
				break;
			case 'int':
			case 'tinyint':
			case 'bigint':
				$value = Helium::numval($value);
				break;
			case 'datetime':
			case 'date':
			case 'timestamp':
				$value = $db->timetostr($value, $type);
				break;
			case 'varchar':
			case 'char':
			case 'text':
			default:
				if (is_array($value) || is_object($value))
					$value = serialize($value);

				$value = $db->escape($value);
			}

			$fields[$field] = (string) $value;
		}

		return $fields;
	}

	// other functions

	public function merge($source) {
		if (is_object($source))
			$source = get_object_vars($source);
		if (!is_array($source))
			return false;

		foreach ($this->_columns() as $column) {
			if ($source[$column] != $this->$column)
				$this->$column = $source[$column];
		}

		return true;
	}

	public function link($class) {
		$class_name = get_class($class);
		$single_name = Inflector::underscore($class_name);
		$field = $this->_associations['one-to-one'][$single_name];

		if (!$field)
			return false;

		if ($this->$field != $class->id)
			$this->$field = $class->id;

		if (get_class($this->$single_name) == $class_name && $this->$single_name->id == $class->id)
			$this->$single_name->merge($class);
		else
			$this->$single_name = $class;

		return true;
	}
}


