/**
 * Кнопки на панелях.
 *
 * @module ModuleNames.COMMON_BUTTONS
 * @requires.ModuleNames.JQUERY_METHODS
 * @requires.ModuleNames.JQUERY_WIDGETS
 */

webtlo.register(ModuleNames.BUTTONS_ACTIONS, function() {

    // обновление сведений о раздачах
    $('#update_info').on('click', function () {
        const button = $(this);

        const update_info_local = function () {
            $.ajax({
                type: 'GET',
                url: 'php/update_info.php',
                data: {
                    process: button.val() || 'all',
                },
                beforeSend: function () {
                    filter_hold = true;
                    block_actions();
                    processStatus.set(button.prop('title') + '...');

                    clearLoadResult();
                },
                success: function (response) {
                    filter_hold = false;
                    response = $.parseJSON(response);
                    addDefaultLog(response.log ?? '');

                    if (response.result) {
                        // Если есть текстовый результат, то это ошибка. Показываем её.
                        showResultTopics(response.result);
                    } else {
                        // Ошибки обновления нет, применяем фильтры.
                        getFilteredTopics();
                    }

                },
                complete: function () {
                    filter_hold = false;
                    block_actions();
                },
            });
        }

        // Выполняем обновление сведений.
        update_info_local();
    });

    // отправка отчётов
    $('button.send_reports').on('click', function (evt) {
        let buttons = $('button.send_reports').toggleDisable(true);
        let icon = buttons.find('i.fa').toggleClass('fa-paper-plane-o fa-spinner');
        $.ajax({
            type: 'POST',
            url: 'php/send_reports.php',
            contentType: 'application/json',
            data: JSON.stringify({
                cleanOverride : evt.ctrlKey
            }),
            beforeSend: function () {
                block_actions();
                processStatus.set('Отправка отчётов хранимого...');
            },
            success: function (response) {
                response = $.parseJSON(response);
                addDefaultLog(response.log ?? '');
                showResultTopics(response.result);
            },
            complete: function () {
                block_actions();
                buttons.toggleDisable(false);
                icon.toggleClass('fa-paper-plane-o fa-spinner');
            },
        });
    });

    // Регулировка раздач в торрент-клиентах.
    $('#control_torrents').on('click', function () {
        $.ajax({
            type: 'POST',
            url: 'php/control_torrents.php',
            beforeSend: () => {
                showResultTopics();
                block_actions();
                processStatus.set('Регулировка раздач...');
            },
            success: response => {
                response = $.parseJSON(response);

                addDefaultLog(response.log ?? '');
                showResultTopics(response.result);
            },
            complete: () => {
                block_actions();
            },
        });
    });

    // получение статистики
    $('#get_statistics').on('click', function () {
        $.ajax({
            context: this,
            type: 'POST',
            url: 'php/get_statistics.php',
            beforeSend: function () {
                $(this).toggleDisable(true);
            },
            success: function (response) {
                response = $.parseJSON(response);

                const tab = $('#table_statistics');
                tab.find('tbody').html(response.tbody);
                tab.find('tfoot').html(response.tfoot);

                // Добавляем возможность открывать ссылку на подраздел.
                tab.find('td:first-child')
                    .addClass('statistic-forum-id')
                    .prop('title', 'Открыть ссылку на подраздел')
                    .click(function(e) {
                        e.preventDefault();

                        openSubForumLink(this.innerText)
                    });
            },
            complete: function () {
                $(this).toggleDisable(false);
            }
        });
    });


}, [
    ModuleNames.JQUERY_METHODS,
    ModuleNames.JQUERY_WIDGETS,
    // forum.func
]);
