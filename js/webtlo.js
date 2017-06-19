//~ $(document).ready(function() {
	
	/* инициализация кнопок */
	$("#button_menu input, .topics_control button").button();
	
	// период хранения средних сидов
	$("#avg_seeders_period, #filter_avg_seeders_period").spinner({
		min: 1,
		max: 30,
		mouseWheel: true
	});
	
	// дата релиза в настройках
	$("#rule_date_release").spinner({
		min: 0,
		mouseWheel: true
	});
	
	// фильтрация раздач, количество сидов
	$("#TT_rule_topics, #TT_rule_reports, .filter_rule input[type=text]").spinner({
		min: 0,
		step: 0.5,
		mouseWheel: true
	});
	
	// дата релиза в фильтре
	$.datepicker.regional["ru"];
	$("#filter_date_release").datepicker({
		changeMonth: true,
		changeYear: true,
		showOn: "both",
		dateFormat: 'dd.mm.yy',
		maxDate: "now",
		buttonImage: "img/calendar.png",
		buttonImageOnly: true,
		buttonText: "Раскрыть календарь"
	})
	.datepicker("setDate", $("#filter_date_release").val())
	.css("width", 90)
	.datepicker("refresh");
	
	// регулировка раздач, количество пиров
	$("#peers").spinner({
		min: 1,
		mouseWheel: true
	});

	/* кнопка справки */
	$("#help").addClass("ui-button ui-state-default");
	$("#help").hover(function(){
		if($(this).hasClass("ui-state-hover"))
			$(this).removeClass("ui-state-hover");
		else
			$(this).addClass("ui-state-hover");
	});
	
	/* инициализация главного меню */
	var menutabs = $( "#menutabs" ).tabs({
		activate: function (event, ui) {
			Cookies.set('selected-tab', ui.newTab.index());
		},
		active: Cookies.get('selected-tab')
	});
	menutabs.addClass( "ui-tabs-vertical ui-helper-clearfix" ).removeClass("ui-widget-content");
	$( "#menutabs li.menu" ).removeClass( "ui-corner-top" ).addClass( "ui-corner-left" );
	
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
			url: "actions.php",
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
			url: "actions.php",
			data: { m:'reports', tcs:tcs, cfg:$data, subsec:subsec },
			beforeSend: function() {
				block_actions();
				$("#log").append(nowTime() + "Начато формирование отчётов...<br />");
			},
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
	
	/* отправка отчётов */
	$( "#sendreports" )
	.click(function() {
		// список подразделов
		subsec = listDataSubsections();
		$data = $("#config").serialize();
		$.ajax({
			type: "POST",
			url: "actions.php",
			data: { m:'send', cfg:$data, subsec:subsec },
			beforeSend: function() {
				block_actions();
				$("#log").append(nowTime() + "Начато выполнение процесса отправки отчётов...<br />");
			},
			success: function(response) {
				//~ var resp = eval("(" + response + ")");
				//~ $("#log").append(resp.log);
				//~ $("#reports").html(jQuery.trim(resp.report));
				$("#log").append(response);
				$("#log").append(nowTime() + "Процесс отправки отчётов завершен.<br />");
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
		subsec = listDataSubsections();
		$data = $("#config").serialize();
		$.ajax({
			type: "POST",
			url: "actions.php",
			data: { m:'update', tcs:tcs, cfg:$data, subsec:subsec },
			beforeSend: function() {
				block_actions();
				$("#log").append(nowTime() + "Начато обновление сведений...<br />");
			},
			success: function(response) {
				response = $.parseJSON(response);
				$("#log").append(response.log);
				getFilteredTopics();
				$("#log").append(nowTime() + "Обновление сведений завершено.</br>");
			},
			complete: function() {
				block_actions();
			},
		});
	});
	
	
	/* проверка введённых данных */
	function FormConfigCheck(errors){
		return true;
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
		//~ if(!/^[0-9]*$/.test(rule_topics)) errors.push(nowTime() + '<p>Указаны недопустимые символы в поле "предлагать для хранения раздачи с кол-вом сидов не более" в настройках сканируемых подразделов.<br />');
		if(!rule_reports) errors.push(nowTime() + 'Не заполнено поле "количество сидов для формирования отчётов" в настройках сканируемых подразделов.<br />');
		if(!/^[0-9]*$/.test(rule_reports)) errors.push(nowTime() + 'Указаны недопустимые символы в поле "количество сидов для формирования отчётов" в настройках сканируемых подразделов.<br />');
		//~ alert(tcs);
		if(listTorClients() == '') errors.push(nowTime() + 'Добавьте хотя бы один торрент-клиент в настройках торрент-клиентов.<br />');
		return errors == '' ? true : false;
	}
	
//~ });

// проверка закрывающего слеша
$("#savedir, #dir_torrents").on("change", function() {
	if($(this).val() != '') {
		CheckSlash(this);
	}
});

// прокси в настройках
$("#proxy_activate").on("change", function() {
	$(this).prop("checked") ? $("#proxy_prop").show() : $("#proxy_prop").hide();
});
$("#proxy_activate").change();

// получение bt_key, api_key, user_id
$("#TT_login, #TT_password").on("change", function() {
	if( $("#TT_login").val() && $("#TT_password").val() ) {
		if( !$("#bt_key").val() || !$("#api_key").val() || !$("#user_id").val() ) {
			$data = $("#config").serialize();
			$.ajax({
				type: "POST",
				url: "php/get_user_details.php",
				data: { cfg:$data },
				//~ beforeSend: function() {
					//~ $(".user_details").prop("disabled", true);
				//~ },
				success: function(response) {
					var resp = eval("(" + response + ")");
					$("#log").append(resp.log);
					$("#bt_key").val(resp.bt_key);
					$("#api_key").val(resp.api_key);
					$("#user_id").val(resp.user_id);
				},
				//~ complete: function() {
					//~ $(".user_details").prop("disabled", false);
				//~ },
			});
		}
	}
});
