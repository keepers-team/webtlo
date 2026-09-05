/**
 * [Настройки] Параметры подразделов с опциями.
 *
 * @module ModuleNames.CONFIG_MAIN
 * @requires ModuleNames.CONFIG_COMMON
 * @requires ModuleNames.JQUERY_METHODS
 */

webtlo.register(ModuleNames.CONFIG_MAIN,function() {

    /* Связь с форумом и API */

    // Показать/скрыть настройки прокси, при использовании
    $('#proxy_activate_forum, #proxy_activate_report').on('change', function () {
        const anyEnabled =
            $('#proxy_activate_forum').prop('checked') ||
            $('#proxy_activate_report').prop('checked');

        $('#proxy_prop').toggle(anyEnabled);
    });

    $('#forum_url_params').on('change', function () {
        $('#forum_url_result').removeAttr('class');
    });

    $('#report_url_params').on('change', function () {
        $('#report_url_result').removeAttr('class');
    });

    // Показать подсказку, если ключи пустые.
    $('#api_auth_params').on('change mouseleave keyup', function() {
        const emptyKeys= !$('#api_key').val() || !$('#bt_key').val();
        $('#api_auth_params .support-note').toggle(emptyKeys);
    });


    // Кнопка проверки доступности форума и API
    $('#check_mirrors_access').on('click', function () {
        const $data = $('#config').serialize();

        // Проверяемые адреса.
        const check_list = ['forum', 'report'];
        const result_list = ['text-danger', 'text-success'];

        let forumButtons = $('#check_mirrors_access').toggleDisable(true);
        let check_count = check_list.length;

        $.each(check_list, function (index, value) {
            const element = `#${value}_url`;
            const url = $(element).val();

            const elemParam = $(`${element}_params`).find('i');

            let lockElems = $(`.check_access_${value}`)
                .add(element)
                .add(`${element}_custom`)
                .toggleDisable(true);

            if (typeof url === 'undefined' || $.isEmptyObject(url)) {
                check_count--;
                if (check_count === 0) {
                    forumButtons.toggleDisable(false);
                }

                elemParam.removeAttr('class');
                lockElems.toggleDisable(false);

                return true;
            }

            $.ajax({
                type: 'POST',
                url: 'php/check_mirror_access.php',
                data: {
                    url_type  : value,
                    cfg       : $data,
                    url       : url,
                    url_custom: $(`${element}_custom`).val(),
                    proxy     : $(`#proxy_activate_${value}`).is(':checked')
                },
                success: function (response) {
                    response = $.parseJSON(response);

                    lockElems.toggleDisable(false);
                    elemParam.removeAttr('class');

                    const result = result_list[response.result];
                    if (typeof result !== 'undefined') {
                        elemParam.addClass(`fa fa-circle ${result}`);
                    }

                    addDefaultLog(response.log ?? '');
                },
                beforeSend: function () {
                    elemParam.removeAttr('class').addClass('fa fa-spinner fa-spin');
                },
                complete: function () {
                    check_count--;
                    if (check_count === 0) {
                        forumButtons.toggleDisable(false);
                    }
                }
            });

            return true;
        });
    });

    // Открыть ссылку на профиль пользователя.
    // @require forum.func
    $('#forum_profile_link').on('click', function(e) {
        e.preventDefault();

        let user = $('#user_id').val();
        if (!user) {
            return;
        }

        openUserProfile(user);
    });

    // Показать/скрыть пароли/ключи от форума/API.
    $('#show_passwords').on('click', function() {
        togglePasswordVisibility(this, $('.user_protected'))
    });

    // Показать/скрыть пароль от торрент-клиента.
    $('button.torrent-client-password-toggle').on('click', function() {
        togglePasswordVisibility(this, $('#torrent-client-password'))
    });


    /* Фильтрация раздач */

    // Период хранения средних сидов
    $('#avg_seeders_period, #avg_seeders_period_outdated').spinner({
        min: 1,
        max: 30,
        mouseWheel: true
    });

    // "Предлагать для хранения раздачи старше"
    $('#rule_date_release').spinner({
        min: 0,
        mouseWheel: true
    });


    /* Скачивание торрент-файлов */

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


    /* Автоматизация и дополнительные настройки */

    // Регулировка, количество пиров
    $('.spinner-peers').spinner({
        min: -2,
        max: 100,
        mouseWheel: true
    });

    // Регулировка, количество хранителей, "ширина" рандома
    $('.control-keepers-spinner, .control-random-spinner').spinner({
        min: 0,
        max: 10,
        mouseWheel: true
    });

    // Регулировка, количество дней, по прошествии которых раздача считается не сидируемой
    $('.control-unseeded-days-spinner').spinner({
        min: 0,
        max: 30,
        mouseWheel: true
    });

    // Регулировка, максимальное количество не сидируемых раздач, которые можно запустить одновременно
    $('.control-unseeded-count-spinner').spinner({
        min: 0,
        mouseWheel: true
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


    // === ЛОКАЛЬНЫЕ ФУНКЦИИ ===

    /**
     * Показать/скрыть пароль и сменить иконку кнопке.
     */
    function togglePasswordVisibility(button, input) {
        $(button).find('i.fa').toggleClass('fa-eye fa-eye-slash');

        let inputField = $(input);
        inputField.prop('type', inputField.prop('type') === 'text' ? 'password' : 'text');
    }

}, [
    ModuleNames.CONFIG_COMMON,
    ModuleNames.JQUERY_METHODS,
]);
