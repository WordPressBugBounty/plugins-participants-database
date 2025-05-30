<?php

/**
 * models the column for use in assembling the main query
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2021  xnau webdesign
 * @license    GPL3
 * @version    1.0
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace PDb_submission\main_query;

use \Participants_Db,
    \PDb_Date_Parse;

defined( 'ABSPATH' ) || exit;

abstract class base_column {

  /**
   * @var \PDb_Field_Item current field item object
   */
  public $field;

  /**
   * @var string the incoming column value
   */
  protected $value;

  /**
   * @var string the unaltered incoming column value
   */
  protected $raw_value;
  
  /**
   * @var bool skip flag
   * 
   * if true, the column is not added to the query
   */
  protected $skip = false;

  /**
   * @param object $column the field definition data
   * @param string $value
   */
  public function __construct( $column, $value )
  {
    $this->field = new \PDb_Field_Item( $column );
    $this->raw_value = $value;
    $this->setup_value();
    $this->setup_readonly();
  }

  /**
   * provides the column's query clause
   * 
   * @return string
   */
  public function query_clause()
  {
    return "`" . $this->field->name() . "` = " . ( $this->value === null ? "NULL" : "%s" );
  }

  /**
   * provides the column value
   * 
   * @return string|int|bool
   */
  public function value()
  {
    return $this->value;
  }

  /**
   * provides the column value
   * 
   * @return string|int|bool
   */
  public function validation_value()
  {
    return $this->value;
  }
  
  /**
   * provides the column value from imported data
   * 
   * @return string|int|bool
   */
  public function import_value()
  {
    $import_value = $this->value === 'null' ? null : $this->value;
    
    if ( ! is_null( $import_value ) && $this->field->is_multi() ) {
      $import_value = serialize( \PDb_Field_Item::field_value_array( $this->value ) );
    }
    
    return $import_value;
  }

  /**
   * provides the main_query object
   * 
   * @return \PDb_submission\main_query\base_query
   */
  public function main_query()
  {
    return base_query::instance();
  }
  
  /**
   * tells if the incoming value should be added to the query
   * 
   * @param string $write_mode insert or update
   * @return bool
   */
  public function add_to_query( $write_mode )
  {
    return ! ( $this->skip || $this->skip_imported_value() );
  }

  /**
   * tells if the imported value should be skipped
   * 
   * @return bool true to skip the value on import
   */
  public function skip_imported_value()
  {
    if ( ! $this->main_query()->is_import() ) {
      return false;
    }
    // don't update the value if importing a CSV and the incoming value is empty #1647
    /**
     * @filter pdb-allow_imported_empty_value_overwrite
     * @param bool whether to skip or not
     * @param mixed the importing value
     * @param \PDb_Field_Item the current field
     * @return bool if true, skip importing the column
     */
    $allow = Participants_Db::apply_filters( 'allow_imported_empty_value_overwrite', 0, $this->value, $this->field );
    
    $skip = !$allow && $this->value === '';
    
    /**
     * @filter pdb-skip_imported_value
     * @param bool true to skip
     * @param \PDb_submission\main_query\base_column object
     * @return bool true to skip
     */
    return Participants_Db::apply_filters('skip_imported_value', $skip, $this );
  }
  
  /**
   * simple serialization detection
   * 
   * @param string $value
   * @return bool
   */
  protected function is_serialization( $value )
  {
    // we don't bother to check if it is a valid serialization
    // the filter is so an admin can craft their own serialization check if they want
    $pattern = \Participants_Db::apply_filters( 'serialization_check_regex', '/^[OsibNa]:/' );
    
    return is_string( $value ) && boolval( preg_match( $pattern, $value ) );
  }
  
  /**
   * sanitize for strings or arrays
   * 
   * this is intended to be applied before adding the value to a db query
   * 
   * @param string|array $initialvalue
   * @param bool $allow_array
   * @return string|array
   */
  protected function general_sanitize( $initialvalue, $allow_array = true )
  {    
    // sanitize out serializations; they are not allowed here #3095, #3098
    if ( $this->is_serialization(  $initialvalue ) )
    {
      $initialvalue = '';
    }

    if ( is_null( $initialvalue ) )
    {
      $returnvalue = null;
    } 
    elseif ( is_array( $initialvalue ) && $allow_array )
    {
      $returnvalue = Participants_Db::_prepare_array_mysql( $initialvalue );
    }
    else
    {
      if ( is_array( $initialvalue ) )
      {
        $initialvalue = implode( ', ', $initialvalue );
      }
      
      $value = Participants_Db::field_html_is_allowed( $this->field->name() ) ? wp_kses( trim( $initialvalue ), Participants_Db::allowed_html( 'post' ) ) : strip_tags( trim( $initialvalue ) );

      $returnvalue = Participants_Db::_prepare_string_mysql( $value );
    }

    return $returnvalue;
  }

  /**
   * sets the value property
   */
  protected abstract function setup_value();

  /**
   * checks for a readonly exception
   * 
   * this is for the purpose of preventing an unauthorized user from changing a 
   * read only value in the record
   */
  private function setup_readonly()
  {
    if (
            $this->field->is_readonly() &&
            !$this->field->is_hidden_field() &&
            \Participants_Db::current_user_has_plugin_role( 'editor', 'readonly access' ) === false &&
            \Participants_Db::apply_filters( 'post_action_override', filter_input( INPUT_POST, 'action', FILTER_SANITIZE_SPECIAL_CHARS ) ) !== 'signup' &&
            $this->main_query()->is_func_call() === false
    ) {
      $this->value = '';
    }
  }

}
