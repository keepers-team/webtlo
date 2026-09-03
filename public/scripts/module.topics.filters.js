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
    const inputClientStatus = $('input[name="filter_client_status[]"]');
    inputClientStatus.on('change', function() {
        let filterClient = $('#filter_client_id');

        // Если выбран "любой" клиент, то делать ничего не нужно.
        if (0 === +filterClient.val()) {
            return false;
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

        return true;
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
    $('#rule_topics, .filter_rule input[type=text]').spinner({
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


    let lastUsedFilter = '';

    $topicsFilter.on('change input selectmenuchange spinstop', function(e) {
        e.preventDefault();

        // Текущий отсортированный набор фильтров.
        const currentFilter = $topicsFilter.serializeAllArray().toSorted();
        const currentFilterString = JSON.stringify(currentFilter);

        // Если прошлый набор фильтров идентичен текущему - ничего не делаем.
        if (lastUsedFilter === currentFilterString) {
            return false;
        }

        // Запоминаем параметры фильтра в куки.
        lastUsedFilter = currentFilterString;
        Cookies.set('filter-options', currentFilter);

        if ($('#enable_auto_apply_filter').prop('checked')) {
            filter_delay(getFilteredTopics, window);
        }

        return true;
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
