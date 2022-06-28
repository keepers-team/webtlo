
/* вспомогательные функции */

/* текущее время */
function nowTime() {
	var now = new Date();
	var day = (now.getDate() < 10 ? "0" : "") + now.getDate();
	var month = (parseInt(now.getMonth() + 1) < 10 ? "0" : "") + parseInt(now.getMonth() + 1);
	var year = now.getFullYear();
	var hours = (now.getHours() < 10 ? "0" : "") + now.getHours();
	var minutes = (now.getMinutes() < 10 ? "0" : "") + now.getMinutes();
	var seconds = (now.getSeconds() < 10 ? "0" : "") + now.getSeconds();
	return day + "." + month + "." + year + " " + hours + ":" + minutes + ":" + seconds + " ";
}

/* перевод байт */
function сonvertBytes(size) {
	var filesizename = [" Bytes", " KB", " MB", " GB", " TB", " PB", " EB", " ZB", " YB"];
	return size ? (size / Math.pow(1024, (i = Math.floor(Math.log(size) / Math.log(1024))))).toFixed(2) + filesizename[i] : "0.00";
}

function showResultTopics(text = "") {
	$("#topics_result").html(text);
}

var lock_actions = 0;

function block_actions() {
	if (lock_actions == 0) {
		$("#topics_control button").addClass("ui-state-disabled").prop("disabled", true);
		$("#main-subsections").selectmenu("disable");
		$("#loading, #process").show();
		lock_actions = 1;
	} else {
		$("#topics_control button").removeClass("ui-state-disabled").prop("disabled", false);
		if (
			$("#main-subsections").val() < 1
			|| !$("input[name=filter_status]").eq(1).prop("checked")
		) {
			$(".tor_add").addClass("ui-state-disabled").prop("disabled", true);
		} else {
			$(".tor_stop, .tor_remove, .tor_label, .tor_start").addClass("ui-state-disabled").prop("disabled", true);
		}
		$("#main-subsections").selectmenu("enable");
		$("#loading, #process").hide();
		lock_actions = 0;
	}
}
// выполнить функцию с задержкой
function makeDelay(ms) {
	var timer = 0;
	return function (callback, scope) {
		clearTimeout(timer);
		timer = setTimeout(function () {
			callback.apply(scope);
		}, ms);
	}
}

function functionDelay(callback, ms) {
	var timer = 0;
	return function () {
		var context = this, args = arguments;
		clearTimeout(timer);
		timer = setTimeout(function () {
			callback.apply(context, args);
		}, ms);
	};
}

// сортировка в select
function doSortSelect(selectID, sortElement = "option") {
	$("#" + selectID).toggle();
	var sortedVals = $.makeArray($("#" + selectID + " " + sortElement)).sort(function (a, b) {
		if ($(a).val() == 0) {
			return -1;
		}
		var textA = $(a).text().toUpperCase();
		var textB = $(b).text().toUpperCase();
		return textA.localeCompare(textB, undefined, { numeric: true, sensitivity: "base" });
	});
	$("#" + selectID).empty().html(sortedVals).toggle();
}

function doSortSelectByValue(selectID, sortElement = "option") {
	$("#" + selectID).toggle();
	var sortedVals = $.makeArray($("#" + selectID + " " + sortElement)).sort(function (a, b) {
		if ($(a).val() == 0) {
			return -1;
		}
		var textA = $(a).text().toUpperCase();
		var textB = $(b).text().toUpperCase();
		return textA.localeCompare(textB, undefined, { numeric: true, sensitivity: "base" });
	});
	$("#" + selectID).empty().html(sortedVals).toggle();
}

// сохранение настроек
function setSettings() {
	var forums = getForums();
	var tor_clients = getListTorrentClients();
	var $data = $("#config").serialize();
	$.ajax({
		context: this,
		type: "POST",
		url: "php/actions/set_config.php",
		data: {
			cfg: $data,
			forums: forums,
			tor_clients: tor_clients
		},
		beforeSend: function () {
			$(this).addClass("ui-state-disabled").prop("disabled", true);
		},
		success: function (response) {
			$("#log").append(response);
		},
		complete: function () {
			$(this).removeClass("ui-state-disabled").prop("disabled", false);
		},
	});
}

// получение отчётов
function getReport() {
	var forum_id = $("#reports-subsections").val();
	if ($.isEmptyObject(forum_id)) {
		return false;
	}
	$.ajax({
		type: "POST",
		url: "php/actions/get_reports.php",
		data: {
			forum_id: forum_id
		},
		beforeSend: function () {
			$("#reports-subsections").selectmenu("disable");
			$("#reports-content").html("<i class=\"fa fa-spinner fa-pulse\"></i>");
		},
		success: function (response) {
			response = $.parseJSON(response);
			$("#log").append(response.log);
			$("#reports-content").html(response.report);
			//инициализация "аккордиона" сообщений
			$("#reports-content .report_message").each(function () {
				$(this).accordion({
					collapsible: true,
					heightStyle: "content"
				});
			});
			// выделение тела сообщения двойным кликом
			$("#reports-content .ui-accordion-content").dblclick(function () {
				selectBlockText(this)
			});
		},
		complete: function () {
			$("#reports-subsections").selectmenu("enable");
		},
	});
}

// выделить тело объекта
function selectBlockText(e) {
	if (window.getSelection) {
		var s = window.getSelection();
		if (s.setBaseAndExtent) {
			s.setBaseAndExtent(e, 0, e, e.childNodes.length);
		} else {
			var r = document.createRange();
			r.selectNodeContents(e);
			s.removeAllRanges();
			s.addRange(r);
		}
	} else if (document.getSelection) {
		var s = document.getSelection();
		var r = document.createRange();
		r.selectNodeContents(e);
		s.removeAllRanges();
		s.addRange(r);
	} else if (document.selection) {
		var r = document.body.createTextRange();
		r.moveToElementText(e);
		r.select();
	}
}

// проверить наличие новой версии
function checkNewVersion() {
	var current_version = $("title").text().split("-")[2];
	var new_version_last_checked = Cookies.get("new-version-last-checked");
	if (
		new_version_last_checked !== undefined
		&& ($.now() - new_version_last_checked) <= 60000
	) {
		if (versionCompare(current_version, Cookies.get("new-version-number")) < 0) {
			showNewVersion(
				Cookies.get("new-version-number"),
				Cookies.get("new-version-link"),
				Cookies.get("new-version-whats-new")
			);
		}
		return;
	}
	$.ajax({
		type: "POST",
		url: "php/actions/check_new_version.php",
		success: function (response) {
			$("#log").append(response.log);
			response = $.parseJSON(response);
			Cookies.set("new-version-number", response.newVersionNumber);
			Cookies.set("new-version-link", response.newVersionLink);
			Cookies.set("new-version-whats-new", response.whatsNew);
			Cookies.set("new-version-last-checked", $.now());
			if (versionCompare(current_version, response.newVersionNumber) < 0) {
				showNewVersion(response.newVersionNumber, response.newVersionLink, response.whatsNew)
			}
		},
	});
}

function setUITheme(){
	var jqueryUIURL = "https://ajax.googleapis.com/ajax/libs/jqueryui/" + jqueryUIVersion + "/themes/" + currentUITheme + "/jquery-ui.css";
    var jqueryUIStyle = $("<link/>")
        .attr("type", "text/css")
        .attr("rel", "stylesheet")
        .attr("href", jqueryUIURL);
    jqueryUIStyle.appendTo("head");
}

function showNewVersion(newVersionNumber, newVersionLink, whatsNew) {
	$("#new_version_description")
		.attr("title", whatsNew)
		.text("Доступна новая версия: ")
		.append('<a id="new_version_link" href="' + newVersionLink + '">' + newVersionNumber + "</a>");
	$("#new_version_available").show();
}

// http://stackoverflow.com/a/6832721/50079
function versionCompare(v1, v2, options) {
	var lexicographical = options && options.lexicographical,
		zeroExtend = options && options.zeroExtend,
		v1parts = v1.split('.'),
		v2parts = v2.split('.');
	function isValidPart(x) {
		return (lexicographical ? /^\d+[A-Za-z]*$/ : /^\d+$/).test(x);
	}
	if (!v1parts.every(isValidPart) || !v2parts.every(isValidPart)) {
		return NaN;
	}
	if (zeroExtend) {
		while (v1parts.length < v2parts.length) v1parts.push("0");
		while (v2parts.length < v1parts.length) v2parts.push("0");
	}
	if (!lexicographical) {
		v1parts = v1parts.map(Number);
		v2parts = v2parts.map(Number);
	}
	for (var i = 0; i < v1parts.length; ++i) {
		if (v2parts.length == i) {
			return 1;
		}

		if (v1parts[i] == v2parts[i]) {
			continue;
		}
		else if (v1parts[i] > v2parts[i]) {
			return 1;
		}
		else {
			return -1;
		}
	}
	if (v1parts.length != v2parts.length) {
		return -1;
	}
	return 0;
}

// https://stackoverflow.com/questions/15958671/disabled-fields-not-picked-up-by-serializearray
(function ($) {
	$.fn.serializeAllArray = function () {
		var data = $(this).serializeArray();
		$(":disabled[name]", this).each(function () {
			if (
				(
					$(this).attr("type") === "checkbox"
					|| $(this).attr("type") === "radio"
				) && !$(this).prop("checked")
			) {
				return true;
			}
			data.push(
				{
					name: this.name,
					value: $(this).val()
				}
			);
		});
		return data;
	}
})(jQuery);
