/**
 * Инициализация работы элементов UI.
 *
 * @module ModuleNames.JQUERY_WIDGETS
 */

webtlo.register(ModuleNames.JQUERY_WIDGETS, function () {

    // Настройки jQuery UI
    const jqueryUIVersion = '1.12.1';
    const defaultUITheme = 'smoothness';

    // Ищем выбранную тему в куках. Если там нет - в конфиге. Если нигде нет - ставить тему по умолчанию.
    let currentUITheme = Cookies.get('theme');
    if (!currentUITheme) {
        currentUITheme = $('#config_selected_theme').val();
    }
    if (!currentUITheme) {
        currentUITheme = defaultUITheme;
    }

    // Переключатель тем оформления.
    $(`#theme_selector [value=${currentUITheme}]`).prop('selected', true);
    setUITheme();

    $('#theme_selector').selectmenu({
        change: function (event, ui) {
            Cookies.set('theme', ui.item.value);
            currentUITheme = ui.item.value;
            setUITheme();
        }
    });

    // Инициализация вкладок главного меню.
    $('#menutabs').tabs({
        activate: function (event, ui) {
            Cookies.set(
                'selected-tab',
                ui.newTab.index()
            );
        },
        beforeActivate: function(event, ui) {
            // Ловим переход с вкладки "настройки"
            if (ui.oldPanel.prop('id') === 'settings') {
                checkSaveSettings();
            }
        },
        active: Cookies.get('selected-tab'),
    }).addClass('ui-tabs-vertical ui-helper-clearfix').removeClass('ui-widget-content');
    $('#menutabs li.menu').removeClass('ui-corner-top').addClass('ui-corner-left');

    // Инициализация кнопок.
    $('input').addClass('ui-widget-content');
    $('button').button();
    $('input[type=button]').button();

    // Создание диалога, для разных окошек.
    $('#dialog').dialog({
        autoOpen: false,
        width: 500
    });

    function setUITheme(){
        const jqueryUIURL = `https://ajax.googleapis.com/ajax/libs/jqueryui/${jqueryUIVersion}/themes/${currentUITheme}/jquery-ui.css`;
        const jqueryUIStyle = $('<link/>')
            .attr('type', 'text/css')
            .attr('rel', 'stylesheet')
            .attr('href', jqueryUIURL);

        jqueryUIStyle.appendTo('head');
    }

});
