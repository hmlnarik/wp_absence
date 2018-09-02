<?php
declare(strict_types=1);

defined( 'ABSPATH' ) or die( 'Nope, not accessing this' );

if ( ! class_exists( 'School_Absence_Admin_Child_Update_Cat' ) ):

define('BULK_CHILD_CATS_ADD', 'add');
define('BULK_CHILD_CATS_SET', 'set');
define('BULK_CHILD_CATS_REMOVE', 'remove');
    
/**
 * Description of School_Absence_Admin_Child_Update_Cat
 *
 * @author hmlnarik
 */
class School_Absence_Admin_Child_Update_Cat {
    
    private $child_ids;
    private $redirect;
    private $referer;
    private $nonce_action;
    private $action;

    public function __construct($child_ids, $action, $nonce_action = 'childupdate') {
        $this->child_ids = array_filter(is_array($child_ids) ? $child_ids : array($child_ids), 'ctype_digit');
        $this->nonce_action = $nonce_action;
        $this->action = $action;

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

	if ( ! current_user_can( 'edit_users' ) ) {
            $errors = new WP_Error( 'edit_users', __( 'Nemáte oprávnění upravovat děti.' ) );
        }

        $child_ids = array_map( 'intval', $this->child_ids);
        $target = esc_attr( admin_url( 'admin-post.php' ) );
        
        switch ($this->action) {
        case BULK_CHILD_CATS_ADD:
            $verb = 'přidat do';
            break;
        case BULK_CHILD_CATS_SET:
            $verb = 'přidat výhradně do';
            break;
        case BULK_CHILD_CATS_REMOVE:
            $verb = 'odebrat ze';
            break;
        }
        
        if (empty($errors)) :
?>
    <form method="post" name="updatechildren" id="updatechildren" action="<?php echo $target; ?>">
<?php
        wp_nonce_field('update-children-cats');
        echo $this->referer;
?>
        <div class="wrap">
<?php
        echo '<h1>' . __('Upravit třídy a rubriky dětí' ) .'</h1>'
            . '<p>';

        if ( 1 == count( $child_ids ) ) {
            echo "Chystáte se následující dítě $verb tříd a rubrik:";
        } else {
            echo "Chystáte se následující děti $verb tříd a rubrik:";
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
                    . sprintf(__('%1$s (%2$s - %3$s), <i>třídy:</i> %4$s'), $row->name, $row->user_login, $row->display_name, $row->classes) . "</li>\n";
	}
	echo '</ul>';
        child_categories_meta_box();
	?>
	<input type="hidden" name="action" value="absence_bulk_children_update_cat" />
        <input type="hidden" name="update" value="<?php echo esc_attr($this->action) ?>" />
	<?php submit_button( __('Provést'), 'primary' ); ?>
        </div>
    </form>
<?php else: ?>
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
    
    private function add_to_cats() {
        if ( ! current_user_can( ABSENCE_CAP_UPDATE_ALL_CHILDREN ) ) {
            wp_send_json_error('Unauthorized.', 403);
        }

        wp_reset_vars( array( 'post_category') );
	global $post_category, $wpdb;

        if (($key = array_search(0, $post_category)) !== false) {
            unset($post_category[$key]);
        }

        if (empty($post_category)) {
            return 0;
        }

        $class_table_name = $wpdb->prefix . "terms";

        $wpdb->query('UPDATE ' . ABSENCE_TABLE_CHILD . ' SET note = NULL WHERE ID IN (' . implode(",", array_map('esc_sql', $this->child_ids)) . ')');

        return $wpdb->query('INSERT IGNORE INTO ' . ABSENCE_TABLE_CHILD_CLASS . "(fk_{$class_table_name}, fk_" . ABSENCE_TABLE_CHILD . ") "
          . ' SELECT cc.term_ID, c.ID FROM ' . ABSENCE_TABLE_CHILD . " c, $class_table_name cc "
          . ' WHERE c.ID in (' . implode(",", array_map('esc_sql', $this->child_ids)) . ')'
          . '   AND cc.term_ID IN (' . implode(",", array_map('esc_sql', $post_category)) . ')'
        );
    }

    private function remove_from_cats() {
        wp_reset_vars( array( 'post_category') );
	global $post_category, $wpdb;

        if (empty($post_category)) {
            return 0;
        }

        $class_table_name = $wpdb->prefix . "terms";
        return $wpdb->query('DELETE FROM ' . ABSENCE_TABLE_CHILD_CLASS
          . ' WHERE fk_' . ABSENCE_TABLE_CHILD . ' IN (' . implode(",", array_map('esc_sql', $this->child_ids)) . ")"
          . "   AND fk_{$class_table_name} IN (" . implode(",", array_map('esc_sql', $post_category)) . ')'
        );
    }

    private function set_cats() {
        wp_reset_vars( array('post_category') );
	global $post_category, $wpdb;

        $class_table_name = $wpdb->prefix . "terms";

        $count = $wpdb->query('DELETE FROM ' . ABSENCE_TABLE_CHILD_CLASS
          . ' WHERE fk_' . ABSENCE_TABLE_CHILD . ' IN (' . implode(",", array_map('esc_sql', $this->child_ids)) . ")"
        );

        if (($key = array_search(0, $post_category)) !== false) {
            unset($post_category[$key]);
        }

        if (empty($post_category)) {
            return $count;
        }

        $wpdb->query('UPDATE ' . ABSENCE_TABLE_CHILD . ' SET note = NULL WHERE ID IN (' . implode(",", array_map('esc_sql', $this->child_ids)) . ')');
        
        return $wpdb->query('INSERT IGNORE INTO ' . ABSENCE_TABLE_CHILD_CLASS . "(fk_{$class_table_name}, fk_" . ABSENCE_TABLE_CHILD . ") "
          . ' SELECT cc.term_ID, c.ID FROM ' . ABSENCE_TABLE_CHILD . " c, $class_table_name cc "
          . ' WHERE c.ID in (' . implode(",", array_map('esc_sql', $this->child_ids)) . ')'
          . '   AND cc.term_ID IN (' . implode(",", array_map('esc_sql', $post_category)) . ')'
        );
    }

    public function perform() {
	check_admin_referer('update-children-cats');

        if ( empty($this->child_ids) ) {
		wp_redirect($this->redirect);
		exit();
	}

	if ( ! current_user_can( 'edit_users' ) )
            wp_die( __( 'Sorry, you are not allowed to edit children.' ), 403 );

        global $update;
        wp_reset_vars(array('update'));

        switch ($update) {
        case BULK_CHILD_CATS_ADD:
            $update_count = $this->add_to_cats();
            break;
        case BULK_CHILD_CATS_SET:
            $update_count = $this->set_cats();
            break;
        case BULK_CHILD_CATS_REMOVE:
            $update_count = $this->remove_from_cats();
            break;
        default:
            $update_count = 0;
        }
        
	$redirect = add_query_arg( array('update_count' => $update_count, 'update' => $update), $this->redirect);
	wp_redirect($redirect);
	exit();
    }
    
}

endif;