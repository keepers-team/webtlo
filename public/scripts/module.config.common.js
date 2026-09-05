/**
 * [Настройки] Инициализация jQueryUI Widget.
 *
 * @module ModuleNames.CONFIG_WIDGETS
 * @requires ModuleNames.JQUERY_METHODS
 * @requires ModuleNames.JQUERY_WIDGETS
 */

webtlo.register(ModuleNames.CONFIG_COMMON,function() {

    // Показать/скрыть произвольные адреса для форума и api.
    $('#forum_url, #report_url').on('selectmenucreate selectmenuchange', function() {
        const name = $(this).prop('name');

        $(`#${name}_custom`).toggle($(this).val() === 'custom');
    });

    // Инициализация Controlgroup Widget.
    $('#config .config_controlgroup').controlgroup({
        classes: {
            'ui-controlgroup': 'hide-dot ui-padding-02'
        }

    });

    // Инициализация Accordion Widget.
    $('#config div.sub_settings').accordion({
        collapsible: true,
        heightStyle: 'content',
        active: +(Cookies.get('selected-sub-settings') ?? 0),
        activate: function () {
            Cookies.set(
                'selected-sub-settings',
                $(this).accordion('option', 'active')
            );
        },
    });

    /**
     * Вешаем обработчик на прокрутку (Selectmenu Widget).
     *
     * @requires ModuleNames.JQUERY_METHODS
     */
    $('#config select').selectMenuWheel();

}, [
    ModuleNames.JQUERY_METHODS,
    ModuleNames.JQUERY_WIDGETS,
]);
