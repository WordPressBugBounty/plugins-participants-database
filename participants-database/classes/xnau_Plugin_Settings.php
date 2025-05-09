<?php
/**
 * plugin settings handler class
 *
 * uses the WP Settings API to manage settings,
 *
 * @version 1.5
 *
 * @depends xnau_FormElement class
 */
if ( !defined( 'ABSPATH' ) )
  die;

class xnau_Plugin_Settings {

  /**
   * 
   * @var string class name of the plugin-specific subclass
   */
  private $plugin_class;
  
  /**
   * @var string WP settings label
   */
  protected $WP_setting;
  
  /**
   * 
   * @var array all registered sections
   */
  protected $sections;
  
  /**
   * 
   * @var array descriptions for each section as needed;
   */
  protected $section_description;
  
  /**
   * 
   * @var array all individual settings
   */
  protected $plugin_settings;
  
  /**
   * 
   * @var string settings page slug
   */
  protected $settings_page;
  
  /**
   * 
   * @var string help text wrap HTML sprintf pattern
   */
  protected $help_text_wrap;
  
  /**
   * 
   * @var type wrapper HTML for the settings page submit button sprintf pattern
   */
  protected $submit_wrap;
  
  /**
   * 
   * @var string classname for the submit button
   */
  protected $submit_class;
  
  /**
   * 
   * @var string label for the submit button
   */
  protected $submit_button;

  /**
   * 
   * @var string name of the option storing a running option version number
   * 
   * this is incremented every time the options are saved so that the includes can 
   * be given a new version number
   */
  public $option_version_location;

  /**
   * constructor
   *
   * @param string $class    the classname of the extending subclass (required)
   * @param string $label    a unique string label for the set of settings for
   *                         the plugin
   * @param array  $sections an array of name/title pairs defining the settings
   *                         sections (optional)
   */
  public function __construct( $class = false, $label = false, $sections = false )
  {
    if ( false === $class )
    {
      die( __CLASS__ . ' class must be instantiated by a plugin-specific subclass' );
    }

    $this->plugin_class = $class;

    if ( $label !== false ) {
      $this->WP_setting = $label;
    }

    $this->settings_page = $this->WP_setting . '_settings_page';

    $this->option_version_location = Participants_Db::$prefix . 'option_version';

    // set up the HTML for the built-in display functions
    // these are generic settings to be modified by the subclass
    $this->help_text_wrap = '<span class="helptext">%s</span>';
    $this->submit_wrap = '<p class="%s">%s</p>';
    $this->submit_class = 'button-primary';
    $this->submit_button = 'Save Settings';

    // define a default settings section so that setting up sections is optional
    if ( $sections !== false ) {
      $this->sections = empty( $sections ) ? array('main' => 'General Settings') : (array) $sections;
    }

    // register the plugin setting with WP
    // this will store an array of all the individual settings for the plugin
    register_setting( $this->WP_setting, $this->WP_setting, $this->register_setting_args() );
    
    add_filter('pre_update_option_' . $this->WP_setting, [$this,'check_option_update'], 10, 2);
    
    if ( defined( 'PDB_DEBUG' ) && PDB_DEBUG )
    {
      add_action('update_option_' . $this->WP_setting, [$this,'log_option_update'], 10, 2);
    }
  }
  
  /**
   * supplies the setting registration argument array
   * 
   * @return array
   */
  protected function register_setting_args()
  {
    return [
        'sanitize_callback' => [$this, 'validate'],
    ];
  }

  /**
   * registers the individual plugin options
   * 
   * fired on the admin_menu hook
   */
  public function initialize()
  {
    // define the individual settings
    $this->_define_settings();

    // register the individual settings
    if ( function_exists( 'add_settings_field' ) ) {
      $this->_register_options();
      // register the sections
      $this->_register_sections();
    }
  }

  /*   * ***********************
   * PUBLIC METHODS
   */

  /**
   * updates or adds an option value
   *
   * @param string  $option_name name of the option to update
   * @param string  $value value to use
   * @param bool    $overwrite if true, overwrite the value, false to write vlaue only if not present
   * @return bool true if the option was updated
   */
  public function update_option( $option_name, $value, $overwrite = true )
  {
    if ( !isset( $option_name ) )
    {
      return false;
    }

    $options = get_option( $this->WP_setting );

    if ( false === $overwrite && isset( $options[$option_name] ) )
    {
      return true;
    } 
    else 
    {
      $options[$option_name] = $value;
    }
    

    return update_option( $this->WP_setting, $options );
  }

  /**
   * retrieves an option value
   *
   * yes, there is a WP function of the same name, but this is the beauty of
   * using classes: the class name tacked onto the front will nicely distinguish
   * the function from its WP counterpart
   *
   * @param string $option_name
   * @return the value of the option or false
   */
  public function get_option( $option_name )
  {
    $options = get_option( $this->WP_setting );

    return isset( $options[$option_name] ) ? $options[$option_name] : false;
  }

  /**
   * provides the current options version number
   */
  public function option_version()
  {
    return get_option( $this->option_version_location, '0.0' );
  }

  /**
   * 
   */
  protected function increment_option_version()
  {
    $version = get_option( $this->option_version_location, '0.0' );
    
    $new_version = floatval( $version ) + 0.1;

    update_option( $this->option_version_location, $new_version );
  }

  /**
   * defines the individual settings for the plugin
   *
   * @return null
   */
  protected function _define_settings()
  {
    
  }

  /**
   * registers the options
   *
   * this function is called by the plugin subclass to set up the options
   *
   * @param array $settings an array of all the individual settings for the plugin
   *                name unique string identifier for the setting
   *                title display title for the setting
   *                group the settings group the setting is assigned to
   *                options an array of extended options for the setting
   *
   * @return null
   */
  private function _register_options()
  {

    foreach ( $this->plugin_settings as $setting_params ) {

      $this->_register_option(
              $setting_params['name'], $setting_params['title'], $setting_params['group'], $setting_params['options']
      );
    }
  }

  /**
   * displays a settings page form using the WP Settings API
   *
   * this just displays the core (form element) of the page; the complete 
   * page display should be defined by the plugin subclass. 
   *
   * @return null
   */
  protected function show_settings_form()
  {

    settings_errors();
    ?>
    <form action="options.php" method="post">
    <?php
    settings_fields( $this->WP_setting );

    do_settings_sections( $this->settings_page );

    $args = array(
        'type' => 'submit',
        'class' => $this->submit_class,
        'value' => $this->submit_button,
        'name' => 'submit_button',
    );

    printf( $this->submit_wrap, 'submit', PDb_FormElement::get_element( $args ) );
    ?>
    </form>
      <?php
    }

    /**
     * customizes the settings display HTML
     *
     * @param array $display_settings
     *                help_text_wrap
     *                submit_wrap
     *                submit_class
     *                submit_button
     */
    public function define_settings_display( $display_settings )
    {

      foreach ( array('help_text_wrap', 'submit_wrap', 'submit_class', 'submit_button') as $setting ) {

        if ( isset( $display_settings[$setting] ) )
          $this->$setting = $display_settings[$setting];
      }
    }

    /**
     * gets a setting default value
     * 
     * @param string $name name of the setting default to get
     * @return unknown the default value
     */
    public function get_default_value( $name )
    {
      foreach ( $this->plugin_settings as $setting ) {
        if ( $setting['name'] == $name and isset( $setting['options']['value'] ) ) {
          return $setting['options']['value'];
        }
      }
      return null;
    }

    /**
     * gets an option title
     * 
     * @param string $name name of the setting
     * @return string|null the title if defined
     */
    public function get_option_title( $name )
    {
      foreach ( $this->plugin_settings as $setting ) {
        if ( $setting['name'] == $name ) {
          return $setting['title'];
        }
      }
      return null;
    }

    /**
     * gets all options names
     * 
     * @return array of all defined option names
     */
    public function get_option_names()
    {

      $names = array();
      foreach ( $this->plugin_settings as $setting ) {
        $names[] = $setting['name'];
      }
      return $names;
    }

    /*     * ******************
     * METHODS USED BY SETTINGS API
     */

    /**
     * validates settings fields
     *
     * the plugin subclass will supply any validation if needed
     */
    public function validate( $input )
    {
      $this->increment_option_version();

      return $input;
    }

    /**
     * prints a settings form element
     *
     * @access public because it's called by WP
     *
     * @param array $input
     *    name - setting slug (required)
     *    type - the type of form element to use
     *    value - the current value of the field
     *    help_text - extra text for the setting page
     *    options - if an array type setting, the values of the settings, array
     *    attributes - any additional attributes to add
     *    class - a CSS class name to add
     */
    public function print_settings_field( $input )
    {
      if ( !isset( $input['name'] ) )
        return NULL;

      if ( $input['type'] !== 'header' ) {

        $options = $this->options_array();

        $args = wp_parse_args( $input, array(
            'options' => false,
            'attributes' => array(),
            'value' => ''
                ) );

        // supply the value of the field from the saved option or the default as defined in the settings init
        /**
         * provides a way to condition a setting value before it is displayed
         * 
         * @filter pdb-settings_page_setting_value
         * @param mixed the setting value
         * @param array $input the setting input parameters
         * @return mixed the value to display in the setting
         * 
         */
        $args['value'] = Participants_Db::apply_filters('settings_page_setting_value', isset( $options[$input['name']] ) ? $options[$input['name']] : $args['value'], $input );

        $args['name'] = $this->WP_setting . '[' . $input['name'] . ']';

        PDb_FormElement::print_element( $args );

        if ( !empty( $args['help_text'] ) ) {

          printf( $this->help_text_wrap, trim( $args['help_text'] ) );
        }
      }
    }
    
    /**
     * provides the plugin options array
     * 
     * this is cached
     * 
     * @return array
     */
    protected function options_array()
    {
      $cachekey = 'pdb-options_array';
      
      $options_array = wp_cache_get($cachekey);
      
      if ( ! is_array( $options_array ) )
      {
        $options_array = get_option( $this->WP_setting );
        
        wp_cache_set($cachekey, $options_array);
      }
      
      return $options_array;
    }

    /**
     * displays a section subheader
     *
     * note: the header is displayed by WP; this is only what would go under that
     */
    public function options_section( $section )
    {
      
    }

    /*     * *********
     * PRIVATE CALLS TO WP SETTINGS API
     */

    /**
     * registers settings sections with the WP Settings API
     *
     */
    private function _register_sections()
    {

      foreach ( $this->sections as $name => $title ) {

        add_settings_section(
                $this->WP_setting . '_' . $name, $title, array($this, 'options_section'), $this->settings_page
        );
      }
    }

    /**
     * registers an option setting with the WP Settings API
     *
     * @param string $name     name of the setting (unique string ID)
     * @param string $title    display title for the setting
     * @param string $group    group for the setting
     * @param array  $options  the various options for the setting
     *                  type the form element type to use
     *                  help_text any explanatory text to include
     *                  value a default value for the setting
     */
    private function _register_option( $name, $title, $group, $options )
    {
      if ( !isset( $options['type'] ) )
      {
        $options['type'] = 'text';
      }
      
      $options['name'] = $name;
      $options['title'] = $title;

      add_settings_field(
              $name, $title, [$this, 'print_settings_field'], $this->settings_page, $this->WP_setting . '_' . $group, $options
      );

      // drop in the default value (if any)
      if ( isset( $options['value'] ) ) 
      {
        $this->update_option( $name, $options['value'], false );
      }
    }
    
    
    /**
     * checks the update to the plugin settings
     * 
     * this is to provide notice that there were no changes in the settings save
     * 
     * @param array $new_options
     * @param array $previous_options
     * @return array
     */
    public function check_option_update( $new_options, $previous_options )
    {
      if ( ! is_array( $previous_options ) )
      {
        // this is a fresh install
        return $new_options;
      }
      
      $changes = array_diff_assoc($new_options, $previous_options);
      
      if ( empty( $changes ) )
      {
        add_settings_error( $this->WP_setting, 'settings_updated', __( 'No settings changes were saved.', 'participants-database' ), 'info' );
      }
      
      return $new_options;
    }
    
    /**
     * logs an update to the plugin settings
     * 
     * this is only called when plugin debugging is enabled
     * 
     * @global \wpdb $wpdb
     * @param array $previous_options
     * @param array $current_options
     */
    public function log_option_update( $previous_options, $current_options )
    {
      global $wpdb;
      
      if ( ! empty( $wpdb->last_error ) )
      {
        Participants_Db::debug_log( __METHOD__.' db error: '. $wpdb->last_error );
      }
      
      $changes = array_diff_assoc($current_options, $previous_options);
      
      if ( empty( $changes ) )
      {
        Participants_Db::debug_log( __METHOD__.'settings update: no changes saved' );
      }
      else
      {
        $pattern = 'option: "%s" old value: "%s"  new value: "%s"';
        $log_messages = [];

        foreach ( $changes as $option => $new_value ) 
        {
          $previous_option_value = isset( $previous_options[$option] ) ? $previous_options[$option] : '';
          $log_messages[$option] = [$previous_option_value, $new_value];
        }
        
        // set up the settings array
        $this->_define_settings();
        
        foreach( $log_messages as $option => $values )
        {
          $message = 'PDB setting update: ' . sprintf( $pattern, $this->get_option_title( $option ), $values[0], $values[1] );
          Participants_Db::debug_log( $message );
        }
      }
    }

  }
  