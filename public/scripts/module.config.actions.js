/**
 * [Настройки] Инициализация кнопок и отслеживание изменений.
 *
 * @module ModuleNames.CONFIG_ACTIONS
 * @requires.ModuleNames.CONFIG_MAIN
 * @requires.ModuleNames.CONFIG_CLIENTS
 * @requires.ModuleNames.CONFIG_SUBSECTIONS
 */

webtlo.register(ModuleNames.CONFIG_ACTIONS,function() {

    // Кнопка. Сохранение настроек.
    $('#configSave')
        .on('click', () => {
            saveSettings().then();
        })
        .on('change', function () {
            const unsaved = !!+$(this).data('unsaved');
            $(this).toggleClass('ui-state-highlight', unsaved);
        });

    // Обработчик кнопки экспорта.
    $('#configExport').on('click', function() {
        const config = JSON.stringify(getConfigData(), null, 2);

        if (copyToClipboard(config)) {
            alert('Скопировано в буфер обмена');

            return;
        }

        $('#dialog')
            .html('<textarea style="width:100%;height:300px;">' + config + '</textarea>')
            .dialog({
                title: 'Экспорт конфигурации',
                width: 600,
                modal: true,
                buttons: [
                    {
                        text: 'Закрыть',
                        click: function() {
                            $(this).dialog('close');
                        }
                    }
                ]
            })
            .dialog('open');
    });

    // Обработчик кнопки импорта.
    $('#configImport').on('click', function() {
        $('#dialog')
            .html('<p>Вставьте JSON конфигурации:</p>' +
                '<textarea id="importText" style="width:100%;height:200px;"></textarea>')
            .dialog({
                title: 'Импорт конфигурации',
                width: 600,
                modal: true,
                buttons: [
                    {
                        text: 'Применить',
                        click: async function() {
                            const text = $('#importText').val();

                            try {
                                const saveResult = await saveSettings($.parseJSON(text));

                                if (saveResult) {
                                    alert('Конфигурация применена. Окно будет перезапущено!');
                                    // reload the current page
                                    window.location.reload();
                                } else {
                                    alert('Ошибка сохранения. Обратитесь к журналу.')
                                }
                            } catch (e) {
                                alert('Ошибка парсинга JSON: ' + e.message);
                            }

                            $(this).dialog('close');
                        }
                    },
                    {
                        text: 'Закрыть',
                        click: function() {
                            $(this).dialog('close');
                        }
                    }
                ]
            })
            .dialog('open');
    });

    // Переносим значения радио кнопок из скрытых элементов формы.
    $('#config .radio_from_input').each(function() {
        if (this.value === '') {
            return false;
        }

        $(`input[type=radio][name='${this.id}'][value=${this.value}]`).prop('checked', true);

        return true;
    });

    // Вызываем смену видимости элементов.
    $('#proxy_activate_report, #api_auth_params').trigger('change');

    // Проверяем, что настройки были изменены
    $('form#config :input').not('.ignore-save-change').on('change selectmenuchange spinstop', function () {
        $('#configSave').data('unsaved', true).change();
    });

}, [
    ModuleNames.CONFIG_MAIN,
    ModuleNames.CONFIG_CLIENTS,
    ModuleNames.CONFIG_SUBSECTIONS,
]);
