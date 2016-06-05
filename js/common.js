/*
 * JS for web-TLO (Web Torrent List Organizer)
 * common.js
 * author: berkut_174 (webtlo@yandex.ru)
 * last change: 11.02.2016
 */

/* текущее время */
function nowTime(){
	var now = new Date();
	var day = (now.getDate() < 10 ? '0' : '') + now.getDate();
	var month = (parseInt(now.getMonth() + 1) < 10 ? '0' : '') + parseInt(now.getMonth() + 1);
	var year = now.getFullYear();
	var hours = (now.getHours() < 10 ? '0' : '') + now.getHours();
	var minutes = (now.getMinutes() < 10 ? '0' : '') + now.getMinutes();
	var seconds = (now.getSeconds() < 10 ? '0' : '') + now.getSeconds();
	return day + '.' + month + '.' + year + ' ' + hours + ':' + minutes + ':' + seconds + ' ';
}

/* перевод байт */
function сonvertBytes(size){
	var filesizename = [" Bytes", " KB", " MB", " GB", " TB", " PB", " EB", " ZB", " YB"];
	return size ? (size / Math.pow(1024, (i = Math.floor(Math.log(size) / Math.log(1024))))).toFixed(2) + filesizename[i] : '0.00';
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

// выполнить функцию с задержкой
function makeDelay(ms){
	var timer = 0;
	return function (callback, scope){
		clearTimeout (timer);
		timer = setTimeout (function(){
             callback.apply(scope);
        }, ms);
	}
}

// инициализация диалога
$('#dialog').dialog({ autoOpen: false, width: 500 });
