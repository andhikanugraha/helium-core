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
	protected $_associate = ''; // the object that is associated to this one.
}

abstract class HeliumRecord extends HeliumRecordSupport {

	public $id = 0;

	// true if the record exists in the database.
	public $_exists = false;

	protected $_model = ''; // a lowercase, underscored version of the class name

	public $_columns = array();
	public $_column_types = array();

	public $_auto_serialize = array();

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
					case 'tinyint':
						if ($length == 1)
							$type = 'bool';
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

				$this->_column_types[$field] = $type;
			}

			$this->_columns = $columns;
			$this->defaults();
		}
	}

	/* blank methods
	   functions that can be redefined by child functions */

	// init function
	// association definitions (has_many, etc) go here
	// everything else that should be called during __construction should also be called here.
	public function init() {}

	public function defaults() {}

	// rebuild, called:
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

	// called before destroy().
	public function before_destroy() {}

	/* finding functions
	   functions for and related to fetching records from the DB */

	final public static function find($conditions = null) {
		if (is_numeric($conditions)) { // we're looking for a single record with an ID.
			$multiple = self::find(array('id' => $conditions));
			return $multiple->first();
		}

		$class_name = get_called_class();
		$set = new HeliumRecordCollection($class_name);

		if (is_array($conditions))
			$set->set_conditions_array($conditions);
		elseif (is_string($conditions) && $conditions != 'all')
			$set->set_conditions_string($conditions);

		return $set;
	}

	// associational functions

	final protected function has_one($association_id, $options = array()) {
		extract($options);
		if (!$foreign_key)
			$foreign_key = $this->_model . '_id';
		if (!$class_name)
			$class_name = Inflector::camelize($association_id);
		if (!$conditions)
			$conditions = array();

		$_type = 'has_one';
		$this->_associations['one-to-one'][$association_id] = compact('_type', 'foreign_key', 'class_name', 'conditions');
	}

	final protected function belongs_to($association_id, $options = array()) {
		extract($options);
		if (!$foreign_key)
			$foreign_key = $association_id . '_id';
		if (!$class_name)
			$class_name = Inflector::camelize($association_id);
		if (!$conditions)
			$conditions = array();
		
		$_type = 'belongs_to';
		$this->_associations['one-to-one'][$association_id] = compact('_type', 'foreign_key', 'class_name', 'conditions');
	}

	final protected function has_many($association_id, $options) {
		extract($options);
		if (!$foreign_key)
			$foreign_key = $this->_model . '_id';
		if (!$class_name)
			$class_name = Inflector::camelize(Inflector::singularize($association_id));
		if (!$conditions)
			$conditions = array();

		$this->_associations['one-to-many'][$association_id] = compact('foreign_key', 'class_name', 'conditions');
	}

	// the other class must also declare has_and_belongs_to_many
	final protected function has_and_belongs_to_many($association_id, $options) {
		extract($options);

		if (!$class_name)
			$class_name = Inflector::classify($association_id);
		if (!$join_table) {
			$sort = array(Inflector::tableize($class_name), $this->_table_name);
			sort($sort);
			$join_table = implode('_', $sort);
		}
		if (!$foreign_key)
			$foreign_key = $this->_model . '_id';
		if (!$association_foreign_key)
			$association_foreign_key = Inflector::underscore($class_name) . '_id';
		if (!$conditions)
			$conditions = array();

		$this->_associations['many-to-many'][$association_id] = compact('class_name', 'join_table', 'foreign_key', 'association_foreign_key', 'conditions');
	}

	// internal mapping functions for associations

	final private function _map_one_to_one_association($association_id, $options) {
		extract($options);
		// check the foreign key. if it's null, 0, or '', don't bother finding.
		if ($_type == 'has_one') {
			if (!$this->id) return;
			$conditions[$foreign_key] = $this->id;
		}
		else {
			if (!$this->$foreign_key) return;
			$conditions['id'] = $this->$foreign_key;
		}

		$return = $class_name::find($conditions);
		$return->set_order('DESC');
		$return = $return->first();
		$return->_associate = $this;

		$this->$association_id = $return;

		return $return;
	}

	final private function _map_one_to_many_association($association_id, $options) {
		extract($options);
		
		$return = array();

		$conditions[$foreign_key] = $this->id;
		$return = $class_name::find($conditions);
		$return->_associate = $this;

		$this->$association_id = $return;

		return $return;
	}

	final protected function _map_many_to_many_association($association_id, $options) {
		if (!$this->id === null)
			return;

		extract($options);

		$associates = $class_name::find("`$join_table`.`$foreign_key`='{$this->id}'");
		if ($conditions)
			$associates->narrow($conditions);

		$associates->_associate = $this;

		$this->$association_id = $associates;

		return $associates;
	}

	// overloading for association support

	final public function __get($association_id) {
		if ($options = $this->_associations['one-to-one'][$association_id]) {
			return $this->_map_one_to_one_association($association_id, $options);
		}
		else if ($options = $this->_associations['one-to-many'][$association_id]) {
			return $this->_map_one_to_many_association($association_id, $options);
		}
		else if ($options = $this->_associations['many-to-many'][$association_id]) {
			return $this->_map_many_to_many_association($association_id, $options);
		}
		else {
			return null;
		}
	}

	final public function __set($association_id, $value) {
		if ($this->_associations['one-to-one'][$association_id] ||
			$this->_associations['one-to-many'][$association_id] ||
			$this->_associations['many-to-many'][$association_id])
			{
			if (!$value->_associate) {
				$this->associate($value, $association_id);
				return $this->$association_id;
			}
			else
				$this->$association_id = $value;
		}
		else
			$this->$association_id = $value;
	}

	final public function __isset($name) {
		return ($this->_associations['one-to-one'][$name] || 
				$this->_associations['one-to-many'][$name] ||
				$this->_associations['many-to-many'][$name]);
	}

	// __wakeup() - Records are refetched upon unserialization.
	
	public function __wakeup() {
		$latest = self::find($this->id);
		$this->merge($latest);
	}

	// manipulation functions

	public function save() {
		$this->before_save();

		$db = Helium::db();

		$table = $this->_table_name;

		if ($this->exists()) {
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

	public function destroy() {
		$this->before_destroy();

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
		return array_keys($this->_column_types);
	}

	private function _db_values() {
		$db = Helium::db();
		$fields = array();

		foreach ($this->_column_types as $field => $type) {
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

	// auto serialize - call this and a property will be automatically serialized and unserialized on save() and find().
	
	protected function auto_serialize() {
		$properties = func_get_args();
		$this->_auto_serialize = array_merge($this->_auto_serialize, $properties);
	}
	
	public function _unserialize_auto() {
		$obj = $this;
		array_walk($this->_auto_serialize, function($property) use ($obj) {
			$obj->$property = unserialize($obj->$property);
		});
	}

	// other functions

	public function exists() {
		return $this->_exists;
	}

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

	public function associate($associate, $match_association_id = '') {
		if (!$associate)
			return;

		$associate_class = get_class($associate);

		foreach ($this->_associations as $type => $associations) {
			foreach ($associations as $association_id => $association) {
				$check_association_id = $match_association_id ? ($association_id == $match_association_id) : true;
				if ($association['class_name'] == $associate_class && $check_association_id) {
					$foreign_key = $association['foreign_key'];
					switch ($type) {
						case 'one-to-one':
							if ($association['_type'] == 'has_one') {
								$associate->$foreign_key = $this->id;
								$associate->save();
								$this->_associate = $associate;
							}
							else {
								$this->$foreign_key = $associate->id;
								$this->save();
								$associate->_associate = $this;
							}
							break;
						case 'one-to-many':
							$associate->$foreign_key = $this->id;
							$associate->save();
							$associate->_associate = $this;
							break;
						case 'many-to-many':
							$db = Helium::db();
							extract($association);
							$query = "INSERT INTO `$join_table` (`$foreign_key`, `$association_foreign_key`) VALUES ('{$associate->id}', '{$this->id}')";
							return $db->query($query);
							break;
					}
				}
			}
		}
	}

	public function disassociate() {
		if (!$this->_associate)
			return;
		
		$associate = $this->_associate;
		$this_class = get_class($this);

		foreach ($associate->_associations as $type => $associations) {
			foreach ($associations as $association) {
				if ($association['class_name'] == $this_class) {
					$foreign_key = $association['foreign_key'];
					switch ($type) {
						case 'one-to-one':
							if ($association['_type'] == 'has_one') {
								$this->$foreign_key = 0;
								$this->save();
							}
							else {
								$associate->$foreign_key = 0;
								$associate->save();
							}
							break;
						case 'one-to-many':
							$this->$foreign_key = 0;
							$this->save();
							break;
						case 'many-to-many':
							$db = Helium::db();
							extract($association);
							$query = "DELETE FROM `$join_table` WHERE `$foreign_key`='{$associate->id}' AND `$association_foreign_key`='{$this->id}";
							return $db->query($query);
							break;
					}
				}
			}
		}
	}
}


