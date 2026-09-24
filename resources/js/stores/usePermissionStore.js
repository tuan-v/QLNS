import { ref } from "vue";
import { defineStore } from "pinia";
import permissionService from "../services/permissionService";

export const usePermissionStore = defineStore("permission", () => {
    const permissions = ref([]);
    const loading = ref(false);
    const errors = ref({});
    const loadError = ref("");

    function resetErrors() {
        errors.value = {};
        loadError.value = "";
    }

    function handleError(error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data.errors;
        } else {
            loadError.value =
                error.response?.data?.message ??
                "Không thể kết nối máy chủ, vui lòng thử lại.";
        }
    }

    async function fetchList() {
        resetErrors();
        loading.value = true;
        try {
            const response = await permissionService.list();
            permissions.value = response.data;
        } catch (e) {
            permissions.value = [];
            handleError(e);
        } finally {
            loading.value = false;
        }
    }

    async function create(permission) {
        resetErrors();
        loading.value = true;
        try {
            const response = await permissionService.create(permission);
            await fetchList();
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
            const response = await permissionService.remove(id);
            await fetchList();
            return response.data;
        } catch (e) {
            handleError(e);
            throw e;
        } finally {
            loading.value = false;
        }
    }

    return {
        permissions,
        loading,
        errors,
        loadError,
        resetErrors,
        fetchList,
        create,
        remove,
    };
});
