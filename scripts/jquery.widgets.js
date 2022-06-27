$(document).ready(function () {

    // настройки jQuery UI
    jqueryUIVersion = "1.12.1";
    defaultUITheme = "smoothness";
    currentUITheme = Cookies.get('theme');
    if (currentUITheme === undefined) {
        currentUITheme = defaultUITheme;
    }
    $("#theme-selector [value=" + currentUITheme + "]").prop("selected", true);
    setUITheme();

    $("select").selectmenu();
    $("#list-forums").selectmenu("option", "width", "auto").selectmenu("menuWidget").addClass("menu-overflow");
    $("input").addClass("ui-widget-content");

    // переключатель тем оформления
    $("#theme-selector").selectmenu({
        change: function (event, ui) {
            Cookies.set('theme', ui.item.value);
            currentUITheme = ui.item.value;
            setUITheme();
        }
    });

    // инициализация главного меню
    $("#menutabs").tabs({
        activate: function (event, ui) {
            Cookies.set(
                'selected-tab',
                ui.newTab.index()
            );
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
    $("#toolbar-new-torrents").buttonset();
    $("#toolbar-filter-topics").buttonset();
    $("#log_tabs").tabs();

    // фильтрация раздач, количество сидов
    $("#rule_topics, .filter_rule input[type=text]").spinner({
        min: 0,
        step: 0.5,
        mouseWheel: true
    });

    // дата релиза в фильтре
    $("#filter_date_release").datepicker($.datepicker.regional['ru'])
        .datepicker({
            changeMonth: true,
            changeYear: true,
            showOn: "both",
            dateFormat: 'dd.mm.yy',
            maxDate: "now",
        }).datepicker(
            "setDate",
            $("#filter_date_release").val()
        ).css(
            "width", 90
        ).datepicker(
            "refresh"
        );

    // регулировка раздач, количество пиров
    $("#peers").spinner({
        min: 1,
        mouseWheel: true
    });

    // инициализация "аккордиона" для вкладки настройки
    $("div.sub_settings").each(function () {
        $(this).accordion({
            collapsible: true,
            heightStyle: "content"
        });
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
    })
        .selectmenu("menuWidget")
        .addClass("menu-overflow");

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
