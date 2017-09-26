$( document ).ready( function () {
	// дата релиза в фильтре
	var filter_date_release_until = $( "#filter_date_release_until" );
	var filter_date_release = $( "#filter_date_release_from, #filter_date_release_until" );
	filter_date_release.datepicker( {
		language: "ru",
		format: 'dd.mm.yyyy',
		endDate: "today",
		autoclose: true,
		defaultViewDate: "today",
		todayHighlight: true,
		todayBtn: "linked"
	} );
	filter_date_release_until.datepicker( "setDate", getReleaseDateLimitTo( filter_date_release_until.attr( "data-registered-until" ) ) );

	// скрыть фильтр если он был скрыт ранее
	/*if ( Cookies.get( 'filter-state' ) === "false" ) {
		$( "#topics_filter" ).hide();
	}*/

	// восстановить состояние опций фильтра
	//TODO добавить сохранение состояния фильтра сверху таблицы
	var filter_options = Cookies.get( 'filter-options' ) !== undefined ?
		JSON.parse( Cookies.get( 'filter-options' ) ) : "default";
	if ( filter_options !== "default" ) {
		$( "#topics_filter input[type=radio], #topics_filter input[type=checkbox]" ).prop( "checked", false ).parent().removeClass( 'active' );
		jQuery.each( filter_options, function ( i, option ) {
			$( "#topics_filter" ).find( "input[name='" + option.name + "']" ).each( function () {
				if ( $( this ).attr( "type" ) === "checkbox" || $( this ).attr( "type" ) === "radio" ) {
					if ( $( this ).val() === option.value ) {
						$( this ).prop( "checked", true );
						if ( $( this ).attr( "name" ) !== "filter_tor_status[]" ) {
							$( this ).parent().addClass( 'active' )
						}
					}
				} else {
					$( this ).val( option.value );
				}
			} );
		} );
	}

	/* инициализация главного меню (вкладок) */
	if ( Cookies.get( 'selected-tab' ) !== undefined ) {
		$( '#main_menu' ).find( ' a[href="' + Cookies.get( 'selected-tab' ) + '"]' ).tab( 'show' );
	} else {
		$( 'a[data-toggle="tab"][href="#main"]' ).tab( 'show' );
	}
} );

// предотвращаем закрытие фильтра по статусам раздач при клике
$( '#topics_filter .dropdown-menu, .columns-visibility' ).on( 'click', function ( e ) {
	e.stopPropagation();
} );

/* сохранение открытой вкладки при перезагрузке страницы */
$( 'a[data-toggle="tab"]' ).on( 'shown.bs.tab', function () {
	Cookies.set( 'selected-tab', ($( this ).attr( 'href' ) === "#reports" ? "#main" : $( this ).attr( 'href' )) );
} );

/* сохранение настроек */
$( "#savecfg" )
	.on( "click", function () {
		var tcs = listTorClients();
		var subsec = listDataSubsections();
		var $data = $( "#config" ).serialize();
		$.ajax( {
			type: "POST",
			url: "actions.php",
			data: { m: 'savecfg', tcs: tcs, cfg: $data, subsec: subsec },
			beforeSend: function () {
				$( "#savecfg" ).prop( "disabled", true );
			},
			success: function ( response ) {
				$( "#log" ).append( response );
			},
			complete: function () {
				$( "#savecfg" ).prop( "disabled", false );
			}
		} );
	} );

// получение статистики
$( "#get_statistics" ).on( "click", function () {
	$.ajax( {
		context: this,
		type: "POST",
		url: "php/actions/get_statistics.php",
		beforeSend: function () {
			$( this ).prop( "disabled", true );
		},
		success: function ( response ) {
			var json = $.parseJSON( response );
			var $table_statistics = $( "#table_statistics" );
			$table_statistics.find( "tbody" ).html( json.tbody );
			$table_statistics.find( "tfoot" ).html( json.tfoot );
		},
		complete: function () {
			$( this ).prop( "disabled", false );
		}
	} );
} );

/* формирование отчётов */
$( "#startreports" )
	.click( function () {
		var errors = [];
		if ( !FormConfigCheck( errors ) ) {
			$( "#reports" ).html( "<br /><div>Проверьте настройки.<br />Для получения подробностей обратитесь к журналу событий.</div><br />" );
			$( "#log" ).append( errors );
			return;
		}
		// получаем список т.-клиентов
		var tcs = listTorClients();
		// подразделов
		var subsec = listSubsections();
		var $data = $( "#config" ).serialize();
		$.ajax( {
			type: "POST",
			url: "actions.php",
			data: { m: 'reports', tcs: tcs, cfg: $data, subsec: subsec },
			beforeSend: function () {
				blockActions();
				$( "#process" ).text( "Формирование отчётов..." );
				$( "#log" ).append( nowTime() + "Начато формирование отчётов...<br />" );
			},
			success: function ( response ) {
				var resp = $.parseJSON( response );
				var $log = $( "#log" );
				$log.append( resp.log );
				$log.append( nowTime() + "Формирование отчётов завершено.<br />" );
				$( "#reports" ).html( jQuery.trim( resp.report ) );

				//выделение тела собщения двойным кликом
				$( ".report_message" ).dblclick( function () {
					var e = this;
					if ( window.getSelection ) {
						var s = window.getSelection();
						if ( s.setBaseAndExtent ) {
							s.setBaseAndExtent( e, 0, e, e.childNodes.length - 1 );
						} else {
							var r = document.createRange();
							r.selectNodeContents( e );
							s.removeAllRanges();
							s.addRange( r );
						}
					} else if ( document.getSelection ) {
						s = document.getSelection();
						r = document.createRange();
						r.selectNodeContents( e );
						s.removeAllRanges();
						s.addRange( r );
					} else if ( document.selection ) {
						r = document.body.createTextRange();
						r.moveToElementText( e );
						r.select();
					}
				} );
				$( '#main_menu' ).find( ' a[href="#reports"]' ).removeClass( "disabled" );
			},
			complete: function () {
				blockActions();
			}
		} );
	} );

/* отправка отчётов */
$( "#sendreports" )
	.click( function () {
		// список подразделов
		var subsec = listDataSubsections();
		var $config = $( "#config" ).serialize();
		$.ajax( {
			type: "POST",
			url: "actions.php",
			data: { m: 'send', cfg: $config, subsec: subsec },
			beforeSend: function () {
				blockActions();
				$( "#process" ).text( "Отправка отчётов на форум..." );
				$( "#log" ).append( nowTime() + "Начато выполнение процесса отправки отчётов...<br />" );
			},
			success: function ( response ) {
				var $log = $( "#log" );
				$log.append( response );
				$log.append( nowTime() + "Процесс отправки отчётов завершен.<br />" );
			},
			complete: function () {
				blockActions();
			}
		} );
	} );

/* обновление сведений о раздачах */
$( "#update" )
	.click( function () {
		var errors = [];
		if ( !FormConfigCheck( errors ) ) {
			$( "#topics_result" ).html( "<div>Проверьте настройки. Для получения подробностей обратитесь к журналу событий.</div>" );
			$( "#log" ).append( errors );
			return;
		}
		var tcs = listTorClients();
		var subsec = listDataSubsections();
		var $config = $( "#config" ).serialize();
		$.ajax( {
			type: "POST",
			url: "actions.php",
			data: { m: 'update', tcs: tcs, cfg: $config, subsec: subsec },
			beforeSend: function () {
				blockActions();
				$( "#process" ).text( "Обновление сведений о раздачах..." );
				$( "#log" ).append( nowTime() + "Начато обновление сведений...<br />" );
			},
			success: function ( response ) {
				response = $.parseJSON( response );
				var $log = $( "#log" );
				$log.append( response.log );
				redrawTopicsList();
				$log.append( nowTime() + "Обновление сведений завершено.</br>" );
			},
			complete: function () {
				blockActions();
			}
		} );
	} );

/* проверка введённых данных */
function FormConfigCheck( errors ) {
	return true;
	/*var login = $( 'input[name=TT_login]' ).val();
	var paswd = $( 'input[name=TT_password]' ).val();
	var api = $( 'input[name=api_key]' ).val();
	var subsections = $( 'textarea[name=TT_subsections]' ).val();
	var rule_topics = $( 'input[name=TT_rule_topics]' ).val();
	var rule_reports = $( 'input[name=TT_rule_reports]' ).val();

	if ( !login ) {
		errors.push( nowTime() + 'Не заполнено поле "логин" в настройках торрент-трекера.<br />' );
	}
	//~ if(!/^\w*$/.test(login)) errors.push(nowTime() + 'Указаны недопустимые символы в поле "логин" в настройках торрент-трекера.<br />');
	if ( !paswd ) {
		errors.push( nowTime() + 'Не заполнено поле "пароль" в настройках торрент-трекера.<br />' );
	}
	//~ if(!/^[A-Za-z0-9]*$/.test(paswd)) errors.push(nowTime() + 'Указаны недопустимые символы в поле "пароль" в настройках торрент-трекера.<br />');
	if ( !api ) {
		errors.push( nowTime() + 'Не заполнено поле "api" в настройках торрент-трекера.<br />' );
	}
	//~ if(!/^[A-Za-z0-9]*$/.test(api)) errors.push('Указаны недопустимые символы в поле "api" в настройках торрент-трекера.<br />');
	//~ if(!subsections) errors.push(nowTime() + 'Не заполнено поле "индексы подразделов" в настройках сканируемых подразделов.<br />');
	//~ if(!/^[0-9\,]*$/.test(subsections)) errors.push(nowTime() + 'Некорректно заполнено поле "индексы подразделов" в настройках сканируемых подразделов.<br />');
	if ( !rule_topics ) {
		errors.push( nowTime() + 'Не заполнено поле "предлагать для хранения раздачи с кол-вом сидов не более" в настройках сканируемых подразделов.<br />' );
	}
	//~ if(!/^[0-9]*$/.test(rule_topics)) errors.push(nowTime() + '<p>Указаны недопустимые символы в поле "предлагать для хранения раздачи с кол-вом сидов не более" в настройках сканируемых подразделов.<br />');
	if ( !rule_reports ) {
		errors.push( nowTime() + 'Не заполнено поле "количество сидов для формирования отчётов" в настройках сканируемых подразделов.<br />' );
	}
	if ( !/^[0-9]*$/.test( rule_reports ) ) {
		errors.push( nowTime() + 'Указаны недопустимые символы в поле "количество сидов для формирования отчётов" в настройках сканируемых подразделов.<br />' );
	}
	//~ alert(tcs);
	if ( listTorClients() == '' ) {
		errors.push( nowTime() + 'Добавьте хотя бы один торрент-клиент в настройках торрент-клиентов.<br />' );
	}
	return errors == '' ? true : false;*/
}

// проверка закрывающего слеша
$( "#savedir, #dir_torrents" ).on( "change", function () {
	if ( $( this ).val() !== '' ) {
		CheckSlash( this );
	}
} );

// прокси в настройках
var $proxy_activate = $( "#proxy_activate" );
var $proxy_prop = $( "#proxy_prop" );
$proxy_activate.on( "change", function () {
	$( this ).prop( "checked" ) ? $proxy_prop.show() : $proxy_prop.hide();
} );
$proxy_activate.change();

// получение bt_key, api_key, user_id
$( "#TT_login, #TT_password" ).on( "change", function () {
	if ( $( "#TT_login" ).val() && $( "#TT_password" ).val() ) {
		if ( !$( "#bt_key" ).val() || !$( "#api_key" ).val() || !$( "#user_id" ).val() ) {
			var $data = $( "#config" ).serialize();
			$.ajax( {
				type: "POST",
				url: "php/get_user_details.php",
				data: { cfg: $data },
				success: function ( response ) {
					var resp = $.parseJSON( response );
					$( "#log" ).append( resp.log );
					$( "#bt_key" ).val( resp.bt_key );
					$( "#api_key" ).val( resp.api_key );
					$( "#user_id" ).val( resp.user_id );
				}
			} );
		}
	}
} );