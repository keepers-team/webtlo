/* всё про работу с подразделами */

// загрузка данных о выбранном подразделе на главной
var $subsections = $( "#subsections" );
$subsections.on( "change", function () {
	redrawTopicsList();
	Cookies.set( 'saved_forum_id', $subsections.val() );
} );

if ( typeof(Cookies.get( 'saved_forum_id' )) !== "undefined" ) {
	$subsections.val( parseInt( Cookies.get( 'saved_forum_id' ) ) );
}

/* добавить подраздел */
$( '#ss-add' ).typeahead( {
	source: function ( query, process ) {
		$.ajax( {
			url: 'php/get_list_subsections.php',
			type: 'GET',
			data: { term: query },
			success: function ( response ) {
				var result = JSON.parse( response );
				return process( result );
			}
		} );
	},
	items: 12,
	delay: 1000,
	afterSelect: addSubsection,
	fitToElement: true,
	matcher: function ( item ) {
		var it = this.displayText( item );
		if ( (~it.toLowerCase().indexOf( this.query.toLowerCase() ) !== 0) || (this.query === item.id) ) {
			return true;
		}
	}
} ).on( "focusout", function () {
	$( this ).val( "" );
} );

var $list_ss = $( "#list-ss" );

function addSubsection( subsection ) {
	if ( subsection.id < 0 ) {
		subsection.id = '';
		return;
	}
	var lb = subsection.name;
	var label = lb.replace( /.* » (.*)$/, '$1' );
	var vl = subsection.id;
	var q = 0;
	$list_ss.find( "option" ).each( function () {
		var val = $( this ).val();
		if ( vl == val ) {
			q = 1;
		}
	} );
	if ( q != 1 ) {
		$list_ss.append( '<option value="' + vl + '" data="0|' + label + '||">' + lb + '</option>' );
		$( "#subsections_stored" ).append( '<option value="' + vl + '">' + lb + '</option>' );
		$( "#ss-prop .ss-prop, #list-ss" ).prop( "disabled", false );
		$( "#ss-id" ).prop( "disabled", true );
	}
	$list_ss.find( "option[value=" + vl + "]" ).prop( "selected", "selected" ).change();
	subsection.id = '';
	doSortSelect( "list-ss" );
	doSortSelect( "subsections_stored" );
}

/* удалить подраздел */
$( "#ss-del" ).on( "click", function () {
	var forum_id = $list_ss.val();
	if ( forum_id ) {
		var $list_ss_selected = $list_ss.find( ":selected" );
		var i = $list_ss_selected.index();
		$list_ss_selected.remove();
		$( "#subsections_stored" ).find( "[value=" + forum_id + "]" ).remove();
		q = $( "select[id=list-ss] option" ).length;
		if ( q == 0 ) {
			$( "#ss-prop .ss-prop, #list-ss" ).val( '' ).prop( "disabled", true );
			$( "#ss-client" ).find( ":first" ).prop( "selected", "selected" );
		} else {
			i = q == i ? i : ++i;
			$list_ss.find( ":nth-child(" + i + ")" ).prop( "selected", "selected" ).change();
		}
		$( '#ss-add' ).val( '' );
	}
} );

/* получение св-в выбранного подраздела */
var ss_change;

$list_ss.on( "change", function () {
	var $ss_client = $( "#ss-client" );
	var $ss_sub_folder = $( "#ss-sub-folder" );
	var data = $list_ss.find( ":selected" ).attr( "data" );
	data = data.split( '|' );
	var client = $ss_client.find( "option [value=" + data[0] + "]" ).val();
	if ( client ) {
		$ss_client.find( "[value=" + client + "]" ).prop( "selected", "selected" );
	} else {
		$ss_client.find( ":first" ).prop( "selected", "selected" );
	}
	$( "#ss-label" ).val( data[ 1 ] );
	$( "#ss-folder" ).val( data[ 2 ] );
	$( "#ss-link" ).val( data[ 3 ] );
	if ( data[ 4 ] ) {
		$ss_sub_folder.val( data[ 4 ] );
	} else {
		$ss_sub_folder.find( ":first" ).prop( "selected", "selected" );
	}
	ss_change = $( this ).val();
	$( "#ss-id" ).val( ss_change );
} );

var $ss_prop = $( "#ss-prop" );
/* при загрузке выбрать первый подраздел в списке */
if ( $( "select[id=list-ss] option" ).length > 0 ) {
	$list_ss.find( ":first" ).prop( "selected", "selected" ).change();
} else {
	$ss_prop.find( ".ss-prop" ).prop( "disabled", true );
}

/* изменение свойств подраздела */
$ss_prop.on( "focusout", function () {
	var $ss_client_selected = $( "#ss-client" ).find( ":selected" );
	var cl = $ss_client_selected.val();
	var lb = $( "#ss-label" ).val();
	var fd = $( "#ss-folder" ).val();
	var ln = $( "#ss-link" ).val();
	var sub_folder = $( "#ss-sub-folder" ).val();
	$list_ss.find( "option[value=" + ss_change + "]" )
		.attr( "data", cl + "|" + lb + "|" + fd + "|" + ln + "|" + sub_folder );
} );

/* получение данных о подразделах */
function getForumIds() {
	var ids = [];
	$list_ss.find( "option" ).each( function () {
		var value = $(this).val();
		if ( value != 0 ) {
			ids.push( value );
		}
	} );
	return ids;
}

function getForums() {
	var forums = {};
	$list_ss.find( "option" ).each( function () {
		value = $( this ).val();
		if ( value != 0 ) {
			text = $( this ).text();
			data = $( this ).attr( "data" );
			data = data.split( "|" );
			forums[value] = {
				"id": value,
				"na": text,
				"cl": data[ 0 ],
				"lb": data[ 1 ],
				"fd": data[ 2 ],
				"ln": data[ 3 ],
				"sub_folder": data[ 4 ]
			};
		}
	} );
	return forums;
}

function getForumLinks() {
	var links = [];
	$( "#list-ss option" ).each( function() {
		value = $( this ).val();
		if ( value != 0 ) {
			data = $( this ).attr( "data" );
			data = data.split( "|" );
			if ( typeof data[3] !== "undefined" ) {
				links[value] = data[3];
			}
		}
	});
	return links;
}

$( document ).ready( function () {

	// fix у кого старые настройки
	window.onload = function () {
		var pattern = [];

		$list_ss.find( "option" ).each( function () {
			var text = $( this ).text();
			var value = $( this ).val();
			if ( text == "" ) {
				pattern.push( value );
			}
		} );

		if ( pattern.length ) {
			$.ajax( {
				url: 'php/get_list_subsections.php',
				type: 'GET',
				data: { term: pattern },
				success: function ( response ) {
					var subsection = $.parseJSON( response );
					for ( var i in subsection ) {
						//~ text = $("#list-ss option[value="+subsection[i].value+"]").text();
						var data = $list_ss.find( "option[value=" + subsection[ i ].value + "]" ).attr( "data" );
						data = data.split( "|" );
						var label = subsection[ i ].label.replace( /.* » (.*)$/, '$1' );
						$list_ss.find( "option[value=" + subsection[ i ].value + "]" )
							.attr( "data", data[ 0 ] + '|' + label + '|' + data[ 2 ] + '|' + data[ 3 ] )
							.text( subsection[ i ].label );
					}
					$list_ss.change();
				}
			} );
		}
	}
} );