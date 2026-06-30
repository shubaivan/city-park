import 'select2';

document.addEventListener("DOMContentLoaded", function () {
    console.log("admin list!");

    let table;
    var common_defs = [];

    common_defs.push({
        "targets": 5,
        "render": function (data, type, row, meta) {
            if (data === true) {
                return '<b style="color:#0a7c2f;">Активний</b>';
            }
            var html = '<b style="color:#c00;">Заблокований</b>';
            if (row.block_reason_label) {
                html += '<br><small><b>' + row.block_reason_label + '</b>';
                if (row.block_reason_details) {
                    html += '<br><span class="text-muted">' + row.block_reason_details + '</span>';
                }
                html += '</small>';
            }
            return html;
        }
    });

    common_defs.push({
        "targets": 6,
        "render": function (data, type, row, meta) {
            if (data && parseFloat(data) > 0) {
                return '<b style="color:red;">' + parseFloat(data).toFixed(2) + ' грн</b>';
            }
            return '<span style="color:green;">0</span>';
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

    // debt_threshold column (index 8) — computed server-side
    common_defs.push({
        "targets": 8,
        "orderable": false,
        "render": function (data, type, row, meta) {
            if (data && parseFloat(data) > 0) {
                return '<b>' + parseFloat(data).toFixed(2) + ' грн</b>';
            }
            return '<span class="text-muted">—</span>';
        }
    });

    // additional_phones column (index 10 after area/threshold insertion)
    common_defs.push({
        "targets": 10,
        "orderable": false,
        "render": function (data, type, row, meta) {
            var divTag = $('<div/>');
            if (data && typeof data === 'object' && Object.keys(data).length) {
                $.each(data, function( index, value ) {
                    if (value && value.property_name !== undefined) {
                        var pOrder = $('<p/>').append('<b>' + value.property_name + ':</b> ').append('<i>'+value.property_value+'</i>');
                        divTag.append(pOrder);
                    }
                });
            }

            return divTag.html();
        }
    });

    // action column (was 14, now 16 with area + threshold added)
    common_defs.push({
        "targets": 16,
        data: 'action',
        render: function (data, type, row, meta) {
            return '    <!-- Button trigger modal -->\n' +
                '    <button type="button" class="btn btn-primary" data-user-id="' + row.id + '" data-toggle="modal" data-target="#exampleModal">\n' +
                '        Редагувати\n' +
                '    </button>'
                ;
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
        address: '',
    };

    table = $('#telegramUserTable').DataTable({
        'order': [[0, 'desc']],
        'responsive': true,
        'fixedHeader': true,
        'processing': true,
        'serverSide': true,
        'serverMethod': 'post',
        'ajax': {
            'url': collectionData,
            "data": function ( d ) {
                d.status_filter = statusFilter;
                d.account_number_filter = fieldFilters.account_number;
                d.search_last_name = fieldFilters.last_name;
                d.search_first_name = fieldFilters.first_name;
                d.search_phone = fieldFilters.phone;
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
        var $input = $('<input/>', {
            'type': 'text',
            'data-field': def.key,
            'placeholder': def.placeholder,
            'class': 'form-control form-control-sm js-user-field-filter',
            'style': 'width:' + def.width,
        });
        $wrap.append($input);
        $fieldPanel.append($wrap);
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
    ];

    var $filterLabel = $('<span/>', {
        'class': 'ml-3 mb-2 text-muted',
        'style': 'font-size:0.9em;align-self:center;',
        'text': 'Фільтр:'
    });
    filterContainer.append($filterLabel);

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
        renderFilterButtons();
        table.ajax.reload();
    });

    renderFilterButtons();

    let exampleModal = $('#exampleModal');
    exampleModal.on('show.bs.modal', function (event) {
        var modal = $(this);
        let form = modal.find("form");

        modal.find('#save_user').remove();
        modal.find('.prop_conf').remove();
        modal.find('.prop_set').remove();

        form.find('input[type=text]').val('');
        form.find('.invalid-feedback').remove();
        form.find('input[type=hidden]').remove()

        var divPropConf = $('<div/>', {'class': "prop_conf"});
        divPropConf.attr('order', 0);

        var divPropSet = $('<div/>', {'class': "prop_set"});

        var divTagColPlus = $('<div/>', {'class': "col text-right remove_block", 'text': "Додати телефон"});
        divTagColPlus.append('<i class="fas fa-plus-circle"></i>');
        divPropConf.append(divTagColPlus);

        form.append(divPropSet);
        form.append(divPropConf);

        var button = $(event.relatedTarget); // Button that triggered the modal
        let userId = button.data('userId');

        if (userId !== undefined) {
            $.ajax({
                type: "GET",
                url: window.Routing
                    .generate('admin-user-get') + '/' + userId,
                error: (result) => {
                    console.log(result);
                },
                success: (data) => {
                    console.log(data);
                    modal.find('#exampleModalLabel').text('Редагувати Користувача')
                    form.find('#account_number').val(data.account_number)
                    form.find('#apartment_number').val(data.apartment_number)
                    form.find('#house_number').val(data.house_number)
                    form.find('#street').val(data.street)
                    form.find('#area').val(data.area || '')
                    form.find('#is_active').prop('checked', data.is_active)

                    // How many times the community has voted to block this account.
                    form.find('#vote_block_count_display').html('<b>' + (data.vote_block_count || 0) + '</b>');

                    // Personal debt threshold — initial value from server, plus
                    // live recompute as admin edits area in the modal.
                    const tariffPrice = parseFloat(data.tariff_price_per_meter || 0);
                    const fallback = parseFloat(data.fallback_threshold || 1300);
                    function renderThreshold(areaVal) {
                        const a = parseFloat((areaVal || '').toString().replace(',', '.'));
                        let text;
                        if (isFinite(a) && a > 0 && tariffPrice > 0) {
                            const t = (a * tariffPrice * 1.5).toFixed(2);
                            text = '<b>' + t + ' грн</b>  <small class="text-muted">(' + a.toFixed(2) + ' м² × ' + tariffPrice.toFixed(2) + ' грн × 1.5)</small>';
                        } else if (isFinite(a) && a > 0 && tariffPrice <= 0) {
                            text = '<b class="text-warning">' + fallback.toFixed(2) + ' грн</b>  <small class="text-muted">(тариф не задано → запасний поріг)</small>';
                        } else {
                            text = '<b class="text-warning">' + fallback.toFixed(2) + ' грн</b>  <small class="text-muted">(площа не задана → запасний поріг)</small>';
                        }
                        form.find('#debt_threshold_display').html(text);
                    }
                    renderThreshold(data.area);
                    form.find('#area').off('input.thresholdRecompute').on('input.thresholdRecompute', function () {
                        renderThreshold($(this).val());
                    });

                    // Surface the derived block reason (debt / photo-miss / manual)
                    // so admins don't have to guess why the account is currently inactive.
                    let $blockReason = form.find('#block_reason_display');
                    if (!data.is_active && data.block_reason_label) {
                        let text = '<b>' + data.block_reason_label + '</b>';
                        if (data.block_reason_details) {
                            text += '<br><small class="text-muted">' + data.block_reason_details + '</small>';
                        }
                        $blockReason.html(text).closest('.form-group').show();
                    } else {
                        $blockReason.empty().closest('.form-group').hide();
                    }

                    // Track initial blocked state so we know which reason picker to show.
                    // unblock_reason appears on blocked→active; block_reason appears on
                    // active→blocked. Both feed into the bot notification text.
                    let wasBlocked = !data.is_active;
                    let $unblockGroup = form.find('#unblock_reason_group');
                    let $unblockSelect = form.find('#unblock_reason');
                    let $blockGroup = form.find('#block_reason_group');
                    let $blockSelect = form.find('#block_reason');
                    $unblockSelect.val('');
                    $blockSelect.val('');
                    $unblockGroup.toggle(wasBlocked);
                    $blockGroup.hide();

                    form.find('#is_active').off('change.statusReason').on('change.statusReason', function () {
                        let nowChecked = $(this).is(':checked');
                        $unblockGroup.toggle(wasBlocked && nowChecked);
                        $blockGroup.toggle(!wasBlocked && !nowChecked);
                        if (!nowChecked) {
                            $unblockSelect.val('');
                        }
                        if (nowChecked) {
                            $blockSelect.val('');
                        }
                    });

                    // Render last 5 status-log entries so admins can answer
                    // "who blocked X and why" without going to logs/DB.
                    let $historyGroup = form.find('#status_history_group');
                    let $historyList = form.find('#status_history_list');
                    $historyList.empty();
                    let history = data.status_history || [];
                    if (history.length === 0) {
                        $historyGroup.hide();
                    } else {
                        $historyGroup.show();
                        let sourceLabels = {
                            'admin': '👤 Адмін',
                            'debt_import': '📥 Імпорт боргів',
                            'debt_recompute': '🔁 Перерахунок боргу',
                            'photo_check': '📸 Перевірка фото',
                            'photo_attach': '📸 Завантаження фото',
                            'photo_forgive': '📸 Forgive',
                            'photo_bulk_unblock': '📸 Bulk unblock',
                        };
                        let reasonLabels = {
                            'debt': '💰 борг',
                            'photo': '📸 фото',
                            'other': '📝 інша',
                        };
                        $.each(history, function (_, e) {
                            let badge = e.new_active
                                ? '<span class="badge badge-success">✅ Розблок</span>'
                                : '<span class="badge badge-danger">⛔ Блок</span>';
                            let src = sourceLabels[e.source] || e.source;
                            let reason = e.reason_code ? (reasonLabels[e.reason_code] || e.reason_code) : '—';
                            let actor = e.actor ? '<i> · ' + e.actor + '</i>' : '<i class="text-muted"> · авто</i>';
                            let detail = e.reason_text ? '<br><small class="text-muted">' + e.reason_text + '</small>' : '';
                            $historyList.append(
                                '<li class="list-group-item p-2">'
                                + badge + ' <small>' + e.at + '</small> · ' + src + ' · ' + reason + actor
                                + detail
                                + '</li>'
                            );
                        });
                    }

                    // Show debt info
                    let debtDisplay = form.find('#debt_display');
                    if (data.debt && parseFloat(data.debt) > 0) {
                        debtDisplay.text(parseFloat(data.debt).toFixed(2) + ' грн').css('color', 'red').css('font-weight', 'bold');
                    } else {
                        debtDisplay.text('Немає боргу').css('color', 'green');
                    }

                    let product_id_input = $('<input>').attr({
                        type: 'hidden',
                        id: 'user_id',
                        name: 'user_id'
                    });
                    product_id_input.val(data.id);
                    form.append(product_id_input);

                    renderGroupSiblings(data.account_id, data.group_siblings || []);

                    if (Object.keys(data.additional_phones).length) {
                        $.each(data.additional_phones, function( index, additionalPhone ) {
                            if (Object.keys(additionalPhone).length) {
                                let order = parseInt($('#telegramUserForm .prop_conf').attr('order')) + 1;
                                divPropSet.append(addPropertiesBlock(order, additionalPhone.property_name, additionalPhone.property_value));
                                $('#telegramUserForm .prop_conf').attr('order', order )
                            }
                        });
                    }
                }
            })
        }

        modal.on('click', '.remove_block .fa-minus-square', function () {
            let current = $(this);
            let block = current.closest('.form-group');
            block.remove();
        });

        divPropConf.on('click', function () {
            let order = parseInt($('#telegramUserForm .prop_conf').attr('order')) + 1;
            divPropSet.append(addPropertiesBlock(order));
            $('#telegramUserForm .prop_conf').attr('order', order);
        })

        form.append('<button id="save_user" type="button" class="btn btn-primary">Зберегти</button>')

        $('.btn#save_user').on('click', function () {
            let telegramUserForm = $('#telegramUserForm');
            telegramUserForm.find('.invalid-feedback').remove();
            let account_number = telegramUserForm.find('#account_number');
            if (!account_number.val()) {
                var divTag = $('<div />').addClass('invalid-feedback');
                divTag.text('Це обов\'язкове поле');
                divTag.insertAfter(account_number);
                divTag.show();
                return;
            }

            // Active → blocked transition must include a reason. Mirrors the
            // server-side check in AdminController::updateUser; both layers needed
            // because the bot notification and audit log read off the same field.
            let $blockSelect = telegramUserForm.find('#block_reason');
            if ($blockSelect.closest('.form-group').is(':visible') && !$blockSelect.val()) {
                var divTag = $('<div />').addClass('invalid-feedback');
                divTag.text('Оберіть причину блокування');
                divTag.insertAfter($blockSelect);
                divTag.show();
                return;
            }

            let inputColumns = telegramUserForm.find('input[type=text]');
            if (inputColumns.length) {
                $.each(inputColumns, function (k, v) {
                    $(v).val($.trim($(v).val()));
                })
            }

            let checkBoxes = telegramUserForm.find('input[type=checkbox]');
            if (checkBoxes.length) {
                $.each(checkBoxes, function (k, v) {
                    $(v).val($(v).is(":checked"))
                })
            }

            let phones = $('.prop_set input#property_value');

            if (phones.length) {
                $.each(phones, function (k, v) {
                    const myRe = new RegExp("^38\\d{10}$", "g");
                    let isValid = myRe.exec($(v).val());
                    if (isValid === null) {
                        console.log('not valid')
                        var divTag = $('<div />').addClass('invalid-feedback');
                        divTag.text('Вкажіть вірно телефон у форматі 380111111111');
                        divTag.insertAfter($(v));
                    }
                })
            }
            let invalidFeedback = $('.prop_set .invalid-feedback');
            if (invalidFeedback.length) {
                invalidFeedback.show();
                return;
            }
            let serialize = telegramUserForm.serialize();

            const admin_user_create = window.Routing
                .generate('admin-user-update');

            $.ajax({
                type: "POST",
                url: admin_user_create,
                data: serialize,
                error: (xhr) => {
                    let msg = 'Помилка збереження';
                    try {
                        let parsed = JSON.parse(xhr.responseText);
                        if (Array.isArray(parsed) && parsed.length) {
                            msg = parsed[0];
                        }
                    } catch (e) {}
                    let $target = telegramUserForm.find('#block_reason').closest('.form-group').is(':visible')
                        ? telegramUserForm.find('#block_reason')
                        : telegramUserForm.find('#account_number');
                    var divTag = $('<div />').addClass('invalid-feedback').text(msg);
                    divTag.insertAfter($target);
                    divTag.show();
                },
                success: (data) => {
                    exampleModal.modal('toggle');
                    table.ajax.reload(null, false);
                }
            });
        });

        function renderGroupSiblings(accountId, siblings) {
            let list = $('#group_siblings_list');
            list.empty();
            $('#group_link_feedback').hide().text('');
            $('#group_link_account_number').val('');

            if (!accountId) {
                list.append('<li class="list-group-item text-muted">Аккаунт не задано — спершу збережіть особовий рахунок.</li>');
                $('#group_link_btn').prop('disabled', true);
                return;
            }
            $('#group_link_btn').prop('disabled', false).data('account-id', accountId);

            if (!siblings.length) {
                list.append('<li class="list-group-item text-muted">Прив\'язок немає — цей аккаунт сам по собі.</li>');
                return;
            }
            $.each(siblings, function (_, sib) {
                let debtLabel = (sib.debt && parseFloat(sib.debt) > 0)
                    ? ' <span style="color:red;">(' + parseFloat(sib.debt).toFixed(2) + ' грн)</span>'
                    : '';
                let item = $('<li class="list-group-item d-flex justify-content-between align-items-center"></li>');
                item.append(
                    '<span>' + (sib.apartment_number || '?') + ' · ' + (sib.street || '') + ' ' + (sib.house_number || '') +
                    ' · <small>' + (sib.account_number || '') + '</small>' + debtLabel + '</span>'
                );
                let btn = $('<button type="button" class="btn btn-sm btn-outline-danger group_unlink_btn">Відв\'язати</button>');
                btn.data('account-id', sib.id);
                item.append(btn);
                list.append(item);
            });
        }

        $(document).off('click.groupLink').on('click.groupLink', '#group_link_btn', function () {
            let sourceId = $(this).data('account-id');
            let partner = $.trim($('#group_link_account_number').val());
            let feedback = $('#group_link_feedback');
            feedback.hide().text('');
            if (!partner) {
                feedback.text('Вкажіть особовий рахунок партнера').show();
                return;
            }
            $.ajax({
                type: 'POST',
                url: window.Routing.generate('admin-account-group-link'),
                data: { source_account_id: sourceId, partner_account_number: partner },
                error: (xhr) => {
                    let msg = 'Помилка';
                    try { msg = JSON.parse(xhr.responseText)[0] || msg; } catch (e) {}
                    feedback.text(msg).show();
                },
                success: (resp) => {
                    renderGroupSiblings(sourceId, resp.group_siblings || []);
                },
            });
        });

        $(document).off('click.groupUnlink').on('click.groupUnlink', '.group_unlink_btn', function () {
            let siblingId = $(this).data('account-id');
            let sourceId = $('#group_link_btn').data('account-id');
            let feedback = $('#group_link_feedback');
            feedback.hide().text('');
            $.ajax({
                type: 'POST',
                url: window.Routing.generate('admin-account-group-unlink'),
                data: { account_id: siblingId },
                error: (xhr) => {
                    let msg = 'Помилка';
                    try { msg = JSON.parse(xhr.responseText)[0] || msg; } catch (e) {}
                    feedback.text(msg).show();
                },
                success: () => {
                    // Re-fetch from server so the source's own group state is correct
                    // (e.g. if unlink left a group of one, the source may have been auto-cleared too).
                    $.ajax({
                        type: 'GET',
                        url: window.Routing.generate('admin-user-get') + '/' + $('#user_id').val(),
                        success: (data) => renderGroupSiblings(data.account_id, data.group_siblings || []),
                    });
                },
            });
        });

        function addPropertiesBlock(order, inputName = null, inputValue = null)
        {
            var divTag = $('<div/>', {'class': "form-group"});

            let label1 = $("<label>");
            label1.attr({'for': 'property_value'});
            let input1 = $('<input>', {
                'id': 'property_value',
                'class': 'form-control',
                'name': 'additional_phones['+order+'][property_value]'
            });
            if (inputValue !== null) {
                input1.val(inputValue)
            }
            let small1 = $("<small>", {
                'class': 'form-text text-muted'
            }).text('телефон');

            let label2 = $("<label>");
            label2.attr({'for': 'property_name'});
            let input2 = $('<input>', {
                'id': 'property_name',
                'class': 'form-control',
                'name': 'additional_phones['+order+'][property_name]'
            });
            if (inputName !== null) {
                input2.val(inputName)
            }
            let small2 = $("<small>", {
                'class': 'form-text text-muted'
            }).text('умовний власник');

            divTag.append(label2).append(input2).append(small2);
            divTag.append(label1).append(input1).append(small1);

            var divTagColMinus = $('<div/>', {'class': "col text-right remove_block", 'text': "Видалити"});
            divTagColMinus.append('<i class="fas fa-minus-square"></i>')
            divTag.append(divTagColMinus);

            return divTag;
        }
    })
});
