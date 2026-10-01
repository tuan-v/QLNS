import { ref } from "vue";
import { defineStore } from "pinia";
import permissionService from "../services/permissionService";

export const usePermissionStore = defineStore("permission", () => {
    const permissions = ref([]);
    const loading = ref(false);
    const errors = ref({});
    // Lỗi 422 của form SỬA quyền để riêng, không dùng chung với `errors` (form
    // Tạo quyền mới) — hai form hiển thị cùng lúc trong RolePermissionsDialog,
    // dùng chung một túi lỗi thì sửa sai mã quyền sẽ làm đỏ luôn ô của form tạo.
    const editErrors = ref({});
    const loadError = ref("");

    function resetErrors() {
        errors.value = {};
        editErrors.value = {};
        loadError.value = "";
    }

    function handleError(error, bag = errors) {
        if (error.response?.status === 422) {
            bag.value = error.response.data.errors;
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

    async function update(id, permission) {
        resetErrors();
        loading.value = true;
        try {
            const response = await permissionService.update(id, permission);
            await fetchList();
            return response.data;
        } catch (e) {
            handleError(e, editErrors);
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
        editErrors,
        loadError,
        resetErrors,
        fetchList,
        create,
        update,
        remove,
    };
});
