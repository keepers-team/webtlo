/**
 * [Журнал] Выбор вкладки журнала для просмотра.
 *
 * @module ModuleNames.JOURNAL
 */

webtlo.register(ModuleNames.JOURNAL,function () {

    // Инициализируем Tabs Widget.
    const $logTabs = $('#log_tabs').tabs();

    // Чтение журнала из файла.
    $logTabs.on('tabsactivate', function (event, ui) {
        // current tab
        const element_new = $(ui.newTab).children('a');
        const name_new = $(element_new).text();

        if (!element_new.hasClass('log_file')) {
            return false;
        }

        // previous tab
        const element_old = $(ui.oldTab).children('a');
        if (element_old.hasClass('log_file')) {
            $(`#log_${$(element_old).text()}`).text('');
        }

        getLogContent(name_new);

        return true;
    });

    // Очистка журнала.
    $('#clear_log').on('click', function () {
        // active log tab
        const log_file = $('#log_tabs .ui-tabs-panel:visible').prop('id').replace(/log_?/, '');
        if (!log_file) {
            $('#log').text('');

            return;
        }

        // request
        $.ajax({
            type: 'POST',
            url: 'php/clear_log_content.php',
            data: {
                log_file: log_file
            },
            success: function () {
                $(`#log_${log_file}`).text('');
            },
            beforeSend: function () {
                $(`#log_${log_file}`).html(`<i class="fa fa-spinner fa-pulse"></i>`);
            }
        });
    });

    // Обновить содержимое журнала.
    $('#refresh_log').on('click', function () {
        // active log tab
        const log_file = $('#log_tabs .ui-tabs-panel:visible').prop('id').replace(/log_?/, '');
        getLogContent(log_file);
    });


    // === ЛОКАЛЬНЫЕ ФУНКЦИИ ===

    /**
     * Получить содержимое лог-файла.
     *
     * @param {string} log_name
     */
    function getLogContent(log_name) {
        if (!log_name) return;

        // request
        $.ajax({
            type: 'POST',
            url: 'php/get_log_content.php',
            data: {
                log_file: log_name
            },
            success: function (response) {
                if (typeof response !== 'undefined') {
                    $(`#log_${log_name}`).html(response);
                }
            },
            beforeSend: function () {
                $(`#log_${log_name}`).html(`<i class="fa fa-spinner fa-pulse"></i>`);
            }
        });
    }

});
