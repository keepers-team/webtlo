
/* всё про работу с подразделами */

/* добавить подраздел */
$("#ss-add").autocomplete({
	source: 'php/get_list_subsections.php',
	delay: 1000,
	select: addSubsection
});

function addSubsection(event, ui) {
	lb = ui.item.label;
	label = lb.replace(/.* » (.*)$/, '$1');
	vl = ui.item.value;
	q = 0;
	$("#list-ss option").each(function(){
		val = $(this).val();
		if(vl == val) q = 1;
	});
	if(q != 1) {
		$("#list-ss").append('<option value="'+vl+'" data="'+vl+'|'+lb+'||'+label+'||">'+label+'</option>');
		$("#ss-prop .ss-prop").prop("disabled", false);
		$("#list-ss :last").prop("selected", "selected").change();
	} else {
		$("#list-ss option[value="+vl+"]").prop("selected", "selected").change();
	}
	ui.item.value = '';
}

/* удалить подраздел */
$("#list-ss").on("keydown", function(e) {
	if (e.which == 46) {
		if($("#list-ss").val()) {
			i = $("#list-ss :selected").index();
			$("#list-ss :selected").remove();			
			q = $("select[id=list-ss] option").size();
			if(q == 1) {
				$("#ss-prop .ss-prop").val('').prop("disabled", true);
				$("#ss-client :first").prop("selected", "selected");
			} else {
				q == i ? i : i++;
				$("#list-ss :nth-child("+i+")").prop("selected", "selected").change();
			}
		}
	}
});

/* получение св-в выбранного подраздела */
var ss_change;

$("#list-ss").on("change", function(){
	val = $("#list-ss :selected").attr('data');
	val = val.split('|');
	$("#ss-id").prop("disabled", true);
	$("#ss-title").prop("disabled", true);
	$("#ss-id").val(val[0]);
	$("#ss-title").val(val[1]);
	if($("#ss-client [value="+val[2]+"]").val())
		$("#ss-client [value="+val[2]+"]").prop("selected", "selected");
	else
		$("#ss-client :first").prop("selected", "selected");
	$("#ss-label").val(val[3]);
	$("#ss-folder").val(val[4]);
	$("#ss-link").val(val[5]);
	ss_change = $(this).val();
});

/* при загрузке выбрать первый подраздел в списке */
if($("select[id=list-ss] option").size() > 1) {
	$("#list-ss :nth-child(2)").prop("selected", "selected").change();
} else {
	$("#ss-prop .ss-prop").prop("disabled", true);
}

/* изменение свойств подраздела */
$("#ss-prop").on("focusout", function(){
	id = $("#ss-id").val();
	na = $("#ss-title").val();
	cl = $("#ss-client").val();
	lb = $("#ss-label").val();
	fd = $("#ss-folder").val();
	ln = $("#ss-link").val();
	$("#list-ss option[value="+ss_change+"]")
		.attr("data", id+"|"+na+"|"+cl+"|"+lb+"|"+fd+"|"+ln)
		.val(id)
		.text(na.replace(/.* » (.*)$/, '$1'));
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
	var list = [];
	$("#list-ss option").each(function(){
		if($(this).attr("data") != 0) {
			data = $(this).attr("data");
			data = data.split("|");
			list.push({
				id: data[0],
				na: data[1],
				cl: data[2],
				lb: data[3],
				fd: data[4],
				ln: data[5]
			});
		}
	});
	return list;
}

$(document).ready(function() {

	// fix у кого старые настройки
	window.onload=function(){
		var pattern = [];
		
		$("#list-ss option").each(function(){
			var data = $(this).attr("data");			
			data = data.split("|");
			if(data[1] == ""){
				pattern.push(data[0]);
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
						data = $("#list-ss option[value="+subsection[i].value+"]").attr("data");
						data = data.split("|");
						label = subsection[i].label.replace(/.* » (.*)$/, '$1');
						$("#list-ss option[value="+subsection[i].value+"]")
							.attr("data", data[0]+'|'+subsection[i].label+'|'+data[2]+'|'+label+'|'+data[4]+'|'+data[5])
							.text(label);
					}
					$("#list-ss").change();
				},
			});
		}
	}
	
});
	

