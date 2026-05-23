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
        "targets": 10,
        "orderable": false,
        "data": "photo_status",
        "render": function (data, type, row, meta) {
            if (row.photo_url) {
                return '<a href="' + row.photo_url + '" target="_blank" title="Відкрити повний розмір">' +
                    '<img src="' + row.photo_url + '" style="height:42px;border-radius:4px;cursor:zoom-in;" alt="photo">' +
                    '</a>';
            }
            switch (row.photo_status) {
                case 'pending': return '<span class="badge bg-warning text-dark">⏳ Очікує</span>';
                case 'blocked': return '<span class="badge bg-danger">⛔ Прострочено</span>';
                case 'future':  return '<span class="text-muted">—</span>';
                case 'legacy':
                default:        return '<span class="text-muted" title="Бронювання до запуску фото-вимоги">—</span>';
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