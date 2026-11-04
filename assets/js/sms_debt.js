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
     * кв. 63 and never кв. 127, and «27 2» goes on narrowing as the flat is typed. Tried every
     * way round: «1 19» is буд. 19, кв. 1 although «1» also begins «19». Four digits or more may
     * also be anywhere in the phone number: a shorter run would match half the house.
     */
    function matches(typed, option) {
        var phone = option.dataset.phone;

        function place(left, address) {
            if (!left.length) {
                return true;
            }

            var word = left[0];
            var rest = left.slice(1);

            if (/^\d{4,}$/.test(word) && phone.indexOf(word) >= 0 && place(rest, address)) {
                return true;
            }

            return address.some(function (candidate, at) {
                return candidate.indexOf(word) === 0
                    && place(rest, address.slice(0, at).concat(address.slice(at + 1)));
            });
        }

        return place(typed, words(option.dataset.place));
    }

    function hasWord(list, word, whole) {
        return list.some(function (candidate) {
            return whole ? candidate === word : candidate.indexOf(word) === 0;
        });
    }

    /**
     * How well the numbers typed name this address, lower is better: the first is the building,
     * the second the unit, as a person says them. So «27 17» is буд. 27, кв. 17 and not буд. 17,
     * кв. 27, whatever each owes. A single number is a building before it is a flat.
     */
    function closeness(typed, option) {
        var numbers = typed.filter(function (word) { return /\d/.test(word); });
        var house = words(option.dataset.house);
        var unit = words(option.dataset.unit);
        var first = numbers[0];
        var second = numbers[1];

        if (first === undefined) {
            return 0;
        }

        if (second === undefined) {
            if (hasWord(house, first, true)) { return 0; }
            if (hasWord(unit, first, true)) { return 1; }

            return 2;
        }

        if (hasWord(house, first, true) && hasWord(unit, second, true)) { return 0; }
        if (hasWord(house, first, true) && hasWord(unit, second, false)) { return 1; }
        // Both exact the other way round beats two beginnings: «1 19» is буд. 19, кв. 1.
        if (hasWord(house, second, true) && hasWord(unit, first, true)) { return 2; }
        if (hasWord(house, first, false) && hasWord(unit, second, false)) { return 3; }

        return 4;
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
        // How close the address is, then a flat before a parking space or a storeroom. The debt
        // only breaks a tie: the options arrive largest first and this sort keeps their order.
        sorter: function (results) {
            if (!typed.length) {
                return results;
            }

            return results.map(function (result) {
                return {
                    result: result,
                    close: closeness(typed, result.element),
                    flat: result.element.dataset.kind === 'apartment' ? 0 : 1,
                };
            }).sort(function (a, b) {
                return a.close - b.close || a.flat - b.flat;
            }).map(function (ranked) {
                return ranked.result;
            });
        },
    });

    /*
        Select2 highlights the first result that can be picked, and Enter takes the highlighted.
        When the best match has no number, that would be some other address: nothing is
        highlighted then, and picking it takes the mouse or an arrow key, on purpose.
    */
    $select.data('select2').on('results:all', function () {
        var $options = $('.select2-container--open .select2-results__option');

        if ($options.first().attr('aria-disabled') === 'true') {
            $options.removeClass('select2-results__option--highlighted');
        }
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
