import { ref } from "vue";
import { defineStore } from "pinia";
import logService from "../services/logService";

// Nhật ký hoạt động (tài liệu yêu cầu mục 9: stores/logStore.js) — danh sách + bộ lọc + chi tiết.
export const useLogStore = defineStore("log", () => {
    const logs = ref([]);
    const meta = ref({ current_page: 1, last_page: 1, total: 0 });
    const filterOptions = ref({ users: [], modules: [], actions: [] });
    const loading = ref(false);
    const loadError = ref("");

    async function fetchList(params = {}) {
        loading.value = true;
        loadError.value = "";
        try {
            const response = await logService.list(params);
            logs.value = response.data.data;
            meta.value = response.data.meta;
        } catch (e) {
            logs.value = [];
            loadError.value = e.response?.data?.message ?? "Không tải được nhật ký hoạt động.";
        } finally {
            loading.value = false;
        }
    }

    async function fetchFilters() {
        try {
            filterOptions.value = (await logService.filters()).data;
        } catch {
            filterOptions.value = { users: [], modules: [], actions: [] };
        }
    }

    async function fetchDetail(id) {
        return (await logService.show(id)).data.data;
    }

    return { logs, meta, filterOptions, loading, loadError, fetchList, fetchFilters, fetchDetail };
});
