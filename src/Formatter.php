<?php

namespace GBradley\DBDiff;

class Formatter {

	protected $color_src = 31;
	protected $color_dest = 32;
	protected $should_colorize;

	public function __construct() {
		$this->should_colorize = $this->isCli();
	}

	/**
	 * Format and return a header result.
	 */
	public function header(string $table_src, string $table_dest) : ?String {
		return null;
	}

	/**
	 * Format a diff result and return the foromatting string.
	 */
	public function format($id, array $row_a = null, array $row_b = null, string $table_src, string $table_dest) : string {

		$rows = ['', '', '', ''];

		// Determine the max length of the first column.
		$heading_len = max(mb_strlen($id), mb_strlen($table_src), mb_strlen($table_dest));

		// Add the first column for each row.
		$rows[0] .= str_pad($id, $heading_len);
		$rows[2] .= str_pad($table_src, $heading_len);
		$rows[3] .= str_pad($table_dest, $heading_len);

		// Determine the columns and iterate though them.
		$columns = array_keys(is_array($row_a) ? $row_a : $row_b);
		foreach ($columns as $column) {
			
			// Get the value from each row.
			$value_a = $row_a ? $this->formatValue($row_a[$column]) : null;
			$value_b = $row_b ? $this->formatValue($row_b[$column]) : null;

			// Determine the max length of the column, then add the values tp the row.
			$column_len = max(mb_strlen($column), mb_strlen($value_a), mb_strlen($value_b));
			$rows[0] .= ' | ' . str_pad($column, $column_len);
			$rows[2] .= ' | ' . $this->colorize($row_a ? str_pad($value_a, $column_len) : str_repeat('-', $column_len), $this->color_src);
			$rows[3] .= ' | ' . $this->colorize($row_b ? str_pad($value_b, $column_len) : str_repeat('-', $column_len), $this->color_dest);
		}

		// Add a separator between the column names and the values.
		$rows[1] = str_repeat('_', mb_strlen($rows[0]));

		return implode(PHP_EOL, $rows) . PHP_EOL . PHP_EOL;
	}

	/**
	 * Format a value by quoting where necessary and truncating to a given length.
	 */
	protected function formatValue($value, int $length = 50) : string {

		if (is_string($value)) {
			// Mark strings as quoted, and escape newlines.
			$quote = true;
			$length -= 2;
			$value = preg_replace("/[\r\n]/", "\\n", $value);
		} else {
			// Don't quote non-strings, and show the special case of NULL explicitly.
			$quote = false;
			$value = is_null($value) ? 'NULL' : (string) $value;
		}

		// Truncate to the given length, adding quotes & ellipsis as needed.
		$ellipsis = '...';
		$max = mb_strlen($value) > $length ? $length - strlen($ellipsis) : $length;
		$value = mb_substr($value, 0, $max) . ($max < $length ? $ellipsis : '');

		return $quote ? '"' . $value . '"' : $value;
	}

	/**
	 * Return a colorized version of a string.
	 */
	protected function colorize(string $value, int $color) : string {
		return $this->should_colorize
			? "\033[" . $color . "m" . $value . "\033[0m"
			: $value;
	}

	/**
	 * Determine if the current script is running from the command line.
	 */
	protected function isCli() : bool {
		return strpos(PHP_SAPI, 'cli') === 0;
	}

}