import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import axios from 'axios';

window.Pusher = Pusher;

let echoInstance = null;

/**
 * Echo được tạo lười (chỉ kết nối khi thật sự có người gọi getEcho() lần đầu)
 * nhưng dùng CHUNG 1 kết nối cho toàn app — do usePresenceStore mở ngay khi
 * đăng nhập (App.vue), không phải theo từng trang, để "online" phản ánh đúng
 * việc đang mở app chứ không phải đang đứng đúng trang Nhân viên.
 */
export function getEcho() {
    if (echoInstance) {
        return echoInstance;
    }

    echoInstance = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        // Đăng nhập hệ thống dùng JWT Bearer token (không dùng cookie/Sanctum),
        // nên phải tự gọi axios (đã có interceptor gắn sẵn Authorization header)
        // thay vì để Echo tự fetch mặc định.
        authorizer: (channel) => ({
            authorize: (socketId, callback) => {
                axios
                    .post('/api/v1/broadcasting/auth', {
                        socket_id: socketId,
                        channel_name: channel.name,
                    })
                    .then((response) => callback(false, response.data))
                    .catch((error) => callback(true, error));
            },
        }),
    });

    return echoInstance;
}

export function disconnectEcho() {
    if (echoInstance) {
        echoInstance.disconnect();
        echoInstance = null;
    }
}
