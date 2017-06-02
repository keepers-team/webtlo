
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
function showSelectedInfo(count, size){
	$("#topics_result").html('Выбрано раздач: <span id="topics_count" class="rp-header"></span> (<span id="topics_size"></span>).');
	$("#topics_count").text(count);
	$("#topics_size").text(сonvertBytes(size));
}

// кнопка выделить все / отменить выделение
$(".tor_select, .tor_unselect").on("click", function(){
	action = $(this).val();
	count = 0;
	size_all = 0;
	$("#topics").closest("div")
	.find("input[type=checkbox]")
	.each(function() {
		switch (action) {
			case "select":
				size = $(this).attr("size");
				$(this).prop("checked", "true");
				size_all += parseInt(size);
				count++;
				break;
			case "unselect":
				$(this).removeAttr("checked");
				break;
		}
	});
	showSelectedInfo(count, size_all);
});

// выделение/снятие выделения интервала раздач
$("#topics").on("click", ".topic", function(event){
	subsection = $("#subsections").val();
	if(!$("#topics .topic").hasClass("first-topic")){
		$(this).addClass("first-topic");
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
	$("#topics .first-topic").removeClass("first-topic");
	$(this).addClass("first-topic");
});

// получение данных о выделенных раздачах (объём, кол-во)
$("#topics").on("click", ".topic", function(){
	count = 0;
	size_all = 0;
	$("#topics").closest("div")
	.find("input[type=checkbox]")
	.each(function() {
		size = $(this).attr("size");
		if($(this).prop("checked")) {
			count++;
			size_all += parseInt(size);
		}
	});
	showSelectedInfo(count, size_all);
});

// фильтр

// вкл/выкл интервал сидов
$("input[name=filter_interval]").on("click", function(){
	$(".filter_rule_interval").toggle(500);
	$(".filter_rule_one").toggle(500);
});

// сортировка по хранителю при двойном клике по его никнейму в списке раздач
$(document).on("dblclick","#keeper",function(e){
	$("input[name=filter_phrase]").val($(this).text());
	$('input[name=filter_by_phrase][type="radio"]').prop("checked", false);
	$('input[name=filter_by_keeper][type="radio"]').prop("checked", true);
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
		},
	});
}

// события при выборе свойств фильтра
var delay = makeDelay (500);
$("#topics_filter").find("input[type=text], input[type=search]").on("spin input", function() {
	delay( getFilteredTopics, this );
	showSelectedInfo( 0, 0.00 );
});

$("#topics_filter input[type=radio], #topics_filter input[type=checkbox]").on("change", function() {
	delay( getFilteredTopics, this );
	showSelectedInfo( 0, 0.00 );
});

$("#filter_date_release").on("change", function() {
	delay( getFilteredTopics, this );
	showSelectedInfo( 0, 0.00 );
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
