/*
 * JS for web-TLO (Web Torrent List Organizer)
 * tor_clients.js
 * author: berkut_174 (webtlo@yandex.ru)
 * last change: 11.02.2016
 */

/* всё про торрент-клиенты */

/* получение св-в выбранного т.-клиента */
$("#list-tcs").on("change", function() {
	val = $("#list-tcs").val()
	val = val.split('|');
	$("#TC_comment").val(val[0]);
	$("#TC_client [value="+val[1]+"]").prop("selected", "selected");
	$("#TC_hostname").val(val[2]);
	$("#TC_port").val(val[3]);
	$("#TC_login").val(val[4]);
	$("#TC_password").val(val[5]);
});

/* при загрузке выбрать первый т.-клиент в списке */
if($("select[id=list-tcs] option").size() > 1) {
	$("#list-tcs :nth-child(2)").prop("selected", "selected").change();
} else {
	$("#tc-prop .tc-prop").prop("disabled", true);
}

/* изменение свойств т.-клиента */
$("#tc-prop .tc-prop").on("keyup mouseup", function(){
	cm = $("#TC_comment").val();
	cl = $("#TC_client").val();
	ht = $("#TC_hostname").val();
	pt = $("#TC_port").val();
	lg = $("#TC_login").val();
	pw = $("#TC_password").val();
	$("#list-tcs :selected").val(cm+"|"+cl+"|"+ht+"|"+pt+"|"+lg+"|"+pw);
	$("#list-tcs :selected").text(cm);
});

/* удалить т.-клиент из списка */
$("#del-tc").on("click", function() {
	if($("#list-tcs").val()) {
		i = $("#list-tcs :selected").index();
		//alert(i);
		$("#list-tcs :selected").remove();			
		q = $("select[id=list-tcs] option").size();
		if(q == 1) {
			$("#tc-prop .tc-prop").val('').prop("disabled", true).removeAttr("checked");
		} else {
			//~ i == 1 ? i++ : i;
			q == i ? i : i++;
			$("#list-tcs :nth-child("+i+")").prop("selected", "selected").change();
		}
	}
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
	if($("#list-tcs").val()) {
		num_new = 0;
		$("#list-tcs option").each(function(){
			val = $(this).val();
			val = val.split('|');
			nm_tmp = val[0].replace(/\d*$/, '');
			num_tmp = val[0].replace(nm_tmp, '');
			zero_tmp = num_tmp.replace(/[^0].*/, '');
			if((nm_tmp == nm) && (parseInt(num_tmp) > num_new) && (zero == zero_tmp)) {
				num_new = num_tmp;
			}			
		});
		num_new++;
		cm_new = nm+'|'+zero+'|'+num_new;
		cm_new = (cm.length < cm_new.length - 2 ? cm_new.replace(/\|0*\|/, zero.slice(0,-1)) : cm_new.replace(/\|/g, ''));
		$("#list-tcs").append('<option value="'+cm_new+'|'+cl+
			'|'+ht+'|'+pt+'|'+lg+'|'+pw+'">'+cm_new+'</option>');
	} else {
		$("#list-tcs").append('<option value="client1|utorrent||||">client1</option>' );
	}
	$("#list-tcs :last").prop("selected", "selected").change();
});

/* получение списка т.-клиентов */
function listTorClients(){
	var list = [];
	$("#list-tcs option").each(function(){
		if($(this).val() != 0) {
			list.push($(this).val());
		}
	});
	return list;
}
