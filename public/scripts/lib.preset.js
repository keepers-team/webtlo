/* Функции для работы с наборами фильтров. */

/**
 * Текущий фильтр из формы.
 */
function getCurrentFilter() {
    return $('#topics_filter').serializeAllArray().toSorted();
}

/**
 * Загрузка списка пресетов при старте.
 */
function loadPresetList(lastSelectedPreset = null) {
    $.get('php/presets_manager.php?action=list', function(names) {
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
    });
}

/**
 * Сохранение текущего фильтра как пресета
 */
function saveCurrentFilterAsPreset() {
    const filter = getCurrentFilter();

    // Используем старое имя пресета, если есть.
    let name = $('#preset_select').val();
    if (name && !confirm(`Перезаписать пресет "${name}"?`)) return;

    // Запрашиваем новое имя через диалог.
    if (!name || name.trim() === '') {
        name = prompt('Введите имя для нового пресета:');
    }

    if (!name || name.trim() === '') return;

    $.post('php/presets_manager.php', {
        action: 'save',
        name  : name.trim(),
        data  : JSON.stringify(filter)
    }, function(response) {
        if (response.success) {
            // Обновляем список.
            loadPresetList(name);
            toggleButtonUnsavedState(false);

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

    $.get('php/presets_manager.php?action=load&name=' + encodeURIComponent(name), function(data) {
        // Раскладываем фильтр по полям.
        loadSavedFilterOptions(JSON.stringify(data));

        // Применяем фильтр.
        $('#topics_filter').trigger('change');
        toggleButtonUnsavedState(false);
    }, 'json');
}

/**
 * Удаление выбранного пресета.
 */
function deleteSelectedPreset() {
    const name = $('#preset_select').val();
    if (!name) return;
    if (!confirm(`Удалить пресет "${name}"?`)) return;

    $.post('php/presets_manager.php', {
        action: 'delete',
        name  : name
    }, function(response) {
        if (response.success) {
            loadPresetList();

            showResultTopics('Пресет удалён.');
        } else {
            showResultTopics(`Ошибка удаления: ${response.error || 'неизвестная ошибка'}`);
        }
    }, 'json');
}

/**
 * Разложить сохранённый набор фильтров по полям.
 */
function loadSavedFilterOptions(filter_options) {
    filter_options = $.parseJSON(filter_options);

    $('#topics_filter input[type=radio], #topics_filter input[type=checkbox]').prop('checked', false);
    $.each(filter_options, function(i, option) {
        // Пропускаем "дату регистрации до".
        if (option.name === 'filter_date_release') {
            return true;
        }

        if ($(`#topics_filter [name='${option.name}']`).is('select')) {
            $(`#${option.name}`).val(option.value).selectmenu('refresh');

            return true;
        }

        $(`#topics_filter input[name='${option.name}']`).each(function() {
            if (
                $(this).attr('type') === 'checkbox'
                || $(this).attr('type') === 'radio'
            ) {
                if ($(this).val() === option.value) {
                    $(this).prop('checked', true);
                }
            } else if (this.name === option.name) {
                $(this).val(option.value);
            }
        });
    });

    // вкл/выкл интервал сидов
    $('#topics_filter input[name=filter_interval]').trigger('filter_init');

    // вкл/выкл интервал хранителей
    $('#topics_filter input[name=is_keepers]').trigger('filter_init');

    // Обновить выбранные статусы хранения раздач.
    $('.filter_status_controlgroup').controlgroup('refresh');
}

function toggleButtonUnsavedState(display) {
    if ($('#preset_select').val()) {
        $('#preset_controls button.preset-unsaved').toggleClass('ui-state-error-text', display);
    }
}
