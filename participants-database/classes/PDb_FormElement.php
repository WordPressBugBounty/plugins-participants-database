<?php

/**
 * PDb subclass for printing and managing form elements
 * 
 * @category   
 * @package    WordPress
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2015 xnau webdesign
 * @license    GPL2
 * @version    1.12
 * @link       http://wordpress.org/extend/plugins/participants-database/
 *
 */
defined( 'ABSPATH' ) || exit;

class PDb_FormElement extends xnau_FormElement {

  /**
   * @var string dummy password
   * 
   * this string is used to show that a password is present; it is not saved to the database
   */
  const dummy = '***************';

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
   *                    size         int    the size of the field
   *                    container_id string CSS id for the element containter (if any)
   *
   * @return NULL
   */
  public function __construct( $parameters )
  {
    $this->prefix = Participants_Db::$prefix;

    parent::__construct( $parameters );
  }

  /**
   * builds the HTML string for display
   *
   * @var array parameters as per __construct()
   * @static
   */
  public static function _HTML( $parameters )
  {
    $Element = new self( $parameters );

    return $Element->_output();
  }

  /*   * *******************
   * PUBLIC METHODS
   */

  /**
   * prints a form element
   *
   * @param array $parameters (same as __construct() )
   * @static
   */
  public static function print_element( $parameters )
  {
    $Element = new self( $parameters );

    echo wp_kses( $Element->_output(), Participants_Db::allowed_html('form') );
  }

  /**
   * returns a form element
   *
   * @param array|object $parameters (same as __construct() )
   * @static
   */
  public static function get_element( $parameters )
  {
    if ( is_array( $parameters ) && isset( $parameters['instance_index'] ) && $parameters['instance_index'] == 0 )
    {
      add_filter( 'pdb-add_index_to_element_id', function ($add) {
          return false;
        }
      );
    }
    
    $Element = new self( $parameters );

    return $Element->_output();
  }

  /**
   * outputs a set of hidden inputs
   *
   * @param array $fields name=>value pairs for each hidden input tag
   */
  public static function print_hidden_fields( $fields, $print = true )
  {

    $output = array();

    $atts = array('type' => 'hidden');

    foreach ( $fields as $k => $v ) {

      $atts['name'] = $k;
      $atts['value'] = $v;

      $output[] = self::_HTML( $atts );
    }

    if ( $print ) {
      echo wp_kses( implode( PHP_EOL, $output ), Participants_Db::allowed_html('form') );
    } else {
      return implode( PHP_EOL, $output );
    }
  }
  
  /**
   * provides the element output
   * 
   * @return string
   */
  public function output()
  {
    return $this->_output();
  }

  /**
   * builds an output string
   */
  protected function _output()
  {
    /**
     * @version 1.7.0.9
     * @filter pdb-form_element_html
     * @param string html
     * @param PDb_Form_Element the current instance
     * @return string
     */
    return Participants_Db::apply_filters( 'form_element_html', parent::_output(), $this );
  }

  /**
   * builds the form element
   * 
   * allows an external func to build the element. If that doesn't happen, uses 
   * the parent method to build it
   * 
   * @return null
   */
  public function build_element()
  {
    /**
     * we pass the object to an external function with 
     * a filter handle that includes the name of the custom form element. The 
     * hook handler is expected to fill the output property of the field object,
     * but this hook can be used to modify the field object before output
     * 
     * @action pdb-form_element_build_{$type}
     */
    Participants_Db::do_action( 'form_element_build_' . $this->form_element, $this );
    
    if ( empty( $this->output ) ) {
      $this->call_element_method();
    }
  }

  /**
   * returns an element value formatted for display or storage
   * 
   * this supplants the function Participants_Db::prep_field_for_display
   * 
   * @param object|string $field a PDb_Field_Item object or field name
   * @param bool   $html  if true, returns the value wrapped in HTML, false returns 
   *                      the formatted value alone
   * @return string the object's current value, formatted
   */
  public static function get_field_value_display( $field, $html = true )
  {

    if ( !is_a( $field, 'PDb_Field_Item' ) ) {
      $field = new PDb_Field_Item( $field );
    }
    
    /* @var $field PDb_Field_Item */
    return $field->get_value_display( $html );
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
      return;

    // checkboxes are grouped, radios are not
    $this->group = $type === 'checkbox';

    // checkboxes are given a null select so an "unchecked" state is possible
    $null_select = (isset( $this->options[self::null_select_key()] )) ? $this->options[self::null_select_key()] : ($type == 'checkbox' ? true : false);

    if ( $null_select !== false ) 
    {
      if ( $type === 'checkbox' ) 
      {
        $id = $this->element_id();
        $this->attributes['id'] = $id . '-default';
        $this->_addline( $this->_input_tag( 'hidden', (is_string( $null_select ) && $null_select !== 'false' ? $null_select : '' ), false ), 1 );
        $this->attributes['id'] = $id;
      } 
      elseif ( $this->options[self::null_select_key()] !== 'false' ) 
      { 
        // this is a "none" selector, set up its label by swapping in its value
        $null_label = $this->options[self::null_select_key()];
        $this->options = Participants_Db::replace_key( $this->options, self::null_select_key(), $null_label );
        $this->options[$null_label] = '';
      }
    }
    unset( $this->options[self::null_select_key()] );

    $this->_addline( '<div class="' . $type . '-group" >' );
    
    $this->_addline( '<fieldset class="no-border">' );
    
    if ( $this->field_def ) {
      $this->_addline('<legend class="screen-reader-text">' . esc_attr( strip_tags( $this->field_def->title() ) ) . '</legend>' );
    }

    $in_optgroup = false;

    foreach ( $this->make_assoc( $this->options ) as $option_key => $option_value ) 
    {
      $option_key = Participants_Db::apply_filters( 'translate_string', stripslashes( $option_key ) );

      if ( ( $option_value === 'optgroup') and ! empty( $option_key ) ) 
      {
        if ( $in_optgroup ) 
        {
          $this->_addline( '</fieldset>' );
        }
        
        $id = $this->element_id( self::legal_name( $this->name . '-' . ( $option_value === '' ? '_' : trim( strtolower( $option_key ) ) ) ) );
        $this->_addline( '<fieldset class="' . esc_attr( $type . '-subgroup ' . $this->name . '-subgroup' ) . '" id="' . esc_attr( $id ) . '"><legend>' . esc_html( $option_key ) . '</legend>' );
        $in_optgroup = true;
      } 
      else 
      {
        $id = $this->element_id();
        $this->attributes['id'] = $this->element_id( self::legal_name( $this->prefix . $this->name . '-' . ( $option_value === '' ? '_' : esc_attr( trim( strtolower( $option_value ) ) ) ) ) );
        $this->_addline( '<label ' . $this->_class() . ' for="' . $this->attributes['id'] . '">' );
        $this->_addline( $this->_input_tag( $type, esc_attr( $option_value ), 'checked' ), 1 );
        $this->_addline( $option_key . '</label>' );
        $this->attributes['id'] = $id;
      }
    }
    if ( $in_optgroup ) {
      $this->_addline( '</fieldset>' );
      $in_optgroup = false;
    }
    if ( $otherlabel ) {
      
      $otherlabel = Participants_Db::apply_filters('translate_string', $otherlabel );

      $value = $type == 'checkbox' ? (isset( $this->value['other'] ) ? $this->value['other'] : '') : $this->value;
      $this->_addline( '<div class="othercontrol">' );
      $id = $this->element_id();
      $this->attributes['id'] = $id . '_otherselect';
      $this->_addline( '<label ' . $this->_class() . ' for="' . esc_attr( $this->attributes['id'] ) . '">' );
      $this->_addline( sprintf( '<input type="%s" name="%s"  value="%s" %s %s />', esc_attr( $type ), $type === 'radio' ? esc_attr( $this->name ) : 'pdb-otherselector', esc_attr( $otherlabel ), $this->_set_selected( $this->options, $value, 'checked', $value === '' ), $this->_attributes() . $this->_class( 'otherselect' )
              ), 1 );
      $this->attributes['id'] = $id;
      $this->_addline( esc_html( $otherlabel ) . ':' );
      $this->_addline( '</label>', -1 );
      $this->_addline( '</div><!-- .othercontrol -->', -1 );
    }

    $this->_addline( '</fieldset><!-- .no-border --></div><!-- .'.$type . '-group -->' );
  }

  /**
   * provides a display string for an array field value
   * 
   * for multi-select form elements
   * 
   * @param PDb_FIeld_Item $field the field object
   * 
   * @return string the array presented as a string
   */
  static function array_display( $field )
  {
    $titles = array();
    foreach ( self::field_value_array( $field->value() ) as $value ) {
      $titles[] = $field->value_title( $value );
    }

    return sanitize_post( implode( Participants_Db::apply_filters( 'stringify_array_glue', ', ' ), $titles ) );
  }

  /*   * *********************** 
   * ELEMENT CONSTRUCTORS
   */

  /**
   * builds a rich-text editor (textarea) element
   */
  protected function _rich_text_field()
  {
    if ( !is_admin() and ! Participants_Db::plugin_setting_is_true('rich_text_editor') ) {
      $this->_text_field();
    } else {
      add_filter( 'tiny_mce_before_init', array( $this, 'tinymce_config' ), 5 );
      parent::_rich_text_field();
    }
  }
  
  /**
   * provides the visual editor configuration array
   * 
   * 
   * @param array $config
   * @return array
   */
  public function tinymce_config( $config )
  {
    // sets up a javascript event that is triggered when a rich text field is changed
    $config['setup'] = "[function(ed){ed.on('input',function(e){jQuery(this.container).trigger('pdb-tinymce-change');});}][0]";
    return $config;
  }

  /**
   * builds a captcha element
   * 
   */
  protected function _captcha()
  {
    $captcha = new PDb_CAPTCHA( $this );
    $this->_addline( $captcha->get_html() );
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
    
    if ( $this->form_element === 'currency' && ! isset( $this->attributes['step'] ) ) {
      $this->attributes['step'] = '0.01';
    } 

    $this->_addline( $this->_input_tag( 'number' ) );
  }

  /**
   * builds a date field
   */
  protected function _date_field()
  {
    $this->add_class( 'date_field' );
    
    $field_atts = $this->field_def ? $this->field_def->attributes() : [];
    
    if ( $this->field_def && empty( $this->value ) ) 
    {  
      // set the timestamp using a relative date key
      $timestamp = PDb_Date_Parse::timestamp( PDb_List_Query::process_search_term_keys( $this->field_def->default_value() ) );
    } 
    else 
    {  
      $timestamp = $this->value;
    }
    
    $field_atts['timestamp'] = $timestamp;
    
    $date_display = new PDb_Date_Display( $field_atts );
    
    $this->value = $date_display->output();

    $this->_addline( $this->_input_tag() );
  }

  /**
   * builds a file upload element
   * 
   * @param string $type the upload type: file or image
   */
  protected function _upload( $type )
  {
    $field_default = $this->is_pdb_field() ? $this->field_def->default_value() : '';
    
    $this->_addline( '<div class="' . $this->prefix . 'upload">' );
    // if a file is already defined, show it
    if ( $this->value !== $field_default ) {

      $this->_addline( self::get_field_value_display( $this ) );
    }

    // add the MAX_FILE_SIZE field
    // this is really just for guidance, not a valid safeguard; this must be checked on submission
    if ( isset( $this->options['max_file_size'] ) ) {
      $max_size = $this->options['max_file_size'];
    } else {
      $max_size = ( (int) ini_get( 'post_max_size' ) / 2 ) * 1048576; // half it to give a cushion
    }

    $this->_addline( $this->print_hidden_fields( array('MAX_FILE_SIZE' => $max_size, $this->name => $this->value), false ) );

    if ( !isset( $this->attributes['readonly'] ) ) {
      
      if ( !empty( $this->value ) ) {
        unset( $this->attributes['required'] );
      }

      $this->_addline( $this->_input_tag( 'file' ) );

      // add the delete checkbox if there is a file defined and showing the switch is enabled in settings
      if ( $this->value !== $field_default && $this->module !== 'signup' && Participants_Db::plugin_setting( 'show_delete_switch', Participants_Db::plugin_setting( 'file_delete', 0 ) ) ) {
        unset( $this->attributes['id'] );
        $this->_addline( '<span class="file-delete" ><label><input type="checkbox" value="delete" name="' . esc_attr( $this->name . '-deletefile' ) . '" ' . $this->_attributes( 'no validate' ) . '>' . __( 'delete', 'participants-database' ) . '</label></span>' );
      }
    }

    $this->_addline( '</div>' );
  }

  /**
   * builds a password text element
   */
  protected function _password()
  {
    if ( !empty( $this->value ) ) {
      
      if ( is_object(Participants_Db::$validation_errors) ) {
      
        $valid = ! Participants_Db::$validation_errors->has_error($this->name);

        if ( !( $valid && Participants_Db::$validation_errors->errors_exist() ) ) {

          $this->value = $valid ? self::dummy : '';
        }
        
      } else {
        
        $this->value = self::dummy;
      }
      
    }

    $this->_addline( $this->_input_tag( 'password' ) );
  }

  /*   * ************************* 
   * UTILITY FUNCTIONS
   */

  /**
   * sets up the null select for dropdown elements
   */
  protected function _set_null_select()
  {
    $field = Participants_Db::get_column( $this->name );

    $default = '';
    if ( $field ) {
      $default = $field->default;
    }
    
    // swap the null select option if the value is the title
    $null_select_index = array_search( self::null_select_key(), $this->options );
    if ( $null_select_index !== false ) {
      $this->options[self::null_select_key()] = $null_select_index;
      unset( $this->options[$null_select_index]);
    }

    /*
     * this is to add a blank null select option if there is no default, no defined 
     * null select and no set field value
     */
    if ( self::is_empty( $default ) && !isset( $this->options[self::null_select_key()] ) && self::is_empty( $this->value ) ) {
      $this->options[self::null_select_key()] = '';
    }

    parent::_set_null_select();
  }

  /**
   * outputs a link (HTML anchor tag) in specified format if enabled by "make_links"
   * option
   * 
   * this func validates the link as being either an email addres or URI, then
   * (if enabled) builds the HTML and returns it
   * 
   * @param PDb_Field_Item $field the field object
   * @param string $template the format of the link (optional)
   * @param array  $get an array of name=>value pairs to include in the get string
   *
   * @return string HTML or HTML-escaped string (if it's not a link)
   */
  public static function make_link( $field, $template = false, $get = false )
  {
    if ( ! is_a( $field, 'PDb_Field_Item' ) ) {      
      $field = new PDb_Field_Item( $field );
    }
    /* @var PDb_Field_Item $field */

    // clean up the provided string
    $URI = str_replace( 'mailto:', '', trim( strip_tags( (string) $field->get_value() ) ) );
    
    if ( $field->has_link() ) {
      /*
       * the field is a single record link or other field with the link property 
       * set, which becomes our href
       */
      $URI = $field->link();
      $linktext = PDb_Manage_Fields_Updates::sanitize_text($field->get_value());
      
    } elseif ( filter_var( $URI, FILTER_VALIDATE_URL ) !== false && Participants_Db::plugin_setting_is_true( 'make_links' ) ) {

      // convert the get array to a get string and add it to the URI
      if ( is_array( $get ) ) {

        $URI .= false !== strpos( $URI, '?' ) ? '&' : '?';

        $URI .= http_build_query( $get );
      }
    } elseif ( filter_var( $URI, FILTER_VALIDATE_EMAIL ) !== false && Participants_Db::plugin_setting_is_true( 'make_links' ) ) {

      if ( Participants_Db::plugin_setting_is_true( 'email_protect' ) && !Participants_Db::$sending_email ) {

        // the email gets displayed in plaintext if javascript is disabled; a clickable link if enabled
        list( $URI, $linktext ) = explode( '@', $URI, 2 );
        $template = '<a class="obfuscate" data-email-values=\'{"name":"%1$s","domain":"%2$s"}\'>%1$s AT %2$s</a>';
      } else {
        $linktext = strip_tags( $URI );
        $URI = 'mailto:' . $URI;
      }
    } elseif ( filter_var( $URI, FILTER_VALIDATE_EMAIL ) !== false && Participants_Db::plugin_setting_is_true( 'email_protect' ) && !Participants_Db::$sending_email ) {

      /**
       * @todo obfuscate other email links
       * if the email address is wrapped in a link, we should obfuscate it
       */
      return $URI;
    } else {
      // if it is neither URL nor email address simply display the sanitized text
      return PDb_Manage_Fields_Updates::sanitize_text($field->get_value());
    }

    // default template for links
    $linktemplate = $template === false ? '<a href="%1$s" %3$s >%2$s</a>' : $template;

    $linktext = empty( $linktext ) ? str_replace( array('http://', 'https://'), '', $URI ) : $linktext;
    
    $attributes = self::html_attributes($field->attributes, array('rel','download','target','type'));

    //construct the link
    return sprintf( $linktemplate, $URI, $linktext, $attributes );
  }

  /**
   * tells if the current screen is the admin list page
   * 
   * @return bool true if on that page
   */
  private static function is_admin_list_page()
  {
    if ( function_exists( 'get_current_screen' ) && $screen = get_current_screen() ) {
      return $screen->id === 'toplevel_page_participants-database';
    }
    return false;
  }

  /**
   * get the title that corresponds to a value from a value series
   * 
   * this func grabs the value and matches it to a title from a list of values set 
   * for a particular field
   * 
   * if there is no title defined, or if the values is stored as a simple string, 
   * the value is returned unchanged
   * 
   * @global object $wpdb
   * @param array $values
   * @param string $fieldname
   * @return array of value=>title pairs
   */
  public static function get_value_titles( $values, $fieldname )
  {
    $options_array = Participants_Db::$fields[$fieldname]->options();
    return array_flip( $options_array );
  }

  /**
   * get the title that corresponds to a value from a value series
   * 
   * this func grabs the value and matches it to a title from a list of values set 
   * for a particular field
   * 
   * if there is no title defined, or if the values are stored as a simple string, 
   * the value is returned unchanged
   * 
   * @param string $value
   * @param string $fieldname
   * @return string the title matching the value
   */
  public static function get_value_title( $value, $fieldname )
  {
    $field = PDb_Form_Field_Def::is_field( $fieldname ) ? Participants_Db::$fields[$fieldname] : false;
    /* @var $field PDb_Form_field_Def */
    return $field ? $field->value_title($value) : $value;
  }
  
  /**
   * provides a matching option value if available
   * 
   * returns the value if no match found or if the field is not a value set (selector) field
   * 
   * @param string $title the title of the value
   * @param string $fieldname the name of the field
   * @return string the value
   */
  public static function maybe_option_value ( $title, $fieldname )
  { 
    $value = $title; // if no match is found, return the title argument
    
    $field_def = Participants_Db::get_field_def($fieldname);
    
    if ( $field_def->is_value_set() ) {
      
      // reject terms that match utility options
      if ( in_array( $value, array( 'null_select' ) ) ) {
        return $value;
      }
      
      $options_array = $field_def->options();
      
      // first check if there is a direct match to a regular option
      if ( isset( $options_array[$title] ) && ! in_array( $options_array[$title], array( 'optgroup', 'other' ) ) ) {
        return $options_array[$title];
      }
      
      // if the "title" is actually a value, return the value
      if ( array_search( $title, $options_array ) ) {
        return $title;
      }
      
      /*
       * if we haven't found the option yet, we perform a search on the options 
       * array for a close match
       * 
       * first, strip out any tags in the keys
       */
      $options_array = self::striptags_keys( $field_def->options() );
      
      // now check if there is a direct case-insensitive match with a tag-stripped title
      if ( isset( $options_array[strtolower($title)] ) ) {
        return $options_array[strtolower($title)];
      }
    }
    
    return $value;
  }

  /**
   * gets the option value that corresponds to an option title from a set of field options
   * 
   * this uses a progressive match, first trying an exact match, then substring 
   * matches, then a similar string match to efficiently find direct matches but 
   * return a best-guess close match if no direct match is found
   * 
   * @param string $title the title of the value
   * @param string $fieldname the name of the field
   * @return string the value that matches the title given
   */
  public static function get_title_value( $title, $fieldname )
  {
    $value = $title; // if no match is found, return the title argument
    
    $field_def = new PDb_Form_Field_Def( $fieldname );
    
    if ( $field_def && $field_def->is_value_set() ) {
      
      $options_array = $field_def->options();
      
      // first check if there is a direct match
      if ( isset( $options_array[$title] ) ) {
        return $options_array[$title];
      }
      
      // if the "title" is actually a value, return the value
      if ( array_search( $title, $options_array ) ) {
        return $title;
      }
      
      /*
       * if we haven't found the option yet, we perform a search on the options 
       * array for a close match
       * 
       * first, strip out any tags in the keys
       */
      $options_array = self::striptags_keys($field_def->options());
      
      // now check if there is a direct case-insensitive match with a tag-stripped title
      if ( isset( $options_array[strtolower($title)] ) ) {
        return $options_array[strtolower($title)];
      }
      
     /*
      * if a direct match doesn't find it, get a set of possible close matches and choose 
      * the closest one from that subset
      * 
      */
          
      /**
       * sets a lower limit to the number of characters that must match for 
       * the value to be considered found
       * 
       * @filter pdb-min_title_match_length
       * @param int the default value
       * @return int
       */
      $min_match_length = Participants_Db::apply_filters( 'min_title_match_length', 4 );

      // strip out wildcards
      $title = str_replace( array('*','?','_','%'), '', $title );

      // don't try to match if the string is too short
      if ( strlen( $title ) < $min_match_length ) {
        return $value;
      }

      // get a list of the substring matches from the options
      $match_list = preg_grep( '/' . preg_quote( $title, '/' ) . '/i', array_keys( $options_array ) );

      // if we find a substring match, find the closest mathching title
      if ( ! empty( $match_list ) ) {
        // find the closest match
        $ranked_matches = array();
        foreach( $match_list as $match ) {
          $match_len = similar_text( strtolower($title), strtolower($match), $rank );
          if ( $match_len >= $min_match_length  ) {
            $ranked_matches[(string)$rank] = $match;
          }
        }
        
        if ( count( $ranked_matches ) > 0 ) {
          ksort( $ranked_matches, SORT_NUMERIC );
          $value = $options_array[ end($ranked_matches) ];
        }
      }
      
    }
    return $value;
  }
  
  /**
   * strips the tags out of the array keys
   * 
   * this generally used on the options array to make the elements easier to get by the index
   * 
   * @param array
   * @return array
   */
  protected static function striptags_keys( $array )
  {
    $sanitized = array();
    foreach ( $array as $key => $value ) {
      $sanitized[ strip_tags( $key ) ] = $value;
    }
    return $sanitized;
  }

  /**
   * builds a string of attributes for inclusion in an HTML element
   *
   * @param string $filter to apply to the array
   * @return string
   */
  protected function _attributes( $filter = 'none' )
  {
    /**
     * @version 1.7.0.9
     * @filter pdb-form_element_attributes_filter
     * @param array the attributes array in name=>value format
     * @param string the name of the filter called
     */
    $attributes_array = Participants_Db::apply_filters( 'form_element_attributes_filter', $this->attributes, $filter );
    
    switch ( $filter ) {
      case 'none':
        break;
      case 'no validate':
        foreach ( array('required', 'maxlength', 'pattern') as $att ) {
          unset( $attributes_array[$att] );
        }
        break;
    }

    return parent::_attributes( $attributes_array );
  }

  /**
   * provides a list of all defined form elements
   * 
   * @return array as $name => $title
   * 
   */
  public static function get_types()
  {
    $cachekey = 'pdb-form_element_types';
    
    $types = wp_cache_get($cachekey);
    
    if ( is_array( $types ) )
    {
      return $types;
    }
    
    $types = array(
        'text-line' => __( 'Text-line', 'participants-database' ),
        'text-area' => __( 'Text Area', 'participants-database' ),
        'rich-text' => __( 'Rich Text', 'participants-database' ),
        'checkbox' => __( 'Checkbox', 'participants-database' ),
        'radio' => __( 'Radio Buttons', 'participants-database' ),
        'dropdown' => __( 'Dropdown List', 'participants-database' ),
        'date' => __( 'Date Field', 'participants-database' ),
        'numeric' => __( 'Numeric', 'participants-database' ),
        'decimal' => __( 'Decimal', 'participants-database' ),
        'currency' => __( 'Currency', 'participants-database' ),
        'dropdown-other' => __( 'Dropdown/Other', 'participants-database' ),
        'multi-checkbox' => __( 'Multiselect Checkbox', 'participants-database' ),
        'multi-dropdown' => __( 'Multiselect Dropdown', 'participants-database' ),
        'select-other' => __( 'Radio Buttons/Other', 'participants-database' ),
        'multi-select-other' => __( 'Multiselect/Other', 'participants-database' ),
        'link' => __( 'Link Field', 'participants-database' ),
        'image-upload' => __( 'Image Upload Field', 'participants-database' ),
        'file-upload' => __( 'File Upload Field', 'participants-database' ),
        'hidden' => __( 'Hidden Field', 'participants-database' ),
        'password' => __( 'Password Field', 'participants-database' ),
        'captcha' => __( 'CAPTCHA', 'participants-database' ),
    );
    /**
     * this gives access to the list of form element types for alteration before
     * it is set
     * @filter pdb-set_form_element_types
     * @param array of core form element types
     * @return array of all form element types
     */
    $all_types = Participants_Db::apply_filters( 'set_form_element_types', $types );
    
    wp_cache_set($cachekey, $all_types);
    
    return $all_types;
  }
  
  /**
   * tells if the string matches a defined form element type
   * 
   * @param string $form_element
   * @return bool true if the form element type is defined
   */
  public static function is_form_element( $form_element )
  {
    return array_key_exists( $form_element, self::get_types() );
  }

  /**
   *  tells if a field stores it's value as an array
   * 
   * any new form element that does this is expected to register with this list
   * 
   * @param string  $form_element the name of the form element
   * 
   * @return bool true if the element is stored as an array
   */
  public static function is_multi( $form_element )
  {
    return in_array( $form_element, Participants_Db::apply_filters( 'multi_form_elements_list', array('multi-checkbox', 'multi-select-other', 'link', 'multi-dropdown') ) );
  }

  /**
   * returns a MYSQL datatype appropriate to the form element type
   * 
   * @param string|array $element the (string) form element type or (array) field definition array
   * @return string the name of the MySQL datatype
   */
  public static function get_datatype( $element )
  {
    $form_element = is_array( $element ) ? $element['form_element'] : $element;
    $fieldname = is_array( $element ) ? $element['name'] : '';
    /**
     * @version 1.7.0.7
     * @filter pdb-form_element_datatype
     * 
     * @param string $datatype the datatype found by the parent method
     * @param string  $form_element the name of the form element
     * @param string name of the field if defined
     * @return string $datatype 
     */
    return Participants_Db::apply_filters( 'form_element_datatype', parent::get_datatype( $form_element ), $form_element, $fieldname );
  }
  
  /**
   * provides some property values
   * 
   * this is for backward compatibility for removed class properties
   * 
   * @param string $name of the property
   * @return mixed property value
   */
  public function __get( $name )
  {
    switch ( $name ) {
      
      case 'type':
        return $this->form_element;
        
      case 'size':
        return isset($this->attributes['size']) ? $this->attributes['size'] : '';
        
      default:
        Participants_Db::debug_log( __METHOD__.' invalid property: ' .  $name );
    }
  }

}
