
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
$( ".tor_download" ).on( "click", function() {
	$( "#process" ).text( "Скачивание торрент-файлов..." );
	forum_id = $( "#subsections" ).val();
	replace_passkey = $( this ).val();
	ids = listSelectedTopics.apply();
	$data = $("#config").serialize();
	$.ajax({
		type: "POST",
		context: this,
		url: "php/actions/get_torrent_files.php",
		data: { cfg:$data, ids:ids, forum_id:forum_id, replace_passkey:replace_passkey },
		beforeSend: block_actions,
		complete: block_actions,
		success: function( response ) {
			var response = $.parseJSON ( response );
			$( "#log" ).append( response.log );
			$( "#topics_result" ).html( response.result );
		},
	});
});

// "чёрный список"
$( "#tor_blacklist" ).on( "click", function() {
	forum_id = $( "#subsections" ).val();
	value = forum_id != -2 ? 1 : 0;
	topics = listSelectedTopics.apply();
	if ( topics == "" ) {
		return;
	}
	$.ajax({
		type: "POST",
		url: "php/actions/blacklist.php",
		data: { topics:topics, value:value },
		beforeSend: function() {
			block_actions();
			$("#process").text( "Редактирование \"чёрного списка\" раздач..." );
		},
		success: function( response ) {
			$( "#topics_result" ).html( response );
			getFilteredTopics.apply( this );
		},
		complete: function() {
			block_actions();
		},
	});
});

// добавление раздач в торрент-клиент
$( "#tor_add" ).on( "click", function() {
	forum_id = $( "#subsections" ).val();
	topics_ids = listSelectedTopics.apply();
	forums = getForums();
	tor_clients = getTorClients();
	if ( $.isEmptyObject( topics_ids ) ) {
		showResult( "Не выделены раздачи для добавления" );
		return;
	}
	if ( $.isEmptyObject( forums ) ) {
		showResult( "В настройках не найдены подразделы" );
		return;
	}
	if ( $.isEmptyObject( tor_clients ) ) {
		showResult( "В настройках не найдены торрент-клиенты" );
		return;
	}
	forum_data = forums[ forum_id ];
	if ( typeof forum_data === "undefined" ) {
		showResult( "В настройках нет данных об указанном подразделе: " + forum_id );
		return;
	}
	if ( forum_data.cl === "" || forum_data.cl === 0 ) {
		showResult( "В настройках текущего подраздела не указан используемый торрент-клиент" );
		return;
	}
	tor_client_data = tor_clients[ forum_data.cl ];
	if ( typeof tor_client_data === "undefined" ) {
		showResult( "В настройках нет данных об указанном торрент-клиенте: " + forum_data.cl );
		return;
	}
	tor_client_data.id = forum_data.cl;
	$( "#process" ).text( "Добавление раздач в торрент-клиент..." );
	$config = $( "#config" ).serialize();
	$.ajax({
		type: "POST",
		url: "php/actions/add_topics_to_client.php",
		data: { cfg:$config, topics_ids:topics_ids, tor_client:tor_client_data, forum:forum_data },
		beforeSend: block_actions,
		complete: block_actions,
		success: function( response ) {
			var response = $.parseJSON ( response );
			$( "#log" ).append( response.log );
			showResult( response.add_log );
		}
	});
});

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
			$("#process").text( "Управление раздачами..." );
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
	clients = getTorClients();
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
function showSizeAndAmount( count, size ) {
	$( "#topics_count" ).text( count );
	$( "#topics_size" ).text( сonvertBytes( size ) );
}

function Counter() {
	this.count = 0;
	this.size_all = 0
}

function addSizeAndAmount( element ) {
	var size = element.attr( "size" );
	this.size_all += parseInt( size );
	this.count++;
}

// получение данных и вывод на экран кол-во, объём выделенных/остортированных раздач
function countSizeAndAmount(thisElem) {
	var action = 0;
	if ( thisElem !== undefined ) {
		action = thisElem.val();
	}
	var counter = new Counter();
	var topics = $("#topics").find("input[type=checkbox]");
	if (topics.length === 0) {
		showSizeAndAmount( 0, 0.00 );
	} else {
		topics.each(function () {
			switch (action) {
				case "select":
					$(this).prop("checked", "true");
					addSizeAndAmount.call(counter, $(this));
					break;
				case "unselect":
					$(this).removeAttr("checked");
					break;
				case "on":
					if ($(this).prop("checked")) {
						addSizeAndAmount.call(counter, $(this));
					}
					break;
				default:
					addSizeAndAmount.call(counter, $(this));
			}
		});
		showSizeAndAmount(counter.count, counter.size_all);
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
	Cookies.set( 'filter-options', $( "#topics_filter" ).serializeArray() );
	forum_id = $("#subsections").val();
	$config = $("#config").serialize();
	$filter = $("#topics_filter").serialize();
	$.ajax({
		type: "POST",
		url: "php/actions/get_filtered_list_topics.php",
		data: { forum_id: forum_id, config: $config, filter: $filter },
		success: function( response ) {
			response = $.parseJSON(response);
			if ( response.topics != null ) {
				$("#topics").html(response.topics);
				$("#filtered_topics_count").text( response.count );
				$("#filtered_topics_size").text( сonvertBytes( response.size ) );
			}
			//~ $("#log").append(response);
		},
		beforeSend: function() {
			block_actions();
			$("#process").text( "Получение данных о раздачах..." );
		},
		complete: function() {
			block_actions();
			showSizeAndAmount( 0, 0.00 );
		}
	});
}

// загрузка параметров фильтра из кук
$( document ).ready( function() {
	var filter_state = Cookies.get( "filter-state" );
	var filter_options = Cookies.get( "filter-options" );
	if ( filter_state === "false" ) {
		$( "#topics_filter" ).hide();
	}
	if ( typeof filter_options !== "undefined" ) {
		filter_options = $.parseJSON ( filter_options );
		$( "#topics_filter input[type=radio], #topics_filter input[type=checkbox]" ).prop( "checked", false );
		$.each( filter_options, function ( i, option ) {
			$( "#topics_filter input[name='" + option.name + "']" ).each( function () {
				if ( $( this ).val() === option.value ) {
					if ( $( this ).attr( "type" ) === "checkbox" || $( this ).attr( "type" ) === "radio" ) {
						$( this ).prop( "checked", true );
					}
					$( this ).val( option.value );
				}
			} );
		} );
	}
});

// скрыть/показать фильтр
$("#filter_show").on("click", function() {
	$("#topics_filter").toggle(500, function () {
		Cookies.set('filter-state', $(this).is(':visible'));
	});
});

// сбросить настройки фильтра
$("#filter_reset").on("click", function() {
	$("#topics_filter input[type=text]").val("");
	$("#topics_filter input[type=search]").val("");
	$("#topics_filter input[type=radio], #topics_filter input[type=checkbox]").prop("checked", false);
	$("#filter_date_release").datepicker("setDate", "-"+$("#rule_date_release").val());
	$("#filter_rule, #filter_rule_to").val($("#rule_topics").val());
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
});

$( "#topics_filter input[type=radio], #topics_filter input[type=checkbox], #filter_date_release" ).on( "change", function () {
	delay( getFilteredTopics, this );
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
