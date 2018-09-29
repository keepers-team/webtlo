//~ $(document).ready(function() {
	
	/* инициализация кнопок */
	$("#topics_control button, #savecfg, #get_statistics, #clear_log").button();
	$("#select, #control, #new-torrents, #filter").buttonset();
	$("#log_tabs").tabs();

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
	$("#rule_topics, .filter_rule input[type=text]").spinner({
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
		buttonText: '<i class="fa fa-calendar" aria-hidden="true"></i>'
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
			Cookies.set('selected-tab', (ui.newTab.index() === 2 ? 0 : ui.newTab.index()));
		},
		active: Cookies.get('selected-tab'),
		disabled: [ 2 ]
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
	.on("click", function() {
		forums = getForums();
		tor_clients = getTorClients();
		$data = $("#config").serialize();
		$.ajax({
			type: "POST",
			url: "php/actions/set_config.php",
			data: { cfg:$data, forums:forums, tor_clients:tor_clients },
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
	
	// получение статистики
	$("#get_statistics").on( "click", function (e) {
		// список подразделов
		forum_ids = getForumIds();
		$.ajax({
			context: this,
			type: "POST",
			url: "php/actions/get_statistics.php",
			data: { forum_ids:forum_ids },
			beforeSend: function() {
				$(this).prop( "disabled", true );
			},
			success: function( response ) {
				json = $.parseJSON( response );
				$("#table_statistics tbody").html( json.tbody );
				$("#table_statistics tfoot").html( json.tfoot );
			},
			complete: function() {
				$(this).prop( "disabled", false );
			}
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
		// список подразделов
		forum_ids = getForumIds();
		$data = $("#config").serialize();
		$.ajax({
			type: "POST",
			url: "php/actions/get_reports.php",
			data: { cfg:$data, forum_ids:forum_ids },
			beforeSend: function() {
				block_actions();
				$("#process").text( "Формирование отчётов..." );
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
					s.setBaseAndExtent(e,0,e,e.childNodes.length); 
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
				$( "#menutabs" ).tabs( "enable", 2 );
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
		forum_ids = getForumIds();
		forum_links = getForumLinks();
		$data = $("#config").serialize();
		$.ajax({
			type: "POST",
			url: "php/actions/send_reports.php",
			data: { cfg:$data, forum_ids:forum_ids, forum_links:forum_links },
			beforeSend: function() {
				block_actions();
				$("#process").text( "Отправка отчётов на форум..." );
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
			$("#topics_result").text("Проверьте настройки. Для получения подробностей обратитесь к журналу событий.");
			$("#log").append(errors);
			return;
		}
		// список торрент-клиентов
		tor_clients = getTorClients();
		// список подразделов
		forums = getForums();
		forum_ids = getForumIds();
		$data = $("#config").serialize();
		$.ajax({
			type: "POST",
			url: "php/actions/update_info.php",
			data: { cfg:$data, forums:forums, forum_ids:forum_ids, tor_clients:tor_clients },
			beforeSend: function() {
				block_actions();
				$("#process").text( "Обновление сведений о раздачах..." );
				$("#log").append(nowTime() + "Начато обновление сведений...<br />");
			},
			success: function(response) {
				response = $.parseJSON(response);
				$("#log").append(response.log);
				if ( response.result.length ) {
					$("#topics_result").text( response.result );
				}
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
        var login = $('input[name=tracker_username]').val();
        var paswd = $('input[name=tracker_password]').val();
        var api = $('input[name=api_key]').val();
        var subsections = $('textarea[name=TT_subsections]').val();
        var rule_topics = $('input[name=rule_topics]').val();
        var rule_reports = $('input[name=rule_reports]').val();
		
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

// получение bt_key, api_key, user_id
$("#tracker_username, #tracker_password").on("change", function() {
	if( $("#tracker_username").val() && $("#tracker_password").val() ) {
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

// проверка доступности форума и API
$( "#check_mirrors_access" ).on( "click", function() {
	$(this).attr( "disabled", true );
	var check_list = [ 'forum_url', 'api_url' ];
	var check_count = check_list.length;
	var result_list = [ 'text-danger', 'text-success' ];
	var $data = $( "#config" ).serialize();
	$.each( check_list, function( index, value ) {
		var element = "#" + value;
		var url = $( element ).val();
		if ( typeof url === "undefined" || $.isEmptyObject( url ) ) {
			check_count--;
			if ( check_count == 0 ) {
				$( "#check_mirrors_access" ).attr( "disabled", false );
			}
			$( element ).siblings( "i" ).removeAttr( "class" );
			return true;
		}
		$.ajax({
			type: "POST",
			url: "php/actions/check_mirror_access.php",
			data: { cfg:$data, url:url, url_type:value },
			success: function( response ) {
				$( element ).siblings( "i" ).removeAttr( "class" );
				var result = result_list[ response ];
				if ( typeof result !== "undefined" ) {
					$( element ).siblings( "i" ).addClass( "fa fa-circle " + result );
				}
			},
			beforeSend: function() {
				$( element ).siblings( "i" ).removeAttr( "class" );
				$( element ).siblings( "i" ).addClass( "fa fa-spinner fa-spin" );
			},
			complete: function() {
				check_count--;
				if ( check_count == 0 ) {
					$( "#check_mirrors_access" ).attr( "disabled", false );
				}
			}
		});
	});
});

// очистка лога
$( "#clear_log" ).on( "click", function() {
	$("#log").text("");
});

// чтение лога из файла
$( "#log_tabs" ).on( "tabsactivate", function( event, ui ) {
	// current tab
	var element_new = $( ui.newTab ).children( "a" );
	var name_new = $( element_new ).text();
	if ( ! element_new.hasClass( "log_file" ) ) {
		return true;
	}
	// previous tab
	var element_old = $( ui.oldTab ).children( "a" );
	var name_old = $( element_old ).text();
	if ( element_old.hasClass( "log_file" ) ) {
		$( "#log_" + name_old ).text( "" );
	}
	// request
	$.ajax({
		type: "POST",
		url: "php/actions/get_log_content.php",
		data: { log_file: name_new },
		success: function( response ) {
			if ( typeof response !== "undefined" ) {
				$( "#log_" + name_new ).html( response );
			}
		},
		beforeSend: function() {
			$( "#log_" + name_new ).html( "<i class=\"fa fa-spinner fa-pulse\"></i>" );
		}
	});
});
