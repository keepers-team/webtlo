
/* Функции для вкладки "Настройки". */


/**
 * Сохранение настроек.
 */
function saveSettings() {
    $('#savecfg').data('unsaved', false).change();

    const forums = getForums();
    const tor_clients = getListTorrentClients();
    const $data = $('#config').serialize();

    $.ajax({
        context: this,
        type: 'POST',
        url: 'php/set_config.php',
        dataType: 'json',
        data: JSON.stringify({
            cfg: $data,
            forums: forums,
            tor_clients: tor_clients,
        }),
        beforeSend: function () {
            $(this).toggleDisable(true);
        },
        success: function (response) {
            addDefaultLog(response.log ?? '');
        },
        complete: function () {
            $(this).toggleDisable(false);
        },
    });
}

function checkSaveSettings() {
    const unsaved = !!+$(this).data('unsaved');
    if (!unsaved) {
        return;
    }

    $('#dialog').dialog(
        {
            buttons: [
                {
                    text: 'Ну и ладно',
                    click: function () {
                        $(this).dialog('close');
                    }
                },
                {
                    text: 'Сохранить',
                    click: function() {
                        saveSettings();
                        $(this).dialog('close');
                    }
                }
            ],
            modal: true,
            resizable: false
        }
    )
        .text('Похоже, что вы не сохранили настройки')
        .dialog('open');
}


function getForums() {
    let forums = {};

    $('#list-forums option').each(function() {
        const forumID = $(this).val();
        if (forumID) {
            const forumTitle = $(this).text();
            const forumData = $(this).data();

            forums[forumID] = {
                'title'        : forumTitle,
                'client'       : forumData.client,
                'label'        : forumData.label,
                'savepath'     : forumData.savepath,
                'subdirectory' : forumData.subdirectory,
                'hide'         : forumData.hide,
                'control_peers': forumData.peers,
                'exclude'      : forumData.exclude
            };
        }
    });

    return forums;
}

// Получение списка торрент-клиентов.
function getListTorrentClients() {
    let torrentClients = {};

    $('#list-torrent-clients li').each(function () {
        const clientId = +$(this).val();
        if (clientId !== 0) {
            const client = this.dataset;

            torrentClients[clientId] = {
                'comment'      : client.comment,
                'type'         : client.type,
                'hostname'     : client.hostname,
                'port'         : client.port,
                'login'        : client.login,
                'password'     : client.password,
                'ssl'          : client.ssl,
                'control_peers': client.peers,
                'exclude'      : client.exclude
            };
        }
    });

    return torrentClients;
}

// Добавление нового хранимого подраздела.
function addSubsection(event, ui) {
    if (ui.item.value < 0) {
        ui.item.value = '';

        return false;
    }

    // Ид и название подраздела, заполняются из autocomplete селектора.
    const forumID = ui.item.value;
    const forumTitle = ui.item.label;
    const forumLabel = forumTitle.replace(/.* » /, '');

    const forumsList = $('#list-forums');
    const mainSelector = $('#main-subsections');
    const reportsSelector = $('#reports-subsections');

    // Сохраним выбранные значения.
    let mainSelectedForumID = mainSelector.val();
    let reportsSelectedForumID = reportsSelector.val();

    // Ищем нужный подраздел.
    let optionForum = $(`#list-forums option[value=${forumID}]`);
    if (optionForum.length === 0) {
        // Если такого нет, то добавляем новую запись в список.
        const templateOption = `<option value="${forumID}">${forumTitle}</option>`;

        optionForum = $(templateOption);
        forumsList.append(optionForum);

        // Дописываем параметры.
        optionForum.text(forumTitle);
        optionForum.saveDataKey('client', 0);
        optionForum.saveDataKey('label', forumLabel);
        optionForum.saveDataKey('savepath', '');
        optionForum.saveDataKey('subdirectory', 1);
        optionForum.saveDataKey('hide', 0);
        optionForum.saveDataKey('peers', '');
        optionForum.saveDataKey('exclude', 0);

        // Дописываем в селекторы подразделов.
        $('#main-subsections-stored').append(templateOption);
        $('#reports-subsections-stored').append(templateOption);

        // Что-то блокируется, что-то нет. Зачем - не ясно.
        $('.forum-props, #list-forums').toggleDisable(false);
        $('#forum-id').toggleDisable(true);

        // Подсвечиваем добавленный подраздел.
        $('#list-forums-button').addClass('ui-state-highlight');
    }

    // Сортируем места, где можно выбрать подраздел.
    doSortSelect('list-forums');
    doSortSelect('main-subsections-stored');
    doSortSelect('reports-subsections-stored');

    mainSelector.val(mainSelectedForumID).selectmenu('refresh');
    reportsSelector.val(reportsSelectedForumID).selectmenu('refresh');
    forumsList.val(forumID).selectmenu('refresh').change();

    ui.item.value = '';
    ui.item.label = '';

    return true;
}

/**
 * Добавление раздела в хранимые, по нажатию на ид форума.
 *
 * @param {number} forum_id
 * @param {string} forum_title
 *
 * @see \KeepersTeam\Webtlo\TopicList\Rule\UntrackedTopics::getTopics
 */
function addUnsavedSubsection(forum_id, forum_title) {
    $('#dialog').dialog({
        buttons  : [
            {
                text : 'Да, добавить',
                click: function() {
                    // Открываем вкладку настроек, настройки хранимых подразделов и вставляем ид раздела
                    $('#menutabs').tabs('option', 'active', $('#menu_settings').index());

                    let target_settings_tab = $('div.sub_settings > h2').index($('#sub_setting_forum'));
                    $('div.sub_settings').accordion('option', 'active', target_settings_tab);

                    $('#add-forum').val(forum_id).autocomplete('search', forum_id);

                    $(this).dialog('close');
                },
            },
            {
                text : 'Нет',
                click: function() {
                    $(this).dialog('close');
                }
            }
        ],
        modal    : true,
        resizable: false
    })
        .text(`Добавить в хранимые подраздел '${forum_title}'?`)
        .dialog('open');
}
