<?php

namespace cora;

/**
 * Offers session-related functions.
 *
 * @author Jon Ziebell
 */
final class api_session {

  /**
   * The session_key for this session.
   *
   * @var string
   */
  private $session_key = null;

  /**
   * The external_id for this session.
   *
   * @var int
   */
  private $external_id = null;

  /**
   * The singleton.
   *
   * @var api_session
   */
  private static $instance;

  /**
   * Constructor
   */
  private function __construct() {}

  /**
   * Use this function to instantiate this class instead of calling new
   * api_session() (which isn't allowed anyways). This avoids confusion from
   * trying to use dependency injection by passing an instance of this class
   * around everywhere.
   *
   * @return api_session A new api_session object or the already created one.
   */
  public static function get_instance() {
    if(isset(self::$instance) === false) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /**
   * Return whether or not this class has been instantiated.
   *
   * @return bool
   */
  public static function has_instance() {
    return isset(self::$instance);
  }

  /**
   * Request a session. This method sets a couple cookies and returns the
   * session key. By default, all cookies except those set in
   * $additional_cookie_values are marked as httponly, which means only the
   * server can access them. Use the following table to determine when the
   * local cookie will be set to expire.
   *
   * timeout | life | expire
   * -------------------------------
   * null    | null | 2038
   * null    | set  | time() + life
   * set     | null | 0
   * set     | set  | time() + life
   *
   * @param int $timeout How long, in seconds, until the session expires due
   * to inactivity. Set to null for no timeout.
   * @param int $life How long, in seconds, until the session expires. Set to
   * null for no expiration.
   * @param int $external_id An optional external integer pointer to another
   * table. This will most often be user.user_id, but could be something like
   * person.person_id or player.player_id.
   * @param array $additional_cookie_values Set additional values in the
   * cookie by setting this value. Doing this is generally discouraged as
   * cookies add state to the application, but something like a username for a
   * "remember me" checkbox is reasonable.
   *
   * @return string The generated session key.
   */
  public function request($timeout, $life, $external_id = null, $additional_cookie_values = null) {
    $database = database::get_instance();
    $session_key = $this->generate_session_key();

    $session_key_escaped = $database->escape($session_key);
    $timeout_escaped = $database->escape($timeout);
    $life_escaped = $database->escape($life);
    $external_id_escaped = $database->escape($external_id);
    $created_by_escaped = $database->escape($_SERVER['REMOTE_ADDR']);
    $last_used_by_escaped = $created_by_escaped;

    $query = '
      insert into
        `api_session`(
          `session_key`,
          `timeout`,
          `life`,
          `external_id`,
          `created_by`,
          `last_used_by`,
          `last_used_at`
        )
      values(
        ' . $session_key_escaped . ',
        ' . $timeout_escaped . ',
        ' . $life_escaped . ',
        ' . $external_id_escaped . ',
        inet_aton(' . $created_by_escaped . '),
        inet_aton(' . $last_used_by_escaped . '),
        now()
      )
    ';
    $database->query($query);

    // Set the local cookie expiration.
    if($life !== null) {
      $expire = time() + $life;
    }
    else {
      if($timeout === null) {
        $expire = 4294967295; // 2038
      }
      else {
        $expire = 0; // Browser close
      }
    }

    // Set all of the necessary cookies. Both *_session_key and *_external_id are
    // read every API request and made available to the API.
    $this->set_cookie('session_key', $session_key, $expire);
    $this->set_cookie('external_id', $external_id, $expire);
    if(isset($additional_cookie_values) === true) {
      foreach($additional_cookie_values as $key => $value) {
        $this->set_cookie($key, $value, $expire, false);
      }
    }

    $this->session_key = $session_key;
    $this->external_id = $external_id;

    return $session_key;
  }

  /**
   * Similar to the Linux touch command, this method "touches" the session and
   * updates last_used_at and last_used_by. This is executed every time a
   * request that requires a session is sent to the API. Note that this uses the
   * cookie sent by the client directly so there is no default way to touch a
   * session unless you are the one logged in to it.
   *
   * @return bool True if it was successfully updated, false if the session does
   *     not exist or is expired. Basically, return bool whether or not the
   *     sesion is valid.
   */
  public function touch() {
    // Grab the cookie values. Note that if no session_key is available, this
    // method will search for a session with a null session key and end up
    // returning false. Class cora\cora will throw an exception for an expired
    // session in that case.
    if(isset($_COOKIE['session_key'])) {
      $session_key = $_COOKIE['session_key'];
    }
    else {
      $session_key = null;
    }

    if(isset($_COOKIE['external_id'])) {
      $external_id = $_COOKIE['external_id'];
    }
    else {
      $external_id = null;
    }

    $database = database::get_instance();
    $session_key_escaped = $database->escape($session_key);
    $last_used_by_escaped = $database->escape($_SERVER['REMOTE_ADDR']);

    $query = '
      update
        `api_session`
      set
        `last_used_at` = now(),
        `last_used_by` = inet_aton(' . $last_used_by_escaped . ')
      where
        `deleted` = 0 and
        `session_key` = ' . $session_key_escaped . ' and
        (
          `timeout` is null or
          `last_used_at` > date_sub(now(), interval `timeout` second)
        ) and
        (
          `life` is null or
          `created_at` > date_sub(now(), interval `life` second)
        )
    ';

    $database->query($query);

    // If there was one row updated, we're good. Otherwise we need to check the
    // info string to see if a row matched but just didn't need updating (if two
    // requests in the same second come through, the second won't update the
    // row). All this is to avoid executing an extra count query.
    if($database->affected_rows === 1) {
      $this->session_key = $session_key;
      $this->external_id = $external_id;
      return true;
    }
    else {
      preg_match_all('/Rows matched: (\d+)/', $database->info, $matches);
      if(isset($matches[1][0]) && $matches[1][0] === '1') {
        $this->session_key = $session_key;
        $this->external_id = $external_id;
        return true;
      }
      else {
        return false;
      }
    }
  }

  /**
   * Delete the session with the provided session_key. If no session_key is
   * provided, delete the current session. This function is provided to aid
   * session management. Call it with no parameters for something like
   * user->log_out(), or set $session_key to end a specific session. You would
   * typically want to have your own permission layer on top of that to enable
   * only admins to do that.
   *
   * @param string $session_key The session key of the session to delete.
   *
   * @return bool True if it was successfully deleted. Could return false for
   * a non-existent session key or if it was already deleted.
   */
  public function delete($session_key = null) {
    $database = database::get_instance();
    if($session_key === null) {
      $session_key = $this->session_key;
    }
    $session_key_escaped = $database->escape($session_key);
    $query = '
      update
        `api_session`
      set
        `deleted` = 1
      where
        `session_key` = ' . $session_key_escaped . '
    ';
    $database->query($query);
    return $database->affected_rows === 1;
  }

  /**
   * Get the external_id on this session. Useful for getting things like the
   * user_id for the currently logged in user.
   *
   * @return int The current external_id.
   */
  public function get_external_id() {
    return $this->external_id;
  }

  /**
   * Generate a random (enough) session key.
   *
   * @return string The generated session key.
   */
  private function generate_session_key() {
    return strtolower(sha1(uniqid(mt_rand(), true)));
  }

  /**
   * Sets a cookie. If you want to set custom cookies, use the
   * $additional_cookie_valeus argument on $session->create().
   *
   * @param string $name The name of the cookie.
   * @param mixed $value The value of the cookie.
   * @param int $expire When the cookie should expire.
   * @param bool $httponly True if the cookie should only be accessible on the
   * server.
   *
   * @throws \Exception If The cookie fails to set.
   *
   * @return null
   */
  private function set_cookie($name, $value, $expire, $httponly = true) {
    $this->setting = setting::get_instance();
    $path = '/'; // The current directory that the cookie is being set in.
    $secure = $this->setting->get('force_ssl');
    $domain = $this->setting->get('cookie_domain');
    if($domain === null) { // See setting documentation for more info.
      $domain = '';
    }

    $cookie_success = setcookie(
      $name,
      $value,
      $expire,
      $path,
      $domain,
      $secure,
      $httponly
    );

    if($cookie_success === false) {
      throw new \Exception('Failed to set cookie.', 1400);
    }
  }

}
