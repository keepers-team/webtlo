
/* работа с топиками */

$(document).ready(function () {

	// скачивание т.-файлов выделенных топиков
	$(".tor_download").on("click", function () {
		var topics_ids = $("#topics").serialize();
		if ($.isEmptyObject(topics_ids)) {
			showResultTopics("Выберите раздачи");
			return false;
		}
		var forum_id = $("#main-subsections").val();
		var replace_passkey = $(this).val();
		var config = $("#config").serialize();
		$("#process").text("Скачивание торрент-файлов...");
		$.ajax({
			type: "POST",
			context: this,
			url: "php/actions/get_torrent_files.php",
			data: {
				cfg: config,
				topics_ids: topics_ids,
				forum_id: forum_id,
				replace_passkey: replace_passkey
			},
			beforeSend: function () {
				block_actions();
			},
			complete: function () {
				block_actions();
			},
			success: function (response) {
				response = $.parseJSON(response);
				$("#log").append(response.log);
				showResultTopics(response.result);
			},
		});
	});

	// "чёрный" список раздач
	$("#tor_blacklist").on("click", function () {
		var topics_ids = $("#topics").serialize();
		if ($.isEmptyObject(topics_ids)) {
			showResultTopics("Выберите раздачи");
			return false;
		}
		var forum_id = $("#main-subsections").val();
		var value = forum_id != -2 ? 1 : 0;
		$("#process").text('Редактирование "чёрного списка" раздач...');
		$.ajax({
			type: "POST",
			url: "php/actions/blacklist.php",
			data: {
				topics_ids: topics_ids,
				value: value
			},
			beforeSend: function () {
				block_actions();
			},
			complete: function () {
				block_actions();
			},
			success: function (response) {
				showResultTopics(response);
				getFilteredTopics();
			}
		});
	});

	// добавление раздач в торрент-клиент
	$("#tor_add").on("click", function () {
		var topics_ids = $("#topics").serialize();
		if ($.isEmptyObject(topics_ids)) {
			showResultTopics("Выберите раздачи");
			return false;
		}
		$("#process").text("Добавление раздач в торрент-клиент...");
		$.ajax({
			type: "POST",
			url: "php/actions/add_topics_to_client.php",
			data: {
				topics_ids: topics_ids
			},
			beforeSend: function () {
				block_actions();
			},
			complete: function () {
				block_actions();
			},
			success: function (response) {
				response = $.parseJSON(response);
				$("#log").append(response.log);
				showResultTopics(response.result);
				getFilteredTopics();
			}
		});
	});

	// управление раздачами (старт, стоп и т.п.)
	$(".torrent_action").on("click", function (e) {
		var topics_ids = $("#topics").serialize();
		if ($.isEmptyObject(topics_ids)) {
			showResultTopics("Выберите раздачи");
			return false;
		}
		var tor_clients = getListTorrentClients();
		if ($.isEmptyObject(tor_clients)) {
			showResultTopics("В настройках не найдены торрент-клиенты");
			return false;
		}
		var action = $(this).val();
		var subsection = $("#main-subsections").val();
		var label = "";
		var remove_data = "";
		var force_start = "";
		if (subsection > 0) {
			var forumData = $("#list-forums [value=" + subsection + "]").data();
			label = forumData.label;
		}
		if (action == "remove") {
			$("#dialog").dialog(
				{
					buttons: [
						{
							text: "Да",
							click: function () {
								remove_data = true;
								execActionTopics(
									topics_ids,
									tor_clients,
									action,
									label,
									force_start,
									remove_data
								);
							}
						},
						{
							text: "Нет",
							click: function () {
								execActionTopics(
									topics_ids,
									tor_clients,
									action,
									label,
									force_start,
									remove_data
								);
							}
						}
					],
					modal: true,
					resizable: false,
					// position: [ 'center', 200 ]
				}
			).text('Удалить загруженные файлы раздач с диска ?');
			$("#dialog").dialog("open");
			return true;
		}
		if (
			action == "set_label"
			&& (
				e.ctrlKey
				|| subsection == 0
				|| subsection == -4
				|| subsection == -5
			)
		) {
			$("#dialog").dialog(
				{
					buttons: [
						{
							text: "ОК",
							click: function () {
								label = $("#any_label").val();
								execActionTopics(
									topics_ids,
									tor_clients,
									action,
									label,
									force_start,
									remove_data
								);
							}
						}
					],
					modal: true,
					resizable: false,
					// position: [ 'center', 200 ]
				}
			).html('<label>Установить метку: <input id="any_label" size="27" />');
			$("#dialog").dialog("open");
			return true;
		}
		execActionTopics(
			topics_ids,
			tor_clients,
			action,
			label,
			force_start,
			remove_data
		);
	});

	// кнопка выделить все / отменить выделение
	$(".tor_select").on("click", function () {
		var value = $(this).val();
		$("#topics").find(".topic[type=checkbox]").prop("checked", Boolean(value));
		getCountSizeSelectedTopics();
	});

	// выделение/снятие выделения интервала раздач
	$("#topics").on("click", ".topic", function (event) {
		var $checkboxes = $("#topics .topic");
		if ($checkboxes.hasClass("last-checked")) {
			if (event.shiftKey) {
				var $lastChecked = $("#topics .last-checked");
				var startIndex = $checkboxes.index(this);
				var endIndex = $checkboxes.index($lastChecked);
				$checkboxes.slice(Math.min(startIndex, endIndex), Math.max(startIndex, endIndex) + 1).prop("checked", $lastChecked[0].checked);
			}
			$checkboxes.removeClass("last-checked");
		}
		$(this).addClass("last-checked");
		getCountSizeSelectedTopics();
	});

	// снять выделение всех раздач по Esc
	$("#topics").on("keyup", function (event) {
		if (event.which == 27) {
			$("#topics .topic").prop("checked", false).removeClass("last-checked");
			getCountSizeSelectedTopics();
		}
	});

	// скрыть/показать фильтр
	$("#filter_show").on("click", function () {
		$("#topics_filter").toggle(500, function () {
			Cookies.set('filter-state', $(this).is(':visible'));
		});
	});

	// сбросить настройки фильтра
	$("#filter_reset").on("click", function () {
		$("#topics_filter input[type=text]").val("");
		$("#topics_filter input[type=search]").val("");
		$("#topics_filter input[type=radio], #topics_filter input[type=checkbox]").prop("checked", false);
		$("#filter_date_release").datepicker("setDate", "-" + $("#rule_date_release").val());
		$("#filter_rule, #filter_rule_to").val($("#rule_topics").val());
		$("#filter_rule_from").val(0);
		$("#filter_avg_seeders_period").val($("#avg_seeders_period").val());
		$(".filter_rule_interval").hide();
		$(".filter_rule_one").show();
		$("#topics_filter .default").prop("checked", true).change();
	});

	// вкл/выкл интервал сидов
	$("input[name=filter_interval]").on("change", function () {
		$(".filter_rule_interval").toggle(500);
		$(".filter_rule_one").toggle(500);
	});

	// есть/нет хранители
	$(".topics_filter .keepers").on("change", function () {
		if ($(this).prop("checked")) {
			switch ($(this).attr("name")) {
				case "not_keepers":
					$("input[name=is_keepers]").prop("checked", false);
					break;
				case "is_keepers":
					$("input[name=not_keepers]").prop("checked", false);
					break;
			}
		}
	});

	$("#topics_filter").on("change input spinstop", function () {
		// запоминаем параметры фильтра в куки
		Cookies.set("filter-options", $("#topics_filter").serializeAllArray());
		if ($("#enable_auto_apply_filter").prop("checked")) {
			filter_delay(getFilteredTopics);
		}
	});

	// есть/нет сиды-хранители
	$(".topics_filter .keepers_seeders").on("change", function () {
		if ($(this).prop("checked")) {
			switch ($(this).attr('name')) {
				case 'not_keepers_seeders':
					$("input[name=is_keepers_seeders]").prop("checked", false);
					break;
				case 'is_keepers_seeders':
					$("input[name=not_keepers_seeders]").prop("checked", false);
					break;
			}
		}
	});

	// ник хранителя в поиск при двойном клике
	$("#topics").on("dblclick", ".keeper", function (e) {
		var searchLine = "";
		var searchBox = $("input[name=filter_phrase]");
		if (e.ctrlKey) {
			searchLine = searchBox.val() + "," + $(this).text();
			searchBox.val(searchLine);
		} else {
			searchLine = $(this).text();
			searchBox.val(searchLine);
		}
		selectBlockText(this);
		$('input[name=filter_by_phrase][type="radio"]').prop("checked", false);
		$('#filter_by_keeper').prop("checked", true);
		var isKeepers = $('input[name=is_keepers][type="checkbox"]').prop("checked");
		var isKeepersSeeders = $('input[name=is_keepers_seeders][type="checkbox"]').prop("checked");
		if (
			!isKeepers
			&& !isKeepersSeeders
		) {
			$('input[name=not_keepers_seeders][type="checkbox"]').prop("checked", false);
			$('input[name=not_keepers][type="checkbox"]').prop("checked", false).change();
		}
	});

	// очистка topics_result при изменениях на странице
	$("#topics_data").on("change input spin", showResultTopics);

	// загрузка параметров фильтра из кук
	var filter_state = Cookies.get("filter-state");
	var filter_options = Cookies.get("filter-options");
	if (filter_state === "false") {
		$("#topics_filter").hide();
	}
	if (typeof filter_options !== "undefined") {
		filter_options = $.parseJSON(filter_options);
		$("#topics_filter input[type=radio], #topics_filter input[type=checkbox]").prop("checked", false);
		$.each(filter_options, function (i, option) {
			// пропускаем дату регистрации до
			if (option.name == "filter_date_release") {
				return true;
			}
			$("#topics_filter input[name='" + option.name + "']").each(function () {
				if (
					$(this).attr("type") == "checkbox"
					|| $(this).attr("type") == "radio"
				) {
					if ($(this).val() == option.value) {
						$(this).prop("checked", true);
					}
				} else if (this.name == option.name) {
					$(this).val(option.value);
				}
			});
		});
		// FIXME !!!
		if ($("#topics_filter [name=filter_interval]").prop("checked")) {
			$(".filter_rule_interval, .filter_rule_one").toggle(500);
		}
	}

	// отобразим раздачи на главной
	getFilteredTopics();

	// проверим наличие новой версии
	checkNewVersion();
});


// задержка при выборе свойств фильтра
var filter_delay = makeDelay(600);

// подавление срабатывания фильтрации раздач
var filter_hold = false;

// получение отфильтрованных раздач из базы
function getFilteredTopics() {
	var forum_id = $("#main-subsections").val();
	// блокировка фильтра
	if (
		forum_id > 0
		|| forum_id == -3
		|| forum_id == -5
	) {
		$(".topics_filter input").removeClass("ui-state-disabled").prop("disabled", false);
		$("#toolbar-new-torrents").buttonset("enable");
		$("#toolbar-control-topics").buttonset("enable");
		$("#filter_avg_seeders_period").spinner("enable");
		$("#filter_rule").spinner("enable");
		$("#filter_rule_from").spinner("enable");
		$("#filter_rule_to").spinner("enable");
		$("#filter_date_release").datepicker("enable");
		if (forum_id == -5) {
			$("#tor_add").button("disable");
			$(".topics_filter input[name^='keeping_priority']").addClass("ui-state-disabled").prop("disabled", true);
		} else {
			$("#tor_add").button("enable");
			$(".topics_filter input[name^='keeping_priority']").removeClass("ui-state-disabled").prop("disabled", false);
		}
	} else {
		if (forum_id == -2) {
			$("#toolbar-control-topics").buttonset("disable");
			$("#tor_blacklist").button("enable");
		} else {
			$("#toolbar-control-topics").buttonset("enable");
			$("#tor_blacklist").button("disable");
		}
		$(".topics_filter input").addClass("ui-state-disabled").prop("disabled", true);
		$(".topics_filter input.sort").removeClass("ui-state-disabled").prop("disabled", false);
		$("#toolbar-new-torrents").buttonset("disable");
		$("#filter_avg_seeders_period").spinner("disable");
		$("#filter_rule").spinner("disable");
		$("#filter_rule_from").spinner("disable");
		$("#filter_rule_to").spinner("disable");
		$("#filter_date_release").datepicker("disable");
		if (forum_id == -4) {
			$("#filter_avg_seeders_period").spinner("enable");
			$(".tor_remove").button("disable");
		}
	}
	// сериализим параметры фильтра
	var $filter = $("#topics_filter").serialize();
	$("#process").text("Получение данных о раздачах...");
	$.ajax({
		type: "POST",
		url: "php/actions/get_filtered_list_topics.php",
		data: {
			forum_id: forum_id,
			filter: $filter,
		},
		beforeSend: function () {
			block_actions();
		},
		complete: function () {
			block_actions();
		},
		success: function (response) {
			response = $.parseJSON(response);
			if (response.log.length) {
				showResultTopics(response.log);
			}
			if (response.topics != null) {
				$("#topics").html(response.topics);
				$("#filtered_topics_count").text(response.count);
				$("#filtered_topics_size").text(сonvertBytes(response.size));
			}
			showCountSizeSelectedTopics();
		}
	});
}

// вывод на экран кол-во, объём выделенных раздач
function showCountSizeSelectedTopics(count = 0, size = 0.00) {
	$("#topics_count").text(count);
	$("#topics_size").text(сonvertBytes(size));
}

// получение кол-ва, объёма выделенных раздач
function getCountSizeSelectedTopics() {
	var count = 0;
	var size = 0.00;
	var topics = $("#topics").find(".topic[type=checkbox]:checked");
	if (!$.isEmptyObject(topics)) {
		topics.each(function () {
			var data = this.dataset;
			size += parseInt(data.size);
			count++;
		});
	}
	showCountSizeSelectedTopics(count, size);
}

// действия с выбранными раздачами (старт, стоп, метка, удалить)
function execActionTopics(topics_ids, tor_clients, action, label, force_start, remove_data) {
	$("#dialog").dialog("close");
	$("#process").text("Управление раздачами...");
	$.ajax({
		type: "POST",
		context: this,
		url: "php/actions/exec_actions_topics.php",
		data: {
			topics_ids: topics_ids,
			tor_clients: tor_clients,
			action: action,
			remove_data: remove_data,
			force_start: force_start,
			label: label
		},
		beforeSend: function () {
			block_actions();
		},
		complete: function () {
			block_actions();
		},
		success: function (response) {
			response = $.parseJSON(response);
			$("#log").append(response.log);
			showResultTopics(response.result);
			if (action == 'remove') {
				getFilteredTopics();
			}
		}
	});
}
