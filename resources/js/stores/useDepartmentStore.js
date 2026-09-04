import { ref } from "vue";
import { defineStore } from "pinia";
import departmentService from "../services/departmentService";

export const useDepartmentStore = defineStore("department", () => {
    const departments = ref([]);
    const pagination = ref(null);
    const tree = ref([]);
    const loading = ref(false);
    const errors = ref({});
    const loadError = ref("");

    function resetErrors() {
        errors.value = {};
        loadError.value = "";
    }

    // Lỗi 422 -> errors (hiển thị tại từng ô nhập), lỗi khác -> loadError (thông báo chung)
    function handleError(error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data.errors;
        } else {
            loadError.value =
                error.response?.data?.message ??
                "Không thể kết nối máy chủ, vui lòng thử lại.";
        }
    }

    async function fetchList(page = 1) {
        resetErrors();
        loading.value = true;
        try {
            const response = await departmentService.list(page);
            const { data, ...meta } = response.data;
            departments.value = data;
            pagination.value = meta;
        } catch (e) {
            handleError(e);
        } finally {
            loading.value = false;
        }
    }

    async function fetchTree() {
        resetErrors();
        loading.value = true;
        try {
            const response = await departmentService.tree();
            tree.value = response.data;
        } catch (e) {
            handleError(e);
        } finally {
            loading.value = false;
        }
    }

    async function create(department) {
        resetErrors();
        loading.value = true;
        try {
            const response = await departmentService.create(department);
            await fetchTree();
            return response.data;
        } catch (e) {
            handleError(e);
            throw e;
        } finally {
            loading.value = false;
        }
    }

    async function update(id, department) {
        resetErrors();
        loading.value = true;
        try {
            const response = await departmentService.update(id, department);
            await fetchTree();
            return response.data;
        } catch (e) {
            handleError(e);
            throw e;
        } finally {
            loading.value = false;
        }
    }

    async function remove(id) {
        resetErrors();
        loading.value = true;
        try {
            const response = await departmentService.remove(id);
            await fetchTree();
            return response.data;
        } catch (e) {
            handleError(e);
            throw e;
        } finally {
            loading.value = false;
        }
    }

    return {
        departments,
        pagination,
        tree,
        loading,
        errors,
        loadError,
        resetErrors,
        fetchList,
        fetchTree,
        create,
        update,
        remove,
    };
});
