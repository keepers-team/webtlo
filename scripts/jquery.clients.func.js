
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
    var torrentClients = {};
    $("#list-torrent-clients li").each(function () {
        var torrentClientID = $(this).val();
        if (torrentClientID != 0) {
            var torrentClientData = this.dataset;
            torrentClients[torrentClientID] = {
                "comment": torrentClientData.comment,
                "type": torrentClientData.type,
                "hostname": torrentClientData.hostname,
                "port": torrentClientData.port,
                "login": torrentClientData.login,
                "password": torrentClientData.password,
                "ssl": torrentClientData.ssl,
                "control_peers": torrentClientData.peers,
                "exclude": torrentClientData.exclude
            };
        }
    });
    return torrentClients;
}
