<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Holiday\SaveHolidayAnnouncementRequest;
use App\Services\HolidayAnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Văn bản thông báo nghỉ lễ của một dịp: người quản lý (holiday.manage) soạn -> chốt trình
// ký -> tải lên bản đã ký -> phát hành; mọi người đăng nhập xem/tải BẢN ĐÃ KÝ đã phát hành.
class HolidayAnnouncementController extends Controller
{
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function __construct(private readonly HolidayAnnouncementService $announcementService)
    {
    }

    public function show(Request $request, string $groupCode): JsonResponse
    {
        return response()->json($this->announcementService->show($groupCode, $request->user()->hasPermission('holiday.manage')));
    }

    public function update(SaveHolidayAnnouncementRequest $request, string $groupCode): JsonResponse
    {
        return response()->json($this->announcementService->save($groupCode, $request->validated(), $request->user()));
    }

    public function prepareSigning(SaveHolidayAnnouncementRequest $request, string $groupCode): JsonResponse
    {
        return response()->json($this->announcementService->prepareSigning($groupCode, $request->validated(), $request->user()));
    }

    public function uploadSigned(Request $request, string $groupCode): JsonResponse
    {
        $request->validate(
            ['signed_file' => ['required', 'file', 'mimes:pdf', 'max:10240']],
            [
                'signed_file.required' => 'Vui lòng chọn bản scan đã ký (PDF)',
                'signed_file.mimes' => 'Bản đã ký phải ở định dạng PDF',
                'signed_file.max' => 'Dung lượng tệp không được vượt quá 10MB',
            ],
        );

        return response()->json($this->announcementService->uploadSigned($groupCode, $request->file('signed_file'), $request->user()));
    }

    public function publish(Request $request, string $groupCode): JsonResponse
    {
        [$data, $recipients] = $this->announcementService->publish($groupCode, $request->user());

        return response()->json($data + ['recipients' => $recipients]);
    }

    public function reopen(Request $request, string $groupCode): JsonResponse
    {
        return response()->json($this->announcementService->reopen($groupCode, $request->user()));
    }

    // Bản Word đã lưu — để in trình ký.
    public function download(string $groupCode): BinaryFileResponse
    {
        return $this->docx($this->announcementService->docx($groupCode, null));
    }

    // Bản Word của nội dung đang soạn (chưa lưu) — để xem trước.
    public function preview(SaveHolidayAnnouncementRequest $request, string $groupCode): BinaryFileResponse
    {
        return $this->docx($this->announcementService->docx($groupCode, $request->validated()));
    }

    // Bản PDF đã ký (nhân viên: chỉ khi đã phát hành).
    public function signed(Request $request, string $groupCode): StreamedResponse
    {
        [$path, $filename] = $this->announcementService->signedFile($groupCode, $request->user()->hasPermission('holiday.manage'));

        return Storage::disk('local')->download($path, $filename, ['Content-Type' => 'application/pdf']);
    }

    private function docx(array $file): BinaryFileResponse
    {
        [$path, $filename] = $file;

        return response()->download($path, $filename, ['Content-Type' => self::DOCX_MIME])->deleteFileAfterSend();
    }
}
