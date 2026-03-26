<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\FileController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\UserPostController;
use App\Http\Controllers\MyFileController;


// Route::get('/', function () {
//     return Inertia::render('Welcome', [
//         'canLogin' => Route::has('login'),
//         'canRegister' => Route::has('register'),
//         'laravelVersion' => Application::VERSION,
//         'phpVersion' => PHP_VERSION,
//     ]);
// });

// Route::get('/dashboard', function () {
//     return Inertia::render('Dashboard');
// })->middleware(['auth', 'verified'])->name('dashboard');

// Route::middleware('auth')->group(function () {
//     Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
//     Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
//     Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
// });

// Trang chủ mặc định - trỏ thẳng vào giao diện quản lý file luôn
Route::get('/', [FileController::class , 'index'])->name('dashboard');

// Route Dashboard cũ cũng trỏ về FileController
Route::get('/dashboard', [FileController::class , 'index']);

// Các route xử lý file (Đã bỏ middleware auth)
Route::post('/upload', [FileController::class , 'store'])->name('files.store');
Route::get('/files/{id}/download', [FileController::class , 'download'])->name('files.download');
Route::get('/files/{id}/preview', [FileController::class , 'preview'])->name('files.preview');
Route::delete('/files/{id}', [FileController::class , 'destroy'])->name('files.destroy');

// Giữ lại các route profile (có thể vẫn cần auth nếu bạn muốn quay lại sau này)
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class , 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class , 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class , 'destroy'])->name('profile.destroy');
});

// Routes for posts
Route::post('/posts', [PostController::class, 'store']);

// Routes for personal files (requires auth)
Route::middleware('auth')->group(function () {
    Route::get('/my-files', [MyFileController::class, 'index'])->name('my-files.index');
    Route::post('/my-files/upload', [MyFileController::class, 'store'])->name('my-files.store');
    Route::delete('/my-files/{id}', [MyFileController::class, 'destroy'])->name('my-files.destroy');
    Route::get('/my-files/{id}/download', [MyFileController::class, 'download'])->name('my-files.download');
    Route::get('/my-files/{id}/preview', [MyFileController::class, 'preview'])->name('my-files.preview');

    Route::post('/user-posts', [UserPostController::class, 'store'])->name('user-posts.store');
    Route::delete('/user-posts/{id}', [UserPostController::class, 'destroy'])->name('user-posts.destroy');
});

require __DIR__ . '/auth.php';
