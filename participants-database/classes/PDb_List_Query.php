<?php

/*
 * manages the query for the list
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2015 xnau webdesign
 * @license    GPL2
 * @version    2.5
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    Participants_Db class
 * 
 * This class provides the methods for building and altering the list query object. 
 * The class is designed to allow for a hierarchical series of filter to be applied, 
 * each overriding the next on a field-by-field basis.
 * 
 */
if ( !defined( 'ABSPATH' ) )
  die;

class PDb_List_Query {

  /**
   * @var array of where clause sets
   * 
   * array of where clause sets, indexed by the field name
   * 
   * array( $field_name => array(  List_Query_Filter object, ... ), ... )
   */
  private $subclauses = array();

  /**
   * @var array of sort terms
   */
  private $sort;

  /**
   * @var array of column names
   */
  private $columns;

  /**
   * @var bool true if current statement is whithin parnentheses
   */
  private $inparens;

  /**
   * @var bool true if the list is to be suppressed when no search is present
   */
  public $suppress;

  /**
   * @var int the total number of where clause elements
   */
  private $clause_count = 0;

  /**
   * this property is primarily used to set the values in the search controls of 
   * the user interface
   *
   * @var array of search fields and search values
   */
  public $filter;

  /**
   * @var \PDb_submission\list_search_post the submitted post
   */
  private $post_input;

  /**
   * 
   * @var string name of the query session value
   */
  static $query_session = 'list_query';

  /**
   * @var bool true if current query is a search result
   */
  private $is_search_result = false;

  /**
   * each clause, as it's added to the where_clauses property, is given a sequential 
   * index. This index is used to reconstruct the filters into their original sequence 
   * so parenthesization will follow from the original filter sequence
   * @version 1.6
   * 
   * @var int index number for a clause
   */
  private $clause_index = 0;

  /**
   * holds the instance index value of the instantiating list instance
   */
  private $instance_index;

  /**
   * the current module
   */
  private $module;

  /**
   * @var object the instantiating List class instance
   */

  /**
   * construct the object
   * 
   * @param PDb_List $List with structure:
   *    @param array  $shortcode_atts array of shortcode attributes
   *                    filter      a shortcode filter string
   *                    orderby     a comma-separated list of fields
   *                    order       a comma-separated list of sort directions, correlates 
   *                                to the $sort_fields argument
   *                    suppress    if true, the query should return zero results if no search is used
   *    @param array  $columns      an array of column names to use in the SELECT statement
   */
  function __construct( PDb_List $List )
  {
    /*
     *  internal filters for search term keys
     * 
     * search term keys are strings that stand for dynamic values
     */
    add_filter( 'pdb-raw_search_term', array( __CLASS__, 'process_search_term_keys' ) );

    $this->instance_index = $List->instance_index;
    $this->module = $List->module;
    $this->_reset_filters();
    $this->set_sort( $List->shortcode_atts[ 'orderby' ], $List->shortcode_atts[ 'order' ] );
    $this->_set_columns( $List->display_columns );
    $this->suppress = $List->attribute_true( 'suppress' );  // filter_var( $List->shortcode_atts['suppress'], FILTER_VALIDATE_BOOLEAN );
    $this->_add_filter_from_shortcode_filter( $List->shortcode_atts[ 'filter' ] );

    /*
     * the following configurations only apply to list and search shortcode pages, not API calls
     */
    if ( $this->module !== 'API' ):

      /*
       * at this point, the object has been instantiated with the properties provided 
       * in the shortcode
       * 
       * now, we process the GET and POST arrays to arrive at the final query structure 
       * 
       * a working GET request must contain these two values to return a search result:
       *    search_field - the name of the field to search in
       *    value - the search string, can be empty depending on settings
       * an operator value is optional:
       *    operator - one of the valid operators: ~,!,ne,eq,lt,gt
       * you cannot perform a search and specify a page number in the same GET request, 
       * the page number will get that page of the last query
       * 
       * a POST request will override any GET request on the same field, the two are 
       * not really meant to be combined
       */

      /**
       * disables searches in the URL
       * 
       * @filter pdb-allow_get_searches
       * @param bool default value
       * @return bool true to allow
       */
      if ( Participants_Db::apply_filters( 'allow_get_searches', true ) ) {
        
        $this->_add_filter_from_get();
      }
      
      $this->_add_filter_from_post();

      /*
       * if we're getting a paginated set of records, get the stored session, if not, 
       * and we are searching, save the session
       */
      if ( $this->requested_page() ) {
        // we're getting a list page
        $this->_restore_query_session();
      } elseif ( $this->is_search_result() ) {
        // we're showing a search result
        $this->_save_query_session();
      } else {
        // we're just showing the list with the shortcode parameters
        $this->_clear_query_session();
      }

    endif; // API check
  }

  /**
   * provides the completed mysql query
   * 
   * @return string the query
   */
  public function get_list_query()
  {
    $query = 'SELECT ' . $this->_column_select() . ' FROM ' . Participants_Db::participants_table() . ' p';
    $query .= $this->_where_clause();
    $query .= ' ORDER BY ' . $this->_order_clause();

    return $query;
  }

  /**
   * provides the completed mysql query for getting a records returned count
   * 
   * @return string the query
   */
  public function get_count_query()
  {
    $query = 'SELECT COUNT(*) FROM ' . Participants_Db::participants_table() . ' p';
    return $query . ' ' . $this->_where_clause();
  }

  /**
   * checks for user search errors
   * 
   * @return string|bool key string for the type of error or false if no error
   */
  public function get_search_error()
  {
    if ( $this->is_search_result() && is_object( $this->post_input ) && $this->post_input->is_search() ) {
      if ( empty( $this->post_input->search_field() ) ) {
        $this->is_search_result( false );
        return 'search';
      } elseif ( !$this->search_term_is_valid( $this->post_input->value() ) ) {
        $this->is_search_result( false );
        return 'value';
      }
    }
    return false;
  }
  
  /**
   * provides the current page selection from the post request
   * 
   * @return int
   */
  public function current_page()
  {
    return is_object( $this->post_input ) ? $this->post_input->current_page() : 1;
  }
  
  /**
   * provides the current instance index
   * 
   * @return int
   */
  public function instance_index()
  {
    return $this->instance_index;
  }

  /**
   * sets the sort property, given a set of fields and sort directions
   * 
   * @param array|string $fields array or comma-separated string of field names
   * @param array|string $ascdesc array or comma-separated list of sort direction strings
   * @return null
   */
  public function set_sort( $fields, $ascdesc )
  {
    $esc_ascdesc = function ( $asd ) {
      return strtolower( $asd ) === 'desc' ? 'DESC' : 'ASC';
    };
    $this->sort = array();
    $fields = $this->_to_array( $fields );
    $ascdesc = $this->_to_array( $ascdesc );
    $ascdesc_value = $esc_ascdesc( 'asc' );
    for ( $i = 0; $i < count( $fields ); $i++ ) {
      if ( !empty( $fields[ $i ] ) && PDb_Form_Field_Def::is_field( $fields[ $i ] ) || $fields[ $i ] === 'random' ) {
        $ascdesc_value = isset( $ascdesc[ $i ] ) ? $esc_ascdesc( $ascdesc[ $i ] ) : $ascdesc_value;
        $this->sort[ $fields[ $i ] ] = array(
            'field' => $fields[ $i ],
            'ascdesc' => $ascdesc_value,
        );
      }
    }
  }

  /**
   * provides current filter information
   * 
   * this is primarily used to populate the search form with submitted values
   * 
   * @param string $key the value to return, if not provided, returns the whole array
   * @return string|array value from the current filter
   */
  public function current_filter( $key = false )
  {
    $this->_setup_filter_array();
    reset( $this->sort );
    reset( $this->filter );
    $sort = current( $this->sort );
    $filter = array(
        'sort_field' => $sort[ 'field' ],
        'sort_order' => $sort[ 'ascdesc' ],
        'search_field' => current( $this->filter[ 'fields' ] ),
        'search_term' => current( $this->filter[ 'values' ] ),
        'search_fields' => $this->filter[ 'fields' ],
        'search_values' => $this->filter[ 'values' ],
        'sort_fields' => $this->sort,
    );
    if ( !$key ) {
      return $filter;
    } else {
      return isset( $filter[ $key ] ) ? $filter[ $key ] : '';
    }
  }

  /**
   * returns true if the current query is a search result
   * 
   * @param bool $set allows the value to be set by the instantiating class
   */
  public function is_search_result( $set = '' )
  {
    if ( is_bool( $set ) ) {
      $this->is_search_result = $set;
    }
    return $this->is_search_result;
  }

  /**
   * clears all filters from a field
   * 
   * @param string $fieldname
   */
  public function clear_field_filter( $fieldname )
  {
    $this->_remove_field_filters( $fieldname );
  }

  /**
   * provides current filter information for a field
   * 
   * returns the array of filters defined for a field
   * 
   * TODO: develop use cases for this public function to see if it's useful
   * 
   * @param string $field_name
   * @return array|bool of List_Query_Filter objects, false if none are defined
   */
  public function get_field_filters( $field_name = false )
  {
    if ( $field_name ) {
      return isset( $this->subclauses[ $field_name ] ) ? $this->subclauses[ $field_name ] : false;
    }
    return isset( $this->subclauses ) ? $this->subclauses : false;
  }

  /**
   * adds a filter to a field
   * 
   * @param string $field the field name
   * @param string $operator the filter operator
   * @param string $term the search term used (optional)
   * @param string $logic the AND|OR logic to use
   */
  public function add_filter( $field, $operator, $term, $logic = 'AND' )
  {
    $this->_add_single_statement(
            filter_var( $field, FILTER_DEFAULT, Participants_Db::string_sanitize() ),
            $this->_sanitize_operator( $operator ),
            filter_var( $term, FILTER_DEFAULT, array( 'flags' => FILTER_FLAG_STRIP_BACKTICK | FILTER_FLAG_NO_ENCODE_QUOTES ) ),
            ($logic === 'OR' ? 'OR' : 'AND' ),
            false
    );
  }

  /**
   * removes all foreground (user search) clauses for the query object
   * 
   * @return null
   */
  public function clear_foreground_clauses()
  {
    foreach ( $this->subclauses as $field => $clauses ) {
      foreach ( $clauses as $index => $filter ) {
        /** @var PDb_List_Query_Filter $filter */
        if ( $filter->is_search() ) {
          unset( $this->subclauses[ $field ][ $index ] );
        }
      }
    }
  }

  /**
   * removes background clauses from the where clauses for fields that have incoming searches
   * 
   * @param string $field the field name
   * @return null
   */
  public function clear_background_clauses( $field )
  {
    if ( isset( $this->subclauses[ $field ] ) && is_array( $this->subclauses[ $field ] ) ) {
      foreach ( $this->subclauses[ $field ] as $index => $clause ) {
        if ( $clause->is_shortcode() ) {
          unset( $this->subclauses[ $field ][ $index ] );
        }
      }
    }
  }

  /**
   * processes the search term keys for use in shortcode filters
   * 
   * @param string  $key the search term
   * @return string the search term to use
   */
  public static function process_search_term_keys( $key )
  {
    return Participants_Db::date_key($key);
  }

  /**
   * provides the list page value from the current request
   * 
   * 
   * @return int|bool page number, bool false if there is no page number in the current request  
   */
  private function requested_page()
  {
    $page_number = filter_input( INPUT_GET, Participants_Db::$list_page, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
    $instance_index = filter_input( INPUT_GET, 'instance', FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
    
    $page_number = is_int( $instance_index ) && $instance_index === $this->instance_index ? $page_number : false;
    
    if ( !$page_number ) {
      $page_number = filter_input( INPUT_POST, Participants_Db::$list_page, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
    }
    
    if ( !$page_number ) {
      return false;
    }
    
    return $page_number;
  }

  /**
   * adds where clauses and sort from the GET array
   * 
   * @return null
   */
  private function _add_filter_from_get()
  {
    $get_input = new PDb_submission\list_search_get();

    if ( $get_input->has_search() ) {
      $this->_add_filter_from_input( $get_input->submission() );
    }
  }

  /**
   * adds where clauses and sort from POST array
   * 
   * @return null
   */
  private function _add_filter_from_post()
  {
    // look for the identifier of the list search submission
    if ( filter_input( INPUT_POST, 'action', FILTER_DEFAULT, Participants_Db::string_sanitize() ) === Participants_Db::apply_filters( 'list_query_action', 'pdb_list_filter' ) ) {

      $this->post_input = new \PDb_submission\list_search_post;

      switch ( $this->post_input->submit_type() ) {
        case 'clear':
          $_GET[ Participants_Db::$list_page ] = 1;
          $this->is_search_result = false;
          $this->_clear_query_session();
          break;
        case 'search':
        case 'sort':
          $this->_add_filter_from_input( $this->post_input->submission() );
          break;
        case 'page':
          break;
      }
    }
  }

  /**
   * adds a filter statement from an input array
   * 
   * @param array $input from GET or POST array
   * @return null
   */
  private function _add_filter_from_input( $input )
  {
    $set_logic = Participants_Db::plugin_setting_is_true( 'strict_search' ) ? 'AND' : 'OR';

    $this->_reset_filters();
    
    if ( $input[ 'target_instance' ] == $this->instance_index ) {

      if ( is_array( $input[ 'search_field' ] ) ) {

        $current_field = '';

        foreach ( $input[ 'search_field' ] as $i => $search_field ) {

          if ( $current_field === '' || $search_field !== $current_field ) {

            $current_field = $search_field;
            $this->_remove_field_filters( $search_field );
          }

          if ( !$this->search_term_is_valid( $input[ 'value' ][ $i ] ) )
            continue;

          $logic = isset( $input[ 'logic' ][ $i ] ) ? $input[ 'logic' ][ $i ] : $set_logic;
          
          $this->_add_search_field_filter( $input[ 'search_field' ][ $i ], $input[ 'operator' ][ $i ], $input[ 'value' ][ $i ], $logic );
        }

        $this->is_search_result = true;
      } elseif ( !empty( $input[ 'search_field' ] ) && $this->search_term_is_valid( $input[ 'value' ] ) ) {

        $this->_remove_field_filters( $input[ 'search_field' ] );

        $logic = isset( $input[ 'logic' ] ) ? $input[ 'logic' ] : $set_logic;
        $this->_add_search_field_filter( $input[ 'search_field' ], $input[ 'operator' ], $input[ 'value' ], $logic );
      } elseif ( $input[ 'submit' ] !== 'clear' && empty( $input[ 'value' ] ) ) {

        // we set this to true even for empty searches
        $this->is_search_result = true;
      }

      if ( !empty( $input[ 'sortBy' ] ) ) {

        $this->set_sort( $input[ 'sortBy' ], $input[ 'ascdesc' ] );
      }

      $this->_save_query_session();
    }
  }

  /**
   * tests a search term for validity, checking against empty or wildcard-only terms
   * 
   * @param string|array $term the search term
   * @return bool true if the search term is valid
   */
  private function search_term_is_valid( $term )
  {
    $valid = false;
    foreach ( (array) $term as $t ) {
      $valid = strlen( trim( urldecode( $t ), '*?_%. ' ) ) > 0 || ( Participants_Db::plugin_setting_is_true( 'empty_search' ) && $t === '' );

      if ( !$valid ) {
        break;
      }
    }

    return Participants_Db::apply_filters( 'search_term_tests_valid', $valid, $term );
  }

  /**
   * adds the field filter
   * 
   * @param string  $search_field
   * @param string $operator
   * @param string $value
   * @param string $logic
   */
  private function _add_search_field_filter( $search_field, $operator, $value, $logic )
  {
    $logic = $logic ? ( strtoupper( $logic ) === 'OR' ? 'OR' : 'AND' ) : 'AND';
    $this->_add_single_statement( $search_field, $operator, urldecode( $value ), $logic );
    $this->is_search_result = true;
  }

  /**
   * provides the mysql where clause
   * 
   * returns a suppressing query if the list is to be suppressed
   * 
   * @return string the where clause
   */
  private function _where_clause()
  {

    $this->_count_clauses();

    $query = '';
    
    if ( $this->suppress && !$this->is_search_result ) {
      $query .= ' WHERE p.id = "0"';
    } elseif ( $this->clause_count > 0 ) {
      $query .= ' WHERE ' . $this->_build_where_clause();
    }
    return $query;
  }

  /**
   * provides a mysql where clause drawn from the where_clauses property
   * 
   * @return string
   */
  private function _build_where_clause()
  {
    $this->_reindex_subclauses();
    $subquery = '';
    $inparens = false;
    $clause_sequence = $this->_build_clause_sequence();

    /*
     * each element in the where_clauses property array is an array of statements 
     * acting on a single field; The key is the name of the field.
     */
    foreach ( $clause_sequence as $clause ) {

      /* @var $clause PDb_List_Query_Filter */

      /**
       * @todo fix the _reindex_subclauses method so it gets it right when there is only one clause
       */
      $last_clause = count( $clause_sequence ) === 1 ? true : $clause->index() === count( $clause_sequence ) - 1;

      if ( $clause->is_parenthesized() && !$inparens ) {
        $subquery .= '(';
        $inparens = true;
      }

      $subquery .= $clause->statement();

      if ( !$clause->is_parenthesized() && $inparens ) {
        $subquery .= ')';
        $inparens = false;
      }

      if ( !$last_clause ) {
        $subquery .= ' ' . ( $clause->is_or() ? 'OR' : 'AND' ) . ' ';
      }

      if ( $inparens && $last_clause ) {
        $subquery .= ')';
        $inparens = false;
      }
    }
    return $subquery;
  }

  /**
   * takes the where_clauses property and builds a sequence of clauses for processing
   * 
   * the where_clauses property is organized by field so that we can override the 
   * filters that are applied to a specific field, but the order in which the filters 
   * were submitted is important to correctly parenthesizing the resulting SQL, so 
   * before assembling the where clause SQL, we build a re-sequenced array of clauses 
   * according to how they were originally submitted
   * 
   * @return array a sequence of filter objects
   */
  private function _build_clause_sequence()
  {
    $sequence = array();
    foreach ( $this->subclauses as $field_clauses ) {
      foreach ( $field_clauses as $clause ) {
        $sequence[ $clause->index() ] = $clause;
      }
    }
    ksort( $sequence );
    return $sequence;
  }

  /**
   * provides an order clause
   * 
   * @return string mysql order clause
   */
  private function _order_clause()
  {
    if ( count( $this->sort ) === 0 ) {
      return '';
    }
    $subquery = array();
    $random = false;
    foreach ( $this->sort as $sort ) {
      extract( $sort ); // yields $field and $ascdesc
      if ( $field === 'random' ) {
        $random = true;
      }
      $subquery[] = $field . ' ' . $ascdesc;
    }
    if ( $random ) {
      return 'RAND()';
    } else {
      return 'p.' . implode( ', p.', $subquery );
    }
  }

  /**
   * sets the columns property
   * 
   * @param array $columns array of column names
   * @return null
   */
  private function _set_columns( $columns )
  {
    $this->columns = array();
    if ( is_array( $columns ) ) {
      
      // make sure the id column is first
      if ( in_array( 'id', $columns ) ) {
        unset($columns[array_search( 'id', $columns )]);
      }
      array_unshift( $columns, 'id' );

      foreach ( $columns as $fieldname ) {
//        $field = new PDb_Form_Field_Def($fieldname);
        if ( Participants_Db::get_field_def($fieldname)->stores_data() ) {
          $this->columns[] = $fieldname;
        }
      }
    }
  }

  /**
   * provides a column select string
   * 
   * @return string the mysql select string
   */
  private function _column_select()
  {
    if ( empty( $this->columns ) ) {
      return '*';
    }
    return 'p.' . implode( ', p.', $this->columns );
  }

  /**
   * clears the filter statements for a single column
   * 
   * @param string $column
   */
  private function _remove_field_filters( $column )
  {
    if ( isset( $this->subclauses[ $column ] ) ) {
      /*
       * @version 1.6.2.6
       * we don't remove indices so that new clauses will always be placed at the end of the queue
       */
      //$this->decrement_clause_index(count($this->subclauses[$column]));
      unset( $this->subclauses[ $column ] );
    }
  }

  /**
   * increment clause index
   * 
   * @param int $amount to increment
   */
  private function increment_clause_index( $amount = 1 )
  {
    $this->clause_index = $this->clause_index + $amount;
  }

  /**
   * increment clause index
   * 
   * @param int $amount to decrement
   */
  private function decrement_clause_index( $amount = 1 )
  {
    $this->clause_index = max( array( $this->clause_index - $amount, 0 ) );
  }

  /**
   * reindexes the subclauses by shifting all the values down so the index begins with zero
   * 
   * @return null 
   */
  private function _reindex_subclauses()
  {
    $diff = $this->clause_count - $this->clause_index;
    if ( $diff >= 0 ) {
      /**
       * @version 1.7.0.3
       * no need to reindex if the count is >= the index
       * 
       * this means that no clauses were removed from the sequence, therefore no 
       * reindexing is needed
       */
      return;
    }
    foreach ( $this->subclauses as $clauses ) {
      foreach ( $clauses as $clause ) {
        $index = $clause->index() + $diff;
        $clause->index( $index );
      }
    }
  }

  /**
   * resets the filter arrays
   * 
   * @return null
   */
  private function _reset_filters()
  {
    $this->filter[ 'fields' ] = array();
    $this->filter[ 'values' ] = array();
  }

  /**
   * adds values to the filter
   * 
   * @param string $field the field name
   * @param string $value the search value
   * @return null
   */
  private function _add_filter_value( $field, $value )
  {
    $this->filter[ 'fields' ][] = $field;
    $this->filter[ 'values' ][] = $value;
  }

  /**
   * sets up the filter values from the query object
   * 
   * @return null
   */
  private function _setup_filter_array()
  {
    $this->_reset_filters();

    foreach ( $this->subclauses as $field_name => $filters ) {
      foreach ( $filters as $filter ) {
        /*
         * include the filter if it is a search filter
         * 
         * shortcode filter statements are not included here; that would expose 
         * them as these values are used to populate the search form
         */
        if ( $filter->is_search() ) {
          $this->_add_filter_value( $field_name, $filter->get_raw_term() );
        }
      }
    }
  }

  /**
   * processes a shortcode filter string, adding the clauses to the query object
   * 
   * ampersands and pipes can be included in the filter statment, but they must 
   * be double-escaped in the shortcode
   * 
   * @param string $filter_string a shortcode filter string
   * @return null
   */
  private function _add_filter_from_shortcode_filter( $filter_string = '' )
  {
    if ( !empty( $filter_string ) ) {

      $statements = preg_split( '#(?<!\\\\)(&|\\|)#', $this->prep_filter_string( $filter_string ), -1, PREG_SPLIT_DELIM_CAPTURE );

      for ( $i = 0; $i < count( $statements ); $i = $i + 2 ) {

        $logic = isset( $statements[ $i + 1 ] ) && $statements[ $i + 1 ] === '|' ? 'OR' : 'AND';

        $this->_add_statement_from_filter_string( stripslashes( $statements[ $i ] ), $logic );
      }// each $statement
    }// done processing shortcode filter statements
  }
  
  /**
   * prepares a filter string for processing
   * 
   * @param string $filter_string
   * @return string
   */
  private function prep_filter_string( $filter_string )
  {
    /*
     * convert curly quote entities to straight quotes and converts curly quotes to straight quotes #2454
     */
    $filter_string = html_entity_decode( self::straighten_quotes( $filter_string ) );
    
    /*
     * trim the quotes only if the entire statement is enclosed #2755
     */
    if ( preg_match( '/^".+?"$/', $filter_string ) === 1 )
    {
      return trim( $filter_string, '"' );
    }
    else
    {
      return $filter_string;
    }
  }
  
  /**
   * straightens curly quotes
   * 
   * also converts single and double prime ticks
   * 
   * @param string $input
   * @return string with curly quotes converted to straight quotes
   */
  public static function straighten_quotes( $input )
  {
    return str_replace( 
            array( '&#8221;','&#8220;','&#8217;','&#8216;','&#8242;','&#8243;',chr(145),chr(146),chr(147),chr(148),'‘','’','“','”' ), 
            array( '"',      '"',      "'",      "'",      "'",      '"',     "'",    "'",     '"',     '"',      "'","'",'"','"' ), 
            $input );
  }

  /**
   * parses a shortcode filter statement string and adds it to the where_clauses property
   * 
   * typical filter string contains subject - operator - value
   * 
   * 
   * @param string $statement a single statement drawn from the shortcode filter string
   * @param string $logic the logic term to add to the array
   * @return null
   * 
   */
  private function _add_statement_from_filter_string( $statement, $logic = 'AND' )
  {

    $operator = preg_match( '#^(.+)(>=|<=|!=|!|>|<|=|!|~)(.*)$#U', $statement, $matches );

    if ( $operator === 0 )
      return false; // no valid operator; skip to the next statement
      
// get the parts
    list( $string, $column, $op_char, $search_term ) = $matches;

    $this->_add_single_statement( $column, $op_char, $search_term, $logic, true );
  }

  /**
   * adds a List_Query_Filter object to the where clauses
   * 
   * @param string $field_name  the name of the field to target
   * @param string $operator    the operator
   * @param string $search_term the term to filter by
   * @param string $logic       the logic term to add to the array
   * @param bool   $shortcode   true if the current filter is from the shortcode
   * @return null
   */
  private function _add_single_statement( $field_name, $operator, $search_term = '', $logic = 'AND', $shortcode = false )
  {
    /*
     * don't add an 'id = 0' clause if there is a user search. This gives us a 
     * way to create a "search results only" list if the shortcode contains 
     * a filter for 'id=0'
     * 
     * we flag it for suppression. Later, if there is no other clause for the ID 
     * column, the list display will be suppressed
     */
    if ( $field_name == 'id' and $search_term == '0' ) {
      $this->suppress = true;
      return false;
    }

    /*
     * possibly convert a date key
     */
    $search_term = $operator === '~' ? Participants_Db::date_key_string( $search_term ) : Participants_Db::date_key( $search_term );

    /**
     * if the search term is empty and it's not allowed in settings and not a shortcode 
     * filter, don't add the filter
     * 
     * @version 1.6.2.6
     */
    if ( $shortcode === false && strlen( $search_term ) === 0 && !Participants_Db::plugin_setting_is_true( 'empty_search', false ) ) {
      return false;
    }

    /*
     * check if we have a valid column name
     */
    if ( !PDb_Form_Field_Def::is_field( $field_name ) ) {
      
      Participants_Db::debug_log('Undefined field "' . $field_name . '" in list filter', 1 );
      
      return false;
    }

    $field_def = new PDb_Form_Field_Def( $field_name );

    // check to make sure we can query the field's value
    if ( !$field_def->stores_data() ) {
      return false;
    }

    /**
     * provides a way to alter the field def before it is used to create the where clause
     * 
     * @action pdb-query_statement_field_def
     * @param PDb_Form_Field_Def
     * @param PDb_List_Query current query object
     */
    do_action( 'pdb-query_statement_field_def', $field_def, $this );

    /**
     * if $parens_logic is true, "or" statements will be parenthesized
     * if false, 'and' statements will be parenthesized
     * 
     * @filter pdb-list_query_parens_logic
     * @version 1.6.2.6
     */
    $filter = new PDb_List_Query_Filter( array(
        'field' => $field_def->name(),
        'logic' => $logic,
        'shortcode' => $shortcode,
        'term' => $search_term,
        'index' => $this->clause_index,
        'parenthesis_logic' => Participants_Db::apply_filters( 'list_query_parens_logic', TRUE ),
            )
    );

    $this->increment_clause_index();

    $statement = false;

    $is_numeric = $field_def->is_numeric();

    // is the field value stored as an array?
    $is_multi = $field_def->is_multi();

    /*
     * set up special-case field types
     */
    if ( $filter->has_search_term() && in_array( $field_def->form_element(), array( 'date', 'timestamp' ) ) ) 
    {

      /*
       * if we're dealing with a date element, the target value needs to be 
       * conditioned to get a correct comparison
       */
      $search_term = PDb_Date_Parse::timestamp( $filter->get_raw_term(), [], __METHOD__ . ' ' . $field_def->form_element() . ' field' );

      $operator = in_array( $operator, [ '>', '<', '>=', '<=', '<>' ] ) ? $operator : '=';

      if ( $search_term === false )
      {
        // the search term doesn't parse as a date
        $statement = false;
      }
      elseif ( $field_def->form_element() === 'timestamp' )
      {
        $date_offset_query = 'FROM_UNIXTIME(' . $search_term . ' + TIMESTAMPDIFF(SECOND, FROM_UNIXTIME(' . current_datetime()->format( 'U' ) . '), UTC_TIMESTAMP()) )';
        
        switch ( $operator )
        {
          case '=':
            $statement = 'DATE(p.' . $field_def->name() . ') LIKE CONCAT( "%", DATE( ' . $date_offset_query . ' ), "%" )';
            break;
          
          case '<>':
            $statement = 'DATE(p.' . $field_def->name() . ') NOT LIKE CONCAT( "%", DATE( ' . $date_offset_query . ' ), "%" )';
            break;
          
          case '>':
          case '>=':
            $statement = 'p.' . $field_def->name() . ' > ' . $date_offset_query;
            break;
          
          case '<':
          case '<=':
            $statement = 'p.' . $field_def->name() . ' < ' . $date_offset_query;
            break;
        }

      }
      else
      {
        /*
         * if the date query was submitted on a standard search form, convert 
         * it to a 24-hour date range #2440
         */
        if ( /* is_object( $this->post_input ) && empty( $this->subclauses ) && */ $operator === '=' ) {
          $statement = 'p.' . $field_def->name() . ' >= CAST(' . $search_term . ' AS SIGNED) AND p.' . $field_def->name() . ' < CAST(' . strtotime( '+ 24 hours', $search_term ) . ' AS SIGNED)';
        } else {
          $statement = 'p.' . $field_def->name() . ' ' . $operator . ' CAST(' . $search_term . ' AS SIGNED)';
        }
      }
    }
    elseif ( $filter->is_empty_search() )
    {
      if ( in_array( $operator, array( 'NOT LIKE', '!', '!=' ) ) ) {
        $pattern = $is_numeric ? 'p.%1$s IS NOT NULL' : '(p.%1$s IS NOT NULL AND p.%1$s <> "")';
      } else {
        $pattern = $is_numeric ? 'p.%1$s IS NULL' : '(p.%1$s IS NULL OR p.%1$s = "")';
      }
      $statement = sprintf( $pattern, $field_def->name() );
    }
    else
    {

      if ( $operator === NULL )
        $operator = 'LIKE';

      /*
       * don't use string operators on numeric values
       */
      if ( $is_numeric ) {

        switch ( $operator ) {

          case 'LIKE':
          case '~':

            $operator = '=';
            break;

          case 'NOT LIKE':
          case '!':

            $operator = '<>';
            break;
        }
      }

      /*
       * change the operator if whole word match is enabled
       * 
       * or if the field value is stored as an array and strict search is enabled
       */
      if ( Participants_Db::apply_filters( 'whole_word_match_list_query', false ) || ( $is_multi && Participants_Db::plugin_setting_is_true( 'strict_search' ) ) )
      {
        // fields with values stored as arrays use word search if strict 
        if ( $is_multi ) {
          switch ( $operator ) {
            case '=':
            case 'eq':
              $operator = 'LIKE';
              break;
            case '!=':
            case 'ne':
              $operator = 'NOT LIKE';
              break;
          }
        }

        switch ( $operator ) {

          case 'LIKE':
          case '~':

            $operator = 'WORD';
            break;

          case 'NOT LIKE':
          case '!':

            $operator = 'NOT WORD';
            break;
        }
      }

      $delimiter = array( '"', '"' );
      $clause_pattern = 'p.%s %s %s%s%s';

      /*
       * set the operator and delimiters
       */
      switch ( $operator ) {

        case '~':
        case 'LIKE':

          $operator = 'LIKE';
          $delimiter = $filter->wildcard_present() ? array( '"', '"' ) : array( '"%', '%"' );
          break;

        case '!':
        case 'NOT LIKE':

          $operator = 'NOT LIKE';
          $delimiter = $filter->wildcard_present() ? array( '"', '"' ) : array( '"%', '%"' );
          break;

        case 'WORD':

          $operator = 'REGEXP';
          $delimiter = self::word_boundaries();
          $filter->is_regex = true;
          break;

        case 'NOT WORD':

          $operator = 'NOT REGEXP';
          $delimiter = self::word_boundaries();
          $filter->is_regex = true;
          break;

        case 'ne':
        case '!=':
        case '<>':

          $clause_pattern = 'NOT p.%s %s %s%s%s';
          $operator = '<=>';
          break;

        case 'eq':
        case '=':

          /*
           * if the field's exact value will be found in an array (actually a 
           * serialized array), we must prepare a special statement to search 
           * for the double quotes surrounding the value in the serialization
           */
          if ( $is_multi ) {
            $delimiter = array( '\'%"', '"%\'' );
            $operator = 'LIKE';
            /*
             * this is so the search term will be treated as a comparison string 
             * in a LIKE statement
             */
            $filter->like_term = true;
          } elseif ( $filter->wildcard_present() ) {
            $operator = 'LIKE';
          } else {
            $operator = '=';
          }
          break;
        /**
         * @since 1.6.3
         * 
         * added >= and <= operators
         */
        case 'gt':
        case '>':

          $operator = '>';
          break;

        case 'lt':
        case '<':

          $operator = '<';
          break;

        case 'gte':
        case '>=':

          $operator = '>=';
          break;

        case 'lte':
        case '<=':

          $operator = '<=';
          break;

        default:
          // invalid operator: don't add the statement
          return false;
      }

      $statement = sprintf( $clause_pattern, $field_def->name(), $operator, $delimiter[ 0 ], $filter->get_term(), $delimiter[ 1 ] );
    }

    if ( $statement ) {
      $filter->update_parameters( array( 'statement' => $statement ) );

      $this->subclauses[ $field_def->name() ][] = $filter;
    }
  }

  /**
   * provides the mysql word boundary codes
   * 
   * @global wpdb $wpdb
   * @return array
   */
  public static function word_boundaries()
  {
    global $wpdb;

    /**
     * @filter pdb-database_query_word_boundary_tags
     * @param array start tag and end tag
     * @return array
     */
    return Participants_Db::apply_filters( 'database_query_word_boundary_tags', version_compare( $wpdb->db_version(), '8.0.4', '<' ) ? array( '"[[:<:]]', '[[:>:]]"' ) : array( '"\\\b', '\\\b"' ) );
  }

  /**
   * counts the where clauses
   * 
   * @return null
   */
  private function _count_clauses()
  {
    $count = 0;
    foreach ( $this->subclauses as $field ) {
      if ( is_array( $field ) ) {
        foreach ( $field as $clause ) {
          $count++;
        }
      }
    }
    $this->clause_count = $count;
  }

  /**
   * converts a comma-separated string into an array
   * 
   * @param string $list
   * @return array
   */
  private function _to_array( $list )
  {
    if ( !is_array( $list ) ) {
      if ( is_string( $list ) ) {
        $list = explode( ',', str_replace( ' ', '', $list ) );
      } else {
        $list = array();
      }
    }
    return $list;
  }

  /**
   * tests for an empty value
   * 
   * @param array|string $value
   */
  public function is_empty( $value )
  {
    if ( is_array( $value ) ) {
      $value = implode( '', $value );
    }
    if ( !is_string( $value ) ) {
      $value = '';
    }
    return $value === '';
  }

  /**
   * saves the query object into a session variable
   * 
   * this is to support pagination
   * 
   * @return null
   */
  private function _save_query_session()
  {
    $this->_clear_query_session();

    $this->_count_clauses();

    $save = array(
        'where_clauses' => $this->subclauses,
        'sort' => $this->sort,
        'clause_count' => $this->clause_count,
        'is_search' => $this->is_search_result
    );
    Participants_Db::$session->set( $this->query_session_name(), $save );
  }

  /**
   * public method for saving the query session
   */
  public function save_query_session()
  {
    $this->_save_query_session();
  }

  /**
   * sets the query session
   * 
   * this is used to restore an instance from another target list
   * 
   * @param int $index the list instance to restore the query session from
   * 
   * @return bool false if no query session is found matching the supplied index
   */
  public function set_query_session( $index )
  {
    return $this->_restore_query_session();
  }

  /**
   * clears the query session
   * 
   * @return null
   */
  private function _clear_query_session()
  {
    Participants_Db::$session->clear( $this->query_session_name() );
  }

  /**
   * restores the query session
   * 
   * @return bool true if valid session was found
   */
  private function _restore_query_session()
  {
    $data = Participants_Db::$session->getArray( $this->query_session_name() );
    
    Participants_Db::debug_log(__METHOD__.' getting session: '.$this->query_session_name().' got: '.print_r($data,1), 3);

    if ( !is_array( $data ) ) {
      return false;
    }
    
    $where_clauses = $data[ 'where_clauses' ]; // do we need to unserialize here?
    $sort = $data[ 'sort' ];
    $this->clause_count = $data[ 'clause_count' ];
    $this->is_search_result = $data[ 'is_search' ];
    if ( is_array( $where_clauses ) ) {
      $this->subclauses = $where_clauses;
    }
    if ( is_array( $sort ) ) {
      $this->sort = $sort;
    }

    return true;
  }

  /**
   * adds the instance index value to the query session name
   * 
   * this is so multiple query session scan be stored and retrieved according to 
   * the instance index
   * 
   * @param int $index the index value if not using current instance
   * 
   * @return string
   */
  public function query_session_name( $index = '' )
  {
    return self::$query_session . '-' . ( empty( $index ) ? $this->instance_index : $index );
  }

  /**
   * sanitizes the operator value from a POST input
   * 
   * @param string $operator
   * @return string the sanitized operator
   */
  private function _sanitize_operator( $operator )
  {
    switch ( $operator ) {
      case '~':
      case 'LIKE':
        $operator = 'LIKE';
        break;
      case '!':
      case 'NOT LIKE':
        $operator = 'NOT LIKE';
        break;
      case 'ne':
      case '!=':
      case '<>':
        $operator = '<>';
        break;
      case 'eq':
      case '=':
        $operator = '=';
        break;
      case 'gt':
      case '>':
        $operator = '>';
        break;
      case 'lt':
      case '<':
        $operator = '<';
        break;
      case 'gte':
      case '>=':
        $operator = '>=';
        break;
      case 'lte':
      case '<=':
        $operator = '<=';
        break;
      case 'WORD':
        $operator = 'WORD';
        break;
      case 'NOT WORD':
        $operator = 'NOT WORD';
        break;
      default:
        $operator = '=';
    }
    return $operator;
  }

  /**
   * provides a filter array for a single-column filter
   * 
   * @return array
   */
  public static function single_search_input_filter()
  {
    $filter = array_merge( array(
        'value' => array(
            'filter' => FILTER_DEFAULT,
            'flags' => FILTER_FLAG_NO_ENCODE_QUOTES | FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_BACKTICK
        ),
        'search_field' => array(
            'filter' => FILTER_CALLBACK,
            'options' => array( __CLASS__, 'prepare_search_field' )
        ),
        'operator' => array(
            'filter' => FILTER_CALLBACK,
            'options' => array( __CLASS__, 'sanitize_operator' )
        ),
            ), self::_common_search_input_filter()
    );
    
    return $filter;
  }

  /**
   * provides a filter array for a multiple-column filter
   * 
   * @return array
   */
  public static function multi_search_input_filter()
  {
    $array_filter = array(
        'filter' => FILTER_DEFAULT,
        'flags' => FILTER_FLAG_NO_ENCODE_QUOTES | FILTER_REQUIRE_ARRAY | FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_BACKTICK,
    );
    $filter = array_merge( array(
        'value' => $array_filter,
        'search_field' => array(
            'filter' => FILTER_CALLBACK,
            'flags' => FILTER_REQUIRE_ARRAY,
            'options' => array( __CLASS__, 'prepare_search_field' )
        ),
        'operator' => array(
            'filter' => FILTER_CALLBACK,
            'flags' => FILTER_REQUIRE_ARRAY,
            'options' => array( __CLASS__, 'sanitize_operator' )
        ),
        'logic' => $array_filter,
            ), self::_common_search_input_filter()
    );
    
    return $filter;
  }

  /**
   * supplies a common input filter array
   * 
   * @return array
   */
  private static function _common_search_input_filter()
  {
    $filter = array(
        'submit' => array( 'filter' => FILTER_DEFAULT ) + Participants_Db::string_sanitize(),
        'submit_button' => array( 'filter' => FILTER_DEFAULT ) + Participants_Db::string_sanitize(),
        'submit-button' => array( 'filter' => FILTER_DEFAULT ) + Participants_Db::string_sanitize(),
        'ascdesc' => array( 'filter' => FILTER_DEFAULT ) + Participants_Db::string_sanitize(),
        'sortBy' => array( 'filter' => FILTER_DEFAULT ) + Participants_Db::string_sanitize(),
        Participants_Db::$list_page => array(
            'filter' => FILTER_VALIDATE_INT,
            'options' => array(
                'min_range' => 1
            )
        ),
        'target_instance' => array(
            'filter' => FILTER_VALIDATE_INT,
            'options' => array(
                'min_range' => 1
            )
        ),
    );
    
    return $filter;
  }

  /**
   * prepares the search_field value
   * 
   * if the data comes in from an AJAX request, it will be url-encoded, so we decode it first
   * 
   * we rely on $wpdb->prepare for sanitizing
   * 
   * @param string $field the name of a field
   * @return string the sanitized value
   */
  public static function prepare_search_field( $field )
  {
    if ( $field === 'none' ) {
      $field = '';
    }
    return strtolower( urldecode( $field ) );
  }

  /**
   * filters the allowed operators for a GET search
   * 
   * @param string $operator
   * @return bool true if allowed
   */
  public static function sanitize_operator( $operator )
  {
    switch ( urldecode( $operator ) ) {
      case '<':
      case 'lt':
        return 'lt';
      case '>':
      case 'gt':
        return 'gt';
      case '=':
      case 'eq':
        return 'eq';
      case 'ne':
      case '<>':
      case '!=':
        return 'ne';
      case '~':
      case 'LIKE':
      case 'like':
        return 'LIKE';
      default:
        return '';
    }
  }

}
