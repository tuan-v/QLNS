import { watch } from "vue";

// Bộ luật kiểm tra ô nhập DÙNG CHUNG cho mọi form (Vuetify `:rules`). Mỗi luật
// trả `true` nếu hợp lệ hoặc chuỗi lỗi tiếng Việt. Luật chỉ phản chiếu đúng rule
// của Backend để báo sớm — Backend vẫn là nơi kiểm tra cuối cùng.
//
// Hành vi báo lỗi (chỉ báo khi RỜI ô, hết lỗi ngay khi sửa đúng, không đỏ sẵn lúc
// mở form) do `validate-on="blur invalid-input lazy"` — FormDialog đã đặt sẵn
// cho mọi form dùng nó; form tự dựng `v-form` thì tự đặt thuộc tính này.

export const notEmpty = (label) => (value) =>
    (value !== null && value !== undefined && String(value).trim() !== "") ||
    `${label} không được để trống`;

export const maxLength = (limit, label) => (value) =>
    !value || String(value).length <= limit || `${label} không được vượt quá ${limit} ký tự`;

export const isEmail = (label) => (value) =>
    !value || /^\S+@\S+\.\S+$/.test(String(value)) || `${label} không đúng định dạng`;

export const minValue = (min, label) => (value) =>
    value === null ||
    value === undefined ||
    value === "" ||
    Number(value) >= min ||
    `${label} không được nhỏ hơn ${min}`;

// Phải là số nguyên (vd cấp bậc, số phút).
export const isInteger = (label) => (value) =>
    value === null ||
    value === undefined ||
    value === "" ||
    Number.isInteger(Number(value)) ||
    `${label} phải là số nguyên`;

// Sửa ô nào thì lỗi 422 của lần Lưu trước ở đúng ô đó tự biến mất (thay vì đỏ mãi
// tới lần Lưu sau). `getErrors` trả về object lỗi hiện tại, vd () => store.errors
// hoặc () => errors.value.
export function useClearErrorsOnEdit(source, getErrors, keyMap = {}) {
    // `source`: object reactive, hoặc hàm trả về object (dùng cho form là ref, vd
    // () => form.value). `keyMap`: khi tên field của form khác tên field lỗi từ
    // Backend (vd camelCase -> snake_case).
    const read = () => (typeof source === "function" ? source() : source);
    for (const key of Object.keys(read())) {
        watch(
            () => read()[key],
            () => {
                const errors = getErrors();
                const errorKey = keyMap[key] ?? key;
                if (errors && errors[errorKey]) {
                    delete errors[errorKey];
                }
            },
        );
    }
}

// Dùng cho nút Gửi nằm NGOÀI <v-form> (footer dialog): kiểm tra các ô trong form
// trước, chỉ chạy `submit` khi hợp lệ. Vd: @click="validateThen(formRef, submit)".
export async function validateThen(form, submit) {
    // Trong template, ref cấp cao nhất được tự "mở" nên nhận thẳng instance của
    // v-form; trong script thì nhận ref (có .value) — hỗ trợ cả hai.
    const instance = form?.value ?? form;
    const { valid } = await instance.validate();
    if (valid) {
        return submit();
    }
}
