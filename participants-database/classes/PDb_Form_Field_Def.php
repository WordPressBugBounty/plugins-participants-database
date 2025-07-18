<?php

/**
 * models a form field definition
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2018  xnau webdesign
 * @license    GPL3
 * @version    1.8
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */
defined( 'ABSPATH' ) || exit;

class PDb_Form_Field_Def {
  
  /**
   * @var string holds the name of the field definition query result cache
   */
  const def_cache = 'pdb-field_def';

  /**
   * @var int id of the field definition
   */
  protected $id;

  /**
   * @var int order value of the field definition
   */
  protected $order;

  /**
   * @var string name of the field
   */
  protected $name;

  /**
   * @var string display title of the field
   */
  protected $title;

  /**
   * @var string default value of the field
   */
  protected $default;

  /**
   * @var string name of the field's group
   */
  protected $group;

  /**
   * @var string title of the field's group
   */
  protected $grouptitle;

  /**
   * @var int id of the field's group
   */
  protected $groupid;
  
  /**
   * @var string the field group visibility mode
   */
  protected $mode;
  
  /**
   * @var int the visibility mode for a participant log field
   */
  protected $visibility;
  
  /**
   * @var bool the searchability mode for a participant log field
   */
  protected $searchable;
  
  /**
   * @var string field help text
   */
  public $help_text;

  /**
   * @var string form element of the field
   */
  protected $form_element;

  /**
   * @var string raw string from the "values" parameter of the field def
   */
  protected $values;

  /**
   * @var string validation type
   */
  public $validation;

  /**
   * @var int display column value
   */
  protected $display_column;

  /**
   * @var int admin column value
   */
  protected $admin_column;

  /**
   * @var bool field is sortable
   */
  public $sortable;

  /**
   * @var bool field is included in CSV export
   */
  public $csv;

  /**
   * @var bool field is persistent
   */
  public $persistent;

  /**
   * @var bool field is included in the signup form
   */
  public $signup;

  /**
   * @var bool field is read only
   */
  protected $readonly;
  
  /**
   * @var string holds the validation message
   */
  public $validation_message;

  /**
   * @var array element attributes
   */
  public $attributes = [];

  /**
   * @var array element options
   */
  public $options = [];

  /**
   * instantiates the object
   * 
   * @param stdClass|string $field the field definition or field name
   */
  public function __construct( $field )
  {
    $field_def = is_string( $field ) ? self::get_field_def( $field ) : self::get_field_def( $field->name );

    if ( $field_def ) 
    {
      $this->assign_def_props( $field_def, $field );
    }
  }

  /**
   * provides an instance of the object, given a field name
   * 
   * @param string  $fieldname
   * @return PDb_Form_Field_Def|bool false if no field matches the name given
   */
  public static function instance( $fieldname )
  {
    $result = self::get_field_def( $fieldname );
    return $result ? new self( $result ) : false;
  }

  /**
   * tells if the field def is in the db
   * 
   * @global wpdb $wpdb
   * @param string  $fieldname
   * @param bool $main_only true if only field from main plugin
   * @return bool
   */
  public static function is_field( $fieldname, $main_only = false )
  {
    if ( ! is_string($fieldname) ) 
    {
      return false;
    }
    
    $cachekey = 'pdb-field_list' . strval($main_only);
    $field_list = wp_cache_get( $cachekey );
    
    if ( ! $field_list ) {
      
      global $wpdb;
      
      $sql = 'SELECT v.name 
              FROM ' . Participants_Db::$fields_table . ' v
              JOIN ' . Participants_Db::$groups_table . ' g ON g.name = v.group';
      
      if ( $main_only ) {
        $sql .= ' WHERE g.mode IN ("admin","private","public")';
      }
      
      $field_list = $wpdb->get_col( $sql );
      
      wp_cache_set( $cachekey, $field_list, '', Participants_Db::cache_expire() );
    }
    
    return in_array( $fieldname, $field_list );
  }
  
  /**
   * tells if the field is in the main plugin fields
   * 
   * @param string $fieldname
   * @return bool
   */
  public static function is_main_field( $fieldname )
  {
    return self::is_field($fieldname, true);
  }

  /**
   * supplies the field definition data from the db
   * 
   * @global wpdb $wpdb
   * @param string  $fieldname
   * @return object (empty object if no field matches the name provided)
   */
  private static function get_field_def( $fieldname )
  {
    $field_defs = Participants_Db::all_field_defs();
      
    $def = isset( $field_defs[$fieldname] ) ? $field_defs[$fieldname] : new stdClass();
    
    /**
     * provides a way to temporarily change a field's definition
     * 
     * @filter pdb-raw_field_definition
     * @param stdClass field definition properties
     * @param string fieldname
     * @return stdClass
     */
    return Participants_Db::apply_filters( 'raw_field_definition', $def, $fieldname );
  }
  
  /**
   * tells if the field stores a value in the db
   * 
   * if the form_element definition has a non-empty datatype, then it stores 
   * its value in the db
   * 
   * @return bool
   */
  public function stores_data()
  {
    $datatype = Participants_Db::apply_filters('form_element_datatype', xnau_FormElement::get_datatype($this->form_element()), $this->form_element() );
    return $datatype !== '';
  }
  
  /**
   * tells if the field has a column in the main db
   * 
   * this should be compatible with SQLite #3082
   * 
   * @global \wpdb $wpdb
   * @param string $fieldname
   * @return bool true if the field has a db column
   */
  public static function has_db_column( $fieldname )
  {
    global $wpdb;
    
    $sql = 'SELECT `' . $fieldname . '` FROM ' . Participants_Db::$participants_table;
    
    $wpdb->suppress_errors( true );
    $wpdb->get_col( $sql );
    $wpdb->suppress_errors( false );
    
    return strpos( strval( $wpdb->last_error ), 'Unknown column' ) === false;
  }
  
  /**
   * provides the value of the named property
   * 
   * this is an an explicit version of the __get magic method
   * 
   * @param string $prop property name
   * @return mixed null if property method does not exist
   */
  public function get_prop( $prop )
  {
    switch ( $prop ) 
    {
      case 'form_element':
      case 'default_value':
      case 'name':
      case 'group':
      case 'options':
      case 'attributes':
      case 'help_text':
      case 'validation':
      case 'validation_message':
        return $this->{$prop}();
        
      case 'sortable':
      case 'csv':
      case 'persistent':
      case 'signup':
      case 'readonly':
        return $this->{'is_' . $prop}();
        
      case 'default':
        return $this->default_value();
        
      case 'id':
      case 'order':
      case 'title':
      case 'grouptitle':
      case 'groupid':
        return $this->{$prop};
        
      default:
        return null;
    }
  }

  /**
   * provides a property value
   * 
   * @param string  $prop of the property
   * @return mixed the property value
   */
  public function __get( $prop )
  {
    return $this->get_prop( $prop );
  }

  /**
   * sets a property value
   * 
   * @param string $prop name of the property to set
   * @paam mixed $value
   * 
   */
  public function __set( $prop, $value )
  {
    switch ( $prop ) {
      case 'value':
        $this->{$prop} = $value;
        break;
      default:
        if ( property_exists( $this, $prop ) )
        {
          $this->{$prop} = $value;
        }
        else
        {
          Participants_Db::debug_log('Accessing unknown property "' . $prop . '" in ' . get_class($this) . '  TRACE: '.print_r( wp_debug_backtrace_summary(),1), 2 );
          $this->{$prop} = $value;
        }
    }
  }

  /**
   * makes the field a read-only field
   * 
   * @param bool $readonly if true, makes the field a readonly field, if false, makes the field a public writable field
   */
  public function make_readonly( $readonly = true )
  {
    if ( $readonly ) 
    {
      $this->readonly = true;
      $this->form_element = 'text-line';
    } 
    else 
    {
      $this->readonly = false;
    }
  }

  /**
   * sets the readonly state
   * 
   * @param bool $state defaults to true, sets the readonly state
   */
  public function set_readonly( $state = true )
  {
    $this->readonly = (bool) $state;
  }
  
  /**
   * sets the form_element property
   * 
   * @param string $form_element
   */
  public function set_form_element( $form_element )
  {
    if ( PDb_FormElement::is_form_element( $form_element ) ) 
    {
      $this->form_element = esc_sql($form_element);
    }
  }

  /**
   * provides the name of the field
   * 
   * @return string
   */
  public function name()
  {
    return $this->name;
  }

  /**
   * provides the field title
   * 
   * @return string
   */
  public function title()
  {
    return Participants_Db::apply_filters( 'translate_string', $this->title );
  }

  /**
   * provides the default value for the field
   * 
   * @return string
   */
  public function default_value()
  {
    if ( ! $this->has_default() ) {
      return '';
    }
    
    if ( $this->is_dynamic_field() || ( $this->is_hidden_field() && Participants_Db::is_dynamic_value( $this->default ) ) ) 
    {
      return empty( $this->default ) ? '' : htmlspecialchars_decode( $this->default );
    }
    
    return Participants_Db::apply_filters( 'translate_string', $this->default );
  }
  
  /**
   * provides the default display string
   * 
   * @return string
   */
  public function default_display()
  {
    $default = $this->default_value();
    
    if ( $this->is_dynamic_field() || $this->form_element === 'link' ) 
    {
      // dynamic fields don't display the default value
      // link fields use the default value as a link wrapper only
      $default = '';
    }
    
    /**
     * provides a way to set the default value
     * 
     * this is the value used to pad a new record with default values
     * 
     * @filter pdb-field_default_display
     * @param string configured field default
     * @param PDb_Form_Field instance
     * @return string
     */
    return Participants_Db::apply_filters( 'field_default_display', $default, $this );
  }

  /**
   * provides the validation type field
   * 
   * @return string
   */
  public function validation()
  {
    return $this->validation;
  }

  /**
   * provides the validation message for the field
   * 
   * @return string
   */
  public function validation_message()
  {
    return Participants_Db::apply_filters( 'translate_string', $this->validation_message );
  }

  /**
   * tells the field's form element
   * 
   * @return string name of the form element
   */
  public function form_element()
  {
    return $this->form_element;
  }

  /**
   * provides the display title of the field's form element
   * 
   * @return string title for the form element
   */
  public function form_element_title()
  {
    $types = PDb_FormElement::get_types();
    
    return  isset( $types[$this->form_element] ) ? $types[$this->form_element] : $this->form_element;
  }

  /**
   * tells the field group
   * 
   * @return string
   */
  public function group()
  {
    return $this->group;
  }

  /**
   * tells the field group
   * 
   * @return string
   */
  public function group_title()
  {
    if ( ! empty($this->grouptitle) ) 
    {
      return Participants_Db::apply_filters( 'translate_string', $this->grouptitle );
    }
    
    return $this->group;
  }
  
  /**
   * provides the field options
   * 
   * this is really only valid with form elements that have options
   * 
   * @retrun array of options as $title => $value
   */
  public function options()
  {
    return (array) $this->options;
  }

  /**
   * provides the field options
   * 
   * alias of options method for backward compatibility
   * 
   * @retrun array of options as $title => $value
   */
  public function values()
  {
    return $this->options();
  }

  /**
   * provides an array of option values
   * 
   * this provides an indexed array of just the values, titles are ignored
   * 
   * @return array of option values
   */
  public function option_values()
  {
    return array_values( $this->options );
  }

  /**
   * provides the attributes array
   * 
   * these are typically printed in the HTML as attributes to the main tag
   * 
   * @return array as $name => $value
   */
  public function attributes()
  {
    /**
     * @filter pdb-form_field_attributes
     * @param array the field attributes
     * @param PDb_Form_Field_Def current instance
     * @return array as $name => $value
     */
    return Participants_Db::apply_filters( 'form_field_attributes', $this->attributes, $this );
  }
  
  /**
   * provides the named attribute value
   * 
   * @param string $name name of the attribute to get
   * @return string empty string if the attribute is not set
   */
  public function get_attribute( $name )
  {
    return $this->has_attribute( $name ) ? $this->attributes[$name] : '';
  }
  
  /**
   * tells if the field has the named attribute value
   * 
   * @param string $name name of the attribute to get
   * @return bool
   */
  public function has_attribute( $name )
  {
    return isset( $this->attributes[$name] );
  }
  
  /**
   * provides the allowed extensions attribute for a file upload field
   * 
   * @return array
   */
  public function allowed_extensions()
  {
    if ( $this->get_attribute('allowed') === '' ) {
      
      /* if the depricated style of setting is present
       * it will show up as an array where the key and value are identical
       */
      $extensions_array = array_filter($this->attributes(), function ($k,$v) {
        return $k === $v;
      }, ARRAY_FILTER_USE_BOTH );
      
    } else {
      
      $extensions_array = explode( '|', strtolower( $this->get_attribute('allowed') ) );
    }
    
    return array_filter( $extensions_array );
  }
  
  /**
   * provides the help text
   * 
   * @return string
   */
  public function help_text()
  {
    return Participants_Db::apply_filters( 'translate_string', $this->help_text );
  }

  /**
   * provides a "value title" if defined
   * 
   * @param string  $value
   * @return string the value or it's defined title
   */
  public function value_title( $value )
  {
    if ( $this->is_value_set() ) {
      
      $key = array_search( $value, $this->options );
      
      if ( $key ) {
        $value = Participants_Db::apply_filters( 'translate_string', stripslashes( $key ) );
      }
    }
    return $value;
  }

  /**
   * tells if the field is readonly
   * 
   * @return bool
   */
  public function is_readonly()
  {
    return (bool) $this->readonly;
  }
  
  /**
   * tells if the field is some kind of file upload field
   * 
   * @return bool
   */
  public function is_upload_field()
  {
    /**
     * @filter pdb-upload_field_types
     * @param array of upload field form element names
     * @return array
     */
    $upload_fields = Participants_Db::apply_filters( 'upload_field_types', ['file-upload', 'image-upload'] );
    
    return in_array( $this->form_element(), $upload_fields );
  }

  /**
   * tells if the field is an internal field
   * 
   * @return bool true if it is an internal field
   */
  public function is_internal_field()
  {
    return $this->group === 'internal';
  }

  /**
   * tells if the field is a hidden field
   * 
   * @return bool true if it is an internal field
   */
  public function is_hidden_field()
  {
    return $this->form_element === 'hidden';
  }

  /**
   * tells if the form element is a "value set" type element
   * 
   * @return bool
   */
  public function is_value_set()
  {
    return PDb_FormElement::is_value_set( $this->form_element );
  }
  
  /**
   * tells if the current field is a dynamic field that uses the default value as a template
   * 
   * @return bool true if the field uses a template
   */
  public function is_templated_field()
  {
    return in_array( $this->form_element, Participants_Db::apply_filters( 'templated_field_list', ['string-combine', 'numeric-calc', 'date-calc'] ) );
  }

  /**
   * tells if the field is a dynamic hidden field
   * 
   * @return bool true if the field is a dynamic hidden field
   */
  public function is_dynamic_hidden_field()
  {
    if ( ! $this->is_hidden_field() ) {
      return false;
    }
    return  Participants_Db::is_dynamic_value( $this->default );
  }

  /**
   * tells if the field is a dynamic field
   * 
   * @return bool true if the field uses the default value to generate its value dynamically
   */
  public function is_dynamic_field()
  {
    $registered_dynamic = in_array( $this->form_element, Participants_Db::apply_filters( 'dynamic_field_list', ['date'] ) );
    
    return $registered_dynamic || $this->is_dynamic_hidden_field();
  }

  /**
   * tells if the field is the single record link field
   * 
   * @return bool
   */
  public function is_single_record_link_field()
  {
    return !in_array( $this->form_element, ['rich-text', 'link'] ) && Participants_Db::is_single_record_link( $this );
  }

  /**
   * tells if the field has the persistent flag set
   * 
   * @return bool
   */
  public function is_persistent()
  {
    return (bool) $this->persistent;
  }
  
  /**
   * tells if the field is sortable
   * 
   * @return bool
   */
  public function is_sortable()
  {
    return (bool) $this->sortable;
  }
  
  /**
   * tells if the field is read only
   * 
   * alias for is_readonly
   * 
   * @return bool
   */
  public function is_read_only()
  {
    return $this->is_readonly();
  }
  
  /**
   * tells if the field is to be included in the signup form by default
   * 
   * @return bool
   */
  public function is_signup()
  {
    return (bool) $this->signup;
  }
  
  /**
   * tells if the field is to be included in the csv export
   * 
   * alias
   * 
   * @return bool
   */
  public function is_csv()
  {
    return $this->is_csv_exported();
  }
  
  /**
   * tells if the field is to be included in the csv export
   * 
   * @return bool
   */
  public function is_csv_exported()
  {
    return (bool) $this->csv;
  }
  
  /**
   * tells if the field is a date field
   * 
   * This is a field that saves its value as a unix timestamp
   */
  public function is_date_field()
  {
    /**
     * @filter pdb-is_date_form_element
     * @param bool true if the field is a date form element
     * @param PDb_Form_Field_Def this field definition
     * @return bool
     */
    return Participants_Db::apply_filters( 'is_date_form_element',  strpos( $this->form_element, 'date' ) !== false, $this );
  }

  /**
   * tells if the title (field label) is empty
   * 
   * @return bool true if there is a title string defined
   */
  public function has_title()
  {
    return strlen( $this->title ) > 0;
  }

  /**
   * tells if the field has a default value
   * 
   * @return bool true if the default is non-empty
   */
  public function has_default()
  {
    return !is_null( $this->default ) && strlen( $this->default ) > 0;
  }

  /**
   * tells if the field has a default value
   * 
   * @return bool true if the default is non-empty
   */
  public function has_validation_message()
  {
    return strlen( $this->validation_message ) > 0;
  }
  
  /**
   * tells if the current field can have an anchor tag wrapped around it
   * 
   * @return bool
   */
  public function is_linkable()
  {
    return PDb_FormElement::field_is_linkable( $this );
  }
  
  /**
   * tells of the field is marked as searchable
   * 
   * @return bool
   */
  public function is_searchable()
  {
    // main fields don't yet have the ability to be marked as searchable #2661
    return self::is_main_field($this->name) || $this->searchable;
  }

  /**
   * tells if the field is a multi-type field
   * 
   * this is a field that has multiple values and stores it's value in the db as 
   * a serialized array
   * 
   * @return bool
   */
  public function is_multi()
  {
    return PDb_FormElement::is_multi( $this->form_element );
  }
  
  /**
   * tells if the field validation is a match to another field
   * 
   * @return bool
   */
  public function is_match_validation()
  {
    return self::is_field( $this->validation );
  }
  
  /**
   * tells if the field's value is to be treated as a number
   * 
   * @return bool
   */
  public function is_numeric()
  {
    /**
     * makes it possible to register a custom form element as numeric
     * 
     * @filter pdb-numeric_fields
     * @param array of numeric form element names
     * @return array
     */
    return in_array( $this->form_element, Participants_Db::apply_filters('numeric_fields', [
        'numeric',
        'decimal',
        'currency',
        'date',
        'numeric-calc',
    ]));
  }

  /**
   * provides the values array
   * 
   * this is the contents of the "values" parameter of the definition
   * 
   * this is not the preferred way to get options or attributes, but it is here 
   * for edge cases
   * 
   * @return array
   */
  public function values_array()
  {
//    $calling_class = $this->get_calling_class();
//    if ( $calling_class !== get_class() ) {
//      error_log(__METHOD__.' calling deprecated method: '. print_r( wp_debug_backtrace_summary(),1 ) );
//    }
    return Participants_Db::unserialize_array( $this->values );
  }
  
  /**
   * tells if the field has data stored in the main db table
   * 
   * @return bool true if data has been stored for this field
   */
  public function has_stored_data()
  {
    return PDb_Field_Editor::db_column_has_data( $this->name );
  }
  
  /**
   * tells if the field is an "other" type selector
   * 
   * @return bool
   */
  public function has_other_option()
  {
    return strpos( $this->form_element, 'other' ) !== false;
  }
  
  /**
   * tells if the field is a calculated field
   * 
   * @return bool
   */
  public function is_calculated()
  {
    return in_array( $this->form_element, Participants_Db::apply_filters('calculated_field_list', ['string-combine','date-calc','numeric-calc'] ) );
  }

  /**
   * assigns the object properties
   * 
   * @param object $field_def the field definition values
   * @param object $field overriding values
   */
  private function assign_def_props( $field_def, $field )
  {
    foreach ( $field_def as $prop => $value ) {
      switch ( $prop ) {

        case 'sortable':
        case 'persistent':
        case 'signup':
        case 'readonly':
          $this->{$prop} = (bool) $value;
          break;
        
        case 'CSV':
        case 'csv':
          $this->csv = (bool) $value;
          break;
        
        case 'values':

          $this->values = $field_def->values; // this is for backward compatibility
          
          // if the values parameter has a value, then the field def is of the old format
          if ( $field_def->values !== '' && !is_null($field_def->values) && $field_def->values !== 'a:0:{}' ) {
            $values = $this->values_array();
            /*
             * for "value set" fields, the values parameter defines the options; for 
             * other fields, it defines the attributes
             */
            if ( $this->is_value_set() ) {
              if ( empty( $field_def->options ) || $field_def->options === 'a:0:{}' ) {
                $this->options = $values;
              }
            } elseif ( empty( $field_def->attributes ) || $field_def->attributes === 'a:0:{}' ) {
              $this->attributes = $values;
            }
          }
          break;

        case 'attributes':
          
          if ( empty( $this->{$prop} ) ) {
              $this->{$prop} = Participants_Db::unserialize_array($value);
          }
          break;
          
        case 'options':
          
          if ( empty( $this->{$prop} ) ) {
              $this->{$prop} = Participants_Db::unserialize_array($value);
          }
          
          if ( isset( $field->{$prop} ) &&  ! empty( $field->{$prop} ) && is_array( $field->{$prop} ) ) {
            $this->{$prop} = $field->{$prop};
          }
          
          break;

        default:
          $this->{$prop} = $value;
      }
    }
  }

}
