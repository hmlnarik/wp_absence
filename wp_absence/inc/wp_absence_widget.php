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

        // Parents without children in "List users" view
        add_action('views_users', function($views) {
            global $role;
            $current_link_attributes = '';
            $this_role = "parents-without-reg-children";
            if ( $this_role === $role ) {
                $current_link_attributes = ' class="current" aria-current="page"';
            }
            $views[$this_role] = "<a href='" . esc_url( add_query_arg( 'role', $this_role, 'users.php') ) . "'$current_link_attributes>Rodiče bez registrovaných dětí</a>";
            return $views;
        });
        add_action('users_list_table_query_args', function($args) {
            if ($args['role'] == 'parents-without-reg-children') {
                $args['role'] = 'parent';
            }

            return $args;
        });
        add_action('pre_user_query', function($query) {
            global $role, $wpdb;
            if ("parents-without-reg-children" === $role) {
                $query->query_where = str_replace(
                    'WHERE 1=1', 
                    "WHERE 1=1 AND NOT EXISTS (SELECT 1 FROM wp_absence_child c WHERE c.fk_wp_users = {$wpdb->users}.id)", 
                    $query->query_where
                );
            }
        });
        // Parents without children in "List users" view end here
        
        // Antispam
        add_action('lrm/pre_register_new_user', function() {
            if (! isset($_POST['exp-checksum']) || ! isset($_POST['checksum'])) {
                wp_send_json_error(array('message' => 'Chybný součet', 'for' => 'signup-checksum'));
            }
            if (strlen($_POST['exp-checksum']) > 2 || strlen($_POST['checksum']) > 1) {
                wp_send_json_error(array('message' => 'Chybný součet', 'for' => 'signup-checksum'));
            }
            if (! ctype_digit($_POST['exp-checksum']) || ! ctype_digit($_POST['checksum'])) {
                wp_send_json_error(array('message' => 'Chybný součet', 'for' => 'signup-checksum'));
            }
            
            $a = intdiv($_POST['exp-checksum'], 7);
            $b = (int) ($_POST['exp-checksum'] % 7);
            if ((int) $_POST['checksum'] !== ($a + $b)) {
                wp_send_json_error(array('message' => 'Chybný součet', 'for' => 'signup-checksum'));
            }
        });
        add_action('lrm/register_form', function() {
            $a = rand(1,4);
            $b = rand(1,5);
            $cislice = ['nula', 'jedna', 'dva', 'tři', 'čtyři', 'pět'];
            $checksum_label = "Uveďte číslem, kolik je $cislice[$a] a $cislice[$b]:";
            ?>
                <div class="fieldset">
                    <div class="lrm-position-relative">
                        <span>Kontrola proti spamu: </span>
                        <label for="signup-checksum" title="<?= $checksum_label; ?>"><?= $checksum_label ?></label>
                        <input name="checksum" class="full-width has-padding has-border" id="signup-checksum" type="number" placeholder="Součet" <?= $fields_required; ?> autocomplete="off" aria-label="<?= $email_label; ?>">
                        <input name="exp-checksum" type="hidden" value="<?= 7 * $a + $b; ?>">
                        <span class="lrm-error-message"></span>
                    </div>
                </div>
            <?php
        });
        // Antispam ends here
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
EOD;
    }
}

endif;
