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
    $('#savecfg')
        .on('click', saveSettings)
        .on('change', function () {
            const unsaved = !!+$(this).data('unsaved');
            $(this).toggleClass('ui-state-highlight', unsaved);
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
        $('#savecfg').data('unsaved', true).change();
    });

}, [
    ModuleNames.CONFIG_MAIN,
    ModuleNames.CONFIG_CLIENTS,
    ModuleNames.CONFIG_SUBSECTIONS,
]);
