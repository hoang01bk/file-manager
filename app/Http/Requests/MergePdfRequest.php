<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class MergePdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('scope') === 'shared' || $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200', 'not_regex:~[/\\\\\x00-\x1F\x7F]~'],
            'pdfs' => ['required', 'array', 'min:1', 'max:'.config('pdf_merge.max_files')],
            'pdfs.*' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:'.intdiv(config('upload_limits.max_file_size'), 1024)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (collect($this->file('pdfs'))->sum(fn ($file) => $file->getSize()) > config('upload_limits.max_file_size')) {
                $validator->errors()->add('pdfs', 'Tổng dung lượng các PDF tải thêm không được vượt quá 100 MB.');
            }
            if (trim((string) $this->input('name')) === '' || strtolower(trim((string) $this->input('name'))) === '.pdf') {
                $validator->errors()->add('name', 'Vui lòng nhập tên file PDF mới.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên file PDF mới.',
            'name.max' => 'Tên file không được vượt quá 200 ký tự.',
            'name.not_regex' => 'Tên file không được chứa dấu gạch chéo hoặc ký tự điều khiển.',
            'pdfs.required' => 'Vui lòng chọn ít nhất một PDF để ghép thêm.',
            'pdfs.max' => 'Chỉ được ghép thêm tối đa 5 PDF mỗi lần.',
            'pdfs.*.mimes' => 'Chỉ hỗ trợ file PDF.',
            'pdfs.*.extensions' => 'File phải có phần mở rộng .pdf.',
            'pdfs.*.max' => 'Mỗi PDF không được vượt quá 100 MB.',
        ];
    }
}
