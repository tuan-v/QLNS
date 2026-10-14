<?php

use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectTaskController;
use Illuminate\Support\Facades\Route;

// Dự án & công việc (2026-10-14) — xem ProjectService / ProjectTaskService.
// Chỉ tạo/xóa dự án gắn mã quyền (project.manage); Lead/thành viên là quyền THEO DỰ ÁN,
// kiểm trong Service (mọi tài khoản đăng nhập đều có thể là Lead/thành viên của dự án nào đó).
Route::middleware('auth:api')->group(function (): void {
    Route::prefix('projects')->group(function (): void {
        Route::get('/', [ProjectController::class, 'index']);
        Route::post('/', [ProjectController::class, 'store'])->middleware('permission:project.manage');
        Route::get('/lead-candidates', [ProjectController::class, 'leadCandidates'])->middleware('permission:project.manage');
        Route::get('/{project}', [ProjectController::class, 'show'])->whereNumber('project');
        Route::put('/{project}', [ProjectController::class, 'update'])->whereNumber('project');
        Route::delete('/{project}', [ProjectController::class, 'destroy'])->whereNumber('project')->middleware('permission:project.manage');

        Route::get('/{project}/member-candidates', [ProjectController::class, 'memberCandidates'])->whereNumber('project');
        Route::post('/{project}/members', [ProjectController::class, 'addMembers'])->whereNumber('project');
        Route::delete('/{project}/members/{employee}', [ProjectController::class, 'removeMember'])->whereNumber('project');

        Route::get('/{project}/tasks', [ProjectTaskController::class, 'index'])->whereNumber('project');
        Route::post('/{project}/tasks', [ProjectTaskController::class, 'store'])->whereNumber('project');
    });

    Route::prefix('tasks')->group(function (): void {
        // "/me" khai TRƯỚC các route {task}, cùng quy ước mọi route "/me" khác.
        Route::get('/me', [ProjectTaskController::class, 'mine']);
        Route::get('/{task}', [ProjectTaskController::class, 'show'])->whereNumber('task');
        Route::put('/{task}', [ProjectTaskController::class, 'update'])->whereNumber('task');
        Route::delete('/{task}', [ProjectTaskController::class, 'destroy'])->whereNumber('task');
        Route::post('/{task}/cancel', [ProjectTaskController::class, 'cancel'])->whereNumber('task');
        Route::post('/{task}/start', [ProjectTaskController::class, 'start'])->whereNumber('task');
        Route::post('/{task}/submit', [ProjectTaskController::class, 'submit'])->whereNumber('task');
        Route::post('/{task}/review', [ProjectTaskController::class, 'review'])->whereNumber('task');
    });
});
