
/* Функции для вкладки настроек. */

// Собрать ссылку на форум из настроек.
function getForumUrl() {
    const scheme = $('#forum_ssl').is(':checked') ? 'https' : 'http';
    let domain = $('#forum_url').val();
    if (domain === 'custom') {
        domain = $('#forum_url_custom').val()
    }

    return `${scheme}://${domain}`;
}
