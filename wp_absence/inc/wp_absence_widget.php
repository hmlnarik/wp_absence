<?php
defined( 'ABSPATH' ) or die( 'Nope, not accessing this' );

if ( ! class_exists( 'School_Absence_Widget' ) ):

/**
 * Description of wp_absence_widget
 *
 * @author hmlnarik
 */
class School_Absence_Widget extends WP_widget {

    public function __construct() {
        //set base values for the widget (override parent)
        parent::__construct(
            'School_Absence_Widget', __('WP Absence Widget', 'Absence Widget'), array('description' => __('WP Absence Widget', 'Absence'))
        );
        add_action('init', array($this, 'register_shortcodes'));
        add_action('wp_ajax_absence_ajax_create_child', array($this, 'absence_ajax_create_child'));
    }

    public function register_shortcodes() {
        add_shortcode('absence', array($this, 'absence_shortcode'));
    }

    function absence_ajax_create_child() {
        if (! is_user_logged_in()) {
            wp_send_json_error("Not logged in", 400);
        }

        global $wpdb, $name, $note;
        wp_reset_vars(array('name', 'note'));

        if (! $name || empty($name)) {
            wp_send_json_error("Jméno musí být vyplněno", 400);
        }
        
        if (! $note || empty($note)) {
            wp_send_json_error("Ověřovací kód musí být vyplněn", 400);
        }
        
        $user = wp_get_current_user();
        $users_table_name = $wpdb->prefix . "users";
        print_r($user->get_caps_data());

        if (! current_user_can(ABSENCE_CAP_UPDATE_OWN_CHILDREN)) {
            wp_send_json_error('Unauthorized.', 403);
        }
        
        $wpdb->show_errors = false;
        $res = $wpdb->insert(ABSENCE_TABLE_CHILD, array(
            'name' => $name,
            'note' => $note,
            "fk_$users_table_name" => $user->ID
        ));
        
        if ($res == false) {
            wp_send_json_error("Záznam nebylo možné vytvořit, nejspíš už existuje", 400);
        }

        wp_send_json(array(
            'id' => $wpdb->insert_id,
            'name' => $name
        ));
    }
    
    function absence_shortcode($atts = [], $content = null) {
        global $wpdb;
        $name  = isset($atts['name'] ) ? $atts['name'] : 'datepicker';

        if (! is_user_logged_in()) {
            return "";
        }

        $user = wp_get_current_user();
        $users_table_name = $wpdb->prefix . "users";
        $querystr = 'SELECT id, name FROM ' . ABSENCE_TABLE_CHILD . "
            WHERE fk_$users_table_name = " . esc_sql($user->ID) . ' ORDER BY name';

        $children_cb = '';
        $rows = $wpdb->get_results($querystr, OBJECT_K);
        $child_ids = array();
        $selected = " selected";
        foreach ($rows as $row) {
            $child_ids[] = $row->id;
            $children_cb .= "<option $selected value=\"{$row->id}\">" . esc_html($row->name) . '</option>';
            $selected = '';
        }
        
        return '<img src="' . plugins_url('../img/spinner.gif', __FILE__) . '" id="calendar-spinner" style="float: right">' . <<<"EOD"
        <ol>
            <li>
                <div id="child-selector">
                    <label for="child_id">Vyberte dítě:</label> <select id="child_id">$children_cb</select> <a onclick="jQuery('#add-child').toggle()">Přidat...</a>
                </div>
                <div id="add-child" style="display: none">
                    Přidejte dítě:<br/>
                    <table>
                        <tr>
                            <th>Jméno a příjmení:</th>
                            <td><input id="child-name"></td>
                        </tr>
                        <tr>
                            <th>Datum narození:<br><small>(pro ověření)</small></th>
                            <td><input id="child-note"></td>
                        </tr>
                    </table>
                    <button onclick="addChild('child-selector', 'child-name', 'child-note', 'child_id')" id="add-button">Přidat</button>
                </div>
            </li>
            <li>
                <label>Zvolte data, pro něž chcete upravit docházku:</label>
                <p>
                    <input type="hidden" id="datepick-child-detail-selected-dates-input">
                    <div id="datepick-child-detail"></div>

    <script>
    var ajaxurl = '/wp-admin/admin-ajax.php';
    var getChildId = function() { return jQuery('#child_id').val(); };
    
    setVisibleOnlyIfSomeChild('child-selector', 'child_id', 'add-child');
                
    jQuery('#child_id').change(function() { refreshDates(null); });
    initialGetChildAbsences();
    </script>
                <label>Vybraná data:</label> <span id="datepick-child-detail-selected-dates">žádná</span>
            </li>
            <li class="legend">
                Vyberte akci:
EOD
                . ' <button disabled="true" class="abs datepick-' . ABSENCE_KIND_ABSENT . '" onclick="submitAbsence(\'' . ABSENCE_KIND_ABSENT . '\')">Omluvit</button>'
                . ' <button disabled="true" class="abs datepick-' . ABSENCE_KIND_LUNCH . '" onclick="submitAbsence(\'' . ABSENCE_KIND_LUNCH . '\')">Odejde po obědě</button>'
                . ' <button disabled="true" class="abs datepick-' . ABSENCE_KIND_PRESENT . '" onclick="submitAbsence(\'' . ABSENCE_KIND_PRESENT . '\')">Přítomno</button>' . <<<EOD
            </li>
        </ol>
        <p>
            Přítomnost dítěte lze omluvit do konce předchozího dne, ale stravování je nutné omluvit do 13:00
            předchozího pracovního dne. Pokud omluvíte dítě později než ve 13:00,
            bude příslušné datum zobrazeno takto: <span class="datepick-B" style="width: 3em; display: inline-block;">&nbsp;</span>.
        </p>
EOD;
    }
}

endif;
