<?php
defined( 'ABSPATH' ) or die( 'Nope, not accessing this' );

if ( ! class_exists( 'School_Only_For_Parents_Shortcode' ) ):

/**
 * Description of School_Only_For_Parents_Shortcode
 *
 * @author hmlnarik
 */
class School_Only_For_Parents_Shortcode extends WP_widget {

    public function __construct() {
        add_action('init', array($this, 'register_shortcodes'));
    }

    public function register_shortcodes() {
        add_shortcode('only_for_parents', array($this, 'only_for_parents_shortcode'));
        add_shortcode('jen_pro_rodice', array($this, 'only_for_parents_shortcode'));
    }

    function get_categories_of_children_of_current_user() {
        global $wpdb;

        $user = wp_get_current_user();
        $class_table_name = $wpdb->prefix . "terms";
        $users_table_name = $wpdb->prefix . "users";

        $querystr = "SELECT cc.fk_$class_table_name
                       FROM " . ABSENCE_TABLE_CHILD . ' c, ' . ABSENCE_TABLE_CHILD_CLASS . ' cc
                      WHERE cc.fk_' . ABSENCE_TABLE_CHILD . " = c.id AND c.fk_$users_table_name = " . esc_sql($user->ID);
        
        return $wpdb->get_col($querystr);
    }
    
    function get_categories_of_current_post() {
        $post_categories = get_the_terms(get_the_ID(), 'category' );
        
        if ( ! empty($post_categories) && ! is_wp_error($post_categories)) {
            return wp_list_pluck( $post_categories, 'term_id' );
        } else {
            return array();
        }
    }
    
    function is_user_authorized() {
        $supplied = $this->get_categories_of_children_of_current_user();
        // Extend the categories of children with all respective parent categories
        $supplied_ext = array();
        foreach ($supplied as $s) {
            $supplied_ext[$s] = true;
            $a = get_ancestors($s, 'category', 'taxonomy');
            foreach ($a as $anc) {
                $supplied_ext[$anc] = true;
            }
        }

        $required = $this->get_categories_of_current_post();
        
        foreach ($required as $r) {
            if (array_key_exists($r, $supplied_ext)) {
                return true;
            }
        }
        
        return false;
    }
    
    function only_for_parents_shortcode($atts = [], $content = null) {
        if (! is_user_logged_in()) {
            // TODO: Depends on Login and Register Modal plugin, could be extracted to configuration
            return 'Pro přístup k tomuto obsahu se nejprve <a class="popup_login" href="#">přihlašte</a>.';
        }

        if ($this->is_user_authorized() || current_user_can(ABSENCE_CAP_UPDATE_ALL_CHILDREN)) {
            return do_shortcode($content);
        }
        
        return 'K tomuto obsahu nemáte přístup.';
    }
}

endif;
