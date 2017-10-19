$( document ).ready( function () {


	var $topics = $( '#topics' );
	var $dataTables_scrollHead = $( '.dataTables_scrollHead' );
	var $topics_table_paginate = $( '#topics_table_paginate' );
	var tableHeight = $topics.height() - $dataTables_scrollHead.height() - 2;
	$( '.dataTables_scrollBody' ).css( 'height', tableHeight + 'px' );
	$( window ).bind( 'resize', function () {
		var tableHeight = $topics.height() - $dataTables_scrollHead.height() - $topics_table_paginate.height() - 4 - 2;
		$( '.dataTables_scrollBody' ).css( 'height', tableHeight + 'px' );
	} );

	// события при выборе свойств фильтра
	var delay = makeDelay( 500 );
	$( "#topics_filter" ).find( "input[type=text], input[type=search]" ).on( "spin input", function () {
		delay( redrawTopicsList, this );
	} );

	$( "#topics_filter input[type=radio], #topics_filter input[type=checkbox], #table_filter input[type=checkbox], #filter_date_release_from, #filter_date_release_until" ).on( "change", function () {
		delay( redrawTopicsList, this );
	} );

	$( "#table_filter input, input[type=number]" ).on( "input", function () {
		var min = 0;
		var max = 0;
		if ( $( this ).is( "#filter_seeders_from" ) ) {
			min = parseFloat( $( this ).val() );
			max = parseFloat( $( "#filter_seeders_to" ).val() );
			if ( min > max ) {
				$( this ).val( max );
			}
		} else if ( ($( this ).is( "#filter_seeders_to" )) ) {
			min = parseFloat( $( "#filter_seeders_from" ).val() );
			max = parseFloat( $( this ).val() );
			if ( max < min ) {
				$( this ).val( min );
			}
		}
		delay( redrawTopicsList, this );
	} );

	//перерисовка таблицы при открытии главной
	$( 'a[data-toggle="tab"][href="#main"]' ).on( 'shown.bs.tab', function () {
		$.fn.dataTable.ext.errMode = "throw";
		if ( $.fn.dataTable.isDataTable( '#topics_table' ) === false ) {
			//инициализация таблицы с топиками
			var table = $( '#topics_table' )
				.on( 'preXhr.dt', function () {
					blockActions();
					$( "#process" ).text( "Получение данных о раздачах..." );
					$( '[data-toggle="tooltip"]' ).tooltip( 'hide' );
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
							d.filter_by_unique_keeper = $( "#filter_by_unique_keeper" ).prop( "checked" );
							d.filter_by_subsection = $( "#filter_by_subsection" ).val();
							d.filter_date_release_from = $( "#filter_date_release_from" ).val();
							d.filter_date_release_until = $( "#filter_date_release_until" ).val();
							d.filter_seeders_from = $( "#filter_seeders_from" ).val();
							d.filter_seeders_to = $( "#filter_seeders_to" ).val();
						},
						dataSrc: function ( json ) {
							$( "#topics_count" ).text( "0" );
							$( "#topics_size" ).text( "0.00" );
							$( "#filtered_topics_count" ).text( json.count );
							$( "#filtered_topics_size" ).text( json.size );
							return json.data;
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
					drawCallback: function () {
						var pagination = $( this ).closest( '.dataTables_wrapper' ).find( '.dataTables_paginate' );
						pagination.toggle( this.api().page.info().pages > 1 );

						var $topics = $( '#topics' );
						var $dataTables_scrollHead = $( '.dataTables_scrollHead' );
						var $topics_table_paginate = $( '#topics_table_paginate' );
						var tableHeight;
						tableHeight = $topics.height() - $dataTables_scrollHead.height() - $topics_table_paginate.height() - 4 - 2 - 3;
						$( '.dataTables_scrollBody' ).css( 'height', tableHeight + 'px' );
					},
					"lengthMenu": [ 50, 100, 200, 500, 1000 ],
					"dom": "<'row'<'col-12'f>>" +
					"<'row'<'col-12'tr>>" +
					"<'row'<'col-5'l><'col-7'p>>",
					"processing": true,
					"searching": false,
					"order": [ 5, 'asc' ],
					"info": false,
					stateSave: true,
					responsive: true,
					"columns": [
						{
							"orderable": false,
							"data": "checkbox",
							"width": "21px"
						},
						{
							"orderable": false,
							"data": "color",
							"width": "23px"
						},
						{
							"data": "torrents_status",
							"width": "1px"
						},
						{
							"data": "reg_date",
							"width": "55px"
						},
						{
							"data": "size",
							"width": "55px"
						},
						{
							"data": "seeders",
							"width": "25px"
						},
						{
							"data": "name"
						},
						{
							"orderable": false,
							"data": "alternatives",
							"width": "25px"
						},
						{
							"orderable": false,
							"data": "keepers",
							"width": "100px"
						},
						{
							"data": "subsection",
							"width": "25px"
						}
					],
					"scrollY": "400px"
				} )
				.on( 'xhr.dt', function () {
					blockActions();

					//инициализация тултипов
					$( function () {
						$( '[data-toggle="tooltip"]' ).tooltip()
					} )
				} );
			var state = table.state.loaded();
			if ( state !== null ) {
				$.each( state.columns, function ( index, value ) {
					if ( value.visible ) {
						$( ".columns-visibility" ).find( ':input[value="' + index + '"]' ).attr( 'checked', true );
					}
				} );
			}
		}
	} );

	$( '.toggle-vis' ).on( 'change', function () {
		if ( $.fn.dataTable.isDataTable( '#topics_table' ) === true ) {
			var table = $( '#topics_table' ).DataTable( {
				retrieve: true
			} );
			// Get the column API object
			var column = table.column( $( this ).val() );
			// Toggle the visibility
			column.visible( !column.visible() );
		}
	} );
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
				var id = $( this ).attr( "id" );
				var hash = $( this ).attr( "hash" );
				var client = $( this ).attr( "client" );
				var subsection = $( this ).attr( "subsection" );
				topics.push( { id: id, hash: hash, client: client, subsection: subsection } );
			}
		} );
	return topics;
}

// скачивание т.-файлов выделенных топиков
$( ".tor_download" ).on( "click", function () {
	$( "#process" ).text( "Скачивание торрент-файлов..." );
	var forum_id = $( "#subsections" ).val();
	var replace_passkey = $( this ).val();
	var ids = listSelectedTopics.apply();
	var $data = $( "#config" ).serialize();
	$.ajax( {
		type: "POST",
		context: this,
		url: "php/actions/get_torrent_files.php",
		data: { cfg:$data, ids:ids, forum_id:forum_id, replace_passkey:replace_passkey },
		beforeSend: blockActions,
		complete: blockActions,
		success: function ( response ) {
			var response = $.parseJSON ( response );
			$( "#log" ).append( response.log );
			$( "#topics_result" ).html( response.result );
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
$( "#tor_add" ).on( "click", function() {
	var forum_id = $( "#subsections" ).val();
	var topics_ids = listSelectedTopics.apply();
	var forums = getForums();
	var tor_clients = getTorClients();
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
	$( "#process" ).text( "Добавление раздач в торрент-клиент..." );
	var config = $( "#config" ).serialize();
	$.ajax({
		type: "POST",
		url: "php/add_topics_to_client.php",
		data: { cfg:config, topics:topics_ids, /*tor_client:tor_client_data, forum:forum_data*/ },
		beforeSend: blockActions,
		complete: blockActions,
		success: function ( response ) {
			var resp = $.parseJSON( response );
			$( "#log" ).append( resp.log );
			$( "#topics_result" ).html( resp.add_log );
			//~ $("#log").append(response);
			if ( resp.success != null ) {
				// помечаем в базе добавленные раздачи
				$.ajax( {
					type: "POST",
					context: this,
					url: "php/mark_topics_in_database.php",
					data: { success: resp.success, status: -1/*, /*client: value */ },
					success: function ( response ) {
						$( "#log" ).append( response );
						redrawTopicsList();
					}
				} );
			}
		},
	});
});

// действия с выбранными раздачами (старт, стоп, метка, удалить)
function execActionForTopics( action, remove_data, label, subsection ) {
	var topics = listSelectedTopics.apply();
	var clients = getTorClients();
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
					}
				} );
			}
		},
		beforeSend: function () {
			blockActions();
			$( "#process" ).text( "Управление раздачами..." );
		},
		complete: function () {
			blockActions();
		}
	} );
}

$( "#remove_data, #remove, #set_custom_label, .torrent_action" ).on( "click", function ( e ) {
	var action = $( this ).attr( 'id' );
	var remove_data = '';
	var label = '';
	var subsection = $( "#subsections" ).val();
	if ( subsection > 0 ) {
		var data = $( "#list-ss" ).find( "[value=" + subsection + "]" ).attr( "data" );
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
function showSizeAndAmount( count, size ) {
	$( "#topics_count" ).text( count );
	$( "#topics_size" ).text( convertBytes( size ) );
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
function countSizeAndAmount( thisElem ) {
	var action = 0;
	if ( thisElem !== undefined ) {
		action = thisElem.val();
	}
	var counter = new Counter();
	var topics = $( "#topics" ).find( "input[type=checkbox]" );
	if ( topics.length === 0 ) {
		showSizeAndAmount( 0, 0.00 );
	} else {
		topics.each( function () {
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
			}
		} );
		showSizeAndAmount( counter.count, counter.size_all );
	}
}

// кнопка выделить все / отменить выделение
$( "#tor_select, #tor_unselect" ).on( "click", function () {
	countSizeAndAmount( $( this ) )
} );

// выделение/снятие выделения интервала раздач
var $topics = $( "#topics" );
$topics.on( "click", ".topic", function ( event ) {
	var subsection = $( "#subsections" ).val();
	if ( !$topics.find( ".topic" ).hasClass( "first-topic" ) ) {
		$( this ).addClass( "first-topic" );
		countSizeAndAmount( $( this ) );
		return;
	}
	if ( event.shiftKey ) {
		var tag = parseInt( $( this ).attr( "tag" ) ); // 2 - 20 = -18; 10 - 2 = 8;
		var tag_first = parseInt( $topics.find( ".first-topic" ).attr( "tag" ) );
		var direction = (tag_first - tag < 0 ? 'down' : 'up');
		$topics.closest( "div" )
			.find( "input[type=checkbox]" )
			.each( function () {
				tag_this = parseInt( $( this ).attr( "tag" ) );
				if ( direction == 'down' ) {
					if ( tag_this >= tag_first && tag_this <= tag ) {
						$( this ).prop( "checked", !event.ctrlKey );
					}
				}
				if ( direction == 'up' ) {
					if ( tag_this <= tag_first && tag_this >= tag ) {
						$( this ).prop( "checked", !event.ctrlKey );
					}
				}
			} );
	}
	countSizeAndAmount( $( this ) );
	$topics.find( ".first-topic" ).removeClass( "first-topic" );
	$( this ).addClass( "first-topic" );
} );

// фильтр

// сортировка по хранителю при двойном клике по его никнейму в списке раздач
$( document ).on( "dblclick", ".keeper", function ( e ) {
	$( "#filter_by_keeper" ).val( $( this ).text() );
	$( 'input[name=is_keepers][value="0"], input[name=is_keepers][value="-1"]' ).prop( "checked", false ).parent().removeClass( 'active' );
	$( 'input[name=is_keepers][value="1"]' ).prop( "checked", true ).change().parent().addClass( 'active' );
} );

// получение отфильтрованных раздач из базы
/*function getFilteredTopics(){
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
			blockActions();
			$("#process").text( "Получение данных о раздачах..." );
		},
		complete: function() {
			blockActions();
			showSizeAndAmount( 0, 0.00 );
		}
	});
}*/


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
	var $topics_filter = $( "#topics_filter" );
	$( "#topics_filter input[type=radio], #topics_filter input[type=checkbox]" ).prop( "checked", false ).parent().removeClass( 'active' );
	$( "#filter_date_release_from" ).val( "" );
	$( "#filter_date_release_until" ).datepicker( "setDate", getReleaseDateLimitTo( $( "#rule_date_release" ).val() ) );
	$( "#filter_seeders_to" ).val( $( "#rule_topics" ).val() );
	$( "#filter_seeders_from" ).val( 0 );
	$( "#filter_by_name" ).val( "" );
	$( "#filter_by_keeper" ).val( "" );
	$( "#filter_avg_seeders_period" ).val( $( "#avg_seeders_period" ).val() );
	$topics_filter.find( ':input[name!="filter_tor_status[]"][value="0"]' ).prop( "checked", true ).parent().addClass( 'active' );
	$topics_filter.find( ':input[name="filter_tor_status[]"]' ).prop( "checked", false );
	$topics_filter.find( ':input[name="filter_tor_status[]"][value="2"], :input[name="filter_tor_status[]"][value="8"]' ).prop( "checked", true ).change();

} );

// есть/нет хранители
$( 'input[name="is_keepers"]' ).on( "change", function () {
		var is_keepers_val = $( this ).val();
		if ( is_keepers_val === "0" || is_keepers_val === "-1" ) {
			$( "#filter_by_keeper" ).val( "" );
		}
	}
);
