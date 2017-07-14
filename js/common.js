
/* вспомогательные функции */

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
}
var lock = 0;

function block_actions(){
	if(lock == 0){
		$(".topics_control button").prop("disabled", true);
		$("#subsections").selectmenu("disable");
		$("#loading").show();
		lock = 1;
	} else {
		$(".topics_control button").prop("disabled", false);
		if( $("#subsections").val() < 1 || !$("input[name=filter_status]").eq(1).prop("checked") ) {
			$(".tor_add").prop("disabled", true);
		} else {
			$(".tor_stop, .tor_remove, .tor_label, .tor_start").prop("disabled", true);
		}
		$("#subsections").selectmenu("enable");
		$("#loading, .loading").hide();
		lock = 0;
	}
}
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

// сортировка в select
function doSortSelect( select_id ) {
	var sortedVals = $.makeArray($('#'+select_id+' option')).sort( function(a,b) {
		if( $(a).val() == 0 ) return -1;
		return $(a).text().toUpperCase() > $(b).text().toUpperCase() ? 1 : $(a).text().toUpperCase() < $(b).text().toUpperCase() ? -1 : 0 ;
	});
	$('#'+select_id).empty().html(sortedVals);
}

function doSortSelectByValue( select_id ) {
	var sortedVals = $.makeArray($('#'+select_id+' option')).sort( function(a,b) {
		if( $(a).val() == 0 ) return -1;
		return $(a).val().toUpperCase() > $(b).val().toUpperCase() ? 1 : $(a).val().toUpperCase() < $(b).val().toUpperCase() ? -1 : 0 ;
	});
	$('#'+select_id).empty().html(sortedVals);
}
