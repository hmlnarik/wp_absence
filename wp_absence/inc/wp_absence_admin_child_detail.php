<?php
declare(strict_types=1);

defined( 'ABSPATH' ) or die( 'Nope, not accessing this' );

if ( ! class_exists( 'School_Absence_Admin_Child_Detail' ) ):

/**
 * Description of School_Absence_Admin_Child_Detail
 *
 * @author hmlnarik
 */
class School_Absence_Admin_Child_Detail {
    
    private $creating;
    private $child_id;
    
    public function __construct(int $child_id = null) {
        $this->creating = ! is_numeric($child_id) || $child_id <= 0;
        $this->child_id = $child_id;
        
        wp_enqueue_script( 'post' );
    }

    function absence_ajax_update_child_absences() {
        global $wpdb, $kind, $dates, $child_id;
        wp_reset_vars(array('kind', 'dates', 'child_id'));
        
        if (! isset($child_id) || ! is_numeric($child_id)) {
            wp_send_json_error('Vyberte dítě', 400);
        }
        if (! isset($kind) || ! in_array($kind, array(ABSENCE_KIND_ABSENT, ABSENCE_KIND_LUNCH, ABSENCE_KIND_PRESENT))) {
            wp_send_json_error('Chybný druh absence', 400);
        }
        if (! isset($dates) || ! preg_match('/^[0-9]{8}(,[0-9]{8})*$/', $dates)) {
            wp_send_json_error('Vyberte alespoň jedno datum', 400);
        }

        if (current_user_can(ABSENCE_CAP_UPDATE_OWN_CHILDREN) && $this->is_current_user_parent_of($child_id)) {
            // Must not update dates from the past
            $d = explode(',', $dates);
            $minDate = min($d);
            $today = date("Ymd");
            if ($minDate <= $today) {
                wp_send_json_error('Na webu lze upravovat jen data v budoucnosti.', 400);
            }
        } else if (current_user_can(ABSENCE_CAP_UPDATE_ALL_CHILDREN)) {
            $d = explode(',', $dates);
            $minDate = min($d);
        } else {
            wp_send_json_error('Unauthorized.', 403);
        }
        
        foreach ($d as $date) {
            $k = ($kind == ABSENCE_KIND_ABSENT && $date == $minDate && $this->is_late($date)) ? ABSENCE_KIND_ABSENT_LATE : $kind;
            $wpdb->replace(ABSENCE_TABLE_DATE, array(
                'date' => $date,
                'type' => $k,
                'fk_' . ABSENCE_TABLE_CHILD => $child_id
            ));
        }
        
        $this->absence_ajax_get_child_absences();
    }
    
    function is_late($date) {
        if (date("H") < 13) {   // After 13:00
            return false;
        }
        $weekday = date("N");
        if ($weekday < 5) {
            $next_workday = date('Ymd', strtotime('+1 day'));
        } else {
            $next_workday = date('Ymd', strtotime('next Monday'));
        }
        return $date == $next_workday;
    }
    
    function is_current_user_parent_of($child_id) {
        global $wpdb;
        $user = wp_get_current_user();
        $users_table_name = $wpdb->prefix . "users";
        
        $res = $wpdb->get_row('SELECT COUNT(*) AS c FROM ' . ABSENCE_TABLE_CHILD . " WHERE id = $child_id AND fk_{$users_table_name} = " . esc_sql($user->ID));
        return $res->c == 1;
    }

    function absence_ajax_get_child_absences() {
        global $wpdb, $child_id;
        wp_reset_vars(array('child_id'));

        if (! isset($child_id) || ! is_numeric($child_id)) {
            wp_send_json_error('Invalid parameter', 400);
        }
        
        if (current_user_can(ABSENCE_CAP_UPDATE_ALL_CHILDREN) || 
            (current_user_can(ABSENCE_CAP_UPDATE_OWN_CHILDREN) && $this->is_current_user_parent_of($child_id))) {
            $result = $wpdb->get_results('SELECT date, type FROM ' . ABSENCE_TABLE_DATE . ' WHERE fk_' . ABSENCE_TABLE_CHILD . " = $child_id", ARRAY_A);
        } else {
            wp_send_json_error('Unauthorized.', 403);
        }
        
        wp_send_json($result);
    }
    
    public function display() {
        $title = $this->creating ? __( 'Přidat dítě' ) : __('Upravit dítě');
        
        global $wpdb;
        if ($this->creating) {
            $current = new stdClass();
            $current->fk_wp_users = null;
            $current->name = null;
            $current->note = null;
            $current->classes = array();
        } else {
            $class_table_name = $wpdb->prefix . "terms";
            $current = $wpdb->get_row("SELECT c.*, GROUP_CONCAT(cc.fk_$class_table_name SEPARATOR ';') as classes "
                . 'FROM ' . ABSENCE_TABLE_CHILD . ' c '
                . 'LEFT JOIN ' . ABSENCE_TABLE_CHILD_CLASS . ' cc ON cc.fk_' . ABSENCE_TABLE_CHILD . ' = c.ID '
                . 'WHERE c.ID = ' . esc_sql($this->child_id)
            );
            $current->classes = $current->classes 
              ? array_map( 'intval', explode(';', $current->classes))
              : array();
        }
        
	$absence_child_update_user_meta_nonce = wp_create_nonce( 'absence_child_form_response' ); 
        
        $dropdown_html = '<select required id="absence_child_user_select" name="user_id">
			    <option value="">' . __( 'Vyberte uživatele', 'absence' ) . '</option>';
	$wp_users = get_users( array( 
            'fields' => array( 'id', 'user_login', 'display_name' ),
            'orderby' => 'user_login'
        ));
	foreach ($wp_users as $user) {
		$user_id = $user->id;
		$user_login = esc_html( $user->user_login );
		$user_display_name = esc_html( $user->display_name );
		$dropdown_html .= '<option value="' . $user_id . '"'
                    . ($current->fk_wp_users == $user_id ? ' selected' : '')
                    . '>' . $user_login . ' (' . $user_display_name  . ') ' . '</option>' . "\n";
	}
	$dropdown_html .= '</select>';

        $child_id_input = $this->creating
            ? ''
            : '<input type="hidden" name="child_id" value="' . $this->child_id . '" />';
        ?>
<div class="wrap">
 
    <h1><?php echo esc_html( $title ); ?></h1>
 
    <form method="post" action="<?php echo esc_html( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="absence_child_form_response">
        <input type="hidden" name="absence_child_update_user_meta_nonce" value="<?php echo $absence_child_update_user_meta_nonce ?>" />
        <?php echo $child_id_input ?>
        <div id="col-container" class="wp-clearfix">
            <div id="col-left">
                <div class="options">
                    <p>
                        <label>Jméno</label>
                        <br />
                        <input type="text" name="name" value="<?php echo esc_attr($current->name) ?>" />
                    </p>
                    <p>
                        <label>Rodič</label>
                        <br />
                        <?php echo $dropdown_html; ?>
                    </p>
                    <p>
                        <label>Kód</label>
                        <br />
                        <input type="text" name="note" value="<?php echo esc_attr($current->note) ?>" />
                    </p>
                </div>

                <?php
                    wp_nonce_field( 'absence-save', 'absence-message' );
                    wp_original_referer_field();
                    submit_button();
                ?>
            </div>

            <div id="col-right" class="nowrap">
                <div class="options">
                    <p>
                        <label>Rubriky a třídy</label>
                        <br />
                        <?php child_categories_meta_box($this->child_id, $current->classes); ?>
                    </p>
                </div>
            </div>
        </div>         
    </form><?php
        if (! $this->creating) : ?>
    <h3>Kalendář absencí <img src="<?php echo plugins_url('../img/spinner.gif', __FILE__) ?>" id="calendar-spinner"></h3>
    <div id="datepick-child-detail"></div>

    <script>
    function getChildId() { return <?php echo $this->child_id ?>; }
    initialGetChildAbsences();
    var dcd = jQuery('#datepick-child-detail');
    dcd.datepick('setDate', dcd.datepick('today'));
    dcd.datepick('refresh');
    updateSelectedDatesField();
    </script>

    <p>
        <label>Vybraná data:</label>
        <span id="datepick-child-detail-selected-dates">žádná</span>
    </p>
    <p class="description">Jednotlivé dny vyberte kliknutím v kalendáři. Rozsah dat vyberte kliknutím na první datum rozsahu, následně klikněte na poslední datum a současně držte klávesu Shift.</p>
        <input type="hidden" id="datepick-child-detail-selected-dates-input">
    <p class="legend">
        <button disabled="true" class="abs datepick-<?php echo ABSENCE_KIND_ABSENT ?>" onclick="submitAbsence('<?php echo ABSENCE_KIND_ABSENT ?>')">Omluvit</button>
        <button disabled="true" class="abs datepick-<?php echo ABSENCE_KIND_LUNCH ?>" onclick="submitAbsence('<?php echo ABSENCE_KIND_LUNCH ?>')">Odejde po obědě</button>
        <button disabled="true" class="abs datepick-<?php echo ABSENCE_KIND_PRESENT ?>" onclick="submitAbsence('<?php echo ABSENCE_KIND_PRESENT ?>')">Přítomno</button>
    </p>
<?php
        endif;
        echo '</div>';
    }
}

function child_categories_meta_box($child_id = 0, $selected_cats = array()) {
    $tax_name = 'category';
    $taxonomy = get_taxonomy( $tax_name );
        ?>
            <div id="taxonomy-<?php echo $tax_name; ?>" class="categorydiv">
                <div id="<?php echo $tax_name; ?>-all" class="tabs-panel">
    <?php
    $name = 'post_category';
    echo "<input type='hidden' name='{$name}[]' value='0' />"; // Allows for an empty term set to be sent. 0 is an invalid Term ID and will be ignored by empty() checks.
    ?>
                    <ul id="<?php echo $tax_name; ?>checklist" data-wp-lists="list:<?php echo $tax_name; ?>" class="categorychecklist form-no-clear">
    <?php wp_terms_checklist($child_id, array('taxonomy' => $tax_name, 'checked_ontop' => false, 'selected_cats' => $selected_cats)); ?>
                    </ul>
                </div>

    <?php if (current_user_can($taxonomy->cap->edit_terms)) : ?>
                            <div id="<?php echo $tax_name; ?>-adder" class="wp-hidden-children">
                                    <a id="<?php echo $tax_name; ?>-add-toggle" href="#<?php echo $tax_name; ?>-add" class="hide-if-no-js taxonomy-add-new">
        <?php
        /* translators: %s: add new taxonomy label */
        printf(__('+ %s'), $taxonomy->labels->add_new_item);
        ?>
                                    </a>
                                    <p id="<?php echo $tax_name; ?>-add" class="category-add wp-hidden-child">
                                            <label class="screen-reader-text" for="new<?php echo $tax_name; ?>"><?php echo $taxonomy->labels->add_new_item; ?></label>
                                            <input type="text" name="new<?php echo $tax_name; ?>" id="new<?php echo $tax_name; ?>" class="form-required form-input-tip" value="<?php echo esc_attr($taxonomy->labels->new_item_name); ?>" aria-required="true"/>
                                            <label class="screen-reader-text" for="new<?php echo $tax_name; ?>_parent">
        <?php echo $taxonomy->labels->parent_item_colon; ?>
                                            </label>
        <?php
        $parent_dropdown_args = array(
            'taxonomy' => $tax_name,
            'hide_empty' => 0,
            'name' => 'new' . $tax_name . '_parent',
            'orderby' => 'name',
            'hierarchical' => 1,
            'show_option_none' => '&mdash; ' . $taxonomy->labels->parent_item . ' &mdash;',
        );

        /**
         * Filters the arguments for the taxonomy parent dropdown on the Post Edit page.
         *
         * @since 4.4.0
         *
         * @param array $parent_dropdown_args {
         *     Optional. Array of arguments to generate parent dropdown.
         *
         *     @type string   $taxonomy         Name of the taxonomy to retrieve.
         *     @type bool     $hide_if_empty    True to skip generating markup if no
         *                                      categories are found. Default 0.
         *     @type string   $name             Value for the 'name' attribute
         *                                      of the select element.
         *                                      Default "new{$tax_name}_parent".
         *     @type string   $orderby          Which column to use for ordering
         *                                      terms. Default 'name'.
         *     @type bool|int $hierarchical     Whether to traverse the taxonomy
         *                                      hierarchy. Default 1.
         *     @type string   $show_option_none Text to display for the "none" option.
         *                                      Default "&mdash; {$parent} &mdash;",
         *                                      where `$parent` is 'parent_item'
         *                                      taxonomy label.
         * }
         */
        wp_dropdown_categories($parent_dropdown_args);
        ?>
                                            <input type="button" id="<?php echo $tax_name; ?>-add-submit" data-wp-lists="add:<?php echo $tax_name; ?>checklist:<?php echo $tax_name; ?>-add" class="button category-add-submit" value="<?php echo esc_attr($taxonomy->labels->add_new_item); ?>" />
        <?php wp_nonce_field('add-' . $tax_name, '_ajax_nonce-add-' . $tax_name, false); ?>
                                            <span id="<?php echo $tax_name; ?>-ajax-response"></span>
                                    </p>
                            </div>
    <?php endif; ?>
            </div>
    <?php
}




endif;