//~ $(document).ready(function() {
	
	/*
	 * JS for web-TLO (Web Torrent List Organizer)
	 * webtlo.js
	 * author: berkut_174 (webtlo@yandex.ru)
	 * last change: 11.02.2016
	 */
	
	/* инициализация кнопок */
	$("#update").button();
	$("#startreports").button();
	
	/* кнопка справки */
	$("#help").addClass("ui-button ui-state-default");
	$("#help").hover(function(){
		if($(this).hasClass("ui-state-hover"))
			$(this).removeClass("ui-state-hover");
		else
			$(this).addClass("ui-state-hover");
	});
	
	/* инициализация главного меню */
	var menutabs = $( "#menutabs" ).tabs();
	menutabs.addClass( "ui-tabs-vertical ui-helper-clearfix" ).removeClass("ui-widget-content");
	$( "#menutabs li.menu" ).removeClass( "ui-corner-top" ).addClass( "ui-corner-left" );
	
	/* отображение топикоп при запуске приложения */
	subsec = listSubsections();
	$data = $("#config").serialize();
	$.ajax({
		type: "POST",
		url: "index.php",
		data: { m:'topics', cfg:$data, subsec:subsec },
		beforeSend: function() {
			block_actions();
		},
		success: function(response) {
			var resp = eval( '(' + response + ')' );
			$("#topics").html(jQuery.trim(resp.topics));
			// $("#log").append(response);		
			//инициализация горизонтальных вкладок отчетов
			var topictabs = $("#topictabs").tabs();
			// проверка настроек
			var errors = [];
			$("#log").append(nowTime() + 'Проверка настроек...<br />');
			if(!FormConfigCheck(errors))
				$("#log").append(errors);
			else
				$("#log").append(nowTime() + 'Готов к работе.<br />');
				// $("#log").append(nowTime() + 'Дата обновления сведений.<br />');
			InitControlButtons();
		},
		complete: function(){
			block_actions();
		},
	});
	
	/* инициализация кнопок управления */
	function InitControlButtons() {
		$(".tor_download, .tor_add, .tor_select, .tor_unselect, .torrent_action").button();
		$(".tor_filter").button({ icons: { primary: "ui-icon-triangle-1-s" }});
		$(".filter_rule input[type=text]").spinner({ min: 0, mouseWheel: true });
		$(".loading").hide();
	}
	
	/* инициализация "аккордиона" для вкладки настройки */
	$("div.sub_settings").each(function() {
		$(this).accordion({
			collapsible: true,
			heightStyle: "content"
		});	
	});
	
	/* сохранение настроек */
	$( "#savecfg" )
	.button()
	.on("click", function() {
		tcs = listTorClients();
		subsec = listDataSubsections();
		//~ OnProxyProp();
		$data = $("#config").serialize();
		//~ OffProxyProp();
		$.ajax({
			type: "POST",
			url: "index.php",
			data: { m:'savecfg', tcs:tcs, cfg:$data, subsec:subsec },
			beforeSend: function() {
				$("#savecfg").prop("disabled", true);
			},
			success: function(response) {
				$("#log").append(response);
			},
			complete: function(response) {
				$("#savecfg").prop("disabled", false);
			},
		});
	});
	
	/* формирование отчётов */
	$( "#startreports" )
	.click(function() {
		var errors = [];
		if(!FormConfigCheck(errors)){
			//~ $("#reports").html("Отчёты не сформированы, есть ошибки. Для получения подробностей обратитесь к журналу событий.");
			$("#reports").html("<br /><div>Проверьте настройки.<br />Для получения подробностей обратитесь к журналу событий.</div><br />");
			$("#log").append(errors);
			return;
		}
		// получаем список т.-клиентов
		tcs = listTorClients(); 
		// подразделов
		subsec = listSubsections();
		$data = $("#config").serialize();
		$.ajax({
			type: "POST",
			//~ dataType: 'json',
			url: "index.php",
			data: { m:'reports', tcs:tcs, cfg:$data, subsec:subsec },
			beforeSend: function() {
				block_actions();
				$("#log").append(nowTime() + "Начато формирование отчётов...<br />");
			},
			//~ error: function(response){
				//~ $("#reports").append(response);
			//~ },
			success: function(response) {
				var resp = eval("(" + response + ")");
				$("#log").append(resp.log);
				$("#log").append(nowTime() + "Формирование отчётов завершено.<br />");
				$("#reports").html(jQuery.trim(resp.report));
				//~ $("#reports").html(response);
				
				//инициализация горизонтальных вкладок отчетов
				var reporttabs = $("#reporttabs").tabs();
				
				//инициализация "аккордиона" сообщений
				$( "div.acc" ).each(function(){
					$(this).accordion({
						collapsible: true,
						heightStyle: "content"
					});
				});
				
				//выделение тела собщения двойным кликом (код должен идти после инициализации аккордиона, иначе handler клика будет затерт)
				$("div.ui-accordion-content").dblclick(function() {
					var e=this; 
					if(window.getSelection){ 
					var s=window.getSelection(); 
					if(s.setBaseAndExtent){ 
					s.setBaseAndExtent(e,0,e,e.innerText.length-1); 
					}else{ 
					var r=document.createRange(); 
					r.selectNodeContents(e); 
					s.removeAllRanges(); 
					s.addRange(r);} 
					}else if(document.getSelection){ 
					var s=document.getSelection(); 
					var r=document.createRange(); 
					r.selectNodeContents(e); 
					s.removeAllRanges(); 
					s.addRange(r); 
					}else if(document.selection){ 
					var r=document.body.createTextRange(); 
					r.moveToElementText(e); 
					r.select();}
				});
				
			},
			complete: function() {
				block_actions();
			},
		});
	});
	
	/* обновление сведений о раздачах */
	$( "#update" )
	.click(function() {
		var errors = [];
		if(!FormConfigCheck(errors)){
			$("#topics").html("<br /><div>Проверьте настройки.<br />Для получения подробностей обратитесь к журналу событий.</div><br />");
			$("#log").append(errors);
			return;
		}
		tcs = listTorClients();
		subsec = listSubsections();
		$data = $("#config").serialize();
		$.ajax({
			type: "POST",
			url: "index.php",
			data: { m:'update', tcs:tcs, cfg:$data, subsec:subsec },
			beforeSend: function() {
				block_actions();
				$("#log").append(nowTime() + "Начато обновление сведений...<br />");
			},
			success: function(response) {
				var resp = eval("(" + response + ")");
				$("#log").append(resp.log);
				$("#log").append(nowTime() + "Обновление сведений завершено.</br>");
				$("#topics").html(jQuery.trim(resp.topics));
				//инициализация горизонтальных вкладок отчетов
				var topictabs = $( "#topictabs" ).tabs();
			},
			complete: function() {
				block_actions();
				InitControlButtons();
			},
		});
	});
	
	
	/* проверка введённых данных */
	function FormConfigCheck(errors){
        var login = $('input[name=TT_login]').val();
        var paswd = $('input[name=TT_password]').val();
        var api = $('input[name=api_key]').val();
        var subsections = $('textarea[name=TT_subsections]').val();
        var rule_topics = $('input[name=TT_rule_topics]').val();
        var rule_reports = $('input[name=TT_rule_reports]').val();
		
		if(!login) errors.push(nowTime() + 'Не заполнено поле "логин" в настройках торрент-трекера.<br />');
		//~ if(!/^\w*$/.test(login)) errors.push(nowTime() + 'Указаны недопустимые символы в поле "логин" в настройках торрент-трекера.<br />');
		if(!paswd) errors.push(nowTime() + 'Не заполнено поле "пароль" в настройках торрент-трекера.<br />');
		//~ if(!/^[A-Za-z0-9]*$/.test(paswd)) errors.push(nowTime() + 'Указаны недопустимые символы в поле "пароль" в настройках торрент-трекера.<br />');
		if(!api) errors.push(nowTime() + 'Не заполнено поле "api" в настройках торрент-трекера.<br />');
		//~ if(!/^[A-Za-z0-9]*$/.test(api)) errors.push('Указаны недопустимые символы в поле "api" в настройках торрент-трекера.<br />');
		//~ if(!subsections) errors.push(nowTime() + 'Не заполнено поле "индексы подразделов" в настройках сканируемых подразделов.<br />');
		//~ if(!/^[0-9\,]*$/.test(subsections)) errors.push(nowTime() + 'Некорректно заполнено поле "индексы подразделов" в настройках сканируемых подразделов.<br />');
		if(!rule_topics) errors.push(nowTime() + 'Не заполнено поле "предлагать для хранения раздачи с кол-вом сидов не более" в настройках сканируемых подразделов.<br />');
		if(!/^[0-9]*$/.test(rule_topics)) errors.push(nowTime() + '<p>Указаны недопустимые символы в поле "предлагать для хранения раздачи с кол-вом сидов не более" в настройках сканируемых подразделов.<br />');
		if(!rule_reports) errors.push(nowTime() + 'Не заполнено поле "количество сидов для формирования отчётов" в настройках сканируемых подразделов.<br />');
		if(!/^[0-9]*$/.test(rule_reports)) errors.push(nowTime() + 'Указаны недопустимые символы в поле "количество сидов для формирования отчётов" в настройках сканируемых подразделов.<br />');
		//~ alert(tcs);
		if(listTorClients() == '') errors.push(nowTime() + 'Добавьте хотя бы один торрент-клиент в настройках торрент-клиентов.<br />');
		return errors == '' ? true : false;
	}
	
//~ });

// проверка закрывающего слеша
$("#savedir").on("change", function() {
	if($(this).val() != '') {
		CheckSlash(this);
	}
});

// вкл/выкл прокси
$("#proxy_activate").on("change", function() {
	if($(this).prop("checked")) {
		$("#proxy_prop").show();
	}
	else {
		$("#proxy_prop").hide();
	}
});

// активировать прокси или нет
$("#proxy_activate").change();
