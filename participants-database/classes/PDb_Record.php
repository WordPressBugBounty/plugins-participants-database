<?php

/**
 * handles the presentation of the editable frontend record
 * 
 *  * 
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2015 xnau webdesign
 * @license    GPL2
 * @version    1.7
 * @link       http://xnau.com/wordpress-plugins/
 * 
 */
if ( !defined( 'ABSPATH' ) )
  die;
/*
 * class for displaying an editable record on the frontend with the [pdb_record] shortcode
 *
 */

class PDb_Record extends PDb_Shortcode {

  /**
   * @var string class for the wrapper
   */
  var $wrap_class = 'participant-record';

  /**
   * @var string the originating page in a multipage form
   */
  var $previous_multipage;

  // methods

  /**
   * initializes the record edit object
   */
  public function __construct( $shortcode_atts )
  {
    $this->rich_text_editor_includes();
    
    // define shortcode-specific attributes to use
    $add_atts = array(
        'module' => 'record',
        'class' => 'edit-participant ' . $this->wrap_class,
        'submit_button' => Participants_Db::plugin_setting( 'save_changes_button' ),
    );
    
    // run the parent class initialization to set up the parent methods 
    parent::__construct( $shortcode_atts, $add_atts );

    add_action( 'pdb-before_field_added_to_iterator', array($this, 'alter_field') );

    $this->_setup_multipage();

    // set the action URI for the form
    $this->_set_submission_page();

    if ( false === $this->shortcode_atts['record_id'] ) 
    {
      $this->participant_values = array();
      $this->_not_found();
    } 
    else 
    {
      $this->participant_id = $this->shortcode_atts['record_id'];

      $record_values = Participants_Db::get_participant( $this->participant_id );

      if ( $record_values === false ) 
      {  
        // the ID is not valid
        $this->participant_id = 0;
        $this->_not_found();   
      } 
      else 
      {
        // drop in any default values 
        $this->participant_values = array_merge(
                array_filter( Participants_Db::get_default_record(), 'Participants_Db::is_set_value' ),
                array_filter( $record_values, 'Participants_Db::is_set_value' )
        );
      
        if ( Participants_Db::pid_in_url( $this->participant_id ) )
        {
          new \PDb_shortcodes\user_access_action( ( $this->participant_id  ) );
        }

        // update the access timestamp
        Participants_Db::set_record_access( $this->participant_id );

        $this->_get_validation_errors();

        $this->_setup_iteration();

        /**
         * @action pdb-open_record_edit
         * @param array record data
         */
        do_action( 'pdb-open_record_edit', $this->participant_values );

        $this->_print_from_template();
      }
    }
  }

  /**
   * prints a signup form called by a shortcode
   *
   * this function is called statically to instantiate the Signup object,
   * which captures the output and returns it for display
   *
   * @param array $params parameters passed by the shortcode
   * @return string form HTML
   */
  public static function print_form( $params )
  {
    $record = new self( $params );

    return $record->output;
  }
  
  /**
   *  provides the record ID if present in the shortcode attribute
   * 
   * includes handling of the deprecated 'id' attribute
   * 
   * @param array $atts shortcode attributes
   * @return int|bool the record ID; bool false if not found in the attributes
   */
  public static function get_id_from_shortcode( $atts )
  {
    $record_id = false;
    
    // checking 'id' atribute for backward compatibility
    if ( (isset( $atts['id'] ) || isset( $atts['record_id'] ) ) ) {
      if ( isset( $atts['id'] ) & !isset( $atts['record_id'] ) ) {
        $atts['record_id'] = $atts['id'];
      }
      $record_id = Participants_Db::get_record_id_by_term( 'id', $atts['record_id'] );
    }
    
    return $record_id;
  }

  /**
   * includes the shortcode template
   */
  protected function _include_template()
  {
    include $this->template;
  }
  
  /**
   * sets up the hidden fields for the record form
   */
  protected function _setup_hidden_fields()
  {
    parent::_setup_hidden_fields();
    
    /**
     * @filter pdb-record_form_hidden_fields
     * @param array as $fieldname => $value
     * @return array
     */
    $this->hidden_fields = Participants_Db::apply_filters( 'record_form_hidden_fields', $this->hidden_fields );
  }

  /**
   * prints the form header and hidden fields
   */
  public function print_form_head()
  {
    $hidden = array(
        'action' => 'update',
        'id' => $this->participant_id,
        Participants_Db::$record_query => $this->participant_values['private_id'],
    );

    $this->_print_form_head( $hidden );
  }

  /**
   * prints the submit button
   *
   * @param string $class a classname for the submit button, defaults to 'button-primary'
   * @param string $button_value submit button text
   * 
   */
  public function print_submit_button( $class = 'button-primary', $button_value = false )
  {
    if ( !empty( $this->participant_id ) ) {

      $button_value = $button_value ? $button_value : $this->shortcode_atts['submit_button'];

      $pattern = '<input class="%s pdb-submit" type="submit" value="%s" name="save" >';

      printf( $pattern, $class, $button_value );
    }
  }

  /**
   * prints a "next" button for multi-page forms
   * 
   * this is simply an anchor to the thanks page
   * 
   * @return string
   */
  public function print_back_button()
  {
    if ( strlen( $this->previous_multipage ) > 0 ) {
      printf( '<a type="button" class="button button-secondary" href="%s" >%s</a>', $this->previous_multipage, __( 'back', 'participants-database' ) );
    }
  }

  /**
   * alters a password field to prevent (hopefully!) autocomplete
   */
  public function alter_field( $field )
  {
    switch ( $field->form_element ) {
      case 'password':
        $field->attributes['autocomplete'] = 'off';
        break;
    }
  }

  /**
   * prints a 'save changes' label according to the plugin setting
   */
  private function print_save_changes_label()
  {
    echo esc_html( Participants_Db::plugin_setting( 'save_changes_label' ) );
  }

  /**
   * outputs a "record not found" message
   *
   * the message is defined in the plugin settings
   */
  protected function _not_found()
  {
    if ( Participants_Db::plugin_setting_is_true( 'no_record_use_template' ) || version_compare( $this->template_version, '0.2', '<' ) ) 
    {
      $this->_print_from_template();
    } 
    else 
    {
      $error_message = Participants_Db::plugin_setting( 'no_record_error_message' );
      $this->output = empty( $error_message ) ? '' : '<p class="alert alert-error">' . $error_message . '</p>';
    }
  }

  /**
   * sets the form submission page
   */
  protected function _set_submission_page()
  {
    $form_status = $this->get_form_status();

    if ( !empty( $this->shortcode_atts['action'] ) ) {
      $this->submission_page = Participants_Db::find_permalink( $this->shortcode_atts['action'] );
      if ( $this->submission_page !== false && $form_status === 'normal' ) {
        $form_status = 'multipage-update';
      }
    }

    if ( !$this->submission_page ) {
      $this->submission_page = $_SERVER['REQUEST_URI'];
    }

    $this->set_form_status( $form_status );
  }

  /**
   * sets up the multipage referral
   * 
   * @retrun null
   */
  protected function _setup_multipage()
  {
    $this->previous_multipage = Participants_Db::$session->get( 'previous_multipage', '' );
    
    if ( $this->previous_multipage && strlen( $this->previous_multipage ) === 0 ) {
      $this->clear_multipage_session();
    }
  }
  
  /**
   * includes the code support for the rich text editor
   * 
   * @global wpdb $wpdb
   */
  private function rich_text_editor_includes()
  {
    global $wpdb;
    
    $rich_text_field_exists = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Participants_Db::$fields_table . ' f WHERE f.form_element = "rich-text"' );
    
    if ( $rich_text_field_exists ) {
      require_once( ABSPATH . WPINC . '/class-wp-editor.php' );
      \_WP_Editors::enqueue_default_editor();
    }
  }

}
