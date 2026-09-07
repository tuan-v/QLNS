import { defineStore } from "pinia";
import { nextTick, ref } from "vue";

// Thời gian hiển thị mặc định. Lỗi để lâu hơn vì người dùng cần đọc rồi mới
// biết phải làm gì tiếp.
const DURATION = { success: 3500, info: 4000, warning: 5000, error: 6000 };

// Khoảng chờ giữa hai thông báo, đủ để hiệu ứng đóng chạy xong rồi cái sau mới
// hiện — nếu không hai cái sẽ chồng lên nhau lúc chuyển.
const GAP = 300;

/**
 * Hàng đợi thông báo ngắn (toast) dùng chung toàn app.
 *
 * Gọi ở bất kỳ đâu:
 *   const toast = useToastStore();
 *   toast.success("Đã lưu nhân viên.");
 *
 * Component hiển thị là `AppToast.vue`, gắn một lần duy nhất trong `App.vue`.
 *
 * TOÀN BỘ trạng thái và hẹn giờ nằm ở store này, component chỉ vẽ ra. Lý do:
 *   - `<v-snackbar-queue>` của Vuetify 3.13.3 không bao giờ tự tắt. Nguyên nhân
 *     ở `VSnackbarQueue.showNext()`: nó bật `isActive` trong `nextTick()`, mà
 *     `VSnackbar` chỉ lên hẹn giờ ở `onMounted` (`if (isActive.value)
 *     startTimeout()`) hoặc khi `isActive` ĐỔI giá trị — snackbar được mount
 *     sau khi `isActive` đã là true nên không nhánh nào chạy.
 *   - Bản tự viết đầu tiên để `visible`/`current` trong component rồi `watch`
 *     qua lại với store cũng hỏng: hai watcher tranh nhau, có lúc message bị
 *     lấy khỏi hàng đợi nhưng không hiện ra.
 * Nên `AppToast.vue` truyền `:timeout="-1"` để Vuetify không đụng vào hẹn giờ,
 * mọi mốc thời gian do store quyết định và đọc/kiểm tra được từ ngoài.
 */
export const useToastStore = defineStore("toast", () => {
    const queue = ref([]);
    const current = ref(null);
    const visible = ref(false);

    let hideTimer = null;
    let nextTimer = null;

    function show(item) {
        clearTimeout(hideTimer);
        clearTimeout(nextTimer);

        current.value = item;

        // Đặt `current` làm phần tử được v-if mount vào DOM. Phải để nó mount ở
        // trạng thái ĐÓNG rồi mới mở ở tick sau: mount thẳng khi đã mở thì
        // Vuetify bỏ qua hoạt ảnh vào và `opacity` kẹt ở 0 — toast nằm trong
        // DOM đầy đủ chữ và màu nhưng người dùng không nhìn thấy gì.
        visible.value = false;
        nextTick(() => {
            visible.value = true;
        });

        hideTimer = setTimeout(hide, item.duration);
    }

    function hide() {
        clearTimeout(hideTimer);
        clearTimeout(nextTimer);

        visible.value = false;

        nextTimer = setTimeout(() => {
            const next = queue.value.shift();

            if (next) {
                show(next);
            } else {
                current.value = null;
            }
        }, GAP);
    }

    function push(text, color) {
        if (!text) {
            return;
        }

        const item = { text, color, duration: DURATION[color] ?? 4000 };

        // Cờ "đang bận" phải là `current` chứ KHÔNG phải `visible`: `visible`
        // chỉ bật ở nextTick, nên hai lần push liên tiếp trong cùng một lượt
        // chạy đều thấy visible=false và cái sau sẽ đè mất cái trước.
        if (current.value) {
            queue.value.push(item);
        } else {
            show(item);
        }
    }

    const success = (text) => push(text, "success");
    const info = (text) => push(text, "info");
    const warning = (text) => push(text, "warning");
    const error = (text) => push(text, "error");

    function clear() {
        clearTimeout(hideTimer);
        clearTimeout(nextTimer);
        queue.value = [];
        current.value = null;
        visible.value = false;
    }

    return {
        queue,
        current,
        visible,
        push,
        hide,
        clear,
        success,
        info,
        warning,
        error,
    };
});
