
/* Работа с раздачами */

// скачивание т.-файлов выделенных топиков
function downloadTorrents(replace_passkey) {
    var topic_hashes = $("#topics").serialize();
    if ($.isEmptyObject(topic_hashes)) {
        showResultTopics("Выберите раздачи");
        return false;
    }
    var forum_id = $("#main-subsections").val();
    var config = $("#config").serialize();
    $("#process").text("Скачивание торрент-файлов...");
    $.ajax({
        type: "POST",
        url: "php/actions/get_torrent_files.php",
        data: {
            cfg: config,
            topic_hashes: topic_hashes,
            forum_id: forum_id,
            replace_passkey: replace_passkey
        },
        beforeSend: function () {
            block_actions();
        },
        complete: function () {
            block_actions();
        },
        success: function (response) {
            response = $.parseJSON(response);
            $("#log").append(response.log);
            showResultTopics(response.result);
        },
    });
}

// скачивание т.-файлов хранимых раздач по спискам с форума
function downloadTorrentsByKeepersList(replace_passkey) {
    var forum_id = $("#main-subsections").val();
    var config = $("#config").serialize();
    if ($.isEmptyObject(forum_id)) {
        return false;
    }
    $("#process").text("Получение списка раздач...");
    $.ajax({
        type: "POST",
        url: "php/actions/get_reports_hashes.php",
        data: {
            forum_id: forum_id
        },
        beforeSend: function () {
            block_actions();
        },
        complete: function () {
            block_actions();
        },
        success: function (response) {
            response = $.parseJSON(response);
            $("#log").append(response.log);

            // скачивание т.-файлов
            var topic_hashes = $.param(response.hashes.map( s => ({name:"topic_hashes[]", value:s}) ));
            if ($.isEmptyObject(topic_hashes)) {
                showResultTopics("Не удалось получить список раздач для загрузки");
                return false;
            }
            $("#process").text("Скачивание торрент-файлов...");
            $.ajax({
                type: "POST",
                url: "php/actions/get_torrent_files.php",
                data: {
                    cfg: config,
                    topic_hashes: topic_hashes,
                    forum_id: forum_id,
                    replace_passkey: replace_passkey
                },
                beforeSend: function () {
                    block_actions();
                },
                complete: function () {
                    block_actions();
                },
                success: function (response) {
                    response = $.parseJSON(response);
                    $("#log").append(response.log);
                    showResultTopics(response.result);
                },
            });
        },
    });
}

// задержка при выборе свойств фильтра
let filter_delay = makeDelay(600);

// подавление срабатывания фильтрации раздач
let filter_hold = false;

// получение отфильтрованных раздач из базы
function getFilteredTopics() {
    // Ставим в "очередь" поиск раздач при выполнении тяжелых запросов.
    if (filter_hold) {
        return filter_delay(getFilteredTopics);
    }

    let forum_id = $("#main-subsections").val();
    $("#excluded_topics_size").parent().hide();
    $("#loading, #process").hide();

    // Ничего не загружать.
    if (forum_id == -999) return;

    // блокировка фильтра
    if (
        forum_id > 0
        || forum_id == -3
        || forum_id == -5
        || forum_id == -6
    ) {
        $(".topics_filter input").removeClass("ui-state-disabled").prop("disabled", false);
        $("#toolbar-control-topics").buttonset("enable");
        $("#filter_avg_seeders_period").spinner("enable");
        $("#filter_rule").spinner("enable");
        $("#filter_rule_from").spinner("enable");
        $("#keepers_filter_rule_from").spinner("enable");
        $("#filter_rule_to").spinner("enable");
        $("#keepers_filter_rule_to").spinner("enable");
        $("#filter_date_release").datepicker("enable");
        if (forum_id == -5) {
            $("#tor_add").button("disable");
            $(".topics_filter input[name^='keeping_priority']").addClass("ui-state-disabled").prop("disabled", true);
        } else {
            $("#tor_add").button("enable");
            $(".topics_filter input[name^='keeping_priority']").removeClass("ui-state-disabled").prop("disabled", false);
        }
        $("#filter_client_id").selectmenu("enable");

        // заблокировать кнопки загрузки по спискам, если не выбран подраздел
        $(".tor_download_by_keepers_list").prop("disabled", forum_id < 0);
        $("#tor_download_options").selectmenu("refresh");
    } else {
        if (forum_id == -2) {
            $("#toolbar-control-topics").buttonset("disable");
            $("#tor_blacklist").button("enable");
        } else {
            $("#toolbar-control-topics").buttonset("enable");
            $("#tor_blacklist").button("disable");
        }
        $(".topics_filter input").addClass("ui-state-disabled").prop("disabled", true);
        $(".topics_filter input.sort").removeClass("ui-state-disabled").prop("disabled", false);
        $("#filter_avg_seeders_period").spinner("disable");
        $("#filter_rule").spinner("disable");
        $("#filter_rule_from").spinner("disable");
        $("#keepers_filter_rule_from").spinner("disable");
        $("#filter_rule_to").spinner("disable");
        $("#keepers_filter_rule_to").spinner("disable");
        $("#filter_date_release").datepicker("disable");
        if (forum_id == -4) {
            $("#filter_avg_seeders_period").spinner("enable");
            $(".tor_remove").button("disable");
        }
        $(".tor_download_by_keepers_list").prop("disabled", true);
        $("#tor_download_options").selectmenu("refresh");
        $("#filter_client_id").val(0).selectmenu("refresh").selectmenu("disable");
    }
    // сериализим параметры фильтра
    var $filter = $("#topics_filter").serialize();
    $("#process").text("Получение данных о раздачах...");
    $.ajax({
        type: "POST",
        url: "php/actions/get_filtered_list_topics.php",
        data: {
            forum_id: forum_id,
            filter: $filter,
        },
        beforeSend: function () {
            filter_hold = true;
            block_actions();
        },
        complete: function () {
            filter_hold = false;
            block_actions();
        },
        success: function (response) {
            response = $.parseJSON(response);
            if (response.log.length) {
                showResultTopics(response.log);
            }
            if (response.topics != null) {
                $("#topics").html(response.topics);
                $("#filtered_topics_count").text(response.count);
                $("#filtered_topics_size").text(convertBytes(response.size));

                $("#excluded_topics_count").text(response.ex_count)
                    .parent().toggle(!!response.ex_count);
                $("#excluded_topics_size").text(convertBytes(response.ex_size));
            }
            showCountSizeSelectedTopics();
        }
    });
}

// вывод на экран кол-во, объём выделенных раздач
function showCountSizeSelectedTopics(count = 0, size = 0.00) {
    $("#topics_count").text(count);
    $("#topics_size").text(convertBytes(size));
}

// получение кол-ва, объёма выделенных раздач
function getCountSizeSelectedTopics() {
    var count = 0;
    var size = 0.00;
    var topics = $("#topics").find(".topic[type=checkbox]:checked");
    if (!$.isEmptyObject(topics)) {
        topics.each(function () {
            var data = this.dataset;
            size += parseInt(data.size);
            count++;
        });
    }
    showCountSizeSelectedTopics(count, size);
}

// действия с выбранными раздачами (старт, стоп, метка, удалить)
function execActionTopics(topic_hashes, tor_clients, action, label, force_start, remove_data) {
    $("#dialog").dialog("close");
    $("#process").text("Управление раздачами...");
    $.ajax({
        type: "POST",
        context: this,
        url: "php/actions/exec_actions_topics.php",
        data: {
            topic_hashes: topic_hashes,
            tor_clients: tor_clients,
            action: action,
            remove_data: remove_data,
            force_start: force_start,
            label: label
        },
        beforeSend: function () {
            block_actions();
        },
        complete: function () {
            block_actions();
        },
        success: function (response) {
            response = $.parseJSON(response);
            $("#log").append(response.log);
            showResultTopics(response.result);
            if (action == 'remove') {
                getFilteredTopics();
            }
        }
    });
}

// распарсить сохранённый набор фильтров на главной
function loadSavedFilterOptions(filter_options) {
    filter_options = $.parseJSON(filter_options);
    $("#topics_filter input[type=radio], #topics_filter input[type=checkbox]").prop("checked", false);
    $.each(filter_options, function (i, option) {
        // пропускаем дату регистрации до
        if (option.name == "filter_date_release") {
            return true;
        }
        if ($(`#topics_filter [name='${option.name}']`).is("select")) {
            $(`#${option.name}`).val(option.value).selectmenu("refresh");
            return true;
        }
        $(`#topics_filter input[name='${option.name}']`).each(function () {
            if (
                $(this).attr("type") == "checkbox"
                || $(this).attr("type") == "radio"
            ) {
                if ($(this).val() == option.value) {
                    $(this).prop("checked", true);
                }
            } else if (this.name == option.name) {
                $(this).val(option.value);
            }
        });
    });
    // FIXME !!!
    if ($("#topics_filter [name=filter_interval]").prop("checked")) {
        $(".filter_rule_interval, .filter_rule_one").toggle(500);
    }
    if ($("input[name=is_keepers]").prop("checked")) {
        $(".keepers_filter_rule_fieldset").show();
    }
}
