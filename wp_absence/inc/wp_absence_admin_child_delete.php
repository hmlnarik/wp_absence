<?php

defined( 'ABSPATH' ) or die( 'Nope, not accessing this' );

if ( ! class_exists( 'School_Absence_Admin_Child_Delete' ) ):

/**
 * Description of School_Absence_Admin_Child_Delete
 *
 * @author hmlnarik
 */
class School_Absence_Admin_Child_Delete {
    
    private $child_ids;
    private $redirect;
    private $referer;
    private $nonce_action;


    public function __construct($child_ids, $nonce_action = 'childdelete') {
        $this->child_ids = array_filter(is_array($child_ids) ? $child_ids : array($child_ids), 'ctype_digit');
        $this->nonce_action = $nonce_action;

        if (empty($_REQUEST) ) {
            $this->referer = '<input type="hidden" name="wp_http_referer" value="'. esc_attr( wp_unslash( $_SERVER['REQUEST_URI'] ) ) . '" />';
        } elseif ( isset($_REQUEST['_wp_http_referer']) ) {
            $this->redirect = remove_query_arg(array('_wp_http_referer', 'post_category', 'update', 'action', 'action2', 'child_id', 'update_count'), wp_unslash( $_REQUEST['_wp_http_referer'] ) );
            $this->referer = '<input type="hidden" name="wp_http_referer" value="' . esc_attr($this->redirect) . '" />';
        } elseif ( isset($_REQUEST['wp_http_referer']) ) {
            $this->redirect = remove_query_arg(array('wp_http_referer', 'post_category', 'update', 'action', 'action2', 'child_id', 'update_count'), wp_unslash( $_REQUEST['wp_http_referer'] ) );
            $this->referer = '<input type="hidden" name="wp_http_referer" value="' . esc_attr($this->redirect) . '" />';
        } else {
            $this->redirect = 'admin.php?page=' . PAGE_MANAGE_CHILDREN;
            $this->referer = '';
        }
    }

    public function display() {
        check_admin_referer($this->nonce_action);

	if ( empty($this->child_ids) ) {
            $errors = new WP_Error( 'no_item_to_update', __( 'Pro úpravy vyberte alespoň jeden záznam' ) );
	}

	if ( ! current_user_can( 'delete_users' ) ) {
            $errors = new WP_Error( 'edit_users', __( 'Nemáte oprávnění smazat děti.' ) );
        }

        $child_ids = array_map( 'intval', $this->child_ids);
        $target = esc_attr( admin_url( 'admin-post.php' ) );

        if (empty($errors)) :
?>
    <form method="post" name="updatechildren" id="updatechildren" action="<?php echo $target; ?>">
<?php
        wp_nonce_field('delete-children');
        echo $this->referer;
?>
        <div class="wrap">
<?php
        echo '<h1>' . __('Smazat děti' ) .'</h1>'
            . '<p>';

        if ( 1 == count( $child_ids ) ) {
            echo "Chystáte se smazat následující dítě:";
        } else {
            echo "Chystáte se smazat následující děti:";
        }
        echo '</p>';

        global $wpdb;
        
        $users_table_name = $wpdb->prefix . "users";
        $class_table_name = $wpdb->prefix . "terms";
        $term_taxonomy_table_name = $wpdb->prefix . "term_taxonomy";
        
        $querystr = "
            SELECT c.ID as id, c.name AS name, u.display_name, u.user_login,
              (SELECT GROUP_CONCAT(CONCAT(cl.name, ' (', COALESCE(cl2.name, '-'), ')') ORDER BY cl2.name, cl.name SEPARATOR ', ') 
                 FROM " . ABSENCE_TABLE_CHILD_CLASS . " cc
                   LEFT JOIN $class_table_name cl ON cc.fk_{$class_table_name} = cl.term_id
                   LEFT JOIN $term_taxonomy_table_name tt ON tt.term_id = cl.term_id
                   LEFT JOIN $class_table_name cl2 ON cl2.term_id = tt.parent
                 WHERE cc.fk_" . ABSENCE_TABLE_CHILD . " = c.ID)
                 AS classes
            FROM " . ABSENCE_TABLE_CHILD . " c, $users_table_name u
            WHERE u.ID = c.fk_$users_table_name
              AND c.ID IN (" . implode(',', $child_ids) . ')
            ORDER BY c.name';
        
        echo '<ul>';
        foreach ( $wpdb->get_results($querystr) as $row ) {
            echo "<li><input type=\"hidden\" name=\"child_id[]\" value=\"" . esc_attr($row->id) . "\" />"
                    . sprintf(__('%1$s (%2$s - %3$s), třídy: %4$s'), $row->name, $row->user_login, $row->display_name, $row->classes) . "</li>\n";
	}
	?>
	</ul>
	<input type="hidden" name="action" value="absence_bulk_children_delete" />
	<?php submit_button( __('Confirm Deletion'), 'primary' ); ?>
        </div>
    </form>
<?php   else: ?>
    <div class="error">
        <ul>
            <?php
                foreach ($errors->get_error_messages() as $err) {
                    echo "<li>$err</li>";
                }
            ?>
        </ul>
    </div>
<?php 
        if (isset($this->redirect)) {
            echo '<a href="' . esc_attr($this->redirect) . '" class="button">Zpět</a>';
        }

        endif;
    }

    public function perform() {
	check_admin_referer('delete-children');

        if ( empty($this->child_ids) ) {
		wp_redirect($this->redirect);
		exit();
	}

        $child_ids = array_map( 'intval', $this->child_ids);

        if ( ! current_user_can( ABSENCE_CAP_UPDATE_ALL_CHILDREN ) ) {
            wp_send_json_error('Unauthorized.', 403);
        }

	$update = 'del';
	$delete_count = count($child_ids);

	global $wpdb;
	foreach ( $child_ids as $id ) {
            $wpdb->delete(ABSENCE_TABLE_CHILD, array('ID' => $id));
	}

	$redirect = add_query_arg( array('delete_count' => $delete_count, 'update' => $update), $this->redirect);
	wp_redirect($redirect);
	exit();
    }
    
}

endif;