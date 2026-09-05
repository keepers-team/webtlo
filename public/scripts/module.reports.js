/**
 * [Отчёты]. Выбор отчёта для просмотра.
 *
 * @module ModuleNames.REPORTS
 * @requires ModuleNames.JQUERY_METHODS
 */

webtlo.register(ModuleNames.REPORTS,function () {

    // Выпадающее меню со списком подразделов для отчётов
    const $reportSubsections = $('#reports-subsections');

    // Кнопка "Обновить текущий отчёт".
    $('#get_reports').on('click', function () {
        getReport();
    });

    $reportSubsections.selectmenu({
        width: "calc(100% - 36px)",
        change: getReport,
        open: function () {
            // Выделяем жирным в списке
            const active = $('#reports-subsections-button').attr('aria-activedescendant');
            $('#reports-subsections-menu div[role=option]').css({
                'font-weight': 'normal'
            });

            $(`#${active}`).css({
                'font-weight': 'bold'
            });
        },
    });

    // Если отчёт по подразделу открыт, то прокрутка списка автоматически загружает другие отчёты.
    $reportSubsections.selectmenu('widget').on('mousewheel', function (event, delta) {
        // Если выпадающее меню открыто, ничего не делаем.
        if ($(this).hasClass('ui-selectmenu-button-open')) {
            return false;
        }

        // Элемент не имеет ид, пропускаем.
        if (!$reportSubsections.find(`option[value=${$reportSubsections.val()}]`).length) {
            return false;
        }

        const optionsCount = $('#reports-subsections-stored option').size();

        // Индекс выбранного элемента (-1 "Выберите из списка") (-1 "Сводный отчёт).
        let selected = $reportSubsections.prop('selectedIndex') - 2;

        selected = selected - delta;
        if (selected === optionsCount) {
            selected = 0;
        }

        // Выбираем новый пункт после прокрутки, обновляем меню, загружаем отчёт.
        $(`#reports-subsections-stored :eq(${selected})`).prop('selected', true);
        $reportSubsections.selectmenu('refresh');

        delayCallback500(getReport, this);

        return false;
    });


    // === ЛОКАЛЬНЫЕ ФУНКЦИИ ===

    // Получение отчёта по ид выбранного подраздела
    function getReport() {
        const subForumId = $reportSubsections.val();
        if ($.isEmptyObject(subForumId)) {
            return false;
        }

        $.ajax({
            type: 'POST',
            url: 'php/get_reports.php',
            data: {
                forum_id: subForumId
            },
            beforeSend: function () {
                $('#get_reports').toggleDisable(true);
                $('#reports-subsections').selectmenu('disable');
                $('#reports-content').html(`<i class="fa fa-spinner fa-pulse"></i>`);
            },
            success: function (response) {
                response = $.parseJSON(response);
                $('#reports-content').html(response.report);
                addDefaultLog(response.log ?? '');

                // Инициализация "аккордиона" сообщений.
                $('#reports-content .report_message').each(function () {
                    $(this).accordion({
                        collapsible: true,
                        heightStyle: 'content'
                    });
                });

                // Выделение тела сообщения двойным кликом.
                $('#reports-content .ui-accordion-content').dblclick(function () {
                    selectBlockText(this)
                });
            },
            complete: function () {
                $('#get_reports').toggleDisable(false);
                $('#reports-subsections').selectmenu('enable');
            },
        });

        return true;
    }

}, [
    ModuleNames.JQUERY_METHODS,
]);
