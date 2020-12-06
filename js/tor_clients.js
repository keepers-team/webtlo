
/* всё про торрент-клиенты */

$(document).ready(function () {

	// список торрент-клиентов
	$("#list-torrent-clients").selectable();

	// получить свойства торрент-клиентов
	var torrentClientTouchTime = 0;
	$("#list-torrent-clients").bind("selectablestop", function () {
		if (torrentClientTouchTime == 0) {
			torrentClientTouchTime = new Date().getTime();
		} else {
			var touchTimeDiff = new Date().getTime() - torrentClientTouchTime;
			if (touchTimeDiff < 200) {
				// выбрать все торрент-клиенты
				$("li", this).addClass("ui-selected ui-editable");
				$(".torrent-client-props").prop("disabled", true);
				torrentClientTouchTime = 0;
				return;
			} else {
				torrentClientTouchTime = new Date().getTime();
			}
		}
		var selectedItems = $(".ui-selected", this).size();
		var editedItems = $(".ui-editable", this).size();
		var torrentClientProps = $(".torrent-client-props");
		// получение свойств торрент-клиента
		if (selectedItems == 0) {
			if (editedItems == 1) {
				var editableItems = $("#list-torrent-clients li.ui-editable");
				var torrentClientData = editableItems.data();
				editableItems.addClass("ui-selected");
				torrentClientProps.prop("disabled", false);
			} else if (editedItems > 1) {
				$("li.ui-editable", this).addClass("ui-selected");
			}
		} else if (selectedItems > 0) {
			var torrentClientData = $(".ui-selected", this).data();
			$("li", this).removeClass("ui-editable");
			$("li.ui-selected", this).addClass("ui-editable");
			if (selectedItems == 1) {
				torrentClientProps.prop("disabled", false);
			} else {
				torrentClientProps.prop("disabled", true);
			}
		}
		if (typeof torrentClientData !== "undefined") {
			$("#torrent-client-comment").val(torrentClientData.comment);
			$("#torrent-client-type [value=" + torrentClientData.type + "]").prop("selected", "selected");
			$("#torrent-client-hostname").val(torrentClientData.hostname);
			$("#torrent-client-port").val(torrentClientData.port);
			$("#torrent-client-login").val(torrentClientData.login);
			$("#torrent-client-password").val(torrentClientData.password);
			$("#torrent-client-ssl").prop("checked", torrentClientData.ssl);
		}
	});

	// изменение свойств торрент-клиента
	$("#torrent-client-props").on("input", functionDelay(function () {
		var torrentClientComment = $("#torrent-client-comment").val();
		var torrentClientType = $("#torrent-client-type").val();
		var torrentClientHostname = $("#torrent-client-hostname").val();
		var torrentClientPort = $("#torrent-client-port").val();
		var torrentClientLogin = $("#torrent-client-login").val();
		var torrentClientPassword = $("#torrent-client-password").val();
		var torrentClientSSL = Number($("#torrent-client-ssl").prop("checked"));
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
		optionTorrentClient.html(torrentClientTitle);
		doSortSelect("list-torrent-clients", "li");
		$("#torrent-client-response").text("");
	}, 300));

	// добавить торрент-клиент в список
	$("#add-torrent-client").on("click", function () {
		$(".torrent-client-props").prop("disabled", false);
		var torrentClientComment = $("#torrent-client-comment").val();
		var torrentClientType = $("#torrent-client-type").val();
		var torrentClientHostname = $("#torrent-client-hostname").val();
		var torrentClientPort = $("#torrent-client-port").val();
		var torrentClientLogin = $("#torrent-client-login").val();
		var torrentClientPassword = $("#torrent-client-password").val();
		var torrentClientSSL = Number($("#torrent-client-ssl").prop("checked"));
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
		$("#list-torrent-clients li").removeClass("ui-selected ui-editable");
		$("#list-torrent-clients").append("<li value=\"" + torrentClientID + "\">" + torrentClientComment + "</li>");
		var optionTorrentClient = $("#list-torrent-clients li[value=" + torrentClientID + "]");
		optionTorrentClient.attr("data-comment", torrentClientComment).data("comment", torrentClientComment);
		optionTorrentClient.attr("data-type", torrentClientType).data("type", torrentClientType);
		optionTorrentClient.attr("data-hostname", torrentClientHostname).data("hostname", torrentClientHostname);
		optionTorrentClient.attr("data-port", torrentClientPort).data("port", torrentClientPort);
		optionTorrentClient.attr("data-login", torrentClientLogin).data("login", torrentClientLogin);
		optionTorrentClient.attr("data-password", torrentClientPassword).data("password", torrentClientPassword);
		optionTorrentClient.attr("data-ssl", torrentClientSSL).data("ssl", torrentClientSSL);
		optionTorrentClient.addClass("ui-widget-content ui-selected");
		$("#list-torrent-clients").trigger("selectablestop");
		doSortSelect("list-torrent-clients", "li");
	});

	// удалить торрент-клиенты из списка
	$("#remove-torrent-client").on("click", function () {
		var selectedItems = $("#list-torrent-clients li.ui-selected").size();
		if (selectedItems === 0) {
			return false;
		}
		var itemIndex = $("#list-torrent-clients li.ui-selected").index();
		$("#list-torrent-clients li.ui-selected").each(function () {
			if (!$(this).hasClass("ui-connection")) {
				$(this).remove();
			}
		});
		var totalItems = $("#list-torrent-clients li").size();
		if (totalItems == 0) {
			$(".torrent-client-props").val("").prop("disabled", true);
			$("#torrent-client-ssl").prop("checked", false);
			$("#torrent-client-response").text("");
		} else {
			if (itemIndex != totalItems) {
				itemIndex++;
			}
			$("#list-torrent-clients li:nth-child(" + itemIndex + ")").addClass("ui-selected").trigger("selectablestop");
		}
		$("#list-forums option").each(function () {
			var forumData = this.dataset;
			var torrentClientID = $("#list-torrent-clients li[value=" + forumData.client + "]").val();
			if (typeof torrentClientID === "undefined") {
				$(this).attr("data-client", 0);
			}
		});
	});

	// обновление списка торрент-клиентов настройках подразделов
	$("#add-torrent-client, #remove-torrent-client").on("click", listClientsRefresh);
	$("#torrent-client-props").on("input", listClientsRefresh);

	// проверка доступности торрент-клиентов
	$("#connect-torrent-client").on("click", function () {
		var button = this;
		var numberTorrentClients = $("#list-torrent-clients li.ui-selected").size();
		$("#list-torrent-clients i").remove();
		$("#list-torrent-clients li.ui-selected").each(function () {
			var torrentClientData = this.dataset;
			$.ajax({
				type: "POST",
				url: "php/actions/tor_client_is_online.php",
				context: this,
				data: { tor_client: torrentClientData },
				beforeSend: function () {
					$("#torrent-client-response").text("");
					$(button).children("i").css("display", "inline-block");
					$(button).prop("disabled", true);
					$(this).append('<i class="fa fa-spinner fa-spin"></i>');
					$(this).addClass("ui-connection");
					// torrent-client-response
				},
				success: function (response) {
					response = $.parseJSON(response);
					$("#log").append(response.log);
					$(this).children("i").remove();
					$(this).append(response.status);
					$(this).removeClass("ui-connection");
				},
				complete: function () {
					numberTorrentClients--
					if (numberTorrentClients === 0) {
						var numberCheckedTorrentClients = $("#list-torrent-clients i").size();
						if (numberCheckedTorrentClients > 1) {
							var numberErrors = $("#list-torrent-clients i.text-danger").size();
							if (numberErrors > 0) {
								$("#torrent-client-response").html('<i class="fa fa-circle text-danger"></i> некоторые торрент-клиенты сейчас недоступны');
							} else {
								$("#torrent-client-response").html('<i class="fa fa-circle text-success"></i> все торрент-клиенты сейчас доступны');
							}
						}
						$(button).prop("disabled", false);
						$(button).children("i").hide();
					}
				},
			});
		});
	});

	// при загрузке выбрать первый торрент-клиент в списке
	if ($("#list-torrent-clients li").size() > 0) {
		$("#list-torrent-clients li:nth-child(1)").addClass("ui-selected").trigger("selectablestop");
	} else {
		$(".torrent-client-props").prop("disabled", true);
	}

});

// обновление списка торрент-клиентов
function listClientsRefresh() {
	$("#forum-client option").each(function () {
		if ($(this).val() != 0) {
			$(this).remove();
		}
	});
	$("#list-torrent-clients li").each(function () {
		var torrentClientID = $(this).val();
		var torrentClientData = this.dataset;
		if (torrentClientID != 0) {
			$("#forum-client").append("<option value=\"" + torrentClientID + "\">" + torrentClientData.comment + "</option>");
		}
	});
	if ($("#list-forums option").size() > 0) {
		$("#list-forums").change();
	}
}

// получение списка торрент-клиентов
function getTorClients() {
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
				"ssl": torrentClientData.ssl
			};
		}
	});
	return torrentClients;
}
