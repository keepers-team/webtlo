$( document ).ready( function () {

	//инициализация таблицы с топиками
	var table = $( '#topics_table' )
		.on( 'preXhr.dt', function () {
			blockActions();
			$( "#process" ).text( "Получение данных о раздачах..." );
		} )
		.DataTable( {
			serverSide: true,
			ajax: {
				url: 'php/actions/get_filtered_list_topics.php',
				type: 'POST',
				data: function ( d ) {
					d.forum_id = $( "#subsections" ).val();
					d.config = $( "#config" ).serialize();
					d.filter = $( "#topics_filter" ).serialize();
					d.filter_by_name = $( "#filter_by_name" ).val();
					d.filter_by_keeper = $( "#filter_by_keeper" ).val();
					d.filter_date_release_from = $( "#filter_date_release_from" ).val();
					d.filter_date_release_until = $( "#filter_date_release_until" ).val();
					d.filter_seeders_from = $( "#filter_seeders_from" ).val();
					d.filter_seeders_to = $( "#filter_seeders_to" ).val();
				}
			},
			language: {
				"processing": "Подождите...",
				"search": "Поиск:",
				"lengthMenu": "Показать _MENU_ записей",
				"info": "Записи с _START_ до _END_ из _TOTAL_ записей",
				"infoEmpty": "Записи с 0 до 0 из 0 записей",
				"infoFiltered": "(отфильтровано из _MAX_ записей)",
				"infoPostFix": "",
				"loadingRecords": "Загрузка записей...",
				"zeroRecords": "Записи отсутствуют.",
				"emptyTable": "В таблице отсутствуют данные",
				"paginate": {
					"first": "Первая",
					"previous": "Предыдущая",
					"next": "Следующая",
					"last": "Последняя"
				},
				"aria": {
					"sortAscending": ": активировать для сортировки столбца по возрастанию",
					"sortDescending": ": активировать для сортировки столбца по убыванию"
				}
			},
			paging: false,
			"processing": true,
			"searching": false,
			"order": [ 4, 'asc' ],
			"info": false,
			stateSave: true,
			responsive: true,
			"columns": [
				{
					"orderable": false,
					"data": "checkbox"
				},
				{
					"orderable": false,
					"data": "color"
				},
				{
					"orderable": false,
					"data": "torrents_status"
				},
				{
					"data": "reg_date",
					"width": "80px"
				},
				{
					"data": "size",
					"width": "80px"
				},
				{
					"data": "seeders",
					"width": "50px"
				},
				{
					"data": "name"
				},
				{
					"orderable": false,
					"data": "keepers",
					"width": "80px"
				}
			],
			"scrollY": "400px"
		} )
		.on( 'draw.dt', function () {
			countSizeAndAmount();
		} ).on( 'xhr.dt', function () {
			blockActions();
		} );
	var $topics = $( '#topics' );
	var $dataTables_scrollHead = $( '.dataTables_scrollHead' );
	var tableHeight = $topics.height() - $dataTables_scrollHead.height() - 2;
	$( '.dataTables_scrollBody' ).css( 'height', tableHeight + 'px' );
	$( window ).bind( 'resize', function () {
		var tableHeight = $topics.height() - $dataTables_scrollHead.height() - 2;
		$( '.dataTables_scrollBody' ).css( 'height', tableHeight + 'px' );
	} );

	// события при выборе свойств фильтра
	$( "#topics_filter" ).find( "input[type=text], input[type=search]" ).on( "spin input", redrawTopicsList );

	$( "#topics_filter input[type=radio], #topics_filter input[type=checkbox], #filter_date_release_from, #filter_date_release_until" ).on( "change", redrawTopicsList );

	$( "#table_filter input, input[type=number]" ).on( "input",
		redrawTopicsList );

	//инициализация тултипов
	$( function () {
		$( '[data-toggle="tooltip"]' ).tooltip()
	} )
} );

function redrawTopicsList() {
	Cookies.set( 'filter-options', $( "#topics_filter" ).serializeArray() );
	var table = $( '#topics_table' ).DataTable( {
		retrieve: true
	} );
	table.ajax.reload();
}

/* работа с топиками */

// получить список выделенных раздач
function listSelectedTopics() {
	var topics = [];
	$( "#topics" ).closest( "div" )
		.find( "input[type=checkbox]" )
		.each( function () {
			if ( $( this ).prop( "checked" ) ) {
				id = $( this ).attr( "id" );
				hash = $( this ).attr( "hash" );
				client = $( this ).attr( "client" );
				topics.push( { id: id, hash: hash, client: client } );
			}
		} );
	return topics;
}

// скачивание т.-файлов выделенных топиков
$( "#tor_download" ).on( "click", function () {
	var subsection = $( "#subsections" ).val();
	var edit = $( this ).val();
	var topics = listSelectedTopics.apply();
	if ( topics == "" ) {
		return;
	}
	$( "#log" ).append( nowTime() + "Начат процесс скачивания торрент-файлов...<br />" );
	var $data = $( "#config" ).serialize();
	$.ajax( {
		type: "POST",
		context: this,
		url: "actions.php",
		data: { topics: topics, m: 'download', subsec: subsection, cfg: $data, edit: edit },
		success: function ( response ) {
			var resp = eval( "(" + response + ")" );
			$( "#log" ).append( resp.log );
			$( "#topics_result" ).html( resp.dl_log );
			//~ $("#log").html(response);
		},
		beforeSend: function () {
			blockActions();
			$( "#process" ).text( "Скачивание торрент-файлов..." );
		},
		complete: function () {
			$( "#topics_list_" + subsection ).closest( "div" )
				.find( "input[type=checkbox]" )
				.each( function () {
					$( this ).removeAttr( "checked" );
				} );
			blockActions();
			//~ $("#log").append(nowTime() + "Скачивание торрент-файлов завершено.<br />");
		},
	} );
} );

// "чёрный список"
$( "#tor_blacklist" ).on( "click", function () {

	var forum_id = $( "#subsections" ).val();
	var value = forum_id != -2 ? 1 : 0;
	var topics = listSelectedTopics.apply();
	if ( topics == "" ) {
		return;
	}

	$.ajax( {
		type: "POST",
		url: "php/actions/blacklist.php",
		data: { topics: topics, value: value },
		beforeSend: function () {
			blockActions();
			$( "#process" ).text( 'Редактирование "чёрного списка" раздач...' );
		},
		success: function ( response ) {
			$( "#topics_result" ).html( response );
			redrawTopicsList();
		},
		complete: function () {
			blockActions();
		}
	} );
} );

// добавление раздач в торрент-клиент
$( "#tor_add" ).on( "click", function () {
	var subsection = $( "#subsections" ).val();
	var topics = listSelectedTopics.apply();
	if ( topics == '' ) {
		return;
	}
	if ( !$( "#list-ss [value=" + subsection + "]" ).val() ) {
		$( "#topics_result" ).html( "В настройках подразделов нет такого идентификатора: " + subsection + ".<br />" );
		return;
	}
	var ss_data = $( "#list-ss [value=" + subsection + "]" ).attr( "data" );
	var tmp = ss_data.split( "|" );
	if ( tmp[ 0 ] == "" && tmp[ 0 ] == 0 ) {
		$( "#topics_result" ).html( "В настройках текущего подраздела не указан используемый торрент-клиент.<br />" );
		return;
	}
	var value = $( "#list-tcs option" ).filter( function () {
		return $( this ).text() == tmp[ 0 ];
	} ).val();
	if ( !value ) {
		$( "#topics_result" ).html( "В настройках нет такого торрент-клиента: " + tmp[ 0 ] + "<br />" );
		return;
	}
	var cl_data = $( "#list-tcs option" ).filter( function () {
		return $( this ).text() == tmp[ 0 ];
	} ).attr( "data" );
	var $data = $( "#config" ).serialize();
	$.ajax( {
		type: "POST",
		context: this,
		url: "php/add_topics_to_client.php",
		data: { topics: topics, client: cl_data, subsec: ss_data, cfg: $data },
		success: function ( response ) {
			var resp = eval( "(" + response + ")" );
			$( "#log" ).append( resp.log );
			$( "#topics_result" ).html( resp.add_log );
			//~ $("#log").append(response);
			if ( resp.success != null ) {
				// помечаем в базе добавленные раздачи
				$.ajax( {
					type: "POST",
					context: this,
					url: "php/mark_topics_in_database.php",
					data: { success: resp.success, status: -1, client: value },
					success: function ( response ) {
						$( "#log" ).append( response );
						redrawTopicsList();
					}
				} );
			}
		},
		beforeSend: function () {
			blockActions();
			$( "#process" ).text( "Добавление раздач в торрент-клиент..." );
		},
		complete: function () {
			blockActions();
		},
	} );
} );

// действия с выбранными раздачами (старт, стоп, метка, удалить)
function execActionForTopics( action, remove_data, label, subsection ) {
	var topics = listSelectedTopics.apply();
	var clients = listTorClients();
	var force_start = "";
	if ( topics == '' ) {
		return;
	}
	$.ajax( {
		type: "POST",
		context: this,
		url: "php/exec_actions_topics.php",
		data: {
			topics: topics,
			clients: clients,
			action: action,
			remove_data: remove_data,
			force_start: force_start,
			label: label
		},
		success: function ( response ) {
			var resp = $.parseJSON( response );
			$( "#log" ).append( resp.log );
			$( "#topics_result" ).html( resp.result );
			//~ $("#log").append(response);
			if ( resp.ids != null && action == 'remove' ) {
				status = subsection == 0 ? '' : 0;
				// помечаем в базе удалённые раздачи
				$.ajax( {
					type: "POST",
					context: this,
					url: "php/mark_topics_in_database.php",
					data: { success: resp.ids, status: status, client: '' },
					success: function ( response ) {
						$( "#log" ).append( response );
						redrawTopicsList();
					},
				} );
			}
		},
		beforeSend: function () {
			blockActions();
			$( "#process" ).text( "Управление раздачами..." );
		},
		complete: function () {
			blockActions();
		},
	} );
}

$( "#remove_data, #remove, #set_custom_label, .torrent_action" ).on( "click", function ( e ) {
	var action = $( this ).attr( 'id' );
	var remove_data = '';
	var label = '';
	var subsection = $( "#subsections" ).val();
	if ( subsection > 0 ) {
		var data = $( "#list-ss [value=" + subsection + "]" ).attr( "data" );
		data = data.split( "|" );
		label = data[ 1 ];
	}

	if ( action === 'remove_open_modal' ) {
		$( '#delete_torrent_modal' ).modal();
		return;
	}
	if ( action === 'set_label' && (e.ctrlKey || subsection == 0) ) {
		$( '#set_label_modal' ).modal();
		return;
	}

	if ( action === 'remove_data' ) {
		remove_data = true;
		action = 'remove';
	}
	if ( action === 'set_custom_label' ) {
		label = $( "#any_label" ).val();
		action = 'set_label';
	}

	execActionForTopics( action, remove_data, label, subsection );
} );

// вывод на экран кол-во, объём выбранных раздач
function showSizeAndAmount( count, size, filtered ) {
	var topics_count = filtered ? "#filtered_topics_count" : "#topics_count";
	var topics_size = filtered ? "#filtered_topics_size" : "#topics_size";
	$( topics_count ).text( count );
	$( topics_size ).text( сonvertBytes( size ) );
}

function Counter() {
	this.count = 0;
	this.size_all = 0
}

function addSizeAndAmount( input ) {
	var size = input.attr( "size" );
	this.size_all += parseInt( size );
	this.count++;
}

// получение данных и вывод на экран кол-во, объём выделенных/остортированных раздач
function countSizeAndAmount( thisElem ) {
	var action = 0;
	if ( thisElem !== undefined ) {
		action = thisElem.val();
	}
	var counter = new Counter();
	var topics_checkboxes = $( "#topics" ).find( "input[type=checkbox]" );
	var filtered = false;
	if ( topics_checkboxes.length === 0 ) {
		showSizeAndAmount( 0, 0, false );
		showSizeAndAmount( 0, 0, true );
	} else {
		topics_checkboxes.each( function () {
			switch ( action ) {
				case "select":
					$( this ).prop( "checked", true );
					addSizeAndAmount.call( counter, $( this ) );
					break;
				case "unselect":
					$( this ).prop( "checked", false );
					break;
				case "on":
					if ( $( this ).prop( "checked" ) ) {
						addSizeAndAmount.call( counter, $( this ) );
					}
					break;
				default:
					addSizeAndAmount.call( counter, $( this ) );
					filtered = true;
			}
		} );
		showSizeAndAmount( 0, 0, false );
		showSizeAndAmount( counter.count, counter.size_all, filtered );
	}
}

// кнопка выделить все / отменить выделение
$( "#tor_select, #tor_unselect" ).on( "click", function () {
	countSizeAndAmount( $( this ) )
} );

// выделение/снятие выделения интервала раздач
$( "#topics" ).on( "click", ".topic", function ( event ) {
	var subsection = $( "#subsections" ).val();
	if ( !$( "#topics .topic" ).hasClass( "first-topic" ) ) {
		$( this ).addClass( "first-topic" );
		countSizeAndAmount( $( this ) );
		return;
	}
	if ( event.shiftKey ) {
		var tag = parseInt( $( this ).attr( "tag" ) ); // 2 - 20 = -18; 10 - 2 = 8;
		var tag_first = parseInt( $( "#topics .first-topic" ).attr( "tag" ) );
		var direction = (tag_first - tag < 0 ? 'down' : 'up');
		$( "#topics" ).closest( "div" )
			.find( "input[type=checkbox]" )
			.each( function () {
				if ( direction == 'down' ) {
					if ( parseInt( $( this ).attr( "tag" ) ) >= tag_first && parseInt( $( this ).attr( "tag" ) ) <= tag ) {
						if ( !event.ctrlKey ) {
							$( this ).prop( "checked", "true" );
						} else {
							$( this ).removeAttr( "checked" );
						}
					}
				}
				if ( direction == 'up' ) {
					if ( parseInt( $( this ).attr( "tag" ) ) <= tag_first && parseInt( $( this ).attr( "tag" ) ) >= tag ) {
						if ( !event.ctrlKey ) {
							$( this ).prop( "checked", "true" );
						} else {
							$( this ).removeAttr( "checked" );
						}
					}
				}
			} );
	}
	countSizeAndAmount( $( this ) );
	$( "#topics .first-topic" ).removeClass( "first-topic" );
	$( this ).addClass( "first-topic" );
} );

// фильтр

// сортировка по хранителю при двойном клике по его никнейму в списке раздач
$( document ).on( "dblclick", ".keeper", function ( e ) {
	$( "#filter_by_keeper" ).val( $( this ).text() );
	$( 'input[name=is_keepers][type="checkbox"]' ).prop( "checked", true ).change();
} );

// скрыть/показать фильтр
/*$( "#filter_show" ).on( "click", function () {
	$( "#topics_filter" ).toggle( 500, function () {
		Cookies.set( 'filter-state', $( this ).is( ':visible' ) );
	} );
} );*/

function getReleaseDateLimitTo( days ) {
	var date_limit = new Date();
	date_limit.setDate( date_limit.getDate() - days );
	return date_limit
}

// сбросить настройки фильтра
$( "#filter_reset" ).on( "click", function () {
	$( "#topics_filter input[type=radio], #topics_filter input[type=checkbox]" ).prop( "checked", false ).parent().removeClass('active');
	$( "#filter_date_release_from" ).val( "" );
	$( "#filter_date_release_until" ).datepicker( "setDate", getReleaseDateLimitTo( $( "#rule_date_release" ).val() ) );
	$( "#filter_seeders_to" ).val( $( "#TT_rule_topics" ).val() );
	$( "#filter_seeders_from" ).val( 0 );
	$( "#filter_by_name" ).val( "" );
	$( "#filter_by_keeper" ).val( "" );
	$( "#filter_avg_seeders_period" ).val( $( "#avg_seeders_period" ).val() );
	$( "#topics_filter" ).find( ':input[value="0"]' ).prop( "checked", true ).change().parent().addClass('active');

} );

// есть/нет хранители
$( ".topics_filter .keepers" ).on( "change", function () {
	var $is_keepers = $( "input[name=is_keepers]" );
	var $not_keepers = $( "input[name=not_keepers]" );
	if ( $( this ).prop( "checked" ) ) {
		switch ( $( this ).attr( 'name' ) ) {
			case 'not_keepers':
				$is_keepers.prop( "checked", false );
				$is_keepers.parent().removeClass("active");
				break;
			case 'is_keepers':
				$not_keepers.prop( "checked", false );
				$not_keepers.parent().removeClass("active");
				break;
		}
	}
} );
