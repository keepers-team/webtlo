/**
 * [Раздачи] Инициализация выбора хранимого подраздела.
 *
 * @module ModuleNames.TOPICS_SUBSECTIONS
 * @requires ModuleNames.JQUERY_WIDGETS
 * @requires ModuleNames.REPORTS
 * @requires ModuleNames.TOPICS_FILTERS
 */

webtlo.register(ModuleNames.TOPICS_SUBSECTIONS, function() {

    let lastLoadedListing = null;
    let delayLoading = false;

    // Функция загрузки данных.
    const loadFilteredTopics = function() {
        showResultTopics();   // очистка
        getFilteredTopics();  // загрузка
    }

    // Элемент с селектором выбора группы.
    const $mainSubsection = $('#main-subsections');

    // Загрузка данных о выбранном подразделе на главной.
    $mainSubsection.selectmenu({
        width : "calc(100% - 36px)",
        create: function() {
            // Создание модуля выполняет первую загрузку раздач при открытии страницы.
            if (!$('#ui_save_selected_section').is(':checked')) {
                return;
            }

            const savedForumId = Cookies.get('saved_forum_id');
            if (typeof savedForumId !== 'undefined') {
                lastLoadedListing = savedForumId;
                $(this).val(savedForumId).selectmenu('refresh')

                loadFilteredTopics();
            }
        },
        open: function() {
            // Выделяем выделенную строку жирным в списке.
            let selectedForumID = $('#main-subsections-button').attr('aria-activedescendant');
            $('#main-subsections-menu div[role=option]').css({'font-weight': 'normal'});
            $(`#${selectedForumID}`).css({'font-weight': 'bold'});

            const getIcon = function(faClass) {
                return `<i class="fa ${faClass}" aria-hidden="true"></i> `;
            }

            $('#main-subsections-menu li div').each(function() {
                let forumTitle = $.trim($(this).text());
                let forumData = $('#list-forums option').filter(function() {
                    return $(this).text() === forumTitle;
                }).data();

                if (typeof forumData === 'undefined') {
                    return;
                }

                let forumIndication = '';
                if (+forumData.hide === 1) {
                    forumIndication += getIcon('fa-eye-slash');
                }
                if (+forumData.exclude === 1) {
                    forumIndication += getIcon('fa-circle-minus');
                }
                if (+forumData.peers === -1) {
                    forumIndication += getIcon('fa-bolt');
                }

                $(this).html(forumIndication + forumTitle);
            });
        },
    });

    // При выборе пункта клавиатурой или прокруткой, срабатывает отложенная загрузка.
    $mainSubsection.on('selectmenuselect', function(event, ui) {
        if (lastLoadedListing === ui.item.value) {
            return;
        }

        // Записываем выбранный ид раздела в куки.
        lastLoadedListing = ui.item.value;
        Cookies.set('saved_forum_id', ui.item.value);

        if (delayLoading) {
            delayLoading = false;

            delayCallback500(loadFilteredTopics, this);
        } else {
            loadFilteredTopics();
        }
    });

    // Прокрутка подразделов в списке колесом мышки.
    $mainSubsection.selectmenu('widget').on('mousewheel', function (event, delta) {
        event.preventDefault();

        // Меню закрыто, ничего не делаем.
        if ($(this).hasClass('ui-selectmenu-button-open')) {
            return false;
        }

        // Тип разворота не является ид подраздела, ничего не делаем.
        const listingType = $mainSubsection.val();
        if (!$mainSubsection.find(`#main-subsections-stored option[value=${listingType}]`).length) {
            return false;
        }

        const totalNumberSubsectionsOptions = +$('#main-subsections-stored option').size();
        const totalNumberAdditionalOptions = $('#main-subsections option').size() - totalNumberSubsectionsOptions;

        const indexSelectedOption = +$mainSubsection.prop('selectedIndex');

        // При прокрутке, учитываем только подразделы.
        let nextIndexNumber = indexSelectedOption - totalNumberAdditionalOptions - delta;
        if (nextIndexNumber === totalNumberSubsectionsOptions) {
            nextIndexNumber = 0;
        }

        delayLoading = true;
        $(`#main-subsections-stored :eq(${nextIndexNumber})`).prop('selected', true);
        $mainSubsection.selectmenu('refresh');

        return true;
    });

}, [
    ModuleNames.JQUERY_WIDGETS,
    ModuleNames.REPORTS,
    ModuleNames.TOPICS_FILTERS,
]);
