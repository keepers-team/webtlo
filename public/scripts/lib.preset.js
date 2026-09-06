/* Функции для работы с наборами фильтров. */


let lastUsedFilter = null;

/**
 * Изменился ли последний использованный пресет, относительно текущего.
 *
 * @return {boolean}
 */
function checkUsedFilterChange() {
    // Текущий отсортированный набор фильтров.
    const currentFilter = getCurrentFilter();
    const currentFilterString = JSON.stringify(currentFilter);

    // Если прошлый набор фильтров идентичен текущему - ничего не делаем.
    if (lastUsedFilter === currentFilterString) {
        return false;
    }

    // Запоминаем параметры фильтра в куки.
    lastUsedFilter = currentFilterString;
    Cookies.set('filter-options', currentFilter);

    return true;
}

/**
 * Текущий фильтр из формы.
 *
 * @return {Object}
 * @see https://github.com/marioizquierdo/jquery.serializeJSON/issues/49
 */
function getCurrentFilter() {
    const $form = $('#topics_filter');

    const $disabledFields = $form.find(':disabled');
    $disabledFields.prop('disabled', false);

    const filter = $form.serializeSortedJSON();
    $disabledFields.prop('disabled', true);

    return filter;
}

/**
 * Загрузка списка пресетов при старте.
 *
 * @param {?string} lastSelectedPreset
 */
function loadPresetList(lastSelectedPreset = null) {
    $.get('php/filter_presets_manager.php?action=list', function(names) {
        let $select = $('#preset_select');
        $select.find('option:not(:first)').remove();

        $.each(names, function(i, name) {
            $select.append($('<option>', {value: name, text: name}));
        });

        // Выбираем активный пресет.
        if (lastSelectedPreset) {
            $select.val(lastSelectedPreset);
        }

        $select.selectmenu('refresh');
        toggleButtonUnsavedState(false);
    });
}

/**
 * Сохранение текущего фильтра как пресета
 */
function saveCurrentFilter() {
    const filter = getCurrentFilter();

    let name = $('#preset_select').val();
    if (name) {
        // Используем старое имя пресета, если есть.
        if (!confirm(`Перезаписать пресет "${name}" текущим набором фильтров?`)) return;
    } else {
        // Запрашиваем новое имя через диалог.
        name = prompt('Введите имя для нового пресета:');
    }

    if (!name || name.trim() === '') return;

    savePreset(name, filter);
}

/**
 * Записать пресет.
 *
 * @param {string} name
 * @param {Object} filter
 */
function savePreset(name, filter) {
    $.post('php/filter_presets_manager.php', {
        action: 'save',
        name  : name.trim(),
        data  : JSON.stringify(filter)
    }, function(response) {
        if (response.success) {
            // Обновляем список.
            loadPresetList(name);

            showResultTopics(`Пресет "${name.trim()}" сохранён.`);
        } else {
            showResultTopics(`Ошибка сохранения: ${response.error || 'неизвестная ошибка'}`);
        }
    }, 'json');
}


/**
 * Применение выбранного пресета.
 */
function applySelectedPreset() {
    const name = $('#preset_select').val();
    if (!name) return;

    $.getJSON('php/filter_presets_manager.php?action=load&name=' + encodeURIComponent(name), function(data) {
        // Раскладываем фильтр по полям.
        loadSavedFilterOptions(JSON.stringify(data));
        toggleButtonUnsavedState(false);

        // Применяем фильтр.
        $('#topics_filter').trigger('manual_change');
    });
}

/**
 * Удаление выбранного пресета.
 */
function deleteSelectedPreset() {
    const name = $('#preset_select').val();
    if (!name) return;
    if (!confirm(`Удалить пресет "${name}"?`)) return;

    $.post('php/filter_presets_manager.php', {
        action: 'delete',
        name  : name
    }, function(response) {
        if (response.success) {
            showResultTopics(`Пресет "${name}" удалён.`);

            loadPresetList();
            toggleButtonUnsavedState(false);
        } else {
            showResultTopics(`Ошибка удаления: ${response.error || 'неизвестная ошибка'}`);
        }
    }, 'json');
}

/**
 * Разложить сохранённый набор фильтров по полям.
 *
 * @param {string} filter_options JSON строка с набором фильтров.
 */
function loadSavedFilterOptions(filter_options) {
    filter_options = $.parseJSON(filter_options);

    // Снимаем галку со всех доступных элементов фильтра.
    $('#topics_filter').find(':checkbox, :radio').prop('checked', false);

    // Перебираем пресет и ставим нужные галки.
    $.each(filter_options, function(option_name, value) {
        // Пропускаем "дату регистрации до".
        if (option_name === 'filter_date_release') {
            return;
        }

        // Обрабатываем селекторы.
        if ($(`#topics_filter [name='${option_name}']`).is('select')) {
            $(`#${option_name}`).val(value).selectmenu('refresh');

            return;
        }

        // Если имеем список значений, значит это группированный элемент вида "option_name[]".
        if (typeof(value) === 'object' && value !== null) {
            $.each(value, function(i, in_value) {
                if (typeof (i) === 'number') {
                    applyOptionWithValue(`${option_name}[]`, in_value);
                } else {
                    applyOptionWithValue(`${option_name}[${i}]`, in_value);
                }
            })

            return;
        }

        // Одиночные элементы.
        applyOptionWithValue(option_name, value);
    });

    // вкл/выкл интервал сидов
    $('#topics_filter input[name=filter_interval]').trigger('filter_init');

    // вкл/выкл интервал хранителей
    $('#topics_filter input[name=is_keepers]').trigger('filter_init');

    // Обновить выбранные статусы хранения раздач.
    $('.filter_status_controlgroup').controlgroup('refresh');


    /**
     * Найти элемент по имени и применить значение.
     *
     * @param {string} input_name
     * @param {string} value
     */
    function applyOptionWithValue(input_name, value) {
        $(`#topics_filter input[name='${input_name}']`).each(function() {
            // Чекбоксы и радио могут иметь несколько выбранных значений.
            if ($(this).is(':checkbox, :radio')) {
                if (this.value === value) {
                    $(this).prop('checked', true);
                }
            } else {
                $(this).val(value);
            }
        });
    }
}

/**
 * Подсветить кнопки необходимости применения пресета.
 *
 * @param {boolean} display
 */
function toggleButtonUnsavedState(display) {
    // Если пресет не выбран, то нет смысла подсвечивать кнопки.
    if (!$('#preset_select').val() && display) {
        return;
    }

    $('#preset_controls button.preset-unsaved').toggleClass('ui-state-error-text', display);
}

/**
 * Открыть диалог управления пресетом (экспорт/импорт/сохранение)
 */
function openPresetDialog() {
    const filter = getCurrentFilter();
    const json = JSON.stringify(filter, null, 2);
    const selectedName = $('#preset_select').val();

    const buttons = [
        {
            text : 'Сохранить',
            click: function() {
                try {
                    const name = $('#presetNameInput').val().trim();
                    if (!name) {
                        alert('Введите имя пресета!');

                        return;
                    }

                    let filterText = $('#presetJsonArea').val();
                    const filter = JSON.parse(filterText);

                    filterText = JSON.stringify(filter);

                    savePreset(name, filter);
                    toggleButtonUnsavedState(false);
                    loadSavedFilterOptions(filterText);

                    // Вызываем применение фильтра.
                    $('#topics_filter').trigger('manual_change');

                    $dialog.dialog('close');
                } catch (e) {
                    alert('Ошибка парсинга JSON: ' + e.message);
                }
            }
        },
        {
            text : 'Копировать JSON',
            click: function() {
                const text = $('#presetJsonArea').val();
                copyToClipboard(text);
                showResultTopics('JSON скопирован в буфер обмена');
            }
        },
        {
            text : 'Закрыть',
            click: function() {
                $(this).dialog('close');
            }
        },
    ];

    // Формируем HTML диалога
    const $dialog = $('#dialog')
        .html(`
            <div style="margin-bottom:10px;">
                <label for="presetNameInput">Имя пресета:</label>
                <input id="presetNameInput" type="text" style="width:100%;" value="${selectedName || ''}" placeholder="Введите имя пресета" />
            </div>
            <div>
                <label>JSON текущего фильтра:</label>
                <textarea id="presetJsonArea" style="width:100%;height:250px;font-family:monospace;font-size:12px;">${json}</textarea>
            </div>
        `)
        .dialog({
            title  : 'Управление пресетом',
            width  : 700,
            modal  : true,
            buttons: buttons,
            open   : function() {
                // При открытии фокусируем поле имени
                $('#presetNameInput').focus();
            }
        })
        .dialog('open');
}
