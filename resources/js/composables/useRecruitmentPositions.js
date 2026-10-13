import { computed, ref } from "vue";
import recruitmentService from "../services/recruitmentService";

// Chức vụ đang dùng (kèm phòng ban + bộ câu hỏi mặc định) cho module Tuyển dụng — lấy qua
// GET /recruitment/position-question-sets (quyền tuyển dụng, không cần quyền xem phòng ban).
// Nạp 1 lần, các component (form CV, offer, bộ lọc) dùng chung; reload() để tải lại.
const positions = ref([]);
let pending = null;

async function reload() {
    pending = recruitmentService
        .positionQuestionSets()
        .then((response) => {
            positions.value = response.data.data;
        })
        .catch(() => {
            positions.value = [];
        });
    return pending;
}

export function useRecruitmentPositions() {
    if (!pending) reload();

    // Mục chọn "Chức vụ — Phòng ban".
    const positionItems = computed(() =>
        positions.value.map((p) => ({ title: p.department ? `${p.name} — ${p.department}` : p.name, value: p.id })),
    );
    const positionById = (id) => positions.value.find((p) => p.id === id) ?? null;

    return { positions, positionItems, positionById, reload };
}
