<?php

/**
 * API Class
 * @package    Database Web API
 * @author     Marco Cesarato <cesarato.developer@gmail.com>
 * @copyright  Copyright (c) 2018
 * @license    http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @link       https://github.com/marcocesarato/Database-Web-API
 */

class API {

	static $instance;
	public $hooks;
	public $dbs = array();
	public $db = null;
	public $dbh = null;
	public $query = array();
	public $ttl = 3600;
	public $cache = array();
	public $connections = array();
	public $request;

	/**
	 * Singleton constructor
	 */
	public function __construct() {
		self::$instance = &$this;
		$this->request  = Request::getInstance();
		$this->logger   = Logger::getInstance();
		$this->hooks    = Hooks::getInstance();
		$this->auth     = Auth::getInstance();
	}

	/**
	 * Returns static reference to the class instance
	 */
	public static function &getInstance() {
		return self::$instance;
	}

	/**
	 * Run API
	 */
	public static function run() {
		$api = self::getInstance();
		$api->parseParams();
		$results = $api->query();
		$api->render($results);
	}

	/**
	 * Returns a database connection
	 * @param null $db
	 * @return object
	 */
	public static function getConnection($db = null) {
		return self::$instance->connect($db);
	}

	/**
	 * Register dataset
	 * @param $datasets
	 */
	public static function registerDatasets($datasets) {
		$api = self::getInstance();
		foreach($datasets as $db_name => $db_config) {
			$api->registerDatabase($db_name, $db_config);
		}
	}

	/**
	 * Register a new database dataset
	 * @param string $name the database dataset name
	 * @param array $args the database dataset properties
	 */
	public function registerDatabase($name = null, $args = array()) {

		$defaults = array(
			'name'             => null,
			'username'         => 'root',
			'password'         => 'root',
			'server'           => 'localhost',
			'port'             => 3306,
			'type'             => 'mysql',
			'table_docs'       => array(),
			'table_blacklist'  => array(),
			'table_list'       => array(),
			'table_free'       => array(),
			'table_readonly'   => array(),
			'column_blacklist' => array(),
			'column_list'      => array(),
			'ttl'              => $this->ttl,
			'receivers'        => array()
		);

		$args = shortcode_atts($defaults, $args);
		$name = $this->slugify($name);

		$this->dbs[$name] = (object) $args;

	}

	/**
	 * Parses rewrite and actual params var and sanitizes
	 * @param null $parts
	 * @return array the params array parsed
	 */
	public function parseParams($parts = null) {

		if($parts == null) {
			$parts = $this->request->input;
		}

		$defaults = array(
			'db'        => null,
			'table'     => null,
			'order_by'  => null,
			'direction' => null,
			'docs'      => null,
			'insert'    => null,
			'update'    => null,
			'limit'     => null,
			'format'    => null,
			'callback'  => null,
			'where'     => null,
			'join'      => null,
			'id'        => null,

			'client_id' => null,
			'referer'   => null,

			'token'            => null,
			'check_token'      => null,
			'check_counter'    => null,
			'force_validation' => null,
			'user_id'          => null,
			'password'         => null,
		);

		//die(print_r($parts));
		$this->query = shortcode_atts($defaults, $parts);
		//die(print_r($this->query));

		if($this->auth->validate($this->query)) {
			if($this->query['db'] == null) {
				$this->hooks->do_action('custom_api_call');
				Request::error('Must select a database', 400);
			}

			$db = $this->getDatabase($this->query['db']);
			$this->setDatabase();

			if(!in_array(strtoupper($this->query['direction']), array('ASC', 'DESC'))) {
				$this->query['direction'] = null;
			}

			if(!in_array($this->query['format'], array('html', 'xml', 'json'))) {
				$this->query['format'] = null;
			}

			if(!$this->auth->is_admin) {

				if(in_array($this->query['table'], $db->table_blacklist)) {
					$this->hooks->do_action('custom_api_call');
					Request::error('Invalid table', 404);
				}

				if(count($db->table_list) > 0 && !in_array($this->query['table'], $db->table_list)) {
					$this->hooks->do_action('custom_api_call');
					Request::error('Invalid table', 404);
				}

			}
		}

		return $this->query;
	}

	/**
	 * Retrieves a database and its properties
	 * @param string $db the DB slug (optional)
	 * @return array the database property array
	 */
	public function getDatabase($db = null) {

		$get_db = $db;

		if($db == null && !is_null($this->db)) {
			return $this->db;
		}

		if(is_object($db)) {
			foreach($this->dbs as $key => $values) {
				if($values->name == $db->name) {
					$get_db = $key;
					break;
				}
			}
		}

		if(empty($db)) {
			$get_db = $this->query['db'];
		}

		if(!array_key_exists($get_db, $this->dbs)) {
			if(empty($db) && !array_key_exists($this->query['db'], $this->dbs)) {
				$this->hooks->do_action('custom_api_call');
			}
			Request::error('Invalid Database', 404);
		}

		return $this->dbs[$get_db];

	}

	/**
	 * Sets the current database
	 * @param string $db the db slug
	 * @return bool success/fail
	 */
	public function setDatabase($db = null) {

		if($db == null) {
			$db = $this->query['db'];
		}

		$db = $this->getDatabase($db);

		if(!$db) {
			return false;
		}

		$this->db = $db;

		return true;

	}

	/**
	 * Establish a database connection
	 * @param string $db the database slug
	 * @return object the PDO object
	 * @todo support port #s and test on each database
	 */
	public function &connect($db = null) {

		// check for existing connection
		if(empty($db) && !empty($this->db->name) && isset($this->connections[$this->db->name])) {
			return $this->connections[$db];
		} else if(isset($this->connections[$db])) {
			return $this->connections[$db];
		}

		$db = $this->getDatabase($db);

		try {
			if($db->type == 'mysql') {
				$dbh = new PDO("mysql:host={$db->server};dbname={$db->name}", $db->username, $db->password);
			} elseif($db->type == 'pgsql') {
				$dbh = new PDO("pgsql:host={$db->server};dbname={$db->name}", $db->username, $db->password);
			} elseif($db->type == 'mssql') {
				$dbh = new PDO("sqlsrv:Server={$db->server};Database={$db->name}", $db->username, $db->password);
			} elseif($db->type == 'sqlite') {
				$dbh = new PDO("sqlite:/{$db->name}");
			} elseif($db->type == 'oracle') {
				$dbh = new PDO("oci:dbname={$db->name}");
			} elseif($db->type == 'ibm') {
				// May require a specified port number as per http://php.net/manual/en/ref.pdo-ibm.connection.php.
				$dbh = new PDO("ibm:DRIVER={IBM DB2 ODBC DRIVER};DATABASE={$db->name};HOSTNAME={$db->server};PROTOCOL=TCPIP;", $db->username, $db->password);
			} elseif(($db->type == 'firebird') || ($db->type == 'interbase')) {
				$dbh = new PDO("firebird:dbname={$db->name};host={$db->server}");
			} elseif($db->type == '4D') {
				$dbh = new PDO("4D:host={$db->server}", $db->username, $db->password);
			} elseif($db->type == 'informix') {
				$dbh = new PDO("informix:host={$db->server}; database={$db->name}; server={$db->server}", $db->username, $db->password);
			} else {
				Request::error('Unknown database type.');
			}
			$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		} catch(PDOException $e) {
			Request::error($e);
		}

		// cache
		$this->connections[$db->type] = &$dbh;

		return $dbh;

	}


	/**
	 * @section Queries
	 */

	/**
	 * Detect method of the request and execute the main database query
	 * @param array $query the database query ASSUMES SANITIZED
	 * @param null $db
	 * @return array|bool
	 */
	public function query($query = null, $db = null) {
		if($query == null) {
			$query = $this->query;
		}
		switch(Request::method()) {
			case 'GET':
				if($this->auth->can_read($query['table'])) {
					if($query['docs']) {
						return $this->query_docs($query, $db);
					} else {
						return $this->query_get($query, $db);
					}
				}
				break;
			case 'POST':
				if($this->auth->can_write($query['table'])) {
					return $this->query_post($query, $db);
				}
				break;
			case 'PUT':
				if($this->auth->can_edit($query['table'])) {
					return $this->query_put($query, $db);
				}
				break;
			case 'DELETE':
				if($this->auth->can_delete($query['table'])) {
					return $this->query_delete($query, $db);
				}
				break;
			default:
				self::response_failed();

				return false;
				break;
		}
		self::no_permissions();

		return false;
	}

	/**
	 * Build and execute the SELECT query from the GET request
	 * @param $query
	 * @param null $db
	 * @return array|bool|mixed|stdClass
	 */
	private function query_docs($query, $db = null) {

		$final_result = array();

		$key = md5(serialize($query) . $this->getDatabase($db)->name . '_docs');

		if($cache = $this->getCache($key)) {
			return $cache;
		}

		try {

			$dbh = &$this->connect($db);
			// check table name
			if($query['table'] == null) {
				Request::error('Must select a table', 404);
			} elseif($query['table'] == "index") {
				$tables = $this->getTables($db);
				foreach($tables as $table) {
					if($this->checkTable($table)) {
						$url            = build_base_url('/docs/' . $table . '.' . $this->query['format']);
						$final_result[] = (object) array(
							'Entity' => $table,
							'Link'   => '<a href="' . $url . '">Go to documentation</a>'
						);
					}
				}
			} elseif(!$this->checkTable($query['table'])) {
				Request::error('Invalid Table', 404);
			} else {

				$sql = null;

				switch($this->db->type) {
					case "pgsql";
						$sql = "SELECT c.column_name, c.udt_name as data_type, is_nullable, character_maximum_length, column_default
				FROM pg_catalog.pg_statio_all_tables AS st
				INNER JOIN pg_catalog.pg_description pgd ON (pgd.objoid=st.relid)
				RIGHT OUTER JOIN information_schema.columns c ON (pgd.objsubid=c.ordinal_position AND c.table_schema=st.schemaname AND c.table_name=st.relname)
				WHERE table_schema = 'public' AND c.table_name = :table;";
						break;
					case "mysql":
						$sql = "SELECT column_name, data_type, is_nullable, character_maximum_length, column_default FROM information_schema.columns WHERE table_name = :table;";
						break;
				}

				if(!empty($sql)) {

					$sth = $dbh->prepare($sql);
					$sth->bindValue(":table", $query['table']);
					$sth->execute();

					$results = $sth->fetchAll();

					foreach($results as $column) {

						if($this->checkColumn($column['column_name'], $query['table'])) {

							$docs_table  = !empty($this->db->table_docs[$query['table']]) ? $this->db->table_docs[$query['table']] : null;
							$docs_column = !empty($docs_table[$column['column_name']]) ? $docs_table[$column['column_name']] : null;

							if(!empty($docs_table) && empty($docs_column)) {
								continue;
							}

							$default = preg_replace("/'([^']*)'.*/si", '$1', $column['column_default']);
							$tmp     = array(
								'Column'      => $column['column_name'],
								'Type'        => strtoupper($column['data_type'] . (!empty($column['character_maximum_length']) ? "(" . $column['character_maximum_length'] . ")" : "")),
								'Optional'    => strtoupper($column['is_nullable']),
								'Default'     => empty($default) ? '-' : $default,
								'Description' => '',
								'Example'     => ''
							);
							if(!empty($docs_column) && is_array($docs_column)) {
								if(!empty($docs_column['description'])) {
									$tmp['Description'] = ucfirst($docs_column['description']);
								}
								if(!empty($docs_column['example'])) {
									$cleaned        = trim($docs_column['example'], "'");
									$tmp['Example'] = !empty($cleaned) ? $cleaned : $docs_column['example'];
								}
							}
							$final_result[] = (object) $tmp;
						}
					}
				} else {
					Request::error("Documentation not implemented for this database type!", 501);
				}
			}
		} catch(PDOException $e) {
			Request::error($e);
		}

		$this->setCache($key, $final_result, $this->getDatabase($db)->ttl);

		return $final_result;
	}

	/**
	 * Build and execute the SELECT query from the GET request
	 * @param array $query the database query ASSUMES SANITIZED
	 * @param null $db
	 * @return array an array of results
	 */
	private function query_get($query, $db = null) {

		$key = md5(serialize($query) . $this->getDatabase($db)->name);

		if($cache = $this->getCache($key)) {
			return $cache;
		}

		try {

			$dbh = &$this->connect($db);

			// check table name
			if($query['table'] == null) {
				Request::error('Must select a table', 404);
			} elseif(!$this->checkTable($query['table'])) {
				Request::error('Invalid Table', 404);
			}

			// check WHERE
			if(isset($query['where']) && is_array($query['where'])) {
				foreach($query['where'] as $column => $value) {
					$column_table = $query['table'];
					$_split       = explode('.', $column, 2);
					if(count($_split) > 1) {
						$column       = $_split[1];
						$column_table = $_split[0];
					}
					if(!$this->checkColumn($column, $column_table)) {
						Request::error('Invalid WHERE column ' . $column_table . '.' . $column, 404);
					}
				}
			}

			// check id
			if(isset($query['id']) && !empty($query['id'])) {
				$query["where"][$this->getFirstColumn($query['table'])] = $query['id'];
			}

			$sql = 'SELECT * FROM ' . $query['table'];

			// build JOIN query
			if(isset($query['join']) && is_array($query['join'])) {

				$methods_available = array('INNER', 'LEFT', 'RIGHT');

				$join_values = array();
				foreach($query['join'] as $table => $join) {
					if(!is_array($join) || count($join) < 2) {
						break;
					}
					$join_method = "";
					if(count($join) > 2) {
						$join['method'] = strtoupper($join['method']);
						if(in_array($join['method'], $methods_available)) {
							$join_method = $join['method'];
						}
					}
					if(!$this->checkTable($query['table'])) {
						continue;
						Request::error('Invalid Join table ' . $table, 404);
					}
					if(!$this->checkColumn($join['on'], $table)) {
						continue;
						Request::error('Invalid Join column ' . $table . '.' . $join['on'], 404);
					}

					$sql .= " {$join_method} JOIN {$table} ON {$table}.{$join['on']} = ";

					if(!$this->checkColumn($join['value'], $table)) {
						$index_value               = self::value_index("join_", $join['on'], $join_values);
						$join_values[$index_value] = $join['value'];
						$sql                       .= ":{$index_value}";
					} else {
						$sql .= "{$query['table']}.{$join['value']}";
					}
				}
			}

			// build WHERE query
			$restriction = $this->auth->sql_restriction($query['table'], 'READ');
			if(isset($query['where']) && is_array($query['where'])) {
				$where        = $this->parse_where($query['table'], $query['where'], $sql);
				$sql          = $where["sql"] . ' AND ' . $restriction;
				$where_values = $where["values"];
			} else if(!empty($restriction)) {
				$sql .= ' WHERE ' . $restriction;
			}

			// build ORDER query
			if(isset($query['order_by']) && !empty($query['order_by'])) {

				$order_by = $query['order_by'];
				if(!is_array($order_by)) {
					$order_by = explode(',', $order_by);
				}

				$order_by = array_map('trim', $order_by);

				$order_query = array();
				foreach($order_by as $column => $column_direction) {
					$order_table = $query['table'];
					$direction   = '';
					if(!is_int($column)) {
						$column = trim($column);
						$_split = array_map('trim', explode('.', $column, 2));
						if(count($_split) > 1 && $this->checkColumn(@$_split[1], @$_split[0])) {
							$order_table = trim($_split[0]);
							$column      = trim($_split[1]);
						} else if(!$this->checkColumn($column, $order_table)) {
							continue;
							if(count($_split) > 1) {
								Request::error('Invalid order column ' . $_split[0] . '.' . $_split[1], 404);
							} else {
								Request::error('Invalid order column ' . $order_table . '.' . $column, 404);
							}
						}
						$order_direction = trim($column_direction);
						if(!empty($order_direction) && in_array(strtoupper($order_direction), array('ASC', 'DESC'))) {
							$direction = $order_direction;
						}
					} else {
						$_split = array_map('trim', explode('.', $column_direction, 2));
						if(count($_split) > 1 && $this->checkColumn(@$_split[1], @$_split[0])) {
							$order_table = $_split[0];
							$column      = $_split[1];
						} else if($this->checkColumn($column_direction, $order_table)) {
							$column = $column_direction;
						} else {
							continue;
							if(count($_split) > 1) {
								Request::error('Invalid order column ' . $_split[0] . '.' . $_split[1], 404);
							} else {
								Request::error('Invalid order column ' . $order_table . '.' . $column_direction, 404);
							}
						}
					}
					$order_query[] = trim("{$order_table}.{$column} {$direction}");
				}
				if(empty($query['direction'])) {
					$query['direction'] = "";
				}
				$sql .= " ORDER BY " . implode(", ", $order_query) . " {$query['direction']}";
			}

			// build LIMIT query
			if(isset($query['limit']) && is_numeric($query['limit'])) {
				$sql .= " LIMIT " . (int) $query['limit'];
			}

			$sql_compiled = $sql;

			$sth = $dbh->prepare($sql);

			// bind WHERE values
			if(isset($where_values) && count($where_values) > 0) {
				foreach($where_values as $key => $value) {
					$type         = self::detect_pdo_type($value);
					$key          = ':' . $key;
					$sql_compiled = preg_replace("/(" . preg_quote($key) . ")([,]|\s|$|\))/i", "'" . $value . "'$2", $sql_compiled);
					$sth->bindValue($key, $value, $type);
				}
			}

			// bind JOIN values
			if(isset($join_values) && count($join_values) > 0) {
				foreach($join_values as $key => $value) {
					$type         = self::detect_pdo_type($value);
					$key          = ':' . $key;
					$sql_compiled = preg_replace("/(" . preg_quote($key) . ")([,]|\s|$|\))/i", "'" . $value . "'$2", $sql_compiled);
					$sth->bindValue($key, $value, $type);
				}
			}

			$this->logger->debug($sql_compiled);
			//die($sql_compiled);

			$sth->execute();

			$results = $sth->fetchAll(PDO::FETCH_OBJ);
			$results = $this->hooks->apply_filters('on_read', $results, $query['table']);

			$results = $this->sanitize_results($query['table'], $results);

		} catch(PDOException $e) {
			Request::error($e);
		}

		$this->setCache($key, $results, $this->getDatabase($db)->ttl);

		return $results;

	}

	/**
	 * Build and execute the INSERT query from the POST request
	 * @param array $query the database query ASSUMES SANITIZED
	 * @param null $db
	 * @return array an array of results
	 */
	private function query_post($query, $db = null) {


		$dbh = &$this->connect($db);

		// check values
		if(!empty($query['insert']) && !is_array($query['insert']) && count($query['insert']) <= 0) {
			Request::error('Invalid values', 400);
		}

		if(!empty($query['table']) && !is_multi_array($query['insert'])) {
			$query['insert'][$query['table']] = $query['insert'];
		}

		foreach($query['insert'] as $table => $values) {
			$columns = array();
			if(!$this->checkTable($table)) {
				Request::error('Invalid Table', 404);
			}

			$i = 0;
			// check columns name
			foreach($values as $key => $value) {
				if(is_array($value)) {
					foreach($value as $column => $column_value) {
						if(!$this->checkColumn($column, $table)) {
							continue;
							Request::error('Invalid column. The column ' . $table . '.' . $column . ' not exists!', 404);
						}
						$columns[$i][$column] = $column_value;
					}
					$i ++;
				} else {
					if(!$this->checkColumn($key, $table)) {
						continue;
						Request::error('Invalid column. The column ' . $table . '.' . $key . ' not exists!', 404);
					}
					$columns[$key] = $value;
				}
			}

			if(is_multi_array($columns)) {
				$all_columns = $columns;
				foreach($all_columns as $columns) {
					$this->execute_insert($table, $columns, $dbh);
				}
			} else {
				$this->execute_insert($table, $columns, $dbh);
			}
		}

		return self::response_success();
	}

	/**
	 * Execute an insert
	 * @param $table
	 * @param $columns
	 * @param null $db
	 */
	private function execute_insert($table, $columns, $dbh) {

		$columns = $this->hooks->apply_filters('on_write', $columns, $table);

		try {
			$sql          = 'INSERT INTO ' . $table . ' (' . implode(', ', array_keys($columns)) . ') VALUES (:' . implode(', :', array_keys($columns)) . ')';
			$sth          = $dbh->prepare($sql);
			$sql_compiled = $sql;

			// bind POST values
			foreach($columns as $column => $value) {
				if(is_array($value)) {
					$value = serialize($value);
				}
				$column = ':' . $column;
				$sth->bindValue($column, $value);
				$sql_compiled = self::debug_compile_sql($sql_compiled, $column, $value);
			}

			$this->logger->debug($sql_compiled);
			$sth->execute();
		} catch(PDOException $e) {
			Request::error($e);
		}
	}

	/**
	 * Build and execute the UPDATE query from the PUT request
	 * @param array $query the database query ASSUMES SANITIZED
	 * @param null $db
	 * @return array an array of results
	 */
	private function query_put($query, $db = null) {

		try {

			$dbh = &$this->connect($db);

			// Check id
			if(isset($query['table']) && !empty($query['table']) && isset($query['id']) && !empty($query['id'])) {
				// Check WHERE
				if(isset($query['where']) && is_array($query['where'])) {
					foreach($query['where'] as $column => $value) {
						$column_table = $query['table'];
						$_split       = explode('.', $column, 2);
						if(count($_split) > 1) {
							$column       = $_split[1];
							$column_table = $_split[0];
						}
						if(!$this->checkColumn($column, $column_table)) {
							Request::error('Invalid WHERE column ' . $column_table . '.' . $column, 404);
						}
					}
					$query['update'][$query['table']]['where']                                         = $query['where'];
					$query['update'][$query['table']]['where'][$this->getFirstColumn($query['table'])] = $query['id'];
				}
				$query['update'][$query['table']]['values']                                        = $query['update'];
				$query['update'][$query['table']]['where'][$this->getFirstColumn($query['table'])] = $query['id'];
				// Check values
			} elseif(!isset($query['update']) && !is_array($query['update']) && count($query['update']) <= 0) {
				Request::error('Invalid values', 400);
			} else {
				foreach($query['update'] as $table => $update) {
					if(isset($update['where']) && is_array($update['where'])) {
						foreach($update['where'] as $column => $value) {
							if(!$this->checkColumn($column, $table)) {
								Request::error('Invalid Where column ' . $column, 404);
							}
						}
					}
				}
			}

			foreach($query['update'] as $table => $values) {
				$where         = $values['where'];
				$values        = $values['values'];
				$values_index  = array();
				$column_values = array();

				$values = $this->hooks->apply_filters('on_edit', $values, $table);

				if(!$this->checkTable($table)) {
					Request::error('Invalid Table', 404);
				}
				// check columns name
				foreach($values as $key => $value) {
					if(!$this->checkColumn($key, $table)) {
						continue;
						Request::error('Invalid column. The column ' . $table . '.' . $key . ' not exists!', 404);
					}
					$column_values[$key] = $value;
					$values_index[]      = $key . ' = :' . $key;
				}

				$sql = 'UPDATE ' . $table;
				$sql .= ' SET ' . implode(', ', $values_index);

				// build WHERE query
				$restriction = $this->auth->sql_restriction($query['table'], 'EDIT');
				if(is_array($where)) {
					$where_parse  = $this->parse_where($table, $where, $sql);
					$sql          = $where_parse["sql"] . ' AND ' . $restriction;
					$where_values = $where_parse["values"];
				} else if(!empty($restriction)) {
					Request::error('Invalid condition', 404);
				}

				$sth          = $dbh->prepare($sql);
				$sql_compiled = $sql;

				// bind PUT values
				foreach($column_values as $key => $value) {
					if(is_array($value)) {
						$value = serialize($value);
					}
					$key          = ':' . $key;
					$sql_compiled = self::debug_compile_sql($sql_compiled, $key, $value);
					if(empty($value)) {
						$value = null;
					}
					$sth->bindValue($key, $value);
				}

				// bind WHERE values
				if(isset($where_values) && count($where_values) > 0) {
					foreach($where_values as $key => $value) {
						$key          = ':' . $key;
						$sql_compiled = self::debug_compile_sql($sql_compiled, $key, $value);
						if(empty($value)) {
							$value = null;
						}
						$sth->bindValue($key, $value);
					}
				}

				$this->logger->debug($sql_compiled);
				//die($sql_compiled);

				$sth->execute();
			}
		} catch(PDOException $e) {
			Request::error($e);
		}

		return self::response_success();
	}

	/**
	 * Build and execute the DELETE query from the DELETE request
	 * @param array $query the database query ASSUMES SANITIZED
	 * @param null $db
	 * @return array an array of results
	 */
	private function query_delete($query, $db = null) {

		try {

			$dbh = &$this->connect($db);

			// check table name
			if(!$this->checkTable($query['table'])) {
				Request::error('Invalid Table', 404);
			}

			// check ID
			if(isset($query['id']) && !empty($query['id'])) {
				$query["where"][$this->getFirstColumn($query['table'])] = $query['id'];
			}

			$sql = 'DELETE FROM ' . $query['table'];

			// build WHERE query
			$restriction = $this->auth->sql_restriction($query['table'], 'DELETE');
			if(isset($query['where']) && is_array($query['where'])) {
				$where        = $this->parse_where($query['table'], $query['where'], $sql);
				$sql          = $where["sql"] . ' AND ' . $restriction;
				$where_values = $where["values"];
			} else if(!empty($restriction)) {
				Request::error('Invalid condition', 404);
			}

			$sth          = $dbh->prepare($sql);
			$sql_compiled = $sql;

			// bind WHERE values
			if(isset($where_values) && count($where_values) > 0) {
				foreach($where_values as $key => $value) {
					$type         = self::detect_pdo_type($value);
					$key          = ':' . $key;
					$sql_compiled = self::debug_compile_sql($sql_compiled, $key, $value);
					$sth->bindValue($key, $value, $type);
				}
			}

			$this->logger->debug($sql_compiled);
			//die($sql_compiled);

			$sth->execute();
		} catch(PDOException $e) {
			Request::error($e);
		}

		return self::response_success();
	}


	/**
	 * @section Output Renders
	 */

	/**
	 * Output with autodetection
	 * @param $data
	 */
	public function render($data) {
		$default_format = Request::method() == 'GET' ? "html" : "json";
		$renderer       = 'render_' . (isset($this->query['format']) ? $this->query['format'] : $default_format);
		$this->$renderer($data);
		die();
	}

	/**
	 * Output JSON encoded data.
	 * @param $data
	 * @param $query
	 */
	public function render_json($data) {

		header('Content-type: application/json');


		if(is_multi_array($data)) {
			$prefix = '';
			$output = '[';
			foreach($data as $row) {
				$output .= $prefix . json_encode($row);
				$prefix = ',';
			}
			$output .= ']';
		} else {
			$output = json_encode($data);
		}
		//die(var_dump(json_last_error()));

		// Prepare a JSONP callback.
		$callback = jsonp_callback_filter($this->query['callback']);

		// Only send back JSONP if that's appropriate for the request.
		if($callback) {
			echo "{$callback}($output);";

			return;
		}
		// If not JSONP, send back the data.
		echo $output;
		die();
	}

	/**
	 * Output data as an HTML table.
	 * @param $data
	 */
	public function render_html($data) {
		require_once(__ROOT__ . '/includes/template/header.php');
		//err out if no results
		if(empty($data)) {
			Request::error('No results found', 404);

			return;
		}
		//render readable array data serialized
		foreach($data as $key => $result) {
			foreach($result as $column => $value) {
				$value_parsed = @unserialize($value);
				if($value_parsed !== false || $value === 'b:0;') {
					if(is_array($data)) {
						$data[$key]->$column = implode("|", $value_parsed);
					}
				}
			}
		}
		//page title
		if(!empty($this->query['docs'])) {
			echo "<h2 class='text-center'>Documentation</h2>";
			echo "<h3>" . $this->query['table'] . "</h3><br>";
		} else {
			echo "<h2>Results</h2>";
		}
		//render table headings
		echo "<table class='table table-striped'>\n<thead>\n<tr>\n";
		foreach(array_keys(get_object_vars(reset($data))) as $heading) {
			echo "\t<th>$heading</th>\n";
		}
		echo "</tr>\n</thead>\n";
		//loop data and render
		foreach($data as $row) {
			echo "<tr>\n";
			foreach($row as $cell) {
				echo "\t<td>$cell</td>\n";
			}
			echo "</tr>";
		}
		echo "</table>";
		require_once(__ROOT__ . '/includes/template/footer.php');
		die();
	}

	/**
	 * Output data as XML.
	 * @param $data
	 */
	public function render_xml($data) {
		header("Content-Type: text/xml");
		$xml = new SimpleXMLElement('<results></results>');
		$xml = object_to_xml($data, $xml);
		echo tidy_xml($xml);
		die();
	}


	/**
	 * @section Database data checks
	 */

	/**
	 * Verify a table exists, used to sanitize queries
	 * @param string $query_table the table being queried
	 * @param string $db the database to check
	 * @return bool true if table exists, otherwise false
	 */
	public function checkTable($query_table, $db = null) {

		if(empty($db)) {
			$db = $this->getDatabase($this->query['db']);
		}

		if($this->auth->authenticated && (!$this->auth->is_admin)) {
			if(!empty($db->table_list) && is_array($db->table_list)) {
				if(!in_array($query_table, $db->table_list)) {
					return false;
				}
			}

			if(!empty($db->table_blacklist) && is_array($db->table_blacklist)) {
				if(in_array($query_table, $db->table_blacklist)) {
					return false;
				}
			}
		}

		$tables = $this->getTables($db);

		return in_array($query_table, $tables);

	}

	/**
	 * Get tables
	 * @param string $db the database to check
	 * @return array an array of the tables names
	 */
	public function getTables($db = null) {
		$key = md5($this->getDatabase($db)->name . '_tables');

		if($cache = $this->getCache($key)) {
			return $cache;
		}

		$dbh = &$this->connect($db);
		try {
			if($this->getDatabase($db)->type == "mysql") {
				$stmt = $dbh->query("SHOW TABLES");
			} else {
				$stmt = $dbh->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
			}
		} catch(PDOException $e) {
			Request::error($e);
		}

		$tables = array();
		while($table = $stmt->fetch()) {
			$tables[] = $table[0];
		}
		$this->setCache($key, $tables, $this->getDatabase($db)->ttl);

		return $tables;
	}

	/**
	 * Verify a column exists
	 * @param string $column the column to check
	 * @param string $table the table to check
	 * @param string $db (optional) the db to check
	 * @return bool
	 * @retrun bool true if exists, otherwise false
	 */
	public function checkColumn($column, $table, $db = null) {

		if(empty($db)) {
			$db = $this->getDatabase($this->query['db']);
		}

		if(!$this->auth->is_admin || Request::method() == 'PUT') {
			if(!empty($db->column_list[$table]) && is_array($db->column_list[$table])) {
				if(!in_array($column, $db->column_list[$table])) {
					return false;
				}
			}

			if(!empty($db->column_blacklist[$table]) && is_array($db->column_blacklist[$table])) {
				if(in_array($column, $db->column_blacklist[$table])) {
					return false;
				}
			}
		}

		$columns = $this->getColumns($table, $db);

		return in_array($column, $columns);

	}

	/**
	 * Get columns
	 * @param string $table the table to check
	 * @param string $db the database to check
	 * @return array an array of the column names
	 */
	public function getColumns($table, $db = null) {

		if(!$this->checkTable($table)) {
			return false;
		}

		$key = md5($this->getDatabase($db)->name . '.' . $table . '_columns');

		if($cache = $this->getCache($key)) {
			return $cache;
		}

		$dbh = &$this->connect($db);

		try {
			if($this->getDatabase($db)->type == "mysql") {
				$q = $dbh->prepare("DESCRIBE {$table}");
			} else {
				$q = $dbh->prepare("SELECT column_name FROM information_schema.columns WHERE table_name ='{$table}'");
			}
			$q->execute();
			$columns = $q->fetchAll(PDO::FETCH_COLUMN);
		} catch(PDOException $e) {
			Request::error($e);
		}

		$this->setCache($key, $columns, $this->getDatabase($db)->ttl);

		return $columns;
	}

	/**
	 * Returns the first column in a table
	 * @param string $table the table
	 * @param string $db the datbase slug
	 * @return string the column name
	 */
	public function getFirstColumn($table, $db = null) {

		$columns = $this->getColumns($table, $db);

		return reset($columns);

	}


	/**
	 * @section Database utils
	 */

	/**
	 * Build WHERE query and assign to an array the values to bind on the PDO obj
	 * @param $main_table
	 * @param $where
	 * @param $sql
	 * @return array
	 */
	private function parse_where($main_table, $where, $sql) {

		$prefix       = "where_";
		$where_sql    = array();
		$where_values = array();

		$cases = array(
			'>' => '>',
			'<' => '<',
			'%' => 'LIKE',
		);

		$sql .= " WHERE ";

		foreach($where as $column => $values_column) {

			$table  = $main_table;
			$_split = explode('.', $column, 2);
			if(count($_split) > 1) {
				$table  = $_split[0];
				$column = $_split[1];
			}

			if(!is_array($values_column)) { // Check equal case
				$_value_split = explode('.', $values_column, 2);
				if(count($_value_split) > 1 && $this->checkColumn(@$_value_split[1], @$_value_split[0])) {
					$index_value = $_value_split[0] . "." . $_value_split[1];
				} else {
					$index_key                = self::value_index($prefix, $column, $where_values);
					$index_value              = " :" . $index_key;
					$where_values[$index_key] = $values_column;
				}
				$where_sql[] = "{$table}.{$column} = {$index_value}";
			} else {

				$where_in = array();

				foreach($values_column as $condition => $value_condition) {

					if(array_key_exists($condition, $cases)) { // Check special cases
						$bind = $cases[$condition];
						if(!is_array($value_condition)) {
							$_value_split = explode('.', $value_condition, 2);
							if(count($_value_split) > 1 && $this->checkColumn(@$_value_split[1], @$_value_split[0])) {
								$index_value = $_value_split[0] . "." . $_value_split[1];
							} else {
								$index_key                = self::value_index($prefix, $column, $where_values);
								$index_value              = " :" . $index_key;
								$where_values[$index_key] = $value_condition;
							}
							$where_sql[] = "{$table}.{$column} " . $bind . " {$index_value}";
						} else {
							foreach($value_condition as $value) {
								$index_key                = self::value_index($prefix, $column, $where_values);
								$index_value              = " :" . $index_key;
								$where_values[$index_key] = $value;
								$where_sql[]              = "{$table}.{$column} " . $bind . " {$index_value}";
							}
						}

					} elseif($condition === '!') { // Check unequal cases

						$where_not_in = array();
						if(!is_array($value_condition)) {
							$value_condition = array($value_condition);
						}

						foreach($value_condition as $value) {
							$_value_split = explode('.', $value, 2);
							if(count($_value_split) > 1 && $this->checkColumn(@$_value_split[1], @$_value_split[0])) {
								$index_value = $_value_split[0] . "." . $_value_split[1];
							} else {
								$index_key                = self::value_index($prefix, $column, $where_values);
								$index_value              = " :" . $index_key;
								$where_values[$index_key] = $value;
							}
							$where_not_in[] = $index_value;
						}

						if(count($where_not_in) > 0) {
							$where_sql[] = "{$table}.{$column} NOT IN (" . implode(", ", $where_not_in) . ")";
						}

					} elseif(!is_array($value_condition) && is_int($condition) || $condition === '=') { // Check equal array cases
						$_value_split = explode('.', $value_condition, 2);
						if(count($_value_split) > 1 && $this->checkColumn(@$_value_split[1], @$_value_split[0])) {
							$index_value = $_value_split[0] . "." . $_value_split[1];
						} else {
							$index_key                = self::value_index($prefix, $column, $where_values);
							$index_value              = " :" . $index_key;
							$where_values[$index_key] = $value_condition;
						}
						$where_in[] = $index_value;
					}
				}

				if(count($where_in) > 0) {
					$where_sql[] = "{$table}.{$column} IN (" . implode(", ", $where_in) . ")";
				}
			}
		}

		if(count($where_sql) > 0) {
			$sql .= implode(" AND ", $where_sql);
		}

		//die($sql);

		$result           = array();
		$result["sql"]    = $sql;
		$result["values"] = $where_values;

		return $result;
	}

	/**
	 * Change column value index name for bind value on PDO
	 * @param string $prefix
	 * @param $column
	 * @param $array
	 * @return string
	 */
	private static function value_index($prefix = "_", $column, $array) {
		$i      = 1;
		$column = $prefix . $column;
		$index  = $column;
		while(array_key_exists($index, $array)) {
			$i ++;
			$index = $column . "_" . $i;
		}

		return $index;
	}

	/**
	 * Detect the type of value and return the PDO::PARAM
	 * @param $value
	 * @return int
	 */
	private static function detect_pdo_type($value) {
		if(filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
			return PDO::PARAM_BOOL;
		}
		if(filter_var($value, FILTER_VALIDATE_INT)) {
			return PDO::PARAM_INT;
		}
		if(is_null($value)) {
			return PDO::PARAM_NULL;
		}

		return PDO::PARAM_STR;
	}

	/**
	 * Compile PDO prepare
	 * @param $string
	 * @param $key
	 * @param $value
	 * @return null|string|string[]
	 */
	private static function debug_compile_sql($string, $key, $value) {
		$string = preg_replace("/" . $key . "([,]|\s|$|\))/i", "'" . $value . "'$1", $string);

		return $string;
	}


	/**
	 * @section Responses
	 */

	/**
	 * Return success reponse
	 * @return array
	 */
	private static function response_success() {
		http_response_code(200);

		return array("response" => (object) array('status' => 200, 'message' => 'OK'));
	}

	/**
	 * Return failed reponse
	 */
	private static function response_failed() {
		Request::error("Bad request", 400);
	}

	/**
	 * Return failed reponse
	 */
	private static function no_permissions() {
		Request::error("No permissions", 403);
	}


	/**
	 * @section Data manipulation
	 */

	/**
	 * Modifies a string to remove all non-ASCII characters and spaces.
	 * @param $text
	 * @return null|string|string[]
	 */
	private function slugify($text) {
		$text = preg_replace('~[^\pL\d]+~u', '-', $text);
		if(function_exists('iconv')) {
			$text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
		}
		$text = preg_replace('~[^-\w]+~', '', $text);
		$text = trim($text, '-');
		$text = preg_replace('~-+~', '-', $text);
		$text = strtolower($text);
		if(empty($text)) {
			return 'n-a';
		}

		return $text;
	}

	/**
	 * Remove any blacklisted columns from the data set.
	 * @param $table
	 * @param $results
	 * @param null $db
	 * @return mixed
	 */
	private function sanitize_results($table, $results, $db = null) {

		$db = $this->getDatabase($db);

		foreach($results as $key => $result) {

			if(!$this->auth->is_admin) {
				// blacklist
				if(!empty($db->column_blacklist[$table]) && is_array($db->column_blacklist[$table])) {
					foreach($db->column_blacklist[$table] as $column) {
						unset($results[$key]->$column);
					}
				}

				// whitelist
				if(!empty($db->column_list[$table]) && is_array($db->column_list[$table])) {
					foreach($result as $column => $value) {
						if(count($db->column_list[$table]) > 0) {
							if(!in_array($column, $db->column_list[$table])) {
								unset($results[$key]->$column);
							}
						}
					}
				}
			}
			// Sanitize encoding
			foreach($result as $column => $value) {
				$results[$key]->$column = utf8_encode($value);
			}
		}

		return $results;
	}


	/**
	 * @section Cache
	 */

	/**
	 * Retrieve data from Alternative PHP Cache (APC).
	 * @param $key
	 * @return bool|mixed
	 */
	private function getCache($key) {

		if(!extension_loaded('apc') || (ini_get('apc.enabled') != 1)) {
			if(isset($this->cache[$key])) {
				return $this->cache[$key];
			}
		} else {
			return apc_fetch($key);
		}

		return false;
	}

	/**
	 * Store data in Alternative PHP Cache (APC).
	 * @param $key
	 * @param $value
	 * @param null $ttl
	 * @return array|bool
	 */
	private function setCache($key, $value, $ttl = null) {

		if($ttl == null) {
			$ttl = (isset($this->db->ttl)) ? $this->db->ttl : $this->ttl;
		}

		$key = 'api_' . $key;

		if(extension_loaded('apc') && (ini_get('apc.enabled') == 1)) {
			return apc_store($key, $value, $ttl);
		}

		$this->cache[$key] = $value;

		return true;
	}
}

$API = new API();
