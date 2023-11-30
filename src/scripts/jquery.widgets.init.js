
/* Инициализация работы элементов UI */

// настройки jQuery UI
let jqueryUIVersion = '1.12.1';
let currentUITheme, defaultUITheme = 'smoothness';

$(document).ready(function () {

    // Скрываем прогресс загрузки.
    $(".process-loading, .process-bar").hide();

    $('.process-bar').progressbar({
        max: 0,
        complete : function () {
            $(this).hide();
            showResultTopics();
        }
    });

    // Переключатель тем оформления.
    currentUITheme = Cookies.get('theme') ?? defaultUITheme;
    $(`#theme-selector [value=${currentUITheme}]`).prop('selected', true);
    setUITheme();

    $("#theme-selector").selectmenu({
        change: function (event, ui) {
            Cookies.set('theme', ui.item.value);
            currentUITheme = ui.item.value;
            setUITheme();
        }
    });

    $("select:not(.filter-select-menu)").selectmenu();
    $("#list-forums").selectmenu("option", "width", "auto");
    $("input").addClass("ui-widget-content");

    // инициализация главного меню
    $("#menutabs").tabs({
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
    }).addClass("ui-tabs-vertical ui-helper-clearfix").removeClass("ui-widget-content");
    $("#menutabs li.menu").removeClass("ui-corner-top").addClass("ui-corner-left");

    // период хранения средних сидов
    $("#avg_seeders_period, #filter_avg_seeders_period, #avg_seeders_period_outdated").spinner({
        min: 1,
        max: 30,
        mouseWheel: true
    });

    // дата релиза в настройках
    $("#rule_date_release").spinner({
        min: 0,
        mouseWheel: true
    });

    // инициализация кнопок
    $("button").button();
    $("input[type=button]").button();
    $("#toolbar-select-topics").buttonset();
    $("#toolbar-control-topics").buttonset();
    $("#toolbar-filter-topics").buttonset();

    $("#log_tabs").tabs();

    $("#tor_download_options").selectmenu({
        classes: {
            "ui-selectmenu-button": "ui-button-icon-only split-button-select"
        },
        select: function (event, ui) {
            if (ui.item.element.attr("class") === "tor_download") {
                downloadTorrents(ui.item.value);
            } else if (ui.item.element.attr("class") === "tor_download_by_keepers_list") {
                downloadTorrentsByKeepersList(ui.item.value);
            }
        }
    });

    // Инициализация кнопок статуса хранения.
    $('.filter_status_controlgroup').controlgroup({
        classes: {
            'ui-controlgroup': 'hide-dot lesser-button'
        }
    });

    // Инициализация кнопок с дополнительным меню.
    $("div.control-group").controlgroup();

    // фильтрация раздач, количество сидов
    $("#rule_topics, .filter_rule input[type=text]").spinner({
        min: 0,
        step: 0.5,
        mouseWheel: true
    });

    // фильтрация раздач, количество хранителей
    $(".keepers_filter_count").spinner({
        min: 0,
        max: 20,
        step: 1,
        mouseWheel: true
    }).on('input change', function(){
        if (this.value.match(/[^0-9]/g)) {
            this.value = this.value.replace(/[^0-9]/g, '');
        }
    });

    // Фильтр по дате релиза.
    let releaseDateFilter = $('#filter_date_release').css('width', 90);
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


    // Меню обновления сведений.
    let updateInfoSelect = $("#update_info_select");
    updateInfoSelect.selectmenu({
        classes: {
            "ui-selectmenu-menu": "ui-menu-update-info",
            "ui-selectmenu-button": "ui-button-icon-only split-button-select",
        },
        position: {
            my: "right+12 top", at: "left bottom", collision: "flip"
        },
        select: function (event, data) {
            Cookies.set('update-info-select-state', data.item.value);

            $('#update_info')
                .val(data.item.value)
                .prop('title', $(data.item.element).prop('title'))
                .find('span').text(data.item.label);
        }
    });

    let updateInfoOptions = {
        'all': {
            'name': 'Обновить сведения',
            'title': 'Обновление всех сведений из всех источников',
        },
        'subsections': {
            'name': 'Обновить хранимые подразделы',
            'title': 'Обновление списков раздач всех хранимых подразделов',
        },
        'keepers': {
            'name': 'Обновить списки хранителей',
            'title': 'Обновление списков раздач, хранимых другими хранителями',
        },
        'priority': {
            'name': 'Обновить высокий приоритет',
            'title': 'Обновление списков раздач с высоким приоритетом со всего трекера',
        },
        'clients': {
            'name': 'Обновить клиенты',
            'title': 'Обновление списков раздач в торрент-клиентах',
        },
    };

    updateInfoSelect.empty();
    $.each(updateInfoOptions, function (value, el){
        updateInfoSelect.append(`<option value="${value}" title="${el.title}">${el.name}</option>`);
    });
    updateInfoSelect.selectmenu('refresh');

    let updateInfoSelectState = Cookies.get('update-info-select-state');
    if (updateInfoSelectState !== undefined) {
        updateInfoSelect.val(updateInfoSelectState).selectmenu('refresh').change();
    }

    // регулировка раздач, количество пиров
    $(".spinner-peers").spinner({
        min: -2,
        max: 100,
        mouseWheel: true
    });
    // регулировка раздач, количество хранителей
    $(".spinner-keepers").spinner({
        min: 0,
        max: 10,
        mouseWheel: true
    });

    // Инициализация "аккордеона" для настроек.
    $('div.sub_settings').accordion({
        collapsible: true,
        heightStyle: 'content',
    });

    // выпадающее меню для отчётов
    $("#reports-subsections").selectmenu({
        width: "calc(100% - 36px)",
        change: getReport,
        open: function (event, ui) {
            // выделяем жирным в списке
            var active = $("#reports-subsections-button").attr("aria-activedescendant");
            $("#reports-subsections-menu div[role=option]").css({
                "font-weight": "normal"
            });
            $("#" + active).css({
                "font-weight": "bold"
            });
        },
    });

    // прокрутка подразделов в отчётах
    $("#reports-subsections-button").on("mousewheel", function (event, delta) {
        var hidden = $("#reports-subsections-menu").attr("aria-hidden");
        if (hidden == "false") {
            return false;
        }
        var forum_id = $("#reports-subsections").val();
        var element = $("#reports-subsections [value=" + forum_id + "]").parent().attr("id");
        if (typeof element === "undefined") {
            return false;
        }
        var size = $("#reports-subsections-stored option").size();
        var selected = $("#reports-subsections").prop("selectedIndex");
        selected = selected - delta - 1;
        if (selected == size) {
            selected = 0;
        }
        $("#reports-subsections-stored :eq(" + selected + ")").prop("selected", "selected");
        $("#reports-subsections").selectmenu("refresh");
        forumDataShowDelay(getReport);
        return false;
    });

    // инициализация диалога для установки произвольной метки
    $("#dialog").dialog({
        autoOpen: false,
        width: 500
    });

});
