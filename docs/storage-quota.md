# Giới hạn dung lượng file

Ứng dụng nhận tối đa 100 MiB cho mỗi file và cho mỗi tài khoản lưu tối đa 1 GiB. File ở bảng tin chung và file cá nhân của cùng một chủ sở hữu được cộng chung; file được người khác chia sẻ không tính vào người nhận. Upload chưa đăng nhập dùng một vùng 1 GiB chung cho tất cả khách, không chia theo IP.

Quota dựa trên dung lượng file đang lưu. Khi xóa file, hoặc khi tác vụ `app:clean-expired-files` xóa file hết hạn, dung lượng được giải phóng. Bản lưu mới từ ONLYOFFICE cập nhật kích thước trong database; nếu bản mới vượt quota thì ứng dụng từ chối lần lưu đó và giữ bản cũ. Người dùng vẫn có thể tải hoặc xóa file khi quota đã đầy.

Sau khi cập nhật mã, chạy `php artisan migrate --force` trong container app. Migration mới đọc kích thước các file đã có để quota chính xác ngay từ lần triển khai đầu. Không cần cài thêm package Composer. Nginx và PHP giới hạn request upload ở khoảng 100 MiB; khi thay đổi giới hạn ứng dụng trong `config/upload_limits.php`, cập nhật cả `docker/nginx/conf.d/default.conf`, `docker/php/local.ini` và giới hạn của ONLYOFFICE trong `config/onlyoffice.php` cho phù hợp.

Để file hết hạn được dọn tự động, cần chạy Laravel scheduler và queue worker. Nếu chưa bật hai dịch vụ này, có thể chạy thủ công `php artisan app:clean-expired-files` rồi `php artisan queue:work --stop-when-empty` trong container app.
