<?php

/*
 * class for handling the display of images
 *
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdeign@xnau.com>
 * @copyright  2015 xnau webdesign
 * @license    GPL2
 * @version    0.8
 * @link       http://xnau.com/wordpress-plugins/
 *
 * functionality provided here:
 *   determine if file exists
 *   get image dimensions
 *   define a default image
 *   define an HTML wrapper and class
 *   provide a default image if image doesn't exist
 *   print image HTML with wrapper
 *
 * future development:
 *   cropping
 *   resizing
 *   watermarking
 *   
 */
if ( !defined( 'ABSPATH' ) )
  die;

abstract class xnau_Image_Handler {

  /**
   * @var string name of the cache group
   */
  const group = 'xnau_image_handler';

  /**
   * true if the image file has been located and verified
   * 
   * @var bool
   */
  var $file_exists = false;

  /**
   * the the image defined and not a default image?
   *
   * @var bool true if the image file is defined and is not a default image
   */
  var $image_defined = false;

  /**
   * holds the image filename
   * 
   * @var string
   */
  var $image_file = '';

  /**
   * holds the URI to the image
   * @var string
   */
  var $image_uri = '';

  /**
   * holds the pixel width of the image
   * @var int
   */
  var $width = 0;

  /**
   * holds the pixel height of the image
   * @var int
   */
  var $height = 0;

  /**
   * the CSS classname for the image
   * @var string
   */
  var $classname;

  /**
   * the image wrap HTML
   * @var array first element is open tag, second is close tag
   */
  var $image_wrap;

  /**
   *
   * @var string holds the path to the default image
   */
  var $default_image = false;

  /**
   * @var string the path to the image directory
   */
  var $image_directory;

  /**
   * @var string the URL for the image directory
   */
  var $image_directory_uri;

  /**
   * @var string class name for an undefined image
   */
  var $emptyclass = 'no-image';

  /**
   * @var string class name for a default image
   */
  var $defaultclass = 'default-image';

  /**
   * determines the display mode for the returned HTML:
   *    image - shows the image (default)
   *    filename - shows the filename
   *    both - shows the image and the filename
   * 
   * @var string the current display mode
   */
  var $display_mode;

  /**
   * the href value for the link
   * 
   * if bool false, no link is shown, if empty, the file URL is used, otherwise, 
   * the string value is used
   * @var mixed
   */
  var $link;

  /**
   * the calling module
   * 
   * @var string
   */
  var $module;

  /**
   * @var array of attributes to apply
   * 
   * these are applied to the image wrapper
   */
  var $attributes = array();

  /**
   * @var array of attributes to apply
   * 
   * these are appplied to the image tag
   */
  var $image_attributes = array();

  /**
   * intializes the object with a setup array
   *
   * @param array $configuration
   *                     'filename' => an image path, filename or URL
   *                     'classname' => a classname for the image
   *                     'wrap_tags' => array of open and close HTML
   *                     'link' => URI for a wrapping anchor tag
   *                     'mode' => display mode: as an image or a filename or both
   *                     'module' => calling module
   *                     'attributes' => array of html attributes to add
   */
  function __construct( $configuration )
  {
    $config = shortcode_atts( array(
        'filename' => '',
        'link' => '',
        'classname' => 'image-field-wrap',
        'attributes' => array(),
        'relstring' => 'lightbox',
        'module' => '',
        'mode' => '',
            ), $configuration );


    $this->set_image_directory();

    $this->set_default_image();

    $this->image_file = $config['filename'];
    $this->link = $config['link'];
    $this->classname = $config['classname'];
    $this->set_attributes( $config['attributes'] );
    $this->attributes['rel'] = $config['relstring'];
    $this->module = $config['module'];

    $this->_file_setup();

    $this->set_image_wrap( isset( $config['wrap_tags'] ) && is_array( $config['wrap_tags'] ) ? $config['wrap_tags'] : '' );

    $this->set_display_mode( $config['mode'] );
  }

  /**
   * prints the HTML
   *
   */
  public function print_image()
  {
    $this->set_image_wrap();

    echo $this->get_image_html();
  }

  /**
   * supplies the HTML for the image
   * 
   * @return string HTML
   */
  public function get_image_html()
  {

    switch ( $this->display_mode ) {
      case 'both':
        $pattern = '%1$s<img src="%2$s" /><span class="image-filename">%4$s</span>%3$s';
        break;
      case 'filename':
        $pattern = '%1$s%4$s%3$s';
        break;
      case 'none':
        $pattern = '';
        break;
      case 'image':
      default:
        $pattern = '%1$s<img src="%2$s" />%3$s';
    }

    return sprintf( $pattern,
            sprintf( $this->image_wrap[0],
                    $this->wrap_class(),
                    $this->link,
                    basename( $this->image_uri ),
                    $this->attribute_string( $this->attributes )
            ),
            $this->image_uri,
            $this->image_wrap[1],
            $this->image_file
    );
  }

  /**
   * supplies the name of the image file
   *
   * @return string the image filename
   */
  public function get_image_file()
  {
    return $this->image_file;
  }

  /**
   * sets the default image path
   *
   */
  abstract function set_image_directory();

  /**
   * sets the default image source path
   *
   * @param string $image absolute path to the default image file
   */
  abstract function set_default_image( $image = false );

  /**
   * sets the display mode
   * 
   * @param string  $mode the default mode to use
   * @return null
   */
  protected function set_display_mode( $mode = '' )
  {
    if ( $this->file_exists ) {
      if ( !empty( $mode ) ) {
        $this->display_mode = $mode;
      } elseif ( is_admin() ) {
        $this->display_mode = 'filename';
      } else {
        $this->display_mode = 'image';
      }
    } elseif ( !empty( $this->default_image ) && !is_admin() ) {
      $this->display_mode = 'image';
    } else {
      $this->display_mode = 'none';
    }
  }

  /**
   * sets up the attributes property
   * 
   * @param array $attributes from the config array
   */
  protected function set_attributes( $attributes )
  {
    if ( is_array( $attributes ) ) :
      foreach ( $attributes as $key => $value ) {
        if ( in_array( $key, array('height', 'width') ) ) {
          $this->image_attributes[$key] = $value;
        } else {
          $this->attributes[$key] = $value;
        }
      }
    endif;
  }

  /**
   * provides an HTML element attribute string
   * 
   * @param array $atts an associative array of attributes
   * @return string
   */
  protected function attribute_string( $atts )
  {
    $attstring = '';
    foreach ( $atts as $name => $value ) {
      if ( is_string( $name ) && strlen( $name ) > 0 ) {
        $attstring .= sprintf( ' %s="%s" ', $name, $value );
      }
    }
    return $attstring;
  }

  /**
   * provides a wrap cvlass name
   * 
   * @param string $class a classname string to add
   * @return string
   */
  protected function wrap_class( $class = '' )
  {
    return $this->classname . ' ' . $class . ' ' . 'display-mode-' . $this->display_mode;
  }

  /**
   * process the filename to test it's validity, set it's path and find its properties
   *
   * sets the path to the file, sets dimensions, sets file_exists flag, sets the HTML 
   * class to indicate the type of filename supplied
   * 
   * @param unknown $filename if set, string image file name, possibly including a path
   */
  protected function _file_setup()
  {

    $status = 'untested';

    switch ( true ) {

      case (empty( $this->image_file )):

        if ( !$this->in_admin() )
          $status = $this->_showing_default_image();
        else
          $status = 'admin';
        break;

      case ($this->test_absolute_path_image( $this->image_file )) :
        
        $status = 'absolute';
        $this->image_uri = esc_url( $this->image_file );
        //$this->image_file = basename($this->image_file);
        $this->file_exists = true;
        $this->image_defined = true;
        $this->set_image_dimensions( $this->image_uri );
        break;

      default:

        /*
         * set the image file path with the full system path to the image
         * directory as defined in the plugin settings
         */
        $status = 'basename';
        $this->set_up_file_props();

        // if we still have no valid image, drop in the default
        if ( !$this->file_exists ) {
          if ( !$this->in_admin() )
            $status = $this->_showing_default_image( $this->image_file );
          else
            $status = 'file-notfound';
        } else {
          $this->image_defined = true;
          $this->_set_dimensions();
        }
    }

    $this->classname .= ' ' . $status;
  }

  /**
   * sets up the image display if no image file is found
   *
   * @param string $filename the name of the file which wasn't found for the purpose
   *                         of showing what the db contains
   * @return string status
   */
  protected function _showing_default_image( $filename = false )
  {
    if ( !empty( $this->default_image ) ) {

      if ( $filename ) {
        $this->image_file = basename( $filename );
      }
      $this->image_uri = $this->default_image;
      $this->_set_dimensions();
      $status = $this->defaultclass;
      $this->file_exists = true;
    } else {

      $this->image_uri = '';
      $this->display_mode = 'none';
      //$this->image_file = '';
      $status = $this->emptyclass;
    }

    return $status;
  }

  /**
   * tests a file and sets properties if extant
   *
   * @param string $filename name of the file, could be relative path
   *
   * sets the file_exists flag to true if the file exists
   */
  protected function set_up_file_props( $filename = '' )
  {
    $filename = empty( $filename ) ? $this->image_file : $filename;

    $filepath = $this->concatenate_directory_path( $this->image_directory, $filename, false );

    if ( $this->_file_exists( $filepath ) ) 
    {
      $this->image_uri = $this->image_directory_uri . $this->image_file;
      $this->file_exists = true;
    }
  }

  /**
   * does an image file exist?
   *
   * this is needed because on some systems file_exists() gives a false negative
   *
   * @param string $filepath a full system filepath to an image file or just a file name
   * @return bool true if the file exists
   *
   */
  protected function _file_exists( $filepath )
  {
    if ( empty( $filepath ) )
    {
      return false;
    }

    // first use the standard function
    if ( is_file( $filepath ) ) 
    {
      return true;
    }
    
    return false;
  }

  /**
   * uses file_get_contents to test if a file exists
   *
   * This must be used as a last resort, it can take a long time to get the
   * server's response in some cases
   *
   * @param string $url the absolute url of the file to test
   * @return bool
   */
  function url_exists( $url )
  {
    $code = $this->get_http_response_code( $url );

    return $code == 200;
  }

  /**
   * gets an HTTP response header
   * 
   * @param string $url the URI to test
   * @return int the final http response code
   */
  function get_http_response_code( $url )
  {
    $options['http'] = array(
        'method' => "HEAD",
        'ignore_errors' => 1,
    );

    $context = stream_context_create( $options );
    $body = file_get_contents( $url, NULL, $context );
    $responses = $this->parse_http_response_header( $http_response_header );

    $last = array_pop( $responses );

    return $last['status']['code']; // last status code
  }

  /**
   * parse_http_response_header
   *
   * @param array $headers as in $http_response_header
   * @return array status and headers grouped by response, last first
   */
  function parse_http_response_header( array $headers )
  {
    $responses = array();
    $buffer = NULL;
    foreach ( $headers as $header ) {
      if ( 'HTTP/' === substr( $header, 0, 5 ) ) {
        // add buffer on top of all responses
        if ( $buffer )
          array_unshift( $responses, $buffer );
        $buffer = array();

        list($version, $code, $phrase) = explode( ' ', $header, 3 ) + array('', FALSE, '');

        $buffer['status'] = array(
            'line' => $header,
            'version' => $version,
            'code' => (int) $code,
            'phrase' => $phrase
        );
        $fields = &$buffer['fields'];
        $fields = array();
        continue;
      }
      list($name, $value) = explode( ': ', $header, 2 ) + array('', '');
      // header-names are case insensitive
      $name = strtoupper( $name );
      // values of multiple fields with the same name are normalized into
      // a comma separated list (HTTP/1.0+1.1)
      if ( isset( $fields[$name] ) ) {
        $value = $fields[$name] . ',' . $value;
      }
      $fields[$name] = $value;
    }
    unset( $fields ); // remove reference
    array_unshift( $responses, $buffer );

    return $responses;
  }

  /**
   * tests an image at an absolute address
   * 
   * @param string $src absolute path to an image file to test
   * 
   * sets $file_exists to true if found
   */
  public function test_absolute_path_image( $src )
  {
    /*
     * we used to test the absolute path with getimagesize, but that failed too 
     * often and took too much time, now we just check for a semantically-correct 
     * absolute URL
     */
    if ( $this->test_url_validity( $src ) /* and false !== self::getimagesize($src) */ ) {
      return $this->file_exists = true;
    }
    return false;
  }

  /**
   * test an absolute path for validity; looks for http, https, or no protocol, must end in a file name
   *
   * @param string $url the path to test
   * @return bool
   */
  public function test_url_validity( $url )
  {
    return 0 !== preg_match( "#^(https?:|)//.+$#", $url ); //  previously: "#^(https?:|)//.+/.+\..{2,4}$#"
  }
  
  /**
   * provides the path to the image file
   * 
   * @return string path
   */
  public function image_path()
  {
    if ( $this->image_defined ) {
      return trailingslashit( $this->image_directory ) . $this->image_file;
    } else {
      return  WP_CONTENT_DIR . str_replace( 'wp-content', '', ltrim( Participants_Db::plugin_setting( 'default_image', '' ), '/' ) );
    }
  }

  /**
   * sets the dimension properties
   *
   */
  protected function _set_dimensions()
  {
    $getimagesize = self::getimagesize( $this->image_path() );

    if ( false !== $getimagesize ) {

      $this->width = $getimagesize[0];
      $this->height = $getimagesize[1];
    }
  }

  /**
   * sets the dimension properties
   *
   * @param string $src the absolute image source path
   */
  protected function set_image_dimensions( $src )
  {
    $getimagesize = self::getimagesize( $src );

    if ( false !== $getimagesize ) {

      $this->width = $getimagesize[0];
      $this->height = $getimagesize[1];
    }
  }

  /**
   * performs a php getimagesize using a cache
   * 
   * @param string $uri the image uri
   * @return array
   */
  public static function getimagesize( $uri )
  {
    $found = false;
    $result = wp_cache_get( $uri, self::group, false, $found );
    if ( $found === false ) {
      try {
        $result = @getimagesize( $uri );
      } catch (Exception $e) {
        Participants_Db::debug_log( __METHOD__ . ' ' . $e->getMessage() );
      }
      
      wp_cache_set( $uri, $result, self::group, Participants_Db::cache_expire() );
    }
    return $result;
  }

  /**
   * tells if the provided file is an image file
   * 
   * @param string $file path or URI to the file
   * @return bool true if the file type checks out
   */
  public static function is_image_file( $file )
  {
    $valid_image = false;
    
    if ( ! is_file($file) ) {
      return $valid_image;
    }
      
    if ( function_exists( 'mime_content_type' ) ) {
      
      $valid_image = preg_match( '/(gif|jpeg|png|webp)/', mime_content_type( $file ) ) === 1;
      
    } else {
      
      Participants_Db::debug_log( 'missing php fileinfo extension' );
      
      $fileinfo = PDb_Image::getimagesize( $file );
      
      $valid_image = in_array( $fileinfo[2], array(IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WBMP, IMAGETYPE_WEBP) );
    }
    
    return $valid_image;
  }

  /**
   * sets the image wrap HTML
   *
   * @param array $wrap_tags  the HTML to place before and after the image tag; 
   * %s is replaced with the classname
   */
  public function set_image_wrap( $wrap_tags = array() )
  {
    if ( !empty( $wrap_tags ) ) {
      $this->image_wrap = array(
          $wrap_tags[0],
          $wrap_tags[1],
      );
    } else {
      $this->_set_image_wrap();
    }
  }

  /**
   * sets up the default wrap tags
   * 
   * @return null
   */
  protected function _set_image_wrap()
  {
    $this->image_wrap = array(
        '<span class="%1$s" title="%3$s" %4$s >',
        '</span>'
    );
  }

  /**
   * adds a final slash to a directory name if there is none
   * 
   * @param string $path the path to test for an end slash
   * @return string the $path with a slash at the end
   */
  public function end_slash( $path )
  {

    return rtrim( $path, '/' ) . '/';
  }

  /**
   * makes sure there is one and only one slash between directory names in a
   * concatenated path, and it ends in a slash
   * 
   * @param string $path1    first part of the path
   * @param string $path2    second part of the path
   * @param bool   $endslash determines whether to end the path with a slash or not
   */
  public static function concatenate_directory_path( $path1, $path2, $endslash = true )
  {
    return rtrim( $path1, '/' ) . '/' . ltrim( rtrim( $path2, '/' ), '/' ) . ( $endslash ? '/' : '' );
  }

  /**
   * indicates whether the user is in the admin section, taking into account that
   * AJAX requests look like they are in the admin, but they're not
   *
   * @return bool
   */
  public function in_admin()
  {
    return Participants_Db::is_admin();
  }

}
