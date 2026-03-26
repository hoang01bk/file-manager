<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenSpout\Reader\XLSX\Reader;

class ImportUsersFromExcel extends Command
{
    protected $signature = 'users:import {file : Đường dẫn đến file Excel}';

    protected $description = 'Import danh sách user từ file Excel (cột UserCode, UserName, Password)';

    private const BATCH_SIZE = 200;

    public function handle(): int
    {
        $filePath = $this->argument('file');

        if (! file_exists($filePath)) {
            Log::error("[ImportUsers] File không tồn tại: {$filePath}");
            return self::FAILURE;
        }

        Log::info("[ImportUsers] Bắt đầu đọc file: {$filePath}");

        $reader = new Reader();
        try {
            $reader->open($filePath);
        } catch (\Exception $e) {
            Log::error("[ImportUsers] Không thể mở file Excel: " . $e->getMessage());
            return self::FAILURE;
        }

        // Load toàn bộ employee_code hiện có vào RAM - chỉ 1 query duy nhất
        $existingCodes = array_flip(
            DB::table('users')->pluck('employee_code')->toArray()
        );

        $codeIdx     = null;
        $nameIdx     = null;
        $passwordIdx = null;
        $inserted    = 0;
        $skipped     = 0;
        $errors      = 0;
        $lineNo      = 0;
        $batch       = [];
        $now         = now()->toDateTimeString();

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $lineNo++;
                $cells = array_map(function ($value) {
                    if ($value instanceof \DateTimeInterface) {
                        return $value->format('Y-m-d H:i:s');
                    }
                    return trim((string) ($value ?? ''));
                }, $row->toArray());

                // Dòng đầu tiên: đọc header
                if ($lineNo === 1) {
                    $headers     = array_map('strtolower', $cells);
                    $codeIdx     = array_search('usercode', $headers);
                    $nameIdx     = array_search('username', $headers);
                    $passwordIdx = array_search('password', $headers);

                    if ($codeIdx === false || $nameIdx === false || $passwordIdx === false) {
                        Log::error("[ImportUsers] Không tìm thấy đủ cột. Các cột hiện có: " . implode(', ', $cells));
                        $reader->close();
                        return self::FAILURE;
                    }
                    continue;
                }

                $userCode = $cells[$codeIdx] ?? '';
                $userName = $cells[$nameIdx] ?? '';
                $password = $cells[$passwordIdx] ?? '';

                if ($userCode === '') {
                    $errors++;
                    Log::warning("[ImportUsers] Dòng {$lineNo}: UserCode rỗng, bỏ qua.");
                    continue;
                }

                // Kiểm tra trùng trong RAM - O(1), không query DB
                if (isset($existingCodes[$userCode])) {
                    $skipped++;
                    continue;
                }

                // Đánh dấu đã xử lý để tránh trùng trong cùng file
                $existingCodes[$userCode] = true;

                $batch[] = [
                    'employee_code' => $userCode,
                    'name'          => $userName ?: $userCode,
                    'password'      => $password,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];

                $inserted++;

                // Insert theo batch
                if (count($batch) >= self::BATCH_SIZE) {
                    DB::table('users')->insert($batch);
                    Log::info("[ImportUsers] Đã insert batch " . $inserted . " records.");
                    $batch = [];
                }
            }
            break; // Chỉ đọc sheet đầu tiên
        }

        // Insert phần còn lại
        if (! empty($batch)) {
            DB::table('users')->insert($batch);
        }

        $reader->close();

        Log::info("[ImportUsers] Hoàn tất. Thêm mới: {$inserted}, Bỏ qua (trùng): {$skipped}, Lỗi dữ liệu: {$errors}.");

        return self::SUCCESS;
    }
}
