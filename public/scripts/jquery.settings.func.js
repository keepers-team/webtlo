
/* Функции для вкладки настроек. */

// Собрать ссылку на форум из настроек.
function getForumUrl() {
    let domain = $('#forum_url').val();
    if (domain === 'custom') {
        domain = $('#forum_url_custom').val()
    }

    return `https://${domain}`;
}

/**
 * Показать/скрыть пароль и сменить иконку кнопке.
 */
function togglePasswordVisibility(button, input) {
    $(button).find('i.fa').toggleClass('fa-eye fa-eye-slash');

    let inputField = $(input);
    inputField.prop('type', inputField.prop('type') === 'text' ? 'password' : 'text');
}

/**
 * Сохранение настроек.
 */
function setSettings() {
    $('#savecfg').data('unsaved', false).change();

    let forums = getForums();
    let tor_clients = getListTorrentClients();
    let $data = $('#config').serialize();

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
                        setSettings();
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
