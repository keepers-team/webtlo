
/* Общие вспомогательные функции */

const COLLATOR_EN = new Intl.Collator('en', {numeric: true, sensitivity: 'base'});
const COLLATOR_RU = new Intl.Collator('ru', {numeric: true, sensitivity: 'base'});

/**
 * EN → RU comparator
 */
const compareByLocale = (a, b) =>
    COLLATOR_EN.compare(a, b) ||
    COLLATOR_RU.compare(a, b);

/* перевод байт */
function convertBytes(size) {
    const units = [' Bytes', ' KB', ' MB', ' GB', ' TB', ' PB', ' EB', ' ZB', ' YB'];

    const pow  = Math.floor(Math.log(size) / Math.log(1024));

    return size ? (size / Math.pow(1024, pow)).toFixed(2) + units[pow] : '0.00';
}

function clearLoadResult() {
    $('#topics_result, #topics_timer').html('');
}

function showResultTopics(text = '') {
    $('#topics_result').html(text);
}

function addDefaultLog(log) {
    if (log) {
        $('#log').prepend(log);
    }
}

let processStatus = {
    status : $('.process-status'),
    loading: $('.process-loading'),
    show: function () {
        this.loading.show();
    },
    hide: function () {
        this.loading.hide();
    },
    set: function (text = '') {
        this.status.text(text);
        if (text) {
            this.show();
        }
    }
};


let lock_actions = 0;
function block_actions() {
    let buttons = $('#topics_control button')
        .add("button.send_reports")
        .not('button.disabled-manual');


    if (lock_actions === 0) {
        buttons.toggleDisable(true);

        $("#main-subsections, .filter-select-menu").selectmenu("disable");

        processStatus.show();
        lock_actions = 1;
    } else {
        buttons.toggleDisable(false);

        if (
            $("#main-subsections").val() < 1
            || !$("input[name=filter_status]").eq(1).prop("checked")
        ) {
            $(".tor_add").toggleDisable(true);
        } else {
            $(".tor_stop, .tor_remove, .tor_label, .tor_start").toggleDisable(true);
        }
        $("#main-subsections, .filter-select-menu").selectmenu("enable");

        processStatus.hide();
        lock_actions = 0;
    }
}

// выполнить функцию с задержкой
function makeDelay(ms) {
    let timer = 0;
    return function (callback, scope) {
        clearTimeout(timer);
        timer = setTimeout(function () {
            callback.apply(scope);
        }, ms);
    }
}

function functionDelay(callback, ms) {
    let timer = 0;
    return function () {
        let context = this, args = arguments;
        clearTimeout(timer);
        timer = setTimeout(function () {
            callback.apply(context, args);
        }, ms);
    };
}

// Задержка при прокрутке подразделов.
const delayCallback500 = makeDelay(500);

/**
 * Сортировка элементов в select.
 *
 * @param {string} selectId
 * @param {string} sortElement
 */
function doSortSelect(selectId, sortElement = 'option') {
    const $select = $(`#${selectId}`);

    const sorted = $select.find(sortElement).get().sort((a, b) => {
        if (!a.value) return -1;
        if (!b.value) return 1;

        return compareByLocale(
            $(a).text().toUpperCase(),
            $(b).text().toUpperCase()
        );
    });

    $select.empty().append(sorted);
}

function copyToClipboard(text) {
    // Пытаемся скопировать через современный API.
    if (document.hasFocus() && navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then();

        return true;
    }

    return false;
}

// Выделить тело объекта.
function selectBlockText(elem) {
    if (window.getSelection) {
        const sel = window.getSelection();
        if (sel.setBaseAndExtent) {
            sel.setBaseAndExtent(elem, 0, elem, elem.childNodes.length);
        } else {
            const range = document.createRange();
            range.selectNodeContents(elem);
            sel.removeAllRanges();
            sel.addRange(range);
        }
    } else if (document.getSelection) {
        const sel = document.getSelection();
        const range = document.createRange();
        range.selectNodeContents(elem);
        sel.removeAllRanges();
        sel.addRange(range);
    } else if (document.selection) {
        const range = document.body.createTextRange();
        range.moveToElementText(elem);
        range.select();
    }
}

// Проверить наличие новой версии.
function checkNewVersion() {
    const current_version = $('title').text().split('-')[2];
    const new_version_last_checked = Cookies.get('new-version-last-checked');

    if (
        new_version_last_checked !== undefined
        && ($.now() - new_version_last_checked) <= 60000
    ) {
        if (versionCompare(current_version, Cookies.get('new-version-number')) < 0) {
            showNewVersion(
                Cookies.get('new-version-number'),
                Cookies.get('new-version-link'),
                Cookies.get('new-version-whats-new')
            );
        }
        return;
    }

    $.ajax({
        type: 'POST',
        url: 'php/check_new_version.php',
        success: function (response) {
            addDefaultLog(response.log ?? '');

            Cookies.set('new-version-number', response.newVersionNumber);
            Cookies.set('new-version-link', response.newVersionLink);
            Cookies.set('new-version-whats-new', response.whatsNew);
            Cookies.set('new-version-last-checked', $.now());

            if (versionCompare(current_version, response.newVersionNumber) < 0) {
                showNewVersion(response.newVersionNumber, response.newVersionLink, response.whatsNew)
            }
        },
    });
}

function showNewVersion(newVersionNumber, newVersionLink, whatsNew) {
    $('#new_version_description')
        .attr('title', whatsNew)
        .append(`(Доступно обновление: <a id="new_version_link" target="_blank" href="${newVersionLink}">v${newVersionNumber}</a>)`);
}

// http://stackoverflow.com/a/6832721/50079
function versionCompare(v1, v2, options) {
    let lexicographical = options && options.lexicographical,
        zeroExtend = options && options.zeroExtend,
        v1parts = v1.split('.'),
        v2parts = v2.split('.');

    function isValidPart(x) {
        return (lexicographical ? /^\d+[A-Za-z]*$/ : /^\d+$/).test(x);
    }

    if (!v1parts.every(isValidPart) || !v2parts.every(isValidPart)) {
        return NaN;
    }

    if (zeroExtend) {
        while (v1parts.length < v2parts.length) v1parts.push("0");
        while (v2parts.length < v1parts.length) v2parts.push("0");
    }

    if (!lexicographical) {
        v1parts = v1parts.map(Number);
        v2parts = v2parts.map(Number);
    }

    for (let i = 0; i < v1parts.length; ++i) {
        if (v2parts.length === i) {
            return 1;
        }
        if (v1parts[i] === v2parts[i]) {
            continue;
        }
        else if (v1parts[i] > v2parts[i]) {
            return 1;
        }
        else {
            return -1;
        }
    }

    if (v1parts.length !== v2parts.length) {
        return -1;
    }

    return 0;
}
