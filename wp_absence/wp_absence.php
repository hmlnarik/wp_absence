<?php

/*
Plugin Name: Evidence absencí
Plugin URI: https://github.com/hmlnarik/wp_absence
Description: Evidence absencí žáků
Version: 1.0.1
Author: hmlnarik
Author URI: 
License: GPLv2
*/

/* 
Copyright (C) 2018 Hynek Mlnařík

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
*/

defined( 'ABSPATH' ) or die( 'Nope, not accessing this' );

include(plugin_dir_path(__FILE__) . 'inc/wp_absence_widget.php');
include(plugin_dir_path(__FILE__) . 'inc/wp_only_for_parents_shortcode.php');
include(plugin_dir_path(__FILE__) . 'inc/wp_absence_admin_child_detail.php');
include(plugin_dir_path(__FILE__) . 'inc/wp_absence_admin_child_update_cat.php');
include(plugin_dir_path(__FILE__) . 'inc/wp_absence_admin_child_delete.php');
include(plugin_dir_path(__FILE__) . 'inc/wp_absence_admin_children_list.php');
include(plugin_dir_path(__FILE__) . 'inc/wp_absence_admin_calendar.php');
include(plugin_dir_path(__FILE__) . 'inc/wp_absence_admin_children.php');

if ( ! class_exists( 'School_Absence' ) ):

require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

define ('ABSENCE_TABLE_CHILD', $wpdb->prefix . "absence_child");
define ('ABSENCE_TABLE_CHILD_CLASS', $wpdb->prefix . "absence_child_class");
define ('ABSENCE_TABLE_DATE', $wpdb->prefix . "absence_date");

define ('ABSENCE_KIND_ABSENT', 'A');
define ('ABSENCE_KIND_LUNCH', 'L');
define ('ABSENCE_KIND_PRESENT', 'N');

define ('ABSENCE_CAP_UPDATE_OWN_CHILDREN', 'update_own_children');
define ('ABSENCE_CAP_UPDATE_ALL_CHILDREN', 'update_all_children');
    
class School_Absence {

    private $adminCalendar;
    private $childrenAdmin;
    
    public function __construct() {
//        add_action('add_meta_boxes', array($this,'add_absence_meta_boxes'));

        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_scripts_and_styles'));
        add_action('admin_menu', array($this, 'register_admin_menu'));
        
        add_filter('wp_nav_menu_items', array($this, 'direct_menu_logout_link'), 10, 2);
        
        $this->adminCalendar = new School_Absence_Admin_Calendar();
        $this->childrenAdmin = new School_Absence_Admin_Children();
        $widget = new School_Absence_Widget();
        $widget = new School_Only_For_Parents_Shortcode();
        
        register_activation_hook(__FILE__, array($this, 'plugin_activate')); //activate hook
        register_deactivation_hook(__FILE__, array($this, 'plugin_deactivate')); //deactivate hook
    }
    
    function direct_menu_logout_link($nav, $args) {
        if (! is_user_logged_in() || $args->theme_location != 'primary') {
            return $nav;
        }
        
        $logoutlink = '<li><a href="' . wp_logout_url('/') . '">Odhlásit</a></li>';

        return $nav . $logoutlink;
    }

    //triggered on activation of the plugin (called only once)
    public function plugin_activate() {
        flush_rewrite_rules();
        $this->create_db(); 
        
        global $wp_roles;
        if (! isset($wp_roles)) {
            $wp_roles = new WP_Roles();
        }

        add_filter('pre_option_default_role', function($default_role){
            return 'parent';
        });

        add_filter('wp_terms_checklist_args', function($args, $idPost) {
            $args['checked_ontop'] = false;
            return $args;
        }, 10, 2);
        
        add_role('parent', 'Rodič', array('read' => true, ABSENCE_CAP_UPDATE_OWN_CHILDREN => true));
        add_role('teacher', 'Učitel', array('read' => true, 'manage_categories' => true, ABSENCE_CAP_UPDATE_ALL_CHILDREN => true));
        
        $editorRoles = $wp_roles->get_role('editor')->capabilities;
        $editorRoles[ABSENCE_CAP_UPDATE_ALL_CHILDREN] = true;
        $editorRoles['delete_users'] = true;
        $editorRoles['create_users'] = true;
        $editorRoles['edit_users'] = true;
        $editorRoles['list_users'] = true;
        $editorRoles['remove_users'] = true;
        $editorRoles['edit_theme_options'] = true;
        $editorRoles['manage_options'] = true;
        add_role('teacher-editor', 'Učitel a editor', $editorRoles);
        
        $role = get_role( 'administrator' );
        $role->add_cap(ABSENCE_CAP_UPDATE_ALL_CHILDREN);
    }

    //trigered on deactivation of the plugin (called only once)
    public function plugin_deactivate(){
        //flush permalinks
        flush_rewrite_rules();

        remove_role( 'parent');
        remove_role( 'teacher');
        remove_role( 'teacher-editor');
    }

    //enqueus scripts and stles on the back end
    public function enqueue_admin_scripts_and_styles(){
//        wp_enqueue_style('wp_location_admin_styles', plugin_dir_url(__FILE__) . '/js/wp_location_admin_styles.css');
    }

    //enqueues scripts and styled on the front end
    public function enqueue_public_scripts_and_styles(){
        wp_enqueue_script( 'children', plugins_url('js/children.js', __FILE__));
        wp_enqueue_script( 'datepicker.plugin.js', plugin_dir_url( __FILE__ ) . 'js/jquery.plugin.min.js' );
        wp_enqueue_script( 'datepicker.js', plugin_dir_url( __FILE__ ) . 'js/jquery.datepick.js' );
        wp_enqueue_script( 'datepicker.local', plugin_dir_url( __FILE__ ) . 'js/jquery.datepick-cs.js' );
        wp_enqueue_style( 'datepicker.ui', plugin_dir_url( __FILE__ ) . 'css/redmond.datepick.css');
    }
    
    public function create_db() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $users_table_name = $wpdb->prefix . "users";
        $class_table_name = $wpdb->prefix . "terms";
        
        $sql = "CREATE TABLE " . ABSENCE_TABLE_CHILD . "(
          id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
          fk_$users_table_name bigint UNSIGNED NOT NULL,
          name varchar(100) NOT NULL,
          note varchar(255),
          PRIMARY KEY  (id),
          UNIQUE KEY   (fk_{$users_table_name}, name),
          FOREIGN KEY  fk_{$users_table_name}_idx (fk_$users_table_name) REFERENCES $users_table_name (id) ON DELETE CASCADE
        ) $charset_collate;";
        maybe_create_table(ABSENCE_TABLE_CHILD, $sql );
        
        $sql = "CREATE TABLE " . ABSENCE_TABLE_CHILD_CLASS . "(
          id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
          fk_" . ABSENCE_TABLE_CHILD . " bigint UNSIGNED NOT NULL,
          fk_$class_table_name bigint UNSIGNED NOT NULL,
          PRIMARY KEY  (id),
          UNIQUE KEY   (fk_" . ABSENCE_TABLE_CHILD . ", fk_$class_table_name),
          FOREIGN KEY  fk_{$class_table_name}_idx (fk_$class_table_name) REFERENCES $class_table_name (term_id) ON DELETE CASCADE,
          FOREIGN KEY  fk_" . ABSENCE_TABLE_CHILD . "_idx (fk_" . ABSENCE_TABLE_CHILD . ") REFERENCES " . ABSENCE_TABLE_CHILD . "(id) ON DELETE CASCADE
        ) $charset_collate;";
        maybe_create_table(ABSENCE_TABLE_CHILD_CLASS, $sql );
        
        $sql = "CREATE TABLE " . ABSENCE_TABLE_DATE . " (
          id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
          fk_" . ABSENCE_TABLE_CHILD . " bigint UNSIGNED NOT NULL,
          date bigint UNSIGNED NOT NULL,
          type tinytext NOT NULL,
          PRIMARY KEY  (id),
          UNIQUE KEY  (fk_" . ABSENCE_TABLE_CHILD . ", date),
          KEY  idx_date (date),
          FOREIGN KEY  fk_" . ABSENCE_TABLE_CHILD . "_idx (fk_" . ABSENCE_TABLE_CHILD . ") REFERENCES " . ABSENCE_TABLE_CHILD . "(id) ON DELETE CASCADE
        ) $charset_collate;";
        maybe_create_table(ABSENCE_TABLE_DATE, $sql );
        
        add_option( "absence_db_version", "1.0" );
    }


    public function register_admin_menu() {
        global $wpdb;
        
        $children_waiting = $wpdb->get_row("SELECT COUNT(DISTINCT id) FROM " . ABSENCE_TABLE_CHILD 
            . " c WHERE NOT EXISTS (SELECT 1 FROM " . ABSENCE_TABLE_CHILD_CLASS . " WHERE fk_" . ABSENCE_TABLE_CHILD . " = c.ID)", ARRAY_N)[0];
        if ($children_waiting) {
            $search_args = array(
                    's_cat' => -1,
                    'page' => PAGE_MANAGE_CHILDREN
            );
            $a = add_query_arg( $search_args, 'admin.php' );

            $title = sprintf('Absence <span onclick="window.location.href = \'%s\'; return false" class="awaiting-mod">%d</span>', $a, $children_waiting);
        } else {
            $title = 'Absence';
        }
        
        add_menu_page("Absence", $title, ABSENCE_CAP_UPDATE_ALL_CHILDREN, PAGE_MANAGE_CHILDREN, array($this->childrenAdmin, 'admin_page_children'));
        add_submenu_page(PAGE_MANAGE_CHILDREN, "Kalendář zadaných absencí", "Kalendář", ABSENCE_CAP_UPDATE_ALL_CHILDREN, PAGE_CALENDAR, array($this->adminCalendar, 'display'));
    }    
}

endif;

$a = new School_Absence();