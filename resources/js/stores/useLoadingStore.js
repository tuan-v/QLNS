import { defineStore } from "pinia";
import { computed, ref } from "vue";

/**
 * Trạng thái "đang bận" toàn cục, dùng cho thanh loading trên cùng màn hình
 * (`AppLoadingBar.vue`). Được bật/tắt tự động từ:
 *   - interceptor axios trong `bootstrap.js` (mỗi request đang chạy)
 *   - guard của vue-router trong `router/index.js` (mỗi lần chuyển trang)
 *
 * Đây là loading CHUNG của cả app, không thay cho `loading` riêng của từng
 * store — cái đó vẫn dùng để khóa nút Lưu hay hiện skeleton trong bảng.
 */
export const useLoadingStore = defineStore("loading", () => {
    // Đếm số việc đang chạy chứ không dùng cờ true/false: hai request chồng
    // nhau mà cái nhanh xong trước sẽ tắt mất thanh loading của cái còn lại.
    const pending = ref(0);
    const active = computed(() => pending.value > 0);

    function start() {
        pending.value += 1;
    }

    function stop() {
        // Chặn dưới 0 phòng trường hợp stop() bị gọi thừa (ví dụ một luồng lỗi
        // vừa tự dọn vừa rơi vào interceptor), nếu không bộ đếm sẽ âm và lần
        // bận tiếp theo không hiện thanh loading nữa.
        pending.value = Math.max(0, pending.value - 1);
    }

    function reset() {
        pending.value = 0;
    }

    return { pending, active, start, stop, reset };
});
