import { useToastStore } from "../stores/useToastStore";

export const NO_CHANGE_MESSAGE = "Bạn chưa chỉnh sửa gì nên không có thông tin nào được cập nhật.";

// Chặn "lưu" khi form SỬA chưa đổi gì (2026-09-30, theo yêu cầu người dùng —
// tránh báo "Đã cập nhật" khi thật ra không có gì thay đổi, gây hiểu nhầm).
//
//   const guard = useChangeGuard(() => form);          // trả về phần dữ liệu cần so sánh
//   ... sau khi nạp dữ liệu cũ vào form:  guard.takeSnapshot();
//   ... đầu hàm submit của form SỬA:
//       if (isEdit.value && guard.skipIfUnchanged()) { close(); return; }
//
// skipIfUnchanged() báo toast "chưa chỉnh sửa gì" và trả true khi giá trị hiện
// tại y hệt lúc chụp; chưa chụp lần nào thì trả false (cứ lưu bình thường).
export function useChangeGuard(getState) {
    const toast = useToastStore();
    let snapshot = null;

    const serialize = () => JSON.stringify(getState());

    return {
        takeSnapshot() {
            snapshot = serialize();
        },
        isUnchanged() {
            return snapshot !== null && serialize() === snapshot;
        },
        skipIfUnchanged() {
            if (snapshot !== null && serialize() === snapshot) {
                toast.info(NO_CHANGE_MESSAGE);
                return true;
            }
            return false;
        },
    };
}
