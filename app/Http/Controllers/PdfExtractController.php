<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExtractPdfRequest;
use App\Services\ExtractPdf;
use App\Services\PdfExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PdfExtractController extends Controller
{
    public function sources(Request $request, string $scope, ExtractPdf $pdfs): JsonResponse
    {
        $pdfs->assertScope($scope, $request->user()?->id);
        $input = $request->validate(['q' => ['nullable', 'string', 'max:200']]);

        return response()->json(['files' => $pdfs->sources($scope, $request->user()?->id, trim($input['q'] ?? ''))]);
    }

    public function info(Request $request, string $scope, int $id, ExtractPdf $pdfs): JsonResponse
    {
        return response()->json($pdfs->info($scope, $id, $request->user()?->id));
    }

    public function inspect(Request $request, string $scope, ExtractPdf $pdfs, PdfExtractor $extractor): JsonResponse
    {
        $pdfs->assertScope($scope, $request->user()?->id);
        $request->validate(['file' => ['required', ...ExtractPdfRequest::fileRules()]], [
            'file.required' => 'Vui lòng chọn một PDF.', 'file.mimes' => 'Chỉ hỗ trợ file PDF.',
            'file.extensions' => 'File phải có phần mở rộng .pdf.', 'file.max' => 'PDF không được vượt quá 100 MB.',
        ]);

        return response()->json(['name' => $request->file('file')->getClientOriginalName(), 'page_count' => $extractor->pageCount($request->file('file')->getRealPath()), 'selection_limit' => config('pdf_merge.max_selected_pages')]);
    }

    public function preview(Request $request, string $scope, int $id, ExtractPdf $pdfs): BinaryFileResponse
    {
        return response()->file($pdfs->previewPath($scope, $id, $request->user()?->id), [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ])->setPrivate();
    }

    public function store(ExtractPdfRequest $request, string $scope, ExtractPdf $pdfs): JsonResponse
    {
        $copy = $pdfs->create($scope, $request->user()?->id, $request->validated());

        return response()->json(['id' => $copy->id, 'message' => 'Đã tạo PDF mới từ các trang đã chọn.'], 201);
    }
}
