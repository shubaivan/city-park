import 'select2';

document.addEventListener("DOMContentLoaded", function () {
    console.log("admin list!");

    let table;
    let common_defs = [];

    // Filter state — sent with every ajax request.
    const filterState = {
        dateFrom: '',
        dateTo: '',
        pavilion: '',
        status: '',
    };

    common_defs.push({
        "targets": 5,
        "render": function (data, type, row, meta) {
            if (data === true) {
                return '<b>Активний</b>';
            } else {
                return '<b style="color:#c00">Заблокований</b>';
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
                case 'future':  return '<span class="text-muted">🕓</span>';
                case 'legacy':
                default:        return '<span class="text-muted" title="Бронювання до запуску фото-вимоги">📜</span>';
            }
        }
    });

    const collectionData = window.Routing.generate('admin-schedule-data-table');

    table = $('#telegramUserTable').DataTable({
        'order': [[0, 'desc']],
        'responsive': true,
        'fixedHeader': true,
        'processing': true,
        'serverSide': true,
        'serverMethod': 'post',
        'ajax': {
            'url': collectionData,
            'data': function (d) {
                d.filter_date_from = filterState.dateFrom;
                d.filter_date_to = filterState.dateTo;
                d.filter_pavilion = filterState.pavilion;
                d.filter_status = filterState.status;
            }
        },
        columns: th_keys,
        "columnDefs": common_defs
    });

    // --- Filter toolbar wiring ---

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function fmt(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

    function rangeForPreset(preset) {
        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        let from = null, to = null;
        switch (preset) {
            case 'today':
                from = today; to = today; break;
            case 'this-week': {
                const day = today.getDay() === 0 ? 7 : today.getDay(); // Mon=1..Sun=7
                from = new Date(today); from.setDate(today.getDate() - (day - 1));
                to = new Date(from); to.setDate(from.getDate() + 6);
                break;
            }
            case 'last-week': {
                const day = today.getDay() === 0 ? 7 : today.getDay();
                const thisMon = new Date(today); thisMon.setDate(today.getDate() - (day - 1));
                from = new Date(thisMon); from.setDate(thisMon.getDate() - 7);
                to = new Date(from); to.setDate(from.getDate() + 6);
                break;
            }
            case 'this-month':
                from = new Date(today.getFullYear(), today.getMonth(), 1);
                to = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                break;
            case 'all':
            default:
                from = null; to = null;
        }
        return [from, to];
    }

    $('#scheduleFilters [data-preset]').on('click', function () {
        const $btn = $(this);
        $('#scheduleFilters [data-preset]').removeClass('active');
        $btn.addClass('active');
        const [from, to] = rangeForPreset($btn.data('preset'));
        filterState.dateFrom = from ? fmt(from) : '';
        filterState.dateTo = to ? fmt(to) : '';
        $('#filterDateFrom').val(filterState.dateFrom);
        $('#filterDateTo').val(filterState.dateTo);
        table.ajax.reload();
    });

    $('#filterDateFrom, #filterDateTo').on('change', function () {
        $('#scheduleFilters [data-preset]').removeClass('active');
        filterState.dateFrom = $('#filterDateFrom').val();
        filterState.dateTo = $('#filterDateTo').val();
        table.ajax.reload();
    });

    $('#scheduleFilters [data-pavilion]').on('click', function () {
        const $btn = $(this);
        $('#scheduleFilters [data-pavilion]').removeClass('active');
        $btn.addClass('active');
        filterState.pavilion = String($btn.data('pavilion') || '');
        table.ajax.reload();
    });

    $('#scheduleFilters [data-status]').on('click', function () {
        const $btn = $(this);
        $('#scheduleFilters [data-status]').removeClass('active');
        $btn.addClass('active');
        filterState.status = String($btn.data('status') || '');
        table.ajax.reload();
    });

    $('#filterReset').on('click', function () {
        filterState.dateFrom = filterState.dateTo = filterState.pavilion = filterState.status = '';
        $('#scheduleFilters [data-preset]').removeClass('active');
        $('#scheduleFilters [data-preset="all"]').addClass('active');
        $('#scheduleFilters [data-pavilion]').removeClass('active');
        $('#scheduleFilters [data-pavilion=""]').addClass('active');
        $('#scheduleFilters [data-status]').removeClass('active');
        $('#scheduleFilters [data-status=""]').addClass('active');
        $('#filterDateFrom, #filterDateTo').val('');
        table.ajax.reload();
    });
});
