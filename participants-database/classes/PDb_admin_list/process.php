<?php

/**
 * handles multiple-record processes on the admin list page
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2020  xnau webdesign
 * @license    GPL3
 * @version    0.2
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */
namespace PDb_admin_list;

use \PDb_List_Admin;
use \Participants_Db;

defined( 'ABSPATH' ) || exit;

class process {
  
  /**
   * @var string name of the nonce
   */
  const nonce = 'pdb-admin-list-action';

  /**
   * stets up the input processing
   */
  public function __construct()
  {
    $this->_process_general();
  }

  /** 	
   * processes all the general list actions
   * 
   */
  private function _process_general()
  {
    if ( filter_input( INPUT_POST, 'action', FILTER_DEFAULT, \Participants_Db::string_sanitize() ) === 'list_action' && check_admin_referer( self::nonce ) === 1 ) {

      switch ( filter_input( INPUT_POST, 'submit-button', FILTER_DEFAULT, \Participants_Db::string_sanitize() ) ) {

        // handles a "with selected" action
        case PDb_List_Admin::$i18n['apply']:
        case 'apply':

          $this->handle_with_selected();
          break;

        // handles changing the number of items to show in the list
        case PDb_List_Admin::$i18n['change']:
        case 'change':

          $list_limit = filter_input( INPUT_POST, 'list_limit', FILTER_VALIDATE_INT );
          if ( $list_limit > 0 ) {
            PDb_List_Admin::set_admin_user_setting( 'list_limit', $list_limit );
          }
          $_GET[PDb_List_Admin::$list_page] = 1;
          break;

        // handles a general process submission
        default:
          /**
           * action: pdb-process_admin_list_submission
           * 
           * @version 1.6
           */
          do_action( Participants_Db::$prefix . 'process_admin_list_submission' );
      }
    }
  }

  /**
   * handles a "with selected" submission
   * 
   */
  private function handle_with_selected()
  {
    $selected_action = filter_input( INPUT_POST, 'with_selected', FILTER_DEFAULT, \Participants_Db::string_sanitize() );
    /**
     * @version 1.7.1
     * @filter  pdb-before_list_admin_with_selected_action
     * @param array $selected_ids list of ids to apply the list action to
     * @param string action called
     * @return array
     */
    $submitted_ids = Participants_Db::apply_filters( 'before_list_admin_with_selected_action', filter_input_array( INPUT_POST, array(
                'pid' => array(
                    'filter' => FILTER_VALIDATE_INT,
                    'flags' => FILTER_REQUIRE_ARRAY,
                )
                    ) ), $selected_action );

    PDb_List_Admin::set_admin_user_setting( 'with_selected', $selected_action );

    $with_selected = new with_selected( $submitted_ids['pid'] );

    $with_selected->execute( $selected_action );
    
    do_action( 'pdb-admin_list_with_selected_complete', $selected_action );

    if ( PDB_DEBUG ) {
      Participants_Db::debug_log( __METHOD__ . ' 
action: ' . $selected_action . ( $with_selected->last_query() !== '' ? '   
query: ' . $with_selected->last_query() : '' ) );
    }
  }

}
