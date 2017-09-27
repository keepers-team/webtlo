
/* всё про торрент-клиенты */

/* получение св-в выбранного т.-клиента */
var tc_change;

$("#list-tcs").on("change", function() {
	val = $("#list-tcs :selected").attr("data");
	val = val.split('|');
	$("#TC_comment").val(val[0]);
	$("#TC_client [value="+val[1]+"]").prop("selected", "selected");
	$("#TC_hostname").val(val[2]);
	$("#TC_port").val(val[3]);
	$("#TC_login").val(val[4]);
	$("#TC_password").val(val[5]);
	tc_change = $(this).val();
});

/* при загрузке выбрать первый т.-клиент в списке */
if($("select[id=list-tcs] option").size() > 1) {
	$("#list-tcs :nth-child(2)").prop("selected", "selected").change();
} else {
	$("#tc-prop .tc-prop").prop("disabled", true);
}

/* изменение свойств т.-клиента */
$("#tc-prop").on("focusout", function(){
	cm_old = $("#list-tcs option[value="+tc_change+"]").text();
	cm = $("#TC_comment").val() != "" ? $("#TC_comment").val() : tc_change;
	cl = $("#TC_client").val();
	ht = $("#TC_hostname").val();
	pt = $("#TC_port").val();
	lg = $("#TC_login").val();
	pw = $("#TC_password").val();
	$("#list-tcs option[value="+tc_change+"]")
		.attr("data", cm+"|"+cl+"|"+ht+"|"+pt+"|"+lg+"|"+pw)
		.text(cm);
	$("#list-ss option").each(function(){
		data = $(this).attr("data");
		arr = data.split("|");
		if( arr[0] == cm_old )
			$(this).attr("data", data.replace(/^[^|]*/, cm));
	});
	doSortSelect("list-tcs");
});

/* удалить т.-клиент из списка */
$("#del-tc").on("click", function() {
	if($("#list-tcs").val()) {
		i = $("#list-tcs :selected").index();
		$("#list-tcs :selected").remove();			
		q = $("select[id=list-tcs] option").size();
		if(q == 1) {
			$("#tc-prop .tc-prop").val('').prop("disabled", true);
		} else {
			q == i ? i : i++;
			$("#list-tcs :nth-child("+i+")").prop("selected", "selected").change();
		}
	}
	$("#list-ss option").each(function(){
		data = $(this).attr("data");
		cl = data.split("|");
		value = $("#list-tcs option").filter(function() {
			return $(this).text() == cl[0];
		}).val();
		if(!value)
			$(this).attr("data", data.replace(/^[^|]*/, ""));
	});
});

/* добавить т.-клиент в список */
$("#add-tc").on("click", function() {
	$("#tc-prop .tc-prop").prop("disabled", false);
	cm = $("#TC_comment").val();
	nm = cm.replace(/\d*$/, '');
	num = cm.replace(nm, '');
	zero = num.replace(/[^0].*/, '');
	cl = $("#TC_client").val();
	ht = $("#TC_hostname").val();
	pt = $("#TC_port").val();
	lg = $("#TC_login").val();
	pw = $("#TC_password").val();
	q = 1;
	if($("#list-tcs").val()) {
		num_new = 0;
		$("#list-tcs option").each(function(){
			val = parseInt($(this).val());
			q = val > q ? val : q;
			data = $(this).attr("data");
			data = data.split("|");
			nm_tmp = data[0].replace(/\d*$/, '');
			num_tmp = data[0].replace(nm_tmp, '');
			zero_tmp = num_tmp.replace(/[^0].*/, '');
			if((nm_tmp == nm) && (parseInt(num_tmp) > num_new) && (zero == zero_tmp)) {
				num_new = num_tmp;
			}
		});
		num_new++;
		q++;
		cm_new = nm+'|'+zero+'|'+num_new;
		cm_new = (cm.length < cm_new.length - 2 ? cm_new.replace(/\|0*\|/, zero.slice(0,-1)) : cm_new.replace(/\|/g, ''));
		$("#list-tcs").append('<option value="'+q+'" data="'+cm_new+'|'+cl+
			'|'+ht+'|'+pt+'|'+lg+'|'+pw+'">'+cm_new+'</option>');
	} else {
		$("#list-tcs").append('<option value="'+q+'" data="client1|utorrent||||">client1</option>' );
	}
	$("#list-tcs option[value="+q+"]").prop("selected", "selected").change();
	doSortSelect("list-tcs");
});

// проверка доступности торрент-клиента
$("#online-tc").on("click", function() {
	if( $("#list-tcs").val() ) {
		data = $("#list-tcs :selected").attr("data");
		data = data.split("|");
		$.ajax({
			url: 'php/actions/tor_client_is_online.php',
			type: 'POST',
			context: this,
			data: { tor_client : data },
			beforeSend: function() {
				$("#result-tc").text("");
				$(this).children("i").css("display", "inline-block");
				$(this).prop("disabled", true);
			},
			success: function (response) {
				response = $.parseJSON(response);
				$("#log").append(response.log);
				$("#result-tc").html(response.status);
			},
			complete: function() {
				$(this).prop("disabled", false);
				$(this).children("i").hide();
			},
		});
	}
});

/* обновление списка используемых торрент-клиентов */
function listClientsRefresh() {
	$("#ss-client option").each(function(){
		if($(this).val() != 0)
			$(this).remove();
	});
	$("#list-tcs option").each(function(){
		id = $(this).val();
		client = $(this).attr("data");
		client = client.split("|");
		if(id != 0)
			$("#ss-client").append('<option value="'+id+'">'+client[0]+'</option>' );
	});
	if($("select[id=list-ss] option").size() > 0) {
		$("#list-ss").change();
	}
}

$("#add-tc, #del-tc").click(listClientsRefresh);
$("#tc-prop").focusout(listClientsRefresh);

/* получение списка т.-клиентов */
function getTorClients() {
	var tor_clients = {};
	$( "#list-tcs option" ).each( function() {
		value = $( this ).val();
		if ( value != 0 ) {
			data = $( this ).attr( "data" );
			data = data.split( "|" );
			tor_clients[value] = {
				"cm": data[0],
				"cl": data[1],
				"ht": data[2],
				"pt": data[3],
				"lg": data[4],
				"pw": data[5]
			};
		}
	});
	return tor_clients;
}

window.onload=listClientsRefresh();
