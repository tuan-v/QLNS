import { ref } from "vue";
import { defineStore } from "pinia";
import roleService from "../services/roleService";

export const useRoleStore = defineStore("role", () => {
    const roles = ref([]);
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
            const response = await roleService.list();
            roles.value = response.data;
        } catch (e) {
            roles.value = [];
            handleError(e);
        } finally {
            loading.value = false;
        }
    }

    async function create(role) {
        resetErrors();
        loading.value = true;
        try {
            const response = await roleService.create(role);
            await fetchList();
            return response.data;
        } catch (e) {
            handleError(e);
            throw e;
        } finally {
            loading.value = false;
        }
    }

    async function update(id, role) {
        resetErrors();
        loading.value = true;
        try {
            const response = await roleService.update(id, role);
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
            const response = await roleService.remove(id);
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
        roles,
        loading,
        errors,
        loadError,
        resetErrors,
        fetchList,
        create,
        update,
        remove,
    };
});
