
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
}).on("focusout", function(){
	$(this).val("");
});

function addSubsection(subsection) {
	if( subsection.id < 0 ) {
		subsection.id = '';
		return;
	}
	var lb = subsection.name;
	var label = lb.replace(/.* » (.*)$/, '$1');
	var vl = subsection.id;
	q = 0;
	$("#list-ss option").each(function(){
		var val = $(this).val();
		if(vl == val) q = 1;
	});
	if(q != 1) {
		$("#list-ss").append('<option value="'+vl+'" data="|'+label+'||">'+lb+'</option>');
		$("#subsections_stored").append('<option value="'+vl+'">'+lb+'</option>');
		$("#ss-prop .ss-prop, #list-ss").prop("disabled", false);
		$("#ss-id").prop("disabled", true);
	}
	$("#list-ss option[value="+vl+"]").prop("selected", "selected").change();
	subsection.id = '';
	doSortSelect("list-ss");
	doSortSelect("subsections_stored");
	//redrawTopicsList();
}

/* удалить подраздел */
$("#ss-del").on("click", function() {
	var forum_id = $("#list-ss").val();
	if( forum_id ) {
		i = $("#list-ss :selected").index();
		$("#list-ss :selected").remove();
		$("#subsections_stored [value="+forum_id+"]").remove();
		q = $("select[id=list-ss] option").length;
		if(q == 0) {
			$("#ss-prop .ss-prop, #list-ss").val('').prop("disabled", true);
			$("#ss-client :first").prop("selected", "selected");
		} else {
			q == i ? i : i++;
			$("#list-ss :nth-child("+i+")").prop("selected", "selected").change();
		}
		$('#ss-add').val( '' );
	}
});

/* получение св-в выбранного подраздела */
var ss_change;

$("#list-ss").on("change", function(){
	data = $("#list-ss :selected").attr("data");
	data = data.split('|');
	client = $("#ss-client option").filter(function() {
		return $(this).val() == data[0];
	}).val();
	if( client )
		$("#ss-client [value="+client+"]").prop("selected", "selected");
	else
		$("#ss-client :first").prop("selected", "selected");
	$("#ss-label").val(data[1]);
	$("#ss-folder").val(data[2]);
	$("#ss-link").val(data[3]);
	if (data[4]){
		$("#ss-sub-folder").val(data[4]);
	} else {
		$("#ss-sub-folder :first").prop("selected", "selected");
	}
	ss_change = $(this).val();
	$("#ss-id").val(ss_change);
});

/* при загрузке выбрать первый подраздел в списке */
if($("select[id=list-ss] option").length > 0) {
	$("#list-ss :first").prop("selected", "selected").change();
} else {
	$("#ss-prop .ss-prop").prop("disabled", true);
}

/* изменение свойств подраздела */
$("#ss-prop").on("focusout", function(){
	cl = $("#ss-client :selected").val() != 0
		? $("#ss-client :selected").val()
		: "";
	lb = $("#ss-label").val();
	fd = $("#ss-folder").val();
	ln = $("#ss-link").val();
	var sub_folder = $("#ss-sub-folder").val();
	$("#list-ss option[value="+ss_change+"]")
		.attr("data", cl+"|"+lb+"|"+fd+"|"+ln+"|"+sub_folder);
});

/* получение идентификаторов подразделов */
function listSubsections(){
	var list = [];
	$("#list-ss option").each(function(){
		if($(this).val() != 0) {
			list.push($(this).val());
		}
	});
	return list.join(",");
}

function listDataSubsections(){
	var list = {};
	$("#list-ss option").each(function(){
		if($(this).attr("data") != 0) {
			value = $(this).val();
			text = $(this).text();
			data = $(this).attr("data");
			data = data.split("|");
			list[value] = {
				"id": value,
				"na": text,
				"cl": data[0],
				"lb": data[1],
				"fd": data[2],
				"ln": data[3],
				"sub_folder": data[4]
			};
		}
	});
	return list;
}

$(document).ready(function() {

	// fix у кого старые настройки
	window.onload=function(){
		var pattern = [];

		$("#list-ss option").each(function(){
			text = $(this).text();
			value = $(this).val();
			if( text == "" ) {
				pattern.push(value);
			}
		});

		if(pattern.length){
			$.ajax({
				url: 'php/get_list_subsections.php',
				type: 'GET',
				data: { term : pattern },
				success: function (response) {
					subsection = $.parseJSON(response);
					for (var i in subsection) {
						//~ text = $("#list-ss option[value="+subsection[i].value+"]").text();
						data = $("#list-ss option[value="+subsection[i].value+"]").attr("data");
						data = data.split("|");
						label = subsection[i].label.replace(/.* » (.*)$/, '$1');
						$("#list-ss option[value="+subsection[i].value+"]")
							.attr("data", data[0]+'|'+label+'|'+data[2]+'|'+data[3])
							.text(subsection[i].label);
					}
					$("#list-ss").change();
				},
			});
		}
	}
});