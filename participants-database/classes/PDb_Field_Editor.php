<?php

/**
 * manages the field editor for PDB field form element types
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2018  xnau webdesign
 * @license    GPL3
 * @version    1.3
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */
defined( 'ABSPATH' ) || exit;

class PDb_Field_Editor {

  /**
   * @var array of attribute statuses
   */
  protected $definition_attributes;

  /**
   * @var PDb_Form_Field_Def the current field definition
   */
  protected $field_def;

  /**
   * @var the row color class
   */
  protected $colorclass;
  
  /**
   * @var bool we use this to class the first checkbox field
   */
  protected $first_checkbox = true;

  /**
   * creates the object
   * 
   * @param PDb_Form_Field_Def $field_def
   */
  public function __construct( PDb_Form_Field_Def $field_def )
  {
    $this->field_def = $field_def;

    $this->setup_switches();
  }

  /**
   * provides the HTML for the field's next definition attribute control
   * 
   * this will be used in a loop like:
   *  while ( $html = get_next_control() ) { print $html; }
   * 
   * @return string|bool HTML or bool false if end has been reached
   */
  public function get_next_control()
  {
    $html = false;
    if ( current( $this->definition_attributes ) ) {
      $html = $this->get_att_control( key( $this->definition_attributes ) );
      next( $this->definition_attributes );
    }
    return $html;
  }
  
  /**
   * provides a classname for the row container
   * 
   * @return string
   */
  public function rowclass ()
  {
    return $this->field_def->form_element() . '-form-element color-' . $this->colorclass . ' ' . $this->open_close_class();
  }
  
  
  /**
   * provides the open/close class for the field
   * 
   * @return string classname
   */
  public function open_close_class()
  {
    $session_value = Participants_Db::$session->getArray(PDb_Manage_Fields_Updates::action_key, array() );
    $field_id = $this->field_def->get_prop( 'id' );
    
    return isset( $session_value['editoropen'][$field_id] ) && $session_value['editoropen'][$field_id] ? 'editor-open' : 'editor-closed';
  }
  
  /**
   * provides the HTML for the hidden inputs
   * 
   * these are field editor controls that are suppressed but must be included as 
   * static values in the editor submission
   * 
   * @return string HTML
   */
  public function get_hidden_inputs()
  {
    $html = array();
    
    foreach( array( 'form_element' ) as $hidden_att ) {
      if ( ! isset( $this->definition_attributes[$hidden_att] ) ) {
        $html[] = sprintf( '<input type="hidden" name="%s" value="%s" />', esc_attr( 'row_' . $this->field_def->id . '[' . $hidden_att . ']' ), esc_attr( $this->field_def->{$hidden_att} ) );
      }
    }
    
    return implode( PHP_EOL, $html );
  }

  /**
   * sets up the definition attribute switches
   */
  private function setup_switches()
  {
    $form_element_switches = $this->form_element_atts();
    $this->definition_attributes = Participants_Db::array_merge2( $this->default_def_att_switches(), $form_element_switches );
    
    if ( isset($form_element_switches['validation']) && $form_element_switches['validation'] === false ) {
      // disable the validation if disabled in the form element
      $this->definition_attributes['validation'] = false;
    }
    
    // this is so the deletable config isn't filtered out in either case
    $this->definition_attributes['deletable'] = $this->definition_attributes['deletable'] === false ? 2 : 1;
    
    /**
     * @filter pdb-field_editor_switches
     * @param array of editor attribute enable/disable switches
     * @param PDb_Form_Field_Def field definition
     * @return array of switches
     */
    $this->definition_attributes = array_filter( Participants_Db::apply_filters('field_editor_switches', $this->definition_attributes, $this->field_def ) ); // remove the disabled elements
    
    reset( $this->definition_attributes );
  }

  /**
   * provides the control HTML for the named attribute
   * 
   * @param string $attribute a field parameter
   * 
   * @return string
   */
  protected function get_att_control( $attribute )
  {
    $field_def_att = $this->def_att_object($attribute);
    
    switch ( true ) {

      case ( $attribute === 'selectable' ):
        $lines = array(
            '<div class="field-header">',
            '<span class="editor-opener dashicons field-close-icon" title="' . _x('Close', 'label for a "close" control', 'participants-database') . '" ></span>',
            '<span class="editor-opener dashicons field-open-icon" title="' . _x('Open for editing','label for an "open" control', 'participants-database') . '" ></span>',
            $field_def_att->html(),
        );
        break;

      case ( $attribute === 'orderable' ):
        $lines = array(
            $field_def_att->html(),
        );
        break;

      case ( $attribute === 'deletable' ):
        $title = $this->field_def->title();
        $lines = array(
            $this->yes_show_delete_button() ? $field_def_att->html() : '',
            '<h4>' . ( strlen( trim( $title ) ) === 0 ? $this->field_def->name() : $title ) . '</h4>',
            '</div>
              <div class="form-element-label" >' . $this->field_def->form_element_title() . '</div>',
        );
        break;

      case ( $field_def_att->is_checkbox() ):
        $lines = array(
            $this->first_checkbox ? '<break></break>' : '',
            '<div class="attribute-control ' . $attribute . '-attribute ' . $field_def_att->type() . '-control-wrap">',
            $field_def_att->has_label() ? '<label for="row_' . $this->field_def->id . '_' . $attribute . '">' . $field_def_att->label() : '',
            $field_def_att->html(),
            $field_def_att->has_label() ? '</label>' : '',
            '</div>',
        );
        $this->first_checkbox = false;
        break;
      
      case ( $attribute === 'groupable' ):
        // this is so the element ids match
        $attribute = 'group';

      default:
        $lines = array(
            $field_def_att->is_hidden() ? '' : '<div class="attribute-control ' . $attribute . '-attribute ' . $field_def_att->type() . '-control-wrap">',
            $field_def_att->html(),
            $field_def_att->has_label() ? '<label for="row_' . $this->field_def->id . '_' . $attribute . '">' . $field_def_att->label() . '</label>' : '',
            $field_def_att->is_hidden() ? '' : '</div>',
        );
    }

    /**
     * allows modification of the attribute editor control html
     * 
     * @filter pdb-field_editor_control_html
     * @param array the html lines
     * @param PDb_Field_Def_Parameter the field attribute object
     * @return array
     */
    return implode( PHP_EOL, Participants_Db::apply_filters('field_editor_control_html', $lines, $field_def_att ) );
  }
  
  /**
   * tells if the delete button should be shown
   * 
   * @return bool
   */
  private function yes_show_delete_button()
  {
    return ! $this->field_def->is_internal_field() && $this->definition_attributes['deletable'] === 1;
  }
  
  /**
   * provides the field definition attribute object
   * 
   * @param string $attribute name of the attribute
   * @return PDb_Field_Def_Parameter object
   */
  protected function def_att_object( $attribute )
  {
    $config = Participants_Db::array_merge2( array(
                'name' => 'row_' . $this->field_def->id . '[' . $attribute . ']',
                'value' => $this->attribute_value( $attribute ),
                'attributes' => array('id' => 'row_' . $this->field_def->id . '_' . $attribute),
            ), $this->attribute_config($attribute) );
    
    /**
     * provides a way to alter the field attribute editor
     * 
     * @filter pdb-field_{$attribute}_attribute_edit_config
     * @param array configuration
     * @param PDb_Form_field_Def field
     * @return array
     */
    return new PDb_Field_Def_Parameter( $attribute, Participants_Db::apply_filters( 'field_' . $attribute . '_attribute_edit_config', $config, $this->field_def ) );
  }
  
  
  /**
   * provides the configuration array for a specific attribute
   * 
   * @param string $attribute name of the attribute
   * @return array|bool false if the attribute is undefined
   */
  protected function attribute_config( $attribute )
  {
    switch ( $attribute ) {
      case 'id':
        $config = array(
            'type' => 'hidden',
            'value' => $this->field_def->id,
        );
        break;
      case 'status':
        $config = array(
            'type' => 'hidden',
            'attributes' => array('id' => 'status_' . $this->field_def->id),
            'value' => null,
        );
        break;
      case 'selectable':
        $config = array(
            'type' => 'checkbox',
            'options' => array(1, 0),
            'attributes' => array('data-id' => $this->field_def->id ),
        );
        break;
      case 'orderable':
        $config = array(
            'type' => 'drag-sort',
        );
        break;
      case 'deletable':
        $config = array(
            'type' => 'delete-button',
            'id' => $this->field_def->id,
        );
        break;
      case 'sortable':
      case 'csv':
      case 'persistent':
      case 'signup':
      case 'readonly':
        $config = array(
            'type' => 'checkbox',
            'options' => array(1, 0),
        );
        break;
      case 'name':
        $config = array(
            'type' => 'text-line',
            'attributes' => array('readonly' => 'readonly'),
        );
        break;
      case 'title':
        $config = array(
            'type' => 'text-line',
            'class' => $attribute . '-field',
            'attributes' => array('data-title' => $this->field_def->title() . ' (' . $this->field_def->name() . ')' ),
        );
        break;
      case 'default':
        if ( $this->field_def->is_value_set() ) {
          $config = array(
              'type' => 'dropdown',
              'options' => $this->field_options_clean(),
              'class' => $attribute . '-field',
          );
        } else {
          $config = array(
              'type' => 'text-line',
              'class' => $attribute . '-field',
          );
        }
        break;
      case 'help_text':
      case 'validation_message':
        $config = array(
            'type' => 'text-area',
        );
        break;
      case 'attributes':
        $config = array(
            'type' => 'text-area',
            'attributes' => array(
                'class' => 'parameter-list'
            ),
        );
        break;
      case 'options':
        $config = array(
            'type' => 'text-area',
            'attributes' => array(
                'class' => 'parameter-list option-list',
                'required' => 'required',
                'data-message' => __('You must define options for this field', 'participants-database'),
            ),
        );
        break;
      case 'groupable':
        $config = array(
            'name' => 'row_' . $this->field_def->id . '[group]',
            'type' => 'dropdown',
            'options' => self::group_options(),
            'attributes' => array('id' => 'row_' . $this->field_def->id . '_group'),
        );
        break;
      case 'form_element':
        if ( array_key_exists( $this->field_def->form_element(), $this->form_element_options() ) ) {
          $config = array(
              'type' => 'dropdown',
              'options' => array_flip( $this->form_element_options() ) + array(PDb_FormElement::null_select_key() => false),
              'attributes' => array('class' => 'form-element-select ' . ( $this->field_def->has_stored_data() ? 'column-has-values' : 'column-empty' ) ),
          );
        } else { // don't display the form element selector if the field's form element is not in the options
          $config = array(
              'type' => 'hidden',
              'attributes' => array('class' => 'form-element-select ' . ( $this->field_def->has_stored_data() ? 'column-has-values' : 'column-empty' ) ),
          );
        }
        break;
      case 'validation':
        $config = array(
            'type' => in_array('other', $this->validation_methods() ) ? 'dropdown-other' : 'dropdown',
            'options' => $this->validation_methods(),
        );
        break;
      default:
        $config = false;
    }
    return $config;
  }
  
  /**
   * provides a list of form element to choose from
   * 
   * @return array as $name => $title
   */
  protected function form_element_options()
  {
    /**
     * @filter pdb-field_editor_form_element_options
     * @param array of form element types
     * @return array
     */
    return Participants_Db::apply_filters( 'field_editor_form_element_options', PDb_FormElement::get_types() );
  }

  /**
   * provides the value of the field definition attribute
   * 
   * @param string $attribute name of the attribute
   * @param array $config
   * @return string
   */
  protected function attribute_value( $attribute )
  {
    switch ( $attribute ) {
      case 'title':
      case 'form_element':
      case 'default':
      case 'name':
      case 'group':
      case 'options':
      case 'help_text':
      case 'validation':
      case 'validation_message':
      case 'sortable':
      case 'csv':
      case 'persistent':
      case 'signup':
      case 'readonly':
        return $this->field_def->$attribute;
//        return $this->field_def->get_prop( $attribute );
        
      case 'attributes':
        return $this->field_attributes();
        
      case 'groupable':
        return $this->field_def->group();
        
      case 'orderable':
        return true;
        
      case 'id':
      case 'selectable':
      case 'deletable':
        return false;
        
      case 'status':
        return null;
    }
  }
  
  /**
   * provides the field's attribute values
   * 
   * this is primarily to update legacy attributes settings for upload fields
   * 
   * @return string
   */
  private function field_attributes()
  {
    $attributes = $this->field_def->attributes();
    
    if ( $this->field_def->is_upload_field() && ! isset( $attributes['allowed'] ) && ( array_values( $attributes ) === array_keys( $attributes ) ) ) {
      
      $allowed_attribute = implode( '|', array_filter( $attributes, 'trim' ) );
      
      $attributes = empty( $allowed_attribute ) ? '' : array( 'allowed' => $allowed_attribute );
    }
    
    return $attributes;
  }

  /**
   * provides the validation methods
   * 
   * this can only be called when the current $this->definition_attributes element is the 
   * validation config array
   * 
   * @return array of validation methods
   */
  protected function validation_methods()
  {
    $base_methods = array_flip( PDb_FormValidation::validation_methods() ) + array(PDb_FormElement::null_select_key() => false);
    
    foreach ( current( $this->definition_attributes ) as $method => $switch ) {
      
      if ( !$switch ) {
        unset( $base_methods[ array_search( $method, $base_methods ) ] );
      }
    }
    
    return $base_methods;
  }

/**
 * provides the group selector options
 * 
 * @return array of options
 */
  public static function group_options()
  {
    $options = array();
    foreach( Participants_Db::get_groups('name,title') as $group_data ) {
      if ( $group_data['name'] === 'internal' ) continue;
      $title = strlen( trim( $group_data['title'])) === 0 ? $group_data['name'] : $group_data['title'];
      $options[stripslashes($title)] = $group_data['name'];
    }
    return $options + array(PDb_FormElement::null_select_key() => false);
  }
  
  /**
   * provides the field's option values for use in a dropdown's options
   * 
   * this is to cope with the possibility that tags are present in the option title
   * 
   * @return array
   */
  protected function field_options_clean()
  {
    $options = array();
    
    foreach( $this->field_def->options() as $k => $v ) {
      $key = strip_tags($k);
      if ( strlen( $key ) === 0 ) {
        $key = strip_tags($v);
      }
      $options[$key] = $v;
    }
    
    return $options;
  }

  /**
   * provides the set of default definition attribute switches
   * 
   * this also defines the order of attributes in the editor
   * 
   * @return array
   */
  protected function default_def_att_switches()
  {
    return array(
        'id' => true,
        'status' => true,
        'selectable' => true,
        'orderable' => true,
        'deletable' => true,
        'title' => true,
        'name' => true,
        'groupable' => true,
        'form_element' => true,
        'help_text' => true,
        'options' => false,
        'validation' => array(
            'no' => true,
            'yes' => true,
            'email-regex' => true,
            'captcha' => false,
            'other' => true,
        ),
        'validation_message' => true,
        'default' => true,
        'attributes' => true,
        'signup' => true,
        'csv' => true,
        'readonly' => true,
        'sortable' => true,
        'persistent' => true,
    );
  }

  /**
   * provides the configuration array for a form element
   * 
   * this array only provides the attributes that are different from the default
   * 
   * @return array
   */
  public function form_element_atts()
  {
    // set up the built-in form elements
    switch ( $this->field_def->form_element() ) {
      
      case 'text-line':
      case 'text-area':
      case 'rich-text':
      case 'password':
        $this->colorclass = 'text';
        $def = array();
        break;
      
      case 'checkbox':
      case 'radio':
      case 'dropdown':
      case 'dropdown-other':
      case 'multi-checkbox':
      case 'multi-dropdown':
      case 'select-other':
      case 'multi-select-other':
        $this->colorclass = 'selector';
        $def = array(
            'options' => true,
            'validation' => array(
                'email-regex' => false,
            ),
        );
        break;
      
      case 'date':
      case 'numeric':
      case 'decimal':
      case 'currency':
        $this->colorclass = 'numeric';
        $def = array(
            'validation' => array(
                'email-regex' => false,
            ),
        );
        break;
      
      case 'link':
      case 'image-upload':
      case 'file-upload':
        $this->colorclass = 'upload';
        $def = array(
            'validation' => array(
                'email-regex' => false,
            ),
            'sortable' => false,
            'persistent' => false,
        );
        break;
      
      case 'hidden':
        $def = array(
            'help_text' => false,
            'validation' => false,
            'attributes' => false,
            'validation_message' => false,
        );
        $this->colorclass = 'utility';
        break;
      
      case 'captcha':
        $this->colorclass = 'captcha';
        $def = array(
            'default' => false,
            'csv' => false,
            'validation' => array(
                'email-regex' => false,
                'captcha' => true,
            ),
        );
        break;
      
      case 'timestamp':
        
        $this->colorclass = 'numeric';
        $def = array(
            'help_text' => false,
            'attributes' => false,
            'readonly' => false,
            'default' => false,
            'groupable' => false,
            'form_element' => false,
        );
        break;
      
      default:
        /**
         * defines the definition attribute switches for a specific form element type
         * 
         * determines which parameters are available to edit in the field editor
         * 
         * @filter pdb-{$form_element_name}_form_element_def_att_switches
         * 
         * @param bool false
         * @return array of specific definition attributes for the form element
         */
        $def = Participants_Db::apply_filters( $this->field_def->form_element() . '_form_element_def_att_switches', array() );
        
        /**
         * sets the color classname for the elemenmt
         * 
         * @filter pdb-{$form_element_name}_form_element_colorclass
         * 
         * @param string default classname
         * @return string classname
         */
        $this->colorclass = Participants_Db::apply_filters( $this->field_def->form_element() . '_form_element_colorclass', 'custom' );
    }
    
    if ( $this->field_def->group() === 'internal' ) {
      $def = array_merge( $def, array(
          'selectable' => true,
          'orderable' => false,
          'deletable' => true,
          'default' => false,
          'groupable' => false,
          'help_text' => false,
          'form_element' => false,
          'attributes' => false,
          'validation' => false,
          'validation_message' => false,
          'signup' => false,
          'readonly' => false,
          'persistent' => false,
          'csv' => true,
              )
      );
    }
    
    return $def;
  }

  /**
   * tells if the field's column has data in the main database
   * 
   * @global wpdb $wpdb
   * @param string $fieldname
   * @return bool true if the column has data
   */
  public static function db_column_has_data( $fieldname )
  {
    global $wpdb;
    /**
     * @filter pdb-field_data_table
     * 
     * @param string default table name
     * @param string field name
     * @return string table to use for this field
     */
    $table = Participants_Db::apply_filters('field_data_table', Participants_Db::$participants_table, $fieldname );
    
    if ( $wpdb->get_var('SHOW COLUMNS FROM ' . $table . ' LIKE "' . $fieldname . '"' ) !== $fieldname ) {
      return false;
    }
    
    $result = $wpdb->get_col( 'SELECT `' . $fieldname . '` FROM ' . $table );
    return count( array_filter( $result ) ) > 0;
  }

  /**
   * tells if the field's column has data in the main database
   * 
   * @global wpdb $wpdb
   * @param string $fieldname
   * @return bool true if the colum has data
   */
  public function column_has_data( $fieldname )
  {
    return self::db_column_has_data($fieldname);
  }

}

/**
 * manages a single field parameter edit control
 */
class PDb_Field_Def_Parameter {

  /**
   * @var array the control form element configuration array
   */
  protected $config;

  /**
   * @var string name of the attribute
   */
  protected $name;

  /**
   * @var string
   */
  protected $label;

  /**
   * sets up the item
   * 
   * @param string $name of the attribute
   * @param array $config
   */
  public function __construct( $name, $config )
  {
    $this->name = $name;
    $this->config = $config;
    $this->label = isset( $config['label'] ) && ! \PDb_FormElement::is_empty( $config['label'] ) ? $config['label'] : $this->get_label();
  }

  /**
   * provides the form element attribute control HTML
   * 
   * @return string
   */
  public function html()
  {
    switch ( $this->name )
    {
      case 'attributes':
      case 'options':
        
        $this->config['value'] = \PDb_FormElement::is_empty( $this->config['value'] ) ? '' : htmlspecialchars( PDb_Manage_Fields_Updates::array_to_string_notation( $this->config['value'] ) );
        break;
      
      case 'deletable':
        return $this->delete_button();
        
      case 'selectable':
        $this->config['attributes']['title'] = __('Select this field', 'participants-database');
        break;
      
      case 'help_text':
      case 'validation_message':
      case 'default':
        $this->config['value'] = \PDb_FormElement::is_empty( $this->config['value'] ) ? '' : htmlspecialchars( $this->config['value'] );
        break;
      
      case 'name':
        break;
    }
    
    return PDb_FormElement::get_element( $this->config );
  }
  
  /**
   * provides the HTML for the field delete button
   * 
   * @return string
   */
  private function delete_button()
  {
    return '<a href="#" data-thing-name="delete_' . $this->config['id'] . '" class="delete" data-thing="field" title="' . __('Delete this field', 'participants-database') . '"><span class="dashicons dashicons-no"></span></a>';
  }

  /**
   *  provides the attribute label
   * 
   * @return string
   */
  public function label()
  {
    return $this->label;
  }

  /**
   *  provides the attribute name
   * 
   * @return string
   */
  public function name()
  {
    return $this->name;
  }

  /**
   * tells if the attribute has a label and it should be added
   * 
   * @return bool true if the attribute has a label
   */
  public function has_label()
  {
    return $this->label !== '' && !$this->is_hidden();
  }

  /**
   * tells of the attribute is hidden
   * 
   * @return bool
   */
  public function is_hidden()
  {
    return $this->config['type'] === 'hidden';
  }

  /**
   * tells the control type
   * 
   * @return string
   */
  public function type()
  {
    return $this->config['type'];
  }

  /**
   * tells if the attribute control is a checkbox
   * 
   * @return bool
   */
  public function is_checkbox()
  {
    return $this->config['type'] === 'checkbox';
  }
  
  /**
   * provides the current value of the parameter
   * 
   * @return mixed
   */
  public function value()
  {
    return $this->config['value'];
  }

  /**
   *  provides the attribute label
   * 
   * @return string
   */
  protected function get_label()
  {
    $titles = PDb_Manage_Fields::get_i18n();

    switch ( $this->name ) {
      case 'default':
        $name = 'default';
        break;
      case 'groupable':
        $name = 'Group';
        break;
      case 'csv':
        $name = 'CSV';
        break;
      case 'readonly':
        $name = 'readonly';
        break;
      case 'validation':
        $name = $titles[$this->name] . $this->help_link( 'field-validation' );
        break;
      case 'options':
        $name = $titles[$this->name] . $this->help_link( 'field-options' );
        break;
      case 'attributes':
        $name = $titles[$this->name] . $this->help_link( 'field-attributes' );
        break;
      default:
        $name = $this->name;
    }
    return isset( $titles[$name] ) ? $titles[$name] : Participants_Db::apply_filters( 'translate_string', $name );
  }
  
  /**
   * provides a help link
   * 
   * @param string $anchor
   * @return string link HTML
   */
  protected function help_link( $anchor )
  {
    return sprintf( ' <a href="'.PDb_Manage_Fields::help_page.'#%s" target="_blank" class="pdb-help-link" ><span class="dashicons dashicons-editor-help"></span></a>', esc_attr( $anchor ) );
  }

}
