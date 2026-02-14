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

    // Открыть ссылку на профиль пользователя.
    $('#tracker_username').next('i').click(function(e) {
        e.preventDefault();

        let user = $('#user_id').val();
        if (!user) {
            user = $('#tracker_username').val();
        }
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

});
