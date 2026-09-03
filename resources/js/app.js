import './bootstrap';

import { createApp } from 'vue';
import App from './App.vue';

import 'vuetify/styles';
import router from './router/index.js';
import vuetify from './plugins/vuetify.js';
import { createPinia } from 'pinia';

const app = createApp(App);
app.use(router);
app.use(vuetify);
app.use(createPinia());
app.mount('#app');
