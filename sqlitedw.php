<?php

# A very simple OO and procedural SQLite database wrapper via PDO with fallback to SQLite3 class if newer

//------------------------------
// object-oriented interface
//------------------------------

interface SQLiteDW {
	public function begin();
	public function commit();
	public function rollback();
	//public function prepare($query); // TODO: finish
	//public function execute($stmt, $params); // TODO: finish
	public function transact($queries);
	public function exec($query);
	public function rows($query);
	public function row($query);
	public function column($query);
	public function cell($query, $default);
	public function quote($string);
	public function insert_id();
	public function attach($file, $name);
	public function detach($name);
	public function changes();
	public function show_tables($schema);
	public function info_view();
	public function prune_seq();
}

// common methods

trait SQLiteDW_common {

	//public function prepare($query) {
	//	return $this->prepare($query);
	//}

	public function transact($queries) {
		try {
			$this->begin();
			foreach ($queries as $query)
				$this->exec($query);
			$this->commit();
		} catch (Exception $e) {
			$this->rollback();
			throw $e;
		}
	}

	public function attach($file, $name) {
		$file = $this->quote($file);
		$this->exec("ATTACH DATABASE $file AS $name");
	}

	public function detach($name) {
		@$this->exec("DETACH DATABASE $name");
	}

	public function changes() {
		return +$this->cell('SELECT changes()', 0);
	}

	public function show_tables($schema = 'main') { // database tables
		$query = "SELECT name FROM `$schema`.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'";
		return $this->column($query);
	}

	public function info_view() { // info schema
		$query = <<<EOT
SELECT
'_SCHEMA_' AS `schema`,
m.`name` AS `table`,
c.`cid` AS `position`,
c.`name` AS `column`,
c.`type`,
c.`notnull`,
c.`dflt_value` AS `default`,
c.`pk`,
s.`seq`
FROM `_SCHEMA_`.sqlite_master AS m
_SEQ_
LEFT JOIN pragma_table_info(m.name, '_SCHEMA_') AS c ON 1
WHERE m.type = 'table' AND m.name NOT LIKE 'sqlite_%'
EOT;
		$no_seq = 'LEFT JOIN (SELECT 0 AS `seq`) AS s ON 1';
		$with_seq = 'LEFT JOIN `_SCHEMA_`.sqlite_sequence AS s USING (name)';
		$union = [];
		$schemas = $this->column('SELECT name AS `schema` FROM pragma_database_list');
		foreach ($schemas as $schema) {
			$seq = $this->cell("SELECT 1 FROM `$schema`.sqlite_master WHERE `name` = 'sqlite_sequence'");
			$select = str_replace('_SEQ_', $seq ? $with_seq : $no_seq, $query);
			$union[] = str_replace('_SCHEMA_', $schema, $select);
		}
		$view = implode("\nUNION\n", $union);
		$this->exec("CREATE TEMPORARY VIEW IF NOT EXISTS temp.info AS $view");
	}

	public function prune_seq() { // experimental
		$this->info_view();
		$rows = $this->rows('SELECT `schema`, `table`, `column`, `seq` FROM temp.info WHERE `pk` AND `seq`');
		foreach ($rows as $row) {
			extract($row);
			$max = $this->cell("SELECT max(`$column`) FROM `$schema`.`$table`", 0);
			if ($seq > $max)
				$this->exec("UPDATE $schema.sqlite_sequence SET `seq` = $max WHERE `name` = '$table'");
		}
	}

}

// PDO interface (using parent:: syntax for clarity)

class SQLiteDW_PDO extends PDO implements SQLiteDW {
	use SQLiteDW_common;

	public static function version() {
		$o = new PDO('sqlite::memory:');
		$v = $o->query('SELECT sqlite_version()')->fetchColumn();
		$o = null; // unset immediately
		return $v;
	}

	public function __construct($file) {
		parent::__construct("sqlite:$file");
		parent::setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		parent::setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
		parent::setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
	}

	public function begin() {
		return parent::beginTransaction();
	}

	// (inherited) public function commit()

	public function rollback() {
		return parent::rollBack();
	}

	/*
	public function execute($stmt, $params) {
		foreach ($params as $param => $value) {
			if (is_int($value) || is_bool($value))
				$type = PDO::PARAM_INT;
			//elseif (is_float($value))
				//$type = PDO::PARAM_FLOAT; // (RFC)
			elseif (is_null($value))
				$type = PDO::PARAM_NULL;
			else
				$type = PDO::PARAM_STR;
			$stmt->bindValue($param, $value, $type);
		}
		return $stmt->execute() ? $stmt : false;
	}
	*/

	// (inherited) public function exec($query)

	public function rows($query) {
		return parent::query($query)->fetchAll();
	}

	public function row($query) {
		$s = parent::query($query);
		$row = $s->fetch();
		$s->closeCursor();
		return $row;
	}

	public function column($query) {
		return parent::query($query)->fetchAll(PDO::FETCH_COLUMN);
	}

	public function cell($query, $default = false) {
		$s = parent::query($query);
		$cell = $s->fetchColumn();
		$s->closeCursor();
		if ($cell === false)
			$cell = $default;
		return $cell;
	}

	// (inherited) public function quote($string)

	public function insert_id() {
		return parent::lastInsertId();
	}

}

// SQLite3 interface (using parent:: syntax for clarity)

class SQLiteDW_Sqlite3 extends Sqlite3 implements SQLiteDW {
	use SQLiteDW_common;

	public static function version() {
		$v = SQLite3::version();
		return $v['versionString'];
	}

	public function __construct($file) {
		parent::__construct($file, SQLITE3_OPEN_READWRITE);
		parent::enableExceptions(true);
	}

	public function begin() {
		return parent::exec('BEGIN');
	}

	public function commit() {
		return parent::exec('COMMIT');
	}

	public function rollback() {
		return parent::exec('ROLLBACK');
	}

	/*
	public function execute($stmt, $params) {
		foreach ($params as $param => $value) {
			if (is_int($value) || is_bool($value))
				$type = SQLITE3_INTEGER;
			elseif (is_float($value))
				$type = SQLITE3_FLOAT
			elseif (is_null($value))
				$type = SQLITE3_NULL;
			else
				$type = SQLITE3_TEXT;
			$stmt->bindValue($param, $value, $type);
		}
		return $stmt->execute();
	}
	*/

	public function exec($query) {
		return parent::exec($query) ? parent::changes() : 0;
	}

	public function rows($query) {
		$rows = [];
		$res = parent::query($query);
		while ($a = $res->fetchArray(SQLITE3_ASSOC))
			$rows[] = $a;
		$res->finalize();
		return $rows;
	}

	public function row($query) {
		return parent::querySingle($query, true);
	}

	public function column($query) {
		$column = [];
		$rows = $this->rows($query);
		foreach ($rows as $row) {
			foreach ($row as $col => $val) {
				$column[] = $val;
				break;
			}
		}
		return $column;
	}

	public function cell($query, $default = false) {
		$cell = parent::querySingle($query);
		if (is_null($cell))
			$cell = $default;
		return $cell;
	}

	public function quote($string) {
		return "'" . SQLite3::escapeString($string) . "'";
	}

	public function insert_id() {
		return parent::lastInsertRowID();
	}

}

//------------------------------
// procedural interface
//------------------------------

function db_connect($file, $sqlite = false) {
	global $db;
	if (isset($db))
		return;
	if ($sqlite) { // force/test SQLite3
		$db = new SQLiteDW_SQLite3($file);
	} else { // use most recent version
		$pdov = SQLiteDW_PDO::version();
		$sqlv = SQLiteDW_SQLite3::version();
		list($pdo1, $pdo2) = explode('.', $pdov);
		list($sql1, $sql2) = explode('.', $sqlv);
		$db = ($pdo1 >= $sql1 && $pdo2 >= $sql2)
			? new SQLiteDW_PDO($file) // use PDO class
			: new SQLiteDW_SQLite3($file); // use SQLite3 class
	}
}

function db_begin() {
	global $db;
	return $db->begin();
}

function db_commit() {
	global $db;
	return $db->commit();
}

function db_rollback() {
	global $db;
	return $db->rollback();
}

/*
function db_prepare($query) { // NOTE: use named params not question marks
	global $db;
	return $db->prepare($query);
}

function db_execute($stmt, $params) { // named params bound by type
	global $db;
	return $db->execute($stmt, $params);
}
*/

function db_transact($queries) {
	global $db;
	$db->transact($queries);
}

function db_exec($query) { // use for all non-SELECTs
	global $db;
	return $db->exec($query);
}

function db_rows($query) {
	global $db;
	return $db->rows($query);
}

function db_row($query) {
	global $db;
	return $db->row($query);
}

function db_column($query) {
	global $db;
	return $db->column($query);
}

function db_cell($query, $default = false) {
	global $db;
	return $db->cell($query, $default);
}

function db_quote($string) {
	global $db;
	return $db->quote($string);
}

function db_insert_id() {
	global $db;
	return $db->insert_id();
}

function db_attach($file, $name) {
	global $db;
	$db->attach($file, $name);
}

function db_detach($name) {
	global $db;
	$db->detach($name);
}

function db_changes() {
	global $db;
	return $db->changes();
}

function db_show_tables($schema = 'main') {
	global $db;
	return $db->show_tables($schema);
}

function db_info_view() {
	global $db;
	$db->info_view();
}

function db_prune_seq() {
	global $db;
	$db->prune_seq();
}
