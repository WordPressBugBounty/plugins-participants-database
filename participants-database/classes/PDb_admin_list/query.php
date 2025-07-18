<?php

/**
 * provides the admin list query
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2020  xnau webdesign
 * @license    GPL3
 * @version    1.0
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace PDb_admin_list;

use \PDb_List_Admin;
use \Participants_Db;

defined( 'ABSPATH' ) || exit;

class query {

  /**
   * @var \PDb_admin_list\filter the admin list filter
   */
  private $filter;

  /**
   * @var bool holds the current parenthesis status used while building a query where clause
   */
  protected $inparens = false;

  /**
   * @var string holds the current list query
   */
  protected $list_query;
  
  /**
   * @var string the duplicate check operator
   */
  const dupcheck = '<=>';
  
  /**
   * @var string name of the list query transient
   */
  const query_store = 'pdb_amin_list_query';

  /**
   * sets up the object
   * 
   * @param array $filter the current list filter
   */
  public function __construct( $filter )
  {
    $this->filter = $filter;
    $this->_process_query();
  }

  /**
   * supplies the list query
   * 
   * @return string
   */
  public function query()
  {
    /**
     * @filter pdb-admin_list_query
     * @param string the current list query
     * @return string query
     */
    return Participants_Db::apply_filters( 'admin_list_query', $this->_query() );
  }

  /**
   * provides the result count for the current query
   * 
   * @global \wpdb $wpdb
   * @return int
   */
  public function result_count()
  {
    $cachekey = 'admin_list_count';
    $count = wp_cache_get( $cachekey );
    
    if ( $count === false ) {
      global $wpdb;

      $count_query = str_replace( '*', 'COUNT(*)', $this->query() );

      $count = $wpdb->get_var( $count_query );
      
      wp_cache_set( $cachekey, $count, '', Participants_Db::cache_expire() );
    }
    
    return $count;
  }
  
  /**
   * provides a list of record IDs from a query
   * 
   * @global \wpdb $wpdb
   * @param string $query a list query
   * @return array as $index => $record_id
   */
  public static function result_list( $query )
  {
    $cachekey = 'pdb_admin_result_list';
    
    $result_list = wp_cache_get( $cachekey );
    
    if ( false === $result_list )
    {
      global $wpdb;
      
      $sql = str_replace('SELECT * FROM', 'SELECT `id` FROM', $query );
      
      $result_list = $wpdb->get_col( $sql );
      
      wp_cache_set( $cachekey, $result_list, Participants_Db::cache_expire() );
    }
    
    return $result_list;
  }

  /**
   * provides the sanitized query
   * 
   * @global \wpdb $wpdb
   * @return string
   */
  private function _query()
  {
    delete_transient( self::query_store ); // clear the transient
    
    global $wpdb;
    $query = $wpdb->remove_placeholder_escape( $this->list_query );
    
    set_transient( self::query_store, $query );
    
    return $query;
  }

  /**
   * processes searches and sorts to build the listing query
   *
   * @param string $submit the value of the submit field
   */
  private function _process_query()
  {
    switch ( filter_input( INPUT_POST, 'submit-button', FILTER_SANITIZE_SPECIAL_CHARS ) ) {

      case PDb_List_Admin::$i18n[ 'clear' ] :
      case 'clear':
        $this->filter->reset();

      case PDb_List_Admin::$i18n[ 'sort' ]:
      case 'sort':
      case PDb_List_Admin::$i18n[ 'filter' ]:
      case 'filter':
      case PDb_List_Admin::$i18n[ 'search' ]:
      case 'search':
        // go back to the first page to display the newly sorted/filtered list
        $_GET[ PDb_List_Admin::$list_page ] = 1;

      default:

        $this->list_query = 'SELECT * FROM ' . Participants_Db::$participants_table . ' p ';

        if ( $this->filter->has_search() )
        {
          $this->list_query .= 'WHERE ';
          for ( $i = 0; $i <= $this->filter->count() - 1; $i++ )
          {
            if ( $this->filter->is_valid_set( $i ) )
            {
              $filter_set = $this->filter->get_set( $i );
              
              if ( $filter_set['operator'] === self::dupcheck )
              {
                $this->duplicate_check( $filter_set, $i );
              } 
              else
              {
                $this->_add_where_clause( $filter_set );
              }
            }
            if ( $i === $this->filter->count() - 1 )
            {
              if ( $this->inparens )
              {
                $this->list_query .= ') ';
                $this->inparens = false;
              }
            }
            elseif ( $this->filter->get_set( $i + 1 )[ 'search_field' ] !== 'none' && $this->filter->get_set( $i + 1 )[ 'search_field' ] !== '' && $this->filter->get_set( $i )[ 'operator' ] !== self::dupcheck )
            {
              $this->list_query .= $this->filter->get_set( $i )[ 'logic' ] . ' ';
            }
          }
          // if no where clauses were added, remove the WHERE operator
          if ( preg_match( '/WHERE $/', $this->list_query ) )
          {
            $this->list_query = str_replace( 'WHERE', '', $this->list_query );
          }
        }

        // add the sorting
        $this->list_query .= $this->sort_clause();
    }
  }
  
  /**
   * provides the sort clause
   * 
   * this sorts empty values at the end of the result
   * 
   * @return string
   */
  private function sort_clause()
  {
    $field_def = new \PDb_Form_Field_Def( $this->filter->sortBy );
    
    $sortclause = 'ORDER BY ';
    
    if ( is_a( $field_def, '\PDb_Form_Field_Def' ) && $field_def->form_element() !== 'timestamp') {
      $sortclause .= 'p.%1$s = "" OR p.%1$s IS NULL, ';
    }
    
    $sortclause .= 'p.%1$s %2$s';
    
    return sprintf( $sortclause, esc_sql( $this->filter->sortBy ), esc_sql( $this->filter->ascdesc ) );
  }

  /**
   * adds a where clause to the query
   * 
   * the filter set has the structure:
   *    'search_field' => name of the field to search on
   *    'value' => search term
   *    'operator' => mysql operator
   *    'logic' => join to next statement (AND or OR)
   * 
   * @param array $filter_set
   * @return null
   */
  protected function _add_where_clause( $filter_set )
  {
    if ( $filter_set[ 'logic' ] === 'OR' && !$this->inparens ) {
      $this->list_query .= ' (';
      $this->inparens = true;
    }
    $filter_set[ 'value' ] = str_replace( array( '*', '?' ), array( '%', '_' ), $filter_set[ 'value' ] );

    $delimiter = array( "'", "'" );

    switch ( $filter_set[ 'operator' ] ) {


      case 'gt':

        $operator = '>';
        break;

      case 'lt':

        $operator = '<';
        break;

      case '=':

        $operator = '=';
        
        if ( in_array( $filter_set['search_field'], search_field_group::group_list() ) ) {
          $operator = 'REGEXP';
          $delimiter = \PDb_List_Query::word_boundaries();
        }
        
        if ( $filter_set[ 'value' ] === '' ) {
          $filter_set[ 'value' ] = 'null';
        } elseif ( strpos( $filter_set[ 'value' ], '%' ) !== false ) {
          $operator = 'LIKE';
          $delimiter = array( "'", "'" );
        }
        break;
        
      case '!=':

        $operator = esc_sql( $filter_set[ 'operator' ] );
        
        if ( in_array( $filter_set['search_field'], search_field_group::group_list() ) ) {
          $operator = 'NOT REGEXP';
          $delimiter = \PDb_List_Query::word_boundaries();
        } elseif ( $filter_set[ 'value' ] === '' ) {
          $filter_set[ 'value' ] = 'null';
          $operator = '<>';
        } elseif ( $this->term_uses_wildcard( $filter_set ) ) {
          $delimiter = array( "'", "'" );
        }
        break;

      case 'NOT LIKE':
      case 'LIKE':
      default:

        $operator = esc_sql( $filter_set[ 'operator' ] );
        $delimiter = array( '"%', '%"' );
        
        if ( $filter_set[ 'value' ] === '' ) {
          $filter_set[ 'value' ] = 'null';
          $operator = '<>';
        } elseif ( $this->term_uses_wildcard( $filter_set ) ) {
          $delimiter = array( "'", "'" );
        }
    }

    $search_field = $this->get_search_field_object( $filter_set[ 'search_field' ] );

    $value = $this->field_value( $filter_set[ 'value' ], $search_field );

    if ( $search_field->form_element() === 'timestamp' ) {

      $value = $filter_set[ 'value' ];
      $value2 = false;
      if ( strpos( $filter_set[ 'value' ], ' to ' ) ) {
        list($value, $value2) = explode( 'to', $filter_set[ 'value' ] );
      }

      $value = \PDb_Date_Parse::timestamp( $value, array(), __METHOD__ . ' ' . $search_field->form_element() );
      if ( $value2 ) {
        $value2 = \PDb_Date_Parse::timestamp( $value2, array(), __METHOD__ . ' ' . $search_field->form_element() );
      }

      if ( $value !== false ) {

        $date_column = "DATE(" . $this->name_clause( $search_field ) . ")";

        if ( $value2 !== false ) {

          $this->list_query .= ' ' . $date_column . ' >= DATE(FROM_UNIXTIME(' . esc_sql( $value ) . ' + TIMESTAMPDIFF(SECOND, FROM_UNIXTIME(' . time() . '), NOW()))) AND ' . $date_column . ' <= DATE(FROM_UNIXTIME(' . esc_sql( $value2 ) . ' + TIMESTAMPDIFF(SECOND, FROM_UNIXTIME(' . time() . '), NOW())))';
        } else {

          if ( $operator == 'LIKE' )
            $operator = '=';

          $this->list_query .= ' ' . $date_column . ' ' . $operator . ' DATE(FROM_UNIXTIME(' . esc_sql( $value ) . ' + TIMESTAMPDIFF(SECOND, FROM_UNIXTIME(' . time() . '), NOW()))) ';
        }
      }
    } elseif ( $search_field->form_element() === 'date' ) {

      $value = $filter_set[ 'value' ];

      if ( $value === 'null' ) {

        $this->list_query .= $this->empty_value_where_clause( $filter_set[ 'operator' ], $search_field );
      } else {

        $value2 = false;
        if ( strpos( $filter_set[ 'value' ], ' to ' ) ) {
          list($value, $value2) = explode( 'to', $filter_set[ 'value' ] );
        }

        $date1 = \PDb_Date_Parse::timestamp( $value, array(), __METHOD__ . ' ' . $search_field->form_element() );
        
        $date2 = false;

        if ( $value2 ) {
          $date2 = \PDb_Date_Parse::timestamp( $value2, array(), __METHOD__ . ' ' . $search_field->form_element() );
        }

        if ( $date1 !== false ) {

          $date_column = $this->name_clause( $search_field );

          if ( $date2 !== false and ! empty( $date2 ) ) {

            $this->list_query .= " " . $date_column . " >= CAST(" . esc_sql( $date1 ) . " AS SIGNED) AND " . $date_column . " < CAST(" . esc_sql( $date2 ) . "  AS SIGNED)";
          } else {

            if ( $operator === 'LIKE' ) {
              $operator = '=';
            }

            $this->list_query .= " " . $date_column . " " . $operator . " CAST(" . esc_sql( $date1 ) . " AS SIGNED)";
          }
        }
      }
    }
    elseif ( $filter_set[ 'value' ] === 'null' )
    {
      $this->list_query .= $this->empty_value_where_clause( $filter_set[ 'operator' ], $search_field );
      
    } elseif ( $operator === '!=' ) {

      $operator = '<=>';
      $this->list_query .= ' NOT ' . $this->name_clause( $search_field ) . ' ' . $operator . " " . $delimiter[ 0 ] . esc_sql( $value ) . $delimiter[ 1 ];
      
    } else {

      $this->list_query .= ' ' . $this->name_clause( $search_field ) . ' ' . $operator . " " . $delimiter[ 0 ] . esc_sql( $value ) . $delimiter[ 1 ];
    }

    if ( $filter_set[ 'logic' ] === 'AND' && $this->inparens ) {
      $this->list_query .= ') ';
      $this->inparens = false;
    }

    $this->list_query .= ' ';
  }
  
  /**
   * sets up a duplicate check search
   * 
   * @param array $filter_set the filter parameters
   * @param int $index
   * @return null
   */
  private function duplicate_check( $filter_set, $index )
  {
    $pattern = ' INNER JOIN( SELECT %1$s FROM ' . Participants_Db::$participants_table . ' p GROUP BY p.%1$s HAVING COUNT(p.%1$s) > 1 ORDER BY p.%1$s) %2$s ON p.%1$s = %2$s.%1$s ';
    
    $this->list_query = str_replace( 'WHERE ', '', $this->list_query );
    
    $this->list_query .= sprintf( $pattern, $filter_set['search_field'], 'temp' . $index );
    
    // set up the sorting
    $this->filter->sortBy = $filter_set['search_field'];
  }

  /**
   * provides the where clause for a search for a blank or empty value
   * 
   * @param string $operator
   * @param \PDb_Form_Field_Def $search_field
   * @return string where clause
   */
  private function empty_value_where_clause( $operator, $search_field )
  {
    switch ( $operator ) {

      case '<>':
      case '!=':
      case 'NOT LIKE':
        $clause = ' (' . $this->name_clause( $search_field ) . ' IS NOT NULL' . $this->empty_value_phrase( $search_field, true ) . ')';
        $not = true;
        break;

      case 'LIKE':
      case '=':
      default:
        $clause = ' (' . $this->name_clause( $search_field ) . ' IS NULL' . $this->empty_value_phrase( $search_field, false ) . ')';
        $not = false;
        break;
    }
    
    if ( $search_field->form_element() === 'link' )
    {
      $empty_array = '"%i:0;s:0%"';
      $default_value = ( $not ? ' NOT ' : '' ) . $this->name_clause( $search_field ) . ' LIKE "%\"' . esc_sql( $search_field->default_value() ) . '\"%"';
      
      $logic = $not ? ' AND ' : ' OR ';
      
      $clause .= $logic . $this->name_clause( $search_field ) . ( $not ? ' NOT ' : '' ) . ' LIKE ' . $empty_array . $logic . $default_value;
    }

    return $clause;
  }

  /**
   * provides a field-specific empty value phrase
   * 
   * @param object $search_field
   * @param bool $not the clause logic
   * @retrun string
   */
  private function empty_value_phrase( $search_field, $not = false )
  {
    $clause = $not ? ' AND ' . $this->name_clause( $search_field ) . ' <> ""' : ' OR ' . $this->name_clause( $search_field ) . ' = ""';

    if ( $search_field->form_element() === 'date' ) {
      $clause .= $not ? ' AND ' . $this->name_clause( $search_field ) . ' <> 0' : ' OR ' . $this->name_clause( $search_field ) . ' = 0';
    }

    return $clause;
  }
  
  /**
   * provides the sanitized name clause
   * 
   * @param object $search_field
   * @return string
   */
  private function name_clause( $search_field )
  {
    return stripslashes( esc_sql( $search_field->name() ) );
  }

  /**
   * provides the search field object
   * 
   * @param string $name of the search field
   * @return object
   */
  private function get_search_field_object( $name )
  {
    if ( in_array( $name, search_field_group::group_list() ) ) {
      return search_field_group::get_search_group_object( $name );
    } else {
      return new \PDb_Form_Field_Def( $name );
    }
  }

  /**
   * provides the field value
   * 
   * provides the value unchanged if the field is not a value_set field or if the 
   * value does not match a defined value in the options
   * 
   * @param string $value
   * @param \PDb_Form_Field_Def $field
   * @return string
   */
  private function field_value( $value, $field )
  {
    if ( !$field->is_value_set() ) {
      return $value;
    }

    $options = $field->options();

    if ( isset( $options[ $value ] ) ) {
      return $options[ $value ];
    }

    return $value;
  }
  
  /**
   * tells if search term should have the enclosing wildcards removed
   * 
   * @param array $filter_set
   * @return bool true if the term should have the pre and post wildcards removed
   */
  private function term_uses_wildcard( $filter_set )
  {
    $field = new \PDb_Form_Field_Def( $filter_set['search_field'] );
    
    // fields that store their value as an array are exempt here #2856
    if ( $field->is_multi() ) {
      return false;
    }
    
    return $this->term_has_wildcard( $filter_set['value'] );
  }

  /**
   * tells if the search term contains a wildcard
   * 
   * @param string $term
   * @return bool true if there is a wildcard in the term
   */
  private function term_has_wildcard( $term )
  {
    return strpos( $term, '%' ) !== false || strpos( $term, '_' ) !== false;
  }

}
