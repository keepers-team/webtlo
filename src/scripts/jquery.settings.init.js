/* Инициализация работы с настройками */

$(document).ready(function () {

    $('#show_passwords').on('click', function () {
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

        const domain = getForumUrl()
        const url = `${domain}/forum/profile.php?mode=viewprofile&u=${user}`;
        window.open(url, '_blank');
    });

});
