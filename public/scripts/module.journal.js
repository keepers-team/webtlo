/**
 * [Журнал] Выбор вкладки журнала для просмотра.
 *
 * @module ModuleNames.JOURNAL
 */

webtlo.register(ModuleNames.JOURNAL, function () {

    // Инициализируем Tabs Widget.
    const $logTabs = $('#log_tabs').tabs();

    // Отмечаем вкладку по умолчанию и текущую активную вкладку.
    const $defaultTab = $logTabs.find('a[href="#log_default"]')
    let $activeTab = $defaultTab;

    $logTabs.on('tabsactivate', function (event, ui) {
        ui.oldPanel.empty();

        // Обновляем текущую активную вкладку и загружаем содержимое.
        $activeTab = $(ui.newTab).children('a[data-log-file]');
        loadLog($activeTab);
    });

    // После завершения операции перечитываем файл; ответ служит запасным источником.
    $logTabs.on('logTabRefresh', function (event, fallbackLog) {
        event.preventDefault();
        loadLog($defaultTab, fallbackLog);
    });

    $('#refresh_log').on('click', function () {
        loadLog($activeTab);
    })

    $('#clear_log').on('click', function () {
        clearLog($activeTab);
    });

    // Первая вкладка активна сразу после инициализации Tabs Widget.
    loadLog($defaultTab);

    function getPanel($tab) {
        return $($tab.attr('href'));
    }

    function loadLog($tab, fallbackLog = '') {
        const logFile = $tab.data('log-file');
        if (!logFile) return;

        const $panel = getPanel($tab);

        $.ajax({
            type: 'POST',
            url: 'php/get_log_content.php',
            data: {log_file: logFile},
            dataType: 'text',
            beforeSend: function () {
                showSpinner($panel);
            },
            success: function (response) {
                $panel.html(response || fallbackLog);
            },
            error: function () {
                if (fallbackLog) {
                    $panel.html(fallbackLog);
                } else {
                    $panel.text('Не удалось прочитать журнал.');
                }
            }
        });
    }

    function clearLog($tab) {
        const logFile = $tab.data('log-file');
        if (!logFile) return;

        const $panel = getPanel($tab);

        $.ajax({
            type: 'POST',
            url: 'php/clear_log_content.php',
            data: {log_file: logFile},
            beforeSend: function () {
                showSpinner($panel);
            },
            success: function () {
                $panel.empty();
            },
            error: function () {
                $panel.text('Не удалось очистить журнал.');
            }
        });
    }

    function showSpinner($panel) {
        $panel.html('<i class="fa fa-spinner fa-pulse"></i>');
    }

});
