
/* Работа с торрент-клиентами */

// обновление списка торрент-клиентов
function refreshListTorrentClients() {
    var clientSelectors = $("#forum-client, #filter_client_id");
    var excludedClients = [];
    clientSelectors.find("option").each(function () {
        if ($(this).val() != 0) {
            $(this).remove();
        }
    });
    $("#list-torrent-clients li").each(function () {
        var torrentClientID = $(this).val();
        var torrentClientData = this.dataset;
        if (torrentClientID != 0) {
            clientSelectors.append(`<option value="${torrentClientID}">${torrentClientData.comment}</option>`);

            if (torrentClientData.exclude-0) {
                excludedClients.push(`${torrentClientData.comment}(${torrentClientID})`);
            }
        }
    });
    if ($("#list-forums option").size() > 0) {
        $("#list-forums").change();
    }
    clientSelectors.selectmenu("refresh");
    $("#exclude_clients_ids").val(excludedClients.join(","));
}

// получение списка торрент-клиентов
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
