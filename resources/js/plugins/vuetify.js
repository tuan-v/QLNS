import '@mdi/font/css/materialdesignicons.css';
import 'vuetify/styles';
import { createVuetify } from 'vuetify';

export default createVuetify({
    theme: {
        defaultTheme: 'qlnsDark',
        themes: {
            qlnsDark: {
                dark: true,
                colors: {
                    primary: '#7575db',
                    success: '#36d399',
                    background: '#0f1729',
                }
            }
        }
    }
});
