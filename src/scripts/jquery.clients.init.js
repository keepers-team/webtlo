
/* Инициализация работы с торрент-клиентами */

$(document).ready(function () {

    // список торрент-клиентов
    const torrentClientsList = $('#list-torrent-clients');
    torrentClientsList.selectable();

    // выбрать все торрент-клиенты
    var torrentClientTouchTime = 0;
    torrentClientsList.bind("selectablestart", functionDelay(function () {
        if (torrentClientTouchTime == 0) {
            torrentClientTouchTime = new Date().getTime();
        } else {
            var touchTimeDiff = new Date().getTime() - torrentClientTouchTime;
            if (touchTimeDiff < 200) {
                $("li", this).addClass("ui-selected ui-editable ui-state-focus");
                $(".torrent-client-props").addClass("ui-state-disabled").prop("disabled", true);
                torrentClientTouchTime = 0;
            } else {
                torrentClientTouchTime = new Date().getTime();
            }
        }
    }, 100));


    // Блок с настройками торрент-клиента.
    const clientProperties = $('.torrent-client-props');
    clientProperties.toggleWidgetsDisable = function(disabled = false) {
        this.toggleDisable(disabled);
        this.filter('select').selectmenu(disabled ? 'disable' : 'enable');
        this.filter('.ui-spinner-input').spinner(disabled ? 'disable' : 'enable');
    }

    // получить свойства торрент-клиентов
    torrentClientsList.bind("selectablestop", function () {
        var selectedItems = $(".ui-selected", this).size();
        var editedItems = $(".ui-editable", this).size();
        var torrentClientProps = $(".torrent-client-props");
        if (selectedItems == 0) {
            if (editedItems == 1) {
                var editableItems = $("#list-torrent-clients li.ui-editable");
                var torrentClientData = editableItems.data();
                editableItems.addClass("ui-selected");
                torrentClientProps.removeClass("ui-state-disabled").prop("disabled", false);
            } else if (editedItems > 1) {
                $("li.ui-editable", this).addClass("ui-selected");
            }
        } else if (selectedItems > 0) {
            var torrentClientData = $(".ui-selected", this).data();
            $("li", this).removeClass("ui-editable ui-state-focus");
            $("li.ui-selected", this).addClass("ui-editable ui-state-focus");
            if (selectedItems == 1) {
                torrentClientProps.removeClass("ui-state-disabled").prop("disabled", false);
            } else {
                torrentClientProps.addClass("ui-state-disabled").prop("disabled", true);
            }
        }
        if (typeof torrentClientData !== "undefined") {
            if (torrentClientData.exclude == '') {
                torrentClientData.exclude = 0;
            }
            $("#torrent-client-comment").val(torrentClientData.comment);
            $("#torrent-client-type").val(torrentClientData.type);
            $("#torrent-client-type").selectmenu().selectmenu("refresh");
            $("#torrent-client-hostname").val(torrentClientData.hostname);
            $("#torrent-client-port").val(torrentClientData.port);
            $("#torrent-client-login").val(torrentClientData.login);
            $("#torrent-client-password").val(torrentClientData.password);
            $("#torrent-client-ssl").prop("checked", torrentClientData.ssl);
            $("#torrent-client-peers").val(torrentClientData.peers);
            $("#torrent-client-exclude").val(torrentClientData.exclude);
            var clientExclude = $("#torrent-client-exclude [value=" + torrentClientData.exclude + "]").val();
            if (typeof clientExclude === "undefined") {
                $("#torrent-client-exclude :first").prop("selected", "selected");
            } else {
                $("#torrent-client-exclude [value=" + clientExclude + "]").prop("selected", "selected");
            }
            $("#torrent-client-exclude").selectmenu().selectmenu("refresh");
        }
    });

    // изменение свойств торрент-клиента
    $("#torrent-client-props").on("input selectmenuchange spinstop", functionDelay(function () {
        var torrentClientComment = $("#torrent-client-comment").val();
        var torrentClientType = $("#torrent-client-type").val();
        var torrentClientHostname = $("#torrent-client-hostname").val();
        var torrentClientPort = $("#torrent-client-port").val();
        var torrentClientLogin = $("#torrent-client-login").val();
        var torrentClientPassword = $("#torrent-client-password").val();
        var torrentClientSSL = Number($("#torrent-client-ssl").prop("checked"));
        var torrentControlPeers = $("#torrent-client-peers").val();
        var torrentExclude = $("#torrent-client-exclude :selected").val();
        if (torrentClientComment == "") {
            var torrentClientID = $("#list-torrent-clients li.ui-editable").val();
            torrentClientComment = torrentClientID;
            $("#torrent-client-comment").val(torrentClientID);
        }
        var torrentClientTitle = torrentClientComment;
        var optionTorrentClient = $("#list-torrent-clients li.ui-editable");
        var torrentClientStatus = optionTorrentClient.children("i");
        if (
            torrentClientStatus.length > 0
            && $("#list-torrent-clients li.ui-editable").hasClass("ui-connection")
        ) {
            torrentClientTitle += torrentClientStatus[0].outerHTML;
        }
        optionTorrentClient.attr("data-comment", torrentClientComment).data("comment", torrentClientComment);
        optionTorrentClient.attr("data-type", torrentClientType).data("type", torrentClientType);
        optionTorrentClient.attr("data-hostname", torrentClientHostname).data("hostname", torrentClientHostname);
        optionTorrentClient.attr("data-port", torrentClientPort).data("port", torrentClientPort);
        optionTorrentClient.attr("data-login", torrentClientLogin).data("login", torrentClientLogin);
        optionTorrentClient.attr("data-password", torrentClientPassword).data("password", torrentClientPassword);
        optionTorrentClient.attr("data-ssl", torrentClientSSL).data("ssl", torrentClientSSL);
        optionTorrentClient.attr("data-peers", torrentControlPeers).data("peers", torrentControlPeers);
        optionTorrentClient.attr("data-exclude", torrentExclude).data("exclude", torrentExclude);
        optionTorrentClient.html(torrentClientTitle);
        doSortSelect("list-torrent-clients", "li");
        $("#torrent-client-response").text("");
    }, 300));

    // добавить торрент-клиент в список
    $("#add-torrent-client").on("click", function () {
        // Разблокируем ввод, при добавлении нового клиента.
        clientProperties.toggleWidgetsDisable(false);

        var torrentClientComment = $("#torrent-client-comment").val();
        var torrentClientType = $("#torrent-client-type").val();
        var torrentClientHostname = $("#torrent-client-hostname").val();
        var torrentClientPort = $("#torrent-client-port").val();
        var torrentClientLogin = $("#torrent-client-login").val();
        var torrentClientPassword = $("#torrent-client-password").val();
        var torrentClientSSL = Number($("#torrent-client-ssl").prop("checked"));
        var torrentControlPeers = $("#torrent-client-peers").val();
        var torrentExclude = $("#torrent-client-exclude").val();
        if ($.isEmptyObject(torrentClientComment)) {
            torrentClientComment = "client1";
        }
        if ($.isEmptyObject(torrentClientType)) {
            torrentClientType = "utorrent";
        }
        var commentText = torrentClientComment.replace(/\d*$/, "");
        var commentNumber = torrentClientComment.replace(commentText, "");
        var commentLeadingZeros = commentNumber.replace(/[^0].*/, "");
        var torrentClientID = 1;
        if ($("#list-torrent-clients li.ui-selected").val()) {
            var newCommentNumber = 0;
            $("#list-torrent-clients li").each(function () {
                var tmpTorrentClientID = parseInt($(this).val());
                torrentClientID = tmpTorrentClientID > torrentClientID ? tmpTorrentClientID : torrentClientID;
                var torrentClientData = this.dataset;
                torrentClientData.comment = torrentClientData.comment.toString();
                var tmpCommentText = torrentClientData.comment.replace(/\d*$/, "");
                var tmpCommentNumber = torrentClientData.comment.replace(tmpCommentText, "");
                var tmpCommentLeadingZeros = tmpCommentNumber.replace(/[^0].*/, "");
                if (
                    tmpCommentText == commentText
                    && parseInt(tmpCommentNumber) > newCommentNumber
                    && commentLeadingZeros == tmpCommentLeadingZeros
                ) {
                    newCommentNumber = tmpCommentNumber;
                }
            });
            newCommentNumber++;
            torrentClientID++;
            var newComment = commentText + "|" + commentLeadingZeros + "|" + newCommentNumber;
            if (torrentClientComment.length < newComment.length - 2) {
                torrentClientComment = newComment.replace(/\|0*\|/, commentLeadingZeros.slice(0, -1));
            } else {
                torrentClientComment = newComment.replace(/\|/g, "");
            }
        }
        $("#list-torrent-clients li").removeClass("ui-selected ui-editable ui-state-focus");

        torrentClientsList.append("<li value=\"" + torrentClientID + "\">" + torrentClientComment + "</li>");
        var optionTorrentClient = $("#list-torrent-clients li[value=" + torrentClientID + "]");
        optionTorrentClient.attr("data-comment", torrentClientComment).data("comment", torrentClientComment);
        optionTorrentClient.attr("data-type", torrentClientType).data("type", torrentClientType);
        optionTorrentClient.attr("data-hostname", torrentClientHostname).data("hostname", torrentClientHostname);
        optionTorrentClient.attr("data-port", torrentClientPort).data("port", torrentClientPort);
        optionTorrentClient.attr("data-login", torrentClientLogin).data("login", torrentClientLogin);
        optionTorrentClient.attr("data-password", torrentClientPassword).data("password", torrentClientPassword);
        optionTorrentClient.attr("data-ssl", torrentClientSSL).data("ssl", torrentClientSSL);
        optionTorrentClient.attr("data-peers", torrentControlPeers).data("peers", torrentControlPeers);
        optionTorrentClient.attr("data-exclude", torrentExclude).data("exclude", torrentExclude);

        optionTorrentClient.addClass("ui-widget-content ui-selected ui-state-focus");

        torrentClientsList.trigger("selectablestop");
        doSortSelect("list-torrent-clients", "li");
    });

    // Удалить торрент-клиент из списка.
    $('#remove-torrent-client').on('click', function () {
        const selectedClient = $('#list-torrent-clients li.ui-selected');

        if (selectedClient.size() === 0) {
            return false;
        }

        let itemIndex = selectedClient.index();
        selectedClient.each(function () {
            if (!$(this).hasClass('ui-connection')) {
                $(this).remove();
            }
        });

        const totalItems = $('#list-torrent-clients li').size();
        if (totalItems === 0) {
            // Клиентов нет - очищаем значения и блокируем ввод.
            clientProperties.val('').toggleWidgetsDisable(true);

            $('#torrent-client-ssl').prop('checked', false);
            $('#torrent-client-response').text('');
        } else {
            if (itemIndex !== totalItems) {
                itemIndex++;
            }
            $(`#list-torrent-clients li:nth-child(${itemIndex})`).addClass('ui-selected').trigger('selectablestop');
        }

        $('#list-forums option').each(function () {
            const forumData = this.dataset;
            const torrentClientID = $(`#list-torrent-clients li[value=${forumData.client}]`).val();
            if (typeof torrentClientID === 'undefined') {
                $(this).attr(`data-client`, 0);
            }
        });

    });

    // Обновление списка торрент-клиентов в настройках подразделов
    $('#add-torrent-client, #remove-torrent-client').on('click', functionDelay(refreshListTorrentClients, 400));
    $(`#torrent-client-props`).on('input selectmenuchange', functionDelay(refreshListTorrentClients, 400));

    // проверка доступности торрент-клиентов
    $('#connect-torrent-client').on('click', function () {
        let button = this;

        let selectedClients = $('#list-torrent-clients li.ui-selected');
        let numberTorrentClients = selectedClients.size();

        $("#list-torrent-clients i").remove();
        selectedClients.each(function () {
            let torrentClientData = this.dataset;

            $.ajax({
                type: 'POST',
                url: 'php/actions/tor_client_is_online.php',
                context: this,
                data: { tor_client: torrentClientData },
                beforeSend: function () {
                    // Очистка результата проверки торрент-клиентов.
                    $('#torrent-client-response').text('');

                    // Прожимаем кнопку.
                    $(button).toggleDisable(true).children('i').css('display', 'inline-block');

                    $(this).append('<i class="fa fa-spinner fa-spin"></i>');
                    $(this).addClass('ui-connection');
                },
                success: function (response) {
                    response = $.parseJSON(response);
                    addDefaultLog(response.log ?? '');

                    $(this).children('i').remove();
                    $(this).append(response.status);
                    $(this).removeClass('ui-connection');
                },
                complete: function () {
                    numberTorrentClients--;
                    if (numberTorrentClients === 0) {
                        const numberCheckedTorrentClients = $("#list-torrent-clients i").size();
                        if (numberCheckedTorrentClients > 1) {
                            const numberErrors = $("#list-torrent-clients i.text-danger").size();
                            if (numberErrors > 0) {
                                $("#torrent-client-response").html('<i class="fa fa-circle text-danger"></i> Некоторые торрент-клиенты сейчас недоступны');
                            } else {
                                $("#torrent-client-response").html('<i class="fa fa-circle text-success"></i> Все торрент-клиенты сейчас доступны');
                            }
                        }
                        // Отжимаем кнопку.
                        $(button).toggleDisable(false).children('i').hide();
                    }
                },
            });
        });
    });

    // При загрузке - выбрать первый торрент-клиент в списке
    if ($('#list-torrent-clients li').size() > 0) {
        $('#list-torrent-clients li:nth-child(1)').addClass('ui-selected').trigger('selectablestop');
    } else {
        // Нет клиентов - блокируем ввод.
        clientProperties.toggleWidgetsDisable(true);
    }

});
