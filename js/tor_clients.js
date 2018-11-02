
/* всё про торрент-клиенты */

$(document).ready(function () {

	// последний выбранный т.-клиент
	var tc_change;

	// получение свойств т.-клиента
	$("#list-tcs").on("change", function () {
		var val = $("#list-tcs :selected").attr("data");
		val = val.split('|');
		$("#TC_comment").val(val[0]);
		$("#TC_client [value=" + val[1] + "]").prop("selected", "selected");
		$("#TC_hostname").val(val[2]);
		$("#TC_port").val(val[3]);
		$("#TC_login").val(val[4]);
		$("#TC_password").val(val[5]);
		tc_change = $(this).val();
	});

	// изменение свойств т.-клиента
	$("#tc-prop").on("focusout", function () {
		var cm_old = $("#list-tcs option[value=" + tc_change + "]").text();
		var cm = $("#TC_comment").val() != "" ? $("#TC_comment").val() : tc_change;
		var cl = $("#TC_client").val();
		var ht = $("#TC_hostname").val();
		var pt = $("#TC_port").val();
		var lg = $("#TC_login").val();
		var pw = $("#TC_password").val();
		$("#list-tcs option[value=" + tc_change + "]")
			.attr("data", cm + "|" + cl + "|" + ht + "|" + pt + "|" + lg + "|" + pw)
			.text(cm);
		$("#list-ss option").each(function () {
			var data = $(this).attr("data");
			var arr = data.split("|");
			if (arr[0] == cm_old) {
				$(this).attr("data", data.replace(/^[^|]*/, cm));
			}
		});
		doSortSelect("list-tcs");
	});

	// добавить т.-клиент в список
	$("#add-tc").on("click", function () {
		$("#tc-prop .tc-prop").prop("disabled", false);
		var cm = $("#TC_comment").val();
		var nm = cm.replace(/\d*$/, '');
		var num = cm.replace(nm, '');
		var zero = num.replace(/[^0].*/, '');
		var cl = $("#TC_client").val();
		var ht = $("#TC_hostname").val();
		var pt = $("#TC_port").val();
		var lg = $("#TC_login").val();
		var pw = $("#TC_password").val();
		var q = 1;
		if ($("#list-tcs").val()) {
			var num_new = 0;
			$("#list-tcs option").each(function () {
				var val = parseInt($(this).val());
				q = val > q ? val : q;
				var data = $(this).attr("data");
				data = data.split("|");
				var nm_tmp = data[0].replace(/\d*$/, '');
				var num_tmp = data[0].replace(nm_tmp, '');
				var zero_tmp = num_tmp.replace(/[^0].*/, '');
				if (
					nm_tmp == nm
					&& parseInt(num_tmp) > num_new
					&& zero == zero_tmp
				) {
					num_new = num_tmp;
				}
			});
			num_new++;
			q++;
			var cm_new = nm + '|' + zero + '|' + num_new;
			if (cm.length < cm_new.length - 2) {
				cm_new = cm_new.replace(/\|0*\|/, zero.slice(0, -1));
			} else {
				cm_new = cm_new.replace(/\|/g, '');
			}
			$("#list-tcs").append('<option value="' + q + '" data="' + cm_new + '|' + cl +
				'|' + ht + '|' + pt + '|' + lg + '|' + pw + '">' + cm_new + '</option>');
		} else {
			$("#list-tcs").append('<option value="' + q + '" data="client1|utorrent||||">client1</option>');
		}
		$("#list-tcs option[value=" + q + "]").prop("selected", "selected").change();
		doSortSelect("list-tcs");
	});

	// удалить т.-клиент из списка
	$("#del-tc").on("click", function () {
		if ($("#list-tcs").val()) {
			var i = $("#list-tcs :selected").index();
			$("#list-tcs :selected").remove();
			var q = $("select[id=list-tcs] option").size();
			if (q == 1) {
				$("#tc-prop .tc-prop").val('').prop("disabled", true);
			} else {
				q == i ? i : i++;
				$("#list-tcs :nth-child(" + i + ")").prop("selected", "selected").change();
			}
		}
		$("#list-ss option").each(function () {
			var data = $(this).attr("data");
			var cl = data.split("|");
			var client_id = $("#list-tcs option[value=" + cl[0] + "]").val();
			if (typeof client_id == "undefined") {
				$(this).attr("data", data.replace(/^[^|]*/, "0"));
			}
		});
	});

	// обновление списка т.-клиентовв настройках подразделов
	$("#add-tc, #del-tc").on("click", listClientsRefresh);
	$("#tc-prop").on("focusout", listClientsRefresh);

	// проверка доступности т.-клиента
	$("#online-tc").on("click", function () {
		if ($("#list-tcs").val()) {
			var data = $("#list-tcs :selected").attr("data");
			data = data.split("|");
			$.ajax({
				url: 'php/actions/tor_client_is_online.php',
				type: 'POST',
				context: this,
				data: { tor_client: data },
				beforeSend: function () {
					$("#result-tc").text("");
					$(this).children("i").css("display", "inline-block");
					$(this).prop("disabled", true);
				},
				success: function (response) {
					response = $.parseJSON(response);
					$("#log").append(response.log);
					$("#result-tc").html(response.status);
				},
				complete: function () {
					$(this).prop("disabled", false);
					$(this).children("i").hide();
				},
			});
		}
	});

	// при загрузке выбрать первый т.-клиент в списке
	if ($("select[id=list-tcs] option").size() > 1) {
		$("#list-tcs :nth-child(2)").prop("selected", "selected").change();
	} else {
		$("#tc-prop .tc-prop").prop("disabled", true);
	}

});

// обновление списка т.-клиентов
function listClientsRefresh() {
	$("#ss-client option").each(function () {
		if ($(this).val() != 0) {
			$(this).remove();
		}
	});
	$("#list-tcs option").each(function () {
		var id = $(this).val();
		var client = $(this).attr("data");
		client = client.split("|");
		if (id != 0) {
			$("#ss-client").append('<option value="' + id + '">' + client[0] + '</option>');
		}
	});
	if ($("select[id=list-ss] option").size() > 0) {
		$("#list-ss").change();
	}
}

// получение списка т.-клиентов
function getTorClients() {
	var tor_clients = {};
	$("#list-tcs option").each(function () {
		var value = $(this).val();
		if (value != 0) {
			var data = $(this).attr("data");
			data = data.split("|");
			tor_clients[value] = {
				"cm": data[0],
				"cl": data[1],
				"ht": data[2],
				"pt": data[3],
				"lg": data[4],
				"pw": data[5]
			};
		}
	});
	return tor_clients;
}
