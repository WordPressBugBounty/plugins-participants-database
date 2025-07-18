<?php

/*
 * this static class provides a set of utility functions used throughout the plugin
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2015 xnau webdesign
 * @license    GPL2
 * @version    1.15
 * @link       http://xnau.com/wordpress-plugins/
 */
defined( 'ABSPATH' ) || exit;

class PDb_Base {

  /**
   * set if a shortcode is called on a page
   * @var bool
   */
  public static $shortcode_present = false;

  /**
   * finds the WP installation root
   * 
   * this uses constants, so it's not filterable, but the constants (if customized) 
   * are defined in the config file, so should be accurate for a particular installation
   * 
   * this works by finding the common path to both ABSPATH and WP_CONTENT_DIR which 
   * we can assume is the base install path of WP, even if the WP application is in 
   * another directory and/or the content directory is in a different place
   * 
   * @return string
   */
  public static function app_base_path()
  {
    $content_path = explode( '/', WP_CONTENT_DIR );
    $wp_app_path = explode( '/', ABSPATH );
    $end = min( array( count( $content_path ), count( $wp_app_path ) ) );
    $i = 0;
    $common = array();
    while ( $content_path[ $i ] === $wp_app_path[ $i ] and $i < $end ) {
      $common[] = $content_path[ $i ];
      $i++;
    }
    /**
     * @filter pdb-app_base_path
     * @param string  the base application path as calculated by the function
     * @return string
     */
    return Participants_Db::apply_filters( 'app_base_path', trailingslashit( implode( '/', $common ) ) );
  }

  /**
   * finds the WP base URL
   * 
   * this can be different from the home url if wordpress is in a different directory (http://site.com/wordpress/)
   * 
   * this is to accomodate alternate setups
   * 
   * @return string
   */
  public static function app_base_url()
  {
    $scheme = parse_url( site_url(), PHP_URL_SCHEME ) . '://';
    $content_path = explode( '/', str_replace( $scheme, '', content_url() ) );
    $wp_app_path = explode( '/', str_replace( $scheme, '', site_url() ) );

    $end = min( array( count( $content_path ), count( $wp_app_path ) ) );
    $i = 0;
    $common = array();
    while ( $i < $end and $content_path[ $i ] === $wp_app_path[ $i ] ) {
      $common[] = $content_path[ $i ];
      $i++;
    }
    return $scheme . trailingslashit( implode( '/', $common ) );
  }

  /**
   * provides the asset include path
   * 
   * @param string $asset name of the asset file with its subdirectory
   * @return asset URL
   */
  public static function asset_url( $asset )
  {
    $basepath = Participants_Db::$plugin_path . '/participants-database/';

    $asset_filename = self::asset_filename( $asset );

    if ( !is_readable( trailingslashit( Participants_Db::$plugin_path ) . $asset_filename ) ) {
      $asset_filename = $asset; // revert to the original name
    }

    return plugins_url( $asset_filename, $basepath );
  }

  /**
   * adds the minify extension to an asset filename
   * 
   * @param string $asset
   * @return asset filename with the minify extension
   */
  protected static function asset_filename( $asset )
  {

    $info = pathinfo( $asset );

    $presuffix = self::use_minified_assets() ? '.min' : '';

    return ($info[ 'dirname' ] ? $info[ 'dirname' ] . DIRECTORY_SEPARATOR : '')
            . $info[ 'filename' ]
            . $presuffix . '.'
            . $info[ 'extension' ];
  }

  /**
   * tells if the minified assets should be used
   * 
   * @return bool true if the minified assets should be used
   */
  public static function use_minified_assets()
  {
    /**
     * @filter pdb-use_minified_assets
     * @param bool default: true if PDB_DEBUG not enabled
     * @return bool
     */
    return Participants_Db::apply_filters( 'use_minified_assets', !( defined( 'PDB_DEBUG' ) && PDB_DEBUG ) );
  }

  /**
   * provides a simplified way to add or update a participant record
   * 
   * 
   * @param array $post associative array of data to store
   * @param int $id the record id to update, creates new record if omitted
   * @param string $context optional label
   * @return  int the ID of the record added or updated
   */
  public static function write_participant( Array $post, $id = '', $context = '' )
  {
    $action = 'update';

    // if the ID isn't in the DB, the action is an insert
    if ( ( is_numeric( $id ) && !self::id_exists( $id ) ) || !is_numeric( $id ) ) {

      $action = 'insert';
      $id = false;
    }

    return Participants_Db::process_form( $post, $action, $id, array_keys( $post ), true, $context );
  }

  /**
   * prepares data for inline javascript
   * 
   * @param string $obj_name name of the js object to inline
   * @param array $obj_data array of property values for the object
   * @param string $context optional context identification string
   * @return string the js to inline
   */
  public static function inline_js_data( $obj_name, $obj_data, $context = false )
  {
    /**
     * @filter pdb-inline_js_data_{$object_name}_{$context}
     * @param array the data
     * @return array the filtered data
     */
    $filter_handle = 'inline_js_data_' . $obj_name . ($context ? '_' . $context : '');

    $data_array = Participants_Db::apply_filters( $filter_handle, $obj_data );

    return 'var ' . esc_js( $obj_name ) . '=' . json_encode( $data_array );
  }

  /**
   * tells if the ID is in the main DB
   * 
   * @global \wpdb $wpdb
   * @param int $id
   * @return bool true if the ID is found in the table
   */
  public static function id_exists( $id )
  {
    global $wpdb;

    return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Participants_Db::participants_table() . ' WHERE `id` = %d', $id ) );
  }

  /**
   * parses a list shortcode filter string into an array
   * 
   * this creates an array that makes it easy to manipulate and interact with the 
   * filter string. The returned array is of format:
   *    'fieldname' => array(
   *       'column' => 'fieldname',
   *       'operator' => '=', (<, >, =, !, ~)
   *       'search_term' => 'string',
   *       'relation' => '&', (optional)
   *       ),
   * 
   * @param string $filter the filter string
   * @return array the string parsed into an array of statement arrays
   */
  public static function parse_filter_string( $filter )
  {
    $return = array();
    $statements = preg_split( '/(&|\|)/', html_entity_decode( $filter ), null, PREG_SPLIT_DELIM_CAPTURE );
    foreach ( $statements as $s ) {
      $statement = self::_filter_statement( $s );
      if ( $statement )
        $return[] = $statement;
    }
    return $return;
  }

  /**
   * builds a filter string from an array of filter statement objects or arrays
   * 
   * @param array $filter_array
   */
  public static function build_filter_string( $filter_array )
  {
    $filter_string = '';
    foreach ( $filter_array as $statement ) {
      $filter_string .= $statement[ 'column' ] . $statement[ 'operator' ] . $statement[ 'search_term' ] . $statement[ 'relation' ];
    }
    return rtrim( $filter_string, '&|' );
  }

  /**
   * merges two filter statement arrays
   * 
   * if a given target field is present in both arrays, all statements for that 
   * field will be eliminated from the first array, and the statements from the 
   * second array will be used. All other elements in the second array will follow the elements from the first array
   * 
   * @param array $array1
   * @param array $array2 the overriding array
   * @return array the combined array
   */
  public static function merge_filter_arrays( $array1, $array2 )
  {
    $return = array();
    foreach ( $array1 as $statement ) {
      $index = self::search_array_column( $array2, $statement[ 'column' ] );
      if ( $index === false ) {
        $return[] = $statement;
      }
    }
    return array_merge( $return, $array2 );
  }

  /**
   * searches for a matching column in an array
   * 
   * this function searches for a matching term of a given key in the second dimension 
   * of the array and returns the index of the matching array
   * 
   * @param array $array the array to search
   * @param string $term the term to search for
   * @param string the key of the element to search in
   * @return mixed the int index of the matching array or bool false if no match
   */
  private static function search_array_column( $array, $term, $key = 'column' )
  {
    for ( $i = 0; $i < count( $array ); $i++ ) {
      if ( $array[ $i ][ $key ] == $term )
        return $i;
    }
    return false;
  }

  /**
   * supplies an object comprised of the components of a filter statement
   * 
   * @param type $statement
   * @return array
   */
  private static function _filter_statement( $statement, $relation = '&' )
  {

    $operator = preg_match( '#^([^\2]+)(\>|\<|=|!|~)(.*)$#', $statement, $matches );

    if ( $operator === 0 )
      return false; // no valid operator; skip to the next statement

    list( $string, $column, $operator, $search_term ) = $matches;

    $return = array();

    // get the parts
    $return = compact( 'column', 'operator', 'search_term' );

    $return[ 'relation' ] = $relation;

    return $return;
  }

  /**
   * supplies a list of participant record ids
   * 
   * @param array $config with structure:
   *                    filter      a shortcode filter string
   *                    orderby     a comma-separated list of fields
   *                    order       a comma-separated list of sort directions, correlates 
   *                                to the $sort_fields argument
   * 
   * @return array of data arrays as $name => $value
   */
  public static function get_id_list( $config )
  {
    return self::get_list( $config, array( 'id' ) );
  }

  /**
   * supplies a list of participant data arrays
   * 
   * this provides only raw data from the database
   * 
   * @param array $config with structure:
   *                    filter      a shortcode filter string
   *                    orderby     a comma-separated list of fields
   *                    order       a comma-separated list of sort directions, correlates 
   *                                to the $sort_fields argument
   *                    fields      a comma-separated list of fields to get
   * 
   * @return array of data arrays as $name => $value
   */
  public static function get_participant_list( $config )
  {
    if ( !isset( $config[ 'fields' ] ) ) {
      // get all column names
      $columns = array_keys( self::field_defs() );
    } else {
      $columns = explode( ',', str_replace( ' ', '', $config[ 'fields' ] ) );
      if ( array_search( 'id', $columns ) ) {
        unset( $columns[ array_search( 'id', $columns ) ] );
      }
      $columns = array_merge( array( 'id' ), $columns );
    }

    return self::get_list( $config, $columns );
  }

  /**
   * supplies a list of all signup shortcodes
   * 
   * @return array of string shortcode tags
   */
  public static function signup_shortcode_tags()
  {
    return \Participants_Db::apply_filters( 'signup_shortcodes', array( 'pdb_signup' ) );
  }

  /**
   * provides an array of field definitions from main groups only
   * 
   * @global wpdb $wpdb
   * @return array of PDb_Form_Field_Def objects
   */
  public static function field_defs()
  {
    $cachekey = 'pdb_field_def_array';
    $fieldlist = wp_cache_get( $cachekey );

    if ( !$fieldlist ) {

      $fieldlist = array();

      $main_modes = array_keys( PDb_Manage_Fields::group_display_modes() );

      $result = array_filter( Participants_Db::all_field_defs(), function ( $field_def ) use ( $main_modes ) {
        return in_array( $field_def->mode, $main_modes );
      } );

      foreach ( $result as $column ) {
        $fieldlist[ $column->name ] = new PDb_Form_Field_Def( $column->name );
      }

      wp_cache_set( $cachekey, $fieldlist, '', self::cache_expire() );
    }

    return $fieldlist;
  }

  /**
   * update a dynamic db field value
   * 
   * this will process an update for all dynamic db fields for a record or series of records
   * 
   * if a fieldname is specified, the update will only be applied to that field
   * 
   * @param int|array $record_id the id of the record or list of record ids to process
   * @param string $fieldname the name of a dynamic db type field
   * @return int number of records updated
   */
  public static function dynamic_db_field_update_record_value( $record_id = [], $fieldname = '' )
  {
    $id_list = is_array( $record_id ) ? $record_id : [ $record_id ];

    if ( empty( $id_list ) ) {
      $id_list = self::get_id_list( [] ); // gets all record ids
    }

    if ( $fieldname !== '' ) {
      self::add_dynamic_db_field_filter( $fieldname );
    }

    $tally = 0;

    foreach ( $id_list as $id ) {
      $record = Participants_Db::get_participant( $id );

      if ( !is_array( $record ) ) {
        continue;
      }

      // update the dynamic values in the record
      $updated_record = apply_filters( 'pdb-dynamic_db_field_update', $record );

      // filter out any values that did not change
      $post = array_diff_assoc( $updated_record, $record );

      if ( !empty( $post ) ) {
        Participants_Db::write_participant( $post, $record[ 'id' ], 'dynamic db field update' );

        $tally++;
      }
    }

    return $tally;
  }

  /**
   * sets up a filter for singling out a field for a dynamic db field record update
   * 
   * @param string $fieldname name of the field
   * 
   */
  private static function add_dynamic_db_field_filter( $fieldname )
  {
    $field = PDb_Field_Item::is_field( $fieldname ) ? Participants_Db::$fields[ $fieldname ] : false;

    if ( !$field ) {
      return;
    }

    add_filter( 'pdb-dynamic_field_type_list', function ( $list ) use ( $fieldname ) {
      return array_filter( $list, function ( $field ) use ( $fieldname ) {
/** @var PDb_Form_Field_Def $field */
return $field->name() === $fieldname;
} );
    } );
  }

  /**
   * provides a list of fields that have columns in the main db
   * 
   * @return array of field names
   */
  public static function db_field_list()
  {
    $cachekey = 'pdb-db-field-list';

    $field_list = wp_cache_get( $cachekey );

    if ( is_array( $field_list ) ) {
      return $field_list;
    }
    
    $field_list = [];

    foreach ( self::field_defs() as $fieldname => $field ) {
      if ( $field->stores_data() ) {
        $field_list[] = $fieldname;
      }
    }

    wp_cache_set( $cachekey, $field_list, '', Participants_Db::cache_expire() );

    return $field_list;
  }

  /**
   * provides the name of the main database table
   * 
   * @return string
   */
  public static function participants_table()
  {
    return Participants_Db::apply_filters( 'participants_table', Participants_Db::$participants_table );
  }

  /**
   * supplies a list of PDB record data given a configuration object
   * 
   * @global wpdb $wpdb
   * @param array $config with structure:
   *                    filter      a shortcode filter string
   *                    orderby     a comma-separated list of fields
   *                    order       a comma-separated list of sort directions, correlates 
   *                                to the $sort_fields argument
   * @param array $columns optional list of field names to include in the results
   * 
   * @return array of record values (if single column) or id-indexed array of data objects
   */
  private static function get_list( $config, Array $columns )
  {
    $shortcode_defaults = array(
        'sort' => 'false',
        'search' => 'false',
        'list_limit' => '-1',
        'filter' => '',
        'orderby' => Participants_Db::plugin_setting( 'list_default_sort' ),
        'order' => Participants_Db::plugin_setting( 'list_default_sort_order' ),
        'suppress' => false,
        'module' => 'API',
        'fields' => implode( ',', $columns ),
        'instance_index' => '1',
    );
    $shortcode_atts = shortcode_atts( $shortcode_defaults, $config );

    $list = new PDb_List( $shortcode_atts );
    $list_query = new PDb_List_Query( $list );

    global $wpdb;
    if ( count( $list->display_columns ) === 1 ) {
      $result = $wpdb->get_col( $list_query->get_list_query() );
    } else {
      $result = $wpdb->get_results( $list_query->get_list_query(), OBJECT_K );
    }

    return $result;
  }

  /**
   * determines if an incoming set of data matches an existing record
   * 
   * @param array|string  $columns    column name, comma-separated series, or array 
   *                                  of column names to check for matching data
   * @param array         $submission the incoming data to test: name => value 
   *                                  (could be an unsanitized POST array)
   * 
   * @return int|bool record ID if the incoming data matches an existing record, 
   *                  bool false if no match
   */
  public static function find_record_match( $columns, $submission )
  {
    $matched_id = self::record_match_id( $columns, $submission );
    /**
     * @version 1.6
     * 
     * filter pdb-find_record_match
     * 
     * a callback on the filter can easily use the PDb_Base::record_match_id() 
     * method to find a match
     * 
     * @param int|bool  $matched_id the id found using the standard method, bool 
     *                              false if no match was found
     * @param string    $columns column name or names used to find the match
     * @param array     $submission the un-sanitized $_POST array
     * 
     * @return int|bool the found record ID
     */
    return self::apply_filters( 'find_record_match', $matched_id, $columns, $submission );
  }

  /**
   * determines if an incoming set of data matches an existing record
   * 
   * @param array|string  $columns    column name, comma-separated series, or array 
   *                                  of column names to check for matching data
   * @param array         $submission the incoming data to test: name => value 
   *                                  (could be an unsanitized POST array)
   * @global object $wpdb
   * @return int|bool record ID if the incoming data matches an existing record, 
   *                  bool false if no match
   */
  public static function record_match_id( $columns, $submission )
  {
    global $wpdb;
    $values = [];
    $where = [];
    
    $columns = is_array( $columns ) ? $columns : explode( ',', str_replace( ' ', '', $columns ) );
    
    foreach ( $columns as $column ) 
    {
      if ( isset( $submission[ $column ] ) ) 
      {
        $values[] = $submission[ $column ];
        $where[] = ' r.' . $column . ' = %s';
      } 
      else 
      {
        $where[] = ' (r.' . $column . ' IS NULL OR r.' . $column . ' = "")';
      }
    }
    
    $sql = 'SELECT r.id FROM ' . Participants_Db::$participants_table . ' r WHERE ' . implode( ' AND ', $where );
    
    $match = $wpdb->get_var( $wpdb->prepare( $sql, $values ) );

    return is_numeric( $match ) ? (int) $match : false;
  }

  /**
   * provides a permalink given a page name, path or ID
   * 
   * this allows a permalink to be found for a page name, relative path or post ID. 
   * If an absolute path is provided, the path is returned unchanged.
   * 
   * @param string|int $page the term indicating the page to get a permalink for
   * @global wpdb $wpdb
   * @return string|bool the permalink or false if it fails
   */
  public static function find_permalink( $page )
  {
    $permalink = false;
    $id = false;
    
    if ( filter_var( $page, FILTER_VALIDATE_URL ) )
    {
      $permalink = $page;
    } 
    elseif ( preg_match( '#^[0-9]+$#', $page ) ) 
    {
      $id = $page;
    } 
    elseif ( $post = get_page_by_path( $page ) ) 
    {
      $id = $post->ID;
    } 
    else 
    {
      // get the ID by the post slug
      global $wpdb;
      $id = $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM $wpdb->posts p WHERE p.post_name = '%s' AND p.post_status = 'publish'", trim( $page, '/ ' ) ) );
    }
    
    if ( $id )
    {
      $permalink = self::get_permalink( $id );
    }
    
    return $permalink;
  }

  /**
   * provides the permalink for a WP page or post given the ID
   * 
   * this implements a filter to allow a multilingual plugin to alter the ID
   * 
   * @param int $id the post ID
   * @return string the permalink
   */
  public static function get_permalink( $id )
  {
    /**
     * allow a multilingual plugin to set the language post id
     * 
     * @filter pdb-lang_page_id
     * @param int the post ID
     * @return int the language page ID
     */
    return get_permalink( Participants_Db::apply_filters( 'lang_page_id', $id ) );
  }

  /**
   * provides the basic string sanitize flags for a php filter function
   * 
   * this is intended to be used with FILTER_DEFAULT as the primary filter
   * 
   * @param string $flags additional flags to add
   * @return array
   */
  public static function string_sanitize( $flags = FILTER_FLAG_NONE )
  {
    return [ 'flags' => FILTER_FLAG_STRIP_BACKTICK | FILTER_FLAG_ENCODE_LOW | $flags ];
  }

  /**
   * provides the allowed HTML array for different contexts
   * 
   * @param string $type for now, will be either "post" or "form"
   * @return array
   */
  public static function allowed_html( $type )
  {
    $cachekey = 'pdb-allowed-html';

    $all_allowed = wp_cache_get( $cachekey, $type );

    if ( !$all_allowed ) {
      $base_attributes = [
          'id' => 1,
          'class' => 1,
          'style' => 1,
          'data-*' => 1,
      ];

      if ( Participants_Db::plugin_setting_is_true( 'allow_js_atts', false ) ) {
        $base_attributes = $base_attributes + self::js_attributes();
      }

      $allowed = [
          'a' => [
            'href' => 1,
            'title' => 1,
            'target' => 1,
            'rel' => 1,
          ] + $base_attributes,
          'break' => [],
          'br' => [],
          'style' => [],
      ];
      $additional = [];

      $form_allowed = [
          'form' => [
            'method' => 1,
            'enctype' => 1,
            'action' => 1,
          ] + $base_attributes,
          'input' => [
            'name' => 1,
            'type' => 1,
            'value' => 1,
            'title' => 1,
            'checked' => 1,
            'size' => 1,
            'max' => 1,
            'maxlength' => 1,
            'min' => 1,
            'minlength' => 1,
            'alt' => 1,
            'accept' => 1,
            'step' => 1,
            'disabled' => 1,
            'pattern' => 1,
            'placeholder' => 1,
            'readonly' => 1,
            'required' => 1,
          ] + $base_attributes,
          'select' => [
            'name' => 1,
            'multiple' => 1,
            'disabled' => 1,
            'required' => 1,
          ] + $base_attributes,
          'option' => [
            'value' => 1,
            'selected' => 1,
            'disabled' => 1,
            'label' => 1,
          ] + $base_attributes,
          'textarea' => [
            'name' => 1,
            'rows' => 1,
            'cols' => 1,
            'title' => 1,
            'maxlength' => 1,
            'minlength' => 1,
            'placeholder' => 1,
            'readonly' => 1,
            'required' => 1,
          ] + $base_attributes,
          'optgroup' => [
              'label' => 1,
              'disabled' => 1,
          ],
          'label' => [
            'title' => 1,
              'for' => 1,
          ] + $base_attributes,
          'fieldset' => [
            'disabled' => 1,
            'form' => 1,
            'name' => 1,
          ] + $base_attributes,
          'output' => [
              'for' => 1,
              'form' => 1,
              'name' => 1,
          ],
      ];

      switch ( $type ) {
        case 'form':
          $additional = $form_allowed;
          break;
      }

      $wp_allowed_post = wp_kses_allowed_html( 'post' );

      $all_allowed = $allowed + $additional + $wp_allowed_post;

      wp_cache_set( $cachekey, $all_allowed, $type, self::cache_expire() );
    }

    /**
     * @filter pdb-allowed_html_post
     * @filter pdb-allowed_html_form
     * 
     * @param array allowed config
     * @return array
     */
    return Participants_Db::apply_filters( 'allowed_html_' . $type, $all_allowed );
  }
  
  
  
  /**
   * tells if the current field allows HTML tags
   * 
   * @param string $fieldname
   * @return bool
   */
  public static function field_html_is_allowed( $fieldname )
  {
    $field = PDb_Form_Field_Def::instance($fieldname);
    
    if ( ! is_a( $field, '\PDb_Form_Field_Def' ) || ! in_array( $field->form_element(), ['text-line','text-area'] ) )
    {
      return true;
    }
    
    $allow = \Participants_Db::plugin_setting_is_true('allow_html', 1);
    
    if ( isset( $field->attributes['allow_html'] ) )
    {
      $allow = ! in_array( $field->attributes['allow_html'], ['no',0,'0','false'] );
    }
    
    return $allow;
  }

  /**
   * provides the list of allowed js action attributes
   * 
   * @return array
   */
  private static function js_attributes()
  {
    return array(
        'onblur' => 1,
        'onchange' => 1,
        'oncontextmenu' => 1,
        'onfocus' => 1,
        'oninput' => 1,
        'oninvalid' => 1,
        'onreset' => 1,
        'onsearch' => 1,
        'onselect' => 1,
        'onsubmit' => 1,
        'onkeydown' => 1,
        'onkeypress' => 1,
        'onkeyup' => 1,
        'onclick' => 1,
        'ondblclick' => 1,
        'onmousedown' => 1,
        'onmousemove' => 1,
        'onmouseout' => 1,
        'onmouseover' => 1,
        'onmouseup' => 1,
        'onwheel' => 1,
    );
  }

  /**
   * supplies the current participant ID
   * 
   * there are several possibilities (depending on the context) for the location 
   * of this information, we need to check each one
   * 
   * @param string $id the id (if known)
   * @return string the ID, empty if not determined
   */
  public static function get_record_id( $id = '' )
  {
    if ( empty( $id ) && !Participants_Db::plugin_setting_is_true( 'use_single_record_pid', false ) ) {
      // this is for backward compatibility
      $id = filter_input( INPUT_GET, Participants_Db::$single_query, FILTER_SANITIZE_NUMBER_INT );
    }
    if ( empty( $id ) ) {
      $id = Participants_Db::get_participant_id( filter_input( INPUT_GET, Participants_Db::$record_query, FILTER_SANITIZE_SPECIAL_CHARS ) );
    }
    if ( empty( $id ) && is_admin() ) {
      $id = filter_input( INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT );
    }
    if ( empty( $id ) ) {
      $id = Participants_Db::get_participant_id( get_query_var( 'pdb-record-edit-slug', false ) );
    }
    if ( empty( $id ) ) {
      $id = Participants_Db::get_record_id_by_term( 'record_slug', get_query_var( 'pdb-record-slug', 0 ) );
    }
    if ( empty( $id ) ) {
      $id = Participants_Db::$session->get( 'pdbid' );
    }
    return $id;
  }

  /**
   * tells if the private ID is in the URL
   * 
   * this is primarily to detect if a pretty permalink was used to access the record, 
   * a lot easier to do it here, than in the pretty permalinks plugin
   * 
   * @param int $record_id
   * @return bool true if the record was accessed with a URL containing the private ID
   */
  public static function pid_in_url( $record_id )
  {
    $url = $_SERVER[ 'REQUEST_URI' ];
    $record = Participants_Db::get_participant( $record_id );

    return is_array( $record ) && strpos( $url, $record[ 'private_id' ] ) !== false;
  }

  /**
   * determines if the field is the designated single record field
   * 
   * also checks that the single record page has been defined
   * 
   * @param object $field
   * @return bool
   */
  public static function is_single_record_link( $field )
  {
    $name = is_object( $field ) ? $field->name : $field;
    $page = Participants_Db::single_record_page();
    /**
     * @filter pdb-single_record_link_field
     * @param array the defined single record link field name
     * @return array of fieldnames
     */
    return !empty( $page ) && in_array( $name, self::apply_filters( 'single_record_link_field', (array) Participants_Db::plugin_setting( 'single_record_link_field' ) ) );
  }

  /*
   * prepares an array for storage in the database
   *
   * @param array $array
   * @return string prepped array in serialized form or empty if no data
   */

  public static function _prepare_array_mysql( $array )
  {

    if ( !is_array( $array ) )
      return Participants_Db::_prepare_string_mysql( $array );

    $prepped_array = array();

    $empty = true;

    foreach ( $array as $key => $value ) {

      if ( $value !== '' )
        $empty = false;
      $prepped_array[ $key ] = Participants_Db::_prepare_string_mysql( (string) $value );
    }

    return $empty ? '' : serialize( $prepped_array );
  }

  /**
   * prepares a string for storage
   * 
   * @param string $string the string to prepare
   */
  public static function _prepare_string_mysql( $string )
  {
    return stripslashes( $string );
  }

  /**
   * provides a rich text editor element ID
   * 
   * there are special rules in the wp_editor function for the $editor_id parameter
   * 
   * @param string $name usually the field name
   * @return string
   */
  public static function rich_text_editor_id( $name )
  {
    $texnum = array(
        '0' => 'zero', '1' => 'one', '2' => 'two', '3' => 'three', '4' => 'four', '5' => 'five', '6' => 'six', '7' => 'seven', '8' => 'eight', '9' => 'nine'
    );
    $text_numbered = preg_replace_callback( '/[0-9]/', function ( $d ) use ( $texnum ) {
      return '_' . $texnum[ intval( current( $d ) ) ];
    }, strtolower( Participants_Db::$prefix . $name . Participants_Db::$instance_index ) );

    return preg_replace( array( '#-#', '#[^a-z_]#' ), array( '_', '' ), $text_numbered );
  }

  /**
   * tells if a database value is set
   * 
   * this is mainly used as a callback for an array_filter function
   * 
   * @param string|null $v the raw value from the db
   * @return bool true if the value is set 
   */
  public static function is_set_value( $v )
  {
    return !is_null( $v ) && strlen( $v ) > 0;
  }

  /**
   * provides an array, safely unserializing if necessary
   * 
   * @param string $serialization the string to unserialize
   * @param bool $return_array optionally don't convert the output to an array
   * @return array|mixed
   */
  public static function unserialize_array( $serialization, $return_array = true )
  {
    if ( ! self::is_serialized_array( $serialization ) ) // make sure it is a serialized array with no objects
    {
      return $return_array ? (array) $serialization : $serialization;
    }
    
    // this is to fix serializations that come in with single quotes enclosing the values
    if ( preg_match( "/(:'.+';)/", $serialization ) === 1 )
    {
      $serialization = str_replace( [":'","';"], [':"','";'], $serialization );
    }
    
    return maybe_unserialize( $serialization );
  }
  
  /**
   * verifies that a string is a serialized array
   * 
   * also makes sure there are no objects in the serialization
   * 
   * @param string $string
   * @return bool
   */
  public static function is_serialized_array( $string )
  {
    return is_string( $string ) && preg_match( '/^a:\d/', $string ) == 1 && preg_match( '/O:\d/', $string ) == 0;
  }

  /**
   * adds the URL conjunction to a GET string
   *
   * @param string $URI the URI to which a get string is to be added
   *
   * @return string the URL with the conjunction character appended
   */
  public static function add_uri_conjunction( $URI )
  {

    return $URI . ( false !== strpos( $URI, '?' ) ? '&' : '?');
  }

  /**
   * returns a path to the defined image location
   *
   * this func is superceded by the PDb_Image class methods
   *
   * can also deal with a path saved before 1.3.2 which included the whole path
   *
   * @return the file url if valid; if the file can't be found returns the
   *         supplied filename
   */
  public static function get_image_uri( $filename )
  {

    if ( !file_exists( $filename ) ) {

      $filename = self::files_uri() . basename( $filename );
    }

    return $filename;
  }

  /**
   * tests a filename for allowed file extentions
   * 
   * @param string  $filename the filename to test
   * @param array $allowed_extensions array of local allowed file extensions
   * 
   * @return bool true if the extension is allowed
   */
  public static function is_allowed_file_extension( $filename, $allowed_extensions = array() )
  {
    $extensions = empty( $allowed_extensions ) || !is_array( $allowed_extensions ) ? self::global_allowed_extensions() : $allowed_extensions;

    if ( empty( $extensions ) ) {
      // nothing in the whitelist, don't allow
      return false;
    }

    $result = preg_match( '#^(.+)\.(' . implode( '|', $extensions ) . ')$#', strtolower( $filename ), $matches );

    return $result == 1;
  }

  /**
   * provides a list of globally allowed file extensions
   * 
   * @return array
   */
  public static function global_allowed_extensions()
  {
    $global_setting = Participants_Db::plugin_setting_value( 'allowed_file_types' );

    return explode( ',', str_replace( array( '.', ' ' ), '', strtolower( $global_setting ) ) );
  }

  /**
   * provides an array of allowed extensions from the field def "values" parameter
   * 
   * deprecated, get this from the PDb_Form_Field_Def instance
   * 
   * @param string|array $values possibly serialized array of field attributes or allowed extensions
   * @return string comma-separated list of allowed extensions, empty string if not defined in the field
   */
  public static function get_field_allowed_extensions( $values )
  {
    $value_list = array_filter( self::unserialize_array( $values ) );

    foreach ( array( 'rel', 'download', 'target', 'type' ) as $att ) {
      if ( array_key_exists( $att, $value_list ) ) {
        unset( $value_list[ $att ] );
      }
    }

    // if the allowed attribute is used, return its values
    if ( array_key_exists( 'allowed', $value_list ) ) {
      return str_replace( '|', ',', $value_list[ 'allowed' ] );
    }

    return implode( ',', $value_list );
  }

  /**
   * parses the value string and obtains the corresponding dynamic value
   *
   * the object property pattern is 'object->property' (for example 'curent_user->name'),
   * and the presence of the  '->'string identifies it.
   * 
   * the superglobal pattern is 'global_label:value_name' (for example 'SERVER:HTTP_HOST')
   *  and the presence of the ':' identifies it.
   *
   * if there is no indicator, the field is treated as a constant
   *
   * @param string $value the current value of the field as read from the
   *                      database or in the $_POST array
   *
   */
  public static function get_dynamic_value( $value )
  {
    // this value serves as a key for the dynamic value to get
    $dynamic_key = html_entity_decode( $value );

    /**
     * @filter pdb-dynamic_value
     * 
     * @param string the initial result; empty string
     * @param string the dynamic value key
     * @return the computed dynamic value
     */
    $dynamic_value = Participants_Db::apply_filters( 'dynamic_value', '', $dynamic_key );

    // return the value if it was set in the filter
    if ( $dynamic_value !== '' ) {
      return $dynamic_value;
    }

    if ( strpos( $dynamic_key, '->' ) > 0 ) {
      /*
       * here, we can get values from one of several WP objects
       * 
       * these will be: $post, $current_user
       */
      global $post, $current_user;

      list( $object, $property ) = explode( '->', $dynamic_key );

      $shortcode = \PDb_shortcodes\attributes::page_shortcode_attributes( 'pdb_signup' );

      $object = ltrim( $object, '$' );

      if ( is_object( $$object ) && !empty( $$object->$property ) ) {
        $dynamic_value = $$object->$property;
      } elseif ( $object === 'current_user' && $property === 'locale' ) {
        $dynamic_value = get_locale();
      } elseif ( $object = 'shortcode' ) {
        static $shortcode_index = 0;

        if ( isset( $shortcode[ $shortcode_index ] ) ) {
          $transient = 'pdb-shortcode_assigns';
          $assigns = get_transient( $transient );
          if ( $assigns === false ) {
            $assigns = $shortcode[ $shortcode_index ];
          }
          if ( isset( $shortcode[ $shortcode_index ][ $property ] ) ) {
            $dynamic_value = $shortcode[ $shortcode_index ][ $property ];
            unset( $assigns[ $property ] );
            if ( count( $assigns ) == 0 ) {
              $shortcode_index++;
              delete_transient( $transient );
            } else {
              set_transient( $transient, $assigns, 20 );
            }
          }
        }
      }
    } elseif ( strpos( $dynamic_key, ':' ) > 0 ) {
      /*
       * here, we are attempting to access a value from a PHP superglobal
       */

      list( $variable, $name ) = explode( ':', $dynamic_key );

      /*
       * if the value refers to an array element by including [index_name] or 
       * ['index_name'] we extract the indices
       */
      $indexes = array();
      if ( strpos( $name, '[' ) !== false ) {
        $count = preg_match( "#^([^]]+)(?:\['?([^]']+)'?\])?(?:\['?([^]']+)'?\])?$#", stripslashes( $name ), $matches );
        $match = array_shift( $matches ); // discarded
        $name = array_shift( $matches );
        $indexes = count( $matches ) > 0 ? $matches : array();
      }

      // clean this up in case someone puts $_SERVER instead of just SERVER
      $variable = preg_replace( '#^[$_]{1,2}#', '', $variable );

      /*
       * for some reason getting the superglobal array directly with the string
       * is unreliable, but this bascially works as a whitelist, so that's
       * probably not a bad idea.
       */
      switch ( strtoupper( $variable ) ) {

        case 'SERVER':
          $global = $_SERVER;
          break;
        case 'SESSION':
          $global = $_SESSION;
          break;
        case 'REQUEST':
          $global = $_REQUEST;
          break;
        case 'COOKIE':
          $global = $_COOKIE;
          break;
        case 'POST':
          $global = $_POST;
          break;
        case 'GET':
          $global = $_GET;
      }

      /*
       * we attempt to evaluate the named value from the superglobal, which includes 
       * the possiblity that it will be referring to an array element. We take that 
       * to two dimensions only. the only way that I know of to do this open-ended 
       * is to use eval, which I won't do
       */
      if ( isset( $global[ $name ] ) ) {

        $dynamic_value = $global[ $name ];

        if ( is_array( $dynamic_value ) || is_object( $dynamic_value ) ) {

          $array = is_object( $dynamic_value ) ? get_object_vars( $dynamic_value ) : $dynamic_value;
          switch ( count( $indexes ) ) {
            case 1:
              $dynamic_value = isset( $array[ $indexes[ 0 ] ] ) ? $array[ $indexes[ 0 ] ] : '';
              break;
            case 2:
              $dynamic_value = isset( $array[ $indexes[ 0 ] ][ $indexes[ 1 ] ] ) ? $array[ $indexes[ 0 ] ][ $indexes[ 1 ] ] : '';
              break;
            default:
              // if we don't have an index, grab the first value
              $dynamic_value = is_array( $array ) ? current( $array ) : '';
          }
        }
      }
    }

    return filter_var( $dynamic_value, FILTER_SANITIZE_SPECIAL_CHARS );
  }

  /**
   * provides the attributes of the last shortcode called
   * 
   * @return stdClass object with properties for each shortcode attribute
   */
  public static function last_shortcode_atts()
  {
    return (object) \PDb_shortcodes\attributes::last_attributes();
  }

  /**
   * determines if the field default value string is a dynamic value
   * 
   * @param string $value the value to test
   * @return bool true if the value is to be parsed as dynamic
   */
  public static function is_dynamic_value( $value )
  {
    $test_value = html_entity_decode( $value );

    /**
     * @filter pdb-dynamic_value
     * 
     * this filter is duplicated here so we can test the dynamic content
     * 
     * @param string the initial result; empty string
     * @param string the dynamic value key
     * @return the computed dynamic value
     */
    $dynamic_value = Participants_Db::apply_filters( 'dynamic_value', '', $test_value );

    return strpos( $test_value, '->' ) > 0 || strpos( $test_value, ':' ) > 0 || $dynamic_value !== '';
  }

  /**
   * tells if the string is a new password
   * 
   * checks if the string is an encrypted WP password or the password dummy
   * 
   * @param string $string the string to test
   * @return bool true if the string is a new password
   */
  public static function is_new_password( $string )
  {
    // we're counting on the new password not beginning with $P$
    return strpos( $string, '$P$' ) !== 0 && $string !== PDb_FormElement::dummy;
  }

  /**
   * supplies a group object for the named group
   * 
   * @param string $name group name
   * @return object the group parameters as a stdClass object
   */
  public static function get_group( $name )
  {
    global $wpdb;
    $sql = 'SELECT * FROM ' . Participants_Db::$groups_table . ' WHERE `name` = "%s"';
    return current( $wpdb->get_results( $wpdb->prepare( $sql, $name ) ) );
  }

  /**
   * checks a plugin permission level and passes it through a filter
   * 
   * this allows for all plugin functions that are permission-controlled to be controlled 
   * with a filter callback
   * 
   * the context value will contain the name of the function or script that is protected
   * 
   * @see http://codex.wordpress.org/Roles_and_Capabilities
   * 
   * @param string $plugin_cap the plugin capability level (not WP cap) to check for
   * @param string $context provides the context of the request
   * 
   * @return string the name of the WP capability to use in the named context
   */
  public static function plugin_capability( $plugin_cap, $context = '' )
  {

    $capability = 'read'; // assume the lowest cap

    if ( in_array( $plugin_cap, array( 'plugin_admin_capability', 'record_edit_capability' ) ) ) {

      $wp_cap = self::plugin_setting_value( $plugin_cap );

      // ensure a valid admin role #2903
      if ( $plugin_cap === 'plugin_admin_capability' ) {
        $wp_cap = self::admin_cap( $wp_cap );
      }

      /**
       * provides access to individual access privileges
       * 
       * @filter pdb-access_capability
       * @param string the WP capability that identifies the default level of access
       * @param string the privilege being requested
       * @return string the WP capability that is allowed this privilege
       */
      $capability = self::apply_filters( 'access_capability', $wp_cap, $context );
    }

    return $capability;
  }

  /**
   * checks for a valid administrator role capability
   * 
   * @param string $wp_cap the WP capability
   * @return string a valid capability to use for the plugin administrator
   */
  private static function admin_cap( $wp_cap )
  {
    $admin_roles = get_users( array( 'capability' => $wp_cap ) );

    if ( empty( $admin_roles ) ) {
      $wp_cap = 'manage_options';
    }

    return $wp_cap;
  }

  /**
   * check the current users plugin role
   * 
   * the plugin has two roles: editor and admin; it is assumed an admin has the editor 
   * capability
   * 
   * @param string $role optional string to test a specific role. If omitted, tests 
   *                     for editor role
   * @param string $context the function or action being tested for
   * 
   * @return bool true if current user has the role tested
   */
  public static function current_user_has_plugin_role( $role = 'editor', $context = '' )
  {
    $role = stripos( $role, 'admin' ) !== false ? 'plugin_admin_capability' : 'record_edit_capability';

    return current_user_can( self::plugin_capability( $role, $context ) );
  }

  /**
   * checks if a CSV export is allowed
   * 
   * first checks for a valid nonce, if that fails, checks the current user's capabilities
   * 
   * @return bool true if the export is allowed under the current circumstances
   */
  public static function csv_export_allowed()
  {
    $nonce = array_key_exists( '_wpnonce', $_POST ) ? filter_input( INPUT_POST, '_wpnonce', FILTER_SANITIZE_SPECIAL_CHARS ) : false;
    if ( $nonce && wp_verify_nonce( $nonce, self::csv_export_nonce() ) ) {
      return true;
    }
    $csv_role = Participants_Db::plugin_setting_is_true( 'editor_allowed_csv_export' ) ? 'editor' : 'admin';
    return Participants_Db::current_user_has_plugin_role( $csv_role, 'csv export' );
  }

  /**
   * supplies a nonce tag for the CSV export
   * 
   * @return string
   */
  public static function csv_export_nonce()
  {
    return 'pdb-csv_export';
  }

  /**
   * loads the plugin translation files and sets the textdomain
   * 
   * the parameter is for the use of aux plugins
   * 
   * originally from: http://geertdedeckere.be/article/loading-wordpress-language-files-the-right-way
   * also: https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/
   * 
   * @param string $path of the calling file
   * @param string $textdomain omit to use default plugin textdomain
   * 
   * @return null
   */
  public static function load_plugin_textdomain( $path, $textdomain = '' )
  {
    $textdomain = empty( $textdomain ) ? Participants_Db::PLUGIN_NAME : $textdomain;

   load_plugin_textdomain( $textdomain, false, dirname( plugin_basename( $path ) ) . '/languages' );
  }

  /**
   * sends a string through a filter for effecting a multilingual translation
   * 
   * this is called on the pdb-translate_string filter, which is only enabled 
   * when PDB_MULTILINGUAL is defined true
   * 
   * this is meant for strings with embedded language tags, if the argument is not 
   * a non-numeric string, it is passed through
   * 
   * @since 1.9.5.7 eliminated call to gettext
   * 
   * @param string the unstranslated string
   * 
   * @return string the translated string or unaltered input value
   */
  public static function string_static_translation( $string )
  {
    if ( !is_string( $string ) || is_numeric( $string ) ) {
      return $string;
    }

    return self::extract_from_multilingual_string( $string );
  }

  /**
   * extracts a language string from a multilingual string
   * 
   * this assumes a Q-TranslateX style multilingual string
   * 
   * this function is basically a patch to let multilingual strings work on the backend
   * 
   * @param string $ml_string
   * @return string
   */
  private static function extract_from_multilingual_string( $ml_string )
  {
    if ( has_filter( 'pdb-extract_multilingual_string' ) ) {
      /**
       * @filter pdb-extract_multilingual_string
       * @param string the multilingual string
       * @return the extracted string for the current language
       */
      return Participants_Db::apply_filters( 'extract_multilingual_string', $ml_string );
    }

    if ( strpos( $ml_string, '[:' ) === false && strpos( $ml_string, '{:' ) === false ) {
      return $ml_string;
    }

    if ( preg_match( '/\[:[a-z]{2}/', $ml_string ) === 1 ) {
      $brace = array( '\[', '\]' );
    } else {
      $brace = array( '\{', '\}' );
    }

    $lang = strstr( get_locale(), '_', true );

    return preg_filter( '/.*' . $brace[ 0 ] . ':' . $lang . '' . $brace[ 1 ] . '(([^' . $brace[ 0 ] . ']|' . $brace[ 0 ] . '[^:])*)(' . $brace[ 0 ] . ':.*|$)/s', '$1', $ml_string );
  }

  /**
   * creates a translated key string of the format title (name) where "name" is untranslated
   * 
   * @param string $title the title string
   * @param string $name the name string
   * 
   * @return string the translated title with the untranslated name added (if supplied)
   */
  public static function title_key( $title, $name = '' )
  {
    if ( empty( $name ) ) {
      return Participants_Db::apply_filters( 'translate_string', $title );
    }
    return sprintf( '%s (%s)', self::apply_filters( 'translate_string', $title ), $name );
  }

  /**
   * provides a plugin setting
   * 
   * @param string $name setting name
   * @param string|int|float $default a default value
   * @return string the plugin setting value or provided default
   */
  public static function plugin_setting( $name, $default = false )
  {
    $setting_value = self::plugin_setting_value( $name, $default );

    return is_string( $setting_value ) ? self::apply_filters( 'translate_string', $setting_value ) : $setting_value;
  }

  /**
   * provides a plugin setting
   * 
   * this one does not send the value through the translation filter
   * 
   * @param string $name setting name
   * @param string|int|float $default a default value
   * @return string the plugin setting value or provided default
   */
  public static function plugin_setting_value( $name, $default = false )
  {
    if ( $default === false ) {
      $default = self::plugin_setting_default( $name );
    }

    /**
     * @filter pdb-{$setting_name}_setting_value
     * @param mixed the setting value
     * @return mixed setting value
     */
    return self::apply_filters( $name . '_setting_value', ( isset( Participants_Db::$plugin_options[ $name ] ) ? Participants_Db::$plugin_options[ $name ] : $default ) );
  }

  /**
   * provides the default setting for an option
   * 
   * @param string $name of the option
   * @return string|bool the option's default value, bool false if no default is set
   */
  public static function plugin_setting_default( $name )
  {
    $defaults = get_option( Participants_Db::$default_options );

    return isset( $defaults[ $name ] ) ? $defaults[ $name ] : false;
  }

  /**
   * checks a plugin setting for a saved value
   * 
   * returns false for empty string, true for 0
   * 
   * @param string $name setting name
   * @return bool false true if the setting has been saved by the user
   */
  public static function plugin_setting_is_set( $name )
  {
    return isset( Participants_Db::$plugin_options[ $name ] ) && strlen( Participants_Db::plugin_setting( $name ) ) > 0;
  }

  /**
   * provides a boolean plugin setting value
   * 
   * @param string $name of the setting
   * @param bool the default value
   * @return bool the setting value
   */
  public static function plugin_setting_is_true( $name, $default = false )
  {
    $cachekey = 'pdb-bool-setting';
    $setting = wp_cache_get( $name, $cachekey, true, $found );

    if ( $found ) {
      return $setting;
    }

    if ( $default === false ) {
      $default = self::plugin_setting_default( $name );
    }

    if ( isset( Participants_Db::$plugin_options[ $name ] ) ) {
      $setting = filter_var( self::plugin_setting_value( $name ), FILTER_VALIDATE_BOOLEAN );
    } else {
      $setting = (bool) $default;
    }

    wp_cache_set( $name, $setting, $cachekey, Participants_Db::cache_expire() );

    return $setting;
  }

  /**
   * updates a main setting option
   * 
   * @param string $option_name
   * @param string|int|bool $setting
   */
  public static function update_plugin_setting( $option_name, $setting )
  {
    $options = get_option( Participants_Db::$participants_db_options );

    if ( is_array( $options ) ) {
      $options[ $option_name ] = $setting;

      update_option( Participants_Db::$participants_db_options, $options );
    }
  }

  /**
   * sets up an API filter
   * 
   * determines if a filter has been set for the given tag, then either filters 
   * the term or returns it unaltered
   * 
   * this function also allows for two extra parameters
   * 
   * @param string $slug the base slug of the plugin API filter
   * @param unknown $term the term to filter
   * @param unknown $var1 extra variable
   * @param unknown $var2 extra variable
   * @return unknown the filtered or unfiltered term
   */
  public static function set_filter( $slug, $term, $var1 = NULL, $var2 = NULL )
  {
    $slug = self::add_prefix( $slug );
    if ( !has_filter( $slug ) ) {
      return $term;
    }
    return apply_filters( $slug, $term, $var1, $var2 );
  }

  /**
   * sets up an API filter
   * 
   * alias for Participants_Db::set_filter()
   * 
   * @param string $slug the base slug of the plugin API filter
   * @param unknown $term the term to filter
   * @param unknown $var1 extra variable
   * @param unknown $var2 extra variable
   * @return unknown the filtered or unfiltered term
   */
  public static function apply_filters( $slug, $term, $var1 = NULL, $var2 = NULL )
  {
    return self::set_filter( $slug, $term, $var1, $var2 );
  }

  /**
   * triggers an action
   * 
   * @param string $slug the base slug of the plugin API filter
   * @param unknown $term the term to filter
   * @param unknown $var1 extra variable
   * @param unknown $var2 extra variable
   * @return unknown the filtered or unfiltered term
   */
  public static function do_action( $slug, $term, $var1 = NULL, $var2 = NULL )
  {
    do_action( self::add_prefix( $slug ), $term, $var1, $var2 );
  }

  /**
   * provides a prefixed slug
   * 
   * @param string  $slug the paybe-prefixed slug
   * @return string the prefixed slug
   */
  public static function add_prefix( $slug )
  {
    return strpos( $slug, Participants_Db::$prefix ) !== 0 ? Participants_Db::$prefix . $slug : $slug;
  }

  /**
   * writes the admin side custom CSS setting to the custom css file
   * 
   * @return bool true if the css file can be written to
   * 
   */
  protected static function _set_admin_custom_css()
  {
    return self::_setup_custom_css( Participants_Db::$plugin_path . '/css/PDb-admin-custom.css', 'custom_admin_css' );
  }

  /**
   * writes the custom CSS setting to the custom css file
   * 
   * @return bool true if the css file can be written to
   * 
   */
  protected static function _set_custom_css()
  {
    return self::_setup_custom_css( Participants_Db::$plugin_path . '/css/PDb-custom.css', 'custom_css' );
  }

  /**
   * writes the custom CSS setting to the custom print css file
   * 
   * @return bool true if the css file can be written to
   * 
   */
  protected static function _set_custom_print_css()
  {
    return self::_setup_custom_css( Participants_Db::$plugin_path . '/css/PDb-custom-print.css', 'print_css' );
  }

  /**
   * writes the custom CSS setting to the custom css file
   * 
   * @param string  $stylesheet_path absolute path to the stylesheet
   * @param string  $setting_name name of the setting to use for the stylesheet content
   * 
   * @return bool true if the css file can be written to
   * 
   */
  protected static function _setup_custom_css( $stylesheet_path, $setting )
  {
    if ( !is_writable( $stylesheet_path ) ) {
      return false;
    }

    $file_contents = file_get_contents( $stylesheet_path );
    $custom_css = self::custom_css_content( $setting );

    if ( empty( $custom_css ) ) {
      return false;
    }

    if ( $file_contents === $custom_css ) {
      // error_log(__METHOD__.' CSS settings are unchanged; do nothing');
    } else {
      file_put_contents( $stylesheet_path, $custom_css );
    }
    return true;
  }

  /**
   * supplies the content of the custom CSS file
   * 
   * @param string $setting name of the css setting to use
   * @return string the CSS
   */
  private static function custom_css_content( $setting )
  {
    $content = Participants_Db::plugin_setting( $setting );

    switch ( $setting ) {

      case 'print_css':
        $content = sprintf( "@media print {\n\n%s\n\n}", $content );
        break;
    }

    return $content;
  }

  /**
   * provides a CSS dimension value with units
   * 
   * defaults to pixels, checks for a valid unit
   * 
   * @param string $value
   * @return string
   */
  public static function css_dimension_value( $value )
  {
    $keyword_check = preg_match( '#^(auto|inherit)$#', $value );

    if ( $keyword_check === 1 ) {
      return $value;
    }

    $fallback = preg_replace( "/[^0-9]/", "", $value ) . 'px';

    $value = str_replace( ' ', '', $value ); // remove any spaces

    $check = preg_match( '/^[0-9]+.?([0-9]+)?(px|em|rem|ex|ch|%|lh|vw|vh|vmin|vmax)$/', $value );

    return $check === 1 ? $value : $fallback;
  }

  /**
   * processes the search term keys for use in shortcode filters
   * 
   * if the supplied key is not defined here, returns the key
   * 
   * @param string  $key the search term
   * @return string the search term to use
   */
  public static function date_key( $key )
  {
    $value = $key;

    // get the numeric part, if included
    if ( $numeric = self::search_key_numeric_value( $key ) ) {
      $key = preg_replace( '/^[+-]?\d+/', 'n', $key );
    }

    switch ( $key ) {
      case 'current_date':
        $value = time();
        break;
      case 'current_day':
        $value = date( 'M j,Y 00:00' );
        break;
      case 'current_week':
        $value = date( 'M j,Y 00:00', strtotime( 'this week' ) );
        break;
      case 'current_month':
        $value = date( 'M 01,Y 00:00' );
        break;
      case 'current_year':
        $value = date( '\j\a\n 01,Y 00:00' );
        break;
      case 'n_days':
        $value = date( 'M j,Y 00:00', strtotime( $numeric . ' days' ) );
        break;
      case 'n_months':
        $value = date( 'M j,Y 00:00', strtotime( $numeric . ' months' ) );
        break;
    }

    return $value;
  }

  /**
   * processes the search term keys for use in shortcode filters as a partial date string
   * 
   * unrecognized keys are processed through self::date_key
   * 
   * @param string  $key the search term
   * @return string the search term to use
   */
  public static function date_key_string( $key )
  {
    $value = $key;

    // get the numeric part, if included
    if ( $numeric = self::search_key_numeric_value( $key ) ) {
      $key = preg_replace( '/^[+-]?\d+/', 'n', $key );
    }

    switch ( $key ) {
      case 'current_month':
        $value = wp_date( 'F' );
        break;
      case 'current_year':
        $value = wp_date( 'Y' );
        break;
      case 'n_months':
        $value = wp_date( 'F', strtotime( $numeric . ' months' ) );
        break;
      case 'n_years':
        $value = wp_date( 'Y', strtotime( $numeric . ' years' ) );
        break;
      default:
        $value = self::date_key( $key );
    }

    return $value;
  }

  /**
   * provides the search term key numeric value
   * 
   * @param string $key
   * @return string|bool extracted numeric value or bool false if no number can be extracted
   */
  private static function search_key_numeric_value( $key )
  {
    if ( preg_match( '/^([+-]?\d+)_/', $key, $matches ) === 0 ) {
      return false;
    }
    return $matches[ 1 ];
  }

  /**
   * supplies an image/file upload location
   * 
   * relative to WP root
   * 
   * @global  wpdb  $wpdb
   * 
   * @return string relative path to the plugin files location
   */
  public static function files_location()
  {
    $base_path = Participants_Db::plugin_setting( 'image_upload_location', 'wp-content/uploads/' . Participants_Db::PLUGIN_NAME . '/' );

    // multisite compatibility
    global $wpdb;
    if ( isset( $wpdb->blogid ) && $wpdb->blogid > 1 ) {
      $base_path = str_replace( Participants_Db::PLUGIN_NAME, 'sites/' . $wpdb->blogid . '/' . Participants_Db::PLUGIN_NAME, $base_path );
    }

    /**
     * @filter pdb-files_location
     * @param string the files location base path
     * @return string
     */
    return Participants_Db::apply_filters( 'files_location', $base_path );
  }

  /**
   * provides the base absolute path for files uploads
   * 
   * @return string
   */
  public static function base_files_path()
  {
    $base_path = Participants_Db::apply_filters( 'files_use_content_base_path', false ) ? trailingslashit( self::content_dir() ) : self::app_base_path();

    return $base_path;
  }

  /**
   * provides the content directory path
   * 
   * @return string
   */
  public static function content_dir()
  {
    return str_replace( 'plugins/' . Participants_Db::PLUGIN_NAME, '', plugin_dir_path( Participants_Db::$plugin_file ) );
  }

  /**
   * provides the base URL for file and image uploads
   * 
   * @return string
   */
  public static function base_files_url()
  {
    return Participants_Db::apply_filters( 'files_use_content_base_path', false ) ? trailingslashit( content_url() ) : self::app_base_url();
  }

  /**
   * supplies the absolute path to the files location
   * 
   * @return string
   */
  public static function files_path()
  {
    return trailingslashit( xnau_Image_Handler::concatenate_directory_path( self::base_files_path(), Participants_Db::files_location() ) );
  }

  /**
   * supplies the URI to the files location
   * 
   * @return string
   */
  public static function files_uri()
  {
    return self::base_files_url() . trailingslashit( ltrim( Participants_Db::files_location(), DIRECTORY_SEPARATOR ) );
  }

  /**
   * deletes a file
   * 
   * @param string $filename
   * @return bool success
   */
  public static function delete_file( $filename )
  {
    /**
     * provides a way to override the delete method: if the filter returns bool 
     * true or false, the normal delete method will be skipped. If the filter returns 
     * a string, the string will be treated as the filename to delete
     * 
     * @since 1.7.6.2
     * @filter pdb-delete_file
     * @param string filename
     * @return string|bool filename or bool false to skip deletion
     */
    $result = self::apply_filters( 'delete_file', $filename );

    if ( !is_bool( $result ) ) {
      $current_dir = getcwd(); // save the current dir
      chdir( self::files_path() ); // set the plugin uploads dir
      $result = @unlink( $filename ); // delete the file
      chdir( $current_dir ); // change back to the previous directory
    }
    return $result;
  }

  /**
   * makes a title legal to use in anchor tag
   */
  public static function make_anchor( $title )
  {
    return str_replace( ' ', '', preg_replace( '#^[0-9]*#', '', strtolower( $title ) ) );
  }

  /**
   * checks if the current user's form submissions are to be validated
   * 
   * @return bool true if the form should be validated 
   */
  public static function is_form_validated()
  {
    if ( is_admin() && !self::plugin_setting_is_true( 'admin_edits_validated', false ) ) {

      return self::current_user_has_plugin_role( 'admin', 'forms not validated' ) === false;
    } else {

      return true;
    }
  }

  /**
   * replace the tags in text messages
   * 
   * provided for backward compatibility
   *
   * returns the text with the values replacing the tags
   * all tags use the column name as the key string
   *
   * @param  string  $text           the text containing tags to be replaced with 
   *                                 values from the db
   * @param  int     $participant_id the record id to use
   * @param  string  $mode           unused
   * @return string                  text with the tags replaced by the data
   */
  public static function proc_tags( $text, $participant_id, $mode = '' )
  {
    return PDb_Tag_Template::replaced_text( $text, $participant_id );
  }

  /**
   * clears empty elements out of an array
   * 
   * leaves "zero" values in
   * 
   * @param array $array the input
   * @return array the cleaned array
   */
  public static function cleanup_array( $array )
  {
    return array_filter( $array, function ( $v ) {
      return $v || $v === 0 || $v === '0';
    } );
  }

  /**
   * recursively merges two arrays, overwriting matching keys
   *
   * if any of the array elements are an array, they will be merged with an array
   * with the same key in the base array
   *
   * @param array $array    the base array
   * @param array $override the array to merge
   * @return array
   */
  public static function array_merge2( $array, $override )
  {
    $x = array();
    foreach ( $array as $k => $v ) {
      if ( isset( $override[ $k ] ) ) {
        if ( is_array( $v ) ) {
          $v = Participants_Db::array_merge2( $v, (array) $override[ $k ] );
        } else
          $v = $override[ $k ];
        unset( $override[ $k ] );
      }
      $x[ $k ] = $v;
    }
    // add in the remaining unmatched elements
    return $x += $override;
  }
  
  
  /**
   * replaces a key in the array without changing the order of the array
   * 
   * @param array $array
   * @param string $key
   * @param string $new_key
   * @return array
   */
  public static function replace_key($array, $key, $new_key)
  {
    $keys = array_keys($array);
    $index = array_search($key, $keys);

    if ($index !== false) {
        $keys[$index] = $new_key;
        $array = array_combine($keys, $array);
    }

    return $array;
  }

  /**
   * validates a time stamp
   *
   * @param mixed $timestamp the string to test
   * @return bool true if valid timestamp
   */
  public static function is_valid_timestamp( $timestamp )
  {
    return is_int( $timestamp ) or ( (string) (int) $timestamp === $timestamp);
  }

  /**
   * translates a PHP date() format string to a jQuery format string
   * 
   * @param string $PHP_date_format the date format string
   *
   */
  static function get_jqueryUI_date_format( $PHP_date_format = '' )
  {

    $dateformat = empty( $PHP_date_format ) ? get_option( 'date_format' ) : $PHP_date_format;

    return xnau_Date_Format_String::to_jQuery( $dateformat );
  }

  /**
   * returns the PHP version as a float
   *
   */
  function php_version()
  {

    $numbers = explode( '.', phpversion() );

    return (float) ( $numbers[ 0 ] + ( $numbers[ 1 ] / 10 ) );
  }

  /**
   * tells if the current operation is in the WP admin side
   * 
   * this won't give a positive for ajax calls
   * 
   * @return bool true if in the admin side
   */
  public static function is_admin()
  {
    return is_admin() && !( defined( 'DOING_AJAX' ) && DOING_AJAX );
  }

  /**
   * adds an admin area error message
   * 
   * @param string $message the message to be dislayed
   * @param string $type the type of message:
   *    error - red
   *    warning - yellow
   *    success - green
   *    info - blue
   */
  public static function set_admin_message( $message, $type = 'error' )
  {
    if ( self::is_admin() ) {

      if ( empty( $message ) ) {
        Participants_Db::debug_log( __METHOD__ . ' adding empty message ' . print_r( wp_debug_backtrace_summary(), 1 ), 3 );
        return null;
      }

      $message_list = Participants_Db::$session->get( 'admin_message', array() );

      switch ( $type ) {
        // this is to translate some legacy values
        case 'updated':
          $type = 'success';
      }

      if ( !self::has_duplicate_message( $message, $message_list ) ) {
        $message_list[] = array( $message, $type );
      }

      Participants_Db::$session->set( 'admin_message', $message_list );
    }
  }

  /**
   * checks for a duplicate admin message
   * 
   * @param string $message
   * @param array $message_list
   * @return bool true if the same message is already in the array
   */
  private static function has_duplicate_message( $message, $message_list )
  {
    return count( array_filter( array_map( function ( $v ) use ( $message ) {
                              return $v[ 0 ] === $message;
                            }, $message_list ) ) ) > 0;
  }

  /**
   * clears the admin message
   */
  public static function clear_admin_message()
  {
    Participants_Db::$session->clear( 'admin_message' );
  }

  /**
   * prints the admin message
   */
  public static function admin_message()
  {
    if ( self::has_admin_message() ) {

      $messages = Participants_Db::$session->get( 'admin_message' );

      foreach ( $messages as $message ) {
        printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $message[ 1 ], $message[ 0 ] );
        self::clear_admin_message();
      }
    }
  }

  /**
   * provides the current admin message as a plain string
   * 
   * @return string
   */
  public static function admin_message_content()
  {
    $messages = Participants_Db::$session->get( 'admin_message' );

    if ( self::has_admin_message() ) {

      $message_text = '';

      foreach ( $messages as $message ) {
        $message_text .= $message[ 0 ];
      }
      return $message_text;
    }

    return '';
  }

  /**
   * provides the current admin message type
   * 
   * @return string
   */
  public static function admin_message_type()
  {
    $message = Participants_Db::$session->get( 'admin_message' );

    if ( self::has_admin_message() ) {
      return current( $message )[ 1 ];
    }

    return '';
  }

  /**
   * tells if there is an admin message
   * 
   * @return bool
   */
  public static function has_admin_message()
  {
    $messages = Participants_Db::$session->get( 'admin_message' );

    return is_array( $messages ) && !empty( current( $messages ) );
  }

  /**
   * displays a warning message if the php version is too low
   * 
   */
  protected static function php_version_warning()
  {
    $target_version = '5.6';

    if ( version_compare( PHP_VERSION, $target_version, '<' ) && !get_option( Participants_Db::one_time_notice_flag ) ) {

      PDb_Admin_Notices::post_warning( '<p><span class="dashicons dashicons-warning"></span>' . sprintf( __( 'Participants Database will require PHP version %1$s in future releases, you have PHP version %2$s. Please update your php version, future versions of Participants Database may not run without minimum php version %1$s', 'participants-database' ), $target_version, PHP_VERSION ) . '</p>', '', false );

      // mark the option as shown
      update_option( Participants_Db::one_time_notice_flag, true );
    }
  }

  /**
   * gets the PHP timezone setting
   * 
   * @return string
   */
  public static function get_timezone()
  {
    $php_timezone = ini_get( 'date.timezone' );
    return empty( $php_timezone ) ? 'UTC' : $php_timezone;
  }

  /**
   * provides a UTC timestamp string for db queries
   * 
   * @see issue #2754
   * @return string mysql timestamp
   */
  public static function timestamp_now()
  {
    $use_utc_tz = Participants_Db::plugin_setting_is_true( 'db_timestamps_use_local_tz', false ) === false;

    return Participants_Db::apply_filters( 'timestamp_now', current_time( 'mysql', $use_utc_tz ) );
  }

  /**
   * collect a list of all the plugin shortcodes present in the content
   *
   * @param string $content the content to test
   * @param string $tag
   * @return array of plugin shortcode tags
   */
  public static function get_plugin_shortcodes( $content = '', $tag = '[pdb_' )
  {

    $shortcodes = array();
    // get all shortcodes
    preg_match_all( '/' . get_shortcode_regex() . '/s', $content, $matches, PREG_SET_ORDER );
    // if no shortcodes, return empty array
    if ( empty( $matches ) )
      return array();
    // check each one for a plugin shortcode
    foreach ( $matches as $shortcode ) {
      if ( false !== strpos( $shortcode[ 0 ], $tag ) ) {
        $shortcodes[] = $shortcode[ 2 ] . '-shortcode';
      }
    }
    return $shortcodes;
  }

  /**
   * check a string for a shortcode
   *
   * modeled on the WP function of the same name
   * 
   * what's different here is that it will return true on a partial match so it can 
   * be used to detect any of the plugin's shortcode. Generally, we just check for 
   * the common prefix
   *
   * @param string $content the content to test
   * @param string $tag
   * @return boolean
   */
  public static function has_shortcode( $content = '', $tag = '[pdb_' )
  {
    // get all shortcodes
    preg_match_all( '/' . get_shortcode_regex() . '/s', $content, $matches, PREG_SET_ORDER );
    // none found
    if ( empty( $matches ) )
      return false;

    // check each one for a plugin shortcode
    foreach ( $matches as $shortcode ) {
      if ( false !== strpos( $shortcode[ 0 ], $tag ) && false === strpos( $shortcode[ 0 ], '[[' ) ) {
        return true;
      }
    }
    return false;
  }

  /**
   * flushes the page cache
   * 
   * Each caching plugin has its own flush mechanism, so there's really way to 
   * guarantee that the cache will be flushed on a particular site. We will add 
   * flush commands for most of the most popular caching plugins here, also a 
   * hook so that a site administrator can set up a page cache flush for their 
   * particular caching setup.
   * 
   * @global WP_Post $post
   * @param string $path the current page path
   */
  public static function flush_page_cache( $path )
  {
    global $post;

    if ( is_a( $post, 'WP_Post' ) ) {

      // W3 Total Cache
      do_action( 'w3tc_flush_post', $post->ID );
    }

    $url = site_url( $path );

    // WP Cloudflare Super Page Cache
//    global $sw_cloudflare_pagecache;
//    if ( is_object( $sw_cloudflare_pagecache ) ) {
//      $sw_cloudflare_pagecache->objects["cache_controller"]->purge_urls( array( $url ) );
//    }
  }

  /**
   * sets the shortcode present flag if a plugin shortcode is found in the post
   * 
   * runs on the 'wp' filter
   * 
   * @global object $post
   * @return array $posts
   */
  public static function remove_rel_link()
  {

    global $post;
    /*
     * this is needed to prevent Firefox prefetching the next page and firing the damn shortcode
     * 
     * as per: http://www.ebrueggeman.com/blog/wordpress-relnext-and-firefox-prefetching
     */
    if ( is_object( $post ) && $post->post_type === 'page' ) {
      remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head' );
    }
  }

  /**
   * provides an array of field indices corresponding, given a list of field names
   * 
   * or vice versa if $indices is false
   * 
   * @param array $fieldnames the array of field names
   * @param bool  $indices if true returns array of indices, if false returns array of fieldnames
   * @return array an array of integers
   */
  public static function get_field_indices( $fieldnames, $indices = true )
  {
    global $wpdb;
    $sql = 'SELECT f.' . ($indices ? 'id' : 'name') . ' FROM ' . Participants_Db::$fields_table . ' f ';
    $sql .= 'WHERE f.' . ($indices ? 'name' : 'id') . ' ';
    if ( count( $fieldnames ) > 1 ) {
      $sql .= 'IN ("' . implode( '","', $fieldnames );
      if ( count( $fieldnames ) < 100 ) {
        $sql .= '") ORDER BY FIELD(f.name, "' . implode( '","', $fieldnames ) . '")';
      } else {
        $sql .= '") ORDER BY f.' . ($indices ? 'id' : 'name') . ' ASC';
      }
    } else {
      $sql .= '= "' . current( $fieldnames ) . '"';
    }
    return $wpdb->get_col( $sql );
  }

  /**
   * provides a list of field names, given a list of indices
   * 
   * @param array $ids of integer ids
   * @return array of field names
   * 
   */
  public static function get_indexed_names( $ids )
  {
    return self::get_field_indices( $ids, false );
  }

  /**
   * gets a list of column names from a dot-separated string of ids
   * 
   * @param string $ids the string of ids
   * @return array of field names
   */
  public static function get_shortcode_columns( $ids )
  {
    return self::get_field_indices( explode( '.', $ids ), false );
  }

  /**
   * provides a filter array for a search submission
   * 
   * filters a POST submission for displaying a list
   * 
   * @param bool $multi if true, filter a multi-field search submission
   * @return array of filter parameters
   */
  public static function search_post_filter( $multi = false )
  {
    $array_filter = array(
        'filter' => FILTER_SANITIZE_SPECIAL_CHARS,
        'flags' => FILTER_FORCE_ARRAY
    );
    $multi_validation = $multi ? $array_filter : FILTER_SANITIZE_SPECIAL_CHARS;
    return array(
        'filterNonce' => FILTER_SANITIZE_SPECIAL_CHARS,
        'postID' => FILTER_VALIDATE_INT,
        'submit' => FILTER_SANITIZE_SPECIAL_CHARS,
        'action' => FILTER_SANITIZE_SPECIAL_CHARS,
        'instance_index' => FILTER_VALIDATE_INT,
        'target_instance' => FILTER_VALIDATE_INT,
        'pagelink' => FILTER_SANITIZE_SPECIAL_CHARS,
        'search_field' => $multi_validation,
        'operator' => $multi_validation,
        'value' => $multi_validation,
        'logic' => $multi_validation,
        'sortBy' => FILTER_SANITIZE_SPECIAL_CHARS,
        'ascdesc' => FILTER_SANITIZE_SPECIAL_CHARS,
        Participants_Db::$list_page => FILTER_VALIDATE_INT,
    );
  }

  /**
   * provides a list of orphaned field columns in the main db
   * 
   * @global wpdb $wpdb
   * @return array of field names
   */
  public static function orphaned_db_columns()
  {
    global $wpdb;
    $columns = $wpdb->get_results( 'SHOW COLUMNS FROM ' . Participants_Db::$participants_table );

    $orphan_columns = array();

    foreach ( $columns as $column ) {
      if ( !array_key_exists( $column->Field, Participants_Db::$fields ) ) {
        $orphan_columns[] = $column->Field;
      }
    }

    return $orphan_columns;
  }

  /**
   * provides a general cache expiration time
   * 
   * this is to prevent persistent caches from holding on to the cached values too long
   * 
   * this is tuned to generously cover a single page load
   * 
   * @return int cache valid time in seconds
   */
  public static function cache_expire()
  {
    return Participants_Db::apply_filters( 'general_cache_expiration', 10 );
  }

  /**
   * clears the shortcode session for the current page
   * 
   * 
   * shortcode sessions are used to provide asynchronous functions with the current 
   * shortcode attributes
   */
  public static function reset_shortcode_session()
  {
    global $post;
    if ( is_object( $post ) ) {
      $current_session = Participants_Db::$session->getArray( 'shortcode_atts' );
      /*
       * clear the current page's session
       */
      $current_session[ $post->ID ] = array();
      Participants_Db::$session->set( 'shortcode_atts', $current_session );
    }
  }

  /**
   * checks for the presence of the WP Session plugin
   * 
   * we are no longer using this as of 1.9.6.2 #2388
   * 
   * @return bool true if the plugin is present
   */
  public static function wp_session_plugin_is_active()
  {
    return class_exists( 'EAMann\Sessionz\Manager' );
  }

  /**
   * determines if the current form status is a kind of multipage
   * 
   * @return bool true if the form is part of a multipage form
   */
  public static function is_multipage_form()
  {
    $form_status = Participants_Db::$session->get( 'form_status' );

    return stripos( $form_status, 'multipage' ) !== false;
  }

  /**
   * provides the byte value for a php configuration shortcoand value
   * 
   * @param string $value
   * @return int bytes
   */
  public static function shorthand_bytes_value( $value )
  {
    $mult = 1;
    switch ( true ) {

      case stripos( $value, 'K' ) !== false:
        $mult = 1000;
        break;

      case stripos( $value, 'M' ) !== false:
        $mult = 1000000;
        break;

      case stripos( $value, 'G' ) !== false:
        $mult = 1000000000;
        break;
    }

    return intval( $value ) * $mult;
  }

  /**
   * Remove slashes from strings, arrays and objects
   * 
   * @param    mixed   input data
   * @return   mixed   cleaned input data
   */
  public static function deep_stripslashes( $input )
  {
    if ( is_array( $input ) ) {
      $input = array_map( array( __CLASS__, 'deep_stripslashes' ), $input );
    } elseif ( is_object( $input ) ) {
      $vars = get_object_vars( $input );
      foreach ( $vars as $k => $v ) {
        $input->{$k} = deep_stripslashes( $v );
      }
    } elseif ( is_string( $input ) ) {
      $input = stripslashes( $input );
    }

    return $input;
  }

  /**
   * performs a fix for some older versions of the plugin; does nothing with current plugins
   */
  public static function reg_page_setting_fix()
  {
    // if the setting was made in previous versions and is a slug, convert it to a post ID
    $regpage = isset( Participants_Db::$plugin_options[ 'registration_page' ] ) ? Participants_Db::$plugin_options[ 'registration_page' ] : '';

    if ( !empty( $regpage ) && !is_numeric( $regpage ) ) {

      Participants_Db::update_plugin_setting( 'registration_page', self::get_id_by_slug( $regpage ) );

      Participants_Db::$plugin_options[ 'registration_page' ] = self::get_id_by_slug( $regpage );
    }
  }

  /**
   * checks to make sure the plugin uploads directory is writable 
   */
  protected static function check_uploads_directory()
  {
    $message_key = 'uploads_directory_notice';

    if ( !is_writable( Participants_Db::files_path() ) ) {
      self::debug_log( ' The configured uploads directory is not reporting as writable: ' . Participants_Db::files_path() );

      if ( !Participants_Db::plugin_setting_is_set( 'upload_location_warning_disable', false ) ) {

        $message_id = PDb_Admin_Notices::post_warning( '<p><span class="dashicons dashicons-warning"></span>' . sprintf( __( 'The configured uploads directory "%s" for Participants Database is not writable. This means that plugins file uploads will fail, check the Participants Database "File Upload Location" setting for the correct path.', 'participants-database' ), Participants_Db::files_path() ) . '<a href="https://xnau.com/work/wordpress-plugins/participants-database/participants-database-documentation/participants-database-settings-help/#File-and-Image-Uploads-Use-WP-"><span class="dashicons dashicons-editor-help"></span></a>' . '</p>', '', false );

        PDb_Admin_Notices::store_message_key( $message_key, $message_id );
      }
    } else {
      PDb_Admin_Notices::clear_message_key( $message_key );
    }

//    if ( substr_count( Participants_Db::files_path(), 'wp-content' ) > 1 ) {
//      
//      PDb_Admin_Notices::post_warning('<p><span class="dashicons dashicons-warning"></span>' . sprintf( __( 'The configured uploads directory "%s" for Participants Database containes a duplicate reference to the wp-content directory. Check your "File Upload Location" setting or uncheck the "File and Image Uploads Use WP Content Path" setting to correct this.', 'participants-database' ), Participants_Db::files_path() ) . '</p>', '', false);
//      
//    }
  }
  
  /**
   * checks for the ability to perform a background process
   * 
   * this tries loading a script from the website, which must be possible to 
   * perform a background process using an HTTP loopback
   * 
   * @return bool true if the loopback is working
   */
  public static function check_http_loopback()
  {
    $bg_imports_enabled = Participants_Db::plugin_setting_value( 'background_import', '0' ) != '0';
    
    $message_key = 'pdb-background_process_fault';
    
    $working = true;
    
    if ( $bg_imports_enabled )
    { 
      $response = wp_remote_head( admin_url( 'admin-ajax.php' ) );

      switch (true)
      {
        case ! is_array( $response ):
          $working = false;
          break;
        case ! isset( $response['response']['code'] ):
          $working = false;
          break;
        case $response['response']['code'] == '403': // the expected code if it is working is 400 Bad Request
          $working = false;
          break;
      }
    }
    
    if ( ! $working && $bg_imports_enabled )
    {
      self::debug_log( __METHOD__ . ' The site cannot remotely access its own admin-ajax.php script. Response: ' . print_r($response,1), 3 );
      
      global $pagenow;
      $page = filter_input( INPUT_GET, 'page', FILTER_DEFAULT, Participants_Db::string_sanitize() );

      if ( $pagenow === 'admin.php' && ( $page === 'participants-database-upload_csv' || $page === 'participants-database_settings_page') ) {

        $message_id = PDb_Admin_Notices::post_warning( '<p><span class="dashicons dashicons-warning"></span>' . sprintf( __('You current server configuration does not allow background processes. You can disable "CSV Imports in the Background" or attempt to fix the problem by making sure the WordPress application can access its own %s script.', 'participants-database'), 'admin-ajax.php' ) . self::settings_help('background-imports-fail') . '</p>', '', false );

        PDb_Admin_Notices::store_message_key( $message_key, $message_id );
      }
    }
    else
    {
      PDb_Admin_Notices::clear_message_key( $message_key );
    }
    
    return $working;
  }
  
  /**
   * supplies a settings help link
   * 
   * @param string $anchor the anchor string
   * @return string 
   */
  public static function settings_help( $anchor )
  {
    $href = 'https://xnau.com/participants-database-settings-help/';
    return '&nbsp;<a class="settings-help-icon" href="' . $href . '#' . $anchor . '" target="_blank"><span class="dashicons dashicons-editor-help"></span></a>';
  }
  
  
 
/**
 * Returns formatted string as 01:20:15 000
 *
 * @param float $seconds seconds
 * @return string
 */
public static function format_seconds( $seconds ) 
{
    $timegroups      = array('miliseconds' => 0, 'seconds' => 0, 'minutes' => 0, 'hours' => 0);
    $interval    = $seconds * 1000; // gives us the number of microseconds
 
    $grouplengths       = array(
        'hours'         => 60*60*1000,
        'minutes'       => 60*1000,
        'seconds'       => 1000,
        'miliseconds'   => 1
    );
 
    foreach ($grouplengths as $group => $mS) 
    {
      $timegroups[$group] = floor($interval / $mS);
      $interval = intval( $interval ) % $mS;
    }
 
    return sprintf(
        '%02d:%02d:%02d %03d',
        $timegroups['hours'],
        $timegroups['minutes'],
        $timegroups['seconds'],
        $timegroups['miliseconds']
    );
}

  /**
   * gets the ID of a page given it's slug
   *
   * this is to provide backwards-compatibility with previous versions that used a page-slug to point to the [pdb_record] page.
   * 
   * @global object $wpdb
   * @param string $page_slug slug or ID of a page or post
   * @param string $post_type name of the post type; defualts to page
   * @return string|bool the post ID; bool false if nothing found
   */
  public static function get_id_by_slug( $page_slug, $post_type = 'page' )
  {
    if ( is_numeric( $page_slug ) ) {
      $post = get_post( $page_slug );
    } else {
      $post = get_page_by_path( $page_slug );
    }

    if ( is_a( $post, 'WP_Post' ) ) {
      return $post->ID;
    }

    // fallback method
    global $wpdb;
    $id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_type= %s AND post_status = 'publish'", $page_slug, $post_type ) );

    return empty( $id ) ? false : $id;
  }

  /**
   * encodes or decodes a string using a simple XOR algorithm
   * 
   * @param string $string the tring to be encoded/decoded
   * @param string $key the key to use
   * @return string
   */
  public static function xcrypt( $string, $key = false )
  {
    if ( $key === false ) {
      $key = self::get_key();
    }
    $text = $string;
    $output = '';
    for ( $i = 0; $i < strlen( $text ); ) {
      for ( $j = 0; ($j < strlen( $key ) && $i < strlen( $text ) ); $j++, $i++ ) {
        $output .= $text[ $i ] ^ $key[ $j ];
      }
    }
    return $output;
  }

  /**
   * supplies a random alphanumeric key
   * 
   * the key is stored in a transient which changes every day
   * 
   * @return null
   */
  public static function get_key()
  {
    if ( !$key = Participants_Db::$session->get( PDb_CAPTCHA::captcha_key ) ) {
      $key = self::generate_key();
      Participants_Db::$session->set( PDb_CAPTCHA::captcha_key, $key );
    }
    //$key = Participants_Db::$session->get( PDb_CAPTCHA::captcha_key);
    //error_log(__METHOD__.' get new key: '.$key);
    return $key;
  }

  /**
   * returns a random alphanumeric key
   * 
   * @param int $length number of characters in the random string
   * @return string the randomly-generated alphanumeric key
   */
  private static function generate_key( $length = 8 )
  {

    $alphanum = self::get_alpha_set();
    $key = '';
    while ( $length > 0 ) {
      $key .= $alphanum[ array_rand( $alphanum ) ];
      $length--;
    }
    return $key;
  }

  /**
   * supplies an alphanumeric character set for encoding
   * 
   * characters that would mess up HTML are not included
   * 
   * @return array of valid characters
   */
  private static function get_alpha_set()
  {
    return str_split( 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890.{}[]_-=+!@#$%^&*()~`' );
  }

  /**
   * decodes the pdb_data_keys value
   * 
   * this provides a security measure by defining which fields to process in a form submission
   * 
   * @param string $datakey the pdb_data_key value
   * 
   * @return array of column names
   */
  public static function get_data_key_columns( $datakey )
  {

    return self::get_indexed_names( explode( '.', $datakey ) );
//    return self::get_indexed_names( explode('.', self::xcrypt($datakey)));
  }

  /**
   * sets the debug mode
   * 
   * plugin debuggin is going to be enabled if the debug setting is enabled, 
   * or if WP_DEBUG is true
   * 
   * 
   * @global PDb_Debug $PDb_Debugging
   */
  protected static function set_debug_mode()
  {
    global $PDb_Debugging;
    
    if ( !defined( 'PDB_DEBUG' ) ) 
    {
      $settings = get_option( Participants_Db::$participants_db_options );
      
      if ( isset( $settings[ 'pdb_debug' ] ) ) 
      {
        $debug_value = intval( $settings[ 'pdb_debug' ] );
      } 
      elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) 
      {
        $debug_value = 1;
      } 
      else 
      {
        $debug_value = 0;
      }
      
      define( 'PDB_DEBUG', $debug_value );
    }

    if ( PDB_DEBUG > 0 && !defined( 'WP_DEBUG' ) ) {
      define( 'WP_DEBUG', true );
    }

    if ( PDB_DEBUG && !is_a( $PDb_Debugging, 'PDb_Debug' ) ) {
      $PDb_Debugging = new PDb_Debug();
    }
  }

  /**
   * writes a debug log message
   * 
   * @global PDb_Debug $PDb_Debugging
   * @param string $message the debugging message
   * @param int $verbosity the verbosity level
   * @param string $group name of logging group the message belongs to
   */
  public static function debug_log( $message, $verbosity = 1, $group = 'general' )
  {
    if ( defined( 'PDB_DEBUG' ) && PDB_DEBUG >= $verbosity ) {
      global $PDb_Debugging;
      if ( $PDb_Debugging && method_exists( $PDb_Debugging, 'write_debug' ) ) {
        $PDb_Debugging->write_debug( $message, $group );
      } else {
        error_log( $message );
      }
    }
  }

  /**
   * provides the user's IP
   * 
   * this function provides a filter so that a different method can be used if the 
   * site is behind a proxy, firewall, etc.
   * 
   * @return string IP
   */
  public static function user_ip()
  {
    return self::apply_filters( 'user_ip', $_SERVER[ 'REMOTE_ADDR' ] );
  }
}
