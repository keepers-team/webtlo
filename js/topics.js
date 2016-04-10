/*
 * JS for web-TLO (Web Torrent List Organizer)
 * topics.js
 * author: berkut_174 (webtlo@yandex.ru)
 * last change: 11.02.2016
 */

/* работа с топиками */

// выделение всех топиков или снятие выделения
function SelAll(btn) {
	var id = $(btn).attr("id");
	var subsec = id.split("_");
	$("#topics_list_"+subsec[1]).closest("form")
	.find("input[type=checkbox]")
	.each(function() {
		//~ var topic = $(this).data("id");
		//~ var topic = topic.split("_");
		//~ if ( topic[1] == subsec[1] ) {
			switch (subsec[0]) {
				case "select":
					$(this).prop("checked", "true");
					break;
				case "unselect":
					$(this).removeAttr("checked");
					break;
			}
		//~ }
	});
	SelTopic(btn);
	nowTime();
	$("#" + id + " span").text(subsec[0] != "select" ? "Выделить все" : "Отменить все");
	$("#" + id).attr("title", subsec[0] != "select" ? "Выделить все топики текущего раздела." : "Отменить выделение всех топиков текущего раздела.");
	$("#" + id).attr("id", (subsec[0] != "select" ? "select" : "unselect") + "_" + subsec[1]);
}

// скачивание т.-файлов выделенных топиков
function DwnldSel(btn) {
	var id = $(btn).attr("id");
	var subsec = id.split("_");
	var topics = [];
	$("#topics_list_"+subsec[1]).closest("form")
	.find("input[type=checkbox]")
	.each(function() {
		var topic = $(this).attr("id");
		var topic = topic.split("_");
		//~ if ( topic[1] == subsec[1] ) {
			if($(this).prop("checked")) {
				topics.push(topic[2]);
			}
		//~ }
	});
	if(topics == "") {
		return;
	}	
	$data = $("form#config").serialize();
	$.ajax({
		type: "POST",
		url: "index.php",
		data: { id:topics, m:'download', subsec:subsec[1], cfg:$data },
		success: function(response) {
			var resp = eval("(" + response + ")");
			$("#log").append(resp.log);
			$("div#result_"+subsec[1]).html(resp.dl_log);
		},
		beforeSend: function() {
			block_actions();
			$("#downloading_"+subsec[1]).show();
		},
		complete: function() {
			$("#topics_list_"+subsec[1]).closest("form")
			.find("input[type=checkbox]")
			.each(function() {
			    //~ var topic = $(this).data("id");
			    //~ var topic = topic.split("_");
			    //~ if ( topic[1] == subsec[1] ) {
				    $(this).removeAttr("checked");
			    //~ }
			});
			block_actions();
			$("#downloading_"+subsec[1]).hide();
			$("#log").append(nowTime() + "Скачивание торрент-файлов завершено.<br />");
		},
	});
}

function SelTopic(cb){
	var id = $(cb).attr("id");
	//~ alert(id);
	var subsec = id.split("_");
	var count = 0;
	var size = 0;
	$("#topics_list_"+subsec[1]).closest("form")
	.find("input[type=checkbox]")
	.each(function() {
		var topic = $(this).attr("id");
		var topic = topic.split("_");
		//~ if ( topic[1] == subsec[1] ) {
			if($(this).prop("checked")) {
				count++;
				size += parseInt(topic[3]);
			}
		//~ }
	});
	$("#result_"+subsec[1]).html("Выбрано раздач: <span id=\"tp_count_"+subsec[1]+"\" class=\"rp-header\"></span> (<span id=\"tp_size_"+subsec[1]+"\"></span>).");
	$("#tp_count_"+subsec[1]).text(count);
	$("#tp_size_"+subsec[1]).text(сonvertBytes(size));
}
