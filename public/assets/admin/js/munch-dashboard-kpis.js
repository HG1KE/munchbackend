(function ($) {
    function loadSalesKpis() {
        var $root = $('#munch-dash-kpis');
        if (!$root.length) {
            return;
        }

        var timeframe = $('#kpi-timeframe').val() || 'today';
        var payload = {
            branch_id: $('#kpi-branch').val() || 'all',
            timeframe: timeframe
        };

        if (timeframe === 'custom') {
            payload.from = $('#kpi-from').val();
            payload.to = $('#kpi-to').val();
            if (!payload.from || !payload.to) {
                return;
            }
        }

        $root.addClass('is-loading');
        $.get($root.data('url'), payload)
            .done(function (data) {
                $('#kpi-munch-sales').text(data.munch_sales);
                $('#kpi-cash').text(data.cash);
                $('#kpi-card').text(data.card);
                $('#kpi-mpesa').text(data.mpesa);
                $('#kpi-glovo').text(data.glovo);
                $('#kpi-uber').text(data.uber);
                $('#kpi-bolt-food').text(data.bolt_food);
            })
            .always(function () {
                $root.removeClass('is-loading');
            });
    }

    function syncCustomDates() {
        var $root = $('#munch-dash-kpis');
        $root.toggleClass('is-custom', $('#kpi-timeframe').val() === 'custom');
    }

    $(function () {
        if (!$('#munch-dash-kpis').length) {
            return;
        }
        syncCustomDates();
        $('#kpi-branch, #kpi-timeframe, #kpi-from, #kpi-to').on('change', function () {
            syncCustomDates();
            loadSalesKpis();
        });
    });
})(jQuery);
