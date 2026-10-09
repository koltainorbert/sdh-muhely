/**
 * SDH Műhely – Beállítások → Ügyféloldal (0.27).
 *
 * Médiaválasztó (logó, háttérkép, háttérvideó) a WordPress médiatárából,
 * élő előnézettel; a sötétítés csúszkájának kijelzője; a színválasztó és a
 * hexa mező összekötése.
 */
jQuery(function ($) {
    'use strict';

    function elonezet($doboz) {
        var tipus = $doboz.data('sdh-media');
        var url = $.trim($doboz.find('input[type="text"]').val() || '');
        var $elo = $doboz.find('[data-sdh-media-elo]').empty();

        $doboz.find('[data-sdh-media-torol]').prop('hidden', url === '');

        if (url === '') {
            return;
        }

        if (tipus === 'video') {
            $('<video muted playsinline preload="metadata">').attr('src', url).appendTo($elo);
        } else {
            $('<img alt="">').attr('src', url).appendTo($elo);
        }
    }

    $(document).on('click', '[data-sdh-media-valaszt]', function (esemeny) {
        esemeny.preventDefault();

        if (!window.wp || !wp.media) {
            return;
        }

        var $doboz = $(this).closest('[data-sdh-media]');
        var tipus = $doboz.data('sdh-media');
        var keret = wp.media({
            title: tipus === 'video' ? 'Háttérvideó kiválasztása' : 'Kép kiválasztása',
            library: { type: tipus },
            button: { text: 'Kiválasztás' },
            multiple: false
        });

        keret.on('select', function () {
            var fajl = keret.state().get('selection').first().toJSON();

            $doboz.find('input[type="text"]').val(fajl.url).trigger('change');
        });

        keret.open();
    });

    $(document).on('click', '[data-sdh-media-torol]', function (esemeny) {
        esemeny.preventDefault();
        $(this).closest('[data-sdh-media]').find('input[type="text"]').val('').trigger('change');
    });

    $(document).on('change', '[data-sdh-media] input[type="text"]', function () {
        elonezet($(this).closest('[data-sdh-media]'));
    });

    $('#rma_sotetites').on('input change', function () {
        $('[data-sdh-kimenet="rma_sotetites"]').text(this.value);
    });

    $('[data-sdh-szin-cel]').each(function () {
        var $valaszto = $(this);
        var $mezo = $('#' + $valaszto.data('sdh-szin-cel'));

        $valaszto.on('input change', function () {
            $mezo.val(this.value);
        });

        $mezo.on('change', function () {
            if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(this.value)) {
                $valaszto.val(this.value.length === 4
                    ? '#' + this.value[1] + this.value[1] + this.value[2] + this.value[2] + this.value[3] + this.value[3]
                    : this.value);
            }
        });
    });
});
