/**
 * [Раздачи] Инициализация панели с фильтрами.
 *
 * @module ModuleNames.TOPICS_WIDGETS
 * @requires ModuleNames.JQUERY_METHODS
 * @requires ModuleNames.JQUERY_WIDGETS
 */

webtlo.register(ModuleNames.TOPICS_FILTERS, function() {

    // Фильтры поиска раздач.
    const $topicsFilter = $('#topics_filter');

    // Добавляем селекторам меню с прокруткой.
    $topicsFilter.find('select:not(.filter-select-menu)').selectMenuWheel();

    // Инициализация кнопок с дополнительным меню.
    $('div.control-group').controlgroup();

    // Инициализация панелей с кнопками.
    $('#toolbar-select-topics').buttonset();
    $('#toolbar-control-topics').buttonset();
    $('#toolbar-filter-topics').buttonset();

    /**
     * Изменение выбранных статусов хранения раздачи.
     *
     * @requires ModuleNames.JQUERY_WIDGETS
     */
    const inputClientStatus = $('#topics_filter input[name="filter_client_status[]"]');
    inputClientStatus.on('change', function() {
        let filterClient = $('#filter_client_id');

        // Если выбран "любой" клиент, то делать ничего не нужно.
        if (0 === +filterClient.val()) {
            return;
        }

        const checkedValues = $.map(inputClientStatus.filter(':checked'), (el) => el.value);
        if (checkedValues.length === 1 && checkedValues[0] === 'null') {
            // Сбрасываем фильтр по торрент-клиенту.
            filterClient.val(0).selectmenu('refresh');

            // Подсвечиваем элемент с фильтром по торрент-клиенту.
            const instance = filterClient.selectmenu('instance');
            if (instance && instance.button) {
                $(instance.button).highlight();
            }
        }
    });

    // Фильтр "Статус хранения раздачи".
    $('.filter_status_controlgroup').controlgroup({
        classes: {
            'ui-controlgroup': 'hide-dot lesser-button'
        }
    });

    // Фильтр "Период хранения средних сидов".
    $('#filter_avg_seeders_period').spinner({
        min       : 1,
        max       : 30,
        mouseWheel: true
    });

    // Фильтр "дата регистрации до".
    const releaseDateFilter = $('#filter_date_release').css('width', 90);
    releaseDateFilter
        .datepicker($.datepicker.regional['ru'])
        .datepicker({
            changeMonth: true,
            changeYear : true,
            showOn     : 'both',
            dateFormat : 'dd.mm.yy',
            maxDate    : 'now',
        })
        .datepicker('setDate', releaseDateFilter.val())
        .datepicker('refresh');


    // Фильтр "количество хранителей".
    $('#topics_filter .keepers_filter_count').spinner({
        min       : 0,
        max       : 20,
        step      : 1,
        mouseWheel: true
    }).on('input change', function() {
        if (this.value.match(/[^0-9]/g)) {
            this.value = this.value.replace(/[^0-9]/g, '');
        }
    });

    // Фильтр "количество сидов" или интервал.
    $('#rule_topics, .filter_rule_value input[type=text]').spinner({
        min       : 0,
        step      : 0.5,
        mouseWheel: true
    });


    // вкл/выкл интервал сидов
    $('#topics_filter input[name=filter_interval]').on('change filter_init', function(e) {
        e.preventDefault();

        const duration = e.type === 'change' ? 200 : null;
        if ($(this).prop('checked')) {
            $('.filter_rule_one').hide(duration);
            $('.filter_rule_interval').show(duration);
        } else {
            $('.filter_rule_interval').hide(duration);
            $('.filter_rule_one').show(duration);
        }
    });

    // вкл/выкл интервал хранителей
    $('#topics_filter input[name=is_keepers]').on('change filter_init', function(e) {
        e.preventDefault();

        const duration = e.type === 'change' ? 200 : null;
        if ($(this).prop('checked')) {
            $('.keepers_filter_rule_fieldset').show(duration);
        } else {
            $('.keepers_filter_rule_fieldset').hide(duration);
        }
    });


    $topicsFilter.on('change input selectmenuchange spinstop manual_change', function(e) {
        // Пропускаем событие input, если элемент — чекбокс или радио.
        if (e.type === 'input' && ($(e.target).is(':checkbox, :radio'))) {
            return;
        }

        e.preventDefault();

        // Если фильтр не изменился, то не применяем его автоматически.
        if (!checkUsedFilterChange()) {
            return;
        }

        // Если фильтр изменился (автоматически), подсвечиваем кнопки пресетов.
        if (e.type !== 'manual_change') {
            toggleButtonUnsavedState(true);
        }

        // Если включена опция "автоматически применять" - применяем.
        if ($('#enable_auto_apply_filter').prop('checked')) {
            filter_delay(function(){
                clearLoadResult();
                getFilteredTopics();
            }, window);
        }
    });

    // Скрываем прогресс загрузки.
    $('.process-loading, .process-bar').hide();

    // Прогресс бар. Оставлю его тут, может пригодиться позже.
    $('.process-bar').progressbar({
        max     : 0,
        complete: function() {
            $(this).hide();
        }
    });

    // Пресет фильтров.
    $('#preset_select').selectMenuWheel({
        classes: {
            'ui-selectmenu-menu': 'ui-menu-update-info'
        },
        select : (e, ui) => {
            // При выборе нового элемента пресета, подсвечиваем кнопки, которые говорят о том, что его надо применить вручную.
            toggleButtonUnsavedState(ui.item.index > 0);
        }
    });

    // Кнопка показать/скрыть пресеты фильтров.
    $('#preset_toggle').on('click', function () {
        $('#preset_controls').toggle(500, function () {
            Cookies.set('filter-preset-state', $(this).is(':visible'));
        });
    });

    // Кнопки действия для пресетов.
    $('#preset_apply').on('click', applySelectedPreset);
    $('#preset_delete').on('click', deleteSelectedPreset);
    $('#preset_save').on('click', function(e) {
        if (e.ctrlKey || e.metaKey) {
            // Ctrl+Click – открываем расширенный диалог.
            e.preventDefault();
            openPresetDialog();
        } else {
            // Обычный клик – сохранение пресета.
            saveCurrentFilter();
        }
    });


    // Загружаем пресеты.
    loadPresetList();

    // Состояние панели пресетов.
    $('#preset_controls').toggle(Cookies.get('filter-preset-state') === 'true');

    // Загрузка параметров фильтра из cookie
    const filter_state = Cookies.get('filter-state');
    const filter_options = Cookies.get('filter-options');
    if (filter_state === 'false') {
        $topicsFilter.hide();
    }
    if (typeof filter_options !== 'undefined') {
        loadSavedFilterOptions(filter_options);
    }

}, [
    ModuleNames.JQUERY_METHODS,
    ModuleNames.JQUERY_WIDGETS,
]);
