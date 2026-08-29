/**
 * Имена модулей, используемые в зависимостях.
 * @enum {string}
 */
const ModuleNames = {
    JQUERY_METHODS    : 'jquery_methods',
    JQUERY_WIDGETS    : 'jquery_widgets',
    CONFIG_COMMON     : 'config_common',
    CONFIG_CLIENTS    : 'config_clients',
    CONFIG_SUBSECTIONS: 'config_subsections',
    CONFIG_MAIN       : 'config_main',
    CONFIG_ACTIONS    : 'config_actions',
    TOPICS_ACTIONS    : 'topics_actions',
    TOPICS_FILTERS    : 'topics_filters',
    TOPICS_LIST       : 'topics_list',
    TOPICS_SUBSECTIONS: 'topics_subsections',
    BUTTONS_ACTIONS   : 'buttons_actions',
    REPORTS           : 'reports',
    JOURNAL           : 'journal',
};

window.webtlo = {
    modules: [],

    register: function(name, initFn, dependencies = []) {
        this.modules.push({name, initFn, dependencies, executed: false});
    },

    // Простая топологическая сортировка
    topologicalSort: function(modules) {
        const sorted = [];
        const visited = {};
        const names = modules.map(m => m.name);
        const graph = {};
        modules.forEach(m => {
            graph[m.name] = m.dependencies.filter(d => names.includes(d));
        });

        function visit(name) {
            if (visited[name]) return;
            visited[name] = 'visiting';
            (graph[name] || []).forEach(dep => {
                if (visited[dep] === 'visiting') {
                    throw new Error(`Циклическая зависимость: ${name} -> ${dep}`);
                }

                visit(dep);
            });
            visited[name] = 'done';
            sorted.push(modules.find(m => m.name === name));
        }

        modules.forEach(m => visit(m.name));

        return sorted;
    },

    init: async function() {
        const sorted = this.topologicalSort(this.modules);
        for (const module of sorted) {
            try {
                const result = module.initFn();
                if (result && typeof result.then === 'function') {
                    // Если инициализация асинхронная – ждём.
                    await result;
                }

                module.executed = true;

                console.log(`Модуль "${module.name}" инициализирован`);
            } catch (e) {
                console.error(`Ошибка в модуле "${module.name}":`, e);
            }
        }
    }
};
