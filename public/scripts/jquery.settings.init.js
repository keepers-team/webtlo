/* Инициализация работы с настройками */

$(document).ready(function() {

    // Показать/скрыть пароли/ключи от форума/API.
    $('#show_passwords').on('click', function() {
        togglePasswordVisibility(this, $('.user_protected'))
    });

    // Показать/скрыть пароль от торрент-клиента.
    $('button.torrent-client-password-toggle').on('click', function() {
        togglePasswordVisibility(this, $('#torrent-client-password'))
    });

    // Показать/скрыть произвольные адреса для форума и api
    $('#forum_url, #report_url').on('selectmenucreate selectmenuchange', function() {
        const name = $(this).prop('name');

        $(`#${name}_custom`).toggle($(this).val() === 'custom');
    });

    // Показать/скрыть настройки прокси, при использовании
    $('#proxy_activate_forum, #proxy_activate_report').change(function () {
        const anyEnabled =
            $('#proxy_activate_forum').prop('checked') ||
            $('#proxy_activate_report').prop('checked');

        $('#proxy_prop').toggle(anyEnabled);
    }).change();

    $("#forum_url_params").on("change", function () {
        $("#forum_url_result").removeAttr("class");
    });

    $("#report_url_params").on("change", function () {
        $("#report_url_result").removeAttr("class");
    });

    // Показать подсказку, если ключи пустые.
    $('#api_auth_params').on('change click keyup', function() {
        const emptyKeys= !$('#api_key').val() || !$('#bt_key').val();
        $('#api_auth_params .support-note').toggle(emptyKeys);
    }).change();

    // Открыть ссылку на профиль пользователя.
    $('#forum_profile_link').click(function(e) {
        e.preventDefault();

        let user = $('#user_id').val();
        if (!user) {
            return;
        }

        openUserProfile(user);
    });

    // topics_control => interval / TopicControl => peersLimitIntervals
    $('#peers_intervals').on('keypress', function(e) {
        const charStr = String.fromCharCode(e.which);

        // Разрешаем только цифры и допустимые символы [:;\|/].
        if (!/[0-9:;\\|\/,]/.test(charStr)) {
            e.preventDefault(); // Блокируем ввод, если символ не подходит
        }
    }).on('click focus blur', function() {
        // Заменяем недопустимые символы.
        this.value = this.value.replace(/[^0-9:;\\|\/,]/g, '');
    });

    // Переносим значения радио кнопок из скрытых элементов формы.
    $('#config .radio_from_input').each(function() {
        if (this.value === '') {
            return false;
        }

        $(`input[type=radio][name='${this.id}'][value=${this.value}]`).prop('checked', true);
    });

    // Инициализация кнопок настроек.
    $('#config .config_controlgroup').controlgroup({
        classes: {
            'ui-controlgroup': 'hide-dot ui-padding-02'
        }
    });

    // Проверка закрывающего слэша.
    $('#savedir, #dir_torrents').on('change', function () {
        const path = this.value.trim(); // Убираем лишние пробелы.
        if (!path) return;

        const lastChar = path.slice(-1);

        // Если уже заканчивается на / или \ – ничего не делаем
        if (lastChar === '/' || lastChar === '\\') return;

        // Определяем разделитель: если путь содержит '/', то используем '/', иначе '\'
        const separator = path.includes('/') ? '/' : '\\';
        if (lastChar !== separator) {
            this.value = path + separator;
        }
    });


    // сохранение настроек
    $("#savecfg")
        .on("click", setSettings)
        .on("change", function () {
            const unsaved = !!+$(this).data('unsaved');
            $(this).toggleClass("ui-state-highlight", unsaved);
        });

    // Проверяем, что настройки были изменены
    $("form#config :input").not(".ignore-save-change").on("change selectmenuchange spinstop", function () {
        $("#savecfg").data('unsaved', true).change();
    });

});
