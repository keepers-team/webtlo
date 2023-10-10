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
    })

});
