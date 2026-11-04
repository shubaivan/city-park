// jQuery by import rather than by global: this entry is emitted inside the page body,
// before base.html.twig's `app` bundle sets `window.$`.
import $ from 'jquery';
import 'select2';
import 'select2/dist/css/select2.min.css';

/**
 * The search on the debtors' SMS card: every debtor, found by building and flat.
 *
 * The table lists those above the threshold. Anybody else a person wants to write to is picked
 * here and becomes a row of that table, ticked, so the sum, the «select all» and the exclusion
 * by unticking all work on one list. A debtor already in the table is ticked, never added
 * again: one checkbox per address is what keeps `ids[]` free of doubles. The server still holds
 * the ids against its own list (DebtSmsCampaign::narrow()).
 */
document.addEventListener('DOMContentLoaded', function () {
    var $select = $('#debt-sms-search');

    if (!$select.length) {
        return;
    }

    var rows = document.getElementById('debt-sms-rows');

    function words(text) {
        return text.toLowerCase().split(/[^0-9a-zа-щьюяґєії]+/).filter(Boolean);
    }

    /**
     * Every word typed has to begin a different word of the address, so «27 63» finds буд. 27,
     * кв. 63 and never кв. 127, and «27 2» goes on narrowing as the flat is typed. Four digits
     * or more may also be anywhere in the phone number: a shorter run would match half the house.
     */
    function matches(typed, option) {
        var address = words(option.dataset.place);
        var phone = option.dataset.phone;

        return typed.every(function (word) {
            var at = address.findIndex(function (candidate) { return candidate.indexOf(word) === 0; });

            if (at >= 0) {
                address.splice(at, 1);

                return true;
            }

            return /^\d{4,}$/.test(word) && phone.indexOf(word) >= 0;
        });
    }

    /** How many of the words typed are a whole word of the address. */
    function exactWords(typed, option) {
        var address = words(option.dataset.place);

        return typed.filter(function (word) { return address.indexOf(word) >= 0; }).length;
    }

    function cell(content, className) {
        var td = document.createElement('td');
        td.className = className || '';
        td.appendChild(content);

        return td;
    }

    function addRow(option) {
        var box = document.createElement('input');
        box.type = 'checkbox';
        box.className = 'debt-sms-pick';
        box.autocomplete = 'off';
        box.id = 'debt-sms-' + option.value;
        box.name = 'ids[]';
        box.value = option.value;
        box.dataset.parts = option.dataset.parts;

        var label = document.createElement('label');
        label.className = 'mb-0';
        label.htmlFor = box.id;
        label.textContent = option.dataset.place;

        var debt = document.createElement('b');
        debt.textContent = option.dataset.debt;

        var row = document.createElement('tr');
        row.className = 'table-success';
        row.appendChild(cell(box));
        row.appendChild(cell(label));
        row.appendChild(cell(debt, 'text-right text-nowrap'));
        row.appendChild(cell(document.createTextNode(option.dataset.phone), 'text-nowrap'));

        // On top, under the search: on a phone the end of the table is a long way down.
        rows.insertBefore(row, rows.firstChild);

        return box;
    }

    var typed = [];

    $select.select2({
        width: '100%',
        placeholder: $select.data('placeholder'),
        language: {
            noResults: function () { return 'Нікого не знайдено'; },
        },
        matcher: function (params, data) {
            typed = words(params.term || '');

            if (!typed.length) {
                return data;
            }

            return data.element && data.element.value && matches(typed, data.element) ? data : null;
        },
        // кв. 63 above кв. 630 and кв. 639, whatever they owe. Otherwise largest debt first.
        sorter: function (results) {
            if (!typed.length) {
                return results;
            }

            return results.slice().sort(function (a, b) {
                return exactWords(typed, b.element) - exactWords(typed, a.element);
            });
        },
    });

    $select.on('select2:select', function (event) {
        var option = event.params.data.element;
        var box = document.getElementById('debt-sms-' + option.value) || addRow(option);

        box.checked = true;
        box.dispatchEvent(new Event('change', { bubbles: true }));
        box.closest('tr').scrollIntoView({ block: 'nearest' });

        // Empty again, ready for the next one: the table is the list, not this box.
        $select.val(null).trigger('change');
    });
});
