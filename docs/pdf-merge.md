# Ghép thêm PDF và lưu thành file mới

## Cách sử dụng

Trong **File Share** hoặc **File cá nhân**, mở menu dấu ba chấm của một PDF còn hạn mà bạn có quyền chỉnh sửa, rồi chọn **Ghép PDF**.

1. Đặt tên PDF mới; hệ thống gợi ý tên dựa trên file gốc.
2. Chọn từ 1 đến 5 PDF trên máy để ghép thêm.
3. Dùng nút lên/xuống để đổi thứ tự các file ghép thêm nếu cần.
4. Bấm **Tạo PDF mới**.

Các trang của PDF gốc đứng đầu, sau đó là toàn bộ trang của từng PDF được thêm theo thứ tự hiển thị. File gốc giữ nguyên; kết quả là một bản ghi và đường dẫn lưu trữ mới trong cùng khu vực file.

## Quyền và dung lượng

- Người thao tác cần có quyền chỉnh sửa file gốc theo cơ chế quyền hiện tại: chủ sở hữu, người được cấp quyền, tất cả người dùng hoặc file chung ẩn danh được phép sửa.
- Ghép trong File cá nhân yêu cầu đăng nhập.
- Bản mới thuộc người tạo bản ghép, tính vào quota của người đó. Nếu tạo khi chưa đăng nhập ở File Share thì tính vào quota chung dành cho khách.
- Giữ `view_only`, `all_users_can_edit`, danh sách người được cấp quyền và thời điểm hết hạn theo file gốc. Nếu người tạo khác chủ file gốc thì chủ file gốc vẫn được cấp quyền trên bản mới.
- File gốc vẫn chiếm dung lượng như trước; tạo bản mới không được trừ kích thước file gốc khi kiểm tra quota.
- Tổng dung lượng các PDF tải thêm tối đa 100 MiB; PDF đầu ra cũng tối đa 100 MiB. Giao diện hiển thị giới hạn này là 100 MB, thống nhất với giới hạn upload hiện tại.
- Không hỗ trợ PDF mã hóa/bảo vệ mật khẩu. PDF hỏng hoặc không xử lý được sẽ trả lỗi và không tạo bản ghép.

## Triển khai

Phần backend dùng **qpdf** thông qua Symfony Process có sẵn trong Laravel. Không thêm dependency Composer, package npm hoặc migration.

Dockerfile đã thêm gói hệ thống `qpdf`. Khi triển khai sang máy khác, build image app từ Dockerfile mới để cài gói này. Với máy hiện tại, gói đã được cài trong container app đang chạy để sử dụng và kiểm thử; cài trực tiếp trong container không thay thế việc cập nhật image cho những lần recreate tiếp theo.

Frontend cần bản build mới theo quy trình triển khai asset của project. Không xóa volume dữ liệu để cập nhật tính năng này.

Nếu chạy PHP ngoài Docker, cài qpdf trên máy chạy PHP và bảo đảm executable nằm trong PATH của tiến trình PHP; có thể đặt `QPDF_BINARY` thành đường dẫn đầy đủ tới executable. Cấu hình nằm trong `config/pdf_merge.php`.

API là `POST /pdf/{scope}/{id}/merge`, với `scope` là `shared` hoặc `personal`, multipart body gồm `name` và `pdfs[]`. Đây là API được giao diện gọi, không phải command người dùng cần chạy.

## Luồng xử lý

1. Kiểm tra input, số lượng file, MIME PDF, phần mở rộng và tổng dung lượng tải thêm.
2. Khóa quota của người tạo, sau đó khóa bản ghi file gốc; kiểm tra quyền, hạn dùng, loại file và sự tồn tại trên storage.
3. qpdf kiểm tra mã hóa, ghép vào một file tạm. Đường dẫn được truyền bằng mảng argument của Process, không ghép thành shell command. Tiến trình ghép có timeout 60 giây.
4. Kiểm tra kích thước đầu ra và quota trước khi lưu file mới, tạo bản ghi và sao chép quyền.
5. Nếu lỗi, rollback bản ghi và xóa file đầu ra đã tạo; dọn file tạm ở cả trường hợp thành công và thất bại. File gốc không bị ghi đè.

qpdf lấy thông tin cấp tài liệu từ PDF gốc; không bảo đảm hợp nhất đầy đủ bookmark, form hoặc dữ liệu cấp tài liệu của các PDF bổ sung. Tính năng này ghép trang, không phải trình biên tập cấu trúc PDF hoặc công cụ bảo toàn chữ ký số trên bản kết quả. Xem [qpdf Page Selection](https://qpdf.readthedocs.io/en/stable/cli.html#page-selection).

## Kiểm thử

Chạy trong container có qpdf:

```powershell
docker compose exec -T app php artisan test --compact --filter=PdfMergeTest
```

Tests dùng các PDF thật nhỏ được tạo trong fixture, kiểm tra thứ tự trang bằng dữ liệu JSON của qpdf; đồng thời kiểm tra quyền, quota người tạo, bản gốc giữ nguyên, file mã hóa/hỏng, giới hạn kích thước và dọn file khi transaction thất bại.
