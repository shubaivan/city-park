import 'select2';

document.addEventListener("DOMContentLoaded", function () {
    console.log("admin list!");

    let table;
    var common_defs = [];

    // A repeat-offender tally: how many community block-votes an account has lost. One
    // account out of 167 has one, and there has been a single campaign ever — so as a
    // column it printed "0" on 166 rows and pushed something useful off the screen. It
    // rides along the status instead, appearing only where there is something to say.
    function voteBlockNote(row) {
        var count = parseInt(row.vote_blocks, 10) || 0;

        if (count < 1) {
            return '';
        }

        return '<br><small style="color:#c00;">🗳️ блокувань голосуванням: ' + count + '</small>';
    }

    // apartment_number (index 2) — renders the whole address, with house_number (3)
    // hidden beside it. The two are never read apart: apartment numbers repeat across
    // the five buildings, so "кв. 76" alone names two different households (this is the
    // same trap DebtBoardService::place() guards against — when the debtors' board
    // shipped, "кв. 76" was one household owing 5 402 грн and another owing 651).
    common_defs.push({
        "targets": 2,
        "render": function (data, type, row, meta) {
            if (!row.account_number) {
                return '<span class="text-muted">—</span>';
            }

            var house = row.house_number ? 'буд. ' + row.house_number : '';
            var flat = data ? 'кв. ' + data : '';

            return [house, flat].filter(Boolean).join(', ') || '<span class="text-muted">—</span>';
        }
    });

    // house_number (index 3) — drawn inside the address column above. Hidden, not
    // removed: the «Адреса» field filter still searches it server-side.
    common_defs.push({
        "targets": 3,
        "visible": false
    });

    // street (index 4) — the ЖК is five buildings on one street, so the column reads
    // "Козацька" 166 times and says nothing; the building number next to it is what
    // actually distinguishes an address. Hidden rather than removed from
    // TelegramUser::$dataTableFields, because every columnDef below targets a column by
    // INDEX: pulling a column out of the middle would silently shift all of them by one
    // and repaint the wrong cells.
    common_defs.push({
        "targets": 4,
        "visible": false
    });

    common_defs.push({
        "targets": 5,
        "render": function (data, type, row, meta) {
            if (data === true) {
                return '<b style="color:#0a7c2f;">Активний</b>' + voteBlockNote(row);
            }
            // No account means no status to report. is_active is NULL for someone who
            // pressed /start and was never linked to a flat, and rendering that as a
            // red "Заблокований" accuses the bot of blocking a person it has never
            // heard of. These rows now turn up in ordinary searches, so the label has
            // to say what is actually true about them.
            if (!row.account_number) {
                return '<span style="color:#666;">⏳ Не прив’язаний</span>';
            }
            var html = '<b style="color:#c00;">Заблокований</b>';
            if (row.block_reason_label) {
                html += '<br><small><b>' + row.block_reason_label + '</b>';
                if (row.block_reason_details) {
                    html += '<br><span class="text-muted">' + row.block_reason_details + '</span>';
                }
                html += '</small>';
            }
            return html + voteBlockNote(row);
        }
    });

    // debt (index 6) — carries the threshold under it, because the number alone means
    // nothing: 94.50 грн blocks a 3.5 m² кладова and 900 грн does not touch a large
    // flat. Two adjacent columns saying "2732.40" and "1024.65" made the reader do that
    // comparison themselves, on a table already too wide to fit a screen.
    common_defs.push({
        "targets": 6,
        "render": function (data, type, row, meta) {
            var debt = parseFloat(data) || 0;
            var threshold = parseFloat(row.debt_threshold) || 0;

            var html = debt > 0
                ? '<b style="color:red;">' + debt.toFixed(2) + ' грн</b>'
                : '<span style="color:green;">0</span>';

            if (threshold > 0) {
                // The comparison is the whole point of showing them together, so it is
                // stated rather than left to be worked out from two numbers.
                html += '<br><small class="text-muted">поріг ' + threshold.toFixed(2)
                    + (debt > threshold ? ' · <b style="color:#c00;">перевищено</b>' : '')
                    + '</small>';
            }

            return html;
        }
    });

    // area column (index 7)
    common_defs.push({
        "targets": 7,
        "render": function (data, type, row, meta) {
            if (data && parseFloat(data) > 0) {
                return parseFloat(data).toFixed(2) + ' м²';
            }
            return '<span class="text-muted">—</span>';
        }
    });

    // debt_threshold (index 8) — hidden, not removed: it is rendered inside the debt
    // column above, and it must stay in the column list because every def here targets
    // its column by index. The server still computes and sends it.
    common_defs.push({
        "targets": 8,
        "orderable": false,
        "visible": false
    });

    // phone_number (index 9) — carries the "has relatives on file" flag that used to be
    // a column of its own. additional_phones is the pre-issued pass for a family member:
    // an owner records a relative's number, and when that person later shares their
    // contact with the bot, TelegramUserService::resolveAccount() finds the number here
    // and links them to the same rahunok with nobody's help. Worth knowing at a glance,
    // not worth a column — 19 rows out of 450 have one.
    common_defs.push({
        "targets": 9,
        "render": function (data, type, row, meta) {
            var phone = data || '<span class="text-muted">—</span>';
            var extra = row.additional_phones;
            var count = 0;

            if (extra && typeof extra === 'object') {
                $.each(extra, function (index, value) {
                    if (value && value.property_value) {
                        count++;
                    }
                });
            }

            if (!count) {
                return phone;
            }

            // 1 родич · 2 родичі · 5 родичів
            var mod10 = count % 10;
            var mod100 = count % 100;
            var word = (mod10 === 1 && mod100 !== 11)
                ? 'родич'
                : ((mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) ? 'родичі' : 'родичів');

            return phone + '<br><small class="text-muted">+' + count + ' ' + word + '</small>';
        }
    });

    // additional_phones (index 10) — hidden; the numbers themselves are edited in the
    // «Редагувати» modal, and the count above is all the list needs to show.
    common_defs.push({
        "targets": 10,
        "orderable": false,
        "visible": false
    });

    // first_name (index 11) — renders the whole name, with last_name (12) hidden beside
    // it. Telegram gives both fields separately and most people fill only the first, so
    // two columns meant one of them was blank on most rows while both ate width.
    common_defs.push({
        "targets": 11,
        "render": function (data, type, row, meta) {
            var name = [data, row.last_name]
                .filter(function (part) { return part && String(part).trim(); })
                .join(' ');

            return name || '<span class="text-muted">—</span>';
        }
    });

    // last_name (index 12) — drawn inside the name column above. Hidden, not removed:
    // the defs here target columns by index, and the per-field «Прізвище» search still
    // queries this column server-side.
    common_defs.push({
        "targets": 12,
        "visible": false
    });

    // last_visit (index 15) — the activity column, with start (14) folded under it.
    // Two full timestamps side by side took three lines each and answered one question
    // between them: is this person still using the bot. The last action leads; the join
    // date is the small grey line, since it matters only as context for the first.
    common_defs.push({
        "targets": 15,
        "render": function (data, type, row, meta) {
            // Stored as 'YYYY-MM-DD HH:MM:SS'; seconds are noise at this distance.
            var pretty = function (value, withTime) {
                if (!value) {
                    return '';
                }

                var parts = String(value).split(' ');
                var date = parts[0].split('-');

                if (date.length !== 3) {
                    return String(value);
                }

                var out = date[2] + '.' + date[1] + '.' + date[0];

                return (withTime && parts[1]) ? out + ' ' + parts[1].slice(0, 5) : out;
            };

            var last = pretty(data, true);
            var joined = pretty(row.start, false);

            if (!last && !joined) {
                return '<span class="text-muted">—</span>';
            }

            var html = last || '<span class="text-muted">—</span>';
            if (joined) {
                html += '<br><small class="text-muted">у боті з ' + joined + '</small>';
            }

            return html;
        }
    });

    // start (index 14) — drawn inside the activity column above.
    common_defs.push({
        "targets": 14,
        "visible": false
    });

    // vote_blocks (index 17) — hidden; rendered next to the status by voteBlockNote().
    common_defs.push({
        "targets": 17,
        "visible": false
    });

    // action column (was 14, now 16 with area + threshold added)
    common_defs.push({
        "targets": 16,
        data: 'action',
        render: function (data, type, row, meta) {
            // One button per row. Starting a community block-vote used to sit here too,
            // which put the rarest and heaviest action in the table next to the one
            // performed all day — it lives on the resident's card now.
            // A link to the resident's own page, not a modal. The card has an address now:
            // it can be sent to somebody, opened in a second tab, and returned to with the
            // back button — none of which a floating panel over the table could do.
            return '<a class="btn btn-primary btn-sm" href="/admin/users/' + row.id + '">Відкрити</a>';
        }
    });

    const collectionData = window.Routing
        .generate('admin-users-data-table');

    // Single mutually-exclusive status filter — buttons act like a radio group.
    // Old design (three independent toggles) ANDed conditions on the server,
    // which produced surprising empty results when admins clicked more than one.
    let statusFilter = 'all'; // 'all' | 'debt' | 'photo_blocked' | 'debt_blocked' | 'blocked'

    // Per-field search filters — all AND'd together on the server. The DataTables
    // global "Search:" input still works as an OR-across-everything quick lookup;
    // these per-field inputs are for narrowing.
    const fieldFilters = {
        account_number: '',
        last_name: '',
        first_name: '',
        phone: '',
        username: '',
        role: '',
        address: '',
    };

    table = $('#telegramUserTable').DataTable({
        'order': [[0, 'desc']],
        'responsive': true,
        'fixedHeader': true,
        'processing': true,
        'serverSide': true,
        'serverMethod': 'post',
        // DataTables' own wording is "Showing 1 to 9 of 9 entries (filtered from 449
        // total entries)", and with a filter pressed that reads as "449 blocked for
        // debt" — the denominator looks like it belongs to the filter. It does not:
        // recordsTotal is the size of the whole table and must stay that way, because
        // the pager is built from it. So the numbers get named instead of renumbered.
        'language': {
            'info': 'Показано _START_–_END_ з _TOTAL_',
            'infoFiltered': ' · усього в базі _MAX_ записів',
            'infoEmpty': 'Немає записів',
            'emptyTable': 'Нічого не знайдено',
            'zeroRecords': 'Нічого не знайдено',
            'search': 'Пошук:',
            'lengthMenu': '_MENU_ записів на сторінку',
            'processing': 'Завантаження…',
            'paginate': {
                'first': '«',
                'previous': '‹',
                'next': '›',
                'last': '»'
            }
        },
        'ajax': {
            'url': collectionData,
            "data": function ( d ) {
                d.status_filter = statusFilter;
                d.account_number_filter = fieldFilters.account_number;
                d.search_last_name = fieldFilters.last_name;
                d.search_first_name = fieldFilters.first_name;
                d.search_phone = fieldFilters.phone;
                d.search_username = fieldFilters.username;
                d.role_filter = fieldFilters.role;
                d.search_address = fieldFilters.address;
            }
        },
        columns: th_keys,
        "columnDefs": common_defs
    });

    // Render the field-search panel as a dedicated row above the table so the
    // labelled inputs don't compete for space with the global DataTables search.
    var $fieldPanel = $('<div/>', {
        'id': 'usersFieldFilters',
        'class': 'd-flex flex-wrap align-items-end mb-3',
        'style': 'gap:8px;',
    });
    $('#telegramUserTable_wrapper').prepend($fieldPanel);

    var fieldDefs = [
        { key: 'account_number', label: 'Особ. рахунок', placeholder: 'точний пошук',     width: '160px' },
        { key: 'last_name',      label: 'Прізвище',       placeholder: 'Шуба',              width: '160px' },
        { key: 'first_name',     label: "Ім'я",           placeholder: 'Іван',              width: '140px' },
        { key: 'phone',          label: 'Телефон',        placeholder: '380...',            width: '160px' },
        { key: 'username',       label: 'Telegram',       placeholder: '@mi_polina28',      width: '170px' },
        // A select, and in this panel rather than the status button row: these fields are
        // AND'd on the server, so "орендарі з боргом" is askable. In the radio group the
        // role would have excluded «Боржники», which is not a question anyone has.
        {
            key: 'role', label: 'Хто це', width: '150px', type: 'select',
            options: [
                { value: '',        text: '— усі —' },
                { value: 'owner',   text: 'Власник' },
                { value: 'family',  text: "Член сім'ї" },
                { value: 'tenant',  text: 'Орендар' },
                { value: 'none',    text: 'Не вказано' },
            ],
        },
        { key: 'address',        label: 'Адреса',         placeholder: 'вулиця / буд / кв', width: '220px' },
    ];

    var debounceTimers = {};
    fieldDefs.forEach(function (def) {
        var $wrap = $('<div/>', { 'class': 'd-flex flex-column' });
        $wrap.append($('<label/>', {
            'class': 'mb-1 text-muted',
            'style': 'font-size:0.78em;',
            'text': def.label,
        }));
        var $input;

        if (def.type === 'select') {
            $input = $('<select/>', {
                'data-field': def.key,
                'class': 'form-control form-control-sm js-user-field-select',
                'style': 'width:' + def.width,
            });
            def.options.forEach(function (o) {
                $input.append($('<option/>', { 'value': o.value, 'text': o.text }));
            });
        } else {
            $input = $('<input/>', {
                'type': 'text',
                'data-field': def.key,
                'placeholder': def.placeholder,
                'class': 'form-control form-control-sm js-user-field-filter',
                'style': 'width:' + def.width,
            });
        }

        $wrap.append($input);
        $fieldPanel.append($wrap);
    });

    // A select fires change, not input, and needs no debounce.
    $fieldPanel.on('change', '.js-user-field-select', function () {
        fieldFilters[$(this).data('field')] = $(this).val();
        renderFilterButtons();
        table.ajax.reload();
    });

    $fieldPanel.on('input', '.js-user-field-filter', function () {
        var field = $(this).data('field');
        var val = $(this).val().trim();
        clearTimeout(debounceTimers[field]);
        debounceTimers[field] = setTimeout(function () {
            fieldFilters[field] = val;
            renderFilterButtons();
            table.ajax.reload();
        }, 350);
    });

    var filterContainer = $('#telegramUserTable_wrapper .dataTables_filter, #telegramUserTable_wrapper .dt-search');

    // value === statusFilter literal; activeClass paints the chosen button.
    var statusButtons = [
        { value: 'debt',          label: '💰 Боржники',            idleClass: 'btn-outline-warning',   activeClass: 'btn-warning' },
        { value: 'photo_blocked', label: '📸 Заблоковані за фото',  idleClass: 'btn-outline-danger',    activeClass: 'btn-danger' },
        { value: 'debt_blocked',  label: '💸 Заблоковані за борг',  idleClass: 'btn-outline-dark',      activeClass: 'btn-dark' },
        { value: 'blocked',       label: '🚫 Усі заблоковані',      idleClass: 'btn-outline-secondary', activeClass: 'btn-secondary' },
        // The odd two out: every other button narrows the list, these two swap which
        // rows exist at all. The table shows everyone by default — hiding the unlinked
        // was the default for one day and cost the ОСББ a public argument, see
        // TelegramUserRepository::buildDataTablesFilters — so «Підтверджені мешканці»
        // is the one-click way back to Людмила's view of just the residents.
        { value: 'linked',        label: '✅ Підтверджені мешканці', idleClass: 'btn-outline-success',   activeClass: 'btn-success' },
        { value: 'unlinked',      label: '⏳ Чекають прив’язки',    idleClass: 'btn-outline-info',      activeClass: 'btn-info' },
    ];

    var $filterLabel = $('<span/>', {
        'class': 'ml-3 mb-2 text-muted',
        'style': 'font-size:0.9em;align-self:center;',
        'text': 'Мешканці · фільтр:'
    });
    filterContainer.append($filterLabel);

    // Says out loud what the row click does — a clickable row nobody knows is clickable
    // is the same as no clickable row.
    filterContainer.append($('<div/>', {
        'class': 'w-100 text-muted mb-2',
        'style': 'font-size:0.85em;',
        'text': '💡 Натисніть на рядок, щоб відкрити картку мешканця. Стрілка ▶ ліворуч показує колонки, які не помістилися на екран.'
    }));

    var $statusGroup = $('<div/>', {
        'class': 'btn-group ml-2 mb-2',
        'role': 'group',
        'aria-label': 'Status filter'
    });
    filterContainer.append($statusGroup);

    statusButtons.forEach(function (def) {
        var $btn = $('<button/>', {
            'type': 'button',
            'class': 'btn ' + def.idleClass,
            'data-value': def.value,
            'text': def.label
        });
        $statusGroup.append($btn);
    });

    var $resetBtn = $('<button/>', {
        'type': 'button',
        'class': 'btn btn-link ml-1 mb-2',
        'id': 'filterResetBtn',
        'text': '✖ Скинути'
    });
    filterContainer.append($resetBtn);

    function anyFieldFilterActive() {
        return Object.values(fieldFilters).some(function (v) { return v !== ''; });
    }

    function renderFilterButtons() {
        $statusGroup.find('button').each(function () {
            var def = statusButtons.find(d => d.value === $(this).data('value'));
            $(this).removeClass(def.idleClass + ' ' + def.activeClass);
            $(this).addClass(statusFilter === def.value ? def.activeClass : def.idleClass);
        });
        var anyActive = statusFilter !== 'all' || anyFieldFilterActive();
        $resetBtn.toggle(anyActive);
    }

    $statusGroup.on('click', 'button', function () {
        var clicked = $(this).data('value');
        statusFilter = (statusFilter === clicked) ? 'all' : clicked;
        renderFilterButtons();
        table.ajax.reload();
    });

    $resetBtn.on('click', function () {
        statusFilter = 'all';
        Object.keys(fieldFilters).forEach(function (k) { fieldFilters[k] = ''; });
        $fieldPanel.find('.js-user-field-filter').val('');
        $fieldPanel.find('.js-user-field-select').val('');
        renderFilterButtons();
        table.ajax.reload();
    });

    renderFilterButtons();

    // The «Редагувати» button sits in the last column of a table wider than any screen,
    // so reaching it means scrolling sideways past a dozen columns — the one action the
    // accountant performs all day was the hardest thing on the page to find. The whole
    // row now opens it. The click is forwarded to that button rather than opening the
    // modal directly, because the modal reads the user id off event.relatedTarget.
    $('#telegramUserTable tbody').on('click', 'td', function (event) {
        // Anything already interactive keeps its own behaviour: the buttons, the vote
        // link, and DataTables' own responsive expander.
        if ($(event.target).closest('a, button, input, select, label, .dtr-control, .dtr-details').length) {
            return;
        }

        // The expander lives in the first cell. Not every Responsive version puts the
        // class on the same element, so the cell position is checked too — on a narrow
        // screen this control is the only way to see the columns that did not fit, and
        // swallowing its click would hide data with no way back.
        if ($(this).is(':first-child')) {
            return;
        }

        var row = $(this).closest('tr');

        // Clicks inside the expanded panel do nothing: that is where someone reads and
        // selects the values that did not fit, and a modal opening under their cursor
        // would undo exactly the thing they just opened.
        if (row.hasClass('child')) {
            return;
        }

        var link = row.find('a[href^="/admin/users/"]').first();

        if (link.length) {
            window.location = link.attr('href');
        }
    });

    /**
     * Open one resident's card straight from a link: /admin/users?user=<id>.
     *
     * The objects register links here — from an object to the person standing behind it —
     * and that person is very likely not on the current page of a server-side table, so
     * there is no row to click. The modal fetches by id anyway; all it needs is an element
     * carrying data-user-id as the Bootstrap relatedTarget.
     */
    // Links written before the card became a page still arrive as ?user=<id>; send them on.
    (function redirectOldUserLink() {
        var wanted = new URLSearchParams(window.location.search).get('user');

        if (wanted && /^\d+$/.test(wanted)) {
            window.location.replace('/admin/users/' + wanted);
        }
    })();

    $('<style/>').text(
        '#telegramUserTable tbody tr { cursor: pointer; }'
        + '#telegramUserTable tbody tr:hover td { background: #eef4ff; }'
    ).appendTo('head');

    // The resident card is a page now: /admin/users/<id>. The modal that used to live
    // here — a narrow column over the table, with no address to return to — went with it,
    // and every form it contained is a small POST on that page instead of one JSON payload
    // that meant "save the whole person".

});
