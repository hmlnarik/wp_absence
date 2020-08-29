var datepickChildDetail = [];

jQuery(document).ready(function ($) {
    $('.children_category_filter').change(function (e) {
        this.form.submit();
    });
});

function updateDates(data) {
    var d = [];
    jQuery.each(data, function(index, value) {
        d['d' + value.date] = value.type;
    });
    datepickChildDetail = d;
    var dp = jQuery('#datepick-child-detail');
    if (dp.datepick) {
        dp.datepick('clear');
        dp.datepick('refresh');
    }
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

function submitAbsence(kind) {
    data = {
      'action': 'absence_ajax_update_child_absences',
      'kind': kind,
      'child_id': getChildId(),
      'dates': jQuery('#datepick-child-detail-selected-dates-input').val()
    };
    jQuery("#calendar-spinner").css('visibility', 'visible')
    jQuery.post(ajaxurl, data, updateDates)
      .fail(failAjax)
      .always(function() { jQuery("#calendar-spinner").css('visibility', 'hidden'); });
}

function updateSelectedDatesField() {
    var dates = jQuery('#datepick-child-detail').datepick('getDate').map(function(a) { return a.getTime(); });
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
    jQuery('#datepick-child-detail-selected-dates').text(value || 'žádná');
    jQuery('#datepick-child-detail-selected-dates-input').val(internalValue);
    jQuery('button.abs').prop( "disabled", dl < 0);
}

function failAjax(data) {
    alert("Došlo k chybě při spojení nebo zadání. Zkontrolujte zadané údaje a zkuste to znovu."
      + (data.responseJSON ? (data.responseJSON.data ? "\n\nDetail: " + data.responseJSON.data : "") : "")
    );
}

function refreshDates(afterFunction) {
    var childId = getChildId();
    if (childId && childId >= 0) {
        jQuery("#calendar-spinner").css('visibility', 'visible');
        jQuery.post(ajaxurl, {
          'action': 'absence_ajax_get_child_absences',
          'child_id': getChildId()
        }, function (data) {
            updateDates(data);
            if (afterFunction) {
                afterFunction();
            }
        }).fail(failAjax)
          .always(function() { jQuery("#calendar-spinner").css('visibility', 'hidden'); });
    } else {
        jQuery("#calendar-spinner").css('visibility', 'hidden');
        updateDates([]);
        if (afterFunction) {
            afterFunction();
        }
    }
}

function createDatePicker() {
    jQuery("#datepick-child-detail").datepick({
        multiSelect: 9999,
        monthsToShow: 2,
        showOtherMonths: true,
        selectOtherMonths: true,
        prevText: 'Předchozí měsíc',
        nextText: 'Následující měsíc',
        onSelect: onSelectDate,
        onDate: updateDate
    });
}
    
function initialGetChildAbsences() {
    refreshDates(createDatePicker);
}

function addChild(blockId, childNameId, childNoteId, selectId) {
    jQuery("#calendar-spinner").css('visibility', 'visible');
    var childName = jQuery('#' + childNameId).val();
    var childNote = jQuery('#' + childNoteId).val();
    jQuery.post(ajaxurl, {
      'action': 'absence_ajax_create_child',
      'name': childName,
      'note': childNote
    }, function (data) {
        var sel = jQuery('#' + selectId);
        sel.append(jQuery('<option>', {
            value: data.id,
            text: data.name
        }));
        sel.val(data.id);
        refreshDates(null);
        setVisibleOnlyIfSomeChild(blockId, selectId);
    }).fail(failAjax)
      .always(function() { jQuery("#calendar-spinner").css('visibility', 'hidden'); });
}

function setVisibleOnlyIfSomeChild(selectorBlockId, selId, addChildBlockId) {
    if (jQuery('#' + selId + ' option').size() === 0) {
        jQuery('#' + selectorBlockId).hide();
        jQuery('#' + addChildBlockId).show();
    } else {
        jQuery('#' + selectorBlockId).show();
    }
}