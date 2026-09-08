// jQuery by import rather than by global: this entry is emitted inside the page body,
// before base.html.twig's `app` bundle sets `window.$`, and select2 registers itself on
// the module instance in either case.
import $ from 'jquery';
import 'select2';
import 'select2/dist/css/select2.min.css';

/**
 * The object picker on a resident's card.
 *
 * «Прив'язати», «Перенести» and the owner-group form each asked for an особовий рахунок in
 * a bare text box. The accountant does not know 966 of them by heart, so the real workflow
 * was: open /admin/objects in another tab, search, copy the number, come back, paste. And a
 * mistyped number that happens to exist is accepted in silence — it attaches the person to
 * somebody else's flat, which is the one mistake on this page nothing downstream can catch.
 *
 * Searching by «85», «комірчина» or «Козацька 19» and picking a line that spells out the
 * address removes the trip and the mistake at once. The value posted is still the особовий
 * рахунок, so every controller behind these forms is untouched.
 */
document.addEventListener('DOMContentLoaded', function () {
    var $pickers = $('.js-object-search');

    if (!$pickers.length) {
        return;
    }

    $pickers.each(function () {
        var $select = $(this);

        $select.select2({
            width: '100%',
            placeholder: $select.data('placeholder') || 'Пошук об’єкта…',
            allowClear: false,
            // Ukrainian, because everything else on this page is. Select2 ships no uk
            // bundle in this build, and four strings do not need one.
            language: {
                inputTooShort: function () { return 'Введіть рахунок, квартиру або адресу'; },
                searching: function () { return 'Шукаю…'; },
                noResults: function () { return 'Нічого не знайдено'; },
                errorLoading: function () { return 'Не вдалося завантажити список'; },
            },
            ajax: {
                url: '/admin/objects/search',
                dataType: 'json',
                // The register is searched on every keystroke otherwise; 966 objects are
                // resolved per request on the server.
                delay: 250,
                data: function (params) {
                    return { q: params.term || '' };
                },
                processResults: function (data) {
                    // The endpoint answers with the first thirty and says how many it
                    // matched. A picker that truncates in silence is how «я не знайшла»
                    // turns into «його немає в системі».
                    var results = data.results || [];

                    if (data.total > data.shown) {
                        results = results.concat([{
                            id: '',
                            text: '… ще ' + (data.total - data.shown) + ' — уточніть пошук',
                            disabled: true,
                        }]);
                    }

                    return { results: results };
                },
            },
        });
    });
});
