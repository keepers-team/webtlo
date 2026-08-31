
/* Функции для вкладки "Раздачи". */

/**
 * Кнопка. Скачивание торрент-файлов выделенных раздач.
 *
 * @param {number} replace_passkey
 */
function downloadTorrents(replace_passkey) {
    const topic_hashes = $('#topics').serialize();
    if ($.isEmptyObject(topic_hashes)) {
        showResultTopics('Выберите раздачи');

        return;
    }

    const subForumId = +$('#main-subsections').val();
    downloadTorrentFiles(subForumId, topic_hashes, replace_passkey);
}

/**
 * Кнопка. Скачивание торрент-файлов раздач по списку хранимого.
 *
 * @param {number} replace_passkey
 */
function downloadTorrentsByKeepersList(replace_passkey) {
    const subForumId = $('#main-subsections').val();
    if ($.isEmptyObject(subForumId) || subForumId < 0) {
        return;
    }

    processStatus.set('Получение списка раздач...');
    $.ajax({
        type: 'POST',
        url: 'php/get_reports_hashes.php',
        data: {
            forum_id: subForumId
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
            if (response.error) {
                showResultTopics(response.error);
                return;
            }

            // Обрабатываем список хешей раздач.
            const topic_hashes = $.param(response.hashes.map(s => ({name: "topic_hashes[]", value: s})));
            if ($.isEmptyObject(topic_hashes)) {
                showResultTopics('Не удалось получить список раздач для загрузки');

                return;
            }

            downloadTorrentFiles(subForumId, topic_hashes, replace_passkey);
        },
    });
}

/**
 * Скачивание торрент-файлов по списку хешей.
 *
 * @param {number} subForumId
 * @param {string} topic_hashes @TODO string[]
 * @param {number} replace_passkey
 */
function downloadTorrentFiles(subForumId, topic_hashes, replace_passkey) {
    processStatus.set('Скачивание торрент-файлов...');

    const config = $('#config').serialize();

    $.ajax({
        type: 'POST',
        url: 'php/get_torrent_files.php',
        data: {
            cfg: config,
            topic_hashes: topic_hashes,
            forum_id: subForumId,
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
            addDefaultLog(response.log ?? '');
            showResultTopics(response.result);
        },
    });
}

// задержка при выборе свойств фильтра
let filter_delay = makeDelay(1500);

// подавление срабатывания фильтрации раздач
let filter_hold = false;


/**
 * Получение отфильтрованных раздач из базы.
 *
 * @requires widgets, report
 */
function getFilteredTopics() {
    // Ставим в "очередь" поиск раздач при выполнении тяжелых запросов.
    if (filter_hold) {
        return filter_delay(getFilteredTopics, this);
    }

    const filterStart = performance.now();

    const forum_id = +$("#main-subsections").val();
    $("#excluded_topics_size").parent().hide();

    // Ничего не загружать.
    if (forum_id === -999) return;

    // Блокировка/разблокировка элементов после смены выбранного разворота.
    blockTopicsFilters(forum_id);

    // Параметры фильтра в строку.
    const $filter = $("#topics_filter").serialize();
    processStatus.set('Получение данных о раздачах...');

    $.ajax({
        type: 'POST',
        url: 'php/get_filtered_list_topics.php',
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

            // Блокировка/разблокировка элементов строго после разблокировки прочих кнопок.
            blockTopicsFilters(forum_id);

            $('#load_error').html('');

            // Допишем время выполнения.
            const timeTaken = ((performance.now() - filterStart) / 1000).toFixed(1);
            $('#topics_timer').html(`[${timeTaken}s]`);
        },
        success: function (response) {
            response = $.parseJSON(response);

            // Если есть ошибка - выводим её текст.
            if (response.result.length) {
                // Если указан элемент, вызывающий ошибку - покажем его.
                if (response.validate) {
                    $(`.${response.validate}`).highlight();
                }

                // Выводим сообщение, если есть что.
                showResultTopics(response.result);
            }

            // Если есть список раздач для отображения - показываем.
            if (response.topics != null) {
                $("#topics").html(response.topics);
                $("#filtered_topics_count").text(response.topics_count);
                $("#filtered_topics_size").text(convertBytes(response.topics_size));

                $("#excluded_topics_count").text(response.excluded_count)
                    .parent().toggle(!!response.excluded_count);
                $("#excluded_topics_size").text(convertBytes(response.excluded_size));
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

// получение кол-ва, объёма выделенных раздач
function refreshCountSizeSelectedTopics() {
    let count = 0;
    let size = 0.00;

    $('#topics .topic[type=checkbox]:checked').each(function () {
        size += Number(this.dataset.size) || 0;
        count++;
    });

    showCountSizeSelectedTopics(count, size);
}

// вывод на экран кол-во, объём выделенных раздач
function showCountSizeSelectedTopics(count = 0, size = 0.00) {
    $('#topics_count').text(count);
    $('#topics_size').text(convertBytes(size));
}

// действия с выбранными раздачами (старт, стоп, метка, удалить)
function execActionTopics(params) {
    processStatus.set('Управление раздачами...');

    $.ajax({
        type: 'POST',
        context: this,
        url: 'php/exec_actions_topics.php',
        data: JSON.stringify(params),
        beforeSend: function () {
            block_actions();
        },
        complete: function () {
            block_actions();
        },
        success: function(response) {
            response = $.parseJSON(response);

            addDefaultLog(response.log ?? '');
            showResultTopics(response.result);

            // После удаления раздач, перезагрузим список.
            if (params.action === 'remove') {
                getFilteredTopics();
            }
        }
    });
}

// Обработать сохранённый набор фильтров вкладки "Раздачи".
function loadSavedFilterOptions(filter_options) {
    filter_options = $.parseJSON(filter_options);

    $('#topics_filter input[type=radio], #topics_filter input[type=checkbox]').prop('checked', false);
    $.each(filter_options, function (i, option) {
        // Пропускаем "дату регистрации до".
        if (option.name === 'filter_date_release') {
            return true;
        }

        if ($(`#topics_filter [name='${option.name}']`).is("select")) {
            $(`#${option.name}`).val(option.value).selectmenu("refresh");
            return true;
        }

        $(`#topics_filter input[name='${option.name}']`).each(function () {
            if (
                $(this).attr("type") === "checkbox"
                || $(this).attr("type") === "radio"
            ) {
                if ($(this).val() === option.value) {
                    $(this).prop("checked", true);
                }
            } else if (this.name === option.name) {
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
