<?php

defined( 'ABSPATH' ) or die( 'Nope, not accessing this' );

//ini_set('display_startup_errors', 1);
//ini_set('display_errors', 1);
//error_reporting(-1);
error_reporting(0);

require_once( ABSPATH . 'wp-admin/includes/admin.php' );

if ( ! class_exists( 'School_Absence_Admin_Children' ) ):

define("PAGE_MANAGE_CHILDREN", "absence-children");
define("PAGE_CALENDAR", "absence-calendar");

/**
 *
 * @author hmlnarik
 */
class School_Absence_Admin_Children {
    
    function __construct() {
        add_action('admin_post_absence_child_form_response', array($this, 'admin_page_children_edit_form_response'));
        add_action('admin_post_absence_bulk_children_delete', array($this, 'admin_page_children_bulk_children_delete'));
        add_action('admin_post_absence_bulk_children_update_cat', array($this, 'admin_page_children_bulk_children_category'));
        add_action('wp_ajax_absence_ajax_update_child_absences', array($this, 'absence_ajax_update_child_absences'));
        add_action('wp_ajax_absence_ajax_get_child_absences', array($this, 'absence_ajax_get_child_absences'));
        add_action('bulk_actions-' . PAGE_MANAGE_CHILDREN, array($this, 'admin_page_children_register_bulk_actions'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts_and_styles'));
    }

    //enqueus scripts and stles on the back end
    public function enqueue_admin_scripts_and_styles(){
        wp_enqueue_script( 'children', plugins_url('../js/children.js', __FILE__));
        wp_enqueue_script( 'datepicker.plugin.js', plugins_url('../js/jquery.plugin.min.js', __FILE__));
        wp_enqueue_script( 'datepicker.js', plugins_url('../js/jquery.datepick.js', __FILE__));
        wp_enqueue_script( 'datepicker.local', plugins_url('../js/jquery.datepick-cs.js', __FILE__));
        
        wp_enqueue_style( 'datepicker.ui', plugins_url('../css/redmond.datepick.css', __FILE__));
    }
    
    function admin_page_children_register_bulk_actions($actions) {
        if ( current_user_can( ABSENCE_CAP_UPDATE_ALL_CHILDREN ) ) {
            $actions['bulk-delete'] = __( 'Delete' );

            $actions['bulk-add_to_cats'] = __( 'Přidat do tříd / rubrik' );
            $actions['bulk-set_cats'] = __( 'Nastavit třídy / rubriky' );
            $actions['bulk-remove_from_cats'] = __( 'Odebrat z tříd / rubrik' );
        }

        return $actions;
    }
    
    function admin_page_children() {
        if ( ! current_user_can( ABSENCE_CAP_UPDATE_ALL_CHILDREN ) ) {
            wp_send_json_error('Unauthorized.', 403);
        }

        global $action, $action2, $child_id, $user_id;
        wp_reset_vars( array( 'action', 'action2', 'child_id', 'user_id', 'wp_http_referer' ) );
        
        if (! isset( $_REQUEST['action'] ) || -1 == $_REQUEST['action']) {
            $action = $action2;
        }
        
        switch ($action) {
        case 'create':
            $this->admin_page_children_edit();
            break;

        case 'edit':
            $this->admin_page_children_edit($child_id);
            break;

        case 'delete':
            $this->admin_page_children_delete($child_id);
            break;

        case 'bulk-delete':
            $this->admin_page_children_delete($child_id, 'bulk-children');
            break;
        
        case 'bulk-add_to_cats':
            $this->admin_page_children_bulk_cats($child_id, BULK_CHILD_CATS_ADD);
            break;

        case 'bulk-set_cats':
            $this->admin_page_children_bulk_cats($child_id, BULK_CHILD_CATS_SET);
            break;

        case 'bulk-remove_from_cats':
            $this->admin_page_children_bulk_cats($child_id, BULK_CHILD_CATS_REMOVE);
            break;

        default:
            $this->admin_page_children_list($user_id);
        }
    }
    
    function admin_page_children_bulk_children_delete() {
        if ( ! current_user_can( ABSENCE_CAP_UPDATE_ALL_CHILDREN ) ) {
            wp_send_json_error('Unauthorized.', 403);
        }

        global $child_id;
        wp_reset_vars( array('child_id') );

        $delete = new School_Absence_Admin_Child_Delete($child_id);
        $delete->perform();
    }
    
    function absence_ajax_update_child_absences() {
        $detail = new School_Absence_Admin_Child_Detail();
        $detail->absence_ajax_update_child_absences();
    }
    
    function absence_ajax_get_child_absences() {
        $detail = new School_Absence_Admin_Child_Detail();
        $detail->absence_ajax_get_child_absences();
    }
    
    function admin_page_children_edit($child_id = null) {
        $detail = new School_Absence_Admin_Child_Detail($child_id);
        $detail->display();
    }
    
    function admin_page_children_delete($child_ids, $nonce_action = 'childdelete') {
        $delete = new School_Absence_Admin_Child_Delete($child_ids, $nonce_action);
        $delete->display();
    }
    
    function admin_page_children_bulk_cats($child_ids, $action) {
        $update_cat = new School_Absence_Admin_Child_Update_Cat($child_ids, $action, 'bulk-children');
        $update_cat->display();
    }
    
    function admin_page_children_bulk_children_category() {
        if ( ! current_user_can( ABSENCE_CAP_UPDATE_ALL_CHILDREN ) ) {
            wp_send_json_error('Unauthorized.', 403);
        }

        global $child_id, $update;
        wp_reset_vars( array('child_id', 'update') );

        $update_cat = new School_Absence_Admin_Child_Update_Cat($child_id, $update);
        $update_cat->perform();
    }
    
    function admin_page_children_edit_form_response() {
        if ( ! current_user_can( ABSENCE_CAP_UPDATE_ALL_CHILDREN ) ) {
            wp_send_json_error('Unauthorized.', 403);
        }

	if( ! isset( $_POST['absence_child_update_user_meta_nonce'] ) || ! wp_verify_nonce( $_POST['absence_child_update_user_meta_nonce'], 'absence_child_form_response') ) {
            wp_die(__('Invalid nonce specified', 'absence'), __('Error', 'absence'), array(
                'response' => 403,
                'back_link' => 'admin.php?page=' . PAGE_MANAGE_CHILDREN,
            ));
        }

        global $wpdb;
        global $child_id, $user_id, $name, $note, $post_category, $_wp_original_http_referer;
        wp_reset_vars( array( 'child_id', 'user_id', 'name', 'note', 'post_category', '_wp_original_http_referer' ) );

        $users_table_name = $wpdb->prefix . "users";
        $class_table_name = $wpdb->prefix . "terms";

        $wpdb->show_errors();

        if (is_numeric($child_id)) {
            $wpdb->update( ABSENCE_TABLE_CHILD, array(
                "fk_$users_table_name" => $user_id,
                'name' => $name,
                'note' => $note
            ), array(
                'id' => $child_id
            ), array(
                '%d', '%s', '%s'
            ), array('%d') );
        } else {
            $wpdb->insert( ABSENCE_TABLE_CHILD, array(
                "fk_$users_table_name" => $user_id,
                'name' => $name,
                'note' => $note
            ), array('%d', '%s', '%s') );
            $child_id = $wpdb->insert_id;
        }

        $wpdb->delete(ABSENCE_TABLE_CHILD_CLASS, array(
            "fk_" . ABSENCE_TABLE_CHILD => $child_id
        ));
        foreach ($post_category as $cat) {
            if (empty($cat)) {
                continue;
            }
            
            $wpdb->insert( ABSENCE_TABLE_CHILD_CLASS, array(
                "fk_" . ABSENCE_TABLE_CHILD => $child_id,
                "fk_$class_table_name" => $cat
            ));
        }

        wp_redirect($_wp_original_http_referer);
        exit();
    }
    
    function admin_page_children_list($user_id) {
        
        global $title;

        $wp_list_table = new School_Absence_Admin_Children_List($user_id);
        $message = '';
        $wp_list_table->prepare_items();

        $add_query_args = array(
                'action' => 'create',
                'page' => PAGE_MANAGE_CHILDREN,
                '_wp_original_http_referer' => wp_unslash( $_SERVER['REQUEST_URI'] ),
        );
        $add_url = esc_url(add_query_arg( $add_query_args, 'admin.php' ));
        ?>
<div class="wrap nosubsub">
<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?>
<a href="<?php echo $add_url; ?>" class="page-title-action"><?php echo esc_html_x( 'Přidat dítě', 'absence' ); ?></a>
</h1>

<hr class="wp-header-end">

<?php if ( $message ) : ?>
<div id="message" class="<?php echo $class; ?> notice is-dismissible"><p><?php echo $message; ?></p></div>
<?php $_SERVER['REQUEST_URI'] = remove_query_arg( array( 'message', 'error' ), $_SERVER['REQUEST_URI'] );
endif; ?>
<div id="ajax-response"></div>

<div id="col-container" class="wp-clearfix">

<div class="col-wrap">

<form class="search-form wp-clearfix" method="get">
    <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']) ?>" />
    
    <?php $wp_list_table->search_box( 'Hledání', 's_name' ); ?>

</form>

<form id="children-table-form" method="get">
    <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']) ?>" />
        <?php $wp_list_table->display(); ?>
</form>
</div>
</div>
</div>
<?php    }
}

endif;