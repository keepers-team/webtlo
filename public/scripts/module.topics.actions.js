/**
 * [Раздачи] Инициализация панели с кнопками.
 *
 * @module ModuleNames.TOPICS_ACTIONS
 * @requires ModuleNames.TOPICS_FILTERS
 */

webtlo.register(ModuleNames.TOPICS_ACTIONS,function () {

    const $topicsFilter = $('#topics_filter');

    // Кнопка показать/скрыть фильтр раздач.
    $('#filter_show').on('click', function () {
        $topicsFilter.toggle(500, function () {
            Cookies.set('filter-state', $(this).is(':visible'));
        });
    });

    // Кнопка "применить фильтр".
    $('#apply_filter').on('click', function () {
        clearLoadResult();
        getFilteredTopics();
    });

    /**
     * Кнопка сбросить настройки фильтра.
     *
     * @requires ModuleNames.TOPICS_FILTERS
     */
    $('#filter_reset').on('click', function (e) {
        if (e.ctrlKey) {
            const filter_options = Cookies.get('filter-backup');
            if (typeof filter_options !== 'undefined') {
                loadSavedFilterOptions(filter_options);

                $topicsFilter.trigger('change');
            }

            return;
        }

        Cookies.set('filter-backup', $topicsFilter.serializeAllArray());

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

        $("#topics_filter .default").prop("checked", true).trigger('change');

        // Обновить выбранные статусы хранения раздач.
        $('.filter_status_controlgroup').controlgroup('refresh');
    });

    // Кнопки выделить все / отменить выделение.
    $('.tor_select').on('click', function () {
        const doSelectAllTopics = Boolean(+$(this).val());

        $('#topics .topic[type=checkbox]').prop('checked', doSelectAllTopics);

        refreshCountSizeSelectedTopics();
    });

    // Кнопка добавления раздач в торрент-клиент.
    $('#tor_add').on('click', function () {
        const topic_hashes = $("#topics").serialize();
        if ($.isEmptyObject(topic_hashes)) {
            showResultTopics('Выберите раздачи');

            return false;
        }

        processStatus.set('Добавление раздач в торрент-клиент...');
        $.ajax({
            type: 'POST',
            url: 'php/add_topics_to_client.php',
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

    // Кнопка (с вариантами) скачивания торрент-файлов.
    $('.tor_download').on('click', function () {
        downloadTorrents(+$(this).val());
    });

    // Варианты скачивания торрент-файлов.
    $('#tor_download_options').selectmenu({
        classes: {
            'ui-selectmenu-menu': 'ui-menu-update-info',
            'ui-selectmenu-button': 'ui-button-icon-only split-button-select'
        },
        position: {
            my: 'right+12 top', at: 'left+135 bottom', collision: 'flip'
        },
        select: function (event, ui) {
            if (ui.item.element.attr('class') === 'tor_download') {
                downloadTorrents(+ui.item.value);
            } else if (ui.item.element.attr('class') === 'tor_download_by_keepers_list') {
                downloadTorrentsByKeepersList(+ui.item.value);
            }
        }
    });

    // Кнопка добавления/удаления в "чёрный" список раздач.
    $('#tor_blacklist').on('click', function () {
        const topic_hashes = $('#topics').serialize();

        if ($.isEmptyObject(topic_hashes)) {
            showResultTopics('Выберите раздачи');

            return false;
        }

        const listingType = +$("#main-subsections").val();
        const exclude = listingType !== -2 ? 1 : 0;
        processStatus.set('Редактирование "чёрного списка" раздач...');

        $.ajax({
            type: 'POST',
            url: 'php/exclude_topics.php',
            data: {
                topic_hashes: topic_hashes,
                exclude: exclude
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
                getFilteredTopics();
            }
        });

        return true;
    });

    // Кнопки управления раздачами (метка, старт, стоп, удаление).
    $('.torrent_action').on('click', function (e) {
        const topic_hashes = $topicsForm.serialize();
        if ($.isEmptyObject(topic_hashes)) {
            showResultTopics('Выберите раздачи');

            return false;
        }

        const tor_clients = getListTorrentClients();
        if ($.isEmptyObject(tor_clients)) {
            showResultTopics('В настройках не найдены торрент-клиенты');

            return false;
        }

        // Текущий выбранный "разворот раздач"/ подраздел.
        const listingType = +$('#main-subsections').val();

        const action = $(this).val();

        let params = {
            action      : action,
            listingType : listingType,
            label       : null,
            tor_clients : tor_clients,
            sel_client  : $('#filter_client_id').val(),
            topic_hashes: topic_hashes,
            // Принудительный запуск раздач, только qBittorrent, uTorrent и Transmission.
            force_start: (action === 'start' && e.ctrlKey),
            remove_data: false,
        }

        const dialog = $('#dialog');

        // Удаление раздач.
        if (action === 'remove') {
            dialog.html(
                'Удалить загруженные файлы раздач с диска?'
                + '<br><br>'
                + 'Внимание, если в фильтре не выбран клиент, то раздачи будут удалены из всех клиентов!'
            );
            dialog.dialog({
                buttons  : [
                    {
                        text : 'Да',
                        click: function() {
                            params.remove_data = true;

                            dialog.dialog('close');

                            execActionTopics(params);
                        }
                    },
                    {
                        text : 'Нет',
                        click: function() {
                            dialog.dialog('close');

                            execActionTopics(params);
                        }
                    }
                ],
                modal    : true,
                resizable: false,
            });
            dialog.dialog("open");

            return true;
        }

        // Присвоение метки.
        if (action === 'set_label' && e.ctrlKey) {
            dialog.html('<label>Установить метку: <input type="text" id="any_label" size="27" placeholder="<пустая строка>"/></label>');
            dialog.dialog({
                buttons  : [
                    {
                        text : 'ОК',
                        click: function() {
                            params.label = $('#any_label').val();

                            dialog.dialog('close');

                            execActionTopics(params);
                        }
                    }
                ],
                modal    : true,
                resizable: false,
            });
            dialog.dialog("open");

            return true;
        }

        // else (default)
        execActionTopics(params);

        return true;
    });



    // Кнопка "Обновить сведения" и варианты обновления.
    const updateInfoSelect = $('#update_info_select');
    updateInfoSelect.selectmenu({
        classes: {
            'ui-selectmenu-menu': 'ui-menu-update-info',
            'ui-selectmenu-button': 'ui-button-icon-only split-button-select',
        },
        position: {
            my: 'right+12 top', at: 'left bottom', collision: 'flip'
        },
        select: function (event, data) {
            Cookies.set('update-info-select-state', data.item.value);

            $('#update_info')
                .val(data.item.value)
                .prop('title', $(data.item.element).prop('title'))
                .find('span').text(data.item.label);
        }
    });

    const updateInfoOptions = {
        'all': {
            'name': 'Обновить сведения',
            'title': 'Обновление всех сведений из всех источников',
        },
        'loadSubForums': {
            'name': 'Обновить хранимые подразделы',
            'title': 'Обновление списков раздач всех хранимых подразделов',
        },
        'keepers': {
            'name': 'Обновить отчёты хранителей',
            'title': 'Получение отчётов с хранимыми раздачами, других хранителей',
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

    const updateInfoSelectState = Cookies.get('update-info-select-state');
    if (updateInfoSelectState !== undefined) {
        updateInfoSelect.val(updateInfoSelectState).selectmenu('refresh').trigger('change');
    }

},[
    ModuleNames.TOPICS_FILTERS,
]);
