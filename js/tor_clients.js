
/* всё про торрент-клиенты */

$(document).ready(function () {

	// последний выбранный т.-клиент
	var editableTorrentClientID;

	// получение свойств т.-клиента
	$("#list-torrent-clients").on("change", function () {
		var torrentClientData = $("#list-torrent-clients :selected").data();
		$("#torrent-client-comment").val(torrentClientData.comment);
		$("#torrent-client-type [value=" + torrentClientData.type + "]").prop("selected", "selected");
		$("#torrent-client-hostname").val(torrentClientData.hostname);
		$("#torrent-client-port").val(torrentClientData.port);
		$("#torrent-client-login").val(torrentClientData.login);
		$("#torrent-client-password").val(torrentClientData.password);
		editableTorrentClientID = $(this).val();
	});

	// изменение свойств т.-клиента
	$("#torrent-client-props").on("focusout", function () {
		var torrentClientComment = $("#torrent-client-comment").val();
		var torrentClientType = $("#torrent-client-type").val();
		var torrentClientHostname = $("#torrent-client-hostname").val();
		var torrentClientPort = $("#torrent-client-port").val();
		var torrentClientLogin = $("#torrent-client-login").val();
		var torrentClientPassword = $("#torrent-client-password").val();
		if (torrentClientComment == "") {
			torrentClientComment = editableTorrentClientID;
		}
		var optionTorrentClient = $("#list-torrent-clients option[value=" + editableTorrentClientID + "]");
		optionTorrentClient.attr("data-comment", torrentClientComment).data("comment", torrentClientComment);
		optionTorrentClient.attr("data-type", torrentClientType).data("type", torrentClientType);
		optionTorrentClient.attr("data-hostname", torrentClientHostname).data("hostname", torrentClientHostname);
		optionTorrentClient.attr("data-port", torrentClientPort).data("port", torrentClientPort);
		optionTorrentClient.attr("data-login", torrentClientLogin).data("login", torrentClientLogin);
		optionTorrentClient.attr("data-password", torrentClientPassword).data("password", torrentClientPassword);
		optionTorrentClient.text(torrentClientComment);
		doSortSelect("list-torrent-clients optgroup");
	});

	// добавить т.-клиент в список
	$("#add-torrent-client").on("click", function () {
		$(".torrent-client-props").prop("disabled", false);
		var torrentClientComment = $("#torrent-client-comment").val();
		var torrentClientType = $("#torrent-client-type").val();
		var torrentClientHostname = $("#torrent-client-hostname").val();
		var torrentClientPort = $("#torrent-client-port").val();
		var torrentClientLogin = $("#torrent-client-login").val();
		var torrentClientPassword = $("#torrent-client-password").val();
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
		if ($("#list-torrent-clients").val()) {
			var newCommentNumber = 0;
			$("#list-torrent-clients option").each(function () {
				var tmpTorrentClientID = parseInt($(this).val());
				torrentClientID = tmpTorrentClientID > torrentClientID ? tmpTorrentClientID : torrentClientID;
				var torrentClientData = $(this).data();
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
		$("#list-torrent-clients optgroup").append("<option value=\"" + torrentClientID + "\">" + torrentClientComment + "</option>");
		var optionTorrentClient = $("#list-torrent-clients option[value=" + torrentClientID + "]");
		optionTorrentClient.attr("data-comment", torrentClientComment).data("comment", torrentClientComment);
		optionTorrentClient.attr("data-type", torrentClientType).data("type", torrentClientType);
		optionTorrentClient.attr("data-hostname", torrentClientHostname).data("hostname", torrentClientHostname);
		optionTorrentClient.attr("data-port", torrentClientPort).data("port", torrentClientPort);
		optionTorrentClient.attr("data-login", torrentClientLogin).data("login", torrentClientLogin);
		optionTorrentClient.attr("data-password", torrentClientPassword).data("password", torrentClientPassword);
		optionTorrentClient.prop("selected", "selected").change();
		doSortSelect("list-torrent-clients optgroup");
	});

	// удалить т.-клиент из списка
	$("#remove-torrent-client").on("click", function () {
		var torrentClientID = $("#list-torrent-clients").val();
		if (typeof torrentClientID === "undefined") {
			return false;
		}
		var optionIndex = $("#list-torrent-clients :selected").index();
		$("#list-torrent-clients :selected").remove();
		var optionTotal = $("select[id=list-torrent-clients] option").size();
		if (optionTotal == 0) {
			$(".torrent-client-props").val("").prop("disabled", true);
		} else {
			if (optionTotal != optionIndex) {
				optionIndex++;
			}
			$("#list-torrent-clients :nth-child(" + optionIndex + ")").prop("selected", "selected").change();
		}
		$("#list-forums option").each(function () {
			var forumData = $(this).data();
			var torrentClientID = $("#list-torrent-clients option[value=" + forumData.client + "]").val();
			if (typeof torrentClientID === "undefined") {
				$(this).attr("data-client", 0);
			}
		});
	});

	// обновление списка т.-клиентовв настройках подразделов
	$("#add-torrent-client, #remove-torrent-client").on("click", listClientsRefresh);
	$("#torrent-client-props").on("focusout", listClientsRefresh);

	// проверка доступности т.-клиента
	$("#connect-torrent-client").on("click", function () {
		var value = $("#list-torrent-clients").val();
		if ($.isEmptyObject(value)) {
			return false;
		}
		var torrentClientData = $("#list-torrent-clients :selected").data();
		$.ajax({
			url: "php/actions/tor_client_is_online.php",
			type: "POST",
			context: this,
			data: { tor_client: torrentClientData },
			beforeSend: function () {
				$("#torrent-client-response").text("");
				$(this).children("i").css("display", "inline-block");
				$(this).prop("disabled", true);
			},
			success: function (response) {
				response = $.parseJSON(response);
				$("#log").append(response.log);
				$("#torrent-client-response").html(response.status);
			},
			complete: function () {
				$(this).prop("disabled", false);
				$(this).children("i").hide();
			},
		});
	});

	// при загрузке выбрать первый т.-клиент в списке
	if ($("select[id=list-torrent-clients] option").size() > 0) {
		$("#list-torrent-clients :nth-child(1)").prop("selected", "selected").change();
	} else {
		$(".torrent-client-props").prop("disabled", true);
	}

});

// обновление списка т.-клиентов
function listClientsRefresh() {
	$("#forum-client option").each(function () {
		if ($(this).val() != 0) {
			$(this).remove();
		}
	});
	$("#list-torrent-clients option").each(function () {
		var torrentClientID = $(this).val();
		var torrentClientData = $(this).data();
		if (torrentClientID != 0) {
			$("#forum-client").append("<option value=\"" + torrentClientID + "\">" + torrentClientData.comment + "</option>");
		}
	});
	if ($("select[id=list-forums] option").size() > 0) {
		$("#list-forums").change();
	}
}

// получение списка т.-клиентов
function getTorClients() {
	var torrentClients = {};
	$("#list-torrent-clients option").each(function () {
		var torrentClientID = $(this).val();
		if (torrentClientID != 0) {
			var torrentClientData = $(this).data();
			torrentClients[torrentClientID] = {
				"comment": torrentClientData.comment,
				"type": torrentClientData.type,
				"hostname": torrentClientData.hostname,
				"port": torrentClientData.port,
				"login": torrentClientData.login,
				"password": torrentClientData.password
			};
		}
	});
	return torrentClients;
}
