
/* Инициализация работы с подразделами */

$(document).ready(function () {

    // последний выбранный подраздел
    var editableForumID;

    // загрузка данных о выбранном подразделе на главной
    $("#main-subsections").selectmenu({
        width: "calc(100% - 36px)",
        change: function (event, ui) {
            showResultTopics();
            getFilteredTopics();
            Cookies.set("saved_forum_id", ui.item.value);
        },
        create: function (event, ui) {
            var forumID = Cookies.get("saved_forum_id");
            if (typeof forumID !== "undefined") {
                $("#main-subsections").val(forumID);
                $("#main-subsections").selectmenu("refresh");
            }
        },
        open: function (event, ui) {
            // выделяем жирным в списке
            var selectedForumID = $("#main-subsections-button").attr("aria-activedescendant");
            $("#main-subsections-menu div[role=option]").css({ "font-weight": "normal" });
            $("#" + selectedForumID).css({ "font-weight": "bold" });
            $("#main-subsections-menu li div").each(function () {
                var forumIndication = '';
                var forumTitle = $.trim($(this).text());
                var forumData = $("#list-forums option").filter(function () {
                    return $(this).text() === forumTitle;
                }).data();
                if (typeof forumData === "undefined") {
                    return;
                }
                if (forumData.hide == 1) {
                    forumIndication += '<i class="fa fa-eye-slash" aria-hidden="true"></i> ';
                }
                if (forumData.peers == -1) {
                    forumIndication += '<i class="fa fa-bolt" aria-hidden="true"></i> ';
                }
                $(this).html(forumIndication + forumTitle);
            });
        },
    });

    // прокрутка подразделов на главной
    $("#main-subsections-button").on("mousewheel", function (event, delta) {
        var hidden = $("#main-subsections-menu").attr("aria-hidden");
        if (hidden == "false") {
            return false;
        }
        var forumID = $("#main-subsections").val();
        var element = $("#main-subsections [value=" + forumID + "]").parent().attr("id");
        if (typeof element === "undefined") {
            return false;
        }
        var totalNumberSubsectionsOptions = $("#main-subsections-stored option").size();
        var totalNumberAdditionalOptions = $("#main-subsections optgroup:first option").size();
        var indexSelectedOption = $("#main-subsections").prop("selectedIndex");
        var nextIndexNumber = indexSelectedOption - totalNumberAdditionalOptions - delta;
        if (nextIndexNumber == totalNumberSubsectionsOptions) {
            nextIndexNumber = 0;
        }
        $("#main-subsections-stored :eq(" + nextIndexNumber + ")").prop("selected", "selected");
        $("#main-subsections").selectmenu("refresh");
        forumDataShowDelay(getFilteredTopics);
        return false;
    });

    // получение свойств выбранного подраздела
    $("#list-forums").on("change selectmenuchange", function () {
        var forumData = $("#list-forums :selected").data();
        if (forumData.client == '') {
            forumData.client = 0;
        }
        if (forumData.subdirectory == '') {
            forumData.subdirectory = 0;
        }
        if (forumData.hide == '') {
            forumData.hide = 0;
        }
        if (forumData.exclude == '') {
            forumData.exclude = 0;
        }
        var torrentClientID = $("#forum-client option[value=" + forumData.client + "]").val();
        if (typeof torrentClientID === "undefined") {
            $("#forum-client :first").prop("selected", "selected");
        } else {
            $("#forum-client").val(torrentClientID);
        }
        var subdirectory = $("#forum-subdirectory option[value=" + forumData.subdirectory + "]").val();
        if (typeof subdirectory === "undefined") {
            $("#forum-subdirectory :first").prop("selected", "selected");
        } else {
            $("#forum-subdirectory [value=" + subdirectory + "]").prop("selected", "selected");
        }
        var hideTopics = $("#forum-hide-topics [value=" + forumData.hide + "]").val();
        if (typeof hideTopics === "undefined") {
            $("#forum-hide-topics :first").prop("selected", "selected");
        } else {
            $("#forum-hide-topics [value=" + hideTopics + "]").prop("selected", "selected");
        }
        var forumExclude = $("#forum-exclude [value=" + forumData.exclude + "]").val();
        if (typeof forumExclude === "undefined") {
            $("#forum-exclude :first").prop("selected", "selected");
        } else {
            $("#forum-exclude [value=" + forumExclude + "]").prop("selected", "selected");
        }
        $("#forum-label").val(forumData.label);
        $("#forum-savepath").val(forumData.savepath);
        $("#forum-control-peers").val(forumData.peers);
        editableForumID = $(this).val();
        $("#forum-id").val(editableForumID);
        $("#forum-client").selectmenu().selectmenu("refresh");
        $("#forum-subdirectory").selectmenu().selectmenu("refresh");
        $("#forum-hide-topics").selectmenu().selectmenu("refresh");
        $("#forum-exclude").selectmenu().selectmenu("refresh");
    });

    // изменение свойств подраздела
    $("#forum-props").on("focusout selectmenuchange spinstop", function () {
        var forumClient = $("#forum-client :selected").val();
        var forumLabel = $("#forum-label").val();
        var forumSavePath = $("#forum-savepath").val();
        var forumSubdirectory = $("#forum-subdirectory").val();
        var forumHideTopics = $("#forum-hide-topics :selected").val();
        var forumControlPeers = $("#forum-control-peers").val();
        var forumExclude = $("#forum-exclude :selected").val();
        var optionForum = $("#list-forums option[value=" + editableForumID + "]");
        optionForum.attr("data-client", forumClient).data("client", forumClient);
        optionForum.attr("data-label", forumLabel).data("label", forumLabel);
        optionForum.attr("data-savepath", forumSavePath).data("savepath", forumSavePath);
        optionForum.attr("data-subdirectory", forumSubdirectory).data("subdirectory", forumSubdirectory);
        optionForum.attr("data-hide", forumHideTopics).data("hide", forumHideTopics);
        optionForum.attr("data-peers", forumControlPeers).data("peers", forumControlPeers);
        optionForum.attr("data-exclude", forumExclude).data("exclude", forumExclude);

        refreshExcludedSubsections();
    });

    // добавить подраздел
    $("#add-forum").autocomplete({
        source: "php/actions/get_list_subsections.php",
        delay: 1000,
        minLength: 3,
        select: addSubsection,
        search: function (event, ui) {
            var color = $(".ui-widget-content").css("color");
            $(".spinner").css("border-color", color + " " + color + " transparent transparent");
            $(this).closest("div").find("div").show();
        },
        response: function (event, ui) {
            $(this).closest("div").find("div").hide();
            if ((ui.content.length) === 0) {
                $(this).addClass("ui-state-error");
            }
            if ((ui.content.length) === 1) {
                $(this).data('ui-autocomplete')._trigger('select', 'autocompleteselect', { item: ui.content[0] });
                $("#list-forums-button").addClass("ui-state-highlight");
                $(this).val("").autocomplete("close");
            }
        },
    }).on("input", function(){
        $(this).removeClass("ui-state-error");
        $("#list-forums-button").removeClass("ui-state-highlight");
    });

    // удалить подраздел
    $("#remove-forum").on("click", function () {
        var forumID = $("#list-forums").val();
        if (typeof forumID === "undefined") {
            return false;
        }
        var optionIndex = $("#list-forums :selected").index();
        $("#list-forums :selected").remove();
        $("#main-subsections-stored [value=" + forumID + "]").remove();
        $("#reports-subsections-stored [value=" + forumID + "]").remove();
        var optionTotal = $("select[id=list-forums] option").size();
        if (optionTotal == 0) {
            $(".forum-props, #list-forums").val("").addClass("ui-state-disabled").prop("disabled", true);
            $("#forum-client :first").prop("selected", "selected");
        } else {
            if (optionTotal != optionIndex) {
                optionIndex++;
            }
            $("#list-forums :nth-child(" + optionIndex + ")").prop("selected", "selected").change();
        }
        $("#main-subsections").selectmenu("refresh");
        $("#reports-subsections").selectmenu("refresh");
        $("#list-forums").selectmenu("refresh");
        getFilteredTopics();
    });

    // при загрузке выбрать первый подраздел в списке
    if ($("select[id=list-forums] option").size() > 0) {
        $("#list-forums :first").prop("selected", "selected").change();
    } else {
        $(".forum-props").addClass("ui-state-disabled").prop("disabled", true);
    }

});
