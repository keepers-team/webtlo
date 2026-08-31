/**
 * Добавляем свои методы в jquery.
 *
 * @module ModuleNames.JQUERY
 */

webtlo.register(ModuleNames.JQUERY_METHODS, function () {

    (function ($) {

        /** Вернуть уникальный набор элементов. */
        $.uniqueValues = function(array) {
            return $.grep(array, function(el, index) {
                return index === $.inArray(el, array);
            });
        }

        /**
         * Записать данные в HTML-dataset и jQuery-data.
         *
         * @param {string} key
         * @param data
         */
        $.fn.saveDataKey = function(key, data) {
            this.attr(`data-${key}`, data).data(key, data);
        }

        // https://stackoverflow.com/questions/15958671/disabled-fields-not-picked-up-by-serializearray
        $.fn.serializeAllArray = function () {
            let data = $(this).serializeArray();
            $(':disabled[name]', this).each(function () {
                if (
                    (
                        $(this).attr('type') === 'checkbox'
                        || $(this).attr('type') === 'radio'
                    ) && !$(this).prop('checked')
                ) {
                    return true;
                }

                data.push(
                    {
                        name: this.name,
                        value: $(this).val()
                    }
                );
            });

            return data;
        }

        /** Блокировка элемента + визуальное отображение */
        $.fn.toggleDisable = function(disabled = false) {
            return this.toggleClass('ui-state-disabled', disabled).prop('disabled', disabled);
        };

        /** Блокировка элемента + дополнительный класс */
        $.fn.disableManual = function(disabled = false, className = 'disabled-manual') {
            return this.toggleClass(className, disabled).toggleDisable(disabled);
        };

        /** Заменить иконку у элементов на колёсико и обратно. */
        $.fn.toggleIconSpinner = function(className = '') {
            this.find('i.fa').toggleClass(className).toggleClass('fa-spinner fa-pulse');

            return this;
        }

        /** Подсветить элемент. */
        $.fn.highlight = function() {
            this.effect('highlight', {color:'#ffc107'}, 1000);

            return this;
        }

        /**
         * Добавляем поддержку прокрутки мышкой по закрытому выпадающему меню.
         */
        $.fn.menuAddWheelCallback = function() {
            const $element = this;

            $element.selectmenu('widget').on('mousewheel', function(event, delta) {
                event.preventDefault();

                if ($(this).hasClass('ui-selectmenu-button-open')) {
                    return false;
                }

                // Нет элементов - нечего прокручивать.
                if (!$element.children().length) {
                    return false;
                }

                const $menu = $element.selectmenu('menuWidget');

                // По какой-то причине jUI не инициализирует menu до первого открытия.
                if (!$menu.children().length) {
                    $element.selectmenu('open').selectmenu('close');
                }

                if (delta > 0 && !$menu.menu('isFirstItem')) {
                    $menu.menu('previous');
                }
                if (delta < 0 && !$menu.menu('isLastItem')) {
                    $menu.menu('next');
                }

                $element.selectmenu('refresh');
            })

            return this;
        };

        /**
         * Инициализировать selectmenu и каждому элементу добавить прокрутку.
         */
        $.fn.selectMenuWheel = function(options) {
            this.selectmenu(options).each(function(_, element) {
                $(element).menuAddWheelCallback();
            });

            return this;
        }

    })(jQuery);

});
