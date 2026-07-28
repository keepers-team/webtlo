$(document).ready(function () {


    // обновление сведений о раздачах
    $("#update_info").on("click", function () {
        let button = $(this);
        let update_info_local = function () {
            $.ajax({
                type: "GET",
                url: "php/update_info.php",
                data: {
                    process: button.val() || 'all',
                },
                beforeSend: function () {
                    filter_hold = true;
                    block_actions();
                    processStatus.set(button.prop('title') + "...");

                },
                success: function (response) {
                    filter_hold = false;
                    response = $.parseJSON(response);
                    addDefaultLog(response.log ?? '');

                    if (response.result) {
                        // Если есть текстовый результат, то это ошибка. Показываем её.
                        showResultTopics(response.result);
                    } else {
                        // Ошибки обновления нет, применяем фильтры.
                        getFilteredTopics();
                    }

                },
                complete: function () {
                    filter_hold = false;
                    block_actions();
                },
            });
        }

        // Выполняем обновление сведений.
        update_info_local();
    });

    // отправка отчётов
    $("button.send_reports").on("click", function (evt) {
        let buttons = $("button.send_reports").toggleDisable(true);
        let icon = buttons.find("i.fa").toggleClass('fa-paper-plane-o fa-spinner');
        $.ajax({
            type: "POST",
            url: "php/send_reports.php",
            contentType: "application/json",
            data: JSON.stringify({
                cleanOverride : evt.ctrlKey
            }),
            beforeSend: function () {
                block_actions();
                processStatus.set("Отправка отчётов хранимого...");
            },
            success: function (response) {
                response = $.parseJSON(response);
                addDefaultLog(response.log ?? '');
                showResultTopics(response.result);
            },
            complete: function () {
                block_actions();
                buttons.toggleDisable(false);
                icon.toggleClass('fa-paper-plane-o fa-spinner');
            },
        });
    });

    // Регулировка раздач в торрент-клиентах.
    $('#control_torrents').on('click', function () {
        $.ajax({
            type: 'POST',
            url: 'php/control_torrents.php',
            beforeSend: () => {
                showResultTopics();
                block_actions();
                processStatus.set('Регулировка раздач...');
            },
            success: response => {
                response = $.parseJSON(response);

                addDefaultLog(response.log ?? '');
                showResultTopics(response.result);
            },
            complete: () => {
                block_actions();
            },
        });
    });

    // применить параметры фильтра
    $("#apply_filter").on("click", function () {
        getFilteredTopics();
    });

    // сохранение настроек
    $("#savecfg").on("click", setSettings)
        .on("change", function () {
            let unsaved = !!+this.dataset["unsaved"];
            $(this).toggleClass("ui-state-highlight", unsaved);
        });

    // Проверяем, что настройки были изменены
    $("form#config :input").not(".ignore-save-change").on("change selectmenuchange spinstop", function () {
        savecfg.dataset["unsaved"] = 1;
        $("#savecfg").change();
    });

    // произвольные адреса для форума и api
    $('#forum_url, #api_url, #report_url').on("selectmenucreate selectmenuchange", function() {
        const value = $(this).val();
        const name = $(this).attr("name");
        if (value === 'custom') {
            $(`#${name}_custom`).attr("type", "text");
        } else {
            $(`#${name}_custom`).attr("type", "hidden");
        }
    });

    // проверка доступности форума и API
    $('#check_mirrors_access').on('click', function () {
        const $data = $("#config").serialize();

        // Проверяемые адреса.
        const check_list = ['forum', 'api', 'report'];
        const result_list = ['text-danger', 'text-success'];

        let forumButtons = $('#check_mirrors_access').toggleDisable(true);
        let check_count = check_list.length;

        $.each(check_list, function (index, value) {
            let element = `#${value}_url`;
            let url = $(element).val();

            let elemParam = $(`${element}_params i`);

            let lockElems = $(`.check_access_${value}`)
                .add(element)
                .add(`${element}_custom`)
                .toggleDisable(true);

            if (typeof url === "undefined" || $.isEmptyObject(url)) {
                check_count--;
                if (check_count === 0) {
                    forumButtons.toggleDisable(false);
                }

                elemParam.removeAttr('class');
                lockElems.toggleDisable(false);
                return true;
            }

            $.ajax({
                type: 'POST',
                url: 'php/check_mirror_access.php',
                data: {
                    url_type  : value,
                    cfg       : $data,
                    url       : url,
                    url_custom: $(`${element}_custom`).val(),
                    ssl       : $(`#${value}_ssl`).is(':checked'),
                    proxy     : $(`#proxy_activate_${value}`).is(':checked')
                },
                success: function (response) {
                    response = $.parseJSON(response);

                    lockElems.toggleDisable(false);
                    elemParam.removeAttr('class');

                    const result = result_list[response.result];
                    if (typeof result !== 'undefined') {
                        elemParam.addClass(`fa fa-circle ${result}`);
                    }

                    addDefaultLog(response.log ?? '');
                },
                beforeSend: function () {
                    elemParam.removeAttr('class').addClass('fa fa-spinner fa-spin');
                },
                complete: function () {
                    check_count--;
                    if (check_count === 0) {
                        forumButtons.toggleDisable(false);
                    }
                }
            });
        });
    });

    $("#forum_url_params").on("change", function () {
        $("#forum_url_result").removeAttr("class");
    });

    $("#api_url_params").on("change", function () {
        $("#api_url_result").removeAttr("class");
    });

    $("#report_url_params").on("change", function () {
        $("#report_url_result").removeAttr("class");
    });

    // проверка закрывающего слеша
    $("#savedir, #dir_torrents").on("change", function () {
        var e = this;
        var val = $(e).val();
        if ($.isEmptyObject(val)) {
            return false;
        }
        var path = $(e).val();
        var last_s = path.slice(-1);
        if (path.indexOf('/') + 1) {
            if (last_s != '/') {
                new_path = path + '/';
            } else {
                new_path = path;
            }
        } else {
            if (last_s != '\\') {
                new_path = path + '\\';
            } else {
                new_path = path;
            }
        }
        $(e).val(new_path);
    });

    // Обновить отчёт.
    $("#get_reports").on("click", function () {
        getReport();
    })

    // получение статистики
    $('#get_statistics').on('click', function () {
        $.ajax({
            context: this,
            type: 'POST',
            url: 'php/get_statistics.php',
            beforeSend: function () {
                $(this).toggleDisable(true);
            },
            success: function (response) {
                response = $.parseJSON(response);

                const tab = $('#table_statistics');
                tab.find('tbody').html(response.tbody);
                tab.find('tfoot').html(response.tfoot);

                // Добавляем возможность открывать ссылку на подраздел.
                tab.find('td:first-child')
                    .addClass('statistic-forum-id')
                    .prop('title', 'Открыть ссылку на подраздел')
                    .click(function(e) {
                        e.preventDefault();

                        const domain = getForumUrl()
                        const url = `${domain}/forum/viewforum.php?f=${this.innerText}`;
                        window.open(url, '_blank');
                    });
            },
            complete: function () {
                $(this).toggleDisable(false);
            }
        });
    });

    // очистка лога
    $("#clear_log").on("click", function () {
        // active log tab
        let log_file = $("#log_tabs .ui-tabs-panel:visible").prop("id").replace(/log_?/, '');
        if (!log_file) {
            $("#log").text("");
            return;
        }

        // request
        $.ajax({
            type: "POST",
            url: "php/clear_log_content.php",
            data: {
                log_file: log_file
            },
            success: function (response) {
                $("#log_" + log_file).text("");
            },
            beforeSend: function () {
                $("#log_" + log_file).html("<i class=\"fa fa-spinner fa-pulse\"></i>");
            }
        });
    });

    // чтение лога из файла
    $("#log_tabs").on("tabsactivate", function (event, ui) {
        // current tab
        let element_new = $(ui.newTab).children("a");
        let name_new = $(element_new).text();
        if (!element_new.hasClass("log_file")) {
            return false;
        }
        // previous tab
        let element_old = $(ui.oldTab).children("a");
        let name_old = $(element_old).text();
        if (element_old.hasClass("log_file")) {
            $("#log_" + name_old).text("");
        }
        getLogContent(name_new);
    });

    $("#refresh_log").on("click", function () {
        // active log tab
        let log_file = $("#log_tabs .ui-tabs-panel:visible").prop("id").replace(/log_?/, '');
        getLogContent(log_file);
    });

});
