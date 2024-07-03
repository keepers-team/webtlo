
/* Инициализация работы с раздачами */

$(document).ready(function () {

    const topicsForm = $('#topics');

    $(".tor_download").on("click", function () {
        downloadTorrents($(this).val());
    });

    // "чёрный" список раздач
    $("#tor_blacklist").on("click", function () {
        var topic_hashes = $("#topics").serialize();
        if ($.isEmptyObject(topic_hashes)) {
            showResultTopics("Выберите раздачи");
            return false;
        }
        var forum_id = $("#main-subsections").val();
        var value = forum_id != -2 ? 1 : 0;
        processStatus.set('Редактирование "чёрного списка" раздач...');
        $.ajax({
            type: "POST",
            url: "php/actions/exclude_topics.php",
            data: {
                topic_hashes: topic_hashes,
                value: value
            },
            beforeSend: function () {
                block_actions();
            },
            complete: function () {
                block_actions();
            },
            success: function (response) {
                showResultTopics(response);
                getFilteredTopics();
            }
        });
    });

    // добавление раздач в торрент-клиент
    $("#tor_add").on("click", function () {
        var topic_hashes = $("#topics").serialize();
        if ($.isEmptyObject(topic_hashes)) {
            showResultTopics("Выберите раздачи");
            return false;
        }
        processStatus.set("Добавление раздач в торрент-клиент...");
        $.ajax({
            type: "POST",
            url: "php/actions/add_topics_to_client.php",
            data: {
                topic_hashes: topic_hashes
            },
            beforeSend: function () {
                block_actions();
            },
            complete: function () {
                block_actions();
            },
            success: function (response) {
                response = $.parseJSON(response);
                addDefaultLog(response.log ?? '');
                getFilteredTopics();
                showResultTopics(response.result);
            }
        });
    });

    // управление раздачами (старт, стоп и т.п.)
    $(".torrent_action").on("click", function (e) {
        var topic_hashes = $("#topics").serialize();
        if ($.isEmptyObject(topic_hashes)) {
            showResultTopics("Выберите раздачи");
            return false;
        }
        var tor_clients = getListTorrentClients();
        if ($.isEmptyObject(tor_clients)) {
            showResultTopics("В настройках не найдены торрент-клиенты");
            return false;
        }
        var action = $(this).val();
        var subsection = $("#main-subsections").val();
        var label = "";
        var remove_data = "";
        var force_start = "";
        if (subsection > 0) {
            var forumData = $("#list-forums [value=" + subsection + "]").data();
            label = forumData.label;
        }
        if (action == "remove") {
            $("#dialog").dialog(
                {
                    buttons: [
                        {
                            text: "Да",
                            click: function () {
                                remove_data = true;
                                execActionTopics(
                                    topic_hashes,
                                    tor_clients,
                                    action,
                                    label,
                                    force_start,
                                    remove_data
                                );
                            }
                        },
                        {
                            text: "Нет",
                            click: function () {
                                execActionTopics(
                                    topic_hashes,
                                    tor_clients,
                                    action,
                                    label,
                                    force_start,
                                    remove_data
                                );
                            }
                        }
                    ],
                    modal: true,
                    resizable: false,
                    // position: [ 'center', 200 ]
                }
            ).text('Удалить загруженные файлы раздач с диска ?');
            $("#dialog").dialog("open");
            return true;
        }
        if (
            action == "set_label"
            && (
                e.ctrlKey
                || subsection == 0
                || subsection == -4
                || subsection == -5
            )
        ) {
            $("#dialog").dialog(
                {
                    buttons: [
                        {
                            text: "ОК",
                            click: function () {
                                label = $("#any_label").val();
                                execActionTopics(
                                    topic_hashes,
                                    tor_clients,
                                    action,
                                    label,
                                    force_start,
                                    remove_data
                                );
                            }
                        }
                    ],
                    modal: true,
                    resizable: false,
                    // position: [ 'center', 200 ]
                }
            ).html('<label>Установить метку: <input id="any_label" size="27" />');
            $("#dialog").dialog("open");
            return true;
        }
        execActionTopics(
            topic_hashes,
            tor_clients,
            action,
            label,
            force_start,
            remove_data
        );
    });

    // кнопка выделить все / отменить выделение
    $(".tor_select").on("click", function () {
        var value = $(this).val();
        $("#topics").find(".topic[type=checkbox]").prop("checked", Boolean(value));
        getCountSizeSelectedTopics();
    });

    // Изменение выбранных статусов хранения раздачи.
    const inputClientStatus = $('input[name="filter_client_status[]"]');
    inputClientStatus.change(function() {
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
    });

    // выделение/снятие выделения интервала раздач
    $("#topics").on("click", ".topic", function (event) {
        var $checkboxes = $("#topics .topic");
        if ($checkboxes.hasClass("last-checked")) {
            if (event.shiftKey) {
                var $lastChecked = $("#topics .last-checked");
                var startIndex = $checkboxes.index(this);
                var endIndex = $checkboxes.index($lastChecked);
                $checkboxes.slice(Math.min(startIndex, endIndex), Math.max(startIndex, endIndex) + 1).prop("checked", $lastChecked[0].checked);
            }
            $checkboxes.removeClass("last-checked");
        }
        $(this).addClass("last-checked");
        getCountSizeSelectedTopics();
    });

    // снять выделение всех раздач по Esc
    $("#topics").on("keyup", function (event) {
        if (event.which == 27) {
            $("#topics .topic").prop("checked", false).removeClass("last-checked");
            getCountSizeSelectedTopics();
        }
    });

    // скрыть/показать фильтр
    $("#filter_show").on("click", function () {
        $("#topics_filter").toggle(500, function () {
            Cookies.set('filter-state', $(this).is(':visible'));
        });
    });

    // сбросить настройки фильтра
    $("#filter_reset").on("click", function (e) {
        let topic_filter = $("#topics_filter");

        if (e.ctrlKey) {
            const filter_options = Cookies.get("filter-backup");
            if (typeof filter_options !== "undefined") {
                loadSavedFilterOptions(filter_options);

                topic_filter.change();
            }
            return;
        }

        Cookies.set("filter-backup", topic_filter.serializeAllArray());

        $("#topics_filter input[type=text]").val("");
        $("#topics_filter input[type=search]").val("");
        $("#topics_filter input[type=radio], #topics_filter input[type=checkbox]").prop("checked", false);
        $("#filter_date_release").datepicker("setDate", "-" + $("#rule_date_release").val());
        $("#filter_rule, #filter_rule_max").val($("#rule_topics").val());
        $("#filter_rule_min").val(0);
        $("#keepers_filter_count_min").val(1);
        $("#keepers_filter_count_max").val(10);
        $("#filter_avg_seeders_period").val($("#avg_seeders_period").val());
        $(".filter_rule_interval").hide();
        $(".keepers_filter_rule_fieldset").hide();
        $(".filter_rule_one").show();
        $("#filter_client_id").val(0).selectmenu("refresh");

        $("#topics_filter .default").prop("checked", true).change();

        // Обновить выбранные статусы хранения раздач.
        $('.filter_status_controlgroup').controlgroup('refresh');
    });

    // вкл/выкл интервал сидов
    $("input[name=filter_interval]").on("change", function () {
        $(".filter_rule_interval").toggle(500);
        $(".filter_rule_one").toggle(500);
    });

    // вкл/выкл интервал хранителей
    $("input[name=is_keepers]").on("change", function () {
        if ($(this).prop("checked")) {
            $(".keepers_filter_rule_fieldset").toggle(200);
        } else {
            $(".keepers_filter_rule_fieldset").hide(200);
        }
    });

    let topicsFilter = $('#topics_filter');
    let lastUsedFilter = '';

    topicsFilter.on('change input selectmenuchange spinstop', function (e) {
        e.preventDefault();

        // Текущий отсортированный набор фильтров.
        const currentFilter = topicsFilter.serializeAllArray().toSorted();
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
    });

    topicsForm.on('mousedown', '.keeper', function (e) {
        if (e.altKey || e.which === 2) {
            e.preventDefault();
            openUserProfile($(this).text());
        }
    });

    // ник хранителя в поиск при двойном клике
    topicsForm.on('dblclick', '.keeper', function (e) {
        const keeperName = $(this).text();
        const searchBox = $('input[name=filter_phrase]');
        const prevSearch = searchBox.val();

        // Собираем желаемый список поисковых значений.
        let values = prevSearch ? prevSearch.split(',') : [];
        if (e.ctrlKey) {
            values.push(keeperName);
        } else {
            values = [keeperName];
        }

        // Убираем повторы.
        const newSearch = $.uniqueValues(values).join(',');
        if (newSearch === prevSearch) {
            return;
        }

        // Применяем поиск.
        searchBox.val(newSearch);

        selectBlockText(this);
        $('input[name=filter_by_phrase][type="radio"]').prop('checked', false);
        $('#filter_by_keeper').prop('checked', true);

        $('#topics_filter').trigger('change');
    });

    // клиент в поиск при двойном клике
    $("#topics").on("dblclick", ".client", function (e) {
        var torrentClientName = $(this).text();
        var torrentClientID = $(`#list-torrent-clients li:contains('${torrentClientName}')`).val();
        $("#filter_client_id").val(torrentClientID).selectmenu("refresh");

        $("#topics_filter").trigger("change");
    });

    // очистка topics_result при изменениях на странице
    $("#topics_data").on("change input spin", showResultTopics);

    // загрузка параметров фильтра из кук
    var filter_state = Cookies.get("filter-state");
    var filter_options = Cookies.get("filter-options");
    if (filter_state === "false") {
        $("#topics_filter").hide();
    }
    if (typeof filter_options !== "undefined") {
        loadSavedFilterOptions(filter_options);
    }

    // Проверяем наличие раздач, которым нужно обновить названия.
    checkEmptyTitleTopics();

    // отобразим раздачи на главной
    getFilteredTopics();

    // проверим наличие новой версии
    checkNewVersion();
});
