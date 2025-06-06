<?php

/**
 * manages user sessions for the plugin
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2013 xnau webdesign
 * @license    GPL2
 * @version    3.7
 * 
 * 
 */
if ( !defined( 'ABSPATH' ) )
  die;

class PDb_Session {

  /**
   * @var string name of the session id variable
   */
  const id_var = 'sess';

  /**
   * @var \PDb_submission\db_session the database session object
   */
  private $session_data;

  /**
   * @var \PDb_Session the current instance
   */
  private static $instance;

  /**
   * provides the class instance
   * 
   * @return \PDb_Session
   */
  public static function get_instance()
  {
    if ( is_null( self::$instance ) ) {
      self::$instance = new self();
    }

    return self::$instance;
  }
  
  /**
   * provides a URL with a session id
   * 
   * @param string $url the url base
   * @return string the url with the session id added
   */
  public static function session_url_var( $url )
  {
    $session = self::get_instance();
    
    return add_query_arg( array( self::id_var => $session->session_id() ), $url );
  }

  /**
   * construct the class
   * 
   */
  private function __construct()
  {
    $this->session_data = new \PDb_submission\db_session( $this->get_session_id() );

    if ( is_admin() && array_key_exists( 'pdb-clear_sessions', $_GET ) ) {
      PDb_submission\db_session::close_all();
    }
  }

  /**
   * get a session variable
   * 
   * @param string $key Session key
   * @param string|array|bool $default the value to return if none is found in the session
   * @return string|array session value or $default value
   */
  public function get( $key, $default = false )
  {
    $value = $this->session_data->get( sanitize_key( $key ) );

    return $value !== false ? $value : $default;
  }

  /**
   * provides the current session id
   * 
   * @return string
   */
  public function session_id()
  {
    return $this->session_data->session_id();
  }

  /**
   * supplies the current record ID if available
   * 
   * @param bool $pid_only if true, don't get the id from the 'pdb' URL var 
   * 
   * @return  int|bool the ID or bool false
   */
  public function record_id( $pid_only = false )
  {
    $pid_only = $pid_only ? : Participants_Db::plugin_setting_is_true('use_single_record_pid', false);
    
    if ( apply_filters( 'pdb-record_id_in_get_var', false ) )
    {
      $record_id = 0;
      if ( !$pid_only && array_key_exists( Participants_Db::$single_query, $_GET ) ) 
      {
        $record_id = filter_input( INPUT_GET, Participants_Db::$single_query, FILTER_SANITIZE_NUMBER_INT, FILTER_NULL_ON_FAILURE );
      } 
      elseif ( array_key_exists( Participants_Db::$record_query, $_GET ) ) 
      {
        $record_id = Participants_Db::get_participant_id( filter_input( INPUT_GET, Participants_Db::$record_query, FILTER_SANITIZE_SPECIAL_CHARS, FILTER_NULL_ON_FAILURE ) );
      }
      if ( $record_id ) 
      {
        return $record_id;
      }
    }
    return $this->get( 'pdbid' );
  }

  /**
   * get a session variable array
   * 
   * @param string $key Session key
   * @param string|array|bool $default the value to return if none is found in the session
   * @return array Session variable or $default value
   */
  public function getArray( $key, $default = false )
  {
    $array_object = $this->get( $key );

    if ( is_array( $array_object ) )
    {
      return $array_object;
    }

    if ( is_object( $array_object ) )
    {
      return $array_object->toArray();
    }

    return $default;
  }

  /**
   * Set a session variable
   *
   * @param $key Session key
   * @param $value Session variable
   * @return mixed Session variable
   */
  public function set( $key, $value )
  {
    $key = sanitize_key( $key );

    $this->session_data->save( $key, $value );

    return $this->get( $key );
  }

  /**
   * update a session variable
   * 
   * if the incoming value is an array, it is merged with the stored value if it 
   * is also an array; if not, it stores the value, overwriting the stored value
   *
   * @param $key Session key
   * @param $value Session variable
   * @return mixed Session variable
   */
  public function update( $key, $value )
  {
    $key = sanitize_key( $key );
    $stored = $this->getArray( $key );

    if ( is_array( $value ) && is_array( $stored ) ) {
      $value = self::deep_merge( $stored, $value );
    }

    $this->session_data->save( $key, $value );

    return $this->get( $key );
  }

  /**
   * clears a session variable
   * 
   * @param string $key the name of the variable to delete
   * @return null
   */
  public function clear( $key )
  {
    $this->session_data->clear( sanitize_key( $key ) );
  }

  /**
   * utility function to show all the stored data values
   * 
   * @return array
   */
  public function to_array()
  {
    return $this->session_data->to_array();
  }

  /**
   * get the user's session id
   * 
   * @retrun string
   */
  private function get_session_id()
  {
    $sessid = '';
    $source = '';

    if ( $this->alt_session_setting() )
    {
      $sessid = $this->get_alt_session_id();

      $source = ' using alt method';
    }

    if ( $sessid === '' )
    {
      $sessid = $this->get_php_session_id();

      $source = ' using php method';
    }

    // if we still don't have the session ID, switch to the alternate method
    if ( $sessid === '' ) 
    {
      $sessid = $this->use_alternate_method();

      $source = ' using fallback alt method';
    }

    if ( empty( $sessid ) ) 
    {
      $source = ' unable to get session id';
    }
    
    // don't log if doing a cron
    if ( ! ( defined('DOING_CRON') && DOING_CRON ) )
    {
      Participants_Db::debug_log( __METHOD__ . ' session: ' . $sessid . ' source: ' . $source, 4 );
    }

    return $sessid;
  }

  /**
   * provides the session ID from php sessions
   * 
   * @return string
   */
  private function get_php_session_id()
  {
    $started_here = false;

    if ( session_status() !== PHP_SESSION_ACTIVE ) 
    {
      if ( headers_sent() ) 
      {
        Participants_Db::debug_log( __METHOD__ . ' can\'t initiate session: headers already sent ', 4 );
      }
      else
      {
        session_start();
        $started_here = true;
      }
    }

    $sessid = session_id();
    
    if ( $started_here ) 
    {
      session_write_close();
      
      Participants_Db::debug_log( __METHOD__ . ' starting session ' . $sessid, 4 );
    }

    return $sessid;
  }
  
  
  /**
   * tries the alternate method for getting the session ID
   * 
   * @return string session id
   */
  private function use_alternate_method()
  {
    return $this->get_alt_session_id();
  }

  /**
   * tells if the alt session method is enabled in the settings
   * 
   * @return bool
   */
  public function alt_session_setting()
  {
    return boolval( PDb_Settings::get_setting_value('use_session_alternate_method') );
  }

  /**
   * gets the session ID from the post, get, or cookie
   * 
   * @return session id or bool false if not found
   */
  private function get_alt_session_id()
  {
    $sessid = false;
    $validator = array( 
        'options' => array(
            'regexp' => '/^[0-9a-zA-Z,-]{22,40}$/',
        ),
        'flags' => FILTER_NULL_ON_FAILURE,
    );
    $source = 'none';

    if ( array_key_exists( self::php_cookie_name(), $_POST ) )
    {
      $sessid = filter_input( INPUT_POST, self::php_cookie_name(), FILTER_VALIDATE_REGEXP, $validator );
      $source = 'POST';
    }

    if ( !$sessid && array_key_exists( self::id_var, $_POST ) ) 
    {
      $sessid = filter_input( INPUT_POST, self::id_var, FILTER_VALIDATE_REGEXP, $validator );
      $source = 'POST';
    }

    if ( !$sessid && array_key_exists( self::id_var, $_GET ) ) 
    {
      $sessid = filter_input( INPUT_GET, self::id_var, FILTER_VALIDATE_REGEXP, $validator );
      $source = 'GET';
    }

    if ( !$sessid )
    {
      $value = false;
      /**
       * @filter pdb-session_get_var_keys
       * @param array of get var keys to check
       * @return string
       */
      $get_var_keys = Participants_Db::apply_filters( 'session_get_var_keys', array( 'cm' ) );
      foreach ( $get_var_keys as $key ) {
        $value = filter_input( INPUT_GET, $key, FILTER_VALIDATE_REGEXP, $validator );
        if ( $value ) {
          $source = 'GET["' . $key . '"]';
          break;
        }
      }

      $sessid = $value;
    }

    // try the php cookie
    if ( !$sessid ) 
    {
      $sessid = filter_input( INPUT_COOKIE, self::php_cookie_name(), FILTER_VALIDATE_REGEXP, $validator );
      
      $source = 'php cookie';
    }

    if ( !$sessid )
    {
      // try our own cookie
      $sessid = filter_input( INPUT_COOKIE, $this->cookie_name(), FILTER_VALIDATE_REGEXP, $validator );
      $source = 'PDB cookie';
    }

    if ( !$sessid )
    {
      // now we have to create an id and use that
      $sessid = $this->create_id();
      $cookie_setting = PDb_Settings::get_setting_value( 'disable_session_cookie');
      
      if ( $cookie_setting != '1' && ! headers_sent() )
      { 
        // save it in our cookie
        setcookie( $this->cookie_name(), $sessid, 0, '/' );
        $source = 'create new PDB cookie';
      }
      else
      {
        $source = ' unable to set session cookie';
      }
    }
    
    if ( ! ( defined('DOING_CRON') && DOING_CRON ) )
    {
      Participants_Db::debug_log(__METHOD__.' got session id: '. $sessid . ' by method: '. $source, 4 );
    }

    return $sessid;
  }

  /**
   * provides the php session cookie name
   * 
   * @return string
   */
  public static function php_cookie_name()
  {
    $phpcookie = ini_get( 'session.name' );
    if ( empty( $phpcookie ) ) {
      $phpcookie = 'PHPSESSID';
    }

    return $phpcookie;
  }

  /**
   * provides the cookie name
   * 
   * @return string
   */
  private function cookie_name()
  {
    return 'pdb-' . self::id_var;
  }

  /**
   * makes up a session ID
   * 
   * if we can't get an ID from php sessions, make one up
   * this will only work if the alternate session method is enabled
   * 
   * @return string
   */
  private function create_id()
  {
    return session_create_id();
  }

  /**
   * merges two arrays recursively
   * 
   * returned array will include unmatched elements from both input arrays. If 
   * there is an element key match, the element from $b will be present in the 
   * return value
   * 
   * @param array $array1
   * @param array $array2
   * @return array
   */
  public static function deep_merge( Array $array1, Array $array2 )
  {
    $merged = $array1;

    foreach ( $array2 as $key => $value ) {
      if ( is_array( $value ) && isset( $merged[ $key ] ) && is_array( $merged[ $key ] ) ) {
        $merged[ $key ] = self::deep_merge( $merged[ $key ], $value );
      } else {
        $merged[ $key ] = $value;
      }
    }
    return $merged;
  }

  /**
   * truncate (clear and reset) the session table
   */
  public static function reset_session_table()
  {
    global $wpdb;

    // truncate the session table
    $wpdb->query( "TRUNCATE TABLE IF EXISTS {$wpdb->prefix}sm_sessions;" );
  }

  /**
   * deletes the session table on uninstall
   */
  public static function uninstall()
  {
    global $wpdb;

    // delete session table
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sm_sessions;" );

    delete_option( 'sm_session_db_version' );
  }

}
