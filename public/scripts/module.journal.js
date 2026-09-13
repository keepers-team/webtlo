/**
 * [Журнал] Выбор вкладки журнала для просмотра.
 *
 * @module ModuleNames.JOURNAL
 */

webtlo.register(ModuleNames.JOURNAL, function () {
    const $logTabs = $('#log_tabs').tabs();
    const $defaultTab = $logTabs.find('a[data-log-file="log-tab"]');

    $logTabs.on('tabsactivate', function (event, ui) {
        ui.oldPanel.empty();
        getLogContent($(ui.newTab).children('a'));
    });

    // После завершения операции перечитываем файл; ответ служит запасным источником.
    $logTabs.on('log-tab:refresh', function (event, fallbackLog) {
        event.preventDefault();
        getLogContent($defaultTab, fallbackLog);
    });

    $('#clear_log').on('click', function () {
        const $tab = getActiveTab();
        const logFile = $tab.data('log-file');
        const $panel = getPanel($tab);

        $.ajax({
            type: 'POST',
            url: 'php/clear_log_content.php',
            data: {log_file: logFile},
            success: function () {
                $panel.empty();
            },
            beforeSend: function () {
                $panel.html('<i class="fa fa-spinner fa-pulse"></i>');
            }
        });
    });

    $('#refresh_log').on('click', function () {
        getLogContent(getActiveTab());
    });

    // Первая вкладка активна сразу после инициализации Tabs Widget.
    getLogContent($defaultTab);

    function getActiveTab() {
        return $logTabs.find('.ui-tabs-active a.log_file');
    }

    function getPanel($tab) {
        return $($tab.attr('href'));
    }

    function getLogContent($tab, fallbackLog = '') {
        const logFile = $tab.data('log-file');
        const $panel = getPanel($tab);

        $.ajax({
            type: 'POST',
            url: 'php/get_log_content.php',
            data: {log_file: logFile},
            success: function (response) {
                $panel.html(response || fallbackLog);
            },
            error: function () {
                if (fallbackLog) {
                    $panel.html(fallbackLog);
                } else {
                    $panel.text('Не удалось прочитать журнал.');
                }
            },
            beforeSend: function () {
                $panel.html('<i class="fa fa-spinner fa-pulse"></i>');
            }
        });
    }
});
