<?php

namespace cora;

/**
 * Workhorse for processing an API request. This has all of the core
 * functionality.
 *
 * @author Jon Ziebell
 */
final class cora {

  /**
   * The singleton.
   */
  private static $instance;

  /**
   * The timestamp when processing of the API request started.
   *
   * @var int
   */
  private $start_timestamp;

  /**
   * The original request passed to this object, usually $_REQUEST. Stored
   * right away so logging and error functions have access to it.
   *
   * @var array
   */
  private $request;

  /**
   * A list of all of the API calls extracted from the request. This is stored
   * so that logging and error functions have access to it.
   *
   * @var array
   */
  private $api_calls;

  /**
   * An array of the API responses. For single API calls, count() == 1, for
   * batch calls there will be one row per call.
   *
   * @var array
   */
  private $response_data = array();

  /**
   * The actual response in array form. It is stored here so the shutdown
   * handler has access to it.
   *
   * @var array
   */
  private $response;

  /**
   * This is necessary because of the shutdown handler. According to the PHP
   * documentation and various bug reports, when the shutdown function
   * executes the current working directory changes back to root.
   * https://bugs.php.net/bug.php?id=36529. This is cool and all but it breaks
   * the autoloader. My solution for this is to just change the working
   * directory back to what it was when the script originally ran.
   *
   * Obviuosly I could hardcode this but then users would have to configure
   * the cwd when installing Cora. This handles it automatically and seems to
   * work just fine. Note that if the class that the autoloader needs is
   * already loaded, the shutdown handler won't break. So it's usually not a
   * problem but this is a good thing to fix.
   *
   * @var string
   */
  private $current_working_directory;

  /**
   * A list of the response times for each API call. This does not reflect the
   * response time for the entire request, nor does it include the time it
   * took for overhead like rate limit checking.
   *
   * @var array
   */
  private $response_times = array();

  /**
   * A list of the query counts for each API call. This does not reflect the
   * query count for the entire request, nor does it include the queries for
   * overhead like rate limit checking.
   *
   * @var array
   */
  private $response_query_counts = array();

  /**
   * A list of the query times for each API call. This does not reflect the
   * query time for the entire request, nor does it include the times for
   * overhead like rate limit checking.
   *
   * @var array
   */
  private $response_query_times = array();

  /**
   * This stores the currently executing API call. If that API call were to
   * fail, I need to know which one I was running in order to propery log the
   * error.
   *
   * @var array
   */
  private $current_api_call = null;

  /**
   * Database object.
   *
   * @var database
   */
  private $database;

  /**
   * Setting object.
   *
   * @var setting
   */
  private $setting;

  /**
   * The headers to output in the shutdown handler.
   *
   * @var array
   */
  private $headers;

  /**
   * Whether or not this is a custom response. If true, none of the Cora data
   * like 'success' and 'data' is returned; only the actual data from the
   * single API call is returned.
   *
   * @var bool
   */
  private $custom_response;

  /**
   * Extra information for errors. For example, the database class puts
   * additional information into this variable if the query fails. The
   * error_message remains the same but has this additional data to help the
   * developer (if debug is enabled).
   *
   * @var mixed
   */
  private $error_extra_info = null;

  /**
   * Save the request variables for use later on. If unset, they are defaulted
   * to null. Any of these values being null will throw an exception as soon
   * as you try to process the request. The reason that doesn't happen here is
   * so that I can store exactly what was sent to me for logging purposes.
   */
  private function __construct() {
    $this->start_timestamp = microtime(true);

    // See class variable documentation for reasoning.
    $this->current_working_directory = getcwd();
  }

  /**
   * Use this function to instantiate this class instead of calling new cora()
   * (which isn't allowed anyways). This is necessary so that the API class
   * can have access to Cora.
   *
   * @return cora A new cora object or the already created one.
   */
  public static function get_instance() {
    if(isset(self::$instance) === false) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /**
   * Execute the request. It is run through the rate limiter, checked for
   * errors, then processed. Requests sent after the rate limit is reached are
   * not logged.
   *
   * @param array $request Basically just $_REQUEST or a slight mashup of it
   * for batch requests.
   *
   * @throws \Exception If the rate limit threshhold is reached.
   * @throws \Exception If SSL is required but not used.
   * @throws \Exception If the API key is not provided.
   * @throws \Exception If the API key is invalid.
   * @throws \Exception If the session is expired.
   * @throws \Exception If a resource is not provided.
   * @throws \Exception If a method is not provided.
   * @throws \Exception If the requested method does not exist.
   */
  public function process_request($request) {
    // This is necessary in order for the shutdown handler/log function to have
    // access to this data, but it's not used anywhere else.
    $this->request = $request;

    // Setting class for getting settings. Anything that extends cora\api gets
    // this automatically.
    $this->setting = setting::get_instance();

    // Used to have this in the constructor, but the database uses this class
    // which causes a dependency loop in the constructors.
    $this->database = database::get_instance();

    // A couple quick error checks
    if($this->is_over_rate_limit() === true) {
      throw new \Exception('Rate limit reached.', 1005);
    }
    if($this->setting->get('force_ssl') === true && empty($_SERVER['HTTPS']) === true) {
      throw new \Exception('Request must be sent over HTTPS.', 1006);
    }

    // Make sure the API key that was sent is present and valid.
    if(isset($request['api_key']) === false) {
      throw new \Exception('API Key is required.', 1000);
    }
    $api_user_resource = new api_user();
    if($api_user_resource->is_valid_api_key($request['api_key']) === false) {
      throw new \Exception('Invalid API key.', 1003);
    }

    // Build a list of API calls.
    $this->build_api_call_list($request);

    // Check the API request for errors.
    $this->check_api_request_for_errors();

    // Set the default headers as a catch-all. Most API calls won't touch these,
    // but it is possible for them to override headers as desired.
    $this->set_default_headers();

    // Get this every time. It's only used for session API calls. Non-session
    // API calls don't bother with this.
    $api_session = api_session::get_instance();
    $session_is_valid = $api_session->touch();

    // Process each request.
    foreach($this->api_calls as $api_call) {
      // Store the currently running API call for tracking if an error occurs.
      $this->current_api_call = $api_call;

      // These are required before we can move on with any more processing or
      // error checking.
      if(isset($api_call['resource']) === false) {
        throw new \Exception('Resource is required.', 1001);
      }
      if(isset($api_call['method']) === false) {
        throw new \Exception('Method is required.', 1002);
      }

      // Sets $call_type to 'public' or 'private'
      $call_type = $this->get_api_call_type($api_call);

      // If the request requires a session, make sure it's valid.
      if($call_type === 'session') {
        if($session_is_valid === false) {
          throw new \Exception('API session is expired.', 1004);
        }
      }

      // If the resource doesn't exist, spl_autoload_register() will throw a
      // fatal error. The shutdown handler will "catch" it. It is not possible
      // to catch exceptions directly from the autoloader using try/catch.
      $resource_instance = new $api_call['resource']();

      // If the method doesn't exist
      if(method_exists($resource_instance, $api_call['method']) === false) {
        throw new \Exception('Method does not exist.', 1009);
      }

      $api_call_map = $this->setting->get('api_call_map');
      $arguments = $this->get_arguments(
        $api_call,
        $api_call_map[$call_type][$api_call['resource']][$api_call['method']]
      );

      // Process the request and save some statistics.
      $start_time = microtime(true);
      $start_query_count = $this->database->get_query_count();
      $start_query_time = $this->database->get_query_time();

      if(isset($api_call['alias']) === true) {
        $index = $api_call['alias'];
      }
      else {
        $index = count($this->response_data);
      }

      $this->response_data[$index] = call_user_func_array(
        array($resource_instance, $api_call['method']),
        $arguments
      );

      $this->response_times[$index] = (microtime(true) - $start_time);
      $this->response_query_counts[$index] = $this->database->get_query_count() - $start_query_count;
      $this->response_query_times[$index] = $this->database->get_query_time() - $start_query_time;
    }
  }

  /**
   * Build a list of API calls from the request. For a single request, it's
   * just the request. For batch requests, add each item in the batch
   * parameter to this array.
   *
   * @param array $request The original request.
   *
   * @throws \Exception If this is a batch request and the batch data is not
   * valid JSON
   * @throws \Exception If this is a batch request and it exceeds the maximum
   * number of api calls allowed in one batch.
   */
  private function build_api_call_list($request) {
    $this->api_calls = array();
    if(isset($request['batch']) === true) {
      $batch = json_decode($request['batch'], true);
      if($batch === null) {
        throw new \Exception('Batch is not valid JSON.', 1012);
      }
      $batch_limit = $this->setting->get('batch_limit');
      if($batch_limit !== null && count($batch) > $batch_limit) {
        throw new \Exception('Batch limit exceeded.', 1013);
      }
      foreach($batch as $api_call) {
        // Put this on each API call for logging.
        $api_call['api_key'] = $request['api_key'];
        $this->api_calls[] = $api_call;
      }
    }
    else {
      $this->api_calls[] = $request;
    }
  }

  /**
   * Check the API request for various errors.
   *
   * @throws \Exception If something other than ALL or NO aliases are set.
   * @throws \Exception If Any duplicate aliases are used.
   */
  private function check_api_request_for_errors() {
    $aliases = array();
    foreach($this->api_calls as $api_call) {
      if(isset($api_call['alias']) === true) {
        $aliases[] = $api_call['alias'];
      }
    }

    // Check to make sure either all or none are set.
    $number_aliases = count($aliases);
    if(count($this->api_calls) !== $number_aliases && $number_aliases !== 0) {
      throw new \Exception('All API calls must have an alias if at least one is set.', 1017);
    }

    // Check for duplicates.
    $number_unique_aliases = count(array_unique($aliases));
    if($number_aliases !== $number_unique_aliases) {
      throw new \Exception('Duplicate alias on API call.', 1018);
    }
  }

  /**
   * Returns 'session' or 'non_session' depending on where the API method is
   * located at. Session methods require a valid session in order to execute.
   *
   * @param array $api_call The API call to get the type for.
   *
   * @throws \Exception If the method was not found in the map.
   *
   * @return string The type.
   */
  private function get_api_call_type($api_call) {
    $map = $this->setting->get('api_call_map');

    if(isset($map['session'][$api_call['resource']][$api_call['method']]) === true) {
      return 'session';
    }
    else if(isset($map['non_session'][$api_call['resource']][$api_call['method']]) === true) {
      return 'non_session';
    }
    else {
      throw new \Exception('Requested method is not mapped.', 1008);
    }
  }

  /**
   * Check to see if the request from the current IP address needs to be rate
   * limited. If $requests_per_minute is null then there is no rate limiting.
   *
   * @return bool If this request puts us over the rate threshold.
   */
  private function is_over_rate_limit() {
    $requests_per_minute = $this->setting->get('requests_per_minute');
    if($requests_per_minute === null) {
      return false;
    }
    $api_log_resource = new api_log();
    $requests_this_minute = $api_log_resource->get_number_requests_since(
      $_SERVER['REMOTE_ADDR'],
      time() - 60
    );
    return ($requests_this_minute >= $requests_per_minute);
  }

  /**
   * Fetches a list of arguments when passed an array of keys. Since the
   * arguments are passed from JS to PHP in JSON, I don't need to cast any of
   * the values as the data types are preserved. Since the argument order from
   * the client doesn't matter, this makes sure that the arguments are placed
   * in the correct order for calling the function.
   *
   * @param array $api_call The
   * @param array $argument_keys The keys to get.
   *
   * @throws \Exception If the arguments in the api_call were not valid JSON.
   *
   * @return array The requested arguments.
   */
  private function get_arguments($api_call, $argument_keys) {
    $arguments = array();

    // Arguments are not strictly required. If a method requires them then you
    // will still get an error, but they are not required by the API.
    if(isset($api_call['arguments']) === true) {
      // All arguments are sent in the "arguments" key as JSON.
      $api_call_arguments = json_decode($api_call['arguments'], true);

      if($api_call_arguments === null) {
        throw new \Exception('Arguments are not valid JSON.', 1011);
      }

      foreach($argument_keys as $argument_key) {
        if(isset($api_call_arguments[$argument_key]) === true) {
          $argument = $api_call_arguments[$argument_key];

          // If this is a batch request, look for JSONPath arguments.
          if(isset($this->request['batch']) === true) {
            $argument = $this->evaluate_json_path_argument($argument);
          }

          $arguments[] = $argument;
        }
        else {
          // This is a bit confusing, but this is nice in that it allows for
          // default arguments. For example: let's say we have the following:
          //
          // api_call($one, $two = 'foo')
          //
          // If I don't specify $two in my arguments, I want the function to use
          // my default value. If I were to pass (1, null) to that function, I
          // would lose the default. Instead, as soon as I come across an argument
          // that wasn't provided, just return the current results.
          return $arguments;
        }
      }
    }
    return $arguments;
  }

  /**
   * Recursively check all values in an argument. If any of them are JSON
   * path, evaluate them.
   *
   * @param mixed $argument The argument to check.
   *
   * @return mixed The argument with the evaluated path.
   */
  private function evaluate_json_path_argument($argument) {
    if(is_array($argument) === true) {
      foreach($argument as $key => $value) {
        $argument[$key] = $this->evaluate_json_path_argument($value);
      }
    }
    else if(preg_match('/^{=(.*)}$/', $argument, $matches) === 1) {
      $json_path_resource = new json_path();
      $json_path = $matches[1];
      $argument = $json_path_resource->evaluate($this->response_data, $json_path);
    }
    return $argument;
  }

  /**
   * Sets error_extra_info.
   *
   * @param mixed $error_extra_info Whatever you want the extra info to be.
   */
  public function set_error_extra_info($error_extra_info) {
    $this->error_extra_info = $error_extra_info;
  }

  /**
   * Get error_extra_info.
   *
   * @return mixed
   */
  public function get_error_extra_info() {
    return $this->error_extra_info;
  }

  /**
   * Sets the headers that should be used for this API call. This is useful
   * for doing things like returning files from the API where the content-type
   * is no longer application/json. This replaces all headers; headers are not
   * outputted to the browser until all API calls have completed, so the last
   * call to this function will win.
   *
   * @param array $headers The headers to output.
   * @param bool $custom_response Whether or not to wrap the response with the
   * Cora data or just output the API call's return value.
   *
   * @throws \Exception If this is a batch request and a custom response was
   * requested.
   * @throws \Exception If this is a batch request and the content type was
   * altered from application/json
   * @throws \Exception If this is not a batch request and the content type
   * was altered from application/json without a custom response.
   */
  public function set_headers($headers, $custom_response = false) {
    if(isset($this->request['batch']) === true) {
      if($custom_response === true) {
        throw new \Exception('Batch API requests can not use a custom response.', 1015);
      }
      if($this->content_type_is_json($headers) === false) {
        throw new \Exception('Batch API requests must return JSON.', 1014);
      }
    }
    else {
      // Not a batch request
      if($custom_response === false && $this->content_type_is_json($headers) === false) {
        throw new \Exception('Non-custom responses must return JSON.', 1016);
      }
    }
    $this->headers = $headers;
    $this->custom_response = $custom_response;
  }

  /**
   * Return whether or not the current output headers indicate that the
   * content type is JSON. This is mostly just used to make sure that batch
   * API calls output JSON.
   *
   * @param array $headers The headers to look at.
   *
   * @return bool Whether or not the output has a content type of
   * application/json
   */
  private function content_type_is_json($headers) {
    return isset($headers['Content-type']) === true
      && stristr($headers['Content-type'], 'application/json') !== false;
  }

  /**
   * Override of the default PHP error handler. Grabs the error info and sends
   * it to the exception handler which returns a JSON response.
   *
   * @param int $error_code The error number from PHP.
   * @param string $error_message The error message.
   * @param string $error_file The file the error happend in.
   * @param int $error_line The line of the file the error happened on.
   *
   * @return string The JSON response with the error details.
   */
  public function error_handler($error_code, $error_message, $error_file, $error_line) {
    $this->set_error_response(
      $error_message,
      $error_code,
      $error_file,
      $error_line,
      debug_backtrace(false)
    );
    die(); // Do not continue execution; shutdown handler will now run.
  }

  /**
   * Override of the default PHP exception handler. All unhandled exceptions
   * go here.
   *
   * @param Exception $e The exception.
   */
  public function exception_handler($e) {
    $this->set_error_response(
      $e->getMessage(),
      $e->getCode(),
      $e->getFile(),
      $e->getLine(),
      $e->getTrace()
    );
    die(); // Do not continue execution; shutdown handler will now run.
  }

  /**
   * Handle all exceptions by generating a JSON response with the error
   * details. If debugging is enabled, a bunch of other information is sent
   * back to help out.
   *
   * @param string $error_message The error message.
   * @param mixed $error_code The supplied error code.
   * @param string $error_file The file the error happened in.
   * @param int $error_line The line of the file the error happened on.
   * @param array $error_trace The stack trace for the error.
   */
  public function set_error_response($error_message, $error_code, $error_file, $error_line, $error_trace) {
    // There are a few places that call this function to set an error response,
    // so this can't just be done in the exception handler alone. If an error
    // occurs, rollback the current transaction. Also only attempt to roll back
    // the transaction if the database was successfully created/connected to.
    if($this->database !== null) {
      $this->database->rollback_transaction();
    }

    $this->response = array(
      'success' => false,
      'data' => array(
        'error_message' => $error_message,
        'error_code' => $error_code,
        'error_file' => $error_file,
        'error_line' => $error_line,
        'error_trace' => $error_trace,
        'error_extra_info' => $this->error_extra_info
      )
    );
  }

  /**
   * Executes when the script finishes. If there was an error that somehow
   * didn't get caught, then this will find it with error_get_last and return
   * appropriately. Note that error_get_last() will only get something when an
   * error wasn't caught by my error/exception handlers. The default PHP error
   * handler fills this in. Doesn't do anything if an exception was thrown due
   * to the rate limit.
   *
   * @throws \Exception If a this was a batch request but one of the api calls
   * changed the content-type to anything but the default.
   */
  public function shutdown_handler() {
    // Since the shutdown handler is rather verbose in what it has to check for
    // and do, it's possible it will fail or detect an error that needs to be
    // handled. For example, someone could return binary data from an API call
    // which will fail a json_encode, or someone could change the headers in a
    // batch API call, which isn't allowed. I can't throw an exception since I'm
    // already in the shutdown handler...it will be caught but it won't execute
    // a new shutdown handler and no output will be sent to the client. I just
    // have to handle all problems manually.
    try {
      // Fix the current working directory. See documentation on this class
      // variable for details.
      chdir($this->current_working_directory);

      // If I didn't catch an error/exception with my handlers, look here...this
      // will catch fatal errors that I can't.
      $error = error_get_last();
      if($error !== null) {
        $this->set_error_response(
          $error['message'],
          $error['type'],
          $error['file'],
          $error['line'],
          debug_backtrace(false)
        );
      }

      // If the response has already been set by one of the error handlers, end
      // execution here and just log & output the response.
      if(isset($this->response) === true) {
        // Don't log anything for rate limit breaches.
        if($this->response['data']['error_code'] !== 1005) {
          $this->log();
        }

        // Override whatever headers might have already been set.
        $this->set_default_headers();
        $this->output_headers();
        die($this->get_json_response());
      }
      else {
        // If we got here, no errors have occurred.

        // For non-custom responses, build the response, log it, and output it.
        if($this->custom_response === false) {
          $this->response = array('success' => true);

          if(isset($this->request['batch']) === true) {
            $this->response['data'] = $this->response_data;
          }
          else {
            // $this->response['data'] = $this->response_data[0];
            $this->response['data'] = reset($this->response_data);
          }

          // Log all of the API calls that were made.
          $this->log();

          // Output the response
          $this->output_headers();
          die($this->get_json_response());
        }
        else {
          // For custom responses, just output whatever we got. Batch requests
          // can't get to this point since they are not allowed to be custom.
          // $this->response = $this->response_data[0];
          $this->response = reset($this->response_data);
          $this->log();

          $this->output_headers();
          die($this->response);
        }
      }
    }
    catch(\Exception $e) {
      $this->set_error_response(
        $e->getMessage(),
        $e->getCode(),
        $e->getFile(),
        $e->getLine(),
        $e->getTrace()
      );
      $this->set_default_headers();
      $this->output_headers();
      die($this->get_json_response());
    }
  }

  /**
   * Gets the json_encoded response. This is called from the shutdown handler
   * and removes debug information if debugging is disabled and then
   * json_encodes the data.
   *
   * @return string The JSON encoded response.
   */
  private function get_json_response() {
    $response = $this->response;
    if($this->setting->get('debug') === false && $response['success'] === false) {
      unset($response['data']['error_file']);
      unset($response['data']['error_line']);
      unset($response['data']['error_trace']);
      unset($response['data']['error_extra_info']);
    }
    return json_encode($response);
  }

  /**
   * Output whatever the headers are currently set to.
   */
  private function output_headers() {
    foreach($this->headers as $key => $value) {
      header($key . ': ' . $value);
    }
  }

  /**
   * Resets the headers to default. Have to do this in case one of the API
   * calls changes them and there was an error to handle.
   */
  private function set_default_headers() {
    $this->set_headers(
      array('Content-type' => 'application/json; charset=UTF-8'),
      false
    );
  }

  /**
   * Returns true for all loggable content types. Mostly JSON, XML, and other
   * text-based types.
   *
   * @return bool Whether or not the output has a content type that can be
   * logged.
   */
  private function content_type_is_loggable() {
    if(isset($this->headers['Content-type']) === false) {
      return false;
    }
    else {
      $loggable_content_types = array(
        'application/json',
        'application/xml',
        'application/javascript',
        'text/html',
        'text/xml',
        'text/plain',
        'text/css'
      );
      foreach($loggable_content_types as $loggable_content_type) {
        if(stristr($this->headers['Content-type'], $loggable_content_type) !== false) {
          return true;
        }
      }
    }
  }

  /**
   * Log the request and response to the database. The logged response is
   * truncated to 16kb for sanity.
   */
  private function log() {
    $api_log_resource = new api_log();

    // If exception. This is lenghty because I have to check to make sure
    // everything was set or else use null.
    if(isset($this->response['data']['error_code']) === true) {
      if(isset($this->request['api_key']) === true) {
        $request_api_key = $this->request['api_key'];
      }
      else {
        $request_api_key = null;
      }

      $request_resource = null;
      $request_method = null;
      $request_arguments = null;
      if($this->current_api_call !== null) {
        if(isset($this->current_api_call['resource']) === true) {
          $request_resource = $this->current_api_call['resource'];
        }
        if(isset($this->current_api_call['method']) === true) {
          $request_method = $this->current_api_call['method'];
        }
        if(isset($this->current_api_call['arguments']) === true) {
          $request_arguments = $this->current_api_call['arguments'];
        }
      }
      $response_error_code = $this->response['data']['error_code'];
      $response_time = null;
      $response_query_count = null;
      $response_query_time = null;
      $response_data = substr(json_encode($this->response['data']), 0, 16384);

      $api_log_resource->create(
        array(
          'request_api_key'       =>  $request_api_key,
          'request_resource'      =>  $request_resource,
          'request_method'        =>  $request_method,
          'request_arguments'     =>  preg_replace('/"(password)":".*"/', '"$1":"[removed]"', $request_arguments),
          'response_error_code'   =>  $response_error_code,
          'response_data'         =>  preg_replace('/"(password)":".*"/', '"$1":"[removed]"', $response_data),
          'response_time'         =>  $response_time,
          'response_query_count'  =>  $response_query_count,
          'response_query_time'   =>  $response_query_time
        )
      );
    }
    else {
      $response_error_code = null;
      $count_api_calls = count($this->api_calls);
      for($i = 0; $i < $count_api_calls; $i++) {
        $api_call = $this->api_calls[$i];
        $request_api_key = $api_call['api_key'];
        $request_resource = $api_call['resource'];
        $request_method = $api_call['method'];
        if(isset($api_call['arguments']) === true) {
          $request_arguments = $api_call['arguments'];
        }
        else {
          $request_arguments = null;
        }

        if(isset($api_call['alias']) === true) {
          $index = $api_call['alias'];
        }
        else {
          $index = $i;
        }

        $response_time = $this->response_times[$index];
        $response_query_count = $this->response_query_counts[$index];
        $response_query_time = $this->response_query_times[$index];

        // The data could be an integer, an XML string, an array, etc, but let's
        // just always json_encode it to keep things simple and standard.
        if($this->content_type_is_loggable() === true) {
          $response_data = substr(json_encode($this->response_data[$index]), 0, 16384);
        }
        else {
          $response_data = null;
        }

        $api_log_resource->create(
          array(
            'request_api_key'       =>  $request_api_key,
            'request_resource'      =>  $request_resource,
            'request_method'        =>  $request_method,
            'request_arguments'     =>  preg_replace('/"(password)":".*"/', '"$1":"[removed]"', $request_arguments),
            'response_error_code'   =>  $response_error_code,
            'response_data'         =>  preg_replace('/"(password)":".*"/', '"$1":"[removed]"', $response_data),
            'response_time'         =>  $response_time,
            'response_query_count'  =>  $response_query_count,
            'response_query_time'   =>  $response_query_time
          )
        );
      }
    }
  }

}
