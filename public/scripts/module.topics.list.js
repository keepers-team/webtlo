/**
 * [Раздачи] Инициализация блока со списком раздач.
 *
 * @module ModuleNames.TOPICS_LIST
 * @requires ModuleNames.JQUERY_METHODS
 * @requires ModuleNames.TOPICS_FILTERS
 */

webtlo.register(ModuleNames.TOPICS_LIST, function() {

    // Список найденных и загруженных раздач.
    const $topicsForm = $('#topics');

    // Выделение/снятие выделения интервала раздач.
    $topicsForm.on('click', '.topic', function(event) {
        const $checkboxes = $('#topics .topic');
        const classMarker = 'last-checked';

        if ($checkboxes.hasClass(classMarker)) {
            if (event.shiftKey) {
                const $lastChecked = $("#topics .last-checked");
                const startIndex = $checkboxes.index(this);
                const endIndex = $checkboxes.index($lastChecked);

                $checkboxes.slice(Math.min(startIndex, endIndex), Math.max(startIndex, endIndex) + 1).prop('checked', $lastChecked[0].checked);
            }

            $checkboxes.removeClass(classMarker);
        }

        $(this).addClass(classMarker);
        refreshCountSizeSelectedTopics();
    });

    // Снять выделение всех раздач по Esc
    $topicsForm.on('keyup', function(event) {
        if (event.which === 27) {
            $('#topics .topic').prop('checked', false).removeClass('last-checked');
            refreshCountSizeSelectedTopics();
        }
    });

    // Alt+Click по нику хранителя - открывает его профиль.
    $topicsForm.on('mousedown', '.keeper', function(e) {
        if (e.altKey || e.which === 2) {
            e.preventDefault();
            openUserProfile($(this).text());
        }
    });

    // Поиск по нику хранителя при двойном клике (Ctrl - добавляет ник в перечень).
    $topicsForm.on('dblclick', '.keeper', function(e) {
        e.preventDefault();

        const keeperName = $(this).text();
        const searchBox = $('input[name=filter_phrase]');
        const prevSearch = searchBox.val();

        // Собираем желаемый список поисковых значений.
        let values = prevSearch ? prevSearch.split(',') : [];
        if (e.ctrlKey) {
            values.push(keeperName);
        } else {
            values = [keeperName];
        }

        // Убираем повторы.
        const newSearch = $.uniqueValues(values).join(',');
        if (newSearch === prevSearch) {
            return;
        }

        // Применяем поиск.
        searchBox.val(newSearch);

        selectBlockText(this);
        $('input[name=filter_by_phrase][type="radio"]').prop('checked', false);
        $('#filter_by_keeper').prop('checked', true);

        $('#topics_filter').change();
    });

    // Поиск по торрент-клиенту при двойном клике по названию.
    $topicsForm.on('dblclick', '.client', function(e) {
        e.preventDefault();
        selectBlockText(this);

        const torrentClientName = $(this).text();
        const torrentClientID = $(`#list-torrent-clients li:contains('${torrentClientName}')`).val();

        $('#filter_client_id').val(torrentClientID).selectmenu('refresh');

        $('#topics_filter').change();
    });

    // очистка topics_result при изменениях на странице
    $('#topics_data').on('change input spin', showResultTopics);

}, [
    ModuleNames.JQUERY_METHODS,
    ModuleNames.TOPICS_FILTERS,
]);
