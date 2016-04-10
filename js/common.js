/*
 * JS for web-TLO (Web Torrent List Organizer)
 * common.js
 * author: berkut_174 (webtlo@yandex.ru)
 * last change: 11.02.2016
 */

/* текущее время */
function nowTime(){
	var now = new Date();
	var hours = (now.getHours() < 10 ? '0' : '') + now.getHours();
	var minutes = (now.getMinutes() < 10 ? '0' : '') + now.getMinutes();
	var seconds = (now.getSeconds() < 10 ? '0' : '') + now.getSeconds();
	return hours + ':' + minutes + ':' + seconds + ' ';
}

/* перевод байт */
function сonvertBytes(size){
	var filesizename = [" Bytes", " KB", " MB", " GB", " TB", " PB", " EB", " ZB", " YB"];
	return size ? (size / Math.pow(1024, (i = Math.floor(Math.log(size) / Math.log(1024))))).toFixed(2) + filesizename[i] : '0.00';
}

/* список статусов раздач на трекере */
function listTorStatus(){
	var status = [];
	$("#tor_status").closest("div")
		.find("input[type=checkbox]")
		.each(function(){
			status.push($(this).attr('name') + '|' + $(this).attr('title') + '|' + Number($(this).prop('checked')));
	});
	return status;
}

/* проверка закрывающего слеша в конце */
function CheckSlash(e){
	var path = $(e).val();
	last_s = path.slice(-1);
	if(path.indexOf('/') + 1) {
		if(last_s != '/') {
			new_path = path + '/';
		}
		else
			new_path = path;
	}
	else {
		if(last_s != '\\') {
			new_path = path + '\\';
		}
		else
			new_path = path;
	}
	$(e).val(new_path);
};

var lock = 0;

function block_actions(){
	if(lock == 0){
		$(".btn-lock").prop("disabled", true);
		$(".btn_cntrl button").prop("disabled", true);
		$("#loading").show();
		lock = 1;
	} else {
		$(".btn-lock").prop("disabled", false);
		$(".btn_cntrl button").prop("disabled", false);
		$("#loading").hide();
		lock = 0;
	}
};

/* вкл./выкл. полей с настройками прокси-сервера */
//~ function OnProxyProp() {
	//~ $("#proxy_prop .myinput").closest("div")
	//~ .find("input[type=text]")
	//~ .each(function() {
		//~ if($(this).prop("disabled"))
			//~ $(this).prop("disabled", false).addClass("disabledProp");
	//~ });
//~ }
//~ 
//~ function OffProxyProp() {
	//~ $("#proxy_prop .myinput").closest("div")
	//~ .find("input[type=text]")
	//~ .each(function() {
		//~ if($(this).hasClass("disabledProp"))
			//~ $(this).prop("disabled", true).removeClass("disabledProp");
	//~ });
//~ }
