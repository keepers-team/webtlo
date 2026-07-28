
/* Функции для вкладки настроек. */

// Собрать ссылку на форум из настроек.
function getForumUrl() {
    let domain = $('#forum_url').val();
    if (domain === 'custom') {
        domain = $('#forum_url_custom').val()
    }

    return `https://${domain}`;
}

/**
 * Показать/скрыть пароль и сменить иконку кнопке.
 */
function togglePasswordVisibility(button, input) {
    $(button).find('i.fa').toggleClass('fa-eye fa-eye-slash');

    let inputField = $(input);
    inputField.prop('type', inputField.prop('type') === 'text' ? 'password' : 'text');
}
