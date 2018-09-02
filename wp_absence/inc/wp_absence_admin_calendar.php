<?php
declare(strict_types=1);

defined( 'ABSPATH' ) or die( 'Nope, not accessing this' );

if ( ! class_exists( 'School_Absence_Admin_Calendar' ) ):

/**
 * Description of School_Absence_Admin_Calendar
 *
 * @author hmlnarik
 */
class School_Absence_Admin_Calendar {
    
    function __construct() {
        add_action('wp_ajax_absence_ajax_get_category_absences', array($this, 'absence_ajax_get_category_absences'));
    }

    function absence_ajax_get_category_absences() {
        if ( ! current_user_can( ABSENCE_CAP_UPDATE_ALL_CHILDREN ) ) {
            wp_die('Unauthorized.');
        }

        global $wpdb, $s_cat, $dates;
        wp_reset_vars(array('s_cat', 'dates'));

        if (! isset($s_cat) || ! is_numeric($s_cat) || $s_cat <= 0) {
            wp_send_json_error('Chybná třída / rubrika: ' . $s_cat, 400);
        }
        
        if (! isset($dates) || ! preg_match('/^[0-9]{8}(,[0-9]{8})*$/', $dates)) {
            wp_send_json_error('Vyberte data', 400);
        }
        
        // TODO: check that current user can view this child's dates
        
        $categories = get_term_children($s_cat, 'category');
        $categories[] = $s_cat;
        $categories_param = implode(",", $categories);

        $users_table_name = $wpdb->prefix . "users";
        $term_taxonomy_table_name = $wpdb->prefix . "term_taxonomy";
        $class_table_name = $wpdb->prefix . "terms";

        // now that the input was checked for sanity, we can use it directly, no SQL injection possible here
        $where_clause = " AND t.date IN ($dates)
                          AND cc.fk_{$class_table_name} IN ($categories_param)
                          AND t.type != '" . ABSENCE_KIND_PRESENT . "'";
                          
        
        $querystr = "
            SELECT DISTINCT c.name as name, c.id AS id, u.display_name, u.user_login, t.type, t.date,
              CONCAT(cl.name, ' (', COALESCE(cl2.name, '-'), ')') AS cl
            FROM " . ABSENCE_TABLE_CHILD . " c LEFT JOIN $users_table_name u ON u.ID = c.fk_$users_table_name,
               " . ABSENCE_TABLE_DATE . " t,
               " . ABSENCE_TABLE_CHILD_CLASS . " cc
                 LEFT JOIN $class_table_name cl ON cc.fk_{$class_table_name} = cl.term_id
                 LEFT JOIN $term_taxonomy_table_name tt ON tt.term_id = cl.term_id
                 LEFT JOIN $class_table_name cl2 ON cl2.term_id = tt.parent
            WHERE c.ID = cc.fk_" . ABSENCE_TABLE_CHILD . "
              AND c.ID = t.fk_" . ABSENCE_TABLE_CHILD .
            "$where_clause ORDER BY cl, date, type, c.name";
                 
        $result = array();
        foreach ($wpdb->get_results($querystr, ARRAY_A) as $row) {
            $result[$row['cl']][$row['date']][$row['name']] = $row;
        }
        
        wp_send_json($result);
    }
    
    public function display() {
        global $s_cat;
        wp_reset_vars(array('$s_cat'));
        ?>
<div class="wrap">
 
    <h1>Absence dle data a třídy / rubriky <img src="<?php echo plugins_url('../img/spinner.gif', __FILE__) ?>" id="calendar-spinner" style="visibility: hidden"></h1>

    <?php
        wp_dropdown_categories(array(
            'hierarchical' => true,
            'hide_empty' => false,
            'selected' => isset($s_cat) ? $s_cat : null,
            'show_option_all' => 'Vyberte třídu / skupinu',
            'name' => 's_cat',
            'id' => 's_cat'
        ));
    ?>

    <p>
        <label>Vybraná data:</label>
        <span id="datepick-list-selected-dates">žádná</span>
        <div id="datepick-list"></div>
    </p>
    <p class="description">Jednotlivé dny vyberte kliknutím v kalendáři. Rozsah dat vyberte kliknutím na první datum rozsahu, následně klikněte na poslední datum a současně držte klávesu Shift.</p>
        <input type="hidden" id="datepick-list-selected-dates-input">
    <p class="legend">
    </p>
    <p>
        <button onclick="submitQuery()">Zjistit absence</button>
    </p>
    
    <div id="absence-results">
    </div>
    
    <script>
    function updateSelectedDatesField() {
        var dates = jQuery('#datepick-list').datepick('getDate').map(function(a) { return a.getTime(); });
        dates.sort();

        var value = ''; 
        var internalValue = '';
        var range = false;
        var dl = dates.length - 1;
        for (var i = 0; i < dates.length; i++) {
            if (i > 0 && i < dl && dates[i + 1] === dates [i - 1] + 2 * 1000 * 60 * 60 * 24) {
                range = true;
            } else if (range) {
                value += ' - ' + jQuery.datepick.formatDate("d. m. yyyy", new Date(dates[i]));
                range = false;
            } else {
                value += (i === 0 ? '' : ', ') + jQuery.datepick.formatDate("d. m. yyyy", new Date(dates[i]));
            }
            internalValue += (i === 0 ? '' : ',') + jQuery.datepick.formatDate("yyyymmdd", new Date(dates[i]));
        } 
        jQuery('#datepick-list-selected-dates').text(value || 'žádná');
        jQuery('#datepick-list-selected-dates-input').val(internalValue);
        jQuery('button.abs').prop( "disabled", dl < 0);
    }

    var datepickChildDetail = [];
    
    function submitQuery(kind) {
        data = {
          'action': 'absence_ajax_get_category_absences',
          's_cat': jQuery("#s_cat").val(),
          'dates': jQuery('#datepick-list-selected-dates-input').val()
        };
        jQuery("#calendar-spinner").css('visibility', 'visible')
        jQuery.post(ajaxurl, data, updateDates)
          .fail(failAjax)
          .always(function() { jQuery("#calendar-spinner").css('visibility', 'hidden'); });
    }

    function createClasses(dest, data) {
        jQuery.each(data, function(index, value) {
            var div = jQuery('<div class="child-list">');
            dest.append(div);
            div.append(jQuery('<h2>').text(index));
            createDates(div, value);
        });
    }
    
    function createDates(dest, data) {
        var ul = jQuery('<ul>');
        dest.append(ul);
        jQuery.each(data, function(index, value) {
            var y = index.substring(0, 4);
            var m = index.substring(4, 6);
            var d = index.substring(6, 8);
            var df = parseInt(d) + ". " + parseInt(m) + ". " + y;
            var li = jQuery('<li>').text(df + ": ");
            ul.append(li.append(createChildren(li, value)));
        });
    }                

    function createChildren(dest, data) {
        var editUrl = "<?php
        $edit_query_args = array(
                'action' => 'edit',
                'page' => PAGE_MANAGE_CHILDREN
        );
        echo esc_js( wp_nonce_url( add_query_arg( $edit_query_args, 'admin.php' ), 'childedit'));
        ?>";
        jQuery.each(data, function(index, value) {
            var res = jQuery('<a target="_blank" class="absent-child datepick-' + value.type + '" href="' + editUrl + '&child_id=' + value.id + '">')
              .attr('title', 'Spravováno uživatelem: ' + value.display_name + ' (' + value.user_login + ')')
              .text(index);
            dest.append(res);
        });
    }

    function updateDates(data) {
        var root = jQuery('#absence-results');
        root.empty();
        
        createClasses(root, data);
        root.append(jQuery('<br style="clear: both; margin-top: 1en"/>'));
        var p = jQuery('<p/>');
        root.append(p);
        p.append([
            jQuery('<span>Legenda: </span>'),
            jQuery('<div class="legend datepick-L"/>'),
            jQuery('<span> Jen do oběda&nbsp; </span>'),
            jQuery('<div class="legend datepick-A"/>'),
            jQuery('<span> Omluveno <span>')
        ]);
    }

    function onSelectDate(date) {
        updateSelectedDatesField();
    }

    function updateDate(date) {
        var t = 'd' + jQuery.datepick.formatDate("yyyymmdd", date);
        return {
           selectable: date.getDay() > 0 && date.getDay() <= 5,
           dateClass: (t in datepickChildDetail ? 'datepick-' + datepickChildDetail[t] : null)
        };
    }

    jQuery("#datepick-list").datepick({
        multiSelect: 9999,
        monthsToShow: 2,
        showOtherMonths: true,
        selectOtherMonths: true,
        prevText: 'Předchozí měsíc',
        nextText: 'Následující měsíc',
        onSelect: onSelectDate,
        onDate: updateDate
    });

    </script>
</div>
<?php
    }
}

endif;