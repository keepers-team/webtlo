
/* Функции для открытия ссылок форума. */

/**
 * Собрать ссылку на форум из настроек.
 *
 * @returns {string}
 */
function getForumUrl() {
    const defaultDomain = 'rutracker.org';

    let domain = $('#forum_url').val();
    if (domain === 'custom') {
        domain = $('#forum_url_custom').val()
    }

    if (!domain) {
        domain = defaultDomain;
    }

    return `https://${domain}`;
}

/**
 * Открыть профиль пользователя.
 *
 * @param {number|string} user id/name
 */
function openUserProfile(user) {
    if (!user) {
        return;
    }

    const domain = getForumUrl()
    const url = `${domain}/forum/profile.php?mode=viewprofile&u=${user}`;
    window.open(url, '_blank');
}

/**
 * Открыть подраздел.
 *
 * @param {number} subForumId
 */
function openSubForumLink(subForumId) {
    if (!subForumId) {
        return;
    }

    const domain = getForumUrl()
    const url = `${domain}/forum/viewforum.php?f=${subForumId}`;
    window.open(url, '_blank');
}
