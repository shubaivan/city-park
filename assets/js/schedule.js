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

    const collectionData = window.Routing
        .generate('admin-schedule-data-table');

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
});