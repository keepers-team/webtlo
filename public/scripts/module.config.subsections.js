/**
 * [Настройки] Инициализация хранимых подразделов.
 *
 * @module ModuleNames.CONFIG_SUBSECTIONS
 * @requires ModuleNames.CONFIG_COMMON
 * @requires ModuleNames.REPORTS
 * @requires ModuleNames.TOPICS_SUBSECTIONS
 */

webtlo.register(ModuleNames.CONFIG_SUBSECTIONS,function() {

    /* Сканируемые подразделы */

    // Последний выбранный подраздел.
    let editableForumID;

    // Список хранимых подразделов в настройках.
    const $listForums = $('#list-forums').selectmenu('option', 'width', 'auto');

    // Получение свойств выбранного подраздела.
    $listForums.on('change selectmenuchange', function () {
        let forumData = $('#list-forums :selected').data();
        if (forumData.client === '') {
            forumData.client = 0;
        }
        if (forumData.subdirectory === '') {
            forumData.subdirectory = 0;
        }
        if (forumData.hide === '') {
            forumData.hide = 0;
        }
        if (forumData.exclude === '') {
            forumData.exclude = 0;
        }

        const forumClient = $('#forum-client');

        const torrentClientID = $(`#forum-client option[value=${forumData.client}]`).val();
        if (typeof torrentClientID === 'undefined') {
            $('#forum-client :first').prop('selected', true);
        } else {
            forumClient.val(torrentClientID);
        }

        const useSubDirectory = $(`#forum-subdirectory option[value=${forumData.subdirectory}]`).val();
        if (typeof useSubDirectory === 'undefined') {
            $('#forum-subdirectory :first').prop('selected', true);
        } else {
            $(`#forum-subdirectory [value=${useSubDirectory}]`).prop('selected', true);
        }

        const hideTopics = $(`#forum-hide-topics [value=${forumData.hide}]`).val();
        if (typeof hideTopics === 'undefined') {
            $("#forum-hide-topics :first").prop('selected', true);
        } else {
            $(`#forum-hide-topics [value=${hideTopics}]`).prop('selected', true);
        }

        const forumExclude = $(`#forum-exclude [value=${forumData.exclude}]`).val();
        if (typeof forumExclude === 'undefined') {
            $('#forum-exclude :first').prop('selected', true);
        } else {
            $(`#forum-exclude [value=${forumExclude}]`).prop('selected', true);
        }

        editableForumID = $(this).val();
        $('#forum-id').val(editableForumID);

        $('#forum-label').val(forumData.label);
        $('#forum-savepath').val(forumData.savepath);
        $('#forum-control-peers').val(forumData.peers);

        forumClient.selectmenu('refresh');
        $('#forum-subdirectory').selectmenu('refresh');
        $('#forum-hide-topics').selectmenu('refresh');
        $('#forum-exclude').selectmenu('refresh');
    });

    // Изменение свойств подраздела.
    $('#forum-props').on('focusout selectmenuchange spinstop', function () {
        const forumClient = $("#forum-client :selected").val();
        const forumLabel = $("#forum-label").val();
        const forumSavePath = $("#forum-savepath").val();
        const forumSubdirectory = $("#forum-subdirectory").val();
        const forumHideTopics = $("#forum-hide-topics :selected").val();
        const forumControlPeers = $("#forum-control-peers").val();
        const forumExclude = $("#forum-exclude :selected").val();
        const optionForum = $(`#list-forums option[value=${editableForumID}]`);

        optionForum.attr('data-client', forumClient).data('client', forumClient);
        optionForum.attr('data-label', forumLabel).data('label', forumLabel);
        optionForum.attr('data-savepath', forumSavePath).data("savepath", forumSavePath);
        optionForum.attr('data-subdirectory', forumSubdirectory).data('subdirectory', forumSubdirectory);
        optionForum.attr('data-hide', forumHideTopics).data('hide', forumHideTopics);
        optionForum.attr('data-peers', forumControlPeers).data('peers', forumControlPeers);
        optionForum.attr('data-exclude', forumExclude).data('exclude', forumExclude);

        refreshExcludedSubsections();
    });

    // Открыть ссылку на подраздел.
    $('#forum-id').next('i').click(function(e) {
        e.preventDefault();

        openSubForumLink(editableForumID);
    });

    // Добавить подраздел.
    $('#add-forum').autocomplete({
        source   : 'php/get_list_subsections.php',
        delay    : 1000,
        minLength: 3,
        select   : addSubsection,
        search   : function() {
            const color = $('.ui-widget-content').css('color');

            $('.spinner').css('border-color', `${color} ${color} transparent transparent`);
            $(this).closest('div').find('div').show();
        },
        response : function(event, ui) {
            $(this).closest('div').find('div').hide();

            // Нет результатов, значит красим в ошибку.
            if (ui.content.length === 0) {
                $(this).addClass('ui-state-error');

                return false;
            }

            if (ui.content.length === 1) {
                // Результат ровно один, но ид отрицательный, значит красим в ошибку.
                let item = ui.content[0];
                if (item.value < 0) {
                    $(this).addClass('ui-state-error');

                    return false;
                }

                // Автоматически выбираем найденный подраздел.
                $(this).data('ui-autocomplete')._trigger('select', 'autocompleteselect', {item: item});
                $(this).val('').autocomplete('close');
            }

            return true;
        },
    }).on('input', function(){
        $(this).removeClass('ui-state-error');
        $('#list-forums-button').removeClass('ui-state-highlight');
    });

    // Удалить подраздел.
    $('#remove-forum').on('click', function () {
        const forumID = $listForums.val();
        if (typeof forumID === 'undefined') {
            return false;
        }

        const selectedForum = $('#list-forums :selected');
        let optionIndex = selectedForum.index();
        if (optionIndex === -1) {
            return false;
        }

        // Удаляем подраздел из всех селекторов.
        selectedForum.remove();
        $(`#main-subsections-stored [value=${forumID}]`).remove();
        $(`#reports-subsections-stored [value=${forumID}]`).remove();

        const optionTotal = $('select[id=list-forums] option').size();
        if (optionTotal === 0) {
            // Если удалили все подразделы, ставим значения по умолчанию всем полям.
            $('.forum-props, #list-forums').val('').toggleDisable(true);
            $('#forum-client :first').prop('selected', true)
            $('#forum-client, select.forum-props').selectmenu('refresh');
        } else {
            if (optionTotal !== optionIndex) {
                optionIndex++;
            }

            $(`#list-forums :nth-child(${optionIndex})`).prop('selected', true).change();
        }

        $('#main-subsections').selectmenu('refresh');
        $('#reports-subsections').selectmenu('refresh');
        $listForums.selectmenu('refresh');

        refreshExcludedSubsections();

        return true;
    });

    // Выбрать первый подраздел в списке при загрузке
    if ($('#list-forums option').size() > 0) {
        $('#list-forums :first').prop('selected', true).change();
    } else {
        $('.forum-props').toggleDisable(true);
        $('select.forum-props').selectmenu('refresh');
    }


    // === ЛОКАЛЬНЫЕ ФУНКЦИИ ===

    // Обновить список ид исключенных из отчётов разделов.
    function refreshExcludedSubsections() {
        let excludedForums = [];

        $listForums.find('option').each(function () {
            const forumID = $(this).val();
            const forumData = $(this).data();

            if (forumData.exclude-0) {
                excludedForums.push(forumID);
            }
        });

        $('#exclude_forums_ids').val(excludedForums.join(','));
    }

}, [
    ModuleNames.CONFIG_COMMON,
    ModuleNames.REPORTS,
    ModuleNames.TOPICS_SUBSECTIONS,
]);
