
/* Инициализация работы с подразделами */

$(document).ready(function () {

    // последний выбранный подраздел
    let editableForumID;

    // загрузка данных о выбранном подразделе на главной
    $('#main-subsections').change(function() {
        // Очищаем результат.
        showResultTopics();
        // Загружаем раздачи.
        getFilteredTopics();
    }).selectmenu({
        width : "calc(100% - 36px)",
        change: function(event, ui) {
            // Записываем выбранный ид раздела в куки.
            Cookies.set('saved_forum_id', ui.item.value);
            $(this).trigger('change');
        },
        create: function(event, ui) {
            if (!$('#ui_save_selected_section').is(':checked')) {
                return;
            }

            const savedForumId = Cookies.get('saved_forum_id');
            if (typeof savedForumId !== 'undefined') {
                $(this).val(savedForumId).selectmenu('refresh').trigger('change');
            }
        },
        open: function(event, ui) {
            // Выделяем выделенную строку жирным в списке.
            let selectedForumID = $('#main-subsections-button').attr('aria-activedescendant');
            $('#main-subsections-menu div[role=option]').css({'font-weight': 'normal'});
            $(`#${selectedForumID}`).css({'font-weight': 'bold'});

            const getIcon = function(faClass) {
                return `<i class="fa ${faClass}" aria-hidden="true"></i> `;
            }

            $("#main-subsections-menu li div").each(function() {
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

    // прокрутка подразделов на главной
    $('#main-subsections-button').on('mousewheel', function (event, delta) {
        let hidden = $('#main-subsections-menu').attr('aria-hidden');
        if (hidden == "false") {
            return false;
        }

        const mainSubsection = $('#main-subsections');
        const forumID = mainSubsection.val();

        const element = $(`#main-subsections [value=${forumID}]`).parent().attr("id");
        if (typeof element === 'undefined') {
            return false;
        }

        const totalNumberSubsectionsOptions = +$("#main-subsections-stored option").size();
        const totalNumberAdditionalOptions = +$("#main-subsections optgroup:first option").size();
        const indexSelectedOption = +mainSubsection.prop('selectedIndex');

        let nextIndexNumber = indexSelectedOption - totalNumberAdditionalOptions - delta;
        if (nextIndexNumber === totalNumberSubsectionsOptions) {
            nextIndexNumber = 0;
        }

        $(`#main-subsections-stored :eq(${nextIndexNumber})`).prop('selected', 'selected');
        mainSubsection.selectmenu('refresh');

        forumDataShowDelay(getFilteredTopics);

        return false;
    });

    // получение свойств выбранного подраздела
    $('#list-forums').on('change selectmenuchange', function () {
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
            $('#forum-client :first').prop('selected', 'selected');
        } else {
            forumClient.val(torrentClientID);
        }

        const useSubDirectory = $(`#forum-subdirectory option[value=${forumData.subdirectory}]`).val();
        if (typeof useSubDirectory === 'undefined') {
            $('#forum-subdirectory :first').prop('selected', 'selected');
        } else {
            $(`#forum-subdirectory [value=${useSubDirectory}]`).prop('selected', 'selected');
        }

        const hideTopics = $(`#forum-hide-topics [value=${forumData.hide}]`).val();
        if (typeof hideTopics === "undefined") {
            $("#forum-hide-topics :first").prop('selected', 'selected');
        } else {
            $(`#forum-hide-topics [value=${hideTopics}]`).prop('selected', 'selected');
        }

        const forumExclude = $(`#forum-exclude [value=${forumData.exclude}]`).val();
        if (typeof forumExclude === 'undefined') {
            $('#forum-exclude :first').prop('selected', 'selected');
        } else {
            $(`#forum-exclude [value=${forumExclude}]`).prop('selected', 'selected');
        }

        editableForumID = $(this).val();
        $('#forum-id').val(editableForumID);

        $('#forum-label').val(forumData.label);
        $('#forum-savepath').val(forumData.savepath);
        $('#forum-control-peers').val(forumData.peers);

        forumClient.selectmenu().selectmenu('refresh');
        $('#forum-subdirectory').selectmenu().selectmenu('refresh');
        $('#forum-hide-topics').selectmenu().selectmenu('refresh');
        $('#forum-exclude').selectmenu().selectmenu('refresh');
    });

    // изменение свойств подраздела
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

    // добавить подраздел
    $('#add-forum').autocomplete({
        source   : 'php/actions/get_list_subsections.php',
        delay    : 1000,
        minLength: 3,
        select   : addSubsection,
        search   : function(event, ui) {
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
                $('#list-forums-button').addClass('ui-state-highlight');
                $(this).val('').autocomplete('close');
            }
        },
    }).on('input', function(){
        $(this).removeClass('ui-state-error');
        $('#list-forums-button').removeClass('ui-state-highlight');
    });

    // удалить подраздел
    $('#remove-forum').on('click', function () {
        const forumList = $('#list-forums');
        const forumID = forumList.val();
        if (typeof forumID === "undefined") {
            return false;
        }

        const selectedForum = $('#list-forums :selected');
        let optionIndex = selectedForum.index();
        selectedForum.remove();

        $(`#main-subsections-stored [value=${forumID}]`).remove();
        $(`#reports-subsections-stored [value=${forumID}]`).remove();

        const optionTotal = $('select[id=list-forums] option').size();
        if (optionTotal === 0) {
            $('.forum-props, #list-forums').val('').addClass('ui-state-disabled').prop('disabled', true);
            $("#forum-client :first").prop('selected', 'selected');
        } else {
            if (optionTotal !== optionIndex) {
                optionIndex++;
            }
            $(`#list-forums :nth-child(${optionIndex})`).prop('selected', 'selected').change();
        }

        $('#main-subsections').selectmenu('refresh');
        $('#reports-subsections').selectmenu('refresh');
        forumList.selectmenu('refresh');

        getFilteredTopics();
    });

    // при загрузке выбрать первый подраздел в списке
    if ($('select[id=list-forums] option').size() > 0) {
        $('#list-forums :first').prop('selected', 'selected').change();
    } else {
        $('.forum-props').addClass('ui-state-disabled').prop('disabled', true);
    }

    // Открыть ссылку на подраздел.
    $('#forum-id').next('i').click(function(e) {
        e.preventDefault();

        if (!editableForumID) {
            return;
        }

        const domain = getForumUrl()
        const url = `${domain}/forum/viewforum.php?f=${editableForumID}`;
        window.open(url, '_blank');
    });

});
