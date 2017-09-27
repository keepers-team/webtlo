
/* всё про работу с подразделами */

// загрузка данных о выбранном подразделе на главной
var subsections = $("#subsections");
subsections.selectmenu({
	width: "calc(100% - 36px)",
	change: function( event, ui ) {
		getFilteredTopics();
		showSizeAndAmount( 0, 0.00 );
		Cookies.set('saved_forum_id', ui.item.value);
	},
	create: function ( event, ui ) {
		if(typeof(Cookies.get('saved_forum_id')) !== "undefined"){
			subsections.val(parseInt(Cookies.get('saved_forum_id')));
			subsections.selectmenu("refresh");
		}
	},
	open: function( event, ui ) {
		height = $("#subsections-menu").height() >= 399 ? 400 : 'auto';
		$("#subsections-menu").css("height", height);
		active = $("#subsections-button").attr("aria-activedescendant");
		$("#subsections-menu").closest("ul")
			.find("div[role=option]")
			.each( function() {
				$(this).css({ "font-weight": "normal" });
			});
		$("#"+active).css({ "font-weight": "bold" });
	},
});

/* добавить подраздел */
$("#ss-add").autocomplete({
	source: 'php/get_list_subsections.php',
	delay: 1000,
	select: addSubsection
}).on("focusout", function(){
	$(this).val("");
});

function addSubsection(event, ui) {
	if( ui.item.value < 0 ) {
		ui.item.value = '';
		return;
	}
	lb = ui.item.label;
	label = lb.replace(/.* » (.*)$/, '$1');
	vl = ui.item.value;
	q = 0;
	$("#list-ss option").each(function(){
		val = $(this).val();
		if(vl == val) q = 1;
	});
	if(q != 1) {
		$("#list-ss").append('<option value="'+vl+'" data="|'+label+'||">'+lb+'</option>');
		$("#subsections_stored").append('<option value="'+vl+'">'+lb+'</option>');
		$("#ss-prop .ss-prop, #list-ss").prop("disabled", false);
		$("#ss-id").prop("disabled", true);
	}
	$("#list-ss option[value="+vl+"]").prop("selected", "selected").change();
	ui.item.value = '';
	doSortSelect("list-ss");
	doSortSelect("subsections_stored");
	$("#subsections").selectmenu("refresh");
}

/* удалить подраздел */
$("#ss-del").on("click", function() {
	forum_id = $("#list-ss").val();
	if( forum_id ) {
		i = $("#list-ss :selected").index();
		$("#list-ss :selected").remove();
		$("#subsections_stored [value="+forum_id+"]").remove();
		q = $("select[id=list-ss] option").size();
		if(q == 0) {
			$("#ss-prop .ss-prop, #list-ss").val('').prop("disabled", true);
			$("#ss-client :first").prop("selected", "selected");
		} else {
			q == i ? i : i++;
			$("#list-ss :nth-child("+i+")").prop("selected", "selected").change();
		}
		$("#subsections").selectmenu("refresh");
		getFilteredTopics();
	}
});

/* получение св-в выбранного подраздела */
var ss_change;

$("#list-ss").on("change", function(){
	data = $("#list-ss :selected").attr("data");
	data = data.split('|');
	client = $("#ss-client option").filter(function() {
		return $(this).text() == data[0];
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
if($("select[id=list-ss] option").size() > 0) {
	$("#list-ss :first").prop("selected", "selected").change();
} else {
	$("#ss-prop .ss-prop").prop("disabled", true);
}

/* изменение свойств подраздела */
$("#ss-prop").on("focusout", function(){
	cl = $("#ss-client :selected").val() != 0
		? $("#ss-client :selected").text()
		: "";
	lb = $("#ss-label").val();
	fd = $("#ss-folder").val();
	ln = $("#ss-link").val();
	var sub_folder = $("#ss-sub-folder").val();
	$("#list-ss option[value="+ss_change+"]")
		.attr("data", cl+"|"+lb+"|"+fd+"|"+ln+"|"+sub_folder);
});

/* получение данных о подразделах */
function getForumIds() {
	var ids = [];
	$("#list-ss option").each( function() {
		value = $(this).val();
		if ( value != 0 ) {
			ids.push( value );
		}
	});
	return ids;
}

function getForums() {
	var forums = {};
	$( "#list-ss option" ).each( function() {
		value = $( this ).val();
		if ( value != 0 ) {
			text = $( this ).text();
			data = $( this ).attr( "data" );
			data = data.split( "|" );
			forums[value] = {
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
	

