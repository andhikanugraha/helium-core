<?php

// HeliumRecordCollection
// An Iterator for HeliumRecord objects.
// Basically, it's lazy loading of database requests.

// a 'set' refers to a 'request' corresponding to a SELECT query.
// iterating on a set returns a batch of rows.
// a batch consists of several rows, corresponding to a LIMIT statement.
// the length of each batch can be controlled in an object
// and switching batches can also be done.
// (think of it like pages in a blog, each containing posts)

class HeliumRecordCollection extends HeliumRecordSupport implements Iterator {

	const all_records = null;

	public $model_name;
	public $table_name;
	public $class_name;

	private $conditions_array = array();
	private $additional_conditions_array = array();
	private $additional_where_approach = 'OR';
	private $conditions_string = '1';

	private $order_by = 'id';
	private $order = 'ASC';

	private $batch_number = 1;
	private $batch_start = 0;
	private $batch_length = 200;

	private $additional_columns = array();

	// joining variables
	private $one_to_one_associations = array();
	private $many_to_many_associations = array();
	private $join_statements = array();
	private $included_associations = array();

	// loading variables
	private $fetched = false;
	public $query = ''; // the aggregate SQL query used in fetching
	public $rows = array(); // array of plain rows StdClass objects.
	private $count = 0;
	private $count_all = 0;
	public $col_info = array();
	public $col_types = array();

	// iteration variables
	private $records = array();
	private $index = 0;
	private $prepared_index = 0;

	public function __construct($class_name) {
		$this->class_name = $class_name;
		$this->model_name = Inflector::underscore($class_name);

		$this->table_name = $this->get_model_table($class_name);

		$prototype = new $class_name;
		$this->one_to_one_associations = $prototype->_associations['one-to-one'];
		$this->many_to_many_associations = $prototype->_associations['many-to-many'];
	}
	
	public function include_associations() {
		$this->set_order_by('id');

		$base_join_statement = ' LEFT JOIN `%s` ON %s';
		$local_table = $this->table_name;

		foreach ($this->one_to_one_associations as $association_id => $options) {
			$this->include_association($association_id);
		}
		
		foreach ($this->many_to_many_associations as $association_id => $options) {
			extract($options);
			$join_condition = "`$join_table`.`$local_key`=`$local_table`.id";
			$this->join_statements[] = sprintf($base_join_statement, $join_table, $join_condition);
		}
	}
	
	public function include_association($association_id) {
		if ($this->included_associations[$association_id])
			return;

		$base_join_statement = ' LEFT JOIN `%s` ON %s';
		$local_table = $this->table_name;

		if ($options = $this->one_to_one_associations[$association_id]) {
			// prepare join statement
			extract($options);
			$foreign_table = $this->get_model_table($class_name);
			if ($foreign_table) {
				if ($_type == 'belongs_to')
					$join_condition = "`$foreign_table`.`id`=`$local_table`.`$foreign_key`";
				else
					$join_condition = "`$foreign_table`.`$foreign_key`=`$local_table`.`id`";
				$this->join_statements[] = sprintf($base_join_statement, $foreign_table, $join_condition);
			}

			// prepare sibling column retrieval
			$db = Helium::db();
			$foreign_columns = $db->get_col("SHOW COLUMNS FROM `$foreign_table`");
			foreach ($foreign_columns as $col) {
				$this->add_additional_column(".{$association_id}.{$col}", "`$foreign_table`.`$col`");
			}

			$options['_foreign_columns'] = $foreign_columns;
			$this->included_associations[$association_id] = $options;
		}
	}

	private function get_model_table($class_name = '') {
		if (class_exists($class_name)) {
			$prototype = new $class_name(false);

			return $prototype->_table_name;
		}
	}

	public function generate_query() {
		// make the query
		$base_query = 'SELECT `%s`.*%s FROM `%1$s`%s WHERE %s ORDER BY %s %s';

		$join_clause = implode('', $this->join_statements);
		
		$additionals = '';
		foreach ($this->additional_columns as $col => $dec) {
			$additionals .= ", ($dec) AS `$col`";
		}

		$query = sprintf($base_query, $this->table_name, $additionals, $join_clause, $this->conditions_string, $this->order_by, $this->order);

		if ($this->batch_length > 0)
			$query .= sprintf(' LIMIT %d,%d', $this->batch_start, $this->batch_length);

		return $query;
	}

	public function fetch() {
		if ($this->fetched)
			return;

		// initialize
		$this->rows = array();
		$this->count = 0;
		$this->count_all = 0;
		$this->query = '';
		$this->records = array();
		$this->index = 0;
		$this->prepared_index = 0;

		$db = Helium::db();

		// make the query
		$base_query = 'SELECT `%s`.*%s FROM `%1$s`%s WHERE %s ORDER BY %s %s';

		$join_clause = implode('', $this->join_statements);
		
		$additionals = '';
		foreach ($this->additional_columns as $col => $dec) {
			$additionals .= ", ($dec) AS `$col`";
		}

		$query = sprintf($base_query, $this->table_name, $additionals, $join_clause, $this->conditions_string, $this->order_by, $this->order);

		if ($this->batch_length > 0)
			$query .= sprintf(' LIMIT %d,%d', $this->batch_start, $this->batch_length);

		// store the query
		$this->query = $query;
		// execute the query
		$results = $db->get_results($query, ARRAY_A);

		$this->col_info = $db->col_info;
		foreach ($this->col_info as $col) {
			$name = $col->name;
			$type = $col->type;
			switch ($type) {
				case MYSQLI_TYPE_TINY:
					if ($length == 1)
						$type = 'bool';
				case MYSQLI_TYPE_SHORT:
				case MYSQLI_TYPE_LONG:
				case MYSQLI_TYPE_LONGLONG:
				case MYSQLI_TYPE_INT24:
					$type = 'int';
					break;
				case MYSQLI_TYPE_FLOAT:
				case MYSQLI_TYPE_DOUBLE:
				case MYSQLI_TYPE_DECIMAL:
				case MYSQLI_TYPE_NEWDECIMAL:
					$type = 'float';
					break;
				case MYSQLI_TYPE_DATE:
				case MYSQLI_TYPE_TIME:
				case MYSQLI_TYPE_DATETIME:
				case MYSQLI_TYPE_NEWDATE:
				case MYSQLI_TYPE_TIMESTAMP:
				case MYSQLI_TYPE_YEAR:
					$type = 'datetime';
					break;
				// to do: support the other column types (BLOB, etc)
				default:
					$type = 'string';
			}
			$this->col_types[$name] = $type;
		}

		if (is_array($results)) {
			$this->fetched = true;
			$this->rows = $results;
			$this->count = count($results);

			return $results;
		}
		else {
			$this->records = array();

			return true;
		}
	}

	private function generate_conditions_string(Array $array) {
		if (!$array) // empty array
			return '';

		$db = Helium::db();

		$query = array();
        foreach ($array as $field => $value) {
			if (strpos($field, '(') > 0) { // functions as fields
				// TODO: add escaping code for the field
			}
			elseif ($dec = $this->additional_columns[$field])
				$field = "({$dec})";
			else
				$field = $this->escape_field($field);
			$value = $db->escape($value);
            $query[] = "{$field}='{$value}'";
		}
		$conditions_string = '(' . implode(" AND ", $query) . ')';

		return $conditions_string;
	}

	private function escape_field($field) {
		$field_particles = explode('.', $field);
		if (count($field_partices) < 2)
			array_unshift($field_particles, $this->table_name);
		array_walk($field_particles, function(&$particle) {
			$particle = "`$particle`";
		});
		$field = implode('.', $field_particles);

		return $field;
	}

	public function first() {
		if (!$this->fetched) {
			$bl = $this->batch_length;
			$this->batch_length = 1;
			$this->fetch();
			$this->batch_length = $bl;
		}

		if (!$this->count)
			return false;

		$this->rewind();

		return $this->current();
	}

	public function set_conditions_array(Array $conditions_array) {
		$this->fetched = false;
		$this->conditions_string = $this->generate_conditions_string($conditions_array);
	}

	public function set_conditions_string($conditions_string) {
		$this->fetched = false;
		$this->conditions_string = trim($conditions_string);
	}

	public function set_batch_length($batch_length) {
		$this->fetched = false;
		$this->batch_length = $batch_length;
	}

	public function set_batch_number($batch_number) {
		$this->fetched = false;
		$this->batch_number = $batch_number;
		$this->batch_start = ($batch_number - 1) * $this->batch_length;
	}

	public function set_order($order) {
		$this->fetched = false;
		$this->order = strtoupper($order);
	}

	public function ascending() {
		$this->set_order('ASC');
	}

	public function descending() {
		$this->set_order('DESC');
	}

	public function set_order_by($field, $order = '') {
		$this->fetched = false;
		$this->order_by = $this->escape_field($field);
		if ($order)
			$this->set_order($order);
	}

	public function count() {
		$this->fetch();

		return $this->count;
	}

	public function count_all() {
		if ($this->count_all && $this->fetched)
			return $this->count_all;

		$db = Helium::db();

		// make the query
		$base_query = 'SELECT COUNT(*) FROM `%s`%s WHERE %s';

		$join_clause = implode('', $this->join_statements);

		$query = sprintf($base_query, $this->table_name, $join_clause, $this->conditions_string);

		$count = $db->get_var($query);

		return $count;
	}
	
	public function get_number_of_batches() {
		if (!$this->batch_length)
			return 1;
		else
			return ceil($this->count_all() / $this->batch_length);
	}

	public function add_additional_column($name, $declaration) {
		$this->additional_columns[$name] = $declaration;
	}

	public function widen($conditions) {
		$this->fetched = false;
		if (is_array($conditions))
			$conditions = $this->generate_conditions_string($conditions);
		$this->conditions_string .= ' OR ' . $conditions;
	}

	public function narrow($conditions) {
		$this->fetched = false;
		if (is_array($conditions))
			$conditions = $this->generate_conditions_string($conditions);
		if ($this->conditions_string == '1')
			$this->conditions_string = $conditions;
		else
			$this->conditions_string .= ' AND ' . $conditions;
	}

	public function add_ID($id) {
		$id = (string) Helium::numval($id);
		$this->widen(array('id' => $id));
	}

	private function prepare_record($row) {
		$class_name = $this->class_name;
		$record = new $class_name(false);

		foreach ($this->col_types as $name => $type) {
			$raw_value = $row[$name];
			$prepared_value = $this->prepare_value($raw_value, $type);
			$record->$name = $prepared_value;
		}

		foreach ($this->additional_columns as $col => $dec) {
			$record->$col = $row[$col];
		}
		
		foreach ($this->included_associations as $association_id => $options) {
			$class_name = $options['class_name'];
			$associate = new $class_name;
			foreach ($options['_foreign_columns'] as $col) {
				$raw_name = '.' . $association_id . '.' . $col;
				$associate->$col = $row[$raw_name];
			}
			$associate->_exists = true;
			$associate->_associate = $record;
			$associate->rebuild();
			$associate->_unserialize_auto();

			$record->$association_id = $associate;
		}

		$record->_column_types = $this->col_types;
		$record->_associate = $this->_associate;
		$record->_exists = true;
		$record->rebuild();
		$record->_unserialize_auto();

		return $record;
	}

	private function prepare_value($value, $type) {
		switch ($type) {
			case 'bool':
				$value = (bool) $value;
				break;
			case 'int':
				$value = Helium::numval($value);
				break;
			case 'float':
				$value = floatval($value);
				break;
			case 'datetime':
				$value = new HeliumDateTime($value);
				break;
			// default: $value is a string, let it be
		}

		return $value;
	}


	// iterator methods
	// we're only using numerical indices
	// so there's no need to use array_keys() like on php.net.

	public function rewind() {
		$this->fetch();

		$this->index = 0;
	}

	public function current() {
		$this->fetch();

		if ($this->prepared_index <= $this->index) {
			$row = $this->rows[$this->index];
			$record = $this->prepare_record($row);
			$this->records[$this->index] = $record;
			$this->prepared_index++;
		}

		$current_record = $this->records[$this->index];

		return $current_record;
	}

	public function key() {
		return $this->index;
	}

	public function next() {
		$this->index++;

		if ($this->index < $this->count)
			return $this->current();
	}

	public function valid() {
		return $this->index < $this->count;
	}
}