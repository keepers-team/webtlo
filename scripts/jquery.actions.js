$(document).ready(function () {

	// обновление сведений о раздачах
	$("#update_info").on("click", function () {
		$.ajax({
			type: "POST",
			url: "php/actions/update_info.php",
			beforeSend: function () {
				block_actions();
				$("#process").text("Обновление сведений о раздачах...");
			},
			success: function (response) {
				response = $.parseJSON(response);
				$("#log").append(response.log);
				showResultTopics(response.result);
				getFilteredTopics();
			},
			complete: function () {
				block_actions();
			},
		});
	});

	// отправка отчётов
	$("#send_reports").on("click", function () {
		$.ajax({
			type: "POST",
			url: "php/actions/send_reports.php",
			beforeSend: function () {
				block_actions();
				$("#process").text("Отправка отчётов на форум...");
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

	// регулировка раздач
	$("#control_torrents").on("click", function () {
		$.ajax({
			type: "POST",
			url: "php/actions/control_torrents.php",
			beforeSend: function () {
				block_actions();
				$("#process").text("Регулировка раздач...");
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
	$("#savecfg").on("click", setSettings);

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
		$(this).addClass("ui-state-disabled").prop("disabled", true);
		var check_list = ['forum', 'api'];
		var check_count = check_list.length;
		var result_list = ['text-danger', 'text-success'];
		var $data = $("#config").serialize();
		$.each(check_list, function (index, value) {
			var element = "#" + value + "_url";
			var url = $(element).val();
			var url_custom = $(element + "_custom").val();
			var ssl = $("#" + value + "_ssl").val();
			$(element).addClass("ui-state-disabled").prop("disabled", true);
			$(element + "_custom").addClass("ui-state-disabled").prop("disabled", true);
			$("#" + value + "_ssl").addClass("ui-state-disabled").prop("disabled", true);
			if (typeof url === "undefined" || $.isEmptyObject(url)) {
				check_count--;
				if (check_count == 0) {
					$("#check_mirrors_access").removeClass("ui-state-disabled").prop("disabled", false);
				}
				$(element + "_params i").removeAttr("class");
				$(element).removeClass("ui-state-disabled").prop("disabled", false);
				$(element + "_custom").removeClass("ui-state-disabled").prop("disabled", false);
				$("#" + value + "_ssl").removeClass("ui-state-disabled").prop("disabled", false);
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
					$(element).removeClass("ui-state-disabled").prop("disabled", false);
					$(element + "_custom").removeClass("ui-state-disabled").prop("disabled", false);
					$("#" + value + "_ssl").removeClass("ui-state-disabled").prop("disabled", false);
					var result = result_list[response];
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
					if (check_count == 0) {
						$("#check_mirrors_access").removeClass("ui-state-disabled").prop("disabled", false);
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
		$(this).addClass("ui-state-disabled").prop("disabled", true);
		var cap_code = $("#cap_code").val();
		var cap_fields = $("#cap_fields").val();
		var $data = $("#config").serialize();
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
					$("#forum_auth_result").removeAttr("class").addClass("fa fa-circle text-danger");
					$("#dialog").dialog(
						{
							buttons: [
								{
									text: "OK",
									click: function () {
										var username_correct = $("#tracker_username_correct").val();
										var password_correct = $("#tracker_password_correct").val();
										$("#tracker_username").val(username_correct);
										$("#tracker_password").val(password_correct);
										$("#forum_auth").click();
										$("#dialog").dialog("close");
									},
								},
							],
							modal: true,
							resizable: false,
							// position: [ 'center', 200 ]
						}
					).html('<span class="text-danger">Вы видите это сообщение, потому что ввели неверные логин и/или пароль</span><br /><br />' +
						'Введите правильные данные для авторизации на форуме RuTracker.org ниже и нажмите "ОК"<br /><br />' +
						'Логин: <input type="text" class="myinput" id="tracker_username_correct" /><br />' +
						'Пароль: <input class="myinput" type="text" id="tracker_password_correct" /><br /><br />' +
						'Введите текст с картинки: <input class="myinput" type="hidden" id="cap_fields" value="' + response.captcha.join(',') + '" />' +
						'<div><img src="data/captcha.jpg?' + new Date().valueOf() + '" /></div>' +
						'<input id="cap_code" size="27" />');
					$("#dialog").dialog("open");
				} else {
					$("#forum_auth_result").removeAttr("class");
					if (
						!$.isEmptyObject(response.bt_key)
						&& !$.isEmptyObject(response.api_key)
						&& !$.isEmptyObject(response.user_id)
					) {
						$("#forum_auth_result").addClass("fa fa-circle text-success");
						$("#bt_key").val(response.bt_key);
						$("#api_key").val(response.api_key);
						$("#user_id").val(response.user_id);
						setSettings();
					} else {
						$("#forum_auth_result").addClass("fa fa-circle text-danger");
					}
				}
			},
			beforeSend: function () {
				$("#forum_auth_result").removeAttr("class");
				$("#forum_auth_result").addClass("fa fa-spinner fa-spin");
			},
			complete: function () {
				$(this).removeClass("ui-state-disabled").prop("disabled", false);
			}
		});
	});

	$("#forum_auth_params, #api_auth_params").on("input", function () {
		$("#forum_auth_result").removeAttr("class");
	});

	$("#forum_auth_params, #api_auth_params").on("keypress", function () {
		var disabled = $("#forum_auth").prop("disabled");
		if (typeof disabled !== "undefined") {
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
		$("#log").text("");
	});

	// чтение лога из файла
	$("#log_tabs").on("tabsactivate", function (event, ui) {
		// current tab
		var element_new = $(ui.newTab).children("a");
		var name_new = $(element_new).text();
		if (!element_new.hasClass("log_file")) {
			return false;
		}
		// previous tab
		var element_old = $(ui.oldTab).children("a");
		var name_old = $(element_old).text();
		if (element_old.hasClass("log_file")) {
			$("#log_" + name_old).text("");
		}
		// request
		$.ajax({
			type: "POST",
			url: "php/actions/get_log_content.php",
			data: {
				log_file: name_new
			},
			success: function (response) {
				if (typeof response !== "undefined") {
					$("#log_" + name_new).html(response);
				}
			},
			beforeSend: function () {
				$("#log_" + name_new).html("<i class=\"fa fa-spinner fa-pulse\"></i>");
			}
		});
	});

});
