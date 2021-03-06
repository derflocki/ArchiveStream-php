<?php

class ArchiveStream
{
	protected $use_container_dir = false;
	
	protected $container_dir_name = '';
	
	private $errors = array();
	
	private $error_log_filename = 'archive_errors.log';
	
	private $error_header_text = 'The following errors were encountered while generating this archive:';
	
	protected $block_size = 1048576; // process in 1 megabyte chunks
	/**
	 * Create a new ArchiveStream object.
	 *
	 * @param string $name name of output file (optional).
	 * @param array $opt hash of archive options (see archive options in readme)
	 * @access public
	 */
	public function __construct($name = null, $opt = array(), $base_path = null)
	{
		// save options
		$this->opt = $opt;
		
		// if a $base_path was passed set the protected property with that value, otherwise leave it empty
		$this->container_dir_name = isset($base_path) ? $base_path . '/' : '';

		// set large file defaults: size = 20 megabytes, method = store
		if ( !isset($this->opt['large_file_size']) )
			$this->opt['large_file_size'] = 20 * 1024 * 1024;
		if ( !isset($this->opt['large_files_only']) )
			$this->opt['large_files_only'] = false;

		$this->output_name = $name;
		if ( $name || isset($opt['send_http_headers']) )
			$this->need_headers = true;

		// turn off output buffering
		while (ob_get_level() > 0)
		{
			ob_end_flush();
		}
	}

	/**
	 * Create instance based on useragent string
	 *
	 * @param string $base_filename the base of the filename that will be appended with the correct extention
	 * @param array $opt hash of archive options (see above for list)
	 * @return ArchiveStream for either zip or tar
	 * @access public
	 */
	public static function instance_by_useragent( $base_filename = null, $opt = array() )
	{
		$user_agent = (isset($_SERVER['HTTP_USER_AGENT']) ? strtolower($_SERVER['HTTP_USER_AGENT']) : '');

		// detect windows and use zip
		if (strpos($user_agent, 'windows') !== false)
		{
			require_once(__DIR__ . '/zipstream.php');
			$filename = (($base_filename === null) ? null : $base_filename . '.zip');
			return new ArchiveStream_Zip($filename, $opt, $base_filename);
		}
		// fallback to tar
		else
		{
			require_once(__DIR__ . '/tarstream.php');
			$filename = (($base_filename === null) ? null : $base_filename . '.tar');
			return new ArchiveStream_Tar($filename, $opt, $base_filename);
		}
	}

	/**
	 * Add file to the archive
	 *
	 * Parameters:
	 *
	 * @param string $name path of file in archive (including directory).
	 * @param string $data contents of file
     * @param array $opt hash of file options (see above for list)
     * @access public
	 */
	function add_file( $name, $data, $opt = array() )
	{
		// calculate header attributes
		$this->meth_str = 'deflate';
		$meth = 0x08;

		// send file header
		$this->init_file_stream_transfer( $name, strlen($data), $opt, $meth );

		// send data
		$this->stream_file_part($data, $single_part = true);

		// complete the file stream
		$this->complete_file_stream();
	}

	/**
	 * Add file by path
	 *
	 * @param string $name name of file in archive (including directory path).
	 * @param string $path path to file on disk (note: paths should be encoded using
	 *          UNIX-style forward slashes -- e.g '/path/to/some/file').
     * @param array $opt hash of file options (see above for list)
	 * @access public
	 */
	function add_file_from_path( $name, $path, $opt = array() )
	{
		if ( $this->opt['large_files_only'] || $this->is_large_file($path) ) {
			// file is too large to be read into memory; add progressively
			$this->add_large_file($name, $path, $opt);
		} else {
			// file is small enough to read into memory; read file contents and
			// handle with add_file()
			$data = file_get_contents($path);
			$this->add_file($name, $data, $opt);
		}
	}
	
	/**
	 * Log an error to be output at the end of the archive
	 * 
	 * @param string $message error text to display in log file
	 */
	public function push_error($message)
	{
		$this->errors[] = (string) $message;
	}
	
	/**
	 * Set whether or not all elements in the archive will be placed within one container directory
	 * 
	 * @param bool $bool true to use contaner directory, false to prevent using one. Defaults to false
	 */
	public function set_use_container_dir($bool = false)
	{
		$this->use_container_dir = (bool) $bool;
	}
	
	/**
	 * Set the name filename for the error log file when it's added to the archive
	 * 
	 * @param string $name the filename for the error log
	 */
	public function set_error_log_filename($name)
	{
		if (isset($name))
		{
			$this->error_log_filename = (string) $name;
		}
	}
	
	/**
	 * Set the first line of text in the error log file
	 * 
	 * @param string $msg the text to display on the first line of the error log file
	 */
	public function set_error_header_text($msg)
	{
		if (isset($msg))
		{
			$this->error_header_text = (string) $msg;
		}
	}

	/***************************
	 * PRIVATE UTILITY METHODS *
	 ***************************/

	/**
	 * Add a large file from the given path
	 *
	 * @param string $name name of file in archive (including directory path).
	 * @param string $path path to file on disk (note: paths should be encoded using
	 *          UNIX-style forward slashes -- e.g '/path/to/some/file').
     * @param array $opt hash of file options (see above for list)
     * @access protected
	 */
	protected function add_large_file( $name, $path, $opt = array() )
	{
		// send file header
		$this->init_file_stream_transfer( $name, filesize($path), $opt );

		// open input file
		$fh = fopen($path, 'rb');

		// send file blocks
		while ($data = fread($fh, $this->block_size))
		{
			// send data
			$this->stream_file_part($data);
		}

		// close input file
		fclose($fh);

		// complete the file stream
		$this->complete_file_stream();
	}

	/**
	 * Is this file larger than large_file_size?
	 *
	 * @param string $path path to file on disk
	 * @return bool true if large, false if small
	 * @access protected
	 */
	protected function is_large_file( $path )
	{
		$st = stat($path);
		return ($this->opt['large_file_size'] > 0) && ($st['size'] > $this->opt['large_file_size']);
	}

	/**
	 * Send HTTP headers for this stream.
	 *
	 * @access private
	 */
	private function send_http_headers()
	{
		// grab options
		$opt = $this->opt;

		// grab content type from options
		if ( isset($opt['content_type']) )
			$content_type = $opt['content_type'];
		else
			$content_type = 'application/x-zip';

		// grab content type encoding from options and append to the content type option
		if ( isset($opt['content_type_encoding']) )
			$content_type .= '; charset=' . $opt['content_type_encoding'];

		// grab content disposition
		$disposition = 'attachment';
		if ( isset($opt['content_disposition']) )
			$disposition = $opt['content_disposition'];

		if ( $this->output_name )
			$disposition .= "; filename=\"{$this->output_name}\"";

		$headers = array(
			'Content-Type'              => $content_type,
			'Content-Disposition'       => $disposition,
			'Pragma'                    => 'public',
			'Cache-Control'             => 'public, must-revalidate',
			'Content-Transfer-Encoding' => 'binary',
		);

		foreach ( $headers as $key => $val )
			header("$key: $val");
	}

	/**
	 * Send string, sending HTTP headers if necessary.
	 *
	 * @param string $data data to send
	 * @access protected
	 */
	protected function send( $data )
	{
		if ($this->need_headers)
			$this->send_http_headers();
		$this->need_headers = false;

		echo $data;
	}
	
	/**
	 * If errors were encountered, add an error log file to the end of the archive
	 */
	function add_error_log()
	{
		if (!empty($this->errors))
		{
			$msg = $this->error_header_text;
			foreach ($this->errors as $err)
			{
				$msg .= "\r\n\r\n" . $err;
			}
			
			// stash current value so it can be reset later
			$temp = $this->use_container_dir;
			
			// set to false to put the error log file in the root instead of the container directory, if we're using one
			$this->use_container_dir = false;
			
			$this->add_file($this->error_log_filename, $msg);
			
			// reset to original value and dump the temp variable
			$this->use_container_dir = $temp;
			unset($temp);
		}
	}

	/**
	 * Convert a UNIX timestamp to a DOS timestamp.
	 *
	 * @param int $when unix timestamp
	 * @return string DOS timestamp
	 * @access protected
	 */
	protected function dostime( $when = 0 )
	{
		// get date array for timestamp
		$d = getdate($when);

		// set lower-bound on dates
		if ($d['year'] < 1980) {
			$d = array('year' => 1980, 'mon' => 1, 'mday' => 1,
				'hours' => 0, 'minutes' => 0, 'seconds' => 0);
		}

		// remove extra years from 1980
		$d['year'] -= 1980;

		// return date string
		return ($d['year'] << 25) | ($d['mon'] << 21) | ($d['mday'] << 16) |
				($d['hours'] << 11) | ($d['minutes'] << 5) | ($d['seconds'] >> 1);
	}

	/**
	 * Split a 64bit integer to two 32bit integers
	 *
	 * @param mixed $value integer or gmp resource
	 * @return array containing high and low 32bit integers
	 * @access protected
	 */
	protected function int64_split($value)
	{
		// gmp
		if (is_resource($value))
		{
			$hex  = str_pad(gmp_strval($value, 16), 16, '0', STR_PAD_LEFT);

	        $low  = $this->gmp_convert( substr($hex, 0, 8), 16, 10 );
	        $high = $this->gmp_convert( substr($hex, 8, 8), 16, 10 );
		}
		// int
		else
		{
			$left  = 0xffffffff00000000;
			$right = 0x00000000ffffffff;

			$low  = ($value & $left) >>32;
			$high = $value & $right;
		}

		return array($high, $low);
	}

	/**
	 * Create a format string and argument list for pack(), then call pack() and return the result.
	 *
	 * @param array key being the format string and value being the data to pack
	 * @return string binary packed data returned from pack()
	 * @access protected
	 */
	protected function pack_fields( $fields )
	{
		list ($fmt, $args) = array('', array());

		// populate format string and argument list
		foreach ($fields as $field) {
			$fmt .= $field[0];
			$args[] = $field[1];
		}

		// prepend format string to argument list
		array_unshift($args, $fmt);

		// build output string from header and compressed data
		return call_user_func_array('pack', $args);
	}

	/**
	 * Convert a number between bases via gmp
	 *
	 * @param int $num number to convert
	 * @param int $base_a base to convert from
	 * @param int $base_b base to convert to
	 * @return string number in string format
	 * @access private
	 */
	private function gmp_convert($num, $base_a, $base_b)
	{
		$gmp_num = gmp_init($num, $base_a);

		if (!$gmp_num)
		{
			die("gmp_convert could not convert [$num] from base [$base_a] to base [$base_b]");
		}

	    return gmp_strval ($gmp_num, $base_b);
	}
}
