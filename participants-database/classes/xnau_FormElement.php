<?php

/**
 * PHP class for printing HTML form elements
 *
 * This class abstracts the functional elements of a form into units that 
 * are easily defined and edited within the context of a PHP script. This 
 * class is especially useful in cases where the content and elements of a 
 * form are only known at runtime, and facilitates a standardized approach 
 * to displaying forms. This first version of the class is focused on the 
 * form elements themselves; in future versions, methods for organizing 
 * elements and formatting forms will be included.
 *
 * Several non-standard form elements have been implemented, fusing an 
 * interrelated set of HTML tags and javascript into a functional unit that 
 * can be output with the same simplicity as any other form tag. This set 
 * of user-experience-centered form elements can be easily expanded by 
 * extending the class.
 *
 * This class was developed for use within the WordPress environment.
 *
 * USAGE
 * The class operates as a static factory, with each element called as a 
 * static method with the minimum necessary parameters as an associative 
 * array or get-request-like string in the WordPress style. The static 
 * method instantiates the element object, which itself remains protected. 
 * See the constructor method for details.
 *
 * Requires PHP Version 5.3 or greater
 * 
 * @category   
 * @package    WordPress
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2011, 2012, 2013, 2014, 2015 xnau webdesign
 * @license    GPL2
 * @version    1.7
 * @link       http://wordpress.org/extend/plugins/participants-database/
 *
 */
defined( 'ABSPATH' ) || exit;

abstract class xnau_FormElement {

  /**
   * defines the type of form element for the object
   *
   * @var string 
   */
  public $form_element;

  /**
   * holds the current value of the element
   *
   * @var string
   */
  public $value;

  /**
   * the name attribute of the form data field
   *
   * @var string
   */
  public $name;

  /**
   * for elements that have set options such as checkboxes and dropdowns, this 
   * array holds the name=>value pairs
   *
   * @var array
   */
  public $options = array();

  /**
   * holds any other html element attributes in name=>value pairs
   * 
   * @var array 
   */
  public $attributes = array();

  /**
   * @var array of class names
   */
  public $classes = array();

  /**
   * array holding the text lines of an element to be output
   *
   * @var array
   */
  public $output = array();
  
  /**
   * holds the form element definition
   * 
   * @var PDb_Form_Field_Def
   */
  protected $field_def;

  /**
   * element group status
   * 
   * this pertains to elements which are part of a group of form elements sharing 
   * a common name, such as for multi-selects
   *
   * @var bool
   */
  public $group;

  /**
   * holds "inside wrapping tag" status
   * 
   * this is used in constructing complex elements that use wrapping tags such as 
   * optgroups
   * 
   * @var bool
   */
  public $inside = false;

  /**
   * @var string the linebreak character
   */
  const BR = PHP_EOL;

  /**
   * 
   * @var string the tab character
   */
  const TAB = "\t";

  /**
   * holds current indent level
   *
   * @var int
   */
  protected $indent;

  /**
   *
   * @var array holds the internationaliztion strings
   */
  protected $i18n;

  /**
   * a namespacing prefix for CSS classes and such
   */
  public $prefix = 'form-element';

  /**
   * 
   * @var string name of the instantiating module
   */
  public $module;

  /**
   * @var string  URL element link property
   */
  public $link;

  /**
   * @var int holds the record ID
   */
  public $record_id;
  
  /**
   * @var string html element id
   */
  public $container_id;

  /**
   * instantiates a xnau_FormElement object
   * 
   *
   * @param array $parameters carries the parameters to build a form element
   *                    type         string sets the type of element to print
   *                    value        string the current value of the element
   *                    name         string the name attribute of the element
   *                    options      mixed  an optional array of values for checkboxes, selects, etc. Can also
   *                                        be serialized array. A special element in this array has the key 
   *                                        "null_select" which if bool false prevents the selected null case of 
   *                                        dropdown elements from being added. If it has another value, the null 
   *                                        case (which has a blank label) will hold this value and be selected 
   *                                        if no value property is provided to the instance
   *                    attributes   array  an optional array of name=>value set of HTML attributes to include
   *                                        (can include a class attribute)
   *                    class        string a class name for the element; more than one class name must be
   *                                        space-separated string
   *                    indent       int    starting indent value
   *                    size         int    the size of the field (text type fields only)
   *                    container_id string CSS id for the element containter (if any)
   *
   * @return NULL
   */
  public function __construct( $parameters )
  {
    $defaults = array(
        'options' => array(),
        'attributes' => array(),
        'class' => '',
        'indent' => 1,
        'size' => false,
        'container_id' => false,
        'group' => false,
        'value' => '',
        'link' => '',
        'record_id' => 0,
    );
    $params = wp_parse_args( $parameters, $defaults );
    
    $this->field_def = Participants_Db::get_field_def( $params['name'] );
    
    if ( ! isset( $params['type']) || !isset($params['name']) ) {
      Participants_Db::debug_log(__METHOD__.' form element instantiated with incomplete configuration. 
backtrace: '.print_r( wp_debug_backtrace_summary(),1));
    }

    $this->form_element = $params[ 'type' ];
    $this->value = $params[ 'value' ];
    $this->name = $params[ 'name' ];
    $this->container_id = $params[ 'container_id' ];
    $this->group = $params[ 'group' ];
    $this->module = isset( $params[ 'module' ] ) ? $params[ 'module' ] : '';
    $this->setup_attributes( $params );
    $this->setup_options( $params['options'] );
    $this->link = $params[ 'link' ];
    $this->record_id = $params[ 'record_id' ];

    $this->i18n = array(
        'other' => _x( 'other', 'indicates a write-in choice', 'participants-database' ),
        'linktext' => _x( 'Link Text', 'indicates the text to be clicked to go to another web page', 'participants-database' )
    );
    
    /*
     * classes can come in in the classes parameter or as part of the attributes array. 
     * We consolidate them into the classes property here.
     */
    $this->classes = empty( $params[ 'class' ] ) ? array() : explode( ' ', $params[ 'class' ] );
    if ( isset( $this->attributes[ 'class' ] ) ) {
      $this->classes = array_merge( $this->classes, explode( ' ', $this->attributes[ 'class' ] ) );
      unset( $this->attributes[ 'class' ] );
    }

    $this->indent = $params[ 'indent' ];

    // clear the output array
    $this->output = array();

    $this->build_element();
  }

  /**
   * give the child class a chance to insert it's modifications to the build method
   */
  abstract function build_element();

  /**
   * builds the form element by calling it's method
   * 
   * @return null
   */
  protected function call_element_method()
  {

    switch ( $this->form_element ) :

      case 'date':
        $this->_date_field();
        break;

      case 'timestamp':
        $this->_timestamp_field();
        break;

      case 'text-area':
      case 'textarea':
        $this->_text_field();
        break;

      case 'rich-text':
        $this->_rich_text_field();
        break;

      case 'checkbox':
        $this->_checkbox();
        break;

      case 'radio':
        $this->_radio();
        break;

      case 'dropdown':
        $this->_dropdown();
        break;

      case 'dropdown-other':
        $this->_dropdown_other();
        break;

      case 'multi-dropdown':
        $this->_dropdown_multi();
        break;

      case 'multi-checkbox':
        $this->_multi_checkbox();
        break;

      case 'text':
      case 'text-line':
        $this->_text_line();
        break;

      case 'password':
        $this->_password();
        break;

      case 'select-other':
        $this->_select_other();
        break;

      case 'multi-select-other':
        $this->_select_other_multi();
        break;

      case 'link':
        $this->_link_field();
        break;

      case 'drag-sort':
        $this->_drag_sort();
        break;

      case 'submit':
        $this->_submit_button();
        break;

      case 'selectbox':
        $this->_selectbox();
        break;

      case 'hidden':
        $this->_hidden();
        break;

      case 'image-upload':
        $this->_upload( 'image' );
        break;

      case 'file':
      case 'file-upload':
        $this->_upload( 'file' );
        break;

      case 'captcha':
        $this->_captcha();
        break;

      case 'numeric':
      case 'decimal':
      case 'currency':
        $this->_numeric();
        break;

      default:

    endswitch;
  }

  /**
   * builds the HTML string for display
   *
   * @static
   */
  public static function _HTML( $parameters )
  {
    
  }

  /*   * ********************
   * PUBLIC METHODS
   */

  /**
   * prints a form element
   *
   * this func is calls the child class so any legacy implementations using the 
   * xnau_FormElement class alone can still work
   *
   * @param array $parameters (same as __construct() )
   * @static
   */
  public static function print_element( $parameters )
  {
    PDb_FormElement::print_element( $parameters );
  }

  /**
   * returns a form element
   *
   * @param array $parameters (same as __construct() )
   * @static
   */
  public static function get_element( $parameters )
  {
    PDb_FormElement::get_element( $parameters );
  }

  /**
   * outputs a set of hidden inputs
   *
   * @param array $fields name=>value pairs for each hidden input tag
   */
  public static function print_hidden_fields( $fields, $print = true )
  {

    PDb_FormElement::print_hidden_fields( $fields, $print );
  }

  /**
   * returns an element value formatted for display or storage
   * 
   * @param object $field a Field_Item object
   * @param bool   $html  if true, returns the value wrapped in HTML, false returns 
   *                      the formatted value alone
   * @return string the object's current value, formatted
   */
  public static function get_field_value_display( $field, $html = true )
  {
    switch ( $field->form_element ) :

      case 'image-upload' :

        $image = new PDb_Image( array(
            'filename' => $field->value,
            'link' => (isset( $field->link ) ? $field->link : ''),
            'mode' => 'both',
            'module' => $field->module,
                ) );

        if ( $html and ( !is_admin() or ( defined( 'DOING_AJAX' ) and DOING_AJAX)) ) {
          if ( isset( $field->module ) and in_array( $field->module, array( 'single', 'list' ) ) ) {
            $image->display_mode = 'image';
          } elseif ( isset( $field->module ) and $field->module == 'signup' ) {
            $image->display_mode = $image->image_defined ? 'both' : 'none';
            $image->link = false;
          } elseif ( isset( $field->module ) and $field->module == 'record' ) {
            $image->display_mode = 'filename';
          }
          $image->set_image_wrap();
          $return = $image->get_image_html();
        } elseif ( $image->file_exists ) {
          $return = $image->get_image_file();
        } else {
          $return = $field->value;
        }

        break;

      case 'file-upload' :

        if ( $html && ! self::is_empty( $field->value ) ) {

          if ( $field->module == 'signup' ) {
            $field->link = false;
            $return = $field->value;
          } else {
            $upload_dir = wp_upload_dir();
            $field->link = $upload_dir[ 'url' ] . $field->value;
            $return = self::make_link( $field );
          }
          break;
        } else {

          $return = $field->value;
          break;
        }

      case 'date' :
      case 'timestamp' :

        $return = '';
        if ( self::is_empty( $field->value ) === false ) {

          $date = strtotime( $field->value );

          $format = get_option( 'date_format', 'r' );
          $return = date_i18n( $format, $date );
        }
        break;

      case 'multi-checkbox' :
      case 'multi-select-other' :
      case 'multi-dropdown':

        $multivalues = Participants_Db::unserialize_array( $field->value );
        if ( is_array( $multivalues ) || empty( $multivalues[ 'other' ] ) )
          unset( $multivalues[ 'other' ] );

        $return = implode( ', ', (array) $multivalues );
        break;

      case 'link' :

        /*
         * value is indexed array: array( $url, $linktext )
         */

        if ( !$linkdata = Participants_Db::unserialize_array( $field->value ) ) {

          $return = '';
          break;
        }

        if ( empty( $linkdata[ 1 ] ) )
        {
          $linkdata[ 1 ] = str_replace( 'http://', '', $linkdata[ 0 ] );
        }

        if ( $html )
        {
          $return = vsprintf( ( empty( $linkdata[ 0 ] ) ? '%1$s%2$s' : '<a href="%1$s">%2$s</a>' ), $linkdata );
        }
        else
        {
          $return = $linkdata[ 0 ];
        }
        break;

      case 'text-line' :

        if ( $html ) {

          $return = self::make_link( $field );
          break;
        } else {

          $return = $field->value;
          break;
        }

      case 'text-area':
      case 'textarea':

        $return = $html ? sprintf( '<span class="' . self::class_attribute( 'textarea' ) . '">%s</span>', $field->value ) : $field->value;
        break;
      case 'rich-text':

        $return = $html ? '<span class="' . self::class_attribute( 'textarea richtext' ) . '">' . $field->value . '</span>' : $field->value;
        break;

      case 'decimal':

        $return = floatval( $field->value );
        break;

      default :

        $return = $field->value;

    endswitch;

    return $return;
  }

  /*   * *********************** 
   * ELEMENT CONSTRUCTORS
   */

  /**
   * builds a input text element
   */
  protected function _text_line()
  {

    if ( is_array( $this->value ) ) {
      $this->value = current( $this->value );
    }

    $this->add_options_to_attributes();

    $this->value = self::is_empty($this->value) ? '' : htmlspecialchars( $this->value, ENT_QUOTES, 'UTF-8', false );

    $this->_addline( $this->_input_tag() );
  }

  /**
   * builds a numeric input element
   */
  protected function _numeric()
  {

    if ( is_array( $this->value ) ) {
      $this->value = current( $this->value );
    }

    $this->add_options_to_attributes();

    $this->_addline( $this->_input_tag( 'number' ) );
  }

  /**
   * builds a date field
   */
  protected function _date_field()
  {

    $this->add_class( 'date_field' );

    if ( !self::is_empty( $this->value ) ) {
      $this->value = $this->format_date( $this->value, false );
    }

    $this->_addline( $this->_input_tag() );
  }

  /**
   * builds a timestamp field
   */
  protected function _timestamp_field()
  {

    $this->add_class( 'timestamp_field' );
    
    // test for a timestamp
    if ( is_int( $this->value ) or ( (string) (int) $this->value === $this->value) ) {
      $this->value = $this->format_date( $this->value, true );
    } elseif ( strlen( $this->value ) > 0 ) {
      
      $use_utc = PDb_Date_Parse::db_timestamp_timezone() === 'UTC';
      $ts = PDb_Date_Parse::timestamp( $this->value, array( 'utc' => $use_utc ) );
      
      $this->value = $this->format_date( $ts, true );
    }

    if ( Participants_Db::apply_filters( 'edit_record_timestamps', false ) === false ) {
      $this->attributes[ 'disabled' ] = true;
    } else {
      unset( $this->attributes[ 'readonly' ] );
    }

    $this->_addline( $this->_input_tag() );
  }
  
  /**
   * provides the textarea rows and cols attributes
   * 
   * @return string
   */
  public function textarea_dims()
  {
    $default = array( 'rows' => 2, 'cols' => 40 );
    $dims = array();
    
    foreach( $default as $att => $value )
    {
      $dims[$att] = $value;
      if ( isset( $this->attributes[$att] )  && is_numeric( $this->attributes[$att] ) ) {
        $dims[$att] = $this->attributes[$att];
      }
    }
    
    return vsprintf( ' rows="%s" cols="%s" ', $dims );
  }

  /**
   * builds a text-field (textarea) element
   */
  protected function _text_field()
  {

    $value = ! self::is_empty( $this->value ) ? $this->value : '';

    $this->_addline( '<textarea name="' . $this->name . '" ' . $this->textarea_dims() . $this->_attributes() . $this->_class() . ' >' . $value . '</textarea>', self::is_empty( $this->value ) ? 0 : -1  );
  }

  /**
   * builds a rich-text editor (textarea) element
   */
  protected function _rich_text_field()
  {
    // we encode the brackets (if any) so that it will go into the editor JS without an error
   $editor = new PDb_fields\rich_text_editor( str_replace( array('[',']'), array('&#91;','&#93;'), $this->name ), $this->value );

   $editor->print_editor();
  }

  /**
   * builds a password text element
   */
  protected function _password()
  {
    $this->value = '';

    $this->_addline( $this->_input_tag( 'password' ) );
  }

  /**
   * builds a checkbox element
   *
   * places a hidden field to supply the value when unchecked
   *
   * if there is no options array supplied, it is assumed to be a "select box",
   * which is a checkbox with no unchecked value, otherwise, there is a hidden
   * input added to supply a value to the field when the box is unchecked. The
   * first value of the array is the checked value, the second is the unchecked
   * value. If a key is provided, the key for the first options array element
   * will be the label for the checkbox. If no key is provided, no label will be
   * printed
   */
  protected function _checkbox()
  {
    if ( false === $this->options or ! is_array( $this->options ) ) {
      
      // give it a default set of options
      $this->options = array( 1, 0 );
    }
      
    $title = $this->is_assoc( $this->options ) ? key( $this->options ) : false;
    $checked_value = current( $this->options );
    $unchecked_value = next( $this->options );
    if ( $unchecked_value === false ) {
      $unchecked_value = '';
    }

    $id = $this->element_id();
    $this->attributes[ 'id' ] = $id . '-default';
    $this->_addline( $this->_input_tag( 'hidden', $unchecked_value ) );
    $this->attributes[ 'id' ] = $id;
    if ( false !== $title ) {
      $this->_addline( '<label for="' . $this->attributes[ 'id' ] . '">' );
    }
    $this->_addline( $this->_input_tag( 'checkbox', $checked_value, 'checked' ), 1 );

    if ( false !== $title ) {
      $this->_addline( Participants_Db::apply_filters( 'translate_string', $title ), 1 );
      $this->_addline( '</label>', -1 );
    }
  }

  /**
   * builds a radio button element
   */
  protected function _radio()
  {
    $this->_add_radio_series();
  }

  /**
   * builds a dropdown or dropdown-other element
   */
  protected function _dropdown( $other = false )
  {
    if ( isset( $this->attributes[ 'other' ] ) ) 
    {
      $otherlabel = $this->attributes[ 'other' ];
      unset( $this->attributes[ 'other' ] );
    } 
    else 
    {
      $otherlabel = $this->i18n[ 'other' ];
    }

    // set the ID for the select element
    $id = $this->element_id();

    if ( !isset( $this->attributes[ 'readonly' ] ) ) 
    {
      // make a unique prefix for the js function
      $js_prefix = $this->_prep_js_string( $this->name );

      // set the ID for the select element
      $id = $this->element_id();
      $this->attributes[ 'id' ] = (empty( $id ) ? $js_prefix . '_select' : $id);
      if ( isset( $this->attributes[ 'multiple' ] ) && $this->attributes[ 'multiple' ] === true ) {
        $this->group = true;
        $this->name = $this->name . '[]';
        $this->value = self::field_value_array( $this->value );
      }
      if ( $other ) {
        $this->_addline( '<div class="dropdown-other-control-group" >' );
        $this->add_class( 'otherselect' );
        //$this->_addline('<select id="' . $js_prefix . '_otherselect" onChange="' . $js_prefix . 'SelectOther()" name="' . $this->name . '" ' . $this->_attributes() . ' >');
      }

      $this->_addline( '<select name="' . $this->name . '" ' . $this->_attributes() . $this->_class() . ' >' );

      // restore the ID attribute
      $this->attributes[ 'id' ] = $id;

      $this->indent++;

      /*
       * include the "nothing selected" state
       */
      $this->_set_null_select();

      $this->_add_option_series( $other ? $otherlabel : false  );

      $this->_addline( '</select>', -1 );

      if ( $other ) {

        // build the text input element
        $this->attributes[ 'id' ] .= '_other';
        $is_other = $this->_set_selected( $this->options, $this->value, 'selected', false ) !== '';

        $this->_addline( '<input type="text" name="' . $this->name . '" value="' . ( $is_other && ! self::is_empty( $this->value ) ? htmlspecialchars( $this->value, ENT_QUOTES, 'UTF-8', false ) : '' ) . '" ' . $this->_attributes( 'no validate' ) . $this->_class( 'otherfield' ) . ' >' );
        $this->_addline( '</div>' );
      }
    } 
    else 
    {
      // readonly display
      $this->attributes[ 'id' ] = $this->element_id() . '_readonly';
      $options = $this->make_assoc( $this->options );
      
      $option_value = array_search( $this->value, $options );
      $display_value = $other && $option_value === false ? $this->value : $option_value;

      $this->_addline( '<input type="text" name="' . $this->name . '" value="' . $display_value . '" ' . $this->_attributes( 'no validate' ) . $this->_class( 'pdb-readonly' ) . ' >' );
    }
  }

  /**
   * builds a dropdown-other element
   *
   * @return string
   */
  protected function _dropdown_other()
  {

    $this->_dropdown( true );
  }

  /**
   * builds a dropdown-multiselect element
   *
   * @return string
   */
  protected function _dropdown_multi()
  {

    $this->attributes[ 'multiple' ] = true;
    $this->_dropdown();
  }

  /**
   * builds a multi-checkbox
   *
   * a set of checkboxes enclosed in a div tag
   */
  protected function _multi_checkbox()
  {
    $this->value = self::field_value_array( $this->value );

//    if (!isset($this->attributes['readonly'])) {

    $this->_addline( '<div class="multicheckbox"' . ( $this->container_id ? ' id="' . $this->container_id . '"' : '' ) . '>' );
    $this->indent++;

    $this->_add_checkbox_series();

    $this->_addline( '</div>', -1 );
//    } else {
//      $this->_readonly_multi();
//    }
  }

  /**
   * builds a select/other form element
   *
   * a set of checkboxes or radio buttons with an optional text input element activated by selecting "other"
   *
   * @param string $type can be either 'radio' or 'checkbox' (for a multi-select element)
   */
  protected function _select_other( $type = 'radio' )
  {

    if ( $type == 'radio' ) {
      $this->value = is_array( $this->value ) ? current( $this->value ) : $this->value;
    } else {
      $this->value = self::field_value_array( $this->value );
      if ( !isset( $this->value[ 'other' ] ) )
        $this->value[ 'other' ] = '';
    }

    /*
     * determine the label for the other field: start with the default value, then 
     * in the field definition, the finally the string if set in the template via 
     * the attributes array
     */
    $otherlabel = $this->i18n[ 'other' ];
    if ( $i = array_search( 'other', $this->options ) ) {
      $otherlabel = array_search( 'other', $this->options );
      unset( $this->options[ $otherlabel ] );
    }
    
    if ( isset( $this->attributes[ 'other' ] ) ) {
      $otherlabel = $this->attributes[ 'other' ];
      unset( $this->attributes[ 'other' ] );
    }

    // make a unique prefix for the function
    $js_prefix = $this->_prep_js_string( $this->name )/* .'_' */;

    // put it in a container
    $this->_addline( '<div class="selectother ' . $type . '-other-control-group"' . ( $this->container_id ? ' id="' . $this->container_id . '"' : '' ) . ' >' );
    $this->indent++;

    $type == 'checkbox' ? $this->_add_checkbox_series( $otherlabel ) : $this->_add_radio_series( $otherlabel );

    $controltag = array_pop( $this->output ); // save the <span.othercontrol> close tag
    $closetag = array_pop( $this->output ); // save the <span.checkbox-group> close tag
    // add the text input element
    $value = $type == 'checkbox' ? $this->value[ 'other' ] : (!in_array( $this->value, $this->options ) ? $this->value : '' );
    $name = $type == 'checkbox' ? str_replace( '[]', '', $this->name ) . '[other]' : '';
    $id = $this->element_id();
    $this->attributes[ 'id' ] = $id . '_other';
    $this->_addline( '<input type="text" name="' . $name . '" value="' . htmlspecialchars( $value, ENT_QUOTES, 'UTF-8', false ) . '" ' . $this->_attributes( 'no validate' ) . $this->_class( 'otherfield' ) . ' />' );
    $this->attributes[ 'id' ] = $id;
    array_push( $this->output, $closetag, $controltag ); // replace the span close tags, enclosing the input element in it
    // close the container
    $this->_addline( '</div><!-- .' . $type . '-other-control-group -->', -1 ); //  control-group div
  }

  /**
   * builds a multi-select/other form element
   */
  protected function _select_other_multi()
  {
//    if (!isset($this->attributes['readonly'])) {
    $this->_select_other( 'checkbox' );
//    } else {
//      $this->_readonly_multi();
//    }
  }

  /**
   * builds a link form element
   *
   * stores an array: first element is the URL the optional second the link text
   */
  protected function _link_field()
  {
    // this element's value is stored as an array
    $this->group = true;

    $link_placeholder = isset( $this->attributes[ 'url_placeholder' ] ) ? $this->attributes[ 'url_placeholder' ] : '(URL)';
    $linktext_placeholder = isset( $this->attributes[ 'placeholder' ] ) ? $this->attributes[ 'placeholder' ] : $this->i18n[ 'linktext' ];

    // set the correct format for an empty value
    if ( $this->value === array() || is_null( $this->value ) || ( is_string( $this->value ) && strlen( $this->value ) === 0 ) ) {
      $this->value = array( '' );
    }

    $parts = Participants_Db::unserialize_array( $this->value, false );

    if ( !is_array( $parts ) ) 
    {
      if ( filter_var( $parts, FILTER_VALIDATE_URL, FILTER_NULL_ON_FAILURE ) ) {
        $this->value = $parts;
        $parts = array( $parts, $linktext_placeholder );
      } elseif ( filter_var( $this->link, FILTER_VALIDATE_URL, FILTER_NULL_ON_FAILURE ) ) {
        $parts = array( $this->link, $parts );
      } else {
        $parts = array( '', $this->value );
      }
    } 
    else
    {
      if ( !empty( $this->link ) ) {
        $parts[0] = $this->link;
      }
    }

    // if the value contains only a URL, the linktext and URL are made the same
    // if the value is not a URL, only the linked text is used

    if ( count( $parts ) < 2 ) {
      $parts[ 1 ] = ''; // when showing an edit form, leave the click text blank
      if ( !filter_var( $parts[ 0 ], FILTER_VALIDATE_URL, FILTER_NULL_ON_FAILURE ) ) {
        $parts[ 0 ] = '';
      }
    }

    list( $url, $title ) = $parts;
    
    $hide_clickable = isset( $this->attributes['hide_clickable'] );
    unset( $this->attributes['hide_clickable'] );

    $this->_addline( '<div class="link-element">' );

    $title = strip_tags( empty( $title ) || ! is_string( $title ) ? '' : $title );

    $this->attributes[ 'placeholder' ] = $link_placeholder;

    $id = $this->element_id();
    $this->attributes[ 'id' ] = $id . '-url';
    $this->_addline( $this->_input_tag( 'url', $url, false ) );

    $this->attributes[ 'placeholder' ] = $linktext_placeholder;

    $this->attributes[ 'id' ] = $id . '-text';
    
    if ( $hide_clickable ) {
      
      unset( $this->attributes[ 'placeholder' ] );
      $this->_addline( $this->_input_tag( 'hidden', '' ) . '</div>' );
      
    } else {
      
      $this->_addline( $this->_input_tag( 'text', htmlspecialchars( $title, ENT_QUOTES, 'UTF-8', false ), false ) . '</div>' ); 
    }
    
    $this->attributes[ 'id' ] = $id;
  }

  /**
   * produces the output for a read-only multi-select element
   * 
   */
  protected function _readonly_multi()
  {

    $display = array();
    $this->group = true;

    $this->_addline( '<div class="readonly-value-group">' );

    foreach ( self::field_value_array( $this->value ) as $value ) {

      if ( $value !== '' ) {

        $display[] = $value;
        $this->_addline( $this->_input_tag( 'hidden', $value ) );
      }
    }
    $this->_addline( '<span class="pdb-readonly">' . implode( ', ', $display ) . '</span></div>' );
  }

  /**
   * builds a drag-sort element
   *
   * requires js on page to function; this just supplies a suitable handle
   *
   */
  protected function _drag_sort()
  {

    $name = preg_replace( '#(\[.*\])#', '', $this->name );

    $this->_addline( '<a id="' . $name . '" class="dragger" href="#" ><span class="dashicons dashicons-sort"></span></a>' ); // &uarr;&darr;
  }

  /**
   * builds a submit button
   */
  protected function _submit_button()
  {

    $this->_addline( $this->_input_tag( 'submit' ) );
  }

  /**
   * builds a selector box
   * special checkbox with no unselected value
   */
  protected function _selectbox()
  {

    $this->_addline( $this->_input_tag( 'hidden', '' ) );

    $this->_addline( $this->_input_tag( 'checkbox', $this->value, false ) );
  }

  /**
   * build a hidden field
   */
  protected function _hidden()
  {
    unset( $this->attributes['data-after'], $this->attributes['data-before'] );
    
    $this->_addline( $this->_input_tag( 'hidden' ) );
  }

  /**
   * builds a file upload element
   * 
   * @param string $type the upload type: file or image
   */
  protected function _upload( $type )
  {

    $this->_addline( '<div class="' . $this->prefix . 'upload">' );
    // if a file is already defined, show it
    if ( ! self::is_empty( $this->value ) ) {

      $this->_addline( self::get_field_value_display( $this ) );
    }

    // add the MAX_FILE_SIZE field
    // this is really just for guidance, not a valid safeguard; this must be checked on submission
    if ( isset( $this->options[ 'max_file_size' ] ) )
      $max_size = $this->options[ 'max_file_size' ];
    else
      $max_size = ( ini_get( 'post_max_size' ) / 2 ) * 1048576; // half it to give a cushion

    $this->_addline( $this->print_hidden_fields( array( 'MAX_FILE_SIZE' => $max_size, $this->name => $this->value ), false ) );

    if ( !isset( $this->attributes[ 'readonly' ] ) ) {
      $this->_addline( $this->_input_tag( 'file' ) );

      // add the delete checkbox if there is a file defined
      if ( ! self::is_empty( $this->value ) ) {
        $this->_addline( '<span class="file-delete" ><label><input type="checkbox" value="delete" name="' . $this->name . '-deletefile">' . __( 'delete', 'participants-database' ) . '</label></span>' );
      }
    }

    $this->_addline( '</div>' );
  }

  /**
   * builds a captcha element
   * 
   */
  protected function _captcha()
  {

    $this->_addline( $this->_input_tag( 'text' ) );
  }

  /*   * ********************** 
   * SUB-ELEMENTS
   */

  /**
   * builds an input tag
   *
   * @param string $type   the type of input tag to return, defaults to 'text'
   * @param string $value  the value of the tag; if not given, uses object value
   *                       property
   * @param string $select the selected attribute string for the element. If
   *                       given, performs a match test and sets the flag if met
   * @param bool   $group  if set, adds brackets to name for group elements
   * @return string
   *
   */
  protected function _input_tag( $type = 'text', $value = false, $select = false )
  {
    if ( $value === false ) {
      $value = $this->value;
    }

    if ( $type === 'text' && isset( $this->attributes[ 'type' ] ) ) {
      $type = $this->attributes[ 'type' ];
    }
    
    unset( $this->attributes['type'] );

    if ( $this->is_selector( $type ) )
    {
      if ( isset( $this->attributes[ 'readonly' ] ) )
      {
        $this->attributes[ 'disabled' ] = 'disabled';
        unset( $this->attributes[ 'readonly' ] );
      } 
      elseif ( has_filter( 'pdb-' . $this->name . '_selector_option_attribute_list' ) ) 
      {
        $this->attributes = apply_filters( 'pdb-'. $this->name . '_selector_option_attribute_list', [], $this->name, $value, $this->record_id );
      }
    }

    $value_att = in_array( $type, array( 'file', 'image' ) ) ? '' : esc_attr( $value );
    $select_att = ( false !== $select ? $this->_set_selected( $value, $this->value, $select ) : '' );
    
    $html = sprintf( '<input name="%s" %s %s %s type="%s" value="%s" />', 
            esc_attr( $this->name . ( $this->group ? '[]' : '' ) ), 
            $select_att, 
            $this->_attributes() , 
            $this->_class(),
            esc_attr( $type ),
            $value_att  );
    
    return $html;
  }
  
  /**
   * tells if the type is a selector type
   * 
   * @param string $type
   * @return bool
   */
  protected function is_selector( $type )
  {
    return in_array( $type, ['checkbox', 'radio', 'multi-checkbox', 'select-other'] );
  }

  /**
   * builds a checkbox or radio input series
   *
   * @param string $type sets the type of input series, defaults to checkbox
   * @param string|bool if string, add an "other" option with this label
   */
  protected function _add_input_series( $type = 'checkbox', $otherlabel = false )
  {
    if ( empty( $this->options ) )
    {
      return;
    }

    // checkboxes are grouped, radios are not
    $this->group = $type === 'checkbox';

    // checkboxes are given a null select so an "unchecked" state is possible
    $null_select = (isset( $this->options[ self::null_select_key() ] )) ? $this->options[ self::null_select_key() ] : ($type == 'checkbox' ? true : false);

    if ( $null_select !== false && $null_select !== 'false' ) {
      $id = $this->element_id();
      $this->attributes[ 'id' ] = $id . '-default';
      $this->_addline( $this->_input_tag( 'hidden', (is_string( $null_select ) ? $null_select : '' ), false ), 1 );
      $this->attributes[ 'id' ] = $id;
    }
    unset( $this->options[ self::null_select_key() ] );

    $this->_addline( '<div class="' . $type . '-group" >' );

    $optgroup = false;

    foreach ( $this->make_assoc( $this->options ) as $option_key => $option_value ) {

      if ( ($option_value === false || $option_value === 'false' || $option_value === 'optgroup') && ! self::is_empty( $option_key ) ) 
      {
        if ( $optgroup )
        {
          $this->_addline( '</fieldset>' );
        }
        
        $id = $this->element_id( $this->legal_name( $this->name . '-' . ($option_value === '' ? '_' : trim( strtolower( $option_key ) )) ) );
        $this->_addline( '<fieldset class="' . $type . '-subgroup ' . $this->name . '-subgroup" id="' . $id . '"><legend>' . $option_key . '</legend>' );
        $optgroup = true;
      }
      else
      {
        $id = $this->element_id();
        $this->attributes[ 'id' ] = $this->element_id( $this->legal_name( $this->prefix . $this->name . '-' . ( $option_value === '' ? '_' : trim( strtolower( $option_value ) ) ) ) );
        
        $this->_addline( '<label ' . $this->_class() . ' for="' . $this->attributes[ 'id' ] . '">' );
        
        $this->_addline( $this->_input_tag( $type, $option_value, 'checked' ), 1 );
        
        $this->_addline( $option_key . '</label>' );
        
        $this->attributes[ 'id' ] = $id;
      }
    }
    
    if ( $optgroup ) 
    {
      $this->_addline( '</fieldset>' );
      $optgroup = false;
    }
    
    if ( $otherlabel ) 
    {
      $value = $type == 'checkbox' ? (isset( $this->value[ 'other' ] ) ? $this->value[ 'other' ] : '') : $this->value;
      $this->_addline( '<div class="othercontrol">' );
      $id = $this->element_id();
      $this->attributes[ 'id' ] = $id . '_otherselect';
      $this->_addline( '<label ' . $this->_class() . ' for="' . $this->attributes[ 'id' ] . '">' );
      $this->_addline( sprintf( '<input type="%s" name="%s"  value="%s" %s %s />', $type, $type === 'radio' ? $this->name : 'pdb-otherselector', $otherlabel, $this->_set_selected( $this->options, $value, 'checked', $value === '' ), $this->_attributes() . $this->_class( 'otherselect' )
              ), 1 );
      $this->attributes[ 'id' ] = $id;
      //$this->_addline('<input type="' . $type . '" id="' . $this->name . '_otherselect" name="' . ($type == 'checkbox' ? 'temp' : $this->name) . '"  value="' . $otherlabel . '" ' . $this->_set_selected($this->options, ( $type == 'checkbox' ? $this->value['other'] : $this->value), 'checked', false) . ' ' . $this->_attributes() . ' />', 1);
      $this->_addline( $otherlabel . ':' );
      $this->_addline( '</label>', -1 );
      $this->_addline( '</div>', -1 );
    }

    $this->_addline( '</div>' );
  }

  /**
   * adds a series of radio buttons
   * 
   * @param string|bool if string, add an "other" option with this label
   */
  protected function _add_checkbox_series( $otherlabel = false )
  {

    $this->_add_input_series( 'checkbox', $otherlabel );
  }

  /**
   * adds a series of radio buttons
   * 
   * @param string|bool if string, add an "other" option with this label
   */
  protected function _add_radio_series( $otherlabel = false )
  {
    $this->_add_input_series( 'radio', $otherlabel );
  }

  /**
   * builds an option series
   * 
   * if an element in the options array has a value of bool false, it will open an 
   * optgroup using the key as the group 
   * 
   * @var string|bool label of the "other" option if any
   */
  protected function _add_option_series( $otherlabel = false )
  {
    if ( empty( $this->options ) )
    {
      return;
    }
    
    $has_option_atts = has_filter( 'pdb-' . $this->name . '_selector_option_attribute_list' );

    foreach ( $this->make_assoc( $this->options ) as $title => $value ) 
    {
      $title = Participants_Db::apply_filters( 'translate_string', $title );

      if ( $title === self::null_select_key() && ( $value === 'false' || $value === false ) ) 
      {
        continue 1;
      }
      
      switch ( $value )
      {
        case 'optgroup':
          if ( strlen( $title ) > 0 )
          {
            $this->_add_options_divider( $title );
          }
          break;
          
        case 'other':
          $otherlabel = $title;
          break;
        
        case '':
          break;
        
        default:
          if ( $has_option_atts  )
          {
            $this->_addline( '<option value="' . esc_attr( $value ) . '" ' . $this->_set_selected( $value, $this->value, 'selected' ) . $this->option_attribute( $value ) . ' >' . strip_tags( $title ) . '</option>', -1 );
          }
          else
          {
            $this->_addline( '<option value="' . esc_attr( $value ) . '" ' . $this->_set_selected( $value, $this->value, 'selected' ) . ' >' . strip_tags( $title ) . '</option>', -1 );
          }
      }
    }
    
    // 
    // add the "other" option
    if ( $otherlabel !== false ) 
    {
      if ( in_array( 'optgroup', $this->options ) ) 
      {
        $this->_add_options_divider( $this->i18n[ 'other' ] );
      }
      
      $this->_addline( '<option ' . ( $this->value_is_unset() ? '' : $this->_set_selected( $this->options, $this->value, 'selected', false ) ) . ' value="other" >' . strip_tags( $otherlabel ) . '</option>' );
    }

    if ( $this->inside ) 
    {
      $this->_addline( '</optgroup>' );
      $this->inside = false;
    }
  }
  
  /**
   * provides an option attribute string
   * 
   * @param string $value the option value
   * @return string
   */
  protected function option_attribute( $value )
  {
    /**
     * @filter pdb-$fieldname_selector_option_attribute_list
     * @param array the empty attributes array
     * @param string the option value
     * @param int the record ID
     * @param array the attribute list as $attribute => $value
     */
    $attributes = apply_filters( 'pdb-' . $this->name . '_selector_option_attribute_list', [], $this->name, $value, $this->record_id );
    
    if ( empty( $attributes ) )
    {
      return '';
    }
    
    $html = [];
    $pattern = '%s="%s"';
    
    foreach ( $attributes as $att => $attval )
    {
      if ( self::is_empty( $attval ) )
      {
        $attval = $att;
      }
      
      $html[] = sprintf( $pattern, $att, $att );
    }
    
    return ( empty( $attributes ) ? '' : ' ' ) . implode( ' ', $html );
  }

  /*   * ****************  
   * OUTPUT FUNCTIONS
   */

  /**
   * builds an output string
   */
  protected function _output()
  {

    return implode( self::BR, (array) $this->output ) . self::BR;
  }

  /**
   * add a line to the output property
   *
   * places the proper number of tabs at the beginning of each line, then adds
   * the line to the output array
   *
   * @param string  $line          the line to be added
   * @param int     $tab_increment change to the current tab level ( +/- 1 ); false
   *                               for no indent
   */
  protected function _addline( $line, $tab_increment = 0 )
  {

    $indent = '';

    if ( false !== $tab_increment ) {

      if ( $tab_increment > 0 )
        $this->indent++;
      elseif ( $tab_increment < 0 )
        $this->indent--;
    }

    $this->output[] = $indent . $line;
  }

  /*   * ************************* 
   * UTILITY FUNCTIONS
   */

  /**
   * provides an array of values from a stored field value
   * 
   * @param string $value the raw value from the db
   * @return array array of values
   */
  public static function field_value_array( $value )
  {
    return PDb_Field_Item::field_value_array( $value );
  }

  /**
   * outputs a link (HTML anchor tag) in specified format if enabled by "make_links"
   * option
   * 
   * this func validates the link as being either an email addres or URI, then
   * (if enabled) builds the HTML and returns it
   * 
   * @param object $field the field object
   * @param string $linktext the clickable text (optional)
   * @param string $template the format of the link (optional)
   * @param array  $get an array of name=>value pairs to include in the get string
   *
   * @return string HTML or HTML-escaped string (if it's not a link)
   */
  public static function make_link( $field, $template = false, $get = false )
  {


    // clean up the provided link string
    $URI = str_replace( 'mailto:', '', trim( strip_tags( $field->value ) ) );
    $linktext = self::is_empty( $field->value ) ? $field->default : $field->value;

    if ( isset( $field->link ) && ! empty( $field->link ) ) {
      // if the field is a single record link or other kind of defined link field
      $URI = $field->link;
    } elseif ( filter_var( $URI, FILTER_VALIDATE_URL ) ) {

      // convert the get array to a get string and add it to the URI
      if ( is_array( $get ) ) {

        $URI .= false !== strpos( $URI, '?' ) ? '&' : '?';

        $URI .= http_build_query( $get );
      }
    } elseif ( filter_var( $URI, FILTER_VALIDATE_EMAIL ) ) {

      // in admin, emails are plaintext
      if ( is_admin() )
        return esc_html( $field->value );
      $linktext = $URI;
      $URI = 'mailto:' . $URI;
    } else {
      return $field->value; // if it is neither URL nor email address nor defined link
    }

    // default template for links
    $linktemplate = $template === false ? '<a href="%1$s" >%2$s</a>' : $template;

    $linktext = self::is_empty( $linktext ) ? str_replace( array( 'http://', 'https://' ), '', $URI ) : $linktext;

    //construct the link
    return sprintf( $linktemplate, $URI, esc_html( $linktext ) );
  }

  /**
   * adds a class name to the class property
   */
  public function add_class( $classname )
  {

    $this->classes[] = $classname;
  }

  /**
   * builds a string of attributes for inclusion in an HTML element
   *
   * @param array $attributes_array an attributes array to use; 
   *                                default: $this->attributes
   * @return string
   */
  protected function _attributes( $attributes_array = false )
  {

    $attributes_array = is_array( $attributes_array ) ? $attributes_array : $this->attributes;

    if ( empty( $attributes_array ) )
      return '';

    return self::html_attributes( $attributes_array );
  }

  /**
   * builds an html attributes string
   * 
   * @param array $attributes the attributes array
   * @param array $allowed array of allowed attributes (optional)
   * 
   * @return string the HTML attribute string
   */
  public static function html_attributes( $attributes, $allowed = false )
  {
    $output = array();

    foreach ( (array) $attributes as $name => $value ) {

      if ( ( $allowed && in_array( $name, $allowed ) ) || !is_array( $allowed ) ) {

        if ( $value === false ) {
          continue;
        } elseif ( $value === true ) {
          $output[] = sprintf( '%1$s="%1$s"', esc_attr( $name ) );
        } elseif ( preg_match( '/^\d+$/', $name ) || $value === $name ) {
          $output[] = esc_attr( $value );
        } elseif ( self::is_translatable_att( $name ) ) {
          $output[] = sprintf( '%s="%s"', esc_attr( $name ), esc_attr( Participants_Db::apply_filters( 'translate_string', $value ) ) );
        } else {
          $output[] = sprintf( '%s="%s"', esc_attr( $name ), esc_attr( $value ) );
        }
      }
    }

    return implode( ' ', $output );
  }

  /**
   * provides a list of attributes that should be passed through the translation filter
   * 
   * @param string $name of the attribute
   * @return bool true if the attribute value should be passed through the translation filter
   */
  protected static function is_translatable_att( $name )
  {
    $translatable = Participants_Db::apply_filters( 'translatable_html_attributes', array(
                'placeholder',
                'title',
            ) );

    return in_array( $name, $translatable );
  }

  /**
   * merges the options array into the attributes
   * 
   * this is used on elements that need arbitrary attributes added
   */
  protected function add_options_to_attributes()
  {
    if ( is_array( $this->options ) ) {
      $this->attributes += $this->options;
    }
  }

  /**
   * builds a class attribute string
   * 
   * @param string $add_class any additional classes to add
   * 
   * @return string an html class attribute string
   */
  protected function _class( $add_class = false )
  {
    return self::class_attribute( $add_class, $this->classes );
  }

  /**
   * supplies a class attribute string
   * 
   * @param string $add_class any additional classes to add
   * @param array $classes array of classnames
   * @return string an html class attribute string
   */
  public static function class_attribute( $add_class, $classes = array() )
  {
    if ( $add_class ) {
      $classes[] = $add_class;
    }
    $class_string = implode( ' ', $classes );
    return empty( $class_string ) ? '' : ' class="' . esc_attr( $class_string ) . '"';
  }

  /**
   * returns a select state for a form field
   *
   * @param mixed  $element_value  the set values of the element that we compare against
   * @param string $selected_value      the selected value of the field
   * 
   * @param string $attribute      the keyword for the select state of the form element
   * @param bool   $state          inverts the logic of the array value match:
   *                               true = looking for a match;
   *                               false = looking for no match
   *
   * @return string selection state string for HTML element
   */
  protected function _set_selected( $element_value, $selected_value, $attribute = 'selected', $state = true )
  {
    if ( is_array( $selected_value ) ) {
      return $this->_set_multi_selected( $element_value, $selected_value, $attribute, $state );
    }

    $add_attribute = false;
    $selected_value = $this->_prep_comp_string( $selected_value );
    $element_value = $this->_prep_comp_array( $element_value );

    switch ( true ) {
      
      case ($element_value === true and $selected_value === true):
      case (is_array( $element_value ) and ( $state === in_array( $selected_value, $element_value ))):
      case ($element_value == $selected_value):
        
        $add_attribute = true;
        break;
      
    }

    return $add_attribute ? sprintf( ' %1$s="%1$s" ', $attribute ) : '';
  }

  /**
   * prepares a string for a comparison
   *
   * converts HTML entities to UTF-8 characters
   * 
   * if the argument is not a string, returns it unchanged
   * 
   * @param mixed $string
   * @return mixed converted string or unchanged input
   */
  protected function _prep_comp_string( $string )
  {

    return is_string( $string ) ? trim( html_entity_decode( $string, ENT_QUOTES, 'UTF-8' ) ) : $string;
  }

  /**
   * prepares an array for string comparison
   * 
   * @param array $array the array to prepare for comparison
   * @return array an indexed array of prepared strings
   */
  protected function _prep_comp_array( $array )
  {

    if ( !is_array( $array ) ) {
      return $this->_prep_comp_string( $array );
    }

    $output = array();

    foreach ( $array as $item ) {
      $output[] = $this->_prep_comp_string( $item );
    }

    return $output;
  }

  /**
   * sets up the "nothing selected" option element
   *      
   * include the null state if it is not overridden. This adds a blank option which 
   * will be selected if there is no value property set for the element. 
   * 
   * If $this->options['null_select'] has a string value, it will be used as the 
   * display value for the null option. 
   * If $this->options['null_select'] is blank, a blank unselected null option will 
   * be added. 
   * If $this->options['null_select'] is false no null state option will be added.
   * 
   * If the value is empty and no null select is defined, a blank null select option 
   * will be added so that the current state of the field can be represented.
   * 
   * TODO: if there is a defined default value, and the current vlaue of the field 
   * is not set, no null select should be added, the default value should be selected 
   * in the control
   * 
   * @return null
   */
  protected function _set_null_select()
  {
    /*
     * if the null_select option is a string, use it as the name of the null select 
     * option, unless it is the string 'false', then make it boolean false. If it is any 
     * other value or not set at all, make it boolean false
     */
    $null_select = true;
    $null_select_label = '';

    if ( isset( $this->options[ self::null_select_key() ] ) ) {

      if ( $this->options[ self::null_select_key() ] !== 'false' && $this->options[ self::null_select_key() ] !== false ) {
        $null_select = $this->options[ self::null_select_key() ];
        $null_select_label = strlen( $null_select ) > 0 ? Participants_Db::apply_filters( 'translate_string', $null_select ) : '&nbsp;';
      } else {
        $null_select = false;
      }
      // remove the null_select from the options array
      unset( $this->options[ self::null_select_key() ] );
    }

    if ( $null_select !== false ) {
      
      $selected = $this->value_is_unset() ? $this->_set_selected( true, true, 'selected' ) : '';
      $this->_addline( '<option value="" ' . $selected . '  >' . esc_html( $null_select_label ) . '</option>' );
    }
  }
  
  /**
   * tells if the field's value is unset
   * 
   * @return bool
   */
  public function value_is_unset()
  {
    return $this->value === '' || is_null( $this->value );
  }

  /**
   * provides the null select key string
   * 
   * @return string
   */
  public static function null_select_key()
  {
    return 'null_select'; //
  }

  /**
   * adds a divider element to an option series
   * 
   * @param string $title of the option divider
   * @return null
   */
  protected function _add_options_divider( $title )
  {
    $divider = '';
    if ( $this->inside ) {
      $divider = '</optgroup>' . self::BR;
      $this->inside = false;
    }
    $divider .= sprintf( '<optgroup label="%s">', esc_html( $title ) );
    $this->inside = true;
    $this->_addline( $divider );
  }

  /**
   * sets the select states for a multi-select element
   *
   * cycles through the available selects or checkboxes and sets the selected
   * attribute if there is a match to an element of the array of stored values
   * for the field
   *
   * @param string  $element_value   the value of one select of a multi-select
   * @param array   $selected_value_array the array of stored or inputted values
   * @param string  $attribute       the name of the "selected" attribute for the element
   * @param bool    $state           true to check for a match or false for a non-match
   * @return string                  the attribute string for the element
   */
  protected function _set_multi_selected( $element_value, $selected_value_array, $attribute = 'selected', $state = true )
  {

    $prepped_new_value_array = $this->_prep_comp_array( $selected_value_array );

    $prepped_string = $this->_prep_comp_string( $element_value );

    if ( $state === in_array( $prepped_string, $prepped_new_value_array ) )
      return sprintf( ' %1$s="%1$s" ', $attribute );
    else
      return '';
  }

  /**
   * tests the type of an array, returns true if associative
   * 
   * @param mixed $array
   * @return bool
   */
  public static function is_assoc( $array )
  {
    if ( function_exists( 'array_is_list' ) )
    {
      return array_is_list($array) === false;
    }
    
    // returns true if there are any string keys in the array
    return count(array_filter(array_keys($array), 'is_string')) > 0;
  }

  /**
   * makes a string OK to use in javascript as a variable or function name
   */
  protected function _prep_js_string( $string )
  {

    return str_replace( array( '[', ']', '{', '}', '-', '.', '(', ')' ), '', $string );
  }

  /**
   * makes an associative array out of an indexed array by copying the values into the keys
   *
   * given an associative array, it returns the array unaltered
   *
   * @param array the array to be processed
   * @return array an associative array
   */
  protected function make_assoc( $array )
  {
    if ( self::is_assoc( $array ) )
      return $array;

    return array_combine( array_values( $array ), $array );
  }

  /**
   * returns an internationalized date string from a UNIX timestamp
   * 
   * @param int $timestamp a UNIX timestamp
   * @param bool $time if true, adds the time of day to the format
   * @return string a formatted date or input string if invalid
   */
  public static function format_date( $timestamp, $time = false )
  {
    $uts = PDb_Date_Parse::timestamp($timestamp);
    
    if ( $uts === false ) {
      
      Participants_Db::debug_log( __METHOD__ . ' unable to parse date: ' . $timestamp, 1 );
      return $timestamp;
    }
    
    if ( $time ) {
      
      return PDb_Date_Display::get_date_time( $uts );
    
    } else {
      
      return PDb_Date_Display::get_date( $uts );
    }
  }

  /**
   * builds a legal CSS classname or ID
   * 
   * @param string $string
   * @return string the legalized name
   */
  public static function legal_name( $string )
  {
    // make sure it doens't start with a numeral
    if ( preg_match( '/^[0-9]/', $string ) )
      $string = '_' . $string;
    // eliminate any non-legal characters
    $string = preg_replace( '/[^_a-zA-Z0-9- ]/', '', $string );
    // replace spaces with a dash
    return strtolower( str_replace( array( ' ' ), array( '-' ), $string ) );
  }

  /**
   * unambiguously test for all the flavors of emptiness that a field value may have
   * 
   * @var unknown $test the value to test
   * @return bool true if the value is the equivalent of empty, zero or undefined
   */
  public static function is_empty( $test )
  {
    // collapse an array
    if ( is_array( $test ) ) {
      $test = implode( '', $test );
    }

    switch ( true ) {
      case $test === null:
      case $test === '0000-00-00 00:00:00':
      case strlen( trim( $test ) ) === 0:
        return true;
      case is_bool( $test ):
      case is_object( $test ):
      default:
        return false;
    }
  }

  /**
   * supplies an ID attribute
   * 
   * this is taken from the attributes property
   * 
   * @param string $baseid the base id
   * @return string the ID attribute or empty string
   */
  public function element_id( $baseid = false )
  {
    if ( !$baseid ) {
      $baseid = isset( $this->attributes[ 'id' ] ) ? $this->attributes[ 'id' ] : '';
    }
    
    $id = ( $baseid !== '' ? $baseid : $this->prefix . str_replace( '[]', '', $this->name ) );

    // attach the instance index if it is not present
    /**
     * @filter pdb-add_index_to_element_id
     * @param bool true to add the instance index to the id
     * @param PDb_FormElement object
     * @return bool
     */
    if ( Participants_Db::apply_filters( 'add_index_to_element_id', true, $this ) && preg_match( '/-' . Participants_Db::$instance_index . '$/', $id ) !== 1 )
    {
      $id = $id . '-' . Participants_Db::$instance_index;
    }
    
    return $id;
  }

  /**
   *  tells if a field is represented as a set of values, such as a dropdown, checkbox or radio control
   * 
   * any new form element that does this is expected to register with this list
   * 
   * @param string  $form_element the name of the form element
   * 
   * @return bool true if the element is represented as a set of values
   */
  public static function is_value_set( $form_element )
  {
    return in_array( $form_element, Participants_Db::apply_filters( 'value_set_form_elements_list', array(
                'dropdown',
                'radio',
                'checkbox',
                'dropdown-other',
                'select-other',
                'multi-checkbox',
                'multi-select-other',
                'multi-dropdown',
            ) ) );
  }

  /**
   * determines if a field type is "linkable"
   * 
   * meaning it is displayed as an element that can be wrapped in an anchor tag
   * 
   * @param object $field the field object
   * @return bool true if the type is linkable
   */
  public static function field_is_linkable( $field )
  {
    $linkable = in_array( $field->form_element, array(
        'text-line',
        'image-upload',
        'file-upload',
        'dropdown',
        'checkbox',
        'radio',
        'hidden',
            )
    );
    return Participants_Db::apply_filters( 'field_is_linkable', $linkable, $field->form_element );
  }

  /**
   * tells if the named column is a numeric datatype
   * 
   * @oaram string  $column name of the column to check
   * @return bool true if the column is a numeric type
   */
  public static function is_numeric_datatype( $column )
  {
    global $wpdb;
    $sql = 'SHOW FIELDS FROM ' . Participants_Db::$participants_table . ' WHERE Field = "%s"';
    $result = $wpdb->get_row( $wpdb->prepare( $sql, $column ) );
    $type = isset( $result->Type ) ? strtoupper( $result->Type ) : '';

    return preg_match( '/(INT|DECIMAL|FLOAT|NUMERIC|DOUBLE)/', $type ) === 1;
  }

  /**
   * returns a MYSQL datatype appropriate to the form element type
   * 
   * @param string $element name of the form element
   * @return string the name of the MySQL datatype
   */
  public static function get_datatype( $form_element )
  {
    switch ( $form_element ) {

      case 'timestamp':
        $datatype = 'timestamp';
        break;

      case 'date':
        $datatype = 'bigint';
        break;

      case 'numeric':
        $datatype = 'bigint';
        break;

      case 'decimal':
        $datatype = 'decimal(14,4)';
        break;

      case 'currency':
        $datatype = 'decimal(10,2)';
        break;

      case 'text-line':
        $datatype = 'tinytext';
        break;

      case 'captcha':
        $datatype = '';
        break;

      case 'checkbox':
      case 'radio':
      case 'multi-select':
      case 'multi-checkbox':
      case 'text-area':
      case 'rich-text':
      case 'dropdown':
      default :
        $datatype = 'text';
    }

    return $datatype;
  }

  /*
   * static function for assembling the types array
   */

  public static function get_types()
  {
    $types = array(
        'text-line' => 'Text-line',
        'text-area' => 'Text Area',
        'rich-text' => 'Rich Text',
        'checkbox' => 'Checkbox',
        'radio' => 'Radio Buttons',
        'dropdown' => 'Dropdown List',
        'date' => 'Date Field',
        'dropdown-other' => 'Dropdown/Other',
        'multi-checkbox' => 'Multiselect Checkbox',
        'multi-dropdown' => 'Multiselect Dropdown',
        'select-other' => 'Radio Buttons/Other',
        'multi-select-other' => 'Multiselect/Other',
        'link' => 'Link Field',
        'image-upload' => 'Image Upload Field',
        'file-upload' => 'File Upload Field',
        'hidden' => 'Hidden Field',
        'password' => 'Password Field',
        'captcha' => 'CAPTCHA',
        'timestamp' => 'Timestamp',
    );
    /*
     * this gives access to the list of form element types for alteration before
     * it is set
     */
    return $types;
  }
  
  /**
   * tells if the current form element is a PDB field
   * 
   * @return bool
   */
  public function is_pdb_field()
  {
    return is_a( $this->field_def, '\PDb_Form_Field_Def' );
  }
  
  /**
   * provides the field definition object
   * 
   * @return PDb_Form_Field_Def|bool false if the form element does not have 
   *                                 a corresponding field definition
   */
  public function get_field_def()
  {
    return $this->field_def;
  }
  
  /**
   * sets up the attributes property
   * 
   * this is used in the constructor
   * 
   * @param array $params the supplied configuration params
   */
  protected function setup_attributes( array $params )
  {
    $attributes = is_array( $params['attributes'] ) ? $params['attributes'] : [];
    
    if ( $this->is_pdb_field() ) 
    {
      $this->attributes = array_merge( $this->field_def->attributes(), $attributes );
    } 
    else 
    {
      $this->attributes = $attributes;
    }
    
    if ( $params['size'] ) {
      $this->attributes['size'] = $params['size'];
    }
  }
  
  /**
   * sets up the options property
   * 
   * this is used in the constructor
   * 
   * @param array|string $options the supplied options as $title => $value
   */
  protected function setup_options( $options )
  {
    $field_options = [];
    
    if ( self::is_empty($options) && $this->is_pdb_field() ) 
    {
      $field_options = $this->field_def->options();
    } 
    elseif ( ! self::is_empty( $options ) ) 
    {
      $field_options = Participants_Db::unserialize_array( $options );
    }
    
    if ( is_array( $field_options ) ) 
    {
      // escape all values
      array_walk( $field_options, function(&$v) { 
        $v = $v === false ? $v : esc_attr( $v );
      } );
      
      $this->options = $field_options;
    }
    
  }

}
