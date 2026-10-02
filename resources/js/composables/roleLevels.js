// Thang cấp bậc vai trò — bản mirror của `Role::LEVELS` (app/Models/Role.php,
// nguồn chuẩn, cũng là rule validate ở Store/UpdateRoleRequest). Chỉ gán được
// cho người khác những vai trò có cấp bậc THẤP HƠN vai trò của chính mình, xem
// RoleService::assignableRolesQuery().
//
// Nhãn ghi "ngang <vai trò>" là MỐC THAM CHIẾU theo 4 vai trò seed sẵn, không
// phải tên cố định của bậc — vai trò tự tạo đặt cùng bậc nào thì ngang quyền
// gán với vai trò seed ở bậc đó.
export const ROLE_LEVEL_OPTIONS = [
    { value: 100, title: "100 — Quản trị hệ thống (ngang Admin)" },
    { value: 80, title: "80 — Trên Nhân sự" },
    { value: 60, title: "60 — Quản lý nhân sự (HR)" },
    { value: 40, title: "40 — Trưởng phòng" },
    { value: 20, title: "20 — Nhân viên" },
    { value: 0, title: "0 — Thấp nhất" },
];

export const DEFAULT_ROLE_LEVEL = 100;

/** Nhãn ngắn để hiện trong bảng, vd 60 -> "60 — Ngang Nhân sự (HR)". */
export function roleLevelLabel(level) {
    return (
        ROLE_LEVEL_OPTIONS.find((option) => option.value === level)?.title ??
        String(level ?? "—")
    );
}
