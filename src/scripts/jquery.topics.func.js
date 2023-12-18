
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
    processStatus.set("Скачивание торрент-файлов...");
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
    processStatus.set("Получение списка раздач...");
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
            processStatus.set("Скачивание торрент-файлов...");
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

    const forum_id = +$("#main-subsections").val();
    $("#excluded_topics_size").parent().hide();

    // Ничего не загружать.
    if (forum_id === -999) return;

    // Блокировка/разблокировка элементов после смены выбранного разворота.
    blockTopicsFilters(forum_id);

    // Параметры фильтра в строку.
    let $filter = $("#topics_filter").serialize();
    processStatus.set("Получение данных о раздачах...");
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

            // Очистка прошлого результата.
            showResultTopics();
        },
        complete: function () {
            filter_hold = false;
            block_actions();

            // Блокировка/разблокировка элементов строго после разблокировки прочих кнопок.
            blockTopicsFilters(forum_id);
        },
        success: function (response) {
            response = $.parseJSON(response);
            // Если есть ошибка - выводим её текст.
            if (response.log.length) {
                showResultTopics(response.log);

                // Если указан элемент, вызывающий ошибку - покажем его.
                if (response.validate) {
                    $(`.${response.validate}`).highlight();
                }
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

/**
 * Изменить набор доступных к работе элементов.
 * @param {number} forum_id
 */
function blockTopicsFilters(forum_id) {
    //  0 - из других подразделов
    // -1 - незарегистрированные
    // -2 - черный список
    // -3 - все хранимые
    // -4 - дублирующиеся раздачи
    // -5 - высокоприоритетные раздачи
    // -6 - раздачи своим по спискам

    if (
        forum_id > 0
        || forum_id === -3
        || forum_id === -5
        || forum_id === -6
    ) {
        // Разблокировать.

        // Разблокируем все input.
        $('.topics_filter input').toggleDisable(false);

        $('#toolbar-control-topics').buttonset('enable');

        // Разблокируем элементы прокрутки.
        $('#filter_rule') // фильтр по сидам
            .add('#filter_rule_min, #filter_rule_max') // интервал сидов
            .add('#filter_avg_seeders_period') // средние сиды
            .add('.keepers_filter_count') // интервал хранителей
            .spinner('enable');

        // Разблокируем выбор даты регистрации.
        $('#filter_date_release').datepicker('enable');

        // Для высокого приоритета, блокируем добавление раздач и фильтр по приоритету.
        if (forum_id === -5) {
            $('#tor_add').button('disable');
            $(".topics_filter input[name^='keeping_priority']").toggleDisable(true);
        }

        // Фильтр по клиенту разблокируем.
        $('#filter_client_id').selectmenu('enable');
        // Фильтр по статусу хранения.
        $('.filter_status_controlgroup').controlgroup('enable')
    } else {
        // Заблокировать.

        if (forum_id === -2) {
            $("#toolbar-control-topics").buttonset("disable");
            $("#tor_blacklist").button("enable");
        } else {
            $("#toolbar-control-topics").buttonset("enable");
            $("#tor_blacklist").button("disable");
        }

        // Блокируем все input, за исключением сортировки.
        $('.topics_filter input').not('.topics_filter input.sort')
            .toggleDisable(true);

        // Блокируем элементы прокрутки.
        $('#filter_rule') // фильтр по сидам
            .add('#filter_rule_min, #filter_rule_max') // интервал сидов
            .add('#filter_avg_seeders_period') // средние сиды
            .add('.keepers_filter_count') // интервал хранителей
            .spinner('disable');

        // Блокируем выбор даты регистрации.
        $('#filter_date_release').datepicker('disable');

        // Фильтр по клиенту установим в состояние по-умолчанию и заблокируем.
        $('#filter_client_id').val(0).selectmenu('refresh').selectmenu('disable');
        // Фильтр по статусу хранения.
        $('.filter_status_controlgroup').controlgroup('disable')

        // Для дублирующихся раздач, разблокируем фильтр средних сидов.
        if (forum_id === -4) {
            $('.tor_remove').toggleDisable(true);
            $('#filter_avg_seeders_period')
                .toggleDisable(false)
                .spinner('enable');
        }
    }

    // Блокируем кнопки загрузки по спискам, если не выбран подраздел.
    $('.tor_download_by_keepers_list').prop('disabled', forum_id < 0);
    $('#tor_download_options').selectmenu('refresh');
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
    processStatus.set("Управление раздачами...");
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

    // Обновить выбранные статусы хранения раздач.
    $('.filter_status_controlgroup').controlgroup('refresh');
}

/** Проверка наличия раздач без названия + отображение. */
let refreshTopics = {
    interval: null,
    updateInProgress: false,
    checkInProgress: false,
};
function checkEmptyTitleTopics(manualUpdate = false) {
    if (!manualUpdate && refreshTopics.checkInProgress) return;
    const bar = $('.process-bar');

    $.ajax({
        url: 'php/actions/count_topics.php',
        beforeSend: () => refreshTopics.checkInProgress = true,
        complete: () => refreshTopics.checkInProgress = false,
        success: function (response) {
            response = $.parseJSON(response);

            if (response.log) {
                $('#log').append(response.log);
            }

            // Обновлять нечего, скрываем и обнуляем прогрессбар, очищаем интервал.
            if (!response.unnamed) {
                clearRefreshTopicsInterval();
                return;
            }

            // Создаём интервал, если нужно следить за процессом обновления имён.
            if (!refreshTopics.interval) {
                refreshTopics.interval = setInterval(checkEmptyTitleTopics, 15000);
            }

            // Ручной запуск обновления имён.
            if (manualUpdate) {
                updateEmptyTitleTopics();
            }

            // Задаём максимум прогрессбара, если его нет.
            const optionMax = bar.progressbar('option', 'max');
            if (!optionMax) {
                bar.show().progressbar('option', 'max', response.total);
            }
            // Обновляем значение в прогрессаре.
            bar.progressbar('value', response.current);

            showResultTopics(`Обновляем имена раздач: [${response.current}/${response.total}]`);
        },
    });
}

/** Ручной запуск обновления дополнительных сведений о раздачах. */
function updateEmptyTitleTopics() {
    if (refreshTopics.updateInProgress) return;

    processStatus.set('Обновляем имена раздач...');
    $.ajax({
        url: 'php/actions/update_topics_details.php',
        beforeSend: () => refreshTopics.updateInProgress = true,
        success: function (response) {
            response = $.parseJSON(response);

            if (response.log) {
                $('#log').append(response.log);
            }
        },
        complete: function () {
            refreshTopics.updateInProgress = false;
            clearRefreshTopicsInterval();

            processStatus.hide();
        },
    });
}

/** Очистка интервала и обнуление статус бара. */
function clearRefreshTopicsInterval() {
    clearInterval(refreshTopics.interval);
    refreshTopics.interval = null;

    $('.process-bar').progressbar('option', 'max', 0);
}