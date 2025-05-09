<?php

/**
 * defines a field that shows the result of a shortcode
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2021  xnau webdesign
 * @license    GPL3
 * @version    2.1
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace PDb_fields;

defined( 'ABSPATH' ) || exit;

class shortcode extends core {

  /**
   * @var string name of the form element
   */
  const element_name = 'shortcode';

  /**
   * 
   */
  public function __construct()
  {
    parent::__construct( self::element_name, 'Shortcode' );
    
    add_filter( 'pdb-field_default_attribute_edit_config', array( $this, 'change_default_attribute_title' ), 10, 2 );
    
    $this->is_dynamic_field();
    
    $this->is_mass_edit_field();
  }
  
  /**
   * sets the translated title of the field
   * 
   * this is triggered in the 'init' hook to avoid a too-early translation load
   */
  public function set_translated_title()
  {
    $this->title = _x( 'Shortcode', 'name of a field type that shows a shortcode', 'participants-database' );
  }

  /**
   * display the field value in a read context
   * 
   * @return string
   */
  protected function display_value()
  {
    if ( strpos( $this->field->module(), 'list' ) !== false && ! $this->field->get_attribute( 'list_enable' ) ) {
      return $this->field->value();
    }
    
    $output = array();
    
    $output[] = $this->shortcode_content();
    
    if ( $this->field->get_attribute( 'show_value' )  ) {
      $output[] = '<span class="field-value">' . $this->field->value() . '</span>';
    }
    
    return implode( PHP_EOL, $output );
  }
  

  /**
   * provides the HTML for the form element in a write context
   * 
   * @param \PDb_FormElement $field the field definition
   * @return null
   */
  public function form_element_build( $field )
  {
    parent::form_element_build( $field );
  }

  /**
   * displays the field in a write context
   * 
   * @return string
   */
  public function form_element_html()
  {
    $output = array( '<div class="shortcode-field-wrap" >' );

    $parameters = array(
        'type' => 'text-line',
        'value' => $this->field_value(),
        'name' => $this->field->name(),
    );

    $output[] = \PDb_FormElement::get_element( $parameters );

    $output[] = '</div>';
    
    
    if ( $this->field->get_attribute( 'preview' )  ) {
      $output[] = '<div class="shortcode-field-preview">' . $this->shortcode_content() . '</div>';
    }

    return implode( PHP_EOL, $output );
  }

  /**
   * provides the shortcode content
   * 
   * @return string
   */
  private function shortcode_content()
  { 
    $shortcode = new shortcode_string( $this->field->default_value );
    
    /**
     * allows a computed value to be used for the shortcode replacement value
     * 
     * @filter pdb-shortcode_field_replacement_value
     * @param string the field's value
     * @param \Pdb_fields\shortcode instance
     * @return string replacement value
     */
    $replacement = \Participants_Db::apply_filters( 'shortcode_field_replacement_value', $this->field_value(), $this );
    
    $replaced_shortcode = $shortcode->replaced_shortcode( $this->field_value() );

    $done_shortcode = $this->execute_shortcode( $replaced_shortcode );
    
    // if the shortcode doesn't get processed, show nothing
    if ( $done_shortcode === $replaced_shortcode ) {
      return '';
    }
    
    add_filter( 'pdb-allowed_html_post', array( $this, 'allow_media_tags' ) );
    add_filter( 'pdb-allowed_html_form', array( $this, 'allow_media_tags' ) );
    
    return $done_shortcode;
  }
  
  /**
   *  provides the filtered (executed) shortcode content
   * 
   * @param string $shortcode
   * @return string HTML
   */
  private function execute_shortcode( $shortcode )
  {
    if ( strpos( $shortcode, '[embed' ) !== 0 ) {
      return do_shortcode( $shortcode );
    }
    
    // the [embed] shortcode is a special case
    global $wp_embed;
    return is_object( $wp_embed ) ? $wp_embed->run_shortcode( $shortcode ) : '';
  }
  
  /**
   * adds iframes to the allowed tags array
   * 
   * @param array $allowed
   * @return array
   */
  public function allow_media_tags($allowed)
  {  
    remove_filter( 'pdb-allowed_html_post', array( $this, 'allow_media_tags' ) );
    remove_filter( 'pdb-allowed_html_form', array( $this, 'allow_media_tags' ) );
    // enable iframes
    $allowed['iframe'] = array( 
        'title' => 1,
        'width' => 1,
        'height' => 1,
        'src' => 1,
        'frameborder' => 1,
        'allow' => 1,
        'allowfullscreen' => 1,
        );
    $allowed['audio'] = array(
        'class' => 1,
        'id' => 1,
        'preload' => 1,
        'style' => 1,
        'controls' => 1,
    );
    $allowed['source'] = array(
        'type' => 1,
        'src' => 1,
    );
    return $allowed;
  }
  
  /**
   * provides the field's value
   * 
   * @return string
   */
  private function field_value()
  {
    $record = \Participants_Db::get_participant( $this->field->record_id );
    
    return $record && array_key_exists( $this->field->name(), $record ) ? $record[ $this->field->name() ] : '';
  }

  /**
   * provides the form element's mysql datatype
   * 
   * @return string
   */
  protected function element_datatype()
  {
    return 'text';
  }

  /**
   *  provides the field editor configuration switches array
   * 
   * @param array $switches
   * @return array
   */
  public function editor_config( $switches )
  {
    return array(
        'readonly' => true,
        'help_text' => true,
        'persistent' => false,
        'signup' => true,
        'validation' => true,
        'validation_message' => true,
        'csv' => true,
        'sortable' => false,
    );
  }
  
  /**
   * changes the title of the default attribute in the field editor
   * 
   * @param array $config the editor attribute configuration
   * @param \PDb_Form_Field_Def $field the field definition
   * @return array the configuration array
   */
  public function change_default_attribute_title( $config, $field )
  {
    if ( $field->form_element() === $this->name ) {
      $config['label'] = __( 'Shortcode', 'participants-database' );
      $config['type'] = 'text-area';
    }
    return $config;
  }
}

class shortcode_string
{
  /**
   * @pvar string the shortcode string
   */
  private $shortcode;
  
  /**
   * @var string the placeholder
   */
  const placeholder = '%1$s';
  
  /**
   * sets up the object
   * 
   * @param string $shortcode the raw shortcode
   */
  public function __construct( $shortcode )
  {
    $this->setup( $shortcode );
  }
  
  /**
   * tells if the shortcode has a placeholder
   * 
   * @return bool
   */
  public function has_placeholder()
  {
    return strpos( $this->shortcode, self::placeholder ) !== false;
  }
  
  /**
   * supplies the replaced shortcode
   * 
   * @param string $replacement the data to replace the placeholders with
   * @return string
   */
  public function replaced_shortcode( $replacement )
  {
    return $this->has_placeholder() ? sprintf( $this->shortcode, $replacement ) : $this->shortcode;
  }
  
  /**
   * sets up the shortcode property
   * 
   * @param string $shortcode
   */
  private function setup ( $shortcode )
  {
    // convert the placeholders to enumerated placeholders in case there are several
    $this->shortcode = str_replace('%s', self::placeholder, $shortcode );
  }
}
