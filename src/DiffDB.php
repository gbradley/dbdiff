<?php

namespace GBradley\DiffDB;

use PDO;
use PDOStatement;

class DiffDB {

	protected $pdo;
	protected $table_src;
	protected $table_dest;
	protected $database_src;
	protected $database_dest;
	protected $constraints = [];
	protected $columns;
	protected $primary_key;
	protected $bindings = [];
	protected $formatter;

	public function __construct(PDO $pdo = null) {
		$this->pdo = $pdo;
	}

	/**
	 * Set the PDF connection used to execute the query.
	 */
	public function connect(PDO $pdo) : DiffDB {
		$this->pdo = $pdo;
		return $this;
	}

	/**
	 * Set the columns to compare.
	 */
	public function compare(array $columns) : DiffDB {
		$this->columns = $columns;
		return $this;
	}
	
	/**
	 * Specify the source and destination tables (and optionally the database).
	 */
	public function from(string $table_src, string $table_dest, string $database = null, string $database_dest = null) : DiffDB {

		$this->table_src = $table_src;
		$this->table_dest = $table_dest;

		// If only 1 DB paramter is provided, use it for both tables. Otherwise, treat as src / destination.
		$this->database_src = $database;
		$this->database_dest = func_num_args() == 3 ? $database : $database_dest;

		// Give each table an alias and map the names to their alias.
		$this->table_src_alias = 's';
		$this->table_dest_alias = 'd';
		$this->aliases = [
			$this->table_src 	=> $this->table_src_alias,
			$this->table_dest 	=> $this->table_dest_alias,
		];

		return $this;
	}

	/**
	 * Set a column & value constraint which either the source or destination column must pass for the row to be
	 * included in the resultset.
	 */
	public function where(string $column, $value) : DiffDB {
		$this->constraints[$column] = $value;
		return $this;
	}

	/**
	 * Set the primary key for both tables.
	 */
	public function primaryKey(string $primary_key) : DiffDB {
		$this->primary_key = $primary_key;
		return $this;
	}

	/**
	 * Set the formatter to use when outputting results.
	 */
	public function format(Formatter $formatter) : DiffDB {
		$this->formatter = $formatter;
		return $this;
	}

	/**
	 * Execute the query and return the number of results.
	 */
	public function count() : int {
		$sql = 'SELECT COUNT(*) AS total FROM (' . $this->getSql() . ') AS t';
		return $this->getQuery($sql)
			->fetch(PDO::FETCH_ASSOC)['total'];
	}

	/**
	 * Execute a callback for each diff in the resultset.
	 */
	public function each(Callable $callback = null) : int {
		$count = 0;
		$query = $this->getQuery($this->getSql());
		while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
			call_user_func_array($callback, $this->diffRow($row));
			$count++;
		}
		return $count;
	}

	/**
	 * Output each diff after passing them through a formatter. By default,
	 * the formatted results wiil echoed.
	 */
	public function output(Callable $handler = null) : int {

		// The default handler simply echos the formatted result of each diff.
		if ($handler === null) {
			$handler = function($result) {
				echo $result;
			};
		}

		// Create a function which will accept the diff and pass the formatted reuslt to the handler.
		$processor = function($id, $source, $dest) use ($handler) {
			$handler(
				$this->getFormatter()->format(
					$id,
					$source,
					$dest,
					$this->table_src,
					$this->table_dest
				)
			);
		};

		return $this->each($processor);
	}

	/**
	 * Given a row of data returned from the query results, extract the row data into id plus source & destination arrays.
	 */
	protected function diffRow($row) : array {

		// Create an array to store the retrieved results.
		$data = [
			$this->table_src_alias 	=> [],
			$this->table_dest_alias	=> []
		];

		// Create an array to store whether the row exists in each table.
		$exists = [
			$this->table_src_alias 	=> false,
			$this->table_dest_alias	=> false
		];

		// Extract the primary ID value and remove it from the array.
		$id = $row[$this->primary_key];
		unset($row[$this->primary_key]);

		// Now work through the array.
		foreach ($row as $column => $value) {

			// Extract the alias and the column name.
			list($alias, $column) = explode('_', $column, 2);

			if ($column == $this->primary_key) {
				// The column is the ID, so we can determine if the row exists in the aliased table by the existance of a value.
				$exists[$alias] = !!$value;
			} else {
				// The column is not the ID, so just store the value in the appropriate array.
				$data[$alias][$column] = $value;
			}
		}

		// Return the data by computing the diff between the two arrays in each direction. If the row doesn't exist, just return null.
		return [
			$id,
			$exists[$this->table_src_alias] ? array_diff_assoc($data[$this->table_src_alias], $data[$this->table_dest_alias]) : null,
			$exists[$this->table_dest_alias] ? array_diff_assoc($data[$this->table_dest_alias], $data[$this->table_src_alias]) : null,
		];
	}

	/**
	 * Return a formatter with which to format the output.
	 */
	protected function getFormatter() : Formatter {
		if ($this->formatter === null) {
			$this->formatter = new Formatter();
		}
		return $this->formatter;
	}

	/**
	 * Prepare and execute a query and return the PDO statement object with which we can get results.
	 */
	protected function getQuery(string $sql) : PDOStatement {
		$query = $this->pdo->prepare($sql);
		$query->execute($this->bindings);
		return $query;
	}

	/**
	 * Return the SQL for the query. This works by JOINing one table to the other, filtering by constraints & columns; then by doing the same 
	 * in the opposite order and UNIONing the results together.
	 */
	protected function getSql() : string {
		return implode(' ' , [
			$this->selectStatement($this->table_src, $this->table_dest, $this->database_src, $this->database_dest),
			'UNION',
			$this->selectStatement($this->table_dest, $this->table_src, $this->database_dest, $this->database_src),
		]);
	}

	/**
	 * Return the SELECT statement for a table.
	 */
	protected function selectStatement(string $from_table, string $join_table, string $from_database = null, string $join_database = null) : string {

		$clauses = [];
		$clauses[] = 'SELECT ' . $this->qualify($from_table, $this->primary_key) . ', ' . $this->selectClause($this->table_src) . ', ' . $this->selectClause($this->table_dest);
		$clauses[] = $this->fromClause($from_table, $from_database);
		$clauses[] = $this->leftJoinClause($join_table, $join_database);
		$clauses[] = $this->whereClause($from_table);

		return implode(' ', $clauses);
	}

	/**
	 * Return the SELECT clause.
	 */
	protected function selectClause(string $table) : string {

		// Select all the provided columns as well as the primary key. We explicitly request the PK under its own alias so we can
		// differentiate between a row where all values are NULL and a row that doens't exist in the original table.
		$columns = array_merge([$this->primary_key], $this->columns);

		return implode(', ', array_map(function($column) use ($table) {
			return $this->qualify($table, $column) . ' AS ' . $this->escape($this->alias($table) . '_' . $column);
		}, $columns));
	}

	/**
	 * Return the FROM clause.
	 */
	protected function fromClause(string $table, string $database = null) : string {
		$db = $database ? $this->escape($database) . '.' : '';
		return 'FROM ' . $db . $this->escape($table) . ' AS ' . $this->alias($table);
	}

	/**
	 * Return the JOIN clause to join the given table.
	 */
	protected function leftJoinClause(string $table, string $database = null) : string {
		$db = $database ? $this->escape($database) . '.' : '';
		return 'LEFT JOIN ' . $db . $this->escape($table) . ' AS ' . $this->alias($table) . ' ON ' . $this->qualify($this->table_src, $this->primary_key) . ' = ' . $this->qualify($this->table_dest, $this->primary_key);
	}

	/**
	 * Return the WHERE clause for the query.
	 */
	protected function whereClause(string $table) : string {

		$whereClauses = [
			$this->whereConstraintsClause(),
			$this->whereColumnsClause($table)
		];

		return 'WHERE ' . implode(' AND ', array_filter($whereClauses));
	}

	/**
	 * Return the WHERE clause for the constaints, testing for rows where the value in either table matches that given by the constraints.
	 */
	protected function whereConstraintsClause() : ?string {
		$parts = [];
		foreach ($this->constraints as $column => $value) {
			$parts[] = '(' . $this->qualify($this->table_src, $column) . ' = ? OR ' . $this->qualify($this->table_dest, $column) . ' = ?)';
			$this->bindings[] = $value;
			$this->bindings[] = $value;
		}
		return count($parts) ? '('. implode(' AND ', $parts) . ')' : null;
	}

	/**
	 * Return the WHERE clause for the columns, testing for at least one of the given columns not maching their value in
	 * the corresponding table, or where the corresponding table doesn't have a row for the ID.
	 */
	protected function whereColumnsClause(string $table) : string {
		$parts = array_map(function($column) {
			return $this->qualify($this->table_src, $column) . ' <> ' . $this->qualify($this->table_dest, $column);
		}, $this->columns);

		$parts[] = $this->qualify($table == $this->table_src ? $this->table_dest : $this->table_src, $this->primary_key) . ' IS NULL';

		return '(' . implode(' OR ', $parts) . ')';
	}

	/**
	 * Return a column's qualified & aliased name.
	 */
	protected function qualify(string $table, string $column) : string {
		return $this->alias($table) . '.' . $this->escape($column);
	}

	/**
	 * Escape an SQL's database / table / column name.
	 */
	protected function escape(string $value) : string {
		return '`' . $value . '`';
	}

	/**
	 * Return a table's alias.
	 */
	protected function alias(string $table) : string {
		return $this->aliases[$table];
	}

}