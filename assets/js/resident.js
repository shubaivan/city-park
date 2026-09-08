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
 *
 * **Every object is reachable, occupied ones included.** The list is the register, not the
 * free flats: a son moving in with his mother is linked to the flat that already has her on
 * it, and each line says which case it is — «👤 2» or «❓ без власника». Filtering out the
 * occupied ones would break the commonest linking there is.
 */
document.addEventListener('DOMContentLoaded', function () {
    var $pickers = $('.js-object-search');

    if (!$pickers.length) {
        return;
    }

    /** Write the «знайдено N» line over the open dropdown. */
    function countLine(text) {
        $('.select2-container--open .js-object-count').text(text);
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
                    return { q: params.term || '', page: params.page || 1 };
                },
                processResults: function (data) {
                    // Paged on scroll, never capped: the register is 966 objects and this
                    // picker is the only way one is ever chosen, so «перші тридцять і
                    // мовчання» reads as a house with thirty flats in it.
                    countLine('знайдено об’єктів: ' + data.total);

                    return {
                        results: data.results || [],
                        pagination: { more: !!(data.pagination && data.pagination.more) },
                    };
                },
            },
        });

        /*
            «знайдено N» above the list.

            The dropdown shows a page at a time and loads the next on scroll, so without
            this line there is no way to tell «це всі» from «це перші тридцять» — which is
            the first thing anybody asks when a house of 966 objects answers with a screen
            of ten.
        */
        $select.on('select2:open', function () {
            var $results = $('.select2-container--open .select2-results');

            if ($results.length && !$results.find('.js-object-count').length) {
                $results.prepend(
                    '<div class="js-object-count" style="padding:.25rem .5rem;font-size:.75rem;'
                    + 'color:#6c757d;border-bottom:1px solid #eee;">шукаю…</div>'
                );
            }
        });
    });
});
