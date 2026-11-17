import '../css/app.css';
import { createInertiaApp } from '@inertiajs/vue3';
import ui from '@nuxt/ui/vue-plugin';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';

createInertiaApp({
    title: (title) => title ? title + ' · GitVerse Gateway' : 'GitVerse Gateway',
    resolve: (name) => resolvePageComponent(
        './pages/' + name + '.vue',
        import.meta.glob('./pages/**/*.vue'),
    ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ui)
            .mount(el);
    },
});
