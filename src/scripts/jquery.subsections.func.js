
/* Работа с подразделами */

// задержка при прокрутке подразделов
var forumDataShowDelay = makeDelay(500);

function addSubsection(event, ui) {
    if (ui.item.value < 0) {
        ui.item.value = "";
        return false;
    }
    var forumTitle = ui.item.label;
    var forumLabel = forumTitle.replace(/.* » /, "");
    var forumID = ui.item.value;
    var mainSelectedForumID = $("#main-subsections").val();
    var reportsSelectedForumID = $("#reports-subsections").val();
    if ($("#list-forums option[value=" + forumID + "]").length == 0) {
        $("#list-forums").append("<option value=\"" + forumID + "\">" + forumTitle + "</option>");
        var optionForum = $("#list-forums option[value=" + forumID + "]");
        optionForum.attr("data-client", 0).data("client", 0);
        optionForum.attr("data-label", forumLabel).data("label", forumLabel);
        optionForum.attr("data-savepath", "").data("savepath", "");
        optionForum.attr("data-subdirectory", 1).data("subdirectory", 1);
        optionForum.attr("data-hide", 0).data("hide", 0);
        optionForum.attr("data-peers", "").data("peers", "");
        optionForum.attr("data-exclude", 0).data("exclude", 0);
        optionForum.text(forumTitle);
        $("#main-subsections-stored").append("<option value=\"" + forumID + "\">" + forumTitle + "</option>");
        $("#reports-subsections-stored").append("<option value=\"" + forumID + "\">" + forumTitle + "</option>");
        $(".forum-props, #list-forums").removeClass("ui-state-disabled").prop("disabled", false);
        $("#forum-id").addClass("ui-state-disabled").prop("disabled", true);
    }
    doSortSelect("list-forums");
    doSortSelect("main-subsections-stored");
    doSortSelect("reports-subsections-stored");
    $("#main-subsections").val(mainSelectedForumID).selectmenu("refresh");
    $("#reports-subsections").val(reportsSelectedForumID).selectmenu("refresh");
    $("#list-forums").val(forumID).selectmenu("refresh").change();
    ui.item.value = "";
}

// Добавление раздела в хранимые, по нажатию на ид форума
function addUnsavedSubsection(forum_id, forum_title) {
    $('#dialog').dialog({
        buttons  : [
            {
                text : 'Да, добавить',
                click: function() {
                    // Открываем вкладку настроек, настройки хранимых подразделов и вставляем ид раздела
                    $('#menutabs').tabs('option', 'active', 1);
                    $('div.sub_settings').accordion('option', 'active', 2);
                    $('#add-forum').val(forum_id).autocomplete("search", forum_id);
                    $(this).dialog('close');
                },
            },
            {
                text : "Нет",
                click: function() {
                    $(this).dialog('close');
                }
            }
        ],
        modal    : true,
        resizable: false
    })
        .text(`Добавить в хранимые подраздел '${forum_title}'?`)
        .dialog('open');
}

function getForums() {
    var forums = {};
    $("#list-forums option").each(function () {
        var forumID = $(this).val();
        if (forumID != 0) {
            var forumTitle = $(this).text();
            var forumData = $(this).data();
            forums[forumID] = {
                "title": forumTitle,
                "client": forumData.client,
                "label": forumData.label,
                "savepath": forumData.savepath,
                "subdirectory": forumData.subdirectory,
                "hide": forumData.hide,
                "control_peers": forumData.peers,
                "exclude": forumData.exclude
            };
        }
    });
    return forums;
}

// Обновить список ид исключенных из отчётов разделов
function refreshExcludedSubsections() {
    var excludedForums = [];
    $("#list-forums option").each(function () {
        var forumID = $(this).val();
        var forumData = this.dataset;

        if (forumData.exclude-0) {
            excludedForums.push(forumID);
        }
    });
    $("#exclude_forums_ids").val(excludedForums.join(","));
}
