/**
 * Отображение занятости PHP-FPM независимо от долгих запросов приложения.
 * @module ModuleNames.WORKER_POOL_STATUS
 */
webtlo.register(ModuleNames.WORKER_POOL_STATUS, function () {
    const $status = $('#worker_pool_status');
    const pollInterval = 5000;
    let timer = null;
    let requestInProgress = false;
    let statusNotSupported = false;

    function schedule() {
        if (!document.hidden && !statusNotSupported) {
            timer = setTimeout(refresh, pollInterval);
        }
    }

    function render(data) {
        const active = data.active_processes;
        const idle = data.idle_processes;
        const queued = data.listen_queue;
        const limit = data.max_children;

        if (![active, idle, queued, limit].every(value => Number.isInteger(value) && value >= 0)) {
            showUnavailable();
            return;
        }

        const occupancy = limit > 0 ? `${active}/${limit}` : `${active}`;
        const queueText = queued > 0 ? ` · очередь ${queued}` : '';
        const label = `PHP-FPM: занято ${occupancy}${queueText}`;

        if ($status.text() !== label) $status.text(label);
        $status.attr('title', `Активно: ${active}; свободных запущенных: ${idle}; в очереди: ${queued}`)
            .toggleClass('is-busy', limit > 0 && active >= limit)
            .toggleClass('has-queue', queued > 0)
            .show();
    }

    function showUnavailable() {
        const label = 'PHP-FPM: статус недоступен';
        if ($status.text() !== label) $status.text(label);
        $status.attr('title', 'Не удалось получить состояние пула воркеров')
            .removeClass('is-busy has-queue')
            .show();
    }

    function refresh() {
        if (document.hidden || requestInProgress || statusNotSupported) return;

        requestInProgress = true;
        $.ajax({
            url: 'php/worker_status.php',
            dataType: 'json',
            global: false,
            cache: false,
            timeout: 2500,
        }).done(render).fail(function (xhr) {
            if (xhr.status === 404) {
                statusNotSupported = true;
                $status.hide();
            } else {
                showUnavailable();
            }
        }).always(function () {
            requestInProgress = false;
            schedule();
        });
    }

    document.addEventListener('visibilitychange', function () {
        clearTimeout(timer);
        if (!document.hidden) refresh();
    });

    refresh();
});
