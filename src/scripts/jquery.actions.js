$(document).ready(function () {


    // обновление сведений о раздачах
    $("#update_info").on("click", function () {
        let button = $(this);
        let update_info_local = function () {
            $.ajax({
                type: "GET",
                url: "php/actions/update_info.php",
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
                    $("#log").append(response.log);
                    showResultTopics(response.result);

                    checkEmptyTitleTopics(true);
                    getFilteredTopics();
                },
                complete: function () {
                    filter_hold = false;
                    block_actions();
                },
            });
        }

        if (!refreshTopics.interval) {
            update_info_local();
        } else {
            $("#dialog")
                .text('Имеются раздачи, в процессе обновления дополнительных сведений. Вы уверены, что хотите запустить обновление сейчас?')
                .dialog({
                    modal: true,
                    autoOpen: true,
                    buttons: [
                        {
                            text: "Да, запустить",
                            click: function () {
                                $(this).dialog("close");
                                update_info_local();
                            },
                        },
                        {
                            text: "Нет, подождём",
                            click: function () {
                                $(this).dialog("close");
                            }
                        }
                    ],
                });
        }
    });

    // отправка отчётов
    $("button.send_reports").on("click", function () {
        let buttons = $("button.send_reports").toggleDisable(true);
        let icon = buttons.find("i.fa").toggleClass('fa-paper-plane-o fa-spinner');
        $.ajax({
            type: "POST",
            url: "php/actions/send_reports.php",
            beforeSend: function () {
                block_actions();
                processStatus.set("Отправка отчётов на форум...");
            },
            success: function (response) {
                response = $.parseJSON(response);
                $("#log").append(response.log);
                showResultTopics(response.result);
            },
            complete: function () {
                block_actions();
                buttons.toggleDisable(false);
                icon.toggleClass('fa-paper-plane-o fa-spinner');
            },
        });
    });

    // регулировка раздач
    $("#control_torrents").on("click", function () {
        $.ajax({
            type: "POST",
            url: "php/actions/control_torrents.php",
            beforeSend: function () {
                block_actions();
                processStatus.set("Регулировка раздач...");
            },
            success: function (response) {
                response = $.parseJSON(response);
                $("#log").append(response.log);
                showResultTopics(response.result);
            },
            complete: function () {
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
    $("#forum_url, #api_url").on("selectmenucreate selectmenuchange", function (event, ui) {
        var value = $(this).val();
        var name = $(this).attr("name");
        if (value == 'custom') {
            $("#" + name + "_custom").attr("type", "text");
        } else {
            $("#" + name + "_custom").attr("type", "hidden");
        }
    });

    // проверка доступности форума и API
    $("#check_mirrors_access").on("click", function () {
        const $data = $("#config").serialize();
        const check_list = ['forum', 'api'];
        const result_list = ['text-danger', 'text-success'];
        let forumButtons = $('#forum_auth, #check_mirrors_access').toggleDisable(true);
        let check_count = check_list.length;

        $.each(check_list, function (index, value) {
            let element = "#" + value + "_url";
            let url = $(element).val();
            let url_custom = $(element + "_custom").val();
            let ssl = $("#" + value + "_ssl").is(":checked");

            let lockElems = $(`.check_access_${value}`)
                .add(element)
                .add(element + "_custom")
                .toggleDisable(true);

            if (typeof url === "undefined" || $.isEmptyObject(url)) {
                check_count--;
                if (check_count === 0) {
                    forumButtons.toggleDisable(false);
                }

                $(element + "_params i").removeAttr("class");
                lockElems.toggleDisable(false);
                return true;
            }
            $.ajax({
                type: "POST",
                url: "php/actions/check_mirror_access.php",
                data: {
                    cfg: $data,
                    url: url,
                    url_custom: url_custom,
                    ssl: ssl,
                    url_type: value
                },
                success: function (response) {
                    $(element + "_params i").removeAttr("class");
                    lockElems.toggleDisable(false);

                    const result = result_list[response];
                    if (typeof result !== "undefined") {
                        $(element + "_params i").addClass("fa fa-circle " + result);
                    }
                },
                beforeSend: function () {
                    $(element + "_params i").removeAttr("class");
                    $(element + "_params i").addClass("fa fa-spinner fa-spin");
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

    // получение bt_key, api_key, user_id
    $("#forum_auth").on("click", function () {
        if (
            !$("#tracker_username").val()
            && !$("#tracker_password").val()
        ) {
            return false;
        }
        let forumButtons = $('#forum_auth, #check_mirrors_access').toggleDisable(true);
        const $data = $("#config").serialize();
        const cap_code = $("#cap_code").val();
        const cap_fields = $("#cap_fields").val();

        let dialog = $('#dialog');
        let authResult = $('#forum_auth_result');
        $.ajax({
            type: "POST",
            url: "php/actions/get_user_details.php",
            data: {
                cfg: $data,
                cap_code: cap_code,
                cap_fields: cap_fields
            },
            context: this,
            success: function (response) {
                response = $.parseJSON(response);
                $("#log").append(response.log);
                if (!$.isEmptyObject(response.captcha)) {
                    authResult.removeAttr("class").addClass("fa fa-circle text-danger");

                    let capFields = response.captcha.join(',');
                    let curLogin = $("#tracker_username").val();
                    let curPass  = $("#tracker_password").val();

                    dialog.dialog(
                        {
                            buttons: [
                                {
                                    text: "OK",
                                    click: function () {
                                        let username_correct = $("#tracker_username_correct").val();
                                        let password_correct = $("#tracker_password_correct").val();
                                        $("#tracker_username").val(username_correct);
                                        $("#tracker_password").val(password_correct);
                                        $("#forum_auth").click();
                                        dialog.dialog("close");
                                    },
                                },
                            ],
                            modal: true,
                            resizable: false,
                        }
                    ).html('<span class="text-danger">Вы видите это сообщение, потому что ввели неверные логин и/или пароль</span><br /><br />' +
                        'Введите правильные данные для авторизации на форуме RuTracker.org ниже и нажмите "ОК"<br /><br />' +
                        `Логин: <input type="text" class="myinput" id="tracker_username_correct" value="${curLogin}"/><br />` +
                        `Пароль: <input class="myinput" type="text" id="tracker_password_correct" value="${curPass}"/><br /><br />` +
                        `Введите текст с картинки: <input class="myinput" type="hidden" id="cap_fields" value="${capFields}" />` +
                        `<div><img src="${response.captcha_path}" /></div>` +
                        '<input id="cap_code" size="27" />');
                    dialog.dialog("open");
                } else {
                    authResult.removeAttr("class");
                    if (
                        !$.isEmptyObject(response.bt_key)
                        && !$.isEmptyObject(response.api_key)
                        && !$.isEmptyObject(response.user_id)
                        && !$.isEmptyObject(response.user_session)
                    ) {
                        // Записываем полученные значения ключей и сохраняем настройки.
                        $("#bt_key").val(response.bt_key);
                        $("#api_key").val(response.api_key);
                        $("#user_id").val(response.user_id);
                        $("#user_session").val(response.user_session);

                        authResult.addClass("fa fa-circle text-success");
                        setSettings();
                    } else {
                        authResult.addClass("fa fa-circle text-danger");
                    }
                }
            },
            beforeSend: function () {
                authResult.removeAttr("class");
                authResult.addClass("fa fa-spinner fa-spin");
            },
            complete: function () {
                forumButtons.toggleDisable(false);
            }
        });
    });

    $("#forum_auth_params, #api_auth_params").on("input", function () {
        $("#forum_auth_result").removeAttr("class");
    });

    $("#forum_auth_params, #api_auth_params").on("keypress", function () {
        let disabled = $("#forum_auth").prop("disabled");
        if (disabled !== false) {
            return false;
        }
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
    $("#get_statistics").on("click", function () {
        $.ajax({
            context: this,
            type: "POST",
            url: "php/actions/get_statistics.php",
            beforeSend: function () {
                $(this).addClass("ui-state-disabled").prop("disabled", true);
            },
            success: function (response) {
                response = $.parseJSON(response);
                $("#table_statistics tbody").html(response.tbody);
                $("#table_statistics tfoot").html(response.tfoot);
            },
            complete: function () {
                $(this).removeClass("ui-state-disabled").prop("disabled", false);
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
            url: "php/actions/clear_log_content.php",
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
        getLogContent(name_new);;
    });

    $("#refresh_log").on("click", function () {
        // active log tab
        let log_file = $("#log_tabs .ui-tabs-panel:visible").prop("id").replace(/log_?/, '');
        getLogContent(log_file);
    });

});
