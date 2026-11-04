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

    /** What was typed: buildings and units named as such, and everything else in order. */
    function parse(term) {
        var digits = term.replace(/\D/g, '');

        // A number as people write it, «+380 67 100 00 26» or «(067) 100-00-26»: one number.
        if (digits.length >= 9 && /^[\d\s+()\-]+$/.test(term)) {
            term = digits;
        }

        var tokens = [];

        words(term).forEach(function (word) {
            var glued = word.match(/^(буд|кв)(\d.*)$/);

            if (glued) {
                tokens.push(glued[1], glued[2]);
            } else {
                tokens.push(word);
            }
        });

        var parsed = { house: null, unit: null, flatOnly: false, free: [] };

        tokens.forEach(function (word, at) {
            var next = tokens[at + 1];

            if (parsed.skip === at) {
                return;
            }
            if (/^буд/.test(word) && next && /^\d/.test(next)) {
                parsed.house = next;
                parsed.skip = at + 1;
            } else if (/^кв/.test(word) && next && /^\d/.test(next)) {
                parsed.unit = next;
                parsed.flatOnly = true;
                parsed.skip = at + 1;
            } else {
                parsed.free.push(word);
            }
        });

        parsed.named = parsed.house !== null || parsed.unit !== null;
        parsed.numbers = parsed.free.filter(function (word) { return /\d/.test(word); });

        // Numbers nobody named: the first is the building, the next the unit, as a person says them.
        var unnamed = parsed.numbers.slice();
        parsed.houseWord = parsed.house !== null ? parsed.house : (unnamed.shift() || null);
        parsed.unitWord = parsed.unit !== null ? parsed.unit : (unnamed.shift() || null);
        parsed.extra = unnamed.length > 0;
        parsed.empty = !parsed.named && !parsed.free.length;

        return parsed;
    }

    function beginsAWord(value, word) {
        return words(value).some(function (candidate) { return candidate.indexOf(word) === 0; });
    }

    /** The whole of it: «63/2» is flat 63/2, and neither flat 63 nor flat 2. */
    function isExactly(value, word) {
        return value.trim().toLowerCase() === word;
    }

    function digitsOf(phone) {
        return phone.replace(/\D/g, '');
    }

    /**
     * The whole number, in the shapes PhoneKey::isUkrainian() accepts: 380…, 0… or the bare nine
     * digits that are the person. «48671000005» ends the same way and is somebody else.
     */
    function isFullPhone(word, phone) {
        return /^(380\d{9}|0\d{9}|\d{9})$/.test(word) && digitsOf(phone).slice(-9) === word.slice(-9);
    }

    /**
     * Every word typed has to begin a different word of the address, so «27 63» finds буд. 27,
     * кв. 63 and never кв. 127, and «27 2» goes on narrowing as the flat is typed. Tried every
     * way round: «1 19» is буд. 19, кв. 1 although «1» also begins «19». A building or a flat
     * named as such is only that: «кв 27 буд 17» is not буд. 27, кв. 17. Four digits or more
     * may also be anywhere in the phone number: a shorter run would match half the house.
     */
    function matches(parsed, option) {
        var phone = digitsOf(option.dataset.phone);
        var address = words(option.dataset.place);

        function take(word) {
            var at = address.findIndex(function (candidate) { return candidate.indexOf(word) === 0; });

            if (at >= 0) {
                address.splice(at, 1);
            }
        }

        if (parsed.house !== null) {
            if (!beginsAWord(option.dataset.house, parsed.house)) {
                return false;
            }
            take(parsed.house);
        }
        if (parsed.unit !== null) {
            if (option.dataset.kind !== 'apartment' || !beginsAWord(option.dataset.unit, parsed.unit)) {
                return false;
            }
            take(parsed.unit);
        }

        function place(left, rest) {
            if (!left.length) {
                return true;
            }

            var word = left[0];
            var others = left.slice(1);
            var inPhone = /^\d{4,}$/.test(word) && (phone.indexOf(word) >= 0 || isFullPhone(word, phone));

            if (inPhone && place(others, rest)) {
                return true;
            }

            return rest.some(function (candidate, at) {
                return candidate.indexOf(word) === 0
                    && place(others, rest.slice(0, at).concat(rest.slice(at + 1)));
            });
        }

        return place(parsed.free, address);
    }

    /**
     * How well the numbers typed name this address, lower is better. So «27 17» is буд. 27, кв. 17
     * and not буд. 17, кв. 27, whatever each owes. A single number is a building before it is a flat.
     */
    function closeness(parsed, option) {
        var house = option.dataset.house;
        var unit = option.dataset.unit;
        var first = parsed.houseWord;
        var second = parsed.unitWord;

        if (first === null && second === null) {
            return 0;
        }
        if (first === null) {
            return isExactly(unit, second) ? 0 : 2;
        }
        if (second === null) {
            if (isExactly(house, first)) { return 0; }

            return !parsed.named && isExactly(unit, first) ? 1 : 2;
        }

        if (isExactly(house, first) && isExactly(unit, second)) { return 0; }
        if (isExactly(house, first) && beginsAWord(unit, second)) { return 1; }
        // Both exact the other way round beats two beginnings: «1 19» is буд. 19, кв. 1.
        if (!parsed.named && isExactly(house, second) && isExactly(unit, first)) { return 2; }
        if (beginsAWord(house, first) && beginsAWord(unit, second)) { return 3; }

        return 4;
    }

    function isExactFlat(parsed, option) {
        return parsed.houseWord !== null && parsed.unitWord !== null && !parsed.extra
            && option.dataset.kind === 'apartment' && closeness(parsed, option) === 0;
    }

    /**
     * May Enter take the top result? Only when what was typed can mean nothing else: a whole
     * phone number, or a building and a flat with exactly one flat answering to them. Anything
     * less is a list to choose from, and an SMS to the wrong flat cannot be taken back.
     */
    function isClearHit(parsed, ranked) {
        var top = ranked[0] && ranked[0].element;

        if (!top || top.disabled) {
            return false;
        }

        if (!parsed.named && parsed.free.length === 1 && isFullPhone(parsed.free[0], top.dataset.phone)) {
            return true;
        }

        return ranked.filter(function (result) { return isExactFlat(parsed, result.element); }).length === 1
            && isExactFlat(parsed, top);
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

    var parsed = parse('');
    var clearHit = false;

    $select.select2({
        width: '100%',
        placeholder: $select.data('placeholder'),
        language: {
            noResults: function () { return 'Нікого не знайдено'; },
        },
        matcher: function (params, data) {
            if (parsed.term !== (params.term || '')) {
                parsed = parse(params.term || '');
                parsed.term = params.term || '';
            }

            if (parsed.empty) {
                return data;
            }

            return data.element && data.element.value && matches(parsed, data.element) ? data : null;
        },
        // How close the address is, then a flat before a parking space or a storeroom. The debt
        // only breaks a tie: the options arrive largest first and this sort keeps their order.
        sorter: function (results) {
            clearHit = false;

            if (parsed.empty) {
                return results;
            }

            var ranked = results.map(function (result) {
                return {
                    result: result,
                    close: closeness(parsed, result.element),
                    flat: result.element.dataset.kind === 'apartment' ? 0 : 1,
                };
            }).sort(function (a, b) {
                return a.close - b.close || a.flat - b.flat;
            }).map(function (entry) {
                return entry.result;
            });

            clearHit = isClearHit(parsed, ranked);

            return ranked;
        },
    });

    /*
        Select2 highlights the first result that can be picked, and Enter takes the highlighted,
        which on an empty search is the largest debtor. Unless the top result is unmistakable,
        nothing is highlighted: an arrow key or the mouse says which one.
    */
    $select.data('select2').on('results:all', function () {
        if (!clearHit) {
            $('.select2-results__option--highlighted').removeClass('select2-results__option--highlighted');
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
