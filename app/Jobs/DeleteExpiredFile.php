<?php

namespace App\Jobs;

use App\Models\Upload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteExpiredFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(protected Upload $upload) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        DB::transaction(function () {
            $upload = Upload::lockForUpdate()->find($this->upload->id);
            if (! $upload || ! $upload->expired_at || $upload->expired_at->isFuture()) {
                return;
            }
            $disk = Storage::disk($upload->view_only ? 'local' : 'public');

            if ($disk->exists($upload->file_path)) {
                $disk->delete($upload->file_path);
            }
            $upload->delete();
        });
    }
}
