/* Инициализация работы с настройками */

$(document).ready(function() {

    $('#show_passwords').on('click', function() {
        $(this).find('i.fa').toggleClass('fa-eye fa-eye-slash');

        let elems = $('.user_protected');
        if (elems.prop('type') === 'text') {
            elems.prop('type', 'password');
        } else {
            elems.prop('type', 'text');
        }
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

    // Переносим значения радио кнопок из скрытых элементов формы.
    $('#config .radio_from_input').each(function() {
        $(`input[type=radio][name='${this.id}'][value=${this.value}]`).prop('checked', true);
    });

    // Инициализация кнопок настроек.
    $('#config .config_controlgroup').controlgroup({
        classes: {
            'ui-controlgroup': 'hide-dot ui-padding-02'
        }
    });

});
