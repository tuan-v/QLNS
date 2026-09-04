import '@mdi/font/css/materialdesignicons.css';
import 'vuetify/styles';
import { createVuetify } from 'vuetify';
import { vi } from 'vuetify/locale';

export default createVuetify({
    locale: {
        locale: 'vi',
        fallback: 'vi',
        messages: { vi },
    },
    // Mặc định dùng chung cho toàn app: đổi ở đây thay vì lặp prop trên từng component.
    defaults: {
        VBtn: {
            rounded: 'lg',
            // Vuetify mặc định VIẾT HOA + giãn chữ, nhìn cũ -> tắt đi
            class: 'text-none font-weight-bold qlns-btn',
        },
        VTextField: {
            variant: 'outlined',
            density: 'comfortable',
        },
        VSelect: {
            variant: 'outlined',
            density: 'comfortable',
        },
        VTextarea: {
            variant: 'outlined',
            density: 'comfortable',
        },
        VCard: {
            rounded: 'lg',
        },
        VChip: {
            rounded: 'lg',
        },
    },
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
            },
            qlnsLight: {
                dark: false,
                colors: {
                    primary: '#7575db',
                    success: '#36d399',
                    background: '#f8fafc',
                }
            }
        }
    }
});
