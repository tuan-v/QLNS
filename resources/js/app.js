import './bootstrap';

import { createApp } from 'vue';
import App from './App.vue';

import 'vuetify/styles';
import router from './router/index.js';
import vuetify from './plugins/vuetify.js';
import { createPinia } from 'pinia';

const app = createApp(App);
// Pinia phải đăng ký TRƯỚC router: guard của router gọi useLoadingStore(), mà
// store chỉ dùng được sau khi Pinia đã được cài vào app.
app.use(createPinia());
app.use(router);
app.use(vuetify);
app.mount('#app');
