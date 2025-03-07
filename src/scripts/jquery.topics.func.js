
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
            addDefaultLog(response.log ?? '');
            showResultTopics(response.result);
        },
    });
}

// скачивание т.-файлов хранимых раздач по спискам с форума
function downloadTorrentsByKeepersList(replace_passkey) {
    const forum_id = $('#main-subsections').val();
    if ($.isEmptyObject(forum_id) || forum_id < 0) {
        return false;
    }

    let config = $('#config').serialize();
    processStatus.set('Получение списка раздач...');
    $.ajax({
        type: 'POST',
        url: 'php/actions/get_reports_hashes.php',
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
            addDefaultLog(response.log ?? '');
            if (response.error) {
                showResultTopics(response.error);
                return false;
            }

            // Обрабатываем список хешей раздач.
            let topic_hashes = $.param(response.hashes.map(s => ({name: "topic_hashes[]", value: s})));
            if ($.isEmptyObject(topic_hashes)) {
                showResultTopics('Не удалось получить список раздач для загрузки');
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
                    addDefaultLog(response.log ?? '');
                    showResultTopics(response.result);
                },
            });
        },
    });
}

// задержка при выборе свойств фильтра
let filter_delay = makeDelay(1500);

// подавление срабатывания фильтрации раздач
let filter_hold = false;

/**
 * Текущий выбранный в фильтре подраздел.
 * @returns {number}
 */
function getCurrentSubsection() {
    return +$('#main-subsections').val();
}

/**
 * Метка для раздач, в зависимости от подраздела.
 *
 * @param {number} subsection
 * @returns {string}
 */
function getLabelBySubsection(subsection) {
    if (subsection > 0) {
        const forumData = $(`#list-forums [value=${subsection}]`).data();

        return '' + forumData.label;
    }

    return '';
}

// получение отфильтрованных раздач из базы
function getFilteredTopics() {
    // Ставим в "очередь" поиск раздач при выполнении тяжелых запросов.
    if (filter_hold) {
        return filter_delay(getFilteredTopics);
    }

    const filterStart = performance.now();

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

            $('#load_error').html('');
        },
        success: function (response) {
            let messageResult = '';
            response = $.parseJSON(response);

            // Если есть ошибка - выводим её текст.
            if (response.log.length) {
                messageResult = response.log;

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

            // Допишем время выполнения.
            const timeTaken = ((performance.now() - filterStart) / 1000).toFixed(1);
            messageResult += ` [${timeTaken}s]`;

            // Выводим сообщение, если есть что.
            showResultTopics(messageResult);
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
function getCountSizeSelectedTopics() {
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
        url: 'php/actions/exec_actions_topics.php',
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

/**
 * Открыть профиль пользователя.
 *
 * @param {number|string} user id/name
 */
function openUserProfile(user) {
    if (!user) {
        return;
    }

    const domain = getForumUrl()
    const url = `${domain}/forum/profile.php?mode=viewprofile&u=${user}`;
    window.open(url, '_blank');
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
                addDefaultLog(response.log ?? '');
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
                addDefaultLog(response.log ?? '');
            }
        },
        complete: function () {
            refreshTopics.updateInProgress = false;
            clearRefreshTopicsInterval();

            // Примеряем фильтр поиска после успешного обновления имён раздач.
            getFilteredTopics();

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
