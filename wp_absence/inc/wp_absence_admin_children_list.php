<?php

defined( 'ABSPATH' ) or die( 'Nope, not accessing this' );

if ( ! class_exists( 'School_Absence_Admin_Children_List' ) ):

if( ! class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

/**
 * Description of wp_absence_admin_children_list
 *
 * @author hmlnarik
 */
class School_Absence_Admin_Children_List extends WP_List_Table {
    private $user_id;

    public function __construct($user_id = null) {
        parent::__construct( [
            'singular' => 'child',
            'plural' => "children",
            'screen' => PAGE_MANAGE_CHILDREN
        ] );
        
        $this->user_id = $user_id;
    }

    public function prepare_items() {
        global $wpdb;
        global $s_cat, $s;
        wp_reset_vars( array( 's', 's_cat') );

        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = array( $columns, $hidden, $sortable );
        
        $this->process_bulk_action();
        
        $users_table_name = $wpdb->prefix . "users";
        $term_taxonomy_table_name = $wpdb->prefix . "term_taxonomy";
        $class_table_name = $wpdb->prefix . "terms";
        
        // Filter by category
        $where_clause = '';
        $having_clause = '';
        $params = array();
        if (is_numeric($s_cat)) {
            if ($s_cat > 0) {
                $categories = get_term_children($s_cat, 'category');
                $categories[] = $s_cat;
                $where_clause .= ' AND c.id IN (SELECT fk_' . ABSENCE_TABLE_CHILD
                                              . ' FROM ' . ABSENCE_TABLE_CHILD_CLASS
                                              . " WHERE fk_{$class_table_name} IN (" . implode(",", $categories) . "))";
            } elseif ($s_cat == -1) {    // show_option_none
                $having_clause .= ' HAVING classes IS NULL';
            }
        }
        // Filter by name
        $parts = empty($s) ? array() : preg_split('/\s+/', $s);
        foreach ($parts as $p) {
            if (! empty($p)) {
                $where_clause .= " AND (c.name LIKE %s OR u.user_login LIKE %s OR u.display_name LIKE %s)";
                $params[] = '%' . $wpdb->esc_like($p) . '%';
                $params[] = '%' . $wpdb->esc_like($p) . '%';
                $params[] = '%' . $wpdb->esc_like($p) . '%';
            }
        }
        
        if (is_numeric($this->user_id)) {
            $where_clause .= " AND u.ID = %d";
            $params[] = $this->user_id;
        }
                   
        $querystr = "
            SELECT DISTINCT c.name as name, c.note, c.id AS id, u.id AS u_id, u.display_name, u.user_login,
              (SELECT GROUP_CONCAT(CONCAT(cl.name, ' (', COALESCE(cl2.name, '-'), ')') ORDER BY cl2.name, cl.name SEPARATOR '|') 
                 FROM " . ABSENCE_TABLE_CHILD_CLASS . " cc
                   LEFT JOIN $class_table_name cl ON cc.fk_{$class_table_name} = cl.term_id
                   LEFT JOIN $term_taxonomy_table_name tt ON tt.term_id = cl.term_id
                   LEFT JOIN $class_table_name cl2 ON cl2.term_id = tt.parent
                 WHERE cc.fk_" . ABSENCE_TABLE_CHILD . " = c.ID)
                 AS classes
            FROM " . ABSENCE_TABLE_CHILD . " c, $users_table_name u
            WHERE u.ID = c.fk_$users_table_name $where_clause $having_clause
         ";

        $this->items = $wpdb->get_results(empty($params) ? $querystr : $wpdb->prepare($querystr, $params), ARRAY_A);
    }
    
    protected function get_table_classes() {
        return array( 'widefat', 'striped', $this->_args['plural'] );
    }

    public function get_columns() {
        $columns = array(
          'cb'              => '<input type="checkbox" />',
          'name'            => 'Jméno dítěte',
          'display_name'    => 'Spravováno uživatelem',
          'classes'         => 'Rubriky a třídy'
        );
        return $columns;
    }
    
    protected function column_cb( $item ) {
        return sprintf('<input type="checkbox" name="child_id[]" value="%s" />', $item['id']);
    }

    protected function column_name( $item ) {
        $edit_query_args = array(
                'action' => 'edit',
                'page' => PAGE_MANAGE_CHILDREN,
                'child_id' => $item['id'],
                '_wp_original_http_referer' => wp_unslash( $_SERVER['REQUEST_URI'] ),
        );
        $actions['edit'] = sprintf(
                '<a href="%1$s">%2$s</a>',
                esc_url( wp_nonce_url( add_query_arg( $edit_query_args, 'admin.php' ), 'childedit') ),
                __( 'Edit' )
        );

        $delete_query_args = array(
                'action' => 'delete',
                'page' => PAGE_MANAGE_CHILDREN,
                'child_id' => $item['id'],
        );
        $actions['delete'] = sprintf(
                '<a class="submitdelete" href="%1$s">%2$s</a>',
                esc_url( wp_nonce_url( add_query_arg( $delete_query_args, 'admin.php' ), 'childdelete' ) ),
                __( 'Delete' )
        );
        
        return sprintf( '%1$s %2$s', esc_html($item['name']), $this->row_actions($actions));
    }

    protected function column_display_name($item) {
        $edit_link = esc_url( add_query_arg( 'wp_http_referer', urlencode( wp_unslash( $_SERVER['REQUEST_URI'] ) ), get_edit_user_link( $item['u_id'] ) ) );
        echo "<a href=\"{$edit_link}\">" . esc_html($item['user_login']);
        if (isset($item['display_name'])) {
            echo esc_html(" ({$item['display_name']})");
        }
        echo '</a>';
    }

    protected function column_classes( $item ) {
        if (empty($item['classes'])) {
            echo '<span style="color: red"><b>Dítě neověřeno</b> (ověřovací kód: '
              . ($item['note'] ? '<b>' . esc_html($item['note']) . '</b>' : "<i>nezadán</i>")
              . ')</span>';
        } else {
            $classes = explode('|', $item['classes']);
            echo implode(', ', array_map('esc_html', $classes));
        }
    }

    protected function process_bulk_action() {
        $action = $this->current_action();
        global $child_id;
        wp_reset_vars( array( 'child_id') );
        
        switch ($action) {
        case 'delete':
            $delete = new School_Absence_Admin_Child_Delete($child_id);
            $delete->display();
            exit;
        }
    }
    
    public function search_box($text, $input_id) {
        global $s_cat;
        wp_reset_vars( array( 's_cat' ) );
        
?>
<p>
	Hledání: 
	<label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>"><?php echo $text; ?>:</label>
	<input type="search" id="<?php echo esc_attr( $input_id ); ?>" name="s" value="<?php _admin_search_query(); ?>" />

	<label class="screen-reader-text" for="<?php echo esc_attr( "s_cat" ); ?>">Rubrika či třída:</label>
    <?php
        wp_dropdown_categories(array(
            'hierarchical' => true,
            'hide_empty' => false,
            'selected' => $s_cat,
            'show_option_all' => 'Vyberte třídu / skupinu',
            'show_option_none' => 'Pouze neověřené děti',
            'class' => 'children_category_filter',
            'name' => 's_cat',
            'id' => 's_cat'
        ));
        
        submit_button( $text, '', '', false, array( 'id' => 'search-submit' ) );
        
        echo '</p>';
    }
}

endif;