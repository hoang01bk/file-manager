<?php

namespace App\Http\Controllers;

use App\Http\Requests\MergePdfRequest;
use App\Services\MergeUploadedPdf;
use Illuminate\Http\JsonResponse;

class PdfMergeController extends Controller
{
    public function __invoke(MergePdfRequest $request, string $scope, int $id, MergeUploadedPdf $merger): JsonResponse
    {
        $input = $request->validated();
        $file = $merger->create($scope, $id, $request->user()?->id, $input['name'], $input['pdfs']);

        return response()->json(['id' => $file->id, 'message' => 'Đã tạo PDF mới. File gốc được giữ nguyên.'], 201);
    }
}
