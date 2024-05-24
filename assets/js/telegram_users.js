import 'select2';

document.addEventListener("DOMContentLoaded", function () {
    console.log("admin list!");

    let table;
    var common_defs = [];

    common_defs.push({
        "targets": 5,
        "render": function (data, type, row, meta) {
            if (data === true) {
                return '<b>Активний</b>;'
            } else {
                return '<b>Заблокований</b>';
            }
        }
    });

    common_defs.push({
        "targets": 7,
        "orderable": false,
        "render": function (data, type, row, meta) {
            var divTag = $('<div/>');
            if (Object.keys(data).length) {
                $.each(data, function( index, value ) {
                    var pOrder = $('<p/>').append('<b>' + value.property_name + ':</b> ').append('<i>'+value.property_value+'</i>');
                    divTag.append(pOrder);
                });
            }

            return divTag.html();
        }
    });

    common_defs.push({
        "targets": 13,
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
                console.log('ajax data', d);
            }
        },
        columns: th_keys,
        "columnDefs": common_defs
    });

    let exampleModal = $('#exampleModal');
    exampleModal.on('show.bs.modal', function (event) {
        var modal = $(this);
        let form = modal.find("form");

        modal.find('#save_user').remove();
        modal.find('.prop_conf').remove();
        modal.find('.prop_set').remove();
        form.find('input[type=text]').val('');
        form.find('.invalid-feedback').remove();

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
                    form.find('#is_active').prop('checked', data.is_active)

                    let product_id_input = $('<input>').attr({
                        type: 'hidden',
                        id: 'user_id',
                        name: 'user_id'
                    });
                    product_id_input.val(data.id);
                    form.append(product_id_input);

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
            let createProduct = $('#telegramUserForm');
            createProduct.find('.invalid-feedback').remove();
            let account_number = createProduct.find('#account_number');
            if (!account_number.val()) {
                var divTag = $('<div />').addClass('invalid-feedback');
                divTag.text('Це обов\'язкове поле');
                divTag.insertAfter(account_number);
                divTag.show();
                return;
            }

            let inputColumns = createProduct.find('input[type=text]');
            if (inputColumns.length) {
                $.each(inputColumns, function (k, v) {
                    $(v).val($.trim($(v).val()));
                })
            }

            let checkBoxes = createProduct.find('input[type=checkbox]');
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
            let serialize = createProduct.serialize();

            const admin_user_create = window.Routing
                .generate('admin-user-update');

            $.ajax({
                type: "POST",
                url: admin_user_create,
                data: serialize,
                error: (result) => {
                    console.log(result);
                },
                success: (data) => {
                    exampleModal.modal('toggle');
                    table.ajax.reload(null, false);
                }
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