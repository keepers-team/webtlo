
/* работа с топиками */

// получить список выделенных раздач
function listSelectedTopics(){
	var topics = [];
	$("#topics").closest("div")
	.find("input[type=checkbox]")
	.each(function() {
		if($(this).prop("checked")) {
			id = $(this).attr("id");
			hash = $(this).attr("hash");
			client = $(this).attr("client");
			topics.push({id: id, hash: hash, client: client});
		}
	});
	return topics;
}

// скачивание т.-файлов выделенных топиков
$(".tor_download").on("click", function(){
	subsection = $("#subsections").val();
	edit = $(this).val();
	topics = listSelectedTopics.apply();
	if(topics == "") return;
	$("#log").append(nowTime() + "Начат процесс скачивания торрент-файлов...<br />");
	$data = $("#config").serialize();
	$.ajax({
		type: "POST",
		context: this,
		url: "actions.php",
		data: { topics:topics, m:'download', subsec:subsection, cfg:$data, edit:edit },
		success: function(response) {
			var resp = eval("(" + response + ")");
			$("#log").append(resp.log);
			$("#topics_result").html(resp.dl_log);
			//~ $("#log").html(response);
		},
		beforeSend: function() {
			block_actions();
			$(this).children(".loading").show();
		},
		complete: function() {
			$("#topics_list_"+subsection).closest("div")
			.find("input[type=checkbox]")
			.each(function() {
			    $(this).removeAttr("checked");
			});
			block_actions();
			//~ $("#log").append(nowTime() + "Скачивание торрент-файлов завершено.<br />");
		},
	});
});

// добавление раздач в торрент-клиент
$(".tor_add").on("click", function(){
	subsection = $("#subsections").val();
	topics = listSelectedTopics.apply();
	if(topics == '') return;
	if(!$("#list-ss [value="+subsection+"]").val()){
		$("#topics_result").html("В настройках подразделов нет такого идентификатора: "+subsection+".<br />");
		return;
	}
	ss_data = $("#list-ss [value="+subsection+"]").attr("data");
	tmp = ss_data.split("|");
	if(tmp[0] == "" && tmp[0] == 0){
		$("#topics_result").html("В настройках текущего подраздела не указан используемый торрент-клиент.<br />");
		return;
	}
	value = $("#list-tcs option").filter(function() {
		return $(this).text() == tmp[0];
	}).val();
	if(!value){
		$("#topics_result").html("В настройках нет такого торрент-клиента: "+tmp[0]+"<br />");
		return;
	}
	cl_data = $("#list-tcs option").filter(function() {
		return $(this).text() == tmp[0];
	}).attr("data");
	$data = $("#config").serialize();
	$.ajax({
		type: "POST",
		context: this,
		url: "php/add_topics_to_client.php",
		data: { topics:topics, client:cl_data, subsec:ss_data, cfg:$data },
		success: function(response) {
			var resp = eval("(" + response + ")");
			$("#log").append(resp.log);
			$("#topics_result").html(resp.add_log);
			//~ $("#log").append(response);
			if(resp.success != null){
				// помечаем в базе добавленные раздачи
			    $.ajax({
				    type: "POST",
				    context: this,
					url: "php/mark_topics_in_database.php",
					data: { success:resp.success, status:-1, client:value },
					success: function(response) {
						$("#log").append(response);
						getFilteredTopics.apply(this);
					},
				});
			}
		},
		beforeSend: function() {
			block_actions();
			$(this).children(".loading").show();
		},
		complete: function() {
			block_actions();
		},
	});
})

// действия с выбранными раздачами (старт, стоп, метка, удалить)
function exec_action_for_topics(){
	$("#dialog").dialog("close");
	$.ajax({
		type: "POST",
		context: this,
		url: "php/exec_actions_topics.php",
		data: { topics:topics, clients:clients, action:action, remove_data:remove_data, force_start:force_start, label:label },
		success: function(response) {
			resp = $.parseJSON(response);
			$("#log").append(resp.log);
			$("#topics_result").html(resp.result);
			//~ $("#log").append(response);
			if(resp.ids != null && action == 'remove'){
				status = subsection == 0 ? '' : 0;
				// помечаем в базе удалённые раздачи
			    $.ajax({
				    type: "POST",
				    context: this,
					url: "php/mark_topics_in_database.php",
					data: { success:resp.ids, status:status, client:'' },
					success: function(response) {
						$("#log").append(response);
						getFilteredTopics.apply(this);
					},
				});
			}
		},
		beforeSend: function() {
			block_actions();
			$(this).children(".loading").show();
		},
		complete: function() {
			block_actions();
		},
	});
}

$(".torrent_action").on("click", function(e){
	var button = this;
	remove_data = ""; force_start = ""; label = "";
	subsection = $("#subsections").val();
	action = $(this).val();
	topics = listSelectedTopics.apply(); if(topics == '') return;
	clients = listTorClients();
	if( subsection > 0 ) {
		data = $("#list-ss [value="+subsection+"]").attr("data");
		data = data.split("|");
		label = data[1];
	}
	if(action == 'remove'){
		$("#dialog").dialog({
			buttons: [{ text: "Да", click: function() { remove_data = true; exec_action_for_topics.apply(button); }},
				{ text: "Нет", click: function() { exec_action_for_topics.apply(button); }}],
			modal: true,
			resizable: false,
			//~ position: [ 'center', 200 ]
		}).text('Удалить загруженные файлы раздач с диска ?');
		$("#dialog").dialog("open");
		return;
	}
	if(action == 'set_label' && (e.ctrlKey || subsection == 0)){
		$("#dialog").dialog({
			buttons: [{ text: "ОК", click: function() { label = $("#any_label").val(); exec_action_for_topics.apply(button); }}],
			modal: true,
			resizable: false,
			//~ position: [ 'center', 200 ]
		}).html('<label>Установить метку: <input id="any_label" size="27" />');
		$("#dialog").dialog("open");
		return;
	}
	exec_action_for_topics.apply(this);
});

// вывод на экран кол-во, объём выбранных раздач
function showSizeAndAmount(count, size, filtered){
	var topics_count = filtered ? "#filtered_topics_count" : "#topics_count";
	var topics_size = filtered ? "#filtered_topics_size" : "#topics_size";
	$(topics_count).text(count);
	$(topics_size).text(сonvertBytes(size));
}

function Counter() {
	this.count = 0;
	this.size_all = 0
}

function addSizeAndAmount(input) {
	var size = input.attr("size");
	this.size_all += parseInt(size);
	this.count++;
}

// получение данных и вывод на экран кол-во, объём выделенных/остортированных раздач
function countSizeAndAmount(thisElem) {
	var action = 0;
	if (thisElem !== undefined){
		action = thisElem.val();
	}
	var counter = new Counter();
	var topics = $("#topics").find("input[type=checkbox]");
	if (topics.length === 0){
		showSizeAndAmount(0, 0, true);
	} else {
		topics.each(function () {
			switch (action) {
				case "select":
					$(this).prop("checked", "true");
					addSizeAndAmount.call(counter, $(this));
					showSizeAndAmount(counter.count, counter.size_all);
					break;
				case "unselect":
					$(this).removeAttr("checked");
					showSizeAndAmount(0, 0);
					break;
				case "on":
					if ($(this).prop("checked")) {
						addSizeAndAmount.call(counter, $(this));
					}
					showSizeAndAmount(counter.count, counter.size_all);
					break;
				default:
					addSizeAndAmount.call(counter, $(this));
					showSizeAndAmount(counter.count, counter.size_all, true);
			}
		});
	}
}

// кнопка выделить все / отменить выделение
$(".tor_select, .tor_unselect").on("click", function(){
	countSizeAndAmount($(this))
});

// выделение/снятие выделения интервала раздач
$("#topics").on("click", ".topic", function(event){
	subsection = $("#subsections").val();
	if(!$("#topics .topic").hasClass("first-topic")){
		$(this).addClass("first-topic");
		countSizeAndAmount($(this));
		return;
	}
	if(event.shiftKey){
		tag = parseInt($(this).attr("tag")); // 2 - 20 = -18; 10 - 2 = 8;
		tag_first = parseInt($("#topics .first-topic").attr("tag"));
		direction = (tag_first - tag < 0 ? 'down' : 'up');
		$("#topics").closest("div")
		.find("input[type=checkbox]")
		.each(function(){
			if(direction == 'down'){
				if(parseInt($(this).attr("tag")) >= tag_first && parseInt($(this).attr("tag")) <= tag){
					if(!event.ctrlKey) $(this).prop("checked", "true");
					else $(this).removeAttr("checked");
				}
			}
			if(direction == 'up'){
				if(parseInt($(this).attr("tag")) <= tag_first && parseInt($(this).attr("tag")) >= tag){
					if(!event.ctrlKey) $(this).prop("checked", "true");
					else $(this).removeAttr("checked");
				}
			}
		});
	}
	countSizeAndAmount($(this));
	$("#topics .first-topic").removeClass("first-topic");
	$(this).addClass("first-topic");
});

// фильтр

// вкл/выкл интервал сидов
$("input[name=filter_interval]").on("click", function(){
	$(".filter_rule_interval").toggle(500);
	$(".filter_rule_one").toggle(500);
});

// сортировка по хранителю при двойном клике по его никнейму в списке раздач
$(document).on("dblclick",".keeper",function(e){
	$("input[name=filter_phrase]").val($(this).text());
	$('input[name=filter_by_phrase][type="radio"]').prop("checked", false);
	$('#filter_by_keeper').prop("checked", true);
	$('input[name=is_keepers][type="checkbox"]').prop("checked", true).change();
});

// получение отфильтрованных раздач из базы
function getFilteredTopics(){
	forum_id = $("#subsections").val();
	$config = $("#config").serialize();
	$filter = $("#topics_filter").serialize();
	$.ajax({
		type: "POST",
		url: "php/actions/get_filtered_list_topics.php",
		data: { forum_id: forum_id, config: $config, filter: $filter },
		success: function(response) {
			response = $.parseJSON(response);
			if( response.log ) $("#topics_result").html(response.log);
			if( response.topics != null ) $("#topics").html(response.topics);
			//~ $("#log").append(response);
		},
		beforeSend: function() {
			block_actions();
		},
		complete: function() {
			block_actions();
			countSizeAndAmount()
		}
	});
}

// скрыть/показать фильтр
$("#filter_show").on("click", function() {
	$("#topics_filter").toggle(500);
});

// сбросить настройки фильтра
$("#filter_reset").on("click", function() {
	$("#topics_filter input[type=text]").val("");
	$("#topics_filter input[type=search]").val("");
	$("#topics_filter input[type=radio], #topics_filter input[type=checkbox]").prop("checked", false);
	$("#filter_date_release").datepicker("setDate", "-"+$("#rule_date_release").val());
	$("#filter_rule, #filter_rule_to").val($("#TT_rule_topics").val());
	$("#filter_rule_from").val(0);
	$("#filter_avg_seeders_period").val($("#avg_seeders_period").val());
	$(".filter_rule_interval").hide();
	$(".filter_rule_one").show();
	$("#topics_filter .default").prop("checked", true).change();
});

// события при выборе свойств фильтра
var delay = makeDelay (500);
$("#topics_filter").find("input[type=text], input[type=search]").on("spin input", function() {
	delay( getFilteredTopics, this );
	showSizeAndAmount( 0, 0.00 );
});

$("#topics_filter input[type=radio], #topics_filter input[type=checkbox]").on("change", function() {
	delay( getFilteredTopics, this );
	showSizeAndAmount( 0, 0.00 );
});

$("#filter_date_release").on("change", function() {
	delay( getFilteredTopics, this );
	showSizeAndAmount( 0, 0.00 );
});

// есть/нет хранители
$(".topics_filter .keepers").on("change", function(){
	if ( $(this).prop("checked") ) {
		switch ( $(this).attr('name') ) {
			case 'not_keepers':
				$("input[name=is_keepers]").prop("checked", false);
				break;
			case 'is_keepers':
				$("input[name=not_keepers]").prop("checked", false);
				break;
		}
	}
});

$(window).on( "load", getFilteredTopics );
