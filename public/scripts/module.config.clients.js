/**
 * [Настройки] Инициализация используемых торрент-клиентов.
 *
 * @module ModuleNames.CONFIG_CLIENTS
 * @requires ModuleNames.JQUERY_METHODS
 * @requires ModuleNames.CONFIG_COMMON
 */

webtlo.register(ModuleNames.CONFIG_CLIENTS, function () {

    /* Торрент-клиенты */

    const $torrentClientsList = $('#list-torrent-clients');

    // Инициализируем Selectable Widget.
    $torrentClientsList.selectable();

    // Результат проверки группы клиентов.
    const $clientsStatus = $('#torrent-client-response');

    // Блок с настройками торрент-клиента.
    const $clientProperties = $('.torrent-client-props');

    // Блокировка ввода значений.
    $clientProperties.toggleWidgetsDisable = function(disabled = false) {
        this.toggleDisable(disabled);

        this.filter('select').selectmenu(disabled ? 'disable' : 'enable');
        this.filter('.ui-spinner-input').spinner(disabled ? 'disable' : 'enable');
    }

    // Прочитать свойства торрент-клиентов и заполнить их в форму.
    $torrentClientsList.bind('selectablestop', function () {
        const selectedItems = +$('.ui-selected', this).size();
        const editedItems = +$('.ui-editable', this).size();

        let client = {};
        if (selectedItems === 0) {
            if (editedItems === 1) {
                // Текущий выделенный клиент.
                const currentEditableClient = $('#list-torrent-clients li.ui-editable');
                client = currentEditableClient.data();

                currentEditableClient.addClass('ui-selected');
                $clientProperties.toggleDisable(false);
            } else if (editedItems > 1) {
                $('li.ui-editable', this).addClass('ui-selected');
            }
        } else if (selectedItems > 0) {
            client = $('.ui-selected', this).data();

            $('li', this).removeClass('ui-editable ui-state-focus');
            $('li.ui-selected', this).addClass('ui-editable ui-state-focus');

            $clientProperties.toggleDisable(selectedItems !== 1)
        }

        if ($.isEmptyObject(client)) {
            return false;
        }

        if (!client.exclude) {
            client.exclude = 0;
        }

        $('#torrent-client-comment').val(client.comment);
        $('#torrent-client-type').val(client.type).selectmenu('refresh');
        $('#torrent-client-hostname').val(client.hostname);
        $('#torrent-client-port').val(client.port);
        $('#torrent-client-login').val(client.login);
        $('#torrent-client-password').val(client.password);
        $('#torrent-client-ssl').prop('checked', client.ssl);
        $('#torrent-client-peers').val(client.peers);

        const clientExclude = $(`#torrent-client-exclude [value=${client.exclude}]`).val();
        if (typeof clientExclude === 'undefined') {
            $('#torrent-client-exclude :first').prop('selected', true);
        } else {
            $(`#torrent-client-exclude [value=${clientExclude}]`).prop('selected', true);
        }
        $('#torrent-client-exclude').val(client.exclude).selectmenu('refresh');

        return true;
    });

    // Изменение свойств торрент-клиента
    $('#torrent-client-props').on('input selectmenuchange spinstop', functionDelay(function () {
        const commentElem = $('#torrent-client-comment');

        let torrentClientComment = commentElem.val();
        const torrentClientType = $("#torrent-client-type").val();
        const torrentClientHostname = $("#torrent-client-hostname").val();
        const torrentClientPort = $("#torrent-client-port").val();
        const torrentClientLogin = $("#torrent-client-login").val();
        const torrentClientPassword = $("#torrent-client-password").val();
        const torrentClientSSL = Number($("#torrent-client-ssl").prop("checked"));
        const torrentControlPeers = $("#torrent-client-peers").val();
        const torrentExclude = $("#torrent-client-exclude :selected").val();

        // Текущий выделенный клиент.
        const currentEditableClient = $('#list-torrent-clients li.ui-editable');
        if (torrentClientComment === '') {
            const torrentClientID = currentEditableClient.val();

            torrentClientComment = 'client' + torrentClientID;
            commentElem.val(torrentClientComment);
        }

        let torrentClientTitle = torrentClientComment;
        const torrentClientStatus = currentEditableClient.children('i');

        if (
            torrentClientStatus.length > 0
            && currentEditableClient.hasClass('ui-connection')
        ) {
            torrentClientTitle += torrentClientStatus[0].outerHTML;
        }

        currentEditableClient.html(torrentClientTitle);
        currentEditableClient.saveDataKey('comment', torrentClientComment);
        currentEditableClient.saveDataKey('type', torrentClientType);
        currentEditableClient.saveDataKey('hostname', torrentClientHostname);
        currentEditableClient.saveDataKey('port', torrentClientPort);
        currentEditableClient.saveDataKey('login', torrentClientLogin);
        currentEditableClient.saveDataKey('password', torrentClientPassword);
        currentEditableClient.saveDataKey('ssl', torrentClientSSL);
        currentEditableClient.saveDataKey('peers', torrentControlPeers);
        currentEditableClient.saveDataKey('exclude', torrentExclude);

        doSortSelect('list-torrent-clients', 'li');

        $clientsStatus.text('');
    }, 300));

    // Кнопка добавить торрент-клиент в список.
    $('#add-torrent-client').on('click', function () {
        // Разблокируем ввод, при добавлении нового клиента.
        $clientProperties.toggleWidgetsDisable(false);

        let torrentClientComment = $('#torrent-client-comment').val();
        let torrentClientType = $('#torrent-client-type').val();
        const torrentClientHostname = $('#torrent-client-hostname').val();
        const torrentClientPort = $('#torrent-client-port').val();
        const torrentClientLogin = $('#torrent-client-login').val();
        const torrentClientPassword = $('#torrent-client-password').val();
        const torrentClientSSL = Number($('#torrent-client-ssl').prop('checked'));
        const torrentControlPeers = $('#torrent-client-peers').val();
        const torrentExclude = $('#torrent-client-exclude').val();

        if ($.isEmptyObject(torrentClientComment)) {
            torrentClientComment = 'client1';
        }

        if ($.isEmptyObject(torrentClientType)) {
            torrentClientType = 'qbittorrent';
        }

        const commentText = torrentClientComment.replace(/\d*$/, '');
        const commentNumber = torrentClientComment.replace(commentText, '');
        const commentLeadingZeros = commentNumber.replace(/[^0].*/, '');

        let torrentClientID = 1;

        const torrentClientsChildren = $('#list-torrent-clients li');
        // Вычислить ид и комментарий нового клиента.
        if ($('#list-torrent-clients li.ui-selected').val()) {
            let newCommentNumber = 0;
            torrentClientsChildren.each(function () {
                const tmpTorrentClientID = parseInt($(this).val());
                torrentClientID = tmpTorrentClientID > torrentClientID ? tmpTorrentClientID : torrentClientID;

                const torrentClientData = this.dataset;
                torrentClientData.comment = torrentClientData.comment.toString();

                const tmpCommentText = torrentClientData.comment.replace(/\d*$/, '');
                const tmpCommentNumber = torrentClientData.comment.replace(tmpCommentText, '');
                const tmpCommentLeadingZeros = tmpCommentNumber.replace(/[^0].*/, '');

                if (
                    tmpCommentText === commentText
                    && parseInt(tmpCommentNumber) > newCommentNumber
                    && commentLeadingZeros === tmpCommentLeadingZeros
                ) {
                    newCommentNumber = tmpCommentNumber;
                }
            });

            newCommentNumber++;
            torrentClientID++;

            const newComment = `${commentText}|${commentLeadingZeros}|${newCommentNumber}`;
            if (torrentClientComment.length < newComment.length - 2) {
                torrentClientComment = newComment.replace(/\|0*\|/, commentLeadingZeros.slice(0, -1));
            } else {
                torrentClientComment = newComment.replace(/\|/g, "");
            }
        }

        torrentClientsChildren.removeClass('ui-selected ui-editable ui-state-focus');

        $torrentClientsList.append(`<li value="${torrentClientID}">${torrentClientComment}</li>`);

        const optionTorrentClient = $("#list-torrent-clients li[value=" + torrentClientID + "]");

        optionTorrentClient.saveDataKey('comment', torrentClientComment);
        optionTorrentClient.saveDataKey('type', torrentClientType);
        optionTorrentClient.saveDataKey('hostname', torrentClientHostname);
        optionTorrentClient.saveDataKey('port', torrentClientPort);
        optionTorrentClient.saveDataKey('login', torrentClientLogin);
        optionTorrentClient.saveDataKey('password', torrentClientPassword);
        optionTorrentClient.saveDataKey('ssl', torrentClientSSL);
        optionTorrentClient.saveDataKey('peers', torrentControlPeers);
        optionTorrentClient.saveDataKey('exclude', torrentExclude);

        optionTorrentClient.addClass('ui-widget-content ui-selected ui-state-focus');

        // Сортируем клиенты и вызываем заполнение параметров.
        doSortSelect('list-torrent-clients', 'li');
        $torrentClientsList.trigger('selectablestop');
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
            $clientsStatus.text('');
            $clientProperties.val('').toggleWidgetsDisable(true);
            $('#torrent-client-ssl').prop('checked', false);
        } else {
            if (itemIndex !== totalItems) {
                itemIndex++;
            }

            $(`#list-torrent-clients li:nth-child(${itemIndex})`).addClass('ui-selected').trigger('selectablestop');
        }

        $('#list-forums option').each(function () {
            const subForum = this.dataset;
            const usedClientId = $(`#list-torrent-clients li[value=${subForum.client}]`).val();

            // Если, используемый в подразделе, клиент был удалён - указываем "не выбран".
            if (typeof usedClientId === 'undefined') {
                $(this).saveDataKey('client', 0);
            }
        });

        return true;
    });

    // Обновление списка торрент-клиентов в настройках подразделов
    $('#add-torrent-client, #remove-torrent-client').on('click', functionDelay(refreshListTorrentClients, 400));
    $(`#torrent-client-props`).on('input selectmenuchange', functionDelay(refreshListTorrentClients, 400));

    // Выбрать все торрент-клиенты
    let torrentClientTouchTime = 0;
    $torrentClientsList.bind('selectablestart', functionDelay(function () {
        if (torrentClientTouchTime === 0) {
            torrentClientTouchTime = new Date().getTime();
        } else {
            const touchTimeDiff = new Date().getTime() - torrentClientTouchTime;
            if (touchTimeDiff < 200) {
                $('li', this).addClass('ui-selected ui-editable ui-state-focus');
                $clientProperties.toggleWidgetsDisable(true);

                torrentClientTouchTime = 0;
            } else {
                torrentClientTouchTime = new Date().getTime();
            }
        }
    }, 100));

    // Кнопка поверки доступности торрент-клиентов
    $('#connect-torrent-client').on('click', function () {
        const button = this;

        // Выделенные торрент-клиенты.
        const selectedClients = $('#list-torrent-clients li.ui-selected');
        let numberTorrentClients = selectedClients.size();

        $clientsStatus.text('');
        $('#list-torrent-clients i').remove();
        selectedClients.each(function () {
            const client = this.dataset;

            $.ajax({
                type: 'POST',
                url: 'php/tor_client_is_online.php',
                context: this,
                data: { tor_client: client },
                beforeSend: function () {
                    // Очистка результата проверки торрент-клиентов.
                    $clientsStatus.text('');

                    // Прожимаем кнопку.
                    $(button).toggleDisable(true).children('i').css('display', 'inline-block');

                    $(this).append('<i class="fa fa-spinner fa-spin"></i>');
                    $(this).addClass('ui-connection');
                },
                success: function (response) {
                    addDefaultLog(response.log ?? '');

                    $(this).children('i').remove();
                    $(this).append(response.status);
                    $(this).removeClass('ui-connection');
                },
                complete: function () {
                    numberTorrentClients--;

                    if (numberTorrentClients === 0) {
                        const numberCheckedTorrentClients = $('#list-torrent-clients i').size();
                        if (numberCheckedTorrentClients > 1) {
                            const numberErrors = $("#list-torrent-clients i.text-danger").size();
                            if (numberErrors > 0) {
                                $clientsStatus.html('<i class="fa fa-circle text-danger"></i> Некоторые торрент-клиенты недоступны');
                            } else {
                                $clientsStatus.html('<i class="fa fa-circle text-success"></i> Выбранные торрент-клиенты доступны');
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
        $clientProperties.toggleWidgetsDisable(true);
    }

    // Обновление списка торрент-клиентов.
    function refreshListTorrentClients() {
        const clientSelectors = $('#forum-client, #filter_client_id');
        let excludedClients = [];

        clientSelectors.find('option').each(function () {
            if ($(this).val() !== '0') {
                $(this).remove();
            }
        });
        $("#list-torrent-clients li").each(function () {
            const clientId = +$(this).val();
            const client = this.dataset;
            if (clientId !== 0) {
                clientSelectors.append(`<option value="${clientId}">${client.comment}</option>`);

                if (client.exclude-0) {
                    excludedClients.push(`${client.comment}(${clientId})`);
                }
            }
        });

        if ($('#list-forums option').size() > 0) {
            $('#list-forums').change();
        }

        clientSelectors.selectmenu('refresh');
        $('#exclude_clients_ids').val(excludedClients.join(','));
    }

}, [
    ModuleNames.JQUERY_METHODS,
    ModuleNames.CONFIG_COMMON,
]);
