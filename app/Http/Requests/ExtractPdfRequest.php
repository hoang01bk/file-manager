<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExtractPdfRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // Multipart uploads send this map as one JSON field to avoid max_input_vars truncation.
        if (is_string($this->input('rotations'))) {
            $decoded = json_decode($this->input('rotations'), true);
            if (is_array($decoded)) {
                $this->merge(['rotations' => $decoded]);
            }
        }
    }

    public function authorize(): bool
    {
        return $this->route('scope') === 'shared' || $this->user() !== null;
    }

    public static function fileRules(): array
    {
        return ['file', 'mimes:pdf', 'extensions:pdf', 'max:'.intdiv(config('upload_limits.max_file_size'), 1024)];
    }

    public function rules(): array
    {
        return [
            'source_id' => ['nullable', 'integer', 'min:1', Rule::requiredIf(! $this->hasFile('file')), Rule::prohibitedIf($this->hasFile('file'))],
            'file' => ['nullable', ...self::fileRules(), Rule::requiredIf(! $this->filled('source_id')), Rule::prohibitedIf($this->filled('source_id'))],
            'name' => ['required', 'string', 'max:200', 'not_regex:~[/\\\\\x00-\x1F\x7F]~', 'not_regex:~^\s*\.pdf\s*$~i'],
            'pages' => ['required', 'string', 'max:50000'],
            'rotations' => ['sometimes', 'array', 'max:'.config('pdf_merge.max_selected_pages')],
            'rotations.*' => ['required', 'integer', Rule::in([0, 90, 180, 270])],
            'ttl_minutes' => [Rule::requiredIf($this->route('scope') === 'shared' && ! $this->filled('source_id')), 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'source_id.required' => 'Vui lòng chọn PDF từ máy hoặc PDF đã upload.',
            'source_id.prohibited' => 'Chỉ được chọn một nguồn PDF.',
            'file.required' => 'Vui lòng chọn PDF từ máy hoặc PDF đã upload.',
            'file.prohibited' => 'Chỉ được chọn một nguồn PDF.',
            'file.mimes' => 'Chỉ hỗ trợ file PDF.',
            'file.extensions' => 'File phải có phần mở rộng .pdf.',
            'file.max' => 'PDF không được vượt quá 100 MB.',
            'name.required' => 'Vui lòng nhập tên PDF mới.',
            'name.max' => 'Tên file không được vượt quá 200 ký tự.',
            'name.not_regex' => 'Tên PDF mới không hợp lệ.',
            'pages.required' => 'Vui lòng chọn ít nhất một trang.',
            'pages.max' => 'Danh sách trang quá dài.',
            'rotations.array' => 'Danh sách xoay trang không hợp lệ.',
            'rotations.max' => 'Có quá nhiều trang cần xoay.',
            'rotations.*.required' => 'Vui lòng chọn góc xoay của trang.',
            'rotations.*.integer' => 'Góc xoay phải là 0, 90, 180 hoặc 270 độ.',
            'rotations.*.in' => 'Góc xoay phải là 0, 90, 180 hoặc 270 độ.',
            'ttl_minutes.required' => 'Vui lòng chọn thời gian lưu PDF mới.',
            'ttl_minutes.min' => 'Thời gian lưu tối thiểu là 1 phút.',
        ];
    }
}
